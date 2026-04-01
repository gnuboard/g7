# 확장 업데이트 시스템 (Extension Update System)

> 모듈, 플러그인, 템플릿의 버전 업데이트 감지, 실행, 롤백을 관리하는 시스템

## TL;DR (5초 요약)

```text
1. 업데이트 감지 우선순위: GitHub > _bundled (2단계, _pending 미참여)
2. _bundled = Git 추적 선탑재 소스, _pending = 설치 시 임시 스테이징만
3. 상태 가드: ExtensionStatusGuard.assertNotInProgress(ExtensionStatus, identifier)
4. 업그레이드 콜백: upgrades/ 디렉토리에 Upgrade_X_Y_Z.php (UpgradeStepInterface 구현)
5. 역할/권한/메뉴 동기화: user_overrides로 사용자 커스터마이징 보존 (개별 식별자 보호)
```

---

## 목차

1. [_bundled vs _pending 디렉토리](#1-_bundled-vs-_pending-디렉토리)
2. [업데이트 감지](#2-업데이트-감지)
3. [업데이트 실행 흐름](#3-업데이트-실행-흐름)
4. [ExtensionStatusGuard](#4-extensionstatusguard)
5. [ExtensionBackupHelper](#5-extensionbackuphelper)
6. [ExtensionPendingHelper](#6-extensionpendinghelper)
7. [UpgradeStepInterface + UpgradeContext](#7-upgradestepinterface--upgradecontext)
8. [번들 디렉토리 개발 워크플로우](#8-번들-디렉토리-개발-워크플로우-critical---mandatory)
9. [개발자 버전 업데이트 가이드](#9-개발자-버전-업데이트-가이드)
10. [템플릿 레이아웃 충돌 전략](#10-템플릿-레이아웃-충돌-전략)
11. [Artisan 커맨드](#11-artisan-커맨드)
12. [API 엔드포인트](#12-api-엔드포인트)
13. [역할/권한/메뉴 동기화](#13-역할권한메뉴-동기화)
14. [SettingsMigrator](#14-settingsmigrator)
15. [큐 워커 재시작 + 스케줄 등록](#15-큐-워커-재시작--스케줄-등록)

---

## 1. _bundled vs _pending 디렉토리

| 속성 | `_bundled/` | `_pending/` |
|------|------------|-------------|
| **용도** | 선탑재 확장 소스 (배포 포함) | 외부 다운로드/업로드 스테이징 |
| **Git 추적** | O (추적됨) | X (`.gitignore` 제외) |
| **수명** | 항상 존재 | 설치 완료 후 삭제 가능 |
| **업데이트 감지** | **참여** (GitHub 다음 우선순위) | **미참여** |
| **사용 시점** | 설치 + 업데이트 소스 | 설치 시 스테이징만 |
| **디렉토리 구조** | `modules/_bundled/vendor-module/` | `modules/_pending/vendor-module/` |

### 디렉토리 자동 제외

`_`로 시작하는 디렉토리는 ExtensionManager에서 활성 확장으로 인식하지 않습니다:

```php
// str_starts_with($name, '_') → skip
// _bundled, _pending 모두 자동 제외
```

---

## 2. 업데이트 감지

### 우선순위 (2단계)

```
1. GitHub (github_url이 설정된 경우) → GitHub API로 최신 버전 조회
2. _bundled (선탑재 소스) → _bundled/{identifier}/manifest.json에서 버전 비교
```

> **주의**: `_pending` 디렉토리는 업데이트 감지에 **참여하지 않습니다**.

### 감지 결과

```php
[
    'update_available' => true,
    'update_source'    => 'github' | 'bundled',  // pending 없음
    'latest_version'   => '1.2.0',
    'current_version'  => '1.0.0',
]
```

### check-updates API 흐름

1. Service 레이어에서 훅 발행: `core.{type}.before_check_updates`
2. Manager의 `checkAll{Type}ForUpdates()` 호출
3. 각 확장별 `check{Type}Update($identifier)` 실행
4. DB에 `update_available`, `latest_version`, `update_source`, `github_changelog_url` 갱신
5. 훅 발행: `core.{type}.after_check_updates`

### GitHub 아카이브 다운로드 및 해석

GitHub 소스 업데이트 시 아카이브 URL 해석 → 다운로드 → 압축 해제 과정을 거칩니다:

**v접두사 자동 감지** (`resolveGithubArchiveUrl`):

```text
1. "v{version}" 태그로 HEAD 요청 시도 (예: v1.2.0)
2. 실패 시 "{version}" 태그로 HEAD 요청 시도 (예: 1.2.0)
3. HTTP 200/302 → 유효한 URL 반환
4. 모두 실패 → null 반환 (업데이트 불가)
```

**아카이브 추출 전략** (2단계 폴백):

| 순서 | 방식 | 조건 |
| ---------- | ---------- | ---------- |
| 1 | `ZipArchive` (PHP zip 확장) | `class_exists(ZipArchive::class)` |
| 2 | `unzip` CLI 명령어 | ZipArchive 미사용 시 폴백 |

**GitHub 아카이브 디렉토리 구조**: GitHub zipball은 `owner-repo-hash/` 형태의 루트 디렉토리를 포함합니다. `ZipInstallHelper::findAndValidateManifest()`가 1단계 하위 디렉토리까지 자동 검색하여 매니페스트 파일을 찾습니다.

---

## 3. 업데이트 실행 흐름

### 모듈/플러그인 업데이트 (10단계)

```
1. [가드] ExtensionStatusGuard::assertNotInProgress()
2. [백업] ExtensionBackupHelper::createBackup()
3. [상태] status → Updating
4. [파일] 소스에서 활성 디렉토리로 복사
   - github: 다운로드 후 복사
   - bundled: ExtensionPendingHelper::copyToActive()
5. [마이그레이션] runMigrations()
6. [DB 트랜잭션] 버전/메타데이터 갱신
   - Roles, Permissions, Menus 동기화 (→ [13. 역할/권한/메뉴 동기화](#13-역할권한메뉴-동기화))
7. [레이아웃] 활성 상태였으면 레이아웃 갱신
   - registerModuleLayouts() + registerLayoutExtensions() + refreshModuleLayouts()
8. [오토로드] Composer autoload 갱신
9. [업그레이드] runUpgradeSteps($module, $fromVersion, $toVersion)
10. [마무리] 이전 상태 복원 + 백업 삭제 + 캐시 무효화
```

### 템플릿 업데이트 (차이점)

- **마이그레이션**: 없음 (템플릿은 DB 스키마 미사용)
- **업그레이드 스텝**: 없음
- **레이아웃 전략**: `layout_strategy` 파라미터로 충돌 처리 (→ [10. 레이아웃 충돌 전략](#10-템플릿-레이아웃-충돌-전략))
- **Roles/Permissions/Menus**: 없음

### 에러 발생 시

```
1. ExtensionBackupHelper::restoreFromBackup() → 파일 원복
2. status → 이전 상태 복원
3. 에러 로깅 (upgrade 채널)
```

---

## 4. ExtensionStatusGuard

> 파일: `app/Extension/Helpers/ExtensionStatusGuard.php`

### 시그니처

```php
public static function assertNotInProgress(ExtensionStatus $status, string $identifier): void
```

### 차단 매트릭스

| 현재 상태 | 설치 | 삭제 | 활성화 | 비활성화 | 업데이트 |
|----------|------|------|--------|---------|---------|
| Active | - | O | - | O | O |
| Inactive | - | O | O | - | O |
| **Installing** | **차단** | **차단** | **차단** | **차단** | **차단** |
| **Uninstalling** | **차단** | **차단** | **차단** | **차단** | **차단** |
| **Updating** | **차단** | **차단** | **차단** | **차단** | **차단** |
| Uninstalled | O | - | - | - | - |

- `Installing`, `Uninstalling`, `Updating` 상태에서는 모든 작업이 `\RuntimeException`으로 차단됩니다.

---

## 5. ExtensionBackupHelper

> 파일: `app/Extension/Helpers/ExtensionBackupHelper.php`

### 메서드

```php
// 백업 생성 → storage/app/extension_backups/{type}/{identifier}_{timestamp}/
public static function createBackup(string $type, string $identifier): string

// 백업에서 복원 (copyToActive의 원자적 교체 사용)
public static function restoreFromBackup(string $type, string $identifier, string $backupPath): void

// 백업 삭제 (경로 미존재 시 무시)
public static function deleteBackup(string $backupPath): void
```

- `$type`: `'modules'`, `'plugins'`, `'templates'`
- 타임스탬프 형식: `Ymd_His` (예: `20260224_143052`)
- 파일 작업: `Illuminate\Support\Facades\File` 사용

---

## 6. ExtensionPendingHelper

> 파일: `app/Extension/Helpers/ExtensionPendingHelper.php`

### 메서드 (8개)

```php
// _pending 확장 목록 로드
public static function loadPendingExtensions(string $basePath, string $manifestName): array

// _bundled 확장 목록 로드
public static function loadBundledExtensions(string $basePath, string $manifestName): array

// _pending/_bundled → 활성 디렉토리로 원자적 교체 (rename→rename→delete 패턴)
public static function copyToActive(string $sourcePath, string $targetPath): void

// 확장 디렉토리 삭제 (경로 미존재 시 무시)
public static function deleteExtensionDirectory(string $basePath, string $identifier): void

// _pending 디렉토리에 존재 여부
public static function isPending(string $basePath, string $identifier): bool

// _bundled 디렉토리에 존재 여부
public static function isBundled(string $basePath, string $identifier): bool

// _pending 경로 반환
public static function getPendingPath(string $basePath, string $identifier): string

// _bundled 경로 반환
public static function getBundledPath(string $basePath, string $identifier): string
```

- `$basePath`: `base_path('modules')`, `base_path('plugins')`, `base_path('templates')`
- `$manifestName`: `'module.json'`, `'plugin.json'`, `'template.json'`

### copyToActive 원자적 교체 패턴

`copyToActive()`는 기존 대상 디렉토리가 있을 때 **rename→rename→delete** 패턴으로 원자적 교체를 수행합니다.
임시 디렉토리(`_updating_*`, `_old_*`)는 **`_pending/` 하위**에 생성되므로, 잔존 시에도 오토로드에 포함되지 않습니다:

```text
1. source → _pending/{id}_updating_{uid} (File::copyDirectory)  # _pending/ 하위 임시 경로에 복사
2. target → _pending/{id}_old_{uid}      (File::moveDirectory)  # 기존을 _pending/ 하위 _old로 rename
3. _pending/{id}_updating_{uid} → target (File::moveDirectory)  # 임시를 활성으로 rename
4. _pending/{id}_old_{uid} 삭제          (File::deleteDirectory) # 이전 버전 삭제 (실패해도 무해)
```

| 실패 시점 | 결과 |
| --------- | ---- |
| 1단계 실패 (복사) | 기존 디렉토리 **온전히 보존**, `_pending/` 내 임시 디렉토리 정리 후 예외 |
| 2단계 실패 (rename) | 기존 디렉토리 **온전히 보존**, `_pending/` 내 임시만 정리 |
| 3단계 실패 (rename) | `_pending/` 내 _old를 원래 위치로 **롤백**, 예외 |

> **Windows 참고**: `deleteDirectory` 직후 같은 이름으로 `rename`이 실패하는 타이밍 이슈가 있어, delete→copy 대신 rename→rename→delete 패턴을 사용합니다.
> **오토로드 안전성**: 임시 디렉토리가 `_pending/` 하위에 생성되므로, IDE 잠금 등으로 잔존하더라도 `str_starts_with($name, '_')` 필터에 의해 오토로드에서 자동 제외됩니다.

이 메서드는 다음 위치에서 공통으로 사용됩니다:

- `ExtensionBackupHelper::restoreFromBackup()` — 백업 복원
- `ModuleManager::downloadModuleUpdate()` — GitHub 다운로드 교체
- `PluginManager::downloadPluginUpdate()` — 동일
- `TemplateManager::downloadTemplateUpdate()` — 동일

---

## 7. UpgradeStepInterface + UpgradeContext

### UpgradeStepInterface

> 파일: `app/Contracts/Extension/UpgradeStepInterface.php`

```php
interface UpgradeStepInterface
{
    public function run(UpgradeContext $context): void;
}
```

### UpgradeContext

> 파일: `app/Extension/UpgradeContext.php`

```php
// readonly 속성
public readonly LoggerInterface $logger;    // upgrade 로그 채널
public readonly string $fromVersion;        // 업그레이드 시작 버전
public readonly string $toVersion;          // 업그레이드 목표 버전
public readonly string $currentStep;        // 현재 실행 중인 스텝 버전

// 메서드
public function table(string $table): string                // DB 프리픽스 적용 (raw SQL용)
public function withCurrentStep(string $stepVersion): self  // 불변 복제
```

> **raw SQL에서 테이블명 사용 시 `$context->table()` 필수**
> - `Schema::hasColumn('users', ...)`, `DB::table('users')` → 프리픽스 자동 적용 (불필요)
> - `DB::selectOne("SHOW COLUMNS FROM ...")` 등 raw SQL → **`$context->table('users')` 필수**

### 업그레이드 스텝 작성 예시

```php
// modules/vendor-module/upgrades/Upgrade_1_1_0.php
namespace Modules\Vendor\Module\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;

class Upgrade_1_1_0 implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        $context->logger->info("Upgrading from {$context->fromVersion} to {$context->toVersion}");

        // 데이터 마이그레이션 등 버전 특화 작업
    }
}
```

### 정적 메뉴/권한 제거 시 cleanup 사용 예시

확장이 새 버전에서 기존 정적 메뉴/권한을 제거해야 하는 경우, UpgradeStep에서 cleanup helper를 명시적으로 호출합니다.

> **주의**: `cleanupStaleMenus()`/`cleanupStalePermissions()`는 정적 정의 기반으로만 판단하므로, 확장이 런타임에 동적으로 생성한 메뉴/권한도 삭제됩니다. 동적 메뉴/권한이 있는 확장에서는 삭제 대상을 직접 특정하는 것이 안전합니다.

```php
// upgrades/Upgrade_2_0_0.php — 정적 메뉴 정리 예시
use App\Extension\Helpers\ExtensionMenuSyncHelper;
use App\Enums\ExtensionOwnerType;

class Upgrade_2_0_0 implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        $menuHelper = app(ExtensionMenuSyncHelper::class);
        $menuHelper->cleanupStaleMenus(
            ExtensionOwnerType::Module,
            'vendor-module',
            ['menu-slug-1', 'menu-slug-2'], // 유지할 slug 목록
        );
    }
}
```

### 자동 발견 규칙

- 디렉토리: `{extensionPath}/upgrades/`
- 파일 패턴: `Upgrade_X_Y_Z.php` (언더스코어 → 점으로 변환: `1_1_0` → `1.1.0`)
- 실행 순서: `fromVersion < stepVersion <= toVersion` 범위의 스텝만 자연 정렬 순 실행

---

## 8. 번들 디렉토리 개발 워크플로우

### 핵심 원칙

```text
필수: 확장(모듈/플러그인/템플릿) 수정/개발은 _bundled 디렉토리에서만 작업
필수: _bundled 작업 완료 후 반영/검증은 업데이트 프로세스(update 커맨드) 사용
이유: 활성 디렉토리 직접 수정 시 _bundled와 불일치 → 업데이트 시 변경 유실
이유: 업데이트 프로세스를 통해야 마이그레이션, 권한/메뉴 동기화, 레이아웃 갱신 등이 정상 수행됨
```

### 개발 워크플로우 (필수 절차)

```text
┌─────────────────────────────────────────────────────────────────┐
│  1. _bundled 디렉토리에서 코드 수정/개발                         │
│     예: modules/_bundled/sirsoft-ecommerce/src/Services/...     │
│                                                                   │
│  2. manifest 버전 올리기 (module.json / plugin.json / template.json) │
│     예: "version": "1.0.0" → "1.1.0"                           │
│                                                                   │
│  3. 업그레이드 스텝 작성 (조건부 - DB/설정 변경 시)                │
│     예: upgrades/Upgrade_1_1_0.php                              │
│                                                                   │
│  4. 업데이트 커맨드로 활성 디렉토리에 반영                        │
│     php artisan module:update sirsoft-ecommerce                 │
│     php artisan plugin:update sirsoft-payment                   │
│     php artisan template:update sirsoft-admin_basic              │
│                                                                   │
│  5. 업데이트 결과 검증 (테스트 실행)                              │
│     php artisan test --filter=관련테스트                         │
│     powershell -Command "npm run test:run -- 관련테스트"         │
└─────────────────────────────────────────────────────────────────┘
```

### 왜 활성 디렉토리 직접 수정이 금지되는가?

| 문제 | 설명 |
|------|------|
| **변경 유실** | 다음 업데이트 시 _bundled 소스로 덮어쓰기되어 활성 디렉토리의 직접 수정 사항이 사라짐 |
| **동기화 누락** | 권한/역할/메뉴 동기화, 레이아웃 갱신, 마이그레이션 실행 등 업데이트 프로세스의 후처리가 수행되지 않음 |
| **Git 추적 불가** | 활성 디렉토리는 .gitignore 대상이므로 변경 사항이 Git에 기록되지 않음 |
| **환경 불일치** | _bundled(Git 추적)와 활성 디렉토리(Git 미추적)가 불일치하여 다른 환경에서 재현 불가 |
| **업데이트 감지 불가** | 활성 디렉토리만 수정하고 _bundled를 미갱신하면 check-updates에서 변경을 감지하지 못함 |

### 확장 타입별 업데이트 커맨드

| 확장 타입 | 업데이트 확인 | 업데이트 실행 | 강제 재설치 |
| -------- | ----------- | ----------- | ---------- |
| 모듈 | `php artisan module:check-updates sirsoft-ecommerce` | `php artisan module:update sirsoft-ecommerce` | `php artisan module:update sirsoft-ecommerce --force` |
| 플러그인 | `php artisan plugin:check-updates sirsoft-payment` | `php artisan plugin:update sirsoft-payment` | `php artisan plugin:update sirsoft-payment --force` |
| 템플릿 | `php artisan template:check-updates sirsoft-admin_basic` | `php artisan template:update sirsoft-admin_basic` | `php artisan template:update sirsoft-admin_basic --force` |

### 예외: 초기 개발 (아직 _bundled에 미등록)

신규 확장을 처음 개발할 때는 활성 디렉토리에서 직접 작업할 수 있습니다. 단, **최초 개발 완료 후 _bundled에 반영하는 시점부터** 이 규칙이 적용됩니다.

```text
허용: 신규 확장 초기 개발 시 활성 디렉토리에서 직접 작업
전환점: _bundled에 최초 반영한 이후부터는 반드시 _bundled에서만 작업
전환 후: 활성 디렉토리 직접 수정 금지 → 업데이트 프로세스만 사용
```

---

## 9. 개발자 버전 업데이트 가이드

### 코드 변경 시 버전/업그레이드 필수 규칙

```text
필수: 확장(모듈/플러그인/템플릿)의 코드를 변경한 경우 아래 절차를 수행해야 합니다.
버전 변경 없이 코드만 수정하면, 이미 설치된 환경에서 업데이트가 감지되지 않습니다.
```

#### 필수 체크리스트

| # | 항목 | 모듈 | 플러그인 | 템플릿 |
|---|------|------|---------|--------|
| 1 | manifest 버전 올리기 (`module.json` / `plugin.json` / `template.json`) | ✅ 필수 | ✅ 필수 | ✅ 필수 |
| 2 | `_bundled` 디렉토리 동기화 (활성 디렉토리와 동일하게) | ✅ 필수 | ✅ 필수 | ✅ 필수 |
| 3 | 업그레이드 스텝 작성 (`upgrades/Upgrade_X_Y_Z.php`) | 조건부 | 조건부 | 해당 없음 |
| 4 | DB 마이그레이션 추가 (`database/migrations/`) | 조건부 | 조건부 | 해당 없음 |

#### 업그레이드 스텝이 필요한 경우 (모듈/플러그인만)

다음 중 하나라도 해당하면 `upgrades/Upgrade_X_Y_Z.php`를 작성해야 합니다:

| 변경 유형 | 업그레이드 스텝 필요 | 예시 |
|----------|-------------------|------|
| DB 스키마 변경 | ✅ (+ 마이그레이션) | 컬럼 추가/삭제, 테이블 구조 변경 |
| 환경설정 구조 변경 | ✅ (SettingsMigrator 사용) | 설정 키 이름 변경, 새 카테고리 추가 |
| 기존 데이터 마이그레이션 | ✅ | 데이터 형식 변환, 기본값 채우기 |
| 권한/역할/메뉴 추가·수정 | ❌ (자동 동기화) | 새 권한 추가, 메뉴 순서 변경 |
| 정적 권한/메뉴 제거 | ✅ (cleanup 명시 호출) | 기존 메뉴/권한 삭제 |
| 레이아웃 JSON 변경 | ❌ (자동 갱신) | 레이아웃 UI 수정, 새 레이아웃 추가 |
| PHP 코드만 변경 (API 호환) | ❌ | 버그 수정, 성능 개선 |

#### 버전 올리기 않아도 되는 경우

- `_bundled` 디렉토리에 아직 반영되지 않은 개발 중 변경 (활성 디렉토리에서만 작업)
- 테스트 파일만 변경
- 규정 문서만 변경

### 새 버전 배포 절차

1. **manifest 버전 수정**: `module.json` / `plugin.json` / `template.json`의 `version` 필드 변경
2. **업그레이드 스텝 작성** (필요 시): `upgrades/Upgrade_X_Y_Z.php` 생성
3. **_bundled 업데이트**: Git 저장소의 `_bundled/{identifier}/` 디렉토리 갱신
4. **GitHub 릴리스** (선택): `github_url` 설정 시 GitHub releases로 자동 감지

### manifest 수정 항목

```json
{
    "version": "1.2.0",
    "github_changelog_url": "https://github.com/vendor/module/releases/tag/engine-v1.2.0"
}
```

---

## 10. 템플릿 레이아웃 충돌 전략

템플릿 업데이트 시 관리자가 수정한 레이아웃이 있으면 충돌이 발생합니다.

### 사전 확인 API

```
GET /api/admin/templates/{templateName}/check-modified-layouts
```

응답:
```json
{
    "has_modified": true,
    "modified_layouts": [
        { "id": 1, "name": "dashboard", "updated_at": "2026-02-24T10:00:00Z" }
    ]
}
```

### 충돌 해결 전략

| 전략 | 동작 |
|------|------|
| `apply_new` (기본값) | 새 버전의 레이아웃으로 덮어쓰기 (관리자 수정 사항 유실) |
| `keep_current` | 기존 레이아웃 유지 (새 버전 레이아웃 미적용) |

업데이트 API에 `layout_strategy` 파라미터로 전달:

```
POST /api/admin/templates/{templateName}/update
Body: { "layout_strategy": "apply_new" }
```

---

## 11. Artisan 커맨드

| 커맨드 | 설명 |
|--------|------|
| `module:check-updates [identifier?]` | 모듈 업데이트 확인 (전체 또는 단일) |
| `module:update [identifier] [--force]` | 모듈 업데이트 실행 |
| `plugin:check-updates [identifier?]` | 플러그인 업데이트 확인 (전체 또는 단일) |
| `plugin:update [identifier] [--force]` | 플러그인 업데이트 실행 |
| `template:check-updates [identifier?]` | 템플릿 업데이트 확인 (전체 또는 단일) |
| `template:update [identifier] [--layout-strategy=overwrite] [--force]` | 템플릿 업데이트 실행 |

> 단일 `check-updates`는 `checkXxxUpdate()` 호출 후 커맨드에서 DB 갱신 (`updateByIdentifier()`)
> 템플릿 `update`만 `--layout-strategy` 옵션 지원 (`overwrite` | `keep`)
> `--force`: 버전 비교를 건너뛰고 강제 재설치 (파일 손상, 수동 수정 복원 시 사용)

---

## 12. API 엔드포인트

### 모듈

| 메서드 | 경로 | 권한 | 설명 |
|--------|------|------|------|
| POST | `/api/admin/modules/check-updates` | `core.modules.install` | 모듈 업데이트 일괄 확인 |
| POST | `/api/admin/modules/{moduleName}/update` | `core.modules.install` | 모듈 업데이트 실행 |

### 플러그인

| 메서드 | 경로 | 권한 | 설명 |
|--------|------|------|------|
| POST | `/api/admin/plugins/check-updates` | `core.plugins.install` | 플러그인 업데이트 일괄 확인 |
| POST | `/api/admin/plugins/{pluginName}/update` | `core.plugins.install` | 플러그인 업데이트 실행 |

### 템플릿

| 메서드 | 경로 | 권한 | 설명 |
|--------|------|------|------|
| POST | `/api/admin/templates/check-updates` | `core.templates.install` | 템플릿 업데이트 일괄 확인 |
| GET | `/api/admin/templates/{templateName}/check-modified-layouts` | `core.templates.read` | 수정된 레이아웃 확인 |
| POST | `/api/admin/templates/{templateName}/update` | `core.templates.install` | 템플릿 업데이트 실행 |

### 훅 (Hook)

| 훅 이름 | 시점 |
|---------|------|
| `core.modules.before_check_updates` | 모듈 업데이트 확인 전 |
| `core.modules.after_check_updates` | 모듈 업데이트 확인 후 |
| `core.modules.before_update` | 모듈 업데이트 실행 전 |
| `core.modules.after_update` | 모듈 업데이트 실행 후 |
| `core.plugins.before_check_updates` | 플러그인 업데이트 확인 전 |
| `core.plugins.after_check_updates` | 플러그인 업데이트 확인 후 |
| `core.plugins.before_update` | 플러그인 업데이트 실행 전 |
| `core.plugins.after_update` | 플러그인 업데이트 실행 후 |
| `core.templates.before_check_updates` | 템플릿 업데이트 확인 전 |
| `core.templates.after_check_updates` | 템플릿 업데이트 확인 후 |
| `core.templates.before_version_update` | 템플릿 업데이트 실행 전 |
| `core.templates.after_version_update` | 템플릿 업데이트 실행 후 |

---

## 13. 역할/권한/메뉴 동기화

> 파일: `app/Extension/Helpers/ExtensionRoleSyncHelper.php`, `app/Extension/Helpers/ExtensionMenuSyncHelper.php`

업데이트 실행 흐름 6단계에서 Roles, Permissions, Menus를 동기화할 때, 사용자 커스터마이징을 보존하면서 안전하게 갱신합니다.

### user_overrides 패턴

각 모델(Role, Menu)에 `user_overrides` JSON 컬럼이 존재합니다. 유저가 수정한 필드명 또는 개별 식별자를 기록하고, 확장 재설치/업데이트 시 **기록된 항목만 보호**합니다.

```text
유저 수정 시: Listener가 user_overrides에 변경 내용 자동 기록
  - 필드 변경 (name, icon 등) → 필드명 기록: ["name", "icon"]
  - 권한 변경 → 개별 권한 식별자 기록: ["sirsoft-board.boards.delete"]
  - 역할 변경 → 개별 역할 식별자 기록: ["editor"]
  - 순서 변경 → "order" 기록: ["order"]

확장 업데이트 시: user_overrides에 기록된 항목만 보호, 나머지 정상 갱신
```

### 역할/권한 동기화 (ExtensionRoleSyncHelper)

- **syncRole()**: `user_overrides`에 기록된 필드(name, description)만 건너뛰고 나머지 갱신
- **syncPermission()**: 항상 확장 정의값으로 덮어쓰기 (Permission은 user_overrides 없음)
- **syncRolePermissions()**: `user_overrides`에 기록된 **개별 권한 식별자**만 보호, 나머지 DB 기반 diff로 attach/detach
- **cleanupStalePermissions()**: 확장에서 제거된 권한 삭제 + 역할 연결 해제 (**자동 호출 폐기 #135** — UpgradeStep에서 명시적 호출 전용)

### 역할-권한 할당 동기화 (syncAllRoleAssignments)

```text
주의: DB 기반 diff + 개별 권한 식별자 보호

유저가 admin에서 boards.delete 해제 → user_overrides = ["sirsoft-board.boards.delete"]
확장 v1.1 업데이트:

→ DB에서 현재 확장 권한 조회
→ user_overrides에서 권한 식별자만 필터 (필드명 "name" 등 제외)
→ 보호된 권한(boards.delete)은 attach/detach 계산에서 제외
→ 나머지 권한은 정의 기준으로 정상 동기화
```

| 시나리오 | 동작 |
| -------- | ---- |
| 최초 설치 (user_overrides=[]) | 정의된 모든 권한 attach |
| 업데이트 (보호 권한 있음) | 보호 권한 제외, 나머지 diff 동기화 |
| 확장이 새 권한 추가 | 비보호 → attach, 보호 → 건너뜀 |
| 확장에서 권한 제거 | 비보호 → detach, 보호 → 건너뜀 |
| 유저 수동 추가 할당 (비확장 권한) | 영향 없음 (확장 권한만 대상) |

### 메뉴 동기화 (ExtensionMenuSyncHelper)

- **비교 필드**: `user_overrides`에 기록된 필드만 건너뜀 (name, icon, order, url 중)
- **항상 확장 정의값**: parent_id, is_active (사용자 보존 대상 아님)
- **syncMenuRecursive()**: 재귀 구조 처리 + 다국어 name 역호환
- **cleanupStaleMenus()**: 자식 포함 cascade 삭제 (**자동 호출 폐기 #135** — UpgradeStep에서 명시적 호출 전용)
- **메뉴-역할 할당**: `user_overrides`에 기록된 **개별 역할 식별자**만 보호

```text
예시: 유저가 name과 editor 역할을 변경
→ user_overrides = ["name", "editor"]

모듈 v1.1 업데이트:
  → name: user_overrides에 있음 → 보존
  → order: user_overrides에 없음 → 새 값 반영
  → icon: user_overrides에 없음 → 새 값 반영
  → 역할 editor: 보호 → 유저 설정 유지
  → 역할 admin: 비보호 → 정상 동기화
```

### user_overrides 기록 리스너

| 리스너 | 구독 훅 | 기록 내용 |
| ------ | ------- | --------- |
| `RoleUserOverridesListener` | `core.role.before_update` | 변경된 필드명 (name, description) |
| `RoleUserOverridesListener` | `core.role.after_sync_permissions` | 변경된 개별 권한 식별자 |
| `MenuUserOverridesListener` | `core.menu.before_update` | 변경된 필드명 (name, icon, order, url) |
| `MenuUserOverridesListener` | `core.menu.after_update_order` | 순서 변경된 메뉴에 "order" |
| `MenuUserOverridesListener` | `core.menu.after_sync_roles` | 변경된 개별 역할 식별자 |

---

## 14. SettingsMigrator

> 파일: `app/Extension/Helpers/SettingsMigrator.php`

UpgradeStep 내에서 환경설정 파일을 안전하게 마이그레이션하는 유틸리티 클래스.

### 사용법

```php
class Upgrade_1_3_0 implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        $result = SettingsMigrator::forModule('sirsoft-ecommerce')
            ->addField('shipping.tracking_enabled', false)
            ->renameField('order_settings.auto_cancel_days', 'order_settings.pending_cancel_days')
            ->removeField('seo.deprecated_field')
            ->addCategory('notifications', ['email_enabled' => true])
            ->apply();

        $context->logger->info('Settings migrated', $result);
    }
}
```

### 오퍼레이션

| 메서드 | 동작 |
|--------|------|
| `addField(path, default)` | 새 필드 추가 (이미 존재하면 **스킵** — 사용자 값 보존) |
| `renameField(old, new)` | 필드 이름 변경 (이전 키 값을 새 키로 이동) |
| `removeField(path)` | 필드 제거 (존재하지 않으면 no-op) |
| `transformField(path, callable)` | 값 변환 (존재하지 않으면 스킵) |
| `addCategory(category, defaults)` | 새 카테고리 파일 생성 (모듈 전용) |
| `apply()` | 모든 변환 실행, `['applied' => int, 'skipped' => int, 'errors' => []]` 반환 |

### 경로 규칙 (dot notation)

- 모듈: `'category.field'` → `storage/app/modules/{id}/settings/{category}.json` 내 `field` 키
- 플러그인: `'field'` → `storage/app/plugins/{id}/settings/setting.json` 내 `field` 키

---

## 15. 큐 워커 재시작 + 스케줄 등록

### 큐 워커 재시작

> 파일: `app/Listeners/ExtensionUpdateQueueListener.php`

확장 업데이트 성공 시 `Artisan::call('queue:restart')` 자동 실행. 코드 변경 후 큐 워커가 이전 코드로 실행되는 문제를 방지합니다.

- 구독 훅: `core.modules.after_update`, `core.plugins.after_update` (priority 100)
- 실패 시: warning 로그만 기록 (예외 전파 안 함)
- 자동 등록: `CoreServiceProvider::registerCoreHookListeners()` 스캔

### 확장 스케줄 등록

> 파일: `routes/console.php`

활성 모듈/플러그인의 `getSchedules()` 메서드를 읽어 Laravel 스케줄러에 자동 등록합니다.

```php
// module.php에서 정의
public function getSchedules(): array
{
    return [
        [
            'command' => 'sirsoft-ecommerce:sync-stock',
            'schedule' => 'hourly',           // 또는 cron: '*/30 * * * *'
            'description' => '재고 동기화',
            'enabled_config' => 'sirsoft-ecommerce.stock.auto_sync',
        ],
    ];
}
```

- cron expression vs 메서드명 자동 분기
- `enabled_config`: 확장 설정값으로 조건부 활성화

---

## 참고 파일 위치

| 파일 | 경로 |
|------|------|
| ExtensionStatusGuard | `app/Extension/Helpers/ExtensionStatusGuard.php` |
| ExtensionBackupHelper | `app/Extension/Helpers/ExtensionBackupHelper.php` |
| ExtensionPendingHelper | `app/Extension/Helpers/ExtensionPendingHelper.php` |
| ExtensionRoleSyncHelper | `app/Extension/Helpers/ExtensionRoleSyncHelper.php` |
| ExtensionMenuSyncHelper | `app/Extension/Helpers/ExtensionMenuSyncHelper.php` |
| SettingsMigrator | `app/Extension/Helpers/SettingsMigrator.php` |
| ExtensionUpdateQueueListener | `app/Listeners/ExtensionUpdateQueueListener.php` |
| UpgradeStepInterface | `app/Contracts/Extension/UpgradeStepInterface.php` |
| UpgradeContext | `app/Extension/UpgradeContext.php` |
| ExtensionStatus | `app/Enums/ExtensionStatus.php` |
| ModuleManager | `app/Extension/ModuleManager.php` |
| PluginManager | `app/Extension/PluginManager.php` |
| TemplateManager | `app/Extension/TemplateManager.php` |
| logging.php (upgrade 채널) | `config/logging.php` |
