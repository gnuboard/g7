# Dashboard API 레퍼런스

> **소유**: module `sirsoft-board` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Dashboard 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-board/admin/dashboard/overview
<!-- @generated:start:api.modules.sirsoft-board.admin.dashboard.overview -->
- **라우트명**: `api.modules.sirsoft-board.admin.dashboard.overview`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\DashboardController@overview`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/dashboard/overview HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| today_posts | integer | `0` | 오늘 등록된 새 게시글 수입니다. board_stats 집계 테이블의 오늘 행에서 읽으며, 행이 없으면 0 을 반환합니다(최대 1시간 지연). |
| today_comments | integer | `0` | 오늘 등록된 새 댓글 수입니다. board_stats 집계 테이블의 오늘 행에서 읽으며, 행이 없으면 0 을 반환합니다(최대 1시간 지연). |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-board::messages.dashboard.fetch_success",
    "data": {
        "today_posts": 0,
        "today_comments": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 관리자 대시보드 위젯에 표시할 오늘의 게시판 현황을 반환합니다. `auth:sanctum` 인증이 필요하며(대시보드 진입은 코어 `core.dashboard.read` 가드로 보호), 오늘 등록된 새 글 수(today_posts)와 새 댓글 수(today_comments)를 집계하여 반환합니다.


### GET /api/modules/sirsoft-board/admin/dashboard/pending-reports
<!-- @generated:start:api.modules.sirsoft-board.admin.dashboard.pending-reports -->
- **라우트명**: `api.modules.sirsoft-board.admin.dashboard.pending-reports`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\DashboardController@pendingReports`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| limit | query | integer | 아니오 | min 1, max 50 | 반환할 최대 항목 수 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/dashboard/pending-reports?limit=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| items | array | `[{"id":7,"board_slug":"qna","board_name":"Q&A","target_ty…` | 전체 게시판을 가로질러 조회한 미처리 신고 항목 목록입니다. 각 항목은 신고 대상 게시판(board_slug/board_name), 대상 종류(target_type), 대상 제목/발췌(target_title/target_excerpt), 상태, 신고 시각을 포함합니다. `limit` 개까지 반환됩니다. |
| total | integer | `16` | 전체 개수 (집계) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-board::messages.dashboard.fetch_success",
    "data": {
        "items": [
            {
                "id": 1,
                "board_slug": "apidoc-sample-board",
                "board_name": "API 문서 샘플 게시판",
                "target_type": "post",
                "target_type_label": "게시글",
                "target_post_id": 1,
                "target_title": "API 문서 샘플 게시글",
                "target_excerpt": null,
                "status": "pending",
                "status_label": "접수",
                "author_name": "API 문서 샘플 사용자",
                "last_reported_at": "4시간 전"
            }
        ],
        "total": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 전체 게시판의 미처리 신고 목록과 총 건수를 대시보드 위젯에 표시하기 위해 반환합니다. `auth:sanctum` 인증이 필요하며(대시보드 진입은 코어 `core.dashboard.read` 가드로 보호), `limit`(1~50, 기본 5)으로 표시 건수를 제어합니다. 응답은 미처리 신고 항목(items)과 전체 미처리 건수(total)를 포함합니다.


### GET /api/modules/sirsoft-board/admin/dashboard/post-graph
<!-- @generated:start:api.modules.sirsoft-board.admin.dashboard.post-graph -->
- **라우트명**: `api.modules.sirsoft-board.admin.dashboard.post-graph`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\DashboardController@postGraph`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/dashboard/post-graph HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| days | array | `[{"date":"2026-07-01","post_count":0,"comment_count":0},{…` | 최근 7일간 일자별 집계 막대 배열입니다. 각 원소는 날짜(date)와 해당 일의 게시글 수(post_count)/댓글 수(comment_count)를 담으며, 집계 행이 없는 날은 0 으로 채워집니다. |
| total_posts | integer | `0` | 이번 7일 기간의 게시글 합계입니다. days 의 post_count 를 합산한 값입니다. |
| total_comments | integer | `0` | 이번 7일 기간의 댓글 합계입니다. days 의 comment_count 를 합산한 값입니다. |
| posts_change | null | `null` | 직전 동일 7일 기간 대비 게시글 증감율(%)입니다. 소수점 첫째 자리까지 계산하며, 직전 기간 합이 0 이면 비교 기준이 없으므로 null 을 반환합니다(화면에서 '—' 폴백). |
| comments_change | null | `null` | 직전 동일 7일 기간 대비 댓글 증감율(%)입니다. 소수점 첫째 자리까지 계산하며, 직전 기간 합이 0 이면 비교 기준이 없으므로 null 을 반환합니다(화면에서 '—' 폴백). |
| updated_at | null | `null` | 최종 수정 일시 |
| updated_at_display | string | `` | 집계 행의 최종 갱신 시각(updated_at)을 모듈 날짜 표시 형식(display.date_display_format 설정)으로 포맷한 표시용 문자열입니다. 집계 행이 없으면 빈 문자열입니다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-board::messages.dashboard.fetch_success",
    "data": {
        "days": [
            {
                "date": "2026-07-02",
                "post_count": 0,
                "comment_count": 0
            },
            {
                "date": "2026-07-03",
                "post_count": 0,
                "comment_count": 0
            },
            {
                "date": "2026-07-04",
                "post_count": 0,
                "comment_count": 0
            },
            {
                "date": "2026-07-05",
                "post_count": 0,
                "comment_count": 0
            },
            {
                "date": "2026-07-06",
                "post_count": 0,
                "comment_count": 0
            },
            {
                "date": "2026-07-07",
                "post_count": 0,
                "comment_count": 0
            },
            {
                "date": "2026-07-08",
                "post_count": 0,
                "comment_count": 0
            }
        ],
        "total_posts": 0,
        "total_comments": 0,
        "posts_change": null,
        "comments_change": null,
        "updated_at": null,
        "updated_at_display": ""
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 대시보드 위젯의 추세 그래프에 표시할 최근 7일간 게시글/댓글 추이를 반환합니다. `auth:sanctum` 인증이 필요하며(대시보드 진입은 코어 `core.dashboard.read` 가드로 보호), 일자별 막대(days), 기간 합계(total_posts/total_comments), 이전 기간 대비 변화율(posts_change/comments_change), 갱신 시각(updated_at)을 포함합니다.


### GET /api/modules/sirsoft-board/admin/dashboard/recent-posts
<!-- @generated:start:api.modules.sirsoft-board.admin.dashboard.recent-posts -->
- **라우트명**: `api.modules.sirsoft-board.admin.dashboard.recent-posts`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\DashboardController@recentPosts`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| limit | query | integer | 아니오 | min 1, max 50 | 반환할 최대 항목 수 |

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/dashboard/recent-posts?limit=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `237` | 기본 키 (내부 식별자) |
| board_slug | string | `apidoc-sample-board` | 게시글이 속한 게시판의 슬러그입니다. 대시보드에서 해당 게시판으로 이동할 때 식별자로 사용됩니다. |
| board_name | string | `API 문서 샘플 게시판` | 게시글이 속한 게시판의 현재 로케일 표시명입니다(getLocalizedName). |
| title | string | `API 문서 샘플 게시글` | 제목 |
| author_name | string | `API 문서 샘플 사용자` | 작성자 이름입니다. 회원 게시글은 연결된 사용자 이름을, 비회원 게시글은 작성 시 입력한 이름(author_name)을 사용합니다. |
| comments_count | integer | `0` | comments 개수 (집계) |
| created_at | string | `4시간 전` | 생성 일시 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "sirsoft-board::messages.dashboard.fetch_success",
    "data": [
        {
            "id": 1,
            "board_slug": "apidoc-sample-board",
            "board_name": "API 문서 샘플 게시판",
            "title": "API 문서 샘플 게시글",
            "author_name": "API 문서 샘플 사용자",
            "comments_count": 0,
            "created_at": "4시간 전"
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 전체 게시판에서 최신 게시글을 대시보드 위젯에 표시하기 위해 반환합니다. `auth:sanctum` 인증이 필요하며(대시보드 진입은 코어 `core.dashboard.read` 가드로 보호), `limit`(1~50, 기본 5)으로 표시 건수를 제어합니다. 각 항목은 게시판(board_slug/board_name), 제목, 작성자, 댓글 수, 작성 시각(상대 시간 표기)을 포함합니다.


