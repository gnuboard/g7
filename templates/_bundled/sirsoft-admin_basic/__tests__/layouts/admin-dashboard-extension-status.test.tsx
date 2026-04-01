/**
 * @file admin-dashboard-extension-status.test.tsx
 * @description 대시보드 확장 상태 카드 렌더링 테스트
 *
 * 테스트 대상:
 * - 모듈 상태 카드: status === 'active' 기반 활성/비활성 배지 표시
 * - 플러그인 상태 카드: 동일 패턴
 * - 템플릿 상태 카드: 동일 패턴
 * - 각 카드 헤더에 아이콘 및 "더보기" 버튼 존재
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

const TestButton: React.FC<{
  className?: string;
  disabled?: boolean;
  type?: string;
  children?: React.ReactNode;
  text?: string;
  'data-testid'?: string;
  onClick?: () => void;
}> = ({ className, disabled, type, children, text, 'data-testid': testId, onClick }) => (
  <button className={className} disabled={disabled} type={type as any} data-testid={testId} onClick={onClick}>
    {children || text}
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

const TestH2: React.FC<{
  className?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ className, children, text }) => (
  <h2 className={className}>{children || text}</h2>
);

const TestIcon: React.FC<{
  name?: string;
  className?: string;
}> = ({ name, className }) => (
  <i className={className} data-icon={name} data-testid={`icon-${name}`} />
);

const TestFragment: React.FC<{
  children?: React.ReactNode;
}> = ({ children }) => <>{children}</>;

// 컴포넌트 레지스트리 설정
function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// 모듈 상태 카드만 추출한 레이아웃
function createExtensionCardLayout(
  cardId: string,
  dataSourceId: string,
  endpoint: string,
  iconName: string,
  itemVar: string,
  titleKey: string,
  subtitleKey: string,
  activeKey: string,
  inactiveKey: string,
  morePath: string,
  permission: string
) {
  return {
    data_sources: [
      {
        id: dataSourceId,
        type: 'api',
        endpoint,
        method: 'GET',
        auto_fetch: true,
        auth_required: true,
        loading_strategy: 'progressive',
        params: { per_page: '5' },
        fallback: {
          data: { data: [], current_page: 1, last_page: 1, per_page: 5, total: 0 },
        },
      },
    ],
    components: [
      {
        id: cardId,
        permissions: [permission],
        type: 'basic',
        name: 'Div',
        children: [
          {
            id: `${cardId}_header`,
            type: 'basic',
            name: 'Div',
            props: { className: 'flex items-center justify-between mb-4' },
            children: [
              {
                id: `${cardId}_header_left`,
                type: 'basic',
                name: 'Div',
                props: { className: 'flex items-center gap-2' },
                children: [
                  {
                    id: `${cardId}_header_icon`,
                    type: 'basic',
                    name: 'Icon',
                    props: { name: iconName },
                  },
                  {
                    id: `${cardId}_header_text`,
                    type: 'basic',
                    name: 'Div',
                    children: [
                      {
                        id: `${cardId}_title`,
                        type: 'basic',
                        name: 'H2',
                        text: titleKey,
                      },
                      {
                        id: `${cardId}_subtitle`,
                        type: 'basic',
                        name: 'P',
                        text: subtitleKey,
                      },
                    ],
                  },
                ],
              },
              {
                id: `${cardId}_more_btn`,
                type: 'basic',
                name: 'Button',
                props: { type: 'button' },
                text: '더보기',
                actions: {
                  onClick: [{ handler: 'navigate', params: { path: morePath } }],
                },
              },
            ],
          },
          {
            id: `${cardId}_list`,
            type: 'basic',
            name: 'Div',
            children: [
              {
                id: `${cardId}_item`,
                type: 'basic',
                name: 'Div',
                props: { className: 'flex items-center justify-between py-2' },
                iteration: {
                  source: `${dataSourceId}?.data?.data`,
                  item_var: itemVar,
                },
                children: [
                  {
                    id: `${cardId}_info`,
                    type: 'basic',
                    name: 'Div',
                    children: [
                      {
                        id: `${cardId}_name`,
                        type: 'basic',
                        name: 'P',
                        text: `{{${itemVar}.name}}`,
                      },
                      {
                        id: `${cardId}_version`,
                        type: 'basic',
                        name: 'P',
                        text: `v{{${itemVar}.version}}`,
                      },
                    ],
                  },
                  {
                    id: `${cardId}_badge`,
                    type: 'basic',
                    name: 'Span',
                    props: {
                      className: `{{${itemVar}.status === 'active' ? 'badge-active' : 'badge-inactive'}}`,
                    },
                    text: `{{${itemVar}.status === 'active' ? '${activeKey}' : '${inactiveKey}'}}`,
                  },
                ],
              },
            ],
          },
        ],
      },
    ],
  };
}

describe('대시보드 확장 상태 카드', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  describe('모듈 상태 카드', () => {
    const layout = createExtensionCardLayout(
      'module_status_card',
      'dashboard_modules',
      '/api/admin/modules/installed',
      'cube',
      'module',
      '모듈 상태',
      '설치된 모듈들의 현재 상태',
      '활성화',
      '비활성화',
      '/admin/modules',
      'core.modules.read'
    );

    it('활성 모듈은 활성화 배지로 표시된다', async () => {
      const testUtils = createLayoutTest(layout, { componentRegistry: registry });
      testUtils.mockApi('dashboard_modules', {
        response: {
          data: {
            data: [
              { name: '게시판', version: '0.7.0', status: 'active' },
              { name: '이커머스', version: '0.16.1', status: 'inactive' },
            ],
          },
        },
      });
      await testUtils.render();

      const badges = document.querySelectorAll('[class*="badge-"]');
      expect(badges.length).toBe(2);
      expect(badges[0].className).toContain('badge-active');
      expect(badges[0].textContent).toBe('활성화');
      expect(badges[1].className).toContain('badge-inactive');
      expect(badges[1].textContent).toBe('비활성화');

      testUtils.cleanup();
    });

    it('cubes 아이콘이 헤더에 표시된다', async () => {
      const testUtils = createLayoutTest(layout, { componentRegistry: registry });
      testUtils.mockApi('dashboard_modules', {
        response: { data: { data: [] } },
      });
      await testUtils.render();

      expect(screen.getByTestId('icon-cube')).toBeInTheDocument();

      testUtils.cleanup();
    });

    it('더보기 버튼이 존재한다', async () => {
      const testUtils = createLayoutTest(layout, { componentRegistry: registry });
      testUtils.mockApi('dashboard_modules', {
        response: { data: { data: [] } },
      });
      await testUtils.render();

      expect(screen.getByText('더보기')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('플러그인 상태 카드', () => {
    const layout = createExtensionCardLayout(
      'plugin_status_card',
      'dashboard_plugins',
      '/api/admin/plugins/installed',
      'puzzle-piece',
      'plugin',
      '플러그인 상태',
      '설치된 플러그인들의 현재 상태',
      '활성화',
      '비활성화',
      '/admin/plugins',
      'core.plugins.read'
    );

    it('활성 플러그인은 활성화 배지로 표시된다', async () => {
      const testUtils = createLayoutTest(layout, { componentRegistry: registry });
      testUtils.mockApi('dashboard_plugins', {
        response: {
          data: {
            data: [
              { name: '결제', version: '1.0.0', status: 'active' },
            ],
          },
        },
      });
      await testUtils.render();

      const badges = document.querySelectorAll('[class*="badge-"]');
      expect(badges.length).toBe(1);
      expect(badges[0].className).toContain('badge-active');
      expect(badges[0].textContent).toBe('활성화');

      testUtils.cleanup();
    });

    it('plug 아이콘이 헤더에 표시된다', async () => {
      const testUtils = createLayoutTest(layout, { componentRegistry: registry });
      testUtils.mockApi('dashboard_plugins', {
        response: { data: { data: [] } },
      });
      await testUtils.render();

      expect(screen.getByTestId('icon-puzzle-piece')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('템플릿 상태 카드', () => {
    const layout = createExtensionCardLayout(
      'template_status_card',
      'dashboard_templates',
      '/api/admin/templates',
      'palette',
      'template',
      '템플릿 상태',
      '설치된 템플릿들의 현재 상태',
      '활성화',
      '비활성화',
      '/admin/templates/admin',
      'core.templates.read'
    );

    it('활성 템플릿은 활성화 배지로 표시된다', async () => {
      const testUtils = createLayoutTest(layout, { componentRegistry: registry });
      testUtils.mockApi('dashboard_templates', {
        response: {
          data: {
            data: [
              { name: 'Admin Basic', version: '0.2.21', status: 'active' },
              { name: 'User Basic', version: '0.1.0', status: 'active' },
            ],
          },
        },
      });
      await testUtils.render();

      const badges = document.querySelectorAll('[class*="badge-"]');
      expect(badges.length).toBe(2);
      expect(badges[0].className).toContain('badge-active');
      expect(badges[1].className).toContain('badge-active');

      testUtils.cleanup();
    });

    it('puzzle-piece 아이콘이 헤더에 표시된다', async () => {
      const testUtils = createLayoutTest(layout, { componentRegistry: registry });
      testUtils.mockApi('dashboard_templates', {
        response: { data: { data: [] } },
      });
      await testUtils.render();

      expect(screen.getByTestId('icon-palette')).toBeInTheDocument();

      testUtils.cleanup();
    });
  });

  describe('모듈 상태 버그 수정 검증 (is_active → status)', () => {
    /**
     * 이전 버그: module.is_active 참조 시 undefined → 항상 비활성화 표시
     * 수정: module.status === 'active' 사용
     */
    const layout = createExtensionCardLayout(
      'module_status_card',
      'dashboard_modules',
      '/api/admin/modules/installed',
      'cube',
      'module',
      '모듈 상태',
      '설치된 모듈들의 현재 상태',
      '활성화',
      '비활성화',
      '/admin/modules',
      'core.modules.read'
    );

    it('status=active 모듈은 is_active 없이도 활성화로 표시된다', async () => {
      const testUtils = createLayoutTest(layout, { componentRegistry: registry });
      // is_active 필드 없이 status만 있는 API 응답 (실제 ModuleResource 출력)
      testUtils.mockApi('dashboard_modules', {
        response: {
          data: {
            data: [
              { name: '게시판', version: '0.7.0', status: 'active' },
              { name: '이커머스', version: '0.16.1', status: 'active' },
            ],
          },
        },
      });
      await testUtils.render();

      const badges = document.querySelectorAll('[class*="badge-"]');
      expect(badges.length).toBe(2);
      // 이전에는 모두 badge-inactive였음 (is_active가 undefined)
      expect(badges[0].className).toContain('badge-active');
      expect(badges[1].className).toContain('badge-active');
      expect(badges[0].textContent).toBe('활성화');
      expect(badges[1].textContent).toBe('활성화');

      testUtils.cleanup();
    });
  });
});
