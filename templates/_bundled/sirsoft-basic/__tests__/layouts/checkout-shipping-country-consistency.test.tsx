/**
 * @file checkout-shipping-country-consistency.test.tsx
 * @description 주문서·배송지 창의 배송국가 단일 출처 + 상세주소 필수 표시 회귀 검증 (#703)
 *
 * 회귀 배경:
 *   - 주문서의 배송국가 선택 칸은 `_local.shipping.country_code ?? _global.preferredShippingCountry` 를,
 *     국내/해외 주소 폼·결제 버튼 잠금은 `_local.shipping.country_code ?? 'KR'` 을 봤다. 진입 시에는
 *     배송비 계산용 `_local.checkout.country_code` 만 시드돼, 선호 배송국가가 미국이면 선택 칸은 「미국」인데
 *     주소 폼은 국내형이고 주문은 KR 로 저장됐다(도입: #421 유저별 배송국가 작업).
 *   - 마이페이지 배송지 창·주문서 배송지 관리 창의 「새 배송지」도 같은 어긋남을 가졌다.
 *   - 국내 상세주소 칸은 서버 필수값인데 별표·오류 표시가 없었다.
 */

import { describe, it, expect } from 'vitest';
import checkoutJson from '../../layouts/shop/checkout.json';
import shippingJson from '../../layouts/partials/shop/_checkout_shipping.json';
import summaryJson from '../../layouts/partials/shop/_checkout_summary.json';
import manageModalJson from '../../layouts/partials/shop/_modal_address_manage.json';
import mypageModalJson from '../../layouts/partials/mypage/addresses/_modal_address.json';
import mypageListJson from '../../layouts/partials/mypage/addresses/_list.json';

/** 객체 트리에서 조건을 만족하는 노드를 모두 모은다 */
function findAll(node: any, predicate: (n: any) => boolean, acc: any[] = []): any[] {
  if (node == null || typeof node !== 'object') return acc;
  if (predicate(node)) acc.push(node);
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) value.forEach((v) => findAll(v, predicate, acc));
    else if (value && typeof value === 'object') findAll(value, predicate, acc);
  }
  return acc;
}

/** `{{ ... }}` 표현식을 `_global` 하나로 평가한다 */
function evalWithGlobal(raw: string, global: Record<string, any>): any {
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function('_global', `return ${expr};`)(global);
}

/** 해외배송 설정을 담은 전역 상태를 만든다 */
function globalWith(intl: boolean, preferred: string, active: string[] = ['KR', 'US']): Record<string, any> {
  return {
    preferredShippingCountry: preferred,
    modules: {
      'sirsoft-ecommerce': {
        shipping: {
          international_shipping_enabled: intl,
          available_countries: ['KR', 'US', 'JP'].map((code) => ({ code, is_active: active.includes(code) })),
        },
      },
    },
  };
}

const seed = findAll(
  (checkoutJson as any).init_actions,
  (n) => n.handler === 'setState' && typeof n.params?.['checkout.country_code'] === 'string'
)[0];

describe('주문서 진입 시드 — 배송비 계산용과 배송지 폼용 국가가 같다', () => {
  /**
   * @scenario screen=checkout, preferred_country=active_overseas, intl_shipping=enabled
   *
   * @effects checkout_country_single_source
   */
  it('checkout.country_code 와 shipping.country_code 를 같은 식으로 함께 채운다', () => {
    expect(seed, '시드 setState 가 있어야 함').toBeDefined();
    expect(seed.params['shipping.country_code']).toBe(seed.params['checkout.country_code']);
  });

  /**
   * @scenario screen=checkout, preferred_country=active_overseas, intl_shipping=enabled
   *
   * @effects checkout_country_single_source
   */
  it.each([
    [true, 'US', ['KR', 'US'], 'US'],
    [true, 'JP', ['KR', 'US'], 'KR'],
    [false, 'US', ['KR', 'US'], 'KR'],
    [true, 'KR', ['KR', 'US'], 'KR'],
  ])('해외배송 %s · 선호 %s · 활성 %j → %s', (intl, preferred, active, expected) => {
    expect(evalWithGlobal(seed.params['checkout.country_code'], globalWith(intl, preferred, active))).toBe(expected);
  });

  /**
   * @scenario screen=checkout, preferred_country=active_overseas, intl_shipping=enabled
   *
   * @effects checkout_country_single_source
   */
  it('선택 칸·국내/해외 주소 폼·결제 버튼 잠금이 선호 배송국가를 직접 보지 않고 같은 자리를 본다', () => {
    const select = findAll(shippingJson, (n) => n.name === 'Select' && n.props?.name === 'country_code')[0];
    expect(select.props.value).toContain('_local.checkout?.country_code');
    expect(select.props.value).not.toContain('preferredShippingCountry');

    const branches = findAll(shippingJson, (n) => typeof n.if === 'string' && n.if.includes("country_code ?? ") && n.if.includes("'KR'"));
    expect(branches.length).toBeGreaterThanOrEqual(2);
    branches.forEach((b) => expect(b.if).toContain('_local.checkout?.country_code'));

    const payButton = findAll(summaryJson, (n) => n.name === 'Button' && typeof n.props?.disabled === 'string' && n.props.disabled.includes('intl_postal_code'))[0];
    expect(payButton.props.disabled).toContain('_local.checkout?.country_code');
  });
});

describe('배송지 창 — 새 배송지의 국가와 주소 폼이 같은 값을 본다', () => {
  /**
   * @scenario screen=checkout_address_modal, preferred_country=active_overseas, intl_shipping=enabled
   *
   * @effects address_modal_country_single_source
   */
  it('주문서 배송지 관리 창: 새로 등록이 비운 뒤 국가를 채우고, 선택 칸은 선호 국가를 직접 보지 않는다', () => {
    const seedCountry = findAll(manageModalJson, (n) => n.handler === 'setState' && typeof n.params?.['editingAddress.country_code'] === 'string')[0];
    expect(seedCountry).toBeDefined();
    expect(evalWithGlobal(seedCountry.params['editingAddress.country_code'], globalWith(true, 'US'))).toBe('US');
    const select = findAll(manageModalJson, (n) => n.name === 'Select' && n.props?.name === 'country_code')[0];
    expect(select.props.value).not.toContain('preferredShippingCountry');
  });

  /**
   * @scenario screen=mypage_address_modal, preferred_country=active_overseas, intl_shipping=enabled
   *
   * @effects address_modal_country_single_source
   */
  it('마이페이지 배송지 창: 추가 버튼 두 곳이 국가를 채우고, 선택 칸은 선호 국가를 직접 보지 않는다', () => {
    const seeds = findAll(mypageListJson, (n) => n.handler === 'setState' && n.params?.editingAddress && typeof n.params.editingAddress.country_code === 'string' && !('id' in n.params.editingAddress));
    expect(seeds.length).toBe(2);
    seeds.forEach((s) => expect(evalWithGlobal(s.params.editingAddress.country_code, globalWith(true, 'US'))).toBe('US'));
    const select = findAll(mypageModalJson, (n) => n.name === 'Select' && n.props?.name === 'country_code')[0];
    expect(select.props.value).not.toContain('preferredShippingCountry');
  });
});

describe('국내 상세주소 — 필수 표시와 칸 아래 오류', () => {
  /**
   * @scenario screen=checkout, shipping_region=domestic, address_detail_input=missing
   *
   * @effects domestic_address_detail_marked_required
   */
  it('상세주소 칸이 오류 강조와 오류 문구를 갖고, 별표 라벨이 붙는다', () => {
    const input = findAll(shippingJson, (n) => n.name === 'Input' && n.props?.name === 'address_detail')[0];
    expect(String(input.props.className)).toContain("errors?.['shipping.address_detail']");
    const errorSpan = findAll(shippingJson, (n) => typeof n.if === 'string' && n.if.includes("errors?.['shipping.address_detail']"))[0];
    expect(errorSpan).toBeDefined();
    const label = findAll(shippingJson, (n) => n.name === 'Label' && JSON.stringify(n).includes('shop.checkout.detail_address') && JSON.stringify(n).includes('*'))[0];
    expect(label).toBeDefined();
  });
});

/** `{{ ... }}` 표현식을 주어진 이름들(_local·_global·$event 등)로 평가한다 */
function evalWith(raw: string, scope: Record<string, any>): any {
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return ${expr};`)(...Object.values(scope));
}

/** 상세주소 입력칸을 품은 국내 전용 블록(조건부)을 찾는다 */
function domesticDetailBlock(tree: any, stateKey: string): any {
  const block = findAll(
    tree,
    (n) =>
      typeof n.if === 'string' &&
      n.if.includes(stateKey) &&
      n.if.includes("=== 'KR'") &&
      findAll(n, (m) => m.name === 'Input' && m.props?.name === 'address_detail').length > 0
  ).pop();
  expect(block, '상세주소 칸을 품은 국내 전용 블록이 있어야 함').toBeDefined();
  return block;
}

describe('선호 해외국가로 시작해 국내로 바꾼 뒤 상세주소를 비운 경우', () => {
  /**
   * @scenario screen=checkout, preferred_country=active_overseas, intl_shipping=enabled, shipping_region=domestic, address_detail_input=missing
   *
   * @effects checkout_country_single_source, domestic_address_detail_marked_required
   */
  it('주문서: 해외 폼으로 시작 → 국내로 바꾸면 상세주소 칸이 나타나고, 비워 둔 채 제출해 받은 오류가 칸 아래에 보인다', () => {
    const g = globalWith(true, 'US');
    const seeded = {
      checkout: { country_code: evalWithGlobal(seed.params['checkout.country_code'], g) },
      shipping: { country_code: evalWithGlobal(seed.params['shipping.country_code'], g) },
    };
    const block = domesticDetailBlock(shippingJson, '_local.checkout?.country_code');
    expect(seeded.shipping.country_code).toBe('US');
    expect(evalWith(block.if, { _local: seeded }), '선호 국가가 미국이면 국내 상세주소 칸은 숨는다').toBe(false);

    // 국가 선택 칸을 KR 로 바꾸면 두 자리가 같이 바뀌고 이전 주소는 비워진다
    const change = findAll(shippingJson, (n) => n.handler === 'setState' && n.params?.['shipping.country_code'] === '{{$event.target.value}}')[0];
    expect(change.params['checkout.country_code']).toBe('{{$event.target.value}}');
    const $event = { target: { value: 'KR' } };
    const switched: Record<string, any> = {
      checkout: { country_code: evalWith(change.params['checkout.country_code'], { $event }) },
      shipping: { country_code: evalWith(change.params['shipping.country_code'], { $event }), address_detail: '' },
    };
    expect(evalWith(block.if, { _local: switched }), '국내로 바꾸면 상세주소 칸이 보인다').toBe(true);

    // 서버(CreateOrderRequest)는 해외 주소 1행이 없으면 상세주소를 요구한다 — 그 오류가 칸에 붙는다
    const withError = { ...switched, errors: { 'shipping.address_detail': ['상세주소를 입력하세요.'] } };
    const input = findAll(block, (n) => n.name === 'Input' && n.props?.name === 'address_detail')[0];
    expect(evalWith(input.props.className, { _local: withError })).toContain('border-red-500');
    const errorSpan = findAll(block, (n) => typeof n.if === 'string' && n.if.includes("errors?.['shipping.address_detail']"))[0];
    expect(evalWith(errorSpan.if, { _local: withError })).toBeTruthy();
    expect(evalWith(errorSpan.if, { _local: switched }), '오류가 없으면 문구도 없다').toBeFalsy();
  });

  /**
   * @scenario screen=checkout_address_modal, preferred_country=active_overseas, intl_shipping=enabled, shipping_region=domestic, address_detail_input=missing
   *
   * @effects address_modal_country_single_source, address_book_detail_optional_matches_server
   */
  it('주문서 배송지 관리 창: 새 배송지가 미국으로 시작 → 국내로 바꾸면 상세주소 칸이 나타나고, 배송지록 저장은 상세주소를 요구하지 않으므로 별표가 없다', () => {
    const seedCountry = findAll(manageModalJson, (n) => n.handler === 'setState' && typeof n.params?.['editingAddress.country_code'] === 'string')[0];
    const seeded = { editingAddress: { country_code: evalWithGlobal(seedCountry.params['editingAddress.country_code'], globalWith(true, 'US')) } };
    const block = domesticDetailBlock(manageModalJson, '_local.editingAddress');
    expect(evalWith(block.if, { _local: seeded })).toBe(false);
    expect(evalWith(block.if, { _local: { editingAddress: { country_code: 'KR', address_detail: '' } } })).toBe(true);

    // StoreUserAddressRequest: address_detail 은 nullable — 비워 저장해도 거절되지 않으므로 필수 표시를 붙이지 않는다
    const label = findAll(block, (n) => n.name === 'Label')[0];
    expect(label).toBeDefined();
    expect(JSON.stringify(label)).not.toContain('*');
    const input = findAll(block, (n) => n.name === 'Input' && n.props?.name === 'address_detail')[0];
    expect(evalWith(input.props.className, { _local: { addressErrors: {} } })).not.toContain('border-red-500');
  });

  /**
   * @scenario screen=mypage_address_modal, preferred_country=active_overseas, intl_shipping=enabled, shipping_region=domestic, address_detail_input=missing
   *
   * @effects address_modal_country_single_source, address_book_detail_optional_matches_server
   */
  it('마이페이지 배송지 창: 새 배송지가 미국으로 시작 → 국내로 바꾸면 상세주소 칸이 나타나고, 별표 없이 비워 저장할 수 있다', () => {
    const seeds = findAll(mypageListJson, (n) => n.handler === 'setState' && n.params?.editingAddress && typeof n.params.editingAddress.country_code === 'string' && !('id' in n.params.editingAddress));
    const g = globalWith(true, 'US');
    const block = domesticDetailBlock(mypageModalJson, '_global.editingAddress');
    seeds.forEach((s) => {
      const seeded = { editingAddress: { country_code: evalWithGlobal(s.params.editingAddress.country_code, g) } };
      expect(evalWith(block.if, { _global: seeded })).toBe(false);
    });
    expect(evalWith(block.if, { _global: { editingAddress: { country_code: 'KR', address_detail: '' } } })).toBe(true);

    const label = findAll(block, (n) => n.name === 'Label')[0];
    expect(label).toBeDefined();
    expect(JSON.stringify(label)).not.toContain('*');
    const input = findAll(block, (n) => n.name === 'Input' && n.props?.name === 'address_detail')[0];
    expect(evalWith(input.props.className, { _local: { addressErrors: {} } })).not.toContain('border-red-500');
  });
});
