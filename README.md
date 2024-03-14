# Laravel Batch Jobs Redis Driver

## Overview

The Laravel Batch Jobs Redis Driver offers a performance-optimized alternative for handling batch jobs in Laravel. This package replaces the default MySQL `job_batches` table with a Redis driver, significantly improving processing efficiency, especially under heavy workloads.

## Fully compatible with Laravel Horizon Batch overview:

![CleanShot 2024-03-14 at 23 28 38@2x](https://github.com/cyppe/laravel-batch-jobs-redis-driver/assets/591720/678804d5-6758-4a6c-86e2-3205435d0568)


## Installation

### Laravel

install

~~~bash
composer require "cyppe/laravel-batch-jobs-redis-driver"
~~~

## Important Configuration

Before using this Redis driver, you must update your `config/queue.php` configuration.

Set the `database` key under the `batching` section to `'redis'`. Without this adjustment, Laravel will default to using the MySQL driver.

~~~php
'batching' => [
    'database'          => 'redis', // Change this from 'mysql' to 'redis'
    'redis_connection'  => 'default', // here you can define what redis connection to store batch related data in. Defaults to 'default' if not set.
    'table'             => 'job_batches',
    'debug'             => false,
],
~~~

Ensure that your Redis connection is correctly configured in Laravel to use this driver effectively.

## Pruning batches

It fully supports pruning of batches with default Laravel command:

~~~bash 
php artisan queue:prune-batches --hours=24 --unfinished=24 --cancelled=24
~~~
