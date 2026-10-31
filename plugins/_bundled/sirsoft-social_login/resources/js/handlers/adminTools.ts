/**
 * 관리자 설정 화면 도구 핸들러 — 주소 복사·브라우저 로그인 테스트 창 열기.
 *
 * 테스트 왕복은 관리자 API 를 코어 API 클라이언트(`G7Core.api`)로 불러 시작한다(인증 헤더·401 처리는 코어 몫 — 로그인 토큰을 이
 * 코드가 읽지 않는다). 새 창은 클릭 순간에 빈 창으로 먼저 연다 — 응답을 기다린 뒤 열면 브라우저가 팝업으로 막는다.
 */
import { safeProviderUrl } from './providerRedirect';

/** 플러그인 API 접두 (코어 API 클라이언트의 `/api` 뒤) */
const API_PREFIX = '/plugins/sirsoft-social_login';

/** 핸들러가 받는 액션 */
export interface AdminToolAction {
  params?: { text?: unknown; provider?: unknown; successMessage?: unknown };
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
 * 토스트를 띄웁니다.
 *
 * @param type 종류 (success·error)
 * @param key 문구 키
 * @returns 반환값 없음
 */
function toast(type: 'success' | 'error', key: string): void {
  void core().dispatch?.({ handler: 'toast', params: { type, message: core().t?.(key) ?? key } });
}

/**
 * 문자열을 클립보드에 씁니다 — 비보안 출처(http 개발 사이트)에서는 텍스트 선택 복사로 물러섭니다.
 *
 * @param text 복사할 문자열
 * @returns 복사했으면 true
 */
async function writeClipboard(text: string): Promise<boolean> {
  try {
    if (navigator.clipboard?.writeText) {
      await navigator.clipboard.writeText(text);

      return true;
    }
  } catch {
    // 권한 거부 등 — 아래 방식으로 다시 시도한다.
  }

  const area = document.createElement('textarea');
  area.value = text;
  area.setAttribute('readonly', '');
  area.style.position = 'fixed';
  area.style.opacity = '0';
  document.body.appendChild(area);
  area.select();
  let copied = false;
  try {
    copied = document.execCommand('copy');
  } catch {
    copied = false;
  }
  document.body.removeChild(area);

  return copied;
}

/**
 * Redirect URI·웹훅 주소를 복사하고 결과를 토스트로 알립니다.
 *
 * @param action `text` 파라미터
 * @returns 복사했으면 true
 */
export async function copyToClipboardHandler(action: AdminToolAction): Promise<boolean> {
  const text = typeof action?.params?.text === 'string' ? action.params.text : '';
  if (text === '') {
    return false;
  }

  const copied = await writeClipboard(text);
  toast(copied ? 'success' : 'error', copied ? 'sirsoft-social_login.admin.providers.copied' : 'sirsoft-social_login.admin.providers.copy_failed');

  return copied;
}

/**
 * [브라우저로 로그인 테스트] — 테스트 왕복을 시작하고 새 창에서 제공자 화면을 엽니다.
 *
 * 창은 클릭 순간에 비워 열고, 주소를 받으면 그 창을 제공자 화면으로 보낸다. 받은 주소는 같은 출처 또는 https 만 따른다.
 *
 * @param action `provider` 파라미터
 * @returns 창을 제공자 화면으로 보냈으면 true
 */
export async function openTestLoginHandler(action: AdminToolAction): Promise<boolean> {
  const key = action?.params?.provider;
  const api = core().api;
  if (typeof key !== 'string' || !/^[a-z][a-z0-9_]{1,49}$/.test(key) || typeof api?.post !== 'function') {
    return false;
  }

  const popup = window.open('', '_blank');
  if (popup) {
    popup.opener = null;
  }

  try {
    // 클라이언트 객체에서 바로 부른다 — 메서드를 떼어 부르면 클라이언트 내부의 this 가 사라진다.
    const response = await api.post(`${API_PREFIX}/admin/providers/${key}/test-login`);
    const url = safeProviderUrl(response?.data?.authorize_url);
    if (url === null) {
      popup?.close();
      toast('error', 'sirsoft-social_login.admin.providers.test_login_failed');

      return false;
    }

    if (popup) {
      // base-path:allow 서버가 주는 값은 제공자의 인가 주소(https 절대 주소)다 — 사이트 경로가 아니다.
      popup.location.href = url;
    } else {
      // 팝업이 막힌 브라우저 — 관리 화면을 떠나지 않도록 이동하지 않고, 팝업을 허용한 뒤 다시 누르라고 알린다.
      toast('error', 'sirsoft-social_login.admin.providers.popup_blocked');

      return false;
    }

    return true;
  } catch {
    popup?.close();
    toast('error', 'sirsoft-social_login.admin.providers.test_login_failed');

    return false;
  }
}
