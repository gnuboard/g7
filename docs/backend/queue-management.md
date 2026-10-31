# 큐 관리 (Queue Management)

> 관리자 > 스케줄 관리의 「큐」 탭 — 대기 작업·실패 작업·작업 처리 기록을 보고, 실패한 작업을 다시 실행하거나 지우는 기능과 그 뒤의 백엔드 구조 (7.2.0~)
> 예약 작업 자체(주기·실행 방식·실행 이력)는 [예약 작업(스케줄) 시스템](scheduling.md)을 본다.

## TL;DR (5초 요약)

```text
1. 큐 탭은 세 목록(대기·실패·처리 기록)과 워커 상태 요약을 보여 준다 — 대기 작업은 database 드라이버만 셀 수 있다
2. 처리 기록 queue_job_logs = 워커가 처리를 마친 작업 1건당 1행(processed·failed). 재시도 대기(release)는 남기지 않는다
3. 기록은 부가 기능이다 — 실패해도 작업을 막지 않는다. drivers.queue_job_log_enabled 로 끄고, config('queue.job_log.excluded_jobs') 로 작업을 뺀다
4. 처리 기록과 실패 작업은 보관 기간 설정 하나(drivers.queue_record_retention_days, 기본 7일)를 함께 따르고 매일 04:10 queue:prune-records 가 지운다
5. 재시도는 queue:retry 와 같은 절차지만 연결·큐를 「큐 워커」 등록 목록으로 다시 해석한다. 되넣을 곳이 sync·null 이면 거부(422)
6. 일괄 재시도·삭제는 요청당 최대 100건. 권한은 core.queue_jobs.read · retry · delete
7. 실패 작업 상세는 직렬화된 작업 객체(payload data.command)를 절대 내려주지 않는다
```

---

## 1. 화면 구성

| 영역 | 데이터 출처 | 비고 |
| --- | --- | --- |
| 상태 요약 | 기본 큐 연결의 드라이버, 대기·실패 건수, 「큐 워커」 상태 보고서 | 워커 등록 수·살아 있는 수·경고 수·하트비트 만료 기준(분)·긴 작업 워커 상태 |
| 대기 작업 | 큐 연결의 `jobs` 표(연결 설정의 `table`) | `state` = `waiting`(대기)·`delayed`(지연 실행 예정)·`running`(워커가 집음). 재시도·삭제 동작 없음 |
| 실패 작업 | `failed_jobs` 표 | 목록은 예외 첫 줄만(`exception_summary`), 상세에서 예외 전문. `attempts` 는 payload 에 시도 수가 있으면(redis) 그 값, 없으면(database — 시도 수를 `jobs.attempts` 열에만 둔다) 같은 UUID 의 마지막 처리 기록 값, 둘 다 없으면 `null`(화면 「-」) |
| 처리 기록 | `queue_job_logs` 표 | 성공·최종 실패를 작업마다 1행 |

**대기 작업은 database 드라이버만 셀 수 있습니다.** redis·sqs 등은 표를 직접 읽을 수 없어 목록이 비고 `meta.measurable = false` 가 내려가며, 화면은 안내 문구를 띄웁니다. 상태 요약의 `pending_count` 도 이때 `null` 입니다. 실패 작업과 처리 기록은 드라이버와 무관하게 DB 표라 항상 보입니다.

워커 상태 요약은 환경설정 > 드라이버 「큐 워커」 구역과 같은 보고서(`QueueWorkerRegistry::statusReport()`)를 씁니다. 워커 등록·하트비트·폴백 규칙은 [hooks.md 「큐 자동 실행」](../extension/hooks.md#큐-자동-실행)과 [admin-settings-access.md](admin-settings-access.md)를 봅니다. 하트비트는 서버별 기본 캐시에 남으므로 다중 서버에서는 요청을 받은 서버 기준입니다.

---

## 2. 작업 처리 기록 (`queue_job_logs`)

`failed_jobs` 는 실패만 남기고 `jobs` 는 처리가 끝나면 행이 사라지므로 「이 작업이 성공했다」는 사실을 볼 곳이 없었습니다. 처리 기록이 그 빈자리를 채웁니다.

### 컬럼

| 컬럼 | 설명 |
| --- | --- |
| `uuid` | 작업 UUID (payload `uuid` — 실패 작업 `failed_jobs.uuid` 와 같은 값) |
| `connection` · `queue` | 큐 연결 이름 · 큐 이름 |
| `job_class` | 작업 클래스 (payload `data.commandName`, 없으면 `job`) |
| `display_name` | 표시 이름 (payload `displayName`) |
| `status` | 처리 결과 — `processed`(성공) · `failed`(최종 실패) |
| `attempts` | 처리 시도 횟수 |
| `started_at` · `finished_at` · `duration_ms` | 처리 시작·종료 시각(밀리초 정밀도)과 소요 시간 |
| `exception_class` · `exception_message` | 실패 예외 클래스와 메시지(2000자에서 자름) |
| `schedule_id` | 예약 작업 ID — 스케줄 실행 잡(`RunScheduledTaskJob`)일 때만. 스케줄이 지워지면 null |
| `created_at` | 기록 시각 (수정되지 않으므로 `updated_at` 없음) |

### 기록 방식

리스너 `App\Listeners\Queue\RecordQueueJobLog` 를 `EventServiceProvider::boot()` 가 Laravel 큐 이벤트 3개에 등록합니다(싱글턴).

| 이벤트 | 동작 |
| --- | --- |
| `JobProcessing` | 시작 시각을 기억한다 |
| `JobProcessed` | 재시도 대기로 돌아가지도(`isReleased()`) 실패하지도(`hasFailed()`) 않았을 때만 `processed` 로 남긴다 |
| `JobFailed` | 최종 실패를 `failed` 로 남긴다 |

| 연결 | 저장 시점 | 이유 |
| --- | --- | --- |
| sync (연결 이름 또는 드라이버가 `sync`) | 버퍼에 모았다가 요청 종료(`app()->terminating`) 때, 또는 100건이 차면 한 번에 insert | 요청 안에서 작업이 바로 돌아 요청마다 insert 가 생긴다 |
| 워커 연결 | 건별 즉시 insert | 워커는 오래 사는 프로세스라 종료까지 기다리면 기록이 늦고, 죽으면 잃는다 |

- **기록 실패는 작업을 막지 않습니다.** 행 만들기·저장 중 예외는 경고 로그만 남기고 삼킵니다. 업그레이드 도중 표가 아직 없으면 기록하지 않습니다.
- `schedule_id` 는 직렬화 문자열에서 공개 속성 `scheduleId` 의 정수만 읽습니다 — 작업 객체를 역직렬화하지 않습니다. 암호화된 작업(`ShouldBeEncrypted`)은 null 입니다.
- 스케줄 실행 잡의 표시 이름은 `schedule:{id} {name}` 이다. 이 잡의 timeout 은 행의 제한 시간을 그 연결의 `retry_after` 보다 짧게 줄여 쓴다(훅 리스너 큐 옵션과 같은 `QueueWorkerRegistry::clampTimeout()` 규칙 — 여유 초는 `queue.worker_registry.timeout_margin_seconds`). 그래야 워커가 같은 잡을 중복으로 다시 집지 않는다.
- **job 유형 스케줄을 큐 모드로 실행하면 `schedule_id` 는 null 이 정상입니다.** job 유형은 스케줄 실행 잡으로 감싸지 않고 Job 클래스 자체를 큐에 넣으므로(그 Job 의 큐·timeout·재시도 선언을 보존) 기록에는 그 Job 클래스만 남습니다.

### 끄기·제외

```php
// 환경설정 > 드라이버 — drivers.queue_job_log_enabled (기본 true)
// false 면 리스너가 아무것도 남기지 않는다

// config/queue.php — 아주 자주 도는 작업이 기록을 채우는 것을 막을 때
'job_log' => [
    'excluded_jobs' => [
        \Modules\Vendor\Shop\Jobs\SyncStockJob::class,   // job_class 와 정확히 일치하는 FQCN
    ],
],
```

---

## 3. 보관 기간과 정리

처리 기록과 실패 작업은 **보관 기간 설정 하나**를 함께 따릅니다.

| 설정 키 | 타입 | 기본 | 범위 | 의미 |
| --- | --- | --- | --- | --- |
| `drivers.queue_job_log_enabled` | boolean | `true` | — | 처리 기록 사용 |
| `drivers.queue_record_retention_days` | integer | `7` | 1~90 | 처리 기록·실패 작업 공통 보관 기간(일) |

범위 한계값은 `config('core.settings_limits.drivers_queue_record_retention_days_min|max')`(1·90)이고, 저장값이 범위 밖이거나 숫자가 아니면 기본 7일로 읽습니다.

```bash
php artisan queue:prune-records            # 환경설정 값(기본 7일)으로 정리
php artisan queue:prune-records --days=30  # 1~90. 범위 밖이면 INVALID 로 종료하고 아무것도 지우지 않는다
```

- `queue_job_logs` 는 `created_at`, `failed_jobs` 는 failer 의 `prune()`(`failed_at`) 기준으로 지웁니다. failer 가 정리를 지원하지 않는 드라이버(null 등)면 실패 작업 정리는 건너뜁니다.
- 코어 스케줄 카탈로그 항목 `queue-prune-records`(매일 04:10, 사이트 시간대)가 이 명령을 돌립니다. **예전 `queue:prune-failed` 예약을 대신합니다.** `queue:prune-failed` 는 운영자가 직접 등록한 스케줄 호환을 위해 실행 허용 목록에 남아 있습니다.

---

## 4. 실패 작업 재시도·삭제

### 재시도 절차

`Artisan::call('queue:retry')` 를 부르지 않고 프레임워크 `queue:retry` 와 같은 절차를 서비스(`JobQueueService`)가 직접 밟습니다.

```text
failer 에서 실패 작업 조회 (없으면 failed_not_found)
 → 원래 연결·큐를 QueueWorkerRegistry::resolve() 로 다시 해석
 → 해석된 연결의 드라이버가 sync·null 이면 거부 (retry_no_worker)
 → JobRetryRequested 이벤트
 → 시도 횟수 0 · retryUntil 재계산 → pushRaw
 → 실패 기록 삭제 (forget)
```

- **연결·큐 재해석** — 워커가 보지 않는 연결·큐로 되넣으면 그 작업은 영영 처리되지 않습니다. 원래 연결이 「큐 워커」에 등록되지 않았으면 기본 연결로, 큐가 등록되지 않았으면 그 연결의 기본 큐로 넣고, 폴백 사례를 「큐 워커」 구역 경고와 경고 로그(1시간에 한 번)로 남깁니다.
- **sync·null 거부** — sync 큐의 `pushRaw()` 는 아무것도 하지 않으므로 그대로 두면 작업은 사라지고 실패 기록만 지워집니다. 그래서 되넣을 워커가 없으면 422(`queue.messages.retry_no_worker`)로 거부합니다.
- 직렬화도 암호화도 아닌 작업 객체는 워커가 풀지 못하므로 넣지 않고 실패(`retry_failed`)로 돌려줍니다. pushRaw 중 예외도 같은 결과이고, 이때 실패 기록은 남습니다.

### 결과와 상한

| 요청 | 성공 | 실패 |
| --- | --- | --- |
| 단건 재시도 | 200, `data.results[0] = {uuid, ok, message, connection, queue}` | 없으면 404, 넣지 못하면 422(`errors.results`) |
| 단건 삭제 | 200, `data.results[0] = {uuid, ok, message}` | 없으면 404 |
| 일괄 재시도·삭제 | 본문 `uuids[]`(1~100) → 200, `data = {results[], succeeded, failed}` | 일부가 실패해도 200 — 건별 결과로 알린다. 101건 이상은 422 |

한 건이 실패해도 나머지는 계속 처리합니다. 일괄 상한은 `BulkRetryFailedJobRequest::MAX_ITEMS`(100)이고 일괄 삭제도 같은 값을 씁니다.

### 개인정보

실패 작업 상세(`FailedJobDetailResource`)는 예외 전문과 payload 메타(`uuid`·`displayName`·`job`·`maxTries`·`maxExceptions`·`failOnTimeout`·`backoff`·`timeout`·`retryUntil`·`commandName`)만 싣습니다. **직렬화된 작업 객체(`data.command`)는 내려주지 않습니다** — 작업 인자에 수신자 주소·토큰 같은 값이 그대로 들어 있습니다. 목록 조회도 작업 객체를 역직렬화하지 않고 payload JSON 의 메타만 읽습니다(`JobPayloadParser`).

---

## 5. 권한

| 권한 | 허용 |
| --- | --- |
| `core.queue_jobs.read` | 상태·대기·실패·처리 기록 조회 |
| `core.queue_jobs.retry` | 실패 작업 재시도 (단건·일괄) |
| `core.queue_jobs.delete` | 실패 작업 삭제 (단건·일괄) |

권한 그룹 `core.queue_jobs`(「큐 작업 관리」)는 스케줄(`schedules`) 카테고리에 속합니다. 큐 화면은 스케줄 관리 화면(`admin_schedule_list`)의 탭이라 화면에 들어가려면 `core.schedules.read` 도 함께 있어야 합니다 — 큐 권한만 가진 역할은 화면 진입이 403 으로 막힙니다(API 는 큐 권한만으로 호출 가능). 큐를 맡길 역할에는 스케줄 조회 권한을 같이 줍니다. 목록 응답의 `abilities.can_retry`·`can_delete` 는 이 권한을 반영하고, 대기 작업과 처리 기록 행은 항상 false 입니다.

---

## 6. API

경로 접두사 `/api/admin/schedules/queue`, 라우트명 접두사 `api.admin.schedules.queue.`. 요청·응답 필드 전체는 [API 레퍼런스 queue.md](api/queue.md)에 있습니다.

| 메서드 | 경로 | 권한 | 용도 |
| --- | --- | --- | --- |
| GET | `status` | read | 요약 + 워커 상태 보고서 전문(`report`) + 등록 연결·큐(`entries`·`connections`) |
| GET | `pending` | read | 대기 작업 목록 (`meta = {measurable, connection, driver}`) |
| GET | `failed` · `failed/{uuid}` | read | 실패 작업 목록 · 상세 |
| POST | `failed/{uuid}/retry` · `failed/retry` | retry | 단건 · 일괄 재시도 |
| DELETE | `failed/{uuid}` · `failed` | delete | 단건 · 일괄 삭제 |
| GET | `logs` · `logs/{id}` | read | 처리 기록 목록 · 상세 |

목록 쿼리 키(`per_page` 1~100):

| 목록 | `search_field` | 그 밖의 필터 |
| --- | --- | --- |
| pending | `all`·`job`·`uuid` | `queue`·`connection` (없으면 기본 연결) |
| failed | `all`·`job`·`error`·`uuid` | `queue`·`connection`·`failed_start_date`·`failed_end_date` |
| logs | `all`·`job`·`error`·`uuid` | `status`(`all`·`processed`·`failed`)·`queue`·`connection`·`created_start_date`·`created_end_date` |

`queue`·`connection`·`status` 의 `all` 은 필터 없음입니다.

정렬은 `sort_by`·`sort_order`(`asc`·`desc`, 생략 시 `desc`)로 고릅니다. 목록마다 허용 열이 정해져 있고(FormRequest `in:` 화이트리스트 + 저장소 재확인), 같은 값은 `id` 로 순서를 정해 페이지 경계에서 행이 겹치거나 빠지지 않습니다. `sort_by` 를 생략하면 기존 순서(최근순) 그대로입니다.

| 목록 | `sort_by` 허용 열 | 생략 시 순서 |
| --- | --- | --- |
| pending | `created_at`·`available_at`·`attempts` | 최근 등록순(`id` 역순) |
| failed | `failed_at`·`id` | 최근 실패순(`failed_at` 역순) |
| logs | `finished_at`·`duration_ms`·`attempts` | 최근 기록순(`created_at` 역순) |

허용 열 목록의 원본은 저장소 인터페이스 상수(`JobQueueRepositoryInterface::PENDING_SORT_COLUMNS`·`FAILED_SORT_COLUMNS`, `QueueJobLogRepositoryInterface::SORT_COLUMNS`)입니다.

---

## 7. 훅

모두 코어 훅이고 접두사는 `core.queue_job.` 입니다.

| 훅 | 종류 | 인자 |
| --- | --- | --- |
| `core.queue_job.before_retry` / `after_retry` | action | `string $uuid` / `string $uuid, array $result` |
| `core.queue_job.before_retry_many` / `after_retry_many` | action | `array $uuids` / `array $uuids, array $results` |
| `core.queue_job.before_delete` / `after_delete` | action | `string $uuid` / `string $uuid, array $result` |
| `core.queue_job.before_delete_many` / `after_delete_many` | action | `array $uuids` / `array $uuids, array $results` |
| `core.queue_job.before_prune` / `after_prune` | action | `int $days` / `int $days, array $result` (`{days, before, logs, failed}`) |
| `core.queue_job.pending_list_validation_rules` · `failed_list_validation_rules` · `log_list_validation_rules` | filter | `array $rules, FormRequest $request` |
| `core.queue_job.bulk_retry_validation_rules` · `bulk_delete_validation_rules` | filter | `array $rules, FormRequest $request` |

`$result` 한 건은 `{uuid, ok, message}` 이고 재시도 성공이면 `connection`·`queue` 가 더 붙습니다. 일괄 훅은 요청 단위로 한 번씩 발화하며 건별 훅(`before_retry` 등)은 따로 발화하지 않습니다.

---

## 8. 관련 코드 진입점

| 역할 | 위치 |
| --- | --- |
| 컨트롤러 | `app/Http/Controllers/Api/Admin/JobQueueController.php` |
| 서비스 (조회·재시도·삭제·정리) | `app/Services/JobQueueService.php` |
| payload 메타 파서 | `app/Services/JobPayloadParser.php` |
| 저장소 | `app/Repositories/JobQueueRepository.php` · `QueueJobLogRepository.php` |
| 요청 검증 | `app/Http/Requests/Queue/*` |
| 처리 기록 리스너 | `app/Listeners/Queue/RecordQueueJobLog.php` |
| 정리 명령 | `app/Console/Commands/PruneQueueRecordsCommand.php` |
| 모델 | `app/Models/QueueJobLog.php` · `FailedJob.php` (조회 전용 — 쓰기는 failer 경유) |

## 9. 관련 문서

- [API 레퍼런스 — 큐](api/queue.md)
- [hooks.md 「큐 자동 실행」](../extension/hooks.md#큐-자동-실행) — 리스너 큐 옵션, 「큐 워커」 등록 큐만 허용·폴백
- [admin-settings-access.md](admin-settings-access.md) — 큐 워커 등록 키 읽기 규칙
- [notification-system.md](notification-system.md) — 예약 작업 실패 알림 `schedule_failed`
