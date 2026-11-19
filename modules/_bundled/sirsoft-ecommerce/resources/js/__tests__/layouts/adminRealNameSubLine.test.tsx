/**
 * @file adminRealNameSubLine.test.tsx
 * @description 이커머스 관리자 목록의 실명 보조 줄(#490 P17)
 *
 * 관리자 화면의 회원 이름 칸은 관리자 영역 표시 이름이다. 서버는 요청한 관리자에게 회원 조회 권한이 있고 실명이 표시 이름과
 * 다를 때만 실명 키를 더한다. 화면은 그 키가 있을 때만 이름 아래에 작은 글씨 보조 줄을 그리고, 없으면 줄 자체를 그리지 않는다.
 *
 * 목록은 `DataGrid` 한 벌이 넓은 화면 표와 좁은 화면 카드를 같은 칸 정의로 그리므로(따로 둔 카드 조각이 없다),
 * 칸 정의에 넣은 보조 줄 노드를 굽기 산출 JSON 에서 그대로 꺼내 행 문맥으로 그려 본다.
 */

import { describe, it, expect, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { collect, loadLayout, setupRegistry, translations, type Json } from './support/checkoutFieldsHarness';

interface Target {
  /** 목록 이름(테스트 제목) */
  list: string;
  /** 레이아웃 JSON(`resources/layouts/admin` 기준) */
  file: string;
  /** 같은 칸에 있어야 하는 이름 식 */
  nameExpr: string;
  /** 보조 줄이 읽는 실명 식(한 파일에 보조 줄이 둘이면 이 글자로 고른다) */
  realExpr: string;
  /** 실명 키가 있는 행 */
  withKey: Json;
  /** 실명 키가 없는 행 */
  withoutKey: Json;
}

const TARGETS: Target[] = [
  {
    list: '장바구니 관리',
    file: 'admin_ecommerce_cart_index.json',
    nameExpr: 'row.member?.name',
    realExpr: 'row.member?.real_name',
    withKey: { member: { uuid: 'u1', name: '길동이', real_name: '홍길동' } },
    withoutKey: { member: { uuid: 'u1', name: '길동이' } },
  },
  {
    list: '위시리스트 관리',
    file: 'admin_ecommerce_wishlist_index.json',
    nameExpr: 'row.member?.name',
    realExpr: 'row.member?.real_name',
    withKey: { member: { uuid: 'u1', name: '길동이', real_name: '홍길동' } },
    withoutKey: { member: { uuid: 'u1', name: '길동이' } },
  },
  {
    list: '배송지 관리',
    file: 'partials/admin_ecommerce_user_address_index/_partial_address_datagrid.json',
    nameExpr: 'row.member?.name',
    realExpr: 'row.member?.real_name',
    withKey: { member: { uuid: 'u1', name: '길동이', real_name: '홍길동' } },
    withoutKey: { member: { uuid: 'u1', name: '길동이' } },
  },
  {
    list: '상품 문의 관리',
    file: 'admin_ecommerce_inquiry_index.json',
    nameExpr: 'row.member?.name',
    realExpr: 'row.member?.real_name',
    withKey: { member: { uuid: 'u1', name: '길동이', real_name: '홍길동' } },
    withoutKey: { member: { uuid: 'u1', name: '길동이' } },
  },
  {
    list: '주문 목록',
    file: 'partials/admin_ecommerce_order_list/_partial_order_datagrid.json',
    nameExpr: 'row.user?.name',
    realExpr: 'row.user?.real_name',
    withKey: { address: {}, user: { uuid: 'u1', name: '길동이', real_name: '홍길동' } },
    withoutKey: { address: {}, user: { uuid: 'u1', name: '길동이' } },
  },
  {
    list: '상품 후기 관리',
    file: 'admin_ecommerce_product_review_index.json',
    nameExpr: 'row.author_name',
    realExpr: 'row.author_real_name',
    withKey: { user_id: 7, author_name: '길동이', author_real_name: '홍길동', user: { name: '길동이', real_name: '홍길동' } },
    withoutKey: { user_id: 7, author_name: '길동이', user: { name: '길동이' } },
  },
  {
    list: '쿠폰 발급 내역',
    file: 'partials/admin_ecommerce_promotion_coupon_list/_modal_issue_history.json',
    nameExpr: 'row.user_name',
    realExpr: 'row.user_real_name',
    withKey: { user_id: 7, user_name: '길동이', user_real_name: '홍길동' },
    withoutKey: { user_id: 7, user_name: '길동이' },
  },
  {
    list: '마일리지 내역(회원)',
    file: 'partials/admin_ecommerce_mileage_transaction_index/_transactions_table.json',
    nameExpr: 'row.user_name',
    realExpr: 'row.user_real_name',
    withKey: { user_id: 7, user_uuid: 'u1', user_name: '길동이', user_real_name: '홍길동' },
    withoutKey: { user_id: 7, user_uuid: 'u1', user_name: '길동이' },
  },
  {
    list: '마일리지 내역(처리자)',
    file: 'partials/admin_ecommerce_mileage_transaction_index/_transactions_table.json',
    nameExpr: 'row.granted_by_name',
    realExpr: 'row.granted_by_real_name',
    withKey: { granted_by_uuid: 'a1', granted_by_name: '운영자', granted_by_real_name: '홍길동' },
    withoutKey: { granted_by_uuid: 'a1', granted_by_name: '운영자' },
  },
];

/** 보조 줄 노드를 고른다(파일에 둘 이상이면 실명 식으로). */
function subLines(tree: Json, realExpr?: string): Json[] {
  return collect(tree, (n) => n.props?.['data-testid'] === 'real-name-sub' && (!realExpr || String(n.if ?? '').includes(realExpr)));
}

/** 노드를 직접 품은 배열(같은 칸의 형제 묶음)을 찾는다. */
function siblingsOf(tree: any, node: Json): Json[] | null {
  if (Array.isArray(tree)) {
    if (tree.includes(node)) return tree;
    for (const child of tree) {
      const found = siblingsOf(child, node);
      if (found) return found;
    }
    return null;
  }
  if (tree && typeof tree === 'object') {
    for (const value of Object.values(tree)) {
      const found = siblingsOf(value, node);
      if (found) return found;
    }
  }
  return null;
}

let active: ReturnType<typeof createLayoutTest> | null = null;

/** 보조 줄 노드를 행마다 한 번씩 그린다(`row` 는 행, `order` 는 주문 상세 응답). */
async function renderSub(node: Json, rows: Json[], data: Json = {}): Promise<HTMLElement> {
  active = createLayoutTest(
    {
      version: '1.0.0',
      layout_name: 'test_real_name_sub',
      components: [{ type: 'basic', name: 'Div', iteration: { source: '{{rows}}', item_var: 'row' }, props: { 'data-testid': 'row' }, children: [node] }],
    } as any,
    { componentRegistry: setupRegistry(), translations: translations('ko'), locale: 'ko', initialState: { _local: {}, _global: {} }, initialData: { rows, ...data } } as any,
  );

  return (await active.render()).container;
}

afterEach(() => {
  active?.cleanup();
  active = null;
});

describe('관리자 목록의 실명 보조 줄(P17)', () => {
  // @scenario case=sub_line_per_list
  // @effects real_name_sub_line_drawn_only_when_key_present
  it.each(TARGETS)('$list — 실명 키가 있는 행에만 이름 아래 보조 줄을 그린다', async ({ file, nameExpr, realExpr, withKey, withoutKey }) => {
    const layout = loadLayout(file);
    const nodes = subLines(layout, realExpr);
    expect(nodes).toHaveLength(1);
    const node = nodes[0];

    // 이름을 그리는 그 칸 안에 있다
    expect(JSON.stringify(siblingsOf(layout, node))).toContain(nameExpr);
    // 칸 안의 번역 인자는 식으로 준다(`$t:키|인자={{row.값}}` 은 표 칸에서 행 값을 받지 못한다)
    expect(node.props['aria-label']).toContain("$t('sirsoft-ecommerce.admin.common.real_name_sub', { name: ");
    expect(node.props['aria-label']).not.toContain('$t:');

    const container = await renderSub(node, [withKey, withoutKey]);
    const rows = Array.from(container.querySelectorAll('[data-testid="row"]'));
    expect(rows).toHaveLength(2);

    const drawn = rows[0].querySelectorAll('[data-testid="real-name-sub"]');
    expect(drawn).toHaveLength(1);
    expect(drawn[0].textContent).toBe('홍길동');
    expect(drawn[0].getAttribute('aria-label')).toBe('실명 홍길동');
    expect(drawn[0].getAttribute('role')).toBe('note');
    expect(drawn[0].className).toContain('ecommerce:text-xs');
    // 키가 없으면 줄 자체가 없다
    expect(rows[1].querySelectorAll('[data-testid="real-name-sub"]')).toHaveLength(0);
  });

  // @scenario case=order_list_same_as_orderer
  // @effects order_sub_line_skipped_when_same_as_orderer_name
  it('주문 목록 — 주문서의 주문자 이름이 실명과 같으면 같은 글자를 두 번 적지 않는다', async () => {
    const node = subLines(loadLayout('partials/admin_ecommerce_order_list/_partial_order_datagrid.json'))[0];

    const container = await renderSub(node, [
      { address: { orderer_name: '홍길동' }, user: { uuid: 'u1', name: '길동이', real_name: '홍길동' } },
      { address: { orderer_name: '김철수' }, user: { uuid: 'u1', name: '길동이', real_name: '홍길동' } },
      // 비회원 주문(회원 없음)
      { address: { orderer_name: '홍길동' }, user: null },
    ]);
    const rows = Array.from(container.querySelectorAll('[data-testid="row"]'));

    expect(rows[0].querySelectorAll('[data-testid="real-name-sub"]')).toHaveLength(0);
    expect(rows[1].querySelector('[data-testid="real-name-sub"]')!.textContent).toBe('홍길동');
    expect(rows[2].querySelectorAll('[data-testid="real-name-sub"]')).toHaveLength(0);
  });

  // @scenario case=order_detail
  // @effects order_detail_sub_line_under_orderer_name
  it('주문 상세 — 회원 주문의 주문자 이름 아래에 실명 키가 있을 때만 보조 줄을 그린다', async () => {
    const layout = loadLayout('partials/admin_ecommerce_order_detail/_partial_order_info.json');
    const nodes = subLines(layout);
    expect(nodes).toHaveLength(1);
    expect(nodes[0].id).toBe('orderer_real_name_sub');
    expect(JSON.stringify(siblingsOf(layout, nodes[0]))).toContain('order.data?.orderer_name');

    const withKey = await renderSub(nodes[0], [{}], { order: { data: { user_id: 7, orderer_name: '김철수', user: { name: '길동이', real_name: '홍길동' } } } });
    const sub = withKey.querySelector('[data-testid="real-name-sub"]')!;
    expect(sub.textContent).toBe('홍길동');
    expect(sub.getAttribute('aria-label')).toBe('실명 홍길동');
    active?.cleanup();

    const withoutKey = await renderSub(nodes[0], [{}], { order: { data: { user_id: 7, orderer_name: '김철수', user: { name: '길동이' } } } });
    expect(withoutKey.querySelectorAll('[data-testid="real-name-sub"]')).toHaveLength(0);
    active?.cleanup();

    const same = await renderSub(nodes[0], [{}], { order: { data: { user_id: 7, orderer_name: '홍길동', user: { name: '길동이', real_name: '홍길동' } } } });
    expect(same.querySelectorAll('[data-testid="real-name-sub"]')).toHaveLength(0);
  });

  // @scenario case=review_user_fallback
  // @effects review_sub_line_falls_back_to_user_real_name
  it('상품 후기 관리 — 작성자 실명 키가 없고 회원 쪽 실명 키만 와도 보조 줄을 그린다', async () => {
    const node = subLines(loadLayout('admin_ecommerce_product_review_index.json'))[0];

    const container = await renderSub(node, [{ user_id: 7, author_name: '길동이', user: { name: '길동이', real_name: '홍길동' } }]);

    expect(container.querySelector('[data-testid="real-name-sub"]')!.textContent).toBe('홍길동');
  });

  // @scenario case=export_history_untouched
  // @effects export_history_requester_has_no_sub_line
  it('데이터 내보내기 이력의 요청자 칸에는 보조 줄이 없다(기록물)', () => {
    expect(subLines(loadLayout('admin_ecommerce_export_index.json'))).toHaveLength(0);
    expect(subLines(loadLayout('partials/admin_ecommerce_export_index/_modal_downloads.json'))).toHaveLength(0);
  });
});
