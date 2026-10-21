<?php

namespace App\Extension;

use App\Jobs\DispatchHookListenerJob;
use App\Services\Queue\QueueWorkerRegistry;
use App\Support\Queue\ThrottledQueueWarning;

/**
 * 훅 리스너의 큐 실행 옵션 (#139)
 *
 * `getSubscribedHooks()` 의 `'queue' => [...]` 덩어리에 라라벨 큐 리스너와 같은 10개 옵션을 적을 수 있다.
 * 옵션이 없으면 종전 동작(재시도 3·제한시간 없음·커밋 후·기본 연결/큐) 그대로다.
 *
 * ```php
 * 'sirsoft-board.post.after_create' => [
 *     'method' => 'handlePostCreated',
 *     'queue' => ['queue' => 'notifications', 'tries' => 5, 'timeout' => 60, 'backoff' => [10, 60]],
 * ],
 * ```
 *
 * - 큐·연결은 **운영자가 등록한 것만** 쓴다(QueueWorkerRegistry). 미등록이면 기본값 + 경고.
 * - timeout 이 해석된 연결의 retry_after 이상이면 `retry_after − 10` 으로 줄인다 — 그대로 두면 실행 중인
 *   잡을 다른 워커가 다시 집어 같은 일을 두 번 한다.
 * - 잘못된 키·값은 **그 키만** 버리고 경고한다(다른 옵션은 살린다).
 */
final class ListenerQueueOptions
{
    /** 허용 옵션 키 (라라벨 큐 리스너와 같은 10개) */
    public const KEYS = [
        'connection',
        'queue',
        'delay',
        'after_commit',
        'tries',
        'timeout',
        'backoff',
        'retry_until',
        'max_exceptions',
        'fail_on_timeout',
    ];

    /** tries 상한 — 0(무제한)은 허용하지 않는다 */
    private const MAX_TRIES = 25;

    /**
     * 리스너가 선언한 큐 옵션을 검사해 유효한 키만 돌려줍니다.
     *
     * @param  mixed  $raw  `'queue'` 덩어리 원본
     * @param  string  $listener  리스너 FQCN (경고 문맥)
     * @param  string  $hook  훅 이름 (경고 문맥)
     * @return array<string, mixed> 유효한 옵션 (없으면 빈 배열)
     */
    public static function normalize(mixed $raw, string $listener, string $hook): array
    {
        if ($raw === null || $raw === []) {
            return [];
        }

        if (! is_array($raw)) {
            ThrottledQueueWarning::warn(
                "opt-invalid|{$listener}|{$hook}|*",
                '훅 리스너 큐 옵션이 배열이 아니어서 무시합니다',
                ['listener' => $listener, 'hook' => $hook, 'type' => get_debug_type($raw)],
            );

            return [];
        }

        $options = [];

        foreach ($raw as $key => $value) {
            if (! in_array($key, self::KEYS, true) || ! self::isValid((string) $key, $value)) {
                ThrottledQueueWarning::warn(
                    "opt-invalid|{$listener}|{$hook}|{$key}",
                    '훅 리스너 큐 옵션의 키 또는 값이 올바르지 않아 그 키만 무시합니다',
                    ['listener' => $listener, 'hook' => $hook, 'key' => $key, 'value' => is_scalar($value) ? $value : get_debug_type($value)],
                );

                continue;
            }

            $options[$key] = $key === 'backoff' && is_array($value) ? array_values($value) : $value;
        }

        return $options;
    }

    /**
     * 정규화된 옵션을 디스패치할 잡에 적용합니다.
     *
     * 순서: ① 연결·큐 해석(미등록이면 폴백 + 경고·카운트) ② delay ③ after_commit ④ tries·backoff·
     * max_exceptions·fail_on_timeout ⑤ retry_until(디스패치 시점 기준) ⑥ timeout 클램프.
     *
     * @param  DispatchHookListenerJob  $job  디스패치할 잡
     * @param  array<string, mixed>  $options  normalize() 결과
     * @param  string  $listener  리스너 FQCN (경고 문맥)
     * @param  string  $hook  훅 이름 (경고 문맥)
     * @return DispatchHookListenerJob 옵션을 적용한 같은 잡
     */
    public static function apply(DispatchHookListenerJob $job, array $options, string $listener, string $hook): DispatchHookListenerJob
    {
        $registry = app(QueueWorkerRegistry::class);

        if (array_key_exists('connection', $options) || array_key_exists('queue', $options)) {
            $requestedConnection = $options['connection'] ?? null;
            $requestedQueue = $options['queue'] ?? null;
            $resolved = $registry->resolve($requestedConnection, $requestedQueue);

            if ($resolved['connection'] !== null) {
                $job->onConnection($resolved['connection']);
            }
            if ($resolved['queue'] !== null) {
                $job->onQueue($resolved['queue']);
            }

            if ($resolved['fallback'] !== []) {
                $info = [
                    'listener' => $listener,
                    'hook' => $hook,
                    'requested_connection' => $requestedConnection,
                    'requested_queue' => $requestedQueue,
                    'reasons' => $resolved['fallback'],
                ];
                $registry->recordFallback($info);
                ThrottledQueueWarning::warn(
                    "fallback|{$listener}|{$hook}|".implode(',', $resolved['fallback']),
                    '훅 리스너가 등록되지 않은 큐 연결·큐를 가리켜 기본값으로 보냅니다 — 환경설정 → 드라이버 → 큐 워커에 등록하세요',
                    $info,
                );
            }
        }

        if (isset($options['delay'])) {
            $job->delay((int) $options['delay']);
        }

        if (($options['after_commit'] ?? null) === false) {
            $job->beforeCommit();
        } elseif (($options['after_commit'] ?? null) === true) {
            $job->afterCommit();
        }

        if (isset($options['tries'])) {
            $job->tries = (int) $options['tries'];
        }
        if (isset($options['backoff'])) {
            $job->backoff = $options['backoff'];
        }
        if (isset($options['max_exceptions'])) {
            $job->maxExceptions = (int) $options['max_exceptions'];
        }
        if (isset($options['fail_on_timeout'])) {
            $job->failOnTimeout = (bool) $options['fail_on_timeout'];
        }
        if (isset($options['retry_until'])) {
            $job->retryUntil = now()->addSeconds((int) $options['retry_until']);
        }

        if (isset($options['timeout'])) {
            $job->timeout = self::clampTimeout((int) $options['timeout'], $job, $registry, $listener, $hook);
        }

        return $job;
    }

    /**
     * timeout 이 해석된 연결의 retry_after 이상이면 그 아래로 줄입니다.
     *
     * retry_after 개념이 없는 드라이버(sync 등)는 검사하지 않는다.
     *
     * @param  int  $timeout  선언한 timeout(초)
     * @param  DispatchHookListenerJob  $job  잡 (해석된 연결·큐)
     * @param  QueueWorkerRegistry  $registry  레지스트리
     * @param  string  $listener  리스너 FQCN
     * @param  string  $hook  훅 이름
     * @return int 적용할 timeout(초)
     */
    private static function clampTimeout(int $timeout, DispatchHookListenerJob $job, QueueWorkerRegistry $registry, string $listener, string $hook): int
    {
        $retryAfter = $registry->retryAfter($job->connection);

        if ($retryAfter === null || $timeout < $retryAfter) {
            return $timeout;
        }

        $applied = max(1, $retryAfter - (int) config('queue.worker_registry.timeout_margin_seconds', 10));
        $info = [
            'listener' => $listener,
            'hook' => $hook,
            'connection' => $job->connection ?? $registry->defaultConnection(),
            'queue' => $job->queue ?? 'default',
            'requested' => $timeout,
            'applied' => $applied,
            'retry_after' => $retryAfter,
        ];

        $registry->recordTimeoutClamp($info);
        ThrottledQueueWarning::warn(
            "clamp|{$listener}|{$hook}|{$info['connection']}",
            '훅 리스너 timeout 이 큐 연결의 retry_after 이상이라 줄여서 보냅니다 — 실행 중인 잡을 다른 워커가 다시 집지 않게 하려면 긴 작업 연결(*-long)을 쓰세요',
            $info,
        );

        return $applied;
    }

    /**
     * 옵션 값 하나가 허용 범위인지 판정합니다.
     *
     * @param  string  $key  옵션 키
     * @param  mixed  $value  값
     * @return bool 유효하면 true
     */
    private static function isValid(string $key, mixed $value): bool
    {
        return match ($key) {
            'connection' => is_string($value) && $value !== '' && mb_strlen($value) <= 64,
            'queue' => is_string($value) && preg_match('/^[A-Za-z0-9_.:\-]{1,64}$/', $value) === 1,
            'delay' => is_int($value) && $value >= 0,
            'after_commit', 'fail_on_timeout' => is_bool($value),
            'tries' => is_int($value) && $value >= 1 && $value <= self::MAX_TRIES,
            'timeout', 'retry_until', 'max_exceptions' => is_int($value) && $value >= 1,
            'backoff' => self::isValidBackoff($value),
            default => false,
        };
    }

    /**
     * backoff 값이 0 이상 정수 또는 그런 정수의 비지 않은 목록인지 판정합니다.
     *
     * @param  mixed  $value  값
     * @return bool 유효하면 true
     */
    private static function isValidBackoff(mixed $value): bool
    {
        if (is_int($value)) {
            return $value >= 0;
        }

        if (! is_array($value) || $value === [] || ! array_is_list($value)) {
            return false;
        }

        foreach ($value as $seconds) {
            if (! is_int($seconds) || $seconds < 0) {
                return false;
            }
        }

        return true;
    }
}
