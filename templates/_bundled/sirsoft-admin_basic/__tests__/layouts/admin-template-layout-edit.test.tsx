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
 */

import { describe, it, expect } from 'vitest';
import * as fs from 'fs';
import * as path from 'path';

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
  it('sequence → apiCall → openWindow 패턴을 사용한다', () => {
    // 미리보기 버튼 찾기 (preview 엔드포인트를 호출하는 버튼)
    const previewButton = findComponent(layoutEditJson, (n) => {
      if (!n.actions) return false;
      return n.actions.some((a: any) =>
        a.handler === 'sequence' &&
        JSON.stringify(a.params?.actions ?? []).includes('/preview')
      );
    });

    expect(previewButton).not.toBeNull();

    const sequenceAction = previewButton.actions.find(
      (a: any) => a.handler === 'sequence'
    );
    expect(sequenceAction).toBeDefined();

    const actions = sequenceAction.params.actions;
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
    const openWindowAction = apiCallAction.onSuccess.find(
      (a: any) => a.handler === 'openWindow'
    );
    expect(openWindowAction).toBeDefined();
    expect(openWindowAction.params.path).toContain('response.data.token');
  });

  it('apiCall body에 editorContent를 전달한다', () => {
    const previewButton = findComponent(layoutEditJson, (n) => {
      if (!n.actions) return false;
      return n.actions.some((a: any) =>
        JSON.stringify(a.params?.actions ?? []).includes('/preview')
      );
    });

    const sequenceAction = previewButton.actions.find(
      (a: any) => a.handler === 'sequence'
    );
    const apiCallAction = sequenceAction.params.actions.find(
      (a: any) => a.handler === 'apiCall'
    );

    expect(apiCallAction.params.body).toBeDefined();
    expect(JSON.stringify(apiCallAction.params.body)).toContain('editorContent');
  });

  it('type: "button" prop이 설정되어 있다', () => {
    const previewButton = findComponent(layoutEditJson, (n) => {
      if (!n.actions) return false;
      return n.actions.some((a: any) =>
        JSON.stringify(a.params?.actions ?? []).includes('/preview')
      );
    });

    expect(previewButton.props?.type).toBe('button');
  });
});

// ==============================
// 버전 히스토리 버튼 테스트
// ==============================

describe('버전 히스토리 버튼', () => {
  it('conditions 핸들러로 일반/확장 모달을 분기한다', () => {
    // 버전 히스토리 버튼 찾기 (version_history_modal 을 여는 버튼)
    const versionHistoryButton = findComponent(layoutEditJson, (n) => {
      if (!n.actions) return false;
      return n.actions.some((a: any) =>
        JSON.stringify(a).includes('version_history_modal')
      );
    });

    expect(versionHistoryButton).not.toBeNull();
    expect(versionHistoryButton.name).toBe('Button');

    // conditions 핸들러 사용 (selectedNodeKind 에 따라 분기)
    const conditionsAction = versionHistoryButton.actions.find(
      (a: any) => a.handler === 'conditions'
    );
    expect(conditionsAction).toBeDefined();
    expect(Array.isArray(conditionsAction.conditions)).toBe(true);

    // extension 분기 — extension_version_history_modal
    const extensionBranch = conditionsAction.conditions.find(
      (c: any) => c.if && c.if.includes("'extension'")
    );
    expect(extensionBranch).toBeDefined();
    expect(JSON.stringify(extensionBranch.then)).toContain('extension_version_history_modal');
    expect(JSON.stringify(extensionBranch.then)).toContain('extension_versions');

    // 기본 분기 (if 없음) — version_history_modal
    const defaultBranch = conditionsAction.conditions.find((c: any) => !c.if);
    expect(defaultBranch).toBeDefined();
    expect(JSON.stringify(defaultBranch.then)).toContain('version_history_modal');
    expect(JSON.stringify(defaultBranch.then)).toContain('layout_versions');
  });
});

// ==============================
// 버전 히스토리 모달 JSON 구조 테스트
// ==============================

describe('버전 히스토리 모달 구조', () => {
  it('올바른 모달 ID와 구조를 가진다', () => {
    expect(versionHistoryModalJson.id).toBe('version_history_modal');
    expect(versionHistoryModalJson.type).toBe('composite');
    expect(versionHistoryModalJson.name).toBe('Modal');
    expect(versionHistoryModalJson.props.size).toBe('medium');
  });

  it('layout_versions 를 반복 렌더링하는 iteration 영역이 있다', () => {
    const iterationNode = findComponent(versionHistoryModalJson, (n) =>
      Boolean(n.iteration?.source?.includes('layout_versions'))
    );
    expect(iterationNode).not.toBeNull();
    expect(iterationNode.iteration.item_var).toBe('version');
  });

  it('첫 번째 버전(idx === 0)에 최신 배지가 표시된다', () => {
    const latestBadge = findComponent(versionHistoryModalJson, (n) =>
      n.if === '{{idx === 0}}' &&
      typeof n.text === 'string' &&
      n.text.includes('latest')
    );
    expect(latestBadge).not.toBeNull();
  });

  it('첫 번째가 아닌 버전(idx !== 0)에 복원 버튼이 있다', () => {
    const restoreButton = findComponent(versionHistoryModalJson, (n) =>
      n.if === '{{idx !== 0}}' &&
      n.name === 'Button' &&
      Array.isArray(n.actions) &&
      n.actions.some((a: any) =>
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
  it('layout_extensions / current_extension / extension_versions 데이터 소스가 정의되어 있다', () => {
    const ids = layoutEditJson.data_sources.map((d: any) => d.id);
    expect(ids).toContain('layout_extensions');
    expect(ids).toContain('current_extension');
    expect(ids).toContain('extension_versions');
  });

  it('layout_extensions 는 auto_fetch + blocking 으로 트리 초기 로드된다', () => {
    const ds = layoutEditJson.data_sources.find((d: any) => d.id === 'layout_extensions');
    expect(ds.auto_fetch).toBe(true);
    expect(ds.loading_strategy).toBe('blocking');
    expect(ds.endpoint).toContain('/layout-extensions');
  });

  it('transition_overlay.wait_for 에 layout_extensions 가 포함된다', () => {
    expect(layoutEditJson.transition_overlay.wait_for).toContain('layout_extensions');
  });

  it('current_extension 은 selectedExtensionId 의존 + editorContent 로 initGlobal 한다', () => {
    const ds = layoutEditJson.data_sources.find((d: any) => d.id === 'current_extension');
    expect(ds.auto_fetch).toBe(false);
    expect(ds.endpoint).toContain('selectedExtensionId');
    expect(ds.initGlobal.key).toBe('editorContent');
  });
});

// ==============================
// 좌측 트리 — 확장 섹션 테스트
// ==============================

describe('좌측 트리 확장 섹션', () => {
  it('확장 주입 레이아웃 섹션 헤더가 존재한다', () => {
    const sectionHeader = findComponent(layoutEditJson, (n) =>
      typeof n.text === 'string' && n.text.includes('section_extensions')
    );
    expect(sectionHeader).not.toBeNull();
  });

  it('layout_extensions 를 source 별로 그룹 iteration 한다', () => {
    const groupIteration = findComponent(layoutEditJson, (n) =>
      Boolean(n.iteration?.source?.includes('layout_extensions'))
    );
    expect(groupIteration).not.toBeNull();
    expect(groupIteration.iteration.item_var).toBe('extGroup');
  });

  it('각 그룹의 extensions 를 ext 로 중첩 iteration 한다', () => {
    const extIteration = findComponent(layoutEditJson, (n) =>
      Boolean(n.iteration?.source?.includes('extGroup.extensions'))
    );
    expect(extIteration).not.toBeNull();
    expect(extIteration.iteration.item_var).toBe('ext');
  });

  it('확장 항목 클릭 시 selectedNodeKind=extension 으로 설정한다', () => {
    const extIteration = findComponent(layoutEditJson, (n) =>
      Boolean(n.iteration?.source?.includes('extGroup.extensions'))
    );
    const clickAction = extIteration.actions.find((a: any) => a.type === 'click');
    expect(JSON.stringify(clickAction)).toContain('"selectedNodeKind":"extension"');
    expect(JSON.stringify(clickAction)).toContain('selectedExtensionId');
  });

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

  it('옵션 value 에 layout:/ext: 접두사로 종류를 인코딩한다', () => {
    const richSelect = findMobileSelector();
    const options = richSelect.props.options;
    expect(options).toContain("'layout:'");
    expect(options).toContain("'ext:'");
  });

  it('현재 선택 value 가 selectedNodeKind 에 따라 접두사를 부여한다', () => {
    const richSelect = findMobileSelector();
    expect(richSelect.props.value).toContain("selectedNodeKind === 'extension'");
    expect(richSelect.props.value).toContain("'ext:'");
    expect(richSelect.props.value).toContain("'layout:'");
  });

  it('change 액션이 conditions 로 확장/일반을 분기한다', () => {
    const richSelect = findMobileSelector();
    const changeAction = richSelect.actions.find((a: any) => a.type === 'change');
    expect(changeAction.handler).toBe('conditions');

    // 확장 분기: ext: 접두사 → selectedNodeKind=extension + current_extension refetch
    const extBranch = changeAction.conditions.find(
      (c: any) => typeof c.if === 'string' && c.if.includes("'ext:'")
    );
    expect(extBranch).toBeDefined();
    const extJson = JSON.stringify(extBranch.then);
    expect(extJson).toContain('"selectedNodeKind":"extension"');
    expect(extJson).toContain('selectedExtensionId');
    expect(extJson).toContain('current_extension');
    expect(extJson).toContain('extension_versions');

    // 일반 분기(default): selectedNodeKind=layout + current_layout refetch
    const layoutBranch = changeAction.conditions.find((c: any) => c.if === undefined);
    expect(layoutBranch).toBeDefined();
    const layoutJson = JSON.stringify(layoutBranch.then);
    expect(layoutJson).toContain('"selectedNodeKind":"layout"');
    expect(layoutJson).toContain('selectedLayoutName');
    expect(layoutJson).toContain('current_layout');
  });

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
  it('올바른 모달 ID와 구조를 가진다', () => {
    expect(extensionVersionHistoryModalJson.id).toBe('extension_version_history_modal');
    expect(extensionVersionHistoryModalJson.type).toBe('composite');
    expect(extensionVersionHistoryModalJson.name).toBe('Modal');
  });

  it('extension_versions 를 반복 렌더링하는 iteration 영역이 있다', () => {
    const iterationNode = findComponent(extensionVersionHistoryModalJson, (n) =>
      Boolean(n.iteration?.source?.includes('extension_versions'))
    );
    expect(iterationNode).not.toBeNull();
    expect(iterationNode.iteration.item_var).toBe('version');
  });

  it('복원 버튼이 확장 버전 restore 엔드포인트를 호출한다', () => {
    const restoreButton = findComponent(extensionVersionHistoryModalJson, (n) =>
      n.if === '{{idx !== 0}}' &&
      n.name === 'Button' &&
      Array.isArray(n.actions) &&
      n.actions.some((a: any) => JSON.stringify(a).includes('/layout-extensions/'))
    );
    expect(restoreButton).not.toBeNull();
    expect(JSON.stringify(restoreButton.actions)).toContain('/restore');
  });
});

// ==============================
// 확장 미리보기 레이아웃 선택 모달 테스트
// ==============================

describe('확장 미리보기 레이아웃 선택 모달', () => {
  it('올바른 모달 ID를 가진다', () => {
    expect(extensionPreviewLayoutModalJson.id).toBe('extension_preview_layout_modal');
    expect(extensionPreviewLayoutModalJson.name).toBe('Modal');
  });

  it('미리보기 버튼이 layout-extensions preview 엔드포인트를 preview_layout 과 함께 호출한다', () => {
    const previewButton = findComponent(extensionPreviewLayoutModalJson, (n) =>
      n.name === 'Button' &&
      Array.isArray(n.actions) &&
      n.actions.some((a: any) => a.handler === 'apiCall' && String(a.target).includes('/preview'))
    );
    expect(previewButton).not.toBeNull();
    const apiCall = previewButton.actions.find((a: any) => a.handler === 'apiCall');
    expect(JSON.stringify(apiCall.params.body)).toContain('preview_layout');
  });
});

// ==============================
// modals 섹션 — 확장 모달 등록 테스트
// ==============================

describe('modals 섹션 — 확장 모달', () => {
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
  it('확장 저장 버튼이 layout-extensions PUT 엔드포인트를 호출한다', () => {
    const extSaveButton = findComponent(layoutEditJson, (n) =>
      n.name === 'Button' &&
      typeof n.if === 'string' &&
      n.if.includes("selectedNodeKind === 'extension'") &&
      Array.isArray(n.actions) &&
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

  it('데스크탑 파일 항목 클릭 시 replaceUrl 로 file.route_path 를 ?route= 에 반영한다', () => {
    // 좌측 트리 레이아웃 파일 iteration (file_var) 의 click 시퀀스
    const fileIteration = findComponent(layoutEditJson, (n) =>
      Boolean(n.iteration?.source?.includes('layout_files')) &&
      n.iteration?.item_var === 'file'
    );
    expect(fileIteration).not.toBeNull();

    const clickAction = fileIteration.actions.find((a: any) => a.type === 'click');
    const replaceUrl = clickAction.actions.find((a: any) => a.handler === 'replaceUrl');
    expect(replaceUrl).toBeDefined();
    expect(replaceUrl.params.query.route).toContain('file.route_path');
  });

  it('모바일 셀렉터 레이아웃 선택 시 replaceUrl 로 route_path 를 반영한다', () => {
    const wrapper = findComponent(layoutEditJson, (n) => n.id === 'mobile_file_selector');
    const richSelect = findComponent(wrapper, (n) => n.name === 'RichSelect');
    const changeAction = richSelect.actions.find((a: any) => a.type === 'change');
    const layoutBranch = changeAction.conditions.find((c: any) => c.if === undefined);
    const replaceUrl = layoutBranch.then.find((a: any) => a.handler === 'replaceUrl');
    expect(replaceUrl).toBeDefined();
    expect(replaceUrl.params.query.route).toContain('route_path');
  });

  it('확장 선택 시 replaceUrl 로 ?route= 를 비운다 (확장은 라우트 없음)', () => {
    const extIteration = findComponent(layoutEditJson, (n) =>
      Boolean(n.iteration?.source?.includes('extGroup.extensions'))
    );
    const clickAction = extIteration.actions.find((a: any) => a.type === 'click');
    const replaceUrl = clickAction.actions.find((a: any) => a.handler === 'replaceUrl');
    expect(replaceUrl).toBeDefined();
    expect(replaceUrl.params.query.route).toBe('');
  });
});
