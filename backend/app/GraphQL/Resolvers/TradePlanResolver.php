<?php

namespace App\GraphQL\Resolvers;

use App\GraphQL\Resolver;
use App\Http\Requests\Portfolio\SetPortfolioCashRequest;
use App\Models\Portfolio;
use App\Models\Stock;
use App\Services\Portfolio\PortfolioService;
use App\Services\Portfolio\SellSignalService;
use App\Services\Portfolio\TradePlanService;

/**
 * The advice side of a portfolio: a trade plan for a buy signal, and sell / hold checks for what you hold.
 * Nothing here trades — it only recommends. Every lookup is scoped to the logged-in user.
 */
class TradePlanResolver extends Resolver
{
    public function __construct(
        private readonly TradePlanService $plans,
        private readonly PortfolioService $portfolios,
        private readonly SellSignalService $sells,
    ) {}

    public function sellChecks($root, array $args): array
    {
        return $this->plain($this->sells->forPortfolio($this->portfolio($args)));
    }

    public function tradePlan($root, array $args): array
    {
        return $this->plans->evaluate($this->portfolio($args), $this->stock($args));
    }

    public function setCash($root, array $args): array
    {
        $data = $this->validated(SetPortfolioCashRequest::class, array_intersect_key($args, ['cash_balance' => true]));

        return $this->plain($this->plans->setCash($this->portfolio($args), (float) $data['cash_balance']));
    }

    private function portfolio(array $args): Portfolio
    {
        return $this->portfolios->find($this->user(), $args['portfolio_id']);
    }

    private function stock(array $args): Stock
    {
        return Stock::where('symbol', strtoupper($args['symbol']))->firstOrFail();
    }
}
