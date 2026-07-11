<?php
/**
 * AJAX — operações em lote sobre múltiplas datas.
 * POST acao=set_tipo_bulk   datas[]=YYYY-MM-DD... fk_tipo=UUID  → upsert DiasEspeciais
 * POST acao=rem_tipo_bulk   datas[]=YYYY-MM-DD...               → delete DiasEspeciais
 * POST acao=bloquear_bulk   datas[]=YYYY-MM-DD... motivo=str    → insert BloqueiosAgenda (dia inteiro)
 */
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'msg' => 'Método inválido']); exit;
}
if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    echo json_encode(['ok' => false, 'msg' => 'Token inválido']); exit;
}

$acao  = trim($_POST['acao'] ?? '');
$datas = array_filter(
    array_map('trim', (array)($_POST['datas'] ?? [])),
    fn($d) => preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)
);

if (empty($datas)) {
    echo json_encode(['ok' => false, 'msg' => 'Nenhuma data válida']); exit;
}

switch ($acao) {

    case 'set_tipo_bulk':
        $fkTipo = trim($_POST['fk_tipo'] ?? '');
        if (!$fkTipo) { echo json_encode(['ok' => false, 'msg' => 'Tipo inválido']); exit; }

        $tipo = $pdo->prepare('SELECT IDTipo, Nome, Cor, BloqueiaTotal, HoraInicio, HoraFim FROM TiposDia WHERE IDTipo = :id');
        $tipo->execute([':id' => $fkTipo]);
        $tipo = $tipo->fetch();
        if (!$tipo) { echo json_encode(['ok' => false, 'msg' => 'Tipo não encontrado']); exit; }

        try {
            $chk = $pdo->prepare('SELECT IDDiaEspecial FROM DiasEspeciais WHERE Data = :d');
            $upd = $pdo->prepare('UPDATE DiasEspeciais SET FKTipo = :fk WHERE Data = :d');
            $ins = $pdo->prepare('INSERT INTO DiasEspeciais (IDDiaEspecial, Data, FKTipo) VALUES (:id, :d, :fk)');
            foreach ($datas as $d) {
                $chk->execute([':d' => $d]);
                if ($chk->fetch()) {
                    $upd->execute([':fk' => $fkTipo, ':d' => $d]);
                } else {
                    $ins->execute([':id' => gerarUuid(), ':d' => $d, ':fk' => $fkTipo]);
                }
            }
            echo json_encode(['ok' => true, 'tipo' => [
                'id'           => $tipo['IDTipo'],
                'nome'         => $tipo['Nome'],
                'cor'          => $tipo['Cor'],
                'bloqueiaTotal'=> (bool)$tipo['BloqueiaTotal'],
                'horaInicio'   => $tipo['HoraInicio'] ? substr($tipo['HoraInicio'], 0, 5) : null,
                'horaFim'      => $tipo['HoraFim']    ? substr($tipo['HoraFim'],    0, 5) : null,
            ], 'total' => count($datas)]);
        } catch (PDOException $e) {
            error_log('[BulkTipo] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'Erro ao salvar']);
        }
        break;

    case 'rem_tipo_bulk':
        try {
            $del = $pdo->prepare('DELETE FROM DiasEspeciais WHERE Data = :d');
            foreach ($datas as $d) $del->execute([':d' => $d]);
            echo json_encode(['ok' => true, 'total' => count($datas)]);
        } catch (PDOException $e) {
            error_log('[BulkRemTipo] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'Erro ao remover']);
        }
        break;

    case 'bloquear_bulk':
        $motivo = trim($_POST['motivo'] ?? '') ?: null;
        try {
            $ins = $pdo->prepare(
                'INSERT INTO BloqueiosAgenda (IDBloqueio, DataInicio, DataFim, Motivo)
                 VALUES (:id, :ini, :fim, :mot)'
            );
            foreach ($datas as $d) {
                $ins->execute([
                    ':id'  => gerarUuid(),
                    ':ini' => $d . ' 00:00:00',
                    ':fim' => $d . ' 23:59:59',
                    ':mot' => $motivo,
                ]);
            }
            echo json_encode(['ok' => true, 'total' => count($datas)]);
        } catch (PDOException $e) {
            error_log('[BulkBloqueio] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'Erro ao bloquear']);
        }
        break;

    default:
        echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida']);
}
