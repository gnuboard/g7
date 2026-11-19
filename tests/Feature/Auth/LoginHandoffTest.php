<?php

namespace Tests\Feature\Auth;

use App\Extension\HookManager;
use App\Models\IdentityVerificationLog;
use App\Models\User;
use App\Services\Auth\LoginHandoffService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\LoginHandoffBinding;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 로그인 인계 `POST /api/auth/login/handoff` (#40 K2 · R4)
 *
 * 외부 인증이 발급한 일회용 코드를 로그인 화면이 교환하면 비밀번호 로그인(`POST /api/auth/login`)과 같은 모양의 응답
 * (인증 완료 · 2단계 인증 · 비밀번호 변경)이 온다. 코드는 한 번·120초만 통하고, 관리자 경로 인계와 사라진 회원은 거절한다.
 */
class LoginHandoffTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;
    use LoginHandoffBinding;
    use PasswordPolicyFixtures;

    /** 발급한 코드 → 묶음 쿠키 값 */
    private array $binds = [];

    /**
     * 메일 발송을 막고 로그인 시도 제한을 초기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Cache::flush();
        $this->prepareIdentityNotifications();
    }

    /**
     * 회원을 만듭니다.
     *
     * @param  array<string, mixed>  $attributes  덮어쓸 속성
     * @return User 회원
     */
    private function member(array $attributes = []): User
    {
        return $this->makeMember(array_merge(['email' => 'handoff.'.Str::lower(Str::random(8)).'@example.test'], $attributes));
    }

    /**
     * 인계 코드를 발급합니다.
     *
     * @param  User  $user  회원
     * @param  string  $guard  로그인 경로
     * @return string 코드
     */
    private function issue(User $user, string $guard = 'user'): string
    {
        $ticket = $this->issueBoundHandoff($user, ['login_method' => 'social:kakao'], $guard);
        $this->binds[$ticket['code']] = $ticket['bind'];

        return $ticket['code'];
    }

    /**
     * 코드를 발급한 브라우저의 묶음 쿠키로 교환합니다 (#798 K2 — 코드는 발급 브라우저에 묶인다).
     *
     * @param  string  $code  인계 코드
     * @param  array<string, string>  $headers  요청 헤더
     * @return TestResponse 응답
     */
    private function exchange(string $code, array $headers = []): TestResponse
    {
        return $this->exchangeHandoff($code, $this->binds[$code] ?? null, 'user', $headers);
    }

    /**
     * 정상 회원은 인계 교환으로 토큰을 받고, 응답 키가 비밀번호 로그인과 같다.
     *
     * @scenario response=authenticated
     *
     * @effects handoff_issues_token, handoff_response_matches_login_shape
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 교환하면_비밀번호_로그인과_같은_모양으로_로그인된다(): void
    {
        $user = $this->member();

        $viaHandoff = $this->exchange($this->issue($user))->assertOk();
        $this->assertNotEmpty($viaHandoff->json('data.token'));
        $this->assertSame($user->uuid, $viaHandoff->json('data.user.uuid'));

        $this->app['auth']->forgetGuards();
        $viaPassword = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'Passw0rd!old'])->assertOk();

        $this->assertSame(
            array_keys($viaPassword->json('data')),
            array_keys($viaHandoff->json('data')),
            '로그인 화면은 두 응답을 같은 분기로 처리한다 — 키가 다르면 한쪽 분기가 깨진다',
        );
        $this->assertSame(array_keys($viaPassword->json()), array_keys($viaHandoff->json()));
    }

    /**
     * 2단계 인증·비밀번호 변경이 필요하면 토큰 대신 같은 단계 응답을 받는다.
     *
     * @scenario response=two_factor_required|password_change_required
     *
     * @effects handoff_two_factor_step, handoff_password_change_step
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 단계가_필요하면_같은_단계_응답을_받는다(): void
    {
        $this->setSecurity(['two_factor_auth' => true, 'two_factor_scope' => 'all']);
        $tfa = $this->exchange($this->issue($this->member()))->assertOk();
        $this->assertTrue((bool) $tfa->json('data.two_factor_required'));
        $this->assertNull($tfa->json('data.token'));
        $this->assertNotEmpty($tfa->json('data.challenge_id'));

        // 2단계 인증 코드를 마치면 로그인된다 — 인계로 시작한 로그인도 같은 완료 경로를 쓴다.
        $log = IdentityVerificationLog::find($tfa->json('data.challenge_id'));
        $log->metadata = array_merge($log->metadata ?? [], ['code_hash' => Hash::make('135790')]);
        $log->save();
        $this->postJson('/api/auth/login/two-factor', ['challenge_id' => $tfa->json('data.challenge_id'), 'code' => '135790'])
            ->assertOk()
            ->assertJsonPath('data.token_type', 'Bearer');

        $this->setSecurity(['two_factor_auth' => false]);
        $this->enableExpiry();
        $pc = $this->exchange($this->issue($this->member(['password_changed_at' => now()->subDays(100)])))->assertOk();
        $this->assertTrue((bool) $pc->json('data.password_change_required'));
        $this->assertNull($pc->json('data.token'));
    }

    /**
     * 같은 코드는 두 번 통하지 않는다.
     *
     * @scenario code=reused
     *
     * @effects handoff_code_single_use
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 코드는_한_번만_통한다(): void
    {
        $code = $this->issue($this->member());

        $this->exchange($code)->assertOk();
        $this->app['auth']->forgetGuards();
        $this->exchange($code)->assertStatus(422)->assertJsonPath('errors.code.0', __('auth.login_handoff_invalid'));
    }

    /**
     * 120초가 지난 코드·위조 코드·빈 코드는 거절한다.
     *
     * @scenario code=expired|forged|empty
     *
     * @effects handoff_code_expires, handoff_forged_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 만료·위조·빈_코드는_거절한다(): void
    {
        $code = $this->issue($this->member());
        $this->travel(LoginHandoffService::TTL_SECONDS + 1)->seconds();
        $this->exchange($code)->assertStatus(422);
        $this->travelBack();

        $this->exchange(str_repeat('A', 43))->assertStatus(422);
        $this->exchange('')->assertStatus(422)->assertJsonValidationErrors(['code']);
    }

    /**
     * 관리자 경로로 발급된 코드와 사라진 회원의 코드는 같은 422 로 거절한다.
     *
     * @scenario guard=admin, user=missing
     *
     * @effects admin_guard_handoff_rejected, missing_user_handoff_rejected
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 관리자_경로와_사라진_회원은_거절한다(): void
    {
        $this->exchange($this->issue($this->member(), 'admin'))->assertStatus(422);

        $ghost = (new User)->forceFill(['id' => 2_000_000_000]);
        $this->exchange($this->issue($ghost))->assertStatus(422);
    }

    /**
     * 차단 회원은 비밀번호 로그인과 같은 401 이고 상태 문구가 `errors.email` 에 실린다.
     *
     * @scenario status=blocked
     *
     * @effects handoff_blocked_status_message
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 차단_회원은_상태_문구로_거절된다(): void
    {
        $this->exchange($this->issue($this->member(['status' => 'blocked'])))
            ->assertStatus(401)
            ->assertJsonPath('errors.email.0', __('auth.account_blocked'));
    }

    /**
     * 교환 요청의 장바구니 키 헤더가 로그인 완료 훅까지 그대로 보인다 (장바구니 병합 경로).
     *
     * @scenario header=x_cart_key
     *
     * @effects cart_key_header_reaches_after_login
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 장바구니_키_헤더가_로그인_완료_훅에_보인다(): void
    {
        $seen = [];
        HookManager::addAction('core.auth.after_login', function () use (&$seen) {
            $seen[] = request()->header('X-Cart-Key');
        }, 99);

        $this->exchange($this->issue($this->member()), ['X-Cart-Key' => 'ck_handoff_test'])->assertOk();

        $this->assertSame(['ck_handoff_test'], $seen);
    }
}
