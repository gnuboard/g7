# Scraps API 레퍼런스 (sirsoft-board)

> **소유**: sirsoft-board 모듈, **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며,
> 블록 밖에 사람이 쓴 설명(상태 표, 설계 이유, 보충 표)은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 스크랩은 회원이 게시글을 개인 보관함에 담는 기능입니다
2. 회원용 3개 + 관리자용 3개 엔드포인트가 있습니다
3. 스크랩 레코드는 원글·게시판이 지워져도 남습니다 — 목록이 그것을 감추지 않는 것이 설계입니다
4. 검색 대상을 고를 수 있습니다 (회원 3갈래 / 관리자 6갈래)
5. 권한: sirsoft-board.scraps.view(조회) · .manage(삭제)
6. 처리 중 서버 오류는 500 으로 답합니다(입력 오류로 위장하지 않음 — 종전 400)
```

---

## 스크랩 행의 네 상태

목록은 아래 넷을 **모두** 보여 줍니다. 감추면 정리할 대상을 찾지 못하기 때문입니다.

| 상태 | 판정 | 화면 표기 |
| --- | --- | --- |
| 정상 | 게시판 살아 있고 `use_scrap` on, 원글 살아 있음 | 제목 클릭 → 원글 |
| 스크랩 중지 | 게시판의 `use_scrap` 가 꺼짐(또는 비활성) | 「스크랩 중지」 배지, 링크 없음 |
| 삭제된 원글 | 원글 소프트 삭제 | 「삭제된 게시글」 + 「삭제됨」, 제목 비노출 |
| 게시판 삭제됨 | `board_id` 가 가리키는 게시판이 사라짐 (`board: null`) | 「게시판 삭제됨」 배지 |

검색은 이 중 «스크랩 중지»·«삭제된 원글» 을 **대상에서 뺍니다** — 링크가 없는 행을 검색으로
찾아 내도 할 수 있는 일이 없습니다.

---

## 회원용

### GET /api/modules/sirsoft-board/me/scraps
<!-- @generated:start:api.modules.sirsoft-board.me.scraps.index -->
- **라우트명**: `api.modules.sirsoft-board.me.scraps.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostScrapController@index`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 페이지 번호 |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| search | query | string | 아니오 | max 255 | 검색어 |
| search_field | query | string | 아니오 | `title_content`(기본), `title`, `content` | 검색 대상. 값이 규칙에 없으면 기본값 `title_content` 로 처리 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/me/scraps?page=1&per_page=1&search=%EC%98%88%EC%8B%9C%EA%B0%92&search_field=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 스크랩 식별자 (삭제가 받는 값) |
| post_id | integer | `517` | 원글 식별자 |
| board_slug | string | `free` | 게시판 슬러그 (원글 이동 링크에 씁니다). 게시판이 삭제됐으면 null |
| board_name | string | `자유게시판` | 현재 로케일 문자열 (다국어 객체가 아닙니다 — 객체를 그대로 주면 카드 렌더가 깨집니다) |
| is_deleted | boolean | `false` | 원글 삭제 여부 |
| scrap_enabled | boolean | `false` | 그 게시판이 지금도 스크랩을 쓰는가 |
| board_deleted | boolean | `false` | 게시판이 지워졌는가 |
| title | string | `DBLCLICK-REAL` | 원글 제목. 삭제된 원글이면 노출하지 않습니다 |
| author_name | string | `관리자` | 작성자. 탈퇴 회원이면 익명 표기 |
| comments_count | integer | `0` | comments 개수 (집계) |
| scrapped_at | string | `09-14` | 스크랩 시각 (서버가 포맷한 상대 시간) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "스크랩 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "post_id": 517,
                "board_slug": "free",
                "board_name": "자유게시판",
                "is_deleted": false,
                "scrap_enabled": false,
                "board_deleted": false,
                "title": "DBLCLICK-REAL",
                "author_name": "관리자",
                "comments_count": 0,
                "scrapped_at": "09-14"
            }
        ],
        "pagination": {
            "total": 1,
            "count": 1,
            "per_page": 25,
            "current_page": 1,
            "last_page": 1,
            "from": 1,
            "to": 1,
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
| 500 | Internal Server Error | 조회 실패 (`스크랩 목록 조회에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

- **인증**: `auth:sanctum` (본인 것만)

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 페이지 번호 |
| search | query | string | 아니오 | max 255 | 검색어 |
| search_field | query | string | 아니오 | `title_content`(기본), `title`, `content` | 검색 대상. 값이 규칙에 없으면 기본값 `title_content` 로 처리 |

**응답 필드** (`data.data[]`)

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| id | integer | 스크랩 식별자 (삭제가 받는 값) |
| post_id | integer | 원글 식별자 |
| board_name | string | 현재 로케일 문자열 (다국어 객체가 아닙니다 — 객체를 그대로 주면 카드 렌더가 깨집니다) |
| title | string\|null | 원글 제목. 삭제된 원글이면 노출하지 않습니다 |
| author_name | string | 작성자. 탈퇴 회원이면 익명 표기 |
| comment_count | integer\|null | 댓글 수 |
| scrapped_at | string | 스크랩 시각 (서버가 포맷한 상대 시간) |
| is_deleted | boolean | 원글 삭제 여부 |
| scrap_enabled | boolean | 그 게시판이 지금도 스크랩을 쓰는가 |
| board_deleted | boolean | 게시판이 지워졌는가 |

마이페이지 「스크랩」 탭이 쓰는 목록이며 최근 스크랩순으로 정렬합니다. 대상은 언제나 인증된 본인(`Auth::id()`)입니다. 기본 `per_page` 는 20 입니다. 원글이 소프트 삭제된 스크랩도 목록에 포함되어(`is_deleted=true`) 회원이 직접 정리할 수 있습니다. 목록에는 게시판명, 작성자, 댓글 수, 스크랩일이 실리고 조회수는 싣지 않습니다.

- 삭제된 원글은 제목(`title`)과 댓글 수(`comments_count`)만 `null` 로 감추고, 게시판명과 작성자명은 계속 실어 어떤 글이 삭제됐는지 알 수 있게 합니다.
- `author_name` 은 회원 글이면 회원명, 비회원 글이면 작성자명입니다. 원글 자체를 찾지 못한 경우에만 `null` 이고, 탈퇴한 회원의 글은 다른 게시판 API 와 같은 익명화 문구로 바꿉니다.
- `scrapped_at` 은 표시용 문자열입니다. 게시판 게시글 목록과 같은 표시 설정(`display.date_display_format`)과 요청 로케일을 따라, 1시간 미만은 분 단위, 24시간 미만은 시간 단위, 그 이상은 설정에 따라 상대 표기(일, 개월, 년 전) 또는 표준 표기(MM-DD, YY-MM-DD)로 냅니다. 정렬은 이 표시값과 무관하게 실제 스크랩 생성 시각으로 합니다.
- `search` 가 있으면 원글이 삭제된 스크랩은 검색 대상에서 빠집니다(제목이 노출되지 않는 것과 같은 이유). 검색 중에는 스크랩이 중지된 게시판(비활성이거나 `use_scrap=false`)의 행도 빠집니다.

### DELETE /api/modules/sirsoft-board/me/scraps/{id}
<!-- @generated:start:api.modules.sirsoft-board.me.scraps.destroy -->
- **라우트명**: `api.modules.sirsoft-board.me.scraps.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostScrapController@destroy`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | 삭제할 스크랩 레코드 ID (목록 응답의 `id`) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/me/scraps/{id} HTTP/1.1
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
    "message": "스크랩을 해제했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | 본인 스크랩이 아니거나 없는 ID (`스크랩을 찾을 수 없습니다.`) |
| 500 | Internal Server Error | 삭제 실패 (`스크랩 삭제에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

본인 소유가 아니면 `404` — 남의 스크랩 id 를 넣어 존재 여부를 떠보지 못하게 합니다.

마이페이지 스크랩 목록의 ✕(확인 창을 거침)에서 호출하며, 스크랩 레코드 ID 로 직접 삭제합니다(찜의 마이페이지 삭제와 같은 방식). 원글이 남아 있으면 `scrap_count` 도 함께 줄이고, 원글이 이미 삭제됐으면 레코드만 지웁니다.

### DELETE /api/modules/sirsoft-board/me/scraps
<!-- @generated:start:api.modules.sirsoft-board.me.scraps.bulk-destroy -->
- **라우트명**: `api.modules.sirsoft-board.me.scraps.bulk-destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostScrapController@bulkDestroy`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1, max 100 | 해제할 스크랩 ID 목록 (각 값은 1 이상 정수, 중복 불가). 본문 JSON 으로 보내도 됩니다. 남의 ID 와 없는 ID 는 오류 없이 건너뜁니다 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/me/scraps?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `2` | 실제로 해제한 본인 스크랩 수 (건너뛴 ID 는 세지 않습니다) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "2개의 스크랩을 해제했습니다.",
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
| 500 | Internal Server Error | 삭제 실패 (`스크랩 삭제에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

선택 해제(일괄).

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | 1~100개 | 해제할 스크랩 식별자 |
| ids.* | body | integer | 예 | — | **`exists` 규칙을 두지 않습니다** — 두면 남의 id 를 넣어 존재 여부를 알아낼 수 있습니다. 소유 검사는 삭제 쿼리가 합니다 |

응답 `deleted_count` 는 **실제로 지워진 내 것의 수**입니다(남의 id 를 섞어 보내도 그것은 세지 않습니다).

---

## 관리자용

### GET /api/modules/sirsoft-board/admin/scraps
<!-- @generated:start:api.modules.sirsoft-board.admin.scraps.index -->
- **라우트명**: `api.modules.sirsoft-board.admin.scraps.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostScrapController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.scraps.view`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| filters | query | array | 아니오 | — | 추가 필터 조건 맵 (필드별 조건) |
| filters.0.field | query | string | 아니오 | `all`, `post_title`, `post_content`, `board_name`, `member`, `author_name` | 검색 대상. 생략하면 `all` 입니다 |
| filters.0.value | query | string | 아니오 | max 255 | 값 |
| filters.0.operator | query | string | 아니오 | — | 검색 연산자 자리. 관리자 목록 공통 형식으로 받기만 하고 이 목록은 값을 읽지 않습니다 |
| board_id | query | integer | 아니오 | min 1 | 게시판 한정 |
| user_id | query | integer | 아니오 | min 1 | 회원 한정 (ID) |
| user_uuid | query | string(uuid) | 아니오 | uuid | 회원 한정 (uuid). 없는 uuid 면 빈 결과 |
| scrapped_at_from | query | date | 아니오 | `Y-m-d` | 스크랩일 범위 시작일 (보는 사람의 시간대로 그날 하루를 포함) |
| scrapped_at_to | query | date | 아니오 | `Y-m-d`, `scrapped_at_from` 이상 | 스크랩일 범위 종료일 (보는 사람의 시간대로 그날 하루를 포함) |
| sort_order | query | string | 아니오 | `asc`, `desc` | 스크랩일 정렬 방향 (정렬 축이 하나라 `sort_by` 를 받지 않습니다) |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/scraps?page=1&per_page=1&filters=%EC%98%88%EC%8B%9C%EA%B0%92&filters.0.field=%EC%98%88%EC%8B%9C%EA%B0%92&filters.0.value=%EC%98%88%EC%8B%9C%EA%B0%92&filters.0.operator=%EC%98%88%EC%8B%9C%EA%B0%92&board_id=1&user_id=1&user_uuid=%EC%98%88%EC%8B%9C%EA%B0%92&scrapped_at_from=2026-01-01&scrapped_at_to=2026-01-01&sort_order=asc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `248` | 스크랩 식별자 |
| post_id | integer | `869` | 원글 식별자 |
| board_id | integer\|null | `null` | 게시판 식별자. 게시판이 지워졌으면 `null` |
| member.uuid | string(uuid) | `a2be5dfd-…` | 스크랩한 회원 |
| member.name | string | `스크랩회원A` | 회원 이름 (탈퇴면 익명) |
| member.email | string\|null | `e2e-scrap-a@example.test` | 관리자 응답에만 실립니다 |
| member | object | `{"uuid":"a2edf080-d821-4faf-96e9-6ac094fde724","name":"qa…` | 스크랩한 회원 `{uuid,name,email,is_withdrawn}`. 탈퇴 회원 이름은 익명 표기입니다 |
| board | object\|null | `null` | 게시판. 지워졌으면 `null` |
| board.slug / name | string | `e2e-scrap-notice` / `공지사항(E2E)` | 게시판 식별자와 이름 |
| board.is_active | boolean | `true` | 게시판 활성 여부 |
| board.use_scrap | boolean | `false` | **지금도 스크랩을 쓰는가** — 화면의 「스크랩 꺼짐」 배지가 읽습니다 |
| post_title | string | `알파 제목 — 공지` | 원글 제목. 관리자 목록은 **삭제된 원글의 제목도 보여 줍니다**(회원 목록과 다릅니다 — 운영자는 무엇을 정리하는지 알아야 합니다) |
| post_status | string | `published` | 원글 상태 |
| post_status_label | string | `게시됨` | 상태 표시 문자열 |
| is_deleted | boolean | `false` | 원글 삭제 여부 |
| author_name | string | `스크랩회원B` | 원글 작성자 (스크랩한 회원과 다를 수 있습니다) |
| scrapped_at | string | `방금 전` | 스크랩 시각 — 모듈 표시 설정(`display.date_display_format`)을 따르므로 「방금 전」 같은 상대 표기일 수 있습니다 |
| created_at | string | `2026-09-14 15:05:31` | 스크랩 시각의 절대 표기(초 단위). 저장은 UTC 이고 이 값은 회원 표시 시간대로 환산됩니다 — 관리 목록은 선후를 가려야 하므로 이 필드를 씁니다 |
| abilities.can_delete | boolean | `true` | 삭제 권한 — 화면의 체크 칸이 이 값으로 열립니다 |
| abilities | object | `{"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

<!-- @probed -->

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "스크랩 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 718,
                "post_id": 3224,
                "board_id": 1004,
                "member": {
                    "uuid": "a2edf080-d821-4faf-96e9-6ac094fde724",
                    "name": "qa736_pc_m1",
                    "email": "qa736_pc_m1@example.com",
                    "is_withdrawn": false
                },
                "board": {
                    "slug": "qa736-pc-main",
                    "name": "qa736 PC 검색",
                    "is_active": true,
                    "use_scrap": true
                },
                "post_title": "평범 둘째글",
                "post_status": "published",
                "post_status_label": "게시됨",
                "is_deleted": false,
                "author_name": "qa736_pc_m1",
                "scrapped_at": "10-08",
                "created_at": "2026-10-08 11:57:39",
                "abilities": {
                    "can_delete": true
                }
            },
            {
                "id": 717,
                "post_id": 3223,
                "board_id": 1004,
                "member": {
                    "uuid": "a2edf080-d821-4faf-96e9-6ac094fde724",
                    "name": "qa736_pc_m1",
                    "email": "qa736_pc_m1@example.com",
                    "is_withdrawn": false
                },
                "board": {
                    "slug": "qa736-pc-main",
                    "name": "qa736 PC 검색",
                    "is_active": true,
                    "use_scrap": true
                },
                "post_title": "평범 제목글",
                "post_status": "published",
                "post_status_label": "게시됨",
                "is_deleted": false,
                "author_name": "qa736_pc_m1",
                "scrapped_at": "10-08",
                "created_at": "2026-10-08 11:57:39",
                "abilities": {
                    "can_delete": true
                }
            },
            "... (총 11건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 25,
            "from": 1,
            "to": 11,
            "has_more_pages": false,
            "last_page": 1,
            "total": 11,
            "total_relation": "exact",
            "total_is_exact": true,
            "result_cap": 10000
        },
        "boards": [
            {
                "id": 1022,
                "slug": "qa720-s5-a",
                "name": "QA720 S5 게시판 & | = $& {{x}}"
            },
            {
                "id": 1008,
                "slug": "product-inquiry",
                "name": "상품 문의"
            },
            {
                "id": 1006,
                "slug": "qa736-pi-main",
                "name": "qa736 PI 메인"
            },
            {
                "id": 1005,
                "slug": "qa736-pg-a",
                "name": "qa736 PG 가"
            },
            {
                "id": 1004,
                "slug": "qa736-pc-main",
                "name": "qa736 PC 검색"
            },
            {
                "id": 997,
                "slug": "qa736-pb-member",
                "name": "qa736 PB 회원전용"
            },
            {
                "id": 996,
                "slug": "qa736-pb-main",
                "name": "qa736 PB 메인"
            },
            {
                "id": 984,
                "slug": "qa736-rf-main",
                "name": "qa736 RF 첨부"
            },
            {
                "id": 974,
                "slug": "qa736-k4-noti",
                "name": "qa736 K4 알림"
            },
            {
                "id": 972,
                "slug": "qa736-k4-guest",
                "name": "qa736 K4 비회원"
            },
            {
                "id": 971,
                "slug": "qa736-k4-member",
                "name": "qa736 K4 회원전용"
            },
            {
                "id": 970,
                "slug": "qa736-k4-secreq",
                "name": "qa736 K4 비밀글필수"
            },
            {
                "id": 969,
                "slug": "qa736-k4-nosecret",
                "name": "qa736 K4 비밀글없음"
            },
            {
                "id": 968,
                "slug": "qa736-k4-main",
                "name": "qa736 K4 메인"
            },
            {
                "id": 967,
                "slug": "qa736-k3t-nofile",
                "name": "qa736 K3T 파일없음2"
            },
            {
                "id": 966,
                "slug": "qa736-k3t-main",
                "name": "qa736 K3T 메인"
            },
            {
                "id": 965,
                "slug": "qa736-k3r-nofile",
                "name": "qa736 K3R 파일없음2"
            },
            {
                "id": 964,
                "slug": "qa736-k3r-main",
                "name": "qa736 K3R 메인"
            },
            {
                "id": 963,
                "slug": "qa736-k3-nofile",
                "name": "qa736 K3 파일없음2"
            },
            {
                "id": 962,
                "slug": "qa736-k3-main",
                "name": "qa736 K3 메인"
            },
            {
                "id": 960,
                "slug": "qa736-k2-noscrap",
                "name": "qa736 K2 스크랩끔예정"
            },
            {
                "id": 959,
                "slug": "qa736-k2-on",
                "name": "qa736 K2 스크랩신고"
            },
            {
                "id": 956,
                "slug": "qa736-k1-off",
                "name": "qa736 K1 반응끔"
            },
            {
                "id": 955,
                "slug": "qa736-k1-postonly",
                "name": "qa736 K1 글만반응"
            },
            {
                "id": 954,
                "slug": "qa736-k1-on",
                "name": "qa736 K1 반응켬"
            },
            {
                "id": 868,
                "slug": "repleak-1789624175716-noacc",
                "name": "무접근신고"
            },
            {
                "id": 867,
                "slug": "repleak-1789624175716",
                "name": "신고유출테스트"
            },
            {
                "id": 866,
                "slug": "formsec-1789624116697",
                "name": "폼비밀노출"
            },
            {
                "id": 865,
                "slug": "sectest-1789624059256",
                "name": "비밀댓글테스트"
            },
            {
                "id": 864,
                "slug": "mo-1789623990067",
                "name": "회원전용게시판"
            },
            {
                "id": 8,
                "slug": "archive",
                "name": "아카이브"
            },
            {
                "id": 7,
                "slug": "inquiry",
                "name": "1:1 문의"
            },
            {
                "id": 6,
                "slug": "members",
                "name": "회원전용"
            },
            {
                "id": 5,
                "slug": "qna",
                "name": "Q&A"
            },
            {
                "id": 4,
                "slug": "event",
                "name": "이벤트"
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
            }
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
| 403 | Forbidden | 요구 권한(`sirsoft-board.scraps.view`)이 없는 경우 (`스크랩 관리 권한이 없습니다.`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 조회 실패 (`스크랩 목록 조회에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

- **인증/권한**: `auth:sanctum` + `permission:admin,sirsoft-board.scraps.view`

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page / per_page | query | integer | 아니오 | per_page max 100 | 페이지네이션 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 스크랩일 정렬 방향 (정렬 축이 하나라 `sort_by` 를 받지 않습니다) |
| board_id | query | integer | 아니오 | — | 게시판 한정 |
| user_id | query | integer | 아니오 | — | 회원 한정 (ID) |
| user_uuid | query | string(uuid) | 아니오 | uuid | 회원 한정 (uuid). 없는 uuid 면 빈 결과 |
| scrapped_at_from / _to | query | date | 아니오 | `Y-m-d` | 스크랩일 범위 (보는 사람의 시간대 하루로 해석) |
| filters[0][field] | query | string | 아니오 | `all`, `post_title`, `post_content`, `board_name`, `member`, `author_name` | 검색 대상 |
| filters[0][value] | query | string | 아니오 | max 255 | 검색어 |
| filters[0][operator] | query | string | 아니오 | `like` | 연산자 |

**응답 필드** (`data.data[]`)

| 필드 | 타입 | 실측 예시값 | 용도 |
| --- | --- | --- | --- |
| id | integer | `248` | 스크랩 식별자 |
| post_id | integer | `869` | 원글 식별자 |
| board_id | integer\|null | `null` | 게시판 식별자. 게시판이 지워졌으면 `null` |
| member.uuid | string(uuid) | `a2be5dfd-…` | 스크랩한 회원 |
| member.name | string | `스크랩회원A` | 회원 이름 (탈퇴면 익명) |
| member.email | string\|null | `e2e-scrap-a@example.test` | 관리자 응답에만 실립니다 |
| board | object\|null | `null` | 게시판. 지워졌으면 `null` |
| board.slug / name | string | `e2e-scrap-notice` / `공지사항(E2E)` | 게시판 식별자·이름 |
| board.is_active | boolean | `true` | 게시판 활성 여부 |
| board.use_scrap | boolean | `false` | **지금도 스크랩을 쓰는가** — 화면의 「스크랩 꺼짐」 배지가 읽습니다 |
| post_title | string | `알파 제목 — 공지` | 원글 제목. 관리자 목록은 **삭제된 원글의 제목도 보여 줍니다**(회원 목록과 다릅니다 — 운영자는 무엇을 정리하는지 알아야 합니다) |
| post_status | string | `published` | 원글 상태 |
| post_status_label | string | `게시됨` | 상태 표시 문자열 |
| is_deleted | boolean | `false` | 원글 삭제 여부 |
| author_name | string | `스크랩회원B` | 원글 작성자 (스크랩한 회원과 다를 수 있습니다) |
| scrapped_at | string | `방금 전` | 스크랩 시각 — 모듈 표시 설정(`display.date_display_format`)을 따르므로 「방금 전」 같은 상대 표기일 수 있습니다 |
| created_at | string | `2026-09-14 15:05:31` | 스크랩 시각의 절대 표기(초 단위). 저장은 UTC 이고 이 값은 회원 표시 시간대로 환산됩니다 — 관리 목록은 선후를 가려야 하므로 이 필드를 씁니다 |
| abilities.can_delete | boolean | `true` | 삭제 권한 — 화면의 체크 칸이 이 값으로 열립니다 |

**응답 메타**: `data.abilities{can_view,can_manage}` + `data.boards[]`(게시판 필터 선택지 — `id`·`slug`·`name`, 비활성 게시판 포함, 최근 생성 순) + `data.pagination`.
게시판 선택지를 목록 응답에 싣는 이유는 게시판 목록 API 가 게시판 관리 권한이 있어야 열려,
`scraps.view` 만 가진 매니저가 그 API 로는 게시판을 고를 수 없기 때문입니다.
권한 범위가 「본인만」·「소유역할」이면 그 범위의 글(항목)만 담깁니다(1.2.1+).

### DELETE /api/modules/sirsoft-board/admin/scraps/{id}
<!-- @generated:start:api.modules.sirsoft-board.admin.scraps.destroy -->
- **라우트명**: `api.modules.sirsoft-board.admin.scraps.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostScrapController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.scraps.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | 삭제할 스크랩 레코드 ID (소유자 무관) |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/admin/scraps/{id} HTTP/1.1
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
    "message": "스크랩을 삭제했습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.scraps.manage`)이 없는 경우 |
| 403 | Forbidden | 스크랩 관리 권한의 범위(「본인만」 또는 「소유역할」) 밖 회원의 스크랩 (`auth.scope_denied`) |
| 404 | Not Found | ID 에 해당하는 스크랩이 없는 경우 (`스크랩을 찾을 수 없습니다.`) |
| 500 | Internal Server Error | 삭제 실패 (`스크랩 삭제에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

- **권한**: `sirsoft-board.scraps.manage`
- 권한 범위가 「본인만」·「소유역할」이면 그 범위 밖 회원의 스크랩은 **403** `auth.scope_denied` (1.2.1+)

삭제하면 원글의 `scrap_count` 가 줄고 훅 `after_toggle` 이 **스크랩 소유자 id** 로 발화합니다.
활동 로그의 `user_id` 는 그와 별개로 **로그인한 관리자**입니다 — 로그는 «누가 했나» 를 남기고,
훅 인자는 «누구 것이 바뀌었나» 를 알립니다.

### DELETE /api/modules/sirsoft-board/admin/scraps/bulk
<!-- @generated:start:api.modules.sirsoft-board.admin.scraps.bulk-destroy -->
- **라우트명**: `api.modules.sirsoft-board.admin.scraps.bulk-destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\PostScrapController@bulkDestroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.scraps.manage`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1, max 100 | 삭제할 스크랩 ID 목록 (각 값은 정수, 중복 불가, 존재하는 스크랩). 본문 JSON 으로 보내도 됩니다 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/admin/scraps/bulk?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `2` | 삭제한 스크랩 수 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "2개의 스크랩을 삭제했습니다.",
    "data": {
        "deleted_count": 2
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.scraps.manage`)이 없는 경우 |
| 403 | Forbidden | 범위 밖 스크랩이 하나라도 섞인 경우. 아무것도 지우지 않습니다 (`auth.scope_denied`) |
| 422 | Unprocessable Entity | `ids` 가 비었거나 100개를 넘거나, 정수가 아니거나 중복되었거나 없는 스크랩 ID 가 있는 경우 (`error.errors` 에 필드별 메시지) |
| 500 | Internal Server Error | 삭제 실패 (`스크랩 일괄 삭제에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

- **권한**: `sirsoft-board.scraps.manage`
- 라우트는 `{id}` 보다 **먼저** 선언되어 있습니다 — 그러지 않으면 `bulk` 가 id 로 잡힙니다.
- 범위 밖 스크랩이 하나라도 섞이면 아무것도 지우지 않고 **403** `auth.scope_denied` (1.2.1+)

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | 1~100개 | 삭제할 스크랩 식별자 |
| ids.* | body | integer | 예 | 존재하는 스크랩 | 관리자 경로는 `exists` 를 둡니다 — 회원 경로와 달리 열람 권한이 이미 전제되어 있어 존재 여부가 비밀이 아닙니다 |

---

## 회원 상세 응답의 요약

`GET /api/admin/users/{uuid}` 의 관리자 응답에 `board_scraps` 가 실립니다
(`UserScrapInfoListener` 가 관리자 요청에만 주입 — 프로필 응답에는 없습니다). 보는 관리자에게
`sirsoft-board.scraps.view` 권한이 없으면 싣지 않습니다 — 실으면 회원 상세가 건수를 그리고,
「이 회원 스크랩 목록 보기」는 403 화면으로 끝납니다.

| 필드 | 타입 | 용도 |
| --- | --- | --- |
| board_scraps.count | integer | 스크랩 총 건수 |
| board_scraps.latest_scrapped_at | string\|null | 최근 스크랩 시각 |
