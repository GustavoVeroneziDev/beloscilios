<?php
/**
 * Cron: follow-up após o procedimento (agradecimento + status de pagamento).
 * Executar a cada hora:
 *   0 * * * * php /caminho/para/beloscilios/cron/whatsapp_followup.php >> /logs/wa_followup.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso restrito ao CLI.');
}

require_once __DIR__ . '/../config/conexao.php';

echo '[' . date('Y-m-d H:i:s') . '] Iniciando follow-ups...' . PHP_EOL;

// Agendamentos concluídos nas últimas 3h que ainda não receberam follow-up
$from = date('Y-m-d H:i:s', strtotime('-3 hours'));

try {
    $stmt = $pdo->prepare(
        'SELECT a.IDAgendamento, a.DataHoraAgendamento, a.StatusPagamento,
                u.Nome AS NomeCliente, u.Telefone,
                s.Nome AS NomeServico
         FROM Agendamentos a
         JOIN Usuarios u ON u.IDUsuario = a.FKCliente
         JOIN Servicos s ON s.IDServico = a.FKServico
         WHERE a.DataHoraFim BETWEEN :from AND NOW()
           AND a.StatusAgendamento = \'concluido\'
           AND a.NotificacaoFollowupEnviada = 0'
    );
    $stmt->execute([':from' => $from]);
    $agendamentos = $stmt->fetchAll();
} catch (PDOException $e) {
    echo '[ERRO BD] ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

if (empty($agendamentos)) {
    echo 'Nenhum follow-up pendente.' . PHP_EOL;
    exit(0);
}

$msgTpl   = getConfig($pdo, 'msg_followup', '');
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

    // Adicionar cobrança se pagamento pendente
    if ($ag['StatusPagamento'] === 'pendente') {
        $msg .= "\n\n💳 Seu pagamento ainda está pendente. Qualquer dúvida, nos avise!";
    }

    $ok = enviarWhatsApp($ag['Telefone'], $msg);
    registrarLogWhatsApp($pdo, $ag['Telefone'], $msg, 'followup',
        $ok ? 'enviado' : 'erro', $ag['IDAgendamento']);

    if ($ok) {
        $pdo->prepare(
            'UPDATE Agendamentos SET NotificacaoFollowupEnviada=1 WHERE IDAgendamento=:id'
        )->execute([':id' => $ag['IDAgendamento']]);
        $enviados++;
        echo "[OK] Follow-up → {$ag['NomeCliente']}" . PHP_EOL;
    } else {
        $erros++;
        echo "[ERRO WA] {$ag['IDAgendamento']}" . PHP_EOL;
    }
}

echo "Concluído. Enviados: {$enviados} | Erros: {$erros}" . PHP_EOL;
