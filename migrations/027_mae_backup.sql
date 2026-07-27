-- Migration 027: coluna TemMaeBackup em Imagens
-- Indica que o arquivo original foi copiado para geral/img/galeria_mae/
-- permitindo restauração após qualquer recorte ou tratamento.
SET NAMES utf8mb4;

ALTER TABLE Imagens
    ADD COLUMN TemMaeBackup TINYINT(1) NOT NULL DEFAULT 0
        COMMENT '1 = backup do original existe em geral/img/galeria_mae/'
    AFTER TamanhoBytes;
