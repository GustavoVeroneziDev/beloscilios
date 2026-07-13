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
            // Se o dia tem grupo, trata como "remove somente este"
            $pdo->prepare('DELETE FROM DiasEspeciais WHERE Data = :d')->execute([':d' => $data]);
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            error_log('[TipoDia] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'Erro ao remover']);
        }
        break;

    case 'set_serie':
        $fkTipo    = trim($_POST['fk_tipo']    ?? '');
        $intervalo = max(1, min(365, (int)($_POST['intervalo'] ?? 7)));
        $vezes     = max(2, min(260, (int)($_POST['vezes']     ?? 4)));

        if (!$fkTipo) { echo json_encode(['ok' => false, 'msg' => 'Tipo inválido']); exit; }

        $stTipo = $pdo->prepare('SELECT IDTipo, Nome, Cor, BloqueiaTotal, HoraInicio, HoraFim FROM TiposDia WHERE IDTipo = :id');
        $stTipo->execute([':id' => $fkTipo]);
        $tipo = $stTipo->fetch();
        if (!$tipo) { echo json_encode(['ok' => false, 'msg' => 'Tipo não encontrado']); exit; }

        $grupo   = gerarUuid();
        $criados = 0;
        $ini     = new DateTime($data);

        try {
            $pdo->beginTransaction();
            $ins = $pdo->prepare(
                'INSERT INTO DiasEspeciais (IDDiaEspecial, Data, FKTipo, GrupoRecorrencia, OrdemRecorrencia)
                 VALUES (:id, :d, :fk, :grupo, :ordem)
                 ON DUPLICATE KEY UPDATE FKTipo = VALUES(FKTipo), GrupoRecorrencia = VALUES(GrupoRecorrencia), OrdemRecorrencia = VALUES(OrdemRecorrencia)'
            );
            for ($i = 0; $i < $vezes; $i++) {
                $ins->execute([
                    ':id'    => gerarUuid(),
                    ':d'     => $ini->format('Y-m-d'),
                    ':fk'    => $fkTipo,
                    ':grupo' => $grupo,
                    ':ordem' => $i + 1,
                ]);
                $criados++;
                $ini->modify("+{$intervalo} days");
            }
            $pdo->commit();
            echo json_encode([
                'ok'     => true,
                'criados'=> $criados,
                'grupo'  => $grupo,
                'tipo'   => [
                    'id'           => $tipo['IDTipo'],
                    'nome'         => $tipo['Nome'],
                    'cor'          => $tipo['Cor'],
                    'bloqueiaTotal'=> (bool)$tipo['BloqueiaTotal'],
                    'horaInicio'   => $tipo['HoraInicio'] ? substr($tipo['HoraInicio'], 0, 5) : null,
                    'horaFim'      => $tipo['HoraFim']    ? substr($tipo['HoraFim'],    0, 5) : null,
                    'grupo'        => $grupo,
                ],
            ]);
        } catch (PDOException $e) {
            $pdo->rollBack();
            error_log('[TipoDia] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'Erro ao criar série']);
        }
        break;

    case 'remove_serie':
        $modo  = $_POST['modo']  ?? 'este';
        $grupo = trim($_POST['grupo'] ?? '');

        try {
            if ($modo === 'futuros' && $grupo) {
                $pdo->prepare(
                    'DELETE FROM DiasEspeciais WHERE GrupoRecorrencia = :g AND Data >= :d'
                )->execute([':g' => $grupo, ':d' => $data]);
            } else {
                $pdo->prepare('DELETE FROM DiasEspeciais WHERE Data = :d')->execute([':d' => $data]);
            }
            echo json_encode(['ok' => true]);
        } catch (PDOException $e) {
            error_log('[TipoDia] ' . $e->getMessage());
            echo json_encode(['ok' => false, 'msg' => 'Erro ao remover']);
        }
        break;

    default:
        echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida']);
}
