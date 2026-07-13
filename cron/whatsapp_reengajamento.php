<?php
/**
 * Cron: reengajamento mensal — clientes sem visita há ~30 dias sem agendamento futuro.
 * Executar 1x por dia (ex: 10h):
 *   0 10 * * * php /caminho/para/beloscilios/cron/whatsapp_reengajamento.php >> /logs/wa_reengajamento.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso restrito ao CLI.');
}

require_once __DIR__ . '/../config/conexao.php';

echo '[' . date('Y-m-d H:i:s') . '] Iniciando reengajamento...' . PHP_EOL;

$diasMin  = (int) getConfig($pdo, 'reengajamento_dias_min', '25');
$diasMax  = (int) getConfig($pdo, 'reengajamento_dias_max', '35');
$cooldown = (int) getConfig($pdo, 'reengajamento_cooldown_dias', '60');

try {
    // Clientes cujo último atendimento foi entre diasMin e diasMax atrás,
    // sem agendamentos futuros e sem reengajamento recente
    $stmt = $pdo->prepare(
        "SELECT u.IDUsuario, u.Nome, u.Telefone,
                MAX(a.DataHoraAgendamento) AS UltimaVisita
         FROM Usuarios u
         JOIN Agendamentos a ON a.FKCliente = u.IDUsuario
         WHERE u.NivelAcesso = 'cliente'
           AND u.Ativo = 1
           AND u.Telefone IS NOT NULL AND u.Telefone != ''
           AND u.Email NOT LIKE '%@avulso.internal'
           AND a.StatusAgendamento IN ('confirmado','concluido')
           AND a.DataHoraAgendamento < NOW()
         GROUP BY u.IDUsuario, u.Nome, u.Telefone
         HAVING UltimaVisita BETWEEN DATE_SUB(NOW(), INTERVAL :max DAY)
                                 AND DATE_SUB(NOW(), INTERVAL :min DAY)
           AND u.IDUsuario NOT IN (
               SELECT DISTINCT a2.FKCliente FROM Agendamentos a2
               WHERE a2.StatusAgendamento IN ('pendente','confirmado')
                 AND a2.DataHoraAgendamento >= NOW()
           )
           AND u.Telefone NOT IN (
               SELECT DISTINCT l.Telefone FROM LogsWhatsApp l
               WHERE l.TipoMensagem = 'reengajamento'
                 AND l.MomentoEnvio > DATE_SUB(NOW(), INTERVAL :cooldown DAY)
           )"
    );
    $stmt->execute([':min' => $diasMin, ':max' => $diasMax, ':cooldown' => $cooldown]);
    $clientes = $stmt->fetchAll();
} catch (PDOException $e) {
    echo '[ERRO BD] ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

if (empty($clientes)) {
    echo 'Nenhum cliente para reengajamento hoje.' . PHP_EOL;
    exit(0);
}

$msgTpl = getConfig($pdo, 'msg_reengajamento', '');
if (!$msgTpl) {
    echo '[AVISO] Template msg_reengajamento não configurado.' . PHP_EOL;
    exit(0);
}

$enviados = 0;
$erros    = 0;

foreach ($clientes as $cli) {
    $tel = sanitizarTelefone((string)($cli['Telefone'] ?? ''));
    if (!$tel) {
        echo "[SKIP] {$cli['IDUsuario']} — telefone inválido" . PHP_EOL;
        continue;
    }

    $primeiroNome = explode(' ', trim($cli['Nome']))[0];
    $msg          = str_replace('{nome}', $primeiroNome, $msgTpl);

    $ok = enviarWhatsApp($tel, $msg);
    registrarLogWhatsApp($pdo, $tel, $msg, 'reengajamento', $ok ? 'enviado' : 'erro', null);

    if ($ok) {
        $enviados++;
        echo "[OK] {$cli['Nome']} ({$tel}) — última visita: {$cli['UltimaVisita']}" . PHP_EOL;
    } else {
        $erros++;
        echo "[ERRO WA] {$cli['Nome']} ({$tel})" . PHP_EOL;
    }

    // Pequena pausa para não sobrecarregar a API
    usleep(500000); // 0.5s
}

echo PHP_EOL . "[FIM] Enviados: {$enviados} | Erros: {$erros}" . PHP_EOL;
