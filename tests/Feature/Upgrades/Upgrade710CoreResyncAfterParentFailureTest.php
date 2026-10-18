<?php

namespace Tests\Feature\Upgrades;

use App\Extension\Helpers\ExtensionRoleSyncHelper;
use App\Extension\UpgradeContext;
use App\Models\Permission;
use App\Models\Role;
use App\Upgrades\Data\V7_1_0\Migrations\ResyncCoreDeclarations;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 7.0.11 → 7.1.0 에서 부모의 코어 역할·권한 재동기화가 실패하고 자식이 재동기화를 건너뛴 사이트를 복구한다
 * (7.1.0 출시 점검 CU-3, W3 리허설 실측으로 확정).
 *
 * 부모(7.0.11 코드)는 옛 `App\Models\Role`(HasUserOverrides 에 planUpgrade 없음)을 이미 메모리에 올린 채 Step 9
 * 재동기화에서 새 파일 `ExtensionRoleSyncHelper` 를 불러 `Call to undefined method Role::planUpgrade()` 로 실패하고
 * (경고만), 자식 `core:execute-upgrade-steps` 는 `G7_UPDATE_IN_PROGRESS=1` 이면 재동기화를 건너뛴다. 그래서 새
 * 권한(`core.profile.read`·`core.analytics.read`·`core.member-notifications.read`)이 어느 역할에도 연결되지 않아
 * 회원 프로필 API·관리자 방문자 통계·회원 알림함이 403 이었다.
 *
 * 대책 (a) 7.1.0 데이터 스텝이 새 코드(자식)에서 코어 선언 재동기화를 1회 멱등 실행, (c) 역할 동기화 헬퍼가
 * planUpgrade 가 없는 옛 모델에서도 운영자 수정을 보존하며 동작.
 */
class Upgrade710CoreResyncAfterParentFailureTest extends TestCase
{
    use DatabaseTransactions;

    /** @var array<string, string> 새 권한 → 받아야 할 역할 */
    private const NEW_GRANTS = [
        'core.profile.read' => 'user',
        'core.analytics.read' => 'admin',
        'core.member-notifications.read' => 'admin',
    ];

    /**
     * 코어 역할·권한 정의를 시딩하고 7.1.0 데이터 스텝을 불러옵니다.
     *
     * @return void 반환값 없음
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        require_once base_path('upgrades/data/7.1.0/migrations/00_ResyncCoreDeclarations.php');
    }

    /**
     * 부모 재동기화 실패(새 권한·역할 연결 없음) + 자식 건너뜀 상태에서, 7.1.0 데이터 스텝이 새 권한을 만들고 역할에 잇는다.
     *
     * @scenario parent_resync=failed, child_resync=skipped
     *
     * @effects data_step_resyncs_core_declarations_after_parent_failure
     *
     * @return void 반환값 없음
     */
    public function test_data_step_restores_new_permission_grants(): void
    {
        // 부모 실패 재현: 7.0.11 에 없던 권한 행과 역할 연결이 만들어지지 않은 상태
        $ids = Permission::whereIn('identifier', array_keys(self::NEW_GRANTS))->pluck('id');
        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        Permission::whereIn('id', $ids)->delete();
        foreach (self::NEW_GRANTS as $permission => $role) {
            $this->assertFalse($this->granted($role, $permission), '전제 — 새 권한 연결이 없어야 한다');
        }

        // 운영자가 고친 역할 이름은 재동기화 뒤에도 남아야 한다
        $user = Role::where('identifier', 'user')->firstOrFail();
        DB::table('roles')->where('id', $user->id)->update([
            'name' => json_encode(['ko' => '운영자가 고친 회원', 'en' => 'Member'], JSON_UNESCAPED_UNICODE),
            'user_overrides' => json_encode(['name.ko']),
        ]);

        (new ResyncCoreDeclarations)->run(new UpgradeContext('7.0.11', '7.1.0', '7.1.0'));

        foreach (self::NEW_GRANTS as $permission => $role) {
            $this->assertTrue($this->granted($role, $permission), "{$role} 역할에 {$permission} 가 다시 연결돼야 한다");
        }
        $this->assertSame('운영자가 고친 회원', json_decode((string) DB::table('roles')->where('identifier', 'user')->value('name'), true)['ko'] ?? null, '운영자가 고친 역할 이름은 보존돼야 한다');

        // 멱등 — 두 번째 실행은 행 수를 바꾸지 않는다
        $count = DB::table('role_permissions')->count();
        (new ResyncCoreDeclarations)->run(new UpgradeContext('7.0.11', '7.1.0', '7.1.0'));
        $this->assertSame($count, DB::table('role_permissions')->count());
    }

    /**
     * planUpgrade 가 없는 옛 역할 모델(7.0.11 부모 메모리)에서도 역할 갱신 계산이 운영자 수정 칸을 보존하며 동작한다.
     *
     * @scenario parent_resync=legacy_role_model, child_resync=skipped
     *
     * @effects role_sync_falls_back_without_plan_upgrade
     *
     * @return void 반환값 없음
     */
    public function test_role_sync_plan_falls_back_for_legacy_role_model(): void
    {
        $legacy = new class extends Model
        {
            protected $casts = ['user_overrides' => 'array', 'name' => 'array', 'description' => 'array'];
        };
        $legacy->setRawAttributes([
            'name' => json_encode(['ko' => '운영자 이름', 'en' => 'Old']),
            'description' => json_encode(['ko' => '설명', 'en' => 'Desc']),
            'is_active' => false,
            'user_overrides' => json_encode(['name.ko', 'is_active']),
        ]);
        $this->assertFalse(method_exists($legacy, 'planUpgrade'), '전제 — 옛 모델에는 planUpgrade 가 없다');

        $plan = ExtensionRoleSyncHelper::planRoleUpgrade($legacy, [
            'name' => ['ko' => '새 이름', 'en' => 'New'],
            'description' => ['ko' => '새 설명', 'en' => 'New desc'],
            'is_active' => true,
        ]);

        $this->assertSame(['ko' => '운영자 이름', 'en' => 'New'], $plan['name'], '운영자가 고친 언어 칸만 보존하고 나머지는 새 값');
        $this->assertSame(['ko' => '새 설명', 'en' => 'New desc'], $plan['description']);
        $this->assertArrayNotHasKey('is_active', $plan, '운영자가 고친 칸은 갱신하지 않는다');
    }

    /**
     * 역할이 권한을 가졌는지 봅니다.
     *
     * @param  string  $role  역할 식별자
     * @param  string  $permission  권한 식별자
     * @return bool 연결돼 있으면 true
     */
    private function granted(string $role, string $permission): bool
    {
        return DB::table('role_permissions')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->where('roles.identifier', $role)
            ->where('permissions.identifier', $permission)
            ->exists();
    }
}
