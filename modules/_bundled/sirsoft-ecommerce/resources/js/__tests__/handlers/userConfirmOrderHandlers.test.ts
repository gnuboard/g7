/**
 * userConfirmOrderHandlers 테스트
 *
 * @description
 * - confirmOrderOption: 구매확정 API 호출 + 성공/실패 시 상태 업데이트
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { confirmOrderOptionHandler } from '../../handlers/userConfirmOrderHandlers';

let mockLocalState: Record<string, any> = {};

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
    },
    api: {
        post: vi.fn(),
    },
    toast: {
        success: vi.fn(),
        error: vi.fn(),
    },
    modal: {
        close: vi.fn(),
        open: vi.fn(),
    },
    dispatch: vi.fn(),
    t: vi.fn((key: string) => key),
    createLogger: () => ({
        log: vi.fn(),
        warn: vi.fn(),
        error: vi.fn(),
    }),
};

describe('confirmOrderOptionHandler', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = {
            isConfirming: false,
            confirmTarget: null,
        };
        (window as any).G7Core = mockG7Core;
    });

    it('구매확정 성공 시 모달 닫기 + 데이터 리패치 + 토스트', async () => {
        mockG7Core.api.post.mockResolvedValue({
            success: true,
            message: '구매확정이 완료되었습니다.',
        });

        await confirmOrderOptionHandler(
            { handler: 'confirmOrderOption', params: { orderId: 1, optionId: 2 } },
            {} as any
        );

        // API 호출 확인
        expect(mockG7Core.api.post).toHaveBeenCalledWith(
            '/api/modules/sirsoft-ecommerce/user/orders/1/options/2/confirm'
        );

        // 상태 초기화 확인
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith(
            expect.objectContaining({ isConfirming: false, confirmTarget: null })
        );

        // 모달 닫기 확인
        expect(mockG7Core.modal.close).toHaveBeenCalledWith('modal_confirm_purchase');

        // 데이터 리패치 확인
        expect(mockG7Core.dispatch).toHaveBeenCalledWith(
            expect.objectContaining({
                handler: 'refetchDataSource',
                params: { dataSourceId: 'order' },
            })
        );

        // 토스트 확인
        expect(mockG7Core.toast.success).toHaveBeenCalledWith('구매확정이 완료되었습니다.');
    });

    it('구매확정 실패 시 에러 토스트 표시', async () => {
        mockG7Core.api.post.mockRejectedValue({
            response: { data: { message: '구매확정할 수 없습니다.' } },
        });

        await confirmOrderOptionHandler(
            { handler: 'confirmOrderOption', params: { orderId: 1, optionId: 2 } },
            {} as any
        );

        // isConfirming false 상태 확인
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith(
            expect.objectContaining({ isConfirming: false })
        );

        // 에러 토스트 확인
        expect(mockG7Core.toast.error).toHaveBeenCalledWith('구매확정할 수 없습니다.');

        // 모달은 닫히지 않아야 함
        expect(mockG7Core.modal.close).not.toHaveBeenCalled();
    });

    it('params 누락 시 조기 반환', async () => {
        await confirmOrderOptionHandler(
            { handler: 'confirmOrderOption', params: {} as any },
            {} as any
        );

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
    });

    it('isConfirming 상태가 true로 설정된 후 API 호출', async () => {
        mockG7Core.api.post.mockResolvedValue({ success: true, message: 'OK' });

        await confirmOrderOptionHandler(
            { handler: 'confirmOrderOption', params: { orderId: 1, optionId: 2 } },
            {} as any
        );

        // 첫 번째 setLocal 호출이 isConfirming: true
        expect(mockG7Core.state.setLocal).toHaveBeenNthCalledWith(1, { isConfirming: true });
    });
});
