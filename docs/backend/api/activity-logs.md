# Activity Logs API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Activity Logs 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
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
| number | integer | `87675` | 목록에서의 순번 (페이지네이션 반영 행 번호 — HasRowNumber 파생) |
| id | integer | `210842` | 기본 키 (내부 식별자) |
| log_type | string | `user` | 로그 유형 (admin: 관리자, user: 사용자, system: 시스템) |
| log_type_label | string | `사용자` | `log_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| loggable_type | string | `Modules\Sirsoft\Board\Models\Attachment` | 로그가 연관된 대상 리소스의 모델 클래스 FQCN (loggable 다형성 관계 타입) |
| loggable_type_display | string | `Attachment` | `loggable_type` 의 표시용 짧은 이름 (네임스페이스 제외 클래스명 파생) |
| loggable_id | integer | `155` | loggable 식별자 (연관 리소스 참조) |
| action | string | `attachment.download` | 액션 유형 (created, updated, deleted, login, export 등) |
| action_label | string | `다운로드` | `action` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| localized_description | string | `첨부파일 다운로드 (게시물: 237)` | `description` 의 현재 로케일 해석 값 (다국어 필드를 표시용 문자열로 해석) |
| description_key | string | `sirsoft-board::activity_log.descripti…` | 다국어 번역 키 (예: activity_log.description.user_create) |
| properties | object | `{"original_filename":"apidoc-sample.png","post_id":237,"c…` | 변경 상세 데이터 (old/new 값) |
| changes | object | `{"status":{"old":"inactive","new":"active","label":""}}` | 구조화된 변경 이력 (필드별 label_key, old, new, type) |
| bulk_changes | null | `null` | 일괄 수정 로그의 모델별 변경 이력 배열 (원소: model_id + changes[]). 단일 수정 로그이면 null이고 대신 changes 필드가 채워짐 |
| has_changes | boolean | `false` | changes 여부 |
| actor_name | string | `API 문서 샘플 사용자` | 행위를 수행한 주체(사용자/시스템)의 이름 |
| user | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 행위를 수행한 사용자 정보 (uuid/name/email). 시스템 발생 로그로 사용자가 없으면 name 에 "시스템" 라벨만 담김 |
| ip_address | string | `127.0.0.1` | IP 주소 (IPv6 대응) |
| created_at | string | `2026-07-07 10:00:47` | 생성 일시 |
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
                "number": 22,
                "id": 22,
                "log_type": "admin",
                "log_type_label": "관리자",
                "loggable_type": null,
                "loggable_type_display": null,
                "loggable_id": null,
                "action": "user.index",
                "action_label": "목록 조회",
                "localized_description": "사용자 목록 조회",
                "description_key": "activity_log.description.user_index",
                "properties": {
                    "result_count": 2
                },
                "changes": null,
                "bulk_changes": null,
                "has_changes": false,
                "actor_name": "API 문서 샘플 사용자",
                "user": {
                    "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                    "name": "API 문서 샘플 사용자",
                    "email": "apidoc-sample-user@example.com"
                },
                "ip_address": "127.0.0.1",
                "created_at": "2026-07-08 12:12:12",
                "is_owner": true,
                "abilities": {
                    "can_read": true,
                    "can_delete": true
                }
            },
            {
                "number": 21,
                "id": 21,
                "log_type": "admin",
                "log_type_label": "관리자",
                "loggable_type": null,
                "loggable_type_display": null,
                "loggable_id": null,
                "action": "user.index",
                "action_label": "목록 조회",
                "localized_description": "사용자 목록 조회",
                "description_key": "activity_log.description.user_index",
                "properties": {
                    "result_count": 2
                },
                "changes": null,
                "bulk_changes": null,
                "has_changes": false,
                "actor_name": "API 문서 샘플 사용자",
                "user": {
                    "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                    "name": "API 문서 샘플 사용자",
                    "email": "apidoc-sample-user@example.com"
                },
                "ip_address": "127.0.0.1",
                "created_at": "2026-07-08 12:08:26",
                "is_owner": true,
                "abilities": {
                    "can_read": true,
                    "can_delete": true
                }
            },
            "... (총 22건 중 2건 표시)"
        ],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 22,
            "from": 1,
            "to": 22,
            "has_more_pages": false
        },
        "abilities": {
            "can_delete": true
        }
    }
}
```

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
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |

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

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: http-422 — 응답 예시는 사람이 작성하세요. -->

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
DELETE /api/admin/activity-logs/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)



<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

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

<!-- @generated:end -->

**설명** 단일 활동 로그를 삭제합니다. 경로의 `{activityLog}`는 라우트 모델 바인딩으로 로그 ID를 받아 해당 레코드를 삭제합니다. `core.activities.delete` 권한이 필요하며, 삭제 실패 시 오류가 로그로 기록되고 500이 반환됩니다.


