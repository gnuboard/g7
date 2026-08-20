/**
 * @file admin-dashboard-iteration-id-unique.test.tsx
 * @description 대시보드 iteration 영역 HTML id 중복 방지 회귀 테스트
 *
 * 배경:
 * - iteration template 안의 id 가 정적 문자열이면 source row 마다 같은 id 가
 *   N 번 펼쳐져 W3C HTML id 유일성 위반 발생.
 * - 정적 분석은 1회 등장 → "중복 아님" 으로 오판하므로 실제 렌더 DOM 으로 검증한다.
 *
 * 검증 대상 (실제 admin_dashboard.json 의 iteration 영역):
 * - 활동 로그 (activity_item / dot / content / title / desc / time)
 * - 모듈 카드 (module_item / info / name / version / badge)
 * - 플러그인 카드 (plugin_item / info / name / version / badge)
 * - 템플릿 카드 (template_item / info / name / version / badge)
 * - 최근 알림 (recent_notification_item / dot / content / subject / recipient / time)
 * - 시스템 알림 (alert_item / icon / content / title / message / time / actions / buttons)
 *
 * 수정 전(정적 id): 각 영역 row 2개 이상 mock → 동일 id 가 2회 이상 → 테스트 fail.
 * 수정 후(동적 id): id 에 {{$idx}} 접미 → row 별 고유 → 중복 0 → green.
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import fs from 'fs';
import path from 'path';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// id 를 실제 DOM 속성으로 출력하는 테스트 컴포넌트 (중복 검출 핵심)
const TestDiv: React.FC<{ id?: string; className?: string; children?: React.ReactNode }> = ({
  id,
  className,
  children,
}) => (
  <div id={id} className={className}>
    {children}
  </div>
);

const TestSpan: React.FC<{ id?: string; className?: string; children?: React.ReactNode; text?: string }> = ({
  id,
  className,
  children,
  text,
}) => (
  <span id={id} className={className}>
    {children || text}
  </span>
);

const TestP: React.FC<{ id?: string; className?: string; children?: React.ReactNode; text?: string }> = ({
  id,
  className,
  children,
  text,
}) => (
  <p id={id} className={className}>
    {children || text}
  </p>
);

const TestH1: React.FC<{ id?: string; className?: string; children?: React.ReactNode; text?: string }> = ({
  id,
  className,
  children,
  text,
}) => (
  <h1 id={id} className={className}>
    {children || text}
  </h1>
);

const TestH2: React.FC<{ id?: string; className?: string; children?: React.ReactNode; text?: string }> = ({
  id,
  className,
  children,
  text,
}) => (
  <h2 id={id} className={className}>
    {children || text}
  </h2>
);

const TestButton: React.FC<{
  id?: string;
  className?: string;
  type?: string;
  children?: React.ReactNode;
  text?: string;
}> = ({ id, className, type, children, text }) => (
  <button id={id} className={className} type={type as any}>
    {children || text}
  </button>
);

const TestA: React.FC<{ id?: string; className?: string; href?: string; children?: React.ReactNode; text?: string }> = ({
  id,
  className,
  href,
  children,
  text,
}) => (
  <a id={id} className={className} href={href}>
    {children || text}
  </a>
);

const TestIcon: React.FC<{ id?: string; name?: string; className?: string }> = ({ id, name, className }) => (
  <i id={id} className={className} data-icon={name} />
);

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

const TestToast: React.FC = () => null;
const TestModalRoot: React.FC = () => null;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();

  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    A: { component: TestA, metadata: { name: 'A', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
    Toast: { component: TestToast, metadata: { name: 'Toast', type: 'composite' } },
    ModalRoot: { component: TestModalRoot, metadata: { name: 'ModalRoot', type: 'composite' } },
  };

  return registry;
}

// 실제 레이아웃 파일 로드 (extends 제거 — 독립 렌더)
function loadDashboardLayout(): any {
  const file = path.resolve(
    __dirname,
    '../../layouts/admin_dashboard.json'
  );
  const layout = JSON.parse(fs.readFileSync(file, 'utf8'));

  // extends/_admin_base 의존 제거 후 slots.content 를 components 로 승격
  delete layout.extends;
  layout.components = layout.slots?.content ?? [];
  delete layout.slots;
  // permissions 게이트 제거 (테스트는 전 카드 렌더 목적)
  stripPermissions(layout.components);

  return layout;
}

function stripPermissions(nodes: any): void {
  if (Array.isArray(nodes)) {
    nodes.forEach(stripPermissions);
    return;
  }
  if (!nodes || typeof nodes !== 'object') return;
  delete nodes.permissions;
  for (const key of Object.keys(nodes)) {
    if (key === 'permissions') continue;
    stripPermissions(nodes[key]);
  }
}

// 여러 row 를 가진 mock 데이터 (정적 id 라면 중복을 유발할 양)
const MULTI_ROW = {
  activities: [
    { title: '활동 A', description: '설명 A', time: '1분 전' },
    { title: '활동 B', description: '설명 B', time: '2분 전' },
    { title: '활동 C', description: '설명 C', time: '3분 전' },
  ],
  paginated: (rows: any[]) => ({
    data: { data: rows, current_page: 1, last_page: 1, per_page: 5, total: rows.length },
  }),
};

describe('대시보드 iteration HTML id 유일성', () => {
  beforeEach(() => {
    setupTestRegistry();
  });

  function collectDuplicateIds(): string[] {
    const all = Array.from(document.querySelectorAll('[id]')).map((el) => el.id);
    const seen = new Set<string>();
    const dup = new Set<string>();
    for (const id of all) {
      if (seen.has(id)) dup.add(id);
      else seen.add(id);
    }
    return Array.from(dup);
  }

  it('모든 카드가 다중 row 로 채워져도 HTML id 중복이 0건이다', async () => {
    const layout = loadDashboardLayout();
    const testUtils = createLayoutTest(layout, { componentRegistry: setupTestRegistry() });

    testUtils.mockApi('dashboard_stats', {
      response: {
        data: {
          installed_modules: { active: 2, total: 3 },
          active_plugins: { active: 1, total: 2 },
          installed_templates: { active: 2, total: 2 },
          language_packs: { active: 1, total: 1 },
        },
      },
    });
    testUtils.mockApi('dashboard_activities', { response: { data: MULTI_ROW.activities } });
    testUtils.mockApi('dashboard_modules', {
      response: MULTI_ROW.paginated([
        { name: '게시판', version: '0.7.0', status: 'active' },
        { name: '이커머스', version: '0.16.1', status: 'inactive' },
        { name: '결제', version: '1.0.0', status: 'active' },
      ]),
    });
    testUtils.mockApi('dashboard_plugins', {
      response: MULTI_ROW.paginated([
        { name: '플러그인 A', version: '1.0.0', status: 'active' },
        { name: '플러그인 B', version: '2.0.0', status: 'inactive' },
      ]),
    });
    testUtils.mockApi('dashboard_templates', {
      response: MULTI_ROW.paginated([
        { name: 'Admin Basic', version: '0.2.21', status: 'active' },
        { name: 'User Basic', version: '0.1.0', status: 'active' },
      ]),
    });
    testUtils.mockApi('dashboard_recent_notifications', {
      response: {
        data: [
          { subject: '알림 1', recipient: 'a@x.com', time: '1분 전', status: 'sent' },
          { subject: '알림 2', recipient: 'b@x.com', time: '2분 전', status: 'failed' },
        ],
      },
    });
    testUtils.mockApi('dashboard_alerts', {
      response: {
        data: [
          {
            subtype: 'recovery_available',
            icon: 'check-circle',
            title: '복구 가능 1',
            message: '메시지 1',
            time: '방금',
            recover_endpoint: '/api/admin/x/1/recover',
            extension_type: 'module',
            identifier: 'm1',
          },
          {
            subtype: 'incompatible_core',
            icon: 'exclamation',
            title: '비호환 1',
            message: '메시지 2',
            time: '5분 전',
            extension_type: 'plugin',
            identifier: 'p1',
          },
        ],
      },
    });

    await testUtils.render();

    const duplicates = collectDuplicateIds();
    expect(duplicates).toEqual([]);

    testUtils.cleanup();
  });

  it('iteration row 가 늘어나도 id 중복이 발생하지 않는다 (활동 로그 단독 검증)', async () => {
    const layout = loadDashboardLayout();
    const testUtils = createLayoutTest(layout, { componentRegistry: setupTestRegistry() });

    // 활동 로그만 다량 채우고 나머지는 빈 배열
    testUtils.mockApi('dashboard_stats', { response: { data: {} } });
    testUtils.mockApi('dashboard_activities', {
      response: {
        data: Array.from({ length: 10 }, (_, i) => ({
          title: `활동 ${i}`,
          description: `설명 ${i}`,
          time: `${i}분 전`,
        })),
      },
    });
    testUtils.mockApi('dashboard_modules', { response: MULTI_ROW.paginated([]) });
    testUtils.mockApi('dashboard_plugins', { response: MULTI_ROW.paginated([]) });
    testUtils.mockApi('dashboard_templates', { response: MULTI_ROW.paginated([]) });
    testUtils.mockApi('dashboard_recent_notifications', { response: { data: [] } });
    testUtils.mockApi('dashboard_alerts', { response: { data: [] } });

    await testUtils.render();

    // 활동 항목 id 가 row 별로 고유하게 펼쳐졌는지 확인
    const activityItems = Array.from(document.querySelectorAll('[id^="activity_item_"]'));
    expect(activityItems.length).toBe(10);
    const activityIds = activityItems.map((el) => el.id);
    expect(new Set(activityIds).size).toBe(10);

    expect(collectDuplicateIds()).toEqual([]);

    testUtils.cleanup();
  });
});
