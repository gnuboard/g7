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
