<?php
/**
 * Webhook Evolution API — recebe mensagens de clientes no WhatsApp,
 * usa Gemini para classificar a intenção (confirmado / cancelado / incerto)
 * e atualiza o agendamento ou notifica a designer.
 *
 * Configurar na Evolution API:
 *   URL:    https://beloscilios.com/webhook_whatsapp.php
 *   Events: messages.upsert
 */

require_once __DIR__ . '/config/conexao.php';

// Só aceita POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

// Validação: rejeita se a chave da API não bater (se configurada)
if (defined('EVOLUTION_KEY') && EVOLUTION_KEY) {
    $apikey = $_SERVER['HTTP_APIKEY'] ?? '';
    if ($apikey !== EVOLUTION_KEY) {
        http_response_code(401);
        exit;
    }
}

$body = file_get_contents('php://input');
$data = json_decode($body, true);

if (!$data) {
    http_response_code(400);
    exit;
}

$event = $data['event'] ?? '';

// Só processa mensagens recebidas
if ($event !== 'messages.upsert') {
    http_response_code(200);
    echo '{"ok":true,"skip":"event_ignored"}';
    exit;
}

$msgData = $data['data'] ?? [];
$fromMe  = (bool)($msgData['key']['fromMe'] ?? true);

// Ignora mensagens que nós mesmos enviamos
if ($fromMe) {
    http_response_code(200);
    echo '{"ok":true,"skip":"from_me"}';
    exit;
}

$remoteJid = $msgData['key']['remoteJid'] ?? '';
$msgText   = $msgData['message']['conversation']
          ?? $msgData['message']['extendedTextMessage']['text']
          ?? '';

if (!$remoteJid || !$msgText) {
    http_response_code(200);
    echo '{"ok":true,"skip":"no_text"}';
    exit;
}

// Número limpo (remove @s.whatsapp.net e sufixos de grupo)
$numero = preg_replace('/[^0-9]/', '', explode('@', $remoteJid)[0]);

// Busca agendamento mais próximo futuro com confirmação enviada e ainda pendente
try {
    $stmt = $pdo->prepare(
        "SELECT a.IDAgendamento, a.DataHoraAgendamento, a.StatusAgendamento,
                u.Nome AS NomeCliente, u.Telefone,
                s.Nome AS NomeServico
         FROM Agendamentos a
         JOIN Usuarios u ON u.IDUsuario = a.FKCliente
         JOIN Servicos s ON s.IDServico = a.FKServico
         WHERE u.Telefone = :tel
           AND a.NotificacaoConfirmacaoEnviada = 1
           AND a.StatusAgendamento = 'pendente'
           AND a.DataHoraAgendamento > NOW()
         ORDER BY a.DataHoraAgendamento ASC
         LIMIT 1"
    );
    $stmt->execute([':tel' => $numero]);
    $ag = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[Webhook WA] BD: ' . $e->getMessage());
    http_response_code(500);
    exit;
}

// Loga a mensagem recebida independente de ter agendamento vinculado
registrarLogWhatsApp($pdo, $numero, $msgText, 'webhook_entrada', 'enviado', $ag['IDAgendamento'] ?? null);

if (!$ag) {
    http_response_code(200);
    echo '{"ok":true,"skip":"no_appointment"}';
    exit;
}

$dataAg = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
$horaAg = date('H:i',   strtotime($ag['DataHoraAgendamento']));

// Classifica intenção via Gemini
$intencao = _classificarRespostaWA($msgText, $ag);

switch ($intencao) {

    case 'confirmado':
        $pdo->prepare(
            "UPDATE Agendamentos SET StatusAgendamento='confirmado' WHERE IDAgendamento=:id"
        )->execute([':id' => $ag['IDAgendamento']]);

        $resposta = "✅ Ótimo, {$ag['NomeCliente']}! Seu agendamento de *{$ag['NomeServico']}* no dia *{$dataAg}* às *{$horaAg}* está confirmado. Te esperamos! 💜";
        enviarWhatsApp($numero, $resposta);
        registrarLogWhatsApp($pdo, $numero, $resposta, 'webhook_confirmado', 'enviado', $ag['IDAgendamento']);
        break;

    case 'cancelado':
        $pdo->prepare(
            "UPDATE Agendamentos SET StatusAgendamento='cancelado' WHERE IDAgendamento=:id"
        )->execute([':id' => $ag['IDAgendamento']]);

        $tplCancelamento = getConfig($pdo, 'msg_cancelamento', '');
        if ($tplCancelamento) {
            $msgCancel = str_replace(
                ['{nome}', '{data}', '{hora}', '{servico}'],
                [$ag['NomeCliente'], $dataAg, $horaAg, $ag['NomeServico']],
                $tplCancelamento
            );
            enviarWhatsApp($numero, $msgCancel);
            registrarLogWhatsApp($pdo, $numero, $msgCancel, 'webhook_cancelado', 'enviado', $ag['IDAgendamento']);
        }

        // Notifica designer
        _notificarDesigner(
            $pdo,
            "❌ *Cancelamento via WhatsApp*\n"
            . "*{$ag['NomeCliente']}* cancelou o agendamento de *{$ag['NomeServico']}* "
            . "em *{$dataAg}* às *{$horaAg}*.\n\nMensagem da cliente: \"{$msgText}\"",
            $ag['IDAgendamento']
        );
        break;

    default: // incerto
        _notificarDesigner(
            $pdo,
            "❓ *Resposta não identificada*\n"
            . "De: *{$ag['NomeCliente']}* — agendamento de *{$ag['NomeServico']}* "
            . "em *{$dataAg}* às *{$horaAg}*\n\nMensagem: \"{$msgText}\"\n\n"
            . "_Verifique se precisa confirmar ou cancelar manualmente._",
            $ag['IDAgendamento']
        );
        break;
}

http_response_code(200);
echo json_encode(['ok' => true, 'intencao' => $intencao]);

// ── Helpers internos ──────────────────────────────────────────────────────────

function _classificarRespostaWA(string $mensagem, array $ag): string
{
    if (!defined('GEMINI_API_KEY') || !GEMINI_API_KEY || GEMINI_API_KEY === 'sua-gemini-key-aqui') {
        // Gemini não configurado — fallback por palavras-chave simples
        $lower = mb_strtolower($mensagem);
        $sim   = ['sim', 'confirmo', 'confirmado', 'ok', 'pode', 'claro', 'certo', 'estarei', 'vou', 'tá', 'ta', 'ótimo', 'otimo', '👍'];
        $nao   = ['não', 'nao', 'nã', 'cancela', 'cancelar', 'cancelado', 'impossível', 'impossivel', 'desmarcar', 'remarcar', 'faltar', 'não vou', 'nao vou'];
        foreach ($nao as $p) { if (str_contains($lower, $p)) return 'cancelado'; }
        foreach ($sim as $p) { if (str_contains($lower, $p)) return 'confirmado'; }
        return 'incerto';
    }

    $dataAg = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
    $horaAg = date('H:i',   strtotime($ag['DataHoraAgendamento']));

    $prompt = "Você é um classificador de intenções para mensagens de WhatsApp de um estúdio de cílios.\n\n"
            . "Contexto: a cliente *{$ag['NomeCliente']}* recebeu uma mensagem pedindo confirmação do agendamento "
            . "de *{$ag['NomeServico']}* no dia *{$dataAg}* às *{$horaAg}*.\n\n"
            . "Resposta da cliente: \"{$mensagem}\"\n\n"
            . "Classifique a intenção em UMA ÚNICA PALAVRA:\n"
            . "- confirmado — qualquer variante positiva (sim, confirmo, ok, pode, vou, tá, claro, etc.)\n"
            . "- cancelado — qualquer forma de recusa, cancelamento, impossibilidade\n"
            . "- incerto — dúvidas, perguntas, mensagem fora de contexto ou ambígua\n\n"
            . "Responda APENAS com uma das três palavras, sem pontuação nem explicação.";

    $payload = json_encode([
        'contents'         => [['parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0, 'maxOutputTokens' => 10],
    ]);

    $ch = curl_init(GEMINI_ENDPOINT . '?key=' . GEMINI_API_KEY);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp = curl_exec($ch);
    $err  = curl_error($ch);
    curl_close($ch);

    if (!$resp || $err) {
        error_log('[Webhook WA] Gemini: ' . $err);
        return 'incerto';
    }

    $json  = json_decode($resp, true);
    $texto = trim(mb_strtolower($json['candidates'][0]['content']['parts'][0]['text'] ?? ''));

    if (str_contains($texto, 'confirmado')) return 'confirmado';
    if (str_contains($texto, 'cancelado'))  return 'cancelado';
    return 'incerto';
}

function _notificarDesigner(PDO $pdo, string $mensagem, ?string $agId): void
{
    $tel = getConfig($pdo, 'telefone_designer', '');
    if (!$tel) return;
    $ok = enviarWhatsApp($tel, $mensagem);
    registrarLogWhatsApp($pdo, $tel, $mensagem, 'webhook_incerto', $ok ? 'enviado' : 'erro', $agId);
}
