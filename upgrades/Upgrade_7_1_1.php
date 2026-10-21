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
 *       02_RevokeGuestScopedPermissionGrants.php
 *         비회원(guest) 역할에 권한 범위(본인·소유역할)가 붙은 부여를 회수(삭제)하고 건마다 경고 로그를 남긴다. 비회원은
 *         범위를 판정할 신원이 없어 「본인만」 이 실제로는 「전체」 였다. 회수한 식별자를 guest 의 user_overrides 에 기록해
 *         다음 동기화가 범위 없이 되살리지 않게 한다. 다시 돌려도 바꿀 것이 없다.
 *
 * 두 스텝 모두 원인 차단은 코드에 있고, 이 스텝은 이미 저장된 상태만 정리한다:
 *   - 01 — 원인 차단(새 bootstrap 의 APP_VERSION 정합, 런타임 안전망)은 코드에 있다. 스텝은 이미 꺼진 확장·언어팩의 복구만 한다.
 *   - 02 — 비회원 역할의 범위 저장은 역할 수정 요청(422)·RoleService·확장 동기화가 막는다. 스텝은 그 전에 저장된 범위 부여의 회수만 한다.
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
