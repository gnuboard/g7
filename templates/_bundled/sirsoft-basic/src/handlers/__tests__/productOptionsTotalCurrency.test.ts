/**
 * @file productOptionsTotalCurrency.test.ts
 * @description 상품 옵션 총금액 통화별 포맷 맵 핸들러 테스트 (D2/D3)
 *
 * 배경: 레이아웃 "총 금액"이 표현식에서 핸들러를 호출할 수 없어(엔진 제약) KRW 로 고정되던 결함.
 * 핸들러가 통화별 합계 포맷 맵을 setState 로 노출하면 레이아웃은 단순 조회만 한다.
 *
 * - addSelectedItemIfComplete / updateSelectedItemQuantity / removeSelectedItem:
 *   selectedTotalMultiCurrency 에 통화별 합계 formatted 노출
 * - updateNoOptionQuantity: noOptionTotalMultiCurrency 에 단가×수량 통화별 formatted 노출
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import {
  updateSelectedItemQuantityHandler,
  removeSelectedItemHandler,
  updateNoOptionQuantityHandler,
} from '../productOptions';

const mockG7Core = {
  state: { get: vi.fn(() => ({})), getLocal: vi.fn(() => ({})), setLocal: vi.fn() },
  toast: { show: vi.fn(), warning: vi.fn() },
  t: vi.fn((key: string) => key),
};

const makeItem = (overrides: any = {}) => ({
  id: 'opt1',
  optionId: 1,
  options: {},
  optionValues: {},
  quantity: 1,
  stock: 99,
  unitPrice: 3000,
  unitPriceFormatted: '3,000원',
  totalPrice: 3000,
  totalPriceFormatted: '3,000원',
  multiCurrencyUnitPrice: {
    KRW: { value: 3000, formatted: '3,000원' },
    USD: { value: 2.55, formatted: '$2.55' },
  },
  multiCurrencyTotalPrice: {
    KRW: { value: 3000, formatted: '3,000원' },
    USD: { value: 2.55, formatted: '$2.55' },
  },
  ...overrides,
});

describe('productOptions 총금액 통화별 포맷 맵 (D2/D3)', () => {
  beforeEach(() => {
    (window as any).G7Core = mockG7Core;
  });
  afterEach(() => {
    vi.restoreAllMocks();
    delete (window as any).G7Core;
  });

  it('updateSelectedItemQuantity 가 selectedTotalMultiCurrency 통화별 합계를 setState 한다', () => {
    const setState = vi.fn();
    updateSelectedItemQuantityHandler(
      { handler: 'x', params: { itemIndex: 0, newQuantity: 2, selectedOptionItems: [makeItem()], preferredCurrency: 'USD' } } as any,
      { setState } as any
    );
    const arg = setState.mock.calls[0][0];
    expect(arg.selectedTotalMultiCurrency).toBeDefined();
    // 수량 2 → USD 합계 $5.10, KRW 6,000원 (서로 다른 통화 포맷)
    expect(arg.selectedTotalMultiCurrency.USD.formatted).toContain('$');
    expect(arg.selectedTotalMultiCurrency.KRW.formatted).not.toContain('$');
    expect(arg.selectedTotalMultiCurrency.USD.value).toBeCloseTo(5.1, 2);
  });

  it('removeSelectedItem 이 남은 항목으로 selectedTotalMultiCurrency 를 재계산한다', () => {
    const setState = vi.fn();
    removeSelectedItemHandler(
      { handler: 'x', params: { itemIndex: 0, selectedOptionItems: [makeItem(), makeItem({ id: 'opt2' })] } } as any,
      { setState } as any
    );
    const arg = setState.mock.calls[0][0];
    expect(arg.selectedOptionItems).toHaveLength(1);
    expect(arg.selectedTotalMultiCurrency.USD.value).toBeCloseTo(2.55, 2);
  });

  it('removeSelectedItem 이 마지막 항목 제거 시 빈 합계 맵을 만든다', () => {
    const setState = vi.fn();
    removeSelectedItemHandler(
      { handler: 'x', params: { itemIndex: 0, selectedOptionItems: [makeItem()] } } as any,
      { setState } as any
    );
    const arg = setState.mock.calls[0][0];
    expect(arg.selectedOptionItems).toHaveLength(0);
    expect(arg.selectedTotalMultiCurrency).toEqual({});
  });

  it('updateNoOptionQuantity 가 단가×수량 통화별 noOptionTotalMultiCurrency 를 setState 한다', () => {
    const setState = vi.fn();
    updateNoOptionQuantityHandler(
      {
        handler: 'x',
        params: {
          newQuantity: 3,
          multiCurrencyUnitPrice: {
            KRW: { value: 10000, formatted: '10,000원' },
            USD: { value: 8.5, formatted: '$8.50' },
          },
        },
      } as any,
      { setState } as any
    );
    const arg = setState.mock.calls[0][0];
    expect(arg.noOptionQuantity).toBe(3);
    expect(arg.noOptionTotalMultiCurrency.USD.value).toBeCloseTo(25.5, 2);
    expect(arg.noOptionTotalMultiCurrency.USD.formatted).toContain('$');
    expect(arg.noOptionTotalMultiCurrency.KRW.formatted).not.toContain('$');
  });
});
