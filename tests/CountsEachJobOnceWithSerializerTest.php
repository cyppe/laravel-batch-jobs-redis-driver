<?php

declare(strict_types=1);

namespace Cyppe\LaravelBatchJobsRedisDriver\Tests;

use Redis;

/**
 * The same behaviour on a connection whose phpredis serializer and compression encode stored values.
 */
class CountsEachJobOnceWithSerializerTest extends CountsEachJobOnceTest
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('database.redis.options.serializer', Redis::SERIALIZER_PHP);
        $app['config']->set('database.redis.options.compression', Redis::COMPRESSION_LZF);
    }
}
