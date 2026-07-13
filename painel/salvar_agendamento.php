<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE . '/painel/agenda.php');
    exit;
}

if (!validarTokenCSRF($_POST['csrf_token'] ?? '')) {
    redirecionarComMensagem(BASE . '/painel/agenda.php', 'Token inválido.', 'danger');
}

// Suporta data+hora separados (novo_agendamento.php) ou data_hora combinado (legado)
$data       = trim($_POST['data']      ?? '');
$hora       = trim($_POST['hora']      ?? '');
$dataHora   = trim($_POST['data_hora'] ?? '');
if (!$dataHora && $data && $hora) {
    $dataHora = $data . ' ' . $hora . ':00';
}

$fkServico   = trim($_POST['fk_servico']  ?? '');
$fkSub       = trim($_POST['fk_sub']      ?? '') ?: null;
$tipoCliente = trim($_POST['tipo_cliente'] ?? 'cadastrada');
$valor       = trim($_POST['valor']       ?? '');
$obs         = trim($_POST['obs']         ?? '');

// URL de retorno em caso de erro (preserva a data na grade)
$retorno = BASE . '/painel/novo_agendamento.php' . ($data ? '?data=' . urlencode($data) : '');

if (!$fkServico || !$dataHora) {
    redirecionarComMensagem($retorno, 'Preencha o serviço e o horário.', 'warning');
}

try {
    // ── 1. Resolve serviço / sub-serviço ─────────────────────────
    if ($fkSub) {
        $svStmt = $pdo->prepare(
            'SELECT DuracaoMinutos, Preco FROM SubServicos WHERE IDSubServico = :id LIMIT 1'
        );
        $svStmt->execute([':id' => $fkSub]);
    } else {
        $svStmt = $pdo->prepare(
            'SELECT DuracaoMinutos, Preco FROM Servicos WHERE IDServico = :id LIMIT 1'
        );
        $svStmt->execute([':id' => $fkServico]);
    }
    $sv = $svStmt->fetch();
    if (!$sv) {
        redirecionarComMensagem($retorno, 'Serviço não encontrado.', 'danger');
    }

    $inicio = new DateTimeImmutable($dataHora);
    $fim    = $inicio->modify("+{$sv['DuracaoMinutos']} minutes");

    // ── 2. Resolve cliente ────────────────────────────────────────
    $fkCliente = null;

    if ($tipoCliente === 'avulsa') {
        $nomeAvulso = trim($_POST['nome_avulso'] ?? '');
        $telAvulso  = trim($_POST['tel_avulso']  ?? '');
        if (!$nomeAvulso) {
            redirecionarComMensagem($retorno, 'Informe o nome da cliente avulsa.', 'warning');
        }

        $uuidAvulso  = gerarUuid();
        $emailFake   = 'avulso_' . substr(str_replace('-', '', $uuidAvulso), 0, 8) . '@avulso.internal';
        $senhaFake   = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);

        $insUsr = $pdo->prepare(
            'INSERT INTO Usuarios (IDUsuario, Nome, Email, Senha, Telefone, NivelAcesso, Ativo, MomentoRegistro)
             VALUES (:id, :nome, :email, :senha, :tel, \'cliente\', 1, NOW())'
        );
        $insUsr->execute([
            ':id'    => $uuidAvulso,
            ':nome'  => $nomeAvulso,
            ':email' => $emailFake,
            ':senha' => $senhaFake,
            ':tel'   => $telAvulso ?: null,
        ]);
        $fkCliente = $uuidAvulso;

        // Anota no campo obs que é cliente avulsa
        $tagAvulso = '[Avulsa' . ($telAvulso ? " — $telAvulso" : '') . ']';
        $obs = $tagAvulso . ($obs ? " $obs" : '');

    } else {
        $fkCliente = trim($_POST['fk_cliente'] ?? '');
        if (!$fkCliente) {
            redirecionarComMensagem($retorno, 'Selecione uma cliente cadastrada.', 'warning');
        }
    }

    // ── 3. Verifica conflito ──────────────────────────────────────
    $checkConflito = $pdo->prepare(
        'SELECT COUNT(*) FROM Agendamentos
         WHERE StatusAgendamento NOT IN (\'cancelado\')
           AND DataHoraAgendamento < :fim
           AND DataHoraFim > :ini'
    );
    $checkConflito->execute([
        ':ini' => $inicio->format('Y-m-d H:i:s'),
        ':fim' => $fim->format('Y-m-d H:i:s'),
    ]);
    if ((int)$checkConflito->fetchColumn() > 0) {
        redirecionarComMensagem($retorno, 'Conflito: já existe agendamento neste horário.', 'warning');
    }

    // ── 4. Insere agendamento ─────────────────────────────────────
    $recorrencia  = !empty($_POST['recorrencia']);
    $recVezes     = $recorrencia ? min(104, max(1, (int)($_POST['rec_vezes']     ?? 1))) : 1;
    $recIntervalo = $recorrencia ? max(1,       (int)($_POST['rec_intervalo']    ?? 1))  : 1;
    $recUniDias   = $recorrencia ? max(1,       (int)($_POST['rec_unidade']      ?? 7))  : 1;
    $intervaloDias = $recIntervalo * $recUniDias;

    $grupoRec = ($recorrencia && $recVezes > 1) ? gerarUuid() : null;

    $insStmt = $pdo->prepare(
        'INSERT INTO Agendamentos
            (IDAgendamento, FKCliente, FKServico, FKSubServico,
             DataHoraAgendamento, DataHoraFim,
             StatusAgendamento, ValorCobrado, Observacoes,
             GrupoRecorrencia, OrdemRecorrencia)
         VALUES (:id, :fkc, :fks, :fksub, :ini, :fim, \'confirmado\', :valor, :obs, :grupo, :ordem)'
    );

    $chkStmt = $pdo->prepare(
        'SELECT COUNT(*) FROM Agendamentos
         WHERE StatusAgendamento NOT IN (\'cancelado\')
           AND DataHoraAgendamento < :fim
           AND DataHoraFim > :ini'
    );

    $id = gerarUuid();
    $insStmt->execute([
        ':id'    => $id,
        ':fkc'   => $fkCliente,
        ':fks'   => $fkServico,
        ':fksub' => $fkSub,
        ':ini'   => $inicio->format('Y-m-d H:i:s'),
        ':fim'   => $fim->format('Y-m-d H:i:s'),
        ':valor' => $valor !== '' ? (float)$valor : $sv['Preco'],
        ':obs'   => $obs ?: null,
        ':grupo' => $grupoRec,
        ':ordem' => $grupoRec ? 1 : null,
    ]);

    $criados   = 1;
    $pulados   = 0;

    if ($grupoRec && $recVezes > 1) {
        for ($i = 2; $i <= $recVezes; $i++) {
            $offsetDias = ($i - 1) * $intervaloDias;
            $iniRec     = $inicio->modify("+{$offsetDias} days");
            $fimRec     = $iniRec->modify("+{$sv['DuracaoMinutos']} minutes");

            $chkStmt->execute([':ini' => $iniRec->format('Y-m-d H:i:s'), ':fim' => $fimRec->format('Y-m-d H:i:s')]);
            if ((int)$chkStmt->fetchColumn() > 0) { $pulados++; continue; }

            $insStmt->execute([
                ':id'    => gerarUuid(),
                ':fkc'   => $fkCliente,
                ':fks'   => $fkServico,
                ':fksub' => $fkSub,
                ':ini'   => $iniRec->format('Y-m-d H:i:s'),
                ':fim'   => $fimRec->format('Y-m-d H:i:s'),
                ':valor' => $valor !== '' ? (float)$valor : $sv['Preco'],
                ':obs'   => $obs ?: null,
                ':grupo' => $grupoRec,
                ':ordem' => $i,
            ]);
            $criados++;
        }
    }

} catch (Exception $e) {
    error_log('[SalvarAg] ' . $e->getMessage());
    redirecionarComMensagem($retorno, 'Erro ao salvar agendamento.', 'danger');
}

$msg = $grupoRec
    ? "Série criada: {$criados} agendamento(s)." . ($pulados > 0 ? " {$pulados} datas com conflito foram ignoradas." : '')
    : 'Agendamento criado com sucesso!';

redirecionarComMensagem(
    BASE . '/painel/agenda.php?data=' . urlencode($data ?: date('Y-m-d')),
    $msg,
    'success'
);
