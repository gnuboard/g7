export interface PaymentCloseReportContext {
    closeReportUrl?: string;
    oid: string;
    price: number;
    buyer_email?: string;
    buyer_phone?: string;
    payment_method?: string;
}

function resolveApiUrl(url: string): string {
    if (/^https?:\/\//i.test(url) || url.startsWith('/api/')) {
        return url;
    }

    if (url.startsWith('/plugins/')) {
        return `/api${url}`;
    }

    if (url.startsWith('plugins/')) {
        return `/api/${url}`;
    }

    return url;
}

function trimReason(reason: string): string {
    return reason.trim().slice(0, 160);
}

export async function reportPaymentWindowClosed(
    context: PaymentCloseReportContext,
    reason = 'nicepay-window-closed',
): Promise<void> {
    if (!context.closeReportUrl) {
        return;
    }

    const payload = {
        oid: context.oid,
        price: Number(context.price),
        buyer_email: context.buyer_email ?? '',
        buyer_phone: context.buyer_phone ?? '',
        payment_method: context.payment_method ?? '',
        reason: trimReason(reason) || 'nicepay-window-closed',
    };

    try {
        const apiClient = ((window as any).G7Core)?.api;
        if (typeof apiClient?.post === 'function') {
            await apiClient.post(context.closeReportUrl, payload);
            return;
        }

        await fetch(resolveApiUrl(context.closeReportUrl), {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(payload),
            keepalive: true,
        });
    } catch (error) {
        console.warn('[sirsoft-pay_nicepayments] failed to report payment window close', error);
    }
}
