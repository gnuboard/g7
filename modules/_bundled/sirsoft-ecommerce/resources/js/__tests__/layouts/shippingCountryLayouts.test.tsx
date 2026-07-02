import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

/**
 * 유저별 배송국가(preferred_shipping_country) — 레이아웃 구조 잠금 테스트 (MP08 후속)
 *
 * 헤더 공용 셀렉터(2섹션)·설정 카드·가입/관리자 필드·체크아웃 Select(B1)·주소록 분기(D10)·
 * B3 매핑·shippability 경고(3계층)·B8 상품상세를 JSON 구조 기준으로 회귀 차단한다.
 */

const moduleRoot = path.resolve(__dirname, '../../../../');
const templateRoot = path.resolve(__dirname, '../../../../../../../templates/_bundled/sirsoft-basic');
const adminTemplateRoot = path.resolve(__dirname, '../../../../../../../templates/_bundled/sirsoft-admin_basic');

const read = (rel: string, base = moduleRoot) =>
  JSON.parse(fs.readFileSync(path.resolve(base, rel), 'utf-8'));

const walk = (node: any, pred: (n: any) => boolean): any => {
  if (!node || typeof node !== 'object') return null;
  if (pred(node)) return node;
  for (const child of node.children ?? []) {
    const f = walk(child, pred);
    if (f) return f;
  }
  return null;
};

const walkAll = (node: any, pred: (n: any) => boolean, acc: any[] = []): any[] => {
  if (!node || typeof node !== 'object') return acc;
  if (pred(node)) acc.push(node);
  for (const child of node.children ?? []) walkAll(child, pred, acc);
  return acc;
};

const serialize = (rel: string, base = moduleRoot) =>
  fs.readFileSync(path.resolve(base, rel), 'utf-8');

describe('register-shipping-country-field.json — 가입폼 배송국가 필드 (D6)', () => {
  const json = read('resources/extensions/register-shipping-country-field.json');
  const components = json.injections.flatMap((i: any) => i.components ?? []);
  const select = components.map((c: any) =>
    walk(c, (n) => n.name === 'Select' && n.props?.name === 'preferred_shipping_country')).find(Boolean);

  it('가입폼(auth/register) register_extension_fields 앵커에 주입', () => {
    expect(json.target_layout).toBe('auth/register');
    expect(json.injections[0].target_id).toBe('register_extension_fields');
  });

  it('preferred_shipping_country Select + 우선순위 prefill', () => {
    expect(select).toBeTruthy();
    expect(select.props.value).toContain('preferredShippingCountry');
    expect(select.props.value).toContain('default_country');
  });

  it('해외배송 활성 게이트(if)', () => {
    expect(components[0].if).toContain('international_shipping_enabled');
  });
});

describe('admin-user-shipping-country-field.json — 관리자 회원 배송국가 필드', () => {
  const json = read('resources/extensions/admin-user-shipping-country-field.json');
  const components = json.injections.flatMap((i: any) => i.components ?? []);
  const select = components.map((c: any) =>
    walk(c, (n) => n.name === 'Select' && n.props?.name === 'ecommerce_preferred_shipping_country')).find(Boolean);

  it('admin_user_form 대상 + 저장버튼 없음(폼 자동 포함)', () => {
    expect(json.target_layout).toBe('admin_user_form');
    expect(select).toBeTruthy();
    // 별도 PUT 버튼이 없어야 한다(회원정보 저장 시 함께 저장)
    const buttons = components.map((c: any) => walk(c, (n) => n.name === 'Button')).filter(Boolean);
    expect(buttons.length).toBe(0);
  });

  it('편집 모드 + 해외배송 활성 게이트', () => {
    expect(components[0].if).toContain('route?.id');
    expect(components[0].if).toContain('international_shipping_enabled');
  });
});

describe('header-currency-selector-user.json — 헤더 공용 셀렉터 2섹션 (D4)', () => {
  const json = read('resources/extensions/header-currency-selector-user.json');
  const raw = serialize('resources/extensions/header-currency-selector-user.json');
  const root = json.injections.flatMap((i: any) => i.components ?? [])[0];

  it('통화 + 배송국가 두 섹션을 모두 포함', () => {
    expect(raw).toContain('availableCurrencies');
    expect(raw).toContain('availableShippingCountries');
    expect(raw).toContain('common.shipping_country_label');
  });

  it('배송국가 섹션 게이트: 2개 이상 + 해외배송 활성', () => {
    expect(raw).toContain('international_shipping_enabled');
    expect(raw).toContain('.length > 1');
  });

  it('루트는 if(마운트 게이트) 없이 항상 마운트 + className 으로 기본 hidden 토글 (깜빡임 방지)', () => {
    // 회귀(유저 헤더): if 마운트/언마운트 대신 항상 마운트 + className 기본 hidden(display:none) → 조건 충족 시 해제.
    expect(root.if).toBeUndefined();
    const cls = root.props?.className ?? '';
    expect(cls).toContain("'hidden'");
    expect(cls).toContain('_global.availableCurrencies');
    expect(cls).toContain('_global.availableShippingCountries');
  });

  it('배송국가 선택 시 PUT + 장바구니/체크아웃/상품상세 refetch', () => {
    expect(raw).toContain('user/shipping-country');
    expect(raw).toContain('refetchDataSource');
    expect(raw).toContain('cartItems');
    expect(raw).toContain('checkoutData');
    // B8 — 상품 상세 페이지에서 국가 변경 시 product 재조회로 배송가능 경고/disable 즉시 갱신
    expect(raw).toContain('"dataSourceId": "product"');
  });

  it('비로그인은 localStorage 저장', () => {
    expect(raw).toContain('g7_preferred_shipping_country');
  });

  it('트리거 라벨 = 배송국가 코드 텍스트 (관리자 헤더와 동일, flag-icons 비의존)', () => {
    // 트리거(통화 버튼) 안의 첫 Span 이 배송국가 코드를 텍스트로 표시해야 한다.
    const trigger = walk(root, (n) => n.name === 'Button' && Array.isArray(n.actions) &&
      n.actions.some((a: any) => a.handler === 'setState' && a.params?.showCurrencyDropdown !== undefined));
    expect(trigger).toBeTruthy();
    const countrySpan = (trigger.children ?? []).find((c: any) =>
      c.name === 'Span' && typeof c.text === 'string' && c.text.includes('preferredShippingCountry'));
    expect(countrySpan).toBeTruthy();
    expect(countrySpan.if).toContain('international_shipping_enabled');
    // flag-icons CSS 클래스(fi fi-)에 의존하지 않는다(렌더 보장).
    expect(countrySpan.props?.className ?? '').not.toContain('fi fi-');
  });

  it('트리거/옵션 어디에도 flag-icons(fi fi-) 클래스 잔존 0', () => {
    expect(raw).not.toContain('fi fi-');
    expect(raw).not.toContain('flag_class');
  });
});

describe('header-currency-selector-admin.json — 관리자 헤더 2섹션', () => {
  const json = read('resources/extensions/header-currency-selector-admin.json');
  const raw = serialize('resources/extensions/header-currency-selector-admin.json');
  const root = json.injections.flatMap((i: any) => i.components ?? [])[0];

  it('init_actions 에 배송국가 초기화 핸들러 등록', () => {
    expect(raw).toContain('initPreferredShippingCountry');
  });

  it('배송국가 섹션 + 게이트', () => {
    expect(raw).toContain('common.shipping_country_label');
    expect(raw).toContain('international_shipping_enabled');
  });

  it('init_actions 가 availableCurrencies/availableShippingCountries 파생 setState 를 포함 (유저 헤더 미러)', () => {
    // 깜빡임 방지(첫 페인트~init 전 숨김)의 SSoT — 파생 상태를 init 단계에서 만든다.
    const setStateActions = (json.init_actions ?? []).filter(
      (a: any) => a.handler === 'setState' && a.params?.target === 'global'
    );
    const keys = setStateActions.flatMap((a: any) => Object.keys(a.params ?? {}));
    expect(keys).toContain('availableCurrencies');
    expect(keys).toContain('availableShippingCountries');
  });

  it('루트는 if(마운트 게이트) 없이 항상 마운트 + className 으로 기본 hidden 토글 (깜빡임 방지)', () => {
    // 회귀: if 로 마운트/언마운트하면 첫 페인트에 잠깐 보였다 사라진다.
    // 항상 마운트하되 className 에 기본 hidden(display:none) 을 깔고 조건 충족 시에만 해제한다.
    expect(root.if).toBeUndefined();
    const cls = root.props?.className ?? '';
    expect(cls).toContain("'hidden'");
    // 표시 조건은 파생 상태(availableCurrencies/availableShippingCountries)로 판정, G7Config 원본 직접 참조 금지.
    expect(cls).toContain('_global.availableCurrencies');
    expect(cls).toContain('_global.availableShippingCountries');
    expect(cls).not.toContain('language_currency?.currencies');
  });

  it('통화/배송국가 iteration source 가 파생 상태를 참조 (G7Config 원본 filter 직접 호출 금지)', () => {
    const iterations = walkAll(root, (n) => !!n.iteration).map((n: any) => n.iteration.source);
    // 모든 iteration source 가 파생 상태(availableCurrencies/availableShippingCountries)만 참조.
    expect(iterations.length).toBeGreaterThanOrEqual(2);
    for (const src of iterations) {
      expect(src).toMatch(/availableCurrencies|availableShippingCountries/);
      expect(src).not.toContain('language_currency?.currencies');
      expect(src).not.toContain('shipping?.available_countries');
    }
  });

  it('배송국가 항목 라벨이 파생 name(현지화 완료)을 직접 참조', () => {
    const label = walk(root, (n) =>
      n.name === 'Span' && typeof n.text === 'string' && n.text.includes('shipCountry.name'));
    expect(label).toBeTruthy();
    // 파생 name 은 이미 현지화되어 있으므로 항목 안에서 다시 locale 분기하지 않는다.
    expect(label.text).not.toContain('name?.[');
  });
});

describe('_user_base.json — 글로벌 배선 (init/headers/onSuccess)', () => {
  const raw = serialize('layouts/_user_base.json', templateRoot);

  it('X-Shipping-Country globalHeader', () => {
    expect(raw).toContain('X-Shipping-Country');
  });

  it('init_actions: initPreferredShippingCountry + availableShippingCountries 주입', () => {
    expect(raw).toContain('initPreferredShippingCountry');
    expect(raw).toContain('availableShippingCountries');
    expect(raw).toContain('flag_class');
  });

  it('current_user onSuccess: 계정 영속 배송국가 재시드', () => {
    expect(raw).toContain('ecommerce_preferred_shipping_country');
  });
});

describe('마이페이지 설정/표시 카드', () => {
  const editRaw = serialize('layouts/partials/mypage/profile/_edit.json', templateRoot);
  const viewRaw = serialize('layouts/partials/mypage/profile/_view.json', templateRoot);

  it('_edit: 배송국가 카드 + onSuccess PUT 저장 단계', () => {
    expect(editRaw).toContain('shipping_country_settings.title');
    expect(editRaw).toContain('ecommerce_preferred_shipping_country');
    expect(editRaw).toContain('user/shipping-country');
  });

  it('_view: 읽기전용 배송국가 카드', () => {
    expect(viewRaw).toContain('shipping_country_settings.title');
  });

  it('두 카드 모두 해외배송 활성 게이트', () => {
    expect(editRaw).toContain('international_shipping_enabled');
    expect(viewRaw).toContain('international_shipping_enabled');
  });
});

describe('B1 — 체크아웃 국가 Select 데이터소스 경로 회귀', () => {
  const raw = serialize('layouts/partials/shop/_checkout_shipping.json', templateRoot);

  it('shippingSettings?.data?.shipping 경로 사용 (ecommerceSettings 잔존 0)', () => {
    expect(raw).toContain('shippingSettings?.data?.shipping');
    expect(raw).not.toContain('ecommerceSettings?.shipping');
  });

  it('국가 Select 기본값 = 헤더 동기화 (preferredShippingCountry)', () => {
    expect(raw).toContain('preferredShippingCountry');
  });
});

describe('B3 — 주소록 칩 선택 시 해외 주소 intl_* 매핑', () => {
  const json = read('layouts/partials/shop/_checkout_shipping.json', templateRoot);
  const raw = serialize('layouts/partials/shop/_checkout_shipping.json', templateRoot);

  it('UserAddress(city/state/postal_code) → 체크아웃(intl_*) 변환', () => {
    expect(raw).toContain('"intl_city": "{{addr.city');
    expect(raw).toContain('"intl_state": "{{addr.state');
    expect(raw).toContain('"intl_postal_code": "{{addr.postal_code');
    expect(raw).toContain('"address_line_1": "{{addr.address_line_1');
  });

  it('체크아웃 해외 주소 입력 필드명 = 백엔드 표준(address_line_1/2) — 전이↔입력↔백엔드 일치(defect #4)', () => {
    // 입력 필드명이 address_line_1/2 여야 전이 핸들러(addr.address_line_1)·백엔드(CreateOrderRequest) 와 일치
    const line1 = walk(json, (n) => n.name === 'Input' && n.props?.name === 'address_line_1');
    const line2 = walk(json, (n) => n.name === 'Input' && n.props?.name === 'address_line_2');
    expect(line1, 'address_line_1 입력 필드 존재').toBeTruthy();
    expect(line2, 'address_line_2 입력 필드 존재').toBeTruthy();
    expect(line1.props.value).toContain('_local.shipping?.address_line_1');
    // 구 키(intl_address1/2)는 필드명/상태 경로에서 제거되어야 한다 (placeholder 키는 예외)
    expect(raw).not.toContain('"name": "intl_address1"');
    expect(raw).not.toContain('"name": "intl_address2"');
    expect(raw).not.toContain('shipping.intl_address1');
    expect(raw).not.toContain('shipping.intl_address2');
  });
});

describe('D10 — 주소록 추가/수정 폼 국가 Select + 국내/해외 분기', () => {
  const json = read('layouts/partials/shop/_modal_address_manage.json', templateRoot);
  const raw = serialize('layouts/partials/shop/_modal_address_manage.json', templateRoot);

  it('country_code Select (해외배송 활성 게이트)', () => {
    const countrySelect = walk(json, (n) => n.name === 'Select' && n.props?.name === 'country_code');
    expect(countrySelect).toBeTruthy();
  });

  it('해외 주소 입력 필드 분기 (address_line_1, intl_city/state/postal_code)', () => {
    const intlInputs = ['address_line_1', 'address_line_2', 'intl_city', 'intl_state', 'intl_postal_code'];
    for (const name of intlInputs) {
      const input = walk(json, (n) => n.name === 'Input' && n.props?.name === name);
      expect(input, `해외 입력 필드 ${name} 존재`).toBeTruthy();
    }
  });

  it('국내(KR)/해외 입력 분기 조건', () => {
    expect(raw).toContain("(_local.editingAddress?.country_code ?? 'KR') === 'KR'");
    expect(raw).toContain("(_local.editingAddress?.country_code ?? 'KR') !== 'KR'");
  });
});

describe('shippability 경고 3계층 + B8', () => {
  it('장바구니 아이템: is_shippable_to_selected_country 경고', () => {
    const raw = serialize('layouts/partials/shop/_cart_item.json', templateRoot);
    expect(raw).toContain('is_shippable_to_selected_country');
    expect(raw).toContain('shippability.not_shippable');
  });

  it('장바구니 요약: has_unshippable_items → 주문하기 disable', () => {
    const raw = serialize('layouts/partials/shop/_cart_summary.json', templateRoot);
    expect(raw).toContain('has_unshippable_items');
    expect(raw).toContain('shippability.blocks_order');
  });

  it('체크아웃 아이템: per-item 경고', () => {
    const raw = serialize('layouts/partials/shop/_checkout_items.json', templateRoot);
    expect(raw).toContain('is_shippable_to_selected_country');
  });

  it('체크아웃 요약: has_unshippable_items → 결제 disable', () => {
    const raw = serialize('layouts/partials/shop/_checkout_summary.json', templateRoot);
    expect(raw).toContain('has_unshippable_items');
  });

  it('B8 상품상세(실사용 detail/_purchase_card): 배송 불가 경고 + 바로구매/장바구니 disable', () => {
    const raw = serialize('layouts/partials/shop/detail/_purchase_card.json', templateRoot);
    // 경고 배너
    expect(raw).toContain('is_shippable_to_selected_country === false');
    expect(raw).toContain('shippability.not_shippable');
    // 바로구매/장바구니 버튼 disabled 가 shippability 를 반영 (2회 — 두 버튼)
    const disableHits = raw.split('is_shippable_to_selected_country === false').length - 1;
    expect(disableHits).toBeGreaterThanOrEqual(3); // 경고 1 + 버튼 disable 2 + conditions 가드 2 이상
  });

  it('B8 상품상세 모바일 가격(detail/_price_mobile): 배송 불가 경고', () => {
    const raw = serialize('layouts/partials/shop/detail/_price_mobile.json', templateRoot);
    expect(raw).toContain('is_shippable_to_selected_country === false');
    expect(raw).toContain('shippability.not_shippable');
  });
});

describe('D9 — 주문 상세/목록 국가 표시', () => {
  it('마이페이지 주문 상세: 배송국가 행', () => {
    const raw = serialize('layouts/partials/mypage/orders/_shipping.json', templateRoot);
    expect(raw).toContain('recipient_country_name');
    expect(raw).toContain('checkout.shipping_country');
  });

  it('마이페이지 주문 목록: 국가 칩', () => {
    const raw = serialize('layouts/partials/mypage/orders/_list.json', templateRoot);
    expect(raw).toContain('recipient_country_code');
    expect(raw).toContain('recipient_country_name');
  });
});

describe('D-admin (defect #2) — 관리자 회원폼 저장 시 배송국가 전용 PATCH 배선', () => {
  const raw = serialize('layouts/admin_user_form.json', adminTemplateRoot);

  it('통화와 동형으로 shipping-country PATCH 가 footer 저장 onSuccess 에 존재', () => {
    expect(raw).toContain('/shipping-country');
    expect(raw).toContain('shipping_country: _local.form.ecommerce_preferred_shipping_country');
    // 게이트: 편집 모드 + 폼에 배송국가 필드가 있을 때만
    expect(raw).toContain("route?.id && _local.form?.ecommerce_preferred_shipping_country");
  });

  it('통화 PATCH 도 함께 존재(회귀 보호 — 두 필드 모두 별도 PATCH)', () => {
    expect(raw).toContain("/currency");
    expect(raw).toContain('currency: _local.form.ecommerce_preferred_currency');
  });
});

describe('D10 (defect #3) — 마이페이지 주소록 모달 국가 Select + 국내/해외 분기', () => {
  const json = read('layouts/partials/mypage/addresses/_modal_address.json', templateRoot);
  const raw = serialize('layouts/partials/mypage/addresses/_modal_address.json', templateRoot);

  it('country_code Select (해외배송 활성 게이트) + change 로 country_code 저장', () => {
    const countrySelect = walk(json, (n) => n.name === 'Select' && n.props?.name === 'country_code');
    expect(countrySelect).toBeTruthy();
    expect(raw).toContain('international_shipping_enabled');
    expect(raw).toContain('editingAddress.country_code');
  });

  it('해외 주소 입력 필드 분기 (address_line_1/2, intl_city/state/postal_code)', () => {
    for (const name of ['address_line_1', 'address_line_2', 'intl_city', 'intl_state', 'intl_postal_code']) {
      const input = walk(json, (n) => n.name === 'Input' && n.props?.name === name);
      expect(input, `해외 입력 필드 ${name} 존재`).toBeTruthy();
    }
  });

  it('국내(KR)/해외 입력 분기 조건 (_global.editingAddress.country_code 기준)', () => {
    expect(raw).toContain("(_global.editingAddress?.country_code ?? 'KR') === 'KR'");
    expect(raw).toContain("(_global.editingAddress?.country_code ?? 'KR') !== 'KR'");
  });
});

describe('D7 (defect #5) — 관리자 주문상세 국내/해외 분기 폼', () => {
  const json = read('resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json');
  const raw = serialize('resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json');
  const detailRaw = serialize('resources/layouts/admin/admin_ecommerce_order_detail.json');
  const saveRaw = serialize('resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json');

  it('국가 Select change → form.recipient_country_code 동기화', () => {
    const sel = walk(json, (n) => n.name === 'Select' && n.props?.name === 'recipient_country_code');
    expect(sel).toBeTruthy();
    expect(raw).toContain('form.recipient_country_code');
  });

  it('국내(KR) 필드는 KR 일 때만, 해외 필드는 KR 아닐 때만 표시', () => {
    expect(raw).toContain("(_local.form.recipient_country_code ?? 'KR') === 'KR'");
    expect(raw).toContain("(_local.form.recipient_country_code ?? 'KR') !== 'KR'");
  });

  it('해외 입력 필드 5종 존재', () => {
    for (const name of ['address_line_1', 'address_line_2', 'intl_city', 'intl_state', 'intl_postal_code']) {
      const input = walk(json, (n) => n.name === 'Input' && n.props?.name === name);
      expect(input, `해외 입력 필드 ${name} 존재`).toBeTruthy();
    }
  });

  it('form init + 저장 body 에 해외 필드 포함', () => {
    // init (admin_ecommerce_order_detail.json)
    expect(detailRaw).toContain('form.address_line_1');
    expect(detailRaw).toContain('form.intl_city');
    expect(detailRaw).toContain('form.recipient_country_code');
    // 저장 body (_partial_order_info.json)
    expect(saveRaw).toContain('"address_line_1": "{{_local.form.address_line_1}}"');
    expect(saveRaw).toContain('"intl_postal_code": "{{_local.form.intl_postal_code}}"');
    expect(saveRaw).toContain('"recipient_country_code": "{{_local.form.recipient_country_code');
  });
});
