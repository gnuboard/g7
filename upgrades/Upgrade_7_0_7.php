<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.0.7 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.0.7/` 안에 격리된다:
 *
 *   - migrations/
 *       01_DropLeftoverActivityLogsCreatedAtIndex.php
 *         7.0.6 색인 교체 스텝의 초기 배포본이 기본 접두사(g7_)가 아닌 설치본에
 *         남긴 활동 로그의 구 정렬 색인을 정리한다. 구 색인은 새 색인의 좌측
 *         프리픽스라 쓰기 비용만 늘린다.
 *
 * 실행 순서는 파일명 정렬(`sort()`)을 따른다.
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음.
 *
 * @upgrade-path 7.0.x → 7.0.7
 *
 * 의존성 제약: 본 스텝은 변환/핫픽스를 `data/7.0.7/migrations/` 의 버전 namespace
 * 클래스에 위임한다. 미래 버전에서 *그 디렉토리는 동결* (수정 금지) 되어 "각 스텝별 동작
 * 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md §13 "버전별 데이터 스냅샷"
 */
class Upgrade_7_0_7 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.0.7/ 가 SSoT.
}
