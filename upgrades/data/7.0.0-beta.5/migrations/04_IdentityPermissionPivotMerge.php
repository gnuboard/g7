<?php

namespace App\Upgrades\Data\V7_0_0_beta_5\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 본인인증(IDV) 관리자 권한 키 마이그레이션의 *충돌 경로* 처리.
 *
 * PermissionsApplier 가 처리하는 *단순 경로* (옛 row 만 존재) 와 본 Migration 의
 * *충돌 경로* (양쪽 row 모두 존재) 가 함께 동작하여 메시지 템플릿 패턴과 일관되도록
 * 프로바이더/정책 권한도 read / update 로 분리한다. 기존 `manage` 키는 `update` 로 변경.
 *
 * 흐름 (충돌 경로만):
 *   1. 옛 권한과 신 권한 양쪽에 부여된 role 식별 (중복 피벗 회피)
 *   2. 옛 권한에만 부여된 role 은 피벗을 신 권한으로 이동 (granted_at/by/scope_type 그대로)
 *   3. 양쪽 부여된 role 은 옛 피벗만 삭제 (신 피벗 보존)
 *   4. 옛 권한 row 삭제
 *
 * 멱등성: 옛 키 또는 신 키 부재 시 silent skip. 두 번 호출해도 무해.
 */
final class IdentityPermissionPivotMerge implements DataMigration
{
    private const RENAMES = [
        'core.admin.identity.manage' => 'core.admin.identity.providers.update',
        'core.admin.identity.policies.manage' => 'core.admin.identity.policies.update',
    ];

    public function name(): string
    {
        return 'IdentityPermissionPivotMerge';
    }

    public function run(UpgradeContext $context): void
    {
        if (! Schema::hasTable('permissions') || ! Schema::hasTable('role_permissions')) {
            $context->logger->info('[7.0.0-beta.5] permissions/role_permissions 테이블 부재 — IdentityPermissionPivotMerge skip');

            return;
        }

        $totalProcessed = 0;
        foreach (self::RENAMES as $oldIdentifier => $newIdentifier) {
            $oldRow = DB::table('permissions')->where('identifier', $oldIdentifier)->first();
            $newRow = DB::table('permissions')->where('identifier', $newIdentifier)->first();

            // 신 row 부재 경로는 PermissionsApplier 가 in-place rename 으로 이미 처리 → skip
            // 옛 row 부재는 처리할 대상 없음 → skip
            if (! $oldRow || ! $newRow) {
                continue;
            }

            $this->mergePermissionPivots(
                $context,
                (int) $oldRow->id,
                (int) $newRow->id,
                $oldIdentifier,
                $newIdentifier,
            );
            $totalProcessed++;
        }

        $context->logger->info(sprintf(
            '[7.0.0-beta.5] IDV 권한 키 충돌 경로 병합 완료 — 총 %d건 처리',
            $totalProcessed,
        ));
    }

    private function mergePermissionPivots(
        UpgradeContext $context,
        int $oldPermissionId,
        int $newPermissionId,
        string $oldIdentifier,
        string $newIdentifier,
    ): void {
        $rolesWithNew = DB::table('role_permissions')
            ->where('permission_id', $newPermissionId)
            ->pluck('role_id')
            ->all();

        $movedCount = DB::table('role_permissions')
            ->where('permission_id', $oldPermissionId)
            ->whereNotIn('role_id', $rolesWithNew)
            ->update(['permission_id' => $newPermissionId]);

        $duplicateDeleted = DB::table('role_permissions')
            ->where('permission_id', $oldPermissionId)
            ->delete();

        DB::table('permissions')
            ->where('id', $oldPermissionId)
            ->delete();

        $context->logger->info(sprintf(
            '[7.0.0-beta.5] IDV 권한 키 병합 — %s(id=%d) → %s(id=%d) — 피벗 이동 %d건, 중복 정리 %d건, 옛 row 삭제',
            $oldIdentifier,
            $oldPermissionId,
            $newIdentifier,
            $newPermissionId,
            $movedCount,
            $duplicateDeleted,
        ));
    }
}
