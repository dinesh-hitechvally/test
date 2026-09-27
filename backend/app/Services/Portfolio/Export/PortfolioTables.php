<?php

namespace App\Services\Portfolio\Export;

use App\Models\Portfolio;
use App\Models\PortfolioTransaction;

/**
 * The holdings/transactions columns shared by the tabular exports (CSV and
 * Excel), so the two can't drift apart column by column.
 */
class PortfolioTables
{
    public const HOLDINGS_HEADER = ['Symbol', 'Company', 'Quantity', 'Avg Cost', 'Invested', 'Current Price', 'Current Value', 'Unrealized P&L', 'Unrealized P&L %', 'Stop Loss', 'Target Price', 'Status'];

    public const TRANSACTIONS_HEADER = ['Date', 'Symbol', 'Type', 'Quantity', 'Price', 'Fees', 'Notes'];

    /** @param array<string, mixed> $holding one row of PortfolioValuationService::holdings() */
    public static function holdingRow(array $holding): array
    {
        return [
            $holding['symbol'], $holding['company_name'], $holding['quantity'], $holding['avg_cost'], $holding['invested'],
            $holding['current_price'], $holding['current_value'], $holding['unrealized_pnl'], $holding['unrealized_pnl_pct'],
            $holding['stop_loss'], $holding['target_price'], $holding['position_status'],
        ];
    }

    /** $numeric casts price/fees to float — Excel wants real numbers, CSV keeps the stored decimal strings. */
    public static function transactionRow(PortfolioTransaction $tx, bool $numeric = false): array
    {
        return [
            $tx->transaction_date->toDateString(), $tx->stock?->symbol, $tx->type, $tx->quantity,
            $numeric ? (float) $tx->price : $tx->price,
            $numeric ? (float) $tx->fees : $tx->fees,
            $tx->notes,
        ];
    }

    public static function filename(Portfolio $portfolio, string $kind, string $extension): string
    {
        return str($portfolio->name)->slug()->value()."-{$kind}-".now()->toDateString().".{$extension}";
    }
}
