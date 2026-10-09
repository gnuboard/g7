/**
 * @file admin-template-layout-edit-markup-default.test.tsx
 * @description 코드 편집기 HTML(.g7.html) 기본화 — 레이아웃 JSON 구조 검증 (#476)
 *
 * 검증 항목:
 * - 서브탭 기본값이 HTML(`markup`)이고 JSON 탭은 읽기 전용 편집기다
 * - HTML 편집기는 `language=html` 이며 서버 컴파일 마커(`markers`)를 받는다
 * - 타이핑 후 1초 유휴에 `markup-compile` 을 부르는 api-call 이 있고(debounce 1000), 저장·미리보기는 `format=markup` 으로 HTML 버퍼를 보낸다
 * - 본문 조회 데이터소스가 `?with_markup=1` 로 역변환본을 받되 **전역에 심지는 않는다**(#476 개편)
 * - 선택 파일 헤더 표기가 `.g7.html` 이다
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
  // HTML 편집은 `<code-workspace>` 가 맡는다 — 파일별 버퍼를 소유해야 하기 때문이다(#476 개편).
  // JSON 탭은 편집 버퍼가 없는 읽기 전용 산출물이라 `<code-editor>` 를 그대로 쓴다.
  const markupEditor = findAll(layoutJson, (n) => n.name === 'CodeWorkspace')[0];
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
  it('HTML 편집 표면은 서버 본문을 prop 으로 받고 컴파일 마커를 함께 받는다', () => {
    expect(markupEditor).toBeDefined();
    expect(markupEditor.props.language).toBe('html');

    // 서버 본문은 **전역이 아니라** 데이터소스에서 직접 들어온다 — 그래야 전역 덮어쓰기 경로가 없다.
    const serverValue = stringify(markupEditor.props.serverValue);
    expect(serverValue).toContain('content_markup');
    expect(serverValue).not.toContain('_global.editorMarkup');

    // 충돌 판정의 근거 — 서버 판본을 함께 받는다.
    expect(stringify(markupEditor.props.serverRevision)).toContain('lock_version');

    expect(stringify(markupEditor.props.markers)).toContain('markupCompileError');
    expect(stringify(markupEditor.props.markers)).toContain('markupWarnings');
  });

  /**
   * 파일 키가 두 갈래를 하나로 묶는다 — 레이아웃과 확장 조각이 같은 버퍼 맵의 다른 키다.
   *
   * @effects code_editor_uses_one_buffer_map_for_both_branches
   */
  it('파일 키가 레이아웃·확장 조각을 접두로 가른다', () => {
    const fileKey = stringify(markupEditor.props.fileKey);

    expect(fileKey).toContain("'ext:'");
    expect(fileKey).toContain("'layout:'");
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

    // 서버 왕복은 **사람이 고쳤을 때만** 돈다. `onChange` 는 파일을 열 때도 불리므로 거기 걸면
    // 파일 전환마다 변환이 돌고, 본문 도착 전 빈 값으로 먼저 나가 422 가 난다(실측 2026-09-12).
    const compileBinding = (markupEditor.actions ?? []).find(
      (a: any) => stringify(a).includes('markup-compile'),
    );
    expect(compileBinding?.event).toBe('onUserEdit');
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
    // 정의는 문서 층 `named_actions` 한 곳이고 머리말 버튼과 단축키가 함께 참조한다(#476 개편).
    const named = Object.values(layoutJson.named_actions ?? {});
    const calls = [...findAll(layoutJson, (n) => Array.isArray(n.actions)).flatMap(actionsOf), ...named.flatMap(flattenActions)]
      .filter((a: any) => a.handler === 'apiCall' && (a.params?.method === 'PUT' || stringify(targetOf(a)).includes('/preview')));

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
  it('본문 조회는 with_markup=1 로 역변환본을 받되 **마크업은 전역에 심지 않는다**', () => {
    for (const id of ['current_layout', 'current_extension']) {
      const source = (layoutJson.data_sources ?? []).find((d: any) => d.id === id);
      expect(source, id).toBeDefined();
      expect(source.endpoint).toContain('with_markup=1');

      const keys = (source.initGlobal ?? []).map((m: any) => m.key);
      // JSON 뷰·경고·오류는 "지금 열린 파일의 서버 상태" 라 전역이 맞다.
      expect(keys, id).toEqual(expect.arrayContaining(['editorContent', 'markupWarnings', 'markupCompileError']));
      // 마크업 버퍼를 여기서 심는 것이 곧 미저장 수정을 덮어쓰는 경로였다(D1/D4).
      expect(keys, id).not.toContain('editorMarkup');
    }
  });

  /**
   * @effects code_editor_shows_g7_html_labels_and_fragment_key
   */
  it('선택 파일 헤더는 .g7.html 로 표기한다', () => {
    const text = JSON.stringify(layoutJson);

    // 목록 쪽 표기는 트리 컴포넌트가 소유한다(마지막 세그먼트만 보인다) — 레이아웃에는
    // 열려 있는 파일의 헤더 표기만 남는다.
    expect(text).toContain("|| 'main'}}.g7.html");

    // 사용자에게 보이는 **파일 이름 문구**가 `.json` 으로 끝나서는 안 된다.
    // (`modals[].partial` 의 `.json` 은 산출 표기라 정상이다 — 그쪽은 화면에 나오지 않는다.)
    const labels: string[] = [];
    walk(layoutJson, (n) => {
      if (typeof n.text === 'string') labels.push(n.text);
    });
    expect(labels.filter((label) => /\.json\s*$/.test(label))).toEqual([]);
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

describe('코드 편집기 — 컴파일 실패 시 경고·좌표 표기 (2026-10-07 실측 O-I1-10·O-I1-11)', () => {
  const markupEditor = findAll(layoutJson, (n) => n.name === 'CodeWorkspace')[0];
  const compile = actionsOf(markupEditor).find((a) => a.handler === 'apiCall' && stringify(targetOf(a)).includes('markup-compile'));

  /**
   * 컴파일이 실패하면 직전 성공본의 경고를 지운다 — 남기면 바뀐 본문의 엉뚱한 줄을 가리키는 노란 표시가 남는다.
   *
   * @effects code_editor_compile_failure_clears_stale_warnings
   */
  it('markup-compile 실패 시 markupWarnings 를 비운다', () => {
    const fails = flattenActions(compile?.onError);
    const reset = fails.find((a) => a.handler === 'setState' && a.params && 'markupWarnings' in a.params);

    expect(reset, '실패 경로에서 경고를 비우는 setState').toBeDefined();
    expect(stringify(reset.params.markupWarnings)).toBe(stringify([]));
  });

  /**
   * 좌표를 모르는 경고·오류(서버 lint·보안 검사)는 「0:0」 을 찍지 않고, 화면 밖 코드(`request_failed`)도 보이지 않는다.
   *
   * @effects code_editor_panels_hide_unknown_coordinates
   */
  it('경고·오류 패널은 줄 번호가 있을 때만 좌표를 쓰고 내부 대체 코드를 보이지 않는다', () => {
    const warningRow = findAll(layoutJson, (n) => n.iteration?.item_var === 'warning')[0];
    expect(warningRow, 'warning row').toBeDefined();
    // 줄 있는 항목(버튼)만 좌표를 쓰고, 줄 없는 항목은 좌표 없이 문구만
    const withLine = (warningRow.children ?? []).find((c: any) => c.name === 'Button');
    const withoutLine = (warningRow.children ?? []).find((c: any) => c.name !== 'Button');
    expect(stringify(withLine?.if)).toContain('warning.line ?? 0) > 0');
    expect(stringify(withLine?.text)).toContain("warning.line + ':'");
    expect(stringify(withoutLine?.if)).toContain('!((warning.line ?? 0) > 0)');
    expect(stringify(withoutLine?.text)).not.toContain("line + ':'");

    const errorWithLine = findAll(layoutJson, (n) => n.name === 'Button' && stringify(n.text).includes("markupCompileError.line + ':'"))[0];
    const errorWithoutLine = findAll(layoutJson, (n) => n.name !== 'Button' && stringify(n.text).includes('markupCompileError?.message'))[0];
    expect(stringify(errorWithLine?.if)).toContain('markupCompileError?.line ?? 0) > 0');
    expect(stringify(errorWithoutLine?.if)).toContain('!((_global.markupCompileError?.line ?? 0) > 0)');
    expect(stringify(errorWithoutLine?.text)).not.toContain("line + ':'");

    const fails = flattenActions(compile?.onError);
    expect(stringify(fails)).not.toContain('request_failed');
  });
});

describe('코드 편집기 — 검사 경고·오류 줄로 이동 (7.1.0 P16)', () => {
  /**
   * 줄이 있는 경고·오류 항목은 버튼이고, 누르면 HTML 탭으로 돌려 그 줄·칸으로 커서를 옮긴다(`editorRevealAt` — 같은
   * 항목을 다시 눌러도 움직이도록 nonce 를 바꾼다). 버튼 이름은 「N번째 줄로 이동」 안내를 title 로 갖는다.
   *
   * @effects code_editor_issue_rows_reveal_source_line
   */
  it('경고 항목·오류 항목 버튼이 editorRevealAt(line·column·nonce)과 HTML 탭 전환을 싣는다', () => {
    const warningRow = findAll(layoutJson, (n) => n.iteration?.item_var === 'warning')[0];
    const warningButton = (warningRow.children ?? []).find((c: any) => c.name === 'Button');
    const errorButton = findAll(layoutJson, (n) => n.name === 'Button' && stringify(n.text).includes("markupCompileError.line + ':'"))[0];

    for (const [label, button, line] of [['warning', warningButton, 'warning.line'], ['error', errorButton, '_global.markupCompileError.line']] as const) {
      expect(button, label).toBeDefined();
      expect(button.props?.type, label).toBe('button');
      expect(stringify(button.props?.title), label).toContain('$t:admin.templates.layout_edit.go_to_line|line=');
      const reveal = flattenActions(button.actions).find((a: any) => a.handler === 'setState' && a.params?.editorRevealAt);
      expect(reveal, label).toBeDefined();
      expect(reveal.params.target).toBe('global');
      expect(reveal.params.editorFormat).toBe('markup');
      expect(reveal.params.editorRevealAt).toContain(`line: ${line}`);
      expect(reveal.params.editorRevealAt).toContain('nonce: Date.now()');
    }
  });

  /**
   * @effects code_editor_issue_rows_reveal_source_line
   */
  it('코드 편집기는 editorRevealAt 을 revealAt 으로 받는다', () => {
    const markupEditor = findAll(layoutJson, (n) => n.name === 'CodeWorkspace')[0];
    expect(markupEditor.props.revealAt).toBe('{{_global.editorRevealAt ?? null}}');
  });

  /**
   * 레이아웃만 고치고 빌드하지 않으면 새 Tailwind 유틸은 화면 CSS 에 없다 — 브라우저는 모르는 클래스를 조용히
   * 무시하므로 항목 버튼의 점선 밑줄(누를 수 있다는 표시)이 오류 없이 사라진다(7.1.0 validate q2, `decoration-dotted`
   * 누락 실측). 줄 이동 버튼·정보 토글·접히는 블록이 쓰는 클래스(식 안 문자열 포함)가 빌드 CSS 에 모두 있어야 한다.
   *
   * @effects code_editor_issue_rows_reveal_source_line
   */
  it('줄 이동 버튼·정보 토글·접히는 블록의 클래스가 빌드 CSS 에 있다', () => {
    const css = fs.readFileSync(path.resolve(__dirname, '../../dist/css/components.css'), 'utf8');
    const backslash = String.fromCharCode(92);
    const selector = (token: string) => '.' + token.replace(/([:\/.\[\]()%!])/g, (m) => backslash + m);
    const inCss = (token: string) => {
      const sel = selector(token);
      for (let i = css.indexOf(sel); i >= 0; i = css.indexOf(sel, i + 1)) {
        const next = css[i + sel.length];
        if (!/[a-z0-9_-]/i.test(next) && next !== backslash) return true;
      }
      return false;
    };
    const classTokens = (className: unknown): string[] =>
      String(className ?? '')
        .replace(/\{\{[\s\S]*?\}\}/g, (expr) => ' ' + [...expr.matchAll(/'([^']*)'/g)].map((m) => m[1]).join(' ') + ' ')
        .split(/\s+/)
        .filter((t) => /^[a-z][a-z0-9:\/.\-\[\]%]*$/.test(t));

    const warningRow = findAll(layoutJson, (n) => n.iteration?.item_var === 'warning')[0];
    const nodes = [
      ...(warningRow?.children ?? []),
      ...findAll(layoutJson, (n) => n.id === 'markup_error_goto' || n.id === 'code_editor_info_toggle'),
      ...['code_editor_file_desc', 'code_editor_file_meta', 'code_editor_inline_notice'].map(
        (id) => findAll(layoutJson, (n) => n.props?.id === id)[0],
      ),
    ];
    const tokens = [...new Set(nodes.flatMap((n: any) => classTokens(n?.props?.className)))];
    expect(tokens).toContain('decoration-dotted');
    expect(tokens.filter((t) => !inCss(t))).toEqual([]);
  });
});

describe('코드 편집기 — 휴대폰 폭에서 위쪽 정보 접기 (7.1.0 P17)', () => {
  const toggle = findAll(layoutJson, (n) => n.id === 'code_editor_info_toggle')[0];

  /**
   * 휴대폰 폭(md 미만)에서는 설명·수정일·이력 버튼·하단 안내를 접어 두고 [파일 정보 펼치기] 로 연다 —
   * 390x844 에서 코드 칸이 128px 였다(R-09). md 이상에서는 늘 펼쳐져 있고 버튼이 없다.
   *
   * @effects code_editor_mobile_info_collapsed
   */
  it('토글 버튼은 md 이상에서 숨고, aria-expanded·aria-controls 를 갖고, 누르면 codeEditorInfoOpen 을 뒤집는다', () => {
    expect(toggle, 'toggle').toBeDefined();
    expect(toggle.name).toBe('Button');
    expect(toggle.props.className).toContain('md:hidden');
    expect(toggle.props['aria-expanded']).toBe("{{_global.codeEditorInfoOpen ? 'true' : 'false'}}");
    expect(toggle.props['aria-controls']).toBe('code_editor_file_desc code_editor_file_meta code_editor_inline_notice');
    const flip = flattenActions(toggle.actions).find((a: any) => a.handler === 'setState');
    expect(flip.params.codeEditorInfoOpen).toBe('{{!_global.codeEditorInfoOpen}}');
    expect(stringify(toggle.children)).toContain('$t:admin.templates.layout_edit.info_toggle_show');
    expect(stringify(toggle.children)).toContain('$t:admin.templates.layout_edit.info_toggle_hide');
  });

  /**
   * @effects code_editor_mobile_info_collapsed
   */
  it('접히는 세 블록은 닫혀 있을 때 휴대폰에서만 숨고(md 이상 표시), 열리면 보인다', () => {
    for (const id of ['code_editor_file_desc', 'code_editor_file_meta', 'code_editor_inline_notice']) {
      // aria-controls 가 가리키는 DOM id — 노드 id(data-ref)가 아니라 props.id 다
      const node = findAll(layoutJson, (n) => n.props?.id === id)[0];
      expect(node, id).toBeDefined();
      expect(node.props.className, id).toContain('_global.codeEditorInfoOpen');
      expect(node.props.className, id).toMatch(/hidden md:(block|flex)/);
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
