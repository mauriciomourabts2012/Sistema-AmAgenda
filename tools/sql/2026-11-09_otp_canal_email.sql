-- AmAgenda - OTP por e-mail (Etapa C2)
-- EXECUÇÃO MANUAL. Faça backup antes. Não foi executado por nenhuma ferramenta.
-- Compatível com o SMS atual: todas as colunas novas são nuláveis ou têm DEFAULT 'sms',
-- então o código atual continua funcionando antes e depois da execução.

-- Atenção: DDL no MySQL faz commit implícito; execute os 3 blocos em ordem.

-- 1) Contato de e-mail validado do cliente (posse comprovada por OTP).
ALTER TABLE cliente
  ADD COLUMN email_verificado_em DATETIME NULL AFTER email;

-- 2) Canal e destino do desafio. O slot ativo (slot_desafio_ativo) continua sendo por
--    chave_principal, logo SMS e e-mail compartilham o mesmo slot: nunca há dois códigos válidos.
ALTER TABLE otp_desafio
  ADD COLUMN canal ENUM('sms','email') NOT NULL DEFAULT 'sms' AFTER finalidade,
  ADD COLUMN destino_hash BINARY(32) NULL AFTER telefone_hash,
  ADD INDEX idx_otp_destino_criado (destino_hash, criado_em);

-- 3) Registro de envio aceita e-mail (sem telefone). SMS existente permanece igual.
--    ck_msg_final4 (regexp) já aceita NULL, pois a verificação de CHECK com NULL não falha.
ALTER TABLE mensagem_envio
  MODIFY telefone_final4 CHAR(4) NULL,
  ADD COLUMN destino_mascarado VARCHAR(120) NULL AFTER telefone_final4,
  ADD CONSTRAINT ck_msg_canal CHECK (canal IN ('sms','email')),
  ADD CONSTRAINT ck_msg_destino CHECK (
    (canal = 'sms' AND telefone_final4 IS NOT NULL)
    OR (canal = 'email' AND telefone_final4 IS NULL AND destino_mascarado IS NOT NULL)
  );

-- Verificação (somente leitura):
-- SHOW COLUMNS FROM otp_desafio; SHOW COLUMNS FROM mensagem_envio; SHOW COLUMNS FROM cliente LIKE 'email_verificado_em';

-- ROLLBACK manual, se necessário (somente se nenhuma linha 'email' foi gravada):
-- ALTER TABLE mensagem_envio DROP CONSTRAINT ck_msg_destino, DROP CONSTRAINT ck_msg_canal,
--   DROP COLUMN destino_mascarado, MODIFY telefone_final4 CHAR(4) NOT NULL;
-- ALTER TABLE otp_desafio DROP INDEX idx_otp_destino_criado, DROP COLUMN destino_hash, DROP COLUMN canal;
-- ALTER TABLE cliente DROP COLUMN email_verificado_em;
