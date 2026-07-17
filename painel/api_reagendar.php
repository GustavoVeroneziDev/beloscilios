<?php
/**
 * Reagenda um agendamento existente.
 * POST JSON: { "agendamento_id": uuid, "nova_data_hora": "YYYY-MM-DD HH:MM" }
 * Response: { "ok": bool, "novaData": "dd/mm/YYYY", "novaHora": "HH:MM",
 *             "nome": string, "tel": string, "cliId": uuid }
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método inválido']); exit;
}

$in   = json_decode(file_get_contents('php://input'), true) ?? [];
$agId = trim($in['agendamento_id'] ?? '');
$nova = trim($in['nova_data_hora'] ?? '');

if (!$agId || !$nova || !preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $nova)) {
    echo json_encode(['ok' => false, 'msg' => 'Dados inválidos']); exit;
}

try {
    $stm = $pdo->prepare(
        'SELECT a.IDAgendamento, a.FKCliente, a.StatusAgendamento,
                u.Nome AS NomeCliente, u.Telefone,
                s.DuracaoMinutos
           FROM Agendamentos a
           JOIN Usuarios u ON u.IDUsuario = a.FKCliente
           JOIN Servicos s ON s.IDServico = a.FKServico
          WHERE a.IDAgendamento = :id
          LIMIT 1'
    );
    $stm->execute([':id' => $agId]);
    $ag = $stm->fetch();

    if (!$ag) {
        echo json_encode(['ok' => false, 'msg' => 'Agendamento não encontrado']); exit;
    }
    if ($ag['StatusAgendamento'] === 'cancelado') {
        echo json_encode(['ok' => false, 'msg' => 'Não é possível reagendar um agendamento cancelado']); exit;
    }

    $novaIni = new DateTime($nova . ':00');
    $novaFim = (clone $novaIni)->modify('+' . (int)$ag['DuracaoMinutos'] . ' minutes');
    $iniStr  = $novaIni->format('Y-m-d H:i:s');
    $fimStr  = $novaFim->format('Y-m-d H:i:s');

    // Checa conflito excluindo o próprio agendamento
    $chk = $pdo->prepare(
        'SELECT COUNT(*) FROM Agendamentos
          WHERE IDAgendamento != :id
            AND StatusAgendamento NOT IN ("cancelado")
            AND DataHoraAgendamento < :fim
            AND DataHoraFim > :ini'
    );
    $chk->execute([':id' => $agId, ':ini' => $iniStr, ':fim' => $fimStr]);
    if ((int)$chk->fetchColumn() > 0) {
        echo json_encode(['ok' => false, 'msg' => 'Esse horário já está ocupado. Escolha outro.']); exit;
    }

    $pdo->prepare(
        'UPDATE Agendamentos
            SET DataHoraAgendamento = :ini, DataHoraFim = :fim, AtualizadoEm = NOW()
          WHERE IDAgendamento = :id'
    )->execute([':ini' => $iniStr, ':fim' => $fimStr, ':id' => $agId]);

    echo json_encode([
        'ok'       => true,
        'novaData' => $novaIni->format('d/m/Y'),
        'novaHora' => $novaIni->format('H:i'),
        'nome'     => $ag['NomeCliente'],
        'tel'      => waNumero($ag['Telefone'] ?? ''),
        'cliId'    => $ag['FKCliente'],
    ], JSON_UNESCAPED_UNICODE);

} catch (PDOException $e) {
    error_log('[Reagendar] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'msg' => 'Erro interno ao reagendar.']);
}
