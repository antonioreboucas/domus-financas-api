# Backend PHP — Domus Finanças

API REST em PHP puro (sem dependências externas/Composer) que atende o
frontend em `../frontend`. Fala com MySQL, autentica por JWT (`Authorization:
Bearer <token>`), e expõe CRUD genérico por entidade mais um punhado de ações
específicas de negócio (ver `CLAUDE.md` para a especificação completa).

## Requisitos no servidor

- PHP 8.0+ com extensões `pdo_mysql` e `curl` (padrão em qualquer plano cPanel).
- Banco de dados MySQL vazio, já criado.

## Deploy

1. **Importar o schema**: no phpMyAdmin do cPanel, abra o banco de dados e
   execute o conteúdo de `schema.sql` (e, se quiser dados de exemplo pra
   testar, `seed.sql` — o usuário de teste fica documentado no topo do
   arquivo).
2. **Configurar segredos**: copie `config.example.php` para `config.php` no
   mesmo diretório e preencha `db_pass`, `jwt_secret` (string aleatória
   longa), `brevo_api_key` (opcional — sem ela, emails são só ignorados),
   `cors_allowed_origins` e `frontend_url`. **Nunca** commite `config.php` —
   já está no `.gitignore`.
3. **Enviar os arquivos**: suba toda a pasta `api/` via File Manager/FTP para
   um subdomínio (ex: `api.seudominio.com.br`) ou subpasta
   (`seudominio.com.br/api`) apontando para este diretório.
4. **Cron Job** (cPanel → Cron Jobs) — gera os alertas diários (conta
   atrasada/vencendo, categoria estourou o limite, fatura de cartão
   chegando):
   `0 8 * * * php /home/SEU_USUARIO/domus-financas-api/cron/daily_alerts.php >> /home/SEU_USUARIO/domus-financas-api/cron/daily_alerts.log 2>&1`

## Rodar localmente

```
php -S localhost:8000
```

Antes disso: importe `schema.sql` (e opcionalmente `seed.sql`) num MySQL
local e copie `config.example.php` para `config.php`, preenchendo
host/usuário/senha do banco local e `cors_allowed_origins` (inclua a origem
do Vite, ex. `http://localhost:5173`).

Rode os testes com `php tests/run.php`.

## Endpoints

- `POST /auth/register`, `POST /auth/login` — devolvem `{ token, user }`.
  Todo endpoint abaixo exige `Authorization: Bearer <token>`.
- `GET /auth/verify-email`, `GET/PUT /auth/me`, `POST /auth/forgot-password`,
  `POST /auth/reset-password`.
- `GET/POST /entities/{Entity}`, `GET/PUT/DELETE /entities/{Entity}/{id}` —
  CRUD genérico para `Income`, `ExpenseCategory`, `Account`, `Card`, `Goal`,
  `Alert`, `Transaction`, `SharedExpense`. Sempre escopado ao usuário
  autenticado (`user_id`, nunca confiado no client). Suporta `?filter=`
  (JSON, operadores `$gte`/`$lte`/`$gt`/`$lt`/`$ne`), `?sort=` (prefixo `-` =
  DESC), `?limit=`, e `POST` com um array JSON no body para criação em lote.
- `PATCH /accounts/{id}/status` — `{ status: "pago" | "pendente" }`.
- `POST /goals/{id}/contribute` — `{ valor: number }`, incrementa `atual` sem
  passar de `meta`.
- `PATCH /shared-expenses/{id}/toggle` — inverte `dividido`.
- `GET /reports/monthly?months=6` — soma de `transactions` por mês (últimos N
  meses, incluindo o atual, zero-preenchido se não houver lançamento).
- `GET /health` — público, testa PHP + MySQL.

Ver `CLAUDE.md` para os schemas de request/response de cada entidade e o
schema SQL completo.

## Modelo de domínio e escopo

- Sem multi-tenant, sem papéis/permissões — cada usuário só enxerga e só
  escreve nas próprias linhas (`EntityController::scopeFilter()` força
  `user_id` = usuário autenticado em toda leitura e escrita).
- Ao registrar uma entidade nova em `lib/EntityRegistry.php`, o modo é sempre
  `'user_scoped'` — não existe hoje nenhum outro modo (nem dado público, nem
  admin de plataforma).
