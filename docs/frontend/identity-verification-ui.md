# 본인인증(IDV) 공통 UI 가이드

운영자가 활성화한 IDV 정책 N개에 대해 사용자/관리자 화면에서 동일한 모달 UX 로 본인 확인 흐름을 진행하기 위한 프론트엔드 표준입니다.

## TL;DR (5초 요약)

```text
1. 모든 IDV 강제 지점은 동일한 428 응답 형식을 공유 (코어 9 + 게시판 4 + 이커머스 4 + N)
2. 코어 IdentityGuardInterceptor 가 428 가로채 → 템플릿이 등록한 launcher 호출
3. launcher 가 challenge 시작 + 모달 open + deferred Promise 반환
4. 모달은 _global.identityChallenge 네임스페이스로 상태 일원화 — verify onSuccess 가 resolveIdentityChallenge 핸들러로 통보
5. render_hint(text_code/link/external_redirect) + provider_id 로 Extension Point 슬롯 분기 — 외부 IDV 플러그인은 동일 슬롯에 자기 SDK 주입
```

## 흐름도 — 428 → launcher → 모달 → return_request 재실행

```text
[사용자 액션] POST /api/auth/register
  ↓
[백엔드] EnforceIdentityPolicy 미들웨어 → 428 + verification payload
  ↓
[코어 ActionDispatcher.handleApiCall] IdentityGuardInterceptor.isIdentityRequired 감지
  ↓
[코어 IdentityGuardInterceptor.handle] launcher 호출 (템플릿 등록)
  ↓
[템플릿 launcher] POST /api/identity/challenges → challenge_id, expires_at, render_hint
  ↓
[템플릿 launcher] G7Core.state.set({ identityChallenge: {...} })
  ↓
[템플릿 launcher] dispatch(openModal: identity-challenge-modal)
  ↓
[모달 파셜] render_hint 별 UI 표시 (Extension Point 슬롯으로 플러그인 override 가능)
  ↓
[사용자 입력] 코드 입력 + 확인 클릭
  ↓
[모달] POST /api/identity/challenges/{id}/verify → 200 + verification_token
  ↓
[모달 onSuccess] resolveIdentityChallenge { result: 'verified', token } + closeModal
  ↓
[코어 IdentityGuardInterceptor.handle] return_request.url 에 ?verification_token=... 부착 후 fetch
  ↓
[백엔드] IdvTokenRule 검증 통과 → 가입 완료
```

## render_hint 별 UI 표준

| render_hint | 모달 표시 내용 | 폴백 동작 |
| --- | --- | --- |
| `text_code` | 6자리 코드 입력 + 카운트다운 + 재전송 버튼 | 코어 default UI (모달 파셜 fallback content) |
| `link` | "메일/SMS 링크 클릭" 안내 + 폴링 또는 재전송 | 코어 default UI |
| `external_redirect` | 모달 안 띄움. launcher 가 sessionStorage stash 후 redirect_url 로 navigate | 풀페이지 `/identity/challenge` |
| 플러그인 정의 (예: `plugin:kcp`) | 플러그인이 슬롯에 자기 SDK 주입 | provider 슬롯 비어있으면 무시 |

## Extension Point 슬롯 명명 규칙

코어 모달 파셜(`_identity_challenge_modal.json`)에 다음 슬롯이 박혀있어 외부 IDV provider 플러그인이 자기 UI 와 SDK 를 주입할 수 있습니다:

| 슬롯 이름 | 용도 | 기본 콘텐츠 |
| --- | --- | --- |
| `identity_provider_ui:text_code` | OTP 코드 입력 UI 슬롯 | 코어 default — 6자리 코드 입력 + 카운트다운 |
| `identity_provider_ui:link` | 링크 안내 UI 슬롯 | 코어 default — 메일 안내 + 스팸함 점검 안내 |
| `identity_provider_ui:provider` | provider 별 SDK 주입 슬롯 | 비어있음 (플러그인 미주입 시 렌더 없음) |

플러그인은 다음 우편번호 / CKEditor5 와 동일한 G7 표준 패턴(`scripts` + `extension_point` + `callExternalEmbed`)으로 자기 콘텐츠를 주입합니다 — 코어 신규 인프라 도입 불필요.

```json
{
  "extension_point": "identity_provider_ui:provider",
  "scripts": [
    { "src": "https://kcp-cdn.example.com/sdk.js", "id": "kcp_sdk" }
  ],
  "components": [
    {
      "name": "Button",
      "if": "{{_global.identityChallenge?.provider_id === 'plugin.kcp'}}",
      "events": {
        "onClick": {
          "actions": [
            {
              "handler": "callExternalEmbed",
              "params": {
                "constructor": "KCP.IdentitySDK",
                "config": { "siteCode": "...", "purpose": "{{_global.identityChallenge.purpose}}" },
                "callbackAction": [
                  { "handler": "resolveIdentityChallenge", "params": { "result": "verified", "token": "{{result.token}}" } }
                ]
              }
            }
          ]
        }
      }
    }
  ]
}
```

## `_global.identityChallenge` 네임스페이스 스키마 (CONTRACT)

본인인증 흐름의 모든 상태는 `_global.identityChallenge.*` 한 네임스페이스로 일원화합니다. launcher 가 모달 open 직전에 이 객체를 set 하고, 모달의 모든 액션이 이 경로를 읽고/씁니다. 외부 IDV 프로바이더 플러그인이 자기 launcher 를 작성하는 경우에도 **이 스키마를 그대로 준수**해야 모달 / 풀페이지 / 재전송 / 카운트다운이 일관되게 동작합니다.

| 키 | 타입 | 출처 | 용도 |
| --- | --- | --- | --- |
| `policy_key` | string | 428 verification payload | 디버깅 / 모달 분기 |
| `purpose` | string | 428 verification payload | 재전송 시 동일 purpose 로 challenge 재요청 |
| `provider_id` | string \| null | 428 verification payload | provider 별 슬롯 매칭 (`identity_provider_ui:provider`) |
| `render_hint` | string | challenge 응답 (또는 payload fallback) | 모달 슬롯 분기 — `text_code` / `link` / `external_redirect` / 플러그인 정의 |
| `challenge_id` | string | challenge 응답 | verify / cancel API 의 path param |
| `expires_at` | ISO8601 string | challenge 응답 | 카운트다운 만료 기준 |
| `public_payload` | object | challenge 응답 | 코드 길이 / 링크 힌트 등 provider 가 공개한 메타 |
| **`target`** | **`{ email?, phone? } \| null`** | **launcher 가 폼/세션에서 추출** | **모달 재전송 시 동일 target 으로 challenge 재요청 — 누락 시 백엔드 422 `missing_target`** |
| `code` | string | 모달 입력 | text_code 모드 OTP 입력값 |
| `error` | string \| null | 모달 onError | 사용자에게 보일 에러 메시지 |
| `attempts` / `maxAttempts` | number | 모달 onError / launcher 초기값 | 시도 횟수 표시 + verify 버튼 비활성화 조건 |
| `remainingSeconds` | number | launcher 카운트다운 | 분/초 표시 + verify 버튼 비활성화 조건 |
| `resendCooldown` | number | 모달 재전송 onSuccess / launcher 카운트다운 | 재전송 버튼 비활성화 조건 (30초) |

> 🛑 `target` 필드 누락은 흔한 회귀입니다. launcher 가 첫 challenge 시작에 사용한 target 을 반드시 같은 객체에 저장하세요 — 모달 재전송 액션이 다른 곳에서 폼 값을 읽을 수 없습니다(모달 컨텍스트는 페이지 _local 과 분리).

### launcher 가 채워야 하는 필드 vs 모달이 갱신하는 필드

```text
[launcher 책임 — 모달 open 전 G7Core.state.set]
  policy_key, purpose, provider_id, render_hint
  challenge_id, expires_at, public_payload
  target                                   ← 외부 provider 플러그인도 동일 의무
  code='', error=null, attempts=0, maxAttempts=5
  remainingSeconds=초기값, resendCooldown=0

[모달 액션 책임 — 사용자 인터랙션에 따라 갱신]
  code (Input onChange)
  error / attempts (verify onError)
  challenge_id / expires_at / render_hint / attempts=0 / code='' (재전송 onSuccess)
  resendCooldown=30 (재전송 onClick 직후)

[launcher 자체 setInterval 책임 — 매 초 갱신]
  remainingSeconds (expires_at 기반 재계산)
  resendCooldown (1씩 감소)
```

> ℹ️ launcher 는 코어 `startInterval` 액션 핸들러를 사용하지 않고 직접 `window.setInterval` 으로 카운트다운을 돌리는 것을 권장합니다 — `startInterval` 은 등록 시점의 dispatch context 를 클로저로 캡처하기 때문에 직전의 `G7Core.state.set` (React 비동기 setState) 이 아직 commit 되지 않은 stale 컨텍스트로 매 tick 평가될 위험이 있습니다.

## 모달 상태 머신 (`_global.identityChallenge`)

상태는 `_global.identityChallenge.*` 네임스페이스로 일원화. launcher 가 모달 open 직전에 채우고, 모달이 사용자 액션에 따라 갱신.

```text
[idle]
  ↓ launcher 진입 + POST /challenges 성공
[challenge_requested] — challenge_id, expires_at, render_hint, public_payload 채워짐
  ↓ openModal
[awaiting_input] — 사용자 코드 입력 대기, 카운트다운 진행
  ↓ 확인 버튼 → POST /verify
[verifying]
  ├─ 200 → resolveIdentityChallenge { verified, token } → [verified] → closeModal
  ├─ 422 INVALID_CODE → setState error + attempts++ → [awaiting_input]
  ├─ 422 EXPIRED / MAX_ATTEMPTS → setState error → 사용자에게 재전송 권유
  └─ network error → setState error → 재시도
  ↓ 카운트다운 0 도달
[expired] — 사용자가 재전송 버튼으로 [challenge_requested] 회귀
  ↓ 사용자 cancel
[cancelled] — resolveIdentityChallenge { cancelled } → 원 요청 폐기
```

## i18n 키 컨벤션

`identity.challenge.*` namespace 를 user/admin 양쪽에서 사용:

| 키 | 용도 |
| --- | --- |
| `title` | 모달 / 풀페이지 제목 |
| `code_title`, `code_subtitle`, `code_placeholder` | text_code 모드 헤더 / 입력 안내 |
| `verify` | 확인 버튼 |
| `resend`, `resend_link`, `resend_cooldown`, `resend_success` | 재전송 버튼 / 쿨다운 표시 / 성공 토스트 |
| `remaining_time`, `remaining_attempts`, `expired` | 카운트다운 / 만료 메시지 |
| `link_title`, `link_subtitle`, `check_spam` | link 모드 안내 |
| `external_title`, `external_subtitle`, `manual_redirect` | external_redirect 모드 안내 |
| `error_generic` | 알 수 없는 verify 실패 메시지 |

파라미터 형식: `$t:user.identity.challenge.remaining_time|minutes={{value}}|seconds={{value}}`.

## 422 failure_code 매핑

| failure_code | 의미 | UI 처리 |
| --- | --- | --- |
| `INVALID_CODE` | 코드 해시 불일치 | 에러 표시 + attempts++ |
| `EXPIRED` | TTL 초과 | 에러 표시 + 재전송 권유 |
| `MAX_ATTEMPTS` | 시도 횟수 초과 | 에러 표시 + 재전송 권유 (challenge 새로 발급 필요) |
| `NOT_FOUND` | 알 수 없는 challenge_id | 에러 표시 + 모달 닫고 재시도 권유 |
| `WRONG_PROVIDER` | provider_id 불일치 | 에러 표시 + 처음부터 재시도 |
| `INVALID_STATE` | 이미 처리된 challenge | 에러 표시 + 모달 닫기 |

## resolveIdentityChallenge 핸들러 사용법

코어가 등록한 표준 핸들러 — 모달 / 풀페이지 / 외부 SDK callback 모두 동일 이름으로 호출.

```json
// verify 성공
{ "handler": "resolveIdentityChallenge", "params": { "result": "verified", "token": "{{response.data.verification_token}}" } }

// 사용자 취소
{ "handler": "resolveIdentityChallenge", "params": { "result": "cancelled" } }

// verify 실패 (422 onError 에서)
{ "handler": "resolveIdentityChallenge", "params": { "result": "failed", "failureCode": "INVALID_CODE" } }

// 비동기 검증 — Stripe Identity / 토스인증 push 등 (인터페이스 예약)
{ "handler": "resolveIdentityChallenge", "params": { "result": "pending", "pollUrl": "/api/identity/challenges/{{id}}", "pollIntervalMs": 2000, "expiresAt": "{{...}}" } }
```

상세 스펙: [identity-guard-interceptor.md](identity-guard-interceptor.md).

## VerificationResult 4-상태 머신

코어 `IdentityGuardInterceptor` 의 launcher 반환 타입(engine-v1.46.0+):

```typescript
type VerificationResult =
  | { status: 'verified'; token: string; providerData?: Record<string, unknown> }
  | { status: 'pending'; pollUrl: string; pollIntervalMs?: number; expiresAt: string }
  | { status: 'cancelled' }
  | { status: 'failed'; failureCode: string; reason?: string };
```

- `verified` → return_request 재실행 + verification_token query 자동 부착
- `pending` → 1차 구현은 `failed` 로 강등. 향후 폴링 루프 도입 시 활용
- `cancelled` / `failed` → 원 요청 폐기

## 모달 파셜 작성 시 필수 규칙 (회귀 방지)

IDV 모달 파셜 (`_identity_challenge_modal.json`) 및 동일 패턴을 따르는 OTP/코드 입력 모달 작성 시 아래 규칙을 위반하면 **확인 버튼이 영구 비활성** 또는 **재전송 시 입력값이 초기화되지 않는** 회귀가 발생합니다.

### 1. Input 이벤트는 표준 actions 패턴만 사용

```text
✅ 사용: actions: [{ event: "onChange", handler: "setState", params: {...} }]
❌ 금지: events: { onChange: { actions: [...] } }   ← 엔진이 인식하지 않음
```

엔진은 컴포넌트 노드의 `actions[]` 배열에서 `event` 필드로 이벤트 종류를 분기합니다. `events: {}` 래퍼는 어디에서도 처리되지 않으므로 onChange 가 발생하지 않고, controlled Input 의 `value` 바인딩이 갱신되지 않아 disabled 조건이 영구 true 가 됩니다.

### 2. controlled Input 은 `value` + `onChange` 한 쌍을 반드시 함께 정의

`name="code"` 만으로는 `_global.identityChallenge.code` 같은 외부 네임스페이스에 자동 바인딩되지 않습니다. 모달 내부 (`_global` 사용 강제 컨텍스트)에서는 다음을 모두 명시해야 합니다.

```json
{
  "name": "Input",
  "props": {
    "name": "code",
    "value": "{{_global.identityChallenge?.code ?? ''}}"
  },
  "actions": [
    {
      "event": "onChange",
      "handler": "setState",
      "params": {
        "target": "global",
        "identityChallenge.code": "{{$event.target.value}}"
      }
    }
  ]
}
```

### 3. 재전송 등 재발급 액션은 즉시 입력값 초기화 (apiCall 응답 대기 금지)

재전송 클릭 시 `code` 초기화를 `apiCall.onSuccess` 안에만 넣으면, 네트워크 지연 동안 사용자가 이전 코드를 그대로 보면서 재입력 상황을 인지하지 못합니다. sequence 의 첫 setState (resendCooldown 30 설정과 함께) 에서 `identityChallenge.code: ""` 도 같이 넣어야 합니다.

```json
{
  "handler": "sequence",
  "params": {
    "actions": [
      {
        "handler": "setState",
        "params": {
          "target": "global",
          "identityChallenge.resendCooldown": 30,
          "identityChallenge.error": null,
          "identityChallenge.code": ""   // ← 즉시 초기화
        }
      },
      { "handler": "apiCall", "...": "..." }
    ]
  }
}
```

### 4. 회귀 테스트 의무

`templates/_bundled/{template}/__tests__/layouts/identity-challenge-modal.test.tsx` 에 아래 3개 케이스 필수:

- code Input 에 `events` 키가 존재하지 않을 것 (비표준 래퍼 차단)
- code Input 의 `actions[]` 에 `event:"onChange"` + `handler:"setState"` + `target:"global"` 항목이 존재할 것
- 재전송 setState (resendCooldown=30) 의 params 에 `identityChallenge.code: ""` 가 포함될 것

## 관련 문서

- [identity-guard-interceptor.md](identity-guard-interceptor.md) — 코어 인터셉터 API 레퍼런스
- [../extension/template-idv-bootstrap.md](../extension/template-idv-bootstrap.md) — 외부 템플릿 개발자용 launcher 등록 가이드
- [../backend/identity-policies.md](../backend/identity-policies.md) — 백엔드 정책 시스템 + 비동기 인프라
- [../extension/module-identity-settings.md](../extension/module-identity-settings.md) — 모듈/플러그인 IDV 정책/목적 등록
