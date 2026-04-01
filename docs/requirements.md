# 그누보드7 시스템 요구사항 (System Requirements)

> 그누보드7 설치 및 운영을 위한 서버/클라이언트 요구사항 문서

## TL;DR (5초 요약)

```text
1. PHP 8.2+ 필수
2. MySQL 8.0+ 또는 MariaDB 10.3+ (utf8mb4, utf8mb4_unicode_ci)
3. PHP 필수 모듈 30개 (ctype, curl, gd, intl, redis, imagick 등)
4. 디스크 용량: 최소 700MB / 권장 2GB+
5. 프로덕션: HTTPS 필수, Redis 권장, 큐 워커/스케줄러/Reverb 데몬 필요
```

---

## 1. 서버 요구사항

### 1.1 운영체제

| OS | 지원 수준 | 비고 |
|----|----------|------|
| Linux (Ubuntu 22.04+, CentOS/RHEL 8+) | 프로덕션 권장 | 가장 안정적 |

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
| SAPI | FPM (권장) 또는 mod_php |

### 1.4 PHP 필수 확장 모듈

| 모듈 | 용도 |
|------|------|
| `bcmath` | 정밀 수학 연산 (가격 계산 등) |
| `ctype` | 문자 타입 검사 |
| `curl` | HTTP 클라이언트 |
| `dom` | XML/HTML DOM 처리 |
| `exif` | 이미지 메타데이터 읽기 |
| `fileinfo` | MIME 타입 감지 |
| `filter` | 데이터 필터링/검증 |
| `gd` | 이미지 처리 (썸네일, 리사이징) |
| `hash` | 해시 함수 |
| `imagick` | 고급 이미지 처리 (ImageMagick) |
| `intl` | 국제화 (다국어, 날짜/숫자 포맷) |
| `json` | JSON 인코딩/디코딩 |
| `ldap` | LDAP 인증 연동 |
| `libxml` | XML 파싱 기반 라이브러리 |
| `maxminddb` | GeoIP 데이터베이스 조회 |
| `mbstring` | 멀티바이트 문자열 처리 |
| `memcached` | Memcached 캐시 드라이버 |
| `openssl` | 암호화/복호화 (AES-256-CBC) |
| `pcntl` | 프로세스 제어 (큐 워커) |
| `pcre` | 정규 표현식 |
| `pdo` | 데이터베이스 추상화 |
| `pdo_mysql` | MySQL/MariaDB PDO 드라이버 |
| `phar` | Phar 아카이브 (Composer) |
| `posix` | POSIX 함수 (프로세스 관리) |
| `redis` | Redis 캐시/세션/큐 드라이버 |
| `session` | 세션 관리 |
| `simplexml` | 간편 XML 파싱 |
| `sodium` | 최신 암호화 라이브러리 |
| `tokenizer` | PHP 토큰 파싱 |
| `xml` | XML 파서 |
| `xmlwriter` | XML 문서 생성 |
| `zip` | ZIP 압축/해제 |
| `zlib` | 데이터 압축 |

### 1.5 PHP 설정 권장값 (php.ini)

| 설정 | 최소값 | 권장값 | 비고 |
|------|--------|--------|------|
| `memory_limit` | 128M | 256M+ | 이미지 처리 시 높은 메모리 필요 |
| `upload_max_filesize` | 10M | 20M+ | 첨부파일 업로드 크기 |
| `post_max_size` | 12M | 25M+ | `upload_max_filesize`보다 커야 함 |
| `max_execution_time` | 60 | 120+ | 대량 데이터 처리 시 |
| `max_input_vars` | 1000 | 5000+ | 복잡한 폼 데이터 처리 |

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
| collation | `utf8mb4_unicode_ci` | 유니코드 정렬 |
| 테이블 접두어 | `g7_` (기본값) | `.env`에서 `DB_PREFIX`로 변경 가능 |

**선택 기능**:
- Write/Read 분리: Master-Replica 구성 지원 (`DB_WRITE_*` / `DB_READ_*` 환경 변수)

---

## 3. 디스크 용량

| 수준 | 용량 | 포함 범위 |
|------|------|----------|
| 최소 | **700MB** | 코어 + 기본 확장 |
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

- `aws/aws-sdk-php` 패키지 포함됨

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

| 브라우저 | 지원 범위 |
|---------|----------|
| Chrome / Edge | 최신 2개 버전 |
| Firefox | 최신 2개 버전 |
| Safari | 최신 2개 버전 |

- React 19 + Tailwind CSS 4 호환 범위 기준
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
