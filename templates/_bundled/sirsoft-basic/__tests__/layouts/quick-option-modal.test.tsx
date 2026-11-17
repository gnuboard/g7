/**
 * @file quick-option-modal.test.tsx
 * @description 상품 카드의 옵션 선택 창을 잠근다.
 *
 * 검증 항목:
 * - 창이 사용자 화면 상속원에 한 번 선언된다(카드는 여러 화면에 나온다)
 * - 창 상태 참조가 전부 창 고유 전역 이름 공간(`_global.quickOptionModal`)이다 — 화면 `_local` 0건
 * - 옵션 핸들러 호출이 모두 그 전역 이름 공간에 쓰게 돼 있다
 * - 여러 조합을 쌓으면 한 번의 담기 요청에 줄 수만큼 실린다(상세 구매 카드와 같은 계산)
 * - 필수 추가옵션을 고르지 않았으면 요청을 보내지 않는다
 *
 * @scenario-manifest tests/scenarios/product-card-quick-actions.yaml
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';
import { addSelectedItemIfCompleteHandler } from '../../src/handlers/productOptions';
import { loadExpandedLayout } from '../helpers/inlineNamedActions';

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

/** `{{식}}` 한 덩어리를 주어진 전역 상태로 계산한다 */
function evaluate(expression: string, _global: Record<string, any>): any {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  return new Function('_global', `return (${body});`)(_global);
}

const modal = loadJson('partials/shop/_modal_quick_option.json');
const text = JSON.stringify(modal);
const HANDLERS = [
  'sirsoft-basic.addSelectedItemIfComplete',
  'sirsoft-basic.updateSelectedItemQuantity',
  'sirsoft-basic.setBlockAdditionalOption',
  'sirsoft-basic.removeSelectedItem',
];

const optionGroups = [{ name: '색상', name_localized: '색상', values: ['블랙', '화이트'], values_localized: ['블랙', '화이트'] }];
const options = ['블랙', '화이트'].map((color, i) => ({
  id: 10 + i,
  option_code: `C-${i}`,
  option_values: [{ key: '색상', value: color }],
  option_values_localized: { 색상: color },
  option_name: color,
  price_adjustment: 0,
  selling_price: 10000,
  selling_price_formatted: '10,000원',
  list_price: 10000,
  list_price_formatted: '10,000원',
  stock_quantity: 5,
  is_active: true,
}));

/** 전역 상태 대역 — 점 표기 키를 창 이름 공간에 반영한다 */
function makeGlobal(detail: Record<string, any>): Record<string, any> {
  const _global: Record<string, any> = { cartKey: 'ck', quickOptionModal: { detail, currentSelection: {}, selectedOptionItems: [] } };
  (window as any).G7Core = {
    t: (k: string) => k,
    toast: { warning: vi.fn() },
    state: {
      get: () => _global,
      set: (updates: Record<string, any>) => {
        for (const [key, value] of Object.entries(updates)) {
          const [root, leaf] = key.split('.');
          _global[root][leaf] = value;
        }
      },
    },
  };
  return _global;
}

function pick(_global: Record<string, any>, color: string, detail: Record<string, any>): void {
  addSelectedItemIfCompleteHandler(
    {
      handler: 'x',
      params: {
        productId: detail.id,
        optionGroups,
        options,
        currentSelection: {},
        selectedOptionItems: _global.quickOptionModal.selectedOptionItems,
        preferredCurrency: 'KRW',
        additionalOptionGroups: detail.additional_options,
        newGroupName: '색상',
        newValue: color,
        stateTarget: 'global',
        statePath: 'quickOptionModal',
      },
    },
    {},
  );
}

describe('상품 카드 옵션 선택 창', () => {
  beforeEach(() => {
    (window as any).G7Core = undefined;
  });

  // @scenario case=c26
  // @effects quick_option_modal_declared_once_in_base
  it('사용자 화면 상속원에 쇼핑몰이 켜져 있을 때만 한 번 선언된다', () => {
    const base = fs.readFileSync(path.join(layoutsDir, '_user_base.g7.html'), 'utf-8');
    const declarations = base.match(/<modal [^>]*src="partials\/shop\/_modal_quick_option\.g7\.html"[^>]*>/g) ?? [];
    expect(declarations).toHaveLength(1);

    const others = ['shop/index.g7.html', 'shop/category.g7.html', 'shop/show.g7.html', 'search/index.g7.html'].filter((f) =>
      fs.existsSync(path.join(layoutsDir, f)) && fs.readFileSync(path.join(layoutsDir, f), 'utf-8').includes('_modal_quick_option'),
    );
    expect(others).toEqual([]);

    // 쇼핑몰이 꺼진 사이트에서는 창 본문을 그리지 않는다
    const root = collect(modal, (n) => n.name === 'Modal')[0];
    expect(String(root.if)).toContain("_global.activeModules?.includes('sirsoft-ecommerce') ?? true");
    expect(text).toContain('quick_option_modal');
  });

  // @scenario case=c27
  // @effects quick_option_modal_state_is_global_namespace
  it('창 상태 참조가 전부 창 고유 전역 이름 공간이고 화면 상태·상세 데이터소스를 읽지 않는다', () => {
    // (name_localized 같은 필드 이름은 제외하고 화면 상태 참조만 센다)
    expect(text).not.toMatch(/(?<![A-Za-z])_local(?![A-Za-z])/);
    expect(text).not.toContain('product.data');
    expect(text).not.toContain('$computed');
    expect(text).toContain('_global.quickOptionModal?.detail');
    expect(text).toContain('_global.quickOptionModal?.selectedOptionItems');

    const localWrites = collect(modal, (n) => n.handler === 'setState' && n.params?.target !== 'global');
    expect(localWrites).toEqual([]);
  });

  // @scenario case=c28
  // @effects quick_option_modal_reuses_option_handlers
  it('상세 구매 카드와 같은 옵션 핸들러를 쓰고, 모두 전역 이름 공간에 쓰게 돼 있다', () => {
    const calls = collect(modal, (n) => typeof n.handler === 'string' && n.handler.startsWith('sirsoft-basic.'));
    const used = new Set(calls.map((c) => c.handler));
    for (const handler of HANDLERS) expect(used.has(handler), handler).toBe(true);
    for (const call of calls) {
      expect(call.params.stateTarget, call.handler).toBe('global');
      expect(call.params.statePath, call.handler).toBe('quickOptionModal');
    }

    // 줄별 추가옵션·수량 계산 인자가 구매 카드와 같다(참조 이름만 다르다)
    const card = loadExpandedLayout('partials/shop/detail/_purchase_card.json');
    const toModal = (s: string) =>
      s.replace(/product\.data\?\./g, '_global.quickOptionModal?.detail?.').replace(/_local\./g, '_global.quickOptionModal?.');
    for (const handler of ['sirsoft-basic.setBlockAdditionalOption', 'sirsoft-basic.updateSelectedItemQuantity', 'sirsoft-basic.removeSelectedItem']) {
      const cardCall = collect(card, (n) => n.handler === handler)[0];
      const modalCall = collect(modal, (n) => n.handler === handler)[0];
      const { stateTarget: _t, statePath: _p, ...modalParams } = modalCall.params;
      expect(modalParams, handler).toEqual(JSON.parse(toModal(JSON.stringify(cardCall.params))));
    }
  });

  // @scenario case=c29
  // @effects quick_option_modal_posts_all_rows
  it('두 조합을 쌓으면 한 번의 담기 요청에 두 줄이 실리고, 줄 계산은 구매 카드의 식과 같다', () => {
    const detail = { id: 7, has_options: true, option_groups: optionGroups, options, additional_options: [], min_purchase_qty: 1, max_purchase_qty: 0 };
    const _global = makeGlobal(detail);
    pick(_global, '블랙', detail);
    pick(_global, '화이트', detail);
    expect(_global.quickOptionModal.selectedOptionItems).toHaveLength(2);

    const apiCalls = collect(modal, (n) => n.handler === 'apiCall');
    expect(apiCalls).toHaveLength(1);
    const call = apiCalls[0];
    expect(call.target).toBe('/api/modules/sirsoft-ecommerce/cart');
    expect(call.params.method).toBe('POST');
    expect(call.auth_mode).toBe('optional');
    expect(call.params.headers).toEqual({ 'X-Cart-Key': '{{_global.cartKey}}' });

    const body = evaluate(call.params.body, _global);
    expect(body.product_id).toBe(7);
    expect(body.items).toEqual([
      { product_option_id: 10, quantity: 1, additional_option_selections: [] },
      { product_option_id: 11, quantity: 1, additional_option_selections: [] },
    ]);

    // 구매 카드의 줄 → 요청 항목 변환식을 그대로 쓴다
    const card = loadExpandedLayout('partials/shop/detail/_purchase_card.json');
    const cardBody: string = collect(card, (n) => n.handler === 'apiCall' && n.target === '/api/modules/sirsoft-ecommerce/cart')[0].params.body;
    const rowMap = cardBody.match(/\.map\(item => \(\{ product_option_id: .*?\}\)\) \}\)\)/)![0];
    expect(call.params.body).toContain(rowMap);

    // 성공하면 장바구니 수를 갱신하고 창을 닫는다
    const success = JSON.stringify(call.onSuccess);
    expect(success).toContain('cartCount');
    expect(success).toContain('closeModal');
    expect(success).toContain('$t:shop.cart_added');
  });

  // @scenario case=c30
  // @effects quick_option_modal_blocks_missing_required_additional_option
  it('필수 추가옵션을 고르지 않은 줄이 있으면 요청 대신 안내하고 그 줄에 사유를 표시한다', () => {
    const additional = [{ id: 1, name: '포장', is_required: true, values: [{ id: 100, name: '선물 포장', price_adjustment: 0, is_default: false }] }];
    const detail = { id: 7, has_options: true, option_groups: optionGroups, options, additional_options: additional, min_purchase_qty: 1, max_purchase_qty: 0 };
    const _global = makeGlobal(detail);
    pick(_global, '블랙', detail);

    const conditions = collect(modal, (n) => Array.isArray(n.conditions) && JSON.stringify(n).includes('/api/modules/sirsoft-ecommerce/cart'))[0];
    const guards = conditions.conditions.filter((c: any) => c.if);
    const firstHit = conditions.conditions.find((c: any) => !c.if || evaluate(c.if, _global));
    expect(JSON.stringify(firstHit)).toContain('$t:shop.additional_option_required');
    expect(JSON.stringify(firstHit)).not.toContain('apiCall');
    // 어느 줄인지 보이도록 「눌러 봤음」 표시를 남긴다
    expect(JSON.stringify(firstHit)).toContain('quickOptionModal.attempted');
    expect(guards.length).toBeGreaterThanOrEqual(3);

    // 고르면 요청 갈래로 간다
    _global.quickOptionModal.selectedOptionItems[0].additionalOptionSelections = { 1: 100 };
    const afterPick = conditions.conditions.find((c: any) => !c.if || evaluate(c.if, _global));
    expect(JSON.stringify(afterPick)).toContain('apiCall');

    // 줄 안 사유 표시
    const hints = collect(modal, (n) => typeof n.if === 'string' && n.if.includes('quickOptionModal?.attempted') && n.if.includes('is_required'));
    expect(hints.length).toBeGreaterThan(0);
    expect(JSON.stringify(hints[0])).toMatch(/text-red-\d+ dark:text-red-\d+/);
  });

  // @scenario case=c31
  // @effects wishlist_list_card_has_no_quick_actions
  it('찜 목록의 카드는 같은 자리에 찜 해제 단추가 있어 카드 단추를 끈다', () => {
    const list = loadJson('partials/mypage/wishlist/_list.json');
    const cards = collect(list, (n) => n.name === 'ProductCard');
    expect(cards.length).toBeGreaterThan(0);
    for (const card of cards) expect(card.props.showActions).toBe(false);
  });
});
