/**
 * @file productOptionsAdditional.test.ts
 * @description 상품 추가옵션(유료 옵션) 핸들러 테스트
 *
 * 검증 대상:
 * - addSelectedItemIfCompleteHandler: 블럭 생성 시 기본 추가옵션(is_default) 자동 적용 + 추가금 반영 소계
 * - setBlockAdditionalOptionHandler: 블럭별 추가옵션 선택/해제 + 추가금×수량 재계산 + 다통화 환산
 * - updateSelectedItemQuantityHandler: 추가옵션 포함 단가 × 수량
 * - toAdditionalOptionSelectionsPayload: 백엔드 입력 형식 변환
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
  addSelectedItemIfCompleteHandler,
  setBlockAdditionalOptionHandler,
  updateSelectedItemQuantityHandler,
  toAdditionalOptionSelectionsPayload,
} from '../productOptions';

// G7Core mock (토스트 등)
beforeEach(() => {
  (window as any).G7Core = {
    toast: { warning: vi.fn() },
    t: (k: string) => k,
  };
});

const optionGroups = [
  { name: '색상', name_localized: '색상', values: ['블랙'], values_localized: ['블랙'] },
];

const options = [
  {
    id: 10,
    option_code: 'C-BLACK',
    option_values: [{ key: '색상', value: '블랙' }],
    option_values_localized: { 색상: '블랙' },
    option_name: '블랙',
    option_name_localized: '블랙',
    price_adjustment: 0,
    selling_price: 10000,
    selling_price_formatted: '10,000원',
    list_price: 10000,
    list_price_formatted: '10,000원',
    multi_currency_selling_price: {
      KRW: { value: 10000, formatted: '₩10,000' },
      USD: { value: 10, formatted: '$10.00' },
    },
    stock_quantity: 99,
    is_active: true,
  },
];

const additionalOptionGroups = [
  {
    id: 1,
    name: '각인',
    is_required: true,
    values: [
      { id: 100, name: '없음', price_adjustment: 0, is_default: true },
      { id: 101, name: '각인 추가', price_adjustment: 5000, is_default: false, allow_custom_text: true },
    ],
  },
  {
    id: 2,
    name: '포장',
    is_required: false,
    values: [{ id: 200, name: '선물포장', price_adjustment: 2000, is_default: false }],
  },
];

function captureSetState() {
  const calls: any[] = [];
  return {
    setState: (u: any) => calls.push(u),
    get last() {
      return calls[calls.length - 1];
    },
    calls,
  };
}

describe('addSelectedItemIfCompleteHandler — 추가옵션 기본 적용', () => {
  it('블럭 생성 시 is_default 선택지를 자동 적용한다 (각인=없음, 추가금 0)', () => {
    const ctx = captureSetState();
    addSelectedItemIfCompleteHandler(
      {
        handler: 'x',
        params: {
          productId: 1,
          optionGroups,
          options,
          currentSelection: {},
          selectedOptionItems: [],
          preferredCurrency: 'KRW',
          additionalOptionGroups,
          newGroupName: '색상',
          newValue: '블랙',
        },
      } as any,
      ctx as any
    );

    const item = ctx.last.selectedOptionItems[0];
    expect(item.additionalOptionSelections).toEqual({ 1: 100 });
    expect(item.additionalOptionsTotal).toBe(0);
    // 옵션가 10000 + 추가금 0 = 10000
    expect(item.totalPrice).toBe(10000);
  });

  // 비필수 그룹은 is_default 선택지가 있어도 자동 선택하지 않는다 ("선택하세요" 유지).
  // 관리자 추가옵션 선택지는 라디오라 그룹당 1개가 항상 is_default 로 시드되므로,
  // 비필수까지 자동 적용하면 원치 않은 선택/추가금이 강제로 붙는 회귀가 발생한다.
  it('비필수 그룹은 is_default 선택지가 있어도 자동 선택하지 않는다', () => {
    const groupsWithDefaultOnOptional = [
      {
        id: 1,
        name: '각인',
        is_required: true,
        values: [
          { id: 100, name: '없음', price_adjustment: 0, is_default: true },
          { id: 101, name: '각인 추가', price_adjustment: 5000, is_default: false },
        ],
      },
      {
        id: 2,
        name: '포장',
        is_required: false,
        // 첫 선택지가 is_default=true (관리자 폼 시드 패턴) — 그래도 미선택 유지되어야 함
        values: [
          { id: 200, name: '기본포장', price_adjustment: 0, is_default: true },
          { id: 201, name: '선물포장', price_adjustment: 2000, is_default: false },
        ],
      },
    ];

    const ctx = captureSetState();
    addSelectedItemIfCompleteHandler(
      {
        handler: 'x',
        params: {
          productId: 1,
          optionGroups,
          options,
          currentSelection: {},
          selectedOptionItems: [],
          preferredCurrency: 'KRW',
          additionalOptionGroups: groupsWithDefaultOnOptional,
          newGroupName: '색상',
          newValue: '블랙',
        },
      } as any,
      ctx as any
    );

    const item = ctx.last.selectedOptionItems[0];
    // 필수(각인=1)만 자동 선택, 비필수(포장=2)는 키 자체가 없어야 함 → "선택하세요" 유지
    expect(item.additionalOptionSelections).toEqual({ 1: 100 });
    expect(item.additionalOptionSelections).not.toHaveProperty('2');
    expect(item.additionalOptionsTotal).toBe(0);
  });

  // 필수 그룹이 하나도 없으면 자동 선택이 전혀 일어나지 않는다.
  it('필수 그룹이 없으면 추가옵션을 전혀 자동 선택하지 않는다', () => {
    const allOptionalGroups = [
      {
        id: 2,
        name: '포장',
        is_required: false,
        values: [{ id: 200, name: '기본포장', price_adjustment: 0, is_default: true }],
      },
    ];

    const ctx = captureSetState();
    addSelectedItemIfCompleteHandler(
      {
        handler: 'x',
        params: {
          productId: 1,
          optionGroups,
          options,
          currentSelection: {},
          selectedOptionItems: [],
          preferredCurrency: 'KRW',
          additionalOptionGroups: allOptionalGroups,
          newGroupName: '색상',
          newValue: '블랙',
        },
      } as any,
      ctx as any
    );

    const item = ctx.last.selectedOptionItems[0];
    expect(item.additionalOptionSelections).toEqual({});
    expect(item.additionalOptionsTotal).toBe(0);
  });
});

describe('setBlockAdditionalOptionHandler — 블럭별 추가옵션 선택', () => {
  it('각인 추가(+5000) 선택 시 추가금·소계·다통화가 재계산된다', () => {
    const block = {
      id: '블랙',
      optionId: 10,
      options: { 색상: '블랙' },
      optionValues: { 색상: '블랙' },
      quantity: 1,
      stock: 99,
      unitPrice: 10000,
      unitPriceFormatted: '10,000원',
      totalPrice: 10000,
      totalPriceFormatted: '10,000원',
      multiCurrencyUnitPrice: {
        KRW: { value: 10000, formatted: '₩10,000' },
        USD: { value: 10, formatted: '$10.00' },
      },
      multiCurrencyTotalPrice: {
        KRW: { value: 10000, formatted: '₩10,000' },
        USD: { value: 10, formatted: '$10.00' },
      },
      additionalOptionSelections: { 1: 100 },
      additionalOptionsTotal: 0,
    };
    const ctx = captureSetState();
    setBlockAdditionalOptionHandler(
      {
        handler: 'x',
        params: {
          itemIndex: 0,
          additionalOptionId: 1,
          valueId: 101,
          selectedOptionItems: [block],
          additionalOptionGroups,
          preferredCurrency: 'KRW',
        },
      } as any,
      ctx as any
    );

    const updated = ctx.last.selectedOptionItems[0];
    expect(updated.additionalOptionSelections).toEqual({ 1: 101 });
    expect(updated.additionalOptionsTotal).toBe(5000);
    expect(updated.totalPrice).toBe(15000);
    // USD 환산: 추가금 5000 × (10/10000) = 5 → 단가 10 + 5 = 15
    expect(updated.multiCurrencyTotalPrice.USD.value).toBe(15);
  });

  it('두 그룹 동시 선택 시 추가금이 합산된다 (각인+5000 + 포장+2000)', () => {
    const block = {
      quantity: 2,
      unitPrice: 10000,
      multiCurrencyUnitPrice: { KRW: { value: 10000, formatted: '' } },
      additionalOptionSelections: { 1: 101 },
      additionalOptionsTotal: 5000,
    };
    const ctx = captureSetState();
    setBlockAdditionalOptionHandler(
      {
        handler: 'x',
        params: {
          itemIndex: 0,
          additionalOptionId: 2,
          valueId: 200,
          selectedOptionItems: [block],
          additionalOptionGroups,
          preferredCurrency: 'KRW',
        },
      } as any,
      ctx as any
    );
    const updated = ctx.last.selectedOptionItems[0];
    expect(updated.additionalOptionsTotal).toBe(7000);
    // (10000 + 7000) × 2 = 34000
    expect(updated.totalPrice).toBe(34000);
  });

  it('직접입력 모드: customText 만 갱신하고 선택 value 는 유지한다', () => {
    const block = {
      quantity: 1,
      unitPrice: 10000,
      multiCurrencyUnitPrice: { KRW: { value: 10000, formatted: '' } },
      additionalOptionSelections: { 1: 101 },
      additionalOptionCustomTexts: {},
      additionalOptionsTotal: 5000,
    };
    const ctx = captureSetState();
    setBlockAdditionalOptionHandler(
      {
        handler: 'x',
        params: {
          itemIndex: 0,
          additionalOptionId: 1,
          valueId: 101,
          customText: '홍길동',
          selectedOptionItems: [block],
          additionalOptionGroups,
          preferredCurrency: 'KRW',
        },
      } as any,
      ctx as any
    );
    const updated = ctx.last.selectedOptionItems[0];
    expect(updated.additionalOptionCustomTexts).toEqual({ 1: '홍길동' });
    expect(updated.additionalOptionSelections).toEqual({ 1: 101 });
    // 직접입력은 가격 무관 — 추가금 그대로
    expect(updated.additionalOptionsTotal).toBe(5000);
  });

  it('직접입력 미허용 선택지로 변경 시 기존 customText 가 정리된다', () => {
    const block = {
      quantity: 1,
      unitPrice: 10000,
      multiCurrencyUnitPrice: { KRW: { value: 10000, formatted: '' } },
      additionalOptionSelections: { 1: 101 },
      additionalOptionCustomTexts: { 1: '홍길동' },
      additionalOptionsTotal: 5000,
    };
    const ctx = captureSetState();
    // value 100(없음, allow_custom_text 없음) 으로 변경
    setBlockAdditionalOptionHandler(
      {
        handler: 'x',
        params: {
          itemIndex: 0,
          additionalOptionId: 1,
          valueId: 100,
          selectedOptionItems: [block],
          additionalOptionGroups,
          preferredCurrency: 'KRW',
        },
      } as any,
      ctx as any
    );
    const updated = ctx.last.selectedOptionItems[0];
    expect(updated.additionalOptionCustomTexts).toEqual({});
  });

  it('플레이스홀더("") 선택 시 해당 그룹 선택이 해제된다', () => {
    const block = {
      quantity: 1,
      unitPrice: 10000,
      multiCurrencyUnitPrice: { KRW: { value: 10000, formatted: '' } },
      additionalOptionSelections: { 1: 101, 2: 200 },
      additionalOptionsTotal: 7000,
    };
    const ctx = captureSetState();
    setBlockAdditionalOptionHandler(
      {
        handler: 'x',
        params: {
          itemIndex: 0,
          additionalOptionId: 2,
          valueId: '',
          selectedOptionItems: [block],
          additionalOptionGroups,
          preferredCurrency: 'KRW',
        },
      } as any,
      ctx as any
    );
    const updated = ctx.last.selectedOptionItems[0];
    expect(updated.additionalOptionSelections).toEqual({ 1: 101 });
    expect(updated.additionalOptionsTotal).toBe(5000);
    expect(updated.totalPrice).toBe(15000);
  });
});

describe('updateSelectedItemQuantityHandler — 추가옵션 포함 수량 배수 (D6)', () => {
  it('추가금 포함 단가 × 수량으로 소계를 계산한다', () => {
    const block = {
      quantity: 1,
      unitPrice: 10000,
      multiCurrencyUnitPrice: { KRW: { value: 10000, formatted: '' } },
      additionalOptionSelections: { 1: 101 },
      additionalOptionsTotal: 5000,
    };
    const ctx = captureSetState();
    updateSelectedItemQuantityHandler(
      {
        handler: 'x',
        params: {
          itemIndex: 0,
          newQuantity: 3,
          selectedOptionItems: [block],
          preferredCurrency: 'KRW',
        },
      } as any,
      ctx as any
    );
    const updated = ctx.last.selectedOptionItems[0];
    // (10000 + 5000) × 3 = 45000
    expect(updated.totalPrice).toBe(45000);
    expect(updated.quantity).toBe(3);
  });
});

describe('toAdditionalOptionSelectionsPayload — 백엔드 입력 변환', () => {
  it('{groupId: valueId} → [{additional_option_id, value_id}]', () => {
    expect(toAdditionalOptionSelectionsPayload({ 1: 101, 2: 200 })).toEqual([
      { additional_option_id: 1, value_id: 101 },
      { additional_option_id: 2, value_id: 200 },
    ]);
  });

  it('빈 선택은 빈 배열', () => {
    expect(toAdditionalOptionSelectionsPayload(undefined)).toEqual([]);
    expect(toAdditionalOptionSelectionsPayload({})).toEqual([]);
  });

  it('customText 가 있으면 custom_text 를 포함한다', () => {
    expect(toAdditionalOptionSelectionsPayload({ 1: 101 }, { 1: '홍길동' })).toEqual([
      { additional_option_id: 1, value_id: 101, custom_text: '홍길동' },
    ]);
  });

  it('빈/공백 customText 는 custom_text 를 생략한다', () => {
    expect(toAdditionalOptionSelectionsPayload({ 1: 101 }, { 1: '   ' })).toEqual([
      { additional_option_id: 1, value_id: 101 },
    ]);
  });
});
