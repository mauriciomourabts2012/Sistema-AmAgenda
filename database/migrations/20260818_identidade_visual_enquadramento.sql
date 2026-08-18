ALTER TABLE configuracao_geral_empresa
    ADD COLUMN imagem_login_escala SMALLINT UNSIGNED NOT NULL DEFAULT 100 AFTER imagem_login,
    ADD COLUMN imagem_login_pos_x SMALLINT NOT NULL DEFAULT 0 AFTER imagem_login_escala,
    ADD COLUMN imagem_login_pos_y SMALLINT NOT NULL DEFAULT 0 AFTER imagem_login_pos_x;
