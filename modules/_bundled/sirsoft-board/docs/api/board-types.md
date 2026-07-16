# Board Types API 레퍼런스

> **소유**: module `sirsoft-board` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Board Types 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/sirsoft-board/admin/board-types
<!-- @generated:start:api.modules.sirsoft-board.admin.board-types.index -->
- **라우트명**: `api.modules.sirsoft-board.admin.board-types.index`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardTypeController@index`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.boards.create`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/modules/sirsoft-board/admin/board-types HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| slug | string | `basic` | 유형 식별자 (basic, card, gallery 등) |
| name | object | `{"ko":"기본형","en":"Basic List","ja":"基本形"}` | 유형명 (다국어: {"ko": "기본형", "en": "Basic List"}) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "게시판 유형 목록을 조회했습니다.",
    "data": [
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
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.boards.create`)이 없는 경우 |

<!-- @generated:end -->

**설명** 관리자가 게시판 생성/편집 화면에서 선택할 수 있는 게시판 유형(basic, card, gallery 등) 목록을 반환합니다. `auth:sanctum` 인증과 `sirsoft-board.boards.create` 권한이 필요하며, 게시판 생성 권한을 재사용해 접근을 통제합니다. `name` 은 다국어 객체로 반환되므로 표시 시 현재 로케일 키를 선택해야 합니다.


### POST /api/modules/sirsoft-board/admin/board-types
<!-- @generated:start:api.modules.sirsoft-board.admin.board-types.store -->
- **라우트명**: `api.modules.sirsoft-board.admin.board-types.store`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardTypeController@store`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.boards.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| slug | body | string | 예 | max 50 | URL 친화 식별자 (slug) |
| name | body | string | 예 | — | 대상의 이름/명칭 |

**요청 예시**

```http
POST /api/modules/sirsoft-board/admin/board-types HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "slug": "example-key",
    "name": "예시 이름"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.boards.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 새 게시판 유형을 생성합니다. `slug` 는 유형을 식별하는 고유 문자열(최대 50자)이고 `name` 은 유형명입니다. `auth:sanctum` 인증과 `sirsoft-board.boards.create` 권한이 필요하며, 성공 시 생성된 유형 리소스와 함께 201 을 반환합니다.


### DELETE /api/modules/sirsoft-board/admin/board-types/{id}
<!-- @generated:start:api.modules.sirsoft-board.admin.board-types.destroy -->
- **라우트명**: `api.modules.sirsoft-board.admin.board-types.destroy`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardTypeController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.boards.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/sirsoft-board/admin/board-types/2 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.boards.create`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 지정한 `id` 의 게시판 유형을 삭제합니다. `auth:sanctum` 인증과 `sirsoft-board.boards.create` 권한이 필요합니다. 존재하지 않는 id 는 404, 해당 유형을 사용 중인 게시판이 있는 등 삭제할 수 없는 경우 422 를 반환합니다.


### PUT /api/modules/sirsoft-board/admin/board-types/{id}
<!-- @generated:start:api.modules.sirsoft-board.admin.board-types.update -->
- **라우트명**: `api.modules.sirsoft-board.admin.board-types.update`
- **컨트롤러**: `Modules\Sirsoft\Board\Http\Controllers\Admin\BoardTypeController@update`
- **인증/권한**: `auth:sanctum` + `permission:sirsoft-board.boards.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| name | body | string | 예 | — | 대상의 이름/명칭 |

**요청 예시**

```http
PUT /api/modules/sirsoft-board/admin/board-types/2 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": "예시 이름"
}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`sirsoft-board.boards.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 지정한 `id` 의 게시판 유형명을 수정합니다. `slug` 는 변경되지 않으며 `name` 만 갱신합니다. `auth:sanctum` 인증과 `sirsoft-board.boards.create` 권한이 필요하고, 존재하지 않는 id 는 404 를 반환합니다.


