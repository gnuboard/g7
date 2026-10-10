/* eslint-disable @typescript-eslint/no-explicit-any */

import { toApiClientPath } from '../apiPath';
import {
    KOREAN_PAYMENT_FORM_ID_PREFIX,
    consumeStandardPaySdkReloadFlag,
    markMobilePaymentReturnPending,
    removeKoreanPaymentForms,
    resetStandardPaySdk,
} from '../paymentDomCleanup';
import {
    markStandardPaymentCloseReportContext,
} from '../paymentCloseMessageListener';

interface PgPaymentData {
    order_number: string;
    order_name: string;
    amount: number;
    currency?: string;
    /**
     * 서버가 저장한 결제수단 ID — 확장 수단이면 확장 ID 그대로(예: 'kginicis_lpay').
     * 이 값이 gopaymethod / CBT paymethod 매핑의 입력이 된다. 확장 결제수단이 1급 시민이
     * 되면서 서버가 원본 수단을 알고 내려주므로, 프론트 인터셉터가 따로 전달할 필요가 없다(#475).
     */
    payment_method?: string;
    customer_name?: string;
    customer_email?: string;
    customer_phone?: string;
    /**
     * 클레임 추가결제일 때만 서버가 싣는 「결제창을 닫으면 돌아갈 신청서 상세」 주소
     * (`ClaimPaymentRedirect::cancelled`). 있으면 `order_number` 는 결제번호(CP…)다.
     */
    claim_cancel_return_url?: string;
    /**
     * 가상계좌 주문의 입금 기한(쇼핑몰 자동취소 기한 — ISO 8601, 한국 시각). 결제창에 계좌 만료 시각으로 넘긴다.
     * 없으면 KG 기본 기한으로 계좌가 열린다.
     */
    vbank_due_at?: string;
}

/**
 * 주문 입금 기한(ISO 8601, 한국 시각)을 KG 형식 조각(YYYYMMDD · hhmm)으로 나눕니다.
 *
 * 서버가 한국 시각(+09:00)으로 싣으므로 글자 자리만 잘라 쓴다 — 브라우저 시간대로 다시 환산하지 않는다.
 *
 * @param dueAt 주문 입금 기한
 * @returns 날짜·시분 조각, 형식이 맞지 않으면 null
 */
function splitVbankDueAt(dueAt: string | undefined): { date: string; hhmm: string } | null {
    const m = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})/.exec(dueAt ?? '');
    return m ? { date: `${m[1]}${m[2]}${m[3]}`, hhmm: `${m[4]}${m[5]}` } : null;
}

interface RequestPaymentParams {
    pgPaymentData: PgPaymentData;
}

interface ClientConfig {
    mid: string;
    sdk_url: string;
    callback_urls: {
        signature: string;
        close_report?: string;
        callback: string;
        close: string;
        cbt_checkout_token: string;
        cbt_hash_data: string;
        cbt_callback: string;
        cbt_cvs_notify: string;
        cbt_auth_url: string;
        mobile_signature: string;
        mobile_callback: string;
        mobile_vbank_notify: string;
    };
    japan_enabled: boolean;
    japan_restrict_jpy_payment_methods?: boolean;
    japan_configured?: boolean;
    standard_configured?: boolean;
    mobile_configured?: boolean;
    easy_pay_enabled_methods?: string[];
    use_escrow: boolean;
    japan_mid: string;
    cbt_extra_data?: CbtExtraData;
    use_credit_point: boolean;
}

interface CbtExtraData {
    paymentUI?: Record<string, string>;
    payment?: {
        paymethod?: string[];
        isMobile?: string;
        card?: Record<string, unknown>;
        cvs?: Record<string, unknown>;
        linepay?: Record<string, unknown>;
    };
    sbpsPayment?: Record<string, string>;
    gmoPayment?: Record<string, string>;
}

interface SignatureResponse {
    signature: string;
    verification: string;
    mKey: string;
}

interface MobileSignatureResponse {
    chkfake: string;
    mobile_payment_url: string;
}

interface CbtHashDataResponse {
    hash_data: string;
}

interface CbtCheckoutTokenResponse {
    checkout_token: string;
}

declare global {
    interface Window {
        INIStdPay: any;
    }
}

function isMobileUserAgent(): boolean {
    if (typeof navigator === 'undefined') return false;

    const nav = navigator as Navigator & {
        userAgentData?: {
            mobile?: boolean;
            platform?: string;
        };
        maxTouchPoints?: number;
    };

    if (nav.userAgentData?.mobile !== undefined) {
        return nav.userAgentData.mobile;
    }

    const ua = (nav.userAgent || '').toLowerCase();
    const platform = ((nav.userAgentData?.platform ?? nav.platform) || '').toLowerCase();

    if (/android|iphone|ipad|ipod|windows phone|iemobile|blackberry|opera mini|mobile safari/.test(ua)) {
        return true;
    }

    if (/iphone|ipad|ipod|ios/.test(platform)) {
        return true;
    }

    const touchPoints = nav.maxTouchPoints ?? 0;

    return /macintosh|mac os x/.test(ua) && touchPoints > 1;
}

/**
 * SDK 스크립트를 로드할 수 있는 호스트 (plugin.json `trusted_script_hosts` 미러).
 *
 * KG 이니시스 결제창은 라이브러리가 아니라 그 회사 서버와 통신하는 서비스 SDK 라
 * 자체 호스팅할 수 없다. 대신 **주입 직전에** 호스트를 확인해, 설정·응답이 어떤
 * 경로로든 다른 주소를 지시하면 결제를 진행하지 않는다(fail-closed).
 *
 * PG사가 SDK 호스트를 바꾸면 이 상수와 plugin.json 을 **함께** 갱신한다 —
 * 둘이 어긋나면 테스트가 실패한다.
 */
export const KNOWN_SDK_HOSTS: readonly string[] = ['stgstdpay.inicis.com', 'stdpay.inicis.com'];

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

    const full = `sirsoft-pay_kginicis.${key}`;
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
 * PG 로 보낼 결과·통보 주소가 서버가 내려준 절대 URL 인지 확인하고 그대로 돌려줍니다.
 *
 * 보안: 브라우저의 현재 주소(window.location.origin)로 조립하지 않는다 — 별칭·하위 도메인·포트로
 * 접속한 사용자의 결제 결과·입금통보가 그 주소로 간다. 서버(client-config)가 사이트 주소로 만든 값만
 * 쓰고, 상대 경로가 오면(구버전 서버) 결제를 시작하지 않는다.
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
 * 결제 정보를 POST 할 PG 주소가 KG 이니시스 도메인인지 확인합니다.
 *
 * 보안: 서버·PG 응답이 준 form action 으로 거래 키·주문자 정보를 POST 한다 — https 이고
 * `*.inicis.com` 일 때만 제출한다(fail-closed, SDK 주소 검사와 같은 기준).
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

    if (parsed === null || parsed.protocol !== 'https:' || !/(^|\.)inicis\.com$/i.test(parsed.hostname)) {
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

    if (window.INIStdPay) {
        return;
    }

    // 전역이 없는데 태그만 남아 있으면 미완료·실패 잔재다 — 제거 후 새로 로드한다.
    document.querySelectorAll(`script[src="${CSS.escape(src)}"]`).forEach((el) => el.remove());

    const loader = (window as any)?.G7Core?.asset?.loadScript;

    if (typeof loader === 'function') {
        await loader(src, {}, { label: 'kginicis SDK' });

        return;
    }

    await new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = src;
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error(`Failed to load script: ${src}`));
        document.head.appendChild(script);
    });
}

function appendSelectedPaymentMethod(url: string, paymentMethod: string): string {
    if (!paymentMethod.startsWith('kginicis_')) {
        return url;
    }

    const resolved = new URL(url, window.location.origin);
    resolved.searchParams.set('selectedPaymentMethod', paymentMethod);

    return resolved.toString();
}

function normalizePaymentCurrency(currency?: string): string {
    const normalized = (currency ?? '').trim().toUpperCase();

    return normalized === '' || normalized === 'WON' ? 'KRW' : normalized;
}

function submitForm(action: string, fields: Record<string, string>, charset = 'utf-8', formId?: string): void {
    const form = document.createElement('form');
    if (formId) {
        form.id = formId;
    }
    form.method = 'POST';
    form.action = action;
    form.acceptCharset = charset;
    form.style.display = 'none';

    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    document.body.appendChild(form);
    form.submit();
}

/**
 * PC 결제수단 → INIStdPay gopaymethod 매핑.
 *
 * 일반 결제수단은 자기 자신을, 간편결제 변종은 KG 이니시스 매뉴얼이 지정한
 * "only{프로바이더}" 표기를 사용한다 (LPAY 가 아니라 'onlylpay').
 *
 * gnuboard5 shop/inicis/lpay_order.script.php 참조:
 *   gopaymethod = (inicis_settle_case === 'inicis_kakaopay') ? 'onlykakaopay' : 'onlylpay'
 *
 * KG 이니시스 간편결제 다이렉트 호출 옵션:
 *   - Samsung Pay: onlyssp
 *   - Naver Pay: onlynaverpay
 */
export const GOPAYMETHOD_MAP: Record<string, string> = {
    card:                 'Card',
    vbank:                'VBank',
    bank:                 'DirectBank',
    phone:                'HPP',
    kginicis_samsung_pay: 'onlyssp',
    kginicis_naverpay:    'onlynaverpay',
    kginicis_lpay:        'onlylpay',
    kginicis_kakaopay:    'onlykakaopay',
};

/**
 * 모바일 결제수단 → P_INI_PAYMENT 매핑 (manual.inicis.com/pay/stdpay_m.html#popup_7).
 *
 * 일반 결제수단만 P_INI_PAYMENT 로 분기 가능. 간편결제 (Samsung Pay / Naver Pay /
 * L.pay / 카카오페이) 는 모바일에서 *완전히 다른 엔드포인트* 를 사용하며 P_INI_PAYMENT
 * 가 아닌 P_RESERVED 의 'd_samsungpay=Y' / 'd_npay=Y' / 'd_lpay=Y' / 'd_kakaopay=Y' 힌트로
 * 식별된다. 따라서 본 맵은 일반 결제수단만 보유한다.
 *
 * 휴대폰결제 코드는 PC 의 'HPP' 가 아닌 'MOBILE' — 잘못된 P_INI_PAYMENT 응답 회귀 차단.
 */
export const MOBILE_PAYMETHOD_MAP: Record<string, string> = {
    card:  'CARD',
    vbank: 'VBANK',
    bank:  'BANK',
    phone: 'MOBILE',
};

/**
 * 모바일 간편결제 → P_RESERVED 에 추가할 hint 토큰 매핑.
 *
 * gnuboard5 mobile/shop/samsungpay/order.script.php 참조:
 *   d_samsungpay=Y / d_npay=Y / d_lpay=Y / d_kakaopay=Y
 *
 * 모바일 간편결제는 https://mobile.inicis.com/smart/wcard/ 로 폼 제출하며
 * P_INI_PAYMENT 를 보내지 않는다.
 */
export const MOBILE_EASY_PAY_RESERVED_HINT: Record<string, string> = {
    kginicis_samsung_pay: 'd_samsungpay=Y',
    kginicis_naverpay:    'd_npay=Y',
    kginicis_lpay:        'd_lpay=Y',
    kginicis_kakaopay:    'd_kakaopay=Y',
};

const DEFAULT_CBT_PAYMETHODS = ['CARD', 'CVS', 'PAYpay'];

const CBT_PAYMETHODS_BY_PAYMENT_METHOD: Record<string, string[]> = {
    card:                   ['CARD'],
    kginicis_japan_paypay: ['PAYpay'],
    kginicis_japan_cvs:    ['CVS'],
};

const CBT_ALLOWED_PAYMENT_METHODS = new Set(Object.keys(CBT_PAYMETHODS_BY_PAYMENT_METHOD));
const DOMESTIC_EASY_PAY_METHODS = new Set([
    'kginicis_samsung_pay',
    'kginicis_naverpay',
    'kginicis_lpay',
    'kginicis_kakaopay',
]);

/**
 * KG 이니시스 한국 모바일 결제
 *
 * 페이지 이동 방식: https://mobile.inicis.com/smart/payment/ 로 폼 제출
 * → KG 이니시스 인증 → P_NEXT_URL(서버)로 GET 리다이렉트 → 서버 승인
 */
async function requestMobileKoreanPayment(
    G7Core: any,
    config: ClientConfig,
    pgPaymentData: PgPaymentData,
    paymentMethod: string,
): Promise<void> {
    const timestamp = String(Math.floor(Date.now()));

    const sigJson: { data: MobileSignatureResponse } = await G7Core.api.post(
        toApiClientPath(config.callback_urls.mobile_signature),
        {
            oid: pgPaymentData.order_number,
            price: pgPaymentData.amount,
            timestamp,
            buyer_email: pgPaymentData.customer_email ?? '',
            buyer_phone: pgPaymentData.customer_phone ?? '',
        },
    );

    const { chkfake, mobile_payment_url: mobilePaymentUrl } = sigJson.data;

    // 메뉴얼(STEP 2) 표준 응답에는 P_OID 가 없음 — 주문번호를 쿼리스트링으로 echo 받아 회수.
    const nextUrl = appendSelectedPaymentMethod(
        requireSiteAbsoluteUrl(config.callback_urls.mobile_callback) +
            '?orderId=' + encodeURIComponent(pgPaymentData.order_number),
        paymentMethod,
    );

    const easyPayHint = MOBILE_EASY_PAY_RESERVED_HINT[paymentMethod];
    const isEasyPay = easyPayHint !== undefined;

    // 모바일 간편결제 (Samsung Pay / Naver Pay / L.pay / 카카오페이) 는 별도 엔드포인트 사용.
    // gnuboard5 mobile/shop/samsungpay/order.script.php 참조:
    //   form.action = 'https://mobile.inicis.com/smart/wcard/'
    // 백엔드가 반환한 mobilePaymentUrl (/smart/payment/) 의 마지막 path segment 를
    // 'wcard' 로 치환하여 호스트/스킴은 보존하면서 엔드포인트만 전환한다.
    const submitUrl = isEasyPay
        ? mobilePaymentUrl.replace(/\/smart\/[^/]+\/?$/, '/smart/wcard/')
        : mobilePaymentUrl;

    const iniPayment = MOBILE_PAYMETHOD_MAP[paymentMethod] ?? 'CARD';

    // 간편결제는 P_RESERVED 에 hint 추가 + P_SKIP_TERMS=Y. 일반 결제는 기존 옵션 유지.
    const baseReserved = config.use_escrow
        ? 'below1000=Y&vbank_receipt=Y&useescrow=Y&centerCd=Y&amt_hash=Y'
        : 'below1000=Y&vbank_receipt=Y&centerCd=Y&amt_hash=Y';
    const reserved = isEasyPay
        ? baseReserved.replace('&useescrow=Y', '') + '&' + easyPayHint
        : baseReserved;

    // 휴대폰결제(MOBILE) 는 P_HPP_METHOD 필수 — '1'=콘텐츠 / '2'=실물상품
    // (manual.inicis.com/pay/stdpay_m.html). 누락 시 PG 가 MX1006 으로 반려.
    //
    // 간편결제는 P_INI_PAYMENT 를 보내지 않는다 — gnuboard5 samsungpay/orderform.1.php
    // 에는 P_INI_PAYMENT 필드 자체가 없으며 결제 종류는 P_RESERVED 의 d_*pay=Y
    // hint 와 form action (/smart/wcard/) 으로 식별된다.
    const fields: Record<string, string> = {
        P_MID:         config.mid,
        P_OID:         pgPaymentData.order_number,
        P_AMT:         String(pgPaymentData.amount),
        P_GOODS:       pgPaymentData.order_name,
        P_UNAME:       pgPaymentData.customer_name ?? '',
        P_MOBILE:      pgPaymentData.customer_phone ?? '',
        P_EMAIL:       pgPaymentData.customer_email ?? '',
        P_NEXT_URL:    nextUrl,
        P_CHARSET:     'utf8',
        P_TIMESTAMP:   timestamp,
        P_CHKFAKE:     chkfake,
        P_RESERVED:    reserved,
    };

    if (! isEasyPay) {
        fields.P_INI_PAYMENT = iniPayment;
    } else {
        // 간편결제는 약관 동의 화면 skip (samsungpay/orderform.1.php 와 동일)
        fields.P_SKIP_TERMS = 'Y';
    }

    if (iniPayment === 'MOBILE' && ! isEasyPay) {
        fields.P_HPP_METHOD = '2';
    }

    // 가상계좌 결제 시 P_NOTI_URL 필수 (manual.inicis.com/pay/stdpay_m.html).
    // PC 가상계좌는 KG 이니시스 가맹점 어드민의 등록 URL 로 통보되지만, 모바일은
    // 요청에 P_NOTI_URL 을 명시해야 입금통보를 받을 수 있다.
    if (iniPayment === 'VBANK' && ! isEasyPay) {
        fields.P_NOTI_URL = requireSiteAbsoluteUrl(config.callback_urls.mobile_vbank_notify);

        // 계좌 만료 시각 = 주문 입금 기한(쇼핑몰 자동취소 기한). 넘기지 않으면 KG 기본 기한(10일)으로 열리고
        // 콜백이 그 값으로 주문 기한을 덮어써 주문서 안내와 어긋난다 (manual.inicis.com/pay/stdpay_m.html).
        const due = splitVbankDueAt(pgPaymentData.vbank_due_at);
        if (due) {
            fields.P_VBANK_DT = due.date;
            fields.P_VBANK_TM = due.hhmm;
        }
    }

    assertTrustedPgFormAction(submitUrl);
    markMobilePaymentReturnPending();
    submitForm(
        submitUrl,
        fields,
        'euc-kr',
        KOREAN_PAYMENT_FORM_ID_PREFIX + 'mobile_' + Date.now(),
    );
}

/**
 * KG 이니시스 한국 표준결제 (INIStdPay 팝업, PC 전용)
 */
async function requestKoreanPayment(
    G7Core: any,
    config: ClientConfig,
    pgPaymentData: PgPaymentData,
    paymentMethod: string,
): Promise<void> {
    const timestamp = String(Math.floor(Date.now()));

    const signatureJson: { data: SignatureResponse } = await G7Core.api.post(
        toApiClientPath(config.callback_urls.signature),
        {
            oid: pgPaymentData.order_number,
            price: pgPaymentData.amount,
            timestamp,
            buyer_email: pgPaymentData.customer_email ?? '',
            buyer_phone: pgPaymentData.customer_phone ?? '',
        },
    );

    const { signature, verification, mKey } = signatureJson.data;

    if (consumeStandardPaySdkReloadFlag()) {
        resetStandardPaySdk(config.sdk_url);
    }

    if (!window.INIStdPay) {
        await loadScript(config.sdk_url);
    }

    if (!window.INIStdPay) {
        await new Promise<void>((resolve) => setTimeout(resolve, 100));
    }

    if (!window.INIStdPay) {
        throw new Error('INIStdPay SDK not available');
    }

    const callbackUrl = appendSelectedPaymentMethod(
        requireSiteAbsoluteUrl(config.callback_urls.callback),
        paymentMethod,
    );
    // KG 이니시스 공식 closeUrl 페이지에서 결제창만 닫게 하여 체크아웃 SPA를 유지한다.
    const orderCloseUrl = requireSiteAbsoluteUrl(config.callback_urls.close);
    removeKoreanPaymentForms();

    const formId = KOREAN_PAYMENT_FORM_ID_PREFIX + Date.now();

    const form = document.createElement('form');
    form.id = formId;
    form.method = 'POST';
    form.acceptCharset = 'euc-kr';

    const fields: Record<string, string> = {
        version:      '1.0',
        mid:          config.mid,
        oid:          pgPaymentData.order_number,
        goodname:     pgPaymentData.order_name,
        price:        String(pgPaymentData.amount),
        currency:     'WON',
        buyername:    pgPaymentData.customer_name ?? '',
        buyeremail:   pgPaymentData.customer_email ?? '',
        buyertel:     pgPaymentData.customer_phone ?? '',
        timestamp,
        signature,
        verification,
        mKey,
        returnUrl:    callbackUrl,
        closeUrl:     orderCloseUrl,
        gopaymethod:  GOPAYMETHOD_MAP[paymentMethod] ?? 'Card',
        acceptmethod: (() => {
            // 기본 acceptmethod 토큰 (일반 결제수단 옵션)
            const escrow = config.use_escrow ? 'useescrow:' : '';
            const creditPoint = config.use_credit_point ? 'CREDITCARD(Y):' : '';
            const base = paymentMethod === 'phone'
                ? `HPP(1):${escrow}${creditPoint}centerCd(Y)`
                : `${escrow}${creditPoint}centerCd(Y)`;

            // 간편결제 (삼성페이 / 네이버페이 / LPAY / 카카오페이) 는 base 뒤에 ':cardonly' 를 append.
            // gnuboard5 orderform.4.php 의
            //   f.acceptmethod.value = f.acceptmethod.value + ":cardonly"
            // 와 일치 — 에스크로 옵션 등 base 를 보존하고 cardonly 만 추가.
            if (
                paymentMethod === 'kginicis_samsung_pay'
                || paymentMethod === 'kginicis_naverpay'
                || paymentMethod === 'kginicis_lpay'
                || paymentMethod === 'kginicis_kakaopay'
            ) {
                return base ? `${base}:cardonly` : 'cardonly';
            }

            // 가상계좌 계좌 만료 시각 = 주문 입금 기한(쇼핑몰 자동취소 기한) — `vbank(YYYYMMDDhhmm)`
            // (manual.inicis.com/pay/stdpay_pc.html). 넘기지 않으면 KG 기본 기한으로 열려 주문서 안내와 어긋난다.
            const due = paymentMethod === 'vbank' ? splitVbankDueAt(pgPaymentData.vbank_due_at) : null;
            if (due) {
                return `${base}:vbank(${due.date}${due.hhmm})`;
            }
            return base;
        })(),
        payViewType:  'overlay',
        use_chkfake:  'Y',
        charset:      'UTF-8',
    };

    for (const [name, value] of Object.entries(fields)) {
        const input = document.createElement('input');
        input.type = 'hidden';
        input.name = name;
        input.value = value;
        form.appendChild(input);
    }

    document.body.appendChild(form);

    // KG 이니시스 표준결제 닫힘 감지는 closeUrl(/payment/close) 페이지가 부모로 보내는
    // postMessage('payment-window-closed') 한 경로로만 처리한다. INIStdPay 가 사용자의
    // 명시적 닫기에서만 closeUrl 을 로드하므로, 성공(returnUrl 최상위 전송)·단순 이탈과
    // 확실히 구분된다. (iframe 존재 폴링 휴리스틱은 성공/이탈 오탐 때문에 제거됨)
    if (config.callback_urls.close_report) {
        markStandardPaymentCloseReportContext({
            closeReportUrl: config.callback_urls.close_report,
            oid: pgPaymentData.order_number,
            price: Number(pgPaymentData.amount),
            payment_method: paymentMethod,
            // 클레임 추가결제면 닫힘을 받은 리스너가 신청서 상세로 돌아간다(EC-455).
            ...(pgPaymentData.claim_cancel_return_url ? { claimCancelReturnUrl: pgPaymentData.claim_cancel_return_url } : {}),
        });
    }

    window.INIStdPay.pay(formId);
}

/**
 * KG 이니시스 CBT (일본 엔 결제) 처리
 *
 * 페이지 전환 방식: /cbtauth 로 POST 폼 전송 → KG 이니시스 인증 → returnUrl 로 리다이렉트 → 서버 승인
 */
/**
 * KG 이니시스 CBT 가 요구하는 timestamp 형식: yyyyMMddHHmmss (14 bytes)
 * 매뉴얼: https://manual.inicis.com/jppay/cbtauth.html
 */
function formatCbtTimestamp(date: Date = new Date()): string {
    const pad = (n: number): string => String(n).padStart(2, '0');
    return (
        date.getFullYear().toString() +
        pad(date.getMonth() + 1) +
        pad(date.getDate()) +
        pad(date.getHours()) +
        pad(date.getMinutes()) +
        pad(date.getSeconds())
    );
}

function buildCbtExtraData(config: ClientConfig, paymentMethod?: string): CbtExtraData {
    const base: CbtExtraData = config.cbt_extra_data ?? {};
    const selectedPaymethods = paymentMethod
        ? CBT_PAYMETHODS_BY_PAYMENT_METHOD[paymentMethod]
        : undefined;
    const paymethod = selectedPaymethods ?? base.payment?.paymethod ?? DEFAULT_CBT_PAYMETHODS;
    const cvsNotifyUrl = requireSiteAbsoluteUrl(config.callback_urls.cbt_cvs_notify);

    return {
        ...base,
        paymentUI: {
            language: 'JP',
            ...(base.paymentUI ?? {}),
        },
        payment: {
            ...(base.payment ?? {}),
            paymethod,
            isMobile: isMobileUserAgent() ? 'true' : 'false',
            cvs: {
                ...(base.payment?.cvs ?? {}),
                notiUrl: cvsNotifyUrl,
            },
        },
    };
}

async function requestCbtPayment(
    G7Core: any,
    config: ClientConfig,
    pgPaymentData: PgPaymentData,
    paymentMethod?: string,
): Promise<void> {
    const japanMid = config.japan_mid;
    const timestamp = formatCbtTimestamp();

    const buyerEmail = pgPaymentData.customer_email ?? '';
    const buyerPhone = pgPaymentData.customer_phone ?? '';
    const tokenResponse: { data: CbtCheckoutTokenResponse } = await G7Core.api.post(
        toApiClientPath(config.callback_urls.cbt_checkout_token),
        {
            oid: pgPaymentData.order_number,
            price: pgPaymentData.amount,
            buyer_email: buyerEmail,
            buyer_phone: buyerPhone,
        },
    );

    const { checkout_token: checkoutToken } = tokenResponse.data;

    const hashResponse: { data: CbtHashDataResponse } = await G7Core.api.post(
        toApiClientPath(config.callback_urls.cbt_hash_data),
        {
            oid: pgPaymentData.order_number,
            price: pgPaymentData.amount,
            timestamp,
            buyer_email: buyerEmail,
            buyer_phone: buyerPhone,
            checkout_token: checkoutToken,
        },
    );

    const { hash_data: hashData } = hashResponse.data;

    const returnUrl =
        requireSiteAbsoluteUrl(config.callback_urls.cbt_callback) +
        `?oid=${encodeURIComponent(pgPaymentData.order_number)}` +
        `&selectedPaymentMethod=${encodeURIComponent(paymentMethod ?? 'card')}`;

    assertTrustedPgFormAction(config.callback_urls.cbt_auth_url);
    submitForm(config.callback_urls.cbt_auth_url, {
        cbtType:     'JPPG',
        mid:         japanMid,
        timestamp,
        returnUrl,
        buyerName:   pgPaymentData.customer_name ?? '',
        buyerTel:    pgPaymentData.customer_phone ?? '',
        buyerEmail:  pgPaymentData.customer_email ?? '',
        goodName:    pgPaymentData.order_name,
        amount:      String(pgPaymentData.amount),
        orderId:     pgPaymentData.order_number,
        hashData,
        extraData:   JSON.stringify(buildCbtExtraData(config, paymentMethod)),
    });
}

/**
 * KG 이니시스 결제창 호출 핸들러
 *
 * 결제 흐름:
 *   - JPY (japan_enabled): CBT 페이지 전환 결제
 *   - KRW + 모바일 UA: 모바일 결제 (페이지 이동)
 *   - KRW + PC: INIStdPay 팝업 (표준결제)
 */
export async function requestPaymentHandler(action: any, _context?: any): Promise<void> {
    const { pgPaymentData } = (action.params || {}) as RequestPaymentParams;

    if (!pgPaymentData) {
        return;
    }

    // 결제수단은 액션 인자 하나에서만 읽는다 — 서버가 주문에 저장한 결제수단(pg_payment_data.payment_method)이
    // 단일 출처이고, 확장 수단이면 확장 ID 가 그대로 온다(#475). 화면 상태를 뒤져 채우면 서버와 다른 수단으로
    // 결제창을 열 수 있어, 없으면 결제창을 열지 않고 오류로 끝낸다.
    const paymentMethod = typeof pgPaymentData.payment_method === 'string' ? pgPaymentData.payment_method : '';

    const G7Core = (window as any).G7Core;

    try {
        if (paymentMethod === '') {
            throw new Error('KG Inicis payment method is missing from pg_payment_data.');
        }

        const configJson = await G7Core.api.get('/modules/sirsoft-ecommerce/payments/client-config/kginicis');

        if (!configJson.data) {
            throw new Error('Failed to fetch KG Inicis client config');
        }

        const config: ClientConfig = configJson.data;
        // 결제 통화도 액션 인자(서버가 주문 스냅샷 환율로 환산한 청구 통화)만 본다. 화면의 표시 통화
        // (`_global.preferredCurrency`)는 주문 뒤에 바뀔 수 있어 청구 금액(amount)과 짝이 맞지 않을 수 있다.
        const currency = normalizePaymentCurrency(pgPaymentData.currency);
        const isJpy = currency === 'JPY';
        const isKrw = currency === 'KRW';
        const isJapanPaymentMethod = paymentMethod.startsWith('kginicis_japan_');
        const isDomesticEasyPayMethod = DOMESTIC_EASY_PAY_METHODS.has(paymentMethod);
        const isJapanConfigured =
            config.japan_enabled &&
            !!config.japan_mid &&
            config.japan_configured !== false;
        const shouldRestrictJpyPaymentMethods = config.japan_restrict_jpy_payment_methods === true;

        if (isJapanPaymentMethod && !isJpy) {
            throw new Error('KG Inicis Japan payment methods require a JPY order.');
        }

        if (isDomesticEasyPayMethod && Array.isArray(config.easy_pay_enabled_methods)
            && !config.easy_pay_enabled_methods.includes(paymentMethod)
        ) {
            throw new Error('Selected KG Inicis easy pay method is disabled.');
        }

        if (!isJpy && !isKrw) {
            throw new Error('KG Inicis supports only KRW standard payments or JPY Japan CBT payments.');
        }

        if (isJpy && !isJapanConfigured) {
            throw new Error('KG Inicis Japan CBT payment is not configured.');
        }

        if (isJpy && shouldRestrictJpyPaymentMethods && !CBT_ALLOWED_PAYMENT_METHODS.has(paymentMethod)) {
            throw new Error('JPY orders can only use KG Inicis Japan CBT payment methods.');
        }

        const isMobile = isMobileUserAgent();

        if (isKrw && config.standard_configured === false) {
            throw new Error('KG Inicis live standard payment is not configured.');
        }

        if (isKrw && isMobile && config.mobile_configured === false) {
            throw new Error('KG Inicis live mobile payment is not configured.');
        }

        if (isJpy) {
            await requestCbtPayment(G7Core, config, pgPaymentData, paymentMethod);
        } else if (isMobile) {
            await requestMobileKoreanPayment(G7Core, config, pgPaymentData, paymentMethod);
        } else {
            await requestKoreanPayment(G7Core, config, pgPaymentData, paymentMethod);
        }

    } catch (error: unknown) {
        const errorMessage = error instanceof Error ? error.message : 'Unknown error';
        G7Core?.state?.setLocal?.({ paymentErrorMessage: errorMessage, isSubmittingOrder: false, paymentMethod });
        G7Core?.modal?.open?.('kginicis_payment_error_modal');
    }
}
