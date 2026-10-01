/**
 * NHN KCP 테스트 모드 모의입금 도구 — 공통 응답 형식(data) 소비와 글자 번역 (#599)
 *
 * 모의입금 정보 API 가 공통 형식({ success, message, data })으로 바뀌어 값이 data 안으로 들어갔다. 최상위를 읽으면
 * available 이 비어 도구가 조용히 사라진다. 도구의 제목·입력 이름·버튼·관리자 안내는 번역 키로 그린다(일본어는 언어팩).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import { installMypageOrderShowInjector } from '../mypageOrderShowInjector';
import { resetOrderScreenPollingForTests } from '../orderScreenPolling';

const EN: Record<string, string> = {
    'sirsoft-pay_nhnkcp.user.mock_title': 'Mock Deposit',
    'sirsoft-pay_nhnkcp.user.mock_trade_no': 'KCP Transaction No.',
    'sirsoft-pay_nhnkcp.user.mock_deposit_no': 'Deposit Account',
    'sirsoft-pay_nhnkcp.user.mock_depositor': 'Depositor Name',
    'sirsoft-pay_nhnkcp.user.mock_notify_url': 'Deposit Notification URL',
    'sirsoft-pay_nhnkcp.user.mock_submit': 'Test Deposit Notification',
    'sirsoft-pay_nhnkcp.user.mock_admin_notice': 'Shown to administrators only',
};

function json(body: unknown): Response {
    return new Response(JSON.stringify(body), { status: 200, headers: { 'Content-Type': 'application/json' } });
}

beforeEach(() => {
    vi.useFakeTimers();
    localStorage.clear();
    sessionStorage.clear();
    localStorage.setItem('auth_token', 'member-token');
    vi.spyOn(console, 'info').mockImplementation(() => {});
    (window as any).G7Core = {
        t: (key: string) => EN[key] ?? key,
        getState: () => ({
            currentDataContext: {
                order: {
                    data: {
                        order_number: 'ORD-MOCK-1',
                        payment: { pg_provider: 'nhnkcp', payment_method: 'vbank', vbank_number: '12345678901234' },
                    },
                },
            },
        }),
    };
    document.body.innerHTML = '<div id="order_payment_info_panel"><div class="space-y-3"></div></div>';
    history.replaceState({}, '', '/mypage/orders/ORD-MOCK-1');
});

afterEach(() => {
    // 주문 화면 한 번 조회 캐시와 폴러는 모듈 상태다 — 같은 주문 번호를 쓰는 다음 테스트가 앞 응답을 받지 않게 비운다
    resetOrderScreenPollingForTests();
    vi.useRealTimers();
    vi.restoreAllMocks();
    delete (window as any).__kcpMpShowInjectorInstalled;
    delete (window as any).G7Core;
    document.body.innerHTML = '';
});

describe('NHN KCP 모의입금 도구', () => {
    /**
     * 응답 data 의 값으로 도구를 그리고, 글자는 번역 키로 그린다.
     *
     * @scenario case=block_available
     * @effects mock_block_reads_envelope_data, mock_block_text_translated
     */
    it('data 의 값으로 도구를 그리고 글자를 화면 언어로 그린다', async () => {
        vi.spyOn(globalThis, 'fetch').mockImplementation(async () => json({
            success: true,
            message: 'ok',
            data: {
                available: true,
                trade_no: 'KCP_TNO_MOCK_001',
                account_no: '12345678901234',
                notify_url: 'https://example.test/plugins/sirsoft-pay_nhnkcp/payment/vbank-notify',
                mock_url: 'https://example.test/mock',
                is_admin_view: true,
            },
        }));

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(2500);

        const block = document.getElementById('kcp-mp-mock-deposit');
        expect(block).not.toBeNull();
        const text = block!.textContent ?? '';
        for (const label of Object.values(EN)) {
            if (label === 'Test Deposit Notification') continue;
            expect(text).toContain(label);
        }
        expect(block!.querySelector('button')!.textContent).toBe('Test Deposit Notification');
        expect((block!.querySelector('input[name="e_trade_no"]') as HTMLInputElement).value).toBe('KCP_TNO_MOCK_001');
        expect(text).not.toMatch(/[가-힣]/);
    });

    /**
     * data.available 이 false 면 도구를 그리지 않는다.
     *
     * @scenario case=block_unavailable
     * @effects mock_block_reads_envelope_data
     */
    it('data.available 이 false 면 도구를 그리지 않는다', async () => {
        vi.spyOn(globalThis, 'fetch').mockImplementation(async () => json({ success: true, message: 'ok', data: { available: false } }));

        installMypageOrderShowInjector();
        await vi.advanceTimersByTimeAsync(2500);

        expect(document.getElementById('kcp-mp-mock-deposit')).toBeNull();
    });
});
