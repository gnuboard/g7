/**
 * @file login-auto-redirect.test.tsx
 * @description 로그인 화면 자동 이동·비상 진입로 회귀 테스트 (sirsoft-basic, 7.2.0 B1⑤⑥)
 *
 * 사이트가 회사 계정 로그인으로 바로 보내도록 정했으면(`_global.appConfig.auth.auto_redirect.user`) 로그인 화면 진입(`<init>`) 때
 * 그 경로로 `redirect replace` 한다(redirect 쿼리 전달). 다만 주소에 `local`(비상 계정 진입로)·`login_handoff`(인계 교환)·
 * `sso_error`·`sso_pending`·`sso_link` 중 하나라도 있으면 이동하지 않는다 — 오류로 돌아온 화면이 다시 IdP 로 튕기면 무한 왕복이다.
 * 자동 이동이 켜진 사이트에는 하단에 「다른 방법으로 로그인」(`/login?local=1`) 링크가 있다.
 *
 * 진입 액션은 운영(TemplateApp.executeInitActions)과 같이 `ActionDispatcher.createHandler(액션, {_global, query, ...})` 로 돌린다 —
 * 하네스의 `triggerAction` 문맥에는 `query` 가 없다.
 *
 * @vitest-environment jsdom
 * @since 1.4.0
 */

import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { createLayoutTest } from '@/core/template-engine/__tests__/utils/layoutTestUtils';
import { asArray } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { ComponentRegistry } from '@/core/template-engine/ComponentRegistry';
import { ActionDispatcher } from '@/core/template-engine/ActionDispatcher';

import loginPage from '../../layouts/auth/login.json';
import koAuth from '../../lang/partial/ko/auth.json';

/**
 * 트리를 평탄화합니다.
 *
 * @param node 노드
 * @returns 노드 목록
 */
function flatten(node: any): any[] {
  if (!node || typeof node !== 'object') return [];
  if (Array.isArray(node)) return node.flatMap(flatten);
  const children = Array.isArray(node.children) ? node.children.flatMap(flatten) : [];
  const slots = node.slots ? Object.values(node.slots).flatMap(flatten) : [];
  return [node, ...children, ...slots];
}

/** 진입 시 자동 이동 액션 — `<init>` 의 redirect */
const autoRedirect = asArray<any>((loginPage as any).init_actions).find((a) => a.handler === 'redirect');

/** 하단 「다른 방법으로 로그인」 블록 */
const otherMethodBlock = flatten(loginPage as any).find(
  (n) => n.name === 'Div' && String(n.if ?? '').includes('auto_redirect?.user') && JSON.stringify(n).includes('/login?local=1'),
);

const AUTO_PATH = '/plugins/sirsoft-sso/start/corp';

/** 같은 탭 이동(`redirect` 핸들러)의 종착점 — jsdom 의 location 은 잠겨 있어 객체를 바꿔 끼운다. */
const locationAssign = vi.fn();
const locationReplace = vi.fn();
let originalLocation: Location;

/**
 * 로그인 화면 진입 액션(자동 이동)을 운영과 같은 문맥으로 실행합니다.
 *
 * @param autoPath 자동 이동 경로 (없으면 null)
 * @param query 주소 쿼리
 * @returns 완료 약속
 */
async function runAutoRedirect(autoPath: string | null, query: Record<string, string>): Promise<void> {
  const dispatcher = new ActionDispatcher({ navigate: vi.fn() });
  const context = {
    _global: { appConfig: { auth: { auto_redirect: { user: autoPath, admin: null } } } },
    _local: {},
    query,
    route: { path: '/login' },
  };
  // 운영에서는 진입 액션 문맥의 `_global` 과 `G7Core.state.get()` 이 같은 전역 상태다. 액션 인자 해석은 `_global` 을
  // `G7Core.state.get()` 으로 바꿔 읽으므로(stale closure 방지), 그 둘을 맞춘다 — 하네스(layoutTestUtils)를 불러오면
  // `G7Core.state.get()` 이 빈 전역 상태를 돌려 `url` 이 "undefined" 로 풀렸다
  const g7State = (window as any).G7Core?.state;
  const getSpy = typeof g7State?.get === 'function' ? vi.spyOn(g7State, 'get').mockReturnValue(context._global) : null;
  try {
    const handler = dispatcher.createHandler({ ...autoRedirect, type: 'click' }, context);
    await handler(new Event('init'));
  } finally {
    getSpy?.mockRestore();
  }
}

describe('sirsoft-basic 로그인 화면 자동 이동 (7.2.0 B1)', () => {
  beforeEach(() => {
    locationAssign.mockReset();
    locationReplace.mockReset();
    originalLocation = window.location;
    delete (window as any).location;
    (window as any).location = {
      origin: 'http://localhost', protocol: 'http:', host: 'localhost', hostname: 'localhost', port: '',
      pathname: '/login', search: '', hash: '', href: 'http://localhost/login',
      assign: locationAssign, replace: locationReplace, reload: vi.fn(),
    };
  });

  afterEach(() => {
    (window as any).location = originalLocation;
  });

  /**
   * @scenario auto_redirect=on, query=none
   * @effects auto_redirect_replaces_to_configured_path
   */
  it('자동 이동 경로가 있고 우회 쿼리가 없으면 진입 시 그 경로로 replace 이동한다', async () => {
    expect(autoRedirect, 'login.g7.html <init> 에 redirect 액션이 없다').toBeDefined();
    expect(autoRedirect.params?.replace).toBe(true);

    await runAutoRedirect(AUTO_PATH, {});

    expect(locationReplace).toHaveBeenCalledTimes(1);
    expect(locationReplace).toHaveBeenCalledWith(AUTO_PATH);
    expect(locationAssign).not.toHaveBeenCalled();
  });

  /**
   * @scenario auto_redirect=on, query=redirect
   * @effects auto_redirect_carries_redirect
   */
  it.each([
    [AUTO_PATH, `${AUTO_PATH}?redirect=%2Fmypage%2Forders`],
    [`${AUTO_PATH}?prompt=login`, `${AUTO_PATH}?prompt=login&redirect=%2Fmypage%2Forders`],
  ])('redirect 쿼리를 실어 보낸다 (%s → %s)', async (autoPath, expected) => {
    await runAutoRedirect(autoPath, { redirect: '/mypage/orders' });

    expect(locationReplace).toHaveBeenCalledWith(expected);
  });

  /**
   * @scenario auto_redirect=on, query=login_handoff
   * @effects auto_redirect_skipped_by_bypass_query
   */
  /**
   * @scenario auto_redirect=on, query=sso_error
   * @effects auto_redirect_skipped_by_bypass_query
   */
  /**
   * @scenario auto_redirect=on, query=sso_pending
   * @effects auto_redirect_skipped_by_bypass_query
   */
  /**
   * @scenario auto_redirect=on, query=sso_link
   * @effects auto_redirect_skipped_by_bypass_query
   */
  /**
   * @scenario auto_redirect=on, query=local
   * @effects auto_redirect_skipped_by_bypass_query
   */
  it.each([
    ['local', '1'],
    ['login_handoff', 'HANDOFF-CODE'],
    ['sso_error', 'access_denied'],
    ['sso_pending', 'p-1'],
    ['sso_link', 'l-1'],
  ])('주소에 %s 가 있으면 이동하지 않는다', async (key, value) => {
    await runAutoRedirect(AUTO_PATH, { [key]: value, redirect: '/mypage' });

    expect(locationReplace).not.toHaveBeenCalled();
    expect(locationAssign).not.toHaveBeenCalled();
  });

  /**
   * @scenario auto_redirect=off, query=none
   * @effects auto_redirect_off_stays_on_login
   */
  it('자동 이동 경로가 없으면 이동하지 않는다', async () => {
    await runAutoRedirect(null, {});

    expect(locationReplace).not.toHaveBeenCalled();
    expect(locationAssign).not.toHaveBeenCalled();
  });

  /**
   * @scenario auto_redirect=off, query=none
   * @effects local_login_link_follows_auto_redirect
   */
  /**
   * @scenario auto_redirect=on, query=none
   * @effects local_login_link_follows_auto_redirect
   */
  it.each([
    [AUTO_PATH, 1],
    [null, 0],
  ])('자동 이동(%s)이 켜진 사이트에만 하단 「다른 방법으로 로그인」(?local=1) 링크가 있다 (%i개)', async (autoPath, count) => {
    expect(otherMethodBlock, 'login.g7.html 에 ?local=1 링크 블록이 없다').toBeDefined();

    const registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: ({ className, children }: any) => <div className={className}>{children}</div>, metadata: { name: 'Div', type: 'basic' } },
      A: { component: ({ href, children, text }: any) => <a href={href}>{children ?? text}</a>, metadata: { name: 'A', type: 'basic' } },
      Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
    };
    const t = createLayoutTest(
      { version: '1.0.0', layout_name: 'auth/login', components: [otherMethodBlock] } as any,
      {
        componentRegistry: registry,
        translations: { auth: koAuth },
        initialState: { _global: { appConfig: { auth: { auto_redirect: { user: autoPath, admin: null } } } } },
      },
    );
    await t.render();

    const links = Array.from(document.querySelectorAll('a[href="/login?local=1"]'));
    expect(links.length).toBe(count);
    if (count > 0) {
      expect(links[0].textContent).toBe(koAuth.external.other_method);
    }

    t.cleanup();
  });

  /**
   * @scenario auto_redirect=on, query=local
   * @effects local_notice_shown_on_local_entry
   */
  /**
   * @scenario auto_redirect=off, query=none
   * @effects local_notice_shown_on_local_entry
   */
  it.each([
    [{ local: '1' }, true],
    [{}, false],
  ])('비상 계정 진입(?local=1)이면 비밀번호 화면임을 알리는 안내가 보인다 (%o → %s, 디자인 ⑤)', async (query, shown) => {
    const notice = flatten(loginPage as any).find((n) => n.id === 'login_local_notice');
    expect(notice, 'login.g7.html 에 비상 계정 안내가 없다').toBeDefined();
    expect(String(notice.if)).toContain('query.local');

    const registry = ComponentRegistry.getInstance();
    (registry as any).registry = {
      Div: { component: ({ className, children, text }: any) => <div className={className}>{children ?? text}</div>, metadata: { name: 'Div', type: 'basic' } },
      Fragment: { component: ({ children }: any) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
    };
    const t = createLayoutTest(
      { version: '1.0.0', layout_name: 'auth/login', components: [notice] } as any,
      { componentRegistry: registry, translations: { auth: koAuth }, routeParams: {}, initialState: {}, queryParams: query } as any,
    );
    await t.render();

    expect(document.body.textContent?.includes(koAuth.external.local_notice)).toBe(shown);
    t.cleanup();
  });
});
