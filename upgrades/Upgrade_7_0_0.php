<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.0.0 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.0.0/` 안에 격리된다:
 *
 *   - migrations/
 *       01_CleanupMisregisteredLayoutExtensions.php
 *         이전 refreshLayoutExtensions 가 모듈/플러그인 확장을 모든 활성 템플릿에
 *         무차별 등록하여, admin 레이아웃 대상 확장이 user 템플릿에(또는 그 반대로)
 *         잘못 등록된 행을 정리한다. cross-template 판정으로 정상 확장은 보존하고
 *         오등록 행만 soft delete (레이아웃 stale 시 전멸 차단).
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음.
 *
 * @upgrade-path 모든 경로 (7.0.0 이전 사용자가 7.0.0 으로 업그레이드)
 *
 * 의존성 제약: 본 스텝은 변환/핫픽스를 `data/7.0.0/migrations/` 의 버전 namespace
 * 클래스에 위임한다. 미래 버전에서 *그 디렉토리는 동결* (수정 금지) 되어 "각 스텝별 동작
 * 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md "버전별 데이터 스냅샷"
 */
class Upgrade_7_0_0 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.0.0/ 가 SSoT.
}
