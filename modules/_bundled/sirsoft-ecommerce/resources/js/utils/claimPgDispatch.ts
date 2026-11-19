// e2e:allow #855 클레임 추가결제의 결제창 열기 전 실패를 신청서로 넘김(문구를 담아 throw) — 외부 PG 결제창이라 spec 으로 재현하지 않고 Chrome MCP 실측(이력 20261114_0354 매트릭스 T2, T4-c, T8-e~g)과 Vitest 로 고정한다.
/**
 * 클레임 추가결제의 PG 결제창 진입 결과 판정
 *
 * 신청서(교환·반품·환불) 화면은 결제 시작 API 가 지목한 결제 플러그인 핸들러(`pg_payment_handler`)를
 * `G7Core.dispatch` 로 부르고, 결제창이 열리는 동안 진행 표시를 일부러 내리지 않는다. 결제 플러그인이
 * 결제창을 열기 **전에** 실패하면(승인키·서명 발급 거절, 비회원 주문 확인 만료, 금액 불일치 등) 클레임 결제에서는
 * 사용자 문구를 담은 오류를 던지기로 약속한다 — 주문서 전용 오류 모달은 신청서 화면에 없기 때문이다.
 * `G7Core.dispatch` 는 그 오류를 던지지 않고 `{ success: false, error }` 로 돌려주므로, 신청서 핸들러는 이 판정으로
 * 실패를 알아채 진행 표시를 내리고 문구를 보여 준다. 종전에는 결과를 보지 않아 안내 없이 「결제 진행 중…」에 멈췄다(#855).
 */

/**
 * 결제 플러그인 핸들러를 부를 동작을 만듭니다.
 *
 * 핸들러가 실패를 던지면 디스패처의 기본 오류 처리(상태 코드 없는 오류는 `default` → 토스트)가 내부 문구
 * 「Failed to execute action: …」를 토스트로 띄운다. 실패 안내는 신청서 핸들러가 플러그인 문구로 직접 하므로, 이 동작의
 * 오류 처리를 `suppress`(엔진의 의도적 억제 핸들러)로 막는다 — 결과(`success: false`)는 그대로 돌아온다.
 *
 * @param handler 결제 시작 API 가 지목한 결제 플러그인 핸들러
 * @param pgPaymentData 결제창 진입 파라미터
 * @returns `G7Core.dispatch` 에 넘길 동작
 */
export function claimPgDispatchAction(handler: string, pgPaymentData: unknown): Record<string, unknown> {
    return {
        handler,
        params: { pgPaymentData },
        errorHandling: { default: { handler: 'suppress' } },
    };
}

/** `G7Core.dispatch` 결과 중 이 판정이 읽는 부분 */
interface DispatchResultLike {
    success?: boolean;
    error?: { message?: string; originalError?: { message?: string } } | null;
}

/**
 * 결제 플러그인 핸들러 실행 결과가 실패면 사용자에게 보일 문구를, 성공(또는 결과 없음)이면 null 을 돌려줍니다.
 *
 * 디스패처는 핸들러 오류를 `ActionError`(「Failed to execute action: …」)로 감싸고 원래 오류를 `originalError` 에 둔다.
 * 사용자 문구는 원래 오류에 있으므로 그것을 먼저 읽고, 없으면 대체 문구를 쓴다 — 감싼 오류의 내부 문구는 보이지 않는다.
 *
 * @param result `G7Core.dispatch` 결과
 * @param fallback 원래 오류에 문구가 없을 때 쓸 문구
 * @returns 실패 문구 또는 null
 */
export function claimPgDispatchFailureMessage(result: unknown, fallback: string): string | null {
    if (result === null || typeof result !== 'object') {
        return null;
    }

    const outcome = result as DispatchResultLike;

    if (outcome.success !== false) {
        return null;
    }

    const message = outcome.error?.originalError?.message;

    return typeof message === 'string' && message.trim() !== '' ? message : fallback;
}
