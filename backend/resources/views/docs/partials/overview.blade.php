<h2>Getting started</h2>
<p>
    This backend has two interfaces: a <strong>GraphQL API</strong>, used by the web app and by anything else that wants the data,
    and a set of <strong>cron URLs</strong> that fetch market data and generate indicators, signals and forecasts.
    This page is generated from the live GraphQL schema and the registered routes, so it matches the running code.
    To try any operation or cron job from the browser, open the <a href="{{ url('console') }}">API console</a>.
</p>

<table>
    <tr><th></th><th>GraphQL API</th><th>Cron URLs</th></tr>
    <tr><td>Address</td><td><code>POST {{ $baseUrl }}/graphql</code></td><td><code>GET {{ $baseUrl }}/cron/&lt;kind&gt;/&lt;what&gt;</code></td></tr>
    <tr><td>Called by</td><td>The web app, mobile apps, scripts</td><td>A scheduler: cPanel cron, cron-job.org, UptimeRobot</td></tr>
    <tr><td>Auth</td><td>Bearer token from <code>login</code></td><td><code>?key=CRON_SECRET</code></td></tr>
    <tr><td>Returns</td><td>JSON</td><td>Plain-text log</td></tr>
    <tr><td>Try it</td><td colspan="2"><a href="{{ url('console') }}"><code>{{ $baseUrl }}/console</code></a> — log in, run any operation, run every query as a check, or fire a cron job</td></tr>
    <tr><td>See</td><td><a href="#api">API reference</a>, <a href="#trading">Buy / sell rules</a></td><td><a href="#cron">Cron jobs</a></td></tr>
</table>

<h3 id="auth">Authentication</h3>
<p><strong>1. Log in</strong> to get a token:</p>
<pre><code>curl -X POST {{ $baseUrl }}/graphql \
  -H "Content-Type: application/json" \
  -d '{"query":"mutation($e:String,$p:String){ login(email:$e, password:$p){ token token_type user{ id name } } }",
       "variables":{"e":"you@example.com","p":"secret"}}'</code></pre>
<p>The response has <code>data.login.token</code>. It stays valid until you call <code>logout</code>.</p>
<p><strong>2. Send it</strong> on every other request:</p>
<pre><code>curl -X POST {{ $baseUrl }}/graphql \
  -H "Authorization: Bearer &lt;token&gt;" \
  -H "Content-Type: application/json" \
  -d '{"query":"{ portfolios { id name cash_balance summary { current_value total_pnl } } }"}'</code></pre>
<p>Only <code>login</code>, <code>forgotPassword</code>, <code>resetPassword</code> and <code>me</code> work without a token.</p>

<h3 id="errors">Errors</h3>
<p>GraphQL answers <strong>HTTP 200 even for errors</strong>. Always check the <code>errors</code> array in the body:</p>
<pre><code>{
  "data": null,
  "errors": [{
    "message": "Free cash 500.00 cannot buy even one share at 820.00.",
    "extensions": { "status": 422 }
  }]
}</code></pre>
<table>
    <tr><th><code>extensions.status</code></th><th>Meaning</th></tr>
    <tr><td>401</td><td>Missing, expired or revoked token. Log in again.</td></tr>
    <tr><td>404</td><td>The stock or portfolio does not exist, or is not yours.</td></tr>
    <tr><td>422</td><td>Input rejected. <code>extensions.validation</code> maps each field to its messages, e.g. <code>{"quantity": ["The quantity must be at least 1."]}</code>. Business-rule refusals, such as a failed buy check, also use 422 with the reason as <code>message</code>.</td></tr>
    <tr><td>502</td><td>An upstream data source (nepalstock.com and similar) failed.</td></tr>
</table>

<h3 id="conventions">Conventions</h3>
<ul>
    <li><strong>Numbers.</strong> Database decimals (prices, percentages) arrive as <strong>strings</strong> exactly as stored, e.g. <code>"567.0000"</code>. Computed numbers (P&amp;L, ratios) arrive as floats. Parse prices before doing arithmetic.</li>
    <li><strong>Dates.</strong> <code>YYYY-MM-DD</code> for trading days, ISO 8601 for timestamps.</li>
    <li><strong>Ownership.</strong> Portfolios and watchlists belong to the logged-in user. Another user's id returns 404, never their data.</li>
    <li><strong>Stocks.</strong> Most operations take a <code>symbol</code> (<code>NABIL</code>, case-insensitive). Portfolio ledger operations take the numeric <code>stock_id</code>.</li>
    <li><strong>Read-only data.</strong> The API only reads what the cron jobs have stored. Calling it never triggers a fetch or a recalculation.</li>
    <li><strong>Explore interactively.</strong> The schema is introspectable: point GraphiQL, Insomnia or Postman at <code>{{ $baseUrl }}/graphql</code> with the bearer header. <code>php artisan lighthouse:print-schema</code> prints the whole schema.</li>
</ul>

<h3 id="flows">Typical flows</h3>
<div class="card">
    <h4>From a buy signal to a logged position, and out again</h4>
    <p class="muted">This system gives advice and keeps records. It never buys or sells; you trade with your broker.</p>
    <ol>
        <li><code>actionableSignals(bias: "buy")</code> lists stocks with a buy signal.</li>
        <li><code>tradePlan(portfolio_id, symbol)</code> runs the signal through the risk, portfolio and cash checks and returns a plan: entry, stop, target and quantity, or the reason there isn't one. Nothing is placed.</li>
        <li>You buy through your broker, then log it with <code>addTransaction</code>. If the holding has no levels yet, its stop and target are set from the stock's support and resistance.</li>
        <li><code>sellChecks(portfolio_id)</code> says, per holding, sell or hold and why. Record the sale with <code>addTransaction</code> (type <code>sell</code>).</li>
    </ol>
    <p class="muted">Every rule is explained in <a href="#trading">Buy / sell rules</a>.</p>
</div>
<div class="card">
    <h4>Where the data comes from</h4>
    <ol>
        <li><code>/cron/fetch/*</code> jobs pull raw prices, indices, dividends and so on into the database.</li>
        <li><code>/cron/generate/indicators</code> turns stored prices into indicators and next-close estimates, then <code>/cron/generate/signals</code> turns the indicators into buy / sell / hold signals.</li>
        <li>The API serves what is stored. Schedules and order are in <a href="#cron-order">Cron jobs</a>.</li>
    </ol>
</div>

<h3 id="config">Configuration (.env)</h3>
<table>
    <tr><th>Key</th><th>Purpose</th></tr>
    <tr><td><code>CRON_SECRET</code></td><td>Required. The value every <code>/cron/*</code> URL must carry as <code>?key=</code>. If unset, every cron request is refused (except on <code>APP_ENV=local</code>).</td></tr>
    <tr><td><code>FRONTEND_URL</code></td><td>Allowed CORS origin and base of password-reset links.</td></tr>
    <tr><td><code>GROQ_API_KEY</code>, <code>GROQ_MODEL</code></td><td>Optional. Enables AI opinions (<code>/cron/generate/ai-opinions</code>).</td></tr>
    <tr><td><code>SLACK_WEBHOOK_URL</code>, <code>CRON_ALERT_EMAIL</code></td><td>Optional. Where failed cron jobs are reported.</td></tr>
    <tr><td><code>DOCS_ENABLED</code></td><td>Set to <code>false</code> to hide this page (it then returns 404).</td></tr>
    <tr><td><code>config/trading.php</code></td><td>Buy and sell rule limits. See <a href="#trading-config">Limits</a>.</td></tr>
</table>
