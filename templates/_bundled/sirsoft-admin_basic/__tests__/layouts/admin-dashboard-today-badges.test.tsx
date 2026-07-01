/**
 * @file admin-dashboard-today-badges.test.tsx
 * @description 관리자 대시보드 템플릿 레이아웃 검증 — 코어 stats 카드 제거 및 확장 포인트 위임
 *
 * 이커머스 "오늘" 배지/판매 차트 등 커머스 콘텐츠는 sirsoft-ecommerce 모듈의
 * admin_dashboard_commerce.json 확장으로 주입되며, 해당 검증은 모듈 테스트
 * (admin_dashboard_commerce_extension.test.tsx) 가 담당한다. 본 템플릿 테스트는
 * 템플릿 JSON 에 실제 존재하는 것(코어 카드 제거 + 확장 포인트 존재)만 검증한다.
 */

import React from 'react';
import { describe, it, expect } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  screen,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import adminDashboardLayout from '../../layouts/admin_dashboard.json';
import adminKo from '../../lang/partial/ko/admin.json';

const TestIcon: React.FC<{ name?: string; className?: string }> = ({ name, className }) => (
  <span className={className} data-icon={name} />
);

const TestBarChart: React.FC = () => <div data-testid="bar-chart" />;

function createRegistry() {
  const registry = createMockComponentRegistryWithBasics();
  registry.register('basic', 'Icon', TestIcon);
  registry.register('composite', 'BarChart', TestBarChart);

  return registry;
}

function findComponentById(node: unknown, id: string): unknown {
  if (!node || typeof node !== 'object') {
    return undefined;
  }

  const value = node as { id?: string; [key: string]: unknown };
  if (value.id === id) {
    return value;
  }

  for (const child of Object.values(value)) {
    if (Array.isArray(child)) {
      for (const item of child) {
        const found = findComponentById(item, id);
        if (found) {
          return found;
        }
      }
      continue;
    }

    const found = findComponentById(child, id);
    if (found) {
      return found;
    }
  }

  return undefined;
}

function findExtensionPointByName(node: unknown, name: string): unknown {
  if (!node || typeof node !== 'object') {
    return undefined;
  }

  const value = node as { type?: string; name?: string; [key: string]: unknown };
  if (value.type === 'extension_point' && value.name === name) {
    return value;
  }

  for (const child of Object.values(value)) {
    if (Array.isArray(child)) {
      for (const item of child) {
        const found = findExtensionPointByName(item, name);
        if (found) {
          return found;
        }
      }
      continue;
    }

    const found = findExtensionPointByName(child, name);
    if (found) {
      return found;
    }
  }

  return undefined;
}

describe('관리자 대시보드 템플릿 — 코어 stats 카드 제거', () => {
  it('코어 stats 카드(new_*/전체회원수)와 stats_cards_grid 컨테이너가 모두 제거된다', () => {
    // 새게시물/새주문/새리뷰 카드는 제거됨
    expect(findComponentById(adminDashboardLayout, 'new_posts_card')).toBeUndefined();
    expect(findComponentById(adminDashboardLayout, 'new_orders_card')).toBeUndefined();
    expect(findComponentById(adminDashboardLayout, 'new_reviews_card')).toBeUndefined();
    // 전체회원수 카드와 이를 담던 stats_cards_grid 컨테이너도 제거됨
    expect(findComponentById(adminDashboardLayout, 'total_users_card')).toBeUndefined();
    expect(findComponentById(adminDashboardLayout, 'stats_cards_grid')).toBeUndefined();
    // 게시판 오늘 요약은 게시판 모듈 extension 으로 이관되어 템플릿에는 없음
    expect(findComponentById(adminDashboardLayout, 'post_graph_today_summary')).toBeUndefined();
    expect(findComponentById(adminDashboardLayout, 'community_section')).toBeUndefined();
  });

  it('커머스/커뮤니티 콘텐츠는 확장 포인트로 위임된다', () => {
    // 이커머스 "오늘" 배지/판매 차트 등은 admin_dashboard_commerce 확장으로 주입되며
    // 템플릿 JSON 에는 확장 포인트만 존재한다 (실제 콘텐츠 검증은 모듈 테스트가 담당).
    expect(findExtensionPointByName(adminDashboardLayout, 'admin_dashboard_commerce')).toBeDefined();
    expect(findExtensionPointByName(adminDashboardLayout, 'admin_dashboard_community')).toBeDefined();
    expect(findExtensionPointByName(adminDashboardLayout, 'admin_dashboard_quick_menu')).toBeDefined();
  });

  it('전체회원수 카드 라벨/값이 더 이상 렌더되지 않는다', async () => {
    const testUtils = createLayoutTest(adminDashboardLayout as any, {
      componentRegistry: createRegistry() as any,
      translations: { admin: adminKo },
      templateId: 'sirsoft-admin_basic',
      locale: 'ko',
    });

    testUtils.mockApi('dashboard_stats', {
      response: {
        data: {
          total_users: { count: 10, change_display: '+0' },
          installed_modules: { total: 0, active: 0 },
          active_plugins: { total: 0, active: 0 },
          installed_templates: { total: 0, active: 0 },
          language_packs: { total: 0, active: 0 },
          system_status: { label: '정상' },
        },
      },
    });

    await testUtils.render();

    // 전체회원수 카드 라벨이 렌더되지 않음
    expect(screen.queryByText('전체회원수')).not.toBeInTheDocument();
    // 제거된 코어 stats 카드 라벨도 렌더되지 않음
    expect(screen.queryByText('새주문')).not.toBeInTheDocument();
    expect(screen.queryByText('새리뷰')).not.toBeInTheDocument();

    testUtils.cleanup();
  });
});
