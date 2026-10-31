<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Testing\TestResponse;
use Modules\Sirsoft\Ecommerce\DTO\CalculationInput;
use Modules\Sirsoft\Ecommerce\DTO\CalculationItem;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundSourceTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Http\Resources\OrderPaymentResource;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderCancel;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderProcessingService;
use Modules\Sirsoft\Ecommerce\Services\OrderRefundRequestService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 입금을 「기록만」 한 결제 전 주문의 취소 — 환불신청서로 받은 돈을 돌려준다 (S3-D7)
 *
 * 무통장 주문의 [입금확인]에서 「주문을 결제완료로 처리」를 끄면 결제행만 입금완료(`paid`)가 되고 주문은
 * 결제대기·받은 돈 0 으로 남는다. 그 주문을 취소하면 「결제 전이라 돌려줄 돈이 없다」로 판정해 환불신청서 없이
 * 취소만 기록했다 — 받은 돈(10,500원)이 장부 어디에도 남지 않았다(출시본 1.2.1 같은 경로).
 *
 * 받은 돈이 결제행에 기록돼 있으면 결제 전 취소라도 그 금액을 돌려주는 환불신청서를 만든다. 관리자 취소·
 * 구매자 취소·입금기한 만료 자동취소가 모두 같은 접수 지점을 지나므로 같은 규칙을 받는다.
 *
 * 시나리오 매니페스트: `tests/scenarios/recorded-deposit-before-payment-cancel.yaml`
 */
class RecordedDepositBeforePaymentCancelTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $admin;

    /**
     * 통화·사유·취소 가능 상태·마일리지 규칙을 심고 주문 수정·클레임 접수 권한을 가진 관리자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();

        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('order_settings.cancellable_statuses', [
            OrderStatusEnum::PENDING_ORDER->value,
            OrderStatusEnum::PENDING_PAYMENT->value,
            OrderStatusEnum::PAYMENT_COMPLETE->value,
        ]);
        $settings->setSetting('mileage.enabled', true);
        $settings->setSetting('mileage.currency_rules', [
            ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
        ]);

        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.orders.update',
            'sirsoft-ecommerce.claims.create',
        ]);
    }

    /**
     * 테스트가 심은 통화 설정을 지웁니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 결제 전 무통장 주문(상품 5,000 + 7,500 · 마일리지 2,000 사용 · 결제예정 10,500)을 만듭니다.
     *
     * 상품 행·헤더 모두 결제대기이고 결제행은 입금 대기다. 사용 마일리지는 실제 원장에서 차감해 둔다.
     *
     * @param  User  $member  주문 회원
     * @param  Carbon|null  $depositDueAt  입금 기한 (null 이면 사흘 뒤)
     * @return Order 결제 전 주문
     */
    protected function makeUnpaidDbankOrder(User $member, ?Carbon $depositDueAt = null): Order
    {
        $policy = $this->createClaimShippingPolicy();
        [$first, $firstOption] = $this->createClaimProduct(5000, $policy);
        [$second, $secondOption] = $this->createClaimProduct(7500, $policy);

        $order = $this->createClaimOrder(
            new CalculationInput(
                items: [
                    new CalculationItem(productId: $first->id, productOptionId: $firstOption->id, quantity: 1),
                    new CalculationItem(productId: $second->id, productOptionId: $secondOption->id, quantity: 1),
                ],
                usePoints: 2000,
            ),
            [
                'user_id' => $member->id,
                'order_status' => OrderStatusEnum::PENDING_PAYMENT,
                'total_paid_amount' => 0,
                'total_due_amount' => 10500,
                'paid_at' => null,
                'is_mileage_deducted' => true,
                'currency_snapshot' => $this->krwCurrencySnapshot(),
            ],
            [
                'payment_method' => 'dbank',
                'pg_provider' => '',
                'payment_status' => PaymentStatusEnum::READY,
                'paid_at' => null,
                'paid_amount_local' => 0,
                // 실주문은 결제행을 만들 때부터 결제예정금액을 기준 통화 수납액 칸에 적어 둔다.
                'paid_amount_base' => 10500,
                'deposit_due_at' => $depositDueAt ?? Carbon::now()->addDays(3),
            ],
        );

        foreach ($order->options as $option) {
            $option->forceFill(['option_status' => OrderStatusEnum::PENDING_PAYMENT])->save();
        }

        $this->grantMileage($member->id, 2000);
        app(UserMileageService::class)->deductFifo($member->id, 2000, 'KRW', $order);

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * [입금확인]에서 「주문을 결제완료로 처리」를 끈 채 입금을 기록합니다. (실제 서비스 경로)
     *
     * @param  Order  $order  결제 전 주문
     * @return Order 입금이 기록된 주문 (결제대기 유지)
     */
    protected function recordDepositOnly(Order $order): Order
    {
        app(OrderProcessingService::class)->confirmManualDeposit($order, 10500, '홍길동', false);

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * 입금이 기록된 결제 전 주문을 만듭니다. (재현 Q16 — 결제대기 · 결제행 입금완료 · 받은 돈 0)
     *
     * @param  User|null  $member  주문 회원 (null 이면 새로 만든다)
     * @param  Carbon|null  $depositDueAt  입금 기한
     * @return Order 입금이 기록된 결제 전 주문
     */
    protected function makeDepositRecordedOrder(?User $member = null, ?Carbon $depositDueAt = null): Order
    {
        $order = $this->recordDepositOnly($this->makeUnpaidDbankOrder($member ?? $this->createUser(), $depositDueAt));

        // 재현 전제 — 결제행은 입금완료인데 주문은 결제대기·받은 돈 0 이다.
        $this->assertSame(OrderStatusEnum::PENDING_PAYMENT, $order->order_status);
        $this->assertSame(PaymentStatusEnum::PAID, $order->payment->payment_status);
        $this->assertEquals(10500, (float) $order->payment->paid_amount_local);
        $this->assertEquals(0, (float) $order->total_paid_amount);

        return $order;
    }

    /**
     * 관리자 [취소·환불 접수]를 부릅니다. (주문 상세 취소 창이 쓰는 경로)
     *
     * @param  Order  $order  주문
     * @param  array<string, mixed>  $body  요청 본문
     * @return TestResponse 응답
     */
    protected function adminCancel(Order $order, array $body)
    {
        return $this->actingAs($this->admin)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel-refund", $body);
    }

    /**
     * 환불신청서를 승인한 뒤 [환불하기]로 실행합니다. (운영자가 계좌로 송금한 뒤의 완료 처리)
     *
     * @param  OrderRefund  $refund  접수된 환불신청서
     * @return OrderRefund 실행된 환불신청서
     */
    protected function approveAndExecute(OrderRefund $refund): OrderRefund
    {
        $service = app(OrderRefundRequestService::class);
        $approved = $service->transition($refund->fresh(), RefundStatusEnum::APPROVED, ['processed_by' => $this->admin->id]);

        return $service->executeRefund($approved->fresh(), [], $this->admin->id);
    }

    /**
     * 관리자 전체취소는 받은 돈(10,500) + 사용 마일리지(2,000)를 돌려주는 계좌 환불신청서를 만들고, 운영자가
     * [환불하기]를 누르면 주문·결제행·환불 누적이 한 번씩만 정리된다.
     *
     * @effects recorded_deposit_cancel_creates_bank_refund_request, recorded_deposit_refund_executes_once
     *
     * @return void 반환값 없음
     */
    public function test_admin_full_cancel_files_a_bank_refund_for_the_recorded_deposit(): void
    {
        $order = $this->makeDepositRecordedOrder();

        $this->adminCancel($order, [
            'type' => 'full',
            'reason' => 'changed_mind',
            'refund_bank' => ['bank_code' => '004', 'account_number' => '123-456-789', 'holder' => '홍길동'],
        ])->assertStatus(201)->assertJsonPath('data.cancelled', false);

        $refunds = OrderRefund::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $refunds, '받은 돈이 있는 취소는 환불신청서를 거쳐야 한다');
        $refund = $refunds->first();
        $this->assertSame(RefundStatusEnum::REQUESTED, $refund->refund_status);
        $this->assertSame(RefundMethodEnum::BANK, $refund->refund_method);
        $this->assertEquals(10500, (float) $refund->refund_amount);
        $this->assertEquals(2000, (float) $refund->refund_points_amount);
        $this->assertSame('123-456-789', $refund->refund_bank_account);

        // 접수만으로는 취소가 확정되지 않는다 — 운영자가 송금하고 [환불하기]를 눌러야 한다.
        $this->assertSame(0, OrderCancel::query()->where('order_id', $order->id)->count());
        $order = $order->fresh(['payment']);
        $this->assertSame(OrderStatusEnum::PENDING_PAYMENT, $order->order_status);
        // 받은 돈은 주문 장부에도 받은 돈으로 올라온다 — 환불 한도(받은 돈 − 환불 누적)의 근거다.
        $this->assertEquals(10500, (float) $order->total_paid_amount);
        $this->assertEquals(0, (float) $order->total_due_amount);

        $executed = $this->approveAndExecute($refund);

        $this->assertSame(RefundStatusEnum::COMPLETED, $executed->refund_status);
        $order = $order->fresh(['payment']);
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(10500, (float) $order->total_paid_amount);
        $this->assertEquals(10500, (float) $order->total_refunded_amount);
        $this->assertEquals(2000, (float) $order->total_refunded_points_amount);
        $this->assertEquals(0, (float) $order->total_due_amount);
        $this->assertSame(PaymentStatusEnum::CANCELLED, $order->payment->payment_status);
        $this->assertEquals(10500, (float) $order->payment->cancelled_amount);

        $cancels = OrderCancel::query()->where('order_id', $order->id)->get();
        $this->assertCount(1, $cancels);
        $this->assertSame($cancels->first()->id, (int) $executed->fresh()->order_cancel_id);
    }

    /**
     * 취소 창의 환불 예정액은 받은 돈까지 포함하고, 계좌 칸을 세울 근거(돌려줄 현금 있음)를 결제 리소스가 싣는다.
     *
     * @effects recorded_deposit_preview_includes_received_cash, recorded_deposit_payment_resource_reports_cash_to_return
     *
     * @return void 반환값 없음
     */
    public function test_cancel_preview_and_payment_resource_count_the_recorded_deposit(): void
    {
        $order = $this->makeDepositRecordedOrder();

        $this->actingAs($this->admin)
            ->postJson("/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/estimate-refund", [
                'items' => $order->options->map(fn ($option) => ['order_option_id' => $option->id, 'cancel_quantity' => 1])->values()->all(),
            ])
            ->assertOk()
            ->assertJsonPath('data.refund_amount', fn ($amount) => (float) $amount === 10500.0)
            ->assertJsonPath('data.refund_points_amount', fn ($amount) => (float) $amount === 2000.0);

        // 미리보기는 DB 를 바꾸지 않는다.
        $this->assertEquals(0, (float) $order->fresh()->total_paid_amount);

        $resource = (new OrderPaymentResource($order->payment))->toArray(Request::create('/'));
        $this->assertTrue($resource['has_cash_to_return']);
        $this->assertFalse($resource['auto_approves_refund']);
    }

    /**
     * 계좌 환불이 될 취소를 계좌 없이 접수하면 결제 완료된 무통장 주문과 같이 계좌를 요구한다.
     *
     * @effects recorded_deposit_cancel_requires_refund_bank
     *
     * @return void 반환값 없음
     */
    public function test_admin_cancel_without_a_refund_bank_is_rejected(): void
    {
        $order = $this->makeDepositRecordedOrder();

        $this->adminCancel($order, ['type' => 'full', 'reason' => 'changed_mind'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['refund_bank.bank_code'], 'errors');

        $this->assertSame(0, OrderRefund::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, OrderCancel::query()->where('order_id', $order->id)->count());
        $this->assertEquals(0, (float) $order->fresh()->total_paid_amount);
    }

    /**
     * 구매자가 마이페이지에서 취소해도 같은 규칙으로 환불신청서가 생긴다(본인이 철회·수정할 수 있는 출처).
     *
     * @effects recorded_deposit_cancel_creates_bank_refund_request
     *
     * @return void 반환값 없음
     */
    public function test_buyer_cancel_files_a_refund_for_the_recorded_deposit(): void
    {
        $member = $this->createUser();
        $order = $this->makeDepositRecordedOrder($member);

        $this->actingAs($member)
            ->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/cancel", ['reason' => 'changed_mind'])
            ->assertOk();

        $refund = OrderRefund::query()->where('order_id', $order->id)->sole();
        $this->assertSame(RefundSourceTypeEnum::DIRECT, $refund->refund_source_type);
        $this->assertSame(RefundStatusEnum::REQUESTED, $refund->refund_status);
        $this->assertSame(RefundMethodEnum::BANK, $refund->refund_method);
        $this->assertEquals(10500, (float) $refund->refund_amount);
        $this->assertEquals(2000, (float) $refund->refund_points_amount);
        $this->assertSame(0, OrderCancel::query()->where('order_id', $order->id)->count());
    }

    /**
     * 입금 기한이 지나 자동취소돼도 환불신청서가 생기고, 다음 실행은 같은 주문을 다시 집지 않는다.
     *
     * 환불신청서가 열려 있는 동안 주문은 결제대기로 남는다 — 다시 집으면 수량 잠금에 걸려 실행마다
     * 실패로 기록되고 종료 코드가 실패가 된다.
     *
     * @effects recorded_deposit_auto_cancel_creates_refund_once
     *
     * @return void 반환값 없음
     */
    public function test_expired_auto_cancel_files_a_refund_once(): void
    {
        $order = $this->makeDepositRecordedOrder(null, Carbon::now()->subDay());

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')->assertExitCode(0);

        $refund = OrderRefund::query()->where('order_id', $order->id)->sole();
        $this->assertSame(RefundSourceTypeEnum::ADMIN, $refund->refund_source_type);
        $this->assertSame(RefundMethodEnum::BANK, $refund->refund_method);
        $this->assertEquals(10500, (float) $refund->refund_amount);
        $this->assertEquals(2000, (float) $refund->refund_points_amount);
        $this->assertSame(0, OrderCancel::query()->where('order_id', $order->id)->count());

        $this->artisan('sirsoft-ecommerce:cancel-pending-orders')->assertExitCode(0);

        $this->assertSame(1, OrderRefund::query()->where('order_id', $order->id)->count());
    }

    /**
     * 부분취소는 빠지는 상품 몫만 돌려주고, 남은 상품을 뒤에 취소하면 나머지만 돌려준다 — 합계가 받은 돈을 넘지 않는다.
     *
     * @effects recorded_deposit_partial_cancel_refunds_share_only, recorded_deposit_refund_executes_once
     *
     * @return void 반환값 없음
     */
    public function test_partial_cancels_refund_each_share_without_double_refund(): void
    {
        $order = $this->makeDepositRecordedOrder();
        $bank = ['bank_code' => '004', 'account_number' => '123-456-789', 'holder' => '홍길동'];
        $cheaper = $order->options->sortBy('unit_price')->first();

        $this->adminCancel($order, [
            'type' => 'partial',
            'reason' => 'changed_mind',
            'items' => [['order_option_id' => $cheaper->id, 'cancel_quantity' => 1]],
            'refund_bank' => $bank,
        ])->assertStatus(201);

        $first = OrderRefund::query()->where('order_id', $order->id)->sole();
        $firstTotal = (float) $first->refund_amount + (float) $first->refund_points_amount;
        $this->assertGreaterThan(0, $firstTotal);
        $this->assertEquals(5000, $firstTotal, '빠지는 상품(5,000원) 몫만 돌려준다');
        $this->approveAndExecute($first);

        $order = $order->fresh(['options', 'payment']);
        $this->assertSame(OrderStatusEnum::PENDING_PAYMENT, $order->order_status);
        $this->assertSame(PaymentStatusEnum::PARTIAL_CANCELLED, $order->payment->payment_status);
        $this->assertEquals(0, (float) $order->total_due_amount);

        $this->adminCancel($order, ['type' => 'full', 'reason' => 'changed_mind', 'refund_bank' => $bank])->assertStatus(201);

        $second = OrderRefund::query()->where('order_id', $order->id)->where('id', '!=', $first->id)->sole();
        $this->approveAndExecute($second);

        $order = $order->fresh(['payment']);
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(10500, (float) $order->total_refunded_amount, '현금 환불 합계는 받은 돈을 넘지 않는다');
        $this->assertEquals(2000, (float) $order->total_refunded_points_amount);
        $this->assertEquals(10500, (float) $order->payment->cancelled_amount);
        $this->assertSame(PaymentStatusEnum::CANCELLED, $order->payment->payment_status);
        $this->assertEquals(
            12500,
            (float) $first->fresh()->refund_amount + (float) $first->fresh()->refund_points_amount
                + (float) $second->fresh()->refund_amount + (float) $second->fresh()->refund_points_amount,
        );
    }

    /**
     * 입금이 기록되지 않은 결제 전 주문의 취소는 종전대로 환불신청서 없이 취소만 남긴다. (회귀 가드)
     *
     * @effects unrecorded_deposit_cancel_skips_refund_request
     *
     * @return void 반환값 없음
     */
    public function test_cancel_without_a_recorded_deposit_still_skips_the_refund(): void
    {
        $order = $this->makeUnpaidDbankOrder($this->createUser());

        $this->adminCancel($order, ['type' => 'full', 'reason' => 'changed_mind'])
            ->assertStatus(201)
            ->assertJsonPath('data.cancelled', true);

        $this->assertSame(0, OrderRefund::query()->where('order_id', $order->id)->count());
        $this->assertSame(1, OrderCancel::query()->where('order_id', $order->id)->count());
        $order = $order->fresh(['payment']);
        $this->assertSame(OrderStatusEnum::CANCELLED, $order->order_status);
        $this->assertEquals(0, (float) $order->total_paid_amount);
        $this->assertEquals(0, (float) $order->total_due_amount);
        $this->assertSame(PaymentStatusEnum::CANCELLED, $order->payment->payment_status);

        $resource = (new OrderPaymentResource($order->payment))->toArray(Request::create('/'));
        $this->assertFalse($resource['has_cash_to_return']);
    }
}
