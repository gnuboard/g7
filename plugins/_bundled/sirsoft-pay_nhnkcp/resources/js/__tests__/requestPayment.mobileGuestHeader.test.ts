// e2e:allow 모바일 승인키 요청 헤더·오류 문구 — KCP 모바일 결제창은 외부 테스트 서버라 spec 으로 재현하지 않고, Chrome MCP 모바일 에뮬레이션 실측 T2-c 로 승인키 응답까지 확인한다.
/**
 * 비회원 주문의 모바일 결제 승인키 요청 (#883)
 *
 * 승인키 API 는 비회원 주문을 주문 조회 토큰(`X-Guest-Order-Token`)으로 확인한다(결제창 닫힘 보고와 같은 방식).
 * 핸들러는 그 헤더를 실어 보내고, 토큰이 없거나 만료돼 401 이 오면(토큰 없이 보낸 요청이라 코어는 로그인 화면으로
 * 옮기지 않는다) 결제 오류 모달에 「주문 조회에서 다시 결제」 안내를 띄운다.
 *
 * 시나리오: plugins/_bundled/sirsoft-pay_nhnkcp/tests/scenarios/mobile-approval-key.yaml
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { requestPaymentHandler } from '../handlers/requestPayment';

const PG_PAYMENT = {
    order_number: 'ORD-GUEST-1',
    order_name: 'Test Order',
    amount: 10000,
    payment_method: 'card',
};

const APPROVAL_PATH = '/plugins/sirsoft-pay_nhnkcp/mobile/approval-key';

let apiPost: ReturnType<typeof vi.fn>;
let setLocal: ReturnType<typeof vi.fn>;
let modalOpen: ReturnType<typeof vi.fn>;
let isAnonymousUnauthorized: ReturnType<typeof vi.fn>;

beforeEach(() => {
    vi.spyOn(navigator, 'userAgent', 'get').mockReturnValue('Mozilla/5.0 (Linux; Android 14) Mobile Safari/537.36');
    vi.spyOn(console, 'error').mockImplementation(() => {});
    document.documentElement.lang = 'ko';
    apiPost = vi.fn();
    setLocal = vi.fn();
    modalOpen = vi.fn();
    isAnonymousUnauthorized = vi.fn(() => false);
    (window as unknown as Record<string, unknown>).G7Core = {
        api: {
            get: vi.fn().mockResolvedValue({ data: {
                client_id: 'T0000',
                callback_urls: {
                    callback: `${window.location.origin}/plugins/sirsoft-pay_nhnkcp/payment/callback`,
                    close_report: `${window.location.origin}/api/plugins/sirsoft-pay_nhnkcp/payment/close-report`,
                },
            } }),
            post: apiPost,
            isAnonymousUnauthorized,
        },
        state: { get: () => ({ guestOrderToken: 'GUEST-TOKEN' }), setLocal },
        modal: { open: modalOpen },
        toast: { error: vi.fn() },
    };
});

afterEach(() => {
    delete (window as unknown as Record<string, unknown>).G7Core;
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('NHN KCP 모바일 승인키 — 비회원 주문 (#883)', () => {
    // @scenario request=guest_token_valid
    // @effects guest_order_mobile_approval_with_order_token
    it('승인키 요청에 비회원 주문 조회 토큰 헤더를 싣는다', async () => {
        apiPost.mockImplementation(async (url: string) => {
            if (url.includes(APPROVAL_PATH)) {
                // 결제창 이동 전에 흐름을 끊는다 — 헤더만 본다
                throw Object.assign(new Error('stop'), { response: { status: 422, data: { error: 'stop' } } });
            }
            return { success: true };
        });

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        const approvalCall = apiPost.mock.calls.find(([url]) => String(url).includes(APPROVAL_PATH));
        expect(approvalCall).toBeDefined();
        expect(approvalCall![2]).toEqual({ headers: { 'X-Guest-Order-Token': 'GUEST-TOKEN' } });
    });

    // @scenario request=guest_token_mismatch
    // @effects guest_order_mobile_approval_with_order_token
    it('무토큰 401 이면 로그인 이동 없이 「주문 조회에서 다시 결제」 안내를 결제 오류 모달에 띄운다', async () => {
        const unauthorized = Object.assign(new Error('401'), {
            response: { status: 401, data: { message: '인증이 필요합니다.' } },
            g7Auth: { tokenSent: false },
        });
        isAnonymousUnauthorized.mockImplementation((e: unknown) => e === unauthorized);
        apiPost.mockImplementation(async (url: string) => {
            if (url.includes(APPROVAL_PATH)) {
                throw unauthorized;
            }
            return { success: true };
        });

        await requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } });

        expect(setLocal).toHaveBeenCalledWith(expect.objectContaining({
            paymentErrorMessage: '주문 확인 정보가 만료되었습니다. 주문 조회에서 다시 결제해 주세요.',
            isSubmittingOrder: false,
        }));
        expect(modalOpen).toHaveBeenCalledWith('nhnkcp_payment_error_modal');
    });
});

describe('NHN KCP 모바일 승인키 — 비회원 교환/반품 추가결제 (#855)', () => {
    const CLAIM_PAYMENT = {
        order_number: 'CP-20261114-0001',
        order_name: '반품 배송비',
        amount: 3000,
        payment_method: 'card',
        claim_cancel_return_url: '/shop/guest/claims/RT-1',
    };

    // @scenario concern=approval_key
    // @effects kcp_guest_claim_mobile_approval_key_with_order_token
    it('추가결제 승인키 요청에도 비회원 주문 조회 토큰 헤더를 싣고, 주문 전용 재시도 준비는 부르지 않는다', async () => {
        apiPost.mockImplementation(async (url: string) => {
            if (url.includes(APPROVAL_PATH)) {
                throw Object.assign(new Error('stop'), { response: { status: 422, data: { error: 'stop' } } });
            }
            return { success: true };
        });

        // 클레임 결제의 실패는 신청서 핸들러가 받도록 던진다 — 여기서는 요청 헤더만 본다.
        await expect(requestPaymentHandler({ params: { pgPaymentData: CLAIM_PAYMENT } })).rejects.toThrow('stop');

        const approvalCall = apiPost.mock.calls.find(([url]) => String(url).includes(APPROVAL_PATH));
        expect(approvalCall).toBeDefined();
        expect(approvalCall![1]).toEqual(expect.objectContaining({ order_number: 'CP-20261114-0001', amount: 3000 }));
        expect(approvalCall![2]).toEqual({ headers: { 'X-Guest-Order-Token': 'GUEST-TOKEN' } });
        expect(apiPost.mock.calls.some(([url]) => String(url).includes('/payment/retry'))).toBe(false);
    });

    // @scenario concern=approval_key
    // @effects kcp_claim_payment_entry_failure_is_handed_to_the_claim_screen
    it('추가결제 승인키가 무토큰 401 이면 주문서 모달을 열지 않고 「주문 조회에서 다시 결제」 문구를 담아 던진다', async () => {
        const unauthorized = Object.assign(new Error('401'), {
            response: { status: 401, data: { success: false, error: 'Guest order verification failed.' } },
        });
        isAnonymousUnauthorized.mockImplementation((e: unknown) => e === unauthorized);
        apiPost.mockImplementation(async (url: string) => {
            if (url.includes(APPROVAL_PATH)) {
                throw unauthorized;
            }
            return { success: true };
        });

        await expect(requestPaymentHandler({ params: { pgPaymentData: CLAIM_PAYMENT } }))
            .rejects.toThrow('주문 확인 정보가 만료되었습니다. 주문 조회에서 다시 결제해 주세요.');

        // 신청서 화면에는 주문서 전용 모달이 없다 — 열면 안내 없이 「결제 진행 중…」에 멈췄다.
        expect(modalOpen).not.toHaveBeenCalled();
        expect(setLocal).not.toHaveBeenCalledWith(expect.objectContaining({ paymentErrorMessage: expect.anything() }));
    });

    // @scenario concern=approval_key
    // @effects kcp_claim_payment_entry_failure_is_handed_to_the_claim_screen
    it('추가결제 승인키 거절(422)은 서버 문구를 담아 던진다', async () => {
        apiPost.mockImplementation(async (url: string) => {
            if (url.includes(APPROVAL_PATH)) {
                throw Object.assign(new Error('422'), {
                    response: { status: 422, data: { success: false, error: 'Payment amount does not match the claim payment amount.' } },
                });
            }
            return { success: true };
        });

        await expect(requestPaymentHandler({ params: { pgPaymentData: CLAIM_PAYMENT } }))
            .rejects.toThrow('Payment amount does not match the claim payment amount.');
        expect(modalOpen).not.toHaveBeenCalled();
    });

    // @scenario concern=approval_key
    // @effects kcp_claim_payment_entry_failure_is_handed_to_the_claim_screen
    it('추가결제의 결제 설정 조회가 빈 응답이면 승인키를 요청하지 않고 빈 문구로 던진다 (신청서가 대체 문구를 띄운다)', async () => {
        const G7Core = (window as unknown as { G7Core: { api: { get: ReturnType<typeof vi.fn> } } }).G7Core;
        G7Core.api.get.mockResolvedValue({ data: null });

        // 그냥 돌아가면 신청서는 성공으로 읽어 「결제 진행 중…」에 멈춘다.
        await expect(requestPaymentHandler({ params: { pgPaymentData: CLAIM_PAYMENT } })).rejects.toThrow(/^$/);
        expect(apiPost).not.toHaveBeenCalled();
        expect(modalOpen).not.toHaveBeenCalled();
    });

    // @scenario concern=approval_key
    // @effects kcp_claim_payment_entry_failure_is_handed_to_the_claim_screen
    it('주문 결제의 결제 설정 조회가 빈 응답이면 종전대로 던지지 않고 모달도 열지 않는다 (대조군)', async () => {
        const G7Core = (window as unknown as { G7Core: { api: { get: ReturnType<typeof vi.fn> } } }).G7Core;
        G7Core.api.get.mockResolvedValue({ data: null });

        await expect(requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } })).resolves.toBeUndefined();
        expect(apiPost).not.toHaveBeenCalled();
        expect(modalOpen).not.toHaveBeenCalled();
    });

    // @scenario concern=approval_key
    // @effects kcp_claim_payment_entry_failure_is_handed_to_the_claim_screen
    it('주문 결제의 같은 실패는 종전대로 주문서 결제 오류 모달을 연다 (대조군)', async () => {
        apiPost.mockImplementation(async (url: string) => {
            if (url.includes(APPROVAL_PATH)) {
                throw Object.assign(new Error('422'), { response: { status: 422, data: { error: 'Payment amount does not match the order amount.' } } });
            }
            return { success: true };
        });

        await expect(requestPaymentHandler({ params: { pgPaymentData: PG_PAYMENT } })).resolves.toBeUndefined();
        expect(modalOpen).toHaveBeenCalledWith('nhnkcp_payment_error_modal');
    });
});
