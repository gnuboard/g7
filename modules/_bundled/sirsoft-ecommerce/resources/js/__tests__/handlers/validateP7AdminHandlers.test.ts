/**
 * #599 validate 결함 수정 (P7) — 이커머스 관리자 핸들러 계약
 *
 * 화면에서는 조용히 틀리고 요청 본문·토스트·상태에서만 드러나는 자리를 잠근다:
 *  - 관리자 [주문취소] 의 「환불까지 한 번에 처리」 는 모달을 다시 열 때 꺼진 채로 시작한다
 *  - 취소 성공 뒤 클레임 내역을 다시 읽고, 클레임을 볼 수 없는 운영자는 신청서로 보내지 않는다
 *  - 취소신청서의 상품 단위 전이는 전용 안내를 띄운다
 *  - 완료 처리 모달의 조기 종료는 성공이 아니라 실패다(모달이 닫히지 않는다)
 *  - 환불 합계는 부동소수 오차 없이 넘긴다
 *  - 모듈 핸들러의 문구 키는 모듈 접두사(`sirsoft-ecommerce.`)를 쓰고, 번역이 없으면 폴백한다
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import fs from 'fs';
import path from 'path';

import {
    executeClaimItemBulkStatusHandler,
    executeClaimCompletionHandler,
    openClaimRefundExecuteHandler,
} from '../../handlers/claimHandlers';
import { withdrawClaimHandler, payClaimOutstandingHandler } from '../../handlers/claimListHandlers';
import { setClaimImageFilesHandler } from '../../handlers/claimImageHandlers';
import { executeCancelOrderHandler } from '../../handlers/cancelOrderHandlers';
import { buildOrderDetailBulkConfirmDataHandler } from '../../handlers/orderDetailHandlers';

let globalState: Record<string, any> = {};
let localState: Record<string, any> = {};

const G7Core = {
    state: {
        get: () => globalState,
        set: vi.fn((u: Record<string, any>) => {
            globalState = { ...globalState, ...u };
        }),
        getLocal: () => localState,
        setLocal: vi.fn((u: Record<string, any>) => {
            localState = { ...localState, ...u };
        }),
    },
    toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() },
    modal: { open: vi.fn(), close: vi.fn() },
    // 번역이 없으면 G7Core.t 는 키 문자열을 그대로 돌려준다 — 폴백 경로를 재기 위해 그 동작을 흉내 낸다
    t: vi.fn((key: string) => key),
    api: { post: vi.fn(), patch: vi.fn(), delete: vi.fn() },
    dispatch: vi.fn(),
    dataSource: { get: vi.fn() },
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
};

const ctx = {} as any;

beforeEach(() => {
    globalState = {};
    localState = {};
    vi.clearAllMocks();
    G7Core.t.mockImplementation((key: string) => key);
    (window as any).G7Core = G7Core;
});

/**
 * 모듈 lang(ko/en)과 ja 언어팩에서 점 경로 키를 찾는다(`$partial` 해석 포함).
 *
 * @param locale ko|en|ja
 * @param key sirsoft-ecommerce. 이후 경로
 * @returns 값
 */
function langValue(locale: 'ko' | 'en' | 'ja', key: string): unknown {
    const moduleRoot = path.resolve(__dirname, '../../../..');
    const file = locale === 'ja'
        ? path.resolve(moduleRoot, '../../../lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/ja.json')
        : path.join(moduleRoot, 'resources/lang', `${locale}.json`);
    const resolve = (node: any, dir: string): any => {
        if (node && typeof node === 'object' && !Array.isArray(node)) {
            if (typeof node.$partial === 'string') {
                const target = path.resolve(dir, node.$partial);

                return resolve(JSON.parse(fs.readFileSync(target, 'utf8')), path.dirname(target));
            }
            const out: Record<string, any> = {};
            for (const [k, v] of Object.entries(node)) out[k] = resolve(v, dir);

            return out;
        }

        return node;
    };
    const root = resolve(JSON.parse(fs.readFileSync(file, 'utf8')), path.dirname(file));

    return key.split('.').reduce((acc: any, part) => acc?.[part], root);
}

describe('11-#11 취소신청서의 상품 단위 전이 — 전용 안내', () => {
    /**
     * 「변경할 상품을 선택해주세요」 는 고른 상품이 있는데도 떴다 — 막힌 이유가 선택이 아니다.
     *
     * @scenario surface=claim_detail, defect=cancel_item_transition_wrong_notice
     * @effects cancel_item_transition_uses_dedicated_key
     */
    it('cancel 유형은 cancel_not_transitionable 키로 안내한다', async () => {
        await executeClaimItemBulkStatusHandler({
            handler: 'x',
            params: { pathSegment: 'cancels', claimType: 'cancel', claimId: 3, itemIds: [1], status: 'approved' },
        }, ctx);

        expect(G7Core.t).toHaveBeenCalledWith('sirsoft-ecommerce.admin.claim.detail.items.cancel_not_transitionable', undefined);
        expect(G7Core.t).not.toHaveBeenCalledWith('sirsoft-ecommerce.admin.claim.detail.items.bulk_no_selection', undefined);
        expect(G7Core.api.patch).not.toHaveBeenCalled();
    });

    /**
     * @scenario surface=claim_detail, defect=cancel_item_transition_wrong_notice
     * @effects cancel_item_transition_uses_dedicated_key
     */
    it('전용 키가 ko·en·ja 에 있다', () => {
        for (const locale of ['ko', 'en', 'ja'] as const) {
            expect(typeof langValue(locale, 'admin.claim.detail.items.cancel_not_transitionable'), locale).toBe('string');
        }
    });
});

describe('11-#12 완료 처리 모달 — 조기 종료는 실패로 끝난다', () => {
    /**
     * 조기 return 은 레이아웃의 success 갈래를 태워 모달을 닫았다(오류 토스트와 함께 닫히기도 했다).
     *
     * @scenario surface=claim_complete_modal, defect=early_return_closes_modal
     * @effects claim_completion_early_exit_rejects
     */
    it('미리보기가 도는 중이면 reject 한다', async () => {
        globalState = { claimCompletePreviewLoading: true, claimCompleteContext: { execute: { url: '/x', method: 'POST' } }, claimCompleteRows: [{ id: 1 }] };

        await expect(executeClaimCompletionHandler({ handler: 'x' }, ctx)).rejects.toThrow();
        expect(G7Core.api.post).not.toHaveBeenCalled();
    });

    /**
     * @scenario surface=claim_complete_modal, defect=early_return_closes_modal
     * @effects claim_completion_early_exit_rejects
     */
    it('대상이 없으면 오류를 알리고 reject 한다', async () => {
        globalState = { claimCompleteContext: { execute: { url: '/x', method: 'POST' } }, claimCompleteRows: [] };

        await expect(executeClaimCompletionHandler({ handler: 'x' }, ctx)).rejects.toThrow();
        expect(G7Core.toast.error).toHaveBeenCalled();
        expect(G7Core.api.post).not.toHaveBeenCalled();
    });
});

describe('11-#14 환불 실행 확인 창 — 합계는 부동소수 오차 없이 넘긴다', () => {
    /**
     * @scenario surface=claim_refund_execute_modal, defect=float_refund_total
     * @effects claim_refund_total_rounded
     */
    it('0.1 + 0.2 가 0.3 으로 실린다', async () => {
        await openClaimRefundExecuteHandler({
            handler: 'x',
            params: { claimId: 7, claimNumber: 'RF1', currencyCode: 'USD', refundAmount: '0.1', refundPointsAmount: '0.2', refundMethod: 'pg', refundMethods: [] },
        }, ctx);

        expect(globalState.claimRefundExecuteContext.refundTotal).toBe(0.3);
    });
});

describe('11-#19 모듈 핸들러 문구 — 모듈 접두사 키 + 번역 없을 때 폴백', () => {
    /**
     * 접두사 없는 키는 sirsoft-basic 템플릿 lang 에만 있어 다른 템플릿에서 키 원문이 노출됐다.
     *
     * @scenario surface=user_claim_handlers, defect=template_scoped_keys
     * @effects claim_user_handlers_use_module_keys
     */
    it('철회 성공 안내는 모듈 키를 쓰고, 번역이 없으면 키 원문 대신 폴백 문구를 띄운다', async () => {
        localState = { withdrawClaimNumber: 'EX1' };
        G7Core.api.post.mockResolvedValue({ success: true });

        await withdrawClaimHandler({ handler: 'x', params: { withdrawApiBase: '/api/u/claims' } }, ctx);

        expect(G7Core.t).toHaveBeenCalledWith('sirsoft-ecommerce.user.claim.withdraw.success', undefined);
        const shown = G7Core.toast.success.mock.calls[0][0];
        expect(shown).not.toBe('sirsoft-ecommerce.user.claim.withdraw.success');
        expect(shown).not.toBe('claim.withdraw.success');
    });

    /**
     * @scenario surface=user_claim_handlers, defect=template_scoped_keys
     * @effects claim_user_handlers_use_module_keys
     */
    it('마일리지 즉시 결제 성공 안내도 모듈 키다', async () => {
        G7Core.api.post.mockResolvedValue({ success: true, data: {} });

        await payClaimOutstandingHandler({ handler: 'x', params: { payApiBase: '/api/u/claim-payments', paymentNumber: 'CP1' } }, ctx);

        expect(G7Core.t).toHaveBeenCalledWith('sirsoft-ecommerce.user.claim.pay.success', undefined);
    });

    /**
     * @scenario surface=user_claim_handlers, defect=template_scoped_keys
     * @effects claim_user_handlers_use_module_keys
     */
    it('첨부 용량 초과 안내는 모듈 키이고 번역이 없으면 폴백한다', () => {
        localState = { claimableSnapshot: { settings: { max_images: 5, image_max_size_kb: 1 } } };
        const big = new File([new Uint8Array(4096)], 'a.jpg', { type: 'image/jpeg' });

        setClaimImageFilesHandler({ handler: 'x', params: { files: [big] } }, ctx);

        expect(G7Core.t).toHaveBeenCalledWith('sirsoft-ecommerce.user.claim.image.size_exceeded', { size: 1 });
        expect(G7Core.toast.warning.mock.calls[0][0]).not.toContain('size_exceeded');
    });

    /**
     * @scenario surface=user_claim_handlers, defect=template_scoped_keys
     * @effects claim_user_handlers_use_module_keys
     */
    it('옮긴 키 8개가 ko·en·ja 모듈 lang 에 있다', () => {
        const keys = [
            'user.claim.image.size_exceeded', 'user.claim.image.limit_exceeded', 'user.claim.image.upload_partial_failed',
            'user.claim.image.remove_failed', 'user.claim.withdraw.success', 'user.claim.withdraw.failed',
            'user.claim.pay.success', 'user.claim.pay.failed',
        ];
        for (const locale of ['ko', 'en', 'ja'] as const) {
            for (const key of keys) {
                expect(typeof langValue(locale, key), `${locale} ${key}`).toBe('string');
            }
        }
    });

    /**
     * @scenario surface=user_claim_handlers, defect=template_scoped_keys
     * @effects claim_user_handlers_use_module_keys
     */
    it('두 핸들러 파일에 접두사 없는 t() 키가 남지 않는다', () => {
        for (const name of ['claimListHandlers.ts', 'claimImageHandlers.ts']) {
            const src = fs.readFileSync(path.resolve(__dirname, '../../handlers', name), 'utf8');
            expect(src, name).not.toMatch(/\bt\??\.?\(\s*'claim\./);
        }
    });
});

describe('10-#1 「환불까지 한 번에 처리」 — 모달을 다시 열면 꺼진 채 시작한다', () => {
    /**
     * 한 번 켠 체크가 같은 화면의 다음 취소 모달에 남아 의도치 않은 PG 실환불이 나갈 수 있었다.
     *
     * @scenario surface=order_detail_cancel, defect=auto_advance_sticks
     * @effects order_cancel_auto_advance_resets_on_open
     */
    it('취소 모달을 여는 초기화가 autoAdvanceRefund 를 false 로 되돌린다', () => {
        localState = { autoAdvanceRefund: true };
        G7Core.dataSource.get.mockReturnValue({ data: { order_number: 'O1', options: [{ id: 1, option_status: 'paid', quantity: 1, unit_price: 1000 }] } });

        buildOrderDetailBulkConfirmDataHandler({ handler: 'x', params: { selectedProducts: [1], batchOrderStatus: 'cancelled' } }, ctx);

        expect(G7Core.modal.open).toHaveBeenCalledWith('modal_cancel_order');
        expect(localState.autoAdvanceRefund).toBe(false);
    });
});

describe('10-#1 · #5 · #6 취소 성공 뒤 — 초기화·재조회·권한별 이동', () => {
    /**
     * @scenario surface=order_detail_cancel, defect=auto_advance_sticks
     * @effects order_cancel_auto_advance_resets_after_success
     */
    it('성공 뒤 autoAdvanceRefund 를 끄고 orderClaims 를 다시 읽는다', async () => {
        localState = { autoAdvanceRefund: true, cancelItems: [{ id: 1, cancel_quantity: 1 }] };
        G7Core.api.post.mockResolvedValue({ success: true, data: { claim: { branch: 'auto', claim_number: 'RF1', cancelled: true } } });

        await executeCancelOrderHandler({ handler: 'x', params: { orderId: 'O1', autoAdvanceRefund: true } }, ctx);

        expect(localState.autoAdvanceRefund).toBe(false);
        const refetched = G7Core.dispatch.mock.calls.map((c) => c[0]?.params?.dataSourceId);
        expect(refetched).toContain('orderClaims');
    });

    /**
     * 클레임 권한이 없는 운영자(orderClaims 403 → unavailable)를 신청서 상세로 보내면 403 화면에 떨어진다.
     *
     * @scenario surface=order_detail_cancel, defect=navigate_without_claim_permission
     * @effects order_cancel_navigates_only_with_claim_access
     */
    it('클레임을 볼 수 없으면 이동하지 않고 번호를 담은 안내를 띄운다', async () => {
        localState = { cancelItems: [{ id: 1, cancel_quantity: 1 }] };
        G7Core.api.post.mockResolvedValue({ success: true, data: { claim: { branch: 'request', claim_number: 'RF9', cancelled: false } } });

        await executeCancelOrderHandler({ handler: 'x', params: { orderId: 'O1', canViewClaims: false } }, ctx);

        const navigations = G7Core.dispatch.mock.calls.filter((c) => c[0]?.handler === 'navigate');
        expect(navigations).toHaveLength(0);
        expect(G7Core.t).toHaveBeenCalledWith('sirsoft-ecommerce.admin.order.detail.modal.cancel.refund_request_created_no_claim_access', { number: 'RF9' });
    });

    /**
     * @scenario surface=order_detail_cancel, defect=navigate_without_claim_permission
     * @effects order_cancel_navigates_only_with_claim_access
     */
    it('볼 수 있으면 방금 만든 신청서로 이동한다', async () => {
        localState = { cancelItems: [{ id: 1, cancel_quantity: 1 }] };
        G7Core.api.post.mockResolvedValue({ success: true, data: { claim: { branch: 'request', claim_number: 'RF9', cancelled: false } } });

        await executeCancelOrderHandler({ handler: 'x', params: { orderId: 'O1', canViewClaims: true } }, ctx);

        const navigations = G7Core.dispatch.mock.calls.filter((c) => c[0]?.handler === 'navigate');
        expect(navigations).toHaveLength(1);
        expect(navigations[0][0].params.path).toBe('/admin/ecommerce/claims/RF9');
    });

    /**
     * 자동 전이가 켜졌는데 취소가 확정되지 않았다면 승인에서 멈춘 것이다 — 「승인·환불완료를 거쳐야」 는 사실과 다르다.
     *
     * @scenario surface=order_detail_cancel, defect=auto_branch_wrong_message
     * @effects order_cancel_message_follows_branch
     */
    it('자동 전이가 승인에서 멈춘 경우 전용 문구를 띄운다', async () => {
        localState = { cancelItems: [{ id: 1, cancel_quantity: 1 }] };
        G7Core.api.post.mockResolvedValue({ success: true, data: { claim: { branch: 'auto', claim_number: 'RF9', cancelled: false } } });

        await executeCancelOrderHandler({ handler: 'x', params: { orderId: 'O1', canViewClaims: true } }, ctx);

        expect(G7Core.t).toHaveBeenCalledWith('sirsoft-ecommerce.admin.order.detail.modal.cancel.refund_request_auto_stopped', undefined);
    });

    /**
     * @scenario surface=order_detail_cancel, defect=auto_branch_wrong_message
     * @effects order_cancel_message_follows_branch
     */
    it('새 안내 키와 누락돼 있던 키 2개가 ko·en·ja 에 있다', () => {
        const keys = [
            'admin.order.detail.modal.cancel.refund_request_created_no_claim_access',
            'admin.order.detail.modal.cancel.refund_request_auto_stopped',
            'admin.order.detail.modal.confirm_deposit.confirm_failed',
            'admin.order.bulk.error',
        ];
        for (const locale of ['ko', 'en', 'ja'] as const) {
            for (const key of keys) {
                expect(typeof langValue(locale, key), `${locale} ${key}`).toBe('string');
            }
        }
    });
});

describe('P7 후속 — [주문취소] 안내는 서버 문구를 쓴다', () => {
    /**
     * 서버가 `data.claim.cancelled` 에 따라 문구를 둘로 가른다(`messages.orders.cancelled` /
     * `messages.orders.cancel_refund_requested`). 화면이 제 문구를 따로 들면 두 출처가 갈린다.
     *
     * @scenario surface=order_detail_cancel, defect=client_authored_cancel_message
     * @effects order_cancel_message_from_server_by_cancelled
     */
    it.each([
        [true, '주문이 취소되었습니다(서버)'],
        [false, '환불신청서가 접수되었습니다(서버)'],
    ])('cancelled=%s 이면 응답 message 를 그대로 띄운다', async (cancelled, serverMessage) => {
        localState = { cancelItems: [{ id: 1, cancel_quantity: 1 }] };
        G7Core.api.post.mockResolvedValue({
            success: true,
            message: serverMessage,
            data: { claim: { branch: 'request', claim_number: 'RF1', cancelled } },
        });

        await executeCancelOrderHandler({ handler: 'x', params: { orderId: 'O1', canViewClaims: true } }, ctx);

        expect(G7Core.toast.success).toHaveBeenCalledWith(serverMessage);
    });

    /**
     * @scenario surface=order_detail_cancel, defect=client_authored_cancel_message
     * @effects order_cancel_message_from_server_by_cancelled
     */
    it('응답 message 가 없으면 cancelled 로 갈라 모듈 키로 떨어진다', async () => {
        localState = { cancelItems: [{ id: 1, cancel_quantity: 1 }] };
        G7Core.api.post.mockResolvedValue({ success: true, data: { claim: { branch: 'request', claim_number: 'RF1', cancelled: true } } });

        await executeCancelOrderHandler({ handler: 'x', params: { orderId: 'O1', canViewClaims: true } }, ctx);

        expect(G7Core.t).toHaveBeenCalledWith('sirsoft-ecommerce.admin.order.detail.modal.cancel.cancel_success', undefined);
    });
});
