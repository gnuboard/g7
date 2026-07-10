/**
 * @file currencySelector.test.tsx
 * @description 헤더 통화·언어 선택기 구조 회귀 테스트 (A1·U11 헤더 통합)
 *
 * 배경:
 * - 통화 = 커머스 책임. 헤더(Header.tsx)는 이커머스 모듈을 모르고, 'header_currency' 슬롯만
 *   렌더한다(SlotContainer). 이커머스 모듈이 layout_extensions 로 그 슬롯에 통화 셀렉터를 주입한다.
 * - 언어는 코어 기능 → 헤더에 독립 버튼으로 내장(비회원 포함 전체 노출). 유저 드롭다운 언어 중복 제거.
 * - 표시 통화 초기화 핸들러(initPreferredCurrency)는 sirsoft-ecommerce 모듈 소유
 *   (`sirsoft-ecommerce.initPreferredCurrency`). _user_base.json init_actions 가 모듈 네임스페이스로 호출.
 *
 * 모바일 배치 (390px 오버플로 해소 후):
 * - 언어·통화는 헤더 우측 그룹이 아니라 모바일 드로어 최상단(mobile_drawer_prefs)에 있다.
 *   데스크톱(Header.tsx)은 기존 헤더 배치를 유지한다. 사유는 아래 해당 it 블록 주석 참조.
 * - 드로어는 overflow-y-auto 라 absolute 드롭다운이 잘린다 → 주입 조각이 responsive.portable
 *   오버라이드로 모바일에서만 static 인라인 목록이 된다.
 * - 언어 / 통화·배송국가는 각각 독립 아코디언이며 기본 접힘이다. 접힘 상태에서도 트리거에
 *   현재 선택값(언어명 / 통화·배송국가)을 요약 표기한다.
 *
 * 회귀 차단:
 * - _user_base.json: defaultCurrency/preferredCurrency(_global)/availableCurrencies 주입 + X-Currency 헤더.
 * - init 핸들러 호출이 모듈 네임스페이스(sirsoft-ecommerce.initPreferredCurrency)로 되어 있다.
 * - 헤더에 통화 슬롯(SlotContainer header_currency)과 언어 버튼이 있고, Header.tsx 는 통화 코드/모듈을 모른다.
 * - 데스크톱 드롭다운(absolute/z-50)은 responsive 오버라이드로 무력화되지 않는다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';

const baseDir = path.resolve(__dirname, '../..');
const repoRoot = path.resolve(baseDir, '../../..');

function loadRaw(relPath: string): string {
  return fs.readFileSync(path.resolve(baseDir, relPath), 'utf8');
}
function loadJson(relPath: string): any {
  return JSON.parse(loadRaw(relPath));
}
function loadRepo(relPath: string): string {
  return fs.readFileSync(path.resolve(repoRoot, relPath), 'utf8');
}

/**
 * 레이아웃 트리에서 id 로 노드를 찾습니다.
 *
 * @param node 탐색 시작 노드/배열
 * @param id 찾을 노드 id
 * @return 찾은 노드 (없으면 null)
 */
function findNode(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) {
    for (const n of node) {
      const r = findNode(n, id);
      if (r) return r;
    }
    return null;
  }
  if (node.id === id) return node;
  for (const k of ['children', 'components']) {
    if (node[k]) {
      const r = findNode(node[k], id);
      if (r) return r;
    }
  }
  return null;
}

/**
 * 레이아웃 트리에서 술어를 만족하는 첫 노드를 찾습니다.
 *
 * 위치 인덱스(children[1] 등) 대신 구조로 찾기 위한 헬퍼 — 노드가 앞에 추가되어도
 * 테스트가 깨지지 않습니다.
 *
 * @param node 탐색 시작 노드/배열
 * @param pred 노드 술어
 * @return 찾은 노드 (없으면 null)
 */
function walkNode(node: any, pred: (n: any) => boolean): any {
  if (!node || typeof node !== 'object') return null;
  if (Array.isArray(node)) {
    for (const n of node) {
      const r = walkNode(n, pred);
      if (r) return r;
    }
    return null;
  }
  if (pred(node)) return node;
  for (const k of ['children', 'components']) {
    if (node[k]) {
      const r = walkNode(node[k], pred);
      if (r) return r;
    }
  }
  return null;
}

describe('A1 — _user_base.json 통화 주입', () => {
  const userBase = loadJson('layouts/_user_base.json');
  const initActions = userBase.init_actions ?? [];
  const initText = JSON.stringify(initActions);

  it('defaultCurrency 를 default_currency 노출값에서 _global 에 주입한다', () => {
    expect(initText).toContain('defaultCurrency');
    expect(initText).toContain('language_currency?.default_currency');
  });

  it('선호 통화 초기화는 이커머스 모듈 소유 핸들러(sirsoft-ecommerce.initPreferredCurrency)로 호출한다', () => {
    // 통화 = 커머스 책임 → init 핸들러는 모듈 네임스페이스. 모듈 미설치 시 미등록 핸들러로 무동작.
    const inject = initActions.find(
      (a: any) => a.handler === 'sirsoft-ecommerce.initPreferredCurrency'
    );
    expect(inject).toBeDefined();
    expect(inject.params?.defaultCurrency).toContain('defaultCurrency');
  });

  it('템플릿 전용(미네임스페이스) initPreferredCurrency 호출이 남아있지 않다 (모듈 이전 회귀)', () => {
    const bare = initActions.find((a: any) => a.handler === 'initPreferredCurrency');
    expect(bare).toBeUndefined();
  });

  it('깨진 _local 라운드트립(loadFromLocalStorage stateKey=preferredCurrency)을 사용하지 않는다', () => {
    const brokenLoad = initActions.find(
      (a: any) => a.handler === 'loadFromLocalStorage'
        && (a.params?.stateKey === 'preferredCurrency' || a.params?.key === 'g7_preferred_currency')
    );
    expect(brokenLoad).toBeUndefined();
  });

  it('availableCurrencies 를 is_default || exchange_rate>0 필터로 주입한다(U11-B)', () => {
    const inject = initActions.find(
      (a: any) => a.params?.availableCurrencies !== undefined
    );
    expect(inject).toBeDefined();
    expect(inject.params.availableCurrencies).toContain('language_currency?.currencies');
    expect(inject.params.availableCurrencies).toContain('is_default');
    expect(inject.params.availableCurrencies).toContain('exchange_rate');
  });

  it('globalHeaders 의 이커머스 패턴에 X-Currency 헤더가 있다', () => {
    const headers = userBase.globalHeaders ?? [];
    const ecommerce = headers.find((h: any) => h.pattern === '/api/modules/sirsoft-ecommerce/*');
    expect(ecommerce).toBeDefined();
    expect(ecommerce.headers['X-Currency']).toBeDefined();
    expect(ecommerce.headers['X-Currency']).toContain('preferredCurrency');
  });

  it('current_user 로드 후 모듈 통화 핸들러를 재실행해 계정 통화를 덮어쓴다 (D-LOGIN-CUR 회귀)', () => {
    const dataSources = userBase.data_sources ?? [];
    const currentUser = dataSources.find((d: any) => d.id === 'current_user');
    expect(currentUser).toBeDefined();
    expect(Array.isArray(currentUser.onSuccess)).toBe(true);
    const reResolve = currentUser.onSuccess.find(
      (a: any) => a.handler === 'sirsoft-ecommerce.initPreferredCurrency'
    );
    expect(reResolve).toBeDefined();
  });
});

describe('헤더 통화 슬롯(이커머스 주입) + 언어 버튼(코어 내장)', () => {
  const userBaseText = loadRaw('layouts/_user_base.json');
  const headerTsx = loadRaw('src/components/composite/Header.tsx');

  it('Header.tsx 가 header_currency 슬롯 컨테이너를 렌더한다 (통화는 모듈 주입)', () => {
    expect(headerTsx).toContain('SlotContainer');
    expect(headerTsx).toContain('header_currency');
  });

  it('Header.tsx 는 이커머스/통화 코드를 모른다 (모듈 무지 — 결합 제거 회귀)', () => {
    // 헤더가 통화 코드·이커머스 식별자·통화 영속 엔드포인트를 직접 들고 있으면 결합 회귀.
    expect(headerTsx).not.toContain('availableCurrencies');
    expect(headerTsx).not.toContain('handleSelectCurrency');
    expect(headerTsx).not.toContain('g7_preferred_currency');
    expect(headerTsx).not.toContain('user/currency');
  });

  it('Header.tsx 에 언어 독립 버튼(코어 기능)이 있다', () => {
    expect(headerTsx).toContain('showLangMenu');
    expect(headerTsx).toContain('handleLocaleChange');
    expect(headerTsx).toContain('currentLocale');
  });

  it('모바일 표면(_user_base.json)도 header_currency 슬롯(SlotContainer)을 마운트한다', () => {
    // 마운트 위치는 헤더 우측 그룹 → 모바일 드로어로 이동했으나, 슬롯 소비 자체는 유지된다.
    expect(userBaseText).toContain('mobile_drawer_currency_wrap');
    expect(userBaseText).toContain('"slotId": "header_currency"');
  });

  /**
   * 배치 반전 사유 (과거 결정의 의도적 해제):
   *
   * 이 자리에는 원래 `it('모바일 헤더에 언어 선택기가 통화와 같은 줄에 노출된다
   * (데스크톱 패리티, 4표면 일관)')` 이 있었고, 헤더 우측 그룹(#mobile_header_right)의
   * 직계 자식에 mobile_lang_selector_wrap / mobile_currency_selector_wrap 이 있음을 단언했다.
   *
   * 390px(iPhone) 실측 결과 그 배치가 사이트 전역 가로 오버플로의 단일 원인이었다:
   * 우측 그룹 = theme(36)+notification(36)+cart(34)+lang(71)+currency(91)+user(30)+hamburger(30)
   * = 351px, 로고 뒤 x=50 에서 시작 → 우측 끝 401px → 뷰포트 390px 를 11px 초과.
   * 언어+통화만 제거하면 document.scrollWidth 가 401 → 390 으로 떨어진다.
   * 320px(iPhone SE)에서는 햄버거 버튼이 화면 밖으로 밀려 내비게이션 자체가 불가능했다.
   *
   * 따라서 "데스크톱 패리티"는 **모바일에 한해** 의도적으로 해제하고, 언어·통화를
   * 드로어(mobile_nav_drawer > mobile_drawer_prefs) 최상단으로 옮겼다.
   * 데스크톱(Header.tsx)의 배치는 그대로다 — 위 'Header.tsx' 단언들이 그것을 지킨다.
   *
   * 되돌리려면 먼저 390px 오버플로를 다른 방법으로 해소해야 한다.
   */
  it('모바일 헤더 우측 그룹에 언어/통화가 없다 (390px 오버플로 해소 — 데스크톱 패리티 모바일 한정 해제)', () => {
    const userBase = loadJson('layouts/_user_base.json');
    const mobileRight = findNode(userBase.components, 'mobile_header_right');
    expect(mobileRight).toBeTruthy();
    const ids = (mobileRight.children ?? []).map((c: any) => c.id);
    expect(ids).not.toContain('mobile_lang_selector_wrap');
    expect(ids).not.toContain('mobile_currency_selector_wrap');
  });

  it('언어/통화는 모바일 드로어 최상단(mobile_drawer_prefs)에 있다', () => {
    const userBase = loadJson('layouts/_user_base.json');
    const drawer = findNode(userBase.components, 'mobile_nav_drawer');
    expect(drawer).toBeTruthy();

    const prefs = findNode(drawer, 'mobile_drawer_prefs');
    expect(prefs).toBeTruthy();

    // 언어: setLocale 액션 + $locales iteration
    const prefsRaw = JSON.stringify(prefs);
    expect(prefsRaw).toContain('setLocale');
    expect(prefsRaw).toContain('{{$locales}}');

    // 통화: 슬롯 컨테이너. id 는 필수 — SlotContainer 가 같은 슬롯을 여러 컨테이너에서
    // 렌더할 때 주입 컴포넌트 root id 를 `{id}__{containerId}` 로 스코프하기 때문
    // (데스크톱 Header.tsx 와 동시 소비 → id 없으면 HTML id 중복).
    const currencyWrap = findNode(prefs, 'mobile_drawer_currency_wrap');
    expect(currencyWrap).toBeTruthy();
    expect(currencyWrap.name).toBe('SlotContainer');
    expect(currencyWrap.props?.slotId).toBe('header_currency');
  });

  /**
   * 엔진의 `iteration` 은 그 속성을 가진 노드 **자신**을 항목 수만큼 복제한다.
   * flex 컨테이너에 iteration 을 걸면 컨테이너가 복제되어 각 칩이 자기 컨테이너를 갖고
   * 세로로 쌓인다. 가로 나열을 유지하려면 iteration 은 반드시 Button 자신에 있어야 한다.
   */
  it('드로어 언어 칩은 flex-wrap 컨테이너 + Button 에 iteration (가로 나열)', () => {
    const userBase = loadJson('layouts/_user_base.json');
    const prefs = findNode(userBase.components, 'mobile_drawer_prefs');
    const langSection = (prefs.children ?? []).find(
      (c: any) => typeof c.if === 'string' && c.if.includes('$locales.length')
    );
    expect(langSection).toBeTruthy();

    // 칩 컨테이너는 아코디언 펼침 영역 안에 있다. 위치 인덱스가 아니라 구조로 찾는다
    // (트리거 행이 앞에 추가되어도 깨지지 않도록).
    const chipRow = walkNode(langSection, (n: any) =>
      n.name === 'Div' &&
      typeof n.props?.className === 'string' &&
      n.props.className.includes('flex-wrap')
    );
    expect(chipRow).toBeTruthy();
    expect(chipRow.props.className).toContain('flex');
    expect(chipRow.iteration).toBeUndefined();

    const button = chipRow.children[0];
    expect(button.name).toBe('Button');
    expect(button.iteration?.source).toBe('{{$locales}}');
    expect(button.iteration?.item_var).toBe('loc');
    expect(button.props.className).toContain('rounded-full');
    expect(button.props.className).toContain('whitespace-nowrap');
  });

  /**
   * 접기/펼치기 — 언어·통화·배송국가를 전부 펼쳐 두면 드로어가 세로로 길어져 정작 메뉴가
   * 스크롤 밖으로 밀린다. 언어와 통화/배송국가를 각각 독립 아코디언으로 접고, 접힘
   * 상태에서도 현재 선택값을 요약 표기한다(무엇이 선택돼 있는지 펼치지 않고 알 수 있어야 함).
   *
   * 언어 = 템플릿 소유(_global.mobileLanguageOpen),
   * 통화/배송국가 = 이커머스 주입 조각 소유(_local.showCurrencyDropdown) → 서로 독립.
   */
  it('드로어 언어 섹션은 기본 접힘 + 트리거에 현재 언어를 요약 표기한다', () => {
    const userBase = loadJson('layouts/_user_base.json');
    const langSection = findNode(userBase.components, 'mobile_drawer_language');
    expect(langSection).toBeTruthy();

    const toggle = findNode(langSection, 'mobile_drawer_language_toggle');
    expect(toggle?.name).toBe('Button');
    expect(toggle.props['aria-expanded']).toBe('{{_global.mobileLanguageOpen ?? false}}');

    // 토글은 언어 전용 플래그를 뒤집는다
    const act = toggle.actions[0];
    expect(act.handler).toBe('setState');
    expect(act.params.target).toBe('global');
    expect(act.params.mobileLanguageOpen).toBe('{{!_global.mobileLanguageOpen}}');

    // 접힘 상태 요약 = 현재 언어명 + chevron
    const summary = JSON.stringify(toggle.children);
    expect(summary).toContain('localeNames?.[$locale]');
    expect(summary).toContain('chevron-down');
    expect(summary).toContain("{{_global.mobileLanguageOpen ? 'rotate-180' : ''}}");

    // 펼침 영역: falsy(기본) 분기가 닫힘(max-h-0)이어야 기본 접힘이 성립한다
    const body = walkNode(langSection, (n: any) =>
      typeof n.props?.className === 'string' &&
      n.props.className.includes('overflow-hidden') &&
      n.props.className.includes('mobileLanguageOpen')
    );
    expect(body).toBeTruthy();
    expect(body.props.className).toContain("'max-h-screen opacity-100 mt-2' : 'max-h-0 opacity-0 mt-0'");
  });

  it('언어 토글은 통화 플래그를 건드리지 않는다 (독립 개폐)', () => {
    const userBase = loadJson('layouts/_user_base.json');
    const toggle = findNode(userBase.components, 'mobile_drawer_language_toggle');
    expect(toggle).toBeTruthy();
    // 언어 = _global.mobileLanguageOpen / 통화 = _local.showCurrencyDropdown (모듈 조각 소유)
    expect(JSON.stringify(toggle)).not.toContain('showCurrencyDropdown');
  });

  it('드로어 언어/통화 섹션은 비회원에게도 노출된다 (아코디언 currentUser 게이트 바깥)', () => {
    const userBase = loadJson('layouts/_user_base.json');
    const drawer = findNode(userBase.components, 'mobile_nav_drawer');
    const prefs = (drawer.children ?? []).find((c: any) => c.id === 'mobile_drawer_prefs');
    // 드로어의 직계 자식이어야 한다 (로그인 아코디언 `if: currentUser?.uuid` 안이면 비회원 미노출)
    expect(prefs).toBeTruthy();
    expect(prefs.if).toBeUndefined();
  });

  it('로그인 아코디언 안에 중복 언어 블록이 남아있지 않다 (드로어 단일 소스)', () => {
    const userBase = loadJson('layouts/_user_base.json');
    const drawer = findNode(userBase.components, 'mobile_nav_drawer');
    const accordion = (drawer.children ?? []).find(
      (c: any) => typeof c.if === 'string' && c.if.includes('currentUser?.uuid')
    );
    expect(accordion).toBeTruthy();
    expect(JSON.stringify(accordion)).not.toContain('setLocale');
  });

  it('헤더 드롭다운 잔여 상태(mobileLangMenuOpen)가 제거되었다', () => {
    expect(userBaseText).not.toContain('mobileLangMenuOpen');
  });

  it('통화 슬롯 주입 앵커(header_currency_inject_anchor)가 _user_base 에 있다', () => {
    expect(userBaseText).toContain('header_currency_inject_anchor');
  });
});

describe('이커머스 헤더 통화 주입 확장 (slot=header_currency)', () => {
  const userExt = JSON.parse(
    loadRepo('modules/_bundled/sirsoft-ecommerce/resources/extensions/header-currency-selector-user.json')
  );
  const adminExt = JSON.parse(
    loadRepo('modules/_bundled/sirsoft-ecommerce/resources/extensions/header-currency-selector-admin.json')
  );

  it('유저 확장이 _user_base 에 header_currency 슬롯 노드를 주입한다', () => {
    expect(userExt.target_layout).toBe('_user_base');
    const node = userExt.injections[0].components[0];
    expect(node.slot).toBe('header_currency');
  });

  it('유저 통화 선택은 로그인 회원이면 PUT user/currency 로 영속 저장한다 (비회원=localStorage)', () => {
    const raw = loadRepo('modules/_bundled/sirsoft-ecommerce/resources/extensions/header-currency-selector-user.json');
    expect(raw).toContain('g7_preferred_currency');
    expect(raw).toContain('/api/modules/sirsoft-ecommerce/user/currency');
    expect(raw).toContain('currentUser?.uuid');
  });

  it('PUT user/currency apiCall 은 auth_mode:required 로 Bearer 토큰을 싣는다 (401 회귀 차단)', () => {
    // G7 은 Sanctum Bearer 인증. apiCall 기본 auth_mode 는 'none' 이라 Bearer 미부착 → 401.
    // 로그인 회원 통화 영속 PUT 은 반드시 auth_mode:required 여야 Authorization 헤더가 붙는다.
    const node = userExt.injections[0].components[0];
    // 트리에서 PUT user/currency apiCall 액션을 재귀 탐색
    function findPutCurrency(n: any): any {
      if (!n || typeof n !== 'object') return null;
      if (Array.isArray(n)) { for (const c of n) { const r = findPutCurrency(c); if (r) return r; } return null; }
      if (n.handler === 'apiCall' && typeof n.target === 'string' && n.target.includes('/user/currency')) return n;
      for (const k of ['children', 'actions', 'conditions', 'then', 'else']) {
        if (n[k]) { const r = findPutCurrency(n[k]); if (r) return r; }
      }
      return null;
    }
    const apiAction = findPutCurrency(node);
    expect(apiAction).toBeDefined();
    expect(apiAction.params?.method).toBe('PUT');
    expect(apiAction.auth_mode).toBe('required');
  });

  it('관리자 통화 표시는 영속 PUT 이 없다 (표시 전용 — auth_mode 불요, D-USERCUR-3)', () => {
    const raw = loadRepo('modules/_bundled/sirsoft-ecommerce/resources/extensions/header-currency-selector-admin.json');
    expect(raw).not.toContain('/api/modules/sirsoft-ecommerce/user/currency');
  });

  /**
   * 모바일 드로어는 overflow-y-auto + w-80 이라 absolute 드롭다운 패널이 스크롤 경계에서 잘린다.
   * SlotContainer 는 주입 자식에게 variant/context prop 을 전달하지 않으므로 컨테이너별로
   * 다르게 그릴 수 없다 → 주입 노드 자체에 responsive.portable(0~1023px) 오버라이드를 둔다.
   *
   * portable 에서는 팝오버가 아니라 드로어 안 인라인 아코디언이 되며, 개폐는 데스크톱과 같은
   * `_local.showCurrencyDropdown` 이 결정한다(기본 접힘).
   */
  it('유저 확장 트리거는 portable 에서 아코디언 행 + 현재값 요약을 노출한다', () => {
    const node = userExt.injections[0].components[0];
    const trigger = (node.children ?? []).find((c: any) => c.name === 'Button');
    expect(trigger).toBeTruthy();

    const portable = trigger.responsive?.portable;
    expect(portable).toBeTruthy();
    expect(portable.props.className).not.toBe('hidden');
    expect(portable.props.className).toContain('w-full');
    expect(portable.props.className).toContain('justify-between');

    // 접힘 상태에서도 현재 통화/배송국가를 알 수 있어야 한다
    const summary = JSON.stringify(portable.children);
    expect(summary).toContain('preferredCurrency');
    expect(summary).toContain('preferredShippingCountry');
    expect(summary).toContain('chevron-down');
  });

  it('유저 확장 패널은 portable 에서도 _local.showCurrencyDropdown 으로 게이트된다 (기본 접힘)', () => {
    const node = userExt.injections[0].components[0];
    const panel = (node.children ?? []).find(
      (c: any) => c.name === 'Div' && c.props?.role === 'listbox'
    );
    expect(panel).toBeTruthy();

    // 데스크톱 기본 경로는 그대로 (회귀 방지)
    expect(panel.if).toBe('{{_local.showCurrencyDropdown}}');
    expect(panel.props.className).toContain('absolute');
    expect(panel.props.className).toContain('z-50');

    // portable 오버라이드: if 를 생략해 base if 를 상속(접힘 기본) + 정적 흐름
    const portable = panel.responsive?.portable;
    expect(portable).toBeTruthy();
    expect(portable.if).toBeUndefined();
    expect(portable.props.className).toContain('static');
    expect(portable.props.className).not.toContain('absolute');
    expect(portable.props.className).not.toContain('z-50');
    expect(portable.props.role).toBe('listbox');
  });

  it('유저 확장 백드롭은 portable 에서 꺼진다 (드로어 클릭 가로채기 방지)', () => {
    const node = userExt.injections[0].components[0];
    const backdrop = (node.children ?? []).find(
      (c: any) => c.name === 'Div' && (c.props?.className ?? '').includes('fixed inset-0')
    );
    expect(backdrop).toBeTruthy();
    expect(backdrop.responsive?.portable?.if).toBe('{{false}}');
  });

  it('관리자 확장이 _admin_base 에 header_currency 슬롯 + init_actions(통화 복원)를 기여한다', () => {
    expect(adminExt.target_layout).toBe('_admin_base');
    const node = adminExt.injections[0].components[0];
    expect(node.slot).toBe('header_currency');
    expect(Array.isArray(adminExt.init_actions)).toBe(true);
    expect(adminExt.init_actions[0].handler).toBe('sirsoft-ecommerce.initPreferredCurrency');
  });
});
