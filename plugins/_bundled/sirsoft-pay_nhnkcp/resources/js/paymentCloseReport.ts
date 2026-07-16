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

function resolveRetryUrl(closeReportUrl?: string): string | undefined {
    if (!closeReportUrl) {
        return undefined;
    }

    return closeReportUrl.replace(/\/payment\/close-report$/, '/payment/retry');
}

async function postPaymentContext(url: string, payload: Record<string, unknown>, keepalive = false): Promise<void> {
    const apiClient = ((window as any).G7Core)?.api;
    if (typeof apiClient?.post === 'function') {
        await apiClient.post(url, payload);
        return;
    }

    const response = await fetch(resolveApiUrl(url), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(payload),
        keepalive,
    });

    if (!response.ok) {
        let message = `NHN KCP payment request failed (${response.status})`;
        try {
            const data = await response.json();
            const responseMessage = Array.isArray(data?.errors?.message)
                ? data.errors.message[0]
                : data?.error ?? data?.message;
            if (typeof responseMessage === 'string' && responseMessage.trim() !== '') {
                message = responseMessage;
            }
        } catch {
            // JSON 응답이 아니면 HTTP 상태 메시지를 사용한다.
        }
        throw new Error(message);
    }
}

export async function preparePaymentRetry(context: PaymentCloseReportContext): Promise<void> {
    const retryUrl = resolveRetryUrl(context.closeReportUrl);
    if (!retryUrl) {
        return;
    }

    await postPaymentContext(retryUrl, {
        oid: context.oid,
        price: Number(context.price),
        buyer_email: context.buyer_email ?? '',
        buyer_phone: context.buyer_phone ?? '',
        payment_method: context.payment_method ?? '',
    });
}

export async function reportPaymentWindowClosed(
    context: PaymentCloseReportContext,
    reason = 'kcp-window-closed',
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
        reason: trimReason(reason) || 'kcp-window-closed',
    };

    try {
        await postPaymentContext(context.closeReportUrl, payload, true);
    } catch (error) {
        console.warn('[sirsoft-pay_nhnkcp] failed to report payment window close', error);
    }
}
