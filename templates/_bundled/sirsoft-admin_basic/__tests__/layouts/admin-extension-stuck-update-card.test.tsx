/**
 * @file admin-extension-stuck-update-card.test.tsx
 * @description 멈춘 업데이트(`stuck_update`) 카드 — 정리·활성화 단추와 템플릿 「업데이트 중」 표시 (7.1.1 출시 전)
 *
 * 업데이트 프로세스가 도중에 죽으면 행이 `updating` 으로 남는다. 서버는 10분 넘게 남고 하는 프로세스가 없으면(목록 행 `stuck_update`)
 * 활성화·강제 업데이트 진입점에서 정리하지만, 카드는 `updating` 이면 스위치·업데이트 단추를 모두 잠가 화면에서는 정리할 곳이 없었다.
 * 템플릿 카드는 `updating` 표시조차 없이 꺼진 스위치로만 보였다.
 */

import { describe, it, expect } from 'vitest';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import templateTabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';
import templateTabUser from '../../layouts/partials/admin_template_list/_tab_user.json';
import moduleUpdateModal from '../../layouts/partials/admin_module_list/_modal_update.json';
import pluginUpdateModal from '../../layouts/partials/admin_plugin_list/_modal_update.json';
import templateUpdateModal from '../../layouts/partials/admin_template_list/_modal_update.json';

type Json = Record<string, any>;

/** 확장 카드 격자 노드를 찾는다. */
function gridOf(tree: unknown): Json {
  let found: Json | undefined;
  const walk = (n: unknown): void => {
    if (found) return;
    if (Array.isArray(n)) return n.forEach(walk);
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (obj.name === 'ExtensionCardGrid' && typeof obj.props?.items === 'string') {
        found = obj;
        return;
      }
      Object.values(obj).forEach(walk);
    }
  };
  walk(tree);
  expect(found, 'ExtensionCardGrid 가 없습니다.').toBeDefined();
  return found!;
}

/** 목록 행 하나를 카드 항목으로 평가한다. */
function cardFor(tree: unknown, row: Json): Json {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(gridOf(tree).props.items.trim())!;
  const src = { data: { data: [row] } };
  const ctx: Record<string, unknown> = { modules: src, plugins: src, templates: src, _global: {}, query: {} };
  // eslint-disable-next-line no-new-func
  const out = new Function(...Object.keys(ctx), `return (${m[1]});`)(...Object.values(ctx));
  return out[0];
}

/** 표현식 하나를 평가한다(`{{ }}` 안쪽). */
function evalExpr(expr: string, ctx: Record<string, unknown>): unknown {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(expr.trim())!;
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(ctx), `return (${m[1]});`)(...Object.values(ctx));
}

/** 이벤트 처리기(actions 의 event)를 찾는다. */
function handlerOf(tree: unknown, event: string): Json {
  const grid = gridOf(tree);
  const action = (grid.actions ?? []).find((a: Json) => a.event === event);
  expect(action, `${event} 처리기가 없습니다.`).toBeDefined();
  return action;
}

/** 트리에서 조건을 만족하는 첫 노드를 찾는다. */
function findNode(tree: unknown, pred: (n: Json) => boolean): Json | undefined {
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

const allowed = { can_activate: true, can_install: true, can_uninstall: true, can_edit_layouts: true };
const stuck = { identifier: 'g7test-x', status: 'updating', stuck_update: true, abilities: allowed };
const running = { identifier: 'g7test-x', status: 'updating', stuck_update: false, abilities: allowed };

describe('멈춘 업데이트 카드 — 모듈·플러그인', () => {
  /**
   * @scenario defect=stuck_update_card_no_recovery_action
   * @effects stuck_card_shows_badge_recover_action_and_enabled_toggle
   */
  it('멈춘 업데이트면 「업데이트 멈춤」 배지, 「정리 후 다시 업데이트」 주 단추, 켤 수 있는 스위치를 보인다', () => {
    for (const [tree, kind] of [[moduleList, 'modules'], [pluginList, 'plugins']] as const) {
      const card = cardFor(tree, stuck);
      const labels = card.badges.map((b: Json) => b.label);
      expect(labels).toContain(`$t:admin.${kind}.status.stuck_update`);
      expect(labels, `${kind}: 멈춘 업데이트를 「업데이트 중」으로 보이면 안 됩니다.`).not.toContain(`$t:admin.${kind}.status.updating`);
      expect(card.badges.find((b: Json) => b.label === `$t:admin.${kind}.status.stuck_update`).title).toBe(`$t:admin.${kind}.stuck_update_hint`);
      expect(card.primaryAction?.label).toBe(`$t:admin.${kind}.actions.recover_update`);
      expect(card.primaryAction?.disabled).toBe(false);
      expect(card.toggle.disabled, `${kind}: 활성화는 서버가 정리한 뒤 켜므로 스위치를 열어야 합니다.`).toBe(false);
      expect(card.toggle.checked).toBe(false);

      // 권한이 없으면 잠근다
      const noPerm = cardFor(tree, { ...stuck, abilities: {} });
      expect(noPerm.primaryAction.disabled).toBe(true);
      expect(noPerm.toggle.disabled).toBe(true);
    }
  });

  /**
   * @scenario defect=stuck_update_card_no_recovery_action
   * @effects running_update_card_stays_locked
   */
  it('실제로 도는(멈추지 않은) 업데이트는 종전처럼 「업데이트 중」 배지에 스위치·단추를 잠근다', () => {
    for (const [tree, kind] of [[moduleList, 'modules'], [pluginList, 'plugins']] as const) {
      const card = cardFor(tree, running);
      expect(card.badges.map((b: Json) => b.label)).toContain(`$t:admin.${kind}.status.updating`);
      expect(card.badges.map((b: Json) => b.label)).not.toContain(`$t:admin.${kind}.status.stuck_update`);
      expect(card.toggle.disabled).toBe(true);
      expect(card.primaryAction).toBeNull();
    }
  });

  /**
   * @scenario defect=stuck_update_card_no_recovery_action
   * @effects recover_action_opens_update_modal_with_force
   */
  it('「정리 후 다시 업데이트」는 업데이트 창을 강제 업데이트로 연다 — 서버는 force 일 때만 멈춘 업데이트를 정리한다', () => {
    for (const [tree, prefix] of [[moduleList, 'module'], [pluginList, 'plugin']] as const) {
      const handler = handlerOf(tree, 'onPrimaryAction');
      const branch = evalExpr(handler.params.value, { $args: [stuck] });
      expect(branch).toBe('update');
      const setState = findNode(handler, (n) => n.handler === 'setState' && n.params?.[`${prefix}ForceUpdate`] !== undefined);
      expect(setState, `${prefix}: 업데이트 창을 열 때 강제 값을 정하지 않습니다.`).toBeDefined();
      expect(evalExpr(setState!.params[`${prefix}ForceUpdate`], { $args: [stuck] })).toBe(true);
      expect(evalExpr(setState!.params[`${prefix}ForceUpdate`], { $args: [{ ...stuck, stuck_update: false }] })).toBe(false);
    }
  });
});

describe('멈춘 업데이트 카드 — 템플릿', () => {
  /**
   * @scenario defect=template_card_no_updating_badge
   * @effects template_card_shows_updating_badge
   */
  it('업데이트 중인 템플릿 카드에 「업데이트 중」을 보인다(플러그인 카드와 같다)', () => {
    for (const tree of [templateTabAdmin, templateTabUser]) {
      const card = cardFor(tree, running);
      expect(card.badges.map((b: Json) => b.label)).toContain('$t:admin.templates.status.updating');
      expect(card.toggle.disabled).toBe(true);
    }
  });

  /**
   * @scenario defect=template_card_no_updating_badge
   * @effects template_card_shows_install_uninstall_badges_and_hides_update_available_while_updating
   */
  it('설치·삭제 중인 템플릿 카드에 「설치 중」·「삭제 중」을 보이고, 업데이트 중에는 「업데이트 있음」을 숨긴다(플러그인 카드와 같다)', () => {
    for (const tree of [templateTabAdmin, templateTabUser]) {
      const installing = cardFor(tree, { identifier: 'g7test-x', status: 'installing', abilities: allowed });
      expect(installing.badges.map((b: Json) => b.label)).toContain('$t:admin.templates.status.installing');
      expect(installing.toggle.disabled).toBe(true);

      const uninstalling = cardFor(tree, { identifier: 'g7test-x', status: 'uninstalling', abilities: allowed });
      expect(uninstalling.badges.map((b: Json) => b.label)).toContain('$t:admin.templates.status.uninstalling');
      expect(uninstalling.toggle.disabled).toBe(true);

      // 업데이트가 도는 동안에는 「업데이트 있음」 이 「업데이트 중」 과 겹쳐 보이지 않는다
      const updating = cardFor(tree, { ...running, update_available: true });
      const labels = updating.badges.map((b: Json) => b.label);
      expect(labels).toContain('$t:admin.templates.status.updating');
      expect(labels).not.toContain('$t:admin.templates.update_available');

      // 대조군 — 활성 템플릿에 올릴 판이 있으면 「업데이트 있음」 은 그대로 보인다
      const idleWithUpdate = cardFor(tree, { identifier: 'g7test-x', status: 'active', update_available: true, abilities: allowed });
      expect(idleWithUpdate.badges.map((b: Json) => b.label)).toContain('$t:admin.templates.update_available');
    }
  });

  /**
   * @scenario defect=stuck_update_card_no_recovery_action
   * @effects stuck_template_card_shows_badge_recover_action_and_enabled_toggle
   */
  it('멈춘 업데이트면 「업데이트 멈춤」 배지, 「정리 후 다시 업데이트」 보조 단추, 켤 수 있는 스위치를 보이고 강제 업데이트로 연다', () => {
    for (const tree of [templateTabAdmin, templateTabUser]) {
      const card = cardFor(tree, stuck);
      const labels = card.badges.map((b: Json) => b.label);
      expect(labels).toContain('$t:admin.templates.status.stuck_update');
      expect(labels).not.toContain('$t:admin.templates.status.updating');
      expect(card.secondaryAction?.label).toBe('$t:admin.templates.actions.recover_update');
      expect(card.secondaryAction?.disabled).toBe(false);
      expect(card.toggle.disabled).toBe(false);

      const handler = handlerOf(tree, 'onSecondaryAction');
      const branch = evalExpr(handler.params.value, { $args: [stuck] });
      expect(branch).toBe('update');
      const setState = findNode(handler, (n) => n.handler === 'setState' && n.params?.templateForceUpdate !== undefined);
      expect(evalExpr(setState!.params.templateForceUpdate, { $args: [stuck] })).toBe(true);
      expect(evalExpr(setState!.params.templateForceUpdate, { $args: [{ ...stuck, stuck_update: false, update_available: true }] })).toBe(false);
    }
  });
});

describe('멈춘 업데이트 — 다시 업데이트 뒤 안내', () => {
  /**
   * @scenario defect=stuck_update_card_no_recovery_action
   * @effects recovered_update_success_tells_to_reactivate
   */
  it('업데이트 창은 멈춘 업데이트를 정리하고 성공했으면 「비활성으로 남았으니 다시 켜라」고 알린다', () => {
    for (const [tree, kind, sel] of [[moduleUpdateModal, 'modules', 'selectedModule'], [pluginUpdateModal, 'plugins', 'selectedPlugin'], [templateUpdateModal, 'templates', 'selectedTemplate']] as const) {
      const toast = findNode(tree, (n) => n.handler === 'toast' && n.params?.message === `$t:admin.${kind}.stuck_update_reactivate_hint`);
      expect(toast, `${kind}: 재활성화 안내 알림이 없습니다.`).toBeDefined();
      expect(evalExpr(toast!.if, { _global: { [sel]: stuck } })).toBe(true);
      expect(evalExpr(toast!.if, { _global: { [sel]: running } })).toBe(false);
    }
  });
});
