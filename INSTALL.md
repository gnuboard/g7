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
