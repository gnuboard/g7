<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\PaymentMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\RefundStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\OrderRefund;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자 [주문취소] — 마일리지로 전액 결제한(현금 0원) 주문 (결함 4)
 *
 * 돌려줄 현금이 없는 주문은 결제수단(무통장 등)과 무관하게 계좌를 묻지 않고 「마일리지로 환불」로
 * 기록된다. 계좌 송금이 없으므로 결제수단 자동승인 게이트(P16)의 정지 근거도 없다 — 「환불까지 한 번에」를
 * 켜면 완료까지 가고 쓴 마일리지가 그 환불에 묶여 돌아온다.
 */
class AdminCancelMileageOnlyOrderTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $admin;

    /**
     * 통화·사유를 심고 주문 취소 권한을 가진 관리자를 만듭니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->setupTestCurrencySettings();
        $this->seedClaimReasons();
        $this->admin = $this->createAdminUser([
            'sirsoft-ecommerce.orders.read',
            'sirsoft-ecommerce.orders.update',
            // 「환불까지 한 번에」 는 환불 실행 권한이 필요하다 (7.1.0).
            'sirsoft-ecommerce.claims.execute-refund',
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
     * 무통장 현금 0원 주문을 관리자가 「환불까지 한 번에」로 취소하면 계좌 없이 완료되고 마일리지가 돌아온다.
     *
     * @return void 반환값 없음
     *
     * @effects mileage_only_refund_bypasses_payment_method_gate
     */
    public function test_admin_cancel_of_a_mileage_only_bank_order_auto_completes(): void
    {
        $member = $this->createUser();
        $order = $this->mileageOnlyPaidOrder($member, PaymentMethodEnum::DBANK->value);

        $this->actingAs($this->admin)->postJson(
            "/api/modules/sirsoft-ecommerce/admin/orders/{$order->order_number}/cancel",
            ['type' => 'full', 'reason' => 'changed_mind', 'cancel_pg' => false, 'auto_advance_refund' => true],
        )->assertOk();

        $refund = OrderRefund::query()->where('order_id', $order->id)->firstOrFail();
        $this->assertSame(RefundMethodEnum::POINTS, $refund->refund_method);
        $this->assertSame(RefundStatusEnum::COMPLETED, $refund->refund_status);
        $this->assertSame(1, MileageTransaction::query()
            ->where('order_refund_id', $refund->id)
            ->where('type', MileageTransactionTypeEnum::REFUND_RESTORE->value)
            ->count());
    }

    /**
     * 주문 상세의 결제 정보가 현금 0원 무통장 주문을 「자동 전이되는 환불」로 알린다 — 취소 창이 「체크해도 멈춘다」를 띄우지 않게.
     *
     * 실측(C12 Z3)에서 현금 0원 무통장 주문의 [주문 취소] 창이 「사람이 송금해야 하므로 환불신청에서 멈춘다」를 보였지만
     * 실제로는 완료까지 자동으로 갔다. 안내 플래그가 결제수단만 보고, 자동 전이 판정은 돌려줄 현금을 보기 때문이다.
     *
     * @return void 반환값 없음
     *
     * @effects cancel_modal_auto_advance_hint_follows_cash_to_return
     */
    public function test_order_detail_marks_mileage_only_bank_order_as_auto_approving(): void
    {
        $member = $this->createUser();
        $mileageOnly = $this->mileageOnlyPaidOrder($member, PaymentMethodEnum::DBANK->value);

        $this->actingAs($this->admin)
            ->getJson("/api/modules/sirsoft-ecommerce/admin/orders/{$mileageOnly->order_number}")
            ->assertOk()
            ->assertJsonPath('data.payment.auto_approves_refund', true);
    }
}
