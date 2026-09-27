<?php

namespace Tests\Feature\Mail;

use App\Models\EmailLog;
use App\Models\User;
use App\Services\Alerts\FailureAlertService;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\AbstractTransport;
use Tests\TestCase;

class EmailLogTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::create(['name' => 'Test', 'email' => 'test@example.com', 'password' => 'password']);
    }

    public function test_a_sent_email_is_recorded_with_its_details(): void
    {
        Mail::raw('Hello', fn ($m) => $m->to('test@example.com')->cc('cc@example.com')->subject('Welcome'));

        $log = EmailLog::sole();
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertSame(['test@example.com'], $log->to);
        $this->assertSame(['cc@example.com'], $log->cc);
        $this->assertSame('Welcome', $log->subject);
        $this->assertSame('raw', $log->source);
        $this->assertSame('array', $log->mailer);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertNotNull($log->message_id);
        $this->assertNotNull($log->sent_at);
        $this->assertNull($log->error);
    }

    public function test_the_log_mailer_is_recorded_as_logged_not_sent(): void
    {
        config(['mail.default' => 'log']);

        Mail::raw('Hello', fn ($m) => $m->to('someone@example.com')->subject('Hi'));

        $this->assertSame(EmailLog::STATUS_LOGGED, EmailLog::sole()->status);
        $this->assertNull(EmailLog::sole()->user_id); // not an app user
    }

    public function test_the_password_reset_email_is_recorded_but_its_body_never_is(): void
    {
        $this->postJson('/api/forgot-password', ['email' => 'test@example.com'])->assertOk();

        $log = EmailLog::sole();
        $this->assertSame(EmailLog::STATUS_SENT, $log->status);
        $this->assertSame(ResetPassword::class, $log->source);
        $this->assertSame($this->user->id, $log->user_id);
        $this->assertFalse(Schema::hasColumn('email_logs', 'body')); // reset links are secrets
    }

    public function test_a_transport_failure_is_recorded_as_failed_with_the_error(): void
    {
        $this->useFailingMailer();

        $this->postJson('/api/forgot-password', ['email' => 'test@example.com'])->assertServerError();

        $log = EmailLog::sole();
        $this->assertSame(EmailLog::STATUS_FAILED, $log->status);
        $this->assertSame('SMTP connection refused', $log->error);
        $this->assertNotNull($log->failed_at);
        $this->assertNull($log->sent_at);
    }

    public function test_a_failed_task_alert_email_is_recorded_and_still_never_throws(): void
    {
        $this->useFailingMailer();
        config(['services.cron.alert_email' => 'ops@example.com']);

        app(FailureAlertService::class)->notifyFailure('market:sync', 'nepalstock.com unreachable');

        $log = EmailLog::sole();
        $this->assertSame(EmailLog::STATUS_FAILED, $log->status);
        $this->assertSame(['ops@example.com'], $log->to);
        $this->assertStringContainsString('market:sync', $log->subject);
    }

    public function test_the_log_can_be_listed_and_filtered_by_status(): void
    {
        Mail::raw('a', fn ($m) => $m->to('a@example.com')->subject('A'));
        EmailLog::create(['status' => EmailLog::STATUS_FAILED, 'mailer' => 'smtp', 'to' => ['b@example.com'], 'subject' => 'B', 'error' => 'x', 'attempted_at' => now()]);
        Sanctum::actingAs($this->user);

        $this->getJson('/api/email-logs')->assertOk()->assertJsonPath('total', 2);
        $this->getJson('/api/email-logs?status=failed')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.subject', 'B');
    }

    private function useFailingMailer(): void
    {
        Mail::extend('failing', fn () => new class extends AbstractTransport
        {
            protected function doSend(SentMessage $message): void
            {
                throw new TransportException('SMTP connection refused');
            }

            public function __toString(): string
            {
                return 'failing://';
            }
        });
        config(['mail.mailers.failing' => ['transport' => 'failing'], 'mail.default' => 'failing']);
        Mail::forgetMailers();
    }
}
