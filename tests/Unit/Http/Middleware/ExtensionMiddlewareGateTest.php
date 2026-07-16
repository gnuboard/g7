<?php

namespace Tests\Unit\Http\Middleware;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Http\Middleware\ExtensionMiddlewareGate;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Mockery;
use Tests\TestCase;

/**
 * 확장 미들웨어 self-gate 실행기 테스트.
 *
 * 게이트가 registry 매칭 결과에 따라 확장 미들웨어를 Pipeline 으로 실행하거나
 * 즉시 통과하는지, 무명 라우트·timing·실행 순서·예외 격리를 검증합니다.
 */
class ExtensionMiddlewareGateTest extends TestCase
{
    /**
     * registry 를 mock 하고 지정 매칭 결과를 반환하는 게이트를 만듭니다.
     *
     * @param  array<int, class-string>  $matched  resolveForRoute 반환값
     */
    private function makeGate(array $matched): ExtensionMiddlewareGate
    {
        $registry = Mockery::mock(ExtensionMiddlewareRegistryInterface::class);
        $registry->shouldReceive('resolveForRoute')->andReturn($matched);

        return new ExtensionMiddlewareGate($this->app, $registry);
    }

    /**
     * @effects gate_runs_matched_middleware_via_pipeline
     */
    public function test_matched_middleware_runs(): void
    {
        GateSpyMiddleware::$ran = false;
        $gate = $this->makeGate([GateSpyMiddleware::class]);

        $response = $gate->handle(Request::create('/api/foo', 'GET'), fn ($req) => new Response('ok'), 'api', 'after_core');

        $this->assertTrue(GateSpyMiddleware::$ran, '매칭된 확장 미들웨어가 실행되어야 합니다.');
        $this->assertSame('ok', $response->getContent());
    }

    /**
     * @effects gate_passes_through_when_no_match
     */
    public function test_no_match_passes_through(): void
    {
        GateSpyMiddleware::$ran = false;
        $gate = $this->makeGate([]);

        $response = $gate->handle(Request::create('/api/foo', 'GET'), fn ($req) => new Response('passed'), 'api', 'after_core');

        $this->assertFalse(GateSpyMiddleware::$ran, '매칭 없으면 미들웨어를 실행하지 않습니다.');
        $this->assertSame('passed', $response->getContent());
    }

    /**
     * @effects gate_matched_middleware_can_short_circuit_response
     */
    public function test_matched_middleware_can_short_circuit_response(): void
    {
        $gate = $this->makeGate([GateBlockMiddleware::class]);

        $response = $gate->handle(Request::create('/api/foo', 'GET'), fn ($req) => new Response('should-not-reach'), 'api', 'after_core');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('blocked', $response->getContent());
    }

    /**
     * @effects gate_execution_order_follows_matched_list
     */
    public function test_execution_order_follows_matched_list(): void
    {
        GateOrderMiddlewareA::$log = [];
        $gate = $this->makeGate([GateOrderMiddlewareA::class, GateOrderMiddlewareB::class]);

        $gate->handle(Request::create('/api/foo', 'GET'), fn ($req) => new Response('ok'), 'api', 'after_core');

        $this->assertSame(['A', 'B'], GateOrderMiddlewareA::$log, '매칭 리스트 순서대로 실행되어야 합니다.');
    }

    /**
     * @effects gate_reaches_registry_and_runs_matched_for_unnamed_route
     */
    public function test_unnamed_route_still_reaches_registry_and_runs_matched(): void
    {
        GateSpyMiddleware::$ran = false;
        // 무명 라우트 요청 (route()->getName()=null) 에서도 게이트가 registry 를 조회하고
        // URI 매칭으로 반환된 미들웨어를 실행한다 (조기 통과하지 않음).
        $gate = $this->makeGate([GateSpyMiddleware::class]);

        $gate->handle(Request::create('/', 'GET'), fn ($req) => new Response('ok'), 'web', 'after_core');

        $this->assertTrue(GateSpyMiddleware::$ran, '무명 라우트에서도 매칭 미들웨어가 실행되어야 합니다.');
    }

    public function test_middleware_exception_propagates(): void
    {
        $gate = $this->makeGate([GateThrowMiddleware::class]);

        $this->expectException(\RuntimeException::class);
        $gate->handle(Request::create('/api/foo', 'GET'), fn ($req) => new Response('ok'), 'api', 'after_core');
    }
}

/** 실행 여부만 기록하는 픽스처 미들웨어. */
class GateSpyMiddleware
{
    public static bool $ran = false;

    public function handle($request, \Closure $next)
    {
        self::$ran = true;

        return $next($request);
    }
}

/** 응답을 사전 차단하는 픽스처 미들웨어. */
class GateBlockMiddleware
{
    public function handle($request, \Closure $next)
    {
        return new Response('blocked', 403);
    }
}

/** 실행 순서를 기록하는 픽스처 미들웨어 A. */
class GateOrderMiddlewareA
{
    /** @var array<int, string> */
    public static array $log = [];

    public function handle($request, \Closure $next)
    {
        self::$log[] = 'A';

        return $next($request);
    }
}

/** 실행 순서를 기록하는 픽스처 미들웨어 B. */
class GateOrderMiddlewareB
{
    public function handle($request, \Closure $next)
    {
        GateOrderMiddlewareA::$log[] = 'B';

        return $next($request);
    }
}

/** 예외를 던지는 픽스처 미들웨어. */
class GateThrowMiddleware
{
    public function handle($request, \Closure $next)
    {
        throw new \RuntimeException('boom');
    }
}
