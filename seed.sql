-- Dados de exemplo, iguais aos do modo mock do frontend
-- (frontend/src/api/mockData.js) — rode depois do schema.sql pra comparar
-- os dois lados durante o desenvolvimento.
--
-- Usuário de teste: demo@domusfinancas.com.br / senha123
-- (o hash abaixo é password_hash('senha123', PASSWORD_BCRYPT))

SET NAMES utf8mb4;

INSERT INTO users (id, email, password_hash, nome, moeda, notificacoes, plano, ativo, email_verified_at)
VALUES (
  'a0000000-0000-4000-8000-000000000001',
  'demo@domusfinancas.com.br',
  '$2y$10$Lvw8BQQ6DpZ9FdeiZRjM8OC434L8xc/QycCftSE5VmmFbI0A1Thx6',
  'João Marques',
  'BRL',
  1,
  'Plano Doméstico',
  1,
  NOW()
);

SET @uid = 'a0000000-0000-4000-8000-000000000001';

INSERT INTO incomes (id, user_id, nome, valor, freq) VALUES
  (UUID(), @uid, 'Salário CLT', 6200, 'Mensal'),
  (UUID(), @uid, 'Freelance Design', 1400, 'Variável'),
  (UUID(), @uid, 'Aluguel recebido', 900, 'Mensal');

INSERT INTO expense_categories (id, user_id, nome, limite, gasto) VALUES
  (UUID(), @uid, 'Moradia', 2200, 2150),
  (UUID(), @uid, 'Alimentação', 1200, 1340),
  (UUID(), @uid, 'Transporte', 600, 410),
  (UUID(), @uid, 'Lazer', 500, 380),
  (UUID(), @uid, 'Saúde', 400, 190),
  (UUID(), @uid, 'Outros', 300, 265);

INSERT INTO accounts (id, user_id, nome, valor, vencimento, tipo, status) VALUES
  (UUID(), @uid, 'Aluguel', 1800, '2026-08-05', 'recorrente', 'pendente'),
  (UUID(), @uid, 'Internet', 120, '2026-08-10', 'recorrente', 'pendente'),
  (UUID(), @uid, 'Energia elétrica', 280, '2026-07-28', 'recorrente', 'pendente'),
  (UUID(), @uid, 'Plano de saúde', 340, '2026-08-15', 'recorrente', 'pendente'),
  (UUID(), @uid, 'Conserto do carro', 650, '2026-08-03', 'nao_recorrente', 'pendente'),
  (UUID(), @uid, 'Streaming', 60, '2026-08-12', 'recorrente', 'pago');

INSERT INTO cards (id, user_id, nome, bandeira, limite, fatura, vencimento) VALUES
  (UUID(), @uid, 'Nubank', 'Mastercard', 5000, 2340, '2026-08-08'),
  (UUID(), @uid, 'Inter', 'Visa', 3000, 980, '2026-08-18');

INSERT INTO goals (id, user_id, chave, label, atual, meta, color) VALUES
  (UUID(), @uid, 'economia', 'Meta de Economia', 4200, 10000, 'var(--accent)'),
  (UUID(), @uid, 'investimento', 'Meta de Investimento', 8600, 30000, 'var(--blue)'),
  (UUID(), @uid, 'emergencia', 'Fundo de Emergência', 3800, 15000, 'var(--warning)');

-- Sem seed de alerts de propósito: rode `php cron/daily_alerts.php` depois
-- deste arquivo (com a data do sistema em 2026-08-01 ou depois) e ele gera
-- os alertas certos sozinho a partir das contas/categorias/cartões acima —
-- semeá-los aqui também duplicaria com título diferente (ver
-- cron/daily_alerts.php, o texto muda todo dia, o source_key não).

INSERT INTO transactions (id, user_id, nome, categoria, valor, data) VALUES
  (UUID(), @uid, 'Supermercado Extra', 'Alimentação', -286, '2026-07-30'),
  (UUID(), @uid, 'Salário', 'Renda', 6200, '2026-07-28'),
  (UUID(), @uid, 'Uber', 'Transporte', -38, '2026-07-27'),
  (UUID(), @uid, 'Farmácia', 'Saúde', -64, '2026-07-25'),
  (UUID(), @uid, 'Cinema', 'Lazer', -72, '2026-07-22'),
  (UUID(), @uid, 'Freelance Design', 'Renda', 1400, '2026-07-20'),
  (UUID(), @uid, 'Restaurante', 'Alimentação', -145, '2026-07-18'),
  (UUID(), @uid, 'Posto de gasolina', 'Transporte', -190, '2026-07-15'),
  (UUID(), @uid, 'Aluguel recebido', 'Renda', 900, '2026-06-28'),
  (UUID(), @uid, 'Supermercado', 'Alimentação', -310, '2026-06-20'),
  (UUID(), @uid, 'Salário', 'Renda', 6200, '2026-06-05');

INSERT INTO shared_expenses (id, user_id, nome, valor, dividido) VALUES
  (UUID(), @uid, 'Aluguel', 1800, 1),
  (UUID(), @uid, 'Internet', 120, 1),
  (UUID(), @uid, 'Supermercado', 850, 1),
  (UUID(), @uid, 'Streaming', 60, 0);
