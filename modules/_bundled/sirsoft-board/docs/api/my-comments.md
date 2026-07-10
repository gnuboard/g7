# My Comments API 레퍼런스

> **소유**: module `sirsoft-board` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 My Comments 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-board/me/my-comments
<!-- @generated:start:api.modules.sirsoft-board.me.my-comments.index -->
- **라우트명**: `api.modules.sirsoft-board.me.my-comments.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\UserActivityController@myComments`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/me/my-comments HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `760` | 기본 키 (내부 식별자) |
| board_slug | string | `apidoc-sample-board` | 댓글이 달린 게시글이 속한 게시판의 슬러그(URL 식별자)입니다. 게시판 링크 구성에 사용합니다. |
| board_name | string | `API 문서 샘플 게시판` | 댓글이 달린 게시글이 속한 게시판의 표시 이름입니다. 현재 로케일에 맞는 다국어 이름(`getLocalizedName()`)이 적용됩니다. |
| post_title | string | `API 문서 샘플 게시글` | 댓글이 달린 원 게시글의 제목입니다. 목록에서 어느 글에 남긴 댓글인지 식별하는 데 사용합니다. |
| post_id_val | integer | `237` | 댓글이 달린 원 게시글의 ID입니다. 원 게시글 상세로 이동하는 링크 구성에 사용합니다. |
| content | string | `API 문서 샘플 댓글입니다.` | 본문 내용 |
| created_at | string | `2026-07-07 09:34:50` | 생성 일시 |
| created_at_formatted | string | `4시간 전` | `created_at` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "내 활동 게시글을 조회했습니다.",
    "data": {
        "current_page": 1,
        "data": [
            {
                "id": 1,
                "board_slug": "apidoc-sample-board",
                "board_name": "API 문서 샘플 게시판",
                "post_title": "API 문서 샘플 게시글",
                "post_id_val": 1,
                "content": "API 문서 샘플 댓글입니다.",
                "created_at": "2026-07-08 10:41:34",
                "created_at_formatted": "4시간 전"
            }
        ],
        "first_page_url": "https://api.example.com/api/modules/sirsoft-board/me/my-comments?page=1",
        "from": 1,
        "last_page": 1,
        "last_page_url": "https://api.example.com/api/modules/sirsoft-board/me/my-comments?page=1",
        "links": [
            {
                "url": null,
                "label": "pagination.previous",
                "page": null,
                "active": false
            },
            {
                "url": "https://api.example.com/api/modules/sirsoft-board/me/my-comments?page=1",
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
        "path": "https://api.example.com/api/modules/sirsoft-board/me/my-comments",
        "per_page": 25,
        "prev_page_url": null,
        "to": 1,
        "total": 1,
        "query": {
            "board_slug": "",
            "search": "",
            "sort": "latest"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 로그인한 회원 본인이 작성한 댓글 목록을 마이페이지에 표시하기 위해 반환하며, 각 항목에 댓글 내용과 함께 원 게시글 제목·게시판 정보를 포함합니다. `auth:sanctum` 인증이 필요한 회원 전용 엔드포인트로, 대상은 항상 인증된 본인(`Auth::id()`)입니다. `board_slug`·`search`·`sort`(기본 latest) 필터와 `per_page`(1~100, 기본 20) 페이지네이션을 지원합니다.


