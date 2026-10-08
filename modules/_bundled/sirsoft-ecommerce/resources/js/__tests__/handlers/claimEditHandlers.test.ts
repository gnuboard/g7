// e2e:allow 수정 진입 핸들러 단위 테스트 — 브라우저 흐름은 Playwright MCP 라이브 재실측으로 덮었다: 승인 건 RF20260922-1155163369 /edit 진입(SPA·직접 입력) → 안내 후 상세·[저장] 0, 승인 전 건은 수정 화면 유지. (#599 3차 실측 T2-D7, 2026-09-23).
/**
 * 클레임 신청서 **수정(edit) 모드** 핸들러 테스트 (#599 S13)
 *
 * @description
 * 수정 화면은 작성 셸을 재사용한다(§5-1-6). 그래서 조용히 어긋나는 축이 작성 쪽과 다르다:
 *
 *  - 수정 라우트는 주소에 **유형도 주문번호도 없다**. 둘 다 신청서가 알려 준다 — 그 해석이
 *    어긋나면 작성 화면의 유형 가드가 수정 진입을 통째로 404 로 만든다.
 *  - 주문 데이터소스의 **첫 호출은 반드시 404** 다(주문번호를 아직 모른다). 그 404 에 오류
 *    화면을 세우면 수정 화면이 열리지 않고, 반대로 무조건 삼키면 작성 진입에서 열 수 없는
 *    주문이 빈 신청서로 열린다.
 *  - 미리보기에 **자기 신청서 번호를 빼놓으면** 자기가 잡아 둔 수량에 자기가 막혀 거절된다 —
 *    재발송지를 바꿔도 배송비가 다시 계산되지 않는다(D14).
 *  - 이미 귀속된 첨부를 그 자리에서 지우면 저장을 취소하고 나가도 첨부만 영구히 사라진다.
 *  - 저장 페이로드에 품목·수량·교환 대상을 실으면 바꾸지 않은 축이 "바뀐 것" 으로 처리된다.
 *
 * @scenario claim_type=exchange, charge_method=pay_on_request, refund_branch=none
 * @effects claim_edit_route_resolves_number_by_position,
 *   claim_edit_order_404_is_a_normal_path,
 *   claim_edit_seeds_items_from_the_claim,
 *   claim_edit_preview_excludes_its_own_lock,
 *   claim_edit_attached_image_removal_defers_to_save,
 *   claim_edit_does_not_resend_attached_images_as_new,
 *   claim_edit_save_sends_only_editable_fields
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import {
    resolveClaimEditNumber,
    isClaimEditRoute,
    claimApplyOrderMissingHandler,
    initClaimEditHandler,
    buildClaimEditItems,
    buildClaimEditImages,
    saveClaimEditHandler,
    estimateClaimHandler,
    resetClaimRedeliveryHandler,
    applyClaimRedeliveryHandler,
} from '../../handlers/claimApplyHandlers';
import { removeClaimImageHandler } from '../../handlers/claimImageHandlers';

let mockLocalState: Record<string, any> = {};

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
        get: vi.fn(() => ({})),
    },
    api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
    dataSource: { get: vi.fn(() => null) },
    toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
    modal: { open: vi.fn(), close: vi.fn() },
    dispatch: vi.fn(),
    t: vi.fn((key: string) => key),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

const MEMBER_CLAIM_BASE = '/api/modules/sirsoft-ecommerce/user/claims';
const MEMBER_ORDER_BASE = '/api/modules/sirsoft-ecommerce/user/orders/1521';

function setPath(pathname: string): void {
    Object.defineProperty(window, 'location', {
        value: { pathname, search: '' },
        writable: true,
        configurable: true,
    });
}

describe('클레임 수정 — 주소 해석', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = {};
        (window as any).G7Core = mockG7Core;
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_route_resolves_number_by_position
     */
    it('회원 수정 주소에서 클레임 번호를 읽는다', () => {
        setPath('/mypage/claims/EX20260901-0001/edit');

        expect(resolveClaimEditNumber()).toBe('EX20260901-0001');
        expect(isClaimEditRoute()).toBe(true);
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_route_resolves_number_by_position
     */
    it('비회원 수정 주소에서도 같은 위치로 읽는다 (상점 기준 경로가 앞에 붙는다)', () => {
        setPath('/shop/guest/claims/RT20260901-0002/edit');

        expect(resolveClaimEditNumber()).toBe('RT20260901-0002');
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_route_resolves_number_by_position
     */
    it('작성 주소는 수정이 아니다', () => {
        setPath('/mypage/orders/20260812-0000123/claims/apply/exchange');

        expect(resolveClaimEditNumber()).toBe('');
        expect(isClaimEditRoute()).toBe(false);
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_route_resolves_number_by_position
     */
    it('상세 주소도 수정이 아니다 (edit 세그먼트가 없다)', () => {
        setPath('/mypage/claims/EX20260901-0001');

        expect(isClaimEditRoute()).toBe(false);
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_route_resolves_number_by_position
     */
    it('claims 가 아닌 곳의 edit 은 클레임 수정이 아니다', () => {
        // 위치로 읽되 소속까지 본다 — 접두사(EX/RT/…)로 가르면 운영자가 채번 설정을 바꾼
        // 상점에서만 조용히 어긋난다.
        setPath('/mypage/profile/edit');

        expect(resolveClaimEditNumber()).toBe('');
    });
});

describe('클레임 수정 — 주문 조회 404 의 처분', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = {};
        (window as any).G7Core = mockG7Core;
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_order_404_is_a_normal_path
     */
    it('작성 진입의 404 는 오류 화면을 세운다 (열 수 없는 주문이다)', async () => {
        setPath('/mypage/orders/20260812-0000123/claims/apply/exchange');

        await claimApplyOrderMissingHandler({ handler: 'claimApplyOrderMissing' }, {} as any);

        expect(mockG7Core.dispatch).toHaveBeenCalledWith(
            expect.objectContaining({ handler: 'showErrorPage' })
        );
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_order_404_is_a_normal_path
     */
    it('수정 진입의 404 는 정상 경로다 (주문번호를 아직 모르는 첫 호출)', async () => {
        setPath('/mypage/claims/EX20260901-0001/edit');

        await claimApplyOrderMissingHandler({ handler: 'claimApplyOrderMissing' }, {} as any);

        expect(mockG7Core.dispatch).not.toHaveBeenCalled();
    });
});

describe('클레임 수정 — 진입', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = {};
        (window as any).G7Core = mockG7Core;
        setPath('/mypage/claims/EX20260901-0001/edit');
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=list
     *
     * @effects claim_edit_shell_sets_api_base_on_the_order_response
     */
    it('신청서를 읽어 주문번호를 세우고 주문 조회를 잇는다', async () => {
        mockG7Core.api.get.mockResolvedValue({
            success: true,
            data: { claim: { claim_number: 'EX20260901-0001', claim_type: 'exchange', order_number: '20260812-0000123' } },
        });

        await initClaimEditHandler(
            { handler: 'initClaimEdit', params: { claimApiBase: MEMBER_CLAIM_BASE, isGuest: false } },
            {} as any
        );

        expect(mockG7Core.api.get).toHaveBeenCalledWith(
            MEMBER_CLAIM_BASE + '/EX20260901-0001',
            undefined
        );
        expect(mockLocalState.claimEditMode).toBe(true);
        expect(mockLocalState.claimEditNumber).toBe('EX20260901-0001');
        expect(mockLocalState.claimEditOrderNumber).toBe('20260812-0000123');
        expect(mockG7Core.dispatch).toHaveBeenCalledWith({
            handler: 'refetchDataSource',
            params: { dataSourceId: 'order' },
        });
    });

    /**
     * 마일리지 우선으로 접수한 신청서는 수정 화면도 마일리지 우선으로 연다 (S1-D13)
     *
     * 세우지 않으면 `_local.claimRefundPriority` 가 기본값 `pg_first` 로 남아, 수정 화면의
     * 읽기 전용 라벨과 미리보기 금액 분해(결제수단/마일리지 줄·잔여 마일리지)가 **저장값과
     * 어긋난다**. 실측(RF20260915-1301303816): 저장은 `PG 4,000 + 마일리지 10,000` 인데
     * 수정 화면이 `PG 우선 환불 / 결제수단 환불 14,000 / 잔여 마일리지 10,000` 으로 떴다.
     *
     * @scenario claim_type=refund, screen=edit, entry=list
     *
     * @effects claim_edit_restores_saved_refund_priority
     */
    it('마일리지 환불액이 있으면 수정 화면이 마일리지 우선으로 열린다', async () => {
        mockG7Core.api.get.mockResolvedValue({
            success: true,
            data: { claim: {
                claim_number: 'EX20260901-0001',
                claim_type: 'refund',
                order_number: '20260812-0000123',
                refund_amount: 4000,
                refund_points_amount: 10000,
            } },
        });

        await initClaimEditHandler(
            { handler: 'initClaimEdit', params: { claimApiBase: MEMBER_CLAIM_BASE, isGuest: false } },
            {} as any
        );

        expect(mockLocalState.claimRefundPriority).toBe('points_first');
    });

    /**
     * 마일리지 환불액이 0 이면 PG 우선으로 연다 (S1-D13)
     *
     * @scenario claim_type=refund, screen=edit, entry=list
     *
     * @effects claim_edit_restores_saved_refund_priority
     */
    it('마일리지 환불액이 없으면 수정 화면이 PG 우선으로 열린다', async () => {
        mockG7Core.api.get.mockResolvedValue({
            success: true,
            data: { claim: {
                claim_number: 'EX20260901-0001',
                claim_type: 'refund',
                order_number: '20260812-0000123',
                refund_amount: 14000,
                refund_points_amount: 0,
            } },
        });

        await initClaimEditHandler(
            { handler: 'initClaimEdit', params: { claimApiBase: MEMBER_CLAIM_BASE, isGuest: false } },
            {} as any
        );

        expect(mockLocalState.claimRefundPriority).toBe('pg_first');
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=list
     *
     * @effects claim_edit_shell_sets_api_base_on_the_order_response
     */
    it('셸이 주소 앞부분을 주지 않으면 보내지 않는다 (핸들러가 조립하면 한쪽이 404 다)', async () => {
        await initClaimEditHandler({ handler: 'initClaimEdit', params: {} }, {} as any);

        expect(mockG7Core.api.get).not.toHaveBeenCalled();
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=list
     *
     * @effects claim_edit_shell_sets_api_base_on_the_order_response
     */
    it('열 수 없는 신청서는 오류 화면으로 끝낸다 (빈 수정 폼을 세우지 않는다)', async () => {
        mockG7Core.api.get.mockRejectedValue({ response: { status: 404 } });

        await initClaimEditHandler(
            { handler: 'initClaimEdit', params: { claimApiBase: MEMBER_CLAIM_BASE } },
            {} as any
        );

        expect(mockG7Core.dispatch).toHaveBeenCalledWith(
            expect.objectContaining({ handler: 'showErrorPage' })
        );
        expect(mockLocalState.claimEditSnapshot).toBeUndefined();
    });

    /**
     * 승인 이후의 신청서는 구매자가 수정 화면을 열지 못한다 (#599 T2-D7 · 운영 결정 2026-09-23)
     *
     * 서버는 승인 이후 수정을 422(`claim_not_editable`)로 막는다. 그런데 주소에 `/edit` 을 붙이면
     * 수정 폼이 그대로 열리고 [저장]도 활성이라, 구매자는 다 고쳐 쓴 뒤에야 거절을 받았다
     * (2026-09-22 실측). 화면이 신청서 응답의 `is_editable` 을 먼저 보고 상세로 돌려보낸다.
     * 관리자 수정은 별도 화면(관리자 클레임 상세의 금액·상태 수정)이라 이 게이트와 무관하다.
     *
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_blocks_a_claim_that_is_no_longer_editable
     */
    it('수정할 수 없는 신청서는 폼을 세우지 않고 안내 후 상세로 돌려보낸다', async () => {
        mockG7Core.api.get.mockResolvedValue({
            success: true,
            data: { claim: { claim_number: 'EX20260901-0001', claim_type: 'exchange', order_number: '20260812-0000123', is_editable: false } },
        });

        await initClaimEditHandler(
            { handler: 'initClaimEdit', params: { claimApiBase: MEMBER_CLAIM_BASE, isGuest: false, detailPath: '/mypage/claims/EX20260901-0001' } },
            {} as any
        );

        expect(mockLocalState.claimEditSnapshot).toBeUndefined();
        expect(mockLocalState.claimEditMode).toBe(false);
        // 안내는 모듈 키(`sirsoft-ecommerce.user.claim_apply.edit_not_editable`)로 찾고, 번역이 없으면(이 목은
        // 키를 그대로 돌려준다) 키 원문이 아니라 폴백 문구를 띄운다(#599 validate 2026-09-23).
        expect(mockG7Core.toast.warning).toHaveBeenCalledWith(expect.stringContaining('승인 이후에는'));
        expect(mockG7Core.dispatch).toHaveBeenCalledWith({
            handler: 'navigate',
            params: { path: '/mypage/claims/EX20260901-0001' },
        });
        expect(mockG7Core.dispatch).not.toHaveBeenCalledWith(expect.objectContaining({ handler: 'refetchDataSource' }));
    });

    /**
     * 승인 전 신청서(`is_editable: true`)는 그대로 수정 화면을 연다 — 위 게이트의 대조군.
     *
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_blocks_a_claim_that_is_no_longer_editable
     */
    it('수정할 수 있는 신청서는 그대로 수정 화면을 연다', async () => {
        mockG7Core.api.get.mockResolvedValue({
            success: true,
            data: { claim: { claim_number: 'EX20260901-0001', claim_type: 'exchange', order_number: '20260812-0000123', is_editable: true } },
        });

        await initClaimEditHandler(
            { handler: 'initClaimEdit', params: { claimApiBase: MEMBER_CLAIM_BASE, isGuest: false, detailPath: '/mypage/claims/EX20260901-0001' } },
            {} as any
        );

        expect(mockLocalState.claimEditSnapshot?.claim_number).toBe('EX20260901-0001');
        expect(mockG7Core.toast.warning).not.toHaveBeenCalled();
        expect(mockG7Core.dispatch).not.toHaveBeenCalledWith(expect.objectContaining({ handler: 'navigate' }));
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=list
     *
     * @effects claim_edit_shell_sets_api_base_on_the_order_response
     */
    it('비회원은 조회 토큰 헤더를 함께 보낸다 (회원 경로는 붙이지 않는다)', async () => {
        // 토큰은 전역 상태에 있다 — 없으면 헤더를 붙일 것이 없으므로 config 자체가 생기지 않고,
        // 그 상태로 비회원 경로를 부르면 서버가 401 로 거절한다.
        mockG7Core.state.get.mockReturnValue({ guestOrderToken: 'tok-123' });
        mockG7Core.api.get.mockResolvedValue({ success: true, data: { claim: { order_number: 'X' } } });

        await initClaimEditHandler(
            { handler: 'initClaimEdit', params: { claimApiBase: MEMBER_CLAIM_BASE, isGuest: true } },
            {} as any
        );

        const config = mockG7Core.api.get.mock.calls[0][1];
        expect(config).toBeDefined();
        expect(config.headers['X-Guest-Order-Token']).toBe('tok-123');

        mockG7Core.state.get.mockReturnValue({});
    });
});

describe('클레임 수정 — 상품·첨부 옮기기', () => {
    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_seeds_items_from_the_claim
     */
    it('상품 줄은 신청한 수량을 그대로 들고, 그 수량이 곧 상한이다', () => {
        const items = buildClaimEditItems(
            {
                claim_type: 'exchange',
                items: [
                    {
                        order_option_id: 359,
                        product_name: '티셔츠',
                        product_option_name: '차콜/M',
                        quantity: 2,
                        unit_price: 29000,
                        unit_price_formatted: '29,000원',
                        is_option_change: true,
                        new_product_option_id: 77,
                        new_option_label: '블랙/L',
                        price_difference: 3000,
                        price_difference_formatted: '3,000원',
                    },
                ],
            },
            'KRW'
        );

        expect(items).toHaveLength(1);
        expect(items[0].order_option_id).toBe(359);
        expect(items[0].quantity).toBe(2);
        // 남은 신청 가능 수량이 아니라 **신청한 수량**이 상한이다 — 수정 화면에서 수량은
        // 바꿀 수 없고, 남은 수량을 상한으로 두면 이미 신청한 만큼이 초과로 읽힌다.
        expect(items[0].available_quantity).toBe(2);
        expect(items[0].locked_quantity).toBe(0);
        expect(items[0].selected).toBe(true);
        expect(items[0].exchange_mode).toBe('change');
        expect(items[0].new_product_option_id).toBe(77);
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_seeds_items_from_the_claim
     */
    it('첨부는 귀속 표식을 달고 옮긴다 (그 표식이 삭제 경로를 가른다)', () => {
        const images = buildClaimEditImages({
            images: [{ id: 9, hash: 'abc', original_name: 'a.jpg', download_url: '/x/a.jpg' }],
        });

        expect(images).toHaveLength(1);
        expect(images[0].attached).toBe(true);
        expect(images[0].id).toBe(9);
    });

    /**
     * @scenario claim_type=return, screen=edit, entry=detail
     *
     * @effects buyer_edit_cannot_remove_an_operator_uploaded_image
     */
    it('운영자가 올린 첨부는 지울 수 없는 줄로 옮긴다 (고객 업로드만 지운다)', () => {
        const images = buildClaimEditImages({
            images: [
                { id: 1, original_name: 'mine.jpg', uploader_type: 'customer' },
                { id: 2, original_name: 'admin.jpg', uploader_type: 'admin', uploader_type_label: '운영자' },
                { id: 3, original_name: 'old.jpg', uploader_type: 'unknown' },
            ],
        });

        expect(images.map((image) => image.removable)).toEqual([true, false, false]);
        expect(images[1].uploader_type_label).toBe('운영자');
    });

    /**
     * @scenario claim_type=return, screen=edit, entry=detail
     *
     * @effects buyer_edit_cannot_remove_an_operator_uploaded_image
     */
    it('지울 수 없는 첨부는 삭제 핸들러가 삭제 목록에 담지 않는다', async () => {
        mockLocalState = {
            claimImages: [{ id: 2, attached: true, removable: false }],
            claimRemoveImageIds: [],
        };

        await removeClaimImageHandler({ params: { imageId: 2, imageDeleteBase: '/api/x' } } as any, {} as any);

        expect(mockLocalState.claimImages).toHaveLength(1);
        expect(mockLocalState.claimRemoveImageIds ?? []).toEqual([]);
    });
});

describe('클레임 수정 — 귀속된 첨부의 삭제', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = {};
        (window as any).G7Core = mockG7Core;
    });

    /**
     * @scenario claim_type=return, screen=edit, entry=detail
     *
     * @effects claim_edit_attached_image_removal_defers_to_save
     */
    it('귀속된 첨부는 지우지 않고 삭제 목록에 담는다 (저장할 때 함께 넘긴다)', async () => {
        mockLocalState = {
            claimImages: [
                { id: 9, attached: true, original_name: 'a.jpg' },
                { id: 10, attached: true, original_name: 'b.jpg' },
            ],
            claimRemoveImageIds: [],
        };

        await removeClaimImageHandler(
            {
                handler: 'removeClaimImage',
                params: { imageId: 9, imageDeleteBase: MEMBER_CLAIM_BASE + '/images' },
            },
            {} as any
        );

        // 파일까지 지우는 삭제 엔드포인트를 부르지 않는다 — 저장을 취소하고 나가도 첨부가
        // 남아 있어야 한다.
        expect(mockG7Core.api.delete).not.toHaveBeenCalled();
        expect(mockLocalState.claimRemoveImageIds).toEqual([9]);
        expect(mockLocalState.claimImages.map((i: any) => i.id)).toEqual([10]);
    });

    /**
     * @scenario claim_type=return, screen=edit, entry=detail
     *
     * @effects claim_edit_attached_image_removal_defers_to_save
     */
    it('아직 귀속되지 않은 첨부는 그 자리에서 지운다 (신청서에 매이지 않은 파일이다)', async () => {
        mockLocalState = { claimImages: [{ id: 11, original_name: 'c.jpg' }] };
        mockG7Core.api.delete.mockResolvedValue({ success: true });

        await removeClaimImageHandler(
            {
                handler: 'removeClaimImage',
                params: { imageId: 11, imageDeleteBase: MEMBER_CLAIM_BASE + '/images' },
            },
            {} as any
        );

        expect(mockG7Core.api.delete).toHaveBeenCalledWith(
            MEMBER_CLAIM_BASE + '/images/11',
            undefined
        );
        expect(mockLocalState.claimRemoveImageIds).toBeUndefined();
    });
});

describe('클레임 수정 — 저장', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = {
            claimEditMode: true,
            claimEditNumber: 'EX20260901-0001',
            claimType: 'exchange',
            claimReason: 'size_mismatch',
            claimReasonDetail: '한 치수 큰 것으로',
            claimImages: [],
            claimImageFiles: [],
            claimRemoveImageIds: [9],
            claimItems: [{ order_option_id: 359, quantity: 2, selected: true }],
        };
        (window as any).G7Core = mockG7Core;
        setPath('/mypage/claims/EX20260901-0001/edit');
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('바꿀 수 있는 것만 보낸다 — 품목·수량·청구 방식은 페이로드에 없다', async () => {
        mockG7Core.api.patch.mockResolvedValue({ success: true, message: 'ok', data: {} });

        await saveClaimEditHandler(
            {
                handler: 'saveClaimEdit',
                params: { claimEditApiBase: MEMBER_CLAIM_BASE, isGuest: false },
            },
            {} as any
        );

        expect(mockG7Core.api.patch).toHaveBeenCalledTimes(1);
        const [url, body] = mockG7Core.api.patch.mock.calls[0];

        expect(url).toBe(MEMBER_CLAIM_BASE + '/EX20260901-0001');
        expect(body.reason_code).toBe('size_mismatch');
        expect(body.reason_detail).toBe('한 치수 큰 것으로');
        expect(body.remove_image_ids).toEqual([9]);
        // 같은 값을 다시 보내면 서버가 그 축을 "바뀐 것" 으로 보고 배송비 재산정·차액 정산이
        // 불필요하게 도는 경로가 생긴다.
        expect(body.items).toBeUndefined();
        expect(body.charge_method).toBeUndefined();
        expect(body.payment_method).toBeUndefined();
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('교환 재발송지는 구매자가 세운 값이 있을 때만, 그리고 수정 표식과 함께 보낸다', async () => {
        mockLocalState.claimRedelivery = {
            recipient_name: '홍길동',
            phone: '010-1234-5678',
            zipcode: '06236',
            address1: '서울시 강남구',
        };
        mockG7Core.api.patch.mockResolvedValue({ success: true, data: {} });

        // 구매자가 [변경] → [적용] 으로 세운 값이다.
        applyClaimRedeliveryHandler({ handler: 'applyClaimRedelivery', params: {} } as any, {} as any);

        await saveClaimEditHandler(
            { handler: 'saveClaimEdit', params: { claimEditApiBase: MEMBER_CLAIM_BASE } },
            {} as any
        );

        const body = mockG7Core.api.patch.mock.calls[0][1];
        // 값이 같아도 바꾼 사실 자체가 운영자에게 남아야 할 기록이다(D14).
        expect(body.redelivery_address.changed_by_user).toBe(true);
        expect(body.redelivery_address.zipcode).toBe('06236');
    });

    /**
     * [원 배송지로 되돌리기] 뒤 저장은 재발송지를 null 로 보낸다 — 키를 빼면 서버는 "손대지
     * 않음"으로 읽어 앞서 바꿔 둔 주소를 그대로 둔다. 화면은 원 배송지를 보여 주는데 신청서에는
     * 바꾼 주소가 남는다(2026-10-06 S2 실측 EX20261006-1447332830).
     *
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('원 배송지로 되돌린 뒤 저장하면 재발송지를 null 로 보낸다', async () => {
        mockLocalState.claimRedelivery = { zipcode: '63000', address1: '제주 서귀포시 1', changed_by_user: true };
        mockG7Core.api.patch.mockResolvedValue({ success: true, data: {} });

        resetClaimRedeliveryHandler({ handler: 'resetClaimRedelivery', params: {} } as any, {} as any);

        await saveClaimEditHandler(
            { handler: 'saveClaimEdit', params: { claimEditApiBase: MEMBER_CLAIM_BASE } },
            {} as any
        );

        const body = mockG7Core.api.patch.mock.calls[0][1];

        expect(Object.prototype.hasOwnProperty.call(body, 'redelivery_address')).toBe(true);
        expect(body.redelivery_address).toBeNull();
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('재발송지에 손대지 않은 저장은 재발송지 키를 싣지 않는다 (되돌림과 구분한다)', async () => {
        // 수정 화면은 신청서에 동결된 재발송지로 열린다(initClaimEditHandler) — 그 값이 있다는
        // 사실만으로 보내면 사유만 고친 저장이 `changed_by_user` 를 true 로 뒤집어 운영자 화면에
        // 「고객 변경」이 뜬다(2026-10-06 S2 실측 EX20261006-1506451469).
        mockLocalState.claimRedelivery = { zipcode: '13529', address1: '경기 성남시', changed_by_user: false };
        mockG7Core.api.patch.mockResolvedValue({ success: true, data: {} });

        await saveClaimEditHandler(
            { handler: 'saveClaimEdit', params: { claimEditApiBase: MEMBER_CLAIM_BASE } },
            {} as any
        );

        expect(Object.prototype.hasOwnProperty.call(mockG7Core.api.patch.mock.calls[0][1], 'redelivery_address')).toBe(false);
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('반품·환불에는 재발송지를 보내지 않는다 (그 유형에는 재발송이 없다)', async () => {
        mockLocalState.claimType = 'return';
        mockLocalState.claimRedelivery = { zipcode: '06236' };
        mockG7Core.api.patch.mockResolvedValue({ success: true, data: {} });

        await saveClaimEditHandler(
            { handler: 'saveClaimEdit', params: { claimEditApiBase: MEMBER_CLAIM_BASE } },
            {} as any
        );

        expect(mockG7Core.api.patch.mock.calls[0][1].redelivery_address).toBeUndefined();
    });

    /**
     * @scenario claim_type=return, screen=edit, entry=detail
     *
     * @effects claim_edit_does_not_resend_attached_images_as_new
     */
    it('이미 귀속된 첨부는 add_image_ids 로 다시 보내지 않는다 (서버가 미귀속 첨부만 받는다)', async () => {
        // 수정 화면의 `claimImages` 는 **이미 신청서에 붙은** 첨부로 채워져 열린다. 그 id 를
        // `add_image_ids` 로 실으면 서버는 그것을 "이 주문의 미귀속 첨부" 로 찾다가 실패해
        // 422 `claim_image_not_found` 를 던지고, 첨부가 한 장이라도 있는 신청서는 사유조차
        // 고칠 수 없게 된다.
        mockLocalState.claimImages = [
            { id: 37, attached: true, original_name: 'a.png' },
            { id: 38, attached: true, original_name: 'b.png' },
        ];
        mockLocalState.claimImageFiles = [];
        mockLocalState.claimRemoveImageIds = [37];
        mockG7Core.api.patch.mockResolvedValue({ success: true, data: {} });

        await saveClaimEditHandler(
            {
                handler: 'saveClaimEdit',
                params: { claimEditApiBase: MEMBER_CLAIM_BASE, claimApiBase: MEMBER_ORDER_BASE, isGuest: false },
            },
            {} as any
        );

        const body = mockG7Core.api.patch.mock.calls[0][1];

        expect(body.add_image_ids).toBeUndefined();
        expect(body.remove_image_ids).toEqual([37]);
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('환불 계좌는 세 값이 다 찼을 때만 실린다 (손대지 않은 저장이 계좌를 지우지 않는다)', async () => {
        // 표시 응답의 계좌는 마스킹된 값이라 화면이 되돌려 보낼 수 없다. 그래서 칸은 빈 채로
        // 열리고, 그 빈 값을 그대로 실으면 저장 한 번이 기록된 계좌를 지운다 — 지워진 사실은
        // 환불을 실행할 때가 되어서야 드러난다.
        mockLocalState.claimType = 'return';
        mockG7Core.api.patch.mockResolvedValue({ success: true, data: {} });

        await saveClaimEditHandler(
            { handler: 'saveClaimEdit', params: { claimEditApiBase: MEMBER_CLAIM_BASE } },
            {} as any
        );

        expect(mockG7Core.api.patch.mock.calls[0][1].refund_bank).toBeUndefined();
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('구매자가 채운 환불 계좌는 접수와 같은 모양으로 실린다', async () => {
        mockLocalState.claimType = 'return';
        mockLocalState.claimRefundBankCode = '004';
        mockLocalState.claimRefundBankAccount = '11122233344';
        mockLocalState.claimRefundBankHolder = '홍길동';
        mockG7Core.api.patch.mockResolvedValue({ success: true, data: {} });

        await saveClaimEditHandler(
            { handler: 'saveClaimEdit', params: { claimEditApiBase: MEMBER_CLAIM_BASE } },
            {} as any
        );

        expect(mockG7Core.api.patch.mock.calls[0][1].refund_bank).toEqual({
            bank_code: '004',
            account_number: '11122233344',
            holder: '홍길동',
        });
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('일부만 채운 계좌도 그대로 보낸다 (조용히 버리면 구매자는 바뀐 줄 안다)', async () => {
        mockLocalState.claimType = 'return';
        mockLocalState.claimRefundBankAccount = '11122233344';
        mockG7Core.api.patch.mockResolvedValue({ success: true, data: {} });

        await saveClaimEditHandler(
            { handler: 'saveClaimEdit', params: { claimEditApiBase: MEMBER_CLAIM_BASE } },
            {} as any
        );

        // 서버가 422 로 거절하고 그 사유가 화면에 남는다 — 화면이 먼저 버리면 저장은 성공하고
        // 계좌만 그대로다.
        expect(mockG7Core.api.patch.mock.calls[0][1].refund_bank).toEqual({
            bank_code: '',
            account_number: '11122233344',
            holder: '',
        });
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('더블클릭으로 두 번 나가지 않는다', async () => {
        mockLocalState.claimEditSaving = true;

        await saveClaimEditHandler(
            { handler: 'saveClaimEdit', params: { claimEditApiBase: MEMBER_CLAIM_BASE } },
            {} as any
        );

        expect(mockG7Core.api.patch).not.toHaveBeenCalled();
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('셸이 주소 앞부분을 주지 않으면 보내지 않는다', async () => {
        await saveClaimEditHandler({ handler: 'saveClaimEdit', params: {} }, {} as any);

        expect(mockG7Core.api.patch).not.toHaveBeenCalled();
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('승인 뒤 거절(4xx)은 화면에 남기고 토스트로 띄우지 않는다 (§6-3 P13)', async () => {
        mockG7Core.api.patch.mockRejectedValue({
            response: { status: 409, data: { message: '승인된 신청은 수정할 수 없습니다.' } },
        });

        await saveClaimEditHandler(
            { handler: 'saveClaimEdit', params: { claimEditApiBase: MEMBER_CLAIM_BASE } },
            {} as any
        );

        expect(mockLocalState.claimEditError).toBe('승인된 신청은 수정할 수 없습니다.');
        expect(mockLocalState.claimEditSaving).toBe(false);
        expect(mockG7Core.toast.error).not.toHaveBeenCalled();
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('서버 장애(5xx)는 토스트로만 알린다 (구매자가 고칠 것이 없다)', async () => {
        mockG7Core.api.patch.mockRejectedValue({
            response: { status: 500, data: { message: 'server error' } },
        });

        await saveClaimEditHandler(
            { handler: 'saveClaimEdit', params: { claimEditApiBase: MEMBER_CLAIM_BASE } },
            {} as any
        );

        expect(mockLocalState.claimEditError).toBeNull();
        expect(mockG7Core.toast.error).toHaveBeenCalled();
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('배송비 차액이 결제를 요구하면 서버가 지목한 결제 진입을 그대로 부른다', async () => {
        mockG7Core.api.patch.mockResolvedValue({
            success: true,
            data: {
                requires_pg_payment: true,
                pg_payment_handler: 'example-pg.requestPayment',
                pg_payment_data: { order_number: 'CP20260901-0001' },
            },
        });

        await saveClaimEditHandler(
            {
                handler: 'saveClaimEdit',
                params: { claimEditApiBase: MEMBER_CLAIM_BASE, successPath: '/mypage/claims/EX20260901-0001' },
            },
            {} as any
        );

        // 특정 PG 를 화면이 지목하지 않는다 — 서버가 준 핸들러 이름을 그대로 dispatch 한다.
        expect(mockG7Core.dispatch).toHaveBeenCalledWith({
            handler: 'example-pg.requestPayment',
            params: { pgPaymentData: { order_number: 'CP20260901-0001' } },
        });
        // 결제창으로 가므로 목적지 이동은 하지 않는다 — 두 번 이동하면 결제창이 닫힌다.
        expect(mockG7Core.dispatch).not.toHaveBeenCalledWith(
            expect.objectContaining({ handler: 'navigate' })
        );
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('차액이 없으면 저장 뒤 그 신청서 상세로 돌아간다', async () => {
        mockG7Core.api.patch.mockResolvedValue({ success: true, data: {} });

        await saveClaimEditHandler(
            {
                handler: 'saveClaimEdit',
                params: { claimEditApiBase: MEMBER_CLAIM_BASE, successPath: '/mypage/claims/EX20260901-0001' },
            },
            {} as any
        );

        expect(mockG7Core.dispatch).toHaveBeenCalledWith({
            handler: 'navigate',
            params: { path: '/mypage/claims/EX20260901-0001' },
        });
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_save_sends_only_editable_fields
     */
    it('사유를 지운 채로는 저장하지 않는다 (서버가 거절할 것을 미리 막는다)', async () => {
        mockLocalState.claimReason = '';

        await saveClaimEditHandler(
            { handler: 'saveClaimEdit', params: { claimEditApiBase: MEMBER_CLAIM_BASE } },
            {} as any
        );

        expect(mockG7Core.api.patch).not.toHaveBeenCalled();
        expect(mockG7Core.toast.warning).toHaveBeenCalled();
    });
});

describe('클레임 수정 — 미리보기 (D14 재발송지 변경)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockLocalState = {
            claimEditMode: true,
            claimEditNumber: 'EX20260901-0001',
            claimEditOriginalCharge: 9000,
            claimType: 'exchange',
            claimReason: 'size_mismatch',
            claimItems: [{ order_option_id: 359, quantity: 1, selected: true, exchange_mode: 'same' }],
            claimRedelivery: { zipcode: '63000', country_code: 'KR' },
        };
        (window as any).G7Core = mockG7Core;
        setPath('/mypage/claims/EX20260901-0001/edit');
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_preview_excludes_its_own_lock
     */
    it('자기 신청서 번호를 실어 보낸다 (자기가 잡아 둔 수량에 자기가 막히지 않게)', async () => {
        mockG7Core.api.post.mockResolvedValue({ data: { total_charge_amount: 12000, items: [] } });

        await estimateClaimHandler(
            { handler: 'estimateClaim', params: { claimApiBase: '/api/x', isGuest: false } },
            {} as any
        );

        expect(mockG7Core.api.post).toHaveBeenCalledTimes(1);
        const body = mockG7Core.api.post.mock.calls[0][1];

        expect(body.claim_number).toBe('EX20260901-0001');
        // 우편번호가 바뀌면 도서산간이 다시 판정되므로 주소도 함께 보낸다.
        expect(body.redelivery_address.zipcode).toBe('63000');
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_preview_excludes_its_own_lock
     */
    it('접수 시점 청구액과의 차액을 세운다 (얼마가 더 청구되는지 화면이 말한다)', async () => {
        mockG7Core.api.post.mockResolvedValue({ data: { total_charge_amount: 12000, items: [] } });

        await estimateClaimHandler(
            { handler: 'estimateClaim', params: { claimApiBase: '/api/x' } },
            {} as any
        );

        expect(mockLocalState.claimEditChargeDelta).toBe(3000);
        expect(mockLocalState.claimEditChargeDeltaFormatted).not.toBe('');
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_preview_excludes_its_own_lock
     */
    it('배송비가 그대로면 차액 줄을 세우지 않는다 (0원 안내는 안내가 아니다)', async () => {
        mockG7Core.api.post.mockResolvedValue({ data: { total_charge_amount: 9000, items: [] } });

        await estimateClaimHandler(
            { handler: 'estimateClaim', params: { claimApiBase: '/api/x' } },
            {} as any
        );

        expect(mockLocalState.claimEditChargeDelta).toBe(0);
        expect(mockLocalState.claimEditChargeDeltaFormatted).toBe('');
    });

    /**
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_preview_excludes_its_own_lock
     */
    it('작성 화면은 자기 번호를 싣지 않는다 (제외할 신청서가 없다)', async () => {
        mockLocalState.claimEditMode = false;
        mockLocalState.claimEditNumber = undefined;
        mockG7Core.api.post.mockResolvedValue({ data: { total_charge_amount: 9000, items: [] } });

        await estimateClaimHandler(
            { handler: 'estimateClaim', params: { claimApiBase: '/api/x' } },
            {} as any
        );

        expect(mockG7Core.api.post.mock.calls[0][1].claim_number).toBeUndefined();
    });
});

describe('클레임 수정 — 단가의 보조 통화 (S3-D6 회귀)', () => {
    /**
     * 수정 화면의 단가 줄 아래 보조 통화는 **단가**에서 와야 한다.
     *
     * 예전에는 매퍼가 `mc_price_difference`(교환 차액)를 `mc_unit_price` 자리에 실었다.
     * 이름은 단가인데 값이 차액이라 서식은 정상이고 금액만 틀려, 화면에
     * `28,000원` 줄에 `$-3.00` 이 붙어도 오류가 뜨지 않았다(실측 EX20260915-0853360266).
     *
     * @scenario claim_type=exchange, screen=edit, entry=detail
     *
     * @effects claim_edit_unit_price_secondary_currency_is_its_own
     */
    it('단가의 보조 통화는 차액이 아니라 단가에서 온다', () => {
        const claim = {
            items: [
                {
                    id: 1,
                    order_option_id: 10,
                    quantity: 1,
                    unit_price: 28000,
                    unit_price_formatted: '28,000원',
                    price_difference: -3000,
                    mc_price_difference: {
                        USD: { amount: -3, formatted: '$-3.00' },
                        JPY: { amount: -345, formatted: '¥-345' },
                    },
                    mc_unit_price: {
                        USD: { amount: 28, formatted: '$28.00' },
                        JPY: { amount: 3220, formatted: '¥3,220' },
                    },
                },
            ],
        };

        const [item] = buildClaimEditItems(claim as any, 'KRW');

        expect(item.mc_unit_price).toEqual([
            { code: 'USD', formatted: '$28.00' },
            { code: 'JPY', formatted: '¥3,220' },
        ]);

        // 차액은 별개 축이다 — 단가 자리에 섞이면 안 된다.
        expect(item.mc_unit_price.some((entry) => entry.formatted.includes('-'))).toBe(false);
        expect(item.price_difference).toBe(-3000);
    });

    /**
     * 서버가 단가의 보조 통화를 내려주지 않으면 그 줄은 **빈 채로** 남아야 한다.
     *
     * 비어 있을 때 옆 필드로 대신 채우는 것이 바로 이 결함의 시작이었다 — 화면은
     * 값이 있으니 정상으로 보이고, 그 값이 다른 금액이라는 사실만 드러나지 않는다.
     *
     * @scenario claim_type=exchange, screen=edit, entry=list
     *
     * @effects claim_edit_unit_price_secondary_currency_is_its_own
     */
    it('단가의 보조 통화가 없으면 빈 배열이다 (다른 필드로 흘리지 않는다)', () => {
        const claim = {
            items: [
                {
                    id: 1,
                    order_option_id: 10,
                    quantity: 1,
                    unit_price: 28000,
                    price_difference: -3000,
                    mc_price_difference: {
                        USD: { amount: -3, formatted: '$-3.00' },
                    },
                },
            ],
        };

        const [item] = buildClaimEditItems(claim as any, 'KRW');

        expect(item.mc_unit_price).toEqual([]);
    });
});
