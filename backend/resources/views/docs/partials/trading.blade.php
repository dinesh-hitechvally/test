<h2>Buy / sell rules</h2>
<p>
    Signals are only a starting point. A <strong>buy</strong> signal has to pass risk, portfolio and cash checks and be sized by risk before it
    becomes an order. Every holding is then watched by <strong>five sell rules</strong>. Both are advisory: the API creates plans and
    recommendations, it never trades. All limits live in <code>config/trading.php</code> and are listed <a href="#trading-config">below</a>.
</p>
<div class="note">Not financial advice. These are mechanical rules; check the numbers before acting on them.</div>

<h3 id="buy-rules">Buy: from signal to order</h3>
<p>
    <code>buyOrderPreview</code> runs the checks and stores nothing. <code>placeBuyOrder</code> runs the same checks and, if they pass,
    stores a <em>pending</em> order. The checks run in this order and <strong>stop at the first failure</strong>, whose message is returned as
    <code>reason</code> (preview) or as the 422 error (place).
</p>
<table>
    <tr><th>#</th><th>Check</th><th>Passes when</th></tr>
    <tr><td>1</td><td><strong>Signal</strong></td><td>The latest signal is <code>buy</code> or <code>strong_buy</code>, its score is at least <code>{{ $t['min_score'] }}</code>, and it is no more than {{ $t['signal_max_age_days'] }} days older than the latest price.</td></tr>
    <tr><td>2</td><td><strong>Risk</strong></td><td>
        There is support below and resistance above the price (from the signal day's indicators), the stop is no more than {{ $t['max_stop_distance_pct'] }}% under the entry,
        and risk/reward is at least <code>{{ $t['min_risk_reward'] }}</code>.
    </td></tr>
    <tr><td>3</td><td><strong>Portfolio</strong></td><td>The stock is not already held or already on a pending order, and holdings plus pending orders are below {{ $t['max_positions'] }}.</td></tr>
    <tr><td>4</td><td><strong>Cash</strong></td><td>Free cash (cash balance minus what pending orders reserve) covers at least one share plus fees.</td></tr>
    <tr><td>5</td><td><strong>Position size</strong></td><td>The size works out to at least 1 share (see <a href="#sizing">Position sizing</a>).</td></tr>
</table>

<h4>How the levels are set</h4>
<table>
    <tr><th>Level</th><th>Rule</th></tr>
    <tr><td>Entry</td><td>The latest close.</td></tr>
    <tr><td>Stop-loss</td><td>The nearest support, less {{ $t['stop_buffer_pct'] }}% so a dip into support does not stop you out.</td></tr>
    <tr><td>Target</td><td>The nearest resistance above the price. With no resistance above, there is no target and the trade is refused.</td></tr>
    <tr><td>Risk/reward</td><td><code>(target − entry) ÷ (entry − stop)</code></td></tr>
</table>

<h4>Worked example</h4>
<p>NRN at Rs. 820, support 780, resistance 900, signal <code>buy</code> with score 0.74, portfolio of Rs. 10,00,000 in cash:</p>
<pre><code>stop        = 780 × (1 − 0.5%)      = 776.10
risk/share  = 820 − 776.10          = 43.90
risk/reward = (900 − 820) ÷ 43.90   = 1.82   ≥ {{ $t['min_risk_reward'] }}  ✓
shares      = see Position sizing   = 227</code></pre>

<h4>Order lifecycle</h4>
<table>
    <tr><th>Status</th><th>Meaning</th></tr>
    <tr><td><code>pending</code></td><td>Planned and approved. Reserves its cost as unavailable cash and counts as a position.</td></tr>
    <tr><td><code>executed</code></td><td>You recorded a buy for that stock with <code>addTransaction</code>. The order's stop and target become the holding's stop and target (unless you had already set your own).</td></tr>
    <tr><td><code>cancelled</code></td><td>Cancelled with <code>cancelBuyOrder</code>.</td></tr>
    <tr><td><code>expired</code></td><td>Still pending after {{ $t['order_valid_days'] }} days; its cash and position slot are released.</td></tr>
</table>
<p class="muted">
    The cash balance is set by hand with <code>setPortfolioCash</code>. Recording a transaction does not change it.
</p>

<h3 id="sizing">Position sizing</h3>
<p>Size comes from <strong>what you can lose</strong>, not from a rupee amount:</p>
<pre><code>equity       = cash + current value of holdings
max risk     = equity × {{ $t['risk_per_trade_pct'] }}%
by risk      = floor(max risk ÷ (entry − stop))
by size cap  = floor(equity × {{ $t['max_position_pct'] }}% ÷ entry)
by cash      = floor(free cash ÷ (entry × (1 + {{ $t['fee_pct'] }}% fees)))

quantity     = the smallest of the three</code></pre>
<p>
    The plan reports <code>limited_by</code> in the sizing check's text, so you can see which limit bound the size. If cash is the
    limit, the quantity has been reduced to fit it.
</p>
<div class="card">
    <h4>Example</h4>
    <p>
        Portfolio Rs. 10,00,000, risk {{ $t['risk_per_trade_pct'] }}% per trade → max risk Rs. 10,000. Entry 820, stop 775 → risk/share 45.
        Quantity = 10,000 ÷ 45 = <strong>222 shares</strong>, costing 222 × 820 = Rs. 1,82,040. If only Rs. 1,23,000 is free, the cash limit
        gives 150 shares and the order is cut to 150.
    </p>
</div>

<h3 id="sell-rules">Sell rules</h3>
<p>
    <code>sellChecks(portfolio_id)</code> evaluates every holding. A holding can trigger several rules at once; all are returned in
    <code>reasons</code> and the first in the list below is <code>primary_reason</code>. <code>action</code> is <code>sell</code> if any rule fired, otherwise <code>hold</code>.
    It recommends only; nothing is sold or recorded.
</p>
<table>
    <tr><th>Rule key</th><th>Fires when</th></tr>
    <tr><td><code>stop_loss</code></td><td>Price ≤ the stop-loss set on the position (<code>setPositionTarget</code>, or copied from a filled buy order).</td></tr>
    <tr><td><code>trailing_stop</code></td><td>Price ≤ the trailing stop (below).</td></tr>
    <tr><td><code>target</code></td><td>Price ≥ the target set on the position.</td></tr>
    <tr><td><code>breakdown</code></td><td><strong>All three</strong>: close below SMA50, volume above its average (ratio &gt; {{ $t['breakdown_min_volume_ratio'] }}) and above the previous day's, and the close under the previous day's support.</td></tr>
    <tr><td><code>signal_reversal</code></td><td>The latest signal is <code>sell</code> or <code>strong_sell</code>. The reason names the previous signal.</td></tr>
</table>

<h4>Trailing stop</h4>
<p>
    The stop sits a fixed distance below the highest close since the position was opened, and only ever moves up. The distance is
    <code>{{ $t['trailing_stop_pct'] }}%</code> of your average cost. It switches on once that stop would sit at or above your cost, so it never starts out as a loss.
</p>
<pre><code>Bought at 820  → distance = 6% × 820 = 49.2   (active once the highest close ≥ 869.2)
highest 900    → trailing stop 850.8
highest 950    → trailing stop 900.8
highest 980    → trailing stop 930.8
price falls back to 880 after a 950 high → stop stays 900.8 → sell</code></pre>
<p>
    <code>effective_stop</code> in the response is the higher of the stop-loss and the trailing stop: the level that actually protects the position.
    Highs use daily closes, not intraday wicks, and nothing is stored: the stop is recomputed from price history on every call.
</p>

<h4>Where else it shows</h4>
<p>
    <code>priceAlerts</code> (the top-bar alerts) reports a holding as <code>stop_breached</code> when the stop-loss or trailing stop is hit and as <code>target_reached</code> at the target.
    Breakdown and signal reversal appear only in <code>sellChecks</code>.
</p>

<h3 id="trading-config">Limits (config/trading.php)</h3>
<p>The values below are what this server is running with right now.</p>
<table>
    <tr><th>Key</th><th>Value</th><th>Used for</th></tr>
    <tr><td><code>min_score</code></td><td>{{ $t['min_score'] }}</td><td>Lowest signal score (-1 to 1) that can lead to a buy.</td></tr>
    <tr><td><code>signal_max_age_days</code></td><td>{{ $t['signal_max_age_days'] }}</td><td>How far the signal may lag the latest price.</td></tr>
    <tr><td><code>order_valid_days</code></td><td>{{ $t['order_valid_days'] }}</td><td>Days a pending order lives before it expires.</td></tr>
    <tr><td><code>min_risk_reward</code></td><td>{{ $t['min_risk_reward'] }}</td><td>Smallest acceptable risk/reward.</td></tr>
    <tr><td><code>stop_buffer_pct</code></td><td>{{ $t['stop_buffer_pct'] }}%</td><td>How far under support the stop is placed.</td></tr>
    <tr><td><code>max_stop_distance_pct</code></td><td>{{ $t['max_stop_distance_pct'] }}%</td><td>Furthest the stop may be below entry.</td></tr>
    <tr><td><code>max_positions</code></td><td>{{ $t['max_positions'] }}</td><td>Holdings plus pending orders.</td></tr>
    <tr><td><code>max_position_pct</code></td><td>{{ $t['max_position_pct'] }}%</td><td>One position's cost as a share of portfolio value.</td></tr>
    <tr><td><code>risk_per_trade_pct</code></td><td>{{ $t['risk_per_trade_pct'] }}%</td><td>Most you may lose at the stop, as a share of portfolio value.</td></tr>
    <tr><td><code>fee_pct</code></td><td>{{ $t['fee_pct'] }}%</td><td>Broker, SEBON and DP fees added to a buy.</td></tr>
    <tr><td><code>trailing_stop_pct</code></td><td>{{ $t['trailing_stop_pct'] }}%</td><td>Trailing stop distance, as a share of average cost.</td></tr>
    <tr><td><code>breakdown_min_volume_ratio</code></td><td>{{ $t['breakdown_min_volume_ratio'] }}</td><td>Volume multiple (of its 20-day average) a breakdown needs.</td></tr>
</table>
