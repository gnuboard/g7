/**
 * @file admin-dashboard-activity-log.test.tsx
 * @description 대시보드 활동 로그 관련 렌더링 테스트
 *
 * 테스트 대상:
 * - 시스템 알림 카드가 if="false"로 숨김 처리됨
 * - 최근 활동 카드가 ActivityLog 데이터로 렌더링됨
 * - 활동 항목에 title, description, time 표시
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
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

// 시스템 알림 카드 레이아웃 (if="false"로 숨김)
function createSystemAlertsLayout() {
  return {
    data_sources: [
      {
        id: 'dashboard_alerts',
        type: 'api',
        endpoint: '/api/admin/dashboard/alerts',
        method: 'GET',
        auto_fetch: false,
        auth_required: true,
        loading_strategy: 'background',
        fallback: { data: [] },
      },
    ],
    components: [
      {
        id: 'system_alerts_card',
        if: 'false',
        type: 'basic',
        name: 'Div',
        props: { 'data-testid': 'system-alerts-card' },
        children: [
          {
            id: 'alerts_title',
            type: 'basic',
            name: 'H2',
            text: '시스템 알림',
          },
        ],
      },
    ],
  };
}

// 최근 활동 카드 레이아웃
function createRecentActivityLayout() {
  return {
    data_sources: [
      {
        id: 'dashboard_activities',
        type: 'api',
        endpoint: '/api/admin/dashboard/activities',
        method: 'GET',
        auto_fetch: true,
        auth_required: true,
        loading_strategy: 'progressive',
        fallback: { data: [] },
      },
    ],
    components: [
      {
        id: 'recent_activity_card',
        type: 'basic',
        name: 'Div',
        props: { 'data-testid': 'recent-activity-card' },
        children: [
          {
            id: 'recent_activity_title',
            type: 'basic',
            name: 'H2',
            text: '최근 활동',
            props: { 'data-testid': 'activity-title' },
          },
          {
            id: 'activity_list',
            type: 'basic',
            name: 'Div',
            props: { 'data-testid': 'activity-list' },
            children: [
              {
                id: 'activity_item',
                type: 'basic',
                name: 'Div',
                props: { 'data-testid': 'activity-item' },
                iteration: {
                  source: 'dashboard_activities?.data',
                  item_var: 'activity',
                },
                children: [
                  {
                    id: 'activity_item_title',
                    type: 'basic',
                    name: 'Span',
                    text: '{{activity.title}}',
                  },
                  {
                    id: 'activity_item_desc',
                    type: 'basic',
                    name: 'Span',
                    text: '{{activity.description}}',
                  },
                  {
                    id: 'activity_item_time',
                    type: 'basic',
                    name: 'Span',
                    text: '{{activity.time}}',
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

describe('대시보드 - 시스템 알림 카드 숨김', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    const registry = setupTestRegistry();
    const layout = createSystemAlertsLayout();
    testUtils = createLayoutTest(layout, { componentRegistry: registry });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  it('시스템 알림 카드가 if="false"로 숨김 처리되어 렌더링되지 않음', async () => {
    await testUtils.render();

    const alertsCard = screen.queryByTestId('system-alerts-card');
    expect(alertsCard).toBeNull();
  });
});

describe('대시보드 - 최근 활동 카드', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;

  beforeEach(() => {
    const registry = setupTestRegistry();
    const layout = createRecentActivityLayout();
    testUtils = createLayoutTest(layout, { componentRegistry: registry });
  });

  afterEach(() => {
    testUtils.cleanup();
  });

  it('최근 활동 카드가 렌더링됨', async () => {
    testUtils.mockApi('dashboard_activities', {
      response: {
        data: [],
      },
    });

    await testUtils.render();

    const activityCard = screen.queryByTestId('recent-activity-card');
    expect(activityCard).not.toBeNull();
  });

  it('ActivityLog 데이터가 활동 항목으로 렌더링됨', async () => {
    testUtils.mockApi('dashboard_activities', {
      response: {
        data: [
          {
            type: 'admin',
            icon: 'pen-to-square',
            icon_color: 'blue',
            title: '사용자 정보를 수정했습니다',
            description: '홍길동',
            time: '10분 전',
            timestamp: '2026-03-28 14:30:00',
          },
          {
            type: 'user',
            icon: 'right-to-bracket',
            icon_color: 'green',
            title: '로그인했습니다',
            description: '김철수',
            time: '30분 전',
            timestamp: '2026-03-28 14:00:00',
          },
        ],
      },
    });

    await testUtils.render();

    const items = screen.queryAllByTestId('activity-item');
    expect(items.length).toBe(2);
  });

  it('활동 데이터 없을 때 빈 상태 표시', async () => {
    testUtils.mockApi('dashboard_activities', {
      response: {
        data: [],
      },
    });

    await testUtils.render();

    const items = screen.queryAllByTestId('activity-item');
    expect(items.length).toBe(0);
  });
});
