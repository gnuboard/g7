/**
 * @file admin-extension-list-action-gates.test.tsx
 * @description 확장 목록 카드의 버튼 잠금이 서버의 설치·활성·삭제·업데이트 거절 조건과 맞는지 (#703)
 *
 * 서버는 (1) 코어 요구 버전을 만족하지 못하는 확장의 설치·활성을 422(`core_version_mismatch`)로,
 * (2) 설치·삭제·업데이트가 진행 중인 확장의 활성·비활성·삭제·업데이트를 422 로 거절한다.
 * 카드가 그 상태에서 스위치·버튼을 켜 두면 운영자는 누른 뒤 실패만 받는다.
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import templateTabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';
import templateTabUser from '../../layouts/partials/admin_template_list/_tab_user.json';
import templateList from '../../layouts/admin_template_list.json';
import languagePackList from '../../layouts/partials/admin_language_pack_list/_content.json';
import languagePackHost from '../../layouts/admin_language_pack_list.json';
import settingsHost from '../../layouts/admin_settings.json';

type Json = Record<string, any>;

/** 확장 카드 격자의 items 바인딩을 찾는다. */
function itemsBinding(tree: unknown): string {
  let found: string | undefined;
  const walk = (n: unknown): void => {
    if (found) return;
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (obj.name === 'ExtensionCardGrid' && typeof obj.props?.items === 'string') {
        found = obj.props.items;
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  expect(found, 'ExtensionCardGrid items 바인딩이 없습니다.').toBeDefined();
  return found!;
}

/** 목록 행 하나를 카드 항목으로 평가한다(바인딩이 읽는 데이터소스 이름을 모두 채운다). */
function cardFor(binding: string, row: Json): Json {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(binding.trim())!;
  const src = { data: { data: [row] } };
  const ctx: Record<string, unknown> = { modules: src, plugins: src, templates: src, adminTemplates: src, userTemplates: src, _global: {}, query: {} };
  // eslint-disable-next-line no-new-func
  const fn = new Function(...Object.keys(ctx), `return (${m[1]});`);
  const out = fn(...Object.values(ctx));
  expect(Array.isArray(out) && out.length === 1, '카드 항목을 만들지 못했습니다.').toBe(true);
  return out[0];
}

const allowed = { can_activate: true, can_install: true, can_uninstall: true, can_edit_layouts: true };

describe('확장 목록 — 코어 비호환 확장', () => {
  /**
   * @scenario defect=extension_core_incompatible_actions
   * @effects incompatible_extension_install_and_activate_locked
   */
  it('모듈·플러그인: 코어 비호환이면 설치 버튼과 켜기 스위치가 잠기고, 켜져 있는 것은 끌 수 있다', () => {
    for (const tree of [moduleList, pluginList]) {
      const binding = itemsBinding(tree);
      const install = cardFor(binding, { identifier: 'x', status: 'uninstalled', core_compatible: false, abilities: allowed });
      expect(install.primaryAction.disabled).toBe(true);

      const off = cardFor(binding, { identifier: 'x', status: 'inactive', core_compatible: false, abilities: allowed });
      expect(off.toggle.disabled).toBe(true);

      const on = cardFor(binding, { identifier: 'x', status: 'active', core_compatible: false, abilities: allowed });
      expect(on.toggle.disabled, '켜진 비호환 확장은 끌 수 있어야 합니다(과차단 금지).').toBe(false);

      const ok = cardFor(binding, { identifier: 'x', status: 'inactive', core_compatible: true, abilities: allowed });
      expect(ok.toggle.disabled).toBe(false);
      const okInstall = cardFor(binding, { identifier: 'x', status: 'uninstalled', core_compatible: true, abilities: allowed });
      expect(okInstall.primaryAction.disabled).toBe(false);
    }
  });

  /**
   * @scenario defect=extension_core_incompatible_actions
   * @effects incompatible_extension_install_and_activate_locked
   */
  it('템플릿: 코어 비호환이면 설치 버튼과 켜기 스위치가 잠긴다', () => {
    for (const tree of [templateTabAdmin, templateTabUser]) {
      const binding = itemsBinding(tree);
      expect(cardFor(binding, { identifier: 'x', status: 'uninstalled', core_compatible: false, abilities: allowed }).primaryAction.disabled).toBe(true);
      expect(cardFor(binding, { identifier: 'x', status: 'inactive', core_compatible: false, abilities: allowed }).toggle.disabled).toBe(true);
      expect(cardFor(binding, { identifier: 'x', status: 'inactive', core_compatible: true, abilities: allowed }).toggle.disabled).toBe(false);
    }
  });
});

/** 레이아웃에서 409(의존 충돌) 처리 액션 목록을 찾는다. */
function conflictActionsOf(tree: unknown): Json[] {
  let found: Json | undefined;
  const walk = (n: unknown): void => {
    if (found) return;
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      const handler = obj.errorHandling?.['409'] ?? obj.onError?.['409'];
      if (handler) {
        found = handler;
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  expect(found, '409 처리 액션이 없습니다.').toBeDefined();
  return asArray(found!.actions ?? found);
}

describe('확장 목록 — 의존 충돌(409) 후 스위치', () => {
  /**
   * 스위치를 누르면 카드가 먼저 꺼진 모양으로 바뀐다. 서버가 409 로 강제 확인 창을 띄우고 운영자가
   * 「취소」하면 확장은 그대로 켜져 있는데 스위치만 꺼진 채 남았다(#703 실측) — 409 에서도 목록을 다시
   * 받아 스위치를 서버 값으로 되맞춘다.
   *
   * @scenario defect=extension_toggle_conflict_cancel
   * @effects toggle_resynced_after_conflict
   */
  it('모듈·플러그인: 409 처리에서 목록을 다시 받아 스위치를 서버 값으로 되맞춘다', () => {
    for (const [tree, source] of [[moduleList, 'modules'], [pluginList, 'plugins']] as const) {
      const actions = conflictActionsOf(tree);
      const refetch = actions.find((a: Json) => a.handler === 'refetchDataSource' && (a.params?.dataSourceId ?? a.dataSourceId) === source);
      expect(refetch, `${source} 409 처리에 목록 재조회가 없습니다.`).toBeDefined();
      expect(actions.some((a: Json) => a.handler === 'openModal'), `${source} 409 처리에서 강제 창을 열어야 합니다.`).toBe(true);
    }
  });

  /**
   * 목록을 다시 받아도 서버 값이 그대로면 스위치의 checked 가 바뀌지 않아 누른 값이 남는다(엔진은 렌더마다
   * 행 객체를 새로 만들어 객체 동일성으로는 재조회를 알 수 없다). 서버가 거절하는 경로 — 실패와 409 — 는
   * 재동기 신호(`_global.extensionToggleResync`)를 올리고, 격자는 그 신호를 `resyncKey` 로 받는다.
   *
   * @scenario defect=extension_toggle_conflict_cancel
   * @effects toggle_resynced_after_conflict
   */
  it('모듈·플러그인·언어팩: 실패·409 경로가 재동기 신호를 올리고 격자가 그 신호를 받는다', () => {
    for (const [tree, source] of [[moduleList, 'modules'], [pluginList, 'plugins'], [languagePackList, 'language_packs']] as const) {
      expect(gridProp(tree, 'resyncKey'), `${source} 격자에 resyncKey 바인딩이 없습니다.`).toContain('_global.extensionToggleResync');
      expect(hasResyncBump(conflictActionsOf(tree)), `${source} 409 처리에 재동기 신호가 없습니다.`).toBe(true);
      expect(hasResyncBump(toggleActionOf(tree).onError), `${source} 토글 실패 처리에 재동기 신호가 없습니다.`).toBe(true);
      expect(hasResyncBump(toggleActionOf(tree).onSuccess), `${source} 성공 처리는 서버 값이 바뀌므로 신호가 필요 없습니다.`).toBe(false);
    }
  });

  /**
   * 템플릿 스위치는 곧바로 부르지 않고 확인 창을 연다. 창을 닫으면(취소) 요청 자체가 없어 스위치만 뒤집힌
   * 채 남는다 — 누르는 즉시 재동기 신호를 올려 스위치를 서버 값에 두고, 확인하면 재조회가 새 값을 준다.
   *
   * @scenario defect=extension_toggle_conflict_cancel
   * @effects toggle_resynced_after_conflict
   */
  it('템플릿: 스위치를 누르면 확인 창을 열기 전에 재동기 신호를 올린다', () => {
    for (const tree of [templateTabAdmin, templateTabUser]) {
      expect(gridProp(tree, 'resyncKey')).toContain('_global.extensionToggleResync');
      const actions = asArray(toggleActionOf(tree).actions);
      const bump = actions.findIndex((a: Json) => hasResyncBump(a));
      const open = actions.findIndex((a: Json) => a.handler === 'openModal');
      expect(bump, '템플릿 토글에 재동기 신호가 없습니다.').toBeGreaterThanOrEqual(0);
      expect(bump).toBeLessThan(open);
    }
  });
});

/** 확장 카드 격자의 prop 값 하나를 읽는다. */
function gridProp(tree: unknown, prop: string): string {
  let found: string | undefined;
  const walk = (n: unknown): void => {
    if (found !== undefined) return;
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (obj.name === 'ExtensionCardGrid' && obj.props && prop in obj.props) {
        found = String(obj.props[prop]);
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  return found ?? '';
}

/** 격자의 onToggle 액션을 찾는다. */
function toggleActionOf(tree: unknown): Json {
  let found: Json | undefined;
  const walk = (n: unknown): void => {
    if (found) return;
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (obj.type === 'action' && obj.event === 'onToggle') {
        found = obj;
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  expect(found, 'onToggle 액션이 없습니다.').toBeDefined();
  return found!;
}

/** 액션 트리 안에 재동기 신호를 올리는 setState 가 있는지 본다. */
function hasResyncBump(n: unknown): boolean {
  if (Array.isArray(n)) return n.some(hasResyncBump);
  if (n && typeof n === 'object') {
    const obj = n as Json;
    if (obj.handler === 'setState' && typeof obj.params?.extensionToggleResync === 'string') return true;
    return Object.values(obj).some(hasResyncBump);
  }
  return false;
}

describe('확장 목록 — 진행 중 상태', () => {
  /**
   * @scenario defect=extension_in_progress_actions
   * @effects in_progress_extension_actions_locked
   */
  it('모듈·플러그인: 설치·삭제·업데이트 진행 중에는 스위치·삭제·업데이트가 잠긴다', () => {
    for (const tree of [moduleList, pluginList]) {
      const binding = itemsBinding(tree);
      for (const status of ['installing', 'uninstalling', 'updating']) {
        const card = cardFor(binding, { identifier: 'x', status, update_available: true, core_compatible: true, abilities: allowed });
        expect(card.toggle.disabled, `${status} 토글`).toBe(true);
        const uninstall = asArray(card.actions).find((a: Json) => a.id === 'uninstall');
        expect(uninstall.disabled, `${status} 삭제`).toBe(true);
        // 업데이트 주 버튼은 아예 없거나 잠겨 있어야 한다
        expect(card.primaryAction === null || card.primaryAction.disabled === true, `${status} 업데이트`).toBe(true);
      }

      const idle = cardFor(binding, { identifier: 'x', status: 'inactive', update_available: true, core_compatible: true, abilities: allowed });
      expect(idle.toggle.disabled).toBe(false);
      expect(asArray(idle.actions).find((a: Json) => a.id === 'uninstall').disabled).toBe(false);
      expect(idle.primaryAction.disabled).toBe(false);
    }
  });

  /**
   * 템플릿도 활성화·삭제·업데이트를 진행 중 상태에서 422 로 거절한다(TemplateManager 가드).
   *
   * @scenario defect=extension_in_progress_actions
   * @effects in_progress_extension_actions_locked
   */
  it('템플릿: 설치·삭제·업데이트 진행 중에는 스위치·삭제·업데이트가 잠긴다', () => {
    for (const tree of [templateTabAdmin, templateTabUser]) {
      const binding = itemsBinding(tree);
      for (const status of ['installing', 'uninstalling', 'updating']) {
        const card = cardFor(binding, { identifier: 'x', status, update_available: true, core_compatible: true, is_compatible: true, abilities: allowed });
        expect(card.toggle.disabled, `${status} 토글`).toBe(true);
        expect(asArray(card.actions).find((a: Json) => a.id === 'uninstall').disabled, `${status} 삭제`).toBe(true);
        expect(asArray(card.actions).find((a: Json) => a.id === 'update').disabled, `${status} 업데이트 메뉴`).toBe(true);
        expect(card.secondaryAction === null || card.secondaryAction.disabled === true, `${status} 업데이트 버튼`).toBe(true);
      }

      const idle = cardFor(binding, { identifier: 'x', status: 'inactive', update_available: true, core_compatible: true, is_compatible: true, abilities: allowed });
      expect(idle.toggle.disabled).toBe(false);
      expect(asArray(idle.actions).find((a: Json) => a.id === 'uninstall').disabled).toBe(false);
      expect(asArray(idle.actions).find((a: Json) => a.id === 'update').disabled).toBe(false);
      expect(idle.secondaryAction.disabled).toBe(false);
    }
  });
});

/** data-ref(구운 JSON 에서는 id) 로 노드를 찾아 표시 조건(if)을 돌려준다. */
function ifOf(tree: unknown, ref: string): string {
  let found: string | undefined;
  const walk = (n: unknown): void => {
    if (found !== undefined) return;
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if ((obj.id === ref || obj.props?.['data-ref'] === ref) && typeof obj.if === 'string') {
        found = obj.if;
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  expect(found, `${ref} 의 표시 조건이 없습니다.`).toBeDefined();
  return found!;
}

/** 표시 조건을 관리자 기본 레이아웃이 주는 이름(current_user)만으로 평가한다. */
function evalIf(expr: string, isSuper: boolean): unknown {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(expr.trim())!;
  const ctx: Record<string, unknown> = { current_user: { data: { is_super: isSuper } }, _global: {}, _local: {} };
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(ctx), `try { return (${m[1]}); } catch (e) { return undefined; }`)(...Object.values(ctx));
}

describe('확장 목록 — 숨김 포함 토글', () => {
  /**
   * 조건이 관리자 기본 레이아웃에 없는 이름(currentUser)을 읽어 최고관리자에게도 토글이 나타나지 않았다
   * (#703 실측). 관리자 로그인 응답(current_user)이 주는 is_super 로만 판정해야 한다.
   *
   * @scenario defect=extension_hidden_toggle_super_only
   * @effects hidden_toggle_visible_only_to_super_admin
   */
  it('모듈·플러그인·템플릿: 최고관리자에게만 보이고 그 판정은 current_user 의 is_super 로 한다', () => {
    // 템플릿 체크박스는 탭마다 툴바 안에 있다(7.1.0 출시 전 점검 — 탭 위 별도 줄에서 옮김).
    const cases: Array<[unknown, string]> = [
      [moduleList, 'include_hidden_toggle'],
      [pluginList, 'include_hidden_toggle'],
      [templateTabUser, 'tpl_user_include_hidden_toggle'],
      [templateTabAdmin, 'tpl_admin_include_hidden_toggle'],
    ];
    for (const [tree, ref] of cases) {
      const expr = ifOf(tree, ref);
      expect(evalIf(expr, true), `${ref}: 최고관리자에게 보여야 합니다.`).toBe(true);
      expect(evalIf(expr, false), `${ref}: 일반 관리자에게는 숨겨야 합니다.`).toBe(false);
    }
  });

  /**
   * 숨김 포함 여부를 화면 상태(`_local.showHidden`)에 두면 페이지를 넘길 때(navigate) 그 값이 풀려
   * 체크는 켜진 채 목록은 숨김을 뺀 채 받았다(#703 실측: 2페이지 요청이 include_hidden=0).
   * 다른 필터처럼 주소(query)에 싣고, 목록 조회·체크 표시가 모두 그 값을 읽어야 한다.
   * 체크를 바꾸면 주소를 바꿔(1페이지로) 다시 받는다 — 바꾸기 전 값을 읽는 한 박자 늦은 재조회도 없다.
   *
   * @scenario defect=extension_hidden_toggle_super_only
   * @effects hidden_toggle_refetch_uses_new_value
   */
  it('모듈·플러그인·템플릿: 숨김 포함 여부는 주소에 싣고 목록 조회·체크 표시·체크 변경이 그 값을 쓴다', () => {
    // [목록 조회를 가진 레이아웃, 체크박스를 가진 레이아웃, 체크박스 id, 데이터소스 id]
    const cases: Array<[unknown, unknown, string, string]> = [
      [moduleList, moduleList, 'include_hidden_toggle', 'modules'],
      [pluginList, pluginList, 'include_hidden_toggle', 'plugins'],
      [templateList, templateTabUser, 'tpl_user_include_hidden_toggle', 'templates'],
      [templateList, templateTabAdmin, 'tpl_admin_include_hidden_toggle', 'templates'],
    ];
    for (const [tree, inputTree, ref, source] of cases) {
      const ds = asArray((tree as Json).data_sources).find((d: Json) => d.id === source) as Json;
      expect(String(ds?.params?.include_hidden), `${source}: 목록 조회가 주소의 include_hidden 을 읽지 않습니다.`).toContain('query.include_hidden');
      expect(String(ds?.params?.include_hidden), `${source}: 목록 조회가 화면 상태를 읽습니다.`).not.toContain('_local');

      const input = asArray(nodeById(inputTree, ref).children).find((c: Json) => c.name === 'Input') as Json;
      expect(String(input?.props?.checked), `${source}: 체크 표시가 주소 값을 읽지 않습니다.`).toContain('query.include_hidden');

      const changes = asArray(input?.actions).filter((a: Json) => a.type === 'change');
      expect(changes.length, `${source}: change 처리는 하나여야 합니다.`).toBe(1);
      const nav = changes[0].handler === 'navigate' ? changes[0] : asArray(changes[0].actions ?? changes[0].params?.actions).find((a: Json) => a.handler === 'navigate');
      expect(nav, `${source}: 체크를 바꾸면 주소를 바꾸지 않습니다.`).toBeTruthy();
      expect(nav.params?.mergeQuery, `${source}: 다른 필터를 지웁니다.`).toBe(true);
      expect(Object.keys(nav.params?.query ?? {}), `${source}: include_hidden 을 주소에 싣지 않습니다.`).toContain('include_hidden');
    }
  });
});

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

/** 트리에서 조건 없는 고정 대상 openModal 을 모은다. */
function openTargets(tree: unknown): Set<string> {
  const out = new Set<string>();
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (obj.handler === 'openModal' && typeof obj.target === 'string' && !obj.target.includes('{{')) out.add(obj.target);
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  return out;
}

/** 호스트가 싣는 창 조각의 루트 id 를 모은다(조각 파일을 읽어 최상위 Modal/Drawer id 를 본다). */
function hostModalIds(host: unknown): Set<string> {
  const ids = new Set<string>();
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (typeof obj.partial === 'string' && /_(modal|drawer)_/.test(obj.partial)) {
        const file = resolve(__dirname, '../../layouts', obj.partial);
        const text = readFileSync(file, 'utf-8');
        for (const m of text.matchAll(/"id":\s*"([^"]+)"/g)) ids.add(m[1]);
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(host);
  return ids;
}

describe('언어팩 목록 조각 — 호스트 창 정합', () => {
  /**
   * 언어팩 목록 조각은 두 호스트(언어팩 관리 화면 · 환경설정 언어팩 탭)가 함께 쓴다. 조각이 여는 창을
   * 호스트가 싣지 않으면 그 호스트에서는 아무 창도 뜨지 않는다 — 환경설정 탭에 자리 충돌 창이 빠져
   * 있었다(#703 실측).
   *
   * @scenario defect=extension_toggle_conflict_cancel
   * @effects toggle_resynced_after_conflict
   */
  it('조각이 여는 창을 두 호스트가 모두 싣는다', () => {
    const targets = openTargets(languagePackList);
    expect(targets.has('language_pack_slot_conflict_modal')).toBe(true);
    for (const [host, name] of [[languagePackHost, 'admin_language_pack_list'], [settingsHost, 'admin_settings']] as const) {
      const ids = hostModalIds(host);
      const missing = [...targets].filter((t) => !ids.has(t));
      expect(missing, `${name} 에 없는 창`).toEqual([]);
    }
  });
});
