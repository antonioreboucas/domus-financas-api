-- Migração 0002: suporte ao filtro de mês/ano (rendas com data, contas
-- recorrentes agrupadas, categoria de gasto sem coluna própria).
-- Rode no phpMyAdmin de produção — aba "SQL", cola e executa.

-- incomes.data: NULL temporário só pra poder popular as linhas existentes
-- antes de travar NOT NULL — toda renda já cadastrada passa a "começar"
-- hoje (não dá pra saber retroativamente quando cada uma começou de
-- verdade, então hoje é o ponto de partida mais seguro).
ALTER TABLE incomes ADD COLUMN data DATE NULL AFTER freq;
UPDATE incomes SET data = CURDATE() WHERE data IS NULL;
ALTER TABLE incomes MODIFY COLUMN data DATE NOT NULL;

-- expense_categories.gasto deixa de existir — o valor gasto no mês agora é
-- sempre calculado a partir de transactions (ver CLAUDE.md).
ALTER TABLE expense_categories DROP COLUMN gasto;

-- accounts.grupo_recorrencia: liga as instâncias mensais de uma mesma
-- conta recorrente entre si (ver comentário em schema.sql). Toda conta
-- recorrente já existente vira o início do próprio grupo — cada uma
-- recebe um UUID novo e distinto (UUID() é reavaliado a cada linha num
-- UPDATE, não é a mesma string repetida).
ALTER TABLE accounts ADD COLUMN grupo_recorrencia CHAR(36) NULL AFTER card_id;
ALTER TABLE accounts ADD INDEX idx_accounts_grupo (user_id, grupo_recorrencia);
UPDATE accounts SET grupo_recorrencia = UUID() WHERE tipo = 'recorrente' AND grupo_recorrencia IS NULL;
