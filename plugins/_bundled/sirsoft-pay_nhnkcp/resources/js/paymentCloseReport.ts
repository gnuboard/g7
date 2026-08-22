export interface PaymentCloseReportContext {
    closeReportUrl?: string;
    oid: string;
    price: number;
    buyer_email?: string;
    buyer_phone?: string;
    payment_method?: string;
}

function resolveApiUrl(url: string): string {
    if (/^https?:\/\//i.test(url) || url.startsWith('/api/')) {
        return url;
    }

    if (url.startsWith('/plugins/')) {
        return `/api${url}`;
    }

    if (url.startsWith('plugins/')) {
        return `/api/${url}`;
    }

    return url;
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

async function postPaymentContext(url: string, payload: Record<string, unknown>, keepalive = false): Promise<void> {
    const apiClient = ((window as any).G7Core)?.api;
    if (typeof apiClient?.post === 'function') {
        await apiClient.post(url, payload);
        return;
    }

    const response = await fetch(resolveApiUrl(url), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        keepalive,
    });

    if (!response.ok) {
        let message = `NHN KCP payment request failed (${response.status})`;
        try {
            const data = await response.json();
            const responseMessage = Array.isArray(data?.errors?.message)
                ? data.errors.message[0]
                : data?.error ?? data?.message;
            if (typeof responseMessage === 'string' && responseMessage.trim() !== '') {
                message = responseMessage;
            }
        } catch {
            // JSON 응답이 아니면 HTTP 상태 메시지를 사용한다.
        }
        throw new Error(message);
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
        buyer_email: context.buyer_email ?? '',
        buyer_phone: context.buyer_phone ?? '',
        payment_method: context.payment_method ?? '',
    });
}

/**
 * 결제 실패 화면으로 돌아왔을 때 보고에 쓸 구매자 정보를 남겨 두는 저장소 키.
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
 * @param context 결제창 닫힘 보고에 필요한 주문·구매자 정보
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
 * 도착한 이 시점에 그 경로로 보고한다. 플러그인 부팅 시 1회 호출한다.
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
        buyer_email: context.buyer_email ?? '',
        buyer_phone: context.buyer_phone ?? '',
        payment_method: context.payment_method ?? '',
        reason: trimReason(reason) || 'kcp-window-closed',
    };

    try {
        await postPaymentContext(context.closeReportUrl, payload, true);
    } catch (error) {
        console.warn('[sirsoft-pay_nhnkcp] failed to report payment window close', error);
    }
}
