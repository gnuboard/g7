// e2e:allow 실제 엔진 렌더 경로(renderTemplate → updateTemplateData progressive 도착 → 렌더러 computed 재계산 → onMount navigate)로 첫 화면 이동을 잰다 — 브라우저 축은 Playwright admin-home-landing.spec.ts 가 덮는다
/**
 * @file admin-home-landing-engine.test.tsx
 * @description 관리자 첫 화면(`admin_home`) 이동을 실제 엔진 경로로 잰다 — 데이터소스가 화면을 그린 **뒤에** 도착하는 경우
 *
 * TemplateApp 은 레이아웃 진입 때(데이터를 받기 전) computed 를 한 번 계산해 `_computed`·`_global._computed` 에 싣고, progressive
 * 데이터소스는 그린 뒤 하나씩 `updateTemplateData` 로 도착한다. 도구(createLayoutTest)는 데이터를 다 받은 뒤 처음 그려 이 순서를 재지 못했다.
 * 이 시험은 실제 엔진 모듈(`renderTemplate`·`updateTemplateData`·DynamicRenderer·ActionDispatcher)로 같은 순서를 재현한다.
 *
 * 결함(첫 구현): 이동을 조건 노드(`admin_home_redirect`, `if` = 갈 곳 있음) 자신의 `mount` 에 걸었다. 엔진은 `if` 가 거짓이어도 노드의
 * 렌더러를 만들고 그때 `lifecycle.onMount` 를 한 번 실행한다(DynamicRenderer 의 마운트 효과는 `shouldRender` 를 보지 않는다). 데이터를 받기 전
 * 첫 렌더에서 `_computed.homePath` 가 null 이라 이동을 건너뛰었고, 조건이 참이 돼 노드가 그려져도 마운트는 다시 오지 않았다 — 실측 「빈 본문에서
 * 멈춤」. 고침: 마운트 동작을 조건 노드 **안쪽** 노드에 둔다(그 렌더러는 조건이 참이 된 뒤에 만들어진다, sirsoft-basic `_redirect_if_logged_in` 과 같은 꼴).
 *
 * @scenario role=dashboard_reader
 * @scenario role=extension_role_only
 * @scenario role=no_menu
 * @effects dashboard_reader_goes_to_dashboard, no_dashboard_goes_to_first_menu, no_menu_shows_notice_in_shell
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach, vi } from 'vitest';
import React from 'react';
import { waitFor } from '@testing-library/react';

import * as engine from '@core/template-engine';
import { initializeG7CoreGlobals } from '@core/template-engine/G7CoreGlobals';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';
import { evaluateComputedDefinitions } from '@core/template-engine/helpers/ComputedEvaluator';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';

import adminHome from '../../layouts/admin_home.json';

vi.mock('@core/api/ApiClient', () => ({
  getApiClient: () => ({
    post: vi.fn().mockResolvedValue({}),
    get: vi.fn().mockResolvedValue({}),
    removeToken: vi.fn(),
    setToken: vi.fn(),
    getToken: vi.fn().mockReturnValue(null),
    setOnUnauthorized: vi.fn(),
  }),
}));

const passthrough = (tag: string) => ({ children, text, className, id }: any) => React.createElement(tag, { className, id }, children ?? text);

/**
 * 실제 앱과 같은 순서로 첫 화면을 그린다 — 진입 때 computed(데이터 없음) → 렌더 → 데이터소스 도착.
 *
 * @param arrivals 도착 순서대로 데이터소스 값
 * @returns 이동 기록
 */
async function mountHome(arrivals: Array<Record<string, any>>): Promise<string[]> {
  document.body.innerHTML = '<div id="app"></div>';
  initializeG7CoreGlobals({
    getState: () => engine.getState() as any,
    transitionManager: { getIsPending: () => false, subscribe: () => () => {} },
    responsiveManager: {},
    webSocketManager: {} as any,
  } as any);
  await engine.initTemplateEngine({ templateId: 'sirsoft-admin_basic', locale: 'ko' } as any);
  (ComponentRegistry.getInstance() as any).registry = {
    Div: { component: passthrough('div'), metadata: {} },
    Span: { component: passthrough('span'), metadata: {} },
    EmptyState: { component: ({ title }: any) => React.createElement('p', null, title), metadata: {} },
  };
  const navigations: string[] = [];
  const dispatcher = engine.getActionDispatcher()!;
  dispatcher.setDefaultContext({ navigate: (path: string) => { navigations.push(path); } } as any);

  const definitions = (adminHome as any).computed;
  // TemplateApp.handleRouteChange 와 같다 — 데이터를 받기 전 문맥으로 한 번 계산해 두 자리에 싣는다
  const initial = evaluateComputedDefinitions(definitions, { route: {}, query: {}, _global: {}, current_user: undefined, admin_menu: undefined } as any, new DataBindingEngine(), {});
  const global = { _computed: initial };
  await engine.renderTemplate({
    containerId: 'app',
    layoutJson: { version: '1.0.0', layout_name: 'admin_home', components: (adminHome as any).slots.content, computed: definitions } as any,
    dataContext: {
      _global: global,
      _local: {},
      current_user: undefined,
      admin_menu: undefined,
      _computed: initial,
      _computedDefinitions: definitions,
    },
  } as any);
  await new Promise((r) => setTimeout(r, 50));
  for (const data of arrivals) {
    engine.updateTemplateData(data);
    await new Promise((r) => setTimeout(r, 30));
  }
  await new Promise((r) => setTimeout(r, 80));
  return navigations;
}

const user = (permissions: string[]) => ({ success: true, data: { uuid: 'u', permissions: permissions.map((identifier, i) => ({ id: i + 1, identifier })) } });
const SSO_MENU = {
  success: true,
  data: [{ id: 30, url: null, children: [{ id: 31, url: '/admin/plugins/sirsoft-sso/accounts' }, { id: 32, url: '/admin/plugins/sirsoft-sso/logs' }] }],
};
const FULL_MENU = { success: true, data: [{ id: 1, url: '/admin/dashboard', children: [] }] };

describe('관리자 첫 화면 — 데이터소스가 그린 뒤 도착(실제 엔진 경로)', () => {
  afterEach(() => {
    try { engine.destroyTemplate(); } catch { /* 정리 */ }
    delete (window as any).G7Core;
    document.body.innerHTML = '';
  });

  // @scenario role=extension_role_only
  // @effects no_dashboard_goes_to_first_menu
  it('SSO 운영자: 메뉴·관리자 순으로 도착해도 회원 연결로 간다', async () => {
    expect(await mountHome([{ admin_menu: SSO_MENU }, { current_user: user(['sirsoft-sso.accounts.read']) }])).toEqual(['/admin/plugins/sirsoft-sso/accounts']);
  });

  // @scenario role=extension_role_only
  // @effects no_dashboard_goes_to_first_menu
  it('SSO 운영자: 관리자·메뉴 순으로 도착해도 회원 연결로 간다', async () => {
    expect(await mountHome([{ current_user: user(['sirsoft-sso.accounts.read']) }, { admin_menu: SSO_MENU }])).toEqual(['/admin/plugins/sirsoft-sso/accounts']);
  });

  // @scenario role=dashboard_reader
  // @effects dashboard_reader_goes_to_dashboard
  it('최고관리자: 대시보드로 간다', async () => {
    expect(await mountHome([{ current_user: user(['core.dashboard.read']) }, { admin_menu: FULL_MENU }])).toEqual(['/admin/dashboard']);
  });

  // @scenario role=no_menu
  // @effects no_menu_shows_notice_in_shell
  it('열 화면이 없으면 이동하지 않고 안내를 그린다', async () => {
    expect(await mountHome([{ current_user: user([]) }, { admin_menu: { success: true, data: [] } }])).toEqual([]);
    await waitFor(() => expect(document.querySelector('#admin_home_no_screen')).not.toBeNull());
  });
});
