/**
 * @file order-change-address-and-payment-gates.test.tsx
 * @description 주문 배송지 변경 창의 적용 잠금 + 주문서 결제수단 0건 안내·잠금 회귀 검증 (#703)
 *
 * 회귀 배경:
 *   - 배송지 변경 창이 「저장된 배송지」 탭으로 열린 채 아무것도 고르지 않거나(또는 저장 배송지 0건)
 *     적용하면 address_id:null 이 나가 422 가 났다.
 *   - 입금 계좌 없는 무통장입금을 서버가 걸러 내면 결제수단 목록이 빌 수 있는데, 빈 칸만 남고 결제하기는
 *     눌려 「입금 은행 선택」 422 로만 끝났다.
 */

import { describe, it, expect } from 'vitest';
import changeAddressModalJson from '../../layouts/partials/mypage/orders/_modal_change_address.json';
import orderShippingJson from '../../layouts/partials/mypage/orders/_shipping.json';
import paymentJson from '../../layouts/partials/shop/_checkout_payment.json';
import summaryJson from '../../layouts/partials/shop/_checkout_summary.json';
import checkoutJson from '../../layouts/shop/checkout.json';

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

/** `{{ ... }}` 표현식을 주어진 이름들로 평가한다 */
function evaluate(raw: string, scope: Record<string, any>): any {
  const expr = String(raw).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), `return ${expr};`)(...Object.values(scope));
}

describe('주문 배송지 변경 창 — 저장된 배송지 미선택 적용 차단', () => {
  const applyButton = findAll(
    changeAddressModalJson,
    (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('sirsoft-ecommerce.changeShippingAddress')
  )[0];

  /**
   * @scenario surface=order_change_address_modal, gate=saved_mode_without_selection
   *
   * @effects saved_mode_without_selection_is_not_sent
   */
  it.each([
    [{ currentUser: { uuid: 'u1' }, changeAddressMode: 'saved', selectedAddressId: null }, true],
    [{ currentUser: { uuid: 'u1' }, changeAddressMode: 'saved', selectedAddressId: 7 }, false],
    [{ currentUser: { uuid: 'u1' }, changeAddressMode: 'manual', selectedAddressId: null }, false],
    [{ currentUser: null, changeAddressMode: 'manual', selectedAddressId: null }, false],
  ])('적용 버튼 잠금 — 전역 %j → %s', (global, expected) => {
    expect(applyButton, '적용 버튼').toBeDefined();
    expect(Boolean(evaluate(applyButton.props.disabled, { _global: global, _local: { isSubmittingAddress: false } }))).toBe(expected);
    const portable = findAll(applyButton, (n) => n.props?.disabled && n !== applyButton)[0]
      ?? (applyButton.responsive?.portable ?? null);
    if (portable?.props?.disabled) {
      expect(Boolean(evaluate(portable.props.disabled, { _global: global, _local: { isSubmittingAddress: false } }))).toBe(expected);
    }
  });

  /**
   * @scenario surface=order_change_address_modal, gate=saved_mode_without_selection
   *
   * @effects saved_mode_without_selection_is_not_sent
   */
  it('저장된 배송지가 0건인 회원은 직접 입력 탭으로 연다', () => {
    const open = findAll(orderShippingJson, (n) => n.handler === 'setState' && typeof n.params?.changeAddressMode === 'string')[0];
    const mode = (addresses: any[]) => evaluate(open.params.changeAddressMode, {
      _global: { currentUser: { uuid: 'u1' } },
      userAddresses: { data: { addresses: { data: addresses } } },
    });
    expect(mode([])).toBe('manual');
    expect(mode([{ id: 1 }])).toBe('saved');
    expect(evaluate(open.params.changeAddressMode, { _global: { currentUser: null }, userAddresses: undefined })).toBe('manual');
  });
});

describe('주문서 결제수단 0건 — 안내와 결제하기 잠금', () => {
  const settings = (methods: any[]) => ({ data: { order_settings: { payment_methods: methods } } });
  /**
   * 주문서 레이아웃의 계산값(고객이 고를 수 있는 결제수단)을 같은 설정으로 평가해 `_computed` 를 만든다.
   * 설정을 아직 못 받았을 때 식이 던지면 엔진처럼 값 없음(undefined)으로 둔다. 결제 통화 허용 목록은 주문서 계산 응답
   * (`checkoutData.data.allowed_payment_methods`)에서 읽으므로 그 데이터 소스도 함께 넘긴다(없으면 거르지 않는다).
   */
  const computedFor = (paymentSettings: any, _global: any, checkoutData?: any) => {
    let selectablePaymentMethods: any;
    try {
      selectablePaymentMethods = evaluate((checkoutJson as any).computed.selectablePaymentMethods, { paymentSettings, _global, checkoutData });
    } catch {
      selectablePaymentMethods = undefined;
    }

    return { selectablePaymentMethods };
  };

  /**
   * @scenario surface=checkout_payment, gate=no_active_payment_method
   *
   * @effects checkout_explains_and_blocks_when_no_payment_method
   */
  it('활성 결제수단이 없으면 안내가 보이고, 있으면 숨는다', () => {
    const notice = findAll(paymentJson, (n) => JSON.stringify(n.children ?? n.text ?? '').includes('shop.checkout.no_payment_methods') && typeof n.if === 'string')[0];
    expect(notice, '안내 블록').toBeDefined();
    const scope = (paymentSettings: any) => ({ paymentSettings, _global: {}, _computed: computedFor(paymentSettings, {}) });
    expect(Boolean(evaluate(notice.if, scope(settings([]))))).toBe(true);
    expect(Boolean(evaluate(notice.if, scope(settings([{ id: 'card', is_active: true }]))))).toBe(false);
    // 결제 설정을 아직 못 받았을 때는 안내를 띄우지 않는다(깜빡임 방지)
    expect(Boolean(evaluate(notice.if, scope(undefined)))).toBe(false);
  });

  /**
   * @scenario surface=checkout_payment, gate=no_active_payment_method
   *
   * @effects checkout_explains_and_blocks_when_no_payment_method
   */
  it('결제하기 버튼은 활성 결제수단이 없을 때 잠긴다', () => {
    const payButton = findAll(summaryJson, (n) => n.name === 'Button' && typeof n.props?.disabled === 'string' && n.props.disabled.includes('intl_postal_code'))[0];
    const scope = (methods: any[]) => ({
      _local: { shipping: { country_code: 'KR', zipcode: '12345', recipient_name: 'a', recipient_phone: '010' }, isSubmittingOrder: false },
      // 주문 권한이 있는 회원 기준 — 권한 잠금은 checkout-pay-button-order-permission 테스트가 따로 본다
      checkoutData: { data: { has_unshippable_items: false, abilities: { can_order: true } } },
      paymentSettings: settings(methods),
      _global: {},
      _computed: computedFor(settings(methods), {}, { data: { has_unshippable_items: false } }),
    });
    expect(Boolean(evaluate(payButton.props.disabled, scope([])))).toBe(true);
    expect(Boolean(evaluate(payButton.props.disabled, scope([{ id: 'dbank', is_active: true }])))).toBe(false);
  });
});
