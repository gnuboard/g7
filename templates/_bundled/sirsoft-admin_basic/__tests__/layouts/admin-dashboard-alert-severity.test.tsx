/**
 * @file admin-dashboard-alert-severity.test.tsx
 * @description 대시보드 시스템 알림의 심각도별 배치·색상 회귀 테스트 (#124 W10)
 *
 * 배경:
 * - 알림 카드의 색상 분기가 `alert.subtype` 리터럴 2개(`incompatible_core`,
 *   `recovery_available`)에만 걸려 있었고, 심각도를 담은 `alert.type` 은 스타일 판정에
 *   한 번도 쓰이지 않았다. 목록에 없는 subtype 은 전부 else 로 떨어져 **경고가 회색
 *   일반 안내로** 렌더됐다(`static_publish_*` 가 이미 그 상태였다).
 * - 게다가 시스템 알림 카드는 대시보드 **맨 아래** 섹션이라, 화면이 정상으로 보이는
 *   구성에서는 운영자가 경고에 도달하지 못할 수 있었다. 그래서 PO 결정(2026-08-28)으로
 *   **경고 등급 알림은 상단 배너로 승격**하고 나머지는 하단 카드에 남긴다.
 *
 * 이 테스트가 잠그는 계약:
 * - `type === 'warning'` → 상단 배너(`#dashboard_alert_banners` / `top_alert_*`)
 * - 그 외 → 하단 카드(`#system_alerts_card` / `alert_*`)
 * - 같은 알림이 두 곳에 **중복 노출되지 않는다**
 * - 경고가 여러 건이면 **모두 쌓이고 서로 덮지 않는다**
 * - 배치가 바뀌어도 행의 액션(복구·닫기)은 따라간다 — 상단 승격이 기능을 빼앗지 않는다
 * - `recovery_available` 의 복구 버튼은 **스타일이 아니라 의미 판정**이므로 `subtype` 유지
 *
 * 합성 레이아웃이 아니라 **실제 admin_dashboard.json** 을 읽는다. 합성 입력으로만
 * 검증하면 실물 레이아웃이 되돌아가도 테스트는 계속 통과한다.
 */

import React from 'react';
import { describe, it, expect, beforeEach } from 'vitest';
import fs from 'fs';
import path from 'path';
import { createLayoutTest, screen } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

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

/** 실제 레이아웃 파일 로드 (extends 제거 — 독립 렌더) */
function loadDashboardLayout(): any {
  const file = path.resolve(__dirname, '../../layouts/admin_dashboard.json');
  const layout = JSON.parse(fs.readFileSync(file, 'utf8'));

  delete layout.extends;
  layout.components = layout.slots?.content ?? [];
  delete layout.slots;
  stripPermissions(layout.components);

  return layout;
}

/**
 * 알림 4종 — 심각도(type)가 **배치**를, 의미(subtype)가 **액션**을 각각 가른다.
 *
 * 배열 순서를 섞어 둔다: 필터링 후 인덱스는 버킷별로 다시 매겨지므로, 원본 순서를 그대로
 * 가정하는 구현이면 여기서 드러난다.
 */
const ALERTS = [
  // (1) 기존 warning — 상단, amber 유지 (회귀 없음)
  { type: 'warning', subtype: 'incompatible_core', icon: 'exclamation', title: '비호환', message: 'm1' },
  // (2) info + 복구 액션 — 하단, blue 유지 + 버튼 유지
  {
    type: 'info',
    subtype: 'recovery_available',
    icon: 'check-circle',
    title: '복구 가능',
    message: 'm2',
    recover_endpoint: '/api/admin/x/1/recover',
    extension_type: 'module',
    identifier: 'm1',
  },
  // (3) subtype 목록에 없던 warning — 종전에는 하단에 회색으로 묻혀 있었다
  { type: 'warning', subtype: 'static_publish_write_failed', icon: 'exclamation-triangle', title: '게시 실패', message: 'm3' },
  // (4) 신규 warning — (3) 과 같은 경로
  { type: 'warning', subtype: 'trusted_proxy_missing', icon: 'exclamation-triangle', title: '신뢰 프록시 미설정', message: 'm4' },
];

/** 알림 목록만 채우고 나머지 데이터소스는 비워 렌더한다 */
async function renderAlerts(alerts: any[]) {
  const layout = loadDashboardLayout();
  const testUtils = createLayoutTest(layout, { componentRegistry: setupTestRegistry() });
  const empty = { data: { data: [], current_page: 1, last_page: 1, per_page: 5, total: 0 } };

  testUtils.mockApi('dashboard_stats', { response: { data: {} } });
  testUtils.mockApi('dashboard_activities', { response: { data: [] } });
  testUtils.mockApi('dashboard_modules', { response: empty });
  testUtils.mockApi('dashboard_plugins', { response: empty });
  testUtils.mockApi('dashboard_templates', { response: empty });
  testUtils.mockApi('dashboard_recent_notifications', { response: { data: [] } });
  testUtils.mockApi('dashboard_alerts', { response: { data: alerts } });

  await testUtils.render();

  return testUtils;
}

/**
 * 지정 영역의 인덱스 i 알림 행과 아이콘 className 을 읽는다.
 *
 * @param region 'top'(상단 배너) 또는 'bottom'(하단 시스템 알림 카드)
 * @param i 그 영역 안에서의 인덱스 (필터링 후 다시 매겨진다)
 * @returns 행·아이콘의 className
 */
function readAlertClasses(region: 'top' | 'bottom', i: number): { row: string; icon: string } {
  const prefix = region === 'top' ? 'top_alert' : 'alert';
  const row = document.querySelector('#' + prefix + '_item_' + i);
  const icon = document.querySelector('#' + prefix + '_icon_' + i);

  return {
    row: row?.getAttribute('class') ?? '',
    icon: icon?.getAttribute('class') ?? '',
  };
}

describe('대시보드 알림 심각도별 배치와 색상', () => {
  beforeEach(() => {
    setupTestRegistry();
  });

  it('경고 등급 알림은 subtype 과 무관하게 상단 배너에 amber + 테두리로 렌더된다', async () => {
    const testUtils = await renderAlerts(ALERTS);

    // 경고 3건이 상단에 모두 쌓인다 — 하나가 다른 하나를 덮지 않는다
    const banner = document.querySelector('#dashboard_alert_banners');
    expect(banner, '상단 배너 영역이 렌더되지 않았다').not.toBeNull();
    expect(banner!.querySelectorAll('[id^="top_alert_item_"]').length).toBe(3);

    for (const i of [0, 1, 2]) {
      const { row, icon } = readAlertClasses('top', i);

      expect(row, 'top_alert_item_' + i + ' 행 배경').toContain('bg-amber-50');
      expect(row, 'top_alert_item_' + i + ' 행 테두리').toContain('border-amber-200');
      expect(row, 'top_alert_item_' + i + ' 는 회색으로 떨어지면 안 된다').not.toContain('bg-gray-50');
      expect(icon, 'top_alert_icon_' + i + ' 아이콘 색').toContain('text-amber-600');
    }

    // 제목 3건이 모두 살아 있다 (덮어쓰기 없음)
    for (const title of ['비호환', '게시 실패', '신뢰 프록시 미설정']) {
      expect(screen.getByText(title), title + ' 이 사라졌다').toBeTruthy();
    }

    testUtils.cleanup();
  });

  it('경고가 아닌 알림만 하단 카드에 남는다 — 같은 알림이 두 곳에 중복 노출되지 않는다', async () => {
    const testUtils = await renderAlerts(ALERTS);

    // 하단에는 info 1건만 (인덱스는 필터 후 0 부터 다시 매겨진다)
    const list = document.querySelector('#alerts_list');
    expect(list).not.toBeNull();
    expect(list!.querySelectorAll('[id^="alert_item_"]').length).toBe(1);

    const { row, icon } = readAlertClasses('bottom', 0);
    expect(row).toContain('bg-blue-50');
    expect(row).toContain('border-blue-200');
    expect(icon).toContain('text-blue-600');

    // 상단에 올라간 경고가 하단에 다시 나타나지 않는다
    expect(screen.queryAllByText('신뢰 프록시 미설정').length).toBe(1);

    testUtils.cleanup();
  });

  it('경고만 있으면 하단 카드 자체가 뜨지 않는다', async () => {
    const testUtils = await renderAlerts(ALERTS.filter((a) => a.type === 'warning'));

    expect(document.querySelector('#dashboard_alert_banners')).not.toBeNull();
    expect(document.querySelector('#system_alerts_card'), '빈 시스템 알림 카드가 남았다').toBeNull();

    testUtils.cleanup();
  });

  it('경고가 없으면 상단 배너 영역이 뜨지 않는다', async () => {
    const testUtils = await renderAlerts(ALERTS.filter((a) => a.type !== 'warning'));

    expect(document.querySelector('#system_alerts_card')).not.toBeNull();
    expect(document.querySelector('#dashboard_alert_banners'), '빈 배너 영역이 남았다').toBeNull();

    testUtils.cleanup();
  });

  it('심각도가 없는 알림은 하단에 회색으로 떨어진다', async () => {
    const testUtils = await renderAlerts([
      { subtype: 'unknown_thing', icon: 'info-circle', title: '미지정', message: 'm' },
    ]);

    const { row, icon } = readAlertClasses('bottom', 0);

    expect(row).toContain('bg-gray-50');
    expect(row).not.toContain('bg-amber-50');
    expect(icon).toContain('text-gray-500');
    expect(document.querySelector('#dashboard_alert_banners')).toBeNull();

    testUtils.cleanup();
  });

  it('복구 버튼은 subtype 판정을 유지한다 — info 라고 모두 뜨지 않는다', async () => {
    const testUtils = await renderAlerts([
      // recovery_available 이 아닌 info — 복구 버튼이 뜨면 안 된다
      { type: 'info', subtype: 'something_else', icon: 'info-circle', title: '일반 안내', message: 'm' },
      // recovery_available — 복구 버튼이 떠야 한다
      {
        type: 'info',
        subtype: 'recovery_available',
        icon: 'check-circle',
        title: '복구 가능',
        message: 'm',
        recover_endpoint: '/api/admin/x/1/recover',
        extension_type: 'module',
        identifier: 'm1',
      },
    ]);

    expect(document.querySelector('#alert_recover_button_0'), 'recovery_available 이 아닌 info 에 복구 버튼이 떴다').toBeNull();
    expect(document.querySelector('#alert_recover_button_1'), 'recovery_available 의 복구 버튼이 사라졌다').not.toBeNull();

    testUtils.cleanup();
  });

  it('상단으로 올라간 경고도 닫기 버튼을 그대로 갖는다 — 배치가 기능을 빼앗지 않는다', async () => {
    const testUtils = await renderAlerts([
      {
        type: 'warning',
        subtype: 'incompatible_core',
        icon: 'exclamation',
        title: '비호환',
        message: 'm',
        extension_type: 'plugin',
        identifier: 'p1',
      },
    ]);

    const dismiss = document.querySelector('#top_alert_dismiss_button_0');
    expect(dismiss, '상단 배너에서 닫기 버튼이 사라졌다').not.toBeNull();

    testUtils.cleanup();
  });
});
