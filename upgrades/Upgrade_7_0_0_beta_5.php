<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.0.0-beta.5 업그레이드 스텝
 *
 * 본 스텝은 새 규약 ("버전별 데이터 스냅샷") 의 첫 dogfood 사례다. 모든 비즈니스 로직은
 * 본 클래스 파일이 아닌 `upgrades/data/7.0.0-beta.5/` 안에 격리된다:
 *
 *   - manifest.json + permissions.delta.json — IDV 권한 식별자 rename 카탈로그
 *   - appliers/PermissionsApplier.php — 카탈로그 단순 적용기
 *   - migrations/
 *       01_RecoverActiveExtensionDirs.php          — #347 회귀: 활성 확장 디렉토리 복구
 *       02_RecoverPendingStubFiles.php             — #347 회귀: _pending stub 재생성
 *       03_VerifyBundledLangPacksFallback.php      — #347 회귀: lang-packs/_bundled fallback
 *       04_IdentityPermissionPivotMerge.php        — IDV 권한 rename 충돌 경로 피벗 병합
 *       05_RecoverPublicStorageSymlink.php         — public/storage symlink 복구
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음.
 *
 * @upgrade-path B (spawn 자식 = 최신 beta.5 코어 메모리)
 *
 * 의존성 제약: 본 스텝은 카탈로그/변환/핫픽스 모두 `data/7.0.0-beta.5/` 안에 버전 namespace
 * 로 격리된 클래스들에 위임한다. 미래 버전에서 *그 디렉토리는 동결* (수정 금지) 되어
 * "각 스텝별 동작 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md §12 "버전별 데이터 스냅샷"
 */
class Upgrade_7_0_0_beta_5 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.0.0-beta.5/ 가 SSoT.
}
