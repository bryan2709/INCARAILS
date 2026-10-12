<?php

namespace Tests\Feature;

use App\Jobs\ProcessExternalTask;
use App\Models\Competition;
use App\Models\ExternalCredential;
use App\Models\ExternalQuotaPool;
use App\Models\ExternalTask;
use App\Models\ExternalWorkerConfig;
use App\Services\ExternalTasks\TaskLeaseManager;
use App\Services\ExternalTasks\TaskMaintenance;
use App\Services\ExternalTasks\TaskResultRecorder;
use App\Services\ExternalTasks\WorkerRegistry;
use App\Services\FootballClient;
use App\Services\ImportCoordinator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class ExternalTaskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->startOfSecond());
        Queue::fake();
        Http::preventStrayRequests();
        config(['football.worker_key' => 'worker-a', 'football.base_url' => 'https://provider.test']);
        ExternalQuotaPool::create(['name' => 'football-data-global', 'provider' => 'football-data', 'max_requests' => 10, 'window_seconds' => 60, 'is_enabled' => true]);
        $this->worker('a');
    }

    private function worker(string $letter): ExternalWorkerConfig
    {
        $reference = 'TEST_TOKEN_'.strtoupper($letter);
        config(['football.credentials.'.$reference => 'fake-'.$letter]);
        $credential = ExternalCredential::create(['alias' => $letter, 'secret_reference' => $reference, 'quota_pool_id' => ExternalQuotaPool::first()->id, 'is_enabled' => true]);

        return ExternalWorkerConfig::create(['worker_key' => 'worker-'.$letter, 'credential_id' => $credential->id, 'is_enabled' => true])->fresh('credential');
    }

    private function task(int $externalId = 2016): ExternalTask
    {
        $competition = Competition::create(['external_id' => $externalId, 'name' => 'Test '.$externalId]);
        $import = app(ImportCoordinator::class)->start($competition, null);

        return ExternalTask::whereHas('step', fn ($query) => $query->where('import_id', $import->id))->firstOrFail();
    }

    private function runTask(ExternalTask $task): void
    {
        app()->call([new ProcessExternalTask($task->id), 'handle']);
    }

    public function test_single_worker_waits_after_transient_error_without_reusing_token(): void
    {
        Http::fake(['*' => Http::response([], 500)]);
        $task = $this->task();
        $this->runTask($task);
        $this->travel(6)->seconds();
        $this->runTask($task);

        Http::assertSentCount(1);
        $this->assertSame('waiting_worker', $task->fresh()->status);
        $this->assertDatabaseCount('external_task_attempts', 1);
        $this->assertDatabaseCount('external_quota_reservations', 1);
    }

    public function test_three_distinct_workers_exhaust_transient_attempts(): void
    {
        $this->worker('b');
        $this->worker('c');
        Http::fake(['*' => Http::sequence()
            ->push(['message' => 'First temporary failure'], 503)
            ->push(['message' => 'Second temporary failure'], 503)
            ->push(['message' => 'Provider maintenance is in progress.'], 503)]);
        $task = $this->task();
        foreach (['a', 'b', 'c'] as $letter) {
            config(['football.worker_key' => 'worker-'.$letter]);
            $this->runTask($task);
            $this->travel(6)->seconds();
        }

        Http::assertSentCount(3);
        $this->assertSame('failed', $task->fresh()->status);
        $this->assertSame('SOURCE_UNAVAILABLE', $task->step->import->fresh()->error_code);
        $this->assertSame('Solicitud externa fallida (HTTP 503). Provider maintenance is in progress.', $task->step->import->fresh()->error_message);
        $this->assertSame(3, $task->attempts()->distinct()->count('worker_id'));
        $this->assertSame(3, $task->attempts()->distinct()->count('credential_id'));
    }

    public function test_provider_forbidden_is_definitive_and_does_not_rotate_workers(): void
    {
        $this->worker('b');
        Http::fake(['*' => Http::response([], 403)]);
        $task = $this->task();
        $this->runTask($task);

        $this->assertSame('failed', $task->fresh()->status);
        $this->assertSame('SOURCE_ACCESS_DENIED', $task->step->import->fresh()->error_code);
        $this->assertSame('Solicitud externa fallida (HTTP 403).', $task->step->import->fresh()->error_message);
        Http::assertSentCount(1);
        Queue::assertPushed(ProcessExternalTask::class, 1);
    }

    public function test_provider_unauthorized_suspends_credential(): void
    {
        Http::fake(['*' => Http::response(['message' => 'Your API token is invalid.'], 401)]);
        $task = $this->task();
        $this->runTask($task);

        $this->assertFalse(ExternalCredential::first()->is_enabled);
        $this->assertSame('SOURCE_CREDENTIAL_INVALID', $task->step->import->fresh()->error_code);
        $this->assertSame('Solicitud externa fallida (HTTP 401). Your API token is invalid.', $task->step->import->fresh()->error_message);
    }

    public function test_invalid_or_missing_provider_messages_keep_the_http_diagnosis(): void
    {
        $bodies = [[], ['message' => null], ['message' => '   '], ['message' => 403], ['message' => ['restricted']], ['message' => false], '<html>Forbidden</html>'];
        $sequence = Http::sequence();
        foreach ($bodies as $body) {
            $sequence->push($body, 403);
        }
        Http::fake(['*' => $sequence]);
        foreach (array_keys($bodies) as $index) {
            $task = $this->task(2016 + $index);
            $this->runTask($task);

            $this->assertSame('SOURCE_ACCESS_DENIED', $task->step->import->fresh()->error_code);
            $this->assertSame('Solicitud externa fallida (HTTP 403).', $task->step->import->fresh()->error_message);
        }
        Http::assertSentCount(7);
        Http::assertSequencesAreEmpty();
    }

    public function test_provider_message_is_bounded_without_corrupting_unicode_or_exposing_other_fields(): void
    {
        Http::fake(['*' => Http::response(['message' => str_repeat('á', 2500), 'debug' => 'private-provider-context'], 403)]);
        $task = $this->task();
        $this->runTask($task);
        $message = $task->step->import->fresh()->error_message;

        $this->assertSame(2000, mb_strlen($message));
        $this->assertTrue(mb_check_encoding($message, 'UTF-8'));
        $this->assertStringEndsWith('á', $message);
        $this->assertStringNotContainsString('private-provider-context', $message);
    }

    public function test_rate_limit_respects_retry_after_and_consumes_reserved_quota(): void
    {
        Http::fake(['*' => Http::response([], 429, ['Retry-After' => '120'])]);
        $task = $this->task();
        $this->runTask($task);

        $this->assertTrue(ExternalQuotaPool::first()->cooldown_until->equalTo(now()->addSeconds(120)));
        $this->assertTrue($task->fresh()->not_before->equalTo(now()->addSeconds(120)));
        $this->assertDatabaseHas('external_quota_reservations', ['reservation_status' => 'sent_or_uncertain']);
        Http::assertSentCount(1);
    }

    public function test_global_quota_is_shared_by_all_workers(): void
    {
        $other = $this->worker('b');
        $first = app(WorkerRegistry::class)->current();
        $leases = app(TaskLeaseManager::class);
        for ($index = 0; $index < 11; $index++) {
            $task = $this->task(2016 + $index);
            $reservation = $leases->reserve($task->id, $index % 2 ? $other : $first);
            $this->assertSame($index < 10 ? 'run' : 'wait', $reservation['action']);
        }

        $this->assertDatabaseCount('external_quota_reservations', 10);
        $this->assertDatabaseCount('external_task_attempts', 10);
        $this->assertSame('waiting_rate_limit', $task->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_stale_lease_cannot_commit_a_late_response(): void
    {
        $task = $this->task();
        $worker = app(WorkerRegistry::class)->current();
        $reservation = app(TaskLeaseManager::class)->reserve($task->id, $worker);
        $task->refresh()->increment('lease_version');
        $outcome = app(TaskResultRecorder::class)->record($task->id, $reservation, $worker, 200, ['id' => 2016]);

        $this->assertSame('stale', $outcome);
        $this->assertNull($task->fresh()->response_json);
        $this->assertDatabaseHas('external_task_attempts', ['result_status' => 'reserved']);
    }

    public function test_http_date_retry_after_is_supported(): void
    {
        $response = Http::response([], 429, ['Retry-After' => now()->addSeconds(90)->toRfc7231String()]);
        Http::fake(['*' => $response]);
        $response = Http::get('https://provider.test');

        $this->assertSame(90, app(FootballClient::class)->retryAfter($response));
    }

    public function test_unapproved_recursive_resource_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        app(FootballClient::class)->get('person:4204', 'fake-token');
    }

    public function test_provider_request_does_not_hold_a_database_transaction(): void
    {
        $transactionLevel = DB::transactionLevel();
        Http::fake(function () use ($transactionLevel) {
            $this->assertSame($transactionLevel, DB::transactionLevel());

            return Http::response([], 500);
        });
        $this->runTask($this->task());
        Http::assertSentCount(1);
    }

    public function test_crash_on_third_attempt_is_recovered_as_failed(): void
    {
        $this->worker('b');
        $this->worker('c');
        $task = $this->task();
        foreach (['a', 'b', 'c'] as $letter) {
            config(['football.worker_key' => 'worker-'.$letter]);
            $worker = app(WorkerRegistry::class)->current();
            $reservation = app(TaskLeaseManager::class)->reserve($task->id, $worker);
            if ($letter !== 'c') {
                app(TaskResultRecorder::class)->record($task->id, $reservation, $worker, 500, []);
                $this->travel(6)->seconds();
            }
        }
        $this->travel(46)->seconds();
        app(TaskMaintenance::class)->run();

        $this->assertSame('failed', $task->fresh()->status);
        $this->assertSame('MAX_ATTEMPTS', $task->step->import->fresh()->error_code);
        $this->assertDatabaseHas('external_task_attempts', ['task_id' => $task->id, 'attempt_number' => 3, 'result_status' => 'uncertain']);
        Http::assertNothingSent();
    }
}
