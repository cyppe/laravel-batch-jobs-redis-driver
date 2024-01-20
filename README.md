# Laravel Batch Jobs Redis Driver

## Overview

The Laravel Batch Jobs Redis Driver offers a performance-optimized alternative for handling batch jobs in Laravel. This package replaces the default MySQL `job_batches` table with a Redis driver, significantly improving processing efficiency, especially under heavy workloads.

Fully compatible with Laravel Horizon.
![Alt text](https://private-user-images.githubusercontent.com/591720/297328799-98793411-f79f-4a0e-9fa3-e5e463d25f12.png?jwt=eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJpc3MiOiJnaXRodWIuY29tIiwiYXVkIjoicmF3LmdpdGh1YnVzZXJjb250ZW50LmNvbSIsImtleSI6ImtleTUiLCJleHAiOjE3MDU3NTk2NzUsIm5iZiI6MTcwNTc1OTM3NSwicGF0aCI6Ii81OTE3MjAvMjk3MzI4Nzk5LTk4NzkzNDExLWY3OWYtNGEwZS05ZmEzLWU1ZTQ2M2QyNWYxMi5wbmc_WC1BbXotQWxnb3JpdGhtPUFXUzQtSE1BQy1TSEEyNTYmWC1BbXotQ3JlZGVudGlhbD1BS0lBVkNPRFlMU0E1M1BRSzRaQSUyRjIwMjQwMTIwJTJGdXMtZWFzdC0xJTJGczMlMkZhd3M0X3JlcXVlc3QmWC1BbXotRGF0ZT0yMDI0MDEyMFQxNDAyNTVaJlgtQW16LUV4cGlyZXM9MzAwJlgtQW16LVNpZ25hdHVyZT1kNTQ5NzkwY2M4ZTkyMmRjZTY0MTIxNDc4ZTI0NjM0ZDU0YjA2ODQwNGE3ZjUzYjI5NTgyZWY3NDE3Mzk2MjdiJlgtQW16LVNpZ25lZEhlYWRlcnM9aG9zdCZhY3Rvcl9pZD0wJmtleV9pZD0wJnJlcG9faWQ9MCJ9.pU9hiGWQ9RquRpaC9kKe2s3grOb7pBjIAX5bb1wuYis "Laravel Horizon with this redis driver")


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
    'database' => 'redis', // Change this from 'mysql' to 'redis'
    'table'    => 'job_batches',
],
~~~

Ensure that your Redis connection is correctly configured in Laravel to use this driver effectively.

## Pruning batches

It fully supports pruning of batches with default Laravel command:

~~~bash 
php artisan queue:prune-batches --hours=24 --unfinished=24 --cancelled=24
~~~