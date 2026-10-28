<?php

declare(strict_types=1);

namespace Cyppe\LaravelBatchJobsRedisDriver\Tests\Fixtures;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

class BatchedJob implements ShouldQueue
{
    use Batchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void {}
}
