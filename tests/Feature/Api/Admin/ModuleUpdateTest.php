<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Enums\LayoutSyncMode;
use App\Enums\PermissionType;
use App\Extension\Layout\DTO\LayoutSyncEntry;
use App\Extension\Layout\LayoutSyncReport;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\ModuleService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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

    /**
     * 모듈 권한을 가진 관리자와 인증 토큰을 준비한다.
     *
     * @return void 반환값 없음
     */
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
                    'type' => PermissionType::Admin,
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
     *
     * @return static Bearer 토큰과 JSON Accept 헤더가 설정된 테스트 인스턴스
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
     */
    public function test_check_updates_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/modules/check-updates');

        $response->assertStatus(401);
    }

    /**
     * 업데이트 확인 성공 시 결과 반환
     *
     * @effects update_api_reports_available_updates
     *
     * @return void 반환값 없음
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
     *
     * @effects update_api_reports_available_updates
     *
     * @return void 반환값 없음
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/modules/test-module/update');

        $response->assertStatus(401);
    }

    /**
     * 모듈 업데이트 성공 시 200과 모듈 정보 반환
     *
     * @effects update_api_returns_updated_extension
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_returns_module_info_on_success(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('test-module', Mockery::any(), 'overwrite', false)
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
     *
     * @effects update_api_returns_updated_extension
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_response_includes_update_fields(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('test-module', Mockery::any(), 'overwrite', false)
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_returns_422_for_nonexistent_module(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('nonexistent-module', Mockery::any(), 'overwrite', false)
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_returns_500_on_server_error(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('test-module', Mockery::any(), 'overwrite', false)
            ->once()
            ->andThrow(new \RuntimeException('Unexpected error'));
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/test-module/update');

        $response->assertStatus(500);
    }

    /**
     * 권한 없이 모듈 업데이트 시 403 반환
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
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
     *
     * @effects update_api_routes_are_named
     *
     * @return void 반환값 없음
     */
    public function test_check_updates_route_name_exists(): void
    {
        $this->assertTrue(
            Route::has('api.admin.modules.check-updates'),
            'Route api.admin.modules.check-updates should exist'
        );
    }

    /**
     * update 라우트가 올바른 이름을 가지는지 확인
     *
     * @effects update_api_routes_are_named
     *
     * @return void 반환값 없음
     */
    public function test_update_route_name_exists(): void
    {
        $this->assertTrue(
            Route::has('api.admin.modules.update'),
            'Route api.admin.modules.update should exist'
        );
    }

    // ========================================================================
    // layout_strategy 파라미터 전달 검증
    // ========================================================================

    /**
     * performUpdate 가 layout_strategy='keep' 을 Service 에 전달하는지 확인
     *
     * @effects update_api_passes_layout_strategy
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_sends_layout_strategy_keep_to_service(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('test-module', Mockery::any(), 'keep', false)
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
     *
     * @effects update_api_passes_layout_strategy
     *
     * @return void 반환값 없음
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
     *
     * @effects modified_layouts_check_lists_operator_rows
     *
     * @return void 반환값 없음
     */
    public function test_check_modified_layouts_returns_result_from_service(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        // 컨트롤러가 미존재 식별자를 404 로 구분하려 존재 확인을 먼저 한다 (#495).
        // 이 스텁이 없으면 mock 이 BadMethodCallException 을 던져 500 이 된다.
        $mockService->shouldReceive('getModuleInfo')
            ->with('test-module')
            ->andReturn(['identifier' => 'test-module', 'version' => '1.0.0']);
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
     */
    public function test_check_modified_layouts_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/modules/test-module/check-modified-layouts');

        $response->assertStatus(401);
    }

    /**
     * check-modified-layouts 라우트 네임 확인
     *
     * @effects update_api_routes_are_named
     *
     * @return void 반환값 없음
     */
    public function test_check_modified_layouts_route_name_exists(): void
    {
        $this->assertTrue(
            Route::has('api.admin.modules.check-modified-layouts'),
            'Route api.admin.modules.check-modified-layouts should exist'
        );
    }

    /**
     * keep 업데이트에서 운영자가 고친 화면이 있으면 성공 문구 끝에 레이아웃 요약이 붙고 data.layout_sync 에 상세가 실린다 (#727 D2).
     *
     * @effects update_api_message_summarizes_layout_sync
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_appends_layout_sync_summary_for_keep(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('test-module', Mockery::any(), 'keep', false)
            ->once()
            ->andReturn([
                'success' => true,
                'from_version' => '1.0.0',
                'to_version' => '2.0.0',
                'layout_strategy' => 'keep',
                'layout_sync' => [
                    'strategy' => 'keep',
                    'applied_count' => 2,
                    'conflict_count' => 1,
                    'kept_no_base_count' => 0,
                    'kept_removed_count' => 1,
                    'kept_deleted_by_operator_count' => 1,
                    'kept_name_collision_count' => 0,
                    'kept_invalid_count' => 0,
                    'modified_count' => 3,
                    'entries' => [
                        ['kind' => 'layout', 'template' => 'sirsoft-admin_basic', 'name' => 'test-module.list', 'verdict' => 'merged', 'applied' => [['unit' => 'root > partials/x/_a @ 0', 'kind' => 'partial'], ['unit' => 'item:data_sources/items', 'kind' => 'item']], 'conflicts' => [['unit' => 'root > partials/x/_b @ 0', 'reason' => 'both_changed']]],
                    ],
                ],
                'module_info' => [
                    'identifier' => 'test-module',
                    'vendor' => 'test',
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'version' => '2.0.0',
                    'description' => ['ko' => '테스트용', 'en' => 'For testing'],
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
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/test-module/update', ['layout_strategy' => 'keep']);

        $response->assertStatus(200)
            ->assertJsonPath('data.identifier', 'test-module')
            ->assertJsonPath('data.layout_sync.applied_count', 2)
            ->assertJsonPath('data.layout_sync.entries.0.verdict', 'merged');

        $message = (string) $response->json('message');
        $this->assertStringContainsString('2.0.0', $message);
        $this->assertStringEndsWith(
            __('extensions.layout_sync.summary', ['applied' => 2, 'conflicts' => 1]).' '.__('extensions.layout_sync.skipped', ['deleted' => 1, 'collided' => 0]),
            $message
        );
    }

    /**
     * 운영자 관련 행이 없으면(modified_count 0) 요약을 붙이지 않는다.
     *
     * @effects update_api_message_summarizes_layout_sync
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_has_no_layout_summary_without_operator_rows(): void
    {
        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('test-module', Mockery::any(), 'keep', false)
            ->once()
            ->andReturn([
                'success' => true,
                'from_version' => '1.0.0',
                'to_version' => '2.0.0',
                'layout_sync' => ['strategy' => 'keep', 'applied_count' => 0, 'conflict_count' => 0, 'kept_no_base_count' => 0, 'kept_removed_count' => 0, 'kept_deleted_by_operator_count' => 0, 'kept_name_collision_count' => 0, 'kept_invalid_count' => 0, 'modified_count' => 0, 'entries' => []],
            ]);
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/test-module/update', ['layout_strategy' => 'keep']);

        $response->assertStatus(200);
        $this->assertStringNotContainsString(__('extensions.layout_sync.summary', ['applied' => 0, 'conflicts' => 0]), (string) $response->json('message'));
    }

    /**
     * 비교 기준을 못 찾은 화면(`kept_no_base`)이 있으면 요약 뒤에 「기준 없음」 안내가 붙고 data.layout_sync 에 그 행이 실린다 (#727 §5.8).
     *
     * @effects update_api_message_summarizes_layout_sync, missing_base_keeps_operator_layout_untouched
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_appends_no_base_notice(): void
    {
        $report = new LayoutSyncReport(LayoutSyncMode::Keep);
        $report->add(new LayoutSyncEntry('layout', 'sirsoft-admin_basic', 'test-module.list', 'merged', [['unit' => 'root > partials/x/_a @ 0', 'kind' => 'partial']]));
        $report->add(new LayoutSyncEntry('layout', 'sirsoft-admin_basic', 'test-module.legacy', 'kept_no_base'));

        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('test-module', Mockery::any(), 'keep', false)
            ->once()
            ->andReturn(['success' => true, 'from_version' => '1.0.0', 'to_version' => '2.0.0', 'layout_strategy' => 'keep', 'layout_sync' => $report->toArray()]);
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/test-module/update', ['layout_strategy' => 'keep']);

        $response->assertStatus(200)
            ->assertJsonPath('data.layout_sync.kept_no_base_count', 1)
            ->assertJsonPath('data.layout_sync.entries.1.verdict', 'kept_no_base');
        $this->assertStringEndsWith(
            __('extensions.layout_sync.summary', ['applied' => 1, 'conflicts' => 0]).' '.__('extensions.layout_sync.no_base', ['count' => 1]),
            (string) $response->json('message')
        );
    }

    /**
     * overwrite 업데이트는 덮어쓴 행을 보고하지 않으므로(modified_count 0) 성공 문구에 레이아웃 요약이 붙지 않는다 (#727 §5.8).
     *
     * @effects update_api_message_summarizes_layout_sync, overwrite_replaces_operator_layout
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_overwrite_has_no_layout_summary(): void
    {
        $report = new LayoutSyncReport(LayoutSyncMode::Overwrite);
        $report->add(new LayoutSyncEntry('layout', 'sirsoft-admin_basic', 'test-module.list', 'updated'));

        $mockService = Mockery::mock(ModuleService::class);
        $mockService->shouldReceive('updateModule')
            ->with('test-module', Mockery::any(), 'overwrite', false)
            ->once()
            ->andReturn(['success' => true, 'from_version' => '1.0.0', 'to_version' => '2.0.0', 'layout_strategy' => 'overwrite', 'layout_sync' => $report->toArray()]);
        $this->app->instance(ModuleService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/modules/test-module/update', ['layout_strategy' => 'overwrite']);

        $response->assertStatus(200)
            ->assertJsonPath('data.layout_sync.strategy', 'overwrite')
            ->assertJsonPath('data.layout_sync.modified_count', 0);
        $this->assertSame(__('modules.update_success', ['module' => 'test-module', 'version' => '2.0.0']), $response->json('message'), '덮어쓰기는 성공 문구만 — 요약이 붙으면 안 된다');
    }
}
