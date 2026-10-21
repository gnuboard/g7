// e2e:allow 이벤트 전파만 멈추는 무상태 핸들러 — 헤더 통화 선택 Esc 의 서랍 전파 축은 headerCurrencySelectorEscape.test.tsx(엔진 렌더)가 덮고, 브라우저 축은 7.1.1 출시 전 캠페인 재실측(관리자·사용자 헤더 통화 선택 Esc)이 덮는다.
/**
 * 이벤트 전파를 조건부로 멈추는 핸들러
 *
 * 엔진의 액션 키 `stopPropagation: true` 는 조건을 받지 못한다 — 진입점 액션을 실행하기 **전에**(액션의 `if` 를
 * 보기 전에) 전파를 막으므로, `if` 를 함께 달아도 거짓일 때까지 막아 버린다. 펼침 목록의 트리거처럼 「열려 있을 때의
 * Esc 만 내 것이고, 닫혀 있을 때의 Esc 는 바깥(서랍 닫기)에 넘긴다」를 나타내려면 이 핸들러를 진입점 액션으로 두고
 * 액션의 `if` 로 조건을 단다 — `if` 가 거짓이면 이 핸들러가 불리지 않아 이벤트가 그대로 올라간다.
 *
 * 반드시 `<on>` 바로 아래의 진입점 액션으로 둔다(`sequence`·`conditions` 안쪽 금지). 화면 이벤트는 바깥 요소로
 * 동기적으로 올라가므로 전파는 이벤트를 받은 그 자리에서 곧바로 막아야 한다 — 다른 동작을 기다린 뒤에는 이미 늦다.
 * 상태는 쓰지 않는다.
 *
 * @example
 * ```html
 * <on type="keydown" key="Escape">
 *   <action handler="sirsoft-ecommerce.stopEventPropagation" if="{{_local.showCurrencyDropdown}}"/>
 * </on>
 * ```
 */

/**
 * 받은 이벤트의 전파를 멈춥니다.
 *
 * @param _action 액션 정의 (인자 없음)
 * @param context 액션 컨텍스트 (`event` 의 전파를 멈춘다)
 * @returns 전파를 멈췄으면 true (이벤트가 없으면 false)
 */
export function stopEventPropagationHandler(
    _action: unknown,
    context?: { event?: { stopPropagation?: () => void } | Event }
): boolean {
    const event = context?.event;
    if (!event || typeof event.stopPropagation !== 'function') {
        return false;
    }
    event.stopPropagation();

    return true;
}
