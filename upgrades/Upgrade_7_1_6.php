<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.1.6 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.1.6/` 안에 격리된다:
 *
 *   - migrations/
 *       01_WritePublicDiskHtaccess.php
 *         공개 디스크 루트(`storage/app/public`)에 웹서버 보호 규칙 파일(`.htaccess`)이 없으면 만든다.
 *         `/storage` 직접 주소 응답에 nosniff 를 붙이고, 래스터 이미지가 아닌 파일은 내려받기로만 열리게 하며,
 *         PHP 실행을 막는다. 이미 자동 생성된 규칙 파일이 있으면 건드리지 않는다. 손으로 만든 파일은
 *         `.htaccess.g7-backup` 으로 사본을 남기고 바꾼다. 공개 디스크 디렉터리가 없으면 아무것도 하지 않는다.
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음
 * (`run()` 은 final 이라 override 자체가 불가능하다).
 *
 * @upgrade-path 7.1.5 → 7.1.6
 *
 * 의존성 제약: 본 스텝은 작업을 `data/7.1.6/migrations/` 의 버전 namespace 클래스에 위임한다.
 * 미래 버전에서 *그 디렉토리는 동결* (수정 금지) 되어 "각 스텝별 동작 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md §13 "버전별 데이터 스냅샷"
 */
class Upgrade_7_1_6 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.1.6/ 가 SSoT.
}
