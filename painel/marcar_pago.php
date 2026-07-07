<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    redirecionarComMensagem('/beloscilios/painel/relatorio.php', 'Requisição inválida.', 'danger');
}

$id       = $_POST['id']       ?? '';
$redirect = $_POST['redirect'] ?? '/beloscilios/painel/relatorio.php';

if ($id) {
    try {
        $pdo->prepare(
            'UPDATE Agendamentos SET StatusPagamento=\'pago\' WHERE IDAgendamento=:id'
        )->execute([':id' => $id]);
        redirecionarComMensagem($redirect, 'Pagamento registrado!', 'success');
    } catch (PDOException $e) {
        error_log('[MarcarPago] ' . $e->getMessage());
        redirecionarComMensagem($redirect, 'Erro ao registrar.', 'danger');
    }
}

redirecionarComMensagem($redirect, 'ID inválido.', 'warning');
