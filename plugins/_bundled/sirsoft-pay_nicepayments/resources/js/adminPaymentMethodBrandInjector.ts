const PLUGIN_ID = 'sirsoft-pay_nicepayments';
const FLAG = '__nicepayAdminPaymentMethodBrandInjectorInstalled';
const LISTENER_FLAG = '__nicepayAdminPaymentMethodBrandSyncListenerAttached';
const ADMIN_SETTINGS_RE = /^\/admin\/ecommerce\/settings\/?$/;
const MARK_SELECTOR = '[data-nicepay-admin-payment-brand-mark="true"]';
const SYNC_RETRY_INTERVAL_MS = 200;
const SYNC_RETRY_ATTEMPTS = 120;

interface AdminPaymentBrandDefinition {
    id: string;
    labels: string[];
    shortLabels: string[];
    markLines: string[];
    markClassName: string;
}

const ADMIN_PAYMENT_BRAND_DEFINITIONS: AdminPaymentBrandDefinition[] = [
    {
        id: 'nicepay_naverpay',
        labels: ['네이버페이 (나이스페이먼츠)', '네이버페이로 결제 (나이스페이먼츠)', 'Naver Pay (NicePayments)', 'Pay with Naver Pay (NicePayments)'],
        shortLabels: [],
        markLines: ['N'],
        markClassName: 'bg-green-500 text-white',
    },
    {
        id: 'nicepay_kakaopay',
        labels: ['카카오페이 (나이스페이먼츠)', '카카오페이로 결제 (나이스페이먼츠)', 'Kakao Pay (NicePayments)', 'Pay with Kakao Pay (NicePayments)'],
        shortLabels: [],
        markLines: ['K'],
        markClassName: 'bg-yellow-400 text-gray-950',
    },
    {
        id: 'nicepay_samsungpay',
        labels: ['삼성페이 (나이스페이먼츠)', '삼성페이로 결제 (나이스페이먼츠)', 'Samsung Pay (NicePayments)', 'Pay with Samsung Pay (NicePayments)'],
        shortLabels: [],
        markLines: ['S'],
        markClassName: 'bg-blue-600 text-white',
    },
    {
        id: 'nicepay_applepay',
        labels: ['애플페이 (나이스페이먼츠)', '애플페이로 결제 (나이스페이먼츠)', 'Apple Pay (NicePayments)', 'Pay with Apple Pay (NicePayments)'],
        shortLabels: [],
        markLines: ['A'],
        markClassName: 'bg-gray-900 text-white',
    },
    {
        id: 'nicepay_payco',
        labels: ['PAYCO (나이스페이먼츠)', 'PAYCO로 결제 (나이스페이먼츠)', 'PAYCO (NicePayments)', 'Pay with PAYCO (NicePayments)'],
        shortLabels: [],
        markLines: ['P'],
        markClassName: 'bg-red-500 text-white',
    },
    {
        id: 'nicepay_skpay',
        labels: ['11pay (나이스페이먼츠)', '11pay로 결제 (나이스페이먼츠)', '11pay (NicePayments)', 'Pay with 11pay (NicePayments)'],
        shortLabels: [],
        markLines: ['11'],
        markClassName: 'bg-orange-500 text-white',
    },
    {
        id: 'nicepay_ssgpay',
        labels: ['SSG페이 (나이스페이먼츠)', 'SSG페이로 결제 (나이스페이먼츠)', 'SSG Pay (NicePayments)', 'Pay with SSG Pay (NicePayments)'],
        shortLabels: [],
        markLines: ['SSG'],
        markClassName: 'bg-red-700 text-white',
    },
    {
        id: 'nicepay_lpay',
        labels: ['L.pay (나이스페이먼츠)', 'L.pay로 결제 (나이스페이먼츠)', 'L.pay (NicePayments)', 'Pay with L.pay (NicePayments)'],
        shortLabels: [],
        markLines: ['L'],
        markClassName: 'bg-purple-600 text-white',
    },
];

let observer: MutationObserver | null = null;
let retryTimer: number | null = null;
let syncQueued = false;

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

function isOrderSettingsPage(): boolean {
    if (!ADMIN_SETTINGS_RE.test(window.location.pathname)) return false;

    const tab = new URLSearchParams(window.location.search).get('tab');
    return tab === null || tab === '' || tab === 'order_settings';
}

function classNameOf(element: Element): string {
    return element instanceof HTMLElement ? element.className : '';
}

function comparableText(value: string | null | undefined): string {
    return (value ?? '')
        .replace(/\u200B/g, '')
        .replace(/\s+/g, ' ')
        .trim();
}

function isPaymentMethodItem(element: HTMLElement): boolean {
    const className = classNameOf(element);

    return className.includes('excel-card')
        || (className.includes('flex-center') && className.includes('border') && className.includes('gap-4'));
}

function findTitleElement(item: HTMLElement): HTMLElement | null {
    return item.querySelector<HTMLElement>('.font-medium');
}

function findDefinitionForItem(item: HTMLElement): AdminPaymentBrandDefinition | null {
    const title = comparableText(findTitleElement(item)?.textContent);
    const text = comparableText(item.textContent);

    return ADMIN_PAYMENT_BRAND_DEFINITIONS.find((definition) => (
        definition.shortLabels.some((label) => title === label)
        || definition.labels.some((label) => text.includes(label))
    )) ?? null;
}

function findPaymentMethodItems(root: ParentNode): HTMLElement[] {
    return Array.from(root.querySelectorAll<HTMLElement>('div'))
        .filter(isPaymentMethodItem);
}

function findPaymentIcon(item: HTMLElement): Element | null {
    const icons = Array.from(item.querySelectorAll<Element>('svg, [data-icon], i'));

    return icons.find((element) => (
        !element.closest('[data-drag-handle]')
        && !element.closest(MARK_SELECTOR)
        && !element.closest('.row-stack, .excel-card-body, button, select, [role="switch"]')
    )) ?? null;
}

function applyBrandMarkContent(mark: HTMLSpanElement, definition: AdminPaymentBrandDefinition): void {
    mark.dataset.nicepayAdminPaymentBrandMark = 'true';
    mark.dataset.nicepayAdminPaymentMethod = definition.id;
    mark.setAttribute('aria-hidden', 'true');
    mark.className = `inline-flex items-center justify-center rounded-lg font-bold ${definition.markClassName}`;
    mark.style.width = '32px';
    mark.style.height = '32px';
    mark.style.flex = '0 0 32px';
    mark.style.lineHeight = '1';
    mark.style.fontSize = definition.markLines.join('').length > 2 ? '9px' : '12px';

    const expectedText = definition.markLines.join('');
    if (mark.textContent !== expectedText) {
        mark.textContent = definition.markLines[0] ?? '';
    }
}

function createBrandMark(definition: AdminPaymentBrandDefinition): HTMLSpanElement {
    const mark = document.createElement('span');
    applyBrandMarkContent(mark, definition);

    return mark;
}

function syncBrandMark(item: HTMLElement, definition: AdminPaymentBrandDefinition): boolean {
    const existing = item.querySelector<HTMLElement>(MARK_SELECTOR);
    if (existing instanceof HTMLSpanElement) {
        item.dataset.nicepayAdminPaymentMethod = definition.id;
        applyBrandMarkContent(existing, definition);
        return true;
    }

    const mark = createBrandMark(definition);
    const icon = findPaymentIcon(item);
    if (icon && icon.parentElement) {
        item.dataset.nicepayAdminPaymentMethod = definition.id;
        icon.replaceWith(mark);
        return true;
    }

    const title = findTitleElement(item);
    if (title && title.parentElement) {
        item.dataset.nicepayAdminPaymentMethod = definition.id;
        title.parentElement.insertBefore(mark, title);
        return true;
    }

    return false;
}

export function syncRenderedAdminPaymentMethodBrands(root: ParentNode = document): boolean {
    if (typeof window === 'undefined' || typeof document === 'undefined') return false;
    if (!isOrderSettingsPage()) return false;

    let matched = false;

    findPaymentMethodItems(root).forEach((item) => {
        const definition = findDefinitionForItem(item);
        if (!definition) return;

        if (syncBrandMark(item, definition)) {
            matched = true;
        }
    });

    return matched;
}

function queueSync(): void {
    if (syncQueued) return;
    syncQueued = true;

    window.setTimeout(() => {
        syncQueued = false;
        syncRenderedAdminPaymentMethodBrands();
    }, 0);
}

function stopRetries(): void {
    if (retryTimer === null) return;

    window.clearInterval(retryTimer);
    retryTimer = null;
}

function startSync(): void {
    if (!isOrderSettingsPage()) return;

    stopRetries();
    syncRenderedAdminPaymentMethodBrands();

    let attempts = 0;
    retryTimer = window.setInterval(() => {
        attempts += 1;
        syncRenderedAdminPaymentMethodBrands();

        if (attempts >= SYNC_RETRY_ATTEMPTS) {
            stopRetries();
        }
    }, SYNC_RETRY_INTERVAL_MS);

    const body = document.body as HTMLElement & Record<string, unknown>;
    if (body[LISTENER_FLAG]) return;
    body[LISTENER_FLAG] = true;

    observer = new MutationObserver(() => queueSync());
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
}

function onRouteChange(): void {
    if (isOrderSettingsPage()) {
        startSync();
        return;
    }

    stopRetries();
}

export function installAdminPaymentMethodBrandInjector(): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    const w = windowRecord();
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] admin payment method brand sync installed`);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => startSync());
    } else {
        startSync();
    }

    const origPush = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        origPush(...args);
        window.setTimeout(() => onRouteChange(), 200);
    };
    window.addEventListener('popstate', () => window.setTimeout(() => onRouteChange(), 200));
}

export function resetAdminPaymentMethodBrandInjectorForTests(): void {
    observer?.disconnect();
    observer = null;
    stopRetries();
    syncQueued = false;
    delete windowRecord()[FLAG];
    if (document.body) {
        delete ((document.body as HTMLElement & Record<string, unknown>)[LISTENER_FLAG]);
    }
}
