export const NHNKCP_APPLEPAY_METHOD = 'nhnkcp_applepay';

export function isNhnKcpApplePayMethod(paymentMethod: unknown): boolean {
    return paymentMethod === NHNKCP_APPLEPAY_METHOD;
}

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

export function applePayUnsupportedMessage(): string {
    const lang = typeof document !== 'undefined'
        ? document.documentElement.lang
        : '';

    if ((lang || '').toLowerCase().startsWith('ko')) {
        return '애플페이는 IOS 기기에 모바일결제만 가능합니다.';
    }

    return 'Apple Pay is available only for mobile payments on iOS devices.';
}
