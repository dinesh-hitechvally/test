<?php

namespace Tests\Feature\MachineLearning;

use App\Events\TaskFailed;
use App\Models\MlModel;
use App\Services\MachineLearning\BackgroundArtisanLauncher;
use App\Services\MachineLearning\MlDirectionPredictorService;
use App\Services\MachineLearning\MlTrainingLauncher;
use App\Services\MachineLearning\MlTrainingRunner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Tests\TestCase;

/**
 * The ML cron URL only STARTS the (minutes-long) training in the background and reports on it; it never waits for it,
 * which is what made it time out.
 */
class MlTrainingCronTest extends TestCase
{
    use RefreshDatabase;

    private FakeLauncher $launcher;

    protected function setUp(): void
    {
        parent::setUp();
        config(['services.cron.secret' => 'k']);
        File::delete(storage_path('app/ml/training.json'));

        $this->launcher = new FakeLauncher;
        $this->app->instance(MlTrainingLauncher::class, $this->launcher);
    }

    protected function tearDown(): void
    {
        File::delete(storage_path('app/ml/training.json'));
        parent::tearDown();
    }

    private function model(): MlModel
    {
        return new MlModel([
            'horizon_days' => 5, 'train_samples' => 80000, 'test_samples' => 20000, 'accuracy' => 0.5303, 'baseline_accuracy' => 0.6705,
            'precision' => 0.6, 'recall' => 0.4, 'f1' => 0.48, 'stocks_used' => 309,
        ]);
    }

    private function trainerReturning(?MlModel $model, ?string $fails = null): void
    {
        $this->app->instance(MlDirectionPredictorService::class, new class($model, $fails) extends MlDirectionPredictorService
        {
            public function __construct(private ?MlModel $model, private ?string $fails) {}

            public function train(): MlModel
            {
                if ($this->fails !== null) {
                    throw new RuntimeException($this->fails);
                }

                return $this->model;
            }
        });
    }

    public function test_the_url_starts_the_training_in_the_background_and_returns_at_once(): void
    {
        $this->get('/cron/generate/ml-model?key=k')
            ->assertOk()
            ->assertSeeText('Training started in the background')
            ->assertSeeText('[ok]');

        $this->assertSame(1, $this->launcher->launched);
        $this->assertSame('running', app(MlTrainingRunner::class)->state()['state']);
    }

    public function test_pinging_again_while_it_runs_does_not_start_a_second_run(): void
    {
        $this->get('/cron/generate/ml-model?key=k');
        $this->get('/cron/generate/ml-model?key=k')
            ->assertOk()
            ->assertSeeText('already running in the background');

        $this->assertSame(1, $this->launcher->launched);
    }

    public function test_a_run_that_died_without_reporting_does_not_block_new_ones_forever(): void
    {
        $this->get('/cron/generate/ml-model?key=k');
        $this->travel(2)->hours(); // "running" for far longer than any real training

        $this->get('/cron/generate/ml-model?key=k')->assertSeeText('Training started');

        $this->assertSame(2, $this->launcher->launched);
    }

    public function test_the_background_run_records_its_result_and_the_url_reports_it(): void
    {
        $this->trainerReturning($this->model());
        $this->get('/cron/generate/ml-model?key=k');

        app(MlTrainingRunner::class)->runNow(); // what `php artisan ml:train` does in the background process

        $this->assertSame('done', app(MlTrainingRunner::class)->state()['state']);

        $this->get('/cron/generate/ml-model?key=k')
            ->assertOk()
            ->assertSeeText('Nothing started — the model was trained')
            ->assertSeeText('Stocks used: 309')
            ->assertSeeText('Accuracy (out-of-sample): 53.03%')
            ->assertSeeText('does NOT beat the naive')
            ->assertSeeText('?force=1');

        $this->assertSame(1, $this->launcher->launched); // the poll did not retrain
    }

    public function test_force_trains_again_even_right_after_a_finished_run(): void
    {
        $this->trainerReturning($this->model());
        $this->get('/cron/generate/ml-model?key=k');
        app(MlTrainingRunner::class)->runNow();

        $this->get('/cron/generate/ml-model?key=k&force=1')->assertSeeText('Training started');

        $this->assertSame(2, $this->launcher->launched);
    }

    public function test_a_finished_run_is_repeated_once_it_is_old_enough(): void
    {
        $this->trainerReturning($this->model());
        $this->get('/cron/generate/ml-model?key=k');
        app(MlTrainingRunner::class)->runNow();

        $this->travel(7)->hours(); // the daily 03:30 call, a day later

        $this->get('/cron/generate/ml-model?key=k')->assertSeeText('Training started');

        $this->assertSame(2, $this->launcher->launched);
    }

    public function test_a_failed_run_is_reported_and_the_next_ping_starts_again(): void
    {
        $this->trainerReturning(null, fails: 'Not enough training data yet (0 examples across 0 stocks)');
        $this->get('/cron/generate/ml-model?key=k');

        try {
            app(MlTrainingRunner::class)->runNow();
            $this->fail('Expected the training to fail.');
        } catch (RuntimeException) {
        }

        $state = app(MlTrainingRunner::class)->state();
        $this->assertSame('failed', $state['state']);
        $this->assertStringContainsString('Not enough training data', $state['message']);

        $this->get('/cron/generate/ml-model?key=k')
            ->assertSeeText('The previous run failed: Not enough training data yet')
            ->assertSeeText('Training started');
    }

    public function test_when_the_server_forbids_background_processes_the_cron_fails_with_the_alternative(): void
    {
        Event::fake([TaskFailed::class]);
        $this->launcher->error = 'Starting a background process is disabled on this server. Schedule "php artisan ml:train" in cPanel > Cron Jobs instead.';

        $this->get('/cron/generate/ml-model?key=k')
            ->assertOk()
            ->assertSeeText('Schedule "php artisan ml:train" in cPanel')
            ->assertSeeText('[failed]');

        Event::assertDispatched(TaskFailed::class);
        $this->assertSame('failed', app(MlTrainingRunner::class)->state()['state']);
    }

    public function test_the_command_trains_and_prints_the_summary(): void
    {
        $this->trainerReturning($this->model());

        $this->artisan('ml:train')->expectsOutputToContain('Stocks used: 309')->assertExitCode(0);
    }

    public function test_the_command_reports_a_failure_without_a_stack_trace(): void
    {
        $this->trainerReturning(null, fails: 'Not enough training data yet');

        $this->artisan('ml:train')->expectsOutputToContain('Not enough training data yet')->assertExitCode(1);
    }

    public function test_the_php_binary_can_be_set_and_is_never_a_fastcgi_binary(): void
    {
        config(['services.ml.php_binary' => '/opt/cpanel/ea-php83/root/usr/bin/php']);
        $this->assertSame('/opt/cpanel/ea-php83/root/usr/bin/php', (new BackgroundArtisanLauncher)->phpBinary());

        config(['services.ml.php_binary' => null]);
        $this->assertStringNotContainsString('fpm', strtolower((new BackgroundArtisanLauncher)->phpBinary()));
        $this->assertStringNotContainsString('cgi', strtolower((new BackgroundArtisanLauncher)->phpBinary()));
    }
}

class FakeLauncher implements MlTrainingLauncher
{
    public int $launched = 0;

    public ?string $error = null;

    public function launch(): ?string
    {
        $this->launched++;

        return $this->error;
    }
}
