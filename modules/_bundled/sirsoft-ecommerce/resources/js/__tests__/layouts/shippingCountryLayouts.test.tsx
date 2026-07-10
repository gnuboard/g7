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

  /**
   * 사용자 템플릿(sirsoft-basic)은 390px 뷰포트 오버플로(+11px) 해소를 위해 이 슬롯을
   * 헤더 우측 그룹에서 모바일 드로어(overflow-y-auto + w-80)로 옮겼다. 드로어에서는
   * absolute 드롭다운 패널이 스크롤 경계에서 잘리므로, 주입 노드 자체가 responsive.portable
   * (0~1023px) 오버라이드로 static 인라인 목록으로 전환한다.
   *
   * 표시 여부는 데스크톱과 동일하게 `_local.showCurrencyDropdown` 이 결정한다 — portable 에
   * `if` 를 두지 않으면 엔진이 base `if` 를 그대로 상속하므로(`override.if ?? baseDef.if`)
   * 드로어에서도 기본 접힘이 되고, 트리거가 접기/펼치기 아코디언 역할을 한다.
   *
   * SlotContainer 는 주입 자식에게 variant/context prop 을 전달하지 않으므로 컨테이너별로
   * 다르게 그릴 방법이 이것뿐이다. 데스크톱(≥1024px)은 기존 드롭다운을 그대로 유지한다.
   */
  it('트리거 버튼은 portable 에서 아코디언 행이 되고 접힘 상태 요약(통화·배송국가)을 노출한다', () => {
    const trigger = walk(root, (n) => n.name === 'Button' && Array.isArray(n.actions) &&
      n.actions.some((a: any) => a.handler === 'setState' && a.params?.showCurrencyDropdown !== undefined));
    expect(trigger).toBeTruthy();

    const portable = trigger.responsive?.portable;
    expect(portable).toBeTruthy();
    // 더 이상 숨기지 않는다 — 드로어 폭을 채우는 트리거 행
    expect(portable.props.className).not.toBe('hidden');
    expect(portable.props.className).toContain('w-full');
    expect(portable.props.className).toContain('justify-between');
    // 접근성: 얕은 머지가 아니라 통째 교체이므로 aria-* 를 오버라이드에도 명시
    expect(portable.props['aria-expanded']).toBe('{{_local.showCurrencyDropdown ?? false}}');

    // 접힘 상태에서 현재 값을 알 수 있어야 한다 (요약 = 통화 + 배송국가)
    const summary = JSON.stringify(portable.children);
    expect(summary).toContain('preferredCurrency');
    expect(summary).toContain('preferredShippingCountry');
    expect(summary).toContain('chevron-down');
    expect(summary).toContain("{{_local.showCurrencyDropdown ? 'rotate-180' : ''}}");
  });

  it('패널은 portable 에서도 _local.showCurrencyDropdown 으로 게이트된다 (기본 접힘)', () => {
    const panel = (root.children ?? []).find(
      (n: any) => n.name === 'Div' && n.props?.role === 'listbox'
    );
    expect(panel).toBeTruthy();

    // 데스크톱 기본 경로 — 회귀 방지
    expect(panel.if).toBe('{{_local.showCurrencyDropdown}}');
    expect(panel.props.className).toContain('absolute');
    expect(panel.props.className).toContain('z-50');

    const portable = panel.responsive?.portable;
    expect(portable).toBeTruthy();
    // 핵심: portable 에 if 를 두지 않아야 base if 를 상속해 기본 접힘이 된다.
    // `{{true}}` 로 강제하면 드로어에서 항상 펼쳐져 아코디언이 성립하지 않는다.
    expect(portable.if).toBeUndefined();
    expect(portable.props.className).toContain('static');
    expect(portable.props.className).not.toContain('absolute');
    expect(portable.props.className).not.toContain('z-50');
    // 접근성 role 은 props 얕은 머지에서 유실되지 않도록 오버라이드에도 명시
    expect(portable.props.role).toBe('listbox');
  });

  it('배경 클릭 오버레이는 portable 에서 꺼진다 (드로어 전체를 덮어 클릭을 가로채는 회귀 방지)', () => {
    const backdrop = (root.children ?? []).find(
      (n: any) => n.name === 'Div' && (n.props?.className ?? '').includes('fixed inset-0')
    );
    expect(backdrop).toBeTruthy();
    expect(backdrop.if).toBe('{{_local.showCurrencyDropdown}}');
    expect(backdrop.responsive?.portable?.if).toBe('{{false}}');
  });

  /**
   * 모바일(portable)에서는 통화·배송국가를 세로 목록이 아니라 가로 나열 칩으로 그린다.
   * 세로 목록은 좁은 드로어에서 세로 공간을 과하게 먹는다.
   *
   * 엔진의 `iteration` 은 그 속성을 가진 노드 자신을 항목 수만큼 복제한다. 따라서 옵션들을
   * 한 줄에 흘리려면 iteration 노드의 **부모**가 flex 컨테이너여야 한다 → 래퍼 Div 를 두고
   * 그 래퍼에만 responsive.portable 로 `flex flex-wrap` 을 준다(데스크톱은 무클래스 블록).
   */
  const findSection = (labelKey: string) => {
    const panel = (root.children ?? []).find((c: any) => c.props?.role === 'listbox');
    return (panel.children ?? []).find(
      (c: any) => JSON.stringify(c).includes(labelKey) && Array.isArray(c.children)
    );
  };

  for (const [name, labelKey] of [
    ['통화', 'common.currency_label'],
    ['배송국가', 'common.shipping_country_label'],
  ] as const) {
    it(`${name} 섹션: iteration 노드의 부모 래퍼가 portable 에서 flex-wrap 이다 (가로 칩 나열)`, () => {
      const section = findSection(labelKey);
      expect(section).toBeTruthy();

      // [0] = 라벨, [1] = 옵션 래퍼
      const wrapper = section.children[1];
      expect(wrapper.name).toBe('Div');
      // 데스크톱은 무클래스(블록 흐름) — 기존 세로 목록 유지
      expect(wrapper.props?.className).toBeUndefined();

      const portableCls = wrapper.responsive?.portable?.props?.className ?? '';
      expect(portableCls).toContain('flex');
      expect(portableCls).toContain('flex-wrap');

      // iteration 은 래퍼가 아니라 그 자식에 있어야 한다 (래퍼가 복제되면 칩이 세로로 쌓임)
      expect(wrapper.iteration).toBeUndefined();
      expect(wrapper.children[0].iteration).toBeTruthy();
    });

    it(`${name} 섹션: 옵션 버튼이 portable 에서 칩(rounded-full) 이고 데스크톱은 w-full 행이다`, () => {
      const section = findSection(labelKey);
      const iterNode = section.children[1].children[0];
      const button = iterNode.children[0];
      expect(button.name).toBe('Button');

      // 데스크톱 기본 — 전체폭 행
      expect(button.props.className).toContain('w-full');
      expect(button.props.className).not.toContain('rounded-full');

      // portable — 가로 칩
      const chip = button.responsive?.portable?.props?.className ?? '';
      expect(chip).toContain('inline-flex');
      expect(chip).toContain('rounded-full');
      expect(chip).toContain('whitespace-nowrap');
      expect(chip).not.toContain('w-full');
      // 접근성 속성은 props 얕은 머지에서 유실되지 않도록 오버라이드에도 유지
      expect(button.responsive.portable.props.role).toBe('option');
      expect(button.responsive.portable.props['aria-selected']).toBeTruthy();
    });

    it(`${name} 섹션: 선택 체크 아이콘은 portable 에서 숨긴다 (칩 폭 절약, 선택은 색+aria-selected 로 전달)`, () => {
      const section = findSection(labelKey);
      const button = section.children[1].children[0].children[0];
      const check = (button.children ?? []).find((c: any) => c.props?.name === 'check');
      expect(check).toBeTruthy();
      expect(check.responsive?.portable?.if).toBe('{{false}}');
    });
  }

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

/**
 * 배송국가명 다국어 표시 회귀 (#459)
 *
 * 두 가지 회귀를 동시에 차단한다.
 *
 * ① `_global.locale` 은 존재하지 않는 경로다. 엔진은 로케일을 최상위 `$locale` 로만 노출하므로
 *    (template-engine.ts::createGlobalVariables) 이를 인덱스로 쓰면 표현식이 항상 undefined 가 되어
 *    `?? name?.ko` 폴백으로 떨어진다 → 어떤 로케일에서도 한국어 국가명이 표시된다.
 *
 * ② `$localized(value)` 를 fallbackKey 없이 호출하면, 저장본(settings/shipping.json)에 해당 로케일
 *    키가 없을 때 헬퍼가 ko 로 폴백한다. 기본 10개국은 언어팩이 라벨을 갖고 있으므로 fallbackKey
 *    (`sirsoft-ecommerce::settings.countries.{code}.name`)를 넘겨야 언어팩 보강이 적용된다.
 *    이미 마이그레이션된 _shipping_country_table/_cards 와 동일한 계약이다(catalogLangPackFallback.test).
 */
describe('#459 — 배송국가명 다국어 표시 ($localized + fallbackKey)', () => {
  /** 국가 lang key 접두사 — 저장본에 로케일 키가 없을 때 언어팩으로 보강되는 경로 */
  const COUNTRY_LANG_PREFIX = 'sirsoft-ecommerce::settings.countries.';

  /** [설명 라벨, 파일 경로, base] */
  const surfaces: Array<[string, string, string]> = [
    ['사용자 헤더 셀렉터', 'layouts/_user_base.json', templateRoot],
    ['관리자 헤더 셀렉터', 'resources/extensions/header-currency-selector-admin.json', moduleRoot],
    ['회원가입 배송국가 필드', 'resources/extensions/register-shipping-country-field.json', moduleRoot],
    ['관리자 회원 배송국가 필드', 'resources/extensions/admin-user-shipping-country-field.json', moduleRoot],
    [
      '관리자 주문상세 배송국가',
      'resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json',
      moduleRoot,
    ],
    ['배송설정 기본국가 Select', 'resources/layouts/admin/partials/admin_ecommerce_settings/_tab_shipping.json', moduleRoot],
  ];

  for (const [label, rel, base] of surfaces) {
    it(`${label}: 존재하지 않는 경로 _global.locale 을 인덱스로 쓰지 않는다`, () => {
      const raw = serialize(rel, base);
      expect(raw).not.toMatch(/\[\s*_global\.locale\s*\]/);
    });
  }

  /**
   * 국가명을 표시하는 각 표현식이 $localized 를 fallbackKey 와 함께 호출하는지 검증한다.
   * `needle` 은 그 표현식을 담은 줄을 특정하기 위한 고유 문자열이다.
   */
  const localizedCalls: Array<[string, string, string, string]> = [
    ['사용자 헤더 파생', 'layouts/_user_base.json', templateRoot, '"availableShippingCountries"'],
    [
      '관리자 헤더 파생',
      'resources/extensions/header-currency-selector-admin.json',
      moduleRoot,
      '"availableShippingCountries"',
    ],
    [
      '회원가입 Select options',
      'resources/extensions/register-shipping-country-field.json',
      moduleRoot,
      '"options"',
    ],
    [
      '관리자 회원폼 Select options',
      'resources/extensions/admin-user-shipping-country-field.json',
      moduleRoot,
      '"options"',
    ],
    [
      '배송설정 기본국가 Select options',
      'resources/layouts/admin/partials/admin_ecommerce_settings/_tab_shipping.json',
      moduleRoot,
      '"options"',
    ],
  ];

  for (const [label, rel, base, needle] of localizedCalls) {
    it(`${label}: $localized 를 국가 lang key fallbackKey 와 함께 호출한다`, () => {
      const line = serialize(rel, base)
        .split('\n')
        .find((l) => l.includes(needle) && l.includes('$localized('));
      expect(line, `${needle} 를 담은 $localized 호출 줄 존재`).toBeTruthy();
      expect(line!).toContain(COUNTRY_LANG_PREFIX);
      // 폴백 체인은 헬퍼 + 언어팩이 담당한다 — 레이아웃에서 ko 를 명시 폴백하지 않는다.
      expect(line!).not.toContain('name?.ko');
    });
  }

  it('관리자 주문상세 Select options: $localized + fallbackKey', () => {
    const line = serialize(
      'resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json'
    )
      .split('\n')
      .find((l) => l.includes('available_countries') && l.includes('"options"'));
    expect(line, '배송국가 Select options 존재').toBeTruthy();
    expect(line!).toContain('$localized(');
    expect(line!).toContain(COUNTRY_LANG_PREFIX);
    expect(line!).not.toContain('name?.ko');
  });

  it('관리자 주문상세: 해외배송 OFF 시 표시되는 주문 스냅샷 국가명도 $localized 로 현지화된다', () => {
    // 주문 스냅샷(recipient_country_name)은 주문 시점 다국어 JSON — 언어팩 보강 대상이 아니므로
    // fallbackKey 없이 $localized 단독 호출로 충분하다(활성 로케일 → ko → en → 첫 값).
    const line = serialize(
      'resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_order_info.json'
    )
      .split('\n')
      .find((l) => l.includes('recipient_country_name') && l.includes('"text"'));
    expect(line, '스냅샷 국가명 표시 노드 존재').toBeTruthy();
    expect(line!).toContain('$localized(');
    expect(line!).not.toContain('name?.ko');
  });
});

/**
 * 배송국가 추가 폼 다국어 입력 회귀 (#459)
 *
 * 운영자가 직접 추가한 국가는 언어팩에 lang key 가 없으므로 보강 대상이 아니다. 따라서 활성 언어팩
 * 전체(`$locales`)에 대해 이름을 입력할 수 있어야 하며, ko/en 두 칸 하드코딩이면 ja 등 나머지
 * 로케일에는 영원히 이름을 넣을 수 없다. 백엔드 검증은 이미 로케일 무제한(`name.*`)이다.
 */
describe('#459 — 배송국가 추가 폼 다국어 입력 ($locales 순회)', () => {
  const json = read('resources/layouts/admin/partials/admin_ecommerce_settings/_tab_shipping.json');
  const raw = serialize('resources/layouts/admin/partials/admin_ecommerce_settings/_tab_shipping.json');

  const addForm = walk(json, (n) => n.id === 'add_country_form');

  it('국가 추가 폼이 존재한다', () => {
    expect(addForm).toBeTruthy();
  });

  it('국가명 입력칸이 $locales 순회로 생성된다 (ko/en 하드코딩 제거)', () => {
    const iterNode = walk(addForm, (n) => typeof n.iteration?.source === 'string'
      && n.iteration.source.includes('$locales'));
    expect(iterNode, '$locales 를 순회하는 입력칸 노드 존재').toBeTruthy();

    // 로케일별 입력칸은 newCountry.name[<loc>] 로 써야 한다 — 고정 .ko/.en 경로 금지
    const formRaw = JSON.stringify(addForm);
    expect(formRaw).not.toContain('newCountry.name.ko');
    expect(formRaw).not.toContain('newCountry.name.en');
    expect(formRaw).not.toContain('newCountry?.name?.ko');
    expect(formRaw).not.toContain('newCountry?.name?.en');
  });

  it('입력칸 라벨이 로케일 표시명(localeNames)으로 동적 생성된다', () => {
    const formRaw = JSON.stringify(addForm);
    expect(formRaw).toContain('localeNames');
    // 고정 라벨 키(country_name_ko/en) 잔존 0
    expect(formRaw).not.toContain('countries.country_name_ko');
    expect(formRaw).not.toContain('countries.country_name_en');
  });

  it('추가 버튼 활성 조건이 특정 로케일(ko)에 고정되지 않는다', () => {
    const formRaw = JSON.stringify(addForm);
    // 최소 하나의 로케일에 이름이 있으면 추가 가능 — 백엔드 validateCountryNames 와 동일 계약
    expect(formRaw).not.toContain('!_local.newCountry?.name?.ko');
  });

  it('신규 국가 초기 상태가 $locales 기반으로 만들어진다 (빈 ko/en 리터럴 금지)', () => {
    const addButton = walk(json, (n) => n.id === 'add_country_button');
    expect(addButton).toBeTruthy();
    const init = JSON.stringify(addButton.actions);
    expect(init).not.toContain('"ko": ""');
    expect(init).not.toContain('"en": ""');
  });

  it('배송국가 카드/테이블에서 이름 편집이 가능하면 동일하게 $locales 를 순회한다', () => {
    // 편집 진입점이 없다면(현재 구조) 이 테스트는 자동 통과 — 추가 시 회귀 차단용 가드
    for (const rel of [
      'resources/layouts/admin/partials/admin_ecommerce_settings/_shipping_country_table.json',
      'resources/layouts/admin/partials/admin_ecommerce_settings/_shipping_country_cards.json',
    ]) {
      const r = serialize(rel);
      if (r.includes('name.ko') || r.includes('name.en')) {
        expect(r, `${rel} 이름 편집이 로케일 하드코딩`).toContain('$locales');
      }
    }
    expect(raw).toBeTruthy();
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
