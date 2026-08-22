# 미들웨어 등록 규칙

> **참조**: [백엔드 가이드 인덱스](index.md) | [인증 시스템](authentication.md)

---

## TL;DR (5초 요약)

```text
1. 인증 필요 미들웨어 → 전역 등록 금지!
2. 그룹 등록: appendToGroup('web'|'api', [...])
3. 실행 순서: 전역 → 그룹(web/api) → 라우트 → 컨트롤러
4. permission 미들웨어: scope_type 기반 접근 제어 (except/only/menu 옵션 폐기)
5. scope 체크: Permission.resource_route_key + owner_key + role_permissions.scope_type
6. 확장 미들웨어 → getMiddleware() 선언(self-gate). SP Kernel 직접 조작·라우트 파일 자기 FQCN 금지
```

---

## 목차

- [핵심 원칙](#핵심-원칙)
- [미들웨어 실행 순서](#미들웨어-실행-순서)
- [미들웨어 등록 방식](#미들웨어-등록-방식)
- [확장 미들웨어 선언 (self-gate)](#확장-미들웨어-선언-self-gate)
- [올바른 등록 예시](#올바른-등록-예시)
- [문제 상황과 해결책](#문제-상황과-해결책)
- [디버깅 방법](#디버깅-방법)
- [미들웨어 개발 체크리스트](#미들웨어-개발-체크리스트)
- [관련 파일](#관련-파일)

---

## 핵심 원칙

```text
필수: 인증 필요 미들웨어는 appendToGroup('api') 사용 (전역 등록 금지)
필수: appendToGroup('web'|'api', [...])으로 그룹 미들웨어에 등록
```

**핵심 이해사항**:

- **미들웨어 실행 순서**: 전역 미들웨어는 web/api 그룹 미들웨어보다 **먼저** 실행됨
- **인증 미들웨어 위치**: Sanctum 인증 미들웨어는 api 그룹에 등록되어 있음
- **결과**: 전역 미들웨어에서 `Auth::check()`, `Auth::user()`를 호출하면 항상 `false`/`null` 반환

---

## 미들웨어 실행 순서

```
요청 → 전역 미들웨어 → 그룹 미들웨어(web/api) → 라우트 미들웨어 → 컨트롤러
        ↑                    ↑
     Auth 불가능           Auth 가능
     (인증 전)            (인증 후)
```

### 실행 순서 상세

1. **전역 미들웨어** (`append()`, `prepend()`)
   - 모든 요청에 대해 가장 먼저 실행
   - 인증 처리 전이므로 `Auth::check()` = `false`

2. **그룹 미들웨어** (`appendToGroup('web'|'api', ...)`)
   - web 또는 api 그룹에 속한 라우트에서 실행
   - Sanctum 인증 미들웨어 이후 실행
   - `Auth::check()` 사용 가능

3. **라우트 미들웨어** (`alias()`)
   - 특정 라우트에만 적용
   - 가장 마지막에 실행

---

## 미들웨어 등록 방식

### 등록 방식 비교 표

| 방식 | 실행 시점 | Auth 사용 | 사용 사례 |
|------|----------|----------|----------|
| `append()` | 인증 **전** | ❌ 불가 | CORS, 로깅 등 |
| `prepend()` | 인증 **전** (최우선) | ❌ 불가 | 보안 헤더 등 |
| `appendToGroup('api', ...)` | 인증 **후** | ✅ 가능 | 사용자별 설정 |
| `appendToGroup('web', ...)` | 인증 **후** | ✅ 가능 | 사용자별 설정 |
| `alias()` | 라우트 지정 시 | ✅ 가능 | 권한 체크 등 |
| `getMiddleware()` (확장 self-gate) | 요청 시점 대상 매칭 | timing 에 따름 | 확장(모듈/플러그인)의 미들웨어 — [아래 절](#확장-미들웨어-선언-self-gate) |

### append vs appendToGroup 차이

| 구분 | `append()` | `appendToGroup()` |
|------|-----------|-------------------|
| **등록 위치** | 전역 미들웨어 스택 | 특정 그룹 미들웨어 스택 |
| **실행 시점** | 모든 요청의 최초 | 그룹 미들웨어 순서에 따름 |
| **인증 상태** | 인증 전 | 인증 후 (Sanctum 이후) |
| **적용 범위** | 모든 라우트 | 해당 그룹(web/api) 라우트만 |

---

## 확장 미들웨어 선언 (self-gate)

확장(모듈/플러그인)은 자기 미들웨어를 web/api 그룹에 직접 넣지 않는다. 각 확장이 `getMiddleware()` 로 미들웨어와 그 부착 대상을 선언하고, 코어 게이트 래퍼(`ExtensionMiddlewareGate`)가 요청 시점에 라우트 이름·URI 를 대상 패턴과 대조해 매칭될 때만 해당 미들웨어를 실행한다. 코어 IDV 정책의 라우트명 인덱스 조회 모델과 동일하다.

배경: 확장 라우트는 부팅 이후 지연 등록되고 확장 간 부팅 순서 보장이 없어, 부팅 시점에 다른 확장(또는 코어 특정 라우트)의 라우트를 순회해 미들웨어를 부착하는 방식은 성립하지 않는다. 요청 시점 매칭은 모든 라우트가 등록 완료된 뒤라 이 제약을 원천 회피한다.

### getMiddleware() 스키마

```php
public function getMiddleware(): array
{
    return [
        [
            'class'   => VerifyGuestOrderToken::class,  // 미들웨어 FQCN (class_exists 검증)
            'groups'  => ['api'],       // ['web'] | ['api'] | ['web','api']
            'timing'  => 'after_core',  // 'after_core'(기본) | 'before_core'
            'targets' => ['self'],      // 라우트명/URI 패턴 배열 (필수, 아래 카탈로그)
        ],
    ];
}
```

| 필드 | 필수 | 의미 |
|------|------|------|
| `class` | O | 미들웨어 FQCN. `class_exists` 실패 시 등록 거부 |
| `groups` | O | `['web']` \| `['api']` \| `['web','api']`. 빈 배열·미허용 그룹은 등록 거부 |
| `timing` | X | `after_core`(기본, 코어 그룹 미들웨어 뒤) \| `before_core`(코어 전처리 전체보다 먼저). `before_core` 는 인증 결과·로케일에 의존하는 미들웨어에 쓰지 않는다 (응답 사전차단/전처리 전용) |
| `targets` | O | 부착 대상 패턴 배열. 누락·빈 배열 시 등록 거부 |

### targets 카탈로그

| 값 | 의미 | 매칭 방식 |
|----|------|----------|
| `self` | 자기 확장 라우트 전체 | 항목 groups 의 각 그룹마다 `{group}.modules.{id}.*` / `{group}.plugins.{id}.*` prefix 로 치환 |
| `all_extensions` | 모든 확장 라우트 (코어 제외) | 라우트명이 `*.modules.*` 또는 `*.plugins.*` |
| `core` | 코어 라우트 전체 (확장 제외) | 라우트명이 확장 prefix 가 **아닌** 것 (negative 판별). 무명 라우트는 core 로 간주하지 않음 |
| `everything` (별칭 `*`) | 코어 + 모든 확장 (전부) | 무조건 매칭 (무명 라우트 포함) |
| `module:{id}` | 특정 모듈 라우트 전체 | `*.modules.{id}.*` |
| `plugin:{id}` | 특정 플러그인 라우트 전체 | `*.plugins.{id}.*` |
| 원시 glob 문자열 | 임의 라우트 이름 패턴 (`api.modules.foo.cart.*`) | `Str::is()` 라우트명 매칭 |
| brace 문자열 | `{a,b}` 단일 그룹 전개 | 전개 후 각 glob 매칭 |
| `/` 로 시작하는 URI 패턴 | 임의 요청 URI 패턴 (`/`, `/admin/*`) — 무명 라우트 타게팅용 | `$request->path()` 매칭 |

`everything`·`*`, `all_extensions`, `core` 는 코어·타 확장에 개입하는 **광역 타게팅** 이다. 선언에 대상이 명문화되므로 감사 가능하며, 리뷰 시 주의 대상으로 표기한다. 자기 라우트 중 일부만 노려야 하면 `self` 대신 원시 glob 으로 정밀화한다.

### targets 커버리지 규율

인증·소유권 검증 같은 **보호형** 미들웨어는 라우트를 개별 나열하지 않고 **서브트리 glob** 으로 선언한다.

| ❌ 금지 | ✅ 올바른 사용 |
| ------- | -------------- |
| 보호 대상 라우트를 하나씩 열거 | 서브트리 glob (`api.modules.foo.guest.orders.cash-receipt.*`) |
| PHPDoc 에 부착 대상 **개수**를 기재 ("4개 라우트에만 부착") | 무엇을·왜 부착/제외하는지 서술만 남긴다 |
| 상위 glob 으로 뭉뚱그리기 (`guest.orders.*`) | 인증 전 단계 라우트를 삼키지 않는 수준까지 정밀화 |

개별 나열은 하위 라우트가 늘 때 갱신이 누락되어 **새 엔드포인트가 무보호로 열린다**. 실제로 현금영수증 라우트 2건이 추가될 때 targets 갱신이 누락돼 그 기능이 전면 불능이었다. glob 은 그 서브트리의 기본값을 '보호' 로 만들어 실패 방향을 안전한 쪽으로 뒤집는다. 개수를 문서에 박는 것 자체가 다음 누락의 씨앗이다.

다만 상위 glob 이 **인증 전 단계** 라우트(토큰 발급 등)를 삼키면 기능이 통째로 막힌다 — 토큰을 받으러 오는 요청에는 아직 토큰이 없기 때문이다. 삼키는 경우에만 한 단계 아래로 정밀화하고, 제외 이유를 PHPDoc 에 남긴다.

targets 정합성은 정적 검사가 불가능하다. 라우트 전체 이름이 확장 라우트 파일의 접미부와 모듈 로더가 부팅 시 붙이는 prefix 의 조합으로 결정될 뿐 아니라, "이 라우트가 보호되어야 하는가" 자체가 의미적 판단이기 때문이다. 그러므로 확장은 **계약 테스트**를 둔다:

- `Route::getRoutes()` 에서 그 서브트리 라우트를 **전수 수집**하고, 클래스 상수로 선언한 면제 목록(사유 주석 필수)을 뺀 나머지가 전부 `resolveForRoute()` 결과에 그 미들웨어를 포함하는지 단언한다. 모집단을 라우트 테이블에서 파생시키므로, 새 라우트를 추가하고 targets 를 안 고치면 테스트를 손대지 않아도 자동으로 실패한다.
- 면제 대상은 **미부착임을 별도로 단언**한다 (상위 glob 으로 뭉뚱그리는 회귀 차단).
- 케이스 간 오염을 막으려면 `ExtensionMiddlewareRegistry::flush()` 를 `setUp`/`tearDown` 양쪽에서 호출한다 — 인덱스가 태그 캐시다.

참조 구현: `modules/_bundled/sirsoft-ecommerce/tests/Unit/Providers/GuestOrderTokenMiddlewareRegistrationTest.php`

미부착 시 응답이 다른 원인의 응답과 구별되지 않을 수 있다는 점도 함께 본다. 예컨대 토큰 미들웨어의 404 와 FormRequest 의 404 가 같은 코드·메시지면, "토큰 없이는 실패한다" 는 테스트는 **미들웨어가 아예 없어도 통과한다**. 구별을 위해 미들웨어 쪽 상태코드나 메시지를 갈라서는 안 된다 — 리소스 존재 여부가 응답으로 새어 열거 공격 표면이 생긴다. 동일 응답을 유지하고, 부착 증명은 위 계약 테스트가 담당한다.

### 이중 매칭 (라우트명 + URI) — 무명 catch-all 대응

target 이 `/` 로 시작하면 **URI 패턴**(`$request->path()` 매칭), 아니면 **라우트명 패턴**(`$request->route()?->getName()` 매칭)이다. 라우트명은 dot-notation 이라 `/` 로 시작하지 않으므로 구분이 명확하다. 코어 SSR 셸 catch-all 라우트는 `->name()` 이 없어(무명) 라우트명으로 타게팅할 수 없다 → 이런 무명 라우트를 대상으로 하려면 URI 패턴(`/`)이나 `everything` 을 쓴다. 라우트명 계열 target 은 무명 라우트에서 항상 miss 한다.

### 게이트 동작과 무효화

- 게이트 래퍼는 코어가 bootstrap 에서 web/api × before_core/after_core 로 4회 등록한다. 매칭 확장 미들웨어가 없으면 no-op 이라 확장 0개 환경에서도 무해하다.
- registry 인덱스는 **활성 확장만** 포함한다. 비활성 확장의 미들웨어는 게이트가 실행하지 않는다.
- 인덱스는 확장 활성/비활성/설치/제거/리로드 시 자동 무효화된다.

### 금지 패턴

| 금지 | 올바른 방식 |
|------|------------|
| 확장 SP `boot()` 에서 `HttpKernel::append/prepend/pushMiddlewareToGroup()` 직접 호출 | `getMiddleware()` 선언 |
| 확장 SP 에서 `aliasMiddleware()` 직접 호출 | `getMiddleware()` 선언 |
| 확장 라우트 파일에서 자기 `Http\Middleware\` FQCN 을 `->middleware(Foo::class)` 로 부착 | `getMiddleware()` 선언 |

자동 차단: 위 규율은 모두 정적 검사 대상 (위반 시 차단). 서드파티 확장의 구방식은 Laravel 공식 API 라 런타임 차단은 없고 문서로 안내한다.

---

## 올바른 등록 예시

### bootstrap/app.php

```php
->withMiddleware(function (Middleware $middleware): void {
    // ✅ SetLocale, SetTimezone은 인증 후 실행되어야 사용자 설정을 읽을 수 있음
    $localeTimezoneMiddleware = [
        \App\Http\Middleware\SetLocale::class,
        \App\Http\Middleware\SetTimezone::class,
    ];
    $middleware->appendToGroup('web', $localeTimezoneMiddleware);
    $middleware->appendToGroup('api', $localeTimezoneMiddleware);

    // 권한 관련 미들웨어 등록 (별칭)
    $middleware->alias([
        'admin' => \App\Http\Middleware\AdminMiddleware::class,
        'permission' => \App\Http\Middleware\PermissionMiddleware::class,
        'start.api.session' => \App\Http\Middleware\StartApiSession::class,
    ]);
})
```

> **참고**: `EnsureFrontendRequestsAreStateful` (stateful)은 제거되었습니다. API 인증은 Bearer 토큰 전용이며, 세션은 `start.api.session` 미들웨어로 로그인/로그아웃 라우트에서만 생성됩니다. 상세: [authentication.md](authentication.md)

---

## permission 미들웨어 — scope_type 기반 접근 제어

> **변경 이력**: `except:`, `only:`, `menu:` 옵션은 scope_type 시스템으로 대체되어 **폐기**되었습니다. (2026-03-10)

### 문법

```text
permission:{type},{permission}[,{requireAll}]
```

옵션 파라미터 없이 권한 타입과 식별자만 전달합니다.

### 처리 흐름

```text
1. 권한 타입 검증 (admin/user)
2. 동적 파라미터 치환 ({slug} → 실제 값)
3. 권한 체크 (인증/게스트)
   → 권한 없음 → 403
   → 권한 있음 → Step 4로 진행

4. scope_type 스코프 체크:
   a. Permission 조회 (static 캐시) → resource_route_key, owner_key
   b. resource_route_key가 null → 통과 (시스템 리소스)
   c. $request->route(resource_route_key) → Model resolve
   d. Model 없음 → 통과 (list 엔드포인트)
   e. 사용자의 effective scope 확인 (union 정책)
   f. scope=null → 통과 (전체 접근)
   g. scope='self' → $model->{owner_key} === $user->id → 아니면 403
   h. scope='role' → 리소스 소유자가 내 역할을 공유하는지 → 아니면 403
```

### scope_type 값 정의

| 값 | 의미 | 상세 접근 체크 | 목록 필터링 |
|---|---|---|---|
| `null` | 전체 접근 (제한 없음) | 항상 통과 | 필터 미적용 |
| `'self'` | 본인 리소스만 | `$model->{owner_key} === $user->id` | `WHERE {owner_key} = {user_id}` |
| `'role'` | 내 역할 범위 리소스 | 리소스 소유자가 내 역할 공유 | `WHERE {owner_key} IN (역할 공유 사용자 IDs)` |

### union 정책 (복수 역할 보유 시)

```text
우선순위: null(전체) > 'role'(소유역할) > 'self'(본인)

- 여러 역할 중 하나라도 scope_type=null → 전체 접근
- 전부 non-null이면 가장 넓은 범위 적용 (role > self)
- 예: 역할A(scope=self) + 역할B(scope=role) → role 적용
```

### DB 구조

```text
permissions 테이블:
  - resource_route_key VARCHAR(50) NULL  — 라우트 파라미터명 (예: 'user', 'menu', 'product')
  - owner_key VARCHAR(50) NULL           — 소유자 식별 컬럼 (예: 'id', 'created_by', 'user_id')

role_permissions 피벗:
  - scope_type ENUM('self', 'role') NULL DEFAULT NULL
```

### 사용 예시

```php
// 관리자 컨텍스트 — permission:admin + admin 타입 권한 식별자
Route::get('{user}', [AdminUserController::class, 'show'])
    ->middleware('permission:admin,core.users.read');

Route::put('{user}', [AdminUserController::class, 'update'])
    ->middleware('permission:admin,core.users.update');

Route::put('{menu}', [AdminMenuController::class, 'update'])
    ->middleware('permission:admin,core.menus.update');

// 사용자 컨텍스트 — permission:user + user 타입 권한 식별자
Route::get('/api/user/notifications', [UserNotificationController::class, 'index'])
    ->middleware('permission:user,core.user-notifications.read');

Route::patch('/api/user/notifications/{notification}/read', [UserNotificationController::class, 'markAsRead'])
    ->middleware('permission:user,core.user-notifications.update');

Route::delete('/api/user/notifications/{notification}', [UserNotificationController::class, 'destroy'])
    ->middleware('permission:user,core.user-notifications.delete');
```

### 권한 type 일치 규칙 (CRITICAL)

```text
⚠️ CRITICAL: PermissionMiddleware는 (식별자, type) 두 필드 모두 매칭하여 권한 행을 조회합니다.
✅ permission:admin,xxx → DB의 (identifier='xxx', type='admin') 행 필요
✅ permission:user,xxx  → DB의 (identifier='xxx', type='user')  행 필요
❌ 사용자 라우트에 admin 타입 권한 식별자를 사용하면 항상 403 (type 불일치)
```

`permissions.identifier` 컬럼은 단일 unique 제약이므로 같은 식별자로 admin/user 두 행을 동시에 만들 수 없습니다. 사용자 컨텍스트 권한이 필요하면 **별도 식별자**(예: `core.user-notifications.*`)를 정의하세요. 상세 규칙은 [extension/permissions.md](../extension/permissions.md#권한-타입-permission-type) 참조.

### 목록 엔드포인트 필터링

미들웨어는 모델 바인딩이 없는 목록 엔드포인트를 **통과**시킵니다.
목록 필터링은 **Repository**에서 `PermissionHelper::applyPermissionScope()`로 처리합니다.

```php
// Repository에서 한 줄로 적용
$query = User::query();
PermissionHelper::applyPermissionScope($query, 'core.users.read');
```

### 관련 핵심 메서드

| 메서드 | 위치 | 용도 |
|--------|------|------|
| `PermissionHelper::checkScopeAccess()` | 미들웨어 (상세 접근) | 모델 바인딩된 리소스의 scope 체크 |
| `PermissionHelper::applyPermissionScope()` | Repository (목록 필터링) | 쿼리에 scope WHERE 조건 추가 |
| `User::getEffectiveScopeForPermission()` | 모델 | union 정책에 따른 effective scope 반환 |

### resource_route_key/owner_key가 null인 권한

시스템 리소스 (ActivityLog, Module, Plugin, Template, Permission, Settings 등)는 `resource_route_key`와 `owner_key`가 null이므로 scope 체크가 자동 스킵됩니다.

### 폐기된 옵션 (DEPRECATED)

```text
아래 옵션들은 제거되었습니다. 사용 시 미들웨어가 인식하지 않습니다.

- except:self:{param}  → scope_type='self'로 대체
- except:owner:{param} → scope_type='self'로 대체
- only:self:{param}    → scope_type='self'로 대체
- only:owner:{param}   → scope_type='self'로 대체
- menu:{slug}          → 제거 (메뉴 접근 제어는 scope_type으로 불필요)
```

---

## OptionalSanctumMiddleware (선택적 인증)

공개 API이면서 인증된 사용자에게는 추가 정보를 제공해야 하는 경우 사용합니다.

### 동작 흐름

```text
요청 → Bearer 토큰 확인
├── 토큰 없음 → guest로 통과
├── 토큰 유효 → Sanctum 인증 진행 (인증된 사용자)
├── 토큰 만료 → guest로 통과 (공개 페이지 접근 허용)
└── 토큰 무효(위조) → 401 Unauthorized
```

### 등록 방식

```php
// bootstrap/app.php
$middleware->alias([
    'optional.sanctum' => \App\Http\Middleware\OptionalSanctumMiddleware::class,
]);
```

### 사용 예시

```php
// 레이아웃 API: 비회원도 접근 가능하지만, 인증 사용자에게는 권한 기반 UI 제공
Route::middleware('optional.sanctum')
    ->get('/layouts/{name}.json', [LayoutController::class, 'show']);
```

### 일반 Sanctum과의 차이

| 상황 | `auth:sanctum` | `optional.sanctum` |
| ------ | -------------- | -------------------- |
| 토큰 없음 | 401 | guest 통과 |
| 토큰 유효 | 인증 | 인증 |
| 토큰 만료 | 401 | guest 통과 |
| 토큰 무효 | 401 | 401 |

### 구현 파일

- `app/Http/Middleware/OptionalSanctumMiddleware.php`

---

## 문제 상황과 해결책

### ❌ 잘못된 예시 - 전역 미들웨어로 등록

```php
// ❌ DON'T: 전역 미들웨어로 등록 - 인증 전에 실행됨
$middleware->append([
    \App\Http\Middleware\SetLocale::class,
    \App\Http\Middleware\SetTimezone::class,
]);

// SetTimezone 미들웨어 내부
if (Auth::check()) {  // 항상 false! Sanctum 인증 전이므로
    return Auth::user()->timezone;
}
```

### ✅ 올바른 예시 - 그룹 미들웨어로 등록

```php
// ✅ DO: 그룹 미들웨어로 등록 - 인증 후에 실행됨
$middleware->appendToGroup('web', [
    \App\Http\Middleware\SetLocale::class,
    \App\Http\Middleware\SetTimezone::class,
]);
$middleware->appendToGroup('api', [
    \App\Http\Middleware\SetLocale::class,
    \App\Http\Middleware\SetTimezone::class,
]);

// SetTimezone 미들웨어 내부
if (Auth::check()) {  // ✅ 정상 작동! Sanctum 인증 후이므로
    return Auth::user()->timezone;
}
```

---

## 디버깅 방법

미들웨어에서 인증 상태 확인이 필요할 때:

```php
// 미들웨어 내부에 로그 추가
\Log::info('Middleware debug', [
    'auth_check' => Auth::check(),
    'user_id' => Auth::id(),
    'user_timezone' => Auth::user()?->timezone,
]);
```

### 디버깅 결과 해석

| `auth_check` 값 | 의미 | 조치 |
|----------------|------|------|
| `false` | 인증 전에 미들웨어 실행됨 | `appendToGroup()`으로 변경 |
| `true` | 정상적으로 인증 후 실행됨 | 문제 없음 |

---

## 미들웨어 개발 체크리스트

### 신규 미들웨어 개발 시

- [ ] `Auth::check()` 또는 `Auth::user()` 사용 여부 확인
- [ ] 인증 필요 시 `appendToGroup('web'|'api', ...)` 사용
- [ ] 인증 불필요 시 `append()` 또는 `prepend()` 사용
- [ ] 로그로 인증 상태 검증
- [ ] web과 api 그룹 모두에 등록 필요 여부 확인

### 등록 위치 결정 가이드

```
미들웨어에서 Auth 사용?
├── YES → appendToGroup('web'|'api', ...)
└── NO → append() 또는 prepend()
```

---

## 관련 파일

- `bootstrap/app.php`: 미들웨어 등록
- `app/Http/Middleware/PermissionMiddleware.php`: permission 미들웨어 (scope_type 체크 포함)
- `app/Helpers/PermissionHelper.php`: checkScopeAccess, applyPermissionScope 메서드
- `app/Models/User.php`: getEffectiveScopeForPermission (union 정책)
- `app/Http/Middleware/StartApiSession.php`: API 세션 미들웨어 (로그인/로그아웃 전용)
- `app/Http/Middleware/SetLocale.php`: 로케일 설정 미들웨어
- `app/Http/Middleware/SetTimezone.php`: 타임존 설정 미들웨어

---

## 관련 문서

- [권한 시스템](../extension/permissions.md) - scope_type 시스템 상세, resource_route_key/owner_key 매핑
- [인증 시스템](authentication.md) - Sanctum 인증 및 세션 처리
- [서비스 프로바이더 안전성](service-provider.md) - 프로바이더 등록 규칙
- [백엔드 가이드 인덱스](index.md) - 전체 가이드 목록

---

## 참고 이력

- [SetTimezone 미들웨어 리팩토링](../../history/20251127_1402_SetTimezone미들웨어리팩토링.md)

### SEO 미들웨어

| 항목 | 값 |
|------|-----|
| 클래스 | `App\Seo\SeoMiddleware` |
| 별칭 | `seo` |
| 등록 위치 | User catch-all 라우트 그룹에만 |
| 금지 | 전역 등록 / Admin 라우트 부착 |

> 상세: [seo-system.md](seo-system.md)