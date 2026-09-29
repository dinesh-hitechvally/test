# Security Policy

## Supported versions

Only the current `main` branch, and what's deployed from it, gets security fixes.

## Reporting a vulnerability

**Please don't open a public issue for a security problem.**

Report it privately through GitHub instead:

1. Go to this repository's **Security** tab.
2. Click **Report a vulnerability**.
3. Describe the problem (see below) and submit.

Only the maintainers can see the report.

### What to include

- What the vulnerability is, and what an attacker could do with it.
- Steps to reproduce, or a proof of concept.
- The affected URL, endpoint or file, if you know it.
- Any logs, screenshots or requests that help.

### What happens next

We'll acknowledge the report, work out how serious it is, and keep you updated until it's fixed. Please give us a reasonable chance to fix it before telling anyone else.

## Areas that matter most

These carry the highest risk, so reports about them are especially welcome:

- **Authentication** — the Sanctum session/cookie login, password reset, and anything that lets one user see or change another user's portfolios, watchlists or saved screens.
- **The `/cron/*` URLs** — these are protected only by `?key=CRON_SECRET`. Anything that runs a task without the right key, or leaks the key, is in scope.
- **Secrets** — database credentials, `APP_KEY`, `CRON_SECRET`, the Groq API key and the Slack webhook live in the server's `.env` and must never appear in the repository, in logs, or in an API response.
- **Outgoing email** — the email log deliberately never stores message bodies, because password-reset emails carry a live reset link.

## For maintainers

- Never commit `.env` files or secrets. If one is committed, **rotate it** — deleting the commit isn't enough, because it stays in git history.
- Keep `APP_DEBUG=false` in production so error pages don't expose stack traces or configuration.
- Keep dependencies up to date (`composer update`, `npm update`) and check for known advisories (`composer audit`, `npm audit`).
