-- Tokens de redefinição de senha (expiram em 1h, uso único)
CREATE TABLE IF NOT EXISTS TokensResetSenha (
    IDToken   VARCHAR(36)  NOT NULL PRIMARY KEY,
    FKUsuario VARCHAR(36)  NOT NULL,
    TokenHash VARCHAR(64)  NOT NULL COMMENT 'SHA-256 do token plain',
    Expira    DATETIME     NOT NULL,
    CriadoEm DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (FKUsuario) REFERENCES Usuarios(IDUsuario) ON DELETE CASCADE,
    INDEX idx_reset_usuario (FKUsuario),
    INDEX idx_reset_expira  (Expira)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
