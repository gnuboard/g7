import {
    clearMobilePaymentReturnPending,
    hasMobilePaymentReturnPending,
    markStandardPaySdkForReload,
    removeKoreanPaymentForms,
} from './paymentDomCleanup';
import { buildGuestOwnershipHeaders } from './guestOrderToken';
import { toApiClientPath } from './apiPath';

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
    payment_method?: string;
    reported?: boolean;
    /**
     * 클레임 추가결제일 때만 — 결제창을 닫으면 돌아갈 신청서 상세(`?claim_payment=cancelled`).
     * 서버가 `pg_payment_data.claim_cancel_return_url` 로 준 값이다. 있으면 `oid` 는 주문번호가
     * 아니라 결제번호(CP…)라 주문 전용 닫힘 보고를 보내지 않는다.
     */
    claimCancelReturnUrl?: string;
}

/**
 * 주소가 현재 문서와 같은 출처인지 판정합니다.
 *
 * 서버가 준 돌아갈 주소라도 이 사이트 밖으로는 보내지 않기 위한 확인이다(열린 이동 방지).
 *
 * @param url 주소 (절대·상대 모두)
 * @returns 같은 출처면 true
 */
function isSameOriginUrl(url: string): boolean {
    try {
        return new URL(url, window.location.origin).origin === window.location.origin;
    } catch {
        return false;
    }
}

/**
 * 활성 결제가 클레임 추가결제면 신청서 상세로 돌아갑니다.
 *
 * 신청서 화면의 진행 표시는 결제창이 열린 동안 일부러 끄지 않는다 — 돌아온 화면이 신청서를
 * 다시 읽어 상태를 맞춘다. 그래서 닫힘을 받고도 머물면 「결제 진행 중…」에 묶인다.
 *
 * @returns 이동했으면 true
 */
function returnToClaimDetailIfClaimPayment(): boolean {
    const url = getActiveStandardPaymentCloseContext()?.claimCancelReturnUrl;
    // 서버가 준 주소라도 이 사이트 밖으로는 보내지 않는다(같은 출처만 — 열린 이동 방지).
    if (typeof url !== 'string' || url === '' || !isSameOriginUrl(url)) {
        return false;
    }

    clearStandardPaymentCloseReportContext();
    removeKoreanPaymentForms();
    markStandardPaySdkForReload();
    window.location.href = url;

    return true;
}

function isPaymentCloseMessage(data: unknown): data is PaymentCloseMessage {
    if (!data || typeof data !== 'object') {
        return false;
    }

    const message = data as PaymentCloseMessage;
    return message.source === CLOSE_MESSAGE_SOURCE && message.type === CLOSE_MESSAGE_TYPE;
}

/**
 * 쇼핑몰 경로 앞머리를 템플릿 상태 계약(`_global.shopBase`)에서 읽습니다.
 *
 * 쇼핑몰 경로는 운영자가 바꿀 수 있어(`/shop`·`/store`·루트) 경로를 글자로 박으면 다른 설치본에서
 * 체크아웃을 알아보지 못한다. 엔진이 아직 상태를 채우기 전이면 null 이다.
 *
 * @returns 경로 앞머리(루트면 빈 문자열) 또는 null
 */
function readShopBase(): string | null {
    try {
        const shopBase = (window as any).G7Core?.state?.get?.()?.shopBase;

        return typeof shopBase === 'string' ? shopBase.replace(/\/+$/, '') : null;
    } catch {
        return null;
    }
}

function isCheckoutPage(): boolean {
    const shopBase = readShopBase();
    if (shopBase === null) {
        return false;
    }

    return window.location.pathname.replace(/\/+$/, '') === `${shopBase}/checkout`;
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
        payment_method: context.payment_method ?? '',
        reason,
    };

    // 서버는 주문 소유자(회원 로그인 / 비회원 조회 토큰)만 받아 준다. 회원 Bearer 는 G7Core.api 가
    // 싣고, 비회원은 체크아웃이 저장해 둔 조회 토큰을 헤더로 싣는다.
    const ownershipHeaders = buildGuestOwnershipHeaders();

    try {
        const apiClient = (window as any).G7Core?.api;
        if (typeof apiClient?.post !== 'function') {
            logger.warn('G7Core.api not available while reporting KG payment window close', { reason });

            return;
        }

        if (Object.keys(ownershipHeaders).length > 0) {
            await apiClient.post(toApiClientPath(context.closeReportUrl), payload, { headers: ownershipHeaders });
        } else {
            await apiClient.post(toApiClientPath(context.closeReportUrl), payload);
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

        // 쇼핑몰 경로(`_global.shopBase`)가 아직 채워지지 않았으면 체크아웃 여부를 모르므로 한도까지 다시 본다.
        if ((!isCheckoutPage() && readShopBase() !== null) || attempts >= RESET_RETRY_LIMIT) {
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

        if (returnToClaimDetailIfClaimPayment()) {
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
