<?php

return [
    // The /docs pages (API reference, trading rules, cron jobs). They contain no secrets,
    // but set DOCS_ENABLED=false to hide them on a server where the API shouldn't be advertised.
    'enabled' => (bool) env('DOCS_ENABLED', true),
];
