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
| `drivers.search_engine_driver` | `scout.driver` |
| `drivers.redis_*` | `database.redis.*` |
| `drivers.memcached_*` | `cache.stores.memcached.*` |
| `drivers.s3_*` | `filesystems.disks.s3.*` |
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

## 새 admin 환경설정 키 추가 시 점검

새 카테고리/키를 `storage/app/settings/*.json` 에 추가할 때:

1. `SettingsServiceProvider::applyXxxConfig()` 에 sync 코드를 추가하면 → `config()` / `g7_core_settings()` 둘 다 동치.
2. sync 코드를 추가하지 않으면 → `g7_core_settings()` 만 사용 가능. `config()` 는 sync 되지 않은 키를 모르기 때문이다.
3. testing 격리가 필요한 키 (드라이버, 외부 서비스 자격증명 등) 는 `! $isTestingEnv` 가드로 sync 를 차단한다. 이 키들은 `config()` 가 testing 격리 SSoT 다.
4. 의미가 다른 키 (`app.timezone` 처럼) 는 sync 하지 않고 별도 키 (`app.default_user_timezone`) 로 분리한다.

---

## 관련 문서

- [service-provider.md](service-provider.md) — ServiceProvider 안전성 (DB 접근 가드)
- [core-config.md](core-config.md) — `config/core.php` (별도 SSoT, admin 환경설정과 무관)
