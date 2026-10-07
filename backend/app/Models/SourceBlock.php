<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'website', 'is_blocked', 'reason', 'http_status', 'consecutive_failures', 'times_blocked',
    'blocked_at', 'blocked_until', 'last_failure_at', 'last_success_at',
])]
class SourceBlock extends Model
{
    protected function casts(): array
    {
        return [
            'is_blocked' => 'boolean',
            'blocked_at' => 'datetime',
            'blocked_until' => 'datetime',
            'last_failure_at' => 'datetime',
            'last_success_at' => 'datetime',
        ];
    }

    /** Blocked and still inside the pause: requests to this website are skipped. */
    public function isPaused(): bool
    {
        return $this->is_blocked && $this->blocked_until !== null && $this->blocked_until->isFuture();
    }
}
