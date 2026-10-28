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
    /**
     * @param bool $finishesBatch this job's success brought pending_jobs to 0, but the batch was never
     *                            marked finished: its worker stopped before Laravel finished the batch
     */
    public function __construct(int $pendingJobs, int $failedJobs, public readonly bool $finishesBatch)
    {
        parent::__construct($pendingJobs, $failedJobs);
    }
}
