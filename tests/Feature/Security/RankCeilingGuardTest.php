<?php

namespace Tests\Feature\Security;

use App\Enums\ExtensionOwnerType;
use App\Exceptions\CannotModifyProtectedRoleException;
use App\Models\Attachment;
use App\Models\Menu;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use App\Services\MenuService;
use App\Services\RoleService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * KVE-2026-1919 등급 상한(rank ceiling) 가드 회귀 테스트
 *
 * 삭제/탈퇴 경로에만 있던 슈퍼 관리자·보호 역할 보호를 수정·상태변경·권한부여
 * 경로까지 대칭 적용했는지 검증합니다. 각 케이스는 (a) 익스플로잇 차단 +
 * (b) 슈퍼 관리자 정상 흐름 회귀 없음 양면을 확인합니다.
 *
 * 시나리오 축·효과는 매니페스트 tests/scenarios/user-grade-rank-ceiling.yaml 참조.
 * 각 test 메서드의 `@scenario actor=… , operation=… , outcome=…` 마커가 축 조합을,
 * `@effects …` 가 효과를 커버한다(메서드당 단일 조합).
 *
 * 축 요약(마커 아님 — 평문): actor 는 sub_admin·super_admin, operation 은 modify·status·
 * unlock·grant_permission·assign_role·remove_role·update_role·toggle_role, outcome 은 blocked·allowed 다.
 * 이 요약을 클래스 레벨 `@scenario` 로 적으면 시나리오 마커 파서가
 * `=` 없는 토큰을 버리고 첫 값만 취해 **실재하지 않는 조합 1건**으로 집계한다 — 커버리지를
 * 부풀리는 방향이라 요약은 반드시 평문으로 둔다. 실제 커버는 메서드 레벨 마커가 담당한다.
 */
class RankCeilingGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * 슈퍼 관리자 사용자를 생성합니다 (is_super + admin 역할 = 전 권한).
     */
    private function createSuperAdmin(): User
    {
        $user = User::factory()->create(['is_super' => true, 'status' => 'active']);
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 위임 부관리자(비-슈퍼)를 생성합니다.
     *
     * @param  array<string, string|null>  $permissionScopes  identifier => scope_type(null=global)
     */
    private function createSubAdmin(array $permissionScopes): User
    {
        $user = User::factory()->create(['is_super' => false, 'status' => 'active']);

        $role = Role::create([
            'identifier' => 'sub_admin_'.uniqid(),
            'name' => json_encode(['ko' => '부관리자', 'en' => 'Sub Admin']),
            'description' => json_encode(['ko' => '위임 부관리자', 'en' => 'Delegated sub admin']),
            'extension_type' => null,
            'is_active' => true,
        ]);

        $pivot = [];
        foreach ($permissionScopes as $identifier => $scope) {
            $permission = Permission::where('identifier', $identifier)->firstOrFail();
            $pivot[$permission->id] = ['scope_type' => $scope, 'granted_at' => now()];
        }
        $role->permissions()->sync($pivot);

        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    private function tokenFor(User $user): string
    {
        return $user->createToken('test-token')->plainTextToken;
    }

    private function authAs(User $user): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$this->tokenFor($user),
            'Accept' => 'application/json',
        ]);
    }

    /**
     * UpdateUserRequest 를 통과하는 유효한 수정 페이로드를 만듭니다.
     *
     * 가드는 서비스 계층에 있으므로, 요청은 FormRequest 검증(name/email/roles 필수)을
     * 먼저 통과해야 서비스에 도달합니다. 실제 공격자도 유효한 요청을 보냅니다.
     *
     * @param  array<string, mixed>  $overrides  덮어쓸 필드
     * @return array<string, mixed>
     */
    private function updatePayload(User $target, array $overrides = []): array
    {
        $roleIds = $target->roles->pluck('id')->all();
        if (empty($roleIds)) {
            $userRole = Role::where('identifier', 'user')->first();
            $roleIds = $userRole ? [$userRole->id] : [Role::where('identifier', 'admin')->firstOrFail()->id];
        }

        return array_merge([
            'name' => $target->name,
            'email' => $target->email,
            'role_ids' => $roleIds,
        ], $overrides);
    }

    private function roleIdentifier(): string
    {
        return 'test_role_'.substr(uniqid(), -8);
    }

    // =========================================================================
    // C-1 — updateUser 등급 가드
    // =========================================================================

    /**
     * 부관리자(core.users.update 글로벌)가 슈퍼 관리자의 비밀번호를 변경하려 하면 403.
     *
     * @scenario actor=sub_admin, operation=modify, outcome=blocked
     *
     * @effects sub_admin_cannot_modify_super_admin, rejected_target_state_and_tokens_unchanged
     */
    public function test_sub_admin_cannot_change_super_admin_password(): void
    {
        $subAdmin = $this->createSubAdmin(['core.users.update' => null]);
        $superAdmin = $this->createSuperAdmin();
        $originalHash = $superAdmin->password;

        $response = $this->authAs($subAdmin)->putJson("/api/admin/users/{$superAdmin->uuid}", $this->updatePayload($superAdmin, [
            'password' => 'HackedPassword123!',
            'password_confirmation' => 'HackedPassword123!',
        ]));

        $response->assertStatus(403);

        // 대상 슈퍼 관리자 비밀번호가 불변인지 확인
        $superAdmin->refresh();
        $this->assertSame($originalHash, $superAdmin->password);
    }

    /**
     * 부관리자가 슈퍼 관리자의 status 를 blocked 로 바꾸려 하면 403 + 상태 불변.
     *
     * @scenario actor=sub_admin, operation=status, outcome=blocked
     *
     * @effects sub_admin_cannot_modify_super_admin, rejected_target_state_and_tokens_unchanged
     */
    public function test_sub_admin_cannot_block_super_admin(): void
    {
        $subAdmin = $this->createSubAdmin(['core.users.update' => null]);
        $superAdmin = $this->createSuperAdmin();

        $response = $this->authAs($subAdmin)->putJson("/api/admin/users/{$superAdmin->uuid}", $this->updatePayload($superAdmin, [
            'status' => 'blocked',
        ]));

        $response->assertStatus(403);
        $superAdmin->refresh();
        $this->assertSame('active', $superAdmin->status);
    }

    /**
     * (회귀) 슈퍼 관리자 액터는 다른 슈퍼 관리자를 정상 수정할 수 있다.
     *
     * @scenario actor=super_admin, operation=modify, outcome=allowed
     *
     * @effects super_admin_actor_performs_same_operations
     */
    public function test_super_admin_can_update_another_super_admin(): void
    {
        $actor = $this->createSuperAdmin();
        $target = $this->createSuperAdmin();

        $response = $this->authAs($actor)->putJson("/api/admin/users/{$target->uuid}", $this->updatePayload($target, [
            'name' => 'Renamed By Super',
        ]));

        $response->assertOk();
        $target->refresh();
        $this->assertSame('Renamed By Super', $target->name);
    }

    /**
     * (회귀) 부관리자는 일반 사용자는 정상 수정할 수 있다.
     *
     * @scenario actor=sub_admin, operation=modify, outcome=allowed
     *
     * @effects super_admin_write_paths_share_rank_ceiling
     */
    public function test_sub_admin_can_update_regular_user(): void
    {
        $subAdmin = $this->createSubAdmin(['core.users.update' => null]);
        $target = User::factory()->create(['status' => 'active', 'name' => 'Before']);

        $response = $this->authAs($subAdmin)->putJson("/api/admin/users/{$target->uuid}", $this->updatePayload($target, [
            'name' => 'After',
        ]));

        $response->assertOk();
        $target->refresh();
        $this->assertSame('After', $target->name);
    }

    // =========================================================================
    // C-2 — bulkUpdateStatus 슈퍼 관리자 제외
    // =========================================================================

    /**
     * 부관리자의 bulk-status 에 슈퍼 관리자 uuid 가 포함돼도 슈퍼는 무력화되지 않는다.
     *
     * @scenario actor=sub_admin, operation=status, outcome=blocked
     *
     * @effects bulk_status_excludes_super_admin_for_sub_admin, rejected_target_state_and_tokens_unchanged
     */
    public function test_bulk_status_excludes_super_admin_for_sub_admin(): void
    {
        $subAdmin = $this->createSubAdmin(['core.users.update' => null]);
        $superAdmin = $this->createSuperAdmin();
        $regular = User::factory()->create(['status' => 'active']);

        // 슈퍼 관리자의 살아 있는 세션. bulkUpdateStatus 는 Active 외 상태로 바꿀 때
        // 대상들의 토큰을 일괄 삭제하므로(UserService::deleteTokensByUserIds), 등급 필터가
        // 슈퍼를 대상 목록에서 빼는 데 실패하면 **상태는 그대로여도 세션만 날아간다**.
        // 상태만 단언하면 그 회귀를 놓친다 — 계획 E-3 T4 의 요구는 "슈퍼 세션 유지" 다.
        $superToken = $superAdmin->createToken('super-session')->plainTextToken;
        // 대조군. 이 토큰이 삭제되는 것으로 "토큰 일괄 삭제가 실제로 도는 경로" 임이
        // 증명되므로, 아래 슈퍼 토큰 생존 단언이 무증상 통과가 아니게 된다.
        $regular->createToken('regular-session');

        $response = $this->authAs($subAdmin)->patchJson('/api/admin/users/bulk-status', [
            'ids' => [$superAdmin->uuid, $regular->uuid],
            'status' => 'blocked',
        ]);

        $response->assertOk();

        // 슈퍼 관리자는 제외 → 상태 불변, 일반 사용자만 적용
        $superAdmin->refresh();
        $regular->refresh();
        $this->assertSame('active', $superAdmin->status);
        $this->assertSame('blocked', $regular->status);

        // 세션: 적용 대상(regular)은 토큰이 지워지고, 제외 대상(super)은 유지된다.
        // 두 단언이 짝을 이뤄야 "삭제가 아예 안 도는" 상태에서 조용히 green 이 되지 않는다.
        $this->assertNotNull($superToken);
        $this->assertSame(0, $regular->tokens()->count(), '적용 대상의 토큰은 삭제되어야 합니다');
        $this->assertSame(1, $superAdmin->tokens()->count(), '슈퍼 관리자 세션이 유지되어야 합니다');
    }

    /**
     * (회귀) 슈퍼 관리자 액터의 bulk-status 는 일반 사용자를 정상 변경한다.
     *
     * C-2 필터가 대상만 보고 슈퍼를 무조건 제외하도록 조여지면 슈퍼 actor 경로가
     * 조용히 과차단된다. 이 회귀는 예외/오류 없이 "아무도 변경되지 않음" 으로만
     * 드러나므로 정상 수행 경로를 명시 고정한다.
     *
     * @scenario actor=super_admin, operation=status, outcome=allowed
     *
     * @effects super_admin_actor_performs_same_operations
     */
    public function test_bulk_status_super_admin_actor_updates_regular_users(): void
    {
        $actor = $this->createSuperAdmin();
        $a = User::factory()->create(['status' => 'active']);
        $b = User::factory()->create(['status' => 'active']);

        $response = $this->authAs($actor)->patchJson('/api/admin/users/bulk-status', [
            'ids' => [$a->uuid, $b->uuid],
            'status' => 'blocked',
        ]);

        $response->assertOk();
        $a->refresh();
        $b->refresh();
        $this->assertSame('blocked', $a->status);
        $this->assertSame('blocked', $b->status);
    }

    /**
     * (회귀) 슈퍼 관리자 액터는 슈퍼 관리자 대상도 bulk-status 로 변경할 수 있다.
     *
     * 등급 상한은 액터 등급 기준이다(mayModify: 슈퍼 대상은 슈퍼 액터만 수정 가능).
     * 필터가 액터를 무시하고 슈퍼 대상을 일괄 제외하도록 바뀌면 이 경로가 막히므로
     * 슈퍼↔슈퍼 정상 수행을 고정한다.
     *
     * @scenario actor=super_admin, operation=status, outcome=allowed
     *
     * @effects super_admin_actor_performs_same_operations
     */
    public function test_bulk_status_super_admin_actor_can_modify_super_target(): void
    {
        $actor = $this->createSuperAdmin();
        $superTarget = $this->createSuperAdmin();

        $response = $this->authAs($actor)->patchJson('/api/admin/users/bulk-status', [
            'ids' => [$superTarget->uuid],
            'status' => 'blocked',
        ]);

        $response->assertOk();
        $superTarget->refresh();
        $this->assertSame('blocked', $superTarget->status);
    }

    // =========================================================================
    // C-2b — bulkUpdateStatus 스코프 축 재적용
    //
    // 등급 축(슈퍼 보호)과 스코프 축은 별개다. PermissionMiddleware 의 스코프 검사는
    // 라우트 모델이 resolve 될 때만 동작하므로(모델 없으면 "목록 엔드포인트" 로 보고 스킵),
    // `{user}` 파라미터가 없는 정적 bulk 라우트에서는 통째로 건너뛰어진다.
    // 상세 경로가 403 으로 막는 대상을 bulk 로는 바꿀 수 있으면 그 경로가 우회로다.
    // =========================================================================

    /**
     * self 스코프 액터가 bulk-status 로 타인을 변경하려 하면 대상에서 제외된다.
     *
     * 기본 탑재 역할 `manager` 가 정확히 이 구성이다(core.users.update = self).
     * 상세 경로 PUT /users/{other} 는 스코프 미들웨어가 403 으로 막지만, bulk 라우트는
     * 라우트 모델이 없어 그 검사가 스킵된다 — 서비스가 같은 판정을 재적용해야 한다.
     *
     * @scenario actor=sub_admin, operation=status, outcome=blocked
     *
     * @effects bulk_status_reapplies_scope_gate_for_scoped_actor, rejected_target_state_and_tokens_unchanged
     */
    public function test_self_scoped_admin_cannot_bulk_status_other_users(): void
    {
        $subAdmin = $this->createSubAdmin(['core.users.update' => 'self']);
        $other = User::factory()->create(['status' => 'active']);
        $otherToken = $other->createToken('victim-token')->plainTextToken;

        $response = $this->authAs($subAdmin)->patchJson('/api/admin/users/bulk-status', [
            'ids' => [$other->uuid],
            'status' => 'blocked',
        ]);

        $response->assertOk();

        // 스코프 밖 대상은 제외 → 상태·토큰 불변
        $other->refresh();
        $this->assertSame('active', $other->status);
        $this->assertSame(0, $response->json('data.updated_count'));
        $this->assertNotNull($otherToken);
        $this->assertSame(1, $other->tokens()->count());
    }

    /**
     * (대칭 앵커) 같은 액터·같은 대상이 상세 경로에서는 이미 403 이다.
     *
     * bulk 경로만 통과하는 비대칭을 고정하기 위해, 두 경로를 같은 테스트군에 나란히 둔다.
     *
     * @scenario actor=sub_admin, operation=modify, outcome=blocked
     *
     * @effects detail_route_enforces_scope_gate
     */
    public function test_self_scoped_admin_cannot_update_other_user_on_detail_route(): void
    {
        $subAdmin = $this->createSubAdmin(['core.users.update' => 'self']);
        $other = User::factory()->create(['status' => 'active', 'name' => '원본 이름']);

        $response = $this->authAs($subAdmin)->putJson(
            '/api/admin/users/'.$other->uuid,
            $this->updatePayload($other, ['name' => '변조된 이름'])
        );

        $response->assertStatus(403);
        $other->refresh();
        $this->assertSame('원본 이름', $other->name);
    }

    /**
     * (과차단 회귀) role 스코프 액터는 같은 역할을 가진 대상을 bulk-status 로 변경할 수 있다.
     *
     * 스코프 가드를 "비-글로벌 액터는 bulk 전면 금지" 로 조이면 이 경로가 조용히
     * 막힌다(예외 없이 updated_count=0). 스코프 판정은 액터의 유효 스코프에 따라
     * 대상별로 이뤄져야 한다 — self 는 자기만, role 은 같은 역할까지, 글로벌은 전체.
     *
     * @scenario actor=sub_admin, operation=status, outcome=allowed
     *
     * @effects bulk_status_allows_scoped_actor_within_own_scope
     */
    public function test_role_scoped_admin_can_bulk_status_user_sharing_role(): void
    {
        $subAdmin = $this->createSubAdmin(['core.users.update' => 'role']);
        $sharedRoleId = $subAdmin->roles->firstOrFail()->id;

        $peer = User::factory()->create(['status' => 'active']);
        $peer->roles()->attach($sharedRoleId, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->authAs($subAdmin)->patchJson('/api/admin/users/bulk-status', [
            'ids' => [$peer->uuid],
            'status' => 'blocked',
        ]);

        $response->assertOk();
        $peer->refresh();
        $this->assertSame('blocked', $peer->status);
        $this->assertSame(1, $response->json('data.updated_count'));
    }

    /**
     * role 스코프 액터가 자기 역할 밖 대상을 bulk-status 로 변경하려 하면 제외된다.
     *
     * self 축과 같은 결함이 role 축에도 성립하는지 고정한다 — 스코프 판정을
     * `PermissionHelper::checkScopeAccess`(상세 경로와 동일 SSoT)에 위임하지 않고
     * 자체 재구현하면 role 분기만 빠지는 형태가 나온다.
     *
     * @scenario actor=sub_admin, operation=status, outcome=blocked
     *
     * @effects bulk_status_reapplies_scope_gate_for_scoped_actor
     */
    public function test_role_scoped_admin_cannot_bulk_status_user_outside_role(): void
    {
        $subAdmin = $this->createSubAdmin(['core.users.update' => 'role']);
        $outsider = User::factory()->create(['status' => 'active']);

        $response = $this->authAs($subAdmin)->patchJson('/api/admin/users/bulk-status', [
            'ids' => [$outsider->uuid],
            'status' => 'blocked',
        ]);

        $response->assertOk();
        $outsider->refresh();
        $this->assertSame('active', $outsider->status);
        $this->assertSame(0, $response->json('data.updated_count'));
    }

    /**
     * (과차단 회귀) 글로벌 스코프 액터는 bulk-status 로 타인을 정상 변경한다.
     *
     * 스코프 가드가 액터의 유효 스코프를 무시하고 일괄 차단하면 위임 관리자의
     * 정상 일괄 작업이 통째로 무력화된다.
     *
     * @scenario actor=sub_admin, operation=status, outcome=allowed
     *
     * @effects bulk_status_allows_scoped_actor_within_own_scope
     */
    public function test_global_scoped_admin_can_bulk_status_other_users(): void
    {
        $subAdmin = $this->createSubAdmin(['core.users.update' => null]);
        $a = User::factory()->create(['status' => 'active']);
        $b = User::factory()->create(['status' => 'active']);

        $response = $this->authAs($subAdmin)->patchJson('/api/admin/users/bulk-status', [
            'ids' => [$a->uuid, $b->uuid],
            'status' => 'blocked',
        ]);

        $response->assertOk();
        $a->refresh();
        $b->refresh();
        $this->assertSame('blocked', $a->status);
        $this->assertSame('blocked', $b->status);
        $this->assertSame(2, $response->json('data.updated_count'));
    }

    // =========================================================================
    // 편차 1 — 역할 부여 게이트의 프론트 노출값(can_assign_roles) 정렬
    //
    // 역할 부여는 사용자 관리(core.users.*)의 일부이지 역할 정의 수정
    // (core.permissions.update)이 아니다. 이 플래그가 라우트 권한 SSoT 와 다른
    // 리소스를 가리키면 화면 게이트와 실제 서버 동작이 갈린다.
    // 키 존재만 단언하면 원복돼도 스위트가 green 이므로 **값**을 양방향으로 고정한다.
    // =========================================================================

    /**
     * `core.users.update` 보유자에게 can_assign_roles=true.
     *
     * @scenario actor=sub_admin, operation=assign_role, outcome=allowed
     *
     * @effects can_assign_roles_gate_follows_users_update
     */
    public function test_can_assign_roles_is_true_for_users_update_holder(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.users.update' => null,
            'core.permissions.read' => null,
        ]);

        $response = $this->authAs($subAdmin)->getJson('/api/admin/roles/active');

        $response->assertOk();
        $this->assertTrue(
            $response->json('data.abilities.can_assign_roles'),
            'core.users.update 보유자는 역할을 부여할 수 있어야 합니다'
        );
    }

    /**
     * `core.permissions.update` 만으로는 can_assign_roles=false.
     *
     * 이 플래그가 다시 core.permissions.update 로 되돌아가면 이 테스트가 red 가 된다.
     *
     * @scenario actor=sub_admin, operation=assign_role, outcome=blocked
     *
     * @effects can_assign_roles_gate_follows_users_update
     */
    public function test_can_assign_roles_is_false_with_permissions_update_only(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.permissions.update' => null,
            'core.permissions.read' => null,
        ]);

        $response = $this->authAs($subAdmin)->getJson('/api/admin/roles/active');

        $response->assertOk();
        $this->assertFalse(
            $response->json('data.abilities.can_assign_roles'),
            '역할 정의 수정 권한만으로는 사용자에게 역할을 부여할 수 없어야 합니다'
        );
    }

    // =========================================================================
    // C-3 — unlockAccount 등급 가드
    // =========================================================================

    /**
     * 부관리자는 슈퍼 관리자 계정을 잠금 해제(조작)할 수 없다.
     *
     * @scenario actor=sub_admin, operation=unlock, outcome=blocked
     *
     * @effects sub_admin_cannot_modify_super_admin
     */
    public function test_sub_admin_cannot_unlock_super_admin(): void
    {
        $subAdmin = $this->createSubAdmin(['core.users.update' => null]);
        $superAdmin = $this->createSuperAdmin();

        $response = $this->authAs($subAdmin)->postJson("/api/admin/users/{$superAdmin->uuid}/unlock");

        $response->assertStatus(403);
    }

    // =========================================================================
    // C-4 — 권한 부여 ceiling
    // =========================================================================

    /**
     * 부관리자(core.permissions.create 보유)가 자신이 보유하지 않은 권한을
     * 역할에 부여하려 하면 403.
     *
     * @scenario actor=sub_admin, operation=grant_permission, outcome=blocked
     *
     * @effects sub_admin_cannot_grant_unheld_or_broader_permission
     */
    public function test_sub_admin_cannot_grant_permission_it_does_not_hold(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.permissions.create' => null,
            'core.permissions.read' => null,
        ]);
        $deletePerm = Permission::where('identifier', 'core.users.delete')->firstOrFail();

        $response = $this->authAs($subAdmin)->postJson('/api/admin/roles', [
            'identifier' => $this->roleIdentifier(),
            'name' => ['ko' => '신규역할', 'en' => 'New Role'],
            'permissions' => [
                ['id' => $deletePerm->id, 'scope_type' => null],
            ],
        ]);

        $response->assertStatus(403);
    }

    /**
     * 상한 위반으로 거부된 역할 생성은 역할 행 자체를 남기지 않는다.
     *
     * 상한 검사가 역할 생성 **뒤**에 있으면 403 을 받은 요청이 권한 0개짜리 고아 역할을
     * 남긴다. 사용자 경로(UserService)는 이미 "가드 → 쓰기" 순서로 해결한 사안이며,
     * 역할 경로도 같은 순서여야 한다. 403 만 단언하면 이 부작용이 고정되지 않는다.
     *
     * @scenario actor=sub_admin, operation=grant_permission, outcome=blocked
     *
     * @effects rejected_role_write_leaves_no_partial_state
     */
    public function test_rejected_role_creation_leaves_no_orphan_role(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.permissions.create' => null,
            'core.permissions.read' => null,
        ]);
        $deletePerm = Permission::where('identifier', 'core.users.delete')->firstOrFail();
        $identifier = $this->roleIdentifier();

        $response = $this->authAs($subAdmin)->postJson('/api/admin/roles', [
            'identifier' => $identifier,
            'name' => ['ko' => '신규역할', 'en' => 'New Role'],
            'permissions' => [
                ['id' => $deletePerm->id, 'scope_type' => null],
            ],
        ]);

        $response->assertStatus(403);
        $this->assertNull(
            Role::where('identifier', $identifier)->first(),
            '거부된 역할 생성이 고아 역할 행을 남기면 안 됩니다'
        );
    }

    /**
     * 상한 위반으로 거부된 역할 수정은 속성 변경도 반영하지 않는다.
     *
     * 상한 검사가 속성 update **뒤**에 있으면 403 인데 name/is_active 는 이미 바뀐다.
     *
     * @scenario actor=sub_admin, operation=update_role, outcome=blocked
     *
     * @effects rejected_role_write_leaves_no_partial_state
     */
    public function test_rejected_role_update_does_not_apply_attribute_changes(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.permissions.create' => null,
            'core.permissions.read' => null,
            'core.permissions.update' => null,
        ]);
        $deletePerm = Permission::where('identifier', 'core.users.delete')->firstOrFail();

        $role = Role::create([
            'identifier' => $this->roleIdentifier(),
            'name' => json_encode(['ko' => '원본역할', 'en' => 'Original Role']),
            'description' => json_encode(['ko' => '설명', 'en' => 'Desc']),
            'extension_type' => null,
            'is_active' => true,
        ]);

        // 저장 표현(인코딩 형태)에 의존하지 않도록, 증명하려는 명제 그대로
        // "요청 전후 저장값이 동일하다" 를 단언한다.
        $nameBefore = $role->getRawOriginal('name');

        $response = $this->authAs($subAdmin)->putJson("/api/admin/roles/{$role->id}", [
            'identifier' => $role->identifier,
            'name' => ['ko' => '변조된역할', 'en' => 'Tampered Role'],
            'permissions' => [
                ['id' => $deletePerm->id, 'scope_type' => null],
            ],
        ]);

        $response->assertStatus(403);
        $role->refresh();

        $this->assertSame(
            $nameBefore,
            $role->getRawOriginal('name'),
            '거부된 역할 수정이 name 속성을 변경하면 안 됩니다'
        );
    }

    /**
     * syncPermissions 를 직접 호출해도 보호 역할(코어/확장 소유) 가드가 걸린다.
     *
     * `updateRole`·`toggleRoleStatus` 는 진입부에 보호 역할 가드를 두는데 형제 public
     * 메서드인 `syncPermissions` 만 비어 있으면, 이 서비스를 주입한 확장이
     * `syncPermissions($coreAdminRole, …)` 로 코어 역할 보호를 우회한다.
     *
     * @scenario actor=sub_admin, operation=update_role, outcome=blocked
     *
     * @effects sub_admin_cannot_modify_protected_role
     */
    public function test_sync_permissions_directly_rejects_protected_role(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.permissions.update' => null,
            'core.permissions.read' => null,
        ]);
        $this->actingAs($subAdmin);

        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        $readPerm = Permission::where('identifier', 'core.permissions.read')->firstOrFail();
        $before = $adminRole->permissions()->pluck('permissions.id')->sort()->values()->all();

        // expectException 은 throw 시점에 테스트를 끝내므로 뒤의 단언이 실행되지 않는다.
        // 형제 두 경로(고아 역할·속성 변경)에는 상태 불변 단언이 붙어 있는데 이 경로만
        // 예외 종류만 보고 있었다 — 가드가 sync 뒤로 밀리면 권한이 이미 치환된 뒤 403 이
        // 되고, 그 회귀를 예외 단언은 잡지 못한다.
        try {
            app(RoleService::class)->syncPermissions($adminRole, [
                ['id' => $readPerm->id, 'scope_type' => null],
            ]);
            $this->fail('보호 역할의 syncPermissions 는 거부되어야 합니다');
        } catch (CannotModifyProtectedRoleException) {
            // 기대 경로
        }

        $after = $adminRole->fresh()->permissions()->pluck('permissions.id')->sort()->values()->all();
        $this->assertSame($before, $after, '거부된 syncPermissions 가 권한 집합을 바꾸면 안 됩니다');
    }

    /**
     * 부관리자가 자신이 보유한 권한을 자신의 범위 이내로 부여하는 것은 허용된다(회귀).
     *
     * @scenario actor=sub_admin, operation=grant_permission, outcome=allowed
     *
     * @effects sub_admin_cannot_grant_unheld_or_broader_permission
     */
    public function test_sub_admin_can_grant_held_permission_within_scope(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.permissions.create' => null,
            'core.permissions.read' => null,
            'core.users.read' => null,
        ]);
        $readPerm = Permission::where('identifier', 'core.users.read')->firstOrFail();

        $response = $this->authAs($subAdmin)->postJson('/api/admin/roles', [
            'identifier' => $this->roleIdentifier(),
            'name' => ['ko' => '조회역할', 'en' => 'Read Role'],
            'permissions' => [
                ['id' => $readPerm->id, 'scope_type' => null],
            ],
        ]);

        $response->assertStatus(201);
    }

    /**
     * 부관리자가 자신의 범위(self)보다 넓은 범위(global)로 권한을 부여하려 하면 403.
     *
     * @scenario actor=sub_admin, operation=grant_permission, outcome=blocked
     *
     * @effects sub_admin_cannot_grant_unheld_or_broader_permission
     */
    public function test_sub_admin_cannot_grant_broader_scope_than_own(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.permissions.create' => null,
            'core.permissions.read' => null,
            'core.users.read' => 'self',
        ]);
        $readPerm = Permission::where('identifier', 'core.users.read')->firstOrFail();

        $response = $this->authAs($subAdmin)->postJson('/api/admin/roles', [
            'identifier' => $this->roleIdentifier(),
            'name' => ['ko' => '광역역할', 'en' => 'Wide Role'],
            'permissions' => [
                ['id' => $readPerm->id, 'scope_type' => null], // global = self 보다 넓음
            ],
        ]);

        $response->assertStatus(403);
    }

    /**
     * (회귀) 슈퍼 관리자는 임의 권한을 역할에 부여할 수 있다.
     *
     * @scenario actor=super_admin, operation=grant_permission, outcome=allowed
     *
     * @effects super_admin_actor_performs_same_operations
     */
    public function test_super_admin_can_grant_any_permission(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $deletePerm = Permission::where('identifier', 'core.users.delete')->firstOrFail();

        $response = $this->authAs($superAdmin)->postJson('/api/admin/roles', [
            'identifier' => $this->roleIdentifier(),
            'name' => ['ko' => '전권역할', 'en' => 'Full Role'],
            'permissions' => [
                ['id' => $deletePerm->id, 'scope_type' => null],
            ],
        ]);

        $response->assertStatus(201);
    }

    // =========================================================================
    // C-4b — 역할 재할당 ceiling (updateUser role_ids)
    // =========================================================================

    /**
     * 부관리자(core.users.update + core.permissions.update 보유)가 자신이 전부 부여할 수
     * 없는 권한을 담은 역할(admin)을 사용자에게 붙이려 하면 403 + 미부여.
     *
     * C-4 가 RoleService(역할→권한 부여)만 막으면, role_ids 경로로 고권한 역할을 통째로
     * 붙여 동일한 권한 상승을 달성할 수 있다(KVE-2026-1919 완결 — 계획 107).
     *
     * @scenario actor=sub_admin, operation=assign_role, outcome=blocked
     *
     * @effects sub_admin_cannot_assign_role_it_cannot_fully_grant
     */
    public function test_sub_admin_cannot_assign_role_it_cannot_fully_grant(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.users.update' => null,
            'core.permissions.update' => null,
        ]);
        $target = User::factory()->create(['status' => 'active']);
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();

        $response = $this->authAs($subAdmin)->putJson("/api/admin/users/{$target->uuid}", $this->updatePayload($target, [
            'role_ids' => [$adminRole->id],
        ]));

        $response->assertStatus(403);
        $target->refresh();
        $this->assertFalse($target->roles->contains('id', $adminRole->id), 'admin 역할이 부여되면 안 됩니다');
    }

    /**
     * (회귀) 슈퍼 관리자는 admin 역할을 사용자에게 부여할 수 있다.
     *
     * @scenario actor=super_admin, operation=assign_role, outcome=allowed
     *
     * @effects super_admin_actor_performs_same_operations
     */
    public function test_super_admin_can_assign_admin_role(): void
    {
        $actor = $this->createSuperAdmin();
        $target = User::factory()->create(['status' => 'active']);
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();

        $response = $this->authAs($actor)->putJson("/api/admin/users/{$target->uuid}", $this->updatePayload($target, [
            'role_ids' => [$adminRole->id],
        ]));

        $response->assertOk();
        $target->refresh();
        $this->assertTrue($target->roles->contains('id', $adminRole->id));
    }

    /**
     * (회귀) 부관리자는 자신이 전부 부여 가능한 권한만 담은 역할은 붙일 수 있다.
     *
     * @scenario actor=sub_admin, operation=assign_role, outcome=allowed
     *
     * @effects sub_admin_cannot_assign_role_it_cannot_fully_grant
     */
    public function test_sub_admin_can_assign_role_within_its_ceiling(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.users.update' => null,
            'core.permissions.update' => null,
            'core.users.read' => null,
        ]);
        $target = User::factory()->create(['status' => 'active']);

        $readOnlyRole = Role::create([
            'identifier' => 'read_only_'.substr(uniqid(), -8),
            'name' => json_encode(['ko' => '읽기역할', 'en' => 'Read Role']),
            'extension_type' => null,
            'is_active' => true,
        ]);
        $readPerm = Permission::where('identifier', 'core.users.read')->firstOrFail();
        $readOnlyRole->permissions()->sync([$readPerm->id => ['scope_type' => null, 'granted_at' => now()]]);

        $response = $this->authAs($subAdmin)->putJson("/api/admin/users/{$target->uuid}", $this->updatePayload($target, [
            'role_ids' => [$readOnlyRole->id],
        ]));

        $response->assertOk();
        $target->refresh();
        $this->assertTrue($target->roles->contains('id', $readOnlyRole->id));
    }

    /**
     * (회귀) 대상이 이미 보유한 역할을 유지하는 수정은 ceiling 에 걸리지 않는다.
     *
     * ceiling 은 새로 추가되는 역할에만 적용된다 — 기존 역할 유지는 권한 상승이 아니므로
     * 부관리자가 admin 보유 사용자의 이름을 바꾸는 정상 흐름을 막지 않는다.
     *
     * @scenario actor=sub_admin, operation=assign_role, outcome=allowed
     *
     * @effects sub_admin_cannot_assign_role_it_cannot_fully_grant
     */
    public function test_keeping_existing_high_role_is_not_blocked(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.users.update' => null,
            'core.permissions.update' => null,
        ]);
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        $target = User::factory()->create(['status' => 'active', 'name' => 'Before']);
        $target->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        $response = $this->authAs($subAdmin)->putJson("/api/admin/users/{$target->uuid}", $this->updatePayload($target->fresh(), [
            'name' => 'After',
        ]));

        $response->assertOk();
        $target->refresh();
        $this->assertSame('After', $target->name);
        $this->assertTrue($target->roles->contains('id', $adminRole->id), '기존 admin 역할이 유지되어야 합니다');
    }

    /**
     * 부관리자(core.users.create + core.permissions.update)가 신규 사용자를 만들면서
     * 자신이 전부 부여할 수 없는 역할(admin)을 붙이려 하면 403.
     *
     * 생성 경로도 수정 경로와 동일한 재할당 상한을 받아야 우회로가 되지 않는다.
     *
     * @scenario actor=sub_admin, operation=assign_role, outcome=blocked
     *
     * @effects sub_admin_cannot_assign_role_it_cannot_fully_grant
     */
    public function test_sub_admin_cannot_create_user_with_role_it_cannot_fully_grant(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.users.create' => null,
            'core.permissions.update' => null,
        ]);
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();

        $response = $this->authAs($subAdmin)->postJson('/api/admin/users', [
            'name' => '신규관리자',
            'email' => 'ceil_'.substr(uniqid(), -8).'@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role_ids' => [$adminRole->id],
        ]);

        $response->assertStatus(403);
    }

    /**
     * (회귀) 부관리자는 기본 'user' 역할을 사용자에게 부여할 수 있어야 한다.
     *
     * 기본 'user' 역할은 모든 회원이 갖는 baseline 역할이다(자동 배정 경로가
     * 심는 바로 그 역할). 그 역할의 권한(알림 self-service·본인인증)은 권한 상승
     * 벡터가 아니므로 재할당 상한에 걸려선 안 된다. core.permissions.update 를 가진
     * 부관리자가 baseline 역할을 명시 지정했다고 403 이 되면 정상 회원 생성/수정이
     * 깨진다(KVE-2026-1919 회귀). auto-assign 경로(상한 미적용)와의 비대칭도 제거한다.
     *
     * @scenario actor=sub_admin, operation=assign_role, outcome=allowed
     *
     * @effects sub_admin_can_assign_baseline_default_user_role
     */
    public function test_sub_admin_can_assign_default_user_role(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.users.update' => null,
            'core.permissions.update' => null,
        ]);
        $target = User::factory()->create(['status' => 'active']);
        $userRole = Role::where('identifier', 'user')->firstOrFail();

        $response = $this->authAs($subAdmin)->putJson("/api/admin/users/{$target->uuid}", $this->updatePayload($target, [
            'role_ids' => [$userRole->id],
        ]));

        $response->assertOk();
        $target->refresh();
        $this->assertTrue($target->roles->contains('id', $userRole->id), '기본 user 역할이 부여되어야 합니다');
    }

    /**
     * (회귀) 부관리자는 신규 사용자를 만들면서 기본 'user' 역할을 명시 지정할 수 있어야 한다.
     *
     * 생성 경로도 baseline 역할 배정을 상한에서 면제해야 한다 — 수정 경로와 동일 강도.
     *
     * @scenario actor=sub_admin, operation=assign_role, outcome=allowed
     *
     * @effects sub_admin_can_assign_baseline_default_user_role
     */
    public function test_sub_admin_can_create_user_with_default_user_role(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.users.create' => null,
            'core.permissions.update' => null,
        ]);
        $userRole = Role::where('identifier', 'user')->firstOrFail();

        $response = $this->authAs($subAdmin)->postJson('/api/admin/users', [
            'name' => '신규회원',
            'email' => 'baseline_'.substr(uniqid(), -8).'@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
            'role_ids' => [$userRole->id],
        ]);

        $response->assertStatus(201);
    }

    /**
     * (회귀) 역할 부여는 core.permissions.update(역할 정의 수정)를 요구하지 않는다.
     *
     * 부관리자가 core.users.update 만 갖고 core.permissions.update 는 없어도, 상한 이내의
     * 역할이라면 사용자에게 부여할 수 있어야 한다. 과거에는 core.permissions.update 가 없으면
     * role_ids 를 조용히 버려(silent no-op) 200 성공을 반환하면서도 역할이 바뀌지 않았다
     * (공개 제보 — 매니저 계정이 자기 수정 화면에서 하위 역할조차 부여하지 못함). 이제는
     * 역할 부여가 "사용자 관리"의 일부로 재분류되어 상한(ceiling)만으로 통제된다.
     *
     * @scenario actor=sub_admin, operation=assign_role, outcome=allowed
     *
     * @effects role_assignment_does_not_require_permissions_update
     */
    public function test_sub_admin_can_assign_role_within_ceiling_without_permissions_update(): void
    {
        // core.permissions.update 없이 core.users.update 만 보유
        $subAdmin = $this->createSubAdmin([
            'core.users.update' => null,
            'core.users.read' => null,
        ]);
        $target = User::factory()->create(['status' => 'active']);

        // 부관리자가 전부 부여 가능한 권한(core.users.read)만 담은 역할 → 상한 이내
        $readOnlyRole = Role::create([
            'identifier' => 'read_only_nopu_'.substr(uniqid(), -8),
            'name' => json_encode(['ko' => '읽기역할', 'en' => 'Read Role']),
            'extension_type' => null,
            'is_active' => true,
        ]);
        $readPerm = Permission::where('identifier', 'core.users.read')->firstOrFail();
        $readOnlyRole->permissions()->sync([$readPerm->id => ['scope_type' => null, 'granted_at' => now()]]);

        $response = $this->authAs($subAdmin)->putJson("/api/admin/users/{$target->uuid}", $this->updatePayload($target, [
            'role_ids' => [$readOnlyRole->id],
        ]));

        $response->assertOk();
        $target->refresh();
        $this->assertTrue(
            $target->roles->contains('id', $readOnlyRole->id),
            'core.permissions.update 없이도 상한 이내 역할은 부여되어야 합니다 (silent no-op 회귀 방지)'
        );
    }

    /**
     * 부관리자(core.users.update, core.permissions.update 없음)가 자신이 전부 부여할 수 없는
     * 역할(admin)을 다른 사용자에게서 **제거**하려 하면 403 + 역할 불변.
     *
     * 역할 조작 상한은 추가·제거 양방향에 대칭 적용된다. 추가만 검사하면 core.users.update 만
     * 가진 하위 관리자가 다른 관리자의 상위 역할(admin)을 박탈하는 하향 조작이 상한 없이
     * 뚫린다(KVE-2026-1919 완결 — 제거 방향). 이 결함은 예외·경고 없이 200 성공으로만
     * 드러나므로 차단 + 역할 불변을 명시 고정한다.
     *
     * @scenario actor=sub_admin, operation=remove_role, outcome=blocked
     *
     * @effects sub_admin_cannot_remove_role_above_its_ceiling, rejected_target_state_and_tokens_unchanged
     */
    public function test_sub_admin_cannot_remove_role_it_cannot_fully_grant(): void
    {
        $subAdmin = $this->createSubAdmin(['core.users.update' => null]);
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        $userRole = Role::where('identifier', 'user')->firstOrFail();
        $target = User::factory()->create(['status' => 'active']);
        $target->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        // admin 을 떼고 baseline user 만 남기려는 시도 — 제거되는 admin 이 상한 밖
        $response = $this->authAs($subAdmin)->putJson("/api/admin/users/{$target->uuid}", $this->updatePayload($target->fresh(), [
            'role_ids' => [$userRole->id],
        ]));

        $response->assertStatus(403);
        $target->refresh();
        $this->assertTrue($target->roles->contains('id', $adminRole->id), 'admin 역할이 제거되면 안 됩니다');
    }

    /**
     * (회귀) 부관리자는 자신이 전부 부여 가능한 역할은 사용자에게서 제거할 수 있다.
     *
     * 제거 방향 상한이 과차단되면 정상적인 하위 역할 정리까지 막힌다. 상한 이내 역할의
     * 제거가 정상 동작함을 명시 고정한다.
     *
     * @scenario actor=sub_admin, operation=remove_role, outcome=allowed
     *
     * @effects sub_admin_cannot_remove_role_above_its_ceiling
     */
    public function test_sub_admin_can_remove_role_within_its_ceiling(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.users.update' => null,
            'core.users.read' => null,
        ]);
        $userRole = Role::where('identifier', 'user')->firstOrFail();
        $target = User::factory()->create(['status' => 'active']);

        $readOnlyRole = Role::create([
            'identifier' => 'read_only_rm_'.substr(uniqid(), -8),
            'name' => json_encode(['ko' => '읽기역할', 'en' => 'Read Role']),
            'extension_type' => null,
            'is_active' => true,
        ]);
        $readPerm = Permission::where('identifier', 'core.users.read')->firstOrFail();
        $readOnlyRole->permissions()->sync([$readPerm->id => ['scope_type' => null, 'granted_at' => now()]]);

        $target->roles()->attach($userRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $target->roles()->attach($readOnlyRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        // read-only 역할 제거, baseline user 유지 — 제거되는 역할이 상한 이내
        $response = $this->authAs($subAdmin)->putJson("/api/admin/users/{$target->uuid}", $this->updatePayload($target->fresh(), [
            'role_ids' => [$userRole->id],
        ]));

        $response->assertOk();
        $target->refresh();
        $this->assertFalse($target->roles->contains('id', $readOnlyRole->id), '상한 이내 역할은 제거되어야 합니다');
        $this->assertTrue($target->roles->contains('id', $userRole->id), 'baseline user 역할은 유지되어야 합니다');
    }

    // =========================================================================
    // C-5 — 코어/확장 소유 역할 수정 보호
    // =========================================================================

    /**
     * 부관리자(core.permissions.update 보유)는 코어 admin 역할을 수정할 수 없다.
     *
     * @scenario actor=sub_admin, operation=update_role, outcome=blocked
     *
     * @effects sub_admin_cannot_toggle_or_update_core_admin_role
     */
    public function test_sub_admin_cannot_update_core_admin_role(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.permissions.update' => null,
            'core.permissions.read' => null,
        ]);
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();

        $response = $this->authAs($subAdmin)->putJson("/api/admin/roles/{$adminRole->id}", [
            'name' => ['ko' => '탈취관리자', 'en' => 'Hijacked'],
        ]);

        $response->assertStatus(403);
    }

    /**
     * 부관리자는 코어 admin 역할의 활성 상태를 토글할 수 없다.
     *
     * @scenario actor=sub_admin, operation=toggle_role, outcome=blocked
     *
     * @effects sub_admin_cannot_toggle_or_update_core_admin_role
     */
    public function test_sub_admin_cannot_toggle_core_admin_role(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.permissions.update' => null,
            'core.permissions.read' => null,
        ]);
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        $wasActive = $adminRole->is_active;

        $response = $this->authAs($subAdmin)->patchJson("/api/admin/roles/{$adminRole->id}/toggle-status");

        $response->assertStatus(403);
        $adminRole->refresh();
        $this->assertSame($wasActive, $adminRole->is_active);
    }

    /**
     * (회귀) 슈퍼 관리자는 코어 역할도 수정할 수 있다.
     *
     * @scenario actor=super_admin, operation=update_role, outcome=allowed
     *
     * @effects super_admin_actor_performs_same_operations
     */
    public function test_super_admin_can_update_core_role(): void
    {
        $superAdmin = $this->createSuperAdmin();
        // 코어 소유이지만 admin 외의 역할을 골라 이름 변경 (admin 은 다른 불변식이 있을 수 있음)
        $coreRole = Role::where('extension_type', ExtensionOwnerType::Core)
            ->where('identifier', '!=', 'admin')
            ->first();

        if (! $coreRole) {
            $this->markTestSkipped('코어 소유 비-admin 역할이 시드되지 않음');
        }

        $response = $this->authAs($superAdmin)->putJson("/api/admin/roles/{$coreRole->id}", [
            'is_active' => $coreRole->is_active,
        ]);

        $response->assertOk();
    }

    // =========================================================================
    // 과차단 회귀 축 (allowed) — 가드가 정상 조작까지 막지 않는지
    // =========================================================================
    //
    // 상한 가드는 "막아야 할 것을 막는가" 만큼 "막지 말아야 할 것을 통과시키는가" 도
    // 고정해야 한다. 차단 축만 있으면 가드가 과차단으로 회귀해도 스위트가 green 이다
    // (C-4b 의 baseline user 역할 회귀가 정확히 그 형태로 운영에서 먼저 드러났다).

    /**
     * 부관리자는 일반 사용자의 상태를 정상 변경할 수 있다.
     *
     * @scenario actor=sub_admin, operation=status, outcome=allowed
     *
     * @effects sub_admin_retains_normal_operations_on_non_super_targets
     */
    public function test_sub_admin_can_change_regular_user_status(): void
    {
        $subAdmin = $this->createSubAdmin(['core.users.update' => null]);
        $target = User::factory()->create(['is_super' => false, 'status' => 'active']);

        $response = $this->authAs($subAdmin)->putJson(
            "/api/admin/users/{$target->uuid}",
            $this->updatePayload($target, ['status' => 'blocked'])
        );

        $response->assertOk();
        $this->assertSame('blocked', $target->fresh()->status->value ?? $target->fresh()->status);
    }

    /**
     * 부관리자는 일반 사용자 계정을 정상 잠금 해제할 수 있다.
     *
     * @scenario actor=sub_admin, operation=unlock, outcome=allowed
     *
     * @effects sub_admin_retains_normal_operations_on_non_super_targets
     */
    public function test_sub_admin_can_unlock_regular_user(): void
    {
        $subAdmin = $this->createSubAdmin(['core.users.update' => null]);
        $target = User::factory()->create(['is_super' => false, 'status' => 'active']);

        $response = $this->authAs($subAdmin)->postJson("/api/admin/users/{$target->uuid}/unlock");

        $response->assertOk();
    }

    /**
     * 부관리자는 코어/확장 소유가 아닌 일반 역할을 정상 수정할 수 있다.
     *
     * @scenario actor=sub_admin, operation=update_role, outcome=allowed
     *
     * @effects sub_admin_retains_normal_operations_on_unprotected_roles
     */
    public function test_sub_admin_can_update_non_core_role(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.permissions.update' => null,
            'core.permissions.read' => null,
        ]);
        $role = Role::create([
            'identifier' => $this->roleIdentifier(),
            'name' => json_encode(['ko' => '일반역할', 'en' => 'Plain Role']),
            'description' => json_encode(['ko' => '보호 대상 아님', 'en' => 'Not protected']),
            'extension_type' => null,
            'is_active' => true,
        ]);

        $response = $this->authAs($subAdmin)->putJson("/api/admin/roles/{$role->id}", [
            'name' => ['ko' => '이름변경', 'en' => 'Renamed'],
        ]);

        $response->assertOk();
    }

    /**
     * 부관리자는 일반 역할의 활성 상태를 정상 토글할 수 있다.
     *
     * @scenario actor=sub_admin, operation=toggle_role, outcome=allowed
     *
     * @effects sub_admin_retains_normal_operations_on_unprotected_roles
     */
    public function test_sub_admin_can_toggle_non_core_role(): void
    {
        $subAdmin = $this->createSubAdmin([
            'core.permissions.update' => null,
            'core.permissions.read' => null,
        ]);
        $role = Role::create([
            'identifier' => $this->roleIdentifier(),
            'name' => json_encode(['ko' => '일반역할', 'en' => 'Plain Role']),
            'description' => json_encode(['ko' => '보호 대상 아님', 'en' => 'Not protected']),
            'extension_type' => null,
            'is_active' => true,
        ]);

        $response = $this->authAs($subAdmin)->patchJson("/api/admin/roles/{$role->id}/toggle-status");

        $response->assertOk();
        $this->assertFalse($role->fresh()->is_active, '일반 역할은 토글되어야 합니다');
    }

    /**
     * (회귀) 슈퍼 관리자는 슈퍼 관리자 계정도 잠금 해제할 수 있다.
     *
     * @scenario actor=super_admin, operation=unlock, outcome=allowed
     *
     * @effects super_admin_actor_performs_same_operations
     */
    public function test_super_admin_can_unlock_super_admin(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $target = $this->createSuperAdmin();

        $response = $this->authAs($superAdmin)->postJson("/api/admin/users/{$target->uuid}/unlock");

        $response->assertOk();
    }

    /**
     * (회귀) 슈퍼 관리자는 상위 역할도 제거할 수 있다 — 제거 방향 상한이 슈퍼를 막지 않는다.
     *
     * @scenario actor=super_admin, operation=remove_role, outcome=allowed
     *
     * @effects super_admin_actor_performs_same_operations
     */
    public function test_super_admin_can_remove_admin_role(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        $userRole = Role::where('identifier', 'user')->firstOrFail();

        $target = User::factory()->create(['is_super' => false, 'status' => 'active']);
        $target->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);
        $target->refresh();

        $response = $this->authAs($superAdmin)->putJson(
            "/api/admin/users/{$target->uuid}",
            $this->updatePayload($target, ['role_ids' => [$userRole->id]])
        );

        $response->assertOk();
        $this->assertFalse(
            $target->fresh()->roles->contains('id', $adminRole->id),
            '슈퍼 관리자는 상위 역할을 제거할 수 있어야 합니다'
        );
    }

    /**
     * (회귀) 슈퍼 관리자는 코어 역할의 활성 상태를 토글할 수 있다.
     *
     * deleteRole 은 슈퍼에게도 코어 역할 삭제를 막지만 toggle 은 허용한다 — 슈퍼는 이미
     * 전권(is_super)이라 권한 상승이 아니므로 양성 비대칭이다. 그 상태를 고정한다.
     *
     * @scenario actor=super_admin, operation=toggle_role, outcome=allowed
     *
     * @effects super_admin_actor_performs_same_operations
     */
    public function test_super_admin_can_toggle_core_role(): void
    {
        $superAdmin = $this->createSuperAdmin();
        $coreRole = Role::where('extension_type', ExtensionOwnerType::Core)
            ->where('identifier', '!=', 'admin')
            ->first();

        if (! $coreRole) {
            $this->markTestSkipped('코어 소유 비-admin 역할이 시드되지 않음');
        }

        $wasActive = $coreRole->is_active;

        $response = $this->authAs($superAdmin)->patchJson("/api/admin/roles/{$coreRole->id}/toggle-status");

        $response->assertOk();
        $this->assertNotSame($wasActive, $coreRole->fresh()->is_active, '코어 역할 토글이 반영되어야 합니다');
    }

    // =========================================================================
    // C-2c — 첨부 순서 변경(정적 라우트)의 스코프 축 재적용
    //
    // bulk-status 와 같은 형태다: `PATCH admin/attachments/reorder` 에는 `{attachment}`
    // 파라미터가 없어 PermissionMiddleware 의 스코프 검사가 스킵된다. 그런데 배포 기본
    // 역할 `manager` 는 core.attachments.update 를 self 스코프로 보유한다 — 이론 구성이
    // 아니라 기본값에서 성립하는 우회로였다.
    //
    // 사용자 일괄 상태변경과 달리 "제외" 가 아니라 "거부" 다. 순서는 집합 전체에 대한
    // 하나의 배열이라 일부만 반영하면 나머지와 어긋난 순서가 저장된다.
    // =========================================================================

    /**
     * self 스코프 액터는 타인 소유 첨부의 순서를 바꿀 수 없다.
     *
     * @effects attachment_reorder_reapplies_scope_gate, rejected_target_state_and_tokens_unchanged
     */
    public function test_self_scoped_admin_cannot_reorder_others_attachments(): void
    {
        $subAdmin = $this->createSubAdmin(['core.attachments.update' => 'self']);
        $other = User::factory()->create();

        $mine = Attachment::factory()->create(['created_by' => $subAdmin->id, 'order' => 1]);
        $theirs = Attachment::factory()->create(['created_by' => $other->id, 'order' => 2]);

        $response = $this->authAs($subAdmin)->patchJson('/api/admin/attachments/reorder', [
            'order' => [
                ['id' => $mine->id, 'order' => 2],
                ['id' => $theirs->id, 'order' => 1],
            ],
        ]);

        $response->assertStatus(403);

        // 거부는 전량 거부다 — 내 첨부까지 반영되지 않아야 순서가 어긋나지 않는다.
        $this->assertSame(1, $mine->fresh()->order, '거부된 요청은 어떤 순서도 바꾸지 않아야 합니다');
        $this->assertSame(2, $theirs->fresh()->order);
    }

    /**
     * (과차단 회귀) self 스코프 액터도 자기 소유 첨부는 순서를 바꿀 수 있다.
     *
     * @effects attachment_reorder_allows_in_scope_targets
     */
    public function test_self_scoped_admin_can_reorder_own_attachments(): void
    {
        $subAdmin = $this->createSubAdmin(['core.attachments.update' => 'self']);

        $first = Attachment::factory()->create(['created_by' => $subAdmin->id, 'order' => 1]);
        $second = Attachment::factory()->create(['created_by' => $subAdmin->id, 'order' => 2]);

        $response = $this->authAs($subAdmin)->patchJson('/api/admin/attachments/reorder', [
            'order' => [
                ['id' => $first->id, 'order' => 2],
                ['id' => $second->id, 'order' => 1],
            ],
        ]);

        $response->assertOk();
        $this->assertSame(2, $first->fresh()->order);
        $this->assertSame(1, $second->fresh()->order);
    }

    /**
     * (과차단 회귀) 글로벌 스코프 액터는 타인 소유 첨부도 정렬할 수 있다.
     *
     * @effects attachment_reorder_allows_in_scope_targets
     */
    public function test_global_scoped_admin_can_reorder_any_attachment(): void
    {
        $subAdmin = $this->createSubAdmin(['core.attachments.update' => null]);
        $other = User::factory()->create();

        $theirs = Attachment::factory()->create(['created_by' => $other->id, 'order' => 1]);

        $response = $this->authAs($subAdmin)->patchJson('/api/admin/attachments/reorder', [
            'order' => [['id' => $theirs->id, 'order' => 5]],
        ]);

        $response->assertOk();
        $this->assertSame(5, $theirs->fresh()->order);
    }

    // =========================================================================
    // C-2d — 메뉴 순서 변경(정적 라우트)의 스코프 축 재적용
    //
    // 첨부 순서와 같은 형태. 배포 기본 역할은 core.menus.update 를 글로벌로 주므로
    // 기본값 노출은 아니지만, 운영자가 역할 화면에서 스코프를 좁히는 순간 성립한다.
    // =========================================================================

    /**
     * self 스코프 액터는 타인이 만든 메뉴의 순서를 바꿀 수 없다.
     *
     * @effects menu_order_reapplies_scope_gate, rejected_target_state_and_tokens_unchanged
     */
    public function test_self_scoped_admin_cannot_reorder_others_menus(): void
    {
        $subAdmin = $this->createSubAdmin(['core.menus.update' => 'self']);
        $other = User::factory()->create();

        $mine = Menu::factory()->create(['created_by' => $subAdmin->id, 'order' => 1]);
        $theirs = Menu::factory()->create(['created_by' => $other->id, 'order' => 2]);

        $response = $this->authAs($subAdmin)->putJson('/api/admin/menus/order', [
            'parent_menus' => [
                ['id' => $mine->id, 'order' => 2],
                ['id' => $theirs->id, 'order' => 1],
            ],
        ]);

        $response->assertStatus(403);

        // 전량 거부 — 내 메뉴 순서도 바뀌지 않아야 트리가 어긋나지 않는다.
        $this->assertSame(1, $mine->fresh()->order, '거부된 요청은 어떤 순서도 바꾸지 않아야 합니다');
        $this->assertSame(2, $theirs->fresh()->order);
    }

    /**
     * (과차단 회귀) 글로벌 스코프 액터는 메뉴 순서를 정상 변경할 수 있다.
     *
     * @effects menu_order_allows_in_scope_targets
     */
    public function test_global_scoped_admin_can_reorder_menus(): void
    {
        $subAdmin = $this->createSubAdmin(['core.menus.update' => null]);
        $other = User::factory()->create();

        $theirs = Menu::factory()->create(['created_by' => $other->id, 'order' => 1]);

        $response = $this->authAs($subAdmin)->putJson('/api/admin/menus/order', [
            'parent_menus' => [['id' => $theirs->id, 'order' => 3]],
        ]);

        $response->assertOk();
        $this->assertSame(3, $theirs->fresh()->order);
    }

    /**
     * (형제 public 메서드 패리티) `updateMenuOrder` 를 직접 호출해도 스코프 가드가 걸린다.
     *
     * `updateMenuOrderWithHierarchy` 는 진입부에 스코프 가드를 두는데 같은 리소스를 같은
     * 방식으로 쓰는 형제 public 메서드 `updateMenuOrder` 만 비어 있으면, 이 서비스를 주입한
     * 확장이 그쪽으로 코어 스코프 보호를 우회한다. `RoleService::syncPermissions` 에 가드를
     * 넣은 것과 같은 판정이다 — 코어 내 호출부가 0 이라는 사실은 방어가 아니다.
     *
     * @effects menu_order_service_method_guard_parity
     */
    public function test_update_menu_order_directly_reapplies_scope_gate(): void
    {
        $subAdmin = $this->createSubAdmin(['core.menus.update' => 'self']);
        $other = User::factory()->create();

        $mine = Menu::factory()->create(['created_by' => $subAdmin->id, 'order' => 1]);
        $theirs = Menu::factory()->create(['created_by' => $other->id, 'order' => 2]);

        $this->actingAs($subAdmin);

        // expectException 은 throw 시점에 테스트를 끝내므로 상태 불변 단언이 실행되지 않는다.
        // 형제 경로(HTTP)에 붙어 있는 전량 거부 단언과 같은 강도로 확인한다.
        try {
            app(MenuService::class)->updateMenuOrder([$mine->id, $theirs->id]);
            $this->fail('스코프 밖 메뉴가 섞인 순서 변경은 거부되어야 합니다');
        } catch (AuthorizationException) {
            // 기대 경로
        }

        $this->assertSame(1, $mine->fresh()->order, '거부된 요청은 어떤 순서도 바꾸지 않아야 합니다');
        $this->assertSame(2, $theirs->fresh()->order);
    }

    /**
     * (과차단 회귀) self 스코프 액터가 **타인 소유 상위 메뉴 아래로** 자기 메뉴를 옮기는 것은 허용된다.
     *
     * 순서 변경 스코프 가드는 이동 항목의 **자신만** 검사한다. 착수 단계에서 `new_parent_id`
     * 까지 검사했다가 철회한 이력이 있는데(결함은 "남의 메뉴 순서를 바꾼다" 이지 "남의 메뉴
     * 아래에 못 붙인다" 가 아니다), 그 철회가 주석으로만 고정돼 있어 되돌리는 변경을 넣어도
     * 스위트가 green 이었다. 이 테스트가 그 축을 고정한다.
     *
     * @effects menu_order_move_under_others_parent_allowed
     */
    public function test_self_scoped_admin_can_move_own_menu_under_others_parent(): void
    {
        $subAdmin = $this->createSubAdmin(['core.menus.update' => 'self']);
        $other = User::factory()->create();

        $mine = Menu::factory()->create(['created_by' => $subAdmin->id, 'order' => 1, 'parent_id' => null]);
        $theirsParent = Menu::factory()->create(['created_by' => $other->id, 'order' => 2, 'parent_id' => null]);

        $response = $this->authAs($subAdmin)->putJson('/api/admin/menus/order', [
            'parent_menus' => [['id' => $mine->id, 'order' => 1]],
            'moved_items' => [['id' => $mine->id, 'new_parent_id' => $theirsParent->id]],
        ]);

        $response->assertOk();
        $this->assertSame(
            $theirsParent->id,
            $mine->fresh()->parent_id,
            '자기 메뉴를 공용/타인 상위 메뉴 아래에 다는 정상 사용이 막히면 안 됩니다'
        );
    }
}
