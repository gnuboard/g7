import {
    clearMobilePaymentReturnPending,
    hasMobilePaymentReturnPending,
    markStandardPaySdkForReload,
    removeKoreanPaymentForms,
} from './paymentDomCleanup';

const PLUGIN_IDENTIFIER = 'sirsoft-pay_kginicis';
const CLOSE_MESSAGE_SOURCE = PLUGIN_IDENTIFIER;
const CLOSE_MESSAGE_TYPE = 'payment-window-closed';
const LISTENER_INSTALLED_KEY = '__sirsoftKginicisPaymentCloseListenerInstalled';
const ACTIVE_STANDARD_PAYMENT_CLOSE_CONTEXT_KEY = '__sirsoftKginicisActiveStandardPaymentCloseContext';
const RESET_RETRY_LIMIT = 20;
const RESET_RETRY_INTERVAL_MS = 100;

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[${PLUGIN_IDENTIFIER}]`, ...args),
};

interface PaymentCloseMessage {
    source?: string;
    type?: string;
    reason?: string;
}

export interface StandardPaymentCloseReportContext {
    closeReportUrl: string;
    oid: string;
    price: number;
    buyer_email?: string;
    buyer_phone?: string;
    payment_method?: string;
    reported?: boolean;
}

function isPaymentCloseMessage(data: unknown): data is PaymentCloseMessage {
    if (!data || typeof data !== 'object') {
        return false;
    }

    const message = data as PaymentCloseMessage;
    return message.source === CLOSE_MESSAGE_SOURCE && message.type === CLOSE_MESSAGE_TYPE;
}

function isCheckoutPage(): boolean {
    return /\/shop\/checkout\/?$/.test(window.location.pathname);
}

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

function getActiveStandardPaymentCloseContext(): StandardPaymentCloseReportContext | null {
    const context = windowRecord()[ACTIVE_STANDARD_PAYMENT_CLOSE_CONTEXT_KEY];
    if (!context || typeof context !== 'object') {
        return null;
    }

    return context as StandardPaymentCloseReportContext;
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

/**
 * 결제 실패 화면으로 돌아왔을 때 보고에 쓸 컨텍스트를 남겨 두는 저장소 키.
 *
 * window 전역만 쓰면 결제창이 전체 페이지 이동으로 열리고 돌아올 때 컨텍스트가 소실된다.
 * sessionStorage 는 같은 탭에서 외부 도메인을 다녀와도 유지되므로 함께 저장한다.
 */
const PENDING_CLOSE_STORAGE_KEY = 'g7:sirsoft-pay_kginicis:pendingClose';

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

export function markStandardPaymentCloseReportContext(
    context: StandardPaymentCloseReportContext,
): void {
    if (!context.closeReportUrl) {
        return;
    }

    windowRecord()[ACTIVE_STANDARD_PAYMENT_CLOSE_CONTEXT_KEY] = {
        ...context,
        reported: false,
    };

    // 전체 페이지 이동(모바일·PC 리턴 콜백)을 건너 살아남도록 함께 보관한다.
    try {
        safeSessionStorage()?.setItem(
            PENDING_CLOSE_STORAGE_KEY,
            JSON.stringify({ ...context, reported: false }),
        );
    } catch {
        // 저장 실패는 무시 — 만료 자동 정리가 최종 안전망이다.
    }
}

export function clearStandardPaymentCloseReportContext(): void {
    delete windowRecord()[ACTIVE_STANDARD_PAYMENT_CLOSE_CONTEXT_KEY];

    try {
        safeSessionStorage()?.removeItem(PENDING_CLOSE_STORAGE_KEY);
    } catch {
        // 무시
    }
}

/**
 * 저장해 둔 보고용 컨텍스트를 읽습니다.
 *
 * @returns 저장된 컨텍스트, 없거나 형식이 깨졌으면 null
 */
function readPendingCloseFromStorage(): StandardPaymentCloseReportContext | null {
    try {
        const raw = safeSessionStorage()?.getItem(PENDING_CLOSE_STORAGE_KEY);
        if (!raw) {
            return null;
        }

        const parsed = JSON.parse(raw) as StandardPaymentCloseReportContext;

        return parsed && typeof parsed.oid === 'string' && parsed.oid !== '' && parsed.closeReportUrl
            ? parsed
            : null;
    } catch {
        return null;
    }
}

/**
 * 결제 실패 화면으로 돌아왔으면 저장해 둔 정보로 서버에 보고합니다.
 *
 * 브라우저 리턴 콜백(PC·모바일·해외결제)은 PG 서명도 IP 증명도 없어 주문 상태를 바꾸지 않는다.
 * 소유권을 대조하는 close-report 만이 정당한 결제 실패를 기록할 수 있으므로, 실패 화면에
 * 도착한 이 시점에 그 경로로 보고한다. 플러그인 부팅 시 1회 호출한다.
 */
export async function reportStandardPaymentFailureOnReturn(): Promise<void> {
    const pending = readPendingCloseFromStorage();
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
        clearStandardPaymentCloseReportContext();

        return;
    }

    const code = params.get('error') ?? '';
    const message = params.get('message') ?? '';

    // 실패 표시가 전혀 없으면 결제창을 열기만 하고 돌아온 경우일 수 있다 — 판단하지 않는다.
    if (code === '' && orderIdInUrl === '') {
        return;
    }

    // window 전역 컨텍스트를 복원해 기존 보고 경로를 그대로 태운다 (중복 보고 가드 포함).
    windowRecord()[ACTIVE_STANDARD_PAYMENT_CLOSE_CONTEXT_KEY] = { ...pending, reported: false };

    await reportStandardPaymentWindowClosed(
        message !== '' ? message : code || 'payment-window-closed',
        false,
    );
}

export function markStandardPaymentCompletionStarted(): void {
    clearStandardPaymentCloseReportContext();
}

export async function reportStandardPaymentWindowClosed(
    reason = 'payment-window-closed',
    requireCheckoutPage = true,
): Promise<void> {
    // 결제창 닫힘 메시지 경로는 체크아웃 화면에서만 유효하다. 반면 리턴 콜백에서 돌아온 경우는
    // 상점이 실패 주소를 바꿨을 수 있어 화면 경로로 판정할 수 없다 — 그때는 이 검사를 건너뛴다.
    const context = getActiveStandardPaymentCloseContext();
    if (!context || context.reported || (requireCheckoutPage && !isCheckoutPage())) {
        return;
    }

    context.reported = true;

    const payload = {
        oid: context.oid,
        price: context.price,
        buyer_email: context.buyer_email ?? '',
        buyer_phone: context.buyer_phone ?? '',
        payment_method: context.payment_method ?? '',
        reason,
    };

    try {
        const apiClient = ((window as any).G7Core)?.api;
        if (typeof apiClient?.post === 'function') {
            await apiClient.post(context.closeReportUrl, payload);
        } else {
            await fetch(resolveApiUrl(context.closeReportUrl), {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify(payload),
                keepalive: true,
            });
        }
    } catch (error) {
        logger.warn('failed to report KG payment window close', { reason, error });
    } finally {
        clearStandardPaymentCloseReportContext();
    }
}

export function resetCheckoutSubmittingState(
    reason = 'payment-window-closed',
    warnOnMissingCore = true,
): boolean {
    if (!isCheckoutPage()) {
        return false;
    }

    const g7Core = (window as any).G7Core;
    const setLocal = g7Core?.state?.setLocal;

    if (typeof setLocal !== 'function') {
        if (warnOnMissingCore) {
            logger.warn('G7Core.state.setLocal not available while resetting payment submit state');
        }
        return false;
    }

    removeKoreanPaymentForms();
    markStandardPaySdkForReload();
    setLocal({ isSubmittingOrder: false });
    logger.info('checkout submit state reset after KG payment close', { reason });
    return true;
}

function scheduleCheckoutSubmittingStateReset(reason: string, clearPendingOnSuccess = false): void {
    let attempts = 0;

    const tryReset = (): void => {
        attempts++;

        if (clearPendingOnSuccess && !hasMobilePaymentReturnPending()) {
            return;
        }

        if (resetCheckoutSubmittingState(reason, attempts >= RESET_RETRY_LIMIT)) {
            if (clearPendingOnSuccess) {
                clearMobilePaymentReturnPending();
            }
            return;
        }

        if (!isCheckoutPage() || attempts >= RESET_RETRY_LIMIT) {
            return;
        }

        window.setTimeout(tryReset, RESET_RETRY_INTERVAL_MS);
    };

    tryReset();
}

function resetAfterMobilePaymentReturn(reason: string): void {
    if (!hasMobilePaymentReturnPending()) {
        return;
    }

    scheduleCheckoutSubmittingStateReset(reason, true);
}

export function installPaymentCloseMessageListener(): void {
    if (typeof window === 'undefined') {
        return;
    }

    const w = windowRecord();
    if (w[LISTENER_INSTALLED_KEY]) {
        return;
    }

    window.addEventListener('message', (event: MessageEvent) => {
        if (event.origin !== window.location.origin) {
            return;
        }

        if (!isPaymentCloseMessage(event.data)) {
            return;
        }

        void reportStandardPaymentWindowClosed(event.data.reason);
        resetCheckoutSubmittingState(event.data.reason);
    });

    window.addEventListener('pagehide', () => {
        markStandardPaymentCompletionStarted();
    });

    window.addEventListener('beforeunload', () => {
        markStandardPaymentCompletionStarted();
    });

    window.addEventListener('pageshow', (event: PageTransitionEvent) => {
        resetAfterMobilePaymentReturn(event.persisted
            ? 'mobile-payment-bfcache-return'
            : 'mobile-payment-page-show');
    });

    window.addEventListener('focus', () => {
        resetAfterMobilePaymentReturn('mobile-payment-window-focus');
    });

    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState !== 'visible') {
            return;
        }

        resetAfterMobilePaymentReturn('mobile-payment-visibility-return');
    });

    w[LISTENER_INSTALLED_KEY] = true;
    resetAfterMobilePaymentReturn('mobile-payment-listener-installed');
}
