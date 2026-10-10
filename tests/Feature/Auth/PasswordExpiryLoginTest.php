<?php

namespace Tests\Feature\Auth;

use App\Auth\DTO\PasswordChangeRequirement;
use App\Contracts\Extension\CacheInterface;
use App\Contracts\Repositories\UserPasswordHistoryRepositoryInterface;
use App\Enums\PasswordChangeReason;
use App\Enums\UserStatus;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\PasswordChangeChallengeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 로그인 비밀번호 변경 관문 테스트 (#736 D2·D5·D11·A3·A4·A6·A16)
 *
 * 만료·관리자 지정 비밀번호는 비밀번호·2단계 인증을 통과해도 토큰 대신 변경 단계 식별자를 받고, 식별자와
 * 새 비밀번호로만 로그인이 끝난다. 경로(사용자·관리자) 묶음, 비관리자 차단, 만료·동시 제출·재사용,
 * 휴면 해제, 캐시 실패까지 브라우저로 만들 수 없는 경계를 여기서 잠근다.
 */
class PasswordExpiryLoginTest extends TestCase
{
    use CapturesSentMail;
    use PasswordPolicyFixtures;
    use RefreshDatabase;

    /**
     * 메일 발송을 막습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    /**
     * 만료된 회원(변경일 100일 전)을 만듭니다.
     *
     * @param  bool  $admin  관리자로 만들지
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return User 회원
     */
    private function expiredUser(bool $admin = false, array $attributes = []): User
    {
        $attributes = array_merge(['password_changed_at' => now()->subDays(100)], $attributes);

        return $admin ? $this->makeAdmin($attributes) : $this->makeMember($attributes);
    }

    /**
     * 로그인을 요청합니다.
     *
     * @param  User  $user  회원
     * @param  bool  $admin  관리자 로그인 경로인지
     * @return TestResponse 응답
     */
    private function login(User $user, bool $admin = false): TestResponse
    {
        return $this->postJson($admin ? '/api/auth/admin/login' : '/api/auth/login', [
            'email' => $user->email,
            'password' => 'Passw0rd!old',
        ]);
    }

    /**
     * 변경 단계를 완료합니다.
     *
     * @param  string  $challengeId  변경 단계 식별자
     * @param  string  $password  새 비밀번호
     * @param  bool  $admin  관리자 경로인지
     * @return TestResponse 응답
     */
    private function complete(string $challengeId, string $password = 'Passw0rd!new', bool $admin = false): TestResponse
    {
        return $this->postJson(($admin ? '/api/auth/admin' : '/api/auth').'/login/password-change', [
            'challenge_id' => $challengeId,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    /**
     * 만료되지 않은 회원은 종전대로 토큰을 받는다.
     *
     * @scenario guard=user|admin, reason=none
     *
     * @effects unexpired_login_issues_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function unexpired_users_get_a_token(): void
    {
        $this->enableExpiry();

        $this->login($this->makeMember(['password_changed_at' => now()->subDays(10)]))->assertOk()->assertJsonPath('data.token_type', 'Bearer');
        $this->login($this->makeAdmin(['password_changed_at' => now()->subDays(10)]), true)->assertOk()->assertJsonPath('data.token_type', 'Bearer');
    }

    /**
     * '변경해야 로그인' 모드의 만료 회원은 토큰 대신 변경 단계를 받고, 새 비밀번호로 로그인이 끝난다.
     *
     * @scenario guard=user|admin, reason=expired, action=force
     *
     * @effects expired_login_returns_challenge_without_token, completion_issues_token_and_records_change
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function expired_force_requires_a_change_before_a_token(): void
    {
        $this->enableExpiry();

        foreach ([false, true] as $admin) {
            $user = $this->expiredUser($admin);
            $this->issueToken($user, 'old-device');

            $response = $this->login($user, $admin)->assertOk();
            $this->assertTrue((bool) $response->json('data.password_change_required'));
            $this->assertSame('expired', $response->json('data.reason'));
            $this->assertFalse((bool) $response->json('data.can_postpone'));
            $this->assertNull($response->json('data.token'), '변경 전에 토큰을 주면 관문이 무력화된다');
            $this->assertSame(1, $user->tokens()->count(), '관문 응답은 새 토큰을 만들지 않는다');

            $done = $this->complete($response->json('data.challenge_id'), 'Passw0rd!new', $admin)->assertOk();
            $this->assertNotEmpty($done->json('data.token'));

            $fresh = $user->fresh();
            $this->assertTrue(Hash::check('Passw0rd!new', $fresh->password));
            $this->assertTrue($fresh->password_changed_at->isToday());
            $this->assertSame(1, $fresh->tokens()->count(), '옛 기기는 로그아웃되고 새 토큰 하나만 남는다');
        }
    }

    /**
     * '다음에 변경 허용' 모드는 연기할 수 있고, 연기 기간 중에는 통과, 끝나면 다시 요구한다.
     *
     * @scenario guard=user|admin, reason=expired, action=remind
     *
     * @effects remind_mode_allows_postpone_until_deadline
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function remind_mode_allows_postponing_until_the_deadline(): void
    {
        $this->enableExpiry(['password_expiry_action' => 'remind', 'password_expiry_remind_days' => 14]);

        foreach ([false, true] as $admin) {
            $user = $this->expiredUser($admin);

            $first = $this->login($user, $admin)->assertOk();
            $this->assertTrue((bool) $first->json('data.can_postpone'));
            $this->assertSame(14, $first->json('data.postpone_days'));

            $postponed = $this->postJson(($admin ? '/api/auth/admin' : '/api/auth').'/login/password-change/postpone', ['challenge_id' => $first->json('data.challenge_id')])->assertOk();
            $this->assertNotEmpty($postponed->json('data.token'));
            $this->assertNotNull($postponed->json('data.postponed_until'), '화면이 연기 종료일을 안내하려면 응답에 실려야 한다');
            $this->assertNotNull($user->fresh()->password_change_postponed_until);

            $this->login($user, $admin)->assertOk()->assertJsonPath('data.token_type', 'Bearer');

            $this->travel(15)->days();
            $this->assertTrue((bool) $this->login($user, $admin)->json('data.password_change_required'), '연기 기간이 끝나면 다시 요구한다');
            $this->travelBack();
        }
    }

    /**
     * 관리자가 정해 준 비밀번호는 만료 기능이 꺼져 있어도 변경을 요구하고 연기할 수 없다(D11).
     *
     * @scenario guard=user|admin, reason=admin_assigned, expiry=off
     *
     * @effects admin_assigned_requires_change_even_when_expiry_off
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_assigned_password_requires_a_change_even_when_expiry_is_off(): void
    {
        foreach ([false, true] as $admin) {
            $attributes = ['password_change_required' => true, 'password_changed_at' => now()];
            $user = $admin ? $this->makeAdmin($attributes) : $this->makeMember($attributes);
            $prefix = $admin ? '/api/auth/admin' : '/api/auth';

            $response = $this->login($user, $admin)->assertOk();
            $this->assertSame('admin_assigned', $response->json('data.reason'));
            $this->assertFalse((bool) $response->json('data.can_postpone'));

            $this->postJson($prefix.'/login/password-change/postpone', ['challenge_id' => $response->json('data.challenge_id')])
                ->assertStatus(422)->assertJsonValidationErrors(['challenge_id']);

            $this->complete($this->login($user, $admin)->json('data.challenge_id'), 'Passw0rd!new', $admin)->assertOk();
            $this->assertFalse($user->fresh()->password_change_required);
        }
    }

    /**
     * 연기할 수 없는 단계(변경해야 로그인·관리자가 정해 준 비밀번호)에서 연기를 부르면 상위 안내도 「연기 불가」다.
     * 식별자는 유효한데 「단계가 만료되었거나 올바르지 않습니다」로 답하면 사용자는 처음부터 다시 로그인하려 든다(7.1.0 실측 O-3).
     * 식별자 자체가 무효면 종전대로 「만료되었거나 올바르지 않음」이다.
     *
     * @scenario guard=user|admin, reason=expired|admin_assigned, action=force
     *
     * @effects postpone_refusal_message_matches_reason
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function postpone_refusal_reports_postpone_not_allowed_not_invalid_challenge(): void
    {
        $this->enableExpiry();

        foreach ([false, true] as $admin) {
            $prefix = $admin ? '/api/auth/admin' : '/api/auth';

            $expired = $this->expiredUser($admin);
            $challengeId = $this->login($expired, $admin)->assertOk()->json('data.challenge_id');
            $this->postJson($prefix.'/login/password-change/postpone', ['challenge_id' => $challengeId])
                ->assertStatus(422)
                ->assertJsonPath('message', __('auth.password_change_postpone_not_allowed'))
                ->assertJsonPath('errors.challenge_id.0', __('auth.password_change_postpone_not_allowed'));

            // 거절된 연기는 식별자를 쓰지 않는다 — 같은 식별자로 변경을 마칠 수 있다.
            $this->complete($challengeId, 'Passw0rd!new', $admin)->assertOk();

            $this->postJson($prefix.'/login/password-change/postpone', ['challenge_id' => '00000000-0000-4000-8000-000000000000'])
                ->assertStatus(422)
                ->assertJsonPath('message', __('auth.password_change_invalid_challenge'));
        }
    }

    /**
     * 2단계 인증이 켜져 있으면 코드 확인이 먼저고 변경 단계가 그 뒤다(A4).
     *
     * @scenario guard=user|admin, reason=expired, two_factor=on
     *
     * @effects two_factor_precedes_password_change
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function two_factor_comes_before_the_password_change_step(): void
    {
        // 본인인증 메일은 7.1.0 부터 알림 정의(category=identity)로 보낸다 — 옛 IdentityMessageDefinitionSeeder 는 건너뛰는 사용 중단 클래스다.
        $this->prepareIdentityNotifications();
        $this->enableExpiry();
        $this->setSecurity(['two_factor_auth' => true]);

        foreach ([false, true] as $admin) {
            $user = $this->expiredUser($admin);
            $prefix = $admin ? '/api/auth/admin' : '/api/auth';

            $first = $this->login($user, $admin)->assertOk();
            $this->assertTrue((bool) $first->json('data.two_factor_required'), '2단계 인증이 먼저다');
            $this->assertNull($first->json('data.password_change_required'));

            $challengeId = $first->json('data.challenge_id');
            $log = IdentityVerificationLog::find($challengeId);
            $metadata = $log->metadata ?? [];
            $metadata['code_hash'] = Hash::make('135790');
            $log->metadata = $metadata;
            $log->save();

            $second = $this->postJson($prefix.'/login/two-factor', ['challenge_id' => $challengeId, 'code' => '135790'])->assertOk();
            $this->assertTrue((bool) $second->json('data.password_change_required'));
            $this->assertNull($second->json('data.token'));

            $this->complete($second->json('data.challenge_id'), 'Passw0rd!new', $admin)->assertOk();
        }
    }

    /**
     * 사용자 경로의 식별자를 관리자 경로에 쓰면 무효다(경로 묶음).
     *
     * @scenario guard=cross
     *
     * @effects challenge_bound_to_guard
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_user_challenge_is_invalid_on_the_admin_endpoint(): void
    {
        $this->enableExpiry();
        $admin = $this->expiredUser(true);

        $challengeId = $this->login($admin)->json('data.challenge_id');

        $this->complete($challengeId, 'Passw0rd!new', true)->assertStatus(422)->assertJsonValidationErrors(['challenge_id']);
        $this->assertTrue(Hash::check('Passw0rd!old', $admin->fresh()->password));
    }

    /**
     * 관리자 경로의 식별자를 사용자 경로에 써도 무효다(경로 묶음 — 반대 방향).
     *
     * @scenario guard=cross
     *
     * @effects challenge_bound_to_guard
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function an_admin_challenge_is_invalid_on_the_user_endpoint(): void
    {
        $this->enableExpiry();
        $admin = $this->expiredUser(true);

        $challengeId = $this->login($admin, true)->assertOk()->json('data.challenge_id');
        $this->assertNotEmpty($challengeId);

        $this->complete($challengeId)->assertStatus(422)->assertJsonValidationErrors(['challenge_id']);
        $this->assertTrue(Hash::check('Passw0rd!old', $admin->fresh()->password));

        // 대조군: 같은 식별자가 발급 경로에서는 유효하다 — 위 422 가 식별자 자체가 아니라 경로 불일치 때문임을 보인다
        $this->complete($challengeId, 'Passw0rd!new', true)->assertOk();
    }

    /**
     * 비관리자의 관리자 로그인은 변경 단계를 열지 않고 종전처럼 403 + 토큰 회수(A16).
     *
     * @scenario guard=admin, actor=non_admin, reason=expired
     *
     * @effects non_admin_admin_login_gets_403_without_challenge
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function non_admin_on_admin_login_gets_403_without_a_challenge(): void
    {
        $this->enableExpiry();
        $member = $this->expiredUser();

        $response = $this->login($member, true)->assertForbidden();
        $this->assertNull($response->json('data.challenge_id'));
        $this->assertSame(0, $member->tokens()->count());
    }

    /**
     * 관리자 경로 식별자를 가진 비관리자는 비밀번호를 쓰기 전에 403 으로 막힌다(A16).
     *
     * @scenario guard=admin, actor=non_admin, proof=forged_admin_challenge
     *
     * @effects admin_completion_checks_admin_before_write
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function admin_completion_rejects_a_non_admin_before_writing(): void
    {
        $member = $this->makeMember(['password_change_required' => true]);
        $challengeId = app(PasswordChangeChallengeService::class)->issue($member, 'admin', new PasswordChangeRequirement(PasswordChangeReason::AdminAssigned));

        $this->complete($challengeId, 'Passw0rd!new', true)->assertForbidden();
        $this->assertTrue(Hash::check('Passw0rd!old', $member->fresh()->password), '비관리자의 비밀번호가 관리자 경로로 바뀌었다');
    }

    /**
     * 식별자는 10분 뒤 만료되고, 한 번 쓴 식별자는 다시 쓸 수 없다.
     *
     * @scenario guard=user|admin, challenge=expired|consumed
     *
     * @effects challenge_expires_and_is_single_use
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function challenges_expire_and_are_single_use(): void
    {
        $this->enableExpiry();

        foreach ([false, true] as $admin) {
            $user = $this->expiredUser($admin);

            $stale = $this->login($user, $admin)->json('data.challenge_id');
            $this->travel(11)->minutes();
            $this->complete($stale, 'Passw0rd!new', $admin)->assertStatus(422)->assertJsonValidationErrors(['challenge_id']);

            $fresh = $this->login($user, $admin)->json('data.challenge_id');
            $this->complete($fresh, 'Passw0rd!new', $admin)->assertOk();
            $this->complete($fresh, 'Passw0rd!other', $admin)->assertStatus(422)->assertJsonValidationErrors(['challenge_id']);
        }
    }

    /**
     * 지금 비밀번호와 같은 값은 재사용 설정과 무관하게 거부하고, 재사용 금지가 켜져 있으면 최근 비밀번호도 거부한다.
     *
     * @scenario guard=user|admin, input_state=current_password|recent_password
     *
     * @effects login_stage_rejects_current_and_reused
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function the_change_step_rejects_the_current_and_recent_passwords(): void
    {
        $this->enableExpiry();

        foreach ([false, true] as $admin) {
            $this->setSecurity(['password_reuse_prevention_enabled' => false]);
            $user = $this->expiredUser($admin);
            app(UserPasswordHistoryRepositoryInterface::class)->push($user->id, Hash::make('Passw0rd!prev'));

            $challengeId = $this->login($user, $admin)->json('data.challenge_id');

            $this->complete($challengeId, 'Passw0rd!old', $admin)->assertStatus(422)->assertJsonValidationErrors(['password']);
            $this->complete($challengeId, 'Passw0rd!prev', $admin)->assertOk();

            $this->setSecurity(['password_reuse_prevention_enabled' => true, 'password_reuse_count' => 3]);
            $other = $this->expiredUser($admin);
            app(UserPasswordHistoryRepositoryInterface::class)->push($other->id, Hash::make('Passw0rd!prev'));

            $this->complete($this->login($other, $admin)->json('data.challenge_id'), 'Passw0rd!prev', $admin)
                ->assertStatus(422)->assertJsonValidationErrors(['password']);
        }
    }

    /**
     * 확인 칸이 새 비밀번호와 다르면 그 오류는 확인 칸(`password_confirmation`)에 붙는다 — 화면이 확인 칸 아래에 띄운다
     * (2026-10-04 결정, 디자인 캔버스 AdminLoginError). 새 비밀번호 칸에는 오류가 없다.
     *
     * @scenario guard=user|admin
     *
     * @effects confirmation_mismatch_reported_on_confirmation_field
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_confirmation_mismatch_is_reported_on_the_confirmation_field(): void
    {
        $this->enableExpiry();

        foreach ([false, true] as $admin) {
            $user = $this->expiredUser($admin);
            $challengeId = $this->login($user, $admin)->json('data.challenge_id');
            $prefix = $admin ? '/api/auth/admin' : '/api/auth';

            $response = $this->postJson("{$prefix}/login/password-change", [
                'challenge_id' => $challengeId,
                'password' => 'Passw0rd!new',
                'password_confirmation' => 'Passw0rd!other',
            ])->assertStatus(422);

            $response->assertJsonValidationErrors(['password_confirmation']);
            $response->assertJsonMissingValidationErrors(['password']);
            $this->assertSame([__('validation.auth.password.confirmed')], $response->json('errors.password_confirmation'));
        }
    }

    /**
     * 휴면 회원도 변경을 마치면 휴면이 풀리고 응답에 그 사실이 실린다.
     *
     * @scenario guard=user|admin, reason=expired, dormant=yes
     *
     * @effects dormant_user_restored_after_change
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function dormant_users_are_restored_after_the_change(): void
    {
        $this->enableExpiry();

        foreach ([false, true] as $admin) {
            $user = $this->expiredUser($admin, ['status' => UserStatus::Dormant->value, 'dormant_at' => now()->subDay()]);

            $response = $this->complete($this->login($user, $admin)->json('data.challenge_id'), 'Passw0rd!new', $admin)->assertOk();

            $this->assertTrue((bool) $response->json('data.dormancy_restored'));
            $this->assertSame(UserStatus::Active->value, $user->fresh()->status);
        }
    }

    /**
     * 잠긴 계정은 변경 단계를 완료할 수 없다(423).
     *
     * @scenario guard=user|admin, locked=yes
     *
     * @effects locked_account_cannot_complete
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function locked_accounts_cannot_complete_the_change(): void
    {
        $this->enableExpiry();

        foreach ([false, true] as $admin) {
            $user = $this->expiredUser($admin);
            $challengeId = $this->login($user, $admin)->json('data.challenge_id');

            $user->forceFill(['locked_until' => now()->addMinutes(30)])->save();

            $this->complete($challengeId, 'Passw0rd!new', $admin)->assertStatus(423);
            $this->assertTrue(Hash::check('Passw0rd!old', $user->fresh()->password));
        }
    }

    /**
     * 같은 식별자를 처리 중이면 두 번째 제출은 422(처리 중)다.
     *
     * @scenario guard=user|admin, concurrency=lock_held
     *
     * @effects concurrent_submission_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_concurrent_submission_is_rejected(): void
    {
        $this->enableExpiry();

        foreach ([false, true] as $admin) {
            $user = $this->expiredUser($admin);
            $challengeId = $this->login($user, $admin)->json('data.challenge_id');

            $lock = Cache::lock(app(PasswordChangeChallengeService::class)->lockKey($challengeId), 10);
            $this->assertTrue($lock->get());

            try {
                $response = $this->complete($challengeId, 'Passw0rd!new', $admin)->assertStatus(422);
                $this->assertSame([__('auth.password_change_in_progress')], $response->json('errors.challenge_id'));
                $this->assertTrue(Hash::check('Passw0rd!old', $user->fresh()->password));
            } finally {
                $lock->release();
            }
        }
    }

    /**
     * 식별자를 저장하지 못하면 토큰을 주지 않고 503 이다.
     *
     * @scenario guard=user|admin, cache=put_fails
     *
     * @effects cache_failure_returns_503
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function a_cache_failure_returns_503(): void
    {
        $this->enableExpiry();

        $cache = \Mockery::mock(CacheInterface::class);
        $cache->shouldReceive('get')->andReturn(null);
        $cache->shouldReceive('put')->andReturn(false);
        $cache->shouldReceive('forget')->andReturn(true);
        // 전역 캐시는 다른 부팅 경로도 쓰므로 식별자 서비스에만 실패하는 캐시를 쥐여 준다.
        $this->app->instance(PasswordChangeChallengeService::class, new PasswordChangeChallengeService($cache));

        foreach ([false, true] as $admin) {
            $user = $this->expiredUser($admin);
            $tokensBefore = $user->tokens()->count();

            $response = $this->login($user, $admin)->assertStatus(503);
            $this->assertSame(__('auth.password_change_unavailable'), $response->json('message'));
            $this->assertNull($response->json('data.token'));
            $this->assertSame($tokensBefore, $user->tokens()->count());
        }
    }

    /**
     * 새 엔드포인트는 로그인 전 호출이라 인증 미들웨어(401)에 막히지 않는다.
     *
     * @scenario guard=user|admin, auth=none
     *
     * @effects endpoints_reachable_without_token
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function new_endpoints_are_reachable_without_a_token(): void
    {
        $unknown = '00000000-0000-4000-8000-000000000000';

        foreach (['/api/auth', '/api/auth/admin'] as $prefix) {
            $this->postJson($prefix.'/login/password-change', [
                'challenge_id' => $unknown,
                'password' => 'Passw0rd!new',
                'password_confirmation' => 'Passw0rd!new',
            ])->assertStatus(422);

            $this->postJson($prefix.'/login/password-change/postpone', ['challenge_id' => $unknown])->assertStatus(422);
        }
    }
}
