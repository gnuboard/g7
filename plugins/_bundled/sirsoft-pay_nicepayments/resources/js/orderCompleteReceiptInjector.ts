import { buildOrderRequestHeaders } from './guestOrderToken';

const PLUGIN_ID = 'sirsoft-pay_nicepayments';
const FLAG = '__nicepayOcReceiptInjectorInstalled';
const BTN_ID = 'nicepay-oc-receipt-btn';

const ORDER_COMPLETE_RE = /^\/shop\/orders\/([^/]+)\/complete$/;

type Payment = {
    pg_provider: string;
    transaction_id: string | null;
    paid_at: string | null;
    [key: string]: unknown;
};

interface ReceiptInfo {
    receipt_url?: string | null;
    payment_method_display_label?: string | null;
}

async function fetchPayment(orderNumber: string): Promise<Payment | null> {
    // 회원 토큰 또는 비회원 주문 조회 토큰 중 하나는 있어야 한다. 비회원 분기를 비우면
    // 비회원 손님에게는 영수증 버튼이 아예 나타나지 않는다 (서버는 지원한다).
    const headers = buildOrderRequestHeaders();
    if (!headers) return null;

    try {
        const res = await fetch(`/api/modules/sirsoft-ecommerce/user/orders/${orderNumber}`, {
            headers,
            credentials: 'same-origin',
        });
        if (!res.ok) return null;
        const data = (await res.json()) as { data?: { payment?: Payment } };
        return data?.data?.payment ?? null;
    } catch {
        return null;
    }
}

async function fetchReceiptInfo(orderNumber: string): Promise<ReceiptInfo | null> {
    const headers = buildOrderRequestHeaders();
    if (!headers) return null;

    try {
        const res = await fetch(`/api/plugins/${PLUGIN_ID}/user/orders/${orderNumber}/receipt`, {
            headers,
            credentials: 'same-origin',
        });
        if (!res.ok) return null;
        // 응답은 공통 형식({ success, message, data }) — 영수증 정보는 data 에 있다.
        const body = (await res.json()) as { data?: ReceiptInfo | null };
        return body?.data ?? null;
    } catch {
        return null;
    }
}

export function patchPaymentMethodDisplay(displayLabel: string | null | undefined): boolean {
    if (!displayLabel) return false;

    // 결제 방법 행은 템플릿이 약속한 id(order_payment_method_row)로만 찾는다. 행 이름 글자(「결제 방법」 등)는 언어마다
    // 달라 찾는 기준이 될 수 없다 — id 가 없는 템플릿에서는 건드리지 않는다(약속: sirsoft-ecommerce docs/extension-points.md).
    const row = document.getElementById('order_payment_method_row');
    if (!row) return false;

    const spans = Array.from(row.children).filter(
        (child): child is HTMLElement => child instanceof HTMLElement && child.tagName === 'SPAN',
    );
    if (spans.length < 2) return false;

    const value = spans[spans.length - 1];
    if (value.textContent?.trim() !== displayLabel) {
        value.textContent = displayLabel;
        value.dataset.nicepayPaymentMethodPatched = 'true';
    }
    row.dataset.nicepayPaymentMethodRow = 'true';
    return true;
}

async function injectOnOrderComplete(orderNumber: string): Promise<void> {
    if (document.getElementById(BTN_ID)) return;

    const payment = await fetchPayment(orderNumber);
    // 입금완료(paid_at 채워짐) 시점에만 영수증 버튼 표시 — 가상계좌 입금대기 차단
    if (!payment || payment.pg_provider !== 'nicepayments' || !payment.transaction_id || !payment.paid_at) return;

    const blueBtn = Array.from(document.querySelectorAll<HTMLButtonElement>('button[type="button"]'))
        .find(b => b.className.includes('bg-blue-600'));

    if (!blueBtn?.parentElement) return;

    const receiptInfo = await fetchReceiptInfo(orderNumber);
    patchPaymentMethodDisplay(receiptInfo?.payment_method_display_label);

    const container = blueBtn.parentElement;

    const receiptBtn = document.createElement('button');
    receiptBtn.id = BTN_ID;
    receiptBtn.type = 'button';
    receiptBtn.className = blueBtn.className
        .replace(/bg-blue-\d+/g, 'bg-green-600')
        .replace(/hover:bg-blue-\d+/g, 'hover:bg-green-700');
    receiptBtn.textContent = '영수증 조회';

    receiptBtn.addEventListener('click', async () => {
        receiptBtn.disabled = true;
        receiptBtn.textContent = '로딩 중...';
        const data = await fetchReceiptInfo(orderNumber);
        receiptBtn.disabled = false;
        receiptBtn.textContent = '영수증 조회';
        const url = data?.receipt_url ?? null;
        if (url) {
            window.open(url, 'nicepay_receipt', 'width=800,height=600,scrollbars=yes,resizable=yes');
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
