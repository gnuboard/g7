import {
    canOpenKginicisReceipt,
    fetchKginicisReceiptInfo,
    openKginicisReceipt,
    receiptButtonLabel,
} from './receiptPopup';

const PLUGIN_ID = 'sirsoft-pay_kginicis';
const FLAG = '__kginicisOcReceiptInjectorInstalled';
const BTN_ID = 'kginicis-oc-receipt-btn';

const ORDER_COMPLETE_RE = /^\/shop\/orders\/([^/]+)\/complete$/;

type Payment = {
    pg_provider: string;
    payment_status: string;
    transaction_id: string | null;
    [key: string]: unknown;
};

function getAuthToken(): string | null {
    return localStorage.getItem('auth_token');
}

function getGuestOrderToken(): string | null {
    // 코어 storageHandlers.initGuestOrderTokenHandler 가 sessionStorage 에 저장한 토큰.
    // sessionStorage 미접근 환경(private/iframe) fallback 으로 _global.guestOrderToken 도 확인.
    try {
        const sessionToken = sessionStorage.getItem('g7_guest_order_token');
        if (sessionToken) return sessionToken;
    } catch {
        // sessionStorage 접근 불가
    }
    const globalToken = (window as any).G7Core?.state?.get?.('_global')?.guestOrderToken;
    return typeof globalToken === 'string' && globalToken !== '' ? globalToken : null;
}

async function fetchPayment(orderNumber: string): Promise<Payment | null> {
    const authToken = getAuthToken();
    const guestToken = getGuestOrderToken();

    // 회원 sanctum 토큰 또는 비회원 주문 조회 토큰 중 하나는 있어야 호출 가능.
    if (!authToken && !guestToken) return null;

    const headers: Record<string, string> = { Accept: 'application/json' };
    if (authToken) {
        headers.Authorization = `Bearer ${authToken}`;
    } else if (guestToken) {
        // 코어 PublicOrderController::showByOrderNumber 가 X-Guest-Order-Token 으로 비회원 주문 매칭.
        headers['X-Guest-Order-Token'] = guestToken;
    }

    try {
        const res = await fetch(`/api/modules/sirsoft-ecommerce/user/orders/${orderNumber}`, { headers });
        if (!res.ok) return null;
        const data = (await res.json()) as { data?: { payment?: Payment } };
        return data?.data?.payment ?? null;
    } catch {
        return null;
    }
}

function patchPaymentMethodDisplay(displayLabel: string | null | undefined): boolean {
    if (!displayLabel) return false;

    const rows = Array.from(document.querySelectorAll<HTMLElement>('div'));
    for (const row of rows) {
        const spans = Array.from(row.children).filter(
            (child): child is HTMLElement => child instanceof HTMLElement && child.tagName === 'SPAN',
        );
        if (spans.length < 2) continue;

        const label = spans[0].textContent?.trim();
        if (label !== '결제 방법' && label !== '결제수단' && label !== '결제 방식') continue;

        const value = spans[spans.length - 1];
        if (value.textContent?.trim() === displayLabel) {
            row.dataset.kginicisPaymentMethodRow = 'true';
            return true;
        }

        value.textContent = displayLabel;
        value.dataset.kginicisPaymentMethodPatched = 'true';
        row.dataset.kginicisPaymentMethodRow = 'true';
        return true;
    }

    return false;
}

async function injectOnOrderComplete(orderNumber: string): Promise<void> {
    if (document.getElementById(BTN_ID)) return;

    const receiptInfo = await fetchKginicisReceiptInfo(orderNumber);
    if (!canOpenKginicisReceipt(receiptInfo)) return;

    const payment = await fetchPayment(orderNumber);
    if (payment) {
        if (payment.pg_provider !== 'kginicis') return;
        if (!payment.transaction_id) return;

        const isPaid = payment.payment_status === 'paid';
        const isCbtConfirmation = receiptInfo.receipt_type === 'cbt_confirmation';
        if (!isPaid && !isCbtConfirmation) return;
    }

    patchPaymentMethodDisplay(receiptInfo.payment_method_display_label);

    const blueBtn = Array.from(document.querySelectorAll<HTMLButtonElement>('button[type="button"]'))
        .find(b => b.className.includes('bg-blue-600'));

    if (!blueBtn?.parentElement) return;

    const container = blueBtn.parentElement;
    container.dataset.kginicisReceiptContainer = 'order-complete';

    const receiptBtn = document.createElement('button');
    receiptBtn.id = BTN_ID;
    receiptBtn.type = 'button';
    receiptBtn.dataset.kginicisReceiptButton = 'order-complete';
    receiptBtn.className = blueBtn.className
        .replace(/bg-blue-\d+/g, 'bg-green-600')
        .replace(/hover:bg-blue-\d+/g, 'hover:bg-green-700');
    receiptBtn.textContent = receiptButtonLabel(receiptInfo);

    receiptBtn.addEventListener('click', async () => {
        receiptBtn.disabled = true;
        receiptBtn.textContent = '로딩 중...';
        const latestReceiptInfo = await fetchKginicisReceiptInfo(orderNumber);
        receiptBtn.disabled = false;
        receiptBtn.textContent = receiptButtonLabel(latestReceiptInfo ?? receiptInfo);
        if (canOpenKginicisReceipt(latestReceiptInfo)) {
            openKginicisReceipt(latestReceiptInfo);
        }
    });

    const lastBtn = container.lastElementChild;
    container.insertBefore(receiptBtn, lastBtn);

    console.info(`[${PLUGIN_ID}] receipt button injected on order complete page`);
}

function tryInject(): void {
    const match = location.pathname.match(ORDER_COMPLETE_RE);
    if (match) {
        void injectOnOrderComplete(match[1]);
    }
}

export function installOrderCompleteReceiptInjector(): void {
    if (typeof window === 'undefined') return;
    const w = window as Record<string, unknown>;
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] order complete receipt injector installed`);

    const schedule = (delay = 1200) => setTimeout(tryInject, delay);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => schedule());
    } else {
        schedule();
    }

    const origPush = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        origPush(...args);
        schedule();
    };
    window.addEventListener('popstate', () => schedule(500));
}
