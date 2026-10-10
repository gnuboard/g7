/**
 * 주문 상세 관련 핸들러
 *
 * 주문 상세 화면에서 사용하는 커스텀 핸들러들을 정의합니다.
 * - 폼 초기화 (데이터 로드 후 수취인 정보 바인딩)
 * - 상품 선택 토글
 * - 일괄 상태 변경 API 호출
 * - 관리자 메모 저장
 */

import type { ActionContext } from '../types';
import { manualPaidRequestFields, refreshManualPaidPreview, resetManualPaidState, SALES_STATUSES } from './manualPaidEffectsHandlers';
import { formatAmountInCurrency } from './calculateCurrencyPrices';

// Logger 설정 (G7Core 초기화 전에도 동작하도록 폴백 포함)
const logger = ((window as any).G7Core?.createLogger?.('Ecom:OrderDetail')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:OrderDetail]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:OrderDetail]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:OrderDetail]', ...args),
};

/**
 * 실패 응답에서 운영자에게 보일 서버 사유를 꺼냅니다. (없으면 null)
 *
 * 5xx 본문(`Server Error`)과 본문 없는 응답·전송 실패의 axios 원문(`Request failed with status code 500`·
 * `Network Error`)은 사유가 아니다 — 띄우면 운영자가 영문 내부 문구를 읽는다. 부르는 쪽의 번역된 폴백으로
 * 넘긴다(목록 일괄처리 S1-D12 와 같은 기준). 그래서 `error.message` 는 읽지 않는다.
 *
 * @param error 오류 객체 (axios 오류 또는 `{ data: 응답 }` 을 실은 Error)
 * @param preferDetail true 면 진단 문구(`errors.detail`)를 문장보다 먼저 쓴다
 * @returns 서버 사유 또는 null
 */
function serverReasonOf(error: any, preferDetail = false): string | null {
    const status = Number(error?.response?.status ?? error?.status ?? 0);

    if (status >= 500) return null;

    const data = error?.response?.data ?? error?.data ?? {};
    const detail = preferDetail && typeof data?.errors?.detail === 'string' && data.errors.detail !== '' ? data.errors.detail : null;
    const message = typeof data?.message === 'string' && data.message !== '' ? data.message : null;

    return detail ?? message;
}

/**
 * 커스텀 핸들러에 전달되는 액션 객체 인터페이스
 */
interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/**
 * 일괄변경 송장번호 입력칸의 실제 DOM 값을 읽습니다.
 *
 * 송장번호 input 은 주문정보 카드의 dataKey="form" 폼 컨텍스트 안에 있어, 폼 자동바인딩이
 * 값을 _local.form.batchTrackingNumber 로 보낸다. 반면 일괄변경 핸들러는 액션 params(
 * 표현식 {{_local.batchTrackingNumber}}) 로 top-level 값을 받으므로 경로가 어긋나 빈 값이
 * 전달될 수 있다(Select 인 상태/택배사는 명시 setState 가 top-level 에 정상 기록되어 무관).
 * 저장소 분기에 의존하지 않도록, params 가 비면 화면에 실제 표시된 input 값을 직접 읽는다.
 *
 * @return 송장번호 문자열 (입력칸이 없으면 빈 문자열)
 */
function readBatchTrackingFromDom(): string {
    if (typeof document === 'undefined') {
        return '';
    }
    const input = document.querySelector<HTMLInputElement>('input[name="batchTrackingNumber"]');

    return input?.value?.trim() ?? '';
}

/**
 * 주문 상세 폼 초기화 핸들러
 *
 * order 데이터소스 로드 완료 후 수취인 정보를 _local.form에 바인딩합니다.
 *
 * @example
 * // data_sources.onLoaded에서 사용
 * {
 *   "handler": "sirsoft-ecommerce.initOrderDetailForm"
 * }
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function initOrderDetailFormHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;

    if (!G7Core?.state) {
        logger.warn('[initOrderDetailForm] G7Core.state를 사용할 수 없습니다.');
        return;
    }

    const local = G7Core.state.getLocal();
    // onLoaded 콜백 context에서 직접 데이터 우선 접근
    const contextData = (_context as any)?.data?.data;
    const orderData = contextData
        ?? G7Core.dataSource?.get?.('order')?.data;

    if (!orderData) {
        logger.warn('[initOrderDetailForm] order 데이터가 없습니다.');
        return;
    }

    // 수취인 정보를 폼에 바인딩
    G7Core.state.setLocal({
        form: {
            recipient_name: orderData.recipient_name || '',
            recipient_phone: orderData.recipient_phone || '',
            recipient_tel: orderData.recipient_tel || '',
            recipient_zipcode: orderData.recipient_zipcode || '',
            recipient_address: orderData.recipient_address || '',
            recipient_detail_address: orderData.recipient_detail_address || '',
            delivery_memo: orderData.delivery_memo || '',
            admin_memo: orderData.admin_memo || '',
        },
    });

    logger.log('[initOrderDetailForm] 폼 초기화 완료');
}

/**
 * 상품 선택 토글 핸들러
 *
 * 개별 상품의 체크박스를 토글합니다.
 *
 * @example
 * {
 *   "type": "change",
 *   "handler": "sirsoft-ecommerce.toggleProductSelection",
 *   "params": { "optionId": "{{option.id}}" }
 * }
 *
 * @param action 액션 객체 (params.optionId 필수)
 * @param _context 액션 컨텍스트
 */
export function toggleProductSelectionHandler(
    action: ActionWithParams<{ optionId: number }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    const { optionId } = action.params || {};

    if (!G7Core?.state || !optionId) {
        return;
    }

    const local = G7Core.state.getLocal();
    const selected: number[] = local.selectedProducts || [];

    let newSelected: number[];
    if (selected.includes(optionId)) {
        newSelected = selected.filter((id: number) => id !== optionId);
    } else {
        newSelected = [...selected, optionId];
    }

    // 전체 선택 상태 업데이트
    const orderData = G7Core.dataSource?.get?.('order')?.data;
    const totalOptions = (orderData?.options || []).length;
    const selectAll = newSelected.length === totalOptions && totalOptions > 0;

    G7Core.state.setLocal({
        selectedProducts: newSelected,
        selectAll,
    });
}

/**
 * 전체 선택/해제 토글 핸들러
 *
 * 모든 상품을 선택하거나 해제합니다.
 *
 * @example
 * {
 *   "type": "change",
 *   "handler": "sirsoft-ecommerce.toggleAllProducts"
 * }
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function toggleAllProductsHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;

    if (!G7Core?.state) {
        return;
    }

    const local = G7Core.state.getLocal();
    const currentSelectAll = local.selectAll;
    const orderData = G7Core.dataSource?.get?.('order')?.data;
    const options = orderData?.options || [];

    if (currentSelectAll) {
        // 전체 해제
        G7Core.state.setLocal({
            selectedProducts: [],
            selectAll: false,
        });
    } else {
        // 전체 선택
        const allIds = options.map((opt: { id: number }) => opt.id);
        G7Core.state.setLocal({
            selectedProducts: allIds,
            selectAll: true,
        });
    }
}

/**
 * 주문 상세 일괄변경 확인 데이터 빌드 핸들러
 *
 * 주문관리의 buildOrderBulkConfirmData와 동일한 패턴.
 * 선택 항목과 상태를 검증 후 확인 모달을 엽니다.
 *
 * @example
 * {
 *   "type": "click",
 *   "handler": "sirsoft-ecommerce.buildOrderDetailBulkConfirmData"
 * }
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function buildOrderDetailBulkConfirmDataHandler(
    action: ActionWithParams<{
        selectedProducts?: number[];
        batchOrderStatus?: string;
        batchCarrierId?: string;
        batchTrackingNumber?: string;
    }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core) return;

    const params = action.params || {};
    const selectedProducts: number[] = params.selectedProducts || [];
    const batchOrderStatus: string = params.batchOrderStatus || '';
    const batchCarrierId: string = params.batchCarrierId || '';
    // 송장번호 입력칸(basic Input)은 dataKey="form" 컨텍스트의 폼 자동바인딩 영향으로
    // 명시적 setState(target:"local") 가 top-level _local 에 도달하지 않고 _local.form 에만
    // 기록되는 경우가 있다(Select 는 정상, Input 만 비대칭). params 가 비면 DOM 의 실제
    // 입력값을 직접 읽어 보정한다. (택배사/상태 Select 는 params 로 정상 전달됨)
    const batchTrackingNumber: string = params.batchTrackingNumber || readBatchTrackingFromDom();

    // 검증: 상태 미선택 시 경고
    if (!batchOrderStatus) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.no_status_selected')
            || '변경할 주문상태를 선택해주세요.'
        );
        return;
    }

    // 검증: 선택된 상품 없음
    if (selectedProducts.length === 0) {
        G7Core.toast?.warning?.(
            G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.no_items_selected')
            || '변경할 상품을 선택해주세요.'
        );
        return;
    }

    // 취소 상태 선택 시 → 언제나 취소·환불 모달(클레임 접수)로 보낸다.
    //
    // 예전에는 `cancelPg` 체크박스로 "PG 없이 주문 상태만 변경" 경로가 갈라졌다. 그 경로는
    // 환불신청서도 취소신청서도 만들지 않고 상품만 `cancelled` 로 옮겨, 재고는 돌아오는데
    // 돈·결제행·주문 합계는 그대로인 취소를 원장에 근거 없이 남겼다(그렇게 취소된 주문은
    // 이후 입금확인·입금통보로 되살아난다). 취소의 입구는 하나다.
    if (batchOrderStatus === 'cancelled') {
        const orderData = G7Core.dataSource?.get?.('order')?.data;
        const options = orderData?.options || [];

        // 주문 표기 통화 = 주문 시점 기준 통화 (운영자가 이후 기본 통화를 바꿔도 불변)
        const orderCurrency = orderData?.currency_snapshot?.base_currency ?? null;

        // 취소 가능한 옵션만 필터 (이미 취소된 옵션 제외)
        const cancelItems = selectedProducts
            .map((optionId: number) => {
                const opt = options.find((o: { id: number }) => o.id === optionId);
                if (!opt || opt.option_status === 'cancelled') return null;
                // S1-D16: 수량 상한은 원 수량이 아니라 **진행 중인 클레임을 민 가용 수량**이다.
                // 서버는 선점을 알고 422 로 거절하므로, 원 수량을 쓰면 운영자가
                // 고를 수 없는 수를 고르고 제출한 뒤에야 한도를 알게 된다.
                // 필드가 없는 구버전 응답은 종전대로 원 수량으로 폴백한다.
                const available = opt.available_quantity ?? opt.quantity ?? 1;
                if (available <= 0) return null;
                const quantity = available;

                return {
                    id: opt.id,
                    product_name: opt.product_name,
                    product_option_name: opt.product_option_name,
                    thumbnail_url: opt.product_snapshot?.thumbnail_url || '',
                    unit_price: opt.unit_price,
                    // 통화 표기는 화면에서 조립하지 않는다 — 서버 포맷을 그대로 쓰고,
                    // 화면에서 새로 계산되는 소계만 설정 통화로 포맷한다.
                    unit_price_formatted: opt.unit_price_formatted
                        ?? formatAmountInCurrency(Number(opt.unit_price ?? 0), orderCurrency),
                    quantity,
                    subtotal_formatted: formatAmountInCurrency(
                        Number(opt.unit_price ?? 0) * quantity,
                        orderCurrency
                    ),
                    cancel_quantity: quantity,
                    option_status: opt.option_status,
                    option_status_label: opt.option_status_label || opt.option_status,
                };
            })
            .filter(Boolean);

        if (cancelItems.length === 0) {
            G7Core.toast?.warning?.(
                G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.no_cancellable_items')
                || '취소할 수 있는 상품이 없습니다. 이미 취소된 상품은 제외됩니다.'
            );
            return;
        }

        {
            // 취소·환불 모달 (환불예정금액 실시간 표시)
            // 모달 열기 전에 페이지 _local에 모든 취소 관련 상태 초기화
            // (사용자 취소 모달과 동일 패턴 — onMount setState 미사용)
            // 참고: modals 섹션의 isolated scope에서 onMount setState는
            // 모달 자체 scope에 복사하여 getLocal()/setLocal()과 단절됨
            G7Core.state.setLocal({
                cancelItems,
                cancelReason: '',
                cancelReasonDetail: '',
                // 「환불까지 한 번에 처리」 는 **열 때마다 꺼진 채** 시작한다(기본 꺼짐 — D7).
                // 초기화하지 않으면 앞 취소에서 켠 체크가 같은 화면의 다음 모달에 남아, 운영자가
                // 의식하지 못한 채 자동 승인·환불완료(PG 실환불)가 나간다.
                autoAdvanceRefund: false,
                refundPriority: 'pg_first',
                refundEstimate: null,
                refundLoading: false,
                isCancelling: false,
                cancelError: null,
                cancelValidationErrors: null,
            });
            G7Core.modal?.open?.('modal_cancel_order');

            // 모달 열린 후 초기 환불 예상금액 계산 (사용자 모달 initUserCancelItemsHandler와 동일)
            const orderId = orderData?.order_number || '';
            if (orderId) {
                G7Core.dispatch?.({
                    handler: 'sirsoft-ecommerce.estimateRefundAmount',
                    params: { orderId, cancelItems },
                });
            }
        }
        return;
    }

    // 검증: 배송 관련 상태 선택 시 택배사/송장번호 필수
    const shippingStatuses = ['shipping_ready', 'shipping', 'delivered'];
    if (shippingStatuses.includes(batchOrderStatus)) {
        if (!batchCarrierId || !batchTrackingNumber) {
            G7Core.toast?.warning?.(
                G7Core.t?.('sirsoft-ecommerce.admin.order.bulk.carrier_required')
                || '해당 상태로 변경하려면 택배사와 송장번호를 모두 입력해주세요.'
            );
            return;
        }
    }

    // 선택된 상품의 상세 정보 수집 (데이터소스는 G7Core.dataSource API로 접근)
    const orderData = G7Core.dataSource?.get?.('order')?.data;
    const options = orderData?.options || [];
    const bulkConfirmItems: Record<string, unknown>[] = [];
    const changeQuantities: Record<number, number> = {};

    for (const optionId of selectedProducts) {
        const opt = options.find((o: { id: number }) => o.id === optionId);
        if (!opt) continue;

        bulkConfirmItems.push({
            id: opt.id,
            product_name: opt.product_name,
            product_option_name: opt.product_option_name,
            sku: opt.sku,
            thumbnail_url: opt.product_snapshot?.thumbnail_url || '',
            unit_price: opt.unit_price,
            original_price: opt.product_snapshot?.original_price || opt.unit_price,
            quantity: opt.quantity,
            option_status: opt.option_status,
        });
        changeQuantities[opt.id] = opt.quantity;
    }

    // _local에 확인 데이터 저장 후 모달 열기
    G7Core.state.setLocal({
        bulkConfirmItems,
        changeQuantities,
        batchOrderStatus,
        batchCarrierId,
        batchTrackingNumber,
    });

    // 수동 결제 처리 항목(Q13) — 창을 열 때마다 세 체크를 모두 켠 채 다시 시작한다.
    // 수량을 바꿀 때 미리보기를 다시 부를 재료는 `_global` 에 둔다 — 수량 입력은 창의 격리 scope 에서
    // 돌아 페이지 `_local` 을 읽지 못한다.
    resetManualPaidState();
    G7Core.state.set?.({
        manualPaidContext: {
            orderNumber: orderData?.order_number ?? '',
            selectedProducts,
            status: batchOrderStatus,
            carrierId: batchCarrierId,
            trackingNumber: batchTrackingNumber,
        },
    }, { merge: 'shallow' });

    G7Core.modal?.open?.('modal_batch_change_confirm');

    // 창을 먼저 띄우고 그 안에서 미리보기를 불러온다. 판매 상태로 가는 변경만 대상이다.
    if (SALES_STATUSES.includes(batchOrderStatus) && orderData?.order_number) {
        await refreshDetailManualPaidPreview(
            String(orderData.order_number),
            selectedProducts,
            changeQuantities,
            batchOrderStatus,
            batchCarrierId,
            batchTrackingNumber,
        );
    }
}

/**
 * 주문상세 확인 창의 수동 결제 처리 미리보기를 다시 부릅니다.
 *
 * 수량을 줄이면 부모 주문의 파생 상태가 바뀌어(혼합 → 가장 낮은 상태) 결제 코스 진입 여부가 달라지므로
 * 수량을 바꿀 때마다 다시 부른다. 늦게 도착한 옛 응답은 세대 카운터가 버린다.
 *
 * @param orderNumber 주문번호
 * @param selectedProducts 선택 상품 행 ID
 * @param changeQuantities 행별 변경 수량
 * @param status 목표 상태
 * @param carrierId 택배사 ID
 * @param trackingNumber 송장번호
 * @returns 반영 여부
 */
async function refreshDetailManualPaidPreview(
    orderNumber: string,
    selectedProducts: number[],
    changeQuantities: Record<number, number>,
    status: string,
    carrierId?: string,
    trackingNumber?: string,
): Promise<boolean> {
    const G7Core = (window as any).G7Core;
    const options = G7Core?.dataSource?.get?.('order')?.data?.options || [];

    const items = selectedProducts.map((optionId: number) => {
        const option = options.find((opt: { id: number }) => opt.id === optionId);

        return { option_id: optionId, quantity: changeQuantities?.[optionId] ?? option?.quantity ?? 1 };
    });

    return refreshManualPaidPreview({
        kind: 'options',
        orderNumber,
        body: {
            items,
            status,
            ...(carrierId ? { carrier_id: carrierId } : {}),
            ...(trackingNumber ? { tracking_number: trackingNumber } : {}),
        },
    });
}

/**
 * 일괄 상태 변경 처리 핸들러
 *
 * 선택된 주문 옵션들의 상태를 일괄 변경합니다.
 * 수량 분할을 지원합니다.
 *
 * @example
 * {
 *   "type": "click",
 *   "handler": "sirsoft-ecommerce.processOrderDetailBulkChange",
 *   "params": { "orderId": "{{route.orderNumber}}" }
 * }
 *
 * @param action 액션 객체 (params.orderId 필수)
 * @param _context 액션 컨텍스트
 */
export async function processOrderDetailBulkChangeHandler(
    action: ActionWithParams<{
        orderId: string | number;
        selectedProducts?: number[];
        batchOrderStatus?: string;
        batchCarrierId?: string;
        batchTrackingNumber?: string;
        changeQuantities?: Record<number, number>;
    }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    const { orderId } = action.params || {};

    if (!G7Core?.state || !orderId) {
        logger.warn('[processOrderDetailBulkChange] orderId가 없습니다.');
        return;
    }

    // 확인 모달(modal_batch_change_confirm)은 격리 scope 라, 모달의 $parent._local 표현식으로
    // 넘긴 params 가 build 단계에서 setLocal 한 페이지 globalLocal 값과 어긋나 빈 값으로 도달할 수
    // 있다(carrier_id/tracking_number 누락 → DB 미저장). params 가 비면 build 가 저장한 페이지
    // _local(getLocal) 을 SSoT 로 fallback 한다. 송장은 추가로 DOM 값까지 보정.
    const pageLocal = G7Core.state.getLocal?.() ?? {};
    const selectedProducts = action.params?.selectedProducts ?? pageLocal.selectedProducts;
    const batchOrderStatus = action.params?.batchOrderStatus || pageLocal.batchOrderStatus;
    const batchCarrierId = action.params?.batchCarrierId || pageLocal.batchCarrierId;
    const batchTrackingNumber = action.params?.batchTrackingNumber
        || pageLocal.batchTrackingNumber
        || readBatchTrackingFromDom();
    const changeQuantities = action.params?.changeQuantities ?? pageLocal.changeQuantities;

    if (!selectedProducts || selectedProducts.length === 0) {
        logger.warn('[processOrderDetailBulkChange] 선택된 상품이 없습니다.');
        return;
    }

    if (!batchOrderStatus) {
        logger.warn('[processOrderDetailBulkChange] 변경할 상태가 없습니다.');
        return;
    }

    // 각 옵션의 수량 결정 (데이터소스는 G7Core.dataSource API로 접근)
    const orderData = G7Core.dataSource?.get?.('order')?.data;
    const options = orderData?.options || [];

    const items = selectedProducts.map((optionId: number) => {
        const option = options.find((opt: { id: number }) => opt.id === optionId);
        return {
            option_id: optionId,
            quantity: changeQuantities?.[optionId] ?? option?.quantity ?? 1,
        };
    });

    const body: Record<string, unknown> = {
        items,
        status: batchOrderStatus,
    };

    if (batchCarrierId) {
        body.carrier_id = batchCarrierId;
    }
    if (batchTrackingNumber) {
        body.tracking_number = batchTrackingNumber;
    }
    // 확인 창에서 고른 세 항목 — 판매 상태로 가는 요청에만 싣는다(서버는 미지정을 「켬」으로 본다).
    Object.assign(body, manualPaidRequestFields(batchOrderStatus));

    try {
        const response = await G7Core.api.patch(
            `/api/modules/sirsoft-ecommerce/admin/orders/${orderId}/options/bulk-status`,
            body
        );

        if (response?.success) {
            const t = G7Core.t;
            const changedCount = response.data?.changed_count ?? selectedProducts.length;
            G7Core.toast?.success?.(
                t?.('sirsoft-ecommerce.admin.order.detail.handler.bulk_change_success', { count: changedCount })
                ?? `${changedCount}개 옵션의 상태가 변경되었습니다.`
            );

            // 모달 닫기 + 데이터 새로고침 + 선택 초기화
            G7Core.modal?.close?.('modal_batch_change_confirm');
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order' } });
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order_logs' } });
            G7Core.state.setLocal({
                selectedProducts: [],
                selectAll: false,
                batchOrderStatus: '',
                batchCarrierId: '',
                batchTrackingNumber: '',
                changeQuantities: {},
            });
            G7Core.state.set?.({ manualPaidPreview: null });
        } else {
            throw Object.assign(new Error('Bulk status change failed'), { data: response });
        }
    } catch (error: any) {
        logger.error('[processOrderDetailBulkChange] API 호출 실패:', error);
        // 서버 422 검증 메시지(상태 전이 차단 사유 등)를 우선 노출한다. 사유가 아닌 원문은 번역된 폴백으로.
        G7Core.toast?.error?.(
            serverReasonOf(error)
            ?? G7Core.t?.('sirsoft-ecommerce.admin.order.detail.handler.bulk_change_failed')
            ?? '상태 변경에 실패했습니다.'
        );
        // 실패를 삼키면 확인 창의 성공 분기(`<close-modal/>`)가 돌아 창이 닫히고 운영자가 고른 항목(수량·결제 처리 세 항목)이
        // 사라진다. 다시 던져 레이아웃 `<fail>` 분기(창 유지)로 넘긴다 — 사유 토스트는 여기서 한 번만 띄운다.
        throw error;
    }
}

/**
 * 관리자 메모 저장 핸들러
 *
 * 관리자 메모를 API를 통해 저장합니다.
 *
 * @example
 * {
 *   "type": "click",
 *   "handler": "sirsoft-ecommerce.saveAdminMemo",
 *   "params": { "orderId": "{{route.orderNumber}}" }
 * }
 *
 * @param action 액션 객체 (params.orderId 필수)
 * @param _context 액션 컨텍스트
 */
export async function saveAdminMemoHandler(
    action: ActionWithParams<{ orderId: string | number }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    const { orderId } = action.params || {};

    if (!G7Core?.state || !orderId) {
        return;
    }

    const local = G7Core.state.getLocal();
    const adminMemo = local.form?.admin_memo ?? '';

    try {
        const response = await G7Core.api.patch(
            `/api/modules/sirsoft-ecommerce/admin/orders/${orderId}`,
            { admin_memo: adminMemo }
        );

        if (response?.success) {
            G7Core.toast?.success?.(
                G7Core.t?.('sirsoft-ecommerce.admin.order.detail.handler.memo_save_success')
                ?? '관리자 메모가 저장되었습니다.'
            );
        } else {
            throw Object.assign(new Error('Admin memo save failed'), { data: response });
        }
    } catch (error: any) {
        logger.error('[saveAdminMemo] 저장 실패:', error);
        G7Core.toast?.error?.(
            serverReasonOf(error)
            ?? G7Core.t?.('sirsoft-ecommerce.admin.order.detail.handler.memo_save_failed')
            ?? '관리자 메모 저장에 실패했습니다.'
        );
    }
}

/**
 * 일괄변경 모달 내 수량 변경 핸들러
 *
 * 개별 항목의 변경 수량을 1~최대수량 범위로 클램핑하여 _local에 저장합니다.
 *
 * @example
 * {
 *   "type": "change",
 *   "handler": "sirsoft-ecommerce.updateChangeQuantity",
 *   "params": { "optionId": "{{confirmItem.id}}", "maxQuantity": "{{confirmItem.quantity}}" }
 * }
 *
 * @param action 액션 객체 (params.optionId, params.maxQuantity 필수)
 * @param _context 액션 컨텍스트
 */
export function updateChangeQuantityHandler(
    action: ActionWithParams<{ optionId: number; maxQuantity: number; value?: number }>,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { optionId, maxQuantity } = action.params || {};
    if (!optionId || !maxQuantity) return;

    // $event.target.value 또는 params.value에서 입력값 추출
    const rawValue = action.params?.value ?? (action as any).$event?.target?.value;
    const parsed = parseInt(String(rawValue), 10);
    const clamped = Math.max(1, Math.min(isNaN(parsed) ? maxQuantity : parsed, maxQuantity));

    const local = G7Core.state.getLocal();
    const currentQuantities = { ...(local.changeQuantities || {}) };
    currentQuantities[optionId] = clamped;

    G7Core.state.setLocal({ changeQuantities: currentQuantities });

    // 수량이 바뀌면 부모 파생 상태가 바뀌어 수동 결제 처리 해당 여부가 달라진다(Q13) — 미리보기를 다시 부른다.
    const context = G7Core.state.get?.()?.manualPaidContext;
    if (context && SALES_STATUSES.includes(context.status) && context.orderNumber && Array.isArray(context.selectedProducts)) {
        void refreshDetailManualPaidPreview(
            String(context.orderNumber),
            context.selectedProducts,
            currentQuantities,
            context.status,
            context.carrierId,
            context.trackingNumber,
        );
    }
}

/**
 * 무통장 입금확인 모달 열기 핸들러
 *
 * 모달(modals 섹션)은 격리된 scope 라 모달 내부에서 setState($parent._local) 한 값이
 * 페이지 globalLocal(핸들러 getLocal()/setLocal() 대상)과 단절된다(엔진 모달 scope 제한).
 * 이 때문에 모달 onMount setState 로 입력 시드를 깔면 입력값/검증에러가 페이지 scope 와
 * 어긋나 입금액 미전송·인라인 에러 미표시가 발생한다.
 *
 * 주문취소 모달과 동일하게, 모달을 열기 전에 페이지 _local 에 입력 시드를 setLocal() 로
 * 미리 깔고 modal.open() 한다. 이렇게 하면 모달이 페이지 _local 을 그대로 상속하여
 * 모달 input(`_local.*`, setState target:"local")·인라인 에러·핸들러가 모두 같은
 * 페이지 scope 를 공유한다.
 *
 * @example
 * {
 *   "type": "click",
 *   "handler": "sirsoft-ecommerce.openConfirmDepositModal"
 * }
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function openConfirmDepositModalHandler(
    _action: ActionWithParams,
    _context: ActionContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) {
        logger.warn('[openConfirmDepositModal] G7Core.state 를 사용할 수 없습니다.');
        return;
    }

    const orderData = G7Core.dataSource?.get?.('order')?.data;
    const depositorName = orderData?.depositor_name ?? '';
    // 입금액 기본값은 **결제 통화 기준 실청구액**(total_due_charge_amount)이다.
    // 서버 검증(validatePaymentAmount 2단계)이 이 값과 대조하므로, base 금액
    // (total_due_amount)을 넣으면 base≠결제 통화인 주문은 항상 금액 불일치 422 가 된다.
    // 구버전 응답 호환을 위해 값이 없을 때만 base 금액으로 폴백한다.
    const depositAmount = orderData?.total_due_charge_amount ?? orderData?.total_due_amount ?? 0;

    // 모달 열기 전 페이지 _local 에 입력 시드 + 상태 초기화 (취소 모달과 동일 패턴)
    G7Core.state.setLocal({
        depositorName,
        depositAmount,
        depositConfirmErrors: null,
        isConfirmingDeposit: false,
        markOrderComplete: false,
    });
    G7Core.modal?.open?.('modal_confirm_deposit');
}

/**
 * 무통장 입금확인 핸들러
 *
 * 무통장(dbank) 미결제 주문의 입금자명·입금액을 확인하여 결제완료 처리한다.
 * 모달은 페이지 _local 을 상속하므로(openConfirmDepositModalHandler 가 모달 열기 전
 * setLocal() 로 시드) 입력값은 getLocal() 로 일관되게 읽을 수 있고, 모달이 표현식으로
 * 박아 넣은 action.params 도 함께 받는다(이중 안전). 선언적 apiCall + onError(sequence
 * 내부 $parent._local setState + toast) 패턴은 setState 와 toast 의 글로벌 상태 업데이트가
 * 경합하여 422 에러 토스트/인라인 에러가 소실되는 문제가 있어(입금확인 검수 결함), 주문취소
 * 모달(executeCancelOrder)과 동일하게 JS 핸들러로 422 를 직접 처리한다.
 *
 * 428(본인인증 필요)은 G7Core.api 가 IdentityGuardInterceptor 를 거치지 않으므로
 * 여기서 명시 분기하여 본인인증 모달 launcher 를 직접 호출한다(검수 결함 N3 의 입금확인 경로 대응).
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.confirmDeposit",
 *   "params": { "orderId": "{{route.orderNumber}}", "amount": "{{_local.depositAmount}}" }
 * }
 *
 * @param action 액션 객체 (params.orderId 필수)
 * @param _context 액션 컨텍스트
 */
export async function confirmDepositHandler(
    action: ActionWithParams<{
        orderId: string | number;
        amount?: string | number;
        depositorName?: string;
        markOrderComplete?: boolean | string;
    }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    const { orderId } = action.params || {};

    if (!G7Core?.state || !orderId) {
        logger.warn('[confirmDeposit] orderId 가 없습니다.');
        return;
    }

    // 입금액·입금자명은 모달 컨텍스트에서 해석된 action.params 로 직접 전달받는다.
    // 모달(modals 섹션)은 격리된 scope 라 모달 input 이 쓰는 $parent._local 값이
    // 페이지 globalLocal(getLocal() 반환 대상)에 도달하지 않는다(엔진 모달 scope 제한).
    // 따라서 getLocal() 로 읽으면 amount=undefined → 422("입금액 필수") 가 되므로,
    // 취소 모달(executeCancelOrder)과 동일하게 모달 표현식이 박아 넣은 params 를 SSoT 로 쓴다.
    // params 미전달 시에만 페이지 _local 로 폴백(하위호환).
    const local = G7Core.state.getLocal();
    const amount = action.params?.amount ?? local.depositAmount;
    const depositorName = action.params?.depositorName ?? local.depositorName ?? '';
    // 체크박스 표현식은 boolean 또는 문자열("true"/"false")로 도착할 수 있어 정규화한다.
    const rawMarkComplete = action.params?.markOrderComplete ?? local.markOrderComplete ?? false;
    const markOrderComplete = rawMarkComplete === true || rawMarkComplete === 'true';

    G7Core.state.setLocal({ isConfirmingDeposit: true, depositConfirmErrors: null });

    try {
        const response = await G7Core.api.patch(
            `/api/modules/sirsoft-ecommerce/admin/orders/${orderId}/confirm-deposit`,
            { amount, depositor_name: depositorName, mark_order_complete: markOrderComplete }
        );

        if (response?.success) {
            G7Core.state.setLocal({ isConfirmingDeposit: false, depositConfirmErrors: null });
            // 안내는 서버가 실제 결과(결제완료 처리 / 입금만 기록 / 결제 이후 단계라 상태 유지)에 맞춰 준
            // 문장을 그대로 띄운다 — 화면이 고정 문구를 들면 기록만 했거나 상태를 그대로 둔 사실이
            // 운영자에게 전해지지 않는다(S3-D4). 문장이 없는 응답에서만 모듈 문구로 떨어진다.
            const serverMessage = typeof response?.message === 'string' && response.message !== ''
                ? response.message
                : null;
            G7Core.toast?.success?.(
                serverMessage
                ?? G7Core.t?.('sirsoft-ecommerce.admin.order.detail.modal.confirm_deposit.confirm_success')
                ?? '입금이 확인되어 처리되었습니다.'
            );
            G7Core.modal?.close?.('modal_confirm_deposit');
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order' } });
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order_logs' } });
        } else {
            throw Object.assign(new Error('Deposit confirm failed'), { data: response });
        }
    } catch (error: any) {
        logger.error('[confirmDeposit] 실패:', error);

        // 428(본인인증 필요)은 ApiClient(G7Core.api) 인터셉터가 중앙에서 처리한다 —
        // 본인인증 모달 → verify → confirm-deposit 자동 재실행 후 성공 응답이 위 try 의 response 로
        // 돌아오므로(아래 catch 에 도달하지 않음), 여기서 별도 428 분기는 불필요(이중 모달 방지).
        // 422 는 금액 불일치 진단(errors.detail)이 문장보다 구체적이라 먼저 띄운다.
        const detailMessage = serverReasonOf(error, true) ?? '';
        const fallbackMessage = G7Core.t?.('sirsoft-ecommerce.admin.order.detail.modal.confirm_deposit.confirm_failed')
            ?? '입금 확인에 실패했습니다.';

        // 422 등 검증 에러: 인라인 에러(amount 필드) + 토스트
        G7Core.state.setLocal({
            isConfirmingDeposit: false,
            depositConfirmErrors: { amount: [detailMessage || fallbackMessage] },
        });
        G7Core.toast?.error?.(detailMessage || fallbackMessage);
    }
}
