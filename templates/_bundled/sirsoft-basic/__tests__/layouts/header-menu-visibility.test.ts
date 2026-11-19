/**
 * 헤더 메뉴 계산값 `siteMenu` — 보이기 조건·꺼진 항목·꺼진 모듈 거르기 (#434)
 *
 * `_user_base` 의 계산값 하나를 헤더·모바일 서랍·봇 화면이 함께 쓴다. 식을 문자열로 대조하지 않고 **실제 바인딩 엔진**으로
 * 평가해, 회원·비회원·역할·꺼짐·모듈 꺼짐·빈 상위 메뉴의 결과를 잠근다(식 방언이 바뀌어 평가가 깨져도 잡히게).
 */

import { describe, it, expect } from 'vitest';
import fs from 'fs';
import path from 'path';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';

const ROOT = path.resolve(__dirname, '../..');
const base = JSON.parse(fs.readFileSync(path.join(ROOT, 'layouts/_user_base.json'), 'utf8'));
const expr = String(base.computed.siteMenu).replace(/^\{\{/, '').replace(/\}\}$/, '');

const link = (id: string, extra: Record<string, unknown> = {}) => ({ id, kind: 'link', label: { ko: id }, url: `/${id}`, is_active: true, visibility: 'all', roles: [], children: [], ...extra });

const evaluate = (menu: unknown, global: Record<string, unknown> = {}) =>
  dataBindingEngine.evaluateExpression(expr, {
    _global: { activeModules: ['sirsoft-board', 'sirsoft-ecommerce'], ...global, templateSettings: menu === undefined ? undefined : { header: { menu } } },
  } as any) as Array<{ id: string; children: Array<{ id: string }> }>;

const ids = (items: Array<{ id: string; children?: Array<{ id: string }> }>) => items.map((item) => (item.children?.length ? `${item.id}(${item.children.map((c) => c.id).join(',')})` : item.id));

// 인증 응답(`UserResource::toAuthArray`) 모양 — 회원 식별은 uuid 이고 id 는 싣지 않는다
const MEMBER = { uuid: 'u-434', name: '회원', roles: [{ id: 2, identifier: 'user', name: '일반' }, { id: 9, identifier: 'vip', name: 'VIP' }] };

describe('siteMenu 계산값 — 보이기 조건 (#434)', () => {
  // @scenario case=header_menu
  // @effects header_menu_visibility_filtered
  it('메뉴 값이 없으면 기본 메뉴(홈·인기글·쇼핑·게시판 전체)', () => {
    expect(ids(evaluate(undefined))).toEqual(['home', 'popular', 'shop', 'boards']);
  });

  // @scenario case=header_menu
  // @effects header_menu_visibility_filtered
  it('비회원 — 회원만·역할(vip) 항목은 빠지고, 비회원만·역할(guest) 항목은 남는다', () => {
    const menu = [
      link('all'),
      link('members', { visibility: 'members' }),
      link('guests', { visibility: 'guests' }),
      link('vip', { visibility: 'roles', roles: ['vip'] }),
      link('guestrole', { visibility: 'roles', roles: ['guest'] }),
    ];

    expect(ids(evaluate(menu, { currentUser: null }))).toEqual(['all', 'guests', 'guestrole']);
  });

  // @scenario case=header_menu
  // @effects header_menu_visibility_filtered
  it('회원(vip 역할) — 회원만·vip 항목은 남고, 비회원만·guest 역할 항목은 빠진다', () => {
    const menu = [
      link('all'),
      link('members', { visibility: 'members' }),
      link('guests', { visibility: 'guests' }),
      link('vip', { visibility: 'roles', roles: ['vip'] }),
      link('admin', { visibility: 'roles', roles: ['admin'] }),
      link('guestrole', { visibility: 'roles', roles: ['guest'] }),
    ];

    expect(ids(evaluate(menu, { currentUser: MEMBER }))).toEqual(['all', 'members', 'vip']);
  });

  // @scenario case=header_menu
  // @effects header_menu_visibility_filtered
  it('하위 메뉴도 같은 규칙으로 거르고, 하위가 다 빠진 주소 없는 상위 메뉴는 통째로 숨긴다', () => {
    const menu = [
      link('community', { url: '', children: [link('free'), link('secret', { visibility: 'members' })] }),
      link('vipzone', { url: '', children: [link('vipnews', { visibility: 'roles', roles: ['vip'] })] }),
      link('withurl', { children: [link('hidden', { visibility: 'members' })] }),
    ];

    expect(ids(evaluate(menu, { currentUser: null }))).toEqual(['community(free)', 'withurl']);
    expect(ids(evaluate(menu, { currentUser: MEMBER }))).toEqual(['community(free,secret)', 'vipzone(vipnews)', 'withurl(hidden)']);
  });

  // @scenario case=header_menu
  // @effects header_menu_visibility_filtered
  it('꺼 둔 항목(is_active=false)은 상위·하위 모두 빠진다', () => {
    const menu = [link('on'), link('off', { is_active: false }), link('parent', { children: [link('childoff', { is_active: false }), link('childon')] })];

    expect(ids(evaluate(menu))).toEqual(['on', 'parent(childon)']);
  });

  // @scenario case=header_menu
  // @effects header_menu_visibility_filtered
  it('모듈이 꺼지면 그 자동 항목도 빠진다 — 게시판(인기글·게시판 전체), 쇼핑몰(쇼핑)', () => {
    const menu = [{ id: 'home', kind: 'home' }, { id: 'popular', kind: 'popular' }, { id: 'shop', kind: 'shop' }, { id: 'boards', kind: 'boards' }];

    expect(ids(evaluate(menu, { activeModules: ['sirsoft-ecommerce'] }))).toEqual(['home', 'shop']);
    expect(ids(evaluate(menu, { activeModules: ['sirsoft-board'] }))).toEqual(['home', 'popular', 'boards']);
  });
});

describe('봇 화면 메뉴 링크 (#434)', () => {
  const header = (function find(nodes: any[]): any {
    for (const node of nodes ?? []) {
      if (node?.name === 'Header') return node;
      const hit = find([...(node?.children ?? []), ...Object.values(node?.slots ?? {}).flat()]);
      if (hit) return hit;
    }
    return null;
  })(base.components);

  // @scenario case=header_menu
  // @effects header_menu_seo_links
  it('계산값을 펴서 {href, label} 목록을 만든다 — 하위 메뉴·자동 항목·게시판 묶음 포함, 주소 없는 상위는 빠진다', () => {
    const seoExpr = String(header.props.seoMenuLinks).replace(/^\{\{/, '').replace(/\}\}$/, '');
    const siteMenu = [
      { id: 'home', kind: 'home', children: [] },
      { id: 'c', kind: 'link', label: { ko: '커뮤니티' }, url: '', children: [{ id: 'n', kind: 'link', label: { ko: '공지' }, url: '/board/notice' }] },
      { id: 'boards', kind: 'boards', children: [] },
    ];
    const links = dataBindingEngine.evaluateExpression(seoExpr, {
      _computed: { siteMenu },
      _global: { shopBase: '/shop' },
      boards: { data: [{ slug: 'free', name: '자유' }] },
    } as any) as Array<{ href: string; label: string }>;

    expect(links.map((l) => l.href)).toEqual(['/', '/board/notice', '/board/free']);
    expect(links[1].label).toBe('공지');
    expect(links[2].label).toBe('자유');
  });
});
