/**
 * @file adminOrderExtraFields.test.ts
 * @description 관리자 주문 상세의 「주문서 추가 항목」 구획
 *
 * 운영자가 주문서에 더한 항목과 다른 확장이 기여한 항목의 값은 주문 응답의 `extra_fields`
 * (`[{ key, label, section, display_value }]`, 값이 있는 것만)로 온다. 주문 정보 탭은 값이 하나라도 있을 때만
 * 구획을 그리고, 항목마다 이름(어느 구획에서 받았는지 포함)과 값을 읽기 전용으로 보인다.
 * 식은 실제 앱과 같은 식 엔진으로 계산한다.
 *
 * @scenario-manifest tests/scenarios/admin-order-detail-extra-fields.yaml
 */

import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

import orderInfoPartial from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json';
import ko from '../../../lang/partial/ko/admin/order.json';
import en from '../../../lang/partial/en/admin/order.json';

type Node = Record<string, any>;

const engine = new DataBindingEngine();

/** 식({{ }})을 식 엔진으로 계산한다 */
function evaluate(expression: string, context: Record<string, unknown>): any {
  expect(expression.startsWith('{{') && expression.endsWith('}}')).toBe(true);
  return engine.evaluateExpression(expression.slice(2, -2), context);
}

/** 자식 노드(children)만 따라 내려가며 모은다 */
function collect(input: Node | Node[] | undefined, out: Node[] = []): Node[] {
  if (!input) return out;
  for (const node of Array.isArray(input) ? input : [input]) {
    out.push(node);
    collect(node.children, out);
  }
  return out;
}

const nodes = collect(orderInfoPartial as Node);
const block = nodes.find((node) => node.id === 'admin_order_extra_fields')!;
const line = collect(block).find((node) => node.iteration?.item_var === 'extraField')!;

const extraFields = [
  { key: 'company', label: '회사명', section: 'orderer', display_value: '시르소프트' },
  { key: 'door_code', label: '공동현관 비밀번호', section: 'shipping', display_value: '1234#' },
];

describe('관리자 주문 상세의 주문서 추가 항목', () => {
  /**
   * @scenario case=block_visibility
   * @effects admin_order_extra_block_drawn_only_with_values
   */
  it('값이 하나라도 있을 때만 구획을 그린다', () => {
    expect(block).toBeDefined();
    expect(evaluate(block.if, { order: { data: { extra_fields: extraFields } } })).toBe(true);
    for (const context of [{ order: { data: { extra_fields: [] } } }, { order: { data: {} } }, { order: {} }, {}]) {
      expect(evaluate(block.if, context)).toBe(false);
    }
  });

  /**
   * @scenario case=rows
   * @effects admin_order_extra_rows_list_all_values_with_section
   */
  it('항목마다 한 줄을 그리고(줄의 id 는 항목 키를 따른다) 이름에 받은 구획을 붙인다', () => {
    expect(line.id).toBe('admin_order_extra_field_{{extraField.key}}');
    expect(evaluate(line.iteration.source, { order: { data: { extra_fields: extraFields } } })).toEqual(extraFields);
    expect(evaluate(line.iteration.source, { order: { data: {} } })).toEqual([]);

    const [label, value] = line.children as Node[];
    expect(value.text).toBe('{{extraField.display_value}}');
    expect(label.text).toContain('{{extraField.label}}');
    // 구획 이름은 번역 키로 고른다 — 주문자 구획이면 주문자, 그 밖은 배송지
    expect(label.text).toContain("extraField.section === 'orderer'");
    expect(label.text).toContain('sirsoft-ecommerce.admin.order.detail.order_info.extra_fields.section_orderer');
    expect(label.text).toContain('sirsoft-ecommerce.admin.order.detail.order_info.extra_fields.section_shipping');
  });

  /**
   * @scenario case=rows
   * @effects admin_order_extra_rows_have_dark_pairs_and_wrap
   */
  it('값 칸은 다크 짝을 갖고 긴 글이 넘치지 않는다', () => {
    const [label, value] = line.children as Node[];
    expect(value.props.className).toContain('ecommerce:dark:bg-gray-800');
    expect(value.props.className).toContain('ecommerce:dark:text-gray-100');
    expect(value.props.className).toContain('ecommerce:break-words');
    expect(label.props.className).toContain('ecommerce:break-words');
  });

  /**
   * @scenario case=translations
   * @effects admin_order_extra_translation_keys_exist
   */
  it('구획 제목과 구획 이름의 번역 키가 ko, en 에 있다', () => {
    const title = collect(block).find((node) => node.text === '$t:sirsoft-ecommerce.admin.order.detail.order_info.extra_fields.title');
    expect(title).toBeDefined();
    for (const lang of [ko, en] as Node[]) {
      const texts = lang.detail.order_info.extra_fields;
      expect(Object.keys(texts).sort()).toEqual(['section_orderer', 'section_shipping', 'title']);
      for (const text of Object.values(texts)) {
        expect(typeof text === 'string' && text.length > 0).toBe(true);
      }
    }
  });
});
