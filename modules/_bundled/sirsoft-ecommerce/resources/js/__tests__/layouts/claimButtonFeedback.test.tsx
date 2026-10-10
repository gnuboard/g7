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

describe('같은 화면의 여러 버튼 중 누른 버튼만 스피너', () => {
  const registryWithIcon = () => {
    const registry = createMockComponentRegistryWithBasics();
    registry.register('basic', 'Icon', ({ name, className }: any) => React.createElement('i', { className, 'data-icon': name }));

    return registry;
  };

  /**
   * 운송장 묶음이 둘이면 [도착 처리] 도 둘이다. 한 묶음을 누르면 스피너는 그 묶음 버튼에만 돌고,
   * 다른 묶음 버튼은 잠기기만 한다(중복 요청 방지) — 둘 다 돌면 운영자는 두 운송장을 함께 처리 중으로 읽는다.
   *
   * @effects claim_deliver_spinner_only_on_pressed_waybill
   */
  it('[도착 처리] 스피너는 누른 운송장 묶음에만 돈다', async () => {
    const section = JSON.parse(fs.readFileSync(path.join(root, '_partial_claim_shipping.json'), 'utf8'));
    const group = (tracking: string) => ({ carrier_id: 1, carrier_name: 'CJ', tracking_number: tracking, items: [], can_deliver: true, all_arrived: false });
    active = createLayoutTest({ data_sources: [{ id: 'claim', type: 'api', endpoint: '/claim', auto_fetch: true }], components: [section] } as any, {
      componentRegistry: registryWithIcon(),
      initialState: { _local: { isDeliveringTracking: true, deliveringTrackingKey: 'collection|1|WB-A' } },
    });
    active.mockApi('claim', { response: { data: {
      abilities: { can_update_status: true },
      claim: { claim_type: 'return', tracking_groups: { collection: [group('WB-A'), group('WB-B')], redelivery: [] } },
    } } });
    const { container } = await active.render();
    const deliverButtons = Array.from(container.querySelectorAll('button')).filter(b => b.textContent?.includes('deliver'));

    expect(deliverButtons).toHaveLength(2);
    expect(deliverButtons.map(b => b.querySelectorAll('[data-icon="spinner"]').length)).toEqual([1, 0]);
    expect(deliverButtons.map(b => (b as HTMLButtonElement).disabled)).toEqual([true, true]);
  });

  /**
   * [송장만 저장] 과 [일괄 적용] 은 같은 확인 창·같은 잠금을 쓴다. 송장 저장 중에는 [송장만 저장] 에만,
   * 상태 적용 중에는 [일괄 적용] 에만 스피너가 돈다.
   *
   * @effects claim_item_bar_spinner_only_on_pressed_button
   */
  it.each([
    ['tracking', [1, 0]],
    ['status', [0, 1]],
  ] as const)('선택 바 — 처리 종류=%s 이면 스피너 위치 %j ([송장만 저장], [일괄 적용])', async (kind, expected) => {
    const ids = ['ci_009_tracking_only', 'ci_004_item_bulk_apply'];
    const all = nodes(JSON.parse(fs.readFileSync(path.join(root, '_partial_claim_items.json'), 'utf8')));
    const buttons = ids.map(id => all.find(n => n.id === id));
    active = createLayoutTest({ data_sources: [{ id: 'claim', type: 'api', endpoint: '/claim', auto_fetch: true }], components: buttons } as any, {
      componentRegistry: registryWithIcon(),
      initialState: { _local: { isUpdatingClaimItems: true, claimItemsUpdateKind: kind, selectedClaimItems: [1] } },
    });
    active.mockApi('claim', { response: { data: { abilities: { can_update_status: true }, claim: { claim_type: 'exchange' } } } });
    const { container } = await active.render();
    const rendered = Array.from(container.querySelectorAll('button'));

    expect(rendered).toHaveLength(2);
    expect(rendered.map(b => b.querySelectorAll('[data-icon="spinner"]').length)).toEqual(expected);
  });
});
