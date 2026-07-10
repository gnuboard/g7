# Schedules API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Schedules 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 실측 응답 필드 표
3. 응답 필드의 예시값은 실제 호출 응답에서 관측된 값입니다
4. 갱신: 코드 변경 후 php artisan api:docgen 재실행
5. 설명(TODO) 칸은 사람이 채웁니다
```

---


### GET /api/admin/schedules
<!-- @generated:start:api.admin.schedules.index -->
- **라우트명**: `api.admin.schedules.index`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@index`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| filters | query | array | 아니오 | max 10 | 추가 필터 조건 맵 (필드별 조건) |
| type | query | string | 아니오 | `artisan`, `shell`, `url` | 유형 필터 (해당 유형의 항목만 조회) |
| frequency | query | string | 아니오 | `everyMinute`, `hourly`, `daily`, `weekly`, `monthly`, `custom` | 실행 주기 |
| status | query | string | 아니오 | `active`, `inactive` | 상태 필터 (해당 상태의 항목만 조회) |
| last_result | query | string | 아니오 | `success`, `failed`, `running`, `never` | 마지막 실행 결과 필터: success(성공), failed(실패), running(실행중), never(미실행) 중 해당 결과인 스케줄만 조회 |
| without_overlapping | query | string | 아니오 | `0`, `1` | 중복 실행 방지 여부 |
| run_in_maintenance | query | string | 아니오 | `0`, `1` | 점검 모드 중 실행 여부 |
| extension_type | query | string | 아니오 | `core`, `module`, `plugin` | 확장 유형 (core/module/plugin/template) |
| extension_identifier | query | string | 아니오 | max 255 | 확장 식별자 |
| created_from | query | date | 아니오 | — | 생성일 범위의 시작일 (이 날짜 이후 생성된 스케줄만 조회) |
| created_to | query | date | 아니오 | — | 생성일 범위의 종료일 (이 날짜 이전 생성된 스케줄만 조회, created_from 이후여야 함) |
| sort_by | query | string | 아니오 | `created_at`, `name`, `next_run_at`, `last_run_at`, `is_active`, `last_result` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.schedule.list_validation_rules`).

**요청 예시**

```http
GET /api/admin/schedules?page=1&per_page=1&filters=%EC%98%88%EC%8B%9C%EA%B0%92&type=artisan&frequency=everyMinute&status=active&last_result=success&without_overlapping=0&run_in_maintenance=0&extension_type=core&extension_identifier=example-key&created_from=2026-01-01&created_to=2026-01-01&sort_by=created_at&sort_order=asc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | string | `API 문서 샘플 스케줄` | 작업명 |
| type | string | `artisan` | 작업 유형: artisan(Artisan 커맨드), shell(쉘 명령), url(URL 호출) |
| type_label | string | `Artisan 커맨드` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| command | string | `cache:clear` | 명령어 또는 URL |
| expression | string | `0 3 * * *` | Cron 표현식 |
| frequency | string | `daily` | 실행 주기: everyMinute(매분), hourly(매시간), daily(매일), weekly(매주), monthly(매월), custom(사용자 정의) |
| frequency_label | string | `매일` | `frequency` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| without_overlapping | boolean | `true` | 중복 실행 방지 여부: 0(허용), 1(방지) |
| run_in_maintenance | boolean | `false` | 점검 모드 실행 여부: 0(비실행), 1(실행) |
| is_active | boolean | `true` | active 여부 |
| last_result | string | `success` | 마지막 실행 결과: success(성공), failed(실패), running(실행중), never(미실행) |
| last_result_label | string | `성공` | `last_result` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| last_run_at | string | `2026-07-05T19:20:23+09:00` | last run 일시 |
| last_duration | null | `null` | 마지막 실행의 소요 시간을 사람이 읽는 문자열로 포맷한 값 (예: "45초", "2분 3초" — 마지막 실행 이력의 duration 파생, 실행 이력이 없으면 null) |
| next_run_at | string | `2026-07-07T12:00:00+09:00` | next run 일시 |
| creator | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| created_at | string | `2026-07-06` | 생성 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true,"c…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "스케줄 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 1,
                "name": "API 문서 샘플 스케줄",
                "type": "artisan",
                "type_label": "Artisan 커맨드",
                "command": "cache:clear",
                "expression": "0 3 * * *",
                "frequency": "daily",
                "frequency_label": "매일",
                "without_overlapping": true,
                "run_in_maintenance": false,
                "is_active": true,
                "last_result": "success",
                "last_result_label": "성공",
                "last_run_at": "2026-07-07T10:41:24+09:00",
                "last_duration": null,
                "next_run_at": "2026-07-08T12:00:00+09:00",
                "creator": {
                    "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
                    "name": "API 문서 샘플 사용자"
                },
                "created_at": "2026-07-08",
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": true,
                    "can_run": true
                }
            }
        ],
        "statistics": {
            "total": 1,
            "active": 1,
            "inactive": 0,
            "success": 1,
            "failed": 0,
            "running": 0,
            "never_run": 0
        },
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 1,
            "from": 1,
            "to": 1,
            "has_more_pages": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 예약 작업(스케줄) 목록을 페이지네이션과 함께 조회하며, 응답에는 상태별 통계도 포함됩니다. `core.schedules.read` 권한이 필요합니다. `type`/`frequency`/`status`/`last_result`/`extension_type` 등으로 필터링하고 `sort_by`/`sort_order` 로 정렬할 수 있습니다. 관리자 스케줄 관리 화면의 목록·필터·요약 카드 표시에 사용합니다.


### POST /api/admin/schedules
<!-- @generated:start:api.admin.schedules.store -->
- **라우트명**: `api.admin.schedules.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@store`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | string | 예 | max 255 | 대상의 이름/명칭 |
| description | body | string | 아니오 | max 1000 | 설명 |
| type | body | string | 예 | `artisan`, `shell`, `url` | 작업 유형: artisan(Artisan 커맨드 실행), shell(쉘 명령 실행), url(URL 호출) |
| command | body | string | 예 | max 2000 | 실행할 아티즌 커맨드 |
| expression | body | string | 예 | max 100 | 실행 시각을 정의하는 Cron 표현식 (예: `0 3 * * *`, 다음 실행 시각 next_run_at 계산의 기준) |
| frequency | body | string | 예 | `everyMinute`, `hourly`, `daily`, `weekly`, `monthly`, `custom` | 실행 주기 |
| without_overlapping | body | boolean | 아니오 | — | 중복 실행 방지 여부 |
| run_in_maintenance | body | boolean | 아니오 | — | 점검 모드 중 실행 여부 |
| timeout | body | integer | 아니오 | min 1, max 86400 | 타임아웃 (초) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| extension_type | body | string | 아니오 | `core`, `module`, `plugin` | 확장 유형 (core/module/plugin/template) |
| extension_identifier | body | string | 아니오 | max 255 | 확장 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.schedule.create_validation_rules`).

**요청 예시**

```http
POST /api/admin/schedules HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": "예시 이름",
    "description": "예시 내용입니다.",
    "type": "artisan",
    "command": "예시값",
    "expression": "예시값",
    "frequency": "everyMinute",
    "without_overlapping": true,
    "run_in_maintenance": true,
    "timeout": 1,
    "is_active": true,
    "extension_type": "core",
    "extension_identifier": "example-key"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `14` | 기본 키 (내부 식별자) |
| name | string | `실측 예시값` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | string | `실측 예시값` | 설명 (다국어 필드는 로케일별 값 객체) |
| type | string | `artisan` | 작업 유형: artisan(Artisan 커맨드), shell(쉘 명령), url(URL 호출) |
| type_label | string | `Artisan 커맨드` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| command | string | `실측 예시값` | 명령어 또는 URL |
| expression | string | `실측 예시값` | Cron 표현식 |
| frequency | string | `everyMinute` | 실행 주기: everyMinute(매분), hourly(매시간), daily(매일), weekly(매주), monthly(매월), custom(사용자 정의) |
| frequency_label | string | `매분` | `frequency` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| without_overlapping | boolean | `true` | 중복 실행 방지 여부: 0(허용), 1(방지) |
| run_in_maintenance | boolean | `true` | 점검 모드 실행 여부: 0(비실행), 1(실행) |
| timeout | integer | `1` | 실행 제한 시간 (초) |
| is_active | boolean | `true` | active 여부 |
| last_result | string | `never` | 마지막 실행 결과: success(성공), failed(실패), running(실행중), never(미실행) |
| last_result_label | string | `미실행` | `last_result` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| last_duration | null | `null` | 마지막 실행 소요 시간 (초/밀리초 — 실행 이력 파생) |
| extension_type | string | `core` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | string | `probe_6a4dc0a862b69` | 이 리소스를 소유한 확장의 식별자 |
| creator | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"AP…` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| created_at | string | `2026-07-08 12:14:48` | 생성 일시 |
| updated_at | string | `2026-07-08 12:14:48` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true,"c…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "스케줄이 생성되었습니다.",
    "data": {
        "id": 14,
        "name": "실측 예시값",
        "description": "실측 예시값",
        "type": "artisan",
        "type_label": "Artisan 커맨드",
        "command": "실측 예시값",
        "expression": "실측 예시값",
        "frequency": "everyMinute",
        "frequency_label": "매분",
        "without_overlapping": true,
        "run_in_maintenance": true,
        "timeout": 1,
        "is_active": true,
        "last_result": "never",
        "last_result_label": "미실행",
        "last_duration": null,
        "extension_type": "core",
        "extension_identifier": "probe_6a4dc0a862b69",
        "creator": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자"
        },
        "created_at": "2026-07-08 12:14:48",
        "updated_at": "2026-07-08 12:14:48",
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_run": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.create`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 새 예약 작업을 생성합니다. `core.schedules.create` 권한이 필요합니다. `type`(artisan/shell/url), `command`, `expression`, `frequency` 를 지정하며 생성자(creator)는 현재 사용자로 자동 기록됩니다. 검증 실패 시 422로 응답하고, 성공 시 201과 생성된 스케줄 리소스를 반환합니다. 관리자 스케줄 등록 폼에 사용합니다.


### DELETE /api/admin/schedules/bulk
<!-- @generated:start:api.admin.schedules.bulk-delete -->
- **라우트명**: `api.admin.schedules.bulk-delete`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@bulkDelete`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.schedule.bulk_delete_validation_rules`).

**요청 예시**

```http
DELETE /api/admin/schedules/bulk?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
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
| 403 | Forbidden | 요구 권한(`core.schedules.delete`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 전달된 `ids` 배열의 스케줄을 일괄 삭제합니다. `core.schedules.delete` 권한이 필요합니다. 삭제 처리 결과 요약을 반환하며, 검증 실패 시 422로 응답합니다. 목록에서 여러 스케줄을 선택해 한 번에 제거하는 동작에 사용합니다.


### PATCH /api/admin/schedules/bulk-status
<!-- @generated:start:api.admin.schedules.bulk-status -->
- **라우트명**: `api.admin.schedules.bulk-status`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@bulkUpdateStatus`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | body | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| is_active | body | boolean | 예 | — | 활성 여부 (true 활성 / false 비활성) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.schedule.bulk_update_status_validation_rules`).

**요청 예시**

```http
PATCH /api/admin/schedules/bulk-status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "ids": [
        "예시값"
    ],
    "is_active": true
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
| 403 | Forbidden | 요구 권한(`core.schedules.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 전달된 `ids` 배열의 스케줄 활성 상태(`is_active`)를 일괄 변경합니다. `core.schedules.update` 권한이 필요합니다. 처리 결과 요약을 반환하며, 검증 실패 시 422로 응답합니다. 목록에서 여러 스케줄을 선택해 한 번에 활성화/비활성화하는 동작에 사용합니다.


### DELETE /api/admin/schedules/history/{historyId}
<!-- @generated:start:api.admin.schedules.delete-history -->
- **라우트명**: `api.admin.schedules.delete-history`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@deleteHistory`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| historyId | path | string | 예 | — | 대상 history의 식별자 |

**요청 예시**

```http
DELETE /api/admin/schedules/history/{historyId} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

<!-- 실측 제외: write-method — 응답 필드는 사람이 작성하세요. -->

**응답 예시**

<!-- 실측 제외: unresolved-path-param — 응답 예시는 사람이 작성하세요. -->

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 실행 이력 레코드를 삭제합니다. `core.schedules.delete` 권한이 필요합니다. 대상 이력이 없으면 404로 응답합니다. 스케줄 상세의 실행 이력 목록에서 개별 이력을 제거할 때 사용합니다.


### GET /api/admin/schedules/statistics
<!-- @generated:start:api.admin.schedules.statistics -->
- **라우트명**: `api.admin.schedules.statistics`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@statistics`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/schedules/statistics HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| total | integer | `1` | 전체 개수 (집계) |
| active | integer | `1` | 활성(is_active=true) 스케줄 수 |
| inactive | integer | `0` | 비활성(is_active=false) 스케줄 수 |
| success | integer | `1` | 마지막 실행 결과가 성공(success)인 스케줄 수 |
| failed | integer | `0` | 마지막 실행 결과가 실패(failed)인 스케줄 수 |
| running | integer | `0` | 마지막 실행 결과가 실행중(running)인 스케줄 수 |
| never_run | integer | `0` | 아직 한 번도 실행되지 않은(last_result=never) 스케줄 수 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "스케줄 통계를 조회했습니다.",
    "data": {
        "total": 1,
        "active": 1,
        "inactive": 0,
        "success": 1,
        "failed": 0,
        "running": 0,
        "never_run": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.read`)이 없는 경우 |

<!-- @generated:end -->

**설명** 스케줄 전체의 집계 통계를 조회합니다. `core.schedules.read` 권한이 필요합니다. 전체/활성/비활성 수와 마지막 실행 결과별(성공/실패/실행중/미실행) 건수를 반환합니다. 관리자 스케줄 대시보드의 요약 카드 표시에 사용합니다.


### DELETE /api/admin/schedules/{schedule}
<!-- @generated:start:api.admin.schedules.destroy -->
- **라우트명**: `api.admin.schedules.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| schedule | path | string | 예 | — | 대상 schedule의 식별자 |

**요청 예시**

```http
DELETE /api/admin/schedules/1 HTTP/1.1
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
    "message": "스케줄이 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.delete`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 단일 스케줄을 삭제합니다. `core.schedules.delete` 권한이 필요합니다. 경로의 `schedule` 은 라우트 모델 바인딩으로 해석되어 존재하지 않으면 404가 됩니다. 관리자 스케줄 관리 화면의 개별 삭제 동작에 사용합니다.


### GET /api/admin/schedules/{schedule}
<!-- @generated:start:api.admin.schedules.show -->
- **라우트명**: `api.admin.schedules.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@show`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| schedule | path | string | 예 | — | 대상 schedule의 식별자 |

**요청 예시**

```http
GET /api/admin/schedules/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | string | `API 문서 샘플 스케줄` | 작업명 |
| description | string | `문서 실측용 스케줄` | 설명 |
| type | string | `artisan` | 작업 유형: artisan(Artisan 커맨드), shell(쉘 명령), url(URL 호출) |
| type_label | string | `Artisan 커맨드` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| command | string | `cache:clear` | 명령어 또는 URL |
| expression | string | `0 3 * * *` | Cron 표현식 |
| frequency | string | `daily` | 실행 주기: everyMinute(매분), hourly(매시간), daily(매일), weekly(매주), monthly(매월), custom(사용자 정의) |
| frequency_label | string | `매일` | `frequency` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| without_overlapping | boolean | `true` | 중복 실행 방지 여부: 0(허용), 1(방지) |
| run_in_maintenance | boolean | `false` | 점검 모드 실행 여부: 0(비실행), 1(실행) |
| timeout | integer | `300` | 실행 제한 시간 (초) |
| is_active | boolean | `true` | active 여부 |
| last_result | string | `success` | 마지막 실행 결과: success(성공), failed(실패), running(실행중), never(미실행) |
| last_result_label | string | `성공` | `last_result` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| last_run_at | string | `2026-07-05T19:20:23+09:00` | last run 일시 |
| last_duration | null | `null` | 마지막 실행의 소요 시간을 사람이 읽는 문자열로 포맷한 값 (예: "45초", "2분 3초" — 마지막 실행 이력의 duration 파생, 실행 이력이 없으면 null) |
| next_run_at | string | `2026-07-07T12:00:00+09:00` | next run 일시 |
| extension_type | null | `null` | 확장 소유 타입: core(코어), module(모듈), plugin(플러그인), NULL(사용자 정의) |
| extension_identifier | null | `null` | 확장 식별자 (예: core, sirsoft-board, sirsoft-payment) |
| creator | object | `{"uuid":"a231747f-e82e-4cf2-9ae1-a261849dce40","name":"AP…` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| created_at | string | `2026-07-06 19:20:23` | 생성 일시 |
| updated_at | string | `2026-07-06 19:20:23` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true,"c…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "스케줄 목록을 조회했습니다.",
    "data": {
        "id": 1,
        "name": "API 문서 샘플 스케줄",
        "description": "문서 실측용 스케줄",
        "type": "artisan",
        "type_label": "Artisan 커맨드",
        "command": "cache:clear",
        "expression": "0 3 * * *",
        "frequency": "daily",
        "frequency_label": "매일",
        "without_overlapping": true,
        "run_in_maintenance": false,
        "timeout": 300,
        "is_active": true,
        "last_result": "success",
        "last_result_label": "성공",
        "last_run_at": "2026-07-07T10:41:24+09:00",
        "last_duration": null,
        "next_run_at": "2026-07-08T12:00:00+09:00",
        "extension_type": null,
        "extension_identifier": null,
        "creator": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자"
        },
        "created_at": "2026-07-08 10:41:24",
        "updated_at": "2026-07-08 10:41:24",
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_run": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.read`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 단일 스케줄의 상세 정보를 조회하며 생성자(creator) 정보를 함께 로드합니다. `core.schedules.read` 권한이 필요합니다. 경로의 `schedule` 은 라우트 모델 바인딩으로 해석되어 없으면 404가 됩니다. 관리자 스케줄 상세/수정 화면의 초기 데이터 로딩에 사용합니다.


### PUT /api/admin/schedules/{schedule}
<!-- @generated:start:api.admin.schedules.update -->
- **라우트명**: `api.admin.schedules.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| schedule | path | string | 예 | — | 대상 schedule의 식별자 |
| name | body | string | 예 | max 255 | 대상의 이름/명칭 |
| description | body | string | 아니오 | max 1000 | 설명 |
| type | body | string | 예 | `artisan`, `shell`, `url` | 작업 유형: artisan(Artisan 커맨드 실행), shell(쉘 명령 실행), url(URL 호출) |
| command | body | string | 예 | max 2000 | 실행할 아티즌 커맨드 |
| expression | body | string | 예 | max 100 | 실행 시각을 정의하는 Cron 표현식 (예: `0 3 * * *`, 다음 실행 시각 next_run_at 계산의 기준) |
| frequency | body | string | 예 | `everyMinute`, `hourly`, `daily`, `weekly`, `monthly`, `custom` | 실행 주기 |
| without_overlapping | body | boolean | 아니오 | — | 중복 실행 방지 여부 |
| run_in_maintenance | body | boolean | 아니오 | — | 점검 모드 중 실행 여부 |
| timeout | body | integer | 아니오 | min 1, max 86400 | 타임아웃 (초) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| extension_type | body | string | 아니오 | `core`, `module`, `plugin` | 확장 유형 (core/module/plugin/template) |
| extension_identifier | body | string | 아니오 | max 255 | 확장 식별자 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.schedule.update_validation_rules`).

**요청 예시**

```http
PUT /api/admin/schedules/1 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": "예시 이름",
    "description": "예시 내용입니다.",
    "type": "artisan",
    "command": "예시값",
    "expression": "예시값",
    "frequency": "everyMinute",
    "without_overlapping": true,
    "run_in_maintenance": true,
    "timeout": 1,
    "is_active": true,
    "extension_type": "core",
    "extension_identifier": "example-key"
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | string | `실측 예시값` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | string | `실측 예시값` | 설명 (다국어 필드는 로케일별 값 객체) |
| type | string | `artisan` | 작업 유형: artisan(Artisan 커맨드), shell(쉘 명령), url(URL 호출) |
| type_label | string | `Artisan 커맨드` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| command | string | `실측 예시값` | 명령어 또는 URL |
| expression | string | `실측 예시값` | Cron 표현식 |
| frequency | string | `everyMinute` | 실행 주기: everyMinute(매분), hourly(매시간), daily(매일), weekly(매주), monthly(매월), custom(사용자 정의) |
| frequency_label | string | `매분` | `frequency` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| without_overlapping | boolean | `true` | 중복 실행 방지 여부: 0(허용), 1(방지) |
| run_in_maintenance | boolean | `true` | 점검 모드 실행 여부: 0(비실행), 1(실행) |
| timeout | integer | `1` | 실행 제한 시간 (초) |
| is_active | boolean | `true` | active 여부 |
| last_result | string | `success` | 마지막 실행 결과: success(성공), failed(실패), running(실행중), never(미실행) |
| last_result_label | string | `성공` | `last_result` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| last_run_at | string | `2026-07-07T10:41:24+09:00` | last run 일시 |
| last_duration | null | `null` | 마지막 실행 소요 시간 (초/밀리초 — 실행 이력 파생) |
| extension_type | string | `core` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | string | `probe_6a4dc0a8e44c0` | 이 리소스를 소유한 확장의 식별자 |
| creator | object | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"AP…` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| created_at | string | `2026-07-08 10:41:24` | 생성 일시 |
| updated_at | string | `2026-07-08 12:14:48` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true,"c…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "스케줄이 수정되었습니다.",
    "data": {
        "id": 1,
        "name": "실측 예시값",
        "description": "실측 예시값",
        "type": "artisan",
        "type_label": "Artisan 커맨드",
        "command": "실측 예시값",
        "expression": "실측 예시값",
        "frequency": "everyMinute",
        "frequency_label": "매분",
        "without_overlapping": true,
        "run_in_maintenance": true,
        "timeout": 1,
        "is_active": true,
        "last_result": "success",
        "last_result_label": "성공",
        "last_run_at": "2026-07-07T10:41:24+09:00",
        "last_duration": null,
        "extension_type": "core",
        "extension_identifier": "probe_6a4dc0a8e44c0",
        "creator": {
            "uuid": "a234c2b1-cde8-437f-b28b-23323be2b98d",
            "name": "API 문서 샘플 사용자"
        },
        "created_at": "2026-07-08 10:41:24",
        "updated_at": "2026-07-08 12:14:48",
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_run": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.update`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 기존 스케줄 정보를 수정합니다. `core.schedules.update` 권한이 필요합니다. store 와 동일한 필드(`type`/`command`/`expression`/`frequency` 등)를 받으며 검증 실패 시 422로 응답합니다. 경로의 `schedule` 은 라우트 모델 바인딩으로 해석되며, 수정된 스케줄 리소스를 반환합니다. 관리자 스케줄 수정 폼에 사용합니다.


### POST /api/admin/schedules/{schedule}/duplicate
<!-- @generated:start:api.admin.schedules.duplicate -->
- **라우트명**: `api.admin.schedules.duplicate`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@duplicate`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| schedule | path | string | 예 | — | 대상 schedule의 식별자 |

**요청 예시**

```http
POST /api/admin/schedules/1/duplicate HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `15` | 기본 키 (내부 식별자) |
| name | string | `API 문서 샘플 스케줄 (복사본)` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | string | `문서 실측용 스케줄` | 설명 (다국어 필드는 로케일별 값 객체) |
| type | string | `artisan` | 작업 유형: artisan(Artisan 커맨드), shell(쉘 명령), url(URL 호출) |
| type_label | string | `Artisan 커맨드` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| command | string | `cache:clear` | 명령어 또는 URL |
| expression | string | `0 3 * * *` | Cron 표현식 |
| frequency | string | `daily` | 실행 주기: everyMinute(매분), hourly(매시간), daily(매일), weekly(매주), monthly(매월), custom(사용자 정의) |
| frequency_label | string | `매일` | `frequency` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| without_overlapping | boolean | `true` | 중복 실행 방지 여부: 0(허용), 1(방지) |
| run_in_maintenance | boolean | `false` | 점검 모드 실행 여부: 0(비실행), 1(실행) |
| timeout | integer | `300` | 실행 제한 시간 (초) |
| is_active | boolean | `false` | active 여부 |
| last_result | string | `never` | 마지막 실행 결과: success(성공), failed(실패), running(실행중), never(미실행) |
| last_result_label | string | `미실행` | `last_result` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| last_duration | null | `null` | 마지막 실행 소요 시간 (초/밀리초 — 실행 이력 파생) |
| extension_type | null | `null` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | null | `null` | 이 리소스를 소유한 확장의 식별자 |
| created_at | string | `2026-07-08 12:14:48` | 생성 일시 |
| updated_at | string | `2026-07-08 12:14:48` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true,"c…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (can_update, can_delete 등 — 권한 맵 기반) |

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "스케줄이 복제되었습니다.",
    "data": {
        "id": 15,
        "name": "API 문서 샘플 스케줄 (복사본)",
        "description": "문서 실측용 스케줄",
        "type": "artisan",
        "type_label": "Artisan 커맨드",
        "command": "cache:clear",
        "expression": "0 3 * * *",
        "frequency": "daily",
        "frequency_label": "매일",
        "without_overlapping": true,
        "run_in_maintenance": false,
        "timeout": 300,
        "is_active": false,
        "last_result": "never",
        "last_result_label": "미실행",
        "last_duration": null,
        "extension_type": null,
        "extension_identifier": null,
        "created_at": "2026-07-08 12:14:48",
        "updated_at": "2026-07-08 12:14:48",
        "abilities": {
            "can_create": true,
            "can_update": true,
            "can_delete": true,
            "can_run": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.create`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 기존 스케줄을 복제해 새 스케줄을 만듭니다. `core.schedules.create` 권한이 필요합니다. 원본을 바탕으로 새 레코드를 생성하며, 성공 시 201과 복제된 스케줄 리소스를 반환합니다. 유사한 설정의 스케줄을 빠르게 추가하는 "복제" 동작에 사용합니다.


### GET /api/admin/schedules/{schedule}/history
<!-- @generated:start:api.admin.schedules.history -->
- **라우트명**: `api.admin.schedules.history`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@history`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| schedule | path | string | 예 | — | 대상 schedule의 식별자 |
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 |
| status | query | string | 아니오 | `success`, `failed`, `running` | 상태 필터 (해당 상태의 항목만 조회) |
| trigger_type | query | string | 아니오 | `scheduled`, `manual` | 실행 방식 필터: scheduled(예약 시각 자동 실행), manual(관리자의 즉시 실행) 중 해당 이력만 조회 |
| started_from | query | date | 아니오 | — | 실행 시작일 범위의 시작일 (이 날짜 이후 시작된 이력만 조회) |
| started_to | query | date | 아니오 | — | 실행 시작일 범위의 종료일 (이 날짜 이전 시작된 이력만 조회, started_from 이후여야 함) |
| sort_by | query | string | 아니오 | `started_at`, `ended_at`, `duration`, `status` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.schedule.history_list_validation_rules`).

**요청 예시**

```http
GET /api/admin/schedules/1/history?page=1&per_page=1&status=success&trigger_type=scheduled&started_from=2026-01-01&started_to=2026-01-01&sort_by=started_at&sort_order=asc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

<!-- 실측 응답에 필드 없음(빈 목록 등) — 데이터가 있는 상태로 재실측하거나 사람이 작성. -->

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "실행 이력을 조회했습니다.",
    "data": {
        "data": [],
        "pagination": {
            "current_page": 1,
            "last_page": 1,
            "per_page": 25,
            "total": 0,
            "from": null,
            "to": null,
            "has_more_pages": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 특정 스케줄의 실행 이력을 페이지네이션으로 조회합니다. `core.schedules.read` 권한이 필요합니다. `status`(success/failed/running), `trigger_type`(scheduled/manual), 기간(`started_from`/`started_to`)으로 필터링하고 `sort_by`/`sort_order` 로 정렬할 수 있습니다. 스케줄 상세의 실행 이력 탭 표시에 사용합니다.


### POST /api/admin/schedules/{schedule}/run
<!-- @generated:start:api.admin.schedules.run -->
- **라우트명**: `api.admin.schedules.run`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@run`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.run`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| schedule | path | string | 예 | — | 대상 schedule의 식별자 |

**요청 예시**

```http
POST /api/admin/schedules/1/run HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `7` | 기본 키 (내부 식별자) |
| schedule_id | integer | `1` | schedule 식별자 (연관 리소스 참조) |
| started_at | string | `2026-07-08T12:14:49+09:00` | started 일시 |
| ended_at | string | `2026-07-08T12:14:49+09:00` | ended 일시 |
| duration | integer | `0` | 실행 소요 시간 (초/밀리초) |
| duration_formatted | null | `null` | `duration` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| status | string | `success` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `성공` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| exit_code | integer | `0` | 실행 종료 코드 (0=성공, 그 외=실패) |
| memory_usage | integer | `427352` | 실행 중 최대 메모리 사용량 (바이트) |
| memory_usage_formatted | string | `417.34 KB` | `memory_usage` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| output | string | `    INFO  Application cache cleared s…` | 실행 표준 출력 내용 |
| error_output | null | `null` | 실행 표준 에러 출력 내용 (없으면 빈 문자열/null) |
| trigger_type | string | `manual` | 동작을 유발한 방식/주체 구분 값 |
| trigger_type_label | string | `수동 실행` | `trigger_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| created_at | string | `2026-07-08 12:14:49` | 생성 일시 |
| updated_at | string | `2026-07-08 12:14:49` | 최종 수정 일시 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "스케줄이 실행되었습니다.",
    "data": {
        "id": 7,
        "schedule_id": 1,
        "started_at": "2026-07-08T12:14:49+09:00",
        "ended_at": "2026-07-08T12:14:49+09:00",
        "duration": 0,
        "duration_formatted": null,
        "status": "success",
        "status_label": "성공",
        "exit_code": 0,
        "memory_usage": 427352,
        "memory_usage_formatted": "417.34 KB",
        "output": "\n   INFO  Application cache cleared successfully.  \n\r\n",
        "error_output": null,
        "trigger_type": "manual",
        "trigger_type_label": "수동 실행",
        "created_at": "2026-07-08 12:14:49",
        "updated_at": "2026-07-08 12:14:49"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.run`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |

<!-- @generated:end -->

**설명** 스케줄을 예약 시각과 무관하게 즉시 실행합니다. `core.schedules.run` 권한이 필요합니다. 실제 명령이 수행되고 실행 이력 레코드가 생성되며, 그 이력(trigger_type=manual)을 반환합니다. 관리자가 대상 작업을 수동으로 즉시 돌려 결과를 확인하는 "지금 실행" 동작에 사용합니다.


