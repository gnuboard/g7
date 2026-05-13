<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Extension\PluginManager;
use App\Models\Permission;
use App\Models\Plugin;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * PluginSettingsController API 테스트
 *
 * 플러그인 설정 조회, 수정, 레이아웃 조회 API 엔드포인트를 테스트합니다.
 * 설정은 파일 기반으로 storage/app/plugins/{identifier}/settings/setting.json에 저장됩니다.
 */
class PluginSettingsControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    private PluginManager $pluginManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
        $this->pluginManager = app(PluginManager::class);
    }

    /**
     * 관리자 역할 생성 및 할당
     *
     * @param  array  $permissions  사용자에게 부여할 권한 식별자 목록
     */
    private function createAdminUser(array $permissions = ['core.plugins.read', 'core.plugins.update']): User
    {
        $user = User::factory()->create();

        // 권한 생성
        $permissionIds = [];
        foreach ($permissions as $permIdentifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                [
                    'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                    'description' => json_encode(['ko' => $permIdentifier.' 권한', 'en' => $permIdentifier.' Permission']),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }

        // 고유한 식별자로 테스트용 역할 생성
        $roleIdentifier = 'admin_settings_test_'.uniqid();
        $testRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

        // admin 역할도 추가 (admin 미들웨어 통과용)
        $adminRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        // 테스트용 역할에 권한 할당
        $testRole->permissions()->sync($permissionIds);

        // 사용자에게 admin 역할과 테스트용 역할 모두 할당
        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $user->roles()->attach($testRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 인증된 요청 헬퍼 메서드
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 테스트용 플러그인 생성 (DB 레코드만)
     */
    private function createTestPlugin(string $identifier = 'test-settings-plugin'): Plugin
    {
        return Plugin::create([
            'identifier' => $identifier,
            'vendor' => 'test',
            'name' => json_encode(['ko' => '테스트 플러그인', 'en' => 'Test Plugin']),
            'version' => '1.0.0',
            'description' => json_encode(['ko' => '테스트용 플러그인', 'en' => 'Test Plugin']),
            'status' => 'active',
        ]);
    }

    /**
     * 테스트용 설정 파일 생성
     */
    private function createTestSettingsFile(string $identifier, array $settings): void
    {
        $settingsDir = storage_path("app/plugins/{$identifier}/settings");
        $settingsPath = $settingsDir.'/setting.json';

        if (! File::isDirectory($settingsDir)) {
            File::makeDirectory($settingsDir, 0755, true);
        }

        File::put($settingsPath, json_encode($settings, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    }

    /**
     * 테스트용 설정 파일 삭제
     */
    private function cleanupTestSettings(string $identifier): void
    {
        $pluginDir = storage_path("app/plugins/{$identifier}");
        if (File::isDirectory($pluginDir)) {
            File::deleteDirectory($pluginDir);
        }
    }

    // ========================================================================
    // 인증/권한 테스트
    // ========================================================================

    /**
     * 인증 없이 설정 조회 시 401 반환
     */
    public function test_show_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/plugins/test-plugin/settings');

        $response->assertStatus(401);
    }

    /**
     * 권한 없이 설정 조회 시 403 반환
     */
    public function test_show_returns_403_without_permission(): void
    {
        // 권한 없는 관리자 생성
        $user = User::factory()->create();
        $adminRole = Role::where('identifier', 'admin')->first();

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/plugins/test-plugin/settings');

        $response->assertStatus(403);
    }

    /**
     * 인증 없이 설정 업데이트 시 401 반환
     */
    public function test_update_returns_401_without_authentication(): void
    {
        $response = $this->putJson('/api/admin/plugins/test-plugin/settings', [
            'key' => 'value',
        ]);

        $response->assertStatus(401);
    }

    /**
     * 권한 없이 설정 업데이트 시 403 반환
     */
    public function test_update_returns_403_without_permission(): void
    {
        // read 권한만 있는 관리자 생성
        $user = $this->createAdminUser(['core.plugins.read']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->putJson('/api/admin/plugins/test-plugin/settings', [
            'key' => 'value',
        ]);

        $response->assertStatus(403);
    }

    /**
     * 인증 없이 레이아웃 조회 시 401 반환
     */
    public function test_layout_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/plugins/test-plugin/settings/layout');

        $response->assertStatus(401);
    }

    // ========================================================================
    // show() 테스트 - 설정 조회
    // ========================================================================

    /**
     * 존재하지 않는 플러그인 설정 조회 시 404 반환
     */
    public function test_show_returns_404_for_nonexistent_plugin(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/plugins/nonexistent-plugin/settings');

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    /**
     * 실제 설치된 Daum 우편번호 플러그인 설정 조회 테스트
     */
    public function test_show_returns_settings_for_installed_plugin(): void
    {
        // Daum 우편번호 플러그인이 설치되어 있는지 확인
        $pluginInstance = $this->pluginManager->getPlugin('sirsoft-daum_postcode');

        if (! $pluginInstance) {
            $this->markTestSkipped('sirsoft-daum_postcode plugin not installed');
        }

        // DB에 플러그인 레코드가 있는지 확인
        $plugin = Plugin::where('identifier', 'sirsoft-daum_postcode')->first();
        if (! $plugin) {
            $this->createTestPlugin('sirsoft-daum_postcode');
        }

        $response = $this->authRequest()->getJson('/api/admin/plugins/sirsoft-daum_postcode/settings');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // 기본 설정값이 반환되는지 확인
        $data = $response->json('data');
        $this->assertIsArray($data);
    }

    // ========================================================================
    // update() 테스트 - 설정 업데이트
    // ========================================================================

    /**
     * 존재하지 않는 플러그인 설정 업데이트 시 에러 반환
     */
    public function test_update_returns_error_for_nonexistent_plugin(): void
    {
        $response = $this->authRequest()->putJson('/api/admin/plugins/nonexistent-plugin/settings', [
            'key' => 'value',
        ]);

        // 플러그인 인스턴스가 없으면 저장 실패
        $response->assertStatus(500)
            ->assertJson(['success' => false]);
    }

    /**
     * 실제 설치된 플러그인 설정 업데이트 테스트
     */
    public function test_update_saves_settings_for_installed_plugin(): void
    {
        $pluginInstance = $this->pluginManager->getPlugin('sirsoft-daum_postcode');

        if (! $pluginInstance) {
            $this->markTestSkipped('sirsoft-daum_postcode plugin not installed');
        }

        // DB에 플러그인 레코드 생성
        $plugin = Plugin::where('identifier', 'sirsoft-daum_postcode')->first();
        if (! $plugin) {
            $this->createTestPlugin('sirsoft-daum_postcode');
        }

        $response = $this->authRequest()->putJson('/api/admin/plugins/sirsoft-daum_postcode/settings', [
            'animation' => true,
            'autoClose' => false,
        ]);

        $response->assertStatus(200)
            ->assertJson(['success' => true]);

        // 파일에서 값 확인
        $settingsPath = storage_path('app/plugins/sirsoft-daum_postcode/settings/setting.json');
        if (File::exists($settingsPath)) {
            $savedContent = json_decode(File::get($settingsPath), true);
            $this->assertTrue($savedContent['animation'] ?? false);
            $this->assertFalse($savedContent['autoClose'] ?? true);
        }

        // 정리
        $this->cleanupTestSettings('sirsoft-daum_postcode');
    }

    // ========================================================================
    // layout() 테스트 - 레이아웃 조회
    // ========================================================================

    /**
     * 존재하지 않는 플러그인 레이아웃 조회 시 404 반환
     */
    public function test_layout_returns_404_for_nonexistent_plugin(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/plugins/nonexistent-plugin/settings/layout');

        $response->assertStatus(404)
            ->assertJson(['success' => false]);
    }

    /**
     * Daum 우편번호 플러그인 레이아웃 조회 성공
     */
    public function test_layout_returns_layout_for_daum_postcode_plugin(): void
    {
        $layoutPath = base_path('plugins/sirsoft-daum_postcode/resources/layouts/settings.json');

        if (! file_exists($layoutPath)) {
            $this->markTestSkipped('Daum postcode plugin layout file not found');
        }

        $pluginInstance = $this->pluginManager->getPlugin('sirsoft-daum_postcode');

        if (! $pluginInstance) {
            $this->markTestSkipped('sirsoft-daum_postcode plugin not installed');
        }

        // DB에 플러그인 레코드 생성
        $plugin = Plugin::where('identifier', 'sirsoft-daum_postcode')->first();
        if (! $plugin) {
            $this->createTestPlugin('sirsoft-daum_postcode');
        }

        $response = $this->authRequest()->getJson('/api/admin/plugins/sirsoft-daum_postcode/settings/layout');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'version',
                    'meta',
                    'schema',
                ],
            ]);
    }

    // ========================================================================
    // 응답 구조 테스트
    // ========================================================================

    /**
     * 설정 조회 응답 구조 검증
     */
    public function test_show_response_structure(): void
    {
        $pluginInstance = $this->pluginManager->getPlugin('sirsoft-daum_postcode');

        if (! $pluginInstance) {
            $this->markTestSkipped('sirsoft-daum_postcode plugin not installed');
        }

        // DB에 플러그인 레코드 생성
        $plugin = Plugin::where('identifier', 'sirsoft-daum_postcode')->first();
        if (! $plugin) {
            $this->createTestPlugin('sirsoft-daum_postcode');
        }

        $response = $this->authRequest()->getJson('/api/admin/plugins/sirsoft-daum_postcode/settings');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ])
            ->assertJson([
                'success' => true,
            ]);
    }

    /**
     * 설정 업데이트 응답에 업데이트된 설정 포함
     */
    public function test_update_response_includes_updated_settings(): void
    {
        $pluginInstance = $this->pluginManager->getPlugin('sirsoft-daum_postcode');

        if (! $pluginInstance) {
            $this->markTestSkipped('sirsoft-daum_postcode plugin not installed');
        }

        // DB에 플러그인 레코드 생성
        $plugin = Plugin::where('identifier', 'sirsoft-daum_postcode')->first();
        if (! $plugin) {
            $this->createTestPlugin('sirsoft-daum_postcode');
        }

        $response = $this->authRequest()->putJson('/api/admin/plugins/sirsoft-daum_postcode/settings', [
            'animation' => true,
        ]);

        $response->assertStatus(200)
            ->assertJson([
                'success' => true,
            ]);

        // 응답에 업데이트된 설정이 포함되어 있는지 확인
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertArrayHasKey('animation', $data);
        $this->assertTrue($data['animation']);

        // 정리
        $this->cleanupTestSettings('sirsoft-daum_postcode');
    }
}
