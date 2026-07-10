# Activity Stats API 레퍼런스

> **소유**: module `sirsoft-board` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Activity Stats 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-board/me/activity-stats
<!-- @generated:start:api.modules.sirsoft-board.me.activity-stats -->
- **라우트명**: `api.modules.sirsoft-board.me.activity-stats`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\UserActivityController@stats`
- **인증/권한**: `auth:sanctum`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/me/activity-stats HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| total_posts | integer | `1` | 회원 본인이 작성한 게시글 총수입니다. 비활성 게시판의 글은 제외하며 소프트 삭제된 글도 집계에서 빠집니다. |
| total_comments | integer | `0` | 회원 본인이 작성한 게시글들의 댓글 수 합계(SUM of comments_count)입니다. 비활성 게시판 글은 제외됩니다. |
| total_views | integer | `42` | 회원 본인이 작성한 게시글들의 누적 조회수 합계(SUM of view_count)입니다. 비활성 게시판 글은 제외됩니다. |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "활동 통계를 조회했습니다.",
    "data": {
        "total_posts": 1,
        "total_comments": 0,
        "total_views": 44
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |

<!-- @generated:end -->

**설명** 로그인한 회원 본인의 게시판 활동 통계(작성 글 수, 작성 댓글 수, 누적 조회수)를 마이페이지 요약 카드에 표시하기 위해 반환합니다. `auth:sanctum` 인증이 필요한 회원 전용 엔드포인트로, 대상은 항상 인증된 본인(`Auth::id()`)입니다.


