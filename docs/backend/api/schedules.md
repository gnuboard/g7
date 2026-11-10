# Schedules API 레퍼런스

> **소유**: 코어 · **생성**: `php artisan api:docgen` (실측 기반). @generated 블록은 재생성 시 갱신되며, 사람이 작성한 설명은 보존됩니다.

---

## TL;DR (5초 요약)

```text
1. 이 문서는 실제 API 호출로 실측한 Schedules 엔드포인트 레퍼런스입니다
2. 각 엔드포인트: 메서드/URI/권한 + 요청 파라미터 표 + 요청 예시(raw HTTP) + 실측 응답 필드 표 + 응답 예시(envelope)
3. 응답 필드의 예시값·응답 예시 JSON 은 실제 호출 응답에서 관측된 값입니다
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
| page | query | integer | 아니오 | min 1 | 조회할 페이지 번호 (1부터 시작, 생략 시 1) |
| per_page | query | integer | 아니오 | min 1, max 100 | 페이지당 항목 수 (생략 시 20) |
| search_field | query | string | 아니오 | `all`, `name`, `identifier`, `command` | 검색 대상 — `name`: 표시 명칭(`label` 의 각 번역)·식별 이름 / `identifier`: 식별 이름(`name`) / `command`: 명령·URL·Job 클래스 / `all`(또는 생략): 셋 + 설명 |
| search_keyword | query | string | 아니오 | max 255 | 검색어 (부분 일치, LIKE 와일드카드 `%`·`_` 는 글자 그대로 찾음) |
| extension_type | query | string | 아니오 | `all`, `core`, `module`, `plugin`, `custom` | 소유 필터 — `custom` 은 직접 등록(소유 확장 없음) 작업 |
| extension_identifier | query | string | 아니오 | max 255 | 소유 확장 식별자 (`extension_type` 이 `module`·`plugin` 일 때만 적용) |
| type | query | string | 아니오 | `all`, `artisan`, `shell`, `url`, `job` | 유형 필터 |
| status | query | string | 아니오 | `all`, `active`, `inactive` | 활성 상태 필터 |
| last_result | query | string | 아니오 | `all`, `success`, `failed`, `running`, `never` | 마지막 실행 결과 필터: success(성공), failed(실패), running(실행중), never(미실행) |
| frequency | query | string | 아니오 | `all`, `everyThirtySeconds`, `everyMinute`, `hourly`, `daily`, `weekly`, `monthly`, `custom` | 실행 주기 필터 |
| dispatch_mode | query | string | 아니오 | `all`, `sync`, `queue` | 실행 방식 필터 (sync 바로 실행 / queue 큐로 보내기) |
| history_mode | query | string | 아니오 | `all`, `failures_only` | 실행 기록 방식 필터 — 이 축만 「전체」가 **빈 값(생략)** 이다. `all` 은 「모든 실행 기록」 값 그대로 걸러진다 |
| overlap | query | string | 아니오 | `all`, `on`, `off` | 중복 실행 방지 필터 (`on`: 잠금 유지 시간 > 0분 / `off`: 0분) |
| run_in_maintenance | query | string | 아니오 | `0`, `1` | 점검 모드 중 실행 여부 |
| created_start_date | query | date | 아니오 | — | 등록일 범위의 시작일 (보는 사람의 시간대 하루로 해석) |
| created_end_date | query | date | 아니오 | `created_start_date` 이후 | 등록일 범위의 종료일 |
| sort_by | query | string | 아니오 | `created_at`, `name`, `next_run_at`, `last_run_at`, `is_active`, `last_result` | 정렬 기준 필드명 (생략 시 `created_at`) |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (생략 시 `desc`) |
| filters | query | array | 아니오 | max 10 | (7.2.0 이전 호환) 다중 검색 조건 — 항목 `{field: all\|name\|description\|command, value, operator: like\|eq\|starts_with\|ends_with}` |
| created_from | query | date | 아니오 | — | (7.2.0 이전 호환) `created_start_date` 의 옛 이름 |
| created_to | query | date | 아니오 | `created_from` 이후 | (7.2.0 이전 호환) `created_end_date` 의 옛 이름 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.schedule.list_validation_rules`).

**요청 예시**

```http
GET /api/admin/schedules?page=1&per_page=20&search_field=all&search_keyword=sitemap&extension_type=module&extension_identifier=sirsoft-ecommerce&type=all&status=active&last_result=all&frequency=all&dispatch_mode=all&overlap=all&created_start_date=2026-10-01&created_end_date=2026-10-16&sort_by=created_at&sort_order=desc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | string | `cache_clear_daily` | 식별 이름 (기계 식별자 — 화면 표시는 `label_localized`) |
| type | string | `artisan` | 작업 유형: artisan(Artisan 커맨드), shell(쉘 명령), url(URL 호출), job(큐 Job 클래스) |
| type_label | string | `Artisan 커맨드` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| command | string | `cache:clear` | 명령어·URL 또는 Job 클래스 |
| expression | string | `0 3 * * *` | Cron 표현식 |
| description | object\|null | `{"ko":"매일 캐시 정리","en":"Daily cache clear"}` | 설명 원본 (로케일별 값 객체) |
| frequency | string | `daily` | 실행 주기: everyThirtySeconds(30초마다), everyMinute(매분), hourly(매시간), daily(매일), weekly(매주), monthly(매월), custom(사용자 정의) |
| frequency_label | string | `매일` | `frequency` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| label | object\|null | `{"ko":"캐시 정리","en":"Clear cache"}` | 표시 명칭 원본 (로케일별 값 객체) |
| label_localized | string | `캐시 정리` | 현재 로케일로 해석한 표시 명칭 (없으면 이름) |
| description_localized | string\|null | `매일 캐시 정리` | 현재 로케일로 해석한 설명 |
| owner | object | `{"type":"core","identifier":"core","name":"코어"}` | 소유자 — `type`(core/module/plugin/custom)·`identifier`·`name`(현지화 이름). 직접 등록은 `{"type":"custom","identifier":null,"name":"직접 등록"}` |
| is_owned | boolean | `true` | 코어·확장이 등록한 작업인지 (직접 등록이면 false) |
| locked_fields | string[] | `["command","type","name","enabled_condition","timezone"]` | 바꿀 수 없는 필드 — 소유 행이면 이 5개, 직접 등록이면 빈 배열 |
| overridden_fields | string[] | `["expression"]` | 운영자가 선언값에서 바꾼 필드 목록 |
| enabled_condition | array | `[]` | 실행 조건 선언 (코어·확장이 정함, 읽기 전용) |
| unmet_conditions | array | `[]` | 지금 충족되지 않은 실행 조건 — 항목 `{source, key, operator}` |
| dispatch_mode | string | `sync` | 실행 방식: sync(바로 실행), queue(큐로 보내기) |
| history_mode | string | `all` | 실행 기록 방식: all(모든 실행 기록), failures_only(실패만 기록) |
| without_overlapping | integer | `1440` | 중복 실행 방지 잠금 유지 시간(분). 0 = 끔 |
| on_one_server | boolean | `false` | 여러 서버 중 한 서버에서만 실행 |
| notify_on_failure | boolean | `true` | 실패 시 알림 발송 |
| queue_connection | string\|null | `null` | 큐로 보낼 때의 연결 (null = 기본) |
| queue_name | string\|null | `null` | 큐로 보낼 때의 큐 (null = 연결의 기본 큐) |
| timezone | string\|null | `null` | Cron 해석 시간대 (null = 스케줄 기본 시간대) |
| run_in_maintenance | boolean | `false` | 점검 모드 실행 여부 |
| timeout | integer\|null | `300` | 실행 제한 시간 (초) |
| is_active | boolean | `true` | 활성 여부 |
| last_result | string | `success` | 마지막 실행 결과: success(성공), failed(실패), running(실행중), never(미실행) |
| last_result_label | string | `성공` | `last_result` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| last_run_at | string\|null | `2026-10-16T03:00:00+09:00` | 마지막 실행 일시 |
| last_duration | string\|null | `null` | 마지막 실행의 소요 시간을 사람이 읽는 문자열로 포맷한 값 (예: "45초", "2분 3초" — 마지막 실행 이력의 duration 파생, 실행 이력이 없으면 null) |
| next_run_at | string\|null | `2026-10-17T03:00:00+09:00` | 다음 실행 일시 (활성 행의 저장값이 지난 시각이면 cron 식으로 다시 계산한 값) |
| creator | object\|null | `null` | 생성자 정보 객체 (uuid/name — creator 관계가 로드된 경우) |
| created_at | string\|null | `2026-10-15` | 등록일 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":false,"c…` | 현재 사용자가 이 행에 수행 가능한 작업 불리언 맵 — **소유 행은 `can_delete` 가 항상 false** |

`data.abilities` 는 컬렉션 권한(`can_create`·`can_update`·`can_delete`·`can_run`)이고, `data.statistics` 는 [GET /api/admin/schedules/statistics](#get-apiadminschedulesstatistics) 와 같은 집계입니다.

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
                "name": "cache_clear_daily",
                "type": "artisan",
                "type_label": "Artisan 커맨드",
                "command": "cache:clear",
                "expression": "0 3 * * *",
                "description": {
                    "ko": "매일 캐시 정리",
                    "en": "Daily cache clear"
                },
                "frequency": "daily",
                "frequency_label": "매일",
                "label": {
                    "ko": "캐시 정리",
                    "en": "Clear cache"
                },
                "label_localized": "캐시 정리",
                "description_localized": "매일 캐시 정리",
                "owner": {
                    "type": "core",
                    "identifier": "core",
                    "name": "코어"
                },
                "is_owned": true,
                "locked_fields": ["command", "type", "name", "enabled_condition", "timezone"],
                "overridden_fields": [],
                "enabled_condition": [],
                "unmet_conditions": [],
                "dispatch_mode": "sync",
                "history_mode": "all",
                "without_overlapping": 1440,
                "on_one_server": false,
                "notify_on_failure": true,
                "queue_connection": null,
                "queue_name": null,
                "timezone": null,
                "run_in_maintenance": false,
                "timeout": 300,
                "is_active": true,
                "last_result": "success",
                "last_result_label": "성공",
                "last_run_at": "2026-10-16T03:00:00+09:00",
                "last_duration": null,
                "next_run_at": "2026-10-17T03:00:00+09:00",
                "creator": null,
                "created_at": "2026-10-15",
                "abilities": {
                    "can_create": true,
                    "can_update": true,
                    "can_delete": false,
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

**설명** 예약 작업(스케줄) 목록을 페이지네이션과 함께 조회하며, 응답에는 상태별 통계도 포함됩니다. `core.schedules.read` 권한이 필요합니다. 검색 대상(`search_field`)+검색어(`search_keyword`), 소유(`extension_type`·`extension_identifier`), 유형·상태·마지막 결과·주기·실행 방식·기록 방식·중복 방지(`overlap`)·점검 모드·등록일로 필터링하고 `sort_by`/`sort_order` 로 정렬할 수 있습니다. 라디오 「전체」 값 `all` 은 조건 없음으로 처리됩니다(`history_mode` 만 예외 — 「전체」는 빈 값). 관리자 스케줄 관리 화면의 목록·필터에 사용하며, 확장 설정 화면은 `extension_type=module&extension_identifier={모듈 식별자}` 로 자기 소유 작업만 조회합니다.

**7.2.0 쿼리 키 변경** 목록 쿼리 키가 위 표로 바뀌었습니다. 옛 `search`·`module`·`without_overlapping` 필터는 없어졌습니다(중복 방지는 `overlap`, 소유 확장은 `extension_type`+`extension_identifier`). 옛 다중 검색 `filters[]` 와 옛 날짜 키 `created_from`/`created_to` 는 호출 호환을 위해 계속 받습니다(새 키와 함께 오면 `created_start_date`/`created_end_date` 가 우선).

**권한 값(7.1.0)** 응답 `data.abilities` 에 컬렉션 권한이 실린다 — `can_create`(= `core.schedules.create`)·`can_update`·`can_delete`·`can_run`. 「예약 작업 등록」·일괄 상태 변경·행 선택이 이 값으로 잠긴다. 행 단위 `abilities` 는 같은 키이되, 코어·확장 소유 행은 삭제할 수 없으므로 `can_delete` 가 false 다(7.2.0). `can_delete` 가 false 인 까닭은 행의 `delete_blocked_reason` 으로 구분한다 — `owned`(코어·확장 소유 행, 삭제 권한이 있어도 풀리지 않는다)·`permission`(`core.schedules.delete` 권한 없음)·`null`(삭제 가능). 상세 응답도 같은 값을 싣는다.

### POST /api/admin/schedules
<!-- @generated:start:api.admin.schedules.store -->
- **라우트명**: `api.admin.schedules.store`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@store`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.create`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| name | body | string | 예 | max 255 | 식별자 (기계 식별자). 생략하면 `label` 의 사이트 기본 언어 값(없으면 첫 값)에서 채운다. `label` 이 모든 언어에서 비어 있으면 `label` 오류만 내고 `name` 필수 오류는 내지 않는다 |
| label | body | object\|string | 예 | 로케일별 max 255, 사이트 기본 언어 값 필수 | 작업명 (로케일별 값 객체). 문자열을 보내면 현재 로케일·사이트 기본 로케일 값으로 올린다. 생략하면 `name` 에서 채운다 |
| description | body | object\|string | 아니오 | 로케일별 max 1000 | 설명 (로케일별 값 객체, 문자열이면 `label` 과 같은 방식으로 올린다) |
| type | body | string | 예 | `artisan`, `shell`, `url`, `job` | 작업 유형: artisan(Artisan 커맨드 실행), shell(쉘 명령 실행), url(URL 호출), job(큐 Job 클래스 실행) |
| command | body | string | 예 | max 2000 | 실행할 명령 (`type` 에 따라 Artisan 커맨드 / 쉘 명령 / 호출할 URL). `type=url` 이면 내부 네트워크 주소(사설 IP·루프백·`localhost`·`*.internal` 등)는 거부되어 422 로 응답합니다 — 서버가 내부망으로 요청을 보내는 것을 막기 위함(SSRF). 사내 엔드포인트를 호출해야 하면 환경설정의 `security.allow_internal_outbound_urls` 를 켜세요. 이 설정을 켜도 userinfo(`https://a@b/`) 위장과 http/https 이외 scheme 은 계속 거부됩니다.<br>`type=shell` 이면 기본적으로 모든 명령이 거부되어 422 로 응답합니다 — 스케줄 권한만 위임받은 계정이 서버에서 임의 OS 명령을 실행하는 것을 막기 위함입니다. 실행하려면 서버의 `.env` 에서 `SCHEDULE_SHELL_ENABLED=true` 로 게이트를 열고 `SCHEDULE_SHELL_ALLOWED_BINARIES` 에 허용할 실행 파일명을 등록해야 합니다(관리자 화면에서는 넓힐 수 없는 코드 소유 정책). 허용된 실행 파일이라도 셸 메타문자(`|`, `;`, `$`, 백틱, 리다이렉션, 따옴표 등)가 포함되면 거부되며, 실행 시 셸을 경유하지 않습니다. 복합 명령이 필요하면 래퍼 스크립트를 만들어 화이트리스트에 등록하세요.<br>`type=artisan` 이면 서버가 허용한 유지보수 명령만 등록할 수 있고 그 밖의 명령은 거부되어 422 로 응답합니다 — 캐시·컴파일 산출물 정리(`cache:clear` 등), 큐 처리(`queue:work`·`queue:restart` 등), 만료 데이터 정리, SEO 관련 명령이 여기 해당합니다. `tinker` 처럼 임의 코드를 실행하거나 `migrate`·`db:wipe`·`make:*` 처럼 스키마를 바꾸거나 코드를 생성하는 명령은 허용 목록에 오르지 않습니다(관리자 화면에서는 넓힐 수 없는 코드 소유 정책 — 서버 관리자는 `storage/app/schedule_security.json` 으로 허용·거부 항목을 더할 수 있고, 기본 거부 항목은 풀 수 없습니다. 유효 정책은 `GET /api/admin/schedules/security`). 설치된 모듈·플러그인이 제공하는 명령은 자동으로 허용됩니다.<br>허용된 명령이라도 그 명령이 선언하지 않은 옵션, 위치 인자, 단축 옵션(`-v`)이 붙으면 거부됩니다. 형식은 `명령명 --옵션[=값]` 만 허용하며 따옴표·역슬래시가 포함된 입력은 거부됩니다 — 저장할 때 해석한 명령과 실제로 실행되는 명령이 달라지지 않도록 하기 위함입니다. 거부 사유(허용 목록 밖 / 형식 오류 / 허용되지 않은 옵션 / 추가 인자 불가)는 응답 메시지로 구분되어 안내됩니다.<br>`type=job` 이면 보안 허용목록(`schedule_security.job.allowed_classes`, 서버 관리자는 덮어쓰기 파일로, 확장은 `core.schedule.filter_allowed_job_classes` 필터로 추가)에 등록된 Job 클래스 FQCN 만 받고 그 밖은 422(`schedule.validation.job_not_allowed`) 입니다.<br>여러 줄로 보내도 저장 전에 줄바꿈·연속 공백·앞뒤 공백을 공백 하나로 접어 한 줄로 검사·저장합니다 |
| expression | body | string | 예 | max 100 | 실행 시각을 정의하는 Cron 표현식 (예: `0 3 * * *`, 다음 실행 시각 next_run_at 계산의 기준) |
| frequency | body | string | 예 | `everyThirtySeconds`, `everyMinute`, `hourly`, `daily`, `weekly`, `monthly`, `custom` | 실행 주기 |
| dispatch_mode | body | string | 아니오 | `sync`, `queue` | 실행 방식 — sync(바로 실행, 기본) / queue(큐로 보내기) |
| queue_connection | body | string | 아니오 | max 50 | 큐로 보낼 연결 (비우면 기본 연결). 「큐 워커」에 등록되지 않은 연결이면 실행 시 기본 연결로 보낸다 |
| queue_name | body | string | 아니오 | max 100 | 큐로 보낼 큐 (비우면 연결의 기본 큐). 등록되지 않은 큐면 실행 시 그 연결의 기본 큐로 보낸다 |
| history_mode | body | string | 아니오 | `all`, `failures_only` | 실행 기록 방식 — all(모든 실행 기록, 기본) / failures_only(실패만 기록) |
| without_overlapping | body | integer | 아니오 | min 0, max 10080 | 중복 실행 방지 잠금 유지 시간(분). 0 = 끔(생략 시 0). 옛 boolean 도 받는다 — `true` → 1440, `false` → 0 |
| on_one_server | body | boolean | 아니오 | — | 여러 서버 중 한 서버에서만 실행 (기본 false) |
| notify_on_failure | body | boolean | 아니오 | — | 실패 시 알림 발송 (기본 true) |
| timezone | body | string | 아니오 | max 64, 유효한 시간대 | Cron 해석 시간대 (비우면 스케줄 기본 시간대) |
| run_in_maintenance | body | boolean | 아니오 | — | 점검 모드 중 실행 여부 (생략 시 false) |
| timeout | body | integer | 아니오 | min 1, max 86400 | 타임아웃 (초) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (생략 시 true) |
| enabled_condition | body | — | 아니오 | 보내면 422 (`prohibited`) | 실행 조건은 코어·확장 선언만 정한다 |
| command_class | body | — | 아니오 | 보내면 422 (`prohibited`) | 명령 클래스는 선언만 정한다 |
| extension_type | body | — | 아니오 | 보내면 422 (`prohibited`) | 등록은 언제나 직접 등록 — 소유자를 지정할 수 없다 |
| extension_identifier | body | — | 아니오 | 보내면 422 (`prohibited`) | 위와 같음 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.schedule.create_validation_rules`).

**요청 예시**

```http
POST /api/admin/schedules HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": "nightly_cache_clear",
    "label": {
        "ko": "야간 캐시 정리",
        "en": "Nightly cache clear"
    },
    "description": {
        "ko": "매일 새벽 3시에 캐시를 비웁니다."
    },
    "type": "artisan",
    "command": "cache:clear",
    "expression": "0 3 * * *",
    "frequency": "daily",
    "dispatch_mode": "queue",
    "queue_connection": null,
    "queue_name": null,
    "history_mode": "failures_only",
    "without_overlapping": 1440,
    "on_one_server": true,
    "notify_on_failure": true,
    "timezone": "Asia/Seoul",
    "run_in_maintenance": false,
    "timeout": 600,
    "is_active": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `12` | 기본 키 (내부 식별자) |
| name | string | `nightly_cache_clear` | 식별 이름 (기계 식별자) |
| description | object\|null | `{"ko":"매일 새벽 3시에 캐시를 비웁니다."}` | 설명 원본 (로케일별 값 객체) |
| type | string | `artisan` | 작업 유형: artisan(Artisan 커맨드), shell(쉘 명령), url(URL 호출), job(큐 Job 클래스) |
| type_label | string | `Artisan 커맨드` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| command | string | `cache:clear` | 명령어·URL 또는 Job 클래스 |
| expression | string | `0 3 * * *` | Cron 표현식 |
| frequency | string | `daily` | 실행 주기: everyThirtySeconds(30초마다), everyMinute(매분), hourly(매시간), daily(매일), weekly(매주), monthly(매월), custom(사용자 정의) |
| frequency_label | string | `매일` | `frequency` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| label | object\|null | `{"ko":"야간 캐시 정리","en":"Nightly cache clear"}` | 표시 명칭 원본 (로케일별 값 객체) |
| label_localized | string | `야간 캐시 정리` | 현재 로케일로 해석한 표시 명칭 (없으면 이름) |
| description_localized | string\|null | `매일 새벽 3시에 캐시를 비웁니다.` | 현재 로케일로 해석한 설명 |
| owner | object | `{"type":"custom","identifier":null,"name":"직접 등록"}` | 소유자 — `type`(core/module/plugin/custom)·`identifier`·`name`(현지화 이름) |
| is_owned | boolean | `false` | 코어·확장이 등록한 작업이면 true |
| locked_fields | string[] | `[]` | 바꿀 수 없는 필드 — 소유 행이면 `command`·`type`·`name`·`enabled_condition`·`timezone`, 직접 등록이면 빈 배열 |
| overridden_fields | string[] | `[]` | 운영자가 선언값에서 바꾼 필드 목록 (소유 행) |
| enabled_condition | array | `[]` | 실행 조건 선언 (코어·확장이 정함, 읽기 전용) |
| unmet_conditions | array | `[]` | 지금 충족되지 않은 실행 조건 — 항목 `{source, key, operator}` |
| dispatch_mode | string | `queue` | 실행 방식: sync(바로 실행), queue(큐로 보내기) |
| history_mode | string | `failures_only` | 실행 기록 방식: all(모든 실행 기록), failures_only(실패만 기록) |
| without_overlapping | integer | `1440` | 중복 실행 방지 잠금 유지 시간(분). 0 = 끔 |
| on_one_server | boolean | `true` | 한 서버에서만 실행 |
| notify_on_failure | boolean | `true` | 실패 시 알림 발송 |
| queue_connection | string\|null | `null` | 큐로 보낼 때의 연결 (null = 기본) |
| queue_name | string\|null | `null` | 큐로 보낼 때의 큐 (null = 연결의 기본 큐) |
| timezone | string\|null | `Asia/Seoul` | Cron 해석 시간대 (null = 스케줄 기본 시간대) |
| run_in_maintenance | boolean | `false` | 점검 모드 실행 여부 |
| timeout | integer\|null | `600` | 실행 제한 시간 (초) |
| is_active | boolean | `true` | 활성 여부 |
| last_result | string | `never` | 마지막 실행 결과: success(성공), failed(실패), running(실행중), never(미실행) |
| last_result_label | string | `미실행` | `last_result` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| last_duration | string\|null | `null` | 마지막 실행 소요 시간 표시 문자열 (실행 이력 파생, 없으면 null) |
| extension_type | null | `null` | 소유 확장 타입 — 직접 등록이므로 언제나 null |
| extension_identifier | null | `null` | 소유 확장 식별자 — 직접 등록이므로 언제나 null |
| creator | object | `{"uuid":"a26219fc-94a0-4f63-9404-04c2a6ac99e4","name":"최고…` | 생성자 정보 객체 (uuid/name — creator 관계 파생) |
| created_at | string | `2026-10-16 10:00:00` | 생성 일시 |
| updated_at | string | `2026-10-16 10:00:00` | 최종 수정 일시 |
| abilities | object | `{"can_create":true,"can_update":true,"can_delete":true,"c…` | 현재 사용자가 이 리소스에 수행 가능한 작업 불리언 맵 (소유 행이면 `can_delete` 는 false) |

_`last_run_at`·`next_run_at` 은 값이 있을 때만 실립니다._

**응답 예시**

```http
HTTP/1.1 201
```

```json
{
    "success": true,
    "message": "스케줄이 생성되었습니다.",
    "data": {
        "id": 12,
        "name": "nightly_cache_clear",
        "description": {
            "ko": "매일 새벽 3시에 캐시를 비웁니다."
        },
        "type": "artisan",
        "type_label": "Artisan 커맨드",
        "command": "cache:clear",
        "expression": "0 3 * * *",
        "frequency": "daily",
        "frequency_label": "매일",
        "label": {
            "ko": "야간 캐시 정리",
            "en": "Nightly cache clear"
        },
        "label_localized": "야간 캐시 정리",
        "description_localized": "매일 새벽 3시에 캐시를 비웁니다.",
        "owner": {
            "type": "custom",
            "identifier": null,
            "name": "직접 등록"
        },
        "is_owned": false,
        "locked_fields": [],
        "overridden_fields": [],
        "enabled_condition": [],
        "unmet_conditions": [],
        "dispatch_mode": "queue",
        "history_mode": "failures_only",
        "without_overlapping": 1440,
        "on_one_server": true,
        "notify_on_failure": true,
        "queue_connection": null,
        "queue_name": null,
        "timezone": "Asia/Seoul",
        "run_in_maintenance": false,
        "timeout": 600,
        "is_active": true,
        "last_result": "never",
        "last_result_label": "미실행",
        "last_duration": null,
        "extension_type": null,
        "extension_identifier": null,
        "creator": {
            "uuid": "a26219fc-94a0-4f63-9404-04c2a6ac99e4",
            "name": "최고관리자"
        },
        "created_at": "2026-10-16 10:00:00",
        "updated_at": "2026-10-16 10:00:00",
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) — 입력 금지 필드(`extension_type`·`extension_identifier`·`enabled_condition`·`command_class`)를 보낸 경우 포함 |

<!-- @generated:end -->

**설명** 새 예약 작업을 생성합니다. `core.schedules.create` 권한이 필요합니다. `type`(artisan/shell/url/job), `command`, `expression`, `frequency` 와 표시 명칭 `label` 을 지정하며 생성자(creator)는 현재 사용자로 자동 기록됩니다. 검증 실패 시 422로 응답하고, 성공 시 201과 생성된 스케줄 리소스를 반환합니다. 관리자 스케줄 등록 폼에 사용합니다.

**7.2.0 변경** API 로 등록한 작업은 언제나 **직접 등록**(소유 확장 없음)입니다 — `extension_type`·`extension_identifier`·`enabled_condition`·`command_class` 를 보내면 422(`schedule.validation.field_not_editable`)입니다. 코어·확장 작업은 각자의 선언에서 동기화됩니다. `without_overlapping` 은 켜고 끄기(boolean)가 아니라 잠금 유지 시간(분, 0~10080)이며, 옛 boolean 값은 `true` → 1440분, `false` → 0 으로 바꿔 받습니다. 새 필드 `label`(다국어 표시 명칭)·`dispatch_mode`·`queue_connection`·`queue_name`·`history_mode`·`on_one_server`·`notify_on_failure`·`timezone` 이 추가됐고, 주기에 `everyThirtySeconds`, 유형에 `job` 이 추가됐습니다.


### DELETE /api/admin/schedules/bulk
<!-- @generated:start:api.admin.schedules.bulk-delete -->
- **라우트명**: `api.admin.schedules.bulk-delete`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@bulkDelete`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.delete`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| ids | query | array | 예 | min 1 | 대상 리소스 식별자 배열 (대량 작업 대상) |
| ids.* | query | integer | 예 | 존재하는 직접 등록 스케줄 ID | 삭제할 스케줄 ID — 존재하지 않거나 코어·확장이 등록한(소유) 스케줄이면 422 (`schedule.validation.id_exists_or_owned`) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.schedule.bulk_delete_validation_rules`).

**요청 예시**

```http
DELETE /api/admin/schedules/bulk?ids=%EC%98%88%EC%8B%9C%EA%B0%92 HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| deleted_count | integer | `3` | 실제로 삭제된 스케줄 건수 (`ScheduleService::bulkDelete` 반환값) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "선택한 스케줄이 삭제되었습니다.",
    "data": {
        "deleted_count": 3
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.delete`)이 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) — `ids` 중 하나라도 없거나 코어·확장 소유 스케줄이면 `errors["ids.N"]` 에 `schedule.validation.id_exists_or_owned` |

<!-- @generated:end -->

**설명** 전달된 `ids` 배열의 스케줄을 일괄 삭제합니다. `core.schedules.delete` 권한이 필요합니다. 삭제 처리 결과 요약을 반환하며, 검증 실패 시 422로 응답합니다. 목록에서 여러 스케줄을 선택해 한 번에 제거하는 동작에 사용합니다. 7.2.0 부터 코어·확장이 등록한 스케줄은 지울 수 없습니다 — 하나라도 섞여 있으면 요청 전체가 422 이고 아무것도 지워지지 않습니다. 실행을 멈추려면 `PATCH /api/admin/schedules/bulk-status` 로 비활성화합니다.


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

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| updated_count | integer | `3` | 상태가 실제로 변경된 스케줄 건수 (`ScheduleService::bulkUpdateStatus` 반환값) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "선택한 스케줄의 상태가 변경되었습니다.",
    "data": {
        "updated_count": 3
    }
}
```

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

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — 컨트롤러가 `success('schedule.history_delete_success')` 를 데이터 없이 호출)._

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "실행 이력이 삭제되었습니다.",
    "data": null
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.delete`)이 없는 경우 |
| 404 | Not Found | 지정한 `historyId` 의 실행 이력이 존재하지 않는 경우 (`schedule.history_not_found`) |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->


**설명** 특정 실행 이력 레코드를 삭제합니다. `core.schedules.delete` 권한이 필요합니다. 대상 이력이 없으면 404로 응답합니다. 스케줄 상세의 실행 이력 목록에서 개별 이력을 제거할 때 사용합니다.


### GET /api/admin/schedules/owners
<!-- @generated:start:api.admin.schedules.owners -->
- **라우트명**: `api.admin.schedules.owners`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@owners`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/schedules/owners HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_배열 응답: `data[]` 항목의 필드._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| type | string | `module` | 소유 타입: core(코어), module(모듈), plugin(플러그인), custom(직접 등록) |
| identifier | string\|null | `sirsoft-ecommerce` | 소유 식별자 (코어는 `core`, 직접 등록은 null) |
| name | string | `이커머스` | 현재 로케일로 해석한 소유자 이름 (확장을 찾지 못하면 식별자) |
| schedule_count | integer | `4` | 이 소유자의 스케줄 수 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "스케줄 소유자 목록을 조회했습니다.",
    "data": [
        {
            "type": "core",
            "identifier": "core",
            "name": "코어",
            "schedule_count": 9
        },
        {
            "type": "module",
            "identifier": "sirsoft-ecommerce",
            "name": "이커머스",
            "schedule_count": 4
        },
        {
            "type": "custom",
            "identifier": null,
            "name": "직접 등록",
            "schedule_count": 2
        }
    ]
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.read`)이 없는 경우 |
| 500 | Internal Server Error | 집계 중 오류 (`schedule.owners_fetch_failed`) |

<!-- @generated:end -->

**설명** 스케줄 목록의 소유 필터 선택지를 만들기 위해 소유자별 스케줄 수를 조회합니다(7.2.0). `core.schedules.read` 권한이 필요합니다. 순서는 코어 → 모듈 → 플러그인(각각 이름순) → 직접 등록이며, 스케줄이 0건인 소유자는 넣지 않습니다. 확장 이름은 요청 로케일로 해석됩니다. 받은 `type`·`identifier` 를 목록 API 의 `extension_type`·`extension_identifier` 쿼리로 그대로 넘기면 됩니다.


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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->



**설명** 스케줄 전체의 집계 통계를 조회합니다. `core.schedules.read` 권한이 필요합니다. 전체/활성/비활성 수와 마지막 실행 결과별(성공/실패/실행중/미실행) 건수를 반환합니다. 관리자 스케줄 대시보드의 요약 카드 표시에 사용합니다.


### GET /api/admin/schedules/status
<!-- @generated:start:api.admin.schedules.status -->
- **라우트명**: `api.admin.schedules.status`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@status`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/schedules/status HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| heartbeat | object | `{"last_tick_at":"…","minutes_ago":1,"healthy":true}` | 스케줄러 동작 신호 |
| heartbeat.last_tick_at | string\|null | `2026-10-16T09:59:00+09:00` | 스케줄러가 마지막으로 돈 시각 (보는 사람의 시간대). 한 번도 돌지 않았으면 null |
| heartbeat.minutes_ago | integer\|null | `1` | 마지막 실행 뒤 지난 분 수 |
| heartbeat.healthy | boolean | `true` | 마지막 실행이 3분 이내면 true. 한 번도 돌지 않았으면(cron 미등록) false |
| queue | object | `{"driver":"database",…}` | 큐 요약 — 필드는 [queue.md 의 `GET /api/admin/schedules/queue/status`](queue.md) 응답 중 `driver`·`connection`·`measurable`·`pending_count`·`failed_count`·`workers` 와 같습니다 |
| shell_enabled | boolean | `false` | Shell 유형을 쓸 수 있는지 — `.env` 의 `SCHEDULE_SHELL_ENABLED=true` 이고 `SCHEDULE_SHELL_ALLOWED_BINARIES` 에 실행 파일이 1개 이상일 때만 true. 등록·수정 창이 Shell 선택지를 잠그는 데 씁니다 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "스케줄러 상태를 조회했습니다.",
    "data": {
        "heartbeat": {
            "last_tick_at": "2026-10-16T09:59:00+09:00",
            "minutes_ago": 1,
            "healthy": true
        },
        "queue": {
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
            }
        },
        "shell_enabled": false
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.read`)이 없는 경우 |
| 500 | Internal Server Error | 상태 조회 중 오류 (`schedule.status_fetch_failed`) |

<!-- @generated:end -->

**설명** 스케줄 관리 화면 상단 상태 카드용으로 스케줄러 동작 신호와 큐 요약을 한 번에 조회합니다(7.2.0). `core.schedules.read` 권한이 필요합니다. `heartbeat.healthy=false` 면 서버 cron 에 스케줄러(`php artisan schedule:run`)가 등록되지 않았거나 멈춘 것이므로 화면이 cron 등록 안내를 띄웁니다. 신호는 캐시에만 남으므로 다중 서버에서는 공유 캐시를 전제합니다(파일 캐시면 서버마다 따로 보입니다). 큐 요약은 큐 권한(`core.queue_jobs.read`) 없이도 볼 수 있는 요약값이며, 대기·실패 작업 목록 자체는 [큐 API](queue.md) 를 씁니다.


### GET /api/admin/schedules/security
<!-- @generated:start:api.admin.schedules.security -->
- **라우트명**: `api.admin.schedules.security`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@security`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.read`

**요청 파라미터**

_요청 파라미터 없음._

**요청 예시**

```http
GET /api/admin/schedules/security HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| override_file | object | `{"path":"storage/app/schedule_security.json",…}` | 서버 관리자 덮어쓰기 파일 상태 |
| override_file.path | string | `storage/app/schedule_security.json` | 파일 위치 (프로젝트 기준 상대경로 — 서버 절대경로는 싣지 않음) |
| override_file.exists | boolean | `true` | 파일이 있으면 true |
| override_file.valid | boolean | `true` | 형식이 맞으면 true. 파일이 없어도 true. false 면 파일 전체를 무시하고 기본 정책만 적용 중 |
| override_file.error_code | string\|null | `null` | 형식 오류 코드 — `invalid_json`·`not_object`·`unknown_key`·`not_overridable`·`invalid_command`·`invalid_allowlist_entry`·`invalid_list`·`too_large`·`unreadable` |
| override_file.error | string\|null | `null` | 형식 오류 안내 문구 (`schedule.security.override_error.*` 현지화) |
| shell | object | `{"enabled":false,…}` | Shell 유형 정책 (`.env` 소유 — 덮어쓰기 파일로 바꿀 수 없음) |
| shell.enabled | boolean | `false` | Shell 유형을 쓸 수 있는지 (게이트 on + 허용 실행 파일 1개 이상). `GET /api/admin/schedules/status` 의 `shell_enabled` 와 같은 값 |
| shell.gate_enabled | boolean | `false` | `SCHEDULE_SHELL_ENABLED` 값 |
| shell.allowed_binaries | string[] | `[]` | `SCHEDULE_SHELL_ALLOWED_BINARIES` 의 실행 파일 basename 목록 |
| shell.source | string | `env` | 정책 출처 (항상 `env`) |
| artisan.allowlist | object[] | `[{"command":"cache:clear",…}]` | 유효 허용목록 (기본 + 덮어쓰기) |
| artisan.allowlist[].command | string | `cache:clear` | 명령 이름 (소문자) |
| artisan.allowlist[].options | string[] | `[]` | 허용 롱옵션 이름 (`--` 없이, 기본 + 덮어쓰기 합집합) |
| artisan.allowlist[].max_arguments | integer | `0` | 허용 위치 인자 개수 |
| artisan.allowlist[].source | string | `core` | `core`(기본 정책) 또는 `override`(덮어쓰기로 더한 명령) |
| artisan.allowlist[].override_options | string[] | `[]` | 기본 항목에 덮어쓰기가 더한 옵션 (기본 항목이 아니면 빈 배열) |
| artisan.allowlist[].denied | boolean | `false` | 거부 목록·거부 접두사에도 걸려 실제로는 거부되는지 (거부가 이긴다) |
| artisan.denylist | object[] | `[{"command":"tinker","source":"core"}]` | 유효 거부 목록 (`command`·`source`) |
| artisan.denylist_prefixes | object[] | `[{"prefix":"make:","source":"core"}]` | 유효 거부 접두사 (`prefix`·`source`) |
| artisan.allow_extension_commands | boolean | `true` | 설치된 확장 소유 명령 자동 허용 여부 (기본 정책 소유) |
| job.allowed_classes | object[] | `[{"class":"App\\Jobs\\GenerateSitemapJob","source":"core"}]` | 유효 Job 허용 클래스 — `source` 는 `core`·`override`·`extension`(훅 `core.schedule.filter_allowed_job_classes`) |
| url | object | `{"block_private_hosts":true,"source":"settings"}` | URL 유형 정책 — 내부망 주소 차단 여부 (환경설정 `security.allow_internal_outbound_urls` 의 반대) |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "스케줄 보안 정책을 조회했습니다.",
    "data": {
        "override_file": {
            "path": "storage/app/schedule_security.json",
            "exists": true,
            "valid": true,
            "error_code": null,
            "error": null
        },
        "shell": {
            "enabled": false,
            "gate_enabled": false,
            "allowed_binaries": [],
            "source": "env"
        },
        "artisan": {
            "allowlist": [
                {
                    "command": "cache:clear",
                    "options": [],
                    "max_arguments": 0,
                    "source": "core",
                    "override_options": [],
                    "denied": false
                },
                {
                    "command": "seo:prune-stats",
                    "options": ["days", "chunk"],
                    "max_arguments": 0,
                    "source": "core",
                    "override_options": ["chunk"],
                    "denied": false
                },
                {
                    "command": "backup:run",
                    "options": ["only-db"],
                    "max_arguments": 0,
                    "source": "override",
                    "override_options": [],
                    "denied": false
                }
            ],
            "denylist": [
                {"command": "tinker", "source": "core"},
                {"command": "inspire", "source": "override"}
            ],
            "denylist_prefixes": [
                {"prefix": "make:", "source": "core"}
            ],
            "allow_extension_commands": true
        },
        "job": {
            "allowed_classes": [
                {"class": "App\\Jobs\\GenerateSitemapJob", "source": "core"}
            ]
        },
        "url": {
            "block_private_hosts": true,
            "source": "settings"
        }
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.read`)이 없는 경우 |
| 500 | Internal Server Error | 정책 조회 중 오류 (`schedule.security_fetch_failed`) |

<!-- @generated:end -->

**설명** 스케줄 실행 보안 정책의 유효 값(기본 정책 `config/schedule_security.php` + 서버 관리자 덮어쓰기 파일 `storage/app/schedule_security.json`)을 항목별 출처와 함께 읽기 전용으로 조회합니다(#17). `core.schedules.read` 권한이 필요합니다. 화면에서 정책을 바꾸는 API 는 없습니다 — 서버 관리자가 덮어쓰기 파일(더하기만, 기본 거부 항목은 풀 수 없음)과 `.env`(Shell)로 바꿉니다. 덮어쓰기 파일 형식이 어긋나면 파일 전체를 무시하고 `override_file.valid=false`·`error` 로 알립니다. 파일 형식: [스케줄 관리 문서 「보안 정책 덮어쓰기」](../scheduling.md#101-보안-정책-덮어쓰기-storageappschedule_securityjson).


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
DELETE /api/admin/schedules/{schedule} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_이 엔드포인트는 `data` 를 반환하지 않습니다 (성공 메시지만 — 컨트롤러가 `success('schedule.delete_success')` 를 데이터 없이 호출)._

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
| 422 | Unprocessable Entity | 코어·확장이 등록한(소유) 스케줄인 경우 — `errors.general` 에 `schedule.owned_not_deletable` |

<!-- @generated:end -->

**설명** 단일 스케줄을 삭제합니다. `core.schedules.delete` 권한이 필요합니다. 경로의 `schedule` 은 라우트 모델 바인딩으로 해석되어 존재하지 않으면 404가 됩니다. 관리자 스케줄 관리 화면의 개별 삭제 동작에 사용합니다. 7.2.0 부터 코어·확장이 등록한 스케줄은 삭제할 수 없습니다(선언 동기화가 다시 만들기 때문) — 422 로 거부되며, 실행을 멈추려면 비활성으로 바꿉니다. 행의 `abilities.can_delete` 가 false 인 행이 이 경우입니다.

```json
{
    "message": "코어·확장이 등록한 작업은 삭제할 수 없습니다. 실행을 멈추려면 비활성으로 바꾸세요.",
    "errors": {
        "general": [
            "코어·확장이 등록한 작업은 삭제할 수 없습니다. 실행을 멈추려면 비활성으로 바꾸세요."
        ]
    }
}
```


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
GET /api/admin/schedules/{schedule} HTTP/1.1
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
| description | object\|null | `{"ko":"문서 실측용 스케줄"}` | 설명 원본 (로케일별 값 객체) |
| type | string | `artisan` | 작업 유형: artisan(Artisan 커맨드), shell(쉘 명령), url(URL 호출), job(큐 Job 클래스) |
| type_label | string | `Artisan 커맨드` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| command | string | `cache:clear` | 명령어 또는 URL |
| expression | string | `0 3 * * *` | Cron 표현식 |
| frequency | string | `daily` | 실행 주기: everyThirtySeconds(30초마다), everyMinute(매분), hourly(매시간), daily(매일), weekly(매주), monthly(매월), custom(사용자 정의) |
| frequency_label | string | `매일` | `frequency` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| label | object\|null | `{"ko":"API 문서 샘플 스케줄","en":"API doc sample schedule"}` | 표시 명칭 원본 (로케일별 값 객체) |
| label_localized | string | `API 문서 샘플 스케줄` | 현재 로케일로 해석한 표시 명칭 (없으면 이름) |
| description_localized | string\|null | `문서 실측용 스케줄` | 현재 로케일로 해석한 설명 |
| owner | object | `{"type":"custom","identifier":null,"name":"직접 등록"}` | 소유자 — `type`(core/module/plugin/custom)·`identifier`·`name`(현지화 이름) |
| is_owned | boolean | `false` | 코어·확장이 등록한 작업이면 true |
| locked_fields | string[] | `[]` | 바꿀 수 없는 필드 — 소유 행이면 `command`·`type`·`name`·`enabled_condition`·`timezone`, 직접 등록이면 빈 배열 |
| overridden_fields | string[] | `[]` | 운영자가 선언값에서 바꾼 필드 목록 (소유 행) |
| enabled_condition | array | `[]` | 실행 조건 선언 (코어·확장이 정함, 읽기 전용) |
| unmet_conditions | array | `[]` | 지금 충족되지 않은 실행 조건 — 항목 `{source, key, operator}` |
| dispatch_mode | string | `sync` | 실행 방식: sync(바로 실행), queue(큐로 보내기) |
| history_mode | string | `all` | 실행 기록 방식: all(모든 실행 기록), failures_only(실패만 기록) |
| without_overlapping | integer | `1440` | 중복 실행 방지 잠금 유지 시간(분). 0 = 끔 |
| on_one_server | boolean | `false` | 한 서버에서만 실행 |
| notify_on_failure | boolean | `true` | 실패 시 알림 발송 |
| queue_connection | string\|null | `null` | 큐로 보낼 때의 연결 (null = 기본) |
| queue_name | string\|null | `null` | 큐로 보낼 때의 큐 (null = 연결의 기본 큐) |
| timezone | string\|null | `null` | Cron 해석 시간대 (null = 스케줄 기본 시간대) |
| run_in_maintenance | boolean | `false` | 점검 모드 실행 여부: 0(비실행), 1(실행) |
| timeout | integer | `300` | 실행 제한 시간 (초) |
| is_active | boolean | `true` | active 여부 |
| last_result | string | `success` | 마지막 실행 결과: success(성공), failed(실패), running(실행중), never(미실행) |
| last_result_label | string | `성공` | `last_result` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| last_run_at | string | `2026-07-30T22:55:00+09:00` | last run 일시 |
| last_duration | null | `null` | 마지막 실행의 소요 시간을 사람이 읽는 문자열로 포맷한 값 (예: "45초", "2분 3초" — 마지막 실행 이력의 duration 파생, 실행 이력이 없으면 null) |
| next_run_at | string | `2026-08-01T12:00:00+09:00` | next run 일시 |
| extension_type | null | `null` | 확장 소유 타입: core(코어), module(모듈), plugin(플러그인), NULL(사용자 정의) |
| extension_identifier | null | `null` | 확장 식별자 (예: core, sirsoft-board, sirsoft-payment) |
| creator | object | `{"uuid":"a2640dae-e87c-4a28-b4f1-481fd961dc02","name":"AP…` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| created_at | string | `2026-07-31 22:55:00` | 생성 일시 |
| updated_at | string | `2026-07-31 22:55:00` | 최종 수정 일시 |
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
        "description": {
            "ko": "문서 실측용 스케줄"
        },
        "type": "artisan",
        "type_label": "Artisan 커맨드",
        "command": "cache:clear",
        "expression": "0 3 * * *",
        "frequency": "daily",
        "frequency_label": "매일",
        "label": {
            "ko": "API 문서 샘플 스케줄",
            "en": "API doc sample schedule"
        },
        "label_localized": "API 문서 샘플 스케줄",
        "description_localized": "문서 실측용 스케줄",
        "owner": {
            "type": "custom",
            "identifier": null,
            "name": "직접 등록"
        },
        "is_owned": false,
        "locked_fields": [],
        "overridden_fields": [],
        "enabled_condition": [],
        "unmet_conditions": [],
        "dispatch_mode": "sync",
        "history_mode": "all",
        "without_overlapping": 1440,
        "on_one_server": false,
        "notify_on_failure": true,
        "queue_connection": null,
        "queue_name": null,
        "timezone": null,
        "run_in_maintenance": false,
        "timeout": 300,
        "is_active": true,
        "last_result": "success",
        "last_result_label": "성공",
        "last_run_at": "2026-07-30T22:55:00+09:00",
        "last_duration": null,
        "next_run_at": "2026-08-01T12:00:00+09:00",
        "extension_type": null,
        "extension_identifier": null,
        "creator": {
            "uuid": "a2640dae-e87c-4a28-b4f1-481fd961dc02",
            "name": "API 문서 샘플 사용자"
        },
        "created_at": "2026-07-31 22:55:00",
        "updated_at": "2026-07-31 22:55:00",
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 단일 스케줄의 상세 정보를 조회하며 생성자(creator) 정보를 함께 로드합니다. `core.schedules.read` 권한이 필요합니다. 경로의 `schedule` 은 라우트 모델 바인딩으로 해석되어 없으면 404가 됩니다. 관리자 스케줄 상세/수정 화면의 초기 데이터 로딩에 사용합니다. 7.2.0 부터 응답에 표시 명칭(`label`·`label_localized`)·소유자(`owner`·`is_owned`)·잠금 필드(`locked_fields`)·실행 조건(`enabled_condition`·`unmet_conditions`)·실행 방식(`dispatch_mode`·`queue_connection`·`queue_name`)·기록 방식(`history_mode`)·`on_one_server`·`notify_on_failure`·`timezone` 이 실리고, `without_overlapping` 은 boolean 이 아니라 잠금 유지 시간(분, 0 = 끔) 정수입니다. `description` 은 로케일별 값 객체 원본이며 현재 로케일 값은 `description_localized` 입니다. `last_run_at`·`next_run_at` 은 값이 있을 때만 실립니다.


### PUT /api/admin/schedules/{schedule}
<!-- @generated:start:api.admin.schedules.update -->
- **라우트명**: `api.admin.schedules.update`
- **컨트롤러**: `App\Http\Controllers\Api\Admin\ScheduleController@update`
- **인증/권한**: `auth:sanctum` + `permission:core.schedules.update`

**요청 파라미터**

| 이름 | 위치 | 타입 | 필수 | 허용값 | 용도 |
| --- | --- | --- | --- | --- | --- |
| schedule | path | string | 예 | — | 대상 schedule의 식별자 |
| name | body | string | 아니오 | max 255 (보내면 비울 수 없음) | 식별자 (기계 식별자). 소유 행은 잠금 필드 |
| label | body | object\|string | 아니오 | 로케일별 max 255 | 작업명. 직접 등록 행은 보내면 사이트 기본 언어 값 필수, 소유 행은 비울 수 있음(비우면 이름으로 표시) |
| description | body | object\|string | 아니오 | 로케일별 max 1000 | 설명 (로케일별 값 객체, 문자열이면 다국어 객체로 올림) |
| type | body | string | 아니오 | `artisan`, `shell`, `url`, `job` (보내면 비울 수 없음) | 작업 유형. 소유 행은 잠금 필드 |
| command | body | string | 아니오 | max 2000 (보내면 비울 수 없음) | 실행할 명령 (`type` 에 따라 Artisan 커맨드 / 쉘 명령 / 호출할 URL). `type=url` 이면 내부 네트워크 주소(사설 IP·루프백·`localhost`·`*.internal` 등)는 거부되어 422 로 응답합니다 — 서버가 내부망으로 요청을 보내는 것을 막기 위함(SSRF). 사내 엔드포인트를 호출해야 하면 환경설정의 `security.allow_internal_outbound_urls` 를 켜세요. 이 설정을 켜도 userinfo(`https://a@b/`) 위장과 http/https 이외 scheme 은 계속 거부됩니다.<br>`type=shell` 이면 기본적으로 모든 명령이 거부되어 422 로 응답합니다 — 스케줄 권한만 위임받은 계정이 서버에서 임의 OS 명령을 실행하는 것을 막기 위함입니다. 실행하려면 서버의 `.env` 에서 `SCHEDULE_SHELL_ENABLED=true` 로 게이트를 열고 `SCHEDULE_SHELL_ALLOWED_BINARIES` 에 허용할 실행 파일명을 등록해야 합니다(관리자 화면에서는 넓힐 수 없는 코드 소유 정책). 허용된 실행 파일이라도 셸 메타문자(`|`, `;`, `$`, 백틱, 리다이렉션, 따옴표 등)가 포함되면 거부되며, 실행 시 셸을 경유하지 않습니다. 복합 명령이 필요하면 래퍼 스크립트를 만들어 화이트리스트에 등록하세요.<br>`type=artisan` 이면 서버가 허용한 유지보수 명령만 등록할 수 있고 그 밖의 명령은 거부되어 422 로 응답합니다 — 캐시·컴파일 산출물 정리(`cache:clear` 등), 큐 처리(`queue:work`·`queue:restart` 등), 만료 데이터 정리, SEO 관련 명령이 여기 해당합니다. `tinker` 처럼 임의 코드를 실행하거나 `migrate`·`db:wipe`·`make:*` 처럼 스키마를 바꾸거나 코드를 생성하는 명령은 허용 목록에 오르지 않습니다(관리자 화면에서는 넓힐 수 없는 코드 소유 정책 — 서버 관리자는 `storage/app/schedule_security.json` 으로 허용·거부 항목을 더할 수 있고, 기본 거부 항목은 풀 수 없습니다. 유효 정책은 `GET /api/admin/schedules/security`). 설치된 모듈·플러그인이 제공하는 명령은 자동으로 허용됩니다.<br>허용된 명령이라도 그 명령이 선언하지 않은 옵션, 위치 인자, 단축 옵션(`-v`)이 붙으면 거부됩니다. 형식은 `명령명 --옵션[=값]` 만 허용하며 따옴표·역슬래시가 포함된 입력은 거부됩니다 — 저장할 때 해석한 명령과 실제로 실행되는 명령이 달라지지 않도록 하기 위함입니다. 거부 사유(허용 목록 밖 / 형식 오류 / 허용되지 않은 옵션 / 추가 인자 불가)는 응답 메시지로 구분되어 안내됩니다.<br>`type=job` 이면 보안 허용목록(`schedule_security.job.allowed_classes`, 서버 관리자는 덮어쓰기 파일로, 확장은 `core.schedule.filter_allowed_job_classes` 필터로 추가)에 등록된 Job 클래스 FQCN 만 받고 그 밖은 422(`schedule.validation.job_not_allowed`) 입니다.<br>여러 줄로 보내도 저장 전에 줄바꿈·연속 공백·앞뒤 공백을 공백 하나로 접어 한 줄로 검사·저장합니다 |
| expression | body | string | 아니오 | max 100 (보내면 비울 수 없음) | 실행 시각을 정의하는 Cron 표현식 (예: `0 3 * * *`, 다음 실행 시각 next_run_at 계산의 기준) |
| frequency | body | string | 아니오 | `everyThirtySeconds`, `everyMinute`, `hourly`, `daily`, `weekly`, `monthly`, `custom` (보내면 비울 수 없음) | 실행 주기 |
| dispatch_mode | body | string | 아니오 | `sync`, `queue` | 실행 방식 |
| queue_connection | body | string | 아니오 | max 50 | 큐로 보낼 연결 (비우면 기본 연결) |
| queue_name | body | string | 아니오 | max 100 | 큐로 보낼 큐 (비우면 연결의 기본 큐) |
| history_mode | body | string | 아니오 | `all`, `failures_only` | 실행 기록 방식 |
| without_overlapping | body | integer | 아니오 | min 0, max 10080 | 중복 실행 방지 잠금 유지 시간(분). 0 = 끔. 옛 boolean 은 `true` → 1440, `false` → 0 |
| on_one_server | body | boolean | 아니오 | — | 한 서버에서만 실행 |
| notify_on_failure | body | boolean | 아니오 | — | 실패 시 알림 발송 |
| timezone | body | string | 아니오 | max 64, 유효한 시간대 | Cron 해석 시간대. 소유 행은 잠금 필드 |
| run_in_maintenance | body | boolean | 아니오 | — | 점검 모드 중 실행 여부 |
| timeout | body | integer | 아니오 | min 1, max 86400 | 타임아웃 (초) |
| is_active | body | boolean | 아니오 | — | 활성 여부 (true 활성 / false 비활성) |
| enabled_condition | body | — | 아니오 | 보내면 422 (`prohibited`) | 실행 조건은 코어·확장 선언만 정한다 |
| command_class | body | — | 아니오 | 보내면 422 (`prohibited`) | 명령 클래스는 선언만 정한다 |
| extension_type | body | — | 아니오 | 보내면 422 (`prohibited`) | 소유자는 바꿀 수 없다 |
| extension_identifier | body | — | 아니오 | 보내면 422 (`prohibited`) | 위와 같음 |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.schedule.update_validation_rules`).

**요청 예시**

```http
PUT /api/admin/schedules/{schedule} HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
Content-Type: application/json

{
    "name": "nightly_cache_clear",
    "label": {
        "ko": "예시 명칭",
        "en": "Example label"
    },
    "description": {
        "ko": "예시 설명입니다."
    },
    "type": "artisan",
    "command": "cache:clear",
    "expression": "0 4 * * *",
    "frequency": "daily",
    "dispatch_mode": "sync",
    "history_mode": "all",
    "without_overlapping": 1440,
    "on_one_server": false,
    "notify_on_failure": true,
    "run_in_maintenance": false,
    "timeout": 600,
    "is_active": true
}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `1` | 기본 키 (내부 식별자) |
| name | string | `nightly_cache_clear` | 식별 이름 (기계 식별자) |
| description | object\|null | `{"ko":"예시 설명입니다."}` | 설명 원본 (로케일별 값 객체) |
| type | string | `artisan` | 작업 유형: artisan(Artisan 커맨드), shell(쉘 명령), url(URL 호출), job(큐 Job 클래스) |
| type_label | string | `Artisan 커맨드` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| command | string | `cache:clear` | 명령어·URL 또는 Job 클래스 |
| expression | string | `0 4 * * *` | Cron 표현식 |
| frequency | string | `daily` | 실행 주기: everyThirtySeconds(30초마다), everyMinute(매분), hourly(매시간), daily(매일), weekly(매주), monthly(매월), custom(사용자 정의) |
| frequency_label | string | `매일` | `frequency` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| label | object\|null | `{"ko":"예시 명칭","en":"Example label"}` | 표시 명칭 원본 (로케일별 값 객체) |
| label_localized | string | `예시 명칭` | 현재 로케일로 해석한 표시 명칭 (없으면 이름) |
| description_localized | string\|null | `예시 설명입니다.` | 현재 로케일로 해석한 설명 |
| owner | object | `{"type":"custom","identifier":null,"name":"직접 등록"}` | 소유자 — `type`(core/module/plugin/custom)·`identifier`·`name`(현지화 이름) |
| is_owned | boolean | `false` | 코어·확장이 등록한 작업이면 true |
| locked_fields | string[] | `[]` | 바꿀 수 없는 필드 — 소유 행이면 `command`·`type`·`name`·`enabled_condition`·`timezone`, 직접 등록이면 빈 배열 |
| overridden_fields | string[] | `[]` | 운영자가 선언값에서 바꾼 필드 목록 (소유 행) |
| enabled_condition | array | `[]` | 실행 조건 선언 (코어·확장이 정함, 읽기 전용) |
| unmet_conditions | array | `[]` | 지금 충족되지 않은 실행 조건 — 항목 `{source, key, operator}` |
| dispatch_mode | string | `sync` | 실행 방식: sync(바로 실행), queue(큐로 보내기) |
| history_mode | string | `all` | 실행 기록 방식: all(모든 실행 기록), failures_only(실패만 기록) |
| without_overlapping | integer | `1440` | 중복 실행 방지 잠금 유지 시간(분). 0 = 끔 |
| on_one_server | boolean | `false` | 한 서버에서만 실행 |
| notify_on_failure | boolean | `true` | 실패 시 알림 발송 |
| queue_connection | string\|null | `null` | 큐로 보낼 때의 연결 (null = 기본) |
| queue_name | string\|null | `null` | 큐로 보낼 때의 큐 (null = 연결의 기본 큐) |
| timezone | string\|null | `null` | Cron 해석 시간대 (null = 스케줄 기본 시간대) |
| run_in_maintenance | boolean | `false` | 점검 모드 실행 여부: 0(비실행), 1(실행) |
| timeout | integer | `600` | 실행 제한 시간 (초) |
| is_active | boolean | `true` | active 여부 |
| last_result | string | `success` | 마지막 실행 결과: success(성공), failed(실패), running(실행중), never(미실행) |
| last_result_label | string | `성공` | `last_result` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| last_run_at | string | `2026-07-30T22:55:00+09:00` | last run 일시 |
| last_duration | null | `null` | 마지막 실행 소요 시간 (초/밀리초 — 실행 이력 파생) |
| extension_type | string\|null | `null` | 소유 확장 타입 (core/module/plugin, 직접 등록이면 null) — 수정으로 바뀌지 않음 |
| extension_identifier | string\|null | `null` | 소유 확장 식별자 (직접 등록이면 null) |
| creator | object | `{"uuid":"a2640dae-e87c-4a28-b4f1-481fd961dc02","name":"AP…` | 생성자 정보 객체 (uuid/name/email — creator 관계 파생) |
| created_at | string | `2026-07-31 22:55:00` | 생성 일시 |
| updated_at | string | `2026-08-04 21:53:48` | 최종 수정 일시 |
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
        "name": "nightly_cache_clear",
        "description": {
            "ko": "예시 설명입니다."
        },
        "type": "artisan",
        "type_label": "Artisan 커맨드",
        "command": "cache:clear",
        "expression": "0 4 * * *",
        "frequency": "daily",
        "frequency_label": "매일",
        "label": {
            "ko": "예시 명칭",
            "en": "Example label"
        },
        "label_localized": "예시 명칭",
        "description_localized": "예시 설명입니다.",
        "owner": {
            "type": "custom",
            "identifier": null,
            "name": "직접 등록"
        },
        "is_owned": false,
        "locked_fields": [],
        "overridden_fields": [],
        "enabled_condition": [],
        "unmet_conditions": [],
        "dispatch_mode": "sync",
        "history_mode": "all",
        "without_overlapping": 1440,
        "on_one_server": false,
        "notify_on_failure": true,
        "queue_connection": null,
        "queue_name": null,
        "timezone": null,
        "run_in_maintenance": false,
        "timeout": 600,
        "is_active": true,
        "last_result": "success",
        "last_result_label": "성공",
        "last_run_at": "2026-07-30T22:55:00+09:00",
        "last_duration": null,
        "extension_type": null,
        "extension_identifier": null,
        "creator": {
            "uuid": "a2640dae-e87c-4a28-b4f1-481fd961dc02",
            "name": "API 문서 샘플 사용자"
        },
        "created_at": "2026-07-31 22:55:00",
        "updated_at": "2026-08-04 21:53:48",
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
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`errors` 에 필드별 메시지) — 입력 금지 필드를 보낸 경우(`schedule.validation.field_not_editable`), 소유 행의 잠금 필드를 저장값과 다르게 보낸 경우(`schedule.validation.owned_field_locked`) 포함 |

<!-- @generated:end -->



**설명** 기존 스케줄 정보를 수정합니다. `core.schedules.update` 권한이 필요합니다. store 와 동일한 필드(`type`/`command`/`expression`/`frequency` 등)를 받으며 검증 실패 시 422로 응답합니다. 경로의 `schedule` 은 라우트 모델 바인딩으로 해석되며, 수정된 스케줄 리소스를 반환합니다. 관리자 스케줄 수정 폼에 사용합니다.

**7.2.0 변경** 모든 본문 필드가 선택이 되었습니다 — 보낸 필드만 검증·저장하며, `name`·`type`·`command`·`expression`·`frequency` 는 보내면 비울 수 없습니다. `type` 을 보내지 않고 `command` 만 바꾸면 저장된 유형 기준으로 명령 허용 검사를 합니다. 코어·확장이 등록한(소유) 행은 `command`·`type`·`name`·`enabled_condition`·`timezone` 이 잠깁니다(응답 `locked_fields`) — 저장값과 같은 값은 그대로 통과하고, 다른 값을 보내면 422(`schedule.validation.owned_field_locked`)입니다. 소유 행에서 운영자가 바꾼 필드는 `overridden_fields` 에 남습니다. `extension_type`·`extension_identifier`·`enabled_condition`·`command_class` 는 모든 행에서 입력 금지(422)이며, 새 필드와 `without_overlapping`(분) 규칙은 등록과 같습니다.


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
POST /api/admin/schedules/{schedule}/duplicate HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `13` | 기본 키 (내부 식별자) |
| name | string | `API 문서 샘플 스케줄 (복사본)` | 대상의 이름/명칭 (다국어 필드는 로케일별 값 객체) |
| description | object\|null | `{"ko":"문서 실측용 스케줄"}` | 설명 원본 (로케일별 값 객체) |
| type | string | `artisan` | 작업 유형: artisan(Artisan 커맨드), shell(쉘 명령), url(URL 호출), job(큐 Job 클래스) |
| type_label | string | `Artisan 커맨드` | `type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| command | string | `cache:clear` | 명령어 또는 URL |
| expression | string | `0 3 * * *` | Cron 표현식 |
| frequency | string | `daily` | 실행 주기: everyThirtySeconds(30초마다), everyMinute(매분), hourly(매시간), daily(매일), weekly(매주), monthly(매월), custom(사용자 정의) |
| frequency_label | string | `매일` | `frequency` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| label | object\|null | `{"ko":"API 문서 샘플 스케줄 (복사본)","en":"API doc sample schedule (Copy)"}` | 표시 명칭 원본 (로케일별 값 객체) |
| label_localized | string | `API 문서 샘플 스케줄 (복사본)` | 현재 로케일로 해석한 표시 명칭 (없으면 이름) |
| description_localized | string\|null | `문서 실측용 스케줄` | 현재 로케일로 해석한 설명 |
| owner | object | `{"type":"custom","identifier":null,"name":"직접 등록"}` | 소유자 — `type`(core/module/plugin/custom)·`identifier`·`name`(현지화 이름) |
| is_owned | boolean | `false` | 코어·확장이 등록한 작업이면 true |
| locked_fields | string[] | `[]` | 바꿀 수 없는 필드 — 소유 행이면 `command`·`type`·`name`·`enabled_condition`·`timezone`, 직접 등록이면 빈 배열 |
| overridden_fields | string[] | `[]` | 운영자가 선언값에서 바꾼 필드 목록 (소유 행) |
| enabled_condition | array | `[]` | 실행 조건 선언 (코어·확장이 정함, 읽기 전용) |
| unmet_conditions | array | `[]` | 지금 충족되지 않은 실행 조건 — 항목 `{source, key, operator}` |
| dispatch_mode | string | `sync` | 실행 방식: sync(바로 실행), queue(큐로 보내기) |
| history_mode | string | `all` | 실행 기록 방식: all(모든 실행 기록), failures_only(실패만 기록) |
| without_overlapping | integer | `1440` | 중복 실행 방지 잠금 유지 시간(분). 0 = 끔 |
| on_one_server | boolean | `false` | 한 서버에서만 실행 |
| notify_on_failure | boolean | `true` | 실패 시 알림 발송 |
| queue_connection | string\|null | `null` | 큐로 보낼 때의 연결 (null = 기본) |
| queue_name | string\|null | `null` | 큐로 보낼 때의 큐 (null = 연결의 기본 큐) |
| timezone | string\|null | `null` | Cron 해석 시간대 (null = 스케줄 기본 시간대) |
| run_in_maintenance | boolean | `false` | 점검 모드 실행 여부: 0(비실행), 1(실행) |
| timeout | integer | `300` | 실행 제한 시간 (초) |
| is_active | boolean | `false` | active 여부 |
| last_result | string | `never` | 마지막 실행 결과: success(성공), failed(실패), running(실행중), never(미실행) |
| last_result_label | string | `미실행` | `last_result` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| last_duration | null | `null` | 마지막 실행 소요 시간 (초/밀리초 — 실행 이력 파생) |
| extension_type | null | `null` | 이 리소스를 소유한 확장의 타입 (core/module/plugin/template) |
| extension_identifier | null | `null` | 이 리소스를 소유한 확장의 식별자 |
| created_at | string | `2026-08-04 21:53:48` | 생성 일시 |
| updated_at | string | `2026-08-04 21:53:48` | 최종 수정 일시 |
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
        "id": 13,
        "name": "API 문서 샘플 스케줄 (복사본)",
        "description": {
            "ko": "문서 실측용 스케줄"
        },
        "type": "artisan",
        "type_label": "Artisan 커맨드",
        "command": "cache:clear",
        "expression": "0 3 * * *",
        "frequency": "daily",
        "frequency_label": "매일",
        "label": {
            "ko": "API 문서 샘플 스케줄 (복사본)",
            "en": "API doc sample schedule (Copy)"
        },
        "label_localized": "API 문서 샘플 스케줄 (복사본)",
        "description_localized": "문서 실측용 스케줄",
        "owner": {
            "type": "custom",
            "identifier": null,
            "name": "직접 등록"
        },
        "is_owned": false,
        "locked_fields": [],
        "overridden_fields": [],
        "enabled_condition": [],
        "unmet_conditions": [],
        "dispatch_mode": "sync",
        "history_mode": "all",
        "without_overlapping": 1440,
        "on_one_server": false,
        "notify_on_failure": true,
        "queue_connection": null,
        "queue_name": null,
        "timezone": null,
        "run_in_maintenance": false,
        "timeout": 300,
        "is_active": false,
        "last_result": "never",
        "last_result_label": "미실행",
        "last_duration": null,
        "extension_type": null,
        "extension_identifier": null,
        "created_at": "2026-08-04 21:53:48",
        "updated_at": "2026-08-04 21:53:48",
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
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

<!-- @generated:end -->

**설명** 기존 스케줄을 복제해 새 스케줄을 만듭니다. `core.schedules.create` 권한이 필요합니다. 원본을 바탕으로 새 레코드를 생성하며, 성공 시 201과 복제된 스케줄 리소스를 반환합니다. 유사한 설정의 스케줄을 빠르게 추가하는 "복제" 동작에 사용합니다. 복제본은 언제나 **직접 등록** 작업입니다 — 원본이 코어·확장 소유여도 소유자(`extension_type`·`extension_identifier`)·실행 조건(`enabled_condition`)·수정 기록(`overridden_fields`)을 물려받지 않습니다. 이름과 각 로케일 표시 명칭 뒤에 「(복사본)」이 붙고, 비활성(`is_active=false`)·미실행(`last_result=never`) 상태로 만들어집니다.


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
| started_from | query | date | 아니오 | — | 실행 시작일 범위의 시작일 (이 날짜 이후 시작된 이력만 조회 — 보는 사람의 시간대 하루로 해석) |
| started_to | query | date | 아니오 | — | 실행 시작일 범위의 종료일 (이 날짜 이전 시작된 이력만 조회, started_from 이후여야 함) |
| sort_by | query | string | 아니오 | `started_at`, `ended_at`, `duration`, `status` | 정렬 기준 필드명 |
| sort_order | query | string | 아니오 | `asc`, `desc` | 정렬 방향 (asc 오름차순 / desc 내림차순) |

> 이 엔드포인트는 확장이 파라미터를 추가할 수 있습니다 (`core.schedule.history_list_validation_rules`).

**요청 예시**

```http
GET /api/admin/schedules/{schedule}/history?page=1&per_page=1&status=success&trigger_type=scheduled&started_from=2026-01-01&started_to=2026-01-01&sort_by=started_at&sort_order=asc HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_목록 응답: `data.data[]` 배열 항목의 필드 + `data.pagination`._

목록 항목은 `ScheduleHistoryResource::toListArray()` 산물입니다 (단건 실행 응답과 달리 `memory_usage`(raw)/`created_at`/`updated_at` 은 포함되지 않습니다. 실행 이력 창이 회차별 출력을 보여 주므로 `output`/`error_output` 은 포함합니다).

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `7` | 실행 이력의 기본 키 (내부 식별자) |
| schedule_id | integer | `1` | 이 이력이 속한 스케줄의 식별자 |
| started_at | string\|null | `2026-07-08T12:14:49+09:00` | 실행 시작 일시 (사용자 타임존 포맷) |
| ended_at | string\|null | `2026-07-08T12:14:49+09:00` | 실행 종료 일시 (사용자 타임존 포맷, 실행 중이면 null) |
| duration | integer\|null | `0` | 실행 소요 시간 |
| duration_formatted | string\|null | `45초` | `duration` 값의 표시용 포맷 문자열 (모델 접근자 파생, 없으면 null) |
| status | string | `success` | 실행 결과 상태: success(성공), failed(실패), running(실행중) |
| status_label | string\|null | `성공` | `status` 값의 사람이 읽는 라벨 (`schedule.result.*` 현지화) |
| exit_code | integer\|null | `0` | 실행 종료 코드 (0=성공, 그 외=실패) |
| memory_usage_formatted | string\|null | `417.34 KB` | 실행 중 메모리 사용량의 표시용 포맷 문자열 |
| output | string\|null | `Done.` | 실행 표준 출력 (없으면 null) |
| error_output | string\|null | `null` | 실행 오류 출력 (실패 회차에서 주로 채워짐, 없으면 null) |
| trigger_type | string | `manual` | 실행 방식: scheduled(예약 자동 실행), manual(수동 즉시 실행) |
| trigger_type_label | string\|null | `수동 실행` | `trigger_type` 값의 사람이 읽는 라벨 (`schedule.trigger_type.*` 현지화) |
| triggered_by | object\|null | `{"uuid":"a234c2b1-cde8-437f-b28b-23323be2b98d","name":"관리자"}` | 수동 실행을 트리거한 사용자 정보 (uuid/name — triggeredBy 관계가 로드되지 않았거나 자동 실행이면 null) |

`data.pagination` 은 `current_page`/`last_page`/`per_page`/`total`/`from`/`to`/`has_more_pages` 로 구성됩니다.

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "실행 이력을 조회했습니다.",
    "data": {
        "data": [
            {
                "id": 7,
                "schedule_id": 1,
                "started_at": "2026-07-08T12:14:49+09:00",
                "ended_at": "2026-07-08T12:14:49+09:00",
                "duration": 0,
                "duration_formatted": null,
                "status": "success",
                "status_label": "성공",
                "exit_code": 0,
                "memory_usage_formatted": "417.34 KB",
                "trigger_type": "manual",
                "trigger_type_label": "수동 실행",
                "triggered_by": null
            }
        ],
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
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 요청 파라미터가 검증 규칙을 위반한 경우 (`error.errors` 에 필드별 메시지) |

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
POST /api/admin/schedules/{schedule}/run HTTP/1.1
Host: api.example.com
Accept: application/json
Authorization: Bearer {YOUR_TOKEN}
```

**응답 필드** (`data` 내부)

_단건 응답: `data` 객체의 필드._

| 필드 | 타입 | 실측 예시값 | 용도/설명 |
| --- | --- | --- | --- |
| id | integer | `6` | 기본 키 (내부 식별자) |
| schedule_id | integer | `1` | schedule 식별자 (연관 리소스 참조) |
| started_at | string | `2026-08-04T21:53:49+09:00` | started 일시 |
| ended_at | string | `2026-08-04T21:53:49+09:00` | ended 일시 |
| duration | integer | `0` | 실행 소요 시간 (초/밀리초) |
| duration_formatted | null | `null` | `duration` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| status | string | `success` | 상태 값 (도메인별 상태 집합 — 사람이 읽는 라벨은 status_label, UI 변형은 status_variant 참조) |
| status_label | string | `성공` | 상태의 사람이 읽는 라벨 (상태 Enum label() 산물) |
| exit_code | integer | `0` | 실행 종료 코드 (0=성공, 그 외=실패) |
| memory_usage | integer | `427312` | 실행 중 최대 메모리 사용량 (바이트) |
| memory_usage_formatted | string | `417.3 KB` | `memory_usage` 값의 표시용 포맷 문자열 (통화/용량/일시 등 로케일·단위 포맷) |
| output | string | `    INFO  Application cache cleared s…` | 실행 표준 출력 내용 |
| error_output | null | `null` | 실행 표준 에러 출력 내용 (없으면 빈 문자열/null) |
| trigger_type | string | `manual` | 동작을 유발한 방식/주체 구분 값 |
| trigger_type_label | string | `수동 실행` | `trigger_type` 값의 사람이 읽는 라벨 (현지화/Enum 파생) |
| created_at | string | `2026-08-04 21:53:49` | 생성 일시 |
| updated_at | string | `2026-08-04 21:53:49` | 최종 수정 일시 |

**응답 예시**

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "스케줄이 실행되었습니다.",
    "data": {
        "id": 6,
        "schedule_id": 1,
        "started_at": "2026-08-04T21:53:49+09:00",
        "ended_at": "2026-08-04T21:53:49+09:00",
        "duration": 0,
        "duration_formatted": null,
        "status": "success",
        "status_label": "성공",
        "exit_code": 0,
        "memory_usage": 427312,
        "memory_usage_formatted": "417.3 KB",
        "output": "\n   INFO  Application cache cleared successfully.  \n\r\n",
        "error_output": null,
        "trigger_type": "manual",
        "trigger_type_label": "수동 실행",
        "created_at": "2026-08-04 21:53:49",
        "updated_at": "2026-08-04 21:53:49"
    }
}
```

**에러 응답**

| 상태코드 | 의미 | 발생 조건 |
| --- | --- | --- |
| 401 | Unauthenticated | 유효한 Bearer 토큰이 없거나 만료된 경우 |
| 403 | Forbidden | 요구 권한(`core.schedules.run`)이 없는 경우 |
| 404 | Not Found | path 파라미터에 해당하는 리소스가 없는 경우 |
| 422 | Unprocessable Entity | 실행을 시작하지 않은 경우 — 소유 확장이 비활성(`schedule.owner_inactive`). 최상위 `message` 는 `schedule.run_failed`, 사유는 `errors.general[0]` |
| 409 | Conflict | 「중복 실행 방지」를 켠 스케줄이 이미 실행 중인 경우 (`schedule.already_running`). 실행 이력을 만들지 않는다 (7.1.5) |
| 503 | Service Unavailable | 「중복 실행 방지」 스케줄의 실행 잠금 저장소(캐시)를 쓸 수 없는 경우 (`schedule.run_lock_unavailable`). 실행 이력을 만들지 않는다 (7.1.5) |

<!-- @generated:end -->

**설명** 스케줄을 예약 시각과 무관하게 즉시 실행합니다. `core.schedules.run` 권한이 필요합니다. 7.2.0 부터 즉시 실행은 예약 실행과 같은 경로(실행 방식·기록 방식·중복 방지 규칙)로 돕니다. 응답은 스케줄 설정에 따라 넷 중 하나입니다.

| 상황 | 상태코드 | `message` | `data` |
| --- | --- | --- | --- |
| 바로 실행(`dispatch_mode=sync`) 또는 `type=job` — 이력이 남은 경우 | 200 | 성공 `schedule.run_success` / 실패 `schedule.run_failed` | 실행 이력(위 표, `trigger_type=manual`). **실패도 200** 이며 `status=failed`·`error_output` 으로 결과를 보여 준다 |
| 바로 실행 + 「실패만 기록」(`history_mode=failures_only`) 이 성공한 경우 | 200 | `schedule.run_success` | `{"queued": false, "status": "success", "recorded": false}` — 이력을 남기지 않았다 |
| 큐로 보내기(`dispatch_mode=queue`, `type≠job`) | 202 | `schedule.run_queued` | `{"queued": true, "status": null}` — 접수만 했고 결과는 워커가 처리한 뒤 실행 이력에 남는다 |
| 소유 확장 비활성 / 이미 실행 중 | 422 | `schedule.run_failed` | 없음 — `errors.general` 에 사유 |

`type=job` 작업은 큐 모드여도 Job 클래스 자체를 큐에 넣고 그 투입 결과를 이력으로 돌려주므로 200 입니다(이 경우 큐 작업의 `schedule_id` 는 비어 있는 것이 정상).

```http
HTTP/1.1 200
```

```json
{
    "success": true,
    "message": "스케줄이 실행되었습니다.",
    "data": {
        "queued": false,
        "status": "success",
        "recorded": false
    }
}
```

```http
HTTP/1.1 202
```

```json
{
    "success": true,
    "message": "스케줄을 큐에 넣었습니다. 워커가 처리하면 실행 이력에 기록됩니다.",
    "data": {
        "queued": true,
        "status": null
    }
}
```

```http
HTTP/1.1 422
```

```json
{
    "success": false,
    "message": "스케줄 실행에 실패했습니다.",
    "errors": {
        "general": [
            "이 스케줄은 이미 실행 중입니다. 앞선 실행이 끝난 뒤 다시 시도하세요."
        ]
    }
}
```

관리자가 대상 작업을 수동으로 즉시 돌려 결과를 확인하는 "지금 실행" 동작에 사용합니다.


