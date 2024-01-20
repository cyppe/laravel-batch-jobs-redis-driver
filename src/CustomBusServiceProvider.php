<?php

namespace Cyppe\LaravelBatchJobsRedisDriver;

use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\BusServiceProvider;
use Cyppe\LaravelBatchJobsRedisDriver\Repositories\RedisBatchRepository;

class CustomBusServiceProvider extends BusServiceProvider
{
    protected function registerBatchServices()
    {
        $driver = config('queue.batching.database');
        if ($driver === 'redis') {
            $this->app->singleton(
                BatchRepository::class, function ($app) {
                $factory = $app->make(\Illuminate\Bus\BatchFactory::class);
                return new RedisBatchRepository($factory);
            }
            );
        }
    }

}