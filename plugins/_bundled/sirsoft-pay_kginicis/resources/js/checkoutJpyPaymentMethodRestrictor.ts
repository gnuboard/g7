const PLUGIN_ID = 'sirsoft-pay_kginicis';
const FLAG = '__sirsoftKginicisCheckoutJpyPaymentMethodRestrictorInstalled';
const CHECKOUT_RE = /^\/shop\/checkout\/?$/;
const CHECKOUT_PATH = '/api/modules/sirsoft-ecommerce/checkout';
const PAYMENT_SETTINGS_PATH = '/api/modules/sirsoft-ecommerce/settings/payment';
const CLIENT_CONFIG_PATH = '/api/modules/sirsoft-ecommerce/payments/client-config/kginicis';
const CHECKOUT_CURRENCY_KEY = '__sirsoftKginicisCheckoutCurrency';

const ALLOWED_JPY_PAYMENT_METHODS = new Set(['card', 'kginicis_japan_paypay', 'kginicis_japan_cvs']);
const JAPAN_ONLY_PAYMENT_METHODS = new Set(['kginicis_japan_paypay', 'kginicis_japan_cvs']);
const KNOWN_PAYMENT_METHODS = new Set([
    'card',
    'vbank',
    'dbank',
    'bank',
    'phone',
    'kginicis_samsung_pay',
    'kginicis_naverpay',
    'kginicis_lpay',
    'kginicis_kakaopay',
    'kginicis_japan_paypay',
    'kginicis_japan_cvs',
]);

const DOM_METHOD_LABELS: Array<{ id: string; labels: string[] }> = [
    { id: 'card', labels: ['신용카드', 'Credit Card', 'クレジットカード'] },
    { id: 'vbank', labels: ['가상계좌', 'Virtual Account', 'バーチャル口座'] },
    { id: 'dbank', labels: ['무통장입금', 'Bank Transfer', '銀行振込'] },
    { id: 'bank', labels: ['계좌이체', 'Account Transfer', '口座振替'] },
    { id: 'phone', labels: ['휴대폰결제', 'Mobile Payment', '携帯電話決済'] },
    { id: 'kginicis_naverpay', labels: ['네이버페이 (KG이니시스)', 'Naver Pay (KG Inicis)'] },
    { id: 'kginicis_samsung_pay', labels: ['삼성페이 (KG이니시스)', 'Samsung Pay (KG Inicis)'] },
    { id: 'kginicis_lpay', labels: ['L.pay (KG이니시스)', 'L.pay (KG Inicis)'] },
    { id: 'kginicis_kakaopay', labels: ['카카오페이 (KG이니시스)', 'Kakao Pay (KG Inicis)'] },
    { id: 'kginicis_japan_paypay', labels: ['PayPay (일본 KG이니시스)', 'PayPay (KG Inicis Japan)'] },
    { id: 'kginicis_japan_cvs', labels: ['일본 편의점결제 (KG이니시스)', 'Japan Convenience Store (KG Inicis)'] },
];

interface PaymentMethodSetting {
    id?: string;
    is_active?: boolean;
    [key: string]: unknown;
}

interface PaymentSettingsBody {
    data?: {
        order_settings?: {
            payment_methods?: PaymentMethodSetting[];
            [key: string]: unknown;
        };
        [key: string]: unknown;
    };
    [key: string]: unknown;
}

interface ClientConfigBody {
    data?: {
        japan_restrict_jpy_payment_methods?: boolean;
    };
}

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_ID}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[${PLUGIN_ID}]`, ...args),
};

let cachedRestrictEnabled: Promise<boolean> | null = null;
let observer: MutationObserver | null = null;
let pollTimer: ReturnType<typeof setInterval> | null = null;

export function resetCheckoutJpyPaymentMethodRestrictorForTests(): void {
    cachedRestrictEnabled = null;
    observer?.disconnect();
    observer = null;

    if (pollTimer !== null) {
        clearInterval(pollTimer);
        pollTimer = null;
    }

    if (typeof window !== 'undefined') {
        delete windowRecord()[FLAG];
        delete windowRecord()[CHECKOUT_CURRENCY_KEY];
    }
}

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

function extractUrl(input: RequestInfo | URL): string {
    if (typeof input === 'string') return input;
    if (input instanceof URL) return input.toString();
    if (typeof Request !== 'undefined' && input instanceof Request) return input.url;
    return String(input);
}

function extractMethod(input: RequestInfo | URL, init?: RequestInit): string {
    if (init?.method) return init.method.toUpperCase();
    if (typeof Request !== 'undefined' && input instanceof Request) return input.method.toUpperCase();
    return 'GET';
}

function pathnameOf(url: string): string {
    try {
        return new URL(url, window.location.origin).pathname;
    } catch {
        return url.split('?')[0].split('#')[0];
    }
}

function normalizeCurrency(value: unknown): string | null {
    if (typeof value !== 'string') return null;
    const currency = value.trim().toUpperCase();
    return /^[A-Z]{3}$/.test(currency) ? currency : null;
}

function getByPath(source: unknown, path: string): unknown {
    return path.split('.').reduce<unknown>((current, key) => {
        if (current === null || typeof current !== 'object') return undefined;
        return (current as Record<string, unknown>)[key];
    }, source);
}

function firstCurrencyFrom(source: unknown, paths: string[]): string | null {
    for (const path of paths) {
        const currency = normalizeCurrency(getByPath(source, path));
        if (currency) return currency;
    }

    return null;
}

function checkoutCurrencyFromBody(body: unknown): string | null {
    return firstCurrencyFrom(body, [
        'data.calculation.summary.selected_payment_currency',
        'data.calculation.metadata.payment_currency',
        'data.calculation.metadata.currency',
        'data.currency',
        'data.currency_code',
    ]);
}

function rememberCheckoutCurrency(body: unknown): void {
    const currency = checkoutCurrencyFromBody(body);
    if (!currency) return;

    windowRecord()[CHECKOUT_CURRENCY_KEY] = currency;
}

export function resolveCheckoutCurrency(): string | null {
    const w = windowRecord();
    const templateApp = w['__templateApp'] as { globalState?: Record<string, unknown> } | undefined;
    const globalState = templateApp?.globalState;

    const candidates: unknown[] = [
        w[CHECKOUT_CURRENCY_KEY],
        globalState?.preferredCurrency,
    ];

    try {
        candidates.push(window.localStorage?.getItem('g7_preferred_currency'));
    } catch {
        // localStorage 접근이 막힌 환경에서는 전역 상태/default 설정만 사용한다.
    }

    candidates.push(
        getByPath(globalState, "modules.sirsoft-ecommerce.language_currency.default_currency"),
        getByPath(globalState, "modules.sirsoft-ecommerce.language_currency.currencies.0.code"),
    );

    for (const candidate of candidates) {
        const currency = normalizeCurrency(candidate);
        if (currency) return currency;
    }

    return null;
}

function shouldUseJpyRestriction(): boolean {
    return CHECKOUT_RE.test(window.location.pathname) && resolveCheckoutCurrency() === 'JPY';
}

function shouldHideJapanMethods(): boolean {
    if (!CHECKOUT_RE.test(window.location.pathname)) return false;
    const currency = resolveCheckoutCurrency();
    return currency !== null && currency !== 'JPY';
}

async function fetchRestrictEnabled(fetchImpl: typeof fetch): Promise<boolean> {
    if (cachedRestrictEnabled !== null) return cachedRestrictEnabled;

    cachedRestrictEnabled = (async () => {
        try {
            const response = await fetchImpl(CLIENT_CONFIG_PATH, {
                headers: { Accept: 'application/json' },
            });
            if (!response.ok) return false;

            const body = (await response.json()) as ClientConfigBody;
            return body.data?.japan_restrict_jpy_payment_methods === true;
        } catch {
            return false;
        }
    })();

    return cachedRestrictEnabled;
}

export function restrictPaymentSettingsForJpy(body: PaymentSettingsBody): PaymentSettingsBody {
    const methods = body.data?.order_settings?.payment_methods;
    if (!Array.isArray(methods)) return body;

    return {
        ...body,
        data: {
            ...body.data,
            order_settings: {
                ...body.data?.order_settings,
                payment_methods: methods.map((method) => {
                    const id = typeof method.id === 'string' ? method.id : '';
                    if (!KNOWN_PAYMENT_METHODS.has(id) || ALLOWED_JPY_PAYMENT_METHODS.has(id)) {
                        return method;
                    }

                    return {
                        ...method,
                        is_active: false,
                        _kginicis_restricted_for_jpy: true,
                    };
                }),
            },
        },
    };
}

export function hideJapanPaymentMethodsForNonJpy(body: PaymentSettingsBody): PaymentSettingsBody {
    const methods = body.data?.order_settings?.payment_methods;
    if (!Array.isArray(methods)) return body;

    return {
        ...body,
        data: {
            ...body.data,
            order_settings: {
                ...body.data?.order_settings,
                payment_methods: methods.map((method) => {
                    const id = typeof method.id === 'string' ? method.id : '';
                    if (!JAPAN_ONLY_PAYMENT_METHODS.has(id)) {
                        return method;
                    }

                    return {
                        ...method,
                        is_active: false,
                        _kginicis_restricted_for_non_jpy: true,
                    };
                }),
            },
        },
    };
}

function mutateResponse(originalResponse: Response, body: PaymentSettingsBody): Response {
    const headers = new Headers(originalResponse.headers);
    headers.set('Content-Type', 'application/json');

    return new Response(JSON.stringify(body), {
        status: originalResponse.status,
        statusText: originalResponse.statusText,
        headers,
    });
}

function findPaymentContainer(): Element | null {
    const h2 = Array.from(document.querySelectorAll<HTMLElement>('h2')).find((el) => {
        const text = el.textContent ?? '';
        return text.includes('결제 수단') || text.includes('결제 방법') || text.includes('Payment Method');
    });
    if (!h2) return null;

    let el: Element | null = h2.parentElement;
    while (el && el !== document.body) {
        if (el.tagName === 'DIV' && String((el as HTMLElement).className).includes('rounded-lg')) {
            return el;
        }
        el = el.parentElement;
    }

    return null;
}

function inferButtonPaymentMethod(button: HTMLElement): string | null {
    const title = button.querySelector('p')?.textContent?.trim() ?? button.textContent?.trim() ?? '';
    for (const method of DOM_METHOD_LABELS) {
        if (method.labels.some((label) => title.includes(label))) {
            return method.id;
        }
    }

    return null;
}

function patchRenderedPaymentButtons(): boolean {
    const isJpy = shouldUseJpyRestriction();
    const isHideJapan = shouldHideJapanMethods();
    if (!isJpy && !isHideJapan) return false;

    const container = findPaymentContainer();
    if (!container) return false;

    let patched = false;
    container.querySelectorAll<HTMLButtonElement>('button[type="button"]').forEach((button) => {
        const methodId = inferButtonPaymentMethod(button);
        if (!methodId || !KNOWN_PAYMENT_METHODS.has(methodId)) return;

        const allowed = isJpy
            ? ALLOWED_JPY_PAYMENT_METHODS.has(methodId)
            : !JAPAN_ONLY_PAYMENT_METHODS.has(methodId);

        if (allowed) {
            button.style.removeProperty('display');
            button.disabled = false;
            button.removeAttribute('aria-hidden');
            return;
        }

        button.style.display = 'none';
        button.disabled = true;
        button.setAttribute('aria-hidden', 'true');
        if (isJpy) {
            button.dataset.kginicisRestrictedForJpy = 'true';
        } else {
            button.dataset.kginicisRestrictedForNonJpy = 'true';
        }
        patched = true;
    });

    if (patched) {
        try {
            // eslint-disable-next-line @typescript-eslint/no-explicit-any
            const local = ((window as any).G7Core)?.state?.getLocal?.();
            const current = local?.paymentMethod;
            const needsReset = typeof current === 'string' && (
                (isJpy && !ALLOWED_JPY_PAYMENT_METHODS.has(current)) ||
                (isHideJapan && JAPAN_ONLY_PAYMENT_METHODS.has(current))
            );
            if (needsReset) {
                // eslint-disable-next-line @typescript-eslint/no-explicit-any
                ((window as any).G7Core)?.state?.setLocal?.({ paymentMethod: 'card' });
            }
        } catch {
            // 상태 보정 실패는 화면 필터링 자체를 막지 않는다.
        }
    }

    return patched;
}

async function startDomPatchLoop(fetchImpl: typeof fetch): Promise<void> {
    if (!CHECKOUT_RE.test(window.location.pathname)) return;
    const isJpy = shouldUseJpyRestriction();
    const isHideJapan = shouldHideJapanMethods();
    if (!isJpy && !isHideJapan) return;
    // JPY 제한은 어드민 토글에 종속. 비-JPY 숨김은 항상 적용.
    if (isJpy && !(await fetchRestrictEnabled(fetchImpl))) return;

    patchRenderedPaymentButtons();

    if (observer === null) {
        observer = new MutationObserver(() => {
            patchRenderedPaymentButtons();
        });
        observer.observe(document.body, { childList: true, subtree: true });
    }

    if (pollTimer !== null) {
        clearInterval(pollTimer);
    }

    let attempts = 0;
    pollTimer = setInterval(() => {
        attempts++;
        patchRenderedPaymentButtons();

        if (attempts >= 50) {
            if (pollTimer !== null) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }
    }, 200);
}

function onRouteChange(fetchImpl: typeof fetch): void {
    cachedRestrictEnabled = null;
    void startDomPatchLoop(fetchImpl);
}

export function installCheckoutJpyPaymentMethodRestrictor(): void {
    if (typeof window === 'undefined' || typeof window.fetch !== 'function') {
        return;
    }

    const w = windowRecord();
    if (w[FLAG]) return;
    w[FLAG] = true;

    const originalFetch = window.fetch.bind(window);

    window.fetch = async function patchedFetch(
        input: RequestInfo | URL,
        init?: RequestInit
    ): Promise<Response> {
        const url = extractUrl(input);
        const method = extractMethod(input, init);
        const path = pathnameOf(url);

        const response = await originalFetch(input, init);

        if (method === 'GET' && path.endsWith(CHECKOUT_PATH)) {
            try {
                const body = await response.clone().json();
                rememberCheckoutCurrency(body);
            } catch {
                // 체크아웃 응답이 JSON 이 아니거나 404 인 경우 default/preferred currency fallback 사용.
            }

            return response;
        }

        if (method !== 'GET' || !path.endsWith(PAYMENT_SETTINGS_PATH)) {
            return response;
        }

        const isJpy = shouldUseJpyRestriction();
        const isHideJapan = shouldHideJapanMethods();
        if (!isJpy && !isHideJapan) {
            return response;
        }

        // JPY 제한(다른 결제수단 차단)은 어드민 토글에 종속되지만,
        // 비-JPY 에서 일본 전용 결제수단 숨김은 항상 적용한다 (한국 등 비-JPY 사용자가
        // PayPay/일본 편의점결제를 잘못 선택하는 UX 회귀를 막기 위함).
        if (isJpy && !(await fetchRestrictEnabled(originalFetch))) {
            return response;
        }

        try {
            const body = (await response.clone().json()) as PaymentSettingsBody;
            const restricted = isJpy
                ? restrictPaymentSettingsForJpy(body)
                : hideJapanPaymentMethodsForNonJpy(body);
            logger.info(
                isJpy
                    ? 'restricted checkout payment methods for JPY order'
                    : 'hid Japan-only payment methods for non-JPY order',
            );
            return mutateResponse(response, restricted);
        } catch (error) {
            logger.warn('failed to restrict checkout payment methods', error);
            return response;
        }
    };

    logger.info('checkout JPY payment method restrictor installed');

    const start = () => {
        void startDomPatchLoop(originalFetch);
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', start);
    } else {
        start();
    }

    const originalPushState = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        originalPushState(...args);
        setTimeout(() => onRouteChange(originalFetch), 200);
    };
    window.addEventListener('popstate', () => setTimeout(() => onRouteChange(originalFetch), 200));
}
