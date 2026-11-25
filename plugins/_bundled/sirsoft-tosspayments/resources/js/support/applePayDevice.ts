// e2e:allow 애플페이 결제 시점 iOS 확인 도우미 — 실 iOS Safari 와 애플페이 계약 상점이 있어야 재현된다. Vitest(UA 스텁)와 Playwright MCP 실측(데스크톱·iPhone UA)으로 잠근다 (#933).
/**
 * 애플페이 결제 가능 기기 판정 (토스페이먼츠)
 *
 * 애플페이 자체창은 iOS·Safari 밖에서 토스가 「지원되지 않는 간편결제 수단」 으로 거절한다. 주문서는 카탈로그
 * `requires_ios` 로 이미 iOS 가 아니면 항목을 숨기지만, 화면 상태를 조작하면 고를 수 있으므로 결제 순간에 한 번 더 막는다.
 * 판정은 NHN KCP(`plugins/_bundled/sirsoft-pay_nhnkcp/resources/js/support/applePayDevice.ts`)와 같다 — UA 의 iphone/ipad/ipod,
 * 또는 Mac UA + 터치 포인트 2 이상(iPadOS 데스크톱 모드).
 */

/** 토스 애플페이 결제수단 id */
export const TOSS_APPLEPAY_METHOD = 'toss_applepay';

/**
 * 토스 애플페이 결제수단인지 확인합니다.
 *
 * @param paymentMethod 결제수단 id
 * @returns 애플페이면 true
 */
export function isTossApplePayMethod(paymentMethod: unknown): boolean {
    return paymentMethod === TOSS_APPLEPAY_METHOD;
}

/**
 * 지금 기기가 iOS(아이폰·아이패드)인지 확인합니다.
 *
 * @returns iOS 기기면 true
 */
export function isIosMobileDevice(): boolean {
    if (typeof navigator === 'undefined') return false;

    const nav = navigator as Navigator & {
        userAgentData?: {
            mobile?: boolean;
            platform?: string;
        };
        maxTouchPoints?: number;
    };
    const ua = (nav.userAgent || '').toLowerCase();
    const platform = ((nav.userAgentData?.platform ?? nav.platform) || '').toLowerCase();
    const touchPoints = nav.maxTouchPoints ?? 0;

    if (/iphone|ipad|ipod/.test(ua) || /iphone|ipad|ipod|ios/.test(platform)) {
        return true;
    }

    return /macintosh|mac os x/.test(ua) && touchPoints > 1;
}

/**
 * iOS 가 아닌 기기에서 애플페이를 고른 경우의 안내 문구를 반환합니다.
 *
 * @returns 화면 언어의 안내 문구 (번역 엔진이 없으면 한국어)
 */
export function applePayUnsupportedMessage(): string {
    const translate = (window as any)?.G7Core?.t;
    const key = 'sirsoft-tosspayments.payment.error.applepay_ios_only';
    const fallback = '애플페이는 iOS 기기(아이폰·아이패드)에서만 결제할 수 있습니다.';

    if (typeof translate !== 'function') {
        return fallback;
    }

    const result = translate(key);

    return typeof result === 'string' && result !== key ? result : fallback;
}
