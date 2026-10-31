<?php

namespace App\Upgrades\Data\V7_1_0\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 관리자 역할이 없는 관리자 메뉴에 관리자 역할(read)을 붙입니다.
 *
 * 7.0.x 의 메뉴 수정은 역할을 전부 빼고 저장하는 것을 막지 않았고, 화면은 역할이 0개인 메뉴를
 * 「모든 역할」 로 표기했습니다. 실제로는 사이드바가 역할이 붙은 메뉴만 보여 주므로 그 메뉴는
 * 관리자에게도 보이지 않아, 되돌릴 화면조차 사라진 상태였습니다. 7.1.0 부터 관리자 역할은
 * 모든 메뉴에 항상 포함되므로(메뉴 수정·역할 폼·일괄 지정 모두 관리자 역할을 뺄 수 없다),
 * 기존 설치본에서 관리자 역할이 빠진 메뉴를 한 번 채웁니다.
 *
 * 운영자가 의도적으로 관리자 역할을 뺀 메뉴도 채워집니다 — 관리자 역할은 모든 메뉴에 항상
 * 접근한다는 7.1.0 규칙의 의도된 결과입니다. 다른 역할 할당은 건드리지 않습니다.
 *
 * idempotent: 이미 관리자 역할이 있는 메뉴는 대상이 아니라 두 번째 실행은 0건입니다.
 *
 * V-1 안전: 모델·Enum 을 참조하지 않고 DB 파사드와 리터럴만 씁니다.
 */
class BackfillAdminRoleOnMenus implements DataMigration
{
    /**
     * 마이그레이션 식별자를 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'BackfillAdminRoleOnMenus';
    }

    /**
     * 관리자 역할이 없는 메뉴에 관리자 역할을 붙입니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void
     */
    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('menus') || ! Schema::hasTable('role_menus') || ! Schema::hasTable('roles')) {
            $context->logger->info('[core:7.1.0] menus/role_menus/roles 테이블 부재 — 관리자 역할 백필 스킵');

            return;
        }

        $adminRoleId = DB::table('roles')->where('identifier', 'admin')->value('id');

        if ($adminRoleId === null) {
            $context->logger->info('[core:7.1.0] admin 역할 부재 — 관리자 역할 백필 스킵');

            return;
        }

        $menuIds = DB::table('menus')
            ->whereNotExists(function ($query) use ($adminRoleId) {
                $query->select(DB::raw(1))
                    ->from('role_menus')
                    ->whereColumn('role_menus.menu_id', 'menus.id')
                    ->where('role_menus.role_id', $adminRoleId)
                    // 사이드바는 read 행으로만 판정한다 — 다른 유형만 있는 행은 없는 것과 같다.
                    ->where('role_menus.permission_type', 'read');
            })
            ->pluck('id')
            ->all();

        if ($menuIds === []) {
            $context->logger->info('[core:7.1.0] 관리자 역할이 빠진 메뉴 없음 — 스킵');

            return;
        }

        $now = now();
        $rows = array_map(static fn ($menuId): array => [
            'role_id' => $adminRoleId,
            'menu_id' => $menuId,
            'permission_type' => 'read',
            'created_at' => $now,
            'updated_at' => $now,
        ], $menuIds);

        DB::table('role_menus')->insert($rows);

        $context->logger->info(
            '[core:7.1.0] 관리자 역할이 빠진 메뉴에 관리자 역할 부착 — '.count($menuIds).'건 (메뉴 ID: '.implode(', ', $menuIds).')'
        );
    }
}
