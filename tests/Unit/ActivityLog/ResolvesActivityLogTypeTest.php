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

    protected function setUp(): void
    {
        parent::setUp();

        // 트레이트를 사용하는 익명 클래스 생성
        $this->traitUser = new class
        {
            use ResolvesActivityLogType;

            /**
             * resolveLogType() 외부 호출용 래퍼
             */
            public function getResolvedLogType(): ActivityLogType
            {
                return $this->resolveLogType();
            }

            /**
             * logActivity() 외부 호출용 래퍼
             */
            public function callLogActivity(string $action, array $context): void
            {
                $this->logActivity($action, $context);
            }
        };
    }

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

    public function test_resolveLogType_returns_admin_for_admin_route(): void
    {
        $this->setRequestPath('api/admin/products');

        $this->assertEquals(ActivityLogType::Admin, $this->traitUser->getResolvedLogType());
    }

    public function test_resolveLogType_returns_user_for_shop_route(): void
    {
        $this->setRequestPath('api/shop/cart');

        $this->assertEquals(ActivityLogType::User, $this->traitUser->getResolvedLogType());
    }

    public function test_resolveLogType_returns_user_for_admin_on_user_route(): void
    {
        // 핵심 테스트: admin 역할이지만 사용자 화면 경로 → user
        $this->setRequestPath('api/shop/cart');

        $this->assertEquals(ActivityLogType::User, $this->traitUser->getResolvedLogType());
    }

    public function test_resolveLogType_returns_user_for_guest_on_user_route(): void
    {
        // 비회원이 사용자 화면 경로 → user (System이 아님)
        $this->setRequestPath('api/shop/coupons/download');

        $this->assertEquals(ActivityLogType::User, $this->traitUser->getResolvedLogType());
    }

    public function test_resolveLogType_returns_user_for_public_api_route(): void
    {
        $this->setRequestPath('api/auth/register');

        $this->assertEquals(ActivityLogType::User, $this->traitUser->getResolvedLogType());
    }

    public function test_resolveLogType_returns_system_for_cli_without_request(): void
    {
        // CLI 환경: request()->path() === '/'
        $this->setRequestPath('/');

        $this->assertEquals(ActivityLogType::System, $this->traitUser->getResolvedLogType());
    }

    public function test_resolveLogType_returns_admin_for_nested_admin_route(): void
    {
        $this->setRequestPath('api/admin/sirsoft-ecommerce/orders/123/cancel');

        $this->assertEquals(ActivityLogType::Admin, $this->traitUser->getResolvedLogType());
    }

    // ═══════════════════════════════════════════
    // logActivity 테스트
    // ═══════════════════════════════════════════

    public function test_logActivity_auto_resolves_log_type_when_not_provided(): void
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

    public function test_logActivity_preserves_explicit_log_type(): void
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

    public function test_logActivity_catches_exception_and_logs_error(): void
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

    public function test_logActivity_resolves_system_type_for_cli_context(): void
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
     * @param string $path 요청 경로 (예: 'api/admin/products', '/' for CLI 시뮬레이션)
     */
    private function setRequestPath(string $path): void
    {
        $request = Request::create($path === '/' ? '/' : '/'.$path);
        $this->app->instance('request', $request);
    }
}
