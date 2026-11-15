<?php

namespace App\Analytics;

use Illuminate\Cache\DatabaseStore;
use Illuminate\Cache\DynamoDbStore;
use Illuminate\Cache\MemcachedStore;
use Illuminate\Cache\RedisStore;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * 방문 적재 잠금 — 같은 방문자의 적재를 한 줄로 세웁니다.
 *
 * 같은 방문자의 요청 두 개(탭 두 개, 연속 비콘, 서버 폴백과 비콘)가 동시에 오면 둘 다 「열린
 * 방문」을 범위 조건으로 잠그며 읽습니다. InnoDB 는 그 범위의 행 사이 간격까지 잠그므로, 방문이
 * 없을 때 두 요청이 같은 간격을 잠근 채 서로의 INSERT 를 기다리거나 뒤 요청의 간격 잠금이 앞
 * 요청의 갱신을 막아 교착(1213)이 납니다. 간격 잠금은 방문이 두 개 생기는 것을 막는 장치라
 * 그냥 없앨 수 없습니다.
 *
 * 그래서 적재 트랜잭션 **바깥에서** 방문자 키로 캐시 잠금을 잡아 같은 방문자의 적재를 차례로
 * 돌립니다. 잠금을 쥔 쪽만 적재하므로 열린 방문은 잠금 없이 읽고 찾은 행만 기본키로 잠그면
 * 됩니다 — 간격 잠금 경쟁이 사라집니다. 잠금은 트랜잭션이 확정된 뒤에 풀리므로 다음 요청은
 * 앞 요청이 남긴 방문을 봅니다. 다른 방문자끼리는 키가 달라 서로 기다리지 않습니다.
 *
 * 이 잠금은 캐시 저장소가 **서버 간에 공유되고 원자 잠금을 지원할 때만** 씁니다(DB·Redis·
 * Memcached·DynamoDB). 파일 캐시는 서버마다 따로라 여러 서버에서 한 줄로 세우지 못하므로
 * 지금 방식(범위 잠금 + 재시도)을 그대로 씁니다. 잠금을 쓸 수 없거나 정해진 시간 안에 잡지
 * 못하거나 캐시 저장소가 오류를 내면 언제나 지금 방식으로 되돌아갑니다 — 수집을 멈추지 않습니다.
 */
class VisitorStoreLock
{
    /**
     * 잠금 키의 접두
     */
    public const KEY_PREFIX = 'analytics_visit:';

    /**
     * 잠금 유지 시간 (초) — 해제가 빠져도 이 시간이 지나면 저절로 풀린다
     */
    public const LOCK_SECONDS = 5;

    /**
     * 잠금을 기다리는 최대 시간 (초) — 넘기면 지금 방식으로 적재한다
     */
    public const WAIT_SECONDS = 2;

    /**
     * 잠금을 다시 묻는 간격 (밀리초)
     *
     * 같은 방문자의 적재 한 번은 수십 밀리초 안에 끝난다. 프레임워크 기본값(250ms)으로 기다리면
     * 비콘 응답이 그만큼 늦어지므로 촘촘히 다시 묻는다.
     */
    private const RETRY_SLEEP_MILLISECONDS = 25;

    /**
     * 서버 간에 공유되고 원자 잠금을 지원하는 캐시 저장소
     *
     * 목록에 없는 저장소(파일·배열·null·확장이 등록한 드라이버)는 공유 여부를 알 수 없으므로
     * 지금 방식으로 적재한다.
     *
     * @var array<int, class-string>
     */
    private const SHARED_STORES = [
        DatabaseStore::class,
        RedisStore::class,
        MemcachedStore::class,
        DynamoDbStore::class,
    ];

    /**
     * 방문자 키의 잠금 키를 돌려줍니다.
     *
     * @param  string  $visitorKey  방문자 키
     * @return string 잠금 키
     */
    public static function key(string $visitorKey): string
    {
        return self::KEY_PREFIX.$visitorKey;
    }

    /**
     * 지금 이 잠금으로 적재를 한 줄로 세울 수 있는지 판정합니다.
     *
     * 바깥 트랜잭션 안에서는 쓰지 않습니다. 잠금은 그 트랜잭션이 확정되기 전에 풀려 다음 요청이
     * 확정되지 않은 방문을 보지 못하고, DB 캐시 저장소라면 잠금 행 자체가 바깥 트랜잭션에 묶여
     * 다른 요청이 잠금 대기 대신 DB 잠금 대기에 빠집니다.
     *
     * @return bool 쓸 수 있으면 true
     */
    public function isAvailable(): bool
    {
        if (DB::transactionLevel() > 0) {
            return false;
        }

        try {
            $store = Cache::getStore();
        } catch (\Throwable) {
            return false;
        }

        foreach (self::SHARED_STORES as $class) {
            if ($store instanceof $class) {
                return true;
            }
        }

        return false;
    }

    /**
     * 방문자 키들을 잠근 채 적재하고, 잠글 수 없으면 지금 방식으로 적재합니다.
     *
     * 키가 여럿이면(쿠키를 방금 받은 첫 비콘은 서버 폴백의 임시 키도 함께 잠근다) 정렬한
     * 순서로 잡아 두 요청이 서로의 키를 쥔 채 기다리지 않게 합니다. 대기 시간은 키 전체에
     * 한 번만 씁니다.
     *
     * @template T
     *
     * @param  array<int, string>  $visitorKeys  잠글 방문자 키
     * @param  callable(): T  $serialized  잠금을 쥔 채 할 적재
     * @param  callable(): T  $fallback  잠글 수 없을 때 할 적재 (지금 방식)
     * @return T 적재 결과
     */
    public function run(array $visitorKeys, callable $serialized, callable $fallback): mixed
    {
        if (! $this->isAvailable()) {
            return $fallback();
        }

        $locks = $this->acquire($visitorKeys);

        if ($locks === null) {
            return $fallback();
        }

        try {
            return $serialized();
        } finally {
            $this->release($locks);
        }
    }

    /**
     * 키들을 정렬한 순서로 잠급니다.
     *
     * 프레임워크의 `block()` 은 벽시계(`now()`)로 대기 시간을 재므로 시각을 고정한 환경에서는
     * 끝나지 않을 수 있습니다. 단조 시계로 직접 기다립니다.
     *
     * @param  array<int, string>  $visitorKeys  잠글 방문자 키
     * @return array<int, Lock>|null 잡은 잠금 (하나라도 못 잡았으면 잡은 것을 풀고 null)
     */
    private function acquire(array $visitorKeys): ?array
    {
        $keys = array_values(array_unique(array_filter($visitorKeys, fn ($key): bool => $key !== '')));
        sort($keys, SORT_STRING);

        $deadline = hrtime(true) + self::WAIT_SECONDS * 1_000_000_000;
        $locks = [];

        try {
            foreach ($keys as $visitorKey) {
                $lock = Cache::lock(self::key($visitorKey), self::LOCK_SECONDS);

                while (! $lock->get()) {
                    if (hrtime(true) >= $deadline) {
                        $this->release($locks);

                        return null;
                    }

                    usleep(self::RETRY_SLEEP_MILLISECONDS * 1000);
                }

                $locks[] = $lock;
            }
        } catch (\Throwable $e) {
            // 캐시 저장소 장애 — 수집을 멈추지 않고 지금 방식으로 적재한다.
            Log::warning('방문 적재 잠금을 얻지 못해 지금 방식으로 적재합니다.', ['error' => $e->getMessage()]);
            $this->release($locks);

            return null;
        }

        return $locks;
    }

    /**
     * 잡은 잠금을 풉니다. 풀지 못해도 유지 시간이 지나면 풀리므로 오류는 삼킵니다.
     *
     * @param  array<int, Lock>  $locks  잡은 잠금
     * @return void 없음
     */
    private function release(array $locks): void
    {
        foreach (array_reverse($locks) as $lock) {
            try {
                $lock->release();
            } catch (\Throwable) {
                // 유지 시간(LOCK_SECONDS)이 지나면 저절로 풀린다.
            }
        }
    }
}
