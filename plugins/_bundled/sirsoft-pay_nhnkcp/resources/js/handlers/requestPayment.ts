/* eslint-disable @typescript-eslint/no-explicit-any */

import {
    PaymentCloseReportContext,
    preparePaymentRetry,
    rememberPendingClose,
    reportPaymentWindowClosed,
} from '../paymentCloseReport';
import {
    applePayUnsupportedMessage,
    isIosMobileDevice,
    isNhnKcpApplePayMethod,
} from '../support/applePayDevice';

interface PgPaymentData {
    order_number: string;
    order_name: string;
    amount: number;
    currency?: string;
    pay_method?: string;
    /**
     * 서버가 저장한 결제수단 ID — 확장 수단이면 확장 ID 그대로(예: 'nhnkcp_naverpay').
     * 확장 결제수단이 1급 시민이 되면서 서버가 원본 수단을 알고 내려준다(#475).
     */
    payment_method?: string;
    customer_name?: string;
    customer_email?: string;
    customer_phone?: string;
    /** 복합과세 분할용 */
    tax_amount?: number;
    vat_amount?: number;
    tax_free_amount?: number;
}

interface RequestPaymentParams {
    pgPaymentData: PgPaymentData;
    paymentMethod?: string;
}

function normalizeCurrency(currency?: string): string {
    const normalized = (currency ?? 'KRW').trim().toUpperCase();

    return normalized || 'KRW';
}

export function isSupportedKcpCurrency(currency?: string): boolean {
    return normalizeCurrency(currency) === 'KRW';
}

/**
 * SDK 스크립트를 로드할 수 있는 호스트 (plugin.json `trusted_script_hosts` 미러).
 *
 * NHN KCP 결제창은 라이브러리가 아니라 그 회사 서버와 통신하는 서비스 SDK 라
 * 자체 호스팅할 수 없다. 대신 **주입 직전에** 호스트를 확인해, 설정·응답이 어떤
 * 경로로든 다른 주소를 지시하면 결제를 진행하지 않는다(fail-closed).
 *
 * PG사가 SDK 호스트를 바꾸면 이 상수와 plugin.json 을 **함께** 갱신한다 —
 * 둘이 어긋나면 테스트가 실패한다.
 */
export const KNOWN_SDK_HOSTS: readonly string[] = ['testpay.kcp.co.kr', 'pay.kcp.co.kr'];

/**
 * 번역 문자열을 얻습니다.
 *
 * @param key 번역 키 (플러그인 네임스페이스 이하)
 * @param fallback 번역 엔진 부재 시 사용할 문구
 * @returns 번역된 문자열
 */
function t(key: string, fallback: string): string {
    const translate = (window as any)?.G7Core?.t;

    if (typeof translate !== 'function') {
        return fallback;
    }

    const full = `sirsoft-pay_nhnkcp.${key}`;
    const result = translate(full);

    return typeof result === 'string' && result !== full ? result : fallback;
}

/**
 * SDK URL 이 신뢰 호스트인지 확인하고, 아니면 예외를 던집니다.
 *
 * @param url 주입할 SDK URL
 * @throws Error 미신뢰 호스트이거나 https 가 아닌 경우
 */
export function assertTrustedSdkUrl(url: string): void {
    let parsed: URL | null = null;

    try {
        parsed = new URL(url);
    } catch {
        parsed = null;
    }

    if (
        parsed === null
        || parsed.protocol !== 'https:'
        || !KNOWN_SDK_HOSTS.includes(parsed.hostname.toLowerCase())
    ) {
        throw new Error(
            t('payment.error.sdk_url_untrusted', '결제 모듈 주소가 올바르지 않아 결제를 진행할 수 없습니다.')
        );
    }
}

function unsupportedCurrencyMessage(currency?: string): string {
    const normalized = normalizeCurrency(currency);
    const isKo = ((typeof document !== 'undefined' ? document.documentElement.lang : '') || '')
        .toLowerCase()
        .startsWith('ko');

    return isKo
        ? `NHN KCP는 KRW 결제만 지원합니다. (${normalized})`
        : `NHN KCP supports KRW payments only. (${normalized})`;
}

export function buildKcpTaxFields(pgPaymentData: PgPaymentData): Record<string, string> {
    const paymentAmount = Math.max(0, Math.round(Number(pgPaymentData.amount ?? 0)));
    const taxFreeAmt = Math.min(
        Math.max(0, Math.round(Number(pgPaymentData.tax_free_amount ?? 0))),
        paymentAmount,
    );

    if (taxFreeAmt <= 0) {
        return {};
    }

    const taxTotalAmt = Math.max(0, Math.round(Number(pgPaymentData.tax_amount ?? 0)));
    const originalVatAmt = Math.max(0, Math.round(Number(pgPaymentData.vat_amount ?? 0)));
    const taxablePaymentAmt = Math.max(0, paymentAmount - taxFreeAmt);
    const vatAmt = taxablePaymentAmt > 0 && taxTotalAmt > 0 && originalVatAmt > 0
        ? Math.min(taxablePaymentAmt, Math.round(taxablePaymentAmt * (originalVatAmt / taxTotalAmt)))
        : 0;
    const supplyAmt = taxablePaymentAmt - vatAmt;

    return {
        tax_flag: 'TG03',
        comm_tax_mny: String(supplyAmt),
        comm_vat_mny: String(vatAmt),
        comm_free_mny: String(taxFreeAmt),
    };
}

interface ClientConfig {
    client_id: string;
    easy_pay_client_id?: string;
    sdk_url: string;
    callback_urls: {
        callback: string;
        close_report?: string;
    };
    vbank_expire_days?: number;
    use_escrow?: boolean;
    escrow_client_id?: string;
}

// 사용자가 결제창을 직접 닫은 경우 - 에러 모달 없이 조용히 처리
class KcpCancelledError extends Error {
    constructor(msg?: string) {
        super(msg ?? '결제가 취소되었습니다.');
        this.name = 'KcpCancelledError';
    }
}

// KCP pay_method 비트마스크 변환 (PC: payplus_web.jsp 규격)
const KCP_PAY_METHOD: Record<string, string> = {
    card:            '100000000000', // 신용카드
    bank_transfer:   '010000000000', // 계좌이체
    virtual_account: '001000000000', // 가상계좌
    mobile:          '000010000000', // 휴대폰결제
    bank:            '010000000000',
    vbank:           '001000000000',
    phone:           '000010000000',
};

// KCP 간편결제 direct 파라미터 (PC/모바일 공통)
const KCP_EASY_PAY_DIRECT: Record<string, Record<string, string>> = {
    nhnkcp_payco:          { payco_direct: 'Y' },
    nhnkcp_naverpay:       { naverpay_direct: 'Y' },
    nhnkcp_naverpay_point: { naverpay_direct: 'Y', naverpay_point_direct: 'Y' },
    nhnkcp_kakaopay:       { kakaopay_direct: 'Y' },
    nhnkcp_applepay:       { applepay_direct: 'Y' },
};

export function buildKcpEasyPayReturnFields(paymentMethod: string, isEasyPay: boolean): Record<string, string> {
    const methodKey = paymentMethod.toLowerCase();
    if (!isEasyPay || !Object.prototype.hasOwnProperty.call(KCP_EASY_PAY_DIRECT, methodKey)) {
        return {};
    }

    return {
        param_opt_1: methodKey,
        nhnkcp_easy_pay_method: methodKey,
    };
}

function isKcpPaymentFrameClosed(iframe: HTMLIFrameElement): boolean {
    if (!iframe.isConnected) {
        return true;
    }

    const style = window.getComputedStyle(iframe);
    if (style.display === 'none' || style.visibility === 'hidden') {
        return true;
    }

    const rect = iframe.getBoundingClientRect();
    return rect.width <= 0 || rect.height <= 0;
}

export function watchKcpPaymentFrameClosure(
    iframe: HTMLIFrameElement,
    onClose: () => void,
): () => void {
    let stopped = false;
    let intervalId: number | undefined;
    let observer: MutationObserver | undefined;

    const stop = () => {
        stopped = true;
        if (observer) {
            observer.disconnect();
            observer = undefined;
        }
        if (intervalId !== undefined) {
            window.clearInterval(intervalId);
            intervalId = undefined;
        }
    };

    const checkClosed = () => {
        if (stopped || !isKcpPaymentFrameClosed(iframe)) {
            return;
        }

        stop();
        onClose();
    };

    const observeTarget = document.body ?? document.documentElement;
    if (observeTarget && typeof MutationObserver !== 'undefined') {
        observer = new MutationObserver(checkClosed);
        observer.observe(observeTarget, {
            attributes: true,
            attributeFilter: ['class', 'hidden', 'style'],
            childList: true,
            subtree: true,
        });
    }

    intervalId = window.setInterval(checkClosed, 300);

    return stop;
}

/**
 * 모바일 기기 여부 판별 (3단계 fallback)
 *
 * 1) User Agent Client Hints — 브라우저가 직접 판단 (Chrome/Edge 90+)
 * 2) UA 문자열 파싱
 * 3) iPadOS 등 데스크탑 UA를 보내는 터치 기기 판별 (maxTouchPoints > 1)
 */
function isMobileDevice(): boolean {
    if (typeof navigator === 'undefined') return false;
    if (isIosMobileDevice()) return true;

    const nav = navigator as Navigator & { userAgentData?: { mobile: boolean } };
    if (nav.userAgentData?.mobile !== undefined) {
        return nav.userAgentData.mobile;
    }

    const ua = (navigator.userAgent || '').toLowerCase();
    if (/android|iphone|ipod|windows phone|iemobile|blackberry|opera mini|mobile safari/.test(ua)) {
        return true;
    }

    // iPadOS, Android 태블릿 등 — 터치스크린 노트북은 maxTouchPoints=1이므로 >1 조건 필요
    const touchPoints = (navigator as Navigator & { maxTouchPoints?: number }).maxTouchPoints ?? 0;
    if (touchPoints > 1 && !ua.includes('windows') && !ua.includes('macintosh')) {
        return true;
    }

    return false;
}

/**
 * NHN KCP 결제창 호출 핸들러
 *
 * - 모바일: SOAP approval_key 획득 → form POST(페이지 전환) → 기존 authCallback 처리
 * - PC: payplus_web.jsp SDK를 iframe 내 동기 로드 → KCP_Pay_Execute() → 콜백
 */
export async function requestPaymentHandler(action: any, _context?: any): Promise<void> {
    const { pgPaymentData, paymentMethod: paramPaymentMethod } = (action.params || {}) as RequestPaymentParams;

    if (!pgPaymentData) {
        console.error('[sirsoft-pay_nhnkcp] pgPaymentData is required');
        return;
    }

    // 서버가 저장한 결제수단(payment_method)이 SSoT — 확장 수단이면 확장 ID 가 그대로 온다.
    // params.paymentMethod 는 인터셉터 시절의 잔재이나 직접 호출 경로 호환을 위해 우선 존중한다.
    const paymentMethod = paramPaymentMethod
        ?? pgPaymentData.payment_method
        ?? pgPaymentData.pay_method
        ?? 'card';
    const isEasyPay = typeof paymentMethod === 'string' && paymentMethod.startsWith('nhnkcp_');

    const G7Core = (window as any).G7Core;
    let closeReportContext: PaymentCloseReportContext | null = null;

    try {
        if (!isSupportedKcpCurrency(pgPaymentData.currency)) {
            const message = unsupportedCurrencyMessage(pgPaymentData.currency);
            G7Core?.state?.setLocal?.({ paymentErrorMessage: message, isSubmittingOrder: false, paymentMethod });
            G7Core?.modal?.open?.('nhnkcp_payment_error_modal');
            return;
        }

        if (isNhnKcpApplePayMethod(paymentMethod) && !isIosMobileDevice()) {
            const message = applePayUnsupportedMessage();
            G7Core?.state?.setLocal?.({
                paymentErrorMessage: message,
                isSubmittingOrder: false,
                paymentMethod,
            });
            G7Core?.modal?.open?.('nhnkcp_payment_error_modal');
            return;
        }

        // 1. Client Config API 호출
        const configJson = await G7Core.api.get('/modules/sirsoft-ecommerce/payments/client-config/nhnkcp');

        if (!configJson.data) {
            console.error('[sirsoft-pay_nhnkcp] Failed to fetch client config', configJson);
            return;
        }

        const config: ClientConfig = configJson.data;
        closeReportContext = {
            closeReportUrl: config.callback_urls.close_report,
            oid: pgPaymentData.order_number,
            price: Number(pgPaymentData.amount),
            buyer_email: pgPaymentData.customer_email ?? '',
            buyer_phone: pgPaymentData.customer_phone ?? '',
            payment_method: paymentMethod,
        };
        const callbackUrl = window.location.origin + config.callback_urls.callback;

        await preparePaymentRetry(closeReportContext);

        // 결제창은 전체 페이지 이동으로 열리고 돌아오므로, 실패 화면에서 서버에 보고할 때 쓸
        // 구매자 정보를 미리 남겨 둔다. 브라우저 리턴 콜백은 인증이 없어 주문 상태를 바꾸지 않고,
        // 소유권을 대조하는 close-report 만이 정당한 결제 실패를 기록할 수 있다.
        rememberPendingClose(closeReportContext);

        if (isMobileDevice()) {
            await handleMobilePayment(G7Core, pgPaymentData, paymentMethod, isEasyPay, callbackUrl);
        } else {
            await handlePcPayment(config, pgPaymentData, paymentMethod, isEasyPay, callbackUrl);
        }

    } catch (error: unknown) {
        if (error instanceof KcpCancelledError) {
            if (closeReportContext) {
                await reportPaymentWindowClosed(closeReportContext, error.message || 'kcp-window-closed');
            }
            G7Core?.state?.setLocal?.({ isSubmittingOrder: false, paymentMethod });
            return;
        }

        console.error('[sirsoft-pay_nhnkcp] requestPayment error', error);

        // axios error 의 response.data 에서 사용자 친화적 메시지 추출.
        // G7Core.api 는 axios 기반이라 4xx/5xx 응답은 자동 reject 됨 — generic
        // "Request failed with status code 422" 가 아니라 Laravel ValidationException
        // 의 message / errors 필드를 우선 사용.
        const anyErr = error as { response?: { data?: { message?: string; error?: string; errors?: Record<string, string[]> } }; message?: string };
        const responseData = anyErr?.response?.data;
        const firstFieldError = responseData?.errors
            ? Object.values(responseData.errors)[0]?.[0]
            : undefined;
        const errorMessage =
            responseData?.error
            ?? responseData?.message
            ?? firstFieldError
            ?? (error instanceof Error ? error.message : 'Unknown error');

        G7Core?.state?.setLocal?.({ paymentErrorMessage: errorMessage, isSubmittingOrder: false, paymentMethod });
        G7Core?.modal?.open?.('nhnkcp_payment_error_modal');
    }
}

/**
 * 모바일 결제 흐름
 *
 * 1) 서버에서 SOAP으로 approval_key + pay_url 획득
 * 2) 전체 form fields를 받아 브라우저가 pay_url 로 POST 전환 (페이지 이동)
 * 3) KCP가 결제 완료 후 Ret_URL(authCallback)로 redirect → 기존 서버 처리
 */
async function handleMobilePayment(
    G7Core: any,
    pgPaymentData: PgPaymentData,
    paymentMethod: string,
    isEasyPay: boolean,
    callbackUrl: string,
): Promise<void> {
    const approvalJson = await G7Core.api.post('/plugins/sirsoft-pay_nhnkcp/mobile/approval-key', {
        order_number: pgPaymentData.order_number,
        amount: pgPaymentData.amount,
        good_name: pgPaymentData.order_name,
        pay_method: paymentMethod,
        buyr_name: pgPaymentData.customer_name ?? '',
        buyr_mail: pgPaymentData.customer_email ?? '',
        buyr_tel1: pgPaymentData.customer_phone ?? '',
        ret_url: callbackUrl,
        currency: normalizeCurrency(pgPaymentData.currency),
    });

    if (!approvalJson.success || !approvalJson.data) {
        // 서버 응답의 에러 메시지 우선순위:
        //   1. ResponseHelper::error 의 'error' 필드 (커스텀)
        //   2. Laravel ValidationException 의 'message' 필드 (422 응답 표준)
        //   3. 'errors' 객체의 첫 번째 메시지 (필드별 validation 메시지)
        //   4. fallback
        const errors = approvalJson.errors as Record<string, string[]> | undefined;
        const firstFieldError = errors ? Object.values(errors)[0]?.[0] : undefined;
        throw new Error(
            approvalJson.error
                ?? approvalJson.message
                ?? firstFieldError
                ?? 'KCP 모바일 승인키 획득 실패',
        );
    }

    const { pay_url, fields } = approvalJson.data as { pay_url: string; fields: Record<string, string> };

    // 페이지 전환 form 생성 및 제출
    const form = document.createElement('form');
    form.method = 'POST';
    form.action = pay_url;
    form.acceptCharset = 'euc-kr'; // KCP 모바일은 EUC-KR 인코딩 필요
    form.style.display = 'none';

    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = String(value);
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();

    // 페이지가 pay_url 로 이동하므로 Promise는 자연스럽게 중단됨
    await new Promise<never>(() => {});
}

/**
 * PC 결제 흐름
 *
 * Chrome 비동기 document.write() 차단을 우회하기 위해 iframe 내에서
 * document.open/write/close 로 SDK를 동기 로드한 뒤 KCP_Pay_Execute() 호출.
 */
async function handlePcPayment(
    config: ClientConfig,
    pgPaymentData: PgPaymentData,
    paymentMethod: string,
    isEasyPay: boolean,
    callbackUrl: string,
): Promise<void> {
    // 간편결제는 pay_method 항상 "100000000000" (카드 비트마스크), direct 파라미터로 수단 지정
    const payMethod = isEasyPay
        ? '100000000000'
        : (KCP_PAY_METHOD[pgPaymentData.pay_method ?? 'card'] ?? '100000000000');

    // PAYCO 전용 테스트 site_cd (S6729), 그 외 간편결제·에스크로·일반은 각자 site_cd 사용
    const siteCd = paymentMethod === 'nhnkcp_payco'
        ? (config.easy_pay_client_id ?? config.client_id)
        : (config.use_escrow && !isEasyPay ? (config.escrow_client_id ?? config.client_id) : config.client_id);

    const fields: Record<string, string> = {
        site_cd: siteCd,
        ordr_idxx: pgPaymentData.order_number,
        good_name: pgPaymentData.order_name,
        good_mny: String(pgPaymentData.amount),
        buyr_name: pgPaymentData.customer_name ?? '',
        buyr_mail: pgPaymentData.customer_email ?? '',
        buyr_tel1: pgPaymentData.customer_phone ?? '',
        pay_method: payMethod,
        Ret_URL: callbackUrl,
    };

    Object.assign(fields, buildKcpTaxFields(pgPaymentData));
    Object.assign(fields, buildKcpEasyPayReturnFields(paymentMethod, isEasyPay));

    // 가상계좌 전용 파라미터
    if (payMethod === '001000000000') {
        fields['vcnt_expire_term'] = String(config.vbank_expire_days ?? 3);
        fields['disp_tax_yn'] = 'N';
    }

    // 에스크로 결제 파라미터 (간편결제 제외)
    if (config.use_escrow && !isEasyPay) {
        fields['escw_used'] = 'Y';
        fields['pay_mod'] = 'O';
    }

    // 간편결제: GNU5 규격과 동일하게 모든 direct 파라미터를 기본값("" 또는 "A")으로 초기화한 뒤
    // 선택된 수단만 "Y"로 덮어씀 — 초기값이 없으면 KCP가 이전 요청값을 재사용할 수 있음
    if (isEasyPay) {
        // GNU5 규격: 모든 direct 파라미터를 기본값으로 초기화 후 선택된 수단만 Y로 덮어씀
        fields['payco_direct']    = '';
        fields['naverpay_direct'] = 'A';
        fields['kakaopay_direct'] = 'A';
        fields['applepay_direct'] = 'A';
        const directOverride = KCP_EASY_PAY_DIRECT[paymentMethod];
        if (directOverride) {
            Object.assign(fields, directOverride);
        }
    }

    const hiddenInputs = Object.entries(fields)
        .map(([n, v]) => `<input type="hidden" name="${n}" value="${v.replace(/"/g, '&quot;')}">`)
        .join('');

    // SDK 주입 직전 출처 확인 — 아래 iframe `document.write` 는 코어 로더를 쓸 수 없으므로
    // (동기 실행 순서가 KCP_Pay_Execute 호출 계약이다) 검증만 선행한다. 실패 시 결제를
    // 진행하지 않는다(fail-closed).
    assertTrustedSdkUrl(config.sdk_url);

    await new Promise<void>((resolve, reject) => {
        // 기존 요소 정리
        document.getElementById('kcp-sdk-iframe')?.remove();
        document.getElementById('kcp-dim-overlay')?.remove();

        // 메인 창에 반투명 dim 오버레이 (결제창 뒤 배경)
        const overlay = document.createElement('div');
        overlay.id = 'kcp-dim-overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:99998;';
        document.body.appendChild(overlay);

        // 투명 iframe - KCP 결제창이 내부에 렌더링됨
        const iframe = document.createElement('iframe');
        iframe.id = 'kcp-sdk-iframe';
        iframe.setAttribute('allowtransparency', 'true');
        iframe.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;border:0;z-index:99999;background:transparent;';
        document.body.appendChild(iframe);

        const iframeWin = iframe.contentWindow as any;
        let settled = false;
        let sdkLoadTimer: number | undefined;
        let stopCloseWatcher: (() => void) | undefined;

        const cleanup = () => {
            stopCloseWatcher?.();
            stopCloseWatcher = undefined;
            if (sdkLoadTimer !== undefined) {
                clearTimeout(sdkLoadTimer);
                sdkLoadTimer = undefined;
            }
            iframe.remove();
            overlay.remove();
        };

        const rejectOnce = (error: Error) => {
            if (settled) {
                return;
            }

            settled = true;
            cleanup();
            reject(error);
        };

        const markCompletingPayment = () => {
            settled = true;
            stopCloseWatcher?.();
            stopCloseWatcher = undefined;
            if (sdkLoadTimer !== undefined) {
                clearTimeout(sdkLoadTimer);
                sdkLoadTimer = undefined;
            }
        };

        stopCloseWatcher = watchKcpPaymentFrameClosure(iframe, () => {
            rejectOnce(new KcpCancelledError('kcp-payment-frame-closed'));
        });

        // 결제 완료 콜백 - KCP가 결제 후 호출
        iframeWin.m_Completepayment = function (form: HTMLFormElement) {
            const resCode = (form.elements as any)['res_cd']?.value ?? '';
            const resMsg  = (form.elements as any)['res_msg']?.value ?? '';

            if (resCode === '0000') {
                markCompletingPayment();

                // GNU5 패턴(GetField): KCP 결과를 order_info에 병합 후 order_info를 POST.
                // KakaoPay 등 direct 결제에서 KCP가 ordr_idxx 없는 새 폼을 전달하므로
                // form을 그대로 제출하면 서버 검증 실패(invalid_params).
                // order_info(우리 주문 필드 포함)를 기준으로 병합해야 한다.
                const orderForm = iframeWin.document.forms.namedItem('order_info') as HTMLFormElement | null;
                const targetForm = (orderForm && orderForm !== form) ? orderForm : form;

                if (orderForm && orderForm !== form) {
                    for (let i = 0; i < form.elements.length; i++) {
                        const el = form.elements[i] as HTMLInputElement;
                        if (!el.name) continue;
                        let dest = orderForm.elements.namedItem(el.name) as HTMLInputElement | null;
                        if (!dest) {
                            dest = iframeWin.document.createElement('input') as HTMLInputElement;
                            dest.type = 'hidden';
                            dest.name = el.name;
                            orderForm.appendChild(dest);
                        }
                        dest.value = el.value;
                    }
                }

                targetForm.action = callbackUrl;
                targetForm.method = 'POST';
                targetForm.target = '_top';
                iframeWin.document.body.appendChild(targetForm);
                targetForm.submit();
            } else {
                // 취소 또는 오류
                const isCancelled = resCode === '' || resCode === '7777' || resMsg.includes('취소');
                rejectOnce(isCancelled ? new KcpCancelledError(resMsg) : new Error(`KCP 오류 [${resCode}]: ${resMsg}`));
            }
        };

        iframeWin.__kcpFail = (err: Error) => {
            rejectOnce(err);
        };

        // SDK 동기 로드 + KCP_Pay_Execute 호출
        const iframeDoc = (iframe.contentDocument || iframeWin.document) as Document;
        iframeDoc.open();
        iframeDoc.write(`<!DOCTYPE html><html><head>
<script src="${config.sdk_url}"><\/script>
</head><body style="margin:0;padding:0;background:transparent;">
<form name="order_info">${hiddenInputs}</form>
<script>
try {
  if (typeof KCP_Pay_Execute === 'function') {
    KCP_Pay_Execute(document.forms['order_info']);
    window.__kcpReady && window.__kcpReady();
  } else {
    window.__kcpFail && window.__kcpFail(new Error('KCP_Pay_Execute not defined'));
  }
} catch(e) {
  window.__kcpFail && window.__kcpFail(e);
}
<\/script>
</body></html>`);
        iframeDoc.close();

        // SDK 로드 실패 대비 타임아웃 — 결제창이 열리면(__kcpReady) 즉시 해제됨
        sdkLoadTimer = window.setTimeout(() => {
            rejectOnce(new Error('KCP SDK load timeout'));
        }, 15000);

        iframeWin.__kcpReady = () => {
            if (sdkLoadTimer !== undefined) {
                clearTimeout(sdkLoadTimer);
                sdkLoadTimer = undefined;
            }
        };
    });
}
