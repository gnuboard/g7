// E2E: tests/Playwright/specs/sso-*.spec.ts 가 로그인 버튼으로 startLogin 을 탄다. startWithTicket(마이페이지 연결 시작)은 Playwright 스펙이 없다 — F1 Chrome MCP 계획서 §10 M4 실측, Vitest 로 덮음
/**
 * SSO 연동 — 로그인 흐름 영역 핸들러.
 *
 * 회사 계정 왕복은 이 사이트의 플러그인 웹 라우트(`/plugins/sirsoft-sso/login/{idp}`)가 서버 302 로 IdP 에 보낸다 — 엔진 `navigate` 는
 * 같은 출처 화면 이동이라 서버 라우트를 열지 못하므로, 시작 주소로는 전체 페이지 이동을 한다. 이동할 수 있는 주소는 이 플러그인의 같은
 * 출처 시작 경로뿐이다(외부 주소로 내보내는 열린 리디렉션을 만들지 않는다).
 */

/** 이동을 허용하는 같은 출처 경로 접두 */
const START_PREFIX = '/plugins/sirsoft-sso/login/';

/** 시작 티켓을 받는 API 접두 */
const API_PREFIX = '/plugins/sirsoft-sso';

interface CoreGlobal {
  api?: { post?: (url: string, data?: unknown) => Promise<{ data?: { start_url?: string } }> };
  dispatch?: (action: unknown) => unknown;
  t?: (key: string) => string;
}

interface StartLoginAction {
  params?: { url?: unknown };
}

interface StartWithTicketAction {
  params?: { endpoint?: unknown; body?: unknown };
}

/**
 * 코어 전역을 꺼냅니다.
 *
 * @returns 코어 전역 (없으면 빈 객체)
 */
function core(): CoreGlobal {
  return ((window as unknown as { G7Core?: CoreGlobal }).G7Core ?? {}) as CoreGlobal;
}

/**
 * 시작 주소가 이 플러그인의 같은 출처 시작 경로인지 확인하고 경로로 돌려줍니다.
 *
 * @param url - 시작 주소 (상대 경로 또는 같은 출처 절대 주소)
 * @returns 경로+쿼리, 허용되지 않으면 null
 */
export function safeStartPath(url: unknown): string | null {
  if (typeof url !== 'string' || url === '' || url.startsWith('//') || url.includes('\\')) {
    return null;
  }

  try {
    const parsed = new URL(url, window.location.origin);
    if (parsed.origin !== window.location.origin || !parsed.pathname.startsWith(START_PREFIX)) {
      return null;
    }

    return parsed.pathname + parsed.search;
  } catch {
    return null;
  }
}

/**
 * 실패 토스트를 띄웁니다.
 *
 * @param error - 오류 (응답 문구가 있으면 그것을 쓴다)
 */
function notifyFailure(error: unknown): void {
  const message =
    (error as { response?: { data?: { message?: string } } })?.response?.data?.message ??
    core().t?.('sirsoft-sso.errors.generic') ??
    'Failed';

  void core().dispatch?.({ handler: 'toast', params: { type: 'error', message } });
}

/**
 * 회사 계정 로그인 왕복을 시작합니다 — 공개 IdP 목록의 `start_url` 로 전체 페이지 이동.
 *
 * @param action - `{ params: { url } }`
 * @returns 이동했으면 true
 */
export function startLoginHandler(action: StartLoginAction): boolean {
  const path = safeStartPath(action?.params?.url);
  if (path === null) {
    notifyFailure(null);

    return false;
  }

  window.location.assign(path);

  return true;
}

/**
 * 로그인 상태가 필요한 왕복(마이페이지 연결, 관리자 테스트)을 시작합니다 — API 가 1회용 시작 티켓 주소를 주면 그리로 이동.
 *
 * 브라우저 이동에는 Bearer 토큰이 실리지 않으므로 회원을 아는 API 가 먼저 티켓을 발급한다. 같은 응답이 브라우저 묶음 쿠키를 굽는다.
 *
 * @param action - `{ params: { endpoint, body } }` — endpoint 는 이 플러그인 API 경로(`/plugins/sirsoft-sso/...`)
 * @returns 이동했으면 true
 */
export async function startWithTicketHandler(action: StartWithTicketAction): Promise<boolean> {
  const endpoint = action?.params?.endpoint;
  const api = core().api;
  if (typeof endpoint !== 'string' || !endpoint.startsWith(API_PREFIX + '/') || typeof api?.post !== 'function') {
    notifyFailure(null);

    return false;
  }

  try {
    // 클라이언트 객체에서 바로 부른다 — 메서드를 떼어 부르면 클라이언트 내부의 this 가 사라진다.
    const response = await api.post(endpoint, action?.params?.body);
    const path = safeStartPath(response?.data?.start_url);
    if (path === null) {
      notifyFailure(null);

      return false;
    }

    window.location.assign(path);

    return true;
  } catch (error) {
    notifyFailure(error);

    return false;
  }
}

/** 흐름 영역 핸들러 — 레이아웃에서 `sirsoft-sso.startLogin`, `sirsoft-sso.startWithTicket` 으로 부른다. */
export const flowHandlers: Record<string, (...args: never[]) => unknown> = {
  startLogin: startLoginHandler,
  startWithTicket: startWithTicketHandler,
};
