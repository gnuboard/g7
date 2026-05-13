/**
 * @file admin_dashboard.recovery.test.tsx
 * @description 어드민 대시보드 - 재호환 알림 (recovery_available) 렌더 + recover 액션 검증
 *
 * 검증 항목:
 * - dashboard_alerts 의 subtype=recovery_available 알림 렌더
 * - "다시 활성화" 버튼이 표시됨 (alert.recover_endpoint 가 있는 경우)
 * - 버튼 클릭 액션이 alert.recover_endpoint 를 POST 로 호출
 * - 호출 성공 후 dashboard_alerts 가 refetchDataSource 로 재조회됨
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
    data-testid={
      ariaLabel === '닫기'
        ? 'dismiss-btn'
        : className?.includes('bg-blue-600')
          ? 'recover-btn'
          : undefined
    }
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
      system_alerts: { title: '시스템 알림', subtitle: '주의가 필요한 항목' },
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
// 공통 헬퍼
// ==============================

function setupDefaultMocks(testUtils: ReturnType<typeof createLayoutTest>) {
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
}

// 레이아웃 트리에서 id 로 노드 찾기
function findById(node: any, id: string): any | null {
  if (!node || typeof node !== 'object') return null;
  if (node.id === id) return node;
  if (Array.isArray(node.children)) {
    for (const c of node.children) {
      const r = findById(c, id);
      if (r) return r;
    }
  }
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

// ==============================
// 테스트
// ==============================

describe('어드민 대시보드 - 재호환 알림 (recovery_available)', () => {
  let testUtils: ReturnType<typeof createLayoutTest>;
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupRegistry();
  });

  afterEach(() => {
    if (testUtils) testUtils.cleanup();
  });

  it('recovery_available 알림이 있을 때 본문이 표시되고 "다시 활성화" 버튼이 렌더된다', async () => {
    testUtils = createLayoutTest(dashboardLayout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Admin' }, authType: 'admin' },
    });
    setupDefaultMocks(testUtils);
    testUtils.mockApi('dashboard_alerts', {
      response: {
        data: [
          {
            subtype: 'recovery_available',
            extension_type: 'plugin',
            identifier: 'sirsoft-payment',
            recover_endpoint: '/api/admin/extensions/plugin/sirsoft-payment/recover',
            title: '결제 플러그인 다시 활성화 가능',
            message: '코어가 호환 가능한 버전으로 업그레이드되었습니다',
            time: '방금 전',
            icon: 'check-circle',
          },
        ],
      },
    });

    await testUtils.render();

    expect(screen.getByText('시스템 알림')).toBeTruthy();
    expect(screen.getByText('결제 플러그인 다시 활성화 가능')).toBeTruthy();
    expect(screen.getByText('다시 활성화')).toBeTruthy();
    expect(screen.getByTestId('recover-btn')).toBeTruthy();
  });

  it('recover 버튼 액션이 alert.recover_endpoint 를 POST 로 호출하도록 정의되어 있다', () => {
    const recoverBtn = findById(dashboardLayout, 'alert_recover_button');
    expect(recoverBtn).not.toBeNull();
    expect(recoverBtn.if).toBe("{{alert.subtype === 'recovery_available'}}");

    const action = recoverBtn.actions[0];
    expect(action.type).toBe('click');
    expect(action.handler).toBe('apiCall');
    expect(action.auth_required).toBe(true);
    expect(action.target).toBe('{{alert.recover_endpoint}}');
    expect(action.params.method).toBe('POST');
  });

  it('recover 호출 성공 시 dashboard_alerts 데이터소스가 재조회되고 toast 가 발화된다', () => {
    const recoverBtn = findById(dashboardLayout, 'alert_recover_button');
    const action = recoverBtn.actions[0];

    expect(Array.isArray(action.onSuccess)).toBe(true);
    const toastAction = action.onSuccess.find((a: any) => a.handler === 'toast');
    expect(toastAction).toBeDefined();
    expect(toastAction.params.type).toBe('success');

    const refetch = action.onSuccess.find(
      (a: any) => a.handler === 'refetchDataSource',
    );
    expect(refetch).toBeDefined();
    expect(refetch.params.dataSourceId).toBe('dashboard_alerts');
  });

  it('recover 실패 시 onError 에 toast 가 정의되어 있다', () => {
    const recoverBtn = findById(dashboardLayout, 'alert_recover_button');
    const action = recoverBtn.actions[0];

    expect(Array.isArray(action.onError)).toBe(true);
    const errorToast = action.onError.find((a: any) => a.handler === 'toast');
    expect(errorToast).toBeDefined();
    expect(errorToast.params.type).toBe('error');
    expect(errorToast.params.message).toBe('{{error.message}}');
  });

  it('recovery_available 알림에는 dismiss 버튼도 함께 렌더된다 (extension_type, identifier 존재)', async () => {
    testUtils = createLayoutTest(dashboardLayout, {
      translations,
      locale: 'ko',
      componentRegistry: registry,
      auth: { isAuthenticated: true, user: { id: 1, name: 'Admin' }, authType: 'admin' },
    });
    setupDefaultMocks(testUtils);
    testUtils.mockApi('dashboard_alerts', {
      response: {
        data: [
          {
            subtype: 'recovery_available',
            extension_type: 'plugin',
            identifier: 'sirsoft-payment',
            recover_endpoint: '/api/admin/extensions/plugin/sirsoft-payment/recover',
            title: '재호환 가능',
            message: '코어 업그레이드 후 재활성화 가능',
            time: '방금 전',
          },
        ],
      },
    });

    await testUtils.render();

    expect(screen.getByTestId('recover-btn')).toBeTruthy();
    expect(screen.getByTestId('dismiss-btn')).toBeTruthy();
  });
});
