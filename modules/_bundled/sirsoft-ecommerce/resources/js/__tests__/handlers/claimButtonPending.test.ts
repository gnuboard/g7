/** @vitest-environment jsdom */
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { executeClaimItemBulkStatusHandler, saveClaimAdminMemoHandler, saveClaimTrackingHandler, saveClaimAmountsHandler } from '../../handlers/claimHandlers';

let local: Record<string, any>;
let api: any;
beforeEach(() => {
  local = {};
  api = { patch: vi.fn(), put: vi.fn() };
  (window as any).G7Core = {
    state: { getLocal: () => local, setLocal: (p: any) => Object.assign(local, p) },
    api, toast: { success: vi.fn(), warning: vi.fn(), error: vi.fn() }, dispatch: vi.fn(), t: (key: string) => key,
  };
});

describe.each([
  [executeClaimItemBulkStatusHandler, 'patch', 'isUpdatingClaimItems', { claimType: 'exchange', itemIds: [1], status: 'approved' }],
  [saveClaimAdminMemoHandler, 'put', 'isSavingMemo', { adminMemo: '메모' }],
  [saveClaimTrackingHandler, 'put', 'isSavingCollection', { kind: 'collection', carrierId: 1, trackingNumber: '123' }],
  [saveClaimTrackingHandler, 'put', 'isSavingRedelivery', { kind: 'redelivery', carrierId: 1, trackingNumber: '123' }],
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
