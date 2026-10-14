<?php

namespace App\Support\Queue;

use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * 같은 잡 사본이 동시에 두 번 돌지 않게 하는 단일 실행 잠금 (#139)
 *
 * 큐 잡이 연결의 retry_after 를 넘겨 돌면 다른 워커가 같은 잡을 다시 집는다. 디스패치 때 만든 ID(잡 클래스 +
 * dispatchId)로 캐시 잠금을 잡고, 두 번째 사본은 잠금을 얻지 못하면 아무 일도 하지 않고 빠진다.
 *
 * 잠금 TTL 은 잡 timeout 보다 넉넉하게 준다 — 워커가 강제 종료되면 TTL 이 지나야 풀린다.
 */
final class JobSingleFlight
{
    /** 캐시 키 접두사 */
    private const PREFIX = 'queue:single-flight:';

    /**
     * 잠금 키를 만듭니다.
     *
     * @param  string  $jobClass  잡 클래스 FQCN
     * @param  string  $dispatchId  디스패치 때 만든 ID
     * @return string 캐시 잠금 키
     */
    public static function key(string $jobClass, string $dispatchId): string
    {
        return self::PREFIX.sha1($jobClass.'|'.$dispatchId);
    }

    /**
     * 잠금을 잡고 작업을 실행합니다 — 다른 사본이 쥐고 있으면 실행하지 않습니다.
     *
     * @param  string  $key  잠금 키
     * @param  int  $ttlSeconds  잠금 TTL(초)
     * @param  Closure  $callback  실행할 작업
     * @return bool 실행했으면 true, 다른 사본이 쥐고 있어 건너뛰었으면 false
     */
    public static function run(string $key, int $ttlSeconds, Closure $callback): bool
    {
        $lock = Cache::lock($key, max(1, $ttlSeconds));

        if (! $lock->get()) {
            return false;
        }

        try {
            $callback();
        } finally {
            $lock->release();
        }

        return true;
    }

    /**
     * 잠금이 지금 잡혀 있는지 확인합니다.
     *
     * 확인용으로 잠금을 잠깐 잡았다가 바로 푼다 — 잡혀 있으면 얻지 못한다.
     *
     * @param  string  $key  잠금 키
     * @return bool 다른 사본이 쥐고 있으면 true
     */
    public static function isHeld(string $key): bool
    {
        $probe = Cache::lock($key, 1);

        if ($probe->get()) {
            $probe->release();

            return false;
        }

        return true;
    }
}
