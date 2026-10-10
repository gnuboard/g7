/**
 * 비회원 주문 조회 토큰 (플러그인 공용)
 *
 * 결제창 닫힘 보고·재결제 준비처럼 주문 상태를 바꾸는 요청은 서버가 주문 소유자만 받아 준다. 회원 주문은
 * 로그인한 본인(Bearer — `G7Core.api` 가 싣는다), 비회원 주문은 주문 생성 때 발급된 조회 토큰
 * (`X-Guest-Order-Token`)으로 확인한다.
 *
 * 토큰은 공개 상태 계약 `_global.guestOrderToken`(sirsoft-ecommerce module.json `state_contracts`)으로만 읽는다.
 * 템플릿이 세션 저장소에서 이 값을 되살려 두므로, 저장소 키를 플러그인이 직접 읽지 않는다 — 저장 방식이 바뀌어도
 * 이 계약만 지켜지면 그대로 동작한다.
 */

/** 템플릿이 토큰을 되살릴 때까지 기다리는 최대 시간(ms) */
const TOKEN_WAIT_MS = 3000;

interface G7CoreState {
    get?: () => Record<string, unknown> | undefined;
    subscribe?: (listener: (state: Record<string, unknown>) => void) => (() => void) | undefined;
}

/**
 * 코어 공개 상태 API 를 돌려줍니다.
 *
 * @return 상태 API (코어가 아직 없으면 undefined)
 */
function coreState(): G7CoreState | undefined {
    return (window as unknown as { G7Core?: { state?: G7CoreState } }).G7Core?.state;
}

/**
 * 비회원 주문 조회 토큰을 돌려줍니다.
 *
 * @return 토큰 또는 null
 */
export function getGuestOrderToken(): string | null {
    let token: unknown;
    try {
        token = coreState()?.get?.()?.guestOrderToken;
    } catch {
        return null;
    }

    return typeof token === 'string' && token !== '' ? token : null;
}

/**
 * 주문 상태를 바꾸는 요청에 실을 비회원 소유자 증명 헤더를 만듭니다.
 *
 * 회원 주문은 서버가 로그인 사용자로만 판정하므로 이 헤더가 함께 실려도 결과가 달라지지 않는다.
 * 토큰이 없으면 빈 객체 — 서버가 보고를 거부하고, 결제대기 주문은 이커머스 만료 정리 잡이 정리한다.
 *
 * @return 헤더 객체 (실을 것이 없으면 빈 객체)
 */
export function guestOwnershipHeaders(): Record<string, string> {
    const token = getGuestOrderToken();

    return token ? { 'X-Guest-Order-Token': token } : {};
}

/**
 * 템플릿이 비회원 조회 토큰을 전역 상태에 되살릴 때까지 잠시 기다립니다.
 *
 * 결제창에서 돌아온 직후에는 템플릿의 초기화 액션(세션 저장소 → `_global.guestOrderToken`)이 아직 돌지
 * 않았을 수 있다. 토큰이 이미 있으면 바로 끝나고, 없으면 전역 상태 변경을 구독하다가 토큰이 생기거나
 * 제한 시간이 지나면 끝난다(회원이면 토큰이 생기지 않으므로 제한 시간까지 기다린 뒤 진행한다).
 *
 * @param timeoutMs 최대 대기 시간(ms)
 * @return 대기가 끝나면 이행되는 Promise
 */
export function waitForGuestOrderToken(timeoutMs: number = TOKEN_WAIT_MS): Promise<void> {
    if (getGuestOrderToken() !== null) {
        return Promise.resolve();
    }

    return new Promise<void>((resolve) => {
        let finished = false;
        let unsubscribe: (() => void) | undefined;

        const finish = (): void => {
            if (finished) return;
            finished = true;
            clearTimeout(timer);
            unsubscribe?.();
            resolve();
        };

        const timer = setTimeout(finish, timeoutMs);

        try {
            unsubscribe = coreState()?.subscribe?.(() => {
                if (getGuestOrderToken() !== null) finish();
            }) ?? undefined;
        } catch {
            // 구독할 수 없으면 제한 시간까지 기다린다.
        }

        // 구독 중에 끝났으면(동기 통지) 방금 받은 해제 함수도 바로 부른다.
        if (finished) unsubscribe?.();
    });
}
