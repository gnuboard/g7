/**
 * @file shop-single-option-additional.test.tsx
 * @description 옵션이 하나뿐인 상품(수량 선택기로 사는 상품)에서 추가옵션을 고르고 담는 레이아웃을 잠근다.
 *
 * 배경: 상세 구매 카드는 추가옵션 칸을 「고른 옵션 줄」 안에만 그렸고, 담기·바로구매 본문의 옵션 없는 갈래도
 * 추가옵션을 싣지 않았다. 그래서 필수 추가옵션이 붙은 옵션 하나 상품은 상세에서 담기 422·바로구매 400 이었고,
 * 직접 입력(각인 문구 등)도 넣을 수 없었다. 하단 구매바는 선택 추가옵션이 있어도 시트 없이 곧바로 담았다.
 *
 * 검증 항목:
 * - 옵션 조각의 수량 칸 아래에 추가옵션 선택·직접 입력 칸이 있고, 같은 화면 상태를 쓴다
 * - 담기·바로구매 본문이 옵션 하나 상품의 추가옵션(직접 입력 포함)을 싣는다
 * - 필수 미선택·직접 입력 빈칸이면 요청 대신 경고한다
 * - 하단 구매바가 아직 다 고르지 않았거나 추가옵션이 있는데 시트를 열지 않았으면 시트를 연다
 * - 상품을 옮기거나 담은 뒤에는 앞 선택과 합계를 지운다
 *
 * @scenario-manifest tests/scenarios/shop-single-option-additional.yaml
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const layoutsDir = path.resolve(__dirname, '../../layouts');

function loadJson(relative: string): any {
  return JSON.parse(fs.readFileSync(path.join(layoutsDir, relative), 'utf-8'));
}

function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
  if (!node || typeof node !== 'object') return out;
  if (Array.isArray(node)) {
    node.forEach((n) => collect(n, match, out));
    return out;
  }
  if (match(node)) out.push(node);
  Object.values(node).forEach((v) => collect(v, match, out));
  return out;
}

function evaluate(expression: string, scope: Record<string, any>): any {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  const names = Object.keys(scope);
  return new Function(...names, `return (${body});`)(...names.map((n) => scope[n]));
}

const show = loadJson('shop/show.json');
const options = loadJson('partials/shop/detail/_purchase_options.json');
const modal = loadJson('partials/shop/detail/_modal_cart_added.json');
const byTestId = (root: any, id: string) => collect(root, (n) => n.props?.['data-testid'] === id)[0];

const groups = [
  { id: 39, is_required: true, values: [{ id: 90, price_adjustment: 0 }, { id: 91, price_adjustment: 2000 }] },
  { id: 40, is_required: true, values: [{ id: 95, price_adjustment: 0 }, { id: 96, price_adjustment: 3000, allow_custom_text: true }] },
];
const single = (extra: Record<string, any> = {}) => ({
  data: { id: 7, has_options: false, options: [{ id: 1 }], additional_options: groups, min_purchase_qty: 1, max_purchase_qty: 0, abilities: { can_order: true }, ...extra },
});
const needs = (product: any, _local: Record<string, any>) => !!evaluate(show.computed.purchaseNeedsSelection, { product, _local });

/** 이름 붙은 액션의 가드(when) 목록과 마지막 else 의 요청 본문 식을 돌려줍니다. */
const actionParts = (name: string) => {
  const conditions = show.named_actions[name].conditions;
  const guards = conditions.filter((c: any) => c.if);
  const api = collect(conditions.find((c: any) => !c.if), (n) => n.handler === 'apiCall')[0];
  return { guards, body: api.params.body };
};
/** 경고를 띄우는 가드 가운데 처음 참인 것의 문구 키(없으면 null — 요청으로 간다). */
const firstGuard = (name: string, product: any, _local: Record<string, any>) => {
  const hit = actionParts(name).guards.find((g: any) => evaluate(g.if, { product, _local }));
  if (!hit) return null;
  // 가드가 「눌러 봤음」 표시와 토스트를 묶어 둘 수 있다 — 경고 문구는 그 안의 토스트에서 읽는다
  const toast = hit.then.handler === 'sequence' ? hit.then.actions.find((a: any) => a.handler === 'toast') : hit.then;
  return toast?.params?.message ?? null;
};

describe('옵션 하나 상품 — 추가옵션 칸', () => {
  // @scenario case=c05
  // @effects single_option_additional_fields_rendered
  it('수량 칸 아래에 그룹마다 선택 칸이 있고, 직접 입력 값을 고르면 글자 칸이 보인다', () => {
    const box = byTestId(options, 'single-additional-options');
    expect(box).toBeDefined();
    expect(evaluate(box.if, { product: single() })).toBe(true);
    expect(evaluate(box.if, { product: single({ additional_options: [] }) })).toBe(false);

    const select = byTestId(box, 'add-option-single-{{addGroup.id}}');
    expect(select.props.value).toBe("{{_local.noOptionAdditionalSelections?.[addGroup.id] ?? ''}}");
    const onChange = select.actions.find((a: any) => a.type === 'change');
    expect(onChange.handler).toBe('sirsoft-basic.setNoOptionAdditionalOption');
    expect(onChange.params).toMatchObject({ additionalOptionId: '{{addGroup.id}}', valueId: '{{$event.target.value}}' });

    const text = byTestId(box, 'add-option-text-single-{{addGroup.id}}');
    const onInput = text.actions.find((a: any) => a.type === 'input');
    expect(onInput.handler).toBe('sirsoft-basic.setNoOptionAdditionalOption');
    expect(onInput.params.customText).toBe('{{$event.target.value}}');

    // 글자 칸의 부모는 고른 값이 직접 입력을 허용할 때만 보인다
    const textWrap = collect(box, (n) => (n.children ?? []).includes(text))[0]
      ?? collect(box, (n) => JSON.stringify(n.children ?? []).includes('add-option-text-single-') && typeof n.if === 'string')[0];
    const scope = (sel: Record<number, number>) => ({ addGroup: groups[1], _local: { noOptionAdditionalSelections: sel } });
    expect(evaluate(textWrap.if, scope({ 40: 96 }))).toBe(true);
    expect(evaluate(textWrap.if, scope({ 40: 95 }))).toBe(false);
  });

  // @scenario case=c06
  // @effects single_option_quantity_passes_additional_selection
  it('수량을 바꿀 때도 고른 추가옵션을 넘겨 합계에 남긴다', () => {
    const qty = collect(options, (n) => n.name === 'QuantitySelector' && String(n.props?.value).includes('noOptionQuantity'))[0];
    const onChange = qty.actions.find((a: any) => a.event === 'onChange');
    expect(onChange.handler).toBe('sirsoft-basic.updateNoOptionQuantity');
    expect(onChange.params.additionalOptionSelections).toBe('{{_local.noOptionAdditionalSelections ?? {}}}');
    expect(onChange.params.additionalOptionGroups).toBe('{{product.data?.additional_options ?? []}}');
  });
});

describe('옵션 하나 상품 — 담기·바로구매', () => {
  const filled = { noOptionQuantity: 2, noOptionAdditionalSelections: { 39: 91, 40: 96 }, noOptionAdditionalCustomTexts: { 40: 'PROBE' } };

  // @scenario case=c07
  // @effects single_option_request_carries_additional_selection
  it.each([
    ['purchaseAddToCart', (b: any) => b.items[0]],
    ['purchaseBuyNow', (b: any) => b.direct_items[0]],
  ])('%s — 본문이 고른 추가옵션과 직접 입력 글자를 싣는다', (name, line) => {
    const body = evaluate(actionParts(name as string).body, { product: single(), _local: filled });
    const item = (line as (b: any) => any)(body);

    expect(item.quantity).toBe(2);
    expect(item.additional_option_selections).toEqual([
      { additional_option_id: 39, value_id: 91, custom_text: undefined },
      { additional_option_id: 40, value_id: 96, custom_text: 'PROBE' },
    ]);
    // 추가옵션을 고르지 않았으면 빈 목록이다(종전 요청과 같은 뜻)
    const bare = (line as (b: any) => any)(evaluate(actionParts(name as string).body, { product: single({ additional_options: [] }), _local: { noOptionQuantity: 1 } }));
    expect(bare.additional_option_selections).toEqual([]);
  });

  // @scenario case=c08
  // @effects single_option_guards_required_and_custom_text
  it.each(['purchaseAddToCart', 'purchaseBuyNow'])('%s — 필수 미선택·직접 입력 빈칸이면 요청 대신 경고한다', (name) => {
    expect(firstGuard(name, single(), { noOptionAdditionalSelections: { 39: 91 } })).toBe('$t:shop.additional_option_required');
    expect(firstGuard(name, single(), { noOptionAdditionalSelections: { 39: 91, 40: 96 }, noOptionAdditionalCustomTexts: { 40: '  ' } })).toBe('$t:shop.additional_option_custom_text_required');
    expect(firstGuard(name, single(), filled)).toBeNull();
    // 여러 옵션 상품은 종전대로 줄 단위 선택으로만 판정한다(상품 하나의 선택은 보지 않는다)
    const multi = { data: { ...single().data, has_options: true, options: [{ id: 1 }, { id: 2 }] } };
    expect(firstGuard(name, multi, { selectedOptionItems: [{ optionId: 1, quantity: 1, additionalOptionSelections: { 39: 90, 40: 95 } }] })).toBeNull();
  });
});

describe('옵션 하나 상품 — 하단 구매바', () => {
  // @scenario case=c09
  // @effects single_option_bar_opens_sheet
  it('필수 미선택·직접 입력 빈칸이거나, 추가옵션이 있는데 시트를 한 번도 열지 않았으면 시트를 연다', () => {
    const optional = single({ additional_options: [{ id: 39, is_required: false, values: [{ id: 90 }, { id: 91 }] }] });

    // 선택 추가옵션만 있어도 첫 누름에는 고를 기회를 준다
    expect(needs(optional, {})).toBe(true);
    expect(needs(optional, { buySheetSeen: true })).toBe(false);
    // 필수 미선택·직접 입력 빈칸은 시트를 본 뒤에도 다 고르지 않은 것이다
    expect(needs(single(), { buySheetSeen: true, noOptionAdditionalSelections: { 39: 91 } })).toBe(true);
    expect(needs(single(), { buySheetSeen: true, noOptionAdditionalSelections: { 39: 91, 40: 96 }, noOptionAdditionalCustomTexts: { 40: '' } })).toBe(true);
    expect(needs(single(), { buySheetSeen: true, noOptionAdditionalSelections: { 39: 91, 40: 96 }, noOptionAdditionalCustomTexts: { 40: 'PROBE' } })).toBe(false);
    // 추가옵션이 없는 옵션 하나 상품은 시트 없이 곧바로 담는다(종전 동작)
    expect(needs(single({ additional_options: [] }), {})).toBe(false);
  });
});

describe('옵션 하나 상품 — 상태 비우기', () => {
  const keysOf = (node: any) => Object.keys(node.params ?? {});

  // @scenario case=c10
  // @effects single_option_selection_reset_on_navigate_and_after_add
  it('상품을 옮길 때·담은 뒤·담김 창에서 계속 둘러볼 때 앞 선택과 합계를 지운다(null 뒤 빈 값)', () => {
    const resets = [
      ['init', show.init_actions],
      ['담기 성공', collect(show.named_actions.purchaseAddToCart, (n) => n.handler === 'apiCall')[0].onSuccess],
      ['담김 창', modal],
    ] as const;

    for (const [where, root] of resets) {
      const sets = collect(root, (n) => n.handler === 'setState' && keysOf(n).includes('noOptionAdditionalSelections'));
      expect(sets.map((s: any) => s.params.noOptionAdditionalSelections), where).toEqual([null, {}]);
      expect(sets.some((s: any) => s.params.noOptionAdditionalCustomTexts === null), where).toBe(true);
      expect(sets.some((s: any) => keysOf(s).includes('noOptionTotalMultiCurrency') && s.params.noOptionTotalMultiCurrency === null), where).toBe(true);
    }
  });
});
