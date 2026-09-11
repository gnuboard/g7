/**
 * @file admin-template-layout-edit-markup-default.test.tsx
 * @description 코드 편집기 HTML(.g7.html) 기본화 — 레이아웃 JSON 구조 검증 (#476)
 *
 * 검증 항목:
 * - 서브탭 기본값이 HTML(`markup`)이고 JSON 탭은 읽기 전용 편집기다
 * - HTML 편집기는 `language=html` 이며 서버 컴파일 마커(`markers`)를 받는다
 * - 타이핑 후 1초 유휴에 `markup-compile` 을 부르는 api-call 이 있고(debounce 1000), 저장·미리보기는 `format=markup` 으로 HTML 버퍼를 보낸다
 * - 본문 조회 데이터소스가 `?with_markup=1` 로 역변환본을 받아 `editorMarkup` 에 올린다
 * - 파일 목록·선택 파일 헤더 표기가 `.g7.html` 이다
 * - 내려받기·복사 핸들러 버튼이 있다
 * - 판본 복원이 HTML 버퍼까지 갈아 끼운다
 *
 * @effects code_editor_markup_tab_is_default, code_editor_json_tab_is_read_only, code_editor_saves_markup_format
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

const LAYOUTS_BASE = path.resolve(__dirname, '../../layouts');

function loadLayout(relativePath: string): any {
  return JSON.parse(fs.readFileSync(path.resolve(LAYOUTS_BASE, relativePath), 'utf-8'));
}

const layoutJson = loadLayout('admin_template_layout_edit.json');
const versionModalJson = loadLayout('partials/admin_template_layout_edit/_modal_version_history.json');
const extensionVersionModalJson = loadLayout('partials/admin_template_layout_edit/_modal_extension_version_history.json');

/** 노드 트리 전체(children/slots/component_layout)를 깊이 우선으로 순회한다 */
function walk(node: any, visit: (n: any) => void): void {
  if (!node || typeof node !== 'object') return;
  visit(node);
  for (const child of node.children ?? []) walk(child, visit);
  for (const slot of Object.values(node.slots ?? {})) if (Array.isArray(slot)) for (const c of slot) walk(c, visit);
  if (node.component_layout && typeof node.component_layout === 'object') {
    for (const slot of Object.values(node.component_layout)) if (Array.isArray(slot)) for (const c of slot) walk(c, visit);
  }
}

function findAll(root: any, predicate: (n: any) => boolean): any[] {
  const out: any[] = [];
  walk(root, (n) => { if (predicate(n)) out.push(n); });
  return out;
}

/** 액션 트리(sequence/conditions/onSuccess/onError)를 평탄화한다 */
function flattenActions(action: any): any[] {
  if (!action) return [];
  if (Array.isArray(action)) return action.flatMap(flattenActions);
  const out = [action];
  out.push(...flattenActions(action.actions));
  out.push(...flattenActions(action.onSuccess));
  out.push(...flattenActions(action.onError));
  out.push(...flattenActions(action.then));
  for (const c of action.conditions ?? []) out.push(...flattenActions(c.then));
  return out;
}

function actionsOf(node: any): any[] {
  return flattenActions(node.actions ?? []).concat(flattenActions(node.onComponentEvent ?? []));
}

const stringify = (v: any) => JSON.stringify(v ?? '');

/** apiCall 의 target 은 액션 레벨 키다(ActionCompiler 허용 목록) — params 에 있는 옛 형태도 함께 본다 */
const targetOf = (a: any) => a?.target ?? a?.params?.target;

describe('코드 편집기 — HTML 기본 · JSON 읽기 전용 (#476)', () => {
  const tabs = findAll(layoutJson, (n) => n.name === 'TabNavigation' && n.id === 'editor_format_tabs');
  const editors = findAll(layoutJson, (n) => n.name === 'CodeEditor');
  const markupEditor = editors.find((e) => e.props?.language === 'html');
  const jsonEditor = editors.find((e) => e.props?.language === 'json');

  /**
   * @effects code_editor_markup_tab_is_default
   */
  it('서브탭이 있고 기본 활성 탭은 markup 이다', () => {
    expect(tabs).toHaveLength(1);
    expect(stringify(tabs[0].props?.activeTabId)).toContain("_global.editorFormat ?? 'markup'");
    expect(stringify(tabs[0].props?.tabs)).toContain("id: 'markup'");
    expect(stringify(tabs[0].props?.tabs)).toContain("id: 'json'");
  });

  /**
   * @effects code_editor_markup_tab_is_default, code_editor_marks_compile_errors_in_monaco_with_g7_markup_owner
   */
  it('HTML 편집기는 language=html · HTML 버퍼 · 서버 컴파일 마커를 받는다', () => {
    expect(markupEditor).toBeDefined();
    expect(stringify(markupEditor.props.value)).toContain('_global.editorMarkup');
    expect(stringify(markupEditor.props.markers)).toContain('markupCompileError');
    expect(stringify(markupEditor.props.markers)).toContain('markupWarnings');
  });

  /**
   * @effects code_editor_json_tab_is_read_only
   */
  it('JSON 편집기는 읽기 전용이고 JSON 뷰 버퍼를 보여준다', () => {
    expect(jsonEditor).toBeDefined();
    expect(jsonEditor.props.readOnly).toBe(true);
    expect(stringify(jsonEditor.props.value)).toContain('_global.editorContent');
  });

  /**
   * @effects code_editor_idle_compile_updates_json_view
   */
  it('타이핑 후 1초 유휴에 markup-compile 을 부르고, 버퍼 갱신(300ms)과 별도 블록이다', () => {
    const actions = actionsOf(markupEditor);
    const compile = actions.find((a) => a.handler === 'apiCall' && stringify(targetOf(a)).includes('markup-compile'));
    const buffer = actions.find((a) => a.handler === 'setState' && a.params?.editorMarkup !== undefined);

    expect(compile, 'markup-compile api-call').toBeDefined();
    expect(compile.debounce).toBe(1000);
    expect(compile.params.method).toBe('POST');
    expect(stringify(compile.params.body?.markup)).toContain('$event.target.value');
    expect(buffer.debounce).toBe(300);

    const success = flattenActions(compile.onSuccess);
    expect(success.some((a) => a.handler === 'setState' && stringify(a.params?.editorContent).includes('response.data.content'))).toBe(true);
    const fail = flattenActions(compile.onError);
    expect(fail.some((a) => a.handler === 'setState' && a.params?.jsonViewStale === true)).toBe(true);
  });

  /**
   * @effects code_editor_saves_markup_format, code_editor_markup_save_persists
   */
  it('저장·미리보기는 HTML 버퍼를 format=markup 으로 보낸다', () => {
    const calls = findAll(layoutJson, (n) => Array.isArray(n.actions))
      .flatMap(actionsOf)
      .filter((a) => a.handler === 'apiCall' && (a.params?.method === 'PUT' || stringify(targetOf(a)).includes('/preview')));

    expect(calls.length).toBeGreaterThanOrEqual(4);
    for (const call of calls) {
      expect(call.params.body?.format, stringify(targetOf(call))).toBe('markup');
      expect(stringify(call.params.body?.content)).toContain('_global.editorMarkup');
    }

    const saveFails = calls.filter((c) => c.params?.method === 'PUT').flatMap((c) => flattenActions(c.onError));
    expect(saveFails.some((a) => stringify(a.params?.markupCompileError).includes('compile_error'))).toBe(true);
  });

  /**
   * @effects code_editor_opens_with_decompiled_markup_buffer
   */
  it('본문 조회는 with_markup=1 로 역변환본을 받아 editorMarkup 에 올린다', () => {
    for (const id of ['current_layout', 'current_extension']) {
      const source = (layoutJson.data_sources ?? []).find((d: any) => d.id === id);
      expect(source, id).toBeDefined();
      expect(source.endpoint).toContain('with_markup=1');
      const keys = (source.initGlobal ?? []).map((m: any) => m.key);
      expect(keys).toEqual(expect.arrayContaining(['editorContent', 'editorMarkup', 'markupWarnings', 'markupCompileError']));
    }
  });

  /**
   * @effects code_editor_shows_g7_html_labels_and_fragment_key
   */
  it('파일 목록과 선택 파일 헤더는 .g7.html 로 표기한다', () => {
    const text = JSON.stringify(layoutJson);
    expect(text).toContain('{{file.name}}.g7.html');
    expect(text).not.toContain('{{file.name}}.json');
    expect(text).toContain("|| 'main'}}.g7.html");
  });

  /**
   * @effects code_editor_downloads_and_copies_the_markup_buffer
   */
  it('내려받기·복사 핸들러 버튼이 있고 HTML 버퍼를 넘긴다', () => {
    const actions = findAll(layoutJson, (n) => Array.isArray(n.actions)).flatMap(actionsOf);
    const download = actions.find((a) => a.handler === 'downloadMarkupFile');
    const copy = actions.find((a) => a.handler === 'copyMarkupToClipboard');

    expect(download).toBeDefined();
    expect(stringify(download.params?.filename)).toContain('.g7.html');
    expect(stringify(download.params?.content)).toContain('_global.editorMarkup');
    expect(copy).toBeDefined();
    expect(stringify(copy.params?.content)).toContain('_global.editorMarkup');
  });

  /**
   * @effects code_editor_version_restore_relies_on_refetch_not_stale_reread
   */
  it('판본 복원은 버퍼를 직접 재독하지 않고 재조회(init-global)에 맡긴다', () => {
    for (const [modal, source] of [[versionModalJson, 'current_layout'], [extensionVersionModalJson, 'current_extension']] as const) {
      const restores = findAll(modal, (n) => Array.isArray(n.actions)).flatMap(actionsOf)
        .filter((a) => a.handler === 'apiCall' && stringify(targetOf(a)).includes('/restore'));
      expect(restores.length, source).toBeGreaterThan(0);
      const success = flattenActions(restores[0].onSuccess);
      // onSuccess 안에서 `current_layout?.data` 를 재독하면 재조회 전 값이라 되돌리기 전 본문을 도로 덮어쓴다 (실측 2026-09-11)
      expect(success.some((a) => a.handler === 'setState' && stringify(a.params).includes(`${source}?.data?.content`))).toBe(false);
      expect(success.some((a) => a.handler === 'refetchDataSource' && a.params?.dataSourceId === source)).toBe(true);
      expect(success.some((a) => a.handler === 'setState' && a.params?.jsonViewStale === false)).toBe(true);
    }
  });
});

describe("[case:init-global-reruns-on-refetch-and-resets-selection] 목록 재조회가 선택을 되돌리지 않는다", () => {
  /**
   * @effects code_editor_file_selection_is_not_reset_by_init_global
   */
  it('layout_files 의 init-global 은 목록만 올리고 선택값을 심지 않는다', () => {
    const source = (layoutJson.data_sources ?? []).find((d: any) => d.id === 'layout_files');

    expect(source).toBeDefined();
    // init-global 은 재조회마다 다시 실행된다 — 선택값을 심으면 저장 뒤 목록 재조회가 첫 파일로 되돌린다.
    const keys = (source.initGlobal ?? []).map((m: any) => m.key).filter(Boolean);
    expect(keys).not.toContain('selectedLayoutName');
    expect(stringify(source.initGlobal)).toContain('layoutFilesList');
  });

  /**
   * @effects code_editor_file_selection_is_not_reset_by_init_global
   */
  it('선택을 읽는 자리는 모두 목록 첫 항목 폴백을 갖는다 (진입 기본값은 폴백이 정한다)', () => {
    const sources = layoutJson.data_sources ?? [];
    const fallback = "_global.selectedLayoutName || _global.layoutFilesList?.[0]?.name";

    for (const id of ['current_layout', 'layout_versions']) {
      const source = sources.find((d: any) => d.id === id);
      expect(source, id).toBeDefined();
      expect(source.endpoint, id).toContain(fallback);
    }

    // 판본 복구 target 도 같은 폴백이어야 편집 중인 화면이 아닌 첫 파일을 되돌리지 않는다.
    const restores = findAll(versionModalJson, (n) => Array.isArray(n.actions)).flatMap(actionsOf)
      .filter((a) => a.handler === 'apiCall' && stringify(targetOf(a)).includes('/restore'));
    expect(restores.length).toBeGreaterThan(0);
    expect(stringify(targetOf(restores[0]))).toContain('_global.layoutFilesList?.[0]?.name');
  });
});
