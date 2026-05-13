<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ActivityLogType;
use App\Enums\ExtensionOwnerType;
use App\Helpers\PermissionHelper;
use App\Models\ActivityLog;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * ActivityLogController 테스트
 *
 * 활동 로그 API 엔드포인트를 테스트합니다.
 */
class ActivityLogControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        $this->clearPermissionCache();
        $this->admin = $this->createAdminUser();
        $this->token = $this->admin->createToken('test-token')->plainTextToken;
    }

    protected function tearDown(): void
    {
        $this->clearPermissionCache();
        parent::tearDown();
    }

    /**
     * PermissionHelper 정적 캐시 초기화
     *
     * @return void
     */
    private function clearPermissionCache(): void
    {
        $reflection = new \ReflectionClass(PermissionHelper::class);
        $prop = $reflection->getProperty('permissionCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    /**
     * 관리자 역할 생성 및 할당 (스코프 지정 가능)
     *
     * @param array $permissions 사용자에게 부여할 권한 식별자 목록
     * @param string|null $scopeType 스코프 타입 (null: 전체, 'self': 본인, 'role': 소유역할)
     * @return User
     */
    private function createAdminUser(array $permissions = ['core.activities.read'], ?string $scopeType = null): User
    {
        $user = User::factory()->create([
            'password' => Hash::make('password123'),
        ]);

        // 권한 생성
        $permissionIds = [];
        foreach ($permissions as $permIdentifier) {
            $attrs = [
                'name' => json_encode(['ko' => $permIdentifier, 'en' => $permIdentifier]),
                'description' => json_encode(['ko' => $permIdentifier.' 권한', 'en' => $permIdentifier.' Permission']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'type' => 'admin',
            ];

            // 스코프 지원 권한에 resource_route_key, owner_key 설정
            if ($permIdentifier === 'core.activities.read') {
                $attrs['resource_route_key'] = 'activityLog';
                $attrs['owner_key'] = 'user_id';
            }

            $permission = Permission::firstOrCreate(
                ['identifier' => $permIdentifier],
                $attrs
            );

            // firstOrCreate로 이미 존재하는 경우 owner_key가 없을 수 있으므로 업데이트
            if ($permIdentifier === 'core.activities.read' && $permission->owner_key === null) {
                $permission->update([
                    'resource_route_key' => 'activityLog',
                    'owner_key' => 'user_id',
                ]);
            }

            $permissionIds[] = $permission->id;
        }

        // 고유한 식별자로 역할 생성
        $roleIdentifier = 'admin_test_'.uniqid();
        $adminRole = Role::create([
            'identifier' => $roleIdentifier,
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'description' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Administrator']),
            'is_active' => true,
        ]);

        // admin 역할 (admin 미들웨어 통과용)
        $adminBaseRole = Role::firstOrCreate(
            ['identifier' => 'admin'],
            [
                'name' => json_encode(['ko' => '관리자', 'en' => 'Administrator']),
                'description' => json_encode(['ko' => '시스템 관리자', 'en' => 'System Administrator']),
                'extension_type' => ExtensionOwnerType::Core,
                'extension_identifier' => 'core',
                'is_active' => true,
            ]
        );

        // 테스트용 역할에 권한 할당 (스코프 타입 포함)
        $syncData = [];
        foreach ($permissionIds as $permId) {
            $syncData[$permId] = ['scope_type' => $scopeType];
        }
        $adminRole->permissions()->sync($syncData);

        // 사용자에게 admin 역할과 테스트용 역할 모두 할당
        $user->roles()->attach($adminBaseRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);
        $user->roles()->attach($adminRole->id, [
            'assigned_at' => now(),
            'assigned_by' => null,
        ]);

        return $user->fresh();
    }

    /**
     * 인증 헤더와 함께 요청
     *
     * @return $this
     */
    private function authRequest(): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->token,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 테스트용 활동 로그 생성
     *
     * @param array $overrides 오버라이드할 속성
     * @return ActivityLog
     */
    private function createActivityLog(array $overrides = []): ActivityLog
    {
        return ActivityLog::create(array_merge([
            'log_type' => ActivityLogType::Admin,
            'action' => 'user.update',
            'description_key' => 'activity_log.description.user_update',
            'description_params' => ['user_id' => 'abc-123'],
            'loggable_type' => 'App\\Models\\User',
            'loggable_id' => 1,
            'user_id' => $this->admin->id,
            'ip_address' => '127.0.0.1',
        ], $overrides));
    }

    // ========================================================================
    // 인증/권한 테스트
    // ========================================================================

    public function test_index_returns_401_without_authentication(): void
    {
        $response = $this->getJson('/api/admin/activity-logs');
        $response->assertStatus(401);
    }

    public function test_index_returns_403_without_permission(): void
    {
        $user = $this->createAdminUser([]);
        $token = $user->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/activity-logs');

        $response->assertStatus(403);
    }

    // ========================================================================
    // index 엔드포인트 - 응답 구조
    // ========================================================================

    public function test_index_returns_data_and_pagination_structure(): void
    {
        $this->createActivityLog();
        $this->createActivityLog(['action' => 'user.create']);
        $this->createActivityLog(['action' => 'user.delete']);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'data',
                    'pagination' => [
                        'current_page',
                        'last_page',
                        'per_page',
                        'total',
                        'from',
                        'to',
                        'has_more_pages',
                    ],
                ],
            ]);
    }

    public function test_index_data_items_have_correct_fields(): void
    {
        $this->createActivityLog();

        $response = $this->authRequest()->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);

        $item = $data[0];
        $this->assertArrayHasKey('id', $item);
        $this->assertArrayHasKey('log_type', $item);
        $this->assertArrayHasKey('log_type_label', $item);
        $this->assertArrayHasKey('loggable_type', $item);
        $this->assertArrayHasKey('loggable_type_display', $item);
        $this->assertArrayHasKey('loggable_id', $item);
        $this->assertArrayHasKey('action', $item);
        $this->assertArrayHasKey('action_label', $item);
        $this->assertArrayHasKey('localized_description', $item);
        $this->assertArrayHasKey('actor_name', $item);
        $this->assertArrayHasKey('user', $item);
        $this->assertArrayHasKey('ip_address', $item);
        $this->assertArrayHasKey('created_at', $item);
        $this->assertArrayHasKey('changes', $item);
        $this->assertArrayHasKey('has_changes', $item);
        $this->assertArrayHasKey('bulk_changes', $item);
        $this->assertArrayHasKey('number', $item);
    }

    public function test_index_includes_loggable_type_display(): void
    {
        $this->createActivityLog([
            'loggable_type' => 'Modules\\Sirsoft\\Ecommerce\\Models\\Product',
            'loggable_id' => 42,
        ]);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEquals('Product', $data[0]['loggable_type_display']);
    }

    public function test_index_returns_empty_data_when_no_logs(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.data'));
        $this->assertEquals(0, $response->json('data.pagination.total'));
    }

    // ========================================================================
    // index 엔드포인트 - 필터
    // ========================================================================

    public function test_index_filters_by_log_type_array(): void
    {
        $this->createActivityLog(['log_type' => ActivityLogType::Admin]);
        $this->createActivityLog(['log_type' => ActivityLogType::User]);
        $this->createActivityLog(['log_type' => ActivityLogType::System]);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs?log_type[]=admin&log_type[]=user');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_index_filters_by_single_log_type(): void
    {
        $this->createActivityLog(['log_type' => ActivityLogType::Admin]);
        $this->createActivityLog(['log_type' => ActivityLogType::User]);
        $this->createActivityLog(['log_type' => ActivityLogType::System]);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs?log_type[]=admin');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_filters_by_search_keyword(): void
    {
        $this->createActivityLog(['action' => 'user.create', 'ip_address' => '10.0.0.1']);
        $this->createActivityLog(['action' => 'product.update', 'ip_address' => '192.168.1.1']);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs?search=product');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_search_matches_ip_address(): void
    {
        $this->createActivityLog(['ip_address' => '10.0.0.1']);
        $this->createActivityLog(['ip_address' => '192.168.1.1']);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs?search=192.168');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_filters_by_search_type_action(): void
    {
        $this->createActivityLog(['action' => 'user.create', 'ip_address' => '10.0.0.1']);
        $this->createActivityLog(['action' => 'product.update', 'ip_address' => '192.168.1.1']);

        // search_type=action → action 필드에서만 검색
        $response = $this->authRequest()->getJson('/api/admin/activity-logs?search=product&search_type=action');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('product.update', $response->json('data.data.0.action'));
    }

    public function test_index_filters_by_search_type_ip_address(): void
    {
        $this->createActivityLog(['action' => 'user.create', 'ip_address' => '10.0.0.1']);
        $this->createActivityLog(['action' => 'product.update', 'ip_address' => '192.168.1.1']);

        // search_type=ip_address → ip_address 필드에서만 검색
        $response = $this->authRequest()->getJson('/api/admin/activity-logs?search=192.168&search_type=ip_address');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_search_type_action_does_not_match_ip(): void
    {
        $this->createActivityLog(['action' => 'user.create', 'ip_address' => '192.168.1.1']);

        // search_type=action → IP 주소로 검색해도 결과 없음
        $response = $this->authRequest()->getJson('/api/admin/activity-logs?search=192.168&search_type=action');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data.data'));
    }

    public function test_index_filters_by_created_by_uuid(): void
    {
        $user1 = User::factory()->create(['name' => '홍길동']);
        $user2 = User::factory()->create(['name' => '김철수']);

        $this->createActivityLog(['user_id' => $user1->id]);
        $this->createActivityLog(['user_id' => $user2->id]);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs?created_by='.$user1->uuid);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('홍길동', $response->json('data.data.0.actor_name'));
    }

    public function test_index_bilingual_search_finds_by_action_translation(): void
    {
        // action 'user.create' → 번역 키 'activity_log.action.user.create' 또는 'activity_log.action.create'
        $this->createActivityLog(['action' => 'user.create']);
        $this->createActivityLog(['action' => 'product.update']);

        // 영어 원문으로 검색
        $response = $this->authRequest()->getJson('/api/admin/activity-logs?search=user.create&search_type=action');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
        $this->assertEquals('user.create', $response->json('data.data.0.action'));
    }

    public function test_index_bilingual_search_finds_by_description_key(): void
    {
        $this->createActivityLog([
            'description_key' => 'activity_log.description.user_update',
        ]);
        $this->createActivityLog([
            'description_key' => 'activity_log.description.product_create',
        ]);

        // description_key 원문으로 검색
        $response = $this->authRequest()->getJson('/api/admin/activity-logs?search=user_update&search_type=description');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_includes_has_changes_field(): void
    {
        // changes가 있는 로그 (오래된 것)
        $this->createActivityLog([
            'changes' => [
                ['field' => 'name', 'old' => '기존', 'new' => '변경'],
            ],
            'created_at' => Carbon::parse('2026-01-01 10:00:00'),
        ]);

        // changes가 없는 로그 (최신)
        $this->createActivityLog([
            'changes' => null,
            'created_at' => Carbon::parse('2026-01-02 10:00:00'),
        ]);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(2, $data);

        // 최신순 정렬이므로 changes=null이 먼저
        $this->assertFalse($data[0]['has_changes']);
        $this->assertTrue($data[1]['has_changes']);
    }

    public function test_index_filters_by_date_range(): void
    {
        $this->createActivityLog(['created_at' => Carbon::parse('2026-01-15')]);
        $this->createActivityLog(['created_at' => Carbon::parse('2026-02-15')]);
        $this->createActivityLog(['created_at' => Carbon::parse('2026-03-15')]);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs?date_from=2026-02-01&date_to=2026-02-28');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    public function test_index_filters_by_action(): void
    {
        $this->createActivityLog(['action' => 'user.create']);
        $this->createActivityLog(['action' => 'user.update']);
        $this->createActivityLog(['action' => 'user.delete']);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs?action=user.create');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data.data'));
    }

    // ========================================================================
    // index 엔드포인트 - 페이지네이션
    // ========================================================================

    public function test_index_respects_per_page_parameter(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->createActivityLog(['action' => "action_{$i}"]);
        }

        $response = $this->authRequest()->getJson('/api/admin/activity-logs?per_page=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
        $this->assertEquals(5, $response->json('data.pagination.total'));
        $this->assertEquals(3, $response->json('data.pagination.last_page'));
    }

    public function test_index_returns_row_numbers(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->createActivityLog(['action' => "action_{$i}"]);
        }

        $response = $this->authRequest()->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');

        // 내림차순 기본 정렬 → 번호는 total부터 역순
        $this->assertEquals(3, $data[0]['number']);
        $this->assertEquals(2, $data[1]['number']);
        $this->assertEquals(1, $data[2]['number']);
    }

    // ========================================================================
    // index 엔드포인트 - 정렬
    // ========================================================================

    public function test_index_sorts_by_created_at_desc_by_default(): void
    {
        $this->createActivityLog(['action' => 'old', 'created_at' => Carbon::parse('2026-01-01')]);
        $this->createActivityLog(['action' => 'new', 'created_at' => Carbon::parse('2026-03-01')]);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEquals('new', $data[0]['action']);
        $this->assertEquals('old', $data[1]['action']);
    }

    public function test_index_sorts_ascending_when_requested(): void
    {
        $this->createActivityLog(['action' => 'old', 'created_at' => Carbon::parse('2026-01-01')]);
        $this->createActivityLog(['action' => 'new', 'created_at' => Carbon::parse('2026-03-01')]);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs?sort_order=asc');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertEquals('old', $data[0]['action']);
        $this->assertEquals('new', $data[1]['action']);
    }

    // ========================================================================
    // 비활성화 모듈 로그 안전 처리
    // ========================================================================

    public function test_index_handles_deactivated_module_loggable_type_safely(): void
    {
        // 비활성화된 모듈의 FQCN — 클래스 미존재
        $this->createActivityLog([
            'loggable_type' => 'Modules\\Nonexistent\\Models\\SomeModel',
            'loggable_id' => 999,
        ]);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs');

        // loggable 관계를 로드하지 않으므로 오류 없이 정상 응답
        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('SomeModel', $data[0]['loggable_type_display']);
    }

    // ========================================================================
    // 유효성 검증 실패
    // ========================================================================

    public function test_index_rejects_invalid_log_type(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/activity-logs?log_type[]=invalid');

        $response->assertStatus(422);
    }

    public function test_index_rejects_invalid_date_range(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/activity-logs?date_from=2026-03-28&date_to=2026-01-01');

        $response->assertStatus(422);
    }

    public function test_index_rejects_per_page_exceeding_max(): void
    {
        $response = $this->authRequest()->getJson('/api/admin/activity-logs?per_page=101');

        $response->assertStatus(422);
    }

    // ========================================================================
    // index 엔드포인트 - abilities
    // ========================================================================

    public function test_index_includes_abilities_with_delete_permission(): void
    {
        // 삭제 권한도 가진 관리자
        $adminWithDelete = $this->createAdminUser(['core.activities.read', 'core.activities.delete']);
        $token = $adminWithDelete->createToken('test-token')->plainTextToken;

        $this->createActivityLog(['user_id' => $adminWithDelete->id]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertTrue($data[0]['abilities']['can_delete']);
    }

    public function test_index_abilities_can_delete_false_without_permission(): void
    {
        $this->createActivityLog();

        $response = $this->authRequest()->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertFalse($data[0]['abilities']['can_delete']);
    }

    // ========================================================================
    // index 엔드포인트 - collection-level abilities
    // ========================================================================

    public function test_index_includes_collection_level_abilities_with_delete_permission(): void
    {
        $adminWithDelete = $this->createAdminUser(['core.activities.read', 'core.activities.delete']);
        $token = $adminWithDelete->createToken('test-token')->plainTextToken;

        $this->createActivityLog(['user_id' => $adminWithDelete->id]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $this->assertTrue($response->json('data.abilities.can_delete'));
    }

    public function test_index_collection_level_abilities_can_delete_false_without_permission(): void
    {
        $this->createActivityLog();

        $response = $this->authRequest()->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $this->assertFalse($response->json('data.abilities.can_delete'));
    }

    // ========================================================================
    // destroy 엔드포인트
    // ========================================================================

    public function test_destroy_returns_401_without_authentication(): void
    {
        $log = $this->createActivityLog();

        $response = $this->deleteJson("/api/admin/activity-logs/{$log->id}");
        $response->assertStatus(401);
    }

    public function test_destroy_returns_403_without_delete_permission(): void
    {
        $log = $this->createActivityLog();

        $response = $this->authRequest()->deleteJson("/api/admin/activity-logs/{$log->id}");
        $response->assertStatus(403);
    }

    public function test_destroy_deletes_activity_log_successfully(): void
    {
        $adminWithDelete = $this->createAdminUser(['core.activities.read', 'core.activities.delete']);
        $token = $adminWithDelete->createToken('test-token')->plainTextToken;

        $log = $this->createActivityLog();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->deleteJson("/api/admin/activity-logs/{$log->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertDatabaseMissing('activity_logs', ['id' => $log->id]);
    }

    public function test_destroy_returns_404_for_nonexistent_log(): void
    {
        $adminWithDelete = $this->createAdminUser(['core.activities.read', 'core.activities.delete']);
        $token = $adminWithDelete->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->deleteJson('/api/admin/activity-logs/99999');

        $response->assertStatus(404);
    }

    // ========================================================================
    // bulkDestroy 엔드포인트
    // ========================================================================

    public function test_bulk_destroy_returns_401_without_authentication(): void
    {
        $response = $this->postJson('/api/admin/activity-logs/bulk-delete', ['ids' => [1]]);
        $response->assertStatus(401);
    }

    public function test_bulk_destroy_returns_403_without_delete_permission(): void
    {
        $log = $this->createActivityLog();

        $response = $this->authRequest()->postJson('/api/admin/activity-logs/bulk-delete', ['ids' => [$log->id]]);
        $response->assertStatus(403);
    }

    public function test_bulk_destroy_deletes_multiple_logs_successfully(): void
    {
        $adminWithDelete = $this->createAdminUser(['core.activities.read', 'core.activities.delete']);
        $token = $adminWithDelete->createToken('test-token')->plainTextToken;

        $log1 = $this->createActivityLog(['action' => 'action1']);
        $log2 = $this->createActivityLog(['action' => 'action2']);
        $log3 = $this->createActivityLog(['action' => 'action3']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/activity-logs/bulk-delete', [
            'ids' => [$log1->id, $log2->id],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.deleted_count', 2);

        $this->assertDatabaseMissing('activity_logs', ['id' => $log1->id]);
        $this->assertDatabaseMissing('activity_logs', ['id' => $log2->id]);
        $this->assertDatabaseHas('activity_logs', ['id' => $log3->id]);
    }

    public function test_bulk_destroy_validates_ids_required(): void
    {
        $adminWithDelete = $this->createAdminUser(['core.activities.read', 'core.activities.delete']);
        $token = $adminWithDelete->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/activity-logs/bulk-delete', []);

        $response->assertStatus(422);
    }

    public function test_bulk_destroy_validates_ids_exist(): void
    {
        $adminWithDelete = $this->createAdminUser(['core.activities.read', 'core.activities.delete']);
        $token = $adminWithDelete->createToken('test-token')->plainTextToken;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->postJson('/api/admin/activity-logs/bulk-delete', [
            'ids' => [99999],
        ]);

        $response->assertStatus(422);
    }

    // ========================================================================
    // index 엔드포인트 - 스코프 기반 권한
    // ========================================================================

    public function test_index_scope_self_returns_only_own_logs(): void
    {
        $selfScopeUser = $this->createAdminUser(['core.activities.read'], 'self');
        $token = $selfScopeUser->createToken('test-token')->plainTextToken;

        $otherUser = User::factory()->create();

        // 본인 로그
        $this->createActivityLog(['user_id' => $selfScopeUser->id, 'action' => 'own.action']);
        // 타인 로그
        $this->createActivityLog(['user_id' => $otherUser->id, 'action' => 'other.action']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(1, $data);
        $this->assertEquals('own.action', $data[0]['action']);
    }

    public function test_index_scope_null_returns_all_logs(): void
    {
        // 기본 createAdminUser는 scopeType=null (전체 접근)
        $otherUser = User::factory()->create();

        $this->createActivityLog(['user_id' => $this->admin->id, 'action' => 'own.action']);
        $this->createActivityLog(['user_id' => $otherUser->id, 'action' => 'other.action']);

        $response = $this->authRequest()->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data.data'));
    }

    public function test_index_scope_role_returns_shared_role_logs(): void
    {
        $roleScopeUser = $this->createAdminUser(['core.activities.read'], 'role');
        $token = $roleScopeUser->createToken('test-token')->plainTextToken;

        // 동일 역할을 공유하는 사용자 생성
        $sharedRoleUser = User::factory()->create();
        $roleScopeUserRoles = $roleScopeUser->roles()->where('identifier', '!=', 'admin')->pluck('roles.id');
        foreach ($roleScopeUserRoles as $roleId) {
            $sharedRoleUser->roles()->attach($roleId, [
                'assigned_at' => now(),
                'assigned_by' => null,
            ]);
        }

        // 다른 역할만 가진 사용자
        $unrelatedUser = User::factory()->create();

        // 본인 로그
        $this->createActivityLog(['user_id' => $roleScopeUser->id, 'action' => 'own.action']);
        // 동일 역할 사용자 로그
        $this->createActivityLog(['user_id' => $sharedRoleUser->id, 'action' => 'shared.action']);
        // 관련 없는 사용자 로그
        $this->createActivityLog(['user_id' => $unrelatedUser->id, 'action' => 'unrelated.action']);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.$token,
            'Accept' => 'application/json',
        ])->getJson('/api/admin/activity-logs');

        $response->assertStatus(200);
        $data = $response->json('data.data');
        $this->assertCount(2, $data);

        $actions = collect($data)->pluck('action')->toArray();
        $this->assertContains('own.action', $actions);
        $this->assertContains('shared.action', $actions);
        $this->assertNotContains('unrelated.action', $actions);
    }
}
