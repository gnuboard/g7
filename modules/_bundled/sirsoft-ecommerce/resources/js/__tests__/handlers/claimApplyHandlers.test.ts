/**
 * claimApplyHandlers 테스트 (#599 S11)
 *
 * @description
 * 이 화면에서 조용히 깨지는 축만 고정한다:
 *  - 교환 방식은 **상품마다** 정하는 속성이다(D29). 2행을 지정했는데 1행이 바뀌면 오류 없이
 *    잘못된 신청서가 저장된다.
 *  - 미리보기는 낙관적 선택 ↔ 서버 재계산의 왕복이다. 늦게 도착한 옛 응답이 새 선택 위에
 *    덮이면 화면이 예고한 금액과 실제 청구가 갈린다.
 *  - 미리보기가 거절되면 금액을 남기지 않는다 — 옛 요약을 남긴 채 제출되면 같은 결함이다.
 *  - 수량은 잔여 수량으로 클램프한다.
 *  - 재발송지는 주문 배송지와 필드 이름이 다르다 — 이름이 어긋나면 서버가 빈 값으로 받는다.
 *  - 제출 페이로드는 선택된 행만, 동일상품 교환에는 new_product_option_id 를 싣지 않는다.
 *
 * @scenario exchange_mode=change, preview_result=ok
 * @effects claim_apply_change_product_updates_only_target_row,
 *   claim_apply_estimate_applies_latest_generation_only,
 *   claim_apply_quantity_clamped_to_available,
 *   claim_apply_redelivery_maps_order_address_fields,
 *   claim_apply_items_payload_omits_option_for_same_exchange,
 *   claim_apply_submit_dispatches_server_named_pg_handler,
 *   claim_apply_shipping_fee_basis_shown_for_policy_mode,
 *   claim_apply_shipping_fee_basis_hidden_for_fixed_mode,
 *   claim_apply_change_product_modal_clears_previous_row_selection,
 *   claim_apply_secondary_currency_absorbs_all_server_shapes,
 *   claim_apply_item_validation_error_maps_to_its_row
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
    applyClaimChangeProductHandler,
    attachClaimItemErrors,
    collectClaimFieldErrors,
    unplacedClaimErrorMessage,
    buildClaimItemsPayload,
    claimStoreSuffix,
    clearClaimApplyTimers,
    estimateClaimHandler,
    openClaimChangeProductHandler,
    formatClaimEstimate,
    initClaimApplyHandler,
    isUserFixableClaimError,
    orderShippingAddressAsRedelivery,
    resolveClaimApplyRoute,
    setClaimChargeMethodHandler,
    setClaimPaymentMethodHandler,
    setClaimReasonHandler,
    submitClaimHandler,
    toSecondaryCurrencyList,
    updateClaimQuantityHandler,
} from '../../handlers/claimApplyHandlers';

let mockLocalState: Record<string, any> = {};
let mockDataSources: Record<string, any> = {};

/**
 * 엔진의 `setLocal` 은 평범한 객체 값을 **깊은 병합**한다 — `setLocal({ x: {} })` 은 무동작이고
 * `setLocal({ x: { a: 1 } })` 은 `x` 의 나머지 키를 남긴다(2026-08-31 브라우저 실측). 얕은
 * 병합으로 흉내 내면 "빈 객체로 지웠다" 고 믿는 코드가 테스트에서만 지워지고 화면에서는
 * 앞 값이 그대로 남는다 — 이 하네스가 그 결함을 통과시켰던 지점이다.
 */
function mergeLocal(base: Record<string, any>, updates: Record<string, any>): Record<string, any> {
    const next = { ...base };

    for (const [key, value] of Object.entries(updates)) {
        const prev = next[key];
        const bothPlainObjects =
            prev !== null && typeof prev === 'object' && !Array.isArray(prev)
            && value !== null && typeof value === 'object' && !Array.isArray(value);

        next[key] = bothPlainObjects ? mergeLocal(prev, value) : value;
    }

    return next;
}

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = mergeLocal(mockLocalState, updates);
        }),
        get: vi.fn(() => ({})),
    },
    dataSource: {
        get: vi.fn((id: string) => mockDataSources[id] ?? null),
    },
    api: {
        post: vi.fn(),
        get: vi.fn(),
        delete: vi.fn(),
    },
    toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
    modal: { open: vi.fn(), close: vi.fn() },
    dispatch: vi.fn(),
    t: vi.fn((key: string) => key),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

const API_BASE = '/api/modules/sirsoft-ecommerce/user/orders/120';

/** claimable-options 응답 (교환이 열린 상품 2건) */
const claimableResponse = {
    data: {
        order_id: 120,
        order_number: '20260823-0012',
        resolved_type: null,
        options: [
            {
                option: {
                    id: 310,
                    product_id: 5,
                    product_name: '반팔 티셔츠',
                    product_option_name: '화이트 / M',
                    unit_price: 29000,
                    unit_price_formatted: '29,000원',
                    thumbnail_url: '/img/a.jpg',
                },
                available_quantity: 2,
                locked_quantity: 0,
                claimable_types: ['exchange', 'return'],
                refund_branch: null,
            },
            {
                option: {
                    id: 311,
                    product_id: 6,
                    product_name: '니트',
                    product_option_name: '아이보리 / L',
                    unit_price: 49000,
                    unit_price_formatted: '49,000원',
                    thumbnail_url: '/img/b.jpg',
                    mc_unit_price: {
                        KRW: { amount: 49000, formatted: '49,000원' },
                        USD: { amount: 41.65, formatted: '$41.65' },
                    },
                },
                available_quantity: 1,
                locked_quantity: 0,
                claimable_types: ['exchange'],
                refund_branch: null,
            },
        ],
        reasons: {
            exchange: [{ code: 'size_mismatch', localized_name: '사이즈가 맞지 않음', fault_type: 'customer' }],
        },
        settings: {
            allowed_charge_methods: ['pay_on_request', 'deduct_from_refund'],
            charge_payment_methods: ['card', 'point'],
            max_images: 5,
            image_max_size_kb: 5120,
        },
        pending_images: [],
    },
};

/**
 * 화면 진입 상태(상품 2건 · 사유 선택 완료)를 만듭니다.
 */
function seedApplyState(): void {
    mockDataSources = {
        claimable: claimableResponse,
        order: { data: { id: 120, order_number: '20260823-0012', base_currency: 'KRW' } },
    };

    initClaimApplyHandler(
        { handler: 'initClaimApply', params: { claimType: 'exchange', preselected: '310,311' } },
        {} as any
    );

    mockLocalState.claimReason = 'size_mismatch';
}

describe('claimApplyHandlers', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.useRealTimers();
        mockLocalState = {};
        mockDataSources = {};
        (window as any).G7Core = mockG7Core;
        window.history.replaceState({}, '', '/');
        clearClaimApplyTimers();
    });

    it('초기화 — 그 유형이 열린 상품만 줄로 세우고 주문상세에서 체크해 온 상품을 미리 선택한다', () => {
        mockDataSources = {
            claimable: claimableResponse,
            order: { data: { id: 120, base_currency: 'KRW' } },
        };

        initClaimApplyHandler(
            { handler: 'initClaimApply', params: { claimType: 'return', preselected: '310' } },
            {} as any
        );

        // 반품이 열린 상품은 310 하나뿐 — 교환만 열린 311 은 이 화면의 줄이 아니다.
        expect(mockLocalState.claimItems).toHaveLength(1);
        expect(mockLocalState.claimItems[0].order_option_id).toBe(310);
        expect(mockLocalState.claimItems[0].selected).toBe(true);
        // 청구 2축 기본값은 운영자 허용 목록의 첫 값이다 (리터럴 기본값 금지)
        expect(mockLocalState.claimChargeMethod).toBe('pay_on_request');
        expect(mockLocalState.claimPaymentMethod).toBe('card');
    });

    it('초기화 — 응답 페이로드는 액션 파라미터로 받는다 (데이터소스 store 는 자기 onSuccess 시점에 아직 비어 있다)', () => {
        // 회귀(2026-08-31 브라우저 실측): initClaimApply 는 claimable 데이터소스의 onSuccess 로
        // 실행된다. 그 시점의 G7Core.dataSource.get('claimable') 은 아직 null 이다 — TemplateApp
        // 의 React 상태 갱신이 refetch 해소 뒤에 일어나기 때문이다. 응답은 200 이고 오류도 없어
        // 화면만 빈 신청서로 남았다. 그래서 페이로드는 응답 컨텍스트에서 직접 받는다.
        mockDataSources = { order: { data: { id: 120, base_currency: 'KRW' } } };

        initClaimApplyHandler(
            {
                handler: 'initClaimApply',
                params: { claimType: 'exchange', preselected: '310', claimable: claimableResponse.data },
            },
            {} as any
        );

        expect(mockLocalState.claimType).toBe('exchange');
        expect(mockLocalState.claimItems).toHaveLength(2);
        expect(mockLocalState.claimItems[0].selected).toBe(true);
    });

    it('초기화 — 유형과 미리 선택 상품은 주소에서 읽는다 (데이터소스 onSuccess 에는 route·query 가 없다)', () => {
        // 회귀(2026-08-31 브라우저 실측): 레이아웃이 {{route.type}} 을 param 으로 넘겨도
        // 데이터소스 onSuccess 의 실행 컨텍스트에는 route·query 가 없어 빈 값이 도착한다.
        // 응답 200 · 콘솔 오류 0 인데 화면만 빈 신청서로 남았다.
        window.history.replaceState({}, '', '/mypage/orders/20260810-0458237422/claims/apply/exchange?options=311');
        mockDataSources = { order: { data: { id: 120, base_currency: 'KRW' } } };

        initClaimApplyHandler(
            { handler: 'initClaimApply', params: { claimable: claimableResponse.data } },
            {} as any
        );

        expect(mockLocalState.claimType).toBe('exchange');
        expect(mockLocalState.claimItems).toHaveLength(2);
        expect(mockLocalState.claimItems[0].selected).toBe(false);
        expect(mockLocalState.claimItems[1].selected).toBe(true);
    });

    it('주소 해석 — 유형과 options 를 꺼낸다', () => {
        window.history.replaceState({}, '', '/mypage/orders/A-1/claims/apply/return?options=1,2');
        expect(resolveClaimApplyRoute()).toEqual({ claimType: 'return', preselected: '1,2' });

        window.history.replaceState({}, '', '/mypage/orders/A-1/claims/apply/exchange');
        expect(resolveClaimApplyRoute()).toEqual({ claimType: 'exchange', preselected: '' });
    });

    it('사유 선택 — 귀책은 진입 시 박아 둔 카탈로그 사본에서 찾는다 (데이터소스 store 는 화면에 따라 비어 있다)', () => {
        // 회귀(2026-08-31 브라우저 실측): 주문 데이터소스가 blocking 인 작성 셸에서는 refetch 로
        // 받은 claimable 이 G7Core.dataSource.get 에 나타나지 않는다. 렌더 바인딩은 사유 목록을
        // 그리는데 핸들러만 null 을 봐서, 사유를 골라도 귀책 배지가 오류 없이 비어 있었다.
        window.history.replaceState({}, '', '/mypage/orders/A-1/claims/apply/exchange');
        mockDataSources = { order: { data: { id: 120, base_currency: 'KRW' } } };
        initClaimApplyHandler(
            { handler: 'initClaimApply', params: { claimable: claimableResponse.data } },
            {} as any
        );

        // store 는 계속 비어 있는 상태로 사유를 고른다.
        setClaimReasonHandler(
            { handler: 'setClaimReason', params: { value: 'size_mismatch', claimApiBase: API_BASE } },
            {} as any
        );

        expect(mockLocalState.claimFaultType).toBe('customer');
    });

    it('교환상품 변경 — 지정한 index 의 줄만 바뀐다 (D29)', () => {
        seedApplyState();

        mockLocalState.changeProductTarget = { index: 1, order_option_id: 311, product_id: 6 };
        mockLocalState.changeProductMode = 'change';
        mockLocalState.changeProductMatched = { id: 56, option_name_localized: '블랙 / L', stock_quantity: 4 };

        applyClaimChangeProductHandler(
            { handler: 'applyClaimChangeProduct', params: { claimApiBase: API_BASE, isGuest: false } },
            {} as any
        );

        expect(mockLocalState.claimItems[1].exchange_mode).toBe('change');
        expect(mockLocalState.claimItems[1].new_product_option_id).toBe(56);
        expect(mockLocalState.claimItems[1].new_option_label).toBe('블랙 / L');
        // 1행은 손대지 않는다 — index 를 흘리면 언제나 첫 상품이 바뀐다.
        expect(mockLocalState.claimItems[0].exchange_mode).toBe('same');
        expect(mockLocalState.claimItems[0].new_product_option_id).toBeNull();
    });

    it('교환상품 변경 — 다른 행의 모달을 열면 앞 행의 선택이 남지 않는다', async () => {
        // 회귀(2026-08-31 브라우저 실측): 초기화를 `{}` 로 쓰면 setLocal 의 깊은 병합에 먹혀
        // 무동작이 된다. 두 상품이 같은 옵션 값을 가지면 구매자가 **이 행에서 고르지 않은** 값이
        // 미리 선택된 채로 열리고, 남은 그룹만 고르면 그대로 적용된다 — 오류는 나지 않는다.
        seedApplyState();
        mockG7Core.api.get.mockResolvedValue({ data: { id: 6, option_groups: [], options: [] } });

        await openClaimChangeProductHandler(
            { handler: 'openClaimChangeProduct', params: { index: 0 } },
            {} as any
        );

        mockG7Core.state.setLocal({
            changeProductSelections: { '색상': '레드' },
            changeProductAdditionalSelections: { 7: 3 },
            changeProductAdditionalTexts: { 7: '각인' },
        });

        await openClaimChangeProductHandler(
            { handler: 'openClaimChangeProduct', params: { index: 1 } },
            {} as any
        );

        expect(mockLocalState.changeProductTarget.index).toBe(1);
        expect(mockLocalState.changeProductSelections ?? {}).toEqual({});
        expect(mockLocalState.changeProductAdditionalSelections ?? {}).toEqual({});
        expect(mockLocalState.changeProductAdditionalTexts ?? {}).toEqual({});
    });

    it('교환상품 변경 — 옵션변경인데 옵션을 고르지 않으면 적용하지 않는다', () => {
        seedApplyState();

        mockLocalState.changeProductTarget = { index: 0, order_option_id: 310, product_id: 5 };
        mockLocalState.changeProductMode = 'change';
        mockLocalState.changeProductMatched = null;

        applyClaimChangeProductHandler(
            { handler: 'applyClaimChangeProduct', params: { claimApiBase: API_BASE } },
            {} as any
        );

        expect(mockG7Core.toast.warning).toHaveBeenCalled();
        expect(mockLocalState.claimItems[0].exchange_mode).toBe('same');
    });

    it('수량 — 잔여 수량을 넘겨 입력하면 잔여 수량으로 잘린다', () => {
        seedApplyState();

        updateClaimQuantityHandler(
            { handler: 'updateClaimQuantity', params: { index: 0, value: '9', claimApiBase: API_BASE } },
            {} as any
        );

        expect(mockLocalState.claimItems[0].quantity).toBe(2);
    });

    it('수량 — 0 이하 입력은 1 로 올린다', () => {
        seedApplyState();

        updateClaimQuantityHandler(
            { handler: 'updateClaimQuantity', params: { index: 0, value: '0', claimApiBase: API_BASE } },
            {} as any
        );

        expect(mockLocalState.claimItems[0].quantity).toBe(1);
    });

    it('미리보기 — 성공하면 서버 금액과 상품별 차액을 그 줄에 되붙인다', async () => {
        seedApplyState();
        mockG7Core.api.post.mockResolvedValue({
            success: true,
            data: {
                type: 'exchange',
                shipping_fee: { fee: 6000, extra_fee: 0 },
                price_difference: 3000,
                additional_product_amount: 3000,
                refund_product_amount: 0,
                total_charge_amount: 9000,
                requires_payment: true,
                items: [
                    { order_option_id: 310, price_difference: 3000 },
                    { order_option_id: 311, price_difference: 0 },
                ],
            },
        });

        await estimateClaimHandler(
            { handler: 'estimateClaim', params: { claimApiBase: API_BASE, isGuest: false } },
            {} as any
        );

        expect(mockG7Core.api.post).toHaveBeenCalledWith(
            API_BASE + '/claims/preview',
            expect.objectContaining({ type: 'exchange', reason_code: 'size_mismatch' }),
            undefined
        );
        expect(mockLocalState.claimEstimate.total_charge_amount).toBe(9000);
        expect(mockLocalState.claimEstimateError).toBeNull();
        expect(mockLocalState.claimItems[0].price_difference).toBe(3000);
        expect(mockLocalState.claimItems[1].price_difference).toBe(0);
    });

    /**
     * 미리보기 거절 — 금액은 지우되 사용자의 선택(교환 대상)은 지우지 않는다.
     *
     * 거절은 곧 신청 거절 사유이므로 금액을 남기면 화면이 예고한 금액과 청구가 갈린다.
     * 반대로 거절을 이유로 사용자가 고른 교환 대상까지 되돌리면, 사용자는 자기가 무엇을
     * 골랐는지 잃은 채 다시 고르게 된다 — 둘 다 오류를 남기지 않는 결함이다.
     *
     * @scenario exchange_mode=change, preview_result=rejected
     * @effects claim_apply_estimate_failure_clears_amounts_and_blocks_submit
     */
    it('미리보기 — 거절되면 금액을 지우고 사유를 남기되 교환 대상 선택은 유지한다', async () => {
        seedApplyState();
        mockLocalState.claimEstimate = { total_charge_amount: 9000 };
        mockLocalState.changeProductTarget = { index: 0, order_option_id: 310, product_id: 5 };
        mockLocalState.changeProductMode = 'change';
        mockLocalState.changeProductMatched = { id: 56, option_name_localized: '블랙 / L', stock_quantity: 4 };
        applyClaimChangeProductHandler(
            { handler: 'applyClaimChangeProduct', params: { claimApiBase: API_BASE } },
            {} as any
        );

        mockG7Core.api.post.mockRejectedValue({
            response: { status: 422, data: { message: '신청할 수 없는 상태입니다.' } },
        });

        await estimateClaimHandler(
            { handler: 'estimateClaim', params: { claimApiBase: API_BASE } },
            {} as any
        );

        expect(mockLocalState.claimEstimate).toBeNull();
        expect(mockLocalState.claimEstimateFormatted).toBeNull();
        expect(mockLocalState.claimEstimateError).toBe('신청할 수 없는 상태입니다.');
        // 거절이 사용자의 선택까지 되돌리지 않는다.
        expect(mockLocalState.claimItems[0].exchange_mode).toBe('change');
        expect(mockLocalState.claimItems[0].new_product_option_id).toBe(56);
    });

    it('미리보기 — 늦게 도착한 옛 응답은 반영하지 않는다 (세대 번호)', async () => {
        seedApplyState();

        let resolveSlow: (value: any) => void = () => undefined;
        const slow = new Promise((resolve) => {
            resolveSlow = resolve;
        });

        mockG7Core.api.post
            .mockReturnValueOnce(slow)
            .mockResolvedValueOnce({
                success: true,
                data: { type: 'exchange', total_charge_amount: 3000, items: [] },
            });

        const first = estimateClaimHandler(
            { handler: 'estimateClaim', params: { claimApiBase: API_BASE } },
            {} as any
        );
        const second = estimateClaimHandler(
            { handler: 'estimateClaim', params: { claimApiBase: API_BASE } },
            {} as any
        );

        await second;
        resolveSlow({ success: true, data: { type: 'exchange', total_charge_amount: 99999, items: [] } });
        await first;

        // 최신 요청(두 번째)의 응답만 남는다.
        expect(mockLocalState.claimEstimate.total_charge_amount).toBe(3000);
    });

    it('미리보기 — 상품이나 사유가 비면 옛 금액을 지운다 (지금 선택의 결과로 읽히지 않게)', async () => {
        seedApplyState();
        mockLocalState.claimEstimate = { total_charge_amount: 9000 };
        mockLocalState.claimReason = '';

        await estimateClaimHandler(
            { handler: 'estimateClaim', params: { claimApiBase: API_BASE } },
            {} as any
        );

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
        expect(mockLocalState.claimEstimate).toBeNull();
    });

    it('제출 페이로드 — 선택된 줄만 싣고 동일상품 교환에는 new_product_option_id 를 싣지 않는다', () => {
        const payload = buildClaimItemsPayload([
            { order_option_id: 310, quantity: 1, selected: true, exchange_mode: 'same', new_product_option_id: null, custom_options: null } as any,
            { order_option_id: 311, quantity: 2, selected: true, exchange_mode: 'change', new_product_option_id: 56, custom_options: null } as any,
            { order_option_id: 312, quantity: 1, selected: false, exchange_mode: 'same', new_product_option_id: null, custom_options: null } as any,
        ]);

        expect(payload).toEqual([
            { order_option_id: 310, quantity: 1 },
            { order_option_id: 311, quantity: 2, new_product_option_id: 56 },
        ]);
    });

    it('제출 — 유형별 엔드포인트로 보내고 결제가 필요하면 응답이 지목한 PG 핸들러를 그대로 dispatch 한다', async () => {
        seedApplyState();
        mockLocalState.claimEstimate = { total_charge_amount: 9000, requires_payment: true };
        mockG7Core.api.post.mockResolvedValue({
            success: true,
            message: '신청이 접수되었습니다.',
            data: {
                claim: { claim_number: 'EX20260823000003' },
                requires_pg_payment: true,
                pg_payment_handler: 'sirsoft-pay_kginicis.requestPayment',
                pg_payment_data: { order_number: 'CP20260823000002', amount: 9000 },
            },
        });

        await submitClaimHandler(
            { handler: 'submitClaim', params: { claimApiBase: API_BASE, isGuest: false, successPath: '/mypage/claims' } },
            {} as any
        );

        expect(mockG7Core.api.post).toHaveBeenCalledWith(
            API_BASE + '/exchanges',
            expect.objectContaining({ reason_code: 'size_mismatch', charge_method: 'pay_on_request', payment_method: 'card' }),
            undefined
        );
        expect(mockG7Core.dispatch).toHaveBeenCalledWith({
            handler: 'sirsoft-pay_kginicis.requestPayment',
            params: { pgPaymentData: { order_number: 'CP20260823000002', amount: 9000 } },
        });
    });

    it('제출 — 422 는 필드 오류를 화면 상태로 남긴다', async () => {
        seedApplyState();
        mockG7Core.api.post.mockRejectedValue({
            response: { status: 422, data: { message: '검증 실패', errors: { reason_code: ['사유를 선택해 주세요.'] } } },
        });

        await submitClaimHandler(
            { handler: 'submitClaim', params: { claimApiBase: API_BASE } },
            {} as any
        );

        expect(mockLocalState.isSubmittingClaim).toBe(false);
        // 사유는 자기 칸 옆에 서므로 요약 카드는 비운다 — 남기면 같은 말이 두 번 나온다(§6-3 P13).
        expect(mockLocalState.claimSubmitError).toBeNull();
        expect(mockLocalState.claimValidationErrors).toEqual({ reason_code: ['사유를 선택해 주세요.'] });
        // 필드 오류는 자리별로도 묶인다 — 요약 카드의 message 한 줄만 남으면 어느 칸을
        // 고쳐야 하는지 알 수 없다(§6-3 P13).
        expect(mockLocalState.claimFieldErrors).toEqual({
            charge_method: '',
            payment_method: '',
            images: '',
            redelivery: '',
        });
        // 화면에 남는 안내를 토스트로 한 번 더 띄우지 않는다 (§6-3 P13).
        expect(mockG7Core.toast.error).not.toHaveBeenCalled();
    });

    it('제출 — 422 의 첨부·재발송지 오류는 접두사로 묶여 그 칸으로 간다', async () => {
        seedApplyState();
        mockG7Core.api.post.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    message: '검증 실패',
                    errors: {
                        'image_ids.0': ['이미지를 찾을 수 없습니다.'],
                        'redelivery_address.country_code': ['교환 재발송지의 국가는 변경할 수 없습니다.'],
                        charge_method: ['선택한 청구 방식은 사용할 수 없습니다.'],
                    },
                },
            },
        });

        await submitClaimHandler(
            { handler: 'submitClaim', params: { claimApiBase: API_BASE } },
            {} as any
        );

        expect(mockLocalState.claimFieldErrors.images).toBe('이미지를 찾을 수 없습니다.');
        expect(mockLocalState.claimFieldErrors.redelivery).toBe(
            '교환 재발송지의 국가는 변경할 수 없습니다.'
        );
        expect(mockLocalState.claimFieldErrors.charge_method).toBe(
            '선택한 청구 방식은 사용할 수 없습니다.'
        );
        // 세 사유 모두 자기 칸에 섰으므로 요약 카드는 비어 있다.
        expect(mockLocalState.claimSubmitError).toBeNull();
    });

    it('제출 — 어느 칸에도 매이지 않는 사유는 요약 카드가 맡는다', async () => {
        seedApplyState();
        mockG7Core.api.post.mockRejectedValue({
            response: {
                status: 422,
                data: {
                    message: '신청할 상품을 선택해 주세요.',
                    errors: { items: ['신청할 상품을 선택해 주세요.'] },
                },
            },
        });

        await submitClaimHandler(
            { handler: 'submitClaim', params: { claimApiBase: API_BASE } },
            {} as any
        );

        expect(mockLocalState.claimSubmitError).toBe('신청할 상품을 선택해 주세요.');
    });

    it('제출 — 오류 맵이 없는 4xx 는 서버 메시지를 요약 카드에 남긴다', async () => {
        seedApplyState();
        mockG7Core.api.post.mockRejectedValue({
            response: { status: 409, data: { message: '이미 처리된 신청입니다.' } },
        });

        await submitClaimHandler(
            { handler: 'submitClaim', params: { claimApiBase: API_BASE } },
            {} as any
        );

        expect(mockLocalState.claimSubmitError).toBe('이미 처리된 신청입니다.');
        expect(mockG7Core.toast.error).not.toHaveBeenCalled();
    });

    /**
     * 오류 채널 규약 (§6-3 P13) — 구매자가 고칠 수 있는 것만 화면에 남고, 고칠 수 없는 것은
     * 토스트로만 알린다. 상태코드를 보지 않고 한쪽으로 몰면 서버 장애가 "상품·수량·사유를
     * 다시 확인해 주세요" 로 안내되어 구매자가 고칠 수 없는 것을 고치려 들게 되는데,
     * 처리는 정상 실패이므로 화면에도 로그에도 어긋났다는 사실이 남지 않는다.
     *
     * @scenario exchange_mode=change, preview_result=rejected
     * @effects claim_apply_error_channel_splits_by_fixability
     */
    it('오류 채널 — 구매자가 고칠 수 있는 것만 화면에 남는다 (4xx)', () => {
        expect(isUserFixableClaimError({ response: { status: 422 } })).toBe(true);
        expect(isUserFixableClaimError({ response: { status: 403 } })).toBe(true);
        expect(isUserFixableClaimError({ status: 404 })).toBe(true);
    });

    it('오류 채널 — 서버 장애와 네트워크 실패는 화면에 남기지 않는다 (5xx · 상태코드 없음)', () => {
        expect(isUserFixableClaimError({ response: { status: 500 } })).toBe(false);
        expect(isUserFixableClaimError({ response: { status: 503 } })).toBe(false);
        // 응답 자체가 오지 않은 실패는 상태코드가 없다.
        expect(isUserFixableClaimError({ message: 'Network Error' })).toBe(false);
        expect(isUserFixableClaimError(undefined)).toBe(false);
    });

    it('미리보기 — 서버 장애는 토스트로만 알리고 화면에 사유를 남기지 않는다', async () => {
        seedApplyState();
        mockG7Core.api.post.mockRejectedValue({
            response: { status: 500, data: { message: 'Server Error' } },
        });

        await estimateClaimHandler(
            { handler: 'estimateClaim', params: { claimApiBase: API_BASE } },
            {} as any
        );

        expect(mockLocalState.claimEstimate).toBeNull();
        // 화면에 남기면 "상품·수량·사유를 다시 확인해 주세요" 안내가 함께 붙는다.
        expect(mockLocalState.claimEstimateError).toBeNull();
        expect(mockG7Core.toast.error).toHaveBeenCalled();
    });

    it('미리보기 — 네트워크 실패도 토스트로만 알린다 (상태코드가 없다)', async () => {
        seedApplyState();
        mockG7Core.api.post.mockRejectedValue(new Error('Network Error'));

        await estimateClaimHandler(
            { handler: 'estimateClaim', params: { claimApiBase: API_BASE } },
            {} as any
        );

        expect(mockLocalState.claimEstimateError).toBeNull();
        expect(mockG7Core.toast.error).toHaveBeenCalled();
    });

    it('제출 — 서버 장애는 토스트로만 알리고 요약 카드에 남기지 않는다', async () => {
        seedApplyState();
        mockG7Core.api.post.mockRejectedValue({
            response: { status: 500, data: { message: 'Server Error' } },
        });

        await submitClaimHandler(
            { handler: 'submitClaim', params: { claimApiBase: API_BASE } },
            {} as any
        );

        expect(mockLocalState.isSubmittingClaim).toBe(false);
        expect(mockLocalState.claimSubmitError).toBeNull();
        expect(mockLocalState.claimValidationErrors).toBeNull();
        expect(mockG7Core.toast.error).toHaveBeenCalled();
    });

    it('유형별 신청 엔드포인트 접미사', () => {
        expect(claimStoreSuffix('exchange')).toBe('/exchanges');
        expect(claimStoreSuffix('return')).toBe('/returns');
        expect(claimStoreSuffix('refund')).toBe('/refund-requests');
        expect(claimStoreSuffix('cancel')).toBeNull();
    });

    it('재발송지 — 주문 배송지의 필드 이름을 재발송지 페이로드 이름으로 옮긴다', () => {
        mockDataSources = {
            order: {
                data: {
                    shipping_address: {
                        recipient_name: '옥영진',
                        recipient_phone: '010-1234-5678',
                        zipcode: '06236',
                        address: '서울 강남구 테헤란로 1',
                        address_detail: '10층',
                        recipient_country_code: 'KR',
                    },
                },
            },
        };

        expect(orderShippingAddressAsRedelivery(mockG7Core)).toEqual({
            recipient_name: '옥영진',
            phone: '010-1234-5678',
            zipcode: '06236',
            address1: '서울 강남구 테헤란로 1',
            address2: '10층',
            country_code: 'KR',
        });
    });

    it('금액 표기 — 차액은 절댓값으로 포맷한다 (부호는 화면이 붙인다)', () => {
        const formatted = formatClaimEstimate(
            { shipping_fee: { fee: 6000, extra_fee: 0 }, price_difference: -3000, total_charge_amount: 6000 },
            'KRW'
        );

        expect(formatted.price_difference).not.toContain('-');
        expect(formatted.total_charge_amount).toContain('6,000');
    });

    it('금액 표기 — 배송비 산출 근거는 서버 그룹을 그대로 옮긴다 (화면이 곱셈하지 않는다)', () => {
        // §6-3: 합계만 보이면 구매자는 청구액이 어떻게 나왔는지 확인할 수 없다. 화면이 스스로
        // base_fee × multiplier 를 계산하면 훅이 금액을 덮어쓴 상점에서 합계와 근거가 갈린다.
        const formatted = formatClaimEstimate(
            {
                shipping_fee: {
                    fee: 9000,
                    extra_fee: 3000,
                    groups: [
                        { mode: 'original_policy', base_fee: 3000, multiplier: 2, extra_fee: 3000 },
                        { mode: 'original_policy', base_fee: 2500, multiplier: 1, extra_fee: 0 },
                    ],
                },
            },
            'KRW'
        );

        expect(formatted.shipping_groups).toHaveLength(2);
        expect(formatted.shipping_groups[0].base_fee).toContain('3,000');
        expect(formatted.shipping_groups[0].multiplier).toBe(2);
        expect(formatted.shipping_groups[0].has_extra_fee).toBe(true);
        expect(formatted.shipping_groups[1].multiplier).toBe(1);
        expect(formatted.shipping_groups[1].has_extra_fee).toBe(false);
    });

    it('금액 표기 — 고정액 모드는 근거로 분해하지 않는다 (D11)', () => {
        // 회귀(2026-08-31 브라우저 실측): 고정액은 운영자가 정책 화면에서 유형별 **총액**을
        // 선언한 값이라 서버도 배수를 곱하지 않는다. 그 값을 "편도 × N" 으로 적으면 선언액을
        // 단가로 잘못 부르게 되고, 교환인데 "× 1회" 로 보여 합계 라벨(왕복 배송비)과 어긋난다.
        const formatted = formatClaimEstimate(
            {
                shipping_fee: {
                    fee: 6000,
                    extra_fee: 0,
                    groups: [
                        { mode: 'fixed', base_fee: 6000, multiplier: 1, extra_fee: 0 },
                        { mode: 'original_policy', base_fee: 3000, multiplier: 2, extra_fee: 0 },
                    ],
                },
            },
            'KRW'
        );

        expect(formatted.shipping_groups).toHaveLength(1);
        expect(formatted.shipping_groups[0].multiplier).toBe(2);
    });

    it('보조통화 — 미리보기가 주는 숫자 맵을 그 통화로 포맷한다 (기준통화 제외)', () => {
        // 회귀(2026-08-31 3차 `/validate-plan`): 미리보기 응답의 다통화 값은 `{통화코드: 숫자}` 인데
        // (`toMultiCurrency` · `mc_fee`) 화면이 주문 리소스의 `{amount, formatted}` 를 가정하고
        // `.formatted` 로 읽고 있었다. 오류도 로그도 없이 그 줄이 통째로 빈 채 렌더된다.
        const formatted = formatClaimEstimate(
            {
                shipping_fee: { fee: 6000, extra_fee: 0, mc_fee: { KRW: 6000, USD: 4.5 } },
                total_charge_amount: 9000,
                mc_total_charge_amount: { KRW: 9000, USD: 6.75 },
            },
            'KRW'
        );

        // 기준통화(KRW)는 본문에 이미 있으므로 병기 목록에서 빠진다.
        expect(formatted.mc_total_charge_amount).toHaveLength(1);
        expect(formatted.mc_total_charge_amount[0].code).toBe('USD');
        expect(formatted.mc_total_charge_amount[0].formatted).toContain('6.75');

        expect(formatted.mc_shipping_fee).toHaveLength(1);
        expect(formatted.mc_shipping_fee[0].formatted).toContain('4.5');
    });

    it('보조통화 — 스냅샷이 없으면 빈 배열이라 그 줄이 서지 않는다', () => {
        const formatted = formatClaimEstimate(
            { shipping_fee: { fee: 6000 }, total_charge_amount: 9000, mc_total_charge_amount: null },
            'KRW'
        );

        expect(formatted.mc_total_charge_amount).toEqual([]);
        expect(formatted.mc_shipping_fee).toEqual([]);
    });

    it('보조통화 — 이미 포맷된 정산 문자열은 다시 포맷하지 않는다', () => {
        // 환불 예정액의 보조통화는 서버 정산(`refund_formatted.mc`)이 이미 포맷해 내려준다.
        // 같은 헬퍼가 세 형태(숫자 · 포맷 문자열 · {amount, formatted})를 모두 흡수해야
        // 출처마다 다른 형태가 화면에서 빈 줄로 나타나지 않는다.
        const formatted = formatClaimEstimate(
            {
                shipping_fee: { fee: 0 },
                adjustment: { refund_total: 26000, refund_formatted: { mc: { refund_total: { USD: '$19.50' } } } },
            },
            'KRW'
        );

        expect(formatted.mc_refund_total).toEqual([{ code: 'USD', formatted: '$19.50' }]);
    });

    it('보조통화 — `{amount, formatted}` 형태(주문 리소스)도 같은 헬퍼가 흡수한다', () => {
        expect(toSecondaryCurrencyList({ KRW: { amount: 9000, formatted: '9,000원' }, USD: { amount: 6.75, formatted: '$6.75' } }, 'KRW'))
            .toEqual([{ code: 'USD', formatted: '$6.75' }]);
    });

    it('제출 거절 — 항목별 오류를 제출 순번이 아니라 그 상품 줄에 붙인다 (P13)', () => {
        // 회귀(2026-08-31 3차 `/validate-plan`): 서버 키 `items.{순번}.{필드}` 의 순번은 제출
        // 페이로드 순번(선택된 행만 실린다)이라 화면의 행 번호와 다르다. 이 대응을 풀지 않으면
        // 오류 본문이 어디에도 그려지지 않고 요약 카드에는 message 만 남는다.
        const items = [
            { order_option_id: 10, selected: false, validation_error: '' },
            { order_option_id: 20, selected: true, validation_error: '' },
            { order_option_id: 30, selected: true, validation_error: '' },
        ] as any;

        // 페이로드에는 20·30 만 실리므로 `items.1` 은 화면 3번째 줄(30번)이다.
        const next = attachClaimItemErrors(items, { 'items.1.quantity': ['신청 수량이 유효하지 않습니다.'] });

        expect(next[0].validation_error).toBe('');
        expect(next[1].validation_error).toBe('');
        expect(next[2].validation_error).toBe('신청 수량이 유효하지 않습니다.');
    });

    it('제출 거절 — 오류 맵이 없으면 지난 시도의 표시를 지운다', () => {
        const items = [{ order_option_id: 20, selected: true, validation_error: '지난 오류' }] as any;

        expect(attachClaimItemErrors(items, null)[0].validation_error).toBe('');
    });

    /**
     * 필드 오류 묶기 — 서버가 키를 쪼개 돌려주는 자리(첨부·재발송지)를 화면이 키마다 열거하면
     * 서버가 키를 더하거나 이름을 바꿀 때 그 사유만 조용히 사라진다.
     */
    it('필드 오류 — 접두사로 묶어 자리마다 첫 사유를 남긴다', () => {
        const grouped = collectClaimFieldErrors({
            'redelivery_address.zipcode': ['우편번호를 확인해 주세요.'],
            'image_ids.2': ['형식이 올바르지 않습니다.'],
            payment_method: ['선택한 결제 수단은 사용할 수 없습니다.'],
            reason_code: ['사유를 선택해 주세요.'],
        });

        expect(grouped.redelivery).toBe('우편번호를 확인해 주세요.');
        expect(grouped.images).toBe('형식이 올바르지 않습니다.');
        expect(grouped.payment_method).toBe('선택한 결제 수단은 사용할 수 없습니다.');
        // 사유는 자기 키로 곧바로 읽는 자리라 여기서 묶지 않는다.
        expect(grouped.charge_method).toBe('');
    });

    it('필드 오류 — refund_priority 는 결제 수단 칸이 정하는 값이라 같은 자리에 남는다', () => {
        expect(collectClaimFieldErrors({ refund_priority: ['사용할 수 없는 환불 우선순위입니다.'] })
            .payment_method).toBe('사용할 수 없는 환불 우선순위입니다.');
    });

    it('배치 판정 — 상품 줄이 받지 못하는 키는 요약이 맡는다', () => {
        const items = [{ order_option_id: 20, selected: true }] as any;

        // 순번 매핑이 읽는 키는 그 줄이 받는다.
        expect(unplacedClaimErrorMessage({ 'items.0.quantity': ['수량 오류'] }, items)).toBe('');
        // 읽지 않는 키(custom_options)는 어디에도 서지 않으므로 요약이 받아야 한다.
        expect(unplacedClaimErrorMessage({ 'items.0.custom_options': ['추가 옵션 오류'] }, items))
            .toBe('추가 옵션 오류');
        // 페이로드 범위를 넘는 순번도 그 줄이 없으므로 요약이 받는다.
        expect(unplacedClaimErrorMessage({ 'items.3.quantity': ['수량 오류'] }, items))
            .toBe('수량 오류');
    });

    it('필드 오류 — 오류가 없으면 전 자리가 빈 문자열이다', () => {
        expect(collectClaimFieldErrors(null)).toEqual({
            charge_method: '',
            payment_method: '',
            images: '',
            redelivery: '',
        });
    });

    /**
     * 단가 보조통화 — 주문 리소스의 `{통화: {amount, formatted}}` 를 화면이 그대로 읽으면
     * 값이 새거나 빈 줄이 된다. 핸들러가 기준통화를 빼고 편 배열로 넘긴다(§5-4).
     */
    it('초기화 — 상품 줄 단가의 보조통화를 기준통화를 뺀 목록으로 편다', () => {
        mockDataSources = {
            claimable: claimableResponse,
            order: { data: { id: 120, base_currency: 'KRW' } },
        };

        initClaimApplyHandler(
            { handler: 'initClaimApply', params: { claimType: 'exchange', preselected: '311' } },
            {} as any
        );

        const row = mockLocalState.claimItems.find((item: any) => item.order_option_id === 311);

        expect(row.mc_unit_price).toEqual([{ code: 'USD', formatted: '$41.65' }]);
    });

    it('미리보기 — 연타해도 요청은 한 번만 나간다 (debounce)', async () => {
        vi.useFakeTimers();
        seedApplyState();
        mockG7Core.api.post.mockResolvedValue({ data: { shipping_fee: { fee: 0 }, items: [], total_charge_amount: 0 } });

        // 청구 방식을 세 번 연달아 누른다 — 토글마다 발사하면 왕복이 겹쳐 옆 응답이 새 선택 위에 덮인다.
        for (const value of ['pay_on_request', 'deduct_from_refund', 'pay_on_request']) {
            setClaimChargeMethodHandler(
                { handler: 'setClaimChargeMethod', params: { value, claimApiBase: API_BASE } },
                {} as any
            );
        }

        expect(mockG7Core.api.post).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(400);

        expect(mockG7Core.api.post).toHaveBeenCalledTimes(1);
        vi.useRealTimers();
    });

    it('청구 2축 — 청구 방식은 서버 금액을 바꾸므로 다시 계산하고, 결제 수단은 바꾸지 않는다 (D6)', async () => {
        vi.useFakeTimers();
        seedApplyState();
        mockG7Core.api.post.mockResolvedValue({ data: { shipping_fee: { fee: 0 }, items: [], total_charge_amount: 0 } });

        // claimApiBase 를 함께 넘긴다 — 넘기지 않으면 미리보기 예약이 base 없음으로 조용히
        // 무동작이 되어, 이 핸들러에 재계산을 붙여도 테스트가 그대로 통과한다(가드가 죽는다).
        setClaimPaymentMethodHandler(
            { handler: 'setClaimPaymentMethod', params: { value: 'point', claimApiBase: API_BASE } } as any,
            {} as any
        );

        await vi.advanceTimersByTimeAsync(400);

        expect(mockLocalState.claimPaymentMethod).toBe('point');
        expect(mockG7Core.api.post).not.toHaveBeenCalled();

        setClaimChargeMethodHandler(
            { handler: 'setClaimChargeMethod', params: { value: 'deduct_from_refund', claimApiBase: API_BASE } },
            {} as any
        );

        await vi.advanceTimersByTimeAsync(400);

        expect(mockLocalState.claimChargeMethod).toBe('deduct_from_refund');
        expect(mockG7Core.api.post).toHaveBeenCalledTimes(1);
        expect(mockG7Core.api.post.mock.calls[0][1].charge_method).toBe('deduct_from_refund');
        vi.useRealTimers();
    });

    it('타이머 정리 — 화면을 떠나면 예약된 미리보기는 나가지 않는다', async () => {
        vi.useFakeTimers();
        seedApplyState();
        mockG7Core.api.post.mockResolvedValue({ data: {} });

        setClaimChargeMethodHandler(
            { handler: 'setClaimChargeMethod', params: { value: 'pay_on_request', claimApiBase: API_BASE } },
            {} as any
        );

        // lifecycle.onUnmount — 정리하지 않으면 떠난 화면의 응답이 다음 화면의 로컬 상태에 쓰인다.
        clearClaimApplyTimers();

        await vi.advanceTimersByTimeAsync(400);

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
        vi.useRealTimers();
    });
});
