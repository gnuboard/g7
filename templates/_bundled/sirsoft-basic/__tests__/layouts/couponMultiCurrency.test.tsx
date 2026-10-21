// e2e:allow 굽기 산출물 표현식 평가(선택 통화 혜택 문구) — 브라우저 축은 7.1.1 캠페인 재실측(USD 로 바꾼 회원의 주문서 쿠폰 받기 창·칩 확인 창·상품 쿠폰 선택칸)이 덮는다.
/**
 * @file couponMultiCurrency.test.tsx
 * @description 쿠폰 다운로드 모달 다통화 환산 구조 회귀 테스트 (A1-④)
 *
 * 배경:
 * - CouponResource 가 multi_currency_discount_value/min_order_amount(정액만, 정률 null)를 노출하며,
 *   모달이 선택 통화 환산값(formatted)을 표시하고 환산 부재 시 기본통화 숫자+단위로 폴백한다.
 * - 정률(rate) 할인은 통화 무관 → % 표시 유지.
 *
 * 회귀 차단:
 * - 정액 할인은 multi_currency_discount_value[선택통화].formatted 를 우선 표시(있을 때).
 * - 폴백 분기는 환산 부재 시 Number(discount_value).toLocaleString() + currency_unit.
 * - 최소주문금액도 동일 패턴(multi_currency_min_order_amount 우선 + 폴백).
 * - 정률 할인은 discount_value% 텍스트 유지(통화 무관).
 *
 * 7.1.1: 같은 모양의 다른 쿠폰 표시 자리(상품 상세 칩 확인 창·주문서 상품 쿠폰 선택칸)도 선택 통화 혜택 문구를 쓴다.
 *
 * @scenario-manifest tests/scenarios/coupon-selected-currency-display.yaml
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');
const modalText = fs.readFileSync(
  path.resolve(baseDir, 'layouts/partials/shop/_modal_coupon_download.json'),
  'utf8'
);

describe('A1-④ — 쿠폰 모달 다통화 환산', () => {
  // @scenario surface=download_modal
  // @effects download_modal_fixed_amount_uses_selected_currency
  it('정액 할인이 multi_currency_discount_value 선택통화 환산을 표시한다', () => {
    expect(modalText).toContain('multi_currency_discount_value');
    expect(modalText).toContain("_global.preferredCurrency ?? _global.defaultCurrency");
  });

  // @scenario surface=download_modal
  // @effects download_modal_falls_back_to_base_amount
  it('정액 할인 폴백은 기본통화 숫자 + currency_unit 을 유지한다', () => {
    expect(modalText).toContain('Number(coupon.discount_value).toLocaleString()');
    expect(modalText).toContain('shop.coupon_download.currency_unit');
  });

  // @scenario surface=download_modal
  // @effects download_modal_min_order_uses_selected_currency
  it('최소주문금액도 multi_currency_min_order_amount 우선 + 폴백 패턴을 쓴다', () => {
    expect(modalText).toContain('multi_currency_min_order_amount');
    expect(modalText).toContain('Number(coupon.min_order_amount).toLocaleString()');
  });

  // @scenario surface=download_modal
  // @effects download_modal_rate_stays_percent
  it('정률(rate) 할인은 % 표시를 유지한다(통화 무관)', () => {
    expect(modalText).toContain("coupon.discount_type === 'rate'");
    expect(modalText).toContain('{{coupon.discount_value}}%');
  });

  // @scenario surface=download_modal
  // @effects download_modal_rate_stays_percent
  it('정액/정률 분기 if 게이트가 discount_type 으로 분리된다', () => {
    expect(modalText).toContain("coupon.discount_type === 'fixed'");
  });
});

// ---------------------------------------------------------------------------
// 7.1.1 — 같은 모양의 다른 쿠폰 표시 자리도 선택 통화로 보인다.
// 굽기 산출물(JSON)의 실제 표현식을 꺼내 USD 로 보는 상태에서 평가한다(문자열 포함 판정만으로는
// 「맵을 읽지만 다른 통화 값이 나온다」 를 못 잡는다).
// ---------------------------------------------------------------------------

/**
 * JSON 트리에서 조건에 맞는 첫 `text` 값을 찾는다.
 *
 * @param node 탐색 대상
 * @param pred 조건
 * @returns text 값 또는 undefined
 */
function findText(node: unknown, pred: (text: string) => boolean): string | undefined {
  if (Array.isArray(node)) {
    for (const child of node) {
      const hit = findText(child, pred);
      if (hit !== undefined) return hit;
    }

    return undefined;
  }
  if (!node || typeof node !== 'object') return undefined;
  const text = (node as Record<string, unknown>).text;
  if (typeof text === 'string' && pred(text)) return text;
  for (const value of Object.values(node as Record<string, unknown>)) {
    const hit = findText(value, pred);
    if (hit !== undefined) return hit;
  }

  return undefined;
}

/**
 * `{{식}}` 하나로 된 text 를 주어진 문맥으로 평가한다.
 *
 * @param text 굽기 산출물의 text
 * @param scope 식이 읽는 이름들
 * @returns 평가 결과
 */
function evaluate(text: string, scope: Record<string, unknown>): unknown {
  const m = text.match(/^\{\{([\s\S]*)\}\}$/);
  expect(m, `식 하나로 된 text 가 아니다: ${text}`).not.toBeNull();
  const names = Object.keys(scope);

  // eslint-disable-next-line no-new-func
  return new Function(...names, `return (${m![1]});`)(...names.map((n) => scope[n]));
}

const readJson = (rel: string) => JSON.parse(fs.readFileSync(path.resolve(baseDir, rel), 'utf8'));

/** 서버가 싣는 혜택 문구 맵 — 정액 1,000원 쿠폰을 USD 로 환산한 모양 */
const BENEFIT_MAP = { KRW: '1,000원 할인', USD: '$0.75 할인' };
const USD_GLOBAL = { preferredCurrency: 'USD', defaultCurrency: 'KRW' };

describe('7.1.1 — 쿠폰 받기 확인 창(상품 상세 칩)의 혜택 문구', () => {
  const confirmModal = readJson('layouts/partials/shop/detail/_modal_coupon_download_confirm.json');
  const benefitText = findText(confirmModal, (t) => t.includes('selectedCouponForDownload') && t.includes('benefit_formatted'))!;

  // @scenario surface=download_confirm
  // @effects confirm_modal_benefit_uses_selected_currency
  it('USD 로 보는 회원에게 선택 통화 혜택 문구를 보인다', () => {
    expect(benefitText).toBeDefined();
    const out = evaluate(benefitText, {
      $parent: { _local: { selectedCouponForDownload: { benefit_formatted: BENEFIT_MAP.KRW, multi_currency_benefit_formatted: BENEFIT_MAP } } },
      _global: USD_GLOBAL,
    });
    expect(out).toBe('$0.75 할인');
  });

  // @scenario surface=download_confirm
  // @effects confirm_modal_benefit_falls_back
  it('통화 맵이 없으면 기본 혜택 문구로, 쿠폰이 없으면 빈 글자로 남는다', () => {
    expect(evaluate(benefitText, { $parent: { _local: { selectedCouponForDownload: { benefit_formatted: '10% 할인' } } }, _global: USD_GLOBAL })).toBe('10% 할인');
    expect(evaluate(benefitText, { $parent: { _local: { selectedCouponForDownload: null } }, _global: USD_GLOBAL })).toBe('');
  });
});

describe('7.1.1 — 주문서 상품 줄의 상품 쿠폰 선택칸 1·2', () => {
  const items = readJson('layouts/partials/shop/_checkout_items.json');
  const cases: Array<[string, string]> = [
    ['선택칸 1', 'pcoupon'],
    ['선택칸 2', 'pcoupon2'],
  ];

  for (const [label, name] of cases) {
    const optionText = findText(items, (t) => t.includes(`${name}.localized_name`) && t.includes('benefit_formatted') && !t.includes(`${name}2.`))!;

    // @scenario surface=checkout_product_coupon
    // @effects product_coupon_option_uses_selected_currency
    it(`${label}: USD 로 보는 회원에게 선택 통화 혜택 문구를 보인다`, () => {
      expect(optionText).toBeDefined();
      const coupon = { id: 7, target_type_short_label: '상품', localized_name: '상품 쿠폰', benefit_formatted: BENEFIT_MAP.KRW, multi_currency_benefit_formatted: BENEFIT_MAP };
      const out = evaluate(optionText, { item: { disabled_coupon_ids: [] }, [name]: coupon, _global: USD_GLOBAL });
      expect(out).toBe('[상품] 상품 쿠폰 ($0.75 할인)');
    });

    // @scenario surface=checkout_product_coupon
    // @effects product_coupon_option_falls_back
    it(`${label}: 통화 맵이 없으면 기본 혜택 문구로 폴백한다`, () => {
      const coupon = { id: 7, target_type_short_label: '상품', localized_name: '상품 쿠폰', benefit_formatted: BENEFIT_MAP.KRW };
      const out = evaluate(optionText, { item: { disabled_coupon_ids: [] }, [name]: coupon, _global: USD_GLOBAL });
      expect(out).toBe('[상품] 상품 쿠폰 (1,000원 할인)');
    });
  }
});

describe('7.1.1 — 쿠폰 받기 창의 할인 종류 없는 목록(상품 상세) 혜택 문구', () => {
  const downloadModal = readJson('layouts/partials/shop/_modal_coupon_download.json');

  /**
   * 굽기 산출물에서 조건(if)에 `!coupon.discount_type` 이 든 노드를 찾는다.
   *
   * @param node 탐색 대상
   * @returns 노드 또는 undefined
   */
  function findNoTypeNode(node: unknown): Record<string, any> | undefined {
    if (Array.isArray(node)) {
      for (const child of node) {
        const hit = findNoTypeNode(child);
        if (hit) return hit;
      }

      return undefined;
    }
    if (!node || typeof node !== 'object') return undefined;
    const obj = node as Record<string, any>;
    if (typeof obj.if === 'string' && obj.if.includes('!coupon.discount_type')) return obj;
    for (const value of Object.values(obj)) {
      const hit = findNoTypeNode(value);
      if (hit) return hit;
    }

    return undefined;
  }

  const noType = findNoTypeNode(downloadModal)!;

  // @scenario surface=download_modal
  // @effects download_modal_benefit_without_type_uses_selected_currency
  it('할인 종류 없이 혜택 문구만 온 쿠폰을 USD 로 보는 회원에게 선택 통화 문구로 보인다', () => {
    expect(noType, '`!coupon.discount_type` 갈래가 없다').toBeDefined();
    const coupon = { benefit_formatted: BENEFIT_MAP.KRW, multi_currency_benefit_formatted: BENEFIT_MAP };
    expect(evaluate(noType.if, { coupon, _global: USD_GLOBAL })).toBeTruthy();
    expect(evaluate(noType.text, { coupon, _global: USD_GLOBAL })).toBe('$0.75 할인');
  });

  // @scenario surface=download_modal
  // @effects download_modal_benefit_without_type_uses_selected_currency
  it('통화 맵이 없으면 기본 혜택 문구로 폴백하고, 할인 종류가 있는 쿠폰에서는 이 갈래가 꺼진다', () => {
    const plain = { benefit_formatted: BENEFIT_MAP.KRW };
    expect(evaluate(noType.text, { coupon: plain, _global: USD_GLOBAL })).toBe('1,000원 할인');
    expect(evaluate(noType.if, { coupon: { ...plain, discount_type: 'fixed' }, _global: USD_GLOBAL })).toBeFalsy();
    expect(evaluate(noType.if, { coupon: {}, _global: USD_GLOBAL })).toBeFalsy();
  });
});
