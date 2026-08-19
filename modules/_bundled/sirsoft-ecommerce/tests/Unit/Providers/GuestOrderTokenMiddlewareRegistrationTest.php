<?php

declare(strict_types=1);

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Providers;

use App\Contracts\Extension\ExtensionMiddlewareRegistryInterface;
use App\Extension\ExtensionMiddlewareRegistry;
use Illuminate\Support\Facades\Route;
use Modules\Sirsoft\Ecommerce\Http\Middleware\VerifyGuestOrderToken;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * VerifyGuestOrderToken 이 비회원 주문 액션 라우트 전체에 부착되는지 검증.
 *
 * 회귀 배경: targets 에 라우트명을 개별 나열하고 있었는데 현금영수증 라우트 2건
 * (`guest.orders.cash-receipt.show` / `.issue`) 이 추가될 때 targets 가 갱신되지 않아
 * 미들웨어가 부착되지 않았다. 그 결과 `$request->attributes->set('guest_order', ...)` 가
 * 실행되지 않아 FormRequest 가 404 를 던지고 비회원 현금영수증 기능이 전면 불능이었다.
 *
 * 이 테스트의 핵심은 `test_all_guest_order_action_routes_are_token_protected` 다 —
 * 라우트 테이블에서 대상을 파생시키므로, 앞으로 `guest/orders/` 밑에 라우트를 추가하고
 * targets 를 갱신하지 않으면 손대지 않아도 자동으로 red 가 된다. 개별 케이스 나열은
 * 이번 결함을 재현하지 못한다(나열한 것만 검사하므로 누락된 라우트를 영원히 못 본다).
 */
class GuestOrderTokenMiddlewareRegistrationTest extends ModuleTestCase
{
    /**
     * 비회원 주문 라우트명 공통 접두사 (모듈 로더가 붙이는 `api.modules.{id}.` 포함).
     */
    private const GUEST_ORDER_PREFIX = 'api.modules.sirsoft-ecommerce.guest.orders.';

    /**
     * 토큰 미들웨어에서 의도적으로 제외되는 라우트.
     *
     * `guest.orders.verify` 는 조회 토큰을 **발급**하는 엔드포인트다. 즉 호출 시점에
     * 아직 토큰이 없으므로 토큰 검증 미들웨어를 붙이면 최초 인증 요청이 404 로 막혀
     * 비회원 주문 조회가 통째로 죽는다. 요청 빈도 제한은 `throttle:20,1` 과
     * `GuestOrderAuthService` 의 실패 잠금이 담당한다.
     *
     * @var list<string>
     */
    private const EXEMPT = [
        self::GUEST_ORDER_PREFIX.'verify',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // 인덱스가 태그 캐시라 케이스 간 오염을 막으려면 앞뒤로 비운다.
        ExtensionMiddlewareRegistry::flush();
    }

    protected function tearDown(): void
    {
        ExtensionMiddlewareRegistry::flush();

        parent::tearDown();
    }

    /**
     * 현금영수증 조회·발급 라우트에 토큰 미들웨어가 부착되는지 (직접 회귀 테스트).
     */
    public function test_gate_resolves_token_middleware_for_cash_receipt_routes(): void
    {
        $registry = $this->app->make(ExtensionMiddlewareRegistryInterface::class);

        foreach (['cash-receipt.show', 'cash-receipt.issue'] as $suffix) {
            $routeName = self::GUEST_ORDER_PREFIX.$suffix;

            $matched = $registry->resolveForRoute(
                $routeName,
                'api/modules/sirsoft-ecommerce/guest/orders/{orderNumber}/cash-receipt',
                'api',
                'after_core',
            );

            $this->assertContains(
                VerifyGuestOrderToken::class,
                $matched,
                "{$routeName} 에 VerifyGuestOrderToken 이 부착되어야 합니다. 미부착이면 guest_order 속성이 "
                .'설정되지 않아 FormRequest 가 404 를 던지고 현금영수증 기능이 전면 불능이 됩니다.',
            );
        }
    }

    /**
     * 비회원 주문 라우트 전수가 `보호 ∪ 명시적 면제` 로 완전 분류되는지.
     *
     * 라우트 테이블에서 모집단을 파생시키므로 새 라우트가 늘면 자동으로 검사 대상이 된다.
     */
    public function test_all_guest_order_action_routes_are_token_protected(): void
    {
        $registry = $this->app->make(ExtensionMiddlewareRegistryInterface::class);

        $guestRoutes = [];
        foreach (Route::getRoutes() as $route) {
            $name = $route->getName();
            if (is_string($name) && str_starts_with($name, self::GUEST_ORDER_PREFIX)) {
                $guestRoutes[$name] = $route->uri();
            }
        }

        $this->assertNotEmpty(
            $guestRoutes,
            '비회원 주문 라우트가 하나도 수집되지 않았습니다. 접두사('.self::GUEST_ORDER_PREFIX
            .')가 바뀌었다면 이 테스트는 아무것도 검사하지 못하는 상태이므로 접두사를 함께 고쳐야 합니다.',
        );

        $unprotected = [];
        foreach ($guestRoutes as $name => $uri) {
            if (in_array($name, self::EXEMPT, true)) {
                continue;
            }

            $matched = $registry->resolveForRoute($name, $uri, 'api', 'after_core');

            if (! in_array(VerifyGuestOrderToken::class, $matched, true)) {
                $unprotected[] = $name;
            }
        }

        $this->assertSame(
            [],
            $unprotected,
            '토큰 미검증 상태로 열려 있는 비회원 주문 라우트가 있습니다: '.implode(', ', $unprotected)
            .'. Module::getMiddleware() 의 VerifyGuestOrderToken targets 를 갱신하거나, '
            .'보호하면 안 되는 라우트라면 이 테스트의 EXEMPT 에 사유와 함께 등재하십시오.',
        );
    }

    /**
     * 토큰 발급 라우트(`verify`)는 미부착이어야 한다.
     *
     * targets 를 `guest.orders.*` 로 뭉뚱그리면 이 라우트까지 삼켜 비회원 조회가 통째로 막힌다.
     */
    public function test_verify_route_is_not_token_protected(): void
    {
        $registry = $this->app->make(ExtensionMiddlewareRegistryInterface::class);

        $matched = $registry->resolveForRoute(
            self::GUEST_ORDER_PREFIX.'verify',
            'api/modules/sirsoft-ecommerce/guest/orders/verify',
            'api',
            'after_core',
        );

        $this->assertNotContains(
            VerifyGuestOrderToken::class,
            $matched,
            'guest.orders.verify 는 토큰을 발급하는 엔드포인트라 토큰 검증 미들웨어가 붙으면 '
            .'최초 인증 요청이 404 로 막힙니다. targets 를 guest.orders.* 로 넓히지 마십시오.',
        );
    }

    /**
     * 선언 계약: api 그룹 전용.
     */
    public function test_module_declares_token_middleware_for_api_group_only(): void
    {
        $declaration = collect((new Module)->getMiddleware())
            ->firstWhere('class', VerifyGuestOrderToken::class);

        $this->assertNotNull(
            $declaration,
            'Module::getMiddleware() 가 VerifyGuestOrderToken 을 선언해야 합니다.',
        );
        $this->assertSame(['api'], $declaration['groups']);
    }
}
