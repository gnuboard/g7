<?php

namespace Tests\Unit\ActivityLog;

use App\ActivityLog\Traits\ResolvesActivityLogType;
use App\Enums\ActivityLogType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

/**
 * ResolvesActivityLogType 트레이트 단위 테스트
 *
 * resolveLogType()과 logActivity()의 동작을 검증합니다.
 * 요청 경로 기반으로 log_type을 결정하는 로직을 테스트합니다.
 */
class ResolvesActivityLogTypeTest extends TestCase
{
    private object $traitUser;

    /**
     * 트레이트를 쓰는 익명 클래스를 준비한다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 트레이트를 사용하는 익명 클래스 생성
        $this->traitUser = new class
        {
            use ResolvesActivityLogType;

            /**
             * resolveLogType() 외부 호출용 래퍼
             *
             * @return ActivityLogType 결정된 로그 유형
             */
            public function getResolvedLogType(): ActivityLogType
            {
                return $this->resolveLogType();
            }

            /**
             * logActivity() 외부 호출용 래퍼
             *
             * @param  string  $action  액션명
             * @param  array  $context  로그 문맥
             * @return void 반환값 없음
             */
            public function callLogActivity(string $action, array $context): void
            {
                $this->logActivity($action, $context);
            }
        };
    }

    /**
     * Mockery 기대치를 단언 수에 더하고 닫는다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        if ($container = Mockery::getContainer()) {
            $this->addToAssertionCount($container->mockery_getExpectationCount());
        }
        Mockery::close();
        parent::tearDown();
    }

    // ═══════════════════════════════════════════
    // resolveLogType 테스트 — 요청 경로 기반 판별
    // ═══════════════════════════════════════════

    /**
     * 코어 관리자 API 경로는 관리자 유형이다.
     *
     * @scenario path_area=core_admin_api
     *
     * @effects core_admin_api_logged_as_admin
     *
     * @return void 반환값 없음
     */
    public function test_resolve_log_type_returns_admin_for_admin_route(): void
    {
        $this->setRequestPath('api/admin/products');

        $this->assertEquals(ActivityLogType::Admin, $this->traitUser->getResolvedLogType());
    }

    /**
     * 사용자 화면 API 경로는 사용자 유형이다.
     *
     * @scenario path_area=user_api
     *
     * @effects user_api_logged_as_user
     *
     * @return void 반환값 없음
     */
    public function test_resolve_log_type_returns_user_for_shop_route(): void
    {
        $this->setRequestPath('api/shop/cart');

        $this->assertEquals(ActivityLogType::User, $this->traitUser->getResolvedLogType());
    }

    /**
     * 관리자 역할이라도 사용자 화면 경로면 사용자 유형이다.
     *
     * @scenario path_area=user_api
     *
     * @effects user_api_logged_as_user
     *
     * @return void 반환값 없음
     */
    public function test_resolve_log_type_returns_user_for_admin_on_user_route(): void
    {
        // 핵심 테스트: admin 역할이지만 사용자 화면 경로 → user
        $this->setRequestPath('api/shop/cart');

        $this->assertEquals(ActivityLogType::User, $this->traitUser->getResolvedLogType());
    }

    /**
     * 비회원의 사용자 화면 경로는 시스템이 아니라 사용자 유형이다.
     *
     * @scenario path_area=user_api
     *
     * @effects user_api_logged_as_user
     *
     * @return void 반환값 없음
     */
    public function test_resolve_log_type_returns_user_for_guest_on_user_route(): void
    {
        // 비회원이 사용자 화면 경로 → user (System이 아님)
        $this->setRequestPath('api/shop/coupons/download');

        $this->assertEquals(ActivityLogType::User, $this->traitUser->getResolvedLogType());
    }

    /**
     * 공개 인증 API(회원가입)는 사용자 유형이다.
     *
     * @scenario path_area=user_api
     *
     * @effects user_api_logged_as_user
     *
     * @return void 반환값 없음
     */
    public function test_resolve_log_type_returns_user_for_public_api_route(): void
    {
        $this->setRequestPath('api/auth/register');

        $this->assertEquals(ActivityLogType::User, $this->traitUser->getResolvedLogType());
    }

    /**
     * 요청이 없는 CLI 문맥은 시스템 유형이다.
     *
     * @scenario path_area=cli
     *
     * @effects cli_logged_as_system
     *
     * @return void 반환값 없음
     */
    public function test_resolve_log_type_returns_system_for_cli_without_request(): void
    {
        // CLI 환경: request()->path() === '/'
        $this->setRequestPath('/');

        $this->assertEquals(ActivityLogType::System, $this->traitUser->getResolvedLogType());
    }

    /**
     * 코어 관리자 API 의 깊은 경로도 관리자 유형이다.
     *
     * @scenario path_area=core_admin_api
     *
     * @effects core_admin_api_logged_as_admin
     *
     * @return void 반환값 없음
     */
    public function test_resolve_log_type_returns_admin_for_nested_admin_route(): void
    {
        $this->setRequestPath('api/admin/sirsoft-ecommerce/orders/123/cancel');

        $this->assertEquals(ActivityLogType::Admin, $this->traitUser->getResolvedLogType());
    }

    /**
     * 확장 관리자 API(`api/{modules|plugins}/{id}/admin/*`)는 관리자 유형이다 — IP 제한의 관리자 영역과 같은 기준
     * (7.1.0 실측 O-B2: 인증 앱 강제 해제가 「사용자」로 남았다).
     *
     * @scenario path_area=extension_admin_api
     *
     * @effects extension_admin_api_logged_as_admin
     *
     * @return void 반환값 없음
     */
    public function test_resolve_log_type_returns_admin_for_extension_admin_api(): void
    {
        foreach ([
            'api/plugins/sirsoft-totp/admin/credentials/0b0c6d1e-uuid',
            'api/modules/sirsoft-board/admin/boards',
            'api/modules/sirsoft-ecommerce/admin',
        ] as $path) {
            $this->setRequestPath($path);

            $this->assertEquals(ActivityLogType::Admin, $this->traitUser->getResolvedLogType(), $path);
        }
    }

    /**
     * 관리자 로그인 API(`api/auth/admin/*`)도 관리자 영역이다 — IP 제한 정의와 같은 기준.
     *
     * @scenario path_area=admin_auth_api
     *
     * @effects admin_auth_api_logged_as_admin
     *
     * @return void 반환값 없음
     */
    public function test_resolve_log_type_returns_admin_for_admin_auth_api(): void
    {
        $this->setRequestPath('api/auth/admin/login');

        $this->assertEquals(ActivityLogType::Admin, $this->traitUser->getResolvedLogType());
    }

    /**
     * 사용자 경로 깊숙한 `admin` 세그먼트나 확장 사용자 API 는 관리자 영역이 아니다.
     *
     * @scenario path_area=extension_user_api
     *
     * @effects extension_user_api_stays_user
     *
     * @return void 반환값 없음
     */
    public function test_resolve_log_type_keeps_user_for_extension_user_api(): void
    {
        foreach ([
            'api/modules/sirsoft-board/boards/admin/posts',
            'api/plugins/sirsoft-totp/enrollment',
            'api/modules/sirsoft-board/boards/notice/posts',
        ] as $path) {
            $this->setRequestPath($path);

            $this->assertEquals(ActivityLogType::User, $this->traitUser->getResolvedLogType(), $path);
        }
    }

    // ═══════════════════════════════════════════
    // logActivity 테스트
    // ═══════════════════════════════════════════

    /**
     * log_type 을 주지 않으면 경로로 자동 결정한다.
     *
     * @effects log_activity_resolves_or_preserves_log_type
     *
     * @return void 반환값 없음
     */
    public function test_log_activity_auto_resolves_log_type_when_not_provided(): void
    {
        $this->setRequestPath('api/admin/products');

        $logChannel = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('activity')->andReturn($logChannel);

        $logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $action === 'test.action'
                    && $context['log_type'] === ActivityLogType::Admin
                    && $context['description_key'] === 'test_key';
            });

        $this->traitUser->callLogActivity('test.action', [
            'description_key' => 'test_key',
        ]);
    }

    /**
     * 명시한 log_type 은 자동 결정으로 덮지 않는다.
     *
     * @effects log_activity_resolves_or_preserves_log_type
     *
     * @return void 반환값 없음
     */
    public function test_log_activity_preserves_explicit_log_type(): void
    {
        // 경로는 admin이지만 명시적으로 User 타입이 전달된 경우 유지
        $this->setRequestPath('api/admin/products');

        $logChannel = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('activity')->andReturn($logChannel);

        $logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                // 명시적으로 전달한 User 타입이 유지되어야 함
                return $context['log_type'] === ActivityLogType::User;
            });

        $this->traitUser->callLogActivity('test.action', [
            'log_type' => ActivityLogType::User,
            'description_key' => 'test_key',
        ]);
    }

    /**
     * 로그 채널 예외는 삼키고 오류 로그만 남긴다.
     *
     * @effects activity_log_failure_does_not_kill_triggering_request
     *
     * @return void 반환값 없음
     */
    public function test_log_activity_catches_exception_and_logs_error(): void
    {
        $this->setRequestPath('/');

        $logChannel = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('activity')->andReturn($logChannel);

        $logChannel->shouldReceive('info')
            ->once()
            ->andThrow(new \RuntimeException('DB connection failed'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Failed to record activity log'
                    && $context['action'] === 'test.action'
                    && $context['error'] === 'DB connection failed';
            });

        $this->traitUser->callLogActivity('test.action', [
            'description_key' => 'test_key',
        ]);
    }

    /**
     * 활동 로그 채널 해석이 실패해(Error) 도 그것을 유발한 요청까지 죽이지 않는지 검증
     *
     * Log::channel('activity') 가 null 을 반환하면 "->info() on null" 은 \Error 이며
     * \Exception 이 아니다. 가드가 \Exception 만 잡으면 결제 콜백 같은 호출자가
     * 활동 로그 때문에 500 으로 죽는다.
     *
     * @effects activity_log_failure_does_not_kill_triggering_request
     *
     * @return void 반환값 없음
     */
    public function test_log_activity_catches_error_not_only_exception(): void
    {
        $this->setRequestPath('/');

        $logChannel = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('activity')->andReturn($logChannel);

        $logChannel->shouldReceive('info')
            ->once()
            ->andThrow(new \Error('Call to a member function info() on null'));

        Log::shouldReceive('error')
            ->once()
            ->withArgs(function (string $message, array $context) {
                return $message === 'Failed to record activity log'
                    && $context['action'] === 'test.action';
            });

        // 예외가 밖으로 새어나오면 이 테스트는 실패한다 (호출자가 죽는다는 뜻).
        $this->traitUser->callLogActivity('test.action', [
            'description_key' => 'test_key',
        ]);

        $this->assertTrue(true);
    }

    /**
     * CLI 문맥의 logActivity 는 시스템 유형으로 기록한다.
     *
     * @effects cli_logged_as_system
     *
     * @return void 반환값 없음
     */
    public function test_log_activity_resolves_system_type_for_cli_context(): void
    {
        // CLI 환경 (request()->path() === '/')
        $this->setRequestPath('/');

        $logChannel = Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')->with('activity')->andReturn($logChannel);

        $logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $action, array $context) {
                return $context['log_type'] === ActivityLogType::System;
            });

        $this->traitUser->callLogActivity('test.action', [
            'description_key' => 'test_key',
        ]);
    }

    // ═══════════════════════════════════════════
    // 헬퍼 메서드
    // ═══════════════════════════════════════════

    /**
     * 테스트용 Request 경로를 설정합니다.
     *
     * @param  string  $path  요청 경로 (예: 'api/admin/products', '/' for CLI 시뮬레이션)
     * @return void 반환값 없음
     */
    private function setRequestPath(string $path): void
    {
        $request = Request::create($path === '/' ? '/' : '/'.$path);
        $this->app->instance('request', $request);
    }
}
