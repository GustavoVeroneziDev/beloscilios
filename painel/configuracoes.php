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
        $intervaloMin = (int)($_POST['intervalo_minutos'] ?? 0);
        if ($intervaloMin < 1) {
            redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'Intervalo entre atendimentos deve ser pelo menos 1 minuto.', 'warning');
        }
        $campos = [
            'nome_estudio',
            'telefone_estudio',
            'endereco_estudio',
            'intervalo_minutos',
            'antecedencia_minima_h',
            'dias_agenda_futura',
            'msg_confirmacao',
            'msg_lembrete',
            'msg_followup',
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
                $temAlmoco  = isset($_POST["dia_{$d}_almoco_ativo"]);
                $almocoIni  = $temAlmoco ? (trim($_POST["dia_{$d}_almoco_ini"] ?? '') ?: null) : null;
                $almocoFim  = $temAlmoco ? (trim($_POST["dia_{$d}_almoco_fim"] ?? '') ?: null) : null;
                if ($ativo) {
                    $stmt = $pdo->prepare(
                        'INSERT INTO HorariosAtendimento
                            (IDHorario, DiaSemana, HoraInicio, HoraFim, AlmocoInicio, AlmocoFim, Ativo)
                         VALUES (:id, :d, :ini, :fim, :alni, :alfm, 1)'
                    );
                    $stmt->execute([
                        ':id'   => gerarUuid(),
                        ':d'    => $d,
                        ':ini'  => $ini,
                        ':fim'  => $fim,
                        ':alni' => $almocoIni,
                        ':alfm' => $almocoFim,
                    ]);
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
            if ($dataFim <= $dataIni) {
                redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'A data de fim deve ser posterior à data de início.', 'warning');
            }
            try {
                $pdo->prepare(
                    'INSERT INTO BloqueiosAgenda (IDBloqueio,DataInicio,DataFim,Motivo)
                     VALUES (:id,:ini,:fim,:mot)'
                )->execute([':id' => gerarUuid(), ':ini' => $dataIni, ':fim' => $dataFim, ':mot' => $motivo ?: null]);
                redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'Bloqueio adicionado!', 'success');
            } catch (PDOException $e) {
                error_log('[Bloqueio] ' . $e->getMessage());
                redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'Erro ao adicionar bloqueio.', 'danger');
            }
        }
    }

    if ($acao === 'rem_bloqueio') {
        $bid = $_POST['bid'] ?? '';
        if ($bid) {
            $pdo->prepare('DELETE FROM BloqueiosAgenda WHERE IDBloqueio=:id')->execute([':id' => $bid]);
            redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'Bloqueio removido.', 'success');
        }
    }

    if ($acao === 'edit_bloqueio') {
        $bid     = trim($_POST['bid']         ?? '');
        $dataIni = trim($_POST['bloq_ini']    ?? '');
        $dataFim = trim($_POST['bloq_fim']    ?? '');
        $motivo  = trim($_POST['bloq_motivo'] ?? '');
        if ($bid && $dataIni && $dataFim) {
            if ($dataFim <= $dataIni) {
                redirecionarComMensagem(BASE . '/painel/configuracoes.php#tabBloqueios', 'A data de fim deve ser posterior à data de início.', 'warning');
            }
            try {
                $pdo->prepare(
                    'UPDATE BloqueiosAgenda SET DataInicio=:ini, DataFim=:fim, Motivo=:mot WHERE IDBloqueio=:id'
                )->execute([':ini' => $dataIni, ':fim' => $dataFim, ':mot' => $motivo ?: null, ':id' => $bid]);
                redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'Bloqueio atualizado!', 'success');
            } catch (PDOException $e) {
                error_log('[Bloqueio] ' . $e->getMessage());
                redirecionarComMensagem(BASE . '/painel/configuracoes.php', 'Erro ao atualizar bloqueio.', 'danger');
            }
        }
    }
}

// Carregar dados
try {
    $cfgKeys = [
        'nome_estudio',
        'telefone_estudio',
        'endereco_estudio',
        'intervalo_minutos',
        'antecedencia_minima_h',
        'dias_agenda_futura',
        'msg_confirmacao',
        'msg_lembrete',
        'msg_followup'
    ];
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

$diasNomes = ['Domingo', 'Segunda', 'Terça', 'Quarta', 'Quinta', 'Sexta', 'Sábado'];

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
            <form method="POST" id="formHorarios" class="card-body p-4">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="horarios">
                <div class="table-responsive">
                    <table class="table align-middle" style="min-width:680px;">
                        <thead>
                            <tr>
                                <th>Dia</th>
                                <th>Ativo</th>
                                <th>Almoço</th>
                                <th>Abertura</th>
                                <th>Início almoço</th>
                                <th>Fim almoço</th>
                                <th>Término</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php for ($d = 0; $d <= 6; $d++): ?>
                                <?php
                                $h_row  = $horarios[$d] ?? null;
                                $temAlm = $h_row && !empty($h_row['AlmocoInicio']);
                                $almIni = substr($h_row['AlmocoInicio'] ?? '12:00', 0, 5);
                                $almFim = substr($h_row['AlmocoFim']    ?? '13:00', 0, 5);
                                ?>
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
                                        <div class="form-check form-switch">
                                            <input class="form-check-input"
                                                type="checkbox"
                                                name="dia_<?= $d ?>_almoco_ativo"
                                                id="almoco<?= $d ?>"
                                                <?= $temAlm ? 'checked' : '' ?>
                                                onchange="toggleAlmoco(<?= $d ?>)">
                                        </div>
                                    </td>
                                    <td>
                                        <input type="time" name="dia_<?= $d ?>_ini"
                                            id="ini_<?= $d ?>"
                                            class="form-control form-control-sm"
                                            value="<?= h($h_row['HoraInicio'] ?? '09:00') ?>"
                                            style="width:110px;">
                                    </td>
                                    <td>
                                        <input type="time" name="dia_<?= $d ?>_almoco_ini"
                                            id="almoco_ini_<?= $d ?>"
                                            class="form-control form-control-sm"
                                            value="<?= h($almIni) ?>"
                                            style="width:110px;"
                                            <?= $temAlm ? '' : 'disabled' ?>>
                                    </td>
                                    <td>
                                        <input type="time" name="dia_<?= $d ?>_almoco_fim"
                                            id="almoco_fim_<?= $d ?>"
                                            class="form-control form-control-sm"
                                            value="<?= h($almFim) ?>"
                                            style="width:110px;"
                                            <?= $temAlm ? '' : 'disabled' ?>>
                                    </td>
                                    <td>
                                        <input type="time" name="dia_<?= $d ?>_fim"
                                            id="fim_<?= $d ?>"
                                            class="form-control form-control-sm"
                                            value="<?= h($h_row['HoraFim'] ?? '18:00') ?>"
                                            style="width:110px;">
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

    <!-- Modal de erro de horário -->
    <div class="modal fade" id="modalErroHorario" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered modal-sm">
            <div class="modal-content border-0 shadow-lg" style="border-radius:14px;">
                <div class="modal-body text-center px-4 pt-4 pb-2">
                    <i class="bi bi-exclamation-triangle-fill d-block mb-3"
                        style="font-size:2.4rem;color:#f59e0b;"></i>
                    <p class="fw-semibold mb-0" id="modalErroMsg" style="color:var(--text-main);"></p>
                </div>
                <div class="modal-footer justify-content-center border-0 pt-2 pb-4">
                    <button type="button" class="btn btn-accent px-5"
                        data-bs-dismiss="modal">Entendi</button>
                </div>
            </div>
        </div>
    </div>

    <script>
        const diasNomes = <?= json_encode($diasNomes) ?>;

        function toggleAlmoco(d) {
            const on = document.getElementById('almoco' + d).checked;
            document.getElementById('almoco_ini_' + d).disabled = !on;
            document.getElementById('almoco_fim_' + d).disabled = !on;
        }

        function erroHorario(msg) {
            document.getElementById('modalErroMsg').textContent = msg;
            new bootstrap.Modal(document.getElementById('modalErroHorario')).show();
        }

        document.getElementById('formHorarios').addEventListener('submit', function(e) {
            for (let d = 0; d <= 6; d++) {
                const ativo = document.getElementById('dia' + d).checked;
                if (!ativo) continue;

                const ini = document.getElementById('ini_' + d).value;
                const fim = document.getElementById('fim_' + d).value;
                const almOn = document.getElementById('almoco' + d).checked;
                const almIni = document.getElementById('almoco_ini_' + d).value;
                const almFim = document.getElementById('almoco_fim_' + d).value;
                const dia = diasNomes[d];

                if (ini >= fim) {
                    e.preventDefault();
                    erroHorario(dia + ': o horário de abertura (' + ini + ') deve ser anterior ao término (' + fim + ').');
                    return;
                }

                if (almOn) {
                    if (!almIni || !almFim) {
                        e.preventDefault();
                        erroHorario(dia + ': preencha os dois horários do intervalo de almoço.');
                        return;
                    }
                    if (almIni >= almFim) {
                        e.preventDefault();
                        erroHorario(dia + ': o início do almoço (' + almIni + ') deve ser anterior ao fim (' + almFim + ').');
                        return;
                    }
                    if (almIni <= ini) {
                        e.preventDefault();
                        erroHorario(dia + ': o início do almoço (' + almIni + ') deve ser após a abertura (' + ini + ').');
                        return;
                    }
                    if (almFim >= fim) {
                        e.preventDefault();
                        erroHorario(dia + ': o fim do almoço (' + almFim + ') deve ser antes do término (' + fim + ').');
                        return;
                    }
                }
            }
        });
    </script>

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

        <!-- Modal editar bloqueio -->
        <div class="modal fade" id="modalEditBloqueio" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-0 shadow-lg" style="border-radius:14px;">
                    <div class="modal-header border-0 pb-0 px-4 pt-4">
                        <h6 class="modal-title fw-semibold"><i class="bi bi-pencil-square me-2 text-accent"></i>Editar bloqueio</h6>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                    <form method="POST" id="formEditBloqueio">
                        <div class="modal-body px-4 pb-0">
                            <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                            <input type="hidden" name="acao" value="edit_bloqueio">
                            <input type="hidden" name="bid" id="editBid">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label">Início *</label>
                                    <input type="datetime-local" name="bloq_ini" id="editIni" class="form-control" required>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label">Fim *</label>
                                    <input type="datetime-local" name="bloq_fim" id="editFim" class="form-control" required>
                                </div>
                                <div class="col-12">
                                    <label class="form-label">Motivo</label>
                                    <input type="text" name="bloq_motivo" id="editMotivo" class="form-control"
                                           placeholder="Ex: Folga, feriado...">
                                </div>
                            </div>
                        </div>
                        <div class="modal-footer border-0 px-4 pb-4 pt-3 gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancelar</button>
                            <button type="submit" class="btn btn-accent btn-sm">
                                <i class="bi bi-save me-1"></i> Salvar alterações
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <script>
        document.getElementById('modalEditBloqueio')?.addEventListener('show.bs.modal', function(e) {
            const btn = e.relatedTarget;
            document.getElementById('editBid').value    = btn.dataset.bid;
            document.getElementById('editIni').value    = btn.dataset.ini;
            document.getElementById('editFim').value    = btn.dataset.fim;
            document.getElementById('editMotivo').value = btn.dataset.motivo;
        });
        </script>

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
                            <div class="d-flex gap-2">
                                <button class="btn btn-sm btn-outline-accent" type="button"
                                    data-bs-toggle="modal" data-bs-target="#modalEditBloqueio"
                                    data-bid="<?= h($b['IDBloqueio']) ?>"
                                    data-ini="<?= h(str_replace(' ', 'T', substr($b['DataInicio'], 0, 16))) ?>"
                                    data-fim="<?= h(str_replace(' ', 'T', substr($b['DataFim'], 0, 16))) ?>"
                                    data-motivo="<?= h($b['Motivo'] ?? '') ?>">
                                    <i class="bi bi-pencil"></i>
                                </button>
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
                            </div>
                        </li>
                    <?php endforeach ?>
                </ul>
            </div>
        <?php endif ?>
    </div>
</div>

<?php require_once __DIR__ . '/../geral/footer.php' ?>