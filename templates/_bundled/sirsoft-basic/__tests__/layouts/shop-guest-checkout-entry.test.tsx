/**
 * @file shop-guest-checkout-entry.test.tsx
 * @description 비회원 주문 진입 분기 구조 검증 (Issue #55)
 *
 * 상품상세(_purchase_card) / 장바구니(_cart_summary) 의 "바로구매"·"주문하기" 는
 * 임시주문(cart → checkout) 생성 후 다음으로 분기한다:
 *   - 로그인: 주문서(/{shopBase}/checkout)로 직행
 *   - 비로그인: 로그인 페이지(/login?redirect={shopBase}/checkout)로 이동
 *     (임시주문은 cart_key(localStorage)로 유지되어 복귀 후에도 조회됨)
 *
 * 로그인 페이지(_login_form)는 redirect 가 checkout 일 때만 "비회원으로 주문하기" 버튼을
 * 노출하고, 클릭 시 redirect 경로로 navigate 한다.
 *
 * 회귀 차단 핵심: 로그인 판정 키는 반드시 `_global.currentUser?.uuid`.
 * `_global.auth?.isLoggedIn` 은 어디서도 set 되지 않는 undefined 키로,
 * 사용 시 로그인 사용자도 항상 비로그인 분기로 강제되는 회귀를 일으킨다.
 */

import { describe, it, expect } from 'vitest';
import purchaseCardJson from '../../layouts/partials/shop/detail/_purchase_card.json';
import cartSummaryJson from '../../layouts/partials/shop/_cart_summary.json';
import loginJson from '../../layouts/auth/login.json';
import checkoutSummaryJson from '../../layouts/partials/shop/_checkout_summary.json';
import checkoutOrdererJson from '../../layouts/partials/shop/_checkout_orderer.json';

/** 객체 트리에서 조건을 만족하는 첫 노드를 깊이우선 탐색 */
function findNode(node: any, predicate: (n: any) => boolean): any {
  if (node == null || typeof node !== 'object') return undefined;
  if (predicate(node)) return node;
  for (const value of Object.values(node)) {
    if (Array.isArray(value)) {
      for (const item of value) {
        const found = findNode(item, predicate);
        if (found !== undefined) return found;
      }
    } else if (value && typeof value === 'object') {
      const found = findNode(value, predicate);
      if (found !== undefined) return found;
    }
  }
  return undefined;
}

/** checkout apiCall 의 onSuccess 내부 conditions 핸들러 블록을 찾는다 */
function findCheckoutConditions(layout: any): any {
  const checkoutCall = findNode(
    layout,
    (n) =>
      n.handler === 'apiCall' &&
      typeof n.target === 'string' &&
      n.target.includes('/checkout')
  );
  expect(checkoutCall, 'checkout apiCall 노드가 존재해야 함').toBeDefined();
  const conditionsAction = (checkoutCall.onSuccess ?? []).find(
    (a: any) => a.handler === 'conditions' && Array.isArray(a.conditions)
  );
  expect(conditionsAction, 'checkout onSuccess 에 conditions 분기가 존재해야 함').toBeDefined();
  return conditionsAction.conditions;
}

describe.each([
  ['상품상세 _purchase_card', purchaseCardJson],
  ['장바구니 _cart_summary', cartSummaryJson],
])('비회원 주문 진입 분기 - %s (Issue #55)', (_label, layout) => {
  it('checkout 성공 후 로그인 분기는 _global.currentUser?.uuid 로 판정해야 한다', () => {
    const conditions = findCheckoutConditions(layout);
    const loginBranch = conditions[0];

    expect(loginBranch.if).toBe('{{_global.currentUser?.uuid}}');
    // 회귀 차단: 어디서도 set 되지 않는 키 → 로그인 사용자도 항상 falsy
    expect(loginBranch.if).not.toContain('auth?.isLoggedIn');
    expect(loginBranch.if).not.toContain('auth.isLoggedIn');

    // 로그인 분기는 주문서로 직행
    expect(loginBranch.then.handler).toBe('navigate');
    expect(loginBranch.then.params.path).toBe('{{_global.shopBase}}/checkout');
  });

  it('비로그인 분기(else)는 /login?redirect={shopBase}/checkout 로 이동해야 한다', () => {
    const conditions = findCheckoutConditions(layout);
    const guestBranch = conditions[conditions.length - 1];

    // else 분기는 if 없이 then 만 존재
    expect(guestBranch.if).toBeUndefined();
    expect(guestBranch.then.handler).toBe('navigate');
    expect(guestBranch.then.params.path).toBe('/login');
    expect(guestBranch.then.params.query.redirect).toBe('{{_global.shopBase}}/checkout');
  });
});

describe('로그인 화면 - 비회원 주문하기 버튼 (Issue #55)', () => {
  /**
   * 비회원 주문 버튼은 Form 바깥(auth/login.json) 에 두어야 폼 submit 으로 가로채지지 않는다.
   * _login_form.json (Form) 안에 두면 type=button 명시에도 불구하고 클릭이 동작하지 않는 회귀가 있었다.
   */
  const guestBlock = findNode(
    loginJson,
    (n) =>
      typeof n.if === 'string' &&
      n.if.includes('query.redirect') &&
      n.if.includes('checkout')
  );

  it('비회원 주문 버튼 블록은 Form 바깥(auth/login.json) 에 위치해야 한다', () => {
    expect(guestBlock).toBeDefined();
    // 회귀 차단: 폼 partial(_login_form.json) 에는 비회원 버튼이 없어야 한다
    // (있으면 Form submit 으로 가로채져 navigate 가 실행되지 않음)
    // 폼 자체는 import 하지 않지만, login.json 의 partial 참조 구조를 통해 확인
    const formPartialRef = findNode(
      loginJson,
      (n) => n.partial === 'partials/auth/_login_form.json'
    );
    expect(formPartialRef).toBeDefined();
  });

  it('redirect 가 checkout 을 포함할 때만 노출되는 컨테이너가 존재해야 한다', () => {
    expect(guestBlock).toBeDefined();
    // if 식 전체가 {{}} 로 감싸져야 ConditionEvaluator 가 식으로 평가
    expect(guestBlock.if.startsWith('{{')).toBe(true);
    expect(guestBlock.if.endsWith('}}')).toBe(true);
    expect(guestBlock.if).toBe("{{query.redirect && query.redirect.includes('checkout')}}");
  });

  it('비회원 주문 버튼은 type=button 이며 redirect 경로로 navigate 해야 한다', () => {
    const button = findNode(
      guestBlock,
      (n) => n.name === 'Button' && n.text === '$t:auth.guest_checkout.continue'
    );
    expect(button).toBeDefined();
    // submit 방지를 위해 type=button 명시 (Form 바깥이지만 명시는 회귀 차단에 안전)
    expect(button.props.type).toBe('button');

    const navAction = (button.actions ?? []).find((a: any) => a.handler === 'navigate');
    expect(navAction).toBeDefined();
    expect(navAction.params.path).toBe('{{query.redirect}}');
  });

  it('번역 키가 i18n 자원에 등록되어 있어야 한다 (ko/en partial)', async () => {
    const koAuth: any = (await import('../../lang/partial/ko/auth.json')).default;
    const enAuth: any = (await import('../../lang/partial/en/auth.json')).default;

    expect(koAuth.guest_checkout.continue).toBe('비회원으로 주문하기');
    expect(koAuth.guest_checkout.divider).toBe('또는');
    expect(koAuth.guest_checkout.hint).toBe('회원가입 없이 주문할 수 있습니다.');

    expect(enAuth.guest_checkout.continue).toBe('Continue as guest');
    expect(enAuth.guest_checkout.divider).toBe('or');
    expect(enAuth.guest_checkout.hint).toBe('You can place an order without signing up.');
  });
});

describe('결제하기 엔드포인트 분기 - _checkout_summary (Issue #55)', () => {
  /** 결제하기 버튼 안의 orders apiCall 노드를 찾는다 */
  const ordersApiCall = findNode(
    checkoutSummaryJson,
    (n) =>
      n.handler === 'apiCall' &&
      typeof n.target === 'string' &&
      n.target.includes('orders')
  );

  it('결제 target 은 회원/비회원 공통 단일 endpoint user/orders 여야 한다 (PG 인터셉터 매칭 회복)', () => {
    expect(ordersApiCall).toBeDefined();
    // PG 플러그인 fetch 인터셉터가 /api/modules/sirsoft-ecommerce/user/orders 한 경로만 매칭하므로
    // 회원/비회원이 동일 endpoint 로 호출해야 비회원 주문에서도 PG 결제창이 정상 노출된다.
    // 회원/비회원 분기는 백엔드 PublicOrderController::store 가 Auth::id() 로 처리.
    // (이전엔 user/orders ↔ guest/orders 표현식 분기였으나, 비회원 주문이 guest/orders 로 가면
    //  PG 인터셉터가 못 가로채 결제창이 안 뜨던 회귀가 있어 단일 endpoint 로 통일)
    expect(ordersApiCall.target).toBe('/api/modules/sirsoft-ecommerce/user/orders');
  });

  it('cart_key 헤더가 전달되어야 guest/orders 가 비회원 임시주문을 매칭할 수 있다', () => {
    expect(ordersApiCall.params?.headers?.['X-Cart-Key']).toBe('{{_global.cartKey}}');
  });

  it('body 에 비회원 조회 비밀번호 + 확인이 비로그인일 때만 값으로 전송되어야 한다', () => {
    const body = ordersApiCall.params?.body ?? {};
    // 회원이면 null, 비회원이면 _local.guestLookupPassword 값
    expect(body.guest_lookup_password).toContain('_global.currentUser?.uuid');
    expect(body.guest_lookup_password).toContain('_local.guestLookupPassword');
    expect(body.guest_lookup_password_confirmation).toContain('_local.guestLookupPasswordConfirmation');
  });
});

describe('비회원 조회 비밀번호 입력 섹션 - _checkout_orderer (Issue #55)', () => {
  /**
   * 비회원 조회 비밀번호 섹션 컨테이너.
   *
   * 비로그인 조건(!_global.currentUser?.uuid)은 이메일 필수 표시(*) Span 에도 쓰이므로,
   * if 조건만으로는 식별이 모호하다. PasswordInput(guest_lookup_password)을 자손으로
   * 포함하는 컨테이너로 좁혀 식별한다.
   */
  const guestSection = findNode(
    checkoutOrdererJson,
    (n) =>
      typeof n.if === 'string' &&
      n.if.includes('!_global.currentUser?.uuid') &&
      findNode(n, (c: any) => c.name === 'PasswordInput' && c.props?.name === 'guest_lookup_password') !== undefined
  );

  it('비로그인(_global.currentUser?.uuid 없음)일 때만 노출되는 컨테이너가 존재해야 한다', () => {
    expect(guestSection).toBeDefined();
    expect(guestSection.if).toBe('{{!_global.currentUser?.uuid}}');
  });

  it('조회 비밀번호 + 확인 PasswordInput 2개가 포함되어야 한다', () => {
    const passwordInput = findNode(
      guestSection,
      (n) =>
        n.name === 'PasswordInput' &&
        n.props?.name === 'guest_lookup_password'
    );
    expect(passwordInput).toBeDefined();
    expect(passwordInput.props.showToggle).toBe(true);

    const confirmInput = findNode(
      guestSection,
      (n) =>
        n.name === 'PasswordInput' &&
        n.props?.name === 'guest_lookup_password_confirmation'
    );
    expect(confirmInput).toBeDefined();
    expect(confirmInput.props.showToggle).toBe(true);
  });

  it('PasswordInput 의 onChange 가 _local.guestLookupPassword(_Confirmation) 에 저장해야 한다', () => {
    const passwordInput = findNode(
      guestSection,
      (n) => n.name === 'PasswordInput' && n.props?.name === 'guest_lookup_password'
    );
    const changeAction = (passwordInput.actions ?? []).find((a: any) => a.type === 'change');
    expect(changeAction.handler).toBe('setState');
    expect(changeAction.params.target).toBe('local');
    expect(changeAction.params.guestLookupPassword).toBe('{{$event.target.value}}');

    const confirmInput = findNode(
      guestSection,
      (n) => n.name === 'PasswordInput' && n.props?.name === 'guest_lookup_password_confirmation'
    );
    const confirmChange = (confirmInput.actions ?? []).find((a: any) => a.type === 'change');
    expect(confirmChange.params.guestLookupPasswordConfirmation).toBe('{{$event.target.value}}');
  });

  it('번역 키가 i18n 자원에 등록되어 있어야 한다 (ko/en partial)', async () => {
    const koShop: any = (await import('../../lang/partial/ko/shop.json')).default;
    const enShop: any = (await import('../../lang/partial/en/shop.json')).default;

    expect(koShop.checkout.guest_lookup_section).toBe('비회원 주문 조회');
    expect(koShop.checkout.guest_lookup_password).toBe('조회 비밀번호');
    expect(koShop.checkout.guest_lookup_password_confirmation).toBe('비밀번호 확인');

    expect(enShop.checkout.guest_lookup_section).toBe('Guest order lookup');
    expect(enShop.checkout.guest_lookup_password).toBe('Lookup password');
    expect(enShop.checkout.guest_lookup_password_confirmation).toBe('Confirm password');
  });
});

describe('주문자 이메일 필수 표시 - _checkout_orderer (Issue #55)', () => {
  /** 이메일 입력란 */
  const emailInput = findNode(
    checkoutOrdererJson,
    (n) => n.name === 'Input' && n.props?.name === 'orderer_email'
  );

  it('orderer_email 입력란이 존재해야 한다', () => {
    expect(emailInput).toBeDefined();
    expect(emailInput.props.type).toBe('email');
  });

  it('이메일 라벨에 비회원일 때만 노출되는 필수 표시(*) Span 이 있어야 한다', () => {
    // 라벨(orderer_email 텍스트)을 children 으로 가진 Label 노드
    const emailLabel = findNode(
      checkoutOrdererJson,
      (n) =>
        n.name === 'Label' &&
        Array.isArray(n.children) &&
        n.children.some((c: any) => c.text === '$t:shop.checkout.orderer_email')
    );
    expect(emailLabel).toBeDefined();

    // 필수 표시(*) Span 은 비로그인(!_global.currentUser?.uuid) 조건으로만 노출
    const requiredMark = (emailLabel.children ?? []).find(
      (c: any) => c.name === 'Span' && c.text === '*'
    );
    expect(requiredMark).toBeDefined();
    expect(requiredMark.if).toBe('{{!_global.currentUser?.uuid}}');
  });

  it('이메일 오류 메시지 표시 Span 이 errors[orderer.email] 에 바인딩되어야 한다', () => {
    const errorSpan = findNode(
      checkoutOrdererJson,
      (n) =>
        n.name === 'Span' &&
        typeof n.if === 'string' &&
        n.if.includes("errors?.['orderer.email']")
    );
    expect(errorSpan).toBeDefined();
  });
});