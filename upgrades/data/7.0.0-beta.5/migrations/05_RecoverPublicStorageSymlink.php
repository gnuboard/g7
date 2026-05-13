<?php

namespace App\Upgrades\Data\V7_0_0_beta_5\Migrations;

use App\Extension\Upgrade\DataMigration;
use App\Extension\UpgradeContext;

/**
 * `public/storage` symlink 손상 자동 복구 (단발성).
 *
 * beta.4 → beta.5 업그레이드 시점의 백업/복원은 부모 beta.4 메모리의 `FilePermissionHelper`
 * 를 사용하므로 symlink 보존 분기 (beta.5+ 도입) 가 미적용 → 롤백 발생 시 `public/storage`
 * symlink 가 일반 디렉토리로 변질되는 결함이 잔존한다. 본 step 이 단발성 자동 복구
 * (rename 보존 + symlink 재생성) 로 보완한다.
 *
 * 안전 가드 (false positive 차단):
 *   1. `is_link($publicStorage)` 가 true → 이미 정상 symlink, silent skip (멱등)
 *   2. `! is_dir($publicStorage) || ! is_dir($storageSource)` → Laravel storage:link
 *      컨벤션을 따르지 않는 환경, skip
 *   3. rename 으로 `.broken.{timestamp}` 백업 후 symlink 재생성 — `rm -rf` 미사용
 *   4. symlink 생성 실패 (Windows 권한 부족 등) → rename 백업을 원위치로 복원
 */
final class RecoverPublicStorageSymlink implements DataMigration
{
    public function name(): string
    {
        return 'RecoverPublicStorageSymlink';
    }

    public function run(UpgradeContext $context): void
    {
        $publicStorage = public_path('storage');
        $storageSource = storage_path('app/public');

        // 1) 정상 symlink → skip (멱등)
        if (is_link($publicStorage)) {
            return;
        }

        // 2) public/storage 가 일반 디렉토리이면서 Laravel storage:link source 가 존재할 때만
        //    손상 후보로 판정 — 운영자가 Laravel 표준을 따르고 있다는 강한 신호.
        //    둘 중 하나라도 부재하면 운영자 의도적 구성으로 간주하여 skip.
        if (! is_dir($publicStorage) || ! is_dir($storageSource)) {
            return;
        }

        // 3) 안전 보존 — rm -rf 대신 rename 으로 백업 후 symlink 재생성.
        //    false positive (운영자가 의도적으로 일반 디렉토리 사용) 시에도 데이터 손실 없음.
        $backup = $publicStorage.'.broken.'.date('YmdHis');
        if (! @rename($publicStorage, $backup)) {
            $context->logger->warning('[7.0.0-beta.5] public/storage rename 실패 — 자동 복구 skip', [
                'path' => $publicStorage,
            ]);

            return;
        }

        if (! @symlink($storageSource, $publicStorage)) {
            // symlink 생성 실패 (Windows SeCreateSymbolicLink 권한 부족 등) → rename 원복
            @rename($backup, $publicStorage);
            $context->logger->warning('[7.0.0-beta.5] public/storage symlink 재생성 실패 — rename 원복', [
                'target' => $storageSource,
            ]);

            return;
        }

        $context->logger->info('[7.0.0-beta.5] public/storage symlink 자동 복구 완료 — 백업 디렉토리 검증 후 수동 삭제 권장', [
            'backup' => $backup,
            'target' => $storageSource,
        ]);
    }
}
