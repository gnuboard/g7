<?php

namespace App\Upgrades\Data\Ext\Modules\SirsoftBoard\V1_2_1\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 신고 관리 권한에 남은 범위(본인만·소유역할) 부여 값을 비웁니다.
 *
 * 1.2.1 부터 신고 관리 권한(`sirsoft-board.reports.view`·`reports.manage`)에는 범위가 없다. 범위 메타
 * (`resource_route_key`·`owner_key`)는 모듈 업데이트의 권한 동기화가 비우고, 판정 코드는 더 이상 범위를
 * 보지 않는다. 그런데 역할에 저장해 둔 `scope_type`(self·role)은 남아 — 역할 화면·역할 응답에는 「본인만」
 * 으로 보이고 코어 업데이트·시드 때마다 「강제 수단 없는 범위 부여」 경고가 반복된다. 실제 동작(전체)과
 * 같게 값을 비운다.
 *
 * 삭제하지 않는다 — 그 역할은 이미 전체 범위로 신고를 다뤄 왔으므로 권한을 거두면 운영이 멈춘다.
 * idempotent: 비울 행이 없으면 아무것도 하지 않는다(두 번째 실행 0건).
 * V-1 안전: Facades\DB/Schema 만 사용하고 Permission·Role 모델을 참조하지 않는다. 식별자는 1.2.1 시점 리터럴.
 */
class ClearReportPermissionScopes implements DataMigration
{
    /** 1.2.1 시점 신고 관리 권한 식별자 동결본 */
    private const PERMISSIONS = [
        'sirsoft-board.reports.view',
        'sirsoft-board.reports.manage',
    ];

    /**
     * 마이그레이션 이름을 반환합니다.
     *
     * @return string 마이그레이션 이름
     */
    public function name(): string
    {
        return 'ClearReportPermissionScopes';
    }

    /**
     * 신고 관리 권한 부여 행의 scope_type 을 비웁니다.
     *
     * @param  UpgradeContext  $context  업그레이드 컨텍스트
     * @return void 반환값 없음
     */
    public function run(UpgradeContext $context): void
    {
        foreach (['permissions', 'roles', 'role_permissions'] as $table) {
            if (! Schema::hasTable($table)) {
                $context->logger->warning("[board:1.2.1] {$table} 테이블 미존재 — 신고 권한 범위 정리 스킵");

                return;
            }
        }

        if (! Schema::hasColumn('role_permissions', 'scope_type')) {
            $context->logger->info('[board:1.2.1] role_permissions.scope_type 컬럼 없음 — 신고 권한 범위 정리 대상 없음');

            return;
        }

        $rows = DB::table('role_permissions')
            ->join('permissions', 'permissions.id', '=', 'role_permissions.permission_id')
            ->join('roles', 'roles.id', '=', 'role_permissions.role_id')
            ->whereIn('permissions.identifier', self::PERMISSIONS)
            ->whereNotNull('role_permissions.scope_type')
            ->orderBy('role_permissions.role_id')
            ->get([
                'role_permissions.role_id',
                'role_permissions.permission_id',
                'roles.identifier as role_identifier',
                'permissions.identifier as permission_identifier',
                'role_permissions.scope_type',
            ]);

        if ($rows->isEmpty()) {
            $context->logger->info('[board:1.2.1] 범위가 지정된 신고 관리 권한 부여 없음 — 정리 0건');

            return;
        }

        foreach ($rows as $row) {
            DB::table('role_permissions')
                ->where('role_id', $row->role_id)
                ->where('permission_id', $row->permission_id)
                ->update(['scope_type' => null, 'updated_at' => now()]);

            $context->logger->info('[board:1.2.1] 신고 관리 권한 범위 비움 — 이제 전체 범위', [
                'role' => $row->role_identifier,
                'permission' => $row->permission_identifier,
                'previous_scope_type' => $row->scope_type,
            ]);
        }

        $context->logger->info("[board:1.2.1] 신고 관리 권한 범위 정리 {$rows->count()}건");
    }
}
