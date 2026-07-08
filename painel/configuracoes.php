<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

// Salvar configurações
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'Token inválido.', 'danger');
    }
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'config') {
        $campos = [
            'nome_estudio','telefone_estudio','endereco_estudio',
            'intervalo_minutos','antecedencia_minima_h','dias_agenda_futura',
            'msg_confirmacao','msg_lembrete','msg_followup',
        ];
        try {
            foreach ($campos as $c) {
                if (isset($_POST[$c])) {
                    setConfig($pdo, $c, trim($_POST[$c]));
                }
            }
            redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'Configurações salvas!', 'success');
        } catch (PDOException $e) {
            error_log('[Config] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'Erro ao salvar.', 'danger');
        }
    }

    if ($acao === 'horarios') {
        try {
            $pdo->exec('DELETE FROM HorariosAtendimento');
            for ($d = 0; $d <= 6; $d++) {
                $ativo = isset($_POST["dia_{$d}_ativo"]) ? 1 : 0;
                $ini   = $_POST["dia_{$d}_ini"] ?? '09:00';
                $fim   = $_POST["dia_{$d}_fim"] ?? '18:00';
                if ($ativo) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO HorariosAtendimento (IDHorario,DiaSemana,HoraInicio,HoraFim,Ativo)
                         VALUES (:id,:d,:ini,:fim,1)'
                    );
                    $stmt->execute([':id'=>gerarUuid(),':d'=>$d,':ini'=>$ini,':fim'=>$fim]);
                }
            }
            redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'Horários atualizados!', 'success');
        } catch (PDOException $e) {
            error_log('[Horarios] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'Erro ao salvar horários.', 'danger');
        }
    }

    if ($acao === 'bloqueio') {
        $dataIni = trim($_POST['bloq_ini'] ?? '');
        $dataFim = trim($_POST['bloq_fim'] ?? '');
        $motivo  = trim($_POST['bloq_motivo'] ?? '');
        if ($dataIni && $dataFim) {
            try {
                $pdo->prepare(
                    'INSERT INTO BloqueiosAgenda (IDBloqueio,DataInicio,DataFim,Motivo)
                     VALUES (:id,:ini,:fim,:mot)'
                )->execute([':id'=>gerarUuid(),':ini'=>$dataIni,':fim'=>$dataFim,':mot'=>$motivo?:null]);
                redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'Bloqueio adicionado!', 'success');
            } catch (PDOException $e) {
                error_log('[Bloqueio] ' . $e->getMessage());
            }
        }
    }

    if ($acao === 'rem_bloqueio') {
        $bid = $_POST['bid'] ?? '';
        if ($bid) {
            $pdo->prepare('DELETE FROM BloqueiosAgenda WHERE IDBloqueio=:id')->execute([':id'=>$bid]);
            redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'Bloqueio removido.', 'success');
        }
    }
}

// Carregar dados
try {
    $cfgKeys = ['nome_estudio','telefone_estudio','endereco_estudio',
                'intervalo_minutos','antecedencia_minima_h','dias_agenda_futura',
                'msg_confirmacao','msg_lembrete','msg_followup'];
    $cfg = [];
    foreach ($cfgKeys as $k) {
        $cfg[$k] = getConfig($pdo, $k);
    }

    $horarios = [];
    $rows = $pdo->query('SELECT * FROM HorariosAtendimento WHERE Ativo=1')->fetchAll();
    foreach ($rows as $r) {
        $horarios[(int)$r['DiaSemana']] = $r;
    }

    $bloqueios = $pdo->query(
        'SELECT * FROM BloqueiosAgenda WHERE DataFim >= CURDATE() ORDER BY DataInicio ASC'
    )->fetchAll();
} catch (PDOException $e) {
    error_log('[Config] ' . $e->getMessage());
    $cfg = $horarios = $bloqueios = [];
}

$diasNomes = ['Domingo','Segunda','Terça','Quarta','Quinta','Sexta','Sábado'];

$paginaTitulo = 'Configurações';
$areaAtual    = 'painel';
require_once __DIR__ . '/../geral/header.php';
?>

<h4 class="fw-bold mb-4">Configurações</h4>

<!-- Nav tabs -->
<ul class="nav nav-tabs mb-4" id="cfgTabs">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabGeral">
            <i class="bi bi-gear me-1"></i> Geral
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabHorarios">
            <i class="bi bi-clock me-1"></i> Horários
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabMensagens">
            <i class="bi bi-whatsapp me-1"></i> WhatsApp
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabBloqueios">
            <i class="bi bi-calendar-x me-1"></i> Bloqueios
        </button>
    </li>
</ul>

<div class="tab-content">
    <!-- Geral -->
    <div class="tab-pane fade show active" id="tabGeral">
        <div class="card">
            <form method="POST" class="card-body p-4">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="config">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label">Nome do estúdio</label>
                        <input type="text" name="nome_estudio" class="form-control"
                               value="<?= h($cfg['nome_estudio'] ?? '') ?>">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Telefone de contato</label>
                        <input type="tel" name="telefone_estudio" class="form-control"
                               value="<?= h($cfg['telefone_estudio'] ?? '') ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Endereço</label>
                        <input type="text" name="endereco_estudio" class="form-control"
                               value="<?= h($cfg['endereco_estudio'] ?? '') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Intervalo entre atendimentos (min)</label>
                        <input type="number" name="intervalo_minutos" class="form-control"
                               min="0" step="5" value="<?= h($cfg['intervalo_minutos'] ?? '15') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Antecedência mínima (horas)</label>
                        <input type="number" name="antecedencia_minima_h" class="form-control"
                               min="0" value="<?= h($cfg['antecedencia_minima_h'] ?? '2') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Dias de agenda disponível</label>
                        <input type="number" name="dias_agenda_futura" class="form-control"
                               min="7" max="365" value="<?= h($cfg['dias_agenda_futura'] ?? '60') ?>">
                    </div>
                </div>
                <div class="mt-4">
                    <button class="btn btn-accent"><i class="bi bi-save me-1"></i> Salvar</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Horários -->
    <div class="tab-pane fade" id="tabHorarios">
        <div class="card">
            <form method="POST" class="card-body p-4">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="horarios">
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th>Dia</th>
                                <th>Ativo</th>
                                <th>Abertura</th>
                                <th>Fechamento</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($d = 0; $d <= 6; $d++): ?>
                            <?php $h_row = $horarios[$d] ?? null; ?>
                            <tr>
                                <td class="fw-medium"><?= $diasNomes[$d] ?></td>
                                <td>
                                    <div class="form-check form-switch">
                                        <input class="form-check-input" type="checkbox"
                                               name="dia_<?= $d ?>_ativo" id="dia<?= $d ?>"
                                               <?= $h_row ? 'checked' : '' ?>>
                                    </div>
                                </td>
                                <td>
                                    <input type="time" name="dia_<?= $d ?>_ini"
                                           class="form-control form-control-sm"
                                           value="<?= h($h_row['HoraInicio'] ?? '09:00') ?>"
                                           style="width:120px;">
                                </td>
                                <td>
                                    <input type="time" name="dia_<?= $d ?>_fim"
                                           class="form-control form-control-sm"
                                           value="<?= h($h_row['HoraFim'] ?? '18:00') ?>"
                                           style="width:120px;">
                                </td>
                            </tr>
                            <?php endfor ?>
                        </tbody>
                    </table>
                </div>
                <button class="btn btn-accent"><i class="bi bi-save me-1"></i> Salvar horários</button>
            </form>
        </div>
    </div>

    <!-- Mensagens WA -->
    <div class="tab-pane fade" id="tabMensagens">
        <div class="card">
            <form method="POST" class="card-body p-4">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="config">
                <p class="text-secondary small mb-3">
                    <i class="bi bi-info-circle me-1"></i>
                    Variáveis disponíveis: <code>{nome}</code>, <code>{data}</code>,
                    <code>{hora}</code>, <code>{servico}</code>
                </p>
                <?php
                $msgs = [
                    ['msg_confirmacao', 'Confirmação de agendamento',         'Enviada assim que o agendamento é criado.'],
                    ['msg_lembrete',    'Lembrete (24h antes)',                'Enviada pelo cron 24h antes do horário.'],
                    ['msg_followup',    'Follow-up pós-procedimento',          'Enviada depois que o agendamento é concluído.'],
                ];
                foreach ($msgs as [$key, $label, $info]):
                ?>
                <div class="mb-4">
                    <label class="form-label fw-medium"><?= $label ?></label>
                    <div class="small text-secondary mb-1"><?= $info ?></div>
                    <textarea name="<?= $key ?>" class="form-control" rows="4"><?= h($cfg[$key] ?? '') ?></textarea>
                </div>
                <?php endforeach ?>
                <button class="btn btn-accent"><i class="bi bi-save me-1"></i> Salvar mensagens</button>
            </form>
        </div>
    </div>

    <!-- Bloqueios -->
    <div class="tab-pane fade" id="tabBloqueios">
        <div class="card mb-3">
            <form method="POST" class="card-body p-4">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="bloqueio">
                <h6 class="fw-semibold mb-3">Adicionar bloqueio</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label">Início *</label>
                        <input type="datetime-local" name="bloq_ini" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Fim *</label>
                        <input type="datetime-local" name="bloq_fim" class="form-control" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Motivo</label>
                        <input type="text" name="bloq_motivo" class="form-control" placeholder="Ex: Folga, feriado...">
                    </div>
                </div>
                <div class="mt-3">
                    <button class="btn btn-accent btn-sm">
                        <i class="bi bi-plus me-1"></i> Adicionar bloqueio
                    </button>
                </div>
            </form>
        </div>

        <?php if (!empty($bloqueios)): ?>
        <div class="card">
            <div class="card-header px-4 py-3">Bloqueios ativos / futuros</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($bloqueios as $b): ?>
                <li class="list-group-item px-4 py-3 d-flex align-items-center justify-content-between">
                    <div>
                        <div class="fw-medium"><?= h($b['Motivo'] ?? 'Sem motivo') ?></div>
                        <div class="small text-secondary">
                            <?= formatarDataHora($b['DataInicio']) ?> → <?= formatarDataHora($b['DataFim']) ?>
                        </div>
                    </div>
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                        <input type="hidden" name="acao" value="rem_bloqueio">
                        <input type="hidden" name="bid" value="<?= h($b['IDBloqueio']) ?>">
                        <button class="btn btn-sm btn-outline-danger" type="button"
                                data-confirm="Remover este bloqueio de horário?"
                                data-confirm-label="Remover">
                            <i class="bi bi-trash"></i>
                        </button>
                    </form>
                </li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php endif ?>
    </div>
</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
