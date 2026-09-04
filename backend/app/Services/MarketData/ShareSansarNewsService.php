<?php

namespace App\Services\MarketData;

use App\Models\NewsArticle;
use App\Models\ScrapeLog;
use DOMDocument;
use DOMXPath;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

/**
 * Headline scrape of ShareSansar's news listing page — every link to a
 * /newsdetail/... article on the page, deduplicated by URL. Plain static
 * HTML, same low-risk shape as the IPO listing scrape.
 */
class ShareSansarNewsService
{
    private const NEWS_PAGE = 'https://www.sharesansar.com/news-page';

    private const SOURCE_NAME = 'sharesansar.com/news';

    public function scrape(): array
    {
        try {
            $userAgent = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome Safari';
            $response = Http::withHeaders(['User-Agent' => $userAgent])->timeout(20)->get(self::NEWS_PAGE);
            $response->throw();

            $articles = $this->parseArticles($response->body());

            if ($articles === []) {
                throw new RuntimeException('No news articles found — the source page layout may have changed.');
            }

            foreach (array_chunk($articles, 200) as $chunk) {
                NewsArticle::upsert($chunk, uniqueBy: ['url'], update: ['title', 'published_date', 'updated_at']);
            }

            ScrapeLog::create([
                'source' => self::SOURCE_NAME,
                'status' => 'success',
                'records_processed' => count($articles),
                'message' => count($articles).' article(s) found.',
            ]);

            return ['articles' => count($articles)];
        } catch (Throwable $e) {
            Log::warning('ShareSansar news scrape failed', ['error' => $e->getMessage()]);

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
    private function parseArticles(string $html): array
    {
        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML($html);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $links = $xpath->query("//a[contains(@href, '/newsdetail/')]");
        $seen = [];
        $rows = [];
        $now = now();

        foreach ($links as $a) {
            $href = $a->getAttribute('href');
            $url = str_starts_with($href, 'http') ? $href : 'https://www.sharesansar.com'.$href;

            if (isset($seen[$url])) {
                continue;
            }
            $seen[$url] = true;

            $title = trim($a->getAttribute('title'));
            if ($title === '') {
                $title = trim(preg_replace('/\s+/', ' ', $a->textContent));
            }
            if ($title === '') {
                continue;
            }

            // Every slug ends in the article's publish date — the only
            // date this source exposes for these headline links.
            preg_match('/-(\d{4}-\d{2}-\d{2})$/', $url, $m);

            $rows[] = [
                'title' => mb_substr($title, 0, 500),
                'url' => mb_substr($url, 0, 500),
                'published_date' => $m[1] ?? null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $rows;
    }
}
