import { guestOwnershipHeaders, waitForGuestOrderToken } from './guestOrderToken';

export interface PaymentCloseReportContext {
    closeReportUrl?: string;
    oid: string;
    price: number;
    payment_method?: string;
}

interface G7CoreApi {
    post: (url: string, data?: unknown, config?: { headers?: Record<string, string> }) => Promise<unknown>;
}

/**
 * 코어 공개 API 클라이언트를 돌려줍니다.
 *
 * @return API 클라이언트 (코어가 아직 없으면 null)
 */
function coreApi(): G7CoreApi | null {
    const api = (window as unknown as { G7Core?: { api?: Partial<G7CoreApi> } }).G7Core?.api;

    return typeof api?.post === 'function' ? (api as G7CoreApi) : null;
}

/**
 * 서버가 내려준 보고 주소를 `G7Core.api` 경로(`/api` 이하)로 바꿉니다.
 *
 * 서버는 사이트 주소로 만든 절대 URL(`https://사이트/plugins/…/payment/close-report`)을 준다. 이 주소를 그대로
 * 부르면 `/api` 접두사가 빠져 API 라우트에 닿지 못한다. 경로만 꺼내 같은 사이트의 API 로 보낸다 — 출처(host)는
 * 버리므로 조회 토큰이 다른 사이트로 새지 않는다.
 *
 * @param url 서버가 내려준 주소 (절대·상대 모두)
 * @return `G7Core.api` 에 넘길 경로, 해석할 수 없으면 null
 */
export function toApiPath(url: string): string | null {
    let parsed: URL;
    try {
        parsed = new URL(url, window.location.origin);
    } catch {
        return null;
    }

    const path = `${parsed.pathname}${parsed.search}`;

    return path.startsWith('/api/') ? path.slice(4) : path;
}

function trimReason(reason: string): string {
    return reason.trim().slice(0, 160);
}

function resolveRetryUrl(closeReportUrl?: string): string | undefined {
    if (!closeReportUrl) {
        return undefined;
    }

    return closeReportUrl.replace(/\/payment\/close-report$/, '/payment/retry');
}

/**
 * 결제 컨텍스트를 서버에 보냅니다 (결제창 닫힘 보고·재시도 준비).
 *
 * 두 엔드포인트 모두 주문 상태를 바꾸므로 서버는 주문 소유자(회원 로그인 / 비회원 조회 토큰)만
 * 받아 준다. 회원 Bearer 와 401 처리는 `G7Core.api` 가 맡고, 비회원은 공개 상태의 조회 토큰을 싣는다.
 * 실패하면 예외를 그대로 던진다 — 재시도 준비가 실패하면 결제창을 열지 않아야 한다.
 *
 * @param url 서버가 내려준 주소
 * @param payload 요청 본문
 * @return 요청이 끝나면 이행되는 Promise
 * @throws Error 코어 API 가 없거나 주소를 해석할 수 없는 경우, 서버가 거절한 경우
 */
async function postPaymentContext(url: string, payload: Record<string, unknown>): Promise<void> {
    const api = coreApi();
    if (!api) {
        throw new Error('[sirsoft-pay_nhnkcp] G7Core.api is not available');
    }

    const path = toApiPath(url);
    if (!path) {
        throw new Error('[sirsoft-pay_nhnkcp] invalid payment report url');
    }

    const headers = guestOwnershipHeaders();
    if (Object.keys(headers).length > 0) {
        await api.post(path, payload, { headers });
    } else {
        await api.post(path, payload);
    }
}

export async function preparePaymentRetry(context: PaymentCloseReportContext): Promise<void> {
    const retryUrl = resolveRetryUrl(context.closeReportUrl);
    if (!retryUrl) {
        return;
    }

    await postPaymentContext(retryUrl, {
        oid: context.oid,
        price: Number(context.price),
        payment_method: context.payment_method ?? '',
    });
}

/**
 * 결제 실패 화면으로 돌아왔을 때 보고에 쓸 주문 정보를 남겨 두는 저장소 키.
 *
 * 결제창은 전체 페이지 이동으로 열리고 돌아오므로 JS 컨텍스트가 소실된다. sessionStorage 는
 * 같은 탭에서 외부 도메인을 다녀와도 유지되므로, 결제 요청 직전에 저장해 두었다가 꺼내 쓴다.
 */
const PENDING_CLOSE_STORAGE_KEY = 'g7:sirsoft-pay_nhnkcp:pendingClose';

/**
 * sessionStorage 접근은 브라우저 설정(사이트 데이터 차단·시크릿 모드)에 따라 예외를 던진다.
 * 보고는 편의 장치이므로 실패해도 결제 흐름을 막지 않는다.
 */
function safeSessionStorage(): Storage | null {
    try {
        return window.sessionStorage ?? null;
    } catch {
        return null;
    }
}

/**
 * 결제창을 열기 직전에 보고용 컨텍스트를 저장합니다.
 *
 * @param context 결제창 닫힘 보고에 필요한 주문 정보
 */
export function rememberPendingClose(context: PaymentCloseReportContext): void {
    const storage = safeSessionStorage();
    if (!storage) {
        return;
    }

    try {
        storage.setItem(PENDING_CLOSE_STORAGE_KEY, JSON.stringify(context));
    } catch {
        // 저장 실패는 무시 — 만료 자동 정리가 최종 안전망이다.
    }
}

/**
 * 저장해 둔 보고용 컨텍스트를 지웁니다.
 */
export function forgetPendingClose(): void {
    const storage = safeSessionStorage();
    if (!storage) {
        return;
    }

    try {
        storage.removeItem(PENDING_CLOSE_STORAGE_KEY);
    } catch {
        // 무시
    }
}

/**
 * 저장해 둔 보고용 컨텍스트를 읽습니다.
 *
 * @returns 저장된 컨텍스트, 없거나 형식이 깨졌으면 null
 */
function readPendingClose(): PaymentCloseReportContext | null {
    const storage = safeSessionStorage();
    if (!storage) {
        return null;
    }

    try {
        const raw = storage.getItem(PENDING_CLOSE_STORAGE_KEY);
        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw) as PaymentCloseReportContext;

        return parsed && typeof parsed.oid === 'string' && parsed.oid !== '' ? parsed : null;
    } catch {
        return null;
    }
}

/**
 * 결제 실패 화면으로 돌아왔으면 저장해 둔 정보로 서버에 보고합니다.
 *
 * 브라우저 리턴 콜백(`authCallback`)은 PG 서명도 IP 증명도 없어 주문 상태를 바꾸지 않는다.
 * 소유권을 대조하는 close-report 만이 정당한 결제 실패를 기록할 수 있으므로, 실패 화면에
 * 도착한 이 시점에 그 경로로 보고한다. 플러그인 부팅 후 코어가 준비되면 1회 호출한다.
 */
export async function reportPaymentFailureOnReturn(): Promise<void> {
    const pending = readPendingClose();
    if (!pending) {
        return;
    }

    let params: URLSearchParams;
    try {
        params = new URLSearchParams(window.location.search);
    } catch {
        return;
    }

    const orderIdInUrl = params.get('orderId') ?? '';

    // 저장분과 화면의 주문번호가 다르면 이번 이동과 무관한 잔여물이다.
    if (orderIdInUrl !== '' && orderIdInUrl !== pending.oid) {
        return;
    }

    // 결제 완료 화면으로 돌아왔으면 보고 대상이 아니다 — 성공 확정은 서버가 이미 했다.
    if (/\/(complete|success)(\/|$|\?)/.test(window.location.pathname)) {
        forgetPendingClose();

        return;
    }

    const code = params.get('error') ?? '';
    const message = params.get('message') ?? '';

    // 실패 표시가 전혀 없으면 결제창을 열기만 하고 돌아온 경우일 수 있다 — 판단하지 않는다.
    if (code === '' && orderIdInUrl === '') {
        return;
    }

    // 중복 보고를 막기 위해 요청 전에 먼저 지운다.
    forgetPendingClose();

    // 비회원이면 템플릿이 조회 토큰을 전역 상태에 되살린 뒤에 보낸다 — 토큰 없이 보내면 서버가 거부한다.
    await waitForGuestOrderToken();

    await reportPaymentWindowClosed(pending, message !== '' ? message : code);
}

export async function reportPaymentWindowClosed(
    context: PaymentCloseReportContext,
    reason = 'kcp-window-closed',
): Promise<void> {
    if (!context.closeReportUrl) {
        return;
    }

    const payload = {
        oid: context.oid,
        price: Number(context.price),
        payment_method: context.payment_method ?? '',
        reason: trimReason(reason) || 'kcp-window-closed',
    };

    try {
        await postPaymentContext(context.closeReportUrl, payload);
    } catch (error) {
        console.warn('[sirsoft-pay_nhnkcp] failed to report payment window close', error);
    }
}
