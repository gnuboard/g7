/**
 * 비회원 주문 조회 토큰 확보 (플러그인 공용)
 *
 * 비회원 주문의 영수증·결제수단 정보는 서버가 `X-Guest-Order-Token` 으로 소유자를
 * 확인한다. 이 토큰을 싣지 않으면 서버는 그 주문을 찾을 수 없다고 응답하므로,
 * 비회원 손님에게는 영수증 버튼이 아예 나타나지 않는다 — 예외도 콘솔 오류도 남지
 * 않아 운영자가 원인을 알 수 없다.
 *
 * 코어 storageHandlers 가 sessionStorage 에 저장하며, 그 접근이 막힌 환경
 * (프라이빗 창·iframe)을 위해 전역 상태 폴백을 함께 본다.
 */

/**
 * 회원 인증 토큰을 돌려줍니다.
 *
 * @return 토큰 또는 null
 */
export function getAuthToken(): string | null {
    return localStorage.getItem('auth_token');
}

/**
 * 비회원 주문 조회 토큰을 돌려줍니다.
 *
 * @return 토큰 또는 null
 */
export function getGuestOrderToken(): string | null {
    try {
        const sessionToken = sessionStorage.getItem('g7_guest_order_token');
        if (sessionToken) return sessionToken;
    } catch {
        // sessionStorage 접근 불가 환경 — 전역 상태 폴백으로 진행
    }

    const globalToken = (window as any).G7Core?.state?.get?.('_global')?.guestOrderToken;

    return typeof globalToken === 'string' && globalToken !== '' ? globalToken : null;
}

/**
 * 주문 조회 요청 헤더를 만듭니다. 회원 토큰을 우선하고, 없으면 비회원 토큰을 씁니다.
 *
 * @return 헤더 객체. 둘 다 없으면 null (호출 자체를 하지 않아야 함)
 */
export function buildOrderRequestHeaders(): Record<string, string> | null {
    const authToken = getAuthToken();
    const guestToken = getGuestOrderToken();

    if (!authToken && !guestToken) {
        return null;
    }

    const headers: Record<string, string> = { Accept: 'application/json' };

    if (authToken) {
        headers.Authorization = `Bearer ${authToken}`;
    } else if (guestToken) {
        headers['X-Guest-Order-Token'] = guestToken;
    }

    return headers;
}

/**
 * 결제 도중 주문 상태를 바꾸는 요청(결제창 닫힘 보고 등)에 실을 소유자 증명 헤더를 만듭니다.
 *
 * 서버는 회원 주문을 로그인한 본인(Bearer)으로, 비회원 주문을 조회 토큰으로만 확인한다 —
 * 주문자 이메일·전화번호는 제3자도 알 수 있어 소유 증명이 되지 못한다. 회원 Bearer 는
 * G7Core.api 가 스스로 싣으므로 여기서는 비회원 조회 토큰만 다룬다. 로그인 상태면 싣지 않는다.
 * 토큰이 없으면 빈 객체 — 서버가 보고를 거부하고, 결제대기 주문은 만료 정리 잡이 정리한다.
 *
 * @return 헤더 객체 (실을 것이 없으면 빈 객체)
 */
export function buildGuestOwnershipHeaders(): Record<string, string> {
    let authToken: string | null = null;
    try {
        authToken = getAuthToken();
    } catch {
        // localStorage 접근 불가 환경 — 비로그인으로 간주
    }

    if (authToken) {
        return {};
    }

    const guestToken = getGuestOrderToken();

    return guestToken ? { 'X-Guest-Order-Token': guestToken } : {};
}

/**
 * 요청 주소가 현재 문서와 같은 출처인지 판정합니다.
 *
 * G7Core.api 없이 fetch 로 직접 보낼 때 조회 토큰을 다른 출처로 흘리지 않기 위한 확인이다.
 *
 * @param url 요청 주소 (절대·상대 모두)
 * @return 같은 출처면 true
 */
export function isSameOriginUrl(url: string): boolean {
    try {
        return new URL(url, window.location.origin).origin === window.location.origin;
    } catch {
        return false;
    }
}
