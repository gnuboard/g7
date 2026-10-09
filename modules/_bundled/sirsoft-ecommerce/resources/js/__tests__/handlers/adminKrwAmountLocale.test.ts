// e2e:allow 이커머스 전수 실측 후속 B — 전용 Chrome(CDP) 브라우저로 영어 관리자 화면 재실측(이력 B4 실측 표). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * 관리자 화면이 계산한 금액도 보는 언어의 통화 표기를 따른다 (B4 — S3-D23 같은 결함군)
 *
 * @description
 * 상품 목록 인라인 옵션 편집에서 옵션 판매가를 바꾸면 화면이 추가금 표기(`price_adjustment_formatted`)를 새로
 * 만드는데 `toLocaleString() + '원'` 고정이라, 영어 관리자에게 「+1,500원」이 나오고 기본 통화가 원화가 아닌
 * 상점에서는 단위까지 틀렸다. 이커머스 공용 `formatAmountInCurrency`(기본 통화·언어별 원화 접두·접미)로 적는다.
 * 배송정책 도서산간 템플릿 창의 금액 대체 표기도 같은 번역 키를 쓴다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { updateOptionFieldHandler } from '../../handlers/updateOptionField';
import extraFeeTemplateModal from '../../../layouts/admin/partials/admin_ecommerce_shipping_policy_form/_modal_extra_fee_template.json';

let mockLocalState: Record<string, any> = {};
let mockDataSource: Record<string, any> = {};
let dict: Record<string, string> = {};

/**
 * 기본 통화를 정해 G7Core 를 심는다.
 *
 * @param defaultCode 기본 통화 코드
 * @returns void
 */
function install(defaultCode: 'KRW' | 'USD'): void {
  const currencies = [
    { code: 'KRW', symbol: '₩', decimal_places: 0, is_default: defaultCode === 'KRW', exchange_rate: 1, rounding_unit: '1', rounding_method: 'round' },
    { code: 'USD', symbol: '$', decimal_places: 2, is_default: defaultCode === 'USD', exchange_rate: 0.85, rounding_unit: '0.01', rounding_method: 'round' },
  ];
  (window as any).G7Core = {
    t: (key: string) => (key in dict ? dict[key] : key),
    state: {
      getLocal: () => mockLocalState,
      get: () => ({ modules: { 'sirsoft-ecommerce': { language_currency: { currencies } } }, _local: mockLocalState }),
      setLocal: vi.fn((u: Record<string, any>) => { mockLocalState = { ...mockLocalState, ...u }; }),
    },
    dataSource: {
      get: vi.fn((id: string) => mockDataSource[id]),
      set: vi.fn((id: string, v: any) => { mockDataSource[id] = v; }),
    },
    createLogger: () => ({ log: vi.fn(), warn: vi.fn(), error: vi.fn() }),
  };
}

/**
 * 비기본 옵션의 판매가를 바꾸고 새 추가금 표기를 돌려준다.
 *
 * @param value 새 판매가
 * @returns 추가금 표기
 */
function changeOption(value: number): string {
  mockDataSource.products = {
    success: true,
    data: { data: [{ id: 313, selling_price: 4000, list_price: 5000, options: [
      { id: 1, is_default: true, selling_price: 4000, list_price: 5000, price_adjustment: 0 },
      { id: 2, is_default: false, selling_price: 5000, list_price: 6000, price_adjustment: 1000 },
    ] }] },
  };
  updateOptionFieldHandler({ handler: 'updateOptionField', params: { productId: 313, optionId: 2, field: 'selling_price', value } } as any, {} as any);

  return mockDataSource.products.data.data[0].options.find((o: any) => o.id === 2).price_adjustment_formatted;
}

beforeEach(() => {
  mockLocalState = {};
  mockDataSource = {};
  dict = {};
});

afterEach(() => {
  delete (window as any).G7Core;
});

describe('옵션 추가금 표기 — 기본 통화·보는 언어', () => {
  /**
   * @scenario surface=option_adjustment
   * @effects admin_option_adjustment_follows_locale
   */
  it('영어 관리자에게 원화 추가금은 ₩ 접두로 보인다', () => {
    dict = { 'sirsoft-ecommerce.currency.krw_prefix': '₩', 'sirsoft-ecommerce.currency.krw_suffix': '' };
    install('KRW');

    expect(changeOption(5500)).toBe('+₩1,500');
  });

  /**
   * @scenario surface=option_adjustment
   * @effects admin_option_adjustment_follows_locale
   */
  it('한국어 관리자에게는 종전대로 「+1,500원」, 내리면 「-500원」', () => {
    dict = { 'sirsoft-ecommerce.currency.krw_prefix': '', 'sirsoft-ecommerce.currency.krw_suffix': '원' };
    install('KRW');

    expect(changeOption(5500)).toBe('+1,500원');
    expect(changeOption(3500)).toBe('-500원');
  });

  /**
   * @scenario surface=option_adjustment
   * @effects admin_option_adjustment_follows_base_currency
   */
  it('기본 통화가 달러인 상점은 원이 아니라 달러로 적는다', () => {
    install('USD');

    expect(changeOption(5500)).toBe('+$1,500.00');
  });
});

describe('도서산간 템플릿 창 금액 대체 표기', () => {
  /**
   * @scenario surface=extra_fee_template
   * @effects admin_inline_krw_uses_locale_affix
   */
  it('서버 표기가 없을 때도 고정 「원」 대신 번역 키 접두·접미를 쓴다', () => {
    const text = JSON.stringify(extraFeeTemplateModal);

    expect(text).not.toContain("toLocaleString() + '원'");
    expect(text).toContain('sirsoft-ecommerce.currency.krw_prefix');
    expect(text).toContain('sirsoft-ecommerce.currency.krw_suffix');
  });
});
