<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('cliente');

$uid = $_SESSION['usuario_id'];

// Destino após salvar
$next = ($_GET['next'] ?? '') === 'agendamento' ? 'agendamento' : 'perfil';
$redirectUrl = $next === 'agendamento'
    ? BASE . '/agendamento/index.php'
    : BASE . '/usuario/perfil.php';

// Busca ficha existente
$ficha = null;
try {
    $s = $pdo->prepare('SELECT * FROM FichaAnamnese WHERE FKCliente = :id LIMIT 1');
    $s->execute([':id' => $uid]);
    $ficha = $s->fetch() ?: null;
} catch (PDOException $e) {
    error_log('[Ficha] ' . $e->getMessage());
}

// ── POST: salvar ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
        redirecionarComMensagem(BASE . '/usuario/ficha_anamnese.php?next=' . $next, 'Token inválido.', 'danger');
    }
    if (empty($_POST['termo'])) {
        redirecionarComMensagem(BASE . '/usuario/ficha_anamnese.php?next=' . $next, 'É necessário aceitar o termo de consentimento para continuar.', 'warning');
    }

    $p = $_POST;
    $sim = fn($k) => !empty($p[$k]) && $p[$k] === 'sim';
    $det = fn($k) => trim($p[$k] ?? '') ?: null;

    $campos = [
        ':gravida'        => $sim('gravida')         ? 1 : 0,
        ':grav_sem'       => ($sim('gravida') && !empty($p['gravida_semanas'])) ? (int)$p['gravida_semanas'] : null,
        ':amamentando'    => $sim('amamentando')      ? 1 : 0,
        ':alergia_ad'     => $sim('alergia_adesivo')  ? 1 : 0,
        ':alergia_ad_det' => $sim('alergia_adesivo')  ? $det('alergia_adesivo_det') : null,
        ':alergia_lat'    => $sim('alergia_latex')    ? 1 : 0,
        ':reacao_ant'     => $sim('reacao_anterior')  ? 1 : 0,
        ':reacao_ant_det' => $sim('reacao_anterior')  ? $det('reacao_anterior_det') : null,
        ':prob_ocular'    => $sim('problema_ocular')  ? 1 : 0,
        ':prob_ocular_det'=> $sim('problema_ocular')  ? $det('problema_ocular_det') : null,
        ':usa_lentes'     => $sim('usa_lentes')       ? 1 : 0,
        ':tireoide'       => $sim('tireoide')         ? 1 : 0,
        ':tireoide_det'   => $sim('tireoide')         ? $det('tireoide_det') : null,
        ':diabetes'       => $sim('diabetes')         ? 1 : 0,
        ':pressao'        => $sim('pressao_alterada') ? 1 : 0,
        ':usa_med'        => $sim('usa_medicamentos') ? 1 : 0,
        ':usa_med_det'    => $sim('usa_medicamentos') ? $det('medicamentos_det') : null,
        ':quimio'         => $sim('quimio_radio')     ? 1 : 0,
        ':retinoide'      => $sim('retinoide')        ? 1 : 0,
        ':cond_pele'      => $sim('cond_pele')        ? 1 : 0,
        ':cond_pele_det'  => $sim('cond_pele')        ? $det('cond_pele_det') : null,
        ':trico'          => $sim('tricotilomania')   ? 1 : 0,
        ':obs'            => $det('observacoes'),
        ':id'             => $uid,
    ];

    try {
        if ($ficha) {
            $pdo->prepare(
                'UPDATE FichaAnamnese SET
                    Gravida=:gravida, GravidaSemanas=:grav_sem, Amamentando=:amamentando,
                    AlergiaAdesivo=:alergia_ad, AlergiaAdesivoDet=:alergia_ad_det,
                    AlergiaLatex=:alergia_lat, ReacaoAnterior=:reacao_ant, ReacaoAnteriorDet=:reacao_ant_det,
                    ProblemaOcular=:prob_ocular, ProblemaOcularDet=:prob_ocular_det, UsaLentes=:usa_lentes,
                    Tireoide=:tireoide, TireoideDet=:tireoide_det, Diabetes=:diabetes,
                    PressaoAlterada=:pressao, UsaMedicamentos=:usa_med, MedicamentosDet=:usa_med_det,
                    QuimioRadio=:quimio, Retinoide=:retinoide, CondicaoPele=:cond_pele,
                    CondicaoPeleDet=:cond_pele_det, Tricotilomania=:trico, Observacoes=:obs,
                    TermoConsentimento=1
                 WHERE FKCliente=:id'
            )->execute($campos);
        } else {
            $campos[':ficha_id'] = gerarUuid();
            $pdo->prepare(
                'INSERT INTO FichaAnamnese (IDFicha, FKCliente,
                    Gravida, GravidaSemanas, Amamentando, AlergiaAdesivo, AlergiaAdesivoDet,
                    AlergiaLatex, ReacaoAnterior, ReacaoAnteriorDet, ProblemaOcular, ProblemaOcularDet,
                    UsaLentes, Tireoide, TireoideDet, Diabetes, PressaoAlterada, UsaMedicamentos,
                    MedicamentosDet, QuimioRadio, Retinoide, CondicaoPele, CondicaoPeleDet,
                    Tricotilomania, Observacoes, TermoConsentimento)
                 VALUES (:ficha_id, :id,
                    :gravida, :grav_sem, :amamentando, :alergia_ad, :alergia_ad_det,
                    :alergia_lat, :reacao_ant, :reacao_ant_det, :prob_ocular, :prob_ocular_det,
                    :usa_lentes, :tireoide, :tireoide_det, :diabetes, :pressao, :usa_med,
                    :usa_med_det, :quimio, :retinoide, :cond_pele, :cond_pele_det,
                    :trico, :obs, 1)'
            )->execute($campos);
        }
        redirecionarComMensagem($redirectUrl, 'Ficha de anamnese salva com sucesso!', 'success');
    } catch (PDOException $e) {
        error_log('[Ficha] ' . $e->getMessage());
        redirecionarComMensagem(BASE . '/usuario/ficha_anamnese.php?next=' . $next, 'Erro ao salvar. Tente novamente.', 'danger');
    }
}

// Helper: valor de um campo booleano da ficha (preenchendo formulário)
$v = fn(string $campo): string => ($ficha && $ficha[$campo]) ? 'sim' : ($ficha ? 'nao' : '');

$paginaTitulo = 'Ficha de Saúde';
$areaAtual    = 'cliente';
require_once __DIR__ . '/../geral/header.php';
?>

<?php if ($next === 'agendamento' && !$ficha): ?>
<div class="alert alert-info d-flex align-items-start gap-2 mb-4">
    <i class="bi bi-clipboard2-pulse-fill fs-5 flex-shrink-0 mt-1"></i>
    <div>
        <strong>Antes de agendar, precisamos de algumas informações de saúde.</strong><br>
        <span class="text-secondary small">A ficha é necessária para garantir a segurança do seu procedimento. Preenchimento rápido, uma única vez.</span>
    </div>
</div>
<?php elseif ($ficha): ?>
<div class="d-flex align-items-center gap-2 mb-4">
    <h5 class="fw-bold mb-0">Ficha de Anamnese</h5>
    <?php
    $atualizado = date('d/m/Y', strtotime($ficha['AtualizadoEm']));
    ?>
    <span class="badge bg-secondary ms-1">Atualizada em <?= $atualizado ?></span>
</div>
<?php else: ?>
<h5 class="fw-bold mb-4">Ficha de Anamnese</h5>
<?php endif ?>

<form method="POST" id="formFicha" novalidate>
    <input type="hidden" name="csrf_token" value="<?= gerarTokenCSRF() ?>">
    <input type="hidden" name="next" value="<?= h($next) ?>">

    <!-- Seção 1: Saúde Reprodutiva -->
    <div class="card mb-3">
        <div class="card-header px-4 py-3 fw-semibold d-flex align-items-center gap-2">
            <i class="bi bi-heart text-accent"></i> Saúde Reprodutiva
        </div>
        <div class="card-body px-4 py-3 d-flex flex-column gap-3">

            <?php $c = $v('Gravida'); ?>
            <div class="ficha-q">
                <label class="form-label fw-medium mb-2">Está grávida atualmente?</label>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="gravida" value="sim" id="gravida_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?> onchange="toggle('boxGravidaSem',this.value==='sim')">
                        <label class="form-check-label" for="gravida_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="gravida" value="nao" id="gravida_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?> onchange="toggle('boxGravidaSem',false)">
                        <label class="form-check-label" for="gravida_nao">Não</label>
                    </div>
                </div>
                <div id="boxGravidaSem" class="mt-2 ps-3" style="display:<?= $c === 'sim' ? 'block' : 'none' ?>;">
                    <label class="form-label small text-secondary">Semanas de gestação:</label>
                    <input type="number" name="gravida_semanas" class="form-control form-control-sm" style="max-width:120px;"
                           min="1" max="45" value="<?= h($ficha['GravidaSemanas'] ?? '') ?>">
                </div>
            </div>

            <?php $c = $v('Amamentando'); ?>
            <div class="ficha-q border-top pt-3">
                <label class="form-label fw-medium mb-2">Está amamentando?</label>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="amamentando" value="sim" id="amamentando_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="amamentando_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="amamentando" value="nao" id="amamentando_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="amamentando_nao">Não</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Seção 2: Alergias e Reações -->
    <div class="card mb-3">
        <div class="card-header px-4 py-3 fw-semibold d-flex align-items-center gap-2">
            <i class="bi bi-exclamation-triangle text-warning"></i> Alergias e Reações
        </div>
        <div class="card-body px-4 py-3 d-flex flex-column gap-3">

            <?php $c = $v('AlergiaAdesivo'); ?>
            <div class="ficha-q">
                <label class="form-label fw-medium mb-1">Tem ou suspeita de alergia a adesivos ou colas?</label>
                <p class="text-secondary small mb-2">Inclui colas de cílios (cianoacrilato), esparadrapo, curativos.</p>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="alergia_adesivo" value="sim" id="alergia_ad_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?> onchange="toggle('boxAlergiaAdDet',this.value==='sim')">
                        <label class="form-check-label" for="alergia_ad_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="alergia_adesivo" value="nao" id="alergia_ad_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?> onchange="toggle('boxAlergiaAdDet',false)">
                        <label class="form-check-label" for="alergia_ad_nao">Não</label>
                    </div>
                </div>
                <div id="boxAlergiaAdDet" class="mt-2 ps-3" style="display:<?= $c === 'sim' ? 'block' : 'none' ?>;">
                    <label class="form-label small text-secondary">Descreva a reação:</label>
                    <textarea name="alergia_adesivo_det" class="form-control form-control-sm" rows="2"><?= h($ficha['AlergiaAdesivoDet'] ?? '') ?></textarea>
                </div>
            </div>

            <?php $c = $v('AlergiaLatex'); ?>
            <div class="ficha-q border-top pt-3">
                <label class="form-label fw-medium mb-2">Tem alergia a látex?</label>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="alergia_latex" value="sim" id="alergia_lat_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="alergia_lat_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="alergia_latex" value="nao" id="alergia_lat_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="alergia_lat_nao">Não</label>
                    </div>
                </div>
            </div>

            <?php $c = $v('ReacaoAnterior'); ?>
            <div class="ficha-q border-top pt-3">
                <label class="form-label fw-medium mb-1">Já teve alguma reação em procedimento de cílios anteriormente?</label>
                <p class="text-secondary small mb-2">Vermelhidão, inchaço, ardência, olho inchado, etc.</p>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="reacao_anterior" value="sim" id="reacao_ant_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?> onchange="toggle('boxReacaoAntDet',this.value==='sim')">
                        <label class="form-check-label" for="reacao_ant_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="reacao_anterior" value="nao" id="reacao_ant_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?> onchange="toggle('boxReacaoAntDet',false)">
                        <label class="form-check-label" for="reacao_ant_nao">Não</label>
                    </div>
                </div>
                <div id="boxReacaoAntDet" class="mt-2 ps-3" style="display:<?= $c === 'sim' ? 'block' : 'none' ?>;">
                    <label class="form-label small text-secondary">Descreva o que aconteceu:</label>
                    <textarea name="reacao_anterior_det" class="form-control form-control-sm" rows="2"><?= h($ficha['ReacaoAnteriorDet'] ?? '') ?></textarea>
                </div>
            </div>

        </div>
    </div>

    <!-- Seção 3: Condições Oculares -->
    <div class="card mb-3">
        <div class="card-header px-4 py-3 fw-semibold d-flex align-items-center gap-2">
            <i class="bi bi-eye text-accent"></i> Condições Oculares
        </div>
        <div class="card-body px-4 py-3 d-flex flex-column gap-3">

            <?php $c = $v('ProblemaOcular'); ?>
            <div class="ficha-q">
                <label class="form-label fw-medium mb-1">Tem algum problema ocular diagnosticado?</label>
                <p class="text-secondary small mb-2">Blefarite, conjuntivite, glaucoma, olho seco, ceratocone, cirurgia ocular recente, etc.</p>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="problema_ocular" value="sim" id="prob_oc_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?> onchange="toggle('boxProbOcDet',this.value==='sim')">
                        <label class="form-check-label" for="prob_oc_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="problema_ocular" value="nao" id="prob_oc_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?> onchange="toggle('boxProbOcDet',false)">
                        <label class="form-check-label" for="prob_oc_nao">Não</label>
                    </div>
                </div>
                <div id="boxProbOcDet" class="mt-2 ps-3" style="display:<?= $c === 'sim' ? 'block' : 'none' ?>;">
                    <label class="form-label small text-secondary">Qual condição?</label>
                    <textarea name="problema_ocular_det" class="form-control form-control-sm" rows="2"><?= h($ficha['ProblemaOcularDet'] ?? '') ?></textarea>
                </div>
            </div>

            <?php $c = $v('UsaLentes'); ?>
            <div class="ficha-q border-top pt-3">
                <label class="form-label fw-medium mb-1">Usa lentes de contato?</label>
                <p class="text-secondary small mb-2">Você precisará retirá-las antes do procedimento e aguardar 24h para colocá-las novamente.</p>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="usa_lentes" value="sim" id="usa_lentes_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="usa_lentes_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="usa_lentes" value="nao" id="usa_lentes_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="usa_lentes_nao">Não</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Seção 4: Saúde Geral -->
    <div class="card mb-3">
        <div class="card-header px-4 py-3 fw-semibold d-flex align-items-center gap-2">
            <i class="bi bi-clipboard2-pulse text-accent"></i> Saúde Geral
        </div>
        <div class="card-body px-4 py-3 d-flex flex-column gap-3">

            <?php $c = $v('Tireoide'); ?>
            <div class="ficha-q">
                <label class="form-label fw-medium mb-1">Tem alteração na tireoide?</label>
                <p class="text-secondary small mb-2">Hipotireoidismo ou hipertireoidismo podem afetar o crescimento e retenção dos cílios.</p>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="tireoide" value="sim" id="tir_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?> onchange="toggle('boxTireoideDet',this.value==='sim')">
                        <label class="form-check-label" for="tir_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="tireoide" value="nao" id="tir_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?> onchange="toggle('boxTireoideDet',false)">
                        <label class="form-check-label" for="tir_nao">Não</label>
                    </div>
                </div>
                <div id="boxTireoideDet" class="mt-2 ps-3" style="display:<?= $c === 'sim' ? 'block' : 'none' ?>;">
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="tireoide_det" value="Hipotireoidismo" id="tir_hipo"
                                   <?= ($ficha['TireoideDet'] ?? '') === 'Hipotireoidismo' ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="tir_hipo">Hipotireoidismo</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="tireoide_det" value="Hipertireoidismo" id="tir_hiper"
                                   <?= ($ficha['TireoideDet'] ?? '') === 'Hipertireoidismo' ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="tir_hiper">Hipertireoidismo</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="tireoide_det" value="Outro" id="tir_outro"
                                   <?= (!empty($ficha['TireoideDet']) && !in_array($ficha['TireoideDet'], ['Hipotireoidismo','Hipertireoidismo'])) ? 'checked' : '' ?>>
                            <label class="form-check-label small" for="tir_outro">Outro</label>
                        </div>
                    </div>
                </div>
            </div>

            <?php $c = $v('Diabetes'); ?>
            <div class="ficha-q border-top pt-3">
                <label class="form-label fw-medium mb-2">Tem diabetes ou problemas de circulação?</label>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="diabetes" value="sim" id="diab_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="diab_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="diabetes" value="nao" id="diab_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="diab_nao">Não</label>
                    </div>
                </div>
            </div>

            <?php $c = $v('PressaoAlterada'); ?>
            <div class="ficha-q border-top pt-3">
                <label class="form-label fw-medium mb-2">Tem pressão arterial alterada (alta ou baixa)?</label>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="pressao_alterada" value="sim" id="press_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="press_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="pressao_alterada" value="nao" id="press_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="press_nao">Não</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Seção 5: Medicamentos e Tratamentos -->
    <div class="card mb-3">
        <div class="card-header px-4 py-3 fw-semibold d-flex align-items-center gap-2">
            <i class="bi bi-capsule text-accent"></i> Medicamentos e Tratamentos
        </div>
        <div class="card-body px-4 py-3 d-flex flex-column gap-3">

            <?php $c = $v('UsaMedicamentos'); ?>
            <div class="ficha-q">
                <label class="form-label fw-medium mb-1">Usa algum medicamento de uso contínuo?</label>
                <p class="text-secondary small mb-2">Alguns medicamentos podem afetar a retenção dos cílios ou causar sensibilidade.</p>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="usa_medicamentos" value="sim" id="med_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?> onchange="toggle('boxMedDet',this.value==='sim')">
                        <label class="form-check-label" for="med_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="usa_medicamentos" value="nao" id="med_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?> onchange="toggle('boxMedDet',false)">
                        <label class="form-check-label" for="med_nao">Não</label>
                    </div>
                </div>
                <div id="boxMedDet" class="mt-2 ps-3" style="display:<?= $c === 'sim' ? 'block' : 'none' ?>;">
                    <label class="form-label small text-secondary">Quais medicamentos?</label>
                    <textarea name="medicamentos_det" class="form-control form-control-sm" rows="2"><?= h($ficha['MedicamentosDet'] ?? '') ?></textarea>
                </div>
            </div>

            <?php $c = $v('QuimioRadio'); ?>
            <div class="ficha-q border-top pt-3">
                <label class="form-label fw-medium mb-1">Fez ou está fazendo quimioterapia ou radioterapia?</label>
                <p class="text-secondary small mb-2">Esses tratamentos afetam o crescimento dos cílios e podem contraindicar o procedimento.</p>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="quimio_radio" value="sim" id="quimio_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="quimio_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="quimio_radio" value="nao" id="quimio_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="quimio_nao">Não</label>
                    </div>
                </div>
            </div>

            <?php $c = $v('Retinoide'); ?>
            <div class="ficha-q border-top pt-3">
                <label class="form-label fw-medium mb-1">Usou isotretinoína (Roacutan, Tretinoína) nos últimos 6 meses?</label>
                <p class="text-secondary small mb-2">Resseca a pele e reduz a retenção da cola.</p>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="retinoide" value="sim" id="ret_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ret_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="retinoide" value="nao" id="ret_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="ret_nao">Não</label>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Seção 6: Pele e Outros -->
    <div class="card mb-3">
        <div class="card-header px-4 py-3 fw-semibold d-flex align-items-center gap-2">
            <i class="bi bi-person-heart text-accent"></i> Pele e Outros
        </div>
        <div class="card-body px-4 py-3 d-flex flex-column gap-3">

            <?php $c = $v('CondicaoPele'); ?>
            <div class="ficha-q">
                <label class="form-label fw-medium mb-1">Tem alguma condição de pele na região dos olhos?</label>
                <p class="text-secondary small mb-2">Psoríase, dermatite atópica, rosácea, seborreia, etc.</p>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="cond_pele" value="sim" id="pele_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?> onchange="toggle('boxPeleDet',this.value==='sim')">
                        <label class="form-check-label" for="pele_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="cond_pele" value="nao" id="pele_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?> onchange="toggle('boxPeleDet',false)">
                        <label class="form-check-label" for="pele_nao">Não</label>
                    </div>
                </div>
                <div id="boxPeleDet" class="mt-2 ps-3" style="display:<?= $c === 'sim' ? 'block' : 'none' ?>;">
                    <label class="form-label small text-secondary">Qual condição?</label>
                    <input type="text" name="cond_pele_det" class="form-control form-control-sm"
                           value="<?= h($ficha['CondicaoPeleDet'] ?? '') ?>">
                </div>
            </div>

            <?php $c = $v('Tricotilomania'); ?>
            <div class="ficha-q border-top pt-3">
                <label class="form-label fw-medium mb-1">Tem tricotilomania?</label>
                <p class="text-secondary small mb-2">Hábito involuntário de arrancar cílios, sobrancelhas ou cabelos.</p>
                <div class="d-flex gap-3">
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="tricotilomania" value="sim" id="trico_sim"
                               <?= $c === 'sim' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="trico_sim">Sim</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="radio" name="tricotilomania" value="nao" id="trico_nao"
                               <?= $c === 'nao' ? 'checked' : '' ?>>
                        <label class="form-check-label" for="trico_nao">Não</label>
                    </div>
                </div>
            </div>

            <div class="ficha-q border-top pt-3">
                <label class="form-label fw-medium mb-1" for="observacoes">Observações adicionais</label>
                <p class="text-secondary small mb-2">Alguma informação de saúde que queira compartilhar com a profissional.</p>
                <textarea name="observacoes" id="observacoes" class="form-control" rows="3"><?= h($ficha['Observacoes'] ?? '') ?></textarea>
            </div>
        </div>
    </div>

    <!-- Termo de Consentimento -->
    <div class="card mb-4 border-accent" style="border-color:var(--accent)!important;">
        <div class="card-body px-4 py-3">
            <div class="form-check">
                <input class="form-check-input" type="checkbox" name="termo" id="chkTermo" value="1"
                       <?= $ficha ? 'checked' : '' ?> required>
                <label class="form-check-label small" for="chkTermo">
                    Declaro que as informações prestadas acima são verdadeiras e estou ciente de que omiti-las
                    pode comprometer a segurança do procedimento. Autorizo o uso dessas informações pela
                    profissional para fins exclusivos de avaliação do atendimento.
                </label>
            </div>
        </div>
    </div>

    <div class="d-flex gap-2 justify-content-end mb-5">
        <?php if ($ficha && $next !== 'agendamento'): ?>
        <a href="<?= BASE ?>/usuario/perfil.php" class="btn btn-outline-secondary">Cancelar</a>
        <?php endif ?>
        <button type="submit" class="btn btn-accent px-4">
            <i class="bi bi-check-lg me-1"></i>
            <?= $ficha ? 'Atualizar ficha' : 'Salvar e continuar' ?>
        </button>
    </div>
</form>

<script>
function toggle(id, show) {
    document.getElementById(id).style.display = show ? 'block' : 'none';
}
document.getElementById('formFicha').addEventListener('submit', function(e) {
    if (!document.getElementById('chkTermo').checked) {
        e.preventDefault();
        document.getElementById('chkTermo').closest('.card').style.border = '2px solid #dc3545';
        document.getElementById('chkTermo').closest('.card').scrollIntoView({behavior:'smooth', block:'center'});
    }
});
</script>

<?php require_once __DIR__ . '/../geral/footer.php' ?>
