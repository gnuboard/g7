<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Models\ActivityLog;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\Order;
use Modules\Sirsoft\Ecommerce\Services\ClaimPaymentService;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 자동 전이의 활동 로그 작업자는 **시스템**이다 (S1-D16)
 *
 * 활동 로그의 작업자는 코어 `ActivityLogProcessor` 가 `user_id ??= Auth::id()` 로 채운다 —
 * 훅이 큐로 가면 발화 시점의 `Auth::id()` 를 `HookContextCapture` 가 실어 간다. 그래서 신청서의
 * `processed_by` 를 비우는 것만으로는 작업자가 바뀌지 않는다. 자동 전이는 고객 요청 안에서
 * 돌기 때문에 로그인한 고객이 그대로 작업자로 남았다.
 *
 * 회귀 배경 (S1-D16, 2026-10-07 실측): 환불 자동 진행 켜짐 · 고객이 결제완료 주문을 취소 →
 * 「환불신청 → 승인 → 완료」 활동 로그 작업자가 전부 고객. 결제 뒤 자동 승인도 고객.
 *
 * 접수 줄은 고객이 낸 신청이므로 고객 그대로다 — 갈리는 것은 그 뒤 자동 전이뿐이다.
 */
class AutoTransitionActorTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private User $member;

    /**
     * 통화·사유·회원을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->member = $this->createUser();
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
     * 주문을 이 회원의 것으로 돌립니다.
     *
     * @param  Order  $order  주문
     * @return Order 회원 주문
     */
    private function own(Order $order): Order
    {
        $order->forceFill(['user_id' => $this->member->id])->save();

        return $order->fresh(['options', 'payment', 'shippings']);
    }

    /**
     * 한 action 의 작업자 목록을 돌려줍니다.
     *
     * @param  string  $action  활동 로그 action
     * @return array<int, int|null> 작업자 ID 목록
     */
    private function actorsOf(string $action): array
    {
        return ActivityLog::where('action', $action)->orderBy('id')->pluck('user_id')->all();
    }

    /**
     * 고객이 낸 취소의 자동 진행(승인·완료)은 작업자가 시스템이다.
     *
     * @scenario actor=auto_approve
     *
     * @effects auto_transition_activity_log_actor_is_system
     *
     * @return void 반환값 없음
     */
    public function test_customer_cancel_auto_advance_is_logged_as_system(): void
    {
        // 마일리지로 전액 낸 주문 — 돌려줄 현금이 없어 계좌 없이 자동으로 끝까지 간다(실측과 같은 조건).
        $order = $this->mileageOnlyPaidOrder($this->member, PaymentMethodEnum::DBANK->value);
        ActivityLog::query()->delete();

        $this->actingAs($this->member)->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/refund-requests", [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
        ])->assertCreated()->assertJsonPath('data.branch', 'auto');

        $this->assertSame([$this->member->id], $this->actorsOf('claim_refund.request'), '접수 줄은 고객이 낸 신청이다.');

        $transitions = $this->actorsOf('claim_refund.status_change');
        $this->assertNotEmpty($transitions, '자동 진행 전이가 기록되지 않았다.');
        $this->assertSame(array_fill(0, count($transitions), null), $transitions, '사람이 누르지 않은 전이에 고객이 작업자로 남았다.');
    }

    /**
     * 청구액 0원 자동 승인은 작업자가 시스템이다.
     *
     * @scenario actor=auto_approve
     *
     * @effects auto_transition_activity_log_actor_is_system
     *
     * @return void 반환값 없음
     */
    public function test_zero_charge_auto_approval_is_logged_as_system(): void
    {
        app(EcommerceSettingsService::class)->setSetting('claim.auto_approve_zero_charge', true);
        $order = $this->own($this->deliveredClaimOrder());
        ActivityLog::query()->delete();

        $this->actingAs($this->member);
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'defective_product',
            'requested_by' => $this->member->id,
        ]);

        $this->assertSame(ReturnStatusEnum::APPROVED, $return->fresh()->return_status);
        $this->assertSame([$this->member->id], $this->actorsOf('claim_return.request'));
        $this->assertSame([null], $this->actorsOf('claim_return.status_change'), '0원 자동 승인에 고객이 작업자로 남았다.');
    }

    /**
     * 결제 뒤 자동 승인은 작업자가 시스템이다 — 결제 확정(`paid`)은 고객이 한 일이라 고객 그대로다.
     *
     * @scenario actor=auto_approve
     *
     * @effects auto_transition_activity_log_actor_is_system
     *
     * @return void 반환값 없음
     */
    public function test_auto_approval_after_payment_is_logged_as_system(): void
    {
        $settings = app(EcommerceSettingsService::class);
        $settings->setSetting('mileage.enabled', true);
        $settings->setSetting('claim.auto_approve_after_payment', true);
        $order = $this->own($this->deliveredClaimOrder());

        $this->actingAs($this->member);
        $return = app(OrderReturnService::class)->requestReturn($order, [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::PAY_ON_REQUEST,
            'requested_by' => $this->member->id,
        ]);
        $this->grantMileage($this->member->id, 10000);
        $payments = app(ClaimPaymentService::class);
        $payment = $payments->initiateForRequest($return, PaymentMethodEnum::POINT->value);
        ActivityLog::query()->delete();

        $payments->pay($payment, PaymentMethodEnum::POINT->value, $this->member->id);

        $this->assertSame(ReturnStatusEnum::APPROVED, $return->fresh()->return_status);

        $rows = ActivityLog::where('action', 'claim_return.status_change')->orderBy('id')->get();
        $byTarget = $rows->mapWithKeys(fn (ActivityLog $log) => [($log->properties['new_status'] ?? '?') => $log->user_id])->all();

        $this->assertArrayHasKey('paid', $byTarget, '결제 확정 전이가 기록되지 않았다.');
        $this->assertArrayHasKey('approved', $byTarget, '자동 승인 전이가 기록되지 않았다.');
        $this->assertSame($this->member->id, $byTarget['paid'], '결제 확정은 고객이 한 일이다.');
        $this->assertNull($byTarget['approved'], '결제 뒤 자동 승인에 고객이 작업자로 남았다.');
    }
}
