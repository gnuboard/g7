<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default Queue Connection Name
    |--------------------------------------------------------------------------
    |
    | Laravel's queue supports a variety of backends via a single, unified
    | API, giving you convenient access to each backend using identical
    | syntax for each. The default queue connection is defined below.
    |
    */

    'default' => env('QUEUE_CONNECTION', 'database'),

    /*
    |--------------------------------------------------------------------------
    | Queue Connections
    |--------------------------------------------------------------------------
    |
    | Here you may configure the connection options for every queue backend
    | used by your application. An example configuration is provided for
    | each backend supported by Laravel. You're also free to add more.
    |
    | Drivers: "sync", "database", "beanstalkd", "sqs", "redis", "null"
    |
    */

    'connections' => [

        'sync' => [
            'driver' => 'sync',
        ],

        'database' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => env('DB_QUEUE', 'default'),
            'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90),
            'after_commit' => false,
        ],

        // 긴 작업 전용 연결 — 통계 재집계·익명화·개인정보 직권 실행처럼 최대 1시간 도는 잡을 위한 것이다.
        // retry_after 는 연결 단위라 기본 연결(90초)에 두면 실행 중인 잡을 다른 워커가 다시 집는다.
        // 같은 jobs 표를 쓰므로 워커는 반드시 이 연결 이름으로 띄운다(`queue:work database-long --queue=long`).
        // 3900 = 최장 잡 timeout 3600 + 여유 300.
        'database-long' => [
            'driver' => 'database',
            'connection' => env('DB_QUEUE_CONNECTION'),
            'table' => env('DB_QUEUE_TABLE', 'jobs'),
            'queue' => 'long',
            'retry_after' => (int) env('DB_QUEUE_LONG_RETRY_AFTER', 3900),
            'after_commit' => false,
        ],

        'beanstalkd' => [
            'driver' => 'beanstalkd',
            'host' => env('BEANSTALKD_QUEUE_HOST', 'localhost'),
            'queue' => env('BEANSTALKD_QUEUE', 'default'),
            'retry_after' => (int) env('BEANSTALKD_QUEUE_RETRY_AFTER', 90),
            'block_for' => 0,
            'after_commit' => false,
        ],

        'sqs' => [
            'driver' => 'sqs',
            'key' => env('AWS_ACCESS_KEY_ID'),
            'secret' => env('AWS_SECRET_ACCESS_KEY'),
            'prefix' => env('SQS_PREFIX', 'https://sqs.us-east-1.amazonaws.com/your-account-id'),
            'queue' => env('SQS_QUEUE', 'default'),
            'suffix' => env('SQS_SUFFIX'),
            'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
            'after_commit' => false,
        ],

        'redis' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => env('REDIS_QUEUE', 'default'),
            'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90),
            'block_for' => null,
            'after_commit' => false,
        ],

        // 긴 작업 전용 Redis 연결 — database-long 과 같은 이유. 워커는 `queue:work redis-long --queue=long`.
        'redis-long' => [
            'driver' => 'redis',
            'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
            'queue' => 'long',
            'retry_after' => (int) env('REDIS_QUEUE_LONG_RETRY_AFTER', 3900),
            'block_for' => null,
            'after_commit' => false,
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | 큐 워커 레지스트리 (#139)
    |--------------------------------------------------------------------------
    |
    | 훅 리스너·코어 잡이 쓰는 큐는 운영자가 환경설정 → 드라이버 탭 「큐 워커」에 등록한
    | 연결·큐만 허용한다(App\Services\Queue\QueueWorkerRegistry). 아래는 그 판단에 쓰는 값이다.
    |
    | heartbeat_interval_seconds: 워커 생존 신호를 캐시에 쓰는 최소 간격(프로세스당)
    | heartbeat_stale_seconds: 이 시간 넘게 신호가 없으면 화면에 「신호 없음」 경고
    | timeout_margin_seconds: 리스너 timeout 이 연결 retry_after 이상이면 retry_after − 이 값으로 줄인다
    | long_connection_suffix·long_queue: 긴 작업 배치 대상 — 「{기본 연결}{접미사}」 연결의 이 큐
    |
    */

    'worker_registry' => [
        'heartbeat_interval_seconds' => 30,
        'heartbeat_stale_seconds' => 300,
        'timeout_margin_seconds' => 10,
        'long_connection_suffix' => '-long',
        'long_queue' => 'long',
    ],

    /*
    |--------------------------------------------------------------------------
    | Job Batching
    |--------------------------------------------------------------------------
    |
    | The following options configure the database and table that store job
    | batching information. These options can be updated to any database
    | connection and table which has been defined by your application.
    |
    */

    'batching' => [
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'job_batches',
    ],

    /*
    |--------------------------------------------------------------------------
    | Failed Queue Jobs
    |--------------------------------------------------------------------------
    |
    | These options configure the behavior of failed queue job logging so you
    | can control how and where failed jobs are stored. Laravel ships with
    | support for storing failed jobs in a simple file or in a database.
    |
    | Supported drivers: "database-uuids", "dynamodb", "file", "null"
    |
    */

    'failed' => [
        'driver' => env('QUEUE_FAILED_DRIVER', 'database-uuids'),
        'database' => env('DB_CONNECTION', 'sqlite'),
        'table' => 'failed_jobs',
    ],

];
