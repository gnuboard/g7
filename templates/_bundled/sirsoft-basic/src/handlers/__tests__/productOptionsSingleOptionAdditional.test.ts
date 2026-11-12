/**
 * @file productOptionsSingleOptionAdditional.test.ts
 * @description 옵션이 하나뿐인 상품(수량 선택기로 사는 상품)의 추가옵션 선택과 합계를 잠근다.
 *
 * 배경: 상세 구매 카드는 추가옵션 칸을 「고른 옵션 줄」 안에만 그려, 옵션이 하나뿐인 상품은 추가옵션을 고를 칸이
 * 없었다. 필수 추가옵션이 붙은 그런 상품은 상세 화면에서 담기 422·바로구매 400 으로 살 수 없었고(로컬 실측),
 * 직접 입력 추가옵션(각인 문구 등)도 넣을 수 없었다. 옵션 하나 상품의 추가옵션은 화면 상태
 * `noOptionAdditionalSelections`·`noOptionAdditionalCustomTexts` 에 두고, 합계 `noOptionTotalMultiCurrency` 에
 * 추가 금액을 더한다.
 *
 * @scenario-manifest tests/scenarios/shop-single-option-additional.yaml
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { setNoOptionAdditionalOptionHandler, updateNoOptionQuantityHandler } from '../productOptions';

const mockG7Core = {
  state: { get: vi.fn(() => ({})), getLocal: vi.fn(() => ({})), setLocal: vi.fn() },
  toast: { show: vi.fn(), warning: vi.fn() },
  t: vi.fn((key: string) => key),
};

const unitPrice = {
  KRW: { value: 12000, formatted: '12,000원' },
  USD: { value: 9, formatted: '$9.00' },
};

const groups = [
  {
    id: 39,
    name: '포장',
    is_required: true,
    values: [
      { id: 90, name: '포장 안 함', price_adjustment: 0, allow_custom_text: false, multi_currency_price_adjustment: { KRW: { price: 0, formatted: '0원' }, USD: { price: 0, formatted: '$0.00' } } },
      { id: 91, name: '선물 포장', price_adjustment: 2000, allow_custom_text: false, multi_currency_price_adjustment: { KRW: { price: 2000, formatted: '+2,000원' }, USD: { price: 1.5, formatted: '+$1.50' } } },
    ],
  },
  {
    id: 40,
    name: '각인',
    is_required: false,
    values: [{ id: 96, name: '이름 각인', price_adjustment: 3000, allow_custom_text: true, multi_currency_price_adjustment: { KRW: { price: 3000, formatted: '+3,000원' }, USD: { price: 2, formatted: '+$2.00' } } }],
  },
];

/** 핸들러를 부르고 마지막에 쓴 상태를 돌려줍니다. */
const run = (handler: any, params: Record<string, unknown>) => {
  const setState = vi.fn();
  handler({ handler: 'x', params } as any, { setState } as any);
  return setState.mock.calls[setState.mock.calls.length - 1][0];
};

describe('옵션 하나 상품의 추가옵션', () => {
  beforeEach(() => {
    (window as any).G7Core = mockG7Core;
  });
  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  // @scenario case=c01
  // @effects single_option_additional_selection_is_stored_and_priced
  it('추가옵션을 고르면 선택이 남고 합계에 추가 금액이 수량만큼 더해진다(통화별)', () => {
    const state = run(setNoOptionAdditionalOptionHandler, {
      additionalOptionId: 39,
      valueId: 91,
      selections: {},
      customTexts: {},
      additionalOptionGroups: groups,
      quantity: 2,
      multiCurrencyUnitPrice: unitPrice,
    });

    expect(state.noOptionAdditionalSelections).toEqual({ 39: 91 });
    expect(state.noOptionAdditionalTotal).toBe(2000);
    expect(state.noOptionAdditionalMultiCurrencyTotal.USD.value).toBe(1.5);
    expect(state.noOptionTotalMultiCurrency.KRW.value).toBe(28000);
    expect(state.noOptionTotalMultiCurrency.USD.value).toBe(21);
  });

  // @scenario case=c02
  // @effects single_option_custom_text_is_kept_and_cleared
  it('직접 입력 값을 고르고 글자를 넣으면 남고, 직접 입력이 없는 값으로 바꾸거나 선택을 풀면 지운다', () => {
    const picked = run(setNoOptionAdditionalOptionHandler, {
      additionalOptionId: 40, valueId: 96, selections: { 39: 90 }, customTexts: {}, additionalOptionGroups: groups, quantity: 1, multiCurrencyUnitPrice: unitPrice,
    });
    const typed = run(setNoOptionAdditionalOptionHandler, {
      additionalOptionId: 40, valueId: 96, customText: '홍길동', selections: picked.noOptionAdditionalSelections, customTexts: picked.noOptionAdditionalCustomTexts, additionalOptionGroups: groups, quantity: 1, multiCurrencyUnitPrice: unitPrice,
    });
    expect(typed.noOptionAdditionalSelections).toEqual({ 39: 90, 40: 96 });
    expect(typed.noOptionAdditionalCustomTexts).toEqual({ 40: '홍길동' });
    expect(typed.noOptionTotalMultiCurrency.KRW.value).toBe(15000);

    const cleared = run(setNoOptionAdditionalOptionHandler, {
      additionalOptionId: 40, valueId: '', selections: typed.noOptionAdditionalSelections, customTexts: typed.noOptionAdditionalCustomTexts, additionalOptionGroups: groups, quantity: 1, multiCurrencyUnitPrice: unitPrice,
    });
    expect(cleared.noOptionAdditionalSelections).toEqual({ 39: 90 });
    expect(cleared.noOptionAdditionalCustomTexts).toEqual({});
    expect(cleared.noOptionTotalMultiCurrency.KRW.value).toBe(12000);
  });

  // @scenario case=c03
  // @effects single_option_quantity_change_keeps_additional_price
  it('수량을 바꿔도 고른 추가옵션 금액이 합계에 남는다', () => {
    const state = run(updateNoOptionQuantityHandler, {
      newQuantity: 3,
      multiCurrencyUnitPrice: unitPrice,
      additionalOptionSelections: { 39: 91 },
      additionalOptionGroups: groups,
    });

    expect(state.noOptionQuantity).toBe(3);
    expect(state.noOptionTotalMultiCurrency.KRW.value).toBe(42000);
  });

  // @scenario case=c04
  // @effects single_option_quantity_without_additional_unchanged
  it('추가옵션 인자가 없으면 수량 × 단가 그대로다(종전 동작)', () => {
    const state = run(updateNoOptionQuantityHandler, { newQuantity: 2, multiCurrencyUnitPrice: unitPrice });

    expect(state.noOptionTotalMultiCurrency.KRW.value).toBe(24000);
  });
});
