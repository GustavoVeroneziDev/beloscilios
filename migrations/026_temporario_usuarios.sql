-- Migration 026: Suporte a usuárias temporárias (avulsas que mesclem ao se cadastrar)
-- Fluxo: designer cria avulsa → Temporario=1; cliente se cadastra pelo mesmo telefone
--        → processa_cadastro.php reassocia agendamentos e apaga a temporária.
SET NAMES utf8mb4;

-- 1. Coluna Temporario
ALTER TABLE Usuarios
    ADD COLUMN Temporario TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = criada automaticamente para avulsa; mescla ao cadastrar pelo mesmo fone'
    AFTER NivelAcesso;

-- 2. Índice composto para: (a) deduplicar avulsas no salvar_agendamento,
--    (b) busca rápida no merge do processa_cadastro
ALTER TABLE Usuarios
    ADD INDEX idx_temporario_tel (Temporario, Telefone(20));

-- 3. Retroativamente marca avulsas já existentes
--    (e-mail fictício gerado por salvar_agendamento.php)
UPDATE Usuarios
SET Temporario = 1
WHERE Email LIKE '%@avulso.internal';
