<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimItemCollectionStateEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderExchangeOption;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderShipping;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Support\ClaimItemCollectionState;
use Modules\Sirsoft\Ecommerce\Support\ClaimNotificationHooks;
use Modules\Sirsoft\Ecommerce\Support\ClaimTrackingGroups;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * 선교환 테스트 (#599 후속 §3-3~§3-5 · D1~D3 · D11)
 *
 * 선교환은 새 물건을 먼저 보내고 헌 물건은 기한 안에 받는다. 상태 줄은 발송 단계만 쓰고, 헌 물건의
 * 회수 진행은 상품 행(회수 송장·도착 시각·미회수 표시)이 따로 말한다.
 *
 * 검증 축:
 * - 진행 방식 전환 게이트(설정·재발송 송장·회수 송장·헤더 상태·상품 상태·정상) — 버튼 게이트와 같은 판정
 * - 선교환 전이표(승인 → 발송 단계, 회수 단계 거절) · 헤더 경로(BFS)
 * - 완료 조건(발송 축 × 회수 축) · 미회수 완료(사유 필수·재고 불변) · 완료 필터가 미회수를 조정
 * - 발송된 행을 수량으로 쪼개면 후속 주문옵션도 같은 수량으로 쪼개진다(금액 합 보존 — D11·R10)
 * - 반송 기한(설정 일수 · 발송/도착 기준) · 회수 지연 판정 · 배치 1회 알림과 기한 재계산 초기화 · 배치 명령
 */
class AdvanceExchangeTest extends ModuleTestCase
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
    protected function service(): OrderExchangeService
    {
        return app(OrderExchangeService::class);
    }

    /**
     * 선교환 기능 설정을 켜거나 끕니다.
     *
     * @param  bool  $enabled  켜기 여부
     * @return void 반환값 없음
     */
    protected function enableAdvance(bool $enabled = true): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_enabled', $enabled);
    }

    /**
     * 왕복 배송비 0원 교환을 접수합니다.
     *
     * @param  int  $quantity  신청 수량 (주문 수량과 같다)
     * @return OrderExchange 접수된 교환 신청서
     */
    protected function requestedExchange(int $quantity = 1): OrderExchange
    {
        $order = $this->deliveredClaimOrder(quantity: $quantity, exchangeFee: 0);

        return $this->service()->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => $quantity]],
            'reason_code' => 'size_mismatch',
        ])->fresh(['exchangeOptions']);
    }

    /**
     * 승인된 교환을 만듭니다. (선교환이면 승인 전에 방식을 바꾼다)
     *
     * @param  int  $quantity  신청 수량
     * @param  bool  $advance  선교환 여부
     * @return OrderExchange 승인된 교환 신청서
     */
    protected function approvedExchange(int $quantity = 1, bool $advance = true): OrderExchange
    {
        $exchange = $this->requestedExchange($quantity);

        if ($advance) {
            $this->enableAdvance();
            $exchange = $this->service()->setExchangeMode($exchange, ExchangeModeEnum::ADVANCE);
        }

        return $this->service()->transition($exchange->fresh(), ExchangeStatusEnum::APPROVED)->fresh(['exchangeOptions']);
    }

    /**
     * 교환품을 먼저 보낸(발송중) 선교환을 만듭니다.
     *
     * @param  int  $quantity  신청 수량
     * @return OrderExchange 발송중 선교환
     */
    protected function redeliveringAdvance(int $quantity = 1): OrderExchange
    {
        return $this->service()->transition($this->approvedExchange($quantity), ExchangeStatusEnum::REDELIVERING, [
            'carrier_id' => $this->testClaimCarrier()->id,
            'tracking_number' => 'ADV-RD-1',
        ])->fresh(['exchangeOptions']);
    }

    /**
     * 신청서의 첫 상품 행을 돌려줍니다.
     *
     * @param  OrderExchange  $exchange  교환 신청서
     * @return OrderExchangeOption 상품 행
     */
    protected function firstItem(OrderExchange $exchange): OrderExchangeOption
    {
        return $exchange->fresh('exchangeOptions')->exchangeOptions->sortBy('id')->first();
    }

    /**
     * 보호 메서드를 호출합니다. (서비스 내부 판정을 직접 잰다)
     *
     * @param  object  $target  대상 객체
     * @param  string  $method  메서드명
     * @param  mixed  ...$args  인수
     * @return mixed 반환값
     */
    protected function callProtected(object $target, string $method, mixed ...$args): mixed
    {
        $reflection = new ReflectionMethod($target, $method);
        $reflection->setAccessible(true);

        return $reflection->invoke($target, ...$args);
    }

    // ================================================================
    // 진행 방식 전환 게이트 (D1 · §3-3)
    // ================================================================

    /**
     * 선교환 기능 설정이 꺼져 있으면 선교환으로 바꿀 수 없다 — 기본은 꺼짐이다. 이미 선교환인 건은 설정을 꺼도 되돌릴 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=advance, stage=review
     *
     * @effects advance_mode_requires_the_setting_but_revert_does_not
     */
    public function test_advance_requires_the_setting_but_reverting_does_not(): void
    {
        $exchange = $this->requestedExchange();

        $this->assertFalse($this->service()->advanceExchangeEnabled(), '선교환 기능은 기본 꺼짐이어야 합니다.');
        $this->assertSame('claim_advance_exchange_disabled', $this->service()->exchangeModeBlockReason($exchange, ExchangeModeEnum::ADVANCE));
        $this->assertClaimException('claim_advance_exchange_disabled', fn () => $this->service()->setExchangeMode($exchange, ExchangeModeEnum::ADVANCE));

        $this->enableAdvance();
        $this->assertTrue($this->service()->advanceExchangeEnabled());
        $operatorId = (int) $this->createAdminUser()->id;
        $advanced = $this->service()->setExchangeMode($exchange->fresh(), ExchangeModeEnum::ADVANCE, $operatorId);
        $this->assertSame(ExchangeModeEnum::ADVANCE, $advanced->mode());
        $this->assertSame($operatorId, (int) $advanced->exchange_mode_set_by);
        $this->assertNotNull($advanced->exchange_mode_set_at);

        // 같은 방식으로의 전환은 거절한다.
        $this->assertClaimException('claim_exchange_mode_not_changeable', fn () => $this->service()->setExchangeMode($advanced, ExchangeModeEnum::ADVANCE));

        $this->enableAdvance(false);
        $reverted = $this->service()->setExchangeMode($advanced->fresh(), ExchangeModeEnum::STANDARD);
        $this->assertSame(ExchangeModeEnum::STANDARD, $reverted->mode());
    }

    /**
     * 재발송 송장이 있는 행이 있으면 방식을 바꿀 수 없고, 선교환의 회수 송장은 일반 교환으로 되돌리는 것만 막는다.
     * 일반 교환에 회수 송장이 있어도 발송 전이면 선교환으로 바꿀 수 있다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=standard, stage=approved
     *
     * @effects advance_mode_is_locked_by_row_tracking
     */
    public function test_row_tracking_locks_the_mode_change(): void
    {
        $carrier = $this->testClaimCarrier();

        // 선교환 + 재발송 송장 → 되돌릴 수 없다(교환품이 이미 나갔다).
        $redelivery = $this->approvedExchange();
        $this->service()->writeItemTracking($redelivery, ClaimTrackingDirectionEnum::REDELIVERY, [$this->firstItem($redelivery)->id], $carrier->id, 'RD-LOCK');
        $this->assertClaimException('claim_exchange_mode_not_changeable', fn () => $this->service()->setExchangeMode($redelivery->fresh(), ExchangeModeEnum::STANDARD));

        // 선교환 + 회수 송장 → 일반 교환으로 되돌릴 수 없다(전용 키).
        $collection = $this->approvedExchange();
        $this->service()->writeItemTracking($collection, ClaimTrackingDirectionEnum::COLLECTION, [$this->firstItem($collection)->id], $carrier->id, 'CL-LOCK');
        $this->assertSame('claim_exchange_mode_locked_by_collection', $this->service()->exchangeModeBlockReason($collection->fresh(), ExchangeModeEnum::STANDARD));
        $this->assertClaimException('claim_exchange_mode_locked_by_collection', fn () => $this->service()->setExchangeMode($collection->fresh(), ExchangeModeEnum::STANDARD));

        // 일반 교환 + 회수 송장(승인 상태) → 선교환으로 바꿀 수 있다.
        $standard = $this->approvedExchange(1, advance: false);
        $this->enableAdvance();
        $this->service()->writeItemTracking($standard, ClaimTrackingDirectionEnum::COLLECTION, [$this->firstItem($standard)->id], $carrier->id, 'CL-OK');
        $this->assertSame(ExchangeModeEnum::ADVANCE, $this->service()->setExchangeMode($standard->fresh(), ExchangeModeEnum::ADVANCE)->mode());
    }

    /**
     * 헤더가 심사·승인 단계를 지났거나, 살아 있는 상품 하나라도 승인보다 앞서 있으면 방식을 바꿀 수 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=standard, stage=collection
     *
     * @effects advance_mode_is_limited_to_review_stages
     */
    public function test_mode_change_is_limited_to_review_stages(): void
    {
        $this->enableAdvance();

        $headerMoved = $this->approvedExchange(1, advance: false);
        $this->service()->transition($headerMoved, ExchangeStatusEnum::COLLECTION_READY);
        $this->assertClaimException('claim_exchange_mode_not_changeable', fn () => $this->service()->setExchangeMode($headerMoved->fresh(), ExchangeModeEnum::ADVANCE));

        $itemMoved = $this->approvedExchange(2, advance: false);
        $this->service()->transitionOption($itemMoved, [['id' => $this->firstItem($itemMoved)->id, 'quantity' => 1]], ExchangeStatusEnum::COLLECTION_READY);
        $this->assertSame(ExchangeStatusEnum::APPROVED, $itemMoved->fresh()->exchange_status, '상품이 갈렸는데 헤더가 움직였습니다.');
        $this->assertSame('claim_exchange_mode_not_changeable', $this->service()->exchangeModeBlockReason($itemMoved->fresh(), ExchangeModeEnum::ADVANCE));
        $this->assertClaimException('claim_exchange_mode_not_changeable', fn () => $this->service()->setExchangeMode($itemMoved->fresh(), ExchangeModeEnum::ADVANCE));
    }

    /**
     * 선교환에서 되돌리면 반송 기한과 지연 알림 기록을 비우고, 전환 앞뒤로 모드 훅이 난다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=advance, stage=approved
     *
     * @effects advance_mode_revert_clears_the_due_date_and_fires_mode_hooks
     */
    public function test_reverting_clears_the_due_date_and_fires_mode_hooks(): void
    {
        $seen = [];
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.before_mode_change', function ($exchange, $from, $to) use (&$seen) {
            $seen[] = "before:{$from}>{$to}";
        });
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.after_mode_change', function ($exchange, $from, $to) use (&$seen) {
            $seen[] = "after:{$from}>{$to}";
        });

        $exchange = $this->approvedExchange();
        $exchange->forceFill(['collection_due_at' => now()->addDays(3), 'collection_overdue_notified_at' => now()])->save();

        $reverted = $this->service()->setExchangeMode($exchange->fresh(), ExchangeModeEnum::STANDARD);

        $this->assertNull($reverted->collection_due_at);
        $this->assertNull($reverted->collection_overdue_notified_at);
        $this->assertSame([
            'before:standard>advance',
            'after:standard>advance',
            'before:advance>standard',
            'after:advance>standard',
        ], $seen);
    }

    // ================================================================
    // 선교환 전이표 · 헤더 경로
    // ================================================================

    /**
     * 선교환은 승인에서 곧장 발송 단계로 가고, 회수 단계로는 가지 않는다. 발송 단계 진입은 교환품 새 주문상품을 만든다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=advance, stage=approved
     *
     * @effects advance_transition_table_skips_the_collection_stages
     */
    public function test_advance_goes_from_approved_to_redelivery_but_not_to_collection(): void
    {
        $exchange = $this->approvedExchange();
        $carrier = $this->testClaimCarrier();

        $this->assertClaimException('claim_transition_not_allowed', fn () => $this->service()->transition($exchange, ExchangeStatusEnum::COLLECTING, [
            'carrier_id' => $carrier->id,
            'tracking_number' => 'NOPE-1',
        ]));
        $this->assertClaimException('claim_transition_not_allowed', fn () => $this->service()->transition($exchange->fresh(), ExchangeStatusEnum::COLLECTED));

        $moved = $this->service()->transition($exchange->fresh(), ExchangeStatusEnum::REDELIVERING, [
            'carrier_id' => $carrier->id,
            'tracking_number' => 'ADV-1',
        ]);

        $this->assertSame(ExchangeStatusEnum::REDELIVERING, $moved->exchange_status);
        $this->assertNotNull($this->firstItem($moved)->new_order_option_id, '선교환 발송이 교환품 새 주문상품을 만들지 않았습니다.');
    }

    /**
     * 헤더 경로 탐색(BFS)은 진행 방식의 전이표를 따른다 — 선교환은 승인에서 곧장 발송 단계, 회수 단계는 닿지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=advance, stage=review
     *
     * @effects advance_header_path_follows_the_mode_table
     */
    public function test_header_path_follows_the_mode_table(): void
    {
        $service = $this->service();

        $this->assertSame(
            [ExchangeStatusEnum::APPROVED, ExchangeStatusEnum::REDELIVERING],
            $this->callProtected($service, 'headerPathTo', ExchangeStatusEnum::REQUESTED, ExchangeStatusEnum::REDELIVERING, ExchangeModeEnum::ADVANCE),
        );
        $this->assertSame(
            [ExchangeStatusEnum::APPROVED, ExchangeStatusEnum::COLLECTED, ExchangeStatusEnum::REDELIVERING],
            $this->callProtected($service, 'headerPathTo', ExchangeStatusEnum::REQUESTED, ExchangeStatusEnum::REDELIVERING, ExchangeModeEnum::STANDARD),
        );
        $this->assertSame([], $this->callProtected($service, 'headerPathTo', ExchangeStatusEnum::APPROVED, ExchangeStatusEnum::COLLECTING, ExchangeModeEnum::ADVANCE));
    }

    // ================================================================
    // 완료 조건 · 미회수 완료 (§3-4 · D3)
    // ================================================================

    /**
     * 선교환 완료는 두 축을 모두 본다 — 교환품이 발송중·발송도착이고 헌 물건이 오는 중이거나 도착했어야 한다.
     * 헌 물건이 출발하지 않은 발송 행은 미회수 완료 대상이다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=advance, stage=redelivery
     *
     * @effects advance_completion_needs_both_axes
     */
    public function test_completion_needs_both_the_redelivery_and_the_collection_axis(): void
    {
        $item = $this->firstItem($this->redeliveringAdvance());
        $service = $this->service();
        $mode = ExchangeModeEnum::ADVANCE;

        $pending = clone $item;
        $this->assertSame(ClaimItemCollectionStateEnum::PENDING, ClaimItemCollectionState::for($pending, $mode));
        $this->assertFalse($service->isCompletableItem($pending, $mode));
        $this->assertTrue($service->isUnreturnedEligibleItem($pending, $mode));

        $inTransit = (clone $item)->forceFill(['collection_carrier_id' => $this->testClaimCarrier()->id, 'collection_tracking_number' => 'CL-1']);
        $this->assertTrue($service->isCompletableItem($inTransit, $mode));
        $this->assertFalse($service->isUnreturnedEligibleItem($inTransit, $mode));

        $collected = (clone $item)->forceFill(['option_status' => ExchangeStatusEnum::REDELIVERED, 'collected_at' => now()]);
        $this->assertTrue($service->isCompletableItem($collected, $mode));

        $notShipped = (clone $item)->forceFill(['option_status' => ExchangeStatusEnum::APPROVED, 'collected_at' => now()]);
        $this->assertFalse($service->isCompletableItem($notShipped, $mode));

        // 일반 교환은 발송 축만 본다.
        $this->assertTrue($service->isCompletableItem(clone $item, ExchangeModeEnum::STANDARD));
        $this->assertFalse($service->isUnreturnedEligibleItem(clone $item, ExchangeModeEnum::STANDARD));
    }

    /**
     * 미회수 완료는 사유가 필수이고, 헌 물건이 오지 않았으므로 재고 복구를 골라도 재고를 늘리지 않는다.
     * 회수 중인 행에 미회수를 붙이면 거절하고, 헌 물건이 출발하지 않은 행은 미회수 없이 완료할 수 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=advance, stage=redelivery
     *
     * @effects advance_unreturned_completion_requires_a_reason_and_keeps_stock
     */
    public function test_unreturned_completion_requires_a_reason_and_keeps_the_stock(): void
    {
        $exchange = $this->redeliveringAdvance();
        $item = $this->firstItem($exchange);
        $service = $this->service();
        $productOptionId = (int) OrderOption::find($item->order_option_id)->product_option_id;
        $stockBefore = (int) ProductOption::find($productOptionId)->stock_quantity;

        $this->assertClaimException('claim_completion_items_invalid', fn () => $service->completeExchange(
            $exchange, [$item->id], new ClaimCompletionOptions([$item->id => true], false),
        ));
        $this->assertClaimException('claim_unreturned_reason_required', fn () => $service->completeExchange(
            $exchange->fresh(), [$item->id], new ClaimCompletionOptions([$item->id => true], false, [$item->id => '   ']),
        ));

        $completed = $service->completeExchange(
            $exchange->fresh(), [$item->id], new ClaimCompletionOptions([$item->id => true], false, [$item->id => '고객 연락 두절']),
        );

        $row = OrderExchangeOption::find($item->id);
        $this->assertSame(ExchangeStatusEnum::COMPLETED, $row->option_status);
        $this->assertNotNull($row->unreturned_at);
        $this->assertSame('고객 연락 두절', $row->unreturned_reason);
        $this->assertFalse((bool) $row->is_stock_restored);
        $this->assertSame($stockBefore, (int) ProductOption::find($productOptionId)->stock_quantity, '미회수 행의 재고가 복구됐습니다.');
        $this->assertSame(ExchangeStatusEnum::COMPLETED, $completed->exchange_status);
        $this->assertSame(ClaimItemCollectionStateEnum::UNRETURNED, ClaimItemCollectionState::for($row, ExchangeModeEnum::ADVANCE));

        // 회수 중인 행에는 미회수를 붙일 수 없다.
        $inTransit = $this->redeliveringAdvance();
        $inTransitItem = $this->firstItem($inTransit);
        $service->writeItemTracking($inTransit, ClaimTrackingDirectionEnum::COLLECTION, [$inTransitItem->id], $this->testClaimCarrier()->id, 'CL-IT');
        $this->assertClaimException('claim_unreturned_not_eligible', fn () => $service->completeExchange(
            $inTransit->fresh(), [$inTransitItem->id], new ClaimCompletionOptions([], false, [$inTransitItem->id => '사유']),
        ));
    }

    /**
     * 완료 필터 훅은 미회수 지시도 조정할 수 있다 — 필터가 붙인 미회수가 그대로 반영된다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=advance, stage=redelivery
     *
     * @effects advance_complete_filter_can_adjust_unreturned
     */
    public function test_complete_filter_can_adjust_unreturned(): void
    {
        $exchange = $this->redeliveringAdvance();
        $itemId = (int) $this->firstItem($exchange)->id;

        HookManager::addFilter('sirsoft-ecommerce.claim.exchange.filter_complete_options', function (ClaimCompletionOptions $options) use ($itemId) {
            return new ClaimCompletionOptions($options->restoreStockByItem, $options->createRefundRequest, [$itemId => '확장이 붙인 미회수']);
        });

        $this->service()->completeExchange($exchange, [$itemId], new ClaimCompletionOptions([], false));

        $row = OrderExchangeOption::find($itemId);
        $this->assertSame(ExchangeStatusEnum::COMPLETED, $row->option_status);
        $this->assertSame('확장이 붙인 미회수', $row->unreturned_reason);
    }

    // ================================================================
    // 발송된 행 쪼개기 (D11 · R10)
    // ================================================================

    /**
     * 교환품을 이미 보낸 3개 행에 회수 송장을 수량 1 로 적으면 행이 1·2 로 쪼개지고 후속 주문옵션도 1·2 로
     * 쪼개진다 — 잔여 교환 행은 새 후속 옵션을 가리키고 금액 합은 보존된다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=advance, stage=redelivery
     *
     * @effects advance_split_of_a_redelivered_row_splits_the_successor_option
     */
    public function test_splitting_a_shipped_row_splits_the_successor_option(): void
    {
        $exchange = $this->redeliveringAdvance(3);
        $item = $this->firstItem($exchange);
        $successor = OrderOption::find($item->new_order_option_id);
        $this->assertSame(3, (int) $successor->quantity);
        $subtotalBefore = (float) $successor->subtotal_price;
        $paidBefore = (float) $successor->subtotal_paid_amount;

        $result = $this->service()->writeItemTracking($exchange, ClaimTrackingDirectionEnum::COLLECTION, [['id' => $item->id, 'quantity' => 1]], $this->testClaimCarrier()->id, 'CL-SPLIT');
        $remainderId = $result['split'][0]['remainder_id'];

        $target = OrderExchangeOption::find($item->id);
        $remainder = OrderExchangeOption::find($remainderId);
        $this->assertSame(1, (int) $target->exchange_quantity);
        $this->assertSame(2, (int) $remainder->exchange_quantity);
        $this->assertSame('CL-SPLIT', $target->collection_tracking_number);
        $this->assertNull($remainder->collection_tracking_number);
        $this->assertSame('ADV-RD-1', $remainder->redelivery_tracking_number, '잔여 행이 재발송 송장을 잃었습니다.');

        $this->assertSame((int) $successor->id, (int) $target->new_order_option_id);
        $this->assertNotNull($remainder->new_order_option_id);
        $this->assertNotSame((int) $target->new_order_option_id, (int) $remainder->new_order_option_id);

        $targetSuccessor = OrderOption::find($target->new_order_option_id);
        $remainderSuccessor = OrderOption::find($remainder->new_order_option_id);
        $this->assertSame(1, (int) $targetSuccessor->quantity);
        $this->assertSame(2, (int) $remainderSuccessor->quantity);
        $this->assertEqualsWithDelta($subtotalBefore, (float) $targetSuccessor->subtotal_price + (float) $remainderSuccessor->subtotal_price, 0.001);
        $this->assertEqualsWithDelta($paidBefore, (float) $targetSuccessor->subtotal_paid_amount + (float) $remainderSuccessor->subtotal_paid_amount, 0.001);

        $this->assertNotNull(OrderShipping::where('order_option_id', $remainderSuccessor->id)->first(), '쪼갠 후속 주문옵션에 배송 행이 없습니다.');
    }

    // ================================================================
    // 반송 기한 · 회수 지연 · 배치 (§3-5 · D2)
    // ================================================================

    /**
     * 반송 기한은 설정 일수를 쓰고, 도착 기록이 없으면 재발송 송장 등록 시각, 있으면 가장 이른 도착 시각이 기준이다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=advance, stage=redelivery
     *
     * @effects advance_due_date_uses_the_setting_and_the_earliest_base
     */
    public function test_due_date_uses_the_configured_days_and_the_earliest_base(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_collection_due_days', 10);
        Carbon::setTestNow(Carbon::parse('2026-09-01 12:00:00'));

        $exchange = $this->approvedExchange();
        $itemId = (int) $this->firstItem($exchange)->id;

        // 승인 상태에서 재발송 송장만 먼저 적어도 기한이 선다(발송 시각 기준).
        $this->service()->writeItemTracking($exchange, ClaimTrackingDirectionEnum::REDELIVERY, [$itemId], $this->testClaimCarrier()->id, 'RD-DUE');
        $this->assertSame('2026-09-11 12:00:00', $exchange->fresh()->collection_due_at?->format('Y-m-d H:i:s'));

        Carbon::setTestNow(Carbon::parse('2026-09-04 08:00:00'));
        $this->service()->transitionOption($exchange->fresh(['exchangeOptions']), [$itemId], ExchangeStatusEnum::REDELIVERED);
        $this->assertSame('2026-09-14 08:00:00', $exchange->fresh()->collection_due_at?->format('Y-m-d H:i:s'));
    }

    /**
     * 회수 지연은 선교환 · 기한 경과 · 헌 물건이 출발하지 않은 행이 있을 때다 — 회수 송장이 적히면 지연이 풀린다.
     * 목록 필터 `collection_overdue` 도 같은 조건으로 거른다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=advance, stage=redelivery
     *
     * @effects advance_overdue_needs_a_pending_row_after_the_due_date
     */
    public function test_overdue_needs_a_pending_row_after_the_due_date(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $overdue = $this->redeliveringAdvance();
        $onTime = $this->redeliveringAdvance();
        $this->service()->writeItemTracking($onTime, ClaimTrackingDirectionEnum::COLLECTION, [$this->firstItem($onTime)->id], $this->testClaimCarrier()->id, 'CL-ON');

        $this->assertFalse(ClaimTrackingGroups::overdueOf($overdue->fresh('exchangeOptions'))['is_overdue'], '기한 전에 지연으로 판정됐습니다.');

        Carbon::setTestNow(Carbon::parse('2026-09-20 09:00:00'));

        $this->assertSame(['is_overdue' => true, 'days' => 5], ClaimTrackingGroups::overdueOf($overdue->fresh('exchangeOptions')));
        $this->assertFalse(ClaimTrackingGroups::overdueOf($onTime->fresh('exchangeOptions'))['is_overdue']);

        $admin = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);
        $rows = $this->actingAs($admin)->getJson('/api/modules/sirsoft-ecommerce/admin/claims?type=exchange&collection_overdue=1')
            ->assertOk()
            ->json('data.data');

        $this->assertSame([$overdue->exchange_number], array_column($rows, 'claim_number'));
    }

    /**
     * 배치는 지연된 선교환에 한 번만 알린다 — 관리자 알림 훅이 나가고 알림 시각이 남는다. 기한이 다시 미래가 되면
     * 알림 기록이 비워진다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=advance, stage=redelivery
     *
     * @effects advance_overdue_batch_notifies_once_and_resets_on_recalculation
     */
    public function test_overdue_batch_notifies_once_and_resets_when_the_due_moves(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $exchange = $this->redeliveringAdvance();

        $notified = [];
        HookManager::addAction(ClaimNotificationHooks::forStatus('exchange', 'collection_overdue'), function ($claim, array $data) use (&$notified) {
            $notified[] = $data;
        });

        Carbon::setTestNow(Carbon::parse('2026-09-20 09:00:00'));

        $first = $this->service()->notifyOverdueCollections();
        $this->assertSame(['scanned' => 1, 'notified' => 1, 'skipped' => 0], $first);
        $this->assertNotNull($exchange->fresh()->collection_overdue_notified_at);
        $this->assertCount(1, $notified);
        $this->assertSame('5', $notified[0]['overdue_days']);
        $this->assertNotSame('', $notified[0]['unreturned_item_lines']);

        $second = $this->service()->notifyOverdueCollections();
        $this->assertSame(0, $second['notified']);
        $this->assertCount(1, $notified, '회수 지연 알림이 두 번 나갔습니다.');

        // 기한이 다시 미래가 되면(기한 일수 90일) 알림 기록을 비운다 — 다음 지연 때 다시 한 번 알린다.
        app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_collection_due_days', 90);
        $this->callProtected($this->service(), 'refreshCollectionDue', $exchange->fresh(['exchangeOptions']));

        $fresh = $exchange->fresh();
        $this->assertTrue($fresh->collection_due_at->isFuture());
        $this->assertNull($fresh->collection_overdue_notified_at);
    }

    /**
     * 배치 명령은 시험 실행이면 아무것도 적지 않고, 실제 실행이면 알림 시각을 남긴다.
     *
     * @return void 반환값 없음
     *
     * @scenario exchange_mode=advance, stage=redelivery
     *
     * @effects advance_overdue_command_supports_dry_run
     */
    public function test_overdue_command_supports_dry_run(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-09-01 10:00:00'));
        $exchange = $this->redeliveringAdvance();
        Carbon::setTestNow(Carbon::parse('2026-09-20 09:00:00'));

        $this->artisan('sirsoft-ecommerce:claims:notify-overdue-collections', ['--dry-run' => true])->assertExitCode(0);
        $this->assertNull($exchange->fresh()->collection_overdue_notified_at, '시험 실행이 알림 기록을 남겼습니다.');

        $this->artisan('sirsoft-ecommerce:claims:notify-overdue-collections')->assertExitCode(0);
        $this->assertNotNull($exchange->fresh()->collection_overdue_notified_at);
    }
}
