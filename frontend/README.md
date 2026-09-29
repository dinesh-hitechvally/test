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
src/views/        one file per page
src/components/   shared pieces (tables, charts, layout)
src/stores/       Pinia stores
src/api/client.js the axios client (session-cookie auth with the backend)
src/directives/   v-align-numbers — right-aligns number columns in tables
```

Put `v-align-numbers` on every new `<table>` so its price and number columns right-align.
