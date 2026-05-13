/**
 * 토스페이먼츠 결제창 호출 핸들러
 *
 * 체크아웃 레이아웃에서 주문 생성 API 성공 후 호출됩니다:
 *   handler: "sirsoft-tosspayments.requestPayment"
 *   params: { pgPaymentData: response.data.pg_payment_data }
 *
 * 호출 순서:
 *   1. Client Config API 호출 → clientKey 획득
 *   2. TossPayments SDK 동적 로드 (미로드 시)
 *   3. SDK 초기화 + payment 인스턴스 생성
 *   4. payment.requestPayment() 호출 → 통합결제창 오픈
 *   5. 결제 완료 시 브라우저가 successUrl/failUrl로 리다이렉트
 */

/* eslint-disable @typescript-eslint/no-explicit-any */

interface PgPaymentData {
    order_number: string;
    order_name: string;
    amount: number;
    currency?: string;
    customer_name?: string;
    customer_email?: string;
    customer_phone?: string;
    customer_key?: string | null;
}

interface RequestPaymentParams {
    pgPaymentData: PgPaymentData;
}

interface ClientConfig {
    client_key: string;
    sdk_url: string;
    callback_urls: {
        success: string;
        fail: string;
    };
}

declare global {
    interface Window {
        TossPayments: any;
    }
}

/**
 * 스크립트를 동적으로 로드합니다.
 *
 * @param src 스크립트 URL
 * @returns Promise
 */
function loadScript(src: string): Promise<void> {
    return new Promise((resolve, reject) => {
        // 이미 로드된 스크립트인지 확인
        if (document.querySelector(`script[src="${src}"]`)) {
            resolve();
            return;
        }

        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error(`Failed to load script: ${src}`));
        document.head.appendChild(script);
    });
}

/**
 * 토스페이먼츠 결제창 호출 핸들러
 *
 * ActionDispatcher는 커스텀 핸들러를 (action, context) 시그니처로 호출합니다.
 * params는 action.params에서 접근해야 합니다.
 *
 * @param action 액션 정의 (handler, params 등)
 * @param _context 액션 컨텍스트
 */
export async function requestPaymentHandler(action: any, _context?: any): Promise<void> {
    const { pgPaymentData } = (action.params || {}) as RequestPaymentParams;

    if (!pgPaymentData) {
        console.error('[sirsoft-tosspayments] pgPaymentData is required');
        return;
    }

    const G7Core = (window as any).G7Core;

    try {
        // 1. Client Config API 호출
        const configJson = await G7Core.api.get('/modules/sirsoft-ecommerce/payments/client-config/tosspayments');

        if (!configJson.data) {
            console.error('[sirsoft-tosspayments] Failed to fetch client config', configJson);
            return;
        }

        const config: ClientConfig = configJson.data;

        // 2. SDK 동적 로드 (미로드 시)
        if (!window.TossPayments) {
            await loadScript(config.sdk_url);
        }

        // SDK 로드 대기 (스크립트 로드 후 TossPayments 객체 초기화까지 약간의 시간 소요)
        if (!window.TossPayments) {
            await new Promise<void>((resolve) => setTimeout(resolve, 100));
        }

        if (!window.TossPayments) {
            console.error('[sirsoft-tosspayments] TossPayments SDK not available');
            return;
        }

        // 3. SDK 초기화
        const tossPayments = window.TossPayments(config.client_key);
        const payment = tossPayments.payment({
            customerKey: pgPaymentData.customer_key ?? window.TossPayments.ANONYMOUS,
        });

        // 4. 결제 요청 (통합결제창)
        const origin = window.location.origin;

        await payment.requestPayment({
            method: 'CARD',
            amount: {
                currency: pgPaymentData.currency ?? 'KRW',
                value: pgPaymentData.amount,
            },
            orderId: pgPaymentData.order_number,
            orderName: pgPaymentData.order_name,
            successUrl: origin + config.callback_urls.success,
            failUrl: origin + config.callback_urls.fail,
            customerEmail: pgPaymentData.customer_email ?? undefined,
            customerName: pgPaymentData.customer_name ?? undefined,
            customerMobilePhone: pgPaymentData.customer_phone ?? undefined,
            card: {
                useEscrow: false,
                flowMode: 'DEFAULT',
                useCardPoint: false,
                useAppCardOnly: false,
            },
        });
        // → 브라우저가 successUrl 또는 failUrl로 리다이렉트됨

    } catch (error: any) {
        console.error('[sirsoft-tosspayments] requestPayment error', error);

        // SDK에서 사용자 취소 시 에러가 발생할 수 있음
        if (error?.code === 'USER_CANCEL') {
            console.info('[sirsoft-tosspayments] Payment cancelled by user');

            // 1. 결제 취소 이력 기록 API 호출 (PG사 응답값 전달)
            try {
                await G7Core.api.post(
                    `/modules/sirsoft-ecommerce/orders/${pgPaymentData.order_number}/cancel-payment`,
                    {
                        cancel_code: error.code,
                        cancel_message: error.message,
                    }
                );
            } catch (e) {
                console.warn('[sirsoft-tosspayments] Failed to record cancellation', e);
            }

            // 2. 로딩 상태 해제 + 취소 안내 모달 표시
            G7Core?.state?.setLocal?.({ isSubmittingOrder: false });
            G7Core?.modal?.open?.('tosspayments_payment_cancel_modal');
            return;
        }

        // 기타 에러 시 모달로 오류 표시 (체크아웃 페이지 유지)
        const errorMessage = error?.message ?? 'Unknown error';
        G7Core?.state?.setLocal?.({ paymentErrorMessage: errorMessage, isSubmittingOrder: false });
        G7Core?.modal?.open?.('tosspayments_payment_error_modal');
    }
}
