/**
 * 수동 결제 처리 확인 창 핸들러 (Q13·Q14·Q26)
 *
 * 관리자가 결제 전 주문을 결제완료 이후로 올리거나 취소 주문·상품을 되살릴 때, 확인 창이
 * 「재고 차감 · 사용 마일리지 차감 · 결제금액 기록」 세 항목을 묻는다. 무엇이 해당하는지는 서버
 * 미리보기(`manual-paid-preview`)가 정한다 — 화면이 다시 판정하지 않는다.
 *
 * 상태는 `_global` 에 둔다 — 주문상세 모달은 `$parent._local` 과 모달 `_local` 이 섞여 격리 scope 로
 * 값이 비어 도달하는 사례가 있다(클레임 완료 모달 선례와 같은 이유).
 *
 * | 키 | 값 |
 * |---|---|
 * | `manualPaidPreview` | 미리보기 `data` 또는 null |
 * | `manualPaidPreviewLoading` | 요청 중 true |
 * | `manualPaidPreviewError` | 실패 시 서버 메시지 |
 * | `manualPaidOptions` | 세 체크 값 — 창을 열 때마다 모두 켠 채 다시 시작(기본값: 모두 체크) |
 */

import type { ActionContext } from '../types';

interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

const logger = ((window as any).G7Core?.createLogger?.('Ecom:ManualPaid')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:ManualPaid]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:ManualPaid]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:ManualPaid]', ...args),
};

/** 목록 일괄변경 미리보기 URL */
export const BULK_PREVIEW_URL = '/api/modules/sirsoft-ecommerce/admin/orders/bulk/manual-paid-preview';

/**
 * 상품 일괄변경 미리보기 URL
 *
 * @param orderNumber 주문번호 (라우트 바인딩 키)
 * @returns URL
 */
export const optionPreviewUrl = (orderNumber: string): string =>
    `/api/modules/sirsoft-ecommerce/admin/orders/${orderNumber}/options/bulk-status/manual-paid-preview`;

/** 판매 상태 — 이 상태로 가는 요청만 세 항목의 대상이다 */
export const SALES_STATUSES = ['payment_complete', 'shipping_hold', 'preparing', 'shipping_ready', 'shipping', 'delivered', 'confirmed'];

/** 세 항목 기본값 — 모두 켬 */
export const DEFAULT_MANUAL_PAID_OPTIONS = {
    apply_stock_deduction: true,
    apply_mileage_deduction: true,
    record_paid_amount: true,
};

/** 미리보기 세대 — 늦게 도착한 옛 응답을 버린다 */
let previewGeneration = 0;

type PreviewTarget =
    | { kind: 'orders'; body: { ids: number[]; order_status: string; carrier_id?: string; tracking_number?: string } }
    | { kind: 'options'; orderNumber: string; body: { items: Array<{ option_id: number; quantity: number }>; status: string; carrier_id?: string; tracking_number?: string } };

/**
 * 확인 창을 열기 직전 상태를 비웁니다. (목록·상세 공용)
 *
 * 세 체크를 모두 켠 채 다시 시작한다 — 앞 창에서 끈 체크가 다음 창으로 새면 운영자가 모르는 사이에
 * 재고·마일리지를 빼지 않는다. 진행 중이던 옛 미리보기 응답도 무시한다.
 *
 * @returns void
 */
export function resetManualPaidState(): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core) return;

    previewGeneration++;
    G7Core.state?.set?.({
        manualPaidPreview: null,
        manualPaidPreviewLoading: false,
        manualPaidPreviewError: null,
        manualPaidOptions: { ...DEFAULT_MANUAL_PAID_OPTIONS },
    }, { merge: 'shallow' });
}

/**
 * 미리보기를 요청합니다. (세대 카운터로 늦게 온 옛 응답을 버린다 — 클레임 완료 모달과 같은 규율)
 *
 * @param target 목록 일괄(orders) 또는 상품 일괄(options) 요청
 * @returns 반영 여부
 */
export async function refreshManualPaidPreview(target: PreviewTarget): Promise<boolean> {
    const G7Core = (window as any).G7Core;
    if (!G7Core?.state?.set || !G7Core?.api?.post) return false;

    const generation = ++previewGeneration;
    G7Core.state.set({ manualPaidPreviewLoading: true, manualPaidPreviewError: null });

    try {
        const url = target.kind === 'orders' ? BULK_PREVIEW_URL : optionPreviewUrl(target.orderNumber);
        const response = await G7Core.api.post(url, target.body);

        if (generation !== previewGeneration) return false;

        G7Core.state.set({ manualPaidPreview: response?.data ?? null }, { merge: 'shallow' });

        return true;
    } catch (error: any) {
        if (generation !== previewGeneration) return false;

        logger.error('[manualPaidPreview] 미리보기 실패:', error);
        const message = error?.response?.data?.message
            ?? error?.data?.message
            ?? G7Core.t?.('sirsoft-ecommerce.admin.order.manual_paid.preview_error')
            ?? '결제 처리 항목을 불러오지 못했습니다.';
        G7Core.state.set({ manualPaidPreview: null, manualPaidPreviewError: message }, { merge: 'shallow' });

        return false;
    } finally {
        if (generation === previewGeneration) {
            G7Core.state.set({ manualPaidPreviewLoading: false });
        }
    }
}

/**
 * 체크박스 토글 — handler `sirsoft-ecommerce.setManualPaidOption`, params `{optionKey, value}`
 *
 * 속성 이름을 `key` 로 두지 않는다 — 레이아웃 속성 `key` 는 렌더러가 노드 식별자로 먼저 가져간다.
 *
 * @param action 액션 (params.optionKey 세 항목 중 하나, params.value 불리언 또는 문자열)
 * @param _context 액션 컨텍스트
 * @returns void
 */
export function setManualPaidOptionHandler(
    action: ActionWithParams<{ optionKey?: string; value?: boolean | string }>,
    _context?: ActionContext,
): void {
    const G7Core = (window as any).G7Core;
    if (!G7Core) return;

    const key = action.params?.optionKey ?? (action as any).optionKey;
    if (!key || !(key in DEFAULT_MANUAL_PAID_OPTIONS)) return;

    const raw = action.params?.value ?? (action as any).value;
    const current = G7Core.state.get()?.manualPaidOptions ?? DEFAULT_MANUAL_PAID_OPTIONS;

    G7Core.state.set({
        manualPaidOptions: { ...DEFAULT_MANUAL_PAID_OPTIONS, ...current, [key]: raw === true || raw === 'true' },
    }, { merge: 'shallow' });
}

/**
 * 실행 요청 본문에 붙일 세 값 — 판매 상태로 가는 요청에만 싣는다.
 *
 * @param targetStatus 목표 상태
 * @returns 세 항목 값 (판매 상태가 아니면 빈 객체)
 */
export function manualPaidRequestFields(targetStatus: string): Record<string, boolean> {
    if (!SALES_STATUSES.includes(targetStatus)) return {};

    const G7Core = (window as any).G7Core;
    const options = G7Core?.state?.get?.()?.manualPaidOptions ?? DEFAULT_MANUAL_PAID_OPTIONS;

    return {
        apply_stock_deduction: options.apply_stock_deduction !== false,
        apply_mileage_deduction: options.apply_mileage_deduction !== false,
        record_paid_amount: options.record_paid_amount !== false,
    };
}
