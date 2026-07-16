const PLUGIN_ID = 'sirsoft-pay_nhnkcp';
const FLAG = '__kcpAdminApplePayNoticeInjectorInstalled';
const ADMIN_SETTINGS_RE = /^\/admin\/ecommerce\/settings\/?$/;
const LISTENER_FLAG = '__kcpAdminApplePayNoticeListenerAttached';
const NOTICE_KO = '애플페이는 IOS 기기에 모바일결제만 가능합니다.';
const NOTICE_EN = 'Apple Pay is available only for mobile payments on iOS devices.';

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

function noticeText(): string {
    const lang = document.documentElement.lang || navigator.language || '';

    return lang.toLowerCase().startsWith('ko') ? NOTICE_KO : NOTICE_EN;
}

function normalizedText(value: string | null | undefined): string {
    return (value ?? '').replace(/\s+/g, ' ').trim();
}

function isApplePayLabel(element: HTMLElement): boolean {
    const text = normalizedText(element.textContent);

    return text === '애플페이' || text === 'Apple Pay';
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
    notice.textContent = noticeText();

    return notice;
}

export function patchAdminApplePayNotice(root: ParentNode = document): boolean {
    if (typeof window === 'undefined' || typeof document === 'undefined') return false;
    if (!isOrderSettingsPage()) return false;
    const bodyText = normalizedText(document.body?.innerText ?? document.body?.textContent);
    if (bodyText.includes(noticeText())) return false;

    const applePayLabel = Array.from(root.querySelectorAll<HTMLElement>('span, p, div'))
        .find(isApplePayLabel);
    if (!applePayLabel) return false;

    applePayLabel.insertAdjacentElement('afterend', createNotice());
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
