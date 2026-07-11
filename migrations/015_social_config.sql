-- Migration 015: configurações de redes sociais + preenche telefone e Instagram

INSERT INTO ConfiguracoesSistema (IDConfig, Chave, Valor)
VALUES (UUID(), 'instagram_estudio', 'https://www.instagram.com/bellos.cilioss/')
ON DUPLICATE KEY UPDATE Valor = 'https://www.instagram.com/bellos.cilioss/';

-- Telefone com DDD (sanitizarTelefone() adiciona o 55 automaticamente)
INSERT INTO ConfiguracoesSistema (IDConfig, Chave, Valor)
VALUES (UUID(), 'telefone_estudio', '17997042069')
ON DUPLICATE KEY UPDATE Valor = '17997042069';
