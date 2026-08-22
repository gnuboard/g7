<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use App\Services\PluginService;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `check-modified-layouts` 및 확장 업데이트 응답의 계약 회귀 테스트.
 *
 * 배경(Chrome MCP 정밀 점검에서 실측):
 * - 미존재 식별자에 대해 형제 엔드포인트(show/uninstall-info)는 404 를 주는데
 *   check-modified-layouts 만 200 + "수정된 레이아웃 없음" 을 반환했다. 오타·제거된
 *   확장이 "수정 없음" 으로 조용히 통과해, 레이아웃 보존 판단을 그르친다.
 * - 업데이트 성공 메시지가 치환 파라미터 없이 반환되어 `:module` / `:version`
 *   플레이스홀더가 그대로 사용자에게 노출됐다.
 *
 * 컨트롤러 소스의 철자가 아니라 **실제 HTTP 응답**(상태코드·메시지 본문)으로 계약을
 * 고정한다. 확장 서비스는 컨테이너에서 대역으로 바꿔 파일시스템/네트워크 의존을 제거한다.
 */
class CheckModifiedLayoutsContractTest extends TestCase
{
    use RefreshDatabase;

    private string $token;

    /** 확장 종류별 검증 축: [서비스, 조회 메서드, URL 세그먼트, 업데이트 메서드, 치환 키] */
    private const EXTENSIONS = [
        ['service' => ModuleService::class, 'lookup' => 'getModuleInfo', 'segment' => 'modules', 'update' => 'updateModule', 'name' => 'zz-audit-module'],
        ['service' => PluginService::class, 'lookup' => 'getPluginInfo', 'segment' => 'plugins', 'update' => 'updatePlugin', 'name' => 'zz-audit-plugin'],
        ['service' => TemplateService::class, 'lookup' => 'getTemplateInfo', 'segment' => 'templates', 'update' => 'performVersionUpdate', 'name' => 'zz-audit-template'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $admin = $this->createAdminUser([
            'core.modules.read', 'core.modules.install',
            'core.plugins.read', 'core.plugins.install',
            'core.templates.read', 'core.templates.install',
        ]);
        $this->token = $admin->createToken('test-token')->plainTextToken;
    }

    /**
     * admin 역할 + 지정 권한을 가진 사용자를 생성합니다.
     *
     * AdminMiddleware 가 hasRole('admin') 을 확인하므로 admin 역할 부여가 필수입니다.
     *
     * @param  array<int, string>  $permissions  부여할 권한 식별자 목록
     * @return User 생성된 관리자
     */
    private function createAdminUser(array $permissions): User
    {
        $user = User::factory()->create();

        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permissionIds[] = Permission::firstOrCreate(
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

        $testRole = Role::create([
            'identifier' => 'admin_test_'.uniqid(),
            'name' => ['ko' => '테스트 관리자', 'en' => 'Test Administrator'],
            'description' => ['ko' => '테스트 관리자', 'en' => 'Test Administrator'],
            'is_active' => true,
        ]);
        $testRole->permissions()->sync($permissionIds);

        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => ['ko' => '관리자', 'en' => 'Administrator'],
                'description' => ['ko' => '시스템 관리자', 'en' => 'System Administrator'],
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        foreach ([$adminRole->id, $testRole->id] as $roleId) {
            $user->roles()->attach($roleId, ['assigned_at' => now(), 'assigned_by' => null]);
        }

        return $user->fresh();
    }

    /**
     * 인증 헤더가 붙은 요청 헬퍼.
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 미존재 식별자는 200 "수정 없음" 이 아니라 404 여야 한다 (D4).
     */
    public function test_check_modified_layouts_returns_404_for_missing_identifier(): void
    {
        foreach (self::EXTENSIONS as $ext) {
            $this->mock($ext['service'], function ($mock) use ($ext) {
                $mock->shouldReceive($ext['lookup'])->andReturn(null);
                $mock->shouldNotReceive('checkModifiedLayouts');
            });

            $response = $this->authRequest()
                ->getJson("/api/admin/{$ext['segment']}/{$ext['name']}/check-modified-layouts");

            $response->assertStatus(404);
            $this->assertFalse(
                $response->json('success'),
                "{$ext['segment']}: 미존재 식별자가 성공 응답으로 통과했습니다."
            );
            $this->assertStringNotContainsString(
                'modified',
                (string) $response->json('message'),
                "{$ext['segment']}: 미존재인데 수정 감지 결과 메시지가 반환되었습니다."
            );
        }
    }

    /**
     * 존재하는 식별자는 종전대로 200 + 감지 결과를 반환한다 (비회귀).
     */
    public function test_check_modified_layouts_returns_200_for_existing_identifier(): void
    {
        foreach (self::EXTENSIONS as $ext) {
            $this->mock($ext['service'], function ($mock) use ($ext) {
                $mock->shouldReceive($ext['lookup'])->andReturn(['identifier' => $ext['name']]);
                $mock->shouldReceive('checkModifiedLayouts')->andReturn([
                    'has_modified_layouts' => true,
                    'modified_count' => 2,
                    'modified_layouts' => [
                        ['name' => 'a', 'size_diff' => 10],
                        ['name' => 'b', 'size_diff' => -4],
                    ],
                ]);
            });

            $response = $this->authRequest()
                ->getJson("/api/admin/{$ext['segment']}/{$ext['name']}/check-modified-layouts");

            $response->assertStatus(200);
            $this->assertSame(2, $response->json('data.modified_count'), $ext['segment']);
        }
    }

    /**
     * 업데이트 성공 메시지에 식별자·버전이 실제로 채워져야 한다 (D7).
     */
    public function test_update_success_message_has_substituted_placeholders(): void
    {
        foreach (self::EXTENSIONS as $ext) {
            $this->mock($ext['service'], function ($mock) use ($ext) {
                // module_info/plugin_info/template_info 를 비워 success() 경로를 통과시킨다.
                $mock->shouldReceive($ext['update'])->andReturn([
                    'success' => true,
                    'from_version' => '1.0.0',
                    'to_version' => '1.2.3',
                ]);
            });

            $response = $this->authRequest()
                ->postJson("/api/admin/{$ext['segment']}/{$ext['name']}/update", [
                    'layout_strategy' => 'keep',
                ]);

            $response->assertStatus(200);
            $message = (string) $response->json('message');

            $this->assertStringContainsString($ext['name'], $message, "{$ext['segment']}: 식별자가 메시지에 치환되지 않았습니다.");
            $this->assertStringContainsString('1.2.3', $message, "{$ext['segment']}: 버전이 메시지에 치환되지 않았습니다.");
            $this->assertDoesNotMatchRegularExpression(
                '/:(module|plugin|template|version)\b/',
                $message,
                "{$ext['segment']}: 치환되지 않은 플레이스홀더가 사용자에게 노출됩니다 — {$message}"
            );
        }
    }

    /**
     * 인증 없이 호출하면 401 (라우트 가드 비회귀).
     */
    public function test_check_modified_layouts_requires_authentication(): void
    {
        foreach (self::EXTENSIONS as $ext) {
            $this->getJson("/api/admin/{$ext['segment']}/{$ext['name']}/check-modified-layouts")
                ->assertStatus(401);
        }
    }
}
