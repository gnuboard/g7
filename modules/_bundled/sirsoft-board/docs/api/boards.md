# Boards API 레퍼런스

> **소유**: module `sirsoft-board` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Boards 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-board/admin/boards
<!-- @generated:start:api.modules.sirsoft-board.admin.boards.index -->
- **라우트명**: `api.modules.sirsoft-board.admin.boards.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.boards.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 999 | 페이지당 항목 수 (게시판은 운영자 등록 설정성 테이블 — 전체 목록 셀렉트 소비처의 전량 조회를 수용) |
| type | query | string | 아니오 | max 50 | 게시판 타입 슬러그 필터 (basic, gallery 등) |
| search | query | string | 아니오 | max 100 | 검색어 (게시판명/슬러그 부분 일치) |
| sort_by | query | string | 아니오 | `id`, `slug`, `type`, `is_active`, `posts_count`, `comments_count`, `created_at`, `updated_at` | 정렬 기준 컬럼 (닫힌 집합 — `name` 은 다국어 JSON 컬럼이라 정렬 대상에서 제외) |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (대문자 `ASC`/`DESC` 로 보내도 검증 전 소문자로 정규화되어 수용) |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/boards HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

> **목록은 경량 표현이다.** 관리자 게시판 목록 화면이 쓰는 행 필드는 `id`/`name`/`slug`/`type`/`is_active`/`categories`/`abilities`/`posts_count` 뿐이다. 종전에는 행마다 매니저·스텝 역할에 속한 **모든 사용자(uuid·이름·이메일)** 까지 함께 직렬화하면서 행 수만큼 역할 조회가 추가로 나갔다. 역할/사용자 정보는 게시판 수정 화면(단건 조회 `GET .../admin/boards/{slug}`)이 공급한다.


| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 기본 키 (내부 식별자) |
| name | string | `API 문서 샘플 게시판` | 게시판명 (현재 로케일로 해석된 문자열) |
| slug | string | `apidoc-sample-board` | 게시판 슬러그 (URL/테이블명) |
| description | string | `` | 게시판 설명 (현재 로케일로 해석된 문자열) |
| posts_count | integer | `0` | 게시글 수 (집계) |
| type | string | `basic` | 게시판 타입 (basic, gallery, card 등) |
| is_active | boolean | `true` | 활성 여부 |
| categories | array | `[]` | 분류 목록 (배열) |
| is_owner | boolean | `false` | 현재 인증 사용자가 이 게시판의 소유자인지 여부 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시판 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 12,
                "name": "API 문서 샘플 게시판",
                "slug": "apidoc-sample-board",
                "description": "",
                "posts_count": 0,
                "type": "basic",
                "is_active": true,
                "categories": [],
                "is_owner": false,
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            },
            "... (총 3건 중 1건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 20,
            "total": 3
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.boards.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) — `sort_by` 가 허용 컬럼 목록 밖이거나, `sort_order` 가 `asc`/`desc` 가 아니거나, `per_page` 가 1~999 범위를 벗어난 경우 등 (`IndexBoardRequest` 검증) |

<!-- @generated:end -->

**설명** 관리자 화면의 게시판 관리 목록을 조회합니다. `auth:sanctum` + `sirsoft-board.boards.read` 권한이 필요하며, `IndexBoardRequest` 로 검증된 필터/정렬/페이징 파라미터만 서비스에 넘겨 전체 게시판을 페이지네이션합니다. 정렬 컬럼(`sort_by`)과 페이지 크기(`per_page` 1~999)는 닫힌 집합으로 제한되어 허용값 밖 요청은 422 로 거부됩니다. 각 항목은 `BoardCollection::withPermissions()` 로 감싸져 현재 관리자의 생성/수정/삭제 가능 여부(`abilities`)를 함께 반환합니다.


### POST /api/modules/sirsoft-board/admin/boards
<!-- @generated:start:api.modules.sirsoft-board.admin.boards.store -->
- **라우트명**: `api.modules.sirsoft-board.admin.boards.store`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.boards.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| slug | body | string | 예 | max 50 | URL 친화 식별자 (slug) |
| description | body | array | 아니오 | — | 설명 |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| type | body | string | 예 | max 50 | 게시판 타입 슬러그 (basic, gallery, card 등 — 등록된 board_types 값 중 하나) |
| add_to_menu | body | boolean | 아니오 | — | 저장 시 이 게시판을 관리자 메뉴에 자동 등록할지 여부 (DB 컬럼 아님 — 메뉴 등록 처리에만 사용) |
| per_page | body | integer | 예 | min 5, max 100 | 페이지당 항목 수 |
| per_page_mobile | body | integer | 예 | min 5, max 100 | 모바일 화면에서 페이지당 표시할 게시글 수 |
| order_by | body | string | 예 | `created_at`, `view_count`, `title`, `author` | 정렬 기준 필드명 |
| order_direction | body | string | 예 | `ASC`, `DESC` | 게시글 목록 정렬 방향 (ASC 오름차순 / DESC 내림차순) |
| categories | body | array | 아니오 | max 50 | 게시글 분류(카테고리) 이름 목록 (최대 50개, 빈/공백 이름 불가) |
| show_view_count | body | boolean | 예 | — | 게시글 목록/상세에 조회수를 노출할지 여부 |
| secret_mode | body | string | 예 | `disabled`, `enabled`, `always` | 비밀글 설정 (disabled 사용 안 함 / enabled 작성자 선택 가능 / always 전 글 비밀글 강제) |
| use_comment | body | boolean | 예 | — | 댓글 기능 사용 여부 |
| use_reply | body | boolean | 예 | — | 게시글 답변(원글에 대한 답글) 기능 사용 여부 |
| use_report | body | boolean | 예 | — | 게시글/댓글 신고 기능 사용 여부 |
| comment_order | body | string | 예 | `ASC`, `DESC` | 댓글 정렬 순서 (ASC 오름차순 / DESC 내림차순) |
| new_display_hours | body | integer | 아니오 | min 0, max 720 | 신규(NEW) 표시를 유지할 기간 (시간 단위, 최대 720시간=30일). `0` 은 NEW 배지를 표시하지 않음 |
| min_title_length | body | integer | 아니오 | min 0, max 200 | 게시글 제목 최소 글자 수 |
| max_title_length | body | integer | 아니오 | min 1, max 1000 | 게시글 제목 최대 글자 수 |
| min_content_length | body | integer | 아니오 | min 0, max 10000 | 게시글 본문 최소 글자 수 |
| max_content_length | body | integer | 아니오 | min 1, max 100000 | 게시글 본문 최대 글자 수 |
| min_comment_length | body | integer | 아니오 | min 0, max 1000 | 댓글 최소 글자 수 |
| max_comment_length | body | integer | 아니오 | min 1, max 10000 | 댓글 최대 글자 수 |
| use_file_upload | body | boolean | 예 | — | 파일 첨부 기능 사용 여부 (true일 때 allowed_extensions 최소 1개 필수) |
| max_file_size | body | integer | 아니오 | min 1, max 200 | 첨부파일 1개당 최대 크기 (MB 단위) |
| max_file_count | body | integer | 아니오 | min 1, max 20 | 게시글 1건당 첨부할 수 있는 최대 파일 개수 |
| allowed_extensions | body | array | 조건부 | min 1 | 업로드 허용 확장자 목록 (예: jpg, png, pdf). `use_file_upload=true` 일 때만 필수이며 최소 1개가 있어야 한다. `use_file_upload=false` 이면 검증에서 제외되어 빈 배열·`null` 도 허용된다 |
| board_manager_ids | body | array | 예 | min 1 | board manager 식별자 배열 |
| board_step_ids | body | array | 아니오 | — | board step 식별자 배열 |
| permissions | body | array | 아니오 | — | 게시판별 세부 권한 매트릭스 (권한 키별 mode/roles — 미지정 시 Service가 Manager/Step 역할을 주입) |
| max_reply_depth | body | integer | 아니오 | min 1, max 10 | 답변글 최대 중첩 깊이 |
| reply_delete_policy | body | string | 예 | `block`, `cascade` | 답글 달린 글의 삭제 정책 (block 답글이 살아 있으면 원글 삭제 차단 / cascade 원글 삭제 시 답글도 함께 소프트 삭제). 미전송 시 검증 전 기본값 `cascade` 로 채워지며, 허용값 밖이면 422 |
| max_comment_depth | body | integer | 아니오 | min 0, max 10 | 대댓글 최대 중첩 깊이 |
| notify_admin_on_post | body | boolean | 예 | — | 게시글 등록 시 관리자에게 이메일 알림 발송 여부 |
| notify_author | body | boolean | 예 | — | 댓글·대댓글·답변글·관리자 처리 발생 시 작성자에게 이메일 알림 발송 여부 |
| blocked_keywords | body | array | 아니오 | — | 게시글/댓글 작성 시 차단할 금지어 목록 (각 항목 최대 100자, 쉼표 구분 문자열도 허용) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.board.store_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-board/admin/boards HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "slug": "example-key",
    "description": [
        "예시 내용입니다."
    ],
    "is_active": true,
    "type": "예시값",
    "add_to_menu": true,
    "per_page": 1,
    "per_page_mobile": 1,
    "order_by": "created_at",
    "order_direction": "ASC",
    "categories": [
        "예시값"
    ],
    "show_view_count": true,
    "secret_mode": "disabled",
    "use_comment": true,
    "use_reply": true,
    "use_report": true,
    "comment_order": "ASC",
    "new_display_hours": 1,
    "min_title_length": 1,
    "max_title_length": 1,
    "min_content_length": 1,
    "max_content_length": 1,
    "min_comment_length": 1,
    "max_comment_length": 1,
    "use_file_upload": true,
    "max_file_size": 1,
    "max_file_count": 1,
    "allowed_extensions": [
        "예시값"
    ],
    "board_manager_ids": [
        "예시값"
    ],
    "board_step_ids": [
        "예시값"
    ],
    "permissions": [
        "예시값"
    ],
    "max_reply_depth": 1,
    "reply_delete_policy": "cascade",
    "max_comment_depth": 1,
    "notify_admin_on_post": true,
    "notify_author": true,
    "blocked_keywords": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (생성된 게시판 = `BoardResource`, 상세 조회(`GET /admin/boards/{board}`)와 동일 스키마)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 기본 키 (내부 식별자) |
| name | string | `API 문서 샘플 게시판` | 게시판명 (다국어 JSON) |
| slug | string | `apidoc-sample-board` | 게시판 슬러그 (URL/테이블명 — 생성 후 변경 불가) |
| is_active | boolean | `true` | active 여부 |
| type | string | `basic` | 게시판 타입 (basic, gallery, card 등) |
| description | string | `` | 게시판 설명 (다국어 JSON) |
| per_page | integer | `20` | 페이지당 게시글 수 (PC) |
| per_page_mobile | integer | `15` | 페이지당 게시글 수 (Mobile) |
| order_by | string | `created_at` | 정렬 기준 (created_at, view_count, title, author) |
| order_direction | string | `DESC` | 정렬 방향 (ASC, DESC) |
| categories | array | `[]` | 분류 목록 (배열) |
| show_view_count | boolean | `true` | 조회수 노출 |
| secret_mode | string | `disabled` | 비밀글 설정 (disabled: 사용안함, enabled: 사용함, always: 고정) |
| use_comment | boolean | `true` | 댓글 기능 사용 |
| use_reply | boolean | `true` | 게시글 답변 기능 사용 (댓글에 대한 답글 아님) |
| max_reply_depth | integer | `5` | 답변글 최대 깊이 |
| reply_delete_policy | string | `cascade` | 답글 달린 글의 삭제 정책 (block: 답글이 살아 있으면 원글 삭제 차단, cascade: 원글 삭제 시 답글도 함께 소프트 삭제) |
| use_report | boolean | `true` | 게시글/댓글 신고 기능 사용 |
| comment_order | string | `ASC` | 댓글 정렬 순서 (ASC: 오름차순, DESC: 내림차순) |
| max_comment_depth | integer | `10` | 대댓글 최대 깊이 |
| min_title_length | integer | `2` | 최소 제목 글자 수 |
| max_title_length | integer | `200` | 최대 제목 글자 수 |
| min_content_length | integer | `10` | 최소 게시글 글자 수 |
| max_content_length | integer | `10000` | 최대 게시글 글자 수 |
| min_comment_length | integer | `2` | 최소 댓글 글자 수 |
| max_comment_length | integer | `1000` | 최대 댓글 글자 수 |
| blocked_keywords | array | `[]` | 금지어 목록 (배열) |
| use_file_upload | boolean | `true` | 파일 업로드 사용 |
| max_file_size | integer | `10` | 최대 파일 크기 (MB) |
| max_file_count | integer | `5` | 게시글 1건당 최대 첨부 개수 |
| allowed_extensions | array | `["jpg","png","pdf"]` | 허용 확장자 배열 |
| add_to_menu | null | `null` | 관리자 메뉴 등록 여부 (폼 요청에서만 채워지는 토글 초기값 — 조회 응답에서는 항상 null) |
| new_display_hours | integer | `24` | 신규 게시글 표시 기간 (시간 단위) |
| board_managers | array | `[]` | 게시판 관리자로 지정된 사용자 목록 (uuid/name/email — 역할 기반 파생) |
| board_steps | array | `[]` | 게시판 승인/처리 담당자(스텝) 사용자 목록 (uuid/name/email — 역할 기반 파생) |
| board_manager_ids | array | `[]` | board manager 식별자(uuid) 배열 |
| board_step_ids | array | `[]` | board step 식별자(uuid) 배열 |
| notify_author | boolean | `true` | 작성자 이메일 알림 (댓글, 대댓글, 답변글, 관리자 처리 시) |
| notify_admin_on_post | boolean | `true` | 관리자 이메일 알림 (게시글 등록 시) |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| updated_at | string | `2026-07-07 09:34:50` | 최종 수정 일시 |
| permissions | object \| null | `null` | 게시판별 권한 매트릭스 (`include_permissions` 요청 시에만 채워지며, 미포함 시 null) |
| category_post_counts | null | `null` | 분류별 게시글 개수 맵 (요청 시 조건부로만 채워지며, 미포함 시 null) |
| posts_count | integer | `0` | 게시글 수 (집계 — 생성 직후 0) |
| user_abilities | null | `null` | 현재 사용자의 게시판별 세부 권한 맵 (`include_user_abilities` 요청 시에만 채워지며 미포함 시 null) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "게시판이 생성되었습니다.",
    "data": {
        "id": 12,
        "name": "API 문서 샘플 게시판",
        "slug": "apidoc-sample-board",
        "is_active": true,
        "type": "basic",
        "description": "",
        "per_page": 20,
        "per_page_mobile": 15,
        "order_by": "created_at",
        "order_direction": "DESC",
        "categories": [],
        "show_view_count": true,
        "secret_mode": "disabled",
        "use_comment": true,
        "use_reply": true,
        "max_reply_depth": 5,
        "reply_delete_policy": "cascade",
        "use_report": true,
        "comment_order": "ASC",
        "max_comment_depth": 10,
        "min_title_length": 2,
        "max_title_length": 200,
        "min_content_length": 10,
        "max_content_length": 10000,
        "min_comment_length": 2,
        "max_comment_length": 1000,
        "blocked_keywords": [],
        "use_file_upload": true,
        "max_file_size": 10,
        "max_file_count": 5,
        "allowed_extensions": [
            "jpg",
            "png",
            "pdf"
        ],
        "add_to_menu": null,
        "new_display_hours": 24,
        "board_managers": [],
        "board_steps": [],
        "board_manager_ids": [],
        "board_step_ids": [],
        "notify_author": true,
        "notify_admin_on_post": true,
        "created_at": "2026-07-08 10:41:34",
        "updated_at": "2026-07-08 10:41:34",
        "permissions": null,
        "category_post_counts": null,
        "posts_count": 0,
        "user_abilities": null,
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.boards.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 게시판 전용 테이블 생성 실패 등 생성 처리 중 예외 (`게시판 생성에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 새 게시판을 생성합니다. `auth:sanctum` + `sirsoft-board.boards.create` 권한이 필요하며, `StoreBoardRequest` 로 검증된 이름/슬러그/타입/권한/제한값 등을 받아 게시판과 전용 데이터를 초기화합니다. 슬러그는 게시글 테이블명·권한 키·URL의 기준이 되므로 생성 후 변경할 수 없다는 점에 주의하고, 성공 시 생성된 게시판 리소스를 201로 반환합니다.


### GET /api/modules/sirsoft-board/admin/boards/form-data
<!-- @generated:start:api.modules.sirsoft-board.admin.boards.form-data -->
- **라우트명**: `api.modules.sirsoft-board.admin.boards.form-data`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardController@getFormData`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.boards.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/boards/form-data HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| type | string | `basic` | 게시판 타입 (basic, gallery, card 등) |
| per_page | integer | `20` | 페이지당 게시글 수 (PC) |
| per_page_mobile | integer | `15` | 페이지당 게시글 수 (Mobile) |
| order_by | string | `created_at` | 정렬 기준 (created_at, view_count, title, author) |
| order_direction | string | `DESC` | 정렬 방향 (ASC, DESC) |
| secret_mode | string | `disabled` | 비밀글 설정 (disabled: 사용안함, enabled: 사용함, always: 고정) |
| use_comment | boolean | `true` | 댓글 기능 사용 |
| use_reply | boolean | `true` | 게시글 답변 기능 사용 (댓글에 대한 답글 아님) |
| max_reply_depth | integer | `5` | 답변글 최대 깊이 (1~10) |
| reply_delete_policy | string | `cascade` | 답글 달린 글의 삭제 정책 기본값 (block: 답글이 살아 있으면 원글 삭제 차단, cascade: 원글 삭제 시 답글도 함께 소프트 삭제) |
| max_comment_depth | integer | `10` | 대댓글 최대 깊이 (1~10) |
| comment_order | string | `ASC` | 댓글 정렬 순서 (ASC: 오름차순, DESC: 내림차순) |
| show_view_count | boolean | `true` | 조회수 노출 |
| use_report | boolean | `false` | 게시글/댓글 신고 기능 사용 |
| min_title_length | integer | `2` | 최소 제목 글자 수 |
| max_title_length | integer | `200` | 최대 제목 글자 수 |
| min_content_length | integer | `2` | 최소 게시글 글자 수 |
| max_content_length | integer | `10000` | 최대 게시글 글자 수 |
| min_comment_length | integer | `2` | 최소 댓글 글자 수 |
| max_comment_length | integer | `1000` | 최대 댓글 글자 수 |
| use_file_upload | boolean | `false` | 파일 업로드 사용 |
| max_file_size | integer | `10` | 최대 파일 크기 (MB) |
| max_file_count | integer | `5` | max file 개수 (집계) |
| notify_admin_on_post | boolean | `true` | 관리자 이메일 알림 (게시글 등록 시) |
| notify_author | boolean | `true` | 작성자 이메일 알림 (댓글, 대댓글, 답변글, 관리자 처리 시) |
| new_display_hours | integer | `24` | 신규 게시글 표시 기간 (시간 단위) |
| id | null | `null` | 기본 키 (내부 식별자) |
| slug | null | `null` | 게시판 슬러그 (URL/테이블명) |
| is_active | boolean | `true` | active 여부 |
| add_to_menu | boolean | `false` | 관리자 메뉴 등록 여부 폼 토글 초기값 (수정 모드에서 해당 게시판이 이미 관리자 메뉴에 등록돼 있으면 true) |
| board_managers | array | `[{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"A…` | 게시판 관리자로 지정된 사용자 목록 (생성 모드에서는 현재 로그인 관리자가 기본값으로 채워짐 — uuid/name/email) |
| board_steps | array | `[]` | 게시판 승인/처리 담당자(스텝)로 지정된 사용자 목록 (step 역할 사용자의 uuid/name/email — 역할 기반 파생) |
| board_manager_ids | array | `["a231747f-e82e-4cf2-9ae1-a261849dce40"]` | board manager 식별자 배열 (연관 리소스 참조) |
| board_step_ids | array | `[]` | board step 식별자 배열 (연관 리소스 참조) |
| created_at | null | `null` | 생성 일시 |
| updated_at | null | `null` | 최종 수정 일시 |
| name | object | `{"ko":""}` | 게시판명 (다국어 JSON) |
| description | object | `{"ko":""}` | 게시판 설명 (다국어 JSON) |
| categories | array | `[]` | 분류 목록 (배열) |
| blocked_keywords | array | `[]` | 금지어 목록 (배열) |
| allowed_extensions | array | `["jpg","jpeg","png","gif","webp","pdf","doc","docx","xls"…` | 허용 확장자 배열 |
| permissions | object | `{"admin_posts_read":{"_key":"admin_posts_read","name":"ad…` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| board_types | array | `[{"id":1,"slug":"basic","name":{"ko":"기본형","en":"Basic Li…` | 선택 가능한 게시판 타입 목록 (타입 선택 UI 렌더링용 — id/slug/name 등) |
| _meta | object | `{"limits":{"per_page_min":5,"per_page_max":100,"min_title…` | 폼 입력 한계값 메타 (config('sirsoft-board.limits') — 페이지당 수·제목/본문/댓글 길이 등 min/max 범위) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시판 폼 데이터를 조회했습니다.",
    "data": {
        "type": "basic",
        "per_page": 20,
        "per_page_mobile": 15,
        "order_by": "created_at",
        "order_direction": "DESC",
        "secret_mode": "{MASKED}",
        "use_comment": true,
        "use_reply": true,
        "max_reply_depth": 5,
        "reply_delete_policy": "cascade",
        "max_comment_depth": 10,
        "comment_order": "ASC",
        "show_view_count": true,
        "use_report": false,
        "min_title_length": 2,
        "max_title_length": 200,
        "min_content_length": 2,
        "max_content_length": 10000,
        "min_comment_length": 2,
        "max_comment_length": 1000,
        "use_file_upload": false,
        "max_file_size": 10,
        "max_file_count": 5,
        "notify_admin_on_post": true,
        "notify_author": true,
        "new_display_hours": 24,
        "id": null,
        "slug": null,
        "is_active": true,
        "add_to_menu": false,
        "board_managers": [
            {
                "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                "name": "API 문서 샘플 사용자",
                "email": "apidoc-sample-user@example.com"
            }
        ],
        "board_steps": [],
        "board_manager_ids": [
            "a234c2b1-cde8-437f-b28b-23323be2b98d"
        ],
        "board_step_ids": [],
        "created_at": null,
        "updated_at": null,
        "name": {
            "ko": ""
        },
        "description": {
            "ko": ""
        },
        "categories": [],
        "blocked_keywords": [],
        "allowed_extensions": [
            "jpg",
            "jpeg",
            "png",
            "gif",
            "webp",
            "pdf",
            "doc",
            "docx",
            "xls",
            "xlsx",
            "ppt",
            "pptx",
            "hwp",
            "txt",
            "zip"
        ],
        "permissions": {
            "admin_posts_read": {
                "_key": "admin_posts_read",
                "name": "admin.posts.read",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_posts_write": {
                "_key": "admin_posts_write",
                "name": "admin.posts.write",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_posts_read-secret": {
                "_key": "admin_posts_read-secret",
                "name": "admin.posts.read-secret",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_comments_read": {
                "_key": "admin_comments_read",
                "name": "admin.comments.read",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_comments_write": {
                "_key": "admin_comments_write",
                "name": "admin.comments.write",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_attachments_upload": {
                "_key": "admin_attachments_upload",
                "name": "admin.attachments.upload",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_attachments_download": {
                "_key": "admin_attachments_download",
                "name": "admin.attachments.download",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_manage": {
                "_key": "admin_manage",
                "name": "admin.manage",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "posts_read": {
                "_key": "posts_read",
                "name": "posts.read",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user",
                    "guest"
                ]
            },
            "posts_write": {
                "_key": "posts_write",
                "name": "posts.write",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user"
                ]
            },
            "posts_read-secret": {
                "_key": "posts_read-secret",
                "name": "posts.read-secret",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "comments_read": {
                "_key": "comments_read",
                "name": "comments.read",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user",
                    "guest"
                ]
            },
            "comments_write": {
                "_key": "comments_write",
                "name": "comments.write",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user"
                ]
            },
            "attachments_upload": {
                "_key": "attachments_upload",
                "name": "attachments.upload",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user"
                ]
            },
            "attachments_download": {
                "_key": "attachments_download",
                "name": "attachments.download",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user",
                    "guest"
                ]
            },
            "manager": {
                "_key": "manager",
                "name": "manager",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            }
        },
        "board_types": [
            {
                "id": 2,
                "slug": "basic",
                "name": {
                    "ko": "기본형",
                    "en": "Basic List"
                }
            },
            {
                "id": 3,
                "slug": "gallery",
                "name": {
                    "ko": "갤러리형",
                    "en": "Gallery"
                }
            },
            {
                "id": 4,
                "slug": "card",
                "name": {
                    "ko": "카드형",
                    "en": "Card"
                }
            }
        ],
        "_meta": {
            "limits": {
                "per_page_min": 5,
                "per_page_max": 100,
                "min_title_length_min": 0,
                "min_title_length_max": 200,
                "max_title_length_min": 1,
                "max_title_length_max": 1000,
                "min_content_length_min": 0,
                "min_content_length_max": 10000,
                "max_content_length_min": 1,
                "max_content_length_max": 100000,
                "min_comment_length_min": 0,
                "min_comment_length_max": 1000,
                "max_comment_length_min": 1,
                "max_comment_length_max": 10000,
                "max_file_size_min": 1,
                "max_file_size_max": 200,
                "max_file_count_min": 1,
                "max_file_count_max": 20,
                "category_max": 50,
                "max_reply_depth_min": 1,
                "max_reply_depth_max": 10,
                "max_comment_depth_min": 0,
                "max_comment_depth_max": 10
            }
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.boards.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 게시판 생성/수정 폼을 렌더링하는 데 필요한 초기 데이터를 반환합니다. `auth:sanctum` + `sirsoft-board.boards.read` 권한이 필요하며, 쿼리 파라미터로 모드가 분기됩니다: `board_id` 가 있으면 기존 게시판 값(수정 모드), `copy_id` 가 있으면 복사 원본 값(복사 모드), 둘 다 없으면 모듈 기본 설정 기반 기본값(생성 모드)을 채웁니다. 생성 모드에서는 로그인한 관리자가 게시판 관리자 기본값으로 지정되며, 응답에는 선택 가능한 게시판 타입 목록(`board_types`)과 입력 한계값(`_meta.limits`)이 함께 포함됩니다.


### GET /api/modules/sirsoft-board/admin/boards/slug/{slug}
<!-- @generated:start:api.modules.sirsoft-board.admin.boards.show-by-slug -->
- **라우트명**: `api.modules.sirsoft-board.admin.boards.show-by-slug`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardController@showBySlug`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.boards.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/boards/slug/{slug} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 기본 키 (내부 식별자) |
| name | string | `API 문서 샘플 게시판` | 게시판명 (다국어 JSON) |
| slug | string | `apidoc-sample-board` | 게시판 슬러그 (URL/테이블명) |
| is_active | boolean | `true` | active 여부 |
| type | string | `basic` | 게시판 타입 (basic, gallery, card 등) |
| description | string | `` | 게시판 설명 (다국어 JSON) |
| per_page | integer | `20` | 페이지당 게시글 수 (PC) |
| per_page_mobile | integer | `15` | 페이지당 게시글 수 (Mobile) |
| order_by | string | `created_at` | 정렬 기준 (created_at, view_count, title, author) |
| order_direction | string | `DESC` | 정렬 방향 (ASC, DESC) |
| categories | array | `[]` | 분류 목록 (배열) |
| show_view_count | boolean | `true` | 조회수 노출 |
| secret_mode | string | `disabled` | 비밀글 설정 (disabled: 사용안함, enabled: 사용함, always: 고정) |
| use_comment | boolean | `true` | 댓글 기능 사용 |
| use_reply | boolean | `true` | 게시글 답변 기능 사용 (댓글에 대한 답글 아님) |
| max_reply_depth | integer | `5` | 답변글 최대 깊이 (1~10) |
| reply_delete_policy | string | `cascade` | 답글 달린 글의 삭제 정책 (block: 답글이 살아 있으면 원글 삭제 차단, cascade: 원글 삭제 시 답글도 함께 소프트 삭제) |
| use_report | boolean | `true` | 게시글/댓글 신고 기능 사용 |
| comment_order | string | `ASC` | 댓글 정렬 순서 (ASC: 오름차순, DESC: 내림차순) |
| max_comment_depth | integer | `10` | 대댓글 최대 깊이 (1~10) |
| min_title_length | integer | `2` | 최소 제목 글자 수 |
| max_title_length | integer | `200` | 최대 제목 글자 수 |
| min_content_length | integer | `10` | 최소 게시글 글자 수 |
| max_content_length | integer | `10000` | 최대 게시글 글자 수 |
| min_comment_length | integer | `2` | 최소 댓글 글자 수 |
| max_comment_length | integer | `1000` | 최대 댓글 글자 수 |
| blocked_keywords | array | `[]` | 금지어 목록 (배열) |
| use_file_upload | boolean | `true` | 파일 업로드 사용 |
| max_file_size | integer | `10` | 최대 파일 크기 (MB) |
| max_file_count | integer | `5` | max file 개수 (집계) |
| allowed_extensions | array | `["jpg","jpeg","png","gif","pdf","zip"]` | 허용 확장자 배열 |
| add_to_menu | null | `null` | 관리자 메뉴 등록 여부 (폼 요청에서만 채워지는 토글 초기값 — 조회 응답에서는 항상 null) |
| new_display_hours | integer | `24` | 신규 게시글 표시 기간 (시간 단위) |
| board_managers | array | `[]` | 게시판 관리자로 지정된 사용자 목록 (manager 역할 사용자의 uuid/name/email — 역할 기반 파생) |
| board_steps | array | `[]` | 게시판 승인/처리 담당자(스텝)로 지정된 사용자 목록 (step 역할 사용자의 uuid/name/email — 역할 기반 파생) |
| board_manager_ids | array | `[]` | board manager 식별자 배열 (연관 리소스 참조) |
| board_step_ids | array | `[]` | board step 식별자 배열 (연관 리소스 참조) |
| notify_author | boolean | `true` | 작성자 이메일 알림 (댓글, 대댓글, 답변글, 관리자 처리 시) |
| notify_admin_on_post | boolean | `true` | 관리자 이메일 알림 (게시글 등록 시) |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| updated_at | string | `2026-07-07 09:34:50` | 최종 수정 일시 |
| permissions | null | `null` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| category_post_counts | null | `null` | 분류별 게시글 개수 맵 (요청 시 조건부로만 채워지며, 미포함 시 null) |
| posts_count | integer | `0` | posts 개수 (집계) |
| user_abilities | object | `{"can_read":true,"can_write":true,"can_read_secret":true,…` | 현재 사용자의 게시판별 세부 권한 맵 (can_read/can_write/can_read_secret/can_read_comments/can_write_comments/can_upload/can_download/can_manage — 관리자 라우트에서는 항상 포함) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시판 정보를 조회했습니다.",
    "data": {
        "id": 1,
        "name": "API 문서 샘플 게시판",
        "slug": "apidoc-sample-board",
        "is_active": true,
        "type": "basic",
        "description": "",
        "per_page": 20,
        "per_page_mobile": 15,
        "order_by": "created_at",
        "order_direction": "DESC",
        "categories": [],
        "show_view_count": true,
        "secret_mode": "{MASKED}",
        "use_comment": true,
        "use_reply": true,
        "max_reply_depth": 5,
        "reply_delete_policy": "cascade",
        "use_report": true,
        "comment_order": "ASC",
        "max_comment_depth": 10,
        "min_title_length": 2,
        "max_title_length": 200,
        "min_content_length": 10,
        "max_content_length": 10000,
        "min_comment_length": 2,
        "max_comment_length": 1000,
        "blocked_keywords": [],
        "use_file_upload": true,
        "max_file_size": 10,
        "max_file_count": 5,
        "allowed_extensions": [
            "jpg",
            "jpeg",
            "png",
            "gif",
            "pdf",
            "zip"
        ],
        "add_to_menu": null,
        "new_display_hours": 24,
        "board_managers": [],
        "board_steps": [],
        "board_manager_ids": [],
        "board_step_ids": [],
        "notify_author": true,
        "notify_admin_on_post": true,
        "created_at": "2026-07-08 10:41:34",
        "updated_at": "2026-07-08 10:41:34",
        "permissions": null,
        "category_post_counts": null,
        "posts_count": 0,
        "user_abilities": {
            "can_read": true,
            "can_write": true,
            "can_read_secret": "{MASKED}",
            "can_read_comments": true,
            "can_write_comments": true,
            "can_upload": true,
            "can_download": true,
            "can_manage": true
        },
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.boards.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 슬러그로 게시판 상세를 조회합니다(관리자용). `auth:sanctum` + `sirsoft-board.boards.read` 권한이 필요하며, ID 대신 슬러그로 접근하는 화면(게시글 관리·글쓰기 진입 등)에서 사용합니다. 관리자 라우트이므로 항상 사용자 권한 맵(`user_abilities`)을 포함하며, `parent_id` 쿼리 파라미터가 있으면 답변글 작성을 위해 원글 정보와 기본 제목(`RE: ...`)을 `parent_post` 로 함께 반환합니다.


### DELETE /api/modules/sirsoft-board/admin/boards/{board}
<!-- @generated:start:api.modules.sirsoft-board.admin.boards.destroy -->
- **라우트명**: `api.modules.sirsoft-board.admin.boards.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.boards.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| board | path | string | 예 | — | 대상 board의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/admin/boards/{board} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만). 컨트롤러가 `success('...boards.delete_success')` 를 데이터 없이 호출하므로 `data` 는 항상 `null` 입니다._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시판이 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.boards.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판을 삭제합니다. `auth:sanctum` + `sirsoft-board.boards.delete` 권한이 필요하며, 요청의 `force_delete` 불리언 플래그로 삭제 방식을 결정합니다. 게시판 삭제는 소속 게시글·댓글·첨부·권한까지 연쇄 정리를 수반하므로 되돌릴 수 없다는 점에 주의해야 합니다.


### GET /api/modules/sirsoft-board/admin/boards/{board}
<!-- @generated:start:api.modules.sirsoft-board.admin.boards.show -->
- **라우트명**: `api.modules.sirsoft-board.admin.boards.show`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardController@show`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.boards.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| board | path | string | 예 | — | 대상 board의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/boards/{board} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 기본 키 (내부 식별자) |
| name | string | `API 문서 샘플 게시판` | 게시판명 (다국어 JSON) |
| slug | string | `apidoc-sample-board` | 게시판 슬러그 (URL/테이블명) |
| is_active | boolean | `true` | active 여부 |
| type | string | `basic` | 게시판 타입 (basic, gallery, card 등) |
| description | string | `` | 게시판 설명 (다국어 JSON) |
| per_page | integer | `20` | 페이지당 게시글 수 (PC) |
| per_page_mobile | integer | `15` | 페이지당 게시글 수 (Mobile) |
| order_by | string | `created_at` | 정렬 기준 (created_at, view_count, title, author) |
| order_direction | string | `DESC` | 정렬 방향 (ASC, DESC) |
| categories | array | `[]` | 분류 목록 (배열) |
| show_view_count | boolean | `true` | 조회수 노출 |
| secret_mode | string | `disabled` | 비밀글 설정 (disabled: 사용안함, enabled: 사용함, always: 고정) |
| use_comment | boolean | `true` | 댓글 기능 사용 |
| use_reply | boolean | `true` | 게시글 답변 기능 사용 (댓글에 대한 답글 아님) |
| max_reply_depth | integer | `5` | 답변글 최대 깊이 (1~10) |
| reply_delete_policy | string | `cascade` | 답글 달린 글의 삭제 정책 (block: 답글이 살아 있으면 원글 삭제 차단, cascade: 원글 삭제 시 답글도 함께 소프트 삭제) |
| use_report | boolean | `true` | 게시글/댓글 신고 기능 사용 |
| comment_order | string | `ASC` | 댓글 정렬 순서 (ASC: 오름차순, DESC: 내림차순) |
| max_comment_depth | integer | `10` | 대댓글 최대 깊이 (1~10) |
| min_title_length | integer | `2` | 최소 제목 글자 수 |
| max_title_length | integer | `200` | 최대 제목 글자 수 |
| min_content_length | integer | `10` | 최소 게시글 글자 수 |
| max_content_length | integer | `10000` | 최대 게시글 글자 수 |
| min_comment_length | integer | `2` | 최소 댓글 글자 수 |
| max_comment_length | integer | `1000` | 최대 댓글 글자 수 |
| blocked_keywords | array | `[]` | 금지어 목록 (배열) |
| use_file_upload | boolean | `true` | 파일 업로드 사용 |
| max_file_size | integer | `10` | 최대 파일 크기 (MB) |
| max_file_count | integer | `5` | max file 개수 (집계) |
| allowed_extensions | array | `["jpg","jpeg","png","gif","pdf","zip"]` | 허용 확장자 배열 |
| add_to_menu | null | `null` | 관리자 메뉴 등록 여부 (폼 요청에서만 채워지는 토글 초기값 — 조회 응답에서는 항상 null) |
| new_display_hours | integer | `24` | 신규 게시글 표시 기간 (시간 단위) |
| board_managers | array | `[]` | 게시판 관리자로 지정된 사용자 목록 (manager 역할 사용자의 uuid/name/email — 역할 기반 파생) |
| board_steps | array | `[]` | 게시판 승인/처리 담당자(스텝)로 지정된 사용자 목록 (step 역할 사용자의 uuid/name/email — 역할 기반 파생) |
| board_manager_ids | array | `[]` | board manager 식별자 배열 (연관 리소스 참조) |
| board_step_ids | array | `[]` | board step 식별자 배열 (연관 리소스 참조) |
| notify_author | boolean | `true` | 작성자 이메일 알림 (댓글, 대댓글, 답변글, 관리자 처리 시) |
| notify_admin_on_post | boolean | `true` | 관리자 이메일 알림 (게시글 등록 시) |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| updated_at | string | `2026-07-07 09:34:50` | 최종 수정 일시 |
| permissions | object | `{"admin_posts_read":{"_key":"admin_posts_read","name":"ad…` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| category_post_counts | null | `null` | 분류별 게시글 개수 맵 (요청 시 조건부로만 채워지며, 미포함 시 null) |
| posts_count | integer | `0` | posts 개수 (집계) |
| user_abilities | null | `null` | 현재 사용자의 게시판별 세부 권한 맵 (can_read/can_write/can_read_secret/can_manage 등 — include_user_abilities 요청 시에만 채워지며 미포함 시 null) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시판 정보를 조회했습니다.",
    "data": {
        "id": 1,
        "name": "API 문서 샘플 게시판",
        "slug": "apidoc-sample-board",
        "is_active": true,
        "type": "basic",
        "description": "",
        "per_page": 20,
        "per_page_mobile": 15,
        "order_by": "created_at",
        "order_direction": "DESC",
        "categories": [],
        "show_view_count": true,
        "secret_mode": "{MASKED}",
        "use_comment": true,
        "use_reply": true,
        "max_reply_depth": 5,
        "reply_delete_policy": "cascade",
        "use_report": true,
        "comment_order": "ASC",
        "max_comment_depth": 10,
        "min_title_length": 2,
        "max_title_length": 200,
        "min_content_length": 10,
        "max_content_length": 10000,
        "min_comment_length": 2,
        "max_comment_length": 1000,
        "blocked_keywords": [],
        "use_file_upload": true,
        "max_file_size": 10,
        "max_file_count": 5,
        "allowed_extensions": [
            "jpg",
            "jpeg",
            "png",
            "gif",
            "pdf",
            "zip"
        ],
        "add_to_menu": null,
        "new_display_hours": 24,
        "board_managers": [],
        "board_steps": [],
        "board_manager_ids": [],
        "board_step_ids": [],
        "notify_author": true,
        "notify_admin_on_post": true,
        "created_at": "2026-07-08 10:41:34",
        "updated_at": "2026-07-08 10:41:34",
        "permissions": {
            "admin_posts_read": {
                "_key": "admin_posts_read",
                "name": "admin.posts.read",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_posts_write": {
                "_key": "admin_posts_write",
                "name": "admin.posts.write",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_posts_read-secret": {
                "_key": "admin_posts_read-secret",
                "name": "admin.posts.read-secret",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_comments_read": {
                "_key": "admin_comments_read",
                "name": "admin.comments.read",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_comments_write": {
                "_key": "admin_comments_write",
                "name": "admin.comments.write",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_attachments_upload": {
                "_key": "admin_attachments_upload",
                "name": "admin.attachments.upload",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_attachments_download": {
                "_key": "admin_attachments_download",
                "name": "admin.attachments.download",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_manage": {
                "_key": "admin_manage",
                "name": "admin.manage",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "posts_read": {
                "_key": "posts_read",
                "name": "posts.read",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user",
                    "guest"
                ]
            },
            "posts_write": {
                "_key": "posts_write",
                "name": "posts.write",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user"
                ]
            },
            "posts_read-secret": {
                "_key": "posts_read-secret",
                "name": "posts.read-secret",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "comments_read": {
                "_key": "comments_read",
                "name": "comments.read",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user",
                    "guest"
                ]
            },
            "comments_write": {
                "_key": "comments_write",
                "name": "comments.write",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user"
                ]
            },
            "attachments_upload": {
                "_key": "attachments_upload",
                "name": "attachments.upload",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user"
                ]
            },
            "attachments_download": {
                "_key": "attachments_download",
                "name": "attachments.download",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user",
                    "guest"
                ]
            },
            "manager": {
                "_key": "manager",
                "name": "manager",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            }
        },
        "category_post_counts": null,
        "posts_count": 0,
        "user_abilities": null,
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.boards.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** ID로 게시판 상세를 조회합니다(관리자용). `auth:sanctum` + `sirsoft-board.boards.read` 권한이 필요하며, 게시판 설정 편집·상세 확인 화면에서 사용합니다. 게시판 권한 매트릭스(`permissions`)를 포함한 전체 설정 필드를 반환합니다.


### PUT /api/modules/sirsoft-board/admin/boards/{board}
<!-- @generated:start:api.modules.sirsoft-board.admin.boards.update -->
- **라우트명**: `api.modules.sirsoft-board.admin.boards.update`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.boards.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| board | path | string | 예 | — | 대상 board의 식별자 |
| name | body | array | 예 | — | 대상의 이름/명칭 |
| description | body | array | 아니오 | — | 설명 |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| type | body | string | 예 | max 50 | 게시판 타입 슬러그 (basic, gallery, card 등 — 등록된 board_types 값 중 하나) |
| add_to_menu | body | boolean | 아니오 | — | 저장 시 이 게시판을 관리자 메뉴에 자동 등록할지 여부 (DB 컬럼 아님 — 메뉴 등록 처리에만 사용) |
| per_page | body | integer | 예 | min 5, max 100 | 페이지당 항목 수 |
| per_page_mobile | body | integer | 예 | min 5, max 100 | 모바일 화면에서 페이지당 표시할 게시글 수 |
| order_by | body | string | 예 | `created_at`, `view_count`, `title`, `author` | 정렬 기준 필드명 |
| order_direction | body | string | 예 | `ASC`, `DESC` | 게시글 목록 정렬 방향 (ASC 오름차순 / DESC 내림차순) |
| categories | body | array | 아니오 | max 50 | 게시글 분류(카테고리) 이름 목록 (최대 50개, 빈/공백 이름 불가) |
| show_view_count | body | boolean | 예 | — | 게시글 목록/상세에 조회수를 노출할지 여부 |
| secret_mode | body | string | 예 | `disabled`, `enabled`, `always` | 비밀글 설정 (disabled 사용 안 함 / enabled 작성자 선택 가능 / always 전 글 비밀글 강제) |
| use_comment | body | boolean | 예 | — | 댓글 기능 사용 여부 |
| use_reply | body | boolean | 예 | — | 게시글 답변(원글에 대한 답글) 기능 사용 여부 |
| use_report | body | boolean | 예 | — | 게시글/댓글 신고 기능 사용 여부 |
| comment_order | body | string | 예 | `ASC`, `DESC` | 댓글 정렬 순서 (ASC 오름차순 / DESC 내림차순) |
| new_display_hours | body | integer | 아니오 | min 0, max 720 | 신규(NEW) 표시를 유지할 기간 (시간 단위, 최대 720시간=30일). `0` 은 NEW 배지를 표시하지 않음 |
| min_title_length | body | integer | 아니오 | min 0, max 200 | 게시글 제목 최소 글자 수 |
| max_title_length | body | integer | 아니오 | min 1, max 1000 | 게시글 제목 최대 글자 수 |
| min_content_length | body | integer | 아니오 | min 0, max 10000 | 게시글 본문 최소 글자 수 |
| max_content_length | body | integer | 아니오 | min 1, max 100000 | 게시글 본문 최대 글자 수 |
| min_comment_length | body | integer | 아니오 | min 0, max 1000 | 댓글 최소 글자 수 |
| max_comment_length | body | integer | 아니오 | min 1, max 10000 | 댓글 최대 글자 수 |
| use_file_upload | body | boolean | 예 | — | 파일 첨부 기능 사용 여부 (true일 때 allowed_extensions 최소 1개 필수) |
| max_file_size | body | integer | 아니오 | min 1, max 200 | 첨부파일 1개당 최대 크기 (MB 단위) |
| max_file_count | body | integer | 아니오 | min 1, max 20 | 게시글 1건당 첨부할 수 있는 최대 파일 개수 |
| allowed_extensions | body | array | 조건부 | min 1 | 업로드 허용 확장자 목록 (예: jpg, png, pdf). `use_file_upload=true` 일 때만 필수이며 최소 1개가 있어야 한다. `use_file_upload=false` 이면 검증에서 제외되어 빈 배열·`null` 도 허용된다. **`allowed_extensions` 만 보내고 `use_file_upload` 를 생략하면 저장된 게시판의 값을 기준으로 판정한다** — 첨부를 쓰지 않는 게시판은 빈 배열도 통과하고, 첨부를 쓰는 게시판은 그대로 차단된다 |
| board_manager_ids | body | array | 예 | min 1 | board manager 식별자 배열 |
| board_step_ids | body | array | 아니오 | — | board step 식별자 배열 |
| permissions | body | array | 예 | — | 게시판별 세부 권한 매트릭스 (권한 키별 mode/roles — 각 권한을 all 또는 특정 역할에 부여) |
| max_reply_depth | body | integer | 아니오 | min 1, max 10 | 답변글 최대 중첩 깊이 |
| reply_delete_policy | body | string | 아니오 | `block`, `cascade` | 답글 달린 글의 삭제 정책 (block 답글이 살아 있으면 원글 삭제 차단 / cascade 원글 삭제 시 답글도 함께 소프트 삭제). 미전송 시 기존값 유지, 허용값 밖이면 422 |
| max_comment_depth | body | integer | 아니오 | min 0, max 10 | 대댓글 최대 중첩 깊이 |
| notify_admin_on_post | body | boolean | 예 | — | 게시글 등록 시 관리자에게 이메일 알림 발송 여부 |
| notify_author | body | boolean | 예 | — | 댓글·대댓글·답변글·관리자 처리 발생 시 작성자에게 이메일 알림 발송 여부 |
| blocked_keywords | body | array | 아니오 | — | 게시글/댓글 작성 시 차단할 금지어 목록 (각 항목 최대 100자, 쉼표 구분 문자열도 허용) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.board.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-board/admin/boards/{board} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": [
        "예시 이름"
    ],
    "description": [
        "예시 내용입니다."
    ],
    "is_active": true,
    "type": "예시값",
    "add_to_menu": true,
    "per_page": 1,
    "per_page_mobile": 1,
    "order_by": "created_at",
    "order_direction": "ASC",
    "categories": [
        "예시값"
    ],
    "show_view_count": true,
    "secret_mode": "disabled",
    "use_comment": true,
    "use_reply": true,
    "use_report": true,
    "comment_order": "ASC",
    "new_display_hours": 1,
    "min_title_length": 1,
    "max_title_length": 1,
    "min_content_length": 1,
    "max_content_length": 1,
    "min_comment_length": 1,
    "max_comment_length": 1,
    "use_file_upload": true,
    "max_file_size": 1,
    "max_file_count": 1,
    "allowed_extensions": [
        "예시값"
    ],
    "board_manager_ids": [
        "예시값"
    ],
    "board_step_ids": [
        "예시값"
    ],
    "permissions": [
        "예시값"
    ],
    "max_reply_depth": 1,
    "reply_delete_policy": "block",
    "max_comment_depth": 1,
    "notify_admin_on_post": true,
    "notify_author": true,
    "blocked_keywords": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (수정된 게시판 = `BoardResource`, 상세 조회(`GET /admin/boards/{board}`)와 동일 스키마)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 기본 키 (내부 식별자) |
| name | string | `API 문서 샘플 게시판` | 게시판명 (다국어 JSON) |
| slug | string | `apidoc-sample-board` | 게시판 슬러그 (생성 시 고정 — 수정 대상 아님) |
| is_active | boolean | `true` | active 여부 |
| type | string | `basic` | 게시판 타입 (basic, gallery, card 등) |
| description | string | `` | 게시판 설명 (다국어 JSON) |
| per_page | integer | `20` | 페이지당 게시글 수 (PC) |
| per_page_mobile | integer | `15` | 페이지당 게시글 수 (Mobile) |
| order_by | string | `created_at` | 정렬 기준 (created_at, view_count, title, author) |
| order_direction | string | `DESC` | 정렬 방향 (ASC, DESC) |
| categories | array | `[]` | 분류 목록 (배열) |
| show_view_count | boolean | `true` | 조회수 노출 |
| secret_mode | string | `disabled` | 비밀글 설정 (disabled/enabled/always) |
| use_comment | boolean | `true` | 댓글 기능 사용 |
| use_reply | boolean | `true` | 게시글 답변 기능 사용 |
| max_reply_depth | integer | `5` | 답변글 최대 깊이 |
| reply_delete_policy | string | `cascade` | 답글 달린 글의 삭제 정책 (block: 답글이 살아 있으면 원글 삭제 차단, cascade: 원글 삭제 시 답글도 함께 소프트 삭제) |
| use_report | boolean | `true` | 게시글/댓글 신고 기능 사용 |
| comment_order | string | `ASC` | 댓글 정렬 순서 (ASC, DESC) |
| max_comment_depth | integer | `10` | 대댓글 최대 깊이 |
| min_title_length | integer | `2` | 최소 제목 글자 수 |
| max_title_length | integer | `200` | 최대 제목 글자 수 |
| min_content_length | integer | `10` | 최소 게시글 글자 수 |
| max_content_length | integer | `10000` | 최대 게시글 글자 수 |
| min_comment_length | integer | `2` | 최소 댓글 글자 수 |
| max_comment_length | integer | `1000` | 최대 댓글 글자 수 |
| blocked_keywords | array | `[]` | 금지어 목록 (배열) |
| use_file_upload | boolean | `true` | 파일 업로드 사용 |
| max_file_size | integer | `10` | 최대 파일 크기 (MB) |
| max_file_count | integer | `5` | 게시글 1건당 최대 첨부 개수 |
| allowed_extensions | array | `["jpg","png","pdf"]` | 허용 확장자 배열 |
| add_to_menu | null | `null` | 관리자 메뉴 등록 여부 (폼 요청 전용 토글 초기값 — 조회 응답에서는 항상 null) |
| new_display_hours | integer | `24` | 신규 게시글 표시 기간 (시간 단위) |
| board_managers | array | `[]` | 게시판 관리자 사용자 목록 (uuid/name/email) |
| board_steps | array | `[]` | 게시판 승인/처리 담당자(스텝) 사용자 목록 (uuid/name/email) |
| board_manager_ids | array | `[]` | board manager 식별자(uuid) 배열 |
| board_step_ids | array | `[]` | board step 식별자(uuid) 배열 |
| notify_author | boolean | `true` | 작성자 이메일 알림 |
| notify_admin_on_post | boolean | `true` | 관리자 이메일 알림 (게시글 등록 시) |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| updated_at | string | `2026-07-08 11:02:10` | 최종 수정 일시 (수정 후 갱신됨) |
| permissions | object \| null | `null` | 게시판별 권한 매트릭스 (`include_permissions` 요청 시에만 채워짐) |
| category_post_counts | null | `null` | 분류별 게시글 개수 맵 (조건부) |
| posts_count | integer | `0` | 게시글 수 (집계) |
| user_abilities | null | `null` | 현재 사용자의 게시판별 세부 권한 맵 (`include_user_abilities` 요청 시에만 채워짐) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시판이 수정되었습니다.",
    "data": {
        "id": 12,
        "name": "API 문서 샘플 게시판",
        "slug": "apidoc-sample-board",
        "is_active": true,
        "type": "basic",
        "description": "",
        "per_page": 20,
        "per_page_mobile": 15,
        "order_by": "created_at",
        "order_direction": "DESC",
        "categories": [],
        "show_view_count": true,
        "secret_mode": "disabled",
        "use_comment": true,
        "use_reply": true,
        "max_reply_depth": 5,
        "reply_delete_policy": "cascade",
        "use_report": true,
        "comment_order": "ASC",
        "max_comment_depth": 10,
        "min_title_length": 2,
        "max_title_length": 200,
        "min_content_length": 10,
        "max_content_length": 10000,
        "min_comment_length": 2,
        "max_comment_length": 1000,
        "blocked_keywords": [],
        "use_file_upload": true,
        "max_file_size": 10,
        "max_file_count": 5,
        "allowed_extensions": [
            "jpg",
            "png",
            "pdf"
        ],
        "add_to_menu": null,
        "new_display_hours": 24,
        "board_managers": [],
        "board_steps": [],
        "board_manager_ids": [],
        "board_step_ids": [],
        "notify_author": true,
        "notify_admin_on_post": true,
        "created_at": "2026-07-08 10:41:34",
        "updated_at": "2026-07-08 11:02:10",
        "permissions": null,
        "category_post_counts": null,
        "posts_count": 0,
        "user_abilities": null,
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.boards.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 수정 처리 중 예외 (`게시판 수정에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시판 설정을 수정합니다. `auth:sanctum` + `sirsoft-board.boards.update` 권한이 필요하며, `UpdateBoardRequest` 로 검증된 값으로 이름/설명/제한값/권한 매트릭스 등을 갱신합니다. 슬러그와 타입은 생성 시 고정되므로 이 요청으로는 변경 대상이 아니며, 성공 시 갱신된 게시판 리소스를 반환합니다.


### POST /api/modules/sirsoft-board/admin/boards/{board}/add-to-menu
<!-- @generated:start:api.modules.sirsoft-board.admin.boards.add-to-menu -->
- **라우트명**: `api.modules.sirsoft-board.admin.boards.add-to-menu`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardController@addToAdminMenu`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.boards.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| board | path | string | 예 | — | 대상 board의 식별자 |

**요청 예시**

```http
POST /api/modules/sirsoft-board/admin/boards/{board}/add-to-menu HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| menu | object | `{"name":{"ko":"API 문서 샘플 게시판 게시판","en":"API Doc Sample Bo…` | 이 게시판용으로 새로 생성된 관리자 사이드바 메뉴 항목 (name/slug/url/icon/parent_id/order 등 Menu 레코드 전체) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "관리자 메뉴에 추가되었습니다.",
    "data": {
        "menu": {
            "name": {
                "ko": "API 문서 샘플 게시판 게시판",
                "en": "API Doc Sample Board Board"
            },
            "slug": "board-apidoc-sample-board",
            "url": "/admin/board/apidoc-sample-board",
            "icon": "fas fa-clipboard-list",
            "parent_id": 15,
            "is_active": true,
            "extension_type": "module",
            "extension_identifier": "sirsoft-board",
            "order": 4,
            "updated_at": "2026-07-08T06:01:45.000000Z",
            "created_at": "2026-07-08T06:01:45.000000Z",
            "id": 27
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.boards.update`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 해당 게시판을 관리자 메뉴에 등록합니다. `auth:sanctum` + `sirsoft-board.boards.update` 권한이 필요하며, 관리자 사이드바에서 게시판별 관리 메뉴를 바로 진입할 수 있도록 메뉴 항목을 생성합니다. 이미 등록된 게시판이면 `MenuAlreadyExistsException` 이 발생해 중복 등록을 막습니다.


### GET /api/modules/sirsoft-board/boards
<!-- @generated:start:api.modules.sirsoft-board.boards.index -->
- **라우트명**: `api.modules.sirsoft-board.boards.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\BoardController@index`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 기본 키 (내부 식별자) |
| name | string | `API 문서 샘플 게시판` | 게시판명 (다국어 JSON) |
| slug | string | `apidoc-sample-board` | 게시판 슬러그 (URL/테이블명) |
| description | string | `` | 게시판 설명 (다국어 JSON) |
| posts_count | integer | `0` | posts 개수 (집계) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": [
        {
            "id": 1,
            "name": "API 문서 샘플 게시판",
            "slug": "apidoc-sample-board",
            "description": "",
            "posts_count": 0,
            "abilities": {
                "can_create": true,
                "can_update": true,
                "can_delete": true
            }
        }
    ]
}
```

**에러 응답**

_에러 없음 (공개 조회 — 컨트롤러가 캐시된 조회 결과를 그대로 반환하며 인증·권한·검증 실패 외 도메인 특이 에러를 던지지 않습니다)._

<!-- @generated:end -->

**설명** 사용자 화면용 활성 게시판 목록을 경량으로 조회합니다. 전체 게시판 목록 페이지에서 사용하며, `id`/`name`/`slug`/`description`/`posts_count` 만 반환합니다. `limit` 파라미터(0~10, 기본 0)를 주면 게시판별 최신글도 함께 담아주며(답변글·삭제·블라인드 제외), 활성화된 게시판만 노출됩니다.


### GET /api/modules/sirsoft-board/boards/board-menu
<!-- @generated:start:api.modules.sirsoft-board.boards.board-menu -->
- **라우트명**: `api.modules.sirsoft-board.boards.board-menu`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\BoardController@boardMenu`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/board-menu HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | string | `테스트 게시판` | 게시판명 (다국어 JSON) |
| slug | string | `test` | 게시판 슬러그 (URL/테이블명) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": [
        {
            "id": 1,
            "name": "API 문서 샘플 게시판",
            "slug": "apidoc-sample-board"
        }
    ]
}
```

**에러 응답**

_에러 없음 (공개 조회 — 컨트롤러가 캐시된 조회 결과를 그대로 반환하며 인증·권한·검증 실패 외 도메인 특이 에러를 던지지 않습니다)._

<!-- @generated:end -->

**설명** 헤더/네비게이션 메뉴에 노출할 게시판 목록을 최소 필드(`id`/`name`/`slug`)로 반환합니다. 활성 게시판만 오래된 순(created_at ASC)으로 정렬해 캐시에서 제공하는 경량 조회 API입니다.


### GET /api/modules/sirsoft-board/boards/popular
<!-- @generated:start:api.modules.sirsoft-board.boards.popular -->
- **라우트명**: `api.modules.sirsoft-board.boards.popular`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\BoardController@popular`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/popular HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| board_slug | string | `gallery` | 이 게시글이 속한 게시판의 slug (게시판 URL 식별자) |
| board_name | string | `갤러리` | 이 게시글이 속한 게시판의 이름 (현재 로케일로 해석된 표시명) |
| title | string | `일반 게시글 (이미지 있음)` | 제목 |
| excerpt | string | `이미지가 첨부된 일반 게시글입니다. 정상적으로 표시됩니다.` | 본문 요약 발췌 (HTML 태그 제거 후 앞 100자) |
| author | object | `{"id":1,"name":"관리자","email":"heuristing@gmail.com","is_g…` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| view_count | integer | `258` | view 개수 (집계) |
| comment_count | integer | `2` | comment 개수 (집계) |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| created_at_formatted | string | `07-10` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": [
        {
            "id": 1,
            "board_slug": "apidoc-sample-board",
            "board_name": "API 문서 샘플 게시판",
            "title": "API 문서 샘플 게시글",
            "excerpt": "API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.",
            "author": {
                "id": 6,
                "name": "API 문서 샘플 사용자",
                "email": "apidoc-sample-user@example.com",
                "is_guest": false,
                "avatar": null,
                "status": "active",
                "status_label": "활성"
            },
            "view_count": 44,
            "comment_count": 0,
            "created_at": "2026-07-08 10:41:34",
            "created_at_formatted": "4시간 전"
        }
    ]
}
```

**에러 응답**

_에러 없음 (공개 조회 — 컨트롤러가 캐시된 조회 결과를 그대로 반환하며 인증·권한·검증 실패 외 도메인 특이 에러를 던지지 않습니다)._

<!-- @generated:end -->

**설명** 조회수가 많은 인기 게시글 목록을 반환합니다. 홈/사이드바 위젯용 경량 조회로, `period`(today·week·month·year, 기본 week; `all` 은 하위 호환으로 year 로 매핑)로 기간을, `limit`(기본 20, 최대 50)으로 개수를 조절합니다. 결과는 캐시에서 제공됩니다.


### GET /api/modules/sirsoft-board/boards/popular-boards
<!-- @generated:start:api.modules.sirsoft-board.boards.popular-boards -->
- **라우트명**: `api.modules.sirsoft-board.boards.popular-boards`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\BoardController@popularBoards`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/popular-boards HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `6` | 기본 키 (내부 식별자) |
| name | string | `Q&A` | 게시판명 (다국어 JSON) |
| slug | string | `qna` | 게시판 슬러그 (URL/테이블명) |
| description | string | `궁금한 점을 질문하고 답변을 받으세요` | 게시판 설명 (다국어 JSON) |
| posts_count | integer | `40` | posts 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": [
        {
            "id": 1,
            "name": "API 문서 샘플 게시판",
            "slug": "apidoc-sample-board",
            "description": "",
            "posts_count": 0
        }
    ]
}
```

**에러 응답**

_에러 없음 (공개 조회 — 컨트롤러가 캐시된 조회 결과를 그대로 반환하며 인증·권한·검증 실패 외 도메인 특이 에러를 던지지 않습니다)._

<!-- @generated:end -->

**설명** 게시글 수가 많은 인기 게시판 목록을 반환합니다. 홈 화면 등에서 활발한 게시판을 노출하는 데 사용하며, `limit`(기본 4, 최대 20)으로 개수를 조절합니다. 결과는 캐시에서 제공됩니다.


### GET /api/modules/sirsoft-board/boards/posts/recent
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.recent -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.recent`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\BoardController@recentPosts`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/posts/recent HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `237` | 기본 키 (내부 식별자) |
| board_slug | string | `apidoc-sample-board` | 게시글이 속한 게시판의 슬러그 (게시글 URL 구성용) |
| board_name | string | `API 문서 샘플 게시판` | 게시글이 속한 게시판의 다국어 이름 |
| title | string | `API 문서 샘플 게시글` | 제목 |
| author_name | string | `API 문서 샘플 사용자` | 작성자 표시 이름 (회원은 회원명, 비회원은 입력한 이름) |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| created_at_formatted | string | `방금 전` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| view_count | integer | `42` | view 개수 (집계) |
| comment_count | integer | `0` | comment 개수 (집계) |
| is_secret | boolean | `false` | secret 여부 |
| is_new | boolean | `true` | new 여부 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": [
        {
            "id": 1,
            "board_slug": "apidoc-sample-board",
            "board_name": "API 문서 샘플 게시판",
            "title": "API 문서 샘플 게시글",
            "author_name": "API 문서 샘플 사용자",
            "created_at": "2026-07-08 10:41:34",
            "created_at_formatted": "4시간 전",
            "view_count": 44,
            "comment_count": 0,
            "is_secret": "{MASKED}",
            "is_new": true
        }
    ]
}
```

**에러 응답**

_에러 없음 (공개 조회 — 컨트롤러가 캐시된 조회 결과를 그대로 반환하며 인증·권한·검증 실패 외 도메인 특이 에러를 던지지 않습니다)._

<!-- @generated:end -->

**설명** 모든 게시판을 통합한 최근 게시글 목록을 반환합니다. 홈 화면의 최신글 위젯 등에 사용하며, `limit`(기본 5, 최대 20)으로 개수를 조절합니다. 각 항목에는 게시판 슬러그/이름과 상대 시간(`created_at_formatted`), 신규 여부(`is_new`)가 함께 담기고, 결과는 캐시에서 제공됩니다.


### GET /api/modules/sirsoft-board/boards/stats
<!-- @generated:start:api.modules.sirsoft-board.boards.stats -->
- **라우트명**: `api.modules.sirsoft-board.boards.stats`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\BoardController@stats`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/stats HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| users | integer | `156` | 전체 회원 수 |
| boards | integer | `10` | 활성 게시판 수 |
| posts | integer | `116` | 전체 게시글 수 |
| comments | integer | `321` | 전체 댓글 수 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "users": 2,
        "boards": 1,
        "posts": 0,
        "comments": 0
    }
}
```

**에러 응답**

_에러 없음 (공개 조회 — 컨트롤러가 캐시된 조회 결과를 그대로 반환하며 인증·권한·검증 실패 외 도메인 특이 에러를 던지지 않습니다)._

<!-- @generated:end -->

**설명** 게시판 관련 집계 통계를 반환합니다. 홈 화면의 통계 카드 등에 사용하며, 회원 수·활성 게시판 수·전체 게시글 수·전체 댓글 수를 담아 캐시에서 제공하는 경량 조회 API입니다.


### GET /api/modules/sirsoft-board/boards/{slug}
<!-- @generated:start:api.modules.sirsoft-board.boards.show -->
- **라우트명**: `api.modules.sirsoft-board.boards.show`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\BoardController@show`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/{slug} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 기본 키 (내부 식별자) |
| name | string | `API 문서 샘플 게시판` | 게시판명 (다국어 JSON) |
| slug | string | `apidoc-sample-board` | 게시판 슬러그 (URL/테이블명) |
| is_active | boolean | `true` | active 여부 |
| type | string | `basic` | 게시판 타입 (basic, gallery, card 등) |
| description | string | `` | 게시판 설명 (다국어 JSON) |
| per_page | integer | `20` | 페이지당 게시글 수 (PC) |
| per_page_mobile | integer | `15` | 페이지당 게시글 수 (Mobile) |
| order_by | string | `created_at` | 정렬 기준 (created_at, view_count, title, author) |
| order_direction | string | `DESC` | 정렬 방향 (ASC, DESC) |
| categories | array | `[]` | 분류 목록 (배열) |
| show_view_count | boolean | `true` | 조회수 노출 |
| secret_mode | string | `disabled` | 비밀글 설정 (disabled: 사용안함, enabled: 사용함, always: 고정) |
| use_comment | boolean | `true` | 댓글 기능 사용 |
| use_reply | boolean | `true` | 게시글 답변 기능 사용 (댓글에 대한 답글 아님) |
| max_reply_depth | integer | `5` | 답변글 최대 깊이 (1~10) |
| reply_delete_policy | string | `cascade` | 답글 달린 글의 삭제 정책 (block: 답글이 살아 있으면 원글 삭제 차단, cascade: 원글 삭제 시 답글도 함께 소프트 삭제) |
| use_report | boolean | `true` | 게시글/댓글 신고 기능 사용 |
| comment_order | string | `ASC` | 댓글 정렬 순서 (ASC: 오름차순, DESC: 내림차순) |
| max_comment_depth | integer | `10` | 대댓글 최대 깊이 (1~10) |
| min_title_length | integer | `2` | 최소 제목 글자 수 |
| max_title_length | integer | `200` | 최대 제목 글자 수 |
| min_content_length | integer | `10` | 최소 게시글 글자 수 |
| max_content_length | integer | `10000` | 최대 게시글 글자 수 |
| min_comment_length | integer | `2` | 최소 댓글 글자 수 |
| max_comment_length | integer | `1000` | 최대 댓글 글자 수 |
| blocked_keywords | array | `[]` | 금지어 목록 (배열) |
| use_file_upload | boolean | `true` | 파일 업로드 사용 |
| max_file_size | integer | `10` | 최대 파일 크기 (MB) |
| max_file_count | integer | `5` | max file 개수 (집계) |
| allowed_extensions | array | `["jpg","jpeg","png","gif","pdf","zip"]` | 허용 확장자 배열 |
| add_to_menu | null | `null` | 관리자 메뉴 등록 여부 (폼 요청에서만 채워지는 토글 초기값 — 조회 응답에서는 항상 null) |
| new_display_hours | integer | `24` | 신규 게시글 표시 기간 (시간 단위) |
| board_managers | array | `[]` | 게시판 관리자로 지정된 사용자 목록 (manager 역할 사용자의 uuid/name/email — 역할 기반 파생) |
| board_steps | array | `[]` | 게시판 승인/처리 담당자(스텝)로 지정된 사용자 목록 (step 역할 사용자의 uuid/name/email — 역할 기반 파생) |
| board_manager_ids | array | `[]` | board manager 식별자 배열 (연관 리소스 참조) |
| board_step_ids | array | `[]` | board step 식별자 배열 (연관 리소스 참조) |
| notify_author | boolean | `true` | 작성자 이메일 알림 (댓글, 대댓글, 답변글, 관리자 처리 시) |
| notify_admin_on_post | boolean | `true` | 관리자 이메일 알림 (게시글 등록 시) |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| updated_at | string | `2026-07-07 09:34:50` | 최종 수정 일시 |
| permissions | object | `{"admin_posts_read":{"_key":"admin_posts_read","name":"ad…` | 연결된 권한 목록 (id/identifier/name — 역할 경유 권한 관계 파생) |
| category_post_counts | null | `null` | 분류별 게시글 개수 맵 (요청 시 조건부로만 채워지며, 미포함 시 null) |
| posts_count | integer | `0` | posts 개수 (집계) |
| user_abilities | null | `null` | 현재 사용자의 게시판별 세부 권한 맵 (can_read/can_write/can_read_secret/can_manage 등 — include_user_abilities 요청 시에만 채워지며 미포함 시 null) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "id": 1,
        "name": "API 문서 샘플 게시판",
        "slug": "apidoc-sample-board",
        "is_active": true,
        "type": "basic",
        "description": "",
        "per_page": 20,
        "per_page_mobile": 15,
        "order_by": "created_at",
        "order_direction": "DESC",
        "categories": [],
        "show_view_count": true,
        "secret_mode": "{MASKED}",
        "use_comment": true,
        "use_reply": true,
        "max_reply_depth": 5,
        "reply_delete_policy": "cascade",
        "use_report": true,
        "comment_order": "ASC",
        "max_comment_depth": 10,
        "min_title_length": 2,
        "max_title_length": 200,
        "min_content_length": 10,
        "max_content_length": 10000,
        "min_comment_length": 2,
        "max_comment_length": 1000,
        "blocked_keywords": [],
        "use_file_upload": true,
        "max_file_size": 10,
        "max_file_count": 5,
        "allowed_extensions": [
            "jpg",
            "jpeg",
            "png",
            "gif",
            "pdf",
            "zip"
        ],
        "add_to_menu": null,
        "new_display_hours": 24,
        "board_managers": [],
        "board_steps": [],
        "board_manager_ids": [],
        "board_step_ids": [],
        "notify_author": true,
        "notify_admin_on_post": true,
        "created_at": "2026-07-08 10:41:34",
        "updated_at": "2026-07-08 10:41:34",
        "permissions": {
            "admin_posts_read": {
                "_key": "admin_posts_read",
                "name": "admin.posts.read",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_posts_write": {
                "_key": "admin_posts_write",
                "name": "admin.posts.write",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_posts_read-secret": {
                "_key": "admin_posts_read-secret",
                "name": "admin.posts.read-secret",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_comments_read": {
                "_key": "admin_comments_read",
                "name": "admin.comments.read",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_comments_write": {
                "_key": "admin_comments_write",
                "name": "admin.comments.write",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_attachments_upload": {
                "_key": "admin_attachments_upload",
                "name": "admin.attachments.upload",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_attachments_download": {
                "_key": "admin_attachments_download",
                "name": "admin.attachments.download",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "admin_manage": {
                "_key": "admin_manage",
                "name": "admin.manage",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "posts_read": {
                "_key": "posts_read",
                "name": "posts.read",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user",
                    "guest"
                ]
            },
            "posts_write": {
                "_key": "posts_write",
                "name": "posts.write",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user"
                ]
            },
            "posts_read-secret": {
                "_key": "posts_read-secret",
                "name": "posts.read-secret",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            },
            "comments_read": {
                "_key": "comments_read",
                "name": "comments.read",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user",
                    "guest"
                ]
            },
            "comments_write": {
                "_key": "comments_write",
                "name": "comments.write",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user"
                ]
            },
            "attachments_upload": {
                "_key": "attachments_upload",
                "name": "attachments.upload",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user"
                ]
            },
            "attachments_download": {
                "_key": "attachments_download",
                "name": "attachments.download",
                "mode": "roles",
                "roles": [
                    "admin",
                    "user",
                    "guest"
                ]
            },
            "manager": {
                "_key": "manager",
                "name": "manager",
                "mode": "roles",
                "roles": [
                    "admin"
                ]
            }
        },
        "category_post_counts": null,
        "posts_count": 0,
        "user_abilities": null,
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 사용자 화면에서 특정 게시판의 상세 설정을 조회합니다. 게시판 목록/글쓰기 진입 시 게시판 헤더와 규칙(제목/본문 길이, 파일 업로드, 댓글 설정 등)을 렌더링하는 데 사용합니다. 스코프 권한 검사 없이 조회하되, 게시판이 없거나 비활성 상태면 존재 여부를 숨기기 위해 404를 반환합니다.


### GET /api/modules/sirsoft-board/boards/{slug}/attachment/{hash}
<!-- @generated:start:api.modules.sirsoft-board.boards.attachment.download -->
- **라우트명**: `api.modules.sirsoft-board.boards.attachment.download`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\AttachmentController@download`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.attachments.download`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| hash | path | string | 예 | — | 대상 리소스의 해시 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/{slug}/attachment/{hash} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON `data` 를 반환하지 않습니다. 성공 시 파일 바이너리를 `StreamedResponse` 로 스트리밍하며(`Content-Disposition: attachment`), JSON 봉투는 실패 시(404/403/500)에만 사용됩니다._

**응답 예시**

```http
HTTP/1.1 200
Content-Type: application/octet-stream
Content-Disposition: attachment; filename="example.pdf"

(파일 바이너리 스트림)
```

실패 시(첨부 미존재):

```json
{
    "success": false,
    "message": "파일을 찾을 수 없습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.attachments.download`)이 없거나 삭제글 첨부 등 접근이 차단된 경우 (`auth.scope_denied`) |
| 404 | Not Found | 게시판 슬러그 또는 해시에 해당하는 첨부가 없는 경우 (`파일을 찾을 수 없습니다.` / `게시판을 찾을 수 없습니다.`) |
| 500 | Internal Server Error | 파일 스트리밍 중 예외 (`파일 다운로드에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 해시로 지정한 첨부파일을 다운로드로 제공합니다(`Content-Disposition: attachment`). `auth:sanctum` + `sirsoft-board.{slug}.attachments.download` 권한이 필요하며, 이미지 포함 모든 파일을 다운로드 방식으로 스트리밍합니다. 이미지의 인라인 미리보기는 별도의 preview 엔드포인트를 사용하고, 삭제글 첨부 등 접근 차단 시 403을 반환합니다.


### GET /api/modules/sirsoft-board/boards/{slug}/attachment/{hash}/preview
<!-- @generated:start:api.modules.sirsoft-board.boards.attachment.preview -->
- **라우트명**: `api.modules.sirsoft-board.boards.attachment.preview`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\AttachmentController@preview`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| hash | path | string | 예 | — | 대상 리소스의 해시 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/{slug}/attachment/{hash}/preview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 JSON `data` 를 반환하지 않습니다. 성공 시 이미지 바이너리를 원본 MIME 타입으로 인라인 응답하며, 레이아웃 캐시 TTL(`cache.layout_ttl`, 기본 86400초) 기반 캐싱 헤더가 포함됩니다. JSON 봉투는 실패 시(400/403/404/500)에만 사용됩니다._

**응답 예시**

```http
HTTP/1.1 200
Content-Type: image/png
Cache-Control: public, max-age=86400

(이미지 바이너리)
```

실패 시(이미지가 아닌 파일 요청):

```json
{
    "success": false,
    "message": "이미지 파일만 미리보기가 가능합니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 대상 첨부가 이미지가 아닌 경우 (`이미지 파일만 미리보기가 가능합니다.`) |
| 403 | Forbidden | 삭제글 첨부 등 접근이 차단된 경우 (`auth.scope_denied`) |
| 404 | Not Found | 게시판 슬러그 또는 해시에 해당하는 첨부가 없는 경우 (`파일을 찾을 수 없습니다.` / `게시판을 찾을 수 없습니다.`) |
| 500 | Internal Server Error | 미리보기 처리 중 예외 (`이미지 미리보기에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 해시로 지정한 이미지 첨부파일을 인라인 미리보기로 제공합니다. 게시글 본문 내 이미지 표시용이라 비회원도 접근할 수 있으며(권한 체크 없음), 이미지가 아닌 파일 요청 시 400을 반환합니다. 응답에는 레이아웃 캐시 TTL(기본 24시간) 기반 캐싱 헤더가 포함되고, 삭제글·비밀글 첨부 등 차단 대상은 403을 반환합니다.

브라우저 `<img src>` 는 Authorization 헤더를 실을 수 없으므로, 비밀글·삭제글의 열람 권한자에게 직렬화되는 상세 응답의 `preview_url` 에는 `expires`·`signature` 쿼리가 붙은 **한시 서명 URL** 이 실리며, 이 엔드포인트는 유효한 서명을 열람 권한과 동등한 자격으로 허용합니다. 서명이 변조·만료된 요청은 종전과 동일하게 403 으로 차단됩니다. 정상글의 `preview_url` 은 무서명 공개 경로입니다.


### POST /api/modules/sirsoft-board/boards/{slug}/attachments
<!-- @generated:start:api.modules.sirsoft-board.boards.attachments.upload -->
- **라우트명**: `api.modules.sirsoft-board.boards.attachments.upload`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\AttachmentController@upload`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.attachments.upload`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| file | body | file | 예 | — | 업로드 파일 |
| post_id | body | integer | 아니오 | min 1 | post 식별자 |
| collection | body | string | 아니오 | max 100 | 첨부 컬렉션 그룹명 (첨부를 용도별로 묶는 키, 미지정 시 default) |
| temp_key | body | string | 아니오 | max 64 | 게시글 저장 전 임시 업로드를 묶는 키 (post_id가 없을 때 첨부를 임시로 그룹핑, 저장 시 게시글에 연결) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.attachment.upload_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-board/boards/{slug}/attachments HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: multipart/form-data; boundary=----G7ExampleBoundary

------G7ExampleBoundary
Content-Disposition: form-data; name="file"; filename="example.pdf"
Content-Type: application/octet-stream

(바이너리 파일 내용)
------G7ExampleBoundary
Content-Disposition: form-data; name="post_id"

1
------G7ExampleBoundary
Content-Disposition: form-data; name="collection"

예시값
------G7ExampleBoundary
Content-Disposition: form-data; name="temp_key"

예시값
------G7ExampleBoundary--
```

**응답 필드** (`data` 내부)

_단건 응답: FileUploader 컴포넌트가 `response.data?.data` 형식을 기대하므로, 업로드된 첨부 객체는 `data.data` 한 단계 더 안쪽에 담깁니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data.id | integer | `155` | 첨부파일 기본 키 (내부 식별자) |
| data.hash | string | `apidocsmpl1` | 첨부파일 해시 (다운로드/미리보기 URL의 식별자) |
| data.original_filename | string | `example.pdf` | 업로드 당시의 원본 파일명 |
| data.stored_filename | string | `20260708_ab12cd34.pdf` | 스토리지에 저장된 실제 파일명 |
| data.mime_type | string | `application/pdf` | 파일 MIME 타입 |
| data.size | integer | `102400` | 파일 크기 (바이트) |
| data.url | string | `/api/modules/sirsoft-board/boards/{slug}/attachment/{hash}` | 첨부 접근 URL (AttachmentService::getUrl 산출) |
| data.order | integer | `0` | 첨부 표시 순서 (reorder 로 갱신) |
| data.created_at | string | `2026-07-08 10:41:34` | 업로드 일시 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "파일이 업로드되었습니다.",
    "data": {
        "data": {
            "id": 155,
            "hash": "apidocsmpl1",
            "original_filename": "example.pdf",
            "stored_filename": "20260708_ab12cd34.pdf",
            "mime_type": "application/pdf",
            "size": 102400,
            "url": "/api/modules/sirsoft-board/boards/apidoc-sample-board/attachment/apidocsmpl1",
            "order": 0,
            "created_at": "2026-07-08 10:41:34"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.attachments.upload`)이 없거나, 게시판의 파일 업로드 설정이 꺼진 경우 (`이 게시판에서는 파일 업로드가 비활성화되어 있습니다.`) |
| 404 | Not Found | 게시판 슬러그에 해당하는 게시판이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 파일 저장 중 예외 (`파일 업로드에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시글 작성/수정 폼에서 단일 파일을 업로드합니다. `auth:sanctum` + `sirsoft-board.{slug}.attachments.upload` 권한이 필요하며, 게시판의 파일 업로드 설정이 꺼져 있으면 403을 반환합니다. 게시글 저장 전 임시 업로드를 위해 `temp_key` 로 묶어두거나 `post_id` 로 기존 글에 바로 연결할 수 있고, 성공 시 FileUploader 컴포넌트가 기대하는 `data.data` 형식으로 첨부 메타(해시·URL·순서 등)를 201로 반환합니다.


### PATCH /api/modules/sirsoft-board/boards/{slug}/attachments/reorder
<!-- @generated:start:api.modules.sirsoft-board.boards.attachments.reorder -->
- **라우트명**: `api.modules.sirsoft-board.boards.attachments.reorder`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\AttachmentController@reorder`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.attachments.upload`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| order | body | array | 예 | min 1 | 첨부 순서 항목 배열 (각 항목은 {id, order} — 첨부 ID별 새 순서 값) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.attachment.reorder_validation_rules`).

**요청 예시**

```http
PATCH /api/modules/sirsoft-board/boards/{slug}/attachments/reorder HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "order": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만). 컨트롤러가 `success('...attachment.reorder_success')` 를 데이터 없이 호출합니다._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "첨부파일 순서가 변경되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.attachments.upload`)이 없는 경우 |
| 404 | Not Found | 게시판 슬러그에 해당하는 게시판이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 순서 갱신 실패 또는 처리 중 예외 (`첨부파일 순서 변경에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시글에 첨부된 파일들의 표시 순서를 재정렬합니다. `auth:sanctum` + `sirsoft-board.{slug}.attachments.upload` 권한이 필요하며, FileUploader 가 보낸 `[{id, order}]` 배열을 받아 각 첨부의 순서 값을 갱신합니다.


### DELETE /api/modules/sirsoft-board/boards/{slug}/attachments/{id}
<!-- @generated:start:api.modules.sirsoft-board.boards.attachments.destroy -->
- **라우트명**: `api.modules.sirsoft-board.boards.attachments.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\AttachmentController@destroy`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.attachments.upload`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/boards/{slug}/attachments/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만). 컨트롤러가 `success('...attachment.delete_success')` 를 데이터 없이 호출합니다._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "파일이 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.attachments.upload`)이 없거나, 해당 첨부의 삭제 권한(소유자/관리자)이 없는 경우 (`파일 삭제 권한이 없습니다.`) |
| 404 | Not Found | 게시판 또는 첨부파일이 없는 경우 (`파일을 찾을 수 없습니다.`) |
| 500 | Internal Server Error | 삭제 처리 중 예외 (`파일 삭제에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 첨부파일을 삭제합니다. `auth:sanctum` + `sirsoft-board.{slug}.attachments.upload` 권한이 필요하며, 서비스의 `canDelete` 로 현재 사용자가 해당 첨부의 소유자(작성자)인지 확인한 뒤 삭제합니다. 권한이 없으면 403을 반환합니다.


### POST /api/modules/sirsoft-board/boards/{slug}/comments/{commentId}/reports
<!-- @generated:start:api.modules.sirsoft-board.boards.comments.reports.store -->
- **라우트명**: `api.modules.sirsoft-board.boards.comments.reports.store`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\ReportController@storeCommentReport`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| commentId | path | string | 예 | — | 대상 comment의 식별자 |
| reason_type | body | string | 예 | — | 신고 사유 유형 (ReportReasonType Enum 값 중 하나 — 스팸/욕설 등) |
| reason_detail | body | string | 예 | min 1, max 1000 | 신고 사유 상세 설명 (1~1000자) |

**요청 예시**

```http
POST /api/modules/sirsoft-board/boards/{slug}/comments/{commentId}/reports HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "reason_type": "예시값",
    "reason_detail": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (접수된 신고 케이스 = `ReportResource`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 신고 케이스 기본 키 (boards_reports 1행 = 1케이스) |
| board_id | integer | `1` | 신고가 접수된 게시판 식별자 |
| board | object | `{"id":1,"name":"API 문서 샘플 게시판","slug":"apidoc-sample-board","title":null,"current_status":null,"deleted_at":null}` | 게시판 정보 (id/name/slug + 신고 대상 제목·현재 상태·삭제 일시) |
| target_type | string | `comment` | 신고 대상 유형 (`post` 게시글 / `comment` 댓글 — ReportType Enum 값) |
| target_type_label | string | `댓글` | 신고 대상 유형의 표시 라벨 |
| target_id | integer | `760` | 신고 대상(댓글) 식별자 |
| post_id | integer | `237` | 신고 대상 댓글이 속한 게시글 식별자 |
| content | null | `null` | 신고 대상 본문 (상세 조회 라우트에서만 채워지며, 접수 응답에서는 null) |
| content_mode | string | `text` | 신고 대상 본문 편집 모드 (html / text) |
| content_preview | string | `API 문서 샘플 댓글입니다.` | 신고 대상 본문 미리보기 (앞 100자 — 목록/접수 응답용) |
| author | object | `{"uuid":"a234c2b1-…","name":"API 문서 샘플 사용자","email":"apidoc-sample-user@example.com","is_guest":false}` | 신고 대상의 작성자 (비회원이면 `is_guest: true`, name 은 `비회원`) |
| reporter | object \| null | `{"uuid":"…","name":"신고자","email":"reporter@example.com","is_guest":false}` | 최초 신고자 (첫 번째 신고 로그 기준) |
| reason_type | string | `spam` | 대표 신고 사유 유형 (abuse, hate_speech, spam, copyright, privacy, misinformation, sexual, violence, other) |
| reason_type_label | string | `스팸/광고` | 대표 신고 사유의 표시 라벨 |
| status | string | `pending` | 신고 케이스 처리 상태 (ReportStatus Enum 값 — 접수 직후 `pending`) |
| status_label | string | `대기` | 처리 상태의 표시 라벨 |
| status_variant | string | `warning` | 처리 상태의 UI 배지 변형값 |
| processor | null | `null` | 처리 관리자 (uuid/name — 미처리 시 null) |
| processed_at | null | `null` | 처리 일시 (미처리 시 null) |
| metadata | null | `null` | 신고 메타(IP/User-Agent) — 상세 조회 라우트에서만 노출, 접수 응답에서는 null |
| report_count | null | `null` | 케이스 누적 신고 건수 (목록 그룹 조회에서만 채워짐) |
| last_reported_at | string | `2026-07-08 10:41:34` | 마지막 신고 접수 일시 (재접수 시 갱신) |
| is_reactivated | boolean | `false` | 반려/중단 후 재접수된 케이스인지 여부 |
| target_status | null | `null` | 신고 대상의 현재 상태 (목록 그룹 조회에서만 채워짐) |
| target_trigger_type | null | `null` | 신고 대상 상태 변경 주체 (목록 그룹 조회에서만 채워짐) |
| target_status_label | null | `null` | 신고 대상 상태 표시 라벨 (목록 그룹 조회에서만 채워짐) |
| created_at | string | `2026-07-08 10:41:34` | 신고 케이스 생성 일시 |
| updated_at | string | `2026-07-08 10:41:34` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 신고의 신고자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_view":false,"can_manage":false}` | 현재 사용자의 신고 조회/관리 권한 맵 (`sirsoft-board.reports.view` / `.manage`) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "신고가 접수되었습니다.",
    "data": {
        "id": 1,
        "board_id": 1,
        "board": {
            "id": 1,
            "name": "API 문서 샘플 게시판",
            "slug": "apidoc-sample-board",
            "title": null,
            "current_status": null,
            "deleted_at": null
        },
        "target_type": "comment",
        "target_type_label": "댓글",
        "target_id": 1,
        "post_id": 1,
        "content": null,
        "content_mode": "text",
        "content_preview": "API 문서 샘플 댓글입니다.",
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "is_guest": false
        },
        "reporter": null,
        "reason_type": null,
        "reason_type_label": null,
        "status": "pending",
        "status_label": "대기",
        "status_variant": "warning",
        "processor": null,
        "processed_at": null,
        "metadata": null,
        "report_count": null,
        "last_reported_at": "2026-07-08 10:41:34",
        "is_reactivated": false,
        "target_status": null,
        "target_trigger_type": null,
        "target_status_label": null,
        "created_at": "2026-07-08 10:41:34",
        "updated_at": "2026-07-08 10:41:34",
        "is_owner": true,
        "abilities": {
            "can_view": false,
            "can_manage": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 게시판의 신고 기능이 꺼진 경우(`이 게시판은 신고 기능이 비활성화되어 있습니다.`), 본인이 작성한 댓글 신고(`본인이 작성한 글은 신고할 수 없습니다.`), 블라인드/삭제된 대상 신고(`신고할 수 없는 대상입니다.`) |
| 404 | Not Found | 게시판 슬러그에 해당하는 게시판이 없는 경우 |
| 409 | Conflict | 동일 대상에 이미 신고한 이력이 있는 경우 (`이미 신고한 내역이 있습니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 신고 접수 중 예외 (`신고 접수에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 특정 댓글을 신고합니다. 신고는 회원 전용이라 컨트롤러가 `AuthBaseController` 를 상속해 로그인 사용자만 호출할 수 있으며, 게시판의 신고 기능이 꺼져 있으면 403을 반환합니다. 본인 댓글 신고, 블라인드/삭제된 대상 신고는 차단되고, 이미 신고한 대상이면 409(중복)로 응답합니다. 신고 사유 유형(`reason_type`)과 상세(`reason_detail`)를 받아 신고를 접수하며 사용자 활동 로그를 남깁니다.


### POST /api/modules/sirsoft-board/boards/{slug}/comments/{commentId}/verify-password
<!-- @generated:start:api.modules.sirsoft-board.boards.comments.verify-password -->
- **라우트명**: `api.modules.sirsoft-board.boards.comments.verify-password`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\CommentController@verifyPassword`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.comments.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| commentId | path | string | 예 | — | 대상 comment의 식별자 |

**요청 예시**

```http
POST /api/modules/sirsoft-board/boards/{slug}/comments/{commentId}/verify-password HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| verified | boolean | `true` | 비밀번호 검증 성공 여부 (성공 응답에서는 항상 true) |
| comment_id | integer | `760` | 검증 대상 댓글 식별자 |
| verification_token | string | `G7xk3mQ8pRt1vZ...` (32자 랜덤 문자열) | 이후 수정/삭제 요청에서 비밀번호 대신 사용하는 임시 검증 토큰 |
| expires_at | string | `2026-07-08T11:41:34+09:00` | 검증 토큰 만료 시각 (ISO 8601 — 발급 후 1시간) |

> 참고: 이 엔드포인트는 요청 본문에 `password`(문자열, 필수)를 함께 받습니다 (컨트롤러의 인라인 검증).

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "비밀번호가 확인되었습니다.",
    "data": {
        "verified": true,
        "comment_id": 1,
        "verification_token": "G7xk3mQ8pRt1vZaB4cD6eF8gH0iJ2kL4",
        "expires_at": "2026-07-08T11:41:34+09:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 400 | Bad Request | 대상이 비회원 댓글이 아닌 경우 (회원 댓글은 비밀번호 검증 대상 아님) |
| 401 | Unauthorized | 비밀번호가 일치하지 않는 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.comments.write`)이 없는 경우 |
| 404 | Not Found | 댓글이 없는 경우 (`댓글을 찾을 수 없습니다.`) |
| 422 | Unprocessable Entity | `password` 누락 등 검증 실패 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 검증 처리 중 예외 |

<!-- @generated:end -->

**설명** 비회원이 작성한 댓글의 수정/삭제 권한을 확인하기 위해 비밀번호를 검증합니다. `auth:sanctum` + `sirsoft-board.{slug}.comments.write` 권한이 필요하며, 대상이 비회원 댓글이 아니면 400, 비밀번호가 틀리면 401을 반환합니다. 검증 성공 시 1시간 유효한 임시 토큰(`verification_token`)을 발급해 이후 수정/삭제 요청에서 비밀번호 재입력 없이 사용하도록 합니다.


### GET /api/modules/sirsoft-board/boards/{slug}/posts
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.index -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostController@index`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.posts.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/{slug}/posts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `237` | 기본 키 (내부 식별자) |
| category | null | `null` | 게시글 분류(카테고리) 이름 (분류 미사용 게시판이거나 미지정 시 null) |
| author | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| is_notice | boolean | `false` | notice 여부 |
| is_secret | boolean | `false` | secret 여부 |
| content_mode | string | `html` | 본문 편집 모드 (html: WYSIWYG/HTML, text: 일반 텍스트) |
| is_new | boolean | `true` | new 여부 |
| status | string | `published` | 게시 상태 (published 게시됨 / blinded 블라인드 처리 / deleted 삭제됨 — PostStatus Enum 값) |
| status_label | string | `게시됨` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| view_count | integer | `43` | view 개수 (집계) |
| comment_count | integer | `0` | comment 개수 (집계) |
| reply_count | integer | `0` | reply 개수 (집계) |
| attachment_count | integer | `0` | attachment 개수 (집계) |
| has_attachment | boolean | `false` | attachment 여부 |
| thumbnail | string | `/api/modules/sirsoft-board/boards/api…` | 썸네일 이미지 URL/경로 |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| is_reply | boolean | `false` | reply 여부 |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| is_author | boolean | `true` | author 여부 |
| is_guest_post | boolean | `false` | guest post 여부 |
| slug | string | `apidoc-sample-board` | 게시판 슬러그 (URL/테이블명) |
| title | string | `API 문서 샘플 게시글` | 제목 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| content_preview | string | `API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.` | 목록용 본문 요약 (평문 앞 150자, 블라인드/비밀글은 원문 유출 방지를 위해 빈 문자열) |
| row_type | string | `normal` | 목록 행 유형 (notice 공지 / reply 답변글 / normal 일반글 — 표시 스타일·순번 처리 구분) |
| number | integer | `1` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| show_category | boolean | `false` | 게시판에 분류가 설정돼 있어 목록에 분류 컬럼을 노출할지 여부 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "category": null,
                "author": {
                    "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                    "name": "API 문서 샘플 사용자",
                    "email": "apidoc-sample-user@example.com",
                    "avatar": null,
                    "status": "active",
                    "status_label": "활성",
                    "is_guest": false
                },
                "is_notice": false,
                "is_secret": "{MASKED}",
                "content_mode": "html",
                "is_new": true,
                "status": "published",
                "status_label": "게시됨",
                "view_count": 44,
                "comment_count": 0,
                "reply_count": 0,
                "attachment_count": 0,
                "has_attachment": false,
                "thumbnail": "/api/modules/sirsoft-board/boards/apidoc-sample-board/attachment/apidocsmpl1/preview",
                "parent_id": null,
                "depth": 0,
                "is_reply": false,
                "created_at": "2026-07-08 10:41:34",
                "created_at_formatted": "4시간 전",
                "is_author": true,
                "is_guest_post": false,
                "slug": "apidoc-sample-board",
                "title": "API 문서 샘플 게시글",
                "deleted_at": null,
                "content_preview": "API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.",
                "row_type": "normal",
                "number": 1,
                "show_category": false
            }
        ],
        "pagination": {
            "total": 1,
            "all_total": 1,
            "count": 1,
            "per_page": 25,
            "current_page": 1,
            "last_page": 1,
            "from": 1,
            "to": 1,
            "has_more_pages": false
        },
        "board": {
            "slug": "apidoc-sample-board",
            "name": "API 문서 샘플 게시판",
            "description": "",
            "type": "basic",
            "categories": [],
            "show_category": false,
            "settings": {
                "use_file_upload": true,
                "use_comment": true,
                "use_reply": true,
                "use_report": true,
                "secret_mode": "{MASKED}",
                "show_view_count": true,
                "per_page": 20,
                "posts_per_page": 20,
                "posts_per_page_mobile": 15,
                "new_display_hours": 24,
                "order_by": "created_at",
                "order_direction": "DESC"
            }
        },
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_read_secret": "{MASKED}",
            "can_read_comments": true,
            "can_write_comments": true,
            "can_upload": true,
            "can_download": true,
            "can_manage": true,
            "can_view_deleted": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.posts.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시판의 게시글 목록을 조회합니다. `auth:sanctum` + `sirsoft-board.{slug}.posts.read` 권한이 필요하며(공개 게시판은 게스트에게도 read 권한이 부여될 수 있음), 검색/카테고리/정렬 등 필터와 페이지네이션을 지원합니다. 성능을 위해 simplePaginate 로 조회하되 일반 게시글 총 건수는 캐시에서 별도로 채우며, manager 권한 보유자가 `del=1` 을 주면 삭제된 게시글까지 포함합니다.


### POST /api/modules/sirsoft-board/boards/{slug}/posts
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.store -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.store`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostController@store`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.posts.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| is_secret | body | boolean | 아니오 | — | 비밀글 여부. 문자열 `"true"`/`"false"`/`"1"`/`"0"` 도 수용해 boolean 으로 정규화한다 (해석 불가값은 422) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.user_post.store_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-board/boards/{slug}/posts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (생성된 게시글 = `PostResource`, 상세 조회(`GET /boards/{slug}/posts/{id}`)와 동일 스키마)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `237` | 생성된 게시글 기본 키 |
| category | null | `null` | 게시글 분류(카테고리) 이름 (미지정 시 null) |
| author | object | `{"uuid":"a234c2b1-…","name":"API 문서 샘플 사용자","email":"…","avatar":null,"status":"active","status_label":"활성","is_guest":false}` | 작성자 정보 (비회원이면 `is_guest: true`) |
| is_notice | boolean | `false` | 공지 게시글 여부 |
| is_secret | boolean | `false` | 비밀글 여부 (게시판 `secret_mode` 가 always 이면 강제 true) |
| content_mode | string | `html` | 본문 편집 모드 (html: WYSIWYG/HTML, text: 일반 텍스트) |
| is_new | boolean | `true` | 신규(NEW) 표시 대상 여부 (게시판 `new_display_hours` 기준) |
| status | string | `published` | 게시 상태 (published / blinded / deleted — PostStatus Enum) |
| status_label | string | `게시됨` | 게시 상태 표시 라벨 |
| view_count | integer | `0` | 조회수 (생성 직후 0) |
| comment_count | integer | `0` | 댓글 수 (집계) |
| reply_count | integer | `0` | 답변글 수 (집계) |
| attachment_count | integer | `0` | 첨부 수 (집계) |
| has_attachment | boolean | `false` | 첨부 보유 여부 |
| thumbnail | null | `null` | 썸네일 이미지 URL (첫 이미지 첨부 — 없으면 null) |
| parent_id | null | `null` | 답변글의 원글 식별자 (원글이면 null) |
| depth | integer | `0` | 계층 트리 깊이 (0 = 원글) |
| is_reply | boolean | `false` | 답변글 여부 |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `방금 전` | `created_at` 의 표시용 포맷 문자열 |
| is_author | boolean | `true` | 현재 사용자가 작성자인지 여부 |
| is_guest_post | boolean | `false` | 비회원 작성 게시글 여부 |
| title | string | `API 문서 샘플 게시글` | 제목 |
| content | string | `<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>` | 본문 내용 |
| user_id | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | 작성자 UUID (비회원이면 null) |
| trigger_type | string \| null | `null` | 상태 변경/삭제를 유발한 주체 (user/admin/report/system/auto_hide/cascade — TriggerType Enum, 생성 직후 null) |
| updated_at | string | `2026-07-08 10:41:34` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| ip_address | string \| null | `null` | 작성 IP (admin.manage 권한 보유자에게만 노출, 그 외 null) |
| action_logs | array \| null | `null` | 블라인드/복원/삭제 등 운영 처리 이력 (admin.manage 권한 보유자만) |
| board | object | `{"id":1,"slug":"apidoc-sample-board","name":"API 문서 샘플 게시판","type":"basic", …}` | 게시글이 속한 게시판 정보 + 댓글/답변/신고/조회수 설정 및 신고 유형 목록 |
| navigation | null | `null` | 이전/다음 글 정보 (별도 navigation 엔드포인트로 비동기 로딩) |
| parent | null | `null` | 답변글의 원글 객체 (원글이면 null) |
| comments | null | `null` | 댓글 목록 (생성 응답에서는 관계 미로드 → null) |
| attachments | null | `null` | 첨부파일 목록 (생성 응답에서는 관계 미로드 → null) |
| replies | null | `null` | 답변글 목록 (관계 미로드 시 null) |
| is_already_reported | boolean | `false` | 현재 사용자가 이 게시글을 이미 신고했는지 여부 |
| is_owner | boolean | `true` | 현재 인증 사용자가 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_write":true,"can_read_secret":false,"can_read_comments":true,"can_write_comments":true,"can_upload":true,"can_download":true,"can_manage":false,"can_access_admin":false}` | 현재 사용자의 게시판별 세부 권한 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "게시글이 등록되었습니다.",
    "data": {
        "id": 1,
        "category": null,
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_notice": false,
        "is_secret": false,
        "content_mode": "html",
        "is_new": true,
        "status": "published",
        "status_label": "게시됨",
        "view_count": 0,
        "comment_count": 0,
        "reply_count": 0,
        "attachment_count": 0,
        "has_attachment": false,
        "thumbnail": null,
        "parent_id": null,
        "depth": 0,
        "is_reply": false,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "방금 전",
        "is_author": true,
        "is_guest_post": false,
        "title": "API 문서 샘플 게시글",
        "content": "<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>",
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "trigger_type": null,
        "updated_at": "2026-07-08 10:41:34",
        "deleted_at": null,
        "ip_address": null,
        "action_logs": null,
        "board": {
            "id": 1,
            "slug": "apidoc-sample-board",
            "name": "API 문서 샘플 게시판",
            "type": "basic",
            "use_comment": true,
            "use_reply": true,
            "use_report": true,
            "show_view_count": true,
            "max_reply_depth": 5,
            "max_comment_depth": 10,
            "report_types": [
                { "value": "abuse", "label": "욕설/비방" },
                { "value": "spam", "label": "스팸/광고" }
            ]
        },
        "navigation": null,
        "parent": null,
        "comments": null,
        "attachments": null,
        "replies": null,
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_read_secret": false,
            "can_read_comments": true,
            "can_write_comments": true,
            "can_upload": true,
            "can_download": true,
            "can_manage": false,
            "can_access_admin": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.posts.write`)이 없는 경우, 게시판이 파일 업로드를 허용하지 않는데 파일을 첨부한 경우(`이 게시판은 파일 업로드가 허용되지 않습니다.`), 첨부 업로드 권한이 없는 경우, 비밀글 기능이 꺼진 게시판에 `is_secret=true` 로 요청한 경우 |
| 404 | Not Found | 게시판 슬러그에 해당하는 활성 게시판이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지 — 제목/본문 길이, 금지어, 작성 쿨다운 등) |
| 500 | Internal Server Error | 게시글 저장 중 예외 (`게시글 등록에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시글을 작성합니다. `auth:sanctum` + `sirsoft-board.{slug}.posts.write` 권한이 필요하며, `StorePostRequest` 로 검증된 제목/본문/카테고리 등을 받아 현재 로그인 사용자를 작성자로 저장합니다. 파일이 첨부되면 게시판의 업로드 허용 여부와 첨부 업로드 권한을 함께 확인하고, 게시판 `secret_mode` 에 따라 비밀글 설정을 강제(always)하거나 거부(disabled)합니다. 스팸 방지 쿨다운이 설정되어 있으면 작성 성공 후 쿨다운을 기록합니다.


### GET /api/modules/sirsoft-board/boards/{slug}/posts/form-data
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.form-data -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.form-data`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostController@getFormData`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.posts.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/{slug}/posts/form-data HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| title | string | `` | 제목 |
| content | string | `` | 본문 내용 |
| content_mode | string | `text` | 본문 편집 모드 (html: WYSIWYG/HTML, text: 일반 텍스트) |
| category | null | `null` | 게시글 분류(카테고리) 이름 (분류 미사용 게시판이거나 미지정 시 null) |
| is_notice | boolean | `false` | notice 여부 |
| is_secret | boolean | `false` | secret 여부 |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글 폼 데이터를 조회했습니다.",
    "data": {
        "title": "",
        "content": "",
        "content_mode": "text",
        "category": null,
        "is_notice": false,
        "is_secret": "{MASKED}",
        "parent_id": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.posts.write`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시글 작성/수정 폼의 초기 입력값을 반환합니다. `auth:sanctum` + `sirsoft-board.{slug}.posts.write` 권한이 필요하며, 쿼리 파라미터로 모드가 분기됩니다: `post_id` 가 있으면 기존 글 값(수정 모드), `parent_id` 가 있으면 원글 제목을 기반으로 한 답변글 초기값(답변 모드), 둘 다 없으면 빈 기본값(생성 모드)입니다. 수정/답변 모드에서는 본인·관리자 여부와 비회원 글의 비밀번호/토큰 검증을 확인하며, 답변 대상이 블라인드/삭제 상태면 진입을 차단합니다.


### GET /api/modules/sirsoft-board/boards/{slug}/posts/form-meta
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.form-meta -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.form-meta`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostController@getFormMeta`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.posts.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/{slug}/posts/form-meta HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| board | object | `{"id":12,"name":"API 문서 샘플 게시판","slug":"apidoc-sample-boa…` | 글쓰기/수정 폼 렌더링용 게시판 메타 (id/name/slug/type + 게시판 설정과 현재 사용자 권한(user_abilities) 등) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글 폼 메타 데이터를 조회했습니다.",
    "data": {
        "board": {
            "id": 1,
            "name": "API 문서 샘플 게시판",
            "slug": "apidoc-sample-board",
            "is_active": true,
            "type": "basic",
            "description": "",
            "per_page": 20,
            "per_page_mobile": 15,
            "order_by": "created_at",
            "order_direction": "DESC",
            "categories": [],
            "show_view_count": true,
            "secret_mode": "{MASKED}",
            "use_comment": true,
            "use_reply": true,
            "max_reply_depth": 5,
            "use_report": true,
            "comment_order": "ASC",
            "max_comment_depth": 10,
            "min_title_length": 2,
            "max_title_length": 200,
            "min_content_length": 10,
            "max_content_length": 10000,
            "min_comment_length": 2,
            "max_comment_length": 1000,
            "blocked_keywords": [],
            "use_file_upload": true,
            "max_file_size": 10,
            "max_file_count": 5,
            "allowed_extensions": [
                "jpg",
                "jpeg",
                "png",
                "gif",
                "pdf",
                "zip"
            ],
            "add_to_menu": null,
            "new_display_hours": 24,
            "board_managers": [],
            "board_steps": [],
            "board_manager_ids": [],
            "board_step_ids": [],
            "notify_author": true,
            "notify_admin_on_post": true,
            "created_at": "2026-07-08 10:41:34",
            "updated_at": "2026-07-08 10:41:34",
            "permissions": null,
            "category_post_counts": null,
            "posts_count": 0,
            "user_abilities": {
                "can_read": true,
                "can_write": true,
                "can_read_secret": "{MASKED}",
                "can_read_comments": true,
                "can_write_comments": true,
                "can_upload": true,
                "can_download": true,
                "can_manage": true
            },
            "abilities": {
                "can_create": true,
                "can_update": true,
                "can_delete": true
            }
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.posts.write`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시글 작성/수정 폼 렌더링에 필요한 게시판 메타 정보를 반환합니다. `auth:sanctum` + `sirsoft-board.{slug}.posts.write` 권한이 필요하며, 게시판 설정과 사용자 권한(`user_abilities`)을 담습니다. `post_id` 가 있으면 수정 모드로 작성자/작성일/첨부 목록을 포함하되 회원 글은 본인 또는 관리자만, 비회원 글은 비밀번호/검증 토큰 확인을 요구합니다. `parent_id` 가 있으면 답변 모드로 원글 정보를 포함하며, 답변 기능이 꺼져 있거나 원글이 블라인드/삭제 상태면 차단합니다.


### DELETE /api/modules/sirsoft-board/boards/{slug}/posts/{id}
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.destroy -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostController@destroy`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.posts.write|sirsoft-board.{slug}.manager`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| password | body | string | 아니오 | min 4 | 비회원 게시글 삭제 시 소유권 확인용 비밀번호. 형식(문자열, 4자 이상)은 `DestroyPostRequest` 가 검증하며, 배열 주입(`password[]=x`) 등 형식 위반은 422. 소유권 판정 자체는 컨트롤러(`canModifyPost`)가 수행 |
| verification_token | body | string | 아니오 | max 255 | 비회원 게시글 삭제 시 소유권 확인용 검증 토큰 (비밀번호 검증 후 발급). 문자열 형식 위반 시 422 |
| force_delete | body | boolean | 아니오 | — | 불리언 플래그 (형식 검증만 수행 — 불리언으로 해석 불가한 값은 422) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/boards/{slug}/posts/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만). 컨트롤러가 `success('...posts.delete_success')` 를 데이터 없이 호출하며, 삭제는 소프트 삭제입니다._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글이 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.posts.write\|sirsoft-board.{slug}.manager`)이 없거나, 작성자 본인·게시판 관리자·비회원 글 검증(토큰/비밀번호) 중 어느 조건도 만족하지 않는 경우 (`게시글 삭제 권한이 없습니다.`) |
| 404 | Not Found | 활성 게시판 또는 게시글이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터(`password`/`verification_token`/`force_delete`)가 형식 검증을 위반한 경우 (`error.errors` 에 필드별 메시지) / 게시판의 답글 삭제 정책(`reply_delete_policy`)이 `block` 이고 살아 있는 답글이 있는 경우 (`답글이 달린 글은 삭제할 수 없습니다. 답글을 먼저 삭제해 주세요.` — `sirsoft-board::validation.post.delete.has_replies`) |
| 500 | Internal Server Error | 삭제 처리 중 예외 (`게시글 삭제에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시글을 삭제(소프트 삭제)합니다. `auth:sanctum` + `sirsoft-board.{slug}.posts.write` 또는 게시판 manager 권한이 필요합니다. 작성자 본인, 게시판 관리자(admin.manage/manager), 또는 비회원 글의 경우 검증 토큰·비밀번호 확인 중 하나를 만족해야 삭제할 수 있으며, 조건 미충족 시 403을 반환합니다. 삭제 동작은 게시판의 답글 삭제 정책(`reply_delete_policy`)을 따릅니다 — `cascade`(기본)에서는 원글 삭제 시 살아 있는 답글 트리(및 그 답글들의 댓글·첨부)가 함께 소프트 삭제되고 이후 원글을 복원하면 연쇄 삭제분이 함께 복원되며, `block` 에서는 살아 있는 답글이 있으면 어떤 부수효과도 없이 422 로 삭제가 차단됩니다.


### GET /api/modules/sirsoft-board/boards/{slug}/posts/{id}
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.show -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.show`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostController@show`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.posts.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/{slug}/posts/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `237` | 기본 키 (내부 식별자) |
| category | null | `null` | 게시글 분류(카테고리) 이름 (분류 미사용 게시판이거나 미지정 시 null) |
| author | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| is_notice | boolean | `false` | notice 여부 |
| is_secret | boolean | `false` | secret 여부 |
| content_mode | string | `html` | 본문 편집 모드 (html: WYSIWYG/HTML, text: 일반 텍스트) |
| is_new | boolean | `true` | new 여부 |
| status | string | `published` | 게시 상태 (published 게시됨 / blinded 블라인드 처리 / deleted 삭제됨 — PostStatus Enum 값) |
| status_label | string | `게시됨` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| view_count | integer | `43` | view 개수 (집계) |
| comment_count | integer | `0` | comment 개수 (집계) |
| reply_count | integer | `0` | reply 개수 (집계) |
| attachment_count | integer | `0` | attachment 개수 (집계) |
| has_attachment | boolean | `false` | attachment 여부 |
| thumbnail | string | `/api/modules/sirsoft-board/boards/api…` | 썸네일 이미지 URL/경로 |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| is_reply | boolean | `false` | reply 여부 |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| is_author | boolean | `true` | author 여부 |
| is_guest_post | boolean | `false` | guest post 여부 |
| title | string | `API 문서 샘플 게시글` | 제목 |
| content | string | `<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>` | 본문 내용 |
| user_id | string | `a231747f-e82e-4cf2-9ae1-a261849dce40` | user 식별자 (연관 리소스 참조) |
| trigger_type | string | `user` | 상태 변경/삭제를 유발한 주체 (user 사용자 / admin 관리자 / report 신고 / system 시스템 / auto_hide 자동 블라인드 / cascade 연쇄 삭제 — TriggerType Enum) |
| updated_at | string | `2026-07-07 09:39:03` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| ip_address | string | `127.0.0.1` | 요청/행위가 발생한 IP 주소 |
| action_logs | array | `[]` | 블라인드/복원/삭제 등 운영 처리 이력 (admin.manage 권한 보유자에게만 노출, action/reason/admin_name/created_at — 비권한자는 빈 배열/null) |
| board | object | `{"slug":"apidoc-sample-board","name":"API 문서 샘플 게시판","typ…` | 게시글이 속한 게시판 정보 (slug/name/type + 댓글·답변·신고·조회수 노출·깊이 등 렌더링에 필요한 설정과 신고 유형 목록) |
| navigation | null | `null` | 이전/다음 글 정보 (상세 API에서는 기본 null — 별도 navigation 엔드포인트로 비동기 로딩) |
| parent | null | `null` | 상위 항목 객체 (parent 관계 파생) |
| comments | array | `[{"id":760,"post_id":237,"parent_id":null,"content":"API …` | 이 게시글의 댓글 목록 (CommentResource 배열 — 대댓글 계층 포함) |
| attachments | array | `[{"id":155,"hash":"apidocsmpl1","original_filename":"apid…` | 이 게시글의 첨부파일 목록 (비밀글/삭제글은 권한에 따라 빈 배열 또는 cascade 항목만 노출) |
| replies | array | `[]` | 이 게시글에 달린 답변글 목록 (PostResource 배열 — use_reply 게시판에서만 로드) |
| is_already_reported | boolean | `false` | already reported 여부 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_write":true,"can_read_secret":true,…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글 목록을 조회했습니다.",
    "data": {
        "id": 1,
        "category": null,
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_notice": false,
        "is_secret": "{MASKED}",
        "content_mode": "html",
        "is_new": true,
        "status": "published",
        "status_label": "게시됨",
        "view_count": 44,
        "comment_count": 0,
        "reply_count": 0,
        "attachment_count": 0,
        "has_attachment": false,
        "thumbnail": "/api/modules/sirsoft-board/boards/apidoc-sample-board/attachment/apidocsmpl1/preview",
        "parent_id": null,
        "depth": 0,
        "is_reply": false,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "4시간 전",
        "is_author": true,
        "is_guest_post": false,
        "title": "API 문서 샘플 게시글",
        "content": "<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>",
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "trigger_type": "user",
        "updated_at": "2026-07-08 15:01:44",
        "deleted_at": null,
        "ip_address": "127.0.0.1",
        "action_logs": [],
        "board": {
            "slug": "apidoc-sample-board",
            "name": "API 문서 샘플 게시판",
            "type": "basic",
            "use_comment": true,
            "use_reply": true,
            "use_report": true,
            "show_view_count": true,
            "max_reply_depth": 5,
            "max_comment_depth": 10,
            "report_types": [
                {
                    "value": "abuse",
                    "label": "욕설/비방"
                },
                {
                    "value": "hate_speech",
                    "label": "혐오 발언"
                },
                {
                    "value": "spam",
                    "label": "스팸/광고"
                },
                {
                    "value": "copyright",
                    "label": "저작권 침해"
                },
                {
                    "value": "privacy",
                    "label": "개인정보 노출"
                },
                {
                    "value": "misinformation",
                    "label": "허위정보"
                },
                {
                    "value": "sexual",
                    "label": "성적인 콘텐츠"
                },
                {
                    "value": "violence",
                    "label": "폭력적인 콘텐츠"
                },
                {
                    "value": "other",
                    "label": "기타"
                }
            ]
        },
        "navigation": null,
        "parent": null,
        "comments": [
            {
                "id": 1,
                "post_id": 1,
                "parent_id": null,
                "content": "API 문서 샘플 댓글입니다.",
                "author": {
                    "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                    "name": "API 문서 샘플 사용자",
                    "email": "apidoc-sample-user@example.com",
                    "avatar": null,
                    "status": "active",
                    "status_label": "활성",
                    "is_guest": false
                },
                "is_secret": "{MASKED}",
                "status": "published",
                "status_label": "게시됨",
                "depth": 0,
                "replies_count": 0,
                "created_at": "2026-07-08 10:41:34",
                "created_at_formatted": "4시간 전",
                "updated_at": "2026-07-08 10:41:34",
                "deleted_at": null,
                "is_cascade_deleted": false,
                "ip_address": null,
                "action_logs": [],
                "is_author": true,
                "is_guest_comment": false,
                "is_already_reported": false,
                "is_owner": true,
                "abilities": {
                    "can_write": true
                }
            }
        ],
        "attachments": [
            {
                "id": 1,
                "hash": "apidocsmpl1",
                "original_filename": "apidoc-sample.png",
                "mime_type": "image/png",
                "size": 2048,
                "size_formatted": "2 KB",
                "collection": "default",
                "order": 0,
                "download_url": "/api/modules/sirsoft-board/boards/apidoc-sample-board/attachment/apidocsmpl1",
                "preview_url": "/api/modules/sirsoft-board/boards/apidoc-sample-board/attachment/apidocsmpl1/preview",
                "is_image": true,
                "meta": null
            }
        ],
        "replies": [],
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_read_secret": "{MASKED}",
            "can_read_comments": true,
            "can_write_comments": true,
            "can_upload": true,
            "can_download": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.posts.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시글 상세를 조회합니다. `auth:sanctum` + `sirsoft-board.{slug}.posts.read` 권한이 필요하며, 댓글/첨부/답글을 함께 로드하고 조회수를 (중복 방지 캐시로) 1회 증가시킵니다. 삭제된 게시글은 manager 권한이 있어야 열람 가능하고, 비밀글의 본문 노출 여부와 신고 이력 표시는 로그인 사용자 기준으로 결정됩니다(비로그인은 신고 불가로 모두 false). manager 가 `del_cmt=1` 을 주면 삭제된 댓글도 포함합니다.


### PUT /api/modules/sirsoft-board/boards/{slug}/posts/{id}
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.update -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.update`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostController@update`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.posts.write|sirsoft-board.{slug}.manager`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| attachment_ids | body | integer[] | 아니오 | — | 미리 업로드해 둔 첨부 ID 목록. 게시글에 연결된다 (관리자 수정 경로와 동일) |
| temp_key | body | string | 아니오 | 최대 64자 | 임시 업로드 묶음 키. 해당 묶음의 첨부가 게시글로 이동·연결된다 |
| is_secret | body | boolean | 아니오 | — | 비밀글 여부. 문자열 `"true"`/`"false"`/`"1"`/`"0"` 도 수용해 boolean 으로 정규화한다 (해석 불가값은 422) |
| password | body | string | 아니오 | min 4 | 비회원 게시글 수정 시 소유권 확인용 비밀번호. 형식(문자열, 4자 이상)만 여기서 검증하며 배열 주입 등 형식 위반은 422. 소유권 판정은 컨트롤러(`canModifyPost`)가 수행 |
| verification_token | body | string | 아니오 | max 255 | 비회원 게시글 수정 시 소유권 확인용 검증 토큰 (비밀번호 검증 후 발급). 문자열 형식 위반 시 422 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.user_post.update_validation_rules`).

`attachment_ids` 는 **연결 대상**이며, 목록에서 뺀 기존 첨부를 삭제하지는 않습니다. 첨부 개수 상한은 이미 연결된 첨부까지 합산해 판정하므로 상한을 넘기면 `422`(`errors.code = attachment_limit_exceeded`)로 거부됩니다.

**요청 예시**

```http
PUT /api/modules/sirsoft-board/boards/{slug}/posts/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| category | null | `null` | 게시글이 속한 분류(카테고리) 이름 (분류 미지정 시 null) |
| author | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"AP…` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| is_notice | boolean | `false` | notice 여부 |
| is_secret | boolean | `false` | secret 여부 |
| content_mode | string | `html` | 본문 편집 모드 (html: HTML 에디터 / text: 일반 텍스트 — 미지정 시 text) |
| is_new | boolean | `true` | new 여부 |
| status | string | `published` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `게시됨` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| view_count | integer | `44` | view 개수 (집계) |
| comment_count | integer | `0` | comment 개수 (집계) |
| reply_count | integer | `0` | reply 개수 (집계) |
| attachment_count | integer | `0` | attachment 개수 (집계) |
| has_attachment | boolean | `false` | attachment 여부 |
| thumbnail | null | `null` | 썸네일 이미지 URL/경로 |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| is_reply | boolean | `false` | reply 여부 |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| is_author | boolean | `true` | author 여부 |
| is_guest_post | boolean | `false` | guest post 여부 |
| title | string | `API 문서 샘플 게시글` | 제목 |
| content | string | `<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>` | 본문 내용 |
| user_id | string | `a234c2b1-cde8-437f-b28b-23323be2b98d` | user 식별자 (연관 리소스 참조) |
| trigger_type | string | `user` | 동작을 유발한 방식/주체 구분 값 |
| updated_at | string | `2026-07-08 15:01:44` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| ip_address | string | `127.0.0.1` | 요청/행위가 발생한 IP 주소 |
| action_logs | array | `[]` | 게시글 블라인드/복원/삭제 등 운영 처리 이력 (action/reason/admin_name/created_at — admin.manage 권한 보유자에게만 노출, 비권한자는 null) |
| board | object | `{"slug":"apidoc-sample-board","name":"API 문서 샘플 게시판","typ…` | 소속 게시판 요약 정보 (slug/name/type/댓글·답변·신고 사용 여부·최대 깊이·신고 유형 목록 등 — board 관계 로드 시에만 채워지며 미로드 시 null) |
| navigation | null | `null` | 이전/다음 글 이동 정보 (별도 navigation 조회로 채워지며, 이 응답에는 기본적으로 미포함되어 null) |
| parent | null | `null` | 상위 항목 객체 (parent 관계 파생) |
| comments | null | `null` | 게시글 댓글 목록 (comments 관계 로드 시 CommentResource 컬렉션으로 채워지며, 미로드 시 null) |
| attachments | null | `null` | 게시글 첨부파일 목록 (attachments 관계 로드 시 권한·비밀글·삭제 정책에 따라 필터링된 AttachmentResource 컬렉션, 미로드 시 null) |
| replies | null | `null` | 이 게시글에 달린 답변글 목록 (replies 관계 로드 시 동일 PostResource 컬렉션으로 채워지며, 미로드 시 null) |
| is_already_reported | boolean | `false` | already reported 여부 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_write":true,"can_read_secret":true,…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글이 수정되었습니다.",
    "data": {
        "id": 1,
        "category": null,
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_notice": false,
        "is_secret": "{MASKED}",
        "content_mode": "html",
        "is_new": true,
        "status": "published",
        "status_label": "게시됨",
        "view_count": 44,
        "comment_count": 0,
        "reply_count": 0,
        "attachment_count": 0,
        "has_attachment": false,
        "thumbnail": null,
        "parent_id": null,
        "depth": 0,
        "is_reply": false,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "4시간 전",
        "is_author": true,
        "is_guest_post": false,
        "title": "API 문서 샘플 게시글",
        "content": "<p>API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.</p>",
        "user_id": "a234c2b1-cde8-437f-b28b-23323be2b98d",
        "trigger_type": "user",
        "updated_at": "2026-07-08 15:01:44",
        "deleted_at": null,
        "ip_address": "127.0.0.1",
        "action_logs": [],
        "board": {
            "slug": "apidoc-sample-board",
            "name": "API 문서 샘플 게시판",
            "type": "basic",
            "use_comment": true,
            "use_reply": true,
            "use_report": true,
            "show_view_count": true,
            "max_reply_depth": 5,
            "max_comment_depth": 10,
            "report_types": [
                {
                    "value": "abuse",
                    "label": "욕설/비방"
                },
                {
                    "value": "hate_speech",
                    "label": "혐오 발언"
                },
                {
                    "value": "spam",
                    "label": "스팸/광고"
                },
                {
                    "value": "copyright",
                    "label": "저작권 침해"
                },
                {
                    "value": "privacy",
                    "label": "개인정보 노출"
                },
                {
                    "value": "misinformation",
                    "label": "허위정보"
                },
                {
                    "value": "sexual",
                    "label": "성적인 콘텐츠"
                },
                {
                    "value": "violence",
                    "label": "폭력적인 콘텐츠"
                },
                {
                    "value": "other",
                    "label": "기타"
                }
            ]
        },
        "navigation": null,
        "parent": null,
        "comments": null,
        "attachments": null,
        "replies": null,
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_read_secret": "{MASKED}",
            "can_read_comments": true,
            "can_write_comments": true,
            "can_upload": true,
            "can_download": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.posts.write\|sirsoft-board.{slug}.manager`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 게시글을 수정합니다. `auth:sanctum` + `sirsoft-board.{slug}.posts.write` 또는 게시판 manager 권한이 필요하며, `UpdatePostRequest` 로 검증된 값으로 갱신합니다. 작성자 본인, 게시판 관리자, 또는 비회원 글의 검증 토큰·비밀번호 확인 중 하나를 만족해야 수정할 수 있고, 조건 미충족 시 403을 반환합니다.


### GET /api/modules/sirsoft-board/boards/{slug}/posts/{id}/navigation
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.navigation -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.navigation`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostController@navigation`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.posts.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/{slug}/posts/{id}/navigation HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| prev | null | `null` | 정렬 기준상 이전 글 정보 (없거나 공지·답글·조회 실패 시 null) |
| next | null | `null` | 정렬 기준상 다음 글 정보 (없거나 공지·답글·조회 실패 시 null) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글 목록을 조회했습니다.",
    "data": {
        "prev": null,
        "next": null
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.posts.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시글 상세 화면의 이전/다음 글 정보를 조회합니다(상세 API에서 분리한 비동기 로딩용). `auth:sanctum` + `sirsoft-board.{slug}.posts.read` 권한이 필요하며, 게시판 정렬 설정과 현재 글의 카테고리를 기준으로 인접 글을 계산합니다. 공지글·답글·존재하지 않는 글이거나 내부 조회 실패 시에도 500 대신 `{prev: null, next: null}` 로 안전하게 응답하며, manager 가 `del=1` 을 주면 삭제된 글까지 후보에 포함합니다.


### POST /api/modules/sirsoft-board/boards/{slug}/posts/{id}/verify-password
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.verify-password -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.verify-password`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostController@verifyPassword`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.posts.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| password | body | string | 예 | — | 비밀번호 |

**요청 예시**

```http
POST /api/modules/sirsoft-board/boards/{slug}/posts/{id}/verify-password HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. 검증 성공 시 `password_verified` 플래그가 설정되어 게시글 상세(`PostResource`)를 그대로 반환합니다 — 즉 `GET /boards/{slug}/posts/{id}` 와 동일 스키마이며, 차이는 비밀글이라도 `content` 와 `attachments` 가 채워진다는 점입니다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `237` | 게시글 기본 키 |
| title | string | `API 문서 샘플 게시글` | 제목 |
| content | string | `<p>비밀글 본문입니다.</p>` | 본문 내용 (비밀번호 검증 성공으로 노출됨) |
| content_mode | string | `html` | 본문 편집 모드 (html / text) |
| category | null | `null` | 게시글 분류 |
| author | object | `{"uuid":null,"name":"비회원 작성자","email":null,"avatar":null,"status":null,"status_label":null,"is_guest":true}` | 작성자 정보 |
| is_secret | boolean | `true` | 비밀글 여부 |
| is_notice | boolean | `false` | 공지 여부 |
| status | string | `published` | 게시 상태 (PostStatus Enum 값) |
| status_label | string | `게시됨` | 게시 상태 표시 라벨 |
| view_count | integer | `43` | 조회수 |
| comment_count | integer | `0` | 댓글 수 (집계) |
| reply_count | integer | `0` | 답변글 수 (집계) |
| attachment_count | integer | `1` | 첨부 수 (집계) |
| has_attachment | boolean | `true` | 첨부 보유 여부 |
| attachments | array | `[{"id":155,"hash":"apidocsmpl1","original_filename":"apidoc.pdf", …}]` | 첨부파일 목록 (검증 성공으로 노출됨) |
| comments | array | `[]` | 댓글 목록 (관계 로드 시) |
| parent_id | null | `null` | 답변글의 원글 식별자 |
| depth | integer | `0` | 계층 트리 깊이 |
| is_reply | boolean | `false` | 답변글 여부 |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | 표시용 일시 포맷 |
| updated_at | string | `2026-07-08 10:41:34` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 |
| is_author | boolean | `false` | 현재 사용자가 작성자인지 여부 |
| is_guest_post | boolean | `true` | 비회원 작성 게시글 여부 |
| is_owner | boolean | `false` | 현재 인증 사용자가 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_write":true,"can_read_secret":false, …}` | 현재 사용자의 게시판별 세부 권한 맵 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "비밀번호가 확인되었습니다.",
    "data": {
        "id": 1,
        "title": "API 문서 샘플 비밀글",
        "content": "<p>비밀글 본문입니다.</p>",
        "content_mode": "html",
        "category": null,
        "author": {
            "uuid": null,
            "name": "비회원 작성자",
            "email": null,
            "avatar": null,
            "status": null,
            "status_label": null,
            "is_guest": true
        },
        "is_secret": true,
        "is_notice": false,
        "status": "published",
        "status_label": "게시됨",
        "view_count": 43,
        "comment_count": 0,
        "reply_count": 0,
        "attachment_count": 1,
        "has_attachment": true,
        "attachments": [
            {
                "id": 155,
                "hash": "apidocsmpl1",
                "original_filename": "apidoc.pdf",
                "mime_type": "application/pdf",
                "size": 102400,
                "size_formatted": "100 KB",
                "collection": "attachments",
                "order": 0,
                "download_url": "/api/modules/sirsoft-board/boards/apidoc-sample-board/attachment/apidocsmpl1",
                "preview_url": null,
                "is_image": false,
                "meta": null
            }
        ],
        "comments": [],
        "parent_id": null,
        "depth": 0,
        "is_reply": false,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "4시간 전",
        "updated_at": "2026-07-08 10:41:34",
        "deleted_at": null,
        "is_author": false,
        "is_guest_post": true,
        "is_owner": false,
        "abilities": {
            "can_read": true,
            "can_write": true,
            "can_read_secret": false,
            "can_read_comments": true,
            "can_write_comments": true,
            "can_upload": true,
            "can_download": true,
            "can_manage": false,
            "can_access_admin": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | 비밀번호가 일치하지 않는 경우 (`비밀번호가 일치하지 않습니다.` — Service 의 verifyPassword 가 반환한 에러 키/코드) |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.posts.read`)이 없는 경우 |
| 404 | Not Found | 활성 게시판 또는 게시글이 없는 경우 |
| 422 | Unprocessable Entity | `password` 누락 등 검증 실패 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 검증 처리 중 예외 (`비밀번호 검증에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 비밀글 열람을 위해 비밀번호를 검증합니다. `auth:sanctum` + `sirsoft-board.{slug}.posts.read` 권한이 필요하며, 주로 비회원 비밀글의 내용을 확인할 때 사용합니다. 검증에 성공하면 `password_verified` 플래그가 설정되어 게시글 본문과 첨부파일을 포함한 상세를 반환하고, 실패 시 서비스가 지정한 에러 키/코드로 응답합니다.


### POST /api/modules/sirsoft-board/boards/{slug}/posts/{id}/verify-password-for-modify
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.verify-password-for-modify -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.verify-password-for-modify`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostController@verifyPasswordForModify`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.posts.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| password | body | string | 예 | — | 비밀번호 |

**요청 예시**

```http
POST /api/modules/sirsoft-board/boards/{slug}/posts/{id}/verify-password-for-modify HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
Content-Type: application/json

{
    "password": "Password123!"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| verified | boolean | `true` | 비밀번호 검증 성공 여부 (성공 응답에서는 항상 true) |
| post_id | integer | `237` | 검증 대상 게시글 식별자 |
| verification_token | string | `G7xk3mQ8pRt1vZ...` (32자 랜덤 문자열) | 이후 수정/삭제 요청에서 비밀번호 대신 사용하는 임시 검증 토큰 (캐시에 저장, 1회 소비) |
| expires_at | string | `2026-07-08T11:41:34+09:00` | 검증 토큰 만료 시각 (PostService::storeDeleteVerifyToken 산출) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "비밀번호가 확인되었습니다.",
    "data": {
        "verified": true,
        "post_id": 1,
        "verification_token": "G7xk3mQ8pRt1vZaB4cD6eF8gH0iJ2kL4",
        "expires_at": "2026-07-08T11:41:34+09:00"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthorized | 비밀번호가 일치하지 않는 경우 (Service 의 verifyPassword 가 반환한 에러 키/코드) |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.posts.write`)이 없는 경우 |
| 404 | Not Found | 활성 게시판 또는 게시글이 없는 경우 |
| 422 | Unprocessable Entity | `password` 누락 등 검증 실패 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 검증 처리 중 예외 (`비밀번호 검증에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시글 수정/삭제 전 권한 확인을 위해 비밀번호를 검증합니다. `auth:sanctum` + `sirsoft-board.{slug}.posts.write` 권한이 필요하며, 주로 비회원 글의 소유 확인에 사용합니다. 검증 성공 시 32자 임시 토큰(`verification_token`)과 만료 시각을 발급하여, 이후 수정/삭제 요청에서 비밀번호를 다시 보내지 않고 토큰으로 권한을 증명하게 합니다.


### GET /api/modules/sirsoft-board/boards/{slug}/posts/{postId}/comments
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.comments.index -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.comments.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\CommentController@index`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.comments.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| postId | path | string | 예 | — | 대상 post의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/boards/{slug}/posts/{postId}/comments HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `760` | 기본 키 (내부 식별자) |
| post_id | integer | `237` | post 식별자 (연관 리소스 참조) |
| parent_id | null | `null` | parent 식별자 (연관 리소스 참조) |
| content | string | `API 문서 샘플 댓글입니다.` | 본문 내용 |
| author | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 작성자 사용자 객체 (uuid/name — author 관계 파생) |
| is_secret | boolean | `false` | secret 여부 |
| status | string | `published` | 게시 상태 (published 게시됨 / blinded 블라인드 처리 / deleted 삭제됨 — PostStatus Enum 값) |
| status_label | string | `게시됨` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| depth | integer | `0` | 계층 트리에서의 깊이 (0 = 최상위, 하위로 갈수록 증가) |
| replies_count | integer | `0` | replies 개수 (집계) |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| updated_at | string | `2026-07-07 09:34:50` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| is_cascade_deleted | boolean | `false` | cascade deleted 여부 |
| ip_address | null | `null` | 요청/행위가 발생한 IP 주소 |
| action_logs | array | `[]` | 블라인드/복원/삭제 등 운영 처리 이력 (admin.manage 권한 보유자에게만 노출, action/reason/admin_name/created_at — 비권한자는 빈 배열/null) |
| is_author | boolean | `true` | author 여부 |
| is_guest_comment | boolean | `false` | guest comment 여부 |
| is_already_reported | boolean | `false` | already reported 여부 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_write":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-board::messages.comments.index_success",
    "data": [
        {
            "id": 1,
            "post_id": 1,
            "parent_id": null,
            "content": "API 문서 샘플 댓글입니다.",
            "author": {
                "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                "name": "API 문서 샘플 사용자",
                "email": "apidoc-sample-user@example.com",
                "avatar": null,
                "status": "active",
                "status_label": "활성",
                "is_guest": false
            },
            "is_secret": "{MASKED}",
            "status": "published",
            "status_label": "게시됨",
            "depth": 0,
            "replies_count": 0,
            "created_at": "2026-07-08 10:41:34",
            "created_at_formatted": "4시간 전",
            "updated_at": "2026-07-08 10:41:34",
            "deleted_at": null,
            "is_cascade_deleted": false,
            "ip_address": null,
            "action_logs": [],
            "is_author": true,
            "is_guest_comment": false,
            "is_already_reported": false,
            "is_owner": true,
            "abilities": {
                "can_write": true
            }
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.comments.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 게시글의 댓글 목록을 조회합니다. `auth:sanctum` + `sirsoft-board.{slug}.comments.read` 권한이 필요하며, 게시판의 댓글 기능이 꺼져 있으면 403을 반환합니다. 대댓글 계층(`depth`, `replies_count`)을 포함하며, 각 항목에 현재 사용자의 작성자 여부·소유 여부·신고 이력 등이 메타로 담깁니다.


### POST /api/modules/sirsoft-board/boards/{slug}/posts/{postId}/comments
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.comments.store -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.comments.store`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\CommentController@store`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.comments.write`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| postId | path | string | 예 | — | 대상 post의 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.comment.store_validation_rules`).

**요청 예시**

```http
POST /api/modules/sirsoft-board/boards/{slug}/posts/{postId}/comments HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (생성된 댓글 = `CommentResource`, 댓글 목록(`GET .../comments`) 항목과 동일 스키마)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `760` | 생성된 댓글 기본 키 |
| post_id | integer | `237` | 댓글이 속한 게시글 식별자 |
| parent_id | integer \| null | `null` | 대댓글의 상위 댓글 식별자 (최상위 댓글이면 null) |
| content | string | `API 문서 샘플 댓글입니다.` | 댓글 본문 |
| author | object | `{"uuid":"a234c2b1-…","name":"API 문서 샘플 사용자","email":"…","avatar":null,"status":"active","status_label":"활성","is_guest":false}` | 작성자 정보 (비회원이면 `is_guest: true`) |
| is_secret | boolean | `false` | 비밀 댓글 여부 |
| status | string | `published` | 게시 상태 (published / blinded / deleted — PostStatus Enum 값) |
| status_label | string | `게시됨` | 게시 상태 표시 라벨 |
| depth | integer | `0` | 대댓글 계층 깊이 (0 = 최상위) |
| replies_count | integer | `0` | 이 댓글에 달린 대댓글 수 (생성 직후 0) |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `방금 전` | 표시용 일시 포맷 |
| updated_at | string | `2026-07-08 10:41:34` | 최종 수정 일시 |
| deleted_at | null | `null` | 소프트 삭제 일시 (미삭제 시 null) |
| is_cascade_deleted | boolean | `false` | 게시글 삭제로 함께 숨겨진(cascade) 댓글인지 여부 |
| ip_address | string \| null | `null` | 작성 IP (admin.manage 권한 보유자에게만 노출) |
| action_logs | array \| null | `null` | 블라인드/복원/삭제 등 운영 처리 이력 (admin.manage 권한 보유자만) |
| is_author | boolean | `true` | 현재 사용자가 작성자인지 여부 |
| is_guest_comment | boolean | `false` | 비회원 작성 댓글 여부 |
| is_already_reported | boolean | `false` | 현재 사용자가 이 댓글을 이미 신고했는지 여부 |
| is_owner | boolean | `true` | 현재 인증 사용자가 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_write":true}` | 현재 사용자의 댓글 작성 권한 맵 (사용자 라우트는 `can_write` 만 노출) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "댓글이 등록되었습니다.",
    "data": {
        "id": 1,
        "post_id": 1,
        "parent_id": null,
        "content": "API 문서 샘플 댓글입니다.",
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_secret": false,
        "status": "published",
        "status_label": "게시됨",
        "depth": 0,
        "replies_count": 0,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "방금 전",
        "updated_at": "2026-07-08 10:41:34",
        "deleted_at": null,
        "is_cascade_deleted": false,
        "ip_address": null,
        "action_logs": null,
        "is_author": true,
        "is_guest_comment": false,
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_write": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.comments.write`)이 없거나, 게시판의 댓글 기능이 꺼진 경우 (`이 게시판은 댓글 기능이 비활성화되어 있습니다.`) |
| 404 | Not Found | 게시판 슬러그에 해당하는 게시판이 없는 경우 (`게시판을 찾을 수 없습니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지 — 댓글 길이, 금지어, 작성 쿨다운 등) |
| 500 | Internal Server Error | 댓글 저장 중 예외 (`댓글 등록에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 게시글에 댓글(또는 대댓글)을 작성합니다. `auth:sanctum` + `sirsoft-board.{slug}.comments.write` 권한이 필요하며, 게시판의 댓글 기능이 꺼져 있으면 403을 반환합니다. 현재 로그인 사용자를 작성자로 저장하고 요청 IP를 기록하며, 스팸 방지 쿨다운이 설정되어 있으면 작성 성공 후 쿨다운을 기록합니다. `parent_id`는 경로의 `{postId}`에 속한 댓글이어야 하며, 다른 게시글의 댓글을 지정하면 422를 반환합니다. 저장되는 `depth`는 부모 댓글 `depth + 1`이고, 게시판 설정 `max_comment_depth`를 넘는 답글은 422로 거절됩니다.


### DELETE /api/modules/sirsoft-board/boards/{slug}/posts/{postId}/comments/{commentId}
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.comments.destroy -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.comments.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\CommentController@destroy`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.comments.write|sirsoft-board.{slug}.manager`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| postId | path | string | 예 | — | 대상 post의 식별자 |
| commentId | path | string | 예 | — | 대상 comment의 식별자 |
| password | body | string | 아니오 | min 4, max 20 | 비회원 댓글 삭제 시 소유권 확인용 비밀번호. 형식(문자열, 4~20자)은 `DestroyCommentRequest` 가 검증하며, 배열 주입 등 형식 위반은 422. 소유권 판정 자체는 서비스(`canDelete`)가 수행 |
| verification_token | body | string | 아니오 | max 255 | 비회원 댓글 삭제 시 비밀번호 재입력을 대체하는 임시 검증 토큰 (verify-password 로 발급, 1회 소비). 문자열 형식 위반(배열 주입 등)은 422 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/boards/{slug}/posts/{postId}/comments/{commentId} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만). 컨트롤러가 `success('...comment.delete_success')` 를 데이터 없이 호출하며, 삭제는 소프트 삭제입니다._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "댓글이 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.comments.write\|sirsoft-board.{slug}.manager`)이 없거나, 게시판의 댓글 기능이 꺼진 경우, 또는 작성자 본인·게시판 관리자·비회원 댓글 비밀번호 확인 중 어느 조건도 만족하지 않는 경우 (`댓글 삭제 권한이 없습니다.`) |
| 404 | Not Found | 게시판 또는 댓글이 없는 경우 (`댓글을 찾을 수 없습니다.`) |
| 422 | Unprocessable Entity | `password` 가 형식 검증(문자열, 4~20자)을 위반한 경우 (`error.errors.password` — 배열 주입 차단) |
| 500 | Internal Server Error | 삭제 처리 중 예외 (`댓글 삭제에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 댓글을 삭제합니다. `auth:sanctum` + `sirsoft-board.{slug}.comments.write` 또는 게시판 manager 권한이 필요하며, 게시판의 댓글 기능이 꺼져 있으면 403을 반환합니다. 서비스의 `canDelete` 가 작성자 본인·게시판 관리자·비회원 댓글의 비밀번호 확인 여부를 판정하며, 조건 미충족 시 403을 반환합니다. 경로의 `{postId}`에 속한 댓글만 대상이 됩니다 — 다른 게시글의 댓글 ID를 지정하면 404를 반환하며 해당 댓글은 변경되지 않습니다.


### PUT /api/modules/sirsoft-board/boards/{slug}/posts/{postId}/comments/{commentId}
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.comments.update -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.comments.update`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\CommentController@update`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근) + `permission:sirsoft-board.{slug}.comments.write|sirsoft-board.{slug}.manager`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| postId | path | string | 예 | — | 대상 post의 식별자 |
| commentId | path | string | 예 | — | 대상 comment의 식별자 |
| content | body | string | 예 | 게시판 설정의 min/max 길이 | 수정할 댓글 본문 (금지 키워드 검사 포함) |
| password | body | string | 조건부 | min 4, max 20 | 비회원 댓글 수정 시 소유권 확인용 비밀번호 (검증 토큰이 있으면 생략 가능) |
| verification_token | body | string | 아니오 | max 255 | 비회원 댓글 수정 시 비밀번호 재입력을 대체하는 임시 검증 토큰 (verify-password 로 발급, 1회 소비) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`sirsoft-board.comment.update_validation_rules`).

**요청 예시**

```http
PUT /api/modules/sirsoft-board/boards/{slug}/posts/{postId}/comments/{commentId} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (수정된 댓글 = `CommentResource`, 댓글 목록 항목과 동일 스키마)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `760` | 댓글 기본 키 |
| post_id | integer | `237` | 댓글이 속한 게시글 식별자 |
| parent_id | integer \| null | `null` | 대댓글의 상위 댓글 식별자 |
| content | string | `수정된 댓글 본문입니다.` | 수정된 댓글 본문 |
| author | object | `{"uuid":"a234c2b1-…","name":"API 문서 샘플 사용자","email":"…","avatar":null,"status":"active","status_label":"활성","is_guest":false}` | 작성자 정보 |
| is_secret | boolean | `false` | 비밀 댓글 여부 |
| status | string | `published` | 게시 상태 (PostStatus Enum 값) |
| status_label | string | `게시됨` | 게시 상태 표시 라벨 |
| depth | integer | `0` | 대댓글 계층 깊이 |
| replies_count | integer | `0` | 대댓글 수 (집계) |
| created_at | string | `2026-07-08 10:41:34` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | 표시용 일시 포맷 |
| updated_at | string | `2026-07-08 11:02:10` | 최종 수정 일시 (수정 후 갱신됨) |
| deleted_at | null | `null` | 소프트 삭제 일시 |
| is_cascade_deleted | boolean | `false` | 게시글 삭제로 함께 숨겨진(cascade) 댓글인지 여부 |
| ip_address | string \| null | `null` | 작성 IP (admin.manage 권한 보유자에게만 노출) |
| action_logs | array \| null | `null` | 운영 처리 이력 (admin.manage 권한 보유자만) |
| is_author | boolean | `true` | 현재 사용자가 작성자인지 여부 |
| is_guest_comment | boolean | `false` | 비회원 작성 댓글 여부 |
| is_already_reported | boolean | `false` | 현재 사용자가 이 댓글을 이미 신고했는지 여부 |
| is_owner | boolean | `true` | 현재 인증 사용자가 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_write":true}` | 현재 사용자의 댓글 작성 권한 맵 |

> 참고: 비회원 댓글 수정 시 함께 보내는 `password` 는 소유 검증용으로만 사용되며 저장 대상에서 제외됩니다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "댓글이 수정되었습니다.",
    "data": {
        "id": 1,
        "post_id": 1,
        "parent_id": null,
        "content": "수정된 댓글 본문입니다.",
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "avatar": null,
            "status": "active",
            "status_label": "활성",
            "is_guest": false
        },
        "is_secret": false,
        "status": "published",
        "status_label": "게시됨",
        "depth": 0,
        "replies_count": 0,
        "created_at": "2026-07-08 10:41:34",
        "created_at_formatted": "4시간 전",
        "updated_at": "2026-07-08 11:02:10",
        "deleted_at": null,
        "is_cascade_deleted": false,
        "ip_address": null,
        "action_logs": null,
        "is_author": true,
        "is_guest_comment": false,
        "is_already_reported": false,
        "is_owner": true,
        "abilities": {
            "can_write": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 403 | Forbidden | 요구 권한(`sirsoft-board.{slug}.comments.write\|sirsoft-board.{slug}.manager`)이 없거나, 게시판의 댓글 기능이 꺼진 경우, 또는 작성자 본인·게시판 관리자·비회원 댓글 비밀번호 확인 중 어느 조건도 만족하지 않는 경우 (`댓글 수정 권한이 없습니다.`) |
| 404 | Not Found | 게시판 또는 댓글이 없는 경우 (`댓글을 찾을 수 없습니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 수정 처리 중 예외 (`댓글 수정에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 댓글을 수정합니다. `auth:sanctum` + `sirsoft-board.{slug}.comments.write` 또는 게시판 manager 권한이 필요하며, 게시판의 댓글 기능이 꺼져 있으면 403을 반환합니다. 서비스의 `canUpdate` 가 작성자 본인·게시판 관리자·비회원 댓글의 비밀번호 확인 여부를 판정하며, 검증용 `password` 는 저장에서 제외됩니다. 경로의 `{postId}`에 속한 댓글만 대상이 됩니다 — 다른 게시글의 댓글 ID를 지정하면 404를 반환하며 해당 댓글은 변경되지 않습니다. 요청 본문에서는 `content`·`is_secret`·`status`만 반영되며, `post_id`·`parent_id`·`depth` 등 그 밖의 필드를 함께 보내도 무시됩니다.


### POST /api/modules/sirsoft-board/boards/{slug}/posts/{postId}/reports
<!-- @generated:start:api.modules.sirsoft-board.boards.posts.reports.store -->
- **라우트명**: `api.modules.sirsoft-board.boards.posts.reports.store`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\ReportController@storePostReport`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | path | string | 예 | — | 대상 리소스의 slug (URL 친화 식별자) |
| postId | path | string | 예 | — | 대상 post의 식별자 |
| reason_type | body | string | 예 | — | 신고 사유 유형 (ReportReasonType Enum 값 중 하나 — 스팸/욕설 등) |
| reason_detail | body | string | 예 | min 1, max 1000 | 신고 사유 상세 설명 (1~1000자) |

**요청 예시**

```http
POST /api/modules/sirsoft-board/boards/{slug}/posts/{postId}/reports HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "reason_type": "예시값",
    "reason_detail": "예시값"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 (접수된 신고 케이스 = `ReportResource` — 댓글 신고 응답과 동일 스키마이며 `target_type` 만 `post` 로 다릅니다)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 신고 케이스 기본 키 |
| board_id | integer | `1` | 신고가 접수된 게시판 식별자 |
| board | object | `{"id":1,"name":"API 문서 샘플 게시판","slug":"apidoc-sample-board","title":"API 문서 샘플 게시글","current_status":null,"deleted_at":null}` | 게시판 정보 + 신고 대상 제목/현재 상태 |
| target_type | string | `post` | 신고 대상 유형 (`post` 게시글 — ReportType Enum 값) |
| target_type_label | string | `게시글` | 신고 대상 유형 표시 라벨 |
| target_id | integer | `237` | 신고 대상(게시글) 식별자 |
| post_id | integer | `237` | 신고 대상 게시글 식별자 (게시글 신고에서는 `target_id` 와 동일) |
| content | null | `null` | 신고 대상 본문 (상세 조회 라우트에서만 채워짐) |
| content_mode | string | `html` | 신고 대상 본문 편집 모드 (html / text) |
| content_preview | string | `API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.` | 신고 대상 본문 미리보기 (앞 100자) |
| author | object | `{"uuid":"a234c2b1-…","name":"API 문서 샘플 사용자","email":"…","is_guest":false}` | 신고 대상 게시글의 작성자 |
| reporter | object \| null | `{"uuid":"…","name":"신고자","email":"reporter@example.com","is_guest":false}` | 최초 신고자 (첫 번째 신고 로그 기준) |
| reason_type | string | `spam` | 대표 신고 사유 유형 (abuse, hate_speech, spam, copyright, privacy, misinformation, sexual, violence, other) |
| reason_type_label | string | `스팸/광고` | 대표 신고 사유 표시 라벨 |
| status | string | `pending` | 신고 케이스 처리 상태 (접수 직후 `pending`) |
| status_label | string | `대기` | 처리 상태 표시 라벨 |
| status_variant | string | `warning` | 처리 상태 UI 배지 변형값 |
| processor | null | `null` | 처리 관리자 (uuid/name — 미처리 시 null) |
| processed_at | null | `null` | 처리 일시 (미처리 시 null) |
| metadata | null | `null` | 신고 메타(IP/User-Agent) — 상세 조회 라우트에서만 노출 |
| report_count | null | `null` | 케이스 누적 신고 건수 (목록 그룹 조회에서만 채워짐) |
| last_reported_at | string | `2026-07-08 10:41:34` | 마지막 신고 접수 일시 |
| is_reactivated | boolean | `false` | 반려/중단 후 재접수된 케이스인지 여부 |
| target_status | null | `null` | 신고 대상 현재 상태 (목록 그룹 조회에서만 채워짐) |
| target_trigger_type | null | `null` | 신고 대상 상태 변경 주체 (목록 그룹 조회에서만 채워짐) |
| target_status_label | null | `null` | 신고 대상 상태 표시 라벨 (목록 그룹 조회에서만 채워짐) |
| created_at | string | `2026-07-08 10:41:34` | 신고 케이스 생성 일시 |
| updated_at | string | `2026-07-08 10:41:34` | 최종 수정 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 신고의 신고자인지 여부 |
| abilities | object | `{"can_view":false,"can_manage":false}` | 현재 사용자의 신고 조회/관리 권한 맵 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "신고가 접수되었습니다.",
    "data": {
        "id": 1,
        "board_id": 1,
        "board": {
            "id": 1,
            "name": "API 문서 샘플 게시판",
            "slug": "apidoc-sample-board",
            "title": "API 문서 샘플 게시글",
            "current_status": null,
            "deleted_at": null
        },
        "target_type": "post",
        "target_type_label": "게시글",
        "target_id": 1,
        "post_id": 1,
        "content": null,
        "content_mode": "html",
        "content_preview": "API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.",
        "author": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자",
            "email": "apidoc-sample-user@example.com",
            "is_guest": false
        },
        "reporter": null,
        "reason_type": null,
        "reason_type_label": null,
        "status": "pending",
        "status_label": "대기",
        "status_variant": "warning",
        "processor": null,
        "processed_at": null,
        "metadata": null,
        "report_count": null,
        "last_reported_at": "2026-07-08 10:41:34",
        "is_reactivated": false,
        "target_status": null,
        "target_trigger_type": null,
        "target_status_label": null,
        "created_at": "2026-07-08 10:41:34",
        "updated_at": "2026-07-08 10:41:34",
        "is_owner": true,
        "abilities": {
            "can_view": false,
            "can_manage": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 게시판의 신고 기능이 꺼진 경우(`이 게시판은 신고 기능이 비활성화되어 있습니다.`), 본인이 작성한 글 신고(`본인이 작성한 글은 신고할 수 없습니다.`), 블라인드/삭제된 대상 신고(`신고할 수 없는 대상입니다.`) |
| 404 | Not Found | 게시판 슬러그에 해당하는 게시판이 없는 경우 |
| 409 | Conflict | 동일 대상에 이미 신고한 이력이 있는 경우 (`이미 신고한 내역이 있습니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 신고 접수 중 예외 (`신고 접수에 실패했습니다.`) |

<!-- @generated:end -->

**설명** 특정 게시글을 신고합니다. 신고는 회원 전용이라 컨트롤러가 `AuthBaseController` 를 상속해 로그인 사용자만 호출할 수 있으며, 게시판의 신고 기능이 꺼져 있으면 403을 반환합니다. 본인 글 신고, 블라인드/삭제된 대상 신고는 차단되고, 이미 신고한 대상이면 409(중복)로 응답합니다. 신고 사유 유형(`reason_type`)과 상세(`reason_detail`)를 받아 신고를 접수하며 사용자 활동 로그를 남깁니다.


