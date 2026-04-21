# Vendor 번들 시스템 (Vendor Bundle System)

## TL;DR (5초 요약)

```text
- Composer 사용 불가 환경(공유 호스팅 등)을 위해 vendor/ 디렉토리를 zip으로 선탑재
- 개별 빌드: php artisan {core|module|plugin}:vendor-bundle [identifier|--all]
- 일괄 빌드: php artisan vendor-bundle:build-all (코어 + 모든 _bundled)
- 개별/일괄 검증: {core|module|plugin}:vendor-verify, vendor-bundle:verify-all
- 모드: VendorMode = auto | composer | bundled
- 결정 순서: 명시 → DB 이전 모드 → config → 환경감지(composer 가능 시 우선)
- composer.json/lock 수정 후 재빌드 필수 — scripts 섹션 같은 런타임 무관 변경도 파일 전체 SHA256 변경 → VendorIntegrityChecker 실패
```

## 1. 개요

G7 코어와 모듈/플러그인은 PHP 의존성 관리에 Composer를 사용합니다. 그러나 일부 운영 환경(공유 호스팅, 제한된 PaaS)에서는 `proc_open()`/`shell_exec()` 함수가 차단되어 Composer를 실행할 수 없습니다. **Vendor 번들 시스템**은 미리 압축된 `vendor-bundle.zip` 파일을 추출하여 vendor/ 디렉토리를 구성하는 대안 방식을 제공합니다.

### 1.1 핵심 파일

각 코어/확장 루트에 두 파일이 함께 존재합니다:

```text
{root}/
├── composer.json           (의존성 정의)
├── composer.lock           (락 파일)
├── vendor-bundle.zip       (압축된 vendor 디렉토리)
└── vendor-bundle.json      (메타파일: SHA256, 패키지 목록 등)
```

`vendor-bundle.json` 스키마 (v1.0):

```json
{
    "schema_version": "1.0",
    "generated_at": "2026-04-14T12:34:56+09:00",
    "generator": "g7 vendor-bundle:build",
    "target": "core" | "module:identifier" | "plugin:identifier",
    "composer_json_sha256": "...",
    "composer_lock_sha256": "...",
    "zip_sha256": "...",
    "zip_size": 12345678,
    "package_count": 142,
    "php_requirement": "^8.2",
    "g7_version": "7.0.0-beta.4",
    "packages": [
        { "name": "laravel/framework", "version": "12.0.5", "type": "library" }
    ]
}
```

## 2. VendorMode

세 가지 모드가 있습니다:

| 모드 | 설명 |
|------|------|
| `auto` | 환경 감지 — composer 사용 가능 시 composer, 불가 시 bundled |
| `composer` | 강제 composer 실행 (불가 시 예외) |
| `bundled` | 강제 vendor-bundle.zip 추출 (zip 없으면 예외) |

### 2.1 모드 결정 우선순위

`VendorResolver::resolveMode()` 의 우선순위:

```text
1. CLI/API 명시 (--vendor-mode 또는 vendor_mode 파라미터)
   └─ composer/bundled 명시 시 그대로 사용
2. 업데이트 시 DB 이전 모드 상속
   └─ modules.vendor_mode / plugins.vendor_mode 컬럼이 auto가 아니면 상속
3. 전역 설정 config('app.install.default_vendor_mode')
4. auto 해석 → 환경 감지
   a. EnvironmentDetector::canExecuteComposer() == true → Composer
   b. composer 불가 + vendor-bundle.zip 존재 → Bundled
   c. 둘 다 불가 → 예외 (no_vendor_strategy_available)
```

**핵심 원칙**: auto 모드에서는 **composer가 우선**입니다 (개발 환경과 프로덕션 동작 일치).

## 3. CLI 사용법

본 시스템은 **개별 명령** 6개와 **일괄 알리아스** 2개로 구성됩니다. 개별 명령은 기존
`core:build` / `module:build` / `plugin:build` 패턴과 정렬되어 있습니다.

### 3.1 개별 빌드 — 코어/모듈/플러그인

```bash
# 코어 단독 빌드
php artisan core:vendor-bundle [--check] [--force]

# 특정 모듈 빌드 (positional identifier — module:build 와 동일 패턴)
php artisan module:vendor-bundle sirsoft-ecommerce [--check] [--force]

# 모든 _bundled 모듈 빌드 (--all 명시 필요)
php artisan module:vendor-bundle --all [--check] [--force]

# 특정 플러그인 빌드
php artisan plugin:vendor-bundle sirsoft-payment [--check] [--force]

# 모든 _bundled 플러그인 빌드
php artisan plugin:vendor-bundle --all [--check] [--force]
```

식별자도 `--all` 도 지정하지 않으면 명시적 에러가 발생합니다 (의도하지 않은 일괄
실행 방지). 기존 `module:build` / `plugin:build` 와 동일한 안전 동작입니다.

### 3.2 개별 검증

```bash
php artisan core:vendor-verify
php artisan module:vendor-verify sirsoft-ecommerce
php artisan module:vendor-verify --all
php artisan plugin:vendor-verify sirsoft-payment
php artisan plugin:vendor-verify --all
```

### 3.3 일괄 알리아스 — 운영/CI 시나리오

코어 + 모든 `_bundled` 모듈/플러그인을 한 번에 처리하려면 일괄 알리아스를 사용합니다.

```bash
# 일괄 빌드 (코어 + 모든 _bundled 모듈/플러그인)
php artisan vendor-bundle:build-all [--check] [--force]

# 일괄 검증
php artisan vendor-bundle:verify-all
```

`vendor-bundle:build-all --check` 는 stale 감지 시 종료 코드 1을 반환하므로 CI
파이프라인의 사전 검증 단계에 유용합니다.

### 3.4 옵션 요약

| 옵션 | 동작 |
| ---- | ---- |
| `--check` | 실제 빌드 없이 stale 여부만 확인. stale 발견 시 종료 코드 1 |
| `--force` | composer.json/lock 해시 체크를 무시하고 강제 재빌드 |
| `--all` | (개별 명령에서) 해당 타입의 모든 `_bundled` 확장 대상 |

### 3.5 검증 항목

- vendor-bundle.zip / vendor-bundle.json 파일 존재
- manifest의 schema_version 지원 여부
- zip 파일의 SHA256 해시 일치
- composer.json/composer.lock SHA256 일치 (소스에 존재 시)

## 4. 코어 업데이트

```bash
# Auto 모드 (기본값)
php artisan core:update

# Composer 강제
php artisan core:update --vendor-mode=composer

# 번들 강제 (공유 호스팅 환경)
php artisan core:update --vendor-mode=bundled

# 로컬 + 번들 강제 (개발 시 번들 검증)
php artisan core:update --local --force --vendor-mode=bundled
```

## 5. 확장 설치/업데이트

### 5.1 CLI

```bash
# 모듈 설치 (auto)
php artisan module:install sirsoft-ecommerce

# 모듈 bundled 강제 설치
php artisan module:install sirsoft-ecommerce --vendor-mode=bundled

# 플러그인 업데이트 (이전 모드 상속)
php artisan plugin:update sirsoft-payment

# 플러그인 force 업데이트 + composer 강제
php artisan plugin:update sirsoft-payment --force --vendor-mode=composer
```

### 5.2 Admin UI

관리자 페이지의 모듈/플러그인 설치 모달에 **Vendor 설치 방식** Select 필드가 있습니다:

- 자동 (권장)
- Composer 실행
- 번들 Vendor 사용

### 5.3 API

```http
POST /api/admin/modules/install
Content-Type: application/json

{
  "module_name": "sirsoft-ecommerce",
  "vendor_mode": "bundled"
}
```

## 6. 웹 인스톨러

`public/install/` 마법사의 Step 3(환경 설정)에서 Vendor 설치 방식을 선택할 수 있습니다.

- 환경 감지: ZipArchive 확장 + proc_open 사용 가능 여부
- 비활성 옵션: 환경에서 사용 불가한 모드는 자동 비활성화
- 자동 폴백: auto 모드에서 composer 실행 불가 시 vendor-bundle.zip이 있으면 자동 전환

## 7. 무결성 검증 및 보안

### 7.1 SHA256 검증

`VendorIntegrityChecker::verify()` 가 다음을 검증합니다:

- vendor-bundle.zip 파일의 실제 SHA256 vs manifest 기록 값
- composer.json SHA256 (소스에 존재 시)
- composer.lock SHA256 (소스에 존재 시)

검증 실패 시 `VendorInstallException` 이 발생하며 설치가 중단됩니다.

### 7.2 Zip Slip 방지

추출 전 zip 내부 모든 파일 경로를 검증합니다:

- `../` 포함 경로 거부
- 절대 경로 거부 (`/...`, `C:/...`)

## 8. 자동 트리거

`composer.json` 또는 `composer.lock` 파일이 변경되면 번들이 stale 상태가 됩니다.
다음 명령으로 재빌드해야 합니다:

```bash
# stale 여부 확인
php artisan vendor-bundle:build-all --check

# 일괄 재빌드
php artisan vendor-bundle:build-all
```

CI 통합 예시:

```yaml
- name: Verify vendor bundles
  run: php artisan vendor-bundle:build-all --check
```

## 9. 핵심 클래스

| 클래스 | 책임 |
|--------|------|
| [VendorMode](../../app/Extension/Vendor/VendorMode.php) | 모드 enum (Auto/Composer/Bundled) |
| [VendorResolver](../../app/Extension/Vendor/VendorResolver.php) | 모드 결정 + 전략 디스패치 |
| [VendorBundler](../../app/Extension/Vendor/VendorBundler.php) | zip 빌드 (개발 타임) |
| [VendorBundleInstaller](../../app/Extension/Vendor/VendorBundleInstaller.php) | zip 추출 (런타임) |
| [VendorIntegrityChecker](../../app/Extension/Vendor/VendorIntegrityChecker.php) | SHA256 무결성 검증 |
| [EnvironmentDetector](../../app/Extension/Vendor/EnvironmentDetector.php) | composer/zip 환경 감지 |
| [VendorInstallException](../../app/Extension/Vendor/Exceptions/VendorInstallException.php) | vendor 관련 예외 |

## 10. 트러블슈팅

| 증상 | 원인 | 해결 |
|------|------|------|
| `bundle_zip_missing` | vendor-bundle.zip 파일 없음 | `vendor-bundle:build` 실행 |
| `zip_hash_mismatch` | zip 파일 손상 또는 변조 | `vendor-bundle:build --force` 재빌드 |
| `composer_json_sha_mismatch` | composer.json 변경 후 번들 미갱신 | `vendor-bundle:build` 재빌드 |
| `composer_not_available` | composer 모드 강제했으나 실행 불가 | --vendor-mode=auto 또는 bundled로 변경 |
| `no_vendor_strategy_available` | composer 불가 + 번들 없음 | vendor-bundle.zip 업로드 또는 호스팅 변경 |
| `bundle_contains_unsafe_path` | 외부 zip 파일이 zip slip 시도 | 신뢰할 수 있는 소스에서만 다운로드 |

## 11. 참고

- 본 문서는 vendor 번들 시스템의 사용자 가이드입니다.
- Bundle 자체는 .gitignore 에 등록되어 Git 추적되지 않습니다 (Release asset 형태로 배포 권장).
- 확장 설치 모드는 `modules.vendor_mode` / `plugins.vendor_mode` 컬럼에 기록되며, 업데이트 시 자동 상속됩니다.
