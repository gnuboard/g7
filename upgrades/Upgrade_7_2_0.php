<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.2.0 업그레이드 스텝
 *
 * 7.2.0 은 예약 작업을 `routes/console.php` 코드 등록에서 DB 스케줄 등록부(`schedules`)로 옮긴다. 코어 예약은
 * `config/core.php` 의 `schedules` 카탈로그, 확장 예약은 `getSchedules()` 선언이 원천이고 운영자가 관리자 >
 * 스케줄 관리에서 바꾼 값은 `user_overrides` 로 보존된다. 모든 비즈니스 로직은 `upgrades/data/7.2.0/` 안에 격리된다:
 *
 *   - migrations/
 *       00_ResyncCoreDeclarations.php
 *         코어 선언(역할·권한·메뉴·알림 정의 + 7.2.0 의 스케줄 카탈로그)을 새 코드로 한 번 더 동기화한다. 부모
 *         프로세스(7.1.x 코드)의 재동기화는 스케줄 카탈로그를 모르므로, 이 스텝이 돌아야 코어 예약 행·큐 관리 권한·
 *         「예약 작업 실패」 알림 정의가 생긴다. 운영자 수정은 보존하고 다시 돌려도 바꿀 것이 없다(멱등).
 *       01_SyncActiveExtensionSchedules.php
 *         활성 모듈·플러그인의 선언형 산출물(7.2.0 부터 스케줄 포함)을 새 코드로 재동기화한다. 이 스텝이 없으면 코어만
 *         올리고 확장을 갱신하지 않은 사이트에서 확장 예약이 등록부에 없어 돌지 않는다(옛 console.php 등록은 사라졌다).
 *       02_MigrateSitemapScheduleSetting.php
 *         SEO 설정의 사이트맵 생성 주기·시각(`seo.sitemap_schedule`·`seo.sitemap_schedule_time`)을 사이트맵 생성
 *         스케줄 행으로 옮기고 설정 키를 지운다. 기본값(매일 02:00)이면 행은 그대로 둔다.
 *       03_MigrateGeoipScheduleEnv.php
 *         환경변수 `GEOIP_SCHEDULE_FREQUENCY`·`GEOIP_SCHEDULE_TIME`(7.2.0 에서 `config/geoip.php` 블록 삭제)이 남아
 *         있으면 그 주기를 GeoIP 갱신 스케줄 행으로 옮긴다. 환경변수 파일은 고치지 않는다(남은 줄은 쓰이지 않는다).
 *
 * 직접 등록 스케줄(화면에서 운영자가 만든 행)은 손대지 않는다. 신규 테이블(`queue_job_logs`)·컬럼은 일반 마이그레이션이
 * 만들고, 설정 기본값(큐 처리 기록 보관 기간 등)은 코어 업데이트 흐름의 비파괴 동기화가 반영한다.
 *
 * 실행 순서는 파일명 정렬(`sort()`)을 따른다. 00 → 01 이 행을 만든 뒤 02·03 이 행의 cron 을 고친다.
 * 실행 로그는 위임받은 각 DataMigration 이 `$context->logger` 로 남긴다 — 본 껍데기 클래스에는 분기가 없어 남길 로그가 없다.
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음
 * (`run()` 은 final 이라 override 자체가 불가능하다).
 *
 * @upgrade-path 7.1.x → 7.2.0
 *
 * 의존성 제약: 본 스텝은 변환/핫픽스를 `data/7.2.0/migrations/` 의 버전 namespace
 * 클래스에 위임한다. 미래 버전에서 *그 디렉토리는 동결* (수정 금지) 되어 "각 스텝별 동작
 * 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md §13 "버전별 데이터 스냅샷"
 */
class Upgrade_7_2_0 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.2.0/ 가 SSoT.
}
