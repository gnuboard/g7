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

interface EscrowProduct {
    id: string;
    name: string;
    code: string;
    unitPrice: number;
    quantity: number;
}

interface PgPaymentData {
    order_number: string;
    order_name: string;
    amount: number;
    currency?: string;
    customer_name?: string;
    customer_email?: string;
    customer_phone?: string;
    customer_key?: string | null;
    escrow_products?: EscrowProduct[];
}

interface RequestPaymentParams {
    pgPaymentData: PgPaymentData;
    // 주문서형에서 사용자가 선택한 결제수단 id (toss_*). 미지정 시 _local.paymentMethod 참조.
    paymentMethod?: string;
}

interface EnabledMethod {
    id: string;
    method: string;
    easy_pay_provider: string | null;
    core_payment_method: string;
}

interface ClientConfig {
    client_key: string;
    sdk_url: string;
    order_sheet_mode?: boolean;
    enabled_methods?: EnabledMethod[];
    vbank?: {
        valid_hours: number;
        cash_receipt_type: string;
    };
    use_escrow?: string;
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
/**
 * 선택된 결제수단 id 를 SDK 파라미터로 변환합니다.
 *
 * 서버가 내려준 enabled_methods 에서 선택 id 를 찾아 SDK method / easyPay provider 를
 * 결정한다. 프론트는 결제수단 매핑을 하드코딩하지 않는다 (서버가 SSoT).
 * order_sheet_mode 가 off 이거나 선택 id 를 못 찾으면 통합결제창 카드(CARD)로 처리한다.
 *
 * @param config 클라이언트 설정 (enabled_methods 포함)
 * @param selectedId 선택된 toss_* 결제수단 id
 * @returns SDK method 와 easyPay provider
 */
function resolveMethod(config: ClientConfig, selectedId?: string): { method: string; easyPay: string | null } {
    if (!config.order_sheet_mode || !selectedId) {
        return { method: 'CARD', easyPay: null };
    }

    const entry = (config.enabled_methods ?? []).find((m) => m.id === selectedId);
    if (!entry) {
        return { method: 'CARD', easyPay: null };
    }

    return { method: entry.method, easyPay: entry.easy_pay_provider };
}

/**
 * use_escrow 설정 문자열을 SDK useEscrow boolean/undefined 로 변환합니다.
 *
 * off → false, on → true, buyer_choice → undefined(키 생략 → 결제창에서 구매자 선택).
 *
 * @param useEscrow 설정값 (off | on | buyer_choice)
 * @returns SDK useEscrow 값 (undefined 면 키를 넣지 않음)
 */
function resolveEscrowFlag(useEscrow?: string): boolean | undefined {
    if (useEscrow === 'on') return true;
    if (useEscrow === 'off') return false;
    // buyer_choice (또는 미설정) → 키 자체를 생략
    return undefined;
}

/**
 * 가상계좌 SDK 페이로드를 구성합니다.
 *
 * @param config 클라이언트 설정 (vbank / use_escrow 포함)
 * @param pgPaymentData PG 결제 데이터 (escrow_products 포함)
 * @returns virtualAccount 페이로드
 */
function buildVirtualAccountPayload(config: ClientConfig, pgPaymentData: PgPaymentData): any {
    const vbank: any = {
        validHours: config.vbank?.valid_hours ?? 24,
    };

    // 현금영수증 자동 발급 유형 (설정 시에만)
    const receiptType = config.vbank?.cash_receipt_type ?? '';
    if (receiptType) {
        vbank.cashReceipt = { type: receiptType };
    }

    // 에스크로 (off → false, on → true, buyer_choice → 키 생략)
    const useEscrow = resolveEscrowFlag(config.use_escrow);
    if (useEscrow !== undefined) {
        vbank.useEscrow = useEscrow;
    }

    return vbank;
}

/**
 * 에스크로 사용 시 필수인 escrowProducts 배열을 페이로드에 부착합니다.
 *
 * use_escrow 가 off 가 아니고(강제 on 또는 구매자 선택) escrow_products 가 있으면 부착한다.
 *
 * @param payload 결제 요청 페이로드 (변경됨)
 * @param config 클라이언트 설정
 * @param pgPaymentData PG 결제 데이터
 * @returns void
 */
function attachEscrowProducts(payload: any, config: ClientConfig, pgPaymentData: PgPaymentData): void {
    if (config.use_escrow === 'off') {
        return;
    }

    const products = pgPaymentData.escrow_products ?? [];
    if (products.length > 0) {
        payload.escrowProducts = products;
    }
}

export async function requestPaymentHandler(action: any, _context?: any): Promise<void> {
    const params = (action.params || {}) as RequestPaymentParams;
    const { pgPaymentData } = params;

    if (!pgPaymentData) {
        console.error('[sirsoft-tosspayments] pgPaymentData is required');
        return;
    }

    const G7Core = (window as any).G7Core;

    // 선택된 결제수단 id — action.params 우선, 없으면 _local.paymentMethod 참조
    const selectedMethodId: string | undefined =
        params.paymentMethod ?? G7Core?.state?.getLocal?.()?.paymentMethod;

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

        // 4. 결제수단 결정 + 비KRW 차단
        const currency = pgPaymentData.currency ?? 'KRW';
        const { method, easyPay } = resolveMethod(config, selectedMethodId);

        // 토스 국내 전용 수단(가상계좌·계좌이체·휴대폰·간편결제)은 비KRW 결제 불가. 카드만 허용.
        const domesticOnly = method !== 'CARD' || easyPay !== null;
        if (currency !== 'KRW' && domesticOnly) {
            console.warn('[sirsoft-tosspayments] non-KRW currency supports card only', { currency, method });
            G7Core?.state?.setLocal?.({
                paymentErrorMessage: G7Core?.t?.('sirsoft-tosspayments.errors.non_krw_method') ?? 'This payment method is available for KRW only.',
                isSubmittingOrder: false,
            });
            G7Core?.modal?.open?.('tosspayments_payment_error_modal');
            return;
        }

        // 5. 결제 요청 페이로드 조립
        const origin = window.location.origin;
        const requestPayload: any = {
            method,
            amount: {
                currency,
                value: pgPaymentData.amount,
            },
            orderId: pgPaymentData.order_number,
            orderName: pgPaymentData.order_name,
            successUrl: origin + config.callback_urls.success,
            failUrl: origin + config.callback_urls.fail,
            customerEmail: pgPaymentData.customer_email ?? undefined,
            customerName: pgPaymentData.customer_name ?? undefined,
            customerMobilePhone: pgPaymentData.customer_phone ?? undefined,
        };

        if (method === 'CARD') {
            requestPayload.card = {
                flowMode: 'DEFAULT',
                useCardPoint: false,
                useAppCardOnly: false,
            };
            // 간편결제는 card 객체 안에 easyPay(간편결제사 코드) + flowMode DIRECT 로
            // 자체창을 직행 호출한다 (토스 v2 결제창 계약 — 카드사/간편결제 자체창 연동).
            // top-level easyPay 키는 SDK 파라미터가 아니라서 무시되고 통합결제창이 열린다.
            if (easyPay) {
                requestPayload.card.flowMode = 'DIRECT';
                requestPayload.card.easyPay = easyPay;
            }
        } else if (method === 'VIRTUAL_ACCOUNT') {
            // 가상계좌 — 에스크로 적용 대상 (escrowProducts 는 에스크로 사용 시 필수)
            requestPayload.virtualAccount = buildVirtualAccountPayload(config, pgPaymentData);
            attachEscrowProducts(requestPayload, config, pgPaymentData);
        } else if (method === 'TRANSFER') {
            // 계좌이체 — 에스크로만 적용 대상
            const useEscrow = resolveEscrowFlag(config.use_escrow);
            if (useEscrow !== undefined) {
                requestPayload.transfer = { useEscrow };
            }
            attachEscrowProducts(requestPayload, config, pgPaymentData);
        }

        await payment.requestPayment(requestPayload);
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
