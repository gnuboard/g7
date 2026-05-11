/**
 * @file admin_plugin_list.banner.test.tsx
 * @description 자동 비활성화 배너 렌더링 테스트
 *
 * 검증 항목:
 * - auto_deactivated_banner.data.items.plugins 가 있을 때 배너 표시
 * - 비어있을 때 배너 미표시
 * - 가이드 링크 href = /admin/system/update
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const TestDiv: React.FC<any> = ({ className, children, role }) => <div className={className} role={role}>{children}</div>;
const TestSpan: React.FC<any> = ({ className, children, text }) => <span className={className}>{children || text}</span>;
const TestP: React.FC<any> = ({ className, children, text }) => <p className={className}>{children || text}</p>;
const TestIcon: React.FC<any> = ({ name, className }) => <i className={`icon-${name} ${className || ''}`} data-testid={`icon-${name}`} />;
const TestA: React.FC<any> = ({ href, className, children }) => <a href={href} className={className} data-testid={`link-${href}`}>{children}</a>;
const TestButton: React.FC<any> = ({ type, className, children }) => <button type={type} className={className}>{children}</button>;
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const translations = {
  extensions: {
    banner: {
      title: '코어 호환성 문제로 자동 비활성화된 확장이 있습니다',
      item_required: '필요 버전: {{required}}',
      guide_link: '코어 업그레이드 가이드',
      dismiss: '닫기',
    },
  },
};

function buildLayout(plugins: any[]) {
  return {
    version: '1.0.0',
    layout_name: 'banner_test',
    initGlobal: { auto_deactivated_banner: { data: { items: { plugins } } } },
    components: [
      {
        id: 'banner',
        type: 'basic',
        name: 'Div',
        if: '{{(_global.auto_deactivated_banner?.data?.items?.plugins?.length ?? 0) > 0}}',
        props: {
          className: 'mb-4 p-4 bg-amber-50 dark:bg-amber-900/20 border border-amber-200 dark:border-amber-800 rounded-lg',
          role: 'alert',
        },
        children: [
          { type: 'basic', name: 'P', text: '$t:extensions.banner.title' },
          {
            type: 'basic',
            name: 'A',
            props: { href: '/admin/system/update', className: 'text-sm' },
            children: [{ type: 'basic', name: 'Span', text: '$t:extensions.banner.guide_link' }],
          },
        ],
      },
    ],
  };
}

describe('플러그인 목록 - 자동 비활성화 배너', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => { registry = setupRegistry(); });
  afterEach(() => { if (testUtils) testUtils.cleanup(); });

  it('plugins 배열에 항목이 있으면 배너가 렌더된다', async () => {
    testUtils = createLayoutTest(
      buildLayout([{ identifier: 'p1', incompatible_required_version: '>=7.5.0' }]),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByText(/자동 비활성화/)).toBeTruthy();
  });

  it('plugins 배열이 비어있으면 배너가 렌더되지 않는다', async () => {
    testUtils = createLayoutTest(
      buildLayout([]),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.queryByText(/자동 비활성화/)).toBeNull();
  });

  it('가이드 링크가 /admin/system/update 로 연결된다', async () => {
    testUtils = createLayoutTest(
      buildLayout([{ identifier: 'p1', incompatible_required_version: '>=7.5.0' }]),
      { translations, locale: 'ko', componentRegistry: registry },
    );
    await testUtils.render();

    expect(screen.getByTestId('link-/admin/system/update')).toBeTruthy();
  });
});
