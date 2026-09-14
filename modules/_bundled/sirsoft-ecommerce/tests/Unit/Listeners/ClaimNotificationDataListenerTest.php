<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Listeners\ClaimNotificationDataListener;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderAddress;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
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

    /**
     * @effects member_claim_routes_to_trigger_user_and_links_to_mypage_detail
     *
     * @return void
     */
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

    /**
     * @effects guest_claim_uses_core_guest_recipient_key_and_lookup_screen
     *
     * @return void
     */
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

    /**
     * @effects payment_request_data_carries_charge_and_outstanding_amounts
     *
     * @return void
     */
    public function test_payment_request_data_carries_the_charge_and_outstanding_amounts(): void
    {
        $exchange = $this->requestedExchange();
        app(ClaimPaymentService::class)->initiateForRequest($exchange, PaymentMethodEnum::CARD->value);

        $result = $this->listener->extractData($this->emptyResult(), 'exchange_payment_requested', [$exchange->fresh()]);

        $this->assertStringContainsString('6,000', $result['data']['charge_amount']);
        $this->assertStringContainsString('6,000', $result['data']['outstanding_amount']);
    }

    /**
     * 운영자가 회원 주문을 대리 접수하면 `requested_by` 가 null 이다.
     *
     * 그 값을 그대로 수신자로 쓰면 **고객이 자기 주문의 환불 알림을 한 통도 못 받는다** —
     * 로그도 없이 조용하다. 수신자 축에서만 주문 주인으로 폴백해야 한다(도메인 값은 그대로).
     *
     * @effects staff_filed_claim_still_notifies_the_order_owner
     *
     * @return void
     */
    public function test_a_staff_filed_claim_still_notifies_the_order_owner(): void
    {
        $order = $this->deliveredClaimOrder();
        $return = $this->requestedReturn($order);

        // 운영자 대리 접수 재현 — 신청자 컬럼은 비고, 주문 주인은 그대로다.
        $return->forceFill(['requested_by' => null])->save();
        $return->refresh();

        $result = $this->listener->extractData($this->emptyResult(), 'return_requested', [$return->fresh()]);

        $this->assertSame(
            $order->user_id,
            $result['context']['trigger_user_id'],
            '대리 접수여도 "이 알림을 받아야 하는 사람" 은 주문의 주인입니다.'
        );
        $this->assertNull($return->fresh()->requested_by, '도메인 값(누가 신청했는가)은 건드리지 않습니다.');
    }

    /**
     * 추출 단계의 기본 링크는 **언제나 고객 링크**여야 한다.
     *
     * 관리자 주소는 수신자가 정해진 뒤 personalize 가 올린다. 추출 단계에서 유형 이름으로
     * 관리자 여부를 단정하면, 운영자가 그 알림의 수신자를 「주문자」로 바꾸는 순간
     * **고객에게 `/admin/...` 주소가 간다** — 예외도 404 도 없이 빈 화면이 열린다.
     *
     * @effects extracted_claim_link_defaults_to_the_customer_screen
     *
     * @return void
     */
    public function test_extracted_claim_link_defaults_to_the_customer_screen(): void
    {
        $return = $this->requestedReturn();

        $result = $this->listener->extractData($this->emptyResult(), 'claim_withdrawn', [$return]);

        $this->assertSame('{recipient_name}', $result['data']['name'], '이름은 수신자마다 코어가 치환한다.');
        $this->assertStringEndsWith('/mypage/claims/'.$return->return_number, $result['data']['claim_url']);
        $this->assertStringNotContainsString('/admin/', $result['data']['claim_url']);
        $this->assertNotSame('', $result['data']['requester_name']);
        $this->assertSame($return->return_number, $result['context']['claim_number']);
    }

    /**
     * 환불 알림의 금액은 화면이 말하는 환불액과 같아야 한다.
     *
     * `refund_shipping_amount` 는 가산 항목이 아니라 `refund_amount` 안에 이미 든 값의
     * **내역**이다 — 배송비 재판정 차는 재계산 결제액에 포함돼 있고(`OrderAdjustmentService`),
     * 교환의 회수비는 음수로 기록되지만 그 몫 역시 `refund_amount` 에서 이미 빠졌다
     * (`OrderExchangeService`). 그래서 이 컬럼을 더하면 유형마다 반대 방향으로 어긋난다 —
     * 교환은 실제보다 적게, 반품·취소는 많게 알린다. 화면(`ClaimResource::refund_total`)은
     * 현금 + 마일리지만 더하므로 같은 신청서를 두 곳이 다른 금액으로 말한다.
     *
     * @return void
     *
     * @effects refund_notification_amount_matches_what_the_screen_reports
     */
    public function test_refund_notification_amount_matches_what_the_screen_reports(): void
    {
        $order = $this->deliveredClaimOrder();

        $fromExchange = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::EXCHANGE,
            'refund_source_id' => 1,
            'refund_number' => 'RF-EXCHANGE-1',
            'refund_status' => RefundStatusEnum::COMPLETED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 7000,
            'refund_points_amount' => 0,
            // 저가 교환의 왕복 배송비 차감분 — 7,000 은 이미 이 몫을 뺀 순액이다.
            'refund_shipping_amount' => -3000,
        ]);

        $result = $this->listener->extractData($this->emptyResult(), 'refund_completed', [$fromExchange->fresh()]);

        $this->assertStringContainsString(
            '7,000',
            $result['data']['refund_amount'],
            '교환 출처 환불은 실제 입금액을 알려야 한다 — 회수비를 한 번 더 빼면 4,000 이 된다.'
        );

        $fromReturn = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::RETURN,
            'refund_source_id' => 1,
            'refund_number' => 'RF-RETURN-1',
            'refund_status' => RefundStatusEnum::COMPLETED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 26000,
            'refund_points_amount' => 0,
            // 반품으로 되돌아가는 주문 배송비 — 26,000 에 이미 포함된 값의 내역이다.
            'refund_shipping_amount' => 3000,
        ]);

        $result = $this->listener->extractData($this->emptyResult(), 'refund_completed', [$fromReturn->fresh()]);

        $this->assertStringContainsString(
            '26,000',
            $result['data']['refund_amount'],
            '반품 출처 환불은 배송비를 두 번 세면 29,000 이 된다.'
        );
    }

    /**
     * @effects non_claim_notification_types_pass_through_untouched
     *
     * @return void
     */
    public function test_non_claim_types_pass_through_untouched(): void
    {
        $default = ['notifiable' => null, 'notifiables' => null, 'data' => ['name' => '주문 축'], 'context' => ['trigger_user_id' => 7]];

        $result = $this->listener->extractData($default, 'order_confirmed', []);

        $this->assertSame($default, $result, '주문 축 리스너가 만든 결과를 덮으면 주문 알림이 조용히 비게 됩니다.');
    }

    /**
     * @effects a_claim_type_without_a_claim_argument_passes_through
     */
    public function test_a_claim_type_without_a_claim_argument_passes_through(): void
    {
        $default = $this->emptyResult();

        $result = $this->listener->extractData($default, 'return_requested', ['not-a-claim']);

        $this->assertSame($default, $result);
    }
}
