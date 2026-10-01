import { buildGuestOwnershipHeaders, isSameOriginUrl } from './guestOrderToken';

export interface PaymentCloseReportContext {
    closeReportUrl?: string;
    oid: string;
    price: number;
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
        payment_method: context.payment_method ?? '',
        reason: trimReason(reason) || 'nicepay-window-closed',
    };

    // 서버는 주문 소유자(회원 로그인 / 비회원 조회 토큰)만 받아 준다. 회원 Bearer 는 G7Core.api 가
    // 싣고, 비회원은 체크아웃이 저장해 둔 조회 토큰을 헤더로 싣는다.
    const ownershipHeaders = buildGuestOwnershipHeaders();

    try {
        const apiClient = ((window as any).G7Core)?.api;
        if (typeof apiClient?.post === 'function') {
            if (Object.keys(ownershipHeaders).length > 0) {
                await apiClient.post(context.closeReportUrl, payload, { headers: ownershipHeaders });
            } else {
                await apiClient.post(context.closeReportUrl, payload);
            }
            return;
        }

        const url = resolveApiUrl(context.closeReportUrl);
        await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                ...(isSameOriginUrl(url) ? ownershipHeaders : {}),
            },
            body: JSON.stringify(payload),
            keepalive: true,
        });
    } catch (error) {
        console.warn('[sirsoft-pay_nicepayments] failed to report payment window close', error);
    }
}
