<?php

namespace App\Upgrades\Data\V7_0_0_beta_5\Appliers;

use App\Extension\Upgrade\SnapshotApplier;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 7.0.0-beta.5 시점의 permissions 카탈로그 delta 적용기.
 *
 * 본 버전은 IDV 권한 식별자 rename 2건만 다룬다. added/removed 는 비어있다.
 *
 * rename 의 *단순 경로* (옛 row 만 존재 → 신 row 부재) 를 in-place UPDATE 로 처리한다.
 * 신 row 충돌 경로 (양쪽 모두 존재) 는 IdentityPermissionPivotMerge DataMigration 이 담당.
 *
 * 멱등성:
 *   - added 는 updateOrInsert (재실행 안전)
 *   - removed 는 where(identifier)->delete (옛 row 없으면 silent skip)
 *   - renamed 는 신 row 존재 시 silent skip (Migration 에 위임)
 *
 * V-1 안전:
 *   - Illuminate\Support\Facades\DB / Schema 만 사용
 *   - app(*Service|*Manager|*Repository::class) 호출 없음
 *   - 다른 버전 namespace 참조 없음
 *   - Database\Seeders 참조 없음
 */
final class PermissionsApplier implements SnapshotApplier
{
    public function __construct(private readonly string $jsonPath) {}

    public function apply(UpgradeContext $context): void
    {
        if (! Schema::hasTable('permissions')) {
            $context->logger->info('[7.0.0-beta.5] permissions 테이블 부재 — PermissionsApplier skip');

            return;
        }

        $delta = json_decode(
            file_get_contents($this->jsonPath),
            true,
            flags: JSON_THROW_ON_ERROR,
        );

        $now = now();

        foreach ($delta['added'] ?? [] as $row) {
            if (empty($row['identifier'])) {
                continue;
            }

            DB::table('permissions')->updateOrInsert(
                ['identifier' => $row['identifier']],
                [
                    'type' => $row['type'] ?? null,
                    'category' => $row['category'] ?? null,
                    'name' => isset($row['name']) ? json_encode($row['name']) : null,
                    'updated_at' => $now,
                    'created_at' => $now,
                ],
            );
        }

        foreach ($delta['removed'] ?? [] as $identifier) {
            DB::table('permissions')->where('identifier', $identifier)->delete();
        }

        $renameApplied = 0;
        foreach ($delta['renamed'] ?? [] as $rename) {
            $from = $rename['from'] ?? null;
            $to = $rename['to'] ?? null;
            if (! is_string($from) || ! is_string($to)) {
                continue;
            }

            $oldRow = DB::table('permissions')->where('identifier', $from)->first();
            $newRow = DB::table('permissions')->where('identifier', $to)->first();

            if ($oldRow && ! $newRow) {
                DB::table('permissions')
                    ->where('id', $oldRow->id)
                    ->update([
                        'identifier' => $to,
                        'updated_at' => $now,
                    ]);

                $context->logger->info(sprintf(
                    '[7.0.0-beta.5] permissions in-place rename — %s → %s (id=%d, 피벗 자동 보존)',
                    $from,
                    $to,
                    $oldRow->id,
                ));
                $renameApplied++;
            }
        }

        if ($renameApplied > 0) {
            $context->logger->info(sprintf(
                '[7.0.0-beta.5] PermissionsApplier 완료 — in-place rename %d건 (충돌 경로는 Migration 위임)',
                $renameApplied,
            ));
        }
    }
}
