/**
 * template-engine.ts — 운영 화면 편집 진입 단추 마운트 (설계 확정 2026-11-09)
 *
 * 편집 진입 단추(EditEntryFab)는 2026-05-25 에 만들어졌지만 어느 화면에도 마운트되지 않았다.
 * 일반 렌더 트리(renderTemplate·updateTemplateData)에 붙어, 로그인했고 레이아웃 편집 권한
 * (`can_edit_layouts`, 서버가 편집기 가드와 같은 판정으로 싣는다)이 있는 사람에게만 보여야 한다.
 * 진입 주소의 `?route=` 는 브라우저 주소가 아니라 **매칭된 라우트 정의 path** 여야 한다 — 편집기 트리는
 * `*\/admin/users` 같은 정의 path 와 정확히 견주므로 `/admin/users` 를 넘기면 빈 상태로 남았다(λ 실측).
 *
 * 실제 renderTemplate·updateTemplateData·Router·AuthManager 를 쓴다. 렌더러 자체(DynamicRenderer)는 이 시험의 대상이 아니라 비운다.
 */

import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { act } from '@testing-library/react';
import React from 'react';
import { initTemplateEngine, renderTemplate, updateTemplateData, destroyTemplate } from '../template-engine';
import { Router } from '../routing/Router';
import { AuthManager } from '../auth/AuthManager';
import { useResponsive } from '../template-engine/ResponsiveContext';
import { getCssText } from '../style/RuntimeStyleSheet';
import { EDIT_ENTRY_FAB_STYLE_KEY } from '../template-engine/layout-editor/utils/editorRuntimeStyles';

const flush = async (): Promise<void> => {
  await act(async () => {
    await new Promise((resolve) => setTimeout(resolve, 0));
  });
};

vi.mock('../template-engine/ComponentRegistry', () => {
  const mockInstance = {
    loadComponents: vi.fn().mockResolvedValue(undefined),
    getComponent: vi.fn().mockReturnValue(() => null),
    hasComponent: vi.fn().mockReturnValue(true),
    getInstance: vi.fn(),
  };
  mockInstance.getInstance.mockReturnValue(mockInstance);
  return { ComponentRegistry: { getInstance: vi.fn(() => mockInstance) } };
});

vi.mock('../template-engine/DataBindingEngine', () => {
  const DataBindingEngine = vi.fn(function (this: any) {
    this.bind = vi.fn();
    this.unbind = vi.fn();
    this.invalidateCacheByKeys = vi.fn();
  });
  return { DataBindingEngine, dataBindingEngine: new (DataBindingEngine as any)() };
});

vi.mock('../template-engine/TranslationEngine', () => {
  const mockInstance = {
    translate: vi.fn((key: string) => key),
    setLocale: vi.fn(),
    loadTranslations: vi.fn().mockResolvedValue({}),
    resolveTranslations: vi.fn((text: string) => text),
    clearCache: vi.fn(),
  };
  return {
    TranslationEngine: { getInstance: vi.fn(() => mockInstance), resetInstance: vi.fn() },
    TranslationContext: {} as any,
  };
});

vi.mock('../template-engine/ActionDispatcher', () => ({
  ActionDispatcher: vi.fn(function (this: any) {
    this.dispatch = vi.fn();
    this.register = vi.fn();
    this.createHandler = vi.fn(() => vi.fn());
  }),
  setActionDispatcherInstance: vi.fn(),
  getActionDispatcher: vi.fn(() => ({ dispatch: vi.fn(), register: vi.fn() })),
}));

vi.mock('../template-engine/DynamicRenderer', () => ({ default: vi.fn(() => null) }));

vi.mock('../template-engine/ResponsiveManager', () => ({
  responsiveManager: {
    getWidth: vi.fn(() => 1280),
    subscribe: vi.fn(() => () => {}),
    getMatchingKey: vi.fn(() => null),
    parseRange: vi.fn(() => null),
  },
  BREAKPOINT_PRESETS: {},
}));

vi.mock('../template-engine/ResponsiveContext', () => ({
  ResponsiveContext: {},
  ResponsiveProvider: ({ children }: { children: React.ReactNode }) => children,
  useResponsive: vi.fn(() => ({ width: 1280, isMobile: false, isTablet: false, isDesktop: true, matchedPreset: 'desktop' })),
}));

const LAYOUT = { layout_name: 'admin_user_list', components: [{ id: 'root', type: 'basic', name: 'Div' }] } as any;

function setAuth(user: Record<string, unknown> | null): void {
  vi.spyOn(AuthManager.getInstance(), 'getState').mockReturnValue({
    isAuthenticated: user !== null,
    user: user as any,
    type: user ? 'admin' : null,
  });
}

async function renderAt(pathname: string): Promise<void> {
  window.history.replaceState({}, '', pathname);
  await act(async () => {
    await renderTemplate({
      containerId: 'app',
      layoutJson: LAYOUT,
      dataContext: {},
      translationContext: { templateId: 'sirsoft-admin_basic', locale: 'ko' },
    });
  });
  await flush();
}

function fab(): HTMLButtonElement | null {
  return document.querySelector('[data-testid="g7le-edit-entry-fab"]');
}

describe('template-engine — 운영 화면 편집 진입 단추', () => {
  let container: HTMLDivElement;

  beforeEach(async () => {
    destroyTemplate();
    vi.clearAllMocks();
    container = document.createElement('div');
    container.id = 'app';
    document.body.appendChild(container);

    const router = new Router('sirsoft-admin_basic');
    router.setRoutes([
      { path: '*/admin/users', layout: 'admin_user_list' },
      { path: '*/admin/users/:id', layout: 'admin_user_detail' },
      { path: '/board/:slug', layout: 'board_list' },
    ]);
    (window as any).__templateApp = { getRouter: () => router };

    await initTemplateEngine({ templateId: 'sirsoft-admin_basic', locale: 'ko' });
  });

  afterEach(async () => {
    destroyTemplate();
    await flush();
    container.remove();
    delete (window as any).__templateApp;
    if ((window as any).G7Config) delete (window as any).G7Config.basePath;
    vi.restoreAllMocks();
    window.history.replaceState({}, '', '/');
  });

  // @scenario case=ops_screen_floating_fab_authenticated_with_perm_normal_render_admin
  // @effects ops_screen_fab_mounts_for_layout_editor, ops_screen_fab_passes_route_definition_path
  it('권한 보유자에게 단추가 보이고, 진입 주소의 ?route= 가 매칭된 라우트 정의 path 다', async () => {
    setAuth({ uuid: 'u1', name: 'a', email: 'a@x', can_edit_layouts: true });
    await renderAt('/admin/users');

    expect(fab(), '일반 렌더 트리에 편집 단추가 붙어야 한다').not.toBeNull();
    expect(fab()!.getAttribute('data-entry-url')).toBe('/admin/layout-editor/sirsoft-admin_basic?route=*%2Fadmin%2Fusers');

    // 언어 접두 주소·동적 파라미터 주소도 정의 path 로 넘긴다
    await renderAt('/en/admin/users/42');
    expect(fab()!.getAttribute('data-entry-url')).toBe(
      '/admin/layout-editor/sirsoft-admin_basic?route=' + encodeURIComponent('*/admin/users/:id'),
    );
  });

  // @scenario case=ops_screen_floating_fab_authenticated_with_perm_normal_render
  // @effects ops_screen_fab_survives_template_data_update
  it('updateTemplateData 재렌더 뒤에도 단추가 남는다', async () => {
    setAuth({ uuid: 'u1', name: 'a', email: 'a@x', can_edit_layouts: true });
    await renderAt('/admin/users');
    await act(async () => {
      updateTemplateData({ _global: { x: 1 } });
    });
    await flush();
    expect(fab()).not.toBeNull();
  });

  // @scenario case=ops_screen_floating_fab_authenticated_no_perm_normal_render
  // @effects ops_screen_fab_hidden_without_ability
  it('권한 판정이 거짓이거나 없거나, 비로그인이면 단추가 없다', async () => {
    setAuth({ uuid: 'u1', name: 'a', email: 'a@x', can_edit_layouts: false });
    await renderAt('/admin/users');
    expect(fab()).toBeNull();

    vi.restoreAllMocks();
    // permissions 목록에 권한이 있어도 서버 판정(can_edit_layouts)이 없으면 보이지 않는다
    setAuth({ uuid: 'u1', name: 'a', email: 'a@x', permissions: [{ identifier: 'core.templates.layouts.edit' }] });
    await renderAt('/admin/users');
    expect(fab()).toBeNull();

    vi.restoreAllMocks();
    setAuth(null);
    await renderAt('/admin/users');
    expect(fab()).toBeNull();
  });

  // @scenario case=ops_screen_floating_fab_authenticated_with_perm_yes
  // @effects ops_screen_fab_supports_subfolder_install
  it('하위 폴더 설치는 접두사를 벗겨 라우트를 찾고 진입 주소에 접두사를 붙인다', async () => {
    (window as any).G7Config = { ...((window as any).G7Config ?? {}), basePath: '/g7' };
    setAuth({ uuid: 'u1', name: 'a', email: 'a@x', can_edit_layouts: true });
    await renderAt('/g7/board/notice');
    expect(fab()!.getAttribute('data-entry-url')).toBe(
      '/g7/admin/layout-editor/sirsoft-admin_basic?route=' + encodeURIComponent('/board/:slug'),
    );
  });

  // @scenario case=ops_screen_floating_fab_mobile
  // @effects ops_screen_fab_compact_has_accessible_name
  it('폭과 관계없이 글자 없는 아이콘 원형 단추이고 보이는 이름은 aria-label 로 준다, 오른쪽 아래에 있고 인쇄에서는 숨는다 (7.2.0)', async () => {
    vi.mocked(useResponsive).mockReturnValue({ width: 375, isMobile: true, isTablet: false, isDesktop: false, matchedPreset: 'mobile' });
    setAuth({ uuid: 'u1', name: 'a', email: 'a@x', can_edit_layouts: true });
    await renderAt('/admin/users');
    const button = fab()!;
    expect(button.getAttribute('aria-label')).toBe('layout_editor.chrome.fab.label');
    expect(button.textContent).toBe('');
    expect(button.querySelector('svg')).not.toBeNull();
    expect(button.tagName).toBe('BUTTON');
    expect(button.style.right).toBe('16px');
    expect(button.style.left).toBe('');
    // 규칙은 JSX <style> 자식이 아니라 런타임 시트(RuntimeStyleSheet)로 붙는다 — 엄격한 스타일 정책에서 <style> 이 막힌다(공개 #163)
    expect(document.querySelector('style[data-g7le-edit-entry-fab-style]')).toBeNull();
    const style = getCssText(EDIT_ENTRY_FAB_STYLE_KEY) ?? '';
    expect(style).toContain('@media print');
    expect(style).toContain('data-g7-color-scheme="dark"');
    expect(style).toContain(':focus-visible');
    expect(style).toContain('width:44px');
  });

  // @scenario case=setting_toggle_live
  // @effects ops_screen_fab_hidden_when_setting_off, ops_screen_fab_follows_setting_save
  it('환경설정 「레이아웃 편집 단추」가 꺼지면 권한 보유자에게도 숨고, 설정 저장(_global.settings 갱신)을 새로고침 없이 따라간다 (7.2.0)', async () => {
    setAuth({ uuid: 'u1', name: 'a', email: 'a@x', can_edit_layouts: true });
    await renderAt('/admin/users');
    expect(fab()).not.toBeNull();

    // 환경설정 저장 성공 갈래가 하는 일 — set-state global settings={{frontend_settings}}
    await act(async () => {
      updateTemplateData({ _global: { settings: { general: { layout_edit_button_enabled: false } } } });
    });
    await flush();
    expect(fab(), '설정을 끄면 바로 사라져야 한다').toBeNull();

    await act(async () => {
      updateTemplateData({ _global: { settings: { general: { layout_edit_button_enabled: true } } } });
    });
    await flush();
    expect(fab(), '다시 켜면 바로 보여야 한다').not.toBeNull();
  });

  // @scenario case=setting_toggle_shell
  // @effects ops_screen_fab_hidden_when_setting_off
  it('첫 화면에서 셸 설정(G7Config.settings)이 꺼져 있으면 처음부터 그리지 않는다 (7.2.0)', async () => {
    (window as any).G7Config = { ...((window as any).G7Config ?? {}), settings: { general: { layout_edit_button_enabled: false } } };
    setAuth({ uuid: 'u1', name: 'a', email: 'a@x', can_edit_layouts: true });
    await renderAt('/admin/users');
    expect(fab()).toBeNull();
    delete (window as any).G7Config.settings;
  });

  // @scenario case=ops_screen_floating_fab_normal_render_logout
  // @effects ops_screen_fab_follows_auth_state_change
  it('로그아웃 알림이 오면 단추가 사라진다', async () => {
    const manager = AuthManager.getInstance();
    const spy = vi.spyOn(manager, 'getState').mockReturnValue({
      isAuthenticated: true,
      user: { uuid: 'u1', name: 'a', email: 'a@x', can_edit_layouts: true } as any,
      type: 'admin',
    });
    await renderAt('/admin/users');
    expect(fab()).not.toBeNull();

    spy.mockReturnValue({ isAuthenticated: false, user: null, type: null });
    await act(async () => {
      (manager as any).emit('authStateChange', { isAuthenticated: false, user: null, type: null });
    });
    await flush();
    expect(fab()).toBeNull();
  });
  // @scenario case=ops_screen_floating_fab_after_spa_login
  // @effects ops_screen_fab_resolves_ability_after_spa_login
  it('로그인 응답처럼 판정 필드가 없는 사용자면 로그인 사용자 조회로 한 번 판정을 받아 온다 — 로그인 뒤 SPA 이동에서도 보인다', async () => {
    localStorage.setItem('auth_token', 'test-token');
    const calls: string[] = [];
    vi.stubGlobal(
      'fetch',
      vi.fn(async (input: RequestInfo | URL, init?: RequestInit) => {
        calls.push(`${String(input)} ${(init?.headers as Record<string, string>)?.Authorization ?? ''}`);
        return new Response(JSON.stringify({ success: true, data: { uuid: 'u1', can_edit_layouts: true } }), {
          status: 200,
          headers: { 'Content-Type': 'application/json' },
        });
      }),
    );
    // 로그인 응답(UserResource::toArray)에는 can_edit_layouts 가 없다
    setAuth({ uuid: 'u1', name: 'a', email: 'a@x' });
    await renderAt('/admin/users');
    await flush();
    expect(fab(), '판정을 받아 온 뒤 단추가 보여야 한다').not.toBeNull();
    expect(calls).toEqual(['/api/admin/auth/user Bearer test-token']);

    // 다시 그려도 같은 사용자면 다시 묻지 않는다
    await act(async () => {
      updateTemplateData({ _global: { y: 1 } });
    });
    await flush();
    expect(calls).toHaveLength(1);
    vi.unstubAllGlobals();
    localStorage.removeItem('auth_token');
  });

  // @scenario case=ops_screen_floating_fab_after_spa_login_no_perm
  // @effects ops_screen_fab_resolves_ability_after_spa_login
  it('받아 온 판정이 거짓이거나 조회가 실패하면 보이지 않는다', async () => {
    localStorage.setItem('auth_token', 'test-token');
    vi.stubGlobal('fetch', vi.fn(async () => new Response(JSON.stringify({ success: true, data: { can_edit_layouts: false } }), { status: 200 })));
    setAuth({ uuid: 'u2', name: 'a', email: 'a@x' });
    await renderAt('/admin/users');
    await flush();
    expect(fab()).toBeNull();
    vi.unstubAllGlobals();

    vi.restoreAllMocks();
    vi.stubGlobal('fetch', vi.fn(async () => new Response('{}', { status: 500 })));
    setAuth({ uuid: 'u3', name: 'a', email: 'a@x' });
    await renderAt('/admin/users');
    await flush();
    expect(fab()).toBeNull();
    vi.unstubAllGlobals();
    localStorage.removeItem('auth_token');
  });
});
