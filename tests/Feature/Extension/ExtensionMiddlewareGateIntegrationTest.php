<?php

namespace Tests\Feature\Extension;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Extension\ExtensionMiddlewareRegistry;
use App\Http\Middleware\ExtensionMiddlewareGate;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Route;
use Mockery;
use Tests\TestCase;

/**
 * 게이트 그룹 등록 파싱 통합 테스트.
 *
 * bootstrap/app.php 가 `ExtensionMiddlewareGate::class.':web,after_core'` 형태의
 * 파라미터+그룹 문자열로 게이트를 등록하는데, 프로젝트 내 이 형태의 선례가 없어
 * Laravel MiddlewareNameResolver + Pipeline::parsePipeString 이 실제로
 * handle($req,$next,'web','after_core') 로 파싱하는지 실요청으로 검증한다.
 */
class ExtensionMiddlewareGateIntegrationTest extends TestCase
{
    protected function tearDown(): void
    {
        ExtensionMiddlewareRegistry::flush();
        parent::tearDown();
    }

    /**
     * @effects gate_param_string_parses_group_and_timing_on_real_request
     */
    public function test_gate_param_string_parses_group_and_timing_on_real_request(): void
    {
        IntegrationSpyMiddleware::$seen = [];
        IntegrationSpyMiddleware::$ran = false;

        // registry 를 mock — after_core/web 조회 시에만 매칭 미들웨어를 반환.
        $registry = Mockery::mock(ExtensionMiddlewareRegistryInterface::class);
        $registry->shouldReceive('resolveForRoute')
            ->andReturnUsing(function (string $routeName, string $path, string $group, string $timing): array {
                IntegrationSpyMiddleware::$seen[] = "{$group},{$timing}";

                return ($group === 'web' && $timing === 'after_core')
                    ? [IntegrationSpyMiddleware::class]
                    : [];
            });
        $this->app->instance(ExtensionMiddlewareRegistryInterface::class, $registry);

        // web 그룹에 게이트를 파라미터 문자열로 등록한 라우트 (bootstrap 등록 형태 복제).
        Route::middleware([
            ExtensionMiddlewareGate::class.':web,before_core',
            ExtensionMiddlewareGate::class.':web,after_core',
        ])->get('/__gate_probe', fn () => new Response('ok'))->name('gate.probe');

        $response = $this->get('/__gate_probe');

        $response->assertOk();
        $response->assertSee('ok');

        // 게이트가 group/timing 파라미터를 정확히 파싱해 registry 를 조회했는지 확인.
        $this->assertContains('web,before_core', IntegrationSpyMiddleware::$seen);
        $this->assertContains('web,after_core', IntegrationSpyMiddleware::$seen);

        // after_core 조회에서 반환된 매칭 미들웨어가 실제로 실행됐는지 확인.
        $this->assertTrue(IntegrationSpyMiddleware::$ran, '파라미터 문자열 파싱 후 매칭 미들웨어가 실행되어야 합니다.');
    }
}

/** 실행/조회 기록 픽스처 미들웨어. */
class IntegrationSpyMiddleware
{
    public static bool $ran = false;

    /** @var array<int, string> */
    public static array $seen = [];

    public function handle($request, \Closure $next)
    {
        self::$ran = true;

        return $next($request);
    }
}
