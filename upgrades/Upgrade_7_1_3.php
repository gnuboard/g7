<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.1.3 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.1.3/` 안에 격리된다:
 *
 *   - migrations/
 *       01_RemoveOrphanExtensionCacheFiles.php
 *         만드는 코드도 읽는 코드도 없는 고아 캐시 파일(`bootstrap/cache/extension-schedule-commands.php`,
 *         `bootstrap/cache/extension-artifacts.json`)이 있으면 지운다. 없으면 아무것도 하지 않는다.
 *       02_RegenerateExtensionCachesWithFingerprint.php
 *         확장 오토로드 캐시와 훅 캐시를 다시 만들어 7.1.3 의 「활성 확장 파일 지문」을 싣는다. 지문이 없는 옛 캐시는
 *         확장 파일만 교체했을 때 낡은 것을 알아채지 못한다(훅 캐시는 형식 번호가 3 으로 올라 이미 스캔 폴백이다).
 *         다시 돌려도 같은 파일을 다시 쓸 뿐이다.
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음
 * (`run()` 은 final 이라 override 자체가 불가능하다).
 *
 * @upgrade-path 7.1.2 → 7.1.3
 *
 * 의존성 제약: 본 스텝은 정리를 `data/7.1.3/migrations/` 의 버전 namespace 클래스에 위임한다.
 * 미래 버전에서 *그 디렉토리는 동결* (수정 금지) 되어 "각 스텝별 동작 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md §13 "버전별 데이터 스냅샷"
 */
class Upgrade_7_1_3 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.1.3/ 가 SSoT.
}
