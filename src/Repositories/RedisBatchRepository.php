<?php

namespace Cyppe\LaravelBatchJobsRedisDriver\Repositories;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Closure;
use DateTimeInterface;
use Illuminate\Bus\Batch;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\DatabaseBatchRepository;
use Illuminate\Bus\PendingBatch;
use Illuminate\Bus\PrunableBatchRepository;
use Illuminate\Bus\UpdatedBatchJobCounts;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class RedisBatchRepository extends DatabaseBatchRepository implements BatchRepository, PrunableBatchRepository
{
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
            $rangeEnd   = $beforeIndex - 1;
            $startIndex = max($rangeEnd - $limit + 1, 0);
        } else {
            $startIndex = max($totalBatches - $limit, 0);
            $rangeEnd   = $totalBatches - 1;
        }

        // Fetch only the required batch IDs
        $batchIds = Redis::connection(config('queue.batching.redis_connection', 'default'))->lrange('batches_list', $startIndex, $rangeEnd);

        // Use Redis pipeline to bulk fetch batch data
        $responses = Redis::connection(config('queue.batching.redis_connection', 'default'))->pipeline(function ($pipe) use ($batchIds) {
            foreach ($batchIds as $batchId) {
                $pipe->get("batch:{$batchId}");
            }
        });

        // Filter, decode, and sort raw batch data
        $batchesRaw = array_map(fn($response) => json_decode($response, true), array_filter($responses));
        uasort($batchesRaw, function ($a, $b) {
            return $b['created_at'] <=> $a['created_at'];
        });

        // Validation and logging for missing 'id' keys - temporary debug block
        // todo: remove this block when potential issue is verified
        foreach ($batchesRaw as $key => $data) {
            if ( ! isset($data['id'])) {
                Log::debug("RedisBatchRepository - get method", ['batch' => $data]);
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
            Log::debug("RedisBatchRepository - find method - Batch ({$batchId}) does not exist.");

            return null;
        }

        $batchData = json_decode($data, true);

        return $this->toBatch($batchData);
    }

    public function store(PendingBatch $batch)
    {
        $id = (string) Str::orderedUuid();

        $batchData = [
            'id'             => $id,
            'name'           => $batch->name,
            'total_jobs'     => 0,
            'pending_jobs'   => 0,
            'failed_jobs'    => 0,
            'failed_job_ids' => [],
            'options'        => $this->serialize($batch->options ?? []),
            'created_at'     => time(),
            'cancelled_at'   => null,
            'finished_at'    => null,
        ];

        Redis::connection(config('queue.batching.redis_connection', 'default'))->set("batch:{$id}", json_encode($batchData));
        Redis::connection(config('queue.batching.redis_connection', 'default'))->rpush('batches_list', $id); // Add the batch ID to the list

        return $this->find($id);
    }

    public function incrementTotalJobs(string $batchId, int $amount)
    {
        return $this->executeWithLock("lock:batch:{$batchId}", function () use ($batchId, $amount) {
            $data = Redis::connection(config('queue.batching.redis_connection', 'default'))->get("batch:{$batchId}");
            if (empty($data)) {
                Log::debug("RedisBatchRepository - incrementTotalJobs method - Batch ({$batchId}) does not exist.");

                return new UpdatedBatchJobCounts(0, 0);
            }
            $batchData = json_decode($data, true);
            $batchData['total_jobs'] += $amount;
            $batchData['pending_jobs'] += $amount;
            Redis::connection(config('queue.batching.redis_connection', 'default'))->set("batch:{$batchId}", json_encode($batchData));

            return new UpdatedBatchJobCounts($batchData['pending_jobs'], $batchData['failed_jobs']);
        }, 200, 200);
    }

    public function incrementFailedJobs(string $batchId, string $jobId)
    {
        return $this->executeWithLock("lock:batch:{$batchId}", function () use ($batchId, $jobId) {
            $data = Redis::connection(config('queue.batching.redis_connection', 'default'))->get("batch:{$batchId}");
            if (empty($data)) {
                Log::debug("RedisBatchRepository - incrementFailedJobs method - Batch ({$batchId}) does not exist.");

                return new UpdatedBatchJobCounts(0, 0);
            }
            $batchData = json_decode($data, true);
            $batchData['failed_jobs']++;
            $batchData['failed_job_ids'][] = $jobId;
            Redis::connection(config('queue.batching.redis_connection', 'default'))->set("batch:{$batchId}", json_encode($batchData));

            return new UpdatedBatchJobCounts($batchData['pending_jobs'], $batchData['failed_jobs']);
        }, 200, 200);
    }

    public function decrementPendingJobs(string $batchId, string $jobId)
    {
        return $this->executeWithLock("lock:batch:{$batchId}", function () use ($batchId) {
            $data = Redis::connection(config('queue.batching.redis_connection', 'default'))->get("batch:{$batchId}");
            if (empty($data)) {
                Log::debug("RedisBatchRepository - decrementPendingJobs method - Batch ({$batchId}) does not exist.");

                return new UpdatedBatchJobCounts(0, 0);
            }
            $batchData = json_decode($data, true);

            // Check if pending_jobs is greater than 0 before decrementing
            if ($batchData['pending_jobs'] > 0) {
                $batchData['pending_jobs']--;
            } else {
                // Will remove later - keeping for debug for now to see if it ever happens
                Log::debug("RedisBatchRepository - decrementPendingJobs method - Attempted to decrement pending_jobs below 0 for batch: " . $batchId);
            }

            Redis::connection(config('queue.batching.redis_connection', 'default'))->set("batch:{$batchId}", json_encode($batchData));

            return new UpdatedBatchJobCounts($batchData['pending_jobs'], $batchData['failed_jobs']);
        }, 200, 200);
    }

    public function markAsFinished(string $batchId)
    {
        return $this->executeWithLock("lock:batch:{$batchId}", function () use ($batchId) {
            $data = Redis::connection(config('queue.batching.redis_connection', 'default'))->get("batch:{$batchId}");

            if (empty($data)) {
                Log::debug("RedisBatchRepository - decrementPendingJobs method - Batch ({$batchId}) does not exist.");

                return;
            }

            $batchData = json_decode($data, true);
            // Convert finished_at to a Unix timestamp before storing
            $batchData['finished_at'] = CarbonImmutable::now()->getTimestamp();
            Redis::connection(config('queue.batching.redis_connection', 'default'))->set("batch:{$batchId}", json_encode($batchData));

            $created_at      = Carbon::createFromTimestamp($batchData['created_at']);
            $finished_at     = Carbon::createFromTimestamp($batchData['finished_at']);
            $created_at_str  = $created_at->format('Y-m-d H:i:s');
            $finished_at_str = $finished_at->format('Y-m-d H:i:s');
            $diff_for_humans = $created_at->diffForHumans($finished_at, true, true);

            Log::debug("RedisBatchRepository - markAsFinished method - Batch marked as finished. batch id: " . $batchId . ", batch name: {$batchData['name']}, created at: {$created_at_str}, finished at: {$finished_at_str}. Duration: {$diff_for_humans}.");
        }, 200, 200);
    }

    public function delete(string $batchId)
    {
        if ( ! Redis::connection(config('queue.batching.redis_connection', 'default'))->exists("batch:{$batchId}")) {
            // Handle the case where the batch does not exist
            return;
        }

        Redis::connection(config('queue.batching.redis_connection', 'default'))->del("batch:{$batchId}");
        Redis::connection(config('queue.batching.redis_connection', 'default'))->lrem('batches_list', 0, $batchId);
    }

    public function cancel(string $batchId)
    {
        $this->executeWithLock("lock:batch:{$batchId}", function () use ($batchId) {
            $data = Redis::connection(config('queue.batching.redis_connection', 'default'))->get("batch:{$batchId}");
            if (empty($data)) {
                Log::debug("RedisBatchRepository - cancel method - Batch ({$batchId}) does not exist.");

                return;
            }
            $batchData = json_decode($data, true);
            // Convert cancelled_at to a Unix timestamp before storing
            $batchData['cancelled_at'] = CarbonImmutable::now()->getTimestamp();
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

    protected function acquireLock(string $key): bool
    {
        $isAcquired = Redis::connection(config('queue.batching.redis_connection', 'default'))->set($key, true, 'EX', $this->lockTimeout, 'NX');

        return (bool) $isAcquired;
    }

    protected function executeWithLock(string $lockKey, Closure $callback, $retryCount = 3, $sleepMilliseconds = 100)
    {
        $attempts = 0;
        while ($retryCount > 0) {
            if ($this->acquireLock($lockKey)) {
                try {
                    if ($attempts > 2) {
                        //Log::info( "Finally got lock. Attempt: " . $attempts );
                    }

                    return $callback();
                } catch (Throwable $e) {
                    Log::debug("RedisBatchRepository - Error in executeWithLock: " . $e->getMessage());
                    throw $e;
                } finally {
                    $this->releaseLock($lockKey);
                }
            } else {
                //Log::info( "Failed to get lock. Will try again. Attempt: $attempt" );
                $attempts++;
                // Failed to acquire lock, decrease retry count and wait
                $retryCount--;
                usleep($sleepMilliseconds * 1000); // microseconds
            }
        }

        Log::debug("RedisBatchRepository - Unable to acquire lock after ({$attempts}) attempts for key: {$lockKey}");
        throw new RuntimeException("RedisBatchRepository - Unable to acquire lock for key {$lockKey}");
    }

    protected function releaseLock(string $key)
    {
        Redis::connection(config('queue.batching.redis_connection', 'default'))->del($key);
    }

    protected function serialize($value)
    {
        return serialize($value);
    }

    protected function unserialize($serialized)
    {
        return unserialize($serialized);
    }

    protected function toBatch($batch): Batch
    {
        if ( ! isset($batch['id'])) {
            Log::debug('RedisBatchRepository - toBatch method - Missing batch ID', ['batch' => $batch]);
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
            CarbonImmutable::createFromTimestamp($batch['created_at']),
            isset($batch['cancelled_at']) ? CarbonImmutable::createFromTimestamp($batch['cancelled_at']) : null,
            isset($batch['finished_at']) ? CarbonImmutable::createFromTimestamp($batch['finished_at']) : null
        );
    }

    protected function pruneBatches(DateTimeInterface $before, $isFinished = null, $isCancelled = false)
    {
        $batchIds     = Redis::connection(config('queue.batching.redis_connection', 'default'))->lrange('batches_list', 0, -1);
        $totalDeleted = 0;

        foreach ($batchIds as $batchId) {
            $data = Redis::connection(config('queue.batching.redis_connection', 'default'))->get("batch:{$batchId}");

            if (empty($data)) {
                Redis::connection(config('queue.batching.redis_connection', 'default'))->lrem('batches_list', 0, $batchId);
                Log::debug("RedisBatchRepository - pruneBatches method - Batch ({$batchId}) does not exist.");
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
                Redis::connection(config('queue.batching.redis_connection', 'default'))->del("batch:{$batchId}");
                Redis::connection(config('queue.batching.redis_connection', 'default'))->lrem('batches_list', 0, $batchId);
                $totalDeleted++;
            }
        }

        return $totalDeleted;
    }
}
