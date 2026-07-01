/**
 * @file shop-guest-order-flow.test.tsx
 * @description 비회원 주문 결제 → 자동 verify → 완료 → 조회 흐름 구조 검증 ( 단계 5 그룹 C+D)
 *
 * 그룹 A+B 가 진입(_purchase_card / _cart_summary / _login_form) + 주문서(_checkout_*) 를 다뤘다면,
 * 본 테스트는 그 이후 흐름:
 *   - 결제 완료 시 비회원 자동 verify (그룹 C)
 *   - 완료 화면 회원/비회원 분기 + 30분 자동 인증 안내 (그룹 C)
 *   - 비회원 조회 폼 (그룹 C)
 *   - 진입 동선 (그룹 D — 모바일 nav, 로그인 화면, Header 컴포넌트)
 *
 * 핵심 설계 검증:
 *   - 공유 라우트 `/shop/orders/:id/complete` (auth_required: false)
 *   - data_sources `if` 분기로 user/orders ↔ guest/orders
 *   - globalHeaders 가 X-Guest-Order-Token 자동 주입 (guest/orders/* 패턴)
 *   - 401/404 errorHandling 이 토큰 삭제 + 조회 폼 안내
 *   - 사용자 노출 텍스트에 "토큰" 같은 내부 용어 없음
 */

import { describe, it, expect } from 'vitest';
import routesJson from '../../routes.json';
import userBaseJson from '../../layouts/_user_base.json';
import checkoutSummaryJson from '../../layouts/partials/shop/_checkout_summary.json';
import orderCompleteJson from '../../layouts/shop/order_complete.json';
import guestFormJson from '../../layouts/shop/guest_order_form.json';
import guestShowJson from '../../layouts/shop/guest_order_show.json';
import shippingPartialJson from '../../layouts/partials/mypage/orders/_shipping.json';
import loginJson from '../../layouts/auth/login.json';
import loginFormJson from '../../layouts/partials/auth/_login_form.json';

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

describe('routes.json — 공유 라우트 + 비회원 조회 라우트 ( 그룹 C+D)', () => {
  const routes: any[] = (routesJson as any).routes;

  it('주문 완료 라우트는 회원/비회원 공유로 auth_required: false 여야 한다', () => {
    const completeRoute = routes.find((r) => r.layout === 'shop/order_complete');
    expect(completeRoute).toBeDefined();
    // 회귀 차단: auth_required:true 로 두면 비회원이 로그인 페이지로 튕김
    expect(completeRoute.auth_required).toBe(false);
  });

  it('비회원 주문 조회 폼 라우트(/shop/guest/orders)가 등록되어야 한다', () => {
    const lookupRoute = routes.find((r) => r.layout === 'shop/guest_order_form');
    expect(lookupRoute).toBeDefined();
    expect(lookupRoute.auth_required).toBe(false);
    expect(lookupRoute.path).toContain('guest/orders');
  });
});

describe('_user_base.json — 토큰 인프라 ( 그룹 C)', () => {
  it('globalHeaders 에 X-Guest-Order-Token 은 guest/orders/* 후속 액션 패턴에만 등록되어야 한다 (통합 상세조회는 데이터소스 개별 headers 사용)', () => {
    const globalHeaders = (userBaseJson as any).globalHeaders;

    // 비회원 후속 액션 (cancel/estimate-refund/...) 용 패턴 유지
    const guestOrdersHeader = globalHeaders.find(
      (h: any) => h.pattern === '/api/modules/sirsoft-ecommerce/guest/orders/*'
    );
    expect(guestOrdersHeader, 'guest/orders/* 패턴 누락 — 비회원 후속 액션에 토큰 주입 안됨').toBeDefined();
    expect(guestOrdersHeader.headers['X-Guest-Order-Token']).toBe('{{_global.guestOrderToken}}');

    // 회귀 차단: 후속 보완 6 에서 user/orders/* 광역 패턴 제거. 회원 전용 라우트(목록/취소/구매확정 등) 에 토큰 노출 방지.
    // 통합 상세조회(user/orders/{N}) 는 order_complete.json + guest_order_show.json 의 데이터소스 개별 headers 로 명시.
    const userOrdersHeader = globalHeaders.find(
      (h: any) => h.pattern === '/api/modules/sirsoft-ecommerce/user/orders/*'
    );
    expect(userOrdersHeader, 'user/orders/* 광역 패턴은 회원 전용 라우트에도 토큰을 노출하므로 globalHeaders 에 두지 않는다').toBeUndefined();
  });

  it('order_complete.json orderData 데이터소스에 X-Guest-Order-Token 헤더가 명시되어야 한다 (회귀 차단)', () => {
    const dataSources: any[] = (orderCompleteJson as any).data_sources;
    const orderSource = dataSources.find((d) => d.id === 'orderData');
    expect(orderSource?.headers?.['X-Guest-Order-Token']).toBe('{{_global.guestOrderToken}}');
  });

  it('init_actions 에 initGuestOrderToken 커스텀 핸들러가 등록되어야 한다 (표준 loadFromLocalStorage 는 _local 만 갱신하므로 _global 토큰 주입 불가)', () => {
    const tokenInit = (userBaseJson as any).init_actions.find(
      (a: any) => a.handler === 'initGuestOrderToken'
    );
    expect(tokenInit).toBeDefined();

    // 회귀 차단: 표준 loadFromLocalStorage 로 되돌리면 _global.guestOrderToken 이 안 들어가 globalHeaders 가 빈 토큰을 주입 → 항상 404
    const standardLoad = (userBaseJson as any).init_actions.find(
      (a: any) => a.handler === 'loadFromLocalStorage' && a.params?.key === 'g7_guest_order_token'
    );
    expect(standardLoad).toBeUndefined();
  });

  it('모바일 nav 비로그인 블록의 \'주문조회\' 버튼은 로그인 페이지(/login?redirect=/mypage/orders) 로 진입해야 한다 (회원/비회원 단일 진입점 — 로그인 폼이 redirect 파라미터로 분기, 비회원은 로그인 화면의 비회원 조회 링크 사용)', () => {
    const lookupButton = findNode(
      userBaseJson,
      (n) => n.name === 'Button' && n.text === '$t:shop.guest_order_form.nav_link'
    );
    expect(lookupButton, '모바일 nav 의 주문조회 버튼 누락').toBeDefined();

    const navAction = findNode(
      lookupButton,
      (a) => a.handler === 'navigate' && typeof a.params?.path === 'string' && a.params.path.startsWith('/login')
    );
    expect(navAction, '/login 으로 navigate 하지 않음 — 회원이 로그아웃 상태에서 자기 주문을 못 찾는 케이스 회귀').toBeDefined();
    expect(navAction.params.path).toContain('redirect=/mypage/orders');

    // 회귀 차단: 이전처럼 직접 guest/orders 폼으로 보내면 로그아웃된 회원이 막다른 길에 빠짐
    const legacyDirect = findNode(
      lookupButton,
      (a) => a.handler === 'navigate' && typeof a.params?.path === 'string' && a.params.path.includes('guest/orders')
    );
    expect(legacyDirect, '주문조회 클릭이 곧장 비회원 lookup 으로 가는 패턴 잔존 — 로그아웃된 회원 케이스 회귀').toBeUndefined();
  });
});

describe('_checkout_summary.json — 비회원 자동 verify 분기 ( 그룹 C)', () => {
  /** 결제 onSuccess 안의 자동 verify apiCall 노드 */
  const verifyCall = findNode(
    checkoutSummaryJson,
    (n) =>
      n.handler === 'apiCall' &&
      typeof n.target === 'string' &&
      n.target.endsWith('/guest/orders/verify')
  );

  it('결제 onSuccess 안에 비회원 자동 verify apiCall 이 존재해야 한다', () => {
    expect(verifyCall).toBeDefined();
  });

  it('verify body 는 store 응답 order_number 와 _local 의 orderer.phone + guestLookupPassword 를 사용해야 한다', () => {
    expect(verifyCall.params?.body).toMatchObject({
      order_number: '{{response.data.order.order_number}}',
      orderer_phone: '{{_local.orderer?.phone ?? \'\'}}',
      guest_lookup_password: '{{_local.guestLookupPassword ?? \'\'}}',
    });
  });

  it('verify onSuccess 가 saveGuestOrderToken 핸들러로 sessionStorage 3개 키(token/orderNumber/expiresAt)와 _global.guestOrderToken 을 동시에 설정해야 한다', () => {
    const onSuccessActions = verifyCall.onSuccess ?? [];
    const saveAction = onSuccessActions.find(
      (a: any) => a.handler === 'saveGuestOrderToken'
    );
    expect(saveAction, 'saveGuestOrderToken 핸들러 누락 — sessionStorage 3개 키 저장 + _global 동기화가 동작하지 않음').toBeDefined();
    expect(saveAction.params).toMatchObject({
      token: '{{response.data.guest_order_token}}',
      orderNumber: '{{response.data.order.order_number}}',
      expiresAt: '{{response.data.expires_at}}',
    });
  });

  it('verify 분기 컨테이너는 비로그인일 때만 실행되어야 한다 (!_global.currentUser?.uuid)', () => {
    // verifyCall 이 속한 conditions.then 의 부모 if 가 비회원 조건이어야 함
    const verifyConditionsBlock = findNode(
      checkoutSummaryJson,
      (n) =>
        n.handler === 'conditions' &&
        Array.isArray(n.conditions) &&
        n.conditions.some(
          (c: any) =>
            c.if === '{{!_global.currentUser?.uuid}}' &&
            findNode(c, (m: any) => m.handler === 'apiCall' && m.target?.endsWith('/guest/orders/verify'))
        )
    );
    expect(verifyConditionsBlock).toBeDefined();
  });
});

describe('order_complete.json — endpoint 표현식 분기 ( 그룹 C)', () => {
  const dataSources: any[] = (orderCompleteJson as any).data_sources;
  const orderSource = dataSources.find((d) => d.id === 'orderData');

  it('단일 orderData 데이터소스가 user/orders/{N} 통합 엔드포인트를 사용해야 한다 (회원/비회원 백엔드 분기)', () => {
    expect(orderSource).toBeDefined();
    const guestDup = dataSources.filter((d) => d.id === 'orderData');
    expect(guestDup.length).toBe(1);

    // 회귀 차단: endpoint 표현식에 _global.currentUser 분기를 다시 도입하면
    // currentUser 가 첫 프레임에 undefined 라 회원도 guest 분기로 호출되는 타이밍 결함이 재현됨.
    // 통합 엔드포인트 user/orders/{N} (optional.sanctum) 가 백엔드 Auth::check() 로 분기.
    expect(orderSource.endpoint).not.toContain('_global.currentUser');
    expect(orderSource.endpoint).toBe('/api/modules/sirsoft-ecommerce/user/orders/{{route.id}}');
    expect(orderSource.auth_mode).toBe('optional');
  });

  it('컴퓨티드 alias 는 사용하지 않아야 한다 (기존 {{orderData?.data?.*}} 표현식 직접 매칭)', () => {
    // computed alias 는 _computed.* 경로로만 접근 가능해 기존 표현식과 불일치
    const computed = (orderCompleteJson as any).computed;
    expect(computed?.orderData).toBeUndefined();
  });

  it('401/404 errorHandling 은 단일 sequence + 토스트 + 백엔드 redirect_to navigate 만 포함해야 한다', () => {
    for (const status of ['401', '404']) {
      const handler = orderSource.errorHandling?.[status];
      expect(handler, `errorHandling[${status}] missing`).toBeDefined();
      expect(handler.handler, `${status}: errorHandling 은 sequence 핸들러여야 함`).toBe('sequence');

      const actions = handler.params?.actions ?? [];

      // 토스트 — 백엔드 message 우선
      const toastAction = actions.find((a: any) => a.handler === 'toast');
      expect(toastAction, `${status}: toast missing`).toBeDefined();
      expect(toastAction.params?.message, `${status}: toast 가 백엔드 error.message 를 사용해야 함`).toContain('{{error.message');

      // navigate — 백엔드 errors.redirect_to 우선 + /login fallback
      const navAction = actions.find((a: any) => a.handler === 'navigate');
      expect(navAction, `${status}: navigate missing`).toBeDefined();
      expect(navAction.params?.path, `${status}: navigate path 가 error.errors.redirect_to 를 사용해야 함`).toContain('error.errors?.redirect_to');
      expect(navAction.params?.path, `${status}: navigate path 에 /login fallback 필요`).toContain('/login');
    }
  });

  it('비회원 안내 블록이 비회원에게만 노출되어야 한다 (!_global.currentUser?.uuid, guest_notice_title)', () => {
    const noticeBlock = findNode(
      orderCompleteJson,
      (n) =>
        n.if === '{{!_global.currentUser?.uuid}}' &&
        findNode(n, (m: any) => m.text === '$t:shop.order_complete.guest_notice_title')
    );
    expect(noticeBlock).toBeDefined();
  });

});

describe('guest_order_form.json — 비회원 조회 폼 ( 그룹 C)', () => {
  it('주문번호 + 휴대폰 + 비밀번호 3개 입력 필드가 등록되어야 한다 (verify API 계약 일치)', () => {
    for (const fieldName of ['order_number', 'orderer_phone', 'guest_lookup_password']) {
      const input = findNode(
        guestFormJson,
        (n) => (n.name === 'Input' || n.name === 'PasswordInput') && n.props?.name === fieldName
      );
      expect(input, `input ${fieldName} missing`).toBeDefined();
    }
  });

  it('submit 액션은 guest/orders/verify 호출 후 saveGuestOrderToken 으로 sessionStorage 3개 키 저장 + 비회원 주문 상세로 navigate 해야 한다', () => {
    const verifyCall = findNode(
      guestFormJson,
      (n) =>
        n.handler === 'apiCall' &&
        typeof n.target === 'string' &&
        n.target.endsWith('/guest/orders/verify')
    );
    expect(verifyCall).toBeDefined();

    const onSuccessActions = verifyCall.onSuccess ?? [];
    const save = onSuccessActions.find((a: any) => a.handler === 'saveGuestOrderToken');
    expect(save, 'saveGuestOrderToken 핸들러 누락 — sessionStorage 토큰/주문번호/만료시각 3개 키 저장 실패').toBeDefined();
    expect(save.params).toMatchObject({
      token: '{{response.data.guest_order_token}}',
      orderNumber: '{{response.data.order.order_number}}',
      expiresAt: '{{response.data.expires_at}}',
    });

    const nav = onSuccessActions.find(
      (a: any) => a.handler === 'navigate' && a.params?.path?.includes('guest/orders/')
    );
    expect(nav).toBeDefined();
    // 비회원 주문 상세 페이지로 이동 (결제 완료 화면이 아닌 상세 화면)
    expect(nav.params.path).toContain('/guest/orders/{{response.data.order.order_number}}');
  });

  it('init_actions 에 clearGuestTokenOnEntry 가 등록되어 조회 폼 진입 시 매번 폼이 노출되도록 sessionStorage 토큰을 초기화해야 한다 (eBay/Best Buy/카페24/11번가 등 표준 패턴 정합)', () => {
    const initActions = (guestFormJson as any).init_actions ?? [];
    const clear = initActions.find((a: any) => a.handler === 'clearGuestTokenOnEntry');
    expect(clear, 'clearGuestTokenOnEntry 누락 — 토큰 보유자에게는 자동 진입되어 폼 노출 안 됨, 다른 주문 조회 불가').toBeDefined();
  });

  it('onError 는 _local.lookupError boolean 플래그만 set 해야 한다 (toast 가 아닌 페이지 내 인라인 에러 박스로 통일 메시지 노출 — PO UX 결정 + 보안 정책 실패 사유 비노출)', () => {
    const verifyCall = findNode(
      guestFormJson,
      (n) => n.handler === 'apiCall' && n.target?.endsWith('/guest/orders/verify')
    );
    const onErrorActions = verifyCall.onError ?? [];

    // setState 가 lookupError: true 를 포함해야 함
    const setStateAction = onErrorActions.find(
      (a: any) => a.handler === 'setState' && a.params?.lookupError === true
    );
    expect(setStateAction, 'onError 의 setState 가 lookupError 플래그를 켜지 않음 — 인라인 에러 박스 노출 불가').toBeDefined();
  });

  it('폼 상단에 인라인 에러 박스가 _local.lookupError 조건부로 렌더되어야 한다 (통일 not_found 메시지)', () => {
    const errorBlock = findNode(
      guestFormJson,
      (n) =>
        n.if === '{{_local.lookupError}}' &&
        findNode(n, (m: any) => m.name === 'P' && m.text === '$t:shop.guest_order_form.not_found')
    );
    expect(errorBlock, '인라인 에러 박스 누락 — lookupError 플래그가 켜져도 사용자에게 실패 안내 노출 안 됨').toBeDefined();
  });

  it('submit sequence 시작 시 lookupError 가 false 로 초기화되어야 한다 (재시도 시 이전 에러 박스 자동 사라짐)', () => {
    const submitSequence = findNode(
      guestFormJson,
      (n) => n.type === 'submit' && n.handler === 'sequence'
    );
    expect(submitSequence).toBeDefined();

    const resetAction = (submitSequence.actions ?? []).find(
      (a: any) => a.handler === 'setState' && a.params?.lookupError === false && a.params?.isSubmitting === true
    );
    expect(resetAction, 'submit 시작 시 lookupError 초기화 누락 — 이전 실패 박스가 그대로 노출됨').toBeDefined();
  });

  it('회원 로그인 사용자가 실수로 진입한 경우 lifecycle.onMount 가 toast + /mypage/orders 로 navigate 해야 한다 (PO UX 결정 — 회원은 본 페이지에서 회원 주문 조회 불가)', () => {
    const memberGuardBlock = findNode(
      guestFormJson,
      (n) => n.if === '{{_global?.currentUser?.uuid}}' && findNode(n, (m: any) => m.lifecycle?.onMount)
    );
    expect(memberGuardBlock, '회원 감지 가드 블록 누락 — 회원이 폼 입력 후 조회 시 백엔드가 404 통일 응답으로 차단해 혼란 발생').toBeDefined();

    const onMountActions = findNode(memberGuardBlock, (n) => Array.isArray(n.onMount))?.onMount ?? [];

    const toast = onMountActions.find((a: any) => a.handler === 'toast');
    expect(toast, '회원 redirect toast 누락').toBeDefined();
    expect(toast.params.message).toBe('$t:shop.guest_order_form.member_redirect_toast');

    const nav = onMountActions.find((a: any) => a.handler === 'navigate');
    expect(nav, '회원 redirect navigate 누락').toBeDefined();
    expect(nav.params.path).toBe('/mypage/orders');

    const toastIdx = onMountActions.findIndex((a: any) => a.handler === 'toast');
    const navIdx = onMountActions.findIndex((a: any) => a.handler === 'navigate');
    expect(toastIdx, 'toast 는 navigate 보다 앞에 와야 함 (페이지 이동 전에 안내 메시지 push)').toBeLessThan(navIdx);
  });

  it('조회 폼 컨테이너는 비회원에게만 노출되어야 한다 (회귀 차단 — 회원도 폼이 깜빡 노출되면 회원 redirect 가 발화하기 전 입력 가능)', () => {
    const formContainer = findNode(
      guestFormJson,
      (n) =>
        n.if === '{{!_global?.currentUser?.uuid}}' &&
        findNode(n, (m: any) => (m.name === 'Input' || m.name === 'PasswordInput') && m.props?.name === 'order_number')
    );
    expect(formContainer, '비회원 전용 가드 누락 — 회원에게도 조회 폼이 노출될 수 있음').toBeDefined();
  });

});

describe('_login_form.json — 로그인 성공 시 비회원 토큰 정리', () => {
  it('login onSuccess 가 clearGuestOrderToken 핸들러로 sessionStorage 3개 키 + _global.guestOrderToken 을 한 번에 비워야 한다 (회귀 차단 — 동일 브라우저 회원/비회원 컨텍스트 분리)', () => {
    const loginCall = findNode(
      loginFormJson,
      (n) => n.handler === 'login'
    );
    expect(loginCall).toBeDefined();

    const onSuccessActions = loginCall.onSuccess ?? [];

    const clearToken = onSuccessActions.find((a: any) => a.handler === 'clearGuestOrderToken');
    expect(clearToken, '로그인 onSuccess 에 clearGuestOrderToken 핸들러 누락 — 비회원 sessionStorage 토큰이 회원 세션과 공존하게 됨').toBeDefined();
  });
});

describe('order_complete.json — 깜빡임 방지', () => {
  it('content 슬롯 최상위 Container 에 blur_until_loaded 가 orderData 응답을 대기해야 한다 (회귀 차단 — 가드 발화 전 빈 화면 한 프레임 노출)', () => {
    const contentRoot = (orderCompleteJson as any).slots?.content?.[0];
    expect(contentRoot).toBeDefined();

    const blur = contentRoot.blur_until_loaded;
    expect(blur).toBeTruthy();

    // 회귀 차단: 표현식 문자열 형태로 두면 blur 의미가 반대로 동작 (truthy 면 blur 켜짐 → 데이터 도착 후 영구 blur).
    // 객체 형태 { enabled, data_sources } 만 허용.
    expect(typeof blur).toBe('object');
    expect(blur.enabled).toBe(true);
    expect(Array.isArray(blur.data_sources)).toBe(true);
    expect(blur.data_sources).toContain('orderData');
  });
});

describe('auth/login.json — 비회원 조회 진입 링크 ( 그룹 D)', () => {
  it('항상 노출되는 비회원 조회 안내 링크가 있어야 한다 (checkout 조건과 별개)', () => {
    const link = findNode(
      loginJson,
      (n) => n.name === 'A' && n.text === '$t:shop.guest_order_form.login_screen_link'
    );
    expect(link).toBeDefined();
    expect(link.props?.href).toContain('guest/orders');
  });
});

describe('i18n — 신규 키 등록 + 사용자 친화 표현 ( 그룹 C+D)', () => {
  it('shop.json ko/en 의 guest_order_form 객체 키가 동기화되어야 한다', async () => {
    const ko: any = (await import('../../lang/partial/ko/shop.json')).default;
    const en: any = (await import('../../lang/partial/en/shop.json')).default;

    expect(ko.guest_order_form).toBeDefined();
    expect(en.guest_order_form).toBeDefined();

    const koKeys = Object.keys(ko.guest_order_form).sort().join(',');
    const enKeys = Object.keys(en.guest_order_form).sort().join(',');
    expect(koKeys).toBe(enKeys);

    // 필수 키 존재 검증
    for (const key of ['title', 'order_number', 'orderer_phone', 'password', 'submit', 'not_found', 'nav_link', 'login_screen_link']) {
      expect(ko.guest_order_form[key], `ko.guest_order_form.${key} 누락`).toBeTruthy();
      expect(en.guest_order_form[key], `en.guest_order_form.${key} 누락`).toBeTruthy();
    }
  });

  it('order_complete 의 비회원 안내 키가 ko/en 모두 등록되어야 한다', async () => {
    const ko: any = (await import('../../lang/partial/ko/shop.json')).default;
    const en: any = (await import('../../lang/partial/en/shop.json')).default;

    for (const key of ['guest_lookup_link', 'guest_token_expired']) {
      expect(ko.order_complete[key]).toBeTruthy();
      expect(en.order_complete[key]).toBeTruthy();
    }
  });

  it('사용자에게 노출되는 값에 "토큰" 같은 내부 용어가 없어야 한다 (UX)', async () => {
    const ko: any = (await import('../../lang/partial/ko/shop.json')).default;
    const en: any = (await import('../../lang/partial/en/shop.json')).default;

    // 검사 대상 — 사용자가 화면에서 읽는 값들
    const userFacingValues = [
      ko.checkout.guest_token_issue_failed,
      ko.order_complete.guest_token_expired,
      en.checkout.guest_token_issue_failed,
      en.order_complete.guest_token_expired,
      ...Object.values(ko.guest_order_form as Record<string, string>),
      ...Object.values(en.guest_order_form as Record<string, string>),
    ];

    for (const value of userFacingValues) {
      expect(typeof value).toBe('string');
      // 회귀 차단: "토큰" / "token" 같은 내부 용어 노출 금지
      expect(value).not.toContain('토큰');
      expect(value.toLowerCase()).not.toContain('token');
    }
  });
});

describe('guest_order_show.json — 위조/만료 토큰 자동 정리 + 다른 주문 조회', () => {
  const dataSources: any[] = (guestShowJson as any).data_sources;
  const orderSource = dataSources.find((d) => d.id === 'order');

  it('errorHandling.401 이 clearGuestOrderToken → toast → navigate 순서로 동작해야 한다 (위조 토큰/관리자 비밀번호 재설정 시 sessionStorage 잔존으로 인한 무한 redirect 회귀 차단)', () => {
    const handler = orderSource.errorHandling?.['401'];
    expect(handler?.handler).toBe('sequence');
    const actions = handler.params?.actions ?? [];

    // clearGuestOrderToken 이 navigate 보다 앞에 와야 함 — 다음 페이지 진입 시 만료 안 된 잔존 토큰이 자동 진입을 또 발화시키는 것을 차단
    const clearIdx = actions.findIndex((a: any) => a.handler === 'clearGuestOrderToken');
    const navIdx = actions.findIndex((a: any) => a.handler === 'navigate');
    expect(clearIdx, 'errorHandling.401 에 clearGuestOrderToken 누락 — sessionStorage 잔존으로 무한 redirect 회귀 가능').toBeGreaterThanOrEqual(0);
    expect(navIdx).toBeGreaterThanOrEqual(0);
    expect(clearIdx).toBeLessThan(navIdx);

    // navigate fallback 은 비회원 조회 폼으로 (이전 /login fallback 에서 변경됨 — 비회원 흐름은 조회 폼이 정식 진입점)
    const navAction = actions[navIdx];
    expect(navAction.params?.path).toContain('/guest/orders');
  });

  it('errorHandling.404 도 동일하게 clearGuestOrderToken → toast → navigate 순서를 따라야 한다 (회귀 차단)', () => {
    const handler = orderSource.errorHandling?.['404'];
    expect(handler?.handler).toBe('sequence');
    const actions = handler.params?.actions ?? [];

    const clearIdx = actions.findIndex((a: any) => a.handler === 'clearGuestOrderToken');
    const navIdx = actions.findIndex((a: any) => a.handler === 'navigate');
    expect(clearIdx, 'errorHandling.404 에 clearGuestOrderToken 누락').toBeGreaterThanOrEqual(0);
    expect(clearIdx).toBeLessThan(navIdx);

    expect(actions[navIdx].params?.path).toContain('/guest/orders');
  });

  it('order 데이터소스가 _global.guestOrderToken 을 X-Guest-Order-Token 헤더로 읽어야 한다 (verify→상세 SPA 전이 토큰 누락 404 회귀 — 토큰 보존은 initGuestOrderToken 핸들러가 담당)', () => {
    // 배경: 조회 폼 verify onSuccess 의 saveGuestOrderToken 이 _global.guestOrderToken 을 동기 set 하고
    // sessionStorage 에도 기록한다. SPA 전이로 상세가 mount 되면 베이스 init_actions 의 initGuestOrderToken 이
    // 재실행되는데, sessionStorage 가 비어 있을 때(미영속/프라이버시 모드 등) in-memory _global 토큰을 null 로
    // 덮어쓰면 본 헤더가 빈 값으로 주입돼 404 가 된다. 핸들러가 그 경우 in-memory 토큰을 보존하도록 정정했고
    // (storageHandlers.test.ts 회귀 가드), 본 데이터소스는 그 _global 토큰을 헤더로 읽는다.
    expect(orderSource?.headers?.['X-Guest-Order-Token']).toBe('{{_global.guestOrderToken}}');
  });

});

describe('비회원 배송지 변경 지원 () — 회원/비회원 공통 + 탭 없는 직접입력', () => {
  it('배송지 수정 버튼은 회원/비회원 공통으로 노출된다 (currentUser 가드 없이 배송 전 상태만 조건)', () => {
    const changeBtn = findNode(
      shippingPartialJson,
      (n) => n.name === 'Button' && n.text === undefined &&
        Array.isArray(n.children) &&
        n.children.some((c: any) => c.text === '$t:mypage.order_detail.change_shipping_address')
    );
    expect(changeBtn, '배송지 수정 버튼을 찾지 못함').toBeDefined();
    // 회원 가드(currentUser) 제거 — 비회원도 노출. 조건은 배송 전 상태(order_status)만.
    expect(
      typeof changeBtn.if === 'string' && !changeBtn.if.includes('currentUser'),
      `배송지 수정 버튼 if 에 회원 가드(currentUser)가 남아있음 — 비회원 미노출 회귀: ${changeBtn.if}`
    ).toBe(true);
    expect(String(changeBtn.if)).toContain('order_status');
  });

  it('배송지 변경 모달 열기 시 changeAddressMode 를 회원=saved / 비회원=manual 로 분기 설정한다', () => {
    const changeBtn = findNode(
      shippingPartialJson,
      (n) => n.name === 'Button' && n.text === undefined &&
        Array.isArray(n.children) &&
        n.children.some((c: any) => c.text === '$t:mypage.order_detail.change_shipping_address')
    );
    const seq = (changeBtn.actions ?? []).find((a: any) => a.handler === 'sequence');
    const setState = (seq?.actions ?? []).find((a: any) => a.handler === 'setState');
    expect(setState, '모달 열기 setState 존재').toBeDefined();
    const mode = String(setState.params?.changeAddressMode);
    // currentUser 면 saved, 아니면 manual (단일 바인딩 삼항)
    expect(mode).toContain('currentUser?.uuid');
    expect(mode).toContain("'saved'");
    expect(mode).toContain("'manual'");
  });

  it('guest_order_show.json modals 에 _modal_change_address 가 등록되어야 한다 (비회원 배송지 변경 지원)', () => {
    const modals = (guestShowJson as any).modals ?? [];
    const hasChangeAddress = modals.some(
      (m: any) => m.partial === 'partials/mypage/orders/_modal_change_address.json'
    );
    expect(hasChangeAddress, '비회원 상세에 배송지 변경 모달 미등록 — 모달이 열리지 않음').toBe(true);
  });

  it('guest_order_show.json initLocal 에 isSubmittingAddress(스피너 상태)가 있다', () => {
    const initLocal = (guestShowJson as any).initLocal ?? {};
    expect(initLocal.isSubmittingAddress, 'isSubmittingAddress 누락 — 비회원 모달 스피너 미작동').toBe(false);
  });
});

describe('주문 상세 주문자 정보 표시 ()', () => {
  it('비회원 주문 상세가 _orderer partial 을 포함한다 (배송지 위에)', () => {
    const partials = JSON.stringify(guestShowJson);
    expect(partials.includes('partials/mypage/orders/_orderer.json'), '_orderer partial 미포함').toBe(true);
  });

  it('_orderer partial 이 order.data.orderer_name/phone/email 을 바인딩한다', async () => {
    const ordererJson: any = (await import('../../layouts/partials/mypage/orders/_orderer.json')).default;
    const json = JSON.stringify(ordererJson);
    expect(json).toContain('order.data.orderer_name');
    expect(json).toContain('order.data.orderer_phone');
    expect(json).toContain('order.data.orderer_email');
  });

  it('_orderer partial 의 다국어 키가 ko/en 에 정의되어 있다', async () => {
    const ko: any = (await import('../../lang/partial/ko/mypage.json')).default;
    const en: any = (await import('../../lang/partial/en/mypage.json')).default;
    for (const key of ['orderer_title', 'orderer_name', 'orderer_phone', 'orderer_email']) {
      expect(ko.order_detail?.[key], `ko: order_detail.${key} 누락`).toBeDefined();
      expect(en.order_detail?.[key], `en: order_detail.${key} 누락`).toBeDefined();
    }
  });
});
