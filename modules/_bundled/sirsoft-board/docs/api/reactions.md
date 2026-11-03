# Reactions API 레퍼런스 (sirsoft-board · 회원/관리자 반응 내역)

> **소유**: sirsoft-board 모듈, **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며,
> 블록 밖에 사람이 쓴 설명(상태 표, 설계 이유, 보충 표)은 보존됩니다.
>
> 반응을 **남기는** 엔드포인트(글/댓글 토글)는 [boards.md](boards.md) 의 `POST …/posts/{postId}/react`, `POST …/comments/{commentId}/react` 절을,
> 반응 **유형** 관리·아이콘 업로드는 [reaction-types.md](reaction-types.md) 를 보세요.

---

## TL;DR (5초 요약)

```text
1. 회원이 남긴 반응 내역을 조회·해제하고(마이페이지), 운영자가 전 회원 반응을 관리합니다
2. 회원용 3개 + 관리자용 3개 엔드포인트가 있습니다
3. 게시글 반응과 댓글 반응이 한 목록에 섞여 나옵니다 (target_type 으로 구분)
4. 반응 행은 원글·댓글·게시판이 지워져도 남습니다 — 해제(회수)는 언제나 가능합니다
5. 권한: sirsoft-board.reactions.view(조회) · .manage(삭제)
6. 관리자 목록·삭제의 처리 중 서버 오류는 500 으로 답합니다(입력 오류로 위장하지 않음 — 종전 400)
```

---

## 반응 행의 네 상태

목록은 아래 넷을 **모두** 보여 줍니다. 감추면 정리할 대상을 찾지 못하기 때문입니다.

| 상태 | 판정 | 화면 표기 |
| --- | --- | --- |
| 정상 | 게시판 살아 있고 `use_reaction` on(댓글이면 `use_comment_reaction` 도), 대상 살아 있음 | 제목 클릭 → 대상 |
| 반응 꺼짐 | 게시판의 `use_reaction`(또는 `use_comment_reaction`)가 꺼짐·비활성 | 「반응 꺼짐」 배지, 링크 없음 |
| 삭제된 대상 | 글/댓글 소프트 삭제 또는 미게시 | 「삭제된 게시글/댓글」, 제목·발췌 비노출 |
| 게시판 삭제됨 | `board_id` 가 가리키는 게시판이 사라짐 (`board: null`) | 「게시판 삭제됨」 배지 |

어느 상태든 **해제는 허용**합니다 (설계 결정 R11) — 남긴 기록을 회수하는 것은 게시판 설정과
무관한 회원의 권리입니다. 마이페이지 검색은 «삭제된 대상» 을 **대상에서 뺍니다**.

---

## 회원용 (마이페이지 「내 반응」)

### GET /api/modules/sirsoft-board/me/reactions
<!-- @generated:start:api.modules.sirsoft-board.me.reactions.index -->
- **라우트명**: `api.modules.sirsoft-board.me.reactions.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\ReactionController@index`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 페이지 번호 |
| per_page | query | integer | 아니오 | 1~100 (기본 10) | 페이지당 건수 |
| search | query | string | 아니오 | max 255 | 검색어 |
| search_field | query | string | 아니오 | `title_content`(기본), `title`, `content` | 검색 대상. 규칙 밖의 값은 기본값 `title_content` 로 처리 |
| reaction_type_id | query | integer | 아니오 | min 1 | 반응 유형 필터 |
| target_type | query | string | 아니오 | `all`(기본), `post`, `comment` | 대상 구분 필터 |
| board_slug | query | string | 아니오 | max 50 | 게시판 필터 |
| sort | query | string | 아니오 | `latest`(기본), `oldest` | 정렬 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/me/reactions?page=1&per_page=1&search=%EC%98%88%EC%8B%9C%EA%B0%92&search_field=title_content&reaction_type_id=1&target_type=post&board_slug=example-key&sort=latest HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1663` | 반응 식별자 (해제가 받는 값) |
| target_type | string | `comment` | `post` \| `comment` |
| target_id | integer | `717` | 대상 식별자 |
| post_id | integer | `571` | 이동 대상 글 ID (댓글 반응도 부모 글로 갑니다) |
| comment_id | integer | `717` | 댓글 반응일 때의 댓글 ID |
| title | string | `비회원 비밀글` | 글 제목 (댓글 반응도 부모 글 제목) |
| excerpt | string | `비회원 댓글` | 댓글 본문 발췌 80자. 삭제된 댓글이면 null |
| board | object | `{"slug":"free","name":"자유게시판","is_active":true,"use_react…` | `{slug,name,is_active,use_reaction,use_comment_reaction}`. 게시판 삭제 시 null |
| board_slug | string | `free` | 게시판 슬러그 (`board.slug` 와 같은 값). 게시판이 삭제됐으면 null |
| board_name | string | `자유게시판` | 게시판 이름의 현재 로케일 문자열 (`board.name` 과 같은 값). 게시판이 삭제됐으면 null |
| board_deleted | boolean | `false` | 게시판 소실 여부 |
| reaction_type | object | `{"id":1,"code":"like","name":"추천","icon_name":"fa-thumbs-…` | `{id,code,name,icon_name,icon_url}` |
| author_name | string | `댓글 작성자` | 대상 작성자. 탈퇴 회원이면 익명 표기 |
| target_deleted | boolean | `false` | 대상 소실 또는 미게시 여부 |
| reaction_enabled | boolean | `true` | 지금도 반응이 열려 있는 상태인지 (링크와 버튼 노출 판정) |
| reacted_at | string | `2026-09-16 13:59:50` | 반응 시각 — 모듈 표시 설정(`display.date_display_format`)을 따르므로 「2시간 전」 같은 상대 표기일 수 있습니다 |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "반응 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1663,
                "target_type": "comment",
                "target_id": 717,
                "post_id": 571,
                "comment_id": 717,
                "title": "비회원 비밀글",
                "excerpt": "비회원 댓글",
                "board": {
                    "slug": "free",
                    "name": "자유게시판",
                    "is_active": true,
                    "use_reaction": true,
                    "use_comment_reaction": true
                },
                "board_slug": "free",
                "board_name": "자유게시판",
                "board_deleted": false,
                "reaction_type": {
                    "id": 1,
                    "code": "like",
                    "name": "추천",
                    "icon_name": "fa-thumbs-up",
                    "icon_url": null
                },
                "author_name": "댓글 작성자",
                "target_deleted": false,
                "reaction_enabled": true,
                "reacted_at": "2026-09-16 13:59:50"
            },
            {
                "id": 1662,
                "target_type": "post",
                "target_id": 571,
                "post_id": 571,
                "comment_id": null,
                "title": "비회원 비밀글",
                "excerpt": null,
                "board": {
                    "slug": "free",
                    "name": "자유게시판",
                    "is_active": true,
                    "use_reaction": true,
                    "use_comment_reaction": true
                },
                "board_slug": "free",
                "board_name": "자유게시판",
                "board_deleted": false,
                "reaction_type": {
                    "id": 1,
                    "code": "like",
                    "name": "추천",
                    "icon_name": "fa-thumbs-up",
                    "icon_url": null
                },
                "author_name": "비회원 작성자",
                "target_deleted": false,
                "reaction_enabled": true,
                "reacted_at": "2026-09-16 13:59:45"
            }
        ],
        "query": {
            "search": null,
            "search_field": "title_content",
            "reaction_type_id": null,
            "target_type": "all",
            "board_slug": null,
            "sort": "latest"
        },
        "reaction_types": [
            {
                "id": 1,
                "code": "like",
                "name": "추천",
                "icon_name": "fa-thumbs-up",
                "icon_url": null
            },
            {
                "id": 2,
                "code": "dislike",
                "name": "비추천1",
                "icon_name": "fa-thumbs-down",
                "icon_url": null
            }
        ],
        "pagination": {
            "total": 2,
            "count": 2,
            "per_page": 25,
            "current_page": 1,
            "last_page": 1,
            "from": 1,
            "to": 2,
            "has_more_pages": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 조회 실패 (`반응 목록 조회에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

- **인증**: `auth:sanctum` (본인 것만)

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 페이지 번호 |
| per_page | query | integer | 아니오 | 1~100 (기본 10) | 페이지당 건수 |
| search | query | string | 아니오 | max 255 | 검색어 |
| search_field | query | string | 아니오 | `title_content`(기본), `title`, `content` | 검색 대상. 규칙 밖의 값은 기본값 `title_content` 로 처리 |
| reaction_type_id | query | integer | 아니오 | min 1 | 반응 유형 필터 |
| target_type | query | string | 아니오 | `all`(기본), `post`, `comment` | 대상 구분 필터 |
| board_slug | query | string | 아니오 | max 50 | 게시판 필터 |
| sort | query | string | 아니오 | `latest`(기본), `oldest` | 정렬 |

> 제목 전용 검색(`title`)은 댓글 분기를 켜지 않습니다 — 댓글에는 제목이 없습니다.

**응답 필드** (`data.data[]`)

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| id | integer | 반응 식별자 (해제가 받는 값) |
| target_type | string | `post` \| `comment` |
| target_id | integer | 대상 식별자 |
| post_id | integer\|null | 이동 대상 글 ID (댓글 반응도 부모 글로 갑니다) |
| comment_id | integer\|null | 댓글 반응일 때의 댓글 ID |
| title | string\|null | 글 제목 (댓글 반응도 부모 글 제목) |
| excerpt | string\|null | 댓글 본문 발췌 80자. 삭제된 댓글이면 null |
| board | object\|null | `{slug,name,is_active,use_reaction,use_comment_reaction}`. 게시판 삭제 시 null |
| board_deleted | boolean | 게시판 소실 여부 |
| reaction_type | object\|null | `{id,code,name,icon_name,icon_url}` |
| author_name | string\|null | 대상 작성자. 탈퇴 회원이면 익명 표기 |
| target_deleted | boolean | 대상 소실·미게시 여부 |
| reaction_enabled | boolean | 지금도 반응이 열려 있는 상태인지 (링크·버튼 노출 판정) |
| reacted_at | string | 반응 시각 — 모듈 표시 설정(`display.date_display_format`)을 따르므로 「2시간 전」 같은 상대 표기일 수 있습니다 |
| created_at | string | 반응 시각의 절대 표기(초 단위). 저장은 UTC 이고 이 값은 회원 표시 시간대로 환산됩니다 — 관리 목록은 선후를 가려야 하므로 이 필드를 씁니다 |

**응답 메타**

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| data.reaction_types[] | array | 필터 셀렉트 옵션. **비활성 유형 포함** — 이미 끈 유형으로 남긴 과거 반응도 골라내야 합니다 |
| data.boards[] | array | 게시판 필터 셀렉트 옵션(`id`·`slug`·`name`, 비활성 게시판 포함, 최근 생성 순). 게시판 목록 API 는 게시판 관리 권한이 있어야 열리므로 `reactions.view` 만 가진 매니저도 고를 수 있게 여기 싣습니다 |
| data.query | object | 적용된 필터 에코 (화면 상태 복원용) |
| data.pagination | object | 표준 페이지네이션 메타 |

### DELETE /api/modules/sirsoft-board/me/reactions/{id}
<!-- @generated:start:api.modules.sirsoft-board.me.reactions.destroy -->
- **라우트명**: `api.modules.sirsoft-board.me.reactions.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\ReactionController@destroy`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | 해제할 반응 ID (목록 응답의 `id`) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/me/reactions/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "반응을 해제했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | 본인 반응이 아니거나 없는 ID (`반응을 찾을 수 없습니다.`). 남의 반응도 404 로 답해 존재 여부를 알리지 않습니다 |
| 500 | Internal Server Error | 해제 실패 (`반응 해제에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

- **인증**: `auth:sanctum` · 본인 소유만
- 남의 반응·없는 ID → **404** (존재 여부를 흘리지 않습니다)
- 해제 후 대상의 `reaction_counts` 캐시를 실제 행 수로 다시 집계합니다
- 대상이 이미 하드 삭제됐으면 재집계를 건너뜁니다 (행만 사라지면 됩니다)

### DELETE /api/modules/sirsoft-board/me/reactions
<!-- @generated:start:api.modules.sirsoft-board.me.reactions.bulk-destroy -->
- **라우트명**: `api.modules.sirsoft-board.me.reactions.bulk-destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\ReactionController@bulkDestroy`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1, max 100 | 해제할 반응 ID 목록 (각 값은 1 이상 정수, 중복 불가). 본문 JSON 으로 보내도 됩니다. 남의 ID 와 없는 ID 는 오류 없이 건너뜁니다 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/me/reactions?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `2` | 실제로 해제한 본인 반응 수 (건너뛴 ID 는 세지 않습니다) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "2개의 반응을 해제했습니다.",
    "data": {
        "deleted_count": 2
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | `ids` 가 비었거나 100개를 넘거나, 정수가 아니거나 중복된 값이 있는 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 해제 실패 (`반응 해제에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

선택 해제(일괄). 본문 JSON 으로 `ids[]` 를 받습니다.

| 이름 | 타입 | 필수 | 허용값 |
| --- | --- | --- | --- |
| ids | array | 예 | 1~100개, 정수, 중복 불가 |

남의 ID·없는 ID 는 **조용히 건너뛰고** 실제 해제 건수만 돌려줍니다 — `exists` 를 걸면
"그 ID 가 존재하는가" 가 422/200 으로 갈려 남의 반응 ID 를 탐색하는 통로가 됩니다.

응답: `data.deleted_count` (integer)

---

## 관리자용 (게시판 관리 › 반응 관리)

권한은 게시판별이 아니라 **모듈 레벨**입니다 — 반응은 회원 소유 데이터라 게시판 경계를
넘어 조회됩니다 (스크랩 관리와 동형).

### GET /api/modules/sirsoft-board/admin/reactions
<!-- @generated:start:api.modules.sirsoft-board.admin.reactions.index -->
- **라우트명**: `api.modules.sirsoft-board.admin.reactions.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReactionController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.reactions.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| filters | query | array | 아니오 | — | 추가 필터 조건 맵 (필드별 조건) |
| filters.0.field | query | string | 아니오 | `all`, `target_title`, `target_content`, `board_name`, `member`, `author_name` | 검색 대상. 생략하면 `all` 입니다 |
| filters.0.value | query | string | 아니오 | max 255 | 값 |
| filters.0.operator | query | string | 아니오 | — | 검색 연산자 자리. 관리자 목록 공통 형식으로 받기만 하고 이 목록은 값을 읽지 않습니다 |
| board_id | query | integer | 아니오 | min 1 | 게시판 필터 |
| user_id | query | integer | 아니오 | min 1 | 회원 필터 |
| user_uuid | query | string | 아니오 | uuid | 회원 필터(uuid). 없는 회원이면 **빈 결과** |
| reaction_type_id | query | integer | 아니오 | 존재하는 유형 | 유형 필터 |
| target_type | query | string | 아니오 | `post`, `comment` | 대상 구분 |
| reacted_at_from | query | date | 아니오 | `Y-m-d` | 반응일 범위 시작일 (보는 사람의 시간대로 그날 하루를 포함) |
| reacted_at_to | query | date | 아니오 | `Y-m-d`, `reacted_at_from` 이상 | 반응일 범위 종료일 (보는 사람의 시간대로 그날 하루를 포함) |
| sort_order | query | string | 아니오 | `asc`, `desc`(기본) | 정렬 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/reactions?page=1&per_page=1&filters=%EC%98%88%EC%8B%9C%EA%B0%92&filters.0.field=%EC%98%88%EC%8B%9C%EA%B0%92&filters.0.value=%EC%98%88%EC%8B%9C%EA%B0%92&filters.0.operator=%EC%98%88%EC%8B%9C%EA%B0%92&board_id=1&user_id=1&user_uuid=%EC%98%88%EC%8B%9C%EA%B0%92&reaction_type_id=1&target_type=post&reacted_at_from=2026-01-01&reacted_at_to=2026-01-01&sort_order=asc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1855` | 기본 키 (내부 식별자) |
| reacted_at | string | `10-08` | reacted 일시 |
| created_at | string | `2026-10-08 12:46:12` | 생성 일시 |
| target_type | string | `post` | 반응 대상 구분 (`post` 또는 `comment`) |
| target_type_label | string | `게시글` | `target_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| target_id | integer | `3227` | target 식별자 (연관 리소스 참조) |
| post_id | integer | `3227` | post 식별자 (연관 리소스 참조) |
| comment_id | integer | `1109` | comment 식별자 (연관 리소스 참조) |
| member | object | `{"uuid":"41866241-871d-497d-9d18-44a1748f8c98","name":"ad…` | `{uuid,name,email,is_withdrawn}`. 이메일은 관리자 응답에만, 탈퇴 회원 이름은 익명화 |
| board | object | `{"id":1,"slug":"notice","name":"공지사항","is_active":true,"use_r…` | 게시판 `{id,slug,name,is_active,use_reaction,use_comment_reaction}`. 게시판이 삭제됐으면 null |
| target_title | string | `관리자 공지 글` | 글 제목 (댓글 반응도 부모 글 제목). 삭제된 대상의 제목도 냅니다 |
| target_excerpt | string | `댓글 내용` | 댓글 반응일 때 댓글 본문 발췌 (태그 제거, 80자). 게시글 반응이거나 삭제된 댓글이면 null |
| target_status | string | `published` | 대상 상태 (`published`, `blinded`, `deleted`). 대상이 사라졌으면 `deleted` |
| target_status_label | string | `게시됨` | `target_status` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| is_target_deleted | boolean | `false` | 대상 소실 여부 |
| author_name | string | `admin` | 대상(글 또는 댓글) 작성자 이름. 탈퇴 회원이면 익명 표기, 비회원 글이면 입력한 작성자명 |
| reaction_type | object | `{"id":1,"code":"like","name":"추천","icon_name":"fa-thumbs-…` | 반응 유형 `{id,code,name,icon_name,icon_url,is_active}`. 비활성 유형도 그대로 실리며 `is_active` 로 구분합니다 |
| abilities | object | `{"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "반응 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1855,
                "reacted_at": "10-08",
                "created_at": "2026-10-08 12:46:12",
                "target_type": "post",
                "target_type_label": "게시글",
                "...": "(13개 키 생략, 총 18개)"
            },
            {
                "id": 1854,
                "reacted_at": "10-08",
                "created_at": "2026-10-08 11:57:39",
                "target_type": "post",
                "target_type_label": "게시글",
                "...": "(13개 키 생략, 총 18개)"
            },
            "... (총 15건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 25,
            "from": 1,
            "to": 15,
            "has_more_pages": false,
            "...": "(5개 키 생략, 총 10개)"
        },
        "reaction_types": [
            {
                "id": 1,
                "code": "like",
                "name": "추천",
                "icon_name": "fa-thumbs-up",
                "icon_url": null,
                "...": "(1개 키 생략, 총 6개)"
            },
            {
                "id": 2,
                "code": "dislike",
                "name": "비추천1",
                "icon_name": "fa-thumbs-down",
                "icon_url": null,
                "...": "(1개 키 생략, 총 6개)"
            }
        ],
        "boards": [
            {
                "id": 1008,
                "slug": "product-inquiry",
                "name": "상품 문의"
            },
            {
                "id": 5,
                "slug": "qna",
                "name": "Q&A"
            },
            {
                "id": 3,
                "slug": "gallery",
                "name": "갤러리"
            },
            {
                "id": 2,
                "slug": "free",
                "name": "자유게시판"
            },
            {
                "id": 1,
                "slug": "notice",
                "name": "공지사항"
            },
            "... (총 38건 중 5건 표시)"
        ],
        "abilities": {
            "can_view": true,
            "can_manage": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.reactions.view`)이 없는 경우 (`반응 관리 권한이 없습니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 조회 실패 (`반응 목록 조회에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

- **인증**: `auth:sanctum` + `admin` · **권한**: `sirsoft-board.reactions.view`

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page / per_page | query | integer | 아니오 | per_page 1~100 (기본 20) | 페이지네이션 |
| filters[0][field] | query | string | 아니오 | `all`(기본), `target_title`, `target_content`, `board_name`, `member`, `author_name` | 검색 대상 |
| filters[0][value] | query | string | 아니오 | max 255 | 검색어 |
| board_id | query | integer | 아니오 | min 1 | 게시판 필터 |
| user_id | query | integer | 아니오 | min 1 | 회원 필터 |
| user_uuid | query | string | 아니오 | uuid | 회원 필터(uuid). 없는 회원이면 **빈 결과** |
| reaction_type_id | query | integer | 아니오 | 존재하는 유형 | 유형 필터 |
| target_type | query | string | 아니오 | `post`, `comment` | 대상 구분 |
| reacted_at_from / _to | query | string | 아니오 | `Y-m-d`, to ≥ from | 반응일 범위 (보는 사람의 시간대 하루로 해석) |
| sort_order | query | string | 아니오 | `asc`, `desc`(기본) | 정렬 |

> 관리자 검색은 삭제된 대상도 **포함**합니다 — 정리할 대상을 찾는 화면이기 때문입니다.

**응답 필드** (`data.data[]`) — 회원용과 다른 부분만

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| member | object\|null | `{uuid,name,email,is_withdrawn}`. 이메일은 관리자 응답에만, 탈퇴 회원 이름은 익명화 |
| target_title / target_excerpt | string\|null | 삭제된 대상의 제목도 냅니다 (상태는 배지가 구분) |
| target_status / target_status_label | string | 삭제면 `deleted` |
| is_target_deleted | boolean | 대상 소실 여부 |
| reaction_type.is_active | boolean | 비활성 유형 배지 판정 |
| abilities.can_delete | boolean | = `reactions.manage` |

**응답 메타**: `data.abilities{can_view,can_manage}` + `data.reaction_types[]`(비활성 포함) + `data.boards[]`(게시판 선택지).
유형 옵션을 목록 응답에 싣는 이유는 `reactions.view` 만 가진 매니저가 `settings.read` 가 없어
유형 API 를 부를 수 없기 때문입니다.
권한 범위가 「본인만」·「소유역할」이면 그 범위의 글(항목)만 담깁니다(1.2.1+).

### DELETE /api/modules/sirsoft-board/admin/reactions/{id}
<!-- @generated:start:api.modules.sirsoft-board.admin.reactions.destroy -->
- **라우트명**: `api.modules.sirsoft-board.admin.reactions.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReactionController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.reactions.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | 삭제할 반응 ID (소유자 무관) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/admin/reactions/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "반응을 삭제했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.reactions.manage`)이 없는 경우 |
| 403 | Forbidden | 반응 관리 권한의 범위(「본인만」 또는 「소유역할」) 밖 회원의 반응 (`auth.scope_denied`) |
| 404 | Not Found | ID 에 해당하는 반응이 없는 경우 (`반응을 찾을 수 없습니다.`) |
| 500 | Internal Server Error | 삭제 실패 (`반응 해제에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

- **권한**: `sirsoft-board.reactions.manage` (view 만 있으면 403)
- 권한 범위가 「본인만」·「소유역할」이면 그 범위 밖 회원의 반응은 **403** `auth.scope_denied` (1.2.1+)
- 소유자와 무관하게 삭제하고 대상의 `reaction_counts` 를 재집계합니다
- 없는 ID → 404

### DELETE /api/modules/sirsoft-board/admin/reactions/bulk
<!-- @generated:start:api.modules.sirsoft-board.admin.reactions.bulk-destroy -->
- **라우트명**: `api.modules.sirsoft-board.admin.reactions.bulk-destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReactionController@bulkDestroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.reactions.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1, max 100 | 삭제할 반응 ID 목록 (각 값은 1 이상 정수, 중복 불가, 존재하는 반응). 본문 JSON 으로 보내도 됩니다 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/admin/reactions/bulk?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `2` | 삭제한 반응 수 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "2개의 반응을 삭제했습니다.",
    "data": {
        "deleted_count": 2
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.reactions.manage`)이 없는 경우 |
| 403 | Forbidden | 범위 밖 반응이 하나라도 섞인 경우. 아무것도 지우지 않습니다 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | `ids` 가 비었거나 100개를 넘거나, 정수가 아니거나 중복되었거나 없는 반응 ID 가 있는 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 삭제 실패 (`반응 일괄 삭제에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

`/{id}` 보다 **먼저** 선언되어 `bulk` 가 id 로 잡히지 않습니다.
범위 밖 반응이 하나라도 섞이면 아무것도 지우지 않고 **403** `auth.scope_denied` 입니다(1.2.1+).

| 이름 | 타입 | 필수 | 허용값 |
| --- | --- | --- | --- |
| ids | array | 예 | 1~100개, 정수, 중복 불가, **존재하는 반응** |

회원용과 달리 여기서는 `exists` 를 겁니다 — 관리자는 전 회원 반응을 다루므로 탐색 통로가
되지 않고, 잘못된 ID 를 조용히 넘기면 "지웠다고 생각한 건"과 실제 건수가 어긋납니다.

응답: `data.deleted_count` (integer)

---

## 회원 상세 요약 (코어 응답에 주입)

`UserReactionInfoListener` 가 `core.user.filter_resource_data` 필터 훅(priority 31)을 구독해
**관리자 회원 상세 응답에만** 아래 블록을 병합합니다. 보는 관리자에게 `sirsoft-board.reactions.view` 권한이
없으면 싣지 않습니다 — 실으면 회원 상세가 건수를 그리고, 「이 회원 반응 목록 보기」는 403 화면으로 끝납니다.

```json
"board_reactions": { "count": 12, "post_count": 9, "comment_count": 3, "latest_reacted_at": "..." }
```

프로필(`/api/me`)에는 싣지 않습니다 — 마이페이지 「내 반응」 탭이 목록 API 로 더 정확한
정보를 내고, 프로필 응답은 모든 화면 부팅 경로에서 읽히므로 집계 비용을 얹지 않습니다.
회원 목록에는 이 훅이 발화하지 않아 N+1 이 구조적으로 없습니다.
