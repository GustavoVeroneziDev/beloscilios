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
        global $pdo;
        if (isset($pdo)) tentarLoginLembrado($pdo);
    }
    if (!estaLogado()) {
        redirecionarComMensagem(BASE . '/usuario/login.php', 'Faça login para continuar.', 'warning');
    }
    if ($nivel && ($_SESSION['nivel_acesso'] ?? '') !== $nivel) {
        redirecionarComMensagem(BASE . '/index.php', 'Acesso não permitido.', 'danger');
    }
}

function criarTokenLembrarMe(PDO $pdo, string $idUsuario, int $dias = 30): void
{
    // Remove tokens expirados do usuário antes de criar um novo
    try {
        $pdo->prepare('DELETE FROM TokensLembrarMe WHERE FKUsuario = :id AND Expira < NOW()')
            ->execute([':id' => $idUsuario]);
    } catch (PDOException) {}

    $idToken    = gerarUuid();
    $tokenPlain = bin2hex(random_bytes(32));
    $tokenHash  = hash('sha256', $tokenPlain);
    $expira     = date('Y-m-d H:i:s', strtotime("+{$dias} days"));

    try {
        $pdo->prepare(
            'INSERT INTO TokensLembrarMe (IDToken, FKUsuario, TokenHash, Expira)
             VALUES (:id, :fku, :hash, :expira)'
        )->execute([':id' => $idToken, ':fku' => $idUsuario, ':hash' => $tokenHash, ':expira' => $expira]);
    } catch (PDOException $e) {
        error_log('[LembrarMe] Erro ao salvar token: ' . $e->getMessage());
        return;
    }

    $path = (defined('BASE') && BASE !== '') ? BASE . '/' : '/';
    setcookie('bc_lembrar', $idToken . ':' . $tokenPlain, [
        'expires'  => strtotime("+{$dias} days"),
        'path'     => $path,
        'secure'   => isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
}

function tentarLoginLembrado(PDO $pdo): void
{
    if (estaLogado() || empty($_COOKIE['bc_lembrar'])) return;

    $partes = explode(':', $_COOKIE['bc_lembrar'], 2);
    if (count($partes) !== 2) {
        _limparCookieLembrarMe();
        return;
    }
    [$idToken, $tokenPlain] = $partes;

    try {
        $stmt = $pdo->prepare(
            'SELECT t.IDToken, t.FKUsuario, t.TokenHash,
                    u.Nome, u.NivelAcesso, u.EmailVerificado, u.Ativo
             FROM TokensLembrarMe t
             JOIN Usuarios u ON u.IDUsuario = t.FKUsuario
             WHERE t.IDToken = :id AND t.Expira > NOW()
             LIMIT 1'
        );
        $stmt->execute([':id' => $idToken]);
        $row = $stmt->fetch();
    } catch (PDOException $e) {
        error_log('[LembrarMe] ' . $e->getMessage());
        return;
    }

    if (!$row || !$row['Ativo']) {
        _limparCookieLembrarMe();
        return;
    }

    if (!hash_equals($row['TokenHash'], hash('sha256', $tokenPlain))) {
        // Hash inválido — possível roubo de cookie; invalida todos os tokens do usuário
        try {
            $pdo->prepare('DELETE FROM TokensLembrarMe WHERE FKUsuario = :id')
                ->execute([':id' => $row['FKUsuario']]);
        } catch (PDOException) {}
        _limparCookieLembrarMe();
        error_log('[LembrarMe] Token inválido para usuário ' . $row['FKUsuario'] . ' — todos os tokens apagados.');
        return;
    }

    // Token válido: apaga o atual e emite um novo (rotação)
    try {
        $pdo->prepare('DELETE FROM TokensLembrarMe WHERE IDToken = :id')
            ->execute([':id' => $idToken]);
    } catch (PDOException) {}

    session_regenerate_id(true);
    $_SESSION['usuario_id']       = $row['FKUsuario'];
    $_SESSION['usuario_nome']     = $row['Nome'];
    $_SESSION['nivel_acesso']     = $row['NivelAcesso'];
    $_SESSION['email_verificado'] = (bool) $row['EmailVerificado'];

    criarTokenLembrarMe($pdo, $row['FKUsuario']);
}

function _limparCookieLembrarMe(): void
{
    $path = (defined('BASE') && BASE !== '') ? BASE . '/' : '/';
    setcookie('bc_lembrar', '', [
        'expires'  => time() - 3600,
        'path'     => $path,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    unset($_COOKIE['bc_lembrar']);
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

function formatarTelefoneExibicao(?string $tel): string
{
    if (!$tel) return '';
    $d = preg_replace('/\D/', '', $tel);
    if (strlen($d) === 13 && str_starts_with($d, '55')) {
        $d = substr($d, 2);
    }
    if (strlen($d) === 11) {
        return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7);
    }
    if (strlen($d) === 10) {
        return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6);
    }
    return $tel;
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
