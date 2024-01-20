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

        // Only bind the RedisBatchRepository when 'redis' is the selected driver
        if ($driver === 'redis') {
            $this->app->singleton(
                BatchRepository::class, function ($app) {
                $factory = $app->make(\Illuminate\Bus\BatchFactory::class);
                return new RedisBatchRepository($factory);
            }
            );
        }
        // If 'redis' is not the driver, do not override the default binding
    }

}