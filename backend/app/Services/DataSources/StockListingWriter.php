<?php

namespace App\Services\DataSources;

use App\Models\Sector;
use App\Models\Stock;

/**
 * Saves a list of listed securities: creates a `stocks` row for each one that is new, fills in a sector for every
 * stock that has none (a stock that already has a sector is left alone) and keeps each stock's instrument type in
 * step with the list. The same logic whichever website supplied the list (nepalstock.com, or MeroLagani when
 * nepalstock.com is unreachable).
 *
 * Promoter / preference shares (symbols like ACLBSLP, BFCPO) are separate securities that are not in the companies
 * list; they take their sector from the parent company (see parentSector()). `sectors_inferred` counts those —
 * a naming-based guess, the one sector that is not read straight from the source.
 */
class StockListingWriter
{
    /**
     * Sector headings that different websites spell differently, as normalised keys (see sectorKey()).
     * Anything else is matched to an existing sector by its normalised name, so "Hydro Power" and "Hydropower"
     * land in one sector instead of two.
     */
    private const SECTOR_ALIASES = ['developmentbanklimited' => 'developmentbanks'];

    /** @var array<string, int>|null normalised sector name => sector id, for this run */
    private ?array $sectorIds = null;

    /**
     * @param  list<array{symbol: string, name: ?string, active: bool, nepse_security_id?: ?int}>  $securities
     * @param  array<string, array{sector: ?string, instrument_type: ?string}>  $companies  symbol => sector and instrument type
     * @return array{total: int, created: int, existing: int, sectors_filled: int, sectors_inferred: int, types_set: int}
     */
    public function write(array $securities, array $companies): array
    {
        $this->sectorIds = null;
        $created = 0;
        $existing = 0;
        $sectorsFilled = 0;
        $sectorsInferred = 0;
        $typesSet = 0;

        foreach ($securities as $security) {
            $symbol = strtoupper((string) ($security['symbol'] ?? ''));

            if ($symbol === '') {
                continue;
            }

            $stock = Stock::firstOrNew(['symbol' => $symbol]);
            $isNew = ! $stock->exists;

            if ($isNew) {
                $stock->company_name = $security['name'] ?? null;
                $stock->is_active = $security['active'] ?? true;
            }

            if (! empty($security['nepse_security_id']) && $stock->nepse_security_id === null) {
                $stock->nepse_security_id = (int) $security['nepse_security_id'];
            }

            $sectorName = $companies[$symbol]['sector'] ?? null;
            $instrumentType = $companies[$symbol]['instrument_type'] ?? null;

            $inferred = false;

            // Not a company at all (so no sector of its own): a promoter / preference share takes its parent's.
            if ($stock->sector_id === null && ! isset($companies[$symbol])) {
                $sectorName = $this->parentSector($symbol, $companies);
                $inferred = $sectorName !== null;
            }

            if ($stock->sector_id === null && $sectorName !== null) {
                $stock->sector_id = $this->sectorId($sectorName);
                $sectorsFilled++;

                if ($inferred) {
                    $sectorsInferred++;
                }
            }

            if ($instrumentType !== null && $stock->instrument_type !== $instrumentType) {
                $stock->instrument_type = $instrumentType;
                $typesSet++;
            }

            if ($isNew || $stock->isDirty()) {
                $stock->save();
            }

            $isNew ? $created++ : $existing++;
        }

        return [
            'total' => count($securities),
            'created' => $created,
            'existing' => $existing,
            'sectors_filled' => $sectorsFilled,
            'sectors_inferred' => $sectorsInferred,
            'types_set' => $typesSet,
        ];
    }

    /** The id of the sector with this name — an existing one when the name matches ignoring case and punctuation, else a new one. */
    private function sectorId(string $name): int
    {
        $this->sectorIds ??= Sector::all()->mapWithKeys(fn (Sector $s) => [$this->sectorKey($s->name) => $s->id])->all();
        $key = $this->sectorKey($name);

        return $this->sectorIds[$key] ??= Sector::firstOrCreate(['name' => $name])->id;
    }

    private function sectorKey(string $name): string
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $name));

        return self::SECTOR_ALIASES[$key] ?? $key;
    }

    /**
     * The sector of the company a promoter / preference share belongs to: its symbol is the company's
     * plus "P" or "PO" (ACLBSLP -> ACLBSL, BFCPO -> BFC). Null when no company matches, so it never
     * guesses for anything that is not clearly such a share.
     *
     * @param  array<string, array{sector: ?string, instrument_type: ?string}>  $companies
     */
    private function parentSector(string $symbol, array $companies): ?string
    {
        foreach (['PO', 'P'] as $suffix) {
            if (strlen($symbol) > strlen($suffix) + 1 && str_ends_with($symbol, $suffix)) {
                $parent = substr($symbol, 0, -strlen($suffix));

                if (isset($companies[$parent]) && $companies[$parent]['sector'] !== null) {
                    return $companies[$parent]['sector'];
                }
            }
        }

        return null;
    }
}
