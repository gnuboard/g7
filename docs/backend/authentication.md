# 인증 및 세션 처리

> **관련 문서**: [index.md](index.md) | [middleware.md](middleware.md)

---

## TL;DR (5초 요약)

```text
1. Laravel Sanctum 토큰 전용 인증 (Bearer 토큰만 사용)
2. 세션은 /dev 대시보드 전용: StartApiSession 미들웨어로 로그인/로그아웃 라우트에서만 생성
3. 로그아웃: 토큰 삭제 + 세션 무효화 (세션 존재 시)
4. 슬라이딩 만료: 토큰 잔여시간 절반 미만 시 자동 갱신
5. 401 응답 시 세션 쿠키 자동 정리 (무조건적 forget cookie)
```

---

## 목차

1. [Laravel Sanctum 토큰 전용 인증](#laravel-sanctum-토큰-전용-인증)
2. [StartApiSession 미들웨어](#startapisession-미들웨어)
3. [토큰 슬라이딩 만료](#토큰-슬라이딩-만료)
4. [로그아웃 구현 원칙](#로그아웃-구현-원칙)
5. [401 응답 시 세션 쿠키 정리](#401-응답-시-세션-쿠키-정리)
6. [Laravel Sanctum plainTextToken 구조](#laravel-sanctum-plaintexttoken-구조)
7. [세션 존재 확인](#세션-존재-확인)
8. [세션 무효화 3단계](#세션-무효화-3단계)
9. [완전한 로그아웃 구현 예시](#완전한-로그아웃-구현-예시)
10. [안티 패턴](#안티-패턴)

---

## Laravel Sanctum 토큰 전용 인증

그누보드7 프로젝트는 SPA(Single Page Application)를 위한 **토큰 전용 인증 방식**을 사용합니다:

**특징**:
- **Bearer 토큰 전용**: API 인증은 `Authorization: Bearer {token}` 헤더로만 수행
- **`EnsureFrontendRequestsAreStateful` 미사용**: 세션 기반 Sanctum Guard를 사용하지 않음
- **`currentAccessToken()`**: 항상 `PersonalAccessToken` 반환 (TransientToken 없음)
- **세션은 /dev 대시보드 전용**: `StartApiSession` 미들웨어가 로그인/로그아웃 라우트에서만 세션 생성

**인증 가드 설정** (`bootstrap/app.php`):
```php
// stateful 미들웨어 없음 — Sanctum Guard는 토큰만 체크
$middleware->alias([
    'start.api.session' => \App\Http\Middleware\StartApiSession::class,
]);
```

---

## StartApiSession 미들웨어

로그인/로그아웃 라우트에서만 세션을 시작하는 전용 미들웨어입니다.

**목적**:
- `/dev` 대시보드(Telescope, Horizon 등)는 세션 기반 `web` guard 인증 사용
- API 로그인 시 `Auth::guard('web')->login($user)`으로 세션에 사용자 저장
- 로그아웃 시 세션 무효화

**동작 방식**:
```php
// StartApiSession은 세션 파이프라인만 실행 (CSRF 검증 없음, sanctum 속성 미설정)
EncryptCookies → AddQueuedCookiesToResponse → StartSession → Controller
```

**적용 라우트**:
```php
// 로그인 라우트 (세션 생성)
Route::post('login', ...)->middleware('start.api.session');

// 로그아웃 라우트 (세션 무효화)
Route::post('logout', ...)->middleware('start.api.session');
```

**핵심 차이** — `stateful` vs `start.api.session`:

| 항목 | `stateful` (제거됨) | `start.api.session` |
|------|---------------------|---------------------|
| 적용 범위 | 모든 API 라우트 | 로그인/로그아웃만 |
| Sanctum 세션 인증 | ✅ 활성화 | ❌ 비활성화 |
| CSRF 검증 | ✅ 포함 | ❌ 미포함 |
| `sanctum` 속성 설정 | ✅ 설정 | ❌ 미설정 |
| 세션 쿠키 발급 | 모든 API 응답 | 로그인/로그아웃 응답만 |

**구현 파일**: `app/Http/Middleware/StartApiSession.php`

---

## 토큰 슬라이딩 만료

G7은 **슬라이딩 만료(Sliding Expiration)** 방식으로 토큰 만료 시간을 자동 갱신합니다.

### 동작 원리

```
[로그인] ───────────────────────────────────────> [만료]
   0분                                            60분
          ↑
        30분에 API 요청 발생 (절반 미만 남음)
          │
          └─────────────────────────────────────> [만료]
         30분                                      90분
```

### 갱신 조건

토큰 만료 시간은 다음 조건을 **모두** 만족할 때 갱신됩니다:

| 조건 | 설명 |
|------|------|
| 인증된 요청 | `$request->user()`가 존재 |
| PersonalAccessToken | 세션이 아닌 토큰 인증 |
| 만료 시간 설정됨 | `expires_at`이 null이 아님 (무한대 토큰 제외) |
| 절반 미만 남음 | 잔여 시간 < 설정값의 50% |

### 설정값

환경설정의 `security.auth_token_lifetime` 값을 사용합니다:
- **기본값**: 30분
- **범위**: 0~3600분 (0은 무한대)

### 구현 파일

- **미들웨어**: `app/Http/Middleware/RefreshTokenExpiration.php`
- **등록 위치**: `bootstrap/app.php` (`api` 그룹에 등록)
- **테스트**: `tests/Feature/Api/Auth/TokenExpirationTest.php`

---

## 로그아웃 구현 원칙

```
필수: 토큰 삭제 + 세션 무효화 (세션이 존재하는 경우)
필수: currentAccessToken() 삭제 → 세션 존재 시 세션 무효화
```

토큰 전용 인증에서는 `currentAccessToken()`이 항상 `PersonalAccessToken`을 반환합니다:

```php
// 1. 토큰 삭제
$token = $user->currentAccessToken();
if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
    $token->delete();
}

// 2. 세션 무효화 (StartApiSession 미들웨어가 세션을 시작한 경우)
if (request()->hasSession() && request()->session()->isStarted() && Auth::guard('web')->check()) {
    Auth::guard('web')->logout();
    request()->session()->invalidate();
    request()->session()->regenerateToken();
}
```

**특징**:

- `stateful` 미들웨어 제거로 `TransientToken` 케이스가 없어짐
- `currentAccessToken()`이 항상 `PersonalAccessToken` 반환
- 세션 무효화는 `start.api.session` 미들웨어가 적용된 로그아웃 라우트에서만 실행됨

---

## 401 응답 시 세션 쿠키 정리

API 요청에서 `AuthenticationException` 발생 시 (토큰 만료, 미인증 등), 잔존 세션 쿠키를 만료시켜 보안 공백을 방지합니다.

**구현 위치**: `bootstrap/app.php` `withExceptions()`

```php
$exceptions->render(function (\Illuminate\Auth\AuthenticationException $e, \Illuminate\Http\Request $request) {
    if ($request->expectsJson()) {
        return response()->json(['message' => __('auth.unauthenticated')], 401)
            ->withCookie(cookie()->forget(config('session.cookie')));
    }
});
```

**동작 방식**:

- **무조건적 forget**: 세션 쿠키 존재 여부와 관계없이 항상 `Set-Cookie` 만료 헤더 전송
- 클라이언트에 해당 쿠키가 없으면 `Set-Cookie` 헤더는 무시됨 (무해)
- 클라이언트에 잔존 세션 쿠키가 있으면 즉시 삭제됨

**테스트 파일**: `tests/Feature/Api/Auth/SessionCleanupOnUnauthorizedTest.php`

---

## Laravel Sanctum plainTextToken 구조

**형식**: `{tokenId}|{actualToken}`

**예시**:
```
9|iHzLc0VcFxEKi8dJlyqaPQoMBKBgs1ocpZpWKQzj0aaddebe
↓           ↓
DB ID    SHA256 해시 후 DB 저장
```

**DB 저장 방식**:
- `|` 앞부분 (tokenId): DB의 `id` 컬럼에 저장
- `|` 뒷부분 (actualToken): SHA256 해시 후 `token` 컬럼에 저장

**파싱 방법**:
```php
// ✅ DO: explode()로 분리 후 뒷부분만 해시
[, $token] = explode('|', $bearerToken, 2);
$hashedToken = hash('sha256', $token);

// ❌ DON'T: 전체 문자열을 해시
$hashedToken = hash('sha256', $bearerToken); // 잘못된 해시값
```

---

## 세션 존재 확인

세션 무효화 전 반드시 세션 존재 여부를 확인해야 합니다:

```php
if (request()->hasSession() && request()->session()->isStarted() && Auth::guard('web')->check()) {
    // 세션 무효화 로직
}
```

**체크 항목**:
| 메서드 | 설명 |
|--------|------|
| `hasSession()` | 세션이 바인딩되어 있는지 확인 |
| `isStarted()` | 세션이 실제로 시작되었는지 확인 |
| `Auth::guard('web')->check()` | 세션에 인증된 사용자가 있는지 확인 |

---

## 세션 무효화 3단계

```php
Auth::guard('web')->logout();           // 1. 세션에서 인증 정보 제거
request()->session()->invalidate();     // 2. 세션 ID 무효화 및 새 세션 생성
request()->session()->regenerateToken(); // 3. CSRF 토큰 재생성
```

**각 단계 설명**:

| 단계 | 메서드 | 역할 |
|------|--------|------|
| 1 | `logout()` | 세션에서 사용자 인증 정보 제거 |
| 2 | `invalidate()` | 세션 ID를 무효화하고 새 세션 ID 생성 |
| 3 | `regenerateToken()` | CSRF 토큰을 재생성 |

**regenerateToken()을 하는 이유**:
- 로그아웃 후에도 사용자가 같은 브라우저를 계속 사용할 수 있음
- 이전 CSRF 토큰을 무효화하고 새로운 CSRF 토큰 발급
- 로그아웃 이후의 공개 페이지 요청(로그인 폼 등)을 위한 새로운 보안 토큰 제공

---

## 완전한 로그아웃 구현 예시

```php
<?php

namespace App\Services;

use App\Extension\HookManager;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

class AuthService
{
    /**
     * 사용자를 로그아웃시키고 현재 디바이스의 인증 토큰만 삭제합니다.
     */
    public function logout(User $user): void
    {
        // Hook 발생 (로그아웃 시작)
        HookManager::doAction('core.auth.before_logout', $user);

        // 토큰 삭제 처리 (토큰 전용 인증이므로 항상 PersonalAccessToken)
        $token = $user->currentAccessToken();

        if ($token instanceof \Laravel\Sanctum\PersonalAccessToken) {
            $token->delete();
        }

        // 세션 무효화 (StartApiSession 미들웨어가 세션을 시작한 경우)
        if (request()->hasSession() && request()->session()->isStarted() && Auth::guard('web')->check()) {
            Auth::guard('web')->logout();
            request()->session()->invalidate();
            request()->session()->regenerateToken();
        }

        // Hook 발생 (로그아웃 완료)
        HookManager::doAction('core.auth.logout', $user);
    }
}
```

---

## 안티 패턴

```php
// ❌ DON'T: EnsureFrontendRequestsAreStateful 사용 (제거됨)
$middleware->api(prepend: [
    \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
]);

// ❌ DON'T: 세션 존재 확인 없이 무효화 (에러 발생 가능)
Auth::guard('web')->logout(); // 세션이 없으면 에러

// ❌ DON'T: 토큰 삭제 없이 세션만 무효화
request()->session()->invalidate(); // 토큰이 살아있으면 재인증 가능

// ❌ DON'T: 전체 plainTextToken 해시 (DB 값과 불일치)
$hashedToken = hash('sha256', $bearerToken); // 잘못됨
```

---

## 참고 자료

- **구현 파일**: [app/Services/AuthService.php](../../../app/Services/AuthService.php)
- **테스트 파일**: [tests/Feature/Api/Admin/AdminAuthTest.php](../../../tests/Feature/Api/Admin/AdminAuthTest.php)

---

## 관련 문서

- [index.md](index.md) - 백엔드 가이드 인덱스
- [middleware.md](middleware.md) - 미들웨어 등록 규칙 (OptionalSanctumMiddleware 포함)
