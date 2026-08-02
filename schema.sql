-- Domus Finanças — schema MySQL
-- Rode este arquivo no phpMyAdmin (ou via `mysql -u ... -p ... < schema.sql`)
-- dentro do banco de dados vazio antes de usar o backend.
--
-- Convenção: toda tabela tem id CHAR(36) (UUID v4), created_date/updated_date
-- automáticos. Toda tabela de dado financeiro tem user_id com FK para
-- users(id) ON DELETE CASCADE — apagar a conta apaga os dados dela.

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS users (
  id CHAR(36) PRIMARY KEY,
  email VARCHAR(255) NOT NULL UNIQUE,
  password_hash VARCHAR(255) NOT NULL,
  nome VARCHAR(255) NOT NULL,
  moeda VARCHAR(3) NOT NULL DEFAULT 'BRL',
  notificacoes TINYINT(1) NOT NULL DEFAULT 1,
  plano VARCHAR(60) NOT NULL DEFAULT 'Plano Doméstico',
  ativo TINYINT(1) NOT NULL DEFAULT 1,
  email_verified_at DATETIME NULL,
  email_verify_token VARCHAR(64) NULL,
  reset_token VARCHAR(64) NULL,
  reset_token_expires DATETIME NULL,
  created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS incomes (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  valor DECIMAL(12,2) NOT NULL,
  freq ENUM('Mensal','Variável','Anual') NOT NULL DEFAULT 'Mensal',
  created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_incomes_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_incomes_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS expense_categories (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  limite DECIMAL(12,2) NOT NULL DEFAULT 0,
  gasto DECIMAL(12,2) NOT NULL DEFAULT 0,
  created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_expense_categories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_expense_categories_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS accounts (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  valor DECIMAL(12,2) NOT NULL,
  vencimento DATE NOT NULL,
  tipo ENUM('recorrente','nao_recorrente') NOT NULL DEFAULT 'recorrente',
  status ENUM('pendente','pago') NOT NULL DEFAULT 'pendente',
  created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_accounts_user (user_id, status, vencimento)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS cards (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  bandeira VARCHAR(60) NOT NULL,
  limite DECIMAL(12,2) NOT NULL,
  fatura DECIMAL(12,2) NOT NULL DEFAULT 0,
  vencimento DATE NOT NULL,
  created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_cards_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_cards_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS goals (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  chave VARCHAR(40) NOT NULL,
  label VARCHAR(120) NOT NULL,
  atual DECIMAL(12,2) NOT NULL DEFAULT 0,
  meta DECIMAL(12,2) NOT NULL,
  color VARCHAR(30) NOT NULL DEFAULT 'var(--accent)',
  created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_goals_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_goals_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS alerts (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  nivel ENUM('alta','media','baixa') NOT NULL,
  titulo VARCHAR(160) NOT NULL,
  descricao VARCHAR(500) NOT NULL,
  -- Identifica a condição que gerou o alerta (ex: "account:<id>:atrasado"),
  -- não o texto exibido — o texto muda todo dia ("vence em 2 dias" vira
  -- "vence em 1 dia"), a condição não. cron/daily_alerts.php faz upsert por
  -- (user_id, source_key) em vez de por título, senão duplicaria o alerta
  -- toda vez que o texto mudasse. NULL para alertas sem origem automática
  -- (não existe hoje, mas a coluna permite — índice único ignora NULLs).
  source_key VARCHAR(80) NULL,
  created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_alerts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_alerts_user (user_id),
  UNIQUE KEY uniq_alerts_user_source (user_id, source_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS transactions (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  nome VARCHAR(160) NOT NULL,
  categoria VARCHAR(60) NOT NULL,
  valor DECIMAL(12,2) NOT NULL,
  data DATE NOT NULL,
  created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_transactions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_transactions_user_data (user_id, data)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS shared_expenses (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  nome VARCHAR(120) NOT NULL,
  valor DECIMAL(12,2) NOT NULL,
  dividido TINYINT(1) NOT NULL DEFAULT 1,
  created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_shared_expenses_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_shared_expenses_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
