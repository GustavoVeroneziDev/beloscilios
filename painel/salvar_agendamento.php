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

// URL de retorno em caso de erro (preserva data e modo força)
$forca   = !empty($_POST['forca']);
$retorno = BASE . '/painel/novo_agendamento.php'
         . ($data ? '?data=' . urlencode($data) : '')
         . ($forca ? ($data ? '&' : '?') . 'forca=1' : '');

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
    $fkCliente  = null;
    $nomeAvulso = $telSanitizado = $emailFake = $senhaFake = null;
    $modoAvulsa = 'nenhum'; // 'criar' | 'atualizar_temp' | 'nenhum'

    if ($tipoCliente === 'avulsa') {
        $nomeAvulso   = trim($_POST['nome_avulso'] ?? '');
        $telAvulsoRaw = trim($_POST['tel_avulso']  ?? '');
        if (!$nomeAvulso) {
            redirecionarComMensagem($retorno, 'Informe o nome da cliente avulsa.', 'warning');
        }
        $telSanitizado = $telAvulsoRaw ? sanitizarTelefone($telAvulsoRaw) : null;

        // 1. Prioridade: usuária real já cadastrada com esse telefone
        if ($telSanitizado) {
            $stmReal = $pdo->prepare(
                'SELECT IDUsuario FROM Usuarios
                 WHERE Telefone = :tel AND Temporario = 0 AND Ativo = 1 LIMIT 1'
            );
            $stmReal->execute([':tel' => $telSanitizado]);
            $fkCliente = $stmReal->fetchColumn() ?: null;
        }

        // 2. Temporária existente com mesmo telefone — reutiliza e atualiza nome
        if (!$fkCliente && $telSanitizado) {
            $stmTemp = $pdo->prepare(
                'SELECT IDUsuario FROM Usuarios
                 WHERE Telefone = :tel AND Temporario = 1 LIMIT 1'
            );
            $stmTemp->execute([':tel' => $telSanitizado]);
            $idTemp = $stmTemp->fetchColumn();
            if ($idTemp) {
                $fkCliente  = $idTemp;
                $modoAvulsa = 'atualizar_temp';
            }
        }

        // 3. Nenhuma encontrada — cria nova temporária
        if (!$fkCliente) {
            $uuid      = gerarUuid();
            $emailFake = 'avulso_' . substr(str_replace('-', '', $uuid), 0, 8) . '@avulso.internal';
            $senhaFake = password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT);
            $fkCliente = $uuid;
            $modoAvulsa = 'criar';
        }

        $tagAvulso = '[Avulsa' . ($telSanitizado ? " — $telSanitizado" : '') . ']';
        $obs       = $tagAvulso . ($obs ? " $obs" : '');

    } else {
        $fkCliente = trim($_POST['fk_cliente'] ?? '');
        if (!$fkCliente) {
            redirecionarComMensagem($retorno, 'Selecione uma cliente cadastrada.', 'warning');
        }
    }

    // ── 3. Verifica conflito (agendamentos + reservas temporárias ativas) ──
    $checkConflito = $pdo->prepare(
        'SELECT COUNT(*) FROM Agendamentos
         WHERE StatusAgendamento NOT IN (\'cancelado\')
           AND DataHoraAgendamento < :fim
           AND DataHoraFim > :ini'
    );
    $checkConflito->execute([':ini' => $inicio->format('Y-m-d H:i:s'), ':fim' => $fim->format('Y-m-d H:i:s')]);
    if ($checkConflito->fetchColumn() > 0) {
        redirecionarComMensagem($retorno, 'Conflito: já existe agendamento neste horário.', 'warning');
    }

    $checkTemp = $pdo->prepare(
        'SELECT COUNT(*) FROM ReservasTemporarias
         WHERE DataHoraSlot < :fim AND DataHoraFim > :ini AND ExpiraEm > NOW()'
    );
    $checkTemp->execute([':ini' => $inicio->format('Y-m-d H:i:s'), ':fim' => $fim->format('Y-m-d H:i:s')]);
    if ($checkTemp->fetchColumn() > 0) {
        redirecionarComMensagem($retorno, 'Horário reservado por uma cliente no fluxo de agendamento. Aguarde 10 minutos ou escolha outro.', 'warning');
    }

    // ── 4. Insere agendamento em transação ───────────────────────
    $pdo->beginTransaction();

    if ($modoAvulsa === 'criar') {
        $pdo->prepare(
            'INSERT INTO Usuarios
                (IDUsuario, Nome, Email, Senha, Telefone, NivelAcesso, Temporario, Ativo, MomentoRegistro)
             VALUES (:id, :nome, :email, :senha, :tel, \'cliente\', 1, 1, NOW())'
        )->execute([
            ':id'    => $fkCliente,
            ':nome'  => $nomeAvulso,
            ':email' => $emailFake,
            ':senha' => $senhaFake,
            ':tel'   => $telSanitizado,
        ]);
    } elseif ($modoAvulsa === 'atualizar_temp') {
        $pdo->prepare('UPDATE Usuarios SET Nome = :nome WHERE IDUsuario = :id')
            ->execute([':nome' => $nomeAvulso, ':id' => $fkCliente]);
    }

    $id   = gerarUuid();
    $stmt = $pdo->prepare(
        'INSERT INTO Agendamentos
            (IDAgendamento, FKCliente, FKServico, FKSubServico,
             DataHoraAgendamento, DataHoraFim,
             StatusAgendamento, ValorCobrado, Observacoes)
         VALUES (:id, :fkc, :fks, :fksub, :ini, :fim, \'confirmado\', :valor, :obs)'
    );
    $stmt->execute([
        ':id'    => $id,
        ':fkc'   => $fkCliente,
        ':fks'   => $fkServico,
        ':fksub' => $fkSub,
        ':ini'   => $inicio->format('Y-m-d H:i:s'),
        ':fim'   => $fim->format('Y-m-d H:i:s'),
        ':valor' => $valor !== '' ? (float)$valor : $sv['Preco'],
        ':obs'   => $obs ?: null,
    ]);

    $pdo->commit();

} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[SalvarAg] ' . $e->getMessage());
    redirecionarComMensagem($retorno, 'Erro ao salvar agendamento.', 'danger');
}

redirecionarComMensagem(
    BASE . '/painel/agenda.php?data=' . urlencode($data ?: date('Y-m-d')),
    'Agendamento criado com sucesso!',
    'success'
);
