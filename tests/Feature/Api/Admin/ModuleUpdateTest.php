<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

/**
 * 모듈 업데이트 API 엔드포인트 테스트
 *
 * checkUpdates, performUpdate 엔드포인트를 검증합니다.
 */
class ModuleUpdateTest extends TestCase
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
     * @return User 생성된 관리자 사용자
     */
    private function createAdminUser(array $permissions = ['core.modules.read', 'core.modules.install', 'core.modules.activate', 'core.modules.uninstall']): User
    {
        $user = User::factory()->create();

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

        $roleIdentifier = 'admin_test_'.uniqid();
        $testRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

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

        $testRole->permissions()->sync($permissionIds);

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
    // checkUpdates 엔드포인트 테스트
    // ========================================================================

    /**
     * 인증 없이 업데이트 확인 시 401 반환
     */
    public function test_check_updates_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/modules/check-updates');

        $response->assertStatus(401);
    }

    /**
     * 업데이트 확인 성공 시 결과 반환
     */
    public function test_check_updates_returns_update_results(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('checkForUpdates')
            ->once()
            ->andReturn([
                'updated_count' => 1,
                'details' => [
                    'test-module' => [
                        'update_available' => true,
                        'update_source' => 'bundled',
                        'latest_version' => '2.0.0',
                        'current_version' => '1.0.0',
                    ],
                ],
            ]);
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/check-updates');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.updated_count', 1)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'updated_count',
                    'details',
                ],
            ]);
    }

    /**
     * 업데이트 없을 때 updated_count = 0 반환
     */
    public function test_check_updates_returns_zero_when_no_updates(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('checkForUpdates')
            ->once()
            ->andReturn([
                'updated_count' => 0,
                'details' => [],
            ]);
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/check-updates');

        $response->assertStatus(200)
            ->assertJsonPath('data.updated_count', 0);
    }

    /**
     * 업데이트 확인 실패 시 422 반환
     */
    public function test_check_updates_returns_422_on_validation_error(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('checkForUpdates')
            ->once()
            ->andThrow(
                ValidationException::withMessages([
                    'modules' => [__('modules.check_updates_failed', ['error' => 'Test error'])],
                ])
            );
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/check-updates');

        $response->assertStatus(422);
    }

    /**
     * 권한 없이 업데이트 확인 시 403 반환
     */
    public function test_check_updates_returns_403_without_permission(): void
    {
        $userWithoutPermission = $this->createAdminUser(['core.modules.read']);
        $token = $userWithoutPermission->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/modules/check-updates');

        $response->assertStatus(403);
    }

    // ========================================================================
    // performUpdate 엔드포인트 테스트
    // ========================================================================

    /**
     * 인증 없이 모듈 업데이트 시 401 반환
     */
    public function test_perform_update_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/modules/test-module/update');

        $response->assertStatus(401);
    }

    /**
     * 모듈 업데이트 성공 시 200과 모듈 정보 반환
     */
    public function test_perform_update_returns_module_info_on_success(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('test-module', Mockery::any(), 'overwrite')
            ->once()
            ->andReturn([
                'success' => true,
                'from_version' => '1.0.0',
                'to_version' => '2.0.0',
                'module_info' => [
                    'identifier' => 'test-module',
                    'vendor' => 'test',
                    'name' => ['ko' => '테스트 모듈', 'en' => 'Test Module'],
                    'version' => '2.0.0',
                    'description' => ['ko' => '테스트용', 'en' => 'For testing'],
                    'dependencies' => [],
                    'status' => 'active',
                    'assets' => null,
                    'update_available' => false,
                    'update_source' => null,
                    'latest_version' => null,
                    'file_version' => null,
                    'github_url' => null,
                    'github_changelog_url' => null,
                    'is_pending' => false,
                    'is_bundled' => false,
                ],
            ]);
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/test-module/update');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.identifier', 'test-module')
            ->assertJsonPath('data.version', '2.0.0')
            ->assertJsonPath('data.update_available', false)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'identifier',
                    'vendor',
                    'name',
                    'version',
                    'status',
                    'update_available',
                    'update_source',
                    'latest_version',
                    'is_pending',
                    'is_bundled',
                ],
            ]);
    }

    /**
     * 모듈 업데이트 시 ModuleResource의 업데이트 관련 필드가 포함되는지 확인
     */
    public function test_perform_update_response_includes_update_fields(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('test-module', Mockery::any(), 'overwrite')
            ->once()
            ->andReturn([
                'success' => true,
                'from_version' => '1.0.0',
                'to_version' => '2.0.0',
                'module_info' => [
                    'identifier' => 'test-module',
                    'vendor' => 'test',
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'version' => '2.0.0',
                    'description' => ['ko' => '테스트', 'en' => 'Test'],
                    'dependencies' => [],
                    'status' => 'active',
                    'assets' => null,
                    'update_available' => false,
                    'update_source' => null,
                    'latest_version' => '2.0.0',
                    'file_version' => '2.0.0',
                    'github_url' => 'https://github.com/test/test-module',
                    'github_changelog_url' => 'https://github.com/test/test-module/releases',
                    'is_pending' => false,
                    'is_bundled' => true,
                ],
            ]);
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/test-module/update');

        $response->assertStatus(200)
            ->assertJsonPath('data.github_url', 'https://github.com/test/test-module')
            ->assertJsonPath('data.github_changelog_url', 'https://github.com/test/test-module/releases')
            ->assertJsonPath('data.is_bundled', true)
            ->assertJsonPath('data.is_pending', false)
            ->assertJsonPath('data.file_version', '2.0.0');
    }

    /**
     * 존재하지 않는 모듈 업데이트 시 422 반환
     */
    public function test_perform_update_returns_422_for_nonexistent_module(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('nonexistent-module', Mockery::any(), 'overwrite')
            ->once()
            ->andThrow(
                ValidationException::withMessages([
                    'module_name' => [__('modules.errors.update_failed', ['module' => 'nonexistent-module', 'error' => 'Module not found'])],
                ])
            );
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/nonexistent-module/update');

        $response->assertStatus(422);
    }

    /**
     * 모듈 업데이트 중 서버 에러 시 500 반환
     */
    public function test_perform_update_returns_500_on_server_error(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('test-module', Mockery::any(), 'overwrite')
            ->once()
            ->andThrow(new \RuntimeException('Unexpected error'));
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/test-module/update');

        $response->assertStatus(500);
    }

    /**
     * 권한 없이 모듈 업데이트 시 403 반환
     */
    public function test_perform_update_returns_403_without_permission(): void
    {
        $userWithoutPermission = $this->createAdminUser(['core.modules.read']);
        $token = $userWithoutPermission->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/modules/test-module/update');

        $response->assertStatus(403);
    }

    // ========================================================================
    // 라우트 확인 테스트
    // ========================================================================

    /**
     * check-updates 라우트가 올바른 이름을 가지는지 확인
     */
    public function test_check_updates_route_name_exists(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has('api.admin.modules.check-updates'),
            'Route api.admin.modules.check-updates should exist'
        );
    }

    /**
     * update 라우트가 올바른 이름을 가지는지 확인
     */
    public function test_update_route_name_exists(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has('api.admin.modules.update'),
            'Route api.admin.modules.update should exist'
        );
    }

    // ========================================================================
    // layout_strategy 파라미터 전달 검증
    // ========================================================================

    /**
     * performUpdate 가 layout_strategy='keep' 을 Service 에 전달하는지 확인
     */
    public function test_perform_update_sends_layout_strategy_keep_to_service(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('test-module', Mockery::any(), 'keep')
            ->once()
            ->andReturn([
                'success' => true,
                'from_version' => '1.0.0',
                'to_version' => '2.0.0',
                'module_info' => null,
            ]);
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/test-module/update', [
            'layout_strategy' => 'keep',
        ]);

        $response->assertStatus(200);
    }

    /**
     * performUpdate 는 유효하지 않은 layout_strategy 를 거부 (422)
     */
    public function test_perform_update_rejects_invalid_layout_strategy(): void
    {
        $response = $this->authRequest()->postJson('/api/admin/modules/test-module/update', [
            'layout_strategy' => 'garbage',
        ]);

        $response->assertStatus(422);
    }

    // ========================================================================
    // checkModifiedLayouts 엔드포인트 테스트
    // ========================================================================

    /**
     * checkModifiedLayouts 는 Service 결과를 그대로 반환한다
     */
    public function test_check_modified_layouts_returns_result_from_service(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('checkModifiedLayouts')
            ->with('test-module')
            ->once()
            ->andReturn([
                'has_modified_layouts' => true,
                'modified_count' => 2,
                'modified_layouts' => [
                    ['id' => 1, 'name' => 'test-module.home', 'updated_at' => '2026-04-19 10:00:00', 'size_diff' => 128],
                    ['id' => 2, 'name' => 'test-module.list', 'updated_at' => '2026-04-19 10:05:00', 'size_diff' => -64],
                ],
            ]);
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->getJson('/api/admin/modules/test-module/check-modified-layouts');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_modified_layouts', true)
            ->assertJsonPath('data.modified_count', 2);
    }

    /**
     * checkModifiedLayouts 는 인증이 없으면 401 반환
     */
    public function test_check_modified_layouts_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/modules/test-module/check-modified-layouts');

        $response->assertStatus(401);
    }

    /**
     * check-modified-layouts 라우트 네임 확인
     */
    public function test_check_modified_layouts_route_name_exists(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has('api.admin.modules.check-modified-layouts'),
            'Route api.admin.modules.check-modified-layouts should exist'
        );
    }
}
