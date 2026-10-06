<h2>Cron jobs</h2>
<p>
    Cron jobs are plain URLs. A scheduler (cPanel cron, cron-job.org, UptimeRobot) requests them on a timetable; each one runs a task and returns a
    plain-text log. There is no Laravel scheduler or server cron, so <strong>scheduling lives in the scheduler, not in code</strong>.
</p>

<div class="card">
    <h4>Calling a cron URL</h4>
    <pre><code>curl -s "{{ $baseUrl }}/cron/fetch/prices?key=YOUR_CRON_SECRET"</code></pre>
    <ul>
        <li><strong>Method:</strong> <span class="tag get">GET</span>. No login or token; the <code>key</code> query parameter must equal <code>CRON_SECRET</code> in <code>.env</code>. A wrong or missing key returns <code>403</code>. (The key is not checked when <code>APP_ENV=local</code>.)</li>
        <li><strong>URL pattern:</strong> <code>/cron/&lt;kind&gt;/&lt;what&gt;</code>, where kind is <code>fetch</code> (pull external data, compute nothing), <code>generate</code> (compute from stored data) or <code>check</code> (health checks).</li>
        <li><strong>Response:</strong> <code>text/plain</code>, always HTTP 200 once the key is accepted:
<pre><code>$ recalculate-market
Recalculated indicators/signals for 42 stock(s).
[ok]</code></pre>
            The last line is <code>[ok]</code> or <code>[failed]</code>. A failing task still answers 200 with <code>[failed]</code>, so set your scheduler to look for that word, not just the status code.</li>
        <li><strong>Safe to repeat:</strong> every job is idempotent. Running one twice does no harm, and unchanged data is skipped.</li>
        <li><strong>Long runs:</strong> jobs ignore the caller disconnecting and have no time limit, so a scheduler that gives up after 30 seconds will not stop a job half-way. Per-stock jobs save each stock as it finishes, so pinging again resumes where it stopped. <code>fetch/histories</code>, <code>fetch/dividends</code> and <code>fetch/fundamentals</code> are the exceptions: they handle <strong>one</strong> stock per ping and tells you how many are still pending.</li>
    </ul>
</div>

<div class="note">
    To run a job from the browser instead of curl, use the <strong>Cron</strong> tab of the <a href="{{ url('console') }}">API console</a>
    (<code>{{ $baseUrl }}/console</code>). It asks you to confirm first, because jobs do real work.
</div>

<h3 id="cron-order">Order &amp; schedule</h3>
<p>Times are suggestions in NPT (Asia/Kathmandu, NEPSE closes about 15:00). Check which timezone your scheduler uses.</p>
<pre><code>06:00  fetch/stock-list                          the stock list everything else works on
15:30  fetch/prices                              final prices once the market has closed
15:32  fetch/index
15:40  generate/indicators                       needs the prices above
15:45  generate/ai-opinions                      needs indicators and signals; slow
03:30  generate/ml-model     04:00 generate/backtest-signals     04:15 generate/backtest-next-close
Mon 04:30  check/data-quality</code></pre>
<div class="note">
    <strong>Fetch jobs only store raw data.</strong> Indicators, signals and next-close estimates exist only after
    <code>generate/indicators</code> runs, so schedule it after every price fetch. Syncing prices alone leaves them stale.
</div>

@foreach ($cron as $kind => $jobs)
    <h3 id="cron-{{ $kind }}">{{ $kind }}/</h3>
    <p class="muted">
        @if ($kind === 'fetch') Pull data from an external source and save it as it is. They never compute anything.
        @elseif ($kind === 'generate') Compute derived data from what is already in the database.
        @else Health checks. @endif
    </p>

    @foreach ($jobs as $job)
        <details class="op" id="cron-{{ Str::slug($job['path']) }}">
            <summary>
                <span class="tag get">GET</span>
                <span class="url">{{ $job['path'] }}</span>
                <span class="muted">{{ $job['when'] }}</span>
            </summary>
            <div class="body">
                @if ($job['note'])<p><strong>{{ $job['note'] }}</strong></p>@endif
                @if ($job['description'])<p class="desc">{{ $job['description'] }}</p>@endif
                <table>
                    <tr><th>Parameter</th><th>In</th><th>Notes</th></tr>
                    @foreach ($job['params'] as $p)
                        <tr><td><code>{{ $p['name'] }}</code></td><td>{{ $p['where'] }}</td><td>{{ $p['description'] }}</td></tr>
                    @endforeach
                </table>
                <p class="muted">Task <code>{{ $job['task'] }}</code> · prints <code>$ {{ $job['name'] }}</code> · log file <code>storage/logs/{{ $job['log'] }}</code></p>
                <pre><code>curl -s "{{ $baseUrl }}{{ preg_replace('/\{(\w+)\}/', '<$1>', $job['path']) }}?key=YOUR_CRON_SECRET"</code></pre>
            </div>
        </details>
    @endforeach
@endforeach

<h3 id="cron-ops">Monitoring &amp; setup</h3>
<table>
    <tr><th>Topic</th><th>How it works</th></tr>
    <tr><td>Logs</td><td>Every run appends <code>[timestamp] [ok|failed] summary</code> to its own file in <code>storage/logs/</code> (name shown on each job above).</td></tr>
    <tr><td>Failure alerts</td><td>A failed run is always written to the Laravel log, and also sent to Slack (<code>SLACK_WEBHOOK_URL</code>) and email (<code>CRON_ALERT_EMAIL</code>) when those are set. Alert delivery failing never breaks the job.</td></tr>
    <tr><td>Scrape history</td><td>Fetch jobs record each run (source, success, rows) in the scrape log, readable with the <code>scrapeLogs</code> and <code>dataSourceStatus</code> queries.</td></tr>
    <tr><td>cPanel</td><td>Add a cron job running <code>curl -s "{{ $baseUrl }}/cron/fetch/prices?key=…" &gt; /dev/null</code> at the time you want. For the weekday jobs use the day-of-week field <code>1-5</code>.</td></tr>
    <tr><td>cron-job.org / UptimeRobot</td><td>Create a GET monitor with the full URL. Add a keyword check for <code>[failed]</code> to be told when a job fails.</td></tr>
    <tr><td>First install</td><td>Run in order by hand: <code>fetch/stock-list</code> (also sets sectors), <code>fetch/histories</code> (long), <code>generate/indicators?all=1</code>, then <code>fetch/dividends</code> and <code>fetch/fundamentals</code>.</td></tr>
    <tr><td>Everything failing at once</td><td>Run <code>check/nepse-token</code> first; an outdated nepalstock.com token breaks every NEPSE fetch.</td></tr>
    <tr><td>After changing indicator or signal logic</td><td>Run <code>generate/indicators?all=1</code>, then <code>generate/backtest-signals</code> and compare accuracy before keeping the change.</td></tr>
</table>
