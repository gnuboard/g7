# Admin 환경설정 값 접근 (`g7_core_settings` vs `config()`)

> **목적**: admin UI 가 SSoT 인 환경설정 값을 코드에서 읽을 때 `g7_core_settings()` 와 `config()` 중 무엇을 써야 하는지 결정 기준 제공

---

## TL;DR (5초 요약)

```text
1. 동기화 SSoT: storage/app/settings/*.json → SettingsServiceProvider::register() 가 Laravel config() 로 sync
2. sync 된 키는 config() 와 g7_core_settings() 가 동치 — 둘 중 어느 쪽을 써도 같은 값
3. 예외 1: app.timezone 은 항상 UTC (서버 저장용). 사용자 시간대는 g7_core_settings('general.timezone') 또는 config('app.default_user_timezone')
4. 예외 2: testing 환경에서 drivers.cache/session/queue/session_lifetime 은 sync 차단 (격리 보호) — config() 는 phpunit.xml 격리값, g7_core_settings() 는 dev 공유 파일값
5. 결론: 드라이버 카테고리는 config() 우선. 그 외 admin 관리 키는 둘 다 가능 (취향)
```

---

## 동기화 매핑 (sync 된 키)

`SettingsServiceProvider::register()` 가 `storage/app/settings/*.json` → Laravel `config()` 로 단방향 sync.

| g7 카테고리.키 | sync 된 Laravel config 키 |
|---------------|---------------------------|
| `mail.mailer` | `mail.default` |
| `mail.host`, `mail.port`, `mail.username`, `mail.password`, `mail.encryption` | `mail.mailers.smtp.*` |
| `mail.from_address` | `mail.from.address` |
| `mail.from_name` | `mail.from.name` |
| `mail.mailgun_*` | `services.mailgun.*` |
| `mail.ses_*` | `services.ses.*` |
| `general.site_name` | `app.name` |
| `general.site_url` | `app.url` |
| `general.timezone` | `app.default_user_timezone` (`app.timezone` 아님) |
| `general.language` | `app.locale` |
| `debug.mode` | `app.debug`, `logging.*.level` |
| `drivers.cache_driver` | `cache.default` (testing 차단) |
| `drivers.session_driver` | `session.driver` (testing 차단) |
| `drivers.session_lifetime` | `session.lifetime` (testing 차단) |
| `drivers.queue_driver` | `queue.default` (testing 차단) |
| `drivers.storage_driver` | `filesystems.default` |
| `drivers.public_asset_disk` | `core.storage.public_asset_disk` (`'none'`/빈값 → `''`, testing 차단) |
| `drivers.search_engine_driver` | `scout.driver` |
| `drivers.redis_*` | `database.redis.*` |
| `drivers.memcached_*` | `cache.stores.memcached.*` |
| `drivers.s3_*` | `filesystems.disks.s3.*` — `s3_url` → `url`(공개 URL base), `s3_endpoint` → `endpoint`(API 요청 대상), `s3_use_path_style` → `use_path_style_endpoint` |
| `drivers.storage_driver` = `s3` | `attachment.disk` = `s3` (단, `ATTACHMENT_DISK` env 명시 시 env 우선 — `attachment.disk_explicit` 로 판별) |
| `geoip.feature_enabled`, `geoip.license_key`, `geoip.auto_update_enabled` | `geoip.*` |

위 매핑은 [`app/Providers/SettingsServiceProvider.php`](../../app/Providers/SettingsServiceProvider.php) 가 단일 SSoT.

---

## 어느 쪽을 쓸 것인가

### 둘 다 동치 (자유 선택)

`SettingsServiceProvider::register()` 가 sync 한 키는 둘 다 같은 값을 반환한다. 가독성 기준으로 선택.

```php
// 둘 다 동치
config('app.name');                  // sync 결과
g7_core_settings('general.site_name'); // SSoT 직접 조회

config('mail.from.address');
g7_core_settings('mail.from_address');

config('cache.default');
g7_core_settings('drivers.cache_driver');
```

### `config()` 를 써야 하는 경우 (testing 격리)

`drivers.cache_driver` / `drivers.session_driver` / `drivers.queue_driver` / `drivers.session_lifetime` 는 testing 환경에서 sync 가 차단된다 (이슈 #258 회귀 방지 — `storage/app/settings/drivers.json` 이 dev 와 testing 이 공유하는 파일이므로 dev 의 Redis/DB 드라이버가 testing 으로 흘러들면 격리가 깨진다).

```php
// testing 환경에서:
config('queue.default');                    // phpunit.xml 의 sync (격리 유지)
g7_core_settings('drivers.queue_driver');   // dev 공유 drivers.json 의 database (격리 깨짐)
```

따라서 드라이버 카테고리는 `config()` 사용이 안전하다. 새 코드에서 `g7_core_settings('drivers.*')` 직접 조회는 테스트 격리 회귀 위험을 만든다.

### `g7_core_settings()` 만 정확한 경우 (의도적으로 분리된 키)

`general.timezone` ↔ `app.timezone` 은 의도적으로 다르다.

| 키 | 의미 | 값 예 |
|----|-----|------|
| `config('app.timezone')` | 서버 저장 타임존 (변경 금지) | `UTC` 고정 |
| `config('app.default_user_timezone')` | 사용자 표시용 (sync 됨) | `Asia/Seoul` |
| `g7_core_settings('general.timezone')` | 사용자 표시용 (SSoT) | `Asia/Seoul` |

사용자에게 표시할 타임존이 필요하면 `config('app.default_user_timezone')` 또는 `g7_core_settings('general.timezone')` 을 사용한다. `config('app.timezone')` 사용은 항상 UTC 가 반환되므로 사용자 표시 의도라면 버그다.

---

## config 미러 갱신 시점 (부팅 + 저장)

`g7_settings.core` / `g7_settings.modules.{id}` / `g7_settings.plugins.{id}` 는 in-memory config 미러다. `g7_core_settings()` / `g7_module_settings()` / `g7_plugin_settings()` 가 이 미러를 읽는다.

미러는 **부팅 때와 저장 때** 모두 채워진다. 채움 로직은 `App\Support\ExtensionSettingsMirror` 가 단일 소유하며, Provider 와 저장 경로가 같은 코드를 호출한다.

| 축 | 채우는 지점 |
|----|-----------|
| 코어 | 부팅 `SettingsServiceProvider::register()` / 저장 `SettingsService::invalidateSettingsCache()` |
| 모듈 | 부팅 `CoreServiceProvider` / 저장 각 모듈 SettingsService 의 캐시 초기화 지점에서 `g7_refresh_module_settings_config($id)` |
| 플러그인 | 부팅 `CoreServiceProvider` / 저장 `PluginSettingsService::save()` |

부팅만으로 채우면 FPM 에서는 요청마다 재부팅되어 드러나지 않지만, 큐 워커·`schedule:work`·Reverb 처럼 프로세스가 상주하는 환경에서는 저장 후에도 그 프로세스가 옛 값을 영원히 읽는다. 새 저장 경로를 만들면 그 자리에서 미러 재채움을 함께 호출한다.

> 다중 상주 워커 간 브로드캐스트 동기화는 범위 밖이다 — 저장을 수행한 프로세스와 이후 새로 부팅되는 프로세스가 정합하면 충분하고, 워커 재기동은 드라이버 탭의 큐 재시작이 담당한다.

### 미러는 민감 항목을 담지 않는다 — 민감값은 전용 게터로

스키마에 `sensitive: true` 로 선언된 필드는 미러에서 **키 자체가 제거**된다 (암호문도 싣지 않는다).

미러는 봇 전용 화면 생성기가 레이아웃 표현식에서 참조한 키를 **제한 없이** 조회하는 경로다. 민감값이 실려 있으면 레이아웃이 그 키를 참조하는 순간 평문이 봇 HTML 로 나간다. 브라우저용 설정 전달 경로는 애초에 민감 항목을 제외하므로 화면만 봐서는 드러나지 않는 비대칭이다.

민감값이 필요한 서버 코드는 전용 게터를 쓴다.

```php
// 표시·분기용 (민감 항목 제외)
g7_plugin_settings('sirsoft-gdpr', 'duplicate_block_enabled');

// 민감값 (복호화 포함)
plugin_setting('sirsoft-pay_kginicis', 'api_key');
```

---

## 새 admin 환경설정 키 추가 시 점검

새 카테고리/키를 `storage/app/settings/*.json` 에 추가할 때:

1. `SettingsServiceProvider::applyXxxConfig()` 에 sync 코드를 추가하면 → `config()` / `g7_core_settings()` 둘 다 동치.
2. sync 코드를 추가하지 않으면 → `g7_core_settings()` 만 사용 가능. `config()` 는 sync 되지 않은 키를 모르기 때문이다.
3. testing 격리가 필요한 키 (드라이버, 외부 서비스 자격증명 등) 는 `! $isTestingEnv` 가드로 sync 를 차단한다. 이 키들은 `config()` 가 testing 격리 SSoT 다.
4. 의미가 다른 키 (`app.timezone` 처럼) 는 sync 하지 않고 별도 키 (`app.default_user_timezone`) 로 분리한다.
5. 고급 탭 화면에 얹을 카테고리는 `config/settings/defaults.json` 의 `frontend_schema.{카테고리}.merge_into` 를 `advanced` 로 선언한다. 저장 시 어느 카테고리 파일에 쓸지는 이 선언에서 도출되므로 별도 등록이 필요 없다. 선언이 없으면 화면·검증·읽기가 모두 정상인데 입력값만 저장되지 않고 버려진다 — 저장 응답은 성공이고 화면에도 값이 보여 실패 신호가 없으므로, 새 카테고리를 추가했으면 저장 후 `storage/app/settings/{카테고리}.json` 이 생성되는지 직접 확인한다.

---

## 관련 문서

- [service-provider.md](service-provider.md) — ServiceProvider 안전성 (DB 접근 가드)
- [core-config.md](core-config.md) — `config/core.php` (별도 SSoT, admin 환경설정과 무관)
