-- ============================================================
-- Belos Cílios — Migration 002: Dados iniciais
-- Senha padrão da designer: Belos@2025  (alterar no primeiro acesso)
-- ============================================================

SET NAMES utf8mb4;

-- Designer padrão
INSERT IGNORE INTO Usuarios (IDUsuario, Nome, Email, Telefone, Senha, NivelAcesso) VALUES
('00000000-0000-0000-0000-000000000001',
 'Designer',
 'designer@beloscilios.com.br',
 '5511999999999',
 '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi', -- senha: password (trocar!)
 'designer');

-- Serviços de exemplo
INSERT IGNORE INTO Servicos (IDServico, Nome, Descricao, Preco, DuracaoMinutos, Ordem) VALUES
('srv-0001-0000-0000-0000-000000000001', 'Lash Lifting',      'Elevação e curvatura natural dos cílios com efeito duradouro.',        150.00, 90,  1),
('srv-0001-0000-0000-0000-000000000002', 'Extensão de Cílios','Aplicação fio a fio para volume e comprimento personalizados.',         200.00, 120, 2),
('srv-0001-0000-0000-0000-000000000003', 'Design de Sobrancelhas','Modelagem com linha/cera + henna para realce natural.',             80.00,  60,  3),
('srv-0001-0000-0000-0000-000000000004', 'Brow Lamination',   'Alisamento e modelagem dos pelos das sobrancelhas por até 6 semanas.', 120.00, 75,  4);

-- Manutenções (sub-serviços)
INSERT IGNORE INTO SubServicos (IDSubServico, FKServico, Nome, Descricao, Preco, DuracaoMinutos) VALUES
('sub-0001-0000-0000-0000-000000000001','srv-0001-0000-0000-0000-000000000002','Manutenção Extensão 3 semanas','Reposição de fios após 3 semanas.',  100.00, 90),
('sub-0001-0000-0000-0000-000000000002','srv-0001-0000-0000-0000-000000000002','Manutenção Extensão 4 semanas','Reposição de fios após 4 semanas.',  120.00, 90),
('sub-0001-0000-0000-0000-000000000003','srv-0001-0000-0000-0000-000000000004','Manutenção Brow Lamination',  'Retoque do alisamento de sobrancelhas.', 70.00, 60);

-- Configurações padrão
INSERT IGNORE INTO ConfiguracoesSistema (IDConfig, Chave, Valor) VALUES
('cfg-0001','intervalo_minutos',      '15'),
('cfg-0002','antecedencia_minima_h',  '2'),
('cfg-0003','dias_agenda_futura',     '60'),
('cfg-0004','msg_confirmacao',        'Olá {nome}! ✨ Seu agendamento em *Belos Cílios* foi confirmado!\n\n📅 *{data}* às *{hora}*\n💆 Serviço: *{servico}*\n\nQualquer dúvida estamos por aqui. Até logo!'),
('cfg-0005','msg_lembrete',           'Olá {nome}! 🌸 Lembrando que amanhã você tem horário em *Belos Cílios*!\n\n📅 *{data}* às *{hora}*\n💆 Serviço: *{servico}*\n\nTe esperamos! ✨'),
('cfg-0006','msg_followup',           'Olá {nome}! 💕 Obrigada pela visita à *Belos Cílios* hoje!\nEsperamos que tenha adorado o resultado. 😍\n\nQualquer dúvida, estamos à disposição!'),
('cfg-0007','nome_estudio',           'Belos Cílios'),
('cfg-0008','telefone_estudio',       ''),
('cfg-0009','endereco_estudio',       '');

-- Horários de atendimento padrão (Seg a Sab, 9h–18h)
INSERT IGNORE INTO HorariosAtendimento (IDHorario, DiaSemana, HoraInicio, HoraFim) VALUES
('hor-0001','1','09:00','18:00'),
('hor-0002','2','09:00','18:00'),
('hor-0003','3','09:00','18:00'),
('hor-0004','4','09:00','18:00'),
('hor-0005','5','09:00','18:00'),
('hor-0006','6','09:00','17:00');
