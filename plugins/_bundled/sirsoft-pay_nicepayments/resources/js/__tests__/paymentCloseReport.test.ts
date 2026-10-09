/**
 * 결제창 닫힘 보고 (나이스페이먼츠)
 *
 * close-report 는 주문을 실패·취소로 바꾸므로 서버가 주문 소유자만 받아 준다(KVE-2026-2434). 회원 Bearer 와 401
 * 처리는 G7Core.api 가 맡고, 비회원은 공개 상태 계약 `_global.guestOrderToken` 을 헤더로 싣는다. 종전에는 G7Core.api 가
 * 없을 때 원시 fetch 로 직접 보내며 저장소 토큰을 손수 실었다(#639).
 *
 * 보고 주소는 이 플러그인의 API 상대 경로다 — client-config 의 close_report_url 은 #704 이후 사이트 절대 주소의 웹 경로
 * (`/plugins/...`)라 그 주소로는 API 라우트에 닿지 않았다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nicepayments/tests/scenarios/public-core-api-only.yaml
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { CLOSE_REPORT_PATH, reportPaymentWindowClosed } from '../paymentCloseReport';

const CONTEXT = { oid: 'ORD-NICE-CLOSE-001', price: 10000, payment_method: 'card' };

/**
 * G7Core 를 심습니다.
 *
 * @param post G7Core.api.post 목
 * @param global 공개 전역 상태
 */
function seedCore(post: ReturnType<typeof vi.fn>, global: Record<string, unknown> = {}): void {
    (window as any).G7Core = { api: { post }, state: { get: () => global } };
}

describe('결제창 닫힘 보고', () => {
    let fetchSpy: ReturnType<typeof vi.spyOn>;

    beforeEach(() => {
        fetchSpy = vi.spyOn(globalThis, 'fetch').mockResolvedValue(new Response('{}'));
        vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    });

    afterEach(() => {
        delete (window as any).G7Core;
        vi.restoreAllMocks();
    });

    // @scenario concern=close_report_member
    // @effects close_report_via_core_api
    it('회원: G7Core.api 로 플러그인 API 상대 경로에 보낸다 — 주문자 연락처는 싣지 않는다', async () => {
        const post = vi.fn().mockResolvedValue({ success: true });
        seedCore(post);

        await reportPaymentWindowClosed(CONTEXT, 'nicepay-window-closed');

        expect(CLOSE_REPORT_PATH).toBe('/plugins/sirsoft-pay_nicepayments/payment/close-report');
        expect(post).toHaveBeenCalledWith(CLOSE_REPORT_PATH, {
            oid: 'ORD-NICE-CLOSE-001',
            price: 10000,
            payment_method: 'card',
            reason: 'nicepay-window-closed',
        });
        expect(fetchSpy).not.toHaveBeenCalled();
    });

    // @scenario concern=close_report_guest
    // @effects close_report_via_core_api, guest_token_read_from_public_state_contract
    it('비회원: 공개 상태 계약의 조회 토큰을 X-Guest-Order-Token 으로 싣는다', async () => {
        const post = vi.fn().mockResolvedValue({ success: true });
        seedCore(post, { guestOrderToken: '1999999999|guest-token' });

        await reportPaymentWindowClosed(CONTEXT);

        expect(post).toHaveBeenCalledWith(
            CLOSE_REPORT_PATH,
            expect.objectContaining({ oid: 'ORD-NICE-CLOSE-001', reason: 'nicepay-window-closed' }),
            { headers: { 'X-Guest-Order-Token': '1999999999|guest-token' } },
        );
    });

    // @scenario concern=close_report_guest
    // @effects close_report_failure_is_swallowed
    it('거부돼도 예외를 흘리지 않는다', async () => {
        seedCore(vi.fn().mockRejectedValue(new Error('403')));

        await expect(reportPaymentWindowClosed(CONTEXT)).resolves.toBeUndefined();
    });

    // @scenario concern=close_report_member
    // @effects close_report_via_core_api
    it('G7Core.api 가 없으면 원시 fetch 로 우회하지 않고 보고를 건너뛴다', async () => {
        await reportPaymentWindowClosed(CONTEXT);

        expect(fetchSpy).not.toHaveBeenCalled();
    });

    // @scenario concern=close_report_member
    // @effects close_report_via_core_api
    it('사유는 160자로 자르고 비면 기본 사유를 쓴다', async () => {
        const post = vi.fn().mockResolvedValue({ success: true });
        seedCore(post);

        await reportPaymentWindowClosed(CONTEXT, `  ${'x'.repeat(200)}  `);
        await reportPaymentWindowClosed(CONTEXT, '   ');

        expect(post.mock.calls[0][1].reason).toHaveLength(160);
        expect(post.mock.calls[1][1].reason).toBe('nicepay-window-closed');
    });
});

/**
 * 닫힘 보고는 서버가 주는 사이트 절대 주소가 아니라 상수 API 경로(CLOSE_REPORT_PATH)로 보낸다.
 *
 * 서버가 주는 닫힘 보고 주소는 사이트 주소 기준 절대 URL 이다(`SiteUrl::absolute`). G7Core.api 는 `/api` 를 앞에 붙이는
 * 경로를 받으므로 절대 URL 을 그대로 넘기면 `/plugins/...`(접두 없음)로 나가 404 가 났고, 결제창을 닫아도 주문이
 * 결제대기로 남았다. 별칭 Host 로 들어온 화면에서는 교차 출처가 되어 Bearer 도 실리지 않는다.
 */
describe('사이트 주소 절대 URL 닫힘 보고', () => {
    afterEach(() => {
        delete (window as any).G7Core;
        vi.restoreAllMocks();
    });

    // @scenario concern=close_report_member
    // @effects close_report_via_core_api
    it('보고 경로는 출처·/api 접두가 없는 플러그인 API 상대 경로다', () => {
        expect(CLOSE_REPORT_PATH).toMatch(/^\/plugins\/sirsoft-pay_nicepayments\/payment\/close-report$/);
        expect(CLOSE_REPORT_PATH).not.toMatch(/^https?:/);
        expect(CLOSE_REPORT_PATH.startsWith('/api/')).toBe(false);
    });

    // @scenario concern=close_report_member
    // @effects close_report_via_core_api
    it('맥락에 서버가 준 사이트 절대 주소가 섞여 와도 G7Core.api 에는 상수 경로로 넘긴다', async () => {
        const post = vi.fn().mockResolvedValue({ success: true });
        seedCore(post);
        const absolute = `${window.location.origin}/plugins/sirsoft-pay_nicepayments/payment/close-report`;

        await reportPaymentWindowClosed({ ...CONTEXT, oid: 'ORD-NICE-ABS-001', closeReportUrl: absolute } as typeof CONTEXT);

        expect(post).toHaveBeenCalledTimes(1);
        expect(post.mock.calls[0][0]).toBe(CLOSE_REPORT_PATH);
        expect(post.mock.calls[0][0]).not.toBe(absolute);
        expect(post.mock.calls[0][1]).toEqual(expect.objectContaining({ oid: 'ORD-NICE-ABS-001' }));
    });
});
