/**
 * @file admin_dashboard.alerts.test.tsx
 * @description 어드민 대시보드 - 시스템 알림 (incompatible_core) 렌더 회귀 테스트
 *
 * 검증 항목:
 * - dashboard_alerts 데이터소스에 incompatible_core subtype 알림 존재 시 system_alerts 카드 렌더
 * - 이전 회귀: system_alerts_card 의 if:false 가 제거되어 카드가 실제로 표시되어야 함
 * - 알림 본문 (title, message, time) 렌더 확인
 * - dismiss 버튼 클릭 액션 정의 검증 (POST /api/admin/extensions/{type}/{id}/dismiss)
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import path from 'path';
import fs from 'fs';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

// ==============================
// 실제 admin_dashboard.json 로드
// ==============================

const layoutPath = path.resolve(__dirname, '../../layouts/admin_dashboard.json');
const dashboardLayout: any = JSON.parse(fs.readFileSync(layoutPath, 'utf-8'));

// ==============================
// 테스트용 컴포넌트
// ==============================

const TestDiv: React.FC<any> = ({ className, children, role, style }) => (
  <div className={className} role={role} style={style}>
    {children}
  </div>
);
const TestSpan: React.FC<any> = ({ className, children, text }) => (
  <span className={className}>{children || text}</span>
);
const TestP: React.FC<any> = ({ className, children, text }) => (
  <p className={className}>{children || text}</p>
);
const TestH1: React.FC<any> = ({ className, children, text }) => (
  <h1 className={className}>{children || text}</h1>
);
const TestH2: React.FC<any> = ({ className, children, text }) => (
  <h2 className={className}>{children || text}</h2>
);
const TestIcon: React.FC<any> = ({ name, size, className }) => (
  <i
    className={`icon-${name} ${className || ''}`}
    data-testid={`icon-${name}`}
    data-size={size}
  />
);
const TestButton: React.FC<any> = ({
  type,
  disabled,
  className,
  children,
  'aria-label': ariaLabel,
}) => (
  <button
    type={type}
    disabled={disabled}
    className={className}
    aria-label={ariaLabel}
  >
    {children}
  </button>
);
const TestFragment: React.FC<any> = ({ children }) => <>{children}</>;

function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: TestDiv, metadata: { name: 'Div', type: 'basic' } },
    Span: { component: TestSpan, metadata: { name: 'Span', type: 'basic' } },
    P: { component: TestP, metadata: { name: 'P', type: 'basic' } },
    H1: { component: TestH1, metadata: { name: 'H1', type: 'basic' } },
    H2: { component: TestH2, metadata: { name: 'H2', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Button: { component: TestButton, metadata: { name: 'Button', type: 'basic' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };
  return registry;
}

const translations = {
  admin: {
    dashboard: {
      title: '대시보드',
      description: '관리자 대시보드',
      refresh: '새로고침',
      refreshed: '새로고침 완료',
      stats: {
        total_users: '전체 사용자',
        installed_modules: '설치된 모듈',
        active_plugins: '활성 플러그인',
        system_status: '시스템 상태',
        active_count: '{{count}}개 활성',
        total_installed: '총 {{count}}개 설치됨',
        all_services_running: '모든 서비스 정상',
      },
      system_resources: {
        title: '시스템 리소스',
        subtitle: '리소스 사용량',
        cpu_usage: 'CPU',
        memory_usage: '메모리',
        disk_usage: '디스크',
      },
      recent_activity: { title: '최근 활동', subtitle: '활동 내역' },
      module_status: { title: '모듈 상태', subtitle: '모듈', active: '활성', inactive: '비활성' },
      plugin_status: { title: '플러그인 상태', subtitle: '플러그인', active: '활성', inactive: '비활성' },
      template_status: { title: '템플릿 상태', subtitle: '템플릿', active: '활성', inactive: '비활성' },
      extension_status: { more: '더보기' },
      system_alerts: {
        title: '시스템 알림',
        subtitle: '주의가 필요한 항목',
      },
    },
  },
  extensions: {
    alerts: {
      recover_action: '다시 활성화',
      recovered_success: '재활성화 완료',
      dismiss_action: '닫기',
    },
  },
};

// ==============================
// 테스트
// ==============================

describe('어드민 대시보드 - 시스템 알림 (incompatible_core)', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupRegistry();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  it('경고 등급 알림은 상단 배너에 렌더되고 하단 시스템 알림 카드는 뜨지 않는다', async () => {
    testUtils = createLayoutTest(dashboardLayout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Admin' }, authType: 'admin' },
    });

    testUtils.mockApi('dashboard_stats', {
      response: {
        total_users: { count: 0, change_display: '-' },
        installed_modules: { total: 0, active: 0 },
        active_plugins: { total: 0, active: 0 },
        system_status: { label: 'OK' },
      },
    });
    testUtils.mockApi('dashboard_resources', {
      response: { cpu: { percentage: 0 }, memory: { percentage: 0 }, disk: { percentage: 0 } },
    });
    testUtils.mockApi('dashboard_activities', { response: { data: [] } });
    testUtils.mockApi('dashboard_modules', {
      response: { data: [], current_page: 1, last_page: 1, per_page: 5, total: 0 },
    });
    testUtils.mockApi('dashboard_plugins', {
      response: { data: [], current_page: 1, last_page: 1, per_page: 5, total: 0 },
    });
    testUtils.mockApi('dashboard_templates', {
      response: { data: [], current_page: 1, last_page: 1, per_page: 5, total: 0 },
    });
    testUtils.mockApi('dashboard_alerts', {
      response: {
        data: [
          {
            // ExtensionCompatibilityAlertListener 가 실제로 넣는 값 — 배치를 가르는 축이다.
            type: 'warning',
            subtype: 'incompatible_core',
            extension_type: 'plugin',
            identifier: 'sirsoft-payment',
            title: '결제 플러그인 자동 비활성화',
            message: '코어 >=7.5.0 필요 (현재 7.0.0)',
            time: '5분 전',
            icon: 'exclamation-triangle',
          },
        ],
      },
    });

    await testUtils.render();

    // 본문은 상단 배너에 그대로 표시된다 (배치가 바뀌어도 내용이 사라지지 않는다)
    expect(screen.getByText('결제 플러그인 자동 비활성화')).toBeTruthy();
    expect(screen.getByText('코어 >=7.5.0 필요 (현재 7.0.0)')).toBeTruthy();
    expect(screen.getByText('5분 전')).toBeTruthy();

    // 경고만 있으면 하단 '시스템 알림' 카드는 뜨지 않는다 — 같은 알림이 두 곳에
    // 중복 노출되면 안 된다. 본문이 위에서 확인됐으므로 이 부재 단언이 곧 배치 증거다.
    expect(screen.queryByText('시스템 알림')).toBeNull();

    // 제목은 정확히 1회만 나타난다 (상단·하단 양쪽 렌더 = 중복 노출 회귀 차단)
    expect(screen.queryAllByText('결제 플러그인 자동 비활성화').length).toBe(1);
  });

  it('알림 배열이 비어있을 때 시스템 알림 카드가 렌더되지 않는다', async () => {
    testUtils = createLayoutTest(dashboardLayout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Admin' }, authType: 'admin' },
    });

    testUtils.mockApi('dashboard_stats', {
      response: {
        total_users: { count: 0, change_display: '-' },
        installed_modules: { total: 0, active: 0 },
        active_plugins: { total: 0, active: 0 },
        system_status: { label: 'OK' },
      },
    });
    testUtils.mockApi('dashboard_resources', {
      response: { cpu: { percentage: 0 }, memory: { percentage: 0 }, disk: { percentage: 0 } },
    });
    testUtils.mockApi('dashboard_activities', { response: { data: [] } });
    testUtils.mockApi('dashboard_modules', {
      response: { data: [], current_page: 1, last_page: 1, per_page: 5, total: 0 },
    });
    testUtils.mockApi('dashboard_plugins', {
      response: { data: [], current_page: 1, last_page: 1, per_page: 5, total: 0 },
    });
    testUtils.mockApi('dashboard_templates', {
      response: { data: [], current_page: 1, last_page: 1, per_page: 5, total: 0 },
    });
    testUtils.mockApi('dashboard_alerts', { response: { data: [] } });

    await testUtils.render();

    expect(screen.queryByText('시스템 알림')).toBeNull();
  });

  it('dismiss 버튼 액션이 POST /api/admin/extensions/{type}/{id}/dismiss 로 정의되어 있다', () => {
    // JSON 구조 검증 — alert_dismiss_button 의 actions[0].target
    // iteration template 안의 노드는 HTML id 유일성을 위해 `{{$idx}}` 접미가 붙는다(#421).
    // 정확 일치만 보면 보간 도입 시 조용히 null 이 되므로 접미 형태도 인정한다.
    function findById(node: any, id: string): any | null {
      if (!node || typeof node !== 'object') return null;
      if (node.id === id || node.id === `${id}_{{$idx}}`) return node;
      const keys = ['children'];
      for (const k of keys) {
        const arr = node[k];
        if (Array.isArray(arr)) {
          for (const c of arr) {
            const r = findById(c, id);
            if (r) return r;
          }
        }
      }
      // slots
      if (node.slots) {
        for (const slotName of Object.keys(node.slots)) {
          const arr = node.slots[slotName];
          if (Array.isArray(arr)) {
            for (const c of arr) {
              const r = findById(c, id);
              if (r) return r;
            }
          }
        }
      }
      return null;
    }
    const dismissBtn = findById(dashboardLayout, 'alert_dismiss_button');
    expect(dismissBtn).not.toBeNull();
    expect(dismissBtn.if).toBe('{{alert.extension_type && alert.identifier}}');

    const action = dismissBtn.actions[0];
    expect(action.type).toBe('click');
    expect(action.handler).toBe('apiCall');
    expect(action.target).toBe(
      '/api/admin/extensions/{{alert.extension_type}}/{{alert.identifier}}/dismiss',
    );
    expect(action.params.method).toBe('POST');

    // onSuccess 에 dashboard_alerts 재조회가 포함되어야 함
    const refetch = action.onSuccess.find(
      (a: any) => a.handler === 'refetchDataSource',
    );
    expect(refetch).toBeDefined();
    expect(refetch.params.dataSourceId).toBe('dashboard_alerts');
  });

  it('system_alerts_card 의 if 조건이 if:false 가 아닌 dashboard_alerts.length 기반이다 (회귀)', () => {
    // iteration template 안의 노드는 HTML id 유일성을 위해 `{{$idx}}` 접미가 붙는다(#421).
    // 정확 일치만 보면 보간 도입 시 조용히 null 이 되므로 접미 형태도 인정한다.
    function findById(node: any, id: string): any | null {
      if (!node || typeof node !== 'object') return null;
      if (node.id === id || node.id === `${id}_{{$idx}}`) return node;
      const arr = node.children;
      if (Array.isArray(arr)) {
        for (const c of arr) {
          const r = findById(c, id);
          if (r) return r;
        }
      }
      if (node.slots) {
        for (const slotName of Object.keys(node.slots)) {
          const slotArr = node.slots[slotName];
          if (Array.isArray(slotArr)) {
            for (const c of slotArr) {
              const r = findById(c, id);
              if (r) return r;
            }
          }
        }
      }
      return null;
    }
    const card = findById(dashboardLayout, 'system_alerts_card');
    expect(card).not.toBeNull();
    expect(card.if).toBeDefined();
    // if:false 회귀 차단
    expect(card.if).not.toBe(false);
    expect(card.if).not.toBe('false');
    // 알림 데이터소스 길이 기반 표현식
    expect(typeof card.if).toBe('string');
    expect(card.if).toContain('dashboard_alerts');
    expect(card.if).toContain('length');
  });
});
