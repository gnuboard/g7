<?php

namespace Tests\Feature\Api\Admin;

use App\Contracts\Extension\ModuleManagerInterface;
use App\Contracts\Extension\PluginManagerInterface;
use App\Contracts\Extension\TemplateManagerInterface;
use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\LanguagePackService;
use App\Services\TemplateService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Illuminate\Testing\TestResponse;
use Mockery;
use Tests\TestCase;

/**
 * 확장 설치·활성화·비활성화·제거 실패 응답이 DB 내부 정보를 싣지 않는다 (7.1.0 전수 실측 O-F7).
 *
 * 개발 DB 에 남은 테이블 때문에 모듈 설치가 실패하자, 운영 모드(APP_DEBUG=false)인데도 관리자 화면 토스트에
 * 「SQLSTATE[42S01] … (Connection: mysql, Host: …, Port: …, Database: …, SQL: create table …)」가 그대로 나왔다.
 * Service 가 모든 예외 원문을 「…에 실패했습니다: :error」 로 감싸 422 검증 오류로 던지기 때문에
 * ResponseHelper 의 운영 모드 500 차단을 거치지 않았다.
 *
 * 운영 모드에서는 DB·PHP 런타임 예외 원문을 일반 문구로 바꾸고 원문은 서버 로그에 남긴다.
 * 디버그 모드와 확장이 일부러 던진 사용자용 사유는 종전대로 보인다.
 */
class ExtensionFailureInternalDetailMaskingTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 실측에서 본 것과 같은 모양의 DB 예외를 만듭니다.
     *
     * @return QueryException 테이블 중복 생성 예외
     */
    private function tableExistsException(): QueryException
    {
        $pdo = new \PDOException("SQLSTATE[42S01]: Base table or view already exists: 1050 Table 'g7_acme_memos' already exists");

        return new QueryException(
            'mysql',
            'create table `g7_acme_memos` (`id` bigint unsigned not null auto_increment primary key)',
            [],
            $pdo,
            ['driver' => 'mysql', 'host' => 'db-internal.example', 'port' => '3306', 'database' => 'g7_secret_db'],
        );
    }

    /**
     * 확장 관리 권한을 가진 관리자를 만듭니다.
     *
     * @return User 관리자
     */
    private function admin(): User
    {
        $user = User::factory()->create();
        $role = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'description' => ['ko' => '관리자', 'en' => 'Administrator'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        $ids = [];
        foreach ([
            'core.modules.install', 'core.modules.activate', 'core.modules.uninstall',
            'core.plugins.install', 'core.plugins.activate', 'core.plugins.uninstall',
            'core.templates.install', 'core.templates.activate', 'core.templates.uninstall',
        ] as $identifier) {
            $ids[] = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => ['ko' => $identifier, 'en' => $identifier],
                    'description' => ['ko' => $identifier, 'en' => $identifier],
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => PermissionType::Admin,
                ]
            )->id;
        }
        $role->permissions()->syncWithoutDetaching($ids);
        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 모든 매니저 메서드가 같은 DB 예외를 던지도록 바꿉니다.
     *
     * @param  \Throwable  $e  매니저가 던질 예외
     * @return void 반환값 없음
     */
    private function managersThrow(\Throwable $e): void
    {
        $modules = Mockery::mock(ModuleManagerInterface::class)->shouldIgnoreMissing();
        foreach (['installModule', 'activateModule', 'deactivateModule', 'uninstallModule'] as $method) {
            $modules->shouldReceive($method)->andThrow($e);
        }
        $this->app->instance(ModuleManagerInterface::class, $modules);

        $plugins = Mockery::mock(PluginManagerInterface::class)->shouldIgnoreMissing();
        foreach (['installPlugin', 'activatePlugin', 'deactivatePlugin', 'uninstallPlugin'] as $method) {
            $plugins->shouldReceive($method)->andThrow($e);
        }
        $this->app->instance(PluginManagerInterface::class, $plugins);

        $templates = Mockery::mock(TemplateManagerInterface::class)->shouldIgnoreMissing();
        $templates->shouldReceive('getTemplateInfo')->andReturn(['identifier' => 'acme-theme']);
        foreach (['installTemplate', 'uninstallTemplate'] as $method) {
            $templates->shouldReceive($method)->andThrow($e);
        }
        $this->app->instance(TemplateManagerInterface::class, $templates);
        // TemplateService 는 싱글톤이라 부팅 중 실제 매니저로 이미 만들어져 있다 — 가짜 매니저로 다시 만든다.
        $this->app->forgetInstance(TemplateService::class);
    }

    /**
     * 응답 본문 전체에 DB 내부 정보가 없는지 확인합니다.
     *
     * @param  TestResponse  $response  검사할 응답
     * @param  string  $label  실패 메시지에 붙일 작업 이름
     * @return void 반환값 없음
     */
    private function assertNoInternalDetail(TestResponse $response, string $label): void
    {
        $body = $response->getContent();
        foreach (['SQLSTATE', 'db-internal.example', 'g7_secret_db', 'create table', 'Connection: mysql'] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "{$label}: 응답에 내부 정보 「{$needle}」 가 실렸습니다 — {$body}");
        }
        $this->assertStringContainsString(
            __('extensions.errors.internal_error'),
            (string) $response->json('message'),
            "{$label}: 일반 문구로 바꾼 사유가 message 에 없습니다 — {$body}"
        );
    }

    /**
     * 운영 모드에서 모듈·플러그인·템플릿 설치·활성화·비활성화·제거 실패 응답은 DB 내부 정보를 싣지 않고,
     * 원문은 서버 로그에 남긴다.
     *
     * @scenario case=production_masks_db_exception
     *
     * @effects extension_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_production_failure_responses_hide_database_detail(): void
    {
        config(['app.debug' => false]);
        $logged = [];
        Log::listen(function ($message) use (&$logged) {
            $logged[] = $message->message.' '.json_encode($message->context['detail'] ?? null);
        });

        $this->managersThrow($this->tableExistsException());
        $admin = $this->admin();

        $calls = [
            'module install' => ['postJson', '/api/admin/modules/install', ['module_name' => 'acme-core']],
            'module activate' => ['postJson', '/api/admin/modules/activate', ['module_name' => 'acme-core']],
            'module deactivate' => ['postJson', '/api/admin/modules/deactivate', ['module_name' => 'acme-core']],
            'module uninstall' => ['deleteJson', '/api/admin/modules/uninstall', ['module_name' => 'acme-core']],
            'plugin install' => ['postJson', '/api/admin/plugins/install', ['plugin_name' => 'acme-pay']],
            'plugin activate' => ['postJson', '/api/admin/plugins/activate', ['plugin_name' => 'acme-pay']],
            'plugin deactivate' => ['postJson', '/api/admin/plugins/deactivate', ['plugin_name' => 'acme-pay']],
            'plugin uninstall' => ['deleteJson', '/api/admin/plugins/uninstall', ['plugin_name' => 'acme-pay']],
            'template install' => ['postJson', '/api/admin/templates/install', ['template_name' => 'acme-theme']],
            'template uninstall' => ['deleteJson', '/api/admin/templates/uninstall', ['template_name' => 'acme-theme']],
        ];

        foreach ($calls as $label => [$method, $uri, $payload]) {
            $response = $this->actingAs($admin, 'sanctum')->{$method}($uri, $payload);
            $this->assertGreaterThanOrEqual(400, $response->status(), "{$label}: 실패 응답이어야 합니다");
            $this->assertNoInternalDetail($response, $label);
        }

        $this->assertNotEmpty(
            array_filter($logged, fn ($line) => str_contains($line, 'SQLSTATE[42S01]')),
            '가린 예외 원문이 서버 로그에 남아야 합니다'
        );
    }

    /**
     * 언어팩 설치 실패도 운영 모드에서 DB 내부 정보를 싣지 않는다(컨트롤러가 예외 객체를 넘겨 응답 계층이 가린다).
     *
     * @scenario case=production_masks_db_exception
     *
     * @effects extension_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_language_pack_install_failure_hides_database_detail(): void
    {
        config(['app.debug' => false]);
        $service = Mockery::mock(LanguagePackService::class)->shouldIgnoreMissing();
        $service->shouldReceive('installFromBundled')->andThrow($this->tableExistsException());
        $this->app->instance(LanguagePackService::class, $service);

        $admin = $this->admin();
        $permission = Permission::firstOrCreate(
            ['identifier' => 'core.language_packs.install'],
            [
                'name' => ['ko' => 'lp', 'en' => 'lp'],
                'description' => ['ko' => 'lp', 'en' => 'lp'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => PermissionType::Admin,
            ]
        );
        Role::where('identifier', 'admin')->first()->permissions()->syncWithoutDetaching([$permission->id]);

        $response = $this->actingAs($admin->fresh(), 'sanctum')
            ->postJson('/api/admin/language-packs/install-from-bundled', ['identifier' => 'g7-core-ja']);

        $this->assertSame(500, $response->status(), $response->getContent());
        foreach (['SQLSTATE', 'db-internal.example', 'g7_secret_db', 'create table'] as $needle) {
            $this->assertStringNotContainsString($needle, $response->getContent());
        }
    }

    /**
     * 디버그 모드에서는 개발자가 원인을 볼 수 있게 원문을 그대로 보인다.
     *
     * @scenario case=debug_keeps_raw_detail
     *
     * @effects extension_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_debug_mode_keeps_raw_detail(): void
    {
        config(['app.debug' => true]);
        $this->managersThrow($this->tableExistsException());

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/modules/install', ['module_name' => 'acme-core']);

        $this->assertStringContainsString('SQLSTATE[42S01]', (string) $response->json('message'));
    }

    /**
     * 확장이 일부러 던진 사용자용 사유(번역 문구)는 운영 모드에서도 그대로 보인다.
     *
     * @scenario case=domain_reason_kept
     *
     * @effects extension_failure_hides_internal_detail
     *
     * @return void 반환값 없음
     */
    public function test_domain_reason_is_kept_in_production(): void
    {
        config(['app.debug' => false]);
        $reason = __('modules.not_found', ['module' => 'acme-core']);
        $this->managersThrow(new \Exception($reason));

        $response = $this->actingAs($this->admin(), 'sanctum')
            ->postJson('/api/admin/modules/install', ['module_name' => 'acme-core']);

        $this->assertStringContainsString($reason, (string) $response->json('message'));
        $this->assertStringNotContainsString(__('extensions.errors.internal_error'), (string) $response->json('message'));
    }
}
