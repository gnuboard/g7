/**
 * @file admin-stage-role-gating.test.tsx
 * @description 검수 서버(실행 역할 stage) 관리자 화면 — 상단 띠·편집기 띠·수명주기 버튼 잠금, 서버별 스케줄 꺼짐 안내 (#888)
 *
 * 운영 DB 를 공유하는 검수 서버(셸 설정 `appConfig.runtime_role === 'stage'`)에서는 확장·코어·언어팩 변경 API 가 409 로 막힌다.
 * 화면은 그 사실을 미리 보여야 한다 — 누른 뒤 오류로 알게 하지 않는다.
 *
 * - 관리자 기본 레이아웃 상단 띠(`stage_role_banner`)와 편집기 띠(`stage_editor_banner`): stage 에서만 1개, live 면 0개
 * - 모듈·플러그인·템플릿·언어팩 카드: stage 면 스위치·설치·업데이트 주 단추·삭제 메뉴가 잠기고 안내(title·disabledReason)가 붙는다
 * - 수동 설치·언어팩 설치·코어 업데이트 시작·대시보드 호환성 [복구] 단추: stage 면 disabled + title, live 면 그대로
 * - 환경설정 > 시스템의 스케줄 꺼짐 안내(`scheduler_off_notice`): 설정 응답 `scheduler_enabled_on_this_server` 가 false 일 때만 1개
 *
 * 모든 단언은 굽기 산출물(.json)의 실제 노드를 그대로 렌더해 확인한다.
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { expandSemanticTokens } from './support/semanticClasses';
import adminBase from '../../layouts/_admin_base.json';
import layoutEdit from '../../layouts/admin_template_layout_edit.json';
import moduleList from '../../layouts/admin_module_list.json';
import pluginList from '../../layouts/admin_plugin_list.json';
import templateList from '../../layouts/admin_template_list.json';
import templateAdminTab from '../../layouts/partials/admin_template_list/_tab_admin.json';
import templateUserTab from '../../layouts/partials/admin_template_list/_tab_user.json';
import languagePackContent from '../../layouts/partials/admin_language_pack_list/_content.json';
import updateTab from '../../layouts/partials/admin_settings/_tab_update.json';
import dashboard from '../../layouts/admin_dashboard.json';
import systemTab from '../../layouts/partials/admin_settings/_tab_system.json';

type Props = Record<string, any> & { children?: React.ReactNode };

const passthrough = (tag: string): React.FC<Props> => ({ children, className, text, 'data-testid': testId, id, role }) =>
  React.createElement(tag, { className, 'data-testid': testId, 'data-node-id': id, role }, children ?? text);

const TestButton: React.FC<Props> = ({ children, className, disabled, title, text }) => (
  <button type="button" className={className} disabled={disabled === true} title={title || undefined} data-testid="probe-button">
    {children ?? text}
  </button>
);

/** 카드 그리드가 받은 items 를 남긴다 — 실제 컴포넌트 대신 prop 계약을 잰다 */
let capturedItems: any[] = [];
const TestCardGrid: React.FC<Props> = ({ items }) => {
  capturedItems = Array.isArray(items) ? items : [];
  return <div data-testid="card-grid">{capturedItems.length}</div>;
};

const TestFragment: React.FC<{ children?: React.ReactNode }> = ({ children }) => <>{children}</>;

/**
 * 시험 레지스트리를 준비합니다. 렌더러가 목록을 Fragment 로 감싸므로 Fragment 를 반드시 등록한다.
 */
function setupRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'basic' } });

  (registry as any).registry = {
    Div: basic('Div', passthrough('div')),
    Span: basic('Span', passthrough('span')),
    P: basic('P', passthrough('p')),
    Icon: basic('Icon', () => <i />),
    Button: basic('Button', TestButton),
    ExtensionCardGrid: { component: TestCardGrid, metadata: { name: 'ExtensionCardGrid', type: 'composite' } },
    Fragment: { component: TestFragment, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/**
 * 굽기 산출물에서 id 가 맞는 노드를 찾습니다.
 */
function findNode(node: any, id: string): any {
  if (!node || typeof node !== 'object') return null;
  if (node.id === id) return node;
  for (const value of Object.values(node)) {
    const found = findNode(value, id);
    if (found) return found;
  }
  return null;
}

/**
 * 노드 하나와 렌더 확인용 앵커만 둔 시험 레이아웃을 만듭니다.
 */
function layoutWith(node: any, dataSources: Array<{ id: string; endpoint: string }> = []) {
  expect(node, '굽기 산출물에서 노드를 찾지 못했다').toBeTruthy();
  return {
    version: '1.0.0',
    layout_name: 'test_admin_stage_role_gating',
    data_sources: dataSources.map((ds) => ({ ...ds, type: 'api', method: 'GET', auto_fetch: true, auth_required: true, fallback: { data: {} } })),
    components: [{ id: 'anchor', type: 'basic', name: 'Div', props: { 'data-testid': 'anchor' } }, node],
  };
}

/**
 * 노드를 실행 역할과 함께 렌더합니다.
 */
async function renderWithRole(node: any, role: 'live' | 'stage', dataSources: Array<{ id: string; endpoint: string; response?: unknown }> = []) {
  const testUtils = createLayoutTest(layoutWith(node, dataSources), {
    initialState: { _global: { appConfig: { runtime_role: role } } },
  });
  for (const ds of dataSources) {
    if (ds.response !== undefined) testUtils.mockApi(ds.id, { response: ds.response });
  }
  const result = await testUtils.render();
  return { testUtils, ...result };
}

const extensionRow = (overrides: Record<string, unknown> = {}) => ({
  identifier: 'acme-sample',
  name: '샘플',
  version: '1.0.0',
  status: 'inactive',
  update_available: true,
  core_compatible: true,
  abilities: { can_install: true, can_activate: true, can_deactivate: true, can_uninstall: true, can_edit_layouts: true, can_update_settings: true },
  dependencies: [],
  ...overrides,
});

describe('검수 서버(stage) 관리자 화면 잠금 (#888)', () => {
  let registry: ComponentRegistry;

  beforeEach(() => {
    registry = setupRegistry();
    capturedItems = [];
  });

  afterEach(() => {
    (registry as any).registry = {};
  });

  // @scenario case=base_banner
  // @effects stage_banner_only_on_stage
  it.each([
    ['stage', 1],
    ['live', 0],
  ] as const)('관리자 기본 레이아웃 상단 띠는 %s 에서 %i 개다', async (role, expected) => {
    const { testUtils, container } = await renderWithRole(findNode(adminBase, 'stage_role_banner'), role);

    expect(container.querySelector('[data-testid="anchor"]')).toBeTruthy();
    expect(container.querySelectorAll('[data-node-id="stage_role_banner"]')).toHaveLength(expected);

    testUtils.cleanup();
  });

  // @scenario case=editor_banner
  // @effects stage_editor_banner_only_on_stage
  it.each([
    ['stage', 1],
    ['live', 0],
  ] as const)('레이아웃 편집기 띠는 %s 에서 %i 개다', async (role, expected) => {
    const { testUtils, container } = await renderWithRole(findNode(layoutEdit, 'stage_editor_banner'), role);

    expect(container.querySelector('[data-testid="anchor"]')).toBeTruthy();
    expect(container.querySelectorAll('[data-node-id="stage_editor_banner"]')).toHaveLength(expected);

    testUtils.cleanup();
  });

  // @scenario case=extension_cards
  // @effects stage_locks_extension_lifecycle_controls
  it.each([
    ['모듈', moduleList, 'module_card_grid', 'modules', '/api/admin/modules'],
    ['플러그인', pluginList, 'plugin_card_grid', 'plugins', '/api/admin/plugins'],
  ] as const)('%s 카드: stage 면 스위치·설치·업데이트·삭제가 잠기고 안내가 붙으며, live 면 그대로다', async (_label, layout, gridId, dsId, endpoint) => {
    const response = { success: true, data: { data: [extensionRow(), extensionRow({ identifier: 'acme-fresh', status: 'uninstalled', update_available: false })], abilities: { can_install: true } } };

    for (const role of ['stage', 'live'] as const) {
      const { testUtils } = await renderWithRole(findNode(layout, gridId), role, [{ id: dsId, endpoint, response }]);
      const [installed, fresh] = capturedItems;
      const locked = role === 'stage';

      expect(capturedItems).toHaveLength(2);
      expect(installed.toggle.disabled).toBe(locked);
      expect(installed.primaryAction.disabled).toBe(locked);
      expect(Boolean(installed.primaryAction.title)).toBe(locked);
      expect(fresh.primaryAction.disabled).toBe(locked);
      expect(Boolean(fresh.primaryAction.title)).toBe(locked);
      const uninstall = asArray(installed.actions).find((a: any) => a.id === 'uninstall');
      expect(uninstall.disabled).toBe(locked);
      expect(Boolean(uninstall.disabledReason)).toBe(locked);

      testUtils.cleanup();
    }
  });

  // @scenario case=extension_cards
  // @effects stage_locks_extension_lifecycle_controls
  it.each([
    ['관리자', templateAdminTab],
    ['사용자', templateUserTab],
  ] as const)('템플릿(%s) 카드: stage 면 켜기·설치·업데이트·삭제가 잠긴다', async (_label, layout) => {
    const response = {
      success: true,
      data: { data: [extensionRow(), extensionRow({ identifier: 'acme-fresh', status: 'uninstalled', update_available: false })], abilities: { can_install: true } },
    };

    for (const role of ['stage', 'live'] as const) {
      const { testUtils } = await renderWithRole(findNode(layout, 'template_card_grid'), role, [{ id: 'templates', endpoint: '/api/admin/templates', response }]);
      const [installed, fresh] = capturedItems;
      const locked = role === 'stage';

      expect(installed.toggle.disabled).toBe(locked);
      expect(installed.secondaryAction.disabled).toBe(locked);
      expect(fresh.primaryAction.disabled).toBe(locked);
      expect(Boolean(fresh.primaryAction.title)).toBe(locked);
      for (const id of ['update', 'uninstall']) {
        const action = asArray(installed.actions).find((a: any) => a.id === id);
        expect(Boolean(action.disabledReason)).toBe(locked);
        if (locked) expect(action.disabled).toBe(true);
      }

      testUtils.cleanup();
    }
  });

  // @scenario case=extension_cards
  // @effects stage_locks_extension_lifecycle_controls
  it('언어팩 카드: stage 면 스위치·설치·업데이트·삭제가 잠긴다', async () => {
    const response = {
      success: true,
      data: {
        data: [
          { identifier: 'acme-ja', name: '일본어', locale: 'ja', status: 'inactive', has_update: true, is_protected: false, abilities: { can_install: true, can_activate: true, can_deactivate: true, can_uninstall: true } },
          { identifier: 'acme-fr', name: '프랑스어', locale: 'fr', status: 'uninstalled', is_protected: false, abilities: { can_install: true } },
        ],
        abilities: { can_install: true, can_update: true },
      },
    };

    for (const role of ['stage', 'live'] as const) {
      const { testUtils } = await renderWithRole(findNode(languagePackContent, 'lp_card_grid'), role, [{ id: 'language_packs', endpoint: '/api/admin/language-packs', response }]);
      const [installed, fresh] = capturedItems;
      const locked = role === 'stage';

      expect(installed.toggle.disabled).toBe(locked);
      expect(installed.secondaryAction.disabled).toBe(locked);
      expect(fresh.primaryAction.disabled).toBe(locked);
      const uninstall = asArray(installed.actions).find((a: any) => a.id === 'uninstall');
      expect(uninstall.disabled).toBe(locked);
      expect(Boolean(uninstall.disabledReason)).toBe(locked);

      testUtils.cleanup();
    }
  });

  // @scenario case=entry_buttons
  // @effects stage_disables_entry_buttons_with_tooltip
  it.each([
    ['모듈 수동 설치', moduleList, 'manual_install_button', 'modules', '/api/admin/modules'],
    ['플러그인 수동 설치', pluginList, 'manual_install_button', 'plugins', '/api/admin/plugins'],
    ['템플릿 수동 설치', templateList, 'manual_install_button', 'templates', '/api/admin/templates'],
    ['언어팩 설치', languagePackContent, 'lp_btn_install', 'language_packs', '/api/admin/language-packs'],
    ['코어 업데이트 시작', updateTab, 'core_update_start_button', 'core_update_status', '/api/admin/core-update/status'],
  ] as const)('%s 단추: stage 면 disabled + 안내, live 면 눌린다', async (_label, layout, nodeId, dsId, endpoint) => {
    const response = { success: true, data: { data: [], abilities: { can_install: true } } };

    for (const role of ['stage', 'live'] as const) {
      const { testUtils, container } = await renderWithRole(findNode(layout, nodeId), role, [{ id: dsId, endpoint, response }]);
      const button = container.querySelector('[data-testid="probe-button"]') as HTMLButtonElement | null;

      expect(button, `${nodeId} 단추가 그려지지 않았다`).toBeTruthy();
      expect(button!.disabled).toBe(role === 'stage');
      expect(Boolean(button!.getAttribute('title'))).toBe(role === 'stage');
      // 시각 비활성 — 활성 형제와 구분되는 꾸밈이 단추에 있다(유틸 또는 .btn 의 disabled 꾸밈)
      expect(/disabled:opacity-50|\bbtn\b/.test(button!.className)).toBe(true);

      testUtils.cleanup();
    }
  });

  // @scenario case=entry_buttons
  // @effects stage_disables_entry_buttons_with_tooltip
  it.each([
    ['상단 경고', 'top_alert_item_{{$idx}}', 'warning'],
    ['시스템 알림 목록', 'alert_item_{{$idx}}', 'error'],
  ] as const)('대시보드 %s 의 호환성 [복구] 단추: stage 면 disabled + 안내, live 면 눌린다', async (_label, itemId, type) => {
    const response = {
      success: true,
      data: [{ type, title: '호환되지 않아 꺼진 확장', recover_endpoint: '/api/admin/extensions/module/acme-sample/recover', abilities: { can_recover: true } }],
    };

    for (const role of ['stage', 'live'] as const) {
      const { testUtils, container } = await renderWithRole(findNode(dashboard, itemId), role, [{ id: 'dashboard_alerts', endpoint: '/api/admin/dashboard/alerts', response }]);
      const buttons = container.querySelectorAll('[data-testid="probe-button"]');

      expect(buttons, '복구 단추 하나만 그려진다').toHaveLength(1);
      const button = buttons[0] as HTMLButtonElement;
      expect(button.disabled).toBe(role === 'stage');
      expect(Boolean(button.getAttribute('title'))).toBe(role === 'stage');
      // #399 R-1 — 꺼짐 모양은 `btn` 의미 클래스 정의(semantic.css)가 갖는다. 정의를 펼쳐 같은 유틸을 확인한다
      expect(button.className.split(/\s+/)).toContain('btn');
      expect(expandSemanticTokens(button.className)).toContain('disabled:opacity-50');

      testUtils.cleanup();
    }
  });

  // @scenario case=scheduler_notice
  // @effects scheduler_off_notice_only_when_off_on_this_server
  it.each([
    [true, 0],
    [false, 1],
  ] as const)('스케줄 실행 스위치가 %s 이면 시스템 탭 안내가 %i 개다', async (enabled, expected) => {
    const { testUtils, container } = await renderWithRole(findNode(systemTab, 'scheduler_off_notice'), 'live', [
      { id: 'settings', endpoint: '/api/admin/settings', response: { success: true, data: { scheduler_enabled_on_this_server: enabled, runtime_role: 'live' } } },
    ]);

    expect(container.querySelector('[data-testid="anchor"]')).toBeTruthy();
    expect(container.querySelectorAll('[data-node-id="scheduler_off_notice"]')).toHaveLength(expected);

    testUtils.cleanup();
  });
});
