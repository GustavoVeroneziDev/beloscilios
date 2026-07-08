-- ============================================================
-- Migration 004: Verificação de e-mail
-- ============================================================

SET NAMES utf8mb4;

ALTER TABLE Usuarios
    ADD COLUMN EmailVerificado     TINYINT(1)   NOT NULL DEFAULT 0   AFTER Email,
    ADD COLUMN TokenVerificacao    VARCHAR(64)  NULL                  AFTER EmailVerificado,
    ADD COLUMN TokenVerificacaoExpira DATETIME  NULL                  AFTER TokenVerificacao;

-- Marca como verificado quem já veio do Google (já verificado pelo Google)
UPDATE Usuarios SET EmailVerificado = 1 WHERE GoogleId IS NOT NULL;
