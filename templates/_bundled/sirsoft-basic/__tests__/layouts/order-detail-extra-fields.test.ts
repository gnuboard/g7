/**
 * @file order-detail-extra-fields.test.ts
 * @description 주문 상세(회원과 비회원이 함께 쓰는 조각)가 주문 응답의 `extra_fields` 를 구획별로 보이는지
 *
 * 운영자가 주문서에 더한 항목과 다른 확장이 기여한 항목의 값은 주문 응답의 `extra_fields`
 * (`[{ key, label, section, display_value }]`, 값이 있는 것만)로 온다. 주문자 구획의 값은 주문자 카드에,
 * 그 밖의 값은 배송지 카드에 「라벨, 값」 줄로 그리고, 값이 없으면 줄도 묶음도 그리지 않는다.
 * 식은 실제 앱과 같은 식 엔진으로 계산한다.
 *
 * @scenario-manifest tests/scenarios/order-detail-extra-fields.yaml
 */

import { describe, it, expect } from 'vitest';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

import ordererPartial from '../../layouts/partials/mypage/orders/_orderer.json';
import shippingPartial from '../../layouts/partials/mypage/orders/_shipping.json';

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

const extraFields = [
  { key: 'company', label: '회사명', section: 'orderer', display_value: '시르소프트' },
  { key: 'door_code', label: '공동현관 비밀번호', section: 'shipping', display_value: '1234#' },
  { key: 'office', label: '사무실 주소', section: 'shipping', display_value: '(06236) 서울시 강남구 역삼동 5층' },
];

const orderWith = (fields: unknown) => ({ order: { data: { extra_fields: fields } } });
const orderWithoutKey = { order: { data: {} } };

const cases = [
  { name: '주문자 카드', partial: ordererPartial as Node, section: 'orderer', blockId: 'order_detail_extra_fields_orderer' },
  { name: '배송지 카드', partial: shippingPartial as Node, section: 'shipping', blockId: 'order_detail_extra_fields_shipping' },
] as const;

describe('주문 상세의 주문서 추가 항목', () => {
  for (const { name, partial, section, blockId } of cases) {
    const nodes = collect(partial);
    const block = nodes.find((node) => node.id === blockId)!;
    const line = collect(block).find((node) => node.iteration?.item_var === 'extraField')!;

    describe(name, () => {
      /**
       * @scenario case=section_lines
       * @effects order_detail_lists_extra_fields_of_its_section
       */
      it('자기 구획의 값만 「라벨, 값」 줄로 그린다 (줄의 id 는 항목 키를 따른다)', () => {
        expect(block).toBeDefined();
        expect(line.id).toBe('order_detail_extra_field_{{extraField.key}}');

        const expected = extraFields.filter((field) => field.section === section);
        expect(evaluate(block.if, orderWith(extraFields))).toBe(true);
        expect(evaluate(line.iteration.source, orderWith(extraFields))).toEqual(expected);

        const [label, value] = line.children as Node[];
        expect(label.text).toBe('{{extraField.label}}');
        expect(value.text).toBe('{{extraField.display_value}}');
      });

      /**
       * @scenario case=hidden_without_values
       * @effects order_detail_extra_block_hidden_without_values
       */
      it('값이 없으면 묶음도 줄도 그리지 않는다 (키가 없는 응답, 빈 목록, 다른 구획의 값만 있을 때)', () => {
        const otherSection = extraFields.filter((field) => field.section !== section);
        for (const context of [orderWithoutKey, orderWith([]), orderWith(null), orderWith(otherSection)]) {
          expect(evaluate(block.if, context)).toBe(false);
          expect(evaluate(line.iteration.source, context)).toEqual([]);
        }
      });

      /**
       * @scenario case=row_style
       * @effects order_detail_extra_rows_match_card_rows_with_dark_pairs
       */
      it('줄은 카드의 다른 줄과 같은 칸 너비를 쓰고, 긴 글이 넘치지 않으며, 다크 짝과 좁은 화면 글자 크기를 갖는다', () => {
        const [label, value] = line.children as Node[];
        // 카드의 기존 줄(라벨 칸 w-28, 좁은 화면 w-20)과 같은 너비
        expect(label.props.className).toContain('w-28');
        expect(label.responsive.portable.props.className).toContain('w-20');
        expect(value.props.className).toContain('min-w-0');
        for (const part of [label, value]) {
          expect(part.props.className).toMatch(/dark:text-/);
          expect(part.props.className).toContain('break-words');
          expect(part.responsive.portable.props.className).toContain('text-sm');
          expect(part.responsive.portable.props.className).toMatch(/dark:text-/);
          expect(part.responsive.portable.props.className).toContain('break-words');
        }
      });

      /**
       * @scenario case=placement
       * @effects order_detail_extra_block_follows_builtin_rows
       */
      it('묶음은 카드의 기존 줄 묶음 안에서 맨 끝에 온다', () => {
        const parent = nodes.find((node) => (node.children ?? []).includes(block))!;
        expect(parent.props.className).toContain('space-y-3');
        expect(parent.children[parent.children.length - 1]).toBe(block);
      });
    });
  }

  /**
   * @scenario case=section_lines
   * @effects order_detail_sections_do_not_share_ids
   */
  it('두 카드의 묶음 id 는 서로 다르다 (한 화면에 함께 그려진다)', () => {
    const ids = cases.map(({ partial, blockId }) => collect(partial).filter((node) => node.id === blockId).length);
    expect(ids).toEqual([1, 1]);
    expect(JSON.stringify(ordererPartial)).not.toContain('order_detail_extra_fields_shipping');
    expect(JSON.stringify(shippingPartial)).not.toContain('order_detail_extra_fields_orderer');
  });
});
