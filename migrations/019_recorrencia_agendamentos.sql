-- Migration 019: suporte a séries recorrentes de agendamentos
ALTER TABLE Agendamentos
    ADD COLUMN GrupoRecorrencia  VARCHAR(36)       NULL AFTER IDAgendamento,
    ADD COLUMN OrdemRecorrencia  SMALLINT UNSIGNED NULL AFTER GrupoRecorrencia,
    ADD INDEX  idx_grupo_rec (GrupoRecorrencia);
