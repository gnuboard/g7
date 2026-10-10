/**
 * @file admin-template-layout-edit.test.tsx
 * @description 템플릿 레이아웃 편집 페이지 테스트
 *
 * 테스트 대상:
 * - admin_template_layout_edit.json: 미리보기 버튼 액션 구조
 * - partials/admin_template_layout_edit/_modal_version_history.json: 버전 히스토리 모달
 * - 어빌리티 기반 조건부 렌더링 (저장 버튼)
 *
 * 검증 항목:
 * - 미리보기 버튼이 sequence → apiCall → openWindow 패턴 사용
 * - 버전 히스토리 버튼이 sequence → refetchDataSource → openModal 패턴 사용
 * - 버전 히스토리 모달 JSON 구조 (iteration, 복원 버튼)
 * - 저장 버튼에 can_update 어빌리티 조건 존재
 * - modals 섹션에 버전 히스토리 모달 partial 등록
  *
 * @effects code_editor_file_select_syncs_route_query, code_editor_extension_select_clears_route_query
*/

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';
import { asArray, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { dataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { isRawWrapped, unwrapRaw } from '@core/template-engine/rawMarkers';

// ==============================
// 레이아웃 JSON 파일 로드
// ==============================

const LAYOUTS_BASE = path.resolve(__dirname, '../../layouts');

function loadLayout(relativePath: string): any {
  const fullPath = path.resolve(LAYOUTS_BASE, relativePath);
  return JSON.parse(fs.readFileSync(fullPath, 'utf-8'));
}

const layoutEditJson = loadLayout('admin_template_layout_edit.json');
const versionHistoryModalJson = loadLayout('partials/admin_template_layout_edit/_modal_version_history.json');
const extensionVersionHistoryModalJson = loadLayout('partials/admin_template_layout_edit/_modal_extension_version_history.json');
const extensionPreviewLayoutModalJson = loadLayout('partials/admin_template_layout_edit/_modal_extension_preview_layout.json');

/** 참조를 펼친 사본 — 액션의 **동작**을 재는 검사는 이쪽을 본다. */
let resolvedLayoutEditJson: any;

// ==============================
// 헬퍼: JSON 구조 내 컴포넌트 검색
// ==============================

/**
 * `{actionRef: 'x'}` 를 `named_actions.x` 의 본문으로 펼친다.
 *
 * 저장·미리보기·판본 이력은 머리말 버튼과 단축키가 **같은 정의**를 부르도록 문서 층
 * `named_actions` 한 곳으로 옮겼다(#476). 그래서 "그 버튼이 무엇을 하는가" 를 재려면 참조를
 * 먼저 풀어야 한다 — 참조가 걸린 자리만 보고 판정하면 정의를 옮길 때마다 테스트가 깨지고,
 * 정작 동작이 바뀐 것은 놓친다.
 *
 * @param value 순회할 값
 * @param namedActions 문서의 named_actions
 * @returns 참조가 펼쳐진 사본
 */
function resolveActionRefs(value: any, namedActions: Record<string, any>): any {
  if (Array.isArray(value)) {
    return value.map((item) => resolveActionRefs(item, namedActions));
  }

  if (!value || typeof value !== 'object') {
    return value;
  }

  if (typeof value.actionRef === 'string' && namedActions[value.actionRef]) {
    const { actionRef, ...rest } = value;

    return { ...rest, ...resolveActionRefs(namedActions[actionRef], namedActions) };
  }

  const out: Record<string, any> = {};
  for (const [key, child] of Object.entries(value)) {
    out[key] = resolveActionRefs(child, namedActions);
  }

  return out;
}

function findAllComponents(node: any, predicate: (n: any) => boolean): any[] {
  const results: any[] = [];
  if (!node) return results;

  if (predicate(node)) {
    results.push(node);
  }

  if (Array.isArray(node.children)) {
    for (const child of node.children) {
      results.push(...findAllComponents(child, predicate));
    }
  }

  if (Array.isArray(node.slots?.content)) {
    for (const child of node.slots.content) {
      results.push(...findAllComponents(child, predicate));
    }
  }

  // RichSelect 등 component_layout 슬롯(item/selected) 내부도 순회
  if (node.component_layout && typeof node.component_layout === 'object') {
    for (const slot of Object.values(node.component_layout)) {
      if (Array.isArray(slot)) {
        for (const child of slot) {
          results.push(...findAllComponents(child, predicate));
        }
      }
    }
  }

  return results;
}

function findComponent(root: any, predicate: (n: any) => boolean): any | null {
  const results = findAllComponents(root, predicate);
  return results.length > 0 ? results[0] : null;
}

// ==============================
// 미리보기 버튼 테스트
// ==============================

resolvedLayoutEditJson = resolveActionRefs(layoutEditJson, layoutEditJson.named_actions ?? {});

describe('미리보기 버튼', () => {
  /**
   * 미리보기는 **저장하지 않은 본문**을 올려 서버가 발급한 일회용 토큰 주소를 새 창으로 연다.
   *
   * 정의는 문서 층 `preview_current_file` 한 곳이고 머리말 버튼과 `Ctrl+P` 가 함께 참조한다.
   *
   * @effects editor_preview_uses_sequence_api_call_then_open_window
   */
  it('저장 없이 본문을 올려 토큰 주소를 새 창으로 연다', () => {
    const named = layoutEditJson.named_actions?.preview_current_file;
    expect(named).toBeDefined();

    const apiCalls = findAllComponents(
      { children: [{ actions: [named] }] },
      () => false,
    );
    // 명명 액션 본문에서 apiCall 을 훑는다 (분기 안쪽에 있다).
    const calls: any[] = [];
    const walk = (value: any): void => {
      if (Array.isArray(value)) {
        value.forEach(walk);

        return;
      }
      if (!value || typeof value !== 'object') return;
      if (value.handler === 'apiCall') calls.push(value);
      Object.values(value).forEach(walk);
    };
    walk(named);

    expect(apiCalls.length).toBe(0);
    expect(calls.length).toBeGreaterThanOrEqual(1);

    for (const call of calls) {
      expect(call.params.method).toBe('POST');
      expect(call.target).toContain('/preview');
      expect(call.auth_required).toBe(true);

      const openWindow = asArray(call?.onSuccess).find((a: any) => a.handler === 'openWindow');
      expect(openWindow).toBeDefined();
      expect(openWindow.params.path).toContain('response.data.token');
    }
  });

  /**
   * @effects code_editor_saves_markup_format
   */
  it('apiCall body에 HTML 버퍼(editorMarkup)를 format=markup 으로 전달한다', () => {
    const body = JSON.stringify(layoutEditJson.named_actions?.preview_current_file ?? {});

    expect(body).toContain('_global.editorMarkup');
    expect(body).toContain('"format":"markup"');
  });

  /**
   * 머리말 버튼은 정의를 복제하지 않고 **이름으로 부른다**.
   *
   * @effects editor_header_buttons_reference_named_actions, editor_buttons_declare_button_type_so_forms_do_not_submit
   */
  it('머리말 미리보기 버튼은 named action 을 참조한다', () => {
    const button = findComponent(layoutEditJson, (n) =>
      n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('preview_current_file'),
    );

    expect(button).not.toBeNull();
    expect(button.props?.type).toBe('button');
  });
});

describe('버전 히스토리 버튼', () => {
  /** @effects version_history_button_branches_layout_and_extension_by_conditions */
  it('conditions 로 일반/확장 모달을 분기한다 (정의는 named action 한 곳)', () => {
    const named = layoutEditJson.named_actions?.open_version_history;
    expect(named).toBeDefined();
    expect(named.handler).toBe('conditions');

    const extensionBranch = asArray(named?.conditions).find(
      (c: any) => c.if && c.if.includes("'extension'"),
    );
    expect(extensionBranch).toBeDefined();
    expect(JSON.stringify(extensionBranch.then)).toContain('extension_version_history_modal');
    expect(JSON.stringify(extensionBranch.then)).toContain('extension_versions');

    const defaultBranch = asArray(named?.conditions).find((c: any) => !c.if);
    expect(defaultBranch).toBeDefined();
    expect(JSON.stringify(defaultBranch.then)).toContain('version_history_modal');
    expect(JSON.stringify(defaultBranch.then)).toContain('layout_versions');
  });

  /** @effects editor_header_buttons_reference_named_actions */
  it('머리말 판본 이력 버튼과 단축키가 같은 정의를 참조한다', () => {
    const button = findComponent(layoutEditJson, (n) =>
      n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('open_version_history'),
    );
    expect(button).not.toBeNull();

    const workspace = findComponent(layoutEditJson, (n) => n.name === 'CodeWorkspace');
    const shortcut = asArray(workspace?.actions).find((a: any) => a.event === 'onVersionHistory');
    expect(shortcut?.actionRef).toBe('open_version_history');
  });
});

// ==============================
// 버전 히스토리 모달 JSON 구조 테스트
// ==============================

describe('버전 히스토리 모달 구조', () => {
  /** @effects layout_version_modal_has_the_expected_id_and_structure */
  it('올바른 모달 ID와 구조를 가진다', () => {
    expect(versionHistoryModalJson.id).toBe('version_history_modal');
    expect(versionHistoryModalJson.type).toBe('composite');
    expect(versionHistoryModalJson.name).toBe('Modal');
    expect(versionHistoryModalJson.props.size).toBe('medium');
  });

  /** @effects layout_version_modal_iterates_layout_versions */
  it('layout_versions 를 반복 렌더링하는 iteration 영역이 있다', () => {
    const iterationNode = findComponent(versionHistoryModalJson, (n) =>
      Boolean(n.iteration?.source?.includes('layout_versions'))
    );
    expect(iterationNode).not.toBeNull();
    expect(iterationNode.iteration.item_var).toBe('version');
  });

  /** @effects newest_layout_version_carries_the_latest_badge */
  it('첫 번째 버전(idx === 0)에 최신 배지가 표시된다', () => {
    const latestBadge = findComponent(versionHistoryModalJson, (n) =>
      n.if === '{{idx === 0}}' &&
      typeof n.text === 'string' &&
      n.text.includes('latest')
    );
    expect(latestBadge).not.toBeNull();
  });

  /** @effects non_newest_layout_versions_carry_a_restore_button */
  it('첫 번째가 아닌 버전(idx !== 0)에 복원 버튼이 있다', () => {
    const restoreButton = findComponent(versionHistoryModalJson, (n) =>
      n.if === '{{idx !== 0}}' &&
      n.name === 'Button' &&
      asArray(n?.actions).length > 0 &&
      asArray(n?.actions).some((a: any) =>
        JSON.stringify(a).includes('/restore')
      )
    );
    expect(restoreButton).not.toBeNull();
  });
});

// ==============================
// modals 섹션 테스트
// ==============================

describe('modals 섹션', () => {
  /** @effects version_history_modal_partial_is_registered */
  it('version_history 모달 partial 이 등록되어 있다', () => {
    expect(layoutEditJson.modals).toBeDefined();
    expect(Array.isArray(layoutEditJson.modals)).toBe(true);

    const versionModal = layoutEditJson.modals.find(
      (m: any) => m.partial?.includes('_modal_version_history')
    );
    expect(versionModal).toBeDefined();
  });
});

// ==============================
// 어빌리티 기반 조건부 렌더링 테스트
// ==============================

describe('어빌리티 기반 조건부 렌더링', () => {
  /** @effects save_button_is_gated_by_can_update */
  it('저장 버튼에 can_update 조건이 있다', () => {
    // 저장 버튼: apiCall로 PUT 요청하는 버튼 or save 관련 텍스트
    const saveButton = findComponent(layoutEditJson, (n) => {
      return n.if && n.if.includes('can_update');
    });

    expect(saveButton).not.toBeNull();
    expect(saveButton.if).toContain('abilities');
    expect(saveButton.if).toContain('can_update');
  });
});

// ==============================
// 레이아웃 확장 데이터 소스 테스트
// ==============================

describe('레이아웃 확장 데이터 소스', () => {
  /** @effects extension_branch_declares_its_three_data_sources, group_header_icon_distinguishes_plugin_from_module */
  it('layout_extensions / current_extension / extension_versions 데이터 소스가 정의되어 있다', () => {
    const ids = layoutEditJson.data_sources.map((d: any) => d.id);
    expect(ids).toContain('layout_extensions');
    expect(ids).toContain('current_extension');
    expect(ids).toContain('extension_versions');
  });

  /** @effects layout_extensions_loads_blocking_on_entry */
  it('layout_extensions 는 auto_fetch + blocking 으로 트리 초기 로드된다', () => {
    const ds = layoutEditJson.data_sources.find((d: any) => d.id === 'layout_extensions');
    expect(ds.auto_fetch).toBe(true);
    expect(ds.loading_strategy).toBe('blocking');
    expect(ds.endpoint).toContain('/layout-extensions');
  });

  /** @effects transition_overlay_waits_for_layout_extensions */
  it('transition_overlay.wait_for 에 layout_extensions 가 포함된다', () => {
    expect(layoutEditJson.transition_overlay.wait_for).toContain('layout_extensions');
  });

  /**
   * @effects code_editor_opens_with_decompiled_markup_buffer, code_editor_opens_extension_fragments_in_the_markup_tab
   */
  it('current_extension 은 selectedExtensionId 의존 + with_markup 이고 **마크업은 전역에 심지 않는다**', () => {
    const ds = layoutEditJson.data_sources.find((d: any) => d.id === 'current_extension');
    expect(ds.auto_fetch).toBe(false);
    expect(ds.endpoint).toContain('selectedExtensionId');
    expect(ds.endpoint).toContain('with_markup=1');

    const keys = ds.initGlobal.map((m: any) => m.key);
    // JSON 뷰·경고·오류는 "지금 열린 파일의 서버 상태" 라 전역이 맞다.
    expect(keys).toEqual(expect.arrayContaining(['editorContent']));
    // 마크업 버퍼는 **여기서 심지 않는다** — 이 심기가 곧 미저장 수정을 덮어쓰는 경로였다(D1/D4, #476).
    // `<code-workspace>` 가 `serverValue` prop 으로 직접 읽고, 편집 값은 파일별 버퍼가 소유한다.
    expect(keys).not.toContain('editorMarkup');
  });

  /**
   * 레이아웃 갈래도 같은 규약이다 — 한쪽만 고치면 그 갈래에서 유실이 그대로 남는다.
   *
   * @effects code_editor_never_seeds_the_markup_buffer_from_a_refetch
   */
  it('current_layout 도 마크업을 전역에 심지 않는다', () => {
    const ds = layoutEditJson.data_sources.find((d: any) => d.id === 'current_layout');
    const keys = ds.initGlobal.map((m: any) => m.key);

    expect(keys).toEqual(expect.arrayContaining(['editorContent']));
    expect(keys).not.toContain('editorMarkup');
  });
});

// ==============================
// 좌측 트리 — 확장 섹션 테스트
// ==============================

describe('좌측 파일 트리', () => {
  /**
   * 좌측 목록은 `<file-tree-picker>` 한 컴포넌트가 맡는다.
   *
   * 종전에는 데스크톱 목록(`data-each`)과 모바일 `rich-select` 두 벌이었고, 모바일 쪽에는 검색이
   * 없어 144개를 스크롤해야 했다. 형태 분기(인라인·서랍·시트)는 이제 컴포넌트 안에 있으므로
   * 레이아웃에는 **하나만** 있어야 한다 — 둘이 되는 순간 한쪽만 고쳐진 상태가 다시 생긴다.
   *
   * @effects code_editor_file_tree_is_a_single_component, extension_tree_nests_each_group_items
   */
  it('기기별 목록이 하나의 file-tree-picker 로 합쳐졌다', () => {
    const pickers = findAllComponents(layoutEditJson, (n) => n.name === 'FileTreePicker');
    expect(pickers).toHaveLength(1);

    // 옛 모바일 선택기는 이 화면에서 사라졌다.
    expect(findComponent(layoutEditJson, (n) => n.name === 'RichSelect')).toBeNull();
    // 옛 평면 목록 iteration 도 사라졌다.
    expect(
      findComponent(layoutEditJson, (n) => Boolean(n.iteration?.source?.includes('layout_files'))),
    ).toBeNull();
  });

  /** @effects extension_tree_groups_by_source, extension_section_header_exists_in_the_file_tree */
  it('nodes 가 레이아웃과 확장 조각을 한 배열로 합치고 출처를 싣는다', () => {
    const picker = findComponent(layoutEditJson, (n) => n.name === 'FileTreePicker');
    const nodes = String(picker.props.nodes);

    // 두 갈래가 한 배열로 들어가고, 키 접두로 갈래를 가른다.
    expect(nodes).toContain('layout_files');
    expect(nodes).toContain('layout_extensions');
    expect(nodes).toContain("'layout:'");
    expect(nodes).toContain("'ext:'");

    // 확장은 준 확장(모듈·플러그인)으로 묶이고 그 종류가 아이콘을 가른다.
    expect(nodes).toContain('source_label');
    expect(nodes).toContain('source_type');
  });

  /** @effects extension_override_badge_is_visible_in_the_tree, override_extension_item_shows_the_override_badge */
  it('덮어쓰기·손댐·비활성 배지를 노드에 실어 보낸다', () => {
    const nodes = String(
      findComponent(layoutEditJson, (n) => n.name === 'FileTreePicker').props.nodes,
    );

    expect(nodes).toContain('extension_override_badge');
    expect(nodes).toContain('extension_modified_badge');
    expect(nodes).toContain('extension_inactive_badge');
  });

  /** @effects code_editor_tree_reads_dirty_keys, clicking_an_extension_switches_the_selected_node_kind */
  it('미저장 파일 목록과 검색 상태를 트리에 넘긴다', () => {
    const props = findComponent(layoutEditJson, (n) => n.name === 'FileTreePicker').props;

    expect(String(props.dirtyKeys)).toContain('dirtyFileKeys');
    expect(String(props.searchKeyword)).toContain('fileSearchQuery');
    expect(String(props.contentMatches)).toContain('layout_search');
    expect(String(props.pendingFolders)).toContain('pendingFolders');
  });

  /**
   * 내용 검색은 타이핑이 멎은 뒤 한 번만 서버에 묻는다 — 디바운스는 이벤트의 **최상위 액션**에 걸려야 한다.
   *
   * 엔진은 이벤트에 직접 달린 액션의 `debounce` 만 읽는다. `<conditions>` 의 `<when>` 안 액션에 건
   * `:debounce="600"` 은 무시돼, 실측 2026-10-06(7.1.0 전수 실측 I1)에서 「notification_center_mobile」 을
   * 치자 글자마다 `layouts/search` 가 25건 나갔고, 공유 서버가 밀려 뒤쪽 19건이 30초 제한으로 끊긴 채
   * 앞선 「notific」 결과(509건)가 최종 결과처럼 남았다.
   *
   * @effects code_editor_content_search_is_debounced
   */
  it('내용 검색 재조회는 최상위 액션에 디바운스가 걸린다', () => {
    const tree = findComponent(layoutEditJson, (n) => n.name === 'FileTreePicker');
    const searchActions = asArray(tree.actions).filter((a: any) => a?.event === 'onSearch');
    const contentSearch = searchActions.find((a: any) => JSON.stringify(a).includes('layout_search'));

    expect(contentSearch, '내용 검색 액션이 있어야 한다').toBeTruthy();
    expect(Number(contentSearch.debounce)).toBeGreaterThanOrEqual(300);
  });

  /** @effects code_editor_tree_hides_actions_without_permission */
  it('편집 권한이 없으면 파일 조작을 내보내지 않는다', () => {
    const props = findComponent(layoutEditJson, (n) => n.name === 'FileTreePicker').props;

    expect(String(props.canManage)).toContain('can_update');

    // 고른 갈래의 권한만 본다 — 저장 버튼·읽기 전용 판정과 같은 기준. 다른 갈래에 남아 있는
    // 이전 응답의 권한으로 파일 조작이 열리면 안 된다.
    const evaluateCanManage = (kind: 'layout' | 'extension', layoutCan: boolean, extensionCan: boolean) => {
      const body = String(props.canManage).trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
      // eslint-disable-next-line no-new-func
      return new Function('_global', 'current_layout', 'current_extension', `return (${body});`)(
        { selectedNodeKind: kind },
        { data: { abilities: { can_update: layoutCan } } },
        { data: { abilities: { can_update: extensionCan } } },
      );
    };

    expect(evaluateCanManage('layout', true, false)).toBe(true);
    expect(evaluateCanManage('layout', false, true)).toBe(false);
    expect(evaluateCanManage('extension', false, true)).toBe(true);
    expect(evaluateCanManage('extension', true, false)).toBe(false);
  });

  /** @effects extension_select_sets_selected_node_kind */
  it('확장 항목을 고르면 selectedNodeKind=extension 으로 간다', () => {
    const picker = findComponent(layoutEditJson, (n) => n.name === 'FileTreePicker');
    const select = asArray(picker?.actions).find((a: any) => a.event === 'onSelect');

    expect(select?.handler).toBe('conditions');

    const extensionBranch = asArray(select?.conditions).find((c: any) => c.if && c.if.includes("'ext:'"));
    expect(extensionBranch).toBeDefined();
    expect(JSON.stringify(extensionBranch.then)).toContain('"selectedNodeKind":"extension"');
    expect(JSON.stringify(extensionBranch.then)).toContain('current_extension');
  });
});

describe('파일 조작', () => {
  /** @effects code_editor_can_create_rename_and_delete_files */
  it('새 파일·새 폴더·이름변경·삭제가 모두 트리에서 시작된다', () => {
    const picker = findComponent(layoutEditJson, (n) => n.name === 'FileTreePicker');
    const events = asArray(picker?.actions).map((a: any) => a.event);

    expect(events).toEqual(
      expect.arrayContaining(['onCreateFile', 'onCreateFolder', 'onRename', 'onDelete']),
    );
  });

  /**
   * 새 폴더는 **서버를 부르지 않는다** — 폴더는 파일 이름의 `/` 일 뿐이다(제품 결정 D-2).
   *
   * @effects code_editor_new_folder_is_client_side_only
   */
  it('새 폴더는 전역 목록에만 얹고 API 를 부르지 않는다', () => {
    const picker = findComponent(layoutEditJson, (n) => n.name === 'FileTreePicker');
    const createFolder = asArray(picker?.actions).find((a: any) => a.event === 'onCreateFolder');

    expect(JSON.stringify(createFolder)).not.toContain('apiCall');
    expect(JSON.stringify(createFolder)).toContain('file_form_modal');
  });

  /** @effects code_editor_registers_file_modals */
  it('이름 입력 창과 삭제 확인 창이 등록돼 있다', () => {
    const partials = (layoutEditJson.modals ?? []).map((m: any) => m.partial ?? '');

    expect(partials.some((p: string) => p.includes('_modal_file_form'))).toBe(true);
    expect(partials.some((p: string) => p.includes('_modal_file_delete_confirm'))).toBe(true);
  });

  /**
   * §5.1 「route_path 가 연결된 레이아웃 삭제는 확인 1단계 추가(차단은 아님)」 — 서버는 `confirm` 없이 오면
   * 422 `delete_requires_confirmation` 으로 되돌린다. 창이 처음부터 `confirm=1` 을 실어 보내면 그 단계는
   * 존재하지 않는 것과 같으므로, 1단계는 confirm 없이 보내고 2단계 버튼만 confirm=1 을 싣는지를 잠근다.
   *
   * @effects code_editor_asks_again_before_deleting_a_routed_layout
   */
  it('삭제 확인 창은 confirm 없이 먼저 보내고, 라우트가 걸린 파일만 두 번째 단계에서 confirm=1 을 싣는다', () => {
    const modal = loadLayout('partials/admin_template_layout_edit/_modal_file_delete_confirm.json');
    const buttons = findAllComponents(modal, (n) => n.name === 'Button' && Array.isArray(n.actions));
    const byTestId = (id: string) => buttons.find((b) => b.props?.['data-testid'] === id);

    const firstStage = byTestId('file-delete-confirm');
    const secondStage = byTestId('file-delete-confirm-route');
    expect(firstStage).toBeTruthy();
    expect(secondStage).toBeTruthy();

    const targetOf = (b: any) => (b.actions ?? []).find((a: any) => a.handler === 'apiCall')?.target ?? '';
    expect(targetOf(firstStage)).not.toContain('confirm=1');
    expect(targetOf(secondStage)).toContain('confirm=1');

    // 2단계 버튼은 서버가 라우트 확인을 요구했을 때만 보이고, 1단계 실패 처리가 그 판정을 적는다.
    expect(secondStage.if).toContain('fileDeleteRouteRequired');
    expect(JSON.stringify(firstStage.actions)).toContain('delete_requires_confirmation');
    expect(JSON.stringify(modal)).toContain('file-delete-route-warning');
  });
});

describe('확장 버전 히스토리 모달 구조', () => {
  /** @effects extension_version_modal_has_the_expected_id_and_structure */
  it('올바른 모달 ID와 구조를 가진다', () => {
    expect(extensionVersionHistoryModalJson.id).toBe('extension_version_history_modal');
    expect(extensionVersionHistoryModalJson.type).toBe('composite');
    expect(extensionVersionHistoryModalJson.name).toBe('Modal');
  });

  /** @effects extension_version_modal_iterates_extension_versions */
  it('extension_versions 를 반복 렌더링하는 iteration 영역이 있다', () => {
    const iterationNode = findComponent(extensionVersionHistoryModalJson, (n) =>
      Boolean(n.iteration?.source?.includes('extension_versions'))
    );
    expect(iterationNode).not.toBeNull();
    expect(iterationNode.iteration.item_var).toBe('version');
  });

  /** @effects extension_version_restore_calls_the_extension_restore_endpoint */
  it('복원 버튼이 확장 버전 restore 엔드포인트를 호출한다', () => {
    const restoreButton = findComponent(extensionVersionHistoryModalJson, (n) =>
      n.if === '{{idx !== 0}}' &&
      n.name === 'Button' &&
      asArray(n?.actions).length > 0 &&
      asArray(n?.actions).some((a: any) => JSON.stringify(a).includes('/layout-extensions/'))
    );
    expect(restoreButton).not.toBeNull();
    expect(JSON.stringify(restoreButton.actions)).toContain('/restore');
  });
});

// ==============================
// 확장 미리보기 레이아웃 선택 모달 테스트
// ==============================

describe('확장 미리보기 레이아웃 선택 모달', () => {
  /** @effects preview_layout_picker_modal_has_the_expected_id */
  it('올바른 모달 ID를 가진다', () => {
    expect(extensionPreviewLayoutModalJson.id).toBe('extension_preview_layout_modal');
    expect(extensionPreviewLayoutModalJson.name).toBe('Modal');
  });

  /** @effects extension_preview_calls_the_extension_preview_endpoint_with_a_host_layout */
  it('미리보기 버튼이 layout-extensions preview 엔드포인트를 preview_layout 과 함께 호출한다', () => {
    const previewButton = findComponent(extensionPreviewLayoutModalJson, (n) =>
      n.name === 'Button' &&
      asArray(n?.actions).length > 0 &&
      asArray(n?.actions).some((a: any) => a.handler === 'apiCall' && String(a.target).includes('/preview'))
    );
    expect(previewButton).not.toBeNull();
    const apiCall = asArray(previewButton?.actions).find((a: any) => a.handler === 'apiCall');
    expect(JSON.stringify(apiCall.params.body)).toContain('preview_layout');
  });
});

// ==============================
// modals 섹션 — 확장 모달 등록 테스트
// ==============================

describe('modals 섹션 — 확장 모달', () => {
  /** @effects extension_version_and_preview_picker_modals_are_registered */
  it('확장 버전 히스토리 / 미리보기 레이아웃 선택 모달이 등록되어 있다', () => {
    const partials = layoutEditJson.modals.map((m: any) => m.partial ?? '');
    expect(partials.some((p: string) => p.includes('_modal_extension_version_history'))).toBe(true);
    expect(partials.some((p: string) => p.includes('_modal_extension_preview_layout'))).toBe(true);
  });
});

// ==============================
// kind 분기 — 확장 저장 버튼 테스트
// ==============================

describe('selectedNodeKind 분기', () => {
  /** @effects extension_save_calls_the_extension_put_endpoint */
  it('확장 저장이 layout-extensions PUT 을 부르고 양쪽을 다시 받는다', () => {
    const save = layoutEditJson.named_actions?.save_current_file;
    expect(save).toBeDefined();

    const extensionBranch = asArray(save?.conditions).find(
      (c: any) => c.if && c.if.includes("'extension'"),
    );
    expect(extensionBranch).toBeDefined();

    const body = JSON.stringify(extensionBranch.then);
    expect(body).toContain('/layout-extensions/');
    expect(body).toContain('"method":"PUT"');
    // 조각의 "손댐" 배지는 목록이 들고 있으므로 본문만 갱신하면 옛 상태로 남는다.
    expect(body).toContain('current_extension');
    expect(body).toContain('layout_extensions');
  });

  /** @effects layout_save_calls_the_layout_put_endpoint */
  it('레이아웃 저장이 layouts PUT 을 부르고 목록까지 다시 받는다', () => {
    const save = layoutEditJson.named_actions?.save_current_file;
    const defaultBranch = asArray(save?.conditions).find((c: any) => !c.if);

    const body = JSON.stringify(defaultBranch.then);
    expect(body).toContain('"method":"PUT"');
    expect(body).toContain('expected_lock_version');
    expect(body).toContain('current_layout');
    expect(body).toContain('layout_files');
  });

  /**
   * 서버는 저장한 마크업을 역변환해 돌려주므로 글자가 정규화된다. 작업 공간이 그 판본을 «내 저장» 으로
   * 알아보도록 저장 동작이 보낸 파일·본문을 적고, 성공 응답의 판본을 채워 `lastSave` 로 넘긴다
   * (실측 2026-10-04: 없으면 저장할 때마다 「yours v2 → server v3」 충돌 바).
   *
   * @scenario branch=layout, buffer_state=dirty, leave=file_switch
   * @effects code_editor_save_records_last_save_for_workspace
   */
  it('저장 동작이 보낸 파일·본문과 응답 판본을 남기고 작업 공간이 그것을 받는다', () => {
    const save = layoutEditJson.named_actions?.save_current_file;
    const branches = asArray(save?.conditions);
    const evaluate = (expr: string, context: Record<string, unknown>): unknown =>
      dataBindingEngine.evaluateExpression(String(expr).trim().replace(/^\{\{|\}\}$/g, ''), context);
    const findSetState = (node: any, pred: (params: any) => boolean): any => {
      if (!node || typeof node !== 'object') return null;
      if (!Array.isArray(node) && node.handler === 'setState' && node.params && pred(node.params)) return node.params;
      for (const v of Array.isArray(node) ? node : Object.values(node)) {
        const hit = findSetState(v, pred);
        if (hit) return hit;
      }
      return null;
    };

    const cases = [
      { branch: branches.find((c: any) => c.if && c.if.includes("'extension'")), global: { selectedNodeKind: 'extension', selectedExtensionId: 12, editorMarkup: '<x  a="1"/>' }, key: 'ext:12' },
      { branch: branches.find((c: any) => !c.if), global: { selectedNodeKind: 'layout', selectedLayoutName: 'home', editorMarkup: '<y  b="2"/>' }, key: 'layout:home' },
    ];

    for (const { branch, global, key } of cases) {
      const sending = findSetState(branch, (p) => p.isSaving === true);
      expect(evaluate(sending.codeEditorLastSave, { _global: global })).toEqual({ key, value: global.editorMarkup, revision: null });

      const done = findSetState(branch?.then, (p) => p.isSaving === false && 'codeEditorLastSave' in p);
      const recorded = { key, value: global.editorMarkup, revision: null };
      expect(
        evaluate(done.codeEditorLastSave, { _global: { ...global, codeEditorLastSave: recorded }, response: { data: { lock_version: 7 } } }),
      ).toEqual({ key, value: global.editorMarkup, revision: 7 });
    }

    // 작업 공간에는 `raw:` 로 넘긴다 — 본문 안의 `$t:` 가 번역 패스에서 바뀌면 보낸 본문과 버퍼가 달라 보여
    // 정규화본을 받아들이지 못하고 저장 뒤에도 미저장으로 남는다(실측 2026-10-04, 첫 수정본에서 재현).
    const workspace = findComponent(layoutEditJson, (n) => n.name === 'CodeWorkspace');
    expect(workspace?.props?.lastSave).toBe('{{raw:_global.codeEditorLastSave ?? null}}');

    const markup = '<h1>$t:gnuboard7-hello_module.admin.memos.index.title</h1>';
    const resolved = dataBindingEngine.resolveObject(
      { lastSave: workspace.props.lastSave },
      { _global: { codeEditorLastSave: { key: 'layout:home', value: markup, revision: 3 } } },
    ) as { lastSave: { key: string; value: string; revision: number } };
    expect(isRawWrapped(resolved.lastSave.value)).toBe(true);
    expect(unwrapRaw(resolved.lastSave.value)).toBe(markup);
    expect(resolved.lastSave.revision).toBe(3);
  });

  /** @effects editor_header_buttons_reference_named_actions */
  it('머리말 저장 버튼과 Ctrl+S 가 같은 정의를 참조한다', () => {
    const buttons = findAllComponents(
      layoutEditJson,
      (n) => n.name === 'Button' && JSON.stringify(n.actions ?? []).includes('save_current_file'),
    );
    expect(buttons.length).toBeGreaterThanOrEqual(1);

    const workspace = findComponent(layoutEditJson, (n) => n.name === 'CodeWorkspace');
    const shortcut = asArray(workspace?.actions).find((a: any) => a.event === 'onSave');
    expect(shortcut?.actionRef).toBe('save_current_file');
  });
});

describe('[case:components-misc-user-content-object-prop-translated] 사용자 본문을 객체 prop 으로 넘길 때', () => {
  /**
   * 증상: 본문에 `$t:` 가 있는 파일은 저장이 200 인데도 미저장 표시가 남았다(실측 2026-10-04).
   * 해결: 작업 공간에 `raw:` 로 넘긴다. 일반 바인딩은 객체 안 본문을 그대로 두어 렌더러의 번역 패스가
   * `$t:` 를 번역문으로 바꾸고, `raw:` 바인딩은 객체 리프 문자열 전부에 마커를 붙여 번역 패스를 건너뛰게 한다.
   *
   * @effects code_editor_save_records_last_save_for_workspace
   */
  it('일반 바인딩은 본문을 번역 대상으로 남기고, 작업 공간이 쓰는 raw 바인딩은 원문을 지킨다', () => {
    const markup = '<h1>$t:gnuboard7-hello_module.admin.memos.index.title</h1>';
    const context = { _global: { codeEditorLastSave: { key: 'layout:home', value: markup, revision: 3 } } };

    const plain = dataBindingEngine.resolveObject(
      { lastSave: '{{_global.codeEditorLastSave ?? null}}' },
      context,
    ) as { lastSave: { value: string } };
    expect(isRawWrapped(plain.lastSave.value)).toBe(false);
    expect(plain.lastSave.value).toContain('$t:');

    const workspace = findComponent(layoutEditJson, (n) => n.name === 'CodeWorkspace');
    expect(String(workspace?.props?.lastSave)).toMatch(/^\{\{raw:/);
    const guarded = dataBindingEngine.resolveObject({ lastSave: workspace.props.lastSave }, context) as {
      lastSave: { key: string; value: string };
    };
    expect(isRawWrapped(guarded.lastSave.value)).toBe(true);
    expect(unwrapRaw(guarded.lastSave.value)).toBe(markup);
  });
});

// ==============================
// 코드 편집기 ?route= URL 동기화 테스트
// ==============================

describe('코드 편집기 ?route= URL 동기화', () => {
  /** @effects init_actions_restore_the_selected_layout_from_the_route_query */
  it('init_actions 가 query.route 로 selectedLayoutName 을 복원한다', () => {
    const initActions = layoutEditJson.init_actions;
    expect(Array.isArray(initActions)).toBe(true);

    const routeInit = initActions.find(
      (a: any) =>
        a.handler === 'setState' &&
        typeof a.if === 'string' &&
        a.if.includes('query.route') &&
        JSON.stringify(a.params ?? {}).includes('selectedLayoutName')
    );
    expect(routeInit).toBeDefined();
    // layout_files 의 route_path 로 매칭 (별도 데이터소스 추가 없이 기존 API 보강분 사용)
    expect(routeInit.if).toContain('route_path');
    expect(JSON.stringify(routeInit.params)).toContain('route_path');
    expect(routeInit.params.selectedNodeKind).toBe('layout');
  });

  /** @effects code_editor_file_select_syncs_route_query */
  it('레이아웃 파일을 고르면 replaceUrl 로 route_path 를 ?route= 에 반영한다', () => {
    const picker = findComponent(layoutEditJson, (n) => n.name === 'FileTreePicker');
    const select = asArray(picker?.actions).find((a: any) => a.event === 'onSelect');
    const layoutBranch = asArray(select?.conditions).find((c: any) => !c.if);

    const replaceUrl = asArray(layoutBranch?.then).find((a: any) => a.handler === 'replaceUrl');
    expect(replaceUrl).toBeDefined();
    expect(replaceUrl.params.query.route).toContain('route_path');
  });

  /**
   * 탭으로 옮겨 갈 때도 **같은 경로**를 지나야 선택·주소·재조회가 한 벌로 움직인다.
   *
   * @effects code_editor_tab_switch_syncs_route_query
   */
  it('탭 전환도 같은 규약으로 주소를 맞춘다', () => {
    const workspace = findComponent(layoutEditJson, (n) => n.name === 'CodeWorkspace');
    const requestSelect = asArray(workspace?.actions).find((a: any) => a.event === 'onRequestSelect');

    expect(requestSelect?.handler).toBe('conditions');
    expect(JSON.stringify(requestSelect)).toContain('replaceUrl');
  });

  /** @effects code_editor_extension_select_clears_route_query */
  it('확장 조각을 고르면 replaceUrl 로 ?route= 를 비운다 (조각은 라우트가 없다)', () => {
    const picker = findComponent(layoutEditJson, (n) => n.name === 'FileTreePicker');
    const select = asArray(picker?.actions).find((a: any) => a.event === 'onSelect');
    const extensionBranch = asArray(select?.conditions).find((c: any) => c.if && c.if.includes("'ext:'"));

    const replaceUrl = asArray(extensionBranch?.then).find((a: any) => a.handler === 'replaceUrl');
    expect(replaceUrl).toBeDefined();
    expect(replaceUrl.params.query.route).toBe('');
  });

  /**
   * 파일을 바꾸면 JSON 탭은 **아직 이전 파일의 산출물**을 들고 있다.
   *
   * 종전에는 여기서 `jsonViewStale=false` 를 세워 그것이 "최신" 으로 보였다(D17).
   *
   * @effects code_editor_marks_json_view_stale_on_file_switch
   */
  it('파일 전환 즉시 JSON 뷰를 오래됨으로 세운다', () => {
    const picker = findComponent(layoutEditJson, (n) => n.name === 'FileTreePicker');
    const select = asArray(picker?.actions).find((a: any) => a.event === 'onSelect');

    for (const branch of asArray(select?.conditions)) {
      const setState = asArray(branch?.then).find((a: any) => a.handler === 'setState');
      expect(setState?.params?.jsonViewStale).toBe(true);
    }
  });

  /**
   * 없는 파일을 겨냥한 본문 조회(404)는 코어 범용 문구가 아니라 이 화면의 안내를 띄우고
   * 목록을 다시 불러야 한다(§7.7 표 5행, 브라우저 재현 §14.9). 선언층이 `<error-handling>` 을 잃으면
   * 컴포넌트층(knownKeys)이 막아도 남이 지운 파일을 눌렀을 때 범용 404 문구로 되돌아간다.
   *
   * @effects code_editor_reloads_file_list_on_missing_file_404
   */
  it('current_layout 의 404 는 안내 토스트 뒤 layout_files 를 다시 부른다 (§7.7 본문 404)', () => {
    const ds = layoutEditJson.data_sources.find((d: any) => d.id === 'current_layout');
    const onNotFound = ds?.errorHandling?.['404'];

    expect(onNotFound?.handler).toBe('sequence');

    const actions = asArray(onNotFound?.actions);
    const toast = actions.find((a: any) => a.handler === 'toast');
    const refetch = actions.find((a: any) => a.handler === 'refetchDataSource');

    expect(toast?.params?.message).toBe('$t:admin.templates.layout_edit.file.missing_reloading');
    expect(refetch?.params?.dataSourceId).toBe('layout_files');
  });
});

describe('admin_template_layout_edit — 없는 파일 탭의 머리말·JSON 창', () => {
  /** id 로 노드를 찾는다. */
  const byId = (node: any, id: string): any => {
    if (!node || typeof node !== 'object') return null;
    if (!Array.isArray(node) && node.id === id) return node;
    for (const v of Array.isArray(node) ? node : Object.values(node)) {
      const hit = byId(v, id);
      if (hit) return hit;
    }
    return null;
  };
  /** 데이터 바인딩 엔진으로 `{{ }}` 식을 평가한다. */
  const evaluate = (expr: string, context: Record<string, unknown>): unknown =>
    dataBindingEngine.evaluateExpression(String(expr).trim().replace(/^\{\{(raw:)?|\}\}$/g, ''), context);

  /** 지워진 파일(zz-probe)의 초안 탭을 고른 상태 — 서버를 부르지 않으므로 current_layout 은 직전 파일이다. */
  const missingTab = {
    _global: { selectedNodeKind: 'layout', selectedLayoutName: 'zz-probe', editorContent: '{"layout_name":"_user_base"}' },
    current_layout: { data: { name: '_user_base', description: '그누보드7 커뮤니티', updated_at: '2026-10-01 11:47:58', content: { layout_name: '_user_base' } } },
  };
  /** 같은 파일을 불러온 평소 상태 */
  const loadedTab = {
    _global: { selectedNodeKind: 'layout', selectedLayoutName: '_user_base', editorContent: '{"layout_name":"_user_base"}' },
    current_layout: missingTab.current_layout,
  };

  /**
   * 없는 파일 탭은 일부러 서버를 부르지 않아 `current_layout` 이 직전 파일로 남는다. 머리말(설명·수정일·
   * 버전 히스토리)과 JSON 창이 그것을 그대로 그리면, 지워진 파일 이름 옆에 다른 파일의 수정일·이력·JSON 이
   * 보인다(브라우저 실측 2026-10-03: zz-probe 탭에 _user_base 의 「수정일 2026-10-01」과 JSON).
   *
   * @scenario branch=layout, buffer_state=dirty, leave=file_switch
   * @effects code_editor_hides_loaded_file_meta_for_missing_file_tab
   */
  it('선택한 파일과 불러온 파일이 다르면 설명·수정일·버전 히스토리를 감추고 JSON 창을 비운다', () => {
    const description = byId(layoutEditJson, 'layout_meta_description');
    const modifiedAt = byId(layoutEditJson, 'layout_meta_modified_at');
    const versionButton = byId(layoutEditJson, 'layout_meta_version_history');
    const jsonEditor = byId(layoutEditJson, 'json_editor');

    for (const node of [description, modifiedAt, versionButton]) {
      expect(node, '머리말 요소가 식별자로 남아 있어야 한다').toBeTruthy();
      expect(evaluate(node.if, missingTab)).toBeFalsy();
      expect(evaluate(node.if, loadedTab)).toBeTruthy();
    }

    expect(evaluate(jsonEditor.props.value, missingTab)).toBe('');
    expect(evaluate(jsonEditor.props.value, loadedTab)).toBe('{"layout_name":"_user_base"}');
  });
});

describe('admin_template_layout_edit — 없는 파일 살리기의 마무리', () => {
  const fileFormModalJson = loadLayout('partials/admin_template_layout_edit/_modal_file_form.json');

  /** 조건을 만족하는 노드를 모두 모은다. */
  const collect = (node: any, pred: (n: any) => boolean, acc: any[] = []): any[] => {
    if (!node || typeof node !== 'object') return acc;
    if (!Array.isArray(node) && pred(node)) acc.push(node);
    for (const v of Array.isArray(node) ? node : Object.values(node)) collect(v, pred, acc);
    return acc;
  };

  /**
   * «새 이름으로 저장» 은 어느 없는 파일에서 왔는지 기억하고, 생성이 성공하면 그 키를 편집기에 `retiredKey` 로
   * 돌려준다 — 편집기는 생성 성공을 모르므로 이 연결이 끊기면 살린 뒤에도 유령 탭과 초안이 남는다.
   *
   * @scenario branch=layout, buffer_state=dirty, leave=file_switch
   * @effects code_workspace_retires_rescued_missing_file
   */
  it('saveAs 가 원래 키를 기억하고 생성 성공이 그 키를 retiredKey 로 넘긴다', () => {
    const workspace = collect(layoutEditJson, (n) => n.name === 'CodeWorkspace')[0];
    expect(workspace?.props?.retiredKey).toBe('{{_global.retiredFileKey ?? \'\'}}');

    const saveAs = collect(layoutEditJson, (n) => n.event === 'onSaveAs')[0];
    const remembers = collect(saveAs, (n) => n.handler === 'setState' && n.params?.fileFormRescueFrom !== undefined)[0];
    expect(remembers?.params?.fileFormRescueFrom).toBe('{{$event.target.key ?? null}}');
    // 새 살리기는 앞선 알림을 비우고 시작한다 — 작업 공간은 알림 하나를 한 번만 처리하므로 같은 키를 다시 살릴 때도
    // 빈 값을 거쳐야 새 알림으로 받는다.
    expect(remembers?.params?.retiredFileKey).toBeNull();

    const handsBack = collect(fileFormModalJson, (n) => n.handler === 'setState' && n.params?.retiredFileKey !== undefined)[0];
    expect(handsBack?.if).toBe('{{!!_global.fileFormRescueFrom}}');
    expect(handsBack?.params?.retiredFileKey).toBe('{{_global.fileFormRescueFrom}}');
    expect(handsBack?.params?.fileFormRescueFrom).toBeNull();
  });

  /**
   * 이름을 바꾼 뒤 옛 이름의 탭이 「없는 파일」 로 남지 않는다 — 깨끗한 탭이면 이름변경 성공이 옛 키를
   * `retiredKey` 로 넘겨 거둔다. 저장하지 않은 편집이 있는 탭은 거두지 않는다(편집분을 말없이 버리지 않게).
   *
   * 실측 2026-10-06(7.1.0 전수 실측 I1): `qa736_i1/probe_d` → `probe_e` 이름변경 뒤 탭 줄에
   * 「없는 파일 qa736_i1/probe_d」 탭이 새 이름 탭과 나란히 남았다.
   *
   * @scenario branch=layout, buffer_state=clean, leave=file_switch
   * @effects code_workspace_retires_renamed_file_tab
   */
  it('이름변경 성공이 깨끗한 옛 탭 키를 retiredKey 로 넘기고, 시작할 때 앞선 알림을 비운다', () => {
    const onRename = collect(layoutEditJson, (n) => n.event === 'onRename')[0];
    const opens = collect(onRename, (n) => n.handler === 'setState' && n.params?.fileFormMode === 'rename')[0];
    expect(opens?.params?.retiredFileKey).toBeNull();

    const renameButton = collect(fileFormModalJson, (n) => n.props?.['data-testid'] === 'file-form-submit-rename')[0];
    const retires = collect(renameButton, (n) => n.handler === 'setState' && n.params?.retiredFileKey !== undefined)[0];
    expect(retires?.params?.retiredFileKey).toBe("layout:{{_global.fileFormTarget}}");

    const evaluate = (expr: string, context: Record<string, unknown>): unknown =>
      dataBindingEngine.evaluateExpression(String(expr).trim().replace(/^\{\{|\}\}$/g, ''), context);
    expect(evaluate(retires.if, { _global: { fileFormTarget: 'a/b', dirtyFileKeys: [] } })).toBeTruthy();
    expect(evaluate(retires.if, { _global: { fileFormTarget: 'a/b', dirtyFileKeys: ['layout:a/b'] } })).toBeFalsy();
  });
});

describe('admin_template_layout_edit — 트리에서 다른 파일 이름변경', () => {
  const fileFormModalJson = loadLayout('partials/admin_template_layout_edit/_modal_file_form.json');
  const collect = (node: any, pred: (n: any) => boolean, acc: any[] = []): any[] => {
    if (!node || typeof node !== 'object') return acc;
    if (!Array.isArray(node) && pred(node)) acc.push(node);
    for (const v of Array.isArray(node) ? node : Object.values(node)) collect(v, pred, acc);
    return acc;
  };
  const evaluate = (expr: string, context: Record<string, unknown>): unknown =>
    dataBindingEngine.evaluateExpression(String(expr).trim().replace(/^\{\{|\}\}$/g, ''), context);

  /**
   * 이름변경은 **대상 파일**의 잠금 번호를 보내야 한다. 지금 열린 파일의 번호를 보내면, 트리에서 다른 파일의
   * 이름을 바꿀 때 가짜 충돌(409 「다른 사용자가 먼저 저장했습니다… 현재 버전: 0, 보낸 버전: 2」)이 난다
   * (실측 2026-10-03: zz-probe2 를 연 채 zz-probe-rescue2 이름변경).
   *
   * @scenario branch=layout, buffer_state=clean, leave=file_switch
   * @effects code_editor_rename_sends_the_target_lock_version
   */
  it('이름변경 요청의 expected_lock_version 은 대상 파일의 것이다', () => {
    const rename = collect(fileFormModalJson, (n) => n.handler === 'apiCall' && String(n.target ?? '').includes('/rename'))[0];
    const lockExpr = rename?.params?.body?.expected_lock_version;
    expect(lockExpr).toBeTruthy();

    const layoutFiles = { data: [{ name: 'zz-probe2', lock_version: 2 }, { name: 'zz-probe-rescue2', lock_version: 0 }] };
    const current = { data: { name: 'zz-probe2', lock_version: 2 } };

    // 다른 파일(열려 있지 않음)의 이름변경 → 그 파일의 번호
    expect(evaluate(lockExpr, { _global: { fileFormTarget: 'zz-probe-rescue2' }, layout_files: layoutFiles, current_layout: current })).toBe(0);
    // 열린 파일의 이름변경 → 상세의 최신 번호
    expect(evaluate(lockExpr, { _global: { fileFormTarget: 'zz-probe2' }, layout_files: layoutFiles, current_layout: { data: { name: 'zz-probe2', lock_version: 5 } } })).toBe(5);
  });
});
