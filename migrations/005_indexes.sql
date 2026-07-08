-- ============================================================
-- Migration 005: Índices de performance
-- ============================================================

SET NAMES utf8mb4;

-- Agendamentos: buscas por data (geração de slots, agenda semanal)
ALTER TABLE Agendamentos
    ADD INDEX IF NOT EXISTS idx_ag_data_status (DataHoraAgendamento, StatusAgendamento),
    ADD INDEX IF NOT EXISTS idx_ag_cliente     (FKCliente),
    ADD INDEX IF NOT EXISTS idx_ag_intervalo   (DataHoraAgendamento, DataHoraFim, StatusAgendamento);

-- Usuarios: busca por NivelAcesso (listagem de clientes no painel)
ALTER TABLE Usuarios
    ADD INDEX IF NOT EXISTS idx_usr_nivel_ativo (NivelAcesso, Ativo);

-- LogsWhatsApp: busca por agendamento
ALTER TABLE LogsWhatsApp
    ADD INDEX IF NOT EXISTS idx_log_ag (FKAgendamento);
