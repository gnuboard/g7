const PLUGIN_ID = 'sirsoft-pay_kginicis';
const FLAG = '__kginicisAdminPaymentMethodBrandInjectorInstalled';
const LISTENER_FLAG = '__kginicisAdminPaymentMethodBrandSyncListenerAttached';
const ADMIN_SETTINGS_RE = /^\/admin\/ecommerce\/settings\/?$/;
const MARK_SELECTOR = '[data-kginicis-admin-payment-brand-mark="true"]';
const SYNC_RETRY_INTERVAL_MS = 200;
const SYNC_RETRY_ATTEMPTS = 120;

interface AdminPaymentBrandDefinition {
    id: string;
    markLines: string[];
    markClassName: string;
}

const ADMIN_PAYMENT_BRAND_DEFINITIONS: AdminPaymentBrandDefinition[] = [
    {
        id: 'kginicis_naverpay',
        markLines: ['N', 'Pay'],
        markClassName: 'bg-green-500 text-white',
    },
    {
        id: 'kginicis_samsung_pay',
        markLines: ['S', 'Pay'],
        markClassName: 'bg-blue-700 text-white',
    },
    {
        id: 'kginicis_lpay',
        markLines: ['L', 'pay'],
        markClassName: 'bg-red-600 text-white',
    },
    {
        id: 'kginicis_kakaopay',
        markLines: ['Kakao', 'Pay'],
        markClassName: 'bg-yellow-400 text-gray-950',
    },
    {
        id: 'kginicis_japan_paypay',
        markLines: ['Pay', 'Pay'],
        markClassName: 'bg-red-600 text-white',
    },
    {
        id: 'kginicis_japan_cvs',
        markLines: ['CVS', 'JP'],
        markClassName: 'bg-blue-600 text-white',
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

function findTitleElement(item: HTMLElement): HTMLElement | null {
    return item.querySelector<HTMLElement>('.font-medium');
}

// 결제수단 항목은 이커머스 관리자 화면이 항목마다 단 data-payment-method(결제수단 id)로만 찾는다. 이름 글자·클래스 모양은
// 관리자 언어·화면 폭마다 달라 찾는 기준이 될 수 없다(약속: sirsoft-ecommerce docs/extension-points.md, PO 결정 2026-09-28).
function findDefinitionForItem(item: HTMLElement): AdminPaymentBrandDefinition | null {
    const method = item.dataset.paymentMethod;

    return ADMIN_PAYMENT_BRAND_DEFINITIONS.find((definition) => definition.id === method) ?? null;
}

function findPaymentMethodItems(root: ParentNode): HTMLElement[] {
    return Array.from(root.querySelectorAll<HTMLElement>('[data-payment-method]'));
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
    mark.dataset.kginicisAdminPaymentBrandMark = 'true';
    mark.dataset.kginicisAdminPaymentMethod = definition.id;
    mark.setAttribute('aria-hidden', 'true');
    mark.className = `inline-flex items-center justify-center rounded-lg font-bold ${definition.markClassName}`;
    mark.style.width = '32px';
    mark.style.height = '32px';
    mark.style.flex = '0 0 32px';
    mark.style.flexDirection = 'column';
    mark.style.lineHeight = '1';
    mark.style.fontSize = '9px';

    const expectedText = definition.markLines.join('');
    if (mark.textContent === expectedText) return;

    mark.replaceChildren();
    definition.markLines.forEach((line) => {
        const lineElement = document.createElement('span');
        lineElement.style.lineHeight = '1';
        lineElement.textContent = line;
        mark.appendChild(lineElement);
    });
}

function createBrandMark(definition: AdminPaymentBrandDefinition): HTMLSpanElement {
    const mark = document.createElement('span');
    applyBrandMarkContent(mark, definition);

    return mark;
}

function syncBrandMark(item: HTMLElement, definition: AdminPaymentBrandDefinition): boolean {
    const existing = item.querySelector<HTMLElement>(MARK_SELECTOR);
    if (existing instanceof HTMLSpanElement) {
        item.dataset.kginicisAdminPaymentMethod = definition.id;
        applyBrandMarkContent(existing, definition);
        return true;
    }

    const mark = createBrandMark(definition);
    const icon = findPaymentIcon(item);
    if (icon && icon.parentElement) {
        item.dataset.kginicisAdminPaymentMethod = definition.id;
        icon.replaceWith(mark);
        return true;
    }

    const title = findTitleElement(item);
    if (title && title.parentElement) {
        item.dataset.kginicisAdminPaymentMethod = definition.id;
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

    // replaceState 도 후킹한다 — 환경설정 화면의 탭 전환은 pushState 가 아니라
    // replaceState 로 URL 만 바꾼다(실측: 탭 왕복 1회에 push 0 / replace 2). pushState 만
    // 후킹하면 다른 탭에 갔다 돌아왔을 때 onRouteChange 가 발화하지 않아 배지가 사라진 채로
    // 남는다(행은 그대로 렌더되므로 화면상 아이콘만 회색으로 되돌아간다).
    const origReplace = history.replaceState.bind(history);
    history.replaceState = (...args: Parameters<typeof history.replaceState>) => {
        origReplace(...args);
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
