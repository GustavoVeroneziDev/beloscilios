-- 012_categorias_galeria.sql
-- Substitui o ENUM fixo de Imagens.Categoria por uma tabela dinâmica de categorias.

-- 1. Tabela de categorias
CREATE TABLE IF NOT EXISTS CategoriasGaleria (
    IDCategoria     VARCHAR(36)  NOT NULL,
    Nome            VARCHAR(100) NOT NULL,
    Ordem           INT          NOT NULL DEFAULT 0,
    MomentoRegistro TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (IDCategoria)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. Categorias padrão
INSERT IGNORE INTO CategoriasGaleria (IDCategoria, Nome, Ordem) VALUES
('cat-lashes',       'Lashes',       1),
('cat-servicos',     'Serviços',     2),
('cat-espaco',       'Espaço',       3),
('cat-pessoal',      'Pessoal',      4),
('cat-ferramentas',  'Ferramentas',  5),
('cat-outros',       'Outros',       6);

-- 3. Muda Imagens.Categoria de ENUM para VARCHAR(36)
ALTER TABLE Imagens
    MODIFY COLUMN Categoria VARCHAR(36) NOT NULL DEFAULT 'cat-outros';

-- 4. Mapeia valores legados para as novas categorias
UPDATE Imagens SET Categoria = 'cat-servicos'    WHERE Categoria = 'servico';
UPDATE Imagens SET Categoria = 'cat-espaco'      WHERE Categoria = 'espaco';
UPDATE Imagens SET Categoria = 'cat-lashes'      WHERE Categoria = 'galeria';
UPDATE Imagens SET Categoria = 'cat-outros'      WHERE Categoria = 'outro';
-- Qualquer valor não reconhecido cai em 'cat-outros'
UPDATE Imagens SET Categoria = 'cat-outros'
    WHERE Categoria NOT IN (SELECT IDCategoria FROM CategoriasGaleria);
