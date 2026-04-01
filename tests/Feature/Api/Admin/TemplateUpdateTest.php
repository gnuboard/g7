<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

/**
 * 템플릿 업데이트 API 엔드포인트 테스트
 *
 * checkUpdates, performUpdate, checkModifiedLayouts 엔드포인트를 검증합니다.
 */
class TemplateUpdateTest extends TestCase
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
    private function createAdminUser(array $permissions = ['core.templates.read', 'core.templates.install', 'core.templates.activate', 'core.templates.uninstall']): User
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
        $response = $this->postJson('/api/admin/templates/check-updates');

        $response->assertStatus(401);
    }

    /**
     * 업데이트 확인 성공 시 결과 반환
     */
    public function test_check_updates_returns_update_results(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('checkForUpdates')
            ->once()
            ->andReturn([
                'updated_count' => 1,
                'details' => [
                    'test-template' => [
                        'update_available' => true,
                        'update_source' => 'bundled',
                        'latest_version' => '2.0.0',
                        'current_version' => '1.0.0',
                    ],
                ],
            ]);
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/templates/check-updates');

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
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('checkForUpdates')
            ->once()
            ->andReturn([
                'updated_count' => 0,
                'details' => [],
            ]);
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/templates/check-updates');

        $response->assertStatus(200)
            ->assertJsonPath('data.updated_count', 0);
    }

    /**
     * 업데이트 확인 실패 시 422 반환
     */
    public function test_check_updates_returns_422_on_validation_error(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('checkForUpdates')
            ->once()
            ->andThrow(
                ValidationException::withMessages([
                    'templates' => [__('templates.check_updates_failed', ['error' => 'Test error'])],
                ])
            );
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/templates/check-updates');

        $response->assertStatus(422);
    }

    /**
     * 권한 없이 업데이트 확인 시 403 반환
     */
    public function test_check_updates_returns_403_without_permission(): void
    {
        $userWithoutPermission = $this->createAdminUser(['core.templates.read']);
        $token = $userWithoutPermission->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/templates/check-updates');

        $response->assertStatus(403);
    }

    // ========================================================================
    // performUpdate 엔드포인트 테스트
    // ========================================================================

    /**
     * 인증 없이 템플릿 업데이트 시 401 반환
     */
    public function test_perform_update_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/templates/test-template/update');

        $response->assertStatus(401);
    }

    /**
     * 템플릿 업데이트 성공 시 200과 템플릿 정보 반환
     */
    public function test_perform_update_returns_template_info_on_success(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('test-template', 'overwrite')
            ->once()
            ->andReturn([
                'success' => true,
                'from_version' => '1.0.0',
                'to_version' => '2.0.0',
                'template_info' => [
                    'identifier' => 'test-template',
                    'vendor' => 'test',
                    'name' => ['ko' => '테스트 템플릿', 'en' => 'Test Template'],
                    'version' => '2.0.0',
                    'type' => 'admin',
                    'description' => ['ko' => '테스트용 템플릿', 'en' => 'Template for testing'],
                    'dependencies' => [],
                    'status' => 'active',
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
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/templates/test-template/update', [
            'layout_strategy' => 'overwrite',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.identifier', 'test-template')
            ->assertJsonPath('data.version', '2.0.0')
            ->assertJsonPath('data.type', 'admin')
            ->assertJsonPath('data.update_available', false)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'identifier',
                    'vendor',
                    'name',
                    'version',
                    'type',
                    'status',
                    'description',
                    'dependencies',
                    'dependencies_met',
                    'update_available',
                    'update_source',
                    'latest_version',
                    'file_version',
                    'is_pending',
                    'is_bundled',
                ],
            ]);
    }

    /**
     * 템플릿 업데이트 시 TemplateResource의 업데이트 관련 필드가 포함되는지 확인
     */
    public function test_perform_update_response_includes_update_fields(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('test-template', 'overwrite')
            ->once()
            ->andReturn([
                'success' => true,
                'from_version' => '1.0.0',
                'to_version' => '2.0.0',
                'template_info' => [
                    'identifier' => 'test-template',
                    'vendor' => 'test',
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'version' => '2.0.0',
                    'type' => 'user',
                    'description' => ['ko' => '테스트', 'en' => 'Test'],
                    'dependencies' => [],
                    'status' => 'active',
                    'update_available' => false,
                    'update_source' => null,
                    'latest_version' => '2.0.0',
                    'file_version' => '2.0.0',
                    'github_url' => 'https://github.com/test/test-template',
                    'github_changelog_url' => 'https://github.com/test/test-template/releases',
                    'is_pending' => false,
                    'is_bundled' => true,
                ],
            ]);
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/templates/test-template/update', [
            'layout_strategy' => 'overwrite',
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.github_url', 'https://github.com/test/test-template')
            ->assertJsonPath('data.github_changelog_url', 'https://github.com/test/test-template/releases')
            ->assertJsonPath('data.is_bundled', true)
            ->assertJsonPath('data.is_pending', false)
            ->assertJsonPath('data.file_version', '2.0.0');
    }

    /**
     * 존재하지 않는 템플릿 업데이트 시 422 반환
     */
    public function test_perform_update_returns_422_for_nonexistent_template(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('nonexistent-template', 'overwrite')
            ->once()
            ->andThrow(
                ValidationException::withMessages([
                    'template_name' => [__('templates.errors.update_failed', ['template' => 'nonexistent-template', 'error' => 'Template not found'])],
                ])
            );
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/templates/nonexistent-template/update');

        $response->assertStatus(422);
    }

    /**
     * 템플릿 업데이트 중 서버 에러 시 500 반환
     */
    public function test_perform_update_returns_500_on_server_error(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('test-template', 'overwrite')
            ->once()
            ->andThrow(new \RuntimeException('Unexpected error'));
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/templates/test-template/update');

        $response->assertStatus(500);
    }

    /**
     * 권한 없이 템플릿 업데이트 시 403 반환
     */
    public function test_perform_update_returns_403_without_permission(): void
    {
        $userWithoutPermission = $this->createAdminUser(['core.templates.read']);
        $token = $userWithoutPermission->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/templates/test-template/update');

        $response->assertStatus(403);
    }

    /**
     * performUpdate가 layout_strategy 파라미터를 서비스에 전달하는지 확인
     */
    public function test_perform_update_sends_layout_strategy_to_service(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('test-template', 'keep')
            ->once()
            ->andReturn([
                'success' => true,
                'from_version' => '1.0.0',
                'to_version' => '2.0.0',
                'template_info' => [
                    'identifier' => 'test-template',
                    'vendor' => 'test',
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'version' => '2.0.0',
                    'type' => 'admin',
                    'description' => ['ko' => '테스트', 'en' => 'Test'],
                    'dependencies' => [],
                    'status' => 'active',
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
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/templates/test-template/update', [
            'layout_strategy' => 'keep',
        ]);

        $response->assertStatus(200);
    }

    // ========================================================================
    // checkModifiedLayouts 엔드포인트 테스트
    // ========================================================================

    /**
     * 인증 없이 수정된 레이아웃 확인 시 401 반환
     */
    public function test_check_modified_layouts_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/templates/test-template/check-modified-layouts');

        $response->assertStatus(401);
    }

    /**
     * 수정된 레이아웃 확인 성공 시 결과 반환
     */
    public function test_check_modified_layouts_returns_result(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('checkModifiedLayouts')
            ->with('test-template')
            ->once()
            ->andReturn([
                'has_modified' => true,
                'modified_layouts' => [
                    [
                        'id' => 1,
                        'name' => 'admin_dashboard',
                        'updated_at' => '2026-02-23 10:00:00',
                    ],
                ],
            ]);
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->getJson('/api/admin/templates/test-template/check-modified-layouts');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.has_modified', true)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'has_modified',
                    'modified_layouts',
                ],
            ]);
    }

    /**
     * 권한 없이 수정된 레이아웃 확인 시 403 반환
     */
    public function test_check_modified_layouts_returns_403_without_permission(): void
    {
        $userWithoutPermission = $this->createAdminUser([]);
        $token = $userWithoutPermission->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/templates/test-template/check-modified-layouts');

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
            \Illuminate\Support\Facades\Route::has('api.admin.templates.check-updates'),
            'Route api.admin.templates.check-updates should exist'
        );
    }

    /**
     * update 라우트가 올바른 이름을 가지는지 확인
     */
    public function test_update_route_name_exists(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has('api.admin.templates.update'),
            'Route api.admin.templates.update should exist'
        );
    }

    /**
     * check-modified-layouts 라우트가 올바른 이름을 가지는지 확인
     */
    public function test_check_modified_layouts_route_name_exists(): void
    {
        $this->assertTrue(
            \Illuminate\Support\Facades\Route::has('api.admin.templates.check-modified-layouts'),
            'Route api.admin.templates.check-modified-layouts should exist'
        );
    }
}
