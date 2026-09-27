/**
 * @file analyticsDashboardWidget.test.tsx
 * @description 대시보드 방문자 통계 카드와 알림 카드 이동 버튼(C1)의 계약 (이슈 #682 S3)
 *
 * 카드: 개요 소스가 background·403 무시이고 대시보드 진입 대기 목록에 들어가지 않는 것, 카드가 데이터 유무로 스스로 빠지는 것.
 * 알림(C1): `recover_endpoint` 가 있으면 종전처럼 복구 버튼(POST), 없고 `action_url` 이 있으면 이동 버튼(navigate), 둘 다 없으면
 * 버튼 없음. 기존 복구 리스너(확장 호환·정적 게시)의 알림은 **버튼이 하나만** 뜬다 — 회귀 확인.
 * 실제 `admin_dashboard.json` 을 렌더한다(합성 입력으로만 검증하면 실물이 되돌아가도 통과한다).
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { evaluateSafeExpression } from '@core/template-engine/SafeExpressionEvaluator';
import { scanBindings } from '@core/template-engine/BindingShape';
import { loadLayout, dataSource, nodeById, actionsWithHandler, stringify } from './analyticsLayoutTestUtils';

const Simple = (tag: string): React.FC<any> => ({ id, className, children, text, href }) =>
  React.createElement(tag, { id, className, href }, children || text);
const TestIcon: React.FC<any> = ({ id, name, className }) => <i id={id} className={className} data-icon={name} />;
const Nothing: React.FC = () => null;

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  (registry as any).registry = {
    Div: { component: Simple('div'), metadata: { name: 'Div', type: 'basic' } },
    Span: { component: Simple('span'), metadata: { name: 'Span', type: 'basic' } },
    P: { component: Simple('p'), metadata: { name: 'P', type: 'basic' } },
    H1: { component: Simple('h1'), metadata: { name: 'H1', type: 'basic' } },
    H2: { component: Simple('h2'), metadata: { name: 'H2', type: 'basic' } },
    Button: { component: Simple('button'), metadata: { name: 'Button', type: 'basic' } },
    A: { component: Simple('a'), metadata: { name: 'A', type: 'basic' } },
    Icon: { component: TestIcon, metadata: { name: 'Icon', type: 'basic' } },
    Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
    Toast: { component: Nothing, metadata: { name: 'Toast', type: 'composite' } },
    ModalRoot: { component: Nothing, metadata: { name: 'ModalRoot', type: 'composite' } },
    Badge: { component: Nothing, metadata: { name: 'Badge', type: 'composite' } },
    LineChart: { component: Nothing, metadata: { name: 'LineChart', type: 'composite' } },
  };
  return registry;
}

/** 권한 게이트·조각 참조를 걷어낸 대시보드 (조각은 설치 시 인라인되므로 테스트에서는 뺀다) */
function loadDashboard(): any {
  const layout = loadLayout('admin_dashboard.json');
  delete layout.extends;
  layout.components = layout.slots?.content ?? [];
  delete layout.slots;
  const strip = (node: any): void => {
    if (Array.isArray(node)) { node.forEach(strip); return; }
    if (!node || typeof node !== 'object') return;
    delete node.permissions;
    if (Array.isArray(node.children)) node.children = node.children.filter((c: any) => !c.partial);
    if (Array.isArray(node.default)) node.default = node.default.filter((c: any) => !c.partial);
    Object.values(node).forEach(strip);
  };
  strip(layout.components);
  return layout;
}

async function renderAlerts(alerts: any[]) {
  const layout = loadDashboard();
  const t = createLayoutTest(layout, { componentRegistry: setupTestRegistry() });
  const empty = { data: { data: [], current_page: 1, last_page: 1, per_page: 5, total: 0 } };
  t.mockApi('dashboard_stats', { response: { data: {} } });
  t.mockApi('dashboard_activities', { response: { data: [] } });
  t.mockApi('dashboard_modules', { response: empty });
  t.mockApi('dashboard_plugins', { response: empty });
  t.mockApi('dashboard_templates', { response: empty });
  t.mockApi('dashboard_recent_notifications', { response: { data: [] } });
  t.mockApi('dashboard_analytics', { response: { data: null } });
  t.mockApi('dashboard_alerts', { response: { data: alerts } });
  await t.render();
  return t;
}

const dashboard = loadLayout('admin_dashboard.json');
const widget = loadLayout('partials/admin_dashboard/_analytics_widget.json');

describe('대시보드 방문자 통계 카드', () => {
  // @scenario screen=dashboard_widget, state=forbidden, viewport=1440, locale=ko
  // @effects dashboard_widget_source_is_background_and_forbidden_is_silent
  it('개요 소스는 background·7일 프리셋·403 무시이고 진입 대기 목록에 없다', () => {
    const ds = dataSource(dashboard, 'dashboard_analytics');
    expect(ds?.endpoint).toBe('/api/admin/analytics/overview');
    expect(ds?.loading_strategy).toBe('background');
    expect(ds?.params).toEqual({ preset: '7d' });
    expect(stringify(ds?.errorHandling)).toContain('"handler":"suppress"');
    expect(dashboard.transition_overlay?.wait_for).not.toContain('dashboard_analytics');
    expect(stringify(dashboard)).toContain('"name":"admin_dashboard_analytics"');
    expect(stringify(dashboard)).toContain('partials/admin_dashboard/_analytics_widget.json');
  });

  // @scenario screen=dashboard_widget, state=collection_off, viewport=1440, locale=ko
  // @effects dashboard_widget_hides_without_data
  it('카드는 KPI 가 있을 때만 그려지고 수집 꺼짐 배지·7일 추이·지금 접속자를 갖는다', () => {
    const root = nodeById(widget, 'dashboard_analytics_widget');
    // 데이터가 있으면 그린다. 없을 때 그릴지 말지는 아래 「읽기는 되는데 실패」 케이스가 잠근다.
    expect(root?.if).toContain('!!dashboard_analytics?.data?.kpi');
    expect(nodeById(widget, 'dashboard_analytics_off_badge')?.if).toContain('collection_enabled === false');
    for (const id of ['dashboard_analytics_today', 'dashboard_analytics_yesterday', 'dashboard_analytics_max', 'dashboard_analytics_total', 'dashboard_analytics_chart', 'dashboard_analytics_now']) {
      expect(nodeById(widget, id), id).toBeDefined();
    }
    expect(actionsWithHandler(widget, 'navigate').map((n) => n.params.path)).toEqual(expect.arrayContaining(['/admin/analytics', '/admin/analytics/realtime']));
  });

  // @scenario screen=dashboard_widget, state=load_failed, viewport=1440, locale=ko
  // @effects dashboard_widget_shows_its_own_error_state
  it('읽기는 되는데 응답이 실패하면 카드가 사라지지 않고 제자리에서 알리고 다시 받는다', () => {
    // 종전에는 500 이면 카드가 통째로 빠지고 대시보드에 전역 오류 토스트만 떴다
    // (브라우저 실측 M8-T4 — 카드 12 → 11, 토스트 「서버 오류」). 어느 카드가 비었는지
    // 알 수 없고, 카드 스스로 조용히 빠지기로 한 규약(권한 없음)과도 어긋난다.
    const ds = dataSource(dashboard, 'dashboard_analytics');
    const handling = stringify(ds?.errorHandling);
    expect(handling).toContain('"403"');
    expect(handling).toContain('"500"');
    expect(stringify(ds?.fallback)).toContain('widget_error');

    const root = nodeById(widget, 'dashboard_analytics_widget');
    expect(root?.if).toContain('widget_error === true');

    const err = nodeById(widget, 'dashboard_analytics_error');
    expect(err, 'dashboard_analytics_error').toBeDefined();
    expect(err?.if).toContain('widget_error === true');
    expect(nodeById(widget, 'dashboard_analytics_body')?.if).toContain('kpi');
    const retry = actionsWithHandler(nodeById(widget, 'dashboard_analytics_retry'), 'refetchDataSource')[0];
    expect(retry?.params?.dataSourceId ?? retry?.target).toBe('dashboard_analytics');
  });
});

describe('대시보드 알림 카드 — 복구 버튼과 이동 버튼 (C1)', () => {
  beforeEach(() => { setupTestRegistry(); });

  const ALERTS = [
    // (1) 통계 보존 경고 — 복구 API 없음, 갈 곳 있음 → 이동 버튼
    { type: 'warning', subtype: 'analytics_ledger_limit', icon: 'exclamation-triangle', title: '기록 많음', message: 'm1', action_url: 'https://site.example/admin/analytics/settings?tab=retention', action_label: '보존 설정 열기' },
    // (2) 기존 복구 리스너(확장 호환) — 복구 버튼만
    { type: 'info', subtype: 'recovery_available', icon: 'check-circle', title: '복구 가능', message: 'm2', recover_endpoint: '/api/admin/modules/x/recover', extension_type: 'module', identifier: 'x' },
    // (3) 기존 복구 리스너(정적 게시) — warning 이라 상단, 복구 버튼만
    { type: 'warning', subtype: 'static_publish_write_failed', icon: 'exclamation-triangle', title: '게시 실패', message: 'm3', recover_endpoint: '/api/admin/static/republish' },
    // (4) 둘 다 없음 — 버튼 없음
    { type: 'info', subtype: 'plain', icon: 'info-circle', title: '안내', message: 'm4' },
  ];

  // @scenario screen=dashboard_widget, state=normal, viewport=1440, locale=ko
  // @effects alert_with_action_url_navigates, alert_with_recover_endpoint_keeps_recover_button, alert_without_either_has_no_button
  it('action_url 만 있는 알림에는 이동 버튼이, recover_endpoint 알림에는 복구 버튼만 뜬다', async () => {
    const t = await renderAlerts(ALERTS);

    // 상단 배너: (1) 이동 버튼, (3) 복구 버튼 — 각각 하나씩
    expect(document.querySelector('#top_alert_navigate_button_0')).not.toBeNull();
    expect(document.querySelector('#top_alert_recover_button_0')).toBeNull();
    expect(document.querySelector('#top_alert_navigate_button_0')?.textContent).toContain('보존 설정 열기');
    expect(document.querySelector('#top_alert_recover_button_1')).not.toBeNull();
    expect(document.querySelector('#top_alert_navigate_button_1')).toBeNull();

    // 하단 카드: (2) 복구 버튼만, (4) 버튼 없음
    expect(document.querySelector('#alert_recover_button_0')).not.toBeNull();
    expect(document.querySelector('#alert_navigate_button_0')).toBeNull();
    expect(document.querySelector('#alert_recover_button_1')).toBeNull();
    expect(document.querySelector('#alert_navigate_button_1')).toBeNull();

    t.cleanup();
  });

  // @scenario screen=dashboard_widget, state=normal, viewport=390, locale=en
  // @effects alert_with_action_url_navigates, alert_with_recover_endpoint_keeps_recover_button
  it('이동 버튼은 절대 주소를 경로로 바꿔 navigate 하고 복구 버튼은 종전대로 POST 다', () => {
    for (const prefix of ['top_alert', 'alert']) {
      const nav = nodeById(dashboard, `${prefix}_navigate_button_{{$idx}}`);
      expect(nav?.if, prefix).toBe('{{!alert.recover_endpoint && !!alert.action_url}}');
      const navigate = actionsWithHandler(nav, 'navigate')[0];
      // 식을 엔진 평가기로 직접 돌린다 — 문자열 대조만 하면 평가기가 읽지 못하는 식(정규식 리터럴)도 통과한다.
      const [pathExpr] = scanBindings(navigate.params.path).map((b) => b.expr);
      const toPath = (url: string) => evaluateSafeExpression(pathExpr, { alert: { action_url: url } });
      expect(toPath('https://example.test/admin/analytics/settings?tab=retention'), prefix).toBe('/admin/analytics/settings?tab=retention');
      expect(toPath('https://example.test'), prefix).toBe('/');
      expect(toPath('/admin/analytics/diagnostics'), prefix).toBe('/admin/analytics/diagnostics');
      const recover = nodeById(dashboard, `${prefix}_recover_button_{{$idx}}`);
      expect(recover?.if, prefix).toBe('{{!!alert.recover_endpoint}}');
      const call = actionsWithHandler(recover, 'apiCall')[0];
      expect(call.target).toBe('{{alert.recover_endpoint}}');
      expect(call.params.method).toBe('POST');
    }
  });
});
