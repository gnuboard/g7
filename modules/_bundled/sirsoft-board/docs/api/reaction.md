# Reaction API 레퍼런스

> **소유**: module `sirsoft-board` · 게시글 반응(추천/비추천) 관련 엔드포인트 레퍼런스입니다.

---

## TL;DR (5초 요약)

```text
1. 게시글 반응(추천/비추천) 등록·전환·해제를 단일 POST 엔드포인트로 처리합니다
2. 반응은 로그인 회원만 가능하며(auth:sanctum), 본인 글에는 반응할 수 없습니다
3. 글당 반응 1개 — 같은 유형 재요청은 해제, 다른 유형은 전환(이전 -1·신규 +1)
4. 관리자 반응 유형 목록 API는 게시판 설정 화면의 유형 체크박스 옵션 소스입니다
5. 유형 CRUD 는 이번 범위 밖 — 유형은 시더/스크립트로 관리합니다
```

---

## POST /api/modules/sirsoft-board/boards/{slug}/posts/{postId}/react

게시글에 반응(추천/비추천)을 남깁니다. 등록·전환·해제를 한 엔드포인트가 통합 처리합니다.

- **라우트명**: `api.modules.sirsoft-board.boards.posts.react`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\User\ReactionController@react`
- **인증/권한**: `auth:sanctum` (로그인 회원 전용)

**경로 파라미터**

| 이름 | 위치 | 타입 | 필수 | 용도 |
| --- | --- | --- | --- | --- |
| slug | path | string | 예 | 게시판 slug |
| postId | path | integer | 예 | 반응 대상 게시글 ID (해당 게시판 소속이어야 함) |

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 용도 |
| --- | --- | --- | --- | --- |
| reaction_type_id | body | integer | 예 | 반응 유형 ID. 존재하는 활성 유형이면서 게시판이 켠(활성) 유형이어야 합니다. |

**요청 예시**

```http
POST /api/modules/sirsoft-board/boards/free/posts/42/react HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
  "reaction_type_id": 1
}
```

**동작**

| 상황 | 결과 (`data.action`) | 카운트 변화 |
| --- | --- | --- |
| 기존 반응 없음 | `add` | 해당 유형 +1 |
| 기존 반응이 다른 유형 | `change` | 이전 유형 -1 · 신규 유형 +1 |
| 기존 반응이 같은 유형 | `remove` | 해당 유형 -1 (이력 행 삭제) |

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| action | string | `add` | 수행된 동작 (`add`/`change`/`remove`) |
| my_reaction_type_id | integer\|null | `1` | 처리 후 내가 누른 반응 유형 ID. 해제 시 `null`. |
| reaction_counts | object | `{"1":18,"2":2}` | 게시판이 켠 활성 유형 전체의 개수 맵. 키는 유형 ID 문자열, 개수 0인 유형도 포함됩니다. |

**응답 예시**

```json
{
  "success": true,
  "message": "반응을 남겼습니다.",
  "data": {
    "action": "add",
    "my_reaction_type_id": 1,
    "reaction_counts": { "1": 18, "2": 2 }
  }
}
```

**오류**

| 상태 | 사유 |
| --- | --- |
| 401 | 비로그인 요청 |
| 404 | 게시글이 해당 게시판 소속이 아니거나 존재하지 않음 |
| 422 | 반응 기능이 꺼진 게시판 / 게시판이 켜지 않은 유형 / 본인 글 반응 / `reaction_type_id` 검증 실패 |

---

## GET /api/modules/sirsoft-board/admin/reaction-types

활성 반응 유형 전체를 `display_order` 순으로 반환합니다. 게시판 설정 화면의 "사용할 반응 유형" 체크박스 옵션 소스입니다. 유형 CRUD 는 제공하지 않습니다.

- **라우트명**: `api.modules.sirsoft-board.admin.reaction-types.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\ReactionTypeController@index`
- **인증/권한**: `auth:sanctum` + `admin` + `permission:sirsoft-board.settings.read`

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/reaction-types HTTP/1.1
Host: api.example.com
Accept: application/json
Accept-Language: ko
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data.reaction_types[]` 배열 항목)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 반응 유형 ID |
| code | string | `like` | 내부 식별자 (예: `like`/`dislike`) |
| name | string | `추천` | 현재 로케일 라벨. 미입력 언어는 사이트 기본 언어로 폴백됩니다. |
| icon | string\|null | `fas fa-thumbs-up` | 저장된 원본 Font Awesome 클래스 (스타일 접두사 포함) |
| icon_name | string\|null | `fa-thumbs-up` | Icon 컴포넌트 `name` prop 용 토큰 (스타일 접두사 제거). Icon 컴포넌트가 접두사를 자체 부착하므로 `icon` 을 그대로 넘기면 접두사가 중복됩니다. |

**응답 예시**

```json
{
  "success": true,
  "message": "반응 유형 목록을 조회했습니다.",
  "data": {
    "reaction_types": [
      { "id": 1, "code": "like", "name": "추천", "icon": "fas fa-thumbs-up", "icon_name": "fa-thumbs-up" },
      { "id": 2, "code": "dislike", "name": "비추천", "icon": "fas fa-thumbs-down", "icon_name": "fa-thumbs-down" }
    ]
  }
}
```
