# 업그레이드 스텝 작성 가이드 (Upgrade Step Guide)

> 버전 업그레이드 시 실행되는 `upgrades/Upgrade_X_Y_Z.php` 작성 규정

## TL;DR (5초 요약)

```text
1. upgrade step 이 실행되는 환경은 경로에 따라 다르다 — 섹션 9 "업그레이드 경로" 먼저 읽기
2. 대부분의 코어 upgrade step = 경로 B (spawn) → 신규 클래스/메서드 자유 사용 가능
3. 인프라 재설계 릴리즈의 특수 경로 = 경로 C → docblock 에 `@upgrade-path C` 선언 + 로컬 로직 필수
4. 경로 A (모듈/플러그인) · 경로 C 규율: 기존 클래스의 신규 메서드 호출 금지, 로컬 private 헬퍼 우선
5. 모든 분기에 upgrade.log 출력 — 로그 없음 = 디버깅 단서 없음
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

---

## 1. 배경 — 왜 이 가이드가 필요한가

`php artisan core:update` 실행 흐름:

```
Step 7  applyUpdate         — 디스크의 app/**, config/**, upgrades/** 파일을 새 버전으로 덮어쓰기
Step 8  Composer / vendor   — 외부 프로세스 실행으로 vendor 재구성
Step 9  runMigrations       — DB 마이그레이션
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

`@upgrade-path C` 선언은 아래 자동화 스크립트가 인식한다:

- [`.claude/scripts/validate-upgrade-step.cjs`](../../.claude/scripts/validate-upgrade-step.cjs) — 파일 내용을 파싱하여 경로 C 선언 시 강한 규율 경고 적용
- [`.claude/scripts/file-rules.cjs`](../../.claude/scripts/file-rules.cjs) — 동일 선언 기반으로 `upgradeStep` 규칙이 경로 C 위반(기존 코어 클래스 use 문) 감지

선언이 없으면 **경로 B** 로 판정되어 규율이 완화된다 (신규 클래스/메서드 자유 사용).

### 경로 판별 체크리스트

upgrade step 작성 전 다음을 확인:

1. 확장(모듈/플러그인) upgrade step 인가? → **경로 A**
2. 코어 upgrade step 인가?
   - 대상 릴리즈가 인프라 재설계(spawn 구조 등) 를 포함하여 이전 버전이 새 진입점을 모르는가?
     - **예** → **경로 C**, 파일에 `@upgrade-path C` 선언 + 로컬 로직 필수
     - **아니오** → **경로 B**, 규율 완화 (대부분의 경우)

경로 B 라고 판단했더라도, proc_open 차단 환경에서는 in-process fallback 이 작동하므로 **가능하면 경로 A/C 규율도 충족** 하도록 작성하는 것이 안전하다.

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

## 관련 문서

- [코어 업데이트 시스템](../backend/core-update-system.md)
- [확장 업데이트 시스템](extension-update-system.md)
- [Changelog 규칙](changelog-rules.md)
- [데이터 동기화 Helper](../backend/data-sync-helpers.md)
- [사용자 수정 보존](../backend/user-overrides.md)
