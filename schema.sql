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
  -- Mês/ano a que a renda pertence. 'Mensal' conta como ativa em todo mês
  -- >= este (recorrência pra frente); 'Variável'/'Anual' só no mês exato —
  -- ver deriveViewModel() em frontend/src/context/FinanceContext.jsx, é lá
  -- que essa regra é aplicada (a API só guarda a data, não filtra).
  data DATE NOT NULL,
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
  -- Sem coluna "gasto": o valor gasto no mês é sempre a soma de
  -- transactions daquela categoria no período selecionado, calculada no
  -- frontend (nunca fica desatualizado, nunca precisa de dois writes pra
  -- criar um gasto). limite continua configuração contínua — não é
  -- redefinido todo mês.
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
  -- Livre por nome, igual transactions.categoria — não é FK pra
  -- expense_categories de propósito (mesma convenção do resto do schema:
  -- ver comentário de categoria_id em produtos no boilerplate original).
  categoria VARCHAR(120) NULL,
  -- Este sim é FK de verdade: "esta conta é paga com este cartão" é uma
  -- relação real entre duas entidades primárias, não uma legenda solta.
  -- NULL = conta não associada a nenhum cartão (pix, boleto, débito, etc).
  card_id CHAR(36) NULL,
  -- Liga todas as instâncias mensais de UMA conta recorrente (mesmo
  -- "Aluguel" em agosto, setembro, outubro...) — gerado uma vez (UUID) na
  -- criação e reaproveitado em cada mês futuro. NULL pra não_recorrente,
  -- que nunca se repete. O frontend projeta um "instância virtual" pro mês
  -- selecionado quando o grupo não tem linha real ainda naquele mês; só
  -- vira linha de verdade (INSERT) quando o usuário marca como paga ou
  -- edita — ver materializeAccount() em FinanceContext.jsx.
  grupo_recorrencia CHAR(36) NULL,
  created_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_accounts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_accounts_user (user_id, status, vencimento),
  INDEX idx_accounts_card (card_id),
  INDEX idx_accounts_grupo (user_id, grupo_recorrencia)
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

-- accounts.card_id só pode ser declarado depois que `cards` existe (ordem de
-- criação das tabelas acima).
ALTER TABLE accounts
  ADD CONSTRAINT fk_accounts_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE SET NULL;

CREATE TABLE IF NOT EXISTS goals (
  id CHAR(36) PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  chave VARCHAR(40) NOT NULL,
  label VARCHAR(120) NOT NULL,
  atual DECIMAL(12,2) NOT NULL DEFAULT 0,
  meta DECIMAL(12,2) NOT NULL,
  color VARCHAR(30) NOT NULL DEFAULT 'var(--accent)',
  -- 'continua' (padrão): meta de longo prazo, sempre visível, ignora o
  -- filtro de mês/ano por completo (ex: Fundo de Emergência). 'mensal'/
  -- 'anual': só fica visível a partir do mês/ano em que foi criada — não
  -- existe reset automático de `atual` por período ainda (isso exigiria
  -- guardar histórico de aportes, que essa tabela não tem); por enquanto a
  -- diferença é só essa visibilidade. Ver "Filtro de período" no CLAUDE.md.
  periodicidade ENUM('continua','mensal','anual') NOT NULL DEFAULT 'continua',
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
