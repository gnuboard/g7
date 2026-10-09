/**
 * [환불하기] 실패 안내는 서버가 준 실제 사유를 보인다 (S3-D11, 2026-10-07 실측)
 *
 * @description
 * 환불 실행이 결제대행사에서 거절되면 서버는 일반 문구 `message`(「환불 실행에 실패했습니다.」)와 함께 실제 사유를
 * `errors.detail` 에 싣는다(예: 「입금이 끝난 가상계좌 결제는 NHN KCP 로 자동 환불할 수 없습니다. 환불 수단을 계좌
 * 환불로 바꿔 고객 계좌로 직접 송금해 주세요.」). 화면은 `message` 만 읽어, 운영자는 무엇을 해야 하는지 모른 채
 * 일반 문구만 봤다(실측: 입금 완료 KCP 가상계좌 신청서 RF20261007-0209323406). 같은 저장소의 주문 취소·입금확인
 * 핸들러는 이미 `errors.detail` 을 먼저 읽는다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

import { executeClaimRefundHandler } from '../../handlers/claimHandlers';

let state: Record<string, any> = {};
const toast = { success: vi.fn(), warning: vi.fn(), error: vi.fn(), info: vi.fn() };
const post = vi.fn();

beforeEach(() => {
    vi.clearAllMocks();
    state = {
        claimRefundExecuteContext: { claimId: 60, claimNumber: 'RF1', refundAmount: 12500, refundPointsAmount: 0, refundMethod: 'pg' },
    };
    (window as any).G7Core = {
        state: { get: () => state, set: vi.fn((u: any) => { state = { ...state, ...u }; }), setLocal: vi.fn(), getLocal: () => ({}) },
        toast,
        modal: { open: vi.fn(), close: vi.fn() },
        t: vi.fn(() => undefined),
        api: { post },
        dispatch: vi.fn(),
        createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
    };
});

/**
 * 422 오류 객체를 만든다.
 *
 * @param data 응답 본문
 * @returns axios 형 오류
 */
function failure(data: Record<string, unknown>): any {
    const error: any = new Error('Request failed with status code 422');
    error.isAxiosError = true;
    error.response = { status: 422, data };

    return error;
}

describe('환불 실행 실패 안내', () => {
    /**
     * @scenario response=with_detail
     *
     * @effects refund_execute_failure_shows_server_detail
     */
    it('errors.detail 의 사유를 띄운다', async () => {
        const detail = 'PG 환불 처리에 실패했습니다. (입금이 끝난 가상계좌 결제는 NHN KCP 로 자동 환불할 수 없습니다. 환불 수단을 계좌 환불로 바꿔 고객 계좌로 직접 송금해 주세요.)';
        post.mockRejectedValueOnce(failure({ success: false, message: '환불 실행에 실패했습니다.', errors: { detail } }));

        await executeClaimRefundHandler({ handler: 'x' } as any).catch(() => {});

        expect(toast.error).toHaveBeenCalledTimes(1);
        expect(String(toast.error.mock.calls[0][0])).toContain('계좌 환불로 바꿔');
    });

    /**
     * @scenario response=message_only
     *
     * @effects refund_execute_failure_falls_back_to_message
     */
    it('사유가 없으면 응답 message 를 띄운다', async () => {
        post.mockRejectedValueOnce(failure({ success: false, message: '취소 가능 금액을 초과합니다.' }));

        await executeClaimRefundHandler({ handler: 'x' } as any).catch(() => {});

        expect(toast.error).toHaveBeenCalledWith('취소 가능 금액을 초과합니다.');
    });
});
