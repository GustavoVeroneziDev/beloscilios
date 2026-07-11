-- Migration 014: controle de vitrine da home no painel
-- Permite que a designer escolha quais imagens aparecem na home,
-- em que ordem e com qual ponto de foco, sem tocar no código.

ALTER TABLE Imagens
    ADD COLUMN ExibirNaHome TINYINT(1)  NOT NULL DEFAULT 0          AFTER TituloExibicao,
    ADD COLUMN OrdemHome    TINYINT UNSIGNED NOT NULL DEFAULT 99     AFTER ExibirNaHome,
    ADD COLUMN FocoHome     VARCHAR(30) NOT NULL DEFAULT 'center center' AFTER OrdemHome;

CREATE INDEX idx_imagens_home ON Imagens (ExibirNaHome, OrdemHome);

-- Migra as imagens que já estavam hardcoded no index.php
UPDATE Imagens SET ExibirNaHome = 1, OrdemHome = 1, FocoHome = 'center 70%'   WHERE TituloExibicao = 'Wispy';
UPDATE Imagens SET ExibirNaHome = 1, OrdemHome = 2, FocoHome = 'center top'   WHERE TituloExibicao = 'Perfil';
UPDATE Imagens SET ExibirNaHome = 1, OrdemHome = 3, FocoHome = 'center top'   WHERE TituloExibicao = 'Perfil 2';
UPDATE Imagens SET ExibirNaHome = 1, OrdemHome = 4, FocoHome = 'center center' WHERE TituloExibicao = 'Ambiente';
UPDATE Imagens SET ExibirNaHome = 1, OrdemHome = 5, FocoHome = 'center 55%'   WHERE TituloExibicao = 'Fox Marrom';
