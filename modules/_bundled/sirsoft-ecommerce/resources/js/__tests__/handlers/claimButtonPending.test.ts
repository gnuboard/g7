/** @vitest-environment jsdom */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { executeClaimItemBulkStatusHandler, saveClaimAdminMemoHandler, saveClaimAmountsHandler, deliverClaimTrackingHandler, restoreClaimStockHandler } from '../../handlers/claimHandlers';

let local: Record<string, any>;
let api: any;
beforeEach(() => {
  local = {};
  api = { patch: vi.fn(), put: vi.fn(), post: vi.fn() };
  (window as any).G7Core = {
    state: { getLocal: () => local, setLocal: (p: any) => Object.assign(local, p) },
    api, toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() }, dispatch: vi.fn(), t: (key: string) => key,
  };
});

describe.each([
  [executeClaimItemBulkStatusHandler, 'patch', 'isUpdatingClaimItems', { claimType: 'exchange', itemIds: [1], status: 'approved' }],
  [saveClaimAdminMemoHandler, 'put', 'isSavingMemo', { adminMemo: '메모' }],
  [executeClaimItemBulkStatusHandler, 'patch', 'isUpdatingClaimItems', { itemIds: [1], trackingOnly: true, direction: 'collection', carrierId: 1, trackingNumber: '123' }],
  [deliverClaimTrackingHandler, 'post', 'isDeliveringTracking', { direction: 'collection', carrierId: 1, trackingNumber: '123' }],
  [restoreClaimStockHandler, 'post', 'isRestoringStock', { itemIds: [1] }],
  [saveClaimAmountsHandler, 'put', 'isSavingAmounts', { claimShippingFee: 0 }],
] as const)('%s 요청 중 상태', (handler, method, key, extra) => {
  /** @effects claim_async_buttons_show_spinner_and_block_duplicate_requests */
  it.each([false, true])('중복 요청 차단과 종료 후 해제 (실패=%s)', async fails => {
    let resolve!: (v: any) => void;
    let reject!: (v: any) => void;
    api[method].mockImplementation(() => new Promise((yes, no) => { resolve = yes; reject = no; }));
    const action = { handler: '', params: { pathSegment: 'exchanges', claimId: 1, ...extra } };
    const pending = handler(action as any);
    const result = pending.catch((e: any) => e);
    expect(local[key]).toBe(true);
    await handler(action as any);
    expect(api[method]).toHaveBeenCalledTimes(1);
    if (fails) reject(new Error('실패')); else resolve({ success: true, data: {} });
    await result;
    expect(local[key]).toBe(false);
  });
});

describe('누른 버튼을 가리키는 표식', () => {
  /** @effects claim_deliver_spinner_only_on_pressed_waybill */
  it('[도착 처리] 는 요청 중 누른 운송장 키를 두고 끝나면 비운다', async () => {
    let resolve!: (v: any) => void;
    api.post.mockImplementation(() => new Promise(yes => { resolve = yes; }));
    const pending = deliverClaimTrackingHandler({ handler: '', params: { pathSegment: 'returns', claimId: 1, direction: 'collection', carrierId: '1', trackingNumber: ' WB-A ' } } as any);
    expect(local.deliveringTrackingKey).toBe('collection|1|WB-A');
    resolve({ success: true, data: {} });
    await pending;
    expect(local.deliveringTrackingKey).toBeNull();
  });

  /** @effects claim_item_bar_spinner_only_on_pressed_button */
  it.each([
    [{ trackingOnly: true, direction: 'collection', carrierId: 1, trackingNumber: '123' }, 'tracking'],
    [{ claimType: 'exchange', status: 'approved' }, 'status'],
  ] as const)('선택 바 처리 종류를 요청 중 두고 끝나면 비운다 (%j → %s)', async (extra, kind) => {
    let resolve!: (v: any) => void;
    api.patch.mockImplementation(() => new Promise(yes => { resolve = yes; }));
    const pending = executeClaimItemBulkStatusHandler({ handler: '', params: { pathSegment: 'exchanges', claimId: 1, itemIds: [1], ...extra } } as any);
    expect(local.claimItemsUpdateKind).toBe(kind);
    resolve({ success: true, data: {} });
    await pending;
    expect(local.claimItemsUpdateKind).toBeNull();
  });
});
