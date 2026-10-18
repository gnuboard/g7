/**
 * @file admin-template-tab-include-hidden.test.ts
 * @description 템플릿 관리 종류 탭을 바꿔도 「숨김 항목 포함」 체크가 유지되는지
 *
 * 템플릿 관리는 종류(사용자·관리자)가 URL 경로(`/admin/templates/{type}`)에 들어가 탭 전환이 다른 주소로의
 * 이동이다. 탭 전환이 쿼리를 하나도 넘기지 않아, 사용자 탭에서 체크를 켠 뒤 관리자 탭으로 가면 체크가 풀리고
 * 숨김 템플릿이 목록에서 빠졌다(7.1.0 O-G3-4 실측). 모듈·플러그인 화면은 체크를 켠 뒤 검색·쪽 이동을 해도
 * 주소에 남아 유지된다 — 템플릿 탭 전환도 같은 값을 이어 준다. 검색어·쪽은 탭마다 다른 목록이라 넘기지 않는다.
 *
 * @scenario-manifest tests/scenarios/template-tab-include-hidden.yaml
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import templateList from '../../layouts/admin_template_list.json';
import templateTabUser from '../../layouts/partials/admin_template_list/_tab_user.json';
import templateTabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';

type Json = Record<string, any>;

/** id 로 노드를 찾는다. */
function nodeById(tree: unknown, id: string): Json {
  let found: Json | undefined;
  const walk = (n: unknown): void => {
    if (found) return;
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (obj.id === id) {
        found = obj;
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  expect(found, `${id} 노드가 없습니다.`).toBeDefined();
  return found!;
}

/** 액션 트리에서 navigate 액션을 모두 모은다(sequence 안 포함). */
function navigates(action: unknown): Json[] {
  const out: Json[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (obj.handler === 'navigate') out.push(obj);
      Object.values(obj).forEach(walk);
    }
  };
  walk(action);
  return out;
}

/** 식 하나를 주어진 이름들로 평가한다. */
function evalExpr(body: string, ctx: Record<string, unknown>): unknown {
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(ctx), `return (${body});`)(...Object.values(ctx));
}

/** `{{식}}` 전체면 값을, 글자 사이에 박힌 `{{식}}` 이면 치환한 문자열을 낸다. 식이 없으면 그대로. */
function evalBinding(expr: unknown, ctx: Record<string, unknown>): unknown {
  if (typeof expr !== 'string') return expr;
  const whole = /^\{\{([\s\S]*)\}\}$/.exec(expr.trim());
  if (whole && !whole[1].includes('}}')) return evalExpr(whole[1], ctx);
  return expr.replace(/\{\{([\s\S]*?)\}\}/g, (_, body: string) => String(evalExpr(body, ctx) ?? ''));
}

/** navigate 액션이 만드는 최종 쿼리(엔진 handleNavigate 와 같이 빈 값은 뺀다). mergeQuery 면 현재 쿼리에 덮어쓴다. */
function resultingQuery(nav: Json, ctx: Record<string, unknown>): Record<string, string> {
  const params = nav.params ?? {};
  const base: Record<string, string> = params.mergeQuery === true ? { ...(ctx.query as Record<string, string>) } : {};
  for (const [k, v] of Object.entries(params.query ?? {})) {
    const value = evalBinding(v, ctx);
    if (value === undefined || value === null || value === '') delete base[k];
    else base[k] = String(value);
  }
  return base;
}

const tabChangeNav = (): Json => {
  const tabs = nodeById(templateList, 'tab_navigation');
  const navs = navigates(asArray(tabs.actions).filter((a: Json) => a.type === 'change'));
  expect(navs.length, '탭 전환 navigate 는 하나여야 합니다.').toBe(1);
  return navs[0];
};

describe('템플릿 관리 — 종류 탭 전환과 숨김 포함 체크', () => {
  /**
   * @scenario start=include_hidden_on, transition=user_to_admin_tab
   * @effects tab_switch_keeps_include_hidden
   */
  it('사용자 탭에서 체크를 켠 채 관리자 탭으로 가면 include_hidden=1 이 이어진다', () => {
    const nav = tabChangeNav();
    const ctx = { $args: ['admin'], query: { include_hidden: '1', search: 'basic', page: '3' }, _global: {}, _local: {} };

    expect(evalBinding(nav.params?.path ?? nav.target, ctx)).toBe('/admin/templates/admin');
    expect(resultingQuery(nav, ctx).include_hidden).toBe('1');
  });

  /**
   * @scenario start=include_hidden_on, transition=user_to_admin_tab
   * @effects tab_switch_drops_search_and_page
   */
  it('검색어와 쪽은 탭마다 다른 목록이라 넘기지 않는다', () => {
    const nav = tabChangeNav();
    const ctx = { $args: ['admin'], query: { include_hidden: '1', search: 'basic', page: '3', per_page: '24' }, _global: {}, _local: {} };

    expect(resultingQuery(nav, ctx)).toEqual({ include_hidden: '1' });
  });

  /**
   * @scenario start=include_hidden_off, transition=admin_to_user_tab
   * @effects tab_switch_without_hidden_adds_nothing
   */
  it('체크를 끈 상태면 탭 전환 주소에 쿼리가 붙지 않는다', () => {
    const nav = tabChangeNav();
    for (const query of [{}, { include_hidden: '0' }, { search: 'basic' }]) {
      const ctx = { $args: ['user'], query, _global: {}, _local: {} };
      expect(resultingQuery(nav, ctx), JSON.stringify(query)).toEqual({});
    }
  });

  /**
   * @scenario start=include_hidden_on, transition=checkbox_reads_url
   * @effects both_tab_checkboxes_read_url
   */
  it('두 탭의 체크박스가 모두 주소 값을 읽어 이어진 값이 체크로 보인다', () => {
    for (const [tree, ref] of [
      [templateTabUser, 'tpl_user_include_hidden_toggle'],
      [templateTabAdmin, 'tpl_admin_include_hidden_toggle'],
    ] as const) {
      const input = asArray(nodeById(tree, ref).children).find((c: Json) => c.name === 'Input') as Json;
      expect(evalBinding(input?.props?.checked, { query: { include_hidden: '1' } }), ref).toBe(true);
      expect(evalBinding(input?.props?.checked, { query: {} }), ref).toBe(false);
    }
  });

  /**
   * 모듈·플러그인 화면의 목록 안 이동(검색, 쪽)은 mergeQuery 로 include_hidden 을 남긴다 — 템플릿 탭과 같은 결과인지 고정.
   *
   * @scenario start=include_hidden_on, transition=module_plugin_list_moves
   * @effects module_plugin_moves_keep_include_hidden
   */
  it('모듈·플러그인 목록 안 이동도 include_hidden=1 을 유지한다(일관)', () => {
    for (const [tree, label] of [[moduleList, 'modules'], [pluginList, 'plugins']] as const) {
      const listNavs = navigates(tree).filter((n) => String(n.params?.path ?? n.target).startsWith(`/admin/${label}`) && !String(n.params?.path ?? n.target).includes('{{'));
      expect(listNavs.length, label).toBeGreaterThan(0);
      for (const nav of listNavs) {
        const q = nav.params?.query ?? {};
        if ('include_hidden' in q) continue; // 체크박스 자체
        const ctx = { $args: ['2'], $event: { target: { checked: true } }, query: { include_hidden: '1' }, _local: { searchQuery: 'x' }, _global: {} };
        expect(resultingQuery(nav, ctx).include_hidden, `${label}: ${JSON.stringify(q)}`).toBe('1');
      }
    }
  });

  /**
   * 관리자 탭에서 켠 체크도 사용자 탭으로 돌아갈 때 이어진다 — 탭 전환 navigate 는 두 탭이 함께 쓴다.
   *
   * @scenario start=include_hidden_on, transition=admin_to_user_tab
   * @effects tab_switch_keeps_include_hidden
   */
  it('관리자 탭에서 체크를 켠 채 사용자 탭으로 가도 include_hidden=1 이 이어진다', () => {
    const nav = tabChangeNav();
    const ctx = { $args: ['user'], query: { include_hidden: '1', page: '2' }, _global: {}, _local: {} };

    expect(evalBinding(nav.params?.path ?? nav.target, ctx)).toBe('/admin/templates/user');
    expect(resultingQuery(nav, ctx)).toEqual({ include_hidden: '1' });
  });

  /**
   * 체크를 끈 채 사용자 탭에서 관리자 탭으로 가면 주소에 아무것도 붙지 않는다.
   *
   * @scenario start=include_hidden_off, transition=user_to_admin_tab
   * @effects tab_switch_without_hidden_adds_nothing
   */
  it('체크를 끈 채 관리자 탭으로 가면 쿼리가 붙지 않는다', () => {
    const nav = tabChangeNav();
    const ctx = { $args: ['admin'], query: { search: 'basic', page: '3' }, _global: {}, _local: {} };

    expect(evalBinding(nav.params?.path ?? nav.target, ctx)).toBe('/admin/templates/admin');
    expect(resultingQuery(nav, ctx)).toEqual({});
  });

  /**
   * 주소에 include_hidden 이 없거나 1 이 아니면 두 탭의 체크박스가 모두 꺼져 보인다.
   *
   * @scenario start=include_hidden_off, transition=checkbox_reads_url
   * @effects both_tab_checkboxes_read_url
   */
  it('주소에 include_hidden=1 이 없으면 두 탭의 체크박스가 꺼져 보인다', () => {
    for (const [tree, ref] of [
      [templateTabUser, 'tpl_user_include_hidden_toggle'],
      [templateTabAdmin, 'tpl_admin_include_hidden_toggle'],
    ] as const) {
      const input = asArray(nodeById(tree, ref).children).find((c: Json) => c.name === 'Input') as Json;
      for (const query of [{}, { include_hidden: '0' }, { search: 'basic' }]) {
        expect(evalBinding(input?.props?.checked, { query }), `${ref} ${JSON.stringify(query)}`).toBe(false);
      }
    }
  });

  /**
   * 체크를 끈 채 모듈·플러그인 목록 안에서 이동하면 include_hidden 이 새로 생기지 않는다.
   *
   * @scenario start=include_hidden_off, transition=module_plugin_list_moves
   * @effects module_plugin_moves_keep_include_hidden
   */
  it('체크를 끈 채 모듈·플러그인 목록 안에서 이동하면 include_hidden 이 붙지 않는다', () => {
    for (const [tree, label] of [[moduleList, 'modules'], [pluginList, 'plugins']] as const) {
      const listNavs = navigates(tree).filter((n) => String(n.params?.path ?? n.target).startsWith(`/admin/${label}`) && !String(n.params?.path ?? n.target).includes('{{'));
      expect(listNavs.length, label).toBeGreaterThan(0);
      for (const nav of listNavs) {
        const q = nav.params?.query ?? {};
        if ('include_hidden' in q) continue; // 체크박스 자체
        const ctx = { $args: ['2'], $event: { target: { checked: false } }, query: {}, _local: { searchQuery: 'x' }, _global: {} };
        expect(resultingQuery(nav, ctx).include_hidden, `${label}: ${JSON.stringify(q)}`).toBeUndefined();
      }
    }
  });
});
