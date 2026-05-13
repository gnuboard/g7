# 인증 시스템 (AuthManager)

> 그누보드7 프론트엔드 인증 시스템 가이드

---

## TL;DR (5초 요약)

```text
1. AuthManager: 싱글톤 인증 상태 관리 클래스
2. 토큰 자동 갱신: 401 응답 시 자동 처리
3. 컨텍스트 분리: admin/user 분리 관리
4. 로그인: login 핸들러 또는 AuthManager.login()
5. 이벤트: auth:login, auth:logout 이벤트 구독 가능
```

---

## 목차

- [개요](#개요)
- [인증 흐름](#인증-흐름)
- [인증 타입 판단](#인증-타입-판단)
- [API 엔드포인트](#api-엔드포인트)
- [토큰 자동 갱신](#토큰-자동-갱신)
- [로그인 시 로케일 처리](#로그인-시-로케일-처리)
- [사용 예시](#사용-예시)
- [이벤트 처리](#이벤트-처리)
- [주의사항](#주의사항)
- [관련 문서](#관련-문서)

---

## 개요

`AuthManager`는 프론트엔드 인증 상태 관리를 담당하는 싱글톤 클래스입니다.

**위치**: `/resources/js/core/auth/AuthManager.ts`

**주요 기능**:
- 인증 상태 확인 및 관리
- 토큰 자동 갱신 (401 응답 시)
- 로그인/로그아웃 처리
- 관리자/일반사용자 컨텍스트 분리
- 로그인 시 사용자 언어 설정 자동 반영

---

## 인증 흐름

```
사용자가 /admin/dashboard 접근
         ↓
Router.navigateToCurrentPath()
         ↓
auth_required: true 확인
         ↓
auth_type 결정 (명시 또는 경로 기반)
         ↓
AuthManager.checkAuth(authType)
         ↓
    ┌────┴────┐
    ↓         ↓
 인증됨    미인증
    ↓         ↓
 렌더링    로그인 페이지로 리다이렉트
           (?redirect 파라미터 포함)
```

---

## 인증 타입 판단

`auth_type`이 라우트에 명시되지 않은 경우 경로 기반으로 자동 판단:

```typescript
// Router.ts
private getAuthType(route: Route, pathname: string): AuthType {
  // 1. 라우트에 명시된 경우 사용
  if (route.auth_type) {
    return route.auth_type;
  }

  // 2. 경로 기반 자동 판단
  if (pathname.startsWith('/admin')) {
    return 'admin';
  }

  return 'user';
}
```

**우선순위**:
1. 라우트에 명시된 `auth_type` 값
2. 경로 기반 자동 판단 (`/admin` → admin, 그 외 → user)

---

## API 엔드포인트

| 구분 | 로그인 | 사용자 정보 | 로그아웃 | 토큰 갱신 |
|------|--------|------------|---------|----------|
| **관리자** | `/auth/login` | `/admin/auth/user` | `/admin/auth/logout` | `/admin/auth/refresh` |
| **일반사용자** | `/auth/login` | `/user/auth/user` | `/user/auth/logout` | `/user/auth/refresh` |

**공통**:

- 로그인 엔드포인트는 관리자/일반사용자 동일 (`/auth/login`)
- 인증 후 작업은 각각 분리된 엔드포인트 사용
- API 인증은 Bearer 토큰 전용 (세션 기반 인증 미사용)
- 401 응답 시 서버가 세션 쿠키 만료 헤더를 자동 전송 (잔존 쿠키 정리)

---

## 토큰 자동 갱신

ApiClient의 응답 인터셉터에서 401 응답 시 자동으로 토큰 갱신을 시도합니다:

```typescript
// ApiClient.ts 응답 인터셉터
if (error.response?.status === 401 && !originalRequest._retry) {
  originalRequest._retry = true;

  const authManager = AuthManager.getInstance();
  const refreshed = await authManager.refreshToken();

  if (refreshed) {
    // 토큰 갱신 성공, 원래 요청 재시도
    const token = this.getToken();
    originalRequest.headers.Authorization = `Bearer ${token}`;
    return this.client(originalRequest);
  }

  // 갱신 실패 시 로그아웃 처리
}
```

**동시 갱신 방지**: 여러 요청이 동시에 401을 받아도 하나의 갱신 요청만 수행

---

## 로그인 시 로케일 처리

로그인 성공 시 사용자의 `language` 설정을 자동으로 반영합니다:

```text
로그인 API 응답 수신
         ↓
user.language 확인
         ↓
현재 g7_locale과 비교
         ↓
    ┌────┴────┐
    ↓         ↓
  동일      다름
    ↓         ↓
  skip    localStorage 저장
            ↓
          TemplateApp.changeLocale() 호출
            ↓
          UI 즉시 업데이트
```

**구현 위치**: `AuthManager.login()`

```typescript
// 로케일 변경 감지 및 처리
const userLanguage = response.data.user.language;
const currentLocale = localStorage.getItem('g7_locale');
const localeChanged = userLanguage && userLanguage !== currentLocale;

if (userLanguage) {
  localStorage.setItem('g7_locale', userLanguage);
}

// 로케일이 변경된 경우 TemplateApp 재초기화
if (localeChanged && window.__templateApp) {
  window.__templateApp.changeLocale(userLanguage);
}
```

**주요 포인트**:

- 사용자의 DB `language` 값을 `g7_locale` localStorage에 저장
- 로케일이 변경된 경우에만 `changeLocale()` 호출 (불필요한 재초기화 방지)
- SPA 환경에서도 새로고침 없이 즉시 반영

---

## 사용 예시

### routes.json 설정

```json
{
  "routes": [
    {
      "path": "/admin/login",
      "layout": "admin_login",
      "auth_required": false
    },
    {
      "path": "/admin/dashboard",
      "layout": "admin_dashboard",
      "auth_required": true
    },
    {
      "path": "/profile",
      "layout": "user_profile",
      "auth_required": true,
      "auth_type": "user"
    }
  ]
}
```

### 로그인 후 리다이렉트

```typescript
// 로그인 성공 후 원래 페이지로 이동
const authManager = AuthManager.getInstance();
const redirectUrl = authManager.getRedirectUrl('admin');
window.location.href = redirectUrl; // URL 파라미터의 redirect 값 또는 기본 경로
```

---

## 이벤트 처리

AuthManager는 이벤트 기반으로 인증 상태 변경을 알립니다:

```typescript
const authManager = AuthManager.getInstance();

// 인증 상태 변경 감지
authManager.on('authStateChange', (state) => {
  console.log('Auth state:', state.isAuthenticated, state.user);
});

// 로그아웃 감지
authManager.on('logout', () => {
  console.log('User logged out');
});
```

**사용 가능한 이벤트**:
- `authStateChange`: 인증 상태가 변경될 때
- `logout`: 사용자가 로그아웃할 때

---

## 주의사항

### 로그인 페이지 설정

```
로그인 페이지는 반드시 auth_required: false로 설정
   → 그렇지 않으면 무한 루프 발생
```

```json
// ✅ DO
{
  "path": "/admin/login",
  "layout": "admin_login",
  "auth_required": false
}

// ❌ DON'T - 무한 루프 발생
{
  "path": "/admin/login",
  "layout": "admin_login",
  "auth_required": true
}
```

### 보안 규칙

- **리다이렉트 URL**: 같은 도메인 경로만 허용 (외부 URL 차단)
- **토큰 저장**: localStorage의 `auth_token` 키에 저장

### 토큰 관리

```typescript
// 토큰 저장 위치
localStorage.getItem('auth_token');  // 토큰 조회
localStorage.setItem('auth_token', token);  // 토큰 저장
localStorage.removeItem('auth_token');  // 토큰 삭제 (로그아웃)
```

---

## 인증 설정 커스터마이징 (`AuthManager.updateConfig`)

템플릿이 코어 디폴트 로그인 경로(`/login`, `/admin/login`)와 다른 경로를 사용하는 경우, **템플릿 부트스트랩(`initTemplate`)**에서 `AuthManager.updateConfig` 를 호출해 사이트 단위로 오버라이드한다. IDV launcher 등록 패턴([template-idv-bootstrap.md](../extension/template-idv-bootstrap.md))과 동일한 "코어는 슬롯, 템플릿이 채움" 철학.

### 시그니처

```typescript
AuthManager.getInstance().updateConfig(
  type: 'user' | 'admin',
  partial: Partial<AuthConfig>
): void
```

`AuthConfig` 의 갱신 가능 필드: `loginPath`, `defaultPath`, `loginEndpoint`, `logoutEndpoint`, `refreshEndpoint`, `userEndpoint`, `unauthorizedRedirect`.

### 호출 위치 — 템플릿 부트스트랩만

```typescript
// templates/_bundled/{template-id}/src/initTemplate.ts (예시)
import { AuthManager } from '@core/auth/AuthManager';

export function initTemplate() {
  // 사이트 단위 로그인 경로 커스터마이징
  AuthManager.getInstance().updateConfig('user', {
    loginPath: '/account/signin',
  });
}
```

### 코드 예시 1 — 사용자 로그인 경로 커스터마이징

```typescript
AuthManager.getInstance().updateConfig('user', { loginPath: '/account/signin' });
// → 401 자동 리다이렉트 시 /account/signin?redirect=...&reason=session_expired 로 이동
```

### 코드 예시 2 — 다국어 prefix 반영

```typescript
const locale = window.G7Core?.locale || 'ko';
AuthManager.getInstance().updateConfig('user', { loginPath: `/${locale}/login` });
```

### 호출 금지 — 모듈/플러그인

| ❌ 금지 | ✅ 올바른 사용 |
| --- | --- |
| 모듈/플러그인의 부트스트랩에서 `updateConfig` 호출 | 템플릿 부트스트랩에서만 호출 |
| 다른 템플릿이 활성화된 사이트에 침범하여 부작용 발생 | 사이트 단위 결정은 템플릿 책임 |

### 보안 가드 — open redirect 차단

`loginPath` 는 **동일 origin path-only**(즉 `/`로 시작)만 허용하며, `//` 시작(protocol-relative URL) 도 차단한다. 외부 URL 을 지정하면 throw 된다 — open redirect 취약점을 런타임에서 차단.

```typescript
// ❌ throw — 외부 origin
AuthManager.getInstance().updateConfig('user', { loginPath: 'https://evil.com/login' });

// ❌ throw — protocol-relative URL
AuthManager.getInstance().updateConfig('user', { loginPath: '//evil.com/login' });

// ❌ throw — 상대 경로
AuthManager.getInstance().updateConfig('user', { loginPath: './login' });

// ✅ OK — path-only
AuthManager.getInstance().updateConfig('user', { loginPath: '/account/signin' });
```

### 401 자동 리다이렉트 동작

레이아웃 fetch 가 토큰 만료 등으로 401 재시도까지 실패하면, 코어 `TemplateApp.showRouteError` 가드가 자동으로 로그인 페이지로 리다이렉트한다 — `getLoginRedirectUrl(authType, returnUrl, 'session_expired')` 호출하여 `?reason=session_expired` 쿼리 포함. 로그인 레이아웃의 `init_actions` 에서 해당 reason 을 감지해 안내 토스트를 표시한다.

```json
// 로그인 레이아웃 init_actions 예시
{
  "if": "{{route?.query?.reason}} === 'session_expired'",
  "handler": "toast",
  "params": {
    "type": "warning",
    "message": "$t:auth.session_expired_toast"
  }
}
```

리다이렉트 동작 자체는 **코어가 강제**하며 레이아웃이 끌 수 없다. 경로만 `updateConfig` 로 사이트 단위 커스터마이즈 가능 ("여부는 강제, 경로는 커스터마이즈" 분리 설계).

---

## 관련 문서

- [데이터 소스](./data-sources.md) - API 호출 및 인증 헤더
- [보안 및 검증](./security.md) - 프론트엔드 보안 규칙
- [컴포넌트](./components.md) - 로그인 폼 컴포넌트

---

## 체크리스트

인증 기능 구현 시 확인 사항:

- [ ] 로그인 페이지에 `auth_required: false` 설정
- [ ] 인증 필요 페이지에 `auth_required: true` 설정
- [ ] 적절한 `auth_type` 설정 (admin/user)
- [ ] 리다이렉트 URL 보안 검증
- [ ] 토큰 갱신 로직 테스트
- [ ] 로그아웃 시 토큰 정리 확인