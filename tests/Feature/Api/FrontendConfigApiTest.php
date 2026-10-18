<?php

namespace Tests\Feature\Api;

use App\Enums\ExtensionOwnerType;
use App\Exceptions\TemplateNotFoundException;
use App\Http\View\Composers\TemplateComposer;
use App\Http\View\Composers\UserTemplateComposer;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Blade;
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
        $api = $this->getJson('/api/frontend-config')->assertOk()->json('data');

        $this->assertSame('user', $api['scope']);
        $this->assertEquals($this->normalize($shell['frontendSettings']), $this->normalize($api['settings']));
        $this->assertEquals($this->normalize($shell['moduleSettings']), $this->normalize($api['modules']));
        $this->assertEquals($this->normalize($shell['pluginSettings']), $this->normalize($api['plugins']));
        $this->assertEquals($this->normalize($shell['appConfig']), $this->normalize($api['app_config']));
        $this->assertEquals($shell['consentInitial'], $api['consent_initial']);
        $this->assertEquals($shell['customRoutes'], $api['custom_routes']);
        $this->assertEquals($shell['websocketConfig'], $api['websocket']);
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
        $api = $this->actingAs($this->admin())->getJson('/api/admin/frontend-config')->assertOk()->json('data');

        $this->assertSame('admin', $api['scope']);
        $this->assertEquals($this->normalize($shell['frontendSettings']), $this->normalize($api['settings']));
        $this->assertEquals($this->normalize($shell['moduleSettings']), $this->normalize($api['modules']));
        $this->assertArrayNotHasKey('consent_initial', $api);
    }

    /**
     * 셸 HTML 은 7.1 셸과 `customRoutes` 두 줄만 다르고 나머지는 바이트까지 같습니다 (계획 WP-C1·WP-K2 시험 잠금).
     *
     * 설정 조립을 공용 서비스로 옮긴 뒤에도 같은 값이 같은 자리에 같은 모양으로 나가는지 — 7.1 셸 템플릿(픽스처)을
     * 같은 뷰 데이터로 그린 결과와 비교한다. 셸을 일부러 바꾸면 픽스처도 그 변경과 함께 고친다.
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

        $this->assertStringContainsString('customRoutes: ', $html);
        $this->assertStringContainsString('"appKey":"lock-key"', $html);
        $withoutCustomRoutes = (string) preg_replace('/\R[^\n]*routes\/custom\.php 로 등록된[^\n]*\R[^\n]*customRoutes: [^\n]*/u', '', $html);
        $this->assertSame($legacy, $withoutCustomRoutes);
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
