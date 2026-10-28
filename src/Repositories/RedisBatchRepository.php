<?php

declare(strict_types=1);

namespace Cyppe\LaravelBatchJobsRedisDriver\Repositories;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use Cyppe\LaravelBatchJobsRedisDriver\AlreadyCountedJobCounts;
use DateTimeInterface;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Bus\PrunableBatchRepository;
use Illuminate\Bus\UpdatedBatchJobCounts;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RedisBatchRepository extends DatabaseBatchRepository implements BatchRepository, PrunableBatchRepository
{
    protected const SUCCESS = 'success';
    protected const FAILURE = 'failure';

    /**
     * Writes a job's counted outcome to the batch in one atomic step.
     *
     * KEYS[1] is the batch, KEYS[2] the set of job UUIDs whose success was counted. ARGV holds the
     * batch JSON the caller read, the updated JSON, the job UUID and the outcome. It returns -1 when
     * the batch changed since the caller read it, 0 when the job was already counted, 1 when written.
     */
    protected const COUNT_JOB_ONCE_SCRIPT = <<<'LUA'
        if redis.call('GET', KEYS[1]) ~= ARGV[1] then
            return -1
        end

        if ARGV[4] == 'success' then
            if redis.call('SADD', KEYS[2], ARGV[3]) == 0 then
                return 0
            end
        elseif redis.call('SISMEMBER', KEYS[2], ARGV[3]) == 1 then
            return 0
        end

        redis.call('SET', KEYS[1], ARGV[2])

        return 1
        LUA;

    protected int $lockTimeout;
    protected $factory;

    public function __construct(BatchFactory $factory)
    {
        $this->lockTimeout = 120;
        $this->factory     = $factory;
    }

    public function get($limit = 50, $before = null): array
    {
        if ( ! Redis::connection(config('queue.batching.redis_connection', 'default'))->exists('batches_list')) {
            return [];
        }

        $totalBatches = Redis::connection(config('queue.batching.redis_connection', 'default'))->llen('batches_list');

        // If $before is specified, find its index
        $beforeIndex = $before ? array_search($before, Redis::connection(config('queue.batching.redis_connection', 'default'))->lrange('batches_list', 0, -1)) : null;

        // Determine the range to fetch
        if ($beforeIndex !== false && $beforeIndex !== null) {
            $rangeEnd   = (int) $beforeIndex - 1;
            $startIndex = max($rangeEnd - $limit + 1, 0);
        } else {
            $startIndex = max($totalBatches - $limit, 0);
            $rangeEnd   = $totalBatches - 1;
        }

        // Fetch only the required batch IDs
        $batchIds = Redis::connection(config('queue.batching.redis_connection', 'default'))->lrange('batches_list', $startIndex, $rangeEnd);

        // Use Redis pipeline to bulk fetch batch data
        $redis    = Redis::connection(config('queue.batching.redis_connection', 'default'))->client();
        $pipeline = $redis->pipeline();
        foreach ($batchIds as $batchId) {
            $pipeline->get("batch:{$batchId}");
        }
        $responses = $pipeline->exec();

        // Filter, decode, and sort raw batch data
        $batchesRaw = array_map(fn($response) => json_decode($response, true), array_filter((array) $responses));
        uasort($batchesRaw, function ($a, $b) {
            return $b['created_at'] <=> $a['created_at'];
        });

        // Validation and logging for missing 'id' keys - temporary debug block
        // todo: remove this block when potential issue is verified
        foreach ($batchesRaw as $key => $data) {
            if ( ! isset($data['id'])) {
                $this->debug("get method - batch['id] is missing");
                // Optionally, remove the invalid data to avoid further errors
                //unset($batchesRaw[$key]);
            }
        }

        // Map sorted data to Batch objects and convert to a sequential array
        return array_values(array_map(fn($data) => $this->toBatch($data), $batchesRaw));
    }

    public function find(string $batchId)
    {
        $data = Redis::connection(config('queue.batching.redis_connection', 'default'))->get("batch:{$batchId}");

        if (empty($data)) {
            // Return null or handle the case where the batch does not exist
            $this->debug("find method - Batch ({$batchId}) does not exist.");

            return null;
        }

        $batchData = json_decode($data, true);

        return $this->toBatch($batchData);
    }

    public function store(PendingBatch $batch): Batch
    {
        $id = (string) Str::orderedUuid();

        $batchData = [
            'id'             => $id,
            'name'           => $batch->name,
            'total_jobs'     => 0,
            'pending_jobs'   => 0,
            'failed_jobs'    => 0,
            'failed_job_ids' => [],
            'options'        => $this->serialize($batch->options),
            'created_at'     => time(),
            'cancelled_at'   => null,
            'finished_at'    => null,
        ];

        Redis::connection(config('queue.batching.redis_connection', 'default'))->set("batch:{$id}", json_encode($batchData));
        Redis::connection(config('queue.batching.redis_connection', 'default'))->rpush('batches_list', $id);

        $this->debug("store method - new batch created with id ({$id}) and name ({$batch->name})");

        $batch = $this->find($id);
        if ($batch === null) {
            throw new RuntimeException("Failed to retrieve batch after creation");
        }

        return $batch;
    }

    public function incrementTotalJobs(string $batchId, int $amount): void
    {
        $this->executeWithLock("lock:batch:{$batchId}", function () use ($batchId, $amount) {
            $data = Redis::connection(config('queue.batching.redis_connection', 'default'))->get("batch:{$batchId}");
            if (empty($data)) {
                $this->debug("incrementTotalJobs method - Batch ({$batchId}) does not exist.");

                return;
            }
            $batchData = json_decode($data, true);
            $batchData['total_jobs'] += $amount;
            $batchData['pending_jobs'] += $amount;
            $batchData['finished_at'] = null;
            Redis::connection(config('queue.batching.redis_connection', 'default'))->set("batch:{$batchId}", json_encode($batchData));
        }, 200, 200);
    }

    /**
     * Count the job's failure once per job UUID.
     *
     * A job already in failed_job_ids, or whose success was already counted, changes nothing: a job
     * redelivered after its success can fail on max attempts, and must not cancel the batch.
     */
    public function incrementFailedJobs(string $batchId, string $jobId)
    {
        return $this->countJobOnce($batchId, $jobId, self::FAILURE, function (array $batchData) use ($jobId): ?array {
            if (in_array($jobId, $batchData['failed_job_ids'], true)) {
                return null;
            }

            $batchData['failed_jobs']++;
            $batchData['failed_job_ids'][] = $jobId;

            return $batchData;
        });
    }

    /**
     * Count the job's success once per job UUID, and take it out of failed_job_ids as Laravel does.
     *
     * A second success of the same job (a redelivery after its worker died before deleting it)
     * changes nothing, so pending_jobs cannot reach 0 while another job of the batch has not run.
     */
    public function decrementPendingJobs(string $batchId, string $jobId)
    {
        return $this->countJobOnce($batchId, $jobId, self::SUCCESS, function (array $batchData) use ($batchId, $jobId): array {
            // Check if pending_jobs is greater than 0 before decrementing
            if ($batchData['pending_jobs'] > 0) {
                $batchData['pending_jobs']--;

                if ($batchData['pending_jobs'] === 0) {
                    // Only this job's redelivery may finish a batch its worker left unfinished.
                    $batchData['finishing_job_id'] = $jobId;
                }
            } else {
                // Will remove later - keeping for debug for now to see if it ever happens
                $this->debug("decrementPendingJobs method - Attempted to decrement pending_jobs below 0 for batch: " . $batchId);
            }

            $batchData['failed_job_ids'] = array_values(array_diff($batchData['failed_job_ids'], [$jobId]));

            return $batchData;
        });
    }

    public function markAsFinished(string $batchId): void
    {
        $this->executeWithLock("lock:batch:{$batchId}", function () use ($batchId) {
            $data = Redis::connection(config('queue.batching.redis_connection', 'default'))->get("batch:{$batchId}");

            if (empty($data)) {
                $this->debug("decrementPendingJobs method - Batch ({$batchId}) does not exist.");

                return;
            }

            $batchData                = json_decode($data, true);
            $batchData['finished_at'] = CarbonImmutable::now()->getTimestamp();
            Redis::connection(config('queue.batching.redis_connection', 'default'))->set("batch:{$batchId}", json_encode($batchData));

            $created_at      = Carbon::createFromTimestamp($batchData['created_at']);
            $finished_at     = Carbon::createFromTimestamp($batchData['finished_at']);
            $created_at_str  = $created_at->format('Y-m-d H:i:s');
            $finished_at_str = $finished_at->format('Y-m-d H:i:s');
            $diff_for_humans = $created_at->diffForHumans($finished_at, ['syntax' => true, 'parts' => -1]);

            $this->debug("markAsFinished method - Batch marked as finished. batch id: " . $batchId . ", batch name: {$batchData['name']}, created at: {$created_at_str}, finished at: {$finished_at_str}. Duration: {$diff_for_humans}.");
        }, 200, 200);
    }

    public function delete(string $batchId)
    {
        if ( ! Redis::connection(config('queue.batching.redis_connection', 'default'))->exists("batch:{$batchId}")) {
            // Handle the case where the batch does not exist
            return;
        }

        Redis::connection(config('queue.batching.redis_connection', 'default'))->del("batch:{$batchId}", $this->succeededJobIdsKey($batchId));
        Redis::connection(config('queue.batching.redis_connection', 'default'))->client()->lrem('batches_list', $batchId, (int) 0);
    }

    public function cancel(string $batchId)
    {
        $this->executeWithLock("lock:batch:{$batchId}", function () use ($batchId) {
            $data = Redis::connection(config('queue.batching.redis_connection', 'default'))->get("batch:{$batchId}");
            if (empty($data)) {
                $this->debug("cancel method - Batch ({$batchId}) does not exist.");

                return;
            }
            $batchData                 = json_decode($data, true);
            $now                       = CarbonImmutable::now()->getTimestamp();
            $batchData['cancelled_at'] = $now;
            $batchData['finished_at']  = $now;
            Redis::connection(config('queue.batching.redis_connection', 'default'))->set("batch:{$batchId}", json_encode($batchData));
        }, 200, 200);
    }

    public function transaction(Closure $callback)
    {
        return $callback();
    }

    public function prune(DateTimeInterface $before)
    {
        return $this->pruneBatches($before, true);
    }

    public function pruneUnfinished(DateTimeInterface $before)
    {
        return $this->pruneBatches($before, false, false);
    }

    public function pruneCancelled(DateTimeInterface $before)
    {
        return $this->pruneBatches($before, null, true);
    }

    /**
     * Rollback the last database transaction for the connection.
     *
     * @return void
     */
    public function rollBack() {}

    /**
     * Apply a job's success or failure to the batch, once per job UUID.
     *
     * The batch lock keeps other writers out. The script also writes only if the batch is still what
     * was read, so even past an expired lock two counts cannot overwrite each other. A batch created
     * before this set existed counts from its next recorded success on; earlier ones are not in it.
     *
     * @param Closure(array<string, mixed>): ?array<string, mixed> $count the updated batch data, or null when the job was already counted
     */
    protected function countJobOnce(string $batchId, string $jobId, string $outcome, Closure $count): UpdatedBatchJobCounts
    {
        return $this->executeWithLock("lock:batch:{$batchId}", function () use ($batchId, $jobId, $outcome, $count) {
            /** @var PhpRedisConnection $redis the package requires phpredis (ext-redis) */
            $redis = Redis::connection(config('queue.batching.redis_connection', 'default'));

            for ($attempt = 1; $attempt <= 3; $attempt++) {
                $data = $redis->get("batch:{$batchId}");
                if (empty($data)) {
                    $this->debug("countJobOnce method - Batch ({$batchId}) does not exist.");

                    return new UpdatedBatchJobCounts(0, 0);
                }

                $batchData                   = json_decode($data, true);
                $batchData['failed_job_ids'] = (array) ($batchData['failed_job_ids'] ?? []);
                $updated                     = $count($batchData);

                if ($updated === null) {
                    return $this->alreadyCounted($batchData, $jobId);
                }

                // The script compares and writes the stored bytes, so the JSON goes in packed like set() packs it.
                [$expected, $replacement] = $redis->pack([$data, json_encode($updated, JSON_THROW_ON_ERROR)]);
                $written                  = $redis->eval(static::COUNT_JOB_ONCE_SCRIPT, 2, "batch:{$batchId}", $this->succeededJobIdsKey($batchId), $expected, $replacement, $jobId, $outcome);

                if ($written === 1) {
                    return new UpdatedBatchJobCounts($updated['pending_jobs'], $updated['failed_jobs']);
                }

                if ($written === 0) {
                    return $this->alreadyCounted($batchData, $jobId);
                }

                if ($written !== -1) {
                    throw new RuntimeException("RedisBatchRepository - Counting job {$jobId} of batch {$batchId} failed: " . ($redis->client()->getLastError() ?? 'unexpected script result'));
                }

                $this->debug("countJobOnce method - Batch ({$batchId}) changed while counting job ({$jobId}), attempt {$attempt}.");
            }

            throw new RuntimeException("RedisBatchRepository - Batch {$batchId} kept changing while counting job {$jobId}");
        }, 200, 200);
    }

    /**
     * @param array<string, mixed> $batchData
     */
    protected function alreadyCounted(array $batchData, string $jobId): AlreadyCountedJobCounts
    {
        $finishesBatch = $batchData['pending_jobs'] === 0
            && ! isset($batchData['finished_at'])
            && ($batchData['finishing_job_id'] ?? null) === $jobId;

        return new AlreadyCountedJobCounts($batchData['pending_jobs'], $batchData['failed_jobs'], $finishesBatch);
    }

    /**
     * The set of job UUIDs whose success the batch counted. It has no TTL, like the batch, and is
     * deleted with it.
     */
    protected function succeededJobIdsKey(string $batchId): string
    {
        return "batch:{$batchId}:succeeded_job_ids";
    }

    protected function acquireLock(string $key): bool
    {
        $isAcquired = Redis::connection(config('queue.batching.redis_connection', 'default'))->client()->set($key, (string) true, ['EX' => $this->lockTimeout, 'NX']);

        return (bool) $isAcquired;
    }

    protected function executeWithLock(string $lockKey, Closure $callback, int $retryCount = 3, int $sleepMilliseconds = 100): mixed
    {
        $attempts = 0;
        while ($retryCount > 0) {
            if ($this->acquireLock($lockKey)) {
                try {
                    if ($attempts > 5) {
                        $this->debug("finally got lock. Attempt: " . $attempts);
                    }

                    return $callback();
                } catch (Throwable $e) {
                    $this->debug("error in executeWithLock: " . $e->getMessage());
                    throw $e;
                } finally {
                    $this->releaseLock($lockKey);
                }
            } else {
                $attempts++;
                // Failed to acquire lock, decrease retry count and wait
                $retryCount--;
                usleep($sleepMilliseconds * 1000); // microseconds
            }
        }

        $this->debug("unable to acquire lock after ({$attempts}) attempts for key: {$lockKey}");
        throw new RuntimeException("RedisBatchRepository - Unable to acquire lock for key {$lockKey}");
    }

    protected function releaseLock(string $key): void
    {
        Redis::connection(config('queue.batching.redis_connection', 'default'))->del($key);
    }

    protected function serialize($value): string
    {
        return serialize($value);
    }

    protected function unserialize($serialized): mixed
    {
        try {
            return unserialize($serialized);
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @param array $batch
     *
     * @phpstan-param mixed $batch
     */
    protected function toBatch($batch): Batch
    {
        if ( ! isset($batch['id'])) {
            $this->debug('toBatch method - Missing batch[id]');
        }

        return $this->factory->make(
            $this,
            $batch['id'],
            $batch['name'],
            (int) $batch['total_jobs'],
            (int) $batch['pending_jobs'],
            (int) $batch['failed_jobs'],
            $batch['failed_job_ids'],
            $this->unserialize($batch['options']),
            CarbonImmutable::createFromTimestamp((int) $batch['created_at'], date_default_timezone_get()),
            isset($batch['cancelled_at']) ? CarbonImmutable::createFromTimestamp((int) $batch['cancelled_at'], date_default_timezone_get()) : null,
            isset($batch['finished_at']) ? CarbonImmutable::createFromTimestamp((int) $batch['finished_at'], date_default_timezone_get()) : null,
        );
    }

    protected function pruneBatches(DateTimeInterface $before, ?bool $isFinished = null, bool $isCancelled = false): int
    {
        $batchIds     = Redis::connection(config('queue.batching.redis_connection', 'default'))->lrange('batches_list', 0, -1);
        $totalDeleted = 0;

        foreach ($batchIds as $batchId) {
            $data = Redis::connection(config('queue.batching.redis_connection', 'default'))->get("batch:{$batchId}");

            if (empty($data)) {
                Redis::connection(config('queue.batching.redis_connection', 'default'))->del($this->succeededJobIdsKey($batchId));
                Redis::connection(config('queue.batching.redis_connection', 'default'))->client()->lrem('batches_list', $batchId, (int) 0);
                $this->debug("pruneBatches method - Batch ({$batchId}) does not exist.");
                continue;
            }

            $batchData = json_decode($data, true);

            $shouldBeDeleted = false;

            $createdAt   = CarbonImmutable::createFromTimestamp($batchData['created_at']);
            $finishedAt  = isset($batchData['finished_at']) ? CarbonImmutable::createFromTimestamp($batchData['finished_at']) : null;
            $cancelledAt = isset($batchData['cancelled_at']) ? CarbonImmutable::createFromTimestamp($batchData['cancelled_at']) : null;

            if ($isFinished === true && $finishedAt && $finishedAt < $before) {
                $shouldBeDeleted = true;
            } elseif ($isFinished === false && ! $finishedAt && $createdAt < $before) {
                $shouldBeDeleted = true;
            } elseif ($isCancelled && $cancelledAt && $createdAt < $before) {
                $shouldBeDeleted = true;
            }

            if ($shouldBeDeleted) {
                Redis::connection(config('queue.batching.redis_connection', 'default'))->del("batch:{$batchId}", $this->succeededJobIdsKey($batchId));
                Redis::connection(config('queue.batching.redis_connection', 'default'))->client()->lrem('batches_list', $batchId, (int) 0);
                $totalDeleted++;
            }
        }

        return $totalDeleted;
    }

    private function debug(string $message): void
    {
        if (config('queue.batching.debug', false)) {
            Log::debug("RedisBatchRepository: " . $message);
        }
    }
}
