<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';

header('Content-Type: application/json');

if (!estaLogado()) {
    echo json_encode(['ok' => false, 'msg' => 'Não autenticado']);
    exit;
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'Token inválido.']);
    exit;
}

$data      = trim($_POST['data']       ?? '');
$hora      = trim($_POST['hora']       ?? '');
$servicoId = trim($_POST['servico_id'] ?? '');
$subId     = trim($_POST['sub_id']     ?? '') ?: null;

if (!$data || !$hora || !$servicoId) {
    echo json_encode(['ok' => false, 'msg' => 'Dados incompletos']);
    exit;
}

// Busca duração real do banco — não confiar no valor enviado pelo cliente
try {
    if ($subId) {
        $durQ = $pdo->prepare('SELECT DuracaoMinutos FROM SubServicos WHERE IDSubServico = :id AND Ativo = 1 LIMIT 1');
        $durQ->execute([':id' => $subId]);
    } else {
        $durQ = $pdo->prepare('SELECT DuracaoMinutos FROM Servicos WHERE IDServico = :id AND Ativo = 1 LIMIT 1');
        $durQ->execute([':id' => $servicoId]);
    }
    $svRow = $durQ->fetch();
    if (!$svRow) {
        echo json_encode(['ok' => false, 'msg' => 'Serviço não encontrado.']);
        exit;
    }
    $duracao = (int) $svRow['DuracaoMinutos'];
} catch (PDOException $e) {
    error_log('[ReservarSlot] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Erro interno. Tente novamente.']);
    exit;
}

$dataHora = "{$data} {$hora}:00";
$inicio   = new DateTimeImmutable($dataHora);
$fimSlot  = $inicio->modify("+{$duracao} minutes");
$expira   = (new DateTimeImmutable())->modify('+10 minutes');
$sessao   = session_id();

try {
    // Limpa reservas expiradas (fora da transação — manutenção)
    $pdo->exec("DELETE FROM ReservasTemporarias WHERE ExpiraEm < NOW()");

    $pdo->beginTransaction();

    // Verifica conflito com agendamentos confirmados
    $check = $pdo->prepare(
        'SELECT COUNT(*) FROM Agendamentos
         WHERE StatusAgendamento NOT IN (\'cancelado\')
           AND DataHoraAgendamento < :fim
           AND DataHoraFim > :ini'
    );
    $check->execute([':ini' => $inicio->format('Y-m-d H:i:s'), ':fim' => $fimSlot->format('Y-m-d H:i:s')]);
    if ((int)$check->fetchColumn() > 0) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Horário já reservado por outra pessoa.']);
        exit;
    }

    // Verifica conflito com outras reservas temporárias (não da própria sessão)
    $checkTemp = $pdo->prepare(
        'SELECT COUNT(*) FROM ReservasTemporarias
         WHERE TokenSessao != :sessao
           AND DataHoraSlot < :fim
           AND DataHoraFim > :ini
           AND ExpiraEm > NOW()'
    );
    $checkTemp->execute([':sessao' => $sessao, ':ini' => $inicio->format('Y-m-d H:i:s'), ':fim' => $fimSlot->format('Y-m-d H:i:s')]);
    if ((int)$checkTemp->fetchColumn() > 0) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Alguém está finalizando o agendamento neste horário. Tente outro.']);
        exit;
    }

    // Verifica bloqueios de horário
    $checkBloq = $pdo->prepare(
        'SELECT COUNT(*) FROM BloqueiosAgenda
         WHERE DataInicio < :fim AND DataFim > :ini'
    );
    $checkBloq->execute([':ini' => $inicio->format('Y-m-d H:i:s'), ':fim' => $fimSlot->format('Y-m-d H:i:s')]);
    if ((int)$checkBloq->fetchColumn() > 0) {
        $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Este horário não está disponível para agendamento.']);
        exit;
    }

    // Remove reserva anterior desta sessão e cria a nova
    $pdo->prepare('DELETE FROM ReservasTemporarias WHERE TokenSessao = :s')->execute([':s' => $sessao]);

    $id = gerarUuid();
    $pdo->prepare(
        'INSERT INTO ReservasTemporarias
            (IDReserva, TokenSessao, FKServico, FKSubServico,
             DataHoraSlot, DataHoraFim, DuracaoMinutos, ExpiraEm)
         VALUES (:id, :s, :srv, :sub, :ini, :fim, :dur, :exp)'
    )->execute([
        ':id'  => $id,    ':s'   => $sessao,
        ':srv' => $servicoId, ':sub' => $subId,
        ':ini' => $inicio->format('Y-m-d H:i:s'),
        ':fim' => $fimSlot->format('Y-m-d H:i:s'),
        ':dur' => $duracao,
        ':exp' => $expira->format('Y-m-d H:i:s'),
    ]);

    $pdo->commit();

    echo json_encode(['ok' => true, 'expira' => $expira->format('Y-m-d H:i:s'), 'token' => $id]);
} catch (PDOException $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ReservarSlot] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Erro interno. Tente novamente.']);
}
