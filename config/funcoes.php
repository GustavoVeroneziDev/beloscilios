<?php

function gerarUuid(): string
{
    $data = random_bytes(16);
    $data[6] = chr(ord($data[6]) & 0x0f | 0x40);
    $data[8] = chr(ord($data[8]) & 0x3f | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
}

function sanitizarTelefone(string $tel): ?string
{
    $tel = preg_replace('/\D/', '', $tel);
    $tel = ltrim($tel, '0');

    if (strlen($tel) === 13 && str_starts_with($tel, '55')) {
        return $tel;
    }
    if (strlen($tel) === 11) {
        return '55' . $tel;
    }
    if (strlen($tel) === 10) {
        return '55' . substr($tel, 0, 2) . '9' . substr($tel, 2);
    }

    return null;
}

function enviarWhatsApp(string $numero, string $mensagem): bool
{
    if (!defined('EVOLUTION_URL') || !defined('EVOLUTION_INSTANCE') || !defined('EVOLUTION_KEY')) {
        error_log('[WhatsApp] Evolution API não configurada.');
        return false;
    }

    $url     = rtrim(EVOLUTION_URL, '/') . '/message/sendText/' . EVOLUTION_INSTANCE;
    $payload = json_encode(['number' => $numero, 'text' => $mensagem]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'apikey: ' . EVOLUTION_KEY,
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        return true;
    }

    error_log("[WhatsApp] HTTP {$httpCode}: {$response}");
    return false;
}

function redirecionarComMensagem(string $url, string $msg, string $tipo): never
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['flash_msg']  = $msg;
    $_SESSION['flash_tipo'] = $tipo;
    header('Location: ' . $url);
    exit;
}

function gerarTokenCSRF(): string
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function validarTokenCSRF(?string $token): bool
{
    return !empty($token)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $token);
}

function estaLogado(): bool
{
    return !empty($_SESSION['usuario_id']);
}

function exigirLogin(string $nivel = ''): void
{
    if (!estaLogado()) {
        redirecionarComMensagem('/beloscilios/usuario/login.php', 'Faça login para continuar.', 'warning');
    }
    if ($nivel && ($_SESSION['nivel_acesso'] ?? '') !== $nivel) {
        redirecionarComMensagem('/beloscilios/index.php', 'Acesso não permitido.', 'danger');
    }
}

function h(mixed $str): string
{
    return htmlspecialchars((string) $str, ENT_QUOTES, 'UTF-8');
}

function flashMsg(): void
{
    if (!empty($_SESSION['flash_msg'])) {
        $tipo = h($_SESSION['flash_tipo'] ?? 'info');
        $msg  = h($_SESSION['flash_msg']);
        echo "<div class=\"alert alert-{$tipo} alert-dismissible fade show mb-3\" role=\"alert\">"
            . "<i class=\"bi bi-info-circle me-2\"></i>{$msg}"
            . '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>'
            . '</div>';
        unset($_SESSION['flash_msg'], $_SESSION['flash_tipo']);
    }
}

function registrarLogWhatsApp(
    PDO $pdo,
    string $numero,
    string $mensagem,
    string $tipo,
    string $status,
    ?string $fkAgendamento = null
): void {
    try {
        $stmt = $pdo->prepare(
            'INSERT INTO LogsWhatsApp (IDLog, FKAgendamento, Numero, Mensagem, TipoMensagem, StatusEnvio)
             VALUES (:id, :fka, :num, :msg, :tipo, :status)'
        );
        $stmt->execute([
            ':id'     => gerarUuid(),
            ':fka'    => $fkAgendamento,
            ':num'    => $numero,
            ':msg'    => $mensagem,
            ':tipo'   => $tipo,
            ':status' => $status,
        ]);
    } catch (PDOException $e) {
        error_log('[LogWA] ' . $e->getMessage());
    }
}

function getConfig(PDO $pdo, string $chave, string $padrao = ''): string
{
    try {
        $stmt = $pdo->prepare('SELECT Valor FROM ConfiguracoesSistema WHERE Chave = :chave LIMIT 1');
        $stmt->execute([':chave' => $chave]);
        $row = $stmt->fetch();
        return $row ? (string) $row['Valor'] : $padrao;
    } catch (PDOException) {
        return $padrao;
    }
}

function setConfig(PDO $pdo, string $chave, string $valor): void
{
    $stmt = $pdo->prepare(
        'INSERT INTO ConfiguracoesSistema (IDConfig, Chave, Valor)
         VALUES (:id, :chave, :valor)
         ON DUPLICATE KEY UPDATE Valor = :valor2, AtualizadoEm = CURRENT_TIMESTAMP'
    );
    $stmt->execute([
        ':id'     => gerarUuid(),
        ':chave'  => $chave,
        ':valor'  => $valor,
        ':valor2' => $valor,
    ]);
}

function formatarMoeda(float $valor): string
{
    return 'R$ ' . number_format($valor, 2, ',', '.');
}

function formatarDataHora(string $datetime): string
{
    return date('d/m/Y \à\s H:i', strtotime($datetime));
}

function formatarData(string $date): string
{
    return date('d/m/Y', strtotime($date));
}

function labelStatus(string $status): string
{
    return match ($status) {
        'pendente'   => '<span class="badge bg-warning text-dark">Pendente</span>',
        'confirmado' => '<span class="badge bg-success">Confirmado</span>',
        'cancelado'  => '<span class="badge bg-danger">Cancelado</span>',
        'concluido'  => '<span class="badge bg-secondary">Concluído</span>',
        default      => '<span class="badge bg-light text-dark">' . h($status) . '</span>',
    };
}

function labelStatusPag(string $status): string
{
    return match ($status) {
        'pendente'  => '<span class="badge bg-warning text-dark">A receber</span>',
        'pago'      => '<span class="badge bg-success">Pago</span>',
        'cancelado' => '<span class="badge bg-danger">Cancelado</span>',
        default     => '<span class="badge bg-light text-dark">' . h($status) . '</span>',
    };
}
