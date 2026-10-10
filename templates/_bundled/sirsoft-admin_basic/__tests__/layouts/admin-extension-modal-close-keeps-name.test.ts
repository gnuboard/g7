/**
 * @file admin-extension-modal-close-keeps-name.test.ts
 * @description 확장 관리 확인 창이 닫히는 동안 확장 이름 자리가 「{{name}}」 으로 바뀌지 않는지
 *
 * 설치·제거·업데이트·레이아웃 새로고침·비활성화 창은 문구에 고른 확장 이름(`_global.selected{Module,Plugin,Template}.name`)을
 * 넣는다. 성공 처리 맨 앞에서 그 선택을 비우고 목록·메뉴를 다시 받고 확장을 새로 읽은 **뒤에야** 창을 닫아, 그 몇 초 동안
 * 창 문구가 「{{name}} 플러그인을 설치하시겠습니까?」 로 바뀌어 보였다(7.1.0 O-G3-7 — 플러그인 설치 실측, 같은 창 구조의
 * 모듈·템플릿과 다른 확인 창도 같은 순서). 템플릿 활성화 창처럼 **창을 먼저 닫고 선택을 비운다**.
 *
 * @scenario-manifest tests/scenarios/extension-modal-close-keeps-name.yaml
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

type Json = Record<string, any>;

const SELECTION_KEYS = ['selectedModule', 'selectedPlugin', 'selectedTemplate'];

const MODALS = [
  'admin_module_list/_modal_install',
  'admin_module_list/_modal_uninstall',
  'admin_module_list/_modal_update',
  'admin_module_list/_modal_refresh_layouts',
  'admin_plugin_list/_modal_install',
  'admin_plugin_list/_modal_uninstall',
  'admin_plugin_list/_modal_update',
  'admin_plugin_list/_modal_refresh_layouts',
  'admin_template_list/_modal_install',
  'admin_template_list/_modal_uninstall',
  'admin_template_list/_modal_update',
  'admin_template_list/_modal_refresh_layouts',
  'admin_template_list/_modal_activate',
  'admin_template_list/_modal_deactivate',
  'admin_template_list/_modal_force_activate',
];

const load = (name: string): Json =>
  JSON.parse(readFileSync(resolve(__dirname, '../../layouts/partials', `${name}.json`), 'utf-8'));

/** 선택을 비우는 setState 인가 */
const clearsSelection = (a: Json): boolean =>
  a?.handler === 'setState' && SELECTION_KEYS.some((k) => k in (a.params ?? {}) && a.params[k] === null);

/** 트리 안의 「액션 목록」(onSuccess, sequence actions 등) 을 모두 모은다. */
function actionLists(tree: unknown): Json[][] {
  const out: Json[][] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) {
      if (n.some((x) => x && typeof x === 'object' && typeof (x as Json).handler === 'string')) out.push(n as Json[]);
      n.forEach(walk);
      return;
    }
    if (n && typeof n === 'object') Object.values(n as Json).forEach(walk);
  };
  walk(tree);
  return out;
}

describe('확장 관리 확인 창 — 닫히는 동안 이름 유지', () => {
  /**
   * @scenario modal=install_update_refresh_deactivate, list=on_success
   * @effects close_before_clearing_selection
   */
  it.each(MODALS)('%s: 선택을 비우는 목록에서는 창을 먼저 닫는다', (name) => {
    const lists = actionLists(load(name)).filter((l) => l.some(clearsSelection) && l.some((a) => a.handler === 'closeModal'));
    expect(lists.length, `${name}: 선택을 비우고 창을 닫는 목록이 없습니다.`).toBeGreaterThan(0);
    for (const list of lists) {
      const firstClear = list.findIndex(clearsSelection);
      const close = list.findIndex((a) => a.handler === 'closeModal');
      expect(close, `${name}: 창을 닫기 전에 선택을 비웁니다 — ${JSON.stringify(list.map((a) => a.handler))}`).toBeLessThan(firstClear);
    }
  });

  /**
   * 제거 창은 보존된 백업이 있으면 창을 닫지 않고 백업 안내를 보인다 — 선택을 비우는 조건과 닫는 조건이 같아야
   * 백업 안내 중에 이름이 지워지지 않는다.
   *
   * @scenario modal=uninstall, list=on_success
   * @effects uninstall_clear_and_close_share_condition
   */
  it.each(MODALS.filter((m) => m.endsWith('_uninstall')))('%s: 비우는 조건과 닫는 조건이 같다', (name) => {
    for (const list of actionLists(load(name)).filter((l) => l.some(clearsSelection) && l.some((a) => a.handler === 'closeModal'))) {
      const clear = list.find(clearsSelection)!;
      const close = list.find((a) => a.handler === 'closeModal')!;
      expect(clear.if ?? null).toBe(close.if ?? null);
    }
  });
});
