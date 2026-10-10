<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Claims;

use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Support\GuestRoleResolver;
use Modules\Sirsoft\Ecommerce\Models\OrderReturn;
use Modules\Sirsoft\Ecommerce\Tests\Concerns\BuildsClaimFlowOrders;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 회원 클레임 응답의 버튼 판정이 권한을 함께 보는지 테스트 (7.1.0 abilities 게이트 G31)
 *
 * [철회]·[수정]·[결제하기] 는 각각 `user-claims.withdraw`·`user-claims.request`·`user-claims.pay` 라우트 권한이다.
 * 응답의 `is_withdrawable`·`is_editable`·`can_pay` 가 상태와 출처만 보면, 운영자가 권한을 거둔 뒤에도 버튼이 서고
 * 누르면 403 이 난다. 판정에 권한을 함께 넣는다.
 */
class UserClaimAbilitiesTest extends ModuleTestCase
{
    use BuildsClaimFlowOrders;

    private User $member;

    /**
     * 통화 설정·사유·회원을 세웁니다.
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
     * 회원 역할에서 권한 하나를 거둡니다.
     *
     * @param  string  $identifier  권한 식별자
     * @return void 반환값 없음
     */
    private function revoke(string $identifier): void
    {
        $permission = Permission::where('identifier', $identifier)->first();
        Role::where('identifier', 'user')->first()?->permissions()->detach($permission?->id);
        GuestRoleResolver::flush();
    }

    /**
     * 회원 소유 배송완료 주문에 반품을 신청하고 신청서 번호를 돌려줍니다.
     *
     * @return string 반품 신청서 번호
     */
    private function requestedReturnNumber(): string
    {
        $order = $this->deliveredClaimOrder();
        $order->forceFill(['user_id' => $this->member->id])->save();
        $order = $order->fresh(['options']);

        $response = $this->actingAs($this->member)->postJson("/api/modules/sirsoft-ecommerce/user/orders/{$order->id}/returns", [
            'items' => [['order_option_id' => $order->options->first()->id, 'quantity' => 1]],
            'reason_code' => 'changed_mind',
            'charge_method' => 'deduct_from_refund',
            'refund_bank' => ['bank_code' => '004', 'account_number' => '110234567890', 'holder' => '홍길동'],
        ]);
        $this->assertSame(201, $response->status(), (string) $response->getContent());

        return (string) OrderReturn::query()->latest('id')->value('return_number');
    }

    /**
     * 권한이 있으면 철회·수정이 열리고 결제 권한 키도 true 입니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=granted
     *
     * @effects user_claim_flags_follow_permissions
     */
    public function test_flags_are_open_with_all_permissions(): void
    {
        $number = $this->requestedReturnNumber();

        $this->actingAs($this->member->fresh())->getJson("/api/modules/sirsoft-ecommerce/user/claims/{$number}")
            ->assertOk()
            ->assertJsonPath('data.claim.is_withdrawable', true)
            ->assertJsonPath('data.claim.is_editable', true)
            ->assertJsonPath('data.claim.can_pay', true);
    }

    /**
     * 철회 권한을 거두면 `is_withdrawable` 이 false 입니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=missing
     *
     * @effects user_claim_flags_follow_permissions
     */
    public function test_withdraw_flag_follows_the_withdraw_permission(): void
    {
        $number = $this->requestedReturnNumber();
        $this->revoke('sirsoft-ecommerce.user-claims.withdraw');

        $this->actingAs($this->member->fresh())->getJson("/api/modules/sirsoft-ecommerce/user/claims/{$number}")
            ->assertOk()
            ->assertJsonPath('data.claim.is_withdrawable', false)
            ->assertJsonPath('data.claim.is_editable', true);
    }

    /**
     * 신청 권한을 거두면 `is_editable` 이, 결제 권한을 거두면 `can_pay` 가 false 입니다.
     *
     * @return void 반환값 없음
     *
     * @scenario surface=user, permission=missing
     *
     * @effects user_claim_flags_follow_permissions
     */
    public function test_edit_and_pay_flags_follow_their_permissions(): void
    {
        $number = $this->requestedReturnNumber();
        $this->revoke('sirsoft-ecommerce.user-claims.request');
        $this->revoke('sirsoft-ecommerce.user-claims.pay');

        $this->actingAs($this->member->fresh())->getJson("/api/modules/sirsoft-ecommerce/user/claims/{$number}")
            ->assertOk()
            ->assertJsonPath('data.claim.is_editable', false)
            ->assertJsonPath('data.claim.can_pay', false)
            ->assertJsonPath('data.claim.is_withdrawable', true);
    }
}
