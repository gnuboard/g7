<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Unit\Listeners;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Listeners\UserMileageInfoListener;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원 응답의 「환불로 받은 마일리지 잔여」 (Q20 — 탈퇴 확인 창 경고 재료)
 *
 * 현금 환불을 마일리지로 받은 회원이 탈퇴하면 그 돈도 소멸한다. 탈퇴 확인 창이 그 금액을 알리도록 회원 응답에
 * `refund_credit_available`(refund_credit lot 의 남은 금액 합)을 싣는다. 기능이 꺼져 있어도 싣는다.
 *
 * 시나리오 매니페스트: `tests/scenarios/withdrawn-member-mileage-ledger.yaml`
 */
class UserMileageRefundCreditNoticeTest extends ModuleTestCase
{
    /**
     * 회원과 refund_credit 3,000(잔여 3,000) + 관리자 지급 1,000 lot 을 만듭니다.
     *
     * @return User 회원
     */
    private function memberWithRefundCredit(): User
    {
        $user = User::factory()->create();
        foreach ([[MileageTransactionTypeEnum::REFUND_CREDIT, 3000], [MileageTransactionTypeEnum::ADMIN_EARN, 1000]] as [$type, $amount]) {
            MileageTransaction::create([
                'user_id' => $user->id,
                'currency' => 'KRW',
                'type' => $type->value,
                'amount' => $amount,
                'remaining_amount' => $amount,
                'balance_after' => $amount,
            ]);
        }

        return $user;
    }

    /**
     * 기능이 켜져 있으면 잔액과 함께 환불로 받은 마일리지 잔여만 따로 싣는다.
     *
     * @effects member_payload_carries_refund_credit_available
     *
     * @return void 반환값 없음
     */
    public function test_payload_carries_refund_credit_available(): void
    {
        app(EcommerceSettingsService::class)->setSetting('mileage.enabled', true);
        $user = $this->memberWithRefundCredit();

        $data = app(UserMileageInfoListener::class)->injectMileageTotal([], $user);

        $this->assertTrue($data['ecommerce_mileage']['enabled']);
        $this->assertEquals(3000, $data['ecommerce_mileage']['refund_credit_available']);
    }

    /**
     * 기능이 꺼져 있어도 이미 지급된 현금 대체분은 고객 돈이라 싣는다.
     *
     * @effects member_payload_carries_refund_credit_available
     *
     * @return void 반환값 없음
     */
    public function test_payload_carries_refund_credit_even_when_mileage_disabled(): void
    {
        app(EcommerceSettingsService::class)->setSetting('mileage.enabled', false);
        $user = $this->memberWithRefundCredit();

        $data = app(UserMileageInfoListener::class)->injectMileageTotal([], $user);

        $this->assertFalse($data['ecommerce_mileage']['enabled']);
        $this->assertEquals(3000, $data['ecommerce_mileage']['refund_credit_available']);
    }
}
