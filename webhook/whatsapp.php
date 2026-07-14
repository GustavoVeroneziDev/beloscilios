<?php
/**
 * Webhook receptor — Evolution API → IA WhatsApp
 *
 * Capacidades:
 *   - Agendar novo horário (fluxo: serviço → data → hora → confirma → cria)
 *   - Cancelar agendamento existente
 *   - Reagendar (cancela atual + abre fluxo novo)
 *   - Confirmar agendamento pendente (via lembrete)
 *   - Tirar dúvidas com personalidade da Thainá (via Gemini)
 */
declare(strict_types=1);
require_once __DIR__ . '/../config/conexao.php';

// ── 1. Segurança ───────────────────────────────────────────────────────────────
$tokenRecebido = $_GET['token'] ?? '';
if (!defined('WEBHOOK_TOKEN') || !hash_equals(WEBHOOK_TOKEN, $tokenRecebido)) {
    http_response_code(403);
    exit;
}

$raw = file_get_contents('php://input');
if (!$raw) { http_response_code(200); exit; }

$payload = json_decode($raw, true);
if (!is_array($payload)) { http_response_code(200); exit; }

// ── 2. Filtra eventos ──────────────────────────────────────────────────────────
if (($payload['event'] ?? '') !== 'messages.upsert') { http_response_code(200); exit; }

$msgData   = $payload['data'] ?? [];
$key       = $msgData['key'] ?? [];
$remoteJid = $key['remoteJid'] ?? '';

if ($key['fromMe'] ?? false)           { http_response_code(200); exit; }
if (str_contains($remoteJid, '@g.us')) { http_response_code(200); exit; }

// ── 3. Extrai texto ────────────────────────────────────────────────────────────
$textoMsg = $msgData['message']['conversation']
    ?? $msgData['message']['extendedTextMessage']['text']
    ?? $msgData['message']['imageMessage']['caption']
    ?? '';
$textoMsg = trim($textoMsg);
if ($textoMsg === '') { http_response_code(200); exit; }

// ── 4. Normaliza telefone ──────────────────────────────────────────────────────
$telefoneRaw = preg_replace('/@.*$/', '', $remoteJid);
$telefone    = sanitizarTelefone($telefoneRaw);
if (!$telefone) { http_response_code(200); exit; }

// ── 4b. Dedup — ignora mensagem já processada nos últimos 30s ─────────────────
$stmtDedup = $pdo->prepare(
    "SELECT 1 FROM LogsWhatsApp
     WHERE Numero = :tel AND TipoMensagem = 'webhook_entrada'
       AND MomentoRegistro > DATE_SUB(NOW(), INTERVAL 30 SECOND)
       AND LEFT(Mensagem, 200) = :msg LIMIT 1"
);
$stmtDedup->execute([':tel' => $telefone, ':msg' => mb_substr($textoMsg, 0, 200)]);
if ($stmtDedup->fetchColumn()) { http_response_code(200); exit; }

// Registra entrada imediatamente (bloqueia reprocessamento concurrent)
registrarLogWhatsApp($pdo, $telefone, $textoMsg, 'webhook_entrada', 'recebido', null);

// ── 5. Busca cliente ───────────────────────────────────────────────────────────
$stmtUsr = $pdo->prepare(
    "SELECT IDUsuario, Nome FROM Usuarios
     WHERE Telefone = :tel
       AND NivelAcesso = 'cliente' AND Ativo = 1
       AND Email NOT LIKE '%@avulso.internal'
     LIMIT 1"
);
$stmtUsr->execute([':tel' => $telefone]);
$cliente = $stmtUsr->fetch() ?: null;

// ── 6. Carrega conversa ativa (últimas 24h) ────────────────────────────────────
$stmtConv = $pdo->prepare(
    "SELECT * FROM ConversasIA
     WHERE Telefone = :tel
       AND Estado NOT IN ('resolvido', 'expirado')
       AND UltimaMensagemEm > DATE_SUB(NOW(), INTERVAL 24 HOUR)
     ORDER BY UltimaMensagemEm DESC LIMIT 1"
);
$stmtConv->execute([':tel' => $telefone]);
$conversa = $stmtConv->fetch() ?: null;

$estadoAtual   = $conversa['Estado']        ?? 'em_conversa';
$dadosCtx      = json_decode($conversa['DadosContexto'] ?? 'null', true) ?: [];
$historico     = json_decode($conversa['Historico']     ?? '[]',   true) ?: [];
$fkAgendamento = $conversa['FKAgendamento'] ?? null;

// ── 7. Agendamentos futuros da cliente ────────────────────────────────────────
$agendamentos = [];
if ($cliente) {
    $stmtAg = $pdo->prepare(
        "SELECT a.IDAgendamento, a.DataHoraAgendamento, a.StatusAgendamento,
                a.AguardandoConfirmacaoIA,
                s.Nome AS Servico, sub.Nome AS SubServico
         FROM Agendamentos a
         JOIN Servicos s ON s.IDServico = a.FKServico
         LEFT JOIN SubServicos sub ON sub.IDSubServico = a.FKSubServico
         WHERE a.FKCliente = :fkc
           AND a.StatusAgendamento NOT IN ('cancelado','concluido')
           AND a.DataHoraAgendamento >= NOW()
         ORDER BY a.DataHoraAgendamento ASC LIMIT 5"
    );
    $stmtAg->execute([':fkc' => $cliente['IDUsuario']]);
    $agendamentos = $stmtAg->fetchAll();
}

// ── 8. Histórico de visitas (contexto para Gemini) ────────────────────────────
$visitas = [];
if ($cliente) {
    $stmtV = $pdo->prepare(
        "SELECT a.DataHoraAgendamento, s.Nome AS Servico, sub.Nome AS SubServico
         FROM Agendamentos a
         JOIN Servicos s ON s.IDServico = a.FKServico
         LEFT JOIN SubServicos sub ON sub.IDSubServico = a.FKSubServico
         WHERE a.FKCliente = :fkc AND a.StatusAgendamento IN ('confirmado','concluido')
         ORDER BY a.DataHoraAgendamento DESC LIMIT 3"
    );
    $stmtV->execute([':fkc' => $cliente['IDUsuario']]);
    $visitas = $stmtV->fetchAll();
}

// ── 9. Máquina de estados ──────────────────────────────────────────────────────
$resposta   = '';
$novoEstado = $estadoAtual;
$novoFkAg   = $fkAgendamento;
$fkAgLog    = null;
$tipoLog    = 'ia_resposta';

// ── 9a. Escape do fluxo: saudação/confusão no meio de um agendamento ──────────
$estadosMidFlow = ['aguardando_servico','aguardando_data','aguardando_horario','aguardando_confirmacao_agendamento'];
if (in_array($estadoAtual, $estadosMidFlow)) {
    $tl = mb_strtolower($textoMsg, 'UTF-8');
    $pareceReset = (bool) preg_match(
        '/\b(ol[aá]|oie+|oi\b|bom dia|boa tarde|boa noite|confus|come[cç]|in[ií]cio|esquecer?|tudo bem|como vai|para|recomeç)\b/u',
        $tl
    ) || ($estadoAtual === 'aguardando_horario' && !preg_match('/\d/', $textoMsg));

    if ($pareceReset) {
        $estadoAtual = 'em_conversa';
        $dadosCtx    = [];
        $historico   = [];
    }
}

switch ($estadoAtual) {

    // ── aguardando escolha do serviço ─────────────────────────────────────────
    case 'aguardando_servico':
        $servicos = _listarServicos($pdo);
        $match    = _parsearServico($textoMsg, $servicos);

        if (is_array($match) && isset($match['ambiguo'])) {
            $tiposStr = implode(', ', array_map(fn($t) => "*{$t}*", $match['tipos']));
            $resposta = "Qual tipo você faz? {$tiposStr}? 🎀";
        } elseif ($match) {
            $dadosCtx = [
                'fk_servico'   => $match['FKServico'],
                'fk_sub'       => $match['FKSub'],
                'nome_servico' => $match['Nome'],
                'duracao'      => $match['Duracao'],
                'preco'        => $match['Preco'],
            ];
            $data = _parsearData($textoMsg);
            if ($data) {
                $slots = _getSlots($pdo, $data, $match['Duracao']);
                if (!empty($slots)) {
                    $dadosCtx['data_escolhida']    = $data;
                    $dadosCtx['slots_disponiveis'] = $slots;
                    $novoEstado = 'aguardando_horario';
                    $resposta   = "Ótima escolha! 🎀 Em " . _formatarDataPT($data) . " tenho: " . implode(', ', $slots) . ". Qual você prefere? ⏰";
                } else {
                    $novoEstado = 'aguardando_data';
                    $resposta   = "Para *{$match['Nome']}* no dia " . _formatarDataPT($data) . " não tenho horários disponíveis 😔 Qual outro dia você prefere? 📅";
                }
            } else {
                $novoEstado = 'aguardando_data';
                $resposta   = "Ótima escolha! 🎀 Pra quando você quer marcar *{$match['Nome']}*? Me fala o dia 📅";
            }
        } else {
            $stmt = $pdo->prepare("SELECT Nome FROM Servicos WHERE Ativo = 1 ORDER BY Nome");
            $stmt->execute();
            $nomes    = implode(', ', array_column($stmt->fetchAll(), 'Nome'));
            $resposta = "Hmm, não entendi qual serviço 😅 Temos: {$nomes}. Qual você quer? 🎀";
        }
        break;

    // ── aguardando escolha da data ────────────────────────────────────────────
    case 'aguardando_data':
        $duracao  = (int)($dadosCtx['duracao'] ?? 60);
        $nomeServ = $dadosCtx['nome_servico'] ?? 'o serviço';
        $data     = _parsearData($textoMsg);

        if ($data) {
            $slots = _getSlots($pdo, $data, $duracao);
            if (!empty($slots)) {
                $dadosCtx['data_escolhida']    = $data;
                $dadosCtx['slots_disponiveis'] = $slots;
                $novoEstado = 'aguardando_horario';
                $resposta   = "Em " . _formatarDataPT($data) . " tenho disponível: " . implode(', ', $slots) . " 🕐\n\nQual você prefere?";
            } else {
                $datas   = _listarDatasDisponiveis($pdo, $duracao);
                if (empty($datas)) {
                    $novoEstado = 'em_conversa';
                    $dadosCtx   = [];
                    $resposta   = "Não tenho horários disponíveis nos próximos dias para *{$nomeServ}* 😔 Tenta de novo em breve ou fala com a gente pelo Instagram! 💜";
                } else {
                    $altsFmt  = array_map('_formatarDataPT', array_slice($datas, 0, 3));
                    $resposta = "Esse dia não tem horário disponível 😔 Os mais próximos são: " . implode(', ', $altsFmt) . ". Algum te atende?";
                }
            }
        } else {
            $resposta = "Não entendi o dia 😅 Pode me falar assim: *dia 14*, *terça-feira*, *14 de julho*... Qual dia você prefere? 📅";
        }
        break;

    // ── aguardando escolha do horário ─────────────────────────────────────────
    case 'aguardando_horario':
        $slots   = $dadosCtx['slots_disponiveis'] ?? [];
        $horaEsc = _parsearHora($textoMsg, $slots);

        if ($horaEsc) {
            $dadosCtx['hora_escolhida'] = $horaEsc;
            $novoEstado = 'aguardando_confirmacao_agendamento';

            $dataEsc  = $dadosCtx['data_escolhida'] ?? '';
            $nomeServ = $dadosCtx['nome_servico']   ?? '';
            $preco    = (float)($dadosCtx['preco']  ?? 0);
            $ts       = strtotime("{$dataEsc} {$horaEsc}");
            $diasPT   = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
            $diaSem   = $diasPT[(int) date('w', $ts)];
            $precoStr = $preco > 0 ? " por *" . _moeda($preco) . "*" : '';

            $resposta = "Posso confirmar *{$nomeServ}* em *{$diaSem}, " . date('d/m', $ts) . " às {$horaEsc}*{$precoStr}? 💜";
        } else {
            $resposta = "Não encontrei esse horário 😅 Os disponíveis são: " . implode(', ', $slots) . ". Qual você prefere? ⏰";
        }
        break;

    // ── confirmando novo agendamento ──────────────────────────────────────────
    case 'aguardando_confirmacao_agendamento':
        $lower     = mb_strtolower(trim($textoMsg));
        $confirmou = _eConfirmacao($lower);
        $cancelou  = _eCancelamento($lower);

        if ($confirmou && $cliente) {
            $result = _criarAgendamento($pdo, $cliente['IDUsuario'], $dadosCtx);
            if ($result['ok']) {
                $fkAgLog    = $result['id'];
                $tipoLog    = 'ia_confirmou';
                $novoEstado = 'resolvido';
                $novoFkAg   = $result['id'];
                $dataEsc    = $dadosCtx['data_escolhida'] ?? '';
                $horaEsc    = $dadosCtx['hora_escolhida'] ?? '';
                $nomeServ   = $dadosCtx['nome_servico']   ?? '';
                $ts         = strtotime("{$dataEsc} {$horaEsc}");
                $diasPT     = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
                $diaSem     = $diasPT[(int) date('w', $ts)];
                $resposta   = "Agendado com sucesso! 🎉💜\n\n";
                $resposta  .= "*{$nomeServ}* em *{$diaSem}, " . date('d/m/Y', $ts) . " às {$horaEsc}*.\n\n";
                $resposta  .= "Lembre-se de vir com o rostinho limpo, sem maquiagem nos olhos 🎀 Até lá!";
                $dadosCtx   = [];
            } else {
                // Horário ocupado — reinicia fluxo
                $dadosCtx   = [];
                $novoEstado = 'aguardando_servico';
                $lista      = _iniciarFluxoAgendamento($pdo);
                $resposta   = "Ops, esse horário acabou de ser ocupado 😅 Vamos escolher outro!\n\n{$lista}";
            }
        } elseif ($cancelou) {
            $dadosCtx   = [];
            $novoEstado = 'em_conversa';
            $resposta   = "Sem problemas! 😊 Se quiser agendar depois, é só me chamar! 💜";
        } else {
            $nomeServ = $dadosCtx['nome_servico']   ?? '';
            $dataEsc  = $dadosCtx['data_escolhida'] ?? '';
            $horaEsc  = $dadosCtx['hora_escolhida'] ?? '';
            $resposta = "Responda *SIM* para confirmar o agendamento de *{$nomeServ}* em " .
                date('d/m/Y', strtotime($dataEsc)) . " às {$horaEsc}, ou *NÃO* para cancelar 😊";
        }
        break;

    // ── escolhendo qual agendamento cancelar ──────────────────────────────────
    case 'aguardando_cancelamento_escolha':
        $agsFuturos = $dadosCtx['ags_para_cancelar'] ?? [];
        $desistiu   = _eCancelamento(mb_strtolower(trim($textoMsg)));
        $idxEsc     = _matchAgendamento($textoMsg, $agsFuturos);

        if ($desistiu && $idxEsc === null) {
            $dadosCtx   = [];
            $novoEstado = 'em_conversa';
            $resposta   = "Tudo bem! 😊 Qualquer coisa, é só chamar! 💜";
        } elseif ($idxEsc !== null && isset($agsFuturos[$idxEsc])) {
            $ag         = $agsFuturos[$idxEsc];
            _cancelarAgId($pdo, $ag['IDAgendamento']);
            $fkAgLog    = $ag['IDAgendamento'];
            $tipoLog    = 'ia_cancelou';
            $dadosCtx   = [];
            $novoEstado = 'resolvido';
            $srv        = $ag['SubServico'] ?: $ag['Servico'];
            $dt         = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
            $hr         = date('H:i',   strtotime($ag['DataHoraAgendamento']));
            $resposta   = "Prontinho! 💜 Cancelei *{$srv}* do dia *{$dt} às {$hr}*. Se quiser remarcar, é só chamar! 🎀";
        } else {
            $lista = '';
            foreach ($agsFuturos as $ag) {
                $srv    = $ag['SubServico'] ?: $ag['Servico'];
                $dt     = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
                $hr     = date('H:i',   strtotime($ag['DataHoraAgendamento']));
                $lista .= "• {$srv} — {$dt} às {$hr}\n";
            }
            $resposta = "Qual desses você quer cancelar?\n\n{$lista}\nPode me falar o serviço ou a data 😊";
        }
        break;

    // ── escolhendo qual agendamento reagendar ─────────────────────────────────
    case 'aguardando_reagendamento_escolha':
        $agsFuturos = $dadosCtx['ags_para_reagendar'] ?? [];
        $desistiu   = _eCancelamento(mb_strtolower(trim($textoMsg)));
        $idxEsc     = _matchAgendamento($textoMsg, $agsFuturos);

        if ($desistiu && $idxEsc === null) {
            $dadosCtx   = [];
            $novoEstado = 'em_conversa';
            $resposta   = "Tudo bem! 😊 Qualquer coisa, é só chamar! 💜";
        } elseif ($idxEsc !== null && isset($agsFuturos[$idxEsc])) {
            $ag         = $agsFuturos[$idxEsc];
            _cancelarAgId($pdo, $ag['IDAgendamento']);
            $fkAgLog    = $ag['IDAgendamento'];
            $tipoLog    = 'ia_reagendou';
            $dadosCtx   = [];
            $novoEstado = 'aguardando_servico';
            $resposta   = "Pronto! Agendamento cancelado 💜 Que serviço você quer marcar? Me fala o nome! 🎀";
        } else {
            $lista = '';
            foreach ($agsFuturos as $ag) {
                $srv    = $ag['SubServico'] ?: $ag['Servico'];
                $dt     = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
                $hr     = date('H:i',   strtotime($ag['DataHoraAgendamento']));
                $lista .= "• {$srv} — {$dt} às {$hr}\n";
            }
            $resposta = "Qual desses você quer reagendar?\n\n{$lista}\nPode me falar o serviço ou a data 😊";
        }
        break;

    // ── aguardando confirmação de agendamento existente (via lembrete) ────────
    case 'aguardando_confirmacao':
        $lower    = mb_strtolower(trim($textoMsg));
        $intencao = _parsearConfirmacao($lower);

        if ($intencao === 'confirmar' && $fkAgendamento) {
            $pdo->prepare("UPDATE Agendamentos SET StatusAgendamento='confirmado', AguardandoConfirmacaoIA=0 WHERE IDAgendamento=:id")
                ->execute([':id' => $fkAgendamento]);
            $fkAgLog    = $fkAgendamento;
            $tipoLog    = 'ia_confirmou';
            $novoEstado = 'resolvido';
            $resposta   = "Confirmado! 🎉 Nos vemos em breve! 💜\nLembre-se de vir com o rostinho limpo, sem maquiagem nos olhos 🎀";

        } elseif ($intencao === 'cancelar' && $fkAgendamento) {
            $pdo->prepare("UPDATE Agendamentos SET StatusAgendamento='cancelado', AguardandoConfirmacaoIA=0 WHERE IDAgendamento=:id")
                ->execute([':id' => $fkAgendamento]);
            $fkAgLog    = $fkAgendamento;
            $tipoLog    = 'ia_cancelou';
            $novoEstado = 'resolvido';
            $resposta   = "Tudo bem! Agendamento cancelado 💜 Se quiser marcar para outra data, é só me chamar! 🎀";

        } elseif ($intencao === 'reagendar' && $fkAgendamento) {
            _cancelarAgId($pdo, $fkAgendamento);
            $fkAgLog    = $fkAgendamento;
            $tipoLog    = 'ia_reagendou';
            $dadosCtx   = [];
            $lista      = _iniciarFluxoAgendamento($pdo);
            $novoEstado = 'aguardando_servico';
            $resposta   = "Entendido! Vamos remarcar 😊\n\n{$lista}";

        } else {
            // Resposta confusa — avisa a designer no pessoal para ela entrar em contato
            $numDesigner = getConfig($pdo, 'numero_alerta_designer', '');
            if ($numDesigner) {
                $telDesigner = sanitizarTelefone($numDesigner);
                if ($telDesigner && $fkAgendamento) {
                    // Busca dados do agendamento para o alerta
                    $stmtAlerta = $pdo->prepare(
                        "SELECT a.DataHoraAgendamento, s.Nome AS Servico, sub.Nome AS SubServico
                         FROM Agendamentos a
                         JOIN Servicos s ON s.IDServico = a.FKServico
                         LEFT JOIN SubServicos sub ON sub.IDSubServico = a.FKSubServico
                         WHERE a.IDAgendamento = :id LIMIT 1"
                    );
                    $stmtAlerta->execute([':id' => $fkAgendamento]);
                    $dadosAlerta = $stmtAlerta->fetch();
                    if ($dadosAlerta) {
                        $srv = $dadosAlerta['SubServico'] ?: $dadosAlerta['Servico'];
                        $dt  = date('d/m/Y', strtotime($dadosAlerta['DataHoraAgendamento']));
                        $hr  = date('H:i',   strtotime($dadosAlerta['DataHoraAgendamento']));
                        $nomeCli  = $cliente['Nome'] ?? 'Desconhecida';
                        $alertMsg = "🚨 *Atenção!*\n\n*{$nomeCli}* está com dúvidas sobre o agendamento de *{$srv}* no dia *{$dt} às {$hr}*.\n\nTelefone: *{$telefone}*\n\nPode entrar em contato diretamente com ela? 💜";
                        $okAlerta = enviarWhatsApp($telDesigner, $alertMsg);
                        registrarLogWhatsApp($pdo, $telDesigner, $alertMsg, 'alerta_designer', $okAlerta ? 'enviado' : 'erro', $fkAgendamento);
                    }
                }
            }
            $resposta = "Oiee! 😊 Não entendi sua resposta. A Thainá vai entrar em contato com você em breve! 💜";
            $novoEstado = 'resolvido';
        }
        break;

    // ── conversa livre (estado padrão) ────────────────────────────────────────
    default:
        // Detecção rápida por palavras-chave (funciona sem Gemini)
        $tl = mb_strtolower($textoMsg, 'UTF-8');
        $kwReagendar  = (bool) preg_match('/reagend|remarc|mud.*horár|troc.*horár/u', $tl);
        $kwCancelar   = !$kwReagendar && (bool) preg_match('/\bcancelar?\b|\bcancela\b/u', $tl);
        $kwAgendar    = !$kwReagendar && !$kwCancelar && (bool) preg_match('/\bagendar?\b|\bmarcar\b|\bhorár/u', $tl);
        $kwInfo       = !$kwReagendar && !$kwCancelar && !$kwAgendar
                     && (bool) preg_match('/como funciona|como [eé]\b|o que [eé]\b|quero saber|me conta|informa|servi[cç]o|pre[cç]o|valor|quanto custa/u', $tl);

        if ($kwCancelar || $kwAgendar || $kwReagendar) {
            $kwAcao    = $kwCancelar ? 'iniciar_cancelamento' : ($kwReagendar ? 'iniciar_reagendamento' : 'iniciar_agendamento');
            $resultado = ['acao' => $kwAcao, 'resposta' => ''];
        } elseif ($kwInfo) {
            $stmtSrv = $pdo->prepare("SELECT Nome FROM Servicos WHERE Ativo = 1 ORDER BY Nome");
            $stmtSrv->execute();
            $nomesSrv = implode(', ', array_column($stmtSrv->fetchAll(), 'Nome'));
            $primeiro = $cliente ? explode(' ', $cliente['Nome'])[0] : null;
            $oi       = $primeiro ? "Oiee, {$primeiro}! 🎀" : "Oiee! 🎀";
            $resultado = ['acao' => 'nenhuma', 'resposta' =>
                "{$oi} O Belos Cílios é um estúdio especializado em cílios da Thainá! ✨\n\n" .
                "Você pode agendar aqui mesmo pelo WhatsApp — é só me dizer o serviço e o dia que prefere e eu verifico os horários disponíveis 😊\n\n" .
                "Trabalhamos com: {$nomesSrv}.\n\nQuer agendar ou tem mais alguma dúvida? 💜"
            ];
        } else {
            $resultado = _geminiNLU($pdo, $textoMsg, $cliente, $agendamentos, $visitas, $historico);
        }
        $acao      = $resultado['acao'] ?? 'nenhuma';
        $resposta  = $resultado['resposta'] ?: _fallback($agendamentos, $cliente);

        switch ($acao) {
            case 'iniciar_agendamento':
                $servicos = _listarServicos($pdo);
                $svcMatch = _parsearServico($textoMsg, $servicos);

                if ($svcMatch && !isset($svcMatch['ambiguo'])) {
                    $dadosCtx = [
                        'fk_servico'   => $svcMatch['FKServico'],
                        'fk_sub'       => $svcMatch['FKSub'],
                        'nome_servico' => $svcMatch['Nome'],
                        'duracao'      => $svcMatch['Duracao'],
                        'preco'        => $svcMatch['Preco'],
                    ];
                    $data = _parsearData($textoMsg);
                    if ($data) {
                        $slots = _getSlots($pdo, $data, $svcMatch['Duracao']);
                        if (!empty($slots)) {
                            $dadosCtx['data_escolhida']    = $data;
                            $dadosCtx['slots_disponiveis'] = $slots;
                            $novoEstado = 'aguardando_horario';
                            $resposta   = "Ótimo! 🎀 Em " . _formatarDataPT($data) . " tenho: " . implode(', ', $slots) . ". Qual você prefere? ⏰";
                        } else {
                            $novoEstado = 'aguardando_data';
                            $resposta   = "Para *{$svcMatch['Nome']}* no dia " . _formatarDataPT($data) . " não tenho horários 😔 Qual outro dia? 📅";
                        }
                    } else {
                        $novoEstado = 'aguardando_data';
                        $resposta   = "Ótima escolha! 🎀 Pra quando você quer marcar *{$svcMatch['Nome']}*? Me fala o dia 📅";
                    }
                } else {
                    // Gemini já gerou uma resposta amigável — só muda o estado
                    $novoEstado = 'aguardando_servico';
                }
                break;

            case 'iniciar_cancelamento':
                if (!empty($agendamentos)) {
                    if (count($agendamentos) === 1) {
                        // Intenção já confirmada pelo contexto — cancela direto
                        $ag   = $agendamentos[0];
                        $ok   = _cancelarAgId($pdo, $ag['IDAgendamento']);
                        $srv  = $ag['SubServico'] ?: $ag['Servico'];
                        $dt   = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
                        $hr   = date('H:i',   strtotime($ag['DataHoraAgendamento']));
                        $novoEstado = 'resolvido';
                        $resposta   = $ok
                            ? "Prontinho! 💜 Cancelei seu *{$srv}* de *{$dt} às {$hr}*.\n\nFico te esperando quando quiser remarcar! 🎀"
                            : "Não consegui cancelar 😓 Fala com a gente pelo Instagram que a gente resolve!";
                    } else {
                        $dadosCtx   = ['ags_para_cancelar' => $agendamentos];
                        $novoEstado = 'aguardando_cancelamento_escolha';
                        $resposta   = "Qual agendamento deseja cancelar?\n\n";
                        foreach ($agendamentos as $i => $ag) {
                            $srv       = $ag['SubServico'] ?: $ag['Servico'];
                            $dt        = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
                            $hr        = date('H:i',   strtotime($ag['DataHoraAgendamento']));
                            $resposta .= ($i + 1) . ". {$srv} — {$dt} às {$hr}\n";
                        }
                        $resposta .= "\nQual deles? (pode responder o número ou o nome)";
                    }
                } else {
                    $resposta = "Você não tem agendamentos futuros para cancelar 😊";
                }
                break;

            case 'iniciar_reagendamento':
                if (!empty($agendamentos)) {
                    if (count($agendamentos) === 1) {
                        // Cancela o atual e já inicia novo agendamento
                        $ag         = $agendamentos[0];
                        $srv        = $ag['SubServico'] ?: $ag['Servico'];
                        $dt         = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
                        $hr         = date('H:i',   strtotime($ag['DataHoraAgendamento']));
                        _cancelarAgId($pdo, $ag['IDAgendamento']);
                        $lista      = _iniciarFluxoAgendamento($pdo);
                        $novoEstado = 'aguardando_servico';
                        $resposta   = "Cancelei seu *{$srv}* de *{$dt} às {$hr}*. Vamos marcar um novo! 🎀\n\n{$lista}";
                    } else {
                        $dadosCtx   = ['ags_para_reagendar' => $agendamentos];
                        $novoEstado = 'aguardando_reagendamento_escolha';
                        $resposta   = "Qual agendamento deseja reagendar?\n\n";
                        foreach ($agendamentos as $i => $ag) {
                            $srv       = $ag['SubServico'] ?: $ag['Servico'];
                            $dt        = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
                            $hr        = date('H:i',   strtotime($ag['DataHoraAgendamento']));
                            $resposta .= ($i + 1) . ". {$srv} — {$dt} às {$hr}\n";
                        }
                        $resposta .= "\nQual deles? (pode responder o número ou o nome)";
                    }
                } else {
                    $lista      = _iniciarFluxoAgendamento($pdo);
                    $novoEstado = 'aguardando_servico';
                    $resposta   = "Você não tem agendamentos futuros. Que tal marcar um novo? 🎀\n\n{$lista}";
                }
                break;
        }
        break;
}

// ── 10. Envia resposta ─────────────────────────────────────────────────────────
$enviou = enviarWhatsApp($telefone, $resposta);
registrarLogWhatsApp($pdo, $telefone, $resposta, $tipoLog, $enviou ? 'enviado' : 'erro', $fkAgLog);

// ── 11. Persiste conversa (best-effort: erro de coluna não derruba o envio) ────
$historico[] = ['role' => 'user',      'text' => $textoMsg, 'ts' => date('c')];
$historico[] = ['role' => 'assistant', 'text' => $resposta, 'ts' => date('c')];
if (count($historico) > 40) {
    $historico = array_slice($historico, -40);
}
$historicoJson = json_encode($historico, JSON_UNESCAPED_UNICODE);
$dadosCtxJson  = json_encode($dadosCtx,  JSON_UNESCAPED_UNICODE);

try {
    if ($conversa) {
        $pdo->prepare(
            "UPDATE ConversasIA
             SET Estado=:est, Historico=:h, DadosContexto=:ctx, FKAgendamento=:fkag, UltimaMensagemEm=NOW()
             WHERE IDConversa=:id"
        )->execute([
            ':est'  => $novoEstado,
            ':h'    => $historicoJson,
            ':ctx'  => $dadosCtxJson,
            ':fkag' => $novoFkAg,
            ':id'   => $conversa['IDConversa'],
        ]);
    } else {
        $pdo->prepare(
            "INSERT INTO ConversasIA
                 (IDConversa, Telefone, FKCliente, FKAgendamento, Estado, Historico, DadosContexto, UltimaMensagemEm)
             VALUES (:id, :tel, :fkc, :fkag, :est, :h, :ctx, NOW())"
        )->execute([
            ':id'   => gerarUuid(),
            ':tel'  => $telefone,
            ':fkc'  => $cliente['IDUsuario'] ?? null,
            ':fkag' => $novoFkAg,
            ':est'  => $novoEstado,
            ':h'    => $historicoJson,
            ':ctx'  => $dadosCtxJson,
        ]);
    }
} catch (\Throwable $e) {
    error_log('[WebhookIA][persist] ' . $e->getMessage());
}

http_response_code(200);
echo json_encode(['ok' => true]);
exit;


// ═══════════════════════════════════════════════════════════════════════════════
// Funções de domínio
// ═══════════════════════════════════════════════════════════════════════════════

function _geminiNLU(
    PDO    $pdo,
    string $mensagem,
    ?array $cliente,
    array  $agendamentos,
    array  $visitas,
    array  $historico
): array {
    if (!defined('GEMINI_API_KEY') || !GEMINI_API_KEY || GEMINI_API_KEY === 'sua-gemini-key-aqui') {
        return ['acao' => 'nenhuma', 'resposta' => _fallback($agendamentos, $cliente)];
    }

    // Contexto da cliente
    $nomeCliente  = $cliente ? $cliente['Nome'] : null;
    $primeiroNome = $nomeCliente ? explode(' ', trim($nomeCliente))[0] : null;
    $ctxCliente   = $nomeCliente ? "Nome: {$nomeCliente} (chame de {$primeiroNome})" : "Não cadastrada no sistema";

    $ctxAg = '';
    if ($agendamentos) {
        $ctxAg = "Agendamentos futuros:\n";
        foreach ($agendamentos as $ag) {
            $srv    = $ag['SubServico'] ?: $ag['Servico'];
            $dt     = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
            $hr     = date('H:i',   strtotime($ag['DataHoraAgendamento']));
            $status = $ag['StatusAgendamento'];
            $ctxAg .= "- {$srv} em {$dt} às {$hr} ({$status})\n";
        }
    } else {
        $ctxAg = "Sem agendamentos futuros.\n";
    }

    $ctxVisitas = '';
    if ($visitas) {
        $ctxVisitas = "Visitas anteriores:\n";
        foreach ($visitas as $v) {
            $srv        = $v['SubServico'] ?: $v['Servico'];
            $dt         = date('d/m/Y', strtotime($v['DataHoraAgendamento']));
            $ctxVisitas .= "- {$srv} em {$dt}\n";
        }
    }

    // Histórico recente da conversa para contexto
    $ctxHistorico = '';
    if (!empty($historico)) {
        $recentes     = array_slice($historico, -6);
        $ctxHistorico = "Mensagens anteriores desta conversa:\n";
        foreach ($recentes as $h) {
            $quem         = $h['role'] === 'user' ? 'Cliente' : 'Beli';
            $ctxHistorico .= "{$quem}: {$h['text']}\n";
        }
    }

    // Contexto do negócio: serviços (banco) + descrição livre (ConfiguracoesSistema)
    $ctxServicos = '';
    try {
        $stmtSrv = $pdo->prepare(
            "SELECT s.Nome AS NomeServ, s.Preco AS PrecoServ, s.DuracaoMinutos AS DurServ,
                    ss.Nome AS NomeSub, ss.Preco AS PrecoSub, ss.DuracaoMinutos AS DurSub
             FROM Servicos s
             LEFT JOIN SubServicos ss ON ss.FKServico = s.IDServico AND ss.Ativo = 1
             WHERE s.Ativo = 1
             ORDER BY s.Nome, ss.Nome"
        );
        $stmtSrv->execute();
        $linhas = $stmtSrv->fetchAll();

        $agrupados = [];
        foreach ($linhas as $l) {
            $agrupados[$l['NomeServ']] ??= ['preco' => $l['PrecoServ'], 'dur' => $l['DurServ'], 'subs' => []];
            if ($l['NomeSub']) {
                $agrupados[$l['NomeServ']]['subs'][] = [
                    'nome'  => $l['NomeSub'],
                    'preco' => $l['PrecoSub'],
                    'dur'   => $l['DurSub'],
                ];
            }
        }

        if ($agrupados) {
            $ctxServicos = "SERVIÇOS DISPONÍVEIS:\n";
            foreach ($agrupados as $nome => $s) {
                if ($s['subs']) {
                    $ctxServicos .= "• {$nome}:\n";
                    foreach ($s['subs'] as $sub) {
                        $preco = $sub['preco'] ? 'R$ ' . number_format($sub['preco'], 2, ',', '.') : '';
                        $dur   = $sub['dur']   ? " ({$sub['dur']} min)" : '';
                        $ctxServicos .= "  - {$sub['nome']}{$dur}" . ($preco ? " — {$preco}" : '') . "\n";
                    }
                } else {
                    $preco = $s['preco'] ? 'R$ ' . number_format($s['preco'], 2, ',', '.') : '';
                    $dur   = $s['dur']   ? " ({$s['dur']} min)" : '';
                    $ctxServicos .= "• {$nome}{$dur}" . ($preco ? " — {$preco}" : '') . "\n";
                }
            }
        }
    } catch (\Throwable $e) {
        error_log('[WebhookIA][servicos] ' . $e->getMessage());
    }

    $ctxNegocio = getConfig($pdo, 'contexto_negocio', '');

    $sistemaPrompt = <<<PROMPT
Você é a Beli 💜, assistente virtual do estúdio de cílios Belos Cílios da Thainá.
Atenda com carinho, leveza e naturalidade — como a própria Thainá faria.
Tom: amigável, informal, acolhedor. Use "Oiee" ao cumprimentar.
Emojis com moderação: 💜 🎀 ✨ 💕 🫶🏼 😊
Responda SEMPRE em português do Brasil.

SOBRE O ESTÚDIO:
{$ctxNegocio}

{$ctxServicos}

Você pode:
• Agendar novo horário
• Cancelar agendamento existente
• Reagendar (mudar horário)
• Confirmar agendamento pendente
• Tirar dúvidas sobre cílios, serviços, cuidados, preços
• Ser uma presença acolhedora e amigável

Retorne APENAS JSON válido sem markdown:
{
  "intencao": "agendar" | "cancelar" | "reagendar" | "confirmar" | "consultar" | "saudacao" | "outro",
  "confianca": 0.0-1.0,
  "resposta": "mensagem natural e calorosa para a cliente",
  "acao": "iniciar_agendamento" | "iniciar_cancelamento" | "iniciar_reagendamento" | "consultar" | "nenhuma"
}

Regras:
- Se quer agendar → acao = "iniciar_agendamento" (NÃO liste serviços na resposta — o sistema fará isso)
- Se quer cancelar → acao = "iniciar_cancelamento"
- Se quer reagendar/mudar horário → acao = "iniciar_reagendamento"
- Em outros casos → acao = "nenhuma" e responda naturalmente baseada no contexto do estúdio
- Se não cadastrada: oriente a criar conta em beloscilios.com
- Nunca invente horários ou infos que não estão no contexto
PROMPT;

    $usuarioPrompt = "CLIENTE: {$ctxCliente}\n{$ctxAg}{$ctxVisitas}{$ctxHistorico}\nMENSAGEM ATUAL: \"{$mensagem}\"";

    $body = json_encode([
        'system_instruction' => ['parts' => [['text' => $sistemaPrompt]]],
        'contents'           => [['parts' => [['text' => $usuarioPrompt]]]],
        'generationConfig'   => [
            'temperature'      => 0.4,
            'maxOutputTokens'  => 600,
            'responseMimeType' => 'application/json',
        ],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init(GEMINI_ENDPOINT . '?key=' . GEMINI_API_KEY);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode !== 200 || !$resp) {
        error_log("[WebhookIA] Gemini HTTP {$httpCode}: " . substr((string)$resp, 0, 200));
        return ['acao' => 'nenhuma', 'resposta' => _fallback($agendamentos, $cliente)];
    }

    $gemini = json_decode($resp, true);
    $texto  = $gemini['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    $result = json_decode($texto, true);

    if (!is_array($result) || !isset($result['acao'])) {
        error_log("[WebhookIA] JSON inválido do Gemini: " . substr($texto, 0, 300));
        return ['acao' => 'nenhuma', 'resposta' => _fallback($agendamentos, $cliente)];
    }

    return $result;
}

function _listarServicos(PDO $pdo): array
{
    $stmt = $pdo->prepare(
        "SELECT s.IDServico, s.Nome AS NomeServ, s.Preco AS PrecoServ, s.DuracaoMinutos AS DurServ,
                ss.IDSubServico, ss.Nome AS NomeSub, ss.Preco AS PrecoSub, ss.DuracaoMinutos AS DurSub
         FROM Servicos s
         LEFT JOIN SubServicos ss ON ss.FKServico = s.IDServico AND ss.Ativo = 1
         WHERE s.Ativo = 1
         ORDER BY s.Nome, ss.Nome"
    );
    $stmt->execute();
    $rows = $stmt->fetchAll();

    $comSub = [];
    foreach ($rows as $r) {
        if ($r['IDSubServico']) $comSub[$r['IDServico']] = true;
    }

    $result = [];
    foreach ($rows as $r) {
        if ($r['IDSubServico']) {
            $result[] = [
                'FKServico' => $r['IDServico'],
                'FKSub'     => $r['IDSubServico'],
                'Nome'      => $r['NomeSub'],
                'NomePai'   => $r['NomeServ'],
                'Preco'     => (float)$r['PrecoSub'],
                'Duracao'   => (int)$r['DurSub'],
            ];
        } elseif (empty($comSub[$r['IDServico']])) {
            $result[] = [
                'FKServico' => $r['IDServico'],
                'FKSub'     => null,
                'Nome'      => $r['NomeServ'],
                'NomePai'   => $r['NomeServ'],
                'Preco'     => (float)$r['PrecoServ'],
                'Duracao'   => (int)$r['DurServ'],
            ];
        }
    }

    return $result;
}

function _iniciarFluxoAgendamento(PDO $pdo): string
{
    // Usado apenas como fallback quando reagendamento cancela e não consegue identificar serviço
    $stmt = $pdo->prepare("SELECT Nome FROM Servicos WHERE Ativo = 1 ORDER BY Nome");
    $stmt->execute();
    $nomes = array_column($stmt->fetchAll(), 'Nome');
    return empty($nomes)
        ? "No momento não temos serviços disponíveis 😔"
        : "Que serviço você quer? 🎀 Temos: " . implode(', ', $nomes) . " — pode me falar o nome!";
}

function _listarDatasDisponiveis(PDO $pdo, int $duracao): array
{
    $antecMinH   = (int) getConfig($pdo, 'antecedencia_minima_h', '2');
    $diasFuturos = min((int) getConfig($pdo, 'dias_agenda_futura', '60'), 30);
    $intervalo   = (int) getConfig($pdo, 'intervalo_minutos', '15');

    $datas = [];
    for ($i = 0; $i <= $diasFuturos && count($datas) < 7; $i++) {
        $data  = date('Y-m-d', strtotime("+{$i} days"));
        $slots = _getSlots($pdo, $data, $duracao, $antecMinH, $intervalo);
        if (!empty($slots)) $datas[] = $data;
    }
    return $datas;
}

function _getSlots(PDO $pdo, string $data, int $duracao, ?int $antecMinH = null, ?int $intervalo = null): array
{
    if ($antecMinH === null) $antecMinH = (int) getConfig($pdo, 'antecedencia_minima_h', '2');
    if ($intervalo === null) $intervalo = (int) getConfig($pdo, 'intervalo_minutos', '15');

    $dataTs    = strtotime($data);
    $diaSemana = (int) date('w', $dataTs);

    // Dia especial
    $deStmt = $pdo->prepare(
        'SELECT td.BloqueiaTotal, td.HoraInicio, td.HoraFim, td.AlmocoInicio, td.AlmocoFim
         FROM DiasEspeciais de JOIN TiposDia td ON td.IDTipo = de.FKTipo
         WHERE de.Data = :data LIMIT 1'
    );
    $deStmt->execute([':data' => $data]);
    $diaEspecial = $deStmt->fetch() ?: null;
    if ($diaEspecial && $diaEspecial['BloqueiaTotal']) return [];

    // Horário padrão do dia da semana
    $horStmt = $pdo->prepare(
        'SELECT HoraInicio, HoraFim, AlmocoInicio, AlmocoFim
         FROM HorariosAtendimento WHERE DiaSemana = :d AND Ativo = 1 LIMIT 1'
    );
    $horStmt->execute([':d' => $diaSemana]);
    $horario = $horStmt->fetch();
    if (!$horario) return [];

    if ($diaEspecial) {
        if (!$diaEspecial['HoraInicio'] || !$diaEspecial['HoraFim']) return [];
        $horario['HoraInicio']   = $diaEspecial['HoraInicio'];
        $horario['HoraFim']      = $diaEspecial['HoraFim'];
        $horario['AlmocoInicio'] = $diaEspecial['AlmocoInicio'];
        $horario['AlmocoFim']    = $diaEspecial['AlmocoFim'];
    }

    $dataNext = date('Y-m-d', strtotime($data . ' +1 day'));

    $agStmt = $pdo->prepare(
        "SELECT DataHoraAgendamento, DataHoraFim FROM Agendamentos
         WHERE DataHoraAgendamento >= :data AND DataHoraAgendamento < :next
           AND StatusAgendamento NOT IN ('cancelado')"
    );
    $agStmt->execute([':data' => $data, ':next' => $dataNext]);
    $agendados = $agStmt->fetchAll();

    $bloqStmt = $pdo->prepare(
        'SELECT DataInicio, DataFim FROM BloqueiosAgenda
         WHERE DATE(DataInicio) <= :data AND DATE(DataFim) >= :data2'
    );
    $bloqStmt->execute([':data' => $data, ':data2' => $data]);
    $bloqueios = $bloqStmt->fetchAll();

    $slots    = [];
    $inicioTs = strtotime("{$data} {$horario['HoraInicio']}");
    $fimTs    = strtotime("{$data} {$horario['HoraFim']}");
    $agora    = time() + ($antecMinH * 3600);

    for ($ts = $inicioTs; ($ts + $duracao * 60) <= $fimTs; $ts += $intervalo * 60) {
        if ($ts < $agora) continue;

        $slotFim = $ts + $duracao * 60;
        $livre   = true;

        foreach ($agendados as $ag) {
            $agIni = strtotime($ag['DataHoraAgendamento']);
            $agFim = strtotime($ag['DataHoraFim']);
            if ($ts < $agFim && $slotFim > $agIni) { $livre = false; break; }
        }
        if ($livre) {
            foreach ($bloqueios as $b) {
                $bIni = strtotime($b['DataInicio']);
                $bFim = strtotime($b['DataFim']);
                if ($ts < $bFim && $slotFim > $bIni) { $livre = false; break; }
            }
        }
        if ($livre && !empty($horario['AlmocoInicio']) && !empty($horario['AlmocoFim'])) {
            $alIni = strtotime("{$data} {$horario['AlmocoInicio']}");
            $alFim = strtotime("{$data} {$horario['AlmocoFim']}");
            if ($ts < $alFim && $slotFim > $alIni) $livre = false;
        }

        if ($livre) $slots[] = date('H:i', $ts);
    }

    return $slots;
}

function _criarAgendamento(PDO $pdo, string $clienteId, array $ctx): array
{
    $fkServico = $ctx['fk_servico']    ?? null;
    $fkSub     = $ctx['fk_sub']        ?? null;
    $data      = $ctx['data_escolhida'] ?? null;
    $hora      = $ctx['hora_escolhida'] ?? null;
    $duracao   = (int)($ctx['duracao']  ?? 60);
    $preco     = (float)($ctx['preco']  ?? 0);

    if (!$fkServico || !$data || !$hora) return ['ok' => false, 'msg' => 'Dados incompletos'];

    try {
        $inicio = new DateTimeImmutable("{$data} {$hora}:00");
        $fim    = $inicio->modify("+{$duracao} minutes");

        if ($inicio <= new DateTimeImmutable()) return ['ok' => false, 'msg' => 'Horário no passado'];

        $pdo->beginTransaction();

        $check = $pdo->prepare(
            "SELECT COUNT(*) FROM Agendamentos
             WHERE StatusAgendamento NOT IN ('cancelado')
               AND DataHoraAgendamento < :fim AND DataHoraFim > :ini"
        );
        $check->execute([':ini' => $inicio->format('Y-m-d H:i:s'), ':fim' => $fim->format('Y-m-d H:i:s')]);
        if ((int) $check->fetchColumn() > 0) {
            $pdo->rollBack();
            return ['ok' => false, 'msg' => 'Horário ocupado'];
        }

        $id = gerarUuid();
        $pdo->prepare(
            "INSERT INTO Agendamentos
                 (IDAgendamento, FKCliente, FKServico, FKSubServico,
                  DataHoraAgendamento, DataHoraFim, StatusAgendamento, ValorCobrado)
             VALUES (:id, :fkc, :fks, :fkss, :ini, :fim, 'pendente', :preco)"
        )->execute([
            ':id'   => $id,
            ':fkc'  => $clienteId,
            ':fks'  => $fkServico,
            ':fkss' => $fkSub ?: null,
            ':ini'  => $inicio->format('Y-m-d H:i:s'),
            ':fim'  => $fim->format('Y-m-d H:i:s'),
            ':preco'=> $preco,
        ]);

        $pdo->commit();
        return ['ok' => true, 'id' => $id];

    } catch (\Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[WebhookIA][criarAgendamento] ' . $e->getMessage());
        return ['ok' => false, 'msg' => 'Erro interno'];
    }
}

function _cancelarAgId(PDO $pdo, string $id): bool
{
    $stmt = $pdo->prepare("UPDATE Agendamentos SET StatusAgendamento='cancelado', AguardandoConfirmacaoIA=0 WHERE IDAgendamento=:id");
    $stmt->execute([':id' => $id]);
    return $stmt->rowCount() > 0;
}

// ── Helpers de linguagem natural ───────────────────────────────────────────────

function _normalizarStr(string $s): string
{
    $s = mb_strtolower($s, 'UTF-8');
    return strtr($s, [
        'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a',
        'é'=>'e','ê'=>'e','ë'=>'e','è'=>'e',
        'í'=>'i','î'=>'i','ï'=>'i','ì'=>'i',
        'ó'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ò'=>'o',
        'ú'=>'u','û'=>'u','ü'=>'u','ù'=>'u',
        'ç'=>'c','ñ'=>'n',
    ]);
}

function _parsearServico(string $texto, array $servicos): mixed
{
    $t = _normalizarStr($texto);

    $candidatos = [];
    foreach ($servicos as $svc) {
        $nome    = _normalizarStr($svc['Nome']);
        $nomePai = _normalizarStr($svc['NomePai'] ?? $svc['Nome']);
        $score   = 0;
        foreach (preg_split('/\s+/', $nome) as $p) {
            if (strlen($p) > 2 && str_contains($t, $p)) $score += 2;
        }
        foreach (preg_split('/\s+/', $nomePai) as $p) {
            if (strlen($p) > 2 && str_contains($t, $p)) $score += 1;
        }
        if ($score > 0) $candidatos[] = ['svc' => $svc, 'score' => $score];
    }

    if (empty($candidatos)) return null;

    usort($candidatos, fn($a, $b) => $b['score'] <=> $a['score']);
    $melhorScore = $candidatos[0]['score'];
    $melhores    = array_values(array_filter($candidatos, fn($c) => $c['score'] === $melhorScore));

    if (count($melhores) === 1) return $melhores[0]['svc'];

    // Vários matches: mesmo sub-serviço, pais diferentes → pede qual tipo
    $nomes = array_unique(array_map(fn($c) => $c['svc']['Nome'], $melhores));
    if (count($nomes) === 1) {
        $tipos = array_unique(array_map(fn($c) => $c['svc']['NomePai'], $melhores));
        return ['ambiguo' => true, 'tipos' => $tipos, 'nome' => $nomes[0]];
    }

    // Múltiplos sub-serviços distintos com mesmo score → retorna o mais específico (nome mais longo)
    usort($melhores, fn($a, $b) => strlen($b['svc']['Nome']) <=> strlen($a['svc']['Nome']));
    return $melhores[0]['svc'];
}

function _parsearData(string $texto): ?string
{
    $texto  = _normalizarStr($texto);
    $hojeTs = strtotime(date('Y-m-d'));

    if (preg_match('/\bamanh[a]/u', $texto)) return date('Y-m-d', strtotime('+1 day'));
    if (preg_match('/\bhoje\b/', $texto))     return date('Y-m-d');

    // "14/07" ou "14/07/2026"
    if (preg_match('/\b(\d{1,2})\/(\d{1,2})(?:\/(\d{4}))?\b/', $texto, $m)) {
        $ano  = (int)($m[3] ?? date('Y'));
        $data = sprintf('%04d-%02d-%02d', $ano, (int)$m[2], (int)$m[1]);
        return strtotime($data) >= $hojeTs ? $data : null;
    }

    // "14 de julho" ou "14 julho"
    $mesesN = ['jan'=>1,'fev'=>2,'mar'=>3,'abr'=>4,'mai'=>5,'jun'=>6,'jul'=>7,'ago'=>8,'set'=>9,'out'=>10,'nov'=>11,'dez'=>12,
                'janeiro'=>1,'fevereiro'=>2,'marco'=>3,'abril'=>4,'maio'=>5,'junho'=>6,'julho'=>7,'agosto'=>8,'setembro'=>9,'outubro'=>10,'novembro'=>11,'dezembro'=>12];
    foreach ($mesesN as $nome => $num) {
        if (preg_match('/\b(\d{1,2})\s+(?:de\s+)?' . preg_quote($nome, '/') . '/', $texto, $m)) {
            $ano  = (int)date('Y');
            $data = sprintf('%04d-%02d-%02d', $ano, $num, (int)$m[1]);
            if (strtotime($data) < $hojeTs) $data = sprintf('%04d-%02d-%02d', $ano + 1, $num, (int)$m[1]);
            return $data;
        }
    }

    // "dia 14" ou "o 14"
    if (preg_match('/\bdia\s+(\d{1,2})\b|\bo\s+dia\s+(\d{1,2})\b/', $texto, $m)) {
        $dia = (int)($m[1] ?: $m[2]);
        $mes = (int)date('m');
        $ano = (int)date('Y');
        $data = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
        if (strtotime($data) < strtotime('+1 hour')) {
            if (++$mes > 12) { $mes = 1; $ano++; }
            $data = sprintf('%04d-%02d-%02d', $ano, $mes, $dia);
        }
        return $data;
    }

    // Dias da semana
    $diasMap = ['domingo'=>0,'segunda'=>1,'terca'=>2,'terca-feira'=>2,'quarta'=>3,'quarta-feira'=>3,
                 'quinta'=>4,'quinta-feira'=>4,'sexta'=>5,'sexta-feira'=>5,'sabado'=>6];
    $diasEng = ['sunday','monday','tuesday','wednesday','thursday','friday','saturday'];
    $proxima = (bool) preg_match('/proxim[ao]|semana que vem|semana prox/', $texto);
    foreach ($diasMap as $nome => $dow) {
        if (str_contains($texto, $nome)) {
            $ts = strtotime('next ' . $diasEng[$dow]);
            if (!$proxima && (int)date('w') === $dow) $ts = strtotime('today');
            return date('Y-m-d', $ts);
        }
    }

    return null;
}

function _parsearHora(string $texto, array $slots): ?string
{
    if (empty($slots)) return null;

    $t    = mb_strtolower($texto, 'UTF-8');
    $hora = null;
    $min  = 0;

    if (preg_match('/\b(\d{1,2})[h:](\d{2})\b/', $t, $m)) {
        $hora = (int)$m[1]; $min = (int)$m[2];
    } elseif (preg_match('/\b(\d{1,2})\s*h\b/', $t, $m)) {
        $hora = (int)$m[1];
    } elseif (preg_match('/\b(?:as|às|ao?s)\s+(\d{1,2})\b/u', $t, $m)) {
        $hora = (int)$m[1];
    }

    if ($hora === null) return null;

    // Ajuste PM: estúdio de cílios → horas < 7 sem "manhã" são tarde
    if (preg_match('/tarde|noite/', $t) && $hora < 12) $hora += 12;
    elseif ($hora < 7 && !preg_match('/manh[aã]/u', $t))  $hora += 12;

    $targetSeg = ($hora * 60 + $min) * 60;
    $bestSlot  = null;
    $bestDiff  = PHP_INT_MAX;
    foreach ($slots as $slot) {
        [$h, $ms] = explode(':', $slot);
        $slotSeg  = ((int)$h * 60 + (int)$ms) * 60;
        $diff     = abs($slotSeg - $targetSeg);
        if ($diff < $bestDiff) { $bestDiff = $diff; $bestSlot = $slot; }
    }

    return $bestDiff <= 45 * 60 ? $bestSlot : null; // até 45 min de diferença
}

function _matchAgendamento(string $texto, array $agendamentos): ?int
{
    if (empty($agendamentos)) return null;
    if (count($agendamentos) === 1) return 0;

    $t = _normalizarStr($texto);

    // Tenta casar por nome do serviço
    foreach ($agendamentos as $i => $ag) {
        $srv = _normalizarStr($ag['SubServico'] ?: $ag['Servico']);
        foreach (preg_split('/\s+/', $srv) as $p) {
            if (strlen($p) > 3 && str_contains($t, $p)) return $i;
        }
    }

    // Tenta casar por data mencionada
    $data = _parsearData($texto);
    if ($data) {
        foreach ($agendamentos as $i => $ag) {
            if (str_starts_with($ag['DataHoraAgendamento'], $data)) return $i;
        }
    }

    // Aceita número se o usuário digitou (último recurso)
    if (preg_match('/\b(\d+)\b/', $texto, $m)) {
        $n = (int)$m[1] - 1;
        if ($n >= 0 && $n < count($agendamentos)) return $n;
    }

    return null;
}

function _parsearNumero(string $mensagem, int $max): ?int
{
    $mensagem = trim($mensagem);

    if (ctype_digit($mensagem)) {
        $n = (int) $mensagem;
        return ($n >= 1 && $n <= $max) ? $n : null;
    }
    if (preg_match('/(?:opção|opcao|número|numero|item|o|a)\s*(\d+)/iu', $mensagem, $m)) {
        $n = (int) $m[1];
        return ($n >= 1 && $n <= $max) ? $n : null;
    }
    if (preg_match('/\b(\d+)\b/', $mensagem, $m)) {
        $n = (int) $m[1];
        return ($n >= 1 && $n <= $max) ? $n : null;
    }

    $escritos = ['um' => 1,'uma' => 1,'dois' => 2,'duas' => 2,'três' => 3,'tres' => 3,'quatro' => 4,'cinco' => 5,'seis' => 6,'sete' => 7];
    $lower    = mb_strtolower($mensagem);
    foreach ($escritos as $palavra => $num) {
        if (str_contains($lower, $palavra) && $num <= $max) return $num;
    }

    return null;
}

function _parsearConfirmacao(string $lower): string
{
    $reagenda = ['reagendar','reagenda','mudar','trocar','outro horário','outra data','rema'];
    foreach ($reagenda as $w) {
        if (str_contains($lower, $w)) return 'reagendar';
    }
    $confirma = ['sim','s','yes','confirmo','confirmar','ok','pode','quero','vou','certo','ótimo','legal','bom dia','claro','com certeza'];
    foreach ($confirma as $w) {
        if ($lower === $w || str_contains($lower, $w)) return 'confirmar';
    }
    $cancela = ['não','nao','n','no','cancela','cancelar','não quero','desistir','deixa'];
    foreach ($cancela as $w) {
        if ($lower === $w || str_contains($lower, $w)) return 'cancelar';
    }
    return 'incerto';
}

function _eConfirmacao(string $lower): bool
{
    $palavras = ['sim','s','yes','confirmo','confirmar','ok','pode','quero','vou','certo','ótimo','legal','claro','com certeza','isso'];
    foreach ($palavras as $p) {
        if ($lower === $p || str_contains($lower, $p)) return true;
    }
    return false;
}

function _eCancelamento(string $lower): bool
{
    $palavras = ['não','nao','n','no','cancela','cancelar','não quero','desistir','deixa','voltar'];
    foreach ($palavras as $p) {
        if ($lower === $p || str_contains($lower, $p)) return true;
    }
    return false;
}

function _formatarDataPT(string $data): string
{
    $ts     = strtotime($data);
    $dias   = ['dom','seg','ter','qua','qui','sex','sáb'];
    $meses  = ['jan','fev','mar','abr','mai','jun','jul','ago','set','out','nov','dez'];
    $diaSem = $dias[(int) date('w', $ts)];
    $dia    = (int) date('j', $ts);
    $mes    = $meses[(int) date('n', $ts) - 1];
    return "*{$diaSem}, {$dia}/{$mes}*";
}

function _moeda(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function _fallback(array $agendamentos, ?array $cliente): string
{
    if ($agendamentos) {
        $ag  = $agendamentos[0];
        $srv = $ag['SubServico'] ?: $ag['Servico'];
        $dt  = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
        $hr  = date('H:i',   strtotime($ag['DataHoraAgendamento']));
        return "Oiee! 😊 Vi que você tem *{$srv}* marcado para *{$dt} às {$hr}*. Posso te ajudar com mais alguma coisa? 💜";
    }
    if ($cliente) {
        $primeiro = explode(' ', trim($cliente['Nome']))[0];
        return "Oiee, {$primeiro}! 😊 Como posso te ajudar? Quer agendar um horário, tirar uma dúvida ou precisa de outra coisa? 💜";
    }
    return "Oiee! 😊 Bem-vinda ao Belos Cílios! Para agendar e ter acesso a todos os recursos, crie sua conta em beloscilios.com É rápido! 🎀";
}
