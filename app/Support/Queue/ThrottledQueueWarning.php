<?php

namespace App\Support\Queue;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * 큐 배치 경고를 한 번씩만 남기는 헬퍼 (#139)
 *
 * 리스너 큐 옵션이 잘못됐거나 미등록 큐로 폴백되는 경고는 훅이 발화할 때마다 난다. PHP-FPM 은 요청마다
 * 프로세스 상태가 비므로 정적 변수만으로는 요청마다 같은 줄이 쌓인다 — 프로세스 안에서는 정적 1회,
 * 프로세스 사이에서는 캐시 잠금(원자적 "없을 때만 획득", TTL 동안 풀지 않음)으로 1회만 남긴다.
 */
final class ThrottledQueueWarning
{
    /**
     * 이 프로세스에서 이미 판단한 경고 키
     *
     * @var array<string, true>
     */
    private static array $seen = [];

    /**
     * 경고를 남깁니다 — 같은 키는 TTL 동안 한 번만 기록됩니다.
     *
     * 캐시가 실패해도 경고 때문에 호출자(훅 디스패치)가 깨지면 안 되므로, 그때는 프로세스 1회 규칙만으로 기록한다.
     *
     * @param  string  $key  경고 식별 키 (예: `opt-invalid|{listener}|{hook}|{key}`)
     * @param  string  $message  로그 메시지
     * @param  array<string, mixed>  $context  로그 맥락
     * @param  int  $ttl  같은 키를 다시 남기기까지의 시간(초)
     * @return bool 이번 호출에서 실제로 기록했으면 true
     */
    public static function warn(string $key, string $message, array $context = [], int $ttl = 3600): bool
    {
        if (isset(self::$seen[$key])) {
            return false;
        }

        self::$seen[$key] = true;

        // 프로세스 사이의 「한 번만」은 원자적이어야 한다 — 캐시 잠금 획득(없을 때만 성공)을 TTL 동안 풀지 않고 쓴다.
        try {
            if (! Cache::lock('queue:warned:'.sha1($key), $ttl)->get()) {
                return false;
            }
        } catch (Throwable) {
            // 캐시를 못 쓰면 프로세스 1회 규칙만 적용한다.
        }

        Log::warning($message, $context);

        return true;
    }

    /**
     * 프로세스 안의 기록을 비웁니다 (테스트 격리용).
     *
     * @return void 반환값 없음
     */
    public static function reset(): void
    {
        self::$seen = [];
    }
}
