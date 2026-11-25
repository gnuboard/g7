// e2e:allow 결제창 수단을 서버가 저장한 결제수단으로 정하도록 바로잡은 것이다. PG 결제창은 외부 테스트 서버라 spec 으로 재현하지 않고, NHN KCP 테스트 상점에서 신용카드와 가상계좌 결제창을 실제로 열어 확인했다(단위 테스트가 결정 함수를 잠근다). 면세 금액 상한(#820)은 Vitest 단언으로 잠근다 — 마일리지를 과세분보다 많이 쓴 면세 혼합 결제는 실계약 상점에서만 재현된다. / #855 클레임 추가결제의 결제창 열기 전 실패를 신청서로 넘김(문구를 담아 throw) — 외부 PG 결제창이라 spec 으로 재현하지 않고 Chrome MCP 실측(이력 20261114_0354 매트릭스 T2, T4-c, T8-e~g)과 Vitest 로 고정한다.
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

import { sitePath } from '../apiUrl';
import { buildGuestOwnershipHeaders } from '../guestOrderToken';
import { rememberPendingClose } from '../paymentCloseReport';
import { applePayUnsupportedMessage, isIosMobileDevice, isTossApplePayMethod } from '../support/applePayDevice';

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
    /** 결제 금액 중 면세분 (복합 과세 상점 계약 — 승인·취소에 함께 실어야 한다) */
    tax_free_amount?: number | null;
    currency?: string;
    customer_name?: string;
    customer_email?: string;
    customer_phone?: string;
    customer_key?: string | null;
    /** 서버가 저장한 결제수단 id (코어 id `card`·`vbank` 또는 `toss_*`) — 결제창 수단 결정의 기준 */
    payment_method?: string | null;
    escrow_products?: EscrowProduct[];
    // 클레임 추가결제에만 실린다 — 주문 결제에는 없다.
    //
    // 이 값이 있으면 이 건은 주문 결제가 아니라 클레임 추가결제이고, `order_number` 가
    // 가리키는 것도 주문번호가 아니라 결제번호(CP…)다. 사용자가 결제창을 스스로 닫았을 때
    // 돌아갈 주소는 서버(`ClaimPaymentRedirect`)가 만들어 실어 준다 — 회원/비회원 분기와
    // 폴백 경로가 그쪽 SSoT 에 있어 프런트가 조립하면 조용히 어긋난다.
    claim_payment_number?: string;
    claim_type?: string;
    claim_number?: string | null;
    claim_cancel_return_url?: string;
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
/**
 * SDK 스크립트를 로드할 수 있는 호스트 (plugin.json `trusted_script_hosts` 미러).
 *
 * 토스페이먼츠 결제위젯은 라이브러리가 아니라 그 회사 서버와 통신하는 서비스 SDK 라
 * 자체 호스팅할 수 없다. 대신 **주입 직전에** 호스트를 확인해, 설정·응답이 어떤
 * 경로로든 다른 주소를 지시하면 결제를 진행하지 않는다(fail-closed).
 *
 * PG사가 SDK 호스트를 바꾸면 이 상수와 plugin.json 을 **함께** 갱신한다 —
 * 둘이 어긋나면 테스트가 실패한다.
 *
 * 이 SDK URL(`/v2/standard`)은 확장자가 없어 정적 검사에 걸리지 않는다 —
 * 이 런타임 검증이 유일한 게이트다.
 */
export const KNOWN_SDK_HOSTS: readonly string[] = ['js.tosspayments.com'];

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

    const full = `sirsoft-tosspayments.${key}`;
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

    if (window.TossPayments) {
        return;
    }

    // 전역이 없는데 태그만 남아 있으면 미완료·실패 잔재다 — 제거 후 새로 로드한다.
    document.querySelectorAll(`script[src="${CSS.escape(src)}"]`).forEach((el) => el.remove());

    const loader = (window as any)?.G7Core?.asset?.loadScript;

    if (typeof loader === 'function') {
        await loader(src, {}, { label: 'tosspayments SDK' });

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

/**
 * 결제창에 쓸 결제수단 id 를 정합니다.
 *
 * 선택 순서:
 *   1. 액션 인자(`paymentMethod`)
 *   2. 클레임 추가결제가 아니고 주문서 화면 상태(`_local.paymentMethod`)가 `toss_` 로 시작하면 그 값
 *   3. 서버가 저장한 결제수단(`payment_method`)
 *
 * 서버는 주문서에서 고른 `toss_*` 를 코어 값(`card`·`vbank`)으로 바꿔 저장한다. 그래서 서버 값만 보면 간편결제 종류가
 * 사라져 `card` 의 첫 항목(`toss_card`)으로 풀리고, 카카오페이를 골라도 카드 통합결제창이 열렸다(#933 A0).
 * 클레임 추가결제 화면의 상태는 주문서 선택이 아니므로 보지 않는다. 화면 값과 서버 값이 어긋나면 `resolveMethod` 가
 * 서버 값으로 다시 찾는다(서버 우선).
 *
 * @param paramId 액션 인자로 받은 결제수단 id
 * @param serverId 서버가 결제 데이터에 실어 준 결제수단 id
 * @param localId 주문서 화면 상태의 결제수단 id (`_local.paymentMethod`)
 * @param isClaim 클레임 추가결제 여부
 * @returns 결제수단 id (모두 비어 있으면 undefined)
 */
export function resolveSelectedMethodId(
    paramId?: string | null,
    serverId?: string | null,
    localId?: string | null,
    isClaim = false,
): string | undefined {
    if (paramId) {
        return paramId;
    }

    if (!isClaim && typeof localId === 'string' && localId.startsWith('toss_')) {
        return localId;
    }

    return serverId || undefined;
}

/**
 * 결제수단 항목을 찾습니다 — toss_* id 를 먼저, 없으면 core_payment_method 로 찾습니다.
 *
 * @param methods 서버가 내려준 enabled_methods
 * @param id 찾을 id (toss_* 또는 코어 id)
 * @returns 찾은 항목 (없으면 undefined)
 */
function findEnabledMethod(methods: EnabledMethod[], id: string): EnabledMethod | undefined {
    return methods.find((m) => m.id === id) ?? methods.find((m) => m.core_payment_method === id);
}

/**
 * 선택된 결제수단 id 를 SDK method / easyPay provider 로 바꿉니다.
 *
 * 서버가 내려준 enabled_methods 에서 선택 id 를 찾아 SDK method / easyPay provider 를
 * 결정한다. 프론트는 결제수단 매핑을 하드코딩하지 않는다 (서버가 SSoT).
 * order_sheet_mode 가 off 이거나 선택 id 를 못 찾으면 통합결제창 카드(CARD)로 처리한다.
 *
 * 선택 id 는 두 가지로 온다 — 주문서에서 toss_* 항목을 직접 고르면 그 id(`toss_virtual_account`)
 * 가, 코어 카탈로그의 결제수단(신용카드·가상계좌)을 고르면 **코어 id**(`card`·`vbank`)가 온다.
 * 코어 id 를 못 찾고 CARD 로 강하하면 **가상계좌 주문에 카드 결제창이 열린다**(S1-D1). 그래서 toss_* id 를
 * 먼저 찾고, 없으면 core_payment_method 로 한 번 더 찾는다. 순서가 중요하다 — toss_kakaopay 의 core 는
 * card 라 코어를 먼저 맞추면 간편결제 자체창(easyPay)이 사라진다.
 *
 * 화면에서 고른 항목이 서버가 저장한 결제수단과 맞지 않으면(`entry.id !== serverId && entry.core_payment_method !== serverId`)
 * 그 항목을 버리고 서버 값으로 다시 찾는다 — 가상계좌로 저장된 주문에 간편결제 창이 열리면 안 된다.
 *
 * @param config 클라이언트 설정 (enabled_methods 포함)
 * @param selectedId 선택된 결제수단 id (toss_* 또는 코어 id)
 * @param serverId 서버가 저장한 결제수단 id
 * @returns SDK method 와 easyPay provider
 */
function resolveMethod(
    config: ClientConfig,
    selectedId?: string,
    serverId?: string | null,
): { method: string; easyPay: string | null } {
    if (!config.order_sheet_mode || !selectedId) {
        return { method: 'CARD', easyPay: null };
    }

    const methods = config.enabled_methods ?? [];
    let entry = findEnabledMethod(methods, selectedId);

    if (entry && serverId && entry.id !== serverId && entry.core_payment_method !== serverId) {
        entry = findEnabledMethod(methods, serverId);
    }

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
 * 에스크로 상품은 `attachEscrowProducts` 가 SDK 페이로드 최상위에 따로 붙인다 — 이 함수는 결제 데이터를 쓰지 않는다.
 *
 * @param config 클라이언트 설정 (vbank / use_escrow 포함)
 * @returns virtualAccount 페이로드
 */
function buildVirtualAccountPayload(config: ClientConfig): any {
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

/**
 * 클레임 추가결제(교환·반품·환불 신청서의 결제)인지 판별합니다 — 서버는 그때만 신청서 복귀 주소를 싣는다.
 *
 * @param pgPaymentData 결제 데이터
 * @returns 클레임 추가결제면 true
 */
function isClaimPayment(pgPaymentData: PgPaymentData): boolean {
    const url = pgPaymentData.claim_cancel_return_url;

    return typeof url === 'string' && url !== '';
}

/**
 * 결제창을 열기 전의 실패를 알립니다.
 *
 * 주문 결제는 주문서의 결제 오류 모달(`tosspayments_payment_error_modal`)에 문구를 띄운다(문구가 없으면 종전처럼 콘솔에만 남긴다).
 * 클레임 추가결제는 신청서 화면에서 열려 그 모달이 없다 — 모달을 열면 안내 없이 「결제 진행 중…」에 멈췄다. 그래서 문구를 담아
 * 던지고(문구가 없으면 빈 문구 — 신청서가 「결제를 시작하지 못했습니다.」로 대신한다), 이커머스 신청서 핸들러가 `G7Core.dispatch`
 * 결과로 받아 진행 표시를 내리고 문구를 보여 준다. 상태 코드 없는 오류라 템플릿의 상태 코드별 오류 처리는 타지 않는다.
 *
 * @param G7Core 코어 전역
 * @param pgPaymentData 결제 데이터
 * @param message 사용자에게 보일 문구 (null 이면 주문 결제에서는 모달을 열지 않는다)
 * @throws Error 클레임 추가결제면 문구를 담아 던진다
 */
function reportPaymentError(G7Core: any, pgPaymentData: PgPaymentData, message: string | null): void {
    if (isClaimPayment(pgPaymentData)) {
        throw new Error(message ?? '');
    }

    if (message === null) {
        return;
    }

    G7Core?.state?.setLocal?.({ paymentErrorMessage: message, isSubmittingOrder: false });
    G7Core?.modal?.open?.('tosspayments_payment_error_modal');
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
    const params = (action.params || {}) as RequestPaymentParams;
    const { pgPaymentData } = params;

    if (!pgPaymentData) {
        console.error('[sirsoft-tosspayments] pgPaymentData is required');
        return;
    }

    const G7Core = (window as any).G7Core;

    const selectedMethodId = resolveSelectedMethodId(
        params.paymentMethod,
        pgPaymentData.payment_method,
        G7Core?.state?.getLocal?.()?.paymentMethod,
        isClaimPayment(pgPaymentData),
    );

    // 애플페이는 iOS 기기에서만 결제된다. 주문서가 `requires_ios` 로 숨기지만 화면 상태를 조작해 골랐다면 결제 설정 조회·SDK 호출
    // 전에 멈춘다(토스 자체창은 iOS 밖에서 「지원되지 않는 간편결제 수단」 오류창을 띄운다). 서버 값이 card 가 아니면(가상계좌 등)
    // 결제창 결정에서 서버가 이기므로 막지 않는다.
    if (
        isTossApplePayMethod(selectedMethodId)
        && (!pgPaymentData.payment_method || pgPaymentData.payment_method === 'card')
        && !isIosMobileDevice()
    ) {
        reportPaymentError(G7Core, pgPaymentData, applePayUnsupportedMessage());
        return;
    }

    try {
        // 1. Client Config API 호출
        const configJson = await G7Core.api.get('/modules/sirsoft-ecommerce/payments/client-config/tosspayments');

        if (!configJson.data) {
            console.error('[sirsoft-tosspayments] Failed to fetch client config', configJson);
            reportPaymentError(G7Core, pgPaymentData, null);
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
            reportPaymentError(G7Core, pgPaymentData, null);
            return;
        }

        // 3. SDK 초기화
        const tossPayments = window.TossPayments(config.client_key);
        const payment = tossPayments.payment({
            customerKey: pgPaymentData.customer_key ?? window.TossPayments.ANONYMOUS,
        });

        // 4. 결제수단 결정 + 비KRW 차단
        const currency = pgPaymentData.currency ?? 'KRW';
        const { method, easyPay } = resolveMethod(config, selectedMethodId, pgPaymentData.payment_method);

        // 토스 국내 전용 수단(가상계좌·계좌이체·휴대폰·간편결제)은 비KRW 결제 불가. 카드만 허용.
        const domesticOnly = method !== 'CARD' || easyPay !== null;
        if (currency !== 'KRW' && domesticOnly) {
            console.warn('[sirsoft-tosspayments] non-KRW currency supports card only', { currency, method });
            reportPaymentError(
                G7Core,
                pgPaymentData,
                G7Core?.t?.('sirsoft-tosspayments.errors.non_krw_method') ?? 'This payment method is available for KRW only.',
            );
            return;
        }

        // 5. 결제 요청 페이로드 조립
        const requestPayload: any = {
            method,
            amount: {
                currency,
                value: pgPaymentData.amount,
            },
            orderId: pgPaymentData.order_number,
            orderName: pgPaymentData.order_name,
            successUrl: requireSiteAbsoluteUrl(config.callback_urls?.success),
            failUrl: requireSiteAbsoluteUrl(config.callback_urls?.fail),
            customerEmail: pgPaymentData.customer_email ?? undefined,
            customerName: pgPaymentData.customer_name ?? undefined,
            customerMobilePhone: pgPaymentData.customer_phone ?? undefined,
        };

        // 복합 과세 상점은 **승인 요청에도** 면세 금액을 실어야 한다 (S1-D12). 승인에서 빼면
        // PG 가 그 결제를 전액 과세로 등록해 두고, 나중에 면세분이 실린 취소를
        // 「취소 할 수 없는 금액 입니다」 로 거절한다 — 면세 상품이 섞인 주문의 부분환불이 막힌다.
        // 면세 금액은 결제 금액 안에 있어야 한다(totalAmount = taxFreeAmount + suppliedAmount + vat). 이커머스 1.3.2 이하는
        // 마일리지 차감 전 면세 합계를 그대로 실어, 마일리지를 과세분보다 많이 쓰면 결제 금액을 넘었다 — 결제 금액으로 자른다.
        if (typeof pgPaymentData.tax_free_amount === 'number' && pgPaymentData.tax_free_amount > 0) {
            requestPayload.taxFreeAmount = Math.min(pgPaymentData.tax_free_amount, Number(pgPaymentData.amount));
        }

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
            requestPayload.virtualAccount = buildVirtualAccountPayload(config);
            attachEscrowProducts(requestPayload, config, pgPaymentData);
        } else if (method === 'TRANSFER') {
            // 계좌이체 — 에스크로만 적용 대상
            const useEscrow = resolveEscrowFlag(config.use_escrow);
            if (useEscrow !== undefined) {
                requestPayload.transfer = { useEscrow };
            }
            attachEscrowProducts(requestPayload, config, pgPaymentData);
        }

        // 결제창은 전체 페이지 이동으로 열리고 돌아오므로, 실패 화면에서 서버에 보고할 때 쓸
        // 주문 정보를 미리 남겨 둔다. 브라우저 리턴 콜백은 인증이 없어 주문 상태를 바꾸지 않고,
        // 주문 소유자(회원 로그인 / 비회원 조회 토큰)를 확인하는 close-report 만이 정당한 결제 실패를 기록할 수 있다.
        rememberPendingClose({
            orderId: pgPaymentData.order_number,
            amount: pgPaymentData.amount,
        });

        await payment.requestPayment(requestPayload);
        // → 브라우저가 successUrl 또는 failUrl로 리다이렉트됨

    } catch (error: any) {
        console.error('[sirsoft-tosspayments] requestPayment error', error);

        // SDK에서 사용자 취소 시 에러가 발생할 수 있음
        if (error?.code === 'USER_CANCEL') {
            console.info('[sirsoft-tosspayments] Payment cancelled by user');

            // 클레임 추가결제는 **주문 결제가 아니다** — 취소 처분이 갈린다.
            //
            // 이 핸들러는 주문 체크아웃과 클레임 추가결제가 함께 쓴다. 두 경우의
            // `pgPaymentData.order_number` 가 가리키는 것이 다르다: 주문은 주문번호지만
            // 클레임은 **결제번호(CP…)** 다(결제 플러그인의 콜백이 그 값으로 결제 건을
            // 되찾도록 서버가 그렇게 싣는다). 그래서 종전처럼 주문 취소 경로로 보내면
            // `orders/CP…/cancel-payment` 가 되어 **404** 가 나고, 아래 catch 가
            // `console.warn` 으로 삼켜 화면에는 아무 신호도 남지 않았다. 이어지는
            // 체크아웃 전용 취소 모달도 신청서 화면에는 뜨지 않아, 구매자는 신청서가
            // 접수된 채 결제만 안 된 상태에서 **무슨 일이 있었는지 알 수 없었다**
            // (실측 EX20260915-1145077892 · CP20260915-1145087969).
            //
            // 클레임은 서버가 복귀 주소 계약을 이미 갖고 있다(`ClaimPaymentRedirect`):
            // 의도된 중단은 실패가 아니므로 `?claim_payment=cancelled` 로 신청서 상세에
            // 돌려보내고 화면이 재시도를 열어 준다. 취소 이력 기록 라우트는 클레임 쪽에
            // 없으므로(있는 것은 pay·approve 뿐) 호출하지 않는다 — 결제 건은
            // `pending` 그대로 남아 구매자가 다시 낼 수 있다.
            const claimReturnUrl = pgPaymentData.claim_cancel_return_url;

            if (claimReturnUrl) {
                G7Core?.state?.setLocal?.({ isSubmittingOrder: false });
                // 서버가 주는 신청서 상세 주소는 앱 내 경로(`/mypage/...`)라 사이트 마운트 접두사를 붙여 이동한다
                // (절대 URL·이미 접두사가 붙은 값은 그대로 — 멱등).
                window.location.href = sitePath(claimReturnUrl);

                return;
            }

            // 1. 결제 취소 이력 기록 API 호출 (PG사 응답값 전달)
            //
            // 이 엔드포인트는 회원/비회원 공유라 서버가 소유권을 대조한다. 비회원 주문은 조회
            // 토큰이 그 증명이므로 반드시 함께 보낸다 — 빠지면 서버가 404 로 거부하고, 여기서는
            // console.warn 만 남긴 채 취소 안내 모달이 평소대로 떠서 이력 유실이 드러나지 않는다.
            // 토큰은 주문 생성 직후 체크아웃이 발급해 쇼핑몰 계약 _global.guestOrderToken 에 넣어 둔다.
            try {
                const ownershipHeaders = buildGuestOwnershipHeaders();
                const config = Object.keys(ownershipHeaders).length > 0
                    ? { headers: ownershipHeaders }
                    : undefined;

                await G7Core.api.post(
                    `/modules/sirsoft-ecommerce/orders/${pgPaymentData.order_number}/cancel-payment`,
                    {
                        cancel_code: error.code,
                        cancel_message: error.message,
                    },
                    config
                );
            } catch (e) {
                console.warn('[sirsoft-tosspayments] Failed to record cancellation', e);
            }

            // 2. 로딩 상태 해제 + 취소 안내 모달 표시
            G7Core?.state?.setLocal?.({ isSubmittingOrder: false });
            G7Core?.modal?.open?.('tosspayments_payment_cancel_modal');
            return;
        }

        // 기타 에러 시 모달로 오류 표시 (체크아웃 페이지 유지). 클레임 추가결제는 신청서 핸들러로 넘긴다.
        reportPaymentError(G7Core, pgPaymentData, error?.message ?? 'Unknown error');
    }
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
