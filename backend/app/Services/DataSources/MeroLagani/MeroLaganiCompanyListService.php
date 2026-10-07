<?php

namespace App\Services\DataSources\MeroLagani;

use App\Services\DataSources\NepalStock\NepalStockClient;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Every listed security with its sector, from MeroLagani's Listed Companies page — the fallback for the stock
 * list when nepalstock.com can't be reached. The page groups securities under a heading per sector; the
 * heading gives the sector and, for the non-equity groups, the instrument type.
 *
 * Promoter shares are listed under their own heading rather than their company's sector: they are returned as
 * securities without a sector so the stock list sync infers it from the parent company, same as for NEPSE's list.
 */
class MeroLaganiCompanyListService
{
    private const PAGE = 'https://merolagani.com/CompanyList.aspx';

    /** Headings that are an instrument type rather than a business sector: heading => [sector, instrument type]. */
    private const NON_SECTOR_GROUPS = [
        'corporate debenture' => [null, 'Non-Convertible Debentures'],
        'government bond' => [null, 'Government Bond'],
        'preferred stock' => [null, 'Preference Shares'],
        'mutual fund' => ['Mutual Fund', 'Mutual Funds'],
    ];

    /** Promoter shares: no sector of their own (it comes from the parent company). */
    private const PROMOTER_GROUPS = ['promotor share', 'promoter share'];

    /**
     * @return array{securities: list<array{symbol: string, name: ?string, active: bool}>, companies: array<string, array{sector: ?string, instrument_type: ?string}>}
     */
    public function fetch(): array
    {
        $response = Http::withHeaders(['User-Agent' => NepalStockClient::USER_AGENT])->timeout(30)->get(self::PAGE);
        $response->throw();

        return $this->parse($response->body());
    }

    public function parse(string $html): array
    {
        $groups = preg_split('/<div class="panel panel-default">/', $html);
        array_shift($groups);

        $securities = [];
        $companies = [];

        foreach ($groups as $group) {
            if (! preg_match('/panel-title">\s*<a[^>]*>(.*?)<\/a>/s', $group, $title)) {
                continue;
            }

            $heading = trim(html_entity_decode(strip_tags($title[1])));
            $key = strtolower($heading);

            preg_match_all("/<a[^>]*href='\/CompanyDetail\.aspx\?symbol=([^']+)'[^>]*>[^<]*<\/a>\s*<\/td>\s*<td[^>]*>(.*?)<\/td>/s", $group, $rows, PREG_SET_ORDER);

            foreach ($rows as $row) {
                $symbol = strtoupper(trim($row[1]));
                $name = trim(html_entity_decode(strip_tags($row[2])));

                if ($symbol === '') {
                    continue;
                }

                $securities[] = ['symbol' => $symbol, 'name' => $name !== '' ? $name : null, 'active' => true];

                if (in_array($key, self::PROMOTER_GROUPS, true)) {
                    continue;
                }

                [$sector, $type] = self::NON_SECTOR_GROUPS[$key] ?? [$heading, 'Equity'];
                $companies[$symbol] = ['sector' => $sector, 'instrument_type' => $type];
            }
        }

        if ($securities === []) {
            throw new RuntimeException('MeroLagani company list has no securities — its layout may have changed.');
        }

        return ['securities' => $securities, 'companies' => $companies];
    }
}
