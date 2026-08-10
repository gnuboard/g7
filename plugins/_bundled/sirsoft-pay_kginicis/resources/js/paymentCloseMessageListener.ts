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
}

export function clearStandardPaymentCloseReportContext(): void {
    delete windowRecord()[ACTIVE_STANDARD_PAYMENT_CLOSE_CONTEXT_KEY];
}

export function markStandardPaymentCompletionStarted(): void {
    clearStandardPaymentCloseReportContext();
}

export async function reportStandardPaymentWindowClosed(
    reason = 'payment-window-closed',
): Promise<void> {
    const context = getActiveStandardPaymentCloseContext();
    if (!context || context.reported || !isCheckoutPage()) {
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
