<?php

namespace Tests\Unit\Cache;

use App\Extension\Cache\CoreCacheDriver;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Cache\Store;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Tests\TestCase;

/**
 * 캐시 키 목록(태그 인덱스) 잠금 단위 테스트 (#142 F)
 *
 * 키 목록은 「읽기 → 고치기 → 쓰기」라 여러 요청이 동시에 고치면 서로의 기록을 덮어써 키가 목록에서
 * 빠진다(빠진 키는 태그 비우기로 지워지지 않는다). 저장소가 잠금을 지원하면 고치는 동안 잠금을 걸고,
 * 지원하지 않거나 잠금이 실패하면 경고만 남기고 지금처럼 진행한다(fail-soft).
 */
class CacheKeyIndexLockTest extends TestCase
{
    /**
     * 잠금 호출을 기록하는 배열 저장소 — 이 테스트 클래스가 공유한다.
     */
    private static ?ArrayStore $spyStore = null;

    /**
     * 잠금 이름 기록
     *
     * @var array<int, string>
     */
    public static array $lockNames = [];

    /**
     * 시험 저장소 3종(잠금 미지원·잠금 기록·잠금 시간 초과)을 등록한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        self::$lockNames = [];

        config([
            'cache.stores.i142_no_lock' => ['driver' => 'i142_no_lock'],
            'cache.stores.i142_spy_lock' => ['driver' => 'i142_spy_lock'],
            'cache.stores.i142_timeout_lock' => ['driver' => 'i142_timeout_lock'],
            'cache.stores.i142_broken_lock' => ['driver' => 'i142_broken_lock'],
        ]);

        Cache::extend('i142_no_lock', fn () => Cache::repository(new class implements Store
        {
            /**
             * 저장된 값
             *
             * @var array<string, mixed>
             */
            public array $data = [];

            /**
             * 값을 읽는다.
             *
             * @param  string  $key  키
             * @return mixed 저장된 값 또는 null
             */
            public function get($key)
            {
                return $this->data[$key] ?? null;
            }

            /**
             * 여러 값을 읽는다.
             *
             * @param  array  $keys  키 목록
             * @return array 키 => 값
             */
            public function many(array $keys)
            {
                return array_map(fn ($k) => $this->get($k), array_combine($keys, $keys));
            }

            /**
             * 값을 저장한다.
             *
             * @param  string  $key  키
             * @param  mixed  $value  값
             * @param  int  $seconds  유효 시간(초)
             * @return bool 저장 여부
             */
            public function put($key, $value, $seconds)
            {
                $this->data[$key] = $value;

                return true;
            }

            /**
             * 여러 값을 저장한다.
             *
             * @param  array  $values  키 => 값
             * @param  int  $seconds  유효 시간(초)
             * @return bool 저장 여부
             */
            public function putMany(array $values, $seconds)
            {
                foreach ($values as $k => $v) {
                    $this->data[$k] = $v;
                }

                return true;
            }

            /**
             * 값을 늘린다.
             *
             * @param  string  $key  키
             * @param  int  $value  늘릴 양
             * @return int 늘어난 값
             */
            public function increment($key, $value = 1)
            {
                return $this->data[$key] = ($this->data[$key] ?? 0) + $value;
            }

            /**
             * 값을 줄인다.
             *
             * @param  string  $key  키
             * @param  int  $value  줄일 양
             * @return int 줄어든 값
             */
            public function decrement($key, $value = 1)
            {
                return $this->increment($key, -$value);
            }

            /**
             * 만료 없이 저장한다.
             *
             * @param  string  $key  키
             * @param  mixed  $value  값
             * @return bool 저장 여부
             */
            public function forever($key, $value)
            {
                return $this->put($key, $value, 0);
            }

            /**
             * 값을 지운다.
             *
             * @param  string  $key  키
             * @return bool 삭제 여부
             */
            public function forget($key)
            {
                unset($this->data[$key]);

                return true;
            }

            /**
             * 전부 지운다.
             *
             * @return bool 삭제 여부
             */
            public function flush()
            {
                $this->data = [];

                return true;
            }

            /**
             * 키 접두사를 돌려준다.
             *
             * @return string 빈 접두사
             */
            public function getPrefix()
            {
                return '';
            }
        }));

        Cache::extend('i142_spy_lock', fn () => Cache::repository(new class extends ArrayStore
        {
            /**
             * 잠금 이름을 기록하고 실제 배열 잠금을 돌려준다.
             *
             * @param  string  $name  잠금 이름
             * @param  int  $seconds  잠금 유지 시간(초)
             * @param  string|null  $owner  소유자
             * @return \Illuminate\Contracts\Cache\Lock 잠금
             */
            public function lock($name, $seconds = 0, $owner = null)
            {
                CacheKeyIndexLockTest::$lockNames[] = $name;

                return parent::lock($name, $seconds, $owner);
            }
        }));

        Cache::extend('i142_timeout_lock', fn () => Cache::repository(new class extends ArrayStore
        {
            /**
             * 잠금 이름을 기록하고 언제나 시간 초과가 나는 잠금을 돌려준다.
             *
             * @param  string  $name  잠금 이름
             * @param  int  $seconds  잠금 유지 시간(초)
             * @param  string|null  $owner  소유자
             * @return \Illuminate\Contracts\Cache\Lock 시간 초과 잠금
             */
            public function lock($name, $seconds = 0, $owner = null)
            {
                CacheKeyIndexLockTest::$lockNames[] = $name;

                return new class($name, $seconds, $owner) extends Lock
                {
                    /**
                     * 잠금을 얻지 못한다.
                     *
                     * @return bool 언제나 false
                     */
                    public function acquire()
                    {
                        return false;
                    }

                    /**
                     * 잠금을 푼다.
                     *
                     * @return bool 언제나 true
                     */
                    public function release()
                    {
                        return true;
                    }

                    /**
                     * 소유자와 무관하게 잠금을 푼다.
                     *
                     * @return void 반환값 없음
                     */
                    public function forceRelease() {}

                    /**
                     * 현재 소유자를 돌려준다.
                     *
                     * @return string|null 소유자 없음
                     */
                    protected function getCurrentOwner()
                    {
                        return null;
                    }

                    /**
                     * 기다리지 않고 곧바로 시간 초과를 던진다.
                     *
                     * @param  int  $seconds  기다릴 시간(초)
                     * @param  callable|null  $callback  잠금 안에서 실행할 콜백
                     * @return mixed 반환하지 않음
                     *
                     * @throws LockTimeoutException 언제나
                     */
                    public function block($seconds, $callback = null)
                    {
                        throw new LockTimeoutException('i142 index lock timeout');
                    }
                };
            }
        }));

        Cache::extend('i142_broken_lock', fn () => Cache::repository(new class extends ArrayStore
        {
            /**
             * 잠금 이름을 기록하고 얻으려 하면 저장소 오류가 나는 잠금을 돌려준다.
             *
             * @param  string  $name  잠금 이름
             * @param  int  $seconds  잠금 유지 시간(초)
             * @param  string|null  $owner  소유자
             * @return \Illuminate\Contracts\Cache\Lock 오류 잠금
             */
            public function lock($name, $seconds = 0, $owner = null)
            {
                CacheKeyIndexLockTest::$lockNames[] = $name;

                return new class($name, $seconds, $owner) extends Lock
                {
                    /**
                     * 잠금 저장소에 닿지 못한다.
                     *
                     * @return bool 반환하지 않음
                     *
                     * @throws \RuntimeException 언제나
                     */
                    public function acquire()
                    {
                        throw new \RuntimeException('i142 lock store down');
                    }

                    /**
                     * 잠금을 푼다.
                     *
                     * @return bool 언제나 true
                     */
                    public function release()
                    {
                        return true;
                    }

                    /**
                     * 소유자와 무관하게 잠금을 푼다.
                     *
                     * @return void 반환값 없음
                     */
                    public function forceRelease() {}

                    /**
                     * 현재 소유자를 돌려준다.
                     *
                     * @return string|null 소유자 없음
                     */
                    protected function getCurrentOwner()
                    {
                        return null;
                    }
                };
            }
        }));
    }

    /**
     * 잠금을 얻는 중에 저장소 오류가 나도 경고를 남기고 잠금 없이 키 목록을 고친다 — 키 목록 갱신이 빠지면
     * 그 키는 태그 비우기로 지워지지 않는다.
     *
     * @scenario store=lock_error
     *
     * @effects lock_error_warns_and_proceeds
     *
     * @return void 반환값 없음
     */
    public function test_lock_error_warns_and_proceeds(): void
    {
        Log::spy();

        $driver = new CoreCacheDriver('i142_broken_lock');

        $this->assertSame('A', $driver->remember('a', fn () => 'A', 60, ['t1']));
        $this->assertSame('B', $driver->remember('b', fn () => 'B', 60, ['t1']));

        $this->assertTrue($driver->flushTags(['t1']));
        $this->assertFalse($driver->has('a'));
        $this->assertFalse($driver->has('b'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context) => ($context['operation'] ?? null) === 'index-lock'
                && ($context['error'] ?? null) === 'i142 lock store down')
            ->atLeast()->once();
    }

    /**
     * 잠금을 지원하지 않는 저장소는 잠금 없이 지금처럼 키 목록을 고치고 태그로 비운다.
     *
     * @scenario store=no_lock_provider
     *
     * @effects index_mutation_runs_without_lock_when_unsupported
     *
     * @return void 반환값 없음
     */
    public function test_store_without_lock_provider_keeps_working(): void
    {
        $driver = new CoreCacheDriver('i142_no_lock');

        $driver->remember('a', fn () => 'A', 60, ['t1']);
        $driver->remember('b', fn () => 'B', 60, ['t1']);
        $this->assertTrue($driver->has('a'));

        $this->assertTrue($driver->flushTags(['t1']));
        $this->assertFalse($driver->has('a'));
        $this->assertFalse($driver->has('b'));
    }

    /**
     * 잠금을 지원하는 저장소에서는 키 목록을 고칠 때마다(기록·태그 비우기·전체 비우기) 키 목록 잠금을 건다.
     *
     * @scenario store=lock_provider
     *
     * @effects index_mutation_takes_lock
     *
     * @return void 반환값 없음
     */
    public function test_lock_is_taken_for_every_index_mutation(): void
    {
        $driver = new CoreCacheDriver('i142_spy_lock');

        $driver->remember('a', fn () => 'A', 60, ['t1']);
        $driver->flushTags(['t1']);
        $driver->remember('b', fn () => 'B', 60);
        $driver->flush();

        $this->assertCount(4, self::$lockNames);
        $this->assertSame(['g7:_idx:g7:core:lock'], array_values(array_unique(self::$lockNames)));
        $this->assertFalse($driver->has('a'));
        $this->assertFalse($driver->has('b'));
    }

    /**
     * 잠금 시간이 지나면 경고를 한 번 남기고 잠금 없이 진행한다 — 잠금 장애가 화면 장애가 되면 안 된다.
     *
     * @scenario store=lock_timeout
     *
     * @effects lock_timeout_warns_once_and_proceeds
     *
     * @return void 반환값 없음
     */
    public function test_lock_timeout_warns_once_and_proceeds(): void
    {
        Log::spy();

        $driver = new CoreCacheDriver('i142_timeout_lock');

        $this->assertSame('A', $driver->remember('a', fn () => 'A', 60, ['t1']));
        $this->assertSame('B', $driver->remember('b', fn () => 'B', 60, ['t1']));

        $this->assertTrue($driver->flushTags(['t1']));
        $this->assertFalse($driver->has('a'));
        $this->assertFalse($driver->has('b'));

        Log::shouldHaveReceived('warning')
            ->withArgs(fn ($message, $context) => ($context['operation'] ?? null) === 'index-lock'
                && ($context['error'] ?? null) === 'i142 index lock timeout')
            ->once();
    }
}
