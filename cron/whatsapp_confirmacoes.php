<?php
/**
 * Cron: dispara confirmação de WhatsApp para agendamentos novos que ainda não foram notificados.
 * Executar a cada 5 minutos:
 *   *\/5 * * * * php /caminho/para/beloscilios/cron/whatsapp_confirmacoes.php >> /logs/wa_confirmacoes.log 2>&1
 */

// Bloquear acesso via browser
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso restrito ao CLI.');
}

require_once __DIR__ . '/../config/conexao.php';

echo '[' . date('Y-m-d H:i:s') . '] Iniciando confirmações...' . PHP_EOL;

try {
    $stmt = $pdo->query(
        'SELECT a.IDAgendamento, a.DataHoraAgendamento, a.ValorCobrado,
                u.Nome AS NomeCliente, u.Telefone,
                s.Nome AS NomeServico
         FROM Agendamentos a
         JOIN Usuarios u ON u.IDUsuario = a.FKCliente
         JOIN Servicos s ON s.IDServico = a.FKServico
         WHERE a.NotificacaoConfirmacaoEnviada = 0
           AND a.StatusAgendamento IN (\'pendente\',\'confirmado\')
           AND a.DataHoraAgendamento > NOW()
         LIMIT 50'
    );
    $pendentes = $stmt->fetchAll();
} catch (PDOException $e) {
    echo '[ERRO BD] ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

if (empty($pendentes)) {
    echo 'Nenhuma confirmação pendente.' . PHP_EOL;
    exit(0);
}

$msgTpl = getConfig($pdo, 'msg_confirmacao', '');
$enviados = 0;
$erros    = 0;

foreach ($pendentes as $ag) {
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
    registrarLogWhatsApp($pdo, $ag['Telefone'], $msg, 'confirmacao',
        $ok ? 'enviado' : 'erro', $ag['IDAgendamento']);

    if ($ok) {
        $pdo->prepare(
            'UPDATE Agendamentos SET NotificacaoConfirmacaoEnviada=1 WHERE IDAgendamento=:id'
        )->execute([':id' => $ag['IDAgendamento']]);
        $enviados++;
        echo "[OK] {$ag['NomeCliente']} — {$data} {$hora}" . PHP_EOL;
    } else {
        $erros++;
        echo "[ERRO WA] {$ag['IDAgendamento']}" . PHP_EOL;
    }
}

echo "Concluído. Enviados: {$enviados} | Erros: {$erros}" . PHP_EOL;
