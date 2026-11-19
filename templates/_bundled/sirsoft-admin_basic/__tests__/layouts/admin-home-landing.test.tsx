/**
 * @file admin-home-landing.test.tsx
 * @description 관리자 첫 화면(`/admin`)이 대시보드 권한이 없는 관리자를 볼 수 있는 화면으로 보낸다
 *
 * 결함: `/admin` 은 라우트 redirect 로 `/admin/dashboard` 에 고정돼 있었다. 로그인이 끝나는 네 갈래(자격증명·2단계 인증·
 * 비밀번호 변경·로그인 인계)는 복귀 주소가 없으면 `/admin` 으로 오므로, 대시보드 권한(`core.dashboard.read`)이 없는
 * 관리자(확장이 준 역할만 가진 SSO 운영자·이커머스 관리자 등)는 로그인 직후 대시보드 레이아웃 403 → 왼쪽 메뉴·머리 없는
 * 「페이지 로딩 실패」 전체 화면에 갇혔다. 같은 계정의 사이드바 메뉴(`/api/admin/menus/active`)에는 열 수 있는 화면이 있다.
 *
 * 고침: `/admin` 은 `_admin_base` 를 상속한 첫 화면 레이아웃(`admin_home`)을 그린다. 대시보드 권한이 있으면 종전대로
 * 대시보드로, 없으면 사이드바 메뉴의 첫 화면으로 보낸다. 열 화면이 하나도 없으면 메뉴·머리가 있는 화면에 안내를 둔다.
 *
 * 이 도구(createLayoutTest)는 데이터를 다 받은 뒤 처음 그리므로 「데이터가 그린 뒤 도착」하는 실제 순서는 재지 못한다 — 그 순서는
 * `admin-home-landing-engine.test.tsx` 가 실제 엔진 경로로 잰다.
 *
 * @scenario role=dashboard_reader
 * @scenario role=extension_role_only
 * @scenario role=no_menu
 * @effects admin_root_renders_home_layout, dashboard_reader_goes_to_dashboard, no_dashboard_goes_to_first_menu, no_menu_shows_notice_in_shell
 */

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
  type MockComponentRegistry,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

const ROOT = resolve(dirname(fileURLToPath(import.meta.url)), '../..');
const HOME_JSON = join(ROOT, 'layouts', 'admin_home.json');

/**
 * JSON 파일을 읽는다.
 *
 * @param path 파일 경로
 * @returns 파싱한 값
 */
function readJson(path: string): any {
  return JSON.parse(readFileSync(path, 'utf-8'));
}

/**
 * 첫 화면 레이아웃에 베이스가 주는 두 데이터소스(로그인 관리자·사이드바 메뉴)를 붙인 시험 레이아웃.
 * 시험 도구는 extends 를 펼치지 않으므로 첫 화면이 읽는 베이스 소스만 옮겨 싣는다(나머지 베이스 소스는 이 판정과 무관하다).
 *
 * @returns 시험 레이아웃
 */
function homeLayout(): any {
  expect(existsSync(HOME_JSON), 'layouts/admin_home.json 이 있어야 한다').toBe(true);
  const home = readJson(HOME_JSON);
  const base = readJson(join(ROOT, 'layouts', '_admin_base.json'));
  const baseSources = (base.data_sources ?? []).filter((ds: any) => ['current_user', 'admin_menu'].includes(ds.id));

  return { ...home, data_sources: [...baseSources, ...(home.data_sources ?? [])] };
}

/**
 * `/api/admin/auth/user` 응답 본문.
 *
 * @param permissions 보유 권한 식별자
 * @returns 응답 본문
 */
function userBody(permissions: string[]): any {
  return {
    success: true,
    data: { uuid: 'u-1', name: '운영자', is_super: false, permissions: permissions.map((identifier, i) => ({ id: i + 1, identifier, name: identifier })) },
  };
}

/** SSO 운영자 계정이 받는 사이드바 메뉴 — 상위 메뉴는 주소 없이 하위 둘을 묶는다 */
const SSO_MENU = {
  success: true,
  data: [
    {
      id: 30,
      name: { ko: 'SSO 연동', en: 'SSO' },
      slug: 'sirsoft-sso',
      url: null,
      children: [
        { id: 31, name: { ko: '회원 연결', en: 'Accounts' }, url: '/admin/plugins/sirsoft-sso/accounts' },
        { id: 32, name: { ko: '이력', en: 'Logs' }, url: '/admin/plugins/sirsoft-sso/logs' },
      ],
    },
  ],
};

describe('관리자 첫 화면 /admin', () => {
  let registry: MockComponentRegistry;

  beforeEach(() => {
    registry = createMockComponentRegistryWithBasics();
  });

  afterEach(() => {
    document.body.innerHTML = '';
  });

  // @scenario role=extension_role_only
  // @effects admin_root_renders_home_layout
  it('/admin 라우트는 대시보드로 바로 보내지 않고 첫 화면 레이아웃을 그린다', () => {
    const routes = readJson(join(ROOT, 'routes.json')).routes ?? [];
    const root = routes.find((r: any) => r.path === '*/admin');
    expect(root, '*/admin 라우트').toBeDefined();
    expect(root.redirect, 'redirect 고정 금지 — 권한을 모르는 채 대시보드로 보낸다').toBeUndefined();
    expect(root.layout).toBe('admin_home');
    expect(root.auth_required).toBe(true);
  });

  // @scenario role=extension_role_only
  // @effects admin_root_renders_home_layout
  it('첫 화면은 _admin_base 를 상속하고 레이아웃 권한을 요구하지 않는다(권한이 없어도 메뉴·머리가 그려진다)', () => {
    expect(existsSync(HOME_JSON), 'layouts/admin_home.json').toBe(true);
    const home = readJson(HOME_JSON);
    expect(home.extends).toBe('_admin_base');
    expect(home.permissions ?? []).toEqual([]);
  });

  // @scenario role=dashboard_reader
  // @effects dashboard_reader_goes_to_dashboard
  it('대시보드 권한이 있으면 종전대로 /admin/dashboard 로 보낸다', async () => {
    const utils = createLayoutTest(homeLayout(), { componentRegistry: registry as any, locale: 'ko' });
    utils.mockApi('current_user', { response: userBody(['core.dashboard.read', 'core.users.read']) });
    utils.mockApi('admin_menu', { response: SSO_MENU });
    await utils.render();
    expect(utils.getNavigationHistory()).toEqual(['/admin/dashboard']);
    utils.cleanup();
  });

  // @scenario role=extension_role_only
  // @effects no_dashboard_goes_to_first_menu
  it('대시보드 권한이 없으면 사이드바 메뉴의 첫 화면(SSO 연동 › 회원 연결)으로 보낸다', async () => {
    const utils = createLayoutTest(homeLayout(), { componentRegistry: registry as any, locale: 'ko' });
    utils.mockApi('current_user', { response: userBody(['sirsoft-sso.accounts.read', 'sirsoft-sso.logs.read']) });
    utils.mockApi('admin_menu', { response: SSO_MENU });
    await utils.render();
    expect(utils.getNavigationHistory()).toEqual(['/admin/plugins/sirsoft-sso/accounts']);
    utils.cleanup();
  });

  // @scenario role=extension_role_only
  // @effects no_dashboard_goes_to_first_menu
  it('대시보드 권한이 없으면 메뉴에 대시보드가 있어도 건너뛰고, 주소 없는 상위 메뉴는 하위 첫 화면으로 간다', async () => {
    const utils = createLayoutTest(homeLayout(), { componentRegistry: registry as any, locale: 'ko' });
    utils.mockApi('current_user', { response: userBody(['sirsoft-ecommerce.products.read']) });
    utils.mockApi('admin_menu', {
      response: {
        success: true,
        data: [
          { id: 1, name: { ko: '대시보드' }, url: '/admin/dashboard', children: [] },
          { id: 2, name: { ko: '쇼핑몰' }, url: null, children: [{ id: 3, name: { ko: '상품' }, url: '/admin/ecommerce/products' }] },
        ],
      },
    });
    await utils.render();
    expect(utils.getNavigationHistory()).toEqual(['/admin/ecommerce/products']);
    utils.cleanup();
  });

  // @scenario role=no_menu
  // @effects no_menu_shows_notice_in_shell
  it('열 수 있는 화면이 하나도 없으면 이동하지 않고 안내를 그린다', async () => {
    const utils = createLayoutTest(homeLayout(), { componentRegistry: registry as any, locale: 'ko' });
    utils.mockApi('current_user', { response: userBody([]) });
    utils.mockApi('admin_menu', { response: { success: true, data: [] } });
    const { container } = await utils.render();
    expect(utils.getNavigationHistory()).toEqual([]);
    expect(container.querySelector('#admin_home_no_screen')).not.toBeNull();
    utils.cleanup();
  });

  // @scenario role=no_menu
  // @effects no_menu_shows_notice_in_shell
  it('사이드바 메뉴를 아직 받지 못했으면 이동도 안내도 하지 않는다(대시보드 권한 없음)', async () => {
    const utils = createLayoutTest(homeLayout(), { componentRegistry: registry as any, locale: 'ko' });
    utils.mockApi('current_user', { response: userBody([]) });
    utils.mockApiError('admin_menu', 500, 'fail');
    const { container } = await utils.render();
    expect(utils.getNavigationHistory()).toEqual([]);
    expect(container.querySelector('#admin_home_no_screen')).toBeNull();
    utils.cleanup();
  });
});
