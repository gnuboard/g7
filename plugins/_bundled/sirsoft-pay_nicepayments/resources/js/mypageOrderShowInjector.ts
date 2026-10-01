import { buildOrderRequestHeaders } from './guestOrderToken';
import { createOrderScreenPoller, enterOrderScreen, requestOncePerOrderScreen } from './orderScreenPolling';

const PLUGIN_ID = 'sirsoft-pay_nicepayments';
const FLAG = '__nicepayOrderShowInjectorInstalled';

// 회원 마이페이지(/mypage/orders/{N}) 와 비회원 주문 상세(/shop/guest/orders/{N}) 를 함께 매칭한다.
// 비회원 경로를 빼면 그 화면에서는 영수증 정보가 조회조차 되지 않는다 (서버는 지원한다).
const ORDER_SHOW_RE = /^(?:\/mypage\/orders\/([^/]+)|\/shop\/guest\/orders\/([^/]+))$/;
// 클레임 신청서 상세도 같은 결제 정보 카드를 재사용한다(§6-2). 그 주소에 실린 것은
// 클레임 번호라 주문번호를 뽑을 수 없으므로, 이 경로에서는 화면이 이미 읽어 둔 주문을
// 그대로 쓴다(주문번호 대조 생략). 이 갈래가 없으면 카드는 뜨는데 간편결제 이름만 빠진 채
// 열리고, 오류도 경고도 남지 않는다. 회원(/mypage/claims/{N})과 비회원
// (/shop/guest/claims/{N}) 신청서 상세가 같은 카드를 쓰므로 둘을 함께 매칭한다 —
// 비회원 쪽은 요청 헤더 도구가 비회원 주문 조회 토큰을 실어 준다.
const CLAIM_SHOW_RE = /^(?:\/mypage\/claims\/([^/]+)|\/shop\/guest\/claims\/([^/]+))$/;

const poller = createOrderScreenPoller();

interface Payment {
    pg_provider?: string;
    [key: string]: unknown;
}

interface OrderData {
    order_number?: string;
    payment?: Payment;
}

interface ReceiptInfo {
    payment_method_display_label?: string | null;
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
    if (value.textContent?.trim() !== displayLabel) {
        value.textContent = displayLabel;
        value.dataset.nicepayPaymentMethodPatched = 'true';
    }
    row.dataset.nicepayPaymentMethodRow = 'true';
    return true;
}

// 결제 정보 카드는 템플릿이 약속한 id(order_payment_info_panel)로만 찾는다. 화면 제목 글자는 언어마다 달라
// 찾는 기준이 될 수 없다 — id 가 없는 템플릿에서는 조용히 붙이지 않는다(약속: sirsoft-ecommerce docs/extension-points.md).
function findPaymentContainer(): Element | null {
    const panel = document.getElementById('order_payment_info_panel');
    if (!panel) return null;

    return Array.from(panel.children).find(el => el.className?.includes('space-y')) ?? panel;
}

// 이 스크립트는 결제 방법 행에 간편결제 이름을 덧붙이는 일만 한다. 가상계좌 입금 안내·영수증 행(결제 영수증·매출전표·
// 현금영수증)은 레이아웃 조각 resources/extensions/user_mypage_order_receipt 가 번역 키로 그린다 — 종전에는 이 스크립트도
// 같은 안내·행을 한국어 고정 글자로 한 번 더 그려 화면에 두 번 나왔다(#599, PO 결정 2026-09-28 「레이아웃 조각만 남김」).
async function tryInject(orderNumber: string | null, isActive: () => boolean): Promise<boolean> {
    const orderData = getOrderFromState(orderNumber);
    if (!orderData) return false;

    // 영수증 API 는 주문번호 기반이다. 클레임 신청서 상세는 주소가 클레임 번호라 주문번호를
    // 상태에서 가져온다 — 주소 세그먼트를 그대로 쓰면 404 가 된다.
    const resolvedOrderNumber = orderData.order_number as string;

    const { payment } = orderData;
    if (!payment || payment.pg_provider !== 'nicepayments') return true;

    const container = findPaymentContainer();
    if (!container) return false;
    // 조회는 화면 진입당 한 번 — 응답을 기다리는 동안 다시 부르지 않고 결과(실패 포함)를 재사용한다.
    const receiptInfo = await requestOncePerOrderScreen(
        `nicepay-receipt:${resolvedOrderNumber}`,
        () => fetchReceiptInfo(resolvedOrderNumber),
    );
    if (!isActive()) return true;
    patchMypagePaymentMethodDisplay(container, receiptInfo?.payment_method_display_label);

    return true;
}

function onRouteChange(): void {
    enterOrderScreen();
    const match = location.pathname.match(ORDER_SHOW_RE);
    // 회원 그룹(match[1]) 또는 비회원 그룹(match[2]) 중 한쪽이 채워진다.
    const segment = match?.[1] ?? match?.[2];
    if (segment) {
        poller.start(location.pathname, isActive => tryInject(segment, isActive));

        return;
    }

    // 클레임 신청서 상세(회원·비회원) — 주소가 클레임 번호라 화면이 읽어 둔 주문을 그대로 쓴다.
    if (CLAIM_SHOW_RE.test(location.pathname)) {
        poller.start(location.pathname, isActive => tryInject(null, isActive));

        return;
    }

    poller.stop();
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
