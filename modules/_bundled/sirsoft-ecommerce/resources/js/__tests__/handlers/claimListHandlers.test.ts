/**
 * claimListHandlers 테스트 (#599 S12)
 *
 * @description
 * 철회에서 조용히 깨지는 축만 고정한다:
 *  - 대상 없이 열면 확인 버튼이 어느 신청서를 가리키는지 알 수 없다 — 그 상태로 확인하면
 *    아무 일도 일어나지 않고 오류도 남지 않는다.
 *  - 닫을 때 대상을 지우지 않으면 다음에 다른 신청서로 열었을 때 앞 대상이 남아, 그 사이에
 *    확인을 누르면 고르지 않은 신청서가 철회된다.
 *  - 더블클릭으로 두 번 나가면 두 번째는 이미 철회된 신청서를 다시 철회하려다 거절된다 —
 *    성공한 철회 뒤에 실패 안내가 뜬다.
 *  - 승인 뒤 철회(409)는 구매자가 이 화면에서 고칠 수 없지만 **왜 안 되는지**는 알아야 한다 —
 *    토스트로만 알리면 사라진 뒤 화면에 남지 않는다(§6-3 P13 과 같은 채널 규약).
 *
 * @scenario claim_type=refund, charge_method=none, refund_branch=request
 * @effects claim_withdraw_requires_a_target,
 *   claim_withdraw_clears_target_on_close,
 *   claim_withdraw_is_idempotent_against_double_click,
 *   claim_withdraw_error_channel_splits_by_fixability,
 *   claim_withdraw_uses_the_shell_supplied_base
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
    openClaimWithdrawHandler,
    closeClaimWithdrawHandler,
    withdrawClaimHandler,
    payClaimOutstandingHandler,
} from '../../handlers/claimListHandlers';

let mockLocalState: Record<string, any> = {};

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
        get: vi.fn(() => ({})),
    },
    api: { post: vi.fn() },
    toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
    modal: { open: vi.fn(), close: vi.fn() },
    dispatch: vi.fn(),
    t: vi.fn((key: string) => key),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

// 철회 라우트는 회원과 비회원의 **모양이 다르다**. 두 상수는 실제 라우트에서 그대로 옮긴 값이며
// (`php artisan route:list --path=withdraw`), 이 파일이 지켜야 할 것은 "핸들러가 셸이 준 앞부분에
// 번호만 잇는다" 이다 — 핸들러가 스스로 주소를 조립하면 한쪽이 404 가 된다(2026-08-31 실측).
const MEMBER_WITHDRAW_BASE = '/api/modules/sirsoft-ecommerce/user/claims';
const GUEST_WITHDRAW_BASE = '/api/modules/sirsoft-ecommerce/guest/orders/20260823-0012/claims';

// 추가결제 라우트도 회원/비회원의 모양이 다르다 (`php artisan route:list --path=claim-payments`).
const MEMBER_PAY_BASE = '/api/modules/sirsoft-ecommerce/user/claim-payments';
const GUEST_PAY_BASE = '/api/modules/sirsoft-ecommerce/guest/orders/20260823-0012/claim-payments';

describe('claimListHandlers', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = {};
        (window as any).G7Core = mockG7Core;
    });

    /** @effects claim_withdraw_modal_target_lifecycle */
    it('열기 — 대상 클레임 번호를 세우고 모달을 연다', () => {
        openClaimWithdrawHandler(
            { handler: 'openClaimWithdraw', params: { claimNumber: 'RT20260901-0001', claimLabel: '반품' } },
            {} as any
        );

        expect(mockLocalState.withdrawClaimNumber).toBe('RT20260901-0001');
        expect(mockLocalState.withdrawClaimLabel).toBe('반품');
        expect(mockLocalState.withdrawError).toBeNull();
        expect(mockG7Core.modal.open).toHaveBeenCalledWith('modal_claim_withdraw');
    });

    /** @effects claim_withdraw_modal_target_lifecycle */
    it('열기 — 대상이 없으면 열지 않는다 (확인 버튼이 아무것도 가리키지 않는 모달을 만들지 않는다)', () => {
        openClaimWithdrawHandler({ handler: 'openClaimWithdraw', params: {} }, {} as any);

        expect(mockG7Core.modal.open).not.toHaveBeenCalled();
        expect(mockLocalState.withdrawClaimNumber).toBeUndefined();
    });

    /** @effects claim_withdraw_modal_target_lifecycle */
    it('닫기 — 대상·오류를 함께 지운다 (다음에 다른 신청서로 열 때 앞 대상이 남지 않는다)', () => {
        mockLocalState = {
            withdrawClaimNumber: 'RT20260901-0001',
            withdrawClaimLabel: '반품',
            withdrawError: '승인된 신청은 철회할 수 없습니다.',
            isWithdrawingClaim: false,
        };

        closeClaimWithdrawHandler({ handler: 'closeClaimWithdraw' }, {} as any);

        // 빈 객체가 아니라 null 로 지운다 — setLocal 은 깊은 병합이라 `{}` 는 무동작이다.
        expect(mockLocalState.withdrawClaimNumber).toBeNull();
        expect(mockLocalState.withdrawClaimLabel).toBeNull();
        expect(mockLocalState.withdrawError).toBeNull();
        expect(mockG7Core.modal.close).toHaveBeenCalledWith('modal_claim_withdraw');
    });

    /** @effects claim_withdraw_handler_channel_contract */
    it('철회 — 성공하면 모달을 닫고 목록을 다시 부른다', async () => {
        mockLocalState = { withdrawClaimNumber: 'RT20260901-0001' };
        mockG7Core.api.post.mockResolvedValue({ success: true, message: '철회되었습니다.' });

        await withdrawClaimHandler(
            {
                handler: 'withdrawClaim',
                params: { withdrawApiBase: MEMBER_WITHDRAW_BASE, refreshDataSourceId: 'claims' },
            },
            {} as any
        );

        // 회원 철회는 주문 스코프가 아니다 — `/user/orders/{id}/claims/...` 로 보내면 404 다.
        expect(mockG7Core.api.post).toHaveBeenCalledWith(
            '/api/modules/sirsoft-ecommerce/user/claims/RT20260901-0001/withdraw',
            {},
            undefined
        );
        expect(mockLocalState.withdrawClaimNumber).toBeNull();
        expect(mockG7Core.modal.close).toHaveBeenCalledWith('modal_claim_withdraw');
        expect(mockG7Core.toast.success).toHaveBeenCalledWith('철회되었습니다.');
        expect(mockG7Core.dispatch).toHaveBeenCalledWith({
            handler: 'refetchDataSource',
            params: { dataSourceId: 'claims' },
        });
    });

    /** @effects claim_withdraw_handler_channel_contract */
    it('비회원 철회는 주문 스코프 주소를 그대로 쓴다 (셸이 준 앞부분에 번호만 잇는다)', async () => {
        mockLocalState = { withdrawClaimNumber: 'RT20260901-0001' };
        mockG7Core.state.get = vi.fn(() => ({ guestOrderToken: 'guest-token-1' })) as any;
        mockG7Core.api.post.mockResolvedValue({ success: true });

        await withdrawClaimHandler(
            { handler: 'withdrawClaim', params: { withdrawApiBase: GUEST_WITHDRAW_BASE, isGuest: true } },
            {} as any
        );

        expect(mockG7Core.api.post.mock.calls[0][0]).toBe(
            '/api/modules/sirsoft-ecommerce/guest/orders/20260823-0012/claims/RT20260901-0001/withdraw'
        );
    });

    /** @effects claim_withdraw_handler_channel_contract */
    it('철회 — 셸이 주소를 주지 않으면 보내지 않는다 (잘못된 주소로 보내는 것보다 낫다)', async () => {
        mockLocalState = { withdrawClaimNumber: 'RT20260901-0001' };

        await withdrawClaimHandler({ handler: 'withdrawClaim', params: {} }, {} as any);

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
    });

    /** @effects claim_withdraw_handler_channel_contract */
    it('철회 — 대상이 없으면 아무것도 보내지 않는다', async () => {
        mockLocalState = {};

        await withdrawClaimHandler(
            { handler: 'withdrawClaim', params: { withdrawApiBase: MEMBER_WITHDRAW_BASE } },
            {} as any
        );

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
    });

    /** @effects claim_withdraw_handler_channel_contract */
    it('철회 — 진행 중이면 두 번째 클릭은 나가지 않는다 (성공한 철회 뒤에 실패 안내가 뜨지 않는다)', async () => {
        mockLocalState = { withdrawClaimNumber: 'RT20260901-0001', isWithdrawingClaim: true };

        await withdrawClaimHandler(
            { handler: 'withdrawClaim', params: { withdrawApiBase: MEMBER_WITHDRAW_BASE } },
            {} as any
        );

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
    });

    /** @effects claim_withdraw_handler_channel_contract */
    it('철회 거절(409) — 사유를 모달 안에 남기고 토스트는 띄우지 않는다', async () => {
        mockLocalState = { withdrawClaimNumber: 'RT20260901-0001' };
        mockG7Core.api.post.mockRejectedValue({
            response: { status: 409, data: { message: '승인된 신청은 철회할 수 없습니다.' } },
        });

        await withdrawClaimHandler(
            { handler: 'withdrawClaim', params: { withdrawApiBase: MEMBER_WITHDRAW_BASE } },
            {} as any
        );

        expect(mockLocalState.withdrawError).toBe('승인된 신청은 철회할 수 없습니다.');
        expect(mockLocalState.isWithdrawingClaim).toBe(false);
        // 화면에 남는 안내를 토스트로 한 번 더 띄우지 않는다 — 같은 말이 두 곳에서 겹친다.
        expect(mockG7Core.toast.error).not.toHaveBeenCalled();
        // 대상은 남긴다 — 사유를 읽고 닫을 때까지 모달이 서 있어야 한다.
        expect(mockLocalState.withdrawClaimNumber).toBe('RT20260901-0001');
    });

    /** @effects claim_pay_handler_address_and_guards */
    it('서버 장애(500) — 화면에 남길 상태가 아니므로 토스트로만 알린다', async () => {
        mockLocalState = { withdrawClaimNumber: 'RT20260901-0001' };
        mockG7Core.api.post.mockRejectedValue({
            response: { status: 500, data: { message: 'Server Error' } },
        });

        await withdrawClaimHandler(
            { handler: 'withdrawClaim', params: { withdrawApiBase: MEMBER_WITHDRAW_BASE } },
            {} as any
        );

        expect(mockLocalState.withdrawError).toBeNull();
        expect(mockG7Core.toast.error).toHaveBeenCalledWith('Server Error');
    });

    /** @effects claim_withdraw_handler_channel_contract */
    it('비회원 — 셸이 선언한 값으로만 토큰 헤더가 붙는다 (핸들러가 로그인 여부를 판단하지 않는다)', async () => {
        mockLocalState = { withdrawClaimNumber: 'RT20260901-0001' };
        mockG7Core.state.get = vi.fn(() => ({ guestOrderToken: 'guest-token-1' })) as any;
        mockG7Core.api.post.mockResolvedValue({ success: true });

        await withdrawClaimHandler(
            { handler: 'withdrawClaim', params: { withdrawApiBase: MEMBER_WITHDRAW_BASE, isGuest: true } },
            {} as any
        );

        expect(mockG7Core.api.post.mock.calls[0][2]).toEqual({
            headers: { 'X-Guest-Order-Token': 'guest-token-1' },
        });
    });

    // ───────────────────────────────────────────────
    // 미수납 추가결제 (승인 시점 결제)
    // ───────────────────────────────────────────────

    /** @effects claim_pay_handler_address_and_guards */
    it('결제 — 셸이 준 앞부분에 결제번호만 잇는다 (핸들러가 주소를 조립하지 않는다)', async () => {
        mockG7Core.api.post.mockResolvedValue({ success: true, data: { requires_pg_payment: false } });

        await payClaimOutstandingHandler(
            { handler: 'payClaimOutstanding', params: { payApiBase: MEMBER_PAY_BASE, paymentNumber: 'CP20260901-0001' } },
            {} as any
        );

        expect(mockG7Core.api.post.mock.calls[0][0]).toBe(MEMBER_PAY_BASE + '/CP20260901-0001/pay');
    });

    /** @effects claim_pay_handler_address_and_guards */
    it('결제 — 비회원 앞부분도 그대로 쓴다 (주문 스코프가 붙은 모양)', async () => {
        mockG7Core.api.post.mockResolvedValue({ success: true, data: { requires_pg_payment: false } });

        await payClaimOutstandingHandler(
            { handler: 'payClaimOutstanding', params: { payApiBase: GUEST_PAY_BASE, paymentNumber: 'CP20260901-0001', isGuest: true } },
            {} as any
        );

        expect(mockG7Core.api.post.mock.calls[0][0]).toBe(GUEST_PAY_BASE + '/CP20260901-0001/pay');
    });

    /** @effects claim_pay_handler_sends_the_chosen_method */
    it('결제 — 수단을 주지 않으면 빈 본문으로 보낸다 (서버가 결제 대기 건에 확정해 둔 값을 쓴다)', async () => {
        mockG7Core.api.post.mockResolvedValue({ success: true, data: { requires_pg_payment: false } });

        await payClaimOutstandingHandler(
            { handler: 'payClaimOutstanding', params: { payApiBase: MEMBER_PAY_BASE, paymentNumber: 'CP20260901-0001' } },
            {} as any
        );

        expect(mockG7Core.api.post.mock.calls[0][1]).toEqual({});
    });

    /**
     * 화면이 고른 수단을 서버로 넘기는 축.
     *
     * 대기 건의 수단은 접수 때 확정되지만 그 뒤에 낼 수 없게 될 수 있다 — 마일리지 잔액 부족,
     * 운영자가 그 수단을 내림. 그때 화면이 다른 수단을 골라도 핸들러가 실어 보내지 않으면 서버는
     * 여전히 대기 건의 옛 수단으로 판정해 같은 거절을 돌려준다(#599 3차 실측 F-1·F-2).
     * @effects claim_pay_handler_sends_the_chosen_method
     *
     */
    it('결제 — 화면이 고른 수단은 실어 보낸다 (수단을 바꿔 다시 낼 수 있어야 한다)', async () => {
        mockG7Core.api.post.mockResolvedValue({ success: true, data: { requires_pg_payment: false } });

        await payClaimOutstandingHandler(
            {
                handler: 'payClaimOutstanding',
                params: { payApiBase: MEMBER_PAY_BASE, paymentNumber: 'CP20260901-0001', paymentMethod: 'card' },
            },
            {} as any
        );

        expect(mockG7Core.api.post.mock.calls[0][1]).toEqual({ payment_method: 'card' });
    });

    /** @effects claim_pay_handler_address_and_guards */
    it('결제 — 주소나 결제번호가 없으면 보내지 않는다', async () => {
        await payClaimOutstandingHandler(
            { handler: 'payClaimOutstanding', params: { payApiBase: '', paymentNumber: 'CP20260901-0001' } },
            {} as any
        );
        await payClaimOutstandingHandler(
            { handler: 'payClaimOutstanding', params: { payApiBase: MEMBER_PAY_BASE, paymentNumber: '' } },
            {} as any
        );

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
    });

    /** @effects claim_pay_handler_address_and_guards */
    it('결제 — PG 가 필요하면 그 플러그인 핸들러로 파라미터를 넘긴다', async () => {
        mockG7Core.api.post.mockResolvedValue({
            success: true,
            data: {
                requires_pg_payment: true,
                pg_payment_handler: 'example-pg.requestPayment',
                pg_payment_data: { order_number: 'CP20260901-0001', amount: 6000 },
            },
        });

        await payClaimOutstandingHandler(
            { handler: 'payClaimOutstanding', params: { payApiBase: MEMBER_PAY_BASE, paymentNumber: 'CP20260901-0001', refreshDataSourceId: 'claim' } },
            {} as any
        );

        expect(mockG7Core.dispatch).toHaveBeenCalledWith({
            handler: 'example-pg.requestPayment',
            params: { pgPaymentData: { order_number: 'CP20260901-0001', amount: 6000 } },
        });
        // 결제창이 뜨는 동안 버튼이 다시 살아나면 같은 건으로 두 번 진입할 수 있다.
        expect(mockLocalState.isPayingClaim).toBe(true);
        // 결제창으로 넘어갔으므로 상세를 다시 읽지 않는다 — 지금 읽으면 결제 전 값으로 덮인다.
        expect(mockG7Core.dispatch).toHaveBeenCalledTimes(1);
    });

    /** @effects claim_pay_handler_address_and_guards */
    it('결제 — 마일리지 즉시 결제는 그 자리에서 끝나고 상세를 다시 읽는다', async () => {
        mockG7Core.api.post.mockResolvedValue({
            success: true,
            message: '결제가 완료되었습니다.',
            data: { requires_pg_payment: false, pg_payment_handler: null },
        });

        await payClaimOutstandingHandler(
            { handler: 'payClaimOutstanding', params: { payApiBase: MEMBER_PAY_BASE, paymentNumber: 'CP20260901-0001', refreshDataSourceId: 'claim' } },
            {} as any
        );

        expect(mockLocalState.isPayingClaim).toBe(false);
        expect(mockG7Core.toast.success).toHaveBeenCalledWith('결제가 완료되었습니다.');
        expect(mockG7Core.dispatch).toHaveBeenCalledWith({
            handler: 'refetchDataSource',
            params: { dataSourceId: 'claim' },
        });
    });

    /** @effects claim_pay_handler_address_and_guards */
    it('결제 — 더블클릭으로 두 번 나가지 않는다', async () => {
        mockLocalState = { isPayingClaim: true };

        await payClaimOutstandingHandler(
            { handler: 'payClaimOutstanding', params: { payApiBase: MEMBER_PAY_BASE, paymentNumber: 'CP20260901-0001' } },
            {} as any
        );

        expect(mockG7Core.api.post).not.toHaveBeenCalled();
    });

    /** @effects claim_withdraw_handler_channel_contract */
    it('결제 — 4xx 는 화면에 남기고 5xx 는 토스트로 알린다 (철회와 같은 채널 규약)', async () => {
        mockG7Core.api.post.mockRejectedValue({ response: { status: 422, data: { message: '결제할 수 있는 상태가 아닙니다.' } } });

        await payClaimOutstandingHandler(
            { handler: 'payClaimOutstanding', params: { payApiBase: MEMBER_PAY_BASE, paymentNumber: 'CP20260901-0001' } },
            {} as any
        );

        expect(mockLocalState.claimPayError).toBe('결제할 수 있는 상태가 아닙니다.');
        expect(mockG7Core.toast.error).not.toHaveBeenCalled();

        vi.clearAllMocks();
        mockLocalState = {};
        mockG7Core.api.post.mockRejectedValue({ response: { status: 500, data: { message: 'Server Error' } } });

        await payClaimOutstandingHandler(
            { handler: 'payClaimOutstanding', params: { payApiBase: MEMBER_PAY_BASE, paymentNumber: 'CP20260901-0001' } },
            {} as any
        );

        expect(mockLocalState.claimPayError).toBeNull();
        expect(mockG7Core.toast.error).toHaveBeenCalledWith('Server Error');
    });
});
