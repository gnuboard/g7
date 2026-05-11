/**
 * IDV Modal Launcher (sirsoft-basic)
 *
 * 코어 `IdentityGuardInterceptor` 가 428 응답을 가로채면 호출하는 launcher 입니다.
 *
 * 코어 진입점은 `window.G7Core.identity.*` 를 통해 사용 — 템플릿 IIFE 번들이 코어 모듈을
 * 중복 포함하면서 정적 클래스 상태가 분리되는 사고를 방지합니다 (다음 우편번호 / CKEditor5 와 동일 패턴).
 *
 * 동작 순서:
 * 1. `external_redirect` 분기 — 코어 helper `redirectExternally` 로 위임.
 * 2. `POST /api/identity/challenges` 로 challenge 시작 → `id, expires_at, render_hint, public_payload` 획득.
 * 3. `_global.identityChallenge` 네임스페이스에 verification payload + challenge 응답 병합.
 * 4. 카운트다운 `startInterval` 등록.
 * 5. `openModal: identity-challenge-modal` 디스패치.
 * 6. `createDeferred()` Promise 반환 — 모달이 `resolveIdentityChallenge` 핸들러로 결과 통보 시 resolve.
 *
 * 외부 IDV 플러그인(KCP/PortOne 등) 은 모달 파셜의 `identity_provider_ui:*` Extension Point 슬롯에
 * 자기 컴포넌트 + scripts 를 주입하여 launcher 변경 없이 자기 SDK 를 띄울 수 있습니다.
 */

const logger = ((window as any).G7Core?.createLogger?.('Template:sirsoft-basic:IdentityLauncher')) ?? {
  log: (...args: unknown[]) => console.log('[Template:sirsoft-basic:IdentityLauncher]', ...args),
  warn: (...args: unknown[]) => console.warn('[Template:sirsoft-basic:IdentityLauncher]', ...args),
  error: (...args: unknown[]) => console.error('[Template:sirsoft-basic:IdentityLauncher]', ...args),
};

/**
 * 코어가 launcher 에 전달하는 verification payload (런타임 형태).
 * 코어 타입 import 를 피하려고 로컬에 정의 — 실제 형식은 `resources/js/core/identity/types.ts` 와 동일.
 */
interface VerificationPayload {
  policy_key: string;
  purpose: string;
  provider_id?: string | null;
  render_hint?: string | null;
  challenge_start_url?: string;
  redirect_url?: string;
  return_request?: { method: string; url: string; headers_echo?: string[] } | null;
}

/**
 * launcher 가 코어에 돌려주는 결과 타입 (런타임 형태).
 */
type VerificationResult =
  | { status: 'verified'; token: string; providerData?: Record<string, unknown> }
  | { status: 'pending'; pollUrl: string; pollIntervalMs?: number; expiresAt: string }
  | { status: 'cancelled' }
  | { status: 'failed'; failureCode: string; reason?: string };

/**
 * 가입/비밀번호 재설정 폼 등에서 target.email 을 추출합니다.
 *
 * 우선순위: signup form > password_reset form > 로그인 사용자
 *
 * 반환값은 launcher 에서 두 곳에 사용:
 * 1) 첫 POST /api/identity/challenges body 에 동봉
 * 2) `_global.identityChallenge.target` 에 저장 — 모달 재전송 액션이 같은 target 을 재사용
 */
function resolveTargetEmail(payload: VerificationPayload): { email?: string } | null {
  const G7Core = (window as any).G7Core;
  const local = G7Core?.state?.getLocal?.() ?? {};
  const global = G7Core?.state?.getGlobal?.() ?? G7Core?.state?.get?.() ?? {};

  const candidates: Array<string | undefined> = [
    payload.purpose === 'signup' ? local?.registerForm?.email : undefined,
    payload.purpose === 'password_reset' ? local?.passwordResetForm?.email : undefined,
    payload.purpose === 'password_reset' ? local?.email : undefined,
    global?.user?.email,
    global?.auth?.user?.email,
  ];

  const email = candidates.find((v) => typeof v === 'string' && v.length > 0);
  return email ? { email } : null;
}

/**
 * Bearer 토큰을 안전하게 추출합니다 (있으면).
 */
function getAuthHeader(): Record<string, string> {
  const G7Core = (window as any).G7Core;
  const token = G7Core?.api?.getToken?.() ?? null;
  return token ? { Authorization: `Bearer ${token}` } : {};
}

interface ChallengeResponseData {
  id: string;
  expires_at: string;
  render_hint: string;
  public_payload?: Record<string, unknown>;
  redirect_url?: string;
}

/**
 * `POST /api/identity/challenges` 로 challenge 를 시작합니다.
 */
async function startChallenge(payload: VerificationPayload): Promise<ChallengeResponseData> {
  const target = resolveTargetEmail(payload);
  const body: Record<string, unknown> = { purpose: payload.purpose };
  if (payload.provider_id) body.provider_id = payload.provider_id;
  if (target) body.target = target;

  const url = payload.challenge_start_url || '/api/identity/challenges';
  const res = await fetch(url, {
    method: 'POST',
    credentials: 'include',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      ...getAuthHeader(),
    },
    body: JSON.stringify(body),
  });

  const json = await res.json().catch(() => null);
  if (!res.ok || !json?.success) {
    const msg = json?.message ?? `Challenge 시작 실패 (HTTP ${res.status})`;
    throw new Error(msg);
  }

  const data = json.data ?? json;
  return {
    id: data.id,
    expires_at: data.expires_at,
    render_hint: data.render_hint ?? payload.render_hint ?? 'text_code',
    public_payload: data.public_payload,
    redirect_url: data.redirect_url,
  };
}

/**
 * sirsoft-basic 의 IDV launcher 본체.
 */
async function sirsoftBasicIdentityLauncher(payload: VerificationPayload): Promise<VerificationResult> {
  const G7Core = (window as any).G7Core;
  const identity = G7Core?.identity;

  if (!G7Core?.dispatch || !G7Core?.state || !identity) {
    logger.error('G7Core 또는 G7Core.identity 가 초기화되지 않아 launcher 를 실행할 수 없습니다.');
    return { status: 'failed', failureCode: 'G7_NOT_READY' };
  }

  // (1) external_redirect 분기 — 모달 안 띄움
  if (payload.render_hint === 'external_redirect' || payload.redirect_url) {
    return identity.redirectExternally(payload);
  }

  // (2) Challenge 시작
  let challenge: ChallengeResponseData;
  try {
    challenge = await startChallenge(payload);
  } catch (err) {
    logger.error('Challenge 시작 실패:', err);
    try {
      await G7Core.dispatch({
        handler: 'toast',
        params: {
          type: 'error',
          message: err instanceof Error ? err.message : '본인인증 시작에 실패했습니다.',
        },
      });
    } catch {
      /* ignore */
    }
    return {
      status: 'failed',
      failureCode: 'CHALLENGE_START_FAILED',
      reason: err instanceof Error ? err.message : String(err),
    };
  }

  // 응답에 redirect_url 이 있으면 외부 redirect 로 분기 (provider 가 link 대신 redirect 결정)
  if (challenge.redirect_url) {
    return identity.redirectExternally({
      ...payload,
      render_hint: 'external_redirect',
      redirect_url: challenge.redirect_url,
    });
  }

  // (3) _global.identityChallenge 네임스페이스 setup
  // target 도 함께 저장 — 모달 재전송 액션이 같은 target 으로 challenge 재요청 가능하게
  const target = resolveTargetEmail(payload);
  const expiresMs = challenge.expires_at ? new Date(challenge.expires_at).getTime() : 0;
  const remainingSeconds = expiresMs > 0 ? Math.max(0, Math.floor((expiresMs - Date.now()) / 1000)) : 0;

  G7Core.state.set({
    identityChallenge: {
      policy_key: payload.policy_key,
      purpose: payload.purpose,
      provider_id: payload.provider_id ?? null,
      render_hint: challenge.render_hint,
      challenge_id: challenge.id,
      expires_at: challenge.expires_at,
      public_payload: challenge.public_payload ?? {},
      target: target ?? null,
      code: '',
      error: null,
      attempts: 0,
      maxAttempts: 5,
      remainingSeconds,
      resendCooldown: 0,
    },
  });

  // (4) 카운트다운 타이머 — launcher 자체 setInterval 사용
  // startInterval 핸들러는 등록 시점의 dispatch context 를 클로저로 캡처하는데,
  // 직전의 G7Core.state.set 가 React 비동기 setState 라서 캡처된 context 의 _global 에는
  // identityChallenge 가 아직 없을 수 있음(stale). 그러면 매 tick 표현식에서 expires_at 가
  // undefined 로 평가되어 remainingSeconds 가 영원히 0 으로 남음. launcher 클로저에서
  // expiresMs 를 직접 보유하고 G7Core.state.set 만 매 초 호출하면 stale 경로를 우회.
  let currentExpiresMs = expiresMs;
  let resendCooldown = 0;
  const tickHandle = window.setInterval(() => {
    const remaining = currentExpiresMs > 0
      ? Math.max(0, Math.floor((currentExpiresMs - Date.now()) / 1000))
      : 0;
    resendCooldown = Math.max(0, resendCooldown - 1);
    G7Core.state.set({
      identityChallenge: {
        remainingSeconds: remaining,
        resendCooldown,
      },
    });
  }, 1000);

  // 모달 재전송 액션이 새 challenge 를 발급하면 expires_at 이 갱신되는데,
  // 그 시점에는 launcher 클로저의 currentExpiresMs 를 동기화해야 카운트다운이 새 만료시각을 따름.
  // G7Core.state.subscribe 로 _global.identityChallenge.expires_at 변경을 감지.
  let unsubscribe: (() => void) | null = null;
  try {
    unsubscribe = G7Core.state.subscribe?.((next: any) => {
      const updated = next?.identityChallenge?.expires_at;
      if (typeof updated === 'string') {
        const ms = new Date(updated).getTime();
        if (Number.isFinite(ms) && ms !== currentExpiresMs) {
          currentExpiresMs = ms;
        }
      }
      const cd = next?.identityChallenge?.resendCooldown;
      if (typeof cd === 'number' && cd > resendCooldown) {
        // 재전송 액션이 _global.identityChallenge.resendCooldown 을 30 으로 set 한 직후 동기화
        resendCooldown = cd;
      }
    }) ?? null;
  } catch {
    /* subscribe 미지원 환경 — 첫 만료시각 기준으로만 카운트다운 */
  }

  // (5) deferred Promise 생성 → 모달 open
  const deferred = identity.createDeferred() as Promise<VerificationResult>;
  try {
    await G7Core.dispatch({
      handler: 'openModal',
      target: 'identity-challenge-modal',
    });
  } catch (e) {
    logger.error('openModal 실패 — 풀페이지로 폴백:', e);
    window.clearInterval(tickHandle);
    unsubscribe?.();
    return identity.redirectExternally({
      ...payload,
      render_hint: 'external_redirect',
      redirect_url: `/identity/challenge?challenge_id=${encodeURIComponent(challenge.id)}&return=${encodeURIComponent(window.location.href)}`,
    });
  }

  // (6) 모달이 resolveIdentityChallenge 호출하면 deferred 가 resolve
  const result = await deferred;

  // 정리 — 카운트다운 중단 + subscribe 해제
  window.clearInterval(tickHandle);
  unsubscribe?.();

  return result;
}

/**
 * 부트스트랩 시 코어 인터셉터에 launcher 를 등록합니다.
 *
 * `initTemplate()` 의 핸들러 등록 직후에 호출 — `window.G7Core.identity.setLauncher` 를 사용해
 * 코어의 단일 IdentityGuardInterceptor 정적 클래스에 launcher 를 주입합니다.
 */
export function registerSirsoftBasicIdentityLauncher(): void {
  const identity = (window as any).G7Core?.identity;
  if (!identity?.setLauncher) {
    logger.warn(
      'G7Core.identity 가 아직 초기화되지 않아 launcher 등록을 건너뜁니다. 코어 부트스트랩 순서를 확인하세요.'
    );
    return;
  }
  identity.setLauncher(sirsoftBasicIdentityLauncher);
  logger.log('IDV launcher registered (sirsoft-basic)');
}
