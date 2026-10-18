# 스케줄 관리 (DB 스케줄 등록부)

> 모든 예약 작업을 `schedules` 표의 행으로 등록하고, 하나의 실행기로 실행하는 구조 (7.2.0~). 코어 카탈로그·확장 선언·운영자 직접 등록이 같은 표·같은 실행 경로를 쓴다.

## TL;DR (5초 요약)

```text
1. 예약 작업은 전부 schedules 행이다 — 코어는 config/core.php 의 schedules 카탈로그, 확장은 getSchedules() 선언이 행으로 동기화되고, 운영자는 관리자 > 스케줄 관리에서 직접 등록한다
2. routes/console.php 는 등록기(ScheduleRegistrar) 한 줄만 부른다 — 활성 행마다 schedule:{id} 이벤트를 만들고, 실행은 ScheduleDispatcher → ScheduleExecutor 가 맡는다
3. 실행 방식은 행마다 고른다: 바로 실행(sync, 스케줄러 프로세스 안에서 실행 — 기본) / 큐로 보내기(queue, RunScheduledTaskJob)
4. 운영자가 화면에서 바꾼 주기·실행 방식·명칭은 user_overrides 로 보존된다 — 코어 업데이트·확장 업데이트가 덮지 않는다
5. 중복 방지(without_overlapping)는 잠금 유지 분(0=끔), 실행 조건(enabled_condition)은 설정값을 읽는 AND 조건, 실패 알림은 schedule_failed(같은 작업 하루 1회)
6. 스케줄러가 살아 있는지는 하트비트로 본다 — 마지막 틱이 3분 이내면 「정상 동작」
7. 7.2.0 부터 SEO 사이트맵 생성 주기·환율 갱신 시각·GeoIP 갱신 주기는 각 설정 화면이 아니라 스케줄 관리에서 바꾼다
```

---

## 목차

1. [구조](#1-구조)
2. [스케줄 행](#2-스케줄-행)
3. [소유자와 등록 경로](#3-소유자와-등록-경로)
4. [실행 경로](#4-실행-경로)
5. [중복 방지·다중 서버·점검 모드](#5-중복-방지다중-서버점검-모드)
6. [실행 조건 (enabled_condition)](#6-실행-조건-enabled_condition)
7. [실행 이력과 수동 실행](#7-실행-이력과-수동-실행)
8. [실패 알림](#8-실패-알림)
9. [스케줄러 상태와 외부 러너](#9-스케줄러-상태와-외부-러너)
10. [보안 (실행 허용목록)](#10-보안-실행-허용목록)
11. [훅](#11-훅)
12. [7.2.0 으로 옮겨진 설정과 업그레이드](#12-720-으로-옮겨진-설정과-업그레이드)
13. [코어 스케줄 카탈로그 목록](#13-코어-스케줄-카탈로그-목록)
14. [관련 코드·문서](#14-관련-코드문서)

---

## 1. 구조

```text
[선언]                                   [DB]                    [실행]
config/core.php 'schedules' ─ CoreScheduleSeeder ──┐
module.php / plugin.php getSchedules() ─ Manager ──┼─▶ schedules 행 ─▶ ScheduleRegistrar (routes/console.php)
관리자 > 스케줄 관리 「직접 등록」 ─────────────────┘                     │ 활성 행마다 schedule:{id} 이벤트
                                                                         ▼
                                                    ScheduleDispatcher::dispatch(행, 계기, 실행자)
                                                      ├─ 바로 실행 → ScheduleExecutor::execute()
                                                      └─ 큐로 보내기 → RunScheduledTaskJob → 워커가 ScheduleExecutor::execute()
                                                                         │
                                                      schedule_histories 이력 · 행 상태 갱신 · 훅 · 실패 알림
```

서버 cron 은 지금까지와 같이 한 줄이면 됩니다.

```bash
* * * * * cd /path/to/g7 && php artisan schedule:run >> /dev/null 2>&1
```

`routes/console.php` 에는 더 이상 개별 예약이 없습니다. 예약을 더하려면 코어는 카탈로그에, 확장은 `getSchedules()` 에 선언합니다([module-schedules.md](../extension/module-schedules.md)).

---

## 2. 스케줄 행

`schedules` 표의 주요 컬럼입니다.

| 컬럼 | 형식 | 의미 |
| --- | --- | --- |
| `name` | string | 기계 식별자. 소유 행은 (`extension_type`, `extension_identifier`, `name`)이 고유 |
| `label` · `description` | JSON `{로케일: 문자열}` | 화면 표시 명칭·설명 (다국어) |
| `type` | `artisan` · `shell` · `url` · `job` | 실행 유형 |
| `command` | string | artisan 명령(옵션 포함) · 실행 파일과 인자 · URL · Job 클래스 FQCN |
| `command_class` | string? | 확장 artisan 명령 클래스 — 웹 요청 안에서 실행할 때 명령을 먼저 등록하는 데 쓴다. 운영자 입력 불가 |
| `expression` | cron 식 | 실행 시각. 30초 주기면 `* * * * *` |
| `frequency` | `custom` · `everyThirtySeconds` 외 | 30초 주기 여부 판별용. 나머지 값(`hourly`·`daily` 등)은 화면 표시용이고 시각은 `expression` 이 정한다 |
| `timezone` | string? | cron 해석 시간대. 비면 사이트 설정 시간대(`app.schedule_timezone`) |
| `without_overlapping` | 정수(분) | 중복 실행 방지 잠금 유지 시간. `0` = 끔, 최대 10080(7일) |
| `on_one_server` | bool | 여러 서버가 스케줄러를 돌릴 때 한 서버에서만 실행 |
| `dispatch_mode` | `sync` · `queue` | 바로 실행 / 큐로 보내기 |
| `queue_connection` · `queue_name` | string? | 큐로 보낼 연결·큐 (비면 긴 작업 배치 또는 기본값) |
| `history_mode` | `all` · `failures_only` | 모든 실행 기록 / 실패만 기록 |
| `notify_on_failure` | bool | 예약 실행이 실패하면 관리자 알림 |
| `enabled_condition` | JSON 목록? | 실행 조건(AND). 운영자 입력 불가 |
| `run_in_maintenance` | bool | 점검 모드에서도 실행 |
| `timeout` | 초? | 실행 제한 시간 |
| `is_active` | bool | 활성 여부 |
| `extension_type` · `extension_identifier` | `core`·`module`·`plugin` / 식별자 | 소유자. 둘 다 NULL 이면 운영자 직접 등록 |
| `user_overrides` | JSON | 운영자가 바꾼 필드 목록 ([user-overrides.md](user-overrides.md)) |
| `last_result` · `last_run_at` · `next_run_at` | — | 실행 상태. 실행기가 모델 이벤트 없이 갱신한다 |

`next_run_at` 은 행의 시간대(없으면 사이트 시간대)로 cron 식을 해석해 계산하고 UTC 로 저장합니다. 화면의 「다음 실행」은 활성 여부·실행 조건과 무관한 "일정상 다음 시각" 입니다(`ScheduleLookupService::nextRunAt()`). 응답(`ScheduleResource`)은 활성 행의 저장값이 비었거나 이미 지난 시각이면 cron 식으로 다시 계산해 내보냅니다 — 스케줄러가 한동안 돌지 않아 저장값이 갱신되지 않았어도 화면에 지난 시각이 「다음 실행」으로 보이지 않습니다. 저장값 자체는 실행기가 다음 실행 때 갱신합니다.

---

## 3. 소유자와 등록 경로

| 소유자 | 출처 | 동기화 시점 | 행 삭제 |
| --- | --- | --- | --- |
| 코어 (`core`/`core`) | `config/core.php` `schedules` 카탈로그 | 설치(`DatabaseSeeder` → `CoreScheduleSeeder`), 코어 업데이트(`CoreUpdateService::reloadCoreConfigAndResync()`) | 카탈로그에서 빠지면 다음 동기화 때 이력과 함께 삭제 |
| 모듈·플러그인 | `getSchedules()` 선언 | 확장 설치·업데이트 때 선언 동기화 — [module-schedules.md](../extension/module-schedules.md) | 선언에서 빠지면 삭제, 「데이터도 함께 삭제」 제거 시 전부 삭제 |
| 직접 등록 (NULL) | 관리자 > 스케줄 관리 「스케줄 추가」 | — | 운영자가 삭제. 동기화·업그레이드는 손대지 않는다 |

**소유 행(코어·확장)에서 바꿀 수 없는 필드** — `command` · `type` · `name` · `enabled_condition` · `timezone`. 저장값과 같으면 통과하고, 다르면 422 입니다. 소유 행은 삭제할 수 없고(`can_delete=false`), 끄려면 활성 토글을 씁니다. 소유 행을 복제하면 직접 등록 행이 됩니다.

**운영자가 바꿀 수 있고 보존되는 필드** — `expression` · `frequency` · `timeout` · `is_active` · `without_overlapping` · `on_one_server` · `dispatch_mode` · `queue_connection` · `queue_name` · `history_mode` · `notify_on_failure` · `run_in_maintenance`, 그리고 다국어 `label` · `description`(로케일 단위). 이 값을 화면에서 바꾸면 `user_overrides` 에 기록되어 이후 재동기화가 덮지 않습니다.

**직접 등록 규칙** — 유형은 `artisan` · `shell` · `url` · `job` 중 하나이고 표시 명칭(`label`)이 필수입니다. `enabled_condition` · `command_class` · `extension_type` · `extension_identifier` 는 보낼 수 없습니다(`prohibited`). 명령은 저장 시점과 실행 직전에 모두 [허용목록](#10-보안-실행-허용목록)으로 검사합니다.

---

## 4. 실행 경로

### 4.1 등록 (ScheduleRegistrar)

`routes/console.php` 는 `.env` 가 있을 때 `app(ScheduleRegistrar::class)->register(Schedule::getFacadeRoot())` 만 부릅니다.

- 매분 생존 신호 이벤트 `schedule:heartbeat` 를 등록합니다([9절](#9-스케줄러-상태와-외부-러너)).
- 활성 행(`is_active`)을 한 번에 읽고, 소유 확장이 비활성인 행은 뺍니다.
- 순서: 30초 주기 → 바로 실행 → 큐로 보내기 (가벼운 작업이 먼저 돈다).
- 행마다 `Schedule::call(...)->name("schedule:{id}")` 로 등록하고, 30초 주기는 `everyThirtySeconds()`, 그 밖은 `cron(expression)` 을 씁니다. `timezone` · `run_in_maintenance` · `on_one_server` · `enabled_condition` 을 이벤트 옵션으로 옮깁니다.
- 이벤트 이름이 행마다 달라 같은 분에 도는 작업끼리 `onOneServer` 잠금을 공유하지 않습니다.
- 설치 전(표 없음)이나 업그레이드 도중(7.2.0 컬럼 없음)에는 등록하지 않고 로그만 남깁니다. 한 행의 잘못된 값(깨진 cron 식 등)은 그 행만 건너뜁니다.

### 4.2 바로 실행 / 큐로 보내기 (ScheduleDispatcher)

`ScheduleDispatcher::dispatch(Schedule|int $schedule, ScheduleTriggerType $trigger, ?int $userId = null): ?ScheduleHistory` 가 예약 틱·수동 실행 API·외부 러너의 공통 진입점입니다.

| 행 | 동작 | 반환 |
| --- | --- | --- |
| 바로 실행 (`sync`, 기본) | 스케줄러 프로세스 안에서 실행기를 직접 호출. 큐·워커가 없어도 동작 | 실행 이력. 실패해도 예외 없이 `status=failed` 이력. 「실패만 기록」 성공이면 `null` |
| 큐로 보내기 (`queue`) — artisan · shell · url | `RunScheduledTaskJob` 을 큐에 넣고 워커가 실행기를 호출 | `null` (수동 실행 API 는 202) |
| 큐로 보내기 — **job 유형** | 실행 잡으로 감싸지 않고 **Job 클래스 자체**를 큐에 넣음 | 「Job queued」 성공 이력 |

바로 실행은 `dispatchSync` 를 쓰지 않습니다 — 30초 작업까지 큐 처리 기록이 쌓이지 않게 하려는 것입니다. 오래 걸리는 작업은 큐로 보내 워커가 처리하게 합니다.

**job 유형을 큐로 보낼 때** — Job 이 가진 큐·timeout·재시도·`backoff`·중복 방지(unique)·`failed()` 를 그대로 살리기 위해 Job 을 그대로 큐에 넣습니다(예: 사이트맵 생성 Job 은 전용 `sitemap` 큐, 1800초, 3회 재시도). 그래서

- 스케줄 실행 이력에는 "큐에 넣는 데 성공했다"만 남습니다. 실제 결과는 스케줄 관리의 「큐」 탭 처리 기록에서 봅니다([queue-management.md](queue-management.md)).
- Job 이 나중에 실패해도 `schedule_failed` 알림은 나가지 않습니다 — Job 자신의 실패 처리가 적용됩니다.
- 잠금(`without_overlapping`)은 큐에 넣는 순간만 지킵니다. 실행 중복은 Job 의 unique 설정이 막습니다.

job 유형을 바로 실행으로 두면 `Bus::dispatchSync()` 로 그 자리에서 실행하고, 실패하면 일반 실패와 같게 처리합니다.

### 4.3 RunScheduledTaskJob (큐로 보내기)

| 항목 | 값 |
| --- | --- |
| payload | 스케줄 ID·실행 계기·실행자만 (모델을 싣지 않는다) |
| `tries` | 1 — 같은 실행이 이력을 두 번 남기지 않게. 재시도는 큐 탭에서 운영자가 명시적으로 |
| `timeout` | 행 `timeout`(없으면 600초)을 큐 연결의 `retry_after` 아래로 줄인 값 |
| 연결·큐 | 행의 `queue_connection`/`queue_name` 을 「큐 워커」 등록 목록으로 해석. 미등록이면 기본값 + 경고, 둘 다 비면 긴 작업 배치(`*-long`)가 있으면 그쪽 |
| 표시 이름 | `schedule:{id} {name}` (큐 탭·처리 기록) |

워커가 꺼낼 때 상태를 다시 봅니다 — 행이 지워졌거나 소유 확장이 비활성이면 건너뛰고, 예약 실행은 행이 비활성이거나 실행 조건이 맞지 않으면 건너뜁니다. 워커가 timeout·강제 종료로 죽으면 `failed()` 가 실행 중 이력을 실패로 마감하고, 잠금을 풀고, `core.schedule.run_failed` 를 발화합니다.

### 4.4 실행기 (ScheduleExecutor)

| 유형 | 실행 방법 |
| --- | --- |
| `artisan` | 같은 프로세스에서 `Artisan::call(명령, 인자배열)`. 웹 요청 안이면 확장이 선언한 `command_class` 를 먼저 등록 |
| `shell` | `Process` Facade, 인자 배열로 실행(셸 해석 없음). 기본 timeout 60초 |
| `url` | `Http` Facade GET. 기본 timeout 30초. 내부망 주소 차단 |
| `job` | 허용 Job 클래스만. 바로 실행 = `dispatchSync`, 큐로 보내기 = `dispatch` |

종료 코드가 0이 아니거나 예외가 나면 실패입니다. 실행 전 거부만 예외(`ScheduleExecutionException`)로 올라옵니다.

| 거부 사유 (`reasonCode`) | 상황 | 수동 실행 응답 |
| --- | --- | --- |
| `owner_inactive` | 소유 확장이 비활성 | 422 |
| `already_running` | 수동 실행인데 앞선 실행이 잠금을 잡고 있음 (예약 실행은 조용히 건너뜀) | 422 |

---

## 5. 중복 방지·다중 서버·점검 모드

- **중복 방지** — `without_overlapping > 0` 이면 실행기가 캐시 잠금 `schedule:run:{id}` 를 그 분만큼 잡습니다. 틱·수동·큐 모든 경로가 이 잠금 하나를 씁니다(Laravel `withoutOverlapping` 은 쓰지 않는다 — 큐 모드는 투입 직후 잠금이 풀리기 때문). 앞 실행이 유지 시간 안에 끝나지 않으면 잠금이 풀립니다. 캐시가 잠금을 주지 못하면 잠금 없이 실행합니다.
- **한 서버에서만 실행** — `on_one_server` 는 Laravel `onOneServer()` 입니다. 서버들이 같은 캐시(redis 등)를 써야 의미가 있습니다.
- **점검 모드** — `run_in_maintenance` 가 켜진 행만 점검 모드에서 돕니다.
- **30초 주기** — `frequency = everyThirtySeconds` 행은 한 분에 두 번 돕니다. 대시보드 리소스·현재 접속자 브로드캐스트가 이 주기이고, 둘 다 「실패만 기록」으로 이력을 쌓지 않습니다.

---

## 6. 실행 조건 (enabled_condition)

설정이 꺼져 있으면 그 틱을 건너뛰게 하는 조건입니다. 코어 카탈로그와 확장 선언만 정할 수 있습니다(운영자 입력 불가).

```php
'enabled_condition' => [
    ['source' => 'core_setting', 'key' => 'geoip.feature_enabled', 'operator' => 'truthy', 'default' => false],
    ['source' => 'core_setting', 'key' => 'geoip.license_key', 'operator' => 'not_empty', 'default' => ''],
],
```

| 키 | 값 |
| --- | --- |
| `source` | `core_setting`(`g7_core_settings()`) · `module_setting`(`module_setting()`) · `plugin_setting`(`plugin_setting()`) · `config`(`config()`) |
| `key` | 설정 키 (dot 표기) |
| `operator` | `truthy` · `not_empty`(null·빈 문자열·빈 배열이 아님) · `equals`(`value` 필요. 불리언 기대값은 `"1"`·`true` 같은 표기 차이를 흡수) |
| `value` | `equals` 의 기대값 |
| `default` | 설정이 없을 때 쓸 값 |
| `identifier` | 선택 — `module_setting`/`plugin_setting` 의 확장 식별자. 생략하면 행의 소유 확장 |

- 여러 조건은 **AND** 입니다.
- 형식이 잘못된 조건은 "미충족"(실행 안 함)으로 보고 경고 로그를 남깁니다.
- 조건은 틱마다(그리고 큐 워커가 꺼낼 때 한 번 더) 평가합니다. 설정을 끄면 다음 틱부터 건너뜁니다.
- **수동 실행은 조건을 보지 않습니다.** 화면은 미충족 조건이 있는 행에 「설정에서 꺼짐」 배지를 달고, 즉시 실행 창에서 "예약 실행은 건너뛰는 작업" 이라고 경고합니다.

---

## 7. 실행 이력과 수동 실행

| `history_mode` | 성공 | 실패 |
| --- | --- | --- |
| `all` (모든 실행 기록, 기본) | 실행 시작 때 `running` 이력을 먼저 남기고 끝나면 갱신 | 같음 |
| `failures_only` (실패만 기록) | 이력을 남기지 않음 | 실패 이력 1건 |

- 이력 출력은 최대 65,535자로 자릅니다. 이력은 매일 04:35 `schedules:prune-history`(기본 90일 보존)가 정리합니다.
- **수동 실행** `POST /api/admin/schedules/{id}/run` (`core.schedules.run`) — 바로 실행이면 200 과 이력(실패도 200 + 실패 이력), 큐로 보내기면 202, 「실패만 기록」 성공이면 200 + `recorded=false`.
- 수동 실행은 비활성 행도, 실행 조건이 꺼진 행도 실행합니다. 소유 확장이 비활성이면 422 입니다.
- 활동 로그에는 수동 실행만 남습니다(30초 작업의 로그 폭증 방지).

---

## 8. 실패 알림

코어 알림 정의 `schedule_failed`(관리자 역할, 메일 + 사이트 알림)가 `core.schedule.run_failed` 훅을 구독합니다.

- **예약 실행** 실패만 알립니다. 화면에서 「즉시 실행」한 실패는 결과를 이미 보므로 보내지 않습니다.
- 행의 「실패 알림」(`notify_on_failure`)이 꺼져 있으면 보내지 않습니다.
- 같은 작업은 사이트 시간대 기준 **하루 1회**만 보냅니다(캐시 키 `schedule:failure_notified:{id}:{Ymd}`). 캐시 장애면 보냅니다.
- 환경설정 > 알림 설정에서 「예약 작업 실패」 정의를 끄거나 채널·문구를 바꿀 수 있습니다.
- 큐로 보낸 job 유형의 실패는 이 알림 대상이 아닙니다([4.2](#42-바로-실행--큐로-보내기-scheduledispatcher)).
- 템플릿 변수: `schedule_label` · `schedule_name` · `owner` · `error` · `failed_at` · `schedule_url` · `app_name` · `site_url`.

자체 실패 알림이 있는 방문자 통계 집계(`analytics-archive-hour`·`analytics-archive-day`)는 카탈로그에서 `notify_on_failure=false` 입니다.

---

## 9. 스케줄러 상태와 외부 러너

**하트비트** — 등록기가 매분 `schedule:heartbeat` 이벤트로 `ScheduleHeartbeat::beat()` 를 부릅니다. 캐시에 마지막 틱 시각만 남기고 DB 행·이력은 없습니다. 스케줄 관리 상단 상태 카드(`GET /api/admin/schedules/status`)는 마지막 틱이 **3분 이내**면 「정상 동작」, 아니면 「멈춤」과 서버 cron 등록 안내를 보여 줍니다. 다중 서버는 공유 캐시를 전제합니다.

```php
$lastTick = app(\App\Services\Schedule\ScheduleHeartbeat::class)->lastTick(); // ?CarbonInterface
```

**외부 러너** — 서버 cron 대신 웹 크론 같은 다른 경로로 스케줄을 돌릴 때도 같은 실행기를 거칩니다.

| 진입점 | 용도 |
| --- | --- |
| `ScheduleDispatcher::dispatch($schedule, $trigger, $userId)` | 행 하나를 실행하는 단일 진입점 |
| `DatabaseScheduleRunner::runDue(CarbonInterface $now): list<string>` | 그 시각에 due 인 `schedule:` 이벤트만 실행하고 실행한 이벤트 이름을 돌려준다. 조건·점검 모드·한 서버 실행 규칙은 `schedule:run` 과 같다. 30초 주기 행은 호출 1회에 1번 |
| `ScheduleRegistrar::isManaged(Event $event): bool` | 이벤트 이름이 `schedule:` 로 시작하면 true — 이 이벤트의 이력은 실행기가 남기므로 러너는 따로 기록하지 않는다 |

Laravel 스케줄러 이벤트 전체를 돌리는 러너라면 `runDue()` 를 함께 부르지 않습니다(이중 실행).

---

## 10. 보안 (실행 허용목록)

기본 정책은 `config/schedule_security.php`(코드 소유)이고, 서버 관리자는 [덮어쓰기 파일](#101-보안-정책-덮어쓰기-storageappschedule_securityjson)로 **더하기만** 할 수 있습니다. 관리자 화면에서는 바꿀 수 없고, 유효 정책을 읽기 전용으로 보여 줍니다(`GET /api/admin/schedules/security`). 저장 시점(FormRequest)과 실행 직전(실행기) 모두 같은 해석기(`App\Services\Schedule\ScheduleSecurityPolicy`)로 검사합니다.

| 유형 | 규칙 |
| --- | --- |
| `artisan` | 거부 목록·거부 접두사(`make:`)가 최우선. 허용목록(`artisan.allowlist`)에 있는 명령만, 선언된 옵션·인자 수만 허용. 확장 네임스페이스(`Modules\`·`Plugins\`) 명령은 `allow_extension_commands` 가 켜져 있으면 허용 |
| `shell` | 기본 차단. `SCHEDULE_SHELL_ENABLED=true` + 허용 실행 파일(`SCHEDULE_SHELL_ALLOWED_BINARIES`)일 때만. 인터프리터는 절대경로 스크립트 파일만 |
| `url` | 내부망 주소 차단(`security.allow_internal_outbound_urls` 로 허용 가능), http·https 만 |
| `job` | `job.allowed_classes` 허용 클래스 + 필터 `core.schedule.filter_allowed_job_classes` 로 확장이 더한 클래스만 |

코어 카탈로그의 artisan 명령은 반드시 **기본 정책**(config) 허용목록에 있어야 동기화됩니다 — 확장 선언 검사(ScheduleManifestValidator)도 덮어쓰기 파일을 보지 않습니다(사이트마다 다른 파일에 기대는 확장이 생기지 않게).

등록·수정 창의 명령 칸은 여러 줄로 입력할 수 있지만, 저장 전에 줄바꿈·연속 공백·앞뒤 공백을 공백 하나로 접어 한 줄로 저장합니다.

### 10.1 보안 정책 덮어쓰기 (`storage/app/schedule_security.json`)

서버 관리자(파일 시스템 접근 권한자)가 사이트에 맞게 정책을 넓히거나 좁히는 선택 파일입니다. 코어 업데이트가 덮어쓰지 않는 위치이며, 관리자 화면이 쓰는 환경설정 저장소(`storage/app/settings/`)와 분리돼 있어 스케줄 권한만 가진 관리자는 바꿀 수 없습니다.

- **더하기만** — Artisan 허용목록(새 명령 추가, 또는 기본 항목에 옵션 추가 — 옵션은 합집합, 위치 인자 상한은 큰 값)·거부 목록·거부 접두사·Job 허용 클래스를 더합니다. 기본 정책의 거부 항목은 풀 수 없습니다.
- **거부가 이깁니다** — 덮어쓰기 허용목록에 넣은 명령이 거부 목록(기본·덮어쓰기)이나 거부 접두사에 걸리면 거부합니다.
- **Shell 은 `.env` 만** — `SCHEDULE_SHELL_ENABLED`·`SCHEDULE_SHELL_ALLOWED_BINARIES` 로만 정합니다. 파일에 `shell` 키가 있으면 형식 오류입니다.
- **형식 오류면 파일 전체를 무시** — 하나라도 어긋나면(JSON 문법 오류, 모르는 키, 잘못된 값 형태, 잠긴 키) 덮어쓰기 전체를 적용하지 않고 기본 정책만 씁니다. 경고 로그(`Schedule security override ignored`)를 남기고, 보안 정책 API 의 `override_file.valid=false`·`error` 로 알립니다.
- 파일을 고치면 다음 판정부터 반영됩니다(수정 시각·크기가 바뀌면 다시 읽음 — 큐 워커 재시작 불필요). `config:cache` 에 굳지 않습니다.
- `_` 로 시작하는 키는 주석으로 보고 건너뜁니다.

| 키 | 형식 | 의미 |
| --- | --- | --- |
| `artisan.allowlist` | 객체 `{ "명령": { "options": ["옵션"], "max_arguments": 0 } }` | 허용 명령 추가. `options` 는 `--` 없는 롱옵션 이름 배열(생략 시 없음), `max_arguments` 는 0~10 정수(생략 시 0) |
| `artisan.denylist` | 문자열 배열 | 거부 명령 추가(대소문자 무관) |
| `artisan.denylist_prefixes` | 문자열 배열 | 거부 접두사 추가(예: `"seo:"`) |
| `job.allowed_classes` | FQCN 문자열 배열 | Job 허용 클래스 추가(선행 `\` 는 떼고 비교) |

덮어쓸 수 없는 키: `shell`·`url`(최상위), `artisan.allow_extension_commands`·`artisan.extension_namespaces`.

```json
{
    "_comment": "사이트 운영자 메모 — 2026-10 백업 점검 명령 허용",
    "artisan": {
        "allowlist": {
            "backup:run": { "options": ["only-db"], "max_arguments": 0 },
            "seo:prune-stats": { "options": ["chunk"] }
        },
        "denylist": ["inspire"],
        "denylist_prefixes": ["analytics:report"]
    },
    "job": {
        "allowed_classes": ["App\\Jobs\\GenerateSitemapJob"]
    }
}
```

덮어쓰기로 더한 옵션이 실제 명령 시그니처에 있는지는 검사하지 않습니다 — 없는 옵션을 더하면 실행 시점에 명령이 오류로 끝납니다. 파일 위치는 `config/schedule_security.php` 의 `override_file` 이 정합니다(기본 `storage/app/schedule_security.json`).

---

## 11. 훅

| 훅 | 종류 | 인자 | 설명 |
| --- | --- | --- | --- |
| `core.schedule.before_run` | action | `Schedule` | 실행 직전 |
| `core.schedule.after_run` | action | `Schedule`, `ScheduleHistory` | 실행 직후. 「실패만 기록」 성공이면 저장되지 않은 이력 인스턴스(`exists=false`) |
| `core.schedule.run_failed` | action | `Schedule`, `?ScheduleHistory`, `Throwable\|string $error` | 실패 시 (수동·예약·큐 워커 강제 종료 모두). 실행기는 오류를 **문자열**로 넘긴다 — 큐로 가는 리스너가 Throwable 을 직렬화하지 못한다 |
| `core.schedule.filter_allowed_job_classes` | filter | `array $classes` | job 유형 허용 클래스. 기본값 `config('schedule_security.job.allowed_classes')` |
| `core.schedules.config` | filter | `array $catalog` | 코어 카탈로그를 동기화 직전에 가공 — 언어팩이 label·description 번역을 더한다 |

확장이 자기 Job 을 스케줄로 돌리려면 필터로 등재합니다.

```php
HookManager::addFilter('core.schedule.filter_allowed_job_classes', function (array $classes) {
    $classes[] = \Modules\Vendor\Sample\Jobs\RebuildIndexJob::class;

    return $classes;
});
```

생성·수정·삭제 훅(`core.schedule.before_create` 등)은 [hook-catalog.md](../extension/hook-catalog.md) 를 봅니다.

---

## 12. 7.2.0 으로 옮겨진 설정과 업그레이드

| 이전 위치 | 7.2.0 | 업그레이드 시 |
| --- | --- | --- |
| 환경설정 > SEO 「사이트맵 생성 주기·시각」(`seo.sitemap_schedule`·`seo.sitemap_schedule_time`) | 코어 행 `sitemap-generate` (기본 매일 02:00) | 저장값이 기본과 다르면 행의 cron 으로 옮기고 운영자 수정으로 기록, 설정 키 삭제 (hourly → `0 * * * *`, daily → `분 시 * * *`, weekly → `분 시 * * 0`) |
| 환경변수 `GEOIP_SCHEDULE_FREQUENCY`·`GEOIP_SCHEDULE_TIME` (`config/geoip.php` `schedule`) | 코어 행 `geoip-update` (기본 수요일 03:00) | 환경변수가 있고 기본과 다르면 행의 cron 으로 옮김. `.env` 는 고치지 않는다(남은 줄은 읽히지 않으므로 지워도 된다) |
| 환율 플러그인 설정 「갱신 시각」(`update_time`) | 플러그인 행 `update-rates` (매일 11:30, Asia/Seoul) | 플러그인 업그레이드가 저장값을 행으로 옮기고 설정 키 삭제 |
| `routes/console.php` 의 코어·확장 예약 | 코어 카탈로그 · 확장 선언 → DB 행 | 코어 선언 재동기화 → 활성 확장 선언 재동기화 순으로 행 생성 |
| 중복 방지 `without_overlapping` boolean | 잠금 유지 분 | `true` → 1440분 |

- 이미 스케줄 관리에서 그 행의 cron 을 바꿨다면(운영자 수정 표식) 업그레이드는 그 값을 존중합니다.
- 운영자가 직접 등록한 행은 업그레이드가 손대지 않습니다.
- 업그레이드 단계가 실패해도 업데이트 전체를 되돌리지 않고 복구 명령을 로그로 안내합니다: `php artisan core:execute-upgrade-steps --from=7.2.0 --to=7.2.0 --force --skip-migrations --skip-bundled-updates`.

---

## 13. 코어 스케줄 카탈로그 목록

공통 기본값은 바로 실행 · 모든 실행 기록 · 한 서버에서만 · 실패 알림 켬 · 중복 방지 끔입니다. 다른 값만 비고에 적습니다. 시각은 사이트 설정 시간대 기준입니다.

| name | 명령 | 일정 | 비고 |
| --- | --- | --- | --- |
| `layout-previews-cleanup` | `layout-previews:cleanup` | `*/30 * * * *` | |
| `sanctum-prune` | `sanctum:prune-expired --hours=24` | `0 4 * * *` | |
| `auth-clear-resets` | `auth:clear-resets` | `5 4 * * *` | |
| `queue-prune-records` | `queue:prune-records` | `10 4 * * *` | 처리 기록·실패 작업 정리 |
| `queue-prune-batches` | `queue:prune-batches` | `15 4 * * *` | |
| `notification-cleanup` | `notification:cleanup` | `20 4 * * *` | |
| `ext-bundles-cleanup` | `ext-bundles:cleanup` | `25 4 * * *` | |
| `ext-static-cleanup` | `ext-static:cleanup` | `28 4 * * *` | |
| `seo-prune-stats` | `seo:prune-stats` | `30 4 * * *` | |
| `schedules-prune-history` | `schedules:prune-history` | `35 4 * * *` | |
| `identity-prune-logs` | `identity:prune-logs` | `40 4 * * *` | |
| `activity-log-prune` | `activity-log:prune` | `45 4 * * *` | |
| `notification-log-prune` | `notification-log:prune` | `50 4 * * *` | |
| `notification-delivery-prune` | `notification-delivery:prune` | `52 4 * * *` | |
| `attachments-prune-orphans` | `attachments:prune-orphans --scheduled` | `55 4 * * *` | |
| `storage-prune-leftovers` | `storage:prune-leftovers` | `0 5 * * *` | |
| `privacy-process-retention` | `privacy:process-retention --scheduled` | `5 5 * * *` | |
| `privacy-process-dormant` | `privacy:process-dormant --scheduled` | `10 5 * * *` | |
| `auth-process-password-expiry` | `auth:process-password-expiry --scheduled` | `15 5 * * *` | |
| `identity-expire-challenges` | `identity:expire-challenges` | `0 * * * *` | |
| `analytics-prune` | `analytics:prune` | `10 5 * * *` | |
| `dashboard-broadcast-resources` | `dashboard:broadcast-resources` | 30초 | 잠금 5분 · 모든 서버 · 실패만 기록 |
| `analytics-archive-hour` | `analytics:archive --period=hour --scheduled` | `5 * * * *` | 잠금 55분 · 실패 알림 끔 |
| `analytics-archive-day` | `analytics:archive --period=day --scheduled` | `15 0 * * *` | 잠금 120분 · 실패 알림 끔 |
| `analytics-check-volume` | `analytics:check-volume` | `5 5 * * *` | |
| `analytics-report-weekly` | `analytics:report --period=weekly` | `0 8 * * 1` | |
| `analytics-report-monthly` | `analytics:report --period=monthly` | `0 8 1 * *` | |
| `analytics-update-cloud-ranges` | `analytics:update-cloud-ranges` | `0 0 * * 0` | 잠금 60분 · 조건 `analytics.block_cloud_ip_ranges` |
| `analytics-broadcast-realtime` | `analytics:broadcast-realtime` | 30초 | 잠금 5분 · 모든 서버 · 실패만 기록 · 조건 Reverb 키 있음 |
| `language-pack-check-updates` | `language-pack:check-updates` | `0 0 * * 0` | 잠금 60분 · 모든 서버 |
| `sitemap-generate` | job `App\Jobs\GenerateSitemapJob` | `0 2 * * *` | 잠금 30분 · 큐로 보내기 · 조건 `seo.sitemap_enabled` |
| `geoip-update` | `geoip:update` | `0 3 * * 3` | 잠금 60분 · 조건 `geoip.feature_enabled` · `geoip.auto_update_enabled` · `geoip.license_key` |

카탈로그 형식은 [core-config.md](core-config.md#schedules--코어-스케줄-카탈로그-720) 를 봅니다.

---

## 14. 관련 코드·문서

| 파일 | 역할 |
| --- | --- |
| `app/Services/Schedule/ScheduleRegistrar.php` | DB 행 → 스케줄러 이벤트 등록 |
| `app/Services/Schedule/ScheduleDispatcher.php` | 바로 실행 / 큐로 보내기 진입점 |
| `app/Services/Schedule/ScheduleExecutor.php` | 실행·이력·잠금·훅 |
| `app/Services/Schedule/ScheduleConditionEvaluator.php` | 실행 조건 해석 |
| `app/Services/Schedule/ScheduleHeartbeat.php` | 스케줄러 생존 신호 |
| `app/Services/Schedule/DatabaseScheduleRunner.php` | 외부 러너용 due 실행 |
| `app/Services/Schedule/ScheduleLookupService.php` | 소유 행 조회·다음 실행 시각·소유자 요약 |
| `app/Services/Schedule/ScheduleFailureNotifier.php` | 실패 알림 판정(하루 1회) |
| `app/Jobs/RunScheduledTaskJob.php` | 큐로 보내기 실행 잡 |
| `app/Extension/Helpers/ExtensionScheduleSyncHelper.php` | 카탈로그·선언 → DB 동기화 |
| `app/Extension/Validators/ScheduleManifestValidator.php` | 선언 검증 |
| `database/seeders/CoreScheduleSeeder.php` | 코어 카탈로그 시딩 |
| `config/schedule_security.php` | 실행 허용목록 |

- [module-schedules.md](../extension/module-schedules.md) — 확장의 스케줄 선언
- [core-config.md](core-config.md) — 코어 카탈로그 형식
- [queue-management.md](queue-management.md) — 큐 탭·처리 기록
- [notification-system.md](notification-system.md) — 알림 정의
- [user-overrides.md](user-overrides.md) — 운영자 수정 보존
- [api/schedules.md](api/schedules.md) — 스케줄 API
