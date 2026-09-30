import {
    createOrderScreenPoller,
    enterOrderScreen,
    readSharedDataSource,
    rememberLeavingDataSources,
    requestOncePerOrderScreen,
} from './orderScreenPolling';

const PLUGIN_ID = 'sirsoft-pay_nhnkcp';
const FLAG = '__nhnkcpAdminPaymentDisplayInjectorInstalled';

const ADMIN_ORDER_SHOW_RE = /^\/admin\/ecommerce\/orders\/([^/]+)$/;

const poller = createOrderScreenPoller();

/** 같은 주소를 받는 레이아웃 확장(admin_order_payment_query)의 데이터 소스 */
const STATUS_DATA_SOURCE = 'kcp_status';
/** 데이터 소스 값을 기다리는 최대 틱 수(400ms 간격) — 넘으면 직접 조회한다 */
const SHARED_STATUS_WAIT_TICKS = 20;

interface AdminTransactionStatus {
    _pay_method_label?: string | null;
    _base_pay_method_label?: string | null;
    _embedded_pg_provider_label?: string | null;
}

function getToken(): string | null {
    return localStorage.getItem('auth_token');
}

async function fetchAdminTransactionStatus(orderNumber: string): Promise<AdminTransactionStatus | null> {
    const token = getToken();
    if (!token) return null;

    try {
        const res = await fetch(`/api/plugins/${PLUGIN_ID}/admin/orders/${orderNumber}/transaction-status`, {
            headers: { Authorization: `Bearer ${token}`, Accept: 'application/json' },
        });
        if (!res.ok) return null;

        const json = (await res.json()) as { data?: AdminTransactionStatus | null } | AdminTransactionStatus;
        return unwrapStatus(json);
    } catch {
        return null;
    }
}

function unwrapStatus(json: unknown): AdminTransactionStatus | null {
    if (!json || typeof json !== 'object') return null;
    const body = json as { data?: AdminTransactionStatus | null } | AdminTransactionStatus;
    return ('data' in body ? body.data : body) ?? null;
}

function directElementChildren(el: Element): HTMLElement[] {
    return Array.from(el.children).filter(
        (child): child is HTMLElement => child instanceof HTMLElement,
    );
}

function patchLabeledPaymentMethod(root: Element, displayLabel: string): boolean {
    let patched = false;

    // 결제수단 칸은 이커머스 관리자 주문 상세가 약속한 id(payment_method_block_{결제 순번})로만 찾는다. 칸 이름 글자
    // (「결제수단」)는 관리자 언어마다 달라 찾는 기준이 될 수 없다(약속: sirsoft-ecommerce docs/extension-points.md).
    const rows = Array.from(root.querySelectorAll<HTMLElement>('[id^="payment_method_block_"]'));
    for (const row of rows) {
        const children = directElementChildren(row);
        // 첫 칸이 이름, 그 뒤 처음으로 글자가 있는 칸이 값이다.
        const labelIndex = 0;
        if (children.length < 2) continue;

        const value = children
            .slice(labelIndex + 1)
            .find(child => child.textContent?.trim());
        if (!value) continue;

        const installment = children
            .slice(labelIndex + 2)
            .find(child => /^\(.+\)$/.test(child.textContent?.trim() ?? ''));
        const installmentText = installment?.textContent?.trim().replace(/^\(|\)$/g, '') ?? '';
        const nextDisplayLabel = installmentText && displayLabel.endsWith(')')
            ? displayLabel.replace(/\)$/, `, ${installmentText})`)
            : displayLabel;

        if (value.textContent?.trim() !== nextDisplayLabel) {
            value.textContent = nextDisplayLabel;
            value.dataset.nhnkcpPaymentMethodPatched = 'true';
        }
        row.dataset.nhnkcpPaymentMethodRow = 'admin-order';
        if (installment && installmentText) {
            installment.textContent = '';
            installment.style.display = 'none';
            installment.dataset.nhnkcpPaymentInstallmentHidden = 'true';
        }
        patched = true;
    }

    return patched;
}

function patchPaymentMethodBadge(root: Element, baseLabel: string, embeddedLabel: string): boolean {
    const candidates = Array.from(root.querySelectorAll<HTMLElement>('span')).filter(
        el => el.textContent?.trim() === baseLabel,
    );

    const badge = candidates.find(el => {
        const className = typeof el.className === 'string' ? el.className : '';
        return className.includes('rounded-full') || className.includes('font-medium');
    });

    if (!badge) return false;

    badge.textContent = embeddedLabel;
    badge.dataset.nhnkcpPaymentMethodPatched = 'true';
    badge.dataset.nhnkcpPaymentMethodBadge = 'admin-order';
    return true;
}

export function patchAdminPaymentMethodDisplay(
    root: Element,
    status: AdminTransactionStatus | null | undefined,
): boolean {
    const displayLabel = status?._pay_method_label;
    const embeddedLabel = status?._embedded_pg_provider_label;
    const baseLabel = status?._base_pay_method_label;

    if (!displayLabel || !embeddedLabel) return false;

    const labeled = patchLabeledPaymentMethod(root, displayLabel);
    const badge = baseLabel ? patchPaymentMethodBadge(root, baseLabel, embeddedLabel) : false;

    return labeled || badge;
}

async function tryInject(orderNumber: string, isActive: () => boolean, waitedTicks: () => number): Promise<boolean> {
    const root = document.getElementById('section_payment_info') ?? document.getElementById('payment_info_card');
    if (!root) return false;

    // 레이아웃 확장이 받은 값이 있으면 그대로 쓴다. 엔진이 있는데 아직 값이 없으면 잠시 기다리고,
    // 그래도 없으면(확장 미적용·조회 실패) 직접 조회한다 — 화면 진입당 한 번, 실패(null)면 그대로 끝낸다.
    const shared = readSharedDataSource(STATUS_DATA_SOURCE);
    if (shared.state === 'waiting' && waitedTicks() < SHARED_STATUS_WAIT_TICKS) return false;
    const status = shared.state === 'ready'
        ? unwrapStatus(shared.value)
        : await requestOncePerOrderScreen(
            `admin-transaction-status:${orderNumber}`,
            () => fetchAdminTransactionStatus(orderNumber),
        );
    if (!isActive() || !status) return true;

    const patched = patchAdminPaymentMethodDisplay(root, status);
    if (patched) {
        console.info(`[${PLUGIN_ID}] payment method display patched on admin order detail`);
    }

    return true;
}

function onRouteChange(): void {
    enterOrderScreen();
    const match = location.pathname.match(ADMIN_ORDER_SHOW_RE);
    if (!match) {
        poller.stop();
        return;
    }

    const orderNumber = match[1];
    let ticks = 0;
    poller.start(orderNumber, isActive => tryInject(orderNumber, isActive, () => ++ticks));
}

export function installAdminOrderPaymentDisplayInjector(): void {
    if (typeof window === 'undefined') return;
    const w = window as unknown as Record<string, unknown>;
    if (w[FLAG]) return;
    w[FLAG] = true;

    console.info(`[${PLUGIN_ID}] admin order payment display injector installed`);

    const schedule = (delay = 1500) => setTimeout(onRouteChange, delay);

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => schedule());
    } else {
        schedule();
    }

    const origPush = history.pushState.bind(history);
    history.pushState = (...args: Parameters<typeof history.pushState>) => {
        rememberLeavingDataSources([STATUS_DATA_SOURCE]);
        origPush(...args);
        schedule(600);
    };
    window.addEventListener('popstate', () => {
        rememberLeavingDataSources([STATUS_DATA_SOURCE]);
        schedule(500);
    });
}
