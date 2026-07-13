-- Migration 018: Ficha de Anamnese para extensão de cílios
CREATE TABLE FichaAnamnese (
    IDFicha              VARCHAR(36)  NOT NULL,
    FKCliente            VARCHAR(36)  NOT NULL,

    -- Saúde reprodutiva
    Gravida              TINYINT(1)   NOT NULL DEFAULT 0,
    GravidaSemanas       TINYINT      NULL,
    Amamentando          TINYINT(1)   NOT NULL DEFAULT 0,

    -- Alergias e reações
    AlergiaAdesivo       TINYINT(1)   NOT NULL DEFAULT 0,
    AlergiaAdesivoDet    VARCHAR(500) NULL,
    AlergiaLatex         TINYINT(1)   NOT NULL DEFAULT 0,
    ReacaoAnterior       TINYINT(1)   NOT NULL DEFAULT 0,
    ReacaoAnteriorDet    VARCHAR(500) NULL,

    -- Condições oculares
    ProblemaOcular       TINYINT(1)   NOT NULL DEFAULT 0,
    ProblemaOcularDet    VARCHAR(500) NULL,
    UsaLentes            TINYINT(1)   NOT NULL DEFAULT 0,

    -- Saúde geral
    Tireoide             TINYINT(1)   NOT NULL DEFAULT 0,
    TireoideDet          VARCHAR(200) NULL,
    Diabetes             TINYINT(1)   NOT NULL DEFAULT 0,
    PressaoAlterada      TINYINT(1)   NOT NULL DEFAULT 0,

    -- Medicamentos e tratamentos
    UsaMedicamentos      TINYINT(1)   NOT NULL DEFAULT 0,
    MedicamentosDet      VARCHAR(500) NULL,
    QuimioRadio          TINYINT(1)   NOT NULL DEFAULT 0,
    Retinoide            TINYINT(1)   NOT NULL DEFAULT 0,

    -- Pele e comportamento
    CondicaoPele         TINYINT(1)   NOT NULL DEFAULT 0,
    CondicaoPeleDet      VARCHAR(500) NULL,
    Tricotilomania       TINYINT(1)   NOT NULL DEFAULT 0,

    Observacoes          TEXT         NULL,
    TermoConsentimento   TINYINT(1)   NOT NULL DEFAULT 0,

    DataPreenchimento    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    AtualizadoEm         DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

    PRIMARY KEY (IDFicha),
    UNIQUE KEY uq_ficha_cliente (FKCliente),
    CONSTRAINT fk_ficha_cliente FOREIGN KEY (FKCliente) REFERENCES Usuarios (IDUsuario) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
