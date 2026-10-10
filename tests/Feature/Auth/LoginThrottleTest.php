<?php

namespace Tests\Feature\Auth;

use App\Models\PasswordResetToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * 로그인 시도 제한 / 계정 잠금 통합 테스트.
 *
 * 보안 환경설정의 `login_attempt_enabled / max_login_attempts /
 * login_lockout_time` 키가 실제 인증 흐름에 적용되는지 검증합니다.
 *
 * 시나리오 매트릭스 (axis × axis):
 *  - login_attempt_enabled ∈ {true, false}
 *  - 잠금 상태 ∈ {none, active, expired}
 *  - 자격 증명 ∈ {valid, invalid}
 *  - 실패 간격 ∈ {차단 시간 안, 차단 시간 밖}
 *  - 해제 경로 ∈ {시간 경과, 로그인 성공, 비밀번호 재설정}
 */
class LoginThrottleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 보안 환경설정을 테스트용 값으로 고정하고 시도 제한 카운트를 비웁니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 환경설정을 테스트 친화적 값으로 강제
        $this->setSecuritySettings([
            'login_attempt_enabled' => true,
            'max_login_attempts' => 3,
            'login_lockout_time' => 5,
            'auth_token_lifetime' => 30,
        ]);

        // RateLimiter 캐시 초기화 — 다른 테스트 잔여 카운트 영향 방지
        Cache::flush();
    }

    /**
     * 비밀번호를 임계 횟수만큼 틀리면 계정이 잠기고, 잠긴 동안은 정답도 423 으로 막힌다.
     *
     * @scenario login_attempt_enabled=true, user_existence=exists, credential=invalid
     *
     * @effects counter_reaches_threshold_triggers_lock, account_locked_response_status_423
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 비밀번호_3회_연속_실패_후_4회째_시도_시_423_으로_차단된다(): void
    {
        $user = User::factory()->create([
            'email' => 'lockme@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'lockme@example.com',
                'password' => 'wrong-password',
            ]);
            $response->assertStatus(401);
        }

        // 4회째 — 잠금 상태로 423 Locked 응답
        $response = $this->postJson('/api/auth/login', [
            'email' => 'lockme@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(423);
        $response->assertJsonPath('success', false);

        $user->refresh();
        $this->assertNotNull($user->locked_until);
        $this->assertTrue($user->locked_until->isFuture());
    }

    /**
     * 잠금 해제 시각이 지난 계정은 정상 로그인되고 잠금 상태가 비워진다.
     *
     * @scenario lock_state=expired, credential=valid
     *
     * @effects lockout_expires_naturally_after_locked_until
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 잠금_해제_시각이_지나면_정상_로그인_가능하다(): void
    {
        $user = User::factory()->create([
            'email' => 'expired@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'locked_until' => now()->subMinutes(1), // 이미 만료
            'failed_login_attempts' => 0,
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'expired@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $user->refresh();
        $this->assertNull($user->locked_until);
        $this->assertSame(0, (int) $user->failed_login_attempts);
    }

    /**
     * 로그인에 성공하면 실패 카운터·잠금 시각·마지막 실패 시각이 모두 초기화된다.
     *
     * @scenario lock_state=none, credential=valid, unlock_path=successful_login
     *
     * @effects successful_login_resets_failed_attempts, successful_login_resets_locked_until, successful_login_resets_last_failed_login_at
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 정상_로그인_성공_시_실패_카운터가_리셋된다(): void
    {
        $user = User::factory()->create([
            'email' => 'reset@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'failed_login_attempts' => 2,
            'last_failed_login_at' => now()->subMinute(),
        ]);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'reset@example.com',
            'password' => 'correct-password',
        ]);

        $response->assertStatus(200);

        $user->refresh();
        $this->assertSame(0, (int) $user->failed_login_attempts);
        $this->assertNull($user->locked_until);
        $this->assertNull($user->last_failed_login_at);
    }

    /**
     * 로그인 시도 제한을 끈 사이트는 몇 번을 틀려도 잠그지 않는다.
     *
     * @scenario login_attempt_enabled=false, credential=invalid
     *
     * @effects lockout_disabled_setting_skips_pre_check_and_mutation
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function login_attempt_enabled_가_off_이면_무제한_시도_허용된다(): void
    {
        $this->setSecuritySettings([
            'login_attempt_enabled' => false,
            'max_login_attempts' => 3,
            'login_lockout_time' => 5,
            'auth_token_lifetime' => 30,
        ]);

        $user = User::factory()->create([
            'email' => 'unlimited@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        // 5번 실패해도 잠금 안 됨
        for ($i = 1; $i <= 5; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'unlimited@example.com',
                'password' => 'wrong-password',
            ]);
            $response->assertStatus(401);
        }

        // 정상 비밀번호로 로그인 가능
        $response = $this->postJson('/api/auth/login', [
            'email' => 'unlimited@example.com',
            'password' => 'correct-password',
        ]);
        $response->assertStatus(200);

        $user->refresh();
        $this->assertNull($user->locked_until);
    }

    /**
     * 실패 카운터가 임계에 도달하면 잠금 시각이 기록되고 카운터는 0 으로 되돌아간다.
     *
     * @scenario login_attempt_enabled=true, user_existence=exists, credential=invalid
     *
     * @effects counter_reaches_threshold_triggers_lock, lock_event_resets_counter_to_zero
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 카운터가_임계값_도달하면_잠금_시각과_함께_카운터가_0으로_리셋된다(): void
    {
        $user = User::factory()->create([
            'email' => 'threshold@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/login', [
                'email' => 'threshold@example.com',
                'password' => 'wrong-password',
            ]);
        }

        $user->refresh();
        // 잠금 처리 시 카운터 0 으로 리셋 (다음 윈도우 시작점)
        $this->assertSame(0, (int) $user->failed_login_attempts);
        $this->assertNotNull($user->locked_until);
        $this->assertTrue($user->locked_until->isFuture());
    }

    /**
     * 존재하지 않는 이메일의 실패는 DB 에 아무것도 남기지 않는다.
     *
     * @scenario user_existence=not_exists, credential=invalid
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 존재하지_않는_이메일은_db에_카운트_저장되지_않는다(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'ghost@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401);
        // 존재하지 않는 이메일에 대해서는 user 가 없으므로 DB 변경 없음 — IP throttle 이 백업
        $this->assertDatabaseMissing('users', ['email' => 'ghost@example.com']);
    }

    /**
     * IP 단위 분당 상한을 넘으면 영문 원문이 아닌 다국어 문구와 재시도 시각으로 응답한다.
     *
     * @scenario two_factor=off, controller=user, delivery=sent
     *
     * @effects too_many_attempts_message_translated, per_ip_throttle_blocks_burst_attempts_with_429
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 분당_요청_상한을_넘으면_다국어_문구로_응답한다(): void
    {
        // 기본 응답은 영문 "Too Many Attempts." 이다 — 로그인 화면이 그 문구를 그대로
        // 노출하므로, 한국어 사이트에서 영문 원문이 오류 박스에 뜬다.
        $limit = max(30, 3 * 6);

        for ($i = 0; $i <= $limit; $i++) {
            $response = $this->postJson('/api/auth/login', [
                'email' => 'ghost@example.com',
                'password' => 'wrong-password',
            ]);

            if ($response->getStatusCode() === 429) {
                break;
            }
        }

        $response->assertStatus(429);
        $this->assertNotSame('Too Many Attempts.', $response->json('message'));
        $this->assertStringNotContainsString(':seconds', (string) $response->json('message'));
        // 영어 문구는 `초 단수|복수` 꼴이라 남은 초로 고른 문장과 비교한다 (2026-10-08 결정: 1이면 단수).
        $seconds = (int) $response->headers->get('Retry-After');
        $this->assertSame(
            trans_choice('auth.too_many_attempts', $seconds, ['seconds' => $seconds]),
            $response->json('message')
        );
        $this->assertStringNotContainsString('|', (string) $response->json('message'));
        // 재시도 시점 안내는 그대로 유지되어야 한다.
        $this->assertNotNull($response->headers->get('Retry-After'));
    }

    /**
     * 차단 시간보다 오래전의 실패는 잊고 1 부터 다시 센다 — 드문 오타가 며칠에 걸쳐 쌓여 잠기지 않는다.
     *
     * @scenario login_attempt_enabled=true, user_existence=exists, credential=invalid, failure_gap=outside_window
     *
     * @effects stale_failures_restart_count_after_lockout_window
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 차단_시간이_지난_뒤의_실패는_1부터_다시_센다(): void
    {
        $user = User::factory()->create([
            'email' => 'sparse@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 2; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'sparse@example.com', 'password' => 'wrong-password'])
                ->assertStatus(401);
        }

        // 차단 시간(5분)이 지난 뒤의 실패 — 앞의 2회와 이어지지 않는다
        $this->travel(6)->minutes();

        for ($i = 1; $i <= 2; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'sparse@example.com', 'password' => 'wrong-password'])
                ->assertStatus(401);
        }

        $user->refresh();
        $this->assertNull($user->locked_until, '차단 시간 밖의 실패까지 합산해 잠겼다 (2+2 ≥ 3)');
        $this->assertSame(2, (int) $user->failed_login_attempts);

        // 정답은 그대로 통과한다
        $this->postJson('/api/auth/login', ['email' => 'sparse@example.com', 'password' => 'correct-password'])
            ->assertStatus(200);
    }

    /**
     * 차단 시간 안의 실패는 사이에 시간이 조금 흘러도 연속으로 센다.
     *
     * @scenario login_attempt_enabled=true, user_existence=exists, credential=invalid, failure_gap=inside_window
     *
     * @effects failures_inside_window_accumulate_to_lock
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 차단_시간_안의_실패는_누적되어_잠긴다(): void
    {
        $user = User::factory()->create([
            'email' => 'dense@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'dense@example.com', 'password' => 'wrong-password'])
                ->assertStatus(401);
            $this->travel(4)->minutes(); // 매번 차단 시간(5분)보다 짧은 간격
        }

        $user->refresh();
        $this->assertNotNull($user->locked_until, '차단 시간 안의 연속 실패가 잠금으로 이어지지 않았다');
    }

    /**
     * 차단 시간 0(무기한 잠금) 사이트는 실패를 기간 없이 누적한다(운영자가 고른 가장 엄격한 정책 유지).
     *
     * @scenario lockout_minutes_setting=zero, credential=invalid, failure_gap=outside_window
     *
     * @effects zero_lockout_keeps_accumulating_without_window
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 차단_시간_0이면_기간과_무관하게_누적되어_무기한_잠긴다(): void
    {
        $this->setSecuritySettings(['login_lockout_time' => 0]);

        $user = User::factory()->create([
            'email' => 'strict@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 2; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'strict@example.com', 'password' => 'wrong-password']);
        }

        $this->travel(1)->day();

        $this->postJson('/api/auth/login', ['email' => 'strict@example.com', 'password' => 'wrong-password']);

        $user->refresh();
        $this->assertTrue((bool) $user->locked_permanently, '차단 시간 0 사이트에서 하루 전 실패가 잊혔다');
    }

    /**
     * 잠긴 계정의 주인이 비밀번호 재설정을 마치면 잠금이 풀리고 새 비밀번호로 바로 로그인된다.
     *
     * @scenario lock_state=active, unlock_path=password_reset
     *
     * @effects password_reset_unlocks_account, password_reset_unlock_logged_in_activity_log
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 잠긴_계정은_비밀번호를_재설정하면_바로_풀린다(): void
    {
        $user = $this->lockedUser('reset-unlock@example.com', permanent: false);

        $this->resetPassword($user->email, 'NewPassw0rd!')->assertStatus(200);

        $user->refresh();
        $this->assertNull($user->locked_until);
        $this->assertFalse((bool) $user->locked_permanently);
        $this->assertSame(0, (int) $user->failed_login_attempts);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'NewPassw0rd!'])
            ->assertStatus(200);

        $this->assertDatabaseHas('activity_logs', [
            'action' => 'auth.account_unlocked',
            'description_key' => 'activity_log.description.auth_account_unlocked_by_password_reset',
        ]);
    }

    /**
     * 무기한 잠금도 비밀번호 재설정으로 풀린다 — 관리자가 없으면 영원히 못 푸는 경우라 복구 경로가 가장 필요하다.
     *
     * @scenario lock_state=permanent, unlock_path=password_reset
     *
     * @effects password_reset_unlocks_permanent_lock
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 무기한_잠금도_비밀번호_재설정으로_풀린다(): void
    {
        $user = $this->lockedUser('reset-unlock-perm@example.com', permanent: true);

        $this->resetPassword($user->email, 'NewPassw0rd!')->assertStatus(200);

        $user->refresh();
        $this->assertFalse((bool) $user->locked_permanently);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'NewPassw0rd!'])
            ->assertStatus(200);
    }

    /**
     * 잠기지 않은 계정의 재설정은 잠금 해제 기록을 남기지 않는다(해제 로그는 실제로 풀렸을 때만).
     *
     * @scenario lock_state=none, unlock_path=password_reset
     *
     * @effects password_reset_without_lock_logs_no_unlock
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 잠기지_않은_계정의_재설정은_해제_기록을_남기지_않는다(): void
    {
        $user = User::factory()->create([
            'email' => 'reset-plain@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'failed_login_attempts' => 1,
            'last_failed_login_at' => now(),
        ]);

        $this->resetPassword($user->email, 'NewPassw0rd!')->assertStatus(200);

        $this->assertSame(0, (int) $user->fresh()->failed_login_attempts, '재설정 후에도 이전 실패 횟수가 남았다');
        $this->assertDatabaseMissing('activity_logs', ['action' => 'auth.account_unlocked']);
    }

    /**
     * 잠김 안내 문구가 비밀번호 재설정으로 풀 수 있음을 알려준다(시한·무기한 모두).
     *
     * @scenario lock_state=active, credential=valid
     *
     * @effects locked_message_mentions_password_reset
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 잠김_안내는_비밀번호_재설정으로_풀_수_있음을_알린다(): void
    {
        $timed = $this->lockedUser('msg-timed@example.com', permanent: false);
        $message = $this->withHeader('Accept-Language', 'ko')
            ->postJson('/api/auth/login', ['email' => $timed->email, 'password' => 'correct-password'])
            ->assertStatus(423)
            ->json('message');
        $this->assertStringContainsString('비밀번호를 재설정', (string) $message);

        $permanent = $this->lockedUser('msg-perm@example.com', permanent: true);
        $message = $this->withHeader('Accept-Language', 'ko')
            ->postJson('/api/auth/login', ['email' => $permanent->email, 'password' => 'correct-password'])
            ->assertStatus(423)
            ->json('message');
        $this->assertStringContainsString('비밀번호를 재설정', (string) $message);
    }

    /**
     * 큐 연결이 database(기본 설치값)여도 실패 횟수와 잠금이 그 요청 안에서 바로 반영된다.
     *
     * 실패·성공 리스너가 큐로 미뤄지면 워커가 없거나 밀린 사이트에서 실패가 하나도 세지지 않아
     * 잠금이 걸리지 않았다(실측: 오답 5회 뒤 정답 200, 실패 처리 작업 5건이 큐에 잔류).
     *
     * @scenario login_attempt_enabled=true, user_existence=exists, credential=invalid
     *
     * @effects lock_counting_runs_synchronously_regardless_of_queue_driver
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 큐_연결이_database_여도_실패가_바로_세져_잠긴다(): void
    {
        config(['queue.default' => 'database']);

        $user = User::factory()->create([
            'email' => 'queued@example.com',
            'password' => Hash::make('correct-password'),
            'status' => 'active',
        ]);

        for ($i = 1; $i <= 3; $i++) {
            $this->postJson('/api/auth/login', ['email' => 'queued@example.com', 'password' => 'wrong-password'])
                ->assertStatus(401);
        }

        $this->assertNotNull($user->fresh()->locked_until, '큐 연결이 database 이면 잠금이 걸리지 않는다 — 실패 리스너가 큐로 미뤄졌다');

        $this->postJson('/api/auth/login', ['email' => 'queued@example.com', 'password' => 'correct-password'])
            ->assertStatus(423);
    }

    /**
     * 잠긴 사용자를 만듭니다.
     *
     * @param  string  $email  이메일
     * @param  bool  $permanent  무기한 잠금 여부
     * @return User 잠긴 사용자
     */
    private function lockedUser(string $email, bool $permanent): User
    {
        return User::factory()->create([
            'email' => $email,
            'password' => Hash::make('correct-password'),
            'status' => 'active',
            'locked_until' => $permanent ? null : now()->addMinutes(5),
            'locked_permanently' => $permanent,
            'failed_login_attempts' => 0,
            'last_failed_login_at' => now(),
        ]);
    }

    /**
     * 재설정 토큰을 심고 비밀번호 재설정 API 를 호출합니다.
     *
     * @param  string  $email  이메일
     * @param  string  $password  새 비밀번호
     * @return TestResponse 재설정 응답
     */
    private function resetPassword(string $email, string $password): TestResponse
    {
        $plain = Str::random(64);
        PasswordResetToken::updateOrCreate(
            ['email' => $email],
            ['token' => Hash::make($plain), 'created_at' => now()],
        );

        return $this->postJson('/api/auth/reset-password', [
            'email' => $email,
            'token' => $plain,
            'password' => $password,
            'password_confirmation' => $password,
        ]);
    }

    /**
     * 보안 환경설정 값을 테스트용으로 덮어씁니다.
     *
     * @param  array<string, mixed>  $values  덮어쓸 security 설정
     * @return void 반환값 없음
     */
    private function setSecuritySettings(array $values): void
    {
        $existing = (array) config('g7_settings.core.security', []);
        config(['g7_settings.core.security' => array_merge($existing, $values)]);
    }
}
