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
        $campos = ['nome_estudio', 'telefone_estudio', 'endereco_estudio',
                   'intervalo_minutos', 'antecedencia_minima_h', 'dias_agenda_futura',
                   'telefone_designer', 'instagram_estudio'];
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

    if ($acao === 'mensagens') {
        $campos = ['msg_confirmacao', 'msg_lembrete', 'msg_cancelamento', 'msg_followup', 'msg_cobranca'];
        try {
            foreach ($campos as $c) {
                if (isset($_POST[$c])) {
                    setConfig($pdo, $c, trim($_POST[$c]));
                }
            }
            redirecionarComMensagem(BASE . '/painel/configuracoes.php?tab=mensagens', 'Mensagens salvas!', 'success');
        } catch (PDOException $e) {
            error_log('[Config] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/configuracoes.php?tab=mensagens', 'Erro ao salvar.', 'danger');
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

    if ($acao === 'add_tipo') {
        $nome = trim($_POST['tipo_nome'] ?? '');
        $cor  = trim($_POST['tipo_cor']  ?? '#6c757d');
        $bloq      = !empty($_POST['tipo_bloqueia']) ? 1 : 0;
        $ini       = $bloq ? null : (trim($_POST['tipo_ini'] ?? '') ?: null);
        $fim       = $bloq ? null : (trim($_POST['tipo_fim'] ?? '') ?: null);
        $temAlm    = !$bloq && !empty($_POST['tipo_almoco_ativo']);
        $almocoIni = $temAlm ? (trim($_POST['tipo_almoco_ini'] ?? '') ?: null) : null;
        $almocoFim = $temAlm ? (trim($_POST['tipo_almoco_fim'] ?? '') ?: null) : null;
        if (!$nome) {
            redirecionarComMensagem(BASE . '/painel/configuracoes.php?tab=tipos', 'Informe o nome do tipo.', 'warning');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) $cor = '#6c757d';
        try {
            $pdo->prepare(
                'INSERT INTO TiposDia (IDTipo, Nome, Cor, BloqueiaTotal, HoraInicio, HoraFim, AlmocoInicio, AlmocoFim)
                 VALUES (:id, :nome, :cor, :bloq, :ini, :fim, :alni, :alfm)'
            )->execute([':id' => gerarUuid(), ':nome' => $nome, ':cor' => $cor,
                        ':bloq' => $bloq, ':ini' => $ini, ':fim' => $fim,
                        ':alni' => $almocoIni, ':alfm' => $almocoFim]);
            redirecionarComMensagem(BASE . '/painel/configuracoes.php?tab=tipos', 'Tipo adicionado!', 'success');
        } catch (PDOException $e) {
            error_log('[TipoDia] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/configuracoes.php?tab=tipos', 'Erro ao adicionar.', 'danger');
        }
    }

    if ($acao === 'edit_tipo') {
        $tid  = trim($_POST['tid']       ?? '');
        $nome = trim($_POST['tipo_nome'] ?? '');
        $cor  = trim($_POST['tipo_cor']  ?? '#6c757d');
        $bloq      = !empty($_POST['tipo_bloqueia']) ? 1 : 0;
        $ini       = $bloq ? null : (trim($_POST['tipo_ini'] ?? '') ?: null);
        $fim       = $bloq ? null : (trim($_POST['tipo_fim'] ?? '') ?: null);
        $temAlm    = !$bloq && !empty($_POST['tipo_almoco_ativo']);
        $almocoIni = $temAlm ? (trim($_POST['tipo_almoco_ini'] ?? '') ?: null) : null;
        $almocoFim = $temAlm ? (trim($_POST['tipo_almoco_fim'] ?? '') ?: null) : null;
        if (!$tid || !$nome) {
            redirecionarComMensagem(BASE . '/painel/configuracoes.php?tab=tipos', 'Dados inválidos.', 'warning');
        }
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $cor)) $cor = '#6c757d';
        try {
            $pdo->prepare(
                'UPDATE TiposDia SET Nome=:nome, Cor=:cor, BloqueiaTotal=:bloq,
                 HoraInicio=:ini, HoraFim=:fim, AlmocoInicio=:alni, AlmocoFim=:alfm
                 WHERE IDTipo=:id'
            )->execute([':nome' => $nome, ':cor' => $cor, ':bloq' => $bloq,
                        ':ini' => $ini, ':fim' => $fim,
                        ':alni' => $almocoIni, ':alfm' => $almocoFim, ':id' => $tid]);
            redirecionarComMensagem(BASE . '/painel/configuracoes.php?tab=tipos', 'Tipo atualizado!', 'success');
        } catch (PDOException $e) {
            error_log('[TipoDia] ' . $e->getMessage());
            redirecionarComMensagem(BASE . '/painel/configuracoes.php?tab=tipos', 'Erro ao editar.', 'danger');
        }
    }

    if ($acao === 'rem_tipo') {
        $tid = trim($_POST['tid'] ?? '');
        if ($tid) {
            try {
                $pdo->prepare('DELETE FROM TiposDia WHERE IDTipo = :id')->execute([':id' => $tid]);
                redirecionarComMensagem(BASE . '/painel/configuracoes.php?tab=tipos', 'Tipo removido.', 'success');
            } catch (PDOException $e) {
                error_log('[TipoDia] ' . $e->getMessage());
                redirecionarComMensagem(BASE . '/painel/configuracoes.php?tab=tipos', 'Erro ao remover.', 'danger');
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
        'msg_followup',
        'msg_cancelamento',
        'msg_cobranca',
        'telefone_designer',
        'instagram_estudio',
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

    $tiposDia = $pdo->query('SELECT * FROM TiposDia ORDER BY Nome ASC')->fetchAll();
} catch (PDOException $e) {
    error_log('[Config] ' . $e->getMessage());
    $cfg = $horarios = $bloqueios = [];
    $tiposDia = [];
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
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabTipos">
            <i class="bi bi-tags me-1"></i> Tipos de Dia
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
                            value="<?= h($cfg['nome_estudio'] ?? '') ?>"
                            maxlength="100">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">Telefone de contato (estúdio)</label>
                        <input type="tel" name="telefone_estudio" class="form-control"
                            value="<?= h(formatarTelefoneExibicao($cfg['telefone_estudio'] ?? '')) ?>"
                            placeholder="(11) 99999-9999"
                            data-mask="tel" maxlength="15">
                    </div>
                    <div class="col-md-6">
                        <label class="form-label">WhatsApp pessoal da designer
                            <span class="text-secondary small fw-normal">(notificações do webhook)</span>
                        </label>
                        <input type="tel" name="telefone_designer" class="form-control"
                            value="<?= h(formatarTelefoneExibicao($cfg['telefone_designer'] ?? '')) ?>"
                            placeholder="(11) 99999-9999"
                            data-mask="tel" maxlength="15">
                        <div class="form-text">Recebe alertas de respostas incertas e cancelamentos via IA.</div>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label">Endereço</label>
                        <input type="text" name="endereco_estudio" class="form-control"
                            value="<?= h($cfg['endereco_estudio'] ?? '') ?>"
                            maxlength="255">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">
                            <i class="bi bi-instagram me-1" style="color:#e1306c"></i>Instagram
                        </label>
                        <input type="url" name="instagram_estudio" class="form-control"
                            value="<?= h($cfg['instagram_estudio'] ?? '') ?>"
                            placeholder="https://www.instagram.com/seuperfil/"
                            maxlength="255">
                        <div class="form-text">URL completa do perfil.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Intervalo entre atendimentos (min)</label>
                        <input type="number" name="intervalo_minutos" class="form-control"
                            min="0" step="5" value="<?= h($cfg['intervalo_minutos'] ?? '15') ?>">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Antecedência mínima (horas)</label>
                        <input type="number" name="antecedencia_minima_h" class="form-control"
                            min="0" max="72" value="<?= h($cfg['antecedencia_minima_h'] ?? '2') ?>">
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
                <input type="hidden" name="acao" value="mensagens">
                <?php
                $varBase  = '<code>{nome}</code>, <code>{data}</code>, <code>{hora}</code>, <code>{servico}</code>';
                $msgs = [
                    [
                        'key'  => 'msg_confirmacao',
                        'label'=> 'Confirmação de agendamento',
                        'info' => 'Enviada assim que o agendamento é criado. Quando a cliente responder, a IA classifica automaticamente.',
                        'vars' => $varBase,
                    ],
                    [
                        'key'  => 'msg_lembrete',
                        'label'=> 'Lembrete (24h antes)',
                        'info' => 'Enviada pelo cron 24h antes do horário.',
                        'vars' => $varBase,
                    ],
                    [
                        'key'  => 'msg_cancelamento',
                        'label'=> 'Cancelamento (via IA)',
                        'info' => 'Enviada à cliente quando a IA detecta cancelamento. O motivo é a mensagem original dela.',
                        'vars' => $varBase . ', <code>{mensagem_cliente}</code> — o que a cliente escreveu',
                    ],
                    [
                        'key'  => 'msg_followup',
                        'label'=> 'Follow-up pós-procedimento',
                        'info' => 'Enviada depois que o agendamento é concluído.',
                        'vars' => $varBase,
                    ],
                    [
                        'key'  => 'msg_cobranca',
                        'label'=> 'Cobrança (pagamento pendente)',
                        'info' => 'Para agendamentos com pagamento pendente.',
                        'vars' => $varBase . ', <code>{valor}</code> — valor cobrado (ex: 150,00)',
                    ],
                ];
                foreach ($msgs as $m):
                ?>
                    <div class="mb-4">
                        <label class="form-label fw-medium"><?= $m['label'] ?></label>
                        <div class="small text-secondary mb-1"><?= $m['info'] ?></div>
                        <div class="small mb-2" style="color:var(--accent,#5a189a);">
                            <i class="bi bi-braces me-1"></i><?= $m['vars'] ?>
                        </div>
                        <textarea name="<?= $m['key'] ?>" class="form-control" rows="5"><?= h($cfg[$m['key']] ?? '') ?></textarea>
                    </div>
                <?php endforeach ?>
                <button class="btn btn-accent"><i class="bi bi-save me-1"></i> Salvar mensagens</button>

                <!-- Card de instruções do webhook -->
                <div class="mt-4 p-3 rounded" style="background:rgba(90,24,154,.05);border:1px solid var(--card-border-color);">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <i class="bi bi-robot text-accent"></i>
                        <span class="fw-semibold small">Webhook de respostas (IA com Gemini)</span>
                    </div>
                    <p class="small text-secondary mb-2">
                        Configure na Evolution API o seguinte webhook para que as respostas das clientes sejam processadas automaticamente:
                    </p>
                    <code class="small d-block p-2 rounded" style="background:rgba(0,0,0,.05);word-break:break-all;">
                        https://beloscilios.com/webhook_whatsapp.php
                    </code>
                    <ul class="small text-secondary mt-2 mb-0 ps-3">
                        <li>Evento: <strong>messages.upsert</strong></li>
                        <li>A IA classifica a resposta como <em>confirmado</em>, <em>cancelado</em> ou <em>incerto</em></li>
                        <li>Respostas incertas são encaminhadas para o WhatsApp pessoal da designer (configure acima)</li>
                        <li>Se a chave Gemini não estiver configurada, usa classificação por palavras-chave</li>
                    </ul>
                </div>
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

    <!-- Tipos de Dia -->
    <div class="tab-pane fade" id="tabTipos">
        <div class="card mb-3">
            <form method="POST" class="card-body p-4" id="formAddTipo">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="add_tipo">
                <h6 class="fw-semibold mb-1">Novo tipo de dia</h6>
                <p class="small text-secondary mb-3">
                    Crie perfis reutilizáveis (ex: "Folga Usina", "Academia") e atribua a datas específicas na Agenda.
                    Dias com um tipo terão o horário ajustado automaticamente.
                </p>
                <div class="row g-3 align-items-end">
                    <div class="col-md-4">
                        <label class="form-label">Nome *</label>
                        <input type="text" name="tipo_nome" class="form-control" required
                               maxlength="60" placeholder="Ex: Academia, Folga Usina">
                    </div>
                    <div class="col-auto">
                        <label class="form-label">Cor</label>
                        <input type="color" name="tipo_cor" class="form-control form-control-color"
                               value="#ef4444" title="Cor de identificação">
                    </div>
                    <div class="col-md-3">
                        <div class="form-check mt-4">
                            <input class="form-check-input" type="checkbox" name="tipo_bloqueia"
                                   id="chkBloqueiaTotal" onchange="toggleHorasTipo(this.checked)">
                            <label class="form-check-label fw-medium" for="chkBloqueiaTotal">
                                Bloqueia o dia inteiro
                            </label>
                        </div>
                    </div>
                    <div class="col-md-2" id="boxTipoIni">
                        <label class="form-label">Início</label>
                        <input type="time" name="tipo_ini" id="tipoIni" class="form-control" value="13:00">
                    </div>
                    <div class="col-md-2" id="boxTipoFim">
                        <label class="form-label">Fim</label>
                        <input type="time" name="tipo_fim" id="tipoFim" class="form-control" value="18:00">
                    </div>
                </div>
                <div class="row g-3 mt-0" id="boxAlmocoTipo">
                    <div class="col-12">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="tipo_almoco_ativo"
                                   id="chkAlmocoTipo" onchange="toggleAlmocoTipo(this.checked)">
                            <label class="form-check-label" for="chkAlmocoTipo">Tem intervalo de almoço</label>
                        </div>
                    </div>
                    <div class="col-md-2" id="boxAlmocoIni" style="display:none">
                        <label class="form-label">Almoço início</label>
                        <input type="time" name="tipo_almoco_ini" id="tipoAlmocoIni" class="form-control" value="12:00">
                    </div>
                    <div class="col-md-2" id="boxAlmocoFim" style="display:none">
                        <label class="form-label">Almoço fim</label>
                        <input type="time" name="tipo_almoco_fim" id="tipoAlmocoFim" class="form-control" value="13:00">
                    </div>
                </div>
                <div class="form-text mt-2 mb-3">
                    Quando não bloqueia o dia, defina a janela reduzida de atendimento (ex: 13h–18h para manhã ocupada).
                </div>
                <button class="btn btn-accent btn-sm">
                    <i class="bi bi-plus me-1"></i> Adicionar tipo
                </button>
            </form>
        </div>

        <?php if (!empty($tiposDia)): ?>
        <div class="card">
            <div class="card-header px-4 py-3 fw-semibold">Tipos cadastrados</div>
            <ul class="list-group list-group-flush">
                <?php foreach ($tiposDia as $tp): ?>
                <li class="list-group-item px-4 py-3 d-flex align-items-center gap-3">
                    <span class="rounded-circle flex-shrink-0"
                          style="width:14px;height:14px;background:<?= h($tp['Cor']) ?>;display:inline-block;"></span>
                    <div class="flex-grow-1">
                        <span class="fw-medium"><?= h($tp['Nome']) ?></span>
                        <span class="text-secondary small ms-2">
                            <?php if ($tp['BloqueiaTotal']): ?>
                                Dia inteiro bloqueado
                            <?php elseif ($tp['HoraInicio']): ?>
                                Das <?= substr($tp['HoraInicio'], 0, 5) ?> às <?= substr($tp['HoraFim'], 0, 5) ?>
                                <?php if (!empty($tp['AlmocoInicio']) && !empty($tp['AlmocoFim'])): ?>
                                    · Almoço <?= substr($tp['AlmocoInicio'], 0, 5) ?>–<?= substr($tp['AlmocoFim'], 0, 5) ?>
                                <?php endif ?>
                            <?php endif ?>
                        </span>
                    </div>
                    <div class="d-flex gap-1">
                        <button class="btn btn-sm btn-outline-accent"
                                onclick="abrirEditarTipo(<?= h(json_encode([
                                    'id'        => $tp['IDTipo'],
                                    'nome'      => $tp['Nome'],
                                    'cor'       => $tp['Cor'],
                                    'bloq'      => (bool)$tp['BloqueiaTotal'],
                                    'ini'       => $tp['HoraInicio'] ? substr($tp['HoraInicio'],0,5) : '',
                                    'fim'       => $tp['HoraFim']    ? substr($tp['HoraFim'],   0,5) : '',
                                    'almocoAti' => !empty($tp['AlmocoInicio']),
                                    'almocoIni' => $tp['AlmocoInicio'] ? substr($tp['AlmocoInicio'],0,5) : '',
                                    'almocoFim' => $tp['AlmocoFim']    ? substr($tp['AlmocoFim'],   0,5) : '',
                                ])) ?>)">
                            <i class="bi bi-pencil"></i>
                        </button>
                        <form method="POST"
                              data-confirm="Remover '<?= h($tp['Nome']) ?>'? Dias atribuídos voltam ao normal."
                              data-confirm-label="Remover">
                            <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                            <input type="hidden" name="acao" value="rem_tipo">
                            <input type="hidden" name="tid" value="<?= h($tp['IDTipo']) ?>">
                            <button class="btn btn-sm btn-outline-danger"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </li>
                <?php endforeach ?>
            </ul>
        </div>
        <?php else: ?>
        <div class="text-center text-secondary py-5">
            <i class="bi bi-tags fs-2 d-block mb-2 opacity-25"></i>
            <p>Nenhum tipo cadastrado ainda.</p>
        </div>
        <?php endif ?>

        <script>
        function toggleHorasTipo(bloqTotal) {
            document.getElementById('boxTipoIni').style.display = bloqTotal ? 'none' : '';
            document.getElementById('boxTipoFim').style.display = bloqTotal ? 'none' : '';
            document.getElementById('tipoIni').required = !bloqTotal;
            document.getElementById('tipoFim').required = !bloqTotal;
            document.getElementById('boxAlmocoTipo').style.display = bloqTotal ? 'none' : '';
            if (bloqTotal) {
                document.getElementById('chkAlmocoTipo').checked = false;
                toggleAlmocoTipo(false);
            }
        }
        function toggleAlmocoTipo(ativo) {
            document.getElementById('boxAlmocoIni').style.display = ativo ? '' : 'none';
            document.getElementById('boxAlmocoFim').style.display = ativo ? '' : 'none';
            document.getElementById('tipoAlmocoIni').required = ativo;
            document.getElementById('tipoAlmocoFim').required = ativo;
        }

        function toggleHorasTipoEdit(bloqTotal) {
            document.getElementById('editBoxIni').style.display = bloqTotal ? 'none' : '';
            document.getElementById('editBoxFim').style.display = bloqTotal ? 'none' : '';
            document.getElementById('editTipoIni').required = !bloqTotal;
            document.getElementById('editTipoFim').required = !bloqTotal;
            document.getElementById('editBoxAlmoco').style.display = bloqTotal ? 'none' : '';
            if (bloqTotal) {
                document.getElementById('editChkAlmoco').checked = false;
                toggleAlmocoTipoEdit(false);
            }
        }
        function toggleAlmocoTipoEdit(ativo) {
            document.getElementById('editBoxAlmocoIni').style.display = ativo ? '' : 'none';
            document.getElementById('editBoxAlmocoFim').style.display = ativo ? '' : 'none';
            document.getElementById('editAlmocoIni').required = ativo;
            document.getElementById('editAlmocoFim').required = ativo;
        }
        function abrirEditarTipo(tp) {
            document.getElementById('editTid').value      = tp.id;
            document.getElementById('editNome').value     = tp.nome;
            document.getElementById('editCor').value      = tp.cor;
            document.getElementById('editChkBloq').checked = tp.bloq;
            document.getElementById('editTipoIni').value  = tp.ini;
            document.getElementById('editTipoFim').value  = tp.fim;
            document.getElementById('editChkAlmoco').checked = tp.almocoAti;
            document.getElementById('editAlmocoIni').value = tp.almocoIni;
            document.getElementById('editAlmocoFim').value = tp.almocoFim;
            toggleHorasTipoEdit(tp.bloq);
            toggleAlmocoTipoEdit(tp.almocoAti && !tp.bloq);
            var modal = new bootstrap.Modal(document.getElementById('modalEditarTipo'));
            modal.show();
        }
        </script>
    </div>
</div>

<!-- Modal: Editar tipo de dia -->
<div class="modal fade" id="modalEditarTipo" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form method="POST" action="<?= BASE ?>/painel/configuracoes.php?tab=tipos">
                <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
                <input type="hidden" name="acao" value="edit_tipo">
                <input type="hidden" name="tid" id="editTid">
                <div class="modal-header">
                    <h5 class="modal-title fw-semibold">Editar tipo de dia</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <div class="row g-3 align-items-end">
                        <div class="col-8">
                            <label class="form-label">Nome *</label>
                            <input type="text" name="tipo_nome" id="editNome" class="form-control" required maxlength="60">
                        </div>
                        <div class="col-auto">
                            <label class="form-label">Cor</label>
                            <input type="color" name="tipo_cor" id="editCor" class="form-control form-control-color">
                        </div>
                        <div class="col-12">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="tipo_bloqueia"
                                       id="editChkBloq" onchange="toggleHorasTipoEdit(this.checked)">
                                <label class="form-check-label fw-medium" for="editChkBloq">Bloqueia o dia inteiro</label>
                            </div>
                        </div>
                        <div class="col-6" id="editBoxIni">
                            <label class="form-label">Início</label>
                            <input type="time" name="tipo_ini" id="editTipoIni" class="form-control">
                        </div>
                        <div class="col-6" id="editBoxFim">
                            <label class="form-label">Fim</label>
                            <input type="time" name="tipo_fim" id="editTipoFim" class="form-control">
                        </div>
                        <div class="col-12" id="editBoxAlmoco">
                            <div class="form-check mb-2">
                                <input class="form-check-input" type="checkbox" name="tipo_almoco_ativo"
                                       id="editChkAlmoco" onchange="toggleAlmocoTipoEdit(this.checked)">
                                <label class="form-check-label" for="editChkAlmoco">Tem intervalo de almoço</label>
                            </div>
                            <div class="row g-2">
                                <div class="col-6" id="editBoxAlmocoIni" style="display:none">
                                    <label class="form-label">Almoço início</label>
                                    <input type="time" name="tipo_almoco_ini" id="editAlmocoIni" class="form-control">
                                </div>
                                <div class="col-6" id="editBoxAlmocoFim" style="display:none">
                                    <label class="form-label">Almoço fim</label>
                                    <input type="time" name="tipo_almoco_fim" id="editAlmocoFim" class="form-control">
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">Cancelar</button>
                    <button type="submit" class="btn btn-accent btn-sm">
                        <i class="bi bi-check me-1"></i> Salvar alterações
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    var tab = new URLSearchParams(location.search).get('tab');
    if (!tab) return;
    var map = { geral: '#tabGeral', horarios: '#tabHorarios', mensagens: '#tabMensagens', bloqueios: '#tabBloqueios', tipos: '#tabTipos' };
    var target = map[tab];
    if (!target) return;
    var btn = document.querySelector('[data-bs-target="' + target + '"]');
    if (btn) bootstrap.Tab.getOrCreateInstance(btn).show();
}());
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>