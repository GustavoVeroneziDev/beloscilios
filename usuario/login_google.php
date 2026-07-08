<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || empty($_POST['credential'])) {
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Requisição inválida.', 'danger');
}

// Valida o JWT via tokeninfo usando cURL
$ch = curl_init();
curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($_POST['credential']));
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
$tokenInfoResponse = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$payload = $httpCode === 200 ? json_decode($tokenInfoResponse, true) : null;

$tokenValido = $payload
    && ($payload['aud'] ?? '') === ($clientID ?? '')
    && in_array($payload['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
    && ($payload['email_verified'] ?? 'false') === 'true'
    && !empty($payload['email']);

if (!$tokenValido) {
    error_log('[GoogleOAuth] Token inválido. httpCode=' . $httpCode . ' aud=' . ($payload['aud'] ?? 'none'));
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Erro ao validar com Google. Tente novamente.', 'danger');
}

$googleId = $payload['sub']   ?? '';
$email    = $payload['email'] ?? '';
$nome     = $payload['name']  ?? ($payload['given_name'] ?? explode('@', $email)[0]);

try {
    // Busca por GoogleId (login recorrente)
    $stmt = $pdo->prepare('SELECT IDUsuario, Nome, NivelAcesso FROM Usuarios WHERE GoogleId = :gid LIMIT 1');
    $stmt->execute([':gid' => $googleId]);
    $usuario = $stmt->fetch();

    if (!$usuario) {
        // Busca por e-mail (conta existente com senha)
        $stmt = $pdo->prepare('SELECT IDUsuario, Nome, NivelAcesso FROM Usuarios WHERE Email = :email LIMIT 1');
        $stmt->execute([':email' => $email]);
        $usuario = $stmt->fetch();

        if ($usuario) {
            // Vincula GoogleId à conta existente
            $pdo->prepare('UPDATE Usuarios SET GoogleId = :gid WHERE IDUsuario = :id')
                ->execute([':gid' => $googleId, ':id' => $usuario['IDUsuario']]);
        } else {
            // Cria nova conta — e-mail já verificado pelo Google
            $novoId = gerarUuid();
            $pdo->prepare(
                'INSERT INTO Usuarios (IDUsuario, Nome, Email, GoogleId, NivelAcesso, EmailVerificado)
                 VALUES (:id, :nome, :email, :gid, \'cliente\', 1)'
            )->execute([
                ':id'    => $novoId,
                ':nome'  => $nome,
                ':email' => $email,
                ':gid'   => $googleId,
            ]);
            $usuario = ['IDUsuario' => $novoId, 'Nome' => $nome, 'NivelAcesso' => 'cliente'];
        }
    }

    // Garante e-mail verificado em contas vinculadas ao Google
    $pdo->prepare('UPDATE Usuarios SET EmailVerificado = 1 WHERE IDUsuario = :id AND EmailVerificado = 0')
        ->execute([':id' => $usuario['IDUsuario']]);

    session_regenerate_id(true);
    $_SESSION['usuario_id']       = $usuario['IDUsuario'];
    $_SESSION['usuario_nome']     = $usuario['Nome'];
    $_SESSION['nivel_acesso']     = $usuario['NivelAcesso'];
    $_SESSION['email_verificado'] = true;

    if ($usuario['NivelAcesso'] === 'designer') {
        header('Location: ' . BASE . '/painel/index.php');
    } else {
        header('Location: ' . BASE . '/agendamento/index.php');
    }
    exit;
} catch (PDOException $e) {
    error_log('[GoogleOAuth] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/usuario/login.php', 'Erro interno. Tente novamente.', 'danger');
}
