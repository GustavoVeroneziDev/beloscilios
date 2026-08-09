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

$in        = json_decode(file_get_contents('php://input'), true) ?? [];
$acao      = trim($in['acao']           ?? '');
$agendId   = trim($in['agendamento_id'] ?? '');
$clienteId = trim($in['cliente_id']     ?? '');
$telInput  = preg_replace('/\D/', '', trim($in['tel'] ?? ''));

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
$nc        = explode(' ', trim($nome))[0];

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
    // Prioridade 1: agendamento específico selecionado (ex: botão na agenda)
    if ($agendId) {
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
    } else {
        // Prioridade 2: agendamento de hoje (confirmar) ou amanhã (lembrar)
        $filtro = $acao === 'confirmar'
            ? 'DATE(a.DataHoraAgendamento) = CURDATE()'
            : 'DATE(a.DataHoraAgendamento) = DATE_ADD(CURDATE(), INTERVAL 1 DAY)';

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

        // Prioridade 3: próximo agendamento futuro
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

// ── 3. Extrai variáveis principais (usadas no prompt E no fallback) ───────────
$ag0     = $ags[0] ?? null;
$sv0     = $ag0 ? ($ag0['Servico'] ?? '') : '';
$hr0     = $ag0 ? date('H:i', strtotime($ag0['DataHoraAgendamento'])) : '';
$dt0     = $ag0 ? date('d/m', strtotime($ag0['DataHoraAgendamento'])) : '';
$val0f   = $ag0 ? (float)($ag0['ValorCobrado'] ?? 0) : 0.0;

// Label temporal dinâmico — nunca diz "amanhã" quando não é amanhã
$dtLabel = '';
if ($ag0) {
    $dts    = strtotime($ag0['DataHoraAgendamento']);
    $hoje   = strtotime('today');
    $amanha = strtotime('tomorrow');
    if ($dts >= $hoje && $dts < $amanha) {
        $dtLabel = 'hoje';
    } elseif ($dts >= $amanha && $dts < $amanha + 86400) {
        $dtLabel = 'amanhã';
    } else {
        $dtLabel = 'dia ' . date('d/m', $dts);
    }
}

// Total pendente (cobrar: pode ter vários)
$totalPend = 0.0;
$ctxLinhas = [];
foreach ($ags as $ag) {
    $sv  = $ag['Servico'] ?? '';
    $dt  = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
    $hr  = date('H:i',   strtotime($ag['DataHoraAgendamento']));
    $val = (float)($ag['ValorCobrado'] ?? 0);
    $totalPend += $val;
    $ctxLinhas[] = "Serviço: {$sv} | Data: {$dt} às {$hr}" . ($val > 0 ? ' | Valor: R$ ' . number_format($val, 2, ',', '.') : '');
}
if ($acao === 'cobrar' && count($ags) > 1 && $totalPend > 0) {
    $ctxLinhas[] = "Total pendente: R$ " . number_format($totalPend, 2, ',', '.');
}
$ctxTexto = $ctxLinhas ? implode("\n", $ctxLinhas) : 'Sem agendamento específico identificado.';

// ── 4. Monta instrução obrigatória específica por ação ────────────────────────
$instrucaoObrig = '';
if ($ag0) {
    $totalStr = number_format($totalPend ?: $val0f, 2, ',', '.');
    if ($acao === 'lembrar' || $acao === 'confirmar') {
        $instrucaoObrig = "OBRIGATÓRIO: mencione o serviço (\"{$sv0}\") e o horário ({$dtLabel} às {$hr0}).";
    } elseif ($acao === 'cobrar') {
        $instrucaoObrig = "OBRIGATÓRIO: mencione o valor total em aberto (R$ {$totalStr})" . ($sv0 ? " e o serviço (\"{$sv0}\")" : '') . ".";
    } elseif ($acao === 'reagendar') {
        $instrucaoObrig = "OBRIGATÓRIO: mencione o serviço (\"{$sv0}\") e a data que estava marcada ({$dt0}).";
    } elseif ($acao === 'avaliacao') {
        $instrucaoObrig = "OBRIGATÓRIO: mencione o serviço realizado (\"{$sv0}\").";
    }
}

// ── 5. Gemini gera a mensagem ─────────────────────────────────────────────────
$acaoLabel = [
    'cobrar'    => 'cobrar um pagamento pendente',
    'lembrar'   => 'lembrar do horário marcado',
    'confirmar' => 'confirmar a presença no horário',
    'reagendar' => 'informar que o horário precisou ser reagendado',
    'avaliacao' => 'pedir uma avaliação ou foto do resultado após o atendimento',
];

$mensagem = '';

if (defined('GEMINI_API_KEY') && GEMINI_API_KEY) {
    $prompt = <<<PROMPT
Você é assistente de uma designer de cílios (Brasil).
Tarefa: escrever UMA mensagem de WhatsApp para {$acaoLabel[$acao]}.

Cliente: {$nc} (nome completo: {$nome})
Dados do agendamento:
{$ctxTexto}

{$instrucaoObrig}

Regras:
- Comece SEMPRE com "Olá {$nc}!"
- Tom amigável, feminino, natural — como se fosse uma mensagem entre amigas
- Use NO MÁXIMO 2 emojis
- Máximo 4 frases curtas — seja direta e objetiva
- Não mencione o nome do estúdio
- Responda APENAS com o texto da mensagem, sem explicações, aspas ou markdown
PROMPT;

    $body = json_encode([
        'contents'         => [['role' => 'user', 'parts' => [['text' => $prompt]]]],
        'generationConfig' => ['temperature' => 0.55, 'maxOutputTokens' => 200],
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

    if ($httpCode === 200 && $resp) {
        $dec = json_decode($resp, true);
        $mensagem = trim($dec['candidates'][0]['content']['parts'][0]['text'] ?? '');
    }
}

// ── 6. Fallback: templates completos com dados reais ─────────────────────────
if (!$mensagem) {
    $totalStr = number_format($totalPend ?: $val0f, 2, ',', '.');
    $svPart   = $sv0 ? " de {$sv0}" : '';
    $hrPart   = ($dtLabel && $hr0) ? " {$dtLabel} às {$hr0}" : ($dtLabel ? " {$dtLabel}" : '');
    $dtPart   = $dt0 ? " do dia {$dt0}" : '';

    $tpls = [
        'cobrar'    => "Olá {$nc}! 😊 Passando pra lembrar que o pagamento{$svPart} no valor de R$ {$totalStr} está em aberto. Pode me pagar assim que puder? Obrigada! 💜",
        'lembrar'   => "Olá {$nc}! 💜 Lembrando do seu horário{$svPart}{$hrPart}. Qualquer dúvida é só chamar! Te espero 🥰",
        'confirmar' => "Olá {$nc}! 💜 Confirmando seu horário{$svPart}{$hrPart}. Você consegue comparecer? 😊",
        'reagendar' => "Olá {$nc}! 😊 Precisei reagendar o horário{$svPart}{$dtPart}. Podemos combinar outro dia? Me chama aqui!",
        'avaliacao' => "Olá {$nc}! 💜 Que bom ter te atendido" . ($sv0 ? " com {$sv0}" : '') . "! Como está o resultado? Me manda uma foto, adoro ver! 😍",
    ];
    $mensagem = $tpls[$acao];
}

echo json_encode(['ok' => true, 'mensagem' => $mensagem, 'tel' => $tel], JSON_UNESCAPED_UNICODE);
