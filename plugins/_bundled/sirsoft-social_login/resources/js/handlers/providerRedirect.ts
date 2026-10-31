/**
 * 로그인 회원의 제공자 왕복(연결·본인 재확인)을 시작하는 핸들러.
 *
 * 왕복 시작은 로그인 회원 API 를 거쳐야 한다 — 서버가 회원과 묶인 state 를 만들고 제공자 인가 주소를 돌려준다. 요청은 코어
 * API 클라이언트(`G7Core.api`)로 보낸다(인증 헤더·401 처리는 코어가 맡는다 — 로그인 토큰을 이 코드가 읽지 않는다).
 * 받은 주소로는 같은 출처이거나 https 일 때만 이동한다(실행 스킴 차단).
 */

/** 플러그인 API 접두 (코어 API 클라이언트의 `/api` 뒤) */
const API_PREFIX = '/plugins/sirsoft-social_login';

/** 핸들러가 받는 액션 */
export interface ProviderRedirectAction {
  params?: { provider?: unknown; return?: unknown };
}

/** 코어 전역에서 쓰는 것 */
interface CoreGlobal {
  api?: { post?: (url: string, data?: unknown) => Promise<{ data?: { authorize_url?: string } }> };
  dispatch?: (action: unknown) => unknown;
  t?: (key: string) => string;
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
 * 이동해도 되는 주소인지 판정합니다 — 같은 출처 또는 https 만.
 *
 * @param url 주소
 * @returns 이동해도 되면 그 절대 주소, 아니면 null
 */
export function safeProviderUrl(url: unknown): string | null {
  if (typeof url !== 'string' || url === '') {
    return null;
  }

  try {
    const parsed = new URL(url, window.location.origin);
    if (parsed.origin === window.location.origin || parsed.protocol === 'https:') {
      return parsed.toString();
    }
  } catch {
    return null;
  }

  return null;
}

/**
 * 실패를 토스트로 알립니다 (서버 문구가 있으면 그것).
 *
 * @param error 실패
 * @returns 반환값 없음
 */
function notifyFailure(error: unknown): void {
  const message =
    (error as { response?: { data?: { message?: string } } })?.response?.data?.message ??
    core().t?.('sirsoft-social_login.errors.provider_error') ??
    'Failed';

  void core().dispatch?.({ handler: 'toast', params: { type: 'error', message } });
}

/**
 * API 로 왕복을 시작하고 제공자 화면으로 이동합니다.
 *
 * @param path API 경로 (접두 제외)
 * @param body 요청 본문
 * @returns 이동했으면 true
 */
async function startRoundTrip(path: string, body?: Record<string, unknown>): Promise<boolean> {
  const api = core().api;
  if (typeof api?.post !== 'function') {
    return false;
  }

  try {
    // 클라이언트 객체에서 바로 부른다 — 메서드를 떼어 부르면 클라이언트 내부의 this 가 사라져 요청 전에 실패한다.
    const response = await api.post(`${API_PREFIX}${path}`, body);
    const url = safeProviderUrl(response?.data?.authorize_url);
    if (url === null) {
      notifyFailure(null);

      return false;
    }

    window.location.assign(url);

    return true;
  } catch (error) {
    notifyFailure(error);

    return false;
  }
}

/**
 * 제공자 키를 검사합니다 (서버 라우트와 같은 형식).
 *
 * @param value 값
 * @returns 키, 형식이 틀리면 null
 */
function providerKey(value: unknown): string | null {
  return typeof value === 'string' && /^[a-z][a-z0-9_]{1,49}$/.test(value) ? value : null;
}

/**
 * 마이페이지 [연결] — 연결 왕복을 시작합니다.
 *
 * @param action `provider` 파라미터
 * @returns 이동했으면 true
 */
export async function startLinkHandler(action: ProviderRedirectAction): Promise<boolean> {
  const key = providerKey(action?.params?.provider);

  return key === null ? false : startRoundTrip(`/me/accounts/${key}/link-start`);
}

/**
 * 「{제공자}로 다시 확인」 — 본인 재확인 왕복을 시작합니다(대체 이메일 회원).
 *
 * @param action `provider`·`return`(확인 뒤 돌아올 같은 출처 경로) 파라미터
 * @returns 이동했으면 true
 */
export async function startReauthHandler(action: ProviderRedirectAction): Promise<boolean> {
  const key = providerKey(action?.params?.provider);
  const back = typeof action?.params?.return === 'string' ? action.params.return : window.location.pathname;

  return key === null ? false : startRoundTrip(`/me/reauth/${key}/start`, { return: back });
}
