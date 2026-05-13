# 템플릿 IDV launcher 등록 가이드

외부 템플릿(third-party template) 이 그누보드7의 IDV 인프라와 연동하기 위한 부트스트랩 가이드입니다. 코어가 발생시키는 428 응답을 가로채 모달 / 풀페이지로 본인 확인 흐름을 진입시키는 launcher 등록이 핵심입니다.

## TL;DR (5초 요약)

```text
1. 템플릿 부트스트랩(initTemplate)에서 window.G7Core.identity.setLauncher(launcher) 호출
2. launcher 는 (payload) => Promise<VerificationResult> 시그니처
3. 모달 파셜 + _user_base/_admin_base 의 modals 배열 마운트 + i18n 키 추가
4. launcher 미등록 시 코어 defaultLauncher 가 /identity/challenge 풀페이지로 폴백
5. 외부 IDV provider SDK 통합은 G7 표준 Extension Point + scripts + callExternalEmbed 패턴 (다음 우편번호/CKEditor5 와 동일)
```

## 등록 위치 / 타이밍

`src/index.ts` 의 `initTemplate()` 함수 내부, 핸들러 등록 직후가 가장 자연스러운 진입점입니다.

```typescript
// src/index.ts
import { handlerMap } from './handlers';
import { registerMyTemplateIdentityLauncher } from './handlers/identityLauncher';

export function initTemplate(): void {
  if (typeof window === 'undefined') return;

  const registerHandlers = () => {
    const actionDispatcher = (window as any).G7Core?.getActionDispatcher?.();
    if (actionDispatcher) {
      Object.entries(handlerMap).forEach(([name, handler]) => {
        actionDispatcher.registerHandler(name, handler);
      });

      // IDV launcher 는 핸들러 등록 직후 호출 — G7Core.identity 가 준비된 시점
      registerMyTemplateIdentityLauncher();
    } else {
      // ActionDispatcher 미초기화 — retry
    }
  };

  // window.load 또는 document.readyState='complete' 시점에 호출
  if (document.readyState === 'complete') registerHandlers();
  else window.addEventListener('load', registerHandlers);
}
```

## 직접 import 금지 — window.G7Core.identity 사용

템플릿 IIFE 번들이 코어 모듈을 중복 포함하면 정적 클래스 상태가 분리됩니다. **반드시 `window.G7Core.identity` namespace 를 통해 호출**하세요.

```typescript
// ❌ 금지 — 직접 import 시 IdentityGuardInterceptor 사본이 템플릿 번들에 포함되어 코어와 분리됨
import { IdentityGuardInterceptor } from '.../core/identity/IdentityGuardInterceptor';

// ✅ 올바른 사용 — 코어의 단일 인스턴스 공유
const identity = (window as any).G7Core?.identity;
identity?.setLauncher?.(launcher);
```

## launcher 시그니처

```typescript
type ModalLauncher = (payload: VerificationPayload) => Promise<VerificationResult>;

interface VerificationPayload {
  policy_key: string;
  purpose: string;
  provider_id?: string | null;
  render_hint?: string | null;
  challenge_start_url?: string;
  redirect_url?: string;
  return_request?: { method: string; url: string } | null;
}

type VerificationResult =
  | { status: 'verified'; token: string; providerData?: Record<string, unknown> }
  | { status: 'pending'; pollUrl: string; pollIntervalMs?: number; expiresAt: string }
  | { status: 'cancelled' }
  | { status: 'failed'; failureCode: string; reason?: string };
```

코어 spec: [../frontend/identity-guard-interceptor.md](../frontend/identity-guard-interceptor.md).

## launcher 작성 필수 항목 체크리스트

`_global.identityChallenge` 네임스페이스 스키마는 [identity-verification-ui.md](../frontend/identity-verification-ui.md) "`_global.identityChallenge` 네임스페이스 스키마 (CONTRACT)" 섹션의 표를 SSoT 로 따릅니다. **외부 IDV 프로바이더 플러그인이 자기 launcher 를 작성하는 경우에도 같은 스키마를 준수해야** 코어 모달과 호환됩니다.

- [ ] `external_redirect` 또는 `redirect_url` 분기 — `identity.redirectExternally(payload)` 위임
- [ ] `POST /api/identity/challenges` (또는 `payload.challenge_start_url`) 로 challenge 시작
- [ ] **`G7Core.state.set({ identityChallenge: {...} })` 시 모든 SSoT 필드 채우기**:
  - 식별/메타: `policy_key`, `purpose`, `provider_id`, `render_hint`
  - challenge 응답: `challenge_id`, `expires_at`, `public_payload`
  - **`target`** — challenge 시작 body 에 사용한 `{ email?, phone? }` 객체를 그대로 저장. 모달 재전송 액션이 같은 target 으로 challenge 를 재요청할 수 있게 함. 누락 시 백엔드가 422 `missing_target` 반환 → 재전송 버튼이 깨짐.
  - UI 초기값: `code=''`, `error=null`, `attempts=0`, `maxAttempts=N`, `remainingSeconds=초기 잔여`, `resendCooldown=0`
- [ ] 카운트다운 — `window.setInterval` 직접 사용 (코어 `startInterval` 핸들러는 stale closure 위험으로 비권장). 매 초 `G7Core.state.set({ identityChallenge: { remainingSeconds, resendCooldown } })`.
- [ ] `G7Core.state.subscribe` 로 `_global.identityChallenge.expires_at` 변경 감지 — 모달 재전송 onSuccess 가 새 expires_at 을 set 하면 launcher 클로저의 카운트다운 기준 동기화.
- [ ] `identity.createDeferred()` Promise 반환 + `G7Core.dispatch({ handler: 'openModal', target: 'identity-challenge-modal' })`.
- [ ] 정리 — `await deferred` 후 `clearInterval` + `unsubscribe` 호출.

## 모달 파셜 작성 필수 항목 체크리스트

- [ ] `id: "identity-challenge-modal"` (모든 템플릿 동일 ID 권장 — launcher 와 결합)
- [ ] `type: "composite"`, `name: "Modal"`
- [ ] `props.closeOnBackdropClick: false` / `closeOnEscape: false` / `showCloseButton: false` — 자동 닫기 차단
- [ ] `_global.identityChallenge.*` 네임스페이스로 상태 관리 (launcher 가 미리 채움)
- [ ] verify onSuccess → `resolveIdentityChallenge { result: 'verified', token }` + `closeModal`
- [ ] cancel onClick → `resolveIdentityChallenge { result: 'cancelled' }` + `closeModal`
- [ ] **재전송 onClick → `POST /api/identity/challenges` body 에 `target: "{{_global.identityChallenge?.target ?? null}}"` 동봉** (launcher 가 SSoT 에 저장한 그 target 을 그대로 재사용)
- [ ] 재전송 onSuccess → `_global.identityChallenge.challenge_id`, `expires_at`, `attempts=0`, `code=''` 갱신
- [ ] Extension Point 슬롯 3종 — `identity_provider_ui:text_code`, `identity_provider_ui:link`, `identity_provider_ui:provider`
- [ ] i18n 키 — `{template-namespace}.identity.challenge.*` (예: `user.identity.challenge.title`)

## Base 레이아웃 마운트 의무

`_user_base.json` / `_admin_base.json` 의 `modals` 배열에 모달 파셜을 추가해야 launcher 가 호출하는 `openModal` 이 동작합니다.

```json
{
  "modals": [
    { "partial": "partials/_identity_challenge_modal.json" },
    { "partial": "partials/_modal_other.json" }
  ]
}
```

## launcher 본체 의사코드

```typescript
async function myTemplateLauncher(payload: VerificationPayload): Promise<VerificationResult> {
  const G7Core = (window as any).G7Core;
  const identity = G7Core?.identity;

  if (!G7Core?.dispatch || !identity) return { status: 'failed', failureCode: 'G7_NOT_READY' };

  // (1) external_redirect 분기
  if (payload.render_hint === 'external_redirect' || payload.redirect_url) {
    return identity.redirectExternally(payload);
  }

  // (2) Challenge 시작 — POST /api/identity/challenges
  let challenge;
  try {
    challenge = await startChallenge(payload);   // fetch + 응답 파싱
  } catch (err) {
    return { status: 'failed', failureCode: 'CHALLENGE_START_FAILED', reason: String(err) };
  }

  // 응답에 redirect_url 이 있으면 redirect 분기 (provider 가 link 대신 redirect 결정)
  if (challenge.redirect_url) {
    return identity.redirectExternally({ ...payload, render_hint: 'external_redirect', redirect_url: challenge.redirect_url });
  }

  // (3) _global.identityChallenge 네임스페이스 setup
  G7Core.state.set({
    identityChallenge: {
      policy_key: payload.policy_key,
      purpose: payload.purpose,
      provider_id: payload.provider_id ?? null,
      render_hint: challenge.render_hint,
      challenge_id: challenge.id,
      expires_at: challenge.expires_at,
      public_payload: challenge.public_payload ?? {},
      code: '', error: null, attempts: 0, maxAttempts: 5,
      remainingSeconds: calcRemaining(challenge.expires_at),
      resendCooldown: 0,
    },
  });

  // (4) 카운트다운 startInterval

  // (5) 모달 open + deferred Promise 반환
  const deferred = identity.createDeferred();
  await G7Core.dispatch({ handler: 'openModal', target: 'identity-challenge-modal' });
  return await deferred;
}
```

## 외부 IDV provider SDK 통합 — G7 표준 패턴 사용

KCP / PortOne / 토스인증 / Stripe Identity 등 외부 SDK 를 추가할 때 **새 인프라 도입 불필요**. 다음 우편번호 (`sirsoft-daum_postcode`) 와 CKEditor5 (`sirsoft-ckeditor5`) 가 이미 사용 중인 G7 표준 패턴을 그대로 적용:

| 표준 인프라 | 위치 | IDV 활용 |
| --- | --- | --- |
| 레이아웃 `scripts` 필드 | `LayoutLoader.LayoutScript` | 외부 SDK URL 자동 로드 (id 기반 dedupe) |
| `extension_point` + scripts 병합 | `LayoutExtensionService` | 슬롯에 컴포넌트 + scripts 동시 주입 |
| `callExternalEmbed` 핸들러 | `ActionDispatcher.handleCallExternalEmbed` | SDK 인스턴스 layer/popup + callbackAction/callbackSetState |

플러그인 extension JSON 예시:

```json
{
  "extension_point": "identity_provider_ui:provider",
  "scripts": [
    { "src": "https://sdk.example.com/v2.js", "id": "vendor_sdk_v2" }
  ],
  "components": [
    {
      "name": "Button",
      "if": "{{_global.identityChallenge?.provider_id === 'vendor.method'}}",
      "events": {
        "onClick": {
          "actions": [
            {
              "handler": "callExternalEmbed",
              "params": {
                "constructor": "VendorSdk.IdentityVerification",
                "config": { "channelKey": "..." },
                "callbackAction": [
                  {
                    "handler": "resolveIdentityChallenge",
                    "params": { "result": "verified", "token": "{{result.verification_token}}" }
                  }
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

## launcher 미등록 시 코어 폴백 동작

외부 템플릿이 launcher 를 호출하지 않으면 코어 `defaultLauncher` 가 동작:

1. `render_hint=external_redirect` → `redirectExternally` 위임
2. G7Core 토스트 발행: "본인 확인이 필요합니다."
3. sessionStorage stash + `/identity/challenge?return={현재URL}` navigate
4. 풀페이지 레이아웃이 verify 후 `resolveIdentityChallenge` 호출 → 원 페이지로 redirect

폴백을 의도적으로 사용하려는 외부 템플릿은:

- `auth/identity_challenge.json` 풀페이지 레이아웃을 만들거나 다른 번들 템플릿(예: sirsoft-basic)에서 복사
- `routes.json` 에 `/identity/challenge` 라우트 등록 (`auth_required: false`)

## 검증 체크리스트

- [ ] launcher 등록 후 비활성 정책 1개를 활성화하여 모달이 열리는지 확인
- [ ] verify 성공 후 원 요청이 `?verification_token=...` 으로 재실행되는지 g7-network 로 확인
- [ ] cancel 클릭 시 원 요청 폐기되는지 확인
- [ ] external_redirect 케이스에서 sessionStorage stash 가 작성되고 redirect 후 복원되는지 확인
- [ ] launcher 등록 안 된 상태에서도 폴백 풀페이지로 흐름이 진입하는지 확인

## 관련 문서

- [../frontend/identity-verification-ui.md](../frontend/identity-verification-ui.md) — 모달 UI 표준 + Extension Point 슬롯
- [../frontend/identity-guard-interceptor.md](../frontend/identity-guard-interceptor.md) — 코어 인터셉터 API 레퍼런스
- [../backend/identity-policies.md](../backend/identity-policies.md) — 백엔드 정책 시스템
- [module-identity-settings.md](module-identity-settings.md) — 모듈/플러그인 IDV 정책/목적 등록
