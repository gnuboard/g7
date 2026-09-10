/** @vitest-environment jsdom */
import fs from 'node:fs';
import path from 'node:path';
import React from 'react';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createLayoutTest, createMockComponentRegistryWithBasics, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';

const root = path.resolve(__dirname, '../../../layouts/admin/partials/admin_ecommerce_claim_detail');
const viewport = vi.hoisted(() => ({ width: 1280 }));
vi.mock('@core/template-engine/ResponsiveContext', async importOriginal => ({
  ...await importOriginal<any>(),
  useResponsive: () => ({ width: viewport.width, isMobile: viewport.width < 768, isTablet: false, isDesktop: viewport.width >= 1024, matchedPreset: viewport.width < 768 ? 'mobile' : 'desktop' }),
}));
const nodes = (v: any): any[] => !v || typeof v !== 'object' ? [] : [v, ...Object.values(v).flatMap(nodes)];
let active: ReturnType<typeof createLayoutTest> | undefined;
afterEach(() => { active?.cleanup(); viewport.width = 1280; });

describe.each([375, 1280])('상태 변경 버튼 너비=%s', width => {
  /** @effects claim_pending_feedback_preserves_responsive_label */
  it.each([false, true])('pending=%s에도 화면별 라벨과 스피너를 유지한다', async busy => {
    viewport.width = width;
    const button = nodes(JSON.parse(fs.readFileSync(path.join(root, '_partial_claim_items.json'), 'utf8')))
      .find(n => n.name === 'Button' && n.children?.some((c: any) => c.if?.includes('isUpdatingClaimItems')));
    const registry = createMockComponentRegistryWithBasics();
    registry.register('basic', 'Icon', ({ name }: any) => React.createElement('i', { 'data-icon': name }));
    active = createLayoutTest({ components: [button] } as any, {
      componentRegistry: registry,
      initialState: { _local: { isUpdatingClaimItems: busy, selectedClaimItems: [1] } },
      initialData: { claim: { data: { abilities: { can_update_status: true } } } },
      translations: { 'sirsoft-ecommerce': { admin: { claim: { bulk: { apply: '변경', apply_full: '선택 상품 상태 변경' } } } } },
    } as any);
    const { container } = await active.render();
    const rendered = screen.getByRole('button', { name: width < 1024 ? '선택 상품 상태 변경' : '변경' });
    expect((rendered as HTMLButtonElement).disabled).toBe(busy);
    expect(container.querySelectorAll('[data-icon="spinner"]').length).toBe(busy ? 1 : 0);
  });
});

describe.each([
  ['_partial_claim_items.json', 'isUpdatingClaimItems'],
  ['_partial_claim_payment.json', 'isSavingAmounts'],
  ['_partial_claim_reason.json', 'isSavingMemo'],
  ['_partial_claim_shipping.json', 'isSavingCollection'],
  ['_partial_claim_shipping.json', 'isSavingRedelivery'],
])('%s %s 처리 표시', (file, key) => {
  /** @effects claim_async_buttons_show_spinner_and_block_duplicate_requests */
  it.each([false, true])('처리 중=%s일 때만 스피너 표시 및 재클릭 차단', async busy => {
    const buttons = nodes(JSON.parse(fs.readFileSync(path.join(root, file), 'utf8')))
      .filter(n => n.name === 'Button' && n.children?.some((c: any) => c.name === 'Icon' && c.if?.includes(key)));
    expect(buttons.length).toBeGreaterThan(0);
    const registry = createMockComponentRegistryWithBasics();
    registry.register('basic', 'Icon', ({ name, className }: any) => React.createElement('i', { className, 'data-icon': name }));
    active = createLayoutTest({ data_sources: [{ id: 'claim', type: 'api', endpoint: '/claim', auto_fetch: true }], components: buttons } as any, {
      componentRegistry: registry,
      initialState: { _local: { [key]: busy, selectedClaimItems: [1] } },
    });
    active.mockApi('claim', { response: { data: { abilities: { can_update_status: true, can_update_amounts: true } } } });
    const { container } = await active.render();
    expect(container.querySelectorAll('.spinner-sm').length).toBe(busy ? buttons.length : 0);
    for (const button of screen.getAllByRole('button')) expect((button as HTMLButtonElement).disabled).toBe(busy);
  });
});
