/**
 * @file shop-mobile-buy-bar.test.tsx
 * @description 상품 상세·장바구니의 좁은 화면 하단 구매바와 바텀시트를 잠근다.
 *
 * 검증 항목:
 * - 본문 단추·바텀시트 단추·하단 구매바가 같은 이름 붙은 액션을 부른다(동작이 한 벌이다)
 * - 「아직 다 고르지 않음」 네 갈래와 「수량이 한도 밖」이면 바가 담기 대신 바텀시트를 연다
 * - 다 골랐으면 바가 시트 없이 담기·바로구매 액션으로 간다
 * - 바텀시트 단추의 잠금 식이 본문 구매 카드 단추와 글자 그대로 같다
 * - 구매 카드와 바텀시트가 같은 옵션 조각을 포함한다
 * - 장바구니 바의 금액·잠금·동작이 요약 칸과 같다
 *
 * @scenario-manifest tests/scenarios/shop-mobile-buy-bar.yaml
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';
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

function evaluate(expression: string, scope: Record<string, any>): any {
  const body = expression.replace(/^\{\{/, '').replace(/\}\}$/, '');
  const names = Object.keys(scope);
  return new Function(...names, `return (${body});`)(...names.map((n) => scope[n]));
}

const show = loadJson('shop/show.json');
const card = loadJson('partials/shop/detail/_purchase_card.json');
const header = loadJson('partials/shop/detail/_header.json');
const cart = loadJson('shop/cart.json');
const summary = loadJson('partials/shop/_cart_summary.json');

const bar = collect(show, (n) => n.name === 'MobileBuyBar')[0];
const sheet = collect(show, (n) => n.name === 'BottomSheet')[0];
const byTestId = (root: any, id: string) => collect(root, (n) => n.props?.['data-testid'] === id)[0];
const actionOf = (node: any, key: string, value: string) => (node.actions ?? []).find((a: any) => a[key] === value);

const multi = (extra: Record<string, any> = {}) => ({
  data: { id: 1, has_options: true, options: [{ id: 1 }, { id: 2 }], additional_options: [], min_purchase_qty: 1, max_purchase_qty: 0, abilities: { can_order: true }, ...extra },
});
const row = (extra: Record<string, any> = {}) => ({ optionId: 1, quantity: 1, additionalOptionSelections: {}, additionalOptionCustomTexts: {}, ...extra });
const needs = (product: any, _local: Record<string, any>) => !!evaluate(show.computed.purchaseNeedsSelection, { product, _local });
const outOfRange = (product: any, _local: Record<string, any>) => !!evaluate(show.computed.purchaseQuantityOutOfRange, { product, _local });

describe('상품 상세 — 한 벌의 구매 동작', () => {
  // @scenario case=c13
  // @effects purchase_actions_are_named_and_shared
  it('본문 단추·바텀시트 단추·하단 구매바가 같은 이름 붙은 액션을 부른다', () => {
    expect(Object.keys(show.named_actions)).toEqual(expect.arrayContaining(['purchaseAddToCart', 'purchaseBuyNow', 'toggleProductWishlist']));

    expect(actionOf(byTestId(card, 'add-to-cart'), 'type', 'click').actionRef).toBe('purchaseAddToCart');
    expect(actionOf(byTestId(card, 'buy-now'), 'type', 'click').actionRef).toBe('purchaseBuyNow');
    expect(actionOf(byTestId(sheet, 'sheet-add-to-cart'), 'type', 'click').actionRef).toBe('purchaseAddToCart');
    expect(actionOf(byTestId(sheet, 'sheet-buy-now'), 'type', 'click').actionRef).toBe('purchaseBuyNow');
    expect(collect(header, (n) => n.actionRef === 'toggleProductWishlist')).toHaveLength(1);
    expect(actionOf(bar, 'event', 'onWish').actionRef).toBe('toggleProductWishlist');

    // 액션 본문 — 담기는 장바구니, 바로구매는 주문서 요청이다. 가드(옵션·필수 추가옵션·직접 입력)는 액션 안에 있다
    const add = JSON.stringify(show.named_actions.purchaseAddToCart);
    expect(add).toContain('/api/modules/sirsoft-ecommerce/cart');
    expect(add).toContain('$t:shop.additional_option_required');
    expect(add).toContain('$t:shop.additional_option_custom_text_required');
    expect(JSON.stringify(show.named_actions.purchaseBuyNow)).toContain('/api/modules/sirsoft-ecommerce/checkout');
    expect(JSON.stringify(show.named_actions.toggleProductWishlist)).toContain('/wishlist/toggle');
  });

  // @scenario case=c14
  // @effects purchase_card_and_sheet_share_options_partial
  it('구매 카드와 바텀시트가 같은 옵션 조각을 포함하고, 조각은 화면 상태를 그대로 쓴다', () => {
    const PARTIAL = 'partials/shop/detail/_purchase_options.json';
    expect(collect(card, (n) => n.partial === PARTIAL)).toHaveLength(1);
    expect(collect(sheet, (n) => n.partial === PARTIAL)).toHaveLength(1);

    const options = JSON.stringify(loadJson(PARTIAL));
    expect(options).toContain('sirsoft-basic.addSelectedItemIfComplete');
    expect(options).toContain('_local.selectedOptionItems');
    expect(options).not.toContain('stateTarget');

    // 펼치면 구매 카드 하나로 옵션 칸과 담기 요청이 모두 보인다(기존 시험이 이 모양을 본다)
    const expanded = JSON.stringify(loadExpandedLayout('partials/shop/detail/_purchase_card.json'));
    expect(expanded).toContain('sirsoft-basic.addSelectedItemIfComplete');
    expect(expanded).toContain('/api/modules/sirsoft-ecommerce/cart');
    expect(expanded).not.toContain('actionRef');
  });
});

describe('하단 구매바 — 다 고르지 않았으면 바텀시트', () => {
  // @scenario case=c15
  // @effects bar_opens_sheet_when_options_missing
  it('① 옵션을 골라야 하는데 고른 줄이 없으면 다 고르지 않은 것이다', () => {
    expect(needs(multi(), { selectedOptionItems: [] })).toBe(true);
    expect(needs(multi(), {})).toBe(true);
    expect(needs(multi(), { selectedOptionItems: [row()] })).toBe(false);
    // 옵션이 하나뿐이거나 없는 상품은 고를 것이 없다
    expect(needs({ data: { id: 1, has_options: false, options: [{ id: 1 }], additional_options: [] } }, {})).toBe(false);
  });

  // @scenario case=c16
  // @effects bar_opens_sheet_when_required_additional_option_missing
  it('② 필수 추가옵션이 빈 줄이 있으면 다 고르지 않은 것이다', () => {
    const product = multi({ additional_options: [{ id: 5, is_required: true, values: [{ id: 50 }] }] });

    expect(needs(product, { buySheetSeen: true, selectedOptionItems: [row()] })).toBe(true);
    expect(needs(product, { buySheetSeen: true, selectedOptionItems: [row({ additionalOptionSelections: { 5: 50 } })] })).toBe(false);
  });

  // @scenario case=c17
  // @effects bar_opens_sheet_when_custom_text_missing
  it('③ 직접 입력을 받는 선택지를 골랐는데 글자가 비면 다 고르지 않은 것이다', () => {
    const product = multi({ additional_options: [{ id: 5, is_required: true, values: [{ id: 50, allow_custom_text: true }] }] });
    const picked = { additionalOptionSelections: { 5: 50 } };

    expect(needs(product, { buySheetSeen: true, selectedOptionItems: [row({ ...picked, additionalOptionCustomTexts: { 5: '  ' } })] })).toBe(true);
    expect(needs(product, { buySheetSeen: true, selectedOptionItems: [row({ ...picked, additionalOptionCustomTexts: { 5: '홍길동' } })] })).toBe(false);
  });

  // @scenario case=c18
  // @effects bar_opens_sheet_once_when_additional_options_exist
  it('④ 추가옵션이 있는 상품은 시트를 한 번 열어 고를 기회를 준다', () => {
    const product = multi({ additional_options: [{ id: 5, is_required: false, values: [{ id: 50 }] }] });

    expect(needs(product, { selectedOptionItems: [row()] })).toBe(true);
    expect(needs(product, { buySheetSeen: true, selectedOptionItems: [row()] })).toBe(false);
  });

  // @scenario case=c19
  // @effects bar_opens_sheet_when_quantity_out_of_range
  it('고른 줄 수량의 합이 구매 한도 밖이면 수량을 고치도록 시트를 연다(구매 카드 단추를 잠그는 것과 같은 판정)', () => {
    expect(outOfRange(multi({ min_purchase_qty: 3 }), { selectedOptionItems: [row({ quantity: 2 })] })).toBe(true);
    expect(outOfRange(multi({ min_purchase_qty: 3 }), { selectedOptionItems: [row({ quantity: 3 })] })).toBe(false);
    expect(outOfRange(multi({ max_purchase_qty: 2 }), { selectedOptionItems: [row({ quantity: 2 }), row({ quantity: 1 })] })).toBe(true);

    // 구매 카드 단추의 잠금 식이 이 판정을 글자 그대로 품고 있다
    const expression = String(show.computed.purchaseQuantityOutOfRange).replace(/^\{\{/, '').replace(/\}\}$/, '');
    expect(String(byTestId(card, 'add-to-cart').props.disabled)).toContain(expression);
    expect(String(byTestId(card, 'buy-now').props.disabled)).toContain(expression);
  });

  // @scenario case=c20
  // @effects bar_routes_between_sheet_and_action
  it.each([
    ['onCart', 'cart', 'purchaseAddToCart'],
    ['onBuy', 'buy', 'purchaseBuyNow'],
  ])('%s — 다 고르지 않았으면 시트를 그 모드로 열고(요청 없음), 다 골랐으면 시트 없이 액션으로 간다', (event, mode, action) => {
    const handler = actionOf(bar, 'event', event);
    expect(handler.handler).toBe('conditions');
    const [open, direct] = handler.conditions;

    expect(open.if).toBe('{{$computed.purchaseNeedsSelection || $computed.purchaseQuantityOutOfRange}}');
    expect(open.then.handler).toBe('setState');
    expect(open.then.params).toMatchObject({ target: 'local', buySheetOpen: true, buySheetMode: mode, buySheetSeen: true });
    expect(JSON.stringify(open)).not.toContain('apiCall');
    expect(JSON.stringify(open)).not.toContain('actionRef');

    expect(direct.if).toBeUndefined();
    expect(direct.then).toEqual({ actionRef: action });
  });

  // @scenario case=c21
  // @effects bar_locks_only_on_hard_blocks
  it('바 단추는 진행 중·구매 불가·배송 불가일 때만 잠긴다 — 옵션을 아직 고르지 않았다고 잠기지 않는다', () => {
    const scope = (product: any, _local: Record<string, any> = {}) => ({ product, _local });

    expect(evaluate(bar.props.cartDisabled, scope(multi()))).toBe(false);
    expect(evaluate(bar.props.buyDisabled, scope(multi()))).toBe(false);
    expect(evaluate(bar.props.cartDisabled, scope(multi(), { isAddingToCart: true }))).toBe(true);
    expect(evaluate(bar.props.buyDisabled, scope(multi(), { isOrdering: true }))).toBe(true);
    expect(evaluate(bar.props.cartDisabled, scope(multi({ options: [] })))).toBe(true);
    expect(evaluate(bar.props.buyDisabled, scope(multi({ is_shippable_to_selected_country: false })))).toBe(true);

    // 판매 중이 아니면 구매 단추 대신 판매 상태 글자
    expect(evaluate(bar.props.soldOutLabel, { product: { data: { sales_status: 'sold_out', sales_status_label: '품절' } } })).toBe('품절');
    expect(evaluate(bar.props.soldOutLabel, { product: { data: { sales_status: 'on_sale', sales_status_label: '판매중' } } })).toBe('');
  });
});

describe('하단 구매바 — 주문 권한', () => {
  // @scenario case=c26
  // @effects bar_buy_locked_without_order_permission
  it('주문 권한이 없으면 바의 바로구매가 본문 단추처럼 잠긴다 — 장바구니 담기는 잠기지 않는다', () => {
    const scope = (product: any) => ({ product, _local: {} });

    expect(evaluate(bar.props.buyDisabled, scope(multi({ abilities: { can_order: false } })))).toBe(true);
    expect(evaluate(bar.props.buyDisabled, scope(multi({ abilities: undefined })))).toBe(true);
    expect(evaluate(bar.props.cartDisabled, scope(multi({ abilities: { can_order: false } })))).toBe(false);

    // 본문 바로구매 단추도 같은 조건으로 잠긴다
    expect(byTestId(card, 'buy-now').props.disabled).toContain('product.data?.abilities?.can_order !== true');
    expect(bar.props.buyDisabled).toContain('product.data?.abilities?.can_order !== true');
  });
});

describe('바텀시트', () => {
  // @scenario case=c27
  // @effects sheet_footer_stays_at_bottom
  it('합계와 단추는 시트 바닥에 붙어 있고, 옵션 영역만 스크롤된다', () => {
    const footer = collect(sheet, (n) => n.props?.['data-testid'] === 'sheet-footer')[0];
    expect(footer).toBeDefined();
    const classes = String(footer.props.className).split(/\s+/);
    expect(classes).toEqual(expect.arrayContaining(['sticky', '-bottom-4', '-mb-4', 'bg-white', 'dark:bg-gray-800']));
    // 합계 두 갈래와 두 단추가 모두 바닥 묶음 안에 있다
    expect(collect(footer, (n) => n.props?.['data-testid'] === 'sheet-purchase-total')).toHaveLength(2);
    expect(byTestId(footer, 'sheet-add-to-cart')).toBeDefined();
    expect(byTestId(footer, 'sheet-buy-now')).toBeDefined();
    // 옵션 조각은 바닥 묶음 밖(스크롤되는 쪽)이다
    expect(collect(footer, (n) => n.partial === 'partials/shop/detail/_purchase_options.json')).toHaveLength(0);
  });

  // @scenario case=c22
  // @effects sheet_buttons_match_card_locks
  it('시트 단추의 잠금 식이 본문 구매 카드 단추와 글자 그대로 같다', () => {
    expect(byTestId(sheet, 'sheet-add-to-cart').props.disabled).toBe(byTestId(card, 'add-to-cart').props.disabled);
    expect(byTestId(sheet, 'sheet-buy-now').props.disabled).toBe(byTestId(card, 'buy-now').props.disabled);
  });

  // @scenario case=c23
  // @effects sheet_open_state_and_close
  it('화면 상태로 열리고, 닫기·담기 성공이 그 상태를 끈다', () => {
    expect(sheet.props.open).toBe('{{!!_local.buySheetOpen}}');
    const close = actionOf(sheet, 'event', 'onClose');
    expect(close.handler).toBe('setState');
    expect(close.params).toMatchObject({ target: 'local', buySheetOpen: false });

    // 담기에 성공하면 시트를 닫고 담김 안내 창을 연다(닫기가 먼저)
    const add = JSON.stringify(show.named_actions.purchaseAddToCart);
    const closeAt = add.indexOf('"buySheetOpen":false');
    const modalAt = add.indexOf('cart_added_modal');
    expect(closeAt).toBeGreaterThan(-1);
    expect(modalAt).toBeGreaterThan(closeAt);

    // 시트의 합계는 구매 카드의 합계와 같은 식이다
    const totals = (root: any, id: string) => collect(root, (n) => n.props?.['data-testid'] === id).map((n) => n.text);
    expect(totals(sheet, 'sheet-purchase-total')).toEqual(totals(card, 'purchase-total'));
  });
});

describe('장바구니 — 하단 구매바', () => {
  const cartBar = collect(cart, (n) => n.name === 'MobileBuyBar')[0];
  const orderButton = collect(summary, (n) => n.id === 'cart_order_button')[0];

  // @scenario case=c24
  // @effects cart_bar_matches_summary
  it('금액·잠금 식이 요약 칸과 같고, 담긴 상품이 있을 때만 보인다', () => {
    expect(cartBar.props.variant).toBe('cart');
    expect(cartBar.props.primaryDisabled).toBe(orderButton.props.disabled);
    expect(JSON.stringify(summary)).toContain(JSON.stringify(cartBar.props.priceText).slice(1, -1));
    expect(cartBar.props.primaryLabel).toBe('$t:shop.cart.checkout_with_count|count={{(_local.selectedItems ?? []).length}}');
    expect(cartBar.if).toBe('{{(cartItems.data?.items ?? []).length > 0}}');
  });

  // @scenario case=c25
  // @effects cart_checkout_is_one_named_action
  it('요약 칸의 주문하기와 바의 주 단추가 같은 이름 붙은 액션을 부른다', () => {
    expect(actionOf(orderButton, 'type', 'click').actionRef).toBe('cartCheckout');
    expect(actionOf(cartBar, 'event', 'onPrimary').actionRef).toBe('cartCheckout');

    const action = cart.named_actions.cartCheckout;
    expect(action.handler).toBe('sequence');
    expect(action.actions[0]).toMatchObject({ handler: 'setState', params: { target: 'local', isOrdering: true } });
    expect(action.actions[1]).toMatchObject({ handler: 'apiCall', target: '/api/modules/sirsoft-ecommerce/checkout' });
    expect(JSON.stringify(action)).toContain('cart_unavailable_modal');
  });
});

describe('구매 옵션 칸의 오류 표시', () => {
  // @scenario case=c28
  // @effects purchase_guard_marks_missing_field_in_row
  it('필수 추가옵션·직접 입력 가드가 「눌러 봤음」을 남기고, 옵션 조각의 칸마다 그 값으로 사유 줄을 보인다', () => {
    const show = loadJson('shop/show.json');
    const options = loadJson('partials/shop/detail/_purchase_options.json');
    for (const name of ['purchaseAddToCart', 'purchaseBuyNow']) {
      const text = JSON.stringify(show.named_actions[name]);
      for (const key of ['shop.additional_option_required', 'shop.additional_option_custom_text_required']) {
        const at = text.indexOf(key);
        expect(at, name + ' ' + key).toBeGreaterThan(-1);
        // 같은 가드 안에서 토스트보다 먼저 시도 표시를 켠다
        const guard = text.slice(Math.max(0, text.lastIndexOf('"if"', at)), at);
        expect(guard, name + ' ' + key).toContain('"purchaseAttempted":true');
      }
    }
    const alerts = collect(options, (n) => n.props?.role === 'alert');
    const bound = alerts.filter((n) => String(n.if ?? '').includes('_local.purchaseAttempted'));
    // 옵션 둘 이상 상품의 줄(선택·직접 입력) + 옵션 하나 상품의 고정 줄(선택·직접 입력)
    expect(bound).toHaveLength(4);
    for (const n of bound) {
      expect(String(n.props?.className)).toMatch(/text-red-600/);
      expect(String(n.props?.className)).toMatch(/dark:text-red-400/);
    }
  });
});
