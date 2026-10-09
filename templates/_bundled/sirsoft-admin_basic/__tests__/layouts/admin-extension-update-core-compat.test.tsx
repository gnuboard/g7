/**
 * @file admin-extension-update-core-compat.test.tsx
 * @description 업데이트 대상 버전이 코어를 만족하지 못할 때 — 목록 버튼은 잠그지 않고 업데이트 창에서 경고·강제 업데이트를 고른다 (F-D4)
 *
 * 목록 API 가 업데이트가 있는 행에 `is_compatible`·`required_core_version`·`current_core_version` 을 싣게 되면서
 * 이 값이 실제로 내려온다. 종전 카드 바인딩은 `is_compatible === false` 면 업데이트 버튼을 잠가, 경고와
 * 「경고를 무시하고 강제 업데이트」 체크가 있는 창에 들어갈 길이 없었다(2026-10-07 결정: 목록 버튼은 잠그지 않는다).
 * 창을 열 때 직전 확장에서 켠 강제 체크가 남아 있으면 다른 확장 업데이트에 force 가 실리므로 열 때 되돌린다.
 */

import { describe, it, expect } from 'vitest';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import templateTabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';
import templateTabUser from '../../layouts/partials/admin_template_list/_tab_user.json';
import moduleUpdateModal from '../../layouts/partials/admin_module_list/_modal_update.json';
import pluginUpdateModal from '../../layouts/partials/admin_plugin_list/_modal_update.json';
import templateUpdateModal from '../../layouts/partials/admin_template_list/_modal_update.json';

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

/** 조건에 맞는 노드를 모두 모은다. */
function collect(tree: unknown, match: (n: Json) => boolean): Json[] {
  const out: Json[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (match(obj)) out.push(obj);
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  return out;
}

const allowed = { can_activate: true, can_install: true, can_uninstall: true, can_edit_layouts: true };

/** 업데이트가 있고 업데이트 대상이 코어를 만족하지 못하는 행(목록 API 가 실제로 내려 주는 모양). */
const incompatibleUpdateRow = {
  identifier: 'x',
  name: '테스트 확장',
  status: 'active',
  version: '1.0.0',
  latest_version: '2.0.0',
  update_available: true,
  update_source: 'bundled',
  core_compatible: true,
  is_compatible: false,
  required_core_version: '>=999.0.0',
  current_core_version: '7.1.0',
  abilities: allowed,
};

describe('업데이트 대상 코어 비호환 — 목록 버튼', () => {
  /**
   * @scenario case=list_button_module_plugin_not_locked
   * @effects extension_update_button_not_locked_by_core_compat
   */
  it('모듈·플러그인: 업데이트 버튼이 잠기지 않고, 「코어 업그레이드 필요」 배지가 붙는다', () => {
    for (const tree of [moduleList, pluginList]) {
      const card = cardFor(itemsBinding(tree), incompatibleUpdateRow);
      expect(card.primaryAction, '업데이트 버튼이 있어야 합니다.').toBeTruthy();
      expect(card.primaryAction.disabled, '코어 비호환 업데이트도 창에는 들어갈 수 있어야 합니다.').toBe(false);
      expect(asArray(card.badges).some((b: Json) => b.label === '$t:extensions.badges.incompatible')).toBe(true);
    }
  });

  /**
   * @scenario case=list_button_template_not_locked
   * @effects extension_update_button_not_locked_by_core_compat
   */
  it('템플릿(관리자·사용자): 업데이트 버튼과 더보기 「업데이트」 가 잠기지 않는다', () => {
    for (const tree of [templateTabAdmin, templateTabUser]) {
      const card = cardFor(itemsBinding(tree), incompatibleUpdateRow);
      expect(card.secondaryAction.label).toBe('$t:admin.templates.actions.update');
      expect(card.secondaryAction.disabled).toBe(false);
      expect(asArray(card.actions).find((a: Json) => a.id === 'update').disabled).toBe(false);
    }
  });

  /**
   * @scenario case=list_button_permission_lock_kept
   * @effects extension_update_button_not_locked_by_core_compat
   */
  it('권한 없음·진행 중 잠금은 그대로다(과허용 금지)', () => {
    for (const tree of [moduleList, pluginList]) {
      const card = cardFor(itemsBinding(tree), { ...incompatibleUpdateRow, abilities: { ...allowed, can_install: false } });
      expect(card.primaryAction.disabled).toBe(true);
    }
    for (const tree of [templateTabAdmin, templateTabUser]) {
      const card = cardFor(itemsBinding(tree), { ...incompatibleUpdateRow, abilities: { ...allowed, can_install: false } });
      expect(card.secondaryAction.disabled).toBe(true);
    }
  });
});

describe('업데이트 창 — 경고와 강제 업데이트', () => {
  const cases: Array<[string, Json, string, string]> = [
    ['모듈', moduleUpdateModal, 'selectedModule', 'moduleForceUpdate'],
    ['플러그인', pluginUpdateModal, 'selectedPlugin', 'pluginForceUpdate'],
    ['템플릿', templateUpdateModal, 'selectedTemplate', 'templateForceUpdate'],
  ];

  /**
   * @scenario case=modal_warning_and_force_checkbox
   * @effects extension_update_modal_warns_and_offers_force
   */
  it.each(cases)('%s: 경고 상자는 목록 행의 is_compatible=false 로 뜨고, 그 안에 강제 체크·요구/현재 버전이 있다', (_label, modal, selected, force) => {
    const warnings = collect(modal, (n) => typeof n.if === 'string' && n.if.includes(`_global.${selected}?.is_compatible === false`) && n.props?.role === 'alert');
    expect(warnings.length, '경고 상자가 없습니다.').toBe(1);
    const text = JSON.stringify(warnings[0]);
    expect(text).toContain(`${selected}?.required_core_version`);
    expect(text).toContain(`${selected}?.current_core_version`);
    expect(text).toContain(force);
    expect(collect(warnings[0], (n) => n.name === 'Input' && n.props?.type === 'checkbox').length, '강제 체크박스가 없습니다.').toBe(1);
  });

  /**
   * @scenario case=modal_force_flag_reset_on_open
   * @effects extension_update_force_flag_reset_on_open
   */
  it('창을 여는 모든 경로가 강제 체크를 거짓으로 되돌린다(다른 확장에 force 가 새지 않게)', () => {
    const openers: Array<[string, Json, string, string, string]> = [
      ['모듈', moduleList, 'selectedModule', 'moduleLayoutStrategy', 'moduleForceUpdate'],
      ['플러그인', pluginList, 'selectedPlugin', 'pluginLayoutStrategy', 'pluginForceUpdate'],
      ['템플릿 관리자', templateTabAdmin, 'selectedTemplate', 'templateLayoutStrategy', 'templateForceUpdate'],
      ['템플릿 사용자', templateTabUser, 'selectedTemplate', 'templateLayoutStrategy', 'templateForceUpdate'],
    ];
    for (const [label, tree, selected, strategy, force] of openers) {
      const setStates = collect(tree, (n) => n.handler === 'setState'
        && typeof n.params?.[selected] === 'string' && n.params[selected].includes('$args')
        && strategy in (n.params ?? {}));
      expect(setStates.length, `${label}: 업데이트 창을 여는 setState 가 없습니다.`).toBeGreaterThan(0);
      for (const s of setStates) {
        expect(s.params[force], `${label}: 창을 열 때 ${force} 를 false 로 되돌려야 합니다.`).toBe(false);
      }
    }
  });
});
