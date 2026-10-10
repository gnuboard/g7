# 인증 시스템 (AuthManager)

> 그누보드7 프론트엔드 인증 시스템 가이드

---

## TL;DR (5초 요약)

```text
1. AuthManager: 싱글톤 인증 상태 관리 클래스
2. 토큰 자동 갱신: 401 응답 시 자동 처리
3. 컨텍스트 분리: admin/user 분리 관리
4. 로그인: login 핸들러 또는 AuthManager.login()
5. 이벤트: on(event, handler) 로 login, logout, tokenRefreshed, authStateChange 구독 (해제 함수 반환)
```

---

## 목차

- [개요](#개요)
- [인증 흐름](#인증-흐름)
- [인증 타입 판단](#인증-타입-판단)
- [API 엔드포인트](#api-엔드포인트)
- [토큰 자동 갱신](#토큰-자동-갱신)
- [로그인 시 로케일 처리](#로그인-시-로케일-처리)
- [2단계 인증 로그인 (engine-v1.65.0+)](#2단계-인증-로그인-engine-v1650)
- [비밀번호 변경 요구 로그인 (engine-v1.77.0+)](#비밀번호-변경-요구-로그인-engine-v1770)
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

| 구분 | 로그인 | 인증번호 확인 | 인증번호 재발송 | 비밀번호 변경 단계 (완료 / 연기) | 사용자 정보 | 로그아웃 | 토큰 갱신 |
|------|--------|--------------|----------------|------------------------------|------------|---------|----------|
| **관리자** | `/auth/admin/login` | `/auth/admin/login/two-factor` | `/auth/admin/login/two-factor/resend` | `/auth/admin/login/password-change` / `…/postpone` | `/admin/auth/user` | `/admin/auth/logout` | `/admin/auth/refresh` |
| **일반사용자** | `/auth/login` | `/auth/login/two-factor` | `/auth/login/two-factor/resend` | `/auth/login/password-change` / `…/postpone` | `/user/auth/user` | `/user/auth/logout` | `/user/auth/refresh` |

**공통**:

- 로그인 엔드포인트는 관리자/일반사용자가 다릅니다 (`AuthConfig.loginEndpoint`)
- `updateConfig({ loginEndpoint })` 로 템플릿이 재정의한 값이 그대로 사용됩니다
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

**구현 위치**: `AuthManager.establishSession()` — 일반 로그인과 2단계 인증 완료가 같은 후처리를
공유합니다. 갈라지면 한쪽 경로에서만 로케일 전환이나 이벤트 발행이 빠집니다.

```typescript
// 로케일 변경 감지 및 처리
const userLanguage = user.language;
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

## 부팅·새로고침 때 회원 언어 맞춤 (engine-v1.79.0+)

로그인 상태의 화면 언어는 **회원이 설정한 언어(DB `users.language`)** 를 기준으로 한다. 서버는 로그인 회원의 요청 언어로 `users.language` 를 쓰므로(SetLocale 미들웨어), 화면 언어가 이 브라우저에 남은 `g7_locale` 로 따로 정해지면 화면 문구와 서버 문구(필수 언어 검증, 확장 목록 이름)가 섞인다.

- **부팅·새로고침**: `TemplateApp.init()` 이 인증 프리로드(`preloadAuth`)를 마친 뒤, 첫 렌더 전에 회원 언어가 화면 언어와 다르면 `g7_locale` 을 회원 언어로 바꾸고 엔진을 그 언어로 다시 초기화한다(`alignLocaleWithMemberLanguage`). 사이트가 지원하지 않는 언어(`appConfig.supportedLocales` 에 없음)면 바꾸지 않는다.
- **언어 선택**: 다른 언어를 고르면 종전처럼 화면을 바꾸고 회원 언어를 저장한다. **같은 언어를 다시 골라도** `changeLocale()` 이 화면은 그대로 두고 로그인 회원의 언어를 저장한다(세션 중 다른 기기에서 바뀐 회원 언어를 이 화면 언어로 되돌린다). 관리자 헤더 `LanguageSelector` 는 다른 언어를 고르면 직접 저장한 뒤 `G7Core.locale.change(locale, { persist: false })` 로 화면만 바꿔 저장 요청이 한 번만 나가고, 같은 언어 재선택은 `G7Core.locale.change(locale)` 에 넘긴다.
- **비로그인**: 종전대로 `g7_locale` → 서버가 내려준 값(`Accept-Language`) → `'ko'` 순이다.

---

## 2단계 인증 로그인 (engine-v1.65.0+)

보안 환경설정의 「2단계 인증」이 켜져 있으면 서버는 비밀번호가 맞아도 토큰을 발급하지 않고
인증 요청(challenge)만 돌려줍니다. 비밀번호 변경 단계(engine-v1.77.0+, 아래 절)까지 더하면 **로그인 응답은 세 가지 형태의 200** 입니다.

```typescript
export type LoginResult =
  | { status: 'authenticated'; user: AuthUser; extras?: LoginExtras }
  | { status: 'two_factor_required'; challenge: TwoFactorChallenge }
  | { status: 'password_change_required'; challenge: PasswordChangeChallenge }; // engine-v1.77.0+

/** 서버가 로그인 응답에 함께 실은 안내 필드 (engine-v1.76.0+). 토큰·사용자·단계 표지를 뺀 나머지 필드다. */
export type LoginExtras = Record<string, unknown>;

/** 2단계 인증 확인 결과 (engine-v1.76.0+, 비밀번호 변경 단계 형태는 engine-v1.77.0+) */
export type TwoFactorSession = Exclude<LoginResult, { status: 'two_factor_required' }>;
```

`extras` 는 서버가 응답에 안내 필드를 실었을 때만 붙는다(지금 코어가 싣는 것은 `dormancy_restored`, 비밀번호 변경을 미룬 로그인의 `postponed_until` 이다). 휴면 계정이 로그인으로 풀리면 `dormancy_restored: true` 가
실려, 화면이 「휴면 상태가 해제되었습니다」 안내를 띄울 수 있다.

`AuthManager.login()` 은 이 판별 유니온을 돌려줍니다. 한 형태만 가정하면 challenge 응답에서
`data.user.*` 접근이 예외가 되고, `data.token`(undefined)을 저장하면 `"undefined"` 문자열이 남아
이후 모든 요청이 401 로 튕깁니다.

| 메서드 | 하는 일 |
|--------|---------|
| `login(type, credentials, options?)` | 비밀번호 확인. `LoginResult` 반환 |
| `completeTwoFactor(type, { challengeId, code }, options?)` | 인증번호 확인 → 토큰 발급 → `AuthUser` 반환. 비밀번호 변경 단계가 오면 세션을 열지 않고 오류를 던집니다 |
| `completeTwoFactorSession(type, { challengeId, code }, options?)` | `completeTwoFactor` 와 같은 확인을 하고 `TwoFactorSession` 반환 — `authenticated`(`user` + 서버 안내 필드 `extras`) 또는 `password_change_required`(engine-v1.77.0+) (engine-v1.76.0+) |
| `resendTwoFactor(type, { challengeId }, options?)` | 인증번호 재발송 → **새** `TwoFactorChallenge` 반환 |

레이아웃에서는 액션 핸들러 `login` / `loginTwoFactor` / `loginTwoFactorResend` 로 사용합니다
(→ [actions-handlers-ui.md](actions-handlers-ui.md)).

### 레이아웃 작성 규칙

- 1단계 블록과 2단계 블록의 `if` 는 **상보적**이어야 합니다. 두 블록이 동시에 보이면 인증번호
  단계에서 이메일·비밀번호가 함께 노출됩니다.
- 제출 시퀀스의 `login` 과 `loginTwoFactor` 도 상호배타 `if` 를 갖습니다. `if` 가 빠지면 인증번호
  단계에서 Enter 를 누를 때 새 challenge 가 발급되어 흐름이 깨집니다. `if` 는 시퀀스 시작 시점
  스냅샷으로 평가되므로 한 번의 제출에 정확히 하나만 실행됩니다.
- **같은 시퀀스·`onSuccess` 안에서 방금 저장한 상태를 다시 읽지 않습니다.** 그 자리의 상태는 아직
  갱신 전이므로 `{{response.*}}` 만 사용합니다.
- 인증번호 입력은 자동바인딩이 아니라 `value` + `onChange` 로 상태가 값을 소유해야 합니다 —
  재발송 시 입력값을 비워야 하기 때문입니다.
- 인증 단계 상태는 화면을 떠나도 남으므로, 로그인 화면 진입 시 `init_actions` 에서 초기화합니다.

### 재발송의 계약

서버는 기존 challenge 를 **취소하고 새로 발행**합니다. 유효한 코드를 여러 개 동시에 살려 두면
대입 시도의 표적이 넓어지기 때문입니다. 따라서 클라이언트는 응답의 `challenge_id` 로 반드시
교체하고 입력란을 비워야 합니다.

---

## 비밀번호 변경 요구 로그인 (engine-v1.77.0+)

비밀번호가 만료됐거나(보안 환경설정 「비밀번호 만료」) 관리자가 정해 준 비밀번호면, 서버는 비밀번호와
(켜져 있으면) 2단계 인증이 모두 맞아도 토큰 대신 **비밀번호 변경 단계**를 돌려줍니다. 순서는 항상
자격증명 → 2단계 인증 → 비밀번호 변경입니다 — 변경 단계가 2단계 인증보다 앞에 오면 비밀번호만 훔친
사람이 2단계 인증 없이 비밀번호를 바꿔 계정을 가져갑니다.

```typescript
export interface PasswordChangeChallenge {
  challengeId: string;
  reason: 'expired' | 'admin_assigned';   // 사용 기간 만료 / 관리자가 정해 준 비밀번호
  canPostpone: boolean;                    // '다음에 변경' 가능 여부 (만료 + '다음에 변경 허용' 설정일 때만)
  postponeDays: number | null;             // 미루면 다시 안내할 때까지의 일수
  expiredAt: string | null;                // 만료 시각 (관리자 지정이면 null)
  expiresAt: string | null;                // 이 challenge 가 무효가 되는 시각 (발급 후 10분)
}
```

| 메서드 | 하는 일 |
|--------|---------|
| `completePasswordChange(type, { challengeId, password, passwordConfirmation }, options?)` | 새 비밀번호로 로그인 완료. 서버가 모든 기기를 로그아웃시키고 새 토큰을 발급. `LoginResult`(authenticated) |
| `postponePasswordChange(type, { challengeId }, options?)` | 변경을 미루고 로그인 완료. `extras.postponed_until` 포함 |

레이아웃 핸들러와 `response` 모양:

| 핸들러 | 변경 단계일 때 `response` | 로그인 완료일 때 `response` |
|--------|--------------------------|---------------------------|
| `login` | `{ user: null, two_factor_required: false, password_change_required: true, challenge_id, reason, can_postpone, postpone_days, expired_at, expires_at }` | `{ user, two_factor_required: false, password_change_required: false, dormancy_restored? }` |
| `loginTwoFactor` | 위와 같음(`two_factor_required` 없음) | `{ user, password_change_required: false, dormancy_restored? }` |
| `loginPasswordChange` (body `challenge_id`·`password`·`password_confirmation`) | — | `{ user, password_change_required: false, dormancy_restored? }` |
| `loginPasswordChangePostpone` (body `challenge_id`) | — | `{ user, password_change_required: false, postponed_until, dormancy_restored? }` |

`dormancy_restored` 는 휴면이 그 로그인으로 풀렸을 때만 실립니다. 네 핸들러 모두 결과의 `extras` 를 `response` 에
펼쳐 전달합니다(engine-v1.76.0 전에는 엔진이 버려 휴면 해제 안내가 뜨지 않았습니다).

실패(422)는 `error.errors` 로 구분합니다 — 규칙 위반·현재 비밀번호와 같은 값·최근 사용 비밀번호는
`errors.password`, 식별자 만료·무효(시간 초과, 이미 사용, 다른 로그인 경로의 식별자)는 `errors.challenge_id` 입니다.
후자는 **처음부터 다시 로그인**해야 합니다 — 2단계 인증 코드도 이미 소비됐습니다.

### 레이아웃 작성 규칙

- 세 단계(자격증명 / 2단계 인증 / 비밀번호 변경)의 블록 `if` 는 서로 **상보적**입니다. 제출 시퀀스의 `login`·
  `loginTwoFactor`·`loginPasswordChange` 도 상호배타 `if` 를 갖습니다.
- `login`·`loginTwoFactor` 성공 분기는 **`response.password_change_required` 를 먼저** 봅니다. 「2단계 인증이 아니면
  로그인 성공」(`navigate if="{{!response.two_factor_required}}"`)으로 판정하면 변경 단계 응답(`user: null`, 토큰 없음)을
  성공으로 오판해 이동하고, 보호 화면이 다시 로그인으로 돌려보냅니다.
- 같은 시퀀스·`onSuccess` 안에서 방금 저장한 단계 상태를 다시 읽지 않습니다 — `{{response.*}}` 만 씁니다.
- 변경 단계 상태도 `init_actions` 에서 초기화합니다(화면을 떠났다 오면 처음부터).
- 「다음에 변경」 버튼은 `can_postpone` 일 때만 그립니다. 「처음부터 다시」 는 두 단계 상태를 모두 비웁니다.
- `errors.challenge_id` 422 를 받으면 안내 후 자격증명 단계로 돌아갑니다.
- 최종 성공 지점(로그인·2단계 인증·변경·연기)마다 `response.dormancy_restored` 토스트와 이동을 같은 모양으로 둡니다.

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

// 최초 상태는 getState() 로 읽는다 (on 은 지난 이벤트를 다시 보내지 않는다)
render(authManager.getState());

// 인증 상태 변경 감지 — on() 은 해제 함수를 돌려준다
const offState = authManager.on('authStateChange', (state) => {
  render(state);
});

// 로그아웃 감지 — 인자는 로그아웃 직전 상태
const offLogout = authManager.on('logout', (previousState) => {
  console.log('User logged out', previousState.type);
});

// 화면을 떠날 때 해제
offState();
offLogout();
```

React 컴포넌트에서는 effect 정리 함수로 해제합니다.

```tsx
useEffect(() => {
  const authManager = G7Core.AuthManager.getInstance();
  setAuth(authManager.getState());

  return authManager.on('authStateChange', (state) => setAuth(state));
}, []);
```

**메서드**:

| 메서드 | 설명 |
|--------|------|
| `on(event, handler)` | 핸들러를 등록하고 해제 함수를 반환 |
| `off(event, handler)` | 등록한 핸들러를 해제 (등록 때와 같은 참조) |
| `getState()` | 현재 인증 상태의 사본 (`{ isAuthenticated, user, type }`) |

**사용 가능한 이벤트**:

| 이벤트 | 발생 시점 | 핸들러 인자 |
|--------|-----------|-------------|
| `login` | 로그인 또는 2단계 인증 완료로 세션이 열릴 때 | 새 인증 상태 |
| `logout` | `logout()` 이 끝날 때 (서버 호출 실패여도 발생) | 로그아웃 직전 상태 |
| `tokenRefreshed` | 토큰 갱신에 성공했을 때 | 없음 |
| `authStateChange` | 인증 상태가 바뀔 때 (아래 참고) | 현재 인증 상태 |

`authStateChange` 는 다음 경우에 발생합니다.

- 로그인으로 세션이 열릴 때 (`login` 바로 뒤)
- `checkAuth()` 가 사용자 정보를 받았을 때 (이미 인증 상태였어도 발생)
- `logout()` 이 끝날 때 (`logout` 바로 뒤, 한 번만)
- 토큰이 없거나 인증 확인이 실패해 인증 상태에서 비인증 상태로 바뀔 때 (비인증에서 비인증으로는 발생하지 않음)

**최초 상태는 `getState()` 로 읽습니다.** 첫 인증 확인은 확장 번들이 실리기 전에 끝날 수 있고, `on()` 은 이미 지나간 이벤트를 다시 보내지 않습니다. 구독을 건 시점의 상태는 `getState()` 로 먼저 읽고, 이후 변경만 이벤트로 받습니다.

**해제를 빠뜨리지 않습니다.** 화면을 오가며 구독을 걸고 풀지 않으면 화면을 떠난 뒤에도 핸들러가 남아 쌓입니다.

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

`AuthConfig` 의 갱신 가능 필드: `loginPath`, `defaultPath`, `loginEndpoint`, `logoutEndpoint`, `refreshEndpoint`, `userEndpoint`, `unauthorizedRedirect`, `guestOnlyPaths`(engine-v1.78.1).

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

### 코드 예시 3 — 로그인 전용 화면 경로 (`guestOnlyPaths`)

로그인 이동 주소(`getLoginRedirectUrl`)는 로그인 전용 화면(로그인·가입·비밀번호 찾기·재설정)을 복귀 경로(`?redirect=`)로 싣지 않는다 — 실으면 로그인을 마친 뒤 다시 로그인 폼에 떨어진다. 로그인 화면이 이미 복귀 경로를 갖고 있으면(`/login?redirect=/mypage`) 그 경로를 이어 싣는다. 기본값은 관리자 `/admin/login`·`/admin/forgot-password`·`/admin/reset-password`, 사용자 `/login`·`/register`·`/forgot-password`·`/reset-password` 이고 `loginPath` 는 항상 포함된다. 이 화면들의 경로를 바꾼 템플릿은 `loginPath` 와 함께 갱신한다.

```typescript
AuthManager.getInstance().updateConfig('user', {
  loginPath: '/account/signin',
  guestOnlyPaths: ['/account/signin', '/account/join', '/account/forgot', '/account/reset'],
});
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

```html
<on type="click">
  <toast if="{{query?.reason === 'session_expired'}}" type="warning" message="$t:auth.session_expired_toast"/>
</on>
```

> 위는 소스(`.g7.html`), 아래는 그 컴파일 결과인 런타임 계약 JSON 이다.

```json
// 로그인 레이아웃 init_actions 예시
{
  "if": "{{query?.reason === 'session_expired'}}",
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