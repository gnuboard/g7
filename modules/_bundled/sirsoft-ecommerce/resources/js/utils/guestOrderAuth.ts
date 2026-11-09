/**
 * 비회원 주문 인증 실패(무토큰 401) 판정
 *
 * 비회원 화면(주문 조회·클레임 신청)의 요청은 로그인 토큰 없이 주문 조회 토큰(`X-Guest-Order-Token`)으로 주인을 증명한다.
 * 그 토큰이 없거나 만료되면 서버는 401 을 준다. 코어는 토큰 없이 보낸 요청의 401 을 세션 만료로 보지 않아 로그인 화면으로
 * 옮기지 않고 호출자에게 돌려준다(engine-v1.88.2, `G7Core.api.isAnonymousUnauthorized`). 종전에는 로그인 화면으로 옮겨져
 * 쓰던 신청서가 사라졌다(#883). 호출자는 이 판정으로 「주문 조회를 다시 하라」는 안내를 띄우고 입력을 그대로 둔다.
 */

/**
 * 토큰 없이 보낸 요청이 401 로 거부됐는지 판정합니다. 판정 메서드가 없는 옛 코어에서는 false 입니다.
 *
 * @param error 잡은 오류
 * @returns 무토큰 401 이면 true
 */
export function isGuestOrderAuthFailure(error: unknown): boolean {
    const api = (window as any)?.G7Core?.api;

    try {
        return api?.isAnonymousUnauthorized?.(error) === true;
    } catch {
        return false;
    }
}

/**
 * 번역 키를 화면 언어 문구로 바꿉니다(번역이 없으면 대체 문구).
 *
 * @param key 번역 키
 * @param fallback 대체 문구
 * @returns 문구
 */
function translate(key: string, fallback: string): string {
    const resolved = (window as any)?.G7Core?.t?.(key);

    return typeof resolved === 'string' && resolved !== '' && resolved !== key ? resolved : fallback;
}

/**
 * 클레임 신청·첨부에서 비회원 주문 확인이 필요할 때의 안내 문구.
 *
 * @returns 문구
 */
export function guestClaimAuthRequiredMessage(): string {
    return translate(
        'sirsoft-ecommerce.user.claim.guest_auth_required',
        '비회원 주문 확인이 필요합니다. 주문 조회를 다시 한 뒤 신청해 주세요. 입력한 내용은 그대로 있습니다.',
    );
}

/**
 * 비회원 주문 상세의 동작(구매확정·배송지 변경)에서 주문 확인이 필요할 때의 안내 문구.
 *
 * @returns 문구
 */
export function guestOrderAuthRequiredMessage(): string {
    return translate(
        'sirsoft-ecommerce.user.guest_order.auth_required',
        '비회원 주문 확인이 필요합니다. 주문 조회를 다시 한 뒤 시도해 주세요.',
    );
}
