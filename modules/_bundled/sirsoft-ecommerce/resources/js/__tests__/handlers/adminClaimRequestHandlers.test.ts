/**
 * 관리자 직권 접수 핸들러 테스트 (#599 §3-11 · D5~D8)
 *
 * @description
 * 접수 창(`modal_admin_claim_request`)의 상태는 전부 `_global.adminClaimRequest` 에 있고, 화면에서는
 * 요청 URL·본문·debounce·늦은 응답 폐기가 보이지 않는다. 그 축을 고정한다:
 *  - 창을 열 때 고른 상품만 줄로 서고, 재발송지는 주문 배송지로 미리 채워진다
 *  - 미리보기는 입력이 멈춘 뒤 한 번만 나가고, 사유가 없으면 나가지 않는다
 *  - 운영자가 손대지 않은 배송비 칸은 서버 계산값을 따라가고, 손대면 덮어쓰기 값으로 실린다
 *  - 접수는 유형별 엔드포인트로 가고, 승인이 막힌 건은 경고로 사유까지 알린다
 *  - 실패는 핸들러가 한 번만 알리고 진행 플래그를 내린다 (레이아웃 onError 는 suppress)
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';

import {
    clearAdminClaimRequestTimersHandler,
    openAdminClaimRequestHandler,
    setAdminClaimRequestFieldHandler,
    setAdminClaimRequestRowHandler,
    submitAdminClaimRequestHandler,
} from '../../handlers/adminClaimRequestHandlers';
import { executeCancelOrderHandler } from '../../handlers/cancelOrderHandlers';

const BASE = '/api/modules/sirsoft-ecommerce/admin/orders';

let mockGlobalState: Record<string, any> = {};
let mockLocalState: Record<string, any> = {};

const mockG7Core = {
    state: {
        get: () => mockGlobalState,
        set: vi.fn((updates: Record<string, any>) => {
            mockGlobalState = { ...mockGlobalState, ...updates };
        }),
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
    },
    toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn(), info: vi.fn() },
    modal: { open: vi.fn(), close: vi.fn() },
    t: vi.fn((key: string) => key),
    api: {
        get: vi.fn(),
        post: vi.fn(),
    },
    dispatch: vi.fn(),
    dataSource: { get: vi.fn(() => null) },
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

const mockContext = {} as any;

/** 주문 상세 상품 표의 세 행 (두 행만 고른다) */
const ORDER_OPTIONS = [
    { id: 101, product_id: 7, product_option_id: 70, product_name: '코튼 티셔츠', product_option_name: '차콜 / M', quantity: 3, available_quantity: 2 },
    { id: 102, product_id: 8, product_option_id: 80, product_name: '니트', product_option_name: null, quantity: 1 },
    { id: 103, product_id: 9, product_option_id: 90, product_name: '고르지 않은 상품', quantity: 1 },
];

/** 주문 배송지 (주문 응답의 칸 이름) */
const SHIPPING_ADDRESS = {
    recipient_name: '김지은',
    recipient_phone: '010-1234-5678',
    zipcode: '06236',
    address: '서울 강남구 테헤란로 1',
    address_detail: '101호',
};

/**
 * 접수 창을 엽니다.
 *
 * @param type 접수 유형
 * @param extra 덧붙일 파라미터
 */
async function openRequest(type: 'exchange' | 'return', extra: Record<string, any> = {}): Promise<void> {
    await openAdminClaimRequestHandler(
        {
            handler: 'sirsoft-ecommerce.openAdminClaimRequest',
            params: {
                type,
                orderNumber: 'ORD-20260901-0001',
                selectedIds: [101, '102'],
                options: ORDER_OPTIONS,
                shippingAddress: SHIPPING_ADDRESS,
                ...extra,
            },
        },
        mockContext,
    );
}

/**
 * 접수 창의 한 칸을 씁니다.
 *
 * @param field 칸 이름
 * @param value 값
 */
function setField(field: string, value: unknown): void {
    setAdminClaimRequestFieldHandler({ handler: 'sirsoft-ecommerce.setAdminClaimRequestField', params: { field, value } }, mockContext);
}

/**
 * 미리보기 요청만 골라 돌려줍니다.
 *
 * @returns [url, body] 목록
 */
function previewCalls(): Array<[string, any]> {
    return mockG7Core.api.post.mock.calls.filter(([url]: any[]) => String(url).endsWith('/claims/preview')) as any;
}

/**
 * 다시 읽은 데이터소스 id 를 순서대로 돌려줍니다.
 *
 * @returns 데이터소스 id 목록
 */
function refetched(): string[] {
    return mockG7Core.dispatch.mock.calls
        .filter(([action]: any[]) => action?.handler === 'refetchDataSource')
        .map(([action]: any[]) => action.params.dataSourceId);
}

/** 미리보기 debounce 창을 넘기고 응답 반영까지 기다린다. */
async function flushPreview(): Promise<void> {
    await vi.advanceTimersByTimeAsync(300);
}

beforeEach(() => {
    vi.useFakeTimers();
    mockGlobalState = {};
    mockLocalState = {};
    vi.clearAllMocks();
    mockG7Core.t.mockImplementation((key: string) => key);
    mockG7Core.api.get.mockResolvedValue({ success: true, data: { options: [] } });
    mockG7Core.api.post.mockResolvedValue({ success: true, data: { shipping_fee: { fee: 3000 } } });
    (window as any).G7Core = mockG7Core;
});

afterEach(() => {
    vi.useRealTimers();
});

describe('접수 창 열기 (openAdminClaimRequest)', () => {
    /**
     * 고른 상품이 없으면 빈 창을 열지 않는다 — 열면 접수할 수 없는 창에서 사유만 고르게 된다.
     *
     * @effects admin_request_modal_requires_a_selection
     */
    it('고른 상품이 없으면 안내만 하고 창을 열지 않는다', async () => {
        await openRequest('return', { selectedIds: [] });

        expect(mockG7Core.toast.warning).toHaveBeenCalledTimes(1);
        expect(mockG7Core.modal.open).not.toHaveBeenCalled();
        expect(mockGlobalState.adminClaimRequest).toBeUndefined();
    });

    /**
     * 수량은 가용 수량(없으면 주문 수량)으로 시작하고, 재발송지는 주문 배송지 칸을 접수 본문 칸 이름으로
     * 옮겨 담는다. 「접수와 함께 승인」 은 기본 켜짐이다(D6).
     *
     * @scenario claim_type=return, gate_side=shipped
     * @effects admin_request_modal_seeds_rows_and_redelivery_from_the_order
     */
    it('고른 상품만 줄로 세우고 재발송지를 주문 배송지로 채운다', async () => {
        await openRequest('return');

        const state = mockGlobalState.adminClaimRequest;
        expect(state.type).toBe('return');
        expect(state.orderNumber).toBe('ORD-20260901-0001');
        expect(state.rows.map((row: any) => row.id)).toEqual([101, 102]);
        expect(state.rows[0]).toEqual(expect.objectContaining({ quantity: 2, max: 2, label: '코튼 티셔츠 / 차콜 / M', newProductOptionId: '' }));
        expect(state.rows[1]).toEqual(expect.objectContaining({ quantity: 1, max: 1, label: '니트' }));
        expect(state.redelivery).toEqual({
            recipient_name: '김지은',
            phone: '010-1234-5678',
            zipcode: '06236',
            address1: '서울 강남구 테헤란로 1',
            address2: '101호',
            city: '',
            state: '',
        });
        expect(state.redeliveryInternational).toBe(false);
        expect(state.approve).toBe(true);
        expect(state.submitting).toBe(false);
        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_admin_claim_request');
        // 반품은 교환 옵션을 부르지 않는다
        expect(mockG7Core.api.get).not.toHaveBeenCalled();
    });

    /**
     * 해외 주문은 국내 주소 칸이 빈 문자열로 온다 — 해외 칸(거리·도시·주·우편번호)으로 채워야 창이
     * 빈 주소로 열리지 않고, 접수 본문에도 도시·주가 실린다(2026-10-07 S4 실측 → S2-D8).
     *
     * @scenario claim_type=exchange, gate_side=shipped
     * @effects admin_request_modal_seeds_the_international_redelivery_address
     */
    it('해외 주문은 재발송지를 해외 주소 칸으로 채우고 도시·주까지 접수 본문에 싣는다', async () => {
        await openRequest('exchange', {
            shippingAddress: {
                recipient_name: 'Jane Doe',
                recipient_phone: '+1-212-555-0101',
                zipcode: '',
                address: '',
                address_detail: '',
                address_line_1: '350 5th Ave',
                address_line_2: 'Suite 2',
                intl_city: 'New York',
                intl_state: 'NY',
                intl_postal_code: '10118',
                recipient_country_code: 'US',
            },
        });

        expect(mockGlobalState.adminClaimRequest.redelivery).toEqual({
            recipient_name: 'Jane Doe',
            phone: '+1-212-555-0101',
            zipcode: '10118',
            address1: '350 5th Ave',
            address2: 'Suite 2',
            city: 'New York',
            state: 'NY',
        });
        expect(mockGlobalState.adminClaimRequest.redeliveryInternational).toBe(true);

        // 미리보기는 사유가 있어야 돌고, 우편번호가 바뀔 때 다시 불린다 — 그 본문에 고친 도시와 그대로인 주가 함께 실린다.
        setField('reasonCode', 'defective');
        await flushPreview();
        setField('redelivery.city', 'Boston');
        setField('redelivery.zipcode', '02108');
        await flushPreview();

        expect(previewCalls().at(-1)![1].redelivery_address).toEqual(expect.objectContaining({ zipcode: '02108', city: 'Boston', state: 'NY' }));
    });

    /**
     * 교환은 상품마다 한 번 옵션을 불러와 활성 옵션만 선택지로 둔다 — 가격 차이와 품절은 라벨에 붙는다.
     *
     * @scenario claim_type=exchange, gate_side=shipped
     * @effects admin_exchange_request_loads_active_option_choices
     */
    it('교환은 상품별 활성 옵션을 선택지로 불러온다', async () => {
        mockG7Core.api.get.mockImplementation(async (url: string) => (url.endsWith('/products/7')
            ? {
                success: true,
                data: {
                    options: [
                        { id: 70, option_name_localized: '차콜 / M' },
                        { id: 71, option_name: '차콜 / L', price_adjustment: 1000, price_adjustment_formatted: '+1,000원' },
                        { id: 72, option_name: '블랙 / M', is_sold_out: true },
                        { id: 73, option_name: '단종', is_active: false },
                    ],
                },
            }
            : { success: true, data: { options: [] } }));

        await openRequest('exchange');

        expect(mockG7Core.api.get.mock.calls.map(([url]: any[]) => url)).toEqual([
            '/api/modules/sirsoft-ecommerce/products/7',
            '/api/modules/sirsoft-ecommerce/products/8',
        ]);

        const [row] = mockGlobalState.adminClaimRequest.rows;
        expect(row.optionChoices).toEqual([
            { value: '70', label: '차콜 / M' },
            { value: '71', label: '차콜 / L (+1,000원)' },
            { value: '72', label: '블랙 / M — 품절' },
        ]);
    });

    /**
     * 옵션 조회가 실패해도 창은 남는다 — 선택지가 비면 같은 옵션 교환으로 접수할 수 있다.
     *
     * @effects admin_exchange_option_lookup_failure_keeps_the_modal
     */
    it('교환 옵션 조회가 실패해도 창을 유지한다', async () => {
        mockG7Core.api.get.mockRejectedValue(new Error('boom'));

        await expect(openRequest('exchange')).resolves.toBeUndefined();

        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_admin_claim_request');
        expect(mockGlobalState.adminClaimRequest.rows.every((row: any) => row.optionChoices.length === 0)).toBe(true);
        expect(mockG7Core.toast.error).not.toHaveBeenCalled();
    });
});

describe('청구 미리보기 (setAdminClaimRequestField · setAdminClaimRequestRow)', () => {
    /**
     * 입력이 이어지는 동안은 나가지 않고, 멈춘 뒤 한 번만 나간다. 본문은 접수와 같은 모양에 `type` 을 더한다.
     *
     * @scenario claim_type=return, gate_side=shipped
     * @effects admin_request_preview_is_debounced_after_input
     */
    it('사유를 고르면 입력이 멈춘 뒤 한 번만 미리보기를 부른다', async () => {
        await openRequest('return');

        setField('reasonCode', 'change_of_mind');
        setField('chargeMethod', 'pay_on_request');
        await vi.advanceTimersByTimeAsync(299);
        expect(previewCalls()).toHaveLength(0);

        await flushPreview();

        expect(previewCalls()).toHaveLength(1);
        const [url, body] = previewCalls()[0];
        expect(url).toBe(`${BASE}/ORD-20260901-0001/claims/preview`);
        expect(body).toEqual({
            type: 'return',
            items: [{ order_option_id: 101, quantity: 2 }, { order_option_id: 102, quantity: 1 }],
            reason_code: 'change_of_mind',
            reason_detail: null,
            charge_method: 'pay_on_request',
            // 반품은 환불 배분 순서가 예상액을 바꾸므로 미리보기에도 싣는다
            refund_priority: 'pg_first',
        });
    });

    /**
     * 사유가 없으면 청구를 계산할 근거가 없다 — 요청하지 않는다.
     *
     * @effects admin_request_preview_skips_without_reason
     */
    it('사유가 비어 있으면 미리보기를 부르지 않는다', async () => {
        await openRequest('return');

        setField('chargeMethod', 'pay_on_request');
        await flushPreview();

        expect(previewCalls()).toHaveLength(0);
        expect(mockGlobalState.adminClaimRequest.previewLoading).toBe(false);
    });

    /**
     * 손대지 않은 배송비 칸은 서버 계산값을 따라가고, 사유를 바꾸면 다시 서버 값으로 돌아간다.
     *
     * @effects admin_request_preview_fee_follows_the_server_until_edited
     */
    it('손대지 않은 배송비 칸은 서버 계산값을 따라간다', async () => {
        await openRequest('return');

        setField('reasonCode', 'change_of_mind');
        await flushPreview();

        const state = mockGlobalState.adminClaimRequest;
        expect(state.shippingFee).toBe('3000');
        expect(state.feeEdited).toBe(false);
        expect(state.preview).toEqual({ shipping_fee: { fee: 3000 } });
        expect(state.previewLoading).toBe(false);

        // 운영자가 고친 값은 다음 미리보기가 덮지 않는다
        mockG7Core.api.post.mockResolvedValue({ success: true, data: { shipping_fee: { fee: 6000 } } });
        setField('shippingFee', '2500');
        await flushPreview();
        expect(mockGlobalState.adminClaimRequest.shippingFee).toBe('2500');

        // 사유를 바꾸면 손댄 표식이 풀리고 서버 값으로 돌아간다
        setField('reasonCode', 'defective');
        await flushPreview();
        expect(mockGlobalState.adminClaimRequest.shippingFee).toBe('6000');
        expect(mockGlobalState.adminClaimRequest.feeEdited).toBe(false);
    });

    /**
     * 고친 배송비는 숫자로 실려 서버 계산을 덮는다(D7). 「무료」 는 배송비 0 이 서버 몫이라 싣지 않는다.
     *
     * @effects admin_request_edited_fee_is_sent_as_override, admin_request_free_charge_omits_the_fee_override
     */
    it('고친 배송비는 덮어쓰기 값으로 싣고 「무료」 면 싣지 않는다', async () => {
        await openRequest('return');

        setField('reasonCode', 'change_of_mind');
        setField('shippingFee', '2500');
        await flushPreview();
        expect(previewCalls().at(-1)![1].claim_shipping_fee).toBe(2500);

        setField('chargeMethod', 'free');
        await flushPreview();
        const [, body] = previewCalls().at(-1)!;
        expect(body.charge_method).toBe('free');
        expect(body).not.toHaveProperty('claim_shipping_fee');
    });

    /**
     * 재발송지 우편번호는 도서산간 판정을 바꾸므로 미리보기를 다시 부른다. 상세주소는 청구와 무관해
     * 부르지 않는다. 빈 칸은 본문에서 빠진다.
     *
     * @scenario claim_type=exchange, gate_side=shipped
     * @effects admin_request_redelivery_zipcode_reschedules_preview
     */
    it('재발송지 우편번호만 미리보기를 다시 부른다', async () => {
        await openRequest('exchange', { shippingAddress: { recipient_name: '김지은', zipcode: '06236' } });
        setField('reasonCode', 'defective');
        await flushPreview();
        const before = previewCalls().length;

        setField('redelivery.address2', '202호');
        await flushPreview();
        expect(previewCalls()).toHaveLength(before);
        expect(mockGlobalState.adminClaimRequest.redelivery.address2).toBe('202호');

        setField('redelivery.zipcode', '63000');
        await flushPreview();
        expect(previewCalls()).toHaveLength(before + 1);
        expect(previewCalls().at(-1)![1].redelivery_address).toEqual({
            recipient_name: '김지은',
            zipcode: '63000',
            address2: '202호',
        });
    });

    /**
     * 미리보기 실패는 토스트가 아니라 창 안 안내로 남는다 — 입력할 때마다 토스트가 쌓이면 안 된다.
     *
     * @effects admin_request_preview_failure_shows_inline_error
     */
    it('미리보기 실패는 창 안 오류로 남고 로딩이 풀린다', async () => {
        await openRequest('return');
        mockG7Core.api.post.mockRejectedValueOnce({ response: { data: { message: '신청할 수 없는 상태입니다.' } } });

        setField('reasonCode', 'change_of_mind');
        await flushPreview();

        const state = mockGlobalState.adminClaimRequest;
        expect(state.previewError).toBe('신청할 수 없는 상태입니다.');
        expect(state.previewLoading).toBe(false);
        expect(state.preview).toBeNull();
        expect(mockG7Core.toast.error).not.toHaveBeenCalled();
    });

    /**
     * 입력 직후(debounce 창 안)에 창을 닫으면 예약된 미리보기를 거둔다 — [취소] 가 창 닫기보다 먼저
     * 부르는 핸들러다(형제 취소 창 `clearCancelOrderTimers` 와 같은 규율, validate branch M8).
     *
     * @effects admin_request_close_cancels_pending_preview
     */
    it('창을 닫으면 예약된 미리보기가 나가지 않는다', async () => {
        await openRequest('return');
        setField('reasonCode', 'change_of_mind');

        clearAdminClaimRequestTimersHandler({ handler: 'sirsoft-ecommerce.clearAdminClaimRequestTimers' }, mockContext);
        await flushPreview();

        expect(previewCalls()).toHaveLength(0);
        expect(mockGlobalState.adminClaimRequest.previewLoading).toBe(false);
    });

    /**
     * 서버 사유가 없는 미리보기 실패(네트워크·5xx)도 창 안 문구로 남는다 — 비우면 청구액 칸이 조용히 사라진다.
     *
     * @effects admin_request_preview_failure_shows_inline_error
     */
    it('서버 사유가 없는 미리보기 실패는 폴백 문구로 남는다', async () => {
        await openRequest('return');
        mockG7Core.api.post.mockRejectedValueOnce(new Error('network'));

        setField('reasonCode', 'change_of_mind');
        await flushPreview();

        expect(mockGlobalState.adminClaimRequest.previewError).toBe('청구액을 계산하지 못했습니다.');
        expect(mockGlobalState.adminClaimRequest.previewLoading).toBe(false);
    });

    /**
     * 서버가 터진 5xx 의 본문 문구는 사유가 아니다 — 운영 환경(디버그 끔)의 500 은 언제나 `{"message":"Server Error"}`
     * 를 싣는다. 그것을 그대로 띄우면 운영자는 영문 원문을 본다(S1-D9 실측: 사유 고른 뒤 미리보기 500 → 창에 「Server Error」).
     *
     * @effects admin_request_server_error_shows_localized_message
     */
    it('5xx 응답의 본문 문구 대신 번역된 폴백 문구를 띄운다 (미리보기·접수)', async () => {
        await openRequest('return');
        mockG7Core.api.post.mockRejectedValueOnce({ response: { status: 500, data: { message: 'Server Error' } } });

        setField('reasonCode', 'change_of_mind');
        await flushPreview();

        expect(mockGlobalState.adminClaimRequest.previewError).toBe('청구액을 계산하지 못했습니다.');

        mockG7Core.api.post.mockRejectedValueOnce({ response: { status: 503, data: { message: 'Service Unavailable' } } });

        await expect(submitAdminClaimRequestHandler({ handler: 'sirsoft-ecommerce.submitAdminClaimRequest' }, mockContext)).rejects.toBeTruthy();

        expect(mockG7Core.toast.error).toHaveBeenCalledWith('접수하지 못했습니다.');
    });

    /**
     * 미리보기 응답은 숫자만 준다 — 창이 읽는 금액 표기는 구매자 신청 화면과 같은 포맷터로 주문 통화에 맞춰
     * 만든다. 없는 키(`adjustment.total_refund_amount_formatted`)를 읽으면 반품 환불 예정액 줄이 조용히
     * 사라진다(Chrome 실측 V-A1-m).
     *
     * @scenario claim_type=return, gate_side=shipped
     * @effects admin_request_preview_amounts_are_formatted_in_order_currency
     */
    it('미리보기 금액을 주문 통화 표기로 만든다 (반품 환불 예정액·교환 청구액)', async () => {
        await openRequest('return', { currency: 'KRW' });
        mockG7Core.api.post.mockResolvedValueOnce({
            success: true,
            data: { shipping_fee: { fee: 3000 }, total_charge_amount: 3000, adjustment: { refund_total: 55000 } },
        });

        setField('reasonCode', 'change_of_mind');
        await flushPreview();

        const formatted = mockGlobalState.adminClaimRequest.previewFormatted;
        expect(mockGlobalState.adminClaimRequest.currency).toBe('KRW');
        expect(formatted.refund_total).toMatch(/55,000/);
        expect(formatted.total_charge_amount).toMatch(/3,000/);
    });

    /**
     * 늦게 도착한 옛 응답이 새 입력의 결과를 덮으면 화면이 예고한 청구와 접수가 갈린다 — 버린다.
     *
     * @effects admin_request_stale_preview_response_is_discarded
     */
    it('늦게 도착한 옛 미리보기 응답은 버린다', async () => {
        await openRequest('return');

        let resolveFirst!: (value: any) => void;
        mockG7Core.api.post
            .mockImplementationOnce(() => new Promise((resolve) => { resolveFirst = resolve; }))
            .mockResolvedValueOnce({ success: true, data: { shipping_fee: { fee: 6000 }, marker: 'new' } });

        setField('reasonCode', 'change_of_mind');
        await flushPreview();
        setField('reasonCode', 'defective');
        await flushPreview();

        resolveFirst({ success: true, data: { shipping_fee: { fee: 3000 }, marker: 'old' } });
        await vi.advanceTimersByTimeAsync(0);

        expect(mockGlobalState.adminClaimRequest.preview.marker).toBe('new');
        expect(mockGlobalState.adminClaimRequest.shippingFee).toBe('6000');
    });

    /**
     * 수량은 1~가용 수량으로 자르고, 교환 옵션은 교환 본문에만 실린다.
     *
     * @scenario claim_type=exchange, gate_side=shipped
     * @effects admin_request_row_quantity_is_clamped
     */
    it('상품 줄의 수량을 클램프하고 교환 옵션을 싣는다', async () => {
        await openRequest('exchange');
        setField('reasonCode', 'defective');
        const setRow = (params: Record<string, any>) => setAdminClaimRequestRowHandler(
            { handler: 'sirsoft-ecommerce.setAdminClaimRequestRow', params },
            mockContext,
        );

        setRow({ optionId: 101, quantity: 9 });
        expect(mockGlobalState.adminClaimRequest.rows[0].quantity).toBe(2);
        setRow({ optionId: '101', quantity: 0 });
        expect(mockGlobalState.adminClaimRequest.rows[0].quantity).toBe(1);
        setRow({ optionId: 101, quantity: 'abc' });
        expect(mockGlobalState.adminClaimRequest.rows[0].quantity).toBe(1);
        setRow({ optionId: 101, quantity: '1.9', newProductOptionId: 71 });
        expect(mockGlobalState.adminClaimRequest.rows[0]).toEqual(expect.objectContaining({ quantity: 1, newProductOptionId: '71' }));

        // 모르는 행은 건드리지 않는다
        setRow({ optionId: 'x', quantity: 5 });
        expect(mockGlobalState.adminClaimRequest.rows[1].quantity).toBe(1);

        await flushPreview();
        expect(previewCalls()).toHaveLength(1);
        expect(previewCalls()[0][1].items).toEqual([
            { order_option_id: 101, quantity: 1, new_product_option_id: 71 },
            { order_option_id: 102, quantity: 1 },
        ]);
    });
});

describe('직권 접수 실행 (submitAdminClaimRequest)', () => {
    /**
     * 사유는 필수다 — 요청 없이 실패로 끝내 레이아웃이 창을 닫지 않게 한다.
     *
     * @effects admin_request_submit_requires_reason
     */
    it('사유가 없으면 요청하지 않고 실패로 끝난다', async () => {
        await openRequest('return');

        await expect(submitAdminClaimRequestHandler({ handler: 'sirsoft-ecommerce.submitAdminClaimRequest' }, mockContext)).rejects.toThrow();

        expect(mockG7Core.toast.warning).toHaveBeenCalledTimes(1);
        expect(mockG7Core.api.post).not.toHaveBeenCalled();
        expect(mockGlobalState.adminClaimRequest.submitting).toBe(false);
    });

    /**
     * @effects admin_request_submit_blocks_duplicate
     */
    it('진행 중이면 다시 누른 요청을 보내지 않는다', async () => {
        await openRequest('return');
        setField('reasonCode', 'change_of_mind');
        mockGlobalState.adminClaimRequest = { ...mockGlobalState.adminClaimRequest, submitting: true };

        await submitAdminClaimRequestHandler({ handler: 'sirsoft-ecommerce.submitAdminClaimRequest' }, mockContext);

        expect(mockG7Core.api.post.mock.calls.filter(([url]: any[]) => String(url).endsWith('/returns'))).toHaveLength(0);
    });

    /**
     * 반품은 `returns` 로 가고, 승인 여부를 싣는다. 성공하면 창을 닫고 새 신청서로 가며 주문 화면의
     * 세 소스를 다시 읽는다.
     *
     * @scenario claim_type=return, gate_side=shipped
     * @effects admin_request_submit_posts_to_type_endpoint, admin_request_submit_opens_new_claim_and_refetches_order
     */
    it('반품 접수는 반품 엔드포인트로 가고 새 신청서를 연다', async () => {
        await openRequest('return');
        setField('reasonCode', 'change_of_mind');
        setField('reasonDetail', '사이즈가 맞지 않음');
        mockG7Core.api.post.mockResolvedValueOnce({
            success: true,
            message: '고객을 대신해 접수했습니다.',
            data: { claim_number: 'RT20260901000001', payment_requested: false, approval_skipped: null },
        });

        await submitAdminClaimRequestHandler({ handler: 'sirsoft-ecommerce.submitAdminClaimRequest' }, mockContext);

        const [url, body] = mockG7Core.api.post.mock.calls[0];
        expect(url).toBe(`${BASE}/ORD-20260901-0001/returns`);
        expect(body).toEqual({
            items: [{ order_option_id: 101, quantity: 2 }, { order_option_id: 102, quantity: 1 }],
            reason_code: 'change_of_mind',
            reason_detail: '사이즈가 맞지 않음',
            charge_method: null,
            refund_priority: 'pg_first',
            approve: true,
        });
        expect(body).not.toHaveProperty('redelivery_address');
        // 주문에 적힌 환불 계좌가 없으면 계좌를 싣지 않는다
        expect(body).not.toHaveProperty('refund_bank');

        expect(mockG7Core.toast.success).toHaveBeenCalledWith('고객을 대신해 접수했습니다.');
        expect(mockG7Core.toast.warning).not.toHaveBeenCalled();
        expect(mockG7Core.modal.close).toHaveBeenCalledWith('modal_admin_claim_request');
        expect(mockGlobalState.adminClaimRequest).toBeNull();
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ selectedProducts: [] });
        expect(mockG7Core.dispatch).toHaveBeenCalledWith({ handler: 'openWindow', params: { path: '/admin/ecommerce/claims/RT20260901000001' } });
        expect(refetched()).toEqual(['order', 'orderClaims', 'order_logs']);
    });

    /**
     * 반품은 환불이 따라오므로 종전 취소 창의 두 칸을 받는다 — 환불 계좌는 주문 때 적은 값으로 미리 차고,
     * 우선순위를 바꾸면 환불 예상액이 달라지므로 미리보기를 다시 부른다. 교환에는 두 칸을 싣지 않는다.
     *
     * @scenario claim_type=return, gate_side=shipped
     * @effects admin_return_request_carries_refund_priority_and_prefilled_bank
     */
    it('반품 접수는 주문의 환불 계좌를 미리 채우고 환불 우선순위를 싣는다', async () => {
        await openRequest('return', {
            payment: { refund_bank_code: '004', refund_bank_account: '123-456', refund_bank_holder: '김지은' },
        });

        expect(mockGlobalState.adminClaimRequest.refundBank).toEqual({ bank_code: '004', account_number: '123-456', holder: '김지은' });
        expect(mockGlobalState.adminClaimRequest.refundPriority).toBe('pg_first');

        setField('reasonCode', 'change_of_mind');
        await flushPreview();
        setField('refundPriority', 'points_first');
        await flushPreview();

        // 사유 한 번 + 우선순위 한 번 — 우선순위도 미리보기 본문에 실린다
        expect(previewCalls()).toHaveLength(2);
        expect(previewCalls()[1][1].refund_priority).toBe('points_first');

        setField('refundBank.holder', '김지은(모)');
        mockG7Core.api.post.mockClear();
        mockG7Core.api.post.mockResolvedValueOnce({ success: true, data: { claim_number: 'RT20260901000002', approval_skipped: null } });

        await submitAdminClaimRequestHandler({ handler: 'sirsoft-ecommerce.submitAdminClaimRequest' }, mockContext);

        const [, body] = mockG7Core.api.post.mock.calls[0];
        expect(body.refund_priority).toBe('points_first');
        expect(body.refund_bank).toEqual({ bank_code: '004', account_number: '123-456', holder: '김지은(모)' });
    });

    /**
     * 교환은 환불이 따르지 않으므로 환불 우선순위·계좌를 싣지 않는다.
     *
     * @scenario claim_type=exchange, gate_side=shipped
     * @effects admin_return_request_carries_refund_priority_and_prefilled_bank
     */
    it('교환 접수에는 환불 우선순위·계좌를 싣지 않는다', async () => {
        await openRequest('exchange', { payment: { refund_bank_code: '004', refund_bank_account: '123-456', refund_bank_holder: '김지은' } });
        setField('reasonCode', 'defective');
        mockG7Core.api.post.mockResolvedValueOnce({ success: true, data: { claim_number: 'EX20260901000002', approval_skipped: null } });

        await submitAdminClaimRequestHandler({ handler: 'sirsoft-ecommerce.submitAdminClaimRequest' }, mockContext);

        const [, body] = mockG7Core.api.post.mock.calls[0];
        expect(body).not.toHaveProperty('refund_priority');
        expect(body).not.toHaveProperty('refund_bank');
    });

    /**
     * 교환은 `exchanges` 로 가고 교환 옵션·재발송지·고친 배송비·승인 끔이 함께 실린다.
     *
     * @scenario claim_type=exchange, gate_side=shipped
     * @effects admin_request_submit_posts_to_type_endpoint
     */
    it('교환 접수는 교환 엔드포인트로 옵션·재발송지·배송비를 싣는다', async () => {
        await openRequest('exchange');
        setField('reasonCode', 'defective');
        setField('chargeMethod', 'pay_on_approval');
        setField('shippingFee', '0');
        setField('approve', 'false');
        setAdminClaimRequestRowHandler({ handler: 'sirsoft-ecommerce.setAdminClaimRequestRow', params: { optionId: 101, newProductOptionId: '71' } }, mockContext);
        mockG7Core.api.post.mockResolvedValueOnce({ success: true, data: { claim_number: 'EX20260901000001', approval_skipped: null } });

        await submitAdminClaimRequestHandler({ handler: 'sirsoft-ecommerce.submitAdminClaimRequest' }, mockContext);

        const [url, body] = mockG7Core.api.post.mock.calls[0];
        expect(url).toBe(`${BASE}/ORD-20260901-0001/exchanges`);
        expect(body).toEqual({
            items: [
                { order_option_id: 101, quantity: 2, new_product_option_id: 71 },
                { order_option_id: 102, quantity: 1 },
            ],
            reason_code: 'defective',
            reason_detail: null,
            charge_method: 'pay_on_approval',
            claim_shipping_fee: 0,
            redelivery_address: {
                recipient_name: '김지은',
                phone: '010-1234-5678',
                zipcode: '06236',
                address1: '서울 강남구 테헤란로 1',
                address2: '101호',
            },
            approve: false,
        });
        // 서버 문구가 없으면 모듈 폴백 문구로 알린다
        expect(mockG7Core.toast.success).toHaveBeenCalledWith('고객을 대신해 접수했습니다.');
    });

    /**
     * 상태 변경 권한이 없는 운영자(화면이 `canApprove=false` 로 넘김)는 「접수와 함께 승인」 이 켜진 상태값이어도
     * 승인을 싣지 않는다 — 서버가 승인 요청을 403 으로 거절하므로, 접수까지는 되게 꺼진 값을 보낸다. (7.1.0 S3)
     * @scenario claim_type=return, gate_side=shipped
     * @effects admin_request_submit_omits_approval_without_permission
     */
    it('승인 권한이 없으면 approve=false 로 접수한다', async () => {
        await openRequest('return');
        setField('reasonCode', 'defective');
        setField('chargeMethod', 'free');
        mockG7Core.api.post.mockResolvedValueOnce({ success: true, data: { claim_number: 'RT20260901000001', approval_skipped: null } });

        await submitAdminClaimRequestHandler({ handler: 'sirsoft-ecommerce.submitAdminClaimRequest', params: { canApprove: false } } as any, mockContext);

        const [, body] = mockG7Core.api.post.mock.calls[0];
        expect(body.approve).toBe(false);
    });

    /**
     * 승인이 막히면 접수는 남는다(D6) — 성공 토스트가 아니라 경고로, 서버 문구와 사유를 함께 알린다.
     *
     * @scenario claim_type=return, gate_side=shipped
     * @effects admin_request_approval_skipped_warns_with_reason
     */
    it('승인이 막힌 접수는 경고로 사유까지 알린다', async () => {
        await openRequest('return');
        setField('reasonCode', 'change_of_mind');
        mockG7Core.api.post.mockResolvedValueOnce({
            success: true,
            message: '접수했지만 승인하지 못했습니다.',
            data: { claim_number: 'RT20260901000002', approval_skipped: { reason: '회수 대상 수량이 잠겨 있습니다.' } },
        });

        await submitAdminClaimRequestHandler({ handler: 'sirsoft-ecommerce.submitAdminClaimRequest' }, mockContext);

        expect(mockG7Core.toast.warning).toHaveBeenCalledWith('접수했지만 승인하지 못했습니다. 회수 대상 수량이 잠겨 있습니다.');
        expect(mockG7Core.toast.success).not.toHaveBeenCalled();
        // 접수는 남았으므로 창을 닫고 신청서로 간다
        expect(mockG7Core.modal.close).toHaveBeenCalledWith('modal_admin_claim_request');
        expect(mockG7Core.dispatch).toHaveBeenCalledWith({ handler: 'openWindow', params: { path: '/admin/ecommerce/claims/RT20260901000002' } });
    });

    /**
     * 실패는 핸들러가 서버 사유로 한 번만 알린다(레이아웃 onError 는 suppress). 진행 플래그를 내려
     * 다시 누를 수 있게 하고, 창은 닫지 않는다.
     *
     * @effects admin_request_submit_failure_reports_once_and_unlocks
     */
    it('접수가 실패하면 한 번만 알리고 진행 플래그를 내린다', async () => {
        await openRequest('return');
        setField('reasonCode', 'change_of_mind');
        mockG7Core.api.post.mockRejectedValueOnce({ response: { status: 422, data: { message: '신청할 수 없는 상품 상태입니다.' } } });

        await expect(submitAdminClaimRequestHandler({ handler: 'sirsoft-ecommerce.submitAdminClaimRequest' }, mockContext)).rejects.toBeTruthy();

        expect(mockG7Core.toast.error).toHaveBeenCalledTimes(1);
        expect(mockG7Core.toast.error).toHaveBeenCalledWith('신청할 수 없는 상품 상태입니다.');
        expect(mockGlobalState.adminClaimRequest.submitting).toBe(false);
        expect(mockG7Core.modal.close).not.toHaveBeenCalled();
        expect(refetched()).toEqual([]);
    });

    /**
     * 서버 메시지가 없는 실패(전송 실패)는 모듈 폴백 문구로 알린다.
     *
     * @effects admin_request_submit_failure_reports_once_and_unlocks
     */
    it('서버 사유가 없는 실패는 폴백 문구로 알린다', async () => {
        await openRequest('return');
        setField('reasonCode', 'change_of_mind');
        mockG7Core.api.post.mockRejectedValueOnce(new Error('Network Error'));

        await expect(submitAdminClaimRequestHandler({ handler: 'sirsoft-ecommerce.submitAdminClaimRequest' }, mockContext)).rejects.toThrow();

        expect(mockG7Core.toast.error).toHaveBeenCalledTimes(1);
        expect(mockG7Core.toast.error).toHaveBeenCalledWith('접수하지 못했습니다.');
        expect(mockGlobalState.adminClaimRequest.submitting).toBe(false);
    });

    /**
     * 2xx 인데 `success: false` 인 응답도 서버 사유를 그대로 띄운다 — 폴백 문구로 덮으면 운영자가
     * 왜 막혔는지 알 수 없다.
     *
     * @effects admin_request_submit_failure_reports_once_and_unlocks
     */
    it('success:false 응답은 서버 사유로 알린다', async () => {
        await openRequest('return');
        setField('reasonCode', 'change_of_mind');
        mockG7Core.api.post.mockResolvedValueOnce({ success: false, message: '이미 신청된 상품입니다.' });

        await expect(submitAdminClaimRequestHandler({ handler: 'sirsoft-ecommerce.submitAdminClaimRequest' }, mockContext)).rejects.toThrow();

        expect(mockG7Core.toast.error).toHaveBeenCalledTimes(1);
        expect(mockG7Core.toast.error).toHaveBeenCalledWith('이미 신청된 상품입니다.');
        expect(mockGlobalState.adminClaimRequest.submitting).toBe(false);
    });
});

describe('취소·환불 접수 (executeCancelOrder → cancel-refund)', () => {
    /**
     * 취소·환불 접수는 종전 취소 모달을 쓰되 교환·반품 직권 접수와 같은 서비스로 가는 `cancel-refund`
     * 경로로 나간다. 신청서만 생겼으면 그 신청서로 데려간다.
     *
     * @scenario claim_type=cancel_refund, gate_side=not_shipped
     * @effects admin_cancel_refund_posts_to_cancel_refund_endpoint
     */
    it('취소·환불 접수는 cancel-refund 엔드포인트로 가고 신청서로 이동한다', async () => {
        mockLocalState = { cancelReason: 'change_mind', cancelItems: [{ id: 101, cancel_quantity: 1 }] };
        mockG7Core.api.post.mockResolvedValueOnce({
            success: true,
            message: '환불신청서가 접수되었습니다.',
            data: { branch: 'manual', claim_number: 'RF20260901000001', cancelled: false, claim: { branch: 'manual', claim_number: 'RF20260901000001', cancelled: false } },
        });

        await executeCancelOrderHandler({ handler: 'sirsoft-ecommerce.executeCancelOrder', params: { orderId: 'ORD-20260901-0001' } } as any, mockContext);

        const [url, body] = mockG7Core.api.post.mock.calls[0];
        expect(url).toBe(`${BASE}/ORD-20260901-0001/cancel-refund`);
        expect(body).toEqual(expect.objectContaining({
            type: 'partial',
            reason: 'change_mind',
            items: [{ order_option_id: 101, cancel_quantity: 1 }],
        }));
        expect(mockG7Core.toast.success).toHaveBeenCalledWith('환불신청서가 접수되었습니다.');
        expect(refetched()).toEqual(['order', 'order_logs', 'orderClaims']);
        expect(mockG7Core.dispatch).toHaveBeenCalledWith({ handler: 'navigate', params: { path: '/admin/ecommerce/claims/RF20260901000001' } });
    });
});
