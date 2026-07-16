/**
 * 주문 생성 요청 인터셉터 (Apple Pay 디바이스 가드 전용)
 *
 * 과거에는 이 인터셉터가 다음을 우회 목적으로 수행했다:
 *
 *   1. 요청 body 의 `payment_method` 를 'card' 로 위장 (서버 검증이 확장 ID 를 422 로 막았기 때문)
 *   2. 응답의 `requires_pg_payment` 를 false 로, `redirect_url` 을 self 로 변조
 *   3. `pg_payment_data` 를 직접 재구성해 결제창을 강제로 띄움
 *
 * 이 우회는 두 개의 심각한 결함을 낳았다 — 서버가 간편결제 주문을 "PG 결제가 아닌 주문"
 * 으로 오인해 (a) 결제 실패했는데 관리자에게 신규주문 알림이 발송되고 (b) 임시주문이
 * 즉시 삭제되어 재결제가 불가능해졌다.
 *
 * 이제 코어가 확장 결제수단을 1급 시민으로 받는다:
 *   - 서버 검증이 결제수단 카탈로그를 화이트리스트로 사용 (확장 ID 통과)
 *   - PG 플러그인이 `pg_provider` 를 자기 PG 로 고정 선언 → PG 결제 주문으로 정상 판정
 *   - provider 가 선언한 `payment_handler` 가 응답의 `pg_payment_handler` 로 내려가
 *     템플릿이 결제 진입 핸들러를 그대로 dispatch
 *
 * 따라서 위장·응답변조·pgPaymentData 재구성은 모두 불필요해졌고 제거했다.
 * 남은 책임은 서버가 알 수 없는 **클라이언트 디바이스 조건** 하나뿐이다:
 * Apple Pay 는 iOS 모바일에서만 동작하므로 그 외 환경에서는 요청 전에 차단한다.
 *
 * @see https://github.com/gnuboard/dev-g7/issues/475
 */

import {
    applePayUnsupportedMessage,
    isIosMobileDevice,
    isNhnKcpApplePayMethod,
} from './support/applePayDevice';

const ORDER_CREATE_PATH = '/api/modules/sirsoft-ecommerce/user/orders';
const PLUGIN_IDENTIFIER = 'sirsoft-pay_nhnkcp';

const logger = {
    info: (...args: unknown[]) => console.info(`[${PLUGIN_IDENTIFIER}]`, ...args),
    warn: (...args: unknown[]) => console.warn(`[${PLUGIN_IDENTIFIER}]`, ...args),
    error: (...args: unknown[]) => console.error(`[${PLUGIN_IDENTIFIER}]`, ...args),
};

function extractUrl(input: RequestInfo | URL): string {
    if (typeof input === 'string') return input;
    if (input instanceof URL) return input.toString();
    if (typeof Request !== 'undefined' && input instanceof Request) return input.url;
    return String(input);
}

function extractMethod(input: RequestInfo | URL, init?: RequestInit): string {
    if (init?.method) return init.method.toUpperCase();
    if (typeof Request !== 'undefined' && input instanceof Request) return input.method.toUpperCase();
    return 'GET';
}

function isTargetEndpoint(url: string, method: string): boolean {
    if (method !== 'POST') return false;
    const path = url.split('?')[0].split('#')[0];
    return path === ORDER_CREATE_PATH || path.endsWith(ORDER_CREATE_PATH);
}

function extractPaymentMethodFromBody(body: string): string | undefined {
    try {
        const parsed = JSON.parse(body) as Record<string, unknown>;
        return parsed['payment_method'] as string | undefined;
    } catch {
        return undefined;
    }
}

/**
 * Apple Pay 미지원 디바이스 차단 응답 (서버 검증과 동일한 422 형식).
 */
function buildPaymentMethodBlockedResponse(message: string): Response {
    return new Response(JSON.stringify({
        success: false,
        message,
        error: message,
        errors: {
            payment_method: [message],
        },
    }), {
        status: 422,
        statusText: 'Unprocessable Content',
        headers: {
            'Content-Type': 'application/json',
        },
    });
}

export function installOrderResponseInterceptor(): void {
    if (typeof window === 'undefined' || typeof window.fetch !== 'function') {
        return;
    }

    const flag = '__sirsoftNhnkcpInterceptorInstalled' as const;
    const w = window as unknown as Record<string, unknown>;
    if (w[flag]) {
        return;
    }
    w[flag] = true;

    const originalFetch = window.fetch.bind(window);

    window.fetch = async function patchedFetch(
        input: RequestInfo | URL,
        init?: RequestInit
    ): Promise<Response> {
        const url = extractUrl(input);
        const method = extractMethod(input, init);

        if (!isTargetEndpoint(url, method)) {
            return originalFetch(input, init);
        }

        // Apple Pay 디바이스 가드 — 서버는 요청자의 디바이스를 알 수 없으므로
        // 클라이언트에서만 판정 가능한 유일한 조건이다.
        let paymentMethod: string | undefined;
        if (init?.body && typeof init.body === 'string') {
            paymentMethod = extractPaymentMethodFromBody(init.body);
        }

        if (isNhnKcpApplePayMethod(paymentMethod) && !isIosMobileDevice()) {
            const message = applePayUnsupportedMessage();
            logger.warn(message);
            return buildPaymentMethodBlockedResponse(message);
        }

        // 그 외에는 요청/응답을 일절 변조하지 않는다 — 코어가 확장 결제수단을
        // 1급 시민으로 처리하고, 결제창 진입은 응답의 pg_payment_handler 로 dispatch 된다.
        return originalFetch(input, init);
    };

    logger.info('order request interceptor installed (Apple Pay device guard)');
}
