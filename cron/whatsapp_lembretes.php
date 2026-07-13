<?php
/**
 * Cron: lembrete 24h antes do agendamento.
 * Executar 1x por dia (ex: 08h):
 *   0 8 * * * php /caminho/para/beloscilios/cron/whatsapp_lembretes.php >> /logs/wa_lembretes.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso restrito ao CLI.');
}

require_once __DIR__ . '/../config/conexao.php';

echo '[' . date('Y-m-d H:i:s') . '] Iniciando lembretes...' . PHP_EOL;

// Agendamentos entre 24h e 25h a partir de agora
$ini24h = date('Y-m-d H:i:s', strtotime('+24 hours'));
$fim25h = date('Y-m-d H:i:s', strtotime('+25 hours'));

try {
    $stmt = $pdo->prepare(
        'SELECT a.IDAgendamento, a.FKCliente AS IDCliente, a.DataHoraAgendamento,
                u.Nome AS NomeCliente, u.Telefone,
                s.Nome AS NomeServico
         FROM Agendamentos a
         JOIN Usuarios u ON u.IDUsuario = a.FKCliente
         JOIN Servicos s ON s.IDServico = a.FKServico
         WHERE a.DataHoraAgendamento BETWEEN :ini AND :fim
           AND a.StatusAgendamento IN (\'pendente\',\'confirmado\')
           AND a.NotificacaoLembreteEnviada = 0'
    );
    $stmt->execute([':ini' => $ini24h, ':fim' => $fim25h]);
    $agendamentos = $stmt->fetchAll();
} catch (PDOException $e) {
    echo '[ERRO BD] ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

if (empty($agendamentos)) {
    echo 'Nenhum lembrete a enviar.' . PHP_EOL;
    exit(0);
}

$msgTpl   = getConfig($pdo, 'msg_lembrete', '');
$enviados = 0;
$erros    = 0;

if (!$msgTpl) {
    echo '[AVISO] Template msg_lembrete não configurado — nenhum lembrete enviado.' . PHP_EOL;
    exit(0);
}

foreach ($agendamentos as $ag) {
    $telNorm = sanitizarTelefone((string)($ag['Telefone'] ?? ''));
    if (!$telNorm) {
        echo "[SKIP] {$ag['IDAgendamento']} — telefone inválido ou ausente" . PHP_EOL;
        continue;
    }

    $ts         = strtotime($ag['DataHoraAgendamento']);
    $data       = date('d/m/Y', $ts);
    $hora       = date('H:i',   $ts);
    $diasPT     = ['domingo','segunda feira','terça feira','quarta feira','quinta feira','sexta feira','sábado'];
    $diaSemana  = $diasPT[(int)date('w', $ts)];

    $msg = str_replace(
        ['{nome}', '{data}', '{hora}', '{servico}', '{dia_semana}'],
        [$ag['NomeCliente'], $data, $hora, $ag['NomeServico'], $diaSemana],
        $msgTpl
    );

    $ok = enviarWhatsApp($telNorm, $msg);
    registrarLogWhatsApp($pdo, $telNorm, $msg, 'lembrete',
        $ok ? 'enviado' : 'erro', $ag['IDAgendamento']);

    if ($ok) {
        $telefoneNorm = $telNorm;

        // Marca agendamento como aguardando confirmação via IA
        $pdo->prepare(
            'UPDATE Agendamentos
             SET NotificacaoLembreteEnviada = 1, AguardandoConfirmacaoIA = 1
             WHERE IDAgendamento = :id'
        )->execute([':id' => $ag['IDAgendamento']]);

        // Encerra conversas anteriores deste telefone e cria nova aguardando confirmação
        $pdo->prepare(
            "UPDATE ConversasIA
             SET Estado = 'expirado'
             WHERE Telefone = :tel AND Estado NOT IN ('resolvido','expirado')"
        )->execute([':tel' => $telefoneNorm]);

        $historico = json_encode([
            ['role' => 'assistant', 'text' => $msg, 'ts' => date('c')],
        ], JSON_UNESCAPED_UNICODE);

        $pdo->prepare(
            "INSERT INTO ConversasIA
                 (IDConversa, Telefone, FKCliente, FKAgendamento,
                  Estado, Historico, UltimaMensagemEm)
             VALUES (:id, :tel, :fkc, :fka, 'aguardando_confirmacao', :h, NOW())"
        )->execute([
            ':id'  => gerarUuid(),
            ':tel' => $telefoneNorm,
            ':fkc' => $ag['IDCliente'],
            ':fka' => $ag['IDAgendamento'],
            ':h'   => $historico,
        ]);

        $enviados++;
        echo "[OK] Lembrete → {$ag['NomeCliente']} ({$data} {$hora})" . PHP_EOL;
    } else {
        $erros++;
        echo "[ERRO WA] {$ag['IDAgendamento']}" . PHP_EOL;
    }
}

echo "Concluído. Enviados: {$enviados} | Erros: {$erros}" . PHP_EOL;
