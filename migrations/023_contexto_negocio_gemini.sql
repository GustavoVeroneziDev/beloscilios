-- Migration 023: contexto permanente do negócio para o prompt do Gemini
-- Permite que a Thainá descreva o estúdio e o Gemini use isso para responder dúvidas

INSERT INTO ConfiguracoesSistema (IDConfig, Chave, Valor)
VALUES (
    UUID(),
    'contexto_negocio',
    'O Belos Cílios é um estúdio especializado em extensão de cílios localizado em Votuporanga-SP, comandado pela Thainá.

Trabalhamos com técnicas de extensão de cílios de alta qualidade, usando materiais premium e técnicas que respeitam a saúde dos cílios naturais.

COMO FUNCIONA O AGENDAMENTO:
- O agendamento é feito direto pelo WhatsApp ou pelo site beloscilios.com
- A cliente escolhe o serviço, o dia e o horário disponível
- Recebe confirmação instantânea
- 48h antes do atendimento recebe um lembrete com instruções de preparo

CUIDADOS ANTES DO ATENDIMENTO:
- Venha com o rosto limpo, sem maquiagem na região dos olhos (sem rímel, delineador ou creme)
- Se usar lente de contato, venha sem elas no dia
- Preferencialmente sem acompanhantes, para manter o ambiente calmo e focado em você

POLÍTICA DE CANCELAMENTO:
- Cancelamentos podem ser feitos até com antecedência pelo WhatsApp
- Para remarcar, basta avisar e escolhemos um novo horário juntas

OUTROS:
- Atendimento individual e personalizado
- Ambiente aconchegante e climatizado
- Pagamento: consultar formas disponíveis com a Thainá'
)
ON DUPLICATE KEY UPDATE Valor = VALUES(Valor);
