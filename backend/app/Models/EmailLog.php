<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One outgoing email and what happened to it. Metadata only — the body is
 * never stored, because some emails (password reset) carry a live secret link.
 */
#[Fillable([
    'status', 'mailer', 'message_id', 'from_address', 'to', 'cc', 'bcc', 'subject',
    'source', 'user_id', 'error', 'attempted_at', 'sent_at', 'failed_at',
])]
class EmailLog extends Model
{
    public const STATUS_SENDING = 'sending';

    public const STATUS_SENT = 'sent';

    /** Handed to the "log" mailer — written to the log file, NOT delivered to an inbox. */
    public const STATUS_LOGGED = 'logged';

    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'attempted_at' => 'datetime',
            'sent_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
