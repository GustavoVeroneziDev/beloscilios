-- Migration 008: Semeia templates padrão para msg_cancelamento e msg_cobranca
INSERT INTO ConfiguracoesSistema (IDConfig, Chave, Valor)
VALUES
(UUID(), 'msg_cancelamento',
'Olá {nome}! 💜 Recebemos sua mensagem e cancelamos seu horário de *{servico}* do dia *{data}* às *{hora}*.

Sentimos muito não poder te receber dessa vez! Quando quiser remarcar é só chamar a gente. 🗓️

_Motivo identificado: "{mensagem_cliente}"_'),

(UUID(), 'msg_cobranca',
'Olá {nome}! 😊 Passando para lembrar que o pagamento referente ao serviço *{servico}* realizado em *{data}* no valor de *R$ {valor}* ainda consta como pendente.

Assim que puder, só nos avisar para confirmarmos! Qualquer dúvida estamos aqui. 💜

_Belos Cílios_')

ON DUPLICATE KEY UPDATE Valor = VALUES(Valor);
