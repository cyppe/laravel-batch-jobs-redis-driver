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

## Each job counts once

A queued job can be delivered twice: its worker records the job's success, then dies before it deletes the job, and the job runs again after `retry_after`. Laravel then records the same job's success twice, and in the database driver `pending_jobs` drops twice, so `then` can fire while another job of the batch has not run yet.

This driver counts each job's success, and its failure, once per job UUID, in one atomic Redis step:

- A second success of the same job changes nothing and runs no callbacks: `then`, `catch` and `finally` fire once, after every job ran.
- A job recorded as failed twice counts as one failed job.
- A failed job that later succeeds (for example through `queue:retry`) lowers `pending_jobs` once and leaves `failed_job_ids`, as in Laravel.
- A failure recorded after the same job's success was counted (a redelivery that then exceeds its max attempts) is ignored.

The UUIDs of the counted successes live in a Redis set `batch:{id}:succeeded_job_ids`, which has no TTL, like the batch itself, and is deleted with it by `delete()` and `queue:prune-batches`. A batch created before this version counts from its next recorded success on.

If the worker that counted a batch's last job stops before Laravel marks the batch finished, that job's redelivery finishes the batch and runs `then`, whether the redelivery succeeds or fails. Only the job whose success brought `pending_jobs` to 0 can do this. A redelivery that fails is still logged as a failed job, and the job's own `failed()` method runs after `then`. A worker that stops after the batch was marked finished but before `then` ran loses `then`, as it would with Laravel's own drivers.

These guarantees assume a job is never delivered again while a worker still runs it: the worker's `timeout` must be shorter than the queue's `retry_after`, as Laravel requires, so a stuck or stalled worker is killed before its job is delivered again. Otherwise two deliveries of a batch's last job can both run `then` and `finally`. A batch cancelled before its last job's worker stops is not finished by the redelivery: `then` and `finally` do not run for it.

The count runs as one Lua script over the batch key and its set, so the batch connection must be a single Redis node (or a replica set), not Redis Cluster: the two keys can land in different cluster slots.

With phpredis compression on the batch connection, use phpredis 5.3.5 or newer: older versions need the separate `lzf` or `zstd` PHP extension to pack a compressed value for the script, and cannot pack LZ4. A serializer alone works with any version.

## Pruning batches

It fully supports pruning of batches with default Laravel command:

~~~bash 
php artisan queue:prune-batches --hours=24 --unfinished=24 --cancelled=24
~~~
