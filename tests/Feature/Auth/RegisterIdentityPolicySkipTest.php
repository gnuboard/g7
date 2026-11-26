<?php

namespace Tests\Feature\Auth;

use App\Enums\UserStatus;
use App\Models\ActivityLog;
use App\Models\IdentityPolicy;
use App\Models\User;
use App\Services\AuthService;
use Database\Seeders\IdentityPolicySeeder;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 확장 내부 가입의 본인인증 정책 생략과 동의 기록 선택
 *
 * 회사 IdP 가 신원을 보증한 가입(SSO 자동 생성 등)은 사이트의 가입 본인인증 정책(가입 전 인증, 가입 후 인증 대기)을 따르지 않게
 * 고를 수 있다 — `register($data, ['identity_policy' => 'skip', 'identity_policy_reason' => …])`. 사유가 없으면 생략하지 않고,
 * 공개 가입 API 는 이 옵션을 받지 않는다. `record_consents => false` 면 약관 동의 기록을 남기지 않는다.
 */
class RegisterIdentityPolicySkipTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 알림·큐를 막고 기본 본인인증 정책(모두 꺼짐)을 시드합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();
        $this->seed(IdentityPolicySeeder::class);
    }

    /**
     * 가입 본인인증 정책을 켭니다.
     *
     * @param  string  ...$keys  정책 키
     * @return void 반환값 없음
     */
    private function enable(string ...$keys): void
    {
        IdentityPolicy::query()->whereIn('key', $keys)->update(['enabled' => true]);
    }

    /**
     * 가입 데이터를 만듭니다.
     *
     * @return array<string, mixed> 가입 데이터
     */
    private function data(): array
    {
        return [
            'name' => '외부 가입',
            'email' => 'skip.'.Str::lower(Str::random(8)).'@example.test',
            'agree_terms' => true,
            'agree_privacy' => true,
        ];
    }

    /**
     * 회원의 동의 기록 수를 셉니다.
     *
     * @param  User  $user  회원
     * @return int 기록 수
     */
    private function consentCount(User $user): int
    {
        return DB::table('user_consents')->where('user_id', $user->id)->count();
    }

    /**
     * 가입 전·후 정책이 켜져 있어도 생략하면 바로 활성 회원이 되고 인증 요청이 생기지 않으며 활동 로그가 남는다.
     *
     * @scenario policy=before_submit+after_create, option=skip_with_reason
     *
     * @effects skip_creates_active_user, skip_issues_no_challenge, skip_records_activity_log
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 생략하면_정책이_켜져_있어도_활성_회원이_된다(): void
    {
        $this->enable('core.auth.signup_before_submit', 'core.auth.signup_after_create');

        $user = app(AuthService::class)->register($this->data(), [
            'issue_token' => false,
            'identity_policy' => 'skip',
            'identity_policy_reason' => 'external:test-idp',
        ])['user'];

        $this->assertSame(UserStatus::Active->value, $user->fresh()->status);
        $this->assertSame(0, DB::table('identity_verification_logs')->where('user_id', $user->id)->count());
        $log = ActivityLog::query()
            ->where('action', 'user.register_identity_policy_skipped')
            ->where('user_id', $user->id)
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('external:test-idp', $log->properties['reason'] ?? null);
    }

    /**
     * 사유가 없으면 생략하지 않는다 — 가입 후 인증 대기 정책이 그대로 적용된다.
     *
     * @scenario option=skip_without_reason
     *
     * @effects skip_requires_reason
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 사유가_없으면_생략하지_않는다(): void
    {
        $this->enable('core.auth.signup_after_create');

        $user = app(AuthService::class)->register($this->data(), ['issue_token' => false, 'identity_policy' => 'skip'])['user'];

        $this->assertSame(UserStatus::PendingVerification->value, $user->fresh()->status);
    }

    /**
     * 공개 가입 API 는 생략 옵션을 받지 않는다 — 가입 전 인증 정책이 그대로 428.
     *
     * @scenario surface=public_api
     *
     * @effects public_register_ignores_skip
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 공개_가입_API_는_생략할_수_없다(): void
    {
        $this->enable('core.auth.signup_before_submit');
        $data = $this->data();

        $this->postJson('/api/auth/register', array_merge($data, [
            'password' => 'Passw0rd!new',
            'password_confirmation' => 'Passw0rd!new',
            'identity_policy' => 'skip',
            'identity_policy_reason' => 'external:forged',
        ]))->assertStatus(428);

        $this->assertDatabaseMissing('users', ['email' => $data['email']]);
    }

    /**
     * 동의 기록은 기본 2건이고 `record_consents=false` 면 0건이다.
     *
     * @scenario record_consents=default|false
     *
     * @effects consents_recorded_by_default, consents_skipped_when_disabled
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 동의_기록을_고를_수_있다(): void
    {
        $service = app(AuthService::class);

        $default = $service->register($this->data(), ['issue_token' => false])['user'];
        $this->assertSame(2, $this->consentCount($default));

        $skipped = $service->register($this->data(), ['issue_token' => false, 'record_consents' => false])['user'];
        $this->assertSame(0, $this->consentCount($skipped));
    }
}
