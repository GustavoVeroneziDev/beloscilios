<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../config/conexao.php';
exigirLogin('designer');

header('Content-Type: text/plain; charset=UTF-8');

echo "=== DEBUG CLIENTES ===\n\n";

// 1. Contagem bruta
try {
    $total = $pdo->query("SELECT COUNT(*) FROM Usuarios")->fetchColumn();
    echo "Total Usuarios (sem filtro): {$total}\n";
} catch (\Throwable $e) {
    echo "ERRO COUNT(*): " . $e->getMessage() . "\n";
}

// 2. Contagem com filtro
try {
    $total = $pdo->query("SELECT COUNT(*) FROM Usuarios WHERE NivelAcesso = 'cliente'")->fetchColumn();
    echo "Total clientes (NivelAcesso=cliente): {$total}\n";
} catch (\Throwable $e) {
    echo "ERRO COUNT clientes: " . $e->getMessage() . "\n";
}

// 3. Com Ativo
try {
    $total = $pdo->query("SELECT COUNT(*) FROM Usuarios WHERE NivelAcesso = 'cliente' AND Ativo = 1")->fetchColumn();
    echo "Total clientes ativos: {$total}\n";
} catch (\Throwable $e) {
    echo "ERRO COUNT ativos: " . $e->getMessage() . "\n";
}

// 4. Coluna Temporario existe?
try {
    $pdo->query("SELECT Temporario FROM Usuarios LIMIT 0");
    echo "Coluna Temporario: EXISTE\n";
} catch (\Throwable $e) {
    echo "Coluna Temporario: NAO EXISTE (" . $e->getMessage() . ")\n";
}

// 5. Query principal completa
try {
    $stmt = $pdo->query(
        "SELECT u.IDUsuario, u.Nome, u.Email, u.Telefone, u.MomentoRegistro, u.EmailVerificado,
                0 AS Temporario,
                COUNT(a.IDAgendamento) AS TotalAg,
                MAX(CASE WHEN a.DataHoraAgendamento <= NOW() AND a.Status != 'cancelado' THEN a.DataHoraAgendamento END) AS UltimoAtend,
                SUM(CASE WHEN a.DataHoraAgendamento > NOW() AND a.Status != 'cancelado' THEN 1 ELSE 0 END) AS ProximoAg
         FROM Usuarios u
         LEFT JOIN Agendamentos a ON a.FKCliente = u.IDUsuario
         WHERE u.NivelAcesso = 'cliente' AND u.Ativo = 1
         GROUP BY u.IDUsuario
         ORDER BY u.Nome ASC
         LIMIT 5"
    );
    $rows = $stmt->fetchAll();
    echo "Query principal: " . count($rows) . " linha(s)\n";
    foreach ($rows as $r) {
        echo "  - {$r['Nome']} | TotalAg={$r['TotalAg']} | Ultimo={$r['UltimoAtend']} | Proximo={$r['ProximoAg']}\n";
    }
} catch (\Throwable $e) {
    echo "ERRO query principal: " . $e->getMessage() . "\n";
}

// 6. Colunas da tabela Usuarios
echo "\nColunas de Usuarios:\n";
try {
    $cols = $pdo->query("SHOW COLUMNS FROM Usuarios")->fetchAll();
    foreach ($cols as $c) {
        echo "  {$c['Field']} ({$c['Type']})\n";
    }
} catch (\Throwable $e) {
    echo "ERRO SHOW COLUMNS: " . $e->getMessage() . "\n";
}

echo "\n=== FIM ===\n";
