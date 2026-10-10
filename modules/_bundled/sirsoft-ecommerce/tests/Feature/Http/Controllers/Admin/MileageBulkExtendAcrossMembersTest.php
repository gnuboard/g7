<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Models\User;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 마일리지 일괄 유효기간 연장의 다회원 선택 회귀 테스트 (#703)
 *
 * 회귀 배경: 마일리지 내역 화면에서 여러 회원의 적립건을 골라 「유효기간 연장」을 누르면 화면이 첫 행의
 * 회원 식별자 하나만 보냈고, 서버는 그 회원의 적립건만 골라 연장했다. 나머지 회원의 적립건은 오류 없이
 * 건너뛰어져 성공 안내와 실제 결과가 달랐다.
 */
class MileageBulkExtendAcrossMembersTest extends ModuleTestCase
{
    private string $endpoint = '/api/modules/sirsoft-ecommerce/admin/mileage-transactions/extend-expiry';

    /**
     * 5일 뒤 만료되는 적립건을 만듭니다.
     *
     * @param  User  $member  적립 회원
     * @return MileageTransaction 적립건
     */
    private function makeLot(User $member): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $member->id,
            'currency' => 'KRW',
            'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => 1000,
            'remaining_amount' => 1000,
            'balance_after' => 1000,
            'expires_at' => now()->addDays(5),
        ]);
    }

    /**
     * 서로 다른 회원의 적립건을 함께 고르면 모두 연장된다.
     *
     * @scenario selection=multiple_members, user_id_param=omitted
     *
     * @effects all_selected_members_lots_extended
     *
     * @return void 없음
     */
    public function test_lots_of_multiple_members_are_all_extended(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.mileage.read', 'sirsoft-ecommerce.mileage.manage']);
        $lotA = $this->makeLot($this->createUser());
        $lotB = $this->makeLot($this->createUser());

        $response = $this->actingAs($admin)->postJson($this->endpoint, [
            'lot_ids' => [$lotA->id, $lotB->id],
            'days' => 30,
        ]);

        $response->assertOk()->assertJsonPath('data.extended_count', 2);
        $this->assertTrue($lotA->fresh()->expires_at->greaterThan(now()->addDays(30)));
        $this->assertTrue($lotB->fresh()->expires_at->greaterThan(now()->addDays(30)));
    }

    /**
     * 회원을 지정하면(종전 호출 계약) 그 회원의 적립건만 연장된다.
     *
     * @scenario selection=multiple_members, user_id_param=given
     *
     * @effects explicit_member_scope_preserved
     *
     * @return void 없음
     */
    public function test_explicit_member_scope_is_preserved(): void
    {
        $admin = $this->createAdminUser(['sirsoft-ecommerce.mileage.read', 'sirsoft-ecommerce.mileage.manage']);
        $memberA = $this->createUser();
        $lotA = $this->makeLot($memberA);
        $lotB = $this->makeLot($this->createUser());

        $response = $this->actingAs($admin)->postJson($this->endpoint, [
            'user_id' => $memberA->uuid,
            'lot_ids' => [$lotA->id, $lotB->id],
            'days' => 30,
        ]);

        $response->assertOk()->assertJsonPath('data.extended_count', 1);
        $this->assertTrue($lotA->fresh()->expires_at->greaterThan(now()->addDays(30)));
        $this->assertTrue($lotB->fresh()->expires_at->lessThan(now()->addDays(6)));
    }
}
