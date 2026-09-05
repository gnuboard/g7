import { reportPaymentWindowClosed } from '../paymentCloseReport';

interface G7ApiResponse {
    data?: unknown;
}

interface G7CoreApi {
    get: (url: string) => Promise<G7ApiResponse>;
    post?: (url: string, data?: Record<string, unknown>) => Promise<G7ApiResponse>;
}

interface G7CoreToast {
    error: (message: string) => void;
}

interface G7CoreStateApi {
    setLocal: (state: Record<string, unknown>) => void;
}

interface G7Core {
    api: G7CoreApi;
    toast?: G7CoreToast;
    state?: G7CoreStateApi;
}

interface TemplateLocalState {
    paymentMethod?: string;
}

interface TemplateApp {
    globalState?: {
        _local?: TemplateLocalState;
    };
}

interface PgPaymentData {
    order_number: string;
    order_name: string;
    amount: number;
    currency?: string;
    /**
     * 서버가 저장한 결제수단 ID — 확장 수단이면 확장 ID 그대로(예: 'nicepay_naverpay').
     * 확장 결제수단이 1급 시민이 되면서 서버가 원본 수단을 알고 내려준다(#475).
     */
    payment_method?: string;
    customer_name?: string;
    customer_email?: string;
    customer_phone?: string;
    goods_cl?: string; // 휴대폰결제 상품 유형: '0'=디지털컨텐츠, '1'=실물
}

interface RequestPaymentParams {
    pgPaymentData: PgPaymentData;
    paymentMethod?: string;
}

interface ClientConfig {
    mid: string;
    sdk_url: string;
    sign_data_url: string;
    close_report_url?: string;
    useEscrow?: boolean;
}

interface SignDataResponse {
    ediDate: string;
    signData: string;
    mid: string;
}

interface PaymentAction {
    params?: RequestPaymentParams;
}

declare global {
    interface Window {
        nicepaySubmit: () => void;
        nicepayClose: (resultCode: string, resultMsg: string) => void;
        goPay: (form: HTMLFormElement) => void;
        G7Core: G7Core;
        __templateApp?: TemplateApp;
    }
}

/**
 * SDK 스크립트를 로드할 수 있는 호스트 (plugin.json `trusted_script_hosts` 미러).
 *
 * 나이스페이먼츠 결제창은 라이브러리가 아니라 그 회사 서버와 통신하는 서비스 SDK 라
 * 자체 호스팅할 수 없다. 대신 **주입 직전에** 호스트를 확인해, 설정·응답이 어떤
 * 경로로든 다른 주소를 지시하면 결제를 진행하지 않는다(fail-closed).
 *
 * PG사가 SDK 호스트를 바꾸면 이 상수와 plugin.json 을 **함께** 갱신한다 —
 * 둘이 어긋나면 테스트가 실패한다.
 */
export const KNOWN_SDK_HOSTS: readonly string[] = ['web.nicepay.co.kr'];

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

    const full = `sirsoft-pay_nicepayments.${key}`;
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

/**
 * SDK 스크립트를 로드합니다.
 *
 * 완료 판정은 **SDK 전역 확보**로 한다 — DOM 에 태그가 있다는 것은 로드 완료를
 * 뜻하지 않는다(로드 중이거나, 실패해 남은 잔재일 수 있다). 종전에는 태그 존재만으로
 * 즉시 resolve 해서, 전역이 없는 상태로 다음 단계가 진행되고 결제창이 열리지 않았다.
 *
 * @param src SDK URL
 * @throws Error 미신뢰 호스트이거나 로드에 실패한 경우
 */
async function loadScript(src: string): Promise<void> {
    assertTrustedSdkUrl(src);

    if (typeof window.goPay === 'function') {
        return;
    }

    // 전역이 없는데 태그만 남아 있으면 미완료·실패 잔재다 — 제거 후 새로 로드한다.
    document.querySelectorAll(`script[src="${CSS.escape(src)}"]`).forEach((el) => el.remove());

    const loader = (window as any)?.G7Core?.asset?.loadScript;

    if (typeof loader === 'function') {
        await loader(src, {}, { label: 'nicepayments SDK' });

        return;
    }

    await new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error(`Failed to load script: ${src}`));
        document.head.appendChild(script);
    });
}

function createPaymentForm(action: string, fields: Record<string, string>): HTMLFormElement {
    const form = document.createElement('form');
    form.id = 'nicepayForm';
    form.method = 'post';
    form.action = action;
    // CharSet 필드와 일치 — 모바일은 form.acceptCharset 으로 charset 결정
    form.acceptCharset = 'utf-8';

    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    document.body.appendChild(form);
    return form;
}

const CALLBACK_PATH = '/plugins/sirsoft-pay_nicepayments/payment/callback';

// 나이스페이 v3 모바일 결제창 endpoint — 폼을 직접 POST 하면 NicePay 모바일 페이지로 전체 redirect.
// PC 와 달리 SDK 불필요. 결제 완료 후 ReturnURL 로 redirect.
const NICEPAY_MOBILE_ENDPOINT = 'https://web.nicepay.co.kr/v3/v3Payment.jsp';

/**
 * 모바일 환경 판별.
 *
 * 판별 우선순위:
 * 1. navigator.userAgentData.mobile — 브라우저가 직접 판단하는 표준 API (Chrome/Edge 90+)
 * 2. UA 문자열 정규식 — iOS/Android/Windows Phone 및 주요 인앱 브라우저
 * 3. maxTouchPoints 보조 판단 — iPadOS처럼 데스크탑 UA를 보내는 터치 기기 처리
 *    (Windows/Mac 데스크탑 터치스크린 오탐 방지를 위해 플랫폼 확인 병행)
 */
function isMobileDevice(): boolean {
    if (typeof navigator === 'undefined') return false;

    // 1단계: User Agent Client Hints (가장 정확, 브라우저가 직접 판단)
    const nav = navigator as Navigator & { userAgentData?: { mobile: boolean } };
    if (nav.userAgentData?.mobile !== undefined) {
        return nav.userAgentData.mobile;
    }

    // 2단계: UA 문자열 파싱
    const ua = (navigator.userAgent || '').toLowerCase();
    const mobileUA = /android|iphone|ipod|windows phone|iemobile|blackberry|opera mini|mobile safari/;
    if (mobileUA.test(ua)) return true;

    // 3단계: iPadOS 등 데스크탑 UA를 보내는 터치 기기 판별
    // maxTouchPoints > 1 이면 실제 멀티터치 기기 (터치스크린 노트북은 보통 1)
    // Windows/Mac 플랫폼이면 데스크탑으로 간주하여 오탐 방지
    const touchPoints = (navigator as Navigator & { maxTouchPoints?: number }).maxTouchPoints ?? 0;
    if (touchPoints > 1 && !ua.includes('windows') && !ua.includes('macintosh')) {
        return true;
    }

    return false;
}

/**
 * 나이스페이먼츠 결제창 호출 핸들러 (나이스페이 구형 API, goPay 방식)
 *
 * 체크아웃 레이아웃에서 주문 생성 API 성공 후 호출됩니다:
 *   handler: "sirsoft-pay_nicepayments.requestPayment"
 *   params: { pgPaymentData: response.data.pg_payment_data }
 *
 * 호출 순서:
 *   1. Client Config API 호출 → MID, SDK URL, SignData URL 획득
 *   2. 환경 분기: PC 면 SDK 동적 로드 / 모바일 은 SDK 생략
 *   3. 서버에서 EdiDate + SignData 생성
 *   4. 결제 폼 생성:
 *      - PC: 폼 action=ReturnURL → goPay(form) 으로 iframe 팝업
 *      - 모바일: 폼 action=https://web.nicepay.co.kr/v3/v3Payment.jsp → 직접 submit (전체 페이지 redirect)
 *   5. 결제 완료 시 나이스페이먼츠가 ReturnURL(POST)로 인증값 전달
 *
 * NicePay 의 PC SDK 는 모바일 자동 감지를 하지 않으므로 UA 기반 분기가 필수.
 * 잘못 분기하면 모바일에서도 PC 팝업이 떠 사용성이 매우 나빠짐.
 */
export async function requestPaymentHandler(action: PaymentAction, _context?: unknown): Promise<void> {
    const { pgPaymentData, paymentMethod: paramPaymentMethod } = action.params ?? {};

    const localState = window.__templateApp?.globalState?._local;
    // 서버가 저장한 결제수단(payment_method)이 SSoT — 확장 수단이면 확장 ID 가 그대로 온다(#475).
    const paymentMethod = paramPaymentMethod
        ?? pgPaymentData?.payment_method
        ?? localState?.paymentMethod
        ?? 'card';

    if (!pgPaymentData) {
        console.error('[sirsoft-pay_nicepayments] pgPaymentData is required');
        return;
    }

    const G7Core = window.G7Core;

    try {
        // 1. Client Config 가져오기
        const configJson = await G7Core.api.get('/modules/sirsoft-ecommerce/payments/client-config/nicepayments');

        if (!configJson.data) {
            console.error('[sirsoft-pay_nicepayments] Failed to fetch client config', configJson);
            return;
        }

        const config = configJson.data as ClientConfig;
        const isMobile = isMobileDevice();

        // 2. PC 인 경우에만 SDK 로드 (모바일 은 직접 form submit 으로 NicePay 모바일 페이지로 이동)
        if (!isMobile) {
            await loadScript(config.sdk_url);

            if (typeof window.goPay !== 'function') {
                G7Core?.toast?.error?.('나이스페이먼츠 SDK를 불러올 수 없습니다. 잠시 후 다시 시도해주세요.');
                G7Core?.state?.setLocal?.({ isSubmittingOrder: false, paymentMethod });
                return;
            }
        }

        // 3. 서버에서 EdiDate + SignData 생성
        //    공개 엔드포인트지만 서버에서 주문번호, 결제 전 상태, 금액, 구매자 컨텍스트를 검증한다.
        const signDataUrl = window.location.origin + config.sign_data_url;
        const authToken = (typeof localStorage !== 'undefined') ? localStorage.getItem('auth_token') : null;
        const signDataHeaders: Record<string, string> = {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'Accept': 'application/json',
        };
        if (authToken) {
            signDataHeaders['Authorization'] = `Bearer ${authToken}`;
        }
        const signDataRes = await fetch(signDataUrl, {
            method: 'POST',
            credentials: 'include',
            headers: signDataHeaders,
            body: JSON.stringify({
                amt: pgPaymentData.amount,
                moid: pgPaymentData.order_number,
                buyer_email: pgPaymentData.customer_email ?? '',
                buyer_phone: pgPaymentData.customer_phone ?? '',
            }),
        });

        if (!signDataRes.ok) {
            throw new Error('SignData 생성에 실패했습니다.');
        }

        const signData: SignDataResponse = await signDataRes.json();
        const callbackUrl = window.location.origin + CALLBACK_PATH;

        // 4. 결제 폼 생성
        // 간편결제는 PayMethod='CARD' + DirectShowOpt='CARD' + 방식별 directive 필드 조합.
        // PayMethod에 'KAKAOPAY' 등을 직접 넣으면 [W004] 발생 — gnu5 orderform.js 동일 방식.
        const isEasyPay = typeof paymentMethod === 'string' && paymentMethod.startsWith('nicepay_');

        const payMethodMap: Record<string, string> = {
            card: 'CARD',
            vbank: 'VBANK',
            bank: 'BANK',
            phone: 'CELLPHONE',
        };
        const payMethod = isEasyPay ? 'CARD' : (payMethodMap[paymentMethod ?? 'card'] ?? 'CARD');

        const formFields: Record<string, string> = {
            PayMethod: payMethod,
            GoodsName: pgPaymentData.order_name,
            Amt: String(pgPaymentData.amount),
            MID: signData.mid,
            Moid: pgPaymentData.order_number,
            BuyerName: pgPaymentData.customer_name ?? '',
            BuyerEmail: pgPaymentData.customer_email ?? '',
            BuyerTel: pgPaymentData.customer_phone ?? '',
            ReturnURL: callbackUrl,
            EdiDate: signData.ediDate,
            SignData: signData.signData,
            CharSet: 'utf-8',
            GoodsCl: '1',
            DirectShowOpt: '',
            DirectEasyPay: '',
            NicepayReserved: '',
            EasyPayMethod: '',
            MallReserved: '',
            MallReserved1: '',
        };

        // 휴대폰결제: 상품 유형 덮어쓰기 (0:디지털컨텐츠, 1:실물)
        if (payMethod === 'CELLPHONE') {
            formFields.GoodsCl = pgPaymentData.goods_cl ?? '1';
        }

        // TransType: 에스크로 활성 + 일반결제 → '1', 그 외(간편결제 포함) → '0'
        const useEscrow = config.useEscrow && !isEasyPay;
        formFields.TransType = useEscrow ? '1' : '0';

        // 간편결제 directive 필드 설정 — gnu5 orderform.js switch(settle_method==='간편결제') 동일
        if (isEasyPay) {
            formFields.DirectShowOpt = 'CARD';
            formFields.MallReserved = `nicepay_easy_pay_method=${encodeURIComponent(paymentMethod)}`;
            formFields.MallReserved1 = paymentMethod;
            switch (paymentMethod) {
                case 'nicepay_naverpay':
                    formFields.DirectEasyPay = 'E020';
                    formFields.EasyPayMethod = 'E020=CARD';
                    break;
                case 'nicepay_kakaopay':
                    formFields.NicepayReserved = 'DirectKakao=Y';
                    break;
                case 'nicepay_samsungpay':
                    formFields.DirectEasyPay = 'E021';
                    break;
                case 'nicepay_applepay':
                    formFields.DirectEasyPay = 'E025';
                    break;
                case 'nicepay_payco':
                    formFields.NicepayReserved = 'DirectPayco=Y';
                    break;
                case 'nicepay_skpay':
                    formFields.NicepayReserved = 'DirectPay11=Y';
                    break;
                case 'nicepay_ssgpay':
                    formFields.DirectEasyPay = 'E007';
                    break;
                case 'nicepay_lpay':
                    formFields.DirectEasyPay = 'E018';
                    break;
            }
        }

        // 4-2. 과세/비과세 금액 조회 (optional — 실패해도 결제 진행)
        try {
            // 비회원 주문은 X-Guest-Order-Token 이 없으면 서버가 주문을 찾지 못한다.
            // 헤더를 빼면 과세/비과세 금액이 조회되지 않아 결제사에 세금 구분이 빠진 채 전달된다.
            const guestToken = G7Core?.state?.get?.('_global')?.guestOrderToken;
            const orderRes = await G7Core.api.get(
                `/modules/sirsoft-ecommerce/user/orders/${pgPaymentData.order_number}`,
                guestToken ? { headers: { 'X-Guest-Order-Token': guestToken } } : undefined,
            );
            const od = orderRes?.data as Record<string, unknown> | null | undefined;
            if (od) {
                const taxAmt = Number(od['total_tax_amount'] ?? 0);
                const vatAmt = Number(od['total_vat_amount'] ?? 0);
                const taxFreeAmt = Number(od['total_tax_free_amount'] ?? 0);
                if (taxAmt > 0 || vatAmt > 0 || taxFreeAmt > 0) {
                    formFields.TaxAmt = String(taxAmt);
                    formFields.VatAmt = String(vatAmt);
                    formFields.TaxFreeAmt = String(taxFreeAmt);
                }
            }
        } catch {
            // 과세 필드는 선택 사항 — 조회 실패 시 미포함 상태로 진행
        }

        const form = createPaymentForm(callbackUrl, formFields);

        if (isMobile) {
            // 모바일 결제창 호출:
            //   - form.action = NicePay 모바일 endpoint
            //   - acceptCharset = 'euc-kr' : NicePay v3 mobile 은 EUC-KR 로 form 데이터를 받음
            //     (NicePay 공식 샘플과 동일). 브라우저가 자동으로 UTF-8 → EUC-KR 변환.
            //   - CharSet 입력값도 'euc-kr' 로 일치 — NicePay 가 같은 인코딩으로 디코딩하도록.
            //     (utf-8 그대로 두면 GoodsName/BuyerName 한글이 mojibake "遺?瑜?" 로 깨짐)
            //   결제 완료 후 NicePay 가 ReturnURL 로 redirect.
            form.action = NICEPAY_MOBILE_ENDPOINT;
            form.acceptCharset = 'euc-kr';
            const charsetInput = form.querySelector('input[name="CharSet"]') as HTMLInputElement | null;
            if (charsetInput) {
                charsetInput.value = 'euc-kr';
            }
            form.submit();
            // 페이지 자체가 redirect 되므로 정리 로직 불필요. submit 후 이 함수의 후속 코드는 실행되지 않음.
            return;
        }

        // 이하 PC 전용 — iframe 팝업 + nicepaySubmit / nicepayClose 콜백 처리

        // 5. 나이스페이 전역 콜백 정의
        window.nicepaySubmit = () => {
            form.submit();
        };

        let paymentClosed = false;

        // goPay() 전 body 자식 스냅샷 — SDK가 추가하는 오버레이/iframe 식별용
        const bodySnapshot = new Set(document.body.children);

        const closePayment = (_resultCode: string, resultMsg: string) => {
            if (paymentClosed) return;
            paymentClosed = true;
            const closeReason = [_resultCode, resultMsg].filter(Boolean).join(': ') || 'nicepay-window-closed';
            void reportPaymentWindowClosed({
                closeReportUrl: config.close_report_url,
                oid: pgPaymentData.order_number,
                price: Number(pgPaymentData.amount),
                buyer_email: pgPaymentData.customer_email ?? '',
                buyer_phone: pgPaymentData.customer_phone ?? '',
                payment_method: paymentMethod,
            }, closeReason);
            window.removeEventListener('popstate', handlePopState);
            // SDK가 body에 추가한 오버레이/iframe 등 제거
            Array.from(document.body.children).forEach(el => {
                if (!bodySnapshot.has(el)) el.remove();
            });
            if (form.parentNode) form.parentNode.removeChild(form);
            (window as unknown as Record<string, unknown>)['__sirsoftNicepayPaymentInProgress'] = false;
            G7Core?.state?.setLocal?.({ isSubmittingOrder: false, paymentMethod });
            if (resultMsg) G7Core?.toast?.error?.(resultMsg);
        };

        window.nicepayClose = closePayment;

        // 뒤로가기(popstate) 감지 → 결제창 정리
        const handlePopState = () => closePayment('', '');

        // 결제창 열기 전 history state 추가 → 뒤로가기 시 popstate 발생
        window.history.pushState({ nicepayOpen: true }, '');
        window.addEventListener('popstate', handlePopState);

        // 6. PC 결제창 호출 (iframe 팝업)
        window.goPay(form);

    } catch (error: unknown) {
        console.error('[sirsoft-pay_nicepayments] requestPayment error', error);
        (window as unknown as Record<string, unknown>)['__sirsoftNicepayPaymentInProgress'] = false;
        G7Core?.state?.setLocal?.({ isSubmittingOrder: false, paymentMethod });
        G7Core?.toast?.error?.('결제 중 오류가 발생했습니다. 잠시 후 다시 시도해주세요.');
    }
}
