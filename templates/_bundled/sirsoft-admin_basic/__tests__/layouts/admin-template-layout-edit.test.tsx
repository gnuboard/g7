/**
 * @file admin-template-layout-edit.test.tsx
 * @description 템플릿 레이아웃 편집 페이지 테스트
 *
 * 테스트 대상:
 * - admin_template_layout_edit.json: 미리보기 버튼 액션 구조
 * - partials/admin_template_layout_edit/_modal_template_changelog.json: CHANGELOG 모달
 * - 어빌리티 기반 조건부 렌더링 (저장 버튼)
 *
 * 검증 항목:
 * - 미리보기 버튼이 sequence → apiCall → openWindow 패턴 사용
 * - CHANGELOG 버튼이 sequence → setState → openModal → apiCall 패턴 사용
 * - CHANGELOG 모달 JSON 구조 (로딩, 빈 상태, 데이터 표시)
 * - 저장 버튼에 can_update 어빌리티 조건 존재
 * - modals 섹션에 CHANGELOG 모달 partial 등록
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
const changelogModalJson = loadLayout('partials/admin_template_layout_edit/_modal_template_changelog.json');

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
// CHANGELOG 버튼 테스트
// ==============================

describe('CHANGELOG 버튼', () => {
  it('sequence → setState → openModal → apiCall 패턴을 사용한다', () => {
    // CHANGELOG 버튼 찾기 (template_changelog_modal을 여는 버튼)
    const changelogButton = findComponent(layoutEditJson, (n) => {
      if (!n.actions) return false;
      return n.actions.some((a: any) =>
        JSON.stringify(a).includes('template_changelog_modal')
      );
    });

    expect(changelogButton).not.toBeNull();
    expect(changelogButton.name).toBe('Button');

    const sequenceAction = changelogButton.actions.find(
      (a: any) => a.handler === 'sequence'
    );
    expect(sequenceAction).toBeDefined();

    const actions = sequenceAction.params.actions;

    // setState (loading 상태 설정)
    const setStateAction = actions.find((a: any) => a.handler === 'setState');
    expect(setStateAction).toBeDefined();
    expect(setStateAction.params.loadingChangelog).toBe(true);

    // openModal
    const openModalAction = actions.find((a: any) => a.handler === 'openModal');
    expect(openModalAction).toBeDefined();
    expect(openModalAction.target).toBe('template_changelog_modal');

    // apiCall (changelog 조회)
    const apiCallAction = actions.find((a: any) => a.handler === 'apiCall');
    expect(apiCallAction).toBeDefined();
    expect(apiCallAction.params.method).toBe('GET');
    expect(apiCallAction.target).toContain('/changelog');
    expect(apiCallAction.auth_required).toBe(true);
  });

  it('apiCall onSuccess에서 templateChangelog와 loadingChangelog를 설정한다', () => {
    const changelogButton = findComponent(layoutEditJson, (n) => {
      if (!n.actions) return false;
      return n.actions.some((a: any) =>
        JSON.stringify(a).includes('template_changelog_modal')
      );
    });

    const sequenceAction = changelogButton.actions.find(
      (a: any) => a.handler === 'sequence'
    );
    const apiCallAction = sequenceAction.params.actions.find(
      (a: any) => a.handler === 'apiCall'
    );

    const onSuccessSetState = apiCallAction.onSuccess.find(
      (a: any) => a.handler === 'setState'
    );
    expect(onSuccessSetState).toBeDefined();
    expect(onSuccessSetState.params).toHaveProperty('templateChangelog');
    expect(onSuccessSetState.params.loadingChangelog).toBe(false);
  });
});

// ==============================
// CHANGELOG 모달 JSON 구조 테스트
// ==============================

describe('CHANGELOG 모달 구조', () => {
  it('올바른 모달 ID와 구조를 가진다', () => {
    expect(changelogModalJson.id).toBe('template_changelog_modal');
    expect(changelogModalJson.type).toBe('composite');
    expect(changelogModalJson.name).toBe('Modal');
    expect(changelogModalJson.props.size).toBe('lg');
  });

  it('로딩 상태를 표시하는 조건부 영역이 있다', () => {
    const loadingDiv = changelogModalJson.children.find(
      (c: any) => c.if && c.if.includes('loadingChangelog') && !c.if.includes('!')
    );
    expect(loadingDiv).toBeDefined();
  });

  it('빈 상태를 표시하는 조건부 영역이 있다', () => {
    const emptyDiv = changelogModalJson.children.find(
      (c: any) => c.if && c.if.includes('templateChangelog.length === 0')
    );
    expect(emptyDiv).toBeDefined();
  });

  it('changelog 목록을 반복 렌더링하는 영역이 있다', () => {
    const listDiv = changelogModalJson.children.find(
      (c: any) => c.if && c.if.includes('templateChangelog.length > 0')
    );
    expect(listDiv).toBeDefined();

    // iteration이 존재하는 자식 확인
    const iterationChild = findComponent(listDiv, (n) => n.iteration?.source?.includes('templateChangelog'));
    expect(iterationChild).not.toBeNull();
  });

  it('is_partial 메타데이터가 설정되어 있다', () => {
    expect(changelogModalJson.meta?.is_partial).toBe(true);
  });
});

// ==============================
// modals 섹션 테스트
// ==============================

describe('modals 섹션', () => {
  it('template_changelog 모달 partial이 등록되어 있다', () => {
    expect(layoutEditJson.modals).toBeDefined();
    expect(Array.isArray(layoutEditJson.modals)).toBe(true);

    const changelogModal = layoutEditJson.modals.find(
      (m: any) => m.partial?.includes('_modal_template_changelog')
    );
    expect(changelogModal).toBeDefined();
  });

  it('version_history 모달 partial도 등록되어 있다', () => {
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
