-- Migration 024: múltiplos intervalos por tipo de dia (academia, almoço, etc.)

CREATE TABLE IntervalosTipo (
    IDIntervalo     VARCHAR(36)  NOT NULL,
    FKTipo          VARCHAR(36)  NOT NULL,
    Nome            VARCHAR(60)  NOT NULL DEFAULT 'Intervalo',
    Inicio          TIME         NOT NULL,
    Fim             TIME         NOT NULL,
    MomentoRegistro DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (IDIntervalo),
    INDEX idx_fktipo (FKTipo)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Migra dados de almoço já existentes em TiposDia para a nova tabela
INSERT INTO IntervalosTipo (IDIntervalo, FKTipo, Nome, Inicio, Fim)
SELECT UUID(), IDTipo, 'Almoço', AlmocoInicio, AlmocoFim
FROM TiposDia
WHERE AlmocoInicio IS NOT NULL AND AlmocoFim IS NOT NULL;
