-- Migration 019: suporte a séries recorrentes de tipos de dia
ALTER TABLE DiasEspeciais
    ADD COLUMN GrupoRecorrencia  VARCHAR(36)       NULL AFTER FKTipo,
    ADD COLUMN OrdemRecorrencia  SMALLINT UNSIGNED NULL AFTER GrupoRecorrencia,
    ADD INDEX  idx_de_grupo_rec (GrupoRecorrencia);
