## What this changes

<!-- What does this pull request do, and why? Link the issue it fixes, e.g. "Fixes #12". -->

## How it was tested

<!-- What did you run or click through to check it works? -->

## Checklist

- [ ] `php artisan test` passes in `backend/`
- [ ] `vendor/bin/pint` has been run (no style changes left)
- [ ] `npm run build` succeeds in `frontend/` (if the frontend changed)
- [ ] Tests added or updated for the change
- [ ] Follows [`backend/ARCHITECTURE.md`](../backend/ARCHITECTURE.md) — thin controllers, logic in services/tasks
- [ ] No secrets, `.env` files or real credentials included
- [ ] New migration included if a table that's already in production changed
- [ ] Signal / indicator changes: backtested, with before/after accuracy noted below

## Deploying

<!-- Anything needed on the server besides uploading the code: migrations, files to delete, php artisan optimize:clear, cPanel cron changes, .env changes. Write "Nothing extra" if none. -->

## Backtest results (signal / indicator changes only)

<!-- Win rate vs. baseline per signal, before and after. Delete this section if it doesn't apply. -->
