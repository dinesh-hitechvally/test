# Backend architecture

Laravel 13 / PHP 8.3 API for the Share Market SPA (`../frontend`). This file is
the map: where things live, how data flows, and where new code should go.

## Folder map

```
app/
├── Contracts/            Interfaces for swappable parts (AI provider, price-history source).
│                         Bound to implementations in AppServiceProvider::$bindings.
├── Events/               Facts the app announces ("prices changed", "a scrape finished").
├── Listeners/            What happens in response. Wired in AppServiceProvider::LISTENERS.
├── Http/
│   ├── Controllers/Api/  JSON endpoints for the SPA (routes/api.php, auth:sanctum).
│   ├── Controllers/      CronController — plain-text endpoints for the external pinger (routes/web.php).
│   ├── Middleware/       VerifyCronSecret (?key= on every /cron/* URL).
│   └── Requests/         One FormRequest per input, grouped by area.
├── Models/               Eloquent models (one per table).
├── Providers/            AppServiceProvider — contract bindings + event wiring.
└── Services/             All business logic, grouped by what it does:
    ├── DataSources/      Talks to the outside world, writes what it gets back.
    │   ├── NepalStock/   nepalstock.com: auth token, market status, live prices, indices,
    │   │                 ~1yr history, securities list/sectors, dividends.
    │   ├── ShareSansar/  Full price history (the primary history source).
    │   ├── MeroLagani/   Fundamentals (EPS, P/E, book value).
    │   ├── Csv/          CSV price import.
    │   └── CorporateActionsRefreshService
    ├── Analysis/         Derived purely from prices already in the DB — no external calls.
    │   ├── Indicators/   TechnicalAnalysisService (pure math) + IndicatorRecalculationService (stores it).
    │   ├── Signals/      SignalRules (rules + weights), SignalGeneratorService, SignalAccuracyService (backtest).
    │   ├── Forecasting/  NextCloseEstimatorService.
    │   └── RecalculationPipeline   indicators → signals → next-close, for one or many stocks.
    ├── MachineLearning/  Direction predictor (Random Forest) + its feature builder.
    ├── Reports/          Read-side aggregations for the report pages.
    ├── Ai/               AI buy/hold/sell opinion (prompt) + GroqOpinionProvider (the LLM call).
    ├── Portfolio/        Valuation, P&L, and Export/ (CSV, Excel, PDF).
    ├── Auth/             Login geolocation.
    └── Cron/             The URL-triggered pipeline:
        ├── Tasks/        CronTask — one step per URL (market sync, backtests, ML training...).
        │                 PerStockTask — a CronTask that works through every pending stock
        │                 in one run (histories, sectors, dividends, AI opinions, fundamentals).
        └── CronAlertService   Log + optional Slack/email on failure.
```

Tests mirror this: `tests/Feature/{Analysis,Cron,Events,Portfolio}`, `tests/Unit/Analysis`.

## How a request flows

```
SPA ──HTTP──▶ Controllers/Api ──▶ FormRequest (validation) ──▶ Service ──▶ Model/DB
Pinger ─GET─▶ /cron/* ──▶ VerifyCronSecret ──▶ CronController ──▶ CronTaskRunner ──▶ CronTask ──▶ Service
```

Controllers stay thin: validate, call a service, return JSON/text. No business
logic, no `$request->validate()`, no `new SomeService()`.

## Event workflow

Services announce what happened; listeners do the follow-up. All listeners are
**synchronous** (this hosting has no queue worker), so the triggering request
waits for them.

| Event | Fired by | Listener |
|---|---|---|
| `StockPricesUpdated` | market sync, history fetch (either source), CSV import | `RecalculateUpdatedStocks` → RecalculationPipeline for those stocks |
| `ScrapeFinished` | every external fetch, success or failure | `RecordScrapeLog` → `scrape_logs` row |
| `CronTaskFailed` | CronTaskRunner, `/cron/scrape/fetch-history/{symbol}` | `AlertCronFailure` → CronAlertService |

So the daily chain is: **market sync → `StockPricesUpdated` → recalculation**,
all in one request. Listeners are registered explicitly (discovery is off in
`bootstrap/app.php`) so a stale `event:cache` can never silently drop one.

## Cron (no server cron / SSH)

An external pinger (cron-job.org etc.) hits `/cron/*?key=CRON_SECRET`. The live
list with times and ready-to-paste URLs is at **Settings → Data Sources** in the
SPA (`GET /api/schedule`, source of truth: `ScheduleController::JOBS`).

- **Scheduled:** sync-stock-list (06:00 NPT), market-sync-stock (15:30),
  market-sync-index (15:32), train-ml / backtest-signals / backtest-next-close (Mon early morning).
- **On-demand:** fetch-history/{symbol}, market-recalculate (`?all=1` = every
  stock), verify-token.
- **Per-stock (no batches):** fetch-histories, sync-sectors, sync-dividends,
  ai-opinions, fundamentals. Each run processes **every** pending stock; each
  stock is saved as it finishes, so if the host cuts a long request short,
  ping again and it resumes. First runs are long (ai-opinions ≈ pending ÷ 2
  minutes, because Groq's free tier fits ~2 stocks/minute and the task waits
  out rate limits); later runs only see new or stale stocks.

There are no artisan commands for the pipeline — everything runs through these URLs.

## Where to add new code

| You want to… | Add |
|---|---|
| Fetch from a new website | a service in `Services/DataSources/<Site>/`; fire `ScrapeFinished` (+ `StockPricesUpdated` if it writes prices) |
| Add a signal rule | detect it in `SignalGeneratorService::detectRules()`, add its key + **weight** to `SignalRules::RULES`, then re-run backtest-signals and check it beats baseline |
| Add an indicator | calculator in `TechnicalAnalysisService`, store it in `IndicatorRecalculationService` (+ migration) |
| React to something that happened | a listener in `Listeners/`, registered in `AppServiceProvider::LISTENERS` |
| A new scheduled whole-market step | a `CronTask` in `Services/Cron/Tasks/`, a route in `routes/web.php`, an entry in `ScheduleController::JOBS` |
| A new per-stock job | a `PerStockTask` in `Services/Cron/Tasks/` (say what's pending + how to process one stock) + route |
| Swap the AI provider | a new `AiOpinionProvider` implementation + one line in `AppServiceProvider::$bindings` |
| A new portfolio export format | a `PortfolioExporter` implementation + route |
| A new API endpoint | FormRequest in `Http/Requests/<Area>/`, method on the controller, logic in a service |

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
```

After changing indicator or signal logic: open `/cron/reports/market-recalculate?all=1&key=…`,
then `/cron/reports/backtest-signals?key=…`.
