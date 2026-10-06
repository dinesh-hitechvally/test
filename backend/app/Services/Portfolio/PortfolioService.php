<?php

namespace App\Services\Portfolio;

use App\Models\Portfolio;
use App\Models\PortfolioTransaction;
use App\Models\PositionTarget;
use App\Models\Stock;
use App\Models\User;
use Illuminate\Support\Collection;
use RuntimeException;

/**
 * A user's portfolios and their transactions. Numbers (holdings, P&L,
 * performance) come from PortfolioValuationService; this owns reading and
 * writing the ledger itself.
 */
class PortfolioService
{
    public function __construct(
        private readonly PortfolioValuationService $valuation,
        private readonly BuyOrderService $buyOrders,
    ) {}

    /**
     * Scoped to the user so one user can never touch another's portfolio,
     * even by guessing an id. Cast defensively — a non-numeric id (e.g. a
     * stale/unset id from the frontend) should 404 cleanly, not blow up on a
     * strict scalar type hint.
     */
    public function find(User $user, mixed $portfolioId): Portfolio
    {
        return $user->portfolios()->findOrFail((int) $portfolioId);
    }

    /** The user's portfolios, each with its summary figures. */
    public function forUser(User $user): Collection
    {
        return $user->portfolios()->get()->each(function ($portfolio) {
            $portfolio->summary = $this->valuation->summary($portfolio);
        });
    }

    public function create(User $user, array $data): Portfolio
    {
        return $user->portfolios()->create($data);
    }

    /** The portfolio page: summary, current holdings and realized gains. */
    public function details(Portfolio $portfolio): array
    {
        return [
            'portfolio' => $portfolio,
            'summary' => $this->valuation->summary($portfolio),
            'holdings' => $this->valuation->holdings($portfolio),
            'realized' => $this->valuation->realizedPnl($portfolio),
        ];
    }

    public function performance(Portfolio $portfolio): array
    {
        $history = $this->valuation->valueHistory($portfolio);

        return [
            'history' => $history,
            'metrics' => $this->valuation->performanceMetrics($portfolio, $history),
        ];
    }

    /** @throws InvalidTransactionException when it breaks the ledger (e.g. selling more than held) */
    public function addTransaction(Portfolio $portfolio, array $data): PortfolioTransaction
    {
        try {
            $this->valuation->assertTransactionIsValid(
                $portfolio,
                (int) $data['stock_id'],
                $data['type'],
                (int) $data['quantity'],
                $data['transaction_date'],
            );
        } catch (RuntimeException $e) {
            throw new InvalidTransactionException($e->getMessage(), previous: $e);
        }

        $transaction = $portfolio->transactions()->create([...$data, 'fees' => $data['fees'] ?? 0]);

        if ($data['type'] === 'buy') {
            $this->buyOrders->markExecuted($portfolio, (int) $data['stock_id']);
        }

        return $transaction->load('stock:id,symbol,company_name');
    }

    public function deleteTransaction(Portfolio $portfolio, mixed $transactionId): void
    {
        $portfolio->transactions()->findOrFail((int) $transactionId)->delete();
    }

    /**
     * Sets or clears the stop-loss / take-profit levels watched for one
     * holding. Independent of the transaction ledger — upsert semantics, so
     * it can be set, edited or cleared (nulls) without touching buy/sell history.
     */
    public function setTarget(Portfolio $portfolio, mixed $stockId, array $levels): PositionTarget
    {
        $stock = Stock::findOrFail((int) $stockId);

        return PositionTarget::updateOrCreate(
            ['portfolio_id' => $portfolio->id, 'stock_id' => $stock->id],
            $levels
        );
    }
}
