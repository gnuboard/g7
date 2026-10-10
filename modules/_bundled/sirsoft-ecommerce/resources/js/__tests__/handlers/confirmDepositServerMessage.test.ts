/**
 * [입금확인] 성공 안내는 서버가 실제 결과에 맞춰 준 문장을 띄운다 (S3-D4, 2026-10-07 실측)
 *
 * @description
 * 서버는 입금확인 결과를 세 문장으로 가른다 — 결제완료 처리 / 입금 내역만 기록(결제대기 유지) /
 * 결제 이후 단계라 입금·결제금액만 기록(상태 유지). 그런데 화면은 응답 문장을 버리고 고정 문구
 * 「입금이 확인되어 처리되었습니다.」만 띄워, 기록만 했거나 상태를 그대로 둔 경우에도 운영자는
 * 그 사실을 알 수 없었다(실측: 상품준비중 주문 입금확인 → 응답 「…주문 상태는 그대로 유지됩니다.」,
 * 화면 토스트 「입금이 확인되어 처리되었습니다.」).
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

import { confirmDepositHandler } from '../../handlers/orderDetailHandlers';

let toastSuccess: ReturnType<typeof vi.fn>;
let patch: ReturnType<typeof vi.fn>;

beforeEach(() => {
    toastSuccess = vi.fn();
    patch = vi.fn();
    (window as any).G7Core = {
        state: { setLocal: vi.fn(), getLocal: () => ({}) },
        toast: { error: vi.fn(), success: toastSuccess },
        modal: { close: vi.fn() },
        dispatch: vi.fn(),
        api: { patch },
        t: () => undefined,
    };
});

/**
 * 입금확인을 실행한다.
 *
 * @param mark 「주문을 결제완료로 처리」 체크 여부
 * @returns void
 */
async function run(mark: boolean): Promise<void> {
    await confirmDepositHandler(
        { handler: 'sirsoft-ecommerce.confirmDeposit', params: { orderId: 'ORD-1', amount: 12500, depositorName: '홍길동', markOrderComplete: mark } } as any,
        {} as any,
    );
}

describe('입금확인 성공 안내', () => {
    /**
     * @scenario response=post_payment_record
     *
     * @effects confirm_deposit_toast_uses_server_message
     */
    it('결제 이후 단계 주문의 기록 — 상태 유지 문장을 그대로 띄운다', async () => {
        const message = '입금 내역과 결제금액을 기록했습니다. 이미 결제 이후 단계인 주문이라 주문 상태는 그대로 유지됩니다.';
        patch.mockResolvedValueOnce({ success: true, message });

        await run(false);

        expect(toastSuccess).toHaveBeenCalledWith(message);
    });

    /**
     * @scenario response=record_only
     *
     * @effects confirm_deposit_toast_uses_server_message
     */
    it('입금 내역만 기록 — 결제대기 유지 문장을 그대로 띄운다', async () => {
        const message = '입금 내역을 기록했습니다. 주문 상태는 결제대기로 유지됩니다.';
        patch.mockResolvedValueOnce({ success: true, message });

        await run(false);

        expect(toastSuccess).toHaveBeenCalledWith(message);
    });

    /**
     * @scenario response=no_message
     *
     * @effects confirm_deposit_toast_uses_server_message
     */
    it('응답에 문장이 없으면 모듈 문구로 떨어진다', async () => {
        patch.mockResolvedValueOnce({ success: true });

        await run(true);

        expect(toastSuccess).toHaveBeenCalledTimes(1);
        expect(String(toastSuccess.mock.calls[0][0])).not.toBe('');
    });
});
