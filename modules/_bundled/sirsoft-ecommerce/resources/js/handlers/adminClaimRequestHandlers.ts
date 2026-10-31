/**
 * 관리자 직권 접수 핸들러 — 주문 상세 선택 바의 [교환 접수]·[반품 접수] (#599 §3-11 · D5~D8)
 *
 * 운영자가 고객을 대신해 교환·반품을 접수한다. 접수 창(`modal_admin_claim_request`)의 상태는 전부
 * `_global.adminClaimRequest` 에 둔다 — 모달은 부모 화면의 `_local` 을 해석하지 못한다.
 *
 *  - 게이트(D5)는 서버가 준 상태 목록(`claims/request-context` 의 `gates`)으로 레이아웃이 판정한다.
 *    신청 기간·신청 가능 상태 설정은 따르지 않는다.
 *  - 청구(D7)는 사유로 자동 계산한 값을 미리보기(`claims/preview`)가 보여 주고, 운영자가 배송비를
 *    고치거나 「무료」 를 고른다. 미리보기는 입력이 멈춘 뒤 한 번만 나간다(debounce).
 *  - 「접수와 함께 승인」(D6)은 기본 켜짐이다. 승인이 막히면 접수는 남고 사유를 안내한다.
 *
 * 취소·환불 접수는 이 파일이 아니라 종전 취소 모달(`cancelOrderHandlers`)을 그대로 쓴다 — 같은 서비스로
 * 위임하는 `cancel-refund` 경로다.
 */

import type { ActionContext } from '../types';
import { formatClaimEstimate } from './claimApplyHandlers';

const logger = ((window as any).G7Core?.createLogger?.('Ecom:AdminClaimRequest')) ?? {
    log: (...args: unknown[]) => console.log('[Ecom:AdminClaimRequest]', ...args),
    warn: (...args: unknown[]) => console.warn('[Ecom:AdminClaimRequest]', ...args),
    error: (...args: unknown[]) => console.error('[Ecom:AdminClaimRequest]', ...args),
};

/** 커스텀 핸들러에 전달되는 액션 객체 */
interface ActionWithParams<T = Record<string, any>> {
    handler: string;
    params?: T;
    [key: string]: any;
}

/** 주문 상세 상품 행 (필요한 축만) */
interface OrderOptionRow {
    id: number;
    product_id?: number | null;
    product_option_id?: number | null;
    product_name?: string | null;
    product_option_name?: string | null;
    option_status?: string | null;
    quantity?: number | null;
    available_quantity?: number | null;
}

/** 접수 창의 상품 한 줄 */
interface RequestRow {
    id: number;
    productId: number | null;
    productOptionId: number | null;
    label: string;
    quantity: number;
    max: number;
    /** 교환 대상 옵션 (비우면 같은 옵션 교환) */
    newProductOptionId: string;
    /** 교환 옵션 선택지 (상품 조회 결과) */
    optionChoices: Array<{ value: string; label: string }>;
}

/** 접수 창 상태 (`_global.adminClaimRequest`) */
interface AdminClaimRequestState {
    type: 'exchange' | 'return';
    orderNumber: string;
    rows: RequestRow[];
    reasonCode: string;
    reasonDetail: string;
    chargeMethod: string;
    shippingFee: string;
    feeEdited: boolean;
    approve: boolean;
    redelivery: Record<string, string>;
    /** 해외 주문 여부 — 재발송지에 도시·주 칸을 세울지 정한다 (S2-D8) */
    redeliveryInternational: boolean;
    /** 환불 우선순위 (반품만 — `pg_first`·`points_first`) */
    refundPriority: string;
    /** 환불 계좌 (반품만 — 주문 입력값 프리필, 사람이 송금하는 결제수단에서만 쓰인다) */
    refundBank: { bank_code: string; account_number: string; holder: string };
    preview: Record<string, any> | null;
    /** 미리보기 금액의 주문 통화 표기 (구매자 신청 화면과 같은 포맷터 — `formatClaimEstimate`) */
    previewFormatted: Record<string, any> | null;
    /** 주문 기준 통화 (금액 표기용) */
    currency: string | null;
    previewError: string | null;
    previewLoading: boolean;
    submitting: boolean;
}

/** 미리보기 debounce (ms) — 완료 모달·취소 모달과 같은 규율 */
const PREVIEW_DEBOUNCE_MS = 300;

let previewTimer: ReturnType<typeof setTimeout> | null = null;
let previewGeneration = 0;

/**
 * G7Core 전역을 반환합니다. (미초기화면 null)
 *
 * @returns G7Core 전역 또는 null
 */
function core(): any | null {
    return (window as any).G7Core ?? null;
}

/**
 * 다국어 문자열을 해석합니다. (키가 없으면 폴백)
 *
 * @param key 다국어 키
 * @param fallback 폴백 문구
 * @param params 치환 파라미터
 * @returns 해석된 문구
 */
function t(key: string, fallback: string, params?: Record<string, unknown>): string {
    const translated = core()?.t?.(key, params);

    return translated && translated !== key ? translated : fallback;
}

/**
 * 응답 오류의 서버 메시지를 꺼냅니다. (없으면 null)
 *
 * 5xx 는 사유가 없는 것으로 본다 — 운영 환경의 500 본문은 언제나 `Server Error` 같은 프레임워크
 * 원문이라, 띄우면 운영자가 영문 내부 문구를 읽는다. 부르는 쪽의 번역된 폴백 문구로 넘긴다.
 *
 * @param error 오류 객체
 * @returns 서버 메시지 또는 null
 */
function serverMessageOf(error: any): string | null {
    const status = Number(error?.response?.status ?? error?.status ?? 0);

    if (status >= 500) return null;

    const message = error?.response?.data?.message ?? error?.data?.message ?? null;

    return typeof message === 'string' && message !== '' ? message : null;
}

/**
 * 접수 창 상태를 읽습니다.
 *
 * @returns 현재 상태 (없으면 null)
 */
function readState(): AdminClaimRequestState | null {
    return core()?.state?.get?.()?.adminClaimRequest ?? null;
}

/**
 * 접수 창 상태를 부분 갱신합니다.
 *
 * @param patch 바꿀 값
 */
function writeState(patch: Partial<AdminClaimRequestState>): void {
    const G7Core = core();
    const current = readState();

    if (!G7Core || !current) return;

    G7Core.state.set({ adminClaimRequest: { ...current, ...patch } });
}

/**
 * 접수 본문을 만듭니다. (미리보기·접수 공용 — 두 요청이 같은 입력을 보내야 예고와 결과가 같다)
 *
 * @param state 접수 창 상태
 * @returns 요청 본문
 */
function buildBody(state: AdminClaimRequestState): Record<string, unknown> {
    const body: Record<string, unknown> = {
        items: state.rows.map((row) => ({
            order_option_id: row.id,
            quantity: row.quantity,
            ...(state.type === 'exchange' && row.newProductOptionId !== ''
                ? { new_product_option_id: Number(row.newProductOptionId) }
                : {}),
        })),
        reason_code: state.reasonCode || null,
        reason_detail: state.reasonDetail || null,
        charge_method: state.chargeMethod || null,
    };

    // 운영자가 배송비를 고쳤을 때만 싣는다 — 싣지 않으면 서버가 사유로 계산한다(D7).
    if (state.feeEdited && state.shippingFee !== '' && state.chargeMethod !== 'free') {
        body.claim_shipping_fee = Number(state.shippingFee);
    }

    if (state.type === 'exchange') {
        const address = Object.fromEntries(Object.entries(state.redelivery).filter(([, value]) => String(value ?? '') !== ''));

        if (Object.keys(address).length > 0) {
            body.redelivery_address = address;
        }
    }

    // 반품은 환불이 따라온다 — 우선순위는 늘, 계좌는 은행을 골랐을 때만 싣는다(서버도 은행이 없으면 계좌를 버린다).
    if (state.type === 'return') {
        body.refund_priority = state.refundPriority || 'pg_first';

        if (state.refundBank.bank_code !== '') {
            body.refund_bank = { ...state.refundBank };
        }
    }

    return body;
}

/**
 * 청구 미리보기를 예약합니다. (입력이 멈춘 뒤 한 번 — 사유가 없으면 부르지 않는다)
 */
function schedulePreview(): void {
    if (previewTimer) clearTimeout(previewTimer);

    previewTimer = setTimeout(() => {
        previewTimer = null;
        void refreshPreview();
    }, PREVIEW_DEBOUNCE_MS);
}

/**
 * 청구 미리보기를 다시 받습니다. (늦게 도착한 옛 응답은 버린다)
 */
async function refreshPreview(): Promise<void> {
    const G7Core = core();
    const state = readState();

    if (!G7Core || !state || state.reasonCode === '') return;

    const generation = ++previewGeneration;
    writeState({ previewLoading: true, previewError: null });

    try {
        const response = await G7Core.api.post(
            `/api/modules/sirsoft-ecommerce/admin/orders/${state.orderNumber}/claims/preview`,
            { type: state.type, ...buildBody(state) },
        );

        if (generation !== previewGeneration) return;

        const preview = response?.data ?? null;
        const fee = preview?.shipping_fee?.fee ?? preview?.claim_shipping_fee?.fee;
        const latest = readState();

        writeState({
            preview,
            // 미리보기 응답은 숫자만 준다 — 창이 읽는 표기는 구매자 신청 화면과 같은 포맷터로 만든다.
            // 없는 키(`*_formatted`)를 읽으면 환불 예정액 줄이 조용히 사라진다(Chrome 실측 V-A1-m).
            previewFormatted: preview ? formatClaimEstimate(preview, latest?.currency ?? null) : null,
            previewLoading: false,
            // 운영자가 손대지 않은 배송비 칸은 서버 계산값으로 따라간다.
            ...(latest && !latest.feeEdited && fee !== undefined && fee !== null ? { shippingFee: String(fee) } : {}),
        });
    } catch (error: any) {
        if (generation !== previewGeneration) return;

        logger.warn('[refreshPreview] 미리보기 실패:', error);
        // 서버 사유가 없는 실패(네트워크·5xx)도 창 안에 남긴다 — 비워 두면 청구액 칸이 조용히 사라진다.
        writeState({
            preview: null,
            previewFormatted: null,
            previewLoading: false,
            previewError: serverMessageOf(error)
                ?? t('sirsoft-ecommerce.admin.order.claim_request.preview_error', '청구액을 계산하지 못했습니다.'),
        });
    }
}

/**
 * 접수 창을 닫기 전에 예약된 미리보기를 거두고 진행 중 응답을 무효화합니다.
 *
 * 입력 직후(debounce 창 안)에 창을 닫으면 닫힌 뒤에 `claims/preview` 가 나간다 — 형제 취소 창의
 * `clearCancelOrderTimers` 와 같은 규율로, 레이아웃의 [취소] 가 창 닫기보다 먼저 부른다.
 *
 * @param _action 액션 객체
 * @param _context 액션 컨텍스트
 */
export function clearAdminClaimRequestTimersHandler(_action?: ActionWithParams, _context?: ActionContext): void {
    if (previewTimer) {
        clearTimeout(previewTimer);
        previewTimer = null;
    }

    previewGeneration++;

    if (readState()) {
        writeState({ previewLoading: false });
    }
}

/**
 * 교환 옵션 선택지를 상품마다 불러옵니다. (공개 상품 조회 — 활성 옵션만)
 *
 * @param rows 접수 창 상품 줄
 */
async function loadExchangeChoices(rows: RequestRow[]): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const productIds = [...new Set(rows.map((row) => row.productId).filter((id): id is number => !!id))];

    for (const productId of productIds) {
        try {
            const response = await G7Core.api.get(`/api/modules/sirsoft-ecommerce/products/${productId}`);
            const options: any[] = response?.data?.options ?? [];
            const choices = options
                .filter((option) => option?.is_active !== false)
                .map((option) => ({
                    value: String(option.id),
                    label: `${option.option_name_localized ?? option.option_name ?? option.id}${option.price_adjustment ? ' (' + option.price_adjustment_formatted + ')' : ''}${option.is_sold_out ? ' — ' + t('sirsoft-ecommerce.admin.order.claim_request.sold_out', '품절') : ''}`,
                }));
            const current = readState();

            if (!current) return;

            writeState({
                rows: current.rows.map((row) => (row.productId === productId ? { ...row, optionChoices: choices } : row)),
            });
        } catch (error: any) {
            logger.warn('[loadExchangeChoices] 상품 옵션 조회 실패:', error);
        }
    }
}

/**
 * 직권 접수 창을 엽니다. (주문 상세 선택 바 [교환 접수]·[반품 접수])
 *
 * @param action 액션 객체 (params: type / orderNumber / selectedIds / options / shippingAddress / payment / currency)
 * @param _context 액션 컨텍스트
 */
export async function openAdminClaimRequestHandler(
    action: ActionWithParams<{
        type?: string;
        orderNumber?: string;
        selectedIds?: Array<number | string>;
        options?: OrderOptionRow[];
        shippingAddress?: Record<string, any> | null;
        payment?: Record<string, any> | null;
        currency?: string | null;
    }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    if (!G7Core) return;

    const params = action.params ?? {};
    const type = params.type === 'exchange' ? 'exchange' : 'return';
    const selectedIds = (params.selectedIds ?? []).map((id) => Number(id));
    const options = params.options ?? [];

    if (selectedIds.length === 0) {
        G7Core.toast?.warning?.(t('sirsoft-ecommerce.admin.order.bulk.no_items_selected', '변경할 상품을 선택해주세요.'));

        return;
    }

    const rows: RequestRow[] = options
        .filter((option) => selectedIds.includes(Number(option.id)))
        .map((option) => {
            const max = Math.max(1, Number(option.available_quantity ?? option.quantity ?? 1));

            return {
                id: Number(option.id),
                productId: option.product_id ? Number(option.product_id) : null,
                productOptionId: option.product_option_id ? Number(option.product_option_id) : null,
                label: [option.product_name ?? '', option.product_option_name ?? ''].filter(Boolean).join(' / '),
                quantity: max,
                max,
                newProductOptionId: '',
                optionChoices: [],
            };
        });

    const address = params.shippingAddress ?? {};
    const payment = params.payment ?? {};

    previewGeneration++;
    G7Core.state.set({
        adminClaimRequest: {
            type,
            orderNumber: params.orderNumber ?? '',
            rows,
            reasonCode: '',
            reasonDetail: '',
            chargeMethod: '',
            shippingFee: '',
            feeEdited: false,
            approve: true,
            // 해외 주문은 국내 칸이 빈 문자열이라 `||` 로 해외 칸에 넘어간다 — 서버 스냅샷과 같은 규칙.
            redelivery: {
                recipient_name: address.recipient_name ?? '',
                phone: address.recipient_phone ?? '',
                zipcode: address.zipcode || address.intl_postal_code || '',
                address1: address.address || address.address_line_1 || '',
                address2: address.address_detail || address.address_line_2 || '',
                city: address.intl_city || '',
                state: address.intl_state || '',
            },
            redeliveryInternational: String(address.recipient_country_code ?? 'KR').toUpperCase() !== 'KR',
            refundPriority: 'pg_first',
            // 주문 때 적은 환불 계좌를 미리 채운다 — 종전 취소 창과 같은 출처(`payment.refund_bank_*`).
            refundBank: {
                bank_code: payment.refund_bank_code ?? '',
                account_number: payment.refund_bank_account ?? '',
                holder: payment.refund_bank_holder ?? '',
            },
            preview: null,
            previewFormatted: null,
            currency: params.currency ?? null,
            previewError: null,
            previewLoading: false,
            submitting: false,
        } satisfies AdminClaimRequestState,
    });
    G7Core.modal?.open?.('modal_admin_claim_request');

    if (type === 'exchange') {
        await loadExchangeChoices(rows);
    }
}

/**
 * 접수 창의 한 칸을 씁니다. (사유·상세 사유·청구 방식·배송비·승인·재발송지·환불 우선순위·환불 계좌)
 *
 * 청구에 영향을 주는 칸(사유·청구 방식·배송비·재발송지 우편번호·환불 우선순위)이 바뀌면 미리보기를 다시 예약한다.
 *
 * @param action 액션 객체 (params: field / value)
 * @param _context 액션 컨텍스트
 */
export function setAdminClaimRequestFieldHandler(
    action: ActionWithParams<{ field?: string; value?: unknown }>,
    _context?: ActionContext,
): void {
    const state = readState();
    const field = action.params?.field ?? '';
    const value = action.params?.value;

    if (!state || field === '') return;

    if (field.startsWith('redelivery.')) {
        const key = field.slice('redelivery.'.length);
        writeState({ redelivery: { ...state.redelivery, [key]: String(value ?? '') } });

        if (key === 'zipcode') schedulePreview();

        return;
    }

    if (field.startsWith('refundBank.')) {
        const key = field.slice('refundBank.'.length);
        writeState({ refundBank: { ...state.refundBank, [key]: String(value ?? '') } });

        return;
    }

    switch (field) {
        case 'reasonCode':
            writeState({ reasonCode: String(value ?? ''), feeEdited: false });
            schedulePreview();
            break;
        case 'reasonDetail':
            writeState({ reasonDetail: String(value ?? '') });
            break;
        case 'chargeMethod':
            writeState({ chargeMethod: String(value ?? '') });
            schedulePreview();
            break;
        case 'shippingFee':
            writeState({ shippingFee: String(value ?? ''), feeEdited: true });
            schedulePreview();
            break;
        case 'approve':
            writeState({ approve: value === true || value === 'true' });
            break;
        case 'refundPriority':
            // 배분 순서가 바뀌면 환불 예상액도 바뀐다 — 미리보기를 다시 받는다.
            writeState({ refundPriority: value === 'points_first' ? 'points_first' : 'pg_first' });
            schedulePreview();
            break;
        default:
            break;
    }
}

/**
 * 접수 창의 상품 수량·교환 옵션을 씁니다. (수량은 1~가용 수량으로 클램프)
 *
 * @param action 액션 객체 (params: optionId / quantity? / newProductOptionId?)
 * @param _context 액션 컨텍스트
 */
export function setAdminClaimRequestRowHandler(
    action: ActionWithParams<{ optionId?: number | string; quantity?: number | string; newProductOptionId?: string | number }>,
    _context?: ActionContext,
): void {
    const state = readState();
    const optionId = Number(action.params?.optionId);

    if (!state || !Number.isFinite(optionId)) return;

    writeState({
        rows: state.rows.map((row) => {
            if (row.id !== optionId) return row;

            const next = { ...row };

            if (action.params?.quantity !== undefined) {
                const raw = Number(action.params.quantity);
                next.quantity = Number.isFinite(raw) ? Math.min(Math.max(Math.trunc(raw), 1), row.max) : 1;
            }

            if (action.params?.newProductOptionId !== undefined) {
                next.newProductOptionId = String(action.params.newProductOptionId ?? '');
            }

            return next;
        }),
    });
    schedulePreview();
}

/**
 * 직권 접수를 실행합니다. (`POST admin/orders/{order}/{exchanges|returns}`)
 *
 * 성공하면 새 신청서 상세로 간다 — 운영자는 접수 결과(승인 여부·청구)를 거기서 바로 확인한다.
 * 승인이 막혔으면(`approval_skipped`) 접수는 남았다는 사실과 사유를 함께 알린다.
 *
 * 「접수와 함께 승인」 은 상태 변경 권한(`claims.update-status`)의 일이다. 화면이 그 권한이 없다고 넘기면
 * (`canApprove=false`) 체크 상태와 무관하게 승인을 싣지 않는다 — 서버는 승인 요청을 403 으로 거절한다.
 *
 * @param action 액션 객체 (params: canApprove — 승인 권한 여부, 생략 시 true)
 * @param _context 액션 컨텍스트
 */
export async function submitAdminClaimRequestHandler(
    action: ActionWithParams<{ canApprove?: boolean | string }>,
    _context?: ActionContext,
): Promise<void> {
    const G7Core = core();
    const state = readState();

    if (!G7Core || !state || state.submitting) return;

    if (state.reasonCode === '') {
        G7Core.toast?.warning?.(t('sirsoft-ecommerce.admin.order.claim_request.reason_required', '사유를 선택해주세요.'));

        throw new Error('admin claim request reason missing');
    }

    writeState({ submitting: true });

    try {
        const segment = state.type === 'exchange' ? 'exchanges' : 'returns';
        const response = await G7Core.api.post(
            `/api/modules/sirsoft-ecommerce/admin/orders/${state.orderNumber}/${segment}`,
            { ...buildBody(state), approve: state.approve && action?.params?.canApprove !== false && action?.params?.canApprove !== 'false' },
        );

        if (response?.success === false) {
            // 응답 본문을 실어 던진다 — 아래 catch 의 `serverMessageOf()` 가 서버 사유를 그대로 띄운다.
            throw Object.assign(new Error(response?.message ?? 'admin claim request failed'), { data: response });
        }

        const data = response?.data ?? {};

        if (data.approval_skipped) {
            G7Core.toast?.warning?.(
                `${response?.message ?? ''} ${data.approval_skipped.reason ?? ''}`.trim(),
            );
        } else {
            G7Core.toast?.success?.(
                response?.message ?? t('sirsoft-ecommerce.admin.order.claim_request.success', '고객을 대신해 접수했습니다.'),
            );
        }

        G7Core.modal?.close?.('modal_admin_claim_request');
        G7Core.state.set({ adminClaimRequest: null });
        G7Core.state.setLocal?.({ selectedProducts: [] });

        if (data.claim_number) {
            G7Core.dispatch?.({ handler: 'openWindow', params: { path: `/admin/ecommerce/claims/${data.claim_number}` } });
        }

        ['order', 'orderClaims', 'order_logs'].forEach((dataSourceId) => {
            G7Core.dispatch?.({ handler: 'refetchDataSource', params: { dataSourceId } });
        });
    } catch (error: any) {
        logger.error('[submitAdminClaimRequest] 직권 접수 실패:', error);
        G7Core.toast?.error?.(
            serverMessageOf(error) ?? t('sirsoft-ecommerce.admin.order.claim_request.error', '접수하지 못했습니다.'),
        );
        writeState({ submitting: false });
        throw error;
    }
}
