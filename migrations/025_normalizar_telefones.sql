-- Migration 025: Normalizar telefones existentes para formato E.164 sem '+'
-- Exemplo esperado: 5517988316505 (13 dígitos, começa com 55)
--
-- Aplicar: mysql -u root beloscilios_dev < migrations/025_normalizar_telefones.sql

-- 1. Remove formatação suja: +, espaços, traços, parênteses
UPDATE Usuarios
SET Telefone = REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(
    Telefone, '+', ''), ' ', ''), '-', ''), '(', ''), ')', '')
WHERE Telefone IS NOT NULL
  AND Telefone REGEXP '[^0-9]';

-- 2. 11 dígitos sem prefixo 55 (DDD + 9 dígitos) → adiciona 55
UPDATE Usuarios
SET Telefone = CONCAT('55', Telefone)
WHERE Telefone IS NOT NULL
  AND LENGTH(Telefone) = 11
  AND Telefone NOT LIKE '55%';

-- 3. 10 dígitos (DDD + 8 dígitos, sem o 9) → adiciona 55 + insere 9 após o DDD
UPDATE Usuarios
SET Telefone = CONCAT('55', LEFT(Telefone, 2), '9', RIGHT(Telefone, 8))
WHERE Telefone IS NOT NULL
  AND LENGTH(Telefone) = 10;

-- 4. Zera qualquer telefone que não ficou no formato esperado (13 dígitos, começa com 55)
--    Melhor deixar NULL do que mandar mensagem para número errado
UPDATE Usuarios
SET Telefone = NULL
WHERE Telefone IS NOT NULL
  AND (LENGTH(Telefone) != 13 OR Telefone NOT LIKE '55%');
