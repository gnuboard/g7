import { buildOrderRequestHeaders } from './guestOrderToken';
import { translateNhnkcp as t } from './translate';

const PLUGIN_ID = 'sirsoft-pay_nhnkcp';
const FLAG = '__kcpMpShowInjectorInstalled';
const VBANK_ID = 'kcp-mp-vbank-row';
const ROW_ID = 'kcp-mp-receipt-row';
const MOCK_DEPOSIT_ID = 'kcp-mp-mock-deposit';

// 회원 마이페이지(/mypage/orders/{N}) 와 비회원 주문 상세(/shop/guest/orders/{N}) 를 함께 매칭한다.
// 비회원 경로를 빼면 그 화면에서는 영수증 정보가 조회조차 되지 않는다 (서버는 지원한다).
const ORDER_SHOW_RE = /^(?:\/mypage\/orders\/([^/]+)|\/shop\/guest\/orders\/([^/]+))$/;
// 클레임 신청서 상세도 같은 결제 정보 카드를 재사용한다(§6-2). 그 주소에 실린 것은
// 클레임 번호라 주문번호를 뽑을 수 없으므로, 이 경로에서는 화면이 이미 읽어 둔 주문을
// 그대로 쓴다(주문번호 대조 생략). 이 갈래가 없으면 카드는 뜨는데 이 블록만 빠진 채
// 열리고, 오류도 경고도 남지 않는다. 회원(/mypage/claims/{N})과 비회원
// (/shop/guest/claims/{N}) 신청서 상세가 같은 카드를 쓰므로 둘을 함께 매칭한다 —
// 비회원 쪽은 요청 헤더 도구가 비회원 주문 조회 토큰을 실어 준다.
const CLAIM_SHOW_RE = /^(?:\/mypage\/claims\/([^/]+)|\/shop\/guest\/claims\/([^/]+))$/;

interface Payment {
    pg_provider?: string;
    payment_method?: string;
    transaction_id?: string | null;
    paid_at?: string | null;
    vbank_name?: string;
    vbank_number?: string;
    vbank_holder?: string;
    due_date_formatted?: string;
    [key: string]: unknown;
}

interface OrderData {
    order_number?: string;
    total_amount_formatted?: string;
    payment?: Payment;
}

function getOrderFromState(orderNumber: string | null): OrderData | null {
    try {
        const g7 = (window as unknown as Record<string, unknown>).G7Core as Record<string, unknown> | undefined;
        const getState = g7?.getState as (() => Record<string, unknown>) | undefined;
        const ctx = getState?.()?.currentDataContext as Record<string, unknown> | undefined;
        const order = ctx?.order as { data?: OrderData } | undefined;
        const data = order?.data;
        if (!data) return null;
        if (orderNumber !== null && data.order_number !== orderNumber) return null;
        if (orderNumber === null && !data.order_number) return null;
        return data;
    } catch {
        return null;
    }
}


interface ReceiptInfo {
    receipt_url?: string;
    cash_receipt_url?: string | null;
    payment_method_display_label?: string | null;
}

async function fetchReceiptUrls(orderNumber: string): Promise<ReceiptInfo | null> {
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

interface MockDepositInfo {
    available: boolean;
    trade_no?: string;
    account_no?: string;
    notify_url?: string;
    mock_url?: string;
    is_admin_view?: boolean;
}

async function fetchMockDepositInfo(orderNumber: string): Promise<MockDepositInfo | null> {
    const headers = buildOrderRequestHeaders();
    if (!headers) return null;
    try {
        const res = await fetch(`/api/plugins/${PLUGIN_ID}/user/orders/${orderNumber}/vbank-mock-deposit-info`, {
            headers,
            credentials: 'same-origin',
        });
        if (!res.ok) return null;
        // 응답은 공통 형식({ success, message, data }) — 모의입금 정보는 data 에 있다.
        const body = (await res.json()) as { data?: MockDepositInfo | null };
        return body?.data ?? null;
    } catch {
        return null;
    }
}

function buildMockDepositBlock(info: MockDepositInfo): HTMLElement {
    const wrap = document.createElement('div');
    wrap.id = MOCK_DEPOSIT_ID;
    wrap.className = 'pt-4 mt-2 border-t border-dashed border-orange-300 dark:border-orange-700';

    const header = document.createElement('div');
    header.className = 'flex items-center gap-2 mb-3';

    const badge = document.createElement('span');
    badge.className = 'inline-flex items-center px-2 py-0.5 rounded text-xs font-bold bg-orange-100 text-orange-700 dark:bg-orange-900/40 dark:text-orange-300 border border-orange-300 dark:border-orange-700';
    badge.textContent = 'TEST';

    const title = document.createElement('p');
    title.className = 'text-sm font-semibold text-orange-700 dark:text-orange-300';
    title.textContent = t('user.mock_title', '모의입금처리');

    header.appendChild(badge);
    header.appendChild(title);

    const form = document.createElement('form');
    form.method = 'POST';
    form.action = info.mock_url ?? '';
    form.target = '_blank';
    form.className = 'space-y-2';

    const fields: Array<{ label: string; name: string; value: string; editable?: boolean }> = [
        { label: t('user.mock_trade_no', 'KCP 거래번호'), name: 'e_trade_no', value: info.trade_no ?? '' },
        { label: t('user.mock_deposit_no', '입금계좌'), name: 'deposit_no', value: info.account_no ?? '' },
        { label: t('user.mock_depositor', '입금자명'), name: 'req_name', value: 'NHN KCP', editable: true },
        { label: t('user.mock_notify_url', '입금통보 URL'), name: 'noti_url', value: info.notify_url ?? '' },
    ];

    for (const f of fields) {
        const row = document.createElement('div');
        row.className = 'flex items-center gap-2 text-sm';

        const lbl = document.createElement('span');
        lbl.className = 'w-28 flex-shrink-0 text-gray-500 dark:text-gray-400 text-xs';
        lbl.textContent = f.label;

        const input = document.createElement('input');
        input.type = 'text';
        input.name = f.name;
        input.value = f.value;
        input.readOnly = !f.editable;
        input.className = f.editable
            ? 'flex-1 px-2 py-1 text-xs border border-gray-300 dark:border-gray-600 rounded bg-white dark:bg-gray-700 text-gray-900 dark:text-white font-mono'
            : 'flex-1 px-2 py-1 text-xs border border-gray-200 dark:border-gray-700 rounded bg-gray-50 dark:bg-gray-800 text-gray-700 dark:text-gray-300 font-mono';

        row.appendChild(lbl);
        row.appendChild(input);
        form.appendChild(row);
    }

    const submitRow = document.createElement('div');
    submitRow.className = 'pt-1';

    const btn = document.createElement('button');
    btn.type = 'submit';
    btn.className = 'w-full py-1.5 text-xs font-semibold rounded border border-orange-400 dark:border-orange-600 bg-orange-50 dark:bg-orange-900/30 text-orange-700 dark:text-orange-300 hover:bg-orange-100 dark:hover:bg-orange-900/50 transition-colors';
    btn.textContent = t('user.mock_submit', '입금통보 테스트');

    submitRow.appendChild(btn);
    form.appendChild(submitRow);

    wrap.appendChild(header);
    wrap.appendChild(form);

    if (info.is_admin_view) {
        const adminNotice = document.createElement('p');
        adminNotice.className = 'mt-2 text-xs text-center text-orange-500 dark:text-orange-400 font-medium';
        adminNotice.textContent = t('user.mock_admin_notice', '관리자에게만 출력되는 내용');
        wrap.appendChild(adminNotice);
    }

    return wrap;
}

// 결제 정보 카드는 템플릿이 약속한 id(order_payment_info_panel)로만 찾는다. 화면 제목 글자는 언어마다 달라
// 찾는 기준이 될 수 없다 — id 가 없는 템플릿에서는 조용히 붙이지 않는다(약속: sirsoft-ecommerce docs/extension-points.md).
function findPaymentRowsContainer(): Element | null {
    const panel = document.getElementById('order_payment_info_panel');
    if (!panel) return null;

    return Array.from(panel.children).find(el => el.className?.includes('space-y')) ?? panel;
}

// 라벨 + 값 행 생성 헬퍼
function makeInfoRow(label: string, value: string, valueClass = 'font-medium text-gray-900 dark:text-white'): HTMLElement {
    const row = document.createElement('div');
    row.className = 'flex items-center justify-between text-sm';

    const lbl = document.createElement('span');
    lbl.className = 'text-gray-500 dark:text-gray-400';
    lbl.textContent = label;

    const val = document.createElement('span');
    val.className = valueClass;
    val.textContent = value;

    row.appendChild(lbl);
    row.appendChild(val);
    return row;
}

export function patchMypagePaymentMethodDisplay(container: Element, displayLabel: string | null | undefined): boolean {
    if (!displayLabel) return false;

    // 결제 방법 행은 템플릿이 약속한 id(order_payment_method_row)로만 찾는다. 행 이름 글자(「결제 방법」 등)는 언어마다
    // 달라 찾는 기준이 될 수 없다 — id 가 없는 템플릿에서는 건드리지 않는다(약속: sirsoft-ecommerce docs/extension-points.md).
    const row = container.querySelector<HTMLElement>('#order_payment_method_row');
    if (!row) return false;

    const spans = Array.from(row.children).filter(
        (child): child is HTMLElement => child instanceof HTMLElement && child.tagName === 'SPAN',
    );
    if (spans.length < 2) return false;

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

function buildVbankInfoBlock(payment: Payment, totalAmountFormatted: string): HTMLElement {
    const wrap = document.createElement('div');
    wrap.id = VBANK_ID;
    wrap.className = 'pt-4 mt-2 border-t border-gray-200 dark:border-gray-700 space-y-3';

    const title = document.createElement('p');
    title.className = 'text-sm font-semibold text-gray-800 dark:text-gray-200';
    title.textContent = t('user.vbank_title', '가상계좌 입금 안내');

    const rows = document.createElement('div');
    rows.className = 'space-y-1.5';
    rows.appendChild(makeInfoRow(t('user.vbank_bank_name', '은행'), payment.vbank_name ?? ''));
    rows.appendChild(makeInfoRow(t('user.vbank_account', '계좌번호'), payment.vbank_number ?? '', 'font-medium text-gray-900 dark:text-white font-mono tracking-wide'));
    rows.appendChild(makeInfoRow(t('user.vbank_holder', '예금주'), payment.vbank_holder ?? ''));
    rows.appendChild(makeInfoRow(t('user.vbank_amount', '입금 금액'), totalAmountFormatted));
    if (payment.due_date_formatted) {
        rows.appendChild(makeInfoRow(t('user.vbank_due', '입금 기한'), payment.due_date_formatted));
    }

    const notice = document.createElement('p');
    notice.className = 'text-xs text-amber-600 dark:text-amber-400';
    notice.textContent = t('user.vbank_notice', '입금 기한 내에 입금하지 않으시면 주문이 자동 취소됩니다.');

    wrap.appendChild(title);
    wrap.appendChild(rows);
    wrap.appendChild(notice);
    return wrap;
}

function buildReceiptRow(orderNumber: string): HTMLElement {
    const row = document.createElement('div');
    row.id = ROW_ID;
    row.className = 'pt-4 mt-2 border-t border-gray-200 dark:border-gray-700';

    const inner = document.createElement('div');
    inner.className = 'flex items-center justify-between';

    const label = document.createElement('span');
    label.className = 'text-gray-500 dark:text-gray-400 text-sm';
    label.textContent = t('user.receipt_label', '영수증');

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'inline-flex items-center gap-1 text-sm text-blue-600 dark:text-blue-400 hover:underline disabled:opacity-50';
    const viewLabel = t('user.receipt_view', '영수증 조회');
    btn.textContent = viewLabel;

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = t('user.loading', '로딩 중...');
        const data = await fetchReceiptUrls(orderNumber);
        btn.disabled = false;
        btn.textContent = viewLabel;
        if (data) {
            const KcpPopup = (window as unknown as Record<string, unknown>).KcpReceiptPopup as
                (new (p: { url?: string; cash_url?: string | null }) => unknown) | undefined;
            if (KcpPopup) {
                new KcpPopup({ url: data.receipt_url, cash_url: data.cash_receipt_url });
            }
        }
    });

    inner.appendChild(label);
    inner.appendChild(btn);
    row.appendChild(inner);
    return row;
}

async function tryInject(orderNumber: string | null): Promise<boolean> {
    const orderData = getOrderFromState(orderNumber);

    if (!orderData) {
        // ctx.order가 null — 관리자가 타인 주문 열람 시 mypage API 404로 상태 없음.
        // 컨테이너가 렌더링됐으면 모의입금 API만 직접 시도 후 종료.
        // 주소가 주문을 가리키지 않는 화면(클레임 신청서 상세)에서는 부를 주문번호가 없다 —
        // 주문이 아직 안 실렸다는 뜻이므로 재시도한다.
        if (orderNumber === null) return false;

        if (!document.getElementById(MOCK_DEPOSIT_ID)) {
            const container = findPaymentRowsContainer();
            if (!container) return false; // 컨테이너 미렌더링 → 재시도
            const info = await fetchMockDepositInfo(orderNumber);
            if (info?.available) {
                container.appendChild(buildMockDepositBlock(info));
                console.info(`[${PLUGIN_ID}] mock deposit form injected on mypage order show`);
            }
        }
        return true;
    }

    const { payment } = orderData;
    if (!payment || payment.pg_provider !== 'nhnkcp') return true; // nhnkcp 아님

    // 영수증·모의입금 API 는 주문번호 기반이다. 클레임 신청서 상세는 주소가 클레임 번호라
    // 주문번호를 상태에서 가져온다 — 주소 세그먼트를 그대로 쓰면 404 가 된다.
    const resolvedOrderNumber = orderData.order_number as string;

    const needsVbank = payment.payment_method === 'vbank' && !!payment.vbank_name;
    // 영수증 버튼은 결제완료(paid_at 채워짐) 시점에만 표시 — 가상계좌 입금대기 상태 차단
    const needsReceipt = !!payment.transaction_id && !!payment.paid_at;
    // 가상계좌 계좌번호가 발급된 경우 API로 모의입금 가능 여부 확인 (tno는 서버에서 fallback)
    const mightNeedMock = payment.payment_method === 'vbank' && !!payment.vbank_number;

    const vbankDone    = !needsVbank    || !!document.getElementById(VBANK_ID);
    const receiptDone  = !needsReceipt  || !!document.getElementById(ROW_ID);
    // 모의입금은 API 결과에 따라 달라지므로 DOM에 없으면 매번 체크
    const mockDone     = !mightNeedMock || !!document.getElementById(MOCK_DEPOSIT_ID);

    if (vbankDone && receiptDone && mockDone) return true;

    const container = findPaymentRowsContainer();
    if (!container) return false; // DOM 미렌더링 → 재시도

    const receiptInfo = needsReceipt ? await fetchReceiptUrls(resolvedOrderNumber) : null;
    const displayPatched = patchMypagePaymentMethodDisplay(
        container,
        receiptInfo?.payment_method_display_label,
    );

    if (!document.getElementById(VBANK_ID) && needsVbank) {
        container.appendChild(buildVbankInfoBlock(payment, orderData.total_amount_formatted ?? ''));
        console.info(`[${PLUGIN_ID}] vbank info injected on mypage order show`);
        return false;
    }

    if (!document.getElementById(ROW_ID) && needsReceipt) {
        container.appendChild(buildReceiptRow(resolvedOrderNumber));
        console.info(`[${PLUGIN_ID}] receipt button injected on mypage order show`);
        return false;
    }

    if (displayPatched) {
        console.info(`[${PLUGIN_ID}] payment method display patched on mypage order show`);
    }

    if (!document.getElementById(MOCK_DEPOSIT_ID) && mightNeedMock) {
        const info = await fetchMockDepositInfo(resolvedOrderNumber);
        if (info?.available) {
            container.appendChild(buildMockDepositBlock(info));
            console.info(`[${PLUGIN_ID}] mock deposit form injected on mypage order show`);
        } else {
            // 테스트 모드 아님 or 조건 불충족 — 더 이상 시도 불필요
            return true;
        }
    }

    return true;
}

function startPolling(orderNumber: string | null): void {
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
    // 회원 그룹(match[1]) 또는 비회원 그룹(match[2]) 중 한쪽이 채워진다.
    const segment = match?.[1] ?? match?.[2];
    if (segment) {
        startPolling(segment);

        return;
    }

    // 클레임 신청서 상세(회원·비회원) — 주소가 클레임 번호라 화면이 읽어 둔 주문을 그대로 쓴다.
    if (CLAIM_SHOW_RE.test(location.pathname)) startPolling(null);
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
