<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.1.0 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.1.0/` 안에 격리된다:
 *
 *   - migrations/
 *       01_MovePublicStorageBrokenBackupsOutOfDocroot.php
 *         7.0.x 가 `public/storage` 손상 복구 때 문서 루트 **안**(`public/storage.broken.*`)에
 *         남긴 공개 파일 사본을 문서 루트 밖으로 옮긴다. 그 위치에 있는 동안 그 사본은
 *         제2 URL 로 계속 서빙되어, 운영자가 지운 공개 파일이 여전히 열린다.
 *       02_SeedDeployFragmentLedger.php
 *         `deploy/**` 서버 설정 조각의 해시 원장(`storage/app/core-update/deploy-fragments.json`)이
 *         없는 설치본에 현재 디스크(사이드카 없는 조각 = 릴리즈 판본)의 해시로 원장을 씨앗한다.
 *         원장이 없으면 다음 업데이트가 전 조각을 「판정 불가 → 보존 + .dist」 로 본다.
 *
 * 실행 순서는 파일명 정렬(`sort()`)을 따른다.
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음.
 *
 * @upgrade-path 7.0.x → 7.1.0
 *
 * 의존성 제약: 본 스텝은 변환/핫픽스를 `data/7.1.0/migrations/` 의 버전 namespace
 * 클래스에 위임한다. 미래 버전에서 *그 디렉토리는 동결* (수정 금지) 되어 "각 스텝별 동작
 * 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md §13 "버전별 데이터 스냅샷"
 */
class Upgrade_7_1_0 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.1.0/ 가 SSoT.
}
