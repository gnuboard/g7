<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.1.0 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.1.0/` 안에 격리된다:
 *
 *   - migrations/
 *       01_BackfillNotificationDefinitionRelations.php
 *         7.1.0 에 추가된 `notification_definitions.relations`(관련 사용자 수신자로 고를 수 있는
 *         relation 키 선언)를 기존 정의에 채운다. 비어 있으면 그 정의의 「관련 사용자」 규칙을
 *         편집·저장할 수 없다.
 *       01_ShiftCoreMenuOrderForAnalytics.php
 *         기존 설치본의 코어 관리자 메뉴 순서를 방문자 통계 자리만큼 밀어 낸다.
 *         7.1.0 에서 「방문자 통계」가 대시보드 다음(order 2)에 들어가는데, 메뉴
 *         동기화는 정의를 하나씩 반영하므로 그 시점에 기존 「환경설정」도 아직 2 라
 *         두 메뉴가 같은 순위로 겹친다(정렬이 order 단독이라 표시 순서가 정해지지
 *         않는다). 동기화 전에 기존 행의 자리를 먼저 비워 그 겹침을 없앤다. 운영자가
 *         관리자 UI 에서 순서를 지정한 메뉴는 보존한다.
 *       02_RehashLayoutOriginalContent.php
 *         레이아웃·확장 조각의 원본 해시를 7.1.0 정규화(빈 객체 = 빈 배열)로 다시 적는다. 7.0.x 는
 *         빈 객체가 있는 레이아웃을 설치 직후부터 "사용자 수정본"으로 오판해 `keep` 업데이트가 새 판을
 *         반영하지 않았다. 설치 뒤 아무도 고치지 않은 행만 바꾸고 운영자 수정본은 그대로 둔다.
 *       03_PruneFalseUserOverrides.php
 *         값을 바꾸지 않은 저장이 남긴 거짓 "운영자 수정" 표식을 지운다(#727). 본인인증 정책 편집 창은 보낸 칸
 *         전부를, 알림·본인인증 메시지 템플릿은 표기만 다른 값(공백·빈 문자열·[]/null)을 수정으로 기록해
 *         새 버전 교정이 막혀 있었다. 동결한 7.1.0 코어 선언값(`data/7.1.0/*.snapshot.json` — identity_policies·
 *         notification_definitions·identity_messages)과 같은 칸의 표식만 지우고, 표식이 비면 「기본」 으로 되돌린다.
 *         실행 시점의 config 가 아니라 동결 사본과 비교하므로 여러 버전을 건너뛰어도 결과가 같다.
 *       04_BackfillTemplateOverrideOriginals.php
 *         7.0.x 가 원본 해시·원본 본문 없이 등록한 템플릿 오버라이드 레이아웃(`layouts/overrides/**`) 행에 병합 기준을
 *         채운다(#727 C-1). 원본 정보가 없는 행은 편집기에서 만든 화면으로 판정돼 keep 업데이트가 새 판을 영영
 *         반영하지 못했다. 활성 디렉토리의 오버라이드 파일과 이름이 맞는 행만 채우고, 본문과 편집기 화면은 그대로 둔다.
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
