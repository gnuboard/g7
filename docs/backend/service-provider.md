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

| 환경 | `installer_completed` | 동작 |
|------|----------------------|------|
| 프로덕션 (설치 완료) | `true` | 가드 통과 → `hasTable` 스킵 (쿼리 0건) |
| 인스톨러 실행 중 / 마이그레이션 전 | `false` (기본값) | 기존 `hasTable` 폴백 경로 (원본 동작 보존) |
| 테스트 (`.env.testing` 에 플래그 없음) | `false` | 기존 `hasTable` 경로 |

### 적용 가이드

- **`.env` 파일 체크는 반드시 유지** — 인스톨러가 아직 `.env` 를 생성하지 않은 시점을 커버
- **`hasTable` 폴백 경로는 반드시 유지** — `installer_completed=false` 환경에서도 안전하게 부팅되어야 함
- **try/catch 도 그대로 유지** — DB 연결 실패 시 안전한 스킵 계약 준수
- 가드는 hasTable 체크 **전체 블록을 `if (! config('app.installer_completed'))` 로 래핑** 하는 형태
- `config:cache` 를 사용하는 환경에서는 `.env` 값 변경 후 반드시 `php artisan config:clear && php artisan config:cache` 를 실행해야 함

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

## 관련 이슈

- 인스톨러에서 `composer install` 시 DB 접근 오류
- 마이그레이션 전 애플리케이션 부팅 실패
- CI/CD 파이프라인에서 의존성 설치 오류

---

## 관련 문서

- [middleware.md](./middleware.md) - 미들웨어 등록 규칙
- [authentication.md](./authentication.md) - 인증 및 세션 처리
- [index.md](./index.md) - 백엔드 가이드 인덱스
