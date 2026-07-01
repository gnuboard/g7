const PLUGIN_ID = 'sirsoft-pay_kginicis';
const FLAG = '__kginicisAdminPaymentDisplayInjectorInstalled';

const ADMIN_ORDER_SHOW_RE = /^\/admin\/ecommerce\/orders\/([^/]+)$/;

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
        return ('data' in json ? json.data : json) ?? null;
    } catch {
        return null;
    }
}

function directElementChildren(el: Element): HTMLElement[] {
    return Array.from(el.children).filter(
        (child): child is HTMLElement => child instanceof HTMLElement,
    );
}

function patchLabeledPaymentMethod(root: Element, displayLabel: string): boolean {
    let patched = false;

    const rows = Array.from(root.querySelectorAll<HTMLElement>('div'));
    for (const row of rows) {
        const children = directElementChildren(row);
        const labelIndex = children.findIndex(child => child.textContent?.trim() === '결제수단');
        if (labelIndex < 0) continue;

        const value = children
            .slice(labelIndex + 1)
            .find(child => child.textContent?.trim() && child.textContent.trim() !== '결제수단');
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
            value.dataset.kginicisPaymentMethodPatched = 'true';
        }
        row.dataset.kginicisPaymentMethodRow = 'admin-order';
        if (installment && installmentText) {
            installment.textContent = '';
            installment.style.display = 'none';
            installment.dataset.kginicisPaymentInstallmentHidden = 'true';
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
    badge.dataset.kginicisPaymentMethodPatched = 'true';
    badge.dataset.kginicisPaymentMethodBadge = 'admin-order';
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

async function tryInject(orderNumber: string): Promise<boolean> {
    const root = document.getElementById('section_payment_info') ?? document.getElementById('payment_info_card');
    if (!root) return false;

    const status = await fetchAdminTransactionStatus(orderNumber);
    if (!status) return true;

    const patched = patchAdminPaymentMethodDisplay(root, status);
    if (patched) {
        console.info(`[${PLUGIN_ID}] payment method display patched on admin order detail`);
    }

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
    const match = location.pathname.match(ADMIN_ORDER_SHOW_RE);
    if (match) startPolling(match[1]);
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
        origPush(...args);
        schedule(600);
    };
    window.addEventListener('popstate', () => schedule(500));
}
