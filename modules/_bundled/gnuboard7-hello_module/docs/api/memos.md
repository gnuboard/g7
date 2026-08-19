# Memos API 레퍼런스

> **소유**: module `gnuboard7-hello_module` · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Memos 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/modules/gnuboard7-hello_module/admin/memos
<!-- @generated:start:api.modules.gnuboard7-hello_module.admin.memos.index -->
- **라우트명**: `api.modules.gnuboard7-hello_module.admin.memos.index`
- **컨트롤러**: `Modules\Gnuboard7\HelloModule\Http\Controllers\Admin\MemoController@index`
- **인증/권한**: `auth:sanctum` + `permission:gnuboard7-hello_module.memos.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |

**요청 예시**

```http
GET /api/modules/gnuboard7-hello_module/admin/memos?page=1&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data` 가 항목 배열, `data.meta` 가 페이지 정보, `data.abilities` 가 컬렉션 레벨 권한 (`MemoCollection`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| data | array | `[{...}]` | 메모 항목 배열 (각 항목은 `MemoResource` — 아래 표) |
| meta.current_page | integer | `1` | 현재 페이지 번호 |
| meta.last_page | integer | `3` | 마지막 페이지 번호 |
| meta.per_page | integer | `10` | 페이지당 항목 수 (미지정 시 기본 `10`) |
| meta.total | integer | `27` | 전체 항목 수 |
| abilities.can_create | boolean | `true` | 요청자의 `gnuboard7-hello_module.memos.create` 보유 여부 |
| abilities.can_update | boolean | `true` | 요청자의 `gnuboard7-hello_module.memos.update` 보유 여부 |
| abilities.can_delete | boolean | `true` | 요청자의 `gnuboard7-hello_module.memos.delete` 보유 여부 |

`data.data[]` 항목 (`MemoResource`):

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 메모 기본키 |
| uuid | string(uuid) | `9f2c1b0e-…` | 외부 노출용 식별자 |
| title | string | `첫 번째 메모` | 제목 |
| content | string | `메모 본문입니다.` | 본문 내용 |
| created_at | string | `2026-08-16 01:30:00` | 생성 일시 (요청자 타임존으로 포맷) |
| updated_at | string | `2026-08-16 01:30:00` | 수정 일시 (요청자 타임존으로 포맷) |
| abilities | object | `{"can_create":true, …}` | 항목 레벨 권한 (컬렉션 `abilities` 와 같은 3종) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "메모 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "uuid": "9f2c1b0e-4d7a-4f10-9c33-1a5b6e8d2f04",
                "title": "첫 번째 메모",
                "content": "메모 본문입니다.",
                "created_at": "2026-08-16 01:30:00",
                "updated_at": "2026-08-16 01:30:00",
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true
                }
            }
        ],
        "meta": {
            "current_page": 1,
            "last_page": 3,
            "per_page": 10,
            "total": 27
        },
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

> 위 문서의 실측이 `403` 으로 관측된 것은 프로브 계정에 `gnuboard7-hello_module.memos.read` 권한이 없었기 때문이다. 권한을 갖춘 요청은 `200` 과 위 페이로드를 받는다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`gnuboard7-hello_module.memos.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지 — `page` 최소 1, `per_page` 1~100) |
| 500 | Internal Server Error | 조회 중 예외 발생 (`gnuboard7-hello_module::messages.memo.fetch_failed`, `errors.error` 에 예외 메시지) |

<!-- @generated:end -->

**설명**

메모 목록을 페이지 단위로 조회한다. 학습용 샘플 모듈의 표준 목록 엔드포인트로, 코어의 `AdminBaseController` + `BaseApiCollection` 조합을 그대로 따른다.

`per_page` 를 지정하지 않으면 기본값 `10` 이 적용된다. 상·하한(1~100)은 `MemoListRequest` 가 검증하므로 Service 는 검증 없이 값을 그대로 쓴다 — 검증은 FormRequest 책임이라는 규칙의 예시다.


### POST /api/modules/gnuboard7-hello_module/admin/memos
<!-- @generated:start:api.modules.gnuboard7-hello_module.admin.memos.store -->
- **라우트명**: `api.modules.gnuboard7-hello_module.admin.memos.store`
- **컨트롤러**: `Modules\Gnuboard7\HelloModule\Http\Controllers\Admin\MemoController@store`
- **인증/권한**: `auth:sanctum` + `permission:gnuboard7-hello_module.memos.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| title | body | string | 예 | max 255 | 제목 |
| content | body | string | 예 | — | 본문 내용 |

**요청 예시**

```http
POST /api/modules/gnuboard7-hello_module/admin/memos HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "title": "예시 제목",
    "content": "예시 내용입니다."
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 가 생성된 메모 하나 (`MemoResource`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `28` | 생성된 메모의 기본키 |
| uuid | string(uuid) | `3b7e5a12-…` | 외부 노출용 식별자 (생성 시 자동 부여) |
| title | string | `예시 제목` | 제목 |
| content | string | `예시 내용입니다.` | 본문 내용 |
| created_at | string | `2026-08-16 01:30:00` | 생성 일시 (요청자 타임존으로 포맷) |
| updated_at | string | `2026-08-16 01:30:00` | 수정 일시 (생성 직후에는 `created_at` 과 같다) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 요청자의 메모 권한 3종 |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "메모가 생성되었습니다.",
    "data": {
        "id": 28,
        "uuid": "3b7e5a12-8c04-4d61-9b2f-7e0a1c4d5f88",
        "title": "예시 제목",
        "content": "예시 내용입니다.",
        "created_at": "2026-08-16 01:30:00",
        "updated_at": "2026-08-16 01:30:00",
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true
        }
    }
}
```

> 성공 상태코드는 `200` 이 아니라 **`201 Created`** 다. 위 문서의 실측이 `403` 으로 관측된 것은 프로브 계정에 `gnuboard7-hello_module.memos.create` 권한이 없었기 때문이다.

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`gnuboard7-hello_module.memos.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지 — `title` 필수·255자 이하, `content` 필수) |
| 500 | Internal Server Error | 생성 중 예외 발생 (`gnuboard7-hello_module::messages.memo.create_failed`, `errors.error` 에 예외 메시지) |

<!-- @generated:end -->

**설명**

메모를 생성한다. 검증은 `StoreMemoRequest` 가 전담하고 Service 는 검증된 배열만 받는다 — Service 에 검증 로직을 두지 않는다는 규칙의 예시다.

컨트롤러가 `$request->validated()` 를 넘기므로 FormRequest 에 정의되지 않은 필드는 모델에 도달하지 않는다. `$request->all()` / `except()` 를 쓰면 `$fillable` 을 통해 미정의 필드가 새므로 쓰지 않는다.


### DELETE /api/modules/gnuboard7-hello_module/admin/memos/{id}
<!-- @generated:start:api.modules.gnuboard7-hello_module.admin.memos.destroy -->
- **라우트명**: `api.modules.gnuboard7-hello_module.admin.memos.destroy`
- **컨트롤러**: `Modules\Gnuboard7\HelloModule\Http\Controllers\Admin\MemoController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:gnuboard7-hello_module.memos.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
DELETE /api/modules/gnuboard7-hello_module/admin/memos/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_삭제 응답에는 페이로드가 없다. 컨트롤러가 `success(메시지)` 만 호출하므로 `data` 는 `null` 이다._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| (없음) | null | `null` | 삭제 성공 시 `data` 는 항상 `null`. 결과 판정은 `success` 와 상태코드로 한다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "메모가 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`gnuboard7-hello_module.memos.delete`)이 없는 경우 |
| 404 | Not Found | 해당 `id` 의 메모가 없는 경우 (`gnuboard7-hello_module::messages.memo.not_found`) |
| 500 | Internal Server Error | 삭제 중 예외 발생 (`gnuboard7-hello_module::messages.memo.delete_failed`, `errors.error` 에 예외 메시지) |

<!-- @generated:end -->

**설명**

메모를 삭제한다. 컨트롤러가 먼저 `getMemo($id)` 로 대상을 조회하므로, 존재하지 않는 `id` 는 삭제 시도 전에 `404` 로 걸러진다.

삭제는 DB CASCADE 에 의존하지 않고 Service 가 명시적으로 수행한다 — 훅 발화·파일 정리·로깅을 보장하기 위해서다.


### GET /api/modules/gnuboard7-hello_module/admin/memos/{id}
<!-- @generated:start:api.modules.gnuboard7-hello_module.admin.memos.show -->
- **라우트명**: `api.modules.gnuboard7-hello_module.admin.memos.show`
- **컨트롤러**: `Modules\Gnuboard7\HelloModule\Http\Controllers\Admin\MemoController@show`
- **인증/권한**: `auth:sanctum` + `permission:gnuboard7-hello_module.memos.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |

**요청 예시**

```http
GET /api/modules/gnuboard7-hello_module/admin/memos/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 가 메모 하나 (`MemoResource`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `2` | 기본 키 (내부 식별자) |
| uuid | string(uuid) | `4473e9ee-ecdf-4ad0-afb3-78ada47265af` | 외부 노출용 UUID |
| title | string | `두 번째 메모` | 제목 |
| content | string | `Memo 엔티티의 CRUD 동작을 확인할 수 있는 추가 샘플입니다.` | 본문 내용 |
| created_at | string | `2026-07-31 22:09:15` | 생성 일시 (요청자 타임존으로 포맷) |
| updated_at | string | `2026-07-31 22:09:15` | 수정 일시 (요청자 타임존으로 포맷) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 요청자의 메모 권한 3종 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "메모를 조회했습니다.",
    "data": {
        "id": 2,
        "uuid": "4473e9ee-ecdf-4ad0-afb3-78ada47265af",
        "title": "두 번째 메모",
        "content": "Memo 엔티티의 CRUD 동작을 확인할 수 있는 추가 샘플입니다.",
        "created_at": "2026-07-31 22:09:15",
        "updated_at": "2026-07-31 22:09:15",
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
| 403 | Forbidden | 요구 권한(`gnuboard7-hello_module.memos.read`)이 없는 경우 |
| 404 | Not Found | 해당 `id` 의 메모가 없는 경우 (`gnuboard7-hello_module::messages.memo.not_found`) |
| 500 | Internal Server Error | 조회 중 예외 발생 (`gnuboard7-hello_module::messages.memo.fetch_failed`, `errors.error` 에 예외 메시지) |

<!-- @generated:end -->

**설명**

메모 단건을 조회하는 관리자 엔드포인트다. path 파라미터는 `int` 타입힌트를 받는 **기본키 `id`** 이며, 응답에 함께 실리는 `uuid` 가 아니다.

같은 리소스의 공개 조회는 `GET /api/modules/gnuboard7-hello_module/memos/{id}` 로 별도 제공된다. 관리자 경로는 `permission:gnuboard7-hello_module.memos.read` 를 요구하는 반면 공개 경로는 `optional.sanctum` 이라 비회원도 접근한다.


### PUT /api/modules/gnuboard7-hello_module/admin/memos/{id}
<!-- @generated:start:api.modules.gnuboard7-hello_module.admin.memos.update -->
- **라우트명**: `api.modules.gnuboard7-hello_module.admin.memos.update`
- **컨트롤러**: `Modules\Gnuboard7\HelloModule\Http\Controllers\Admin\MemoController@update`
- **인증/권한**: `auth:sanctum` + `permission:gnuboard7-hello_module.memos.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 대상 리소스의 식별자 |
| title | body | string | 예 | max 255 | 제목 |
| content | body | string | 예 | — | 본문 내용 |

**요청 예시**

```http
PUT /api/modules/gnuboard7-hello_module/admin/memos/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "title": "예시 제목",
    "content": "예시 내용입니다."
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 가 수정된 메모 하나 (`MemoResource`)._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `2` | 기본 키 (수정으로 바뀌지 않는다) |
| uuid | string(uuid) | `4473e9ee-ecdf-4ad0-afb3-78ada47265af` | 외부 노출용 UUID (수정으로 바뀌지 않는다) |
| title | string | `예시 제목` | 수정된 제목 |
| content | string | `예시 내용입니다.` | 수정된 본문 내용 |
| created_at | string | `2026-07-31 22:09:15` | 생성 일시 (불변) |
| updated_at | string | `2026-08-16 01:30:00` | 수정 일시 (이번 요청 시각으로 갱신) |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true}` | 요청자의 메모 권한 3종 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "메모가 수정되었습니다.",
    "data": {
        "id": 2,
        "uuid": "4473e9ee-ecdf-4ad0-afb3-78ada47265af",
        "title": "예시 제목",
        "content": "예시 내용입니다.",
        "created_at": "2026-07-31 22:09:15",
        "updated_at": "2026-08-16 01:30:00",
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
| 403 | Forbidden | 요구 권한(`gnuboard7-hello_module.memos.update`)이 없는 경우 |
| 404 | Not Found | 해당 `id` 의 메모가 없는 경우 (`gnuboard7-hello_module::messages.memo.not_found`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지 — `title` 필수·255자 이하, `content` 필수) |
| 500 | Internal Server Error | 수정 중 예외 발생 (`gnuboard7-hello_module::messages.memo.update_failed`, `errors.error` 에 예외 메시지) |

<!-- @generated:end -->

**설명**

메모를 수정한다. `PUT` 이므로 `title` 과 `content` 를 **모두** 보내야 한다 (`UpdateMemoRequest` 가 둘 다 필수로 검증). 일부 필드만 보내면 `422` 다.

컨트롤러는 `getMemo($id)` 로 대상을 먼저 조회하므로 존재하지 않는 `id` 는 수정 시도 전에 `404` 로 걸러지고, Service 에는 `$request->validated()` 결과만 전달되어 FormRequest 미정의 필드가 모델에 도달하지 않는다.


### GET /api/modules/gnuboard7-hello_module/memos
<!-- @generated:start:api.modules.gnuboard7-hello_module.memos.index -->
- **라우트명**: `api.modules.gnuboard7-hello_module.memos.index`
- **컨트롤러**: `Modules\Gnuboard7\HelloModule\Http\Controllers\Api\MemoController@index`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |

**요청 예시**

```http
GET /api/modules/gnuboard7-hello_module/memos?page=1&per_page=1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `2` | 기본 키 (내부 식별자) |
| uuid | string | `4473e9ee-ecdf-4ad0-afb3-78ada47265af` | 외부 노출용 UUID (URL/API 식별자, 내부 id 비노출) |
| title | string | `두 번째 메모` | 제목 |
| content | string | `Memo 엔티티의 CRUD 동작을 확인할 수 있는 추가 샘플입니다.` | 본문 내용 |
| created_at | string | `2026-07-31 22:09:15` | 생성 일시 |
| updated_at | string | `2026-07-31 22:09:15` | 최종 수정 일시 |
| abilities | object | `{"can_create":false,"can_update":false,"can_delete":false}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "메모를 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 2,
                "uuid": "4473e9ee-ecdf-4ad0-afb3-78ada47265af",
                "title": "두 번째 메모",
                "content": "Memo 엔티티의 CRUD 동작을 확인할 수 있는 추가 샘플입니다.",
                "created_at": "2026-07-31 22:09:15",
                "updated_at": "2026-07-31 22:09:15",
                "abilities": {
                    "can_create": false,
                    "can_update": false,
                    "can_delete": false
                }
            },
            {
                "id": 1,
                "uuid": "86a29502-24cd-4124-a283-b0454d499fc1",
                "title": "환영합니다",
                "content": "Hello 모듈의 첫 번째 샘플 메모입니다. 학습용으로 제공됩니다.",
                "created_at": "2026-07-31 22:09:15",
                "updated_at": "2026-07-31 22:09:15",
                "abilities": {
                    "can_create": false,
                    "can_update": false,
                    "can_delete": false
                }
            },
            "... (총 25건 중 2건 표시)"
        ],
        "meta": {
            "current_page": 1,
            "last_page": 2,
            "per_page": 25,
            "total": 34
        },
        "abilities": {
            "can_create": false,
            "can_update": false,
            "can_delete": false
        }
    }
}
```

**응답 예시**

```json
{
    "success": true,
    "message": "메모를 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "uuid": "9f1c0b4e-3a2d-4c8e-9f77-0a1b2c3d4e5f",
                "title": "샘플 메모",
                "content": "메모 본문 내용",
                "created_at": "2026-04-21 09:00:00",
                "updated_at": "2026-04-21 09:00:00",
                "abilities": {
                    "can_create": false,
                    "can_update": false,
                    "can_delete": false
                }
            }
        ],
        "meta": {
            "current_page": 1,
            "last_page": 3,
            "per_page": 10,
            "total": 21
        },
        "abilities": {
            "can_create": false,
            "can_update": false,
            "can_delete": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 429 | Too Many Requests | `throttle:600,1` 초과 (분당 600회) |
| 500 | Internal Server Error | 목록 조회 중 예외 발생 시 `messages.memo.fetch_failed` (`메모 조회에 실패했습니다.`) |

<!-- @generated:end -->

**설명**

메모 목록을 페이지네이션으로 조회하는 공개 엔드포인트다. 라우트는 `optional.sanctum` 미들웨어를 쓰므로 **비로그인 사용자도 조회할 수 있다**(생성기 표기 `auth:sanctum` 은 실제와 다르며, 실제로는 토큰이 있으면 인증 컨텍스트를 붙이되 없어도 통과한다). 이 확장은 학습용 샘플로, 공개 읽기 API 의 표준 패턴(`PublicBaseController` + `throttle:600,1`)을 보여준다.

- **요청 파라미터**: query `per_page`(정수, 기본 10)로 페이지 크기를 조절한다. FormRequest 를 쓰지 않고 컨트롤러가 `$request->query('per_page', 10)` 로 직접 읽는다.
- **응답**: `data` 는 `MemoCollection`(`BaseApiCollection`) 산물로, `data.data` 에 `MemoResource` 배열, `data.meta.pagination` 에 페이지 메타(`current_page`/`last_page`/`per_page`/`total`/`from`/`to`/`has_more_pages`)를 담는다. 각 메모 항목 필드는 아래 상세 조회와 동일하다.
- **미설치 주의**: 이 문서는 확장이 미설치인 상태에서 라우트 파일 정적 분석으로 생성되어 실측 응답이 없다(`http-404`). 설치 후 `api:docgen --scope=module:gnuboard7-hello_module --seed` 로 실측하면 응답 예시가 채워진다.

**응답 예시** (정적 — MemoResource 구조 기준)

```json
{
  "success": true,
  "data": {
    "data": [
      { "id": 1, "uuid": "…", "title": "샘플 메모", "content": "본문", "created_at": "…", "updated_at": "…", "is_owner": false, "abilities": { "can_create": false, "can_update": false, "can_delete": false } }
    ],
    "meta": { "pagination": { "current_page": 1, "last_page": 1, "per_page": 10, "total": 1, "from": 1, "to": 1, "has_more_pages": false } }
  },
  "message": "메모를 조회했습니다.",
  "error": null
}
```


### GET /api/modules/gnuboard7-hello_module/memos/{id}
<!-- @generated:start:api.modules.gnuboard7-hello_module.memos.show -->
- **라우트명**: `api.modules.gnuboard7-hello_module.memos.show`
- **컨트롤러**: `Modules\Gnuboard7\HelloModule\Http\Controllers\Api\MemoController@show`
- **인증/권한**: `optional.sanctum` (선택적 인증: 회원/비회원 모두 접근)

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | string | 예 | — | 조회할 메모의 고유번호(기본 키). `MemoService::getMemo($id)` 로 직접 조회하며 없으면 404 |

**요청 예시**

```http
GET /api/modules/gnuboard7-hello_module/memos/{id} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}   (optional.sanctum: 비회원은 헤더 생략 가능)
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체(`MemoResource`)의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 메모 고유번호 (기본 키) |
| uuid | string | `9f1c0b4e-3a2d-4c8e-9f77-0a1b2c3d4e5f` | 메모 UUID (외부 식별자, 36자) |
| title | string | `샘플 메모` | 메모 제목 (최대 255자) |
| content | string | `메모 본문 내용` | 메모 본문 |
| created_at | string | `2026-04-21 09:00:00` | 생성 일시 (사용자 타임존 기준 `Y-m-d H:i:s`, 값이 없으면 필드 자체 생략) |
| updated_at | string | `2026-04-21 09:00:00` | 수정 일시 (사용자 타임존 기준 `Y-m-d H:i:s`, 값이 없으면 필드 자체 생략) |
| abilities | object | `{"can_create":false,"can_update":false,"can_delete":false}` | 권한 능력 (`gnuboard7-hello_module.memos.{create,update,delete}` 보유 여부). `MemoResource` 는 `ownerField()` 미정의 → `is_owner` 미포함 |

**응답 예시**

```json
{
    "success": true,
    "message": "메모를 조회했습니다.",
    "data": {
        "id": 1,
        "uuid": "9f1c0b4e-3a2d-4c8e-9f77-0a1b2c3d4e5f",
        "title": "샘플 메모",
        "content": "메모 본문 내용",
        "created_at": "2026-04-21 09:00:00",
        "updated_at": "2026-04-21 09:00:00",
        "abilities": {
            "can_create": false,
            "can_update": false,
            "can_delete": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 404 | Not Found | 해당 `id` 의 메모가 없는 경우 — `ModelNotFoundException` → `메모를 찾을 수 없습니다.` |
| 429 | Too Many Requests | `throttle:600,1` 초과 (분당 600회) |
| 500 | Internal Server Error | 조회 중 예외 발생 시 `메모 조회에 실패했습니다.` |

<!-- @generated:end -->

**설명**

단일 메모를 조회하는 공개 엔드포인트다. 목록과 마찬가지로 `optional.sanctum` 이라 비로그인 조회가 허용된다(생성기 표기 `auth:sanctum` 은 실제와 다름).

- **path 파라미터** `{id}`: 메모의 정수 PK. 라우트 제약 `whereNumber('id')` 로 숫자만 매칭된다. 라우트-모델 바인딩 없이 컨트롤러가 `MemoService::getMemo($id)` 로 직접 조회하므로, 존재하지 않으면 `ModelNotFoundException` → `messages.memo.not_found`(404).
- **응답**: `data` 는 단건 `MemoResource` 다. 필드는 `id`(integer), `uuid`(string), `title`(string), `content`(string), 타임스탬프(`created_at`/`updated_at`), 그리고 `BaseApiResource` 공통 메타 `is_owner`(boolean) + `abilities`(`can_create`/`can_update`/`can_delete` — 각 권한 보유 여부). 권한 능력은 `gnuboard7-hello_module.memos.{create,update,delete}` 권한 매핑에서 파생된다.
- **미설치 주의**: 이 문서는 미설치 상태 정적 분석으로 생성되어 실측 응답이 없다(`unresolved-path-param` — 실측할 실제 메모 레코드가 없음). 설치 후 `--seed` 실측 시 실제 값으로 채워진다.

**응답 예시** (정적 — MemoResource 구조 기준)

```json
{
  "success": true,
  "data": {
    "id": 1,
    "uuid": "…",
    "title": "샘플 메모",
    "content": "본문",
    "created_at": "…",
    "updated_at": "…",
    "is_owner": false,
    "abilities": { "can_create": false, "can_update": false, "can_delete": false }
  },
  "message": "메모를 조회했습니다.",
  "error": null
}
```


