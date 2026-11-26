<?php

namespace Tests\Feature\Auth;

use App\Contracts\Repositories\ConfigRepositoryInterface;
use App\Extension\HookManager;
use App\Services\AuthService;
use App\Services\FrontendConfigService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\Test;
use Tests\Concerns\PasswordPolicyFixtures;
use Tests\TestCase;

/**
 * 「일반 회원가입 받지 않음」 `security.registration_enabled`
 *
 * 꺼지면 공개 가입 API 가 403 이다. 확장 내부 가입(서비스 직접 호출 — SSO 자동 생성·관리자 추가)은 계속된다. 확장 필터
 * `core.auth.self_registration_open` 이 닫을 수도 있다. 설정 일부 저장이 이 값을 바꾸지 않고, 프론트 설정에 노출된다.
 */
class RegistrationToggleTest extends TestCase
{
    use DatabaseTransactions;
    use PasswordPolicyFixtures;

    /**
     * 알림·큐를 막습니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        Notification::fake();
        Queue::fake();
    }

    /**
     * 공개 가입을 요청합니다.
     *
     * @return TestResponse 응답
     */
    private function publicRegister(): TestResponse
    {
        return $this->postJson('/api/auth/register', [
            'name' => '가입자',
            'email' => 'reg.'.Str::lower(Str::random(8)).'@example.test',
            'password' => 'Passw0rd!new9',
            'password_confirmation' => 'Passw0rd!new9',
            'agree_terms' => true,
            'agree_privacy' => true,
        ]);
    }

    /**
     * 기본은 열려 있다.
     *
     * @scenario setting=default
     *
     * @effects registration_open_by_default
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 기본은_열려_있다(): void
    {
        $this->assertTrue(app(AuthService::class)->isSelfRegistrationOpen());
        $this->publicRegister()->assertCreated();
    }

    /**
     * 끄면 공개 가입이 403 이고, 서비스 직접 가입은 계속된다.
     *
     * @scenario setting=false, surface=public_api|service
     *
     * @effects registration_closed_403, service_register_still_works
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 끄면_공개_가입만_막힌다(): void
    {
        $this->setSecurity(['registration_enabled' => false]);

        $this->publicRegister()
            ->assertForbidden()
            ->assertJsonPath('message', __('auth.registration_disabled'));

        $result = app(AuthService::class)->register([
            'name' => '회사 계정',
            'email' => 'jit.'.Str::lower(Str::random(8)).'@example.test',
        ], ['issue_token' => false, 'record_consents' => false]);
        $this->assertNotNull($result['user']->id);
    }

    /**
     * 확장 필터가 닫으면 설정이 켜져 있어도 닫힌다.
     *
     * @scenario filter=closes
     *
     * @effects filter_closes_registration
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 확장이_닫을_수_있다(): void
    {
        HookManager::addFilter('core.auth.self_registration_open', fn (bool $open) => false);

        $this->assertFalse(app(AuthService::class)->isSelfRegistrationOpen());
        $this->publicRegister()->assertForbidden();
    }

    /**
     * 화면은 실효값을 읽는다 — 확장 필터가 가입을 닫으면 프론트 설정의 `appConfig.auth.registration_open` 이 false 이고,
     * 원 설정값 `settings.security.registration_enabled` 는 저장값(true) 그대로다(관리자 스위치는 저장값을 보여야 한다).
     * 화면이 원 설정값만 보면 서버가 403 으로 막는데도 가입 폼·링크가 보인다(W37, M5 T13 실측).
     *
     * @scenario filter=closes, surface=frontend_config
     *
     * @effects effective_registration_flag_exposed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 화면에는_실효값이_따로_실린다(): void
    {
        $open = app(FrontendConfigService::class)->settingsBundle(FrontendConfigService::SCOPE_USER);
        $this->assertTrue($open['appConfig']['auth']['registration_open']);

        HookManager::addFilter('core.auth.self_registration_open', fn (bool $value) => false);

        $closed = app(FrontendConfigService::class)->settingsBundle(FrontendConfigService::SCOPE_USER);
        $this->assertFalse($closed['appConfig']['auth']['registration_open']);
        $this->assertTrue($closed['settings']['security']['registration_enabled'], '원 설정값은 저장값 그대로');
    }

    /**
     * 보안 탭의 다른 값만 저장해도 이 값은 바뀌지 않는다 (부분 저장 보호).
     *
     * @scenario save=partial
     *
     * @effects partial_save_keeps_registration_flag
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 부분_저장은_값을_바꾸지_않는다(): void
    {
        $admin = $this->makeAdmin(['email' => 'reg.admin.'.Str::lower(Str::random(8)).'@example.test', 'is_super' => true]);
        $token = $this->issueToken($admin);

        $this->saveSecurity($token, ['registration_enabled' => false])->assertOk();
        $this->assertFalse((bool) app(ConfigRepositoryInterface::class)->getCategory('security')['registration_enabled']);

        // 이 키를 싣지 않은 보안 탭 저장 — 값이 바뀌지 않는다
        $this->saveSecurity($token, ['max_login_attempts' => 6])->assertOk();
        $this->assertFalse((bool) app(ConfigRepositoryInterface::class)->getCategory('security')['registration_enabled']);
    }

    /**
     * 보안 탭을 저장합니다 (필수 키 + 덧붙일 값).
     *
     * @param  string  $token  최고관리자 토큰
     * @param  array<string, mixed>  $values  덧붙일 값
     * @return TestResponse 응답
     */
    private function saveSecurity(string $token, array $values): TestResponse
    {
        return $this->withToken($token)->postJson('/api/admin/settings', [
            '_tab' => 'security',
            'security' => array_merge(['force_https' => false, 'login_attempt_enabled' => true], $values),
        ]);
    }

    /**
     * 프론트 설정에 값이 노출된다.
     *
     * @scenario surface=frontend_config
     *
     * @effects registration_flag_exposed
     *
     * @return void 반환값 없음
     */
    #[Test]
    public function 프론트_설정에_노출된다(): void
    {
        $this->assertTrue(app(FrontendConfigService::class)->settingsBundle(FrontendConfigService::SCOPE_USER)['settings']['security']['registration_enabled']);

        $admin = $this->makeAdmin(['email' => 'reg.admin.'.Str::lower(Str::random(8)).'@example.test', 'is_super' => true]);
        $this->saveSecurity($this->issueToken($admin), ['registration_enabled' => false])->assertOk();

        $this->assertFalse(app(FrontendConfigService::class)->settingsBundle(FrontendConfigService::SCOPE_USER)['settings']['security']['registration_enabled']);
    }
}
