<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\ClaimChargeMethodEnum;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Enums\ReturnStatusEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Services\AdminClaimService;
use Modules\Sirsoft\Ecommerce\Services\OrderReturnService;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 완료 처리의 「적립 차감」 예고는 **아직 적립 전인 상품**에만 뜬다 (#599 T2-D13 파생)
 *
 * T2-D13 으로 이미 적립이 나간 상품은 반품·교환을 완료해도 되돌리지 않게 됐다(운영 결정
 * 2026-09-23). 그런데 완료 모달의 요약(「적립 차감」)과 상세 정산 카드(「적립 예정 · 완료 시
 * 차감」)는 회원 주문이기만 하면 예고를 띄웠다 — 120원이 이미 적립된 상품의 반품을 완료하려는
 * 운영자에게 **일어나지 않을 차감**을 예고하는 것이다(2026-09-23 재실측: 반품
 * `RT20260923-0039383437` 완료 뒤 `earn_cancel` 0건 · 적립 lot 120 그대로).
 *
 * 아직 적립 전인 상품은 예고가 맞다 — 반품완료로 주문옵션 상태가 바뀌면 적립 대상 조회에서
 * 빠져 적립 예정분이 나가지 않는다. 그래서 두 경우를 함께 잠근다.
 */
class ClaimCompletionEarnNoticeTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    protected User $admin;

    /**
     * 관리자와 통화·사유 시드를 준비합니다.
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
        ]);
    }

    /**
     * 통화 설정을 되돌립니다.
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        $this->clearTestCurrencySettings();
        parent::tearDown();
    }

    /**
     * 이미 적립이 나간 상품의 반품은 「적립 차감」을 예고하지 않는다.
     *
     * @return void 반환값 없음
     *
     * @scenario event=completion_preview_after_earn
     *
     * @effects completion_preview_omits_the_earn_notice_after_the_earn
     */
    public function test_the_completion_notice_omits_the_earn_deduction_once_the_option_has_earned(): void
    {
        $return = $this->collectedMemberReturn(earned: true);

        $this->assertFalse($this->previewRow($return)['earn_recovery'], '완료 모달이 일어나지 않을 적립 차감을 예고한다');
        $this->assertFalse(app(AdminClaimService::class)->settlementFor($return)['earn_recovery'], '상세 정산 카드가 일어나지 않을 적립 차감을 예고한다');
    }

    /**
     * 아직 적립 전인 상품의 반품은 「적립 차감」(적립 예정분이 나가지 않음)을 그대로 예고한다.
     *
     * @return void 반환값 없음
     *
     * @scenario event=completion_preview_before_earn
     *
     * @effects completion_preview_keeps_the_earn_notice_before_the_earn
     */
    public function test_the_completion_notice_keeps_the_earn_deduction_before_the_option_earns(): void
    {
        $return = $this->collectedMemberReturn(earned: false);

        $this->assertTrue($this->previewRow($return)['earn_recovery']);
        $this->assertTrue(app(AdminClaimService::class)->settlementFor($return)['earn_recovery']);
    }

    /**
     * 회원 주문의 회수도착 반품을 만듭니다. (완료 직전 상태)
     *
     * @param  bool  $earned  반품 대상 주문옵션에 구매 적립이 이미 나갔는지
     * @return OrderReturn 반품
     */
    protected function collectedMemberReturn(bool $earned): OrderReturn
    {
        $member = User::factory()->create();
        $order = $this->deliveredClaimOrder(orderOverrides: ['user_id' => $member->id]);
        $option = $order->options->first();

        if ($earned) {
            MileageTransaction::create([
                'user_id' => $member->id,
                'currency' => 'KRW',
                'type' => MileageTransactionTypeEnum::PURCHASE_EARN->value,
                'amount' => 120,
                'remaining_amount' => 120,
                'balance_after' => 120,
                'order_id' => $order->id,
                'order_option_id' => $option->id,
                'expires_at' => now()->addYear(),
            ]);
        }

        $service = app(OrderReturnService::class);

        $return = $service->requestReturn($order, [
            'items' => [['order_option_id' => $option->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => ClaimChargeMethodEnum::DEDUCT_FROM_REFUND,
        ]);

        foreach ([ReturnStatusEnum::APPROVED, ReturnStatusEnum::COLLECTION_READY, ReturnStatusEnum::COLLECTING, ReturnStatusEnum::COLLECTED] as $status) {
            $return = $service->transition($return, $status, $this->claimTrackingFor($status));
        }

        return $return->fresh(['returnOptions', 'order']);
    }

    /**
     * 완료 처리 미리보기의 신청서 행을 읽습니다.
     *
     * @param  OrderReturn  $return  반품
     * @return array<string, mixed> 미리보기 행
     */
    protected function previewRow(OrderReturn $return): array
    {
        return $this->actingAs($this->admin)
            ->postJson('/api/modules/sirsoft-ecommerce/admin/claims/completion-preview', [
                'claim_type' => 'return',
                'create_refund_request' => false,
                'claims' => [['id' => $return->id, 'restore_stock_by_item' => []]],
            ])
            ->assertOk()
            ->json('data.claims.0');
    }
}
