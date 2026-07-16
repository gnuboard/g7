const PLUGIN_ID = 'sirsoft-pay_nhnkcp';
const FLAG = '__kcpOcReceiptInjectorInstalled';
const BTN_ID = 'kcp-oc-receipt-btn';

const ORDER_COMPLETE_RE = /^\/shop\/orders\/([^/]+)\/complete$/;

type Payment = {
    pg_provider: string;
    transaction_id: string | null;
    paid_at: string | null;
    [key: string]: unknown;
};

function getToken(): string | null {
    return localStorage.getItem('auth_token');
}

async function fetchPayment(orderNumber: string): Promise<Payment | null> {
    const token = getToken();
    if (!token) return null;

    try {
        const res = await fetch(`/api/modules/sirsoft-ecommerce/user/orders/${orderNumber}`, {
            headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        });
        if (!res.ok) return null;
        const data = (await res.json()) as { data?: { payment?: Payment } };
        return data?.data?.payment ?? null;
    } catch {
        return null;
    }
}

interface ReceiptInfo {
    receipt_url: string;
    cash_receipt_url: string | null;
    payment_method_display_label?: string | null;
}

async function fetchReceiptUrl(orderNumber: string): Promise<ReceiptInfo | null> {
    const token = getToken();
    if (!token) return null;

    try {
        const res = await fetch(`/api/plugins/${PLUGIN_ID}/user/orders/${orderNumber}/receipt`, {
            headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        });
        if (!res.ok) return null;
        return (await res.json()) as ReceiptInfo;
    } catch {
        return null;
    }
}

function makeBtn(text: string, classes: string, onClick: (btn: HTMLButtonElement) => void): HTMLButtonElement {
    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = classes;
    btn.textContent = text;
    btn.addEventListener('click', () => onClick(btn));
    return btn;
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
            row.dataset.nhnkcpPaymentMethodRow = 'true';
            return true;
        }

        value.textContent = displayLabel;
        value.dataset.nhnkcpPaymentMethodPatched = 'true';
        row.dataset.nhnkcpPaymentMethodRow = 'true';
        return true;
    }

    return false;
}

// 주문완료 페이지 버튼 영역에 영수증 버튼 주입
async function injectOnOrderComplete(orderNumber: string): Promise<void> {
    if (document.getElementById(BTN_ID)) return;

    const payment = await fetchPayment(orderNumber);
    // 입금완료(paid_at 채워짐) 시점에만 영수증 버튼 표시.
    // 가상계좌 발급 시 transaction_id 는 채워지지만 paid_at 은 null 이므로 입금대기 상태에선 미노출.
    if (!payment || payment.pg_provider !== 'nhnkcp' || !payment.transaction_id || !payment.paid_at) return;

    // "주문 상세 보기" 버튼 찾기 (bg-blue-600)
    const blueBtn = Array.from(document.querySelectorAll<HTMLButtonElement>('button[type="button"]'))
        .find(b => b.className.includes('bg-blue-600'));

    if (!blueBtn?.parentElement) return;

    const receiptInfo = await fetchReceiptUrl(orderNumber);
    patchPaymentMethodDisplay(receiptInfo?.payment_method_display_label);

    const container = blueBtn.parentElement;

    const receiptBtn = makeBtn('영수증 조회', blueBtn.className.replace(/bg-blue-\d+/g, 'bg-green-600').replace(/hover:bg-blue-\d+/g, 'hover:bg-green-700'), async (btn) => {
        btn.disabled = true;
        btn.textContent = '로딩 중...';
        const data = await fetchReceiptUrl(orderNumber);
        btn.disabled = false;
        btn.textContent = '영수증 조회';
        if (data) {
            const KcpPopup = (window as unknown as Record<string, unknown>).KcpReceiptPopup as
                (new (p: { url?: string; cash_url?: string }) => unknown) | undefined;
            if (KcpPopup) new KcpPopup({ url: data.receipt_url ?? undefined });
        }
    });
    receiptBtn.id = BTN_ID;

    // "쇼핑 계속하기" 버튼 앞에 삽입
    const lastBtn = container.lastElementChild;
    container.insertBefore(receiptBtn, lastBtn);

    // 현금영수증 버튼 (가상계좌/계좌이체)
    const cashBtnId = 'kcp-oc-cash-receipt-btn';
    if (!document.getElementById(cashBtnId) && (payment.payment_method === 'vbank' || payment.payment_method === 'bank_transfer')) {
        const cashBtn = makeBtn('현금영수증 조회', receiptBtn.className.replace(/bg-green-\d+/g, 'bg-teal-600').replace(/hover:bg-green-\d+/g, 'hover:bg-teal-700'), async (btn) => {
            btn.disabled = true;
            btn.textContent = '로딩 중...';
            const data = await fetchReceiptUrl(orderNumber);
            btn.disabled = false;
            btn.textContent = '현금영수증 조회';
            if (data) {
                const KcpPopup = (window as unknown as Record<string, unknown>).KcpReceiptPopup as
                    (new (p: { url?: string; cash_url?: string }) => unknown) | undefined;
                if (KcpPopup) new KcpPopup({ cash_url: data.cash_receipt_url ?? undefined });
            }
        });
        cashBtn.id = cashBtnId;
        container.insertBefore(cashBtn, lastBtn);
    }

    console.info(`[${PLUGIN_ID}] receipt button injected on order complete page`);
}

function tryInject(): void {
    const path = location.pathname;
    const ocMatch = path.match(ORDER_COMPLETE_RE);
    if (ocMatch) {
        void injectOnOrderComplete(ocMatch[1]);
    }
    // 마이페이지 주문상세는 mypageOrderShowInjector가 처리
}

export function installOrderCompleteReceiptInjector(): void {
    if (typeof window === 'undefined') return;
    const w = window as Record<string, unknown>;
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] order complete receipt injector installed`);

    const schedule = (delay = 1200) => setTimeout(tryInject, delay);

    // 현재 페이지 즉시 처리
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => schedule());
    } else {
        schedule();
    }

    // SPA 네비게이션 감지
    const origPush = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        origPush(...args);
        schedule();
    };
    window.addEventListener('popstate', () => schedule(500));
}
