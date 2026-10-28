<?php

declare(strict_types=1);

namespace Cyppe\LaravelBatchJobsRedisDriver\Tests;

use Cyppe\LaravelBatchJobsRedisDriver\RedisBatch;
use Cyppe\LaravelBatchJobsRedisDriver\Repositories\RedisBatchRepository;
use Cyppe\LaravelBatchJobsRedisDriver\Tests\Fixtures\BatchedJob;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Redis;
use PHPUnit\Framework\Attributes\RequiresPhpExtension;
use ReflectionClassConstant;
use RuntimeException;

class CountsEachJobOnceTest extends TestCase
{
    public function test_the_redis_repository_makes_batches_that_count_each_job_once(): void
    {
        $batch = $this->dispatch($this->batchOf(1));

        $this->assertInstanceOf(RedisBatchRepository::class, app(BatchRepository::class));
        $this->assertInstanceOf(RedisBatch::class, $batch);
        $this->assertInstanceOf(RedisBatch::class, Bus::findBatch($batch->id));
    }

    public function test_a_redelivered_success_counts_once_and_then_waits_for_the_other_job(): void
    {
        $batch = $this->dispatch($this->batchOf(2));

        $batch->recordSuccessfulJob('job-a');
        $batch->recordSuccessfulJob('job-a');

        $this->assertSame(1, $batch->fresh()->pendingJobs);
        $this->assertFalse($batch->fresh()->finished());
        $this->assertSame(0, $this->fired('then'));
        $this->assertSame(0, $this->fired('finally'));

        $batch->recordSuccessfulJob('job-b');

        $this->assertSame(0, $batch->fresh()->pendingJobs);
        $this->assertTrue($batch->fresh()->finished());
        $this->assertSame(1, $this->fired('then'));
        $this->assertSame(1, $this->fired('finally'));
        $this->assertSame(2, $this->fired('progress'));
    }

    public function test_a_success_redelivered_after_the_batch_finished_fires_nothing_again(): void
    {
        $batch = $this->dispatch($this->batchOf(2));

        $batch->recordSuccessfulJob('job-a');
        $batch->recordSuccessfulJob('job-b');
        $batch->recordSuccessfulJob('job-a');

        $this->assertSame(0, $batch->fresh()->pendingJobs);
        $this->assertSame(1, $this->fired('then'));
        $this->assertSame(1, $this->fired('finally'));
        $this->assertSame(2, $this->fired('progress'));
    }

    public function test_a_failed_job_that_succeeds_on_retry_counts_once_and_leaves_failed_job_ids(): void
    {
        $batch = $this->dispatch($this->batchOf(2)->allowFailures());

        $batch->recordFailedJob('job-a', new RuntimeException('first attempt failed'));

        $this->assertSame(['job-a'], $batch->fresh()->failedJobIds);
        $this->assertSame(1, $batch->fresh()->failedJobs);

        $batch->recordSuccessfulJob('job-a');
        $batch->recordSuccessfulJob('job-a');

        $fresh = $batch->fresh();
        $this->assertSame(1, $fresh->pendingJobs);
        $this->assertSame([], $fresh->failedJobIds);
        $this->assertSame(1, $fresh->failedJobs, 'Laravel keeps failed_jobs when a failed job later succeeds');
        $this->assertSame(0, $this->fired('then'));

        $batch->recordSuccessfulJob('job-b');

        $this->assertSame(0, $batch->fresh()->pendingJobs);
        $this->assertSame(1, $this->fired('then'));
    }

    public function test_a_failure_recorded_twice_counts_once_and_finally_waits_for_the_other_job(): void
    {
        $batch = $this->dispatch($this->batchOf(2)->allowFailures());

        $batch->recordFailedJob('job-a', new RuntimeException('failed'));
        $batch->recordFailedJob('job-a', new RuntimeException('failed again on redelivery'));

        $fresh = $batch->fresh();
        $this->assertSame(1, $fresh->failedJobs);
        $this->assertSame(['job-a'], $fresh->failedJobIds);
        $this->assertSame(1, $this->fired('catch'));
        $this->assertSame(0, $this->fired('finally'));

        $batch->recordSuccessfulJob('job-b');

        $this->assertSame(1, $this->fired('finally'));
        $this->assertSame(0, $this->fired('then'), 'then waits while a failed job is pending');
    }

    public function test_a_failure_recorded_twice_cancels_the_batch_once(): void
    {
        $batch = $this->dispatch($this->batchOf(2));

        $batch->recordFailedJob('job-a', new RuntimeException('failed'));
        $cancelledAt = $batch->fresh()->cancelledAt;
        $batch->recordFailedJob('job-a', new RuntimeException('failed again on redelivery'));

        $this->assertNotNull($cancelledAt);
        $this->assertEquals($cancelledAt, $batch->fresh()->cancelledAt);
        $this->assertSame(1, $batch->fresh()->failedJobs);
        $this->assertSame(1, $this->fired('catch'));
    }

    public function test_a_failure_after_the_jobs_success_was_counted_is_ignored(): void
    {
        $batch = $this->dispatch($this->batchOf(2));

        $batch->recordSuccessfulJob('job-a');
        // Redelivered after its worker died, the job exceeds its max attempts and fails.
        $batch->recordFailedJob('job-a', new RuntimeException('max attempts exceeded'));

        $fresh = $batch->fresh();
        $this->assertSame(0, $fresh->failedJobs);
        $this->assertSame([], $fresh->failedJobIds);
        $this->assertFalse($fresh->cancelled());
        $this->assertSame(0, $this->fired('catch'));

        $batch->recordSuccessfulJob('job-b');

        $this->assertSame(1, $this->fired('then'));
        $this->assertSame(1, $this->fired('finally'));
    }

    #[RequiresPhpExtension('pcntl')]
    public function test_concurrent_redeliveries_count_each_job_once(): void
    {
        $jobIds = array_map(static fn(int $index): string => "job-{$index}", range(1, 20));
        $batch  = $this->dispatch($this->batchOf(count($jobIds)));

        // Eight workers each record every job but the last, at the same time and in their own order.
        $this->inParallelWorkers(8, static function () use ($batch, $jobIds): void {
            $workerBatch = Bus::findBatch($batch->id);
            $toRecord    = array_slice($jobIds, 0, -1);
            shuffle($toRecord);

            foreach ($toRecord as $jobId) {
                $workerBatch->recordSuccessfulJob($jobId);
            }
        });

        $this->assertSame(1, $batch->fresh()->pendingJobs);
        $this->assertSame(0, $this->fired('then'));
        $this->assertSame(19, $this->fired('progress'));

        $batch->recordSuccessfulJob(end($jobIds));

        $this->assertSame(0, $batch->fresh()->pendingJobs);
        $this->assertSame(1, $this->fired('then'));
        $this->assertSame(1, $this->fired('finally'));
    }

    public function test_a_batch_whose_counted_set_is_missing_counts_from_its_next_success_on(): void
    {
        $batch = $this->dispatch($this->batchOf(3));
        $batch->recordSuccessfulJob('job-a');
        // A batch created before the counted set existed: its earlier successes are not in a set.
        Redis::connection()->del($this->succeededJobIdsKey($batch));

        $batch->recordSuccessfulJob('job-b');
        $batch->recordSuccessfulJob('job-b');

        $this->assertSame(1, $batch->fresh()->pendingJobs);
        $this->assertSame(['job-b'], Redis::connection()->smembers($this->succeededJobIdsKey($batch)));

        $batch->recordSuccessfulJob('job-c');

        $this->assertSame(1, $this->fired('then'));
    }

    public function test_the_counted_set_lives_and_is_deleted_with_its_batch(): void
    {
        $repository = app(BatchRepository::class);
        $deleted    = $this->dispatch($this->batchOf(2));
        $pruned     = $this->dispatch($this->batchOf(1));
        $deleted->recordSuccessfulJob('job-a');
        $pruned->recordSuccessfulJob('job-a');
        $redis = Redis::connection();

        $this->assertSame(-1, $redis->ttl("batch:{$deleted->id}"), 'the batch has no TTL');
        $this->assertSame(-1, $redis->ttl($this->succeededJobIdsKey($deleted)), 'nor does its counted set');

        $repository->delete($deleted->id);
        $repository->prune(now()->addMinute());

        $this->assertSame(0, $redis->exists("batch:{$deleted->id}", $this->succeededJobIdsKey($deleted)));
        $this->assertSame(0, $redis->exists("batch:{$pruned->id}", $this->succeededJobIdsKey($pruned)));
    }

    public function test_the_count_script_refuses_a_write_based_on_a_stale_read(): void
    {
        $batch  = $this->dispatch($this->batchOf(2));
        $script = (new ReflectionClassConstant(RedisBatchRepository::class, 'COUNT_JOB_ONCE_SCRIPT'))->getValue();
        $redis  = Redis::connection();
        $read   = $redis->get("batch:{$batch->id}");
        $batch->recordSuccessfulJob('job-b');

        $written = $redis->eval($script, 2, "batch:{$batch->id}", $this->succeededJobIdsKey($batch), $read, '{"stale":true}', 'job-a', 'success');

        $this->assertSame(-1, $written);
        $this->assertSame(1, $batch->fresh()->pendingJobs);
        $this->assertSame(['job-b'], $redis->smembers($this->succeededJobIdsKey($batch)));
    }

    private function batchOf(int $jobs): PendingBatch
    {
        return Bus::batch(array_map(static fn(): BatchedJob => new BatchedJob(), range(1, $jobs)))
            ->progress(static fn() => Redis::connection()->incr('fired:progress'))
            ->then(static fn() => Redis::connection()->incr('fired:then'))
            ->catch(static fn() => Redis::connection()->incr('fired:catch'))
            ->finally(static fn() => Redis::connection()->incr('fired:finally'));
    }

    private function dispatch(PendingBatch $pendingBatch): Batch
    {
        return $pendingBatch->dispatch();
    }

    private function fired(string $callback): int
    {
        return (int) Redis::connection()->get("fired:{$callback}");
    }

    private function succeededJobIdsKey(Batch $batch): string
    {
        return "batch:{$batch->id}:succeeded_job_ids";
    }

    /**
     * Run the work in forked processes at once, each on its own Redis connection, and wait for all.
     *
     * A worker leaves through pcntl_exec, so PHPUnit's shutdown handling never runs in it.
     */
    private function inParallelWorkers(int $workers, callable $work): void
    {
        $pids = [];

        for ($worker = 0; $worker < $workers; $worker++) {
            $pid = pcntl_fork();
            $this->assertNotSame(-1, $pid, 'fork failed');

            if ($pid === 0) {
                $succeeded = false;

                try {
                    Redis::purge();
                    $work();
                    $succeeded = true;
                } finally {
                    pcntl_exec($succeeded ? '/bin/true' : '/bin/false');
                }
            }

            $pids[] = $pid;
        }

        foreach ($pids as $pid) {
            pcntl_waitpid($pid, $status);
            $this->assertTrue(pcntl_wifexited($status) && pcntl_wexitstatus($status) === 0, "worker {$pid} failed");
        }

        Redis::purge();
    }
}
