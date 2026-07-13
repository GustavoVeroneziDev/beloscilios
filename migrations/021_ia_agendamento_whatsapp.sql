-- Migration 021: IA completa — agendamento via WhatsApp, boas-vindas, reengajamento

-- Novos estados no fluxo de conversa
ALTER TABLE ConversasIA
    MODIFY Estado ENUM(
        'aguardando_confirmacao',
        'aguardando_novo_horario',
        'aguardando_servico',
        'aguardando_data',
        'aguardando_horario',
        'aguardando_confirmacao_agendamento',
        'aguardando_cancelamento_escolha',
        'aguardando_reagendamento_escolha',
        'em_conversa',
        'resolvido',
        'expirado'
    ) NOT NULL DEFAULT 'em_conversa';

-- Dados de contexto do fluxo (serviço/data/hora em andamento)
ALTER TABLE ConversasIA
    ADD COLUMN DadosContexto JSON NULL AFTER Historico;

-- Novos tipos de log para boas-vindas e reengajamento
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
        'ia_consulta',
        'boas_vindas',
        'reengajamento'
    ) NOT NULL;

-- Templates de boas-vindas e reengajamento
INSERT INTO ConfiguracoesSistema (IDConfig, Chave, Valor)
VALUES (UUID(), 'msg_boas_vindas',
'Oiee, {nome}! 💜 Que bom ter você aqui no Belos Cílios!

Sou a Beli, assistente virtual da Thainá 🎀 Estou aqui para te ajudar com agendamentos, dúvidas sobre os serviços e muito mais!

Sempre que precisar marcar, cancelar ou tirar alguma dúvida sobre seus cílios, é só me chamar aqui! 😊

Até breve! 💕')
ON DUPLICATE KEY UPDATE Valor = VALUES(Valor);

INSERT INTO ConfiguracoesSistema (IDConfig, Chave, Valor)
VALUES (UUID(), 'msg_reengajamento',
'Oiee, {nome}! 💜 Saudades!

Já faz um tempinho que você não vem nos visitar... Como estão seus cílios? 😊

Que tal marcar um horário para renovar? Me diz quando é bom para você ou acesse o link de agendamento! 🎀

Te espero em breve! 💕')
ON DUPLICATE KEY UPDATE Valor = VALUES(Valor);
