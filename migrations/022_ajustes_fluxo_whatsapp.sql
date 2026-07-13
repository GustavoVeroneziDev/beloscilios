-- Migration 022: ajustes no fluxo de mensagens WhatsApp
-- 48h antes em vez de 24h, aviso no dia, notificação da designer, {valor} na confirmação

-- Flag para aviso no dia do atendimento
ALTER TABLE Agendamentos
    ADD COLUMN NotificacaoAvisoDiaEnviada TINYINT(1) NOT NULL DEFAULT 0
    AFTER AguardandoConfirmacaoIA;

-- Novo tipo de log para aviso do dia
ALTER TABLE LogsWhatsApp
    MODIFY TipoMensagem ENUM(
        'confirmacao',
        'lembrete',
        'aviso_dia',
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
        'reengajamento',
        'alerta_designer'
    ) NOT NULL;

-- Número pessoal da designer para alertas de clientes confusas
INSERT INTO ConfiguracoesSistema (IDConfig, Chave, Valor)
VALUES (UUID(), 'numero_alerta_designer', '5517996125943')
ON DUPLICATE KEY UPDATE Valor = '5517996125943';

-- Atualiza template de confirmação com {valor}
UPDATE ConfiguracoesSistema SET Valor =
'Oiee, {nome}! 🎀
Seu horário está confirmado para *{dia_semana}, {data} às {hora}*.
📌 *{servico}* — {valor}

Por gentileza, venha com o rosto limpo, sem maquiagem na região dos olhos (sem rímel, delineador ou creme). Assim garantimos um resultado incrível e maior durabilidade! ✨

Qualquer dúvida, estou à disposição! 💕'
WHERE Chave = 'msg_confirmacao';

-- Atualiza template de lembrete para 48h + pede confirmação
UPDATE ConfiguracoesSistema SET Valor =
'Oiee, bom dia {nome}! 💓
Passando para lembrar do seu horário de *{servico}* para *{dia_semana}, {data} às {hora}*. 🗓️

Para garantir um resultado incrível:
• Rosto limpo e sem maquiagem nos olhos
• Caso use lente de contato, venha sem elas
• Preferencialmente sem acompanhantes, para manter o ambiente calmo e focado em você 🫶🏼

Você *confirma* a presença? Responda *SIM* para confirmar ou *NÃO* caso precise cancelar 💜'
WHERE Chave = 'msg_lembrete';

-- Template de aviso no dia do atendimento
INSERT INTO ConfiguracoesSistema (IDConfig, Chave, Valor)
VALUES (UUID(), 'msg_aviso_dia',
'Bom dia, {nome}! ☀️💜

Você tem *{servico}* agendado para *hoje às {hora}*! Estou animada para te atender 🎀

Lembrando os cuidados antes de vir:
• Rosto limpo e sem maquiagem nos olhos (sem rímel, delineador ou creme)
• Se usar lente de contato, venha sem elas
• Preferencialmente sem acompanhantes

Qualquer dúvida é só me chamar! Até mais tarde! 💕')
ON DUPLICATE KEY UPDATE Valor = VALUES(Valor);
