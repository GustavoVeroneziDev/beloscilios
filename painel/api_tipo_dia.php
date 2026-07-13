<?php
/**
 * AJAX — atribui ou remove um TipoDia de uma data específica.
 * POST acao=set  data=YYYY-MM-DD fk_tipo=UUID  → upsert DiasEspeciais
 * POST acao=remove data=YYYY-MM-DD              → delete DiasEspeciais
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método inválido']);
    exit;
}
if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'Token inválido']);
    exit;
}

$acao = trim($_POST['acao'] ?? '');
$data = trim($_POST['data'] ?? '');

if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $data)) {
    echo json_encode(['ok' => false, 'msg' => 'Data inválida']);
    exit;
}

switch ($acao) {

    case 'set':
        $fkTipo = trim($_POST['fk_tipo'] ?? '');
        if (!$fkTipo) { echo json_encode(['ok' => false, 'msg' => 'Tipo inválido']); exit; }

        $stmt = $pdo->prepare(
            'SELECT IDTipo, Nome, Cor, BloqueiaTotal, HoraInicio, HoraFim
             FROM TiposDia WHERE IDTipo = :id'
        );
        $stmt->execute([':id' => $fkTipo]);
        $tipo = $stmt->fetch();
        if (!$tipo) { echo json_encode(['ok' => false, 'msg' => 'Tipo não encontrado']); exit; }

        // Aviso: tipo que bloqueia o dia inteiro + agendamentos confirmados nessa data
        $aviso = null;
        if ($tipo['BloqueiaTotal']) {
            $cntAg = $pdo->prepare(
                'SELECT COUNT(*) FROM Agendamentos
                 WHERE DATE(DataHoraAgendamento) = :d
                   AND StatusAgendamento NOT IN (\'cancelado\')'
            );
            $cntAg->execute([':d' => $data]);
            $qtdAg = (int)$cntAg->fetchColumn();
            if ($qtdAg > 0) {
                $aviso = "Há {$qtdAg} agendamento(s) neste dia. Cancelamentos devem ser feitos manualmente.";
            }
        }

        try {
            $ex = $pdo->prepare('SELECT IDDiaEspecial FROM DiasEspeciais WHERE Data = :d');
            $ex->execute([':d' => $data]);
            if ($ex->fetch()) {
                $pdo->prepare('UPDATE DiasEspeciais SET FKTipo = :fk WHERE Data = :d')
                    ->execute([':fk' => $fkTipo, ':d' => $data]);
            } else {
                $pdo->prepare('INSERT INTO DiasEspeciais (IDDiaEspecial, Data, FKTipo) VALUES (:id, :d, :fk)')
                    ->execute([':id' => gerarUuid(), ':d' => $data, ':fk' => $fkTipo]);
            }
            echo json_encode([
                'ok'   => true,
                'aviso'=> $aviso,
                'tipo' => [
                    'id'           => $tipo['IDTipo'],
                    'nome'         => $tipo['Nome'],
                    'cor'          => $tipo['Cor'],
                    'bloqueiaTotal'=> (bool)$tipo['BloqueiaTotal'],
                    'horaInicio'   => $tipo['HoraInicio'] ? substr($tipo['HoraInicio'], 0, 5) : null,
                    'horaFim'      => $tipo['HoraFim']    ? substr($tipo['HoraFim'],    0, 5) : null,
                ],
            ]);
        } catch (PDOException $e) {
            error_log('[TipoDia] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'Erro ao salvar']);
        }
        break;

    case 'remove':
        try {
            $pdo->prepare('DELETE FROM DiasEspeciais WHERE Data = :d')->execute([':d' => $data]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            error_log('[TipoDia] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'Erro ao remover']);
        }
        break;

    default:
        echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida']);
}
