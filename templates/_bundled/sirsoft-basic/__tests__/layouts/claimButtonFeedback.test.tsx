/** @vitest-environment jsdom */
import fs from 'node:fs';
import path from 'node:path';
import React from 'react';
import { afterEach, describe, expect, it } from 'vitest';
import { createLayoutTest, createMockComponentRegistryWithBasics, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';

const root = path.resolve(__dirname, '../../layouts/partials/mypage/claims');
const nodes = (v: any): any[] => !v || typeof v !== 'object' ? [] : [v, ...Object.values(v).flatMap(nodes)];
let active: ReturnType<typeof createLayoutTest> | undefined;
afterEach(() => active?.cleanup());

describe.each([false, true])('회원/비회원 공용 클레임 버튼 (isGuest=%s)', isGuest => {
  /** @effects claim_async_buttons_show_spinner_and_block_duplicate_requests */
  it.each([
    ['_detail_claim_info.json', 'isPayingClaim', { isPayingClaim: true }],
    ['_apply_items.json', 'changeProductLoading', { changeProductLoading: true, changeProductTarget: { index: 0 } }],
    ['_apply_reason.json', 'removingClaimImageId', { removingClaimImageId: 9 }],
    ['_modal_withdraw.json', 'isWithdrawingClaim', { isWithdrawingClaim: true }],
    ['_apply_summary.json', 'isSubmittingClaim', { isSubmittingClaim: true }],
    ['_apply_summary.json', 'claimEditSaving', { claimEditSaving: true, claimEditMode: true }],
  ])('%s 처리 중 스피너와 비활성화', async (file, key, local) => {
    const button = nodes(JSON.parse(fs.readFileSync(path.join(root, file), 'utf8')))
      .find(n => n.name === 'Button' && n.children?.some((c: any) => c.name === 'Icon' && c.if?.includes(key)));
    expect(button).toBeTruthy();
    const registry = createMockComponentRegistryWithBasics();
    registry.register('basic', 'Icon', ({ name, className }: any) => React.createElement('i', { className, 'data-icon': name }));
    active = createLayoutTest({ components: [button] } as any, {
      componentRegistry: registry,
      initialState: { _local: { isGuest, ...local } },
      initialData: { claimIdx: 0, claimImage: { id: 9 } },
    } as any);
    const { container } = await active.render();
    expect(container.querySelector('[data-icon="spinner"]')).not.toBeNull();
    expect((screen.getByRole('button') as HTMLButtonElement).disabled).toBe(true);
  });
});
