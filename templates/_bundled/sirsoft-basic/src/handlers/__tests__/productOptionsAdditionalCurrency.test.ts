/**
 * @file productOptionsAdditionalCurrency.test.ts
 * @description 추가옵션 추가금의 통화 환산 회귀 테스트
 *
 * 배경: 추가옵션 추가금은 쇼핑몰 **기본 통화** 기준으로 저장되는데, 총액 재계산이 그 값을
 * KRW 로 간주해 표시통화가 KRW 이면 환산 없이 그대로 더했다. 기본통화가 KRW 인 쇼핑몰에서만
 * 우연히 맞았고, 기본통화가 JPY 인 쇼핑몰을 KRW 로 보면
 * "환산된 상품가 + 환산 안 된 추가금" 이라는 통화가 섞인 합계가 표시됐다.
 *
 * 서버가 선택지마다 `multi_currency_price_adjustment` 를 내려주므로, 총액은 그 맵의
 * 표시통화 값을 써야 한다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  updateSelectedItemQuantityHandler,
  addSelectedItemIfCompleteHandler,
} from '../productOptions';

const mockG7Core = {
  state: { get: vi.fn(() => ({})), getLocal: vi.fn(() => ({})), setLocal: vi.fn() },
  toast: { show: vi.fn(), warning: vi.fn() },
  t: vi.fn((key: string) => key),
};

/**
 * 기본통화 JPY / 표시통화 KRW 인 쇼핑몰의 옵션 단가 맵.
 * 상품가 10,000 JPY = 95,000 KRW (1,000 JPY = 9,500 KRW 가정).
 */
const unitPriceMap = {
  JPY: { value: 10000, formatted: '¥10,000' },
  KRW: { value: 95000, formatted: '95,000원' },
};

/**
 * 추가금 5,000 JPY = 47,500 KRW 인 추가옵션 그룹.
 */
const additionalOptionGroups = [
  {
    id: 10,
    name: '포장 방식',
    is_required: false,
    values: [
      {
        id: 24,
        name: '프리미엄 포장',
        price_adjustment: 5000,
        is_default: false,
        multi_currency_price_adjustment: {
          JPY: { price: 5000, formatted: '+¥5,000', is_default: true },
          KRW: { price: 47500, formatted: '+47,500원', is_default: false },
        },
      },
    ],
  },
];

const makeItem = (overrides: any = {}) => ({
  id: 'opt1',
  optionId: 1,
  options: {},
  optionValues: {},
  quantity: 1,
  stock: 99,
  unitPrice: 10000,
  unitPriceFormatted: '¥10,000',
  totalPrice: 15000,
  totalPriceFormatted: '¥15,000',
  multiCurrencyUnitPrice: { ...unitPriceMap },
  multiCurrencyTotalPrice: { ...unitPriceMap },
  additionalOptionSelections: { 10: 24 },
  additionalOptionsTotal: 5000,
  additionalOptionsMultiCurrencyTotal: {
    JPY: { value: 5000 },
    KRW: { value: 47500 },
  },
  ...overrides,
});

describe('추가옵션 추가금 통화 환산 (기본통화 ≠ 표시통화)', () => {
  beforeEach(() => {
    (window as any).G7Core = mockG7Core;
  });
  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  it('표시통화 KRW 합계에 추가금이 환산되어 더해진다 (기준통화 값이 그대로 섞이지 않는다)', () => {
    const setState = vi.fn();

    updateSelectedItemQuantityHandler(
      {
        handler: 'x',
        params: {
          itemIndex: 0,
          newQuantity: 1,
          selectedOptionItems: [makeItem()],
          preferredCurrency: 'KRW',
          additionalOptionGroups,
        },
      } as any,
      { setState } as any
    );

    const arg = setState.mock.calls[0][0];

    // 기대: 95,000(상품가 환산) + 47,500(추가금 환산) = 142,500
    // 수정 전: 95,000 + 5,000(환산 안 된 기준통화 값) = 100,000
    expect(arg.selectedTotalMultiCurrency.KRW.value).toBe(142500);

    // 기본통화(JPY)는 원값 그대로 10,000 + 5,000 = 15,000
    expect(arg.selectedTotalMultiCurrency.JPY.value).toBe(15000);
  });

  it('수량이 늘면 환산된 추가금도 수량만큼 곱해진다', () => {
    const setState = vi.fn();

    updateSelectedItemQuantityHandler(
      {
        handler: 'x',
        params: {
          itemIndex: 0,
          newQuantity: 3,
          selectedOptionItems: [makeItem()],
          preferredCurrency: 'KRW',
          additionalOptionGroups,
        },
      } as any,
      { setState } as any
    );

    const arg = setState.mock.calls[0][0];

    // (95,000 + 47,500) × 3 = 427,500
    expect(arg.selectedTotalMultiCurrency.KRW.value).toBe(427500);
  });

  it('추가옵션을 선택하지 않으면 상품가만 환산되어 합계가 된다', () => {
    const setState = vi.fn();

    updateSelectedItemQuantityHandler(
      {
        handler: 'x',
        params: {
          itemIndex: 0,
          newQuantity: 2,
          selectedOptionItems: [
            makeItem({
              additionalOptionSelections: {},
              additionalOptionsTotal: 0,
              additionalOptionsMultiCurrencyTotal: undefined,
            }),
          ],
          preferredCurrency: 'KRW',
          additionalOptionGroups,
        },
      } as any,
      { setState } as any
    );

    const arg = setState.mock.calls[0][0];
    expect(arg.selectedTotalMultiCurrency.KRW.value).toBe(190000);
  });

  // 블럭 생성 시 자동 적용되는 기본 선택지는 **필수 그룹** 한정이므로 is_required: true 로 둔다
  it('선택 항목 생성 시 통화별 추가금 합계가 항목에 실린다', () => {
    const setState = vi.fn();

    addSelectedItemIfCompleteHandler(
      {
        handler: 'x',
        params: {
          productId: 28,
          optionGroups: [{ name: '길이', values: ['1m'] }],
          options: [
            {
              id: 1,
              option_values: { 길이: '1m' },
              stock_quantity: 10,
              is_active: true,
              selling_price: 10000,
              selling_price_formatted: '¥10,000',
              multi_currency_selling_price: {
                JPY: { price: 10000, formatted: '¥10,000' },
                KRW: { price: 95000, formatted: '95,000원' },
              },
            },
          ],
          currentSelection: { 길이: '1m' },
          selectedOptionItems: [],
          preferredCurrency: 'KRW',
          additionalOptionGroups: [
            {
              ...additionalOptionGroups[0],
              is_required: true,
              values: [{ ...additionalOptionGroups[0].values[0], is_default: true }],
            },
          ],
        },
      } as any,
      { setState } as any
    );

    const arg = setState.mock.calls[0][0];
    const item = arg.selectedOptionItems?.[0];

    expect(item).toBeDefined();
    expect(item.additionalOptionsMultiCurrencyTotal?.KRW?.value).toBe(47500);
    expect(arg.selectedTotalMultiCurrency.KRW.value).toBe(142500);
  });
});
