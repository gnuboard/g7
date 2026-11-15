# 서버 이전 안내

> **목적**: 운영 중인 그누보드7 사이트를 다른 서버(또는 다른 DB·다른 도메인)로 옮길 때 무엇을 옮기고, 무엇을 바꾸고, 옮긴 뒤 무엇을 실행해야 하는지를 순서대로 정리한다.
>
> **관련 문서**: [README.md](README.md) | [Admin 환경설정 값 접근](admin-settings-access.md) | [코어 업데이트 시스템](core-update-system.md) | [리버스 프록시](reverse-proxy.md) | [INSTALL.md](../../INSTALL.md)

---

## TL;DR (5초 요약)

```text
1. 옮길 것: DB 덤프 · storage/app (설정 JSON·업로드) · .env · 확장 디렉토리(modules·plugins·templates·lang-packs)
2. APP_KEY 는 그대로 가져간다 — 바꾸면 암호화해 저장한 설정과 로그인 세션을 읽지 못한다
3. 바꿀 것은 두 곳이다: .env (DB·APP_URL·Redis·프록시) 와 설정 JSON (storage/app/settings/*.json)
4. 드라이버·메일·사이트 주소는 설정 JSON 이 .env 를 이긴다 — .env 만 고치면 반영되지 않는다 (G7_ENV_PRIORITY=true 로 뒤집을 수 있다)
5. 옮긴 뒤: 권한 → storage:link → extension:update-autoload → config:clear → config:cache → route:cache → hooks:cache → 큐 재시작 → cron
6. storage/installer/runtime.php 가 남아 있으면 7.1.6 업데이트가 .env 로 옮기고 지운다 (그 전 판에서는 이 파일이 .env 를 이긴다)
```

---

## 목차

1. [옮기기 전에 — 옛 서버에서 챙길 것](#1-옮기기-전에--옛-서버에서-챙길-것)
2. [APP_KEY 를 지킨다](#2-app_key-를-지킨다)
3. [바꿔야 하는 값](#3-바꿔야-하는-값)
4. [옮긴 뒤 실행할 명령](#4-옮긴-뒤-실행할-명령)
5. [DB 엔진이 바뀔 때 (MariaDB → MySQL)](#5-db-엔진이-바뀔-때-mariadb--mysql)
6. [옮긴 뒤 확인](#6-옮긴-뒤-확인)
7. [자주 겪는 문제](#7-자주-겪는-문제)

---

## 1. 옮기기 전에 — 옛 서버에서 챙길 것

| 무엇 | 어디 | 메모 |
|------|------|------|
| DB 덤프 | DB 전체 (테이블 접두사 포함) | 엔진이 바뀌면 [5절](#5-db-엔진이-바뀔-때-mariadb--mysql)을 먼저 읽는다 |
| 설정 JSON | `storage/app/settings/*.json` | 관리자 환경설정의 실제 저장소. 메일 비밀번호·S3 키 같은 값은 `APP_KEY` 로 암호화돼 있다 |
| 업로드 파일 | `storage/app/` (공개 파일은 `storage/app/public/`) | S3 를 쓰는 사이트는 버킷이 그대로면 옮길 것이 없다 |
| 환경 파일 | `.env` | `APP_KEY` 가 여기 있다 ([2절](#2-app_key-를-지킨다)) |
| 확장 | `modules/` · `plugins/` · `templates/` · `lang-packs/` | `_bundled`·`_pending` 이 아닌 활성 디렉토리가 실제로 도는 코드다. 사이트에 맞게 고친 확장이 있으면 반드시 함께 옮긴다 |
| 설치 완료 표시 | `storage/app/g7_installed` | 없으면 새 서버가 설치 화면으로 보낼 수 있다(`.env` 의 `INSTALLER_COMPLETED=true` 로도 판정한다) |
| 설치 잔여 파일 | `storage/installer/runtime.php` 가 **있는지** | 있으면 아래 설명을 읽는다 |

### `storage/installer/runtime.php` 가 남아 있다면

웹 설치는 진행 중 DB 접속 정보와 `APP_KEY` 를 이 파일에 잠깐 두고, 설치가 끝나면 `.env` 에 합친 뒤 지운다. 지우지 못한 채 설치가 끝난 사이트에는 이 파일이 남아 있고, **7.1.6 이전 판에서는 이 파일의 값이 `.env` 를 이긴다.** 그래서 새 서버에서 `.env` 의 DB 정보를 고쳐도 옛 DB 로 접속한다.

- 이 파일이 있으면 **옮기기 전에 내용을 확인**한다. `.env` 의 `DB_WRITE_*` · `DB_PREFIX` · `APP_KEY` 가 비어 있고 이 파일로만 돌던 사이트도 있다 — 그런 사이트는 파일만 지우면 접속하지 못한다.
- 가장 안전한 순서: 옛 서버에서 코어를 **7.1.6 이상으로 먼저 올린다.** 업데이트가 이 파일의 값을 `.env` 로 옮기고(바꾼 것이 있으면 `.env.bak-7.1.6-<시각>` 사본을 남긴다) 파일을 지운다. 그 뒤 `.env` 를 가져가면 된다.
- 먼저 올릴 수 없으면: 이 파일의 `APP_KEY` 와 DB 접속 정보를 `.env` 에 손으로 옮겨 적고, 새 서버에는 이 파일을 가져가지 않는다.

7.1.6 부터는 설치가 끝난 사이트에 이 파일이 남아 있어도 `.env` 에 적힌 DB 값이 이기고, `.env` 의 빈 칸만 이 파일의 값으로 채운다. 다만 `APP_KEY` 는 이 파일의 값이 그대로 쓰인다(지금까지 암호화에 쓰인 키이기 때문이다). `.env` 의 DB 계정이 예시 파일의 기본값(`root`)으로 남아 있고 이 파일의 계정은 다른 값이면 — 설치 마무리가 `.env` 를 채우지 못한 사이트다 — DB 접속 정보도 이 파일의 값이 쓰인다.

---

## 2. APP_KEY 를 지킨다

`APP_KEY` 는 로그인 세션·쿠키뿐 아니라 **설정 JSON 안의 비밀값(메일 비밀번호, S3·Mailgun·SES 키, 웹소켓 시크릿 등)** 을 암호화하는 데 쓰인다. 새 서버에서 `php artisan key:generate` 를 실행하거나 새로 설치한 `.env` 의 키를 쓰면 이 값들을 읽지 못한다.

- 옛 서버 `.env` 의 `APP_KEY` 줄을 그대로 가져간다.
- 옛 `.env` 의 `APP_KEY` 가 비어 있으면 `storage/installer/runtime.php` 를 본다([1절](#storageinstallerruntimephp-가-남아-있다면)).
- 키를 잃었다면 되살릴 방법은 없다. 관리자 환경설정에서 비밀값을 다시 입력해 저장해야 한다.

---

## 3. 바꿔야 하는 값

값이 **두 곳**에 나뉘어 있다는 점이 핵심이다. `.env` 만 고치고 끝내면 절반만 바뀐다.

### 3.1 `.env`

| 키 | 설명 |
|----|------|
| `DB_WRITE_HOST` · `DB_WRITE_PORT` · `DB_WRITE_DATABASE` · `DB_WRITE_USERNAME` · `DB_WRITE_PASSWORD` | 쓰기 DB |
| `DB_READ_*` | 읽기 DB를 따로 둘 때만. 비워 두면 쓰기 DB 를 따른다 |
| `DB_PREFIX` | 덤프한 테이블의 접두사와 같아야 한다 |
| `APP_URL` | 새 주소. 단, 사이트 주소는 설정 JSON(`general.site_url`)이 이긴다 — 아래 3.2 를 함께 고친다 |
| `REDIS_HOST` · `REDIS_PORT` · `REDIS_PASSWORD` | Redis 를 쓰는 사이트. 이 값도 설정 JSON(`drivers.redis_*`)이 이긴다 |
| `TRUSTED_PROXIES` · `TRUSTED_HOSTS` | 프록시·로드밸런서 뒤로 옮기거나 도메인이 바뀔 때. [리버스 프록시](reverse-proxy.md) 참고 |
| `SESSION_DOMAIN` | 도메인이 바뀌는데 값을 적어 둔 사이트 |
| `G7_ENV_PRIORITY` | 아래 「env 우선 모드」 |

### 3.2 설정 JSON (`storage/app/settings/*.json`)

관리자 환경설정에서 저장한 값이다. 부팅할 때 이 값이 Laravel 설정으로 들어가므로, **대응하는 `.env` 키가 있어도 이 JSON 의 값이 이긴다.** 옮긴 뒤 관리자 화면에 들어갈 수 있으면 화면에서 고치고, 들어갈 수 없으면(예: 세션·캐시 드라이버가 옛 Redis 를 가리킴) 파일을 직접 고치거나 `php artisan settings:set` 을 쓴다.

| 파일 | 살펴볼 키 | 메모 |
|------|-----------|------|
| `general.json` | `site_url`, `asset_url_mode` | 주소가 바뀌면 `site_url` 을 새 주소로 |
| `security.json` | `force_https`, `ip_restriction_enabled`, `admin_allowed_ips`, `ip_restriction_rules` | 새 서버가 아직 HTTPS 가 아니면 `force_https` 때문에 접속이 돌 수 있다. 관리자 IP 제한은 새 접속 IP 를 넣거나 잠시 끈다 |
| `mail.json` | 전부 (`mailer`, `host`, `port`, `username`, `password`, `encryption`, `mailgun_*`, `ses_*`, `from_address`, `from_name`) | 새 서버에서 보낼 수 있는 경로인지 확인 (발신 IP 허용, 포트 차단) |
| `drivers.json` | 저장소 `storage_driver` · `public_asset_disk` · `s3_*` / 캐시 `cache_driver` · `redis_*` · `memcached_*` / 세션 `session_driver` / 큐 `queue_driver` / 로그 `log_driver` / 웹소켓 `websocket_*` / 검색 `search_engine_driver` | 옛 서버에만 있던 Redis·Memcached 주소가 남아 있으면 부팅 직후부터 오류가 난다 |
| `debug.json` | `outbound_proxy`, `outbound_proxy_bypass` | 옛 망 전용 프록시 주소가 남아 있는지 |
| `geoip.json` | `license_key` | 값은 그대로 두되 DB 파일은 새 서버에서 `php artisan geoip:update` 로 다시 받는다 |
| `core_update.json` | `github_url`, `github_token` | 사설 저장소에서 받는 사이트 |
| `analytics.json` | `excluded_ips`, `ip_allowlist` | 서버·사무실 IP 가 바뀌었으면 |
| `seo.json` | `google_site_verification`, `naver_site_verification` | 도메인이 바뀌면 인증값을 다시 받는다 |

### env 우선 모드 (`G7_ENV_PRIORITY`)

컨테이너·여러 대 서버처럼 환경마다 값을 `.env` 로 주입하는 운영이라면 `.env` 에 `G7_ENV_PRIORITY=true` 를 둔다. 그러면 `.env` 에 값이 **있는** 항목은 설정 JSON 이 덮어쓰지 않는다(값이 없는 항목은 지금처럼 설정 JSON 을 따른다). 어떤 설정 키가 어떤 `.env` 키와 짝인지는 `App\Support\EnvPriority::MAP` 이 유일한 기준이며, 동작 설명은 [Admin 환경설정 값 접근 — env 우선 모드](admin-settings-access.md#env-우선-모드-g7_env_priority)에 있다.

---

## 4. 옮긴 뒤 실행할 명령

순서대로 실행한다. 코어 업데이트가 아니므로 `core:update` 는 필요 없다.

```bash
# 1) 권한 — 웹 서버 사용자가 쓸 수 있어야 한다 (사용자·그룹 이름은 서버에 맞게)
chown -R www-data:www-data storage bootstrap/cache modules plugins templates lang-packs
chmod -R ug+rwX storage bootstrap/cache

# 2) 공개 저장소 링크 (public/storage → storage/app/public)
php artisan storage:link

# 3) 확장 오토로드 다시 만들기 — 경로가 바뀌었으므로 반드시
php artisan extension:update-autoload

# 4) 캐시 — 옛 서버의 경로·값이 구워져 있을 수 있다
php artisan config:clear
php artisan config:cache
php artisan route:cache
php artisan hooks:cache

# 5) 확장 정적 파일 게시 상태 확인 (필요하면 다시 게시)
php artisan ext-static:status
php artisan ext-static:publish --force

# 6) 큐 워커 재시작 — 상주 워커는 부팅 때의 설정을 계속 쓴다
php artisan queue:restart
```

그리고 스케줄러를 새 서버 cron 에 등록한다(옛 서버 cron 은 끈다 — 두 곳에서 돌면 예약 작업이 두 번 실행된다).

```cron
* * * * * cd /사이트/경로 && php artisan schedule:run >> /dev/null 2>&1
```

> `bootstrap/cache/` 의 파일(설정·라우트·훅·확장 오토로드 캐시)을 옛 서버에서 통째로 복사해 왔다면 위 3)·4)를 건너뛰지 않는다. 그 안에는 옛 서버의 절대 경로가 들어 있다.
>
> 7.1.6 부터는 **없는** 캐시 파일을 스스로 다시 만든다 — 확장 오토로드 캐시는 환경과 무관하게 다음 요청이, 훅·설정·라우트 캐시는 운영 환경(`APP_ENV=production`, 디버그 꺼짐)의 웹 요청이 응답을 보낸 뒤에 만든다. 캐시 파일을 지우고 옮겨도 사이트가 멈추지 않게 하려는 안전장치이며, 위 명령을 대신하지는 않는다(낡은 캐시가 **있으면** 그대로 쓴다). 끄려면 `.env` 에 `G7_CACHE_SELF_HEAL=false`.

---

## 5. DB 엔진이 바뀔 때 (MariaDB → MySQL)

같은 엔진·같은 판끼리 옮길 때는 덤프를 그대로 넣으면 된다. **MariaDB 에서 MySQL 로**(또는 그 반대로) 옮길 때는 생성 열(generated column)에서 걸린다.

- **증상**: 덤프를 넣다가 `ERROR 3105 (HY000): The value specified for generated column '…' in table '…' is not allowed.` 로 멈춘다.
- **원인**: 생성 열은 다른 열의 값으로 계산되는 열이라 값을 직접 넣을 수 없다. MariaDB 의 덤프는 `INSERT` 문에 생성 열의 값을 함께 적는데, MySQL 은 그 `INSERT` 를 거절한다.
- **해결 절차**
  1. 덤프의 `CREATE TABLE` 에서 그 열의 정의(`GENERATED ALWAYS AS (…)`)와 그 열을 쓰는 인덱스(주로 고유 인덱스)를 **따로 적어 둔다.**
  2. 그 열을 일반 열로 바꿔 테이블을 만들고(`GENERATED ALWAYS AS (…) VIRTUAL|STORED` 부분을 떼고 자료형만 남긴다), 그 열을 쓰는 인덱스 정의는 잠시 뺀다.
  3. 데이터를 넣는다.
  4. 열을 다시 생성 열로 되돌리고 인덱스를 다시 만든다.

     ```sql
     ALTER TABLE `테이블` DROP COLUMN `생성열`;
     ALTER TABLE `테이블` ADD COLUMN `생성열` 자료형 GENERATED ALWAYS AS (원래 식) VIRTUAL;
     ALTER TABLE `테이블` ADD UNIQUE KEY `인덱스이름` (`…`, `생성열`);
     ```

  5. 고유 인덱스가 다시 만들어지지 않으면(중복 키 오류) 옮기는 동안 데이터가 어긋난 것이다 — 인덱스 없이 넘어가지 말고 중복 행부터 확인한다.
- 덤프를 뜰 때 생성 열을 빼고 뜰 수 있으면(도구가 지원하면) 그쪽이 간단하다.
- 문자셋·정렬 규칙 이름도 엔진마다 다를 수 있다(예: MariaDB 전용 정렬 규칙). `CREATE TABLE` 에서 거절되면 새 엔진이 지원하는 `utf8mb4` 정렬 규칙으로 바꾼다.

옮긴 뒤 `php artisan migrate:status` 로 마이그레이션 기록이 그대로 넘어왔는지 확인한다. 「대기 중」인 마이그레이션이 보이면 덤프가 온전하지 않은 것이다.

---

## 6. 옮긴 뒤 확인

- [ ] 사이트 첫 화면이 열린다 (설치 화면으로 가지 않는다)
- [ ] 관리자 로그인이 된다 — 안 되면 `APP_KEY`·세션 드라이버·`force_https`·관리자 IP 제한 순으로 본다
- [ ] 관리자 > 확장 목록에 옮기기 전과 같은 확장이 같은 상태로 보인다
- [ ] 관리자 > 환경설정 > 메일에서 시험 메일이 간다
- [ ] 예전에 올린 이미지·첨부가 보인다 (`/storage/…` 주소, S3 주소)
- [ ] 새 파일을 올릴 수 있다
- [ ] `storage/logs/` 에 권한 오류·접속 오류가 쌓이지 않는다
- [ ] 예약 작업이 돈다 (관리자 > 스케줄 실행 기록)
- [ ] 옛 서버의 cron·큐 워커를 껐다

---

## 7. 자주 겪는 문제

| 증상 | 볼 곳 |
|------|-------|
| `.env` 의 DB 정보를 고쳤는데 옛 DB 로 접속한다 | `storage/installer/runtime.php` 가 남아 있다 ([1절](#storageinstallerruntimephp-가-남아-있다면)) |
| `.env` 의 메일·Redis·사이트 주소를 고쳤는데 그대로다 | 설정 JSON 이 이긴다 ([3.2](#32-설정-json-storageappsettingsjson)) |
| 로그인하면 바로 풀리거나 관리자 설정의 비밀값이 비어 보인다 | `APP_KEY` 가 바뀌었다 ([2절](#2-app_key-를-지킨다)) |
| 옮기자마자 500 — 로그에 `Class "…" not found` | `bootstrap/cache/autoload-extensions.php` 가 없거나 옛 경로다. `php artisan extension:update-autoload` |
| 옮기자마자 500 — 로그에 옛 서버 경로가 보인다 | `bootstrap/cache/config.php`·`routes-v7.php` 를 복사해 왔다. `config:clear` → `config:cache` → `route:cache` |
| 계속 HTTPS 로 되돌아가 접속이 안 된다 | `security.json` 의 `force_https`, 프록시 뒤라면 `TRUSTED_PROXIES` |
| 관리자만 403 | `security.json` 의 관리자 IP 제한 |
| 이미지가 깨진다 | `php artisan storage:link`, `storage/app/public` 권한, `general.json` 의 `asset_url_mode` |
