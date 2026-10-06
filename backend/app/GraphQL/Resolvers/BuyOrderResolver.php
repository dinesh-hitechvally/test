<?php

namespace App\GraphQL\Resolvers;

use App\GraphQL\ApiError;
use App\GraphQL\Resolver;
use App\Http\Requests\Portfolio\SetPortfolioCashRequest;
use App\Models\Portfolio;
use App\Models\Stock;
use App\Services\Portfolio\BuyOrderRejectedException;
use App\Services\Portfolio\BuyOrderService;
use App\Services\Portfolio\PortfolioService;
use App\Services\Portfolio\SellSignalService;

/** BUY signal → buy order. Every lookup is scoped to the logged-in user. */
class BuyOrderResolver extends Resolver
{
    public function __construct(
        private readonly BuyOrderService $orders,
        private readonly PortfolioService $portfolios,
        private readonly SellSignalService $sells,
    ) {}

    public function sellChecks($root, array $args): array
    {
        return $this->plain($this->sells->forPortfolio($this->portfolio($args)));
    }

    public function preview($root, array $args): array
    {
        return $this->orders->evaluate($this->portfolio($args), $this->stock($args));
    }

    public function orders($root, array $args): array
    {
        $query = $this->portfolio($args)->buyOrders()->with('stock:id,symbol,company_name')->orderByDesc('id');

        if (! empty($args['status'])) {
            $query->where('status', $args['status']);
        }

        return $this->plain($query->get());
    }

    public function place($root, array $args): array
    {
        try {
            return $this->plain($this->orders->place($this->portfolio($args), $this->stock($args)));
        } catch (BuyOrderRejectedException $e) {
            throw new ApiError($e->getMessage(), 422);
        }
    }

    public function cancel($root, array $args): array
    {
        return $this->plain($this->orders->cancel($this->portfolio($args), $args['order_id']));
    }

    public function setCash($root, array $args): array
    {
        $data = $this->validated(SetPortfolioCashRequest::class, array_intersect_key($args, ['cash_balance' => true]));

        return $this->plain($this->orders->setCash($this->portfolio($args), (float) $data['cash_balance']));
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
