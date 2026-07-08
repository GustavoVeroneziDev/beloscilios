<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE . '/painel/agenda.php');
    exit;
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    redirecionarComMensagem(BASE . '/painel/agenda.php', 'Token inválido.', 'danger');
}

$fkCliente  = trim($_POST['fk_cliente']  ?? '');
$fkServico  = trim($_POST['fk_servico']  ?? '');
$dataHora   = trim($_POST['data_hora']   ?? '');
$valor      = trim($_POST['valor']       ?? '');
$obs        = trim($_POST['obs']         ?? '');

if (!$fkCliente || !$fkServico || !$dataHora) {
    redirecionarComMensagem(BASE . '/painel/agenda.php', 'Preencha todos os campos obrigatórios.', 'warning');
}

try {
    $servico = $pdo->prepare('SELECT DuracaoMinutos, Preco FROM Servicos WHERE IDServico = :id LIMIT 1');
    $servico->execute([':id' => $fkServico]);
    $servico = $servico->fetch();
    if (!$servico) {
        redirecionarComMensagem(BASE . '/painel/agenda.php', 'Serviço não encontrado.', 'danger');
    }

    $inicio = new DateTimeImmutable($dataHora);
    $fim    = $inicio->modify("+{$servico['DuracaoMinutos']} minutes");

    // Verifica conflito com agendamentos existentes
    $checkConflito = $pdo->prepare(
        'SELECT COUNT(*) FROM Agendamentos
         WHERE StatusAgendamento NOT IN (\'cancelado\')
           AND DataHoraAgendamento < :fim
           AND DataHoraFim > :ini'
    );
    $checkConflito->execute([
        ':ini' => $inicio->format('Y-m-d H:i:s'),
        ':fim' => $fim->format('Y-m-d H:i:s'),
    ]);
    if ((int)$checkConflito->fetchColumn() > 0) {
        redirecionarComMensagem(BASE . '/painel/agenda.php', 'Conflito: já existe agendamento neste horário.', 'warning');
    }

    $id = gerarUuid();
    $stmt = $pdo->prepare(
        'INSERT INTO Agendamentos
            (IDAgendamento, FKCliente, FKServico, DataHoraAgendamento, DataHoraFim,
             StatusAgendamento, ValorCobrado, Observacoes)
         VALUES (:id,:fkc,:fks,:ini,:fim,\'confirmado\',:valor,:obs)'
    );
    $stmt->execute([
        ':id'    => $id,
        ':fkc'   => $fkCliente,
        ':fks'   => $fkServico,
        ':ini'   => $inicio->format('Y-m-d H:i:s'),
        ':fim'   => $fim->format('Y-m-d H:i:s'),
        ':valor' => $valor !== '' ? (float)$valor : $servico['Preco'],
        ':obs'   => $obs ?: null,
    ]);
} catch (PDOException $e) {
    error_log('[SalvarAg] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/painel/agenda.php', 'Erro ao salvar agendamento.', 'danger');
}

redirecionarComMensagem(BASE . '/painel/agenda.php', 'Agendamento criado com sucesso!', 'success');
