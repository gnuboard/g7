# Queue API 레퍼런스

> **소유**: 코어 · 스케줄 관리 화면의 큐 탭이 쓰는 관리자 API 입니다. 코드 기준으로 작성했으며, 응답 예시의 값은 설명용 예시입니다.

---

## TL;DR (5초 요약)

```text
1. 경로는 /api/admin/schedules/queue/* (라우트명 api.admin.schedules.queue.*) — 엔드포인트 10개
2. 권한: 조회 core.queue_jobs.read · 재시도 core.queue_jobs.retry · 삭제 core.queue_jobs.delete
3. 대기 작업은 database 드라이버만 셀 수 있다 — 그 밖의 드라이버는 빈 목록 + measurable=false
4. 실패 작업 재시도: 미등록 연결 → 기본 연결, 미등록 큐 → 그 연결의 기본 큐로 넣는다. 해석된 연결의 드라이버가 sync·null 이면 422
5. 일괄 재시도·삭제는 요청당 최대 100건(101건 이상 422), 일부 실패여도 200 + 건별 결과
6. 실패 작업 상세는 예외 전문과 payload 메타만 준다 — 직렬화된 작업 객체는 싣지 않는다
```

---

## 개요

스케줄 관리 화면의 큐 탭이 쓰는 API 입니다. 다음을 다룹니다.

- **상태**: 워커 상태 요약(기본 연결·드라이버·대기/실패 건수·워커 수)과 환경설정 > 드라이버 탭의 「큐 워커 상태」 보고서 전문
- **대기 작업**: 아직 처리되지 않은 작업 목록 — 큐 표를 직접 읽을 수 있는 `database` 드라이버에서만 셀 수 있습니다
- **실패 작업**: 최종 실패해 실패 작업 표로 옮겨진 작업의 조회·재시도·삭제
- **처리 기록**: 워커가 처리를 마친 작업마다 남는 성공·실패 기록

공통 사항

- 모든 엔드포인트는 `auth:sanctum` 인증과 아래 표의 권한이 필요합니다.
- 목록 응답은 `data.data[]` + `data.pagination` 입니다. 총 건수가 상한을 넘으면 `pagination` 에 `total_relation`·`total_is_exact`·`result_cap` 이 실리고 `last_page` 가 `null` 이 됩니다. 상세는 [pagination.md](../pagination.md).
- 목록의 `queue`·`connection`·`status` 쿼리 값 `all` 은 「전체」(조건 없음)입니다.
- 날짜 범위(`*_start_date`·`*_end_date`)는 보는 사람의 시간대 하루로 해석합니다. 종료일은 시작일과 같거나 그 뒤여야 합니다.
- 시각 필드는 보는 사람의 시간대로 바꾼 `Y-m-d H:i:s` 문자열입니다.
- 보관 기간: 처리 기록과 실패 작업은 환경설정 > 드라이버 「처리 기록 보관 기간」(기본 7일, 1~90일)이 지나면 매일 새벽 정리됩니다(`php artisan queue:prune-records`).

| 메서드 | 경로 | 라우트명 | 권한 |
| --- | --- | --- | --- |
| GET | `/api/admin/schedules/queue/status` | `api.admin.schedules.queue.status` | `core.queue_jobs.read` |
| GET | `/api/admin/schedules/queue/pending` | `api.admin.schedules.queue.pending` | `core.queue_jobs.read` |
| GET | `/api/admin/schedules/queue/failed` | `api.admin.schedules.queue.failed` | `core.queue_jobs.read` |
| GET | `/api/admin/schedules/queue/failed/{uuid}` | `api.admin.schedules.queue.failed.show` | `core.queue_jobs.read` |
| POST | `/api/admin/schedules/queue/failed/{uuid}/retry` | `api.admin.schedules.queue.failed.retry` | `core.queue_jobs.retry` |
| POST | `/api/admin/schedules/queue/failed/retry` | `api.admin.schedules.queue.failed.retry-bulk` | `core.queue_jobs.retry` |
| DELETE | `/api/admin/schedules/queue/failed/{uuid}` | `api.admin.schedules.queue.failed.destroy` | `core.queue_jobs.delete` |
| DELETE | `/api/admin/schedules/queue/failed` | `api.admin.schedules.queue.failed.destroy-bulk` | `core.queue_jobs.delete` |
| GET | `/api/admin/schedules/queue/logs` | `api.admin.schedules.queue.logs` | `core.queue_jobs.read` |
| GET | `/api/admin/schedules/queue/logs/{id}` | `api.admin.schedules.queue.logs.show` | `core.queue_jobs.read` |

---


### GET /api/admin/schedules/queue/status
- **라우트명**: `api.admin.schedules.queue.status`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\JobQueueController@status`
- **인증/권한**: `auth:sanctum` + `permission:core.queue_jobs.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/schedules/queue/status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| driver | string\|null | `database` | 기본 연결의 드라이버 (`database`·`redis`·`sync` 등, 연결 정의가 없으면 null) |
| connection | string | `database` | 기본 큐 연결 이름 |
| measurable | boolean | `true` | 대기 건수를 셀 수 있는지 — 기본 연결 드라이버가 `database` 일 때만 true |
| pending_count | integer\|null | `3` | 기본 연결의 대기 작업 수. `measurable=false` 면 null |
| failed_count | integer | `2` | 실패 작업 표의 전체 건수 |
| workers | object | `{"registered":2,"alive":1,…}` | 워커 요약 |
| workers.registered | integer | `2` | 등록된 연결·큐 쌍 수 (보고서 `rows` 행 수) |
| workers.alive | integer | `1` | 경고 없이 신호가 들어오는 연결·큐 쌍 수 |
| workers.warning_count | integer | `1` | 경고가 있는 연결·큐 쌍 수 (`registered - alive`) |
| workers.heartbeat_stale_after_minutes | integer\|null | `5` | 이 시간 넘게 워커 신호가 없으면 경고로 봅니다 |
| workers.long_worker | string\|null | `not_registered` | 긴 작업 전용 워커 등록 단계 — `registered` · `partial` · `not_registered` |
| report | object | `{"driver":"database","rows":[…],…}` | 환경설정 > 드라이버 「큐 워커」 구역의 상태 보고서 전문 — 필드는 [settings.md 의 `GET /api/admin/settings/queue-status`](settings.md) 응답과 같습니다 |
| entries | array | `[{"connection":"database","queue":"default"}]` | 등록된 연결·큐 쌍 목록. 등록이 없으면 기본 연결의 `default` 큐 한 쌍 |
| connections | string[] | `["database"]` | 등록된 연결 이름 목록 (기본 연결이 sync 면 빈 배열일 수 있음) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "큐 상태를 조회했습니다.",
    "data": {
        "driver": "database",
        "connection": "database",
        "measurable": true,
        "pending_count": 3,
        "failed_count": 2,
        "workers": {
            "registered": 2,
            "alive": 1,
            "warning_count": 1,
            "heartbeat_stale_after_minutes": 5,
            "long_worker": "not_registered"
        },
        "report": {
            "driver": "database",
            "measured_at": "2026-10-16T10:00:00+09:00",
            "heartbeat_stale_after_minutes": 5,
            "rows": [
                {
                    "connection": "database",
                    "queue": "default",
                    "driver": "database",
                    "pending": 3,
                    "pending_measurable": true,
                    "last_heartbeat_at": "2026-10-16T09:59:30+09:00",
                    "warnings": [],
                    "clamped_timeout_count": 0,
                    "long_queue_hint": false
                },
                {
                    "connection": "database",
                    "queue": "notifications",
                    "driver": "database",
                    "pending": 0,
                    "pending_measurable": true,
                    "last_heartbeat_at": null,
                    "warnings": ["no_heartbeat"],
                    "clamped_timeout_count": 0,
                    "long_queue_hint": false
                }
            ],
            "long_worker": {
                "connection": "database-long",
                "queue": "long",
                "status": "not_registered"
            },
            "commands": [
                {
                    "connection": "database",
                    "queues": "default,notifications",
                    "command": "php artisan queue:work database --queue=default,notifications --sleep=3 --tries=3 --max-time=3600"
                }
            ],
            "placement": {
                "timeout_clamped": {"count": 0, "last": null},
                "fallback": {"count": 0, "last": null}
            }
        },
        "entries": [
            {"connection": "database", "queue": "default"},
            {"connection": "database", "queue": "notifications"}
        ],
        "connections": ["database"]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.queue_jobs.read`)이 없는 경우 |

**설명** 큐 탭 머리의 상태 요약과 워커 상태 보고서를 한 번에 조회합니다. `driver`~`workers` 는 스케줄 관리 화면 상태 카드의 큐 요약(`GET /api/admin/schedules/status` 의 `data.queue`)과 같은 값이고, 여기에 보고서 전문(`report`)과 등록된 연결·큐(`entries`·`connections`)가 더해집니다. 큐 탭의 연결·큐 필터 선택지는 `entries`·`connections` 로 만듭니다. 기본 드라이버가 `sync`·`null` 이면 실패 작업을 되넣을 워커가 없으므로, 화면은 `driver` 로 재시도 불가 안내를 띄울 수 있습니다.


### GET /api/admin/schedules/queue/pending
- **라우트명**: `api.admin.schedules.queue.pending`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\JobQueueController@pending`
- **인증/권한**: `auth:sanctum` + `permission:core.queue_jobs.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 (상한은 페이지 번호 한도 설정) | 조회할 페이지 번호 |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 (생략 시 20) |
| search_field | query | string | 아니오 | `all`, `job`, `uuid` | 검색 대상 — `all`·`job`: 작업 payload 원문(작업 이름·클래스 포함) 부분 일치 / `uuid`: 작업 UUID 앞부분 일치. 생략 시 `all` |
| search_keyword | query | string | 아니오 | max 255 | 검색어 (LIKE 와일드카드 `%`·`_` 는 글자 그대로 찾음) |
| queue | query | string | 아니오 | max 100 | 큐 이름 필터 (`all` = 전체) |
| connection | query | string | 아니오 | max 50 | 조회할 큐 연결 — 그 연결의 대기 표를 읽습니다. 생략·`all` 이면 기본 연결 |
| sort_by | query | string | 아니오 | `created_at`, `available_at`, `attempts` | 정렬 기준 — 생략하면 최근 등록순(id 역순). 같은 값은 id 로 순서를 정합니다 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 순서 (생략 시 `desc`) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.queue_job.pending_list_validation_rules`).

**요청 예시**

```http
GET /api/admin/schedules/queue/pending?page=1&per_page=20&search_field=job&search_keyword=SendMail&queue=default&connection=database&sort_by=available_at&sort_order=asc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination` + `data.meta`._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `41` | 대기 표의 행 ID |
| uuid | string\|null | `0c7d2f8a-3e4b-4a6c-9d1e-5f6a7b8c9d0e` | 작업 UUID (payload 에서 추출) |
| queue | string | `default` | 큐 이름 |
| display_name | string | `App\Jobs\RunScheduledTaskJob` | 작업 표시 이름 (payload 의 `displayName`, 없으면 `Unknown`) |
| job_class | string | `App\Jobs\RunScheduledTaskJob` | 작업 클래스 |
| schedule_id | integer\|null | `7` | 예약 작업 실행 Job 이면 그 스케줄 ID, 아니면 null |
| attempts | integer | `0` | 지금까지 시도 횟수 |
| max_tries | integer\|null | `3` | 최대 시도 횟수 (작업이 지정하지 않았으면 null) |
| state | string | `waiting` | `waiting`(대기) · `delayed`(지연 실행 예정 — 실행 가능 시각이 미래) · `running`(워커가 집어 실행 중) |
| reserved_at | string\|null | `null` | 워커가 집은 시각 |
| available_at | string\|null | `2026-10-16 10:00:00` | 실행 가능 시각 |
| created_at | string\|null | `2026-10-16 09:59:58` | 큐에 들어온 시각 |
| abilities | object | `{"can_retry":false,"can_delete":false}` | 대기 작업에는 재시도·삭제 동작이 없어 항상 false |

`data.meta` 는 `measurable`(boolean — 이 연결이 대기 작업을 셀 수 있는지)·`connection`(조회한 연결)·`driver`(그 연결의 드라이버) 입니다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "대기 작업 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 41,
                "uuid": "0c7d2f8a-3e4b-4a6c-9d1e-5f6a7b8c9d0e",
                "queue": "default",
                "display_name": "App\\Jobs\\RunScheduledTaskJob",
                "job_class": "App\\Jobs\\RunScheduledTaskJob",
                "schedule_id": 7,
                "attempts": 0,
                "max_tries": 3,
                "state": "waiting",
                "reserved_at": null,
                "available_at": "2026-10-16 10:00:00",
                "created_at": "2026-10-16 09:59:58",
                "abilities": {
                    "can_retry": false,
                    "can_delete": false
                }
            }
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "from": 1,
            "to": 1,
            "has_more_pages": false,
            "last_page": 1,
            "total": 1,
            "total_relation": "exact",
            "total_is_exact": true,
            "result_cap": 10000
        },
        "meta": {
            "measurable": true,
            "connection": "database",
            "driver": "database"
        }
    }
}
```

`database` 가 아닌 드라이버(예: `redis`)의 연결이면 목록은 비고 `meta.measurable` 이 `false` 입니다.

```json
{
    "success": true,
    "message": "대기 작업 목록을 조회했습니다.",
    "data": {
        "data": [],
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "from": null,
            "to": null,
            "has_more_pages": false,
            "last_page": 1,
            "total": 0
        },
        "meta": {
            "measurable": false,
            "connection": "redis",
            "driver": "redis"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.queue_jobs.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

**설명** 아직 처리되지 않은 큐 작업을 최신순(행 ID 내림차순)으로 조회합니다. 대기 건수를 셀 수 있는 것은 큐 표를 직접 읽을 수 있는 `database` 드라이버뿐이며, 그 밖의 드라이버는 빈 목록과 `meta.measurable=false` 를 돌려주므로 화면은 안내 문구를 띄웁니다. `connection` 은 행을 거르는 조건이 아니라 「어느 연결의 대기 표를 읽을지」입니다. 대기 작업은 조회만 가능합니다.


### GET /api/admin/schedules/queue/failed
- **라우트명**: `api.admin.schedules.queue.failed`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\JobQueueController@failed`
- **인증/권한**: `auth:sanctum` + `permission:core.queue_jobs.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 (상한은 페이지 번호 한도 설정) | 조회할 페이지 번호 |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 (생략 시 20) |
| search_field | query | string | 아니오 | `all`, `job`, `error`, `uuid` | 검색 대상 — `job`: 작업 payload(작업 이름·클래스) / `error`: 예외 내용 / `uuid`: 작업 UUID / `all`: 셋 모두. 생략 시 `all` |
| search_keyword | query | string | 아니오 | max 255 | 검색어 (부분 일치, LIKE 와일드카드는 글자 그대로) |
| queue | query | string | 아니오 | max 100 | 큐 이름 필터 (`all` = 전체) |
| connection | query | string | 아니오 | max 50 | 연결 이름 필터 (`all` = 전체) |
| failed_start_date | query | date | 아니오 | — | 실패 일시 범위 시작일 (보는 사람의 시간대 하루로 해석) |
| failed_end_date | query | date | 아니오 | `failed_start_date` 이후 | 실패 일시 범위 종료일 |
| sort_by | query | string | 아니오 | `failed_at`, `id` | 정렬 기준 — 생략하면 최근 실패순(`failed_at` 역순). 같은 값은 id 로 순서를 정합니다 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 순서 (생략 시 `desc`) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.queue_job.failed_list_validation_rules`).

**요청 예시**

```http
GET /api/admin/schedules/queue/failed?page=1&per_page=20&search_field=error&search_keyword=timeout&queue=all&connection=all&failed_start_date=2026-10-01&failed_end_date=2026-10-16&sort_by=failed_at&sort_order=desc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination` + `data.abilities`._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 실패 작업 표의 행 ID |
| uuid | string | `9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f` | 작업 UUID — 상세·재시도·삭제의 경로 값 |
| connection | string | `database` | 실패한 연결 이름 |
| queue | string | `default` | 실패한 큐 이름 |
| display_name | string | `App\Jobs\RunScheduledTaskJob` | 작업 표시 이름 |
| job_class | string | `App\Jobs\RunScheduledTaskJob` | 작업 클래스 |
| schedule_id | integer\|null | `7` | 예약 작업 실행 Job 이면 그 스케줄 ID |
| attempts | integer\|null | `3` | 실패 시점의 시도 횟수. payload 에 시도 수를 싣지 않는 드라이버(database)는 같은 UUID 의 마지막 처리 기록 값이고, 처리 기록도 없으면 `null`(미상) |
| max_tries | integer\|null | `3` | 최대 시도 횟수 |
| exception_summary | string | `Illuminate\Queue\TimeoutExceededException: …` | 예외의 첫 줄 (최대 300자). 전문은 상세 조회로 봅니다 |
| failed_at | string\|null | `2026-10-16 03:10:05` | 실패 시각 |
| abilities | object | `{"can_retry":true,"can_delete":true}` | 이 행에 대한 재시도(`core.queue_jobs.retry`)·삭제(`core.queue_jobs.delete`) 가능 여부 |

`data.abilities` 는 같은 두 키의 컬렉션 단위 값입니다(일괄 재시도·삭제 버튼 잠금용).

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "실패 작업 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 12,
                "uuid": "9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f",
                "connection": "database",
                "queue": "default",
                "display_name": "App\\Jobs\\RunScheduledTaskJob",
                "job_class": "App\\Jobs\\RunScheduledTaskJob",
                "schedule_id": 7,
                "attempts": 3,
                "max_tries": 3,
                "exception_summary": "Illuminate\\Queue\\TimeoutExceededException: App\\Jobs\\RunScheduledTaskJob has timed out.",
                "failed_at": "2026-10-16 03:10:05",
                "abilities": {
                    "can_retry": true,
                    "can_delete": true
                }
            }
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "from": 1,
            "to": 1,
            "has_more_pages": false,
            "last_page": 1,
            "total": 1,
            "total_relation": "exact",
            "total_is_exact": true,
            "result_cap": 10000
        },
        "abilities": {
            "can_retry": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.queue_jobs.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

**설명** 최종 실패한 작업을 실패 시각 최신순으로 조회합니다. 예외 전문(스택 트레이스)은 행마다 크기가 커서 목록에는 첫 줄 요약(`exception_summary`)만 싣습니다. 전문은 `GET …/failed/{uuid}` 로 봅니다.


### GET /api/admin/schedules/queue/failed/{uuid}
- **라우트명**: `api.admin.schedules.queue.failed.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\JobQueueController@showFailed`
- **인증/권한**: `auth:sanctum` + `permission:core.queue_jobs.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| uuid | path | string | 예 | UUID 형식 | 실패 작업 UUID (형식이 아니면 라우트가 맞지 않아 404) |

**요청 예시**

```http
GET /api/admin/schedules/queue/failed/9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드. 목록 행의 필드(`id`~`failed_at`, `abilities`)에 아래 두 필드가 더해집니다._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| exception | string | `Illuminate\Queue\TimeoutExceededException: …\n#0 …` | 예외 전문 (스택 트레이스 포함) |
| payload_meta | object | `{"uuid":"9f3c…","displayName":"…","maxTries":3,…}` | payload 의 메타 키만 추린 객체 — `uuid`·`displayName`·`job`·`maxTries`·`maxExceptions`·`failOnTimeout`·`backoff`·`timeout`·`retryUntil` 중 payload 에 있는 것과 `commandName` |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "실패 작업 상세를 조회했습니다.",
    "data": {
        "id": 12,
        "uuid": "9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f",
        "connection": "database",
        "queue": "default",
        "display_name": "App\\Jobs\\RunScheduledTaskJob",
        "job_class": "App\\Jobs\\RunScheduledTaskJob",
        "schedule_id": 7,
        "attempts": 3,
        "max_tries": 3,
        "exception_summary": "Illuminate\\Queue\\TimeoutExceededException: App\\Jobs\\RunScheduledTaskJob has timed out.",
        "failed_at": "2026-10-16 03:10:05",
        "exception": "Illuminate\\Queue\\TimeoutExceededException: App\\Jobs\\RunScheduledTaskJob has timed out.\n#0 /var/www/vendor/laravel/framework/src/Illuminate/Queue/Worker.php(...)\n...",
        "payload_meta": {
            "uuid": "9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f",
            "displayName": "App\\Jobs\\RunScheduledTaskJob",
            "job": "Illuminate\\Queue\\CallQueuedHandler@call",
            "maxTries": 3,
            "maxExceptions": null,
            "failOnTimeout": true,
            "backoff": null,
            "timeout": 1800,
            "retryUntil": null,
            "commandName": "App\\Jobs\\RunScheduledTaskJob"
        },
        "abilities": {
            "can_retry": true,
            "can_delete": true
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.queue_jobs.read`)이 없는 경우 |
| 404 | Not Found | 해당 UUID 의 실패 작업이 없는 경우 (`queue.messages.failed_not_found` — 이미 재시도했거나 삭제됐을 수 있음) |

**설명** 실패 작업 1건의 예외 전문과 payload 메타를 조회합니다. **직렬화된 작업 객체(payload 의 `data.command`)는 응답에 싣지 않습니다** — 작업 인자에 수신자 주소·토큰 같은 값이 들어 있을 수 있기 때문입니다. 실패 작업 상세 창에 사용합니다.


### POST /api/admin/schedules/queue/failed/{uuid}/retry
- **라우트명**: `api.admin.schedules.queue.failed.retry`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\JobQueueController@retry`
- **인증/권한**: `auth:sanctum` + `permission:core.queue_jobs.retry`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| uuid | path | string | 예 | UUID 형식 | 다시 넣을 실패 작업 UUID |

**요청 예시**

```http
POST /api/admin/schedules/queue/failed/9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f/retry HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| results | array | `[{"uuid":"9f3c…","ok":true,…}]` | 건별 결과 (단건이므로 1개) |
| results[].uuid | string | `9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f` | 작업 UUID |
| results[].ok | boolean | `true` | 다시 넣었는지 |
| results[].message | string | `작업을 다시 큐에 넣었습니다.` | 건별 결과 메시지 (현지화) |
| results[].connection | string | `database` | 성공 시에만 — 실제로 넣은 연결 (재해석 결과) |
| results[].queue | string\|null | `default` | 성공 시에만 — 실제로 넣은 큐. null 이면 그 연결의 기본 큐 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "작업을 다시 큐에 넣었습니다.",
    "data": {
        "results": [
            {
                "uuid": "9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f",
                "ok": true,
                "message": "작업을 다시 큐에 넣었습니다.",
                "connection": "database",
                "queue": "default"
            }
        ]
    }
}
```

넣지 못하면 422 이며, 건별 결과는 `errors.results` 에 실립니다.

```http
HTTP/1.1 422
```

```json
{
    "success": false,
    "message": "작업을 다시 큐에 넣지 못했습니다.",
    "errors": {
        "results": [
            {
                "uuid": "9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f",
                "ok": false,
                "message": "현재 큐 드라이버(sync 등)는 워커가 없어 작업을 다시 넣을 수 없습니다. 환경설정 > 드라이버에서 큐 드라이버와 큐 워커를 확인해 주세요."
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.queue_jobs.retry`)이 없는 경우 |
| 404 | Not Found | 해당 UUID 의 실패 작업이 없는 경우 (최상위 `message` 는 `queue.messages.retry_failed`, `errors.results[0].message` 는 `queue.messages.failed_not_found`) |
| 422 | Unprocessable Entity | 다시 넣지 못한 경우 — 해석된 연결의 드라이버가 `sync`·`null`(`errors.results[0].message` = `queue.messages.retry_no_worker`), 작업 객체를 풀 수 없음, 큐 투입 중 오류(`queue.messages.retry_failed`) |

**설명** 실패 작업 1건을 다시 큐에 넣습니다. 절차는 `php artisan queue:retry` 와 같습니다 — 시도 횟수 초기화 → `JobRetryRequested` 이벤트 → 다시 넣기 → 실패 기록 삭제. 다른 점은 **넣을 곳을 다시 해석한다**는 것입니다.

- 원래 연결이 「큐 워커」에 등록되지 않았으면 **기본 연결**로 넣습니다.
- 원래 큐가 (해석된) 연결에 등록되지 않았으면 **그 연결의 기본 큐**로 넣습니다.
- 워커가 보지 않는 연결·큐로 되넣으면 그 작업은 영영 처리되지 않기 때문입니다. 대체가 일어나면 「큐 워커」 상태 보고서의 `placement.fallback` 에 사례가 남습니다.
- 해석된 연결의 드라이버가 `sync`·`null` 이면 되넣을 워커가 없으므로 거부합니다(422). 그대로 넣으면 작업은 사라지고 실패 기록만 지워지기 때문입니다.

넣는 데 성공해야 실패 기록이 지워집니다. 실패 작업 목록·상세의 「재시도」 동작에 사용합니다.


### POST /api/admin/schedules/queue/failed/retry
- **라우트명**: `api.admin.schedules.queue.failed.retry-bulk`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\JobQueueController@retryBulk`
- **인증/권한**: `auth:sanctum` + `permission:core.queue_jobs.retry`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| uuids | body | array | 예 | min 1, max 100 | 다시 넣을 실패 작업 UUID 목록 (101건 이상이면 422) |
| uuids.* | body | string | 예 | UUID 형식, 중복 불가 | 실패 작업 UUID |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.queue_job.bulk_retry_validation_rules`).

**요청 예시**

```http
POST /api/admin/schedules/queue/failed/retry HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "uuids": [
        "9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f",
        "1b2c3d4e-5f60-4a7b-8c9d-0e1f2a3b4c5d"
    ]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| results | array | `[{"uuid":"9f3c…","ok":true,…}]` | 건별 결과 — 필드는 단건 재시도의 `results[]` 와 같음 (성공 건에만 `connection`·`queue`) |
| succeeded | integer | `1` | 다시 넣은 건수 |
| failed | integer | `1` | 넣지 못한 건수 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "재시도를 마쳤습니다. 성공 1건, 실패 1건",
    "data": {
        "results": [
            {
                "uuid": "9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f",
                "ok": true,
                "message": "작업을 다시 큐에 넣었습니다.",
                "connection": "database",
                "queue": "default"
            },
            {
                "uuid": "1b2c3d4e-5f60-4a7b-8c9d-0e1f2a3b4c5d",
                "ok": false,
                "message": "실패 작업을 찾을 수 없습니다. 이미 재시도했거나 삭제됐을 수 있습니다."
            }
        ],
        "succeeded": 1,
        "failed": 1
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.queue_jobs.retry`)이 없는 경우 |
| 422 | Unprocessable Entity | `uuids` 가 비었거나 100건을 넘은 경우, UUID 형식이 아니거나 중복된 경우 (`errors` 에 필드별 메시지 — `queue.validation.uuids_required` / `uuids_max` / `uuid_invalid` / `uuid_duplicated`) |

**설명** 실패 작업 여러 건을 한 번에 다시 넣습니다(요청당 최대 100건). 건마다 단건 재시도와 같은 절차·재해석·거부 규칙이 적용되며, 한 건이 실패해도 나머지는 계속합니다. **일부가 실패해도 200** 으로 응답하고 건별 결과와 성공·실패 건수를 돌려주므로, 화면은 `succeeded`·`failed` 를 따로 알립니다.


### DELETE /api/admin/schedules/queue/failed/{uuid}
- **라우트명**: `api.admin.schedules.queue.failed.destroy`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\JobQueueController@destroy`
- **인증/권한**: `auth:sanctum` + `permission:core.queue_jobs.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| uuid | path | string | 예 | UUID 형식 | 지울 실패 작업 UUID |

**요청 예시**

```http
DELETE /api/admin/schedules/queue/failed/9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| results | array | `[{"uuid":"9f3c…","ok":true,"message":"…"}]` | 건별 결과 (`uuid`·`ok`·`message`) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "실패 작업을 삭제했습니다.",
    "data": {
        "results": [
            {
                "uuid": "9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f",
                "ok": true,
                "message": "실패 작업을 삭제했습니다."
            }
        ]
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.queue_jobs.delete`)이 없는 경우 |
| 404 | Not Found | 해당 UUID 의 실패 작업이 없는 경우 (`queue.messages.failed_not_found`, 건별 결과는 `errors.results`) |

**설명** 실패 작업 1건을 지웁니다. 복구할 수 없으며 작업은 다시 실행되지 않습니다. 실패 작업 목록·상세의 「삭제」 동작에 사용합니다.


### DELETE /api/admin/schedules/queue/failed
- **라우트명**: `api.admin.schedules.queue.failed.destroy-bulk`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\JobQueueController@destroyBulk`
- **인증/권한**: `auth:sanctum` + `permission:core.queue_jobs.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| uuids | body | array | 예 | min 1, max 100 | 지울 실패 작업 UUID 목록 (101건 이상이면 422) |
| uuids.* | body | string | 예 | UUID 형식, 중복 불가 | 실패 작업 UUID |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.queue_job.bulk_delete_validation_rules`).

**요청 예시**

```http
DELETE /api/admin/schedules/queue/failed HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "uuids": [
        "9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f",
        "1b2c3d4e-5f60-4a7b-8c9d-0e1f2a3b4c5d"
    ]
}
```

**응답 필드** (`data` 내부)

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| results | array | `[{"uuid":"9f3c…","ok":true,"message":"…"}]` | 건별 결과 (`uuid`·`ok`·`message`) |
| succeeded | integer | `2` | 지운 건수 |
| failed | integer | `0` | 지우지 못한 건수 (이미 없는 작업 등) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "삭제를 마쳤습니다. 성공 2건, 실패 0건",
    "data": {
        "results": [
            {
                "uuid": "9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f",
                "ok": true,
                "message": "실패 작업을 삭제했습니다."
            },
            {
                "uuid": "1b2c3d4e-5f60-4a7b-8c9d-0e1f2a3b4c5d",
                "ok": true,
                "message": "실패 작업을 삭제했습니다."
            }
        ],
        "succeeded": 2,
        "failed": 0
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.queue_jobs.delete`)이 없는 경우 |
| 422 | Unprocessable Entity | `uuids` 가 비었거나 100건을 넘은 경우, UUID 형식이 아니거나 중복된 경우 (`errors` 에 필드별 메시지) |

**설명** 실패 작업 여러 건을 한 번에 지웁니다(요청당 최대 100건). 일괄 재시도와 같은 형식으로, 일부가 없어도 200 과 건별 결과·건수를 돌려줍니다.


### GET /api/admin/schedules/queue/logs
- **라우트명**: `api.admin.schedules.queue.logs`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\JobQueueController@logs`
- **인증/권한**: `auth:sanctum` + `permission:core.queue_jobs.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| page | query | integer | 아니오 | min 1 (상한은 페이지 번호 한도 설정) | 조회할 페이지 번호 |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 (생략 시 20) |
| search_field | query | string | 아니오 | `all`, `job`, `error`, `uuid` | 검색 대상 — `job`: 표시 이름·작업 클래스 / `error`: 예외 메시지·예외 클래스 / `uuid`: 작업 UUID / `all`: 모두. 생략 시 `all` |
| search_keyword | query | string | 아니오 | max 255 | 검색어 (부분 일치, LIKE 와일드카드는 글자 그대로) |
| status | query | string | 아니오 | `all`, `processed`, `failed` | 처리 결과 필터 (`processed` 성공 / `failed` 최종 실패 / `all` 전체) |
| queue | query | string | 아니오 | max 100 | 큐 이름 필터 (`all` = 전체) |
| connection | query | string | 아니오 | max 50 | 연결 이름 필터 (`all` = 전체) |
| created_start_date | query | date | 아니오 | — | 기록 일시 범위 시작일 (보는 사람의 시간대 하루로 해석) |
| created_end_date | query | date | 아니오 | `created_start_date` 이후 | 기록 일시 범위 종료일 |
| sort_by | query | string | 아니오 | `finished_at`, `duration_ms`, `attempts` | 정렬 기준 — 생략하면 최근 기록순(`created_at` 역순). 같은 값은 id 로 순서를 정합니다 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 순서 (생략 시 `desc`) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.queue_job.log_list_validation_rules`).

**요청 예시**

```http
GET /api/admin/schedules/queue/logs?page=1&per_page=20&search_field=job&search_keyword=RunScheduledTaskJob&status=failed&queue=all&connection=all&created_start_date=2026-10-10&created_end_date=2026-10-16&sort_by=duration_ms&sort_order=desc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `305` | 처리 기록 ID |
| uuid | string\|null | `9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f` | 작업 UUID |
| connection | string | `database` | 처리한 연결 |
| queue | string | `default` | 처리한 큐 |
| display_name | string | `App\Jobs\RunScheduledTaskJob` | 작업 표시 이름 |
| job_class | string | `App\Jobs\RunScheduledTaskJob` | 작업 클래스 |
| schedule_id | integer\|null | `7` | 예약 작업 실행 Job 이면 그 스케줄 ID |
| status | string | `failed` | 처리 결과 — `processed`(성공) · `failed`(최종 실패) |
| attempts | integer | `3` | 처리 시점의 시도 횟수 |
| duration_ms | integer\|null | `1800213` | 처리 소요 시간 (밀리초) |
| exception_class | string\|null | `Illuminate\Queue\TimeoutExceededException` | 실패 시 예외 클래스 |
| exception_message | string\|null | `App\Jobs\RunScheduledTaskJob has timed out.` | 실패 시 예외 메시지 |
| started_at | string\|null | `2026-10-16 02:40:05` | 처리 시작 시각 |
| finished_at | string\|null | `2026-10-16 03:10:05` | 처리 종료 시각 |
| created_at | string\|null | `2026-10-16 03:10:05` | 기록 시각 |
| abilities | object | `{"can_retry":false,"can_delete":false}` | 처리 기록은 읽기 전용이라 항상 false |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "처리 기록 목록을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 305,
                "uuid": "9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f",
                "connection": "database",
                "queue": "default",
                "display_name": "App\\Jobs\\RunScheduledTaskJob",
                "job_class": "App\\Jobs\\RunScheduledTaskJob",
                "schedule_id": 7,
                "status": "failed",
                "attempts": 3,
                "duration_ms": 1800213,
                "exception_class": "Illuminate\\Queue\\TimeoutExceededException",
                "exception_message": "App\\Jobs\\RunScheduledTaskJob has timed out.",
                "started_at": "2026-10-16 02:40:05",
                "finished_at": "2026-10-16 03:10:05",
                "created_at": "2026-10-16 03:10:05",
                "abilities": {
                    "can_retry": false,
                    "can_delete": false
                }
            }
        ],
        "pagination": {
            "current_page": 1,
            "per_page": 20,
            "from": 1,
            "to": 1,
            "has_more_pages": false,
            "last_page": 1,
            "total": 1,
            "total_relation": "exact",
            "total_is_exact": true,
            "result_cap": 10000
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.queue_jobs.read`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) |

**설명** 큐 작업 처리 기록을 기록 시각 최신순으로 조회합니다. 워커가 처리를 마친 작업마다 1행이 남습니다 — `processed` 는 성공, `failed` 는 최종 실패(실패 작업 표로 옮겨진 작업)입니다. 재시도 대기로 돌려보낸(release) 회차는 남기지 않습니다. 예약 작업 실행 Job 이면 `schedule_id` 가 있어 해당 스케줄로 이어 볼 수 있습니다. 기록은 환경설정 > 드라이버 「큐 작업 처리 기록 사용」(`queue_job_log_enabled`, 기본 켜짐)으로 끌 수 있고, 보관 기간(`queue_record_retention_days`, 기본 7일)이 지나면 정리됩니다. 처리 기록은 조회 전용이며 행 단위 동작이 없습니다.


### GET /api/admin/schedules/queue/logs/{id}
- **라우트명**: `api.admin.schedules.queue.logs.show`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\JobQueueController@showLog`
- **인증/권한**: `auth:sanctum` + `permission:core.queue_jobs.read`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| id | path | integer | 예 | 숫자 | 처리 기록 ID (숫자가 아니면 라우트가 맞지 않아 404) |

**요청 예시**

```http
GET /api/admin/schedules/queue/logs/305 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드 — 처리 기록 목록 행과 같습니다._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "처리 기록 상세를 조회했습니다.",
    "data": {
        "id": 305,
        "uuid": "9f3c2a1e-4b5d-4c6e-8f70-1a2b3c4d5e6f",
        "connection": "database",
        "queue": "default",
        "display_name": "App\\Jobs\\RunScheduledTaskJob",
        "job_class": "App\\Jobs\\RunScheduledTaskJob",
        "schedule_id": 7,
        "status": "failed",
        "attempts": 3,
        "duration_ms": 1800213,
        "exception_class": "Illuminate\\Queue\\TimeoutExceededException",
        "exception_message": "App\\Jobs\\RunScheduledTaskJob has timed out.",
        "started_at": "2026-10-16 02:40:05",
        "finished_at": "2026-10-16 03:10:05",
        "created_at": "2026-10-16 03:10:05",
        "abilities": {
            "can_retry": false,
            "can_delete": false
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.queue_jobs.read`)이 없는 경우 |
| 404 | Not Found | 해당 ID 의 처리 기록이 없는 경우 (`queue.messages.log_not_found`) |

**설명** 처리 기록 1건을 조회합니다. 처리 기록 상세 창에 사용합니다.

