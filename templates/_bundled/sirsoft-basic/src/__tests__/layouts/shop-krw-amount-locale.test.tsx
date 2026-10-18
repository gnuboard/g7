// e2e:allow 이커머스 전수 실측 후속 B — 전용 Chrome(CDP) 브라우저로 영어 화면 재실측(이력 B4 실측 표). 커밋 단위 E2E spec 은 후속 판단으로 이월
/**
 * @file shop-krw-amount-locale.test.tsx
 * @description 사용자 쇼핑 화면이 계산한 원화 금액도 보는 언어의 표기를 따른다 (B4 — S3-D23 같은 결함군)
 *
 * 서버는 원화를 언어별 접두·접미로 적는다(ko 「5,000원」·en 「₩5,000」·ja 「5,000ウォン」). 화면이 계산한 금액은
 * 「원」을 고정으로 붙여, 영어 화면에서 서버 금액(₩5,000)과 화면 계산 금액(5,000원)이 섞였다. 이커머스 공용
 * `formatCurrency` 는 S3 가 번역 키 `sirsoft-ecommerce.currency.krw_prefix`·`krw_suffix` 로 고쳤고, 같은 키를
 * 사용자 템플릿의 금액 처리기와 인라인 표현식(상품 상세·장바구니 옵션 변경 창의 추가옵션 금액, 주문서 배송비
 * 할인 대체 표기)도 쓴다. 선택한 상품 합계(productOptions)는 브라우저 언어의 `Intl` 통화 서식을 써서 보는 언어와 무관하게
 * 「₩8,000」이었다 — 같은 금액 처리기로 적는다. 번역을 읽지 못하면 종전 표기(금액 뒤 「원」)로 돌아간다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { formatCurrencyHandler } from '../../handlers/formatCurrency';
import { updateSelectedItemQuantityHandler } from '../../handlers/productOptions';
import purchaseCard from '../../../layouts/partials/shop/detail/_purchase_card.json';
import cartOptionChange from '../../../layouts/partials/shop/_modal_cart_option_change.json';
import checkoutDiscount from '../../../layouts/partials/shop/_checkout_discount.json';

/**
 * 언어별 번역을 흉내 낸 G7Core 를 심는다.
 *
 * @param affix 원화 접두·접미 (null 이면 번역 없음 — 키를 그대로 돌려준다)
 * @returns void
 */
function installT(affix: { prefix: string; suffix: string } | null): void {
  const dict: Record<string, string> = affix === null
    ? {}
    : { 'sirsoft-ecommerce.currency.krw_prefix': affix.prefix, 'sirsoft-ecommerce.currency.krw_suffix': affix.suffix };
  (window as any).G7Core = {
    t: (key: string) => (key in dict ? dict[key] : key),
    state: { get: () => ({ modules: { 'sirsoft-ecommerce': { language_currency: { currencies: [{ code: 'KRW', symbol: '₩', decimal_places: 0, is_default: true }] } } } }) },
  };
}

/**
 * 금액 처리기를 부른다.
 *
 * @param value 금액
 * @returns 표기
 */
function fmt(value: number): string {
  return formatCurrencyHandler({ handler: 'formatCurrency', params: { value, currencyCode: 'KRW' } } as any);
}

afterEach(() => {
  delete (window as any).G7Core;
});

describe('사용자 템플릿 금액 처리기 — 원화는 보는 언어의 접두·접미', () => {
  beforeEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario surface=handler
   * @effects shop_krw_amount_follows_locale
   */
  it('영어는 ₩ 를 앞에 붙인다', () => {
    installT({ prefix: '₩', suffix: '' });
    expect(fmt(5000)).toBe('₩5,000');
  });

  /**
   * @scenario surface=handler
   * @effects shop_krw_amount_follows_locale
   */
  it('한국어는 원을 뒤에 붙인다', () => {
    installT({ prefix: '', suffix: '원' });
    expect(fmt(5000)).toBe('5,000원');
  });

  /**
   * @scenario surface=handler
   * @effects shop_krw_amount_follows_locale
   */
  it('일본어는 ウォン 을 뒤에 붙인다', () => {
    installT({ prefix: '', suffix: 'ウォン' });
    expect(fmt(12500)).toBe('12,500ウォン');
  });

  /**
   * @scenario surface=handler
   * @effects shop_krw_amount_falls_back_without_translation
   */
  it('번역을 읽지 못하면 종전 표기(금액 뒤 원)로 돌아간다', () => {
    installT(null);
    expect(fmt(5000)).toBe('5,000원');
  });
});

describe('선택한 상품 합계(productOptions) — 브라우저 언어가 아니라 보는 언어', () => {
  /**
   * 선택 상품 1개(수량 1→2)의 통화별 합계를 다시 계산하게 한다.
   *
   * @returns 원화·달러 합계 표기
   */
  function recalcTotals(): { KRW: string; USD: string } {
    const setState = vi.fn();
    const item = {
      id: 'opt1', optionId: 1, options: {}, optionValues: {}, quantity: 1, stock: 99,
      unitPrice: 4000, unitPriceFormatted: '', totalPrice: 4000, totalPriceFormatted: '',
      multiCurrencyUnitPrice: { KRW: { value: 4000, formatted: '' }, USD: { value: 3.4, formatted: '' } },
      multiCurrencyTotalPrice: { KRW: { value: 4000, formatted: '' }, USD: { value: 3.4, formatted: '' } },
    };
    updateSelectedItemQuantityHandler(
      { handler: 'x', params: { itemIndex: 0, newQuantity: 2, selectedOptionItems: [item], preferredCurrency: 'KRW' } } as any,
      { setState } as any
    );
    const totals = setState.mock.calls[0][0].selectedTotalMultiCurrency;

    return { KRW: totals.KRW.formatted, USD: totals.USD.formatted };
  }

  /**
   * 원화 접두·접미 번역과 통화 설정을 함께 심는다.
   *
   * @param affix 원화 접두·접미
   * @returns void
   */
  function installWithCurrencies(affix: { prefix: string; suffix: string }): void {
    installT(affix);
    (window as any).G7Core.state = {
      get: () => ({ modules: { 'sirsoft-ecommerce': { language_currency: { currencies: [
        { code: 'KRW', symbol: '₩', decimal_places: 0, is_default: true },
        { code: 'USD', symbol: '$', decimal_places: 2 },
      ] } } } }),
      getLocal: () => ({}),
      setLocal: vi.fn(),
    };
    (window as any).G7Core.toast = { show: vi.fn(), warning: vi.fn() };
  }

  /**
   * @scenario surface=handler
   * @effects shop_selected_total_follows_locale
   */
  it('한국어 화면의 원화 합계는 「8,000원」 이다(브라우저 언어의 ₩8,000 이 아님)', () => {
    installWithCurrencies({ prefix: '', suffix: '원' });
    expect(recalcTotals().KRW).toBe('8,000원');
  });

  /**
   * @scenario surface=handler
   * @effects shop_selected_total_follows_locale
   */
  it('영어 화면은 「₩8,000」, 달러는 설정 기호로 「$6.80」', () => {
    installWithCurrencies({ prefix: '₩', suffix: '' });
    const t = recalcTotals();
    expect(t.KRW).toBe('₩8,000');
    expect(t.USD).toBe('$6.80');
  });

  /**
   * @scenario surface=handler
   * @effects shop_selected_total_follows_locale
   */
  it('일본어 화면은 「8,000ウォン」', () => {
    installWithCurrencies({ prefix: '', suffix: 'ウォン' });
    expect(recalcTotals().KRW).toBe('8,000ウォン');
  });
});

describe('인라인 표현식 — 원화 단위를 고정 「원」으로 붙이지 않는다', () => {
  const sources: Array<[string, unknown]> = [
    ['상품 상세 추가옵션 금액', purchaseCard],
    ['장바구니 옵션 변경 창 추가옵션 금액', cartOptionChange],
    ['주문서 배송비 할인 대체 표기', checkoutDiscount],
  ];

  /**
   * @scenario surface=layout
   * @effects shop_inline_krw_uses_locale_affix
   */
  it.each(sources)('%s 은 번역 키 접두·접미를 쓴다', (_label, layout) => {
    const text = JSON.stringify(layout);

    expect(text).not.toMatch(/n \+ '원'|_discount \+ '원'/);
    expect(text).toContain("sirsoft-ecommerce.currency.krw_prefix");
    expect(text).toContain("sirsoft-ecommerce.currency.krw_suffix");
  });
});
