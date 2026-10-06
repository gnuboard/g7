import { buildGuestOrderTokenHeaders } from './guestOrderToken';

/**
 * 결제창 닫힘 보고 주소 — 이 플러그인의 API 경로(G7Core.api 기준 상대 경로, 실제 요청은 /api/plugins/...).
 *
 * client-config 의 close_report_url 은 쓰지 않는다. 브라우저가 부르는 자기 API 라 같은 출처 상대 경로가 맞고,
 * 사이트 절대 주소로 부르면 별칭 Host 로 들어온 화면에서 교차 출처가 되어 Bearer 가 실리지 않는다.
 */
export const CLOSE_REPORT_PATH = '/plugins/sirsoft-pay_nicepayments/payment/close-report';

export interface PaymentCloseReportContext {
    oid: string;
    price: number;
    payment_method?: string;
}

function trimReason(reason: string): string {
    return reason.trim().slice(0, 160);
}

/**
 * 결제창이 결제 없이 닫혔음을 서버에 보고합니다.
 *
 * 서버는 주문 소유자(회원 로그인 / 비회원 조회 토큰)만 받아 준다. 회원 Bearer 와 401 처리는 G7Core.api 가
 * 맡고, 비회원은 공개 상태 계약 `_global.guestOrderToken` 을 헤더로 싣는다. 실패해도 예외를 흘리지 않는다 —
 * 보고가 거부된 결제대기 주문은 만료 정리 잡이 정리한다.
 *
 * @param context 보고할 주문 정보
 * @param reason 닫힘 사유 (160자까지)
 */
export async function reportPaymentWindowClosed(
    context: PaymentCloseReportContext,
    reason = 'nicepay-window-closed',
): Promise<void> {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    const apiClient = (window as any).G7Core?.api;
    if (typeof apiClient?.post !== 'function') {
        console.warn('[sirsoft-pay_nicepayments] G7Core.api unavailable — payment window close not reported');
        return;
    }

    const payload = {
        oid: context.oid,
        price: Number(context.price),
        payment_method: context.payment_method ?? '',
        reason: trimReason(reason) || 'nicepay-window-closed',
    };
    const ownershipHeaders = buildGuestOrderTokenHeaders();

    try {
        if (Object.keys(ownershipHeaders).length > 0) {
            await apiClient.post(CLOSE_REPORT_PATH, payload, { headers: ownershipHeaders });
        } else {
            await apiClient.post(CLOSE_REPORT_PATH, payload);
        }
    } catch (error) {
        console.warn('[sirsoft-pay_nicepayments] failed to report payment window close', error);
    }
}
