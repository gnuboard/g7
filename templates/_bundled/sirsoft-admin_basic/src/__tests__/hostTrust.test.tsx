/**
 * sirsoft-admin_basic — 메뉴 이동 폴백·본인인증 복귀 주소의 출처 회귀
 *
 * - 사이드바 메뉴의 이동 폴백(엔진 dispatch 부재)은 같은 출처 경로로만 이동한다.
 * - 본인인증 모달을 열지 못해 인증 화면으로 넘어갈 때 `return_url=<경로>` 를 넘긴다(화면이 읽는 이름).
 */
import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { AdminSidebar, MenuItem } from '../components/composite/AdminSidebar';
import { sirsoftAdminBasicIdentityLauncher } from '../handlers/identityLauncher';

describe('sirsoft-admin_basic — 이동 폴백·복귀 주소', () => {
  let originalLocation: Location;
  let fakeLocation: { href: string; pathname: string; search: string; hash: string; origin: string };

  beforeEach(() => {
    originalLocation = window.location;
    fakeLocation = {
      href: `${window.location.origin}/admin/settings`,
      pathname: '/admin/settings',
      search: '?tab=identity',
      hash: '',
      origin: window.location.origin,
    };
    delete (window as any).location;
    (window as any).location = fakeLocation;
  });

  afterEach(() => {
    (window as any).location = originalLocation;
    delete (window as any).G7Core;
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
  });

  // @scenario host_header=forged, sink=template_nav_fallback
  // @effects template_nav_fallback_same_origin_only
  it('사이드바 이동 폴백은 외부 주소로 이동하지 않고, 같은 출처 경로로는 이동한다', () => {
    (window as any).G7Core = {
      locale: { current: () => 'ko', supported: () => ['ko'], change: vi.fn() },
      state: { get: () => ({ _global: { locale: 'ko' } }), set: vi.fn(), subscribe: () => () => undefined },
    };
    const menu: MenuItem[] = [
      { id: 1, name: { ko: '외부', en: 'External' }, slug: 'evil', url: 'https://evil.example/phish', icon: 'fas fa-home' },
      { id: 2, name: { ko: '설정', en: 'Settings' }, slug: 'settings', url: '/admin/settings', icon: 'fas fa-cog' },
    ] as MenuItem[];
    render(<AdminSidebar menu={menu} />);

    fireEvent.click(screen.getByText('외부'));
    expect(fakeLocation.href).toBe(`${originalLocation.origin}/admin/settings`);

    fireEvent.click(screen.getByText('설정'));
    expect(fakeLocation.href).toBe('/admin/settings');
  });

  // @scenario host_header=canonical, sink=template_return_path
  // @effects template_identity_fallback_passes_return_url_path
  it('본인인증 모달을 못 열면 인증 화면에 return_url=<경로> 를 넘긴다', async () => {
    const redirectExternally = vi.fn(() => Promise.resolve({ status: 'cancelled' }));
    (window as any).G7Core = {
      dispatch: vi.fn(async (action: { handler: string }) => {
        if (action.handler === 'openModal') {
          throw new Error('modal unavailable');
        }
      }),
      state: { get: vi.fn(() => ({})), getGlobal: vi.fn(() => ({})), getLocal: vi.fn(() => ({})), set: vi.fn() },
      // challenge 시작은 코어 G7Core.api 로 나간다 (ApiClient 는 응답 본문을 돌려준다)
      api: {
        post: vi.fn(async () => ({
          success: true,
          data: { id: 'ch_1', expires_at: new Date(Date.now() + 300000).toISOString(), render_hint: 'text_code' },
        })),
      },
      identity: { createDeferred: vi.fn(() => Promise.resolve({ status: 'cancelled' })), redirectExternally },
    };

    await sirsoftAdminBasicIdentityLauncher({
      policy_key: 'k',
      purpose: 'sensitive_action',
      provider_id: 'g7:core.mail',
      render_hint: 'text_code',
      return_request: { method: 'PUT', url: '/api/admin/settings' },
    } as any);

    expect(redirectExternally).toHaveBeenCalledTimes(1);
    const redirectUrl = (redirectExternally.mock.calls[0] as any)[0].redirect_url as string;
    expect(redirectUrl).toContain(`return_url=${encodeURIComponent('/admin/settings?tab=identity')}`);
    expect(redirectUrl).not.toContain('return=http');
  });
});
