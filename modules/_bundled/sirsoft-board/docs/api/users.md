# Users API 레퍼런스

> **소유**: module `sirsoft-board` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Users 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-board/users/{user}/posts
<!-- @generated:start:api.modules.sirsoft-board.users.posts.index -->
- **라우트명**: `api.modules.sirsoft-board.users.posts.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostController@userPosts`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| user | path | string | 예 | — | 대상 user의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/users/a234c2b1-cde8-437f-b28b-23323be2b98d/posts HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `237` | 기본 키 (내부 식별자) |
| board_slug | string | `apidoc-sample-board` | 게시글이 속한 게시판의 슬러그(URL 식별자)입니다. 게시판 상세 링크 구성에 사용합니다. |
| board_name | string | `API 문서 샘플 게시판` | 게시글이 속한 게시판의 표시 이름입니다. 현재 로케일에 맞는 다국어 이름(`getLocalizedName()`)이 적용됩니다. |
| activity_type | string | `authored` | 활동 유형입니다. 공개 프로필의 게시글 목록은 작성글만 반환하므로 항상 `authored`(본인이 작성한 글)입니다. |
| activity_count | integer | `0` | activity 개수 (집계) |
| title | string | `API 문서 샘플 게시글` | 제목 |
| is_secret | boolean | `false` | secret 여부 |
| status | string | `published` | 계정 상태 (active: 활성, inactive: 비활성, blocked: 차단, withdrawn: 탈퇴) |
| view_count | integer | `43` | view 개수 (집계) |
| comment_count | integer | `0` | comment 개수 (집계) |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| content_plain | string | `API 레퍼런스 실측용 완전 샘플 게시글 본문입니다.` | 게시글 본문의 순수 텍스트입니다. HTML 모드 글은 태그를 제거한 평문으로 변환되며, 목록 미리보기용으로 사용합니다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시글 목록을 조회했습니다.",
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "board_slug": "apidoc-sample-board",
                "board_name": "API 문서 샘플 게시판",
                "activity_type": "authored",
                "activity_count": 0,
                "title": "API 문서 샘플 게시글",
                "is_secret": "{MASKED}",
                "status": "published",
                "view_count": 44,
                "comment_count": 0,
                "created_at": "2026-07-08 10:41:34",
                "created_at_formatted": "4시간 전",
                "content_plain": "API 레퍼런스 실측용 완전 샘플 게시글 본문입니다."
            }
        ],
        "first_page_url": "https://api.example.com/api/modules/sirsoft-board/users/a234c2b1-cde8-437f-b28b-23323be2b98d/posts?page=1",
        "from": 1,
        "last_page": 1,
        "last_page_url": "https://api.example.com/api/modules/sirsoft-board/users/a234c2b1-cde8-437f-b28b-23323be2b98d/posts?page=1",
        "links": [
            {
                "url": null,
                "label": "pagination.previous",
                "page": null,
                "active": false
            },
            {
                "url": "https://api.example.com/api/modules/sirsoft-board/users/a234c2b1-cde8-437f-b28b-23323be2b98d/posts?page=1",
                "label": "1",
                "page": 1,
                "active": true
            },
            {
                "url": null,
                "label": "pagination.next",
                "page": null,
                "active": false
            }
        ],
        "next_page_url": null,
        "path": "https://api.example.com/api/modules/sirsoft-board/users/a234c2b1-cde8-437f-b28b-23323be2b98d/posts",
        "per_page": 25,
        "prev_page_url": null,
        "to": 1,
        "total": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 사용자(`{user}` 는 회원 uuid 로 라우트 바인딩)의 공개 프로필 페이지에 표시할 게시글 목록을 모든 게시판에 걸쳐 반환합니다. 비밀글은 제외되며, `optional.sanctum` 이 적용되어 비로그인 상태에서도 조회할 수 있습니다. `per_page`(1~100, 기본 20)와 `sort`(latest 등) 쿼리 파라미터로 페이지네이션·정렬을 제어합니다.


### GET /api/modules/sirsoft-board/users/{user}/posts/stats
<!-- @generated:start:api.modules.sirsoft-board.users.posts.stats -->
- **라우트명**: `api.modules.sirsoft-board.users.posts.stats`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\PostController@userPostsStats`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| user | path | string | 예 | — | 대상 user의 식별자 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/users/a234c2b1-cde8-437f-b28b-23323be2b98d/posts/stats HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| posts_count | integer | `1` | posts 개수 (집계) |
| comments_count | integer | `1` | comments 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "성공적으로 처리되었습니다.",
    "data": {
        "posts_count": 1,
        "comments_count": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 공개 프로필 페이지 상단에 표시할 특정 사용자(`{user}` 는 회원 uuid)의 게시글·댓글 수 요약을 반환합니다. `status=published` 인 항목만 집계하며, `optional.sanctum` 이 적용되어 비로그인 상태에서도 조회할 수 있습니다.


