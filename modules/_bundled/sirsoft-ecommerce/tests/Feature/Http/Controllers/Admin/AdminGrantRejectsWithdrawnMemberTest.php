<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\DTO\MileageAdminEarnDto;
use Modules\Sirsoft\Ecommerce\Enums\CouponDiscountType;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueMethod;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetScope;
use Modules\Sirsoft\Ecommerce\Enums\CouponTargetType;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Exceptions\CouponNotIssuableException;
use Modules\Sirsoft\Ecommerce\Exceptions\MileageValidationException;
use Modules\Sirsoft\Ecommerce\Models\Coupon;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Services\EcommerceSettingsService;
use Modules\Sirsoft\Ecommerce\Services\UserCouponService;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 관리자가 탈퇴 회원에게 혜택을 주는 쓰기를 서버가 거절한다 (7.1.1 판정)
 *
 * 탈퇴 회원은 로그인할 수 없어 받은 것을 쓰지 못하고, 마일리지는 탈퇴 때 소멸시키는 규칙(주문 적립·환불 반환의
 * `forfeitIfWithdrawn`)이 관리자 지급만 거치지 않아 잔액이 남았다. 쿠폰 직접 발급은 회원 상태를 보지 않았다.
 * 수동 지급·직접 발급·유효기간 연장은 거절(422)하고, 수동 차감은 남은 잔액을 정리하는 길이라 받는다.
 *
 * @scenario-manifest tests/scenarios/admin-grant-rejects-withdrawn-member.yaml
 */
class AdminGrantRejectsWithdrawnMemberTest extends ModuleTestCase
{
    private string $mileageApi = '/api/modules/sirsoft-ecommerce/admin/mileage-transactions';

    private User $adminUser;

    private User $activeMember;

    private User $withdrawnMember;

    /**
     * 마일리지·쿠폰 권한을 가진 관리자, 정상 회원, 탈퇴 회원, KRW 마일리지 규칙을 준비합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        app()->setLocale('ko');

        $this->adminUser = $this->createAdminUser([
            'sirsoft-ecommerce.mileage.read',
            'sirsoft-ecommerce.mileage.manage',
            'sirsoft-ecommerce.promotion-coupon.read',
            'sirsoft-ecommerce.promotion-coupon.create',
            'sirsoft-ecommerce.promotion-coupon.update',
        ]);
        $this->activeMember = $this->createUser();
        $this->withdrawnMember = $this->createUser();
        $this->withdrawnMember->forceFill(['status' => UserStatus::Withdrawn->value])->save();

        app(EcommerceSettingsService::class)->setSetting('mileage.enabled', true);
        app(EcommerceSettingsService::class)->setSetting('mileage.currency_rules', [
            ['currency_code' => 'KRW', 'point_value' => 1, 'min_use_amount' => 0, 'use_unit' => 1, 'max_use_type' => 'percent', 'max_use_percent' => 100, 'max_use_value' => 0],
        ]);
    }

    /**
     * 직접 발급형 쿠폰 1건을 만듭니다.
     *
     * @return Coupon 생성된 쿠폰
     */
    private function createDirectCoupon(): Coupon
    {
        return Coupon::create([
            'name' => ['ko' => '직접발급 쿠폰', 'en' => 'Direct Coupon'],
            'target_type' => CouponTargetType::PRODUCT_AMOUNT->value,
            'discount_type' => CouponDiscountType::FIXED->value,
            'discount_value' => 1000,
            'min_order_amount' => 0,
            'issue_method' => CouponIssueMethod::DIRECT->value,
            'issue_condition' => CouponIssueCondition::MANUAL->value,
            'issue_status' => CouponIssueStatus::ISSUING->value,
            'per_user_limit' => 0,
            'valid_type' => 'days_from_issue',
            'valid_days' => 30,
            'is_combinable' => true,
            'target_scope' => CouponTargetScope::ALL->value,
            'created_by' => $this->adminUser->id,
        ]);
    }

    /**
     * 회원에게 남은 관리자 지급 적립건을 만듭니다(7.1.0 에서 탈퇴 회원에게 들어간 잔액을 흉내 낸다).
     *
     * @param  User  $member  적립건 주인
     * @param  int  $amount  금액
     * @return MileageTransaction 생성된 적립건
     */
    private function createLeftoverLot(User $member, int $amount): MileageTransaction
    {
        return MileageTransaction::create([
            'user_id' => $member->id, 'currency' => 'KRW', 'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
            'amount' => $amount, 'remaining_amount' => $amount, 'balance_after' => $amount, 'expires_at' => now()->addDays(5),
        ]);
    }

    /**
     * 탈퇴 회원에게 마일리지 수동 지급을 보내면 검증 단계에서 422 이고 적립건이 생기지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario grant=mileage_earn, member=withdrawn
     *
     * @effects withdrawn_member_grant_rejected_with_422, nothing_is_written_for_withdrawn_member
     */
    public function test_mileage_earn_to_withdrawn_member_is_rejected(): void
    {
        $response = $this->actingAs($this->adminUser)->postJson($this->mileageApi, [
            'user_id' => $this->withdrawnMember->uuid,
            'action' => 'earn',
            'amount' => 2000,
            'currency' => 'KRW',
            'use_default_expiry' => false,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('user_id');
        $this->assertSame(
            __('sirsoft-ecommerce::validation.mileage.user_withdrawn'),
            $response->json('errors.user_id.0')
        );
        $this->assertDatabaseMissing('ecommerce_mileage_transactions', [
            'user_id' => $this->withdrawnMember->id,
            'type' => MileageTransactionTypeEnum::ADMIN_EARN->value,
        ]);
    }

    /**
     * 정상 회원에게는 종전처럼 지급됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario grant=mileage_earn, member=active
     *
     * @effects active_member_grant_still_succeeds
     */
    public function test_mileage_earn_to_active_member_still_succeeds(): void
    {
        $response = $this->actingAs($this->adminUser)->postJson($this->mileageApi, [
            'user_id' => $this->activeMember->uuid,
            'action' => 'earn',
            'amount' => 2000,
            'currency' => 'KRW',
            'use_default_expiry' => false,
        ]);

        $response->assertCreated();
    }

    /**
     * 서비스를 바로 불러도(확장·훅 경로) 탈퇴 회원 지급은 거절됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario grant=mileage_earn, member=withdrawn
     *
     * @effects service_guard_rejects_withdrawn_member
     */
    public function test_admin_earn_service_rejects_withdrawn_member(): void
    {
        $this->actingAs($this->adminUser);

        $this->expectException(MileageValidationException::class);

        app(UserMileageService::class)->adminEarn($this->withdrawnMember->id, new MileageAdminEarnDto(
            amount: 1000,
            currency: 'KRW',
            grantedBy: $this->adminUser->id,
            useDefaultExpiry: false,
        ));
    }

    /**
     * 탈퇴 회원에게 남은 잔액은 수동 차감으로 정리할 수 있습니다(차감은 혜택이 아니라 정리 수단).
     *
     * @return void 반환값 없음
     *
     * @scenario grant=mileage_deduct, member=withdrawn
     *
     * @effects withdrawn_member_leftover_can_be_deducted
     */
    public function test_mileage_deduct_from_withdrawn_member_leftover_is_allowed(): void
    {
        $this->createLeftoverLot($this->withdrawnMember, 3000);

        $response = $this->actingAs($this->adminUser)->postJson($this->mileageApi, [
            'user_id' => $this->withdrawnMember->uuid,
            'action' => 'deduct',
            'amount' => 3000,
            'currency' => 'KRW',
        ]);

        $response->assertCreated();
        $this->assertDatabaseHas('ecommerce_mileage_transactions', [
            'user_id' => $this->withdrawnMember->id,
            'type' => MileageTransactionTypeEnum::ADMIN_DEDUCT->value,
        ]);
    }

    /**
     * 탈퇴 회원의 적립건은 유효기간을 연장하지 않습니다(422, 만료일 그대로).
     *
     * @return void 반환값 없음
     *
     * @scenario grant=mileage_extend, member=withdrawn
     *
     * @effects withdrawn_member_grant_rejected_with_422, nothing_is_written_for_withdrawn_member
     */
    public function test_extend_expiry_of_withdrawn_member_lot_is_rejected(): void
    {
        $lot = $this->createLeftoverLot($this->withdrawnMember, 1000);
        $before = $lot->expires_at->copy();

        $response = $this->actingAs($this->adminUser)->postJson($this->mileageApi.'/extend-expiry', [
            'lot_ids' => [$lot->id],
            'days' => 30,
        ]);

        $response->assertStatus(422);
        $this->assertTrue($lot->fresh()->expires_at->equalTo($before));
    }

    /**
     * 정상 회원 적립건 연장은 종전처럼 됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario grant=mileage_extend, member=active
     *
     * @effects active_member_grant_still_succeeds
     */
    public function test_extend_expiry_of_active_member_lot_still_succeeds(): void
    {
        $lot = $this->createLeftoverLot($this->activeMember, 1000);

        $response = $this->actingAs($this->adminUser)->postJson($this->mileageApi.'/extend-expiry', [
            'lot_ids' => [$lot->id],
            'days' => 30,
        ]);

        $response->assertOk();
        $this->assertTrue($lot->fresh()->expires_at->greaterThan(now()->addDays(30)));
    }

    /**
     * 탈퇴 회원이 섞인 쿠폰 직접 발급은 검증 단계에서 422 이고 누구에게도 발급되지 않습니다.
     *
     * @return void 반환값 없음
     *
     * @scenario grant=coupon_direct, member=withdrawn
     *
     * @effects withdrawn_member_grant_rejected_with_422, nothing_is_written_for_withdrawn_member
     */
    public function test_coupon_direct_issue_with_withdrawn_member_is_rejected(): void
    {
        $coupon = $this->createDirectCoupon();

        $response = $this->actingAs($this->adminUser)->postJson(
            "/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}/issue-direct",
            ['user_uuids' => [$this->activeMember->uuid, $this->withdrawnMember->uuid]]
        );

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('user_uuids.1');
        $this->assertSame(
            __('sirsoft-ecommerce::validation.coupon.user_withdrawn'),
            $response->json('errors')['user_uuids.1'][0]
        );
        $this->assertSame(0, CouponIssue::where('coupon_id', $coupon->id)->count());
    }

    /**
     * 정상 회원만이면 종전처럼 발급됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario grant=coupon_direct, member=active
     *
     * @effects active_member_grant_still_succeeds
     */
    public function test_coupon_direct_issue_to_active_member_still_succeeds(): void
    {
        $coupon = $this->createDirectCoupon();

        $response = $this->actingAs($this->adminUser)->postJson(
            "/api/modules/sirsoft-ecommerce/admin/promotion-coupons/{$coupon->id}/issue-direct",
            ['user_uuids' => [$this->activeMember->uuid]]
        );

        $response->assertOk();
        $this->assertSame(1, $response->json('data.issued'));
    }

    /**
     * 서비스를 바로 불러도 탈퇴 회원 발급은 사유 `user_withdrawn` 으로 거절됩니다.
     *
     * @return void 반환값 없음
     *
     * @scenario grant=coupon_direct, member=withdrawn
     *
     * @effects service_guard_rejects_withdrawn_member
     */
    public function test_issue_directly_to_user_service_rejects_withdrawn_member(): void
    {
        $coupon = $this->createDirectCoupon();

        try {
            app(UserCouponService::class)->issueDirectlyToUser($coupon, $this->withdrawnMember->id);
            $this->fail('탈퇴 회원 발급이 거절되지 않았다');
        } catch (CouponNotIssuableException $e) {
            $this->assertSame('user_withdrawn', $e->getReason());
        }

        $this->assertSame(0, CouponIssue::where('coupon_id', $coupon->id)->count());
    }
}
