/**
 * userChangeShippingAddressHandlers 테스트
 *
 * @description
 * - changeShippingAddress: 배송지 변경 API (회원/비회원 단일 핸들러)
 *   - 비회원: guest endpoint + X-Guest-Order-Token 헤더 + editingShippingAddress body
 *   - 회원 saved 모드: user endpoint + { address_id } body
 *   - 회원 manual 모드: user endpoint + editingShippingAddress body
 *   - 성공: 모달 닫기 + order 리패치 + 토스트 + isSubmittingAddress 해제
 *   - 실패: 에러 토스트 + isSubmittingAddress 복구
 *
 * @scenario actor=guest, change_mode=manual, e2e_browser=chromium
 * @effects change_address_handler_branches_guest_endpoint_with_token_header_when_guest_context,
 *   change_address_handler_uses_user_endpoint_without_token_header_for_member,
 *   change_address_handler_saved_mode_sends_address_id_manual_sends_full_object,
 *   change_address_handler_resets_issubmitting_and_closes_modal_and_refetches_order_on_success
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { changeShippingAddressHandler } from '../../handlers/userChangeShippingAddressHandlers';

let mockLocalState: Record<string, any> = {};
let mockGlobalState: Record<string, any> = {};
let mockOrderDataSource: { data?: { order_number?: string } } | null = null;

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
        get: vi.fn((key: string) => (key === '_global' ? mockGlobalState : undefined)),
    },
    dataSource: {
        get: vi.fn((id: string) => (id === 'order' ? mockOrderDataSource : null)),
    },
    api: {
        put: vi.fn(),
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

const manualAddress = {
    recipient_name: '옥영진',
    recipient_phone: '010-1234-5678',
    zipcode: '06236',
    address: '서울 강남구 테헤란로 1',
    address_detail: '10층',
};

describe('changeShippingAddressHandler', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = { isSubmittingAddress: false };
        mockGlobalState = {};
        mockOrderDataSource = null;
        (window as any).G7Core = mockG7Core;
    });

    /**
     * 비회원은 guest endpoint 로 토큰 헤더를 붙여 직접 입력 주소 전체를 보내고, 성공하면 창을 닫고 주문을 다시 읽는다.
     *
     * @scenario actor=guest, change_mode=manual, address_region=domestic, e2e_browser=chromium
     *
     * @effects change_address_handler_branches_guest_endpoint_with_token_header_when_guest_context,
     *   change_address_handler_resets_issubmitting_and_closes_modal_and_refetches_order_on_success
     */
    it('비회원 — guest endpoint + X-Guest-Order-Token 헤더 + editingShippingAddress body', async () => {
        mockGlobalState = {
            guestOrderToken: 'guest-token-abc',
            editingShippingAddress: manualAddress,
        };
        mockOrderDataSource = { data: { order_number: '20260527-123456' } };
        mockG7Core.api.put.mockResolvedValue({ success: true });

        await changeShippingAddressHandler(
            { handler: 'changeShippingAddress', params: {} },
            {} as any
        );

        expect(mockG7Core.api.put).toHaveBeenCalledWith(
            '/api/modules/sirsoft-ecommerce/guest/orders/20260527-123456/shipping-address',
            manualAddress,
            { headers: { 'X-Guest-Order-Token': 'guest-token-abc' } }
        );
        expect(mockG7Core.modal.close).toHaveBeenCalledWith('changeAddressModal');
        expect(mockG7Core.dispatch).toHaveBeenCalledWith(
            expect.objectContaining({ handler: 'refetchDataSource', params: { dataSourceId: 'order' } })
        );
        expect(mockG7Core.toast.success).toHaveBeenCalled();
    });

    /**
     * 회원이 저장된 배송지를 고르면 user endpoint 로 address_id 만 보내고 토큰 헤더는 붙이지 않는다.
     *
     * @scenario actor=member, change_mode=saved, address_region=domestic, e2e_browser=chromium
     *
     * @effects change_address_handler_uses_user_endpoint_without_token_header_for_member,
     *   change_address_handler_saved_mode_sends_address_id_manual_sends_full_object
     */
    it('회원 saved 모드 — user endpoint + { address_id } body (토큰 헤더 없음)', async () => {
        mockGlobalState = {
            changeAddressMode: 'saved',
            selectedAddressId: 42,
        };
        mockG7Core.api.put.mockResolvedValue({ success: true });

        await changeShippingAddressHandler(
            { handler: 'changeShippingAddress', params: { orderId: 7 } },
            {} as any
        );

        expect(mockG7Core.api.put).toHaveBeenCalledWith(
            '/api/modules/sirsoft-ecommerce/user/orders/7/shipping-address',
            { address_id: 42 },
            undefined
        );
    });

    /**
     * 회원이 직접 입력하면 user endpoint 로 입력한 주소 객체 전체를 보낸다.
     *
     * @scenario actor=member, change_mode=manual, address_region=domestic, e2e_browser=chromium
     *
     * @effects change_address_handler_uses_user_endpoint_without_token_header_for_member,
     *   change_address_handler_saved_mode_sends_address_id_manual_sends_full_object
     */
    it('회원 manual 모드 — user endpoint + editingShippingAddress body', async () => {
        mockGlobalState = {
            changeAddressMode: 'manual',
            editingShippingAddress: manualAddress,
        };
        mockG7Core.api.put.mockResolvedValue({ success: true });

        await changeShippingAddressHandler(
            { handler: 'changeShippingAddress', params: { orderId: 7 } },
            {} as any
        );

        expect(mockG7Core.api.put).toHaveBeenCalledWith(
            '/api/modules/sirsoft-ecommerce/user/orders/7/shipping-address',
            manualAddress,
            undefined
        );
    });

    /**
     * 성공하면 제출 중 표시를 켰다가 다시 끈다.
     *
     * @scenario actor=member, change_mode=saved, address_region=domestic, e2e_browser=chromium
     *
     * @effects change_address_handler_resets_issubmitting_and_closes_modal_and_refetches_order_on_success
     */
    it('성공 시 isSubmittingAddress=false 로 해제된다', async () => {
        mockGlobalState = { changeAddressMode: 'saved', selectedAddressId: 1 };
        mockG7Core.api.put.mockResolvedValue({ success: true });

        await changeShippingAddressHandler(
            { handler: 'changeShippingAddress', params: { orderId: 7 } },
            {} as any
        );

        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ isSubmittingAddress: true });
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ isSubmittingAddress: false });
    });

    /**
     * 서버가 거부하면 서버 안내를 오류 토스트로 보여 주고 제출 중 표시를 되돌리며 창은 닫지 않는다.
     *
     * @scenario request_state=api_error
     *
     * @effects change_address_handler_shows_server_message_and_restores_submitting_on_failure
     */
    it('실패 시 에러 토스트 + isSubmittingAddress 복구', async () => {
        mockGlobalState = { changeAddressMode: 'manual', editingShippingAddress: manualAddress };
        mockG7Core.api.put.mockRejectedValue({
            response: { data: { message: '배송지 변경에 실패했습니다.' } },
        });

        await changeShippingAddressHandler(
            { handler: 'changeShippingAddress', params: { orderId: 7 } },
            {} as any
        );

        expect(mockG7Core.toast.error).toHaveBeenCalledWith('배송지 변경에 실패했습니다.');
        expect(mockG7Core.state.setLocal).toHaveBeenCalledWith({ isSubmittingAddress: false });
        expect(mockG7Core.modal.close).not.toHaveBeenCalled();
    });

    /**
     * 회원 주문 식별자도 비회원 토큰도 없으면 보낼 주소를 만들 수 없으므로 요청하지 않는다.
     *
     * @scenario request_state=missing_order_identity
     *
     * @effects change_address_handler_skips_request_without_order_identity
     */
    it('회원 식별자(orderId)도 비회원 토큰도 없으면 API 호출하지 않는다', async () => {
        mockGlobalState = { changeAddressMode: 'manual', editingShippingAddress: manualAddress };

        await changeShippingAddressHandler(
            { handler: 'changeShippingAddress', params: {} },
            {} as any
        );

        expect(mockG7Core.api.put).not.toHaveBeenCalled();
    });

    /**
     * 회귀(#703): 저장된 배송지 탭에서 아무것도 고르지 않고 적용하면 address_id:null 이 나가 422 가 났다.
     *
     * @scenario change_mode=saved, selection=none
     *
     * @effects saved_mode_without_selection_is_not_sent
     */
    it('회원 saved 모드에서 고른 배송지가 없으면 API 를 호출하지 않고 안내한다', async () => {
        mockGlobalState = { changeAddressMode: 'saved', selectedAddressId: null };

        await changeShippingAddressHandler(
            { handler: 'changeShippingAddress', params: { orderId: 42 } },
            {} as any
        );

        expect(mockG7Core.api.put).not.toHaveBeenCalled();
        expect(mockG7Core.toast.error).toHaveBeenCalledWith('mypage.order_detail.select_saved_address_required');
        expect(mockLocalState.isSubmittingAddress).toBe(false);
    });
});
