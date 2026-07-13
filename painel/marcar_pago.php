<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    redirecionarComMensagem(BASE . '/painel/relatorio.php', 'Requisição inválida.', 'danger');
}

$id = trim($_POST['id'] ?? '');

// Redireciona sempre para o relatório — não aceita URL externa do POST
$redirect = BASE . '/painel/relatorio.php';

if ($id) {
    try {
        $pdo->prepare(
            'UPDATE Agendamentos SET StatusPagamento=\'pago\'
             WHERE IDAgendamento=:id
               AND StatusAgendamento != \'cancelado\'
               AND StatusPagamento   != \'pago\''
        )->execute([':id' => $id]);
        redirecionarComMensagem($redirect, 'Pagamento registrado!', 'success');
    } catch (PDOException $e) {
        error_log('[MarcarPago] ' . $e->getMessage());
        redirecionarComMensagem($redirect, 'Erro ao registrar.', 'danger');
    }
}

redirecionarComMensagem($redirect, 'ID inválido.', 'warning');
