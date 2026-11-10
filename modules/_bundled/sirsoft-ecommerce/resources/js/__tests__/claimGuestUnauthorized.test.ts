// e2e:allow 비회원 무토큰 401 안내 — 서버가 비회원 주문 토큰을 거절하는 상황을 단위 대역으로 잠그고, 브라우저에서는 Chrome MCP 실측(주문 조회 토큰 제거 후 신청)으로 이동 0·입력 유지를 확인한다.
/**
 * 비회원 주문 확인 실패(무토큰 401) — 로그인 화면으로 옮기지 않고 「주문 조회를 다시 하라」고 알린다 (#883)
 *
 * 비회원 클레임 신청·첨부·구매확정·배송지 변경은 로그인 토큰 없이 주문 조회 토큰으로 주인을 증명한다. 토큰이 없거나
 * 만료되면 서버가 401 을 주는데, 종전 코어는 그것을 세션 만료로 보고 로그인 화면으로 옮겨 쓰던 신청서가 사라졌다.
 * 이제 코어는 무토큰 401 을 호출자에게 돌려주고(`G7Core.api.isAnonymousUnauthorized`), 핸들러는
 * 「상품·수량을 확인하라」(4xx 화면 안내)보다 먼저 이 판정으로 안내 토스트를 띄우고 입력을 그대로 둔다.
 */
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { initClaimApplyHandler, submitClaimHandler } from '../handlers/claimApplyHandlers';
import { removeClaimImageHandler } from '../handlers/claimImageHandlers';
import { confirmOrderOptionHandler } from '../handlers/userConfirmOrderHandlers';

const CLAIM_MESSAGE = '비회원 주문 확인이 필요합니다. 주문 조회를 다시 한 뒤 신청해 주세요. 입력한 내용은 그대로 있습니다.';
const ORDER_MESSAGE = '비회원 주문 확인이 필요합니다. 주문 조회를 다시 한 뒤 시도해 주세요.';
const GUEST_API_BASE = '/api/modules/sirsoft-ecommerce/guest/orders/20260823-0012';

let local: Record<string, any>;
let dataSources: Record<string, any>;

const anonymous401 = Object.assign(new Error('401'), {
    response: { status: 401, data: { message: '인증이 필요합니다.' } },
    g7Auth: { tokenSent: false },
});

const core = {
    state: {
        getLocal: () => local,
        setLocal: vi.fn((updates: Record<string, any>) => { local = { ...local, ...updates }; }),
        get: vi.fn(() => ({ guestOrderToken: 'GUEST-TOKEN' })),
    },
    dataSource: { get: vi.fn((id: string) => dataSources[id] ?? null) },
    api: {
        post: vi.fn(),
        get: vi.fn(),
        delete: vi.fn(),
        isAnonymousUnauthorized: (error: unknown) => error === anonymous401,
    },
    toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
    modal: { open: vi.fn(), close: vi.fn() },
    dispatch: vi.fn(),
    t: vi.fn((key: string) => ({
        'sirsoft-ecommerce.user.claim.guest_auth_required': CLAIM_MESSAGE,
        'sirsoft-ecommerce.user.guest_order.auth_required': ORDER_MESSAGE,
    } as Record<string, string>)[key] ?? key),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

beforeEach(() => {
    vi.clearAllMocks();
    local = {};
    dataSources = {};
    (window as any).G7Core = core;
    window.history.replaceState({}, '', '/');
});

afterEach(() => {
    delete (window as any).G7Core;
});

describe('비회원 무토큰 401 안내 (#883)', () => {
    /**
     * @scenario case=submit_claim_expired
     * @effects guest_claim_auth_failure_keeps_input_without_redirect
     */
    it('클레임 신청 제출 — 안내 토스트를 띄우고 신청서(선택·사유)를 그대로 둔다', async () => {
        dataSources = {
            claimable: {
                data: {
                    order_id: 120,
                    order_number: '20260823-0012',
                    resolved_type: null,
                    options: [{
                        option: { id: 310, product_id: 5, product_name: '반팔 티셔츠', product_option_name: '화이트 / M', unit_price: 29000, unit_price_formatted: '29,000원', thumbnail_url: '/img/a.jpg' },
                        available_quantity: 2, locked_quantity: 0, claimable_types: ['exchange'], refund_branch: null,
                    }],
                    reasons: { exchange: [{ code: 'size_mismatch', localized_name: '사이즈가 맞지 않음', fault_type: 'customer' }] },
                    settings: { allowed_charge_methods: ['pay_on_request'], charge_payment_methods: ['card'], max_images: 5, image_max_size_kb: 5120 },
                    pending_images: [],
                },
            },
            order: { data: { id: 120, order_number: '20260823-0012', base_currency: 'KRW' } },
        };
        initClaimApplyHandler({ handler: 'initClaimApply', params: { claimType: 'exchange', preselected: '310' } }, {} as any);
        local.claimReason = 'size_mismatch';
        core.api.post.mockRejectedValue(anonymous401);

        await submitClaimHandler(
            { handler: 'submitClaim', params: { claimApiBase: GUEST_API_BASE, isGuest: true, successPath: '/shop/guest/claims' } },
            {} as any,
        );

        expect(core.toast.error).toHaveBeenCalledWith(CLAIM_MESSAGE);
        expect(local.isSubmittingClaim).toBe(false);
        expect(local.claimReason).toBe('size_mismatch');
        expect((local.claimItems ?? []).length).toBeGreaterThan(0);
        // 4xx 화면 안내(「상품·수량을 확인」)로 떨어지지 않는다
        expect(local.claimSubmitError ?? null).toBeNull();
        expect(core.dispatch).not.toHaveBeenCalledWith(expect.objectContaining({ handler: 'navigate' }));
    });

    /**
     * @scenario case=remove_claim_image_expired
     * @effects guest_claim_auth_failure_keeps_input_without_redirect
     */
    it('클레임 첨부 삭제 — 서버 문구 대신 주문 조회 안내를 띄운다', async () => {
        local = { claimImages: [{ id: 7, url: '/x.jpg' }] };
        core.api.delete.mockRejectedValue(anonymous401);

        await removeClaimImageHandler(
            { handler: 'removeClaimImage', params: { imageId: 7, imageDeleteBase: `${GUEST_API_BASE}/claims/images`, isGuest: true } },
            {} as any,
        );

        expect(core.toast.error).toHaveBeenCalledWith(CLAIM_MESSAGE);
        expect(local.claimImages).toEqual([{ id: 7, url: '/x.jpg' }]);
    });

    /**
     * @scenario case=confirm_order_expired
     * @effects guest_order_auth_failure_shows_lookup_guidance
     */
    it('비회원 구매확정 — 주문 조회 안내를 띄운다', async () => {
        dataSources = { order: { data: { order_number: '20260823-0012' } } };
        core.api.post.mockRejectedValue(anonymous401);

        await confirmOrderOptionHandler({ handler: 'confirmOrderOption', params: { orderId: 0, optionId: 2 } } as any, {} as any);

        expect(core.toast.error).toHaveBeenCalledWith(ORDER_MESSAGE);
        expect(local.isConfirming).toBe(false);
    });

    /**
     * @scenario case=confirm_order_other_error
     * @effects guest_order_auth_failure_shows_lookup_guidance
     */
    it('무토큰 401 이 아니면 종전처럼 서버 문구를 띄운다', async () => {
        dataSources = { order: { data: { order_number: '20260823-0012' } } };
        core.api.post.mockRejectedValue({ response: { status: 422, data: { message: '이미 구매확정된 상품입니다.' } } });

        await confirmOrderOptionHandler({ handler: 'confirmOrderOption', params: { orderId: 0, optionId: 2 } } as any, {} as any);

        expect(core.toast.error).toHaveBeenCalledWith('이미 구매확정된 상품입니다.');
    });
});
