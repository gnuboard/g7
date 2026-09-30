/**
 * @file admin-extension-reactivate-language-packs.test.tsx
 * @description 확장 재활성화 뒤 함께 꺼졌던 언어팩을 되살리는 창의 배선 (#703)
 *
 * 확장을 끄면 그 확장에 딸린 언어팩도 함께 꺼지고, 다시 켜면 서버가 활성 응답에
 * `pending_language_packs` 를 실어 준다(그리고 그 목록을 서버에서 지운다). 창 세 벌
 * (`_modal_reactivate_language_packs`)은 만들어져 있었지만 어느 목록 화면도 싣지 않았고,
 * 활성 성공 갈래가 그 목록을 담아 창을 여는 곳도 없었다 — 되살릴 기회가 매번 버려졌다.
 *
 * 또한 활성 응답이 `data.module`/`data.plugin` 으로 한 겹 감싸진 뒤, 확장 번들 재적재에
 * 응답 전체를 넘겨 식별자를 찾지 못하던 것도 함께 고정한다.
 */

import { describe, it, expect } from 'vitest';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import templateList from '../../layouts/admin_template_list.json';
import moduleForceActivate from '../../layouts/partials/admin_module_list/_modal_force_activate.json';
import pluginForceActivate from '../../layouts/partials/admin_plugin_list/_modal_force_activate.json';
import templateActivate from '../../layouts/partials/admin_template_list/_modal_activate.json';
import templateForceActivate from '../../layouts/partials/admin_template_list/_modal_force_activate.json';
import templateReactivateModal from '../../layouts/partials/admin_template_list/_modal_reactivate_language_packs.json';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';

type Json = Record<string, any>;

/** 트리 전체에서 조건에 맞는 노드를 모은다. */
function collect(node: unknown, match: (n: Json) => boolean): Json[] {
  const out: Json[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) {
      n.forEach(walk);
      return;
    }
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (match(obj)) out.push(obj);
      Object.values(obj).forEach(walk);
    }
  };
  walk(node);
  return out;
}

/** `{{ ... }}` 바인딩 식을 문맥으로 평가한다. */
function evaluate(binding: string, ctx: Record<string, unknown>): any {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(String(binding).trim());
  expect(m, `바인딩 식이 아닙니다: ${binding}`).not.toBeNull();
  const keys = Object.keys(ctx);
  // eslint-disable-next-line no-new-func
  const fn = new Function(...keys, `return (${m![1]});`);
  return fn(...keys.map((k) => ctx[k]));
}

const withPerm = { data: { permissions: [{ identifier: 'core.language_packs.manage' }] } };
const withoutPerm = { data: { permissions: [{ identifier: 'core.modules.activate' }] } };
const pending = [{ id: 7, identifier: 'ko-pack', locale: 'ja', locale_native_name: '日本語' }];

/** 활성 요청 노드의 성공 갈래에서 대기 목록 저장과 창 열기를 검사한다. */
function assertWiring(tree: unknown, target: string, modalId: string, args: unknown[] = [true]) {
  const calls = collect(tree, (n) => n.handler === 'apiCall' && String(n.target).includes(target));
  expect(calls.length, `${target} 요청이 없습니다.`).toBeGreaterThan(0);
  const call = calls[0];

  const setter = collect(call.onSuccess, (n) => n.handler === 'setState' && n.params?.reactivateLanguagePacksData !== undefined)[0];
  expect(setter, `${target}: 대기 목록 저장이 없습니다.`).toBeDefined();
  expect(evaluate(setter.params.reactivateLanguagePacksData, { response: { data: { pending_language_packs: pending } } })).toEqual(pending);

  const opener = collect(call.onSuccess, (n) => n.handler === 'openModal' && n.target === modalId)[0];
  expect(opener, `${target}: ${modalId} 열기가 없습니다.`).toBeDefined();
  const ctx = (resp: unknown, user: unknown) => ({ response: resp, current_user: user, $args: args, _global: {} });
  expect(evaluate(opener.if, ctx({ data: { pending_language_packs: pending } }, withPerm))).toBe(true);
  // 빈 목록이면 열지 않는다
  expect(evaluate(opener.if, ctx({ data: { pending_language_packs: [] } }, withPerm))).toBe(false);
  // 언어팩 관리 권한이 없으면 열지 않는다 — 창의 일괄 활성 요청을 서버가 403 으로 거절한다
  expect(evaluate(opener.if, ctx({ data: { pending_language_packs: pending } }, withoutPerm))).toBe(false);
}

describe('확장 재활성화 — 언어팩 되살리기 창 배선', () => {
  /**
   * @scenario defect=extension_reactivate_language_packs
   * @effects reactivate_language_pack_modal_is_reachable
   */
  it('세 목록 화면이 창을 싣는다', () => {
    for (const [tree, src] of [
      [moduleList, 'partials/admin_module_list/_modal_reactivate_language_packs.g7.html'],
      [pluginList, 'partials/admin_plugin_list/_modal_reactivate_language_packs.g7.html'],
      [templateList, 'partials/admin_template_list/_modal_reactivate_language_packs.g7.html'],
    ] as const) {
      const serialized = JSON.stringify(tree);
      expect(serialized, src).toContain(src.replace('.g7.html', '.json'));
    }
  });

  /**
   * @scenario defect=extension_reactivate_language_packs
   * @effects reactivate_language_pack_modal_is_reachable
   */
  it('모듈·플러그인 토글과 강제 활성, 템플릿 활성·강제 활성이 대기 목록으로 창을 연다', () => {
    assertWiring(moduleList, '/api/admin/modules/activate', 'module_reactivate_language_packs_modal');
    assertWiring(pluginList, '/api/admin/plugins/activate', 'plugin_reactivate_language_packs_modal');
    assertWiring(moduleForceActivate, '/api/admin/modules/activate', 'module_reactivate_language_packs_modal');
    assertWiring(pluginForceActivate, '/api/admin/plugins/activate', 'plugin_reactivate_language_packs_modal');
    assertWiring(templateActivate, '/api/admin/templates/activate', 'template_reactivate_language_packs_modal');
    assertWiring(templateForceActivate, '/api/admin/templates/activate', 'template_reactivate_language_packs_modal');
  });

  /**
   * 토글로 끌 때는 창을 열지 않는다(끄는 응답에는 대기 목록이 없다).
   *
   * @scenario defect=extension_reactivate_language_packs
   * @effects reactivate_language_pack_modal_is_reachable
   */
  it('토글의 끄기 방향에서는 창을 열지 않는다', () => {
    for (const [tree, modalId] of [
      [moduleList, 'module_reactivate_language_packs_modal'],
      [pluginList, 'plugin_reactivate_language_packs_modal'],
    ] as const) {
      const opener = collect(tree, (n) => n.handler === 'openModal' && n.target === modalId)[0];
      expect(evaluate(opener.if, { response: { data: { pending_language_packs: pending } }, current_user: withPerm, $args: [false], _global: {} })).toBe(false);
    }
  });

  /**
   * 관리자 템플릿을 켜면 새 화면을 그리려고 페이지를 새로 고친다 — 창을 띄운 경우에는 새로 고침을
   * 창이 닫힐 때로 미뤄야 창이 곧바로 사라지지 않는다.
   *
   * @scenario defect=extension_reactivate_language_packs
   * @effects reactivate_language_pack_modal_is_reachable
   */
  it('관리자 템플릿 활성에서 창을 띄우면 새로 고침은 창이 닫힐 때 한다', () => {
    const serialized = JSON.stringify(templateReactivateModal);
    expect(serialized).toContain('reactivateLanguagePacksRefreshAfter');
    const refreshes = collect(templateReactivateModal, (n) => n.handler === 'refresh');
    expect(refreshes.length).toBeGreaterThanOrEqual(2);
  });

  /**
   * @scenario defect=extension_reactivate_language_packs
   * @effects reactivate_language_pack_modal_is_reachable
   */
  it('창 문구 키가 ko·en 에 있다', () => {
    for (const dict of [adminKo, adminEn] as unknown as Json[]) {
      for (const ns of ['modules', 'plugins', 'templates']) {
        for (const key of ['title', 'message', 'success', 'failed']) {
          expect(dict[ns].modals[`reactivate_language_packs_${key}`], `${ns}.${key}`).toBeTruthy();
        }
      }
    }
  });

  /**
   * @scenario defect=extension_reactivate_language_packs
   * @effects extension_bundle_reload_reads_wrapped_activate_response
   */
  it('활성 응답(data.module / data.plugin 래핑)에서도 확장 번들 재적재가 식별자를 찾는다', () => {
    for (const [tree, param, wrap] of [
      [moduleList, 'moduleInfo', 'module'],
      [moduleForceActivate, 'moduleInfo', 'module'],
      [pluginList, 'pluginInfo', 'plugin'],
      [pluginForceActivate, 'pluginInfo', 'plugin'],
    ] as const) {
      const reload = collect(tree, (n) => n.handler === 'reloadExtensions' && n.params?.[param])[0];
      expect(reload, param).toBeDefined();
      const activateResult = { success: true, data: { [wrap]: { identifier: 'vendor-x' }, pending_language_packs: [] } };
      const resolved = evaluate(reload.params[param], { result: activateResult });
      expect((resolved.data ?? resolved).identifier).toBe('vendor-x');
      // 끄기 응답(래핑 없음)도 그대로 식별자를 찾는다
      const deactivateResult = { success: true, data: { identifier: 'vendor-x' } };
      const resolved2 = evaluate(reload.params[param], { result: deactivateResult });
      expect((resolved2.data ?? resolved2).identifier).toBe('vendor-x');
    }
  });
});
