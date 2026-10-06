/**
 * 핸들러는 엔진 내부 전역이 아니라 자기가 받은 액션 컨텍스트로 상태를 반영한다 (#639)
 *
 * @description
 * 주문 취소 모달의 환불 예상금액, 클레임 신청서의 미리보기는 입력을 debounce 로 묶어 나중에
 * 계산한다. 콜백이 돌 때는 액션 실행이 이미 끝나 엔진이 핸들러를 부른 컴포넌트를 모르므로, 종전에는
 * 엔진 내부 전역(`window.__g7ActionContext`)을 저장했다가 바꿔 끼웠다. 엔진이 내부 구조를 바꾸면
 * 조용히 깨지는 자리라, 이제 핸들러가 받은 컨텍스트의 `setState` 로 같은 값을 함께 싣는다.
 *
 * 상품 복사의 새 상품코드도 모달 여부를 엔진 내부 스택(`__g7LayoutContextStack`)이 아니라 핸들러가
 * 받은 데이터 컨텍스트의 `$parent` 로 판정한다.
 *
 * @vitest-environment jsdom
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import {
    changeRefundPriorityHandler,
    clearCancelOrderTimers,
    updateCancelQuantityHandler,
} from '../../handlers/cancelOrderHandlers';
import { clearClaimApplyTimers, setClaimReasonHandler } from '../../handlers/claimApplyHandlers';
import { generateCopyProductCodeHandler } from '../../handlers/generateCopyProductCode';

let mockLocalState: Record<string, any> = {};

const mockG7Core = {
    state: {
        getLocal: vi.fn(() => mockLocalState),
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
        setParentLocal: vi.fn(),
    },
    dataSource: { get: vi.fn(() => null) },
    api: { post: vi.fn() },
    toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
    modal: { close: vi.fn() },
    t: vi.fn((key: string) => key),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

/**
 * 엔진 내부 전역에 누가 값을 쓰면 잡아 내는 감시를 건다.
 *
 * @returns 쓰기 기록
 */
function watchPrivateGlobals(): string[] {
    const writes: string[] = [];

    for (const name of ['__g7ActionContext', '__g7LayoutContextStack']) {
        Object.defineProperty(window, name, {
            configurable: true,
            get: () => undefined,
            set: () => {
                writes.push(name);
            },
        });
    }

    return writes;
}

beforeEach(() => {
    vi.useFakeTimers();
    mockLocalState = {};
    vi.clearAllMocks();
    (window as any).G7Core = mockG7Core;
});

afterEach(() => {
    clearCancelOrderTimers();
    clearClaimApplyTimers();
    vi.useRealTimers();
    delete (window as any).__g7ActionContext;
    delete (window as any).__g7LayoutContextStack;
    delete (window as any).G7Core;
});

describe('주문 취소 모달 — 환불 예상금액 debounce', () => {
    /**
     * @scenario handler=cancel_quantity_debounce
     * @effects deferred_state_reaches_the_calling_component, engine_private_globals_untouched
     */
    it('수량을 바꾸면 debounce 뒤 계산 상태가 핸들러가 받은 컨텍스트의 setState 에도 실린다', async () => {
        const writes = watchPrivateGlobals();
        const setState = vi.fn();
        mockLocalState = { cancelItems: [{ id: 7, cancel_quantity: 2 }] };
        mockG7Core.api.post.mockResolvedValue({ success: true, data: { refund_amount: 1000 } });

        updateCancelQuantityHandler(
            { handler: 'updateCancelQuantity', params: { optionId: 7, maxQuantity: 3, orderId: 'ORD-1', value: 1 } },
            { setState } as any,
        );

        expect(setState).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(500);

        expect(setState).toHaveBeenCalledWith({ refundLoading: true });
        expect(setState).toHaveBeenCalledWith(expect.objectContaining({ refundEstimate: { refund_amount: 1000 }, refundLoading: false }));
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ refundLoading: true });
        expect(writes).toEqual([]);
    });

    /**
     * @scenario handler=refund_priority_debounce
     * @effects deferred_state_reaches_the_calling_component, engine_private_globals_untouched
     */
    it('환불 우선순위를 바꾸면 debounce 뒤 계산 상태가 컨텍스트의 setState 에 실린다', async () => {
        const writes = watchPrivateGlobals();
        const setState = vi.fn();
        mockLocalState = { cancelItems: [] };

        changeRefundPriorityHandler(
            { handler: 'changeRefundPriority', params: { priority: 'points_first', orderId: 'ORD-1' } },
            { setState } as any,
        );

        await vi.advanceTimersByTimeAsync(300);

        // 취소 항목이 없으면 예상금액을 비운다 — 그 결과도 모달 컴포넌트에 닿아야 한다
        expect(setState).toHaveBeenCalledWith({ refundEstimate: null });
        expect(writes).toEqual([]);
    });

    /**
     * @scenario handler=cancel_quantity_debounce
     * @effects deferred_state_reaches_the_calling_component
     */
    it('컨텍스트에 setState 가 없어도 정본 _local 에는 반영한다', async () => {
        mockLocalState = { cancelItems: [] };

        changeRefundPriorityHandler(
            { handler: 'changeRefundPriority', params: { priority: 'pg_first', orderId: 'ORD-1' } },
            {} as any,
        );

        await vi.advanceTimersByTimeAsync(300);

        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ refundEstimate: null });
    });
});

describe('클레임 신청서 — 미리보기 debounce', () => {
    /**
     * @scenario handler=claim_estimate_debounce
     * @effects deferred_state_reaches_the_calling_component, engine_private_globals_untouched
     */
    it('사유를 고르면 debounce 뒤 미리보기 상태가 컨텍스트의 setState 에도 실린다', async () => {
        const writes = watchPrivateGlobals();
        const setState = vi.fn();
        // 신청 상품이 아직 없으면 미리보기는 금액을 비운다 — 그 쓰기가 컴포넌트까지 가는지 본다
        mockLocalState = { claimType: 'refund', claimItems: [] };

        setClaimReasonHandler(
            { handler: 'setClaimReason', params: { value: 'change_of_mind', claimApiBase: '/api/modules/sirsoft-ecommerce/user/orders/ORD-1', isGuest: false } },
            { setState } as any,
        );

        await vi.advanceTimersByTimeAsync(400);

        expect(setState).toHaveBeenCalledWith(expect.objectContaining({ claimEstimate: null, claimEstimateLoading: false }));
        expect(writes).toEqual([]);
    });
});

describe('상품 복사 — 새 상품코드', () => {
    /**
     * @scenario handler=copy_product_code
     * @effects modal_detected_from_handler_context, engine_private_globals_untouched
     */
    it('데이터 컨텍스트에 $parent 가 있으면(모달) 부모 _local 에 저장한다', async () => {
        const writes = watchPrivateGlobals();
        mockG7Core.api.post.mockResolvedValue({ data: { product_code: 'P-NEW' } });

        await generateCopyProductCodeHandler(
            { handler: 'generateCopyProductCode' },
            { data: { $parent: { _local: {} } } } as any,
        );

        expect(mockG7Core.state.setParentLocal).toHaveBeenCalledWith({ 'ui.copyOptions.newProductCode': 'P-NEW' });
        expect(mockG7Core.state.setLocal).not.toHaveBeenCalled();
        expect(writes).toEqual([]);
    });

    /**
     * @scenario handler=copy_product_code
     * @effects modal_detected_from_handler_context
     */
    it('$parent 가 없으면(폼) 현재 _local 에 저장한다', async () => {
        mockG7Core.api.post.mockResolvedValue({ data: { product_code: 'P-FORM' } });

        await generateCopyProductCodeHandler({ handler: 'generateCopyProductCode' }, { data: {} } as any);

        expect(mockG7Core.state.setParentLocal).not.toHaveBeenCalled();
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({
            ui: { copyOptions: { newProductCode: 'P-FORM' } },
        });
    });
});
