# 그누보드7 설치 가이드

그누보드7(G7)을 설치하는 방법을 안내합니다.

---

## 시스템 요구사항

| 항목 | 요구사항 |
|------|---------|
| **PHP** | 8.2 이상 (필수 확장 30개 포함) |
| **데이터베이스** | MySQL 8.0+ 또는 MariaDB 10.3+ (utf8mb4) |
| **Composer** | 2.x |
| **Redis** | 6.0+ (프로덕션 권장, 선택) |

> 상세 요구사항은 [docs/requirements.md](docs/requirements.md)를 참조하세요.

---

## 방법 1: 웹 서버에서 바로 구동

Apache, Nginx 등 웹 서버가 이미 구동 중인 환경에서 설치합니다.

### 1단계: 소스 코드 다운로드

웹 서버의 루트 디렉토리(또는 원하는 위치)에서 실행합니다.

```bash
git clone https://github.com/gnuboard/g7.git
```

### 2단계: 웹 서버 설정

웹 서버의 DocumentRoot(또는 Virtual Host)를 `g7/public` 디렉토리로 설정합니다.

**Apache 예시** (Virtual Host):

```apache
<VirtualHost *:80>
    ServerName example.com
    DocumentRoot /var/www/g7/public

    <Directory /var/www/g7/public>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

**Apache + mod_fcgid 환경 추가 설정** (PHP 8.5 NTS Windows 등 mod_php 미제공 빌드):

`fcgid.conf` 에 다음 1줄을 추가 후 Apache 재시작. 미설정 시 mod_fcgid 의 default 64KB 출력 버퍼가 인스톨러 SSE 스트림과 폴링 응답을 스크립트 종료 시점까지 보관하여 설치 진행 상황이 화면에 실시간 반영되지 않는다.

```apache
FcgidOutputBufferSize 0
```

**Nginx 예시**:

```nginx
server {
    listen 80;
    server_name example.com;
    root /var/www/g7/public;

    index index.php;

    location / {
        try_files $uri $uri/ /index.php?$query_string;
    }

    location ~ \.php$ {
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $realpath_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

### 3단계: 설치 마법사 실행

브라우저에서 접속합니다.

```
http://도메인/install
```

설치 마법사의 안내에 따라 DB 정보 입력 및 관리자 계정을 생성합니다.

---

## 방법 2: 로컬 개발 서버 (PHP 내장 서버)

로컬 환경에서 개발/테스트 목적으로 빠르게 구동합니다.

### 1단계: 소스 코드 다운로드

```bash
git clone https://github.com/gnuboard/g7.git
```

### 2단계: 프로젝트 디렉토리로 이동

```bash
cd g7
```

### 3단계: Composer 의존성 설치

```bash
composer install
```

### 4단계: 환경 설정 파일 생성

```bash
cp .env.example .env
```

### 5단계: 개발 서버 실행

```bash
php artisan serve
```

### 6단계: 설치 마법사 실행

브라우저에서 접속합니다.

```
http://localhost:8000/install
```

설치 마법사의 안내에 따라 DB 정보 입력 및 관리자 계정을 생성합니다.

---

## 방법 3: ZIP 파일 다운로드

Git이 설치되지 않은 환경에서 설치합니다.

### 1단계: GitHub 접속

브라우저에서 아래 주소로 접속합니다.

```
https://github.com/gnuboard/g7
```

### 2단계: 릴리스 다운로드

1. 페이지 우측의 **Releases** 섹션을 클릭합니다.
2. 최신 릴리스를 선택합니다.
3. 하단의 **Source code (zip)** 을 다운로드합니다.

### 3단계: 압축 해제

다운로드한 ZIP 파일을 원하는 위치에 압축 해제합니다.

### 4단계: Composer 의존성 설치

터미널에서 압축 해제된 디렉토리로 이동한 후 실행합니다.

```bash
cd g7-버전명
composer install
```

### 5단계: 환경 설정 파일 생성

```bash
cp .env.example .env
```

### 6단계: 설치 진행

환경에 따라 선택합니다.

**웹 서버가 있는 경우:**

- DocumentRoot를 `public` 디렉토리로 설정한 후 브라우저에서 `http://도메인/install` 접속

**로컬에서 구동하는 경우:**

```bash
php artisan serve
```

브라우저에서 `http://localhost:8000/install` 접속

설치 마법사의 안내에 따라 DB 정보 입력 및 관리자 계정을 생성합니다.

---

## 방법 4: 공유 호스팅

Composer 실행이 불가능한 공유 호스팅 환경에서의 설치 방법입니다. SSH + PHP CLI 사용이 가능한 요금제를 전제로 하며, [Vendor 번들 시스템](docs/extension/vendor-bundle.md)을 통해 Composer 없이 의존성을 배치합니다.

### Cafe24

#### 검증 환경

본 가이드는 Cafe24 [**뉴아우토반 호스팅 절약형**](https://hosting.cafe24.com/?controller=new_product_page&page=newautobahn) 요금제에서 검증되었습니다. 동일 계열(일반형/비즈니스형 등) 요금제는 더 넉넉한 리소스를 제공하므로 그대로 적용 가능합니다.

| 항목 | 절약형 사양 |
|------|-------------|
| 월 요금 | 500원 (1년 약정 시 450원) |
| 웹 용량 | 700MB (FullSSD) |
| 트래픽 | 1.6GB/일 |
| DB | MariaDB 10.x (InnoDB), 서버 공간 내 무제한 |
| PHP | 8.4 / 8.2 / 7.4 (Rocky OS 기준) |
| 접속 | FTP / SFTP / SSH 지원 |

> 웹 용량 700MB는 G7 코어 + 기본 확장 설치에 충분하지만, 업로드 파일이 많아질 경우 **일반형(1.4GB) 이상**을 권장합니다.

#### 사전 준비

- SSH 접속이 허용된 Cafe24 호스팅 계정 (절약형 이상)
- SFTP 클라이언트 (FileZilla, WinSCP, Cyberduck 등)
- Cafe24 관리자 페이지에서 PHP 버전을 **8.2 또는 8.4**로 설정
- Cafe24 관리자 페이지에서 생성한 MariaDB DB (utf8mb4)

#### 1단계: GitHub Release에서 배포 패키지 다운로드

[https://github.com/gnuboard/g7/releases](https://github.com/gnuboard/g7/releases) 접속하여 최신 릴리스의 **Assets** 섹션에서 배포 패키지를 다운로드합니다.

#### 2단계: SFTP로 ZIP 파일 업로드

다운로드한 ZIP 파일을 SFTP로 홈 디렉토리 바로 아래에 업로드합니다.

```text
~/                  ← Cafe24 계정의 홈 디렉토리
├── www             ← 기존 심볼릭 링크 또는 디렉토리 (4단계에서 갱신)
└── g7-release.zip  ← 업로드한 ZIP 파일
```

#### 3단계: SSH 접속 후 압축 해제 및 심볼릭 링크 갱신

SSH 접속 후 홈 디렉토리에서 ZIP 압축 해제와 웹 루트 심볼릭 링크 갱신을 함께 진행합니다.

```bash
cd ~

# 업로드한 ZIP 압축 해제 (파일명은 실제 다운로드한 이름으로 교체)
unzip g7-release.zip

# 압축 해제 결과 확인 — 루트 디렉토리가 g7이 아니면 이름 변경
ls -la
# (필요 시) mv g7-7.0.0-beta.7 g7

# ZIP 파일 정리 (선택)
rm g7-release.zip
```

압축 해제 후 디렉토리 구조는 다음과 같아야 합니다.

```text
~/
├── www             ← 아래에서 갱신할 심볼릭 링크
└── g7/
    ├── app/
    ├── bootstrap/
    ├── public/
    ├── storage/
    ├── vendor-bundle.zip
    ├── .env.example
    └── ...
```

Cafe24의 웹 루트는 홈 디렉토리 아래 `www`(심볼릭 링크 또는 디렉토리)이며, 이를 `g7/public/`으로 연결해야 합니다.

```bash
# 기존 www 상태 확인
ls -la www

# 기존 www 삭제 후 재생성
rm www
ln -s g7/public www

# 결과 확인 (아래와 같은 형태여야 합니다)
ls -la www
# lrwxrwxrwx 1 user user 9 ... www -> g7/public/
```

기존 `www` 안에 운영 중인 사이트가 있다면, 먼저 SFTP로 백업 다운로드하거나 `mv www www.bak`으로 이름 변경한 뒤 새 심볼릭 링크를 생성하세요.

> 본 가이드의 모든 쉘 명령은 `cd ~` 또는 `cd ~/g7` 컨텍스트 진입 후 `./` 상대경로로 실행하므로, Cafe24 계정의 홈 디렉토리 절대경로를 몰라도 됩니다.

#### 4단계: 웹 인스톨러 실행

브라우저에서 도메인으로 접속합니다.

```text
http://도메인/install
```

설치 마법사의 각 단계를 진행합니다.

| Step | 작업 |
|------|------|
| 0. 환영 | 언어 선택 및 `storage` 권한 검증 — 권한 부족 시 인스톨러가 상대경로 기반 명령을 자동 안내 |
| 1. 라이선스 | 동의 |
| 2. 요구사항 | PHP 8.2+ 및 필수 확장 확인 |
| 3. 환경 설정 | DB 정보 + 관리자 계정 + **Vendor 설치 방식** 선택 |
| 4. 확장 선택 | 템플릿/모듈/플러그인 선택 (의존성 자동 해결) |
| 5. 설치 실행 | "설치 시작" 버튼 클릭 후 진행 상황 모니터링 |

`.env` 파일 생성과 `storage` / `bootstrap/cache` 쓰기 권한 부여는 인스톨러가 환경(소유자 일치 여부 등)에 맞춰 Step 0 및 이후 단계에서 자동 안내하므로, 사전 SSH 작업으로 수행할 필요 없습니다.

**Step 3 — Vendor 설치 방식 선택:**

- **자동 (권장)**: 환경을 자동 감지하여 번들 모드로 폴백합니다.
- **번들 Vendor 사용**: `vendor-bundle.zip`을 명시적으로 선택합니다 (Cafe24 권장).

#### Cafe24 환경 체크리스트

설치 중 문제가 발생하면 아래 항목을 확인합니다.

- [ ] `php -v` 출력이 8.2 이상인가? (Cafe24 관리자에서 PHP 버전 변경 가능)
- [ ] `php -m` 출력에 `pdo_mysql`, `mbstring`, `openssl`, `zip`이 포함되어 있는가?
- [ ] `~/www`가 `g7/public/`를 올바르게 가리키는가? (`ls -la ~/www` 확인)
- [ ] `vendor-bundle.zip`이 `~/g7/` 디렉토리에 존재하는가?
- [ ] 웹 인스톨러 Step 3에서 "Vendor 설치 방식"을 **번들 Vendor 사용** 또는 **자동**으로 선택했는가?
- [ ] DB 접속 정보(호스트/포트/계정)가 Cafe24 관리자 정보와 일치하는가?

---

## 설치 후 확인

설치가 완료되면 아래 페이지에 접근할 수 있습니다.

| 페이지 | URL | 비고 |
|--------|-----|------|
| **관리자 페이지** | `http://도메인/admin` | |
| **사용자 페이지** | `http://도메인/` | 사용자 템플릿 설치 필수 |

> 사용자 페이지는 사용자 템플릿이 설치되어 있어야 접근할 수 있습니다. 인스톨러에서 사용자 템플릿을 함께 설치하거나, 관리자 페이지에서 템플릿을 먼저 설치해 주세요.

---

## 업그레이드

이미 운영 중인 그누보드7을 새 버전으로 업그레이드할 때의 절차입니다. 신버전 ZIP 또는 git pull 로 파일만 덮어쓰면 **데이터베이스 마이그레이션, 데이터 시드, 확장(모듈/플러그인/템플릿) 재동기화가 함께 수행되지 않아** 게시판/회원/상품 데이터 누락이나 부정합 부팅 실패가 발생합니다. 반드시 아래 절차를 따라주세요.

### 개요

그누보드7 업그레이드의 SSoT(Single Source of Truth)는 Artisan 커맨드입니다.

```bash
sudo php artisan core:update
```

이 커맨드 1회 실행으로 다음 작업이 자동 수행됩니다.

- 신버전 다운로드 및 압축 해제 (GitHub Release 또는 지정 ZIP)
- 자동 백업 생성 (실패 시 자동 롤백)
- 유지보수 모드 자동 진입
- 데이터베이스 마이그레이션 실행
- 업그레이드 스텝 실행 (버전별 데이터 보정)
- 번들 확장 자동 재동기화
- 캐시 정리 및 유지보수 모드 해제

### 업그레이드 사전 준비

업그레이드 전 다음을 확인하세요.

1. **데이터베이스 백업** (필수)

    ```bash
    mysqldump -u DB사용자 -p DB이름 > backup-$(date +%Y%m%d).sql
    ```

2. **활성 디렉토리 백업 권장** — 직접 수정한 코드나 외부 확장(`_bundled` 에 포함되지 않은 GitHub install 확장) 이 있다면 다음 4개 디렉토리를 별도 위치로 백업하세요.

    ```bash
    tar -czf extensions-backup-$(date +%Y%m%d).tar.gz modules plugins templates lang-packs
    ```

3. **SSH/CLI 접근 확보** — 코어 업그레이드는 보안상 웹 UI 에서 실행할 수 없으며, 반드시 서버 터미널(SSH) 에서 직접 실행해야 합니다.

### 방법 1: 관리자 UI 에서 업데이트 확인 (권장 동선 시작점)

운영자가 새 버전 출시 여부를 가장 빠르게 알 수 있는 동선입니다.

1. 관리자 페이지 로그인 → **환경설정 > 정보** 탭 이동
2. "**업데이트 확인**" 버튼 클릭 → 최신 버전과 변경사항 모달 표시
3. 신버전이 감지되면 "**변경사항 보기**" 로 changelog 확인 → "**업데이트 방법**" 버튼 클릭
4. 모달이 권장 명령어와 본 가이드 링크를 안내하므로 그 안내에 따라 **서버 터미널에서 직접 실행**

> 모달은 안내 전용입니다. 보안상 UI 에서 코어 업그레이드를 직접 실행하지 않으며, 운영자가 권한 일치를 확인한 후 CLI 에서 실행해야 합니다.

### 방법 2: CLI 자동 업그레이드 (표준 절차)

대다수 환경에서 권장되는 절차입니다.

```bash
sudo php artisan core:update
```

#### `sudo` 가 권장되는 이유

업그레이드는 다음 파일들을 수정·생성하므로, 운영 환경에서 일반 사용자 권한으로 모두 접근 가능한 경우가 드뭅니다.

- `.env` — `APP_VERSION` 갱신 (보안상 `0600` 또는 `0640` 권한, 소유자는 보통 웹 서버 사용자 `www-data` · `nginx` · `apache` 등)
- `bootstrap/cache/` — 설정·라우트·서비스 캐시 갱신
- `storage/framework/`, `storage/logs/` — 캐시·로그 파일 생성
- `modules/`, `plugins/`, `templates/`, `lang-packs/` — 확장 재동기화

`sudo` 로 root 권한을 위임받으면 위 파일들의 소유자가 누구든(웹 서버 사용자 또는 별도 배포 사용자) 권한 충돌 없이 한 번에 처리할 수 있습니다. 업그레이드 도중 `.env` 머지 단계는 기존 소유자·그룹을 그대로 보존하므로, root 로 실행해도 파일 권한이 임의로 변경되지 않습니다.

#### `sudo` 없이 실행해도 되는 환경

- 소유자가 명령줄 사용자와 일치하는 **단일 사용자 호스팅** (Cafe24 등 공유 호스팅, 개인 VPS 의 사용자 계정 운영) — 그냥 `php artisan core:update` 실행
- **Windows (XAMPP/Laragon 등)** — 관리자 권한 PowerShell 에서 `php artisan core:update` 실행

> 자신의 환경이 어느 쪽인지 확인하려면 `ls -l .env` 의 소유자(세 번째 컬럼) 가 명령줄 로그인 사용자와 일치하는지 보세요. 일치하면 `sudo` 없이 실행 가능합니다.

### 방법 3: 수동 ZIP 업그레이드 (PHP zip 확장 미설치 환경)

PHP `zip` 확장 또는 시스템 `unzip` 명령이 없는 서버에서는 GitHub Release ZIP 을 직접 다운로드한 뒤 압축 해제 경로를 지정해 업그레이드합니다.

```bash
# 1. GitHub 릴리스 페이지에서 ZIP 다운로드 후 서버에 압축 해제
wget https://github.com/gnuboard/g7/releases/download/<버전>/<파일명>.zip
unzip <파일명>.zip -d /tmp/g7-new

# 2. 압축 해제된 경로를 지정하여 업데이트 실행
sudo php artisan core:update --source=/tmp/g7-new
```

`--source=` 옵션은 다운로드 단계를 건너뛰고 지정 경로의 파일을 신버전으로 간주합니다.

### 사용자군별 단계적 업그레이드

여러 베타 버전을 건너뛰고 한 번에 최신 버전으로 올리는 것은 권장하지 않습니다. 중간 버전의 업그레이드 스텝이 이전 버전 메모리에 없는 신규 코드에 의존하여 부팅 실패 위험이 있습니다.

| 현재 버전 | 권장 경로 |
|----------|----------|
| **beta.5 이상** | `sudo php artisan core:update` 1회로 최신 버전까지 자동 처리 |
| **beta.3 / beta.4** | beta.5 → 최신 순서로 2회 분할 실행. 각 단계 후 `.env` `APP_VERSION` 이 자동 갱신되므로 같은 명령을 반복 실행 |
| **beta.1 / beta.2** | beta.2 → beta.3 → beta.4 → beta.5 → 최신 순으로 단계별 진행 |
| **beta.4 도중 fatal 후 중단** | `.env` `APP_VERSION` 을 변경하지 말고 `php artisan core:update --force` 재실행. 새 자식 프로세스가 신버전 메모리로 부팅되어 남은 스텝이 멱등 적용됨 |

특정 베타 버전으로의 단계 업그레이드는 `--source=` 또는 `--zip=` 옵션으로 해당 버전 릴리스 ZIP 을 지정하여 진행합니다.

> 사용자군별 상세 분기는 [CHANGELOG.md](CHANGELOG.md) 의 해당 버전 "Upgrade Notice" 섹션을 참고하세요.

### 권한/캐시 트러블슈팅

업그레이드 도중 또는 직후 사이트가 응답하지 않는 경우, 다음을 확인합니다. 본 절의 `chmod`/`chown`/`chgrp` 명령은 POSIX 권한 모델 (Linux/macOS/BSD) 환경 전용이며, Windows 환경에서는 해당하지 않습니다.

#### `.env` 읽기 실패 (`Permission denied`)

```bash
ls -l .env
# -rw------- 1 www-data www-data ... .env   ← 세 번째 컬럼이 소유자
```

대부분의 경우 `sudo php artisan core:update` 로 재실행하면 root 권한으로 소유자에 관계없이 접근됩니다. 권한 보안을 강화하려면 본 문서 [`.env` 권한 강화 (선택)](#env-권한-강화-선택) 섹션의 `0600` / `0640` 가이드를 참고하세요. 명령줄 사용자와 웹 서버 사용자가 다른 환경에서는 `0640` + 웹 서버 그룹 부여가 안전한 절충안입니다.

#### `bootstrap/cache` 쓰기 실패

```bash
sudo chown -R www-data:www-data bootstrap/cache storage
```

소유자를 웹 서버 사용자로 일괄 정렬한 뒤 업그레이드를 재실행합니다.

#### 업그레이드 도중 자동 롤백 후 부팅 실패

beta.6 이상에서는 자동 롤백 후 잔존 파일을 추가 진단하는 `hotfix:rollback-stale-files` 커맨드가 제공됩니다.

```bash
# 진단 모드 (실제 삭제 없음)
sudo php artisan hotfix:rollback-stale-files

# 운영자 확인 후 실제 정리
sudo php artisan hotfix:rollback-stale-files --prune
```

### 업그레이드 후 확인

업그레이드가 끝나면 다음을 점검합니다.

1. 관리자 페이지 > **환경설정 > 정보** 에서 G7 버전이 갱신되었는지 확인
2. **확장 > 모듈/플러그인/템플릿** 목록이 모두 정상 표시되는지 확인
3. 사용자 페이지 진입하여 기존 콘텐츠(게시판/상품/페이지) 가 정상 노출되는지 확인
4. `storage/logs/laravel.log` 에 신규 에러가 누적되지 않는지 확인

---

## 프로덕션 환경 추가 설정

프로덕션 환경에서는 아래 항목을 추가로 설정하는 것을 권장합니다.

### HTTPS 설정

프로덕션 환경에서는 HTTPS를 사용해야 합니다. `.env` 파일에서 `APP_URL`을 `https://`로 설정하세요.

### `.env` 권한 강화 (선택)

`.env` 는 DB 비밀번호와 `APP_KEY` 등 평문 자격증명을 포함합니다. 인스톨러는 설치 직후 `.env` 의 권한을 임의로 변경하지 않으므로, 운영자가 환경에 맞춰 직접 강화 권한을 적용할 수 있습니다.

- 가장 안전한 권한은 `0600` (소유자만 읽기·쓰기) 입니다. 다만 `0600` 적용 전에 **`.env` 의 소유자가 웹 서버 실행 사용자와 동일해야** 합니다. 소유자가 다른데 `0600` 으로 변경하면 웹 서버가 `.env` 를 읽지 못해 사이트가 응답하지 않습니다.

- **단일 사용자 환경** (예: 카페24 등 명령줄 사용자와 웹 서버 사용자가 같은 경우):

    ```bash
    sudo chown www-data:www-data .env
    sudo chmod 600 .env
    ```

- **자체 구축 서버** (명령줄 사용자 `jjh` 등과 웹 서버 사용자 `www-data` 가 다른 경우):

    ```bash
    sudo chgrp www-data .env
    sudo chmod 640 .env
    ```

    웹 서버는 그룹 멤버로 읽기만 허용되고, 명령줄 사용자는 직접 수정할 수 있는 절충안입니다.

인스톨러 Step 5 완료 화면은 현재 `.env` 권한이 `0600` 이 아닐 경우 위 두 명령 예시를 조건부로 표시합니다.

### 데몬 프로세스

상시 실행이 필요한 프로세스입니다. Supervisor 등을 사용하여 관리합니다.

| 프로세스 | 명령어 | 용도 |
|---------|--------|------|
| 큐 워커 | `php artisan queue:work` | 비동기 작업 처리 |
| WebSocket | `php artisan reverb:start` | 실시간 알림 |

### 스케줄러

cron에 아래 항목을 등록합니다.

```bash
* * * * * cd /path/to/g7 && php artisan schedule:run >> /dev/null 2>&1
```

> 상세 내용은 [docs/requirements.md](docs/requirements.md)를 참조하세요.
