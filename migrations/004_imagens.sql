-- Migration 004: tabela de imagens gerenciadas pelo painel
CREATE TABLE IF NOT EXISTS Imagens (
    IDImagem        VARCHAR(36)  NOT NULL PRIMARY KEY,
    NomeArquivo     VARCHAR(255) NOT NULL,
    TituloExibicao  VARCHAR(255) NULL,
    Categoria       ENUM('galeria','servico','espaco','outro') NOT NULL DEFAULT 'galeria',
    Largura         INT          NULL,
    Altura          INT          NULL,
    TamanhoBytes    BIGINT       NULL,
    MomentoRegistro DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
