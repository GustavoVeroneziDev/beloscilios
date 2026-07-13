<?php
/**
 * Inicia o fluxo de reagendamento para um agendamento existente.
 * Valida que pertence ao cliente, armazena o ID na sessão e redireciona
 * para horarios.php com o mesmo serviço pré-selecionado.
 */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('cliente');

$uid = $_SESSION['usuario_id'];
$id  = trim($_GET['id'] ?? '');

if (!$id) {
    redirecionarComMensagem(BASE . '/usuario/historico.php', 'Agendamento não encontrado.', 'danger');
}

try {
    $stmt = $pdo->prepare(
        'SELECT a.IDAgendamento, a.FKServico, a.FKSubServico, a.DataHoraAgendamento, a.StatusAgendamento,
                s.Nome AS NomeServico, s.DuracaoMinutos AS DuracaoS, s.Preco AS PrecoS,
                ss.Nome AS NomeSubServico, ss.DuracaoMinutos AS DuracaoSS, ss.Preco AS PrecoSS
         FROM Agendamentos a
         JOIN Servicos s ON s.IDServico = a.FKServico
         LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
         WHERE a.IDAgendamento = :id AND a.FKCliente = :uid
         LIMIT 1'
    );
    $stmt->execute([':id' => $id, ':uid' => $uid]);
    $ag = $stmt->fetch();
} catch (PDOException $e) {
    error_log('[Reagendar] ' . $e->getMessage());
    redirecionarComMensagem(BASE . '/usuario/historico.php', 'Erro ao buscar agendamento.', 'danger');
}

if (!$ag) {
    redirecionarComMensagem(BASE . '/usuario/historico.php', 'Agendamento não encontrado.', 'danger');
}

if (!in_array($ag['StatusAgendamento'], ['pendente', 'confirmado'])) {
    redirecionarComMensagem(BASE . '/usuario/historico.php', 'Este agendamento não pode ser reagendado.', 'warning');
}

if (strtotime($ag['DataHoraAgendamento']) <= time()) {
    redirecionarComMensagem(BASE . '/usuario/historico.php', 'Não é possível reagendar agendamentos passados.', 'warning');
}

// Guarda o ID na sessão; confirmar.php vai cancelar este ao criar o novo
$_SESSION['reagendar_id'] = $ag['IDAgendamento'];

$nome    = $ag['FKSubServico'] ? $ag['NomeSubServico']  : $ag['NomeServico'];
$duracao = $ag['FKSubServico'] ? (int)$ag['DuracaoSS']  : (int)$ag['DuracaoS'];
$preco   = $ag['FKSubServico'] ? (float)$ag['PrecoSS']  : (float)$ag['PrecoS'];

$qs = http_build_query(array_filter([
    'servico_id' => $ag['FKServico'],
    'sub_id'     => $ag['FKSubServico'] ?: '',
    'nome'       => $nome,
    'preco'      => $preco,
    'duracao'    => $duracao,
], fn($v) => $v !== '' && $v !== null));

header('Location: ' . BASE . '/agendamento/horarios.php?' . $qs);
exit;
