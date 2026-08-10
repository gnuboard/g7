<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.0.6 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.0.6/` 안에 격리된다:
 *
 *   - migrations/
 *       01_NullifyUntouchedSeoCacheOverrides.php
 *         SEO 캐시 설정을 "미설정(null) = 고급 탭 값 사용" 의미로 이행 (결정 D19).
 *         옛 기본값 그대로인 키는 null 로 비우고, 다른 값이면 오버라이드로 보존한다.
 *       02_ClearSeoPageCacheAfterRendererParityFix.php
 *         봇용 페이지 캐시를 1회 전량 무효화한다. 7.0.6 이전 렌더러가 빈 본문으로
 *         저장한 결과물이 캐시 수명 동안 계속 서빙되는 것을 막는다.
 *       03_BackfillForeignKeyColumnComments.php
 *         외래키 컬럼의 비어 있는 한국어 comment 를 채운다. `->comment()` 가
 *         `->constrained()` 뒤에 체인되어 컬럼이 아닌 FK 정의에 부착되던 문제를
 *         소스에서 교정했으나, 기설치본은 마이그레이션이 재실행되지 않아 남는다.
 *       04_AddNotificationLogSortIndexes.php
 *         알림 발송 이력의 수신자명·제목 정렬 인덱스를 추가한다. 마이그레이션만으로는
 *         신규 설치에만 반영되므로 기존 사이트에 동일 인덱스를 적용한다.
 *         발송 이력이 많으면 ALTER TABLE 이 수 분 걸리며 그동안 해당 테이블 쓰기가 대기한다.
 *       05_AddTiebreakToCoreListIndexes.php
 *         코어 목록 인덱스에 고유 키 tiebreak 를 더한다. 정렬이 비고유 컬럼만으로
 *         끝나면 동률 구간의 페이지 경계가 흔들려 같은 행이 중복 노출된다.
 *         위 04 와 같은 이유로 ALTER TABLE 이 오래 걸릴 수 있어 마지막에 실행한다.
 *
 * 실행 순서는 파일명 정렬(`sort()`)을 따른다. 부작용이 없고 빠른 스텝(01~03)을 앞에 두고,
 * 테이블 락을 오래 잡는 인덱스 추가(04~05)를 뒤에 배치했다 — 인덱스 스텝이 지연·실패해도
 * 앞선 교정은 이미 적용된 상태가 된다.
 *
 * 본 클래스는 `AbstractUpgradeStep` 의 default `run()` 에 위임 — 별도 override 없음.
 *
 * @upgrade-path 7.0.x → 7.0.6
 *
 * 의존성 제약: 본 스텝은 변환/핫픽스를 `data/7.0.6/migrations/` 의 버전 namespace
 * 클래스에 위임한다. 미래 버전에서 *그 디렉토리는 동결* (수정 금지) 되어 "각 스텝별 동작
 * 100% 동일 보장" invariant 가 성립.
 *
 * 상세: docs/extension/upgrade-step-guide.md §13 "버전별 데이터 스냅샷"
 */
class Upgrade_7_0_6 extends AbstractUpgradeStep
{
    // 모든 로직 위임 — data/7.0.6/ 가 SSoT.
}
