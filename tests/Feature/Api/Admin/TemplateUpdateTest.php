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
use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
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

    /**
     * 템플릿 권한을 가진 관리자와 인증 토큰을 준비한다.
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
        $response = $this->postJson('/api/admin/templates/check-updates');

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
     *
     * @effects update_api_reports_available_updates
     *
     * @return void 반환값 없음
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/templates/test-template/update');

        $response->assertStatus(401);
    }

    /**
     * 템플릿 업데이트 성공 시 200과 템플릿 정보 반환
     *
     * @effects update_api_returns_updated_extension
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_returns_template_info_on_success(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('test-template', 'overwrite', false)
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
     *
     * @effects update_api_returns_updated_extension
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_response_includes_update_fields(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('test-template', 'overwrite', false)
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_returns_422_for_nonexistent_template(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('nonexistent-template', 'overwrite', false)
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_returns_500_on_server_error(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('test-template', 'overwrite', false)
            ->once()
            ->andThrow(new \RuntimeException('Unexpected error'));
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/templates/test-template/update');

        $response->assertStatus(500);
    }

    /**
     * 권한 없이 템플릿 업데이트 시 403 반환
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
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
     *
     * @effects update_api_passes_layout_strategy
     *
     * @return void 반환값 없음
     */
    public function test_perform_update_sends_layout_strategy_to_service(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('test-template', 'keep', false)
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
     */
    public function test_check_modified_layouts_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/templates/test-template/check-modified-layouts');

        $response->assertStatus(401);
    }

    /**
     * 수정된 레이아웃 확인 성공 시 결과 반환
     *
     * @effects modified_layouts_check_lists_operator_rows
     *
     * @return void 반환값 없음
     */
    public function test_check_modified_layouts_returns_result(): void
    {
        $mockService = Mockery::mock(TemplateService::class);
        // 컨트롤러는 조회 전에 식별자 존재를 먼저 확인한다 — 이 선행 호출을 스텁하지 않으면
        // Mockery 가 미정의 호출로 예외를 던지고 그것이 500 으로 잡힌다.
        $mockService->shouldReceive('getTemplateInfo')
            ->with('test-template')
            ->andReturn(['identifier' => 'test-template', 'name' => 'Test Template']);
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
     *
     * @effects update_api_rejects_unauthorized_and_invalid_requests
     *
     * @return void 반환값 없음
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
     *
     * @effects update_api_routes_are_named
     *
     * @return void 반환값 없음
     */
    public function test_check_updates_route_name_exists(): void
    {
        $this->assertTrue(
            Route::has('api.admin.templates.check-updates'),
            'Route api.admin.templates.check-updates should exist'
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
            Route::has('api.admin.templates.update'),
            'Route api.admin.templates.update should exist'
        );
    }

    /**
     * check-modified-layouts 라우트가 올바른 이름을 가지는지 확인
     *
     * @effects update_api_routes_are_named
     *
     * @return void 반환값 없음
     */
    public function test_check_modified_layouts_route_name_exists(): void
    {
        $this->assertTrue(
            Route::has('api.admin.templates.check-modified-layouts'),
            'Route api.admin.templates.check-modified-layouts should exist'
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
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('test-template', 'keep', false)
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
                        ['kind' => 'layout', 'template' => 'sirsoft-admin_basic', 'name' => 'test-template.list', 'verdict' => 'merged', 'applied' => [['unit' => 'root > partials/x/_a @ 0', 'kind' => 'partial'], ['unit' => 'item:data_sources/items', 'kind' => 'item']], 'conflicts' => [['unit' => 'root > partials/x/_b @ 0', 'reason' => 'both_changed']]],
                    ],
                ],
                'template_info' => [
                    'identifier' => 'test-template',
                    'vendor' => 'test',
                    'name' => ['ko' => '테스트', 'en' => 'Test'],
                    'version' => '2.0.0',
                    'type' => 'admin',
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
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/templates/test-template/update', ['layout_strategy' => 'keep']);

        $response->assertStatus(200)
            ->assertJsonPath('data.identifier', 'test-template')
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
        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('test-template', 'keep', false)
            ->once()
            ->andReturn([
                'success' => true,
                'from_version' => '1.0.0',
                'to_version' => '2.0.0',
                'layout_sync' => ['strategy' => 'keep', 'applied_count' => 0, 'conflict_count' => 0, 'kept_no_base_count' => 0, 'kept_removed_count' => 0, 'kept_deleted_by_operator_count' => 0, 'kept_name_collision_count' => 0, 'kept_invalid_count' => 0, 'modified_count' => 0, 'entries' => []],
            ]);
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/templates/test-template/update', ['layout_strategy' => 'keep']);

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
        $report->add(new LayoutSyncEntry('layout', 'sirsoft-admin_basic', 'test-template.list', 'merged', [['unit' => 'root > partials/x/_a @ 0', 'kind' => 'partial']]));
        $report->add(new LayoutSyncEntry('layout', 'sirsoft-admin_basic', 'test-template.legacy', 'kept_no_base'));

        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('test-template', 'keep', false)
            ->once()
            ->andReturn(['success' => true, 'from_version' => '1.0.0', 'to_version' => '2.0.0', 'layout_strategy' => 'keep', 'layout_sync' => $report->toArray()]);
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/templates/test-template/update', ['layout_strategy' => 'keep']);

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
        $report->add(new LayoutSyncEntry('layout', 'sirsoft-admin_basic', 'test-template.list', 'updated'));

        $mockService = Mockery::mock(TemplateService::class);
        $mockService->shouldReceive('performVersionUpdate')
            ->with('test-template', 'overwrite', false)
            ->once()
            ->andReturn(['success' => true, 'from_version' => '1.0.0', 'to_version' => '2.0.0', 'layout_strategy' => 'overwrite', 'layout_sync' => $report->toArray()]);
        $this->app->instance(TemplateService::class, $mockService);

        $response = $this->authRequest()->postJson('/api/admin/templates/test-template/update', ['layout_strategy' => 'overwrite']);

        $response->assertStatus(200)
            ->assertJsonPath('data.layout_sync.strategy', 'overwrite')
            ->assertJsonPath('data.layout_sync.modified_count', 0);
        $this->assertSame(__('templates.update_success', ['template' => 'test-template', 'version' => '2.0.0']), $response->json('message'), '덮어쓰기는 성공 문구만 — 요약이 붙으면 안 된다');
    }
}
