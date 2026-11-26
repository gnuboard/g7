<?php

namespace Modules\Sirsoft\Ecommerce\Tests\Feature\Coupon\AutoIssue;

use App\Enums\UserStatus;
use App\Extension\HookManager;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueCondition;
use Modules\Sirsoft\Ecommerce\Enums\CouponIssueStatus;
use Modules\Sirsoft\Ecommerce\Models\CouponIssue;
use Modules\Sirsoft\Ecommerce\Services\CouponService;
use Modules\Sirsoft\Ecommerce\Tests\Feature\Coupon\AutoIssue\Concerns\CreatesAutoIssueCoupons;
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

/**
 * 가입 쿠폰 자동발급
 *
 * 실제 훅 체인(core.auth.after_register / after_register_activated → GrantSignupCouponListener)으로 검증한다.
 */
class SignupCouponIssueTest extends ModuleTestCase
{
    use CreatesAutoIssueCoupons;

    /**
     * 활성 상태로 가입하면 가입 쿠폰이 한 번, AU 코드·signup 키로 발급된다.
     *
     * @scenario case=register_active
     *
     * @effects signup_coupon_issued
     *
     * @return void 반환값 없음
     */
    public function test_active_registration_issues_signup_coupon_once(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP);
        $user = $this->createUser();

        HookManager::doAction('core.auth.after_register', $user, []);

        $issues = CouponIssue::query()->where('coupon_id', $coupon->id)->where('user_id', $user->id)->get();
        $this->assertCount(1, $issues);
        $this->assertStringStartsWith('AU-', $issues->first()->coupon_code);
        $this->assertSame('signup', $issues->first()->issue_source);
        $this->assertSame('signup', $issues->first()->auto_issue_key);
        $this->assertSame(1, $coupon->fresh()->issued_count);
    }

    /**
     * 본인인증 대기로 가입하면 가입 때는 받지 않고 활성화될 때 받는다. 두 훅이 모두 와도 한 번이다.
     *
     * @scenario case=register_then_activate_pending_verification
     *
     * @effects signup_coupon_issued_on_activation
     *
     * @return void 반환값 없음
     */
    public function test_pending_registration_issues_on_activation_only_once(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP);
        $user = $this->createUser();
        $user->forceFill(['status' => UserStatus::PendingVerification->value])->save();

        HookManager::doAction('core.auth.after_register', $user->fresh(), []);
        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->count());

        $user->forceFill(['status' => UserStatus::Active->value])->save();
        HookManager::doAction('core.auth.after_register_activated', $user->fresh());
        HookManager::doAction('core.auth.after_register_activated', $user->fresh());

        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $coupon->id)->where('user_id', $user->id)->count());
    }

    /**
     * 관리자가 만든 회원(core.user.after_create)은 가입 쿠폰을 받지 않는다.
     *
     * @scenario case=admin_create
     *
     * @effects signup_coupon_not_issued
     *
     * @return void 반환값 없음
     */
    public function test_admin_created_user_receives_nothing(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP);
        $user = $this->createUser();

        HookManager::doAction('core.user.after_create', $user, []);

        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->count());
    }

    /**
     * 발급중단·발급기간 밖·수량 소진·기간형 유효기간 만료 쿠폰은 발급하지 않고, 가입 흐름은 예외 없이 끝난다.
     *
     * @scenario case=not_issuable
     *
     * @effects signup_coupon_not_issued
     *
     * @return void 반환값 없음
     */
    public function test_non_issuable_coupons_are_skipped_without_breaking_registration(): void
    {
        $stopped = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['issue_status' => CouponIssueStatus::STOPPED]);
        $outOfPeriod = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['issue_from' => Carbon::now()->addDay()]);
        $exhausted = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['total_quantity' => 1, 'issued_count' => 1]);
        $expired = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, [
            'valid_type' => 'period',
            'valid_days' => null,
            'valid_from' => Carbon::now()->subMonth(),
            'valid_to' => Carbon::now()->subDay(),
        ]);
        $user = $this->createUser();

        HookManager::doAction('core.auth.after_register', $user, []);

        foreach ([$stopped, $outOfPeriod, $exhausted, $expired] as $coupon) {
            $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->count(), 'coupon '.$coupon->id);
        }
    }

    /**
     * 가입 쿠폰이 여럿이면 모두 발급하고, 다른 조건(첫구매·생일) 쿠폰은 발급하지 않는다.
     *
     * @scenario case=multiple_coupons
     *
     * @effects signup_coupon_issued
     *
     * @return void 반환값 없음
     */
    public function test_all_signup_coupons_issued_and_other_conditions_ignored(): void
    {
        $a = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP);
        $b = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP);
        $firstPurchase = $this->makeAutoCoupon(CouponIssueCondition::FIRST_PURCHASE);
        $user = $this->createUser();

        HookManager::doAction('core.auth.after_register', $user, []);

        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $a->id)->count());
        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $b->id)->count());
        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $firstPurchase->id)->count());
    }

    /**
     * 필터 훅이 거부하면 발급하지 않는다.
     *
     * @scenario case=filter_rejects
     *
     * @effects signup_coupon_not_issued
     *
     * @return void 반환값 없음
     */
    public function test_eligibility_filter_can_reject(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP);
        $user = $this->createUser();
        $callback = fn () => false;
        HookManager::addFilter('sirsoft-ecommerce.coupon.filter_auto_issue_eligible', $callback, 10);

        try {
            HookManager::doAction('core.auth.after_register', $user, []);
        } finally {
            HookManager::removeFilter('sirsoft-ecommerce.coupon.filter_auto_issue_eligible', $callback);
        }

        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->count());
    }

    /**
     * 자동발급 쿠폰을 직접발급받은 회원은 같은 조건으로 자동으로 다시 받지 않고, 두 번째 직접발급은 사유와 함께 건너뛴다 (P14).
     *
     * @scenario case=direct_then_auto
     *
     * @effects issue_limit_shared
     *
     * @return void 반환값 없음
     */
    public function test_direct_issue_shares_limit_with_auto_issue(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP);
        $user = $this->createUser();
        $this->actingAs($this->createAdminUser(['sirsoft-ecommerce.promotion-coupon.update']));

        $first = app(CouponService::class)->issueDirectly($coupon->id, [$user->id]);
        $this->assertSame(1, $first['issued']);

        HookManager::doAction('core.auth.after_register', $user, []);
        $second = app(CouponService::class)->issueDirectly($coupon->id, [$user->id]);

        $this->assertSame(0, $second['issued']);
        $this->assertSame('auto_issue_already_issued', $second['skipped'][0]['reason']);
        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $coupon->id)->where('user_id', $user->id)->count());
        $this->assertSame('direct', CouponIssue::query()->where('coupon_id', $coupon->id)->value('issue_source'));
    }

    /**
     * 탈퇴 후 다시 가입한 사람은 회원번호가 다른 새 회원이라 다시 받는다 (P2).
     *
     * @scenario case=rejoin
     *
     * @effects signup_coupon_issued
     *
     * @return void 반환값 없음
     */
    public function test_rejoined_member_is_new_member_and_receives_again(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP);
        $old = $this->createUser();
        HookManager::doAction('core.auth.after_register', $old, []);
        $old->forceFill(['status' => UserStatus::Withdrawn->value])->save();

        $new = $this->createUser();
        HookManager::doAction('core.auth.after_register', $new, []);

        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $coupon->id)->where('user_id', $new->id)->count());
        $this->assertSame(2, $coupon->fresh()->issued_count);
    }

    /**
     * 같은 회원·쿠폰·키 발급이 동시에 들어와 유니크에 걸리면 「이미 발급됨」으로 조용히 흡수한다 — 경고 로그 없이, 발급 수도 늘지 않는다.
     *
     * 발급 키 확인을 지난 뒤(대상 판정 필터 시점) 다른 요청이 같은 키 행을 먼저 넣은 경합을 재현한다.
     *
     * @scenario case=unique_violation_absorbed
     *
     * @effects signup_coupon_not_issued
     *
     * @return void 반환값 없음
     */
    public function test_unique_violation_is_absorbed_as_already_issued(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP);
        $user = $this->createUser();
        $racer = function ($eligible, $c) use ($user) {
            CouponIssue::query()->create([
                'coupon_id' => $c->id,
                'user_id' => $user->id,
                'coupon_code' => 'AU-RACE'.$c->id,
                'status' => 'available',
                'issued_at' => now(),
                'issue_source' => 'signup',
                'auto_issue_key' => 'signup',
            ]);

            return $eligible;
        };
        HookManager::addFilter('sirsoft-ecommerce.coupon.filter_auto_issue_eligible', $racer, 10);
        Log::spy();

        try {
            HookManager::doAction('core.auth.after_register', $user, []);
        } finally {
            HookManager::removeFilter('sirsoft-ecommerce.coupon.filter_auto_issue_eligible', $racer);
        }

        Log::shouldNotHaveReceived('warning');
        $this->assertSame(0, $coupon->fresh()->issued_count);
    }

    /**
     * 자동발급은 회원당 발급 한도(per_user_limit)를 보지 않는다 — 한도 1 인 쿠폰을 예전에 받은 회원도 가입 키로 한 번 더 받는다.
     *
     * @scenario case=per_user_limit_ignored
     *
     * @effects signup_coupon_issued
     *
     * @return void 반환값 없음
     */
    public function test_per_user_limit_is_not_applied_to_auto_issue(): void
    {
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['per_user_limit' => 1]);
        $user = $this->createUser();
        // 자동발급 컬럼이 생기기 전(키 없음)에 받아 둔 발급 건
        CouponIssue::query()->create([
            'coupon_id' => $coupon->id,
            'user_id' => $user->id,
            'coupon_code' => 'DR-LEGACY'.$coupon->id,
            'status' => 'available',
            'issued_at' => now(),
        ]);

        HookManager::doAction('core.auth.after_register', $user, []);

        $this->assertSame(1, CouponIssue::query()->where('coupon_id', $coupon->id)->where('user_id', $user->id)->where('auto_issue_key', 'signup')->count());
        $this->assertSame(2, CouponIssue::query()->where('coupon_id', $coupon->id)->where('user_id', $user->id)->count());
    }

    /**
     * 수량이 다 떨어진 가입 쿠폰만 있어도 가입 API 는 201 로 성공하고 쿠폰은 발급되지 않는다.
     *
     * @scenario case=exhausted_register_http
     *
     * @effects signup_coupon_not_issued
     *
     * @return void 반환값 없음
     */
    public function test_register_succeeds_when_signup_coupon_is_exhausted(): void
    {
        $this->syncMemberFieldDeclarations();
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP, ['total_quantity' => 1, 'issued_count' => 1]);

        $response = $this->postJson('/api/auth/register', $this->registerPayload());

        $response->assertStatus(201);
        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->count());
        $this->assertSame(1, $coupon->fresh()->issued_count);
    }

    /**
     * 발급 도중 예기치 않은 예외가 나도 가입 API 는 201 로 성공한다 — 발급은 경고 로그만 남기고 건너뛴다.
     *
     * @scenario case=issue_failure_register_http
     *
     * @effects signup_coupon_not_issued
     *
     * @return void 반환값 없음
     */
    public function test_register_succeeds_when_issuing_throws(): void
    {
        $this->syncMemberFieldDeclarations();
        $coupon = $this->makeAutoCoupon(CouponIssueCondition::SIGNUP);
        $boom = function () {
            throw new \RuntimeException('i952 발급 실패 흉내');
        };
        HookManager::addFilter('sirsoft-ecommerce.coupon.filter_auto_issue_eligible', $boom, 10);

        $payload = $this->registerPayload();
        try {
            $response = $this->postJson('/api/auth/register', $payload);
        } finally {
            HookManager::removeFilter('sirsoft-ecommerce.coupon.filter_auto_issue_eligible', $boom);
        }

        $response->assertStatus(201);
        $this->assertTrue(User::query()->where('email', $payload['email'])->exists());
        $this->assertSame(0, CouponIssue::query()->where('coupon_id', $coupon->id)->count());
    }

    /**
     * 가입 요청 본문을 만듭니다.
     *
     * @return array<string, string> 가입 요청 본문
     */
    private function registerPayload(): array
    {
        return [
            'name' => 'i952 가입 시험',
            'email' => 'i952_reg_'.uniqid().'@example.com',
            'password' => 'password1234',
            'password_confirmation' => 'password1234',
            'agree_terms' => '1',
            'agree_privacy' => '1',
        ];
    }
}
