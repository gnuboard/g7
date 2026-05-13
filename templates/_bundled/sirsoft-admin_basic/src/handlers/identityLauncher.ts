/**
 * IDV Modal Launcher (sirsoft-admin_basic)
 *
 * 코어 `IdentityGuardInterceptor` 가 428 응답을 가로채면 호출하는 launcher 입니다.
 *
 * 코어 진입점은 `window.G7Core.identity.*` 를 통해 사용 — 템플릿 IIFE 번들이 코어 모듈을
 * 중복 포함하면서 정적 클래스 상태가 분리되는 사고를 방지합니다 (다음 우편번호 / CKEditor5 와 동일 패턴).
 *
 * sirsoft-basic 의 launcher 와 동일한 기본 흐름을 사용하지만 admin 컨텍스트 차이:
 * - 사용자는 항상 로그인 상태(관리자) → target.email 은 세션에서 자동 도출됨 → 클라이언트 추출 불필요
 * - 풀페이지 폴백 경로가 `/admin/identity/challenge`
 * - 기본 purpose 추정값이 `sensitive_action` (admin 정책은 대부분 민감 작업)
 */

const logger = ((window as any).G7Core?.createLogger?.('Template:sirsoft-admin_basic:IdentityLauncher')) ?? {
  log: (...args: unknown[]) => console.log('[Template:sirsoft-admin_basic:IdentityLauncher]', ...args),
  warn: (...args: unknown[]) => console.warn('[Template:sirsoft-admin_basic:IdentityLauncher]', ...args),
  error: (...args: unknown[]) => console.error('[Template:sirsoft-admin_basic:IdentityLauncher]', ...args),
};

interface VerificationPayload {
  policy_key: string;
  purpose: string;
  provider_id?: string | null;
  render_hint?: string | null;
  challenge_start_url?: string;
  redirect_url?: string;
  return_request?: { method: string; url: string; headers_echo?: string[] } | null;
}

type VerificationResult =
  | { status: 'verified'; token: string; providerData?: Record<string, unknown> }
  | { status: 'pending'; pollUrl: string; pollIntervalMs?: number; expiresAt: string }
  | { status: 'cancelled' }
  | { status: 'failed'; failureCode: string; reason?: string };

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

async function startChallenge(payload: VerificationPayload, target: { email?: string } | null = null): Promise<ChallengeResponseData> {
  // admin 컨텍스트 — 일반적으로 로그인 상태(서버가 세션 기반으로 target 도출).
  // 단, target 이 명시되면(예: 재전송 동기화) 그것을 사용.
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

async function sirsoftAdminBasicIdentityLauncher(payload: VerificationPayload): Promise<VerificationResult> {
  const G7Core = (window as any).G7Core;
  const identity = G7Core?.identity;

  if (!G7Core?.dispatch || !G7Core?.state || !identity) {
    logger.error('G7Core 또는 G7Core.identity 가 초기화되지 않아 launcher 를 실행할 수 없습니다.');
    return { status: 'failed', failureCode: 'G7_NOT_READY' };
  }

  if (payload.render_hint === 'external_redirect' || payload.redirect_url) {
    return identity.redirectExternally(payload);
  }

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

  if (challenge.redirect_url) {
    return identity.redirectExternally({
      ...payload,
      render_hint: 'external_redirect',
      redirect_url: challenge.redirect_url,
    });
  }

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
      // admin 은 일반적으로 서버 세션이 target 결정 — null 로 두면 모달 재전송도 동일 흐름.
      target: null,
      code: '',
      error: null,
      attempts: 0,
      maxAttempts: 5,
      remainingSeconds,
      resendCooldown: 0,
    },
  });

  // 카운트다운 — launcher 자체 setInterval (startInterval 핸들러의 stale-context 우회)
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

  // 재전송으로 expires_at 이 갱신될 때 launcher 클로저의 currentExpiresMs 동기화
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
        resendCooldown = cd;
      }
    }) ?? null;
  } catch {
    /* subscribe 미지원 — 첫 만료시각 기준만 카운트다운 */
  }

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
      redirect_url: `/admin/identity/challenge?challenge_id=${encodeURIComponent(challenge.id)}&return=${encodeURIComponent(window.location.href)}`,
    });
  }

  const result = await deferred;

  window.clearInterval(tickHandle);
  unsubscribe?.();

  return result;
}

/**
 * 부트스트랩 시 코어 인터셉터에 launcher 를 등록합니다.
 */
export function registerSirsoftAdminBasicIdentityLauncher(): void {
  const identity = (window as any).G7Core?.identity;
  if (!identity?.setLauncher) {
    logger.warn(
      'G7Core.identity 가 아직 초기화되지 않아 launcher 등록을 건너뜁니다. 코어 부트스트랩 순서를 확인하세요.'
    );
    return;
  }
  identity.setLauncher(sirsoftAdminBasicIdentityLauncher);
  logger.log('IDV launcher registered (sirsoft-admin_basic)');
}
