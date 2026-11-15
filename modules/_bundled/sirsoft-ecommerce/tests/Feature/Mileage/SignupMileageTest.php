<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Mileage;

use App\Enums\UserStatus;
use App\Extension\ModuleManager;
use App\Models\User;
use Illuminate\Support\Carbon;
use Modules\Sirsoft\Ecommerce\Enums\MileageTransactionTypeEnum;
use Modules\Sirsoft\Ecommerce\Listeners\GrantSignupMileageListener;
use Modules\Sirsoft\Ecommerce\Models\MileageTransaction;
use Modules\Sirsoft\Ecommerce\Services\UserMileageService;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Mileage\Concerns\BuildsMileagePolicyFixtures;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 가입 적립금 테스트
 *
 * 스스로 가입한 회원에게만, 한 회원에 한 번만 준다. 본인인증 대기 가입은 활성화될 때 준다.
 * 관리자가 만든 회원은 대상이 아니다.
 *
 * @scenario-manifest modules/_bundled/sirsoft-ecommerce/tests/scenarios/mileage-policy-signup-noearn-mintotal.yaml
 */
class SignupMileageTest extends ModuleTestCase
{
    use BuildsMileagePolicyFixtures;

    /**
     * 켜고 금액을 주면 가입 한 번에 한 건을 시스템 지급으로 남긴다.
     *
     * @scenario policy=signup_earn, setting=on, trigger=register_active
     *
     * @effects signup_earn_granted_once, signup_earn_is_system_grant, signup_earn_follows_expiry_setting
     *
     * @return void 반환값 없음
     */
    public function test_signup_grants_mileage_once_as_system_grant(): void
    {
        Carbon::setTestNow('2026-11-11 10:00:00');
        $this->writeMileageSettings(['signup_earn_enabled' => true, 'signup_earn_amount' => 1000, 'expiry_days' => 30]);
        $user = User::factory()->create(['status' => UserStatus::Active->value]);

        app(GrantSignupMileageListener::class)->handleRegister($user, []);

        $rows = $this->signupRows($user);
        $this->assertCount(1, $rows);
        $this->assertSame(1000.0, (float) $rows[0]->amount);
        $this->assertSame(1000.0, (float) $rows[0]->remaining_amount);
        $this->assertNull($rows[0]->granted_by);
        $this->assertSame('2026-12-11', $rows[0]->expires_at->toDateString());
        $this->assertSame(1000, app(UserMileageService::class)->availableBalance($user->id));
        Carbon::setTestNow();
    }

    /**
     * 꺼져 있거나 금액이 0 이면 주지 않는다.
     *
     * @scenario policy=signup_earn, setting=off_or_zero, trigger=register_active
     *
     * @effects signup_earn_skipped_when_disabled
     *
     * @return void 반환값 없음
     */
    public function test_signup_grants_nothing_when_disabled_or_zero(): void
    {
        $listener = app(GrantSignupMileageListener::class);

        $this->writeMileageSettings(['signup_earn_enabled' => false, 'signup_earn_amount' => 1000]);
        $off = User::factory()->create(['status' => UserStatus::Active->value]);
        $listener->handleRegister($off, []);
        $this->assertCount(0, $this->signupRows($off));

        $this->writeMileageSettings(['signup_earn_enabled' => true, 'signup_earn_amount' => 0]);
        $zero = User::factory()->create(['status' => UserStatus::Active->value]);
        $listener->handleRegister($zero, []);
        $this->assertCount(0, $this->signupRows($zero));

        $this->writeMileageSettings(['enabled' => false, 'signup_earn_enabled' => true, 'signup_earn_amount' => 1000]);
        $mileageOff = User::factory()->create(['status' => UserStatus::Active->value]);
        $listener->handleRegister($mileageOff, []);
        $this->assertCount(0, $this->signupRows($mileageOff));
    }

    /**
     * 본인인증 대기 가입은 가입 때 주지 않고 활성화될 때 준다. 두 훅이 모두 와도 한 건이다.
     *
     * @scenario policy=signup_earn, setting=on, trigger=pending_then_activated
     *
     * @effects signup_earn_deferred_until_activation, signup_earn_granted_once
     *
     * @return void 반환값 없음
     */
    public function test_pending_verification_signup_is_granted_on_activation_only_once(): void
    {
        $this->writeMileageSettings(['signup_earn_enabled' => true, 'signup_earn_amount' => 1000]);
        $listener = app(GrantSignupMileageListener::class);
        $user = User::factory()->create(['status' => UserStatus::PendingVerification->value]);

        $listener->handleRegister($user, []);
        $this->assertCount(0, $this->signupRows($user));

        $user->forceFill(['status' => UserStatus::Active->value])->save();
        $listener->handleActivated($user);
        $this->assertCount(1, $this->signupRows($user));

        $listener->handleActivated($user);
        $listener->handleRegister($user, []);
        $this->assertCount(1, $this->signupRows($user));
    }

    /**
     * 탈퇴 회원에게는 주지 않는다.
     *
     * @scenario policy=signup_earn, setting=on, trigger=withdrawn
     *
     * @effects signup_earn_skipped_for_withdrawn_member
     *
     * @return void 반환값 없음
     */
    public function test_withdrawn_member_gets_nothing(): void
    {
        $this->writeMileageSettings(['signup_earn_enabled' => true, 'signup_earn_amount' => 1000]);
        $user = User::factory()->create(['status' => UserStatus::Withdrawn->value]);

        app(GrantSignupMileageListener::class)->handleActivated($user);

        $this->assertCount(0, $this->signupRows($user));
        $this->assertNull(app(UserMileageService::class)->systemEarn($user->id, MileageTransactionTypeEnum::SIGNUP_EARN, 1000, 'test'));
    }

    /**
     * 리스너는 가입 훅 두 개만 구독하고(관리자 회원 생성 훅은 구독하지 않는다) 모듈에 등록돼 있다.
     *
     * @scenario policy=signup_earn, setting=on, trigger=admin_created
     *
     * @effects signup_earn_not_subscribed_to_admin_create, signup_listener_registered_in_module
     *
     * @return void 반환값 없음
     */
    public function test_listener_subscribes_to_signup_hooks_only(): void
    {
        $hooks = array_keys(GrantSignupMileageListener::getSubscribedHooks());

        $this->assertEqualsCanonicalizing(
            ['core.auth.after_register', 'core.auth.after_register_activated'],
            $hooks
        );
        $this->assertContains(
            GrantSignupMileageListener::class,
            app(ModuleManager::class)->getModule('sirsoft-ecommerce')->getHookListeners()
        );
    }

    /**
     * 가입 적립 거래가 적립 유형으로 분류되고 라벨이 있다.
     *
     * @scenario policy=signup_earn, setting=on, trigger=enum_contract
     *
     * @effects signup_earn_type_is_earning_with_label
     *
     * @return void 반환값 없음
     */
    public function test_signup_earn_type_is_classified_as_earning(): void
    {
        $type = MileageTransactionTypeEnum::SIGNUP_EARN;

        $this->assertTrue($type->isEarning());
        $this->assertFalse($type->isDeducting());
        $this->assertSame('earn', $type->userDisplayCategory());
        $this->assertSame('green', $type->adminBadgeGroup());
        $this->assertContains('signup_earn', MileageTransactionTypeEnum::earningValues());
        foreach (['ko', 'en'] as $locale) {
            app()->setLocale($locale);
            $this->assertStringNotContainsString('mileage_transaction_type', $type->label());
        }
    }

    /**
     * 회원의 가입 적립 거래를 가져옵니다.
     *
     * @param  User  $user  회원
     * @return array<int, MileageTransaction> 가입 적립 거래
     */
    private function signupRows(User $user): array
    {
        return MileageTransaction::query()
            ->where('user_id', $user->id)
            ->where('type', MileageTransactionTypeEnum::SIGNUP_EARN->value)
            ->get()
            ->all();
    }
}
