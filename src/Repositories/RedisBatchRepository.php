<?php

namespace Cyppe\LaravelBatchJobsRedisDriver\Repositories;

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

class RedisBatchRepository extends DatabaseBatchRepository implements BatchRepository, PrunableBatchRepository
{
    protected int $lockTimeout;
    protected $factory;

    public function __construct( BatchFactory $factory )
    {
        $this->lockTimeout = 120;
        $this->factory     = $factory;
    }

    public function get($limit = 50, $before = null): array
    {
        if (!Redis::exists('batches_list')) {
            return [];
        }

        $totalBatches = Redis::llen('batches_list');

        // If $before is specified, find its index
        $beforeIndex = $before ? array_search($before, Redis::lrange('batches_list', 0, -1)) : null;

        // Determine the range to fetch
        if ($beforeIndex !== false && $beforeIndex !== null) {
            $rangeEnd = $beforeIndex - 1;
            $startIndex = max($rangeEnd - $limit + 1, 0);
        } else {
            $startIndex = max($totalBatches - $limit, 0);
            $rangeEnd = $totalBatches - 1;
        }

        // Fetch only the required batch IDs
        $batchIds = Redis::lrange('batches_list', $startIndex, $rangeEnd);

        // Use Redis pipeline to bulk fetch batch data
        $responses = Redis::pipeline(function ($pipe) use ($batchIds) {
            foreach ($batchIds as $batchId) {
                $pipe->get("batch:$batchId");
            }
        });

        // Filter, decode, and sort raw batch data
        $batchesRaw = array_map(fn($response) => json_decode($response, true), array_filter($responses));
        uasort($batchesRaw, function ($a, $b) {
            return $b['created_at'] <=> $a['created_at'];
        });

        // Map sorted data to Batch objects and convert to a sequential array
        $batches = array_values(array_map(fn($data) => $this->toBatch($data), $batchesRaw));

        return $batches;
    }

    public function find( string $batchId )
    {
        $data = Redis::get( "batch:$batchId" );

        if ( $data === false ) {
            // Return null or handle the case where the batch does not exist
            return null;
        }

        $batchData = json_decode( $data, true );
        return $this->toBatch( $batchData );
    }

    public function store( PendingBatch $batch )
    {
        $id = (string)Str::orderedUuid();

        $batchData = [
            'id'             => $id,
            'name'           => $batch->name,
            'total_jobs'     => 0,
            'pending_jobs'   => 0,
            'failed_jobs'    => 0,
            'failed_job_ids' => [],
            'options'        => $this->serialize( $batch->options ?? [] ),
            'created_at'     => time(),
            'cancelled_at'   => null,
            'finished_at'    => null,
        ];

        Redis::set( "batch:$id", json_encode( $batchData ) );
        Redis::rpush( 'batches_list', $id ); // Add the batch ID to the list

        return $this->find( $id );
    }

    public function incrementTotalJobs( string $batchId, int $amount )
    {
        return $this->executeWithLock( "lock:batch:$batchId", function () use ( $batchId, $amount ) {
            $data = Redis::get( "batch:$batchId" );
            if ( $data === false ) {
                Log::error( "Batch not found for incrementTotalJobs: " . $batchId );
                return new UpdatedBatchJobCounts( 0, 0 );
            }
            $batchData                 = json_decode( $data, true );
            $batchData['total_jobs']   += $amount;
            $batchData['pending_jobs'] += $amount;
            Redis::set( "batch:$batchId", json_encode( $batchData ) );
            return new UpdatedBatchJobCounts( $batchData['pending_jobs'], $batchData['failed_jobs'] );
        },                             100, 200 );
    }

    protected function acquireLock( string $key ): bool
    {
        $isAcquired = Redis::set( $key, true, 'EX', $this->lockTimeout, 'NX' );
        return (bool)$isAcquired;
    }

    protected function executeWithLock( string $lockKey, Closure $callback, $retryCount = 3, $sleepMilliseconds = 100 )
    {
        $attempts = 0;
        while ( $retryCount > 0 ) {
            if ( $this->acquireLock( $lockKey ) ) {
                try {
                    if ( $attempts > 2 ) {
                        //Log::info( "Finally got lock. Attempt: " . $attempts );
                    }
                    return $callback();
                } catch ( \Throwable $e ) {
                    Log::error( "Error in executeWithLock: " . $e->getMessage() );
                    throw $e;
                } finally {
                    $this->releaseLock( $lockKey );
                }
            } else {
                //Log::info( "Failed to get lock. Will try again. Attempt: $attempt" );
                $attempts++;
                // Failed to acquire lock, decrease retry count and wait
                $retryCount--;
                usleep( $sleepMilliseconds * 1000 ); // microseconds
            }
        }

        Log::warning( "Unable to acquire lock after ({$attempts}) attempts for key: $lockKey" );
        throw new \RuntimeException( "Unable to acquire lock for key $lockKey" );
    }

    protected function releaseLock( string $key )
    {
        Redis::del( $key );
    }

    public function incrementFailedJobs( string $batchId, string $jobId )
    {
        return $this->executeWithLock( "lock:batch:$batchId", function () use ( $batchId, $jobId ) {
            $data = Redis::get( "batch:$batchId" );
            if ( $data === false ) {
                Log::error( "Batch not found for incrementFailedJobs: " . $batchId );
                return new UpdatedBatchJobCounts( 0, 0 );
            }
            $batchData = json_decode( $data, true );
            $batchData['failed_jobs']++;
            $batchData['failed_job_ids'][] = $jobId;
            Redis::set( "batch:$batchId", json_encode( $batchData ) );
            return new UpdatedBatchJobCounts( $batchData['pending_jobs'], $batchData['failed_jobs'] );
        },                             100, 200 );
    }

    public function decrementPendingJobs( string $batchId, string $jobId )
    {
        return $this->executeWithLock( "lock:batch:$batchId", function () use ( $batchId, $jobId ) {
            $data = Redis::get( "batch:$batchId" );
            if ( $data === false ) {
                Log::error( "Batch not found for decrementPendingJobs: " . $batchId );
                return new UpdatedBatchJobCounts( 0, 0 );
            }
            $batchData = json_decode( $data, true );
            $batchData['pending_jobs']--;
            Redis::set( "batch:$batchId", json_encode( $batchData ) );
            return new UpdatedBatchJobCounts( $batchData['pending_jobs'], $batchData['failed_jobs'] );
        },                             100, 200 );
    }


    public function markAsFinished( string $batchId )
    {
        return $this->executeWithLock( "lock:batch:$batchId", function () use ( $batchId ) {
            $data = Redis::get( "batch:$batchId" );

            if ( $data === false ) {
                Log::debug( "Batch not found for markAsFinished: " . $batchId );
                return;
            }

            $batchData = json_decode( $data, true );
            // Convert finished_at to a Unix timestamp before storing
            $batchData['finished_at'] = CarbonImmutable::now()->getTimestamp();
            Redis::set( "batch:$batchId", json_encode( $batchData ) );

            Log::debug( "Batch marked as finished: " . $batchId . " with finished_at: " . $batchData['finished_at'] );
        },                             100, 200 );
    }


    public function delete( string $batchId )
    {
        if ( !Redis::exists( "batch:$batchId" ) ) {
            // Handle the case where the batch does not exist
            return;
        }

        Redis::del( "batch:$batchId" );
        Redis::lrem( 'batches_list', 0, $batchId );
    }

    protected function serialize( $value )
    {
        return serialize( $value );
    }

    protected function unserialize( $serialized )
    {
        return unserialize( $serialized );
    }

    protected function toBatch( $data ): Batch
    {
        return $this->factory->make(
            $this,
            $data['id'],
            $data['name'],
            (int)$data['total_jobs'],
            (int)$data['pending_jobs'],
            (int)$data['failed_jobs'],
            $data['failed_job_ids'],
            $this->unserialize( $data['options'] ),
            CarbonImmutable::createFromTimestamp( $data['created_at'] ),
            isset( $data['cancelled_at'] ) ? CarbonImmutable::createFromTimestamp( $data['cancelled_at'] ) : null,
            isset( $data['finished_at'] ) ? CarbonImmutable::createFromTimestamp( $data['finished_at'] ) : null
        );
    }

    public function cancel( string $batchId )
    {
        $this->executeWithLock( "lock:batch:$batchId", function () use ( $batchId ) {
            $data = Redis::get( "batch:$batchId" );
            if ( $data === false ) {
                return;
            }
            $batchData = json_decode( $data, true );
            // Convert cancelled_at to a Unix timestamp before storing
            $batchData['cancelled_at'] = CarbonImmutable::now()->getTimestamp();
            Redis::set( "batch:$batchId", json_encode( $batchData ) );
        },                      100, 200 ); // Retry 100 times with 200 milliseconds between retries
    }

    public function transaction( Closure $callback )
    {
        return $callback();
    }

    public function prune( DateTimeInterface $before )
    {
        return $this->pruneBatches( $before, true );
    }

    public function pruneUnfinished( DateTimeInterface $before )
    {
        return $this->pruneBatches( $before, false, false );
    }

    public function pruneCancelled( DateTimeInterface $before )
    {
        return $this->pruneBatches( $before, null, true );
    }

    protected function pruneBatches( DateTimeInterface $before, $isFinished = null, $isCancelled = false )
    {
        $batchIds     = Redis::lrange( 'batches_list', 0, -1 );
        $totalDeleted = 0;

        foreach ( $batchIds as $batchId ) {
            $data = Redis::get( "batch:$batchId" );

            if ( $data === false ) {
                Redis::lrem( 'batches_list', 0, $batchId );
                continue;
            }

            $batchData = json_decode( $data, true );

            $shouldBeDeleted = false;

            $createdAt   = CarbonImmutable::createFromTimestamp( $batchData['created_at'] );
            $finishedAt  = isset( $batchData['finished_at'] ) ? CarbonImmutable::createFromTimestamp( $batchData['finished_at'] ) : null;
            $cancelledAt = isset( $batchData['cancelled_at'] ) ? CarbonImmutable::createFromTimestamp( $batchData['cancelled_at'] ) : null;

            if ( $isFinished === true && $finishedAt && $finishedAt < $before ) {
                $shouldBeDeleted = true;
            } elseif ( $isFinished === false && !$finishedAt && $createdAt < $before ) {
                $shouldBeDeleted = true;
            } elseif ( $isCancelled && $cancelledAt && $createdAt < $before ) {
                $shouldBeDeleted = true;
            }

            if ( $shouldBeDeleted ) {
                Redis::del( "batch:$batchId" );
                Redis::lrem( 'batches_list', 0, $batchId );
                $totalDeleted++;
            }
        }

        return $totalDeleted;
    }

    /**
     * Rollback the last database transaction for the connection.
     *
     * @return void
     */
    public function rollBack()
    {
    }
}