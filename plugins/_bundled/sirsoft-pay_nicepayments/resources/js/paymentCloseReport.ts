import { apiUrl } from './apiUrl';
export interface PaymentCloseReportContext {
    closeReportUrl?: string;
    oid: string;
    price: number;
    buyer_email?: string;
    buyer_phone?: string;
    payment_method?: string;
}

function resolveApiUrl(url: string): string {
    // 절대 URL 은 그대로. 나머지는 `/api/…` 로 정규화한 뒤 **사이트 마운트 접두사**를
    // 붙인다 — 붙이지 않으면 하위 폴더 배치에서 결제 종료 보고가 사이트 밖으로 나가고,
    // 실패해도 화면에 아무 흔적이 남지 않는다 (12차 G3 라운드).
    if (/^https?:\/\//i.test(url)) {
        return url;
    }

    if (url.startsWith('/api/')) {
        return apiUrl(url);
    }

    if (url.startsWith('/plugins/')) {
        return apiUrl(`/api${url}`);
    }

    if (url.startsWith('plugins/')) {
        return apiUrl(`/api/${url}`);
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

        // base-path:allow resolveApiUrl() 이 정규화 끝에서 apiUrl() 을 거친다
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
