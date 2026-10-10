/**
 * @file admin-extension-toolbar-layout.test.ts
 * @description 확장 관리 화면 툴바 배치 — 숨김 항목 체크박스 위치와 모바일 보기 전환 상자 높이
 *
 * 1. 툴바가 `justify-between` 에 자식 셋 [보기 전환, 체크박스, 검색] 이라 체크박스가 남는 폭의
 *    정중앙에 떴다(1440px 실측 x=698). 보기 전환에 `mr-auto` 를 주고 나머지를 오른쪽으로 민다.
 * 2. 모바일(portable)은 class 를 통째로 `row-stack` 으로 바꿔, row-stack 이 첫 자식(보기 전환
 *    상자)에 주는 8px 이 테두리 안에 들어가 상자가 49px(버튼 40px)로 아래가 비어 보였다.
 *    portable 교체를 없애고 `flex-wrap` 한 줄 구조로 둔다.
 * 3. 템플릿 화면은 체크박스가 탭 위 별도 줄에 있었다 — 탭마다 툴바 안으로 옮긴다.
 */

import { describe, it, expect } from 'vitest';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import templateList from '../../layouts/admin_template_list.json';
import templateTabUser from '../../layouts/partials/admin_template_list/_tab_user.json';
import templateTabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';
import languagePackContent from '../../layouts/partials/admin_language_pack_list/_content.json';

type Json = Record<string, any>;

/** id 로 노드를 찾는다(없으면 undefined). */
function findById(tree: unknown, id: string): Json | undefined {
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
  return found;
}

/** id 로 노드를 찾고 없으면 실패한다. */
function nodeById(tree: unknown, id: string): Json {
  const node = findById(tree, id);
  expect(node, `${id} 노드가 없습니다.`).toBeDefined();
  return node!;
}

const tokens = (cls: unknown): string[] => String(cls ?? '').split(/\s+/).filter(Boolean);
const portableClass = (node: Json): string | undefined => node.responsive?.portable?.props?.className;
const childIds = (node: Json): string[] => (node.children ?? []).map((c: Json) => c.id ?? c.name);

/**
 * 검색칸 너비 래퍼 — SearchBar 는 받은 class 를 안쪽 input 에만 걸어 바깥 div 가 내용 폭이 된다.
 * 한 줄 flex 툴바에서 늘어나려면 래퍼가 PC w-64, 모바일 w-full 을 가져야 한다(390px 실측 245.7px 로 줄었다).
 */
function expectSearchBox(tree: unknown): void {
  const box = nodeById(tree, 'search_bar_box');
  expect(tokens(box.props?.className)).toEqual(['w-64']);
  expect(tokens(portableClass(box))).toEqual(['w-full']);
  const bar = (box.children ?? []).find((c: Json) => c.id === 'search_bar');
  expect(bar, 'search_bar 가 래퍼 바로 안에 없습니다.').toBeDefined();
  expect(tokens(bar.props?.className)).toEqual(['w-full']);
}

/** 표시 조건을 관리자 기본 레이아웃이 주는 이름(current_user)만으로 평가한다. */
function evalIf(expr: string, isSuper: boolean): unknown {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(expr.trim())!;
  const ctx: Record<string, unknown> = { current_user: { data: { is_super: isSuper } }, _global: {}, _local: {} };
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(ctx), `try { return (${m[1]}); } catch (e) { return undefined; }`)(...Object.values(ctx));
}

describe('모듈·플러그인 관리 툴바', () => {
  /**
   * @scenario defect=extension_toolbar_hidden_toggle_position
   * @effects hidden_toggle_next_to_search_on_desktop
   */
  it('wrapper 는 portable 교체 없이 flex-wrap 한 줄 구조다', () => {
    for (const tree of [moduleList, pluginList]) {
      const wrapper = nodeById(tree, 'search_bar_wrapper');
      expect(wrapper.responsive?.portable, 'portable 에서 class 를 통째로 바꾸면 row-stack 패딩이 상자 안에 든다').toBeUndefined();
      const cls = tokens(wrapper.props?.className);
      expect(cls).toEqual(expect.arrayContaining(['flex', 'flex-wrap', 'items-center', 'gap-x-4', 'gap-y-3', 'mb-6']));
      expect(cls).not.toContain('justify-between');
      expect(cls).not.toContain('row-stack');
    }
  });

  /**
   * @scenario defect=extension_toolbar_hidden_toggle_position
   * @effects view_toggle_pushes_rest_right_on_desktop
   */
  it('보기 전환 상자는 데스크톱에 mr-auto, portable 에는 mr-auto 가 없고 둘 다 self-start 가 없다', () => {
    for (const tree of [moduleList, pluginList]) {
      const toggle = nodeById(tree, 'view_mode_toggle');
      const desktop = tokens(toggle.props?.className);
      const portable = tokens(portableClass(toggle));
      expect(desktop).toContain('mr-auto');
      expect(portable.length, 'portable class 가 없습니다').toBeGreaterThan(0);
      expect(portable).not.toContain('mr-auto');
      expect(portable).toEqual(expect.arrayContaining(['inline-flex', 'shrink-0', 'rounded-lg', 'border', 'overflow-hidden']));
      expect(desktop).not.toContain('self-start');
      expect(portable).not.toContain('self-start');
    }
  });

  /**
   * @scenario defect=extension_toolbar_hidden_toggle_position
   * @effects hidden_toggle_next_to_search_on_desktop
   */
  it('직속 자식 순서는 [보기 전환, 체크박스, 검색 래퍼] 이고 검색 너비는 래퍼가 맡는다', () => {
    for (const tree of [moduleList, pluginList]) {
      expect(childIds(nodeById(tree, 'search_bar_wrapper'))).toEqual(['view_mode_toggle', 'include_hidden_toggle', 'search_bar_box']);
      expectSearchBox(tree);
    }
  });
});

describe('템플릿 관리 툴바', () => {
  const tabs: Array<[unknown, string]> = [
    [templateTabUser, 'tpl_user'],
    [templateTabAdmin, 'tpl_admin'],
  ];

  /**
   * @scenario defect=extension_toolbar_hidden_toggle_position
   * @effects hidden_toggle_inside_template_tab_toolbar
   */
  it('탭 툴바는 portable 에 row-stack 이 없고 자식 순서가 [보기 전환, 체크박스, 검색] 이다', () => {
    for (const [tree, prefix] of tabs) {
      const group = nodeById(tree, 'toolbar_group');
      expect(tokens(group.props?.className)).toEqual(expect.arrayContaining(['flex-center', 'gap-4', 'shrink-0']));
      const portable = tokens(portableClass(group));
      expect(portable).not.toContain('row-stack');
      expect(portable).toEqual(expect.arrayContaining(['flex', 'flex-wrap', 'items-center', 'gap-x-4', 'gap-y-3', 'w-full']));
      expect(childIds(group)).toEqual([`${prefix}_view_mode_toggle`, `${prefix}_include_hidden_toggle`, 'search_bar_box']);
      expectSearchBox(tree);

      const toggle = nodeById(tree, `${prefix}_view_mode_toggle`);
      expect(tokens(toggle.props?.className)).not.toContain('self-start');
    }
  });

  /**
   * @scenario defect=extension_toolbar_hidden_toggle_position
   * @effects hidden_toggle_visible_only_to_super_admin
   */
  it('탭 체크박스는 최고관리자에게만 보인다', () => {
    for (const [tree, prefix] of tabs) {
      const label = nodeById(tree, `${prefix}_include_hidden_toggle`);
      expect(evalIf(label.if, true)).toBe(true);
      expect(evalIf(label.if, false)).toBe(false);
    }
  });

  /**
   * @scenario defect=extension_toolbar_hidden_toggle_position
   * @effects hidden_toggle_inside_template_tab_toolbar
   */
  it('탭 위 별도 체크박스 줄은 없다', () => {
    expect(findById(templateList, 'include_hidden_toggle_wrapper')).toBeUndefined();
    expect(findById(templateList, 'include_hidden_toggle')).toBeUndefined();
  });
});

describe('언어팩 관리 툴바', () => {
  /**
   * @scenario defect=extension_toolbar_view_toggle_box_height
   * @effects view_toggle_box_fits_buttons_on_mobile
   */
  it('portable class 에 row-stack 이 없고 세로 간격은 gap 으로 준다', () => {
    const bar = nodeById(languagePackContent, 'lp_filter_bar');
    const portable = tokens(portableClass(bar));
    expect(portable).not.toContain('row-stack');
    expect(portable).toEqual(expect.arrayContaining(['flex', 'flex-col', 'gap-3', 'mb-6']));
    expect(tokens(nodeById(languagePackContent, 'lp_view_mode_toggle').props?.className)).toContain('self-start');
  });
});
