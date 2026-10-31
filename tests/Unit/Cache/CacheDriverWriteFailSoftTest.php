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
 *
 * @scenario-manifest tests/scenarios/cache-driver-write-fail-soft.yaml
 */
class CacheDriverWriteFailSoftTest extends TestCase
{
    /**
     * 모든 쓰기 연산이 Permission denied 예외를 던지는 시험용 저장소(g7_failing_store)를 등록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['cache.stores.g7_failing_store' => ['driver' => 'g7_failing_store']]);

        Cache::extend('g7_failing_store', function () {
            return Cache::repository(new class implements Store
            {
                public array $data = [];

                /**
                 * 메모리 배열에서 값을 읽습니다.
                 *
                 * @param  string  $key  캐시 키
                 * @return mixed 저장된 값, 없으면 null
                 */
                public function get($key)
                {
                    return $this->data[$key] ?? null;
                }

                /**
                 * 여러 키를 메모리 배열에서 읽습니다.
                 *
                 * @param  array  $keys  캐시 키 목록
                 * @return array 키별 값 (없는 키는 null)
                 */
                public function many(array $keys)
                {
                    return array_map(fn ($k) => $this->get($k), array_combine($keys, $keys));
                }

                /**
                 * 권한 거부를 흉내 내 항상 예외를 던집니다.
                 *
                 * @param  string  $key  캐시 키
                 * @param  mixed  $value  값
                 * @param  int  $seconds  TTL (초)
                 * @return bool 반환하지 않음 (항상 예외)
                 *
                 * @throws \RuntimeException 쓰기 권한 거부
                 */
                public function put($key, $value, $seconds)
                {
                    throw new \RuntimeException("fopen({$key}): Failed to open stream: Permission denied");
                }

                /**
                 * 권한 거부를 흉내 내 항상 예외를 던집니다.
                 *
                 * @param  array  $values  키와 값 목록
                 * @param  int  $seconds  TTL (초)
                 * @return bool 반환하지 않음 (항상 예외)
                 *
                 * @throws \RuntimeException 쓰기 권한 거부
                 */
                public function putMany(array $values, $seconds)
                {
                    throw new \RuntimeException('putMany: Permission denied');
                }

                /**
                 * 권한 거부를 흉내 내 항상 예외를 던집니다.
                 *
                 * @param  string  $key  캐시 키
                 * @param  mixed  $value  증가량
                 * @return int|bool 반환하지 않음 (항상 예외)
                 *
                 * @throws \RuntimeException 쓰기 권한 거부
                 */
                public function increment($key, $value = 1)
                {
                    throw new \RuntimeException('increment: Permission denied');
                }

                /**
                 * 권한 거부를 흉내 내 항상 예외를 던집니다.
                 *
                 * @param  string  $key  캐시 키
                 * @param  mixed  $value  감소량
                 * @return int|bool 반환하지 않음 (항상 예외)
                 *
                 * @throws \RuntimeException 쓰기 권한 거부
                 */
                public function decrement($key, $value = 1)
                {
                    throw new \RuntimeException('decrement: Permission denied');
                }

                /**
                 * 권한 거부를 흉내 내 항상 예외를 던집니다.
                 *
                 * @param  string  $key  캐시 키
                 * @param  mixed  $value  값
                 * @return bool 반환하지 않음 (항상 예외)
                 *
                 * @throws \RuntimeException 쓰기 권한 거부
                 */
                public function forever($key, $value)
                {
                    throw new \RuntimeException('forever: Permission denied');
                }

                /**
                 * 권한 거부를 흉내 내 항상 예외를 던집니다.
                 *
                 * @param  string  $key  캐시 키
                 * @return bool 반환하지 않음 (항상 예외)
                 *
                 * @throws \RuntimeException 쓰기 권한 거부
                 */
                public function forget($key)
                {
                    throw new \RuntimeException('forget: Permission denied');
                }

                /**
                 * 권한 거부를 흉내 내 항상 예외를 던집니다.
                 *
                 * @return bool 반환하지 않음 (항상 예외)
                 *
                 * @throws \RuntimeException 쓰기 권한 거부
                 */
                public function flush()
                {
                    throw new \RuntimeException('flush: Permission denied');
                }

                /**
                 * 키 접두사를 돌려줍니다 (접두사 없음).
                 *
                 * @return string 빈 문자열
                 */
                public function getPrefix()
                {
                    return '';
                }
            });
        });
    }

    /**
     * 쓰기가 실패하는 시험용 저장소를 쓰는 코어 드라이버를 만듭니다.
     *
     * @return CoreCacheDriver 시험용 코어 드라이버
     */
    private function driver(): CoreCacheDriver
    {
        return new CoreCacheDriver('g7_failing_store');
    }

    /**
     * put 실패는 예외를 전파하지 않고 false 를 반환한다.
     *
     * @scenario case=put_failure
     *
     * @effects write_failure_not_thrown, write_failure_returns_false
     *
     * @return void 반환값 없음
     */
    public function test_put_write_failure_returns_false_without_throwing(): void
    {
        $this->assertFalse($this->driver()->put('some.key', 'value'));
    }

    /**
     * remember 는 저장(put/인덱스 기록)이 실패해도 콜백 결과를 반환한다 —
     * 부팅 경로의 remember 하나가 죽으면 화면 전체가 500 이 되기 때문.
     * 콜백은 정확히 1회만 실행되어야 한다 (재실행 부수효과 금지).
     *
     * @scenario case=remember_failure
     *
     * @effects write_failure_not_thrown, remember_returns_callback_value_once
     *
     * @return void 반환값 없음
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
     *
     * @scenario case=forget_failure
     *
     * @effects write_failure_not_thrown, write_failure_returns_false
     *
     * @return void 반환값 없음
     */
    public function test_forget_write_failure_returns_false_without_throwing(): void
    {
        $this->assertFalse($this->driver()->forget('some.key'));
    }

    /**
     * putMany 실패도 예외를 전파하지 않는다.
     *
     * @scenario case=put_many_failure
     *
     * @effects write_failure_not_thrown, write_failure_returns_false
     *
     * @return void 반환값 없음
     */
    public function test_put_many_write_failure_returns_false_without_throwing(): void
    {
        $this->assertFalse($this->driver()->putMany(['a' => 1, 'b' => 2]));
    }
}
