-- ============================================================
-- Migration 003: Reservas temporárias de slot + Google OAuth
-- ============================================================

SET NAMES utf8mb4;

-- Reservas temporárias de horário (10 minutos)
CREATE TABLE IF NOT EXISTS ReservasTemporarias (
    IDReserva       VARCHAR(36)  NOT NULL,
    TokenSessao     VARCHAR(128) NOT NULL,
    FKServico       VARCHAR(36)  NOT NULL,
    FKSubServico    VARCHAR(36)  NULL,
    DataHoraSlot    DATETIME     NOT NULL,
    DataHoraFim     DATETIME     NOT NULL,
    DuracaoMinutos  INT          NOT NULL DEFAULT 60,
    ExpiraEm        DATETIME     NOT NULL,
    MomentoRegistro TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (IDReserva),
    KEY idx_slot   (DataHoraSlot, DataHoraFim),
    KEY idx_expiry (ExpiraEm),
    KEY idx_sessao (TokenSessao)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Google OAuth: adiciona coluna GoogleId e torna Senha nullable
ALTER TABLE Usuarios
    ADD COLUMN IF NOT EXISTS GoogleId VARCHAR(100) NULL UNIQUE AFTER AtualizadoEm,
    MODIFY COLUMN Senha VARCHAR(255) NULL;
