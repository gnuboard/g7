<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Extension\HookManager;
use App\Models\User;
use ArrayObject;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTrackingDirectionEnum;
use Modules\Sirsoft\Ecommerce\Enums\ClaimTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeModeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ExchangeStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderClaimException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Models\ProductOption;
use Modules\Sirsoft\Ecommerce\Models\ShippingCarrier;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 클레임 before·filter 훅 신설 검증 (#599 validate 01-#6)
 *
 * 클레임 도메인에는 after 훅(`claim.{type}.requested`·`status_changed`·`completed` 등)만 있어 확장이
 * 신청·수정·전이·완료·철회를 **막거나 입력을 바꿀** 자리가 없었다(선례 `order.before_cancel`).
 * 아래 테스트는 수정 전 코드에서 모두 실패한다 — 새 훅 이름이 발화되지 않아 막는 리스너가 불리지
 * 않고(쓰기가 그대로 일어남), 필터가 바꾼 값이 반영되지 않으며, 막는 데 쓰는
 * `OrderClaimException::blockedByExtension()` 도 없었다.
 *
 * 검증 축:
 * - before 훅은 트랜잭션·잠금 전 동기 호출이다 — 예외를 던지면 쓰기가 없다.
 * - filter 훅은 허용 키(대상·사유·재발송지 / 메모·반려 사유 / 완료 옵션)만 반영하고, 신청자·처리자·
 *   접수 창구·환불 계좌 같은 보안 값은 원래대로 되돌린다.
 * - 상품 단위·일괄·송장 전이·상품이 끌고 간 헤더 동기화도 같은 before 훅을 지난다.
 */
class ClaimBeforeFilterHooksTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    /**
     * 테스트 확장이 쓰는 거절 메시지 키
     */
    private const BLOCK_KEY = 'test-claim-hook::messages.blocked';

    protected OrderExchangeService $exchanges;

    protected OrderReturnService $returns;

    protected OrderRefundRequestService $refunds;

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

        $this->exchanges = app(OrderExchangeService::class);
        $this->returns = app(OrderReturnService::class);
        $this->refunds = app(OrderRefundRequestService::class);
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

    // ── 픽스처 ──

    /**
     * 확장이 동작을 막는 리스너를 붙입니다.
     *
     * @param  string  $hook  훅 이름
     * @param  callable|null  $when  막을 조건 (인자를 받아 bool, 없으면 언제나)
     * @return ArrayObject 리스너가 마지막으로 받은 인자 (호출 뒤 채워진다)
     */
    protected function blockOn(string $hook, ?callable $when = null): ArrayObject
    {
        $captured = new ArrayObject;

        HookManager::addAction($hook, function (...$args) use ($captured, $when) {
            $captured->exchangeArray($args);

            if ($when === null || $when(...$args)) {
                throw OrderClaimException::blockedByExtension(self::BLOCK_KEY);
            }
        });

        return $captured;
    }

    /**
     * 호출이 확장의 거절로 끝나는지 단언합니다.
     *
     * @param  callable  $callback  막혀야 하는 호출
     * @return void 반환값 없음
     */
    protected function assertBlocked(callable $callback): void
    {
        try {
            $callback();
        } catch (OrderClaimException $e) {
            $this->assertSame(self::BLOCK_KEY, $e->getMessageKey());

            return;
        }

        $this->fail('확장의 before 훅이 동작을 막지 않았습니다.');
    }

    /**
     * 신청 페이로드를 만듭니다. (주문 첫 상품 1개)
     *
     * @param  Order  $order  주문
     * @param  string  $reasonCode  사유 코드
     * @param  array  $extra  추가 키
     * @return array 신청 페이로드
     */
    protected function payloadFor(Order $order, string $reasonCode = 'defective_product', array $extra = []): array
    {
        return [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => $reasonCode,
            'requested_by' => $order->user_id,
        ] + $extra;
    }

    /**
     * 판매자 귀책(청구 0원) 반품을 접수합니다.
     *
     * @return OrderReturn 접수된 반품 신청서
     */
    protected function freeReturn(): OrderReturn
    {
        $order = $this->deliveredClaimOrder();

        return $this->returns->requestReturn($order, $this->payloadFor($order));
    }

    /**
     * 판매자 귀책(청구 0원) 동일 옵션 교환을 접수합니다.
     *
     * @return OrderExchange 접수된 교환 신청서
     */
    protected function freeExchange(): OrderExchange
    {
        $order = $this->deliveredClaimOrder();

        return $this->exchanges->requestExchange($order, $this->payloadFor($order));
    }

    /**
     * 상품준비중 주문을 만듭니다. (심사 대기 환불신청 분기)
     *
     * @return Order 상품준비중 주문
     */
    protected function preparingOrder(): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder(29000, 1, $policy, ['currency_snapshot' => $this->krwCurrencySnapshot()]);

        return $this->moveOrderTo($this->attachPolicyShippings($order, $policy), OrderStatusEnum::PREPARING);
    }

    /**
     * 심사 대기 환불신청서를 접수합니다.
     *
     * @return OrderRefund 접수된 환불신청서
     */
    protected function requestedRefund(): OrderRefund
    {
        $order = $this->preparingOrder();

        return $this->refunds->request($order, $this->payloadFor($order, 'changed_mind'))['refund'];
    }

    /**
     * 반품을 지정 단계까지 전이합니다.
     *
     * @param  OrderReturn  $return  반품 신청서
     * @param  array<int, ReturnStatusEnum>  $steps  거칠 단계
     * @return OrderReturn 전이된 반품
     */
    protected function advanceReturn(OrderReturn $return, array $steps): OrderReturn
    {
        foreach ($steps as $step) {
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

    // ── 신청 ──

    /**
     * 교환 신청의 before 훅이 트랜잭션 전 동기로 발화하고, 막으면 신청서가 생기지 않는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=request, hook_kind=before
     *
     * @effects exchange_request_before_hook_blocks_the_intake_before_any_write
     */
    public function test_교환_신청_before_훅이_막으면_신청서가_생기지_않는다(): void
    {
        $order = $this->deliveredClaimOrder();
        $baseline = DB::transactionLevel();
        $level = null;

        HookManager::addAction('sirsoft-ecommerce.claim.exchange.before_request', function () use (&$level) {
            $level = DB::transactionLevel();
        }, 5);
        $captured = $this->blockOn('sirsoft-ecommerce.claim.exchange.before_request');

        $this->assertBlocked(fn () => $this->exchanges->requestExchange($order, $this->payloadFor($order)));

        $this->assertSame($baseline, $level, 'before 훅은 서비스 트랜잭션 밖에서 발화해야 합니다.');
        $this->assertSame((int) $order->id, (int) $captured[0]->id);
        $this->assertSame('defective_product', $captured[1]['reason_code']);
        $this->assertSame(0, OrderExchange::query()->where('order_id', $order->id)->count());
        $this->assertSame(OrderStatusEnum::DELIVERED, $order->fresh(['options'])->options->first()->option_status);
    }

    /**
     * 반품 신청 필터가 사유를 바꾸면 반영되고, 신청자는 바꾸지 못하는지 검증합니다.
     *
     * 사유가 판매자 귀책으로 바뀌면 청구액도 그 사유로 다시 산정된다(0원) — 필터 뒤 검증·산정을 그대로 지난다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=request, hook_kind=filter
     *
     * @effects return_request_filter_changes_the_reason_but_not_the_requester
     */
    public function test_반품_신청_필터는_사유를_바꾸고_신청자는_바꾸지_못한다(): void
    {
        $order = $this->deliveredClaimOrder();
        $requester = (int) $order->user_id;

        HookManager::addFilter('sirsoft-ecommerce.claim.return.filter_request_data', function (array $payload, Order $hookOrder) {
            $payload['reason_code'] = 'defective_product';
            $payload['reason_detail'] = 'filtered-by-extension';
            $payload['requested_by'] = null;

            return $payload;
        });

        $return = $this->returns->requestReturn($order, $this->payloadFor($order, 'changed_mind', [
            'charge_method' => 'deduct_from_refund',
        ]));

        $this->assertSame('defective_product', $return->reason_code);
        $this->assertSame('filtered-by-extension', $return->reason_detail);
        $this->assertSame($requester, (int) $return->requested_by);
        $this->assertEquals(0, (float) $return->total_charge_amount);
    }

    /**
     * 필터가 배열이 아닌 값을 돌려주면 원래 입력으로 접수하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=request, hook_kind=filter
     *
     * @effects non_array_filter_result_keeps_the_original_payload
     */
    public function test_필터가_배열이_아닌_값을_돌려주면_원래_입력으로_접수한다(): void
    {
        $order = $this->deliveredClaimOrder();

        HookManager::addFilter('sirsoft-ecommerce.claim.exchange.filter_request_data', fn () => null);

        $exchange = $this->exchanges->requestExchange($order, $this->payloadFor($order, 'defective_product', ['reason_detail' => '원문']));

        $this->assertSame('원문', $exchange->reason_detail);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->exchange_status);
    }

    /**
     * 취소·환불 접수의 before 훅이 막으면 환불신청서가 생기지 않는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=request, hook_kind=before
     *
     * @effects refund_request_before_hook_blocks_the_intake_before_any_write
     */
    public function test_환불_접수_before_훅이_막으면_환불신청서가_생기지_않는다(): void
    {
        $order = $this->preparingOrder();

        $captured = $this->blockOn('sirsoft-ecommerce.claim.refund.before_request');

        $this->assertBlocked(fn () => $this->refunds->request($order, $this->payloadFor($order, 'changed_mind')));

        $this->assertSame((int) $order->id, (int) $captured[0]->id);
        $this->assertSame(0, OrderRefund::query()->where('order_id', $order->id)->count());
    }

    /**
     * 취소·환불 접수 필터가 상세 사유를 바꾸면 반영되고, 접수 창구 축은 바꾸지 못하는지 검증합니다.
     *
     * 고객 창구 접수를 운영자 축(`refund_source_type=admin`)으로 바꾸면 고객이 철회·수정할 수 없는
     * 신청서가 되고 자격 판정도 운영자 목록으로 넓어진다 — 필터가 그 값을 넣어도 버린다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=request, hook_kind=filter
     *
     * @effects refund_request_filter_changes_the_detail_but_not_the_intake_channel
     */
    public function test_환불_접수_필터는_상세_사유를_바꾸고_접수_창구는_바꾸지_못한다(): void
    {
        $order = $this->preparingOrder();

        HookManager::addFilter('sirsoft-ecommerce.claim.refund.filter_request_data', function (array $payload) {
            $payload['reason_detail'] = 'filtered-by-extension';
            $payload['refund_source_type'] = RefundSourceTypeEnum::ADMIN;
            $payload['branch'] = OrderRefundRequestService::BRANCH_AUTO;

            return $payload;
        });

        $refund = $this->refunds->request($order, $this->payloadFor($order, 'changed_mind'))['refund'];

        $this->assertInstanceOf(OrderRefund::class, $refund);
        $this->assertSame('filtered-by-extension', $refund->reason_detail);
        $this->assertSame(RefundSourceTypeEnum::DIRECT, $refund->refund_source_type);
        $this->assertSame(RefundStatusEnum::REQUESTED, $refund->refund_status);
    }

    // ── 신청 수정 ──

    /**
     * 교환 신청 수정의 before 훅이 막으면 수정이 반영되지 않는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=update_request, hook_kind=before
     *
     * @effects exchange_update_before_hook_blocks_the_edit
     */
    public function test_교환_수정_before_훅이_막으면_수정되지_않는다(): void
    {
        $exchange = $this->freeExchange();

        $captured = $this->blockOn('sirsoft-ecommerce.claim.exchange.before_update_request');

        $this->assertBlocked(fn () => $this->exchanges->updateRequest($exchange, ['reason_detail' => '바꿈']));

        $this->assertSame((int) $exchange->id, (int) $captured[0]->id);
        $this->assertSame('바꿈', $captured[1]['reason_detail']);
        $this->assertNotSame('바꿈', $exchange->fresh()->reason_detail);
    }

    /**
     * 반품 신청 수정 필터가 상세 사유를 바꾸면 반영되고, 환불 계좌는 넣지 못하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=update_request, hook_kind=filter
     *
     * @effects return_update_filter_changes_the_detail_but_not_the_refund_account
     */
    public function test_반품_수정_필터는_상세_사유를_바꾸고_환불_계좌는_넣지_못한다(): void
    {
        $return = $this->freeReturn();

        HookManager::addFilter('sirsoft-ecommerce.claim.return.filter_update_request_data', function (array $payload) {
            $payload['reason_detail'] = 'filtered-by-extension';
            $payload['refund_bank'] = ['bank_code' => '004', 'account_number' => '9999999999', 'holder' => '다른사람'];

            return $payload;
        });

        $updated = $this->returns->updateRequest($return, ['reason_detail' => '원문']);

        $this->assertSame('filtered-by-extension', $updated->reason_detail);
        $this->assertNull($updated->fresh()->refund_bank_account);
    }

    /**
     * 환불 신청 수정 필터가 상세 사유를 바꾸면 반영되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=update_request, hook_kind=filter
     *
     * @effects refund_update_filter_changes_the_detail
     */
    public function test_환불_수정_필터는_상세_사유를_바꾼다(): void
    {
        $refund = $this->requestedRefund();

        HookManager::addFilter('sirsoft-ecommerce.claim.refund.filter_update_request_data', function (array $payload, OrderRefund $hookRefund) {
            $payload['reason_detail'] = 'filtered-'.$hookRefund->id;

            return $payload;
        });

        $updated = $this->refunds->updateRequest($refund, ['reason_detail' => '원문']);

        $this->assertSame('filtered-'.$refund->id, $updated->fresh()->reason_detail);
    }

    // ── 상태 전이 (헤더·일괄·송장) ──

    /**
     * 반품 헤더 전이의 before 훅이 막으면 상태가 그대로이고, 훅이 전/후 상태를 받는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=status_change, hook_kind=before
     *
     * @effects return_status_before_hook_blocks_the_transition
     */
    public function test_반품_전이_before_훅이_막으면_상태가_그대로다(): void
    {
        $return = $this->freeReturn();

        $captured = $this->blockOn('sirsoft-ecommerce.claim.return.before_status_change');

        $this->assertBlocked(fn () => $this->returns->transition($return, ReturnStatusEnum::APPROVED, ['admin_memo' => 'm']));

        $this->assertSame('requested', $captured[1]);
        $this->assertSame('approved', $captured[2]);
        $this->assertSame(['admin_memo' => 'm'], $captured[3]);
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh(['returnOptions'])->returnOptions->first()->option_status);
    }

    /**
     * 교환 전이 필터가 반려 사유를 바꾸면 반영되고, 처리자는 바꾸지 못하는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=status_change, hook_kind=filter
     *
     * @effects exchange_status_filter_changes_the_reject_reason_but_not_the_processor
     */
    public function test_교환_전이_필터는_반려_사유를_바꾸고_처리자는_바꾸지_못한다(): void
    {
        $exchange = $this->freeExchange();
        $admin = User::factory()->create();

        HookManager::addFilter('sirsoft-ecommerce.claim.exchange.filter_status_change_data', function (array $context, OrderExchange $hookExchange, ?string $from, string $to) {
            $context['reject_reason'] = "filtered:{$from}->{$to}";
            $context['processed_by'] = null;

            return $context;
        });

        $rejected = $this->exchanges->transition($exchange, ExchangeStatusEnum::REJECTED, [
            'reject_reason' => '원문',
            'processed_by' => $admin->id,
        ]);

        $this->assertSame(ExchangeStatusEnum::REJECTED, $rejected->exchange_status);
        $this->assertSame('filtered:requested->rejected', $rejected->fresh()->reject_reason);
        $this->assertSame((int) $admin->id, (int) $rejected->fresh()->processed_by);
    }

    /**
     * 환불 전이 필터가 반려 사유를 바꾸면 반영되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=status_change, hook_kind=filter
     *
     * @effects refund_status_filter_changes_the_reject_reason
     */
    public function test_환불_전이_필터는_반려_사유를_바꾼다(): void
    {
        $refund = $this->requestedRefund();
        $seen = null;

        HookManager::addAction('sirsoft-ecommerce.claim.refund.before_status_change', function ($hookRefund, ?string $from, string $to) use (&$seen) {
            $seen = "{$from}->{$to}";
        });
        HookManager::addFilter('sirsoft-ecommerce.claim.refund.filter_status_change_data', function (array $context) {
            $context['reject_reason'] = 'filtered-by-extension';

            return $context;
        });

        $this->refunds->transition($refund, RefundStatusEnum::REJECTED, ['reject_reason' => '원문']);

        $this->assertSame('requested->rejected', $seen);
        $this->assertSame('filtered-by-extension', $refund->fresh()->reject_reason);
        $this->assertSame(RefundStatusEnum::REJECTED, $refund->fresh()->refund_status);
    }

    /**
     * 일괄 전이에서 before 훅이 한 건을 막으면 그 건만 건너뛰고 나머지는 전이되는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=bulk_status_change, hook_kind=before
     *
     * @effects bulk_transition_skips_only_the_claim_an_extension_blocked
     */
    public function test_일괄_전이는_before_훅이_막은_건만_건너뛴다(): void
    {
        $blocked = $this->freeReturn();
        $passed = $this->freeReturn();

        $this->blockOn(
            'sirsoft-ecommerce.claim.return.before_status_change',
            fn ($claim) => (int) $claim->id === (int) $blocked->id,
        );

        $result = app(AdminClaimService::class)->bulkTransition(
            ClaimTypeEnum::RETURN,
            [$blocked->id, $passed->id],
            ReturnStatusEnum::APPROVED->value,
        );

        $this->assertSame([(int) $passed->id], array_map('intval', $result['succeeded']));
        $this->assertSame([(int) $blocked->id], array_map('intval', array_column($result['skipped'], 'id')));
        $this->assertSame(ReturnStatusEnum::REQUESTED, $blocked->fresh()->return_status);
        $this->assertSame(ReturnStatusEnum::APPROVED, $passed->fresh()->return_status);
    }

    /**
     * 송장을 실은 회수중 전이도 before 훅을 지나고, 막으면 송장도 기록되지 않는지 검증합니다.
     *
     * 송장 저장은 상태를 옮기지 않는다(#599 후속 운영 결정) — 회수중은 전이가 송장을 실어 들어간다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=status_change, hook_kind=before
     *
     * @effects tracking_driven_transition_passes_the_before_hook
     */
    public function test_송장_등록이_끄는_전이도_before_훅이_막는다(): void
    {
        $return = $this->advanceReturn($this->freeReturn(), [ReturnStatusEnum::APPROVED, ReturnStatusEnum::COLLECTION_READY]);
        $carrier = ShippingCarrier::first() ?? ShippingCarrier::create([
            'code' => 'test-carrier',
            'name' => ['ko' => '테스트택배', 'en' => 'Test Carrier'],
            'is_active' => true,
        ]);

        $captured = $this->blockOn('sirsoft-ecommerce.claim.return.before_status_change');

        $this->assertBlocked(fn () => $this->returns->transition($return, ReturnStatusEnum::COLLECTING, ['carrier_id' => $carrier->id, 'tracking_number' => 'T-1234']));

        $this->assertSame('collection_ready', $captured[1]);
        $this->assertSame('collecting', $captured[2]);
        $this->assertSame(ReturnStatusEnum::COLLECTION_READY, $return->fresh()->return_status);
        $this->assertNull($return->fresh()->collection_tracking_number);
    }

    // ── 철회 ──

    /**
     * 교환·환불 철회의 before 훅이 막으면 철회되지 않는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=withdraw, hook_kind=before
     *
     * @effects withdraw_before_hook_blocks_the_withdrawal
     */
    public function test_철회_before_훅이_막으면_철회되지_않는다(): void
    {
        $exchange = $this->freeExchange();
        $refund = $this->requestedRefund();

        $exchangeArgs = $this->blockOn('sirsoft-ecommerce.claim.exchange.before_withdraw');
        $refundArgs = $this->blockOn('sirsoft-ecommerce.claim.refund.before_withdraw');

        $this->assertBlocked(fn () => $this->exchanges->withdraw($exchange));
        $this->assertBlocked(fn () => $this->refunds->withdraw($refund));

        $this->assertSame((int) $exchange->id, (int) $exchangeArgs[0]->id);
        $this->assertSame((int) $refund->id, (int) $refundArgs[0]->id);
        $this->assertSame(ExchangeStatusEnum::REQUESTED, $exchange->fresh()->exchange_status);
        $this->assertSame(RefundStatusEnum::REQUESTED, $refund->fresh()->refund_status);
    }

    // ── 상품 단위 전이 · 헤더 동기화 ──

    /**
     * 상품 단위 전이의 before 훅이 막으면 어떤 상품 행도 옮겨지지 않는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=option_status_change, hook_kind=before
     *
     * @effects option_status_before_hook_blocks_every_item_write
     */
    public function test_상품_단위_전이_before_훅이_막으면_상품이_옮겨지지_않는다(): void
    {
        $refund = $this->requestedRefund();
        $itemId = (int) $refund->refundOptions()->first()->id;

        $captured = $this->blockOn('sirsoft-ecommerce.claim.refund.before_option_status_change');

        $this->assertBlocked(fn () => $this->refunds->transitionOption($refund, [$itemId], RefundStatusEnum::APPROVED));

        $this->assertSame([['id' => $itemId, 'quantity' => null]], $captured[1]);
        $this->assertSame('approved', $captured[2]);
        $this->assertSame(RefundStatusEnum::REQUESTED->value, $refund->refundOptions()->first()->option_status->value);
        $this->assertSame(RefundStatusEnum::REQUESTED, $refund->fresh()->refund_status);
    }

    /**
     * 상품이 끌고 가는 헤더 동기화도 before 훅을 지나고, 막으면 상품 전이까지 함께 되돌아가는지 검증합니다.
     *
     * 헤더만 막고 상품 전이를 남기면 상품은 승인인데 헤더는 신청인 채로 갈린다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=header_sync, hook_kind=before
     *
     * @effects header_sync_before_hook_rolls_back_the_item_transition
     */
    public function test_헤더_동기화의_before_훅이_막으면_상품_전이도_되돌아간다(): void
    {
        $return = $this->freeReturn();
        $itemId = (int) $return->returnOptions->first()->id;

        $captured = $this->blockOn(
            'sirsoft-ecommerce.claim.return.before_status_change',
            fn ($claim, $from, $to, array $context) => ($context['source'] ?? null) === 'option_sync',
        );

        $this->assertBlocked(fn () => $this->returns->transitionOption($return, [$itemId], ReturnStatusEnum::APPROVED));

        $this->assertSame('option_sync', $captured[3]['source']);
        $this->assertSame('approved', $captured[2]);
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh()->return_status);
        $this->assertSame(ReturnStatusEnum::REQUESTED, $return->fresh(['returnOptions'])->returnOptions->first()->option_status);
    }

    // ── 완료 ──

    /**
     * 반품 완료의 before 훅이 막으면 완료·재고 복구가 일어나지 않는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=complete, hook_kind=before
     *
     * @effects return_complete_before_hook_blocks_completion_and_restock
     */
    public function test_반품_완료_before_훅이_막으면_완료와_재고_복구가_없다(): void
    {
        $return = $this->advanceReturn($this->freeReturn(), [
            ReturnStatusEnum::APPROVED,
            ReturnStatusEnum::COLLECTION_READY,
            ReturnStatusEnum::COLLECTING,
            ReturnStatusEnum::COLLECTED,
        ]);
        $itemId = (int) $return->returnOptions->first()->id;
        $before = $this->stockOf($return->order);

        $captured = $this->blockOn('sirsoft-ecommerce.claim.return.before_complete');

        $this->assertBlocked(fn () => $this->returns->completeReturn($return, [], new ClaimCompletionOptions([$itemId => true], false)));

        $this->assertInstanceOf(ClaimCompletionOptions::class, $captured[2]);
        $this->assertSame(ReturnStatusEnum::COLLECTED, $return->fresh()->return_status);
        $this->assertSame($before, $this->stockOf($return->order));
    }

    /**
     * 교환 완료 필터가 재고 복구를 끄면 원 옵션 재고가 복구되지 않는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=complete, hook_kind=filter
     *
     * @effects exchange_complete_filter_changes_the_restock_choice
     */
    public function test_교환_완료_필터가_재고_복구를_끄면_복구되지_않는다(): void
    {
        [$order, $exchange] = $this->redeliveredLowerPricedExchange();
        $itemId = (int) $exchange->exchangeOptions->first()->id;
        $before = $this->stockOf($order);

        HookManager::addFilter('sirsoft-ecommerce.claim.exchange.filter_complete_options', function (ClaimCompletionOptions $options) {
            return new ClaimCompletionOptions([], $options->createRefundRequest);
        });

        $completed = $this->exchanges->completeExchange($exchange, [], new ClaimCompletionOptions([$itemId => true], false));

        $this->assertSame(ExchangeStatusEnum::COMPLETED, $completed->exchange_status);
        $this->assertSame($before, $this->stockOf($order));
        $this->assertFalse((bool) $completed->fresh()->is_stock_restored);
    }

    /**
     * 환불 실행(완료)의 before 훅이 막으면 승인 상태에 머무르는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=complete, hook_kind=before
     *
     * @effects refund_complete_before_hook_blocks_the_execution
     */
    public function test_환불_완료_before_훅이_막으면_실행되지_않는다(): void
    {
        $refund = $this->refunds->transition($this->requestedRefund(), RefundStatusEnum::APPROVED);

        $captured = $this->blockOn('sirsoft-ecommerce.claim.refund.before_complete');

        $this->assertBlocked(fn () => $this->refunds->executeRefund($refund, [], null));

        $this->assertSame((int) $refund->id, (int) $captured[0]->id);
        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
    }

    /**
     * 상품 칸 반려 사유도 신청서 칸과 같은 필터(`filter_status_change_data`)를 거치는지 검증합니다. (#599 후속 운영 결정)
     *
     * 같은 「반려 사유」 인데 어느 칸에서 적었느냐에 따라 확장이 손댈 수 있는지가 갈리면 안 된다 — 금칙어
     * 필터 같은 확장이 상품 칸 경로로 우회된다. 받아들이는 키도 헤더 경로와 같다(처리자는 되돌린다).
     *
     * @return void 반환값 없음
     *
     * @scenario operation=option_status_change, hook_kind=filter
     *
     * @effects option_rejection_reason_passes_the_status_filter
     */
    public function test_상품_단위_반려_사유도_상태_필터를_거친다(): void
    {
        $admin = User::factory()->create();
        $seen = [];

        foreach (['exchange', 'return', 'refund'] as $type) {
            HookManager::addFilter("sirsoft-ecommerce.claim.{$type}.filter_status_change_data", function (array $context, $claim, ?string $from, string $to) use (&$seen, $type) {
                $seen[$type] = "{$from}->{$to}";
                $context['reject_reason'] = "filtered:{$type}";
                $context['processed_by'] = null;

                return $context;
            });
        }

        $exchange = $this->freeExchange();
        $this->exchanges->transitionOption($exchange, [(int) $exchange->exchangeOptions()->first()->id], ExchangeStatusEnum::REJECTED, [
            'reject_reason' => '원문', 'processed_by' => $admin->id,
        ]);

        $return = $this->freeReturn();
        $this->returns->transitionOption($return, [(int) $return->returnOptions()->first()->id], ReturnStatusEnum::REJECTED, [
            'reject_reason' => '원문', 'processed_by' => $admin->id,
        ]);

        $refund = $this->requestedRefund();
        $this->refunds->transitionOption($refund, [(int) $refund->refundOptions()->first()->id], RefundStatusEnum::REJECTED, [
            'reject_reason' => '원문', 'processed_by' => $admin->id,
        ]);

        $this->assertSame('filtered:exchange', $exchange->fresh()->reject_reason);
        $this->assertSame('filtered:return', $return->fresh()->reject_reason);
        $this->assertSame('filtered:refund', $refund->fresh()->reject_reason);
        $this->assertSame(['exchange' => 'requested->rejected', 'return' => 'requested->rejected', 'refund' => 'requested->rejected'], $seen);
        // 처리자는 필터가 바꿀 수 있는 키가 아니다 — 헤더 경로와 같은 규칙.
        $this->assertSame((int) $admin->id, (int) $return->fresh()->processed_by);
    }

    // ── 송장만 저장 · 진행 방식 (#599 후속 §3-7) ──

    /**
     * 「송장만 저장」 의 before 훅이 막으면 어떤 행에도 송장이 쓰이지 않는다 — 훅은 방향·대상 행·입력을 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=tracking_update, hook_kind=before
     *
     * @effects tracking_update_before_hook_blocks_the_write
     */
    public function test_송장만_저장_before_훅이_막으면_송장이_쓰이지_않는다(): void
    {
        $return = $this->advanceReturn($this->freeReturn(), [ReturnStatusEnum::APPROVED]);
        $itemId = (int) $return->returnOptions->first()->id;
        $carrier = $this->testClaimCarrier();

        $captured = $this->blockOn('sirsoft-ecommerce.claim.return.before_tracking_update');

        $this->assertBlocked(fn () => $this->returns->writeItemTracking($return, ClaimTrackingDirectionEnum::COLLECTION, [['id' => $itemId, 'quantity' => 1]], $carrier->id, 'BLOCK-1'));

        $this->assertSame('collection', $captured[1]);
        $this->assertSame([['id' => $itemId, 'quantity' => 1]], $captured[2]);
        $this->assertSame(['carrier_id' => $carrier->id, 'tracking_number' => 'BLOCK-1'], $captured[3]);
        $this->assertNull($return->returnOptions->first()->fresh()->collection_tracking_number);
    }

    /**
     * 송장 필터는 택배사·운송장만 바꿀 수 있다 — 다른 키(처리자·상태)를 실어도 되돌린다. 바뀐 값도 반쪽 송장
     * 규칙을 그대로 지난다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=tracking_update, hook_kind=filter
     *
     * @effects tracking_update_filter_changes_only_the_tracking_pair
     */
    public function test_송장_필터는_택배사와_운송장만_바꾼다(): void
    {
        $exchange = $this->exchanges->transition($this->freeExchange(), ExchangeStatusEnum::APPROVED);
        $itemId = (int) $exchange->fresh('exchangeOptions')->exchangeOptions->first()->id;
        $carrier = $this->testClaimCarrier();
        $seen = [];

        HookManager::addFilter('sirsoft-ecommerce.claim.exchange.filter_tracking_update_data', function (array $data, $claim, string $direction) use (&$seen) {
            $seen[] = $direction;
            $data['tracking_number'] = 'FILTERED-'.$data['tracking_number'];
            $data['processed_by'] = 999999;
            $data['option_status'] = 'completed';

            return $data;
        });

        $this->exchanges->writeItemTracking($exchange, ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, 'RAW-1', null);

        $row = $exchange->fresh('exchangeOptions')->exchangeOptions->first();
        $this->assertSame(['collection'], $seen);
        $this->assertSame('FILTERED-RAW-1', $row->collection_tracking_number);
        $this->assertSame(ExchangeStatusEnum::APPROVED, $row->option_status);
        $this->assertNotSame(999999, (int) $row->processed_by);

        // 필터가 반쪽 송장을 만들면 같은 규칙으로 거절한다.
        HookManager::addFilter('sirsoft-ecommerce.claim.exchange.filter_tracking_update_data', fn (array $data) => ['carrier_id' => null] + $data, 20);

        try {
            $this->exchanges->writeItemTracking($exchange->fresh(), ClaimTrackingDirectionEnum::COLLECTION, [$itemId], $carrier->id, 'RAW-2');
            $this->fail('필터가 만든 반쪽 송장이 저장됐습니다.');
        } catch (OrderClaimException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.claim_tracking_incomplete', $e->getMessageKey());
        }

        $this->assertSame('FILTERED-RAW-1', $exchange->fresh('exchangeOptions')->exchangeOptions->first()->collection_tracking_number);
    }

    /**
     * 진행 방식 전환의 before 훅이 막으면 방식이 바뀌지 않는다 — 훅은 바꾸기 전·후 방식을 받는다.
     *
     * @return void 반환값 없음
     *
     * @scenario operation=mode_change, hook_kind=before
     *
     * @effects exchange_mode_before_hook_blocks_the_change
     */
    public function test_진행_방식_before_훅이_막으면_방식이_그대로다(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.advance_exchange_enabled', true);
        $exchange = $this->freeExchange();

        $captured = $this->blockOn('sirsoft-ecommerce.claim.exchange.before_mode_change');

        $this->assertBlocked(fn () => $this->exchanges->setExchangeMode($exchange, ExchangeModeEnum::ADVANCE));

        $this->assertSame('standard', $captured[1]);
        $this->assertSame('advance', $captured[2]);
        $this->assertSame(ExchangeModeEnum::STANDARD, $exchange->fresh()->mode());
        $this->assertNull($exchange->fresh()->exchange_mode_set_at);
    }
}
