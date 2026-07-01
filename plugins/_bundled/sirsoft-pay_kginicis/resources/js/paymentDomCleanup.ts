export const KOREAN_PAYMENT_FORM_ID_PREFIX = 'kginicis_pay_form_';
const RELOAD_STANDARD_PAY_SDK_KEY = '__sirsoftKginicisReloadStandardPaySdk';
const MOBILE_PAYMENT_RETURN_PENDING_KEY = '__sirsoftKginicisMobilePaymentReturnPending';
const MOBILE_PAYMENT_RETURN_TTL_MS = 30 * 60 * 1000;

export function removeKoreanPaymentForms(): number {
    if (typeof document === 'undefined') {
        return 0;
    }

    const forms = document.querySelectorAll<HTMLFormElement>(
        `form[id^="${KOREAN_PAYMENT_FORM_ID_PREFIX}"]`,
    );

    forms.forEach((form) => form.remove());

    return forms.length;
}

export function markStandardPaySdkForReload(): void {
    if (typeof window === 'undefined') {
        return;
    }

    (window as unknown as Record<string, unknown>)[RELOAD_STANDARD_PAY_SDK_KEY] = true;
}

export function consumeStandardPaySdkReloadFlag(): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    const w = window as unknown as Record<string, unknown>;
    const shouldReload = w[RELOAD_STANDARD_PAY_SDK_KEY] === true;
    delete w[RELOAD_STANDARD_PAY_SDK_KEY];

    return shouldReload;
}

export function resetStandardPaySdk(sdkUrl: string): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    (window as any).INIStdPay = undefined;

    document
        .querySelectorAll<HTMLScriptElement>(
            `script[src="${sdkUrl}"], script[src*="/INIStdPay_third-party.js"]`,
        )
        .forEach((script) => script.remove());
}

export function markMobilePaymentReturnPending(now = Date.now()): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.setItem(MOBILE_PAYMENT_RETURN_PENDING_KEY, String(now));
    } catch {
        (window as unknown as Record<string, unknown>)[MOBILE_PAYMENT_RETURN_PENDING_KEY] = now;
    }
}

export function clearMobilePaymentReturnPending(): void {
    if (typeof window === 'undefined') {
        return;
    }

    try {
        window.sessionStorage.removeItem(MOBILE_PAYMENT_RETURN_PENDING_KEY);
    } catch {
        // sessionStorage 접근이 막힌 환경에서는 window fallback 만 정리한다.
    }

    delete (window as unknown as Record<string, unknown>)[MOBILE_PAYMENT_RETURN_PENDING_KEY];
}

export function hasMobilePaymentReturnPending(now = Date.now()): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    let markedAt: number | null = null;

    try {
        const stored = window.sessionStorage.getItem(MOBILE_PAYMENT_RETURN_PENDING_KEY);
        markedAt = stored ? Number(stored) : null;
    } catch {
        markedAt = null;
    }

    if (!Number.isFinite(markedAt)) {
        const fallback = (window as unknown as Record<string, unknown>)[MOBILE_PAYMENT_RETURN_PENDING_KEY];
        markedAt = typeof fallback === 'number' ? fallback : Number(fallback);
    }

    if (!Number.isFinite(markedAt)) {
        clearMobilePaymentReturnPending();
        return false;
    }

    if (now - markedAt > MOBILE_PAYMENT_RETURN_TTL_MS) {
        clearMobilePaymentReturnPending();
        return false;
    }

    return true;
}

export function consumeMobilePaymentReturnPending(now = Date.now()): boolean {
    if (!hasMobilePaymentReturnPending(now)) {
        return false;
    }

    clearMobilePaymentReturnPending();

    return true;
}
