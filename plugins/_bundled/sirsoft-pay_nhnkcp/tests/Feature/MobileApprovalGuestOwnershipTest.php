<?php

namespace Plugins\Sirsoft\PayNhnkcp\Tests\Feature;

use App\Models\User;
use App\Services\PluginSettingsService;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderFactory;
use Modules\Sirsoft\Ecommerce\Database\Factories\OrderPaymentFactory;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\GuestOrderAuthService;
use Plugins\Sirsoft\PayNhnkcp\Services\KcpSoapService;
use Plugins\Sirsoft\PayNhnkcp\Tests\PluginTestCase;

/**
 * 비회원 주문의 모바일 결제 승인키 — 주문 조회 토큰으로 주인을 확인한다 (#883)
 *
 * 승인키 라우트가 `auth:sanctum` 이고 컨트롤러가 `user_id === 로그인 회원` 만 허용해, 비회원 주문은 모바일에서 KCP 로
 * 결제할 수 없었다. 이제 라우트는 `optional.sanctum` 이고, 비회원 주문은 주문 생성 때 받은 조회 토큰
 * (`X-Guest-Order-Token`)이 이 주문의 것일 때만 승인키를 준다(결제창 닫힘 보고와 같은 판정).
 */
class MobileApprovalGuestOwnershipTest extends PluginTestCase
{
    private const APPROVAL_KEY_ENDPOINT = '/api/plugins/sirsoft-pay_nhnkcp/mobile/approval-key';

    /**
     * 결제 전 주문과 카드 결제 행을 만듭니다.
     *
     * @param  int|null  $userId  주문 회원 ID (null 이면 비회원 주문)
     * @return Order 만든 주문
     */
    private function createOrder(?int $userId): Order
    {
        $order = OrderFactory::new()->create([
            'user_id' => $userId,
            'order_number' => 'ORD-MOBG-'.random_int(10000, 99999),
            'order_status' => OrderStatusEnum::PENDING_ORDER,
            'subtotal_amount' => 10000,
            'total_amount' => 10000,
            'total_due_amount' => 10000,
            'total_paid_amount' => 0,
            'total_tax_amount' => 10000,
            'total_vat_amount' => 909,
            'total_tax_free_amount' => 0,
            'total_zero_rated_amount' => 0,
            'currency' => 'KRW',
            'currency_snapshot' => [
                'base_currency' => 'KRW',
                'order_currency' => 'KRW',
                'base_unit' => 1,
                'exchange_rates' => [
                    'KRW' => ['rate' => 1, 'rounding_unit' => '1', 'rounding_method' => 'round', 'decimal_places' => 0, 'base_unit' => 1],
                ],
            ],
        ]);

        OrderPaymentFactory::new()->create([
            'order_id' => $order->id,
            'payment_status' => PaymentStatusEnum::READY,
            'payment_method' => PaymentMethodEnum::CARD,
            'pg_provider' => 'nhnkcp',
        ]);

        return $order;
    }

    /**
     * KCP SOAP 와 플러그인 설정을 대역으로 바꿉니다.
     *
     * @param  bool  $expectCall  SOAP 승인키 요청이 일어나야 하는지
     * @return void 반환값 없음
     */
    private function fakeKcp(bool $expectCall): void
    {
        $soap = $this->createMock(KcpSoapService::class);
        $soap->expects($expectCall ? $this->once() : $this->never())
            ->method('getApprovalKey')
            ->willReturn(['approval_key' => 'GUEST_APPROVAL', 'pay_url' => 'https://testpay.kcp.co.kr/php/mobile/mc_pay_form.php']);
        $soap->method('getSiteCd')->willReturn('T0000');
        $soap->method('getEscrowSiteCd')->willReturn('T0000');
        $this->app->instance(KcpSoapService::class, $soap);

        $settings = $this->createMock(PluginSettingsService::class);
        $settings->method('get')->willReturn(['is_test_mode' => true, 'test_site_cd' => 'T0000', 'test_site_key' => 'TEST_KEY']);
        $this->app->instance(PluginSettingsService::class, $settings);
    }

    /**
     * 승인키 요청 본문을 만듭니다.
     *
     * @param  Order  $order  대상 주문
     * @return array<string, mixed> 요청 본문
     */
    private function payload(Order $order): array
    {
        return [
            'order_number' => $order->order_number,
            'amount' => 10000,
            'good_name' => '테스트 상품',
            'pay_method' => 'card',
            'currency' => 'KRW',
        ];
    }

    /**
     * 비회원 주문 + 그 주문의 조회 토큰 → 승인키를 준다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=guest_token_valid
     *
     * @effects guest_order_mobile_approval_with_order_token
     */
    public function test_guest_order_with_matching_token_gets_approval_key(): void
    {
        $order = $this->createOrder(null);
        $this->fakeKcp(true);
        $token = app(GuestOrderAuthService::class)->issueForOrder($order)['token'];

        $this->withHeader('X-Guest-Order-Token', $token)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->payload($order))
            ->assertOk()
            ->assertJsonPath('data.fields.approval_key', 'GUEST_APPROVAL')
            ->assertJsonPath('data.fields.ordr_idxx', $order->order_number);
    }

    /**
     * 비회원 주문인데 토큰이 없거나, 다른 주문의 토큰이면 401 이고 SOAP 를 부르지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=guest_token_mismatch
     *
     * @effects guest_order_mobile_approval_with_order_token
     */
    public function test_guest_order_without_or_with_foreign_token_is_rejected_401(): void
    {
        $order = $this->createOrder(null);
        $other = $this->createOrder(null);
        $this->fakeKcp(false);
        $foreignToken = app(GuestOrderAuthService::class)->issueForOrder($other)['token'];

        $this->postJson(self::APPROVAL_KEY_ENDPOINT, $this->payload($order))
            ->assertUnauthorized()
            ->assertJsonPath('success', false);

        $this->withHeader('X-Guest-Order-Token', $foreignToken)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->payload($order))
            ->assertUnauthorized();
    }

    /**
     * 로그인 회원이 남의 회원 주문으로 요청하면 403 — 비회원 토큰을 붙여도 회원 주문은 회원 판정만 한다.
     *
     * @return void 반환값 없음
     *
     * @scenario request=other_owner
     *
     * @effects approval_requires_the_order_owner
     */
    public function test_member_cannot_get_approval_key_for_another_members_order(): void
    {
        $owner = User::factory()->create();
        $order = $this->createOrder($owner->id);
        $this->fakeKcp(false);
        $guestToken = app(GuestOrderAuthService::class)->issueForOrder($order)['token'];

        $this->actingAs(User::factory()->create())
            ->withHeader('X-Guest-Order-Token', $guestToken)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->payload($order))
            ->assertForbidden();
    }

    /**
     * 로그인 회원 본인의 주문은 종전대로 승인키를 받는다(회귀).
     *
     * @return void 반환값 없음
     *
     * @scenario request=valid
     *
     * @effects approval_key_returns_pay_url_and_fields
     */
    public function test_member_own_order_still_gets_approval_key(): void
    {
        $owner = User::factory()->create();
        $order = $this->createOrder($owner->id);
        $this->fakeKcp(true);

        $this->actingAs($owner)
            ->postJson(self::APPROVAL_KEY_ENDPOINT, $this->payload($order))
            ->assertOk();
    }
}
