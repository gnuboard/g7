<?php

namespace Tests\Feature\Docs;

use App\Models\User;
use App\Services\Auth\LoginHandoffService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\Concerns\LoginHandoffBinding;
use Tests\Support\Docs\HeaderAuthExample;
use Tests\TestCase;

/**
 * 공개 문서의 헤더 인증 예제가 실제로 동작하는지 돌린다 (docs/backend/authentication.md 「예제: 헤더 인증 확장」)
 *
 * 예제 확장(`Tests\Support\Docs\HeaderAuthExample`)을 등록하고 프록시 헤더 → 회원 생성 → 인계 코드 302 → 로그인 화면의 교환 →
 * 토큰까지 한 바퀴 돈다. 회사 도메인의 비밀번호 로그인이 막히는지도 본다.
 */
class HeaderAuthExampleTest extends TestCase
{
    use DatabaseTransactions;
    use LoginHandoffBinding;

    /**
     * 예제 확장을 등록하고 이 시험의 요청 주소(127.0.0.1)를 신뢰 프록시로 둡니다(운영의 `TRUSTED_PROXIES` 와 같은 설정값).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        HeaderAuthExample::register();
        $this->app['router']->getRoutes()->refreshNameLookups();
        config(['trustedproxy.proxies' => '127.0.0.1']);
    }

    /**
     * 요청 객체에 남은 신뢰 프록시 목록을 비웁니다(다음 시험으로 새지 않게).
     *
     * @return void 반환값 없음
     */
    protected function tearDown(): void
    {
        Request::setTrustedProxies([], Request::HEADER_X_FORWARDED_FOR);

        parent::tearDown();
    }

    /**
     * 처음 보는 사내 이메일이면 회원을 만들고 인계 코드로 로그인 화면에 보내며, 그 브라우저가 교환하면 토큰을 받는다.
     *
     * @scenario member=new, proxy=trusted
     *
     * @effects header_example_creates_member_and_hands_off, header_example_handoff_exchanges_to_token
     *
     * @return void 반환값 없음
     */
    public function test_header_login_round_trip_creates_member_and_logs_in(): void
    {
        $email = 'hdr.'.Str::lower(Str::random(8)).HeaderAuthExample::COMPANY_DOMAIN;

        $response = $this->withHeaders(['X-Remote-User' => strtoupper($email)])->get(HeaderAuthExample::ENTRY_PATH);

        $response->assertRedirect();
        $location = (string) $response->headers->get('Location');
        $this->assertStringContainsString('/login?login_handoff=', $location);
        parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

        $user = User::query()->where('email', $email)->firstOrFail();
        $this->assertFalse($user->hasPassword(), '회사가 만드는 계정은 비밀번호가 없다');

        $bind = $response->getCookie(LoginHandoffService::BIND_COOKIE, false);
        $this->assertNotNull($bind, '웹 응답은 인계 코드를 이 브라우저에 묶는 쿠키를 굽는다');

        $this->exchangeHandoff((string) $query['login_handoff'], $bind->getValue())
            ->assertOk()
            ->assertJsonPath('data.user.uuid', $user->uuid);
    }

    /**
     * 이미 있는 회원이면 새로 만들지 않고 그 회원으로 인계한다.
     *
     * @scenario member=existing, proxy=trusted
     *
     * @effects header_example_reuses_existing_member
     *
     * @return void 반환값 없음
     */
    public function test_existing_member_is_reused(): void
    {
        $user = User::factory()->create(['email' => 'hdr.'.Str::lower(Str::random(8)).HeaderAuthExample::COMPANY_DOMAIN]);
        $before = User::query()->count();

        $response = $this->withHeaders(['X-Remote-User' => $user->email])->get(HeaderAuthExample::ENTRY_PATH);
        parse_str((string) parse_url((string) $response->headers->get('Location'), PHP_URL_QUERY), $query);

        $this->assertSame($before, User::query()->count());
        $this->exchangeHandoff((string) $query['login_handoff'], $response->getCookie(LoginHandoffService::BIND_COOKIE, false)?->getValue())
            ->assertOk()
            ->assertJsonPath('data.user.uuid', $user->uuid);
    }

    /**
     * 신뢰 프록시를 거치지 않은 요청의 헤더는 믿지 않는다.
     *
     * @scenario member=new, proxy=untrusted
     *
     * @effects header_example_rejects_untrusted_proxy
     *
     * @return void 반환값 없음
     */
    public function test_untrusted_request_is_refused(): void
    {
        config(['trustedproxy.proxies' => null]);
        $email = 'hdr.'.Str::lower(Str::random(8)).HeaderAuthExample::COMPANY_DOMAIN;

        $this->withHeaders(['X-Remote-User' => $email])->get(HeaderAuthExample::ENTRY_PATH)->assertForbidden();
        $this->assertFalse(User::query()->where('email', $email)->exists());
    }

    /**
     * 회사 도메인의 비밀번호 로그인은 막히고, 응답이 헤더 인증 진입 경로를 알려 준다.
     *
     * @scenario member=existing, purpose=login
     *
     * @effects header_example_blocks_company_password_login
     *
     * @return void 반환값 없음
     */
    public function test_company_domain_password_login_is_blocked(): void
    {
        $user = User::factory()->create(['email' => 'hdr.'.Str::lower(Str::random(8)).HeaderAuthExample::COMPANY_DOMAIN]);

        $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password'])
            ->assertForbidden()
            ->assertJsonPath('errors.code', 'auth.password_auth_disabled')
            ->assertJsonPath('errors.redirect_url', HeaderAuthExample::ENTRY_PATH);
    }
}
