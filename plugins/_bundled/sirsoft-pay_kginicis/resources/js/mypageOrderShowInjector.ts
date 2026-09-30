// e2e:allow PG 영수증 DOM 주입 — 외부 PG(이니시스) 결제 데이터 의존으로 브라우저 E2E 재현 불가, Vitest 회귀(__tests__/mypageOrderShowInjector.test.ts)로 검증
import {
    canOpenKginicisReceipt,
    fetchKginicisReceiptInfo,
    fetchKginicisReceiptInfoForOrderScreen,
    KginicisReceiptInfo,
    openKginicisReceipt,
    receiptButtonLabel,
    receiptRowLabel,
    translateKginicis,
} from './receiptPopup';
import { createOrderScreenPoller, enterOrderScreen, isOrderDataLoading } from './orderScreenPolling';

const PLUGIN_ID = 'sirsoft-pay_kginicis';
const FLAG = '__kginicisOrderShowInjectorInstalled';
const ROW_ID = 'kginicis-mp-receipt-row';

// 회원 마이페이지(/mypage/orders/{N}) + 비회원 주문 상세(/shop/guest/orders/{N}) 두 URL 매칭.
// 두 페이지가 동일 _payment.g7.html partial 을 공유하므로 DOM 구조도 동일 — 같은 injector 가 양쪽 처리.
// 두 경로 모두 URL 세그먼트는 주문번호다(라우트 :order_number).
const ORDER_SHOW_RE = /^(?:\/mypage\/orders\/([^/]+)|\/shop\/guest\/orders\/([^/]+))$/;
// 클레임 신청서 상세도 같은 결제 정보 카드를 재사용한다(§6-2). 그 주소에 실린 것은 클레임
// 번호라 주문번호도 주문 ID 도 뽑을 수 없으므로, 이 경로에서는 화면이 이미 읽어 둔 주문을
// 그대로 쓴다(세그먼트 대조 생략). 이 갈래가 없으면 카드는 뜨는데 영수증·세금내역만 빠진 채
// 열리고, 오류도 경고도 남지 않는다. 회원(/mypage/claims/{N})과 비회원(/shop/guest/claims/{N})
// 신청서 상세가 같은 카드를 쓰므로 둘을 함께 매칭한다 — 비회원 쪽은 영수증 조회가 비회원 주문
// 조회 토큰을 실어 보낸다(receiptPopup).
const CLAIM_SHOW_RE = /^(?:\/mypage\/claims\/([^/]+)|\/shop\/guest\/claims\/([^/]+))$/;

const poller = createOrderScreenPoller();

interface Payment {
    pg_provider?: string;
    payment_status?: string;
    payment_method?: string;
    transaction_id?: string | null;
    [key: string]: unknown;
}

interface OrderData {
    id?: number | string;
    order_number?: string;
    total_amount_formatted?: string;
    payment?: Payment;
}

function getOrderFromState(routeSegment: string | null): OrderData | null {
    try {
        const g7 = (window as unknown as Record<string, unknown>).G7Core as Record<string, unknown> | undefined;
        const getState = g7?.getState as (() => Record<string, unknown>) | undefined;
        const ctx = getState?.()?.currentDataContext as Record<string, unknown> | undefined;
        const order = ctx?.order as { data?: OrderData } | undefined;
        const data = order?.data;
        if (!data) return null;
        // 주소가 주문을 가리키지 않는 화면(클레임 신청서 상세)에서는 대조 없이 그대로 쓴다.
        if (routeSegment === null) return data.order_number ? data : null;
        // 세그먼트는 주문번호다. 주문 ID 로 들어온 옛 링크도 받아 준다.
        const matches = data.order_number === routeSegment
            || (data.id !== undefined && String(data.id) === routeSegment);
        return matches ? data : null;
    } catch {
        return null;
    }
}

// 결제 정보 카드는 템플릿이 약속한 id(order_payment_info_panel)로만 찾는다. 화면 제목 글자는 언어마다 달라
// 찾는 기준이 될 수 없다 — id 가 없는 템플릿에서는 조용히 붙이지 않는다(약속: sirsoft-ecommerce docs/extension-points.md).
function findPaymentContainer(): Element | null {
    const panel = document.getElementById('order_payment_info_panel');
    if (!panel) return null;

    return Array.from(panel.children).find(el => el.className?.includes('space-y')) ?? panel;
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
        row.dataset.kginicisPaymentMethodRow = 'true';
        return true;
    }

    value.textContent = displayLabel;
    value.dataset.kginicisPaymentMethodPatched = 'true';
    row.dataset.kginicisPaymentMethodRow = 'true';
    return true;
}

function buildReceiptRow(orderNumber: string, receiptInfo: KginicisReceiptInfo): HTMLElement {
    const row = document.createElement('div');
    row.id = ROW_ID;
    row.className = 'flex items-center justify-between';
    row.dataset.kginicisReceiptRow = 'mypage-order';

    const label = document.createElement('span');
    label.className = 'text-gray-500 dark:text-gray-400 text-sm';
    label.textContent = receiptRowLabel(receiptInfo);

    const btn = document.createElement('button');
    btn.type = 'button';
    btn.dataset.kginicisReceiptButton = 'mypage-order';
    btn.className =
        'inline-flex items-center gap-1 text-sm text-blue-600 dark:text-blue-400 hover:underline disabled:opacity-50';
    btn.textContent = receiptButtonLabel(receiptInfo);

    btn.addEventListener('click', async () => {
        btn.disabled = true;
        btn.textContent = translateKginicis('user.loading', '로딩 중...');
        const latestReceiptInfo = await fetchKginicisReceiptInfo(orderNumber);
        btn.disabled = false;
        btn.textContent = receiptButtonLabel(latestReceiptInfo ?? receiptInfo);
        if (canOpenKginicisReceipt(latestReceiptInfo)) {
            openKginicisReceipt(latestReceiptInfo);
        }
    });

    row.appendChild(label);
    row.appendChild(btn);
    return row;
}

async function tryInject(routeSegment: string | null, isGuestRoute: boolean, isActive: () => boolean): Promise<boolean> {
    // 주문을 불러오는 중이면 기다린다 — 그 사이 비회원 경로가 다른 결제대행사 주문의 영수증을 조회하지 않게.
    if (isOrderDataLoading()) return false;
    const orderData = getOrderFromState(routeSegment);
    const payment = orderData?.payment;
    if (payment) {
        if (payment.pg_provider !== 'kginicis') return true;
        if (!payment.transaction_id) return true;
    }

    // 회원 경로는 state 에서 주문을 확인하기 전까지 네트워크 호출 없이 재시도만 한다.
    // 비회원 경로는 state 를 못 읽는 환경에서도 경로의 주문번호로 조회한다.
    const receiptOrderNumber = orderData?.order_number ?? (isGuestRoute ? routeSegment : null);
    if (!receiptOrderNumber) return false;

    // 회원 경로에서 주문 데이터에 PG 결제 정보 자체가 없으면 영수증 대상이 아니다.
    if (!isGuestRoute && orderData && !payment) return true;

    const container = findPaymentContainer();
    if (!container) return false;

    // 조회는 화면 진입당 한 번 — 재렌더로 폴러가 다시 돌아도 같은 결과를 재사용한다.
    const { status, info: paymentInfo } = await fetchKginicisReceiptInfoForOrderScreen(receiptOrderNumber);
    if (!isActive()) return true;

    // 404 = kginicis 결제 이력 없음(확정 응답) → 회원 경로는 즉시 폴링 중단.
    // 비회원 경로는 게스트 토큰 준비 지연으로 일시 404 가 가능해 폴링을 이어 간다 —
    // 같은 토큰으로는 다시 조회하지 않고, 토큰이 준비되면 그때 한 번 더 조회한다.
    if (!isGuestRoute && status === 404) return true;

    const isPaid = payment?.payment_status === 'paid';
    const isCbtConfirmation = paymentInfo?.receipt_type === 'cbt_confirmation';
    if (payment && !isPaid && !isCbtConfirmation) return true;
    if (!payment && !canOpenKginicisReceipt(paymentInfo)) return false;

    const patched = patchMypagePaymentMethodDisplay(
        container,
        paymentInfo?.payment_method_display_label,
    );

    if (!document.getElementById(ROW_ID) && canOpenKginicisReceipt(paymentInfo)) {
        container.appendChild(buildReceiptRow(receiptOrderNumber, paymentInfo));
        console.info(`[${PLUGIN_ID}] receipt button injected on mypage order show`);
    }

    if (patched) {
        console.info(`[${PLUGIN_ID}] payment method display patched on mypage order show`);
    }

    return Boolean(document.getElementById(ROW_ID) || patched);
}

function onRouteChange(): void {
    enterOrderScreen();
    const match = location.pathname.match(ORDER_SHOW_RE);
    // 회원 그룹(match[1]) 또는 비회원 그룹(match[2]) 중 한 쪽이 채워진다 — 둘 다 주문번호.
    const memberSegment = match?.[1];
    const guestSegment = match?.[2];
    const segment = memberSegment ?? guestSegment;
    if (segment) {
        const isGuestRoute = guestSegment !== undefined;
        poller.start(location.pathname, isActive => tryInject(segment, isGuestRoute, isActive));

        return;
    }

    // 클레임 신청서 상세 — 주소가 클레임 번호라 화면이 읽어 둔 주문을 그대로 쓴다.
    // 비회원 경로(claim[2])는 토큰 준비 지연으로 일시 404 가 날 수 있어 비회원 재시도 규칙을 탄다.
    const claim = location.pathname.match(CLAIM_SHOW_RE);
    if (claim) {
        const isGuestClaim = claim[2] !== undefined;
        poller.start(location.pathname, isActive => tryInject(null, isGuestClaim, isActive));

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

    let pendingRouteCheck: number | null = null;
    const schedule = (delay = 1500) => {
        if (pendingRouteCheck !== null) {
            window.clearTimeout(pendingRouteCheck);
        }
        pendingRouteCheck = window.setTimeout(() => {
            pendingRouteCheck = null;
            onRouteChange();
        }, delay);
    };

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
    const origReplace = history.replaceState.bind(history);
    history.replaceState = (...args: Parameters<typeof history.replaceState>) => {
        origReplace(...args);
        schedule(600);
    };
    window.addEventListener('popstate', () => schedule(500));

    const observeTarget = document.getElementById('app') ?? document.body;
    const observer = new MutationObserver(() => {
        if (ORDER_SHOW_RE.test(location.pathname) || CLAIM_SHOW_RE.test(location.pathname)) schedule(250);
    });
    observer.observe(observeTarget, { childList: true, subtree: true });
}
