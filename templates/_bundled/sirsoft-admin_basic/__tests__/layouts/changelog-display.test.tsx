/**
 * @file changelog-display.test.tsx
 * @description Changelog 표시 레이아웃 테스트
 *
 * 테스트 대상:
 * - partials/admin_module_list/_modal_update.json: 업데이트 모달 인라인 changelog
 * - partials/admin_module_list/_modal_detail.json: 상세 모달 changelog 섹션
 * - partials/admin_plugin_list/_modal_update.json: 플러그인 업데이트 모달 인라인 changelog
 * - partials/admin_plugin_list/_modal_detail.json: 플러그인 상세 모달 changelog 섹션
 * - partials/admin_template_list/_modal_update.json: 템플릿 업데이트 모달 인라인 changelog
 * - partials/admin_template_list/_modal_detail.json: 템플릿 상세 모달 changelog 섹션
 *
 * 검증 항목:
 * - 업데이트 모달 인라인 changelog JSON 구조 (if 조건, iteration, 텍스트 바인딩)
 * - 업데이트 모달 changelog 없을 때 fallback (github_changelog_url 외부 링크)
 * - 상세 모달 changelog 섹션 JSON 구조 (empty state, 리스트 구조)
 * - 모달 크기가 medium으로 설정되어 있는지 확인
 * - 메인 리스트에서 changelog API fetch 액션 존재 여부
 * - 전역 상태 변수명 (moduleChangelog, pluginChangelog, templateChangelog, updateChangelog)
 * - changelog 데이터를 사용한 실제 렌더링 검증
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import * as fs from 'fs';
import * as path from 'path';

// ==============================
// 레이아웃 JSON 파일 로드 헬퍼
// ==============================

const PARTIALS_BASE = path.resolve(
  __dirname,
  '../../layouts/partials',
);

const LAYOUTS_BASE = path.resolve(
  __dirname,
  '../../layouts',
);

function loadLayout(relativePath: string): any {
  const fullPath = path.resolve(LAYOUTS_BASE, relativePath);
  return JSON.parse(fs.readFileSync(fullPath, 'utf-8'));
}

function loadPartial(relativePath: string): any {
  const fullPath = path.resolve(PARTIALS_BASE, relativePath);
  return JSON.parse(fs.readFileSync(fullPath, 'utf-8'));
}

// ==============================
// 테스트용 컴포넌트 정의
// ==============================

const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestButton: React.FC<{
  type?: string;
  className?: string;
  disabled?: boolean;
  children?: React.ReactNode;
  onClick?: () => void;
  'data-testid'?: string;
}> = ({ type, className, disabled, children, onClick, 'data-testid': testId }) => (
  <button
    type={type as any}
    className={className}
    disabled={disabled}
    onClick={onClick}
    data-testid={testId}
  >
    {children}
  </button>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestP: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);

const TestIcon: React.FC<{
  name?: string;
  size?: string;
  className?: string;
}> = ({ name, size, className }) => (
  <i className={`icon-${name} ${className || ''}`} data-testid={`icon-${name}`} data-icon={name} data-size={size} />
);

const TestA: React.FC<{
  href?: string;
  target?: string;
  className?: string;
  children?: React.ReactNode;
}> = ({ href, target, className, children }) => (
  <a href={href} target={target} className={className} data-testid="link">{children}</a>
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// ==============================
// 레지스트리 설정
// ==============================

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// ==============================
// 공통 번역 키
// ==============================

const translations = {
  admin: {
    modules: {
      modals: {
        update_changelog_title: '이 업데이트의 변경사항',
        changelog: '변경 내역',
        no_changelog: '변경 내역 정보가 없습니다.',
        view_changelog: '변경사항 보기',
      },
    },
    plugins: {
      modals: {
        update_changelog_title: '이 업데이트의 변경사항',
        changelog: '변경 내역',
        no_changelog: '변경 내역 정보가 없습니다.',
        view_changelog: '변경사항 보기',
      },
    },
    templates: {
      modals: {
        update_changelog_title: '이 업데이트의 변경사항',
        changelog: '변경 내역',
        no_changelog: '변경 내역 정보가 없습니다.',
        view_changelog: '변경사항 보기',
      },
    },
  },
};

// ==============================
// 샘플 changelog 데이터
// ==============================

const sampleChangelog = [
  {
    version: '1.1.0',
    date: '2026-02-25',
    categories: [
      {
        name: 'Added',
        items: ['새로운 기능 A 추가', '새로운 기능 B 추가'],
      },
      {
        name: 'Fixed',
        items: ['버그 C 수정'],
      },
    ],
  },
];

const multiVersionChangelog = [
  {
    version: '1.2.0',
    date: '2026-02-26',
    categories: [
      {
        name: 'Added',
        items: ['기능 X 추가'],
      },
    ],
  },
  {
    version: '1.1.0',
    date: '2026-02-25',
    categories: [
      {
        name: 'Changed',
        items: ['기존 기능 Y 변경'],
      },
      {
        name: 'Fixed',
        items: ['버그 Z 수정', '버그 W 수정'],
      },
    ],
  },
];

// ==============================
// JSON 트리 탐색 헬퍼
// ==============================

/**
 * 컴포넌트 트리에서 특정 조건을 만족하는 노드를 찾습니다.
 */
function findInTree(nodes: any[], predicate: (node: any) => boolean): any | undefined {
  for (const node of nodes) {
    if (predicate(node)) return node;
    if (node.children) {
      const found = findInTree(node.children, predicate);
      if (found) return found;
    }
  }
  return undefined;
}

/**
 * 컴포넌트 트리에서 모든 매칭 노드를 찾습니다.
 */
function findAllInTree(nodes: any[], predicate: (node: any) => boolean): any[] {
  const results: any[] = [];
  for (const node of nodes) {
    if (predicate(node)) results.push(node);
    if (node.children) {
      results.push(...findAllInTree(node.children, predicate));
    }
  }
  return results;
}

/**
 * 액션 트리에서 changelog API 호출을 찾습니다.
 */
function findChangelogApiCalls(nodes: any[]): any[] {
  const results: any[] = [];

  function traverseActions(action: any) {
    if (action.handler === 'apiCall' && action.target?.includes('/changelog')) {
      results.push(action);
    }
    if (action.handler === 'sequence' && action.actions) {
      for (const sub of action.actions) {
        traverseActions(sub);
      }
    }
    if (action.onSuccess) {
      for (const sub of action.onSuccess) {
        traverseActions(sub);
      }
    }
    if (action.onError) {
      for (const sub of action.onError) {
        traverseActions(sub);
      }
    }
    // switch handler with cases
    if (action.handler === 'switch' && action.cases) {
      for (const caseAction of Object.values(action.cases) as any[]) {
        traverseActions(caseAction);
      }
    }
  }

  function traverseComponents(comps: any[]) {
    for (const comp of comps) {
      if (comp.actions) {
        for (const action of comp.actions) {
          traverseActions(action);
        }
      }
      if (comp.children) {
        traverseComponents(comp.children);
      }
      // DataGrid: cellChildren 안에 중첩된 액션 탐색
      if (comp.cellChildren) {
        traverseComponents(comp.cellChildren);
      }
      // DataGrid columns/cardColumns 내부 탐색
      if (comp.columns) {
        traverseComponents(comp.columns);
      }
      if (comp.props?.columns) {
        traverseComponents(comp.props.columns);
      }
      if (comp.props?.cardColumns) {
        traverseComponents(comp.props.cardColumns);
      }
      // slots 내부 탐색
      if (comp.slots) {
        for (const slotComps of Object.values(comp.slots)) {
          if (Array.isArray(slotComps)) {
            traverseComponents(slotComps);
          }
        }
      }
    }
  }

  traverseComponents(nodes);
  return results;
}

// ==============================
// 0. 업데이트 모달 크기 검증
// ==============================

describe('Changelog - 업데이트 모달 크기 검증', () => {
  it('모듈 업데이트 모달 크기가 medium으로 설정되어 있다', () => {
    const layout = loadPartial('admin_module_list/_modal_update.json');
    expect(layout.props.size).toBe('medium');
  });

  it('플러그인 업데이트 모달 크기가 medium으로 설정되어 있다', () => {
    const layout = loadPartial('admin_plugin_list/_modal_update.json');
    expect(layout.props.size).toBe('medium');
  });

  it('템플릿 업데이트 모달 크기가 medium으로 설정되어 있다', () => {
    const layout = loadPartial('admin_template_list/_modal_update.json');
    expect(layout.props.size).toBe('medium');
  });
});

// ==============================
// 1. 업데이트 모달 - 인라인 changelog 구조 검증
// ==============================

describe('Changelog - 업데이트 모달 인라인 changelog 구조', () => {
  let moduleUpdateModal: any;
  let pluginUpdateModal: any;
  let templateUpdateModal: any;

  beforeEach(() => {
    moduleUpdateModal = loadPartial('admin_module_list/_modal_update.json');
    pluginUpdateModal = loadPartial('admin_plugin_list/_modal_update.json');
    templateUpdateModal = loadPartial('admin_template_list/_modal_update.json');
  });

  describe('모듈 업데이트 모달', () => {
    it('인라인 changelog 섹션이 updateChangelog 조건부로 존재한다', () => {
      const changelogSection = findInTree(moduleUpdateModal.children, (node: any) =>
        node.if?.includes('_global.updateChangelog') && node.if?.includes('length > 0'),
      );
      expect(changelogSection).toBeDefined();
    });

    it('changelog 타이틀이 올바른 i18n 키를 사용한다', () => {
      const changelogSection = findInTree(moduleUpdateModal.children, (node: any) =>
        node.if?.includes('_global.updateChangelog') && node.if?.includes('length > 0'),
      );
      const titleDiv = findInTree(changelogSection.children, (node: any) =>
        node.text === '$t:admin.modules.modals.update_changelog_title',
      );
      expect(titleDiv).toBeDefined();
    });

    it('changelog 엔트리를 _global.updateChangelog로 iteration한다', () => {
      const iterationDiv = findInTree(moduleUpdateModal.children, (node: any) =>
        node.iteration?.source === '_global.updateChangelog',
      );
      expect(iterationDiv).toBeDefined();
      expect(iterationDiv.iteration.item_var).toBe('entry');
    });

    it('카테고리를 entry.categories로 중첩 iteration한다', () => {
      const categoryIteration = findInTree(moduleUpdateModal.children, (node: any) =>
        node.iteration?.source === 'entry.categories',
      );
      expect(categoryIteration).toBeDefined();
      expect(categoryIteration.iteration.item_var).toBe('category');
    });

    it('개별 항목을 category.items로 3단 중첩 iteration한다', () => {
      const itemIteration = findInTree(moduleUpdateModal.children, (node: any) =>
        node.iteration?.source === 'category.items',
      );
      expect(itemIteration).toBeDefined();
      expect(itemIteration.iteration.item_var).toBe('changeItem');
    });

    it('changelog 항목에 불릿(•)이 포함된 텍스트 바인딩이 있다', () => {
      const bulletItem = findInTree(moduleUpdateModal.children, (node: any) =>
        node.text === '• {{changeItem}}',
      );
      expect(bulletItem).toBeDefined();
    });

    it('버전과 날짜 텍스트 바인딩이 올바르다', () => {
      // 버전 노드: text 가 'v{{entry.version}}' 인 Span
      const versionText = findInTree(moduleUpdateModal.children, (node: any) =>
        node.text === 'v{{entry.version}}',
      );
      expect(versionText).toBeDefined();
      // 날짜 노드: if 가 entry.date 이고 text 가 {{entry.date}} 인 Span
      const dateText = findInTree(moduleUpdateModal.children, (node: any) =>
        node.text === '{{entry.date}}' && node.if?.includes('entry.date'),
      );
      expect(dateText).toBeDefined();
    });

    it('blue 배경 스타일이 적용되어 있다', () => {
      const changelogSection = findInTree(moduleUpdateModal.children, (node: any) =>
        node.if?.includes('_global.updateChangelog') && node.if?.includes('length > 0'),
      );
      expect(changelogSection.props.className).toContain('bg-blue-50');
      expect(changelogSection.props.className).toContain('dark:bg-blue-900/20');
    });

    it('github_changelog_url 외부 링크가 조건부로 존재한다', () => {
      const linkSection = findInTree(moduleUpdateModal.children, (node: any) =>
        node.if === '{{_global.selectedModule.github_changelog_url}}',
      );
      expect(linkSection).toBeDefined();

      const linkA = findInTree(linkSection.children, (node: any) => node.name === 'A');
      expect(linkA).toBeDefined();
      expect(linkA.props.target).toBe('_blank');
      expect(linkA.props.href).toContain('_global.selectedModule.github_changelog_url');
    });
  });

  describe('플러그인 업데이트 모달', () => {
    it('인라인 changelog 섹션이 updateChangelog 조건부로 존재한다', () => {
      const changelogSection = findInTree(pluginUpdateModal.children, (node: any) =>
        node.if?.includes('_global.updateChangelog') && node.if?.includes('length > 0'),
      );
      expect(changelogSection).toBeDefined();
    });

    it('changelog 타이틀이 플러그인 i18n 키를 사용한다', () => {
      const changelogSection = findInTree(pluginUpdateModal.children, (node: any) =>
        node.if?.includes('_global.updateChangelog') && node.if?.includes('length > 0'),
      );
      const titleDiv = findInTree(changelogSection.children, (node: any) =>
        node.text === '$t:admin.plugins.modals.update_changelog_title',
      );
      expect(titleDiv).toBeDefined();
    });

    it('github_changelog_url 외부 링크가 조건부로 존재한다', () => {
      const linkSection = findInTree(pluginUpdateModal.children, (node: any) =>
        node.if === '{{_global.selectedPlugin.github_changelog_url}}',
      );
      expect(linkSection).toBeDefined();
    });
  });

  describe('템플릿 업데이트 모달', () => {
    it('인라인 changelog 섹션이 updateChangelog 조건부로 존재한다', () => {
      const changelogSection = findInTree(templateUpdateModal.children, (node: any) =>
        node.if?.includes('_global.updateChangelog') && node.if?.includes('length > 0'),
      );
      expect(changelogSection).toBeDefined();
    });

    it('changelog 타이틀이 템플릿 i18n 키를 사용한다', () => {
      const changelogSection = findInTree(templateUpdateModal.children, (node: any) =>
        node.if?.includes('_global.updateChangelog') && node.if?.includes('length > 0'),
      );
      const titleDiv = findInTree(changelogSection.children, (node: any) =>
        node.text === '$t:admin.templates.modals.update_changelog_title',
      );
      expect(titleDiv).toBeDefined();
    });

    it('github_changelog_url 외부 링크가 조건부로 존재한다', () => {
      const linkSection = findInTree(templateUpdateModal.children, (node: any) =>
        node.if === '{{_global.selectedTemplate.github_changelog_url}}',
      );
      expect(linkSection).toBeDefined();
    });
  });
});

// ==============================
// 2. 상세 모달 - changelog 섹션 구조 검증
// ==============================

describe('Changelog - 상세 모달 changelog 섹션 구조', () => {
  let moduleDetailModal: any;
  let pluginDetailModal: any;
  let templateDetailModal: any;

  beforeEach(() => {
    moduleDetailModal = loadPartial('admin_module_list/_modal_detail.json');
    pluginDetailModal = loadPartial('admin_plugin_list/_modal_detail.json');
    templateDetailModal = loadPartial('admin_template_list/_modal_detail.json');
  });

  describe('모듈 상세 모달', () => {
    it('changelog_section이 존재한다', () => {
      const changelogSection = findInTree(moduleDetailModal.children, (node: any) =>
        node.id === 'changelog_section',
      );
      expect(changelogSection).toBeDefined();
    });

    it('changelog 아이콘이 clock-rotate-left이다', () => {
      const changelogSection = findInTree(moduleDetailModal.children, (node: any) =>
        node.id === 'changelog_section',
      );
      const icon = findInTree(changelogSection.children, (node: any) =>
        node.name === 'Icon' && node.props?.name === 'clock-rotate-left',
      );
      expect(icon).toBeDefined();
    });

    it('changelog 제목이 올바른 i18n 키를 사용한다', () => {
      const changelogSection = findInTree(moduleDetailModal.children, (node: any) =>
        node.id === 'changelog_section',
      );
      const title = findInTree(changelogSection.children, (node: any) =>
        node.text === '$t:admin.modules.modals.changelog',
      );
      expect(title).toBeDefined();
    });

    it('empty state가 moduleChangelog 비어있을 때 조건부로 표시된다', () => {
      const changelogSection = findInTree(moduleDetailModal.children, (node: any) =>
        node.id === 'changelog_section',
      );
      const emptyState = findInTree(changelogSection.children, (node: any) =>
        node.if?.includes('!_global.moduleChangelog') && node.text === '$t:admin.modules.modals.no_changelog',
      );
      expect(emptyState).toBeDefined();
    });

    it('changelog 리스트가 moduleChangelog로 iteration한다', () => {
      const changelogSection = findInTree(moduleDetailModal.children, (node: any) =>
        node.id === 'changelog_section',
      );
      const iterationDiv = findInTree(changelogSection.children, (node: any) =>
        node.iteration?.source === '_global.moduleChangelog',
      );
      expect(iterationDiv).toBeDefined();
      expect(iterationDiv.iteration.item_var).toBe('entry');
    });

    it('changelog 리스트에 스크롤 가능한 max-h-64 스타일이 있다', () => {
      const changelogSection = findInTree(moduleDetailModal.children, (node: any) =>
        node.id === 'changelog_section',
      );
      // 스크롤 컨테이너는 iteration Div 의 부모 wrapper (조건부 표시)
      const scrollWrapper = findInTree(changelogSection.children, (node: any) =>
        node.if?.includes('_global.moduleChangelog') &&
        node.if?.includes('length > 0'),
      );
      expect(scrollWrapper).toBeDefined();
      expect(scrollWrapper.props.className).toContain('max-h-64');
      expect(scrollWrapper.props.className).toContain('overflow-y-auto');
    });

    it('3단 중첩 iteration 구조가 올바르다 (entry → categories → items)', () => {
      const changelogSection = findInTree(moduleDetailModal.children, (node: any) =>
        node.id === 'changelog_section',
      );

      // 1단: _global.moduleChangelog → entry
      const entryIteration = findInTree(changelogSection.children, (node: any) =>
        node.iteration?.source === '_global.moduleChangelog',
      );
      expect(entryIteration).toBeDefined();

      // 2단: entry.categories → category
      const categoryIteration = findInTree(entryIteration.children, (node: any) =>
        node.iteration?.source === 'entry.categories',
      );
      expect(categoryIteration).toBeDefined();

      // 3단: category.items → changeItem
      const itemIteration = findInTree(categoryIteration.children, (node: any) =>
        node.iteration?.source === 'category.items',
      );
      expect(itemIteration).toBeDefined();
      expect(itemIteration.iteration.item_var).toBe('changeItem');
    });
  });

  describe('플러그인 상세 모달', () => {
    it('changelog_section이 존재한다', () => {
      const changelogSection = findInTree(pluginDetailModal.children, (node: any) =>
        node.id === 'changelog_section',
      );
      expect(changelogSection).toBeDefined();
    });

    it('pluginChangelog를 참조한다 (moduleChangelog 아님)', () => {
      const changelogSection = findInTree(pluginDetailModal.children, (node: any) =>
        node.id === 'changelog_section',
      );

      const iterationDiv = findInTree(changelogSection.children, (node: any) =>
        node.iteration?.source === '_global.pluginChangelog',
      );
      expect(iterationDiv).toBeDefined();

      const emptyState = findInTree(changelogSection.children, (node: any) =>
        node.if?.includes('!_global.pluginChangelog'),
      );
      expect(emptyState).toBeDefined();
    });

    it('플러그인 i18n 키를 사용한다', () => {
      const changelogSection = findInTree(pluginDetailModal.children, (node: any) =>
        node.id === 'changelog_section',
      );
      const title = findInTree(changelogSection.children, (node: any) =>
        node.text === '$t:admin.plugins.modals.changelog',
      );
      expect(title).toBeDefined();
    });
  });

  describe('템플릿 상세 모달', () => {
    it('changelog_section이 존재한다', () => {
      const changelogSection = findInTree(templateDetailModal.children, (node: any) =>
        node.id === 'changelog_section',
      );
      expect(changelogSection).toBeDefined();
    });

    it('templateChangelog를 참조한다 (moduleChangelog 아님)', () => {
      const changelogSection = findInTree(templateDetailModal.children, (node: any) =>
        node.id === 'changelog_section',
      );

      const iterationDiv = findInTree(changelogSection.children, (node: any) =>
        node.iteration?.source === '_global.templateChangelog',
      );
      expect(iterationDiv).toBeDefined();

      const emptyState = findInTree(changelogSection.children, (node: any) =>
        node.if?.includes('!_global.templateChangelog'),
      );
      expect(emptyState).toBeDefined();
    });

    it('템플릿 i18n 키를 사용한다', () => {
      const changelogSection = findInTree(templateDetailModal.children, (node: any) =>
        node.id === 'changelog_section',
      );
      const title = findInTree(changelogSection.children, (node: any) =>
        node.text === '$t:admin.templates.modals.changelog',
      );
      expect(title).toBeDefined();
    });
  });
});

// ==============================
// 3. 메인 리스트 - changelog fetch 액션 검증
// ==============================

describe('Changelog - 메인 리스트 changelog fetch 액션 검증', () => {
  it('모듈 리스트에서 changelog API 호출 액션이 존재한다', () => {
    const layout = loadLayout('admin_module_list.json');
    const components = layout.slots?.content ?? layout.components ?? [];
    const changelogCalls = findChangelogApiCalls(components);

    expect(changelogCalls.length).toBeGreaterThanOrEqual(1);
    const hasModuleChangelog = changelogCalls.some((c: any) =>
      c.target.includes('/api/admin/modules/') && c.target.includes('/changelog'),
    );
    expect(hasModuleChangelog).toBe(true);
  });

  it('플러그인 리스트에서 changelog API 호출 액션이 존재한다', () => {
    const layout = loadLayout('admin_plugin_list.json');
    const components = layout.slots?.content ?? layout.components ?? [];
    const changelogCalls = findChangelogApiCalls(components);

    expect(changelogCalls.length).toBeGreaterThanOrEqual(1);
    const hasPluginChangelog = changelogCalls.some((c: any) =>
      c.target.includes('/api/admin/plugins/') && c.target.includes('/changelog'),
    );
    expect(hasPluginChangelog).toBe(true);
  });

  it('템플릿 리스트 (admin 탭)에서 changelog API 호출 액션이 존재한다', () => {
    const layout = loadPartial('admin_template_list/_tab_admin.json');
    const components = layout.children ? [layout] : [];
    const changelogCalls = findChangelogApiCalls(components);

    expect(changelogCalls.length).toBeGreaterThanOrEqual(1);
    const hasTemplateChangelog = changelogCalls.some((c: any) =>
      c.target.includes('/api/admin/templates/') && c.target.includes('/changelog'),
    );
    expect(hasTemplateChangelog).toBe(true);
  });

  it('모듈 리스트의 update용 changelog 호출에 버전 범위 파라미터가 포함된다', () => {
    const layout = loadLayout('admin_module_list.json');
    const components = layout.slots?.content ?? layout.components ?? [];
    const changelogCalls = findChangelogApiCalls(components);

    const updateCall = changelogCalls.find((c: any) =>
      c.target.includes('from_version') && c.target.includes('to_version'),
    );
    expect(updateCall).toBeDefined();
    expect(updateCall.target).toContain('source=');
  });

  it('모듈 리스트의 detail용 changelog 호출은 전체 조회 (버전 범위 없음)이다', () => {
    const layout = loadLayout('admin_module_list.json');
    const components = layout.slots?.content ?? layout.components ?? [];
    const changelogCalls = findChangelogApiCalls(components);

    const detailCall = changelogCalls.find((c: any) =>
      c.target.includes('/changelog') && !c.target.includes('from_version'),
    );
    expect(detailCall).toBeDefined();
  });
});

// ==============================
// 4. 전역 상태 변수명 검증
// ==============================

describe('Changelog - 전역 상태 변수명 검증', () => {
  it('플러그인 상세 모달은 _global.pluginChangelog를 참조한다', () => {
    const layout = loadPartial('admin_plugin_list/_modal_detail.json');
    const content = JSON.stringify(layout);
    expect(content).toContain('_global.pluginChangelog');
    expect(content).not.toContain('_global.moduleChangelog');
  });

  it('템플릿 상세 모달은 _global.templateChangelog를 참조한다', () => {
    const layout = loadPartial('admin_template_list/_modal_detail.json');
    const content = JSON.stringify(layout);
    expect(content).toContain('_global.templateChangelog');
    expect(content).not.toContain('_global.moduleChangelog');
  });

  it('모듈 상세 모달은 _global.moduleChangelog를 참조한다', () => {
    const layout = loadPartial('admin_module_list/_modal_detail.json');
    const content = JSON.stringify(layout);
    expect(content).toContain('_global.moduleChangelog');
    expect(content).not.toContain('_global.pluginChangelog');
  });

  it('모든 업데이트 모달은 공통으로 _global.updateChangelog를 참조한다', () => {
    const modalPaths = [
      'admin_module_list/_modal_update.json',
      'admin_plugin_list/_modal_update.json',
      'admin_template_list/_modal_update.json',
    ];

    for (const modalPath of modalPaths) {
      const layout = loadPartial(modalPath);
      const content = JSON.stringify(layout);
      expect(content).toContain('_global.updateChangelog');
    }
  });
});

// ==============================
// 5. 실제 렌더링 검증 - changelog 데이터가 있을 때
// ==============================

describe('Changelog - changelog 데이터 렌더링 검증', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  it('updateChangelog가 있으면 인라인 changelog 항목이 렌더링된다', async () => {
    const layout = {
      version: '1.0.0',
      layout_name: 'inline_changelog_render_test',
      initGlobal: {
        updateChangelog: sampleChangelog,
      },
      components: [
        {
          id: 'root',
          type: 'basic',
          name: 'Div',
          children: [
            {
              type: 'basic',
              name: 'Div',
              if: '{{_global.updateChangelog && _global.updateChangelog.length > 0}}',
              props: { 'data-testid': 'changelog-section' },
              children: [
                {
                  type: 'basic',
                  name: 'Span',
                  text: '$t:admin.modules.modals.update_changelog_title',
                },
                {
                  type: 'basic',
                  name: 'Div',
                  iteration: {
                    source: '_global.updateChangelog',
                    item_var: 'entry',
                    index_var: 'entryIdx',
                  },
                  children: [
                    {
                      type: 'basic',
                      name: 'Div',
                      text: "v{{entry.version}}{{entry.date ? ' (' + entry.date + ')' : ''}}",
                    },
                    {
                      type: 'basic',
                      name: 'Div',
                      iteration: {
                        source: 'entry.categories',
                        item_var: 'category',
                        index_var: 'catIdx',
                      },
                      children: [
                        {
                          type: 'basic',
                          name: 'Span',
                          text: '{{category.name}}',
                        },
                        {
                          type: 'basic',
                          name: 'Div',
                          iteration: {
                            source: 'category.items',
                            item_var: 'changeItem',
                            index_var: 'itemIdx',
                          },
                          children: [
                            {
                              type: 'basic',
                              name: 'Div',
                              text: '• {{changeItem}}',
                            },
                          ],
                        },
                      ],
                    },
                  ],
                },
              ],
            },
          ],
        },
      ],
    };

    testUtils = createLayoutTest(layout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
    });
    await testUtils.render();

    // 섹션 렌더링 확인
    expect(screen.getByTestId('changelog-section')).toBeTruthy();
    expect(screen.getByText('이 업데이트의 변경사항')).toBeTruthy();

    // 버전 + 날짜
    expect(screen.getByText('v1.1.0 (2026-02-25)')).toBeTruthy();

    // 카테고리명
    expect(screen.getByText('Added')).toBeTruthy();
    expect(screen.getByText('Fixed')).toBeTruthy();

    // 개별 항목 (불릿 포함)
    expect(screen.getByText('• 새로운 기능 A 추가')).toBeTruthy();
    expect(screen.getByText('• 새로운 기능 B 추가')).toBeTruthy();
    expect(screen.getByText('• 버그 C 수정')).toBeTruthy();
  });

  it('여러 버전의 changelog가 모두 렌더링된다', async () => {
    const layout = {
      version: '1.0.0',
      layout_name: 'multi_version_changelog_test',
      initGlobal: {
        updateChangelog: multiVersionChangelog,
      },
      components: [
        {
          id: 'root',
          type: 'basic',
          name: 'Div',
          children: [
            {
              type: 'basic',
              name: 'Div',
              if: '{{_global.updateChangelog && _global.updateChangelog.length > 0}}',
              children: [
                {
                  type: 'basic',
                  name: 'Div',
                  iteration: {
                    source: '_global.updateChangelog',
                    item_var: 'entry',
                    index_var: 'entryIdx',
                  },
                  children: [
                    {
                      type: 'basic',
                      name: 'Div',
                      text: "v{{entry.version}}{{entry.date ? ' (' + entry.date + ')' : ''}}",
                    },
                    {
                      type: 'basic',
                      name: 'Div',
                      iteration: {
                        source: 'entry.categories',
                        item_var: 'category',
                        index_var: 'catIdx',
                      },
                      children: [
                        {
                          type: 'basic',
                          name: 'Span',
                          text: '{{category.name}}',
                        },
                        {
                          type: 'basic',
                          name: 'Div',
                          iteration: {
                            source: 'category.items',
                            item_var: 'changeItem',
                            index_var: 'itemIdx',
                          },
                          children: [
                            {
                              type: 'basic',
                              name: 'Div',
                              text: '• {{changeItem}}',
                            },
                          ],
                        },
                      ],
                    },
                  ],
                },
              ],
            },
          ],
        },
      ],
    };

    testUtils = createLayoutTest(layout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
    });
    await testUtils.render();

    // 두 버전 모두 표시
    expect(screen.getByText('v1.2.0 (2026-02-26)')).toBeTruthy();
    expect(screen.getByText('v1.1.0 (2026-02-25)')).toBeTruthy();

    // 각 버전의 항목
    expect(screen.getByText('• 기능 X 추가')).toBeTruthy();
    expect(screen.getByText('• 기존 기능 Y 변경')).toBeTruthy();
    expect(screen.getByText('• 버그 Z 수정')).toBeTruthy();
    expect(screen.getByText('• 버그 W 수정')).toBeTruthy();
  });

  it('updateChangelog가 빈 배열이면 changelog 섹션이 렌더링되지 않는다', async () => {
    const layout = {
      version: '1.0.0',
      layout_name: 'empty_changelog_test',
      initGlobal: {
        updateChangelog: [],
      },
      components: [
        {
          id: 'root',
          type: 'basic',
          name: 'Div',
          children: [
            {
              type: 'basic',
              name: 'Div',
              if: '{{_global.updateChangelog && _global.updateChangelog.length > 0}}',
              props: { 'data-testid': 'changelog-section' },
              children: [
                {
                  type: 'basic',
                  name: 'Span',
                  text: '$t:admin.modules.modals.update_changelog_title',
                },
              ],
            },
          ],
        },
      ],
    };

    testUtils = createLayoutTest(layout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
    });
    await testUtils.render();

    expect(screen.queryByTestId('changelog-section')).toBeNull();
    expect(screen.queryByText('이 업데이트의 변경사항')).toBeNull();
  });

  it('상세 모달의 changelog empty state가 올바르게 렌더링된다', async () => {
    const layout = {
      version: '1.0.0',
      layout_name: 'detail_empty_changelog_test',
      initGlobal: {
        moduleChangelog: [],
      },
      components: [
        {
          id: 'root',
          type: 'basic',
          name: 'Div',
          children: [
            {
              type: 'basic',
              name: 'Div',
              children: [
                {
                  type: 'basic',
                  name: 'Span',
                  text: '$t:admin.modules.modals.changelog',
                },
                {
                  type: 'basic',
                  name: 'P',
                  if: '{{!_global.moduleChangelog || _global.moduleChangelog.length === 0}}',
                  text: '$t:admin.modules.modals.no_changelog',
                },
                {
                  type: 'basic',
                  name: 'Div',
                  if: '{{_global.moduleChangelog && _global.moduleChangelog.length > 0}}',
                  props: { 'data-testid': 'changelog-list' },
                  text: 'changelog list',
                },
              ],
            },
          ],
        },
      ],
    };

    testUtils = createLayoutTest(layout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
    });
    await testUtils.render();

    expect(screen.getByText('변경 내역')).toBeTruthy();
    expect(screen.getByText('변경 내역 정보가 없습니다.')).toBeTruthy();
    expect(screen.queryByTestId('changelog-list')).toBeNull();
  });

  it('github_changelog_url 외부 링크가 렌더링된다', async () => {
    const layout = {
      version: '1.0.0',
      layout_name: 'external_link_test',
      initGlobal: {
        selectedModule: {
          github_changelog_url: 'https://github.com/gnuboard/sirsoft-board/releases',
        },
      },
      components: [
        {
          id: 'root',
          type: 'basic',
          name: 'Div',
          children: [
            {
              type: 'basic',
              name: 'Div',
              if: '{{_global.selectedModule.github_changelog_url}}',
              props: { 'data-testid': 'external-link-section' },
              children: [
                {
                  type: 'basic',
                  name: 'A',
                  props: {
                    href: '{{_global.selectedModule.github_changelog_url}}',
                    target: '_blank',
                  },
                  children: [
                    {
                      type: 'basic',
                      name: 'Icon',
                      props: { name: 'external-link', size: 'sm' },
                    },
                    {
                      type: 'basic',
                      name: 'Span',
                      text: '$t:admin.modules.modals.view_changelog',
                    },
                  ],
                },
              ],
            },
          ],
        },
      ],
    };

    testUtils = createLayoutTest(layout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
    });
    await testUtils.render();

    expect(screen.getByTestId('external-link-section')).toBeTruthy();
    expect(screen.getByText('변경사항 보기')).toBeTruthy();
    expect(screen.getByTestId('icon-external-link')).toBeTruthy();
  });

  it('github_changelog_url이 null이면 외부 링크가 렌더링되지 않는다', async () => {
    const layout = {
      version: '1.0.0',
      layout_name: 'no_external_link_test',
      initGlobal: {
        selectedModule: {
          github_changelog_url: null,
        },
      },
      components: [
        {
          id: 'root',
          type: 'basic',
          name: 'Div',
          children: [
            {
              type: 'basic',
              name: 'Div',
              if: '{{_global.selectedModule.github_changelog_url}}',
              props: { 'data-testid': 'external-link-section' },
              children: [
                {
                  type: 'basic',
                  name: 'Span',
                  text: '$t:admin.modules.modals.view_changelog',
                },
              ],
            },
          ],
        },
      ],
    };

    testUtils = createLayoutTest(layout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
    });
    await testUtils.render();

    expect(screen.queryByTestId('external-link-section')).toBeNull();
    expect(screen.queryByText('변경사항 보기')).toBeNull();
  });
});
