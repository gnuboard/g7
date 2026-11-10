// e2e:allow 결제창 수단을 서버가 저장한 결제수단으로 정하도록 바로잡은 것이다. PG 결제창은 외부 테스트 서버라 spec 으로 재현하지 않고, NHN KCP 테스트 상점에서 신용카드와 가상계좌 결제창을 실제로 열어 확인했다(단위 테스트가 결정 함수를 잠근다). 복합과세 분할(#820)은 Vitest 금액 단언으로 잠근다 — 복합과세 협의 사이트코드가 없어 KCP 매출 구분은 실측 불가.
/* eslint-disable @typescript-eslint/no-explicit-any */

import {
    PaymentCloseReportContext,
    preparePaymentRetry,
    rememberPendingClose,
    reportPaymentWindowClosed,
} from '../paymentCloseReport';
import { guestOwnershipHeaders } from '../guestOrderToken';
import { translateNhnkcp as t } from '../translate';
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
    /**
     * 서버가 저장한 결제수단 ID — 확장 수단이면 확장 ID 그대로(예: 'nhnkcp_naverpay').
     * 확장 결제수단이 1급 시민이 되면서 서버가 원본 수단을 알고 내려준다(#475).
     */
    payment_method?: string;
    customer_name?: string;
    customer_email?: string;
    customer_phone?: string;
    /**
     * 복합과세 분할용 — 서버는 면세분이 있을 때만 싣는다(없으면 null). `tax_amount` 는 부가세 포함 과세 합계.
     * 구버전 서버는 `tax_free_amount` 만 실었다 — 그때는 기본 세율로 부가세를 나눈다.
     */
    tax_amount?: number | null;
    vat_amount?: number | null;
    tax_free_amount?: number | null;
    /**
     * 클레임 추가결제일 때만 서버가 싣는 「결제창을 닫으면 돌아갈 신청서 상세」 주소
     * (`ClaimPaymentRedirect::cancelled`). 있으면 이 결제는 주문이 아니라 클레임 결제다 —
     * `order_number` 도 주문번호가 아니라 결제번호(CP…)다.
     */
    claim_cancel_return_url?: string;
    /**
     * 가상계좌 주문의 입금 기한(쇼핑몰 자동취소 기한 — ISO 8601, 한국 시각). 계좌 만료 기간으로 넘긴다.
     */
    vbank_due_at?: string;
}

/**
 * 클레임 추가결제면 결제창을 닫았을 때 돌아갈 신청서 상세 주소를, 주문 결제면 null 을 돌려줍니다.
 *
 * @param pgPaymentData 결제 데이터
 * @returns 신청서 상세 주소 또는 null
 */
function claimCancelReturnUrl(pgPaymentData: PgPaymentData): string | null {
    const url = pgPaymentData.claim_cancel_return_url;

    return typeof url === 'string' && url !== '' ? url : null;
}

/**
 * 사용자가 결제창을 닫은 뒤를 마무리합니다.
 *
 * 주문 결제는 서버에 닫힘을 보고하고 체크아웃의 진행 표시를 내린다. 클레임 추가결제는 주문 전용
 * 닫힘 보고를 보내지 않고(결제번호로는 주문을 찾지 못해 404) 서버가 준 신청서 상세로 돌아간다 —
 * 신청서 화면의 진행 표시는 결제창이 열린 동안 일부러 끄지 않으므로, 머물면 「결제 진행 중…」에 묶인다.
 *
 * @param pgPaymentData 결제 데이터
 * @param closeReportContext 닫힘 보고 문맥 (클라이언트 설정을 받기 전이면 null)
 * @param paymentMethod 결제수단
 * @param reason 닫힘 사유
 * @returns Promise<void>
 */
export async function finishCancelledPayment(
    pgPaymentData: PgPaymentData,
    closeReportContext: PaymentCloseReportContext | null,
    paymentMethod: string,
    reason: string,
): Promise<void> {
    const G7Core = (window as any).G7Core;
    const claimReturnUrl = claimCancelReturnUrl(pgPaymentData);

    if (claimReturnUrl) {
        G7Core?.state?.setLocal?.({ isSubmittingOrder: false, paymentMethod });
        window.location.href = claimReturnUrl;

        return;
    }

    if (closeReportContext) {
        await reportPaymentWindowClosed(closeReportContext, reason || 'kcp-window-closed');
    }
    G7Core?.state?.setLocal?.({ isSubmittingOrder: false, paymentMethod });
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
 * SDK 스크립트를 로드할 수 있는 호스트 (plugin.json `trusted_script_hosts` 의 부분집합 —
 * manifest 는 SDK 가 이어서 싣는 npay·spay 계열 호스트까지 선언한다, #158).
 *
 * NHN KCP 결제창은 라이브러리가 아니라 그 회사 서버와 통신하는 서비스 SDK 라
 * 자체 호스팅할 수 없다. 대신 **주입 직전에** 호스트를 확인해, 설정·응답이 어떤
 * 경로로든 다른 주소를 지시하면 결제를 진행하지 않는다(fail-closed).
 *
 * PG사가 SDK 호스트를 바꾸면 이 상수와 plugin.json 을 **함께** 갱신한다 —
 * 이 상수의 호스트가 manifest 에 없으면 테스트가 실패한다.
 */
export const KNOWN_SDK_HOSTS: readonly string[] = ['testpay.kcp.co.kr', 'pay.kcp.co.kr'];

/**
 * 번역 문자열을 얻습니다.
 *
 * @param key 번역 키 (플러그인 네임스페이스 이하)
 * @param fallback 번역 엔진 부재 시 사용할 문구
 * @returns 번역된 문자열
 */
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

    return t('payment.error.krw_only', 'NHN KCP는 KRW 결제만 지원합니다. ({{currency}})', { currency: normalized ?? '' });
}

/** 과세 합계를 받지 못했을 때의 부가세율(%) — 이커머스 `VatCalculator::DEFAULT_RATE` 와 같다. */
const DEFAULT_VAT_RATE = 10;

export function buildKcpTaxFields(pgPaymentData: PgPaymentData): Record<string, string> {
    const paymentAmount = Math.max(0, Math.round(Number(pgPaymentData.amount ?? 0)));
    const taxFreeAmt = Math.min(
        Math.max(0, Math.round(Number(pgPaymentData.tax_free_amount ?? 0))),
        paymentAmount,
    );

    if (taxFreeAmt <= 0) {
        return {};
    }

    const taxablePaymentAmt = Math.max(0, paymentAmount - taxFreeAmt);
    // 과세 합계를 받지 못하면(구버전 서버) 기본 세율로 나눈다 — 종전에는 이때 부가세가 0 으로 가
    // 과세분 전액이 공급가액(comm_tax_mny)으로 등록됐다(#820).
    const hasOrderTax = pgPaymentData.tax_amount !== undefined && pgPaymentData.tax_amount !== null;
    const taxTotalAmt = Math.max(0, Math.round(Number(pgPaymentData.tax_amount ?? 0)));
    const originalVatAmt = Math.max(0, Math.round(Number(pgPaymentData.vat_amount ?? 0)));
    const vatRatio = hasOrderTax
        ? (taxTotalAmt > 0 ? originalVatAmt / taxTotalAmt : 0)
        : DEFAULT_VAT_RATE / (100 + DEFAULT_VAT_RATE);
    const vatAmt = taxablePaymentAmt > 0
        ? Math.min(taxablePaymentAmt, Math.round(taxablePaymentAmt * vatRatio))
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

/**
 * PC 결제창에 넘길 KCP 결제수단 비트마스크를 정합니다.
 *
 * 결제수단은 서버가 저장한 값(payment_method)으로 정한다 — 서버는 pay_method 를 보내지 않으므로 그 키만 보면
 * 가상계좌·계좌이체 주문에도 카드 결제창이 열린다(모바일 경로와 같은 기준). 간편결제는 항상 카드 비트마스크이고
 * direct 파라미터로 수단을 지정한다.
 *
 * @param paymentMethod 서버가 저장한 결제수단 id (card / vbank / bank / phone / nhnkcp_*)
 * @param isEasyPay 간편결제 여부
 * @returns KCP pay_method 비트마스크
 */
export function resolveKcpPcPayMethod(paymentMethod: string, isEasyPay: boolean): string {
    if (isEasyPay) {
        return '100000000000';
    }

    return KCP_PAY_METHOD[paymentMethod] ?? '100000000000';
}

// KCP 간편결제 direct 파라미터 (PC/모바일 공통)
const KCP_EASY_PAY_DIRECT: Record<string, Record<string, string>> = {
    nhnkcp_payco:          { payco_direct: 'Y' },
    nhnkcp_naverpay:       { naverpay_direct: 'Y' },
    nhnkcp_naverpay_point: { naverpay_direct: 'Y', naverpay_point_direct: 'Y' },
    nhnkcp_kakaopay:       { kakaopay_direct: 'Y' },
    nhnkcp_applepay:       { applepay_direct: 'Y' },
};

/**
 * 가상계좌 계좌 만료 기간 필드를 만듭니다. (주문 입금 기한 = 쇼핑몰 자동취소 기한)
 *
 * KCP 는 만료를 「발급일로부터 N일(`vcnt_expire_term`) · 그날 HHMMSS(`vcnt_expire_term_time`)까지」로 받는다.
 * 일수는 한국 날짜로 센다 — 서버가 한국 시각(+09:00)으로 싣는 기한의 날짜와 지금의 한국 날짜 차이다.
 * 기한이 없거나 오늘 이전이면 종전 예비값(플러그인 `vbank_expire_days`, 없으면 3일)으로 연다.
 *
 * @param dueAt 주문 입금 기한
 * @param fallbackDays 예비 일수
 * @param now 지금(밀리초) — 테스트 주입용
 * @returns KCP 가상계좌 만료 필드
 */
export function buildKcpVbankExpireFields(
    dueAt: string | undefined,
    fallbackDays: number | undefined,
    now: number = Date.now(),
): Record<string, string> {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})/.exec(dueAt ?? '');
    if (m) {
        const kstNow = new Date(now + 9 * 60 * 60 * 1000);
        const today = Date.UTC(kstNow.getUTCFullYear(), kstNow.getUTCMonth(), kstNow.getUTCDate());
        const days = Math.round((Date.UTC(Number(m[1]), Number(m[2]) - 1, Number(m[3])) - today) / 86_400_000);
        if (days >= 1) {
            return { vcnt_expire_term: String(days), vcnt_expire_term_time: `${m[4]}${m[5]}${m[6]}` };
        }
    }

    return { vcnt_expire_term: String(fallbackDays ?? 3) };
}

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

    // 결제수단은 액션 인자로만 받는다 — 호출부가 넘긴 paymentMethod, 없으면 같은 인자의 pgPaymentData 에 서버가
    // 저장해 실은 payment_method(확장 수단이면 확장 ID 그대로)다. 화면 상태나 다른 저장소에서 다시 찾지 않는다.
    // 둘 다 없으면 서버와 다른 수단(예: 신용카드)으로 대신 열지 않고 결제 오류로 끝낸다(KG이니시스·나이스페이먼츠와 같은 규칙).
    const paymentMethod = paramPaymentMethod ?? pgPaymentData.payment_method;
    const isEasyPay = typeof paymentMethod === 'string' && paymentMethod.startsWith('nhnkcp_');

    const G7Core = (window as any).G7Core;

    if (typeof paymentMethod !== 'string' || paymentMethod === '') {
        console.error('[sirsoft-pay_nhnkcp] payment method is required (paymentMethod or pgPaymentData.payment_method)');
        G7Core?.state?.setLocal?.({
            paymentErrorMessage: t('payment.error.method_missing', '결제수단 정보가 없어 결제를 진행할 수 없습니다. 주문서를 다시 열어 주세요.'),
            isSubmittingOrder: false,
        });
        G7Core?.modal?.open?.('nhnkcp_payment_error_modal');
        return;
    }
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
            payment_method: paymentMethod,
        };
        const callbackUrl = requireSiteAbsoluteUrl(config.callback_urls.callback);

        // 재시도 준비·닫힘 보고는 **주문** 전용 엔드포인트다. 클레임 추가결제의 order_number 는
        // 결제번호(CP…)라 서버가 주문을 찾지 못해 404 를 주고, 그 거절이 결제창을 열기 전에 흐름을 끊는다.
        // 클레임 결제 건은 pending 그대로 다시 낼 수 있으므로 되살릴 주문 상태가 없다.
        if (!claimCancelReturnUrl(pgPaymentData)) {
            await preparePaymentRetry(closeReportContext);

            // 결제창은 전체 페이지 이동으로 열리고 돌아오므로, 실패 화면에서 서버에 보고할 때 쓸
            // 주문 정보를 미리 남겨 둔다. 브라우저 리턴 콜백은 인증이 없어 주문 상태를 바꾸지 않고,
            // 주문 소유자(회원 로그인 / 비회원 조회 토큰)를 확인하는 close-report 만이 정당한 결제 실패를 기록할 수 있다.
            rememberPendingClose(closeReportContext);
        }

        if (isMobileDevice()) {
            await handleMobilePayment(G7Core, pgPaymentData, paymentMethod, isEasyPay, callbackUrl);
        } else {
            await handlePcPayment(config, pgPaymentData, paymentMethod, isEasyPay, callbackUrl);
        }

    } catch (error: unknown) {
        if (error instanceof KcpCancelledError) {
            await finishCancelledPayment(pgPaymentData, closeReportContext, paymentMethod, error.message);
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
        // 비회원 주문 조회 토큰이 없거나 만료돼 승인키 요청이 401 로 거부된 경우 — 코어는 로그인 화면으로 옮기지 않는다
        // (토큰 없이 보낸 요청, engine-v1.88.2). 서버 문구(「인증이 필요합니다.」) 대신 다시 결제하는 길을 알린다.
        const guestAuthFailed = G7Core?.api?.isAnonymousUnauthorized?.(error) === true;
        const errorMessage = guestAuthFailed
            ? t('payment.error.mobile_guest_auth_failed', '주문 확인 정보가 만료되었습니다. 주문 조회에서 다시 결제해 주세요.')
            : responseData?.error
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
    _isEasyPay: boolean,
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
    }, {
        // 비회원 주문은 주문 생성 때 받은 조회 토큰으로 주문 소유를 증명한다(결제창 닫힘 보고와 같은 방식, #883).
        // 회원은 헤더가 비어 있고 Bearer 로 판정된다.
        headers: guestOwnershipHeaders(),
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
                ?? t('payment.error.mobile_approval_key_failed', 'KCP 모바일 승인키 획득 실패'),
        );
    }

    const { pay_url, fields } = approvalJson.data as { pay_url: string; fields: Record<string, string> };
    assertTrustedPgFormAction(pay_url);

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
 * 같은 출처 프레임 페이지(payment.kcp-frame)가 SDK 를 정적 <script src> 로 동기 로드한 뒤
 * 브리지(assets/bridge/kcp-frame.js)가 KCP_Pay_Execute() 를 호출한다(#158 — 인라인 스크립트 0).
 */
async function handlePcPayment(
    config: ClientConfig,
    pgPaymentData: PgPaymentData,
    paymentMethod: string,
    isEasyPay: boolean,
    callbackUrl: string,
): Promise<void> {
    const payMethod = resolveKcpPcPayMethod(paymentMethod, isEasyPay);

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
        // 계좌 만료 = 주문 입금 기한(쇼핑몰 자동취소 기한). 플러그인 일수 설정은 기한이 없을 때의 예비값이다.
        Object.assign(fields, buildKcpVbankExpireFields(pgPaymentData.vbank_due_at, config.vbank_expire_days));
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

    // SDK 출처 확인 — 프레임 페이지가 이 주소를 그대로 싣는다(서버도 허용 주소 두 개와 정확히 대조한다).
    // 실패 시 결제를 진행하지 않는다(fail-closed).
    assertTrustedSdkUrl(config.sdk_url);

    await new Promise<void>((_resolve, reject) => {
        // 기존 요소 정리
        document.getElementById('kcp-sdk-iframe')?.remove();
        document.getElementById('kcp-dim-overlay')?.remove();

        // 메인 창에 반투명 dim 오버레이 (결제창 뒤 배경)
        const overlay = document.createElement('div');
        overlay.id = 'kcp-dim-overlay';
        overlay.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:99998;';
        document.body.appendChild(overlay);

        // 투명 iframe - KCP 결제창이 내부에 렌더링됨. 같은 출처 프레임 페이지를 연다(#158) —
        // 종전에는 빈 iframe 에 document.write 로 SDK 와 인라인 스크립트를 써 넣었는데, 사이트가
        // script-src 'self' 를 걸면 그 인라인 코드가 실행되지 않아 15초 뒤 시간 초과로 끝났다.
        const iframe = document.createElement('iframe');
        iframe.id = 'kcp-sdk-iframe';
        iframe.setAttribute('allowtransparency', 'true');
        iframe.style.cssText = 'position:fixed;top:0;left:0;width:100%;height:100%;border:0;z-index:99999;background:transparent;';

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
            delete (window as any)[KCP_FRAME_BRIDGE];
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

        // 결제 완료 콜백 - KCP 가 프레임 안에서 m_Completepayment(form) 을 부르면 브리지가 이리로 넘긴다
        const completePayment = (form: HTMLFormElement, frameWin: Window) => {
            const resCode = (form.elements as any)['res_cd']?.value ?? '';
            const resMsg  = (form.elements as any)['res_msg']?.value ?? '';

            if (resCode === '0000') {
                markCompletingPayment();

                // GNU5 패턴(GetField): KCP 결과를 order_info에 병합 후 order_info를 POST.
                // KakaoPay 등 direct 결제에서 KCP가 ordr_idxx 없는 새 폼을 전달하므로
                // form을 그대로 제출하면 서버 검증 실패(invalid_params).
                // order_info(우리 주문 필드 포함)를 기준으로 병합해야 한다.
                const frameDoc = frameWin.document;
                const orderForm = frameDoc.forms.namedItem('order_info') as HTMLFormElement | null;
                const targetForm = (orderForm && orderForm !== form) ? orderForm : form;

                if (orderForm && orderForm !== form) {
                    for (let i = 0; i < form.elements.length; i++) {
                        const el = form.elements[i] as HTMLInputElement;
                        if (!el.name) continue;
                        let dest = orderForm.elements.namedItem(el.name) as HTMLInputElement | null;
                        if (!dest) {
                            dest = frameDoc.createElement('input') as HTMLInputElement;
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
                frameDoc.body.appendChild(targetForm);
                targetForm.submit();
            } else {
                // 취소 또는 오류
                const isCancelled = resCode === '' || resCode === '7777' || resMsg.includes('취소');
                rejectOnce(isCancelled ? new KcpCancelledError(resMsg) : new Error(t('payment.error.pg_error', 'KCP 오류 [{{code}}]: {{message}}', { code: resCode, message: resMsg })));
            }
        };

        // 프레임 브리지(assets/bridge/kcp-frame.js)가 같은 출처 부모의 이 객체를 읽는다.
        (window as any)[KCP_FRAME_BRIDGE] = {
            fields,
            complete: completePayment,
            // 결제창이 열리면 SDK 로드 타임아웃을 해제한다
            ready: () => {
                if (sdkLoadTimer !== undefined) {
                    clearTimeout(sdkLoadTimer);
                    sdkLoadTimer = undefined;
                }
            },
            fail: (message: string) => {
                rejectOnce(new Error(message));
            },
        };

        iframe.src = buildKcpFrameUrl(config.sdk_url);
        document.body.appendChild(iframe);

        stopCloseWatcher = watchKcpPaymentFrameClosure(iframe, () => {
            rejectOnce(new KcpCancelledError('kcp-payment-frame-closed'));
        });

        // SDK 로드 실패 대비 타임아웃 — 결제창이 열리면(ready) 즉시 해제됨
        sdkLoadTimer = window.setTimeout(() => {
            rejectOnce(new Error('KCP SDK load timeout'));
        }, 15000);
    });
}

/**
 * PC 결제창 프레임 브리지가 부모에서 찾는 전역 이름 (assets/bridge/kcp-frame.js 와 짝).
 */
export const KCP_FRAME_BRIDGE = '__g7KcpFrame';

/**
 * PC 결제창 프레임 페이지 경로 (플러그인 web 라우트 `payment.kcp-frame`).
 *
 * 사이트 주소(SiteUrl)가 아니라 같은 출처 상대 경로다 — 부모와 프레임이 같은 출처여야 주문 값·결과를
 * 주고받을 수 있는데, 별칭 주소로 접속한 사용자에게 사이트 주소는 다른 출처가 된다.
 */
export const KCP_FRAME_PATH = '/plugins/sirsoft-pay_nhnkcp/payment/kcp-frame';

/**
 * 결제창 프레임 주소를 만듭니다.
 *
 * @param sdkUrl 신뢰 호스트 확인을 거친 KCP SDK 주소
 * @returns 같은 출처 프레임 주소
 */
export function buildKcpFrameUrl(sdkUrl: string): string {
    return `${KCP_FRAME_PATH}?sdk=${encodeURIComponent(sdkUrl)}`;
}

/**
 * PG 로 보낼 결과 수신 주소가 서버가 내려준 절대 URL 인지 확인하고 그대로 돌려줍니다.
 *
 * 보안: 브라우저의 현재 주소(window.location.origin)로 조립하지 않는다 — 별칭·하위 도메인·포트로
 * 접속한 사용자의 결제 결과가 그 주소로 간다. 서버(client-config)가 사이트 주소로 만든 값만 쓰고,
 * 상대 경로가 오면(구버전 서버) 결제를 시작하지 않는다.
 *
 * @param url 서버가 내려준 주소
 * @returns 절대 URL
 * @throws Error http(s) 절대 URL 이 아닌 경우
 */
export function requireSiteAbsoluteUrl(url: unknown): string {
    if (typeof url === 'string' && /^https?:\/\//i.test(url)) {
        return url;
    }

    throw new Error(
        t('payment.error.sdk_url_untrusted', '결제 모듈 주소가 올바르지 않아 결제를 진행할 수 없습니다.')
    );
}

/**
 * 결제 정보를 POST 할 PG 주소가 그 PG 도메인인지 확인합니다.
 *
 * 보안: 서버 응답이 준 form action 으로 거래 키·주문자 정보를 POST 한다 — https 이고 PG 도메인일 때만
 * 제출한다(fail-closed, SDK 주소 검사와 같은 기준).
 *
 * @param url 제출 주소
 * @throws Error 허용 밖 주소인 경우
 */
export function assertTrustedPgFormAction(url: unknown): void {
    let parsed: URL | null = null;

    try {
        parsed = typeof url === 'string' ? new URL(url) : null;
    } catch {
        parsed = null;
    }

    if (parsed === null || parsed.protocol !== 'https:' || !/(^|\.)kcp\.co\.kr$/i.test(parsed.hostname)) {
        throw new Error(
            t('payment.error.sdk_url_untrusted', '결제 모듈 주소가 올바르지 않아 결제를 진행할 수 없습니다.')
        );
    }
}
