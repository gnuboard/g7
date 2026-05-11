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
// 버전 히스토리 버튼 테스트
// ==============================

describe('버전 히스토리 버튼', () => {
  it('sequence → refetchDataSource → openModal 패턴을 사용한다', () => {
    // 버전 히스토리 버튼 찾기 (version_history_modal 을 여는 버튼)
    const versionHistoryButton = findComponent(layoutEditJson, (n) => {
      if (!n.actions) return false;
      return n.actions.some((a: any) =>
        JSON.stringify(a).includes('version_history_modal')
      );
    });

    expect(versionHistoryButton).not.toBeNull();
    expect(versionHistoryButton.name).toBe('Button');

    const sequenceAction = versionHistoryButton.actions.find(
      (a: any) => a.handler === 'sequence'
    );
    expect(sequenceAction).toBeDefined();

    // sequence 의 actions 는 sequenceAction.actions 또는 sequenceAction.params.actions 양쪽 형태 지원
    const actions = sequenceAction.actions ?? sequenceAction.params?.actions ?? [];

    // refetchDataSource (layout_versions 갱신)
    const refetchAction = actions.find((a: any) => a.handler === 'refetchDataSource');
    expect(refetchAction).toBeDefined();
    expect(refetchAction.params.dataSourceId).toBe('layout_versions');

    // openModal
    const openModalAction = actions.find((a: any) => a.handler === 'openModal');
    expect(openModalAction).toBeDefined();
    expect(openModalAction.target).toBe('version_history_modal');
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
