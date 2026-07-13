<?php
/**
 * Cron: lembrete de atualização de ficha de anamnese.
 * Clientes cuja ficha não foi atualizada há 60+ dias recebem WhatsApp.
 * Executar: C:\xampp\php\php.exe cron\ficha_anamnese_lembrete.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso restrito.');
}

require_once __DIR__ . '/../config/conexao.php';

$msg_template = getConfig($pdo, 'msg_ficha_lembrete',
    'Olá, {nome}! 👋 Han já se passaram 2 meses desde que você preencheu sua ficha de saúde na Belos Cílios. '
    . 'Algumas informações podem ter mudado (medicamentos, condições de saúde, etc.). '
    . 'Por favor, acesse seu perfil e atualize sua ficha: {link}'
);

if (empty(trim($msg_template))) {
    echo "[AVISO] Template msg_ficha_lembrete vazio. Abortando.\n";
    exit(0);
}

$link = 'https://beloscilios.com.br/usuario/ficha_anamnese.php';

try {
    // Clientes com ficha preenchida há mais de 60 dias E que nunca receberam lembrete de ficha
    // ou cujo lembrete foi enviado há mais de 60 dias
    $stmt = $pdo->prepare(
        'SELECT u.IDUsuario, u.Nome, u.Telefone, fa.AtualizadoEm
         FROM FichaAnamnese fa
         JOIN Usuarios u ON u.IDUsuario = fa.FKCliente
         WHERE fa.AtualizadoEm < NOW() - INTERVAL 60 DAY
           AND u.Telefone IS NOT NULL
           AND u.Telefone != \'\'
           AND (
               fa.UltimoLembreteFicha IS NULL
               OR fa.UltimoLembreteFicha < NOW() - INTERVAL 60 DAY
           )'
    );
    $stmt->execute();
    $clientes = $stmt->fetchAll();
} catch (PDOException $e) {
    // Coluna UltimoLembreteFicha pode não existir ainda — fallback sem filtro de reenvio
    echo "[AVISO] Erro na query principal: " . $e->getMessage() . "\n";
    try {
        $stmt = $pdo->prepare(
            'SELECT u.IDUsuario, u.Nome, u.Telefone, fa.AtualizadoEm
             FROM FichaAnamnese fa
             JOIN Usuarios u ON u.IDUsuario = fa.FKCliente
             WHERE fa.AtualizadoEm < NOW() - INTERVAL 60 DAY
               AND u.Telefone IS NOT NULL
               AND u.Telefone != \'\''
        );
        $stmt->execute();
        $clientes = $stmt->fetchAll();
    } catch (PDOException $e2) {
        echo "[ERRO] " . $e2->getMessage() . "\n";
        exit(1);
    }
}

if (empty($clientes)) {
    echo "[OK] Nenhum cliente com ficha desatualizada encontrada.\n";
    exit(0);
}

echo "[INFO] " . count($clientes) . " cliente(s) com ficha desatualizada.\n";

$enviados  = 0;
$erros     = 0;
$ignorados = 0;

foreach ($clientes as $cl) {
    $tel = sanitizarTelefone($cl['Telefone']);
    if (!$tel) {
        echo "[IGNORADO] {$cl['Nome']} — telefone inválido: {$cl['Telefone']}\n";
        $ignorados++;
        continue;
    }

    $msg = str_replace(
        ['{nome}', '{link}'],
        [$cl['Nome'], $link],
        $msg_template
    );

    $ok = enviarWhatsApp($tel, $msg);
    if ($ok) {
        echo "[OK] Lembrete de ficha enviado para {$cl['Nome']} ({$tel})\n";
        // Tenta registrar o envio (coluna pode não existir)
        try {
            $pdo->prepare('UPDATE FichaAnamnese SET UltimoLembreteFicha = NOW() WHERE FKCliente = :id')
                ->execute([':id' => $cl['IDUsuario']]);
        } catch (PDOException) {}
        $enviados++;
    } else {
        echo "[ERRO] Falha ao enviar para {$cl['Nome']} ({$tel})\n";
        $erros++;
    }

    // Log
    try {
        $pdo->prepare(
            'INSERT INTO LogsWhatsApp (IDLog, FKUsuario, Tipo, Mensagem, Status, MomentoEnvio)
             VALUES (:id, :uid, :tipo, :msg, :st, NOW())'
        )->execute([
            ':id'  => gerarUuid(),
            ':uid' => $cl['IDUsuario'],
            ':tipo'=> 'ficha_lembrete',
            ':msg' => mb_substr($msg, 0, 1000),
            ':st'  => $ok ? 'enviado' : 'falha',
        ]);
    } catch (PDOException) {}

    sleep(1);
}

echo "[CONCLUÍDO] Enviados: {$enviados} | Erros: {$erros} | Ignorados: {$ignorados}\n";
