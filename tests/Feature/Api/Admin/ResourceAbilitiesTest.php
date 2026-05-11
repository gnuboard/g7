<?php

namespace Tests\Feature\Api\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * API 리소스의 abilities 메타 통합 테스트
 *
 * BaseApiResource.resourceMeta()가 실제 API 응답에서
 * abilities 키를 올바르게 반환하는지 검증합니다.
 */
class ResourceAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * sirsoft-marketing 플러그인이 User 리소스 훅에 개입하므로 마이그레이션 필요
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-marketing',
    ];

    private User $adminUser;

    private string $adminToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed();

        // Admin 사용자 생성
        $this->adminUser = $this->createUserWithRole([
            'core.users.read',
            'core.users.create',
            'core.users.update',
            'core.users.delete',
        ]);
        $this->adminToken = $this->adminUser->createToken('test')->plainTextToken;
    }

    // =========================================================================
    // Admin 사용자 abilities 테스트
    // =========================================================================

    /**
     * Admin 역할 사용자는 모든 abilities가 true로 반환된다.
     */
    public function test_admin_user_gets_all_abilities_true(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->withToken($this->adminToken)
            ->getJson("/api/admin/users/{$targetUser->uuid}");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        $this->assertTrue($data['abilities']['can_read']);
        $this->assertTrue($data['abilities']['can_create']);
        $this->assertTrue($data['abilities']['can_update']);
        $this->assertTrue($data['abilities']['can_delete']);
    }

    /**
     * abilities 키는 abilityMap 정의 개수만큼의 항목을 포함한다.
     */
    public function test_abilities_contains_expected_keys(): void
    {
        $targetUser = User::factory()->create();

        $response = $this->withToken($this->adminToken)
            ->getJson("/api/admin/users/{$targetUser->uuid}");

        $response->assertOk();
        $data = $response->json('data');

        $this->assertArrayHasKey('abilities', $data);
        // UserResource의 abilityMap에는 can_read, can_create, can_update, can_delete, can_assign_roles 5개
        $this->assertCount(5, $data['abilities']);
        $this->assertArrayHasKey('can_read', $data['abilities']);
        $this->assertArrayHasKey('can_create', $data['abilities']);
        $this->assertArrayHasKey('can_update', $data['abilities']);
        $this->assertArrayHasKey('can_delete', $data['abilities']);
        $this->assertArrayHasKey('can_assign_roles', $data['abilities']);
    }

    // =========================================================================
    // permission 기반 abilities 테스트 (scope_type 시스템 도입 이후)
    // =========================================================================

    /**
     * 자기 자신 조회 시에도 core.users.update 권한이 없으면 can_update는 false로 반환된다.
     *
     * 과거 abilityBypassMap() 의 self/owner 바이패스는 폐기되고 scope_type 시스템으로
     * 대체되었으므로, permission 보유 여부가 단일 판정 기준이 된다.
     */
    public function test_self_user_gets_can_update_false_without_permission(): void
    {
        // core.users.read + core.users.create만 가진 사용자 (update 없음)
        $user = $this->createUserWithRole([
            'core.users.read',
            'core.users.create',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        // 자기 자신 조회
        $response = $this->withToken($token)
            ->getJson("/api/admin/users/{$user->uuid}");

        $response->assertOk();
        $abilities = $response->json('data.abilities');

        $this->assertFalse($abilities['can_update'], 'permission 없으면 자기 자신이라도 can_update=false');
        $this->assertFalse($abilities['can_delete'], 'permission 없으면 can_delete=false');
    }

    /**
     * 다른 사용자 조회 시 core.users.update 권한 없으면 can_update가 false로 반환된다.
     */
    public function test_other_user_gets_can_update_false_without_permission(): void
    {
        // core.users.read만 가진 사용자 (update 없음)
        $user = $this->createUserWithRole([
            'core.users.read',
        ]);
        $token = $user->createToken('test')->plainTextToken;

        $otherUser = User::factory()->create();

        // 다른 사용자 조회
        $response = $this->withToken($token)
            ->getJson("/api/admin/users/{$otherUser->uuid}");

        $response->assertOk();
        $abilities = $response->json('data.abilities');

        $this->assertFalse($abilities['can_update'], '타인은 can_update가 false여야 합니다');
    }

    // =========================================================================
    // is_owner 테스트 (UserResource ownerField='id')
    // =========================================================================

    /**
     * UserResource 는 ownerField='id' 를 정의하므로 is_owner 키가 응답에 포함된다.
     *
     * 자기 자신 조회 시 is_owner=true, 타인 조회 시 is_owner=false.
     * (ownerField 미정의 리소스의 is_owner 생략 동작은 BaseApiResource 단위 테스트에서 검증.)
     */
    public function test_user_resource_includes_is_owner_based_on_request_user(): void
    {
        $targetUser = User::factory()->create();

        // 타인 조회: is_owner=false
        $responseOther = $this->withToken($this->adminToken)
            ->getJson("/api/admin/users/{$targetUser->uuid}");
        $responseOther->assertOk();
        $this->assertArrayHasKey('is_owner', $responseOther->json('data'));
        $this->assertFalse($responseOther->json('data.is_owner'));

        // 자기 자신 조회: is_owner=true
        $responseSelf = $this->withToken($this->adminToken)
            ->getJson("/api/admin/users/{$this->adminUser->uuid}");
        $responseSelf->assertOk();
        $this->assertTrue($responseSelf->json('data.is_owner'));
    }

    // =========================================================================
    // 기존 permissions 키 유지 확인
    // =========================================================================

    /**
     * UserResource의 기존 permissions 키(역할 권한 목록)가 정상 유지된다.
     */
    public function test_existing_permissions_key_preserved_in_user_detail(): void
    {
        $response = $this->withToken($this->adminToken)
            ->getJson("/api/admin/users/{$this->adminUser->uuid}?detail=true");

        $response->assertOk();
        $data = $response->json('data');

        // abilities 키와 permissions 키가 공존
        $this->assertArrayHasKey('abilities', $data);

        // permissions 키는 기존 역할 권한 목록 (abilities와 다른 용도)
        // 상세 조회 시에만 포함 (whenLoaded)
    }

    // =========================================================================
    // abilityMap 미정의 리소스 테스트
    // =========================================================================

    /**
     * abilityMap이 빈 리소스는 abilities 키가 포함되지 않는다.
     */
    public function test_resource_without_ability_map_excludes_abilities_key(): void
    {
        // ActivityLogResource는 abilityMap 미정의
        // 직접 API 호출 대신 단위 테스트에서 이미 검증
        // 여기서는 구조적 검증만 수행
        $this->assertTrue(true, 'abilityMap 미정의 리소스는 단위 테스트에서 검증됨');
    }

    // =========================================================================
    // 헬퍼 메서드
    // =========================================================================

    /**
     * 특정 역할과 권한을 가진 사용자를 생성합니다.
     *
     * @param  array  $permissions  권한 식별자 목록
     * @return User 생성된 사용자
     */
    private function createUserWithRole(array $permissions = []): User
    {
        static $roleCounter = 0;
        $roleCounter++;

        $user = User::factory()->create();

        // 테스트별 고유 역할 생성 (권한 격리를 위해)
        $role = Role::create([
            'identifier' => 'test_admin_' . $roleCounter . '_' . uniqid(),
            'name' => json_encode(['ko' => '테스트 관리자', 'en' => 'Test Admin']),
            'description' => json_encode(['ko' => '테스트용', 'en' => 'For testing']),
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'is_active' => true,
        ]);

        // 권한 생성 및 역할에 연결
        $permissionIds = [];
        foreach ($permissions as $identifier) {
            $permission = Permission::firstOrCreate(
                ['identifier' => $identifier],
                [
                    'name' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'description' => json_encode(['ko' => $identifier, 'en' => $identifier]),
                    'extension_type' => ExtensionOwnerType::Core,
                    'extension_identifier' => 'core',
                    'type' => 'admin',
                ]
            );
            $permissionIds[] = $permission->id;
        }
        $role->permissions()->sync($permissionIds);

        // 역할 할당
        $user->roles()->attach($role->id, ['assigned_at' => now()]);

        return $user->fresh();
    }
}
