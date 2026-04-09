-- ============================================================
-- CJ2Tech Sistema de Consulta — Schema MySQL v2
-- Compatível com HostGator compartilhado (MySQL 5.7+)
-- FASE 1-5: Perfis, Empresas, Financeiro
-- ============================================================

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
SET time_zone = "-03:00";
SET NAMES utf8mb4;

-- ============================================================
-- TABELA: empresas (FASE 2 — campos completos)
-- ============================================================
CREATE TABLE IF NOT EXISTS `empresas` (
  `id`                        INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `nome_empresa`              VARCHAR(150) NOT NULL,
  `nome_sistema`              VARCHAR(150) NOT NULL DEFAULT 'CJ2Tech Sistema de Consulta',
  `slug`                      VARCHAR(80)  NOT NULL UNIQUE,
  `status`                    ENUM('ativo','inativo','suspenso') NOT NULL DEFAULT 'ativo',

  -- Licenças
  `limite_licencas`           SMALLINT UNSIGNED NOT NULL DEFAULT 5,
  `licencas_em_uso`           SMALLINT UNSIGNED NOT NULL DEFAULT 0,

  -- Domínio (FASE 2)
  `tipo_dominio`              ENUM('cj2','proprio') NOT NULL DEFAULT 'cj2',
  `subdominio`                VARCHAR(80)  NULL DEFAULT NULL COMMENT 'Ex: minhaempresa (para domínio CJ2)',
  `dominio_personalizado`     VARCHAR(255) NULL DEFAULT NULL COMMENT 'Ex: consulta.empresa.com.br',

  -- API
  `api_habilitada`            TINYINT(1) NOT NULL DEFAULT 1,
  `api_usuario`               VARCHAR(100) NULL DEFAULT NULL,
  `api_senha`                 VARCHAR(255) NULL DEFAULT NULL,
  `api_empresa`               VARCHAR(100) NULL DEFAULT NULL,
  `api_modo`                  ENUM('offline','online') NOT NULL DEFAULT 'offline',

  -- Financeiro (FASE 5 — apenas master_total vê)
  `valor_mensal`              DECIMAL(10,2) NULL DEFAULT NULL,
  `valor_por_licenca`         DECIMAL(10,2) NULL DEFAULT NULL,
  `valor_por_licenca_excedente` DECIMAL(10,2) NULL DEFAULT NULL,
  `custo_por_consulta`        DECIMAL(10,4) NULL DEFAULT NULL,
  `saldo_creditos`            DECIMAL(10,2) NULL DEFAULT 0.00,
  `vencimento_dia`            TINYINT UNSIGNED NULL DEFAULT NULL,
  `status_cobranca`           ENUM('em_dia','pendente','bloqueado') NOT NULL DEFAULT 'em_dia',

  `created_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`                DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_slug` (`slug`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABELA: configuracoes_empresa (FASE 2 — campos visuais completos)
-- ============================================================
CREATE TABLE IF NOT EXISTS `configuracoes_empresa` (
  `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `empresa_id`          INT UNSIGNED NOT NULL,

  -- Identidade
  `titulo_login`        VARCHAR(255) NULL DEFAULT NULL,
  `subtitulo_login`     TEXT NULL DEFAULT NULL,
  `chip_1`              VARCHAR(120) NULL DEFAULT NULL,
  `chip_2`              VARCHAR(120) NULL DEFAULT NULL,
  `chip_3`              VARCHAR(120) NULL DEFAULT NULL,

  -- Assets visuais (FASE 2)
  `logo_dark`           MEDIUMTEXT NULL DEFAULT NULL COMMENT 'Base64 ou URL',
  `logo_light`          MEDIUMTEXT NULL DEFAULT NULL COMMENT 'Base64 ou URL',
  `favicon`             MEDIUMTEXT NULL DEFAULT NULL COMMENT 'Base64 ou URL',
  `imagem_capa`         MEDIUMTEXT NULL DEFAULT NULL COMMENT 'Base64 ou URL (fundo do login)',

  -- Tema
  `tema_padrao`         ENUM('dark','light') NOT NULL DEFAULT 'dark',

  `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_empresa` (`empresa_id`),
  CONSTRAINT `fk_config_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABELA: usuarios (FASE 1 — 4 perfis padronizados)
-- Perfis: master_total, master_operacional, administrador, consultor
-- ============================================================
CREATE TABLE IF NOT EXISTS `usuarios` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `empresa_id`  INT UNSIGNED NULL DEFAULT NULL COMMENT 'NULL apenas para perfis master',
  `nome`        VARCHAR(150) NOT NULL,
  `login`       VARCHAR(80)  NOT NULL,
  `senha_hash`  VARCHAR(255) NOT NULL,
  `perfil`      ENUM('master_total','master_operacional','administrador','consultor') NOT NULL DEFAULT 'consultor',
  `status`      ENUM('ativo','inativo') NOT NULL DEFAULT 'ativo',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_login` (`login`),
  KEY `idx_empresa` (`empresa_id`),
  KEY `idx_perfil` (`perfil`),
  CONSTRAINT `fk_usuario_empresa` FOREIGN KEY (`empresa_id`) REFERENCES `empresas` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- TABELA: sessoes
-- ============================================================
CREATE TABLE IF NOT EXISTS `sessoes` (
  `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `usuario_id`  INT UNSIGNED NOT NULL,
  `token`       CHAR(64) NOT NULL,
  `ip`          VARCHAR(45) NULL DEFAULT NULL,
  `expira_em`   DATETIME NOT NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_token` (`token`),
  KEY `idx_usuario` (`usuario_id`),
  CONSTRAINT `fk_sessao_usuario` FOREIGN KEY (`usuario_id`) REFERENCES `usuarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================
-- DADOS INICIAIS
-- ============================================================

INSERT INTO `empresas` (
  `nome_empresa`, `nome_sistema`, `slug`, `status`, `limite_licencas`, `tipo_dominio`
) VALUES (
  'CJ2 Tech', 'CJ2Tech Sistema de Consulta', 'cj2tech', 'ativo', 999, 'cj2'
);

INSERT INTO `configuracoes_empresa` (
  `empresa_id`, `titulo_login`, `subtitulo_login`,
  `chip_1`, `chip_2`, `chip_3`, `tema_padrao`
) VALUES (
  1,
  'Consulta e simulação de contratos consignados em segundos.',
  'Acesse dados completos do beneficiário, visualize contratos ativos e veja o valor de quitação calculado automaticamente pela metodologia do Banco Central.',
  'CPF e benefício atualizados',
  'Quitação automática pelo Banco Central',
  'Contratos detalhados',
  'dark'
);

-- Usuário master_total inicial (senha: admin@cj2 — TROQUE IMEDIATAMENTE)
-- Para gerar hash: php -r "echo password_hash('admin@cj2', PASSWORD_BCRYPT, ['cost'=>12]);"
INSERT INTO `usuarios` (
  `empresa_id`, `nome`, `login`, `senha_hash`, `perfil`, `status`
) VALUES (
  NULL, 'Master Admin', 'master',
  '$2y$12$placeholder.hash.troque.imediatamente.antes.de.usar',
  'master_total', 'ativo'
);
