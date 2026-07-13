-- Migration 020: Atualiza templates de mensagens WhatsApp com tom da designer
INSERT INTO ConfiguracoesSistema (IDConfig, Chave, Valor)
VALUES

(UUID(), 'msg_confirmacao',
'Oiee, {nome}! 🎀
Seu horário está agendado para *{dia_semana}, {data} às {hora}*.

Por gentileza, venha com o rosto limpo, sem maquiagem, principalmente na região dos olhos (sem rímel, delineador ou creme). Assim garantimos um resultado incrível e maior durabilidade das extensões! ✨

Qualquer dúvida, estou à disposição! 💕'),

(UUID(), 'msg_lembrete',
'Oiee, bom dia {nome}! 💓
Confirmando o seu horário de *{servico}* para *{dia_semana}, {data} às {hora}*. 🗓️

Para garantir uma experiência confortável e um resultado impecável, siga essas orientações:
• Rosto limpo e sem maquiagem nos olhos;
• Caso use lente de contato, venha sem elas;
• Preferencialmente, venha sem acompanhantes para manter o ambiente calmo e focado em você.

Qualquer necessidade de reagendamento, me avise com antecedência. Será um prazer realçar ainda mais sua beleza! 🫶🏼'),

(UUID(), 'msg_followup',
'Foi um prazer te atender hoje, {nome}! 💜

Muito obrigada por confiar no meu trabalho. Espero que você tenha amado o resultado tanto quanto eu amei fazer! ✨

Lembre-se dos cuidados:
• 💜 Evitar calor excessivo (secador, banhos muito quentes...);
• 💜 Evitar esfregar os olhos;
• 💜 Escovar e higienizar os cílios diariamente.

Qualquer dúvida ou necessidade, estou aqui para te ajudar! 💕')

ON DUPLICATE KEY UPDATE Valor = VALUES(Valor);
