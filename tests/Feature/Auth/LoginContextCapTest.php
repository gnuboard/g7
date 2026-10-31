<?php

namespace Tests\Feature\Auth;

use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\Auth\TokenExpirySlider;
use App\Services\AuthService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 로그인 문맥 보존과 토큰 절대 상한 (#798 K7)
 *
 * 외부 인증이 로그인 문맥 `max_session_minutes` 로 세션 수명 상한을 주면 토큰 만료가 그 시각을 넘지 않는다 — 사용 중 연장(슬라이드)과
 * 갱신(refresh)도 넘지 못한다. 문맥(`max_session_minutes`, `external_ref`)은 2단계 인증을 거쳐도 유지되고, 로그인 완료 훅에
 * `token_id`·`external_ref` 가 실리며, 토큰에 로그인 수단이 기록된다.
 */
class LoginContextCapTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /** 로그인 완료 훅이 받은 문맥 */
    private array $afterLogin = [];

    /**
     * 메일 발송을 막고 토큰 수명을 30분으로 고정하며 로그인 완료 훅을 기록합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        $this->prepareIdentityNotifications();
        $this->setSecurity(['auth_token_lifetime' => 30]);

        $this->afterLogin = [];
        HookManager::addAction('core.auth.after_login', function (User $user, array $context) {
            $this->afterLogin[] = $context;
        }, 99);
    }

    /**
     * 서비스를 반환합니다.
     *
     * @return AuthService 인증 서비스
     */
    private function service(): AuthService
    {
        return app(AuthService::class);
    }

    /**
     * 회원을 만듭니다.
     *
     * @return User 회원
     */
    private function member(): User
    {
        return $this->makeMember(['email' => 'cap.'.Str::lower(Str::random(8)).'@example.test']);
    }

    /**
     * 평문 토큰의 토큰 행을 찾습니다.
     *
     * @param  string  $plain  평문 토큰
     * @return PersonalAccessToken 토큰 행
     */
    private function tokenRow(string $plain): PersonalAccessToken
    {
        return PersonalAccessToken::findToken($plain);
    }

    /**
     * 상한이 없으면 지금처럼 수명만큼이고 절대 만료는 비어 있다 (비밀번호 로그인 회귀).
     *
     * @scenario cap=none
     *
     * @effects no_cap_keeps_lifetime, password_login_records_method
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 상한이_없으면_지금과_같다(): void
    {
        Carbon::setTestNow('2026-10-22 10:00:00');
        $user = $this->member();

        $row = $this->tokenRow($this->service()->login($user->email, 'Passw0rd!old')['token']);

        $this->assertSame('2026-10-22 10:30:00', $row->expires_at->format('Y-m-d H:i:s'));
        $this->assertNull($row->absolute_expires_at);
        $this->assertSame('password', $row->login_method);
        Carbon::setTestNow();
    }

    /**
     * 상한이 수명보다 짧으면 만료와 절대 만료가 상한이고, 슬라이드가 상한을 넘지 못한다.
     *
     * @scenario cap=shorter_than_lifetime
     *
     * @effects cap_limits_expiry, slide_cannot_exceed_cap, external_login_records_method
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 상한이_만료를_자르고_슬라이드가_넘지_못한다(): void
    {
        Carbon::setTestNow('2026-10-22 10:00:00');
        $user = $this->member();

        $result = $this->service()->loginVerifiedUser($user, ['login_method' => 'external:test', 'max_session_minutes' => 10]);
        $row = $this->tokenRow($result['token']);

        $this->assertSame('2026-10-22 10:10:00', $row->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-22 10:10:00', Carbon::parse($row->absolute_expires_at)->format('Y-m-d H:i:s'));
        $this->assertSame('external:test', $row->login_method);

        Carbon::setTestNow('2026-10-22 10:06:00');
        app(TokenExpirySlider::class)->slide($row);
        $this->assertSame('2026-10-22 10:10:00', $row->fresh()->expires_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    /**
     * 수명이 무기한(0)이어도 상한이 있으면 상한에 만료한다.
     *
     * @scenario cap=with_infinite_lifetime
     *
     * @effects cap_applies_to_infinite_lifetime
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 무기한_수명에도_상한이_걸린다(): void
    {
        Carbon::setTestNow('2026-10-22 10:00:00');
        $this->setSecurity(['auth_token_lifetime' => 0]);

        $row = $this->tokenRow($this->service()->loginVerifiedUser($this->member(), ['login_method' => 'external:test', 'max_session_minutes' => 480])['token']);

        $this->assertSame('2026-10-22 18:00:00', $row->expires_at->format('Y-m-d H:i:s'));
        Carbon::setTestNow();
    }

    /**
     * 갱신한 토큰은 절대 만료를 물려받고, 절대 만료가 지난 토큰은 갱신되지 않는다.
     *
     * @scenario refresh=inherits|after_cap
     *
     * @effects refresh_inherits_cap, refresh_after_cap_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 갱신은_상한을_물려받고_지난_상한은_거절한다(): void
    {
        Carbon::setTestNow('2026-10-22 10:00:00');
        $user = $this->member();
        $row = $this->tokenRow($this->service()->loginVerifiedUser($user, ['login_method' => 'external:test', 'max_session_minutes' => 10])['token']);

        Carbon::setTestNow('2026-10-22 10:05:00');
        $user->withAccessToken($row);
        $refreshed = $this->tokenRow($this->service()->refreshToken($user)['token']);
        $this->assertSame('2026-10-22 10:10:00', $refreshed->expires_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-22 10:10:00', Carbon::parse($refreshed->absolute_expires_at)->format('Y-m-d H:i:s'));
        $this->assertSame('external:test', $refreshed->login_method);

        Carbon::setTestNow('2026-10-22 10:11:00');
        $user->withAccessToken($refreshed);

        try {
            $this->service()->refreshToken($user);
            $this->fail('절대 만료가 지난 토큰은 갱신되지 않아야 한다');
        } catch (AuthenticationException) {
            $this->assertSame(0, $user->tokens()->count(), '지난 토큰은 지운다');
        } finally {
            Carbon::setTestNow();
        }
    }

    /**
     * 2단계 인증을 거쳐도 상한과 외부 참조가 유지되고 로그인 완료 훅에 토큰 id 와 외부 참조가 실린다.
     *
     * @scenario two_factor=on, context=max_session_minutes+external_ref
     *
     * @effects context_survives_two_factor, after_login_carries_token_id_and_external_ref
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 이단계_인증_뒤에도_문맥이_유지된다(): void
    {
        Carbon::setTestNow('2026-10-22 10:00:00');
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $user = $this->member();

        $challenge = $this->service()->loginVerifiedUser($user, [
            'login_method' => 'external:test',
            'max_session_minutes' => 10,
            'external_ref' => 'sess-abc',
        ]);
        $this->assertTrue($challenge['two_factor_required']);

        $log = IdentityVerificationLog::find($challenge['challenge_id']);
        $log->metadata = array_merge($log->metadata ?? [], ['code_hash' => Hash::make('135790')]);
        $log->save();

        $result = $this->service()->completeTwoFactor($challenge['challenge_id'], ['code' => '135790']);
        $row = $this->tokenRow($result['token']);

        $this->assertSame('2026-10-22 10:10:00', Carbon::parse($row->absolute_expires_at)->format('Y-m-d H:i:s'));
        $this->assertSame('external:test', $row->login_method);

        $last = end($this->afterLogin);
        $this->assertSame('external:test', $last['login_method']);
        $this->assertSame('sess-abc', $last['external_ref']);
        $this->assertSame($row->id, $last['token_id']);
        $this->assertArrayHasKey('email', $last, '기존 키는 그대로');
        Carbon::setTestNow();
    }

    /**
     * 범위를 벗어난 상한·외부 참조는 버린다.
     *
     * @scenario context=out_of_range
     *
     * @effects invalid_cap_ignored
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 범위_밖_값은_버린다(): void
    {
        $row = $this->tokenRow($this->service()->loginVerifiedUser($this->member(), [
            'login_method' => 'external:test',
            'max_session_minutes' => 0,
            'external_ref' => str_repeat('r', 101),
        ])['token']);

        $this->assertNull($row->absolute_expires_at);
        $last = end($this->afterLogin);
        $this->assertNull($last['external_ref']);
    }

    /**
     * 긴 수단 이름(예: `sso:` + 50자 공급자 키 = 54자)도 토큰과 로그인 완료 훅에 같은 값으로 남는다 — 토큰 칼럼이 짧아 잘리면
     * 그 수단의 토큰만 회수하는 정리가 이 토큰을 놓친다. 64자를 넘는 이름은 둘 다 64자로 같이 잘린다.
     *
     * @scenario context=long_login_method
     *
     * @effects long_login_method_kept_same_on_token_and_hook
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 긴_수단_이름도_토큰과_훅에_같게_남는다(): void
    {
        $method = 'sso:'.str_repeat('k', 50);
        $row = $this->tokenRow($this->service()->loginVerifiedUser($this->member(), ['login_method' => $method])['token']);
        $last = end($this->afterLogin);
        $this->assertSame(54, mb_strlen($method));
        $this->assertSame($method, $row->login_method);
        $this->assertSame($method, $last['login_method']);

        $tooLong = 'sso:'.str_repeat('x', 80);
        $row = $this->tokenRow($this->service()->loginVerifiedUser($this->member(), ['login_method' => $tooLong])['token']);
        $last = end($this->afterLogin);
        $this->assertSame(mb_substr($tooLong, 0, 64), $row->login_method);
        $this->assertSame($row->login_method, $last['login_method']);
    }
}
