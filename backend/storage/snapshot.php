<?php
// Throwaway characterization check: records every read-only API response so a
// refactor can prove the JSON didn't change. Usage: php snapshot.php before|after
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Portfolio;
use App\Models\Sector;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Http\Request;
use Laravel\Sanctum\Sanctum;

$phase = $argv[1] ?? 'before';
$dir = __DIR__."/snap_{$phase}";
@mkdir($dir);

$user = Portfolio::first()?->user ?? User::first();
$symbol = Stock::whereHas('latestSignal')->whereHas('dividends')->orderBy('id')->value('symbol')
    ?? Stock::whereHas('latestSignal')->value('symbol');
$sector = Sector::has('stocks')->value('name');
$portfolio = $user->portfolios()->value('id');

$paths = [
    '/api/user', '/api/users', '/api/user/login-history',
    '/api/stocks', '/api/stocks?search=bank', "/api/stocks/{$symbol}",
    "/api/stocks/{$symbol}/prices?days=30", "/api/stocks/{$symbol}/indicators?days=30",
    "/api/stocks/{$symbol}/forecasts?days=30", "/api/stocks/{$symbol}/signals?per_page=15&page=2",
    "/api/stocks/{$symbol}/signals?signal[]=buy&signal[]=strong_buy", "/api/stocks/{$symbol}/ml-prediction",
    "/api/stocks/{$symbol}/ai-opinion", "/api/stocks/{$symbol}/next-close-forecast",
    "/api/stocks/{$symbol}/dividends", "/api/stocks/{$symbol}/right-shares",
    '/api/stocks/NOPE123',
    '/api/signals/today', '/api/signals/today?signal=buy', '/api/signals/actionable?bias=buy', '/api/signals/actionable?bias=sell',
    '/api/alerts', '/api/indices?days=30', '/api/signals/accuracy', '/api/schedule',
    '/api/saved-screens', '/api/patterns/scan',
    '/api/reports/dashboard', '/api/reports/market', '/api/reports/sectors',
    '/api/reports/sector?name='.urlencode($sector), '/api/reports/sector', '/api/reports/sector?name=NoSuchSector',
    "/api/reports/stock/{$symbol}", '/api/reports/rules',
    '/api/reports/rule-scan?rules[]=rsi_oversold&rules[]=bb_lower_touch', '/api/reports/rule-scan?rules[]=rsi_oversold&rules[]=bb_lower_touch&mode=all',
    '/api/reports/rule-scan?rules[]=nope', "/api/reports/technical/{$symbol}",
    '/api/reports/dividends', '/api/reports/next-close-accuracy',
    '/api/reports/long-term', '/api/reports/mid-term', '/api/reports/short-term?sector='.urlencode($sector),
    "/api/reports/analyst/{$symbol}",
    '/api/market/screener', '/api/market/52-week', '/api/scrape/logs?limit=5',
    '/api/watchlists', '/api/portfolios',
];
if ($portfolio) {
    array_push($paths, "/api/portfolios/{$portfolio}", "/api/portfolios/{$portfolio}/transactions", "/api/portfolios/{$portfolio}/performance");
}

foreach ($paths as $path) {
    Sanctum::actingAs($user);
    $response = $kernel->handle(Request::create($path, 'GET', server: ['HTTP_ACCEPT' => 'application/json']));
    $body = $response->getContent();
    // Normalize the few values that legitimately differ run to run.
    $body = preg_replace('/"generated_at":"[^"]*"|"as_of":"[^"]*"/', '"__time__":""', $body);
    file_put_contents($dir.'/'.md5($path).'.json', $response->getStatusCode()."\n".$body);
    $index[$path] = md5($path);
}
file_put_contents($dir.'/_index.json', json_encode($index, JSON_PRETTY_PRINT));

if ($phase === 'after' && is_dir(__DIR__.'/snap_before')) {
    $diff = 0;
    foreach ($index as $path => $hash) {
        $a = @file_get_contents(__DIR__."/snap_before/{$hash}.json");
        $b = file_get_contents("{$dir}/{$hash}.json");
        if ($a !== $b) { $diff++; echo "DIFFERENT: {$path}\n"; }
    }
    echo $diff === 0 ? 'All '.count($index)." responses identical.\n" : "{$diff} response(s) differ.\n";
} else {
    echo 'Recorded '.count($index)." responses (symbol {$symbol}, sector {$sector}).\n";
}
