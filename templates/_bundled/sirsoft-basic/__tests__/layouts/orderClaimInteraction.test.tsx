/** 주문 상품 선택·클레임 부분 갱신 회귀 (#599). @vitest-environment jsdom */
import fs from 'node:fs';
import path from 'node:path';
import { afterEach, describe, expect, it } from 'vitest';
import { screen } from '@testing-library/react';
import { createLayoutTest, createMockComponentRegistryWithBasics } from '@core/template-engine/__tests__/utils/layoutTestUtils';

const root = path.resolve(__dirname, '../../layouts');
const read = (file: string) => JSON.parse(fs.readFileSync(path.join(root, file), 'utf8'));
const nodes = (value: any): any[] => !value || typeof value !== 'object'
  ? [] : [value, ...Object.values(value).flatMap(nodes)];
let active: ReturnType<typeof createLayoutTest> | undefined;
afterEach(() => { active?.cleanup(); active = undefined; });

describe.each(['mypage/orders/show.json', 'shop/guest_order_show.json'])('%s 상품 선택', (file) => {
  const shell = read(file);
  const group = nodes(shell.slots).find(n => n.children?.some((c: any) => c.text === '$t:claim.apply.entry_refund'));

  /** @effects claim_actions_follow_order_items */
  it('클레임 버튼이 주문 상품 바로 다음, 주문자 정보 앞에 있다', () => {
    const children = nodes(shell.slots).find(n => n.children?.includes(group)).children;
    const index = children.indexOf(group);
    expect(children[index - 1].partial).toBe('partials/mypage/orders/_items.json');
    // 버튼 줄과 주문자 정보 사이에는 권한 때문에 잠긴 이유 안내(7.1.0 R3)만 선다 — 버튼 바로 아래에서 이유를 말한다.
    const after = children.slice(index + 1);
    const ordererAt = after.findIndex((c: any) => c.partial === 'partials/mypage/orders/_orderer.json');
    expect(ordererAt).toBeGreaterThanOrEqual(0);
    expect(after.slice(0, ordererAt).map((c: any) => c.text)).toEqual([
      '$t:claim.apply.no_request_permission',
      '$t:claim.apply.no_cancel_permission',
    ]);
  });

  /** @effects claim_buttons_follow_paid_order_policy_and_use_action_colors */
  it.each([
    { selected: [], types: ['refund'], shown: true, enabled: false },
    { selected: [416], types: ['refund'], shown: true, enabled: true },
    { selected: [416, 417], types: ['refund'], shown: true, enabled: true },
    { selected: [416, 999], types: ['refund'], shown: true, enabled: false },
    { selected: [416], types: [], shown: false, enabled: false },
  ])('신청 가능한 상품만 선택한 경우 환불 버튼 활성: $selected / $types', async ({ selected, types, shown, enabled }) => {
    active = createLayoutTest({
      data_sources: [{ id: 'claimable', type: 'api', endpoint: '/claimable', auto_fetch: true }],
      components: [group],
    } as any, {
      componentRegistry: createMockComponentRegistryWithBasics(),
      initialState: { _local: { selectedItemIds: selected } },
      translations: { claim: { apply: { entry_refund: '취소·환불 신청', entry_return: '반품 신청', entry_exchange: '교환 신청' } } },
    });
    // 신청 가능 상품 응답은 abilities(7.1.0 R3)를 늘 싣고 버튼은 `can_request !== true` 면 잠긴다.
    // 이 표는 상품 선택 축만 재므로 권한은 있는 상태로 둔다(권한 게이트 계약은 이커머스 모듈 테스트가 잰다).
    active.mockApi('claimable', { response: { data: {
      options: [416, 417].map(id => ({ option: { id, option_status: 'payment_complete' }, available_quantity: 1, claimable_types: types })),
      abilities: { can_request: true, can_cancel: true },
    } } });
    await active.render();
    const button = screen.queryByRole('button', { name: '취소·환불 신청' });
    if (!shown) expect(button).toBeNull();
    else {
      expect(button).toBeInTheDocument();
      expect((button as HTMLButtonElement).disabled).toBe(!enabled);
      expect(button).toHaveClass('bg-red-600', 'text-white');
      expect(screen.getByRole('button', { name: '반품 신청' })).toHaveClass('bg-orange-600', 'text-white');
      expect(screen.getByRole('button', { name: '교환 신청' })).toHaveClass('bg-blue-600', 'text-white');
    }
  });
});

describe('클레임 부분 갱신과 주문 식별자', () => {
  /** @effects claim_order_id_initialization_uses_unwrapped_response */
  it('data_source initLocal은 이미 풀린 주문 응답의 id를 읽고 초기값으로 덮어쓰지 않는다', () => {
    const shell = read('mypage/orders/show.json');
    const order = shell.data_sources.find((d: any) => d.id === 'order');
    const payload = { success: true, data: { id: 297 } };
    expect((payload.data as any)[order.initLocal.claimOrderId]).toBe(297);
    expect(shell.initLocal).not.toHaveProperty('claimOrderId');
  });

  /** @effects claim_tabs_and_pages_refetch_only_claims */
  it('탭·페이지는 URL과 상태를 갱신하고 claims만 다시 조회한다', () => {
    const list = read('partials/mypage/claims/_list.json');
    const sequences = nodes(list).filter(n => n.handler === 'sequence' && n.actions?.some((a: any) => a.params?.claimListPath || a.params?.path?.includes('claimListPath')));
    expect(sequences).toHaveLength(2);
    for (const sequence of sequences) {
      expect(sequence.actions.map((a: any) => a.handler)).toEqual(['setState', 'replaceUrl', 'refetchDataSource', 'setState']);
      expect(sequence.actions[1].params.scroll).toBe('preserve');
      expect(sequence.actions[2].params.dataSourceId).toBe('claims');
    }
    expect(sequences[0].actions[0].params.claimsPage).toBe(1);
    for (const file of ['mypage/orders/show.json', 'shop/guest_order_show.json', 'mypage/claims.json']) {
      const source = read(file).data_sources.find((d: any) => d.id === 'claims');
      expect(source.params.type).toContain('_local.claimsType ?? query.type');
      expect(source.params.page).toContain('_local.claimsPage ?? query.page');
    }
  });
});

describe('주문 상품 체크박스 자리 — 신청 불가 항목도 칸은 선다', () => {
  // 종전에는 취소됐거나 열린 클레임 유형이 없는 상품에서 체크박스 칸을 통째로 감췄다. 그러면
  // 같은 목록 안에서 그 줄만 상품 이미지가 왼쪽으로 밀려, 목록을 훑을 때 「왜 이 줄만 다르게
  // 생겼나」 가 먼저 눈에 들어온다(2026-09-21 요구사항 — 모든 클레임 화면 공통). 칸을 두고
  // 잠그면 줄 머리가 가지런하고, 「이 상품은 고를 수 없다」 도 그 자리에서 말한다.
  const render = async (options: any[], claimableTypes: Record<number, string[]>) => {
    active = createLayoutTest({
      data_sources: [
        { id: 'order', type: 'api', endpoint: '/order', auto_fetch: true },
        { id: 'claimable', type: 'api', endpoint: '/claimable', auto_fetch: true },
      ],
      components: [read('partials/mypage/orders/_items.json')],
    } as any, {
      componentRegistry: createMockComponentRegistryWithBasics(),
      initialState: { _local: { selectedItemIds: [] } },
    });
    active.mockApi('order', { response: { data: { options } } });
    active.mockApi('claimable', {
      response: {
        data: {
          options: options.map((o) => ({
            option: { id: o.id, option_status: o.option_status },
            available_quantity: 1,
            claimable_types: claimableTypes[o.id] ?? [],
          })),
        },
      },
    });
    await active.render();
    return [...document.querySelectorAll('input[type="checkbox"]')] as HTMLInputElement[];
  };

  /** @effects order_item_checkbox_column_always_renders */
  it('신청 가능·불가가 섞여도 상품 수만큼 체크박스가 서고, 불가 항목만 잠긴다', async () => {
    const boxes = await render(
      [
        { id: 416, option_status: 'payment_complete' },
        { id: 417, option_status: 'cancelled' },
        { id: 418, option_status: 'payment_complete' },
      ],
      { 416: ['refund'], 418: [] },
    );

    // 전체선택 라벨 1개 + 상품 3개.
    const perItem = boxes.slice(-3);

    expect(perItem).toHaveLength(3);
    expect(perItem.map((b) => b.disabled)).toEqual([false, true, true]);
  });

  /** @effects order_item_checkbox_locked_state_is_visible */
  it('잠긴 칸은 눈에도 잠겨 보인다 — 흐리게 + 금지 커서', async () => {
    const boxes = await render(
      [
        { id: 416, option_status: 'payment_complete' },
        { id: 417, option_status: 'cancelled' },
      ],
      { 416: ['refund'] },
    );
    const [open, locked] = boxes.slice(-2);

    expect(open).toHaveClass('cursor-pointer');
    expect(open).not.toHaveClass('opacity-40');
    expect(locked).toHaveClass('opacity-40', 'cursor-not-allowed');
  });
});
