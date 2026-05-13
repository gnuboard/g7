# 코어 업데이트 시스템 (Core Update System)

> 코어 버전 업데이트의 감지, 다운로드, 적용, 롤백, 업그레이드 스텝 실행을 관리하는 시스템

## TL;DR (5초 요약)

```text
1. 코어 업그레이드 스텝: upgrades/ 디렉토리 (프로젝트 루트), 네임스페이스 App\Upgrades
2. 마이그레이션 = 스키마 변경만, 데이터 백필/변환 = 업그레이드 스텝 (역할 분리 필수)
3. 업데이트 실행 흐름: 11단계 (감지 → 다운로드 → 백업 → 적용 → 마이그레이션 → 동기화 → 업그레이드 → 마무리)
4. 롤백: CoreBackupHelper로 백업 생성, 실패 시 자동 복원
5. 부트스트랩 호환성 검증: .env의 APP_VERSION < 확장 g7_version 시 자동 비활성화 (1시간 캐시)
```

---

## 실행 환경 전제 (CRITICAL)

```text
⚠️ core:update 는 SSH/CLI 환경에서 코어 파일 소유자(보통 FTP/SSH 사용자)가
   직접 실행한다고 가정합니다.
```

### 권한 모델

| 실행 환경 | 권한 가정 | 권장 사용 |
|----------|----------|----------|
| **SSH/CLI 직접 실행** | 사용자 = 파일 소유자 → 모든 코어 파일 쓰기 가능 | ✅ 정상 사용 (권장) |
| **웹 인스톨러 (`public/install/`)** | PHP-FPM 사용자 (www-data 등) — 권한 제한적 | ⚠️ vendor 번들 모드로만 신규 설치 가능. 코어 업데이트는 미지원 |
| **www-data 로 CLI 실행** | 코어 파일 소유자와 다를 수 있음 | ⚠️ 비권장 — 권한 거부 가능 |

### 권한 거부 발생 시 대처

`core:update` 시작 시 현재 실행 사용자 UID와 코어 파일(`composer.json`) 소유자 UID를 비교하여 불일치 시 경고 로그를 남깁니다. 다음과 같은 경우 발생할 수 있습니다:

1. **vendor/ 가 다른 사용자 소유**: 과거에 웹 인스톨러로 설치되어 vendor/ 가 www-data 소유인 상태에서 SSH 사용자로 `core:update` 실행
   - **해결**: `chown -R $(whoami) vendor/` 후 재시도

2. **코어 파일이 다른 사용자 소유**: FTP 사용자와 SSH 사용자가 다른 환경
   - **해결**: 호스팅 제공자에게 권한 정리 요청 또는 수동 업데이트 수행

### 웹 인스톨러는 별개

웹 인스톨러(`public/install/`)는 PHP-FPM 사용자로 실행되므로 권한 제약이 큽니다. vendor 번들 시스템(244 이슈)으로 신규 설치 시점의 vendor/ 권한 이슈는 해소되었으나, **코어 업데이트는 웹에서 지원하지 않으며 SSH/CLI 로만 수행해야 합니다**.

---

## 목차

1. [업데이트 감지](#1-업데이트-감지)
2. [업데이트 실행 흐름 (11단계)](#2-업데이트-실행-흐름-11단계)
3. [백업 및 롤백](#3-백업-및-롤백)
4. [마이그레이션 vs 업그레이드 스텝](#4-마이그레이션-vs-업그레이드-스텝)
5. [업그레이드 스텝 작성](#5-업그레이드-스텝-작성)
6. [자동 발견 및 실행 규칙](#6-자동-발견-및-실행-규칙)
7. [버전 관리](#7-버전-관리)
8. [CoreUpdateService 주요 메서드](#8-coreupdateservice-주요-메서드)
9. [Artisan 커맨드](#9-artisan-커맨드)
10. [API 엔드포인트](#10-api-엔드포인트)
11. [설정 (config/app.php)](#11-설정-configappphp)
12. [에러 처리 및 로깅](#12-에러-처리-및-로깅)
13. [유지보수 모드](#13-유지보수-모드)
14. [코어 vs 확장 업데이트 비교](#14-코어-vs-확장-업데이트-비교)

---

## 1. 업데이트 감지

> 파일: `app/Extension/CoreVersionChecker.php`, `app/Services/CoreUpdateService.php`

### 감지 흐름

```text
1. CoreVersionChecker::getCoreVersion() → config('app.version') 읽기
2. CoreUpdateService::checkForUpdates() → GitHub API로 최신 릴리스 조회
3. version_compare(current, latest) → 업데이트 가용 여부 판단
4. 원격 CHANGELOG 캐시 → storage/app/temp/core_remote_changelog.md
```

### GitHub API 통합

```text
- config('app.update.github_url') 에서 리포지토리 URL 읽기
- config('app.update.github_token') 으로 인증 (선택)
- GitHub Releases API 호출 → 최신 릴리스 태그에서 버전 추출
- 모든 원격 HTTP 호출은 GithubHelper (Laravel Http 파사드 기반) 사용
  → file_get_contents + stream_context_create 금지 (allow_url_fopen=Off 환경 대응)
```

### 감지 결과

```php
[
    'update_available' => true,
    'current_version'  => '7.0.0-alpha.14',
    'latest_version'   => '7.0.0-alpha.15',
    'check_failed'     => false,        // GitHub API 실패 시 true
]
```

---

## 2. 업데이트 실행 흐름 (11단계)

> 파일: `app/Console/Commands/Core/CoreUpdateCommand.php`

```text
┌─ Step 1:  업데이트 확인 (GitHub API 또는 --source/--local 모드)
├─ Step 2:  _pending 경로 검증 (디렉토리 존재/쓰기 권한)
├─ Step 3:  유지보수 모드 활성화 (--no-maintenance 시 스킵)
├─ Step 4:  다운로드 (GitHub zipball 또는 --source/--local에서 복사)
├─ Step 5:  백업 생성 (--no-backup 시 스킵)
├─ Step 6:  Composer install (_pending에서 실행, 변경 없으면 스킵)
├─ Step 7:  파일 적용 (_pending → base_path 선택적 덮어쓰기)
├─ Step 8:  vendor 복사 (_pending/vendor → base_path/vendor, Step 6 스킵 시 함께 스킵)
├─ Step 9:  마이그레이션 + 동기화 (migrate, roles, permissions, menus, mail templates)
├─ Step 10: 업그레이드 스텝 실행 (upgrades/Upgrade_X_Y_Z.php)
└─ Step 11: 마무리 (.env 버전 갱신, 캐시 클리어, _pending 삭제, 유지보수 해제)
```

### Step 1: 업데이트 확인

| 모드 | 동작 |
|------|------|
| 일반 | `checkForUpdates()` → GitHub API 호출 → 사용자 확인 프롬프트 |
| `--source={path}` | 지정된 디렉토리를 소스로 사용 (GitHub 스킵) |
| `--zip={path}` | 지정된 ZIP 파일을 추출하여 소스로 사용 (GitHub 스킵). 추출 후 `config/app.php` 에서 버전 자동 판별. ZIP 구조가 GitHub zipball 의 `owner-repo-hash/` 래퍼이든 평탄 루트이든 모두 지원 |
| `--local` | 현재 코드베이스를 소스로 사용 (GitHub 스킵) |
| `--force` | 버전 비교 스킵, 동일 버전이어도 강제 실행 |
| `--vendor-mode=auto\|composer\|bundled` | vendor 설치 모드 지정 (기본 `auto` — composer 가능 시 composer, 불가 시 vendor-bundle.zip 추출). `bundled` 강제 시 공유 호스팅에서도 설치 가능 |

> `--source` / `--zip` / `--local` 은 **상호 배타적**입니다. 동시 지정 시 커맨드는 시작 전에 FAILURE(1) 로 종료됩니다.

### Step 4: 다운로드 및 추출

```text
추출 전략 (폴백 체인):
1. ZipArchive (PHP zip 확장) → class_exists(ZipArchive::class)
2. unzip CLI 명령어 → ZipArchive 미사용 시 폴백

v접두사 자동 감지 (resolveGithubArchiveUrl):
1. "v{version}" 태그로 HEAD 요청 시도 (예: v7.0.0-alpha.15)
2. 실패 시 "{version}" 태그로 HEAD 요청 시도 (예: 7.0.0-alpha.15)
3. HTTP 200/302 → 유효한 URL 반환
4. 모두 실패 → null (업데이트 불가)
```

### Step 5: 백업

```text
- CoreBackupHelper::createBackup() 사용
- 백업 대상: config('app.update.targets') + config('app.update.backup_only') + config('app.update.backup_extra')
- 제외 패턴: config('app.update.excludes')
- 백업 위치: storage 경로에 타임스탬프 디렉토리
```

### Step 6: Vendor 설치 (Composer 또는 Bundled)

`CoreUpdateService::runVendorInstallInPending()` 가 `VendorResolver` 경유로 모드에 따라 분기합니다.

```text
1. --vendor-mode 결정:
   - composer 명시 / bundled 명시 → 그대로 사용
   - auto (기본) → EnvironmentDetector 로 composer 실행 가능 여부 자동 감지

2. Composer 모드 (기존 흐름):
   - composer.json + composer.lock 의 MD5 비교 (_pending vs base_path)
   - 동일 → "composer 의존성 변경 없음 — 스킵" (Step 6 + Step 8 모두 스킵)
   - 변경됨 → composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

3. Bundled 모드 (신규, 공유 호스팅 대응):
   - _pending/vendor-bundle.zip 무결성 검증 (SHA256)
   - VendorBundleInstaller::install() 로 zip 추출 → _pending/vendor/ 생성
   - 기존 vendor/ 를 vendor.old.{timestamp} 로 rename 후 추출 → 실패 시 복구

4. --no-scripts: post-autoload-dump 방지 (Step 11에서 package:discover로 처리)
```

> Vendor 번들 시스템 상세: [docs/extension/vendor-bundle.md](../extension/vendor-bundle.md)

### Step 7: 파일 적용

```text
- _pending 소스에서 config('app.update.targets') 에 해당하는 파일/디렉토리 선택적 덮어쓰기
- 자동 발견 폴백: targets 에 미등재된 source 최상위 항목도 스캔하여 적용
  (config('app.update.protected_paths') 와 config('app.update.excludes') 매치 시 스킵)
- FilePermissionHelper::copyDirectory() 사용 → 원본 파일 권한 보존
- ExtensionPendingHelper::copyToActive() 미사용 (권한 유실 방지)
```

> **자동 발견 폴백의 배경 (engine-v / beta.4 이후)**: Step 7 은 부모 프로세스의 `config('app.update.targets')` 를 사용한다. 부모는 업그레이드 *직전* 의 코드/메모리 상태이므로 신버전이 도입한 신규 최상위 디렉토리(예: beta.4 의 `lang-packs/`) 가 부모의 stale targets 에서 누락된다. 폴백은 이 결함을 안전망으로 차단하며, `config/app.php` 의 `update.protected_paths` 가 런타임 데이터(`storage`)·로컬 환경(`.env*`)·별도 파이프라인 산출물(`vendor`)·개발 메타(`.git`/`.claude`/`.serena` 등) 의 의도치 않은 덮어쓰기를 방지한다.

### Step 9: 마이그레이션 + 동기화

```text
1. php artisan migrate --force
2. syncCoreRolesAndPermissions() — config/core.php의 roles/permissions 동기화
3. syncCoreMenus() — config/core.php의 menus 동기화
```

> **변경 이력 (7.0.0-beta.2)**: `syncCoreMailTemplates()` 단계는 알림 시스템 통합(#146)으로 제거되었습니다. 메일 템플릿은 `notification_definitions` + `notification_templates` 로 통합되었으며, `Upgrade_7_0_0_beta_2` 가 운영 환경 데이터 이관과 알림 정의 시드를 처리합니다.

> 역할/권한/메뉴 동기화는 `user_overrides` 패턴을 사용하여 사용자 커스터마이징 보존.
> 상세: [extension-update-system.md § 13](../extension/extension-update-system.md#13-역할권한메뉴-동기화)

### Step 10: 업그레이드 스텝

```text
1. upgrades/ 디렉토리 스캔
2. Upgrade_X_Y_Z.php 패턴 매칭 → 버전 변환
3. fromVersion < stepVersion <= toVersion 범위 필터링
4. version_compare 자연 정렬 (오름차순)
5. 각 스텝 순차 실행 (UpgradeContext 전달)
```

> **spawn 자식 진입 시 PSR-4 autoload 갱신 (engine-v / beta.4 이후)**: `core:execute-upgrade-steps` (Step 10) 와 `core:execute-bundled-updates` (Step 12) 의 spawn 자식은 `handle()` 진입 직후 `app(ExtensionManager::class)->updateComposerAutoload()` 를 1회 호출한다. 부모 프로세스의 `bootstrap/cache/autoload-extensions.php` 가 stale 한 경우 자식이 그 매핑을 그대로 로드 → upgrade step 또는 bundled update 안에서 모듈/플러그인의 `Models`/`Services` 같은 다른 클래스를 lazy autoload 시 "Class not found" 발생. 진입 시점 1회 호출로 모든 후속 작업이 fresh autoload 환경에서 실행됨을 보장한다 (개별 step 마다 호출할 필요 없음). 본 진입점들은 자체가 spawn 자식 (별개 PHP 프로세스) 이라 디스크의 fresh `ExtensionManager` 클래스를 메모리에 로드한 상태 — 직접 메서드 호출도 stale 가능성 없음.

### Step 11: 마무리

```text
1. .env의 APP_VERSION 갱신
2. 캐시 클리어: config, cache, route, view
3. bootstrap/cache 파일 삭제 (services.php, packages.php)
4. php artisan package:discover 재실행
5. php artisan extension:update-autoload (코어 업데이트로 _bundled 변경 가능)
6. _pending 디렉토리 삭제
7. 성공 시 백업 삭제
8. 유지보수 모드 해제
```

### Step 12: _bundled 확장 일괄 업데이트 프롬프트 (인터랙티브)

> Trait: `App\Console\Commands\Core\Concerns\BundledExtensionUpdatePrompt`

코어 업데이트 완료 후, 번들(`_bundled/`) 에 설치된 확장보다 새 버전이 포함된 경우 일괄 업데이트를 제안합니다.

```text
1. checkAllModulesForUpdates() / checkAllPluginsForUpdates() / checkAllTemplatesForUpdates() 호출
2. update_source === 'bundled' 항목만 수집
3. 감지 결과 없으면 "활성 확장이 최신 번들과 일치합니다" 출력 후 종료
4. 감지 결과 있으면 목록 표시 + 일괄 업데이트 여부 확인 (기본값 yes)
5. 동의 시:
   - 전역 레이아웃 전략 선택 (overwrite | keep)  ※ 섹션 10 참조
   - 예외 확장 지정 여부 확인, yes 이면 다중 선택으로 전략 오버라이드
   - 매니페스트 JSON 직렬화 → `core:execute-bundled-updates` spawn 자식 실행
6. 결과 요약 출력 (성공/실패 건수)

--force 플래그가 코어 업데이트에 지정된 경우: 프롬프트 스킵 + 전역 overwrite 자동 적용 (CI 대응)
```

> **spawn 위임의 배경 (beta.4 이후)**: 부모(`core:update`) 프로세스는 코어 업그레이드 *직전* 버전의 메모리 상태를 보유한다. 부모가 직접 `ModuleManager::updateModule()` 등을 호출하면 신버전 코어가 도입한 sync 메서드(예: `syncModuleIdentityPolicies` `@since beta.4`)가 메모리에 부재해 호출 자체가 누락된다. `executeBulkUpdate` 는 사용자 선택만 매니페스트로 직렬화한 뒤 `proc_open` 으로 `core:execute-bundled-updates` 를 spawn — 자식은 fresh PHP 프로세스라 디스크의 신버전 코어 코드를 메모리에 로드한 상태에서 update 메서드 호출. proc_open 미지원 환경에서는 in-process fallback 으로 안전 전환. 자식 stdout 은 부모 콘솔로 forwarding 하며, 종료 직전 `[BUNDLED-RESULT]` 표식 라인으로 결과 카운트를 회신한다.

> **선언형 산출물 일괄 sync — `syncDeclarativeArtifacts`**: `ModuleManager::syncDeclarativeArtifacts(ModuleInterface)` / `PluginManager::syncDeclarativeArtifacts(PluginInterface)` 는 모듈/플러그인의 모든 declarative 데이터(역할·권한·메뉴·cleanup·IDV 정책·IDV 메시지·알림 정의) 를 한 묶음으로 동기화하는 public 진입점이다. `installModule` / `updateModule` 트랜잭션이 내부적으로 호출하며, 코어 업그레이드 사후 보정(`Upgrade_7_0_0_beta_4` 등) 도 활성 모듈/플러그인을 순회하며 이 메서드를 호출해 일회성 회복을 수행한다. 각 sync 메서드는 helper 의 user_overrides 보존 패턴을 따르므로 정상 환경 재호출 무해 (멱등).

---

## 3. 백업 및 롤백

> 파일: `app/Extension/Helpers/CoreBackupHelper.php`

### 백업 생성

```text
- CoreBackupHelper::createBackup() 호출
- 대상: config('app.update.targets') + config('app.update.backup_only') + config('app.update.backup_extra')
- 제외: config('app.update.excludes')
- 저장: storage 경로에 타임스탬프 디렉토리 (Ymd_His)
```

### 롤백 흐름 (실패 시)

```text
1. Exception 발생
2. 백업이 존재하면:
   ├─ CoreUpdateService::restoreFromBackup($backupPath)
   ├─ CoreBackupHelper::restoreFromBackup() → 파일 원복
   └─ _pending 디렉토리 삭제
3. 실패 보고서 생성: storage/logs/core_update_failure_YYYYMMDD_HHMMSS.log
4. 업데이트 로그 저장: storage/logs/core_update_failed_YYYYMMDD_HHMMSS.log
5. 유지보수 모드 유지 (수동으로 php artisan up 필요)
```

### 롤백 실패 시

```text
- 복원 실패 자체는 예외를 전파하지 않음 (에러 로그만 기록)
- 관리자에게 안내: "이전 버전으로 사이트를 운영하려면: php artisan up"
- 유지보수 모드 bypass secret 출력
```

---

## 4. 마이그레이션 vs 업그레이드 스텝

```text
주의: 마이그레이션과 업그레이드 스텝의 역할을 혼동하지 않을 것
```

| 구분 | 마이그레이션 (`database/migrations/`) | 업그레이드 스텝 (`upgrades/`) |
|------|--------------------------------------|-------------------------------|
| **역할** | DB 스키마 변경 (컬럼 추가/삭제/변경, 인덱스, 테이블) | 데이터 백필/변환, 설정 마이그레이션 |
| **실행 시점** | `php artisan migrate` (설치/업데이트 모두) | `php artisan core:update` Step 10 (업데이트 시만) |
| **신규 설치** | 실행됨 | 실행되지 않음 (fromVersion == toVersion) |
| **버전 업그레이드** | 실행됨 | 실행됨 (범위 내 스텝만) |
| **예시** | `$table->uuid('uuid')->nullable()->after('id')` | 기존 레코드 UUID 백필 + NOT NULL 변환 |

### 역할 분리 원칙

```text
✅ 마이그레이션: 테이블/컬럼/인덱스 생성·변경·삭제 (스키마)
✅ 업그레이드 스텝: 기존 데이터 변환, 백필, 설정 구조 변경 (데이터)

❌ 마이그레이션에 데이터 백필 로직 포함 금지
❌ 업그레이드 스텝에 스키마 변경 포함 금지 (Schema::table 등)
   단, 백필 후 NOT NULL 제약 추가처럼 데이터 완결성에 필수인 경우는 예외
```

### 신규 설치 시 데이터 초기화

신규 설치 시 업그레이드 스텝은 실행되지 않으므로, 모델의 `boot()` 이벤트나 Seeder에서 초기 데이터를 생성해야 합니다.

```php
// 예: User 모델 — 신규 레코드 생성 시 UUID 자동 할당
protected static function boot(): void
{
    parent::boot();

    static::creating(function (self $user) {
        if (empty($user->uuid)) {
            $user->uuid = app(UniqueIdServiceInterface::class)->generateUuid();
        }
    });
}
```

### 업그레이드 스텝이 필요한 경우

| 변경 유형 | 업그레이드 스텝 필요 | 예시 |
|----------|-------------------|------|
| 기존 데이터 백필/변환 | ✅ | UUID 백필, 형식 변환 |
| NOT NULL 제약 추가 (백필 후) | ✅ (예외) | 스키마이지만 데이터 완결성 의존 |
| 권한/역할/메뉴 추가·수정 | ❌ (자동 동기화) | config/core.php 수정만으로 충분 |
| 정적 권한/메뉴 제거 | ✅ (cleanup 명시 호출) | 기존 메뉴/권한 삭제 |
| PHP 코드만 변경 | ❌ | 버그 수정, 성능 개선 |

---

## 5. 업그레이드 스텝 작성

### 디렉토리 구조

```text
upgrades/                             # 프로젝트 루트
├── .gitkeep
├── Upgrade_7_0_0_beta_4.php          # 7.0.0-alpha.4
└── Upgrade_7_0_0_beta_15.php         # 7.0.0-alpha.15
```

### 작성 예시

```php
<?php

namespace App\Upgrades;

use App\Contracts\Extension\UpgradeStepInterface;
use App\Extension\UpgradeContext;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * 코어 7.0.0-alpha.15 업그레이드 스텝
 *
 * 기존 사용자 레코드에 UUID v7을 백필합니다.
 */
class Upgrade_7_0_0_beta_15 implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        // 1. 사전 조건 확인
        if (! Schema::hasColumn('users', 'uuid')) {
            $context->logger->warning('uuid 컬럼 없음. 마이그레이션을 먼저 실행하세요.');
            return;
        }

        // 2. 데이터 백필
        $nullCount = DB::table('users')->whereNull('uuid')->count();
        if ($nullCount > 0) {
            $context->logger->info("UUID 백필 시작: {$nullCount}건");
            DB::table('users')->whereNull('uuid')->orderBy('id')->chunk(100, function ($users) {
                foreach ($users as $user) {
                    DB::table('users')
                        ->where('id', $user->id)
                        ->update(['uuid' => Str::orderedUuid()->toString()]);
                }
            });
            $context->logger->info("UUID 백필 완료");
        }

        // 3. 백필 완료 후 NOT NULL 제약 (데이터 완결성 예외)
        // raw SQL에서는 $context->table()로 프리픽스 적용 필수
        $column = DB::selectOne("SHOW COLUMNS FROM {$context->table('users')} WHERE Field = 'uuid'");
        if ($column && $column->Null === 'YES') {
            Schema::table('users', function ($table) {
                $table->uuid('uuid')->nullable(false)->unique()->change();
            });
        }
    }
}
```

### 작성 체크리스트

```text
□ UpgradeStepInterface 구현
□ 네임스페이스: App\Upgrades (코어) / Modules\Vendor\Module\Upgrades (모듈)
□ 파일명: Upgrade_X_Y_Z.php (버전에 맞는 언더스코어 표기)
□ 사전 조건 확인 (테이블/컬럼 존재 여부)
□ 로거를 통한 진행 상황 기록
□ 멱등성 보장 — 모든 DB 조작에 방어 로직 필수 (아래 참조)
□ 대량 데이터는 chunk() 사용
□ raw SQL 사용 시 $context->table()로 테이블 프리픽스 적용
```

### 멱등성 방어 로직 (필수)

업그레이드 스텝은 재실행될 수 있다 (롤백 후 재시도, `--force` 등). 모든 DB 조작은 이미 완료된 상태에서 재실행해도 안전해야 한다.

| 조작 유형 | 방어 패턴 | 비고 |
|----------|----------|------|
| 레코드 삽입 | `firstOrCreate` 또는 `updateOrCreate` | `insert` 단독 사용 금지 |
| 대량 데이터 이관 | 이관 완료 마커 확인 후 스킵 | 예: `source` 컬럼에 마커 기록 → `exists()` 체크 |
| 식별자 rename (unique 컬럼) | 신규 존재 여부 확인 후 분기 | 양쪽 다 존재하면 역할 이관 후 구 레코드 삭제 |
| 코드/상태값 변환 (update WHERE) | WHERE 조건이 이미 변환된 건을 제외 | 대부분 자동 방어됨 |
| 캐시 무효화 | 항상 안전 | — |

---

## 6. 자동 발견 및 실행 규칙

> 파일: `app/Services/CoreUpdateService.php` — `runUpgradeSteps()`

### 발견 프로세스

```text
1. base_path('upgrades') 디렉토리 스캔
2. .php 확장자 파일만 대상
3. 정규식 매칭: /^Upgrade_(\d+)_(\d+)_(\d+)(?:_([a-zA-Z]\w*(?:_\d+)*))?$/
4. 버전 변환: 1_0_0 → 1.0.0, 7_0_0_beta_4 → 7.0.0-alpha.4
5. require_once 로딩 → App\Upgrades\{filename} 클래스 인스턴스화
6. UpgradeStepInterface 구현 확인
```

### 실행 규칙

- **필터 조건**: `fromVersion < stepVersion <= toVersion` (`version_compare` 사용)
- **정렬**: `uksort($steps, 'version_compare')` — 자연 정렬 오름차순
- **실행**: 각 스텝에 `UpgradeContext::withCurrentStep($version)` 불변 복제 전달

### 버전 네이밍 규칙

파일명의 언더스코어가 버전 구분자로 변환됩니다:

| 파일명 | 변환 버전 | 설명 |
|--------|----------|------|
| `Upgrade_1_0_0.php` | `1.0.0` | 숫자 3자리 |
| `Upgrade_7_0_0_beta_4.php` | `7.0.0-alpha.4` | pre-release 포함 |
| `Upgrade_7_0_0_beta_15.php` | `7.0.0-alpha.15` | pre-release 2자리 |

**규칙**: 숫자 3자리(`X_Y_Z`) 후 알파벳으로 시작하는 부분은 `-`로 연결, 이후 `_`는 `.`로 변환

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

---

## 7. 버전 관리

### 버전 소스

| 파일 | 역할 |
|------|------|
| `config/app.php` — `'version'` | SSoT (env 기본값 정의) |
| `.env` — `APP_VERSION` | 런타임 오버라이드 |
| `.env.example` | 배포 템플릿 |

### 버전 읽기

```php
CoreVersionChecker::getCoreVersion()  // → config('app.version')
```

### 버전 갱신 (Step 11)

```php
CoreUpdateService::updateVersionInEnv($version)
// .env에 APP_VERSION이 존재하면: 정규식으로 라인 교체
// .env에 APP_VERSION이 없으면: "APP_VERSION={version}\n" 추가
```

### 버전 변경 시 필수 동기화

```text
필수: 코어 버전 변경 시 3곳 동기화

1. config/app.php — env('APP_VERSION', '새버전')
2. .env.example — APP_VERSION=새버전
3. .env.testing — APP_VERSION=새버전 (테스트 환경)
4. CHANGELOG.md — 변경사항 기록
```

### 부트스트랩 호환성 자동 검증

> 파일: `app/Providers/CoreServiceProvider.php` — `validateAndDeactivateIncompatibleExtensions()`, `validateAndDeactivateIncompatibleTemplates()`

**애플리케이션 부트(boot) 시마다** CoreServiceProvider가 모든 활성 확장의 `g7_version` 요구사항을 검증합니다.
`config('app.version')`이 확장의 요구 버전을 충족하지 않으면 **해당 확장을 자동으로 비활성화**합니다.

#### 검증 흐름

```text
1. CoreServiceProvider::boot() 실행
2. CoreVersionChecker::getCacheKey($type) 캐시 확인 (TTL: 1시간)
3. 캐시 없으면 → 모든 활성 확장 순회
4. 각 확장의 g7_version (예: ">=7.0.0-beta.1") 확인
5. Semver::satisfies(config('app.version'), $constraint) 실행
6. false 반환 시 → Manager::deactivateModule/Plugin/Template() 호출
7. 캐시 저장 (1시간 유효)
```

#### 주의사항

| 항목 | 설명 |
|------|------|
| **검증 주기** | 캐시 만료(1시간)마다 재검증 |
| **비활성화 대상** | 호환되지 않는 **모든** 활성 확장 (모듈, 플러그인, 템플릿) |
| **activity_log** | 기록되지 않음 (Service 레이어를 거치지 않고 Manager 직접 호출) |
| **로그** | `storage/logs/laravel.log`에 warning 기록 |

#### 버전 확인 방법

```bash
php artisan tinker
> \Composer\Semver\Semver::satisfies(config('app.version'), '>=7.0.0-beta.1');
```

#### 버전 우선순위

```text
config('app.version') = env('APP_VERSION', 'config/app.php 기본값')

.env에 APP_VERSION이 있으면 → .env 값 사용 (config/app.php 기본값 무시)
.env에 APP_VERSION이 없으면 → config/app.php의 기본값 사용
```

---

## 8. CoreUpdateService 주요 메서드

> 파일: `app/Services/CoreUpdateService.php`

### 업데이트 감지

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `checkForUpdates()` | `(): array` | GitHub API로 최신 릴리스 조회 |
| `getChangelog()` | `(?string $from, ?string $to): array` | CHANGELOG.md 파싱, 버전 범위 필터 |
| `checkSystemRequirements()` | `(): array` | 추출 도구 검증 (ZipArchive/unzip) |
| `validatePendingPath()` | `(): array` | _pending 디렉토리 존재/권한 확인 |

### 다운로드 및 소스 준비

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `downloadUpdate()` | `(string $version, ?Closure $onProgress): string` | GitHub 다운로드 + 추출 (폴백 체인) |
| `copySourceToPending()` | `(string $sourceDir, ?Closure $onProgress): string` | --source 모드: 외부 디렉토리 → _pending 복사 |
| `prepareLocalSource()` | `(?Closure $onProgress): string` | --local 모드: 현재 코드 → _pending 복사 |
| `validatePendingUpdate()` | `(string $pendingPath): void` | 패키지 구조 검증 (composer.json, app/, config/app.php) |
| `createPendingDirectory()` | `(): string` | _pending/core_Ymd_His/ 디렉토리 생성 |
| `cleanupPending()` | `(string $pendingPath): void` | _pending 하위 디렉토리 삭제 |

### 백업 및 복원

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `createBackup()` | `(?Closure $onProgress): string` | CoreBackupHelper로 백업 생성 |
| `restoreFromBackup()` | `(string $backupPath, ?Closure $onProgress): void` | 백업에서 파일 복원 |

### 적용 및 설치

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `applyUpdate()` | `(string $sourcePath, ?Closure $onProgress): void` | _pending → base_path 선택적 덮어쓰기 |
| `runComposerInstallInPending()` | `(string $pendingPath, ?Closure $onProgress): void` | _pending에서 composer install (--no-scripts) |
| `isComposerUnchangedForCore()` | `(string $pendingPath): bool` | composer.json/lock MD5 비교 |
| `runComposerInstall()` | `(?Closure $onProgress): void` | base_path에서 composer install |
| `copyVendorFromPending()` | `(string $pendingPath, ?Closure $onProgress): void` | _pending/vendor → base_path/vendor |

### 마이그레이션 및 동기화

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `runMigrations()` | `(): void` | `php artisan migrate --force` |
| `syncCoreRolesAndPermissions()` | `(): void` | config/core.php roles/permissions 동기화 |
| `syncCoreMenus()` | `(): void` | config/core.php menus 동기화 |

### 업그레이드 및 마무리

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `runUpgradeSteps()` | `(string $from, string $to, ?Closure $onStep): void` | upgrades/ 자동 발견 + 실행 |
| `updateVersionInEnv()` | `(string $version): void` | .env의 APP_VERSION 갱신 |
| `clearAllCaches()` | `(): void` | config/cache/route/view 클리어 + package:discover |

### 유지보수 모드

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `enableMaintenanceMode()` | `(): string` | `php artisan down --secret` → bypass secret 반환 |
| `disableMaintenanceMode()` | `(): void` | `php artisan up` |

### 유틸리티

| 메서드 | 시그니처 | 설명 |
|--------|---------|------|
| `generateFailureReport()` | `(Throwable $e, string $from, string $to): string` | 에러 보고서 생성 → storage/logs/ |

---

## 9. Artisan 커맨드

### core:update

```bash
php artisan core:update [--force] [--no-backup] [--no-maintenance] [--local] [--source={path}]
```

| 옵션 | 설명 |
|------|------|
| `--force` | 버전 비교 스킵, 동일 버전이어도 강제 업데이트 |
| `--no-backup` | 백업 생성 스킵 (Step 5) |
| `--no-maintenance` | 유지보수 모드 스킵 (Step 3) |
| `--local` | 현재 코드베이스를 소스로 사용 (GitHub 스킵) |
| `--source={path}` | 지정 디렉토리를 소스로 사용 (GitHub 스킵) |

**종료 코드**:
- `0` (SUCCESS): 업데이트 성공 또는 이미 최신 버전
- `1` (FAILURE): 요건 미충족 또는 업데이트 실패

### core:check-updates

```bash
php artisan core:check-updates
```

- GitHub API 호출 → 현재/최신 버전 표시
- "새로운 업데이트가 있습니다!" 또는 "현재 최신 버전입니다."

---

## 10. API 엔드포인트

> 파일: `app/Http/Controllers/Api/Admin/CoreUpdateController.php`

| 메서드 | 경로 | 설명 |
|--------|------|------|
| GET | `/api/admin/core-update/check-updates` | 코어 업데이트 확인 (422 시 check_failed) |
| POST | `/api/admin/core-update/changelog` | 코어 변경 로그 조회 (from_version, to_version) |

> ActivityLog: `checkForUpdates` 호출 시 `core_update.check` 액티비티 기록

---

## 11. 설정 (config/app.php)

### 버전

```php
'version' => env('APP_VERSION', '7.0.0-alpha.14'),
```

### 업데이트 설정

```php
'update' => [
    'github_url'    => '...',              // GitHub 리포지토리 URL
    'github_token'  => env('GITHUB_TOKEN'), // GitHub Personal Access Token (선택)
    'pending_path'  => '...',              // _pending 디렉토리 경로
    'targets'       => [...],              // 업데이트 적용 + 백업 대상 파일/디렉토리
    'backup_only'   => ['vendor'],         // 백업/복원 전용 (applyUpdate 제외)
    'backup_extra'  => [...],              // 추가 백업 대상
    'excludes'      => [...],              // 제외 패턴
],
```

---

## 12. 에러 처리 및 로깅

### 시스템 요건 검증

```text
- ZipArchive 또는 unzip 명령어 중 하나 이상 필요
- 미충족 시 커맨드 실패 (EXIT 1) + 사용 가능 방법 목록 출력
```

### _pending 검증

```text
- 디렉토리 미존재: File::ensureDirectoryExists() 시도
- 쓰기 불가: 경로, 소유자, 그룹, 권한 정보 포함 에러 출력
```

### 패키지 검증

```text
- 필수 파일: composer.json, app/ 디렉토리, config/app.php
- config/app.php에 'version' 키 필수
- 미충족 시 RuntimeException
```

### Composer 실패

```text
- exit code 비정상 → RuntimeException (마지막 5줄 출력 포함)
```

### 로그 위치

| 로그 | 경로 | 내용 |
|------|------|------|
| 성공 로그 | `storage/logs/core_update_success_YYYYMMDD_HHMMSS.log` | 전체 실행 타임라인 |
| 실패 로그 | `storage/logs/core_update_failed_YYYYMMDD_HHMMSS.log` | 실패까지의 실행 타임라인 |
| 실패 보고서 | `storage/logs/core_update_failure_YYYYMMDD_HHMMSS.log` | 예외 상세 + 시스템 정보 |
| 일반 로그 | `storage/logs/laravel.log` | Log 파사드 통한 기록 |

---

## 13. 유지보수 모드

### 활성화 (Step 3)

```php
Artisan::call('down', [
    '--secret' => $secret,    // UUID 토큰 (bypass 접근용)
    '--retry'  => 60,         // Retry-After 헤더 (초)
    '--refresh' => 15,        // 브라우저 자동 새로고침 (초)
]);
```

### Bypass 접근

```text
URL: https://example.com/{secret}
→ 쿠키 발급 → 유지보수 모드에서도 접근 가능
```

### 해제

```text
- 성공 시: Step 11에서 자동 해제 (php artisan up)
- 실패 시: 유지보수 모드 유지 → 관리자에게 수동 해제 안내
  "이전 버전으로 사이트를 운영하려면: php artisan up"
```

---

## 14. 코어 vs 확장 업데이트 비교

| 항목 | 코어 | 모듈/플러그인 | 템플릿 |
|------|------|-------------|--------|
| **소스** | GitHub releases (zipball) | GitHub 또는 _bundled | GitHub 또는 _bundled |
| **추출** | 폴백 체인 (ZipArchive → unzip) | ExtensionPendingHelper | ExtensionPendingHelper |
| **백업** | CoreBackupHelper (선택) | ExtensionBackupHelper (선택) | ExtensionBackupHelper (선택) |
| **유지보수 모드** | 전체 앱 down (secret 토큰) | 없음 | 없음 |
| **Composer** | MD5 비교 → 변경 시만 실행 | 확장별 독립 vendor/ | 해당 없음 |
| **파일 적용** | FilePermissionHelper (권한 보존) | ExtensionPendingHelper::copyToActive() | ExtensionPendingHelper::copyToActive() |
| **vendor 처리** | 변경 시 전체 복사 | 확장별 composer install | 해당 없음 |
| **마이그레이션** | 필수 (Step 9) | 선택 (확장별) | 없음 |
| **동기화** | roles + permissions + menus + mail templates | roles + permissions + menus | 없음 |
| **업그레이드 스텝** | `upgrades/Upgrade_X_Y_Z.php` | `{ext}/upgrades/Upgrade_X_Y_Z.php` | 없음 |
| **네임스페이스** | `App\Upgrades` | `Modules\Vendor\Module\Upgrades` | 해당 없음 |
| **실행 주체** | `CoreUpdateService` | `AbstractModule` | `TemplateManager` |
| **커맨드** | `core:update` | `module:update {id}` | `template:update {id}` |
| **레이아웃** | 해당 없음 | 자동 갱신 | 충돌 전략 (apply_new/keep_current) |
| **롤백** | 전체 복원 + 유지보수 유지 | 확장별 복원 + 상태 복원 | 확장별 복원 |
| **로깅** | 타임스탬프 성공/실패 로그 + 실패 보고서 | laravel.log | laravel.log |

---

## 참고 파일 위치

| 파일 | 경로 |
|------|------|
| CoreUpdateCommand | `app/Console/Commands/Core/CoreUpdateCommand.php` |
| CoreCheckUpdatesCommand | `app/Console/Commands/Core/CoreCheckUpdatesCommand.php` |
| CoreUpdateService | `app/Services/CoreUpdateService.php` |
| CoreUpdateController | `app/Http/Controllers/Api/Admin/CoreUpdateController.php` |
| CoreVersionChecker | `app/Extension/CoreVersionChecker.php` |
| CoreBackupHelper | `app/Extension/Helpers/CoreBackupHelper.php` |
| UpgradeStepInterface | `app/Contracts/Extension/UpgradeStepInterface.php` |
| UpgradeContext | `app/Extension/UpgradeContext.php` |
| 코어 업그레이드 디렉토리 | `upgrades/` |
| 코어 설정 | `config/app.php` (version, update 섹션) |
| 역할/권한/메뉴 정의 | `config/core.php` |
