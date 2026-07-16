<?php

namespace App\Upgrades\Data\V7_0_0_beta_5\Migrations;

use App\Extension\Helpers\StorageLinkHelper;
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
 * 복구 로직은 `StorageLinkHelper::ensurePublicStorageLink()` 로 일원화되어 있다 (#43 에서
 * 코어 업데이트 종료 시점과 공유). 헬퍼가 멱등이며 "부재→재생성" 케이스까지 처리하므로
 * 본 step 의 기존 동작(정상 symlink skip / 손상 디렉토리 rename+재생성 / 실패 원복)은
 * 상위호환으로 유지된다.
 *
 * V-1 안전: `StorageLinkHelper` 는 `Illuminate\Support\Facades\*` + 네이티브 함수 + 로컬
 * 로직만 사용하는 정적 헬퍼이므로, 이 step 이 이전 버전 프로세스 메모리에서 in-process
 * fallback 으로 실행되어도 stale 인스턴스 의존이 없다.
 */
final class RecoverPublicStorageSymlink implements DataMigration
{
    public function name(): string
    {
        return 'RecoverPublicStorageSymlink';
    }

    public function run(UpgradeContext $context): void
    {
        StorageLinkHelper::ensurePublicStorageLink($context->logger);
    }
}
