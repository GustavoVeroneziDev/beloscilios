-- Migration 007: Estende TipoMensagem em LogsWhatsApp para suportar webhook e novos tipos
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
        'webhook_incerto'
    ) NOT NULL;
