/**
 * 비회원 주문 조회 토큰 확보 (플러그인 공용)
 *
 * 비회원 주문은 서버가 `X-Guest-Order-Token` 으로 소유자를 확인한다. 결제창 닫힘 보고처럼
 * 주문 상태를 바꾸는 요청에 이 토큰을 싣지 않으면 서버는 정당한 비회원의 보고도 거부한다.
 *
 * 토큰은 쇼핑몰 모듈이 공개한 상태 계약 `_global.guestOrderToken` 하나에서만 읽는다(공개 API
 * `G7Core.state.get()`). 체크아웃·비회원 조회가 이 값을 채우고 로그인하면 비운다 — 저장소 키를
 * 직접 읽으면 모듈이 저장 방식을 바꿀 때 조용히 깨진다.
 */

/**
 * 비회원 주문 조회 토큰을 돌려줍니다.
 *
 * @return 토큰 또는 null
 */
export function getGuestOrderToken(): string | null {
    try {
        const token = (window as any).G7Core?.state?.get?.()?.guestOrderToken;

        return typeof token === 'string' && token !== '' ? token : null;
    } catch {
        return null;
    }
}

/**
 * 결제 도중 주문 상태를 바꾸는 요청(결제창 닫힘 보고 등)에 실을 소유자 증명 헤더를 만듭니다.
 *
 * 서버는 회원 주문을 로그인한 본인(Bearer)으로, 비회원 주문을 조회 토큰으로만 확인한다 —
 * 주문자 이메일·전화번호는 제3자도 알 수 있어 소유 증명이 되지 못한다. 회원 Bearer 는
 * G7Core.api 가 스스로 싣으므로 여기서는 비회원 조회 토큰만 다룬다. 로그인하면 쇼핑몰이 이
 * 토큰을 비우므로 회원 요청에는 실리지 않는다. 토큰이 없으면 빈 객체 — 서버가 보고를 거부하고,
 * 결제대기 주문은 만료 정리 잡이 정리한다.
 *
 * @return 헤더 객체 (실을 것이 없으면 빈 객체)
 */
export function buildGuestOwnershipHeaders(): Record<string, string> {
    const guestToken = getGuestOrderToken();

    return guestToken ? { 'X-Guest-Order-Token': guestToken } : {};
}
