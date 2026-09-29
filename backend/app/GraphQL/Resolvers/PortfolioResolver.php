<?php

namespace App\GraphQL\Resolvers;

use App\GraphQL\ApiError;
use App\GraphQL\Resolver;
use App\Http\Requests\Portfolio\SetPositionTargetRequest;
use App\Http\Requests\Portfolio\StorePortfolioRequest;
use App\Http\Requests\Portfolio\StoreTransactionRequest;
use App\Services\Portfolio\InvalidTransactionException;
use App\Services\Portfolio\PortfolioService;

/** Portfolios and their transactions. Every lookup is scoped to the logged-in user. */
class PortfolioResolver extends Resolver
{
    public function __construct(private readonly PortfolioService $portfolios) {}

    public function portfolios(): array
    {
        return $this->plain($this->portfolios->forUser($this->user()));
    }

    public function portfolio($root, array $args): array
    {
        return $this->plain($this->portfolios->details($this->portfolios->find($this->user(), $args['id'])));
    }

    public function transactions($root, array $args): array
    {
        return $this->plain($this->portfolios->find($this->user(), $args['portfolio_id'])->transactionHistory()->get());
    }

    public function performance($root, array $args): array
    {
        return $this->plain($this->portfolios->performance($this->portfolios->find($this->user(), $args['portfolio_id'])));
    }

    public function createPortfolio($root, array $args): array
    {
        return $this->plain($this->portfolios->create($this->user(), $this->validated(StorePortfolioRequest::class, $args)));
    }

    public function addTransaction($root, array $args): array
    {
        $portfolio = $this->portfolios->find($this->user(), $args['portfolio_id']);
        $data = $this->validated(StoreTransactionRequest::class, $args);

        try {
            return $this->plain($this->portfolios->addTransaction($portfolio, $data));
        } catch (InvalidTransactionException $e) {
            throw new ApiError($e->getMessage(), 422);
        }
    }

    public function deleteTransaction($root, array $args): string
    {
        $this->portfolios->deleteTransaction($this->portfolios->find($this->user(), $args['portfolio_id']), $args['transaction_id']);

        return 'Deleted.';
    }

    public function setPositionTarget($root, array $args): array
    {
        $portfolio = $this->portfolios->find($this->user(), $args['portfolio_id']);
        $levels = $this->validated(SetPositionTargetRequest::class, array_intersect_key($args, array_flip(['stop_loss', 'target_price', 'notes'])));

        return $this->plain($this->portfolios->setTarget($portfolio, $args['stock_id'], $levels));
    }
}
