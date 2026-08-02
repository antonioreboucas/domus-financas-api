# Domus Finanças — API (PHP puro)

Esta pasta contém a implementação real da API, adaptada de um boilerplate
PHP puro reutilizado de outro projeto (ConveniênciaOS). Este documento
descreve o contrato **como ele está implementado hoje** — não é mais uma
proposta a ser seguida, é a referência para o frontend (`../frontend`) e
para qualquer mudança futura no backend.

> Se este arquivo divergir do código, o código está certo e este arquivo
> está desatualizado — atualize-o na mesma mudança.

## Stack

- **PHP puro**, sem framework, sem Composer. PHP 8.0+.
- **PDO** com prepared statements em toda query.
- MySQL/MariaDB (dev local: XAMPP, `db_name = domus_financas`).
- Front controller único: `index.php` faz o roteamento manual (if/switch em
  `$_SERVER['REQUEST_METHOD']` + `$path`) e inclui o arquivo de rota
  correspondente em `routes/`.

## Estrutura

```
api/
  index.php              # front controller — roteamento
  config.example.php      # template (copiar pra config.php E/OU config.local.php)
  config.php               # config de PRODUÇÃO — é isso que sobe pro HostGator (NUNCA commitar)
  config.local.php          # override local — tem prioridade quando existe (NUNCA commitar)
  schema.sql                # CREATE TABLE
  seed.sql                   # dados de exemplo (usuário demo@domusfinancas.com.br / senha123)
  .htaccess                   # rewrite tudo pra index.php (Apache/cPanel)
  lib/
    Config.php       # Config::load() — config.local.php se existir, senão config.php
    Database.php    # conexão PDO (singleton), usa Config::load()
    Response.php     # Response::json()/error() — envelope flat, ver abaixo
    Jwt.php           # JWT HS256 minimalista (sem lib externa)
    Uuid.php           # UUID v4 (todo id de tabela é CHAR(36))
    Auth.php            # Auth::currentUser() — decodifica Bearer, busca no banco
    RateLimiter.php      # limita tentativas de /auth/login (arquivo, sem Redis)
    EntityRegistry.php    # mapa entidade -> tabela (todas 'user_scoped')
    EntityController.php   # CRUD genérico (list/filter/get/create/update/delete/bulkCreate)
    Mailer.php               # email transacional via Brevo (best-effort, no-op sem api key)
  routes/
    auth.php          # /auth/*
    entities.php       # /entities/{Entity}(/{id})
    domus.php           # ações que não são CRUD genérico (ver abaixo)
    health.php            # /health
  cron/
    daily_alerts.php   # gera alerts (conta atrasada, limite estourado, fatura chegando)
  tests/
    run.php             # smoke tests sem framework (php tests/run.php)
```

Rodar localmente: `php -S localhost:8000` (a partir de `api/`). O
`frontend/vite.config.js` tem um proxy de `/api` → `http://localhost:8000`
para uso com `VITE_API_MODE=http`.

> **`config.php` costuma ter credenciais de produção** (é o arquivo pensado
> pra subir pro HostGator — ver README "Deploy"). Todo script rodado
> localmente (`php -S`, `php cron/*.php`) passa por `Config::load()`, que
> usa `config.local.php` no lugar dele quando esse arquivo existe — crie um
> a partir de `config.example.php` com credenciais do banco local antes de
> rodar qualquer coisa nesta pasta na sua máquina. Sem isso, testar
> localmente pode acabar lendo/escrevendo no banco de produção de verdade.

## Autenticação

**JWT Bearer, não sessão/cookie.** `POST /auth/login` e `POST /auth/register`
devolvem `{ token, user }`; todo outro endpoint exige o header
`Authorization: Bearer <token>`. `lib/Auth.php::currentUser()` decodifica o
token (`lib/Jwt.php`, HS256, segredo em `config.php['jwt_secret']`), busca o
usuário no banco e aborta com `401` se o token for inválido/expirado ou o
usuário estiver `ativo = 0`.

O frontend guarda o token (ex.: `localStorage`) e manda
`Authorization: Bearer <token>` em toda chamada — não usa cookie de sessão,
então não há necessidade de CORS com credentials, mas `cors_allowed_origins`
em `config.php` ainda precisa listar a origem do frontend.

| Rota | Método | Body | Resposta |
|---|---|---|---|
| `/auth/register` | POST | `{ email, password, nome? }` | `201` → `{ token, user }` |
| `/auth/login` | POST | `{ email, password }` | `200` → `{ token, user }` (rate-limited: 5 tentativas/15min por IP+email) |
| `/auth/verify-email?token=` | GET | — | redirect pro frontend |
| `/auth/me` | GET | — | perfil do usuário autenticado |
| `/auth/me` | PUT | `{ nome?, moeda?, notificacoes? }` | perfil atualizado |
| `/auth/forgot-password` | POST | `{ email }` | `{ success: true }` sempre (não revela se o email existe) |
| `/auth/reset-password` | POST | `{ resetToken, newPassword }` | `{ success: true }` |

`user` sempre tem a forma: `{ id, email, nome, moeda, notificacoes, plano, ativo, created_date, updated_date }`
(nunca inclui `password_hash`/tokens internos).

## Convenções gerais

**Envelope de resposta — flat, sem wrapper `{success, data}`:**

```json
// sucesso: o corpo da resposta É o dado (objeto, array, ou {success:true} pra ações sem retorno)
{ "id": "...", "nome": "...", ... }

// erro: sempre { "error": "mensagem em português" } — sem campo "code"
{ "error": "Não autenticado" }
```

`Response::json($data, $status)` / `Response::error($message, $status)` em
`lib/Response.php`. HTTP status: `200` ok, `201` created, `400` validação,
`401` não autenticado, `404` não encontrado, `409` conflito (email
duplicado), `429` rate limit, `500` erro inesperado.

**IDs:** `CHAR(36)` UUID v4 (`lib/Uuid.php`), sempre string — nunca assuma
inteiro/autoincrement.

**Dinheiro:** campos monetários (`valor`, `limite`, `gasto`, `fatura`,
`atual`, `meta`) são números decimais brutos (o MySQL devolve como string
tipo `"1800.00"` via PDO — o frontend já faz `Number(...)` antes de
formatar). Nunca `R$`/vírgula vindo do backend.

**Datas:** `YYYY-MM-DD` (colunas `DATE`), sem hora.

**Nomes de coluna:** `created_date`/`updated_date` (não `created_at`) —
convenção herdada do boilerplate original, mantida por consistência com o
resto do código PHP.

**Escopo por usuário:** toda tabela de dado financeiro tem `user_id`. Nunca
confiado no client — `EntityController::scopeFilter()` sobrescreve/força
`user_id` = usuário autenticado em toda query (leitura e escrita). Testado
manualmente: um usuário B tentando ler/editar um registro do usuário A por
id recebe `404` (não `403` — não revela que o registro existe).

## CRUD genérico — `/entities/{Entity}`

`lib/EntityController.php` é o motor: introspecciona colunas via
`INFORMATION_SCHEMA` (então não precisa saber os campos de cada tabela na
mão), decodifica colunas JSON automaticamente, e aplica sempre o modo
`'user_scoped'` (único modo que existe — ver `lib/EntityRegistry.php`).

| Rota | Método | Descrição |
|---|---|---|
| `/entities/{Entity}` | GET | Lista. Suporta `?filter={"campo":"valor"}` (JSON; operadores `$gte`/`$lte`/`$gt`/`$lt`/`$ne` como `{"campo":{"$gte":10}}`), `?sort=campo` (prefixo `-` = DESC), `?limit=N` (default 1000). |
| `/entities/{Entity}/{id}` | GET | Um registro. `404` se não existir ou não for do usuário. |
| `/entities/{Entity}` | POST | Cria. Body é um objeto único, OU uma lista JSON (`[{...},{...}]`) pra criação em lote — `EntityController::isListPayload()` detecta automaticamente. `id`/`user_id`/`created_date`/`updated_date` no body são sempre ignorados/sobrescritos. |
| `/entities/{Entity}/{id}` | PUT | Atualiza parcial (só os campos enviados). `id`/`user_id` não são reatribuíveis. |
| `/entities/{Entity}/{id}` | DELETE | Remove. `{ "success": true }`. |

Entidades registradas (`{Entity}` → tabela):

| Entity | Tabela | Campos |
|---|---|---|
| `Income` | `incomes` | `nome, valor, freq` (`Mensal｜Variável｜Anual`), `data` — mês/ano a que pertence, ver "Filtro de período" abaixo |
| `ExpenseCategory` | `expense_categories` | `nome, limite` — **sem `gasto`**: calculado no frontend a partir de `transactions`, ver "Filtro de período" |
| `Account` | `accounts` | `nome, valor, vencimento, tipo` (`recorrente｜nao_recorrente`), `status` (`pendente｜pago` — **nunca** `atrasado`, ver abaixo), `categoria` (string livre, opcional — mesmo padrão de `transactions.categoria`, não é FK), `card_id` (opcional, FK pra `cards.id`, `ON DELETE SET NULL` — conta paga com aquele cartão), `grupo_recorrencia` (opcional, liga instâncias mensais da mesma conta recorrente entre si, ver "Filtro de período") |
| `Card` | `cards` | `nome, bandeira, limite, fatura, vencimento` |
| `Goal` | `goals` | `chave, label, atual, meta, color` |
| `Alert` | `alerts` | `nivel` (`alta｜media｜baixa`), `titulo, descricao` — só GET/DELETE pelo frontend; quem cria é `cron/daily_alerts.php` |
| `Transaction` | `transactions` | `nome, categoria, valor, data` (`valor` positivo = entrada, negativo = saída) |
| `SharedExpense` | `shared_expenses` | `nome, valor, dividido` (bool) |

**`accounts.status` só guarda `pendente`/`pago`.** "Atrasado" é sempre
derivado comparando `vencimento` com a data atual — no frontend em
`frontend/src/lib/format.js#effectiveStatus()`, e em `cron/daily_alerts.php`
pro alerta diário. Nunca persista `'atrasado'` como valor de status — um
valor gravado ficaria desatualizado sem um job rodando toda hora.

## Filtro de período (mês/ano)

O app tem um seletor de mês/ano global (topo das telas principais) que
decide "de qual mês são esses valores". **Toda essa lógica vive no
frontend** (`frontend/src/context/FinanceContext.jsx`) — a API sempre
devolve as linhas cruas, sem filtrar por período; quem decide o que
mostrar pro mês selecionado é o cliente. Documentado aqui porque muda o
que os campos acima significam:

- **`incomes`**: `freq='Mensal'` conta como ativa em todo mês
  `>= data` (recorrência pra frente, sem data final). `freq='Variável'`
  ou `'Anual'` só conta no mês exato de `data`.
- **`expense_categories`**: `limite` é configuração contínua (não muda
  por mês). O "gasto" do mês selecionado é `SUM(transactions.valor)`
  (só as negativas, valor absoluto) onde `transactions.categoria = expense_categories.nome`
  e `transactions.data` cai no mês/ano selecionado.
- **`accounts` recorrentes**: cada linha real é a instância de UM mês
  específico (seu próprio `vencimento` diz qual). Pra um mês que ainda
  não tem instância real de um `grupo_recorrencia`, o frontend **projeta
  uma linha virtual** (não existe no banco, `id: null`) usando a instância
  mais antiga do grupo como modelo (nome/valor/categoria/card_id), com o
  mesmo dia-do-mês projetado pro mês selecionado e `status='pendente'`.
  Essa projeção só acontece pra mês `>=` o mês da instância mais antiga do
  grupo (uma conta recorrente não "existe" antes de ter sido criada). A
  linha vira real (`POST /entities/Account` de verdade, com o mesmo
  `grupo_recorrencia`) só quando o usuário interage com ela — marcar como
  paga ou editar. Contas `nao_recorrente` nunca são projetadas.
- **`cards`/`goals`**: visíveis a partir do mês/ano de `created_date`
  (um cartão criado em setembro não aparece revisitando agosto). O valor
  mostrado (`fatura`, `atual`) é sempre o valor atual real — não existe
  reconstrução histórica de fatura/aporte por mês passado, só a data de
  criação decide a partir de quando a linha aparece.
- **`transactions`**: filtradas direto por `data` caindo no mês/ano
  selecionado — é o caso mais simples, já tinha o campo certo.
- **`alerts`** e **`shared_expenses`**: não passam pelo filtro de período,
  sempre mostram o estado atual independente do mês selecionado (alertas
  são sobre agora; despesas compartilhadas não têm essa noção ainda).

## Ações que não são CRUD genérico — `routes/domus.php`

Mudam um campo específico com uma regra de negócio, ou agregam dados entre
linhas — não fazem sentido como "substituir o registro inteiro" via
`PUT /entities/...`.

| Rota | Método | Body | Resposta |
|---|---|---|---|
| `/accounts/{id}/status` | PATCH | `{ status: "pago"｜"pendente" }` | conta atualizada |
| `/goals/{id}/contribute` | POST | `{ valor: number }` | meta atualizada, `atual = min(meta, atual + valor)` |
| `/shared-expenses/{id}/toggle` | PATCH | — | despesa com `dividido` invertido |
| `/reports/monthly?months=6` | GET | — | `[{ mes, ano, renda, gasto }, ...]`, últimos N meses (padrão 6, máx 24), **incluindo o mês atual mesmo sem transação** (zero-preenchido) |

`reports/monthly` agrega `transactions` por `DATE_FORMAT(data, '%Y-%m')`
direto em SQL (`SUM` condicional pra separar renda de gasto pelo sinal de
`valor`), scoped por `user_id` — ver `reportsMonthly()` em
`routes/domus.php`.

## Alertas automáticos — `cron/daily_alerts.php`

Roda via cron (ver `README.md`), não pela API HTTP. Pra cada usuário ativo:

1. Contas `pendente` atrasadas (nível `alta`) ou vencendo em ≤3 dias (nível
   `media`) — mesmo corte de urgência usado no frontend.
2. Categorias com gasto do **mês corrente** (`SUM(transactions)` agrupado
   por categoria, mês de `CURDATE()`) acima do `limite` (nível `media`).
3. Faturas de cartão vencendo em ≤8 dias (nível `baixa`).

Upsert por `(user_id, source_key)` — **não por título**: o título muda todo
dia ("vence em 2 dias" → "vence em 1 dia"), a condição que gerou o alerta
não (`account:{id}:atrasado`, `account:{id}:vencendo`, `category:{id}:limite`,
`card:{id}:fatura`). Dedupar por título faria o mesmo alerta ser inserido de
novo a cada execução — foi um bug real da primeira versão deste script,
pego rodando contra dado seedado (ver histórico). A cada execução, alertas
cuja condição não é mais verdadeira (conta paga, categoria voltou pro
limite, fatura fora da janela de 8 dias) são deletados — resolvidos não
ficam presos na tela pra sempre. Dispensar um alerta na UI (`DELETE
/entities/Alert/{id}`) remove a linha; se a condição ainda for verdadeira, a
próxima execução do cron recria (não fica "dispensado" permanentemente — não
há campo pra isso hoje). Se `notificacoes = 1`, manda um resumo por email via
`Mailer` (best-effort, silencioso sem `brevo_api_key`).

## Schema SQL

Ver `schema.sql` (fonte da verdade). Convenções: `id CHAR(36) PRIMARY KEY`
(UUID v4), `created_date`/`updated_date DATETIME` automáticos, `DECIMAL(12,2)`
pra dinheiro, `TINYINT(1)` pra booleano, `ENGINE=InnoDB DEFAULT
CHARSET=utf8mb4`, toda tabela de dado financeiro com
`user_id CHAR(36) NOT NULL REFERENCES users(id) ON DELETE CASCADE`.

`seed.sql` popula um usuário de teste (`demo@domusfinancas.com.br` /
`senha123`) com os mesmos dados do modo mock do frontend
(`frontend/src/api/mockData.js`), incluindo ~3 meses de `transactions` pra
`/reports/monthly` ter o que agregar.

## Regras de negócio replicadas no frontend (não na API)

`frontend/src/context/FinanceContext.jsx` calcula tudo isso a partir dos
dados brutos que a API devolve — a API não replica (evita duas fontes de
verdade), exceto `reports/monthly` que exige `GROUP BY` em SQL:

- `saldoDisponível = totalRenda − totalGasto`
- `orçamentoPct = min(100, round(totalGasto / totalLimite * 100))`; cor:
  `≥95%` vermelho, `≥75%` amarelo, senão verde
- prioridade de conta: atrasado ou `≤3 dias` → ALTA, `≤8 dias` → MÉDIA,
  senão BAIXA (mesmo corte usado em `daily_alerts.php`)
- insight "onde economizar": categorias com gasto do mês `> limite * 0.85`
- toda a lógica de "qual mês/ano é isso" (recorrência de renda/conta,
  projeção de conta recorrente, gasto por categoria) — ver "Filtro de
  período" acima

## Segurança

- Prepared statements sempre (`PDO::prepare` + bind), nunca interpolar
  input do usuário em SQL — inclusive em `EntityController` (nomes de
  coluna são validados contra `INFORMATION_SCHEMA` antes de entrar numa
  query, nunca vêm crus do client).
- `password_hash`/`password_verify` (bcrypt), nunca texto plano em log.
- Rate limit em `/auth/login` (`lib/RateLimiter.php`, 5 tentativas/15min por
  IP+email, arquivo em `storage/rate_limit/` com `.htaccess` negando acesso
  HTTP direto).
- Todo `GET`/`PUT`/`DELETE` por id passa por `scopeFilter()` — nunca confia
  só no id vindo da URL.
- `.htaccess` reenvia o header `Authorization` pro PHP (alguns hosts cPanel
  não repassam por padrão).
