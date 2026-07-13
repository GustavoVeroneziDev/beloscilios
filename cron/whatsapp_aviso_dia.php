<?php
/**
 * Cron: aviso no dia do atendimento com cuidados pré-procedimento.
 * Executar todos os dias às 7h:
 *   0 7 * * * php /caminho/para/beloscilios/cron/whatsapp_aviso_dia.php >> /logs/wa_aviso_dia.log 2>&1
 */

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso restrito ao CLI.');
}

require_once __DIR__ . '/../config/conexao.php';

echo '[' . date('Y-m-d H:i:s') . '] Iniciando aviso do dia...' . PHP_EOL;

// Agendamentos de hoje que ainda não receberam aviso
$hoje      = date('Y-m-d');
$amanha    = date('Y-m-d', strtotime('+1 day'));

try {
    $stmt = $pdo->prepare(
        "SELECT a.IDAgendamento, a.DataHoraAgendamento,
                u.Nome AS NomeCliente, u.Telefone,
                s.Nome AS NomeServico,
                COALESCE(ss.Nome, s.Nome) AS NomeServicoCompleto
         FROM Agendamentos a
         JOIN Usuarios u ON u.IDUsuario = a.FKCliente
         JOIN Servicos s ON s.IDServico = a.FKServico
         LEFT JOIN SubServicos ss ON ss.IDSubServico = a.FKSubServico
         WHERE a.DataHoraAgendamento >= :hoje
           AND a.DataHoraAgendamento < :amanha
           AND a.StatusAgendamento IN ('pendente','confirmado')
           AND a.NotificacaoAvisoDiaEnviada = 0"
    );
    $stmt->execute([':hoje' => $hoje, ':amanha' => $amanha]);
    $agendamentos = $stmt->fetchAll();
} catch (PDOException $e) {
    echo '[ERRO BD] ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

if (empty($agendamentos)) {
    echo 'Nenhum aviso do dia a enviar.' . PHP_EOL;
    exit(0);
}

$msgTpl = getConfig($pdo, 'msg_aviso_dia', '');
if (!$msgTpl) {
    echo '[AVISO] Template msg_aviso_dia não configurado.' . PHP_EOL;
    exit(0);
}

$enviados = 0;
$erros    = 0;

foreach ($agendamentos as $ag) {
    $tel = sanitizarTelefone((string)($ag['Telefone'] ?? ''));
    if (!$tel) {
        echo "[SKIP] {$ag['IDAgendamento']} — telefone inválido" . PHP_EOL;
        continue;
    }

    $ts           = strtotime($ag['DataHoraAgendamento']);
    $hora         = date('H:i', $ts);
    $primeiroNome = explode(' ', trim($ag['NomeCliente']))[0];

    $msg = str_replace(
        ['{nome}', '{hora}', '{servico}'],
        [$primeiroNome, $hora, $ag['NomeServicoCompleto']],
        $msgTpl
    );

    $ok = enviarWhatsApp($tel, $msg);
    registrarLogWhatsApp($pdo, $tel, $msg, 'aviso_dia', $ok ? 'enviado' : 'erro', $ag['IDAgendamento']);

    if ($ok) {
        $pdo->prepare('UPDATE Agendamentos SET NotificacaoAvisoDiaEnviada=1 WHERE IDAgendamento=:id')
            ->execute([':id' => $ag['IDAgendamento']]);
        $enviados++;
        echo "[OK] {$ag['NomeCliente']} — hoje às {$hora}" . PHP_EOL;
    } else {
        $erros++;
        echo "[ERRO WA] {$ag['IDAgendamento']}" . PHP_EOL;
    }
}

echo PHP_EOL . "[FIM] Enviados: {$enviados} | Erros: {$erros}" . PHP_EOL;
