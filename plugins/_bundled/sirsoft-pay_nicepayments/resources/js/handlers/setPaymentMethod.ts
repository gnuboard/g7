/* eslint-disable @typescript-eslint/no-explicit-any */

/**
 * 결제수단 선택 — 고른 결제수단 ID 를 화면 상태(`_local.paymentMethod`)에 그대로 저장한다.
 *
 * 간편결제(`nicepay_*`)는 이커머스 결제수단 목록에 1급 결제수단으로 등록되고(RegisterEasyPayMethodsListener),
 * 선택 표시는 그 목록이 그린다. 종전에는 주문서에 따로 붙이는 간편결제 버튼 조각과, 그 버튼을 「간편결제」 한국어
 * 문구로 찾아 그림자를 칠하는 DOM 탐색이 있었는데, 조각의 주입 대상(`nicepay_checkout_payment_section`)이 주문서
 * 어디에도 없어 한 번도 그려지지 않았고 다른 언어에서는 탐색 자체가 맞지 않았다 — 7.1.0 에서 둘 다 걷어냈다.
 *
 * @param action 액션 정의 — `params.paymentMethod` 에 결제수단 ID
 */
export function setPaymentMethodHandler(action: any): void {
    const paymentMethod = action.params?.paymentMethod;
    if (!paymentMethod) return;

    // 확장 결제수단은 1급 시민 — 선택한 수단 ID 를 그대로 서버에 보낸다.
    // (과거에는 서버 검증이 확장 ID 를 422 로 막아 'card' 로 정규화한 serverPaymentMethod 를
    //  함께 저장했으나, 소비처가 없는 죽은 코드였고 위장 자체가 결함의 원인이었다 — #475)
    (window as any).G7Core?.state?.setLocal?.({ paymentMethod });
}
