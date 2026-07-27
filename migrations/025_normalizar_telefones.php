<?php
/**
 * Migration 025 — Normaliza telefones sujos no banco.
 * Roda via CLI: php migrations/025_normalizar_telefones.php
 *
 * Lê todos os Usuarios com Telefone não nulo, passa por sanitizarTelefone()
 * e atualiza os que estiverem em formato errado (com +, espaços, traços etc.).
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Acesso negado.');
}

require_once __DIR__ . '/../config/conexao.php';

$stmt = $pdo->query(
    "SELECT IDUsuario, Telefone FROM Usuarios WHERE Telefone IS NOT NULL AND Telefone != ''"
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$atualizados = 0;
$nulos       = 0;
$iguais      = 0;

$upd = $pdo->prepare('UPDATE Usuarios SET Telefone = :tel WHERE IDUsuario = :id');

foreach ($rows as $row) {
    $normalizado = sanitizarTelefone($row['Telefone']);

    if ($normalizado === null) {
        // Número irreconhecível — deixa null para não bloquear WhatsApp com lixo
        if ($row['Telefone'] !== null) {
            $upd->execute([':tel' => null, ':id' => $row['IDUsuario']]);
            echo "NULO    [{$row['IDUsuario']}] \"{$row['Telefone']}\" → null\n";
            $nulos++;
        }
        continue;
    }

    if ($normalizado === $row['Telefone']) {
        $iguais++;
        continue;
    }

    $upd->execute([':tel' => $normalizado, ':id' => $row['IDUsuario']]);
    echo "OK      [{$row['IDUsuario']}] \"{$row['Telefone']}\" → \"{$normalizado}\"\n";
    $atualizados++;
}

echo "\n--- Resultado ---\n";
echo "Atualizados : $atualizados\n";
echo "Zerados     : $nulos\n";
echo "Já OK       : $iguais\n";
echo "Total       : " . count($rows) . "\n";
