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
 *    7.2.0(#888)부터 업데이트 창의 레이아웃 판정은 미리보기 API 한 곳이 한다 — 여는 쪽은 그 호출을 더 하지 않고,
 *    결과를 읽는 곳도 없다(읽지 않는 상태를 쓰는 죽은 호출이었다).
 */

import { describe, it, expect } from 'vitest';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
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
  /**
   * @scenario case=list_check_updates
   * @effects extension_list_reads_api_contract
   */
  it.each(LIST_LAYOUTS)('%s: check-updates 는 updated_count 를 읽어야 함', (_name, layout) => {
    const json = JSON.stringify(layout);
    expect(json).toContain('response.data?.updated_count');
    // 잘못된 필드명이 남아있으면 토스트가 영구히 "최신 상태" 로 고정된다
    expect(json).not.toContain('response.data?.update_count');
    expect(json).not.toContain('response.data.update_count');
  });

  /**
   * @scenario case=list_pagination
   * @effects extension_list_reads_api_contract
   */
  it.each(PAGINATION_LAYOUTS)('%s: 페이지네이션은 data.pagination.* 경로여야 함', (_name, layout, ds) => {
    const json = JSON.stringify(layout);
    for (const key of ['total', 'current_page', 'last_page', 'per_page']) {
      expect(json).not.toContain(`${ds}?.data?.${key}`);
    }
    expect(json).toContain(`${ds}?.data?.pagination?.total`);
  });

  /**
   * @scenario case=check_modified_on_error
   * @effects extension_list_reads_api_contract
   */
  it.each(CHECK_SOURCES)('%s: 업데이트 창을 열 때 check-modified-layouts 를 부르지 않고 미리보기만 부른다 (#888)', (_name, layout, kind) => {
    const json = JSON.stringify(layout);
    // 판정은 미리보기(…/update/preview)가 한다 — 옛 확인 호출과 그 결과 상태는 남아 있지 않다
    expect(json).not.toContain('/check-modified-layouts');
    expect(json).not.toContain(`hasModified${kind}Layouts`);
    expect(json).not.toContain(`modified${kind}LayoutsCheckFailed`);
    expect(json).toContain('/update/preview');
    expect(json).toContain('"fetchLayoutPreview"');
  });

  // 7.2.0(#888)부터 업데이트 창의 레이아웃 판정은 공통 미리보기 조각이 그린다 — 「수정 없음」 단언은 미리보기가
  // 성공(ready)했을 때만 보이고, 실패(error)하면 오류 + 다시 시도가 보이며 실행 버튼이 잠긴다.
  /**
   * @scenario case=update_modal_preview_gate
   * @effects update_modal_preview_blocks_no_change_claim
   */
  it.each(MODALS)('%s 업데이트 모달: 확인 실패 분기가 있고 "수정 없음" 단언을 가로막아야 함', (_name, modal) => {
    const json = JSON.stringify(modal);
    expect(json).toContain('"partial":"partials/_shared/_layout_sync_preview.json"');
    expect(json).toContain("_global.layoutPreviewState !== 'ready'");

    const preview = JSON.stringify(JSON.parse(readFileSync(resolve(__dirname, '../../layouts/partials/_shared/_layout_sync_preview.json'), 'utf-8')));
    expect(preview).toContain("{{_global.layoutPreviewState === 'error'}}");
    // 「바뀌는 화면이 없습니다」는 미리보기 결과(ready) 묶음 안에만 있다
    const ready = preview.indexOf("{{_global.layoutPreviewState === 'ready'}}");
    expect(ready).toBeGreaterThan(-1);
    expect(preview.indexOf('$t:admin.layout_sync_preview.empty')).toBeGreaterThan(ready);
  });
});
