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

// Agendamentos concluídos nos últimos 7 dias que ainda não receberam follow-up.
// Janela de 7 dias (em vez de 3h) para sobreviver a paradas de servidor.
$from = date('Y-m-d H:i:s', strtotime('-7 days'));

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

if (!$msgTpl) {
    echo '[AVISO] Template msg_followup não configurado — nenhum follow-up enviado.' . PHP_EOL;
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

    // Adicionar cobrança se pagamento pendente
    if ($ag['StatusPagamento'] === 'pendente') {
        $msg .= "\n\n💳 Seu pagamento ainda está pendente. Qualquer dúvida, nos avise!";
    }

    $ok = enviarWhatsApp($telNorm, $msg);
    registrarLogWhatsApp($pdo, $telNorm, $msg, 'followup',
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
