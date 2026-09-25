<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderOption;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;
use ReflectionMethod;

/**
 * 클레임 핵심 서비스 결함 수정 검증 — 완료·수정 동시성과 훅 발화 시점 (#599 validate P2-A)
 *
 * 한 요청 안에서 동시성을 재현할 수 없으므로 **낡은 모델**(다른 요청이 이미 바꾼 뒤의 메모리 값)을
 * 넘겨 같은 판정을 부른다 — 잠금 안에서 다시 읽지 않는 경로는 그 낡은 값을 믿고 두 번 실행한다.
 *
 * 검증 축:
 * - 완료가 잠금 안에서 대상을 다시 골라 재고·완료 훅이 1회다(#3).
 * - 분할 행 부분 완료의 재고 플래그가 실제로 전이된 행에 적힌다(#4).
 * - 소수 청구가 0원 자동 승인을 통과하지 않는다(#5), 자동 승인 실패가 접수를 실패로 만들지 않는다(#20).
 * - 신청 수정이 잠금 안에서 수정 가능 여부를 다시 판정한다(#8), 계좌 3필드 짝을 본다(#9).
 * - 도메인 훅이 커밋 뒤에 발화하고(#7), 헤더 동기화 쓰기가 상품 전이와 같은 잠금 트랜잭션 안이다(#19).
 */
class ValidateP2ClaimConcurrencyTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimFlowOrders;

    protected OrderReturnService $returns;

    /**
     * 서비스와 테스트 통화·사유를 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();

        $this->returns = app(OrderReturnService::class);
    }

    /**
     * 테스트 통화 설정을 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 판매자 귀책(청구 0원) 반품을 접수합니다.
     *
     * @param  int  $quantity  주문·반품 수량
     * @return OrderReturn 접수된 반품 신청서
     */
    protected function freeReturn(int $quantity = 1): OrderReturn
    {
        $order = $this->deliveredClaimOrder(quantity: $quantity);

        return $this->returns->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => $quantity]],
            'reason_code' => 'defective_product',
        ]);
    }

    /**
     * 반품을 회수도착까지 전이합니다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @return OrderReturn 회수도착 반품
     */
    protected function collected(OrderReturn $return): OrderReturn
    {
        foreach ([ReturnStatusEnum::APPROVED, ReturnStatusEnum::COLLECTION_READY, ReturnStatusEnum::COLLECTING, ReturnStatusEnum::COLLECTED] as $step) {
            $return = $this->returns->transition($return, $step, $this->claimTrackingFor($step));
        }

        return $return->fresh(['returnOptions']);
    }

    /**
     * 주문 첫 상품옵션의 현재 재고를 읽습니다.
     *
     * @param  Order  $order  주문
     * @return int 재고 수량
     */
    protected function stockOf(Order $order): int
    {
        $order->loadMissing('options');

        return (int) ProductOption::query()->findOrFail($order->options->first()->product_option_id)->stock_quantity;
    }

    /**
     * 신청 상품 전부의 재고를 복구하는 완료 옵션을 만듭니다.
     *
     * @param  iterable<int, mixed>  $items  신청 상품 행
     * @return ClaimCompletionOptions 완료 옵션
     */
    protected function restoreAll(iterable $items): ClaimCompletionOptions
    {
        $map = [];

        foreach ($items as $item) {
            $map[(int) $item->id] = true;
        }

        return new ClaimCompletionOptions(restoreStockByItem: $map, createRefundRequest: false);
    }

    // ── #3 완료의 잠금 안 재판정 ──

    /**
     * 이미 완료된 반품을 낡은 모델로 다시 완료하면 재고·완료 훅이 두 번 돌지 않는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=completion_recheck
     *
     * @effects stale_return_completion_does_not_restore_stock_twice
     */
    public function test_낡은_모델로_반품을_다시_완료해도_재고와_훅은_1회다(): void
    {
        $return = $this->collected($this->freeReturn());
        $stale = $return->fresh(['returnOptions']);
        $options = $this->restoreAll($return->returnOptions);

        $completedHooks = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.return.completed', function () use (&$completedHooks) {
            $completedHooks++;
        });

        $before = $this->stockOf($return->order);
        $this->returns->completeReturn($return, [], $options);

        $this->assertClaimException('claim_completion_items_invalid', fn () => $this->returns->completeReturn($stale, [], $options));

        $this->assertSame($before + 1, $this->stockOf($return->order));
        $this->assertSame(1, $completedHooks);
    }

    /**
     * 이미 완료된 교환을 낡은 모델로 다시 완료하면 원 옵션 재고가 두 번 복구되지 않는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=completion_recheck
     *
     * @effects stale_exchange_completion_does_not_restore_stock_twice
     */
    public function test_낡은_모델로_교환을_다시_완료해도_재고는_1회다(): void
    {
        [$order, $exchange] = $this->redeliveredLowerPricedExchange();
        $stale = $exchange->fresh(['exchangeOptions']);
        $options = $this->restoreAll($exchange->exchangeOptions);
        $service = app(OrderExchangeService::class);

        $completedHooks = 0;
        HookManager::addAction('sirsoft-ecommerce.claim.exchange.completed', function () use (&$completedHooks) {
            $completedHooks++;
        });

        $before = $this->stockOf($order);
        $service->completeExchange($exchange, [], $options);

        $this->assertClaimException('claim_completion_items_invalid', fn () => $service->completeExchange($stale, [], $options));

        $this->assertSame($before + 1, $this->stockOf($order));
        $this->assertSame(1, $completedHooks);
    }

    // ── #4 분할 행 부분 완료 ──

    /**
     * 분할 행을 부분 완료하면 재고 플래그가 반품완료로 전이된 행에 적히는지 검증합니다.
     *
     * 예전에는 완료 수량만큼 새 행이 반품완료로 갈라져 나가는데 `is_stock_deducted=false` 는
     * 아직 코스에 남은 원 행에 적혔다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=split_completion_target
     *
     * @effects partial_completion_marks_stock_on_the_transitioned_order_option
     */
    public function test_분할_행_부분_완료는_전이된_행에_재고_플래그를_적는다(): void
    {
        $return = $this->returns->transition($this->freeReturn(3), ReturnStatusEnum::APPROVED);
        $itemId = (int) $return->returnOptions->first()->id;

        $this->returns->transitionOption($return->fresh(['returnOptions']), [['id' => $itemId, 'quantity' => 2]], ReturnStatusEnum::COLLECTION_READY);
        $this->returns->transitionOption($return->fresh(['returnOptions']), [$itemId], ReturnStatusEnum::COLLECTING, $this->claimTrackingFor(ReturnStatusEnum::COLLECTING));
        $this->returns->transitionOption($return->fresh(['returnOptions']), [$itemId], ReturnStatusEnum::COLLECTED);

        $fresh = $return->fresh(['returnOptions']);
        $this->returns->completeReturn($fresh, [$itemId], new ClaimCompletionOptions([$itemId => true], false));

        $rows = OrderOption::query()->where('order_id', $return->order_id)->get();
        $returned = $rows->firstWhere('option_status', OrderStatusEnum::RETURNED);
        $inCourse = $rows->firstWhere('option_status', OrderStatusEnum::RETURN_IN_PROGRESS);

        $this->assertNotNull($returned);
        $this->assertNotNull($inCourse);
        $this->assertSame(2, (int) $returned->quantity);
        $this->assertFalse((bool) $returned->is_stock_deducted, '재고를 복구한 반품완료 행에 플래그가 내려가야 한다');
        $this->assertTrue((bool) $inCourse->is_stock_deducted, '아직 코스에 남은 행은 재고를 점유하고 있다');
    }

    // ── #5 · #20 0원 자동 승인 ──

    /**
     * 소수 통화의 0.5 청구가 0원 자동 승인을 통과하지 않는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=zero_charge_auto_approve
     *
     * @effects fractional_charge_is_not_treated_as_zero_for_auto_approval
     */
    public function test_소수_청구는_0원_자동_승인_대상이_아니다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.auto_approve_zero_charge', true);

        $method = new ReflectionMethod(OrderReturnService::class, 'shouldAutoApproveZeroCharge');
        $claim = new OrderReturn(['total_charge_amount' => 0.5]);

        $this->assertFalse($method->invoke($this->returns, $claim));
    }

    /**
     * 0원 자동 승인이 실패해도 접수는 성공으로 끝나는지 검증합니다. (교환과 동형)
     *
     * @return void 반환값 없음
     *
     * @scenario defect=zero_charge_auto_approve
     *
     * @effects zero_charge_auto_approval_failure_keeps_the_intake_successful
     */
    public function test_0원_자동_승인이_실패해도_반품_접수는_성공한다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.auto_approve_zero_charge', true);

        // 접수 직후 다른 요청이 신청서를 철회한 상황 — 자동 승인은 전이 게이트에서 거절된다.
        HookManager::addAction('sirsoft-ecommerce.claim.return.requested', function ($claim) {
            app(OrderReturnService::class)->withdraw($claim->fresh());
        });

        $return = $this->freeReturn();

        $this->assertSame(ReturnStatusEnum::WITHDRAWN, $return->return_status);
    }

    // ── #8 · #9 신청 수정 ──

    /**
     * 이미 승인된 반품을 낡은 모델로 수정하면 잠금 안에서 다시 판정해 거절하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=update_request_recheck
     *
     * @effects stale_return_update_is_refused_after_approval
     */
    public function test_승인된_반품은_낡은_모델로_수정할_수_없다(): void
    {
        $stale = $this->freeReturn();
        $this->returns->transition($stale->fresh(), ReturnStatusEnum::APPROVED);

        $this->assertClaimException('claim_not_editable', fn () => $this->returns->updateRequest($stale, ['reason_detail' => '바꿈']));
        $this->assertNotSame('바꿈', $stale->fresh()->reason_detail);
    }

    /**
     * 이미 승인된 교환을 낡은 모델로 수정하면 잠금 안에서 다시 판정해 거절하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=update_request_recheck
     *
     * @effects stale_exchange_update_is_refused_after_approval
     */
    public function test_승인된_교환은_낡은_모델로_수정할_수_없다(): void
    {
        $order = $this->deliveredClaimOrder();
        $service = app(OrderExchangeService::class);
        $stale = $service->requestExchange($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'defective_product',
        ]);
        $service->transition($stale->fresh(), ExchangeStatusEnum::APPROVED);

        $this->assertClaimException('claim_not_editable', fn () => $service->updateRequest($stale, ['reason_detail' => '바꿈']));
        $this->assertNotSame('바꿈', $stale->fresh()->reason_detail);
    }

    /**
     * 이미 승인된 환불신청서를 낡은 모델로 수정하면 잠금 안에서 다시 판정해 거절하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=update_request_recheck
     *
     * @effects stale_refund_update_is_refused_after_approval
     */
    public function test_승인된_환불신청서는_낡은_모델로_수정할_수_없다(): void
    {
        $stale = $this->requestedRefund();
        $service = app(OrderRefundRequestService::class);
        $service->transition($stale->fresh(), RefundStatusEnum::APPROVED);

        $this->assertClaimException('claim_not_editable', fn () => $service->updateRequest($stale, ['reason_detail' => '바꿈']));
        $this->assertNotSame('바꿈', $stale->fresh()->reason_detail);
    }

    /**
     * 계좌 3필드가 짝을 이루지 않으면 500 이 아니라 도메인 예외로 거절하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=refund_bank_pair
     *
     * @effects incomplete_refund_bank_is_a_domain_error
     */
    public function test_짝이_맞지_않는_환불_계좌는_도메인_예외다(): void
    {
        $refund = $this->requestedRefund();
        $return = $this->freeReturn();
        $bank = ['refund_bank' => ['bank_code' => '004']];

        $this->assertClaimException('claim_refund_bank_incomplete', fn () => app(OrderRefundRequestService::class)->updateRequest($refund, $bank));
        $this->assertClaimException('claim_refund_bank_incomplete', fn () => $this->returns->updateRequest($return, $bank));
    }

    /**
     * 상품준비중 주문에 심사 대기 환불신청서를 접수합니다.
     *
     * @return OrderRefund 접수된 환불신청서
     */
    protected function requestedRefund(): OrderRefund
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder(29000, 1, $policy, ['currency_snapshot' => $this->krwCurrencySnapshot()]);
        $order = $this->moveOrderTo($this->attachPolicyShippings($order, $policy), OrderStatusEnum::PREPARING);

        $refund = app(OrderRefundRequestService::class)->request($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'requested_by' => $order->user_id,
        ])['refund'];

        $this->assertSame(RefundStatusEnum::REQUESTED, $refund->refund_status);

        return $refund;
    }

    // ── #7 · #19 훅 발화 시점과 헤더 동기화 잠금 ──

    /**
     * 신청서 단위 전이가 끌고 간 상품 축 훅이 트랜잭션 안이 아니라 커밋 뒤에 발화하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=hook_after_commit
     *
     * @effects item_hook_of_a_header_transition_fires_after_commit
     */
    public function test_헤더_전이의_상품_축_훅은_커밋_뒤에_발화한다(): void
    {
        $return = $this->freeReturn();
        $baseline = DB::transactionLevel();
        $levels = [];

        HookManager::addAction('sirsoft-ecommerce.claim.return.option_status_changed', function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });

        $this->returns->transition($return, ReturnStatusEnum::APPROVED);

        $this->assertNotEmpty($levels);
        $this->assertSame([$baseline], array_values(array_unique($levels)));
    }

    /**
     * 환불신청서 접수 훅이 접수 트랜잭션 안이 아니라 커밋 뒤에 발화하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=hook_after_commit
     *
     * @effects refund_request_hook_fires_after_commit
     */
    public function test_환불신청서_접수_훅은_커밋_뒤에_발화한다(): void
    {
        $baseline = DB::transactionLevel();
        $levels = [];

        HookManager::addAction('sirsoft-ecommerce.claim.refund.requested', function () use (&$levels) {
            $levels[] = DB::transactionLevel();
        });

        $this->requestedRefund();

        $this->assertNotEmpty($levels);
        $this->assertSame([$baseline], array_values(array_unique($levels)));
    }

    /**
     * 상품 단위 전이가 헤더를 따라 옮길 때 그 쓰기가 상품 전이와 같은 트랜잭션 안인지 검증합니다.
     *
     * 예전에는 상품 전이를 커밋한 뒤 잠금 없이 헤더를 옮겨, 그 사이에 들어온 다른 요청과
     * 헤더 전이가 겹칠 수 있었다.
     *
     * @return void 반환값 없음
     *
     * @scenario defect=header_sync_lock
     *
     * @effects header_sync_writes_inside_the_item_transition_transaction
     */
    public function test_헤더_동기화는_상품_전이와_같은_트랜잭션_안에서_쓴다(): void
    {
        $return = $this->returns->transition($this->freeReturn(), ReturnStatusEnum::APPROVED);
        $itemId = (int) $return->returnOptions->first()->id;
        $baseline = DB::transactionLevel();
        $levels = [];

        Event::listen('eloquent.updating: '.OrderReturn::class, function (OrderReturn $model) use (&$levels) {
            if ($model->isDirty('return_status')) {
                $levels[] = DB::transactionLevel();
            }
        });

        $this->returns->transitionOption($return->fresh(['returnOptions']), [$itemId], ReturnStatusEnum::COLLECTION_READY);

        $this->assertSame(ReturnStatusEnum::COLLECTION_READY, $return->fresh()->return_status);
        $this->assertNotEmpty($levels);
        $this->assertGreaterThan($baseline, min($levels));
    }
}
