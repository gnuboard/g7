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
<!-- @generated:permissions END -->

<!-- @intent START -->
위 표는 **모듈 레벨** 권한(게시판 관리 자체를 다루는 관리자 권한)만 보여줍니다. 게시판 하나를
만들면 그 게시판 전용 권한이 `config/board.php` 의 `board_permission_definitions` 템플릿을
기반으로 추가 생성됩니다(admin.posts.read/write, posts.read-secret 등 — 게시판마다 독립적인
권한 묶음). 그 동적 권한은 이 표에 나타나지 않으며 `getDynamicPermissionIdentifiers()` 로만
전수를 확인할 수 있습니다. `boards`/`reports` 카테고리에 `resource_route_key`/`owner_key` 가
붙어 있는 것은 소유자 기반 스코프 판정(자기 글만 관리 가능한 `manager` 이하 역할 등)이 걸려
있다는 뜻입니다.
<!-- @intent END -->

## 메뉴

<!-- @generated:menus START — ext:docgen 이 갱신. 이 블록 안은 직접 수정하지 않는다 -->
| 구분 | slug | 이름 | URL | 하위 |
|---|---|---|---|---|
| 관리자 | `sirsoft-board` | 게시판 관리 | - | 3개 |
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
| `sirsoft-basic` | 템플릿 | `>=1.0.0` |
<!-- @generated:dependencies END -->

<!-- @intent START -->
이 모듈이 의존하는 확장이 "없음"인 것은 board 가 코어 훅·API 만으로 완결되도록 설계됐다는
뜻입니다. 반대로 이 모듈에 의존하는 쪽은 하나(템플릿)뿐이지만, 그보다 결합이 느슨한
**필터 훅 위임** 소비자(이커머스 문의)는 `dependencies` 로 선언되지 않습니다 — 이커머스는
board 를 자기 도메인으로 대체할 뿐 board API 계약에 실제로 묶여 있지 않기 때문입니다.
이 모듈의 공개 표면(라우트·API 응답 구조)을 바꿀 때는 `sirsoft-basic` 의 최소 버전 상향을
검토해야 합니다(§코어 AGENTS.md "확장 → 확장 동기화").
<!-- @intent END -->
