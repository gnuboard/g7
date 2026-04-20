<?php

namespace Tests\Unit\Http\Controllers\Base;

use App\Contracts\Extension\CacheInterface;
use App\Http\Controllers\Api\Base\PublicBaseController;
use Tests\TestCase;

/**
 * PublicBaseController::cached() 에러 응답 캐시 제외 검증.
 *
 * 확장 설치 직후·활성화 전 등의 일시적 상태에서 error 응답이 영구 캐시되어
 * 복구 후에도 잘못된 응답을 반환하던 문제를 방지하는 공용 룰 검증.
 */
class PublicBaseControllerCachedTest extends TestCase
{
    private function invokeCached(callable $callback, string $key = 'test-key', int $ttl = 60): mixed
    {
        $controller = new class extends PublicBaseController {
            public function callCached(string $key, callable $callback, int $ttl): mixed
            {
                return $this->cached($key, $callback, $ttl);
            }
        };

        return $controller->callCached($key, $callback, $ttl);
    }

    public function test_success_response_is_cached(): void
    {
        $cache = app(CacheInterface::class);
        $cache->forget('test-success');

        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;

            return ['success' => true, 'data' => ['foo' => 'bar']];
        };

        // 1회차 — 캐시 miss → callback 실행 + 저장
        $r1 = $this->invokeCached($callback, 'test-success');
        // 2회차 — 캐시 hit → callback 재실행 안 됨
        $r2 = $this->invokeCached($callback, 'test-success');

        $this->assertEquals($r1, $r2);
        $this->assertEquals(1, $calls, 'success 응답은 캐시되어 callback 이 한 번만 실행되어야 함');

        $cache->forget('test-success');
    }

    public function test_error_response_is_not_cached(): void
    {
        $cache = app(CacheInterface::class);
        $cache->forget('test-error');

        $calls = 0;
        $callback = function () use (&$calls) {
            $calls++;

            return ['error' => 'template_not_found'];
        };

        // 1회차 — callback 실행, 에러라 캐시 안 됨
        $r1 = $this->invokeCached($callback, 'test-error');
        // 2회차 — 여전히 캐시 miss, callback 재실행
        $r2 = $this->invokeCached($callback, 'test-error');

        $this->assertEquals($r1, $r2);
        $this->assertEquals(2, $calls, 'error 응답은 캐시되지 않아 callback 이 매번 실행되어야 함');
    }

    public function test_recovers_when_callback_stops_returning_error(): void
    {
        $cache = app(CacheInterface::class);
        $cache->forget('test-recover');

        $callCount = 0;
        $callback = function () use (&$callCount) {
            $callCount++;

            // 1~2회는 에러, 3회째부터 성공 (활성화 전 → 활성화 후 시뮬레이션)
            if ($callCount < 3) {
                return ['error' => 'template_not_found'];
            }

            return ['success' => true, 'data' => ['recovered' => true]];
        };

        $r1 = $this->invokeCached($callback, 'test-recover');
        $this->assertArrayHasKey('error', $r1);

        $r2 = $this->invokeCached($callback, 'test-recover');
        $this->assertArrayHasKey('error', $r2);

        // 3회째 — 성공 응답 반환되고 캐시에 저장
        $r3 = $this->invokeCached($callback, 'test-recover');
        $this->assertArrayHasKey('success', $r3);

        // 4회째 — 캐시 hit
        $r4 = $this->invokeCached($callback, 'test-recover');
        $this->assertEquals($r3, $r4);
        $this->assertEquals(3, $callCount, '3번째 호출에서 성공 후 캐시되어 4번째는 callback 재실행 안 됨');

        $cache->forget('test-recover');
    }
}
