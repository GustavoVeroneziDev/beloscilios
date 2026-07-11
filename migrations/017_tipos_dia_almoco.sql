-- Migration 017: adiciona intervalo de almoço aos tipos de dia

ALTER TABLE TiposDia
    ADD COLUMN AlmocoInicio TIME NULL AFTER HoraFim,
    ADD COLUMN AlmocoFim    TIME NULL AFTER AlmocoInicio;
