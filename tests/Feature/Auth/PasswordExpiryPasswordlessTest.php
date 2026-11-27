<?php

namespace Tests\Feature\Auth;

use App\Extension\HookManager;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Services\PasswordExpiryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 비밀번호 없는 회원은 비밀번호 만료 정책 비대상 (K4 · R3)
 *
 * 만료될 비밀번호가 없으므로 적용 판정·배치 후보·사전 안내·만료 처리(토큰 회수)·로그인 변경 관문·관리자 상세 표시
 * 어디에도 잡히지 않는다. 같은 조건의 비밀번호 회원은 종전대로 잡혀 대조군이 된다.
 */
class PasswordExpiryPasswordlessTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 메일 발송을 막고 만료를 켭니다 ('변경해야 로그인' 모드 — 토큰 회수 경로 포함).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        $this->enableExpiry(['password_expiry_action' => 'force']);
    }

    /**
     * 만료 조건(변경일 100일 전)인 두 회원을 만듭니다 — 비밀번호 없음 / 있음.
     *
     * @return array{0: User, 1: User} [비밀번호 없는 회원, 비밀번호 회원]
     */
    private function expiredPair(): array
    {
        $suffix = Str::lower(Str::random(8));

        $passwordless = $this->makeMember([
            'email' => "pwless.{$suffix}@example.test",
            'password' => null,
            'password_changed_at' => now()->subDays(100),
        ]);
        $holder = $this->makeMember([
            'email' => "pwset.{$suffix}@example.test",
            'password_changed_at' => now()->subDays(100),
        ]);

        return [$passwordless, $holder];
    }

    /**
     * 적용 판정과 SQL 판정 둘 다 비밀번호 없는 회원을 뺀다.
     *
     * @scenario password=none|set, judge=php|sql
     *
     * @effects policy_excludes_passwordless, candidate_query_excludes_passwordless
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 적용_판정과_후보_쿼리가_비밀번호_없는_회원을_뺀다(): void
    {
        [$passwordless, $holder] = $this->expiredPair();
        $service = app(PasswordExpiryService::class);

        $this->assertFalse($service->policyAppliesTo($passwordless));
        $this->assertTrue($service->policyAppliesTo($holder));

        $noticeIds = $service->noticeQuery()->pluck('id')->all();
        $expireIds = $service->expireQuery()->pluck('id')->all();

        $this->assertNotContains($passwordless->id, $expireIds);
        $this->assertNotContains($passwordless->id, $noticeIds);
        $this->assertContains($holder->id, $expireIds);
    }

    /**
     * 사전 안내·만료 처리 배치가 비밀번호 없는 회원에게 안내를 보내지 않고 토큰도 회수하지 않는다.
     *
     * @scenario password=none|set, batch=notify|expire, action=force
     *
     * @effects no_notice_for_passwordless, no_token_revoke_for_passwordless
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 배치가_안내도_토큰_회수도_하지_않는다(): void
    {
        [$passwordless, $holder] = $this->expiredPair();
        $this->issueToken($passwordless, 'device-a');
        $this->issueToken($holder, 'device-b');

        $touched = [];
        foreach (['core.auth.password_expiry_notice', 'core.auth.password_expired'] as $hook) {
            HookManager::addAction($hook, function (User $user) use (&$touched, $hook) {
                $touched[$hook][] = $user->id;
            }, 10);
        }

        $service = app(PasswordExpiryService::class);
        $service->notifyUpcoming(false);
        $service->processExpired(false);

        $this->assertNotContains($passwordless->id, $touched['core.auth.password_expiry_notice'] ?? []);
        $this->assertNotContains($passwordless->id, $touched['core.auth.password_expired'] ?? []);
        $this->assertContains($holder->id, $touched['core.auth.password_expired'] ?? []);

        $this->assertSame(1, $passwordless->tokens()->count(), '비밀번호가 없는 회원을 주기적으로 로그아웃시키면 안 된다');
        $this->assertSame(0, $holder->tokens()->count());
    }

    /**
     * 로그인 변경 관문은 만료·관리자 지정 둘 다 비밀번호 없는 회원에게 요구하지 않는다.
     *
     * @scenario password=none, reason=expired|admin_assigned
     *
     * @effects login_requirement_null_for_passwordless
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 로그인_변경_관문이_요구하지_않는다(): void
    {
        [$passwordless] = $this->expiredPair();
        $service = app(PasswordExpiryService::class);

        $this->assertNull($service->loginRequirement($passwordless));

        $passwordless->forceFill(['password_change_required' => true])->save();
        $this->assertNull($service->loginRequirement($passwordless->fresh()), '바꿀 비밀번호가 없는데 변경을 요구하면 변경하는 순간 의도 없이 비밀번호 회원이 된다');
    }

    /**
     * 관리자 상세·마이페이지의 만료 표시가 「해당 없음」 이다.
     *
     * @scenario password=none, surface=admin_detail|profile
     *
     * @effects expiry_display_not_applicable
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 만료_표시가_해당_없음이다(): void
    {
        [$passwordless] = $this->expiredPair();
        $this->actingAs($passwordless);

        $resource = new UserResource($passwordless->fresh());
        $status = $resource->passwordStatusArray();
        $profile = $resource->toProfileArray();

        $this->assertFalse($status['applies']);
        $this->assertNull($status['expires_at']);
        $this->assertNull($status['days_left']);
        $this->assertNull($profile['password_expires_at']);
        $this->assertNull($profile['password_expiry_days_left']);
    }
}
