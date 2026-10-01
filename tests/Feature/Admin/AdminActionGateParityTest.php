<?php

namespace Tests\Feature\Admin;

use App\Enums\ExtensionOwnerType;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 관리자 화면 「조작 가능 여부」 표시와 서버 수락 판정의 일치(#703) — 역할·회원 축
 *
 * 화면은 응답의 `abilities`/`is_deletable` 로 버튼을 켜고 끈다. 그 값이 서버 가드
 * (보호 역할 가드·등급 상한·요청 검증)와 다른 판정을 내리면, 화면은 누를 수 있는데
 * 서버가 403/422 로 거절하는 상태가 된다. 각 테스트는 "응답이 알려 주는 판정" 과
 * "같은 요청을 실제로 보냈을 때의 서버 판정" 을 한 쌍으로 고정한다.
 *
 * 시나리오 매니페스트: tests/scenarios/admin-action-gate-parity.yaml
 */
class AdminActionGateParityTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 역할·권한 시드를 채웁니다(코어 admin/user 역할과 권한 식별자).
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
    }

    /**
     * 슈퍼 관리자 사용자를 생성합니다 (is_super + admin 역할).
     *
     * @return User 슈퍼 관리자
     */
    private function createSuperAdmin(): User
    {
        $user = User::factory()->create(['is_super' => true, 'status' => 'active']);
        $adminRole = Role::where('identifier', 'admin')->firstOrFail();
        $user->roles()->attach($adminRole->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 지정한 권한만 가진 비-슈퍼 운영자를 생성합니다.
     *
     * @param  array<int, string>  $permissions  보유시킬 권한 식별자(전부 글로벌 범위)
     * @return User 운영자
     */
    private function createOperator(array $permissions): User
    {
        $user = User::factory()->create(['is_super' => false, 'status' => 'active']);

        $role = Role::create([
            'identifier' => 'operator_'.uniqid(),
            'name' => json_encode(['ko' => '운영자', 'en' => 'Operator']),
            'description' => json_encode(['ko' => '운영자', 'en' => 'Operator']),
            'extension_type' => null,
            'is_active' => true,
        ]);

        $pivot = [];
        foreach ($permissions as $identifier) {
            $pivot[Permission::where('identifier', $identifier)->firstOrFail()->id] = [
                'scope_type' => null,
                'granted_at' => now(),
            ];
        }
        $role->permissions()->sync($pivot);

        $user->roles()->attach($role->id, ['assigned_at' => now(), 'assigned_by' => null]);

        return $user->fresh();
    }

    /**
     * 사용자로 인증한 요청 빌더를 반환합니다.
     *
     * @param  User  $user  인증할 사용자
     * @return static 헤더가 설정된 테스트 인스턴스
     */
    private function authAs(User $user): static
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.$user->createToken('test-token')->plainTextToken,
            'Accept' => 'application/json',
        ]);
    }

    /**
     * 사용자가 직접 만든(보호되지 않은) 역할을 생성합니다.
     *
     * @return Role 역할
     */
    private function createCustomRole(): Role
    {
        return Role::create([
            'identifier' => 'custom_'.substr(uniqid(), -8),
            'name' => json_encode(['ko' => '사용자 역할', 'en' => 'Custom Role']),
            'description' => json_encode(['ko' => '', 'en' => '']),
            'extension_type' => null,
            'is_active' => true,
        ]);
    }

    /**
     * 모듈이 소유한 역할을 생성합니다.
     *
     * @return Role 역할
     */
    private function createModuleOwnedRole(): Role
    {
        return Role::create([
            'identifier' => 'module_owned_'.substr(uniqid(), -8),
            'name' => json_encode(['ko' => '모듈 역할', 'en' => 'Module Role']),
            'description' => json_encode(['ko' => '', 'en' => '']),
            'extension_type' => ExtensionOwnerType::Module,
            'extension_identifier' => 'sirsoft-sample',
            'is_active' => true,
        ]);
    }

    /**
     * 역할 목록 응답에서 id 로 행을 찾습니다.
     *
     * @param  array<int, array<string, mixed>>  $rows  목록 행
     * @param  int  $id  역할 id
     * @return array<string, mixed> 행
     */
    private function rowOf(array $rows, int $id): array
    {
        foreach ($rows as $row) {
            if ((int) $row['id'] === $id) {
                return $row;
            }
        }

        $this->fail("역할 {$id} 가 목록 응답에 없습니다.");
    }

    /**
     * 코어·확장 소유 역할은 슈퍼 관리자에게도 「삭제」 능력이 꺼져 있어야 한다 —
     * 서버는 그 역할의 삭제를 누구에게나 403 으로 거절한다.
     *
     * @scenario defect=role_delete_protected
     *
     * @effects role_row_delete_ability_matches_server_delete_guard
     *
     * @return void 반환값 없음
     */
    public function test_protected_role_rows_expose_can_delete_false_matching_server_rejection(): void
    {
        $super = $this->createSuperAdmin();
        $coreRole = Role::where('identifier', 'admin')->firstOrFail();
        $moduleRole = $this->createModuleOwnedRole();
        $customRole = $this->createCustomRole();

        $response = $this->authAs($super)->getJson('/api/admin/roles?per_page=100');
        $response->assertOk();
        $rows = $response->json('data.data');

        $this->assertFalse($this->rowOf($rows, $coreRole->id)['abilities']['can_delete'], '코어 역할은 삭제 능력이 꺼져야 합니다.');
        $this->assertFalse($this->rowOf($rows, $moduleRole->id)['abilities']['can_delete'], '확장 소유 역할은 삭제 능력이 꺼져야 합니다.');
        $this->assertTrue($this->rowOf($rows, $customRole->id)['abilities']['can_delete'], '직접 만든 역할은 삭제할 수 있어야 합니다(과차단 금지).');

        // 서버 판정이 표시와 같은지 — 꺼진 것은 거절, 켜진 것은 수락
        $this->authAs($super)->deleteJson("/api/admin/roles/{$moduleRole->id}")->assertStatus(403);
        $this->authAs($super)->deleteJson("/api/admin/roles/{$customRole->id}")->assertOk();
    }

    /**
     * 비-슈퍼 운영자에게 보호 역할(코어·확장 소유)의 수정·사용 여부 토글 능력은 꺼져 있어야 한다 —
     * 서버는 그 조작을 403 으로 거절한다. 직접 만든 역할은 그대로 켜져 있어야 한다.
     *
     * @scenario defect=role_modify_protected
     *
     * @effects role_row_update_and_toggle_abilities_match_protected_role_guard
     *
     * @return void 반환값 없음
     */
    public function test_protected_role_rows_expose_update_and_toggle_false_for_non_super_operator(): void
    {
        $operator = $this->createOperator(['core.permissions.read', 'core.permissions.update']);
        $coreRole = Role::where('identifier', 'admin')->firstOrFail();
        $moduleRole = $this->createModuleOwnedRole();
        $customRole = $this->createCustomRole();

        $rows = $this->authAs($operator)->getJson('/api/admin/roles?per_page=100')->assertOk()->json('data.data');

        foreach ([$coreRole, $moduleRole] as $protected) {
            $row = $this->rowOf($rows, $protected->id);
            $this->assertFalse($row['abilities']['can_update'], "보호 역할 {$protected->identifier} 은 수정 능력이 꺼져야 합니다.");
            $this->assertFalse($row['abilities']['can_toggle_status'], "보호 역할 {$protected->identifier} 은 토글 능력이 꺼져야 합니다.");
        }

        $customRow = $this->rowOf($rows, $customRole->id);
        $this->assertTrue($customRow['abilities']['can_update']);
        $this->assertTrue($customRow['abilities']['can_toggle_status']);

        // 상세(수정 폼이 읽는 응답)도 같은 판정이어야 한다
        $detail = $this->authAs($operator)->getJson("/api/admin/roles/{$moduleRole->id}")->assertOk();
        $this->assertFalse($detail->json('data.abilities.can_update'));

        // 서버 판정 — 꺼진 것은 거절, 켜진 것은 수락
        $this->authAs($operator)->patchJson("/api/admin/roles/{$moduleRole->id}/toggle-status")->assertStatus(403);
        $this->authAs($operator)->patchJson("/api/admin/roles/{$customRole->id}/toggle-status")->assertOk();
    }

    /**
     * 슈퍼 관리자에게는 보호 역할의 수정·토글 능력이 그대로 켜져 있어야 한다(과차단 금지).
     *
     * @scenario defect=role_modify_protected
     *
     * @effects super_admin_keeps_protected_role_update_ability
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_keeps_update_and_toggle_on_protected_roles(): void
    {
        $super = $this->createSuperAdmin();
        $moduleRole = $this->createModuleOwnedRole();

        $rows = $this->authAs($super)->getJson('/api/admin/roles?per_page=100')->assertOk()->json('data.data');
        $row = $this->rowOf($rows, $moduleRole->id);

        $this->assertTrue($row['abilities']['can_update']);
        $this->assertTrue($row['abilities']['can_toggle_status']);
    }

    /**
     * 비-슈퍼 운영자가 슈퍼 관리자 회원을 보면 「수정」·「역할 부여」 능력이 꺼져 있어야 한다 —
     * 서버(등급 상한)는 그 저장을 403 으로 거절한다. 일반 회원은 그대로 켜져 있어야 한다.
     *
     * @scenario defect=user_edit_super_admin
     *
     * @effects user_update_ability_matches_rank_ceiling
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_target_exposes_can_update_false_for_non_super_operator(): void
    {
        $operator = $this->createOperator(['core.users.read', 'core.users.update']);
        $super = $this->createSuperAdmin();
        $normal = User::factory()->create(['is_super' => false, 'status' => 'active']);

        $superDetail = $this->authAs($operator)->getJson("/api/admin/users/{$super->uuid}")->assertOk();
        $this->assertFalse($superDetail->json('data.abilities.can_update'), '슈퍼 관리자 대상은 수정 능력이 꺼져야 합니다.');
        $this->assertFalse($superDetail->json('data.abilities.can_assign_roles'), '슈퍼 관리자 대상은 역할 부여 능력도 꺼져야 합니다.');

        $normalDetail = $this->authAs($operator)->getJson("/api/admin/users/{$normal->uuid}")->assertOk();
        $this->assertTrue($normalDetail->json('data.abilities.can_update'), '일반 회원은 수정할 수 있어야 합니다(과차단 금지).');

        // 서버 판정 — 꺼진 것은 거절
        $userRoleId = Role::where('identifier', 'user')->value('id') ?? Role::where('identifier', 'admin')->value('id');
        $this->authAs($operator)->putJson("/api/admin/users/{$super->uuid}", [
            'name' => $super->name,
            'email' => $super->email,
            'role_ids' => $super->roles->pluck('id')->all() ?: [$userRoleId],
        ])->assertStatus(403);
    }

    /**
     * 회원 목록의 행도 상세와 같은 등급 상한을 따른다 — 목록의 수정 버튼·일괄 역할 부여가 슈퍼 관리자 행에서
     * 켜져 있으면 누른 뒤 403 만 받는다(#703 브라우저 실측: 상세는 false, 목록은 true 로 갈렸다).
     *
     * @scenario defect=user_edit_super_admin
     *
     * @effects user_list_update_ability_matches_rank_ceiling
     *
     * @return void 반환값 없음
     */
    public function test_super_admin_row_in_user_list_exposes_can_update_false_for_non_super_operator(): void
    {
        $operator = $this->createOperator(['core.users.read', 'core.users.update']);
        $super = $this->createSuperAdmin();
        $normal = User::factory()->create(['is_super' => false, 'status' => 'active']);

        $rows = $this->authAs($operator)->getJson('/api/admin/users?per_page=100&search_field=all&search_keyword='.urlencode($super->email))
            ->assertOk()
            ->json('data.data');
        $superRow = collect($rows)->firstWhere('uuid', $super->uuid);
        $this->assertNotNull($superRow, '목록에서 슈퍼 관리자 행을 찾지 못했습니다.');
        $this->assertFalse($superRow['abilities']['can_update'], '목록의 슈퍼 관리자 행도 수정 능력이 꺼져야 합니다.');
        $this->assertFalse($superRow['abilities']['can_assign_roles'], '목록의 슈퍼 관리자 행도 역할 부여 능력이 꺼져야 합니다.');

        $normalRows = $this->authAs($operator)->getJson('/api/admin/users?per_page=100&search_field=all&search_keyword='.urlencode($normal->email))
            ->assertOk()
            ->json('data.data');
        $normalRow = collect($normalRows)->firstWhere('uuid', $normal->uuid);
        $this->assertTrue($normalRow['abilities']['can_update'], '일반 회원 행은 수정할 수 있어야 합니다(과차단 금지).');
    }

    /**
     * 역할 부여 권한(core.users.update) 없이 회원 생성 권한만 가진 운영자는 역할 칸이 잠긴 채
     * 등록한다 — 서버가 역할을 필수로 요구하면 늘 422 다. 역할을 싣지 않은 등록은 기본 역할로
     * 만들어져야 한다.
     *
     * @scenario defect=user_create_without_role_assign
     *
     * @effects create_without_roles_assigns_default_role
     *
     * @return void 반환값 없음
     */
    public function test_create_user_without_roles_gets_default_role(): void
    {
        $operator = $this->createOperator(['core.users.read', 'core.users.create']);

        $response = $this->authAs($operator)->postJson('/api/admin/users', [
            'name' => '새 회원',
            'email' => 'new-member-'.uniqid().'@example.com',
            'password' => 'Str0ng!Passw0rd#2026',
            'password_confirmation' => 'Str0ng!Passw0rd#2026',
        ]);

        $response->assertCreated();

        $created = User::where('email', $response->json('data.email'))->firstOrFail();
        $this->assertSame(['user'], $created->roles()->pluck('identifier')->all(), '역할을 싣지 않은 등록은 기본 역할(user)을 받아야 합니다.');
    }

    /**
     * 비-슈퍼 운영자의 일괄 상태 변경(활성·비활성·차단)에 슈퍼 관리자가 섞이면 서버는 그 대상을
     * 조용히 뺀다 — 그 결과가 `failed_count`·`failed_reasons` 로 보고되어야 화면이 부분 결과를
     * 안내할 수 있다.
     *
     * @scenario defect=bulk_status_partial_result
     *
     * @effects bulk_status_reports_excluded_targets_as_failed
     *
     * @return void 반환값 없음
     */
    public function test_bulk_status_reports_rank_excluded_targets_as_failed(): void
    {
        $operator = $this->createOperator(['core.users.read', 'core.users.update']);
        $super = $this->createSuperAdmin();
        $normal = User::factory()->create(['is_super' => false, 'status' => 'active']);

        $response = $this->authAs($operator)->patchJson('/api/admin/users/bulk-status', [
            'ids' => [$super->uuid, $normal->uuid],
            'status' => 'inactive',
        ]);

        $response->assertOk();
        $this->assertSame(1, $response->json('data.updated_count'));
        $this->assertSame(1, $response->json('data.failed_count'), '제외된 슈퍼 관리자는 실패 건수로 보고되어야 합니다.');
        $this->assertNotEmpty($response->json('data.failed_reasons'), '제외 사유가 함께 실려야 합니다.');

        $this->assertSame('active', $super->fresh()->status, '슈퍼 관리자 상태는 그대로여야 합니다.');
        $this->assertSame('inactive', $normal->fresh()->status);
    }
}
