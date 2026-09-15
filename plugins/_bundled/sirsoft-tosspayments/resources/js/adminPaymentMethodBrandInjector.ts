/**
 * 관리자 주문설정 결제수단 목록의 브랜드 마크 주입.
 *
 * 관리자 레이아웃(`_payment_methods_list.json` / `_payment_methods_cards.json`)은 결제수단
 * 아이콘 열을 `Icon name={{$method._cached_icon}}` 하나로만 그린다 — 카탈로그의
 * `_cached_brand_mark` 를 읽는 노드가 없다(그 값을 렌더하는 곳은 사용자 템플릿 체크아웃뿐).
 * 그래서 각 PG 플러그인이 자기 결제수단 행의 아이콘을 브랜드 배지로 치환하는 관리자 전용
 * injector 를 직접 싣는다. 토스에만 이 파일이 없어 간편결제가 회색 기본 아이콘으로 보였다.
 *
 * 구조는 다른 PG 플러그인(nicepayments·kginicis)의 동명 파일과 동일하게 맞춘다 — 행 판별은
 * 라벨 텍스트, 치환 대상은 드래그 핸들/컨트롤을 제외한 첫 아이콘, 재적용은 MutationObserver
 * + 폴링이다. 배지 색·문자는 같은 브랜드가 어느 PG 를 통하든 같아야 하므로 서버측
 * `RegisterTossPaymentMethodsListener::METHOD_PRESENTATION` 의 `brand_mark` 와 값을 맞춘다.
 */
const PLUGIN_ID = 'sirsoft-tosspayments';
const FLAG = '__tossAdminPaymentMethodBrandInjectorInstalled';
const LISTENER_FLAG = '__tossAdminPaymentMethodBrandSyncListenerAttached';
const ADMIN_SETTINGS_RE = /^\/admin\/ecommerce\/settings\/?$/;
const MARK_SELECTOR = '[data-toss-admin-payment-brand-mark="true"]';
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
        id: 'toss_tosspay',
        labels: ['토스페이 (토스페이먼츠)', '토스페이 간편결제 — 토스페이먼츠를 통해 처리', 'TossPay (TossPayments)', 'TossPay easy payment — processed via TossPayments'],
        shortLabels: [],
        markLines: ['T'],
        markClassName: 'bg-blue-500 text-white',
    },
    {
        id: 'toss_kakaopay',
        labels: ['카카오페이 (토스페이먼츠)', '카카오페이 간편결제 — 토스페이먼츠를 통해 처리', 'KakaoPay (TossPayments)', 'KakaoPay easy payment — processed via TossPayments'],
        shortLabels: [],
        markLines: ['K'],
        markClassName: 'bg-yellow-400 text-gray-950',
    },
    {
        id: 'toss_naverpay',
        labels: ['네이버페이 (토스페이먼츠)', '네이버페이 간편결제 — 토스페이먼츠를 통해 처리', 'NaverPay (TossPayments)', 'NaverPay easy payment — processed via TossPayments'],
        shortLabels: [],
        markLines: ['N'],
        markClassName: 'bg-green-500 text-white',
    },
    {
        id: 'toss_payco',
        labels: ['페이코 (토스페이먼츠)', '페이코 간편결제 — 토스페이먼츠를 통해 처리', 'PAYCO (TossPayments)', 'PAYCO easy payment — processed via TossPayments'],
        shortLabels: [],
        markLines: ['P'],
        markClassName: 'bg-red-500 text-white',
    },
    {
        id: 'toss_samsungpay',
        labels: ['삼성페이 (토스페이먼츠)', '삼성페이 간편결제 — 토스페이먼츠를 통해 처리', 'Samsung Pay (TossPayments)', 'Samsung Pay easy payment — processed via TossPayments'],
        shortLabels: [],
        markLines: ['S'],
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

function classNameOf(element: Element): string {
    return element instanceof HTMLElement ? element.className : '';
}

function comparableText(value: string | null | undefined): string {
    return (value ?? '')
        .replace(/​/g, '')
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
    mark.dataset.tossAdminPaymentBrandMark = 'true';
    mark.dataset.tossAdminPaymentMethod = definition.id;
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
        item.dataset.tossAdminPaymentMethod = definition.id;
        applyBrandMarkContent(existing, definition);
        return true;
    }

    const mark = createBrandMark(definition);
    const icon = findPaymentIcon(item);
    if (icon && icon.parentElement) {
        item.dataset.tossAdminPaymentMethod = definition.id;
        icon.replaceWith(mark);
        return true;
    }

    const title = findTitleElement(item);
    if (title && title.parentElement) {
        item.dataset.tossAdminPaymentMethod = definition.id;
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
