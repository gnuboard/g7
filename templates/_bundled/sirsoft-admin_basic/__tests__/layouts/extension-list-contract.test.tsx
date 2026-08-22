/**
 * @file extension-list-contract.test.tsx
 * @description 확장(모듈/플러그인/템플릿) 목록·업데이트 모달의 API 계약 회귀 테스트
 *
 * Chrome MCP 정밀 점검에서 실측된 결함 3건을 레이아웃 JSON 수준에서 고정한다.
 *
 * 1) check-updates 응답 필드는 `updated_count` 인데 레이아웃이 `update_count` 를 읽어
 *    업데이트가 감지돼도 항상 "모든 X가 최신 상태입니다" 토스트가 떴다.
 * 2) 목록 페이지네이션이 `data.total` / `data.current_page` 를 읽는데 실제 응답은
 *    `data.pagination.*` 이라 "총 0개 중 1-0개 표시" 로 고정 표기됐다.
 * 3) check-modified-layouts 호출에 onError 가 없어, 네트워크 실패 시 모달이
 *    "수정된 레이아웃이 없습니다" 라고 단언 → '모두 교체' 진행 시 수정 소실.
 */

import { describe, it, expect } from 'vitest';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import templateList from '../../layouts/admin_template_list.json';
import tabAdmin from '../../layouts/partials/admin_template_list/_tab_admin.json';
import tabUser from '../../layouts/partials/admin_template_list/_tab_user.json';
import moduleModal from '../../layouts/partials/admin_module_list/_modal_update.json';
import pluginModal from '../../layouts/partials/admin_plugin_list/_modal_update.json';
import templateModal from '../../layouts/partials/admin_template_list/_modal_update.json';

const LIST_LAYOUTS: Array<[string, unknown]> = [
  ['admin_module_list', moduleList],
  ['admin_plugin_list', pluginList],
  ['admin_template_list', templateList],
];

const PAGINATION_LAYOUTS: Array<[string, unknown, string]> = [
  ['admin_module_list', moduleList, 'modules'],
  ['admin_plugin_list', pluginList, 'plugins'],
  ['_tab_admin', tabAdmin, 'templates'],
  ['_tab_user', tabUser, 'templates'],
];

const MODALS: Array<[string, unknown, string]> = [
  ['module', moduleModal, 'Module'],
  ['plugin', pluginModal, 'Plugin'],
  ['template', templateModal, 'Template'],
];

const CHECK_SOURCES: Array<[string, unknown, string]> = [
  ['admin_module_list', moduleList, 'Module'],
  ['admin_plugin_list', pluginList, 'Plugin'],
  ['_tab_admin', tabAdmin, 'Template'],
  ['_tab_user', tabUser, 'Template'],
];

describe('확장 목록 레이아웃 — API 계약', () => {
  it.each(LIST_LAYOUTS)('%s: check-updates 는 updated_count 를 읽어야 함', (_name, layout) => {
    const json = JSON.stringify(layout);
    expect(json).toContain('response.data?.updated_count');
    // 잘못된 필드명이 남아있으면 토스트가 영구히 "최신 상태" 로 고정된다
    expect(json).not.toContain('response.data?.update_count');
    expect(json).not.toContain('response.data.update_count');
  });

  it.each(PAGINATION_LAYOUTS)('%s: 페이지네이션은 data.pagination.* 경로여야 함', (_name, layout, ds) => {
    const json = JSON.stringify(layout);
    for (const key of ['total', 'current_page', 'last_page', 'per_page']) {
      expect(json).not.toContain(`${ds}?.data?.${key}`);
    }
    expect(json).toContain(`${ds}?.data?.pagination?.total`);
  });

  it.each(CHECK_SOURCES)('%s: check-modified-layouts 호출에 onError 가 있어야 함', (_name, layout, kind) => {
    const found: any[] = [];
    const walk = (node: any) => {
      if (Array.isArray(node)) { node.forEach(walk); return; }
      if (!node || typeof node !== 'object') return;
      if (node.handler === 'apiCall' && typeof node.target === 'string'
        && node.target.includes('/check-modified-layouts')) {
        found.push(node);
      }
      Object.values(node).forEach(walk);
    };
    walk(layout);

    expect(found.length).toBeGreaterThan(0);
    for (const call of found) {
      expect(Array.isArray(call.onError)).toBe(true);
      expect(JSON.stringify(call.onError)).toContain(`modified${kind}LayoutsCheckFailed`);
      // 성공 시에는 플래그를 반드시 해제해야 재조회가 경고에 갇히지 않는다
      expect(JSON.stringify(call.onSuccess)).toContain(`modified${kind}LayoutsCheckFailed`);
    }
  });

  it.each(MODALS)('%s 업데이트 모달: 확인 실패 분기가 있고 "수정 없음" 단언을 가로막아야 함', (_name, modal, kind) => {
    const json = JSON.stringify(modal);
    expect(json).toContain('modified_layouts_check_failed');
    expect(json).toContain(`_global.modified${kind}LayoutsCheckFailed`);
    // "수정된 레이아웃이 없습니다" 는 확인이 성공했을 때만 표시되어야 한다
    expect(json).toContain(
      `{{!_global.hasModified${kind}Layouts && !_global.modified${kind}LayoutsCheckFailed}}`
    );
  });
});
