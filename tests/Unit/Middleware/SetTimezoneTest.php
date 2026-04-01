<?php

namespace Tests\Unit\Middleware;

use App\Http\Middleware\SetTimezone;
use App\Models\User;
use App\Services\GeoIpService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Auth;
use Tests\TestCase;

class SetTimezoneTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();
    }

    /**
     * 미들웨어 테스트용 next 클로저를 생성합니다.
     */
    protected function getNextClosure(): \Closure
    {
        return fn () => new Response;
    }

    /**
     * SetTimezone 미들웨어 인스턴스를 생성합니다.
     */
    protected function createMiddleware(): SetTimezone
    {
        return app(SetTimezone::class);
    }

    /**
     * 인증된 사용자의 타임존이 우선 적용되는지 테스트합니다.
     */
    public function test_authenticated_user_timezone_has_priority(): void
    {
        $user = User::factory()->create(['timezone' => 'America/New_York']);
        Auth::login($user);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Timezone', 'Europe/London');

        $middleware = $this->createMiddleware();
        $middleware->handle($request, $this->getNextClosure());

        $this->assertEquals('America/New_York', SetTimezone::getTimezone());
    }

    /**
     * X-Timezone 헤더가 비인증 사용자에게 적용되는지 테스트합니다.
     */
    public function test_browser_timezone_header_applied_for_guest(): void
    {
        Auth::logout();

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Timezone', 'Europe/London');

        $middleware = $this->createMiddleware();
        $middleware->handle($request, $this->getNextClosure());

        $this->assertEquals('Europe/London', SetTimezone::getTimezone());
    }

    /**
     * 지원하지 않는 타임존은 무시되는지 테스트합니다.
     */
    public function test_unsupported_timezone_is_ignored(): void
    {
        Auth::logout();

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Timezone', 'Invalid/Timezone');

        $middleware = $this->createMiddleware();
        $middleware->handle($request, $this->getNextClosure());

        // 기본값이 적용되어야 함
        $this->assertEquals('Asia/Seoul', SetTimezone::getTimezone());
    }

    /**
     * 타임존 설정이 없는 경우 기본값이 적용되는지 테스트합니다.
     */
    public function test_default_timezone_applied_when_no_setting(): void
    {
        Auth::logout();

        $request = Request::create('/api/test', 'GET');

        $middleware = $this->createMiddleware();
        $middleware->handle($request, $this->getNextClosure());

        $this->assertEquals('Asia/Seoul', SetTimezone::getTimezone());
    }

    /**
     * 인증된 사용자의 지원하지 않는 타임존은 무시되는지 테스트합니다.
     */
    public function test_authenticated_user_unsupported_timezone_falls_back(): void
    {
        $user = User::factory()->create(['timezone' => 'Invalid/Timezone']);
        Auth::login($user);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Timezone', 'Europe/London');

        $middleware = $this->createMiddleware();
        $middleware->handle($request, $this->getNextClosure());

        // 사용자 타임존이 유효하지 않으므로 헤더의 타임존이 적용
        $this->assertEquals('Europe/London', SetTimezone::getTimezone());
    }

    /**
     * getTimezone 정적 메서드가 미들웨어 실행 전에도 기본값을 반환하는지 테스트합니다.
     */
    public function test_get_timezone_returns_default_before_middleware(): void
    {
        // App::forgetInstance를 사용하여 기존 바인딩 제거
        App::forgetInstance('user_timezone');

        $this->assertEquals('Asia/Seoul', SetTimezone::getTimezone());
    }

    /**
     * GeoIP가 활성화되었을 때 IP 기반 타임존이 적용되는지 테스트합니다.
     */
    public function test_geoip_timezone_applied_when_enabled(): void
    {
        $dbPath = storage_path('app/geoip/GeoLite2-City.mmdb');

        if (! file_exists($dbPath)) {
            $this->markTestSkipped('GeoLite2-City.mmdb 파일이 없습니다.');
        }

        Auth::logout();
        config(['geoip.enabled' => true]);
        config(['geoip.database_path' => $dbPath]);
        config(['geoip.cache.enabled' => false]);

        // GeoIpService 목업: 특정 타임존 반환
        $mockGeoIpService = $this->createMock(GeoIpService::class);
        $mockGeoIpService->method('getTimezoneByIp')
            ->willReturn('America/New_York');

        $this->app->instance(GeoIpService::class, $mockGeoIpService);

        $request = Request::create('/api/test', 'GET');
        // X-Timezone 헤더 없음

        $middleware = $this->createMiddleware();
        $middleware->handle($request, $this->getNextClosure());

        $this->assertEquals('America/New_York', SetTimezone::getTimezone());
    }

    /**
     * X-Timezone 헤더가 GeoIP보다 우선하는지 테스트합니다.
     */
    public function test_browser_timezone_has_priority_over_geoip(): void
    {
        Auth::logout();

        // GeoIpService 목업: 다른 타임존 반환
        $mockGeoIpService = $this->createMock(GeoIpService::class);
        $mockGeoIpService->method('getTimezoneByIp')
            ->willReturn('America/New_York');

        $this->app->instance(GeoIpService::class, $mockGeoIpService);

        $request = Request::create('/api/test', 'GET');
        $request->headers->set('X-Timezone', 'Europe/London');

        $middleware = $this->createMiddleware();
        $middleware->handle($request, $this->getNextClosure());

        // X-Timezone 헤더가 GeoIP보다 우선해야 함
        $this->assertEquals('Europe/London', SetTimezone::getTimezone());
    }

    /**
     * GeoIP가 null을 반환하면 기본값이 적용되는지 테스트합니다.
     */
    public function test_default_timezone_when_geoip_returns_null(): void
    {
        Auth::logout();

        // GeoIpService 목업: null 반환 (조회 실패)
        $mockGeoIpService = $this->createMock(GeoIpService::class);
        $mockGeoIpService->method('getTimezoneByIp')
            ->willReturn(null);

        $this->app->instance(GeoIpService::class, $mockGeoIpService);

        $request = Request::create('/api/test', 'GET');
        // X-Timezone 헤더 없음

        $middleware = $this->createMiddleware();
        $middleware->handle($request, $this->getNextClosure());

        // 기본값이 적용되어야 함
        $this->assertEquals('Asia/Seoul', SetTimezone::getTimezone());
    }

    /**
     * 실제 GeoIP DB를 사용한 통합 테스트 (한국 IP)
     */
    public function test_real_geoip_with_korean_ip(): void
    {
        $dbPath = storage_path('app/geoip/GeoLite2-City.mmdb');

        if (! file_exists($dbPath)) {
            $this->markTestSkipped('GeoLite2-City.mmdb 파일이 없습니다.');
        }

        Auth::logout();
        config(['geoip.enabled' => true]);
        config(['geoip.database_path' => $dbPath]);
        config(['geoip.cache.enabled' => false]);

        // 새 GeoIpService 인스턴스 생성
        $this->app->forgetInstance(GeoIpService::class);
        $this->app->singleton(GeoIpService::class);

        $request = Request::create('/api/test', 'GET', [], [], [], [
            'REMOTE_ADDR' => '168.126.63.1', // KT DNS (한국)
        ]);

        $middleware = $this->createMiddleware();
        $middleware->handle($request, $this->getNextClosure());

        $this->assertEquals('Asia/Seoul', SetTimezone::getTimezone());
    }
}
