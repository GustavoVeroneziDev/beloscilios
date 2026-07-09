-- 011_servicos_ediane.sql
-- Substitui os serviços placeholder pelos serviços reais da Ediane Lash Designer.
-- Durações estimadas: completo 120min (Fio a Fio 90min), manut. 15d 60min, manut. 20/21d 75min.

SET FOREIGN_KEY_CHECKS = 0;
DELETE FROM SubServicos;
DELETE FROM Servicos;
SET FOREIGN_KEY_CHECKS = 1;

-- ── Serviços principais ──────────────────────────────────────────────────────

INSERT INTO Servicos (IDServico, Nome, Descricao, Preco, DuracaoMinutos, Ordem) VALUES
('srv-ediane-0001', 'Volume Brasileiro',
 'Preenchimento equilibrado que une leveza e volume na medida certa.',
 120.00, 120, 1),

('srv-ediane-0002', 'Volume Luxo',
 'Alta densidade com fios ultrafinos, proporcionando efeito marcante e volumoso.',
 140.00, 120, 2),

('srv-ediane-0003', 'Volume Fox Marrom',
 'Aplicação fio a fio para um resultado natural e delicado.',
 150.00, 120, 3),

('srv-ediane-0004', 'Volume Fox Preto',
 'Leques estruturados que garantem volume definido e elegante.',
 150.00, 120, 4),

('srv-ediane-0005', 'Volume Gatinho',
 'Mistura do clássico com volume, criando um efeito moderno e texturizado.',
 135.00, 120, 5),

('srv-ediane-0006', 'Volume Brasileiro Marrom',
 'Alongamento com efeito puxado, trazendo um olhar mais liftado.',
 120.00, 120, 6),

('srv-ediane-0007', 'Volume Egípcio',
 'Curvatura dos fios naturais, realçando o olhar sem extensão.',
 140.00, 120, 7),

('srv-ediane-0008', 'Volume Wispy',
 'Efeito leve e texturizado com fios mais evidentes, proporcionando movimento e naturalidade ao olhar.',
 140.00, 120, 8),

('srv-ediane-0009', 'Wispy Marrom',
 'Efeito leve e texturizado com fios marrons, proporcionando um olhar suave, elegante e natural.',
 140.00, 120, 9),

('srv-ediane-0010', 'Volume Sirena',
 'Estilo moderno com picos estratégicos e fios destacados, criando um olhar marcante e glamouroso.',
 120.00, 120, 10),

('srv-ediane-0011', 'Brasileiro em Capping',
 'Volume brasileiro com técnica de capping, proporcionando maior durabilidade, melhor retenção e um preenchimento elegante.',
 135.00, 120, 11),

('srv-ediane-0012', 'Fio a Fio Clássico',
 'Aplicação de um fio sintético sobre cada cílio natural, garantindo alongamento e definição com efeito delicado e natural.',
 100.00, 90, 12),

('srv-ediane-0013', 'Pluma',
 'Efeito leve e delicado, com fios distribuídos de forma suave para um olhar elegante e natural.',
 140.00, 120, 13),

('srv-ediane-0014', 'Mega Fox',
 'Volume intenso com alongamento nos cantos externos, criando um olhar liftado, marcante e sofisticado.',
 160.00, 120, 14);

-- ── Manutenções (sub-serviços) ────────────────────────────────────────────────

INSERT INTO SubServicos (IDSubServico, FKServico, Nome, Preco, DuracaoMinutos) VALUES
-- Volume Brasileiro
('sub-ediane-0001', 'srv-ediane-0001', 'Manutenção 15 dias',  65.00, 60),
('sub-ediane-0002', 'srv-ediane-0001', 'Manutenção 21 dias',  80.00, 75),
-- Volume Luxo
('sub-ediane-0003', 'srv-ediane-0002', 'Manutenção 15 dias',  75.00, 60),
('sub-ediane-0004', 'srv-ediane-0002', 'Manutenção 21 dias',  90.00, 75),
-- Volume Fox Marrom
('sub-ediane-0005', 'srv-ediane-0003', 'Manutenção 15 dias',  75.00, 60),
('sub-ediane-0006', 'srv-ediane-0003', 'Manutenção 21 dias',  90.00, 75),
-- Volume Fox Preto
('sub-ediane-0007', 'srv-ediane-0004', 'Manutenção 15 dias',  75.00, 60),
('sub-ediane-0008', 'srv-ediane-0004', 'Manutenção 21 dias',  90.00, 75),
-- Volume Gatinho
('sub-ediane-0009', 'srv-ediane-0005', 'Manutenção 15 dias',  75.00, 60),
('sub-ediane-0010', 'srv-ediane-0005', 'Manutenção 21 dias',  90.00, 75),
-- Volume Brasileiro Marrom
('sub-ediane-0011', 'srv-ediane-0006', 'Manutenção 15 dias',  65.00, 60),
('sub-ediane-0012', 'srv-ediane-0006', 'Manutenção 21 dias',  80.00, 75),
-- Volume Egípcio
('sub-ediane-0013', 'srv-ediane-0007', 'Manutenção 15 dias',  75.00, 60),
('sub-ediane-0014', 'srv-ediane-0007', 'Manutenção 21 dias',  90.00, 75),
-- Volume Wispy
('sub-ediane-0015', 'srv-ediane-0008', 'Manutenção 15 dias',  80.00, 60),
('sub-ediane-0016', 'srv-ediane-0008', 'Manutenção 21 dias',  95.00, 75),
-- Wispy Marrom
('sub-ediane-0017', 'srv-ediane-0009', 'Manutenção 15 dias',  80.00, 60),
('sub-ediane-0018', 'srv-ediane-0009', 'Manutenção 21 dias',  95.00, 75),
-- Volume Sirena
('sub-ediane-0019', 'srv-ediane-0010', 'Manutenção 15 dias',  65.00, 60),
('sub-ediane-0020', 'srv-ediane-0010', 'Manutenção 21 dias',  80.00, 75),
-- Brasileiro em Capping (sem manutenção no catálogo)
-- Fio a Fio Clássico
('sub-ediane-0021', 'srv-ediane-0012', 'Manutenção 15 dias',  55.00, 60),
('sub-ediane-0022', 'srv-ediane-0012', 'Manutenção 20 dias',  70.00, 75),
-- Pluma
('sub-ediane-0023', 'srv-ediane-0013', 'Manutenção 15 dias',  75.00, 60),
('sub-ediane-0024', 'srv-ediane-0013', 'Manutenção 21 dias',  90.00, 75);
-- Mega Fox (sem manutenção no catálogo)
