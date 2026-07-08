<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('cliente');

$servicoId = trim($_GET['servico_id'] ?? '');
$subId     = trim($_GET['sub_id']     ?? '');
$nome      = trim($_GET['nome']       ?? '');
$preco     = (float)($_GET['preco']   ?? 0);
$duracao   = (int)($_GET['duracao']   ?? 60);
$data      = trim($_GET['data']       ?? '');
$hora      = trim($_GET['hora']       ?? '');
$token     = trim($_GET['token']      ?? '');

if (!$servicoId || !$data || !$hora) {
    redirecionarComMensagem(BASE . '/agendamento/index.php', 'Dados incompletos. Tente novamente.', 'warning');
}

$dataHora = "{$data} {$hora}:00";

// Salvar agendamento
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/agendamento/index.php', 'Token inválido.', 'danger');
    }

    $uid = $_SESSION['usuario_id'];
    try {
        $inicio = new DateTimeImmutable($dataHora);
        $fim    = $inicio->modify("+{$duracao} minutes");

        // Verifica se a sessão ainda tem reserva ativa para este slot
        $temReserva = $pdo->prepare(
            'SELECT COUNT(*) FROM ReservasTemporarias
             WHERE TokenSessao = :s AND DataHoraSlot = :ini AND ExpiraEm > NOW()'
        );
        $temReserva->execute([':s' => session_id(), ':ini' => $inicio->format('Y-m-d H:i:s')]);
        if ((int)$temReserva->fetchColumn() === 0) {
            redirecionarComMensagem(
                BASE . '/agendamento/horarios.php?' . http_build_query([
                    'servico_id' => $servicoId, 'sub_id' => $subId,
                    'nome' => $nome, 'preco' => $preco, 'duracao' => $duracao, 'data' => $data,
                ]),
                'A reserva do horário expirou. Selecione novamente.',
                'warning'
            );
        }

        // Verificar duplo-booking (concorrência)

        $check = $pdo->prepare(
            'SELECT COUNT(*) FROM Agendamentos
             WHERE StatusAgendamento NOT IN (\'cancelado\')
               AND DataHoraAgendamento < :fim
               AND DataHoraFim > :ini'
        );
        $check->execute([
            ':ini' => $inicio->format('Y-m-d H:i:s'),
            ':fim' => $fim->format('Y-m-d H:i:s'),
        ]);
        if ((int) $check->fetchColumn() > 0) {
            redirecionarComMensagem(
                BASE . '/agendamento/horarios.php?' . http_build_query([
                    'servico_id' => $servicoId,
                    'sub_id'     => $subId,
                    'nome'       => $nome,
                    'preco'      => $preco,
                    'duracao'    => $duracao,
                    'data'       => $data,
                ]),
                'Este horário acabou de ser ocupado. Escolha outro.',
                'warning'
            );
        }

        $id = gerarUuid();
        $stmt = $pdo->prepare(
            'INSERT INTO Agendamentos
                (IDAgendamento, FKCliente, FKServico, FKSubServico,
                 DataHoraAgendamento, DataHoraFim, StatusAgendamento, ValorCobrado)
             VALUES (:id,:fkc,:fks,:fkss,:ini,:fim,\'pendente\',:preco)'
        );
        $stmt->execute([
            ':id'   => $id,
            ':fkc'  => $uid,
            ':fks'  => $servicoId,
            ':fkss' => $subId ?: null,
            ':ini'  => $inicio->format('Y-m-d H:i:s'),
            ':fim'  => $fim->format('Y-m-d H:i:s'),
            ':preco'=> $preco,
        ]);

        // Remove reserva temporária desta sessão
        $pdo->prepare('DELETE FROM ReservasTemporarias WHERE TokenSessao = :s')
            ->execute([':s' => session_id()]);

        // Notificações: WhatsApp + E-mail
        $usuarioStmt = $pdo->prepare(
            'SELECT Nome, Email, Telefone FROM Usuarios WHERE IDUsuario = :id LIMIT 1'
        );
        $usuarioStmt->execute([':id' => $uid]);
        $usuarioDados = $usuarioStmt->fetch();

        if ($usuarioDados) {
            $nomeU  = $usuarioDados['Nome'];
            $emailU = $usuarioDados['Email'];
            $telU   = $usuarioDados['Telefone'];
            $dataFmt = date('d/m/Y (l)', strtotime($data));
            $valorFmt = formatarMoeda($preco);

            // WhatsApp
            if ($telU) {
                $msgTpl = getConfig($pdo, 'msg_confirmacao', '');
                if ($msgTpl) {
                    $msg = str_replace(
                        ['{nome}', '{data}', '{hora}', '{servico}'],
                        [$nomeU, date('d/m/Y', strtotime($data)), $hora, $nome],
                        $msgTpl
                    );
                    $okWa = enviarWhatsApp($telU, $msg);
                    registrarLogWhatsApp($pdo, $telU, $msg, 'confirmacao',
                        $okWa ? 'enviado' : 'erro', $id);
                    if ($okWa) {
                        $pdo->prepare(
                            'UPDATE Agendamentos SET NotificacaoConfirmacaoEnviada=1 WHERE IDAgendamento=:id'
                        )->execute([':id' => $id]);
                    }
                }
            }

            // E-mail
            if ($emailU) {
                enviarEmailConfirmacaoAgendamento(
                    $emailU, $nomeU, $nome, $dataFmt, $hora, $valorFmt
                );
            }
        }

        redirecionarComMensagem(
            BASE . '/usuario/perfil.php',
            "Agendamento confirmado! Te esperamos em {$data} às {$hora}.",
            'success'
        );
    } catch (PDOException $e) {
        error_log('[Confirmar] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/agendamento/index.php', 'Erro ao agendar. Tente novamente.', 'danger');
    }
}

$paginaTitulo = 'Confirmar agendamento';
$areaAtual    = 'cliente';
require_once __DIR__ . '/../geral/header.php';
?>

<!-- Progresso -->
<div class="d-flex align-items-center gap-2 mb-5">
    <a href="<?= BASE ?>/agendamento/index.php"
       class="badge rounded-pill px-3 py-2 text-decoration-none"
       style="background:var(--card-border-color);color:var(--text-secondary);font-size:.9rem;">
        1. Serviço
    </a>
    <div class="flex-grow-1 border-top" style="border-color:var(--card-border-color)!important;"></div>
    <a href="javascript:history.back()"
       class="badge rounded-pill px-3 py-2 text-decoration-none"
       style="background:var(--card-border-color);color:var(--text-secondary);font-size:.9rem;">
        2. Horário
    </a>
    <div class="flex-grow-1 border-top" style="border-color:var(--card-border-color)!important;"></div>
    <span class="badge rounded-pill px-3 py-2" style="background:var(--accent);font-size:.9rem;">
        3. Confirmar
    </span>
</div>

<div class="row justify-content-center">
    <div class="col-md-8 col-lg-6">
        <div class="card p-4">
            <div class="text-center mb-4">
                <i class="bi bi-calendar-check text-accent" style="font-size:2.5rem;"></i>
                <h5 class="fw-bold mt-2">Confirme seu agendamento</h5>
                <p class="text-secondary">Revise os detalhes abaixo antes de confirmar.</p>
            </div>

            <!-- Resumo -->
            <div class="card mb-4 p-3" style="background:var(--accent-light);border-color:var(--accent);">
                <dl class="row mb-0">
                    <dt class="col-5 small text-secondary">Serviço</dt>
                    <dd class="col-7 fw-medium mb-2"><?= h($nome) ?></dd>

                    <dt class="col-5 small text-secondary">Data</dt>
                    <dd class="col-7 fw-medium mb-2"><?= date('d/m/Y (l)', strtotime($data)) ?></dd>

                    <dt class="col-5 small text-secondary">Horário</dt>
                    <dd class="col-7 fw-medium mb-2"><?= h($hora) ?></dd>

                    <dt class="col-5 small text-secondary">Duração</dt>
                    <dd class="col-7 mb-2"><?= $duracao ?> minutos</dd>

                    <dt class="col-5 small text-secondary">Valor</dt>
                    <dd class="col-7 fw-bold text-accent fs-5 mb-0"><?= formatarMoeda($preco) ?></dd>
                </dl>
            </div>

            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="token"      value="<?= h($token) ?>">

                <div class="mb-3">
                    <label class="form-label">Observações <span class="text-secondary">(opcional)</span></label>
                    <textarea name="obs" class="form-control" rows="2"
                              placeholder="Alguma alergia, preferência ou informação importante..."></textarea>
                </div>

                <div class="d-grid gap-2">
                    <button type="submit" class="btn btn-accent btn-lg">
                        <i class="bi bi-calendar-check me-2"></i> Confirmar agendamento
                    </button>
                    <a href="javascript:history.back()" class="btn btn-outline-secondary">
                        <i class="bi bi-arrow-left me-1"></i> Voltar e alterar horário
                    </a>
                </div>
            </form>
        </div>

        <p class="text-center text-secondary small mt-3">
            <i class="bi bi-whatsapp me-1"></i>
            Você receberá uma confirmação no WhatsApp após o agendamento.
        </p>
    </div>
</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
