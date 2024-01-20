# Laravel Batch Jobs Redis Driver

## Overview

The Laravel Batch Jobs Redis Driver offers a performance-optimized alternative for handling batch jobs in Laravel. This package replaces the default MySQL `job_batches` table with a Redis driver, significantly improving processing efficiency, especially under heavy workloads.

## Installation

### Laravel

install

~~~bash
composer require "cyppe/laravel-batch-jobs-redis-driver"
~~~

## Important Configuration

**Before using this Redis driver, you must update your `config/queue.php` configuration.** Set the `database` key under the `batching` section to `'redis'`. Without this adjustment, Laravel will default to using the MySQL driver.

~~~php
'batching' => [
    'database' => 'redis', // Change this from 'mysql' to 'redis'
    'table'    => 'job_batches',
],
~~~

Ensure that your Redis connection is correctly configured in Laravel to use this driver effectively.
