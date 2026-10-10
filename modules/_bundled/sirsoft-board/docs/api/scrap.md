# Scrap API 레퍼런스

> **소유**: module `sirsoft-board` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.
>
> **이 문서는 회원용 3종(토글·목록·개별삭제)만 다룹니다.** 회원 일괄 해제(`DELETE /me/scraps`)와
> 관리자 3종(`GET/DELETE /admin/scraps`, `DELETE /admin/scraps/bulk`)을 포함한 전체 레퍼런스는
> [scraps.md](scraps.md) 에 있습니다 (이슈 #524).

---

## TL;DR (5초 요약)

```text
1. 게시글 스크랩(북마크) 엔드포인트 3종 — 토글·목록·개별삭제
2. 토글: 상세 버튼 전용, 있으면 해제·없으면 등록 (added 로 결과 구분)
3. 목록: 마이페이지 스크랩 탭, 최근 스크랩순, 삭제된 원글도 포함(is_deleted)
4. 개별삭제: 스크랩 레코드 ID 기준, 본인 소유만
5. 모두 auth:sanctum 회원 전용
```

---

## POST /api/modules/sirsoft-board/boards/{slug}/posts/{postId}/scrap/toggle

- **라우트명**: `api.modules.sirsoft-board.boards.posts.scrap.toggle`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostScrapController@toggle`
- **인증/권한**: `auth:sanctum`

**경로 파라미터**

| 파라미터 | 타입 | 예시 | 용도/설명 |
| --- | --- | --- | --- |
| slug | string | `free` | 게시판 슬러그(URL 식별자). 스크랩 대상 게시글이 속한 게시판. |
| postId | integer | `237` | 스크랩할 게시글 ID. 해당 slug 게시판 소속이어야 하며, 아니면 404. |

**요청 본문**

_요청 본문 없음._ (토글은 URL 파라미터만 사용)

**요청 예시**

```http
POST /api/modules/sirsoft-board/boards/free/posts/237/scrap/toggle HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| added | boolean | `true` | `true`=스크랩 등록됨, `false`=기존 스크랩 해제됨. 상세 버튼의 스크랩/스크랩됨 상태 전환에 사용. |
| scrap_count | integer | `13` | 토글 직후 갱신된 스크랩 수. 화면은 이 값으로 수치를 확정해 별도 재조회 없이 즉시 반영한다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글을 스크랩했습니다.",
    "data": {
        "added": true,
        "scrap_count": 13
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 게시판의 `use_scrap` 설정이 꺼져 있는 경우 |
| 404 | Not Found | `postId` 가 `slug` 게시판 소속이 아니거나 존재하지 않는 경우 (교차 접근 차단) |
| 500 | Server Error | 처리 중 서버 오류 (입력 오류가 아니다 — 종전 400) |

**설명** 게시글 상세의 스크랩 버튼 전용 엔드포인트로, 이미 스크랩한 글이면 해제하고 아니면 등록하는 토글로 동작합니다(찜 기능과 동일 패턴). 등록/해제에 따라 `board_posts.scrap_count` 를 원자적으로 증감하며, 레코드 변경과 카운트 증감은 한 트랜잭션으로 묶입니다. 본인 글도 스크랩할 수 있습니다(개인 북마크). `postId` 가 실제로 `slug` 게시판 소속인지 검증해 다른 게시판 게시글을 스크랩하려는 교차 접근을 404 로 차단합니다. 7.1.0 부터 그 게시판의 글 읽기 권한(`sirsoft-board.{slug}.posts.read`)이 없으면 403 입니다.

---

## GET /api/modules/sirsoft-board/me/scraps

- **라우트명**: `api.modules.sirsoft-board.me.scraps.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostScrapController@index`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 파라미터 | 타입 | 예시 | 용도/설명 |
| --- | --- | --- | --- |
| page | integer | `1` | 페이지 번호 (1 이상). 생략 시 1. |
| per_page | integer | `20` | 페이지당 개수 (1~100). 생략 시 20. |
| search | string | `책 추천` | 검색어 (최대 255자, 생략 가능). 매칭 대상은 `search_field` 가 정한다. 원글이 삭제된 스크랩은 응답에서 title 자체가 노출되지 않으므로 검색 대상에서도 제외된다. 검색 중에는 스크랩이 중지된 게시판(비활성·`use_scrap=false`)의 행도 제외된다. |
| search_field | string | `content` | 검색 대상 (`title_content` 기본 · `title` · `content`). 그 밖의 값은 기본값 `title_content` 로 처리. 상세는 [scraps.md](scraps.md). |

**요청 예시**

```http
GET /api/modules/sirsoft-board/me/scraps HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data.data[]` 배열 항목)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 스크랩 레코드 ID. 마이페이지 개별 삭제 시 이 ID 를 사용. |
| post_id | integer | `237` | 스크랩한 원 게시글 ID. 원글 상세 이동 링크에 사용. |
| board_slug | string | `free` | 스크랩 당시 게시판 슬러그. 원글/게시판이 사라져도 목록 이동에 사용. |
| board_name | string | `자유게시판` | 게시판 표시 이름. |
| is_deleted | boolean | `false` | 원글 삭제 여부. `true`=삭제된 게시글(제목·댓글수 미노출, "삭제된 게시글" 표기). |
| title | string\|null | `책 추천 모음` | 원글 제목. 삭제된 원글이면 null. |
| author_name | string\|null | `hong**` | 원글 작성자명(회원명 또는 비회원 작성자명). 원글 자체가 없는 경우(post 관계 미해결)에만 null — 삭제된 원글도 "어떤 글이 삭제됐는지" 맥락 유지를 위해 노출한다. 탈퇴한 회원의 글은 다른 게시판 API와 동일하게 익명화 문구로 대체된다. |
| comments_count | integer\|null | `12` | 원글 댓글 수. 삭제된 원글이면 null. |
| scrapped_at | string | `3시간 전` | 스크랩한 일시(표시용). 게시판 게시글 목록·상세와 동일하게 게시판 설정(`display.date_display_format`)과 요청 로케일을 따라 포맷된다 — 1시간 미만은 분 단위, 24시간 미만은 시간 단위, 그 이상은 설정에 따라 상대(일/개월/년 전) 또는 표준(MM-DD·YY-MM-DD) 표기. 정렬 기준은 실제 스크랩 생성 시각(정렬 자체는 이 표시값과 무관하게 서버에서 처리). |

**응답 예시**

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
                "id": 13,
                "post_id": 240,
                "board_slug": "free",
                "board_name": "자유게시판",
                "is_deleted": true,
                "title": null,
                "author_name": "hong**",
                "comments_count": null,
                "scrapped_at": "22시간 전"
            },
            {
                "id": 12,
                "post_id": 237,
                "board_slug": "free",
                "board_name": "자유게시판",
                "is_deleted": false,
                "title": "책 추천 모음",
                "author_name": "hong**",
                "comments_count": 12,
                "scrapped_at": "3시간 전"
            }
        ],
        "pagination": {
            "total": 2,
            "count": 2,
            "per_page": 20,
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
| 500 | Server Error | 처리 중 서버 오류 (입력 오류가 아니다 — 종전 400) |

**설명** 로그인 회원의 스크랩 목록을 마이페이지 "스크랩" 탭에 표시하기 위해 반환하며, 최근 스크랩순으로 정렬됩니다. 원글이 소프트 삭제된 스크랩도 목록에 포함되어(`is_deleted=true`) 유저가 직접 정리할 수 있습니다. 목록에는 게시판명·작성자·댓글수·스크랩일이 표시되며 조회수는 제외됩니다. 삭제된 원글은 제목·댓글수만 미노출하고 게시판명·작성자명은 계속 노출해 "어떤 글이 삭제됐는지" 맥락을 유지합니다. `search` 파라미터로 검색할 수 있고 대상은 `search_field` 가 정합니다 — 기본값 `title_content`(제목 또는 내용) · `title` · `content`. 삭제된 원글의 스크랩은 제목이 노출되지 않는 것과 동일하게 검색으로도 찾을 수 없습니다. 대상은 항상 인증된 본인(`Auth::id()`)입니다.

---

## DELETE /api/modules/sirsoft-board/me/scraps/{id}

- **라우트명**: `api.modules.sirsoft-board.me.scraps.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostScrapController@destroy`
- **인증/권한**: `auth:sanctum`

**경로 파라미터**

| 파라미터 | 타입 | 예시 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 삭제할 스크랩 레코드 ID. 본인 소유가 아니면 404. |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/me/scraps/12 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "스크랩을 해제했습니다."
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 404 | Not Found | 해당 ID 의 스크랩이 없거나 본인 소유가 아닌 경우 |
| 500 | Server Error | 처리 중 서버 오류 (입력 오류가 아니다 — 종전 400) |

**설명** 마이페이지 스크랩 목록의 ✕(확인 모달 경유)에서 호출하는 개별 삭제 엔드포인트로, 스크랩 레코드 ID 로 직접 삭제합니다(찜의 마이페이지 삭제와 동일 패턴). 원글이 남아 있으면 `scrap_count` 도 함께 감소하고, 원글이 이미 삭제됐으면 레코드만 제거합니다. 본인 소유 레코드만 삭제할 수 있으며, 타인 레코드 삭제 시도는 404 로 차단됩니다.
