-- Migration 013: Tabela de conversas IA via WhatsApp
-- Rastreia estado de cada conversa por telefone

CREATE TABLE IF NOT EXISTS ConversasIA (
    IDConversa     VARCHAR(36)  NOT NULL,
    Telefone       VARCHAR(20)  NOT NULL COMMENT 'Número normalizado sem código de país extra',
    FKCliente      VARCHAR(36)  NULL,
    FKAgendamento  VARCHAR(36)  NULL COMMENT 'Agendamento principal da conversa',
    Estado         ENUM(
                       'aguardando_confirmacao',
                       'aguardando_novo_horario',
                       'em_conversa',
                       'resolvido',
                       'expirado'
                   ) NOT NULL DEFAULT 'em_conversa',
    Historico      JSON         NULL COMMENT 'Array de {role, text, ts} para contexto da IA',
    UltimaMensagemEm DATETIME   NOT NULL,
    CriadoEm      TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (IDConversa),
    KEY idx_telefone (Telefone),
    KEY idx_estado   (Estado),
    KEY idx_ultima   (UltimaMensagemEm),
    CONSTRAINT fk_conv_cliente FOREIGN KEY (FKCliente)
        REFERENCES Usuarios(IDUsuario) ON DELETE SET NULL,
    CONSTRAINT fk_conv_agend  FOREIGN KEY (FKAgendamento)
        REFERENCES Agendamentos(IDAgendamento) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Adiciona coluna para marcar lembrete aguardando confirmação IA
ALTER TABLE Agendamentos
    ADD COLUMN AguardandoConfirmacaoIA TINYINT(1) NOT NULL DEFAULT 0
    AFTER NotificacaoFollowupEnviada;

-- Novos tipos de log para respostas da IA
ALTER TABLE LogsWhatsApp
    MODIFY TipoMensagem ENUM(
        'confirmacao',
        'lembrete',
        'followup',
        'manual',
        'cancelamento',
        'cobranca',
        'webhook_entrada',
        'webhook_confirmado',
        'webhook_cancelado',
        'webhook_incerto',
        'ia_resposta',
        'ia_confirmou',
        'ia_cancelou',
        'ia_reagendou',
        'ia_consulta'
    ) NOT NULL;
