# IdentityGuardInterceptor — 코어 본인인증 인터셉터 레퍼런스

코어 `resources/js/core/identity/IdentityGuardInterceptor.ts` 정적 클래스의 공개 API와 동작 계약을 다룹니다. 템플릿 / 플러그인이 IDV 흐름을 wiring 할 때 참조하세요.

## TL;DR (5초 요약)

```text
1. ActionDispatcher.handleApiCall 응답 후처리에서 isIdentityRequired/handle 호출 (engine-v1.44.0+)
2. setLauncher(launcher) 로 모달 launcher 등록 — 템플릿 부트스트랩 의무
3. launcher 반환 타입 = Promise<VerificationResult> (engine-v1.46.0+)
4. handle() 가 verified 시 return_request.url 에 ?verification_token=... query 자동 부착 후 fetch
5. defaultLauncher — launcher 미등록 시 토스트 + /identity/challenge?return=... navigate 폴백
```

## 정적 API 전체

```typescript
class IdentityGuardInterceptor {
  // 등록 / 진단
  static setLauncher(launcher: ModalLauncher): void
  static hasLauncher(): boolean
  static reset(): void                       // 테스트 / 진단용

  // 응답 감지
  static isIdentityRequired(status: number, body: unknown): body is IdentityResponse428
  static async handle(response: IdentityResponse428): Promise<Response | null>

  // Deferred resolver — 모달 ↔ launcher Promise 통신
  static createDeferred(): Promise<VerificationResult>
  static resolveDeferred(result: VerificationResult): void

  // external_redirect helper
  static redirectExternally(payload: VerificationPayload): Promise<VerificationResult>
}
```

## launcher 반환 타입 — VerificationResult

engine-v1.46.0+ — 4-상태 객체로 확장 (이전: `Promise<boolean>`).

```typescript
type VerificationResult =
  | { status: 'verified'; token: string; providerData?: Record<string, unknown> }
  | { status: 'pending'; pollUrl: string; pollIntervalMs?: number; expiresAt: string }
  | { status: 'cancelled' }
  | { status: 'failed'; failureCode: string; reason?: string };
```

| 상태 | handle() 동작 |
| --- | --- |
| `verified` | `return_request.url` 에 `?verification_token=` 자동 부착 → `fetch` 재실행 → Response 반환 |
| `pending` | 1차 구현은 `failed` 로 강등 (caller 에 null 반환). 향후 폴링 루프 도입 시 활용 |
| `cancelled` | null 반환 — 원 요청 폐기 |
| `failed` | null 반환 — `failureCode` 는 caller 가 onError 분기에서 처리 |

## verification_token 자동 query 부착

`handle()` 이 verify 성공 시 `return_request.url` 에 token 을 query 로 자동 부착:

```text
원 요청: POST /api/auth/register   (body: { email, password, ... })
재실행:  POST /api/auth/register?verification_token=tok-abc-123   (body: 원본 그대로)
```

회원가입 폼 등 기존 레이아웃이 `query.verification_token` 패턴을 사용 중이라면 그대로 호환. 백엔드 `IdvTokenRule` 은 query/body 어디에 있든 검증.

## resolveIdentityChallenge 핸들러 spec

코어 `ActionDispatcher` 가 등록한 표준 핸들러 — 레이아웃 JSON 에서 직접 호출.

| params | 타입 | 설명 |
| --- | --- | --- |
| `result` | `'verified'\|'cancelled'\|'failed'\|'pending'` | 결과 종류 (필수) |
| `token` | string | result=verified 일 때 verification_token (필수) |
| `failureCode` | string | result=failed 일 때 — INVALID_CODE / EXPIRED / MAX_ATTEMPTS 등 |
| `reason` | string | result=failed 의 사람-읽기용 메시지 (선택) |
| `providerData` | object | provider 가 돌려준 추가 데이터 (선택) |
| `pollUrl`, `pollIntervalMs`, `expiresAt` | — | result=pending 인터페이스 (예약) |

값 누락 시 안전한 기본값으로 강등:
- result=verified 인데 token 누락 → `failed/MISSING_TOKEN`
- result=pending 인데 pollUrl/expiresAt 누락 → `failed/MALFORMED_PENDING`
- result 미상 / 오타 → `cancelled`

## defaultLauncher — launcher 미등록 폴백

외부 템플릿이 `setLauncher` 미호출 시 코어가 사용하는 폴백:

```text
1. external_redirect 또는 redirect_url 있음 → redirectExternally 위임
2. G7Core.toast: warning("본인 확인이 필요합니다.")
3. sessionStorage stash (return_url + payload)
4. G7Core.dispatch: navigate('/identity/challenge?return=' + encodeURIComponent(currentUrl))
5. Promise<never> — 페이지 unmount
```

G7Core 미초기화 환경: `console.error` 만 출력 + `failed/G7_NOT_READY` 반환.

## external_redirect helper — redirectExternally

```text
1. payload.redirect_url 누락 → failed/MISSING_REDIRECT_URL
2. sessionStorage[g7.identity.redirectStash] = JSON({ return_url, payload, stashed_at })
3. window.location.href = redirect_url
4. Promise<never>
```

콜백 URL(`POST /api/identity/callback/{providerId}` 처리 후 redirect) 에서 stash 복원 책임은 콜백 처리 레이아웃이 담당.

## sessionStorage stash 키

```typescript
import { IDENTITY_REDIRECT_STASH_KEY } from '../identity/types';
// 또는 window.G7Core.identity.redirectStashKey
// 값: 'g7.identity.redirectStash'
```

## window.G7Core.identity 노출 (engine-v1.46.0+)

템플릿 IIFE 번들이 코어 모듈을 중복 포함하면 정적 클래스 상태가 분리되므로, 직접 import 대신 `window.G7Core.identity` 를 사용해야 합니다.

```typescript
// 템플릿 / 플러그인 코드
const identity = (window as any).G7Core?.identity;
if (identity?.setLauncher) {
  identity.setLauncher(myLauncher);
}
identity.redirectExternally(payload);
const deferred = identity.createDeferred();
identity.resolveDeferred({ status: 'verified', token });
```

| 노출 메서드 | 설명 |
| --- | --- |
| `setLauncher(launcher)` | 모달 launcher 등록 |
| `hasLauncher()` | 등록 여부 |
| `redirectExternally(payload)` | external_redirect helper |
| `createDeferred()` | deferred Promise 생성 |
| `resolveDeferred(result)` | resolver 호출 (resolveIdentityChallenge 핸들러가 내부적으로 사용) |
| `reset()` | launcher + deferred 슬롯 초기화 (테스트용) |
| `redirectStashKey` | 'g7.identity.redirectStash' 상수 |

## 동시 launcher 진입 처리

두 번째 `createDeferred()` 호출 시 이전 deferred 는 자동으로 `cancelled` 로 강제 종료:

```typescript
const first = IdentityGuardInterceptor.createDeferred();
const second = IdentityGuardInterceptor.createDeferred();   // first 는 자동 cancelled
IdentityGuardInterceptor.resolveDeferred({ status: 'verified', token: 'tok' });
// first  → resolves to { status: 'cancelled' }
// second → resolves to { status: 'verified', token: 'tok' }
```

## 관련 문서

- [identity-verification-ui.md](identity-verification-ui.md) — 모달 UI / 상태 머신 / Extension Point 슬롯 가이드
- [../extension/template-idv-bootstrap.md](../extension/template-idv-bootstrap.md) — 외부 템플릿 launcher 등록 가이드
- [../backend/identity-policies.md](../backend/identity-policies.md) — 백엔드 정책 시스템
