# Contributing

Thanks for helping improve Share Market Signals. This is a proprietary project (see [`LICENSE`](LICENSE)), so code contributions are by invitation — but bug reports and ideas are always welcome from anyone who can see the repository.

By taking part you agree to follow the [Code of Conduct](CODE_OF_CONDUCT.md).

## Reporting bugs and asking for features

Open an issue and pick the matching template (**Bug report** or **Feature request**).

**Found a security problem?** Don't open an issue — follow [`SECURITY.md`](SECURITY.md) instead.

## Making a change

1. **Set up** the project — see [Getting started](README.md#getting-started).
2. **Create a branch** from `main` with a short, descriptive name, e.g. `fix/portfolio-xirr` or `feature/sector-heatmap`.
3. **Make the change**, following the conventions below.
4. **Check it** — run the tests and the style check (below), and try it in the app.
5. **Open a pull request** into `main` and fill in the template.

Keep each pull request to one change. Small pull requests get reviewed faster.

## Conventions

The backend's structure and rules are written up in [`backend/ARCHITECTURE.md`](backend/ARCHITECTURE.md) — read the "Where to add new code" table before adding something new. The short version:

- **The SPA's API is GraphQL** (`backend/graphql/*.graphql`, resolvers in `app/GraphQL/Resolvers/`). Only file uploads/downloads and the `/cron/*` URLs are plain HTTP routes.
- **Resolvers and controllers only call things.** Validate with a FormRequest, call a service/task/event, return the result. No queries or business logic in a resolver or controller.
- **Business logic lives in `app/Services/<Domain>/`.** Background work lives in `app/Tasks/<Domain>/`.
- **Anything that talks to nepalstock.com goes through `NepalStockClient`** — never build the auth headers yourself.
- **React to things with events.** Fire an event (e.g. `StockPricesUpdated`) and add a listener in `AppServiceProvider::LISTENERS`, instead of calling the follow-up directly.
- **Read settings with `config()`, never `env()`,** outside the `config/` folder.
- **New columns on a table that's already in production need a new migration.** Editing an old migration only affects fresh installs.
- **A change to indicator or signal logic must be backtested** — re-run `/cron/generate/backtest-signals` and say in the pull request how the accuracy changed.

**Frontend:**
- Pages get their data from the functions in `src/api/<area>.js`, never by calling `gql()` or axios directly.
- Build pages from the shared components in `src/components/ui/` (`PageHeader`, `Card`, `SortableTh`, `SignalBadge`, `StockLink`, …) and the helpers in `src/utils/format.js`. Don't copy their markup.
- Add `v-align-numbers` to any new `<table>` so its number columns right-align.

## Tests and style

```bash
cd backend
php artisan test         # all tests must pass
vendor/bin/pint          # formats the code (Laravel preset)
```

Add or update tests for what you change. Tests run against an in-memory SQLite database; fake external sites with `Http::fake()` or by binding a contract in the container — never call real APIs from a test.

For frontend changes, make sure `npm run build` succeeds in `frontend/`.

## Commit messages

Write a short summary line in the imperative mood ("Fix XIRR for sold-out positions", not "Fixed…"), and add a body when the *why* isn't obvious.
