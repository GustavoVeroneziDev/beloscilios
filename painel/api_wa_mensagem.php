<?php
/**
 * Gera mensagem de WhatsApp personalizada com dados reais do banco + Gemini.
 * POST JSON: { "acao": string, "agendamento_id": uuid?, "cliente_id": uuid? }
 * Response: { "ok": true, "mensagem": string, "tel": string }
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método inválido']);
    exit;
}

$in           = json_decode(file_get_contents('php://input'), true) ?? [];
$acao         = trim($in['acao']          ?? '');
$agendId      = trim($in['agendamento_id'] ?? '');
$clienteId    = trim($in['cliente_id']    ?? '');
$telInput     = preg_replace('/\D/', '', trim($in['tel'] ?? ''));

$acaoValidas = ['cobrar', 'lembrar', 'confirmar', 'reagendar', 'avaliacao'];
if (!in_array($acao, $acaoValidas, true)) {
    echo json_encode(['ok' => false, 'msg' => 'Ação inválida']);
    exit;
}

// ── 1. Busca dados do cliente ─────────────────────────────────────────────────
if ($agendId) {
    $stm = $pdo->prepare(
        'SELECT u.IDUsuario AS IDCliente, u.Nome, u.Telefone
           FROM Agendamentos a
           JOIN Usuarios u ON u.IDUsuario = a.FKCliente
          WHERE a.IDAgendamento = :id LIMIT 1'
    );
    $stm->execute([':id' => $agendId]);
} elseif ($clienteId) {
    $stm = $pdo->prepare(
        'SELECT IDUsuario AS IDCliente, Nome, Telefone
           FROM Usuarios WHERE IDUsuario = :id AND NivelAcesso = "cliente" LIMIT 1'
    );
    $stm->execute([':id' => $clienteId]);
} elseif ($telInput) {
    // Fallback: localiza pelo número usando REPLACE nested (compatível com MySQL 5.x)
    // Compara pelos últimos 8 dígitos para tolerar DDI/DDD diferentes na base
    $sufixo = substr($telInput, -8);
    $stm = $pdo->prepare(
        "SELECT IDUsuario AS IDCliente, Nome, Telefone
           FROM Usuarios
          WHERE REPLACE(REPLACE(REPLACE(REPLACE(Telefone,' ',''),'-',''),'(',''),')','') LIKE :suf
            AND NivelAcesso = 'cliente'
          LIMIT 1"
    );
    $stm->execute([':suf' => '%' . $sufixo]);
} else {
    echo json_encode(['ok' => false, 'msg' => 'Parâmetros insuficientes']);
    exit;
}
$cli = $stm->fetch();
if (!$cli) {
    echo json_encode(['ok' => false, 'msg' => 'Cliente não encontrado']);
    exit;
}

$clienteId = $cli['IDCliente'];
$nome      = $cli['Nome'];
$tel       = waNumero($cli['Telefone'] ?? '');
$nc        = explode(' ', trim($nome))[0]; // primeiro nome

// ── 2. Busca agendamentos relevantes por ação ─────────────────────────────────
$ags = [];

if ($acao === 'cobrar') {
    $stm = $pdo->prepare(
        'SELECT a.DataHoraAgendamento, a.ValorCobrado,
                COALESCE(ss.Nome, s.Nome) AS Servico
           FROM Agendamentos a
           JOIN Servicos s ON s.IDServico = a.FKServico
           LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
          WHERE a.FKCliente = :id
            AND a.StatusPagamento = "pendente"
            AND a.StatusAgendamento NOT IN ("cancelado")
          ORDER BY a.DataHoraAgendamento DESC LIMIT 3'
    );
    $stm->execute([':id' => $clienteId]);
    $ags = $stm->fetchAll();

} elseif (in_array($acao, ['lembrar', 'confirmar'], true)) {
    $filtro = $acao === 'confirmar' ? 'DATE(a.DataHoraAgendamento) = CURDATE()' : 'DATE(a.DataHoraAgendamento) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)';
    $stm = $pdo->prepare(
        "SELECT a.DataHoraAgendamento, a.ValorCobrado,
                COALESCE(ss.Nome, s.Nome) AS Servico
           FROM Agendamentos a
           JOIN Servicos s ON s.IDServico = a.FKServico
           LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
          WHERE a.FKCliente = :id
            AND {$filtro}
            AND a.StatusAgendamento IN ('pendente','confirmado')
          ORDER BY a.DataHoraAgendamento ASC LIMIT 1"
    );
    $stm->execute([':id' => $clienteId]);
    $ag = $stm->fetch();
    // fallback: próximo agendamento futuro
    if (!$ag) {
        $stm = $pdo->prepare(
            'SELECT a.DataHoraAgendamento, a.ValorCobrado,
                    COALESCE(ss.Nome, s.Nome) AS Servico
               FROM Agendamentos a
               JOIN Servicos s ON s.IDServico = a.FKServico
               LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
              WHERE a.FKCliente = :id
                AND a.DataHoraAgendamento >= NOW()
                AND a.StatusAgendamento IN ("pendente","confirmado")
              ORDER BY a.DataHoraAgendamento ASC LIMIT 1'
        );
        $stm->execute([':id' => $clienteId]);
        $ag = $stm->fetch();
    }
    if ($ag) $ags = [$ag];

} elseif ($acao === 'reagendar' && $agendId) {
    $stm = $pdo->prepare(
        'SELECT a.DataHoraAgendamento, a.ValorCobrado,
                COALESCE(ss.Nome, s.Nome) AS Servico
           FROM Agendamentos a
           JOIN Servicos s ON s.IDServico = a.FKServico
           LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
          WHERE a.IDAgendamento = :id LIMIT 1'
    );
    $stm->execute([':id' => $agendId]);
    $ag = $stm->fetch();
    if ($ag) $ags = [$ag];

} elseif ($acao === 'avaliacao') {
    $stm = $pdo->prepare(
        'SELECT a.DataHoraAgendamento, a.ValorCobrado,
                COALESCE(ss.Nome, s.Nome) AS Servico
           FROM Agendamentos a
           JOIN Servicos s ON s.IDServico = a.FKServico
           LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
          WHERE a.FKCliente = :id
            AND a.StatusAgendamento = "concluido"
          ORDER BY a.DataHoraAgendamento DESC LIMIT 1'
    );
    $stm->execute([':id' => $clienteId]);
    $ag = $stm->fetch();
    if ($ag) $ags = [$ag];
}

// ── 3. Monta contexto textual ─────────────────────────────────────────────────
$acaoLabel = [
    'cobrar'    => 'cobrar um pagamento pendente',
    'lembrar'   => 'lembrar do horário de amanhã',
    'confirmar' => 'confirmar a presença no horário de hoje',
    'reagendar' => 'informar que o horário precisou ser reagendado',
    'avaliacao' => 'pedir uma avaliação ou foto do resultado após o atendimento',
];

$ctxLinhas = [];
$totalPend = 0.0;
foreach ($ags as $ag) {
    $sv  = $ag['Servico'] ?? '';
    $dt  = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
    $hr  = date('H:i',   strtotime($ag['DataHoraAgendamento']));
    $val = (float)($ag['ValorCobrado'] ?? 0);
    $totalPend += $val;
    $ctxLinhas[] = "Serviço: {$sv} | Data: {$dt} às {$hr}" . ($val > 0 ? ' | Valor: R$ ' . number_format($val, 2, ',', '.') : '');
}
$ctxTexto = $ctxLinhas ? implode("\n", $ctxLinhas) : 'Sem agendamento específico identificado.';

if ($acao === 'cobrar' && count($ags) > 1 && $totalPend > 0) {
    $ctxTexto .= "\nTotal pendente: R$ " . number_format($totalPend, 2, ',', '.');
}

// ── 4. Gemini gera a mensagem ─────────────────────────────────────────────────
$mensagem = '';

if (defined('GEMINI_API_KEY') && GEMINI_API_KEY) {
    $prompt = <<<PROMPT
Você é assistente de uma designer de cílios do estúdio Belos Cílios (Brasil).
Sua tarefa: escrever UMA mensagem de WhatsApp para {$acaoLabel[$acao]}.

Cliente: {$nc} (nome completo: {$nome})
Contexto:
{$ctxTexto}

Regras obrigatórias:
- Comece SEMPRE com "Olá {$nc}!"
- Tom amigável, feminino, natural — como se fosse uma mensagem entre amigas
- Use NO MÁXIMO 2 emojis (escolha os mais adequados ao contexto)
- Máximo 4 frases curtas — seja direta
- Se houver valor, mencione-o claramente
- Se houver data/hora, mencione-a
- Não mencione o nome do estúdio
- Responda APENAS com o texto da mensagem, sem explicações ou aspas
PROMPT;

    $body = json_encode([
        'contents'          => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig'  => ['temperature' => 0.65, 'maxOutputTokens' => 200],
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/gemini-2.0-flash:generateContent?key=' . GEMINI_API_KEY);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $body,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_TIMEOUT        => 9,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $resp     = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $resp) {
        $dec = json_decode($resp, true);
        $mensagem = trim($dec['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }
}

// ── 5. Fallback: template com dados reais ────────────────────────────────────
if (!$mensagem) {
    $ag0 = $ags[0] ?? null;
    $sv  = $ag0['Servico'] ?? '';
    $hr  = $ag0 ? date('H:i', strtotime($ag0['DataHoraAgendamento'])) : '';
    $dt  = $ag0 ? date('d/m/Y', strtotime($ag0['DataHoraAgendamento'])) : '';
    $val = $ag0 && $ag0['ValorCobrado'] ? 'R$ ' . number_format((float)$ag0['ValorCobrado'], 2, ',', '.') : '';

    $tpls = [
        'cobrar'    => "Olá {$nc}! 💜 Passando para lembrar que o pagamento" . ($val ? " de {$val}" : '') . ($sv ? " de {$sv}" : '') . " está pendente. Pode me pagar assim que puder? Obrigada! 😊",
        'lembrar'   => "Olá {$nc}! 💜 Lembrando do seu horário" . ($sv ? " de {$sv}" : '') . ($hr ? " amanhã às {$hr}" : ' amanhã') . ". Qualquer dúvida é só chamar! Te espero 🥰",
        'confirmar' => "Olá {$nc}! 💜 Confirmando seu horário" . ($sv ? " de {$sv}" : '') . ($hr ? " hoje às {$hr}" : ' hoje') . ". Você consegue comparecer? 😊",
        'reagendar' => "Olá {$nc}! 😊 Precisei reagendar o horário" . ($sv ? " de {$sv}" : '') . ($dt ? " do dia {$dt}" : '') . ". Podemos combinar outro dia?",
        'avaliacao' => "Olá {$nc}! Que prazer ter você! 😍 Já viu como ficou o resultado? Manda uma foto, adoro ver! 💜",
    ];
    $mensagem = $tpls[$acao];
}

echo json_encode(['ok' => true, 'mensagem' => $mensagem, 'tel' => $tel], JSON_UNESCAPED_UNICODE);
