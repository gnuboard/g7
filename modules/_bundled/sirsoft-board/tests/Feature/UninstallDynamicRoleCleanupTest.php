<?php

namespace Modules\Sirsoft\Board\Tests\Feature;

use App\Models\Permission;
use App\Models\Role;
use Illuminate\Support\Facades\DB;
use Modules\Sirsoft\Board\Module;
use Modules\Sirsoft\Board\Tests\ModuleTestCase;

/**
 * 모듈 제거 시 동적 역할 정리 누락 회귀 테스트.
 *
 * 재현 대상 결함: `Role::where(...)->each(callback)` 은 Eloquent 의 OFFSET 기반
 * chunk 순회(기본 1000건)다. 콜백이 순회 대상 행을 삭제하므로 두 번째 페이지의
 * OFFSET 1000 이 이미 줄어든 결과 집합을 지나쳐 남은 행을 건너뛴다.
 *
 * 따라서 동적 역할이 1000건을 넘는 사이트에서 모듈을 제거하면 초과분이 그대로
 * 남는다. `chunkById()`(키셋 순회) 로 바꾸면 삭제와 무관하게 전건이 처리된다.
 *
 * 시드 건수는 Eloquent `each()` 의 기본 청크 크기(1000)보다 커야 결함이 드러나므로
 * 1100건을 사용한다.
 */
class UninstallDynamicRoleCleanupTest extends ModuleTestCase
{
    /**
     * 시드 건수 (Eloquent each() 기본 청크 1000 초과 필수)
     */
    private const SEED_COUNT = 1100;

    /**
     * 권한/사용자 피벗을 연결할 역할 수 (뒤쪽 = OFFSET skip 구간)
     */
    private const PIVOT_TAIL_COUNT = 50;

    /**
     * 시드 역할 식별자 프리픽스 (정리 대상 식별용)
     */
    private const IDENTIFIER_PREFIX = 'chunkskip';

    private Module $module;

    protected function setUp(): void
    {
        parent::setUp();

        $this->module = new Module;
        $this->purgeSeededRoles();
    }

    /**
     * ModuleTestCase 는 프로세스당 1회 `migrate:fresh`(DDL) 를 실행하므로 그 케이스의
     * 트랜잭션이 암묵적으로 커밋된다 — 시드 행이 롤백되지 않고 다음 케이스로 새어
     * identifier unique 충돌을 일으킨다. 케이스 전후로 명시 정리한다.
     */
    protected function tearDown(): void
    {
        $this->purgeSeededRoles();

        parent::tearDown();
    }

    /**
     * 이 테스트가 시드한 역할과 그 피벗을 제거합니다.
     *
     * @return void
     */
    private function purgeSeededRoles(): void
    {
        $roleIds = DB::table('roles')
            ->where('identifier', 'LIKE', '%.'.self::IDENTIFIER_PREFIX.'%')
            ->pluck('id')
            ->all();

        if ($roleIds === []) {
            return;
        }

        DB::table('role_permissions')->whereIn('role_id', $roleIds)->delete();
        DB::table('user_roles')->whereIn('role_id', $roleIds)->delete();
        DB::table('roles')->whereIn('id', $roleIds)->delete();
    }

    /**
     * 게시판별 동적 역할을 시드합니다.
     *
     * @return void
     */
    private function seedDynamicRoles(): void
    {
        $rows = [];

        for ($i = 0; $i < self::SEED_COUNT; $i++) {
            $rows[] = [
                'identifier' => 'sirsoft-board.chunkskip'.$i.'.manager',
                'name' => json_encode(['ko' => '게시판 관리자 '.$i, 'en' => 'Board Manager '.$i]),
                'extension_type' => 'module',
                'extension_identifier' => 'sirsoft-board',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        foreach (array_chunk($rows, 200) as $batch) {
            DB::table('roles')->insert($batch);
        }
    }

    /**
     * 동적 역할 잔여 건수를 반환합니다.
     *
     * @return int 잔여 건수
     */
    private function remainingDynamicRoleCount(): int
    {
        return Role::where('extension_type', 'module')
            ->where('extension_identifier', 'sirsoft-board')
            ->count();
    }

    public function test_uninstall_removes_every_dynamic_role_beyond_the_first_chunk(): void
    {
        $this->seedDynamicRoles();

        $this->assertSame(
            self::SEED_COUNT,
            $this->remainingDynamicRoleCount(),
            '시드 직후에는 동적 역할이 시드 건수만큼 존재해야 합니다.'
        );

        $this->assertTrue($this->module->uninstall());

        $this->assertSame(
            0,
            $this->remainingDynamicRoleCount(),
            'OFFSET 순회로 인해 삭제되지 않고 남은 동적 역할이 있습니다 (each → chunkById 필요).'
        );
    }

    public function test_uninstall_detaches_permissions_of_roles_in_the_skipped_range(): void
    {
        $this->seedDynamicRoles();

        $permission = Permission::firstOrCreate(
            ['identifier' => 'sirsoft-board.chunkskip.test'],
            ['name' => ['ko' => '테스트 권한', 'en' => 'Test Permission'], 'type' => 'admin']
        );

        $tailRoleIds = Role::where('extension_identifier', 'sirsoft-board')
            ->orderByDesc('id')
            ->limit(self::PIVOT_TAIL_COUNT)
            ->pluck('id')
            ->all();

        foreach ($tailRoleIds as $roleId) {
            DB::table('role_permissions')->insert([
                'role_id' => $roleId,
                'permission_id' => $permission->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $this->module->uninstall();

        $this->assertSame(
            0,
            DB::table('role_permissions')->whereIn('role_id', $tailRoleIds)->count(),
            '삭제된 역할의 권한 피벗이 남아 있습니다.'
        );
    }

    public function test_uninstall_keeps_roles_owned_by_other_extensions(): void
    {
        $this->seedDynamicRoles();

        DB::table('roles')->insert([
            'identifier' => 'sirsoft-other.chunkskip.manager',
            'name' => json_encode(['ko' => '타 확장 역할', 'en' => 'Other Extension Role']),
            'extension_type' => 'module',
            'extension_identifier' => 'sirsoft-other',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->module->uninstall();

        $this->assertTrue(
            Role::where('extension_identifier', 'sirsoft-other')->exists(),
            '다른 확장이 소유한 역할은 삭제되지 않아야 합니다.'
        );
    }
}
