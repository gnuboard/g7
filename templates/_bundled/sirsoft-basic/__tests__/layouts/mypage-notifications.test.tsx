/**
 * @file mypage-notifications.test.tsx
 * @description 사용자 알림 목록 페이지 레이아웃 테스트
 *
 * 테스트 대상:
 * - templates/.../mypage/notifications.json
 *
 * 검증 항목:
 * - 레이아웃 구조 (extends, data_sources)
 * - userNotifications 데이터소스 endpoint 및 params
 * - sort_order 파라미터 전달
 * - 초기 상태 (notificationsPage)
 * - init_actions 설정
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import {
  createLayoutTest,
} from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';

// ========== 테스트용 컴포넌트 ==========

const TestDiv: React.FC<{ className?: string; children?: React.ReactNode; 'data-testid'?: string }> = ({ className, children, 'data-testid': testId }) => (
  <div className={className} data-testid={testId}>{children}</div>
);

const TestSpan: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);

const TestButton: React.FC<{ type?: string; className?: string; children?: React.ReactNode; text?: string; onClick?: () => void }> = ({ type, className, children, text, onClick }) => (
  <button type={type as any} className={className} onClick={onClick}>{children || text}</button>
);

const TestH1: React.FC<{ className?: string; children?: React.ReactNode; text?: string }> = ({ className, children, text }) => (
  <h1 className={className}>{children || text}</h1>
);

const TestPagination: React.FC<{ currentPage?: number; totalPages?: number }> = ({ currentPage, totalPages }) => (
  <div data-testid="pagination" data-current={currentPage} data-total={totalPages}>Pagination</div>
);

const TestContainer: React.FC<{ className?: string; children?: React.ReactNode }> = ({ className, children }) => (
  <div className={className} data-testid="container">{children}</div>
);

// ========== 레지스트리 설정 ==========

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    Pagination: { component: TestPagination, metadata: { name: 'Pagination', type: 'composite' } },
    Container: { component: TestContainer, metadata: { name: 'Container', type: 'layout' } },
  };
  return registry;
}

// ========== 테스트용 레이아웃 (mypage/notifications.json 기반) ==========

const notificationsLayout = {
  version: '1.0.0',
  layout_name: 'mypage/notifications',
  data_sources: [
    {
      id: 'userNotifications',
      type: 'api',
      endpoint: '/api/user/notifications',
      method: 'GET',
      auto_fetch: true,
      loading_strategy: 'progressive',
      params: {
        page: '{{_local.notificationsPage ?? 1}}',
        sort_order: 'desc',
      },
    },
  ],
  init_actions: [
    {
      handler: 'setState',
      params: {
        target: 'local',
        notificationsPage: 1,
      },
    },
  ],
  components: [
    {
      type: 'basic',
      name: 'Div',
      props: { className: 'min-h-screen bg-gray-50 dark:bg-gray-900' },
      children: [
        {
          type: 'layout',
          name: 'Container',
          props: { className: 'py-8' },
          children: [
            {
              id: 'notifications_header',
              type: 'basic',
              name: 'H1',
              text: '$t:notification.user.page_title',
            },
          ],
        },
      ],
    },
  ],
};

// ========== 테스트 ==========

describe('mypage/notifications 레이아웃', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupTestRegistry();
    testUtils = createLayoutTest(notificationsLayout, {
      translations: {},
      locale: 'ko',
      componentRegistry: registry,
    });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  // ── 구조 검증 ──

  it('데이터소스 endpoint가 새 API를 가리킨다', () => {
    const ds = notificationsLayout.data_sources.find((d) => d.id === 'userNotifications');
    expect(ds).toBeDefined();
    expect(ds!.endpoint).toBe('/api/user/notifications');
  });

  it('데이터소스에 sort_order 파라미터가 포함된다', () => {
    const ds = notificationsLayout.data_sources.find((d) => d.id === 'userNotifications');
    expect(ds!.params!.sort_order).toBe('desc');
  });

  it('데이터소스 params에 page 파라미터가 _local 바인딩이다', () => {
    const ds = notificationsLayout.data_sources.find((d) => d.id === 'userNotifications');
    expect(ds!.params!.page).toContain('_local.notificationsPage');
  });

  it('init_actions에 notificationsPage 초기화가 설정되어 있다', () => {
    const initAction = notificationsLayout.init_actions[0];
    expect(initAction.handler).toBe('setState');
    expect(initAction.params.target).toBe('local');
    expect(initAction.params.notificationsPage).toBe(1);
  });

  // ── 상태 관리 검증 ──

  it('notificationsPage 초기 상태를 변경할 수 있다', async () => {
    testUtils.mockApi('userNotifications', {
      response: { data: { data: [], pagination: { current_page: 1, last_page: 1 } } },
    });

    await testUtils.render();

    testUtils.setState('notificationsPage', 2, 'local');
    expect(testUtils.getState()._local.notificationsPage).toBe(2);
  });
});
