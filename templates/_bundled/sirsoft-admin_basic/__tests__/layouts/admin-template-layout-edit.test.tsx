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

// ==============================
// 헬퍼: JSON 구조 내 컴포넌트 검색
// ==============================

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

describe('미리보기 버튼', () => {
  /** @effects editor_preview_uses_sequence_api_call_then_open_window */
  it('sequence → apiCall → openWindow 패턴을 사용한다', () => {
    // 미리보기 버튼 찾기 (preview 엔드포인트를 호출하는 버튼)
    const previewButton = findComponent(layoutEditJson, (n) => {
      if (!n.actions) return false;
      return asArray(n?.actions).some((a: any) =>
        a.handler === 'sequence' &&
        JSON.stringify(callbacksOf(a, 'actions')).includes('/preview')
      );
    });

    expect(previewButton).not.toBeNull();

    const sequenceAction = asArray(previewButton?.actions).find(
      (a: any) => a.handler === 'sequence'
    );
    expect(sequenceAction).toBeDefined();

    const actions = callbacksOf(sequenceAction, 'actions');
    expect(actions).toBeDefined();
    expect(actions.length).toBeGreaterThanOrEqual(1);

    // apiCall 액션 찾기
    const apiCallAction = actions.find((a: any) => a.handler === 'apiCall');
    expect(apiCallAction).toBeDefined();
    expect(apiCallAction.params.method).toBe('POST');
    expect(apiCallAction.target).toContain('/preview');
    expect(apiCallAction.auth_required).toBe(true);

    // onSuccess에 openWindow 존재
    expect(apiCallAction.onSuccess).toBeDefined();
    const openWindowAction = asArray(apiCallAction?.onSuccess).find(
      (a: any) => a.handler === 'openWindow'
    );
    expect(openWindowAction).toBeDefined();
    expect(openWindowAction.params.path).toContain('response.data.token');
  });

  /**
   * @effects code_editor_saves_markup_format
   */
  it('apiCall body에 HTML 버퍼(editorMarkup)를 format=markup 으로 전달한다', () => {
    const previewButton = findComponent(layoutEditJson, (n) => {
      if (!n.actions) return false;
      return asArray(n?.actions).some((a: any) =>
        JSON.stringify(callbacksOf(a, 'actions')).includes('/preview')
      );
    });

    const sequenceAction = asArray(previewButton?.actions).find(
      (a: any) => a.handler === 'sequence'
    );
    const apiCallAction = callbacksOf(sequenceAction, 'actions').find(
      (a: any) => a.handler === 'apiCall'
    );

    expect(apiCallAction.params.body).toBeDefined();
    // 편집기 정본이 HTML 이다 (#476) — 미리보기도 저장과 같은 버퍼를 같은 형식으로 보낸다.
    expect(JSON.stringify(apiCallAction.params.body)).toContain('editorMarkup');
    expect(apiCallAction.params.body.format).toBe('markup');
  });

  /** @effects editor_buttons_declare_button_type_so_forms_do_not_submit */
  it('type: "button" prop이 설정되어 있다', () => {
    const previewButton = findComponent(layoutEditJson, (n) => {
      if (!n.actions) return false;
      return asArray(n?.actions).some((a: any) =>
        JSON.stringify(callbacksOf(a, 'actions')).includes('/preview')
      );
    });

    expect(previewButton.props?.type).toBe('button');
  });
});

// ==============================
// 버전 히스토리 버튼 테스트
// ==============================

describe('버전 히스토리 버튼', () => {
  /** @effects version_history_button_branches_layout_and_extension_by_conditions */
  it('conditions 핸들러로 일반/확장 모달을 분기한다', () => {
    // 버전 히스토리 버튼 찾기 (version_history_modal 을 여는 버튼)
    const versionHistoryButton = findComponent(layoutEditJson, (n) => {
      if (!n.actions) return false;
      return asArray(n?.actions).some((a: any) =>
        JSON.stringify(a).includes('version_history_modal')
      );
    });

    expect(versionHistoryButton).not.toBeNull();
    expect(versionHistoryButton.name).toBe('Button');

    // conditions 핸들러 사용 (selectedNodeKind 에 따라 분기)
    const conditionsAction = asArray(versionHistoryButton?.actions).find(
      (a: any) => a.handler === 'conditions'
    );
    expect(conditionsAction).toBeDefined();
    expect(asArray(conditionsAction?.conditions).length > 0).toBe(true);

    // extension 분기 — extension_version_history_modal
    const extensionBranch = asArray(conditionsAction?.conditions).find(
      (c: any) => c.if && c.if.includes("'extension'")
    );
    expect(extensionBranch).toBeDefined();
    expect(JSON.stringify(extensionBranch.then)).toContain('extension_version_history_modal');
    expect(JSON.stringify(extensionBranch.then)).toContain('extension_versions');

    // 기본 분기 (if 없음) — version_history_modal
    const defaultBranch = asArray(conditionsAction?.conditions).find((c: any) => !c.if);
    expect(defaultBranch).toBeDefined();
    expect(JSON.stringify(defaultBranch.then)).toContain('version_history_modal');
    expect(JSON.stringify(defaultBranch.then)).toContain('layout_versions');
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
  /** @effects extension_branch_declares_its_three_data_sources */
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
  it('current_extension 은 selectedExtensionId 의존 + with_markup + editorContent·editorMarkup 로 initGlobal 한다', () => {
    const ds = layoutEditJson.data_sources.find((d: any) => d.id === 'current_extension');
    expect(ds.auto_fetch).toBe(false);
    expect(ds.endpoint).toContain('selectedExtensionId');
    expect(ds.endpoint).toContain('with_markup=1');
    // 편집기 정본이 HTML 이라 JSON 뷰(editorContent)와 HTML 버퍼(editorMarkup)를 함께 올린다 (#476)
    const keys = ds.initGlobal.map((m: any) => m.key);
    expect(keys).toEqual(expect.arrayContaining(['editorContent', 'editorMarkup']));
  });
});

// ==============================
// 좌측 트리 — 확장 섹션 테스트
// ==============================

describe('좌측 트리 확장 섹션', () => {
  /** @effects extension_section_header_exists_in_the_file_tree */
  it('확장 주입 레이아웃 섹션 헤더가 존재한다', () => {
    const sectionHeader = findComponent(layoutEditJson, (n) =>
      typeof n.text === 'string' && n.text.includes('section_extensions')
    );
    expect(sectionHeader).not.toBeNull();
  });

  /** @effects extension_tree_groups_by_source */
  it('layout_extensions 를 source 별로 그룹 iteration 한다', () => {
    const groupIteration = findComponent(layoutEditJson, (n) =>
      Boolean(n.iteration?.source?.includes('layout_extensions'))
    );
    expect(groupIteration).not.toBeNull();
    expect(groupIteration.iteration.item_var).toBe('extGroup');
  });

  /** @effects extension_tree_nests_each_group_items */
  it('각 그룹의 extensions 를 ext 로 중첩 iteration 한다', () => {
    const extIteration = findComponent(layoutEditJson, (n) =>
      Boolean(n.iteration?.source?.includes('extGroup.extensions'))
    );
    expect(extIteration).not.toBeNull();
    expect(extIteration.iteration.item_var).toBe('ext');
  });

  /** @effects clicking_an_extension_switches_the_selected_node_kind */
  it('확장 항목 클릭 시 selectedNodeKind=extension 으로 설정한다', () => {
    const extIteration = findComponent(layoutEditJson, (n) =>
      Boolean(n.iteration?.source?.includes('extGroup.extensions'))
    );
    const clickAction = asArray(extIteration?.actions).find((a: any) => a.type === 'click');
    expect(JSON.stringify(clickAction)).toContain('"selectedNodeKind":"extension"');
    expect(JSON.stringify(clickAction)).toContain('selectedExtensionId');
  });

  /** @effects group_header_icon_distinguishes_plugin_from_module */
  it('그룹 헤더 아이콘이 plugin/module 출처를 구분한다', () => {
    // 오버라이드 행도 override_target 의 모듈/플러그인 그룹에 묶이므로
    // 그룹 source_type 은 plugin / module 두 가지.
    const groupIcon = findComponent(layoutEditJson, (n) =>
      n.name === 'Icon' &&
      typeof n.props?.name === 'string' &&
      n.props.name.includes('extGroup.source_type')
    );
    expect(groupIcon).not.toBeNull();
    expect(groupIcon.props.name).toContain("=== 'plugin'");
    expect(groupIcon.props.name).toContain('fa-plug');
    expect(groupIcon.props.name).toContain('fa-puzzle-piece');
  });

  /** @effects override_extension_item_shows_the_override_badge */
  it('오버라이드 확장 항목에 is_override 뱃지를 표시한다', () => {
    // 템플릿 오버라이드 행은 출처 기준으로 묶이되, ext.is_override 로
    // 오버라이드 상황을 별도 뱃지로 구분 표시한다.
    const overrideBadge = findComponent(layoutEditJson, (n) =>
      n.name === 'Span' &&
      n.if === '{{ext.is_override}}' &&
      typeof n.text === 'string' &&
      n.text.includes('extension_override_badge')
    );
    expect(overrideBadge).not.toBeNull();
  });
});

// ==============================
// 모바일 셀렉터 테스트
// ==============================

describe('모바일 파일 셀렉터', () => {
  function findMobileSelector(): any {
    const wrapper = findComponent(layoutEditJson, (n) => n.id === 'mobile_file_selector');
    expect(wrapper).not.toBeNull();
    return findComponent(wrapper, (n) => n.name === 'RichSelect');
  }

  /** @effects mobile_selector_lists_both_layouts_and_extensions */
  it('모바일 셀렉터가 일반 레이아웃과 확장을 모두 옵션에 포함한다', () => {
    // 데스크탑 트리(일반 + 확장 2섹션)와 동등하게, 모바일 RichSelect 도
    // layout_files 와 layout_extensions 양쪽을 옵션으로 노출해야 한다.
    const richSelect = findMobileSelector();
    const options = richSelect.props.options;
    expect(options).toContain('layout_files');
    expect(options).toContain('layout_extensions');
    // 확장 그룹 배열을 flatMap 으로 평탄화
    expect(options).toContain('flatMap');
  });

  /** @effects mobile_selector_encodes_kind_with_a_value_prefix */
  it('옵션 value 에 layout:/ext: 접두사로 종류를 인코딩한다', () => {
    const richSelect = findMobileSelector();
    const options = richSelect.props.options;
    expect(options).toContain("'layout:'");
    expect(options).toContain("'ext:'");
  });

  /** @effects mobile_selector_current_value_carries_the_matching_prefix */
  it('현재 선택 value 가 selectedNodeKind 에 따라 접두사를 부여한다', () => {
    const richSelect = findMobileSelector();
    expect(richSelect.props.value).toContain("selectedNodeKind === 'extension'");
    expect(richSelect.props.value).toContain("'ext:'");
    expect(richSelect.props.value).toContain("'layout:'");
  });

  /** @effects mobile_selector_change_branches_by_conditions */
  it('change 액션이 conditions 로 확장/일반을 분기한다', () => {
    const richSelect = findMobileSelector();
    const changeAction = asArray(richSelect?.actions).find((a: any) => a.type === 'change');
    expect(changeAction.handler).toBe('conditions');

    // 확장 분기: ext: 접두사 → selectedNodeKind=extension + current_extension refetch
    const extBranch = asArray(changeAction?.conditions).find(
      (c: any) => typeof c.if === 'string' && c.if.includes("'ext:'")
    );
    expect(extBranch).toBeDefined();
    const extJson = JSON.stringify(extBranch.then);
    expect(extJson).toContain('"selectedNodeKind":"extension"');
    expect(extJson).toContain('selectedExtensionId');
    expect(extJson).toContain('current_extension');
    expect(extJson).toContain('extension_versions');

    // 일반 분기(default): selectedNodeKind=layout + current_layout refetch
    const layoutBranch = asArray(changeAction?.conditions).find((c: any) => c.if === undefined);
    expect(layoutBranch).toBeDefined();
    const layoutJson = JSON.stringify(layoutBranch.then);
    expect(layoutJson).toContain('"selectedNodeKind":"layout"');
    expect(layoutJson).toContain('selectedLayoutName');
    expect(layoutJson).toContain('current_layout');
  });

  /** @effects mobile_selector_item_shows_the_override_badge */
  it('모바일 셀렉터 항목에 is_override 뱃지를 표시한다', () => {
    const wrapper = findComponent(layoutEditJson, (n) => n.id === 'mobile_file_selector');
    const overrideBadge = findComponent(wrapper, (n) =>
      n.name === 'Span' &&
      n.if === '{{item.is_override}}' &&
      typeof n.text === 'string' &&
      n.text.includes('extension_override_badge')
    );
    expect(overrideBadge).not.toBeNull();
  });
});

// ==============================
// 확장 버전 히스토리 모달 구조 테스트
// ==============================

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
  it('확장 저장 버튼이 layout-extensions PUT 엔드포인트를 호출한다', () => {
    const extSaveButton = findComponent(layoutEditJson, (n) =>
      n.name === 'Button' &&
      typeof n.if === 'string' &&
      n.if.includes("selectedNodeKind === 'extension'") &&
      asArray(n?.actions).length > 0 &&
      JSON.stringify(n.actions).includes('/layout-extensions/') &&
      JSON.stringify(n.actions).includes('"method":"PUT"')
    );
    expect(extSaveButton).not.toBeNull();
    // 저장 성공 후 current_extension / layout_extensions 양쪽 refetch
    expect(JSON.stringify(extSaveButton.actions)).toContain('current_extension');
    expect(JSON.stringify(extSaveButton.actions)).toContain('layout_extensions');
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
  it('데스크탑 파일 항목 클릭 시 replaceUrl 로 file.route_path 를 ?route= 에 반영한다', () => {
    // 좌측 트리 레이아웃 파일 iteration (file_var) 의 click 시퀀스
    const fileIteration = findComponent(layoutEditJson, (n) =>
      Boolean(n.iteration?.source?.includes('layout_files')) &&
      n.iteration?.item_var === 'file'
    );
    expect(fileIteration).not.toBeNull();

    const clickAction = asArray(fileIteration?.actions).find((a: any) => a.type === 'click');
    const replaceUrl = asArray(clickAction?.actions).find((a: any) => a.handler === 'replaceUrl');
    expect(replaceUrl).toBeDefined();
    expect(replaceUrl.params.query.route).toContain('file.route_path');
  });

  /** @effects mobile_selector_reflects_the_route_path_with_replace_url */
  it('모바일 셀렉터 레이아웃 선택 시 replaceUrl 로 route_path 를 반영한다', () => {
    const wrapper = findComponent(layoutEditJson, (n) => n.id === 'mobile_file_selector');
    const richSelect = findComponent(wrapper, (n) => n.name === 'RichSelect');
    const changeAction = asArray(richSelect?.actions).find((a: any) => a.type === 'change');
    const layoutBranch = asArray(changeAction?.conditions).find((c: any) => c.if === undefined);
    const replaceUrl = asArray(layoutBranch?.then).find((a: any) => a.handler === 'replaceUrl');
    expect(replaceUrl).toBeDefined();
    expect(replaceUrl.params.query.route).toContain('route_path');
  });

  /** @effects code_editor_extension_select_clears_route_query */
  it('확장 선택 시 replaceUrl 로 ?route= 를 비운다 (확장은 라우트 없음)', () => {
    const extIteration = findComponent(layoutEditJson, (n) =>
      Boolean(n.iteration?.source?.includes('extGroup.extensions'))
    );
    const clickAction = asArray(extIteration?.actions).find((a: any) => a.type === 'click');
    const replaceUrl = asArray(clickAction?.actions).find((a: any) => a.handler === 'replaceUrl');
    expect(replaceUrl).toBeDefined();
    expect(replaceUrl.params.query.route).toBe('');
  });
});
