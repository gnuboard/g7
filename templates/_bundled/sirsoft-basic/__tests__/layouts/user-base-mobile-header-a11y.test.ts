/**
 * @file user-base-mobile-header-a11y.test.ts
 * @description 모바일 머리글 아이콘 단추의 접근 이름과 드로어 언어 목록의 역할
 *
 * 7.1.0 전수 실측(O-C1-3, 결정 ⑬): 390 폭 첫 화면 머리글의 장바구니·사용자·메뉴 단추와 드로어 닫기 단추가
 * 아이콘만 있어 화면 읽기 프로그램에 이름 없는 단추로 읽혔다. 각 단추는 용도를 말하는 다국어 이름을 갖는다.
 * 드로어의 언어 칩은 `role="option"` 인데 감싼 목록에 `role="listbox"` 가 없어 고아 항목이었다.
 *
 * @scenario-manifest tests/scenarios/mobile-header-accessible-names.yaml
 */

import { describe, it, expect } from 'vitest';
import fs from 'node:fs';
import path from 'node:path';
import userBase from '../../layouts/_user_base.json';

type Json = Record<string, any>;

/** 조건에 맞는 첫 노드를 찾는다. */
function find(tree: unknown, pred: (n: Json) => boolean): Json | undefined {
  let found: Json | undefined;
  const walk = (n: unknown): void => {
    if (found) return;
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (pred(obj)) {
        found = obj;
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  return found;
}

const ROOT = path.resolve(__dirname, '../..');
const JA_ROOT = path.resolve(ROOT, '../../../lang-packs/_bundled/g7-template-sirsoft-basic-ja/frontend/partial');

/** `a.b.c` 키를 로케일 partial 에서 찾는다(첫 마디가 partial 파일 이름). */
function lookup(locale: 'ko' | 'en' | 'ja', key: string): unknown {
  const [file, ...rest] = key.split('.');
  const dir = locale === 'ja' ? JA_ROOT : path.join(ROOT, 'lang/partial', locale);
  const json = JSON.parse(fs.readFileSync(path.join(dir, `${file}.json`), 'utf8'));
  return rest.reduce<any>((o, k) => (o == null ? undefined : o[k]), json);
}

/** 문자열에 든 번역 키(`$t:key` 또는 `$t('key')`)를 모두 뽑는다. */
function keysIn(label: string): string[] {
  const keys = [...label.matchAll(/\$t:([a-z_.]+)/g)].map((m) => m[1]);
  keys.push(...[...label.matchAll(/\$t\('([a-z_.]+)'\)/g)].map((m) => m[1]));
  return keys;
}

const byId = (id: string) => find(userBase, (n) => n.id === id);

describe('모바일 머리글 아이콘 단추 접근 이름', () => {
  const cases: [string, string][] = [
    ['mobile_cart_btn', 'cart'],
    ['mobile_user_btn', 'user'],
    ['mobile_menu_toggle', 'menu'],
    ['mobile_drawer_close_btn', 'close'],
  ];

  // @scenario button=cart
  // @effects accessible_name_present, label_translated
  // @scenario button=user
  // @effects accessible_name_present, label_translated
  // @scenario button=menu
  // @effects accessible_name_present, label_translated
  // @scenario button=close
  // @effects accessible_name_present, label_translated
  it.each(cases)('%s 는 다국어 키로 된 aria-label 을 갖고, 키는 ko·en·ja 에 모두 있다', (id) => {
    const node = byId(id);
    expect(node, `${id} 단추가 없습니다.`).toBeDefined();
    const label = node!.props?.['aria-label'];
    expect(typeof label, `${id} 에 aria-label 이 없습니다.`).toBe('string');
    const keys = keysIn(label);
    expect(keys.length, `${id} aria-label 이 번역 키를 쓰지 않습니다: ${label}`).toBeGreaterThan(0);
    for (const key of keys) {
      for (const locale of ['ko', 'en', 'ja'] as const) {
        expect(typeof lookup(locale, key), `${locale} 에 ${key} 가 없습니다.`).toBe('string');
      }
    }
  });

  // @scenario button=user
  // @effects label_follows_login_state
  it('사용자 단추 이름은 로그인 여부에 따라 마이페이지/로그인으로 갈린다', () => {
    const label: string = byId('mobile_user_btn')!.props['aria-label'];
    const m = /^\{\{([\s\S]*)\}\}$/.exec(label.trim());
    expect(m, '로그인 여부 식이 아닙니다.').not.toBeNull();
    const $t = (k: string) => k;
    // eslint-disable-next-line no-new-func
    const run = (currentUser: unknown) => new Function('_global', '$t', `return (${m![1]});`)({ currentUser }, $t);
    // 키는 base(#754)의 아이콘 단추 이름 정리(common.*)를 따른다. 로그인 여부는 topic 대로 uuid 로 가린다.
    expect(run({ uuid: 'u' })).toBe('$t:common.mypage');
    expect(run(null)).toBe('$t:common.login');
    // 비회원도 전역 currentUser 는 빈 값([] 또는 {})이고 참으로 읽힌다 — uuid 로 가려야 「마이페이지」 로 잘못 부르지 않는다
    // (issue759 리베이스가 base 식 `_global.currentUser ?` 를 들여와 비회원에게 「마이페이지」 로 나온 것을 2026-10-09 실측)
    expect(run({})).toBe('$t:common.login');
    expect(run([])).toBe('$t:common.login');
  });

  // @scenario button=cart
  // @effects cart_count_in_name
  it('장바구니 단추 이름은 담긴 상품 수를 함께 말한다', () => {
    const label: string = byId('mobile_cart_btn')!.props['aria-label'];
    expect(label).toMatch(/\|count=\{\{[^}]*_global\.cartCount[^}]*\}\}/);
    for (const locale of ['ko', 'en', 'ja'] as const) {
      expect(String(lookup(locale, 'nav.cart_with_count'))).toContain('{{count}}');
    }
  });

  // @scenario button=menu
  // @effects menu_toggle_expanded_state
  it('메뉴 단추는 드로어가 열렸는지를 aria-expanded 로 알린다', () => {
    const expanded = byId('mobile_menu_toggle')!.props?.['aria-expanded'];
    expect(expanded).toMatch(/_global\.mobileMenuOpen/);
  });
});

describe('드로어 언어 목록 역할', () => {
  // @scenario button=drawer_language
  // @effects option_owned_by_listbox
  it('role=option 칩을 감싼 목록은 role=listbox 이고 이름을 갖는다', () => {
    const list = find(userBase, (n) => Array.isArray(n.children) && n.children.some((c: Json) => c?.props?.role === 'option'));
    expect(list, '언어 칩 목록이 없습니다.').toBeDefined();
    expect(list!.props?.role).toBe('listbox');
    expect(String(list!.props?.['aria-label'] ?? '')).toContain('$t:common.language');
  });
});
