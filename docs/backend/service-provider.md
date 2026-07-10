# 서비스 프로바이더 안전성

> **목차**: [index.md](./index.md) | [enum.md](./enum.md) | [controllers.md](./controllers.md) | [service-repository.md](./service-repository.md) | [validation.md](./validation.md) | [exceptions.md](./exceptions.md) | [api-resources.md](./api-resources.md) | [routing.md](./routing.md) | [response-helper.md](./response-helper.md) | [middleware.md](./middleware.md) | [authentication.md](./authentication.md) | **service-provider.md**

---

## TL;DR (5초 요약)

```text
1. DB 접근 전 .env 파일 존재 확인 필수
2. 테이블 존재 여부 Schema::hasTable() 체크
3. 인스톨러 안정성: 마이그레이션 전에도 부팅 가능해야 함
4. 조건 미충족 시 예외 대신 안전하게 스킵
5. runningInConsole() + 'migrate' 명령 감지로 스킵
6. 성능 최적화: config('app.installer_completed') 가드로 설치 완료 환경에서 hasTable 호출 제거
```

---

## 목차

1. [핵심 원칙](#핵심-원칙)
2. [검증이 필요한 경우](#검증이-필요한-경우)
3. [검증 체크리스트](#검증-체크리스트)
4. [잘못된 예시](#잘못된-예시)
5. [올바른 예시](#올바른-예시)
6. [실행 흐름 다이어그램](#실행-흐름-다이어그램)
7. [다른 서비스 프로바이더 적용 예시](#다른-서비스-프로바이더-적용-예시)
8. [서비스 프로바이더 개발 체크리스트](#서비스-프로바이더-개발-체크리스트)
9. [인스톨러 테스트 시나리오](#인스톨러-테스트-시나리오)

---

## 핵심 원칙

```text
필수: 서비스 프로바이더에서 DB 접근 전 .env + 테이블 존재 확인
필수: .env 파일 및 테이블 존재 여부 확인 후 접근
```

**핵심 원칙**:

- **인스톨러 안정성**: `.env` 파일이 없는 상태에서도 `composer install` 실행 가능해야 함
- **단계적 초기화**: DB 테이블이 없는 상태(마이그레이션 전)에서도 애플리케이션 부팅 가능해야 함
- **안전한 스킵**: 조건 미충족 시 예외 발생 대신 안전하게 건너뛰기

---

## 검증이 필요한 경우

| 상황 | 검증 항목 | 필요 이유 |
|------|----------|----------|
| DB 쿼리 실행 | `.env` 파일, 테이블 존재 | 인스톨러 실행 전 오류 방지 |
| 모듈/플러그인 로드 | 관련 테이블 존재 | 마이그레이션 전 오류 방지 |
| 설정값 로드 | `.env` 파일 존재 | 초기 설정 전 오류 방지 |

---

## 검증 체크리스트

1. **`.env` 파일 존재 여부**: `File::exists(base_path('.env'))`
2. **필수 테이블 존재 여부**: `Schema::hasTable('table_name')`
3. **조건 미충족 시 안전하게 return**

---

## 잘못된 예시

```php
// ❌ 검증 없이 DB 접근 - 인스톨러 실행 시 오류 발생
class ModuleRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->routes(function () {
            $this->loadModuleRoutes();
        });
    }

    protected function loadModuleRoutes(): void
    {
        $modulesPath = base_path('modules');

        if (! File::exists($modulesPath)) {
            return;
        }

        // ❌ .env 파일이나 테이블 확인 없이 DB 쿼리 실행
        $activeModules = Module::where('status', 'active')->get();

        foreach ($activeModules as $module) {
            // 라우트 로드...
        }
    }
}
```

**오류 시나리오**:

```bash
# 인스톨러 실행 중
composer install
↓
package:discover 자동 실행
↓
ModuleRouteServiceProvider 로드
↓
Module::where() 실행 시도
↓
❌ SQLSTATE[HY000] [1045] Access denied (using password: NO)
```

---

## 올바른 예시

### 1. .env 파일 체크 추가

```php
// ✅ .env 파일 및 테이블 존재 확인
class ModuleRouteServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->routes(function () {
            $this->loadModuleRoutes();
        });
    }

    protected function loadModuleRoutes(): void
    {
        // ✅ .env 파일이 없으면 스킵 (인스톨러 실행 전)
        if (! File::exists(base_path('.env'))) {
            return;
        }

        $modulesPath = base_path('modules');

        if (! File::exists($modulesPath)) {
            return;
        }

        // ✅ 데이터베이스 테이블이 존재하지 않으면 스킵 (마이그레이션 전)
        if (! Schema::hasTable('modules')) {
            return;
        }

        // 안전하게 DB 접근
        $activeModules = Module::where('status', ExtensionStatus::Active->value)
            ->pluck('identifier')
            ->toArray();

        foreach ($activeModules as $moduleIdentifier) {
            // 라우트 로드...
        }
    }
}
```

### 2. Service 클래스에서의 적용

```php
class TemplateService
{
    /**
     * 활성화된 모듈의 routes 데이터 로드
     */
    private function loadActiveModulesRoutesData(): array
    {
        // ✅ .env 파일이 없으면 빈 배열 반환 (인스톨러 실행 전)
        if (! file_exists(base_path('.env'))) {
            return [];
        }

        // ✅ modules 테이블이 없으면 빈 배열 반환 (마이그레이션 전)
        if (! Schema::hasTable('modules')) {
            return [];
        }

        // 안전하게 모듈 데이터 로드
        $routes = [];
        $activeModules = $this->moduleManager->getActiveModules();

        foreach ($activeModules as $module) {
            // routes.json 로드...
        }

        return $routes;
    }
}
```

---

## 실행 흐름 다이어그램

```
인스톨러 단계 1: composer install
├─ .env 없음 → ModuleRouteServiceProvider 스킵 ✅
└─ package:discover 정상 완료

인스톨러 단계 2: .env 생성
├─ DB 연결 정보 입력
└─ .env 파일 생성 완료

인스톨러 단계 3: 마이그레이션
├─ modules 테이블 없음 → ModuleRouteServiceProvider 스킵 ✅
└─ 테이블 생성 완료

설치 완료 후:
├─ .env 있음 ✅
├─ modules 테이블 있음 ✅
└─ ModuleRouteServiceProvider 정상 실행 ✅
```

---

## 다른 서비스 프로바이더 적용 예시

### PluginRouteServiceProvider

```php
class PluginRouteServiceProvider extends ServiceProvider
{
    protected function loadPluginRoutes(): void
    {
        // ✅ 동일한 패턴 적용
        if (! File::exists(base_path('.env'))) {
            return;
        }

        if (! Schema::hasTable('plugins')) {
            return;
        }

        // 플러그인 라우트 로드...
    }
}
```

### ConfigServiceProvider

```php
class ConfigServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        // ✅ 동일한 패턴 적용
        if (! File::exists(base_path('.env'))) {
            return;
        }

        if (! Schema::hasTable('settings')) {
            return;
        }

        // 동적 설정 로드...
    }
}
```

---

## 성능 최적화: installer_completed 가드

```text
필수: 인스톨러 안전 체크(hasTable 폴백) 는 유지하되,
      프로덕션(설치 완료) 환경에서는 `config('app.installer_completed')` 가드로
      매 요청 Schema::hasTable() 호출을 건너뛴다.
```

### 배경

`Schema::hasTable()` 은 `information_schema.tables` 에 대한 DB 쿼리를 실행합니다. 매 HTTP 요청마다 여러 ServiceProvider 와 확장 Trait 에서 이 체크가 반복되면 수십 ms 의 누적 오버헤드가 발생합니다. 설치가 완료된 프로덕션 환경에서는 테이블 존재가 **앱 수명주기 동안 불변** 이므로 이 체크 자체가 불필요합니다.

### 구현 패턴

`.env` 의 `INSTALLER_COMPLETED=true` 플래그를 `config/app.php` 에 노출하여 사용합니다:

```php
// config/app.php
'installer_completed' => env('INSTALLER_COMPLETED', false),
```

```php
// ❌ 매 요청 DB 쿼리 (개선 전)
protected function loadModuleRoutes(): void
{
    if (! File::exists(base_path('.env'))) {
        return;
    }

    try {
        if (! Schema::hasTable('modules')) {
            return;
        }
        if (! Schema::hasColumn('modules', 'identifier')) {
            return;
        }
    } catch (\Exception) {
        return;
    }

    // 라우트 로드...
}
```

```php
// ✅ installer_completed 가드 적용 (개선 후)
protected function loadModuleRoutes(): void
{
    if (! File::exists(base_path('.env'))) {
        return;
    }

    // 설치 완료 상태에서는 Schema introspection 을 건너뜀 (매 요청 쿼리 제거).
    // 인스톨러 이전 환경에서는 기존 체크 경로로 폴백.
    if (! config('app.installer_completed')) {
        try {
            if (! Schema::hasTable('modules')) {
                return;
            }
            if (! Schema::hasColumn('modules', 'identifier')) {
                return;
            }
        } catch (\Exception) {
            return;
        }
    }

    // 라우트 로드...
}
```

### 동작 표

| 환경 | `installer_completed` | 마이그레이션 명령 | 동작 |
|------|----------------------|------------------|------|
| 프로덕션 (설치 완료, 웹 요청) | `true` | 아님 | 가드 통과 → `hasTable` 스킵 (쿼리 0건) |
| 설치 완료 `.env` 복사 + 빈 DB (마이그레이션 전) | `true` | **실행 중** | fast-path 무력화 → `hasTable` 폴백 (table not found 부팅 실패 방지) |
| 인스톨러 실행 중 / 마이그레이션 전 | `false` (기본값) | — | 기존 `hasTable` 폴백 경로 (원본 동작 보존) |
| 테스트 (`.env.testing` 에 플래그 없음) | `false` | — | 기존 `hasTable` 경로 |

두 번째 행이 이 가드의 핵심 케이스다. `INSTALLER_COMPLETED=true` 가 적힌 `.env` 를 빈 DB 새 서버에 복사한 뒤 `php artisan migrate` 로 테이블을 만들기 **전** 에 앱이 부팅되면, fast-path 가 테이블 존재를 잘못 전제하여 뒤따르는 쿼리가 "table not found" 로 부팅을 깨뜨린다. 이 컨텍스트에서는 fast-path 를 신뢰하지 않고 실제 `hasTable` 검증 경로로 폴백해야 한다 (아래 "마이그레이션 컨텍스트 가드" 참조).

### 적용 가이드

- **`.env` 파일 체크는 반드시 유지** — 인스톨러가 아직 `.env` 를 생성하지 않은 시점을 커버
- **`hasTable` 폴백 경로는 반드시 유지** — `installer_completed=false` 환경에서도 안전하게 부팅되어야 함
- **try/catch 도 그대로 유지** — DB 연결 실패 시 안전한 스킵 계약 준수
- 가드는 hasTable 체크 **전체 블록을 `if (! config('app.installer_completed'))` 로 래핑** 하는 형태
- **fast-path 이후 쿼리가 try/catch 로 보호되지 않는 지점은 마이그레이션 컨텍스트 가드를 함께 적용** (아래 참조). 예외로 보호되는 지점은 빈 DB 에서도 안전하므로 불필요

### 마이그레이션 컨텍스트 가드

`installer_completed` fast-path 는 테이블 존재를 전제한다. 그러나 `INSTALLER_COMPLETED=true` 가 적힌 `.env` 를 빈 DB 새 서버에 복사한 뒤 `php artisan migrate` 로 테이블을 만들기 **전** 에 앱이 부팅되면, fast-path 가 `true` 를 반환하고 곧이어 실제 쿼리(`Model::where()->pluck()` 등)가 실행되어 "table not found" 로 부팅이 실패한다. 마이그레이션을 실행하려는 부팅에서 그 명령이 오히려 실패하는 닭-달걀 상태다.

이를 막기 위해, 스키마를 파괴/재생성하는 마이그레이션 계열 콘솔 명령 판정을 `App\Support\InstallerContext::isSchemaMutatingCommand()` 로 단일 SSoT 화하고, fast-path 지점에 가드로 결합한다. 명령 리스트: `migrate`, `migrate:fresh`, `migrate:refresh`, `migrate:rollback`, `migrate:reset`, `db:wipe`.

```php
// App\Support\InstallerContext
public static function isSchemaMutatingCommand(): bool
{
    if (! app()->runningInConsole()) {
        return false;
    }

    return in_array(
        $_SERVER['argv'][1] ?? null,
        ['migrate', 'migrate:fresh', 'migrate:refresh', 'migrate:rollback', 'migrate:reset', 'db:wipe'],
        true
    );
}
```

가드 결합 방식은 지점의 **논리 극성** 에 따라 다르다:

- **Trait (fast-path 통과 조건)** — `&&` 로 결합. 마이그레이션 중이면 fast-path 를 건너뛰고 hasTable 폴백으로 진입.

  ```php
  if (! InstallerContext::isSchemaMutatingCommand() && config('app.installer_completed')) {
      return true;
  }
  ```

- **RouteServiceProvider (hasTable 검증 블록 진입 조건)** — `||` 로 결합. 마이그레이션 중이면 hasTable 검증 경로로 진입해 빈 DB 시 early return → 무방비 `pluck` 도달 차단.

  ```php
  if (! config('app.installer_completed') || InstallerContext::isSchemaMutatingCommand()) {
      try {
          if (! Schema::hasTable('modules')) {
              return;
          }
          // ...
      } catch (\Exception) {
          return;
      }
  }
  ```

`isRegistryReady()` 처럼 fast-path 앞에서 조기 반환하는 지점은 `if (InstallerContext::isSchemaMutatingCommand()) { return false; }` 로 단순 게이트한다.

#### 부류 A (가드 필수) vs 부류 B (불필요)

| 부류 | 조건 | 예 | 조치 |
|------|------|-----|------|
| A | fast-path 이후 쿼리가 try/catch 로 **미보호** → 빈 DB 에서 부팅 실패 | `Caches{Module,Plugin,Template}Status`, `Module/PluginRouteServiceProvider` | 마이그레이션 컨텍스트 가드 적용 |
| B | fast-path 이후 쿼리가 try/catch(\Throwable) 로 **보호** → 예외를 삼켜 안전한 기본값 반환 | `NotificationHookListener`, `IdentityPolicyRepository::{listHookTargets,activeExtensionIdentifiers}` | 가드 불필요 (예외 흡수로 안전) |

### config 캐시 재생성 주의

- `config:cache` 는 config 소스를 변경하는 라이프사이클에서 `App\Support\ConfigCacheHelper::rebuild()` 로 자동 재생성된다 (아래 "config 캐시 자동 재생성" 참조). `.env` 를 직접 편집한 경우처럼 헬퍼를 거치지 않는 변경은 여전히 `php artisan config:clear && php artisan config:cache` 를 수동 실행한다

### 확장 Trait 패턴

`CachesModuleStatus::isExtensionTableReady()`, `CachesPluginStatus::isPluginTableReady()`, `CachesTemplateStatus::isTemplateTableReady()` 등 확장 Trait 에도 동일 가드를 적용합니다:

```php
private static function isExtensionTableReady(string $table): bool
{
    if (config('app.installer_completed')) {
        return true;
    }

    try {
        DB::connection()->getPdo();

        return Schema::hasTable($table);
    } catch (\Throwable $e) {
        return false;
    }
}
```

### 부팅 경로 Listener / Repository 적용

ServiceProvider 와 Trait 외에도, `CoreServiceProvider::boot()` 말미(동적 훅 등록 / IDV 정책 동기화)에서 매 요청 실행되는 다음 지점도 동일 가드를 적용합니다:

- `NotificationHookListener::registerDynamicHooks()` — `notification_definitions` 존재 확인 (단일 최대 비용)
- `IdentityPolicyRepository::listHookTargets()` — `identity_policies` 존재 확인
- `IdentityPolicyRepository::activeExtensionIdentifiers()` — `modules` / `plugins` 존재 확인 (테이블 부재 시 `null` 반환으로 필터 미적용 계약 보존)

```php
// hasTable 을 단독 조건이 아니라 installer_completed 와 && 로 단락.
// 설치 완료 시 hasTable 호출 자체를 건너뛰고 후속 로직으로 진행한다.
if (! config('app.installer_completed') && ! Schema::hasTable('notification_definitions')) {
    return;
}
```

`try/catch` 블록 안에 있는 Repository 조회는 catch 계약(테이블 부재/DB 오류 시 `[]` 또는 `null` 반환)을 그대로 보존한 채 조건만 확장한다.

### 부팅 경로 정적 훅 등록 캐시

`installer_completed` 가드가 부팅 시 DB 조회를 줄이는 것과 별개로, `CoreServiceProvider::boot()` 의 정적 훅 리스너 등록(`app/Listeners` 재귀 스캔 + 모듈/플러그인 `getHookListeners()` 클래스 로딩)은 `bootstrap/cache/hooks.php` 캐시로 매 요청 스캔·리플렉션을 제거한다. 캐시 부재/테스트 환경에서는 기존 스캔 경로로 안전 폴백하며, 등록 결과는 스캔 경로와 바이트 동일하다. 상세: [extension/hooks.md "정적 훅 매핑 캐시"](../extension/hooks.md).

### 주의사항

- **인스톨러 설치 완료 시점에 `INSTALLER_COMPLETED=true` 가 `.env` 에 기록되어야 함** — G7 인스톨러(`public/install/includes/task-runner.php`)는 이를 이미 수행
- 가드는 **성능 최적화이며 안전 체크 대체가 아님** — 하드웨어 장애 등으로 테이블이 소실된 경우를 커버하지 못함. 그런 상황은 별도 헬스체크로 처리
- 같은 HTTP 요청 내에서 테이블을 생성/삭제하는 플로우(예: 설치 직후 검증)가 있다면 가드가 stale 결과를 줄 수 있으므로 그 경로에서는 직접 `Schema::hasTable()` 호출 권장

---

## 서비스 프로바이더 개발 체크리스트

- [ ] DB 접근이 필요한 경우 `.env` 파일 존재 확인
- [ ] 특정 테이블 접근 시 `Schema::hasTable()` 확인
- [ ] 조건 미충족 시 예외 발생 대신 `return`으로 안전하게 스킵
- [ ] `config('app.installer_completed')` 가드로 hasTable 블록 래핑 (성능 최적화)
- [ ] 인스톨러 환경에서 테스트 수행 (`INSTALLER_COMPLETED` 미설정 상태)
- [ ] `composer install` 단독 실행 테스트

---

## 인스톨러 테스트 시나리오

```bash
# 1. .env 없이 composer install
rm .env
composer install
# 예상: 정상 완료 (오류 없음)

# 2. .env 생성 후 애플리케이션 부팅
cp .env.example .env
php artisan config:clear
# 예상: 정상 부팅 (modules 테이블 없어도 오류 없음)

# 3. 마이그레이션 후 정상 동작
php artisan migrate
# 예상: 모든 기능 정상 동작
```

---

## config 캐시 자동 재생성 (ConfigCacheHelper)

config 소스(`config/*.php`, `.env`, `storage/app/settings/*.json`, 활성 확장 목록)를 변경하는 라이프사이클은 `App\Support\ConfigCacheHelper::rebuild()` 를 호출해 config 캐시를 재빌드한다.

### 배경

과거 설정 변경/코어 업데이트/APP_KEY 재생성 등은 `config:clear` 만 하고 `config:cache` 를 재생성하지 않았다. 그 결과 관리자가 "시스템 최적화"(`optimizeSystem`)로 캐시를 만들어도, 이후 설정을 한 번 저장하면 캐시가 비워진 채 재생성되지 않아 이후 모든 요청이 config 파일을 재파싱했다(성능 손실). `rebuild()` 는 clear 직후 재생성까지 수행해 캐시가 비활성 상태로 잔존하지 않게 한다.

### 정책

- **환경 무관 항상 재생성** — config:cache 는 그 자체로 부팅 비용을 줄이고, G7 설정은 config 캐시에 박제되지 않고 매 요청 런타임 `Config::set()` 으로 재주입되므로(설정 stale 없음) 항상 켜두는 것이 이득이다.
- **testing 환경**: `config:clear` 는 수행(값 반영 계약 유지)하되 `config:cache` 생성은 스킵(캐시된 config 가 다음 테스트로 누출되는 격리 파괴 방지).
- **설치 미완료 상태**: 불완전 config 박제를 피하려 재생성을 스킵하고 clear 만 한다.
- **local 개발 주의**: config:cache 가 켜지면 `config/*.php` 를 직접 수정해도 다음 요청에 반영되지 않는다. 이는 개발자가 `php artisan config:clear` 로 대응하는 개발자 책임 영역이다.

### 적용 지점

| 라이프사이클 | 재생성 위치 |
| --- | --- |
| 코어 설치 완료 | 인스톨러 `complete_flag` 직후 `config:cache` task |
| 코어 업데이트 | `CoreUpdateCommand` Step 11 완료 지점 |
| 코어 업그레이드 스텝(단독 실행) | `ExecuteUpgradeStepsCommand` 캐시 정리 블록 (spawn 자식은 부모가 처리) |
| 코어 관리자 설정 저장 / 캐시 정리 / GeoIP / APP_KEY | `SettingsService` / `GeoIpDatabaseService` 의 각 지점 |
| 확장 설치/삭제/업데이트 | `ExtensionManager::updateComposerAutoload()` (오토로드·훅 캐시와 동일 생명주기) |
| 확장 활성화/비활성화 | `ExtensionConfigCacheListener` (`core.*.activated` / `core.*.after_deactivate` 훅 구독) |

`ExtensionConfigCacheListener` 의 구독 훅은 `getSubscribedHooks()` 에서 `'sync' => true` 로 선언한다. 훅 Action 리스너의 기본 등록 정책은 큐 디스패치(`HookListenerRegistrar`)인데, config 캐시 재생성을 큐로 미루면 (1) 워커 미가동 환경에서 영영 재생성되지 않고, (2) 확장 토글을 수행하는 CLI 커맨드(`plugin:deactivate` 등)는 실행 후 프로세스가 종료되어 큐 작업을 처리할 주체가 없다. config 캐시 재생성은 인프라 부수효과이므로 반드시 동기 실행한다. 회귀 방지 테스트: `ExtensionConfigCacheListenerTest::test_all_hooks_are_synchronous`.

**확장별 개별 환경설정 저장(board/ecommerce/plugin SettingsService)은 재생성 대상이 아니다.** 확장 설정은 `config/*.php` 가 아니라 settings JSON 에 저장되고, 매 요청 `CoreServiceProvider::boot` 의 `loadModule/PluginSettingsToConfig` 가 런타임 `Config::set('g7_settings.*', 최신값)` 으로 config 캐시에 박제된 값을 덮어쓴다. 따라서 값 stale 이 없고(즉시 반영), config 캐시를 clear 하지도 않으므로(성능 손실 없음) 재생성이 실효가 없다.

---

## 관련 이슈

- 인스톨러에서 `composer install` 시 DB 접근 오류
- 마이그레이션 전 애플리케이션 부팅 실패
- CI/CD 파이프라인에서 의존성 설치 오류

---

## 관련 문서

- [middleware.md](./middleware.md) - 미들웨어 등록 규칙
- [authentication.md](./authentication.md) - 인증 및 세션 처리
- [index.md](./index.md) - 백엔드 가이드 인덱스
