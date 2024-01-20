<?php

namespace Cyppe\LaravelBatchJobsRedisDriver;

use Illuminate\Bus\BatchRepository;
use Illuminate\Bus\BusServiceProvider;
use Cyppe\LaravelBatchJobsRedisDriver\Repositories\RedisBatchRepository;

class CustomBusServiceProvider extends BusServiceProvider
{
    protected function registerBatchServices()
    {
        $this->app->singleton(
            BatchRepository::class, function ( $app ) {
            $driver = config( 'queue.batching.database' );
            if ( $driver === 'redis' ) {
                $factory = $app->make( \Illuminate\Bus\BatchFactory::class );
                return new RedisBatchRepository(
                    $factory
                );
            }

            // Fallback to parent method if not using Redis
            return parent::registerBatchServices();
        }
        );
    }
}