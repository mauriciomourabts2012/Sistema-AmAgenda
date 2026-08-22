-- Sistema AmAgenda - permissões personalizadas por usuário
-- Preparado em 2026-08-21. EXECUÇÃO MANUAL pelo responsável pelo banco.
-- Este script não cria registros de permissões: a ausência de linha significa
-- que o usuário segue o padrão do perfil.

CREATE TABLE `usuario_permissao` (
  `id_usuario_permissao` bigint unsigned NOT NULL AUTO_INCREMENT,
  `id_empresa` int unsigned NOT NULL,
  `id_usuario` bigint unsigned NOT NULL,
  `codigo_permissao` varchar(100) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `estado` enum('permitido','bloqueado') COLLATE utf8mb4_unicode_ci NOT NULL,
  `alterado_por` bigint unsigned DEFAULT NULL,
  `criado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `atualizado_em` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id_usuario_permissao`),
  UNIQUE KEY `uq_usuario_permissao_empresa_usuario_codigo` (`id_empresa`,`id_usuario`,`codigo_permissao`),
  KEY `idx_usuario_permissao_codigo` (`id_empresa`,`codigo_permissao`,`estado`),
  KEY `idx_usuario_permissao_alterado_por` (`alterado_por`),
  CONSTRAINT `fk_usuario_permissao_empresa`
    FOREIGN KEY (`id_empresa`) REFERENCES `empresa` (`id_empresa`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_usuario_permissao_vinculo`
    FOREIGN KEY (`id_empresa`,`id_usuario`)
    REFERENCES `empresa_usuario` (`id_empresa`,`id_usuario`)
    ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_usuario_permissao_alterado_por`
    FOREIGN KEY (`alterado_por`) REFERENCES `usuario` (`id_usuario`)
    ON DELETE SET NULL ON UPDATE CASCADE,
  CONSTRAINT `chk_usuario_permissao_codigo`
    CHECK (`codigo_permissao` REGEXP '^[a-z][a-z0-9_]*(\\.[a-z][a-z0-9_]*)+$')
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci
  COMMENT='Exceções permitidas ou bloqueadas sobre as permissões padrão do perfil';

-- Validações somente de leitura para executar depois do CREATE TABLE.
SHOW CREATE TABLE `usuario_permissao`;
SHOW INDEX FROM `usuario_permissao`;

SELECT
  `CONSTRAINT_NAME`,
  `CONSTRAINT_TYPE`
FROM `information_schema`.`TABLE_CONSTRAINTS`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'usuario_permissao'
ORDER BY `CONSTRAINT_TYPE`, `CONSTRAINT_NAME`;

SELECT
  `CONSTRAINT_NAME`,
  `COLUMN_NAME`,
  `REFERENCED_TABLE_NAME`,
  `REFERENCED_COLUMN_NAME`
FROM `information_schema`.`KEY_COLUMN_USAGE`
WHERE `TABLE_SCHEMA` = DATABASE()
  AND `TABLE_NAME` = 'usuario_permissao'
  AND `REFERENCED_TABLE_NAME` IS NOT NULL
ORDER BY `CONSTRAINT_NAME`, `ORDINAL_POSITION`;
