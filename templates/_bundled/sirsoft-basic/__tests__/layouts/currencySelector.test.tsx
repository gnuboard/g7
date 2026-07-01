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
 * 회귀 차단:
 * - _user_base.json: defaultCurrency/preferredCurrency(_global)/availableCurrencies 주입 + X-Currency 헤더.
 * - init 핸들러 호출이 모듈 네임스페이스(sirsoft-ecommerce.initPreferredCurrency)로 되어 있다.
 * - 헤더에 통화 슬롯(SlotContainer header_currency)과 언어 버튼이 있고, Header.tsx 는 통화 코드/모듈을 모른다.
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

  it('모바일 헤더(_user_base.json)도 header_currency 슬롯(SlotContainer)을 마운트한다', () => {
    expect(userBaseText).toContain('mobile_currency_selector_wrap');
    expect(userBaseText).toContain('"slotId": "header_currency"');
  });

  it('모바일 헤더에 언어 선택기가 통화와 같은 줄에 노출된다 (데스크톱 패리티, 4표면 일관)', () => {
    const userBase = loadJson('layouts/_user_base.json');
    // 모바일 헤더 우측 영역에서 언어 셀렉터와 통화 슬롯을 찾는다
    function find(node: any, id: string): any {
      if (!node || typeof node !== 'object') return null;
      if (Array.isArray(node)) { for (const n of node) { const r = find(n, id); if (r) return r; } return null; }
      if (node.id === id) return node;
      for (const k of ['children', 'components']) { if (node[k]) { const r = find(node[k], id); if (r) return r; } }
      return null;
    }
    const mobileRight = find(userBase.components, 'mobile_header_right');
    expect(mobileRight).toBeTruthy();
    const ids = (mobileRight.children ?? []).map((c: any) => c.id);
    expect(ids).toContain('mobile_lang_selector_wrap');
    expect(ids).toContain('mobile_currency_selector_wrap');
    // 언어 버튼은 setLocale + 현재 로케일 코드($locale) 표기
    const langWrap = find(mobileRight, 'mobile_lang_selector_wrap');
    const raw = JSON.stringify(langWrap);
    expect(raw).toContain('setLocale');
    expect(raw).toContain('{{$locale}}');
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

  it('관리자 확장이 _admin_base 에 header_currency 슬롯 + init_actions(통화 복원)를 기여한다', () => {
    expect(adminExt.target_layout).toBe('_admin_base');
    const node = adminExt.injections[0].components[0];
    expect(node.slot).toBe('header_currency');
    expect(Array.isArray(adminExt.init_actions)).toBe(true);
    expect(adminExt.init_actions[0].handler).toBe('sirsoft-ecommerce.initPreferredCurrency');
  });
});
