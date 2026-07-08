<?php
/**
 * Mailer — envio de e-mail via SMTP (HostGator) ou mail() nativo como fallback.
 * Não contém credenciais; requer config/smtp_keys.php com as constantes SMTP_*.
 */

function enviarEmail(string $para, string $assunto, string $htmlBody, string $textoBody = ''): bool
{
    if (!$textoBody) {
        $textoBody = wordwrap(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $htmlBody)), 76);
    }

    if (defined('SMTP_HOST') && SMTP_HOST) {
        return _mailerSmtp($para, $assunto, $htmlBody, $textoBody);
    }

    return _mailerNativo($para, $assunto, $htmlBody, $textoBody);
}

function _mailerNativo(string $para, string $assunto, string $html, string $texto): bool
{
    $from = defined('SMTP_FROM')      ? SMTP_FROM      : 'noreply@beloscilios.com';
    $nome = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME : 'Belos Cilios';
    $b    = md5(uniqid('bc_', true));

    $hdrs  = "From: =?UTF-8?B?" . base64_encode($nome) . "?= <{$from}>\r\n";
    $hdrs .= "MIME-Version: 1.0\r\n";
    $hdrs .= "Content-Type: multipart/alternative; boundary=\"{$b}\"\r\n";
    $hdrs .= "X-Mailer: BelosCilios/1.0";

    $body  = "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$texto}\r\n\r\n";
    $body .= "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n\r\n--{$b}--";

    return mail($para, '=?UTF-8?B?' . base64_encode($assunto) . '?=', $body, $hdrs);
}

function _mailerSmtp(string $para, string $assunto, string $html, string $texto): bool
{
    $host   = SMTP_HOST;
    $port   = defined('SMTP_PORT')      ? (int)SMTP_PORT   : 465;
    $secure = defined('SMTP_SECURE')    ? SMTP_SECURE       : 'ssl';
    $user   = SMTP_USER;
    $pass   = SMTP_PASS;
    $from   = defined('SMTP_FROM')      ? SMTP_FROM         : $user;
    $nome   = defined('SMTP_FROM_NAME') ? SMTP_FROM_NAME    : 'Belos Cilios';

    $ctx  = stream_context_create([
        'ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true],
    ]);
    $addr = ($secure === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
    $sock = @stream_socket_client($addr, $errno, $errstr, 15, STREAM_CLIENT_CONNECT, $ctx);

    if (!$sock) {
        error_log("[Mailer] Conexão falhou: {$errstr} ({$errno})");
        return false;
    }
    stream_set_timeout($sock, 15);

    $recv = function () use ($sock): string {
        $buf = '';
        while (!feof($sock)) {
            $line = fgets($sock, 512);
            if ($line === false) break;
            $buf .= $line;
            // Linha de resposta final: 4 chars, 4º é espaço (ex: "250 OK")
            if (strlen($line) >= 4 && $line[3] === ' ') break;
        }
        return $buf;
    };

    $cmd = function (string $c) use ($sock, $recv): string {
        fwrite($sock, $c . "\r\n");
        return $recv();
    };

    $recv(); // saudação do servidor

    $cmd('EHLO ' . (gethostname() ?: 'beloscilios.com'));

    if ($secure === 'tls') {
        $cmd('STARTTLS');
        stream_socket_enable_crypto($sock, true, STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT);
        $cmd('EHLO ' . (gethostname() ?: 'beloscilios.com'));
    }

    $cmd('AUTH LOGIN');
    $cmd(base64_encode($user));
    $authResp = $cmd(base64_encode($pass));

    if (!str_starts_with(ltrim($authResp), '235')) {
        error_log("[Mailer] AUTH LOGIN falhou: {$authResp}");
        fclose($sock);
        return false;
    }

    $cmd("MAIL FROM:<{$from}>");
    $cmd("RCPT TO:<{$para}>");
    $cmd('DATA');

    $b    = md5(uniqid('bc_', true));
    $msg  = "From: =?UTF-8?B?" . base64_encode($nome) . "?= <{$from}>\r\n";
    $msg .= "To: <{$para}>\r\n";
    $msg .= "Subject: =?UTF-8?B?" . base64_encode($assunto) . "?=\r\n";
    $msg .= "MIME-Version: 1.0\r\n";
    $msg .= "Content-Type: multipart/alternative; boundary=\"{$b}\"\r\n";
    $msg .= "X-Mailer: BelosCilios/1.0\r\n\r\n";
    $msg .= "--{$b}\r\nContent-Type: text/plain; charset=UTF-8\r\n\r\n{$texto}\r\n\r\n";
    $msg .= "--{$b}\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n{$html}\r\n\r\n--{$b}--\r\n";

    // SMTP dot-stuffing: linhas que começam com '.' precisam de um '.' extra
    $msg = preg_replace('/^\./m', '..', $msg);

    fwrite($sock, $msg . "\r\n.\r\n");
    $dataResp = $recv();
    $ok = str_starts_with(ltrim($dataResp), '250');

    if (!$ok) error_log("[Mailer] DATA rejeitado: {$dataResp}");

    $cmd('QUIT');
    fclose($sock);
    return $ok;
}

// ── Templates de e-mail ──────────────────────────────────────────────────────

function emailHtml(string $titulo, string $corpo): string
{
    $t = htmlspecialchars($titulo, ENT_QUOTES, 'UTF-8');
    return <<<HTML
<!DOCTYPE html><html lang="pt-BR">
<head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>{$t}</title></head>
<body style="margin:0;padding:0;background:#faf5ff;font-family:'Segoe UI',Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#faf5ff;padding:32px 16px;">
<tr><td align="center">
<table width="100%" style="max-width:520px;background:#fff;border-radius:14px;border:1px solid #e0aaff;overflow:hidden;box-shadow:0 2px 12px rgba(16,0,43,.07);">
  <tr><td style="background:#10002b;padding:20px 28px;text-align:center;">
    <span style="color:#b38cff;font-size:1.15rem;font-weight:700;">Belos C&iacute;lios</span>
  </td></tr>
  <tr><td style="padding:28px 32px;color:#10002b;line-height:1.6;">
    {$corpo}
  </td></tr>
  <tr><td style="background:#f3e8ff;padding:14px 32px;text-align:center;font-size:12px;color:#6739c7;">
    Este e-mail foi gerado automaticamente &mdash; n&atilde;o responda.
  </td></tr>
</table>
</td></tr>
</table>
</body></html>
HTML;
}

function enviarEmailVerificacao(string $email, string $nome, string $token): bool
{
    $base = defined('BASE') ? BASE : '';
    $host = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
          . '://' . ($_SERVER['HTTP_HOST'] ?? 'beloscilios.com');
    $link = $host . $base . '/usuario/verificar_email.php?token=' . urlencode($token);

    $n = htmlspecialchars($nome, ENT_QUOTES, 'UTF-8');
    $l = htmlspecialchars($link, ENT_QUOTES, 'UTF-8');

    $corpo = <<<HTML
<h2 style="color:#5a189a;margin:0 0 16px;">Confirme seu e-mail</h2>
<p>Ol&aacute;, <strong>{$n}</strong>!</p>
<p>Clique no bot&atilde;o abaixo para verificar seu e-mail e receber lembretes de agendamento:</p>
<p style="text-align:center;margin:28px 0;">
  <a href="{$l}"
     style="background:#5a189a;color:#fff;padding:13px 30px;border-radius:8px;
            text-decoration:none;font-weight:600;font-size:15px;display:inline-block;">
    Verificar e-mail
  </a>
</p>
<p style="font-size:13px;color:#888;">
  Ou copie este link no navegador:<br>
  <a href="{$l}" style="color:#5a189a;font-size:12px;word-break:break-all;">{$l}</a>
</p>
<p style="font-size:12px;color:#aaa;">Se n&atilde;o foi voc&ecirc;, ignore este e-mail. O link expira em 24 horas.</p>
HTML;

    return enviarEmail($email, 'Verifique seu e-mail — Belos Cílios', emailHtml('Verificar e-mail', $corpo));
}

function enviarEmailConfirmacaoAgendamento(
    string $email,
    string $nome,
    string $servico,
    string $data,
    string $hora,
    string $valor
): bool {
    $n = htmlspecialchars($nome,    ENT_QUOTES, 'UTF-8');
    $s = htmlspecialchars($servico, ENT_QUOTES, 'UTF-8');
    $d = htmlspecialchars($data,    ENT_QUOTES, 'UTF-8');
    $h = htmlspecialchars($hora,    ENT_QUOTES, 'UTF-8');
    $v = htmlspecialchars($valor,   ENT_QUOTES, 'UTF-8');

    $corpo = <<<HTML
<h2 style="color:#5a189a;margin:0 0 16px;">Agendamento confirmado!</h2>
<p>Ol&aacute;, <strong>{$n}</strong>! Seu hor&aacute;rio est&aacute; reservado. Te esperamos!</p>
<table width="100%" style="border-collapse:collapse;margin:20px 0;font-size:15px;">
  <tr>
    <td style="padding:11px 0;border-bottom:1px solid #e0aaff;color:#666;width:38%;">Servi&ccedil;o</td>
    <td style="padding:11px 0;border-bottom:1px solid #e0aaff;font-weight:600;">{$s}</td>
  </tr>
  <tr>
    <td style="padding:11px 0;border-bottom:1px solid #e0aaff;color:#666;">Data</td>
    <td style="padding:11px 0;border-bottom:1px solid #e0aaff;font-weight:600;">{$d}</td>
  </tr>
  <tr>
    <td style="padding:11px 0;border-bottom:1px solid #e0aaff;color:#666;">Hor&aacute;rio</td>
    <td style="padding:11px 0;border-bottom:1px solid #e0aaff;font-weight:600;">{$h}</td>
  </tr>
  <tr>
    <td style="padding:11px 0;color:#666;">Valor</td>
    <td style="padding:11px 0;font-weight:700;color:#5a189a;font-size:16px;">{$v}</td>
  </tr>
</table>
<p style="font-size:13px;color:#888;">
  Para cancelar ou reagendar, entre em contato via WhatsApp.
</p>
HTML;

    return enviarEmail(
        $email,
        "Agendamento confirmado — {$servico}",
        emailHtml('Agendamento confirmado', $corpo)
    );
}
