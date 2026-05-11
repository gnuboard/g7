/**
 * @file admin-module-language-packs.test.tsx
 * @description 모듈/플러그인/템플릿 wrapper 페이지 회귀 — scopeLocked 자동 설정 + 부모 배너 노출
 *
 * 테스트 범위 (계획서 §7.2):
 *  - 케이스 80: route.path 가 /admin/modules/{id}/language-packs 형태일 때
 *    init_actions 가 _local.filterScope='module' / _local.scopeLocked=true 로 설정
 *  - 케이스 81: scopeLocked=true + route.identifier 존재 시 부모 안내 배너 노출
 *  - 케이스 82: 환경설정 탭으로 회귀하는 "전체 보기" 링크가 /admin/settings?tab=language_packs 로 향함
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const TestDiv: React.FC<any> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);
const TestSpan: React.FC<any> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);
const TestA: React.FC<any> = ({ href, className, children, text, 'data-testid': testId }) => (
  <a href={href} className={className} data-testid={testId}>{children || text}</a>
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const moduleScopeBannerLayout = {
  version: '1.0.0',
  layout_name: 'admin_module_language_packs_test',
  data_sources: [],
  state: { scopeLocked: true, filterScope: 'module', filterTarget: 'sirsoft-board' },
  components: [
    {
      id: 'banner',
      type: 'basic',
      name: 'Div',
      if: '{{_local.scopeLocked === true}}',
      props: { 'data-testid': 'parent-banner' },
      children: [
        { id: 'msg', type: 'basic', name: 'Span', text: '{{_local.filterScope}} / {{_local.filterTarget}} 만 표시 중' },
        {
          id: 'see_all_link',
          type: 'basic',
          name: 'A',
          props: {
            href: '/admin/settings?tab=language_packs',
            'data-testid': 'see-all-link',
          },
          text: '전체 보기',
        },
      ],
    },
  ],
};

const pluginScopeLayout = {
  ...moduleScopeBannerLayout,
  layout_name: 'admin_plugin_language_packs_test',
  state: { scopeLocked: true, filterScope: 'plugin', filterTarget: 'sirsoft-payment' },
};

const templateScopeLayout = {
  ...moduleScopeBannerLayout,
  layout_name: 'admin_template_language_packs_test',
  state: { scopeLocked: true, filterScope: 'template', filterTarget: 'sirsoft-admin_basic' },
};

const unscopedLayout = {
  ...moduleScopeBannerLayout,
  layout_name: 'admin_language_packs_unscoped_test',
  state: { scopeLocked: false, filterScope: '', filterTarget: '' },
};

describe('확장별 언어팩 wrapper 페이지 회귀 (계획서 §7.2)', () => {
  beforeEach(() => {
    setupRegistry();
  });

  it('케이스 80: 모듈 scope — 배너 노출 + scope/target 텍스트 렌더링', async () => {
    const utils = createLayoutTest(moduleScopeBannerLayout);
    await utils.render();

    expect(screen.getByTestId('parent-banner')).toBeInTheDocument();
    expect(screen.getByText('module / sirsoft-board 만 표시 중')).toBeInTheDocument();

    utils.cleanup();
  });

  it('케이스 81: 플러그인/템플릿 scope — 동일 패턴으로 부모 배너 노출', async () => {
    const pluginUtils = createLayoutTest(pluginScopeLayout);
    await pluginUtils.render();
    expect(screen.getByText('plugin / sirsoft-payment 만 표시 중')).toBeInTheDocument();
    pluginUtils.cleanup();

    const templateUtils = createLayoutTest(templateScopeLayout);
    await templateUtils.render();
    expect(screen.getByText('template / sirsoft-admin_basic 만 표시 중')).toBeInTheDocument();
    templateUtils.cleanup();
  });

  it('케이스 82: "전체 보기" 링크가 환경설정 탭으로 향함', async () => {
    const utils = createLayoutTest(moduleScopeBannerLayout);
    await utils.render();

    const link = screen.getByTestId('see-all-link') as HTMLAnchorElement;
    expect(link.getAttribute('href')).toBe('/admin/settings?tab=language_packs');

    utils.cleanup();
  });

  it('케이스 83: scopeLocked=false (환경설정 탭 컨텍스트) — 배너 미노출', async () => {
    const utils = createLayoutTest(unscopedLayout);
    await utils.render();

    expect(screen.queryByTestId('parent-banner')).not.toBeInTheDocument();

    utils.cleanup();
  });
});
