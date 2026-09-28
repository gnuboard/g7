/**
 * 나이스페이먼츠 화면 글자·결제 오류 알림은 번역 키로 낸다 (#599)
 *
 * 종전에는 주문 완료 화면 영수증 버튼(「영수증 조회」「로딩 중...」), 결제 실패 알림(「나이스페이먼츠 SDK를 불러올 수
 * 없습니다」「결제 중 오류가 발생했습니다」), 영수증 조각의 간편결제 이름표(「네이버페이」 등)를 한국어로 박아 두어 영어·일본어
 * 화면에서도 한국어로 나왔다(PO 결정 2026-09-28 「모두 번역 키로」). 이제 플러그인 번역 키(ko·en, 일본어는 언어팩)를 쓴다.
 */

import { readFileSync } from 'fs';
import path from 'path';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { requestPaymentHandler } from '../handlers/requestPayment';
import { installOrderCompleteReceiptInjector } from '../orderCompleteReceiptInjector';

const EXT = path.resolve(__dirname, '../../extensions');
const JA: Record<string, string> = {
    'sirsoft-pay_nicepayments.user.receipt_view': '領収書を確認',
    'sirsoft-pay_nicepayments.payment.error.request_failed': '決済中にエラーが発生しました。',
};

function fakeT(key: string): string {
    return JA[key] ?? key;
}

describe('나이스페이먼츠 — 화면 글자·오류 알림 번역 키', () => {
    beforeEach(() => {
        vi.useFakeTimers();
        vi.spyOn(console, 'info').mockImplementation(() => {});
        vi.spyOn(console, 'error').mockImplementation(() => {});
    });

    afterEach(() => {
        vi.useRealTimers();
        vi.restoreAllMocks();
        delete (window as any).G7Core;
        delete (window as any).__nicepayOcReceiptInjectorInstalled;
        document.body.innerHTML = '';
    });

    /**
     * @scenario surface=order_complete_button
     * @effects text_from_translation_key
     */
    it('주문 완료 영수증 버튼 글자를 번역 키로 낸다', async () => {
        (window as any).G7Core = { t: fakeT };
        localStorage.setItem('auth_token', 'member-token');
        history.replaceState({}, '', '/shop/orders/ORD-OC-9/complete');
        document.body.innerHTML = '<div><button type="button" class="bg-blue-600 hover:bg-blue-700">A</button><button type="button">B</button></div>';
        vi.spyOn(globalThis, 'fetch').mockImplementation(async (input) => {
            const body = String(input).endsWith('/receipt')
                ? { success: true, data: { receipt_url: 'https://r.example/1' } }
                : { success: true, data: { payment: { pg_provider: 'nicepayments', transaction_id: 'T-9', paid_at: '2026-09-28 10:00:00' } } };

            return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
        });

        installOrderCompleteReceiptInjector();
        await vi.advanceTimersByTimeAsync(1500);

        expect(document.getElementById('nicepay-oc-receipt-btn')?.textContent).toBe('領収書を確認');
    });

    /**
     * @scenario surface=payment_error_toast
     * @effects text_from_translation_key
     */
    it('결제 요청 실패 알림을 번역 키로 낸다', async () => {
        const toastError = vi.fn();
        (window as any).G7Core = {
            t: fakeT,
            api: { get: vi.fn().mockRejectedValue(new Error('network')) },
            state: { setLocal: vi.fn() },
            toast: { error: toastError },
        };

        await requestPaymentHandler({ params: { pgPaymentData: { order_number: 'O-1', order_name: 'T', amount: 1 }, paymentMethod: 'card' } });

        expect(toastError).toHaveBeenCalledWith('決済中にエラーが発生しました。');
    });

    /**
     * @scenario surface=easy_pay_name
     * @effects text_from_translation_key
     */
    it('영수증 조각의 간편결제 이름은 번역 키로 풀고 한국어 이름표를 두지 않는다', () => {
        for (const name of ['user_mypage_order_receipt', 'user_order_complete_receipt']) {
            for (const ext of ['.g7.html', '.json']) {
                const text = readFileSync(path.join(EXT, name + ext), 'utf8');
                expect(text, name + ext).not.toContain('네이버페이');
                expect(text, name + ext).toContain("sirsoft-pay_nicepayments.user.easy_pay_names.");
            }
        }
    });
});
