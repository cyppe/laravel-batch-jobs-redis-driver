<?php

declare(strict_types=1);

namespace Cyppe\LaravelBatchJobsRedisDriver;

use Illuminate\Bus\Batch;
use Illuminate\Bus\UpdatedBatchJobCounts;

/**
 * A batch that records each job's success and failure once per job UUID.
 *
 * When the repository reports the job as already counted, the batch records nothing and runs no
 * callbacks, so `then`, `catch` and `finally` fire once, after every job ran. Otherwise Laravel's
 * own bookkeeping runs unchanged, with the counts the repository just returned.
 *
 * One exception: when the last job's success was counted but the batch was never marked finished
 * (its worker stopped in between), the redelivered job finishes the batch, so `then` still runs.
 * Laravel marks the batch finished before it runs `then`, so a worker that stops after that point
 * still loses `then`, as it would without this package.
 */
class RedisBatch extends Batch
{
    /**
     * The counts the repository returned for the job being recorded, handed once to Laravel's bookkeeping.
     */
    private ?UpdatedBatchJobCounts $countsBeingRecorded = null;

    public function recordSuccessfulJob(string $jobId)
    {
        $counts = $this->repository->decrementPendingJobs($this->id, $jobId);

        if ($counts instanceof AlreadyCountedJobCounts && ! $counts->batchLeftUnfinished()) {
            return;
        }

        $this->countsBeingRecorded = $counts;

        try {
            parent::recordSuccessfulJob($jobId);
        } finally {
            $this->countsBeingRecorded = null;
        }
    }

    public function decrementPendingJobs(string $jobId)
    {
        return $this->takeCountsBeingRecorded() ?? parent::decrementPendingJobs($jobId);
    }

    public function recordFailedJob(string $jobId, $e)
    {
        $counts = $this->repository->incrementFailedJobs($this->id, $jobId);

        if ($counts instanceof AlreadyCountedJobCounts) {
            return;
        }

        $this->countsBeingRecorded = $counts;

        try {
            parent::recordFailedJob($jobId, $e);
        } finally {
            $this->countsBeingRecorded = null;
        }
    }

    public function incrementFailedJobs(string $jobId)
    {
        return $this->takeCountsBeingRecorded() ?? parent::incrementFailedJobs($jobId);
    }

    private function takeCountsBeingRecorded(): ?UpdatedBatchJobCounts
    {
        $counts                    = $this->countsBeingRecorded;
        $this->countsBeingRecorded = null;

        return $counts;
    }
}
