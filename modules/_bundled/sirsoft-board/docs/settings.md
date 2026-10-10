# 게시판 — 설정·권한·라우트

> 설정 스키마·권한·메뉴·라우트·의존 관계 · 진입점: [AGENTS.md](../AGENTS.md)

## 설정 스키마

<!-- @generated:settings-schema START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
_`getSettingsSchema()` 선언이 없습니다._

기본값 파일: `config/settings/defaults.json`
<!-- @generated:settings-schema END -->

<!-- @intent START -->
`getSettingsSchema()` 가 없는 것은 누락이 아니라 설계입니다 — 이 모듈에는 "전역 설정"이라
부를 만한 것이 없습니다. 운영자가 조정하는 값은 전부 **게시판 하나**에 속한 설정
(`BoardSettingsService`, `/admin/boards/{slug}/settings`)이라 코어의 전역 설정 스키마
메커니즘과 맞지 않습니다. `config/board.php` 는 운영자가 바꾸는 자리가 아니라 개발자가
정의하는 상수(첨부 저장 디스크, 게시판별 동적 권한 정의 템플릿)이며, 이 값은 `.env` 로만
바꿉니다.

### 개인정보 보유기간 (`privacy_retention`, 이슈 #636)

모듈 환경설정의 「개인정보 보유기간」 탭(`?tab=privacy_retention`, 저장 `_tab=privacy_retention`)이 저장하는
전역 카테고리입니다(`config/settings/defaults.json`). 코어 개인정보 탭·쇼핑몰 보유기간 탭·gdpr 통지 탭처럼
개인정보 조치를 자기 탭에 둡니다.
게시판 하나가 아니라 **모든 게시판의 행**에 걸리므로 일괄 적용 대상이 아닙니다.

| 키 | 기본값 | 의미 |
|---|---|---|
| `enabled` | `false` | 보유기간 자동 파기 사용 여부 — 되돌릴 수 없는 파기라 기본 꺼짐 |
| `ip_retention_days` | `365` | 게시글·댓글 작성 IP 보유기간(일, 하한 1). 회원 글 포함 |
| `guest_retention_days` | `0` | 비회원 작성자 정보 보유기간(일). **0 = 비회원 축 미적용** |
| `guest_mode` | `anonymize` | 비회원 글 처리 방식 — `anonymize`(작성자명 `***` 치환) / `delete`(게시판 삭제 경로 + 행에 남은 개인정보·본문·첨부 파기) |

실행은 이 모듈이 하지 않습니다 — 코어 `privacy:process-retention`(매일)이
`core.privacy.retention_handlers` 로 등록된 두 핸들러 `sirsoft-board.ip`·`sirsoft-board.guest`
(`src/Privacy/`)를 부릅니다. 한계값은 `config/board.php` 의 `limits.privacy_*` 이고 서버 검증과
화면 입력이 같은 값을 읽습니다. 대상 건수는 `GET …/admin/settings/privacy-retention-preview`
(저장된 설정 기준, **꺼진 축도 센다** — 꺼짐은 `ip_enabled`·`guest_enabled` 로 구분, 비회원 기간 0 이면 비회원 건수 0)가
코어 커맨드의 `--dry-run` 과 같은 조건식으로 셉니다. 두 축의 건수는 토글 바로 아래 줄에 있고, 옆 「IP 대상 보기」·
「비회원 대상 보기」가 코어 공통 대상 명단 API(`GET /api/admin/privacy/retention-targets/sirsoft-board.ip` · `.guest`)를
무한 스크롤 모달로 엽니다(대상이 0건이어도 버튼은 보이고, 창은 빈 명단을 안내합니다) — 행은 제목(댓글이면 그 글의 제목)·작성자·게시판과 글/댓글 구분·작성일이고, 글의 **관리자
상세**(`/admin/board/{slug}/post/{id}`, 댓글은 그 글)를 새 탭으로 엽니다 — 대상에 휴지통 글이 들고, 사용자 화면은
삭제글을 게시판 관리자(사용자 권한)에게만 보여 주기 때문입니다. 명단은 제목만 싣고 본문·첨부는 싣지 않습니다(비밀글 게이트가 가리는 원문 밖). 권한은
`sirsoft-board.settings.read` + `sirsoft-board.boards.read` 가 모두 필요하고, 둘 다 적용 범위가 전체여야 합니다(범위를 좁혀 위임받은 관리자는 건수만 봅니다). 핸들러 계약 전체는 코어
[개인정보 보유기간 처리 가이드](../../../../docs/backend/privacy-retention.md)에 있습니다.

- **IP 축과 비회원 축의 기간을 따로 두는 이유**: IP 는 접속기록이라 안전성 확보조치 기준의 보관
  기간(1년, 대규모 2년)이 지나면 남길 근거가 없지만, 비회원 작성자명은 게시판 운영 맥락(답글·
  댓글의 대상)이라 운영자가 더 길게 둘 수 있어야 합니다.
- **삭제 모드가 행을 물리적으로 지우지 않는 이유**: 게시판 삭제 경로를 지나야 답글·댓글 연쇄,
  게시판 건수, 이커머스 문의 피벗이 리스너로 맞춰집니다. 그 뒤 행에 남은 작성자명·비밀번호·IP·
  제목·본문을 파기하고 첨부는 파일째 즉시 지우므로 개인정보가 남지 않습니다. 삭제 경로 중
  **항목별 `before_delete` 는 건너뜁니다** — 그 훅의 본인인증 정책은 무인 배치에 인증할 사람이
  없어, 켜지는 순간 법정 파기가 전부 멈춥니다(일괄 처리 경로와 같은 판단).
- **신고 로그 사본도 같은 행 처리 안에서 지웁니다**: 신고 로그가 복사해 둔 작성자명·제목·본문
  스냅샷을 행의 처리 표지(작성자명 `***`)보다 **먼저** 덮습니다. 순서가 반대면 스냅샷 쪽 실패가 그
  행을 다음 실행의 대상에서 영구히 빼 사본의 개인정보가 남습니다. 게시판 행이 사라진 고아 글·댓글은
  삭제 경로를 탈 수 없으므로 파기하면서 삭제 시각을 직접 찍습니다(찍지 않으면 매 실행 다시 잡힘).
- **탈퇴 회원 글은 대상이 아닙니다**: 표시 계층(`PostResource`·`CommentResource`·`BoardRepository`·
  `ResolvesScrapAuthorName`)이 이미 「탈퇴한 사용자」로 가리고, 회원 정보 파기는 코어 개인정보
  탭(탈퇴 회원 잔여 정보 2차 파기)이 담당합니다. 이 표시 판정은 `=== UserStatus::Withdrawn`
  단일 비교라 휴면(`dormant`) 회원에게는 영향이 없습니다.
- **운영자 사전 고지**: 켜기 전에 개인정보 처리방침에 보유기간과 파기 방법을 안내해야 합니다 —
  설정 카드 설명이 이를 적고 있습니다.
- **관리자 직권 실행**: 두 축의 대상 행(`ip_target_row` · `guest_target_row` — 이름 · 건수 · 대상 보기 · 결과 줄)에 있는
  「지금 실행」은 코어 직권 실행
  (`retention:sirsoft-board.ip` · `retention:sirsoft-board.guest`)입니다. 핸들러 등록만으로 코어가 대상을 만들므로
  별도 등록이 없고, 토글이 꺼져 있어도 저장된 기간·방식으로 `process(force: true)` 를 한 번 큐에서 돌립니다.
  권한은 두 축 모두 `sirsoft-board.settings.update` + `sirsoft-board.boards.update`(전체 범위). 확인 창은
  `_modal_privacy_run`, 상태 줄은 코어 상태 API(`privacyManualRuns`). 계약은 코어 가이드 「관리자 직권 실행」 절.
  확인 창의 실행 버튼은 `lockRepeatClick` 으로 첫 클릭 즉시 잠급니다 — 빠른 두 번째 클릭이 빈 실행을 한 번 더 접수해
  카드 결과를 「0건 처리」로 덮지 않도록(코어 확인 창과 같은 규칙).
<!-- @intent END -->

## 권한

<!-- @generated:permissions START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 카테고리 | 이름 | 액션 | 라우트 키 |
|---|---|---|---|
| `boards` | 게시판 관리 | `read`, `create`, `update`, `delete` | `board` |
| `settings` | 환경설정 | `read`, `update` | - |
| `identity.policies` | 게시판 본인인증 정책 | `read`, `update` | - |
| `dashboard` | 게시판 대시보드 | `view` | - |
| `reports` | 게시판 신고 관리 | `view`, `manage` | `report` |
| `scraps` | 게시글 스크랩 관리 | `view`, `manage` | `scrap` |
| `reactions` | 게시글 반응 관리 | `view`, `manage` | `reaction` |
<!-- @generated:permissions END -->

<!-- @intent START -->
`identity.policies` 는 환경설정 「본인인증 정책」 탭의 권한입니다(7.1.0~) — 조회(`read`)가 없으면 탭에 「권한 없음」 안내가,
수정(`update`)이 없으면 읽기 전용 안내가 뜨고 추가·켜고 끄기·편집·삭제가 잠깁니다. 탭은 게시판 경로
(`/api/modules/sirsoft-board/admin/identity-policies`)를 쓰고, 그 경로는 같은 권한으로 403 을 내며 코어 정책 표에서
게시판 몫(`source_identifier = sirsoft-board`)만 다룹니다. 두 권한은 기본 관리자 역할(`admin`)에 기본으로 주어집니다.
운영자 정책의 대상이 게시판 지점(`sirsoft-board.` · `api.modules.sirsoft-board.`)인지 검사하는 것은 **추가할 때와 범위·대상을
바꿀 때만**입니다 — 켜고 끄기나 다른 칸 수정은 저장된 범위·대상과 값이 같으면 검사를 건너뜁니다. 7.0.x 에서 이 탭의 자유 입력으로
게시판 밖 대상을 겨눈 정책도 업데이트 뒤 켜고 끄기·수정이 되고(422 아님), 대상을 게시판 밖으로 새로 옮기는 것만 거절합니다.
코어 관리자 환경설정의 본인인증 정책 화면은 코어 권한(`core.admin.identity.policies.*`)으로 모든 출처의 정책을 다룹니다.

위 표는 **모듈 레벨** 권한(게시판 관리 자체를 다루는 관리자 권한)만 보여줍니다. 게시판 하나를
만들면 그 게시판 전용 권한이 `config/board.php` 의 `board_permission_definitions` 템플릿을
기반으로 추가 생성됩니다(admin.posts.read/write, posts.read-secret 등 — 게시판마다 독립적인
권한 묶음). 그 동적 권한은 이 표에 나타나지 않으며 `getDynamicPermissionIdentifiers()` 로만
전수를 확인할 수 있습니다. `boards`/`reports` 카테고리에 `resource_route_key`/`owner_key` 가
붙어 있는 것은 소유자 기반 스코프 판정(자기 글만 관리 가능한 `manager` 이하 역할 등)이 걸려
있다는 뜻입니다.

게시판 동적 권한 중 `posts.write-notice`(공지 등록)는 **필드 설정 권한**입니다 — `posts.write` 를
통과한 사용자 중 글을 공지로 등록·해제할 수 있는 사람을 가립니다. 사용자·관리자 글 작성·수정 컨트롤러가
같은 판정(`ChecksBoardPermission::canSetNotice()` — 게시판 관리 권한 `admin.manage`/`manager` 또는 이 권한)을 쓰며, 폼 메타의
`user_abilities.can_write_notice` 가 같은 결과를 화면에 내려 줍니다(관리자 글 폼의 공지 체크박스도 이 값으로 잠금). 기본값은 `admin` 이고 게시판
생성 시 그 게시판의 관리자·스텝 역할이 자동 주입됩니다. 기존 설치본은 1.2.0 업그레이드 스텝이 기존
게시판의 권한 행과 저장된 기본 권한 설정(`basic_defaults.default_board_permissions`)을 함께 채웁니다
— 설정이 통째로 저장되는 구조라 기본값 병합만으로는 새 키가 들어가지 않기 때문입니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 구분 | slug | 이름 | URL | 하위 |
|---|---|---|---|---|
| 관리자 | `sirsoft-board` | 게시판 관리 | - | 5개 |
<!-- @generated:menus END -->

<!-- @intent START -->
정적 관리자 메뉴는 "게시판 관리" 3개 하위 메뉴(환경설정/목록/신고현황)뿐입니다. 게시판을
만들 때마다 생기는 `board-{slug}` 메뉴는 동적 메뉴라 이 표에 없으며
`getDynamicMenuSlugs()` 로 전수를 확인합니다. 방문자용 메뉴(사이트 상단 게시판 링크 등)는
이 모듈이 등록하지 않습니다 — 템플릿이 공개 API(`boards.board-menu`)를 호출해 직접 구성합니다.
<!-- @intent END -->

## 라우트

<!-- @generated:routes START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 종류 | 파일 | URL prefix |
|---|---|---|
| `api` | `src/routes/api.php` | `/api/modules/sirsoft-board/...` |

확장 라우트는 **활성 상태인 확장의 것만** 등록됩니다. 라우트 정의를 바꾸면 라우트 캐시 재생성이 필요합니다.
<!-- @generated:routes END -->

<!-- @intent START -->
같은 `src/routes/api.php` 파일 안에 관리자 전용 그룹(`/admin/board/{slug}/...`, 권한 미들웨어)과
공개 그룹(`/boards/...`, `optional.sanctum`)이 함께 있습니다 — 파일을 분리하지 않은 것은
board 의 라우트가 20개 안팎으로 한 파일에서 관리 가능한 규모이기 때문입니다. 새 공개
엔드포인트를 추가할 때는 반드시 `optional.sanctum`(비회원도 접근 가능, 회원이면 컨텍스트
주입)을 쓰고 `auth:sanctum` 을 쓰지 않습니다 — 게시판 열람은 비회원에게도 열려 있어야 합니다.
<!-- @intent END -->

## 의존 관계

<!-- @generated:dependencies START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
**이 확장이 의존하는 확장**

없음 — 코어만으로 동작합니다.

**이 확장에 의존하는 확장** (이 확장을 비활성화하면 함께 영향을 받습니다)

| 확장 | 유형 | 요구 버전 |
|---|---|---|
| `sirsoft-basic` | 템플릿 | `>=1.2.0` |

**이 확장과 연동하는 확장** (이 확장이 있으면 기능을 더합니다 — 이 확장의 화면·훅 이름을 바꿀 때 함께 확인)

| 확장 | 유형 |
|---|---|
| `sirsoft-ecommerce` | 모듈 |
| `sirsoft-message_bizppurio` | 플러그인 |
<!-- @generated:dependencies END -->

<!-- @intent START -->
이 모듈이 의존하는 확장이 "없음"인 것은 board 가 코어 훅·API 만으로 완결되도록 설계됐다는
뜻입니다. 반대로 이 모듈에 의존하는 쪽은 하나(템플릿)뿐이지만, 그보다 결합이 느슨한
**필터 훅 위임** 소비자(이커머스 문의)는 `dependencies` 로 선언되지 않습니다 — 이커머스는
board 를 자기 도메인으로 대체할 뿐 board API 계약에 실제로 묶여 있지 않기 때문입니다.
이 모듈의 공개 표면(라우트·API 응답 구조)을 바꿀 때는 `sirsoft-basic` 의 최소 버전 상향을
검토해야 합니다(§코어 AGENTS.md "확장 → 확장 동기화").
<!-- @intent END -->
