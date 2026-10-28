<?php

declare(strict_types=1);

namespace Cyppe\LaravelBatchJobsRedisDriver;

use Carbon\CarbonImmutable;
use Illuminate\Bus\BatchFactory;
use Illuminate\Bus\BatchRepository;

/**
 * Makes RedisBatch instances, so every batch the Redis repository returns counts each job once.
 */
class RedisBatchFactory extends BatchFactory
{
    /**
     * @param array<int, string>   $failedJobIds
     * @param array<string, mixed> $options
     */
    public function make(
        BatchRepository $repository,
        string $id,
        string $name,
        int $totalJobs,
        int $pendingJobs,
        int $failedJobs,
        array $failedJobIds,
        array $options,
        CarbonImmutable $createdAt,
        ?CarbonImmutable $cancelledAt,
        ?CarbonImmutable $finishedAt,
    ) {
        return new RedisBatch($this->queue, $repository, $id, $name, $totalJobs, $pendingJobs, $failedJobs, $failedJobIds, $options, $createdAt, $cancelledAt, $finishedAt);
    }
}
