<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>API console · Share Market Signals</title>
    <style>
        :root {
            --bg: #f8fafc; --panel: #ffffff; --text: #0f172a; --muted: #64748b; --line: #e2e8f0;
            --accent: #2563eb; --code-bg: #0f172a; --code-text: #e2e8f0; --chip: #eef2ff;
            --good: #15803d; --bad: #b91c1c; --warn: #b45309;
        }
        @media (prefers-color-scheme: dark) {
            :root {
                --bg: #0b1220; --panel: #111a2e; --text: #e2e8f0; --muted: #94a3b8; --line: #1f2a44;
                --accent: #60a5fa; --code-bg: #060b16; --code-text: #e2e8f0; --chip: #1b2547;
                --good: #4ade80; --bad: #f87171; --warn: #fbbf24;
            }
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 14px/1.5 system-ui, -apple-system, Segoe UI, sans-serif; height: 100vh; display: flex; flex-direction: column; }
        a { color: var(--accent); text-decoration: none; } a:hover { text-decoration: underline; }
        code, pre, textarea, .mono { font: 13px/1.5 ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; }
        header { display: flex; flex-wrap: wrap; gap: 10px 16px; align-items: center; padding: 10px 16px; border-bottom: 1px solid var(--line); background: var(--panel); }
        header .title { font-weight: 700; }
        header .spacer { flex: 1; }
        input, select, textarea, button { font: inherit; color: var(--text); }
        input[type=text], input[type=password], input[type=search], input[type=email] { padding: 6px 9px; border: 1px solid var(--line); border-radius: 7px; background: var(--bg); min-width: 0; }
        button { padding: 6px 12px; border: 1px solid var(--line); border-radius: 7px; background: var(--panel); cursor: pointer; }
        button:hover { border-color: var(--accent); }
        button.primary { background: var(--accent); border-color: var(--accent); color: #fff; font-weight: 600; }
        button:disabled { opacity: .5; cursor: default; }
        .auth { display: flex; flex-wrap: wrap; gap: 6px; align-items: center; }
        .auth .state { font-size: .8rem; }
        .banner { padding: 9px 16px; font-size: .9rem; font-weight: 600; border-bottom: 1px solid var(--line); white-space: pre-line; }
        .banner.ok { background: #dcfce7; color: #166534; } .banner.bad { background: #fee2e2; color: #991b1b; } .banner.info { background: var(--chip); color: var(--text); }
        .ok { color: var(--good); } .bad { color: var(--bad); } .warn { color: var(--warn); } .muted { color: var(--muted); }

        .layout { flex: 1; display: grid; grid-template-columns: 300px minmax(0, 1fr); min-height: 0; }
        aside { border-right: 1px solid var(--line); background: var(--panel); display: flex; flex-direction: column; min-height: 0; }
        .tabs { display: flex; border-bottom: 1px solid var(--line); }
        .tabs button { flex: 1; border: 0; border-radius: 0; border-bottom: 2px solid transparent; background: none; padding: 9px 4px; }
        .tabs button.on { border-bottom-color: var(--accent); color: var(--accent); font-weight: 600; }
        .list { overflow: auto; padding: 6px 8px 24px; flex: 1; }
        .list input { width: 100%; margin: 8px 0; }
        .list h4 { margin: 12px 4px 3px; font-size: .72rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
        .item { display: flex; gap: 6px; align-items: baseline; padding: 4px 8px; border-radius: 6px; cursor: pointer; }
        .item:hover { background: var(--chip); }
        .item.on { background: var(--chip); color: var(--accent); font-weight: 600; }
        .item .k { font-size: .62rem; font-weight: 700; text-transform: uppercase; padding: 0 5px; border-radius: 999px; }
        .k.query { background: #dbeafe; color: #1d4ed8; } .k.mutation { background: #fce7f3; color: #be185d; } .k.get { background: #dcfce7; color: #166534; }
        .item .name { overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }

        main { overflow: auto; padding: 16px 20px 40px; min-width: 0; }
        .pane { display: none; } .pane.on { display: block; }
        h2 { margin: 0 0 4px; font-size: 1.15rem; } h3 { margin: 18px 0 6px; font-size: .95rem; }
        textarea { width: 100%; padding: 10px; border: 1px solid var(--line); border-radius: 8px; background: var(--code-bg); color: var(--code-text); resize: vertical; tab-size: 2; }
        .row { display: flex; flex-wrap: wrap; gap: 8px; align-items: center; margin: 10px 0; }
        .status { font-size: .85rem; }
        pre.out { background: var(--code-bg); color: var(--code-text); padding: 12px 14px; border-radius: 8px; overflow: auto; max-height: 55vh; margin: 8px 0; white-space: pre-wrap; word-break: break-word; }
        table { width: 100%; border-collapse: collapse; font-size: .85rem; }
        th, td { text-align: left; padding: 6px 8px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-size: .72rem; text-transform: uppercase; letter-spacing: .04em; }
        .pill { display: inline-block; padding: 0 8px; border-radius: 999px; font-size: .72rem; font-weight: 700; }
        .pill.pass { background: #dcfce7; color: #166534; } .pill.warn { background: #fef3c7; color: #92400e; } .pill.fail { background: #fee2e2; color: #991b1b; }
        .summary { display: flex; gap: 16px; margin: 10px 0; font-weight: 600; }
        .note { border-left: 3px solid var(--warn); background: var(--chip); padding: 8px 12px; border-radius: 0 8px 8px 0; margin: 10px 0; font-size: .85rem; }
        .params { display: flex; flex-wrap: wrap; gap: 8px 16px; margin: 8px 0; }
        .params label { display: flex; flex-direction: column; gap: 2px; font-size: .78rem; color: var(--muted); }
        @media (max-width: 820px) { .layout { grid-template-columns: 1fr; } aside { max-height: 40vh; border-right: 0; border-bottom: 1px solid var(--line); } }
    </style>
</head>
<body>
<header>
    <span class="title">API console</span>
    <a href="{{ url('docs') }}">← Documentation</a>
    <span class="muted mono" id="endpoint"></span>
    <span class="spacer"></span>
    <form class="auth" id="loginForm" autocomplete="on">
        <input type="text" id="email" placeholder="email" autocomplete="username" size="18">
        <input type="password" id="password" placeholder="password" autocomplete="current-password" size="12">
        <button class="primary" type="submit">Log in</button>
        <input type="text" id="token" placeholder="…or paste a bearer token" size="22" class="mono">
        <button type="button" id="logout">Clear</button>
        <span class="state" id="authState"></span>
    </form>
</header>
<div id="authBanner" class="banner" hidden></div>

<div class="layout">
    <aside>
        <div class="tabs">
            <button class="on" data-tab="gql">GraphQL</button>
            <button data-tab="all">Check all</button>
            <button data-tab="cron">Cron</button>
        </div>
        <div class="list" id="gqlList">
            <input type="search" id="gqlFilter" placeholder="Filter operations…" autocomplete="off">
            @foreach ($groups as $group => $ops)
                <h4>{{ $group }}</h4>
                @foreach ($ops as $op)
                    <div class="item" data-op="{{ $op['name'] }}" data-search="{{ strtolower($op['name']) }}">
                        <span class="k {{ $op['kind'] }}">{{ $op['kind'] === 'query' ? 'Q' : 'M' }}</span>
                        <span class="name">{{ $op['name'] }}</span>
                    </div>
                @endforeach
            @endforeach
        </div>
        <div class="list" id="cronList" hidden>
            @foreach ($cron as $kind => $jobs)
                <h4>{{ $kind }}/</h4>
                @foreach ($jobs as $job)
                    <div class="item" data-cron="{{ $job['path'] }}">
                        <span class="k get">GET</span>
                        <span class="name">{{ \Illuminate\Support\Str::after($job['path'], '/cron/') }}</span>
                    </div>
                @endforeach
            @endforeach
        </div>
        <div class="list" id="allList" hidden>
            <p class="muted" style="padding: 8px">Runs every query below with sample values and reports which ones respond.</p>
        </div>
    </aside>

    <main>
        {{-- GraphQL tester --}}
        <div class="pane on" id="pane-gql">
            <h2 id="opTitle">Pick an operation</h2>
            <div class="muted" id="opDesc">Choose one on the left, or write any query below. Ctrl + Enter runs it.</div>
            <h3>Query</h3>
            <textarea id="query" rows="9" spellcheck="false">{ me { id name email } }</textarea>
            <h3>Variables (JSON)</h3>
            <textarea id="variables" rows="4" spellcheck="false">{}</textarea>
            <div class="row">
                <button class="primary" id="run">Run ▶</button>
                <span class="status" id="runStatus"></span>
            </div>
            <pre class="out" id="response">Response appears here.</pre>
        </div>

        {{-- Check all --}}
        <div class="pane" id="pane-all">
            <h2>Check all queries</h2>
            <p class="muted">
                Runs every <strong>query</strong> once with sample values (stock <code>NABIL</code>, id <code>1</code>…) and shows how each responds.
                Mutations are skipped because they change data. Log in first: most queries need a token.
            </p>
            <div class="note">
                <strong>pass</strong> = data came back. <strong>warn</strong> = the API answered but the sample value had no match (404 / 422 / empty), so the operation is reachable but needs real input.
                <strong>fail</strong> = a server error, an unreadable response or a network failure.
            </div>
            <div class="row">
                <button class="primary" id="runAll">Run all queries</button>
                <button id="stopAll" disabled>Stop</button>
                <span class="status" id="allStatus"></span>
            </div>
            <div class="summary" id="allSummary"></div>
            <table id="allTable" hidden>
                <thead><tr><th>Query</th><th>Result</th><th>Time</th><th>Detail</th></tr></thead>
                <tbody></tbody>
            </table>
        </div>

        {{-- Cron --}}
        <div class="pane" id="pane-cron">
            <h2 id="cronTitle">Pick a cron job</h2>
            <div class="muted" id="cronDesc">Cron jobs are real: running one fetches data or recalculates exactly as the scheduler would.</div>
            <div class="params">
                <label>Cron key (CRON_SECRET) <input type="password" id="cronKey" size="28" autocomplete="off"></label>
                <label id="symbolBox" hidden>symbol <input type="text" id="cronSymbol" size="10" value="NABIL"></label>
                <label id="allBox" hidden>all <select id="cronAll"><option value="">not set</option><option value="1">1 (every stock)</option></select></label>
            </div>
            <div class="mono muted" id="cronUrl"></div>
            <div class="row">
                <button class="primary" id="cronRun" disabled>Run job ▶</button>
                <span class="status" id="cronStatus"></span>
            </div>
            <pre class="out" id="cronOut">Response appears here.</pre>
        </div>
    </main>
</div>

<script type="application/json" id="data">@json(['groups' => $groups, 'cron' => $cron, 'base' => $baseUrl])</script>
@verbatim
<script>
    const DATA = JSON.parse(document.getElementById('data').textContent);
    const OPS = {};
    Object.values(DATA.groups).flat().forEach(o => OPS[o.name] = o);
    const CRON = {};
    Object.values(DATA.cron).flat().forEach(j => CRON[j.path] = j);
    const $ = id => document.getElementById(id);
    const origin = DATA.base; // scheme + host + any sub-folder the app is served from
    $('endpoint').textContent = 'POST ' + origin + '/graphql';

    // ---- storage that never throws (private windows, blocked storage) ----
    const store = {
        get: (k, s = localStorage) => { try { return s.getItem(k) || ''; } catch { return ''; } },
        set: (k, v, s = localStorage) => { try { s.setItem(k, v); } catch {} },
        del: (k, s = localStorage) => { try { s.removeItem(k); } catch {} },
    };

    // ---- auth ----
    let token = store.get('console_token');
    function banner(msg, cls) {
        const el = $('authBanner');
        el.hidden = !msg;
        el.className = 'banner ' + (cls || 'info');
        el.textContent = msg || '';
    }
    function showAuth(msg, cls) {
        $('token').value = token;
        const el = $('authState');
        el.className = 'state ' + (cls || (token ? 'ok' : 'muted'));
        el.textContent = msg || (token ? 'token set' : 'not logged in');
    }
    // Asks the API who the token belongs to, so "logged in" always means the API accepted it.
    async function verify(prefix) {
        const r = await gql('{ me { name email } }');
        const me = r.json?.data?.me;
        if (me) {
            showAuth('logged in as ' + (me.name || me.email), 'ok');
            banner((prefix || 'Logged in') + ' as ' + me.name + ' (' + me.email + '). Requests now carry your token.', 'ok');
        } else if (token) {
            showAuth('token rejected', 'bad');
            banner('The API did not accept this token (HTTP ' + r.http + '). It may have expired or been revoked: log in again.', 'bad');
        } else {
            showAuth();
        }
    }
    function loginError(r) {
        if (r.network) return 'Could not reach ' + origin + '/graphql: ' + r.text;
        if (!r.json) return 'The server did not return JSON (HTTP ' + r.http + '). First 300 characters:\n' + r.text.slice(0, 300);
        const err = r.json.errors?.[0];
        if (!err) return 'Unexpected response (HTTP ' + r.http + '): ' + r.text.slice(0, 300);
        const fields = err.extensions?.validation
            ? '\n' + Object.entries(err.extensions.validation).map(([k, v]) => k + ': ' + [].concat(v).join(' ')).join('\n')
            : '';
        return 'Login failed: ' + err.message + fields;
    }
    $('token').addEventListener('input', e => { token = e.target.value.trim(); store.set('console_token', token); showAuth(); });
    $('token').addEventListener('change', () => { if (token) verify('Token accepted'); });
    $('logout').addEventListener('click', () => { token = ''; store.del('console_token'); showAuth(); banner('Token cleared.', 'info'); });
    $('loginForm').addEventListener('submit', async e => {
        e.preventDefault();
        const email = $('email').value.trim(), password = $('password').value;
        if (!email || !password) { banner('Enter both your email and password.', 'bad'); return; }
        showAuth('logging in…', 'muted');
        banner('Logging in…', 'info');
        const r = await gql('mutation($e:String,$p:String){ login(email:$e,password:$p){ token } }', { e: email, p: password }, false);
        const t = r.json?.data?.login?.token;
        if (t) {
            token = t; store.set('console_token', t); $('password').value = '';
            await verify('Logged in');
        } else {
            showAuth('login failed', 'bad');
            banner(loginError(r), 'bad');
        }
    });
    showAuth();
    if (token) verify('Using the saved token');

    // ---- one GraphQL request; never throws ----
    async function gql(query, variables, withToken = true) {
        const started = performance.now();
        const headers = { 'Content-Type': 'application/json', 'Accept': 'application/json' };
        if (withToken && token) headers['Authorization'] = 'Bearer ' + token;
        try {
            const res = await fetch(origin + '/graphql', { method: 'POST', headers, body: JSON.stringify({ query, variables }) });
            const text = await res.text();
            let json = null;
            try { json = JSON.parse(text); } catch {}
            return { http: res.status, ms: Math.round(performance.now() - started), text, json, size: text.length };
        } catch (err) {
            return { http: 0, ms: Math.round(performance.now() - started), text: String(err), json: null, size: 0, network: true };
        }
    }

    // ---- tabs ----
    document.querySelectorAll('.tabs button').forEach(b => b.addEventListener('click', () => {
        document.querySelectorAll('.tabs button').forEach(x => x.classList.toggle('on', x === b));
        const tab = b.dataset.tab;
        $('gqlList').hidden = tab !== 'gql';
        $('cronList').hidden = tab !== 'cron';
        $('allList').hidden = tab !== 'all';
        ['gql', 'all', 'cron'].forEach(t => $('pane-' + t).classList.toggle('on', t === tab));
    }));

    // ---- GraphQL tester ----
    function pick(name) {
        const op = OPS[name];
        document.querySelectorAll('#gqlList .item').forEach(i => i.classList.toggle('on', i.dataset.op === name));
        $('opTitle').textContent = op.name;
        $('opDesc').textContent = (op.kind === 'query' ? 'Query' : 'Mutation') + (op.public ? ' (public)' : ' (needs a token)') + ' · ' + (op.description || '');
        $('query').value = op.console_query;
        $('variables').value = JSON.stringify(op.sample_variables, null, 2);
        $('response').textContent = 'Response appears here.';
        $('runStatus').textContent = '';
    }
    document.querySelectorAll('#gqlList .item').forEach(i => i.addEventListener('click', () => pick(i.dataset.op)));
    $('gqlFilter').addEventListener('input', e => {
        const q = e.target.value.trim().toLowerCase();
        document.querySelectorAll('#gqlList .item').forEach(i => i.hidden = q && !i.dataset.search.includes(q));
    });

    function describe(r) {
        const gqlStatus = r.json?.errors?.[0]?.extensions?.status;
        const bits = ['HTTP ' + r.http];
        if (gqlStatus) bits.push('GraphQL status ' + gqlStatus);
        bits.push(r.ms + ' ms', (r.size / 1024).toFixed(1) + ' KB');
        const ok = r.http === 200 && r.json && !r.json.errors;
        return { ok, text: (ok ? '✓ ' : '✗ ') + bits.join(' · '), cls: ok ? 'ok' : 'bad' };
    }

    async function run() {
        let variables = {};
        try { variables = JSON.parse($('variables').value || '{}'); }
        catch (e) { $('runStatus').className = 'status bad'; $('runStatus').textContent = 'Variables are not valid JSON: ' + e.message; return; }
        $('run').disabled = true;
        $('runStatus').className = 'status muted';
        $('runStatus').textContent = 'running…';
        const r = await gql($('query').value, variables);
        const d = describe(r);
        $('runStatus').className = 'status ' + d.cls;
        $('runStatus').textContent = d.text;
        $('response').textContent = r.json ? JSON.stringify(r.json, null, 2) : r.text;
        $('run').disabled = false;
    }
    $('run').addEventListener('click', run);
    $('query').addEventListener('keydown', e => { if (e.ctrlKey && e.key === 'Enter') run(); });
    $('variables').addEventListener('keydown', e => { if (e.ctrlKey && e.key === 'Enter') run(); });

    // ---- check all ----
    let stopping = false;
    function classify(r) {
        if (r.network || r.http >= 500 || (r.http !== 200 && r.http !== 401) || !r.json) return ['fail', r.network ? r.text : 'HTTP ' + r.http + (r.json ? '' : ' (not JSON)')];
        if (r.http === 401) return ['warn', 'HTTP 401: log in first'];
        const err = r.json.errors?.[0];
        if (!err) return ['pass', 'data returned'];
        const status = err.extensions?.status;
        if (status === 401) return ['warn', 'not authenticated: log in first'];
        if (status === 404 || status === 422) return ['warn', status + ': ' + err.message];
        if (/Cannot query field|Unknown (type|argument)|Variable/.test(err.message)) return ['fail', err.message];
        return ['fail', (status ? status + ': ' : '') + err.message];
    }
    $('runAll').addEventListener('click', async () => {
        const queries = Object.values(OPS).filter(o => o.kind === 'query');
        const body = $('allTable').querySelector('tbody');
        body.innerHTML = '';
        $('allTable').hidden = false;
        stopping = false;
        $('runAll').disabled = true; $('stopAll').disabled = false;
        const tally = { pass: 0, warn: 0, fail: 0 };
        let done = 0;
        for (const op of queries) {
            if (stopping) break;
            $('allStatus').textContent = 'running ' + (++done) + ' / ' + queries.length + ' · ' + op.name;
            const r = await gql(op.console_query, op.sample_variables);
            const [cls, detail] = classify(r);
            tally[cls]++;
            const tr = document.createElement('tr');
            tr.innerHTML = '<td class="mono"></td><td><span class="pill ' + cls + '">' + cls + '</span></td><td>' + r.ms + ' ms</td><td class="muted"></td>';
            tr.children[0].textContent = op.name;
            tr.children[3].textContent = detail;
            body.appendChild(tr);
            $('allSummary').innerHTML = '<span class="ok">' + tally.pass + ' pass</span><span class="warn">' + tally.warn + ' warn</span><span class="bad">' + tally.fail + ' fail</span>';
        }
        $('allStatus').textContent = stopping ? 'stopped' : 'done: ' + queries.length + ' queries';
        $('runAll').disabled = false; $('stopAll').disabled = true;
    });
    $('stopAll').addEventListener('click', () => { stopping = true; });

    // ---- cron ----
    $('cronKey').value = store.get('console_cron_key', sessionStorage);
    $('cronKey').addEventListener('input', e => store.set('console_cron_key', e.target.value, sessionStorage));
    let cronJob = null;
    function cronUrl() {
        if (!cronJob) return '';
        let path = cronJob.path.replace(/\{(\w+)\}/g, () => encodeURIComponent($('cronSymbol').value.trim()));
        const q = new URLSearchParams();
        if ($('cronKey').value) q.set('key', $('cronKey').value);
        if ($('cronAll').value && !$('allBox').hidden) q.set('all', $('cronAll').value);
        return path + '?' + q.toString();
    }
    function refreshCronUrl() {
        $('cronUrl').textContent = cronJob ? 'GET ' + cronUrl().replace(/key=[^&]*/, 'key=•••') : '';
    }
    document.querySelectorAll('#cronList .item').forEach(i => i.addEventListener('click', () => {
        cronJob = CRON[i.dataset.cron];
        document.querySelectorAll('#cronList .item').forEach(x => x.classList.toggle('on', x === i));
        $('cronTitle').textContent = cronJob.path;
        $('cronDesc').textContent = [cronJob.when, cronJob.note].filter(Boolean).join(' · ') || 'Runs for real, as the scheduler would.';
        $('symbolBox').hidden = !cronJob.path.includes('{');
        $('allBox').hidden = !cronJob.params.some(p => p.name === 'all');
        $('cronRun').disabled = false;
        $('cronOut').textContent = 'Response appears here.';
        $('cronStatus').textContent = '';
        refreshCronUrl();
    }));
    ['cronKey', 'cronSymbol', 'cronAll'].forEach(id => $(id).addEventListener('input', refreshCronUrl));
    $('cronRun').addEventListener('click', async () => {
        if (!confirm('This runs the job for real (' + cronJob.path + '). Continue?')) return;
        $('cronRun').disabled = true;
        $('cronStatus').className = 'status muted';
        $('cronStatus').textContent = 'running… long jobs can take minutes';
        const started = performance.now();
        try {
            const res = await fetch(origin + cronUrl(), { headers: { Accept: 'text/plain' } });
            const text = await res.text();
            const failed = /\[failed\]\s*$/.test(text) || res.status !== 200;
            $('cronStatus').className = 'status ' + (failed ? 'bad' : 'ok');
            $('cronStatus').textContent = (failed ? '✗ ' : '✓ ') + 'HTTP ' + res.status + ' · ' + Math.round(performance.now() - started) + ' ms';
            $('cronOut').textContent = text;
        } catch (err) {
            $('cronStatus').className = 'status bad';
            $('cronStatus').textContent = '✗ network error';
            $('cronOut').textContent = String(err);
        }
        $('cronRun').disabled = false;
    });
</script>
@endverbatim
</body>
</html>
