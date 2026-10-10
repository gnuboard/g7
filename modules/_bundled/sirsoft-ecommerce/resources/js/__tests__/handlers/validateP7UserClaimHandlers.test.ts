/**
 * #599 validate P7 — 사용자 클레임 진입·작성 핸들러 결함 회귀 테스트
 *
 * @description
 * 브랜치 검증(2026-09-23)이 사용자 화면에서 찾은 핸들러 결함을 잠근다.
 *  - 주문상세 전체선택이 `claimable` 판정을 무시해 잠긴 칸까지 골랐다 → 신청 버튼이 전부 꺼졌다.
 *  - 수정 화면의 교환 차액이 서버의 부호 붙은 표기를 그대로 받아 화면이 `-` 를 한 번 더 붙였다(`--5,000원`).
 *  - 수정 화면의 환불 우선순위를 금액 분해로만 역산해 PG 우선 신청서가 마일리지 우선으로 뒤집혔다.
 *  - 제출이 첨부 업로드를 기다린 **앞** 의 로컬 스냅샷으로 본문을 만들었다.
 *  - 안내 문구가 템플릿 키 + 죽은 한국어 폴백(`G7Core.t?.() ?? '…'`)이라 다른 템플릿에서는 키 원문이 떴다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';

const uploadMock = vi.hoisted(() => ({ impl: null as null | ((G7Core: any) => Promise<number[]>) }));

vi.mock('../../handlers/claimImageHandlers', async (importOriginal) => {
    const original: any = await importOriginal();

    return {
        ...original,
        uploadPendingClaimFiles: vi.fn(async (G7Core: any) => (uploadMock.impl ? uploadMock.impl(G7Core) : [])),
    };
});

import { toggleItemSelectionHandler, toggleSelectAllItemsHandler } from '../../handlers/orderItemSelectionHandlers';
import {
    buildClaimEditItems,
    initClaimEditHandler,
    submitClaimHandler,
    setClaimReasonHandler,
    toggleClaimItemHandler,
} from '../../handlers/claimApplyHandlers';

let mockLocalState: Record<string, any> = {};

const ORDER = {
    data: {
        id: 120,
        options: [
            { id: 310, option_status: 'delivered' },
            { id: 311, option_status: 'delivered' },
            { id: 312, option_status: 'cancelled' },
            { id: 313, option_status: 'delivered' },
        ],
    },
};

/** 313 은 취소되지 않았지만 열린 클레임 유형이 없다(이미 반품 진행 중 등) — 화면은 이 칸을 잠근다. */
const CLAIMABLE_OPTIONS = [
    { option: { id: 310 }, claimable_types: ['exchange', 'return'] },
    { option: { id: 311 }, claimable_types: ['return'] },
    { option: { id: 313 }, claimable_types: [] },
];

const translations: Record<string, string> = {};

const mockG7Core = {
    state: {
        getLocal: () => mockLocalState,
        setLocal: vi.fn((updates: Record<string, any>) => {
            mockLocalState = { ...mockLocalState, ...updates };
        }),
        get: vi.fn(() => ({})),
    },
    api: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
    dataSource: { get: vi.fn((id: string) => (id === 'order' ? ORDER : null)) },
    toast: { success: vi.fn(), error: vi.fn(), warning: vi.fn() },
    modal: { open: vi.fn(), close: vi.fn() },
    dispatch: vi.fn(),
    // 번역이 없으면 G7Core.t 는 키를 그대로 돌려준다(undefined 가 아니다) — 실제 런타임과 같은 계약.
    t: vi.fn((key: string) => translations[key] ?? key),
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

function setPath(pathname: string): void {
    Object.defineProperty(window, 'location', {
        value: { pathname, search: '' },
        writable: true,
        configurable: true,
    });
}

beforeEach(() => {
    vi.clearAllMocks();
    mockLocalState = {};
    uploadMock.impl = null;
    for (const key of Object.keys(translations)) delete translations[key];
    (window as any).G7Core = mockG7Core;
});

describe('주문상세 상품 선택 × claimable', () => {
    /**
     * 전체선택은 화면의 잠금 판정(취소되지 않음 + 열린 클레임 유형 있음)과 같은 술어로 고른다.
     * 잠긴 313 을 함께 고르면 클레임 신청 버튼(고른 상품 전부에 그 유형이 열려 있어야 켜진다)이 전부 꺼진다.
     *
     * @scenario screen=order_detail, selection=select_all
     * @effects order_detail_select_all_follows_claimable
     */
    it('전체선택은 claimable 유형이 열린 상품만 고른다 (잠긴 칸 제외)', () => {
        toggleSelectAllItemsHandler(
            { handler: 'toggleSelectAllItems', params: { claimableOptions: CLAIMABLE_OPTIONS } },
            {} as any
        );

        expect(mockLocalState.selectedItemIds).toEqual([310, 311]);
        expect(mockLocalState.selectAllItems).toBe(true);
    });

    /**
     * 개별 토글의 「전부 골랐다」 판정도 같은 술어다 — 잠긴 칸은 고를 수 없으므로 셈에서 빠져야
     * 고를 수 있는 것을 다 고른 순간 전체선택 표시가 켜진다.
     *
     * @scenario screen=order_detail, selection=single
     * @effects order_detail_select_all_follows_claimable
     */
    it('개별 토글 — 고를 수 있는 상품을 다 고르면 전체선택이 켜진다 (잠긴 칸은 셈에서 뺀다)', () => {
        toggleItemSelectionHandler(
            { handler: 'toggleItemSelection', params: { optionId: 310, claimableOptions: CLAIMABLE_OPTIONS } },
            {} as any
        );
        toggleItemSelectionHandler(
            { handler: 'toggleItemSelection', params: { optionId: 311, claimableOptions: CLAIMABLE_OPTIONS } },
            {} as any
        );

        expect(mockLocalState.selectedItemIds).toEqual([310, 311]);
        expect(mockLocalState.selectAllItems).toBe(true);
    });
});

describe('클레임 수정 화면 — 차액 부호 · 환불 우선순위', () => {
    /**
     * 서버의 `price_difference_formatted` 는 부호를 품는다(`-5,000원`). 화면은 부호를 따로 붙이므로
     * 핸들러는 절대값으로 포맷해 둔다 — 그대로 받으면 `--5,000원` 이 된다.
     *
     * @scenario screen=claim_edit, price_difference=negative
     * @effects claim_edit_price_difference_is_unsigned
     */
    it('수정 화면 상품 줄의 차액 표기는 부호 없는 절대값이다', () => {
        const items = buildClaimEditItems(
            {
                claim_type: 'exchange',
                items: [{ order_option_id: 1, quantity: 1, price_difference: -5000, price_difference_formatted: '-5,000원' }],
            },
            'KRW'
        );

        expect(items[0].price_difference).toBe(-5000);
        expect(items[0].price_difference_formatted).not.toContain('-');
        expect(items[0].price_difference_formatted).toContain('5,000');
    });

    /**
     * 신청서가 저장한 우선순위(`refund_priority`)가 있으면 그 값이 정본이다. 금액 분해로 역산하면
     * PG 잔액이 모자라 마일리지로 넘친 PG 우선 신청서가 「마일리지 우선」 으로 뒤집힌다.
     *
     * @scenario screen=claim_edit, refund_priority=pg_first
     * @effects claim_edit_refund_priority_prefers_saved_value
     */
    it('응답의 refund_priority 가 있으면 금액 분해 역산보다 그 값을 쓴다', async () => {
        setPath('/mypage/claims/RF20260901-0001/edit');
        mockG7Core.api.get.mockResolvedValue({
            success: true,
            data: {
                claim: {
                    claim_number: 'RF20260901-0001',
                    claim_type: 'refund',
                    order_number: 'ORD-1',
                    refund_priority: 'pg_first',
                    refund_points_amount: 3000,
                },
            },
        });

        await initClaimEditHandler(
            { handler: 'initClaimEdit', params: { claimApiBase: '/api/modules/sirsoft-ecommerce/user/claims' } },
            {} as any
        );

        expect(mockLocalState.claimRefundPriority).toBe('pg_first');
    });

    /**
     * 필드가 없는 응답(구버전 서버)은 종전처럼 금액 분해로 역산한다.
     *
     * @scenario screen=claim_edit, refund_priority=missing
     * @effects claim_edit_refund_priority_prefers_saved_value
     */
    it('refund_priority 가 없으면 마일리지 환불액으로 역산한다 (종전 경로)', async () => {
        setPath('/mypage/claims/RF20260901-0002/edit');
        mockG7Core.api.get.mockResolvedValue({
            success: true,
            data: { claim: { claim_number: 'RF20260901-0002', claim_type: 'refund', order_number: 'ORD-2', refund_points_amount: 3000 } },
        });

        await initClaimEditHandler(
            { handler: 'initClaimEdit', params: { claimApiBase: '/api/modules/sirsoft-ecommerce/user/claims' } },
            {} as any
        );

        expect(mockLocalState.claimRefundPriority).toBe('points_first');
    });
});

describe('클레임 제출 — 업로드 대기 뒤 최신 상태', () => {
    /**
     * 첨부 업로드를 기다리는 동안 바뀐 로컬 값(예: 상세 사유 입력)이 본문에 실려야 한다. 대기 **앞**
     * 스냅샷으로 본문을 만들면 화면에 보이는 값과 제출된 값이 갈린다.
     *
     * @scenario apply_handler=submit, upload=pending
     * @effects claim_apply_submit_reads_latest_local_after_upload
     */
    it('업로드를 기다린 뒤의 로컬 상태로 본문을 만든다', async () => {
        mockLocalState = {
            claimType: 'return',
            claimReason: 'defect',
            claimReasonDetail: 'before',
            claimItems: [
                { order_option_id: 310, quantity: 1, selected: true, exchange_mode: 'same', new_product_option_id: null, custom_options: null },
            ],
        };
        uploadMock.impl = async (G7Core: any) => {
            G7Core.state.setLocal({ claimReasonDetail: 'after upload' });

            return [77];
        };
        mockG7Core.api.post.mockResolvedValue({ success: true, data: {} });

        await submitClaimHandler(
            { handler: 'submitClaim', params: { claimApiBase: '/api/x', successPath: '/mypage/claims' } },
            {} as any
        );

        expect(mockG7Core.api.post).toHaveBeenCalledWith(
            '/api/x/returns',
            expect.objectContaining({ reason_detail: 'after upload', image_ids: [77] }),
            undefined
        );
    });
});

describe('클레임 작성 안내 문구 — 모듈 키 + 실제로 도는 폴백', () => {
    /**
     * 모듈 핸들러는 어느 템플릿에서든 돈다 — 템플릿 키(`claim.*`)에 기대면 그 키가 없는 템플릿에서
     * 원문 키가 뜬다. 모듈 키를 먼저 찾는다.
     *
     * @scenario apply_handler=submit, locale=translated
     * @effects claim_apply_messages_use_module_keys
     */
    it('사유 없이 제출하면 모듈 키 번역을 띄운다', async () => {
        translations['sirsoft-ecommerce.user.claim_apply.reason_required'] = 'MODULE: reason required';
        mockLocalState = {
            claimType: 'return',
            claimReason: '',
            claimItems: [{ order_option_id: 310, quantity: 1, selected: true, exchange_mode: 'same' }],
        };

        await submitClaimHandler({ handler: 'submitClaim', params: { claimApiBase: '/api/x' } }, {} as any);

        expect(mockG7Core.toast.warning).toHaveBeenCalledWith('MODULE: reason required');
    });

    /**
     * `G7Core.t` 는 번역이 없으면 키를 돌려준다 — `?? '한국어'` 폴백은 한 번도 돌지 않는다.
     * 키가 그대로 돌아오면 폴백 문구로 떨어져야 한다.
     *
     * @scenario apply_handler=toggle, locale=missing
     * @effects claim_apply_messages_use_module_keys
     */
    it('번역이 없으면 키 원문이 아니라 폴백 문구를 띄운다', () => {
        mockLocalState = {
            claimType: 'refund',
            claimItems: [
                { order_option_id: 1, selected: true, refund_branch: 'auto' },
                { order_option_id: 2, selected: false, refund_branch: 'request' },
            ],
        };

        toggleClaimItemHandler({ handler: 'toggleClaimItem', params: { index: 1 } }, {} as any);

        const shown = mockG7Core.toast.warning.mock.calls[0]?.[0];

        expect(shown).toBeTruthy();
        expect(shown).not.toMatch(/^[a-z_.-]+$/);
    });

    /**
     * 안내 문구 헬퍼가 다른 핸들러의 동작(사유 선택 → 미리보기 예약)을 바꾸지 않는다.
     *
     * @scenario apply_handler=reason, locale=missing
     * @effects claim_apply_messages_use_module_keys
     */
    it('사유 선택은 종전처럼 사유와 오류 초기화만 한다', () => {
        mockLocalState = { claimType: 'return' };

        setClaimReasonHandler({ handler: 'setClaimReason', params: { value: 'defect' } }, {} as any);

        expect(mockLocalState.claimReason).toBe('defect');
        expect(mockLocalState.claimSubmitError).toBeNull();
    });
});
