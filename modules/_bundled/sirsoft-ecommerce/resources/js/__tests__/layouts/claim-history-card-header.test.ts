/**
 * @file claim-history-card-header.test.ts
 * @description 주문 상세 클레임 이력 — 모바일 카드 머리줄 배치와 상품 목록 모바일 옵션 카드 묶음
 *
 * 1. 카드 머리줄이 `justify-between` 에 [번호, 상태 배지, 환불 대기 배지] 셋이라, 환불 대기 배지가
 *    붙으면 상태 배지가 가운데로 떴다. 두 배지를 한 묶음으로 감싸 [번호, 배지 묶음] 둘로 둔다.
 * 2. 상품 목록의 모바일 옵션 카드(admin-card)가 row-stack 직속이라 카드 패딩(16px)이 위 8 /
 *    아래 0 으로 덮어써졌다. 카드 간격은 카드의 mb-3 이 맡으므로 stack-flush 로 바꾼다.
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';

type Json = Record<string, any>;

const LAYOUTS_ROOT = path.resolve(__dirname, '../../../layouts');
const load = (rel: string): Json => JSON.parse(fs.readFileSync(path.join(LAYOUTS_ROOT, rel), 'utf8'));

const claimHistory = load('admin/partials/admin_ecommerce_order_detail/_partial_claim_history.json');
const productDatagrid = load('admin/partials/admin_ecommerce_product_list/_partial_product_datagrid.json');

/** 조건에 맞는 첫 노드를 찾는다. */
function find(tree: unknown, pred: (n: Json) => boolean): Json | undefined {
  let found: Json | undefined;
  const walk = (n: unknown): void => {
    if (found) return;
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (pred(obj)) {
        found = obj;
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  return found;
}

const tokens = (cls: unknown): string[] => String(cls ?? '').split(/\s+/).filter(Boolean);

describe('클레임 이력 모바일 카드 머리줄', () => {
  /**
   * @scenario defect=justify_between_three_children
   * @effects badges_stay_right_aligned_together
   */
  it('머리줄은 [번호(링크/글자 중 하나), 배지 묶음] 이고 배지 묶음 안에 배지 둘이 있다', () => {
    const header = find(claimHistory, (n) => String(n.id ?? '').startsWith('claim_history_card_header_'));
    expect(header, '카드 머리 노드가 없습니다.').toBeDefined();
    const row = (header!.children ?? []).find((c: Json) => tokens(c.props?.className).includes('justify-between'));
    expect(row, '머리줄이 없습니다.').toBeDefined();

    const kids: Json[] = row.children ?? [];
    expect(kids.some((c) => c.name === 'Badge'), '배지가 아직 머리줄 직속입니다.').toBe(false);
    const numberNodes = kids.filter((c) => String(c.id ?? '').startsWith('claim_history_card_number_'));
    expect(numberNodes.length, '번호 링크/글자가 있어야 합니다.').toBe(2);
    // 번호 둘은 서로 배타 조건이라 화면에는 하나만 나온다 — 화면상 자식은 [번호, 배지 묶음] 둘이다.
    expect(numberNodes.map((n) => n.if).every((cond) => typeof cond === 'string')).toBe(true);

    const group = kids.find((c) => !String(c.id ?? '').startsWith('claim_history_card_number_'));
    expect(kids.length).toBe(3);
    expect(tokens(group!.props?.className)).toEqual(expect.arrayContaining(['flex-center', 'gap-1.5']));
    const badges = (group!.children ?? []).filter((c: Json) => c.name === 'Badge');
    expect(badges.length).toBe(2);
    expect(String(badges[1].if)).toContain('has_pending_refund');
  });
});

describe('상품 목록 모바일 옵션 카드 묶음', () => {
  /**
   * @scenario defect=row_stack_boxed_child_padding
   * @effects admin_card_padding_kept_on_mobile
   */
  it('옵션 카드의 portable 컨테이너는 row-stack 이 아니라 stack-flush 다', () => {
    const container = find(productDatagrid, (n) =>
      (n.responsive?.portable?.children ?? []).some((c: Json) => c.iteration?.item_var === 'option' && tokens(c.props?.className).includes('admin-card')),
    );
    expect(container, '옵션 카드 묶음이 없습니다.').toBeDefined();
    const cls = tokens(container!.responsive.portable.props?.className);
    expect(cls).toContain('stack-flush');
    expect(cls).not.toContain('row-stack');
  });
});
