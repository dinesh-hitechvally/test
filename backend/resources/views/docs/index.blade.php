<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Documentation · Share Market Signals</title>
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
        [hidden] { display: none !important; } /* class rules like .auth { display: flex } must not defeat the hidden attribute */
        html { scroll-behavior: smooth; scroll-padding-top: 20px; }
        body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.6 system-ui, -apple-system, Segoe UI, sans-serif; }
        a { color: var(--accent); text-decoration: none; }
        a:hover { text-decoration: underline; }

        .shell { display: grid; grid-template-columns: 270px minmax(0, 1fr); }
        nav.side { position: sticky; top: 0; align-self: start; height: 100vh; overflow-y: auto; padding: 22px 14px 40px; border-right: 1px solid var(--line); background: var(--panel); }
        nav.side .brand { font-weight: 700; margin: 0 6px 2px; }
        nav.side .tagline { margin: 0 6px 14px; color: var(--muted); font-size: .8rem; }
        nav.side .acct { display: flex; flex-wrap: wrap; gap: 6px 8px; align-items: center; margin: 0 6px 12px; padding: 7px 9px; border: 1px solid var(--line); border-radius: 8px; font-size: .8rem; background: var(--bg); }
        nav.side .acct .on { color: var(--good); font-weight: 600; }
        nav.side .acct button { margin-left: auto; padding: 2px 9px; border: 1px solid var(--line); border-radius: 6px; background: var(--panel); color: var(--text); font: inherit; cursor: pointer; }
        nav.side .acct button:hover { border-color: var(--accent); }
        nav.side input { width: 100%; padding: 7px 10px; margin: 0 0 12px; border: 1px solid var(--line); border-radius: 8px; background: var(--bg); color: var(--text); font: inherit; font-size: .85rem; }
        nav.side .topic { display: block; margin: 12px 0 2px; padding: 5px 8px; font-weight: 700; font-size: .78rem; text-transform: uppercase; letter-spacing: .05em; color: var(--muted); }
        nav.side a.link { display: block; padding: 4px 8px 4px 16px; border-left: 2px solid transparent; color: var(--text); font-size: .88rem; border-radius: 0 6px 6px 0; }
        nav.side a.link:hover { background: var(--chip); text-decoration: none; }
        nav.side a.link.active { border-left-color: var(--accent); background: var(--chip); color: var(--accent); font-weight: 600; }
        nav.side a.link .n { color: var(--muted); font-size: .75rem; }

        main { padding: 32px clamp(16px, 4vw, 56px) 120px; max-width: 1060px; }
        section.topic-section { padding-top: 8px; margin-bottom: 36px; border-bottom: 1px solid var(--line); }
        section.topic-section:last-child { border-bottom: 0; }
        h2 { margin: 28px 0 8px; font-size: 1.5rem; }
        h3 { margin: 26px 0 6px; font-size: 1.12rem; }
        h4 { margin: 18px 0 4px; font-size: .95rem; }
        p, li { max-width: 80ch; }
        code { font: .86em ui-monospace, SFMono-Regular, Menlo, Consolas, monospace; background: var(--chip); padding: 1px 6px; border-radius: 4px; }
        pre { background: var(--code-bg); color: var(--code-text); padding: 14px 16px; border-radius: 8px; overflow: auto; font-size: .82rem; line-height: 1.5; }
        pre code { background: none; padding: 0; color: inherit; }
        table { width: 100%; border-collapse: collapse; margin: 10px 0 18px; font-size: .88rem; }
        th, td { text-align: left; padding: 8px 10px; border-bottom: 1px solid var(--line); vertical-align: top; }
        th { color: var(--muted); font-weight: 600; font-size: .75rem; text-transform: uppercase; letter-spacing: .04em; }
        .card { background: var(--panel); border: 1px solid var(--line); border-radius: 10px; padding: 14px 18px; margin: 14px 0; }
        .card h3, .card h4 { margin-top: 0; }
        .tag { display: inline-block; font-size: .68rem; font-weight: 700; text-transform: uppercase; padding: 1px 8px; border-radius: 999px; letter-spacing: .04em; vertical-align: middle; }
        .tag.query { background: #dbeafe; color: #1d4ed8; }
        .tag.mutation { background: #fce7f3; color: #be185d; }
        .tag.public, .tag.get { background: #dcfce7; color: #166534; }
        .tag.auth { background: #fef3c7; color: #92400e; }
        .tag.type { background: #e0e7ff; color: #4338ca; }
        .muted { color: var(--muted); }
        .note { border-left: 3px solid var(--warn); background: var(--chip); padding: 10px 14px; border-radius: 0 8px 8px 0; margin: 14px 0; }
        .ok { color: var(--good); } .bad { color: var(--bad); }
        details.op { border: 1px solid var(--line); border-radius: 8px; background: var(--panel); margin: 8px 0; }
        details.op > summary { cursor: pointer; padding: 9px 14px; list-style: none; display: flex; gap: 10px; align-items: baseline; flex-wrap: wrap; }
        details.op > summary::-webkit-details-marker { display: none; }
        details.op[open] > summary { border-bottom: 1px solid var(--line); }
        details.op .body { padding: 4px 16px 12px; }
        .opname { font: 600 .92rem ui-monospace, Menlo, Consolas, monospace; }
        .desc { white-space: pre-line; color: var(--muted); }
        .url { font: 600 .95rem ui-monospace, Menlo, Consolas, monospace; }
        @media (max-width: 860px) {
            .shell { grid-template-columns: 1fr; }
            nav.side { position: static; height: auto; max-height: 50vh; border-right: 0; border-bottom: 1px solid var(--line); }
        }
    </style>
</head>
<body>
<div class="shell">
    <nav class="side" id="toc">
        <div class="brand">Share Market Signals</div>
        <div class="tagline">API &amp; cron documentation</div>
        <div class="acct" id="acct" hidden>
            <span id="acctText"></span>
            <button type="button" id="acctLogout" hidden>Log out</button>
        </div>
        <a class="link" href="{{ url('console') }}" style="font-weight:600">▶ Try the APIs (console)</a>
        <input type="search" id="tocfilter" placeholder="Filter topics…" autocomplete="off" style="margin-top:8px">

        <span class="topic">Getting started</span>
        <a class="link" href="#start">Overview</a>
        <a class="link" href="#auth">Authentication</a>
        <a class="link" href="#errors">Errors</a>
        <a class="link" href="#conventions">Conventions</a>
        <a class="link" href="#flows">Typical flows</a>
        <a class="link" href="#config">Configuration</a>

        <span class="topic">API reference</span>
        <a class="link" href="#api">How to read it</a>
        @foreach ($groups as $group => $ops)
            <a class="link" href="#g-{{ Str::slug($group) }}">{{ $group }} <span class="n">{{ count($ops) }}</span></a>
        @endforeach
        <a class="link" href="#types">Types</a>

        <span class="topic">Buy / sell rules</span>
        <a class="link" href="#trading">Overview</a>
        <a class="link" href="#buy-rules">Buy: signal to order</a>
        <a class="link" href="#sizing">Position sizing</a>
        <a class="link" href="#sell-rules">Sell rules</a>
        <a class="link" href="#trading-config">Limits (trading.php)</a>

        <span class="topic">Cron jobs</span>
        <a class="link" href="#cron">How crons work</a>
        <a class="link" href="#cron-order">Order &amp; schedule</a>
        @foreach ($cron as $kind => $jobs)
            <a class="link" href="#cron-{{ $kind }}">{{ $kind }}/ <span class="n">{{ count($jobs) }}</span></a>
        @endforeach
        <a class="link" href="#cron-ops">Monitoring &amp; setup</a>
    </nav>

    <main>
        <section class="topic-section" id="start">@include('docs.partials.overview')</section>
        <section class="topic-section" id="api">@include('docs.partials.api')</section>
        <section class="topic-section" id="types">@include('docs.partials.types')</section>
        <section class="topic-section" id="trading">@include('docs.partials.trading')</section>
        <section class="topic-section" id="cron">@include('docs.partials.cron')</section>
    </main>
</div>

<script>
    // Who is logged in? Shares the console's saved token (same browser, same origin).
    (function () {
        const base = @json($baseUrl);
        const KEY = 'console_token';
        const box = document.getElementById('acct'), text = document.getElementById('acctText'), out = document.getElementById('acctLogout');
        const read = () => { try { return localStorage.getItem(KEY) || ''; } catch { return ''; } };
        const clear = () => { try { localStorage.removeItem(KEY); } catch {} };
        const call = async (query, token) => {
            try {
                const res = await fetch(base + '/graphql', { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'Authorization': 'Bearer ' + token }, body: JSON.stringify({ query }) });
                return await res.json();
            } catch { return null; }
        };
        const link = '<a href="' + base + '/console">Log in in the console</a>';
        function show(html, withLogout) { box.hidden = false; text.innerHTML = html; out.hidden = !withLogout; }

        async function refresh() {
            const token = read();
            if (!token) { show('Not logged in · ' + link, false); return; }
            const json = await call('{ me { name email } }', token);
            const me = json && json.data && json.data.me;
            if (me) {
                const label = document.createElement('span');
                label.className = 'on';
                label.textContent = '● ' + (me.name || me.email);
                text.replaceChildren(label);
                box.hidden = false; out.hidden = false;
            } else if (json) {
                show('Saved token is no longer valid · ' + link, false);
                clear();
            } else {
                show('API not reachable', false);
            }
        }

        out.addEventListener('click', async () => {
            out.disabled = true;
            const token = read();
            if (token) await call('mutation { logout }', token); // revokes it on the server
            clear();
            out.disabled = false;
            show('Logged out · ' + link, false);
        });
        refresh();
    })();

    // Highlight the topic currently in view.
    const links = [...document.querySelectorAll('nav.side a.link')];
    const targets = links.map(a => document.getElementById(a.getAttribute('href').slice(1))).filter(Boolean);
    function spy() {
        let current = targets[0];
        for (const t of targets) { if (t.getBoundingClientRect().top <= 120) current = t; }
        links.forEach(a => a.classList.toggle('active', a.getAttribute('href') === '#' + current?.id));
        const active = document.querySelector('nav.side a.link.active');
        if (active) active.scrollIntoView({ block: 'nearest' });
    }
    addEventListener('scroll', spy, { passive: true });
    spy();

    // Filter the left-hand topic list.
    document.getElementById('tocfilter').addEventListener('input', e => {
        const q = e.target.value.trim().toLowerCase();
        links.forEach(a => { a.hidden = q && !a.textContent.toLowerCase().includes(q); });
    });

    // Open a collapsed operation/type when linked to or searched for.
    function openFromHash() {
        const el = location.hash && document.querySelector(location.hash);
        if (el && el.tagName === 'DETAILS') el.open = true;
    }
    addEventListener('hashchange', openFromHash);
    openFromHash();
</script>
</body>
</html>
