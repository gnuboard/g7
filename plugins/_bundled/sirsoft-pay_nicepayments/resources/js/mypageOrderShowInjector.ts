const PLUGIN_ID = 'sirsoft-pay_nicepayments';
const FLAG = '__nicepayOrderShowInjectorInstalled';
const ROW_ID = 'nicepay-mp-receipt-row';
const VBANK_BLOCK_ID = 'nicepay-mp-vbank-info';

const ORDER_SHOW_RE = /^\/mypage\/orders\/([^/]+)$/;

interface Payment {
    pg_provider?: string;
    payment_method?: string;
    transaction_id?: string | null;
    paid_at?: string | null;
    vbank_name?: string | null;
    vbank_number?: string | null;
    vbank_holder?: string | null;
    vbank_due_at?: string | null;
    [key: string]: unknown;
}

interface OrderData {
    order_number?: string;
    total_amount_formatted?: string;
    payment?: Payment;
}

interface ReceiptInfo {
    receipt_url?: string | null;
    payment_method_display_label?: string | null;
}

function getOrderFromState(orderNumber: string): OrderData | null {
    try {
        const g7 = (window as Record<string, unknown>).G7Core as Record<string, unknown> | undefined;
        const getState = g7?.getState as (() => Record<string, unknown>) | undefined;
        const ctx = getState?.()?.currentDataContext as Record<string, unknown> | undefined;
        const order = ctx?.order as { data?: OrderData } | undefined;
        const data = order?.data;
        if (!data || data.order_number !== orderNumber) return null;
        return data;
    } catch {
        return null;
    }
}

function getToken(): string | null {
    return localStorage.getItem('auth_token');
}

async function fetchReceiptInfo(orderNumber: string): Promise<ReceiptInfo | null> {
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

function patchPaymentMethodDisplay(container: Element, displayLabel: string | null | undefined): boolean {
    if (!displayLabel) return false;

    const rows = Array.from(container.querySelectorAll<HTMLElement>('div'));
    for (const row of rows) {
        const spans = Array.from(row.children).filter(
            (child): child is HTMLElement => child instanceof HTMLElement && child.tagName === 'SPAN',
        );
        if (spans.length < 2) continue;

        const label = spans[0].textContent?.trim();
        if (label !== '결제 방법' && label !== '결제수단' && label !== 'Payment Method') continue;

        const value = spans[spans.length - 1];
        if (value.textContent?.trim() !== displayLabel) {
            value.textContent = displayLabel;
            value.dataset.nicepayPaymentMethodPatched = 'true';
        }
        row.dataset.nicepayPaymentMethodRow = 'true';
        return true;
    }

    return false;
}

function findPaymentContainer(): Element | null {
    const panel = document.getElementById('order_payment_info_panel');
    if (panel) {
        return Array.from(panel.children).find(el => el.className?.includes('space-y')) ?? panel;
    }

    const h3 = Array.from(document.querySelectorAll<HTMLElement>('h3')).find(
        el => el.textContent?.includes('결제 정보'),
    );
    if (!h3) return null;

    const panelDiv = h3.parentElement?.parentElement;
    if (!panelDiv) return null;

    return Array.from(panelDiv.children).find(el => el.className?.includes('space-y')) ?? panelDiv;
}

function buildReceiptRow(orderNumber: string): HTMLElement {
    const row = document.createElement('div');
    row.id = ROW_ID;
    row.className = 'flex items-center justify-between';

    const label = document.createElement('span');
    label.className = 'text-gray-500 dark:text-gray-400 text-sm';
    label.textContent = '영수증';

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className =
        'inline-flex items-center gap-1 text-sm text-blue-600 dark:text-blue-400 hover:underline disabled:opacity-50';
    btn.textContent = '영수증 조회';

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = '로딩 중...';
        const data = await fetchReceiptInfo(orderNumber);
        btn.disabled = false;
        btn.textContent = '영수증 조회';
        const url = data?.receipt_url ?? null;
        if (url) {
            window.open(url, 'nicepay_receipt', 'width=800,height=600,scrollbars=yes,resizable=yes');
        }
    });

    row.appendChild(label);
    row.appendChild(btn);
    return row;
}

function formatVbankDueAt(raw: string | null | undefined): string {
    if (!raw) return '';
    try {
        const d = new Date(raw);
        if (Number.isNaN(d.getTime())) return raw;
        const pad = (n: number) => String(n).padStart(2, '0');
        return `${d.getFullYear()}-${pad(d.getMonth() + 1)}-${pad(d.getDate())} ${pad(d.getHours())}:${pad(d.getMinutes())}:${pad(d.getSeconds())}`;
    } catch {
        return raw;
    }
}

function buildVbankRow(label: string, value: string, valueClass = 'font-medium text-gray-900 dark:text-white'): HTMLElement {
    const row = document.createElement('div');
    row.className = 'flex justify-between text-sm';
    const l = document.createElement('span');
    l.className = 'text-gray-600 dark:text-gray-400';
    l.textContent = label;
    const v = document.createElement('span');
    v.className = valueClass;
    v.textContent = value;
    row.appendChild(l);
    row.appendChild(v);
    return row;
}

function buildVbankBlock(orderData: OrderData): HTMLElement {
    const p = orderData.payment ?? {};
    const wrap = document.createElement('div');
    wrap.id = VBANK_BLOCK_ID;
    wrap.className = 'pt-4 mt-2 border-t border-gray-200 dark:border-gray-700';

    const inner = document.createElement('div');
    inner.className = 'bg-blue-50 dark:bg-blue-900/30 border border-blue-200 dark:border-blue-700 rounded-lg p-4';

    const title = document.createElement('h3');
    title.className = 'text-sm font-semibold text-blue-800 dark:text-blue-300 mb-3';
    title.textContent = '가상계좌 입금 안내';

    const rows = document.createElement('div');
    rows.className = 'space-y-2';
    rows.appendChild(buildVbankRow('은행', p.vbank_name ?? ''));
    rows.appendChild(buildVbankRow('계좌번호', p.vbank_number ?? '', 'font-medium font-mono text-gray-900 dark:text-white'));
    rows.appendChild(buildVbankRow('예금주', p.vbank_holder ?? ''));
    rows.appendChild(buildVbankRow('입금 금액', orderData.total_amount_formatted ?? '', 'font-bold text-blue-600 dark:text-blue-400'));

    const dueRaw = p.vbank_due_at;
    if (dueRaw) {
        const dueRow = document.createElement('div');
        dueRow.className = 'flex justify-between text-sm pt-2 border-t border-blue-200 dark:border-blue-700 mt-2';
        const dueLabel = document.createElement('span');
        dueLabel.className = 'text-gray-600 dark:text-gray-400';
        dueLabel.textContent = '입금 기한';
        const dueValue = document.createElement('span');
        dueValue.className = 'font-medium text-red-600 dark:text-red-400';
        dueValue.textContent = formatVbankDueAt(dueRaw);
        dueRow.appendChild(dueLabel);
        dueRow.appendChild(dueValue);
        rows.appendChild(dueRow);
    }

    inner.appendChild(title);
    inner.appendChild(rows);

    if (!p.paid_at) {
        const notice = document.createElement('p');
        notice.className = 'text-xs text-blue-700 dark:text-blue-300 mt-3';
        notice.textContent = '입금 기한 내에 입금이 완료되지 않으면 주문이 자동 취소됩니다.';
        inner.appendChild(notice);
    }

    wrap.appendChild(inner);
    return wrap;
}

async function tryInject(orderNumber: string): Promise<boolean> {
    const orderData = getOrderFromState(orderNumber);
    if (!orderData) return false;

    const { payment } = orderData;
    if (!payment || payment.pg_provider !== 'nicepayments') return true;

    const container = findPaymentContainer();
    if (!container) return false;
    const receiptInfo = await fetchReceiptInfo(orderNumber);
    patchPaymentMethodDisplay(container, receiptInfo?.payment_method_display_label);

    // 가상계좌 입금 안내 — 결제수단이 vbank 이고 가상계좌 번호 발급된 경우 표시 (paid_at 무관: 입금 전후 모두 표시).
    if (payment.payment_method === 'vbank' && payment.vbank_number && !document.getElementById(VBANK_BLOCK_ID)) {
        container.appendChild(buildVbankBlock(orderData));
        console.info(`[${PLUGIN_ID}] vbank info injected on mypage order show`);
    }

    if (!payment.transaction_id) return true;
    // 영수증 버튼은 결제완료(paid_at 채워짐) 시점에만 표시 — 가상계좌 입금대기 차단
    if (!payment.paid_at) return true;

    if (document.getElementById(ROW_ID)) return true;

    container.appendChild(buildReceiptRow(orderNumber));
    console.info(`[${PLUGIN_ID}] receipt button injected on mypage order show`);
    return true;
}

function startPolling(orderNumber: string): void {
    let attempts = 0;
    const id = setInterval(() => {
        attempts++;
        void tryInject(orderNumber).then(done => {
            if (done || attempts >= 30) clearInterval(id);
        });
    }, 400);
}

function onRouteChange(): void {
    const match = location.pathname.match(ORDER_SHOW_RE);
    if (match) startPolling(match[1]);
}

export function installMypageOrderShowInjector(): void {
    if (typeof window === 'undefined') return;
    const w = window as unknown as Record<string, unknown>;
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] mypage order show injector installed`);

    const schedule = (delay = 1500) => setTimeout(onRouteChange, delay);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => schedule());
    } else {
        schedule();
    }

    const origPush = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        origPush(...args);
        schedule(600);
    };
    window.addEventListener('popstate', () => schedule(500));
}
