<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 알림 데이터 추출 테스트 (S7 / §4-8)
 *
 * 검증 축:
 * - 회원 신청은 `trigger_user_id`, 비회원 신청은 코어 표준 키 `guest_recipient` 로 수신자가 잡힌다
 * - 비회원 링크는 상세가 아니라 비회원 주문 조회 화면이다(메일에는 조회 토큰이 없다)
 * - 금액은 주문 통화로 포맷되고, 결제 요청 알림은 미수납액을 싣는다
 * - 클레임이 아닌 알림 유형은 앞선 필터 결과를 그대로 돌려준다(주문 축을 덮지 않는다)
 */
class ClaimNotificationDataListenerTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private const GUEST_PASSWORD = 'guest-pass-1234';

    private const GUEST_PHONE = '010-7777-8888';

    protected ClaimNotificationDataListener $listener;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->listener = app(ClaimNotificationDataListener::class);
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    private function emptyResult(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
    }

    private function requestedReturn(?Order $order = null, ClaimChargeMethodEnum $method = ClaimChargeMethodEnum::DEDUCT_FROM_REFUND): OrderReturn
    {
        $order ??= $this->deliveredClaimOrder();

        return app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => $method,
            'requested_by' => $order->user_id,
        ]);
    }

    private function requestedExchange(): OrderExchange
    {
        $order = $this->deliveredClaimOrder();

        return app(OrderExchangeService::class)->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'size_mismatch',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $order->user_id,
        ]);
    }

    public function test_a_member_claim_routes_to_the_requester_and_links_to_the_mypage_detail(): void
    {
        $return = $this->requestedReturn();

        $result = $this->listener->extractData($this->emptyResult(), 'return_requested', [$return]);

        $this->assertSame($return->requested_by, $result['context']['trigger_user_id']);
        $this->assertArrayNotHasKey('guest_recipient', $result['context']);
        $this->assertStringEndsWith('/mypage/claims/'.$return->return_number, $result['data']['claim_url']);
        $this->assertSame($return->return_number, $result['data']['claim_number']);
        $this->assertSame($return->order->order_number, $result['data']['order_number']);
        $this->assertNotSame('', $result['data']['status_label']);
        $this->assertNotSame('', $result['data']['reason_label']);
    }

    public function test_a_guest_claim_uses_the_core_guest_recipient_key_and_the_lookup_screen(): void
    {
        $order = $this->deliveredClaimOrder();
        $order->forceFill(['user_id' => null, 'guest_lookup_password_hash' => Hash::make(self::GUEST_PASSWORD)])->save();
        OrderAddress::where('order_id', $order->id)->update(['orderer_phone' => self::GUEST_PHONE]);

        $return = $this->requestedReturn($order->fresh(['options']));
        $return->forceFill(['requested_by' => null])->save();

        $result = $this->listener->extractData($this->emptyResult(), 'return_requested', [$return->fresh()]);

        $this->assertNull($result['context']['trigger_user_id']);
        $this->assertTrue($result['context']['is_guest_claim']);
        $this->assertSame(
            $order->fresh()->getOrdererEmail(),
            $result['context']['guest_recipient']['email'],
            '비회원 수신자는 코어 표준 키로 넘겨야 회원과 같은 발송 경로를 탄다.'
        );
        $this->assertStringEndsWith(
            '/guest/orders',
            $result['data']['claim_url'],
            '메일에서 눌러 들어온 브라우저에는 조회 토큰이 없어 상세 주소는 열리지 않는다.'
        );
    }

    public function test_payment_request_data_carries_the_charge_and_outstanding_amounts(): void
    {
        $exchange = $this->requestedExchange();
        app(ClaimPaymentService::class)->initiateForRequest($exchange, PaymentMethodEnum::CARD->value);

        $result = $this->listener->extractData($this->emptyResult(), 'exchange_payment_requested', [$exchange->fresh()]);

        $this->assertStringContainsString('6,000', $result['data']['charge_amount']);
        $this->assertStringContainsString('6,000', $result['data']['outstanding_amount']);
    }

    public function test_admin_withdrawal_data_links_to_the_admin_screen_and_defers_the_recipient_name(): void
    {
        $return = $this->requestedReturn();

        $result = $this->listener->extractData($this->emptyResult(), 'claim_withdrawn', [$return]);

        $this->assertSame('{recipient_name}', $result['data']['name'], '관리자 알림 이름은 수신자마다 코어가 치환한다.');
        $this->assertStringEndsWith('/admin/ecommerce/claims/'.$return->return_number, $result['data']['claim_url']);
        $this->assertNotSame('', $result['data']['requester_name']);
        $this->assertArrayNotHasKey('guest_recipient', $result['context']);
    }

    public function test_non_claim_types_pass_through_untouched(): void
    {
        $default = ['notifiable' => null, 'notifiables' => null, 'data' => ['name' => '주문 축'], 'context' => ['trigger_user_id' => 7]];

        $result = $this->listener->extractData($default, 'order_confirmed', []);

        $this->assertSame($default, $result, '주문 축 리스너가 만든 결과를 덮으면 주문 알림이 조용히 비게 됩니다.');
    }

    public function test_a_claim_type_without_a_claim_argument_passes_through(): void
    {
        $default = $this->emptyResult();

        $result = $this->listener->extractData($default, 'return_requested', ['not-a-claim']);

        $this->assertSame($default, $result);
    }
}
