<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.2.1 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.2.1/` 안에 격리된다:
 *
 *   - migrations/
 *       01_ClearSeoPageCacheAfterClassMergeRenderer.php
 *         봇용 페이지 캐시를 1회 전량 무효화한다. 7.2.1 봇 렌더러는 레이아웃 폭별 덮어쓰기의 덧붙이기 클래스
 *         (`classMerge`)와 조건부 클래스(`classMap`)를 적용하는데, 7.2.0 렌더러가 두 키를 무시하고 저장한 결과물이
 *         캐시 수명 동안 계속 서빙되는 것을 막는다.
 *       02_NarrowSettingsFilePermissions.php
 *         사이트 설정 파일(`storage/app/settings/*.json`) 권한에서 설정 폴더 범위를 넘는 비트만 걷어낸다. 7.2.0 까지는
 *         만든 프로세스의 umask 를 따라 일부 파일(`identity.json`)만 0664 였다. 넓히지 않아 웹호스팅 읽기는 그대로다.
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음.
 *
 * @upgrade-path 7.2.0 → 7.2.1
 *
 * 의존성 제약: 본 스텝은 변환을 `data/7.2.1/migrations/` 의 버전 namespace 클래스에 위임한다. 미래 버전에서
 * *그 디렉토리는 동결* (수정 금지) 되어 "각 스텝별 동작 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md §13 "버전별 데이터 스냅샷"
 */
class Upgrade_7_2_1 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.2.1/ 가 SSoT.
}
