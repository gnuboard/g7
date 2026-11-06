<?php

namespace Tests\Feature\Api;

use App\Enums\ExtensionOwnerType;
use App\Exceptions\TemplateNotFoundException;
use App\Extension\HookManager;
use App\Http\View\Composers\TemplateComposer;
use App\Http\View\Composers\UserTemplateComposer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
use Illuminate\Testing\TestResponse;
use Illuminate\View\View;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 공개 설정 API 테스트 (#792 WP-C1 — D8)
 *
 * 셸(`window.G7Config`)과 같은 함수로 만든 값이 API 로 나가고, 민감 키가 없으며, 사용자 템플릿이 없어도 동작하고,
 * 관리자 API 는 비인증 401·일반 회원 403·관리자 200 인지 잠근다.
 */
class FrontendConfigApiTest extends TestCase
{
    use DatabaseTransactions;

    /**
     * 사용자 API 값이 사용자 셸에 실리는 값과 같습니다 (설정·모듈·플러그인·앱 설정·동의 첫 값·custom 주소).
     *
     * @scenario case=user_equals_shell
     *
     * @effects user_api_equals_shell
     *
     * @return void 반환값 없음
     */
    public function test_user_api_equals_shell_values(): void
    {
        $shell = $this->composeShell(UserTemplateComposer::class);
        $response = $this->getJson('/api/frontend-config')->assertOk();
        $api = $response->json('data');

        $this->assertSame('user', $api['scope']);
        $this->assertEquals($this->normalize($shell['frontendSettings']), $this->normalize($api['settings']));
        $this->assertEquals($this->normalize($shell['moduleSettings']), $this->normalize($api['modules']));
        $this->assertEquals($this->normalize($shell['pluginSettings']), $this->normalize($api['plugins']));
        $this->assertEquals($this->normalize($shell['appConfig']), $this->normalize($api['app_config']));
        $this->assertEquals($shell['consentInitial'], $api['consent_initial']);
        $this->assertEquals($shell['customRoutes'], $api['custom_routes']);
        $this->assertEquals($shell['websocketConfig'], $api['websocket']);
        $this->assertSameShapeAsShell($shell, $response);
    }

    /**
     * 관리자 API 값이 관리자 셸에 실리는 값과 같습니다.
     *
     * @scenario case=admin_equals_shell
     *
     * @effects admin_api_equals_shell
     *
     * @return void 반환값 없음
     */
    public function test_admin_api_equals_shell_values(): void
    {
        $shell = $this->composeShell(TemplateComposer::class);
        $response = $this->actingAs($this->admin())->getJson('/api/admin/frontend-config')->assertOk();
        $api = $response->json('data');

        $this->assertSame('admin', $api['scope']);
        $this->assertEquals($this->normalize($shell['frontendSettings']), $this->normalize($api['settings']));
        $this->assertEquals($this->normalize($shell['moduleSettings']), $this->normalize($api['modules']));
        $this->assertArrayNotHasKey('consent_initial', $api);
        $this->assertSameShapeAsShell($shell, $response);
    }

    /**
     * 셸 HTML 은 7.1 셸과 G7Config 의 `customRoutes` 키만 다르고 나머지는 바이트까지 같습니다 (계획 WP-C1·WP-K2 시험 잠금).
     *
     * 설정 조립을 공용 서비스로 옮긴 뒤에도 같은 값이 같은 자리에 같은 모양으로 나가는지 — 7.1 셸 템플릿(픽스처)을
     * 같은 뷰 데이터로 그린 결과와 비교한다. 셸을 일부러 바꾸면 픽스처도 그 변경과 함께 고친다.
     *
     * 픽스처는 「설정을 공용 서비스로 옮기기 전의 조립 방식(`locale`·`websocket` 을 셸이 직접 계산)」만 7.1 그대로 두고,
     * 그 밖의 의도된 셸 변경(확장 CSS 층 선언, 다크 방식·확장 CSS 덮기·확장 접두 값)은 셸과 같은 줄로 따라간다.
     * 셸과 픽스처의 차이는 늘 그 두 값의 조립과 `customRoutes` 두 줄뿐이어야 한다. 7.1.4 부터 셸 설정은 비실행 JSON 블록
     * (strict CSP)이라 픽스처도 그 셸을 따르고, 비교는 블록을 풀어 키 단위로 한다.
     *
     * @scenario case=shell_bytes
     *
     * @effects shell_output_unchanged_except_custom_routes
     *
     * @param  string  $view  셸 뷰
     * @param  string  $fixture  7.1 셸 템플릿 픽스처
     * @return void 반환값 없음
     */
    #[DataProvider('shellProvider')]
    public function test_shell_output_is_unchanged_except_custom_routes(string $view, string $fixture): void
    {
        config([
            'broadcasting.connections.reverb.key' => 'lock-key',
            'broadcasting.connections.reverb.options.host' => 'ws.example.test',
            'broadcasting.connections.reverb.options.port' => 443,
        ]);
        // 활성 템플릿이 있어야 G7Config 를 싣는 셸 본문이 그려진다(시험 DB 에는 설치 템플릿이 없다)
        $templates = Mockery::mock(TemplateService::class);
        $templates->shouldReceive('getActiveTemplateIdentifier')->andReturnUsing(fn (string $type) => $type === 'admin' ? 'sirsoft-admin_basic' : 'sirsoft-basic');
        $this->app->instance(TemplateService::class, $templates);

        $shell = view($view);
        $html = $shell->render();
        $legacy = Blade::render((string) file_get_contents(base_path('tests/Fixtures/Shell/'.$fixture)), $shell->getData());

        // 셸 설정은 비실행 JSON 데이터 블록(#g7-config, strict CSP)이라 customRoutes 는 그 안의 키다 —
        // 블록을 풀어 customRoutes 하나만 빼면 7.1 셸과 같은 값·같은 순서이고, 블록 밖 HTML 은 바이트까지 같다.
        $this->assertStringContainsString('"appKey":"lock-key"', $html);
        [$rest, $config] = $this->splitConfigBlock($html);
        [$legacyRest, $legacyConfig] = $this->splitConfigBlock($legacy);
        $this->assertArrayHasKey('customRoutes', $config);
        unset($config['customRoutes']);
        // 7.2.0 #434 — 활성 템플릿 공개 설정 키와 head 파비콘 링크가 더해졌다. 그 둘만 걷고 나머지는 7.1 과 같아야 한다.
        $this->assertArrayHasKey('templateSettings', $config);
        unset($config['templateSettings']);
        // 템플릿이 공급하는 설정 화면 번들 주소(#434) — 관리자 셸만 싣는다.
        if ($view === 'admin') {
            $this->assertArrayHasKey('templateSettingsAssets', $config);
            unset($config['templateSettingsAssets']);
        }
        $this->assertMatchesRegularExpression('#<link rel="icon"#', $rest);
        $rest = (string) preg_replace('#\s*<link rel="(?:icon|apple-touch-icon)"[^>]*>#', '', $rest);
        $this->assertSame($legacyConfig, $config);
        $this->assertSame($legacyRest, $rest);
    }

    /**
     * 셸 HTML 에서 G7Config 데이터 블록을 떼어 냅니다.
     *
     * @param  string  $html  셸 HTML
     * @return array{0: string, 1: array<string, mixed>} 블록 자리를 표지로 바꾼 HTML 과 풀어 낸 설정
     */
    private function splitConfigBlock(string $html): array
    {
        $pattern = '#<script type="application/json" id="g7-config">(.*?)</script>#s';
        $this->assertSame(1, preg_match($pattern, $html, $match), '#g7-config 블록이 없다');
        $config = json_decode($match[1], true);
        $this->assertIsArray($config, '#g7-config 블록이 JSON 으로 풀리지 않는다');

        return [(string) preg_replace($pattern, '<!--g7-config-->', $html, 1), $config];
    }

    /**
     * 셸 뷰와 7.1 픽스처.
     *
     * @return array<string, array{0: string, 1: string}> 데이터셋
     */
    public static function shellProvider(): array
    {
        return [
            'user' => ['app', 'app-7.1.blade.txt'],
            'admin' => ['admin', 'admin-7.1.blade.txt'],
        ];
    }

    /**
     * 민감·비노출 키(사이트 URL·사용자 화면 URL·메일 비밀번호류)와 엔진 전용 값은 싣지 않습니다.
     *
     * @scenario case=sensitive
     *
     * @effects sensitive_keys_absent
     *
     * @return void 반환값 없음
     */
    public function test_sensitive_and_engine_only_keys_are_absent(): void
    {
        config(['g7_settings.core.general.user_screen_url' => 'https://front.example.test']);
        $data = $this->getJson('/api/frontend-config')->assertOk()->json('data');
        $json = json_encode($data);

        $this->assertArrayNotHasKey('site_url', $data['settings']['general'] ?? []);
        $this->assertArrayNotHasKey('user_screen_url', $data['settings']['general'] ?? []);
        $this->assertStringNotContainsString('front.example.test', (string) $json);
        $this->assertStringNotContainsString('"password"', (string) $json);
        $this->assertStringNotContainsString('mail_password', (string) $json);
        foreach (['bundleUrls', 'moduleAssets', 'stateContracts', 'trustedScriptHosts'] as $engineOnly) {
            $this->assertArrayNotHasKey($engineOnly, $data);
        }
    }

    /**
     * 활성 사용자 템플릿이 없어도 200 입니다.
     *
     * @scenario case=no_template
     *
     * @effects api_works_without_template
     *
     * @return void 반환값 없음
     */
    public function test_works_without_user_template(): void
    {
        $templates = Mockery::mock(TemplateService::class);
        $templates->shouldReceive('getActiveTemplateIdentifier')->andThrow(new TemplateNotFoundException('user'));
        $this->app->instance(TemplateService::class, $templates);

        $this->getJson('/api/frontend-config')->assertOk()->assertJsonPath('data.scope', 'user');
    }

    /**
     * 로그인 화면 계산값을 만드는 확장 필터가 예외를 던져도 사용자·관리자 API 가 200 으로 대체값을 냅니다.
     *
     * 가입 실효값 필터(`core.auth.self_registration_open`)가 던지면 원 설정값(`security.registration_enabled`)으로,
     * 자동 이동 경로 필터(`core.auth.login_auto_redirect`)가 던지면 이메일 로그인·자동 이동 없음으로 둔다.
     *
     * @scenario case=filter_throws
     *
     * @effects filter_exception_falls_back
     *
     * @return void 반환값 없음
     */
    public function test_extension_filter_exception_falls_back(): void
    {
        try {
            HookManager::addFilter('core.auth.self_registration_open', function (): bool {
                throw new \RuntimeException('extension filter failure');
            });

            $expected = (bool) g7_core_settings('security.registration_enabled', true);
            $this->getJson('/api/frontend-config')->assertOk()->assertJsonPath('data.app_config.auth.registration_open', $expected);
            $this->actingAs($this->admin())->getJson('/api/admin/frontend-config')->assertOk()->assertJsonPath('data.app_config.auth.registration_open', $expected);

            HookManager::addFilter('core.auth.login_auto_redirect', function (): ?string {
                throw new \RuntimeException('extension filter failure');
            });

            $this->getJson('/api/frontend-config')->assertOk()
                ->assertJsonPath('data.app_config.auth.login_identifier.user', 'email')
                ->assertJsonPath('data.app_config.auth.auto_redirect.user', null);
        } finally {
            HookManager::clearFilter('core.auth.self_registration_open');
            HookManager::clearFilter('core.auth.login_auto_redirect');
        }
    }

    /**
     * 관리자 API — 비인증 401, 일반 회원 403, 관리자 200.
     *
     * @scenario case=admin_auth
     *
     * @effects admin_api_requires_admin
     *
     * @return void 반환값 없음
     */
    public function test_admin_api_permissions(): void
    {
        $this->getJson('/api/admin/frontend-config')->assertUnauthorized();
        $this->actingAs(User::factory()->create())->getJson('/api/admin/frontend-config')->assertForbidden();
        $this->actingAs($this->admin())->getJson('/api/admin/frontend-config')->assertOk();
    }

    /**
     * 셸 컴포저를 실행해 뷰에 바인딩된 값을 모읍니다.
     *
     * @param  class-string  $composer  컴포저 클래스
     * @return array<string, mixed> 뷰 데이터
     */
    private function composeShell(string $composer): array
    {
        $captured = [];
        $view = Mockery::mock(View::class);
        $view->shouldReceive('with')->andReturnUsing(function (string $key, mixed $value) use (&$captured, $view) {
            $captured[$key] = $value;

            return $view;
        });

        app($composer)->compose($view);

        return $captured;
    }

    /**
     * 응답의 맵 필드가 셸(`window.G7Config`)이 심는 값과 JSON 모양까지 같은지 단언합니다.
     *
     * 값 비교({@see normalize()})는 연관 배열로 풀어 `{}` 와 `[]` 를 구분하지 못한다. 셸 뷰가 하는 그대로
     * 최상위를 `(object)` 로 감싸 인코딩한 값과, 응답 본문을 객체로 푼 값을 비교해 빈 맵의 모양 차이까지 잡는다.
     * 보이는 필드가 없는 코어 카테고리(`identity`)는 빈 맵 `{}` 로 나가야 한다.
     *
     * @param  array<string, mixed>  $shell  셸 뷰 데이터
     * @param  TestResponse  $response  공개 설정 API 응답
     * @return void 반환값 없음
     */
    private function assertSameShapeAsShell(array $shell, TestResponse $response): void
    {
        $data = json_decode((string) $response->getContent())->data;

        foreach (['frontendSettings' => 'settings', 'moduleSettings' => 'modules', 'pluginSettings' => 'plugins', 'templateSettings' => 'template_settings', 'appConfig' => 'app_config'] as $shellKey => $apiKey) {
            $this->assertEquals(
                json_decode((string) json_encode((object) $shell[$shellKey])),
                $data->{$apiKey},
                "{$apiKey} 의 JSON 모양이 셸({$shellKey})과 다르다"
            );
        }

        $settingsJson = (string) json_encode($data->settings);
        $this->assertStringContainsString('"identity":{}', $settingsJson, '보이는 필드가 없는 카테고리는 빈 맵이어야 한다');
        $this->assertStringNotContainsString('"identity":[]', $settingsJson);
    }

    /**
     * 객체·배열 표현 차이를 없애 비교합니다.
     *
     * @param  mixed  $value  값
     * @return mixed JSON 왕복 값
     */
    private function normalize(mixed $value): mixed
    {
        return json_decode((string) json_encode($value), true);
    }

    /**
     * 관리자 회원을 만듭니다.
     *
     * @return User 관리자
     */
    private function admin(): User
    {
        $user = User::factory()->create();
        $permission = Permission::firstOrCreate(['identifier' => 'core.settings.read'], [
            'name' => json_encode(['ko' => 'core.settings.read', 'en' => 'core.settings.read']),
            'description' => json_encode(['ko' => 'core.settings.read', 'en' => 'core.settings.read']),
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'type' => 'admin',
        ]);
        $role = Role::create(['identifier' => 'fc_admin_'.uniqid(), 'name' => json_encode(['ko' => '시험', 'en' => 'Test']), 'is_active' => true]);
        $role->permissions()->sync([$permission->id]);
        $base = Role::firstOrCreate(['identifier' => 'admin'], ['name' => json_encode(['ko' => '관리자', 'en' => 'Admin']), 'is_active' => true]);
        $user->roles()->attach([$role->id => ['assigned_at' => now()], $base->id => ['assigned_at' => now()]]);

        return $user->fresh();
    }
}
