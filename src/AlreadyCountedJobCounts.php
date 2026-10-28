<?php

declare(strict_types=1);

namespace Cyppe\LaravelBatchJobsRedisDriver;

use Illuminate\Bus\UpdatedBatchJobCounts;

/**
 * The batch's current counts, returned when the job's success or failure was already counted.
 *
 * A job is counted once per job UUID. A redelivered job (its worker died after recording the
 * outcome and before deleting the job) records its outcome again; the repository then changes
 * nothing and returns this, so RedisBatch runs no callbacks for it.
 */
class AlreadyCountedJobCounts extends UpdatedBatchJobCounts
{
    public function __construct(int $pendingJobs, int $failedJobs, public readonly bool $batchFinished)
    {
        parent::__construct($pendingJobs, $failedJobs);
    }

    /**
     * Whether every job was counted but the batch was never marked finished: the worker that counted
     * the last job stopped before Laravel marked the batch finished and ran `then`.
     */
    public function batchLeftUnfinished(): bool
    {
        return $this->pendingJobs === 0 && ! $this->batchFinished;
    }
}
