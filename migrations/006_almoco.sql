-- Adiciona suporte a horário de almoço por dia da semana
ALTER TABLE HorariosAtendimento
    ADD COLUMN AlmocoInicio TIME NULL DEFAULT NULL AFTER HoraFim,
    ADD COLUMN AlmocoFim    TIME NULL DEFAULT NULL AFTER AlmocoInicio;
