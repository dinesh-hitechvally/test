# Share Market Signals — frontend

The Vue 3 single-page app for Share Market Signals. It talks to the Laravel API in `../backend`. See the [main README](../README.md) for the whole project.

## Setup

```bash
npm install
echo "VITE_API_URL=http://localhost:8000" > .env   # where the backend runs
npm run dev                                         # http://localhost:5173
```

## Build

```bash
npm run build      # uses .env.production — set VITE_API_URL there to the live API
```

Upload the contents of `dist/`.

## Where things are

```
src/views/          one file per page
src/components/ui/  building blocks every page uses: PageHeader, Card, StatCard, SortableTh,
                    SignalBadge, StockLink, LoadingState, EmptyState, Pagination, SearchableSelect
                    (registered globally — no import needed)
src/components/     other shared pieces (tables, charts, layout)
src/stores/         Pinia stores
src/api/            the backend API, one module per area (auth, stocks, market, reports,
                    portfolio, watchlists) — each function runs one GraphQL query/mutation
src/api/graphql.js  gql(): POST /graphql; errors come back shaped like axios errors
                    (err.response.status / .data.message / .data.errors)
src/api/fields.js   the field selections the queries share
src/api/client.js   the axios client (session-cookie auth) — also used for the CSV upload
src/utils/format.js formatPrice, formatSignal, changeTone
src/directives/     v-align-numbers — right-aligns number columns in tables
```

Pages call `src/api/<area>.js`, not `gql()` directly. A page needing a new field adds it to the
query there (and to `backend/graphql/` if the API doesn't have it yet).

Put `v-align-numbers` on every new `<table>` so its price and number columns right-align.
