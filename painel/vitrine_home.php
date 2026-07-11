<?php
/**
 * AJAX — gerencia a vitrine da home.
 * Ações: toggle (adicionar/remover), reordenar, set_foco
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

switch ($acao) {

    // ── Adiciona ou remove da home ────────────────────────────────
    case 'toggle':
        $id = trim($_POST['id'] ?? '');
        if (!$id) { echo json_encode(['ok' => false, 'msg' => 'ID inválido']); exit; }

        $stmt = $pdo->prepare('SELECT ExibirNaHome FROM Imagens WHERE IDImagem = :id');
        $stmt->execute([':id' => $id]);
        $img = $stmt->fetch();
        if (!$img) { echo json_encode(['ok' => false, 'msg' => 'Imagem não encontrada']); exit; }

        if ($img['ExibirNaHome']) {
            // Remove: zera ordem
            $pdo->prepare('UPDATE Imagens SET ExibirNaHome = 0, OrdemHome = 99 WHERE IDImagem = :id')
                ->execute([':id' => $id]);
            echo json_encode(['ok' => true, 'exibindo' => false]);
        } else {
            // Adiciona no final
            $proxOrdem = (int) $pdo->query(
                'SELECT COALESCE(MAX(OrdemHome), 0) FROM Imagens WHERE ExibirNaHome = 1'
            )->fetchColumn() + 1;
            $pdo->prepare('UPDATE Imagens SET ExibirNaHome = 1, OrdemHome = :ord WHERE IDImagem = :id')
                ->execute([':ord' => $proxOrdem, ':id' => $id]);
            echo json_encode(['ok' => true, 'exibindo' => true]);
        }
        break;

    // ── Salva nova ordem após drag-and-drop ──────────────────────
    case 'reordenar':
        $ids = json_decode($_POST['ids'] ?? '[]', true);
        if (!is_array($ids)) { echo json_encode(['ok' => false, 'msg' => 'Dados inválidos']); exit; }

        $stmt = $pdo->prepare('UPDATE Imagens SET OrdemHome = :ord WHERE IDImagem = :id AND ExibirNaHome = 1');
        foreach ($ids as $i => $id) {
            $stmt->execute([':ord' => $i + 1, ':id' => (string) $id]);
        }
        echo json_encode(['ok' => true]);
        break;

    // ── Atualiza ponto de foco ─────────────────────────────────────
    case 'set_foco':
        $id   = trim($_POST['id']   ?? '');
        $foco = trim($_POST['foco'] ?? 'center center');
        $validos = [
            'left top', 'center top', 'right top',
            'left center', 'center center', 'right center',
            'left bottom', 'center bottom', 'right bottom',
            'center 25%', 'center 35%', 'center 55%', 'center 70%', 'center 80%',
        ];
        if (!in_array($foco, $validos, true)) $foco = 'center center';

        $pdo->prepare('UPDATE Imagens SET FocoHome = :foco WHERE IDImagem = :id AND ExibirNaHome = 1')
            ->execute([':foco' => $foco, ':id' => $id]);

        echo json_encode(['ok' => true]);
        break;

    default:
        echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida']);
}
