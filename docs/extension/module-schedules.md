# 확장 스케줄 선언 (getSchedules)

> 모듈·플러그인이 주기 작업을 선언하는 방법. 선언은 `schedules` 표의 행으로 동기화되고, 코어 스케줄러가 그 행을 읽어 실행한다 (코어 7.2.0~).

## TL;DR (5초 요약)

```text
1. module.php / plugin.php 의 getSchedules() 가 스케줄 목록(list<array>)을 돌려준다 — 항목 키는 코어 카탈로그(config/core.php schedules)와 같고 name 을 더한다
2. 선언은 확장 설치·업데이트 때(선언 동기화) schedules 행으로 옮겨진다 — 행 식별은 (module|plugin, 식별자, name)
3. 운영자가 관리자 > 스케줄 관리에서 바꾼 주기·실행 방식·명칭은 업데이트 때 보존된다. 명령·유형·이름·실행 조건·시간대는 선언이 소유한다(화면에서 잠김)
4. 선언으로는 artisan·job 유형만 받는다 (shell·url 은 운영자 직접 등록 전용). 잘못된 선언은 그 항목만 건너뛴다
5. 확장이 비활성이면 행은 남지만 실행되지 않는다. 「데이터도 함께 삭제」로 제거하면 행·이력이 지워진다
6. ja 등 추가 로케일 명칭은 언어팩 seed/schedules.json 이 name 기준으로 채운다
7. 옛 형식(schedule·enabled_config·description 문자열)도 동기화 때 새 형식으로 바뀐다 — 새로 쓰는 코드는 새 형식으로
```

---

## 목차

1. [선언 형식](#1-선언-형식)
2. [검증 규칙](#2-검증-규칙)
3. [실행 조건](#3-실행-조건)
4. [생명주기](#4-생명주기)
5. [운영자 수정과 잠금 필드](#5-운영자-수정과-잠금-필드)
6. [Job 유형 스케줄](#6-job-유형-스케줄)
7. [다국어 (언어팩 seed)](#7-다국어-언어팩-seed)
8. [옛 형식 호환](#8-옛-형식-호환)
9. [확장 화면에서 스케줄 다루기](#9-확장-화면에서-스케줄-다루기)
10. [체크리스트](#10-체크리스트)
11. [관련 문서](#11-관련-문서)

---

## 1. 선언 형식

```php
public function getSchedules(): array
{
    return [
        [
            'name' => 'aggregate-stats',
            'type' => 'artisan',
            'label' => ['ko' => '게시물 현황 집계', 'en' => 'Aggregate board stats'],
            'description' => ['ko' => '대시보드 게시물 현황을 집계합니다.', 'en' => 'Aggregates post statistics for the dashboard.'],
            'command' => 'sirsoft-board:aggregate-stats',
            'frequency' => 'hourly',
            'expression' => '0 * * * *',
        ],
        [
            'name' => 'cancel-pending-orders',
            'type' => 'artisan',
            'label' => ['ko' => '입금 기한 만료 주문 취소', 'en' => 'Cancel overdue unpaid orders'],
            'description' => ['ko' => '입금 기한이 지난 주문을 자동으로 취소합니다(주문 설정에서 켠 경우만).', 'en' => 'Automatically cancels orders past the payment deadline (only when enabled in order settings).'],
            'command' => 'sirsoft-ecommerce:cancel-pending-orders',
            'expression' => '0 0 * * *',
            'enabled_condition' => [
                ['source' => 'module_setting', 'key' => 'order_settings.auto_cancel_expired', 'operator' => 'truthy', 'default' => true],
            ],
        ],
    ];
}
```

| 키 | 필수 | 기본값 | 설명 |
| --- | --- | --- | --- |
| `name` | ✅ | — | 확장 안에서 고유한 기계 식별자. 소문자·숫자로 시작하고 소문자·숫자·`-`·`_`·`.` 만, 255자 이하. **한 번 정하면 바꾸지 않는다** — 바꾸면 다른 행으로 보아 옛 행(운영자 수정 포함)이 지워진다 |
| `type` | 권장 | `artisan` | `artisan` · `job` |
| `command` | ✅ | — | artisan 명령(옵션 포함) 또는 Job 클래스 FQCN |
| `expression` | ✅ | — | cron 식. 시간대를 정하지 않으면 사이트 설정 시간대 기준 |
| `frequency` | | `custom` | `everyThirtySeconds` 면 30초 주기(expression 은 `* * * * *` 로 맞춰진다). `hourly`·`daily` 등은 화면 표시용 |
| `label` | 권장 | — | `{로케일: 문자열}` 표시 명칭. 없으면 화면에 `name` 이 보인다 |
| `description` | | — | `{로케일: 문자열}` 설명 |
| `timezone` | | `null` | 시각을 특정 시간대로 고정할 때만 (예: 환율 갱신 `Asia/Seoul`) |
| `without_overlapping` | | `0` | 중복 실행 방지 잠금 유지 분. 0~10080. `true` 는 1440 으로 바뀐다 |
| `on_one_server` | | `true` | 다중 서버에서 한 서버만 실행 |
| `dispatch_mode` | | `sync` | `sync`(스케줄러에서 바로 실행) · `queue`(큐로 보내기) |
| `history_mode` | | `all` | `all` · `failures_only` (자주 도는 작업용) |
| `notify_on_failure` | | `true` | 예약 실행이 실패하면 관리자 알림 (하루 1회) |
| `timeout` | | `null` | 초 단위 정수, 1 이상 |
| `enabled_condition` | | `null` | 실행 조건 목록 (AND) — [3절](#3-실행-조건) |
| `command_class` | | `null` | 명령 클래스 FQCN. 웹 요청(수동 실행 API·웹 크론)에서 실행할 때 확장 명령을 먼저 등록하는 데 쓴다 |
| `run_in_maintenance` | | `false` | 점검 모드에서도 실행 |
| `is_active` | | `true` | 처음 만들 때 활성 여부 |

위 표에 없는 키는 저장하지 않고 버립니다. 실행 방식·이력·잠금의 의미는 [scheduling.md](../backend/scheduling.md) 를 봅니다.

---

## 2. 검증 규칙

동기화 직전에 `ScheduleManifestValidator` 가 항목마다 검사합니다. 위반 항목은 **그 항목만 건너뛰고** 경고 로그(`[ScheduleSync] 잘못된 스케줄 선언 — 건너뜀`)를 남깁니다. 확장 설치·업데이트 전체는 막지 않으며, 같은 이름의 기존 행은 지우지 않습니다.

| 검사 | 규칙 |
| --- | --- |
| `name` | 형식·길이 ([1절](#1-선언-형식)) |
| `type` | `artisan` · `job` 만. shell·url 은 운영자 직접 등록 전용 |
| `command` | 비어 있으면 안 됨. artisan 은 거부 목록·거부 접두사(`make:`)에 걸리면 거부. job 은 클래스가 있어야 함 |
| `expression` | 올바른 cron 식 |
| `frequency` · `dispatch_mode` · `history_mode` | 허용 값 |
| `timezone` | PHP 시간대 식별자 |
| `without_overlapping` | 0~10080 정수 |
| `timeout` | 1 이상 정수 |
| `label` · `description` | `{로케일: 문자열}` |
| `enabled_condition` | 목록이고 각 조건이 `source`·`key`·`operator`(equals 는 `value`)를 갖춤 |
| `command_class` | 클래스가 있어야 함 |

확장 artisan 명령은 코어 허용목록에 없어도 선언할 수 있습니다. 실행 시점에 실행기가 명령 클래스의 네임스페이스(`Modules\`·`Plugins\`)로 확장 소유를 판정합니다(`config/schedule_security.php` `allow_extension_commands`). 코어 명령(`cache:clear` 등)을 확장이 선언하면 코어 허용목록 규칙을 따릅니다. 선언 검사는 기본 정책(config)만 봅니다 — 서버 관리자의 덮어쓰기 파일(`storage/app/schedule_security.json`, [스케줄 관리 문서](../backend/scheduling.md#101-보안-정책-덮어쓰기-storageappschedule_securityjson))은 사이트마다 달라 확장 선언이 기대면 안 됩니다. 확장 자기 Job 클래스는 필터 `core.schedule.filter_allowed_job_classes` 로 등재합니다.

---

## 3. 실행 조건

설정에 따라 실행 여부가 바뀌는 작업은 커맨드 안에서 설정을 확인하는 대신 `enabled_condition` 으로 선언합니다. 조건이 맞지 않는 틱은 건너뛰고, 화면에 「설정에서 꺼짐」 배지가 붙습니다.

```php
'enabled_condition' => [
    ['source' => 'plugin_setting', 'key' => 'unusedImageCleanup', 'operator' => 'truthy', 'default' => true],
],
```

- `source`: `module_setting` · `plugin_setting` · `core_setting` · `config`
- `operator`: `truthy` · `not_empty` · `equals`(+ `value`)
- `module_setting`/`plugin_setting` 의 식별자는 생략하면 **선언한 확장 자신**입니다. 다른 확장 설정을 볼 때만 `identifier` 를 적습니다.
- `default` 는 설정이 없을 때 쓸 값입니다. 7.1.x 까지 `enabled_config` 로 켜던 작업은 `default => true` 가 같은 의미입니다.
- 여러 조건은 AND 입니다.
- 키는 그 확장의 `config/settings/defaults.json` defaults 나 `getConfigValues()` 에 있어야 합니다.
- 운영자의 「즉시 실행」은 조건을 보지 않습니다.

삭제처럼 되돌릴 수 없는 처리는 조건과 별개로 커맨드 안에서도 설정을 다시 확인합니다(게시판 첨부 영구 정리처럼 작업의 일부만 설정이 켜는 경우).

---

## 4. 생명주기

| 사건 | 스케줄 행 |
| --- | --- |
| 확장 설치 (관리자 화면·`module:install`·`plugin:install`) | 선언대로 행 생성. 비활성으로 설치돼도 행은 만들고, 활성화 전까지 실행하지 않는다 |
| 확장 업데이트 (관리자 화면·`module:update`·`plugin:update`) | 선언을 다시 동기화. 새 항목은 생성, 기존 항목은 운영자 수정을 보존하며 갱신, 선언에서 빠진 항목은 이력과 함께 삭제 |
| 코어 7.2.0 업그레이드 | 활성 확장 전체의 선언을 재동기화 (옛 형식 포함) |
| 비활성화 | 행 유지. 등록기·실행기가 걸러 실행하지 않는다. 화면 수동 실행은 422(`owner_inactive`) |
| 제거 — 데이터 유지 | 행 유지 |
| 제거 — 「데이터도 함께 삭제」 | 그 확장의 행과 실행 이력 삭제. 제거 확인 창이 지워질 스케줄 수를 보여 준다 |

동기화는 `ModuleManager::syncDeclarativeArtifacts()` / `PluginManager::syncDeclarativeArtifacts()` 의 마지막 단계(`syncModuleSchedules`·`syncPluginSchedules` → `ExtensionScheduleSyncHelper::syncSchedules()`)입니다.

**빈 선언 보호** — `getSchedules()` 가 빈 배열을 돌려주는데 그 확장의 행이 이미 있으면 정리하지 않고 경고만 남깁니다(실수로 선언이 비어 데이터가 사라지는 것을 막는다). 스케줄을 모두 없애려면 운영자가 행을 끄도록 안내하거나, 하나라도 남긴 상태에서 필요한 항목만 빼십시오.

**업그레이드 스텝과의 순서** — 확장 업데이트는 선언 동기화를 업그레이드 스텝보다 먼저 합니다. 업그레이드 스텝에서 행을 고쳐야 하면(예: 옛 설정값을 cron 으로 옮기기) 행이 이미 있다고 보고 작업할 수 있습니다. 옮긴 값은 `user_overrides` 에 필드 이름을 더해 다음 동기화가 되돌리지 않게 합니다. 업그레이드 스텝은 모델 이벤트를 거치지 않고 행을 직접 고치므로, 주기(`expression`)를 바꿨으면 같은 갱신에서 `next_run_at` 도 다시 계산해 넣으십시오 — 행 시간대(없으면 `app.schedule_timezone`) 기준 다음 실행 시각, 비활성 행이나 해석할 수 없는 식이면 `null`. 빠뜨리면 첫 실행 전까지 스케줄 목록의 「다음 실행」이 옛 시각으로 보입니다.

---

## 5. 운영자 수정과 잠금 필드

| 구분 | 필드 |
| --- | --- |
| 선언이 소유 (화면에서 잠김, 바꾸면 422) | `command` · `type` · `name` · `enabled_condition` · `timezone` |
| 운영자가 바꿀 수 있고 업데이트 때 보존 | `expression` · `frequency` · `timeout` · `is_active` · `without_overlapping` · `on_one_server` · `dispatch_mode` · `queue_connection` · `queue_name` · `history_mode` · `notify_on_failure` · `run_in_maintenance` · `label`·`description`(로케일 단위) |

운영자가 바꾸지 않은 필드는 새 선언 값으로 갱신됩니다. 그래서 다음 버전에서 기본 시각을 바꾸면, 운영자가 시각을 손대지 않은 사이트에만 반영됩니다. 확장 소유 행은 삭제할 수 없습니다.

---

## 6. Job 유형 스케줄

artisan 명령 대신 Job 을 주기 실행하려면 `type => 'job'`, `command` 에 Job 클래스 FQCN 을 적고, 그 클래스를 허용 Job 목록에 등재합니다. 등재하지 않은 클래스는 실행 시점에 거부됩니다.

```php
// 확장의 훅 리스너 (filter)
public function allowJobs(array $classes): array
{
    $classes[] = \Modules\Vendor\Sample\Jobs\RebuildIndexJob::class;

    return $classes;
}
// 구독: 'core.schedule.filter_allowed_job_classes' => ['method' => 'allowJobs', 'type' => 'filter']
```

- Job 은 생성자 인자 없이 컨테이너로 만들어집니다(`app($class)`).
- `dispatch_mode => 'queue'` 면 Job 자체가 큐에 들어가 Job 의 큐·timeout·재시도·unique·`failed()` 가 그대로 적용됩니다. 스케줄 실행 이력은 "큐에 넣음" 만 기록하고, 실패 알림(`schedule_failed`)은 보내지 않습니다 — Job 의 실패 처리가 맡습니다.
- `dispatch_mode => 'sync'` 면 스케줄러 프로세스에서 동기로 실행하고 결과가 이력에 남습니다.

---

## 7. 다국어 (언어팩 seed)

선언에는 `ko`·`en` 을 적고, 그 밖의 로케일은 확장 언어팩의 seed 파일(예: `lang-packs/_bundled/g7-plugin-sirsoft-koreaexim-ja/seed/schedules.json`)이 채웁니다. 형식은 `name` 을 키로 쓰는 객체입니다.

```json
{
    "aggregate-stats": {
        "label": "投稿状況の集計",
        "description": "ダッシュボードの投稿状況を集計します。"
    }
}
```

- 동기화 때 필터 `seed.{확장 식별자}.schedules.translations` 가 활성 언어팩의 값을 `label`·`description` 에 더합니다(매칭 키 `name`). 선언의 `label`·`description` 이 다국어 배열이어야 채워집니다.
- 언어팩을 켜면 이미 있는 행에도 그 로케일 값이 들어가고, 끄면 그 로케일 키가 빠집니다. 운영자가 그 로케일 명칭을 고쳤다면(`label.ja` 등) 보존됩니다.
- 언어팩은 자기 대상 확장이 소유한 행만 바꿉니다. 직접 등록 행은 어느 언어팩도 건드리지 않습니다.

자세한 언어팩 구조는 [language-packs.md](language-packs.md) 를 봅니다.

---

## 8. 옛 형식 호환

7.1.x 형식도 계속 받습니다. 동기화 직전에 `ExtensionScheduleSyncHelper::normalizeLegacySchedules()` 가 새 형식으로 바꿉니다.

```php
// 7.1.x 형식 (계속 동작하지만 새 코드에는 쓰지 않는다)
[
    'command' => 'sirsoft-ecommerce:sync-stock',
    'schedule' => 'hourly',                 // 또는 cron 식 '*/30 * * * *'
    'description' => '재고 동기화',
    'enabled_config' => 'sirsoft-ecommerce.stock.auto_sync',
],
```

| 옛 키 | 바뀌는 값 |
| --- | --- |
| (name 없음) | 명령 문자열을 소문자·`-` 로 바꾼 값 (`sirsoft-ecommerce:sync-stock` → `sirsoft-ecommerce-sync-stock`). 겹치면 `-2`, `-3` … |
| `schedule` | cron 식이면 그대로, Laravel 주기 메서드명(`hourly`·`daily`·`everyFiveMinutes` 등)이면 cron 으로 변환, `everyThirtySeconds` 는 30초 주기 |
| `description` 문자열 | `{사이트 기본 로케일: 문자열}` 로 감싸 `label`·`description` 양쪽에 사용 |
| `enabled_config` | `{식별자}.` 접두사를 떼고 `[{source: module_setting\|plugin_setting, key, operator: truthy, default: true}]` |
| `without_overlapping: true` | 1440분 |

옛 형식은 `name` 이 명령에서 만들어지므로, 옛 형식인 채로 명령 옵션을 바꾸면 이름도 바뀌어 기존 행(운영자 수정 포함)이 지워지고 새 행이 생깁니다.

새 형식으로 옮길 때는 원하는 `name` 을 적으면 됩니다 — 동기화가 **같은 명령의 옛 자동 이름 행을 새 `name` 으로 이어받습니다**(새 `name` 행이 아직 없을 때만, 이름만 바꾸므로 운영자 수정·실행 이력·다음 실행 시각이 남는다). 코어를 먼저 7.2.0 으로 올린 사이트는 옛 버전 확장이 자동 이름으로 행을 만들어 두므로, 이 이어받기가 없으면 확장 업데이트 때 그 사이 운영자가 바꾼 값이 사라집니다. 전제는 **명령 문자열과 선언 순서가 옛 버전과 같은 것**입니다(같은 명령이 여러 개면 순서로 `-2`·`-3` 을 매긴다). 명령까지 바꾸면 다른 작업으로 보고 옛 행을 정리합니다. 번들 확장 테스트는 `AssertsExtensionScheduleDeclarations::assertLegacyRowsAdoptedOnFormatUpgrade()` 로 이 이어받기를 실제 선언에 걸어 확인합니다. 언어팩 seed 는 새 `name` 으로 매칭됩니다.

---

## 9. 확장 화면에서 스케줄 다루기

확장 설정 화면에서 자기 스케줄을 보여 줄 때는 새 API 를 만들지 않고 코어 API 를 그대로 씁니다. 권한·검증·실행 경로가 코어 화면과 같아집니다.

| 용도 | 호출 |
| --- | --- |
| 자기 소유 목록 | `GET /api/admin/schedules?extension_type=module&extension_identifier={식별자}` (플러그인은 `extension_type=plugin`) |
| 활성 토글 | `PATCH /api/admin/schedules/bulk-status` `{ids: [id], is_active}` |
| 주기·실행 방식 변경 | 코어 화면으로 이동 `/admin/schedules?extension_type=module&extension_identifier={식별자}` — 스케줄 관리가 이 쿼리를 소유자 필터로 읽는다 |

목록 행에는 `label_localized` · `owner` · `locked_fields` · `overridden_fields` · `unmet_conditions` · `next_run_at` 등이 함께 내려옵니다([api/schedules.md](../backend/api/schedules.md)).

확장 설정에 "실행 시각" 같은 칸을 따로 두지 마십시오. 시각의 기준은 스케줄 행 하나여야 합니다. 다음 실행 시각이 필요하면 `ScheduleLookupService` 로 행을 찾아 계산합니다.

```php
$lookup = app(\App\Services\Schedule\ScheduleLookupService::class);
$row = $lookup->findOwned('plugin', 'sirsoft-koreaexim', 'update-rates');
$next = $row ? $lookup->nextRunAt($row) : null; // 일정상 다음 시각 (UTC)
```

---

## 10. 체크리스트

- [ ] 항목마다 `name`·`type`·`label{ko,en}`·`command`·`expression` 을 적었다
- [ ] `name` 은 이전 버전에서 쓰던 이름 그대로다 (옛 형식에서 옮겼다면 자동 생성 이름)
- [ ] 설정으로 켜고 끄는 작업은 `enabled_condition` 으로 선언했고, 키가 defaults 에 있다
- [ ] 오래 걸리는 작업은 `dispatch_mode => 'queue'` 와 `timeout` 을, 겹치면 안 되는 작업은 `without_overlapping` 을 정했다
- [ ] 30초 주기·매시 실행처럼 잦은 작업은 `history_mode => 'failures_only'` 를 검토했다
- [ ] job 유형은 `core.schedule.filter_allowed_job_classes` 로 클래스를 등재했다
- [ ] 언어팩 seed 파일(seed/schedules.json)에 새 `name` 의 번역을 더했다
- [ ] 기본 시각을 바꿨다면 CHANGELOG 에 "운영자가 바꾸지 않은 사이트에만 반영" 을 적었다

---

## 11. 관련 문서

- [scheduling.md](../backend/scheduling.md) — 실행 경로·실행 방식·잠금·실패 알림
- [extension-update-system.md](extension-update-system.md) — 확장 업데이트와 선언 동기화
- [user-overrides.md](../backend/user-overrides.md) — 운영자 수정 보존
- [translatable-seeders.md](../backend/translatable-seeders.md) — 언어팩 seed 주입
- [hooks.md](hooks.md) · [hook-catalog.md](hook-catalog.md) — 훅 구독
- [module-commands.md](module-commands.md) — 확장 artisan 명령 작성
