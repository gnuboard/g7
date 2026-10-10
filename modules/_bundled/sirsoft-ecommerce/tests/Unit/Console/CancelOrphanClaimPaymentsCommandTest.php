<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Console;

use Modules\Sirsoft\Ecommerce\Enums\ClaimPaymentStatusEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\ClaimPayment;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Module;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Services\UserClaimService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 고아 클레임 결제 대기 건 정리 커맨드 테스트 (S6a)
 *
 * 경계 축 — 취소하는 것은 **종결된 신청서에 남았거나 신청서가 사라진** 오래된 결제 대기
 * 건뿐이다. 살아 있는 신청서(결제대기)의 대기 건은 사용자가 결제할 유일한 통로라 보존
 * 기간과 무관하게 남기고, 결제 완료 건과 보존 기간 이내의 대기 건도 건드리지 않는다.
 */
class CancelOrphanClaimPaymentsCommandTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
    }

    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 신청 시점 결제 반품(결제대기 + 결제 대기 건)을 만들고 결제 건 나이를 조정합니다.
     *
     * @return array{return: OrderReturn, payment: ClaimPayment} 신청서와 결제 대기 건
     */
    protected function makePayOnRequestReturn(int $paymentAgeDays): array
    {
        $order = $this->deliveredClaimOrder();

        ['claim' => $return] = app(UserClaimService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => 'pay_on_request',
        ], [], 'card');

        $payment = ClaimPayment::query()->where('payable_id', $return->id)->firstOrFail();
        $payment->forceFill(['created_at' => now()->subDays($paymentAgeDays)])->saveQuietly();

        return ['return' => $return->fresh(), 'payment' => $payment->fresh()];
    }

    public function test_cancels_only_stale_pending_payments_of_settled_or_missing_claims(): void
    {
        // ① 반려된 신청서의 오래된 대기 건 — 취소 대상.
        ['return' => $rejected, 'payment' => $orphan] = $this->makePayOnRequestReturn(3);
        app(OrderReturnService::class)->transition($rejected, ReturnStatusEnum::REJECTED);
        $this->assertTrue($orphan->fresh()->isPending());

        // ② 살아 있는 신청서(결제대기)의 오래된 대기 건 — 사용자가 결제할 통로라 남긴다.
        ['payment' => $active] = $this->makePayOnRequestReturn(3);

        // ③ 반려된 신청서라도 보존 기간 이내의 대기 건은 남긴다.
        ['return' => $recentRejected, 'payment' => $recent] = $this->makePayOnRequestReturn(0);
        app(OrderReturnService::class)->transition($recentRejected, ReturnStatusEnum::REJECTED);

        // ④ 결제 완료 건은 상태 필터 밖이다.
        ['payment' => $paid] = $this->makePayOnRequestReturn(3);
        $paid->forceFill(['payment_status' => ClaimPaymentStatusEnum::PAID, 'paid_at' => now()])->saveQuietly();

        $this->artisan('sirsoft-ecommerce:cancel-orphan-claim-payments')->assertSuccessful();

        $this->assertSame(ClaimPaymentStatusEnum::CANCELLED, $orphan->fresh()->payment_status);
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $active->fresh()->payment_status);
        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $recent->fresh()->payment_status);
        $this->assertSame(ClaimPaymentStatusEnum::PAID, $paid->fresh()->payment_status);
    }

    public function test_cancels_a_stale_pending_payment_whose_claim_row_is_gone(): void
    {
        ['return' => $return, 'payment' => $payment] = $this->makePayOnRequestReturn(3);

        // 신청서 행이 사라진 결제 대기 건 — 되찾을 신청서가 없으므로 취소한다.
        OrderReturn::query()->whereKey($return->id)->delete();

        $this->artisan('sirsoft-ecommerce:cancel-orphan-claim-payments')->assertSuccessful();

        $this->assertSame(ClaimPaymentStatusEnum::CANCELLED, $payment->fresh()->payment_status);
    }

    public function test_dry_run_reports_without_cancelling(): void
    {
        ['return' => $rejected, 'payment' => $orphan] = $this->makePayOnRequestReturn(3);
        app(OrderReturnService::class)->transition($rejected, ReturnStatusEnum::REJECTED);

        $this->artisan('sirsoft-ecommerce:cancel-orphan-claim-payments', ['--dry-run' => true])
            ->expectsOutputToContain('[dry-run] 취소 대상 1건')
            ->assertSuccessful();

        $this->assertSame(ClaimPaymentStatusEnum::PENDING, $orphan->fresh()->payment_status);
    }

    /**
     * 모듈이 이 커맨드를 스케줄로 등록한다 (등록 누락 회귀 차단).
     */
    public function test_command_is_registered_in_module_schedules(): void
    {
        $entry = collect((new Module)->getSchedules())
            ->firstWhere('command', 'sirsoft-ecommerce:cancel-orphan-claim-payments');

        $this->assertNotNull($entry, '고아 결제 대기 건 취소 스케줄이 등록되어야 합니다.');
        $this->assertSame('daily', $entry['schedule']);
    }
}
