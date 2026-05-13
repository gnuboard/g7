/**
 * @file admin-extension-detail-dependencies.test.tsx
 * @description 모듈/플러그인/템플릿 정보 모달 - 의존성 섹션 렌더링 테스트
 *
 * 테스트 대상:
 * - partials/admin_module_list/_modal_detail.json (dependencies_section)
 * - partials/admin_plugin_list/_modal_detail.json (dependencies_section)
 * - partials/admin_template_list/_modal_detail.json (dependencies_section)
 *
 * 검증:
 * - 코어 요구 버전 배지 렌더링 (값 있음/없음)
 * - 의존성 없을 때 "없음" 메시지
 * - 의존 모듈/플러그인 행 렌더링 — 요구 버전, 설치 버전, 충족 상태 뱃지
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const TestDiv: React.FC<any> = ({ className, children }) => (
  <div className={className}>{children}</div>
);
const TestSpan: React.FC<any> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);
const TestP: React.FC<any> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);
const TestIcon: React.FC<any> = ({ name, className }) => (
  <i className={className} data-icon={name} />
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const translations = {
  admin: {
    modules: {
      modals: {
        dependencies: '의존성',
        required_core_version: '코어 요구 버전',
        no_required_core_version: '지정되지 않음',
        no_dependencies_required: '의존하는 확장이 없습니다.',
        dependency_required_version: '요구: {{version}}',
        dependency_installed_version: '설치: {{version}}',
        dependency_not_installed: '미설치',
        dependency_met: '충족',
        dependency_unmet_version: '버전 불일치',
        dependency_inactive: '비활성',
      },
    },
    plugins: {
      modals: {
        dependencies: '의존성',
        required_core_version: '코어 요구 버전',
        no_required_core_version: '지정되지 않음',
        no_dependencies_required: '의존하는 확장이 없습니다.',
        dependency_required_version: '요구: {{version}}',
        dependency_installed_version: '설치: {{version}}',
        dependency_not_installed: '미설치',
        dependency_met: '충족',
        dependency_unmet_version: '버전 불일치',
        dependency_inactive: '비활성',
      },
    },
    templates: {
      modals: {
        dependencies: '의존성',
        required_core_version: '코어 요구 버전',
        no_required_core_version: '지정되지 않음',
        no_dependencies_required: '의존하는 확장이 없습니다.',
        dependency_required_version: '요구: {{version}}',
        dependency_installed_version: '설치: {{version}}',
        dependency_not_installed: '미설치',
        dependency_met: '충족',
        dependency_unmet_version: '버전 불일치',
        dependency_inactive: '비활성',
      },
    },
  },
};

function loadModulePartial(relativePath: string): any {
  const fs = require('fs');
  const path = require('path');
  const full = path.resolve(__dirname, '..', '..', 'layouts', relativePath);
  const json = JSON.parse(fs.readFileSync(full, 'utf-8'));
  const root = json.children?.[0] ?? json;
  return (root.children ?? []).find((c: any) => c.id === 'dependencies_section');
}

function buildLayoutFromSection(section: any, globalKey: string, value: Record<string, any>) {
  return {
    version: '1.0.0',
    layout_name: 'dependency_section_test',
    initGlobal: { [globalKey]: value },
    components: [section],
  };
}

describe('모듈 정보 모달 - 의존성 섹션', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;
  let section: any;

  beforeEach(() => {
    registry = setupRegistry();
    section = loadModulePartial('partials/admin_module_list/_modal_detail.json');
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  it('dependencies_section 이 partial 에 존재한다', () => {
    expect(section).toBeDefined();
    expect(section.id).toBe('dependencies_section');
  });

  it('requires_core 값이 있으면 버전 문자열이 배지로 표시된다', async () => {
    testUtils = createLayoutTest(
      buildLayoutFromSection(section, 'selectedModule', {
        requires_core: '>=7.0.0-beta.2',
        dependencies: [],
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('>=7.0.0-beta.2')).toBeTruthy();
    expect(screen.getByText('의존하는 확장이 없습니다.')).toBeTruthy();
  });

  it('requires_core 가 null 이면 "지정되지 않음" 메시지가 표시된다', async () => {
    testUtils = createLayoutTest(
      buildLayoutFromSection(section, 'selectedModule', {
        requires_core: null,
        dependencies: [],
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('지정되지 않음')).toBeTruthy();
  });

  it('충족된 모듈 의존성 행에 "충족" 뱃지가 표시된다', async () => {
    testUtils = createLayoutTest(
      buildLayoutFromSection(section, 'selectedModule', {
        requires_core: '>=7.0.0-beta.2',
        dependencies: [
          {
            identifier: 'sirsoft-ecommerce',
            name: '상점',
            type: 'module',
            required_version: '>=1.0.0',
            installed_version: '1.2.0',
            is_active: true,
            is_met: true,
          },
        ],
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('상점 (sirsoft-ecommerce)')).toBeTruthy();
    expect(screen.getByText('요구: >=1.0.0')).toBeTruthy();
    expect(screen.getByText('설치: 1.2.0')).toBeTruthy();
    expect(screen.getByText('충족')).toBeTruthy();
  });

  it('미설치 의존성 행에 "미설치" 뱃지가 표시된다', async () => {
    testUtils = createLayoutTest(
      buildLayoutFromSection(section, 'selectedModule', {
        requires_core: '>=7.0.0-beta.2',
        dependencies: [
          {
            identifier: 'missing-module',
            name: 'missing-module',
            type: 'module',
            required_version: '>=1.0.0',
            installed_version: null,
            is_active: false,
            is_met: false,
          },
        ],
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('미설치')).toBeTruthy();
  });

  it('설치되었지만 비활성 의존성에 "비활성" 뱃지가 표시된다', async () => {
    testUtils = createLayoutTest(
      buildLayoutFromSection(section, 'selectedModule', {
        requires_core: '>=7.0.0-beta.2',
        dependencies: [
          {
            identifier: 'inactive-module',
            name: 'inactive-module',
            type: 'module',
            required_version: '>=1.0.0',
            installed_version: '1.0.0',
            is_active: false,
            is_met: false,
          },
        ],
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('비활성')).toBeTruthy();
  });

  it('설치/활성이지만 버전 불일치인 의존성에 "버전 불일치" 뱃지가 표시된다', async () => {
    testUtils = createLayoutTest(
      buildLayoutFromSection(section, 'selectedModule', {
        requires_core: '>=7.0.0-beta.2',
        dependencies: [
          {
            identifier: 'old-module',
            name: 'old-module',
            type: 'module',
            required_version: '>=2.0.0',
            installed_version: '1.0.0',
            is_active: true,
            is_met: false,
          },
        ],
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('버전 불일치')).toBeTruthy();
  });
});

describe('플러그인 정보 모달 - 의존성 섹션', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;
  let section: any;

  beforeEach(() => {
    registry = setupRegistry();
    section = loadModulePartial('partials/admin_plugin_list/_modal_detail.json');
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  it('dependencies_section 이 plugin partial 에 존재한다', () => {
    expect(section).toBeDefined();
  });

  it('requires_core 와 충족 뱃지가 함께 표시된다', async () => {
    testUtils = createLayoutTest(
      buildLayoutFromSection(section, 'selectedPlugin', {
        requires_core: '>=7.0.0-beta.2',
        dependencies: [
          {
            identifier: 'sirsoft-board',
            name: '게시판',
            type: 'module',
            required_version: '>=1.0.0',
            installed_version: '1.1.0',
            is_active: true,
            is_met: true,
          },
        ],
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('>=7.0.0-beta.2')).toBeTruthy();
    expect(screen.getByText('게시판 (sirsoft-board)')).toBeTruthy();
    expect(screen.getByText('충족')).toBeTruthy();
  });
});

describe('템플릿 정보 모달 - 의존성 섹션', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;
  let section: any;

  beforeEach(() => {
    registry = setupRegistry();
    section = loadModulePartial('partials/admin_template_list/_modal_detail.json');
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  it('dependencies_section 이 template partial 에 존재한다', () => {
    expect(section).toBeDefined();
  });

  it('의존성 없을 때 "없음" 메시지와 코어 요구 버전이 표시된다', async () => {
    testUtils = createLayoutTest(
      buildLayoutFromSection(section, 'selectedTemplate', {
        requires_core: '>=7.0.0-beta.2',
        dependencies: { modules: [], plugins: [] },
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('>=7.0.0-beta.2')).toBeTruthy();
    expect(screen.getByText('의존하는 확장이 없습니다.')).toBeTruthy();
  });

  it('모듈 의존성에 버전 및 충족 뱃지가 표시된다', async () => {
    testUtils = createLayoutTest(
      buildLayoutFromSection(section, 'selectedTemplate', {
        requires_core: '>=7.0.0-beta.2',
        dependencies: {
          modules: [
            {
              identifier: 'sirsoft-ecommerce',
              name: '상점',
              required_version: '>=1.0.0',
              installed_version: '1.2.0',
              is_active: true,
              is_met: true,
            },
          ],
          plugins: [],
        },
      }),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText('상점 (sirsoft-ecommerce)')).toBeTruthy();
    expect(screen.getByText('요구: >=1.0.0')).toBeTruthy();
    expect(screen.getByText('설치: 1.2.0')).toBeTruthy();
    expect(screen.getByText('충족')).toBeTruthy();
  });
});
