<?php

namespace Tests\Feature\Upgrades;

use App\Enums\ExtensionOwnerType;
use App\Enums\PermissionType;
use App\Extension\UpgradeContext;
use App\Models\Permission;
use App\Models\Role;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Upgrade_7_0_0_beta_5 의 본인인증 권한 키 마이그레이션 검증.
 *
 * 7.0.0-beta.5 도입 "버전별 데이터 스냅샷" 인프라로 재구성:
 *   - 단순 rename (신 row 부재) → PermissionsApplier 가 in-place UPDATE
 *   - 충돌 경로 (신 row 존재) → IdentityPermissionPivotMerge DataMigration
 *
 * 회귀 시나리오: beta.4 환경에서 운영자 커스텀 role 에 `core.admin.identity.manage` /
 * `core.admin.identity.policies.manage` 를 부여한 상태로 beta.5 로 업그레이드.
 *
 * 검증 핵심: `permissions.identifier` 컬럼만 UPDATE 하므로 `permission_id` 가 보존되어
 * `role_permissions` 피벗의 `granted_at` / `granted_by` / `scope_type` 도 자동 보존된다.
 */
class Upgrade_7_0_0_beta_5PermissionRenameTest extends TestCase
{
    use RefreshDatabase;

    private UpgradeContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->context = new UpgradeContext(
            fromVersion: '7.0.0-beta.4',
            toVersion: '7.0.0-beta.5',
            currentStep: '7.0.0-beta.5',
        );
    }

    /**
     * 본 PR 의 새 구조에서 IDV 권한 마이그레이션은 두 단계 — Applier 먼저, Migration 다음.
     *
     * AbstractUpgradeStep::run() 의 위임 순서 (dataSnapshot → dataMigrations) 와 동일.
     */
    private function invokeMigrate(): void
    {
        $appliersDir = base_path('upgrades/data/7.0.0-beta.5/appliers');
        $migrationsDir = base_path('upgrades/data/7.0.0-beta.5/migrations');

        require_once $appliersDir.'/PermissionsApplier.php';
        require_once $migrationsDir.'/04_IdentityPermissionPivotMerge.php';

        $applierClass = 'App\\Upgrades\\Data\\V7_0_0_beta_5\\Appliers\\PermissionsApplier';
        $migrationClass = 'App\\Upgrades\\Data\\V7_0_0_beta_5\\Migrations\\IdentityPermissionPivotMerge';

        $applier = new $applierClass(base_path('upgrades/data/7.0.0-beta.5/permissions.delta.json'));
        $applier->apply($this->context);

        $migration = new $migrationClass;
        $migration->run($this->context);
    }

    private function makeLegacyPermissionRow(string $identifier): Permission
    {
        return Permission::create([
            'identifier' => $identifier,
            'name' => ['ko' => 'legacy', 'en' => 'legacy'],
            'description' => ['ko' => '', 'en' => ''],
            'type' => PermissionType::Admin,
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
            'order' => 0,
        ]);
    }

    /**
     * 신권한 row 를 제거하여 경로 A (in-place rename) 환경 재현.
     *
     * RolePermissionSeeder 가 config/core.php 기반으로 신권한을 시드하므로,
     * 경로 A 검증을 위해 명시적으로 신권한 row 를 삭제.
     */
    private function clearNewPermissionRows(): void
    {
        DB::table('permissions')
            ->whereIn('identifier', [
                'core.admin.identity.providers.update',
                'core.admin.identity.policies.update',
            ])
            ->delete();
    }

    public function test_path_a_inplace_rename_when_new_row_absent(): void
    {
        $this->clearNewPermissionRows();
        $legacy = $this->makeLegacyPermissionRow('core.admin.identity.manage');
        $legacyId = $legacy->id;

        $this->invokeMigrate();

        $this->assertDatabaseMissing('permissions', ['identifier' => 'core.admin.identity.manage']);
        $this->assertDatabaseHas('permissions', [
            'id' => $legacyId,
            'identifier' => 'core.admin.identity.providers.update',
        ]);
    }

    public function test_path_a_policies_manage_inplace_rename(): void
    {
        $this->clearNewPermissionRows();
        $legacy = $this->makeLegacyPermissionRow('core.admin.identity.policies.manage');
        $legacyId = $legacy->id;

        $this->invokeMigrate();

        $this->assertDatabaseMissing('permissions', ['identifier' => 'core.admin.identity.policies.manage']);
        $this->assertDatabaseHas('permissions', [
            'id' => $legacyId,
            'identifier' => 'core.admin.identity.policies.update',
        ]);
    }

    public function test_path_a_pivot_preserved_with_metadata(): void
    {
        $this->clearNewPermissionRows();
        $legacy = $this->makeLegacyPermissionRow('core.admin.identity.manage');

        $role = Role::create([
            'identifier' => 'custom-idv-role-a',
            'name' => ['ko' => '커스텀 IDV', 'en' => 'Custom IDV'],
            'description' => ['ko' => '', 'en' => ''],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ]);

        $grantedAt = now()->subDays(7);
        $role->permissions()->attach($legacy->id, [
            'granted_at' => $grantedAt,
            'granted_by' => null,
            'scope_type' => 'role',
        ]);

        $this->invokeMigrate();

        $pivot = DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->where('permission_id', $legacy->id)
            ->first();

        $this->assertNotNull($pivot, '경로 A 에서 permission_id 가 보존되어 피벗 row 가 유지되어야 함');
        $this->assertSame('role', $pivot->scope_type);
        $this->assertEquals($grantedAt->toDateTimeString(), \Carbon\Carbon::parse($pivot->granted_at)->toDateTimeString());

        $role->refresh();
        $identifiers = $role->permissions->pluck('identifier')->all();
        $this->assertContains('core.admin.identity.providers.update', $identifiers);
        $this->assertNotContains('core.admin.identity.manage', $identifiers);
    }

    public function test_path_b_pivot_moved_when_new_row_already_exists(): void
    {
        // 신권한 row 는 RolePermissionSeeder 가 이미 생성한 상태 (경로 B 환경)
        $newRow = DB::table('permissions')
            ->where('identifier', 'core.admin.identity.providers.update')
            ->first();
        $this->assertNotNull($newRow, '경로 B 시나리오: 신권한 row 가 RolePermissionSeeder 에 의해 미리 존재');

        $legacy = $this->makeLegacyPermissionRow('core.admin.identity.manage');

        $role = Role::create([
            'identifier' => 'custom-idv-role-b',
            'name' => ['ko' => '커스텀 IDV B', 'en' => 'Custom IDV B'],
            'description' => ['ko' => '', 'en' => ''],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ]);

        $grantedAt = now()->subDays(3);
        $role->permissions()->attach($legacy->id, [
            'granted_at' => $grantedAt,
            'granted_by' => null,
            'scope_type' => 'self',
        ]);

        $this->invokeMigrate();

        // 옛 row 는 삭제됨
        $this->assertDatabaseMissing('permissions', ['identifier' => 'core.admin.identity.manage']);
        // 신 row 는 그대로 유지 (id 변경 없음)
        $this->assertDatabaseHas('permissions', [
            'id' => $newRow->id,
            'identifier' => 'core.admin.identity.providers.update',
        ]);

        // role 은 신권한을 보유 (피벗이 신 permission_id 로 이동됨)
        $movedPivot = DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->where('permission_id', $newRow->id)
            ->first();
        $this->assertNotNull($movedPivot, '경로 B 에서 옛 피벗이 신권한 id 로 이동되어야 함');
        $this->assertSame('self', $movedPivot->scope_type);
        $this->assertEquals($grantedAt->toDateTimeString(), \Carbon\Carbon::parse($movedPivot->granted_at)->toDateTimeString());

        // 옛 permission_id 참조 피벗은 없어야 함
        $this->assertDatabaseMissing('role_permissions', ['permission_id' => $legacy->id]);

        $role->refresh();
        $identifiers = $role->permissions->pluck('identifier')->all();
        $this->assertContains('core.admin.identity.providers.update', $identifiers);
    }

    public function test_path_b_duplicate_grant_old_pivot_dropped(): void
    {
        // 양쪽 모두 부여된 role 의 옛 피벗 row 는 단순 삭제 (신 피벗 보존)
        $newRow = DB::table('permissions')
            ->where('identifier', 'core.admin.identity.providers.update')
            ->first();
        $legacy = $this->makeLegacyPermissionRow('core.admin.identity.manage');

        $role = Role::create([
            'identifier' => 'dual-grant-role',
            'name' => ['ko' => '듀얼', 'en' => 'Dual'],
            'description' => ['ko' => '', 'en' => ''],
            'extension_type' => ExtensionOwnerType::Core,
            'extension_identifier' => 'core',
        ]);

        // 옛 + 신 권한 양쪽 부여
        $role->permissions()->attach($legacy->id, [
            'granted_at' => now()->subDays(10),
            'scope_type' => 'role',
        ]);
        $role->permissions()->attach($newRow->id, [
            'granted_at' => now()->subDays(1),
            'scope_type' => 'self',
        ]);

        $this->invokeMigrate();

        // role 은 단 1개 피벗만 보유 (신권한 측 — scope_type='self' 유지)
        $pivots = DB::table('role_permissions')
            ->where('role_id', $role->id)
            ->where('permission_id', $newRow->id)
            ->get();
        $this->assertCount(1, $pivots);
        $this->assertSame('self', $pivots->first()->scope_type);

        // 옛 row 와 옛 피벗 모두 사라짐
        $this->assertDatabaseMissing('permissions', ['id' => $legacy->id]);
        $this->assertDatabaseMissing('role_permissions', ['permission_id' => $legacy->id]);
    }

    public function test_migration_idempotent_no_legacy_rows_silent_skip(): void
    {
        // 옛 키 row 가 없는 환경 (이미 마이그레이션 됨) → 안전 silent skip
        $this->invokeMigrate();
        $this->invokeMigrate();

        $this->assertDatabaseMissing('permissions', ['identifier' => 'core.admin.identity.manage']);
        $this->assertDatabaseMissing('permissions', ['identifier' => 'core.admin.identity.policies.manage']);
    }

    public function test_migration_handles_both_legacy_keys_simultaneously(): void
    {
        $this->clearNewPermissionRows();
        $legacyProviders = $this->makeLegacyPermissionRow('core.admin.identity.manage');
        $legacyPolicies = $this->makeLegacyPermissionRow('core.admin.identity.policies.manage');

        $this->invokeMigrate();

        $this->assertDatabaseHas('permissions', [
            'id' => $legacyProviders->id,
            'identifier' => 'core.admin.identity.providers.update',
        ]);
        $this->assertDatabaseHas('permissions', [
            'id' => $legacyPolicies->id,
            'identifier' => 'core.admin.identity.policies.update',
        ]);
    }
}
