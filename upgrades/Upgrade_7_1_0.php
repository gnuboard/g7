<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.1.0 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.1.0/` 안에 격리된다:
 *
 *   - migrations/
 *       01_BackfillNotificationDefinitionRelations.php
 *         7.1.0 에 추가된 `notification_definitions.relations`(관련 사용자 수신자로 고를 수 있는
 *         relation 키 선언)를 기존 정의에 채운다. 비어 있으면 그 정의의 「관련 사용자」 규칙을
 *         편집·저장할 수 없다.
 *
 * 실행 순서는 파일명 정렬(`sort()`)을 따른다.
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음.
 *
 * @upgrade-path 7.0.x → 7.1.0
 *
 * 상세: docs/extension/upgrade-step-guide.md §13 "버전별 데이터 스냅샷"
 */
class Upgrade_7_1_0 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.1.0/ 가 SSoT.
}
