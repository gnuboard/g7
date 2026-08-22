# Activity Logs API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Activity Logs 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(curl) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/activity-logs
<!-- @generated:start:api.admin.activity-logs.index -->
- **라우트명**: `api.admin.activity-logs.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ActivityLogController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.activities.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| log_type | query | array | 아니오 | — | 로그 유형 필터 (원소별 값: admin 관리자, user 사용자, system 시스템 — ActivityLogType Enum). 배열로 다중 유형 동시 조회 가능 |
| action | query | string | 아니오 | max 100 | 액션 유형 필터 (예: created, updated, deleted, login — action 필드 부분/일치 검색 대상) |
| user_id | query | integer | 아니오 | — | user 식별자 |
| loggable_type | query | string | 아니오 | max 255 | 연관 리소스 모델 클래스명 필터 (예: App\Models\User — 특정 엔티티 유형의 로그만 조회) |
| search | query | string | 아니오 | max 255 | 검색어 (지정한 검색 대상 필드에서 부분 일치) |
| search_type | query | string | 아니오 | — | 검색 유형 (검색 대상/방식 구분) |
| created_by | query | string | 아니오 | max 36 | 로그를 생성한 행위 주체 식별자 필터 (행위자 기준 조회) |
| date_from | query | date | 아니오 | — | 조회 기간 시작일 |
| date_to | query | date | 아니오 | — | 조회 기간 종료일 |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| sort_by | query | string | 아니오 | — | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | — | 정렬 방향 (asc 오름차순 / desc 내림차순) |
| cursor | query | string | 아니오 | max 500 | 이어보기 커서. 주면 페이지 번호 대신 키셋 방식으로 응답합니다 (기록이 많이 쌓인 사이트에서 뒤쪽 페이지가 느려지지 않음). 형식이 깨진 값은 오류 없이 첫 페이지로 해석됩니다 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.activity_log.index_validation_rules`).

**요청 예시**

```http
GET /api/admin/activity-logs?log_type=%EC%98%88%EC%8B%9C%EA%B0%92&action=%EC%98%88%EC%8B%9C%EA%B0%92&user_id=1&loggable_type=%EC%98%88%EC%8B%9C%EA%B0%92&search=%EC%98%88%EC%8B%9C%EA%B0%92&search_type=%EC%98%88%EC%8B%9C%EA%B0%92&created_by=%EC%98%88%EC%8B%9C%EA%B0%92&date_from=2026-01-01&date_to=2026-01-01&per_page=1&sort_by=%EC%98%88%EC%8B%9C%EA%B0%92&sort_order=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| number | integer | `88816` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `89118` | 기본 키 (내부 식별자) |
| log_type | string | `user` | 로그 유형 (admin: 관리자, user: 사용자, system: 시스템) |
| log_type_label | string | `사용자` | `log_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| loggable_type | string | `App\Models\User` | 로그가 연관된 대상 리소스의 모델 클래스 FQCN (loggable 다형성 관계 타입) |
| loggable_type_display | string | `User` | `loggable_type` 의 표시용 짧은 이름 (네임스페이스 제외 클래스명 파생) |
| loggable_id | integer | `1209` | loggable 식별자 (연관 리소스 참조) |
| action | string | `auth.login` | 액션 유형 (created, updated, deleted, login, export 등) |
| action_label | string | `로그인` | `action` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| localized_description | string | `관리자 로그인` | `description` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| description_key | string | `activity_log.description.auth_login` | 다국어 번역 키 (예: activity_log.description.user_create) |
| properties | object | `{"result_count":82}` | 변경 상세 데이터 (old/new 값) |
| changes | array | `[{"field":"stock_quantity","label_key":"sirsoft-ecommerce…` | 구조화된 변경 이력 (필드별 label_key, old, new, type) |
| bulk_changes | null | `null` | 일괄 수정 로그의 모델별 변경 이력 배열 (원소: model_id + changes[]). 단일 수정 로그이면 null이고 대신 changes 필드가 채워짐 |
| has_changes | boolean | `false` | changes 여부 |
| actor_name | string | `최고관리자` | 행위를 수행한 주체(사용자/시스템)의 이름 |
| user | object | `{"uuid":"a26219fc-94a0-4f63-9404-04c2a6ac99e4","name":"최고…` | 행위를 수행한 사용자 정보 (uuid/name/email). 시스템 발생 로그로 사용자가 없으면 name 에 "시스템" 라벨만 담김 |
| ip_address | string | `127.0.0.1` | IP 주소 (IPv6 대응) |
| created_at | string | `2026-08-04 19:00:10` | 생성 일시 |
| is_owner | boolean | `true` | 현재 인증 사용자가 이 리소스의 소유자인지 여부 (BaseApiResource 표준 메타) |
| abilities | object | `{"can_read":true,"can_delete":true}` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "활동 로그 정보를 성공적으로 가져왔습니다.",
    "data": {
        "data": [
            {
                "number": 88816,
                "id": 89118,
                "log_type": "user",
                "log_type_label": "사용자",
                "loggable_type": "App\\Models\\User",
                "loggable_type_display": "User",
                "loggable_id": 1209,
                "action": "auth.login",
                "action_label": "로그인",
                "localized_description": "관리자 로그인",
                "description_key": "activity_log.description.auth_login",
                "properties": null,
                "changes": null,
                "bulk_changes": null,
                "has_changes": false,
                "actor_name": "최고관리자",
                "user": {
                    "uuid": "a26219fc-94a0-4f63-9404-04c2a6ac99e4",
                    "name": "최고관리자",
                    "email": "heuristing@gmail.com"
                },
                "ip_address": "127.0.0.1",
                "created_at": "2026-08-04 19:00:10",
                "is_owner": true,
                "abilities": {
                    "can_read": true,
                    "can_delete": true
                }
            },
            {
                "number": 88815,
                "id": 89117,
                "log_type": "admin",
                "log_type_label": "관리자",
                "loggable_type": "App\\Models\\TemplateLayout",
                "loggable_type_display": "TemplateLayout",
                "loggable_id": 62,
                "action": "layout.update",
                "action_label": "수정",
                "localized_description": "레이아웃 수정 (home)",
                "description_key": "activity_log.description.layout_update",
                "properties": null,
                "changes": null,
                "bulk_changes": null,
                "has_changes": false,
                "actor_name": "최고관리자",
                "user": {
                    "uuid": "a26219fc-94a0-4f63-9404-04c2a6ac99e4",
                    "name": "최고관리자",
                    "email": "heuristing@gmail.com"
                },
                "ip_address": "127.0.0.1",
                "created_at": "2026-08-04 17:40:37",
                "is_owner": true,
                "abilities": {
                    "can_read": true,
                    "can_delete": true
                }
            },
            "... (총 25건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 3553,
            "per_page": 25,
            "total": 88816,
            "from": 1,
            "to": 25,
            "has_more_pages": true
        },
        "abilities": {
            "can_delete": true
        }
    }
}
```

**커서 방식 응답 (cursor 파라미터를 준 경우)**

페이지 번호 대신 앞뒤 커서를 싣습니다. 총 건수를 세지 않으므로 `total` 과 `last_page` 는 없습니다.

```json
{
    "success": true,
    "data": {
        "data": [],
        "pagination": {
            "per_page": 25,
            "next_cursor": "eyJpZCI6MTIzLCJfcG9pbnRzVG9OZXh0SXRlbXMiOnRydWV9",
            "prev_cursor": null,
            "has_more_pages": true
        }
    }
}
```

총 건수가 상한을 넘겨 정확히 세지 못한 경우(페이지 번호 방식)에는 `pagination` 에 `total_relation`·`total_is_exact`·`result_cap` 이 함께 실리고 `last_page` 가 `null` 이 됩니다. 상세는 [pagination.md](../pagination.md).

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.activities.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 시스템 활동 로그를 페이지네이션 목록으로 조회합니다. `log_type`(admin/user/system), `action`, `user_id`, `loggable_type`, 기간(`date_from`/`date_to`), 키워드(`search`) 등으로 필터링하고 `sort_by`/`sort_order`로 정렬합니다. null 값 필터는 자동으로 제외됩니다. `core.activities.read` 권한이 필요하며, 각 항목에는 현지화된 액션 라벨·변경 이력(changes)·소유자/권한 메타가 포함됩니다. 확장은 `core.activity_log.index_validation_rules` 훅으로 필터 파라미터를 추가할 수 있습니다.


### POST /api/admin/activity-logs/bulk-delete
<!-- @generated:start:api.admin.activity-logs.bulk-destroy -->
- **라우트명**: `api.admin.activity-logs.bulk-destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ActivityLogController@bulkDestroy`
- **인증/권한**: `auth:sanctum` + `permission:core.activities.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 삭제할 활동 로그 ID 배열 (원소는 integer 이며 `activity_logs.id` 에 존재해야 함) |

**요청 예시**

```http
POST /api/admin/activity-logs/bulk-delete HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ]
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `3` | 실제로 삭제된 활동 로그 건수 (`ActivityLogService::deleteMany()` 반환값 — 요청한 `ids` 중 삭제에 성공한 개수) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "선택한 활동 로그가 삭제되었습니다.",
    "data": {
        "deleted_count": 3
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.activities.delete`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 지정한 활동 로그들을 일괄 삭제합니다. `ids` 배열에 삭제할 로그 ID를 담아 요청하며, 서비스가 각 항목을 삭제하고 실제 삭제된 건수(`deleted_count`)를 반환합니다. `core.activities.delete` 권한이 필요합니다. 로그 목록에서 여러 항목을 선택해 한 번에 정리하는 시나리오에 사용합니다.


### DELETE /api/admin/activity-logs/{activityLog}
<!-- @generated:start:api.admin.activity-logs.destroy -->
- **라우트명**: `api.admin.activity-logs.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ActivityLogController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:core.activities.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| activityLog | path | string | 예 | — | 대상 activity log의 식별자 |

**요청 예시**

```http
DELETE /api/admin/activity-logs/{activityLog} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — 컨트롤러가 `success('activity_log.delete_success')` 를 데이터 인자 없이 호출하여 `data` 는 `null`)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "활동 로그가 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.activities.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 단일 활동 로그를 삭제합니다. 경로의 `{activityLog}`는 라우트 모델 바인딩으로 로그 ID를 받아 해당 레코드를 삭제합니다. `core.activities.delete` 권한이 필요하며, 삭제 실패 시 오류가 로그로 기록되고 500이 반환됩니다.


