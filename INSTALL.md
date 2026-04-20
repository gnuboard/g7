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
# (필요 시) mv g7-7.0.0-beta.2 g7

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

## 프로덕션 환경 추가 설정

프로덕션 환경에서는 아래 항목을 추가로 설정하는 것을 권장합니다.

### HTTPS 설정

프로덕션 환경에서는 HTTPS를 사용해야 합니다. `.env` 파일에서 `APP_URL`을 `https://`로 설정하세요.

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
