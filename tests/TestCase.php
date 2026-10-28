<?php

declare(strict_types=1);

namespace Cyppe\LaravelBatchJobsRedisDriver\Tests;

use Cyppe\LaravelBatchJobsRedisDriver\CustomBusServiceProvider;
use Illuminate\Support\Facades\Redis;
use Orchestra\Testbench\TestCase as Orchestra;

/**
 * Runs against a real Redis (REDIS_HOST, default 127.0.0.1), on a key prefix so the scripts' keys are prefixed too.
 */
abstract class TestCase extends Orchestra
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->deleteTestKeys();
    }

    protected function getPackageProviders($app): array
    {
        return [CustomBusServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('queue.default', 'null');
        $app['config']->set('queue.batching.database', 'redis');
        $app['config']->set('queue.batching.redis_connection', 'default');
        $app['config']->set('database.redis.client', 'phpredis');
        $app['config']->set('database.redis.options.prefix', 'batch-test:');
        $app['config']->set('database.redis.default', [
            'host'     => env('REDIS_HOST', '127.0.0.1'),
            'port'     => env('REDIS_PORT', 6379),
            'database' => env('REDIS_DB', 15),
        ]);
    }

    /**
     * Delete only this suite's keys: the prefix confines them, and a shared database keeps its other keys.
     */
    private function deleteTestKeys(): void
    {
        $redis  = Redis::connection();
        $prefix = (string) $redis->client()->getOption(\Redis::OPT_PREFIX);

        foreach ($redis->keys('*') as $key) {
            $redis->del(substr($key, strlen($prefix)));
        }
    }
}
