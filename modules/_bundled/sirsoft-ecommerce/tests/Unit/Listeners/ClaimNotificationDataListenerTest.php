<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use Illuminate\Support\Facades\Hash;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
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

    /**
     * 통화 설정·클레임 사유와 알림 데이터 리스너를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->listener = app(ClaimNotificationDataListener::class);
    }

    /**
     * 테스트 통화 설정을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 데이터 필터에 넘길 빈 결과 봉투를 만듭니다.
     *
     * @return array 결과 배열
     */
    private function emptyResult(): array
    {
        return ['notifiable' => null, 'notifiables' => null, 'data' => [], 'context' => []];
    }

    /**
     * 반품을 접수합니다.
     *
     * @param  Order|null  $order  대상 주문 (없으면 배송완료 주문을 만든다)
     * @param  ClaimChargeMethodEnum  $method  청구 방식
     * @return OrderReturn 접수된 반품 신청서
     */
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

    /**
     * 교환을 접수합니다. (신청 시점 결제)
     *
     * @return OrderExchange 접수된 교환 신청서
     */
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
     * 회원 신청 알림이 신청자에게 가고 마이페이지 상세로 링크되는지 검증합니다.
     *
     * @effects member_claim_routes_to_trigger_user_and_links_to_mypage_detail
     *
     * @return void 반환값 없음
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
     * 비회원 신청 알림이 코어 비회원 수신 키와 주문 조회 화면 링크를 쓰는지 검증합니다.
     *
     * @effects guest_claim_uses_core_guest_recipient_key_and_lookup_screen
     *
     * @return void 반환값 없음
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
     * 결제 요청 알림이 청구액과 미수납액을 싣는지 검증합니다.
     *
     * @effects payment_request_data_carries_charge_and_outstanding_amounts
     *
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * 운영자에게 가는 「고객이 ~을(를) 신청했습니다」 는 **고객이 낸 신청**에만 나간다.
     *
     * 운영자가 직권 접수한 반품·교환, 반품·교환 완료가 만든 환불, 운영자가 대리 접수한 환불은 같은
     * `notify_requested` 훅을 지나지만 고객 신청이 아니다 — 그대로 보내면 운영자 전원이 사실과 다른
     * 알림을 받는다(2026-10-07 실측: 반품 완료로 생긴 환불 1건에 운영자 21명 × 2채널). 고객 수신 알림은
     * 그대로 나간다.
     *
     * @effects staff_requested_alert_only_for_customer_requests
     *
     * @return void 반환값 없음
     */
    public function test_staff_requested_alert_is_skipped_unless_the_customer_filed_it(): void
    {
        $order = $this->deliveredClaimOrder();
        $customerReturn = $this->requestedReturn($order);

        $this->assertEmpty(
            $this->listener->extractData($this->emptyResult(), 'return_requested_admin', [$customerReturn->fresh()])['context']['skip'] ?? null,
            '고객이 낸 반품은 운영자에게 알린다.'
        );

        $staffReturn = $this->requestedReturn($this->deliveredClaimOrder());
        $staffReturn->forceFill(['requested_by_admin' => $order->user_id])->save();

        $this->assertTrue(
            (bool) ($this->listener->extractData($this->emptyResult(), 'return_requested_admin', [$staffReturn->fresh()])['context']['skip'] ?? false),
            '운영자가 직권 접수한 반품을 「고객이 신청」 으로 알리면 안 된다.'
        );
        $this->assertEmpty(
            $this->listener->extractData($this->emptyResult(), 'return_requested', [$staffReturn->fresh()])['context']['skip'] ?? null,
            '고객 수신 알림은 직권 접수여도 나간다.'
        );

        $refund = fn (RefundSourceTypeEnum $source, string $number) => OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => $source,
            'refund_source_id' => $source === RefundSourceTypeEnum::DIRECT ? null : 1,
            'refund_number' => $number,
            'refund_status' => RefundStatusEnum::REQUESTED,
            'refund_method' => RefundMethodEnum::PG,
            'refund_amount' => 1000,
            'refund_points_amount' => 0,
        ])->fresh();

        $this->assertEmpty(
            $this->listener->extractData($this->emptyResult(), 'refund_requested_admin', [$refund(RefundSourceTypeEnum::DIRECT, 'RF-DIRECT-1')])['context']['skip'] ?? null,
            '고객이 직접 낸 환불 신청은 운영자에게 알린다.'
        );

        foreach ([RefundSourceTypeEnum::RETURN, RefundSourceTypeEnum::EXCHANGE, RefundSourceTypeEnum::ADMIN] as $source) {
            $this->assertTrue(
                (bool) ($this->listener->extractData($this->emptyResult(), 'refund_requested_admin', [$refund($source, 'RF-'.strtoupper($source->value).'-1')])['context']['skip'] ?? false),
                $source->value.' 출처 환불은 고객 신청이 아니다.'
            );
        }
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
     * @return void 반환값 없음
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
     * @return void 반환값 없음
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
     * 완료된 환불신청서를 만들고 환불 완료 알림 데이터를 추출합니다. (S7)
     *
     * @param  RefundMethodEnum  $method  신청서의 환불 수단
     * @param  int  $cash  현금 환불액 (`refund_amount`)
     * @param  int  $points  마일리지 환불액 (`refund_points_amount`)
     * @return array<string, mixed> 추출된 알림 데이터
     */
    private function refundCompletedData(RefundMethodEnum $method, int $cash, int $points): array
    {
        $order = $this->deliveredClaimOrder();
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::RETURN,
            'refund_source_id' => 1,
            'refund_number' => 'RF-S7-'.$method->value.'-'.$cash.'-'.$points,
            'refund_status' => RefundStatusEnum::COMPLETED,
            'refund_method' => $method,
            'refund_amount' => $cash,
            'refund_points_amount' => $points,
        ]);

        return $this->listener->extractData($this->emptyResult(), 'refund_completed', [$refund->fresh()])['data'];
    }

    /**
     * 카드 주문을 마일리지로만 환불하면(현금 0) 수단은 「마일리지」 이고 현금 줄이 없다.
     *
     * 종전에는 신청서 수단(결제수단에서 정해진 PG) 라벨에 마일리지 합계를 묶어 「PG사 환불 15,000원」 으로 알렸다.
     *
     * @effects refund_notification_splits_cash_and_mileage
     *
     * @return void 반환값 없음
     */
    public function test_mileage_only_refund_is_announced_as_mileage(): void
    {
        $data = $this->refundCompletedData(RefundMethodEnum::PG, 0, 15000);

        $this->assertSame(RefundMethodEnum::POINTS->label(), $data['refund_method_label']);
        $this->assertSame('', $data['refund_cash_amount']);
        $this->assertStringContainsString('15,000', $data['refund_points_amount']);
        $this->assertStringContainsString('15,000', $data['refund_amount'], '합계 변수는 하위호환으로 그대로 둔다');
        $this->assertStringNotContainsString(RefundMethodEnum::PG->label(), $data['refund_breakdown']);
    }

    /**
     * 현금과 마일리지가 섞이면 수단 라벨은 현금 수단이고, 두 금액이 따로 실린다.
     *
     * @effects refund_notification_splits_cash_and_mileage
     *
     * @return void 반환값 없음
     */
    public function test_mixed_refund_carries_cash_and_mileage_separately(): void
    {
        $data = $this->refundCompletedData(RefundMethodEnum::PG, 12000, 3000);

        $this->assertSame(RefundMethodEnum::PG->label(), $data['refund_method_label']);
        $this->assertStringContainsString('12,000', $data['refund_cash_amount']);
        $this->assertStringContainsString('3,000', $data['refund_points_amount']);
        $this->assertStringContainsString('15,000', $data['refund_amount']);

        $lines = explode("\n", $data['refund_breakdown']);
        $this->assertCount(2, $lines);
        $this->assertStringContainsString(RefundMethodEnum::PG->label(), $lines[0]);
        $this->assertStringContainsString('12,000', $lines[0]);
        $this->assertStringContainsString('3,000', $lines[1]);
    }

    /**
     * 마일리지 수단 신청서는 현금분도 마일리지로 지급되므로(결함 1) 마일리지 한 줄로 합쳐 알린다.
     *
     * @effects refund_notification_splits_cash_and_mileage
     *
     * @return void 반환값 없음
     */
    public function test_points_method_refund_is_one_mileage_line(): void
    {
        $data = $this->refundCompletedData(RefundMethodEnum::POINTS, 10000, 2000);

        $this->assertSame(RefundMethodEnum::POINTS->label(), $data['refund_method_label']);
        $this->assertSame('', $data['refund_cash_amount']);
        $this->assertStringContainsString('12,000', $data['refund_points_amount']);
        $this->assertCount(1, explode("\n", $data['refund_breakdown']));
        $this->assertStringContainsString('12,000', $data['refund_breakdown']);
    }

    /**
     * 클레임이 아닌 알림 유형은 앞선 필터 결과를 그대로 돌려주는지 검증합니다.
     *
     * @effects non_claim_notification_types_pass_through_untouched
     *
     * @return void 반환값 없음
     */
    public function test_non_claim_types_pass_through_untouched(): void
    {
        $default = ['notifiable' => null, 'notifiables' => null, 'data' => ['name' => '주문 축'], 'context' => ['trigger_user_id' => 7]];

        $result = $this->listener->extractData($default, 'order_confirmed', []);

        $this->assertSame($default, $result, '주문 축 리스너가 만든 결과를 덮으면 주문 알림이 조용히 비게 됩니다.');
    }

    /**
     * 클레임 유형이어도 신청서 인자가 없으면 결과를 그대로 돌려주는지 검증합니다.
     *
     * @effects a_claim_type_without_a_claim_argument_passes_through
     *
     * @return void 반환값 없음
     */
    public function test_a_claim_type_without_a_claim_argument_passes_through(): void
    {
        $default = $this->emptyResult();

        $result = $this->listener->extractData($default, 'return_requested', ['not-a-claim']);

        $this->assertSame($default, $result);
    }

    /**
     * 운송장이 둘이면 `tracking_lines` 는 운송장마다 한 줄이고, 발행 시점 스냅샷이 있으면 그 값이 이긴다(S10 —
     * 큐가 밀린 사이 송장이 바뀌어도 그때 값이 나간다).
     *
     * @effects tracking_lines_list_every_waybill_and_prefer_the_snapshot
     *
     * @return void 반환값 없음
     */
    public function test_tracking_lines_list_every_waybill_and_prefer_the_snapshot(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $service = app(OrderReturnService::class);
        $return = $service->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 2]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
            'requested_by' => $order->user_id,
        ]);
        $return = $service->transition($return, ReturnStatusEnum::APPROVED);
        $carrier = $this->testClaimCarrier();
        $itemId = (int) $return->fresh('returnOptions')->returnOptions->first()->id;

        $remainderId = $service->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [['id' => $itemId, 'quantity' => 1]], $carrier->id, 'BOX-ONE')['split'][0]['remainder_id'];
        $service->writeItemTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$remainderId], $carrier->id, 'BOX-TWO');
        $collecting = $service->transition($return->fresh(['returnOptions']), ReturnStatusEnum::COLLECTING);

        $lines = explode("\n", $this->listener->extractData($this->emptyResult(), 'return_collecting', [$collecting->fresh()])['data']['tracking_lines']);

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('BOX-ONE', $lines[0].$lines[1]);
        $this->assertStringContainsString('BOX-TWO', $lines[0].$lines[1]);
        $this->assertStringContainsString('× 1', $lines[0]);

        $fromSnapshot = $this->listener->extractData($this->emptyResult(), 'return_collecting', [$collecting->fresh(), ['status' => 'collecting', 'tracking_lines' => 'SNAPSHOT-LINE']]);
        $this->assertSame('SNAPSHOT-LINE', $fromSnapshot['data']['tracking_lines']);
    }
}
