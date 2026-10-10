/**
 * 주문 상세 처리기의 실패 안내는 서버 사유만 띄우고, 사유가 아닌 원문은 번역된 폴백으로 넘긴다 (2026-10-07 실측)
 *
 * @description
 * [입금확인]·상세 일괄변경·관리자 메모 저장은 실패하면 `error.response.data.message || error.message` 를
 * 그대로 띄웠다. 본문 없는 5xx·전송 실패에서 `error.message` 는 axios 원문(「Request failed with status
 * code 500」·「Network Error」)이고, 운영 환경 500 본문은 프레임워크 원문(「Server Error」)이라 운영자가
 * 영문 내부 문구를 읽었다(실측: 입금확인 창 토스트·입금액 칸 오류). 5xx·전송 실패는 사유가 아닌 것으로
 * 보고 번역된 폴백 문구로 넘기며, 4xx 의 서버 사유는 그대로 띄운다(목록 일괄처리 S1-D12 와 같은 기준).
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

import {
    confirmDepositHandler,
    processOrderDetailBulkChangeHandler as processOrderDetailBulkChange,
    saveAdminMemoHandler,
} from '../../handlers/orderDetailHandlers';

const FALLBACK: Record<string, string> = {
    'sirsoft-ecommerce.admin.order.detail.modal.confirm_deposit.confirm_failed': '입금 확인에 실패했습니다.',
    'sirsoft-ecommerce.admin.order.detail.handler.bulk_change_failed': '상태 변경에 실패했습니다.',
    'sirsoft-ecommerce.admin.order.detail.handler.memo_save_failed': '관리자 메모 저장에 실패했습니다.',
};

let toastError: ReturnType<typeof vi.fn>;
let setLocal: ReturnType<typeof vi.fn>;
let patch: ReturnType<typeof vi.fn>;

beforeEach(() => {
    toastError = vi.fn();
    setLocal = vi.fn();
    patch = vi.fn();
    (window as any).G7Core = {
        state: {
            setLocal,
            getLocal: () => ({ form: { admin_memo: '메모' } }),
            set: vi.fn(),
        },
        toast: { error: toastError, success: vi.fn() },
        modal: { close: vi.fn() },
        dispatch: vi.fn(),
        dataSource: { get: () => ({ data: { options: [{ id: 1, quantity: 1 }] } }) },
        api: { patch },
        t: (key: string) => FALLBACK[key] ?? key,
    };
});

/** 본문 없는 500 — axios 원문만 있는 오류 */
const emptyServerError = () => Object.assign(new Error('Request failed with status code 500'), { response: { status: 500, data: '' } });
/** 운영 환경 500 본문 */
const frameworkServerError = () => Object.assign(new Error('Request failed with status code 500'), { response: { status: 500, data: { message: 'Server Error' } } });
/** 응답 없는 전송 실패 */
const networkError = () => Object.assign(new Error('Network Error'), { request: {} });
/** 서버 사유가 실린 422 */
const validationError = (message: string, detail?: string) =>
    Object.assign(new Error('Request failed with status code 422'), { response: { status: 422, data: { success: false, message, errors: detail ? { detail } : undefined } } });

/**
 * 입금확인을 실행한다.
 *
 * @returns void
 */
async function runDeposit(): Promise<void> {
    await confirmDepositHandler(
        { handler: 'sirsoft-ecommerce.confirmDeposit', params: { orderId: 'ORD-1', amount: 12500, depositorName: '홍길동', markOrderComplete: true } } as any,
        {} as any,
    );
}

/**
 * 상세 일괄변경을 실행한다 (실패는 다시 던지므로 삼킨다).
 *
 * @returns void
 */
async function runBulk(): Promise<void> {
    await processOrderDetailBulkChange(
        { handler: 'sirsoft-ecommerce.processOrderDetailBulkChange', params: { orderId: 'ORD-1', selectedProducts: [1], batchOrderStatus: 'preparing' } } as any,
        {} as any,
    ).catch(() => undefined);
}

/**
 * 관리자 메모 저장을 실행한다.
 *
 * @returns void
 */
async function runMemo(): Promise<void> {
    await saveAdminMemoHandler({ handler: 'sirsoft-ecommerce.saveAdminMemo', params: { orderId: 'ORD-1' } } as any, {} as any);
}

describe('입금확인 실패 안내', () => {
    /**
     * @scenario failure=server_5xx
     *
     * @effects order_detail_failure_hides_non_reason_text
     */
    it.each([
        ['본문 없는 500', emptyServerError],
        ['운영 환경 500 본문', frameworkServerError],
        ['전송 실패', networkError],
    ])('%s 는 번역된 폴백을 토스트와 입금액 칸에 띄운다', async (_label, make) => {
        patch.mockRejectedValueOnce(make());

        await runDeposit();

        expect(toastError).toHaveBeenCalledWith('입금 확인에 실패했습니다.');
        expect(setLocal).toHaveBeenLastCalledWith(expect.objectContaining({
            depositConfirmErrors: { amount: ['입금 확인에 실패했습니다.'] },
        }));
    });

    /**
     * @scenario failure=validation_4xx
     *
     * @effects order_detail_failure_shows_server_reason
     */
    it('422 는 진단 문구(detail)를 먼저, 없으면 서버 문장을 띄운다', async () => {
        patch.mockRejectedValueOnce(validationError('입금액이 결제예정금액과 일치하지 않습니다.', '결제 금액이 일치하지 않습니다. (예상: $17.06, 실제: $17.00)'));
        await runDeposit();
        expect(toastError).toHaveBeenLastCalledWith('결제 금액이 일치하지 않습니다. (예상: $17.06, 실제: $17.00)');

        patch.mockRejectedValueOnce(validationError('입금액이 결제예정금액과 일치하지 않습니다.'));
        await runDeposit();
        expect(toastError).toHaveBeenLastCalledWith('입금액이 결제예정금액과 일치하지 않습니다.');
    });
});

describe('상세 일괄변경·관리자 메모 실패 안내', () => {
    /**
     * @scenario failure=server_5xx
     *
     * @effects order_detail_failure_hides_non_reason_text
     */
    it.each([
        ['본문 없는 500', emptyServerError],
        ['운영 환경 500 본문', frameworkServerError],
        ['전송 실패', networkError],
    ])('%s 는 번역된 폴백을 띄운다', async (_label, make) => {
        patch.mockRejectedValueOnce(make());
        await runBulk();
        expect(toastError).toHaveBeenLastCalledWith('상태 변경에 실패했습니다.');

        patch.mockRejectedValueOnce(make());
        await runMemo();
        expect(toastError).toHaveBeenLastCalledWith('관리자 메모 저장에 실패했습니다.');
    });

    /**
     * @scenario failure=validation_4xx
     *
     * @effects order_detail_failure_shows_server_reason
     */
    it('4xx 서버 사유와 성공 플래그 없는 응답의 문장은 그대로 띄운다', async () => {
        patch.mockRejectedValueOnce(validationError('배송완료 주문은 결제대기로 되돌릴 수 없습니다.'));
        await runBulk();
        expect(toastError).toHaveBeenLastCalledWith('배송완료 주문은 결제대기로 되돌릴 수 없습니다.');

        patch.mockResolvedValueOnce({ success: false, message: '메모가 너무 깁니다.' });
        await runMemo();
        expect(toastError).toHaveBeenLastCalledWith('메모가 너무 깁니다.');
    });
});
