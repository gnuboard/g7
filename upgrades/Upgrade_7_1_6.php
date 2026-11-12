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
 *       02_FinalizeResidualInstallerRuntime.php
 *         설치가 끝난 사이트에 남은 인스톨러 런타임 파일(`storage/installer/runtime.php`)을 정리한다. 이 파일이 남아 있으면
 *         `.env` 대신 쓰여 서버를 옮기며 `.env` 를 고쳐도 반영되지 않았다. 파일의 값을 `.env` 로 옮긴 뒤(빈 칸은 채우고,
 *         DB 정보가 다르면 접속해 보고 정하며, APP_KEY 는 지금까지 쓰인 값을 채택) 지운다. 바꾼 것이 있으면 `.env` 를
 *         `.env.bak-7.1.6-<시각>` 으로 남긴다. 기록에는 키 이름과 결정만 남긴다. 파일이 없거나 설치 중이면 아무것도 하지 않는다.
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
