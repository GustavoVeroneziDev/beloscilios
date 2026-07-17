<?php
/**
 * Reverte status de agendamento ou pagamento.
 * POST: csrf_token, acao (reabrir|estornar_pagamento), id
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    redirecionarComMensagem(BASE . '/painel/relatorio.php', 'Requisição inválida.', 'danger');
}

$acao  = trim($_POST['acao'] ?? '');
$id    = trim($_POST['id']   ?? '');
$volta = trim($_POST['redirect'] ?? BASE . '/painel/relatorio.php');
// Garante que o redirect é interno
if (strpos($volta, BASE . '/painel/') !== 0) {
    $volta = BASE . '/painel/relatorio.php';
}

if (!$id) {
    redirecionarComMensagem($volta, 'ID inválido.', 'warning');
}

try {
    if ($acao === 'reabrir') {
        $pdo->prepare(
            'UPDATE Agendamentos SET StatusAgendamento = "confirmado", AtualizadoEm = NOW()
              WHERE IDAgendamento = :id AND StatusAgendamento = "concluido"'
        )->execute([':id' => $id]);
        redirecionarComMensagem($volta, 'Atendimento reaberto como confirmado.', 'success');

    } elseif ($acao === 'estornar_pagamento') {
        $pdo->prepare(
            'UPDATE Agendamentos SET StatusPagamento = "pendente", AtualizadoEm = NOW()
              WHERE IDAgendamento = :id AND StatusPagamento = "pago"'
        )->execute([':id' => $id]);
        redirecionarComMensagem($volta, 'Pagamento revertido para pendente.', 'success');

    } else {
        redirecionarComMensagem($volta, 'Ação desconhecida.', 'warning');
    }
} catch (PDOException $e) {
    error_log('[ReverterStatus] ' . $e->getMessage());
    redirecionarComMensagem($volta, 'Erro ao reverter.', 'danger');
}
