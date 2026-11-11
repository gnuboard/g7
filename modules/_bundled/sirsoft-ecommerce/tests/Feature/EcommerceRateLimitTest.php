<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature;

use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 장바구니 쓰기·체크아웃·비회원 주문 변경의 요청 제한.
 *
 * 세 묶음에는 요청 제한이 없어 장바구니 행·임시 주문·환불 계산을 무제한으로 만들거나 돌릴 수 있었다.
 * 묶음마다 이름 있는 제한기를 두고, 로그인 회원은 회원 단위로·비회원은 IP 단위로 센다 — 같은 IP 의 회원끼리,
 * 회원과 비회원끼리 서로의 한도를 깎지 않는다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/ecommerce-rate-limit.yaml
 */
class EcommerceRateLimitTest extends ModuleTestCase
{
    private const BASE = '/api/modules/sirsoft-ecommerce';

    /**
     * 한도를 시험하기 좋은 작은 값으로 고정합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        config(['sirsoft-ecommerce.rate_limits' => [
            'cart_write' => 5,
            'checkout' => 4,
            'guest_order_write' => 3,
        ]]);
    }

    /**
     * 비회원으로 요청합니다.
     *
     * @param  string  $method  HTTP 메서드
     * @param  string  $path  모듈 API 기준 경로
     * @param  string  $ip  요청 IP
     * @return TestResponse 응답
     */
    private function guest(string $method, string $path, string $ip = '198.51.100.7'): TestResponse
    {
        // 앞 요청의 인증 상태가 남지 않게 매번 비운다.
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(['Accept' => 'application/json', 'Authorization' => ''])
            ->json($method, self::BASE.$path);
    }

    /**
     * 회원 토큰으로 요청합니다.
     *
     * @param  string  $token  회원 토큰
     * @param  string  $method  HTTP 메서드
     * @param  string  $path  모듈 API 기준 경로
     * @param  string  $ip  요청 IP
     * @return TestResponse 응답
     */
    private function member(string $token, string $method, string $path, string $ip = '198.51.100.7'): TestResponse
    {
        $this->app['auth']->forgetGuards();

        return $this->withServerVariables(['REMOTE_ADDR' => $ip])
            ->withHeaders(['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token])
            ->json($method, self::BASE.$path);
    }

    /**
     * 장바구니 쓰기는 한도까지 받고 그 다음은 429 다. 조회는 세지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=cart_write__guest
     *
     * @effects bundle_limit_returns_429_with_translated_message
     */
    public function test_cart_writes_are_limited_and_reads_are_not_counted(): void
    {
        for ($i = 1; $i <= 5; $i++) {
            $this->assertNotSame(429, $this->guest('POST', '/cart/key')->status());
            $this->assertNotSame(429, $this->guest('GET', '/cart/count')->status());
        }

        $blocked = $this->guest('POST', '/cart/key');

        $blocked->assertStatus(429);
        $this->assertNotNull($blocked->headers->get('Retry-After'));
        $this->assertNotSame('Too Many Attempts.', $blocked->json('message'));

        // 묶음 안의 다른 쓰기도 같은 카운터다.
        $this->guest('DELETE', '/cart/all')->assertStatus(429);
        // 조회는 계속 된다.
        $this->assertNotSame(429, $this->guest('GET', '/cart/count')->status());
    }

    /**
     * 체크아웃 계산·갱신·연장과 주문 생성은 한 카운터로 센다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=checkout__guest
     *
     * @effects bundle_limit_returns_429_with_translated_message
     */
    public function test_checkout_and_order_creation_share_one_limit(): void
    {
        $this->assertNotSame(429, $this->guest('POST', '/checkout')->status());
        $this->assertNotSame(429, $this->guest('PUT', '/checkout')->status());
        $this->assertNotSame(429, $this->guest('POST', '/checkout/extend')->status());
        $this->assertNotSame(429, $this->guest('POST', '/user/orders')->status());

        $this->guest('POST', '/user/orders')->assertStatus(429);
        $this->guest('POST', '/checkout')->assertStatus(429);

        // 임시 주문 조회는 세지 않는다.
        $this->assertNotSame(429, $this->guest('GET', '/checkout')->status());
        // 다른 묶음(장바구니 쓰기)은 영향받지 않는다.
        $this->assertNotSame(429, $this->guest('POST', '/cart/key')->status());
    }

    /**
     * 결제창을 닫을 때 남기는 결제 취소 기록도 체크아웃 카운터로 센다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=payment_cancel_record__guest
     *
     * @effects bundle_limit_returns_429_with_translated_message
     */
    public function test_payment_cancellation_record_shares_the_checkout_limit(): void
    {
        for ($i = 1; $i <= 4; $i++) {
            $this->assertNotSame(429, $this->guest('POST', '/orders/ORD-NONE/cancel-payment')->status());
        }

        $blocked = $this->guest('POST', '/orders/ORD-NONE/cancel-payment');

        $blocked->assertStatus(429);
        $this->assertNotNull($blocked->headers->get('Retry-After'));

        // 같은 카운터라 체크아웃도 함께 막힌다.
        $this->guest('POST', '/checkout')->assertStatus(429);
        // 다른 묶음(비회원 주문 변경)은 영향받지 않는다.
        $this->assertNotSame(429, $this->guest('POST', '/guest/orders/ORD-NONE/estimate-refund')->status());
    }

    /**
     * 비회원 주문 변경(취소·환불 예상·배송지·구매확정·현금영수증 발급)은 한 카운터로 센다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=guest_order_write__guest
     *
     * @effects bundle_limit_returns_429_with_translated_message
     */
    public function test_guest_order_changes_including_refund_estimate_are_limited(): void
    {
        $this->assertNotSame(429, $this->guest('POST', '/guest/orders/ORD-NONE/estimate-refund')->status());
        $this->assertNotSame(429, $this->guest('POST', '/guest/orders/ORD-NONE/cancel')->status());
        $this->assertNotSame(429, $this->guest('PUT', '/guest/orders/ORD-NONE/shipping-address')->status());

        $this->guest('POST', '/guest/orders/ORD-NONE/estimate-refund')->assertStatus(429);
        $this->guest('POST', '/guest/orders/ORD-NONE/options/1/confirm')->assertStatus(429);
        $this->guest('POST', '/guest/orders/ORD-NONE/cash-receipt')->assertStatus(429);
    }

    /**
     * 회원은 회원 단위로, 비회원은 IP 단위로 센다 — 같은 IP 여도 서로의 한도를 깎지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=cart_write__member
     *
     * @effects member_and_guest_counters_are_separate
     */
    public function test_members_are_counted_per_account_not_per_ip(): void
    {
        $alice = $this->createUser()->createToken('a')->plainTextToken;
        $bob = $this->createUser()->createToken('b')->plainTextToken;

        for ($i = 1; $i <= 5; $i++) {
            $this->assertNotSame(429, $this->member($alice, 'POST', '/cart/key')->status());
        }
        $this->member($alice, 'POST', '/cart/key')->assertStatus(429);

        // 같은 IP 의 다른 회원·비회원은 각자 한도를 쓴다.
        $this->assertNotSame(429, $this->member($bob, 'POST', '/cart/key')->status());
        $this->assertNotSame(429, $this->guest('POST', '/cart/key')->status());

        // 한도가 찬 회원은 IP 를 바꿔도 그대로 막힌다.
        $this->member($alice, 'POST', '/cart/key', '203.0.113.50')->assertStatus(429);
    }

    /**
     * 비회원 클레임 블록은 기존 제한기를 그대로 쓴다(새 묶음에 넣지 않는다).
     *
     * @return void 반환값 없음
     *
     * @scenario case=guest_claims_unchanged__guest
     *
     * @effects guest_claim_limiter_unchanged
     */
    public function test_guest_claim_routes_keep_their_own_limiter(): void
    {
        $claimRoutes = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($route) => str_contains($route->uri(), 'modules/sirsoft-ecommerce/guest/orders/{orderNumber}/')
                && in_array('throttle:sirsoft-ecommerce.guest-claims', $route->gatherMiddleware(), true));

        $this->assertGreaterThan(0, $claimRoutes->count());

        foreach ($claimRoutes as $route) {
            $this->assertNotContains('throttle:sirsoft-ecommerce.guest-order-write', $route->gatherMiddleware(), $route->uri());
        }

        // 환불 예상은 새 묶음에 들어 있다.
        $estimate = Route::getRoutes()->getByName('api.modules.sirsoft-ecommerce.guest.orders.estimate-refund');
        $this->assertNotNull($estimate);
        $this->assertContains('throttle:sirsoft-ecommerce.guest-order-write', $estimate->gatherMiddleware());
    }

    /**
     * 한도는 모듈 설정의 기본값(장바구니 쓰기 120·체크아웃 30·비회원 주문 변경 20)에서 온다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=defaults__guest
     *
     * @effects limits_come_from_module_config_defaults
     */
    public function test_default_limits_are_declared_in_module_config(): void
    {
        $defaults = require base_path('modules/_bundled/sirsoft-ecommerce/config/ecommerce.php');

        $this->assertSame(
            ['cart_write' => 120, 'checkout' => 30, 'guest_order_write' => 20],
            $defaults['rate_limits']
        );
    }

    /**
     * 재주문(지난 주문 상품을 장바구니에 일괄 담기)도 장바구니 쓰기와 같은 카운터로 센다.
     *
     * @return void 반환값 없음
     *
     * @scenario case=cart_write__member
     *
     * @effects bundle_limit_returns_429_with_translated_message
     */
    public function test_reorder_shares_the_cart_write_counter(): void
    {
        $token = $this->createUser()->createToken('reorder')->plainTextToken;

        for ($i = 1; $i <= 5; $i++) {
            $this->assertNotSame(429, $this->member($token, 'POST', '/user/orders/999999/reorder')->status());
        }

        $this->member($token, 'POST', '/user/orders/999999/reorder')->assertStatus(429);
        // 장바구니 쓰기와 한 카운터다.
        $this->member($token, 'POST', '/cart/key')->assertStatus(429);
    }
}
