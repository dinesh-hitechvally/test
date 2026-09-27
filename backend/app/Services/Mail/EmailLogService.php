<?php

namespace App\Services\Mail;

use App\Models\EmailLog;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Mail\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * Keeps a record of every email the app sends, whatever sent it. Hooked into
 * Laravel's own mail events (see the RecordEmail* listeners), so a new email
 * anywhere in the app is logged without touching its code:
 *
 *   MessageSending   → row created, status "sending", tagged on the message
 *   MessageSent      → "sent" (or "logged" when the mailer is "log")
 *   transport throws → "failed" + the error (via the exception handler)
 *
 * Laravel has no "mail failed" event — a failure is just an exception — so
 * rows this process started and hasn't finished are tracked here, and the
 * exception handler (bootstrap/app.php) calls markPendingFailed(). Must be a
 * singleton (AppServiceProvider::$singletons) for that to work.
 */
class EmailLogService
{
    public const HEADER = 'X-Email-Log-Id';

    /** @var array<int, true> ids of rows started in this process but not yet sent */
    private array $pending = [];

    /** @param  array<string, mixed>  $data  the mail event's view data (carries the notification/mailable class) */
    public function recordSending(Email $message, array $data): void
    {
        $to = $this->addresses($message->getTo());

        $log = EmailLog::create([
            'status' => EmailLog::STATUS_SENDING,
            'mailer' => config('mail.default'),
            'from_address' => $this->addresses($message->getFrom())[0] ?? null,
            'to' => $to,
            'cc' => $this->addresses($message->getCc()) ?: null,
            'bcc' => $this->addresses($message->getBcc()) ?: null,
            'subject' => $message->getSubject(),
            'source' => $data['__laravel_notification'] ?? $data['__laravel_mailable'] ?? 'raw',
            'user_id' => $to ? User::where('email', $to[0])->value('id') : null,
            'attempted_at' => now(),
        ]);

        $message->getHeaders()->addTextHeader(self::HEADER, (string) $log->id);
        $this->pending[$log->id] = true;
    }

    public function recordSent(SentMessage $sent): void
    {
        $id = (int) $sent->getOriginalMessage()->getHeaders()->get(self::HEADER)?->getBodyAsString();

        if (! $id) {
            return;
        }

        EmailLog::whereKey($id)->update([
            'status' => config('mail.default') === 'log' ? EmailLog::STATUS_LOGGED : EmailLog::STATUS_SENT,
            'message_id' => $sent->getMessageId(),
            'sent_at' => now(),
        ]);

        unset($this->pending[$id]);
    }

    /** Called when sending throws: whatever this process was still sending didn't go out. */
    public function markPendingFailed(Throwable $e): void
    {
        if ($this->pending === []) {
            return;
        }

        EmailLog::whereKey(array_keys($this->pending))->update([
            'status' => EmailLog::STATUS_FAILED,
            'error' => mb_substr($e->getMessage(), 0, 2000),
            'failed_at' => now(),
        ]);

        $this->pending = [];
    }

    /** Newest first, optionally one status only. */
    public function recent(?string $status = null, int $perPage = 50): LengthAwarePaginator
    {
        return EmailLog::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->latest('attempted_at')
            ->latest('id')
            ->paginate(min($perPage, 200));
    }

    /**
     * @param  Address[]  $addresses
     * @return list<string>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $a) => $a->getAddress(), $addresses));
    }
}
