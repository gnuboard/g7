<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.1.0 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.1.0/` 안에 격리된다:
 *
 *   - migrations/
 *       01_ShiftCoreMenuOrderForAnalytics.php
 *         기존 설치본의 코어 관리자 메뉴 순서를 방문자 통계 자리만큼 밀어 낸다.
 *         7.1.0 에서 「방문자 통계」가 대시보드 다음(order 2)에 들어가는데, 메뉴
 *         동기화는 정의를 하나씩 반영하므로 그 시점에 기존 「환경설정」도 아직 2 라
 *         두 메뉴가 같은 순위로 겹친다(정렬이 order 단독이라 표시 순서가 정해지지
 *         않는다). 동기화 전에 기존 행의 자리를 먼저 비워 그 겹침을 없앤다. 운영자가
 *         관리자 UI 에서 순서를 지정한 메뉴는 보존한다.
 *
 * 신규 테이블 11개(방문자 통계 원장·집계·운영)는 일반 마이그레이션이 만들고, 설정
 * 기본값·권한·메뉴 정의·알림 정의는 코어 업데이트 흐름의 비파괴 동기화가 반영하므로
 * 별도 변환이 필요 없다.
 *
 * 실행 순서는 파일명 정렬(`sort()`)을 따른다. 실행 로그는 위임받은 각 DataMigration 이
 * `$context->logger` 로 남긴다 — 본 껍데기 클래스에는 분기가 없어 남길 로그가 없다.
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음
 * (`run()` 은 final 이라 override 자체가 불가능하다).
 *
 * @upgrade-path 7.0.x → 7.1.0
 *
 * 의존성 제약: 본 스텝은 변환/핫픽스를 `data/7.1.0/migrations/` 의 버전 namespace
 * 클래스에 위임한다. 미래 버전에서 *그 디렉토리는 동결* (수정 금지) 되어 "각 스텝별 동작
 * 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md §13 "버전별 데이터 스냅샷"
 */
class Upgrade_7_1_0 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.1.0/ 가 SSoT.
}
