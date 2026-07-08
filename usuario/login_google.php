<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
require_once '../config/funcoes.php';
require_once 'google_oauth.php';

$clientID = '808511905880-9jd31jmci1m9ibikht6r2vlerjeb8r4l.apps.googleusercontent.com';

// O Google Identity Services envia o ID token (JWT) via POST no campo 'credential'
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['credential'])) {

    // Valida o token direto no endpoint do Google (confere assinatura, expiração, issuer)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($_POST['credential']));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $tokenInfoResponse = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $payload = $httpCode === 200 ? json_decode($tokenInfoResponse, true) : null;

    $tokenValido = $payload
        && ($payload['aud'] ?? '') === $clientID
        && in_array($payload['iss'] ?? '', ['accounts.google.com', 'https://accounts.google.com'], true)
        && ($payload['email_verified'] ?? 'false') === 'true'
        && !empty($payload['email']);

    if ($tokenValido) {
        $email    = $payload['email'];
        $nome     = $payload['name'] ?? explode('@', $email)[0];
        $googleId = $payload['sub'] ?? '';

        // Procura se esse e-mail já existe na tabela Usuarios do Belos Cílios
        $sql = "SELECT IDUsuario, Nome, NivelAcesso, EmailVerificado, Ativo FROM Usuarios WHERE Email = :email LIMIT 1";
        $stmt = $pdo->prepare($sql);
        $stmt->execute([':email' => $email]);
        $usuario = $stmt->fetch();

        if ($usuario) {
            // JÁ EXISTE! 
            // Atualiza o Google ID e garante que o e-mail está verificado e a conta ativa
            $pdo->prepare("UPDATE Usuarios SET GoogleId = :gid, EmailVerificado = 1, Ativo = 1 WHERE IDUsuario = :uid")
                ->execute([':gid' => $googleId, ':uid' => $usuario['IDUsuario']]);

            // Cria as sessões e entra
            session_regenerate_id(true);
            $_SESSION['usuario_id']       = $usuario['IDUsuario'];
            $_SESSION['usuario_nome']     = $usuario['Nome'];
            $_SESSION['nivel_acesso']     = $usuario['NivelAcesso'];
            $_SESSION['email_verificado'] = true;

            // Redirecionamento específico do Belos Cílios
            if ($usuario['NivelAcesso'] === 'designer') {
                header('Location: ' . BASE . '/painel/index.php');
            } else {
                header('Location: ' . BASE . '/agendamento/index.php');
            }
            exit;
        } else {
            // NÃO EXISTE! VAMOS CADASTRAR NA HORA
            $id_novo_usuario = gerarUuid(); // Garanta que a função gerarUuid() esteja incluída
            $nivel_acesso = 'cliente';

            // Gera um token de 64 caracteres (perfeito para o seu varchar(64))
            $token_verificacao = bin2hex(random_bytes(32));
            $expiracao = date('Y-m-d H:i:s', strtotime('+24 hours'));

            // A sua coluna 'Senha' permite NULL, então gravamos NULL até ela decidir criar uma
            $sqlInsert = "INSERT INTO Usuarios (
                            IDUsuario, Nome, Email, EmailVerificado, TokenVerificacao, TokenVerificacaoExpira,
                            Senha, NivelAcesso, Ativo, GoogleId
                          ) VALUES (
                            :id, :nome, :email, 1, :token, :exp,
                            NULL, :nivel, 1, :gid
                          )";

            $pdo->prepare($sqlInsert)->execute([
                ':id'    => $id_novo_usuario,
                ':nome'  => $nome,
                ':email' => $email,
                ':token' => $token_verificacao,
                ':exp'   => $expiracao,
                ':nivel' => $nivel_acesso,
                ':gid'   => $googleId
            ]);

            // Monta o e-mail de boas-vindas com o link usando o token (Estilo Auralis)
            $link_criar_senha = "https://beloscilios.com/usuario/redefinir_senha.php?token=" . $token_verificacao;
            $primeiro_nome = explode(' ', $nome)[0];

            $mensagemHTML = "
            <!DOCTYPE html>
            <html lang='pt-BR'>
            <head>
                <meta charset='UTF-8'>
                <style>
                    body { font-family: Arial, sans-serif; background-color: #fcfcfc; margin: 0; padding: 0; }
                    .container { max-width: 550px; margin: 40px auto; background-color: #ffffff; border-radius: 8px; box-shadow: 0 4px 10px rgba(0,0,0,0.05); }
                    .header { background-color: #ffb6c1; padding: 25px; text-align: center; }
                    .content { padding: 40px 30px; color: #333333; line-height: 1.6; }
                    .btn { background-color: #ff6b81; color: #ffffff !important; padding: 14px 28px; text-decoration: none; border-radius: 6px; font-weight: bold; display: inline-block; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <img src='https://beloscilios.com/geral/img/LogoTransparente.png' alt='Belos Cílios' style='max-height: 55px;'>
                    </div>
                    <div class='content'>
                        <h2>Bem-vinda ao Belos Cílios, " . htmlspecialchars($primeiro_nome) . "!</h2>
                        <p>Sua conta foi criada com sucesso utilizando o seu acesso do Google.</p>
                        <p>Se você quiser acessar o sistema no futuro usando apenas o seu e-mail e uma senha manual, clique no botão abaixo para definir sua senha (o link é válido por 24 horas):</p>
                        <div style='text-align: center; margin: 35px 0;'>
                            <a href='" . $link_criar_senha . "' class='btn'>Criar Senha Manual</a>
                        </div>
                        <p><strong>Atenção:</strong> Se você prefere continuar entrando apenas clicando no botão do Google, basta ignorar este e-mail.</p>
                    </div>
                </div>
            </body>
            </html>
            ";

            // Se você tiver a função enviarEmail() pronta, basta descomentar a linha abaixo:
            // enviarEmail($email, "Bem-vinda ao Belos Cílios - Crie sua senha de acesso", $mensagemHTML);

            // Loga a nova usuária e redireciona
            session_regenerate_id(true);
            $_SESSION['usuario_id']       = $id_novo_usuario;
            $_SESSION['usuario_nome']     = $nome;
            $_SESSION['nivel_acesso']     = $nivel_acesso;
            $_SESSION['email_verificado'] = true;

            header('Location: ' . BASE . '/agendamento/index.php');
            exit;
        }
    }
}

// Se algo der errado na comunicação com o Google
header("Location: " . BASE . "/usuario/login.php?erro=google");
exit;
