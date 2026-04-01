/**
 * 상품 옵션 선택 관련 핸들러
 *
 * 상품 상세 페이지에서 옵션 선택 및 수량 변경을 처리합니다.
 * DB의 option_groups (문자열 값 배열)과 options (ProductOption 레코드)를 사용합니다.
 *
 * G7Core ActionDispatcher는 커스텀 핸들러를 (action, context) 시그니처로 호출합니다.
 * - action.params: 레이아웃 JSON에서 정의한 params (resolveParams로 이미 해석됨)
 * - context.setState: 컴포넌트 상태 업데이트 함수
 *
 * ⚠️ sequence 내에서 setState 후 다음 액션의 context.data._local은 갱신되지 않음
 *    (ActionDispatcher.handleSequence는 state만 동기화, data._local은 미갱신)
 *    따라서 이 핸들러는 newGroupName+newValue를 직접 받아서 currentSelection을 자체 구성합니다.
 */
/**
 * G7Core ActionContext (ActionDispatcher에서 전달)
 */
interface ActionContext {
    data?: any;
    event?: Event;
    state?: any;
    setState?: (updates: any) => void;
}
/**
 * G7Core ActionDefinition (커스텀 핸들러 첫 번째 인자)
 */
interface ActionDefinition {
    handler: string;
    params?: Record<string, any>;
    target?: string;
    [key: string]: any;
}
/**
 * 옵션 선택 완료 시 selectedItems에 추가
 *
 * 모든 옵션 그룹이 선택되면 매칭되는 ProductOption을 찾아 선택 목록에 추가합니다.
 * 동일한 옵션 조합이 이미 있으면 토스트 알림 + 선택 초기화합니다.
 *
 * ⚠️ sequence 내 setState 후 context.data._local이 갱신되지 않는 G7Core 한계 때문에
 *    newGroupName + newValue를 직접 받아서 currentSelection을 자체 병합합니다.
 *
 * G7Core에서 (action: ActionDefinition, context: ActionContext) 시그니처로 호출됩니다.
 */
export declare function addSelectedItemIfCompleteHandler(action: ActionDefinition, context: ActionContext): void;
/**
 * 선택된 상품의 수량 변경 및 가격 재계산
 *
 * G7Core에서 (action: ActionDefinition, context: ActionContext) 시그니처로 호출됩니다.
 */
export declare function updateSelectedItemQuantityHandler(action: ActionDefinition, context: ActionContext): void;
export {};
