/**
 * @file adminOrderManualPaidEffects.test.ts
 * @description 수동 결제 처리 확인 창 블록 구조 테스트 (Q13·Q14·Q24·Q26·Q28)
 *
 * 테스트 대상:
 * - layouts/admin/partials/admin_ecommerce_order_list/_modal_bulk_confirm.json (목록 일괄변경)
 * - layouts/admin/partials/admin_ecommerce_order_detail/_modal_batch_change_confirm.json (주문상세 상품 일괄변경)
 *
 * 축 요약(마커 아님, 평문): 두 확인 창 × 세 체크(재고·마일리지·결제금액) × 복원 안내·쿠폰 충돌·경고.
 * 테스트별 효과 매핑은 각 it 바로 위 주석에 둔다.
 */

import { describe, it, expect } from 'vitest';
import bulkModal from '../../../layouts/admin/partials/admin_ecommerce_order_list/_modal_bulk_confirm.json';
import detailModal from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_modal_batch_change_confirm.json';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

interface Node {
  type?: string;
  name?: string;
  id?: string;
  if?: string;
  props?: Record<string, any>;
  children?: Node[];
  actions?: any[];
}

function flatten(node: any, acc: Node[] = []): Node[] {
  if (!node || typeof node !== 'object') return acc;
  acc.push(node);
  if (Array.isArray(node.children)) for (const c of node.children) flatten(c, acc);
  return acc;
}

function findById(nodes: Node[], id: string): Node | undefined {
  return nodes.find((n) => n.id === id);
}

function confirmButtonDisabled(nodes: Node[]): string {
  const buttons = nodes.filter((n) => n.name === 'Button' && typeof n.props?.disabled === 'string');
  return String(buttons[buttons.length - 1]?.props?.disabled ?? '');
}

const cases: Array<[string, any, string]> = [
  ['목록 일괄변경', bulkModal, 'coupon_conflict_order_numbers'],
  ['주문상세 상품 일괄변경', detailModal, 'coupon_conflicts'],
];

describe.each(cases)('%s 확인 창 — 수동 결제 처리 블록', (_label, modal, conflictKey) => {
  const nodes = flatten(modal);

  /**
   * 블록과 세 체크 노드가 있고, 해당 없는 항목은 숨긴다(미리보기 값으로만 판정).
   *
   * @effects not_applicable_items_are_hidden_in_preview
   */
  it('블록과 세 체크·복원·경고 노드가 있다', () => {
    for (const id of ['manual_paid_effects', 'manual_paid_stock', 'manual_paid_mileage', 'manual_paid_record',
      'manual_paid_restoration', 'manual_paid_restoration_conflict', 'manual_paid_pg_warning', 'manual_paid_never_paid', 'manual_paid_loading', 'manual_paid_error']) {
      expect(findById(nodes, id), id).toBeDefined();
    }
    for (const id of ['manual_paid_stock', 'manual_paid_mileage', 'manual_paid_record']) {
      expect(String(findById(nodes, id)?.if ?? '')).toContain('_global.manualPaidPreview');
    }
  });

  /**
   * 세 체크는 기본으로 켜져 있고(!== false), 바꾸면 setManualPaidOption 이 그 항목만 바꾼다.
   *
   * @effects manual_paid_transition_applies_three_items_by_default, unchecked_item_is_skipped_only
   */
  it('체크박스가 기본 체크이고 올바른 항목 키로 토글한다', () => {
    for (const [id, key] of [['manual_paid_stock', 'apply_stock_deduction'], ['manual_paid_mileage', 'apply_mileage_deduction'], ['manual_paid_record', 'record_paid_amount']]) {
      const label = findById(nodes, id)!;
      const input = flatten(label).find((n) => n.props?.type === 'checkbox')!;
      expect(input, id).toBeDefined();
      expect(String(input.props?.checked)).toContain(`manualPaidOptions?.${key} !== false`);
      const action = actionsOf(input).find((a: any) => a.handler === 'sirsoft-ecommerce.setManualPaidOption');
      expect(action, id).toBeDefined();
      expect(action.params?.optionKey).toBe(key);
    }
  });

  /**
   * 복원 안내에는 체크박스가 없다 — 운영자가 고를 항목이 아니다(Q26).
   *
   * @effects reactivation_revives_cancelled_rows_with_order
   */
  it('복원 블록에 체크박스가 없다', () => {
    const restoration = findById(nodes, 'manual_paid_restoration')!;
    expect(flatten(restoration).some((n) => n.props?.type === 'checkbox')).toBe(false);
  });

  /**
   * 미리보기 로딩·실패·쿠폰 충돌이면 확인 버튼을 잠근다 — 예고와 실행이 갈리지 않게.
   *
   * @effects reactivation_reclaims_coupons_or_blocks_on_conflict, preview_matches_execution
   */
  it('확인 버튼 잠금 식에 미리보기 로딩·오류·쿠폰 충돌이 들어 있다', () => {
    const disabled = confirmButtonDisabled(nodes);
    expect(disabled).toContain('manualPaidPreviewLoading');
    expect(disabled).toContain('manualPaidPreviewError');
    expect(disabled).toContain(conflictKey);
  });

  /**
   * 새 블록은 `_global` 만 읽는다 — 격리 scope 의 `$parent` 를 쓰지 않는다.
   *
   * @effects option_bulk_parent_sync_is_atomic_with_effects
   */
  it('새 블록에 $parent 참조가 없다', () => {
    const block = JSON.stringify(findById(nodes, 'manual_paid_effects'));
    expect(block).not.toContain('$parent');
  });
});

/**
 * 노드의 `if` 표현식을 `_global` 문맥으로 평가한다 (엔진과 같은 `{{ }}` 한 덩어리 표현식).
 *
 * @param node 평가할 노드
 * @param global `_global` 상태
 * @returns 표시 여부
 */
function visibleWith(node: Node, global: any): boolean {
  const body = String(node.if ?? 'true').replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return Boolean(new Function('_global', `return (${body});`)(global));
}

describe('주문상세 상품 일괄변경 확인 창 — 재활성 안내 문구', () => {
  const restoration = findById(flatten(detailModal), 'manual_paid_restoration')!;
  const texts = flatten(restoration).filter((n) => typeof (n as any).text === 'string');
  const partialHint = texts.find((n) => String((n as any).text).includes('manual_paid.restoration_hint_partial'));
  const fullHint = texts.find((n) => /manual_paid\.restoration_hint$/.test(String((n as any).text)));
  const previewWith = (totalAmount: number) => ({
    manualPaidPreview: { orders: [{ restoration: { applicable: true, totals_before: { total_amount: totalAmount } } }] },
  });

  /**
   * 일부 취소 주문(남은 주문 금액 > 0)을 되살리면 「취소로 0원이 된…」 전체 취소용 문구가 아니라 부분용 문구를 보인다.
   * 상품을 하나씩 모두 취소해 0원이 된 주문은 전체 취소와 같으므로 종전 문구다.
   *
   * @effects reactivation_hint_matches_partial_or_full_cancel
   */
  it('남은 주문 금액이 있으면 부분용 안내, 0원이면 종전 안내 하나만 보인다', () => {
    expect(partialHint, 'restoration_hint_partial 안내 노드가 없다').toBeDefined();
    expect(fullHint, 'restoration_hint 안내 노드가 없다').toBeDefined();

    expect(visibleWith(partialHint!, previewWith(57000))).toBe(true);
    expect(visibleWith(fullHint!, previewWith(57000))).toBe(false);

    expect(visibleWith(partialHint!, previewWith(0))).toBe(false);
    expect(visibleWith(fullHint!, previewWith(0))).toBe(true);
  });

  /**
   * 목록 일괄변경은 주문 단위(R-주문)라 늘 전체 취소 주문을 되살린다 — 종전 문구만 쓴다.
   *
   * @effects reactivation_hint_matches_partial_or_full_cancel
   */
  it('목록 일괄변경 확인 창은 부분용 안내를 쓰지 않는다', () => {
    expect(JSON.stringify(bulkModal)).not.toContain('restoration_hint_partial');
  });
});
