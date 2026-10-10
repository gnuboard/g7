<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Extension\HookManager;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\OrderStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 [환불하기]의 환불 계좌 게이트 (T2-D6 · #599 3차 실측 L-8)
 *
 * 무통장입금처럼 **운영자가 손으로 송금해야** 끝나는 환불은 보낼 계좌가 있어야 한다. 그런데
 * 실행 경로는 계좌를 아예 보지 않았다 — 10,000원을 무통장으로 받은 주문의 환불을 계좌 세 칸이
 * 빈 채로 [환불하기] 하면 **돈은 한 푼도 나가지 않았는데 `completed` 로 닫혔고**, 그 뒤에는
 * 되돌릴 경로도 계좌를 적을 자리도 없었다(2026-09-22 실측).
 *
 * 세 가지를 함께 잠근다.
 *
 *  ⓐ **계좌가 없으면 막는다** — 422. 장부만 닫히는 길을 없앤다.
 *  ⓑ **그 자리에서 계좌를 적을 수 있다** — 실행 본문의 `refund_bank` 를 신청서에 저장한 뒤
 *     실행한다. 막기만 하면 운영자는 계좌를 넣을 화면이 없어 영영 끝내지 못한다.
 *  ⓒ **직권으로 넘길 수 있되 동의를 받는다**(운영 결정 2026-09-23) — `force_without_refund_bank`
 *     가 참이면 계좌 없이도 실행한다. 이미 다른 경로로 송금을 마쳤거나 채권을 포기하는 등
 *     운영상 필요가 있기 때문이며, 모르고 눌러 장부가 닫히는 것과 **알고 누르는 것**을 가른다.
 *
 * 판정은 신청 화면·구매자 접수와 **같은 술어**(`OrderRefundRequestService::refundsToBankAccount()`)
 * 를 쓴다. 다른 값으로 재현하면 구매자는 계좌를 내야 접수되는데 운영자는 없이 실행할 수 있는
 * (또는 그 반대의) 상태가 오류 없이 생긴다.
 */
class AdminRefundExecuteRequiresBankTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $admin;

    /**
     * 통화·사유를 심고 실행 권한을 가진 운영자를 만듭니다.
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
            'sirsoft-ecommerce.claims.execute-refund',
            'sirsoft-ecommerce.orders.update',
        ]);

        HookManager::addFilter('sirsoft-ecommerce.payment.refund', fn () => [
            'success' => true, 'transaction_id' => 'PGTXN-1', 'error_code' => null, 'error_message' => null,
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
     * 관리자 API 의 접두 경로를 돌려줍니다.
     *
     * @return string API 접두 경로
     */
    protected function base(): string
    {
        return '/api/modules/sirsoft-ecommerce/admin';
    }

    /**
     * 승인까지 간 직접 환불신청서를 만듭니다.
     *
     * 계좌 축이므로 결제수단을 갈아 끼울 수 있어야 한다 — 무통장(`dbank`)이 본 축이고
     * 카드(`card`)가 대조군이다.
     *
     * @param  string  $paymentMethod  결제수단 코드
     * @param  string  $bankCode  신청서에 이미 기록된 환불 은행 코드 (빈 문자열이면 미기록)
     * @param  array  $orderOverrides  주문에 덮어쓸 값 (마일리지 사용 축 등)
     * @return array{0: Order, 1: OrderRefund} 주문 + 승인된 환불신청서
     */
    protected function approvedRefund(string $paymentMethod = 'dbank', string $bankCode = '', array $orderOverrides = []): array
    {
        $order = $this->deliveredClaimOrder(orderOverrides: $orderOverrides);
        $order = $this->moveOrderTo($order, OrderStatusEnum::PREPARING);
        $order->payment?->forceFill([
            'payment_method' => $paymentMethod,
            'pg_provider' => $paymentMethod === 'card' ? 'testpg' : '',
        ])->save();
        $order = $order->fresh(['options', 'payment', 'shippings']);

        $refund = $this->makeDirectRefund($order, $order->options->first(), 1, RefundStatusEnum::APPROVED);
        $refund->update([
            'refund_amount' => 10000,
            'refund_points_amount' => 0,
            'refund_method' => $paymentMethod === 'card' ? RefundMethodEnum::PG : RefundMethodEnum::BANK,
            'reason_code' => 'changed_mind',
            'refund_bank_code' => $bankCode,
            'refund_bank_account' => $bankCode !== '' ? '11122233344' : '',
            'refund_bank_holder' => $bankCode !== '' ? '홍길동' : '',
        ]);

        return [$order, $refund->fresh(['refundOptions'])];
    }

    /**
     * 계좌가 없는 계좌 환불은 실행이 422 로 막히고 상태가 그대로 남는지 검증합니다.
     *
     * @return void 반환값 없음
     *
     * @scenario gate_case=stopped_method_admin_auto_advance
     *
     * @effects admin_refund_execution_requires_a_bank_account
     */
    public function test_execution_is_blocked_when_the_bank_account_is_missing(): void
    {
        [, $refund] = $this->approvedRefund('dbank');

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertStatus(422)
            ->assertJsonValidationErrors('refund_bank');

        $this->assertSame(
            RefundStatusEnum::APPROVED,
            $refund->fresh()->refund_status,
            '계좌 없이 환불완료가 찍혔다 — 돈은 나가지 않았는데 장부만 닫힌다',
        );
    }

    /**
     * 실행 본문에 실은 계좌가 신청서에 저장되고 실행이 끝나는지 검증합니다.
     *
     * 막기만 하고 적을 자리를 주지 않으면 그 건은 어느 화면에서도 끝낼 수 없다.
     *
     * @return void 반환값 없음
     *
     * @scenario gate_case=stopped_method_admin_auto_advance
     *
     * @effects admin_refund_execution_records_the_bank_account_it_was_given
     */
    public function test_execution_saves_the_bank_account_sent_with_it(): void
    {
        [, $refund] = $this->approvedRefund('dbank');

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute", [
                'refund_bank' => [
                    'bank_code' => '004',
                    'account_number' => '11122233344',
                    'holder' => '홍길동',
                ],
            ])
            ->assertOk();

        $fresh = $refund->fresh();
        $this->assertSame(RefundStatusEnum::COMPLETED, $fresh->refund_status);
        $this->assertSame('004', $fresh->refund_bank_code, '실행하며 적은 계좌가 신청서에 남지 않았다');
        $this->assertSame('11122233344', $fresh->refund_bank_account);
        $this->assertSame('홍길동', $fresh->refund_bank_holder);
    }

    /**
     * 동의를 실으면 계좌 없이도 직권 실행이 되는지 검증합니다. (운영 결정 2026-09-23)
     *
     * @return void 반환값 없음
     *
     * @scenario gate_case=stopped_method_admin_auto_advance
     *
     * @effects admin_refund_execution_allows_forcing_without_a_bank_account
     */
    public function test_execution_passes_without_an_account_when_the_operator_consents(): void
    {
        [, $refund] = $this->approvedRefund('dbank');

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute", [
                'force_without_refund_bank' => true,
            ])
            ->assertOk();

        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->fresh()->refund_status);
    }

    /**
     * 대조군 — 결제한 곳으로 되돌아가는 환불은 계좌를 묻지 않는다.
     *
     * 게이트를 결제수단과 무관하게 걸면 카드 환불이 영영 막힌다.
     *
     * @return void 반환값 없음
     *
     * @scenario gate_case=allowed_method_admin_auto_advance
     *
     * @effects admin_refund_execution_requires_a_bank_account
     */
    public function test_pg_refund_needs_no_bank_account(): void
    {
        [, $refund] = $this->approvedRefund('card');

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertOk();

        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->fresh()->refund_status);
    }

    /**
     * 대조군 — 이미 계좌가 기록된 건은 본문 없이도 실행된다.
     *
     * @return void 반환값 없음
     *
     * @scenario gate_case=stopped_method_admin_auto_advance
     *
     * @effects admin_refund_execution_requires_a_bank_account
     */
    public function test_recorded_account_is_enough(): void
    {
        [, $refund] = $this->approvedRefund('dbank', '004');

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertOk();

        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->fresh()->refund_status);
    }

    /**
     * 대조군 — 마일리지로만 돌려주는 환불은 계좌를 묻지 않는다.
     *
     * 돈이 회원 원장으로 들어가 **보낼 계좌가 없다**. 이 축을 빼고 게이트를 걸었더니 무통장
     * 주문의 포인트 환급이 전부 422 가 됐다(`ClaimRefundExecutionTest` 2건이 red 로 잡아 줬다)
     * — 계좌가 필요한 조건은 「계좌로 환불되는 주문인가」만이 아니라 **「계좌로 나갈 현금이
     * 있는가」**를 함께 봐야 한다.
     *
     * @return void 반환값 없음
     *
     * @scenario gate_case=stopped_method_admin_auto_advance
     *
     * @effects admin_refund_execution_requires_a_bank_account
     */
    public function test_points_only_refund_needs_no_bank_account(): void
    {
        // 포인트로 환급하려면 원주문이 실제로 마일리지를 썼어야 한다 — 상한(D19)이 그것을 본다.
        [, $refund] = $this->approvedRefund('dbank', '', [
            'total_points_used_amount' => 5000,
            'is_mileage_deducted' => true,
        ]);
        $refund->update([
            'refund_amount' => 0,
            'refund_points_amount' => 3000,
            'refund_method' => RefundMethodEnum::POINTS,
        ]);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertOk();

        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->fresh()->refund_status);
    }

    /**
     * 무통장 주문의 현금 환불을 「마일리지로 환불」하면 계좌 없이 실행되고, 현금 환불액이 회원
     * 마일리지로 지급되며 결제행은 결제완료로 남는지 검증합니다. (refund 274 재현 — D1·Q3)
     *
     * @return void 반환값 없음
     *
     * @effects points_refund_of_cash_needs_no_bank_account_and_credits_mileage
     */
    public function test_points_refund_of_cash_needs_no_bank_account_and_credits_mileage(): void
    {
        app(EcommerceSettingsService::class)->setSetting('mileage.enabled', true);
        [$order, $refund] = $this->approvedRefund('dbank');
        $refund->update(['refund_method' => RefundMethodEnum::POINTS]);

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute")
            ->assertOk();

        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->fresh()->refund_status);
        $this->assertSame(10000.0, (float) MileageTransaction::query()
            ->where('order_refund_id', $refund->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_CREDIT->value)
            ->sum('amount'));

        $payment = $order->payment()->first();
        $this->assertSame(PaymentStatusEnum::PAID, $payment->payment_status);
        $this->assertSame(0.0, (float) $payment->cancelled_amount);
    }

    /**
     * 세 칸 중 일부만 실은 계좌는 거절되고 신청서를 건드리지 않는지 검증합니다.
     *
     * 은행만 바뀌고 계좌번호가 옛 값으로 남으면 운영자가 없는 계좌로 이체한다 —
     * 접수·수정 경로와 같은 등식이다.
     *
     * @return void 반환값 없음
     *
     * @scenario gate_case=stopped_method_admin_auto_advance
     *
     * @effects admin_refund_execution_records_the_bank_account_it_was_given
     */
    public function test_partly_filled_account_is_rejected(): void
    {
        [, $refund] = $this->approvedRefund('dbank');

        $this->actingAs($this->admin)
            ->postJson($this->base()."/refunds/{$refund->id}/execute", [
                'refund_bank' => ['bank_code' => '004'],
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('refund_bank');

        $this->assertSame(RefundStatusEnum::APPROVED, $refund->fresh()->refund_status);
    }
}
