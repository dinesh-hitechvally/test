<?php

namespace App\Services\MarketData;

use App\Models\IpoListing;
use App\Models\ScrapeLog;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * IPO/new-listing data from ShareSansar's homepage — unlike the price
 * history and dividend endpoints, this is plain static HTML embedded
 * directly in the page (two tables: currently-open issues and upcoming
 * ones), not a separate AJAX/CSRF-protected endpoint, so it carries none of
 * the risk that endpoint has shown.
 */
class ShareSansarIpoService
{
    private const HOMEPAGE = 'https://www.sharesansar.com/';

    private const SOURCE_NAME = 'sharesansar.com/ipo';

    /**
     * @return array{open: int, upcoming: int}
     */
    public function scrape(): array
    {
        try {
            $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';
            $response = Http::withHeaders(['User-Agent' => $userAgent])->timeout(20)->get(self::HOMEPAGE);
            $response->throw();
            $html = $response->body();

            $open = $this->parseTable($html, 'myTableEip', 'open');
            $upcoming = $this->parseTable($html, 'myTableUip', 'upcoming');
            $rows = [...$open, ...$upcoming];

            if ($rows === []) {
                throw new RuntimeException('No IPO rows found — the source page layout may have changed.');
            }

            // Small, fully-replaceable dataset (a few dozen rows at most) —
            // delete-and-reinsert keeps it always matching the site's
            // current state rather than trying to reconcile stale rows.
            IpoListing::query()->delete();
            IpoListing::insert($rows);

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'success',
                'records_processed' => count($rows),
                'message' => count($open).' open issue(s), '.count($upcoming).' upcoming issue(s).',
            ]);

            return ['open' => count($open), 'upcoming' => count($upcoming)];
        } catch (Throwable $e) {
            Log::warning('ShareSansar IPO scrape failed', ['error' => $e->getMessage()]);

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'failed',
                'records_processed' => 0,
                'message' => mb_substr($e->getMessage(), 0, 2000),
            ]);

            throw $e;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function parseTable(string $html, string $panelId, string $stage): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $panel = $xpath->query("//*[@id='{$panelId}']")->item(0);
        if (! $panel) {
            return [];
        }

        $rows = [];
        foreach ($xpath->query('.//tbody/tr', $panel) as $tr) {
            $cells = $xpath->query('./td', $tr);
            if ($cells->length === 0) {
                continue;
            }

            $text = [];
            foreach ($cells as $td) {
                $text[] = trim(preg_replace('/\s+/', ' ', $td->textContent));
            }

            $symbolLink = $xpath->query('.//a', $cells->item(1))->item(0);
            $symbol = $symbolLink ? trim($symbolLink->textContent) : null;
            $detailUrl = $symbolLink ? $symbolLink->getAttribute('href') : null;

            $now = now();

            if ($stage === 'open') {
                // S.N., Symbol, Company, Units, Price, Opening, Closing, Status, View
                $rows[] = [
                    'stage' => 'open',
                    'symbol' => $symbol,
                    'company_name' => $text[2] ?? null,
                    'units' => $this->number($text[3] ?? null),
                    'price' => $this->number($text[4] ?? null),
                    'sector' => null,
                    'remark' => null,
                    'opening_date' => $this->date($text[5] ?? null),
                    'closing_date' => $this->date($text[6] ?? null),
                    'status' => ($text[7] ?? '') !== '' ? $text[7] : null,
                    'detail_url' => $detailUrl,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            } else {
                // S.N., Symbol, Company, Units, Sector, Remark
                $rows[] = [
                    'stage' => 'upcoming',
                    'symbol' => $symbol,
                    'company_name' => $text[2] ?? null,
                    'units' => $this->number($text[3] ?? null),
                    'sector' => ($text[4] ?? '') !== '' ? $text[4] : null,
                    'remark' => ($text[5] ?? '') !== '' ? $text[5] : null,
                    'price' => null,
                    'opening_date' => null,
                    'closing_date' => null,
                    'status' => null,
                    'detail_url' => $detailUrl,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows;
    }

    private function number(?string $raw): ?float
    {
        if ($raw === null) {
            return null;
        }

        $clean = trim(str_replace([',', ' '], '', $raw));

        return $clean === '' ? null : (float) $clean;
    }

    private function date(?string $raw): ?string
    {
        $clean = trim((string) $raw);

        return preg_match('/^\d{4}-\d{2}-\d{2}/', $clean, $m) ? $m[0] : null;
    }
}
