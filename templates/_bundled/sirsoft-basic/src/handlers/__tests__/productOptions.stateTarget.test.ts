/**
 * @file productOptions.stateTarget.test.ts
 * @description 옵션 선택 핸들러의 「상태 쓰기 대상」 인자를 잠근다.
 *
 * 상세 구매 카드는 인자 없이 화면 `_local` 에 쓰고(종전 그대로), 계속 마운트되는 옵션 선택 창은
 * `stateTarget="global" statePath="quickOptionModal"` 로 같은 키를 `_global.quickOptionModal` 아래에 쓴다.
 *
 * @scenario-manifest tests/scenarios/product-card-quick-actions.yaml
 */

import { describe, it, expect, vi, beforeEach } from 'vitest';
import {
  addSelectedItemIfCompleteHandler,
  updateSelectedItemQuantityHandler,
  removeSelectedItemHandler,
  setBlockAdditionalOptionHandler,
  updateNoOptionQuantityHandler,
  buildSelectedItemFromOption,
} from '../productOptions';

const optionGroups = [{ name: '색상', name_localized: '색상', values: ['블랙', '화이트'], values_localized: ['블랙', '화이트'] }];

const makeOption = (id: number, color: string) => ({
  id,
  option_code: `C-${id}`,
  option_values: [{ key: '색상', value: color }],
  option_values_localized: { 색상: color },
  option_name: color,
  option_name_localized: color,
  price_adjustment: 0,
  selling_price: 10000,
  selling_price_formatted: '10,000원',
  list_price: 10000,
  list_price_formatted: '10,000원',
  multi_currency_selling_price: { KRW: { value: 10000, formatted: '10,000원' } },
  stock_quantity: 5,
  is_active: true,
});

const options = [makeOption(10, '블랙'), makeOption(11, '화이트')];

let globalSet: ReturnType<typeof vi.fn>;
let warning: ReturnType<typeof vi.fn>;

beforeEach(() => {
  globalSet = vi.fn();
  warning = vi.fn();
  (window as any).G7Core = { toast: { warning }, t: (k: string) => k, state: { set: globalSet, get: () => ({}) } };
});

const addParams = (extra: Record<string, unknown> = {}) => ({
  productId: 1,
  optionGroups,
  options,
  currentSelection: {},
  selectedOptionItems: [],
  preferredCurrency: 'KRW',
  additionalOptionGroups: [],
  newGroupName: '색상',
  newValue: '블랙',
  ...extra,
});

describe('옵션 핸들러 — 상태 쓰기 대상', () => {
  // @scenario case=c21
  // @effects option_handlers_write_local_by_default
  it('인자가 없으면 종전처럼 화면 상태(context.setState)에만 쓴다', () => {
    const setState = vi.fn();
    addSelectedItemIfCompleteHandler({ handler: 'x', params: addParams() }, { setState });

    expect(globalSet).not.toHaveBeenCalled();
    expect(setState).toHaveBeenCalledTimes(2);
    const last = setState.mock.calls[1][0];
    expect(last.currentSelection).toEqual({});
    expect(last.selectedOptionItems).toHaveLength(1);
    expect(last.selectedOptionItems[0].optionId).toBe(10);
    expect(last.__mergeMode).toBe('shallow');
  });

  // @scenario case=c22
  // @effects option_handlers_write_global_namespace_when_asked
  it('global + 경로를 주면 같은 키를 그 전역 이름 공간 아래에 쓰고 화면 상태는 건드리지 않는다', () => {
    const setState = vi.fn();
    addSelectedItemIfCompleteHandler(
      { handler: 'x', params: addParams({ stateTarget: 'global', statePath: 'quickOptionModal' }) },
      { setState },
    );

    expect(setState).not.toHaveBeenCalled();
    expect(globalSet).toHaveBeenCalledTimes(2);
    // 앞 선택을 지우려 null 을 먼저 쓰고 빈 객체로 다시 쓴다
    expect(globalSet.mock.calls[0][0]['quickOptionModal.currentSelection']).toBeNull();
    const last = globalSet.mock.calls[1][0];
    expect(last['quickOptionModal.currentSelection']).toEqual({});
    expect(last['quickOptionModal.selectedOptionItems']).toHaveLength(1);
    expect(last['quickOptionModal.selectedTotalMultiCurrency']).toBeTruthy();
    expect(Object.keys(last).every((k) => k.startsWith('quickOptionModal.'))).toBe(true);
  });

  // @scenario case=c23
  // @effects option_handlers_write_global_namespace_when_asked
  it('수량·삭제·추가옵션·옵션 없는 수량 핸들러도 같은 대상 규칙을 따른다', () => {
    const item = buildSelectedItemFromOption({ id: '블랙', option: options[0], optionLabels: { 색상: '블랙' }, preferredCurrency: 'KRW' });
    const target = { stateTarget: 'global', statePath: 'quickOptionModal' };

    updateSelectedItemQuantityHandler({ handler: 'x', params: { itemIndex: 0, newQuantity: 3, selectedOptionItems: [item], preferredCurrency: 'KRW', ...target } }, {});
    expect(globalSet.mock.calls[0][0]['quickOptionModal.selectedOptionItems'][0].quantity).toBe(3);
    expect(globalSet.mock.calls[0][0]['quickOptionModal.selectedOptionItems'][0].totalPrice).toBe(30000);

    setBlockAdditionalOptionHandler(
      {
        handler: 'x',
        params: {
          itemIndex: 0,
          additionalOptionId: 1,
          valueId: 100,
          selectedOptionItems: [item],
          additionalOptionGroups: [{ id: 1, name: '포장', is_required: true, values: [{ id: 100, name: '선물 포장', price_adjustment: 2000, is_default: false }] }],
          preferredCurrency: 'KRW',
          ...target,
        },
      },
      {},
    );
    expect(globalSet.mock.calls[1][0]['quickOptionModal.selectedOptionItems'][0].additionalOptionSelections).toEqual({ 1: 100 });
    expect(globalSet.mock.calls[1][0]['quickOptionModal.selectedOptionItems'][0].totalPrice).toBe(12000);

    removeSelectedItemHandler({ handler: 'x', params: { itemIndex: 0, selectedOptionItems: [item], ...target } }, {});
    expect(globalSet.mock.calls[2][0]['quickOptionModal.selectedOptionItems']).toEqual([]);

    updateNoOptionQuantityHandler({ handler: 'x', params: { newQuantity: 2, multiCurrencyUnitPrice: { KRW: { value: 10000, formatted: '10,000원' } }, ...target } }, {});
    expect(globalSet.mock.calls[3][0]['quickOptionModal.noOptionQuantity']).toBe(2);

    // 인자 없는 호출은 여전히 화면 상태
    const setState = vi.fn();
    removeSelectedItemHandler({ handler: 'x', params: { itemIndex: 0, selectedOptionItems: [item] } }, { setState });
    expect(setState).toHaveBeenCalledTimes(1);
    expect(globalSet).toHaveBeenCalledTimes(4);
  });

  // @scenario case=c24
  // @effects same_combination_is_not_stacked_twice
  it('같은 조합을 다시 고르면 줄을 더 쌓지 않고 알린다 — 다른 조합은 줄이 늘어난다', () => {
    const first = buildSelectedItemFromOption({ id: '블랙', option: options[0], optionLabels: { 색상: '블랙' }, preferredCurrency: 'KRW' });
    const target = { stateTarget: 'global', statePath: 'quickOptionModal' };

    addSelectedItemIfCompleteHandler({ handler: 'x', params: addParams({ selectedOptionItems: [first], ...target }) }, {});
    expect(warning).toHaveBeenCalledTimes(1);
    expect(globalSet.mock.calls.every((c) => c[0]['quickOptionModal.selectedOptionItems'] === undefined)).toBe(true);

    globalSet.mockClear();
    addSelectedItemIfCompleteHandler({ handler: 'x', params: addParams({ selectedOptionItems: [first], newValue: '화이트', ...target }) }, {});
    const rows = globalSet.mock.calls[1][0]['quickOptionModal.selectedOptionItems'];
    expect(rows.map((r: any) => r.optionId)).toEqual([10, 11]);
  });

  // @scenario case=c25
  // @effects fixed_row_is_built_from_single_option
  it('옵션이 하나뿐인 상품의 고정 줄을 같은 계산으로 만든다(필수 추가옵션 기본값·수량 반영)', () => {
    const row = buildSelectedItemFromOption({
      id: 'single',
      option: options[0],
      optionLabels: {},
      preferredCurrency: 'KRW',
      quantity: 2,
      additionalOptionGroups: [
        { id: 1, name: '포장', is_required: true, values: [{ id: 100, name: '선물 포장', price_adjustment: 1000, is_default: true }] },
        { id: 2, name: '메모', is_required: false, values: [{ id: 200, name: '카드', price_adjustment: 500, is_default: true }] },
      ],
    });

    expect(row.optionId).toBe(10);
    expect(row.quantity).toBe(2);
    expect(row.additionalOptionSelections).toEqual({ 1: 100 });
    expect(row.additionalOptionsTotal).toBe(1000);
    expect(row.totalPrice).toBe(22000);
    expect(row.stock).toBe(5);
  });
});
