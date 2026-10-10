/**
 * 영수증 창을 여는 핸들러.
 *
 * 주문 화면 조각이 마운트 때 영수증 조회 API 를 불러 담아 둔 응답을 그대로 넘긴다. 클릭과 같은 틱에서
 * 창을 열어야 팝업 차단에 걸리지 않으므로 여기서는 다시 조회하지 않는다. 열 수 있는 창이 없으면
 * (주소도 확인서 항목도 없음) 아무것도 하지 않는다.
 */
import { canOpenKginicisReceipt, openKginicisReceipt } from '../receiptPopup';

/**
 * 레이아웃이 넘긴 영수증 조회 응답으로 영수증 창을 연다.
 *
 * @param action `receipt` 파라미터(영수증 조회 응답의 data)를 실은 액션 객체
 * @returns 창을 열었으면 true
 */
export function openReceiptHandler(action: unknown): boolean {
    const receipt = (action as { params?: { receipt?: unknown } } | undefined)?.params?.receipt;

    if (!receipt || typeof receipt !== 'object' || !canOpenKginicisReceipt(receipt)) {
        return false;
    }

    openKginicisReceipt(receipt);

    return true;
}
