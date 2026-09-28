import { applePayUnsupportedMessage, NHNKCP_APPLEPAY_METHOD } from './support/applePayDevice';

const PLUGIN_ID = 'sirsoft-pay_nhnkcp';
const FLAG = '__kcpAdminApplePayNoticeInjectorInstalled';
const ADMIN_SETTINGS_RE = /^\/admin\/ecommerce\/settings\/?$/;
const LISTENER_FLAG = '__kcpAdminApplePayNoticeListenerAttached';

let observer: MutationObserver | null = null;
let retryTimer: number | null = null;
let syncQueued = false;

function windowRecord(): Record<string, unknown> {
    return window as unknown as Record<string, unknown>;
}

function isOrderSettingsPage(): boolean {
    if (!ADMIN_SETTINGS_RE.test(window.location.pathname)) return false;

    const tab = new URLSearchParams(window.location.search).get('tab');
    return tab === null || tab === 'order_settings';
}

function createNotice(): HTMLSpanElement {
    const notice = document.createElement('span');
    notice.dataset.nhnkcpAdminApplePayNotice = 'true';
    notice.className = 'text-label-subtle';
    notice.style.display = 'block';
    notice.style.width = '100%';
    notice.style.flexBasis = '100%';
    notice.style.marginTop = '2px';
    notice.style.fontSize = '12px';
    notice.style.lineHeight = '1rem';
    // 문구는 번역 키(payment.error.applepay_ios_only)가 정한다 — 주문서의 애플페이 차단 안내와 같은 문구다.
    notice.textContent = applePayUnsupportedMessage();

    return notice;
}

export function patchAdminApplePayNotice(root: ParentNode = document): boolean {
    if (typeof window === 'undefined' || typeof document === 'undefined') return false;
    if (!isOrderSettingsPage()) return false;
    // 애플페이 항목은 이커머스 관리자 화면이 단 data-payment-method(결제수단 id)로만 찾는다. 이름 글자(「애플페이」
    // 「Apple Pay」)는 관리자 언어마다 달라 찾는 기준이 될 수 없다(PO 결정 2026-09-28 「id 표식으로만 찾음」).
    const item = root.querySelector<HTMLElement>(`[data-payment-method="${NHNKCP_APPLEPAY_METHOD}"]`);
    if (!item || item.querySelector('[data-nhnkcp-admin-apple-pay-notice="true"]')) return false;

    const title = item.querySelector<HTMLElement>('.font-medium');
    if (!title) return false;

    title.insertAdjacentElement('afterend', createNotice());
    return true;
}

function queueSync(): void {
    if (syncQueued) return;
    syncQueued = true;

    window.setTimeout(() => {
        syncQueued = false;
        patchAdminApplePayNotice();
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
    patchAdminApplePayNotice();

    let attempts = 0;
    retryTimer = window.setInterval(() => {
        attempts += 1;
        if (patchAdminApplePayNotice() || attempts >= 50) {
            stopRetries();
        }
    }, 200);

    const body = document.body as HTMLElement & Record<string, unknown>;
    if (body[LISTENER_FLAG]) return;
    body[LISTENER_FLAG] = true;

    observer = new MutationObserver(() => queueSync());
    observer.observe(document.body, { childList: true, subtree: true, characterData: true });
}

function onRouteChange(): void {
    if (isOrderSettingsPage()) startSync();
}

export function installAdminApplePayNoticeInjector(): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') return;
    const w = windowRecord();
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] admin Apple Pay notice injector installed`);

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

export function resetAdminApplePayNoticeInjectorForTests(): void {
    observer?.disconnect();
    observer = null;
    stopRetries();
    syncQueued = false;
    delete windowRecord()[FLAG];
    if (document.body) {
        delete ((document.body as HTMLElement & Record<string, unknown>)[LISTENER_FLAG]);
    }
}
