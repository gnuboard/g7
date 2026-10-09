/**
 * 주문 취소 관련 핸들러
 *
 * 관리자 주문상세에서 주문 취소 모달의 커스텀 핸들러들을 정의합니다.
 * - 환불 예상금액 계산 (debounce)
 * - 취소 수량 변경
 * - 주문 취소 실행
 */

import type { ActionContext } from '../types';

const logger = ((window as any).G7Core?.createLogger?.('Ecom:CancelOrder')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:CancelOrder]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:CancelOrder]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:CancelOrder]', ...args),
};

interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/**
 * 모듈 문구를 번역하고, 번역이 없으면 폴백 문구를 돌려줍니다.
 *
 * `G7Core.t` 는 번역이 없으면 키 문자열을 그대로 돌려주므로 `t(...) ?? 폴백` 은 폴백까지
 * 가지 않는다 — 키와 같으면 폴백으로 본다.
 *
 * @param key 문구 키
 * @param fallback 번역이 없을 때의 문구
 * @param params 치환 파라미터
 * @returns 표시할 문구
 */
function translateOr(key: string, fallback: string, params?: Record<string, unknown>): string {
    const resolved = (window as any).G7Core?.t?.(key, params);

    return typeof resolved === 'string' && resolved !== '' && resolved !== key ? resolved : fallback;
}

/**
 * 엔진이 커스텀 핸들러에 넘기는 액션 컨텍스트 중 이 파일이 쓰는 부분.
 *
 * `setState` 는 핸들러를 부른 컴포넌트(취소 모달 등)의 로컬 상태 writer 다.
 */
type HandlerContext = ActionContext & {
    setState?: (updates: Record<string, any>) => void;
};

/** 로컬 상태 쓰기 함수 */
type LocalWriter = (updates: Record<string, any>) => void;

/**
 * debounce 콜백에서 쓸 로컬 상태 writer 를 만듭니다.
 *
 * debounce 콜백은 액션 실행이 끝난 뒤에 돌므로, 그때의 `G7Core.state.setLocal()` 은 핸들러를 부른
 * 컴포넌트를 모른다 — 정본 `_local` 만 바뀌고 모달 화면은 그대로 남는다. 핸들러가 받은 컨텍스트의
 * `setState` 를 잡아 두었다가 같은 값을 함께 실어 그 컴포넌트에도 반영한다(엔진 내부 전역을 바꿔
 * 끼우지 않는다).
 *
 * @param G7Core G7Core 인스턴스
 * @param context 핸들러가 받은 액션 컨텍스트
 * @returns 로컬 상태 writer
 */
function deferredLocalWriter(G7Core: any, context?: HandlerContext): LocalWriter {
    const componentSetState = typeof context?.setState === 'function' ? context.setState : null;

    return (updates) => {
        G7Core.state.setLocal(updates);
        componentSetState?.(updates);
    };
}

/** Debounce 타이머 */
let estimateDebounceTimer: ReturnType<typeof setTimeout> | null = null;

/**
 * Debounce 타이머 정리
 *
 * 모달 닫기/페이지 이동 시 pending debounce를 취소하여
 * 잘못된 컨텍스트에서 setLocal() 호출되는 것을 방지합니다.
 */
export function clearCancelOrderTimers(): void {
    if (estimateDebounceTimer) {
        clearTimeout(estimateDebounceTimer);
        estimateDebounceTimer = null;
        logger.log('[clearCancelOrderTimers] debounce 타이머 정리 완료');
    }
}

/**
 * 취소 수량 변경 핸들러
 *
 * 개별 취소 항목의 수량을 변경하고, debounce로 환불 예상금액을 재계산합니다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.updateCancelQuantity",
 *   "params": { "optionId": 1, "maxQuantity": 5, "orderId": "ORD-001" }
 * }
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트 (debounce 콜백의 상태 반영 대상)
 */
export function updateCancelQuantityHandler(
    action: ActionWithParams<{ optionId: number; maxQuantity: number; orderId: string }>,
    context: HandlerContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { optionId, maxQuantity, orderId } = action.params || {};
    if (!optionId || !maxQuantity || !orderId) return;

    const rawValue = action.params?.value ?? (action as any).$event?.target?.value;
    const parsed = parseInt(String(rawValue), 10);
    const clamped = Math.max(1, Math.min(isNaN(parsed) ? maxQuantity : parsed, maxQuantity));

    // cancelItems 배열에서 해당 옵션의 수량 업데이트
    const local = G7Core.state.getLocal();
    const cancelItems = (local.cancelItems || []).map((item: any) => {
        if (item.id === optionId) {
            return { ...item, cancel_quantity: clamped };
        }
        return item;
    });

    G7Core.state.setLocal({ cancelItems });

    // Debounce로 환불 예상금액 재계산
    // 콜백 실행 시점에는 액션이 이미 끝나 모달 컴포넌트를 엔진이 모른다 — 핸들러가 받은
    // 컨텍스트로 writer 를 만들어 두어야 계산 결과가 모달에 반영된다.
    const writeLocal = deferredLocalWriter(G7Core, context);
    if (estimateDebounceTimer) {
        clearTimeout(estimateDebounceTimer);
    }
    estimateDebounceTimer = setTimeout(() => {
        void estimateRefundAmountInternal(G7Core, orderId, undefined, writeLocal);
    }, 500);
}

/**
 * 환불 예상금액 계산 핸들러
 *
 * estimate-refund API를 호출하여 환불 예상금액을 조회합니다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.estimateRefundAmount",
 *   "params": { "orderId": "ORD-001" }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function estimateRefundAmountHandler(
    action: ActionWithParams<{ orderId: string; cancelItems?: any[] }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { orderId, cancelItems } = action.params || {};
    if (!orderId) return;

    await estimateRefundAmountInternal(G7Core, orderId, cancelItems);
}

/**
 * 환불 예상금액 내부 계산 함수
 *
 * @param G7Core G7Core 인스턴스
 * @param orderId 주문번호
 * @param paramCancelItems 취소 항목 (없으면 로컬 상태의 cancelItems)
 * @param writeLocal 로컬 상태 writer (debounce 콜백은 핸들러 컨텍스트로 만든 writer 를 넘긴다)
 */
async function estimateRefundAmountInternal(
    G7Core: any,
    orderId: string,
    paramCancelItems?: any[],
    writeLocal: LocalWriter = (updates) => G7Core.state.setLocal(updates),
): Promise<void> {
    const local = G7Core.state.getLocal();
    const cancelItems = paramCancelItems || local.cancelItems || [];
    const refundPriority = local.refundPriority || 'pg_first';

    if (cancelItems.length === 0) {
        writeLocal({ refundEstimate: null });
        return;
    }

    const items = cancelItems.map((item: any) => ({
        order_option_id: item.id,
        cancel_quantity: item.cancel_quantity,
    }));

    writeLocal({ refundLoading: true });

    try {
        const response = await G7Core.api.post(
            `/api/modules/sirsoft-ecommerce/admin/orders/${orderId}/estimate-refund`,
            { items, refund_priority: refundPriority }
        );

        if (response?.success) {
            writeLocal({
                refundEstimate: response.data,
                refundLoading: false,
                cancelError: null,
            });
        } else {
            throw new Error(response?.message || 'Estimate failed');
        }
    } catch (error: any) {
        logger.error('[estimateRefundAmount] 실패:', error);
        writeLocal({
            refundLoading: false,
            refundEstimate: null,
        });
        G7Core.toast?.error?.(
            G7Core.t?.('sirsoft-ecommerce.admin.order.detail.modal.cancel.estimate_failed')
            ?? '환불 예상금액 계산에 실패했습니다.'
        );
    }
}

/**
 * 환불 우선순위 변경 핸들러
 *
 * 환불 우선순위를 변경하고, debounce로 환불 예상금액을 재계산합니다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.changeRefundPriority",
 *   "params": { "priority": "points_first", "orderId": "ORD-001" }
 * }
 *
 * @param action 액션 객체
 * @param context 액션 컨텍스트 (debounce 콜백의 상태 반영 대상)
 */
export function changeRefundPriorityHandler(
    action: ActionWithParams<{ priority: string; orderId: string }>,
    context: HandlerContext
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { priority, orderId } = action.params || {};
    if (!priority || !orderId) return;

    G7Core.state.setLocal({ refundPriority: priority });

    // Debounce로 환불 예상금액 재계산 (콜백의 상태 반영은 updateCancelQuantityHandler 와 같은 이유로 컨텍스트 writer 를 쓴다)
    const writeLocal = deferredLocalWriter(G7Core, context);
    if (estimateDebounceTimer) {
        clearTimeout(estimateDebounceTimer);
    }
    estimateDebounceTimer = setTimeout(() => {
        void estimateRefundAmountInternal(G7Core, orderId, undefined, writeLocal);
    }, 300);
}

/**
 * 주문 취소 실행 핸들러
 *
 * cancel API를 호출하여 주문을 취소합니다.
 * PG 실패 시 cancelError를 설정하고 모달을 유지합니다.
 *
 * @example
 * {
 *   "handler": "sirsoft-ecommerce.executeCancelOrder",
 *   "params": { "orderId": "ORD-001" }
 * }
 *
 * @param action 액션 객체
 * @param _context 액션 컨텍스트
 */
export async function executeCancelOrderHandler(
    action: ActionWithParams<{
        orderId: string;
        cancelItems?: any[];
        cancelReason?: string;
        cancelReasonDetail?: string;
        autoAdvanceRefund?: boolean;
        refundPriority?: string;
        /** 이 운영자가 클레임을 볼 수 있는가 (셸의 orderClaims 가 403 으로 unavailable 이면 false) */
        canViewClaims?: boolean;
    }>,
    _context: ActionContext
): Promise<void> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state) return;

    const { orderId } = action.params || {};
    if (!orderId) return;

    const local = G7Core.state.getLocal();
    const cancelItems = action.params?.cancelItems || local.cancelItems || [];
    const cancelReason = action.params?.cancelReason || local.cancelReason || '';
    const cancelReasonDetail = action.params?.cancelReasonDetail || local.cancelReasonDetail || '';
    // "환불까지 한 번에 처리" — 기본 **꺼짐**(D7). 관리자 [주문취소]는 환불신청서를 만들 뿐이고
    // 금액확인 → 승인 → 환불완료는 운영자가 하나씩 밟는 것이 기본 운영이다.
    const autoAdvanceRefund = action.params?.autoAdvanceRefund ?? local.autoAdvanceRefund ?? false;
    const refundPriority = action.params?.refundPriority || local.refundPriority || 'pg_first';

    // 선택한 항목을 항상 items(type:partial)로 전송한다. 프론트에서 전체취소를 휴리스틱으로 판단하지 않는다.
    // 진짜 전체취소(모든 활성 옵션 전량)도 items 전량으로 보내면 백엔드 shouldConvertToFullCancel 이 FULL 로
    // 승격해 동작·환불 결과가 동일하다. 종전 라인수+수량 휴리스틱은 단일 항목 주문을 항상 full 로 처리해
    // 수량을 줄여도 전량 취소되는 결함이 있었고(MP03 §9 발견#2), 유저 핸들러와도 판정 기준이 어긋났다.
    const body: Record<string, any> = {
        type: 'partial',
        // `cancel_pg` 는 없앴다(P2). 끈 채로 카드 취소를 하면 PG 를 부르지 않고도 환불이
        // 완료로 찍혀 **고객 카드는 그대로인데 장부만 환불완료**가 됐다.
        auto_advance_refund: autoAdvanceRefund,
        refund_priority: refundPriority,
        items: cancelItems.map((item: any) => ({
            order_option_id: item.id,
            cancel_quantity: item.cancel_quantity,
        })),
    };

    if (cancelReason) {
        body.reason = cancelReason;
    }
    if (cancelReasonDetail) {
        body.reason_detail = cancelReasonDetail;
    }

    // 환불 계좌 — 관리자가 취소 모달에서 입력/수정한 값.
    // 셋 중 하나라도 채워졌으면 채워진 그대로 보낸다. 부분 입력 거부와 "가상계좌 입금완료 건은 필수"
    // 판정은 서버(CancelOrderRequest)가 단독으로 수행한다 — 프론트에서 중복 판정하지 않는다.
    // 아무것도 입력하지 않았으면 키 자체를 보내지 않아, 주문 시 입력된 계좌가 지워지지 않는다.
    const refundBank = {
        bank_code: local.refundBankCode || '',
        account_number: local.refundBankAccount || '',
        holder: local.refundBankHolder || '',
    };
    if (refundBank.bank_code || refundBank.account_number || refundBank.holder) {
        body.refund_bank = refundBank;
    }

    G7Core.state.setLocal({ isCancelling: true, cancelError: null, cancelValidationErrors: null });

    try {
        const response = await G7Core.api.post(
            // [취소·환불 접수](§3-11) — 교환·반품 직권 접수와 같은 서비스·물리 게이트로 간다.
            `/api/modules/sirsoft-ecommerce/admin/orders/${orderId}/cancel-refund`,
            body
        );

        if (response?.success) {
            // 「환불까지 한 번에 처리」 는 성공 뒤에도 끈다 — 취소가 확정돼 화면에 남는 경로에서
            // 켜진 채 남으면 다음 취소가 의식하지 못한 자동 환불이 된다.
            G7Core.state.setLocal({ isCancelling: false, autoAdvanceRefund: false });

            // 안내 문구는 **실제로 일어난 일**을 말한다.
            //
            // 기본 운영(자동 전이 꺼짐)에서는 아무것도 취소되지 않는다 — 환불신청서가 접수될
            // 뿐이고, 취소는 운영자가 승인·환불완료를 밟은 뒤에 확정된다(D3). 그 갈림은 서버가
            // `data.claim.cancelled` 로 판정해 응답 `message` 를 둘로 가른다
            // (`messages.orders.cancelled` / `messages.orders.cancel_refund_requested`) — 화면은
            // 그 문구를 그대로 띄우고, 문구가 없는 응답에서만 같은 갈림(`cancelled`)으로 모듈 키에
            // 떨어진다. 화면이 제 문구를 따로 들면 같은 사실을 말하는 출처가 둘이 된다.
            //
            // 서버 문구가 말하지 않는 두 사정만 화면이 덧붙인다: 클레임을 볼 수 없는 운영자에게는
            // 신청서 번호를(이동하지 않으므로), 자동 전이가 멈춘 건(송금 수단은 접수 단계에서 멈춘다)에는 그 사실을.
            const claimNumber = response?.data?.claim?.claim_number ?? null;
            const cancelled = response?.data?.claim?.cancelled === true;
            const autoBranch = response?.data?.claim?.branch === 'auto';
            // 클레임 열람 권한은 셸이 판정해 넘긴다(orderClaims 가 403 이면 false). 권한이 없는
            // 운영자를 신청서 상세로 보내면 403 화면에 떨어진다 — 그때는 번호만 알리고 남는다.
            const canViewClaims = action.params?.canViewClaims !== false;

            const serverMessage = typeof response?.message === 'string' && response.message !== ''
                ? response.message
                : null;

            let message: string;
            if (!cancelled && !canViewClaims) {
                message = translateOr(
                    'sirsoft-ecommerce.admin.order.detail.modal.cancel.refund_request_created_no_claim_access',
                    `환불신청서 ${claimNumber ?? ''}가 접수되었습니다. 클레임 권한이 있는 운영자가 승인·환불완료를 처리해야 주문취소가 확정됩니다.`,
                    { number: claimNumber ?? '' },
                );
            } else if (!cancelled && autoBranch) {
                message = translateOr(
                    'sirsoft-ecommerce.admin.order.detail.modal.cancel.refund_request_auto_stopped',
                    '환불신청서를 접수했습니다. 자동으로 환불할 수 없는 결제수단이라 자동 처리는 멈췄습니다 — 신청서에서 승인한 뒤 [환불하기]로 환불완료를 처리해야 주문취소가 확정됩니다.',
                );
            } else if (serverMessage !== null) {
                message = serverMessage;
            } else if (cancelled) {
                message = translateOr('sirsoft-ecommerce.admin.order.detail.modal.cancel.cancel_success', '주문이 취소되었습니다.');
            } else {
                message = translateOr(
                    'sirsoft-ecommerce.admin.order.detail.modal.cancel.refund_request_created',
                    '환불신청서가 접수되었습니다. 승인·환불완료를 거쳐야 주문취소가 확정됩니다.',
                );
            }
            G7Core.toast?.success?.(message);

            G7Core.modal?.close?.('modal_cancel_order');
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order' } });
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'order_logs' } });
            // 클레임 내역 섹션과 탭 건수도 다시 읽는다 — 방금 만든 신청서가 빠진 옛 목록이 남는다.
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId: 'orderClaims' } });

            // 선택 초기화
            G7Core.state.setLocal({
                selectedProducts: [],
                selectAll: false,
                batchOrderStatus: '',
            });

            // 만든 환불신청서로 데려간다 (§5-3).
            //
            // 접수만 하고 모달만 닫으면 운영자는 방금 만든 신청서를 스스로 찾아가야 한다 —
            // 클레임 목록에서 주문번호로 뒤지는 길뿐이라, 그 자리에서 승인·환불완료로 이어지지
            // 않는다. 취소가 확정된 경우(자동 전이)와 클레임을 볼 수 없는 운영자는 주문 화면에 남는다.
            if (!cancelled && claimNumber && canViewClaims) {
                G7Core.dispatch?.({
                    handler: 'navigate',
                    params: { path: `/admin/ecommerce/claims/${claimNumber}` },
                });
            }
        } else {
            throw new Error(response?.message || 'Cancel failed');
        }
    } catch (error: any) {
        logger.error('[executeCancelOrder] 실패:', error);

        const errorData = (error as any)?.response?.data || (error as any)?.data;
        const httpStatus = (error as any)?.response?.status || (error as any)?.status;
        // 428(본인인증 필요)은 ApiClient(G7Core.api) 인터셉터가 중앙에서 처리한다 —
        // 본인인증 모달 → verify → 원 요청 자동 재실행 후 성공 응답이 이 try 로 돌아오므로
        // 여기서 별도 428 분기는 불필요(이중 모달 방지). 사용자가 인증 취소 시에만 에러로 전파됨.
        const isValidationError = httpStatus === 422;
        const pgError = errorData?.errors?.detail;
        const validationErrors = errorData?.errors ?? null;
        const detailMessage = pgError || errorData?.message || error?.message || '';
        const fallbackMessage = G7Core.t?.('sirsoft-ecommerce.admin.order.detail.modal.cancel.cancel_failed')
            ?? '주문 취소에 실패했습니다.';

        if (isValidationError) {
            // 422 Validation 에러: 필드별 에러만 표시 (PG 에러 영역 미사용)
            G7Core.state.setLocal({
                isCancelling: false,
                cancelError: null,
                cancelValidationErrors: validationErrors,
            });
            G7Core.toast?.error?.(detailMessage || fallbackMessage);
        } else {
            // PG 에러 등 기타 에러: PG 에러 영역에 표시
            G7Core.state.setLocal({
                isCancelling: false,
                cancelError: detailMessage || fallbackMessage,
                cancelValidationErrors: null,
            });
            G7Core.toast?.error?.(detailMessage || fallbackMessage);
        }
    }
}
