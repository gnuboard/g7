<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\OrderReturnOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\ShippingCarrierService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 상품 행 송장 규칙 테스트 (#599 후속 §3-2 R1~R12 — `RecordsClaimTracking`)
 *
 * 송장은 신청서 헤더가 아니라 **상품 행**에 붙는다(주문과 같은 행 쪼개기 방식). 교환·반품 서비스가
 * 같은 trait 을 쓰므로 두 유형을 같은 규칙으로 잰다.
 *
 * 검증 축:
 * - R1 방향 · R2 반쪽 송장 · R3 고른 행만 · R4 수량 지정 = 행 쪼개기 · R5 상태별 쓰기 허용
 * - R6 이동 전이는 대상 행 전부에 자기 송장 (다른 행 송장을 빌리지 않는다 — S1)
 * - R7 「송장만 저장」 상태 불변·비우면 삭제·이동 상태 행은 삭제 거절 · R8 회수지 혼합 거절
 * - R9 운송장 단위 도착 · R11 후속 배송 행이 그 행 송장을 쓴다 · R12 택배사 사용 집계
 */
class ClaimItemTrackingTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimFlowOrders;

    /**
     * 통화 설정과 클레임 사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    /**
     * 통화 설정과 고정 시각을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    // ================================================================
    // 헬퍼
    // ================================================================

    /**
     * 교환 서비스를 돌려줍니다.
     *
     * @return OrderExchangeService 교환 서비스
     */
    protected function exchangeService(): OrderExchangeService
    {
        return app(OrderExchangeService::class);
    }

    /**
     * 반품 서비스를 돌려줍니다.
     *
     * @return OrderReturnService 반품 서비스
     */
    protected function returnService(): OrderReturnService
    {
        return app(OrderReturnService::class);
    }

    /**
     * 왕복 배송비 0원 교환을 접수하고 승인합니다. (선교환이면 승인 전에 방식을 바꾼다)
     *
     * @param  int  $quantity  신청 수량 (주문 수량과 같다)
     * @param  bool  $advance  선교환 여부
     * @return OrderExchange 승인된 교환 신청서
     */
    protected function approvedExchange(int $quantity = 1, bool $advance = false): OrderExchange
    {
        $order = $this->deliveredClaimOrder(quantity: $quantity, exchangeFee: 0);
        $service = $this->exchangeService();

        $exchange = $service->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => $quantity]],
            'reason_code' => 'size_mismatch',
        ]);

        if ($advance) {
            app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_enabled', true);
            $exchange = $service->setExchangeMode($exchange->fresh(['exchangeOptions']), ExchangeModeEnum::ADVANCE);
        }

        return $service->transition($exchange->fresh(), ExchangeStatusEnum::APPROVED)->fresh(['exchangeOptions']);
    }

    /**
     * 반품을 접수합니다. (환불금 차감 — 승인하면 미수납 없이 진행할 수 있다)
     *
     * @param  int  $quantity  신청 수량 (주문 수량과 같다)
     * @param  bool  $approve  승인까지 진행할지
     * @return OrderReturn 반품 신청서
     */
    protected function returnOf(int $quantity = 1, bool $approve = true): OrderReturn
    {
        $order = $this->deliveredClaimOrder(quantity: $quantity);
        $service = $this->returnService();

        $return = $service->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => $quantity]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);

        return ($approve ? $service->transition($return, ReturnStatusEnum::APPROVED) : $return)->fresh(['returnOptions']);
    }

    /**
     * 신청서의 첫 상품 행 ID 를 돌려줍니다.
     *
     * @param  OrderExchange|OrderReturn  $claim  신청서
     * @return int 상품 행 ID
     */
    protected function firstItemId(OrderExchange|OrderReturn $claim): int
    {
        $items = $claim instanceof OrderExchange
            ? $claim->fresh('exchangeOptions')->exchangeOptions
            : $claim->fresh('returnOptions')->returnOptions;

        return (int) $items->sortBy('id')->first()->id;
    }

    /**
     * 운영자가 등록한 택배사를 만듭니다. (시드 택배사와 구분되는 사용 집계용)
     *
     * @param  string  $code  택배사 코드
     * @return ShippingCarrier 택배사
     */
    protected function operatorCarrier(string $code): ShippingCarrier
    {
        return ShippingCarrier::create([
            'code' => $code,
            'name' => ['ko' => '운영자 택배', 'en' => 'Operator carrier'],
            'type' => 'domestic',
            'is_active' => true,
            'sort_order' => 0,
        ]);
    }

    // ================================================================
    // R1 · R2 — 방향 · 반쪽 송장
    // ================================================================

    /**
     * 반품 행에는 재발송 방향이 없다 — 「송장만 저장」 도 운송장 단위 도착도 거절한다. (R1)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=redelivery
     *
     * @effects tracking_direction_is_limited_by_claim_type
     */
    public function test_return_rejects_the_redelivery_direction(): void
    {
        $return = $this->returnOf();
        $itemId = $this->firstItemId($return);
        $carrier = $this->testClaimCarrier();

        $this->assertClaimException('claim_tracking_direction_not_allowed', fn () => $this->returnService()
            ->writeItemTracking($return, ClaimTrackingDirectionEnum::REDELIVERY, [$itemId], $carrier->id, 'RD-1'));
        $this->assertClaimException('claim_tracking_direction_not_allowed', fn () => $this->returnService()
            ->deliverTracking($return, ClaimTrackingDirectionEnum::REDELIVERY, $carrier->id, 'RD-1'));

        $this->assertNull(OrderReturnOption::find($itemId)->collection_tracking_number);
    }

    /**
     * 택배사와 운송장은 둘 다 있거나 둘 다 없다 — 공백뿐인 운송장은 없는 것이다. (R2)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=standard, direction=collection
     *
     * @effects tracking_requires_carrier_and_number_together
     */
    public function test_half_a_tracking_pair_is_rejected(): void
    {
        $exchange = $this->approvedExchange();
        $itemId = $this->firstItemId($exchange);
        $carrier = $this->testClaimCarrier();

        $this->assertClaimException('claim_tracking_incomplete', fn () => $this->exchangeService()
            ->writeItemTracking($exchange, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, null));
        $this->assertClaimException('claim_tracking_incomplete', fn () => $this->exchangeService()
            ->writeItemTracking($exchange, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], null, 'NO-CARRIER'));
        $this->assertClaimException('claim_tracking_incomplete', fn () => $this->exchangeService()
            ->writeItemTracking($exchange, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, '   '));

        $this->assertNull(OrderExchangeOption::find($itemId)->collection_tracking_number);
    }

    // ================================================================
    // R3 · R4 — 고른 행만 · 수량 지정 = 행 쪼개기
    // ================================================================

    /**
     * 한 행의 송장을 고쳐도 같은 운송장을 가진 다른 행은 그대로다. (R3 · D10 — 주문과 같다)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=standard, direction=collection
     *
     * @effects tracking_write_touches_only_the_chosen_rows
     */
    public function test_editing_one_row_does_not_touch_another_row_with_the_same_tracking(): void
    {
        $exchange = $this->approvedExchange(3);
        $service = $this->exchangeService();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($exchange);

        $split = $service->writeItemTracking($exchange, ClaimTrackingDirectionEnum::COLLECTION, [['id' => $itemId, 'quantity' => 1]], $carrier->id, 'BOX-1')['split'];
        $remainderId = $split[0]['remainder_id'];

        // 두 번째 행도 같은 상자(같은 운송장)로 보낸다.
        $service->writeItemTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$remainderId], $carrier->id, 'BOX-1');
        $this->assertSame('BOX-1', OrderExchangeOption::find($remainderId)->collection_tracking_number);

        // 첫 행만 운송장을 고친다 — 같은 운송장을 가진 두 번째 행은 바뀌지 않는다.
        $service->writeItemTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, 'BOX-2');

        $this->assertSame('BOX-2', OrderExchangeOption::find($itemId)->collection_tracking_number);
        $this->assertSame('BOX-1', OrderExchangeOption::find($remainderId)->collection_tracking_number);
    }

    /**
     * 수량을 지정하면 행을 쪼개 지정 수량 행에만 송장을 쓰고, 잔여 행은 원래 값을 잇는다. 금액 합은 보존된다. (R4)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects tracking_quantity_splits_the_row_and_the_remainder_keeps_its_value
     */
    public function test_quantity_splits_the_row_and_the_remainder_keeps_its_original_value(): void
    {
        $return = $this->returnOf(3);
        $service = $this->returnService();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($return);
        $subtotalBefore = (float) OrderReturnOption::find($itemId)->subtotal_amount;

        // 행 전체에 먼저 상자 A 를 적는다.
        $service->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, 'BOX-A');

        // 3개 중 1개에만 상자 B — 1개 행과 2개 행으로 나뉜다.
        $result = $service->writeItemTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [['id' => $itemId, 'quantity' => 1]], $carrier->id, 'BOX-B');

        $this->assertCount(1, $result['split']);
        $this->assertSame($itemId, $result['split'][0]['id']);
        $this->assertSame(1, $result['split'][0]['quantity']);
        $this->assertSame(2, $result['split'][0]['remainder_quantity']);

        $target = OrderReturnOption::find($itemId);
        $remainder = OrderReturnOption::find($result['split'][0]['remainder_id']);

        $this->assertSame(1, (int) $target->return_quantity);
        $this->assertSame('BOX-B', $target->collection_tracking_number);
        $this->assertSame(2, (int) $remainder->return_quantity);
        $this->assertSame('BOX-A', $remainder->collection_tracking_number, '잔여 행이 원래 송장을 잃었습니다.');
        $this->assertSame(ReturnStatusEnum::APPROVED, $target->option_status);
        $this->assertSame(ReturnStatusEnum::APPROVED, $remainder->option_status);
        $this->assertEqualsWithDelta($subtotalBefore, (float) $target->subtotal_amount + (float) $remainder->subtotal_amount, 0.001);
    }

    /**
     * 행 수량을 넘는 수량이나 다른 신청서의 행은 거절한다. (R4 경계)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects tracking_quantity_splits_the_row_and_the_remainder_keeps_its_value
     */
    public function test_quantity_over_the_row_or_a_foreign_row_is_rejected(): void
    {
        $return = $this->returnOf(2);
        $other = $this->returnOf();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($return);

        $this->assertClaimException('claim_quantity_invalid', fn () => $this->returnService()
            ->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [['id' => $itemId, 'quantity' => 3]], $carrier->id, 'X-1'));
        $this->assertClaimException('claim_item_not_found', fn () => $this->returnService()
            ->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [$this->firstItemId($other)], $carrier->id, 'X-1'));
        $this->assertClaimException('claim_items_required', fn () => $this->returnService()
            ->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [], $carrier->id, 'X-1'));
    }

    // ================================================================
    // R5 — 상태별 쓰기 허용
    // ================================================================

    /**
     * 일반 교환: 회수 송장은 승인~회수중, 재발송 송장은 회수도착~발송중에서만 받는다. (R5)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=standard, direction=redelivery
     *
     * @effects tracking_write_is_limited_by_row_status
     */
    public function test_standard_exchange_writable_statuses(): void
    {
        $exchange = $this->approvedExchange();
        $service = $this->exchangeService();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($exchange);

        // 승인 — 재발송 송장은 아직 받지 않는다.
        $this->assertClaimException('claim_tracking_item_not_eligible', fn () => $service
            ->writeItemTracking($exchange, ClaimTrackingDirectionEnum::REDELIVERY, [$itemId], $carrier->id, 'RD-1'));
        $service->writeItemTracking($exchange, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, 'CL-1');

        // 회수도착 — 회수 송장은 닫히고 재발송 송장이 열린다.
        $collected = $service->transition($exchange->fresh(), ExchangeStatusEnum::COLLECTED);
        $this->assertClaimException('claim_tracking_item_not_eligible', fn () => $service
            ->writeItemTracking($collected, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, 'CL-2'));
        $service->writeItemTracking($collected, ClaimTrackingDirectionEnum::REDELIVERY, [$itemId], $carrier->id, 'RD-1');

        $row = OrderExchangeOption::find($itemId);
        $this->assertSame('CL-1', $row->collection_tracking_number);
        $this->assertSame('RD-1', $row->redelivery_tracking_number);
    }

    /**
     * 선교환: 재발송 송장은 승인부터, 회수 송장은 발송 뒤에도(종결 전) 받는다. 발송도착 뒤 재발송 송장은 닫힌다. (R5)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=advance, direction=collection
     *
     * @effects tracking_write_is_limited_by_row_status
     */
    public function test_advance_exchange_writable_statuses(): void
    {
        $exchange = $this->approvedExchange(1, advance: true);
        $service = $this->exchangeService();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($exchange);

        // 승인에서 곧바로 재발송 송장을 받는다(새 물건을 먼저 보낸다).
        $service->writeItemTracking($exchange, ClaimTrackingDirectionEnum::REDELIVERY, [$itemId], $carrier->id, 'RD-ADV');

        $redelivered = $service->transition($exchange->fresh(), ExchangeStatusEnum::REDELIVERED);
        $this->assertSame(ExchangeStatusEnum::REDELIVERED, $redelivered->exchange_status);

        // 발송도착 뒤에도 헌 물건의 회수 송장은 받는다.
        $service->writeItemTracking($redelivered, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, 'CL-ADV');
        // 재발송 송장은 발송도착 뒤로 닫힌다.
        $this->assertClaimException('claim_tracking_item_not_eligible', fn () => $service
            ->writeItemTracking($redelivered->fresh(), ClaimTrackingDirectionEnum::REDELIVERY, [$itemId], $carrier->id, 'RD-2'));

        $row = OrderExchangeOption::find($itemId);
        $this->assertSame('CL-ADV', $row->collection_tracking_number);
        $this->assertSame(ExchangeStatusEnum::REDELIVERED, $row->option_status, '회수 송장이 선교환 상태를 옮겼습니다.');
    }

    /**
     * 반품: 회수 송장은 승인~회수중에서만 받는다 — 신청·회수도착은 거절한다. (R5)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects tracking_write_is_limited_by_row_status
     */
    public function test_return_writable_statuses(): void
    {
        $requested = $this->returnOf(1, approve: false);
        $carrier = $this->testClaimCarrier();

        $this->assertClaimException('claim_tracking_item_not_eligible', fn () => $this->returnService()
            ->writeItemTracking($requested, ClaimTrackingDirectionEnum::COLLECTION, [$this->firstItemId($requested)], $carrier->id, 'CL-1'));

        $collected = $this->returnService()->transition($this->returnOf(), ReturnStatusEnum::COLLECTED);

        $this->assertClaimException('claim_tracking_item_not_eligible', fn () => $this->returnService()
            ->writeItemTracking($collected, ClaimTrackingDirectionEnum::COLLECTION, [$this->firstItemId($collected)], $carrier->id, 'CL-2'));
    }

    // ================================================================
    // R6 · R7 — 이동 전이의 송장 · 「송장만 저장」
    // ================================================================

    /**
     * 회수중 전이는 대상 행 전부에 자기 송장이 있어야 한다 — 송장 없는 행이 섞이면 거절하고, 다른 행의
     * 송장을 빌려 쓰지 않는다. 자기 송장을 가진 행만 옮기면 통과한다. (R6 · S1)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects transition_requires_each_rows_own_tracking
     */
    public function test_collecting_requires_each_rows_own_tracking_and_never_borrows(): void
    {
        $return = $this->returnOf(2);
        $service = $this->returnService();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($return);

        $remainderId = $service->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [['id' => $itemId, 'quantity' => 1]], $carrier->id, 'BOX-1')['split'][0]['remainder_id'];

        $this->assertClaimException('claim_tracking_required', fn () => $service
            ->transitionOption($return->fresh(['returnOptions']), [$itemId, $remainderId], ReturnStatusEnum::COLLECTING));
        $this->assertClaimException('claim_tracking_required', fn () => $service
            ->transition($return->fresh(['returnOptions']), ReturnStatusEnum::COLLECTING));

        // 거절된 전이는 아무것도 옮기지 않았고, 송장 없는 행이 첫 행 송장을 물려받지도 않았다.
        $this->assertNull(OrderReturnOption::find($remainderId)->collection_tracking_number);
        $this->assertSame(ReturnStatusEnum::APPROVED, OrderReturnOption::find($itemId)->option_status);

        $result = $service->transitionOption($return->fresh(['returnOptions']), [$itemId], ReturnStatusEnum::COLLECTING);

        $this->assertSame([$itemId], array_map('intval', $result['succeeded']));
        $this->assertSame(ReturnStatusEnum::COLLECTING, OrderReturnOption::find($itemId)->option_status);
        $this->assertSame('BOX-1', OrderReturnOption::find($itemId)->collection_tracking_number);
        $this->assertSame(ReturnStatusEnum::APPROVED, OrderReturnOption::find($remainderId)->option_status);
        $this->assertNull(OrderReturnOption::find($remainderId)->collection_tracking_number);
    }

    /**
     * 「송장만 저장」 은 상태를 바꾸지 않고, 비우면 그 행 송장과 등록 시각을 지운다. 회수중 행은 비울 수 없다. (R7)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=standard, direction=collection
     *
     * @effects tracking_only_save_keeps_status_and_clears_on_empty
     */
    public function test_tracking_only_save_keeps_status_clears_on_empty_and_protects_moving_rows(): void
    {
        $exchange = $this->approvedExchange(2);
        $service = $this->exchangeService();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($exchange);

        $service->writeItemTracking($exchange, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, 'SAVE-1');
        $row = OrderExchangeOption::find($itemId);
        $this->assertSame(ExchangeStatusEnum::APPROVED, $row->option_status);
        $this->assertSame(ExchangeStatusEnum::APPROVED, $exchange->fresh()->exchange_status);
        $this->assertNotNull($row->collection_shipped_at);

        // 둘 다 비우면 지운다.
        $service->writeItemTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$itemId], null, null);
        $row = OrderExchangeOption::find($itemId);
        $this->assertNull($row->collection_tracking_number);
        $this->assertNull($row->collection_carrier_id);
        $this->assertNull($row->collection_shipped_at);

        // 회수중 행의 송장은 지울 수 없다 — 이동 상태의 근거가 사라진다.
        $service->transition($exchange->fresh(), ExchangeStatusEnum::COLLECTING, ['carrier_id' => $carrier->id, 'tracking_number' => 'MOVE-1']);
        $this->assertClaimException('claim_tracking_required', fn () => $service
            ->writeItemTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$itemId], null, null));
        $this->assertSame('MOVE-1', OrderExchangeOption::find($itemId)->collection_tracking_number);
    }

    // ================================================================
    // R8 — 회수지 혼합
    // ================================================================

    /**
     * 한 회수 송장은 한 회수지로 간다 — 회수지가 다른 행을 한 송장으로 묶으면 거절한다. 이미 그 운송장을 가진
     * 다른 행과 회수지가 달라도 거절하고, 다른 운송장이면 받는다. (R8 · S4)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects tracking_rejects_mixed_return_addresses
     */
    public function test_mixed_return_addresses_cannot_share_a_collection_tracking(): void
    {
        $return = $this->returnOf(2);
        $service = $this->returnService();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($return);

        // 상품 행을 둘로 나눈 뒤 회수지를 서로 다르게 둔다(배송정책이 다른 상품을 한 신청서로 묶은 경우).
        $remainderId = $service->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [['id' => $itemId, 'quantity' => 1]], null, null)['split'][0]['remainder_id'];
        OrderReturnOption::find($itemId)->forceFill(['return_address_snapshot' => ['recipient_name' => 'A 창고', 'zipcode' => '06236', 'address1' => '서울 강남구 1']])->save();
        OrderReturnOption::find($remainderId)->forceFill(['return_address_snapshot' => ['recipient_name' => 'B 창고', 'zipcode' => '48058', 'address1' => '부산 해운대구 2']])->save();

        $this->assertClaimException('claim_tracking_mixed_return_address', fn () => $service
            ->writeItemTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$itemId, $remainderId], $carrier->id, 'MIX-1'));

        $service->writeItemTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, 'MIX-1');

        $this->assertClaimException('claim_tracking_mixed_return_address', fn () => $service
            ->writeItemTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$remainderId], $carrier->id, 'MIX-1'));

        $service->writeItemTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$remainderId], $carrier->id, 'MIX-2');
        $this->assertSame('MIX-2', OrderReturnOption::find($remainderId)->collection_tracking_number);
    }

    // ================================================================
    // R9 — 운송장 단위 도착
    // ================================================================

    /**
     * 일반 반품: 한 운송장의 행 전부가 회수도착으로 가고 행 도착 시각이 찍힌다. 이미 도착한 행은 건너뛴다. (R9)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects tracking_deliver_moves_every_row_of_the_waybill
     */
    public function test_deliver_moves_every_row_of_the_waybill_and_skips_arrived_rows(): void
    {
        $return = $this->returnOf(3);
        $service = $this->returnService();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($return);

        $remainderId = $service->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [['id' => $itemId, 'quantity' => 1]], $carrier->id, 'WAY-1')['split'][0]['remainder_id'];
        $service->writeItemTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$remainderId], $carrier->id, 'WAY-1');

        // 첫 행은 먼저 도착 처리해 둔다.
        $service->transitionOption($return->fresh(['returnOptions']), [$itemId], ReturnStatusEnum::COLLECTED);

        $result = $service->deliverTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, $carrier->id, 'WAY-1');

        $this->assertSame([$remainderId], array_map('intval', $result['succeeded']));
        $this->assertSame([$itemId], array_map(fn ($row) => (int) $row['id'], $result['skipped']));

        $remainder = OrderReturnOption::find($remainderId);
        $this->assertSame(ReturnStatusEnum::COLLECTED, $remainder->option_status);
        $this->assertNotNull($remainder->collected_at);
        $this->assertSame(ReturnStatusEnum::COLLECTED, $return->fresh()->return_status);

        $this->assertClaimException('claim_tracking_not_found', fn () => $service
            ->deliverTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, $carrier->id, 'NO-SUCH'));
    }

    /**
     * 일반 교환: 재발송 운송장 도착은 발송도착 전이다 — 행 재발송 도착 시각이 찍힌다. (R9)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=standard, direction=redelivery
     *
     * @effects tracking_deliver_moves_every_row_of_the_waybill
     */
    public function test_standard_exchange_redelivery_deliver_moves_to_redelivered(): void
    {
        $exchange = $this->approvedExchange();
        $service = $this->exchangeService();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($exchange);

        $service->transition($exchange, ExchangeStatusEnum::COLLECTED);
        $service->transition($exchange->fresh(), ExchangeStatusEnum::REDELIVERING, ['carrier_id' => $carrier->id, 'tracking_number' => 'RD-WAY']);

        $result = $service->deliverTracking($exchange->fresh(), ClaimTrackingDirectionEnum::REDELIVERY, $carrier->id, 'RD-WAY');

        $this->assertSame([$itemId], array_map('intval', $result['succeeded']));
        $row = OrderExchangeOption::find($itemId);
        $this->assertSame(ExchangeStatusEnum::REDELIVERED, $row->option_status);
        $this->assertNotNull($row->redelivered_at);
        $this->assertSame(ExchangeStatusEnum::REDELIVERED, $exchange->fresh()->exchange_status);
    }

    /**
     * 선교환 회수 운송장 도착은 상태를 바꾸지 않고 행 회수 도착 시각만 찍는다 — 두 번째 도착은 건너뛴다. (R9)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=advance, direction=collection
     *
     * @effects tracking_deliver_advance_collection_only_stamps_arrival
     */
    public function test_advance_collection_deliver_only_stamps_arrival(): void
    {
        $exchange = $this->approvedExchange(1, advance: true);
        $service = $this->exchangeService();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($exchange);

        $service->transition($exchange, ExchangeStatusEnum::REDELIVERING, ['carrier_id' => $carrier->id, 'tracking_number' => 'RD-ADV']);
        $service->writeItemTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, 'CL-ADV');

        $result = $service->deliverTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, $carrier->id, 'CL-ADV');

        $this->assertSame([$itemId], array_map('intval', $result['succeeded']));
        $row = OrderExchangeOption::find($itemId);
        $this->assertSame(ExchangeStatusEnum::REDELIVERING, $row->option_status, '선교환 회수 도착이 상태를 옮겼습니다.');
        $this->assertNotNull($row->collected_at);

        $again = $service->deliverTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, $carrier->id, 'CL-ADV');
        $this->assertSame([], $again['succeeded']);
        $this->assertSame([$itemId], array_map(fn ($skip) => (int) $skip['id'], $again['skipped']));
    }

    /**
     * 선교환 재발송 운송장 도착은 발송도착 전이이고 회수 기한을 도착 시각 기준으로 다시 잡는다. (R9 · §3-5)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=advance, direction=redelivery
     *
     * @effects tracking_deliver_advance_redelivery_recalculates_the_due_date
     */
    public function test_advance_redelivery_deliver_recalculates_the_collection_due_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-10 10:00:00'));
        $exchange = $this->approvedExchange(1, advance: true);
        $service = $this->exchangeService();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($exchange);

        $service->transition($exchange, ExchangeStatusEnum::REDELIVERING, ['carrier_id' => $carrier->id, 'tracking_number' => 'RD-DUE']);
        // 도착 기록이 없으면 발송(송장 등록) 시각이 기준이다 — 기본 14일.
        $this->assertSame('2026-09-24 10:00:00', $exchange->fresh()->collection_due_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::parse('2026-09-12 09:00:00'));
        $result = $service->deliverTracking($exchange->fresh(), ClaimTrackingDirectionEnum::REDELIVERY, $carrier->id, 'RD-DUE');

        $this->assertSame([$itemId], array_map('intval', $result['succeeded']));
        $this->assertSame(ExchangeStatusEnum::REDELIVERED, OrderExchangeOption::find($itemId)->option_status);
        // 도착 기록이 생기면 도착 시각이 기준이다.
        $this->assertSame('2026-09-26 09:00:00', $exchange->fresh()->collection_due_at?->format('Y-m-d H:i:s'));
    }

    // ================================================================
    // R11 · R12 — 후속 배송 행 · 택배사 사용 집계
    // ================================================================

    /**
     * 수량을 나눠 보낸 교환품은 행마다 자기 후속 주문옵션 배송 행에 자기 재발송 송장을 싣는다. (R11 · S9)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=standard, direction=redelivery
     *
     * @effects successor_shipping_uses_its_rows_redelivery_tracking
     */
    public function test_each_redelivered_row_carries_its_own_tracking_to_its_successor_shipping(): void
    {
        $exchange = $this->approvedExchange(2);
        $service = $this->exchangeService();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($exchange);

        $service->transition($exchange, ExchangeStatusEnum::COLLECTED);

        $first = $service->transitionOption($exchange->fresh(['exchangeOptions']), [['id' => $itemId, 'quantity' => 1]], ExchangeStatusEnum::REDELIVERING, [
            'carrier_id' => $carrier->id,
            'tracking_number' => 'SHIP-1',
        ]);
        $remainderId = $first['split'][0]['remainder_id'];

        $service->transitionOption($exchange->fresh(['exchangeOptions']), [$remainderId], ExchangeStatusEnum::REDELIVERING, [
            'carrier_id' => $carrier->id,
            'tracking_number' => 'SHIP-2',
        ]);

        $rowA = OrderExchangeOption::find($itemId);
        $rowB = OrderExchangeOption::find($remainderId);
        $this->assertNotNull($rowA->new_order_option_id);
        $this->assertNotNull($rowB->new_order_option_id);
        $this->assertNotSame($rowA->new_order_option_id, $rowB->new_order_option_id);

        $this->assertSame('SHIP-1', OrderShipping::where('order_option_id', $rowA->new_order_option_id)->first()?->tracking_number);
        $this->assertSame('SHIP-2', OrderShipping::where('order_option_id', $rowB->new_order_option_id)->first()?->tracking_number);

        // 한 행의 재발송 송장을 고치면 그 행의 후속 배송 행만 따라 바뀐다.
        $service->writeItemTracking($exchange->fresh(), ClaimTrackingDirectionEnum::REDELIVERY, [$itemId], $carrier->id, 'SHIP-1B');
        $this->assertSame('SHIP-1B', OrderShipping::where('order_option_id', $rowA->new_order_option_id)->first()?->tracking_number);
        $this->assertSame('SHIP-2', OrderShipping::where('order_option_id', $rowB->new_order_option_id)->first()?->tracking_number);
    }

    /**
     * 재발송 배송 행은 송장·정책만 승계하고 **배송비는 원 행에 남는다.** (S1-D11)
     *
     * 원 배송 행을 금액째 복제하면 교환품 줄이 「유료배송 · 배송비 3,000원」 으로 보이고 구매자 「배송 현황」 에도
     * 같은 배송비가 한 줄 더 생긴다 — 그 재발송은 고객이 낸 배송이 아니다(실측: 판매자 귀책 교환 EX…0235594357,
     * 주문 헤더 배송비 3,000 인데 행 합 6,000). 수량 분할 복제본과 같은 규칙(S3-D9)이다.
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=exchange, exchange_mode=standard, direction=redelivery
     *
     * @effects successor_shipping_carries_no_shipping_fee
     */
    public function test_successor_shipping_carries_no_shipping_fee(): void
    {
        $exchange = $this->approvedExchange(1);
        $service = $this->exchangeService();
        $carrier = $this->testClaimCarrier();
        $itemId = $this->firstItemId($exchange);
        $originalOptionId = (int) OrderExchangeOption::find($itemId)->order_option_id;

        OrderShipping::where('order_option_id', $originalOptionId)->update([
            'base_shipping_amount' => 3000,
            'total_shipping_amount' => 3000,
        ]);

        $service->transition($exchange, ExchangeStatusEnum::COLLECTED);
        $service->transitionOption($exchange->fresh(['exchangeOptions']), [$itemId], ExchangeStatusEnum::REDELIVERING, [
            'carrier_id' => $carrier->id,
            'tracking_number' => 'SHIP-FEE',
        ]);

        $successorShipping = OrderShipping::where('order_option_id', OrderExchangeOption::find($itemId)->new_order_option_id)->first();

        $this->assertSame('SHIP-FEE', $successorShipping?->tracking_number);
        $this->assertSame(0.0, (float) $successorShipping->total_shipping_amount, '재발송 행에 배송비가 복제되면 안 된다.');
        $this->assertSame(0.0, (float) $successorShipping->base_shipping_amount);
        $this->assertSame(3000.0, (float) OrderShipping::where('order_option_id', $originalOptionId)->first()->total_shipping_amount, '배송비는 원 행에 남는다.');
    }

    /**
     * 택배사 사용 집계는 상품 행 송장을 센다 — 행에 쓰면 사용 중, 지우면 다시 0 이다. (R12)
     *
     * @return void 반환값 없음
     *
     * @scenario claim_type=return, exchange_mode=standard, direction=collection
     *
     * @effects carrier_usage_counts_row_level_tracking
     */
    public function test_carrier_usage_counts_row_level_tracking(): void
    {
        $return = $this->returnOf();
        $exchange = $this->approvedExchange();
        $carrier = $this->operatorCarrier('r12_row_usage');
        $usage = app(ShippingCarrierService::class);

        $this->assertSame(0, $usage->countUsage($carrier->id));

        $this->returnService()->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [$this->firstItemId($return)], $carrier->id, 'USE-1');
        $this->exchangeService()->writeItemTracking($exchange, ClaimTrackingDirectionEnum::COLLECTION, [$this->firstItemId($exchange)], $carrier->id, 'USE-2');
        $this->assertSame(2, $usage->countUsage($carrier->id));

        $this->returnService()->writeItemTracking($return->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$this->firstItemId($return)], null, null);
        $this->assertSame(1, $usage->countUsage($carrier->id));
    }
}
