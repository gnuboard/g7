<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.0.9 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.0.9/` 안에 격리된다:
 *
 *   - migrations/
 *       01_ResyncActiveLanguagePackTranslations.php
 *         활성 언어팩의 seed 번역을 DB JSON 컬럼에 재동기화한다. 7.0.9 이전의
 *         [기본값 복원] 결함(복원 기본값에 언어팩 로케일 미포함)과 코어 시더 필터
 *         주입 불능으로 이미 소실된 팩 번역(ja 등)을 병합 복구한다. 병합은 운영자
 *         수정(user_overrides)을 보존하며 멱등이다.
 *
 * 실행 순서는 파일명 정렬(`sort()`)을 따른다.
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음.
 *
 * @upgrade-path 7.0.x → 7.0.9
 *
 * 의존성 제약: 본 스텝은 변환/핫픽스를 `data/7.0.9/migrations/` 의 버전 namespace
 * 클래스에 위임한다. 미래 버전에서 *그 디렉토리는 동결* (수정 금지) 되어 "각 스텝별 동작
 * 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md §13 "버전별 데이터 스냅샷"
 */
class Upgrade_7_0_9 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.0.9/ 가 SSoT.
}
