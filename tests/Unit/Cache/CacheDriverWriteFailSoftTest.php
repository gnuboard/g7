<?php

namespace Tests\Unit\Cache;

use App\Extension\Cache\CoreCacheDriver;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

/**
 * 캐시 쓰기 실패는 페이지를 죽이면 안 된다 (fail-soft).
 *
 * 실사례 (7.0.9→7.0.10 sudo 업데이트): root 로 실행된 업데이트가 캐시 키 인덱스
 * 파일(`g7:_idx:g7:core` — 모든 remember 가 갱신)을 root 소유로 남기면, 이후 웹
 * 프로세스의 **모든** 캐시 쓰기가 fopen Permission denied 예외로 죽어 부팅 경로
 * 전면 500 이 된다 (로거 도달 전이라 laravel.log 무기록). 캐시는 최적화일 뿐이므로
 * 쓰기 실패는 경고 로그 + 무캐시 동작으로 강등되어야 한다.
 */
class CacheDriverWriteFailSoftTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.stores.g7_failing_store' => ['driver' => 'g7_failing_store']]);

        Cache::extend('g7_failing_store', function () {
            return Cache::repository(new class implements Store
            {
                public array $data = [];

                public function get($key)
                {
                    return $this->data[$key] ?? null;
                }

                public function many(array $keys)
                {
                    return array_map(fn ($k) => $this->get($k), array_combine($keys, $keys));
                }

                public function put($key, $value, $seconds)
                {
                    throw new \RuntimeException("fopen({$key}): Failed to open stream: Permission denied");
                }

                public function putMany(array $values, $seconds)
                {
                    throw new \RuntimeException('putMany: Permission denied');
                }

                public function increment($key, $value = 1)
                {
                    throw new \RuntimeException('increment: Permission denied');
                }

                public function decrement($key, $value = 1)
                {
                    throw new \RuntimeException('decrement: Permission denied');
                }

                public function forever($key, $value)
                {
                    throw new \RuntimeException('forever: Permission denied');
                }

                public function forget($key)
                {
                    throw new \RuntimeException('forget: Permission denied');
                }

                public function flush()
                {
                    throw new \RuntimeException('flush: Permission denied');
                }

                public function getPrefix()
                {
                    return '';
                }
            });
        });
    }

    private function driver(): CoreCacheDriver
    {
        return new CoreCacheDriver('g7_failing_store');
    }

    /**
     * put 실패는 예외를 전파하지 않고 false 를 반환한다.
     */
    public function test_put_write_failure_returns_false_without_throwing(): void
    {
        $this->assertFalse($this->driver()->put('some.key', 'value'));
    }

    /**
     * remember 는 저장(put/인덱스 기록)이 실패해도 콜백 결과를 반환한다 —
     * 부팅 경로의 remember 하나가 죽으면 화면 전체가 500 이 되기 때문.
     * 콜백은 정확히 1회만 실행되어야 한다 (재실행 부수효과 금지).
     */
    public function test_remember_returns_callback_value_when_write_fails(): void
    {
        $calls = 0;

        $value = $this->driver()->remember('boot.key', function () use (&$calls) {
            $calls++;

            return ['ok' => true];
        });

        $this->assertSame(['ok' => true], $value);
        $this->assertSame(1, $calls);
    }

    /**
     * forget 실패도 예외를 전파하지 않는다.
     */
    public function test_forget_write_failure_returns_false_without_throwing(): void
    {
        $this->assertFalse($this->driver()->forget('some.key'));
    }

    /**
     * putMany 실패도 예외를 전파하지 않는다.
     */
    public function test_put_many_write_failure_returns_false_without_throwing(): void
    {
        $this->assertFalse($this->driver()->putMany(['a' => 1, 'b' => 2]));
    }
}
