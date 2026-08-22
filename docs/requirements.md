# 그누보드7 시스템 요구사항 (System Requirements)

> 그누보드7 설치 및 운영을 위한 서버/클라이언트 요구사항 문서

## TL;DR (5초 요약)

```text
1. PHP 8.2+ 필수
2. MySQL 8.0+ 또는 MariaDB 10.3+ (utf8mb4, utf8mb4_unicode_ci)
3. PHP 확장: 필수 16개 + 기능별 선택 17개 (설치 단계 자동 검사는 그중 14개)
4. 하드웨어: 최소 2 vCPU·2GB / 권장 4 vCPU·8GB, 디스크: 설치 검사 기준 500MB, 권장 2GB+
5. 프로덕션: HTTPS 필수(설치는 차단하지 않고 경고), Redis 권장, 큐 워커/스케줄러/Reverb 데몬 필요
```

---

## 1. 서버 요구사항

### 1.1 운영체제

| OS | 지원 수준 | 비고 |
|----|----------|------|
| Linux (Ubuntu 22.04+, CentOS/RHEL 8+) | 프로덕션 권장 | 가장 안정적 |
| Windows | 개발 환경 지원 | XAMPP/Laragon 등 (§1.3.1, [INSTALL.md](../INSTALL.md) 참조) |

### 1.2 웹서버

| 웹서버 | 버전 | 비고 |
|--------|------|------|
| Nginx | 1.18+ | 프로덕션 권장 |
| Apache | 2.4+ | `mod_rewrite` 활성화 필수 |

- Laravel은 `public/index.php`를 진입점으로 사용
- Apache 사용 시 `.htaccess` 파일이 URL 리라이팅 처리

### 1.3 PHP

| 항목 | 요구사항 |
|------|---------|
| 버전 | **8.2 이상** (`^8.2`) |
| SAPI | FPM (권장) 또는 mod_php (Apache + mod_fcgid 환경은 §1.3.1) |
| `disable_functions` | `exec`·`proc_open`·`shell_exec` 허용 필요 (설치·코어 업데이트가 자식 프로세스를 실행) |
| 웹 ↔ CLI 버전 | 메이저·마이너 버전 일치 권장 (설치 프로그램이 확인 후 불일치 시 안내) |
| OPcache | 활성화 권장 (성능 — 미활성화 시 설치는 진행되고 안내만 표시) |

#### 1.3.1 Apache + mod_fcgid 환경 추가 설정 (권장)

PHP 8.5 NTS(Windows) 등 mod_php DLL 이 제공되지 않는 빌드를 Apache 와 결합할 때 PHP 가 `php-cgi.exe` 기반 mod_fcgid 로 구동된다. 이 SAPI 의 default 출력 버퍼(`FcgidOutputBufferSize` 64KB) 가 인스톨러 SSE 스트림과 폴링 응답을 스크립트 종료 시점까지 보관하여 진행 상황이 화면에 실시간 반영되지 않는다.

Apache `fcgid.conf` 에 다음 1줄을 추가 후 Apache 재시작:

```apache
FcgidOutputBufferSize 0
```

미설정 시:
- 인스톨러 SSE 호환성 사전 체크가 buffered 환경으로 자동 판정되어 폴링 모드로 fallback
- 폴링 모드 응답은 코드 레벨 64KB padding 워크어라운드로 동작은 보장되나, SSE 모드가 아닌 1초 간격 폴링으로 진행 상황이 표시됨

### 1.4 PHP 확장 모듈

필수 16개 + 기능별 선택 17개.

| 구분 | 모듈 | 용도 |
|------|------|------|
| 필수 | `ctype` | 문자 타입 검사 |
| 필수 | `curl` | HTTP 클라이언트 |
| 필수 | `dom` | XML/HTML DOM 처리 (메일 본문 인라인 CSS 등) |
| 필수 | `fileinfo` | MIME 타입 감지 |
| 필수 | `filter` | 데이터 필터링/검증 |
| 필수 | `hash` | 해시 함수 |
| 필수 | `json` | JSON 인코딩/디코딩 |
| 필수 | `mbstring` | 멀티바이트 문자열 처리 |
| 필수 | `openssl` | 암호화/복호화 (AES-256-CBC) |
| 필수 | `pcre` | 정규 표현식 |
| 필수 | `pdo` | 데이터베이스 추상화 |
| 필수 | `pdo_mysql` | MySQL/MariaDB PDO 드라이버 |
| 필수 | `session` | 세션 관리 |
| 필수 | `tokenizer` | PHP 토큰 파싱 |
| 필수 | `xml` | XML 파서 |
| 필수 | `zip` | 확장·언어팩·코어 업데이트 패키지의 압축 해제 |
| 선택 | `gd` | 이미지 처리 (썸네일, 리사이징) — `imagick` 과 택일 |
| 선택 | `imagick` | 고급 이미지 처리 (ImageMagick) — `gd` 와 택일 |
| 선택 | `exif` | 이미지 메타데이터 읽기 |
| 선택 | `intl` | 국제화 (다국어 날짜/숫자 포맷) |
| 선택 | `bcmath` | 정밀 수학 연산 (금액 계산 등) |
| 선택 | `redis` | Redis 캐시/세션/큐 드라이버 (`predis` 동봉 — 확장 없이도 사용 가능) |
| 선택 | `memcached` | Memcached 캐시 드라이버 |
| 선택 | `pcntl` | 큐 워커 전체 기능 및 콘솔 시그널 처리 |
| 선택 | `posix` | 큐 워커 전체 기능 및 코어 업데이트의 파일 소유자 확인 |
| 선택 | `maxminddb` | GeoIP 데이터베이스 조회 (접속 국가·타임존 감지) |
| 선택 | `simplexml` | AWS SDK(S3/SES/SQS) 응답 파싱 |
| 선택 | `libxml` | XML 파싱 기반 라이브러리 (`dom`·`simplexml` 의 토대) |
| 선택 | `sodium` | 최신 암호화 라이브러리 |
| 선택 | `phar` | Phar 아카이브 (Composer 실행) |
| 선택 | `xmlwriter` | XML 문서 생성 |
| 선택 | `zlib` | 데이터 압축 (gzip 응답 압축) |
| 선택 | `ldap` | LDAP 연동 확장을 사용할 때 (코어 기본 기능은 사용하지 않음) |

설치 프로그램은 위 필수 확장 중 14개(`pdo`, `mbstring`, `openssl`, `tokenizer`, `xml`,
`ctype`, `json`, `fileinfo`, `curl`, `dom`, `filter`, `hash`, `pcre`, `session`)를 설치
단계에서 자동 검사하고 하나라도 없으면 진행을 막는다. `pdo_mysql` 과 `zip` 은 자동 검사
대상이 아니지만 각각 데이터베이스 접속과 확장·언어팩 설치에 반드시 필요하므로 필수로
분류한다. 선택 확장은 해당 기능을 사용하는 시점에 필요하며, 자동 검사 항목과 운영자가
직접 확인해야 하는 항목의 전체 구분은 §9 를 참조한다.

### 1.5 PHP 설정 권장값 (php.ini)

| 설정 | 최소값 | 권장값 | 비고 |
|------|--------|--------|------|
| `memory_limit` | 128M | 256M+ | 이미지 처리 시 높은 메모리 필요 |
| `upload_max_filesize` | 10M | 20M+ | 첨부파일 업로드 크기 |
| `post_max_size` | 12M | 25M+ | `upload_max_filesize`보다 커야 함 |
| `max_execution_time` | 60 | 120+ | 대량 데이터 처리 시 |
| `max_input_vars` | 1000 | 5000+ | 복잡한 폼 데이터 처리 |

### 1.6 파일 권한 및 umask 운영 방식

G7 은 배포 환경에 따라 세 가지 대표 운영 방식을 지원한다. 본 섹션은 각 방식에서 `storage/` 등 런타임 쓰기 대상 디렉토리의 권한 설정과 umask 권장값을 정리한다.

#### 운영 방식 분류

| 운영 방식 | 소유자 : 그룹 | 권장 퍼미션 | 전형적 환경 |
|-----------|--------------|-------------|-------------|
| **A. 그룹 공유** | `사용자 : www-data` (서로 다른 UID) | `drwxrwxr-x` (0775) + g+w | SSH 로그인 사용자와 php-fpm 프로세스가 UID 가 다르고 `www-data` 같은 공용 그룹으로 파일 쓰기 권한을 공유하는 일반적인 Ubuntu/Debian 구성 |
| **B. 단일 소유자** | `사용자 : 사용자` 또는 `www-data : www-data` (동일 UID) | `drwxr-xr-x` (0755) | suexec / mod_userdir / 단순 Apache 환경에서 파일 소유자·웹서버 프로세스가 같은 UID |
| **C. suexec / cPanel** | 계정별 UID 격리 | `drwxr-xr-x` (0755) | 공유 호스팅, 계정마다 독립 UID/GID |

`storage/` 디렉토리의 실제 퍼미션을 확인:

```bash
stat -c '%a %U:%G' storage
```

#### 권장 설정

**방식 A (그룹 공유)**:

```bash
# 인스톨러 완료 후 운영자가 1회 실행
sudo chown -R $USER:www-data storage bootstrap/cache vendor modules plugins templates
sudo chmod -R 775 storage bootstrap/cache vendor modules plugins templates
```

추가로 php-fpm / systemd 의 umask 를 `002` 로 설정하면 cron·composer·수동 SSH artisan 등 외부 프로세스도 동일 권한으로 파일을 만든다.

| 설정 지점 | 값 | 위치 예시 |
|-----------|----|-----------|
| php-fpm pool | `umask = 002` | `/etc/php/8.x/fpm/pool.d/www.conf` |
| systemd unit | `UMask=0002` | `/lib/systemd/system/php8.x-fpm.service` `[Service]` 섹션 |

시스템 레벨 설정이 없어도 코어 부팅 시 `storage/` 의 g+w 여부를 감지하여 프로세스 umask 를 자동으로 `0002` 로 동조하므로 Laravel 부팅 경로를 거치는 파일 생성은 정상 동작한다 (`public/index.php`, `artisan`, queue worker, scheduler 등). 시스템 레벨 설정은 **부팅 경로를 거치지 않는 외부 프로세스 대응용 권장 사항**.

**방식 B/C (단일 소유자)**:

```bash
sudo chmod -R 755 storage bootstrap/cache vendor modules plugins templates
```

그룹 쓰기 비트가 없으므로 코어 자동 umask 동조는 발동하지 않는다 (운영자 의도 존중). 추가 설정 불필요.

#### 인스톨러가 안내하는 기본 권한

인스톨러의 기본 안내 명령은 보수적으로 `chmod -R 755` 를 제시한다. 방식 A 로 운영하려면 인스톨 완료 후 `775` 로 재조정 + 소유자/그룹을 본인 계정 + `www-data` 로 변경. 인스톨러는 `chmod` 를 직접 호출하지 않으므로 운영자가 쉘에서 1회 실행.

### 1.7 개발/빌드 도구

| 도구 | 버전 | 필요한 경우 |
|------|------|------------|
| Node.js | 20 이상 | 프론트엔드 소스를 직접 빌드할 때 |
| Composer | 2.x | Composer 로 의존성을 설치할 때, 확장이 외부 패키지를 요구할 때 |

배포본에는 빌드 산출물과 `vendor` 디렉토리가 동봉되어 있으므로 **일반적인 운영 설치에는
두 도구 모두 필요하지 않다.** 소스에서 직접 빌드하거나 개발 환경을 구성할 때, 또는
Composer 설치 방식을 선택할 때만 필요하다.

---

## 2. 데이터베이스

### 2.1 지원 DBMS

| DBMS | 최소 버전 | 비고 |
|------|----------|------|
| MySQL | **8.0 이상** | 프로덕션 권장 |
| MariaDB | **10.3 이상** | MySQL 호환 대안 |

### 2.2 설정 요구사항

| 항목 | 값 | 비고 |
|------|-----|------|
| charset | `utf8mb4` | 이모지 등 4바이트 문자 지원 |
| collation | `utf8mb4_unicode_ci` | 기본값이며 `.env`의 `DB_COLLATION`으로 변경 가능 |
| 테이블 접두어 | `g7_` (기본값) | `.env`에서 `DB_PREFIX`로 변경 가능 (최대 6자 — 자동 생성 인덱스명이 MySQL 식별자 한도 64자를 넘지 않도록 제한) |

**선택 기능**:
- Write/Read 분리: Master-Replica 구성 지원 (`DB_WRITE_*` / `DB_READ_*` 환경 변수)

---

## 3. 하드웨어 사양

### 3.1 CPU · 메모리

| 수준 | CPU | 메모리 | 상정 환경 |
|------|-----|--------|----------|
| 최소 | 2 vCPU | 2GB | 설치·기능 확인용. 동시 접속이 거의 없는 개발/검토 환경 |
| 권장 | 4 vCPU | 8GB | 웹서버 + PHP-FPM + MySQL + Redis 를 한 대에 올린 소규모 운영 |
| 분리 구성 | 웹 2 vCPU / DB 2 vCPU | 각 4GB 이상 | 트래픽이 늘면 DB 를 먼저 분리 |

메모리 배분은 어떤 구성 요소를 같은 서버에 올렸는지에 따라 달라진다. 한 대에 모두 올린
경우 MySQL 의 `innodb_buffer_pool_size` 가 전체 메모리의 절반을 넘지 않도록 두고, PHP-FPM
자식 프로세스 수 × `memory_limit` 가 남은 메모리를 넘지 않는지 확인한다.

위 수치는 코어와 번들 확장을 기본 설정으로 운영할 때의 출발점이다. 실제 필요량은 데이터
규모·동시 접속·설치한 확장에 따라 달라지므로, 운영 전 대상 트래픽으로 직접 측정할 것을
권한다(`php artisan g7:bench` 로 목록·화면·쓰기·배치 4축을 잰다).

**이 수치의 근거**

- **권장 4 vCPU · 8GB** — 사용자가 4 vCPU · 8GB 가상머신에서 수행한 운영 환경 측정 보고
  (`gnuboard/g7#82`)에서 코어와 번들 확장을 함께 올린 구성이 동작한 사양이다. 같은 보고에서
  대량 데이터의 검색·목록 조회는 이 사양에서도 메모리 압박이 관측되었으므로, "이 사양이면
  어떤 규모든 충분하다" 는 뜻이 아니라 **한 대 구성의 하한선**으로 읽어야 한다.
- **최소 2 vCPU · 2GB** — 설치와 기능 확인이 가능한 수준으로, 위 보고의 측정 대상이 아니다.
  동시 접속이 있는 운영에는 적합하지 않다.
- **분리 구성** — 위 보고에서 부하가 먼저 걸린 지점이 데이터베이스였다는 관측에 따른 순서
  제안이다. 분리 시점의 트래픽 임계값은 측정하지 않았다.

우리가 직접 측정한 범위는 쿼리 실행 횟수와 조회 구조이며, 특정 사양에서의 동시 접속 한계나
응답 시간은 측정하지 않았다. 그 값이 필요하면 대상 환경에서 직접 재야 한다.

### 3.2 디스크 용량

| 수준 | 용량 | 포함 범위 |
|------|------|----------|
| 최소 | **500MB** (설치 프로그램 검사 기준) | 설치가 진행되기 위한 여유 공간의 하한. 코어 + 기본 확장의 실사용은 약 700MB 이므로 여유 확보 권장 |
| 권장 | **2GB 이상** | 코어 + 확장 + 첨부파일 + 캐시 + 로그 |

- 사용자 업로드 파일, 로그, 캐시 등은 별도 용량 산정 필요
- `storage/` 디렉토리에 쓰기 권한 필수

---

## 4. 선택적 서비스 (프로덕션 권장)

### 4.1 Redis

| 항목 | 요구사항 | 비고 |
|------|---------|------|
| Redis | 6.0 이상 | 캐시, 세션, 큐 드라이버로 사용 가능 |

- 프로덕션 환경에서 캐시/세션/큐 성능 향상을 위해 권장
- PHP `redis` 확장 필요 (`phpredis`)

### 4.2 클라우드 서비스 (AWS)

| 서비스 | 용도 | 필수 여부 |
|--------|------|----------|
| AWS S3 | 파일 스토리지 (클라우드) | 선택 |
| AWS SES | 이메일 발송 | 선택 |
| AWS SQS | 큐 처리 (대규모 트래픽) | 선택 |

- `aws/aws-sdk-php` · `league/flysystem-aws-s3-v3` 패키지 포함됨 (S3 및 S3 호환 스토리지 — Cloudflare R2, MinIO 등 — 를 별도 설치 없이 사용 가능)
- `predis/predis` 패키지 포함됨 (phpredis PHP 확장이 없는 서버에서도 Redis 드라이버 사용 가능)

### 4.3 메일 서비스

| 서비스 | 비고 |
|--------|------|
| Mailgun | `symfony/mailgun-mailer` 패키지 포함됨 |
| AWS SES | 위 AWS 서비스 참조 |
| SMTP | 자체 SMTP 서버 사용 가능 |

- 이메일 발송이 필요 없는 경우 `MAIL_MAILER=log`로 설정

### 4.4 WebSocket (Laravel Reverb)

| 항목 | 요구사항 | 비고 |
|------|---------|------|
| Laravel Reverb | 포함됨 (`laravel/reverb ^1.6`) | 자체 호스팅 WebSocket 서버 |
| 기본 포트 | 8080 | `REVERB_PORT`로 변경 가능 |

- 실시간 알림, 브로드캐스팅 기능 사용 시 필요
- 대안: Pusher 서비스 (`pusher-js` 클라이언트 포함됨)

---

## 5. 보안

### 5.1 SSL/TLS

| 환경 | 요구사항 |
|------|---------|
| 프로덕션 | **HTTPS 필수** |

- 설치 단계에서는 HTTPS 여부를 경고로 안내하며 설치를 차단하지 않는다. 정책상 필수라는
  선언은 유지하되, 자동으로 강제되지는 않으므로 운영자가 직접 확인해야 한다
- Laravel Reverb WebSocket도 `wss://` 프로토콜 사용 (`REVERB_SCHEME=https`)
- Sanctum 세션 인증 시 `SESSION_SECURE_COOKIE=true` 설정 권장

---

## 6. 프로덕션 데몬 프로세스

프로덕션 환경에서 상시 실행해야 하는 프로세스:

| 프로세스 | 명령어 | 관리 도구 |
|---------|--------|----------|
| 큐 워커 | `php artisan queue:work` | Supervisor 등 |
| 스케줄러 | `php artisan schedule:run` | cron (매분 실행) |
| WebSocket | `php artisan reverb:start` | Supervisor 등 |

```bash
# cron 예시 (스케줄러)
* * * * * cd /path/to/g7 && php artisan schedule:run >> /dev/null 2>&1
```

---

## 7. 지원 브라우저

| 브라우저 | 최소 지원 버전 | 검증 방식 |
|---------|--------------|----------|
| Chrome / Edge | **111** 이상 | 자동 브라우저 테스트(Chromium)로 상시 검증 |
| Safari (macOS / iOS) | **16.4** 이상 | 호환 범위 기준 지원 (상시 자동 테스트 대상 아님, 실기기 검증 미수행) |
| Firefox | **128** 이상 | 호환 범위 기준 지원 (상시 자동 테스트 대상 아님) |

- 지원 하한은 프론트엔드 핵심 의존성(React 19, Tailwind CSS 4)의 공식 지원 브라우저
  범위를 따른다. 위 세 버전은 2026-08 현재 Tailwind CSS 4 의 공식 하한이며(`@property`
  등 CSS 기능이 결정한다), 해당 의존성 버전이 변경되면 이 기준도 함께 재검토한다
- **하한 미만 브라우저에서도 화면 표시와 기본 이용은 가능하도록 유지한다.** 최신 CSS 기능을
  해석하지 못해 색상·간격 등 스타일이 일부 깨질 수 있으나, 그것이 이용 불가로 이어지지
  않도록 한다. 이를 위해 배포되는 JavaScript 산출물에는 하한을 넘는 문법·API 를 넣지 않으며,
  특히 부팅에 필요한 번들에는 다운레벨이 불가능한 정규식 리터럴 문법(lookbehind, `v` 플래그)
  을 하한과 같은 버전이라도 사용하지 않는다 — 이 번들이 파싱되지 않으면 사이트가 통째로
  뜨지 않기 때문이다
- 브라우저가 화면 구성 스크립트를 끝내 실행하지 못하는 경우에는 백지 대신 그 사유를 밝히는
  안내 화면을 표시한다
- 자동 테스트는 테스트 도구에 포함된 단일 브라우저 빌드로 수행하므로 특정 버전
  목록(예: "최신 N개 버전")을 상시 보장하지 않는다
- Internet Explorer 미지원

---

## 8. 호스팅 환경별 제한사항

### 8.1 공유 호스팅 (Shared Hosting)

> 공유 호스팅에서도 그누보드7 설치는 가능하지만, 호스팅 업체에 따라 아래 기능이 제한될 수 있습니다.

**제한될 수 있는 기능**:

| 제한 항목 | 영향받는 기능 | 대안 |
| -------- | ----------- | ---- |
| 데몬 프로세스 (Supervisor) | 큐 워커 상시 실행 불가 | `QUEUE_CONNECTION=sync` (동기 처리) |
| cron 최소 간격 | 스케줄러 분 단위 실행 제한 | 호스팅 cPanel cron (지원 간격 확인) |
| PHP 확장 제한 (`pcntl`, `posix`, `redis`, `imagick` 등) | 큐 워커, 프로세스 관리, Redis 캐시, 이미지 처리 | 파일/DB 캐시, GD 라이브러리 |
| PHP 설정 변경 (`memory_limit`, `max_execution_time` 등) | 대용량 파일 업로드, 이미지 처리 | 호스팅 관리자에게 변경 요청 |
| 커스텀 포트 (80/443 외) | Reverb WebSocket (기본 8080 포트) | Pusher 등 외부 WebSocket 서비스 |
| 파일 권한 (`symlink` 등) | `storage/` 심볼릭 링크 | `php artisan storage:link` 대체 방식 확인 |
| 디스크 용량 | 첨부파일, 로그, 캐시 누적 | 플랜별 용량 확인, 정기 정리 |

---

## 9. 요구사항 검증 수준

이 문서의 항목은 자동으로 확인되는 것과 운영자가 직접 확인해야 하는 것으로 나뉜다.
"요구사항" 이라는 표현이 곧 "설치 프로그램이 검사한다" 를 뜻하지는 않는다.

### 9.1 설치 프로그램이 자동 검사

| 항목 | 기준 | 미충족 시 |
|------|------|----------|
| PHP 버전 | 8.2 이상 | 설치 차단 |
| PHP 확장 | §1.4 필수 확장 중 14개 | 설치 차단 |
| 차단 함수 | `exec`·`proc_open`·`shell_exec` 사용 가능 | 설치 차단 |
| 디스크 여유 공간 | 500MB | 설치 차단 |
| 디렉토리 쓰기 권한 | `storage/`, `bootstrap/cache`, `vendor`, 확장 디렉토리 등 | 설치 차단 |
| `.env` 파일 | 존재 + 쓰기 가능 | 설치 차단 |
| 데이터베이스 접속 | 접속 성공 여부 | 다음 단계 진행 불가 |
| DB 테이블 접두어 | 최대 6자 | 입력 거부 |
| 웹 ↔ CLI PHP 버전 | 메이저·마이너 일치 | 안내만 (설치 진행) |
| OPcache | 활성화 여부 | 안내만 (설치 진행) |
| HTTPS | 사용 여부 | 안내만 (설치 진행) |

`gd`·`imagick`·`redis`·`intl`·`zlib` 은 설치 단계에서 상태를 표시하지만 설치를 막지 않는다.

### 9.2 운영자 확인 필요 — 자동 검사 없음

| 항목 | 문서상의 기준 | 확인 방법 |
|------|--------------|----------|
| 데이터베이스 버전 | MySQL 8.0+ / MariaDB 10.3+ | `SELECT VERSION()` |
| DB charset·collation | `utf8mb4` / `utf8mb4_unicode_ci` | 접속 후 스키마 확인 |
| php.ini 값 | §1.5 의 5개 항목 | `php -i` 또는 관리자 정보 화면 |
| CPU·메모리 | §3.1 | 서버 사양 확인 |
| 웹서버 종류·버전 | Nginx 1.18+ / Apache 2.4+ | 서버 설정 확인 |
| `mod_rewrite` | Apache 사용 시 활성화 | 서버 설정 확인 |
| Redis 버전 | 6.0 이상 | `redis-server --version` |
| Node.js·Composer 버전 | §1.7 | 소스 빌드·Composer 설치를 할 때만 해당 |
| 지원 브라우저 | §7 | 자동 브라우저 테스트는 Chromium 만 상시 수행 |
| 데몬 프로세스 가동 | §6 의 3종 | 프로세스 관리 도구에서 확인 |
| §1.4 의 선택 확장 | 사용하는 기능에 따라 | 관리자 정보 화면의 확장 목록 |
