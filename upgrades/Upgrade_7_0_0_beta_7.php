<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.0.0-beta.7 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.0.0-beta.7/` 안에 격리된다:
 *
 *   - migrations/
 *       01_FinalizeOrphanedInstallerRuntime.php
 *         beta.4~beta.6 의 finalize-env.php 자가 차단 회귀로 잔존한 storage/installer/runtime.php
 *         를 감지하여 .env 머지 + runtime.php 삭제 + state.json 정리를 인라인 수행 (이슈 #371)
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음.
 *
 * @upgrade-path 모든 경로 (beta.4 / beta.5 / beta.6 사용자가 beta.7 로 업그레이드)
 *
 * 의존성 제약: 본 스텝은 변환/핫픽스를 `data/7.0.0-beta.7/migrations/` 의 버전 namespace
 * 클래스에 위임한다. 미래 버전에서 *그 디렉토리는 동결* (수정 금지) 되어 "각 스텝별 동작
 * 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md §12 "버전별 데이터 스냅샷"
 */
class Upgrade_7_0_0_beta_7 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.0.0-beta.7/ 가 SSoT.
}
