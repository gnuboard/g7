<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\Auth\LoginHandoffService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\LoginHandoffBinding;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\Support\Concerns\CapturesSentMail;
use Tests\TestCase;

/**
 * 로그인 인계 코드의 브라우저 묶기
 *
 * 인계 코드는 발급한 브라우저의 묶음 쿠키(`g7_login_bind`)가 있어야만 교환된다 — 공격자가 자기 신원으로 받은 코드를 피해자
 * 브라우저에 넣어 로그인시키는 로그인 CSRF 를 막는다. 쿠키가 다르거나 없으면 코드를 소비하고 같은 422 로 답한다.
 */
class LoginHandoffBindingTest extends TestCase
{
    use CapturesSentMail;
    use DatabaseTransactions;
    use LoginHandoffBinding;
    use PasswordPolicyFixtures;

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
     * @param  bool  $admin  관리자로 만들지
     * @return User 회원
     */
    private function person(bool $admin = false): User
    {
        $email = 'bind.'.Str::lower(Str::random(8)).'@example.test';

        return $admin ? $this->makeAdmin(['email' => $email]) : $this->makeMember(['email' => $email]);
    }

    /**
     * 발급한 브라우저의 쿠키로는 교환된다.
     *
     * @scenario cookie=same, guard=user|admin
     *
     * @effects bound_code_exchanges_with_same_cookie
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 같은_쿠키면_교환된다(): void
    {
        $ticket = $this->issueBoundHandoff($this->person());
        $this->assertNotNull($ticket['bind'], 'issue() 는 묶음 쿠키를 대기열에 넣어야 한다');
        $this->exchangeHandoff($ticket['code'], $ticket['bind'])->assertOk();

        $admin = $this->issueBoundHandoff($this->person(true), ['login_method' => 'social:test'], 'admin');
        $this->exchangeHandoff($admin['code'], $admin['bind'], 'admin')->assertOk();
    }

    /**
     * 다른 쿠키로 교환하면 422 이고 코드가 소멸해 원래 브라우저로도 다시 쓸 수 없다.
     *
     * @scenario cookie=different
     *
     * @effects bound_code_rejected_with_other_cookie, bound_code_consumed_on_mismatch
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 다른_쿠키면_422_이고_코드가_소멸한다(): void
    {
        $ticket = $this->issueBoundHandoff($this->person());

        $this->exchangeHandoff($ticket['code'], str_repeat('x', 43))
            ->assertStatus(422)
            ->assertJsonPath('errors.code.0', __('auth.login_handoff_invalid'));

        $this->exchangeHandoff($ticket['code'], $ticket['bind'])->assertStatus(422);
    }

    /**
     * 쿠키 없이 교환하면 422 다 (관리자 경로도 같다).
     *
     * @scenario cookie=missing, guard=user|admin
     *
     * @effects bound_code_rejected_without_cookie
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 쿠키가_없으면_422(): void
    {
        $ticket = $this->issueBoundHandoff($this->person());
        $this->exchangeHandoff($ticket['code'], null)->assertStatus(422);

        $admin = $this->issueBoundHandoff($this->person(true), ['login_method' => 'social:test'], 'admin');
        $this->exchangeHandoff($admin['code'], null, 'admin')->assertStatus(422);
    }

    /**
     * `issueUnbound()` 코드는 쿠키 없이 교환되고 쿠키를 대기열에 넣지 않는다.
     *
     * @scenario binding=none
     *
     * @effects unbound_code_exchanges_without_cookie
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 무묶음_코드는_쿠키_없이_교환된다(): void
    {
        $code = app(LoginHandoffService::class)->issueUnbound($this->person(), ['login_method' => 'social:test']);

        $this->assertNull(\Illuminate\Support\Facades\Cookie::queued(LoginHandoffService::BIND_COOKIE));
        $this->exchangeHandoff($code, null)->assertOk();
    }

    /**
     * `issueTicket()` 은 대기열 대신 쿠키를 돌려주고, 그 쿠키 속성이 정해진 값이다.
     *
     * @scenario binding=ticket
     *
     * @effects ticket_cookie_attributes, ticket_exchanges_with_cookie
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 티켓_쿠키_속성과_교환(): void
    {
        $ticket = app(LoginHandoffService::class)->issueTicket($this->person(), ['login_method' => 'social:test'], 'user');

        $this->assertNull(\Illuminate\Support\Facades\Cookie::queued(LoginHandoffService::BIND_COOKIE));
        $this->assertSame(LoginHandoffService::BIND_COOKIE, $ticket->cookie->getName());
        $this->assertTrue($ticket->cookie->isHttpOnly());
        $this->assertSame('lax', strtolower((string) $ticket->cookie->getSameSite()));
        $this->assertSame('/api/auth', $ticket->cookie->getPath());
        $this->assertEqualsWithDelta(time() + LoginHandoffService::TTL_SECONDS, $ticket->cookie->getExpiresTime(), 5);
        $this->assertGreaterThanOrEqual(43, strlen((string) $ticket->cookie->getValue()));

        $this->exchangeHandoff($ticket->code, $ticket->cookie->getValue())->assertOk();
    }
}
