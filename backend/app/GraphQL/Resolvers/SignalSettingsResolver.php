<?php

namespace App\GraphQL\Resolvers;

use App\GraphQL\Resolver;
use App\Http\Requests\Auth\UpdateSignalSettingsRequest;
use App\Services\Analysis\Signals\SignalSettingsService;

/** The signed-in person's own signal settings (weights, minimum, margin, guard). */
class SignalSettingsResolver extends Resolver
{
    public function __construct(private readonly SignalSettingsService $settings) {}

    public function show(): array
    {
        return $this->settings->present($this->user());
    }

    public function update($root, array $args): array
    {
        return $this->settings->save($this->user(), $this->validated(UpdateSignalSettingsRequest::class, $args));
    }

    public function reset(): array
    {
        return $this->settings->reset($this->user());
    }
}
