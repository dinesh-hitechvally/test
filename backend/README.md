# Share Market Signals

A NEPSE (Nepal Stock Exchange) investment platform — daily price sync, technical analysis, rule-based buy/sell signals with honest backtested accuracy, dividend/bonus tracking, portfolio P&L, and an ML direction predictor. Backend: Laravel 13. Frontend: Vue 3 + Vite (`../frontend`).

## Development Log — 2026-09-06

- **Dividend/corporate actions**: built a nepalstock.com fallback for when ShareSansar's dividend/right-share endpoints are blocked (`NepalStockCorporateActionsService`, `CorporateActionsRefreshService`); bulk-backfilled dividend history from 7 → 188 stocks; fixed a face-value bug that showed 200%+ "yields" for mutual fund units (added `stocks.face_value`, captured per-security from NEPSE's own API); reconciled bonus shares into portfolio holdings (`PortfolioValuationService`) so quantity/avg cost/P&L are no longer silently wrong for long-term holders.
- **Signal/ML accuracy**: raised the buy/sell confluence threshold (now requires ≥2 rules agreeing) — buy signals now measurably beat their baseline; retrained the ML direction model on all 309 eligible stocks (was stale at 10) and fixed a real production bug where the resulting model file grew too large to load under normal PHP memory limits (capped tree depth/leaf size, 27MB → 4MB, no accuracy cost).
- **New reports**: Analyst Report (`/reports/analyst/{symbol}`) synthesizing technical + dividend + signal + ML + forecast into one page with a cross-lens consensus check; Dividend Report with a transparent "Top Dividend Picks" ranking; Buy/Sell Signals pages now show real target/stop-loss/risk-reward per stock, not just a bare ticker list.
- **UX**: reorganized the sidebar around customer priority (Portfolio/Watchlist/Signals up top, deeper tools under an "Analyst Tools" section); design-system pass (Inter font, shadows, focus states); replaced every `<select>` with a searchable combobox (`SearchableSelect`); numbered pagination with a "Go to page" jumper (`Pagination`); click-to-sort added across every real data table (`useSortableTable`).
- **Infrastructure**: found and fixed a ~2-day silent outage — the Windows Scheduled Task driving the whole automation pipeline had been failing every minute because its target `run-scheduler.bat` had been deleted; fixed log rotation (was writing one unbounded file).

<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
