<?php

namespace Tests\Feature\Auth;

use App\Extension\Auth\LoginDiscoveryResult;
use App\Extension\HookManager;
use App\Services\FrontendConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 로그인 진입 판정과 자동 이동 (#798 K5 · W19 · W20)
 *
 * 이메일 먼저 입력 화면은 `POST /api/auth/login/discover`(관리자 `…/admin/login/discover`)로 수단을 묻는다. 응답은 항상
 * `{method, label, redirect_url}` 이고 회원 유무와 무관하다. 외부 주소는 비밀번호로 낮춘다. 자동 이동 경로는 프론트 설정
 * `appConfig.auth.auto_redirect` 로 노출한다.
 */
class LoginDiscoveryTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 시도 제한을 초기화합니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::flush();
    }

    /**
     * 도메인으로 외부 수단을 답하는 필터를 둡니다.
     *
     * @param  string  $redirect  돌려줄 이동 경로
     * @return void 반환값 없음
     */
    private function discoverCorp(string $redirect = '/plugins/test/login/corp'): void
    {
        HookManager::addFilter('core.auth.login_discovery', function ($result, string $identifier, string $guard) use ($redirect) {
            return str_ends_with(strtolower($identifier), '@corp798.test')
                ? LoginDiscoveryResult::external(['ko' => '회사 계정', 'en' => 'Company'], $redirect.'?guard='.$guard)
                : $result;
        });
    }

    /**
     * 확장이 없으면 비밀번호 로그인이다.
     *
     * @scenario extension=none
     *
     * @effects discovery_defaults_to_password
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 확장이_없으면_비밀번호(): void
    {
        $this->postJson('/api/auth/login/discover', ['identifier' => 'a@example.test'])
            ->assertOk()
            ->assertExactJson(['success' => true, 'message' => __('auth.login_discovery_success'), 'data' => ['method' => 'password', 'label' => null, 'redirect_url' => null]]);
    }

    /**
     * 확장이 답하면 외부 수단과 이동 경로를 돌려준다 (관리자 경로는 guard 가 admin).
     *
     * @scenario extension=external, guard=user|admin
     *
     * @effects discovery_returns_external
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 확장_응답을_돌려준다(): void
    {
        $this->discoverCorp();

        $this->postJson('/api/auth/login/discover', ['identifier' => 'hong@corp798.test'])
            ->assertOk()
            ->assertJsonPath('data.method', 'external')
            ->assertJsonPath('data.label.ko', '회사 계정')
            ->assertJsonPath('data.redirect_url', '/plugins/test/login/corp?guard=user');

        $this->postJson('/api/auth/admin/login/discover', ['identifier' => 'hong@corp798.test'])
            ->assertOk()
            ->assertJsonPath('data.redirect_url', '/plugins/test/login/corp?guard=admin');
    }

    /**
     * 외부 주소·프로토콜 상대 주소는 비밀번호로 낮춘다.
     *
     * @scenario redirect=external|protocol_relative
     *
     * @effects external_redirect_normalized
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 외부_주소는_비밀번호로_낮춘다(): void
    {
        $this->discoverCorp('https://evil.example/steal');
        $this->postJson('/api/auth/login/discover', ['identifier' => 'hong@corp798.test'])->assertJsonPath('data.method', 'password');

        HookManager::resetAll();
        $this->discoverCorp('//evil.example/steal');
        $this->postJson('/api/auth/login/discover', ['identifier' => 'hong@corp798.test'])
            ->assertJsonPath('data.method', 'password')
            ->assertJsonPath('data.redirect_url', null);
    }

    /**
     * 회원이 있든 없든 응답 모양이 같다.
     *
     * @scenario user=present|absent
     *
     * @effects discovery_shape_independent_of_user
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 회원_유무와_응답이_같다(): void
    {
        $this->discoverCorp();
        $member = $this->makeMember(['email' => 'exists.'.Str::lower(Str::random(6)).'@corp798.test']);

        $present = $this->postJson('/api/auth/login/discover', ['identifier' => $member->email])->json();
        $absent = $this->postJson('/api/auth/login/discover', ['identifier' => 'nobody.'.Str::random(6).'@corp798.test'])->json();

        $this->assertSame($present, $absent);

        $this->postJson('/api/auth/login/discover', [])->assertStatus(422);
    }

    /**
     * 자동 이동과 로그인 식별자 형식이 프론트 설정에 노출된다.
     *
     * @scenario auto_redirect=set|unset
     *
     * @effects auto_redirect_exposed_in_app_config, login_identifier_exposed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 자동_이동이_프론트_설정에_노출된다(): void
    {
        $bundle = app(FrontendConfigService::class)->settingsBundle(FrontendConfigService::SCOPE_USER);
        $this->assertArrayHasKey('user', $bundle['appConfig']['auth']['auto_redirect']);
        $this->assertNull($bundle['appConfig']['auth']['auto_redirect']['user']);
        $this->assertSame('email', $bundle['appConfig']['auth']['login_identifier']['user']);

        HookManager::addFilter('core.auth.login_auto_redirect', fn ($path, string $guard) => $guard === 'admin' ? 'https://evil.example/' : '/plugins/test/login/auto');

        $bundle = app(FrontendConfigService::class)->settingsBundle(FrontendConfigService::SCOPE_USER);
        $this->assertSame('/plugins/test/login/auto', $bundle['appConfig']['auth']['auto_redirect']['user']);
        $this->assertNull($bundle['appConfig']['auth']['auto_redirect']['admin'], '외부 주소는 버린다');
    }
}
