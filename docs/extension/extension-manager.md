# ExtensionManager (확장 관리자)

> **소스**: extension-guide.md에서 분리
> **관련 문서**: [module-basics.md](module-basics.md), [plugin-development.md](plugin-development.md)

---

## TL;DR (5초 요약)

```text
1. composer.json 수정 없음 - 런타임 오토로드 방식 사용
2. 캐시 파일: bootstrap/cache/autoload-extensions.php (gitignore 대상)
3. 설치/삭제 시 자동 갱신: updateComposerAutoload() → generateAutoloadFile()
4. 수동 갱신: php artisan extension:update-autoload
5. _bundled/_pending 디렉토리 자동 제외 (str_starts_with($name, '_') → skip)
```

---

## 목차

1. [개요](#개요)
2. [런타임 오토로드 방식](#런타임-오토로드-방식)
3. [Composer 의존성 관리](#composer-의존성-관리)
4. [식별자 검증 규칙](#식별자-검증-규칙-validextensionidentifier)
5. [관리자 UI 필터링 (hidden 플래그)](#관리자-ui-필터링-hidden-플래그)
6. [Artisan 커맨드](#artisan-커맨드)
7. [서비스 등록](#서비스-등록)

---

## 개요

모듈과 플러그인에서 공통으로 사용되는 기능을 담당하는 관리자 클래스입니다. 주로 오토로드 설정 관리를 담당합니다.

**주요 역할**:

- 런타임 오토로드 캐시 파일 생성
- 모듈/플러그인 설치 시 PSR-4 네임스페이스 등록
- Composer ClassLoader에 동적 등록

---

## 런타임 오토로드 방식

### 핵심 원칙

```
중요: composer.json을 수정하지 않음
✅ 결정: 런타임 오토로드 방식 사용 (캐시 파일 기반)
✅ 장점: 버전 관리 충돌 없음, composer dump-autoload 불필요
```

### 동작 방식

```
모듈/플러그인 설치/삭제 시
    ↓
ExtensionManager::updateComposerAutoload() 호출
    ↓
bootstrap/cache/autoload-extensions.php 파일 생성 (gitignore 대상)
    ↓
public/index.php 또는 artisan 실행 시
    ↓
$loader->addPsr4()로 런타임에 네임스페이스 등록
```

### 캐시 파일 구조

```php
// bootstrap/cache/autoload-extensions.php

return [
    'psr4' => [
        "Modules\\Sirsoft\\Ecommerce\\" => "modules/sirsoft-ecommerce/src/",
        "Plugins\\Sirsoft\\Payment\\" => "plugins/sirsoft-payment/src/",
    ],
    'classmap' => [
        "modules/sirsoft-ecommerce/module.php",
        "plugins/sirsoft-payment/plugin.php",
    ],
    'files' => [],
    'vendor_autoloads' => [
        "modules/sirsoft-ecommerce/vendor/autoload.php",
    ],
];
```

- `vendor_autoloads`: 모듈/플러그인의 `vendor/autoload.php` 경로 목록 (Composer 의존성이 설치된 경우만 포함)

### 중요 규칙

```
설치된 모듈/플러그인만 캐시 파일에 반영됩니다
_bundled/_pending 디렉토리는 활성 확장으로 인식하지 않습니다
```

- 디렉토리에 파일이 있어도 **DB에 설치 기록이 없으면 반영되지 않음**
- identifier는 디렉토리명과 동일 (예: `sirsoft-sample`, `sirsoft-payment`)
- `_`로 시작하는 디렉토리(`_bundled`, `_pending`)는 디렉토리 스캔 시 자동 제외됨
- 캐시 파일에는 활성 디렉토리 경로만 포함 (`_bundled/` 경로 미포함)

### 수행 작업

1. `ModuleRepository`와 `PluginRepository`를 통해 설치된 확장 목록 조회
2. 각 모듈/플러그인의 `composer.json`에서 PSR-4 오토로드 정보 수집
3. `bootstrap/cache/autoload-extensions.php` 파일 생성
4. `module.php`, `plugin.php` 파일을 `classmap`에 추가

### 로딩 진입점

**public/index.php** 및 **artisan**에서 `ExtensionManager::registerExtensionAutoload($loader)` 호출:

```text
registerExtensionAutoload($loader)
    ↓ 1. PSR-4 네임스페이스 등록 ($loader->addPsr4())
    ↓ 2. Classmap 파일 로드 (module.php, plugin.php → require_once)
    ↓ 3. Files 로드 (헬퍼 함수 등 → require_once)
    ↓ 4. Vendor autoloads 로드 (모듈/플러그인의 vendor/autoload.php → require_once)
```

**로딩 순서가 중요합니다**:

| 순서 | 유형 | 설명 |
| ---------- | ---------- | ---------- |
| 1 | PSR-4 | 확장의 `src/` 디렉토리 네임스페이스를 Composer ClassLoader에 등록 |
| 2 | Classmap | `module.php`, `plugin.php` 엔트리 파일을 즉시 로드 |
| 3 | Files | 헬퍼 함수 등 자동 로드 파일 |
| 4 | Vendor autoloads | 각 확장의 `vendor/autoload.php`를 `require_once`하여 외부 패키지 로드 |

- 캐시 파일 미존재 시 아무 작업도 하지 않고 return (설치 전 상태)
- 경로가 존재하지 않는 항목은 자동 스킵 (`is_dir()` / `file_exists()` 체크)

---

## Composer 의존성 관리

모듈/플러그인이 외부 Composer 패키지(예: `stripe/stripe-php`)를 필요로 할 때, 각 확장의 `composer.json`에 `require`로 정의하고 확장별 독립 `vendor/` 디렉토리에 설치합니다.

### 핵심 원칙

```text
중요: 루트 composer.json에 모듈/플러그인 패키지 추가 금지
✅ 결정: 각 확장의 composer.json → 확장별 vendor/ 디렉토리에 독립 설치
✅ 장점: 확장 간 의존성 충돌 방지, 삭제 시 깔끔한 정리
```

### Vendor 설치 모드 (VendorMode)

공유 호스팅 등 Composer 실행 불가 환경을 지원하기 위해 **VendorResolver** 경유로 설치 모드를 결정합니다.

```text
VendorMode enum:
- auto: composer 가능 시 composer, 불가 시 bundled (기본값)
- composer: composer install 강제 (불가 시 예외)
- bundled: vendor-bundle.zip 추출 강제
```

설치/업데이트 시 `installModule()` / `installPlugin()` / `updateModule()` / `updatePlugin()` 의 `VendorMode $vendorMode` 파라미터로 전달되며, `VendorResolver::install()` 가 환경 감지 + DB 기록 이전 모드 상속 + 번들 zip 무결성 검증을 거쳐 적절한 전략으로 라우팅합니다. `modules.vendor_mode` / `plugins.vendor_mode` 컬럼에 최종 사용된 모드가 기록되어 업데이트 시 자동 상속됩니다.

> 상세: [vendor-bundle.md](vendor-bundle.md) — 번들 구조, 빌드/검증/설치 흐름, 무결성 검증

### 동작 방식

```text
모듈/플러그인 설치 시 (Phase 4.5)
    ↓
hasComposerDependencies() → 외부 패키지 존재 여부 확인
    ↓ (존재 시)
runComposerInstall() → 확장 디렉토리에서 composer install 실행
    ↓
modules/{name}/vendor/ 또는 plugins/{name}/vendor/ 생성
    ↓
updateComposerAutoload() → autoload-extensions.php에 vendor_autoloads 등록
    ↓
public/index.php / artisan → vendor/autoload.php를 require_once
```

### 주요 메서드

| 메서드 | 시그니처 | 설명 |
|--------|----------|------|
| `hasComposerDependencies` | `(string $type, string $dirName): bool` | 외부 패키지 의존성 존재 여부 (`php`, `ext-*` 제외) |
| `getComposerDependencies` | `(string $type, string $dirName): array` | 외부 패키지 목록 반환 |
| `runComposerInstall` | `(string $type, string $dirName, bool $noDev, ?Command $command): bool` | 확장 디렉토리에서 `composer install` 실행 |
| `detectDuplicatePackages` | `(): array` | 여러 확장에서 중복 사용되는 패키지 감지 |
| `isComposerUnchanged` | `(string $stagingPath, string $activePath): bool` | 스테이징/활성 디렉토리의 composer 변경 여부 확인 |

### Composer 의존성 변경 감지 (isComposerUnchanged)

업데이트 시 불필요한 `composer install`을 스킵하기 위해 `composer.json`과 `composer.lock`을 이중 해시 비교합니다:

```text
isComposerUnchanged(stagingPath, activePath)
    ↓ 활성 디렉토리 또는 vendor/ 미존재 → false (설치 필요)
    ↓ composer.json 한쪽 미존재 → false (변경으로 간주)
    ↓ md5_file(staging/composer.json) !== md5_file(active/composer.json) → false
    ↓ composer.lock 존재 여부 불일치 → false
    ↓ md5_file(staging/composer.lock) !== md5_file(active/composer.lock) → false
    ↓ 모두 일치 → true (스킵 가능)
```

- **이중 비교**: `composer.json`(의존성 선언) + `composer.lock`(실제 설치 버전) 모두 확인
- **false 반환 = 설치 필요**: 하나라도 변경되었으면 `composer install` 실행
- **true 반환 = 스킵 가능**: 기존 `vendor/` 디렉토리 재사용

### runComposerInstall 환경변수

웹 서버 환경에서 Composer 실행 시 시스템 환경변수가 부족하므로, `install-worker.php` 패턴과 동일하게 환경변수를 명시적으로 구성합니다.

| 환경변수 | 출처/설정값 | 용도 |
|----------|-----------|------|
| PATH, SystemRoot | `getenv()`으로 시스템에서 복사 | Composer 바이너리 탐색 |
| TEMP, TMP | 시스템에서 복사, 불가 시 `storage/temp` | 임시 파일 (Windows 특이) |
| APPDATA, LOCALAPPDATA, USERPROFILE | `getenv()`으로 시스템에서 복사 | Windows 사용자 경로 |
| COMPOSER_HOME | `storage/composer` | Composer 캐시 디렉토리 |
| HOME | `storage/composer` | Unix-style 홈 디렉토리 |

### 삭제 시 vendor 정리

`InspectsUninstallData` trait이 제공하는 메서드:

| 메서드 | 설명 |
|--------|------|
| `getVendorDirectoryInfo(string $type, string $dirName): ?array` | vendor/ 디렉토리 크기 정보 조회 (삭제 모달 표시용) |
| `deleteVendorDirectory(string $type, string $dirName): void` | vendor/ 디렉토리 및 composer.lock 삭제 |

- **삭제 시점**: `uninstallModule`/`uninstallPlugin`에서 `$deleteData === true`일 때 실행
- **실패 처리**: 삭제 실패 시 예외를 catch하고 로그만 남김 (프로세스 중단하지 않음)

---

## Artisan 커맨드

### extension:update-autoload

모듈과 플러그인의 오토로드 캐시 파일을 생성합니다.

```bash
php artisan extension:update-autoload
```

**사용 시점**:

- 모듈/플러그인 디렉토리를 수동으로 추가/삭제한 경우
- 오토로드 캐시 파일이 손상된 경우
- 개발 중 오토로드 문제 디버깅

**출력 예시**:

```bash
$ php artisan extension:update-autoload
확장 오토로드 파일을 생성합니다...
오토로드 파일이 성공적으로 생성되었습니다.
  → bootstrap/cache/autoload-extensions.php
```

### extension:composer-install

모든 모듈과 플러그인의 Composer 의존성을 일괄 설치합니다.

```bash
php artisan extension:composer-install
```

**옵션**:

- `--no-dev`: dev 의존성 제외

**사용 시점**:

- 프로젝트 초기 설정 시 모든 확장의 의존성을 한번에 설치
- 배포 환경 구성 시

**동작**:

- `module:composer-install --all`과 `plugin:composer-install --all`을 순차 호출

---

## 식별자 검증 규칙 (ValidExtensionIdentifier)

모듈/플러그인/템플릿 설치 시 `ValidExtensionIdentifier` 규칙으로 식별자 형식을 공통 검증합니다:

| 규칙 | 설명 | 예시 |
| ---------- | ---------- | ---------- |
| **하이픈 1차 구분** | 최소 2개 부분 필수 (vendor-name) | `sirsoft-board` ✅ / `sirsoftboard` ❌ |
| **언더스코어 2차 구분** | 이름 내 단어 구분 (선택적) | `sirsoft-daum_postcode` ✅ |
| **영문 소문자 + 숫자 + `_`만** | 대문자, 특수문자 불가 | `Sirsoft-Board` ❌ / `sirsoft-my@module` ❌ |
| **각 단어 첫 글자 숫자 불가** | 하이픈/언더스코어로 구분된 각 단어 기준 | `sirsoft-2shop` ❌ / `sirsoft-board2` ✅ |
| **빈 부분 불가** | 연속 하이픈/언더스코어, 양끝 하이픈/언더스코어 금지 | `sirsoft--board` ❌ / `-sirsoft-board` ❌ |
| **최대 255자** | 길이 제한 | - |

### 유효/무효 예시

```text
✅ sirsoft-board           → Sirsoft\Board
✅ sirsoft-daum_postcode   → Sirsoft\DaumPostcode
✅ sirsoft-board2          → Sirsoft\Board2
✅ vendor-my_module_name   → Vendor\MyModuleName

❌ sirsoftboard            → 하이픈 없음 (최소 2부분 필수)
❌ sirsoft-2shop           → '2shop' 첫 글자 숫자
❌ Sirsoft-Board           → 대문자 사용
❌ sirsoft-my@module       → 특수문자 '@'
❌ sirsoft--board          → 연속 하이픈 (빈 부분)
❌ sirsoft-board_          → 끝 언더스코어 (빈 단어)
```

### Artisan 커맨드에서의 검증

`module:install`, `plugin:install`, `template:install` 등에서 `ExtensionManager::validateIdentifierFormat()`을 호출하여 잘못된 식별자 입력 시 `InvalidArgumentException`을 발생시킵니다.

### 관련 파일

- `app/Rules/ValidExtensionIdentifier.php` — 검증 규칙 구현
- `ExtensionManager::validateIdentifierFormat()` — Artisan 커맨드용 래퍼

---

## 관리자 UI 필터링 (hidden 플래그)

확장 manifest (`module.json` / `plugin.json` / `template.json`) 에 `"hidden": true` 를 설정하면, 관리자 UI 목록 응답과 artisan `*:list` 기본 출력에서 제외됩니다. 학습용 샘플 확장, 내부 운영 전용 확장을 숨길 때 사용합니다.

### 제외 범위

| 대상 | hidden 적용 |
|------|-------------|
| 관리자 UI 기본 응답 (`GET /api/admin/modules`, `/plugins`, `/templates`) | 제외 |
| artisan 기본 목록 (`module:list`, `plugin:list`, `template:list`) | 제외 |
| 설치/활성화/비활성화/제거 커맨드 (`*:install`, `*:activate`, `*:uninstall`) | 정상 동작 |
| 업데이트 감지 (`*:check-updates`, `*:update`) | 정상 동작 |
| 확장 로딩/오토로드/훅 실행 | 정상 동작 (활성화되어 있으면 기능 전부 사용 가능) |

### 숨김 포함 조회

슈퍼관리자는 다음 방법으로 숨김 확장까지 포함하여 조회할 수 있습니다:

```bash
# artisan CLI
php artisan module:list --hidden
php artisan plugin:list --hidden
php artisan template:list --hidden

# API 쿼리
GET /api/admin/modules?include_hidden=1
GET /api/admin/plugins?include_hidden=1
GET /api/admin/templates?include_hidden=1
```

### manifest 설정

```json
{
    "identifier": "gnuboard7-hello_module",
    "version": "0.1.0",
    "hidden": true
}
```

- 기본값은 필드 생략 (hidden=false)
- `true` 설정 시에만 숨김 처리됨
- 학습용 샘플 확장 예: `gnuboard7-hello_module`, `gnuboard7-hello_plugin`, `gnuboard7-hello_admin_template`, `gnuboard7-hello_user_template`

> 상세: [sample-extensions.md](sample-extensions.md) — 학습용 샘플 확장 전체 가이드

---

## 서비스 등록

`CoreServiceProvider`에서 싱글톤으로 등록됩니다.

```php
// app/Providers/CoreServiceProvider.php

public function register(): void
{
    // 확장 매니저 등록 (모듈/플러그인 공통 기능)
    $this->app->singleton(ExtensionManager::class, function ($app) {
        return new ExtensionManager;
    });

    // 모듈 매니저에 주입
    $this->app->singleton(ModuleManager::class, function ($app) {
        return new ModuleManager($app->make(ExtensionManager::class));
    });

    // 플러그인 매니저에 주입
    $this->app->singleton(PluginManager::class, function ($app) {
        return new PluginManager($app->make(ExtensionManager::class));
    });
}
```

### 의존성 관계

```
ExtensionManager (독립)
    ↓
ModuleManager ← ExtensionManager 주입
    ↓
PluginManager ← ExtensionManager 주입
```

---

## 비활성화 사유 추적 (DeactivationReason)

확장 비활성화는 **사용자 수동 비활성화**와 **시스템 자동 비활성화**를 DB 레벨에서 구분합니다.

### Enum 값

| 값 | 의미 | 시스템 트리거 |
| --- | --- | --- |
| `manual` | 관리자가 관리자 UI 또는 Artisan 커맨드로 직접 비활성화 | false |
| `incompatible_core` | 코어 버전 호환성 검사 실패로 자동 비활성화 | true |

`DeactivationReason::isSystemTriggered()` 가 `true` 인 사유는 알림 영속화 + 재호환 시 원클릭 복구 UX 대상이 됩니다.

### Manager 시그니처

```php
ModuleManager::deactivateModule(
    string $identifier,
    string $reason = DeactivationReason::Manual->value,
    ?string $incompatibleRequiredVersion = null,
): void
```

`PluginManager::deactivatePlugin` / `TemplateManager::deactivateTemplate` 도 동일 시그니처. `$incompatibleRequiredVersion` 은 `incompatible_core` 사유에서 어떤 코어 버전을 요구했는지 기록 (재호환 알림에서 사용).

### 자동 복구 흐름

코어 업그레이드 후 `incompatible_core` 사유로 비활성화된 확장이 다시 호환 범위에 들어오면 관리자 대시보드 카드에 "다시 활성화" CTA 가 표시됩니다 (수동 비활성화 `manual` 은 대상 아님 — 사용자 의도 존중).

DB 컬럼: `modules/plugins/templates` 테이블의 `deactivated_reason` (string) + `incompatible_required_version` (string|null).

---

## 관련 문서

- [모듈 개발 기초](module-basics.md) - 모듈 구조 및 설정
- [플러그인 개발](plugin-development.md) - 플러그인 구조 및 설정
- [모듈 Artisan 커맨드](module-commands.md) - 모듈 관련 커맨드
- [확장 업데이트 시스템](extension-update-system.md) - _bundled/_pending 및 업데이트
- [인덱스](index.md) - 확장 시스템 전체 문서 목록
