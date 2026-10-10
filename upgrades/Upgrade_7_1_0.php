<?php

namespace App\Upgrades;

use App\Extension\AbstractUpgradeStep;

/**
 * 코어 7.1.0 업그레이드 스텝
 *
 * 모든 비즈니스 로직은 본 클래스 파일이 아닌 `upgrades/data/7.1.0/` 안에 격리된다:
 *
 *   - migrations/
 *       00_ResyncCoreDeclarations.php
 *         코어 역할·권한·메뉴·알림 정의 선언을 새 코드로 한 번 더 동기화한다. 7.0.x 부모는 옛 역할 모델을 메모리에
 *         올린 채 재동기화하다 실패하고(경고만), 자식은 재동기화를 건너뛰어 7.1.0 의 새 권한이 어느 역할에도 연결되지
 *         않았다(내 정보·방문자 통계·회원 알림함 403). 운영자 수정은 보존하고, 정상이던 사이트에서는 바꿀 것이 없다.
 *       01_BackfillNotificationDefinitionRelations.php
 *         7.1.0 에 추가된 `notification_definitions.relations`(관련 사용자 수신자로 고를 수 있는
 *         relation 키 선언)를 기존 정의에 채운다. 비어 있으면 그 정의의 「관련 사용자」 규칙을
 *         편집·저장할 수 없다.
 *       01_ShiftCoreMenuOrderForAnalytics.php
 *         기존 설치본의 코어 관리자 메뉴 순서를 방문자 통계 자리만큼 밀어 낸 최종 순서로 맞춘다.
 *         7.1.0 에서 「방문자 통계」가 대시보드 다음(order 2)에 들어가는데, 메뉴
 *         동기화는 정의를 하나씩 반영하므로 도중에 기존 「환경설정」과 같은 순위로 겹칠 수 있다.
 *         이 스텝은 메뉴 동기화 뒤에 돌아, 동기화가 이미 반영했으면 바꿀 것이 없고(no-op) 남은
 *         어긋남만 기대 순서로 보정한다. 운영자가 관리자 UI 에서 순서를 지정한 메뉴는 보존한다.
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
 *       05_BackfillAdminRoleOnMenus.php
 *         관리자 역할(read)이 빠진 관리자 메뉴에 관리자 역할을 붙인다. 7.0.x 는 메뉴 역할을 전부 빼고 저장할 수
 *         있었고 화면은 그 메뉴를 「모든 역할」 로 표기했지만, 실제로는 관리자 사이드바에서도 사라졌다. 7.1.0 부터
 *         관리자 역할은 모든 메뉴에 항상 포함된다. 다른 역할 할당은 건드리지 않는다.
 *       05_RekeyLegacyLayoutExtensionFragments.php
 *         조각 식별자(`fragment_key`) 백필이 대상 이름으로 채운 옛 확장 조각 행을 소스 파일 키로 옮긴다(#712). 옮기지
 *         않으면 템플릿 오버라이드 옛 행은 첫 갱신에서 정리돼 운영자 수정이 사라지고, 같은 대상에 조각이 여럿인 확장의
 *         옛 행은 새 행과 함께 두 번 주입되거나 사라진다. 후보 파일이 하나면 옮기고(새 키 행이 있으면 수정본 우선 병합),
 *         여럿이면 원본 해시로 고르며, 고를 수 없는 운영자 수정본은 건드리지 않고 경고만 남긴다.
 *       06_PurgeOrphanPluginLayouts.php
 *         삭제된 플러그인이 남긴 레이아웃 행을 지운다(#712). 7.0.x 의 플러그인 비활성화·삭제는 레이아웃 정리에 모듈용
 *         조회를 써서 플러그인 레이아웃이 남았다. 설치 기록이 없는 플러그인의 행만 대상이다.
 *       07_MigrateIdentityMessagesToNotifications.php
 *         본인인증 메일 문구를 알림 정의(category=identity)로 옮기고 옛 표(`identity_message_definitions/templates`)를
 *         드랍한다(#699). 재시딩이 만든 코어 선언 행에는 운영자 수정분만 옮기고, 운영자 정책 문구·비활성 확장 문구는
 *         새로 만든다. 옮긴 건수와 옛 표 건수가 맞을 때만 드랍하며, 권한 `core.admin.identity.messages.*` 를 지운다
 *         (알림 설정 권한 `core.settings.*` 로 대체). 03 이 먼저 거짓 수정 표식을 지운 뒤에 돈다.
 *       08_DropLegacyMailTables.php
 *         7.0.0-beta.2 부터 쓰이지 않는 `mail_templates`(0건일 때)·`mail_send_logs`(0건이거나 알림 발송 로그로 이관이
 *         끝났을 때)를 드랍한다(#699). 행이 남아 있으면 경고만 남기고 보존한다.
 *       09_PruneStaleBundledFiles.php
 *         7.0.x 부모의 파일 적용이 번들 디렉토리(`*_bundled`)에 남긴, 7.1.0 에서 지운 파일을 지운다(R1). 남겨 두면 뒤이은
 *         확장 갱신이 그 파일을 활성 디렉토리로 옮겨 옛 조각·레이아웃이 다시 등록된다(결제 3종 옛 주문 목록 조각이
 *         관리자 주문 목록 열을 7.0.11 판으로 덮은 실측). 동결 목록(`upgrades/data/7.1.0/stale_bundled_files.snapshot.json`)의
 *         경로 중 본문이 7.0.x 배포본과 같은 파일만 지우고, 고친 파일은 경고만 남긴다. 활성 디렉토리는 건드리지 않는다.
 *
 * 신규 테이블 13개(방문자 통계 원장·집계·운영 11개 + 개인정보 처리 수동 실행 기록 `privacy_manual_runs`·
 * 비밀번호 이력 `user_password_histories`)는 일반 마이그레이션이 만들고, 설정 기본값·권한(사용자 본인 정보
 * `core.profile` 포함)·메뉴 정의·알림 정의는 코어 업데이트 흐름의 비파괴 동기화가 반영하므로
 * 별도 변환이 필요 없다. 비밀번호 정책(#736)의 users·roles 컬럼과 이력 테이블, 보안 설정 키,
 * 만료 알림 정의 2종도 같은 이유로 변환이 필요 없다(기존 회원의 변경일은 NULL — 만료 기준은 정책 시작일).
 *
 * 실행 순서는 파일명 정렬(`sort()`)을 따른다. 접두 번호가 같은 두 쌍(`01_`·`05_`)은 파일 이름 나머지로 갈리며
 * (01_Backfill… → 01_Shift…, 05_BackfillAdmin… → 05_Rekey…) 서로 순서에 의존하지 않는다. 순서 의존은 02 → 05_Rekey
 * (수정 판정 해시 정규화), 03 → 07(거짓 수정 표식 정리 뒤 이관), 00(코어 선언 재동기화)이 맨 앞이며, 시험이 목록과 순서를 잠근다. 실행 로그는 위임받은 각 DataMigration 이
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
