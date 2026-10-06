# Share Market Signals

A NEPSE (Nepal Stock Exchange) research and portfolio platform: daily price sync, technical indicators, rule-based buy/sell signals with their **backtested accuracy shown next to them**, an ML direction predictor, dividend and bonus tracking, and portfolio P&L.

> Signals and predictions here are research tools, not financial advice. Every one is shown with its measured track record — including when it doesn't beat a simple baseline.

## Features

- **Market data** — daily prices from NEPSE's official API (live while the market is open) and ShareSansar (final prices for a date once it's closed); NEPSE index and sub-indices; sectors, dividends and bonus shares; fundamentals (EPS, P/E, book value).
- **Analysis** — SMA/EMA, RSI, MACD, Bollinger Bands and %B, Stochastic, ATR; rule-based signals; next-close estimate; candlestick pattern scan; support/resistance.
- **Accuracy you can check** — walk-forward backtests of the signals and the next-close estimate, published alongside them.
- **Reports** — market, sector, stock, technical, dividend, investment-horizon (long/mid/short term) and an all-lenses Analyst Report.
- **Portfolio** — transactions, holdings, realized/unrealized P&L, XIRR, stop-loss/target alerts, CSV/Excel/PDF exports.
- **Watchlists** with price alerts, saved screens, and an optional AI opinion (Groq).

## Tech stack

| Part | Stack |
|---|---|
| `backend/` | PHP 8.3, Laravel 13, MySQL, Laravel Sanctum (SPA cookie auth), Rubix ML |
| `frontend/` | Vue 3, Vite, Pinia, Vue Router, Chart.js |

The backend is an API only; the frontend is a separate single-page app that calls it.

## Repository layout

```
backend/    Laravel API — see backend/ARCHITECTURE.md for the full map
frontend/   Vue 3 single-page app
.github/    Issue and pull request templates
```

## Getting started

**Requirements:** PHP 8.3+, Composer, MySQL, Node.js 18+ and npm.

### Backend

```bash
cd backend
composer install
cp .env.example .env          # then fill in DB_*, FRONTEND_URL, SANCTUM_STATEFUL_DOMAINS, CRON_SECRET
php artisan key:generate
php artisan migrate
php artisan serve              # http://localhost:8000
```

### Frontend

```bash
cd frontend
npm install
echo "VITE_API_URL=http://localhost:8000" > .env
npm run dev                    # http://localhost:5173
```

For a production build, set `VITE_API_URL` in `frontend/.env.production` and run `npm run build`; upload `frontend/dist/`.

### Loading data

Data is fetched by task URLs under `/cron/*` (they all need `?key=CRON_SECRET`). On a server, schedule them as cPanel cron jobs, e.g.:

```bash
curl -s "https://your-api-host/cron/fetch/prices?key=YOUR_CRON_SECRET" > /dev/null
```

The full list, and when each should run, is in [`backend/ARCHITECTURE.md`](backend/ARCHITECTURE.md#cron-no-server-cron--ssh).

## Running tests

```bash
cd backend
php artisan test
vendor/bin/pint --test         # code style
```

## Documentation

- [`backend/ARCHITECTURE.md`](backend/ARCHITECTURE.md) — folder map, request flow, events, tasks, and where new code goes.
- [`CONTRIBUTING.md`](CONTRIBUTING.md) — how to propose changes.
- [`SECURITY.md`](SECURITY.md) — how to report a vulnerability.
- [`CODE_OF_CONDUCT.md`](CODE_OF_CONDUCT.md)

## License

Proprietary — all rights reserved. See [`LICENSE`](LICENSE). The source is not licensed for use, copying, modification or distribution without written permission.
