const PLUGIN_ID = 'sirsoft-pay_nhnkcp';
const FLAG = '__nhnkcpAdminPaymentMethodBrandInjectorInstalled';
const LISTENER_FLAG = '__nhnkcpAdminPaymentMethodBrandSyncListenerAttached';
const ADMIN_SETTINGS_RE = /^\/admin\/ecommerce\/settings\/?$/;
const MARK_SELECTOR = '[data-nhnkcp-admin-payment-brand-mark="true"]';
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
        id: 'nhnkcp_payco',
        labels: ['PAYCO (NHN KCP)', 'PAYCO로 결제 (NHN KCP)', 'Pay with PAYCO (NHN KCP)'],
        shortLabels: ['PAYCO'],
        markLines: ['P'],
        markClassName: 'bg-red-500 text-white',
    },
    {
        id: 'nhnkcp_naverpay',
        labels: ['네이버페이 (NHN KCP)', '네이버페이 신용카드로 결제 (NHN KCP)', 'Naver Pay (NHN KCP)', 'Pay by Naver Pay credit card (NHN KCP)'],
        shortLabels: ['네이버페이 (카드)', 'Naver Pay (Card)'],
        markLines: ['N'],
        markClassName: 'bg-green-500 text-white',
    },
    {
        id: 'nhnkcp_naverpay_point',
        labels: ['네이버페이 포인트 (NHN KCP)', '네이버페이 머니/포인트로 결제 (NHN KCP)', 'Naver Pay Point (NHN KCP)', 'Pay with Naver Pay Money/Points (NHN KCP)'],
        shortLabels: ['네이버페이 (포인트)', 'Naver Pay (Point)'],
        markLines: ['NP'],
        markClassName: 'bg-green-600 text-white',
    },
    {
        id: 'nhnkcp_kakaopay',
        labels: ['카카오페이 (NHN KCP)', '카카오페이로 결제 (NHN KCP)', 'Kakao Pay (NHN KCP)', 'Pay with Kakao Pay (NHN KCP)'],
        shortLabels: ['카카오페이', 'Kakao Pay'],
        markLines: ['K'],
        markClassName: 'bg-yellow-400 text-gray-950',
    },
    {
        id: 'nhnkcp_applepay',
        labels: ['애플페이 (NHN KCP)', '애플페이로 결제 (NHN KCP)', 'Apple Pay (NHN KCP)', 'Pay with Apple Pay (NHN KCP)'],
        shortLabels: ['애플페이', 'Apple Pay'],
        markLines: ['A'],
        markClassName: 'bg-gray-900 text-white',
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
    mark.dataset.nhnkcpAdminPaymentBrandMark = 'true';
    mark.dataset.nhnkcpAdminPaymentMethod = definition.id;
    mark.setAttribute('aria-hidden', 'true');
    mark.className = `inline-flex items-center justify-center rounded-lg font-bold ${definition.markClassName}`;
    mark.style.width = '32px';
    mark.style.height = '32px';
    mark.style.flex = '0 0 32px';
    mark.style.lineHeight = '1';
    mark.style.fontSize = definition.markLines.join('').length > 2 ? '9px' : '12px';

    const expectedText = definition.markLines.join('');
    if (mark.textContent === expectedText) return;

    mark.replaceChildren();
    if (definition.markLines.length > 1) {
        mark.style.flexDirection = 'column';
        definition.markLines.forEach((line) => {
            const lineElement = document.createElement('span');
            lineElement.style.lineHeight = '1';
            lineElement.textContent = line;
            mark.appendChild(lineElement);
        });
    } else {
        mark.style.removeProperty('flex-direction');
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
        item.dataset.nhnkcpAdminPaymentMethod = definition.id;
        applyBrandMarkContent(existing, definition);
        return true;
    }

    const mark = createBrandMark(definition);
    const icon = findPaymentIcon(item);
    if (icon && icon.parentElement) {
        item.dataset.nhnkcpAdminPaymentMethod = definition.id;
        icon.replaceWith(mark);
        return true;
    }

    const title = findTitleElement(item);
    if (title && title.parentElement) {
        item.dataset.nhnkcpAdminPaymentMethod = definition.id;
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
