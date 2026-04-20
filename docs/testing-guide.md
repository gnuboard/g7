# 그누보드7 테스트 가이드

> 이 문서는 그누보드7의 테스트 작성 및 실행 규칙을 상세히 설명합니다.

---

## TL;DR (5초 요약)

```text
1. 테스트 통과 = 작업 완료 (작성만으로 불충분!)
2. 기존 테스트 있음 → 변경사항 반영하여 수정 후 실행
3. 기존 테스트 없음 → 새 테스트 작성 후 실행
4. 백엔드: php artisan test --filter=TestName
5. _bundled 확장 테스트: 활성 디렉토리 복사 불필요 → _bundled에서 직접 실행
6. 모듈/플러그인 프론트엔드 테스트는 해당 디렉토리에서 독립 실행
```

---

## 목차

- [필수 원칙](#필수-원칙)
- [모듈/플러그인 프론트엔드 테스트 독립성](#모듈플러그인-프론트엔드-테스트-독립성)
- [DDL 트랜잭션 격리 문제](#ddl-트랜잭션-격리-문제)
- [테스트 작성 패턴](#테스트-작성-패턴)
- [체크리스트](#체크리스트)

---

## _bundled 확장 직접 테스트

```
✅ _bundled 디렉토리에서 PHPUnit/Vitest 직접 실행 가능
✅ 활성 디렉토리(modules/vendor-module/)로 복사 불필요
✅ tests/bootstrap.php가 _bundled를 자동으로 우선 오토로드
프로덕션 반영은 별도로 module:update 필요 (테스트만 직접 가능)
```

### 백엔드 (PHPUnit)

```bash
# _bundled 모듈 전체 테스트
php vendor/bin/phpunit modules/_bundled/sirsoft-ecommerce/tests

# _bundled 모듈 특정 테스트
php vendor/bin/phpunit --filter=ShippingPolicyControllerTest modules/_bundled/sirsoft-ecommerce/tests

# _bundled 플러그인 테스트
php vendor/bin/phpunit plugins/_bundled/sirsoft-tosspayments/tests
```

### 프론트엔드 (Vitest)

```bash
# _bundled 모듈 디렉토리에서 직접 실행
cd modules/_bundled/sirsoft-ecommerce
powershell -Command "npm run test:run"
```

### 동작 원리

`tests/bootstrap.php`가 `_bundled` 디렉토리의 코드를 활성 디렉토리보다 먼저 등록(prepend)합니다:

1. `_bundled` module.php/plugin.php → classmap 선점 (require_once)
2. `_bundled` src/ → PSR-4 prepend 등록 (활성 디렉토리보다 우선 검색)
3. `autoload-extensions.php` → 이미 로드된 _bundled 항목은 스킵
4. Manager/RouteServiceProvider → `class_exists` 가드로 중복 선언 방지

### 주의사항

```
_bundled 테스트 통과 ≠ 프로덕션 반영 완료
✅ 프로덕션 반영: module:update / plugin:update 커맨드 필수
_bundled 코드 변경 시 module.json 버전 올리기 필수 (업데이트 감지용)
```

---

## 확장 테스트 베이스 클래스

```
필수: 모듈 → ModuleTestCase, 플러그인 → PluginTestCase 상속 (Tests\TestCase 직접 상속 금지)
```

모듈/플러그인의 모든 테스트 클래스는 해당 확장이 제공하는 `ModuleTestCase` 또는 `PluginTestCase`를 상속해야 합니다. `Tests\TestCase`를 직접 상속하면 확장의 마이그레이션이 실행되지 않아 DB 테이블 부재 에러가 발생합니다.

### 올바른 패턴

```php
// ✅ 모듈 테스트
use Modules\Sirsoft\Ecommerce\Tests\ModuleTestCase;

class OrderActivityLogListenerTest extends ModuleTestCase
{
    // ModuleTestCase가 마이그레이션, 오토로드, ServiceProvider 등록을 자동 처리
}

// ✅ 플러그인 테스트
use Plugins\Sirsoft\Tosspayments\Tests\PluginTestCase;

class PaymentServiceTest extends PluginTestCase
{
    // PluginTestCase가 마이그레이션, 오토로드, ServiceProvider 등록을 자동 처리
}
```

### 잘못된 패턴

```php
// ❌ Tests\TestCase 직접 상속 → 확장 마이그레이션 미실행 → DB 테이블 부재 에러
use Tests\TestCase;

class OrderActivityLogListenerTest extends TestCase
{
    // Order::whereIn() 호출 시 "Base table or view not found" 에러 발생
}
```

### ModuleTestCase/PluginTestCase가 제공하는 기능

| 기능 | 설명 |
|------|------|
| 마이그레이션 | 코어 + 확장 마이그레이션 자동 실행 |
| 오토로드 | 확장 네임스페이스 PSR-4 등록 |
| ServiceProvider | Repository 바인딩 등 확장 서비스 등록 |
| 라우트 | 확장 API 라우트 등록 |
| 기본 역할 | admin/user 역할 자동 생성 |

### 위치

| 확장 | 베이스 클래스 위치 |
|------|-------------------|
| 모듈 | `modules/_bundled/{identifier}/tests/ModuleTestCase.php` |
| 플러그인 | `plugins/_bundled/{identifier}/tests/PluginTestCase.php` |

---

## 확장 마이그레이션 자동 로드 (requiredExtensions)

코어 테스트에서 확장(모듈/플러그인)의 DB 테이블이 필요한 경우, `$requiredExtensions` 프로퍼티를 선언합니다.
`RefreshDatabase`의 `migrate:fresh` 실행 시 해당 확장의 마이그레이션도 함께 실행됩니다.

```php
class ResourceAbilitiesTest extends TestCase
{
    use RefreshDatabase;

    /**
     * sirsoft-marketing 플러그인이 User 리소스 훅에 개입하므로 마이그레이션 필요
     */
    protected array $requiredExtensions = [
        'plugins/sirsoft-marketing',
    ];
}
```

### 경로 형식

프로젝트 루트 기준 상대 경로로, `database/migrations` 디렉토리가 자동 추가됩니다:

| 선언값 | 실제 로드 경로 |
|--------|---------------|
| `'plugins/sirsoft-marketing'` | `plugins/sirsoft-marketing/database/migrations` |
| `'modules/sirsoft-ecommerce'` | `modules/sirsoft-ecommerce/database/migrations` |
| `'plugins/_bundled/sirsoft-marketing'` | `plugins/_bundled/sirsoft-marketing/database/migrations` |

### 동작 원리

`TestCase::setUpTraits()`에서 `RefreshDatabase::refreshDatabase()` 호출 전에 확장 마이그레이션 경로를 `migrator`에 등록합니다:

1. `setUpTraits()` → `loadExtensionMigrations()` (확장 경로 등록)
2. `parent::setUpTraits()` → `RefreshDatabase::refreshDatabase()` → `migrate:fresh` (코어 + 확장 함께 실행)

### 주의사항

```
afterApplicationCreated()는 setUpTraits() 이후 실행되므로 마이그레이션 등록에 사용 불가
beforeRefreshingDatabase()는 RefreshDatabase 트레이트가 부모 클래스 메서드를 가리므로 사용 불가
✅ setUpTraits() 오버라이드가 유일하게 작동하는 훅 포인트
```

---

## 필수 원칙

```
필수: 테스트 통과 = 작업 완료 (작성만으로 불충분)
```

---

## 모듈/플러그인 프론트엔드 테스트 독립성

```
필수: 각 모듈/플러그인은 독립적인 vitest.config.ts를 가짐 (루트 vitest.config에 포함 금지)
필수: 테스트는 해당 모듈/플러그인 디렉토리에서 실행
```

### 원칙

모듈과 플러그인은 **독립적인 확장 단위**입니다. 프론트엔드 테스트 역시 코어 프로젝트와 분리되어야 합니다:

1. **루트 vitest.config.ts에 모듈/플러그인 경로 포함 금지**
2. **각 모듈/플러그인은 자체 테스트 환경 구성**
3. **테스트는 해당 디렉토리에서 독립 실행**

### 필수 파일 구성

모듈/플러그인에 프론트엔드 테스트가 필요한 경우 다음 파일을 구성합니다:

**1. vitest.config.ts**

```typescript
// modules/[vendor-module]/vitest.config.ts
import { defineConfig } from 'vitest/config';
import path from 'path';
import fs from 'fs';

// 프로젝트 루트를 동적으로 탐색 (artisan 파일 기준)
// _bundled와 활성 디렉토리 모두에서 동작합니다.
function findProjectRoot(startDir: string): string {
    let dir = startDir;
    while (dir !== path.dirname(dir)) {
        if (fs.existsSync(path.join(dir, 'artisan'))) return dir;
        dir = path.dirname(dir);
    }
    return path.resolve(startDir, '../../'); // fallback
}

const projectRoot = findProjectRoot(__dirname);

export default defineConfig({
    test: {
        globals: true,
        environment: 'node',
        include: ['resources/js/**/*.{test,spec}.{ts,tsx}'],
        exclude: ['node_modules/', 'dist/'],
    },
    resolve: {
        alias: {
            '@': path.resolve(__dirname, 'resources/js'),
            '@core': path.resolve(projectRoot, 'resources/js/core'),
        },
    },
});
```

**2. package.json 테스트 스크립트**

```json
{
    "name": "@g7/[vendor-module]",
    "scripts": {
        "dev": "vite",
        "build": "vite build",
        "test": "vitest",
        "test:run": "vitest run"
    },
    "devDependencies": {
        "vitest": "^2.1.8"
    }
}
```

### 테스트 파일 위치

```
modules/[vendor-module]/
├── resources/
│   └── js/
│       ├── handlers/
│       │   ├── index.ts
│       │   ├── someHandler.ts
│       │   └── __tests__/
│       │       └── someHandler.test.ts    # ← 테스트 파일
│       └── utils/
│           ├── someUtil.ts
│           └── __tests__/
│               └── someUtil.test.ts       # ← 테스트 파일
├── package.json
└── vitest.config.ts
```

### 테스트 실행 방법

```bash
# ✅ DO: _bundled 모듈 디렉토리에서 직접 실행
cd modules/_bundled/sirsoft-ecommerce
npm install       # 최초 1회
npm run test:run  # 테스트 실행

# ✅ DO: _bundled 모듈에서도 직접 실행 가능
cd modules/_bundled/sirsoft-ecommerce
npm install       # 최초 1회
powershell -Command "npm run test:run"

# ❌ DON'T: 루트에서 모듈 테스트 실행 시도
cd /path/to/g7
npm run test:run -- modules/sirsoft-ecommerce  # 작동 안함
```

### 의존성 설치

모듈/플러그인에서 테스트를 처음 실행하기 전 의존성을 설치합니다:

```bash
cd modules/_bundled/[vendor-module]
npm install
```

### 왜 독립적이어야 하는가?

1. **확장 단위의 독립성**: 모듈/플러그인은 언제든 설치/삭제될 수 있는 독립 단위
2. **루트 설정 오염 방지**: 특정 모듈 경로가 루트 설정에 하드코딩되면 유지보수 어려움
3. **테스트 격리**: 각 모듈/플러그인의 테스트가 서로 영향을 주지 않음
4. **배포 독립성**: 모듈 배포 시 테스트 환경도 함께 배포

### 체크리스트

```
□ 모듈/플러그인에 vitest.config.ts 존재하는가?
□ package.json에 test 스크립트가 있는가?
□ devDependencies에 vitest가 있는가?
□ npm install 실행했는가?
□ 해당 디렉토리에서 테스트 실행하는가?
```

### 주의 사항

```
루트 vitest.config.ts에 modules/plugins 경로 추가 금지
모듈/플러그인 테스트는 해당 디렉토리에서만 실행
모듈/플러그인 테스트 설정은 독립적으로 구성
```

---

## 환경 격리 — Shared Settings 파일 주의

### 배경

`storage/app/settings/*.json` 파일(특히 `drivers.json`)은 dev / testing 환경이 **공유**하는 파일입니다. `SettingsServiceProvider::register()`가 부팅 시 이 파일을 읽어 `cache.default`, `session.driver`, `queue.default` 등을 runtime에 오버라이드합니다.

### 발생했던 문제

과거에는 `SettingsServiceProvider::applyDriverConfig()`가 testing 환경에서도 이 오버라이드를 적용하여, `phpunit.xml`이 지정한 `CACHE_STORE=array` 설정이 무효화되고 testing이 dev의 **Redis 인스턴스와 프리픽스를 공유**했습니다. 그 결과:

1. 테스트가 `RefreshDatabase`로 `notification_definitions` 테이블을 truncate
2. 부팅 중 `NotificationDefinitionService::getAllActive()` 호출 → 빈 컬렉션
3. 빈 컬렉션이 dev Redis에 cached
4. 이후 dev 요청이 이 stale 빈 캐시를 읽어 `NotificationHookListener::registerDynamicHooks()`가 어떤 훅도 등록하지 못함
5. **알림이 silent하게 발송 실패**

### 현재 보호 장치

`SettingsServiceProvider::applyDriverConfig()`는 `env('APP_ENV') === 'testing'`에서 cache/session/queue 드라이버 오버라이드를 **건너뜁니다**. testing 환경은 `phpunit.xml` + `.env.testing`이 지정한 드라이버를 그대로 사용합니다.

### 지침

```text
⚠️ testing 환경은 dev의 Redis/DB/file 캐시를 절대 공유하지 않아야 함

✅ phpunit.xml: CACHE_STORE=array (in-memory, per-process)
✅ .env.testing: CACHE_STORE=file 또는 array
❌ drivers.json 오버라이드는 testing에서 비활성화됨 (SettingsServiceProvider 내 가드)
```

**향후 변경 시 주의**: `SettingsServiceProvider`에 새 드라이버 오버라이드를 추가할 때, shared 파일 경로(storage/app/settings)에서 읽는다면 testing 환경 가드를 반드시 포함할 것.

---

## DDL 트랜잭션 격리 문제

### 문제 설명

모듈/플러그인/템플릿 설치 테스트에서 `Artisan::call('migrate')`가 실행되면 MySQL의 DDL(Data Definition Language) 문이 **암시적 커밋(implicit commit)**을 유발하여 RefreshDatabase 트랜잭션이 깨집니다.

```
주의: DDL(CREATE TABLE 등)은 MySQL에서 암시적 커밋을 유발
필수: DDL 테스트는 하나의 메서드로 통합 (개별 분리 시 각 12-14초 소요)
```

### 영향받는 테스트 유형

| 테스트 유형 | DDL 유발 | 해결 방법 |
|-------------|----------|----------|
| 모듈 install/activate/deactivate/uninstall | ✅ | 하나의 테스트로 통합 |
| 플러그인 install/activate/deactivate/uninstall | ✅ | 하나의 테스트로 통합 |
| 템플릿 install/activate | ✅ | 하나의 테스트로 통합 |
| 일반 CRUD 테스트 | ❌ | 개별 테스트 유지 |
| 캐시/리스트 테스트 | ❌ | 개별 테스트 유지 |

### 올바른 패턴

```php
// ❌ BAD: 개별 테스트 (각각 12-14초, 총 48초+)
public function test_install_module(): void { ... }      // 12s
public function test_activate_module(): void { ... }     // 12s
public function test_deactivate_module(): void { ... }   // 12s
public function test_uninstall_module(): void { ... }    // 12s

// ✅ GOOD: 통합 테스트 (전체 1-2초)
public function test_module_lifecycle_workflow(): void
{
    // Part 1: 미설치 상태 실패 케이스
    $this->artisan('module:activate', ['identifier' => $id])->assertExitCode(1);

    // Part 2: 설치 워크플로우
    $this->artisan('module:install', ['identifier' => $id])->assertExitCode(0);
    $this->assertDatabaseHas('modules', ['identifier' => $id]);

    // Part 3: 활성화/비활성화 워크플로우
    $this->artisan('module:activate', ['identifier' => $id])->assertExitCode(0);
    $this->artisan('module:deactivate', ['identifier' => $id])->assertExitCode(0);

    // Part 4: 삭제 워크플로우
    $this->artisan('module:uninstall', ['identifier' => $id, '--force' => true])
        ->assertExitCode(0);
}
```

### 테스트 클래스 구조

```php
class ModuleArtisanCommandsTest extends TestCase
{
    use RefreshDatabase;

    // ========================================
    // Non-DDL 테스트: 개별 메서드로 유지 (빠름)
    // ========================================

    public function test_list_command(): void { ... }           // 0.1s
    public function test_cache_clear_command(): void { ... }    // 0.1s
    public function test_nonexistent_module_fails(): void { ... } // 0.1s

    // ========================================
    // DDL 통합 테스트: 반드시 하나의 메서드로!
    // test_z_ 접두사로 마지막에 실행
    // ========================================

    /**
     * DDL 유발: 모듈 설치 시 마이그레이션 실행
     *
     * 모든 install/activate/deactivate/uninstall 시나리오를
     * 하나의 테스트에서 순차 실행
     */
    public function test_z_module_commands_full_workflow(): void
    {
        // Part 1: 미설치 상태 실패 케이스
        // Part 2: 설치 워크플로우
        // Part 3: 활성화/비활성화 워크플로우
        // Part 4: 삭제 워크플로우
        // Part 5: --force 옵션 테스트
        // Part 6: 전체 라이프사이클
    }
}
```

### 참고 자료

- [MySQL Implicit Commit](https://dev.mysql.com/doc/refman/8.0/en/implicit-commit.html)
- 예시 파일: `tests/Feature/Console/Commands/Module/ModuleArtisanCommandsTest.php`

---

## 템플릿 엔진 테스트 필수화

```
필수: 템플릿 엔진 또는 컴포넌트 수정 시 테스트 포함 (테스트 없이 작업 완료 불가)
```

### 적용 범위

다음 작업 수행 시 **반드시** 테스트 코드 작업이 포함되어야 합니다:

| 작업 유형 | 테스트 요구사항 |
|----------|----------------|
| 템플릿 엔진 버그 수정 | 해당 버그를 재현하는 테스트 케이스 추가 |
| 템플릿 엔진 기능 추가 | 새 기능에 대한 테스트 케이스 작성 |
| 컴포넌트 props 변경 | 변경된 props에 대한 테스트 수정/추가 |
| 컴포넌트 동작 변경 | 변경된 동작에 대한 테스트 수정/추가 |
| 새 컴포넌트 추가 | 해당 컴포넌트의 테스트 파일 생성 |
| Action/Handler 수정 | ActionDispatcher 등 관련 테스트 수정/추가 |

### 테스트 파일 위치

```
/resources/js/core/template-engine/
├── ActionDispatcher.ts
├── __tests__/
│   └── ActionDispatcher.test.ts    # ← 테스트 파일

/templates/sirsoft-admin_basic/
├── src/components/
│   ├── basic/
│   │   └── __tests__/              # ← basic 컴포넌트 테스트
│   ├── composite/
│   │   └── __tests__/              # ← composite 컴포넌트 테스트
│   └── layout/
│       └── __tests__/              # ← layout 컴포넌트 테스트
```

### 테스트 작성 규칙

**1. 버그 수정 시 (TDD 권장)**

```typescript
// 1. 먼저 버그를 재현하는 테스트 작성 (실패 확인)
it('should handle URL query parameters correctly', () => {
  // 버그 재현 코드
});

// 2. 버그 수정
// 3. 테스트 통과 확인
```

**2. 기능 추가 시**

```typescript
describe('NewFeature', () => {
  it('should perform expected behavior', () => {
    // 새 기능 테스트
  });

  it('should handle edge cases', () => {
    // 엣지 케이스 테스트
  });
});
```

**3. 컴포넌트 수정 시**

```typescript
// 기존 테스트가 있으면 수정
// 없으면 새로 생성
describe('ModifiedComponent', () => {
  it('should render with new props', () => {
    // 변경된 props 테스트
  });
});
```

### 테스트 실행 명령어

```text
✅ 프론트엔드 테스트는 루트 또는 템플릿 디렉토리에서 실행 가능
✅ 템플릿은 자체 vitest.config.ts를 가지며 루트 setup/alias 참조
✅ 코어 테스트(template-engine)는 루트에서 실행
❌ DON'T: --testPathPattern 옵션 사용 (Jest 전용, Vitest에서는 동작 안함)
```

### 컴포넌트 테스트

**템플릿 디렉토리에서 실행 (권장)**:

```bash
# 템플릿 디렉토리에서 해당 템플릿 테스트만 실행
cd templates/_bundled/sirsoft-admin_basic
powershell -Command "npm run test:run"                    # 전체 테스트
powershell -Command "npm run test:run -- DataGrid"        # 특정 테스트
powershell -Command "npm run test:run -- SortableMenuItem"
```

**프로젝트 루트에서 실행**:

```bash
# 모든 테스트 (코어 + 모든 템플릿)
powershell -Command "npm run test:run"

# 특정 템플릿만
powershell -Command "npm run test:run -- templates/_bundled/sirsoft-admin_basic"

# 특정 테스트 필터
powershell -Command "npm run test:run -- DataGrid"
```

**코어 템플릿 엔진 테스트 (루트에서만 실행)**:

```bash
# 템플릿 엔진 코어는 루트에서 실행
powershell -Command "npm run test:run -- template-engine"
powershell -Command "npm run test:run -- Router"
```

**잘못된 사용법**:

```bash
# ❌ DON'T: --testPathPattern 옵션 사용 (Vitest에서 지원하지 않음)
npm run test:run -- --testPathPattern=Router  # 잘못된 명령
```

**템플릿별 vitest.config.ts 구조**:

각 템플릿은 자체 `vitest.config.ts`를 가지며, 루트의 setup 파일과 alias를 참조합니다:
- `server.fs.allow`로 루트 디렉토리 접근 허용
- `setupFiles`에서 루트의 `resources/js/tests/setup.ts` 참조
- `resolve.alias`에서 `@` → 루트의 `resources/js` 매핑

### 완료 조건 체크리스트

```
□ 관련 테스트 파일이 존재하는가?
  → 없으면: 테스트 파일 생성
  → 있으면: 기존 테스트 검토

□ 변경 사항에 대한 테스트가 있는가?
  → 없으면: 테스트 케이스 추가
  → 있으면: 테스트가 변경 사항을 커버하는지 확인

□ 모든 테스트가 통과하는가?
  → 실패: 코드 수정 후 재실행
  → 통과: 작업 완료 가능
```

### 테스트 필수 원칙

```
필수: 코드 수정 시 테스트 포함 후 완료 선언
필수: 기존 테스트 유지 (삭제/skip 금지)
필수: 테스트 작성 후 실행까지 완료
```

### 테스트 커버리지 기준

코어, 모듈, 플러그인 전 영역에 걸쳐 아래 계층별 테스트가 존재해야 합니다.

**백엔드 (PHPUnit)**:

| 계층 | 테스트 대상 | 검증 항목 |
|------|-----------|----------|
| Controller (Feature) | API 엔드포인트 | 상태 코드, 응답 구조, abilities, 권한 403 |
| Resource (Unit) | 직렬화 | 필드 반환, 관계 미로드 시 null, json_encode 안전성 |
| Collection (Unit) | 목록 직렬화 | abilityMap, resolveCollectionAbilities, pagination |
| Service (Unit) | 비즈니스 로직 | CRUD, 캐싱, 예외 처리 |
| Listener (Unit) | 이벤트/훅 처리 | 훅 구독, 데이터 변환 |
| Upgrade (Unit) | 업그레이드 스텝 | 데이터 이관, 멱등성 |

**프론트엔드 (Vitest)**:

| 계층 | 테스트 대상 | 검증 항목 |
|------|-----------|----------|
| 컴포넌트 | TSX 컴포넌트 | 렌더링, props, 이벤트 |
| 레이아웃 | JSON 레이아웃 | 렌더링, 데이터 바인딩, 액션 |

**신규 기능 구현 시 최소 커버리지**:
- Controller → Feature 테스트 (정상 + 권한 부족)
- Resource → json_encode 안전성 (관계 미로드 시나리오 포함)
- Collection → abilities 반환 검증 (abilityMap 정의 시)
- Service → 핵심 메서드별 정상/예외 케이스

### 레이아웃 렌더링 테스트 (상세)

레이아웃 JSON 파일을 실제 렌더링하고 런타임 동작을 검증하는 테스트 시스템이 제공됩니다.

> 상세 문서: [frontend/layout-testing.md](frontend/layout-testing.md)

**주요 기능**:
- `createLayoutTest()`: 레이아웃 테스트 헬퍼 생성
- `mockApi()`: 데이터소스 API 응답 모킹
- `getState()` / `setState()`: 상태 관리
- `triggerAction()`: 액션 트리거
- `getToasts()` / `getModalStack()`: UI 피드백 추적

**테스트 유틸리티 위치**:
```text
resources/js/core/template-engine/__tests__/
├── utils/
│   ├── mockApiUtils.ts       # API 모킹 유틸리티
│   └── layoutTestUtils.ts    # 레이아웃 테스트 헬퍼
└── layouts/
    └── example.test.tsx      # 유틸리티 사용 예제 (코어 전용)
```

**레이아웃 렌더링 테스트 위치**:

```text
필수: 레이아웃 테스트는 해당 레이아웃이 속한 확장 디렉토리에 작성 (코어 디렉토리에 모듈/템플릿 테스트 배치 금지)
```

| 레이아웃 소속 | 테스트 파일 위치 |
|-------------|-----------------|
| 모듈 레이아웃 | `modules/_bundled/{id}/resources/js/__tests__/layouts/*.test.tsx` |
| 템플릿 레이아웃 | `templates/_bundled/{id}/__tests__/layouts/*.test.tsx` |
| 코어 레이아웃 | `resources/js/core/template-engine/__tests__/layouts/*.test.tsx` |

**실행 명령어**:
```powershell
# 모듈 레이아웃 테스트
cd modules/_bundled/sirsoft-ecommerce
powershell -Command "npm run test:run"

# 템플릿 레이아웃 테스트
cd templates/_bundled/sirsoft-admin_basic
powershell -Command "npm run test:run"

# 코어 레이아웃 테스트 (루트에서)
powershell -Command "npm run test:run -- layouts/example.test"
```

**완료 조건**:
```bash
php artisan test --filter=TargetTest

# 결과: Tests: X passed (Y assertions) - 모든 테스트 성공
```

**실패 시 대응**:
1. 에러 메시지 분석
2. 코드 수정
3. 재실행
4. 통과할 때까지 반복

### 테스트 작성 패턴

```php
<?php

namespace Tests\Feature\Modules\Sirsoft\Ecommerce;

use Tests\TestCase;
use App\Models\User;
use Modules\Sirsoft\Ecommerce\Models\Product;
use Modules\Sirsoft\Ecommerce\Models\ProductCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    private User $adminUser;
    private ProductCategory $category;

    protected function setUp(): void
    {
        parent::setUp();

        // 관리자 사용자 생성
        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('admin');

        // 카테고리 생성
        $this->category = ProductCategory::factory()->create();
    }

    /**
     * 상품 목록 조회 테스트
     */
    public function test_can_list_products(): void
    {
        // Arrange
        Product::factory()->count(5)->create([
            'category_id' => $this->category->id,
        ]);

        // Act
        $response = $this->actingAs($this->adminUser)
            ->getJson('/api/admin/sirsoft-ecommerce/products');

        // Assert
        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => ['id', 'name', 'price', 'category'],
                ],
            ]);

        $this->assertEquals(5, count($response->json('data')));
    }

    /**
     * 상품 생성 테스트
     */
    public function test_can_create_product(): void
    {
        // Arrange
        $productData = [
            'name' => '테스트 상품',
            'description' => '테스트 설명',
            'price' => 10000,
            'category_id' => $this->category->id,
            'is_active' => true,
        ];

        // Act
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/admin/sirsoft-ecommerce/products', $productData);

        // Assert
        $response->assertStatus(201)
            ->assertJsonFragment(['name' => '테스트 상품']);

        $this->assertDatabaseHas('products', [
            'name' => '테스트 상품',
            'price' => 10000,
        ]);
    }

    /**
     * 유효성 검증 테스트
     */
    public function test_validation_fails_without_required_fields(): void
    {
        // Act
        $response = $this->actingAs($this->adminUser)
            ->postJson('/api/admin/sirsoft-ecommerce/products', []);

        // Assert
        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'price', 'category_id']);
    }
}
```

### 체크리스트

**테스트 전**:
- [ ] 필요한 클래스/메서드 구현 완료
- [ ] 모델 관계 정의 완료
- [ ] API 리소스에서 민감 정보 제외

**테스트 중**:
- [ ] 실패 원인 분석
- [ ] 코드 수정
- [ ] 재실행

**테스트 후**:
- [ ] 모든 테스트 통과 확인
- [ ] 실패한 테스트 0건

---

## Mockery 사용 규칙

### 인터페이스 타입힌트 필수

```text
필수: Mock 객체 생성 시 인터페이스/클래스 타입 지정
잘못된 패턴: Mockery::mock() — 타입 미지정
올바른 패턴: Mockery::mock(PluginInterface::class)
```

**문제 상황**: 타입힌트 없는 Mock 객체가 반환 타입이 지정된 메서드에서 반환될 때 TypeError 발생

```php
// ❌ 문제 코드 - TypeError 발생
$mockPlugin = Mockery::mock();
$mockPlugin->shouldReceive('getSettingsSchema')->andReturn([]);

// 에러: Return value must be of type ?PluginInterface, Mockery_3 returned
```

```php
// ✅ 올바른 코드
use App\Contracts\Extension\PluginInterface;

$mockPlugin = Mockery::mock(PluginInterface::class);
$mockPlugin->shouldReceive('getSettingsSchema')->andReturn([]);
```

### Mock 객체 생성 패턴

```php
<?php

namespace Tests\Unit\Services;

use App\Contracts\Extension\PluginInterface;
use App\Extension\PluginManager;
use Mockery;
use Tests\TestCase;

class PluginServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();  // 필수: 테스트 종료 시 Mock 정리
        parent::tearDown();
    }

    public function test_example(): void
    {
        // ✅ 인터페이스 타입 지정
        $mockPlugin = Mockery::mock(PluginInterface::class);
        $mockPlugin->shouldReceive('getIdentifier')
            ->andReturn('test-plugin');
        $mockPlugin->shouldReceive('hasSettings')
            ->andReturn(true);

        // ✅ 클래스 타입 지정
        $mockPluginManager = Mockery::mock(PluginManager::class);
        $mockPluginManager->shouldReceive('getPlugin')
            ->with('test-plugin')
            ->andReturn($mockPlugin);

        // 테스트 로직...
    }
}
```

### Mockery 체크리스트

- [ ] `Mockery::mock(Interface::class)` 형태로 타입 지정
- [ ] `tearDown()`에서 `Mockery::close()` 호출
- [ ] 반환 타입이 있는 메서드는 해당 타입의 Mock 반환

---

## PHPUnit Attributes 사용

```
주의: PHPUnit 11.x부터 doc-comment annotation은 deprecated
필수: 새 테스트 작성 시 PHP 8 Attributes 사용 (@test, @dataProvider 등 doc-comment 사용 금지)
```

### 배경

- PHPUnit 11.x에서 doc-comment 기반 메타데이터 deprecated
- PHPUnit 12에서 완전히 제거 예정
- 기존 코드는 이미 PHP 8 Attributes로 변환 완료됨

### 사용 방법

**1. 기본 use 구문**

```php
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Depends;
```

**2. 테스트 메서드 정의**

```php
// ❌ DON'T: deprecated annotation
/**
 * @test
 */
public function it_can_create_user(): void { ... }

// ✅ DO: PHP 8 Attribute
#[Test]
public function it_can_create_user(): void { ... }
```

**3. DataProvider 사용**

```php
// ❌ DON'T: deprecated annotation
/**
 * @dataProvider validDataProvider
 */
public function test_validation(string $input, bool $expected): void { ... }

// ✅ DO: PHP 8 Attribute
#[Test]
#[DataProvider('validDataProvider')]
public function it_validates_input(string $input, bool $expected): void { ... }
```

**4. Group 지정**

```php
// 메서드 레벨
#[Test]
#[Group('slow')]
public function it_processes_large_data(): void { ... }

// 클래스 레벨
#[Group('module-dependent')]
class BoardModelTest extends TestCase { ... }
```

**5. Depends 사용**

```php
#[Test]
public function it_creates_user(): User
{
    $user = User::factory()->create();
    $this->assertNotNull($user);
    return $user;
}

#[Test]
#[Depends('it_creates_user')]
public function it_updates_user(User $user): void
{
    $user->update(['name' => 'Updated']);
    $this->assertEquals('Updated', $user->fresh()->name);
}
```

### 메서드명 규칙

```
✅ DO: it_can_*, it_should_*, it_validates_* (Attribute 사용 시)
✅ DO: test_* (Attribute 없이도 자동 인식)
❌ DON'T: @test annotation과 test_ prefix 혼용
```

### 변환 도구

기존 annotation을 Attributes로 변환해야 하는 경우:

```bash
# 통계 확인
php artisan phpunit:convert-annotations --stats-only

# Dry-run (미리보기)
php artisan phpunit:convert-annotations --dry-run

# 실제 변환 (백업 포함)
php artisan phpunit:convert-annotations --backup

# 특정 경로만 변환
php artisan phpunit:convert-annotations --path=tests/Unit
```

### 체크리스트

```
□ 새 테스트 작성 시 #[Test] Attribute 사용했는가?
□ use PHPUnit\Framework\Attributes\Test; 추가했는가?
□ @test, @dataProvider 등 doc-comment annotation 사용하지 않았는가?
□ IDE에서 deprecation 경고가 없는가?
```

---

## Config 캐시와 테스트 환경

### 문제 설명

`php artisan config:cache` 실행 후 테스트를 실행하면 테스트 환경이 `testing`이 아닌 `local`로 적용됩니다.

```
주의: config 캐시가 존재하면 Laravel은 환경 변수를 무시
증상: app()->environment()가 'testing'이 아닌 'local' 반환
해결: tests/bootstrap.php에서 자동으로 config 캐시 삭제
```

### 근본 원인

1. `php artisan config:cache` 실행 시 `bootstrap/cache/config.php` 파일 생성
2. 이 캐시 파일에 `'env' => 'local'`이 하드코딩됨
3. Laravel은 **config 캐시 파일이 존재하면 환경 변수를 완전히 무시**
4. PHPUnit이 `APP_ENV=testing`을 설정해도 Laravel은 캐시에서 `local`을 읽어옴

### 검증 결과

| 상태 | `env('APP_ENV')` | `app()->environment()` |
|------|------------------|------------------------|
| 캐시 있음 | testing | **local** ← 문제 |
| 캐시 삭제 | testing | **testing** ← 정상 |

### 해결 방법

`tests/bootstrap.php`에서 PHPUnit 실행 시 자동으로 config 캐시를 삭제합니다:

```php
// tests/bootstrap.php
$configCacheFile = __DIR__.'/../bootstrap/cache/config.php';
if (file_exists($configCacheFile)) {
    unlink($configCacheFile);
}
```

### 동작 방식

1. `php artisan config:cache` 실행 → 캐시 생성 (개발/운영 환경 성능 최적화)
2. `php artisan test` 실행 → bootstrap.php에서 캐시 자동 삭제 → testing 환경 적용
3. 다음 웹 요청 시 → 캐시 없으면 `.env` 파일에서 읽음

### 수동 해결 (참고용)

```bash
# config 캐시 삭제
php artisan config:clear
# 또는
rm bootstrap/cache/config.php

# 테스트 실행
php artisan test
```

---

