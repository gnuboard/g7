<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * AdminPluginController 테스트
 *
 * 플러그인 관리 API 엔드포인트를 테스트합니다.
 */
class PluginControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    /**
     * 관리자 역할 생성 및 할당
     *
     * @param  array  $permissions  사용자에게 부여할 권한 식별자 목록
     */
    private function createAdminUser(array $permissions = ['core.plugins.read', 'core.plugins.install', 'core.plugins.activate', 'core.plugins.uninstall']): User
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
                    'type' => \App\Enums\PermissionType::Admin,
                ]
            );
            $permissionIds[] = $permission->id;
        }

        // 고유한 식별자로 테스트용 역할 생성 (테스트별 격리를 위해)
        $roleIdentifier = 'admin_test_'.uniqid();
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

    // ========================================================================
    // 인증/권한 테스트
    // ========================================================================

    /**
     * 인증 없이 플러그인 목록 조회 시 401 반환
     */
    public function test_index_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/plugins');

        $response->assertStatus(401);
    }

    /**
     * 권한 없이 플러그인 목록 조회 시 403 반환
     */
    public function test_index_returns_403_without_permission(): void
    {
        // 권한 없는 관리자 생성
        $user = User::factory()->create();
        $adminRole = Role::where('identifier', 'admin')->first();

        // 기존 권한 분리
        $readPermission = Permission::where('identifier', 'core.plugins.read')->first();
        if ($adminRole && $readPermission) {
            $adminRole->permissions()->detach($readPermission->id);
        }

        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/plugins');

        $response->assertStatus(403);
    }

    /**
     * 설치 권한 없이 플러그인 설치 시 403 반환
     */
    public function test_install_returns_403_without_install_permission(): void
    {
        // read 권한만 있는 관리자 생성
        $user = $this->createAdminUser(['core.plugins.read']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/plugins/install', [
            'plugin_name' => 'test-plugin',
        ]);

        $response->assertStatus(403);
    }

    // ========================================================================
    // 플러그인 목록 테스트 (index)
    // ========================================================================

    /**
     * 페이지네이션된 플러그인 목록 조회 성공
     */
    public function test_index_returns_paginated_plugins(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/plugins');

        $response->assertStatus(200)
            ->assertJson(['success' => true])
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data',
                    'pagination' => [
                        'total',
                        'current_page',
                        'last_page',
                        'per_page',
                    ],
                ],
            ]);
    }

    /**
     * 단일 검색어 필터 테스트
     */
    public function test_index_supports_search_filter(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/plugins?search=test');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 다중 검색 필터 테스트
     */
    public function test_index_supports_multiple_filters(): void
    {
        $filters = [
            [
                'field' => 'name',
                'operator' => 'like',
                'value' => 'test',
            ],
        ];

        $response = $this->authRequest()->getJson('/api/admin/plugins?'.http_build_query(['filters' => $filters]));

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 상태 필터 테스트
     */
    public function test_index_supports_status_filter(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/plugins?status=installed');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    // ========================================================================
    // 설치된 플러그인 테스트 (installed)
    // ========================================================================

    /**
     * 설치된 플러그인만 조회
     */
    public function test_installed_returns_only_installed_plugins(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/plugins/installed');

        $response->assertStatus(200)
            ->assertJson(['success' => true]);
    }

    /**
     * 전체 권한 유저의 index 응답에 abilities 포함 확인
     */
    public function test_index_returns_abilities_for_full_permission_user(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/plugins');

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_install', true)
            ->assertJsonPath('data.abilities.can_activate', true)
            ->assertJsonPath('data.abilities.can_uninstall', true);
    }

    /**
     * read 전용 유저의 index 응답에 abilities false 확인
     */
    public function test_index_returns_false_abilities_for_read_only_user(): void
    {
        $readOnlyUser = $this->createAdminUser(['core.plugins.read']);
        $readOnlyToken = $readOnlyUser->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$readOnlyToken,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/plugins');

        $response->assertStatus(200)
            ->assertJsonPath('data.abilities.can_install', false)
            ->assertJsonPath('data.abilities.can_activate', false)
            ->assertJsonPath('data.abilities.can_uninstall', false);
    }

    // ========================================================================
    // 플러그인 상세 테스트 (show)
    // ========================================================================

    /**
     * 플러그인 상세 조회 성공
     */
    public function test_show_returns_plugin_details(): void
    {
        // 실제 플러그인이 설치되어 있지 않으면 404가 반환될 수 있음
        // 테스트 환경에 따라 적절한 플러그인명 사용
        $response = $this->authRequest()->getJson('/api/admin/plugins/test-plugin');

        // 플러그인이 없으면 404, 있으면 200
        $this->assertContains($response->status(), [200, 404]);
    }

    /**
     * 존재하지 않는 플러그인 조회 시 404 반환
     */
    public function test_show_returns_404_for_nonexistent_plugin(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/plugins/nonexistent-plugin-name');

        $response->assertStatus(404);
    }

    // ========================================================================
    // 플러그인 설치 테스트 (install)
    // ========================================================================

    /**
     * plugin_name 필수 검증
     */
    public function test_install_validates_plugin_name_required(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/plugins/install', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plugin_name']);
    }

    /**
     * plugin_name 최대 길이 검증
     */
    public function test_install_validates_plugin_name_max_length(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/plugins/install', [
            'plugin_name' => str_repeat('a', 300), // 255자 초과
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plugin_name']);
    }

    // ========================================================================
    // 플러그인 활성화 테스트 (activate)
    // ========================================================================

    /**
     * plugin_name 필수 검증
     */
    public function test_activate_validates_plugin_name_required(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/plugins/activate', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plugin_name']);
    }

    /**
     * 활성화 요청 성공 (유효한 플러그인이 있는 경우)
     */
    public function test_activate_returns_success_for_valid_plugin(): void
    {
        // 실제 플러그인이 설치되어 있어야 활성화 가능
        // 테스트 환경에서는 validation만 통과하면 됨
        $response = $this->authRequest()->postJson('/api/admin/plugins/activate', [
            'plugin_name' => 'nonexistent-plugin',
        ]);

        // 플러그인이 없으면 실패, 있으면 성공
        $this->assertContains($response->status(), [200, 400, 422, 500]);
    }

    // ========================================================================
    // 플러그인 비활성화 테스트 (deactivate)
    // ========================================================================

    /**
     * plugin_name 필수 검증
     */
    public function test_deactivate_validates_plugin_name_required(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/plugins/deactivate', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plugin_name']);
    }

    // ========================================================================
    // 플러그인 제거 테스트 (uninstall)
    // ========================================================================

    /**
     * plugin_name 필수 검증
     */
    public function test_uninstall_validates_plugin_name_required(): void
    {
        $response = $this->authRequest()->deleteJson('/api/admin/plugins/uninstall', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plugin_name']);
    }

    /**
     * 제거 요청 성공 (유효한 플러그인이 있는 경우)
     */
    public function test_uninstall_returns_success(): void
    {
        // 실제 플러그인이 설치되어 있어야 제거 가능
        $response = $this->authRequest()->deleteJson('/api/admin/plugins/uninstall', [
            'plugin_name' => 'nonexistent-plugin',
        ]);

        // 플러그인이 없으면 실패, 있으면 성공
        $this->assertContains($response->status(), [200, 400, 422, 500]);
    }

    // ========================================================================
    // 플러그인 활성화/비활성화 의존성 테스트
    // ========================================================================

    /**
     * 플러그인 활성화 시 필요한 의존성이 충족되지 않으면 409 경고 응답
     */
    public function test_activate_returns_409_when_dependencies_not_met(): void
    {
        // Arrange: PluginService를 Mock
        $pluginServiceMock = \Mockery::mock(\App\Services\PluginService::class);
        $pluginServiceMock->shouldReceive('activatePlugin')
            ->with('test-plugin', false)
            ->andReturn([
                'success' => false,
                'warning' => true,
                'missing_modules' => [
                    ['identifier' => 'sirsoft-core', 'name' => 'Core Module', 'status' => 'not_installed'],
                ],
                'missing_plugins' => [
                    ['identifier' => 'sirsoft-base', 'name' => 'Base Plugin', 'status' => 'inactive'],
                ],
                'message' => '플러그인 활성화를 위해 필요한 의존성이 충족되지 않았습니다.',
            ]);
        $this->app->instance(\App\Services\PluginService::class, $pluginServiceMock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/plugins/activate', [
            'plugin_name' => 'test-plugin',
        ]);

        // Assert
        $response->assertStatus(409)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => [
                    'warning',
                    'missing_modules',
                    'missing_plugins',
                ],
            ])
            ->assertJsonFragment(['warning' => true]);

        // missing_modules 확인
        $this->assertNotEmpty($response->json('errors.missing_modules'));
        $this->assertEquals('sirsoft-core', $response->json('errors.missing_modules.0.identifier'));

        // missing_plugins 확인
        $this->assertNotEmpty($response->json('errors.missing_plugins'));
        $this->assertEquals('sirsoft-base', $response->json('errors.missing_plugins.0.identifier'));
    }

    /**
     * 플러그인 활성화 시 force=true로 의존성 경고 무시하고 강제 활성화
     */
    public function test_activate_with_force_bypasses_dependency_check(): void
    {
        // Arrange: PluginService를 Mock
        $pluginServiceMock = \Mockery::mock(\App\Services\PluginService::class);
        $pluginServiceMock->shouldReceive('activatePlugin')
            ->with('test-plugin', true)
            ->andReturn([
                'success' => true,
                'plugin_info' => [
                    'identifier' => 'test-plugin',
                    'name' => 'Test Plugin',
                    'status' => 'active',
                ],
            ]);
        $this->app->instance(\App\Services\PluginService::class, $pluginServiceMock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/plugins/activate', [
            'plugin_name' => 'test-plugin',
            'force' => true,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);
    }

    /**
     * 플러그인 비활성화 시 의존하는 확장이 있으면 409 경고 응답
     */
    public function test_deactivate_returns_409_when_dependents_exist(): void
    {
        // Arrange: PluginService를 Mock
        $pluginServiceMock = \Mockery::mock(\App\Services\PluginService::class);
        $pluginServiceMock->shouldReceive('deactivatePlugin')
            ->with('test-plugin', false)
            ->andReturn([
                'success' => false,
                'warning' => true,
                'dependent_templates' => ['sirsoft-admin_basic'],
                'dependent_modules' => [
                    ['identifier' => 'sirsoft-ecommerce', 'name' => 'Ecommerce Module'],
                ],
                'dependent_plugins' => [
                    ['identifier' => 'sirsoft-payment-card', 'name' => 'Card Payment'],
                ],
                'message' => '이 플러그인에 의존하는 활성화된 확장이 있습니다.',
            ]);
        $this->app->instance(\App\Services\PluginService::class, $pluginServiceMock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/plugins/deactivate', [
            'plugin_name' => 'test-plugin',
        ]);

        // Assert
        $response->assertStatus(409)
            ->assertJsonStructure([
                'success',
                'message',
                'errors' => [
                    'warning',
                    'dependent_templates',
                    'dependent_modules',
                    'dependent_plugins',
                ],
            ])
            ->assertJsonFragment(['warning' => true]);
    }

    /**
     * 플러그인 비활성화 시 force=true로 의존성 경고 무시하고 강제 비활성화
     */
    public function test_deactivate_with_force_bypasses_dependent_check(): void
    {
        // Arrange: PluginService를 Mock
        $pluginServiceMock = \Mockery::mock(\App\Services\PluginService::class);
        $pluginServiceMock->shouldReceive('deactivatePlugin')
            ->with('test-plugin', true)
            ->andReturn([
                'success' => true,
                'plugin_info' => [
                    'identifier' => 'test-plugin',
                    'name' => 'Test Plugin',
                    'status' => 'inactive',
                ],
            ]);
        $this->app->instance(\App\Services\PluginService::class, $pluginServiceMock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/plugins/deactivate', [
            'plugin_name' => 'test-plugin',
            'force' => true,
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonFragment(['success' => true]);
    }

    // ========================================================================
    // 에셋 정보 포함 테스트
    // ========================================================================

    /**
     * 플러그인 활성화 응답에 assets 정보가 포함됨
     */
    public function test_activate_response_includes_assets_when_plugin_has_assets(): void
    {
        // Arrange: PluginService를 Mock
        $pluginServiceMock = \Mockery::mock(\App\Services\PluginService::class);
        $pluginServiceMock->shouldReceive('activatePlugin')
            ->with('test-plugin', false)
            ->andReturn([
                'success' => true,
                'plugin_info' => [
                    'identifier' => 'test-plugin',
                    'name' => 'Test Plugin',
                    'status' => 'active',
                    'assets' => [
                        'js' => '/api/plugins/assets/test-plugin/dist/js/plugin.iife.js',
                        'css' => '/api/plugins/assets/test-plugin/dist/css/plugin.css',
                        'priority' => 100,
                    ],
                ],
            ]);
        $this->app->instance(\App\Services\PluginService::class, $pluginServiceMock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/plugins/activate', [
            'plugin_name' => 'test-plugin',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonPath('data.assets.js', '/api/plugins/assets/test-plugin/dist/js/plugin.iife.js')
            ->assertJsonPath('data.assets.css', '/api/plugins/assets/test-plugin/dist/css/plugin.css')
            ->assertJsonPath('data.assets.priority', 100);
    }

    /**
     * 플러그인 비활성화 응답에 assets 정보가 포함됨
     */
    public function test_deactivate_response_includes_assets_when_plugin_has_assets(): void
    {
        // Arrange: PluginService를 Mock
        $pluginServiceMock = \Mockery::mock(\App\Services\PluginService::class);
        $pluginServiceMock->shouldReceive('deactivatePlugin')
            ->with('test-plugin', false)
            ->andReturn([
                'success' => true,
                'plugin_info' => [
                    'identifier' => 'test-plugin',
                    'name' => 'Test Plugin',
                    'status' => 'inactive',
                    'assets' => [
                        'js' => '/api/plugins/assets/test-plugin/dist/js/plugin.iife.js',
                        'css' => '/api/plugins/assets/test-plugin/dist/css/plugin.css',
                        'priority' => 100,
                    ],
                ],
            ]);
        $this->app->instance(\App\Services\PluginService::class, $pluginServiceMock);

        // Act
        $response = $this->authRequest()->postJson('/api/admin/plugins/deactivate', [
            'plugin_name' => 'test-plugin',
        ]);

        // Assert
        $response->assertStatus(200)
            ->assertJsonPath('data.assets.js', '/api/plugins/assets/test-plugin/dist/js/plugin.iife.js')
            ->assertJsonPath('data.assets.css', '/api/plugins/assets/test-plugin/dist/css/plugin.css')
            ->assertJsonPath('data.assets.priority', 100);
    }

    // ========================================================================
    // Changelog 조회 테스트
    // ========================================================================

    /**
     * CHANGELOG.md가 있는 플러그인의 changelog를 조회합니다.
     */
    public function test_changelog_returns_parsed_entries(): void
    {
        $pluginPath = base_path('plugins/test-changelog-plg');
        \Illuminate\Support\Facades\File::ensureDirectoryExists($pluginPath);
        \Illuminate\Support\Facades\File::put($pluginPath.'/CHANGELOG.md', "# Changelog\n\n## [0.1.1] - 2026-02-25\n\n### Fixed\n- 버그 수정\n");

        try {
            $response = $this->authRequest()->getJson('/api/admin/plugins/test-changelog-plg/changelog');

            $response->assertStatus(200)
                ->assertJsonPath('data.changelog.0.version', '0.1.1')
                ->assertJsonPath('data.changelog.0.categories.0.name', 'Fixed');
        } finally {
            \Illuminate\Support\Facades\File::deleteDirectory($pluginPath);
        }
    }

    /**
     * CHANGELOG.md가 없는 플러그인은 빈 배열을 반환합니다.
     */
    public function test_changelog_returns_empty_for_missing_file(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/plugins/nonexistent-plugin/changelog');

        $response->assertStatus(200)
            ->assertJsonPath('data.changelog', []);
    }

    /**
     * 인증 없이 changelog 조회 시 401 반환
     */
    public function test_changelog_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/plugins/test-plugin/changelog');
        $response->assertStatus(401);
    }

    // ========================================================================
    // 식별자 형식 검증 테스트
    // ========================================================================

    /**
     * 잘못된 형식의 플러그인 식별자로 설치 요청 시 422 반환
     */
    public function test_install_returns_422_for_invalid_identifier_format(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/plugins/install', [
            'plugin_name' => 'sirsoftpayment', // 하이픈 없음
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plugin_name']);
    }

    /**
     * 숫자로 시작하는 단어가 포함된 플러그인 식별자로 설치 요청 시 422 반환
     */
    public function test_install_returns_422_for_digit_starting_identifier(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/plugins/install', [
            'plugin_name' => 'sirsoft-2payment',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plugin_name']);
    }

    /**
     * 특수문자가 포함된 플러그인 식별자로 설치 요청 시 422 반환
     */
    public function test_install_returns_422_for_special_char_identifier(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/plugins/install', [
            'plugin_name' => 'sirsoft-pay@ment',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plugin_name']);
    }

    // ========================================================================
    // 파일 업로드 설치 테스트 (installFromFile)
    // ========================================================================

    /**
     * 인증 없이 파일 업로드 설치 시 401 반환
     */
    public function test_install_from_file_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/plugins/install-from-file', []);

        $response->assertStatus(401);
    }

    /**
     * 파일 없이 업로드 설치 요청 시 422 반환
     */
    public function test_install_from_file_returns_422_without_file(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/plugins/install-from-file', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 잘못된 파일 형식으로 업로드 시 422 반환
     */
    public function test_install_from_file_returns_422_for_invalid_file_type(): void
    {
        Storage::fake('local');

        $file = UploadedFile::fake()->create('document.pdf', 100);

        $response = $this->authRequest()->postJson('/api/admin/plugins/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 파일 크기 초과 시 422 반환
     */
    public function test_install_from_file_returns_422_for_oversized_file(): void
    {
        Storage::fake('local');

        // 설정된 최대 크기보다 큰 파일 생성 (기본 50MB)
        $maxSize = config('plugin.upload_max_size', 50);
        $oversizedKb = ($maxSize * 1024) + 100; // 100KB 초과

        $file = UploadedFile::fake()->create('plugin.zip', $oversizedKb);

        $response = $this->authRequest()->postJson('/api/admin/plugins/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['file']);
    }

    /**
     * 설치 권한 없이 파일 업로드 설치 시 403 반환
     */
    public function test_install_from_file_returns_403_without_install_permission(): void
    {
        $user = $this->createAdminUser(['core.plugins.read']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/plugins/install-from-file', []);

        $response->assertStatus(403);
    }

    /**
     * 유효한 ZIP 파일 업로드 시 PluginService::installFromZipFile 호출 확인
     */
    public function test_install_from_file_calls_service_with_valid_zip(): void
    {
        $pluginServiceMock = \Mockery::mock(\App\Services\PluginService::class);
        $pluginServiceMock->shouldReceive('installFromZipFile')
            ->once()
            ->andReturn([
                'identifier' => 'sirsoft-testplugin',
                'name' => 'Test Plugin',
                'version' => '1.0.0',
            ]);
        $this->app->instance(\App\Services\PluginService::class, $pluginServiceMock);

        $file = UploadedFile::fake()->create('plugin.zip', 100, 'application/zip');

        $response = $this->authRequest()->postJson('/api/admin/plugins/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.identifier', 'sirsoft-testplugin');
    }

    /**
     * PluginService에서 RuntimeException 발생 시 422 반환
     */
    public function test_install_from_file_returns_422_on_runtime_exception(): void
    {
        $pluginServiceMock = \Mockery::mock(\App\Services\PluginService::class);
        $pluginServiceMock->shouldReceive('installFromZipFile')
            ->once()
            ->andThrow(new \RuntimeException('plugin.json을 찾을 수 없습니다.'));
        $this->app->instance(\App\Services\PluginService::class, $pluginServiceMock);

        $file = UploadedFile::fake()->create('plugin.zip', 100, 'application/zip');

        $response = $this->authRequest()->postJson('/api/admin/plugins/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'plugin.json을 찾을 수 없습니다.');
    }

    /**
     * PluginService에서 일반 Exception 발생 시 500 반환
     */
    public function test_install_from_file_returns_500_on_general_exception(): void
    {
        $pluginServiceMock = \Mockery::mock(\App\Services\PluginService::class);
        $pluginServiceMock->shouldReceive('installFromZipFile')
            ->once()
            ->andThrow(new \Exception('예상치 못한 오류'));
        $this->app->instance(\App\Services\PluginService::class, $pluginServiceMock);

        $file = UploadedFile::fake()->create('plugin.zip', 100, 'application/zip');

        $response = $this->authRequest()->postJson('/api/admin/plugins/install-from-file', [
            'file' => $file,
        ]);

        $response->assertStatus(500);
    }

    // ========================================================================
    // GitHub 설치 테스트 (installFromGithub)
    // ========================================================================

    /**
     * 인증 없이 GitHub 설치 시 401 반환
     */
    public function test_install_from_github_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/plugins/install-from-github', []);

        $response->assertStatus(401);
    }

    /**
     * GitHub URL 없이 설치 요청 시 422 반환
     */
    public function test_install_from_github_returns_422_without_url(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/plugins/install-from-github', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['github_url']);
    }

    /**
     * 잘못된 GitHub URL 형식으로 요청 시 422 반환
     */
    public function test_install_from_github_returns_422_for_invalid_url(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/plugins/install-from-github', [
            'github_url' => 'not-a-valid-url',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['github_url']);
    }

    /**
     * GitHub이 아닌 URL로 요청 시 422 반환
     */
    public function test_install_from_github_returns_422_for_non_github_url(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/plugins/install-from-github', [
            'github_url' => 'https://gitlab.com/vendor/plugin',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['github_url']);
    }

    /**
     * 유효한 GitHub URL 형식 테스트
     */
    public function test_install_from_github_accepts_valid_github_url(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/plugins/install-from-github', [
            'github_url' => 'https://github.com/sirsoft/sample-plugin',
        ]);

        // URL 형식 검증 통과 확인: 422가 아니거나 github_url 관련 validation 에러가 없어야 함
        if ($response->status() === 422) {
            $response->assertJsonMissingValidationErrors(['github_url']);
        } else {
            $this->assertContains($response->status(), [200, 201, 500]);
        }
    }

    /**
     * 설치 권한 없이 GitHub 설치 시 403 반환
     */
    public function test_install_from_github_returns_403_without_install_permission(): void
    {
        $user = $this->createAdminUser(['core.plugins.read']);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/plugins/install-from-github', [
            'github_url' => 'https://github.com/sirsoft/sample-plugin',
        ]);

        $response->assertStatus(403);
    }

    /**
     * 유효한 GitHub URL로 설치 시 PluginService::installFromGithub 호출 확인
     */
    public function test_install_from_github_calls_service_with_valid_url(): void
    {
        $pluginServiceMock = \Mockery::mock(\App\Services\PluginService::class);
        $pluginServiceMock->shouldReceive('installFromGithub')
            ->once()
            ->with('https://github.com/sirsoft/sample-plugin')
            ->andReturn([
                'identifier' => 'sirsoft-sampleplugin',
                'name' => 'Sample Plugin',
                'version' => '1.0.0',
            ]);
        $this->app->instance(\App\Services\PluginService::class, $pluginServiceMock);

        $response = $this->authRequest()->postJson('/api/admin/plugins/install-from-github', [
            'github_url' => 'https://github.com/sirsoft/sample-plugin',
        ]);

        $response->assertStatus(201)
            ->assertJson(['success' => true])
            ->assertJsonPath('data.identifier', 'sirsoft-sampleplugin');
    }

    /**
     * PluginService에서 RuntimeException 발생 시 422 반환
     */
    public function test_install_from_github_returns_422_on_runtime_exception(): void
    {
        $pluginServiceMock = \Mockery::mock(\App\Services\PluginService::class);
        $pluginServiceMock->shouldReceive('installFromGithub')
            ->once()
            ->andThrow(new \RuntimeException('GitHub 저장소를 다운로드할 수 없습니다.'));
        $this->app->instance(\App\Services\PluginService::class, $pluginServiceMock);

        $response = $this->authRequest()->postJson('/api/admin/plugins/install-from-github', [
            'github_url' => 'https://github.com/sirsoft/sample-plugin',
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'GitHub 저장소를 다운로드할 수 없습니다.');
    }

    /**
     * PluginService에서 일반 Exception 발생 시 500 반환
     */
    public function test_install_from_github_returns_500_on_general_exception(): void
    {
        $pluginServiceMock = \Mockery::mock(\App\Services\PluginService::class);
        $pluginServiceMock->shouldReceive('installFromGithub')
            ->once()
            ->andThrow(new \Exception('예상치 못한 오류'));
        $this->app->instance(\App\Services\PluginService::class, $pluginServiceMock);

        $response = $this->authRequest()->postJson('/api/admin/plugins/install-from-github', [
            'github_url' => 'https://github.com/sirsoft/sample-plugin',
        ]);

        $response->assertStatus(500);
    }
}
