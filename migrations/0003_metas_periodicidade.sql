-- Migração 0003: goals.periodicidade
-- Rode no phpMyAdmin de produção — aba "SQL", cola e executa.
--
-- Toda meta já cadastrada vira 'continua' (o padrão da coluna) — nenhuma
-- some ou muda de comportamento com essa migração; só passa a existir a
-- opção de marcar uma meta nova como 'mensal'/'anual'.

ALTER TABLE goals
  ADD COLUMN periodicidade ENUM('continua','mensal','anual') NOT NULL DEFAULT 'continua' AFTER color;
