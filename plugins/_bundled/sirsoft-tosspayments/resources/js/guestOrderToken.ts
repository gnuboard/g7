/**
 * 비회원 주문 조회 토큰 확보 (플러그인 공용)
 *
 * 비회원 주문은 서버가 `X-Guest-Order-Token` 으로 소유자를 확인한다. 결제창 닫힘 보고처럼
 * 주문 상태를 바꾸는 요청에 이 토큰을 싣지 않으면 서버는 정당한 비회원의 보고도 거부한다.
 *
 * 토큰은 쇼핑몰 공개 상태 계약 `_global.guestOrderToken` 하나에서만 읽는다(쓰는 쪽은 사용자 템플릿 —
 * 주문 생성·조회 응답의 토큰을 저장하고 화면 초기화 때 다시 올린다). 템플릿의 저장소 키를 직접 읽지
 * 않는다 — 저장 방식은 템플릿이 정하고 바꿀 수 있다.
 *
 * 회원 인증은 이 파일이 다루지 않는다. `G7Core.api` 가 Bearer 를 싣고 401 을 처리한다.
 */

/** 계약 값이 올라오길 기다리는 간격(ms) */
const CONTRACT_POLL_INTERVAL_MS = 100;

/** 계약 값을 기다리는 최대 횟수 (100ms × 50 = 5초, 핸들러 등록 대기와 같은 상한) */
const CONTRACT_POLL_MAX_ATTEMPTS = 50;

/**
 * 현재 전역 상태를 돌려줍니다.
 *
 * @return 전역 상태 (G7Core 미준비면 빈 객체)
 */
function globalState(): Record<string, unknown> {
    const state = (window as any).G7Core?.state?.get?.();

    return state && typeof state === 'object' ? state : {};
}

/**
 * 비회원 주문 조회 토큰을 돌려줍니다.
 *
 * @return 토큰 또는 null
 */
export function getGuestOrderToken(): string | null {
    const token = globalState().guestOrderToken;

    return typeof token === 'string' && token !== '' ? token : null;
}

/**
 * 결제 도중 주문 상태를 바꾸는 요청(결제창 닫힘 보고 등)에 실을 소유자 증명 헤더를 만듭니다.
 *
 * 서버는 회원 주문을 로그인한 본인(Bearer)으로, 비회원 주문을 조회 토큰으로만 확인한다 —
 * 주문자 이메일·전화번호는 제3자도 알 수 있어 소유 증명이 되지 못한다. 회원 주문은 서버가 이 헤더를
 * 보지 않으므로 토큰이 있으면 그대로 싣는다. 토큰이 없으면 빈 객체 — 서버가 비회원 보고를 거부하고,
 * 결제대기 주문은 만료 정리 잡이 정리한다.
 *
 * @return 헤더 객체 (실을 것이 없으면 빈 객체)
 */
export function buildGuestOwnershipHeaders(): Record<string, string> {
    const guestToken = getGuestOrderToken();

    return guestToken ? { 'X-Guest-Order-Token': guestToken } : {};
}

/**
 * 템플릿이 `_global.guestOrderToken` 을 올릴 때까지 기다립니다.
 *
 * 플러그인 스크립트는 화면 초기화(템플릿의 init_actions)보다 먼저 실행된다. 템플릿은 초기화 때 토큰이
 * 없어도 이 키를 null 로 세우므로, 키가 생기면 계약 값이 확정된 것이다. 키를 세우지 않는 템플릿을
 * 위해 상한을 두고, 상한이 지나면 그대로 진행한다(토큰 없이 보낸다).
 *
 * @return 기다림이 끝나면 resolve
 */
export function waitForGuestOrderTokenContract(): Promise<void> {
    const isReady = () => Object.prototype.hasOwnProperty.call(globalState(), 'guestOrderToken');

    if (isReady()) {
        return Promise.resolve();
    }

    return new Promise((resolve) => {
        let attempts = 0;
        const timer = setInterval(() => {
            attempts++;

            if (isReady() || attempts >= CONTRACT_POLL_MAX_ATTEMPTS) {
                clearInterval(timer);
                resolve();
            }
        }, CONTRACT_POLL_INTERVAL_MS);
    });
}
