<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Services;

use App\Extension\HookManager;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\CancelStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\OrderCancellationException;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderCancellationService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\PaymentMethodResolver;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\AssertsClaimExceptions;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 취소신청서 기록 지점과 자동 전이 엔진 테스트 (#599 후속 §4-2·§4-3)
 *
 * 구매자가 주문 상품 하나를 마이페이지에서 환불했는데 **주문취소 기록만 남고 환불신청서가
 * 없었다**. 결함이 아니라 설계 기본값이었다 — 즉시취소가 환불 행을 이미 완료 상태로
 * INSERT 하므로 신청→승인→완료 단계가 없었고, 그 행은 "취소 탭과 이중 노출된다" 는 이유로
 * 목록에서 배제돼 화면에 나타나지 않았다.
 *
 * 새 모델은 취소와 환불을 **별개 문서**로 본다. 이 테스트가 잠그는 것:
 *
 * - 취소신청서는 **환불이 집행되는 순간** 한 지점(`recordCancellation`)에서만 생긴다
 * - 자동 전이는 단계를 **건너뛰지 않는다**(요청 → 승인 → 완료)
 * - 종착점은 **결제수단**이 가른다(PG·마일리지는 완료, 계좌는 접수에서 정지 — P16)
 * - 잔여 한도가 **두 번 깎이지 않는다** — 2차 부분환불이 0원이 되지 않는다
 * - 환불할 돈이 없으면 0원짜리 환불 레코드를 만들지 않는다(P11)
 * - 미결제 취소도 결제행을 정리해 취소된 주문이 되살아나지 않는다(P4)
 */
class ClaimCancellationRecordTest extends ModuleTestCase
{
    use AssertsClaimExceptions;
    use BuildsClaimFlowOrders;

    protected OrderRefundRequestService $service;

    /**
     * 테스트 픽스처와 환경을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->service = app(OrderRefundRequestService::class);

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true,
            'transaction_id' => 'TEST_REFUND_TXN',
            'error_code' => null,
            'error_message' => null,
        ]);
    }

    /**
     * 테스트가 남긴 상태를 정리합니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 신청 페이로드를 만듭니다.
     *
     * @param  Order  $order  주문
     * @param  int|null  $quantity  수량 (null 이면 전량)
     * @param  int  $index  대상 상품 순번
     * @return array 페이로드
     */
    protected function payload(Order $order, ?int $quantity = null, int $index = 0): array
    {
        $option = $order->options->values()[$index];

        return [
            'items' => [['order_option_id' => $option->id, 'quantity' => $quantity ?? (int) $option->quantity]],
            'reason_code' => 'changed_mind',
            'reason_detail' => '단순 변심',
            'requested_by' => $order->user_id,
        ];
    }

    // ================================================================
    // 취소신청서 생성 시점 (D3)
    // ================================================================

    /**
     * the cancellation record is created when the refund completes 동작을 검증합니다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects cancellation_record_is_created_only_on_refund_completion
     *
     * @return void 반환값 없음
     */
    public function test_the_cancellation_record_is_created_when_the_refund_completes(): void
    {
        $order = $this->paidClaimOrder();

        $result = $this->service->request($order, $this->payload($order));

        $refund = $result['refund'];
        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->refund_status);

        $cancel = OrderCancel::where('order_id', $order->id)->first();
        $this->assertInstanceOf(OrderCancel::class, $cancel);
        $this->assertSame(CancelStatusEnum::COMPLETED, $cancel->cancel_status);
        // 취소신청서 번호의 날짜는 신청일이 아니라 환불완료일이다 — 모델의 당연한 결과다.
        $this->assertNotNull($cancel->cancel_number);
    }

    /**
     * 취소신청서가 **자기를 만든 환불신청서를 가리킨다**. (브라우저 실측 회귀)
     *
     * 취소신청서는 환불이 집행된 바로 그 순간 만들어지므로(D3), 그 환불이 무엇이었는지를
     * 언제나 알 수 있다. 그런데 `order_refund_id` 를 채우지 않아 링크가 끊겨 있었다 —
     * 관리자 취소 상세의 "환불 상태 · 환불 금액" 이 영영 `-` 로 비었고(리소스는 그 관계에서
     * 값을 읽는다), 운영자는 취소 문서만 보고는 **얼마가 어떻게 돌아갔는지 알 수 없어**
     * 환불 탭으로 건너가 같은 주문의 환불을 손으로 찾아야 했다.
     *
     * D6 는 두 문서가 서로를 **대표하지 않는다**고 했지 서로를 **가리키지 않는다**고 하지
     * 않았다 — 별개 문서이기 때문에 오히려 링크가 필요하다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects cancellation_record_links_the_refund_that_created_it
     *
     * @return void 반환값 없음
     */
    public function test_the_cancellation_record_links_the_refund_that_created_it(): void
    {
        $order = $this->paidClaimOrder();

        $result = $this->service->request($order, $this->payload($order));
        $refund = $result['refund'];

        $cancel = OrderCancel::where('order_id', $order->id)->firstOrFail();

        // 링크는 환불 쪽 컬럼이 갖는다 — `OrderCancel::refund()` 가 `order_cancel_id` 로 걸린
        // `hasOne` 이기 때문이다. 방향을 뒤집어 단언하면 없는 컬럼을 보게 된다.
        $this->assertSame(
            $cancel->id,
            $refund->fresh()->order_cancel_id,
            '환불신청서가 자기가 만든 취소신청서를 가리켜야 한다',
        );
        $this->assertNotNull($cancel->fresh()->refund, '관계로도 읽을 수 있어야 한다 — 리소스가 그 관계에서 금액을 읽는다');
        $this->assertSame($refund->refund_number, $cancel->fresh()->refund->refund_number);
    }

    /**
     * 계좌 환불은 **접수(`requested`)에서 멈추고**, 그때는 취소신청서도 생기지 않는다. (P16)
     *
     * 운영자가 송금하기 전에 취소신청서를 만들면 장부는 "돈이 빠졌다" 고 말하는데 실제로는
     * 아직 아무것도 나가지 않은 상태가 된다.
     *
     * 정지 지점이 바뀌었다 — 종전(前D4)에는 계좌 환불도 `approved` 까지 자동으로 갔고 운영자는
     * 승인된 건을 송금만 하면 됐다. P16 이 그것을 대체한다: 사람이 개입해야 돈이 나가는 수단은
     * **승인 자체가 사람의 결정**이므로 자동으로 승인하지 않는다. 자동 승인해 두면 운영자의
     * 대기열에는 "승인됨" 으로 보이는데 아무도 그 건을 검토한 적이 없다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects bank_refund_stops_at_requested_without_cancellation_record
     *
     * @return void 반환값 없음
     */
    public function test_a_bank_refund_stops_at_requested_and_records_no_cancellation_yet(): void
    {
        $order = $this->paidClaimOrder();
        $order->payment->forceFill([
            'payment_method' => 'dbank',
            'pg_provider' => '',
        ])->save();
        $order = $order->fresh(['options', 'payment', 'shippings']);

        $result = $this->service->request($order, $this->payload($order));

        $refund = $result['refund'];
        $this->assertSame(RefundMethodEnum::BANK, $refund->refund_method);
        $this->assertSame(RefundStatusEnum::REQUESTED, $refund->refund_status);
        $this->assertNull($refund->refunded_at);
        $this->assertSame(0, OrderCancel::where('order_id', $order->id)->count());

        // 운영자가 **승인한 뒤** [환불하기]로 송금을 선언한다 — 그때 취소신청서가 생긴다.
        // 두 단계인 것이 P16 의 요지다: 승인이 사람의 검토, 실행이 사람의 송금이다.
        $approved = $this->service->transition($refund->fresh(), RefundStatusEnum::APPROVED);
        $this->service->executeRefund($approved->fresh(['refundOptions']));

        $this->assertSame(1, OrderCancel::where('order_id', $order->id)->count());
    }

    /**
     * 환불할 돈이 없으면 환불신청서를 만들지 않는다 (D1 · P11).
     *
     * 0원짜리 미지급 환불 레코드는 송금할 것이 없어 영영 `approved` 에 남는다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects unpaid_cancel_skips_the_refund_request
     *
     * @return void 반환값 없음
     */
    public function test_an_unpaid_cancellation_skips_the_refund_request(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder(29000, 1, $policy);
        $order = $this->attachPolicyShippings($order, $policy);
        $order->forceFill(['order_status' => OrderStatusEnum::PENDING_PAYMENT])->save();
        $order->options->first()->forceFill(['option_status' => OrderStatusEnum::PENDING_PAYMENT])->save();
        $order->payment?->forceFill(['payment_status' => PaymentStatusEnum::WAITING_DEPOSIT])->save();

        app(EcommerceSettingsService::class)->setSetting('order_settings.cancellable_statuses', [
            OrderStatusEnum::PENDING_ORDER->value,
            OrderStatusEnum::PENDING_PAYMENT->value,
            OrderStatusEnum::PAYMENT_COMPLETE->value,
        ]);

        $order = $order->fresh(['options', 'payment', 'shippings']);

        $result = $this->service->request($order, $this->payload($order));

        $this->assertNull($result['refund']);
        $this->assertInstanceOf(OrderCancel::class, $result['cancel']);
        $this->assertSame(0, OrderRefund::where('order_id', $order->id)->count());

        // P4 — 결제행이 취소로 정리되어야 뒤늦은 입금으로 주문이 되살아나지 않는다.
        $payment = $order->fresh('payment')->payment;
        $this->assertContains(
            $payment->payment_status,
            [PaymentStatusEnum::CANCELLED, PaymentStatusEnum::PARTIAL_CANCELLED],
            '미결제 취소도 결제행을 정리해야 한다 (P4)',
        );
    }

    // ================================================================
    // 잔여 한도 이중 차감 (#599 후속 §12-B)
    // ================================================================

    /**
     * 2차 부분환불이 0원이 되지 않는다.
     *
     * 잔여 환불 한도는 `total_paid_amount − total_refunded_amount` 다. 환불 실행이 환불
     * 누적을 더하는데 취소 기록까지 `total_paid_amount` 를 재계산값으로 덮어쓰면 같은
     * 금액이 두 번 빠진다 — 10만원 주문의 6만원 환불 뒤 잔여가 `4만 − 6만 = 0` 이 되어
     * **2차 부분환불이 0원으로 실행된다**. 그래서 취소 기록은 그 두 컬럼만 건너뛴다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects second_partial_refund_is_not_zeroed_by_double_deduction
     *
     * @return void 반환값 없음
     */
    public function test_a_second_partial_refund_is_not_zeroed_by_double_deduction(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$productA, $optionA] = $this->createClaimProduct(60000, $policy);
        [$productB, $optionB] = $this->createClaimProduct(40000, $policy);

        $order = $this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem(productId: $productA->id, productOptionId: $optionA->id, quantity: 1),
            new CalculationItem(productId: $productB->id, productOptionId: $optionB->id, quantity: 1),
        ]));
        $order = $this->attachPolicyShippings($order, $policy);
        $order = $this->moveOrderTo($order, OrderStatusEnum::PAYMENT_COMPLETE);

        $first = $this->service->request($order, $this->payload($order, 1, 0));
        $this->assertGreaterThan(0, (float) $first['refund']->refund_amount);

        $order = $order->fresh(['options', 'payment', 'shippings']);
        $second = $this->service->request($order, $this->payload($order, 1, 1));

        $this->assertGreaterThan(
            0,
            (float) $second['refund']->refund_amount,
            '2차 부분환불이 0원이면 잔여 한도가 두 번 깎인 것이다 (#599 후속 §12-B)',
        );
    }

    /**
     * 2차 부분환불은 **남은 몫만** 돌려준다 — 이미 돌려준 돈을 다시 세지 않는다.
     *
     * 환불액은 `(원 결제금액) − (남은 상품의 재계산 금액)` 으로 구한다. 그런데 새 회계는
     * 원 결제금액을 깎지 않고 누적으로 말하므로(§12-B B1), 이 식은 **누적 환불 가능액**을
     * 내놓는다 — 2차 신청에서 1차로 이미 나간 돈이 다시 섞여 들어온다.
     *
     * 3개 중 1개씩 두 번 취소하면 각 회차는 단가(20,000)만 돌려주어야 하는데, 빼 주지 않으면
     * 2차가 40,000 을 돌려준다 — 고객이 상품 1개를 가진 채로 **전액을 환불받는다**.
     *
     * 잔여 한도 상한(`remainingPg`)은 이것을 막지 못한다. 그 상한도 같은 40,000 이라
     * 전량 제외(전체취소) 경로에서만 걸리고, 상품이 남는 부분취소에서는 그대로 통과한다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects second_partial_refund_returns_only_the_remaining_share
     *
     * @return void 반환값 없음
     */
    public function test_a_second_partial_refund_returns_only_the_remaining_share(): void
    {
        $policy = $this->createClaimShippingPolicy();
        [$product, $option] = $this->createClaimProduct(20000, $policy);

        $order = $this->createClaimOrder(new CalculationInput(items: [
            new CalculationItem(
                productId: $product->id,
                productOptionId: $option->id,
                quantity: 3,
            ),
        ]));
        $order = $this->attachPolicyShippings($order, $policy);
        $order = $this->moveOrderTo($order, OrderStatusEnum::PAYMENT_COMPLETE);

        $first = $this->service->request($order, $this->payload($order, 1, 0));
        $this->assertSame(20000.0, (float) $first['refund']->refund_amount, '1차는 단가만 환불한다.');

        $order = $order->fresh(['options', 'payment', 'shippings']);
        $remaining = $order->options
            ->first(fn ($o) => $o->option_status !== OrderStatusEnum::CANCELLED);

        $second = $this->service->request($order, [
            'items' => [['order_option_id' => $remaining->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'requested_by' => $order->user_id,
        ]);

        $this->assertSame(
            20000.0,
            (float) $second['refund']->refund_amount,
            '2차도 단가만 환불해야 한다 — 더 크면 이미 돌려준 몫을 다시 돌려주는 것이다.',
        );

        // 두 회차 합이 원 결제금액을 넘지 않는다 (상품 1개는 고객이 계속 갖고 있다).
        $settled = $order->fresh();
        $this->assertLessThanOrEqual(
            (float) $settled->total_paid_amount,
            (float) $settled->total_refunded_amount,
            '누적 환불액이 원 결제금액을 넘었다 — 과다 환불이다.',
        );
    }

    // ================================================================
    // §9-1 DoD — 장부 확정의 단일 지점이 한 번만 돈다
    // ================================================================

    /**
     * 주문 합계 재계산은 **한 번만** 돈다 (DoD 2 — "재계산 지점이 하나뿐임을 테스트로 잠금").
     *
     * 취소 파이프라인이 둘이던 시절에는 같은 취소가 두 경로에서 합계를 건드릴 수 있었고, 그때
     * 재계산이 두 번 돌면 같은 금액이 두 번 빠진다. 지금은 `recordCancellation()` 한 지점이
     * 장부를 확정하므로, 한 번의 접수에 취소 문서가 **한 건**이고 누적 컬럼이 **한 번만** 오른다.
     *
     * 재계산 호출 횟수를 직접 세지 않는 이유는 그것이 내부 구현이기 때문이다 — 두 번 돌면
     * 반드시 드러나는 **결과**(취소 건수·누적액)를 잠근다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects order_ledger_is_settled_exactly_once_per_intake
     *
     * @return void 반환값 없음
     */
    public function test_the_order_ledger_is_settled_exactly_once_per_intake(): void
    {
        $order = $this->paidClaimOrder(30000, 2);

        $outcome = $this->service->request($order, $this->payload($order, 1));

        $this->assertNotNull($outcome['refund']);

        $settled = $order->fresh();
        $cancels = OrderCancel::query()->where('order_id', $order->id)->get();

        $this->assertCount(1, $cancels, '한 번의 접수에 취소 문서가 둘이면 장부가 두 번 확정된 것이다.');
        $this->assertSame(1, (int) $settled->cancellation_count);

        // 누적 취소액은 실제로 집행된 환불액과 같다 — 두 번 더해졌으면 두 배가 된다.
        $this->assertSame(
            (float) $outcome['refund']->fresh()->refund_amount,
            (float) $settled->total_cancelled_amount,
        );
        $this->assertSame(
            (float) $outcome['refund']->fresh()->refund_amount,
            (float) $settled->total_refunded_amount,
        );
    }

    /**
     * 상품 상태는 **출처**가 정한다 (D13).
     *
     * 취소·환불 신청은 상품을 `cancelled` 로 옮기고, 반품·교환은 `returned`·`exchanged` 를
     * 유지한다 — 그 상품들은 취소된 것이 아니라 돌아오거나 바뀐 것이다. 취소신청서는 세
     * 경우 모두 생기지만(환불이 집행됐으므로), 상품 상태까지 취소로 뒤집으면 반품·교환 이력이
     * 사라진다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects item_status_after_a_refund_is_decided_by_the_source
     *
     * @return void 반환값 없음
     */
    public function test_the_item_status_after_a_refund_is_decided_by_the_source(): void
    {
        $order = $this->paidClaimOrder(30000, 1);

        $this->service->request($order, $this->payload($order));

        $this->assertSame(
            OrderStatusEnum::CANCELLED,
            $order->fresh(['options'])->options->first()->option_status,
            '취소·환불 신청은 상품을 취소로 옮긴다.',
        );
    }

    /**
     * PG 가 거절하면 **접수까지 통째로 되돌린다** (D15).
     *
     * 자동 전이는 접수와 같은 트랜잭션에서 돈다. 나누면 PG 거절 시 신청서만 `requested` 로
     * 남아 고객은 접수됐다고 보는데 돈은 나가지 않은 상태가 된다 — 그 어긋남을 아무도 모른다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects pg_rejection_rolls_the_whole_intake_back
     *
     * @return void 반환값 없음
     */
    public function test_a_pg_rejection_rolls_the_whole_intake_back(): void
    {
        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn ($default) => [
            'success' => false,
            'error_code' => 'PG_DECLINED',
            'error_message' => 'PG 가 거절했습니다',
            'transaction_id' => null,
        ]);

        $order = $this->paidClaimOrder(30000, 1);
        $order->payment->update(['payment_method' => 'card', 'pg_provider' => 'testpg']);
        $order = $order->fresh(['options', 'payment', 'shippings']);

        $threw = false;

        try {
            $this->service->request($order, $this->payload($order));
        } catch (OrderCancellationException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'PG 거절은 예외로 올라와야 한다 — 삼키면 접수만 남는다.');

        // 신청서도 취소 문서도 남지 않는다.
        $this->assertSame(0, OrderRefund::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, OrderCancel::query()->where('order_id', $order->id)->count());

        $rolledBack = $order->fresh(['options']);
        $this->assertSame(0.0, (float) $rolledBack->total_refunded_amount);
        $this->assertSame(0, (int) $rolledBack->cancellation_count);
        $this->assertNotSame(
            OrderStatusEnum::CANCELLED,
            $rolledBack->options->first()->option_status,
            '상품이 취소로 남으면 돈은 안 나갔는데 재고만 빠진 상태다.',
        );
    }

    // ================================================================
    // 자동 전이 (§4-2)
    // ================================================================

    /**
     * 자동 전이는 고객에게 알림을 **한 번만** 보낸다.
     *
     * 단계를 실제로 밟으면 접수·승인·완료 세 번의 전이가 일어나는데, 그때마다 알리면 한 번의
     * 취소로 문자 3통이 간다. D16 이 요구한 것은 활동로그이지 알림이 아니다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects auto_advance_sends_one_notification_but_logs_every_step
     *
     * @return void 반환값 없음
     */
    public function test_auto_advance_suppresses_intermediate_notifications(): void
    {
        $order = $this->paidClaimOrder();

        $notified = [];
        $logged = [];

        HookManager::addAction(
            'sirsoft-ecommerce.claim.refund.status_changed',
            function ($refund, $previous = null, $target = null, $context = []) use (&$notified, &$logged) {
                $logged[] = $previous.'->'.$target;

                if (! (bool) ($context['suppress_notification'] ?? false)) {
                    $notified[] = $previous.'->'.$target;
                }
            },
        );

        $this->service->request($order, $this->payload($order));

        $this->assertContains('requested->approved', $logged, '승인 단계가 로그에 남아야 한다');
        $this->assertContains('approved->completed', $logged, '완료 단계가 로그에 남아야 한다');
        $this->assertNotContains('requested->approved', $notified, '중간 전이는 알림을 억제한다');
        $this->assertContains('approved->completed', $notified, '마지막 상태는 알린다');
    }

    /**
     * 자동 전이 1회가 고객에게 보내는 알림은 **접수분까지 합쳐 정확히 1통**이다 (§4-2).
     *
     * 억제를 전이 훅에만 걸면 접수 알림이 그대로 나가 3통이 2통으로 줄 뿐이다 — §4-2 가 적은 것은
     * "마지막 상태에서 1건" 이다. 접수 훅과 전이 훅의 억제 판정이 갈리면 0통(억제했는데 전이하지
     * 않음)이나 2통(억제하지 않았는데 전이함)이 되므로, 두 훅을 **함께** 세어야 그 어긋남이 드러난다.
     *
     * 되돌리면 red — 접수 훅에서 억제 컨텍스트를 빼면 2통이 된다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects auto_advance_sends_exactly_one_customer_notification
     *
     * @return void 반환값 없음
     */
    public function test_auto_advance_sends_exactly_one_customer_notification(): void
    {
        $order = $this->paidClaimOrder();

        $notified = [];

        HookManager::addAction(
            'sirsoft-ecommerce.claim.refund.requested',
            function ($refund, $order = null, $context = []) use (&$notified) {
                if (! (bool) ($context['suppress_notification'] ?? false)) {
                    $notified[] = 'requested';
                }
            },
        );

        HookManager::addAction(
            'sirsoft-ecommerce.claim.refund.status_changed',
            function ($refund, $previous = null, $target = null, $context = []) use (&$notified) {
                if (! (bool) ($context['suppress_notification'] ?? false)) {
                    $notified[] = $previous.'->'.$target;
                }
            },
        );

        $this->service->request($order, $this->payload($order));

        $this->assertSame(
            ['approved->completed'],
            $notified,
            '자동 전이 1회의 고객 알림은 마지막 상태 1통뿐이어야 한다 — 접수 알림이 함께 나가면 2통이다',
        );
    }

    /**
     * **자동승인**(D8)이 켜진 접수도 고객 알림은 1통이다.
     *
     * 자동승인은 자동 전이와 다른 분기지만 구매자가 보는 것은 같다 — 한 번 냈는데 시스템이
     * 접수와 승인 두 단계를 움직인다. 억제를 자동 전이에만 걸면 이 경로가 그물을 빠져나가
     * 접수+승인 2통이 간다. 같은 결함군의 두 경로에 다른 규칙을 적용한 셈이다.
     *
     * 되돌리면 red — 접수 훅의 억제 판정에서 자동승인 축을 빼면 2통이 된다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=request
     *
     * @effects auto_approve_also_sends_exactly_one_notification
     *
     * @return void 반환값 없음
     */
    public function test_auto_approve_sends_exactly_one_customer_notification(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->saveSettings(['claim' => ['auto_approve_refund_request' => true]]);

        $order = $this->paidClaimOrder();
        $this->moveOrderTo($order, OrderStatusEnum::PREPARING);

        $notified = [];

        HookManager::addAction(
            'sirsoft-ecommerce.claim.refund.requested',
            function ($refund, $order = null, $context = []) use (&$notified) {
                if (! (bool) ($context['suppress_notification'] ?? false)) {
                    $notified[] = 'requested';
                }
            },
        );

        HookManager::addAction(
            'sirsoft-ecommerce.claim.refund.status_changed',
            function ($refund, $previous = null, $target = null, $context = []) use (&$notified) {
                if (! (bool) ($context['suppress_notification'] ?? false)) {
                    $notified[] = $previous.'->'.$target;
                }
            },
        );

        $result = $this->service->request($order->fresh(), $this->payload($order->fresh()));

        $this->assertSame(
            RefundStatusEnum::APPROVED,
            $result['refund']->refund_status,
            '자동승인이 켜지고 대상 상태면 승인까지 간다 (D8)',
        );
        $this->assertCount(
            1,
            $notified,
            '자동승인 1회의 고객 알림도 1통이어야 한다 — 접수 알림이 함께 나가면 2통이다',
        );
    }

    /**
     * 사람이 송금해야 끝나는 결제수단(무통장)은 `auto` 분기여도 **접수에서 정지**한다. (P16)
     *
     * 결제완료 상품이라 분기는 `auto` 지만, 자동 전이는 "돈이 실제로 돌아갔다" 를 전제한다 —
     * 무통장은 운영자가 계좌로 보내야 끝나므로 자동으로 완료까지 가면 신청서만 앞서 나가고
     * 고객에게는 "환불 완료" 알림이 가는데 돈은 아직 나가지 않은 상태가 된다. 취소신청서도
     * 환불완료 순간에만 생기므로 이 시점에는 없어야 한다(D3).
     *
     * 고객 알림은 `refund_requested` 1통이 정상이다 — 자동 전이가 없으니 억제할 것도 없다.
     *
     * @scenario gate_case=stopped_method_auto_branch
     *
     * @effects stopped_payment_method_holds_refund_at_requested, stopped_payment_method_sends_one_requested_notification
     *
     * @return void 반환값 없음
     */
    public function test_a_stopped_payment_method_holds_the_refund_at_requested(): void
    {
        $order = $this->paidClaimOrder(paymentOverrides: ['payment_method' => 'dbank']);

        $notified = [];

        HookManager::addAction(
            'sirsoft-ecommerce.claim.refund.requested',
            function ($refund, $order = null, $context = []) use (&$notified) {
                if (! (bool) ($context['suppress_notification'] ?? false)) {
                    $notified[] = 'requested';
                }
            },
        );

        HookManager::addAction(
            'sirsoft-ecommerce.claim.refund.status_changed',
            function ($refund, $previous = null, $target = null, $context = []) use (&$notified) {
                if (! (bool) ($context['suppress_notification'] ?? false)) {
                    $notified[] = $previous.'->'.$target;
                }
            },
        );

        $result = $this->service->request($order, $this->payload($order));

        $this->assertSame(
            RefundStatusEnum::REQUESTED,
            $result['refund']->refund_status,
            '무통장은 결제완료 상품이라도 환불신청에서 멈춰야 한다 (P16)',
        );
        $this->assertSame(
            0,
            OrderCancel::where('order_id', $order->id)->count(),
            '환불이 완료되지 않았으므로 취소신청서는 아직 없다 (D3)',
        );
        $this->assertSame(
            OrderStatusEnum::PAYMENT_COMPLETE,
            $order->fresh()->order_status,
            '송금 전에는 주문을 취소로 닫지 않는다',
        );
        $this->assertSame(
            ['requested'],
            $notified,
            '자동 전이가 없으므로 접수 알림 1통이 정상이다',
        );
    }

    /**
     * 자동승인 토글(D8)을 켜도 정지 수단 주문은 승인까지 가지 않는다. (P16)
     *
     * 세 경로(즉시취소 분기 · 이 토글 · 관리자 [주문취소] 자동 전이)가 같은 결제수단 규칙을
     * 따라야 한다 — 한 경로만 게이트를 지나가면 운영자는 "어떤 건 멈추고 어떤 건 안 멈추는"
     * 이유를 알 수 없다.
     *
     * @scenario gate_case=stopped_method_auto_approve_toggle
     *
     * @effects stopped_payment_method_ignores_auto_approve_toggle
     *
     * @return void 반환값 없음
     */
    public function test_the_auto_approve_toggle_does_not_advance_a_stopped_payment_method(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->saveSettings(['claim' => ['auto_approve_refund_request' => true]]);

        $order = $this->paidClaimOrder(paymentOverrides: ['payment_method' => 'dbank']);
        $this->moveOrderTo($order, OrderStatusEnum::PREPARING);

        $result = $this->service->request($order->fresh(), $this->payload($order->fresh()));

        $this->assertSame(
            RefundStatusEnum::REQUESTED,
            $result['refund']->refund_status,
            '자동승인이 켜져 있어도 정지 수단은 접수에서 멈춘다 (P16)',
        );
    }

    /**
     * 자동 승인 목록에 있는 수단(카드)은 종전대로 완료까지 간다. (P16 비회귀)
     *
     * 게이트를 넣으면서 자동 전이 전체가 막히지 않았는지 — 같은 술어의 반대 방향을 함께 잠근다.
     *
     * @scenario gate_case=allowed_method_auto_branch
     *
     * @effects allowed_payment_method_still_auto_completes
     *
     * @return void 반환값 없음
     */
    public function test_an_allowed_payment_method_still_auto_completes(): void
    {
        $order = $this->paidClaimOrder(paymentOverrides: ['payment_method' => 'card']);

        $result = $this->service->request($order, $this->payload($order));

        $this->assertSame(
            RefundStatusEnum::COMPLETED,
            $result['refund']->refund_status,
            '카드는 PG 가 그 자리에서 환불하므로 완료까지 간다',
        );
        $this->assertSame(
            1,
            OrderCancel::where('order_id', $order->id)->count(),
            '환불완료 순간에 취소신청서가 생긴다 (D3)',
        );
    }

    /**
     * 운영자가 목록에 넣은 수단은 그 순간부터 자동 전이한다. (P17 — 목록이 판정의 SSoT)
     *
     * builtin 파생값이 아니라 **설정 목록**이 게이트를 연다는 것을 잠근다. 파생값만 읽으면
     * 운영자가 아무리 목록을 고쳐도 무통장은 영원히 멈춘다 — 설정 UI 가 장식이 된다.
     *
     * @scenario gate_case=operator_allows_stopped_method
     *
     * @effects auto_approve_list_is_the_single_source_of_truth
     *
     * @return void 반환값 없음
     */
    public function test_the_operator_list_decides_which_methods_auto_advance(): void
    {
        app(EcommerceSettingsService::class)->saveSettings([
            'claim' => ['auto_approve_refund_payment_methods' => ['card', 'point', 'free', 'dbank']],
        ]);
        app(PaymentMethodResolver::class)->flushCache();

        $order = $this->paidClaimOrder(paymentOverrides: ['payment_method' => 'dbank']);

        $result = $this->service->request($order, $this->payload($order));

        $this->assertNotSame(
            RefundStatusEnum::REQUESTED,
            $result['refund']->refund_status,
            '운영자가 목록에 넣은 수단은 더 이상 접수에서 멈추지 않는다 (P17)',
        );
    }

    /**
     * 취소 축 활동로그가 접수·완료 두 줄로 남는다 (D16 · B3·B4).
     *
     * 최종 상태를 바로 INSERT 하면 한 줄만 남아, 운영자는 이 취소가 어떤 경로로 확정됐는지
     * 되짚을 수 없다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects cancellation_record_walks_requested_then_completed
     *
     * @return void 반환값 없음
     */
    public function test_the_cancellation_record_walks_both_steps(): void
    {
        $order = $this->paidClaimOrder();

        $requested = 0;
        $transitions = [];

        HookManager::addAction('sirsoft-ecommerce.claim.cancel.requested', function () use (&$requested) {
            $requested++;
        });
        HookManager::addAction(
            'sirsoft-ecommerce.claim.cancel.status_changed',
            function ($cancel, $previous = null, $target = null) use (&$transitions) {
                $transitions[] = $previous.'->'.$target;
            },
        );

        $this->service->request($order, $this->payload($order));

        $this->assertSame(1, $requested, '취소신청서 생성이 접수 훅으로 남아야 한다');
        $this->assertContains('requested->completed', $transitions, '취소 문서도 단계를 밟아야 한다');
    }

    /**
     * 운영자가 대리 접수한 신청서는 고객이 철회할 수 없다 (§4-3-1).
     *
     * `requested_by` 로는 가릴 수 없다 — 비회원 신청도 그 값이 null 이라 "운영자 생성" 과
     * 구분되지 않는다. 그래서 출처 축(`ADMIN`)이 필요하다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=request
     *
     * @effects admin_filed_refund_is_not_buyer_withdrawable
     *
     * @return void 반환값 없음
     */
    public function test_an_admin_filed_refund_cannot_be_withdrawn_by_the_buyer(): void
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder(29000, 1, $policy);
        $order = $this->moveOrderTo($this->attachPolicyShippings($order, $policy), OrderStatusEnum::PREPARING);

        $result = $this->service->request($order, $this->payload($order) + [
            'refund_source_type' => RefundSourceTypeEnum::ADMIN,
            'user_selectable_reason_only' => false,
        ]);

        $refund = $result['refund'];
        $this->assertSame(RefundSourceTypeEnum::ADMIN, $refund->refund_source_type);

        $this->assertClaimException('claim_not_withdrawable', fn () => $this->service->withdraw($refund->fresh()));
    }

    // ================================================================
    // 결함 2 · E1 — 돈 부수효과 실패는 취소 전체를 되돌린다 / 다른 수단 환불은 결제행을 두고 간다
    // ================================================================

    /**
     * 입금 대기 중인 단일 상품 주문을 만들고, 미결제 취소를 허용 상태로 둡니다.
     *
     * @param  array  $orderOverrides  주문 덮어쓰기
     * @return Order 미결제 주문
     */
    protected function unpaidOrder(array $orderOverrides = []): Order
    {
        $policy = $this->createClaimShippingPolicy();
        $order = $this->createSingleItemClaimOrder(29000, 1, $policy, $orderOverrides + ['currency_snapshot' => $this->krwCurrencySnapshot()]);
        $order = $this->attachPolicyShippings($order, $policy);
        $order->forceFill(['order_status' => OrderStatusEnum::PENDING_PAYMENT])->save();
        $order->options->first()->forceFill(['option_status' => OrderStatusEnum::PENDING_PAYMENT])->save();
        $order->payment?->forceFill(['payment_status' => PaymentStatusEnum::WAITING_DEPOSIT])->save();

        app(EcommerceSettingsService::class)->setSetting('order_settings.cancellable_statuses', [
            OrderStatusEnum::PENDING_ORDER->value,
            OrderStatusEnum::PENDING_PAYMENT->value,
            OrderStatusEnum::PAYMENT_COMPLETE->value,
        ]);

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * 환불 없는 취소(미입금)에서 선차감 마일리지 반환이 실패하면 취소 전체가 롤백된다(D2) —
     * 「취소는 됐는데 마일리지는 영영 안 돌아오는」 상태를 「재시도할 수 있는 실패」로 바꾼다.
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects unpaid_cancel_rolls_back_when_mileage_restore_fails
     *
     * @return void 반환값 없음
     */
    public function test_unpaid_cancel_rolls_back_when_mileage_restore_fails(): void
    {
        $order = $this->unpaidOrder(['total_points_used_amount' => 3000, 'is_mileage_deducted' => true]);
        HookManager::addAction('sirsoft-ecommerce.mileage.restore', function () {
            throw new \RuntimeException('원장 잠금 실패');
        }, 5);

        try {
            $this->service->request($order, $this->payload($order));
            $this->fail('마일리지 반환 실패는 취소를 되돌려야 한다');
        } catch (OrderCancellationException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.mileage_refund_failed', $e->getMessageKey());
        }

        $this->assertSame(0, OrderCancel::query()->where('order_id', $order->id)->count());
        $this->assertSame(OrderStatusEnum::PENDING_PAYMENT, $order->options->first()->fresh()->option_status);
    }

    /**
     * 환불 없는 취소에서 쿠폰 복원이 실패하면 취소 전체가 롤백된다(F1).
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects unpaid_cancel_rolls_back_when_coupon_restore_fails
     *
     * @return void 반환값 없음
     */
    public function test_unpaid_cancel_rolls_back_when_coupon_restore_fails(): void
    {
        $order = $this->unpaidOrder(['promotions_applied_snapshot' => ['coupon_issue_ids' => [987654]]]);
        HookManager::addAction('sirsoft-ecommerce.coupon.restore', function () {
            throw new \RuntimeException('쿠폰 잠금 실패');
        }, 5);

        try {
            $this->service->request($order, $this->payload($order));
            $this->fail('쿠폰 복원 실패는 취소를 되돌려야 한다');
        } catch (OrderCancellationException $e) {
            $this->assertSame('sirsoft-ecommerce::exceptions.coupon_restore_failed', $e->getMessageKey());
        }

        $this->assertSame(0, OrderCancel::query()->where('order_id', $order->id)->count());
    }

    /**
     * 결제수단과 다른 수단으로 돌려준 환불(paymentSettled=false)은 결제행의 상태·취소 시각을
     * 건드리지 않고, 이력에만 수단과 함께 남긴다(Q16·E1).
     *
     * @scenario claim_type=refund, charge_method=none, refund_branch=auto
     *
     * @effects record_cancellation_keeps_the_payment_row_for_a_foreign_method_refund
     *
     * @return void 반환값 없음
     */
    public function test_record_cancellation_keeps_the_payment_row_for_a_foreign_method_refund(): void
    {
        $order = $this->deliveredClaimOrder();
        $refund = OrderRefund::create([
            'order_id' => $order->id,
            'refund_source_type' => RefundSourceTypeEnum::RETURN,
            'refund_number' => 'RF-TEST-'.uniqid(),
            'refund_status' => RefundStatusEnum::COMPLETED,
            'refund_method' => RefundMethodEnum::POINTS,
            'refund_amount' => 29000,
            'refund_points_amount' => 0,
            'refund_shipping_amount' => 0,
        ]);

        app(OrderCancellationService::class)->recordCancellation(
            order: $order->fresh(['options', 'payment', 'shippings']),
            cancelItems: [['order_option_id' => $order->options->first()->id, 'cancel_quantity' => 1]],
            refund: $refund,
            transitionOptions: false,
            syncOrderStatus: false,
            accumulatePayment: false,
            recalculatePaidAmount: false,
            restoreCouponsAndStock: false,
            paymentSettled: false,
        );

        $payment = $order->payment()->first();
        $this->assertSame(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertNull($payment->cancelled_at);
        $last = (array) collect($payment->cancel_history ?? [])->last();
        $this->assertSame('points', $last['refund_method']);
        $this->assertFalse($last['settled_on_payment']);
        $this->assertSame(29000.0, (float) $last['amount']);
    }
}
