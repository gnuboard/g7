<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.1.1 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.1.1/` 안에 격리된다:
 *
 *   - migrations/
 *       01_RecoverFalselyAutoDeactivatedExtensions.php
 *         7.0.x → 7.1.0 업데이트 뒤 「코어 버전 호환성 문제」 로 잘못 꺼진 확장을 한 번 다시 켠다(#145, #137). 7.0.0~7.0.10
 *         부모가 옛 APP_VERSION 을 env 로 물려줘 config 캐시에 옛 버전이 구워졌고, 업데이트 뒤 첫 프로세스가 `>=7.1.0` 을
 *         요구하는 확장을 `incompatible_core` 로 껐다. 기록된 요구 버전을 이번 대상 버전이 충족하는 행만 매니저의 일반 활성화로
 *         켜고(진짜 비호환은 그대로), 그때 함께 꺼진 언어팩도 켠다. 평소의 「재호환 확장 자동 재활성화 없음」(#302) 원칙의
 *         1회 예외다. 다시 돌려도 바꿀 것이 없다.
 *
 * 원인 차단(새 bootstrap 의 APP_VERSION 정합, 런타임 안전망)은 코드에 있으므로 이 스텝은 이미 피해를 입은 사이트의 복구만 맡는다.
 * 실행 로그는 위임받은 DataMigration 이 `$context->logger` 로 남긴다 — 본 껍데기 클래스에는 분기가 없어 남길 로그가 없다.
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음
 * (`run()` 은 final 이라 override 자체가 불가능하다).
 *
 * @upgrade-path 7.1.0 → 7.1.1
 *
 * 의존성 제약: 본 스텝은 변환/핫픽스를 `data/7.1.1/migrations/` 의 버전 namespace
 * 클래스에 위임한다. 미래 버전에서 *그 디렉토리는 동결* (수정 금지) 되어 "각 스텝별 동작
 * 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md §13 "버전별 데이터 스냅샷"
 */
class Upgrade_7_1_1 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.1.1/ 가 SSoT.
}
