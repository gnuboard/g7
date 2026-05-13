# 업그레이드 스텝 작성 가이드 (Upgrade Step Guide)

> 버전 업그레이드 시 실행되는 `upgrades/Upgrade_X_Y_Z.php` 작성 규정

## TL;DR (5초 요약)

```text
1. upgrade step 이 실행되는 환경은 경로에 따라 다르다 — 섹션 9 "업그레이드 경로" 먼저 읽기
2. 대부분의 코어 upgrade step = 경로 B (spawn) → 신규 클래스/메서드 자유 사용 가능
3. 인프라 재설계 릴리즈의 특수 경로 = 경로 C → docblock 에 `@upgrade-path C` 선언 + 로컬 로직 필수
4. 경로 A (모듈/플러그인) · 경로 C 규율: 기존 클래스의 신규 메서드 호출 금지, 로컬 private 헬퍼 우선
5. 모든 분기에 upgrade.log 출력 — 로그 없음 = 디버깅 단서 없음
6. beta.3+ 타깃 step 이 중간에 새 프로세스 재진입이 필요하면 `UpgradeHandoffException` throw (섹션 10.5)
7. 7.0.0-beta.5+ 신규 step 은 `AbstractUpgradeStep` 상속 의무 + 카탈로그/변환/핫픽스를 `upgrades/data/{version}/` 으로 격리 (섹션 13)
```

---

## 목차

1. [배경 — 왜 이 가이드가 필요한가](#1-배경--왜-이-가이드가-필요한가)
2. [PHP 클래스 캐싱 제약](#2-php-클래스-캐싱-제약)
3. [작성 규칙](#3-작성-규칙)
4. [허용/금지 패턴](#4-허용금지-패턴)
5. [체크리스트](#5-체크리스트)
6. [sudo 환경 / 소유권 고려사항](#6-sudo-환경--소유권-고려사항)
7. [생명주기와 제거 시점](#7-생명주기와-제거-시점)
8. [실전 사례](#8-실전-사례)
9. [업그레이드 경로별 규율 (모듈/플러그인 · 코어 beta.3+ · 코어 beta.2 특수)](#9-업그레이드-경로별-규율)
10. [경로 C 내부 inline spawn 패턴](#10-경로-c-내부-inline-spawn-패턴)
10.5. [업그레이드 핸드오프 (beta.3+ 인프라)](#105-업그레이드-핸드오프-beta3-인프라)
11. [업그레이드 후 데이터 정합성 (완전 동기화)](#11-업그레이드-후-데이터-정합성-완전-동기화)
12. [Declarative artifacts 일회성 보정 패턴](#12-declarative-artifacts-일회성-보정-패턴)
13. [버전별 데이터 스냅샷 (7.0.0-beta.5+)](#13-버전별-데이터-스냅샷-700-beta5)

---

## 1. 배경 — 왜 이 가이드가 필요한가

`php artisan core:update` 실행 흐름:

```
Step 7  applyUpdate         — 디스크의 app/**, config/**, upgrades/** 파일을 새 버전으로 덮어쓰기
Step 8  Composer / vendor   — 외부 프로세스 실행으로 vendor 재구성
Step 9  runMigrations + reloadCoreConfigAndResync — DB 마이그레이션 + 디스크 fresh config 재주입 후 권한/메뉴 sync
Step 10 runUpgradeSteps     — upgrades/Upgrade_*.php 의 run() 호출 ← 여기
Step 11 Cleanup             — 캐시 초기화, 소유권 복원 등
```

**주의**: Step 10 시점에 실행되는 PHP 프로세스는 **Step 1 부터 시작한 "이전 버전" 프로세스** 이다. applyUpdate 로 디스크 파일이 모두 새 버전으로 바뀌어도, 이미 메모리에 로드된 클래스는 재로드되지 않는다.

즉:

- `require_once` 되는 **upgrade 파일 자체** → 신규 파일이므로 새 코드 로드 OK
- upgrade 파일 안에서 참조하는 **다른 클래스** → 이미 메모리에 있는 **이전 버전** 클래스를 사용

## 2. PHP 클래스 캐싱 제약

### 작동 원리

PHP 는 클래스를 한 번 로드하면 동일 프로세스 내에서 재정의 불가. Laravel 의 Composer autoloader 도 마찬가지 — 네임스페이스·클래스명 매핑이 캐시된 뒤에는 파일 교체가 무시된다.

### 구체 시나리오

이전 버전에서 `App\Extension\Helpers\FilePermissionHelper` 가 이미 사용되어 메모리에 로드된 상태에서:

```php
// upgrades/Upgrade_X_Y_Z.php (새 버전 파일)
use App\Extension\Helpers\FilePermissionHelper;

// 새 버전에서 신설한 메서드
FilePermissionHelper::newMethod();
// → Call to undefined method FilePermissionHelper::newMethod() Fatal
```

디스크의 `FilePermissionHelper.php` 는 새 버전으로 바뀌어 `newMethod()` 가 정의되어 있어도, PHP 는 **메모리의 이전 버전 클래스** 를 사용하므로 Fatal.

### 영향 받지 않는 대상

- **신규 추가된 클래스** (이전 버전에 존재하지 않던 파일) → autoload 시 새 코드 로드
- 예: `App\Models\NotificationDefinition` 가 beta.2 에서 처음 도입된 경우, beta.1 → beta.2 upgrade step 에서 사용 가능

### 영향 받는 대상

- **이미 이전 버전에 존재하던 클래스에 새로 추가된 메서드/프로퍼티**
- **이미 이전 버전에 존재하던 클래스의 시그니처 변경**

## 3. 작성 규칙

### 원칙 1 — 로컬 private 로직 우선

upgrade step 이 필요로 하는 로직은 **Upgrade 클래스 내부 private 메서드** 로 직접 작성한다. 공용 Helper 에 유사 로직이 있더라도 **이전 버전에 없는 메서드** 라면 호출 금지.

```php
class Upgrade_7_0_0_beta_2 implements UpgradeStepInterface
{
    public function run(UpgradeContext $context): void
    {
        $this->restoreVendorOwnership($context);
    }

    // ✅ 로컬 private 메서드 — 메모리의 이전 버전 클래스와 무관
    private function restoreVendorOwnership(UpgradeContext $context): void
    {
        // 로직 인라인 작성
    }
}
```

### 원칙 2 — 프레임워크/기존 클래스만 use

use 문은 아래 범주만 허용:

- `Illuminate\*` (Laravel 프레임워크)
- `App\Contracts\Extension\UpgradeStepInterface`, `App\Extension\UpgradeContext`
- **이전 버전에도 이미 존재하던** `App\Models\*`, `App\Services\*` 등
- **새 버전에서 처음 도입된** 클래스 (예: 새 Seeder, 새 Model)

### 원칙 3 — 모든 분기에 upgrade.log 출력

upgrade step 의 로거는 `storage/logs/upgrade-YYYY-MM-DD.log` 에 기록된다. 조기 return 경로에도 **이유를 명시** 하는 로그를 남긴다.

```php
if ($currentOwner === $expectedOwner) {
    $context->logger->info('[X.Y.Z] 이미 일치 — 복원 스킵');
    return;
}
```

로그 없음은 디버깅 불가를 의미한다. 실패 보고를 받았을 때 **어느 분기에서 return 됐는지 추적할 수 없으면 원인 파악에 많은 시간이 소요**된다.

### 원칙 4 — 멱등성 보장

upgrade step 은 동일 버전으로 재실행될 수 있다(`--force` 옵션). 어떤 분기에서든 반복 실행이 안전해야 한다.

- 파일 생성 전 `File::exists()` 체크
- 테이블/컬럼 조작 전 `Schema::hasTable/hasColumn` 체크
- 데이터 이관은 "이미 이관됨" 플래그로 skip 가능하게 설계

## 4. 허용/금지 패턴

### ❌ 금지 — 기존 클래스의 신규 메서드 호출

```php
use App\Extension\Helpers\FilePermissionHelper;

// FilePermissionHelper 는 이전 버전에도 존재 → 메모리에 구 클래스 로드됨
// inferWebServerOwnership() 가 새 버전에서 신설된 메서드라면 Fatal
FilePermissionHelper::inferWebServerOwnership();
```

### ✅ 허용 — 로컬 헬퍼로 직접 구현

```php
private function inferWebServerOwnershipLocal(): array
{
    $baseOwner = @fileowner(base_path());
    $candidates = ['storage/logs', 'storage/framework/views', /* ... */];

    foreach ($candidates as $candidate) {
        $owner = @fileowner(base_path($candidate));
        if ($owner !== false && $owner !== $baseOwner) {
            return [$owner, @filegroup(base_path($candidate)), $candidate];
        }
    }

    return [$baseOwner, @filegroup(base_path()), 'base_path'];
}
```

### ❌ 금지 — 기존 Model 의 신규 메서드/스코프 호출

```php
use App\Models\User;

// User 가 이전 버전에도 존재 → 메모리 구 클래스
// 새 버전에서 추가된 scope 는 Fatal
User::scopeNewlyAdded()->get();
```

### ✅ 허용 — DB 파사드로 직접 쿼리

```php
use Illuminate\Support\Facades\DB;

DB::table('users')->where(/* ... */)->get();
```

### ✅ 허용 — 새 버전에서 처음 도입된 클래스

```php
// 새 버전에서 처음 추가된 Seeder
use Database\Seeders\NotificationDefinitionSeeder;

(new NotificationDefinitionSeeder())->run();
```

### ✅ 허용 — 프레임워크 파사드

```php
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
```

### ✅ 허용 — 사용자 입력 (yes/no 프롬프트)

upgrade step 에서 사용자 확인이 필요한 경우 `\App\Console\Helpers\ConsoleConfirm::ask()` 를 **FQN 직접 호출**한다. fgets(STDIN) 직접 사용 금지.

```php
$confirmed = \App\Console\Helpers\ConsoleConfirm::ask(
    '번들에 포함된 새 버전으로 일괄 업데이트하시겠습니까?',
    true, // default = yes
);

if ($confirmed) {
    // 진행 로직
} else {
    $context->logger->info('[X.Y.Z] 일괄 업데이트 스킵');
}
```

규칙:

- **FQN 사용 권장**: `use App\Console\Helpers\ConsoleConfirm` 보다 `\App\Console\Helpers\ConsoleConfirm::ask()` 직접 호출 (use 문 의존 최소화)
- **헬퍼 자체가 TTY 가드 + EOF 처리 + 재질문 루프 내장** → upgrade step 코드는 호출만 하면 됨
- **non-TTY (CI, spawn 자식)** → 자동으로 `$default` 반환
- 입력 정규화 / 재질문 규칙 상세: [docs/backend/console-confirm.md](../backend/console-confirm.md)

`ConsoleConfirm` 클래스는 ConsoleConfirm 도입 버전(예: beta.3) 이상의 코어에서만 존재한다 — 이전 버전 메모리에서 실행되는 upgrade step 에서 호출하더라도 PHP autoloader 가 디스크에서 lazy load 하므로 안전 (§2 PHP 클래스 캐싱 제약 §"영향 받지 않는 대상" 참조).

## 5. 체크리스트

upgrade step PR 검토 시 아래 항목을 모두 확인:

- [ ] `use App\*` 중 이전 버전에 **이미 존재하던** 클래스의 **새 버전에서 신설된 메서드·프로퍼티** 호출이 없는가?
- [ ] 필요한 로직이 로컬 private 메서드로 작성되어 있는가?
- [ ] 모든 return 분기에 upgrade.log 메시지가 있는가?
- [ ] `--force` 재실행 시 안전한 멱등 동작인가?
- [ ] sudo 실행 환경에서 root 오염이 발생할 수 있는 파일 조작이 있다면 소유권 복원 로직이 포함되었는가?
- [ ] 테스트 환경(`beta.N fresh` → `beta.N+1 update`) 에서 수동 검증을 통과했는가?

## 6. sudo 환경 / 소유권 고려사항

`sudo php artisan core:update` 실행 시:

- 외부 프로세스(composer 등) 가 root 로 파일을 생성
- 파일 시스템 API(`File::copy`, `mkdir` 등) 도 root 소유로 생성
- 원본 소유자(www-data 등) 를 보존하려면 **업데이트 후 명시적 chown 필요**

beta.2 이후 버전은 `CoreUpdateService::snapshotOwnership() + restoreOwnership()` 공통 로직이 Step 11 Cleanup 에서 자동 수행. upgrade step 에서 별도 소유권 복원이 필요한 예외 상황(이전 버전에 해당 로직이 없음) 에서만 인라인 작성.

### 6.1 항목별 정확 복원 — `snapshotOwnershipDetailed` + `restoreOwnership($detailedSnapshot)` (beta.4+)

`snapshotOwnership` 은 target 의 **루트 디렉토리 1개만** stat. 트리 내부 항목의 owner/group/perms 는 보존하지 않는다. PHP-FPM 쓰기 영역(`storage/logs`, `storage/framework`, `storage/app/core_pending`, `bootstrap/cache`) 처럼 **항목 수가 적고 정확 복원이 필요한 경로** 는 다음 패턴 사용:

```php
// Step 5 (백업 직후)
$detailedSnapshot = $service->snapshotOwnershipDetailed([
    'storage/logs', 'storage/framework', 'storage/app/core_pending', 'bootstrap/cache',
]);

// Step 11/12
$service->restoreOwnership($snapshot, $onProgress, $detailedSnapshot);
```

`$detailedSnapshot` 가 비어있지 않은 path 는 `chown + chgrp + chmod` 항목별 정확 복원. 비어있으면 기존 `chownRecursive` 동작 (호환성 유지).

**대상 영역 결정 원칙**:

- chown 대상이며 정확 복원이 필요한 좁은 영역만 detailed 사용 (50,000 항목 가드)
- `storage/app/{modules,plugins,attachments,public,settings}` 같은 사용자 데이터는 `restore_ownership` 자체에서 빠져 chown 비대상 — detailed 불필요
- `config/app.php` 의 `restore_ownership` 기본값은 PHP-FPM 쓰기 필수 영역 한정 (인스톨러 SSoT 의 `storage` 재귀 검증 의도와는 다른 책임)

### 6.2 release transition 한정 권한 우회 — 마커 + boot 트리거 패턴 (beta.4+)

부모 프로세스의 결함을 신버전 코드로 차단할 수 없는 경우(이미 메모리에 로드된 OLD 코드) 사용하는 패턴:

1. spawn 자식(NEW 코드) 의 upgrade step 이 update 시작 시점의 트리를 재귀 스냅샷 → **디스크에 직렬화 보존** (`storage/framework/cache/permission_snapshot_pending.json`)
2. 부모(OLD 코드) 가 망가뜨려도 직렬화 파일은 무사 (chown 만 영향, 내용 그대로)
3. update 종료 후 첫 ServiceProvider boot (NEW 코드) 가 `PermissionRestoreHelper::restoreFromPendingSnapshot()` 로 항목별 정확 복원 → 마커 삭제

자가 무력화: 부모도 NEW 코드인 다음 release transition 부터는 마커 작성 자체를 skip (가드: `method_exists` 또는 OLD 결함 부재 조건). beta.3 → beta.4 에서 `Upgrade_7_0_0_beta_4::recordPermissionSnapshotForLegacyParent()` 가 본 패턴의 참조 구현.

## 7. 생명주기와 제거 시점

각 upgrade step 은 **해당 버전에서만 필요한 1회성 작업** 을 담당한다. 일반적으로 다음 버전(N+2) 릴리즈 시점에 제거 가능 — 단 아래 조건을 모두 만족해야:

- N → N+1 업그레이드를 수행해야 하는 사용자가 더 이상 없음
- 또는 N → N+2 직접 업그레이드를 공식적으로 미지원
- upgrade step 이 수행한 DB/파일 정리가 새 설치에서는 불필요함

제거 시 upgrade 파일 자체를 삭제하면 된다. upgrade step 내부에서 참조했던 "공용 Helper 메서드" 는 다른 용도로 재사용될 수 있으므로 별도 판단.

## 8. 실전 사례

### 사례 A — beta.2 MailTemplate shim (클래스 캐싱 회피)

beta.1 의 `CoreUpdateService::syncCoreMailTemplates()` 가 `App\Models\MailTemplate::where(...)` 를 호출하는데, beta.2 에서 MailTemplate 이 제거되어 autoload 실패.

해결: beta.2 릴리즈에 `App\Models\MailTemplate` 의 **극소 shim** 을 포함. beta.2 upgrade step 에서 shim 파일과 테이블 자가 정리.

이 경우 shim 파일은 "upgrade step 의 의존성" 이지만 **이전 버전(beta.1) 이 메모리에 올리는 대상** 이므로 upgrade step 밖의 신규 파일로 작성되어야 했다. upgrade step 내부에서 `class_exists` 체크만으로 충분.

### 사례 B — vendor 소유권 복원 (sudo + 비대칭 환경)

beta.1 의 `runComposerInstall(base_path())` 가 sudo 에서 vendor/ 를 root 로 재생성. beta.2 upgrade step 이 storage/ 디렉토리 기준으로 원본 웹서버 계정(www-data) 을 추정하여 vendor/ 복원.

여기서 `FilePermissionHelper::inferWebServerOwnership()` 을 호출했다가 **beta.1 메모리 클래스 캐싱으로 undefined method Fatal** 발생. 로컬 private 메서드로 재작성하여 해결.

이 사례가 본 가이드 작성의 직접 계기다. **"공용 Helper 로 승격" 은 신규 버전 코드 기반에서만 안전** 이라는 교훈.

---

## 9. 업그레이드 경로별 규율

본 가이드의 많은 규칙은 "upgrade step 이 **이전 버전 PHP 프로세스** 메모리에서 실행된다" 는 전제에서 출발한다. 하지만 그누보드7 에는 서로 다른 실행 환경을 가진 **3개 경로** 가 공존하므로, 자신이 작성하는 upgrade step 이 어느 경로인지 먼저 확인해야 한다.

### 경로 A — 모듈/플러그인 upgrade step (기존 규율 유지)

- 실행 주체: `ModuleManager::updateModule()` / `PluginManager::updatePlugin()`
- 실행 환경: **메인 PHP 프로세스** (Artisan 커맨드가 로드된 상태)
- 단, `reloadModule`/`reloadPlugin` 이 `evalFreshModule` 로 **진입점 클래스만 재로드** (`app/Extension/ModuleManager.php` 의 `evalFreshModule`, `PluginManager.php` 의 `evalFreshPlugin` 참조)
- 진입점 메서드(`getPermissions`/`getMenus` 등)는 재로드 덕분에 최신 정의 반환
- 하지만 **그 외 App\* 클래스**(Helper, Service, Model)는 여전히 이전 버전 메모리
- **적용 규율**: 섹션 3~5 의 모든 작성 규칙 적용. 로컬 private 헬퍼 우선, 기존 클래스의 신규 메서드 호출 금지

### 경로 B — 코어 upgrade step (beta.3 이후, 규율 완화)

- 실행 주체: `CoreUpdateCommand` Step 10 → `proc_open` 으로 `core:execute-upgrade-steps` 커맨드 spawn
- 실행 환경: **별도 PHP 프로세스** (새로 시작되어 디스크의 최신 파일로 Composer autoload 수행)
- 모든 클래스·config 가 **최신 버전 기준** 으로 로드됨
- **적용 규율**: 클래스 캐싱 제약 **해제**. upgrade step 이 beta.N+1 의 새 Service/Repository/Controller/Model 등을 자유롭게 호출 가능
- 예외: `proc_open` 미지원 환경에서는 in-process fallback 으로 전환되므로, **신중하게 설계된 upgrade step 은 여전히 경로 A/C 규율도 충족** 하는 것이 안전
- 관련 구현: `app/Console/Commands/Core/ExecuteUpgradeStepsCommand.php`, `CoreUpdateCommand::spawnUpgradeStepsProcess`, `CoreUpdateService::reloadCoreConfigAndResync`

#### spawn 호출 시 필수 env 전파

`proc_open` 을 직접 사용할 때 `$env` 배열은 **반드시 아래 패턴** 으로 구성한다:

```php
$env = array_merge(getenv(), $_ENV, [
    'G7_UPDATE_IN_PROGRESS' => '1',
    // 필요한 추가 env ...
]);
```

`$_ENV` 단독 사용 금지. `variables_order` php.ini 에 `E` 가 없는 환경에서는 `$_ENV` 가 비어있어 플래그 전파가 누락되고, 자식의 `CoreServiceProvider::validateAndDeactivate*` 가 발동해 활성 확장이 일괄 비활성화되는 회귀가 발생한다. `getenv()` 는 프로세스 environ 테이블을 직접 반환해 `putenv` 로 설정된 값까지 포함한다. 상세 배경: [extension-update-system.md "업데이트 진행 플래그"](extension-update-system.md#업데이트-진행-플래그-g7_update_in_progress).

### 경로 C — 코어 upgrade step (이전 버전 in-process 실행 특수 경로)

- 실행 주체: **이전 버전 CoreUpdateCommand** — 이미 운영 서버에 배포되어 변경 불가
- 실행 환경: **이전 버전의 메인 PHP 프로세스** — 새 릴리즈에서 도입된 spawn 커맨드를 모르므로 호출 안 함
- 새 릴리즈의 재작성된 `CoreUpdateCommand` / `ExecuteUpgradeStepsCommand` 가 설치되어 있어도 이전 버전 CoreUpdateCommand 가 호출하지 않으므로 무용
- **적용 규율**: 섹션 3~5 의 모든 작성 규칙을 **강하게** 적용. upgrade step 파일 내부 로컬 private 로직으로 모든 후처리를 직접 수행
- 허용: 이전 버전에 이미 존재하던 클래스(예: `App\Services\CoreUpdateService`) 의 **기존 메서드** 호출 (예: `syncCoreRolesAndPermissions`, `syncCoreMenus`) — 단 내부에서 `config()` 로 읽는 값이 최신이어야 하므로 `config(['core' => require config_path('core.php')])` 로 선행 재주입 필요
- 금지: 새 릴리즈에서 신설된 메서드(예: `reloadCoreConfigAndResync`) 호출 — 이전 버전 메모리에 존재하지 않음
- 역사적 인스턴스: [`upgrades/Upgrade_7_0_0_beta_2.php`](../../upgrades/Upgrade_7_0_0_beta_2.php) 의 `resyncCorePermissionsAndMenus` 로컬 메서드

### 경로 C 가 필요한 상황 — 발생 조건

대부분의 릴리즈는 경로 B(spawn) 만으로 충분하다. 경로 C 가 필요한 것은 아래 **좁은 조건**일 때만:

**대상 릴리즈가 `CoreUpdateCommand` 의 업그레이드 흐름 자체를 구조적으로 변경** 하여,

- 새 진입점(spawn 커맨드, 신규 Service 메서드, 신규 내부 단계 등)을 도입했고
- 해당 진입점은 **새 버전이 실행 주체일 때만** 활성화되며
- **이전 버전 CoreUpdateCommand 는 그 진입점을 호출하지 않는** 경우

일반적인 기능 추가 / 버그 수정 / 데이터 마이그레이션 릴리즈는 모두 경로 B. 예: beta.3 → beta.4 에서 신규 모듈 도입이나 신규 권한 추가는 모두 경로 B 로 처리된다.

경로 C 는 주로 **인프라 재설계 릴리즈** 에서 1회씩 발생한다. 과거 예: beta.1 → beta.2 의 spawn 구조 도입.

### 경로 C 파일 선언 — 메타데이터 규약

경로 C 로 작성하는 upgrade step 파일은 **docblock 에 메타데이터** 를 명시한다:

```php
/**
 * 코어 N.N.N 업그레이드 스텝
 *
 * @upgrade-path C
 *
 * 경로 C(이전 버전 CoreUpdateCommand 의 in-process 메모리에서 실행) 선언.
 * ... 구체적 사유 ...
 */
class Upgrade_N_N_N implements UpgradeStepInterface
```

`@upgrade-path C` 선언이 있는 업그레이드 스텝은 경로 C 규율(기존 코어 클래스 use 문 금지 등)이 강하게 적용되며, 선언이 없으면 **경로 B** 로 판정되어 규율이 완화된다 (신규 클래스/메서드 자유 사용).

### 경로 판별 체크리스트

upgrade step 작성 전 다음을 확인:

1. 확장(모듈/플러그인) upgrade step 인가? → **경로 A**
2. 코어 upgrade step 인가?
   - 대상 릴리즈가 인프라 재설계(spawn 구조 등) 를 포함하여 이전 버전이 새 진입점을 모르는가?
     - **예** → **경로 C**, 파일에 `@upgrade-path C` 선언 + 로컬 로직 필수
     - **아니오** → **경로 B**, 규율 완화 (대부분의 경우)

경로 B 라고 판단했더라도, proc_open 차단 환경에서는 in-process fallback 이 작동하므로 **가능하면 경로 A/C 규율도 충족** 하도록 작성하는 것이 안전하다.

### V-1 안전 작성 패턴 (경로 B 의 사각지대)

경로 B 의 "spawn 자식이 fresh 디스크 코드를 로드" 가정은 `proc_open` 정상 동작에 의존한다. 다음 4가지 상황에서 in-process fallback 으로 전환되어 V-1 (이전 버전 메모리에 부재한 신규 메서드 호출) fatal 위험이 부활:

1. `proc_open` 함수 비활성 (보안 설정 / 일부 공유 호스팅)
2. `proc_open` 자원 생성 실패 (메모리 부족 / pipe 한도 초과)
3. 자식 비정상 종료 (uncaught exception / fatal / OOM)
4. 자식 exit=0 이지만 `[STEPS_EXECUTED]` 신호 미발행 또는 step 0건 실행 (silent skip)

beta.5+ 의 `spawn_failure_mode` (기본 `abort`) 가 위 4분기 모두를 fail-fast 차단하지만, 운영자가 `G7_UPDATE_SPAWN_FAILURE_MODE=fallback` 으로 호환 모드를 선택하면 V-1 위험이 잔존한다.

따라서 신규 step 작성 시 다음 안전 패턴을 적용:

- 신규 도입 (현재 작성 중인 버전에서 처음 추가된) 클래스/메서드/Repository 를 upgrade step 안에서 호출 금지
- 부득이 호출이 필요하면 `@upgrade-path C` 어노테이션으로 명시 + 로컬 private 메서드로 인라인 작성
- 허용 호출: `FilePermissionHelper`, `File` / `DB` / `Schema` / `Cache` / `Log` 파사드 등 *이전 버전 디스크 코드에도 존재하는* 코어 헬퍼만
- 검증: PR review 단계에서 "이 step 이 호출하는 모든 메서드/클래스가 *이전 버전* 디스크 코드에도 존재하는가?" 자문

#### In-process fallback 진입 시 위험 메커니즘

부모 프로세스 메모리의 stale 클래스 인스턴스가 Laravel DI 컨테이너에서 반환되어, 디스크의 신버전 코드를 무시한 채 신규 메서드 호출 → `Call to undefined method` fatal. 이슈 #28 의 실 보고 사례:

```text
Call to undefined method App\Services\CoreUpdateService::ensureWritableDirectories()
 at upgrades/Upgrade_7_0_0_beta_4.php:173 — ensureLangPacksPermissions()
```

beta.4 의 `Upgrade_7_0_0_beta_4` step 이 `app(CoreUpdateService::class)->ensureWritableDirectories(...)` 를 호출했으나, 부모 메모리의 stale beta.3 `CoreUpdateService` 인스턴스에는 `ensureWritableDirectories` 가 없어 fatal. 디스크는 이미 beta.4 였음에도 PHP autoloader 가 beta.3 인스턴스를 재사용한 결과.

#### 자동 검출 — `upgrade-step-vone-safety` audit rule (manual-only)

`upgrades/Upgrade_*.php` 안의 `app(\w+Service::class)` / `app(\w+Manager::class)` / `app(\w+Repository::class)` 패턴은 PR review reviewer 에게 manual-only 경고를 발행한다. 자동 차단은 아니지만, 매치된 위치를 보고 "이 메서드가 이전 버전 디스크에 존재했는가" 를 reviewer 가 수동 판정한다. 면제: `// audit:allow upgrade-step-vone-safety reason: ...` 인라인 주석.

---

## 10. 경로 C 내부 inline spawn 패턴

경로 C 에서 **새 릴리즈 클래스 로직이 꼭 필요한** 경우, upgrade step 파일 자체가 `proc_open` 으로 새 PHP 프로세스를 띄우면 된다. 새 프로세스는 디스크의 최신 파일을 autoload 하므로 클래스 캐싱과 무관.

```php
private function spawnResyncInline(UpgradeContext $context): bool
{
    if (! function_exists('proc_open')) {
        return false;
    }

    $basePath = base_path();
    $phpCode = <<<'PHP'
$base = getenv('G7_BASE_PATH');
chdir($base);
require $base.'/vendor/autoload.php';
$app = require $base.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(App\Services\CoreUpdateService::class)->reloadCoreConfigAndResync();
echo "OK\n";
PHP;

    $cmd = escapeshellarg(PHP_BINARY).' -r '.escapeshellarg($phpCode).' 2>&1';
    $process = proc_open($cmd, [/* descriptors */], $pipes, $basePath,
        array_merge($_ENV, ['G7_BASE_PATH' => $basePath]));

    if (! is_resource($process)) return false;
    // ... stdout 수집 + proc_close 검증
    return $exitCode === 0 && str_contains($stdout, 'OK');
}
```

### 적용 원칙

- **전용 아티산 커맨드 금지**: 1회성 로직이 beta.3 cleanup 시 upgrade step 파일 삭제와 함께 자연 소거되어야 함
- **Fallback 필수**: `proc_open` 미지원 환경에서는 in-process fallback + 수동 복구 안내 로그
- **Idempotent 보장**: spawn 성공/실패 무관하게 재실행 시 no-op

### 역사적 인스턴스

- `upgrades/Upgrade_7_0_0_beta_2.php::spawnResyncInlineLocal` — 경로 C 에서 beta.2 최신 `reloadCoreConfigAndResync()` 호출

---

## 10.5 업그레이드 핸드오프 (beta.3+ 인프라)

일부 릴리즈는 "여기서부터는 새 PHP 프로세스가 필요하다" 는 경계 지점을 가진다. 예컨대 upgrade step A 까지는 현재 프로세스에서 안전하지만, step B 는 이미 로드된 이전 클래스와 충돌한다면, A 까지 확정하고 B 는 사용자가 `core:update` 를 재실행할 때 새 프로세스에서 처리하도록 위임하는 편이 안전하다.

이를 위해 coreunit beta.3 에서 `UpgradeHandoffException` 인프라를 도입했다.

### 동작 흐름

```text
Upgrade_X_Y_Z::run()
  └─ throw UpgradeHandoffException(afterVersion, reason)
         ↓
[spawn 경로]                          [in-process 경로]
ExecuteUpgradeStepsCommand            CoreUpdateService::runUpgradeSteps
  └─ catch → stdout "[HANDOFF] <json>"  └─ 그대로 전파
  └─ exit 75                              ↓
                                         CoreUpdateCommand::handle
spawnUpgradeStepsProcess                 └─ catch (UpgradeHandoffException)
  └─ [HANDOFF] 페이로드 파싱                └─ updateVersionInEnv(toVersion)
  └─ exit 75 → UpgradeHandoffException      └─ clearAllCaches
  └─ throw                                  └─ restoreOwnership
         ↓                                  └─ cleanupPending
CoreUpdateCommand::handle                   └─ disableMaintenanceMode
  └─ (in-process 분기와 동일한 처리)            └─ resumeCommand 자동 생성
                                              └─ 사용자에게 스텝 전용 재실행 안내
                                              └─ return Command::SUCCESS
```

핸드오프 catch 는 `.env APP_VERSION` 을 `afterVersion` 이 아닌 **`toVersion`** 으로 올린다. 디스크의 파일·vendor·migration 은 이미 `toVersion` 상태이므로 .env 를 `toVersion` 으로 맞춰야 상태가 일치한다. 만약 `afterVersion` 으로 되돌리면 사용자가 다시 `core:update` 를 실행했을 때 GitHub 재다운로드부터 전체 프로세스가 반복된다.

대신 사용자에게는 **스텝 전용** 명령 (`php artisan core:execute-upgrade-steps --from=<afterVersion> --to=<toVersion> --force`) 만 실행하도록 안내한다. 이 명령은 재다운로드·vendor 재설치 없이 남은 upgrade step 만 실행한다.

#### 단독 실행 시 자동 수행되는 보조 단계 (beta.6+)

`core:execute-upgrade-steps` 가 운영자에 의해 직접 호출 (HANDOFF 안내 또는 수동 복구) 되면, 부모 `CoreUpdateCommand` 가 평소 수행하던 다음 단계를 자동으로 함께 수행한다 — 단독 실행자가 별도 명령을 잇따라 실행할 필요가 없다.

- 사전 단계: `runMigrations()`, `reloadCoreConfigAndResync()` (config/core.php 재로드 + 권한/메뉴/시더 동기화)
- 사후 단계: `updateVersionInEnv($toVersion)`, `clearAllCaches()`, `runBundledExtensionUpdatePrompt()` (모듈/플러그인/템플릿/언어팩 일괄 업데이트)

부모 `CoreUpdateCommand` 가 spawn 호출하는 경로에서는 다음 5개 옵션을 모두 자식 명령 라인에 추가해 중복 회피한다 — 부모는 이미 Step 9 / Step 11 / 번들 prompt 를 자식 종료 후 수행하기 때문이다.

- `--skip-migrations`
- `--skip-resync`
- `--skip-version-env`
- `--skip-cache-clear`
- `--skip-bundled-updates`

수동 복구 시나리오에서 사용자가 부분 단계만 제어하고 싶다면 위 옵션을 선택적으로 조합한다. 옵션을 모두 부여하면 부모 spawn 시나리오와 등가 — 본 명령은 upgrade step 만 실행한다.

### 사용 시점

upgrade step 파일에서 아래 조건이 모두 성립할 때 사용한다:

1. 현재 PHP 프로세스가 본 step 을 안전하게 실행할 수 없다고 판단 가능 (예: 필요한 클래스/메서드 미로드)
2. **직전 step 까지의 상태는 유효** 하며 이 상태를 확정해도 무방
3. 사용자가 `core:update` 를 한 번 더 실행하는 불편이 허용 범위

조건 2 가 충족되지 않으면 핸드오프 대신 일반 예외를 던져 롤백시키는 편이 안전하다.

### 사용 예

```php
use App\Exceptions\UpgradeHandoffException;
use App\Extension\Helpers\FilePermissionHelper;

public function run(UpgradeContext $context): void
{
    if (! method_exists(FilePermissionHelper::class, 'syncGroupWritability')) {
        // resumeCommand 를 지정하지 않으면 CoreUpdateCommand 가 catch 시점에
        //   `php artisan core:execute-upgrade-steps --from=<afterVersion> --to=<toVersion> --force`
        // 로 자동 생성한다. 대부분의 step 은 이 기본 동작을 사용하면 된다.
        throw new UpgradeHandoffException(
            afterVersion: '7.0.0-beta.2',
            reason: '신설 메서드 FilePermissionHelper::syncGroupWritability 가 현재 프로세스에 로드되지 않음',
        );
    }

    // ... 정상 로직
}
```

커스텀 재실행 명령을 안내하고 싶다면 `resumeCommand` 를 명시 전달한다. 그러나 기본 자동 생성 명령이 표준 시나리오에 최적이므로 특별한 이유가 없는 한 생략이 권장된다.

### 주의: 이전 버전 상위 계층 호환성

`UpgradeHandoffException` 은 beta.3 에서 신설된 클래스다. 이전 버전 (beta.1, beta.2) 의 `CoreUpdateService` / `CoreUpdateCommand` 는 이 클래스를 모른다. 따라서 **이전 버전 in-process 에서 throw 하면 uncaught exception 취급되어 `catch (\Throwable)` 롤백 경로로 빠진다**.

즉, 본 인프라가 의도대로 작동하는 것은 **beta.3 이후 코어가 실행 주체인 경우** — 즉 beta.3 이후 업데이트에서 사용 가능. beta.1 / beta.2 로부터 beta.3 로 올라오는 시점의 step 은 이 인프라를 사용할 수 없다 (graceful skip 등 다른 전략 필요).

다음 표로 정리:

| 실행 주체 코어 | spawn 가능 | 핸드오프 인프라 사용 |
| -------------- | ---------- | -------------------- |
| beta.1 | ✗ | ✗ (uncaught → 롤백) |
| beta.2 | ✓ (spawn) | ✗ (자식 catch 없음) |
| beta.3+ | ✓ | ✓ |

beta.3+ 타깃을 가정할 수 있는 경우에만 사용한다.

### 인프라 도입 시점

- 인프라 자체는 beta.3 에서 도입. 첫 실사용은 beta.3+ 후속 릴리즈부터 (beta.3 본 릴리즈의 `Upgrade_7_0_0_beta_3.php` 는 graceful skip 사용 — beta.1 하위 호환 사유)

---

## 11. 업그레이드 후 데이터 정합성 (완전 동기화)

upgrade step 이 수행하는 데이터 변경은 단순 "마이그레이션" 이 아니라 **완전 동기화** 를 지향한다. 즉:

1. **Upsert**: config/seeder → DB 반영
2. **Orphan Delete**: config 에 없는 DB row 삭제 (user_overrides 무관)
3. **Mapping Diff**: 관계 테이블 재정렬
4. **Dependent Cleanup**: 삭제된 상위 엔티티 하위 정리

세부 정책과 Helper 사용법은 다음을 참조:

- [완전 동기화 원칙](../backend/core-config.md#완전-동기화-원칙) — 4단계 패턴의 상세 정의
- [데이터 동기화 Helper 5종](../backend/data-sync-helpers.md) — Menu/Role/Notification/FilePermission/Generic
- [사용자 수정 보존 (HasUserOverrides)](../backend/user-overrides.md) — trait 사용 및 mass update 투명 추적

---

## 12. Declarative artifacts 일회성 보정 패턴

번들 모듈/플러그인의 **declarative 시드** (예: `getIdentityPolicies()`, `getIdentityMessageDefinitions()`, `getNotificationDefinitions()`) 는 정상 흐름에서 `ExecuteBundledUpdatesCommand` 의 spawn 자식 프로세스가 신버전 `ModuleManager::syncDeclarativeArtifacts()` 를 호출해 시드한다. 따라서 미래 release 의 회귀 차단은 spawn 구조에 의해 자동 보장되며 추가 추상화 불필요.

### 일회성 사후 보정이 필요한 transition

부모 프로세스(이전 버전) 가 spawn 위임 코드를 메모리에 보유하지 않아 in-process fallback 이 발생하면, 부모의 stale `ModuleManager` 가 신버전 sync 메서드를 호출하지 못해 declarative 시드가 silent fail 한다. 이 경우 해당 코어 transition 의 upgrade step 에서 사후 보정을 수행한다.

**보정 호출 (활성 디렉토리 기준)**:

```php
$moduleResult = app(ModuleManager::class)->resyncAllActiveDeclarativeArtifacts();
$pluginResult = app(PluginManager::class)->resyncAllActiveDeclarativeArtifacts();
```

`resyncAllActiveDeclarativeArtifacts()` 는 **활성 디렉토리** 의 `module.php` / `plugin.php` 를 fresh-load 하여 신버전 sync 일괄 호출. _bundled fresh-load 가 아닌 이유:
- 활성 디렉토리는 직전 버전 코드이지만 그 시점의 declaration 이 이미 존재 — 이를 신버전 sync 로 시드하면 **누락된 OLD declaration 이 정정** 됨
- _bundled 의 NEW declaration 은 사용자가 추후 일괄 업데이트를 선택했을 때 정상 spawn 흐름에서 시드되어야 함 (사용자 선택 존중)

### 사용자 선택 존중 매트릭스

| transition | 일괄 업데이트 사용자 선택 | resync 동작 |
| ---------- | ------------------------ | ----------- |
| beta.4→beta.5+ (정상) | yes | spawn 자식이 매니페스트의 각 확장 sync — 미선택은 미반영 |
| beta.4→beta.5+ (정상) | no | 어떤 확장도 sync 호출되지 않음 — 사용자 의지 보존 |
| beta.3→beta.4 (transition) | yes | in-process fallback 으로 silent fail. upgrade step 사후 보정이 활성 디렉토리 OLD declaration 을 정정 |
| beta.3→beta.4 (transition) | no | upgrade step 사후 보정은 그래도 발동 — 활성 디렉토리 OLD declaration 정정은 사용자가 직전 버전 활성화 시 의도한 시드의 silent failure 정정이라 의지 위반 아님 |

### 작성자 책임 (미래 신규 declaration 영역 도입)

코어에서 새 declaration 영역을 추가할 때 (예: `getXxxDefinitions(): array`):

1. `AbstractModule` / `AbstractPlugin` 에 새 declaration 메서드 시그니처 추가
2. `ModuleManager` / `PluginManager` 의 `syncDeclarativeArtifacts()` 묶음에 새 sync 호출 추가
3. 확장 작성자는 자신의 `module.php` / `plugin.php` 에 declaration override (필요 확장만)
4. manifest(`module.json` / `plugin.json`) 변경 불필요 — declaration 은 PHP 클래스 메서드

**자동 정합성 검증**: 정적 audit 도구가 새 declaration 메서드 추가 시 sync 묶음에 누락 없이 반영되는지 검증. 누락 시 빌드/커밋 단계에서 차단된다.

### 권한 정상화 실패 노출

`CoreUpdateService::restoreOwnership()` 는 chown / `chmod g+w` 실패 항목을 누적하여 `getLastPermissionWarnings()` 로 노출. `CoreUpdateCommand` 가 매 호출 직후 콘솔에 실패 경로 + 운영자 수동 복구 명령(`sudo chown -R / chmod g+w`) 을 즉시 안내. upgrade step 에서 권한 정상화를 수행할 때도 동일한 회귀 차단 패턴이 권장된다 (`FilePermissionHelper::chownRecursiveDetailed` / `syncGroupWritabilityDetailed` 사용).

---

## 13. 버전별 데이터 스냅샷 (7.0.0-beta.5+)

### 배경

spawn 자식 (경로 B) 은 디스크의 *최신* 코드/시더/카탈로그를 fresh-load 한다. 멀티 버전 점프 (예: beta.1 → beta.5) 시 beta.2/3/4 의 upgrade step 이 순차 실행되더라도, 각 step 이 호출하는 시더·Manager·헬퍼는 모두 **beta.5 메모리** 위에서 동작한다. 결과: 사용자가 하나씩 단계 업그레이드한 것과 동등하지 않은 데이터 상태.

본 섹션은 이 비대칭을 해소하는 규약을 정의한다 — **카탈로그 / 변환 / 핫픽스 모두를 그 버전 디렉토리 안에 동결**하여 "각 스텝별 동작 100% 동일 보장" invariant 를 성립시킨다.

### 적용 시점

- **코어**: 7.0.0-beta.5 부터 신규 step 의무 (beta.2~4 는 legacy 호환 유지)
- **번들 모듈/플러그인**: `module.json` / `plugin.json` 의 `g7_version` 제약 최소 버전이 `7.0.0-beta.5` 이상이면 *그 확장의 현재 version 부터* 신규 step 의무. 그 미만이면 legacy (가드 미발동)
- **외부 확장 (`modules/{not _bundled}` / `plugins/{not _bundled}`)**: 런타임 가드는 동일하게 발화 (manifest g7_version 판정), audit 만 적용 제외 (사용자 수정 코드 PR 차단 부적합)
- **번들 템플릿/언어팩**: upgrade step 시스템 자체가 부재 — 미래 도입 시 동일 규약 자동 상속

### 확장 작성자의 적용 트리거

확장 작성자가 `g7_version` 을 `>=7.0.0-beta.5` 이상으로 상향하는 시점이 본 규약의 *자동 적용 첫 버전* 이다 (`ExtensionUpgradeGuardHelper::resolveSinceVersion`). 그 이후 `upgrades()` 에서 반환하는 신규 step 은 모두 `AbstractUpgradeStep` 상속 의무 — 미상속 시 `ModuleManager::runUpgradeSteps` / `PluginManager::runUpgradeSteps` 가 `RuntimeException` throw.

| `g7_version` | 확장 working version | 의무 시작 버전 | 효과 |
| --- | --- | --- | --- |
| `>=7.0.0-beta.5` | `1.2.0` | `1.2.0` | `1.2.0` 이상 step 은 모두 AbstractUpgradeStep 의무 |
| `>=7.0.0-beta.4` | `1.2.0` | (legacy) | 가드 미발동 — 신규 step 도 자유 작성 가능 |
| (미선언 / null) | `1.2.0` | (legacy) | 가드 미발동 |

### `dataDir()` 의 코어/확장 자동 분기

`AbstractUpgradeStep::dataDir()` 는 `ReflectionClass($this)->getFileName()` 으로 *상속받은 구체 클래스의 파일 위치* 를 기준으로 data 디렉토리를 계산:

| 상속 위치 | `dataDir()` 결과 |
| --- | --- |
| `upgrades/Upgrade_7_0_0_beta_5.php` (코어) | `upgrades/data/7.0.0-beta.5/` |
| `modules/_bundled/vendor-foo/upgrades/Upgrade_1_2_0.php` | `modules/_bundled/vendor-foo/upgrades/data/1.2.0/` |
| `plugins/_bundled/vendor-bar/upgrades/Upgrade_2_0_0.php` | `plugins/_bundled/vendor-bar/upgrades/data/2.0.0/` |

확장은 코어 인프라(`AbstractUpgradeStep`, `DataSnapshot`, `SnapshotApplier` / `DataMigration` 인터페이스, manifest 스키마) 를 그대로 재사용한다 — 별도 사본 없음.

### 격리 원칙

각 step 은 다음을 보유:

- 스텝 파일 `upgrades/Upgrade_X_Y_Z.php` — `AbstractUpgradeStep` 상속만, 비즈니스 로직 없음
- `upgrades/data/{version}/manifest.json` — kind → delta JSON 파일 매핑
- `upgrades/data/{version}/*.delta.json` — 카탈로그 시드 delta (added / removed / renamed)
- `upgrades/data/{version}/appliers/{Kind}Applier.php` — delta JSON 적용기 (버전 namespace)
- `upgrades/data/{version}/migrations/*.php` — 변환 / 단발성 핫픽스 (버전 namespace)

namespace 규약 (코어/확장 자동 분기):

| 위치 | namespace |
| --- | --- |
| 코어 (`upgrades/data/{ver}/`) | `App\Upgrades\Data\V{token}\(Appliers\|Migrations)` |
| 번들 모듈 (`modules/_bundled/{id}/upgrades/data/{ver}/`) | `App\Upgrades\Data\Ext\Modules\{StudlyId}\V{token}\(Appliers\|Migrations)` |
| 번들 플러그인 (`plugins/_bundled/{id}/upgrades/data/{ver}/`) | `App\Upgrades\Data\Ext\Plugins\{StudlyId}\V{token}\(Appliers\|Migrations)` |
| 외부 모듈 (`modules/{id}/upgrades/data/{ver}/`) | 동일 패턴 (Ext\Modules) — 사용자 수정 경로도 격리 |
| 외부 플러그인 (`plugins/{id}/upgrades/data/{ver}/`) | 동일 패턴 (Ext\Plugins) |

`{token}` = 점·하이픈을 underscore 로 치환 (예: `7.0.0-beta.5` → `V7_0_0_beta_5`).
`{StudlyId}` = 확장 식별자 hyphen/underscore 를 StudlyCase 로 변환 (예: `sirsoft-ecommerce` → `SirsoftEcommerce`).

`DataSnapshot::versionedNamespace($context, $sourceLocation)` 가 `$sourceLocation` 경로의 `modules|plugins` 마커 substring 을 기준으로 분기 — 코어/확장의 같은 step 버전이라도 *서로 다른 namespace* 가 부여되어 PHP compile-time fatal ("Cannot declare class ...") 회귀가 차단된다.

### `AbstractUpgradeStep` 위임 흐름

```php
final public function run(UpgradeContext $context): void
{
    $this->dataSnapshot($context)->apply($context);    // Applier 순차 실행
    foreach ($this->dataMigrations($context) as $m) {  // Migration 순차 실행 (파일명 정렬 순)
        $m->run($context);
    }
    $this->postRun($context);                           // 거의 사용 안 함
}
```

`dataSnapshot()` / `dataMigrations()` 모두 default impl 이 `data/{version}/` 을 스캔 + `require_once` + 버전 namespace 클래스 인스턴스화. 일반 케이스는 override 불필요.

### 실행 순서 제어

`dataMigrations()` 는 `data/{version}/migrations/*.php` 를 파일명 alphabetical 정렬 순으로 실행. 명시적 순서가 필요하면 파일명에 두 자리 숫자 prefix 사용:

```text
01_RecoverActiveExtensionDirs.php
02_RecoverPendingStubFiles.php
03_VerifyBundledLangPacksFallback.php
04_IdentityPermissionPivotMerge.php
05_RecoverPublicStorageSymlink.php
```

클래스명 자체는 prefix 없이 (PHP 식별자 제약). `AbstractUpgradeStep` 이 매핑 시 정규식 `/^\d{2,}_/` 으로 제거.

### Delta JSON 스키마

`permissions.delta.json` 예:

```json
{
  "added": [
    {
      "identifier": "core.foo.read",
      "type": "admin",
      "category": "core.foo",
      "name": { "ko": "Foo 조회", "en": "Read Foo" }
    }
  ],
  "removed": ["core.legacy.x"],
  "renamed": [
    { "from": "core.old.key", "to": "core.new.key" }
  ]
}
```

`role_permissions.delta.json` 예:

```json
{
  "grants": [{ "role": "user", "permission": "core.notifications.read" }],
  "revokes": [{ "role": "user", "permission": "core.legacy.read" }]
}
```

각 Applier 가 동일 패턴으로 added/removed/renamed (또는 grants/revokes) 를 idempotent SQL 로 적용.

### Applier / Migration 작성 의무

- **raw JSON 만 read** (Applier) — 시더 클래스 `Database\Seeders\*` 참조 금지 (fresh-load invariant)
- **idempotent** — `Schema::hasColumn` / `where->exists()` 가드 동반
- **V-1 안전 강화** — `app(*Service|*Manager|*Repository::class)` 호출 금지 (audit `upgrade-step-data-snapshot` 가 error 로 차단)
- **버전 격리** — 다른 버전 namespace `App\Upgrades\Data\V{other}\*` 참조 금지
- **로컬 헬퍼 + Illuminate 파사드만 사용** — 신규 도입 클래스 / 미래 변경 가능 클래스 의존 회피
- **공용 헬퍼 사용 시 신중** — `FilePermissionHelper::copyDirectory` 처럼 V-1 안전 검증된 헬퍼만 허용 (audit 룰이 미허용 호출은 차단)

### 강제 메커니즘

| 시점 | 영역 | 메커니즘 | 동작 |
| --- | --- | --- | --- |
| 런타임 | 코어 | `CoreUpdateService::runUpgradeSteps()` 의 instance 검증 | 버전 ≥ beta.5 인데 `AbstractUpgradeStep` 미상속 → `CoreUpdateOperationException` throw → update 전체 중단 → 백업 복원 |
| 런타임 | 모듈 | `ModuleManager::runUpgradeSteps()` 내 `ExtensionUpgradeGuardHelper` 호출 | manifest g7_version 기반 since-version 판정 → 미상속 시 `RuntimeException` throw |
| 런타임 | 플러그인 | `PluginManager::runUpgradeSteps()` 내 `ExtensionUpgradeGuardHelper` 호출 | 동일 패턴 (식별자만 다름) |
| PR 시점 | 코어 + 번들 모듈/플러그인 | audit rule `upgrade-step-data-snapshot` (severity: error) | namespace 불일치 / Seeders use / app() 호출 / 다른 버전 참조 / 공용 디렉토리 사용 자동 차단. 확장 경로는 manifest g7_version 기반 since-version 으로 검사 범위 결정 |

면제: `// audit:allow upgrade-step-data-snapshot reason: ...` 인라인 주석. legacy 미들 케이스 또는 임시 우회 시.

### 확장간 namespace 격리

`DataSnapshot::versionedNamespace($context, $sourceLocation)` 가 step 파일/data 디렉토리 경로의 `modules|plugins` 마커로 코어/확장을 분기하여 namespace 를 결정. 결과:

- 두 다른 모듈 (`vendor-foo` / `vendor-bar`) 이 동일 step 버전(예: `1.0.0`) 을 가져도 namespace 가 각자 격리:
  - `App\Upgrades\Data\Ext\Modules\VendorFoo\V1_0_0\Migrations\Shared`
  - `App\Upgrades\Data\Ext\Modules\VendorBar\V1_0_0\Migrations\Shared`
- `require_once` 가 두 파일을 로드해도 별개 FQCN 이라 PHP compile-time fatal ("Cannot declare class ...") 없음.
- 모듈 vs 플러그인 vs 코어 namespace 도 서로 격리.

회귀 안전망:

- `tests/Unit/Extension/Upgrade/DataSnapshotTest::test_versionedNamespace_different_extensions_same_step_version_produce_different_namespaces` — 정적 검증
- `tests/Feature/Upgrades/ExtensionAbstractUpgradeStepFullFlowTest::test_two_extensions_with_same_step_version_isolated_by_namespace` — 실제 실행 회귀 검증 (두 확장 fixture 의 같은 step 버전 + 같은 클래스명 → 양쪽 모두 자기 코드 실행 확인)

### dogfood — 7.0.0-beta.5

본 규약의 첫 사례:

- `upgrades/Upgrade_7_0_0_beta_5.php` — `extends AbstractUpgradeStep` 만 선언, 본문 비어있음
- `upgrades/data/7.0.0-beta.5/manifest.json` — `permissions` kind 1건
- `upgrades/data/7.0.0-beta.5/permissions.delta.json` — IDV 권한 식별자 rename 2건
- `upgrades/data/7.0.0-beta.5/appliers/PermissionsApplier.php` — added/removed/renamed (부재 경로) 적용기
- `upgrades/data/7.0.0-beta.5/migrations/` — 5종:
  1. `01_RecoverActiveExtensionDirs.php` — #347 회귀 후속: 4개 도메인 활성 디렉토리 복구
  2. `02_RecoverPendingStubFiles.php` — #347 회귀 후속: _pending stub 재생성
  3. `03_VerifyBundledLangPacksFallback.php` — #347 회귀 후속: lang-packs/_bundled fallback
  4. `04_IdentityPermissionPivotMerge.php` — IDV 권한 rename 충돌 경로 피벗 병합
  5. `05_RecoverPublicStorageSymlink.php` — public/storage symlink 복구

beta.4 까지 출시본의 박제된 핫픽스 모든 동작이 본 격리 구조로 100% 보존되며, 미래 버전이 이 디렉토리를 *수정하지 않는 한* (수정은 audit error) beta.1 → beta.7 같은 멀티 점프에서도 동일한 결과를 보장한다.

---

## 관련 문서

- [코어 업데이트 시스템](../backend/core-update-system.md)
- [확장 업데이트 시스템](extension-update-system.md)
- [Changelog 규칙](changelog-rules.md)
- [데이터 동기화 Helper](../backend/data-sync-helpers.md)
- [사용자 수정 보존](../backend/user-overrides.md)
