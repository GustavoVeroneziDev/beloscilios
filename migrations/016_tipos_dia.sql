-- Migration 016: tipos de dia e dias especiais (folga rotacional)

CREATE TABLE TiposDia (
    IDTipo          VARCHAR(36)  NOT NULL,
    Nome            VARCHAR(60)  NOT NULL,
    Cor             VARCHAR(7)   NOT NULL DEFAULT '#6c757d',
    BloqueiaTotal   TINYINT(1)   NOT NULL DEFAULT 0,
    HoraInicio      TIME         NULL,
    HoraFim         TIME         NULL,
    MomentoRegistro DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (IDTipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE DiasEspeciais (
    IDDiaEspecial   VARCHAR(36)  NOT NULL,
    Data            DATE         NOT NULL,
    FKTipo          VARCHAR(36)  NOT NULL,
    MomentoRegistro DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (IDDiaEspecial),
    UNIQUE  KEY uq_diasep_data (Data),
    INDEX         idx_diasep_data (Data),
    CONSTRAINT fk_diasep_tipo FOREIGN KEY (FKTipo) REFERENCES TiposDia(IDTipo) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed: tipo padrão já pronto pra usar
INSERT INTO TiposDia (IDTipo, Nome, Cor, BloqueiaTotal, HoraInicio, HoraFim)
VALUES (UUID(), 'Folga Usina', '#ef4444', 1, NULL, NULL);
