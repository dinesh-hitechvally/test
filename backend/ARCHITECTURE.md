# Backend architecture

Laravel 13 / PHP 8.3 API for the Share Market SPA (`../frontend`). This file is
the map: where things live, how data flows, and where new code should go.

## Folder map

```
app/
├── Contracts/            Interfaces for swappable parts (AI provider, price-history source).
│                         Bound to implementations in AppServiceProvider::$bindings.
├── Events/               Facts the app announces ("prices changed", "a scrape finished").
├── GraphQL/              The SPA's API (POST /graphql, Lighthouse). Schema in ../graphql/*.graphql.
│   ├── Resolvers/        One class per area (Auth, Stock, Market, Report, Portfolio, Watchlist);
│   │                     each field is a one-line call into a service.
│   ├── Resolver          Base: plain() (same JSON as the models serialise to), validated()
│   │                     (runs a FormRequest on the field's args).
│   └── ErrorHandler · ApiError   Errors carry extensions.status (401/404/422/502) and
│                         extensions.validation (the FormRequest's per-field messages).
├── Listeners/            What happens in response. Wired in AppServiceProvider::LISTENERS.
├── Http/
│   ├── Controllers/Api/  The file transfers that stay plain HTTP (routes/api.php): the price CSV
│   │                     upload and the portfolio CSV/PDF/Excel downloads.
│   ├── Controllers/      CronController — one invokable action behind every /cron/* URL.
│   ├── Middleware/       VerifyCronSecret (?key= on every /cron/* URL).
│   └── Requests/         One FormRequest per input, grouped by area (used by resolvers too).
├── Models/               Eloquent models (one per table).
├── Providers/            AppServiceProvider — contract bindings + event wiring.
├── Tasks/                Background units of work, grouped by domain like Services/.
│   │                     They don't know how they're triggered — cron URLs today, but a
│   │                     controller, test or queued job can run the same class.
│   ├── Task · PerStockTask (works through every pending stock) · TaskRunner (log + alert)
│   ├── MarketData/       stock list, live prices, index, histories, sectors, dividends,
│   │                     fundamentals, nepalstock.com token check.
│   ├── Analysis/         manual recalculation, signal + next-close backtests.
│   ├── MachineLearning/  train the direction predictor.
│   └── Ai/               generate AI opinions.
└── Services/             All business logic, grouped by what it does:
    ├── DataSources/      Talks to the outside world, writes what it gets back.
    │   ├── NepalStock/   nepalstock.com. NepalStockClient is the only class that knows how to
    │   │                 authenticate (headers + token); the others (market status, live prices,
    │   │                 indices, securities/sectors, dividends) just call get().
    │   ├── ShareSansar/  Full price history (the primary history source).
    │   ├── MeroLagani/   Fundamentals (EPS, P/E, book value).
    │   ├── Csv/          CSV price import.
    │   └── CorporateActionsRefreshService
    ├── Analysis/         Derived purely from prices already in the DB — no external calls.
    │   ├── Indicators/   TechnicalAnalysisService (pure math) + IndicatorRecalculationService (stores it).
    │   ├── Signals/      SignalRules (rules + weights), SignalGeneratorService, SignalAccuracyService
    │   │                 (backtest), SignalFeedService (today / buy-sell feeds), SignalRuleScanner.
    │   ├── Forecasting/  NextCloseEstimatorService.
    │   ├── Patterns/     CandlestickPatternScanner (market-wide pattern scan).
    │   └── RecalculationPipeline   indicators → signals → next-close, for one or many stocks.
    ├── MachineLearning/  Direction predictor (Random Forest) + its feature builder.
    ├── Reports/          Read-side assembly for pages, one class per job:
    │                     PriceStatisticsService (% change, returns, 52-week, trend — used by the
    │                     others), MarketReportService (dashboard/market), DividendReportService,
    │                     InvestmentHorizonService (long/mid/short-term), Sector/Stock/Screener/
    │                     Index/TechnicalAnalysis reports.
    ├── Stocks/           StockService — find/list/create stocks, their price/indicator/
    │                     forecast series, signal history, dividends.
    ├── Watchlists/       WatchlistService.
    ├── Ai/               AI buy/hold/sell opinion (prompt) + GroqOpinionProvider (the LLM call).
    ├── Portfolio/        PortfolioService (ledger), valuation/P&L, price alerts, Export/.
    ├── Auth/             Login history + geolocation.
    ├── Alerts/           FailureAlertService — log + optional Slack/email when a task fails.
    ├── DataQuality/      DataQualityService — detects bad data (invalid OHLC, abnormal moves,
    │                     missing volume, stale-date corrections, missing trading dates, a likely-
    │                     unadjusted corporate action) and records it (data_quality_flags), never
    │                     fixes it. Per-row checks run inline (FlagPriceQualityIssues listener,
    │                     DailyPriceWriter); market-wide checks run daily (ScanDataQualityTask).
    └── Mail/             EmailLogService — records every outgoing email (email_logs table).

```

Tests mirror this: `tests/Feature/{Analysis,Auth,Cron,Events,Portfolio,Watchlists}`, `tests/Unit/{Analysis,DataSources}`.
Feature tests call the API with `$this->graphQL($query, $variables)`.

## How a request flows

```
SPA ──POST /graphql──▶ Resolver ──▶ FormRequest (validation) ──▶ Service ──▶ Model/DB
SPA ──file up/down──▶ Controllers/Api ──▶ Service
Pinger ─GET─▶ /cron/<path> ──▶ VerifyCronSecret ──▶ CronController ──▶ TaskRunner ──▶ Task ──▶ Service
```

**Resolvers and controllers only call things.** A resolver method (or
controller action) does at most three things: validate (`$this->validated(SomeRequest::class, $args)`
in a resolver, a type-hinted FormRequest in a controller), call a service /
task / event, and return the result (`$this->plain(...)`) or throw
`ApiError($message, $status)`. No queries, loops or response assembly — that
belongs in a service. No `new SomeService()`.

**Auth: Sanctum bearer token, no cookies.** `login` issues a personal access token
(`$user->createToken('spa')`); the SPA stores it and sends `Authorization: Bearer
<token>` on every request from then on. `logout` revokes it. Neither `/graphql`
nor `/api/*` carries `EnsureFrontendRequestsAreStateful`/`statefulApi()` — there's
no session, no CSRF, no cookie to keep warm (see `config/lighthouse.php` and
`bootstrap/app.php`). Sanctum's guard resolves the user straight from the token;
every field except `me`, `login`, `forgotPassword` and `resetPassword` is
`@guard`ed. A downside: the SPA never proactively checks whether a token is still
valid — it finds out reactively, the moment a real request 401s (frontend's
`auth:unauthenticated` handling, see `frontend/src/main.js`). Parsed queries are
cached as PHP files in `bootstrap/cache` (`LIGHTHOUSE_QUERY_CACHE_MODE=opcache`),
and `php artisan lighthouse:clear-cache` clears the cached schema after a schema change.

## Event workflow

Services announce what happened; listeners do the follow-up. All listeners are
**synchronous** (this hosting has no queue worker), so the triggering request
waits for them.

| Event | Fired by | Listener |
|---|---|---|
| `StockPricesUpdated` | market sync, history fetch (either source), CSV import | `FlagPriceQualityIssues` → DataQualityService checks on each stock's newest row |
| `ScrapeFinished` | every external fetch, success or failure | `RecordScrapeLog` → `scrape_logs` row |
| `TaskFailed` | TaskRunner (any failed task) | `AlertTaskFailure` → FailureAlertService |
| `UserLoggedIn` | the `login` mutation (AuthResolver) | `RecordLoginHistory` → LoginHistoryService (IP, device, location) |
| Laravel `MessageSending` / `MessageSent` | every email, whatever sends it | `RecordEmailSending` / `RecordEmailSent` → EmailLogService |

**Email log:** every email gets an `email_logs` row — status (`sending` → `sent`, `logged` when
the mailer is `log` so it was NOT delivered, or `failed` + the error), recipients, from, subject,
mailer, message id, what sent it, and the matching user. Laravel has no "mail failed" event, so a
transport exception is caught by the hook in `bootstrap/app.php`; code that catches a mail error
itself must `report($e)` for the row to be marked failed. Bodies are never stored (reset links
are secrets). Read it with the `emailLogs(status: "failed")` GraphQL query.

So the daily chain is: **fetch/prices (prices only) → generate/indicators (indicators → signals → next-close)**.
Listeners are registered explicitly (discovery is off in
`bootstrap/app.php`) so a stale `event:cache` can never silently drop one.

## Cron (no server cron / SSH)

> A browsable version of this section, the API reference and the buy/sell rules is served at **`/docs`** (single page, built from the live schema and routes; `DOCS_ENABLED=false` hides it).

The task URLs are ordinary routes in `routes/web.php` (each one names the task it runs),
all under `/cron/*` and all needing `?key=CRON_SECRET`. **Scheduling is done in cPanel cron,
not in code** — add a cron job there that curls the URL at the time you want, e.g.
`curl -s "https://api.bizrms.com/cron/fetch/prices?key=..."`.

URL pattern: `/cron/<kind>/<what>`

| Kind | Does | URL → task |
|---|---|---|
| `fetch/` | Pulls from an external source and saves raw data. Never computes anything. | `stock-list` (also sets sectors and instrument types) · `prices` (live while open, final after close) · `index` · `histories` (one pending stock per ping) · `history/{symbol}` (one named stock) · `dividends` (one pending stock per ping) · `dividends/{symbol}` · `fundamentals` (one due stock per ping) · `fundamentals/{symbol}` |
| `generate/` | Computes derived data from what is already in the database. | `indicators` (indicators → signals → next-close; `?all=1` = every stock) · `ai-opinions` (Groq) · `ml-model` · `backtest-signals` · `backtest-next-close` |
| `check/` | Health checks. | `nepse-token` · `data-quality` |

- **Order matters:** `fetch/prices` → `generate/indicators` → `generate/ai-opinions`. `generate/indicators`
  is the only thing that creates indicator rows; it picks up stocks with new or changed prices, so it is
  safe to run as often as you like.
- **Suggested times (NPT, Asia/Kathmandu — check which timezone your cPanel cron uses):**
  fetch/stock-list 06:00 daily; fetch/prices 15:30 and fetch/index 15:32 Mon–Fri (after NEPSE's
  ~15:00 close); generate/indicators 15:40 Mon–Fri; generate/ml-model 03:30,
  generate/backtest-signals 04:00, generate/backtest-next-close 04:15, check/data-quality 04:30 on Mondays.
- **Per-stock:** fetch/histories, fetch/dividends, fetch/fundamentals,
  generate/ai-opinions. Each run processes **every** pending stock, except
  **fetch/histories, fetch/dividends and fetch/fundamentals, which handle ONE stock per run** (a full
  history is about a minute per stock; the others are one or two requests plus a pause each) and report how many are
  still pending: ping again for the next. Each
  stock is saved as it finishes, so if the host cuts a long request short,
  ping again and it resumes. First runs are long (ai-opinions ≈ pending ÷ 2
  minutes, because Groq's free tier fits ~2 stocks/minute and the task waits
  out rate limits); later runs only see new or stale stocks.

The app has no console commands of its own — every task runs through these URLs.

## Where to add new code

| You want to… | Add |
|---|---|
| Call a new nepalstock.com endpoint | `$this->client->get('/api/...')` via `NepalStockClient` — never build the auth headers yourself |
| Fetch from a new website | a service in `Services/DataSources/<Site>/`; fire `ScrapeFinished` (+ `StockPricesUpdated` if it writes prices) |
| Change when a BUY signal may become an order | the limits in `config/trading.php` (min risk/reward, stop buffer, risk per trade, position caps, fees); the check order lives in `BuyOrderService::evaluate()` |
| Add a signal rule | detect it in `SignalGeneratorService::detectRules()`, add its key + **weight** to `SignalRules::RULES`, then re-run generate/backtest-signals and check it beats baseline |
| Add an indicator | calculator in `TechnicalAnalysisService`, store it in `IndicatorRecalculationService` (+ migration) |
| React to something that happened | a listener in `Listeners/`, registered in `AppServiceProvider::LISTENERS` |
| A new background task | a `Task` (or `PerStockTask` for per-stock work) in `Tasks/<Domain>/`. To trigger it by URL, add a route in `routes/web.php` (`->defaults('task', MyTask::class)`); schedule it in cPanel cron; if it needs URL input, override `withRequest()`. To run it from code: `app(TaskRunner::class)->run(app(MyTask::class))`. |
| Swap the AI provider | a new `AiOpinionProvider` implementation + one line in `AppServiceProvider::$bindings` |
| A new portfolio export format | a `PortfolioExporter` implementation + route |
| A new API field | the type + field in `graphql/<area>.graphql` (`@field(resolver: ...)`, `@guard` via the `extend type` block), a FormRequest in `Http/Requests/<Area>/` if it takes input, the logic in a service method, and a one-line resolver method; then add the query to the matching `frontend/src/api/<area>.js` |
| A new file upload/download | a controller action in `Controllers/Api/` + a route in `routes/api.php` (GraphQL is for JSON only) |

## Conventions

- **Style:** Laravel Pint (default preset). Run `vendor/bin/pint` before committing.
- **Config:** `env()` only inside `config/*.php`; code reads `config()` (survives `config:cache`).
- **DI:** constructor/method injection; depend on a `Contracts/` interface where one exists.
- **Comments explain *why*** — especially numbers that came from a backtest.
- **Tests:** `php artisan test` (SQLite in memory). Fake external sources by binding
  the contract in the container, e.g. `$this->app->instance(PriceHistorySource::class, ...)`.

## Deploying changes

Upload changed files, then on the server:

```
composer dump-autoload -o
php artisan migrate --force      # when there's a new migration
php artisan optimize:clear       # clears cached config/routes/events
php artisan lighthouse:clear-cache   # when a graphql/*.graphql file changed
```

After changing indicator or signal logic: open `/cron/generate/indicators?all=1&key=…`,
then `/cron/generate/backtest-signals?key=…`.
