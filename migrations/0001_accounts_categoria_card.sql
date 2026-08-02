-- Migração 0001: accounts.categoria + accounts.card_id
-- Rode isso no phpMyAdmin de produção (banco anto8520_domus): abra o banco,
-- aba "SQL", cole o conteúdo deste arquivo e execute. Já testado localmente
-- (mesmo texto usado em domus_financas) — só falta aplicar em produção.
--
-- Idempotente na prática: se rodar duas vezes, a 2ª execução dá erro
-- "Duplicate column" / "Duplicate key" e para aí — não corrompe nada, só
-- não repete o que já foi aplicado. Se isso acontecer, é sinal de que a
-- migração já estava aplicada.

ALTER TABLE accounts ADD COLUMN categoria VARCHAR(120) NULL AFTER tipo;
ALTER TABLE accounts ADD COLUMN card_id CHAR(36) NULL AFTER categoria;
ALTER TABLE accounts ADD INDEX idx_accounts_card (card_id);
ALTER TABLE accounts
  ADD CONSTRAINT fk_accounts_card FOREIGN KEY (card_id) REFERENCES cards(id) ON DELETE SET NULL;
