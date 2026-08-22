// e2e:allow PG 영수증 DOM 주입 — 외부 PG(이니시스) 결제 데이터 의존으로 브라우저 E2E 재현 불가, Vitest 회귀(__tests__/mypageOrderShowInjector.test.ts)로 검증
import {
    canOpenKginicisReceipt,
    fetchKginicisReceiptInfo,
    fetchKginicisReceiptInfoDetailed,
    KginicisReceiptInfo,
    openKginicisReceipt,
    receiptButtonLabel,
    receiptRowLabel,
} from './receiptPopup';

const PLUGIN_ID = 'sirsoft-pay_kginicis';
const FLAG = '__kginicisOrderShowInjectorInstalled';
const ROW_ID = 'kginicis-mp-receipt-row';

// 회원 마이페이지(/mypage/orders/{N}) + 비회원 주문 상세(/shop/guest/orders/{N}) 두 URL 매칭.
// 두 페이지가 동일 _payment.json partial 을 공유하므로 DOM 구조도 동일 — 같은 injector 가 양쪽 처리.
// 단 URL 세그먼트의 의미가 다르다: 회원 경로는 주문 ID, 비회원 경로는 주문번호.
const ORDER_SHOW_RE = /^(?:\/mypage\/orders\/([^/]+)|\/shop\/guest\/orders\/([^/]+))$/;

const activePolls = new Map<string, number>();

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

function getOrderFromState(routeSegment: string): OrderData | null {
    try {
        const g7 = (window as Record<string, unknown>).G7Core as Record<string, unknown> | undefined;
        const getState = g7?.getState as (() => Record<string, unknown>) | undefined;
        const ctx = getState?.()?.currentDataContext as Record<string, unknown> | undefined;
        const order = ctx?.order as { data?: OrderData } | undefined;
        const data = order?.data;
        if (!data) return null;
        // 회원 경로 세그먼트는 주문 ID, 비회원 경로 세그먼트는 주문번호 — 양쪽 모두 허용.
        const matches = data.order_number === routeSegment
            || (data.id !== undefined && String(data.id) === routeSegment);
        return matches ? data : null;
    } catch {
        return null;
    }
}

function findPaymentContainer(): Element | null {
    const panel = document.getElementById('order_payment_info_panel');
    if (panel) {
        return Array.from(panel.children).find(el => el.className?.includes('space-y')) ?? panel;
    }

    const h3 = Array.from(document.querySelectorAll<HTMLElement>('h3')).find(
        el => {
            const text = el.textContent?.trim() ?? '';
            return text.includes('결제 정보') || /^Payment Information$/i.test(text);
        },
    );
    if (!h3) return null;

    const panelDiv = h3.parentElement?.parentElement;
    if (!panelDiv) return null;

    return Array.from(panelDiv.children).find(el => el.className?.includes('space-y')) ?? panelDiv;
}

export function patchMypagePaymentMethodDisplay(container: Element, displayLabel: string | null | undefined): boolean {
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
        btn.textContent = '로딩 중...';
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

async function tryInject(routeSegment: string, isGuestRoute: boolean): Promise<boolean> {
    const orderData = getOrderFromState(routeSegment);
    const payment = orderData?.payment;
    if (payment) {
        if (payment.pg_provider !== 'kginicis') return true;
        if (!payment.transaction_id) return true;
    }

    // 영수증 API 는 주문번호 기반인데 회원 경로 세그먼트는 주문 ID 라 그대로 쓰면 404 확정.
    // 회원 경로는 state 에서 실제 주문번호를 확보하기 전까지 네트워크 호출 없이 재시도만 한다.
    const receiptOrderNumber = orderData?.order_number ?? (isGuestRoute ? routeSegment : null);
    if (!receiptOrderNumber) return false;

    // 회원 경로에서 주문 데이터에 PG 결제 정보 자체가 없으면 영수증 대상이 아니다.
    if (!isGuestRoute && orderData && !payment) return true;

    const container = findPaymentContainer();
    if (!container) return false;

    const { status, info: paymentInfo } = await fetchKginicisReceiptInfoDetailed(receiptOrderNumber);

    // 404 = kginicis 결제 이력 없음(확정 응답) → 회원 경로는 즉시 폴링 중단.
    // 비회원 경로는 게스트 토큰 준비 지연으로 일시 404 가 가능해 기존 재시도를 유지한다.
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

function startPolling(routeSegment: string, isGuestRoute: boolean): void {
    if (activePolls.has(routeSegment)) return;

    let attempts = 0;
    const id = window.setInterval(() => {
        attempts++;
        void tryInject(routeSegment, isGuestRoute).then(done => {
            if (done || attempts >= 30) {
                window.clearInterval(id);
                activePolls.delete(routeSegment);
            }
        });
    }, 400);
    activePolls.set(routeSegment, id);
}

function onRouteChange(): void {
    const match = location.pathname.match(ORDER_SHOW_RE);
    if (match) {
        // 회원 그룹(match[1] = 주문 ID) 또는 비회원 그룹(match[2] = 주문번호) 중 한 쪽이 채워진다.
        const memberSegment = match[1];
        const guestSegment = match[2];
        const segment = memberSegment ?? guestSegment;
        if (segment) startPolling(segment, guestSegment !== undefined);
    }
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
        if (ORDER_SHOW_RE.test(location.pathname)) schedule(250);
    });
    observer.observe(observeTarget, { childList: true, subtree: true });
}
