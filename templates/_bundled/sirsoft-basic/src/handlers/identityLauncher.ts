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
interface IdentityVerificationTarget {
  email?: string;
  phone?: string;
}

interface VerificationPayload {
  policy_key: string;
  purpose: string;
  provider_id?: string | null;
  render_hint?: string | null;
  challenge_start_url?: string;
  redirect_url?: string;
  return_request?: { method: string; url: string; headers_echo?: string[] } | null;
  /** 흐름이 apiCall identity_target 으로 선언한 인증 대상 — 코어 인터셉터가 병합해 전달. */
  target?: IdentityVerificationTarget | null;
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
 * 인증 대상(이메일·전화)을 결정합니다.
 *
 * 우선순위 (필드별 보충 머지 — email/phone 각각 첫 비어있지 않은 값 채택):
 *   1. `payload.target` — 흐름이 apiCall `identity_target` 으로 선언한 값 (범용 진입점, 비로그인 흐름의 핵심)
 *   2. 로그인 사용자 세션 (`currentUser` / `user` / `auth.user` 의 email·phone)
 *   3. (하위호환) signup/password_reset 폼 — `identity_target` 미선언 기존 레이아웃 대비
 *
 * 흐름별 폼 경로 하드코딩을 폐기하고 선언값을 1순위로 둠으로써, 주문/게시판/임의 액션 어떤 흐름이든
 * 레이아웃이 `identity_target` 만 선언하면 launcher 변경 없이 동작한다.
 *
 * 반환값은 launcher 에서 두 곳에 사용:
 * 1) 첫 POST /api/identity/challenges body 에 동봉
 * 2) `_global.identityChallenge.target` 에 저장 — 모달 재전송 액션이 같은 target 을 재사용
 *
 * @param payload 코어 인터셉터가 전달한 verification payload (target 병합 포함)
 * @returns 인증 대상 또는 null (email·phone 둘 다 없으면 — 로그인 사용자는 서버 세션 도출)
 */
function resolveTarget(payload: VerificationPayload): IdentityVerificationTarget | null {
  const G7Core = (window as any).G7Core;
  const local = G7Core?.state?.getLocal?.() ?? {};
  const global = G7Core?.state?.getGlobal?.() ?? G7Core?.state?.get?.() ?? {};

  const pick = (...candidates: Array<unknown>): string | undefined =>
    candidates.find((v): v is string => typeof v === 'string' && v.length > 0);

  const email = pick(
    payload.target?.email,
    global?.currentUser?.email,
    global?.user?.email,
    global?.auth?.user?.email,
    payload.purpose === 'signup' ? local?.registerForm?.email : undefined,
    payload.purpose === 'password_reset' ? local?.passwordResetForm?.email : undefined,
    payload.purpose === 'password_reset' ? local?.email : undefined,
  );

  const phone = pick(
    payload.target?.phone,
    global?.currentUser?.phone,
    global?.user?.phone,
    global?.auth?.user?.phone,
  );

  if (!email && !phone) {
    return null;
  }

  const target: IdentityVerificationTarget = {};
  if (email) target.email = email;
  if (phone) target.phone = phone;
  return target;
}

interface ChallengeResponseData {
  id: string;
  expires_at: string;
  render_hint: string;
  public_payload?: Record<string, unknown>;
  redirect_url?: string;
  /** 허용 최대 시도 횟수 — 0 은 무제한 (popup/SDK 형 provider). 코어 mail provider 는 환경설정값을 반영. */
  max_attempts?: number;
  /**
   * 링크 방식 challenge 에만 오는 일회용 비밀값 — 링크를 연 쪽(다른 기기일 수 있음)의 확인 결과를 이 화면이
   * `POST /api/identity/challenges/{id}/claim` 으로 받아 갈 때 쓴다. 상태 조회 응답에는 실리지 않는다.
   */
  claim_secret?: string;
}

/**
 * `POST /api/identity/challenges` 로 challenge 를 시작합니다.
 *
 * @param payload verification payload
 * @param target launcher 본체에서 1회 계산한 인증 대상 (startChallenge 와 _global 저장이 동일 값 공유)
 */
async function startChallenge(
  payload: VerificationPayload,
  target: IdentityVerificationTarget | null,
): Promise<ChallengeResponseData> {
  const body: Record<string, unknown> = { purpose: payload.purpose };
  if (payload.provider_id) body.provider_id = payload.provider_id;
  // 428 이 알려 준 정책 — 서버가 그 정책별 문구를 고른다(켜져 있고 목적이 같을 때만).
  if (payload.policy_key) body.policy_key = payload.policy_key;
  if (target) body.target = target;

  // challenge 시작 주소는 428 응답 본문(`challenge_start_url`)에서 온다. 코어 `G7Core.api` 로 보내면
  // Bearer·XSRF 는 동일 출처 대상에만 실리고(교차 출처 서버가 428 을 흉내 내 자기 주소를 넣어도 토큰이
  // 가지 않는다, KVE-2026-2350), 화면 언어(`Accept-Language: g7_locale`)도 코어가 붙인다 — IDV 메일이
  // 사용자 화면 언어로 렌더된다.
  const url = payload.challenge_start_url || '/api/identity/challenges';
  const api = (window as any).G7Core?.api;
  // 사용자에게 보이는 실패 문구는 화면 언어로 — 서버가 준 message 가 없을 때만 쓴다.
  const failedText = (): string => {
    const translated = (window as any).G7Core?.t?.('common.error_occurred');
    return typeof translated === 'string' && translated !== 'common.error_occurred' ? translated : 'An error occurred.';
  };
  if (typeof api?.post !== 'function') {
    throw new Error(failedText());
  }

  let json: any;
  try {
    json = await api.post(url, body);
  } catch (err: any) {
    const status = err?.response?.status;
    const msg = err?.response?.data?.message
      ?? (status ? `${failedText()} (HTTP ${status})` : (err instanceof Error ? err.message : String(err)));
    throw new Error(msg);
  }
  if (!json?.success) {
    throw new Error(json?.message ?? failedText());
  }

  const data = json.data ?? json;
  return {
    id: data.id,
    expires_at: data.expires_at,
    render_hint: data.render_hint ?? payload.render_hint ?? 'text_code',
    public_payload: data.public_payload,
    redirect_url: data.redirect_url,
    max_attempts: typeof data.max_attempts === 'number' ? data.max_attempts : undefined,
    claim_secret: typeof data.claim_secret === 'string' ? data.claim_secret : undefined,
  };
}

/**
 * sirsoft-basic 의 IDV launcher 본체.
 *
 * 테스트에서 직접 호출할 수 있도록 export (런타임 진입점은 registerSirsoftBasicIdentityLauncher).
 */
export async function sirsoftBasicIdentityLauncher(payload: VerificationPayload): Promise<VerificationResult> {
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

  // 인증 대상을 1회 계산 — startChallenge body 와 _global.identityChallenge.target 저장이 동일 값 공유
  // (모달 재전송이 같은 target 으로 challenge 재요청). 흐름 선언값(payload.target) 우선.
  const target = resolveTarget(payload);

  // (2) Challenge 시작
  let challenge: ChallengeResponseData;
  try {
    challenge = await startChallenge(payload, target);
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
  // 위에서 1회 계산한 target 을 재사용 — 모달 재전송 액션이 같은 target 으로 challenge 재요청 가능하게
  const expiresMs = challenge.expires_at ? new Date(challenge.expires_at).getTime() : 0;
  const remainingSeconds = expiresMs > 0 ? Math.max(0, Math.floor((expiresMs - Date.now()) / 1000)) : 0;

  // maxAttempts: 백엔드 응답값이 있으면 사용, 없으면 0(무제한 — popup/SDK 형 provider 의 기본).
  // 코어 mail provider 응답에는 환경설정의 max_attempts 가 정확히 반영되므로 화면 카운트도 정합.
  const maxAttempts = typeof challenge.max_attempts === 'number' ? challenge.max_attempts : 0;

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
      maxAttempts,
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
    const subscription = G7Core.state.subscribe?.((next: any) => {
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
    });
    // 코어는 해제 함수를 돌려준다 — 함수가 아니면(구 코어) 보관하지 않는다
    unsubscribe = typeof subscription === 'function' ? subscription : null;
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
      // 인증 화면은 `query.return_url` 을 읽는다 — 같은 출처 경로+쿼리만 넘긴다(절대 주소 아님). 해시는 빼는데,
      // 인증 화면의 복귀 이동(mergeQuery)이 쿼리를 덧붙일 때 해시 안으로 들어가지 않게 하기 위해서다.
      redirect_url: `/identity/challenge?challenge_id=${encodeURIComponent(challenge.id)}&return_url=${encodeURIComponent(
        `${window.location.pathname}${window.location.search}`,
      )}`,
    });
  }

  // (6) 모달이 resolveIdentityChallenge 호출하면 deferred 가 resolve
  // 링크 방식 — 확인은 메일 링크가 하므로 시작 응답의 비밀값으로 결과를 받아 간다(인증번호 방식은 묻지 않는다).
  const stopClaim = startLinkClaimPolling(challenge, G7Core);

  // 정리 — 결과가 어떻게 나든(취소·실패 포함) 결과 폴링·카운트다운 중단 + subscribe 해제. 구독이 남으면 모달을
  // 닫은 뒤에도 전역 상태가 바뀔 때마다 이 클로저가 불린다
  try {
    return await deferred;
  } finally {
    stopClaim();
    window.clearInterval(tickHandle);
    unsubscribe?.();
  }
}

/**
 * 링크 방식 challenge 의 일회용 비밀값 — 건 번호별로 이 모듈 안에만 둔다. 전역 상태(`_global`)는 개발자 도구로 누구나
 * 볼 수 있어 토큰을 받아 갈 수 있는 값을 두지 않는다(docs/frontend/security.md 「_global 상태 민감 정보 금지」).
 */
const claimSecrets = new Map<string, string>();

/**
 * [다시 보내기]가 새로 받은 링크 방식 비밀값을 기억합니다 — 인증 창이 새 건의 확인 결과를 받아 가게.
 *
 * @param action 액션 정의 (params.challenge_id · params.claim_secret)
 * @param _context 실행 컨텍스트 (미사용)
 * @return 기억했으면 true
 */
export function rememberIdentityClaimHandler(
  action?: { params?: Record<string, unknown> } & Record<string, any>,
  _context?: any,
): boolean {
  const id = typeof action?.params?.challenge_id === 'string' ? action.params.challenge_id : '';
  const secret = typeof action?.params?.claim_secret === 'string' ? action.params.claim_secret : '';
  if (!id || !secret) {
    return false;
  }
  claimSecrets.set(id, secret);
  return true;
}

/** 링크 방식 결과를 묻는 간격 — 서버 throttle(분당 60) 안에서 회원이 링크를 누른 뒤 곧 이어지게. */
const CLAIM_INTERVAL_MS = 3000;

/**
 * 링크 방식 challenge 의 확인 결과를 받아 갈 때까지 주기적으로 묻습니다.
 *
 * 확인 결과의 토큰은 링크를 연 쪽에만 돌아가므로, 인증 창을 띄운 이 화면은 시작 응답으로 받은 일회용 비밀값으로
 * 서버에 물어 받아 간다. 받으면 원래 요청(428 을 낸 요청)을 이어 가도록 resolve 하고 창을 닫는다. [다시 보내기]가
 * 새 건을 만들면 전역 인증 상태의 건 번호로 갈아 묻고, 그 건의 비밀값은 `rememberIdentityClaimHandler` 가 넘겨 준다. 끝낼 수 없는 답(거절·이미 사용·만료)을 받은 건은
 * 같은 건·같은 비밀값으로 다시 묻지 않는다.
 *
 * @param challenge 시작 응답
 * @param G7Core 코어 전역 객체
 * @return 묻기를 멈추는 함수
 */
function startLinkClaimPolling(challenge: ChallengeResponseData, G7Core: any): () => void {
  if (challenge.render_hint !== 'link' || !challenge.claim_secret) {
    return () => {};
  }

  let stopped = false;
  let inFlight = false;
  let deadKey: string | null = null;
  claimSecrets.set(challenge.id, challenge.claim_secret);

  const handle = setInterval(async () => {
    if (stopped || inFlight) return;
    const current = G7Core.state?.getGlobal?.()?.identityChallenge ?? {};
    const id = String(current.challenge_id ?? challenge.id);
    const secret = claimSecrets.get(id);
    if (!secret) return;
    if (`${id}:${secret}` === deadKey) return;

    const api = G7Core?.api;
    if (typeof api?.post !== 'function') return;
    const url = `/api/identity/challenges/${encodeURIComponent(id)}/claim`;
    inFlight = true;
    try {
      // 코어 `G7Core.api` 로 묻는다 — Bearer·XSRF·화면 언어는 코어가 같은 출처 요청에만 붙인다(템플릿은 로그인 토큰을 읽지 않는다).
      const json = await api.post(url, { claim_secret: secret });
      if (stopped) return;
      const token = json?.data?.verification_token;
      if (typeof token === 'string' && token !== '') {
        stopped = true;
        clearInterval(handle);
        await G7Core.dispatch({ handler: 'resolveIdentityChallenge', params: { result: 'verified', token } });
        await G7Core.dispatch({ handler: 'closeModal', target: 'identity-challenge-modal' });
        return;
      }
      // 아직 링크를 열지 않았으면(202 pending) 다음 주기에 다시 묻는다.
      if (json?.data?.status === 'pending') return;
      deadKey = `${id}:${secret}`;
    } catch (err: any) {
      // 서버가 답한 실패(거절·이미 사용·만료)는 같은 건·같은 비밀값으로 다시 묻지 않는다.
      // 응답이 없는 일시 오류(네트워크)는 다음 주기에 다시 묻는다.
      if (err?.response) deadKey = `${id}:${secret}`;
    } finally {
      inFlight = false;
    }
  }, CLAIM_INTERVAL_MS);

  return () => {
    stopped = true;
    clearInterval(handle);
    claimSecrets.clear();
  };
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
