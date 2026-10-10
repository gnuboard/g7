/**
 * @file admin-extension-uninstall-reload-assets.test.ts
 * @description 모듈·플러그인 제거 창이 지운 확장의 프론트 자산(JS·CSS)을 걷어 내는지
 *
 * 제거 창은 성공하면 `reloadExtensions` 에 지운 확장을 넘겨 `window.G7Config.{module,plugin}Assets` 의 그 항목과
 * 그 확장이 실은 `<script>`·`<link>` 를 지운다. 종전에는 `{{result}}`(제거 응답)를 넘겼는데, 제거 응답의 data 는
 * `{ preserved_backups }` 뿐이라 식별자가 없다. 엔진은 「…Info with identifier is required」 경고만 남기고 자산 제거를
 * 건너뛰어, 켜진 채로 지운 확장의 JS·CSS 가 새로고침 전까지 화면에 남았다(7.1.0 종합 실측). 제거 창은 고른 확장의
 * 식별자를 넘긴다.
 *
 * 활성화·비활성화 경로(목록 스위치, 강제 비활성화 창)는 응답이 Resource 라 식별자가 있다 — 같은 결함군 대조로 함께 고정한다.
 *
 * 레이아웃 JSON 의 실제 액션을 엔진(`ActionDispatcher`)에 실제 응답 모양과 함께 넣어 돌린다.
 *
 * @scenario-manifest tests/scenarios/extension-uninstall-reload-assets.yaml
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { ActionDispatcher } from '@core/template-engine/ActionDispatcher';

type Json = Record<string, any>;

const ID = 'qa736-sample';
const OTHER = 'qa736-other';

const LAYOUTS = resolve(__dirname, '../../layouts');
const load = (rel: string): Json => JSON.parse(readFileSync(resolve(LAYOUTS, `${rel}.json`), 'utf-8'));

/** 트리에서 조건을 만족하는 노드를 모두 모은다. */
function collect(node: unknown, predicate: (n: Json) => boolean, out: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, predicate, out));
    return out;
  }
  if (node && typeof node === 'object') {
    if (predicate(node as Json)) out.push(node as Json);
    Object.values(node as Json).forEach((v) => collect(v, predicate, out));
  }
  return out;
}

/** 지정 주소로 끝나는 apiCall 노드 */
function apiCall(tree: Json, suffix: string): Json {
  const [call] = collect(tree, (n) => n.handler === 'apiCall' && typeof n.target === 'string' && n.target.endsWith(suffix));
  expect(call, `${suffix} 호출을 찾지 못했습니다`).toBeDefined();
  return call;
}

const KINDS = [
  {
    name: '플러그인',
    plural: 'plugins',
    infoKey: 'pluginInfo',
    selectedKey: 'selectedPlugin',
    assetsKey: 'pluginAssets',
    scriptId: (id: string) => `plugin-${id}`,
    cssId: (id: string) => `plugin-css-${id}`,
    uninstallModal: 'partials/admin_plugin_list/_modal_uninstall',
    forceDeactivateModal: 'partials/admin_plugin_list/_modal_force_deactivate',
    list: 'admin_plugin_list',
  },
  {
    name: '모듈',
    plural: 'modules',
    infoKey: 'moduleInfo',
    selectedKey: 'selectedModule',
    assetsKey: 'moduleAssets',
    scriptId: (id: string) => `module-${id}`,
    cssId: (id: string) => `module-css-${id}`,
    uninstallModal: 'partials/admin_module_list/_modal_uninstall',
    forceDeactivateModal: 'partials/admin_module_list/_modal_force_deactivate',
    list: 'admin_module_list',
  },
] as const;

type Kind = (typeof KINDS)[number];

/** 그 확장이 켜져 프론트 자산이 실린 상태 — 다른 확장 하나도 함께 실어 남는지 본다. */
function seedAssets(kind: Kind): void {
  const assets = (id: string) => ({ js: `/api/${kind.plural}/assets/${id}/dist/js/app.iife.js`, css: `/api/${kind.plural}/assets/${id}/dist/css/app.css` });
  (window as any).G7Config = { [kind.assetsKey]: { [ID]: assets(ID), [OTHER]: assets(OTHER) } };
  for (const id of [ID, OTHER]) {
    const script = document.createElement('script');
    script.id = kind.scriptId(id);
    document.head.appendChild(script);
    const link = document.createElement('link');
    link.id = kind.cssId(id);
    link.rel = 'stylesheet';
    document.head.appendChild(link);
  }
}

/** 지운 확장의 자산만 빠지고 다른 확장은 남았는지 */
function expectRemoved(kind: Kind): void {
  const map = (window as any).G7Config[kind.assetsKey];
  expect(Object.keys(map), `${kind.assetsKey} 에 지운 확장이 남았습니다`).toEqual([OTHER]);
  expect(document.getElementById(kind.scriptId(ID)), '지운 확장의 script 가 남았습니다').toBeNull();
  expect(document.getElementById(kind.cssId(ID)), '지운 확장의 link 가 남았습니다').toBeNull();
  expect(document.getElementById(kind.scriptId(OTHER))).not.toBeNull();
  expect(document.getElementById(kind.cssId(OTHER))).not.toBeNull();
}

/** 서버 Resource 모양(식별자·이름·상태) */
const resource = (status: string) => ({ identifier: ID, vendor: 'qa736', name: 'QA736 Sample', version: '1.0.0', status });

describe('확장 제거·비활성화 — 프론트 자산 걷어 내기', () => {
  let dispatcher: ActionDispatcher;
  let warn: ReturnType<typeof vi.spyOn>;

  beforeEach(() => {
    dispatcher = new ActionDispatcher({ navigate: vi.fn() });
    (window as any).__templateApp = { reloadExtensionState: vi.fn().mockResolvedValue(undefined) };
    warn = vi.spyOn(console, 'warn').mockImplementation(() => {});
  });

  afterEach(() => {
    delete (window as any).__templateApp;
    delete (window as any).G7Config;
    document.head.querySelectorAll('script[id], link[id]').forEach((el) => el.remove());
    warn.mockRestore();
  });

  /** 성공 목록의 reloadExtensions 를 그 시점의 데이터 컨텍스트로 엔진에 넣는다. */
  async function runReload(node: Json, data: Json): Promise<void> {
    await dispatcher.dispatchAction(node as any, { data } as any);
  }

  /**
   * @scenario path=uninstall_no_backups
   * @effects removed_extension_assets_unloaded
   */
  it.each(KINDS)('$name 제거 창: 제거 응답(보존 백업 없음)으로 그 확장의 자산을 걷어 낸다', async (kind) => {
    seedAssets(kind);
    const success: Json[] = apiCall(load(kind.uninstallModal), '/uninstall').onSuccess;
    const reload = success.find((a) => a.handler === 'reloadExtensions');
    expect(reload, '제거 성공 목록에 reloadExtensions 가 없습니다').toBeDefined();

    const response = { success: true, message: '삭제되었습니다.', data: { preserved_backups: [] } };
    await runReload(reload!, {
      _global: { [kind.selectedKey]: resource('active') },
      result: response,
      response,
    });

    expectRemoved(kind);
  });

  /**
   * @scenario path=uninstall_with_backups
   * @effects removed_extension_assets_unloaded
   */
  it.each(KINDS)('$name 제거 창: 보존 백업이 있어 창이 남는 응답에서도 자산을 걷어 낸다', async (kind) => {
    seedAssets(kind);
    const success: Json[] = apiCall(load(kind.uninstallModal), '/uninstall').onSuccess;
    const reload = success.find((a) => a.handler === 'reloadExtensions')!;

    const response = { success: true, message: '삭제되었습니다.', data: { preserved_backups: [{ archive: 'storage/app/extension_backups/x.zip' }] } };
    await runReload(reload, {
      _global: { [kind.selectedKey]: resource('active') },
      result: response,
      response,
    });

    expectRemoved(kind);
  });

  /**
   * 식별자를 고른 확장에서 읽으므로, 같은 성공 목록에서 그 선택을 비우는 setState 보다 앞서야 한다.
   *
   * @scenario path=uninstall_no_backups
   * @effects reload_runs_before_selection_cleared
   */
  it.each(KINDS)('$name 제거 창: 자산 걷어 내기가 고른 확장을 비우기 전에 실행된다', (kind) => {
    const success: Json[] = apiCall(load(kind.uninstallModal), '/uninstall').onSuccess;
    const reloadIndex = success.findIndex((a) => a.handler === 'reloadExtensions');
    const clearIndex = success.findIndex((a) => a.handler === 'setState' && a.params?.[kind.selectedKey] === null);

    expect(reloadIndex).toBeGreaterThanOrEqual(0);
    expect(clearIndex).toBeGreaterThanOrEqual(0);
    expect(reloadIndex, `${kind.selectedKey} 를 비운 뒤에 식별자를 읽습니다`).toBeLessThan(clearIndex);
  });

  /**
   * @scenario path=force_deactivate_modal
   * @effects deactivated_extension_assets_unloaded
   */
  it.each(KINDS)('$name 강제 비활성화 창: 비활성 응답(Resource)으로 자산을 걷어 낸다', async (kind) => {
    seedAssets(kind);
    const success: Json[] = apiCall(load(kind.forceDeactivateModal), '/deactivate').onSuccess;
    const [reload] = collect(success, (n) => n.handler === 'reloadExtensions');
    expect(reload).toBeDefined();

    const response = { success: true, message: '비활성화되었습니다.', data: resource('inactive') };
    await runReload(reload, { _global: {}, result: response, response });

    expectRemoved(kind);
  });

  /**
   * @scenario path=list_toggle_off
   * @effects deactivated_extension_assets_unloaded
   */
  it.each(KINDS)('$name 목록 스위치 끄기: 비활성 응답(Resource)으로 자산을 걷어 낸다', async (kind) => {
    seedAssets(kind);
    const [reload] = collect(load(kind.list), (n) => n.handler === 'reloadExtensions' && typeof n.params?.action === 'string' && n.params.action.includes('$args'));
    expect(reload, '목록 스위치의 reloadExtensions 를 찾지 못했습니다').toBeDefined();

    const response = { success: true, message: '비활성화되었습니다.', data: resource('inactive') };
    await runReload(reload, { _global: {}, $args: [false, resource('active')], result: response, response });

    expectRemoved(kind);
  });
});
