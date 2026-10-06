/**
 * 비회원 주문 조회 토큰 헤더 (플러그인 공용)
 *
 * 비회원 주문은 서버가 `X-Guest-Order-Token` 으로 소유자를 확인한다 — 주문자 이메일·전화번호는 제3자도
 * 알 수 있어 소유 증명이 되지 못한다. 토큰은 이커머스가 약속한 공개 상태 계약 `_global.guestOrderToken`
 * (sirsoft-ecommerce module.json `state_contracts`)에서만 읽는다. 저장소 키(sessionStorage)나 로그인 토큰을
 * 직접 읽지 않는다 — 회원 Bearer 는 G7Core.api 가 싣고 401 도 코어가 처리한다(#639).
 *
 * 회원 주문은 서버가 로그인 본인으로만 판정하므로 이 헤더가 함께 실려도 결과가 바뀌지 않는다.
 */

/**
 * 비회원 주문 조회 토큰을 돌려줍니다.
 *
 * @return 토큰 또는 null
 */
export function getGuestOrderToken(): string | null {
    try {
        // eslint-disable-next-line @typescript-eslint/no-explicit-any
        const token = (window as any).G7Core?.state?.get?.()?.guestOrderToken;

        return typeof token === 'string' && token !== '' ? token : null;
    } catch {
        return null;
    }
}

/**
 * 주문 소유자 증명 헤더를 만듭니다 (비회원 조회 토큰).
 *
 * @return 헤더 객체 (토큰이 없으면 빈 객체 — 서버가 비회원 요청을 거부한다)
 */
export function buildGuestOrderTokenHeaders(): Record<string, string> {
    const token = getGuestOrderToken();

    return token ? { 'X-Guest-Order-Token': token } : {};
}
