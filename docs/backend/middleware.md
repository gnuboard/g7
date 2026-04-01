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
```

---

## 목차

- [핵심 원칙](#핵심-원칙)
- [미들웨어 실행 순서](#미들웨어-실행-순서)
- [미들웨어 등록 방식](#미들웨어-등록-방식)
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

### append vs appendToGroup 차이

| 구분 | `append()` | `appendToGroup()` |
|------|-----------|-------------------|
| **등록 위치** | 전역 미들웨어 스택 | 특정 그룹 미들웨어 스택 |
| **실행 시점** | 모든 요청의 최초 | 그룹 미들웨어 순서에 따름 |
| **인증 상태** | 인증 전 | 인증 후 (Sanctum 이후) |
| **적용 범위** | 모든 라우트 | 해당 그룹(web/api) 라우트만 |

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
// 권한만 체크 (scope는 DB 설정에 따라 자동 적용)
Route::get('{user}', [AdminUserController::class, 'show'])
    ->middleware('permission:admin,core.users.read');

Route::put('{user}', [AdminUserController::class, 'update'])
    ->middleware('permission:admin,core.users.update');

Route::put('{menu}', [AdminMenuController::class, 'update'])
    ->middleware('permission:admin,core.menus.update');
```

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