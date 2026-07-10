<?php
/**
 * Webhook receptor — Evolution API → IA WhatsApp
 *
 * Evolution API aponta para este endpoint.
 * Segurança: token secreto na querystring (?token=...) definido em WEBHOOK_TOKEN.
 *
 * Fluxo:
 *   1. Valida token
 *   2. Filtra evento (só messages.upsert, de pessoas, não fromMe)
 *   3. Busca cliente por telefone
 *   4. Carrega conversa ativa (ConversasIA)
 *   5. Busca agendamentos futuros
 *   6. Chama Gemini para classificar intenção
 *   7. Executa ação (confirmar / cancelar / reagendar / nenhuma)
 *   8. Persiste histórico e responde via WhatsApp
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/conexao.php';

// ── 1. Segurança ──────────────────────────────────────────────────────────────
$tokenRecebido = $_GET['token'] ?? '';
if (!defined('WEBHOOK_TOKEN') || !hash_equals(WEBHOOK_TOKEN, $tokenRecebido)) {
    http_response_code(403);
    exit;
}

$raw = file_get_contents('php://input');
if (!$raw) {
    http_response_code(200);
    exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    http_response_code(200);
    exit;
}

// ── 2. Filtra eventos irrelevantes ────────────────────────────────────────────
$evento = $payload['event'] ?? '';
if ($evento !== 'messages.upsert') {
    http_response_code(200);
    exit;
}

$msgData = $payload['data'] ?? [];
$key     = $msgData['key'] ?? [];

// Mensagens enviadas pelo próprio bot
if ($key['fromMe'] ?? false) {
    http_response_code(200);
    exit;
}

$remoteJid = $key['remoteJid'] ?? '';

// Grupos (@g.us) — ignora
if (str_contains($remoteJid, '@g.us')) {
    http_response_code(200);
    exit;
}

// ── 3. Extrai texto ───────────────────────────────────────────────────────────
$textoMsg = $msgData['message']['conversation']
    ?? $msgData['message']['extendedTextMessage']['text']
    ?? $msgData['message']['imageMessage']['caption']
    ?? '';
$textoMsg = trim($textoMsg);

if ($textoMsg === '') {
    // Sticker, áudio, documento — ignora
    http_response_code(200);
    exit;
}

// ── 4. Normaliza telefone ─────────────────────────────────────────────────────
$telefoneRaw = preg_replace('/@.*$/', '', $remoteJid);
$telefone    = sanitizarTelefone($telefoneRaw);
if (!$telefone) {
    error_log("[Webhook] Telefone não reconhecido: {$telefoneRaw}");
    http_response_code(200);
    exit;
}

// ── 5. Busca cliente cadastrada pelo telefone ─────────────────────────────────
$stmtUsr = $pdo->prepare(
    "SELECT IDUsuario, Nome
     FROM Usuarios
     WHERE REGEXP_REPLACE(Telefone, '[^0-9]', '') = :tel
       AND NivelAcesso = 'cliente'
       AND Ativo = 1
       AND Email NOT LIKE '%@avulso.internal'
     LIMIT 1"
);
$stmtUsr->execute([':tel' => $telefone]);
$cliente = $stmtUsr->fetch() ?: null;

// ── 6. Carrega conversa ativa (até 24h atrás) ─────────────────────────────────
$conversa = null;
if ($cliente) {
    $stmtConv = $pdo->prepare(
        "SELECT *
         FROM ConversasIA
         WHERE Telefone = :tel
           AND Estado NOT IN ('resolvido', 'expirado')
           AND UltimaMensagemEm > DATE_SUB(NOW(), INTERVAL 24 HOUR)
         ORDER BY UltimaMensagemEm DESC
         LIMIT 1"
    );
    $stmtConv->execute([':tel' => $telefone]);
    $conversa = $stmtConv->fetch() ?: null;
}

// ── 7. Agendamentos futuros da cliente ────────────────────────────────────────
$agendamentos = [];
if ($cliente) {
    $stmtAg = $pdo->prepare(
        "SELECT a.IDAgendamento, a.DataHoraAgendamento, a.StatusAgendamento,
                a.AguardandoConfirmacaoIA,
                s.Nome AS Servico,
                sub.Nome AS SubServico
         FROM Agendamentos a
         JOIN Servicos s ON s.IDServico = a.FKServico
         LEFT JOIN SubServicos sub ON sub.IDSubServico = a.FKSubServico
         WHERE a.FKCliente = :fkc
           AND a.StatusAgendamento NOT IN ('cancelado', 'concluido')
           AND a.DataHoraAgendamento >= NOW()
         ORDER BY a.DataHoraAgendamento ASC
         LIMIT 5"
    );
    $stmtAg->execute([':fkc' => $cliente['IDUsuario']]);
    $agendamentos = $stmtAg->fetchAll();
}

// ── 8. Interpreta intenção via Gemini ─────────────────────────────────────────
$intencao = _interpretarIA($textoMsg, $cliente, $conversa, $agendamentos);

// ── 9. Executa ação ───────────────────────────────────────────────────────────
$fkAgLog  = null;
$tipoLog  = 'ia_resposta';
$resposta = $intencao['resposta'] ?? _fallbackResposta($conversa['Estado'] ?? '', $agendamentos);

switch ($intencao['acao'] ?? 'nenhuma') {
    case 'confirmar_agendamento':
        $idAg = $conversa['FKAgendamento'] ?? null;
        if ($idAg) {
            $pdo->prepare(
                "UPDATE Agendamentos
                 SET StatusAgendamento = 'confirmado', AguardandoConfirmacaoIA = 0
                 WHERE IDAgendamento = :id"
            )->execute([':id' => $idAg]);
            _encerrarConversa($pdo, $conversa['IDConversa']);
            $fkAgLog  = $idAg;
            $tipoLog  = 'ia_confirmou';
        }
        break;

    case 'cancelar_agendamento':
        $idAg = $conversa['FKAgendamento'] ?? null;
        if ($idAg) {
            $pdo->prepare(
                "UPDATE Agendamentos
                 SET StatusAgendamento = 'cancelado', AguardandoConfirmacaoIA = 0
                 WHERE IDAgendamento = :id"
            )->execute([':id' => $idAg]);
            _encerrarConversa($pdo, $conversa['IDConversa']);
            $fkAgLog  = $idAg;
            $tipoLog  = 'ia_cancelou';
        }
        break;

    case 'solicitar_novo_horario':
        if ($conversa) {
            $pdo->prepare(
                "UPDATE ConversasIA SET Estado = 'aguardando_novo_horario' WHERE IDConversa = :id"
            )->execute([':id' => $conversa['IDConversa']]);
        }
        $tipoLog = 'ia_reagendou';
        break;

    case 'consultar':
        $tipoLog = 'ia_consulta';
        break;
}

// ── 10. Persiste histórico ────────────────────────────────────────────────────
$historico = [];
if ($conversa && $conversa['Historico']) {
    $historico = json_decode($conversa['Historico'], true) ?: [];
}
$historico[] = ['role' => 'user',      'text' => $textoMsg,  'ts' => date('c')];
$historico[] = ['role' => 'assistant', 'text' => $resposta,  'ts' => date('c')];
// Limita a 20 pares (40 entradas)
if (count($historico) > 40) {
    $historico = array_slice($historico, -40);
}
$historicoJson = json_encode($historico, JSON_UNESCAPED_UNICODE);

if ($conversa && !in_array($conversa['Estado'] ?? '', ['resolvido', 'expirado'])) {
    $pdo->prepare(
        "UPDATE ConversasIA SET Historico = :h, UltimaMensagemEm = NOW() WHERE IDConversa = :id"
    )->execute([':h' => $historicoJson, ':id' => $conversa['IDConversa']]);
} elseif (!$conversa) {
    // Cria nova conversa genérica
    $pdo->prepare(
        "INSERT INTO ConversasIA
             (IDConversa, Telefone, FKCliente, Estado, Historico, UltimaMensagemEm)
         VALUES (:id, :tel, :fkc, 'em_conversa', :h, NOW())"
    )->execute([
        ':id'  => gerarUuid(),
        ':tel' => $telefone,
        ':fkc' => $cliente['IDUsuario'] ?? null,
        ':h'   => $historicoJson,
    ]);
}

// ── 11. Log de entrada + envia resposta ──────────────────────────────────────
registrarLogWhatsApp($pdo, $telefone, $textoMsg, 'webhook_entrada', 'recebido', $fkAgLog);

$enviou = enviarWhatsApp($telefone, $resposta);
registrarLogWhatsApp($pdo, $telefone, $resposta, $tipoLog, $enviou ? 'enviado' : 'erro', $fkAgLog);

http_response_code(200);
echo json_encode(['ok' => true]);
exit;


// ── Funções internas ──────────────────────────────────────────────────────────

function _interpretarIA(
    string $mensagem,
    ?array $cliente,
    ?array $conversa,
    array  $agendamentos
): array {
    $nomeCliente = $cliente['Nome'] ?? 'cliente';
    $estadoConv  = $conversa['Estado'] ?? 'sem_conversa';

    // Monta bloco de agendamentos
    $blocoAg = '';
    foreach ($agendamentos as $ag) {
        $srv      = $ag['SubServico'] ?: $ag['Servico'];
        $dt       = date('d/m/Y \à\s H:i', strtotime($ag['DataHoraAgendamento']));
        $blocoAg .= "- {$srv} em {$dt} (status: {$ag['StatusAgendamento']})\n";
    }
    $blocoAg = $blocoAg ?: "Nenhum agendamento futuro.\n";

    // Agendamento pendente de confirmação (vinculado à conversa)
    $agPendente = '';
    if ($conversa && $conversa['FKAgendamento']) {
        foreach ($agendamentos as $ag) {
            if ($ag['IDAgendamento'] === $conversa['FKAgendamento']) {
                $srv        = $ag['SubServico'] ?: $ag['Servico'];
                $dt         = date('d/m/Y \à\s H:i', strtotime($ag['DataHoraAgendamento']));
                $agPendente = "Agendamento em questão: {$srv} em {$dt}.\n";
                break;
            }
        }
    }

    $promptSistema = <<<PROMPT
Você é a assistente virtual do estúdio de cílios Belos Cílios.
Atenda de forma amigável, informal e atenciosa, como se fosse a própria dona do estúdio.
Use emojis com moderação. Responda SEMPRE em português do Brasil.

Retorne APENAS um JSON válido (sem markdown, sem texto extra) com exatamente estes campos:
{
  "intencao": "confirmar" | "cancelar" | "reagendar" | "consultar" | "saudacao" | "outro",
  "confianca": 0.0 até 1.0,
  "resposta": "mensagem a enviar para a cliente",
  "acao": "confirmar_agendamento" | "cancelar_agendamento" | "solicitar_novo_horario" | "consultar" | "nenhuma"
}

Regras:
- Use "confirmar_agendamento" SOMENTE quando o estado for "aguardando_confirmacao" e a intenção for clara (confiança > 0.7).
- Use "cancelar_agendamento" SOMENTE quando o estado for "aguardando_confirmacao" e a cliente quiser cancelar claramente (confiança > 0.7).
- Se não tiver certeza da intenção, use "nenhuma" e peça esclarecimento na resposta.
- Para reagendar, use "solicitar_novo_horario" e oriente a cliente a entrar em contato para combinar novo horário.
- Nunca invente agendamentos. Se não tiver agendamento listado, informe que não há agendamento encontrado.
- Em saudações ou dúvidas gerais, ajude educadamente e informe horários de funcionamento se perguntado (você não sabe os horários exatos, então diga para a cliente verificar pelo link de agendamento).
PROMPT;

    $promptUsuario = <<<PROMPT
CONTEXTO:
Nome da cliente: {$nomeCliente}
Estado da conversa: {$estadoConv}
{$agPendente}
Agendamentos futuros da cliente:
{$blocoAg}

MENSAGEM DA CLIENTE:
"{$mensagem}"
PROMPT;

    if (!defined('GEMINI_API_KEY') || !GEMINI_API_KEY || GEMINI_API_KEY === 'sua-gemini-key-aqui') {
        error_log('[WebhookIA] Gemini não configurado.');
        return [
            'intencao'  => 'outro',
            'confianca' => 0,
            'resposta'  => _fallbackResposta($estadoConv, $agendamentos),
            'acao'      => 'nenhuma',
        ];
    }

    $body = json_encode([
        'system_instruction' => ['parts' => [['text' => $promptSistema]]],
        'contents'           => [['parts' => [['text' => $promptUsuario]]]],
        'generationConfig'   => [
            'temperature'      => 0.3,
            'maxOutputTokens'  => 512,
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
        error_log("[WebhookIA] Gemini HTTP {$httpCode}: " . substr($resp ?: '', 0, 200));
        return [
            'intencao'  => 'outro',
            'confianca' => 0,
            'resposta'  => _fallbackResposta($estadoConv, $agendamentos),
            'acao'      => 'nenhuma',
        ];
    }

    $gemini = json_decode($resp, true);
    $texto  = $gemini['candidates'][0]['content']['parts'][0]['text'] ?? '{}';
    $result = json_decode($texto, true);

    if (!is_array($result) || !isset($result['intencao'])) {
        error_log("[WebhookIA] JSON inválido do Gemini: " . substr($texto, 0, 300));
        return [
            'intencao'  => 'outro',
            'confianca' => 0,
            'resposta'  => _fallbackResposta($estadoConv, $agendamentos),
            'acao'      => 'nenhuma',
        ];
    }

    return $result;
}

function _fallbackResposta(string $estado, array $agendamentos): string
{
    if ($estado === 'aguardando_confirmacao') {
        return 'Olá! 😊 Não entendi sua resposta. Você gostaria de *confirmar* ou *cancelar* seu agendamento? Responda com uma dessas palavras.';
    }
    if ($agendamentos) {
        return 'Olá! 😊 Recebi sua mensagem. Para dúvidas sobre seus agendamentos, acesse seu perfil pelo link de agendamento ou nos chame aqui!';
    }
    return 'Olá! 😊 Para agendar um horário, acesse nosso link de agendamento. Qualquer dúvida, estamos à disposição!';
}

function _encerrarConversa(PDO $pdo, string $idConversa): void
{
    $pdo->prepare("UPDATE ConversasIA SET Estado = 'resolvido' WHERE IDConversa = :id")
        ->execute([':id' => $idConversa]);
}
