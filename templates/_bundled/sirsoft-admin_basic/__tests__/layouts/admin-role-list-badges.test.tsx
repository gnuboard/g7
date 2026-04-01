/**
 * @file admin-role-list-badges.test.tsx
 * @description 역할 목록 - extension_type별 뱃지 렌더링 테스트
 *
 * 테스트 대상:
 * - core 역할: shield 아이콘 + "시스템" 뱃지 (회색)
 * - module 역할: cube 아이콘 + 확장명 뱃지 (파란색)
 * - plugin 역할: plug 아이콘 + 확장명 뱃지 (보라색)
 * - user-created 역할(null): 뱃지 없음
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// 테스트용 컴포넌트 정의
const TestDiv: React.FC<{
  className?: string;
  children?: React.ReactNode;
  'data-testid'?: string;
}> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestSpan: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
}> = ({ className, children, text, 'data-testid': testId }) => (
  <span className={className} data-testid={testId}>{children || text}</span>
);

const TestIcon: React.FC<{
  name?: string;
  size?: string;
  'data-testid'?: string;
}> = ({ name, size, 'data-testid': testId }) => (
  <i data-testid={testId || `icon-${name}`} data-icon={name} data-size={size} />
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// 테스트용 레지스트리 설정
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// 이름 셀의 뱃지 부분만 추출한 최소 레이아웃
function createBadgeTestLayout(row: Record<string, any>) {
  return {
    version: '1.0.0',
    layout_name: 'admin_role_list_badge_test',
    initGlobal: {
      row,
    },
    slots: {
      content: [
        {
          id: 'name_cell',
          type: 'basic',
          name: 'Div',
          props: { className: 'flex items-center gap-2' },
          children: [
            {
              type: 'basic',
              name: 'Span',
              props: { className: 'font-medium' },
              text: '{{_global.row.name}}',
            },
            {
              id: 'badge_core',
              type: 'basic',
              name: 'Div',
              if: "{{_global.row.extension_type === 'core'}}",
              props: {
                className: 'inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-gray-100 text-gray-600',
              },
              children: [
                {
                  type: 'basic',
                  name: 'Icon',
                  props: { name: 'shield', size: 'sm' },
                },
                {
                  type: 'basic',
                  name: 'Span',
                  text: 'System',
                },
              ],
            },
            {
              id: 'badge_module',
              type: 'basic',
              name: 'Div',
              if: "{{_global.row.extension_type === 'module'}}",
              props: {
                className: 'inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-blue-100 text-blue-700',
              },
              children: [
                {
                  type: 'basic',
                  name: 'Icon',
                  props: { name: 'cube', size: 'sm' },
                },
                {
                  type: 'basic',
                  name: 'Span',
                  text: "{{_global.row.extension_name ?? 'Module'}}",
                },
              ],
            },
            {
              id: 'badge_plugin',
              type: 'basic',
              name: 'Div',
              if: "{{_global.row.extension_type === 'plugin'}}",
              props: {
                className: 'inline-flex items-center gap-1 px-2 py-0.5 rounded text-xs font-medium bg-purple-100 text-purple-700',
              },
              children: [
                {
                  type: 'basic',
                  name: 'Icon',
                  props: { name: 'plug', size: 'sm' },
                },
                {
                  type: 'basic',
                  name: 'Span',
                  text: "{{_global.row.extension_name ?? 'Plugin'}}",
                },
              ],
            },
          ],
        },
      ],
    },
  };
}

describe('admin_role_list - extension_type별 뱃지 렌더링', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  it('core 역할: shield 아이콘 + "System" 뱃지가 렌더링된다', async () => {
    const layout = createBadgeTestLayout({
      name: 'Admin',
      extension_type: 'core',
      extension_identifier: 'core',
      extension_name: null,
    });

    const testUtils = createLayoutTest(layout, { componentRegistry: registry });
    await testUtils.render();

    expect(screen.getByText('Admin')).toBeInTheDocument();
    expect(screen.getByTestId('icon-shield')).toBeInTheDocument();
    expect(screen.getByText('System')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('module 역할: cube 아이콘 + 확장명 뱃지가 렌더링된다', async () => {
    const layout = createBadgeTestLayout({
      name: 'Board Manager',
      extension_type: 'module',
      extension_identifier: 'sirsoft-board',
      extension_name: '게시판',
    });

    const testUtils = createLayoutTest(layout, { componentRegistry: registry });
    await testUtils.render();

    expect(screen.getByText('Board Manager')).toBeInTheDocument();
    expect(screen.getByTestId('icon-cube')).toBeInTheDocument();
    expect(screen.getByText('게시판')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('plugin 역할: plug 아이콘 + 확장명 뱃지가 렌더링된다', async () => {
    const layout = createBadgeTestLayout({
      name: 'Payment Admin',
      extension_type: 'plugin',
      extension_identifier: 'sirsoft-payment',
      extension_name: '토스페이먼츠',
    });

    const testUtils = createLayoutTest(layout, { componentRegistry: registry });
    await testUtils.render();

    expect(screen.getByText('Payment Admin')).toBeInTheDocument();
    expect(screen.getByTestId('icon-plug')).toBeInTheDocument();
    expect(screen.getByText('토스페이먼츠')).toBeInTheDocument();

    testUtils.cleanup();
  });

  it('user-created 역할(null): 뱃지가 렌더링되지 않는다', async () => {
    const layout = createBadgeTestLayout({
      name: 'Custom Role',
      extension_type: null,
      extension_identifier: null,
      extension_name: null,
    });

    const testUtils = createLayoutTest(layout, { componentRegistry: registry });
    await testUtils.render();

    expect(screen.getByText('Custom Role')).toBeInTheDocument();
    expect(screen.queryByTestId('icon-shield')).not.toBeInTheDocument();
    expect(screen.queryByTestId('icon-cube')).not.toBeInTheDocument();
    expect(screen.queryByTestId('icon-plug')).not.toBeInTheDocument();

    testUtils.cleanup();
  });

  it('module 역할에 extension_name이 없으면 폴백 "Module" 텍스트가 표시된다', async () => {
    const layout = createBadgeTestLayout({
      name: 'Unknown Module Role',
      extension_type: 'module',
      extension_identifier: 'unknown-module',
      extension_name: null,
    });

    const testUtils = createLayoutTest(layout, { componentRegistry: registry });
    await testUtils.render();

    expect(screen.getByTestId('icon-cube')).toBeInTheDocument();
    expect(screen.getByText('Module')).toBeInTheDocument();

    testUtils.cleanup();
  });
});
