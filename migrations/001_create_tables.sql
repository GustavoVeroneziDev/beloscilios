-- ============================================================
-- Belos Cílios — Migration 001: Criação das tabelas
-- ============================================================

SET NAMES utf8mb4;
SET foreign_key_checks = 0;

-- ------------------------------------------------------------
-- Usuarios (clientes + designers)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Usuarios (
    IDUsuario       VARCHAR(36)  NOT NULL,
    Nome            VARCHAR(100) NOT NULL,
    Email           VARCHAR(150) NOT NULL,
    Telefone        VARCHAR(20)  NULL,
    Senha           VARCHAR(255) NOT NULL,
    NivelAcesso     ENUM('cliente','designer') NOT NULL DEFAULT 'cliente',
    Ativo           TINYINT(1)   NOT NULL DEFAULT 1,
    MomentoRegistro TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    AtualizadoEm   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (IDUsuario),
    UNIQUE KEY uq_email (Email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Servicos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Servicos (
    IDServico       VARCHAR(36)    NOT NULL,
    Nome            VARCHAR(100)   NOT NULL,
    Descricao       TEXT           NULL,
    Preco           DECIMAL(10,2)  NOT NULL DEFAULT 0.00,
    DuracaoMinutos  INT            NOT NULL DEFAULT 60,
    FotoUrl         VARCHAR(500)   NULL,
    Ordem           INT            NOT NULL DEFAULT 0,
    Ativo           TINYINT(1)     NOT NULL DEFAULT 1,
    MomentoRegistro TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP,
    AtualizadoEm   TIMESTAMP      NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (IDServico)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- SubServicos (manutenções / serviços filhos)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS SubServicos (
    IDSubServico    VARCHAR(36)   NOT NULL,
    FKServico       VARCHAR(36)   NOT NULL,
    Nome            VARCHAR(100)  NOT NULL,
    Descricao       TEXT          NULL,
    Preco           DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    DuracaoMinutos  INT           NOT NULL DEFAULT 60,
    Ativo           TINYINT(1)    NOT NULL DEFAULT 1,
    MomentoRegistro TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    AtualizadoEm   TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (IDSubServico),
    CONSTRAINT fk_ss_servico FOREIGN KEY (FKServico) REFERENCES Servicos(IDServico) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- ConfiguracoesSistema (chave/valor)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ConfiguracoesSistema (
    IDConfig        VARCHAR(36)  NOT NULL,
    Chave           VARCHAR(100) NOT NULL,
    Valor           TEXT         NULL,
    MomentoRegistro TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    AtualizadoEm   TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (IDConfig),
    UNIQUE KEY uq_chave (Chave)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- HorariosAtendimento (grade semanal de funcionamento)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS HorariosAtendimento (
    IDHorario       VARCHAR(36) NOT NULL,
    DiaSemana       TINYINT(1)  NOT NULL COMMENT '0=dom 1=seg 2=ter 3=qua 4=qui 5=sex 6=sab',
    HoraInicio      TIME        NOT NULL,
    HoraFim         TIME        NOT NULL,
    Ativo           TINYINT(1)  NOT NULL DEFAULT 1,
    MomentoRegistro TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (IDHorario)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- BloqueiosAgenda (folgas, pausas, feriados)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS BloqueiosAgenda (
    IDBloqueio      VARCHAR(36)  NOT NULL,
    DataInicio      DATETIME     NOT NULL,
    DataFim         DATETIME     NOT NULL,
    Motivo          VARCHAR(255) NULL,
    MomentoRegistro TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (IDBloqueio)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- Agendamentos
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS Agendamentos (
    IDAgendamento                  VARCHAR(36)   NOT NULL,
    FKCliente                      VARCHAR(36)   NOT NULL,
    FKServico                      VARCHAR(36)   NOT NULL,
    FKSubServico                   VARCHAR(36)   NULL,
    DataHoraAgendamento            DATETIME      NOT NULL,
    DataHoraFim                    DATETIME      NOT NULL,
    StatusAgendamento              ENUM('pendente','confirmado','cancelado','concluido') NOT NULL DEFAULT 'pendente',
    Observacoes                    TEXT          NULL,
    ValorCobrado                   DECIMAL(10,2) NULL,
    StatusPagamento                ENUM('pendente','pago','cancelado') NOT NULL DEFAULT 'pendente',
    NotificacaoConfirmacaoEnviada  TINYINT(1)    NOT NULL DEFAULT 0,
    NotificacaoLembreteEnviada     TINYINT(1)    NOT NULL DEFAULT 0,
    NotificacaoFollowupEnviada     TINYINT(1)    NOT NULL DEFAULT 0,
    MomentoRegistro                TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    AtualizadoEm                  TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (IDAgendamento),
    CONSTRAINT fk_ag_cliente   FOREIGN KEY (FKCliente)    REFERENCES Usuarios(IDUsuario),
    CONSTRAINT fk_ag_servico   FOREIGN KEY (FKServico)    REFERENCES Servicos(IDServico),
    CONSTRAINT fk_ag_subserv   FOREIGN KEY (FKSubServico) REFERENCES SubServicos(IDSubServico) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ------------------------------------------------------------
-- LogsWhatsApp
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS LogsWhatsApp (
    IDLog           VARCHAR(36) NOT NULL,
    FKAgendamento   VARCHAR(36) NULL,
    Numero          VARCHAR(20) NOT NULL,
    Mensagem        TEXT        NOT NULL,
    TipoMensagem    ENUM('confirmacao','lembrete','followup','manual') NOT NULL,
    StatusEnvio     ENUM('pendente','enviado','erro') NOT NULL DEFAULT 'pendente',
    MomentoRegistro TIMESTAMP   NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (IDLog),
    CONSTRAINT fk_log_ag FOREIGN KEY (FKAgendamento) REFERENCES Agendamentos(IDAgendamento) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET foreign_key_checks = 1;
