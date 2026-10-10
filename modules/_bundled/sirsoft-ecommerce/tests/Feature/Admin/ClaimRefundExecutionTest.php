<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Modules\Sirsoft\Ecommerce\DTO\ClaimCompletionOptions;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageBalance;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderExchange;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Models\OrderRefundOption;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Repositories\Contracts\MileageTransactionRepositoryInterface;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderExchangeService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\StockService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\ReadsReturnRefundLink;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 환불 실행 API Feature 테스트 (S6b)
 *
 * 검증 축:
 * - 실행 — 직접 환불신청서(옵션 분할·취소 + 누적) / 반품 발 환불(옵션 불변 + 누적) / 비-PG 완료 확정
 * - D18 — 실행 본문 금액 반영 (PG 훅이 수정값을 받는지)
 * - D19 — 상한 초과 422 (실행 직전 재계산 가드) + `cancellable-amount` 3축 응답·자기 제외·교차 접근 404
 * - 멱등 — 재실행 409 (더블클릭) + 사용 마일리지 환급 order_refund_id 멱등
 * - 게이트 — 취소 발 환불 422 / 미승인 422 / 권한 403
 * - 취소 미리보기 비대칭 해소 — 클레임 진행 중 상품의 estimate-refund 422
 */
class ClaimRefundExecutionTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;
    use ReadsReturnRefundLink;

    protected User $admin;

    /**
     * 통화 설정·클레임 사유를 시드하고 조회·상태 변경·금액 수정·환불 실행·주문 수정 권한을 가진 관리자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.claims.read',
            'sirsoft-ecommerce.claims.update-status',
            'sirsoft-ecommerce.claims.update-amounts',
            'sirsoft-ecommerce.claims.execute-refund',
            'sirsoft-ecommerce.orders.update',
            // 취소 창 예상금액 조회는 접수(claims.create) 축이다 (7.1.0).
            'sirsoft-ecommerce.claims.create',
        ]);
    }

    /**
     * 테스트용 통화 설정을 지워 다음 테스트로 새지 않게 합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 관리자 이커머스 API 의 기본 경로를 돌려줍니다.
     *
     * @return string 관리자 API 기본 경로
     */
    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/admin';
    }

    /**
     * PG 환불 훅을 성공으로 고정하고 호출 금액을 기록합니다.
     *
     * @param  array|null  $calls  호출 기록 (참조)
     * @return void 반환값 없음
     */
    protected function fakePgRefund(?array &$calls = null): void
    {
        $calls = [];

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function ($default, $order, $payment, $amount) use (&$calls) {
            $calls[] = ['amount' => $amount];

            return ['success' => true, 'transaction_id' => 'PGTXN-'.count($calls), 'error_code' => null, 'error_message' => null];
        });
    }

    /**
     * 승인된 직접 환불신청서를 만듭니다. (상품준비중 주문 — D2 환불신청 분기 상태)
     *
     * @param  float  $amount  PG 환불액
     * @param  float  $points  마일리지 환불액
     * @param  array  $orderOverrides  주문 팩토리에 덮어쓸 속성 (마일리지 사용액 등)
     * @return array{0: Order, 1: OrderRefund} 주문 + 환불신청서
     */
    protected function approvedDirectRefund(float $amount = 10000, float $points = 0, array $orderOverrides = []): array
    {
        $order = $this->deliveredClaimOrder(orderOverrides: $orderOverrides);
        $order = $this->moveOrderTo($order, OrderStatusEnum::PREPARING);

        $refund = $this->makeDirectRefund($order, $order->options->first(), 1, RefundStatusEnum::APPROVED);
        $refund->update([
            'refund_amount' => $amount,
            'refund_points_amount' => $points,
            'refund_method' => $points > 0 && $amount <= 0 ? RefundMethodEnum::POINTS : RefundMethodEnum::PG,
            'reason_code' => 'changed_mind',
        ]);

        return [$order, $refund->fresh(['refundOptions'])];
    }

    /**
     * 완료 처리로 환불신청서가 접수된 반품을 만들고 승인까지 진행합니다.
     *
     * @return array{0: Order, 1: OrderReturn, 2: OrderRefund} 주문 + 반품 + 승인된 환불신청서
     */
    protected function approvedReturnRefund(): array
    {
        $order = $this->deliveredClaimOrder();

        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);

        $service = app(OrderReturnService::class);
        $return = $service->transition($return, ReturnStatusEnum::APPROVED);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTION_READY);
        $return = $service->transition($return, ReturnStatusEnum::COLLECTING, $this->claimTrackingFor(ReturnStatusEnum::COLLECTING));
        $return = $service->transition($return, ReturnStatusEnum::COLLECTED);

        $return = $service->completeReturn($return, [], new ClaimCompletionOptions(
            restoreStockByItem: [],
            createRefundRequest: true,
        ));

        $refund = OrderRefund::findOrFail($this->returnRefundId($return));
        $refund = app(OrderRefundRequestService::class)->transition($refund, RefundStatusEnum::APPROVED);

        return [$order, $return, $refund];
    }

    /**
     * 저가 교환을 완료해 차액 환불신청서를 접수하고 승인까지 진행합니다.
     *
     * D19 ① 축의 교환 분기(동결 차액 − 이미 접수된 몫)와 자기 제외를 재는 유일한 픽스처다.
     *
     * @return array{0: Order, 1: OrderExchange, 2: OrderRefund} 주문 + 교환 + 승인된 환불신청서
     */
    protected function approvedExchangeRefund(): array
    {
        [$order, $exchange] = $this->redeliveredLowerPricedExchange();

        $exchange = app(OrderExchangeService::class)->completeExchange(
            $exchange,
            [],
            new ClaimCompletionOptions(restoreStockByItem: [], createRefundRequest: true),
        );

        $refund = OrderRefund::query()
            ->where('refund_source_type', RefundSourceTypeEnum::EXCHANGE->value)
            ->where('refund_source_id', $exchange->id)
            ->firstOrFail();

        $refund = app(OrderRefundRequestService::class)->transition($refund, RefundStatusEnum::APPROVED);

        return [$order->fresh(['options', 'payments']), $exchange, $refund->fresh(['refundOptions'])];
    }

    // ================================================================
    // 환불 실행 — 직접 환불신청서
    // ================================================================

    /**
     * 승인된 직접 환불신청서를 실행하면 신청서·상세 행이 완료되고, 선점하던 옵션 수량이 취소로 빠진다.
     *
     * 주문은 `total_paid_amount` 를 그대로 두고 `total_refunded_amount`·결제 취소액만 쌓으며,
     * PG 환불 훅은 환불액 그대로 한 번 호출된다.
     *
     * @effects execute_completes_an_approved_direct_refund_and_cancels_the_option_quantity
     *
     * @return void 반환값 없음
     */
    public function test_execute_completes_an_approved_direct_refund_and_cancels_the_option_quantity(): void
    {
        $this->fakePgRefund($calls);
        [$order, $refund] = $this->approvedDirectRefund(10000);

        $response = $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute");

        $response->assertStatus(200);

        $refund->refresh();
        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->refund_status);
        $this->assertNotNull($refund->refunded_at);
        $this->assertNotNull($refund->pg_transaction_id);
        $this->assertSame(
            RefundStatusEnum::COMPLETED,
            $refund->refundOptions()->first()->option_status,
        );

        // 직접 환불신청 — 선점만 하던 수량이 실행 시점에 취소 상태로 빠진다.
        $option = $order->options->first()->fresh();
        $this->assertSame(OrderStatusEnum::CANCELLED, $option->option_status);
        $this->assertSame(1, (int) $option->cancelled_quantity);

        // 클레임 환불 누적 — total_paid_amount 는 불변, total_refunded_amount 만 쌓인다 (§4-3-1).
        $freshOrder = $order->fresh();
        $this->assertSame(10000.0, (float) $freshOrder->total_refunded_amount);
        $this->assertSame((float) $order->total_paid_amount, (float) $freshOrder->total_paid_amount);
        $this->assertSame(10000.0, (float) $freshOrder->payment->fresh()->cancelled_amount);

        $this->assertCount(1, $calls);
        $this->assertSame(10000.0, (float) $calls[0]['amount']);
    }

    /**
     * [환불하기] 는 저장 없이도 화면에 입력된 값으로 실행한다 (D18) — 모달이 예고한 금액과
     * 실제로 나가는 금액이 같아야 "예고 = 실행" 이 성립한다.
     *
     * @scenario claim_type=refund, cancellable=present
     *
     * @effects claim_refund_execute_uses_request_body_amounts
     *
     * @return void 반환값 없음
     */
    public function test_execute_uses_the_request_body_amounts(): void
    {
        $this->fakePgRefund($calls);
        [, $refund] = $this->approvedDirectRefund(20000);

        $response = $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute", [
                'refund_amount' => 15000,
            ]);

        $response->assertStatus(200);

        // D18 — [환불하기]는 화면에 입력된 값으로 실행한다 (저장 없이 실행 가능).
        $this->assertSame(15000.0, (float) $refund->fresh()->refund_amount);
        $this->assertSame(15000.0, (float) $calls[0]['amount']);
    }

    /**
     * 상한 초과는 서버가 막는다 — 화면의 초과 안내는 예고일 뿐 가드가 아니다. 저장과 실행
     * 사이에 다른 클레임이 선점한 금액까지 반영해 실행 직전에 다시 판정한다.
     *
     * @scenario claim_type=refund, cancellable=present
     *
     * @effects claim_refund_execute_rejects_over_the_cancellable_limit
     *
     * @return void 반환값 없음
     */
    public function test_execute_rejects_amounts_exceeding_the_cancellable_limit(): void
    {
        $this->fakePgRefund($calls);
        [, $refund] = $this->approvedDirectRefund(10000);

        $response = $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute", [
                'refund_amount' => 999999,
            ]);

        $response->assertStatus(422);
        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
        $this->assertCount(0, $calls);
    }

    /**
     * 재실행은 입력 오류가 아니라 충돌이다 — 확정된 상태를 다시 읽어 보여 주는 것이 조치다.
     *
     * @scenario claim_type=refund, cancellable=present
     *
     * @effects claim_refund_execute_twice_is_a_conflict
     *
     * @return void 반환값 없음
     */
    public function test_execute_twice_is_a_409_conflict(): void
    {
        $this->fakePgRefund();
        [, $refund] = $this->approvedDirectRefund(10000);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(409);

        // 재실행이 누적을 다시 쌓지 않는다.
        $this->assertSame(10000.0, (float) $refund->fresh()->order->total_refunded_amount);
    }

    /**
     * 구형 취소 발 환불도 운영자가 **완료 처리할 수 있다**. (#599 후속 P1)
     *
     * 예전에는 출처가 `cancel` 이면 422 로 막았다. 그런데 그 행들은 `approved` 에서 멈춰 있고
     * 완료로 옮길 API 가 저장소 어디에도 없어 **영구 정지**했다 — 운영자는 송금을 마쳐도
     * 장부를 닫을 방법이 없었다. 막는 것이 아니라 열어 주는 것이 이 결함의 해다.
     *
     * @scenario claim_type=refund, cancellable=present
     *
     * @effects claim_refund_execute_accepts_a_cancel_sourced_refund
     *
     * @return void 반환값 없음
     */
    public function test_execute_accepts_a_cancel_sourced_refund(): void
    {
        $this->fakePgRefund();
        $order = $this->deliveredClaimOrder();
        $refund = $this->makeDirectRefund($order, $order->options->first(), 1, RefundStatusEnum::APPROVED, RefundSourceTypeEnum::CANCEL);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->fresh()->refund_status);
    }

    /**
     * 승인 전(requested) 환불신청서는 실행할 수 없다(422).
     *
     * @effects execute_requires_an_approved_refund
     *
     * @return void 반환값 없음
     */
    public function test_execute_requires_an_approved_refund(): void
    {
        $order = $this->deliveredClaimOrder();
        $refund = $this->makeDirectRefund($order, $order->options->first(), 1, RefundStatusEnum::REQUESTED);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(422);
    }

    /**
     * 조회 권한만 있는 관리자는 환불을 실행할 수 없다(403).
     *
     * @effects execute_requires_the_execute_refund_permission
     *
     * @return void 반환값 없음
     */
    public function test_execute_requires_the_execute_refund_permission(): void
    {
        [, $refund] = $this->approvedDirectRefund(10000);

        $reader = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);

        $this->actingAs($reader)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(403);
    }

    /**
     * 마일리지 환불을 실행하면 `order_refund_id` 가 붙은 환급(REFUND_RESTORE) 거래가 한 건 생기고,
     * 같은 환불로 환급을 다시 시도해도 거래가 늘지 않는다(멱등).
     *
     * @effects execute_refunds_used_mileage_with_the_refund_idempotency_axis
     *
     * @return void 반환값 없음
     */
    public function test_execute_refunds_used_mileage_with_the_refund_idempotency_axis(): void
    {
        [$order, $refund] = $this->approvedDirectRefund(0, 3000, [
            'total_points_used_amount' => 5000,
            'is_mileage_deducted' => true,
        ]);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        $restored = MileageTransaction::query()->where('order_refund_id', $refund->id)->get();
        $this->assertCount(1, $restored);
        $this->assertSame(3000.0, (float) $restored->first()->amount);
        // 원장 라벨은 사유를 따라간다 — 환불 발 환급을 '주문취소 복원' 으로 기록하면
        // 마이페이지 마일리지 내역이 있지도 않은 주문취소를 가리킨다.
        $this->assertSame(
            MileageTransactionTypeEnum::REFUND_RESTORE->value,
            $restored->first()->type->value,
        );

        // order_refund_id 멱등 — 같은 환불의 환급 재시도는 lot 을 다시 발행하지 않는다.
        $again = app(UserMileageService::class)->restoreForRefund(
            $order->user_id,
            $order->id,
            $refund->id,
            3000,
            'KRW',
        );
        $this->assertNull($again);
        $this->assertSame(1, MileageTransaction::query()->where('order_refund_id', $refund->id)->count());
    }

    // ================================================================
    // 환불 실행 — 반품 발 환불신청서
    // ================================================================

    /**
     * 실행은 주문의 누적 환불에 더해진다 (§4-3-1) — 관리자 결제 정보의 환불 행이 그 값을 읽는다.
     *
     * @scenario claim_type=refund, cancellable=present
     *
     * @effects claim_refund_execute_accumulates_order_refund_totals
     *
     * @return void 반환값 없음
     */
    public function test_execute_of_a_return_sourced_refund_accumulates_without_touching_options(): void
    {
        $this->fakePgRefund($calls);
        [$order, , $refund] = $this->approvedReturnRefund();

        // 손계산 리터럴(T6) — 판매가 29,000 − 반품 편도 회수비 3,000(환불금 차감). 같은 값끼리 비교하면 공제 누락을 못 잡는다.
        $expectedAmount = 26000.0;
        $this->assertSame($expectedAmount, (float) $refund->refund_amount);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        $refund->refresh();
        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->refund_status);

        // 반품 발 환불 — 상품은 완료 시점에 이미 RETURNED 로 닫혔고, 실행은 돈만 움직인다.
        $this->assertSame(OrderStatusEnum::RETURNED, $order->options->first()->fresh()->option_status);
        $this->assertSame($expectedAmount, (float) $order->fresh()->total_refunded_amount);
        $this->assertSame($expectedAmount, (float) $calls[0]['amount']);
    }

    // ================================================================
    // 취소 가능 금액 (D19)
    // ================================================================

    /**
     * 취소 가능 금액 응답이 세 축(도메인 환불 대상·결제 잔여·선점)과 수단별 내역·통화를 함께 싣고,
     * 다른 클레임이 없으면 선점 내역이 비어 있다.
     *
     * @effects cancellable_amount_returns_the_three_axes_with_methods
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_returns_the_three_axes_with_methods(): void
    {
        $order = $this->deliveredClaimOrder();

        $response = $this->actingAs($this->admin)
            ->getJson($this->base()."/orders/{$order->id}/cancellable-amount");

        $response->assertStatus(200)
            ->assertJsonStructure(['data' => ['cancellable' => [
                'amount', 'domain_refundable', 'payment_capacity', 'reserved',
                'binding', 'binding_label_key', 'methods', 'reserved_claims', 'currency', 'mc_amount',
            ]]]);

        $this->assertGreaterThan(0, (float) $response->json('data.cancellable.amount'));
        $this->assertSame([], $response->json('data.cancellable.reserved_claims'));
    }

    /**
     * 같은 주문의 다른 미실행 환불신청서 금액이 선점(`reserved`) 축에 집계된다.
     *
     * @effects cancellable_amount_reserves_other_pending_refunds
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_reserves_other_pending_refunds(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $other = $this->makeDirectRefund($order, $order->options->first(), 1, RefundStatusEnum::APPROVED);
        $other->update(['refund_amount' => 5000]);

        $response = $this->actingAs($this->admin)
            ->getJson($this->base()."/orders/{$order->id}/cancellable-amount");

        $response->assertStatus(200);
        $this->assertSame(5000.0, (float) $response->json('data.cancellable.reserved'));
    }

    /**
     * `refund_id` 문맥으로 조회하면 그 신청서 자신은 선점 집계에서 빠져 자기 금액으로 한도가 줄지 않는다.
     *
     * @effects cancellable_amount_with_refund_context_excludes_itself_from_the_reserve
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_with_refund_context_excludes_itself_from_the_reserve(): void
    {
        [, $refund] = $this->approvedDirectRefund(10000);

        $response = $this->actingAs($this->admin)
            ->getJson($this->base()."/orders/{$refund->order_id}/cancellable-amount?refund_id={$refund->id}");

        $response->assertStatus(200);

        // 자기 자신은 선점 집계에서 빠진다 — 남아 있으면 자기 금액을 스스로 빼 한도가 줄어든다.
        $this->assertSame(0.0, (float) $response->json('data.cancellable.reserved'));
        $this->assertGreaterThanOrEqual(10000.0, (float) $response->json('data.cancellable.amount'));
    }

    /**
     * 교환 발 환불의 도메인 환불 대상(① 축)은 교환이 동결한 차액이며, 자기 접수분을 빼지 않아
     * 한도가 접수액 이상으로 남는다.
     *
     * @effects cancellable_amount_for_an_exchange_sourced_refund_excludes_its_own_filing
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_for_an_exchange_sourced_refund_excludes_its_own_filing(): void
    {
        [$order, $exchange, $refund] = $this->approvedExchangeRefund();

        $filed = (float) $refund->refund_amount + (float) $refund->refund_points_amount;
        $this->assertGreaterThan(0, $filed, '접수된 차액이 이 축의 전제다.');

        $response = $this->actingAs($this->admin)
            ->getJson($this->base()."/orders/{$order->id}/cancellable-amount?refund_id={$refund->id}");

        $response->assertStatus(200);

        // ① 축은 교환이 동결한 차액이고, 자기 접수분은 빼지 않는다 — 빼면 한도가 0 이 되어
        // 정당한 환불이 스스로에게 막힌다.
        $this->assertSame(
            (float) $exchange->refund_product_amount,
            (float) $response->json('data.cancellable.domain_refundable'),
        );
        $this->assertGreaterThanOrEqual($filed, (float) $response->json('data.cancellable.amount'));
    }

    /**
     * 교환 발 환불을 실행하면 옵션 상태(EXCHANGED)는 그대로 두고 주문 누적 환불액과 PG 호출액만 움직인다.
     *
     * @effects execute_of_an_exchange_sourced_refund_accumulates_without_touching_options
     *
     * @return void 반환값 없음
     */
    public function test_execute_of_an_exchange_sourced_refund_accumulates_without_touching_options(): void
    {
        $this->fakePgRefund($calls);
        [$order, , $refund] = $this->approvedExchangeRefund();

        // 손계산 리터럴(T6) — 저가 교환 차액 10,000 − 왕복 배송비 6,000(환불금 차감).
        $expectedAmount = 4000.0;
        $this->assertSame($expectedAmount, (float) $refund->refund_amount);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        $refund->refresh();
        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->refund_status);

        // 교환 발 환불 — 원 상품은 교환 완료 시점에 이미 닫혔고 실행은 돈만 움직인다.
        $this->assertSame(OrderStatusEnum::EXCHANGED, $order->options->first()->fresh()->option_status);
        $this->assertSame($expectedAmount, (float) $order->fresh()->total_refunded_amount);
        $this->assertSame($expectedAmount, (float) $calls[0]['amount']);
    }

    /**
     * 다른 주문의 환불신청서 ID 를 문맥으로 넘기면 교차 접근으로 보고 404 를 낸다.
     *
     * @effects cancellable_amount_is_404_for_a_refund_of_another_order
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_is_404_for_a_refund_of_another_order(): void
    {
        [, $refund] = $this->approvedDirectRefund(10000);
        $otherOrder = $this->deliveredClaimOrder();

        $this->actingAs($this->admin)
            ->getJson($this->base()."/orders/{$otherOrder->id}/cancellable-amount?refund_id={$refund->id}")
            ->assertStatus(404);
    }

    // ================================================================
    // 금액 수정 (D18) — 환불 축
    // ================================================================

    /**
     * 상한 이내의 환불액·환불 방법 수정은 저장되어 신청서에 반영된다.
     *
     * @effects amounts_updates_the_refund_amounts_within_the_cancellable_limit
     *
     * @return void 반환값 없음
     */
    public function test_amounts_updates_the_refund_amounts_within_the_cancellable_limit(): void
    {
        [, $refund] = $this->approvedDirectRefund(10000);

        $response = $this->actingAs($this->admin)
            ->putJson($this->base()."/refunds/{$refund->id}/amounts", [
                'refund_amount' => 8000,
                'refund_method' => RefundMethodEnum::BANK->value,
            ]);

        $response->assertStatus(200);

        $refund->refresh();
        $this->assertSame(8000.0, (float) $refund->refund_amount);
        $this->assertSame(RefundMethodEnum::BANK, $refund->refund_method);
    }

    /**
     * 금액 수정이 D19 ③ 축의 직렬화 지점인 주문 행을 잠그는지 (4차 계획 검증)
     *
     * 상한의 ③ 축은 "다른 미실행 클레임의 저장 금액 합" 이라 주문 전역 집계다. 자기
     * 신청서 행만 잠그면 같은 주문의 서로 다른 두 신청서가 서로 다른 행을 잠그고 나란히
     * 통과해 합계가 D19 를 넘긴 채로 저장된다 — 형제 `executeRefund()` · 접수 경로와 같은
     * 직렬화 지점(주문 행)을 쓰는지가 이 축의 유일한 관측 가능 신호다.
     *
     * @effects amounts_locks_the_order_row_for_the_cancellable_aggregate
     *
     * @return void 반환값 없음
     */
    public function test_amounts_locks_the_order_row_for_the_cancellable_aggregate(): void
    {
        [, $refund] = $this->approvedDirectRefund(10000);

        $ordersTable = DB::getTablePrefix().(new Order)->getTable();
        $orderLocks = [];

        DB::listen(function ($query) use (&$orderLocks, $ordersTable) {
            $sql = strtolower($query->sql);

            if (str_contains($sql, 'for update') && str_contains($sql, '`'.$ordersTable.'`')) {
                $orderLocks[] = $query->sql;
            }
        });

        $this->actingAs($this->admin)
            ->putJson($this->base()."/refunds/{$refund->id}/amounts", ['refund_amount' => 8000])
            ->assertStatus(200);

        $this->assertNotSame(
            [],
            $orderLocks,
            '금액 수정이 주문 행을 잠그지 않았다 — 같은 주문의 두 신청서를 동시에 상향하면 D19 상한을 함께 넘긴다.',
        );
    }

    /**
     * 취소 가능 금액을 넘는 환불액 수정은 422 로 거절되고 저장 금액이 바뀌지 않는다.
     *
     * @effects amounts_rejects_a_refund_amount_exceeding_the_cancellable_limit
     *
     * @return void 반환값 없음
     */
    public function test_amounts_rejects_a_refund_amount_exceeding_the_cancellable_limit(): void
    {
        [, $refund] = $this->approvedDirectRefund(10000);

        $this->actingAs($this->admin)
            ->putJson($this->base()."/refunds/{$refund->id}/amounts", ['refund_amount' => 999999])
            ->assertStatus(422);

        $this->assertSame(10000.0, (float) $refund->fresh()->refund_amount);
    }

    /**
     * 비회원 주문의 환불신청서에는 마일리지 환불액을 저장할 수 없다(422) — 원장이 회원 전용이다.
     *
     * @effects amounts_rejects_mileage_refunds_for_guest_orders
     *
     * @return void 반환값 없음
     */
    public function test_amounts_rejects_mileage_refunds_for_guest_orders(): void
    {
        [$order, $refund] = $this->approvedDirectRefund(10000);
        $order->forceFill(['user_id' => null])->save();

        $this->actingAs($this->admin)
            ->putJson($this->base()."/refunds/{$refund->id}/amounts", ['refund_points_amount' => 1000])
            ->assertStatus(422);
    }

    /**
     * 실행 경로도 비회원 마일리지 환급을 거절한다 (fail-closed — §4-10-6 ①).
     *
     * 저장과 실행은 같은 정규화기를 지나지만 저장 축만 단언돼 있었다. 실행이 본문 금액을
     * 다른 경로로 받게 되면 그 순간 장부만 정산되는 결함이 회귀 없이 지나간다 —
     * 마일리지 원장은 회원 전용이라 환급 대상 자체가 없다.
     *
     * @effects execute_rejects_mileage_refunds_for_guest_orders
     *
     * @return void 반환값 없음
     */
    public function test_execute_rejects_mileage_refunds_for_guest_orders(): void
    {
        [$order, $refund] = $this->approvedDirectRefund(10000);
        $order->forceFill(['user_id' => null])->save();

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute", ['refund_points_amount' => 1000])
            ->assertStatus(422);

        // 거절됐으므로 신청서는 승인 상태 그대로다 — 가드가 쓰기보다 앞선다.
        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
    }

    /**
     * 환불 유형 금액 수정에 클레임 배송비 필드를 보내면 422 로 거절한다.
     *
     * @effects amounts_prohibits_shipping_fee_fields_on_the_refund_type
     *
     * @return void 반환값 없음
     */
    public function test_amounts_prohibits_shipping_fee_fields_on_the_refund_type(): void
    {
        [, $refund] = $this->approvedDirectRefund(10000);

        $this->actingAs($this->admin)
            ->putJson($this->base()."/refunds/{$refund->id}/amounts", ['claim_shipping_fee' => 1000])
            ->assertStatus(422);
    }

    /**
     * 조회 권한만 있는 관리자는 환불 금액을 수정할 수 없다(403).
     *
     * @effects amounts_requires_the_update_amounts_permission
     *
     * @return void 반환값 없음
     */
    public function test_amounts_requires_the_update_amounts_permission(): void
    {
        [, $refund] = $this->approvedDirectRefund(10000);

        $reader = $this->createAdminUser(['sirsoft-ecommerce.claims.read']);

        $this->actingAs($reader)
            ->putJson($this->base()."/refunds/{$refund->id}/amounts", ['refund_amount' => 1])
            ->assertStatus(403);
    }

    // ================================================================
    // 취소 미리보기 비대칭 해소 (S3 2차 확정 ②)
    // ================================================================

    /**
     * 반품 접수로 클레임 코스에 들어간 상품은 실행뿐 아니라 취소 환불 미리보기도 422 로 막힌다.
     *
     * @effects estimate_refund_is_422_for_items_locked_by_a_claim_course
     *
     * @return void 반환값 없음
     */
    public function test_estimate_refund_is_422_for_items_locked_by_a_claim_course(): void
    {
        $order = $this->deliveredClaimOrder();

        app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);

        // 반품 접수로 코스 상태(return_in_progress)가 된 상품 — 실행뿐 아니라 미리보기도 422 다.
        $option = $order->options()->first();

        $this->actingAs($this->admin)
            ->postJson($this->base()."/orders/{$order->id}/estimate-refund", [
                'items' => [['order_option_id' => $option->id, 'cancel_quantity' => 1]],
            ])
            ->assertStatus(422);
    }

    // ================================================================
    // 취소 가능 금액 — 다통화 · 권한 · 문맥 배타 · 한도 축 (3차 계획 검증 보강)
    // ================================================================

    /**
     * 외화 결제 주문의 수단별 잔여는 결제 통화 원값(USD 50)과 기준 통화 환산값(KRW 50,000)을 따로 싣고,
     * 합계 축은 기준 통화로 답한다.
     *
     * @effects cancellable_amount_reports_the_payment_currency_remaining_separately_from_the_base
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_reports_the_payment_currency_remaining_separately_from_the_base(): void
    {
        $order = $this->foreignPaidClaimOrder(rate: 0.001, paidLocal: 50);

        $response = $this->actingAs($this->admin)
            ->getJson($this->base()."/orders/{$order->id}/cancellable-amount");

        $response->assertStatus(200);

        $payment = collect($response->json('data.cancellable.methods'))
            ->firstWhere('type', 'payment');

        $this->assertNotNull($payment, '결제행 수단 항목이 있어야 이 축을 잴 수 있다.');

        // 결제 통화 원값과 기본 통화 환산값은 서로 다른 숫자다 — 화면이 혼용하면 단위가 틀린다.
        $this->assertSame('USD', $payment['currency']);
        $this->assertSame(50.0, (float) $payment['remaining_local']);
        $this->assertSame(50000.0, (float) $payment['remaining_base']);

        // 합계(② 축)는 기본 통화 기준이다.
        $this->assertSame(50000.0, (float) $response->json('data.cancellable.payment_capacity'));
        $this->assertSame('KRW', $response->json('data.cancellable.currency'));
    }

    /**
     * 주문 권한만 있는 관리자는 취소 가능 금액을 조회할 수 없다(403) — 게이트는 `claims.read` 다.
     *
     * @effects cancellable_amount_requires_the_claims_read_permission
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_requires_the_claims_read_permission(): void
    {
        $order = $this->deliveredClaimOrder();

        // 주문 권한만 가진 운영자 — D19 는 클레임 도메인 산정이라 claims.read 가 게이트다.
        $outsider = $this->createAdminUser(['sirsoft-ecommerce.orders.update']);

        $this->actingAs($outsider)
            ->getJson($this->base()."/orders/{$order->id}/cancellable-amount")
            ->assertStatus(403);
    }

    /**
     * `refund_id` 문맥과 `items` 를 함께 보내면 items 를 조용히 버리지 않고 422 로 거절한다.
     *
     * @effects cancellable_amount_rejects_the_refund_context_together_with_items
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_rejects_the_refund_context_together_with_items(): void
    {
        [$order, $refund] = $this->approvedDirectRefund(10000);
        $option = $order->options->first();

        // 두 문맥은 배타다 — 함께 오면 items 가 조용히 버려지므로 422 로 막는다.
        $this->actingAs($this->admin)
            ->getJson($this->base()."/orders/{$order->id}/cancellable-amount?refund_id={$refund->id}"
                ."&items[0][order_option_id]={$option->id}&items[0][cancel_quantity]=1")
            ->assertStatus(422)
            ->assertJsonValidationErrors('refund_id');
    }

    /**
     * 쿼리 스트링의 빈 `refund_id` 는 보내지 않은 것으로 보고 상품 기준 조회를 통과시킨다.
     *
     * @effects cancellable_amount_accepts_items_when_the_refund_context_is_blank
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_accepts_items_when_the_refund_context_is_blank(): void
    {
        $order = $this->deliveredClaimOrder();
        $option = $order->options->first();

        // 쿼리 스트링의 빈 값은 "보내지 않은 것" 이다 — 여기서 422 가 나면 상품 기준 조회가 막힌다.
        $this->actingAs($this->admin)
            ->getJson($this->base()."/orders/{$order->id}/cancellable-amount?refund_id="
                ."&items[0][order_option_id]={$option->id}&items[0][cancel_quantity]=1")
            ->assertStatus(200);
    }

    /**
     * 다른 주문의 옵션 ID 로 이 주문의 한도를 묻는 교차 접근은 해당 항목 422 로 거절한다.
     *
     * @effects cancellable_amount_scopes_the_requested_items_to_the_order
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_scopes_the_requested_items_to_the_order(): void
    {
        $order = $this->deliveredClaimOrder();
        $otherOrder = $this->deliveredClaimOrder();
        $foreignOption = $otherOrder->options->first();

        // 남의 주문 옵션 ID 로 이 주문의 한도를 묻는 교차 접근은 422 다.
        $this->actingAs($this->admin)
            ->getJson($this->base()."/orders/{$order->id}/cancellable-amount"
                ."?items[0][order_option_id]={$foreignOption->id}&items[0][cancel_quantity]=1")
            ->assertStatus(422)
            ->assertJsonValidationErrors('items.0.order_option_id');
    }

    /**
     * ③ 진행 중 클레임 예정액은 **참고 축**이다 — 아무리 커도 한도를 깎지 않는다.
     *
     * 예전에는 이 축이 한도를 0 으로 잘라, 결제 잔여가 충분한데도 승인조차 되지 않은 남의
     * 신청서 때문에 정당한 환불이 막혔다(S17 B5 — 실측 3회 재현). 응답은 근거로서 예정액과
     * 그 내역을 계속 싣되, `binding` 은 ①·② 두 강제 축만 가리킨다.
     *
     * @effects cancellable_amount_reports_the_reserved_axis_but_does_not_bind_on_it
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_reports_the_reserved_axis_but_does_not_bind_on_it(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);

        $other = $this->makeDirectRefund($order, $order->options->first(), 2, RefundStatusEnum::APPROVED);
        $other->update(['refund_amount' => 999000]);

        $response = $this->actingAs($this->admin)
            ->getJson($this->base()."/orders/{$order->id}/cancellable-amount");

        $response->assertStatus(200);
        $this->assertGreaterThan(0.0, (float) $response->json('data.cancellable.reserved'));
        $this->assertNotSame('reserved', $response->json('data.cancellable.binding'));
        $this->assertGreaterThan(0.0, (float) $response->json('data.cancellable.amount'));
    }

    /**
     * 결제 잔여가 도메인 환불 대상보다 작으면 한도를 정한 축(`binding`)이 결제 잔여로 보고되고 한도가 그 값이 된다.
     *
     * @effects cancellable_amount_reports_the_payment_axis_as_binding
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_reports_the_payment_axis_as_binding(): void
    {
        // 결제 잔여가 도메인 환불 대상보다 작으면 ② 축이 한도를 정한다.
        $order = $this->deliveredClaimOrder(paymentOverrides: ['paid_amount_local' => 100, 'paid_amount_base' => 100]);

        $response = $this->actingAs($this->admin)
            ->getJson($this->base()."/orders/{$order->id}/cancellable-amount");

        $response->assertStatus(200);
        $this->assertSame('payment_capacity', $response->json('data.cancellable.binding'));
        $this->assertSame(100.0, (float) $response->json('data.cancellable.amount'));
    }

    /**
     * 전량 취소된 주문은 취소 가능 금액 0 을 답하되, 통화(KRW)는 비우지 않고 함께 싣는다.
     *
     * @effects cancellable_amount_answers_a_fully_cancelled_order_with_the_base_currency
     *
     * @return void 반환값 없음
     */
    public function test_cancellable_amount_answers_a_fully_cancelled_order_with_the_base_currency(): void
    {
        $order = $this->deliveredClaimOrder();
        $order->options()->update(['option_status' => OrderStatusEnum::CANCELLED->value]);

        $response = $this->actingAs($this->admin)
            ->getJson($this->base()."/orders/{$order->id}/cancellable-amount");

        $response->assertStatus(200);
        $this->assertSame(0.0, (float) $response->json('data.cancellable.amount'));

        // 되돌릴 대상이 없어도 통화는 답한다 — 이 경로만 null 이면 같은 엔드포인트가 단위를 잃는다.
        $this->assertSame('KRW', $response->json('data.cancellable.currency'));
    }

    // ================================================================
    // 환불 실행 — 혼합 수단 · 처리자 보존 · 원장 연결 (3차 계획 검증 보강)
    // ================================================================

    /**
     * PG·마일리지 혼합 환불을 실행하면 PG 에는 PG 몫만 청구하고 마일리지 몫은 원장 환급으로 나가며,
     * 주문의 두 누적 컬럼에 각자 쌓인다.
     *
     * @effects execute_splits_a_mixed_pg_and_mileage_refund
     *
     * @return void 반환값 없음
     */
    public function test_execute_splits_a_mixed_pg_and_mileage_refund(): void
    {
        $this->fakePgRefund($calls);
        [$order, $refund] = $this->approvedDirectRefund(7000, 3000, [
            'total_points_used_amount' => 5000,
            'is_mileage_deducted' => true,
        ]);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        // PG 에는 PG 몫만 청구한다 — 합계를 넘기면 마일리지 몫이 두 번 나간다.
        $this->assertSame(7000.0, (float) $calls[0]['amount']);

        // 마일리지 몫은 원장으로만 환급된다.
        $restored = MileageTransaction::query()->where('order_refund_id', $refund->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)->get();
        $this->assertCount(1, $restored);
        $this->assertSame(3000.0, (float) $restored->first()->amount);

        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->fresh()->refund_status);

        // 두 수단은 각자의 누적 컬럼으로 간다 — 합산해 한쪽에 실으면 단위가 섞인다.
        $order = $order->fresh();
        $this->assertSame(7000.0, (float) $order->total_refunded_amount);
        $this->assertSame(3000.0, (float) $order->total_refunded_points_amount);
    }

    /**
     * 마일리지 환급 실행이 원 사용 거래에 `order_refund_id` 를 역주입해 환급과 사용이 연결 거래로 묶인다.
     *
     * @effects execute_links_the_mileage_restore_to_the_original_usage_transaction
     *
     * @return void 반환값 없음
     */
    public function test_execute_links_the_mileage_restore_to_the_original_usage_transaction(): void
    {
        [$order, $refund] = $this->approvedDirectRefund(0, 3000, [
            'total_points_used_amount' => 5000,
            'is_mileage_deducted' => true,
        ]);

        $usage = MileageTransaction::create([
            'user_id' => $order->user_id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::ORDER_USE->value,
            'amount' => -5000,
            'remaining_amount' => 0,
            'balance_after' => 0,
            'order_id' => $order->id,
        ]);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        // 원 사용 거래에 환불 축이 역주입돼야 환급 ↔ 사용이 연결 거래로 묶인다 (취소 축과 대칭).
        $this->assertSame($refund->id, (int) $usage->fresh()->order_refund_id);

        $restore = MileageTransaction::query()->where('order_refund_id', $refund->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)->firstOrFail();

        $linked = app(MileageTransactionRepositoryInterface::class)->getLinkedTransactions($restore);
        $this->assertTrue(
            $linked->contains(fn (MileageTransaction $tx) => (int) $tx->id === (int) $usage->id),
            '환급 거래의 연결 거래에 원 사용 거래가 보여야 한다.',
        );
    }

    /**
     * 처리자 없이 실행해도 환불 상세 행에 이미 기록된 처리자는 지워지지 않는다.
     *
     * @effects execute_preserves_the_option_processed_by_when_none_is_given
     *
     * @return void 반환값 없음
     */
    public function test_execute_preserves_the_option_processed_by_when_none_is_given(): void
    {
        $this->fakePgRefund();
        [, $refund] = $this->approvedDirectRefund(10000);

        $refund->refundOptions()->update(['processed_by' => $this->admin->id]);

        // 서비스 직접 호출 — 처리자 미상 경로에서도 상세 행의 기존 처리자는 지워지지 않는다.
        app(OrderRefundRequestService::class)->executeRefund($refund, [], null);

        $this->assertSame(
            (int) $this->admin->id,
            (int) $refund->refundOptions()->first()->fresh()->processed_by,
        );
    }

    // ================================================================
    // 금액 수정 — 상태 게이트 2분기 (3차 계획 검증 보강)
    // ================================================================

    /**
     * 취소 발 환불신청서는 취소 파이프라인 산출값이라 금액 수정을 422 로 거절하고 금액이 그대로다.
     *
     * @effects amounts_rejects_a_cancel_sourced_refund
     *
     * @return void 반환값 없음
     */
    public function test_amounts_rejects_a_cancel_sourced_refund(): void
    {
        $order = $this->deliveredClaimOrder();
        $refund = $this->makeDirectRefund(
            $order,
            $order->options->first(),
            1,
            RefundStatusEnum::APPROVED,
            RefundSourceTypeEnum::CANCEL,
        );

        // 취소 파이프라인 산출값이라 이 API 로 고치면 실환불과 어긋난다 — 실행과 같은 강도로 막는다.
        $this->actingAs($this->admin)
            ->putJson($this->base()."/refunds/{$refund->id}/amounts", ['refund_amount' => 1000])
            ->assertStatus(422);

        $this->assertSame(0.0, (float) $refund->fresh()->refund_amount);
    }

    /**
     * 처리 중(PROCESSING) 환불신청서는 금액이 이미 실행 근거로 소비됐으므로 수정을 422 로 거절한다.
     *
     * @effects amounts_rejects_a_processing_refund
     *
     * @return void 반환값 없음
     */
    public function test_amounts_rejects_a_processing_refund(): void
    {
        [, $refund] = $this->approvedDirectRefund(10000);
        $refund->update(['refund_status' => RefundStatusEnum::PROCESSING]);

        // 환불신청서는 종결 전이라도 PROCESSING 이면 금액이 이미 실행 근거로 소비됐다.
        $this->actingAs($this->admin)
            ->putJson($this->base()."/refunds/{$refund->id}/amounts", ['refund_amount' => 1000])
            ->assertStatus(422);

        $this->assertSame(10000.0, (float) $refund->fresh()->refund_amount);
    }

    // ================================================================
    // 취소 미리보기 — 사유 전달 · 수량 잠금 (3차 계획 검증 보강)
    // ================================================================

    /**
     * 클레임 진행 중 상품의 취소 미리보기가 422 일 때 관리자 응답은 사유 원문을 `errors.detail` 로 싣는다.
     *
     * @effects estimate_refund_reports_the_reason_detail_for_admins
     *
     * @return void 반환값 없음
     */
    public function test_estimate_refund_reports_the_reason_detail_for_admins(): void
    {
        $order = $this->deliveredClaimOrder();

        app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);

        $response = $this->actingAs($this->admin)
            ->postJson($this->base()."/orders/{$order->id}/estimate-refund", [
                'items' => [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1]],
            ]);

        $response->assertStatus(422);

        // 관리자 면은 사유 원문을 errors.detail 로 받는다 — 회원·비회원의 사유 키와 짝인 계약이다.
        $this->assertNotEmpty($response->json('errors.detail'));
    }

    /**
     * 미실행 환불신청서가 잠근 수량까지 합쳐 취소를 미리보기하면 수량 잠금으로 422 가 난다.
     *
     * @effects estimate_refund_is_422_for_items_locked_by_a_pending_refund_quantity
     *
     * @return void 반환값 없음
     */
    public function test_estimate_refund_is_422_for_items_locked_by_a_pending_refund_quantity(): void
    {
        $order = $this->deliveredClaimOrder(quantity: 2);
        $option = $order->options->first();

        // 코스 상태가 아니라 수량 잠금으로 막히는 축 — 이미 잠긴 수량까지 합쳐 요청한다.
        $this->makeDirectRefund($order, $option, 2, RefundStatusEnum::APPROVED);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/orders/{$order->id}/estimate-refund", [
                'items' => [['order_option_id' => $option->id, 'cancel_quantity' => 2]],
            ])
            ->assertStatus(422);
    }

    /**
     * **저장**도 D19 상한을 막는다 — 실행만 막으면 상한 초과가 장부에 앉는다. (2-M)
     *
     * `UpdateClaimAmountsRequest` 의 독블록은 상한을 「서비스가 **저장·실행 두 지점**에서
     * 같은 식으로 재계산해 검증한다」고 선언하고, `OrderRefundRequestService:1318` 도
     * 「저장은 최종값으로 한 번, 실행은 반영 후 실행 직전에」로 같은 계약을 적는다.
     * **그런데 회귀는 실행 지점에만 있었다**(`test_execute_rejects_amounts_exceeding_the_cancellable_limit`).
     *
     * 저장 가드가 빠지면 초과값이 **신청서에 저장된 채 approved 로 남는다.** 화면은 그 값을
     * 그대로 그리므로 운영자는 승인된 금액이라고 읽는다. 실행 단계에서야 422 가 나지만,
     * 그때는 이미 「저장은 됐는데 실행만 안 되는」 상태라 원인이 금액인지 상태인지 가려지지 않는다.
     * 게다가 D19 ③ 축(다른 미실행 클레임의 **저장 금액** 합)이 그 초과값을 읽으므로,
     * **다른 신청서의 상한까지 함께 깎인다.**
     *
     * @scenario claim_type=refund, cancellable=present
     *
     * @effects claim_refund_save_rejects_over_the_cancellable_limit
     *
     * @return void 반환값 없음
     */
    public function test_save_rejects_amounts_exceeding_the_cancellable_limit(): void
    {
        [, $refund] = $this->approvedDirectRefund(10000);

        $before = (float) $refund->refund_amount;

        $this->actingAs($this->admin)
            ->putJson($this->base()."/refunds/{$refund->id}/amounts", [
                'refund_amount' => 999999,
            ])
            ->assertStatus(422);

        $this->assertSame(
            $before,
            (float) $refund->fresh()->refund_amount,
            '상한을 넘긴 저장이 거절됐는데도 금액이 바뀌었습니다 — 거절과 쓰기가 갈렸습니다.'
        );
    }

    /**
     * 상한 **이내**의 저장은 통과한다 — 대조군.
     *
     * 이 테스트가 없으면 위의 422 가 「상한을 판정해서」인지 「저장 경로가 통째로 막혀서」인지
     * 가려지지 않는다. 막혀서 나는 422 도 똑같이 초록이라, 대조군 없이는 근거가 서지 않는다.
     *
     * @scenario claim_type=refund, cancellable=present
     *
     * @effects claim_refund_save_accepts_within_the_cancellable_limit
     *
     * @return void 반환값 없음
     */
    public function test_save_accepts_amounts_within_the_cancellable_limit(): void
    {
        [, $refund] = $this->approvedDirectRefund(10000);

        $this->actingAs($this->admin)
            ->putJson($this->base()."/refunds/{$refund->id}/amounts", [
                'refund_amount' => 9500,
            ])
            ->assertStatus(200);

        $this->assertSame(
            9500.0,
            (float) $refund->fresh()->refund_amount,
            '상한 이내 저장이 200 을 냈는데 금액이 반영되지 않았습니다.'
        );
    }

    // ================================================================
    // 마일리지로 환불 — 현금분 지급 (결함 1 · D1 · Q2 · Q16 · Q21)
    // ================================================================

    /**
     * 상점의 마일리지 기능을 켭니다. (현금을 마일리지로 돌려주는 환불은 기능이 켜진 상점에서만 — Q21)
     *
     * @return void 반환값 없음
     */
    protected function enableMileage(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('mileage.enabled', true);
        $settings->setSetting('mileage.expiry_enabled', true);
        $settings->setSetting('mileage.expiry_days', 365);
    }

    /**
     * 결제행 취소 이력의 마지막 항목을 돌려줍니다.
     *
     * @param  Order  $order  주문
     * @return array<string, mixed> 마지막 이력 항목
     */
    protected function lastCancelHistory(Order $order): array
    {
        $history = $order->payment()->first()->cancel_history ?? [];

        return (array) end($history);
    }

    /**
     * 「마일리지로 환불」로 실행하면 현금 환불액이 그 환불에 묶인 무기한 마일리지로 지급되고,
     * 카드는 취소되지 않으며 결제행은 결제완료로 남는다.
     *
     * @effects points_refund_credits_the_cash_amount_as_mileage
     *
     * @return void 반환값 없음
     */
    public function test_points_refund_credits_the_cash_amount_as_mileage(): void
    {
        $this->enableMileage();
        $this->fakePgRefund($calls);
        [$order, $refund] = $this->approvedDirectRefund(10000);
        $refund->update(['refund_method' => RefundMethodEnum::POINTS]);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        $credits = MileageTransaction::query()->where('order_refund_id', $refund->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_CREDIT->value)->get();
        $this->assertCount(1, $credits);
        $this->assertSame(10000.0, (float) $credits->first()->amount);
        $this->assertSame((int) $order->user_id, (int) $credits->first()->user_id);
        $this->assertNull($credits->first()->expires_at);

        $this->assertSame(10000.0, app(MileageTransactionRepositoryInterface::class)->getBalanceByCurrency($order->user_id, 'KRW'));
        $this->assertSame(10000.0, (float) MileageBalance::query()->where('user_id', $order->user_id)->where('currency', 'KRW')->value('available'));
        $this->assertSame([], $calls);

        $payment = $order->payment()->first();
        $this->assertSame(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame(0.0, (float) $payment->cancelled_amount);

        $last = $this->lastCancelHistory($order);
        $this->assertSame('points', $last['refund_method']);
        $this->assertFalse($last['settled_on_payment']);
        $this->assertSame(10000.0, (float) $last['amount']);

        $fresh = $order->fresh();
        $this->assertSame(10000.0, (float) $fresh->total_refunded_amount);
        $this->assertSame(0.0, (float) $fresh->total_refunded_points_amount);
        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->fresh()->refund_status);
    }

    /**
     * 혼합 결제를 「마일리지로 환불」하면 쓴 마일리지를 먼저 반환하고 남은 현금분을 지급한다(D1).
     *
     * @effects points_refund_returns_used_mileage_before_crediting_the_cash
     *
     * @return void 반환값 없음
     */
    public function test_points_refund_returns_used_mileage_before_crediting_the_cash(): void
    {
        $this->enableMileage();
        $this->fakePgRefund($calls);
        [$order, $refund] = $this->approvedDirectRefund(7000, 3000, [
            'total_points_used_amount' => 5000,
            'is_mileage_deducted' => true,
        ]);
        $refund->update(['refund_method' => RefundMethodEnum::POINTS]);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        $restore = MileageTransaction::query()->where('order_refund_id', $refund->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)->firstOrFail();
        $credit = MileageTransaction::query()->where('order_refund_id', $refund->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_CREDIT->value)->firstOrFail();

        $this->assertSame(3000.0, (float) $restore->amount);
        $this->assertNotNull($restore->expires_at);
        $this->assertSame(7000.0, (float) $credit->amount);
        $this->assertNull($credit->expires_at);
        $this->assertLessThan((int) $credit->id, (int) $restore->id, '쓴 마일리지 반환이 현금분 지급보다 먼저다');

        $this->assertSame(10000.0, app(MileageTransactionRepositoryInterface::class)->getBalanceByCurrency($order->user_id, 'KRW'));
        $this->assertSame([], $calls);
        $fresh = $order->fresh();
        $this->assertSame(7000.0, (float) $fresh->total_refunded_amount);
        $this->assertSame(3000.0, (float) $fresh->total_refunded_points_amount);
    }

    /**
     * 반품 발 환불을 「마일리지로 환불」로 실행하면(refund 274 형) 현금 환불액 전액이 마일리지로
     * 지급되고, 반품 상품 상태와 결제완료 결제행은 그대로다(Q16).
     *
     * @effects return_sourced_points_refund_credits_the_cash
     *
     * @return void 반환값 없음
     */
    public function test_return_sourced_points_refund_credits_the_cash(): void
    {
        $this->enableMileage();
        $this->fakePgRefund($calls);
        [$order, , $refund] = $this->approvedReturnRefund();
        // 손계산 리터럴(T6) — 29,000 − 반품 편도 회수비 3,000
        $expected = 26000.0;
        $this->assertSame($expected, (float) $refund->refund_amount);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute", ['refund_method' => 'points'])
            ->assertStatus(200);

        $this->assertSame($expected, (float) MileageTransaction::query()->where('order_refund_id', $refund->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_CREDIT->value)->sum('amount'));
        $this->assertSame([], $calls);
        $this->assertSame(OrderStatusEnum::RETURNED, $order->options->first()->fresh()->option_status);

        $payment = $order->payment()->first();
        $this->assertSame(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame(0.0, (float) $payment->cancelled_amount);
        $this->assertNull($payment->cancelled_at);

        $last = $this->lastCancelHistory($order);
        $this->assertSame('points', $last['refund_method']);
        $this->assertFalse($last['settled_on_payment']);
        $this->assertSame($expected, (float) $last['amount']);
        $this->assertSame($expected, (float) $order->fresh()->total_refunded_amount);
    }

    /**
     * 저장된 환불수단이 「마일리지」인 비회원 주문은 본문 없이 실행해도 거절된다 — 정규화는
     * 본문(override)만 보므로 저장값 가드가 없으면 돈이 증발한다(S5).
     *
     * @effects stored_points_method_is_rejected_for_guest_orders
     *
     * @return void 반환값 없음
     */
    public function test_stored_points_method_is_rejected_for_guest_orders(): void
    {
        $this->enableMileage();
        [$order, $refund] = $this->approvedDirectRefund(10000);
        $refund->update(['refund_method' => RefundMethodEnum::POINTS]);
        $order->forceFill(['user_id' => null])->save();

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(422);

        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
        $this->assertSame(0, MileageTransaction::query()->where('order_refund_id', $refund->id)->count());
        $this->assertSame(0.0, (float) $order->fresh()->total_refunded_amount);
    }

    /**
     * [금액 수정]으로 비회원 주문의 환불수단을 「마일리지」로 바꾸는 저장은 거절된다(S5).
     *
     * @effects amounts_rejects_switching_a_guest_order_to_points
     *
     * @return void 반환값 없음
     */
    public function test_amounts_rejects_switching_a_guest_order_to_points(): void
    {
        $this->enableMileage();
        [$order, $refund] = $this->approvedDirectRefund(10000);
        $order->forceFill(['user_id' => null])->save();

        $this->actingAs($this->admin)
            ->putJson($this->base()."/refunds/{$refund->id}/amounts", ['refund_method' => 'points'])
            ->assertStatus(422);

        $this->assertSame(RefundMethodEnum::PG, $refund->fresh()->refund_method);
    }

    /**
     * 환불수단 선택지는 비회원 주문과, 마일리지 기능이 꺼진 상점의 현금 환불에서 「마일리지」를
     * 뺀다. 현금이 0 원(쓴 마일리지 반환만)이면 기능과 무관하게 남긴다(Q21).
     *
     * @effects refund_method_options_hide_points_for_guest_or_disabled_mileage
     *
     * @return void 반환값 없음
     */
    public function test_refund_method_options_hide_points_for_guest_orders(): void
    {
        $service = app(AdminClaimService::class);
        [$member] = $this->approvedDirectRefund(10000);
        $guest = $this->deliveredClaimOrder();
        $guest->forceFill(['user_id' => null])->save();

        $values = fn (array $options): array => array_column($options, 'value');

        // 기능 꺼짐(기본값) — 현금이 있으면 숨기고, 현금 0 원이면 남긴다.
        $this->assertNotContains('points', $values($service->refundMethodOptions($member, 10000)));
        $this->assertContains('points', $values($service->refundMethodOptions($member, 0)));

        $this->enableMileage();
        $this->assertContains('points', $values($service->refundMethodOptions($member, 10000)));
        $this->assertNotContains('points', $values($service->refundMethodOptions($guest->fresh(), 10000)));
        $this->assertNotContains('points', $values($service->refundMethodOptions($guest->fresh(), 0)));
    }

    /**
     * 마일리지 기능이 꺼진 상점에서 현금을 「마일리지로 환불」하는 실행은 거절된다(Q21).
     *
     * @effects guest_or_disabled_points_refund_rejected
     *
     * @return void 반환값 없음
     */
    public function test_points_refund_of_cash_is_rejected_when_mileage_is_disabled(): void
    {
        $this->fakePgRefund($calls);
        [$order, $refund] = $this->approvedDirectRefund(10000);
        $refund->update(['refund_method' => RefundMethodEnum::POINTS]);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(422);

        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
        $this->assertSame(0, MileageTransaction::query()->where('order_refund_id', $refund->id)->count());
        $this->assertSame(0.0, (float) $order->fresh()->total_refunded_amount);
        $this->assertSame([], $calls);
    }

    // ================================================================
    // 실패 삼킴 제거 · 실행 순서 (결함 2 · D2 · P-D5 · F1 · F2)
    // ================================================================

    /**
     * 쓴 마일리지 반환이 실패하면 PG 를 부르지 않고 환불 전체가 롤백된다(D2).
     *
     * @effects mileage_restore_failure_stops_the_refund_before_pg
     *
     * @return void 반환값 없음
     */
    public function test_mileage_restore_failure_stops_the_refund_before_pg(): void
    {
        $this->fakePgRefund($calls);
        [$order, $refund] = $this->approvedDirectRefund(7000, 3000, [
            'total_points_used_amount' => 5000,
            'is_mileage_deducted' => true,
        ]);
        HookManager::addAction('sirsoft-ecommerce.mileage.restore', function () {
            throw new \RuntimeException('원장 잠금 실패');
        }, 5);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(422);

        $this->assertSame([], $calls);
        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
        $this->assertSame(0.0, (float) $order->fresh()->total_refunded_amount);
        $this->assertSame(0, MileageTransaction::query()->where('order_refund_id', $refund->id)->count());
    }

    /**
     * 현금분 마일리지 지급이 실패하면 먼저 끝난 쓴 마일리지 반환까지 롤백된다(D2).
     *
     * @effects mileage_credit_failure_rolls_back_the_restore
     *
     * @return void 반환값 없음
     */
    public function test_mileage_credit_failure_rolls_back_the_restore(): void
    {
        $this->enableMileage();
        [$order, $refund] = $this->approvedDirectRefund(7000, 3000, [
            'total_points_used_amount' => 5000,
            'is_mileage_deducted' => true,
        ]);
        $refund->update(['refund_method' => RefundMethodEnum::POINTS]);
        HookManager::addAction('sirsoft-ecommerce.mileage.refund_credit', function () {
            throw new \RuntimeException('지급 실패');
        }, 5);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(422);

        $this->assertSame(0, MileageTransaction::query()->where('order_refund_id', $refund->id)->count());
        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
        $this->assertSame(0.0, (float) $order->fresh()->total_refunded_points_amount);
    }

    /**
     * PG 가 거절하면 그 앞에서 끝난 마일리지 반환이 함께 롤백된다 — 순서를 옮긴 뒤의 회귀 가드.
     *
     * @effects pg_failure_rolls_back_the_mileage_restore
     *
     * @return void 반환값 없음
     */
    public function test_pg_failure_rolls_back_the_mileage_restore(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => false, 'transaction_id' => null, 'error_code' => 'DENY', 'error_message' => '거절',
        ]);
        [$order, $refund] = $this->approvedDirectRefund(7000, 3000, [
            'total_points_used_amount' => 5000,
            'is_mileage_deducted' => true,
        ]);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(422);

        $this->assertSame(0, MileageTransaction::query()->where('order_refund_id', $refund->id)->count());
        $this->assertSame(0.0, app(MileageTransactionRepositoryInterface::class)->getBalanceByCurrency($order->user_id, 'KRW'));
        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
    }

    /**
     * PG 는 장부 쓰기가 모두 끝난 뒤 마지막에 부른다(P-D5) — 호출 시점에 주문 누적·취소신청서·
     * 결제행 누적·상품 행 완료가 이미 기록돼 있다.
     *
     * @effects pg_is_the_last_step_of_the_transaction
     *
     * @return void 반환값 없음
     */
    public function test_pg_is_called_after_every_ledger_write(): void
    {
        $snapshot = null;
        [$order, $refund] = $this->approvedDirectRefund(10000);

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function ($default, $pgOrder, $payment) use (&$snapshot, $order, $refund) {
            $snapshot = [
                'total_refunded' => (float) Order::query()->whereKey($order->id)->value('total_refunded_amount'),
                'cancels' => OrderCancel::query()->where('order_id', $order->id)->count(),
                'payment_cancelled' => (float) $payment->cancelled_amount,
                'payment_status' => $payment->payment_status,
                'option_statuses' => OrderRefundOption::query()->where('order_refund_id', $refund->id)->pluck('option_status')->map(fn ($s) => $s->value)->all(),
            ];

            return ['success' => true, 'transaction_id' => 'PGTXN-LAST', 'error_code' => null, 'error_message' => null];
        });

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        $this->assertNotNull($snapshot);
        $this->assertSame(10000.0, $snapshot['total_refunded']);
        $this->assertSame(1, $snapshot['cancels']);
        $this->assertSame(10000.0, $snapshot['payment_cancelled']);
        $this->assertSame(PaymentStatusEnum::CANCELLED, $snapshot['payment_status']);
        $this->assertSame([RefundStatusEnum::COMPLETED->value], array_values(array_unique($snapshot['option_statuses'])));
        $this->assertSame('PGTXN-LAST', $refund->fresh()->pg_transaction_id);
    }

    /**
     * PG 뒤에 코어가 쓰는 것은 신청서 행 UPDATE 하나뿐이다(P-D5).
     *
     * @effects only_the_refund_row_is_written_after_pg
     *
     * @return void 반환값 없음
     */
    public function test_only_the_refund_row_is_written_after_pg(): void
    {
        $afterPg = false;
        $writes = [];
        [, $refund] = $this->approvedDirectRefund(10000);
        // 테스트 자체의 트랜잭션 깊이 — 실행 트랜잭션 안의 쓰기만 센다. 커밋 뒤 훅(활동로그 등)이
        // 남기는 행은 이 트랜잭션의 쓰기가 아니다.
        $baseLevel = DB::transactionLevel();

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', function () use (&$afterPg) {
            $afterPg = true;

            return ['success' => true, 'transaction_id' => 'PGTXN-W', 'error_code' => null, 'error_message' => null];
        });

        DB::listen(function ($query) use (&$afterPg, &$writes, $baseLevel) {
            if ($afterPg && DB::transactionLevel() > $baseLevel && preg_match('/^\s*(insert|update|delete)\b/i', $query->sql)) {
                $writes[] = $query->sql;
            }
        });

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        $this->assertCount(1, $writes, implode("\n", $writes));
        $this->assertMatchesRegularExpression('/^update\s+`?\w*order_refunds`?/i', $writes[0]);
    }

    /**
     * PG 승인 직후 장부 확정이 실패해 롤백되면 critical 로그와 신청서 표식을 남긴다(P-D5 §14-C).
     *
     * @effects orphan_pg_settlement_leaves_a_marker
     *
     * @return void 반환값 없음
     */
    public function test_commit_failure_after_pg_leaves_a_critical_log_and_a_marker(): void
    {
        $this->fakePgRefund($calls);
        [$order, $refund] = $this->approvedDirectRefund(10000);

        $critical = [];
        Event::listen(MessageLogged::class, function (MessageLogged $log) use (&$critical) {
            if ($log->level === 'critical') {
                $critical[] = $log->context;
            }
        });

        $thrown = false;
        OrderRefund::updating(function (OrderRefund $model) use (&$thrown) {
            if (! $thrown && $model->isDirty('refund_status') && $model->refund_status === RefundStatusEnum::COMPLETED) {
                $thrown = true;
                throw new \RuntimeException('커밋 직전 실패');
            }
        });

        $status = $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->status();

        $this->assertContains($status, [422, 500]);
        $this->assertCount(1, $calls);
        $this->assertNotEmpty($critical);
        $this->assertSame('PGTXN-1', $critical[0]['transaction_id'] ?? null);

        $fresh = $refund->fresh();
        $this->assertSame(RefundStatusEnum::APPROVED, $fresh->refund_status);
        $this->assertSame('PGTXN-1', $fresh->pg_transaction_id);
        $this->assertSame('LEDGER_COMMIT_FAILED', $fresh->pg_error_code);
        $this->assertSame(0.0, (float) $order->fresh()->total_refunded_amount);
    }

    /**
     * 표식(승인 상태 + PG 거래 ID)이 있는 신청서를 다시 실행하면 PG 를 부르지 않고 장부만 확정한다.
     *
     * @effects retry_with_a_marker_does_not_call_pg_again
     *
     * @return void 반환값 없음
     */
    public function test_retry_with_a_marker_does_not_call_pg_again(): void
    {
        $this->fakePgRefund($calls);
        [$order, $refund] = $this->approvedDirectRefund(10000);
        $refund->update(['pg_transaction_id' => 'PGTXN-X', 'pg_error_code' => 'LEDGER_COMMIT_FAILED']);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        $this->assertSame([], $calls);
        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->fresh()->refund_status);
        $this->assertSame(10000.0, (float) $order->fresh()->total_refunded_amount);
        $this->assertSame(10000.0, (float) $order->payment()->first()->cancelled_amount);
    }

    /**
     * 쿠폰 복원이 실패하면 PG 를 부르지 않고 환불 전체가 롤백된다(F1).
     *
     * @effects coupon_restore_failure_stops_the_refund_before_pg
     *
     * @return void 반환값 없음
     */
    public function test_coupon_restore_failure_stops_the_refund_before_pg(): void
    {
        $this->fakePgRefund($calls);
        // 재계산이 적용하지 못하는 쿠폰이 원 주문에 있었다 = 조건 소멸 → 복원 대상.
        [$order, $refund] = $this->approvedDirectRefund(10000, 0, [
            'promotions_applied_snapshot' => ['coupon_issue_ids' => [987654]],
        ]);
        HookManager::addAction('sirsoft-ecommerce.coupon.restore', function () {
            throw new \RuntimeException('쿠폰 잠금 실패');
        }, 5);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(422);

        $this->assertSame([], $calls);
        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
        $this->assertSame(OrderStatusEnum::PREPARING, $order->options()->first()->option_status);
        $this->assertSame(0.0, (float) $order->fresh()->total_refunded_amount);
    }

    /**
     * 재고 복원이 실패하면 PG 를 부르지 않고 환불 전체가 롤백된다(F2).
     *
     * @effects stock_restore_failure_stops_the_refund_before_pg
     *
     * @return void 반환값 없음
     */
    public function test_stock_restore_failure_stops_the_refund_before_pg(): void
    {
        $this->fakePgRefund($calls);
        [$order, $refund] = $this->approvedDirectRefund(10000);
        $order->options()->update(['is_stock_deducted' => true]);
        $this->partialMock(StockService::class, function ($mock) {
            $mock->shouldReceive('restoreOptionStockForOrderOption')->andThrow(new \RuntimeException('재고 잠금 실패'));
        });

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(422);

        $this->assertSame([], $calls);
        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
        $this->assertSame(0.0, (float) $order->fresh()->total_refunded_amount);
    }

    // ================================================================
    // E1 — 결제행 취소 이력 금액 · 결제수단과 다른 수단의 환불 (Q16)
    // ================================================================

    /**
     * 반품 발 환불의 결제행 취소 이력에 실제로 돌려준 금액이 남는다(E1).
     *
     * @effects return_refund_records_the_executed_amount_in_cancel_history
     *
     * @return void 반환값 없음
     */
    public function test_execute_of_a_return_sourced_refund_records_the_executed_amount_in_cancel_history(): void
    {
        $this->fakePgRefund($calls);
        [$order, , $refund] = $this->approvedReturnRefund();

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        $refund->refresh();
        $last = $this->lastCancelHistory($order);
        $this->assertSame((int) $refund->id, (int) $last['refund_id']);
        // 손계산 리터럴(T6) — 29,000 − 반품 편도 회수비 3,000, 마일리지 사용 없음
        $this->assertSame(26000.0, (float) $last['amount']);
        $this->assertSame(0.0, (float) $last['points_amount']);
        $this->assertSame('pg', $last['refund_method']);
        $this->assertTrue($last['settled_on_payment']);

        $payment = $order->payment()->first();
        $settled = array_sum(array_map(
            fn ($h) => ! empty($h['settled_on_payment']) ? (float) ($h['amount'] ?? 0) : 0.0,
            $payment->cancel_history ?? [],
        ));
        $this->assertSame((float) $payment->cancelled_amount, $settled);
    }

    /**
     * 운영자가 실행 시점에 고친 금액이 취소 이력에 남는다(E1).
     *
     * @effects cancel_history_records_the_operator_edited_amount
     *
     * @return void 반환값 없음
     */
    public function test_execute_records_the_operator_edited_amount_in_cancel_history(): void
    {
        $this->fakePgRefund($calls);
        [$order, $refund] = $this->approvedDirectRefund(10000);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute", ['refund_amount' => 7000])
            ->assertStatus(200);

        $this->assertSame(7000.0, (float) $this->lastCancelHistory($order)['amount']);
        $this->assertSame(7000.0, (float) $order->payment()->first()->cancelled_amount);
    }

    /**
     * 신청서에 적힌 마일리지 몫이 취소 이력에 그대로 남는다 — 재계산 배분값이 아니다(E1).
     *
     * @effects cancel_history_records_the_filed_mileage_share
     *
     * @return void 반환값 없음
     */
    public function test_execute_records_the_filed_mileage_share_in_cancel_history(): void
    {
        $this->fakePgRefund($calls);
        [$order, $refund] = $this->approvedDirectRefund(7000, 3000, [
            'total_points_used_amount' => 5000,
            'is_mileage_deducted' => true,
        ]);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        $last = $this->lastCancelHistory($order);
        $this->assertSame(3000.0, (float) $last['points_amount']);
        $this->assertSame(7000.0, (float) $last['amount']);
    }

    /**
     * 카드 주문을 계좌 송금으로 환불하면 결제행은 결제완료로 남고 이력에만 수단과 함께 남는다(Q16).
     *
     * @effects foreign_method_refund_keeps_payment_row_paid
     *
     * @return void 반환값 없음
     */
    public function test_bank_refund_of_a_card_order_keeps_the_payment_row_paid(): void
    {
        $this->fakePgRefund($calls);
        [$order, $refund] = $this->approvedDirectRefund(10000);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute", [
                'refund_method' => 'bank',
                'refund_bank' => ['bank_code' => '004', 'account_number' => '11122233344', 'holder' => '홍길동'],
            ])
            ->assertStatus(200);

        $this->assertSame([], $calls);
        $payment = $order->payment()->first();
        $this->assertSame(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame(0.0, (float) $payment->cancelled_amount);
        $this->assertNull($payment->cancelled_at);

        $last = $this->lastCancelHistory($order);
        $this->assertSame('bank', $last['refund_method']);
        $this->assertFalse($last['settled_on_payment']);
        $this->assertSame(10000.0, (float) $last['amount']);
    }

    // ================================================================
    // Q15 — 실제로 차감되지 않은 마일리지는 반환하지도 장부에 적지도 않는다
    // ================================================================

    /**
     * 사용 마일리지가 실제로 차감된 적 없는 주문의 환불은 마일리지 몫을 0 으로 확정한다 —
     * 반환하지 않고, 신청서·장부·이력에도 0 원으로 남긴다(Q15).
     *
     * @effects mileage_never_deducted_refunds_zero_points
     *
     * @return void 반환값 없음
     */
    public function test_execute_zeroes_points_share_when_order_mileage_was_never_deducted(): void
    {
        $this->fakePgRefund($calls);
        [$order, $refund] = $this->approvedDirectRefund(7000, 3000, [
            'total_points_used_amount' => 5000,
            'is_mileage_deducted' => false,
        ]);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(200);

        $fresh = $refund->fresh();
        $this->assertSame(0.0, (float) $fresh->refund_points_amount);
        $this->assertNull($fresh->mc_refund_points_amount);
        $this->assertSame(0, MileageTransaction::query()->where('order_refund_id', $refund->id)->count());
        $this->assertSame(0.0, (float) $order->fresh()->total_refunded_points_amount);
        $this->assertSame(7000.0, (float) $order->fresh()->total_refunded_amount);
        $this->assertSame(7000.0, (float) $calls[0]['amount']);
        $this->assertSame(0.0, (float) $this->lastCancelHistory($order)['points_amount']);
    }
}
