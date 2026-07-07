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
        'SELECT a.IDAgendamento, a.DataHoraAgendamento,
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

foreach ($agendamentos as $ag) {
    if (!$ag['Telefone']) {
        echo "[SKIP] {$ag['IDAgendamento']} — sem telefone" . PHP_EOL;
        continue;
    }

    $data = date('d/m/Y', strtotime($ag['DataHoraAgendamento']));
    $hora = date('H:i',   strtotime($ag['DataHoraAgendamento']));

    $msg = str_replace(
        ['{nome}', '{data}', '{hora}', '{servico}'],
        [$ag['NomeCliente'], $data, $hora, $ag['NomeServico']],
        $msgTpl
    );

    $ok = enviarWhatsApp($ag['Telefone'], $msg);
    registrarLogWhatsApp($pdo, $ag['Telefone'], $msg, 'lembrete',
        $ok ? 'enviado' : 'erro', $ag['IDAgendamento']);

    if ($ok) {
        $pdo->prepare(
            'UPDATE Agendamentos SET NotificacaoLembreteEnviada=1 WHERE IDAgendamento=:id'
        )->execute([':id' => $ag['IDAgendamento']]);
        $enviados++;
        echo "[OK] Lembrete → {$ag['NomeCliente']} ({$data} {$hora})" . PHP_EOL;
    } else {
        $erros++;
        echo "[ERRO WA] {$ag['IDAgendamento']}" . PHP_EOL;
    }
}

echo "Concluído. Enviados: {$enviados} | Erros: {$erros}" . PHP_EOL;
