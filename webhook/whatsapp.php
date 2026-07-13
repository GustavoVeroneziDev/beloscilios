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

// ── 5. Busca cliente ───────────────────────────────────────────────────────────
$stmtUsr = $pdo->prepare(
    "SELECT IDUsuario, Nome FROM Usuarios
     WHERE REGEXP_REPLACE(Telefone, '[^0-9]', '') = :tel
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

switch ($estadoAtual) {

    // ── aguardando escolha do serviço ─────────────────────────────────────────
    case 'aguardando_servico':
        $servicos = _listarServicos($pdo);
        $escolha  = _parsearNumero($textoMsg, count($servicos));

        if ($escolha !== null) {
            $svc = $servicos[$escolha - 1];
            $dadosCtx = [
                'fk_servico'   => $svc['FKServico'],
                'fk_sub'       => $svc['FKSub'],
                'nome_servico' => $svc['Nome'],
                'duracao'      => $svc['Duracao'],
                'preco'        => $svc['Preco'],
            ];
            $datas = _listarDatasDisponiveis($pdo, $svc['Duracao']);
            if (empty($datas)) {
                $resposta   = "Oiee! 😊 Infelizmente não encontrei horários disponíveis nos próximos dias para *{$svc['Nome']}*. Tente de novo em breve ou acesse o link de agendamento! 💜";
                $novoEstado = 'em_conversa';
                $dadosCtx   = [];
            } else {
                $dadosCtx['datas_disponiveis'] = $datas;
                $novoEstado = 'aguardando_data';
                $resposta   = "Ótima escolha! 🎀 Para *{$svc['Nome']}*, esses dias têm horário disponível:\n\n";
                foreach ($datas as $i => $dt) {
                    $resposta .= ($i + 1) . ". " . _formatarDataPT($dt) . "\n";
                }
                $resposta .= "\nQual prefere? Responda com o número 😊";
            }
        } else {
            $servicos = _listarServicos($pdo);
            $resposta = "Não entendi 😅 Por favor, responda com o *número* do serviço:\n\n";
            $resposta .= _textoListaServicos($servicos);
        }
        break;

    // ── aguardando escolha da data ────────────────────────────────────────────
    case 'aguardando_data':
        $datas   = $dadosCtx['datas_disponiveis'] ?? [];
        $escolha = _parsearNumero($textoMsg, count($datas));

        if ($escolha !== null && isset($datas[$escolha - 1])) {
            $dataEsc = $datas[$escolha - 1];
            $duracao = (int)($dadosCtx['duracao'] ?? 60);
            $slots   = _getSlots($pdo, $dataEsc, $duracao);

            if (empty($slots)) {
                $resposta = "Ops, esse dia não tem mais horários disponíveis 😅 Escolha outra data:\n\n";
                foreach ($datas as $i => $dt) {
                    $resposta .= ($i + 1) . ". " . _formatarDataPT($dt) . "\n";
                }
            } else {
                $dadosCtx['data_escolhida']   = $dataEsc;
                $dadosCtx['slots_disponiveis'] = $slots;
                $novoEstado = 'aguardando_horario';
                $resposta   = "Ótimo! Em *" . _formatarDataPT($dataEsc) . "*, esses horários estão disponíveis:\n\n";
                foreach ($slots as $i => $s) {
                    $resposta .= ($i + 1) . ". {$s}\n";
                }
                $resposta .= "\nQual horário prefere? 😊";
            }
        } else {
            $resposta = "Por favor, responda com o *número* da data desejada:\n\n";
            foreach ($datas as $i => $dt) {
                $resposta .= ($i + 1) . ". " . _formatarDataPT($dt) . "\n";
            }
        }
        break;

    // ── aguardando escolha do horário ─────────────────────────────────────────
    case 'aguardando_horario':
        $slots   = $dadosCtx['slots_disponiveis'] ?? [];
        $escolha = _parsearNumero($textoMsg, count($slots));

        if ($escolha !== null && isset($slots[$escolha - 1])) {
            $horaEsc              = $slots[$escolha - 1];
            $dadosCtx['hora_escolhida'] = $horaEsc;
            $novoEstado           = 'aguardando_confirmacao_agendamento';

            $dataEsc  = $dadosCtx['data_escolhida'] ?? '';
            $nomeServ = $dadosCtx['nome_servico']   ?? '';
            $preco    = (float)($dadosCtx['preco']  ?? 0);
            $ts       = strtotime("{$dataEsc} {$horaEsc}");
            $diasPT   = ['domingo','segunda-feira','terça-feira','quarta-feira','quinta-feira','sexta-feira','sábado'];
            $diaSem   = $diasPT[(int) date('w', $ts)];

            $resposta  = "Perfeito! 💜 Vou confirmar:\n\n";
            $resposta .= "📌 *Serviço:* {$nomeServ}\n";
            $resposta .= "📅 *Data:* {$diaSem}, " . date('d/m/Y', $ts) . "\n";
            $resposta .= "🕐 *Horário:* {$horaEsc}\n";
            if ($preco > 0) {
                $resposta .= "💰 *Valor:* " . _moeda($preco) . "\n";
            }
            $resposta .= "\nConfirmo? Responda *SIM* para agendar ou *NÃO* para cancelar 🎀";
        } else {
            $resposta = "Por favor, responda com o *número* do horário:\n\n";
            foreach ($slots as $i => $s) {
                $resposta .= ($i + 1) . ". {$s}\n";
            }
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
        $escolha    = _parsearNumero($textoMsg, count($agsFuturos));
        $desistiu   = _eCancelamento(mb_strtolower(trim($textoMsg)));

        if ($desistiu && $escolha === null) {
            $dadosCtx   = [];
            $novoEstado = 'em_conversa';
            $resposta   = "Tudo bem! 😊 Qualquer coisa, é só chamar! 💜";
        } elseif ($escolha !== null && isset($agsFuturos[$escolha - 1])) {
            $ag  = $agsFuturos[$escolha - 1];
            _cancelarAgId($pdo, $ag['IDAgendamento']);
            $fkAgLog    = $ag['IDAgendamento'];
            $tipoLog    = 'ia_cancelou';
            $dadosCtx   = [];
            $novoEstado = 'resolvido';
            $srv        = $ag['SubServico'] ?: $ag['Servico'];
            $dt         = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
            $hr         = date('H:i',   strtotime($ag['DataHoraAgendamento']));
            $resposta   = "Agendamento de *{$srv}* do dia *{$dt} às {$hr}* cancelado! 💜\n\nSe quiser remarcar outro horário, é só me chamar! 🎀";
        } else {
            $resposta = "Por favor, responda com o *número* do agendamento para cancelar:\n\n";
            foreach ($agsFuturos as $i => $ag) {
                $srv       = $ag['SubServico'] ?: $ag['Servico'];
                $dt        = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
                $hr        = date('H:i',   strtotime($ag['DataHoraAgendamento']));
                $resposta .= ($i + 1) . ". {$srv} — {$dt} às {$hr}\n";
            }
            $resposta .= "\nOu diga *não* para voltar.";
        }
        break;

    // ── escolhendo qual agendamento reagendar ─────────────────────────────────
    case 'aguardando_reagendamento_escolha':
        $agsFuturos = $dadosCtx['ags_para_reagendar'] ?? [];
        $escolha    = _parsearNumero($textoMsg, count($agsFuturos));
        $desistiu   = _eCancelamento(mb_strtolower(trim($textoMsg)));

        if ($desistiu && $escolha === null) {
            $dadosCtx   = [];
            $novoEstado = 'em_conversa';
            $resposta   = "Tudo bem! 😊 Qualquer coisa, é só chamar! 💜";
        } elseif ($escolha !== null && isset($agsFuturos[$escolha - 1])) {
            $ag = $agsFuturos[$escolha - 1];
            _cancelarAgId($pdo, $ag['IDAgendamento']);
            $fkAgLog    = $ag['IDAgendamento'];
            $tipoLog    = 'ia_reagendou';
            $dadosCtx   = [];
            $lista      = _iniciarFluxoAgendamento($pdo);
            $novoEstado = 'aguardando_servico';
            $resposta   = "Pronto! Agendamento anterior cancelado 💜 Agora vamos marcar um novo:\n\n{$lista}";
        } else {
            $resposta = "Por favor, responda com o *número* do agendamento para reagendar:\n\n";
            foreach ($agsFuturos as $i => $ag) {
                $srv       = $ag['SubServico'] ?: $ag['Servico'];
                $dt        = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
                $hr        = date('H:i',   strtotime($ag['DataHoraAgendamento']));
                $resposta .= ($i + 1) . ". {$srv} — {$dt} às {$hr}\n";
            }
            $resposta .= "\nOu diga *não* para voltar.";
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
        $resultado = _geminiNLU($textoMsg, $cliente, $agendamentos, $visitas, $historico);
        $acao      = $resultado['acao'] ?? 'nenhuma';
        $resposta  = $resultado['resposta'] ?? _fallback($agendamentos, $cliente);

        switch ($acao) {
            case 'iniciar_agendamento':
                $lista = _iniciarFluxoAgendamento($pdo);
                if ($lista) {
                    $novoEstado = 'aguardando_servico';
                    $saudacao   = $cliente ? "Ótimo, {$cliente['Nome']}! 🎀\n\n" : "Ótimo! 🎀\n\n";
                    $resposta   = $saudacao . $lista;
                }
                break;

            case 'iniciar_cancelamento':
                if (!empty($agendamentos)) {
                    $dadosCtx   = ['ags_para_cancelar' => $agendamentos];
                    $novoEstado = 'aguardando_cancelamento_escolha';
                    if (count($agendamentos) === 1) {
                        $ag        = $agendamentos[0];
                        $srv       = $ag['SubServico'] ?: $ag['Servico'];
                        $dt        = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
                        $hr        = date('H:i',   strtotime($ag['DataHoraAgendamento']));
                        $resposta  = "Você tem *{$srv}* marcado para *{$dt} às {$hr}*.\n\n";
                        $resposta .= "Quer cancelar? Responda *1* para confirmar ou *não* para voltar.";
                    } else {
                        $resposta = "Qual agendamento deseja cancelar?\n\n";
                        foreach ($agendamentos as $i => $ag) {
                            $srv       = $ag['SubServico'] ?: $ag['Servico'];
                            $dt        = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
                            $hr        = date('H:i',   strtotime($ag['DataHoraAgendamento']));
                            $resposta .= ($i + 1) . ". {$srv} — {$dt} às {$hr}\n";
                        }
                        $resposta .= "\nOu diga *não* para voltar.";
                    }
                } else {
                    $resposta = "Você não tem agendamentos futuros para cancelar 😊";
                }
                break;

            case 'iniciar_reagendamento':
                if (!empty($agendamentos)) {
                    $dadosCtx   = ['ags_para_reagendar' => $agendamentos];
                    $novoEstado = 'aguardando_reagendamento_escolha';
                    if (count($agendamentos) === 1) {
                        $ag        = $agendamentos[0];
                        $srv       = $ag['SubServico'] ?: $ag['Servico'];
                        $dt        = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
                        $hr        = date('H:i',   strtotime($ag['DataHoraAgendamento']));
                        $resposta  = "Você quer reagendar *{$srv}* do dia *{$dt} às {$hr}*?\n";
                        $resposta .= "Responda *1* para confirmar ou *não* para voltar.";
                    } else {
                        $resposta = "Qual agendamento deseja reagendar?\n\n";
                        foreach ($agendamentos as $i => $ag) {
                            $srv       = $ag['SubServico'] ?: $ag['Servico'];
                            $dt        = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
                            $hr        = date('H:i',   strtotime($ag['DataHoraAgendamento']));
                            $resposta .= ($i + 1) . ". {$srv} — {$dt} às {$hr}\n";
                        }
                        $resposta .= "\nOu diga *não* para voltar.";
                    }
                } else {
                    // Sem agendamentos → iniciar novo agendamento
                    $lista      = _iniciarFluxoAgendamento($pdo);
                    $novoEstado = 'aguardando_servico';
                    $resposta   = "Você não tem agendamentos futuros. Que tal marcar um novo? 🎀\n\n{$lista}";
                }
                break;
        }
        break;
}

// ── 10. Persiste conversa ──────────────────────────────────────────────────────
$historico[] = ['role' => 'user',      'text' => $textoMsg, 'ts' => date('c')];
$historico[] = ['role' => 'assistant', 'text' => $resposta, 'ts' => date('c')];
if (count($historico) > 40) {
    $historico = array_slice($historico, -40);
}
$historicoJson = json_encode($historico, JSON_UNESCAPED_UNICODE);
$dadosCtxJson  = json_encode($dadosCtx,  JSON_UNESCAPED_UNICODE);

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

// ── 11. Envia resposta ─────────────────────────────────────────────────────────
registrarLogWhatsApp($pdo, $telefone, $textoMsg, 'webhook_entrada', 'recebido', $fkAgLog);
$enviou = enviarWhatsApp($telefone, $resposta);
registrarLogWhatsApp($pdo, $telefone, $resposta, $tipoLog, $enviou ? 'enviado' : 'erro', $fkAgLog);

http_response_code(200);
echo json_encode(['ok' => true]);
exit;


// ═══════════════════════════════════════════════════════════════════════════════
// Funções de domínio
// ═══════════════════════════════════════════════════════════════════════════════

function _geminiNLU(
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

    $sistemaPrompt = <<<PROMPT
Você é a Beli 💜, assistente virtual do estúdio de cílios Belos Cílios da Thainá.
Atenda com carinho, leveza e naturalidade — como a própria Thainá faria.
Tom: amigável, informal, acolhedor. Use "Oiee" ao cumprimentar.
Emojis com moderação: 💜 🎀 ✨ 💕 🫶🏼 😊
Responda SEMPRE em português do Brasil.

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
- Em outros casos → acao = "nenhuma" e responda naturalmente
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
                'Preco'     => (float)$r['PrecoSub'],
                'Duracao'   => (int)$r['DurSub'],
            ];
        } elseif (empty($comSub[$r['IDServico']])) {
            $result[] = [
                'FKServico' => $r['IDServico'],
                'FKSub'     => null,
                'Nome'      => $r['NomeServ'],
                'Preco'     => (float)$r['PrecoServ'],
                'Duracao'   => (int)$r['DurServ'],
            ];
        }
    }

    return $result;
}

function _textoListaServicos(array $servicos): string
{
    $txt = '';
    foreach ($servicos as $i => $svc) {
        $txt .= ($i + 1) . ". *{$svc['Nome']}*";
        if ($svc['Preco'] > 0) $txt .= " — " . _moeda($svc['Preco']);
        $txt .= " ({$svc['Duracao']}min)\n";
    }
    return $txt;
}

function _iniciarFluxoAgendamento(PDO $pdo): string
{
    $servicos = _listarServicos($pdo);
    if (empty($servicos)) {
        return "No momento não temos serviços disponíveis. Entre em contato para mais informações! 💜";
    }
    return "Qual serviço você gostaria de agendar? 🎀\n\n" . _textoListaServicos($servicos);
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

function _cancelarAgId(PDO $pdo, string $id): void
{
    $pdo->prepare("UPDATE Agendamentos SET StatusAgendamento='cancelado', AguardandoConfirmacaoIA=0 WHERE IDAgendamento=:id")
        ->execute([':id' => $id]);
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
