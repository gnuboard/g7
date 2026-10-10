/**
 * sirsoft-basic — 복귀 주소·메뉴 이동 폴백의 출처 회귀
 *
 * - 소셜 로그인은 복귀 주소로 경로만 넘긴다(절대 주소는 접속한 Host 를 그대로 싣는다).
 * - 사용자 메뉴의 이동 폴백(엔진 navigate 부재)은 같은 출처 경로로만 이동한다.
 * - 본인인증 모달을 열지 못해 인증 화면으로 넘어갈 때 `return_url=<경로>` 를 넘긴다(화면이 읽는 이름).
 */
import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import SocialLoginButtons from '../components/composite/SocialLoginButtons';
import { UserInfo } from '../components/composite/UserInfo';
import { sirsoftBasicIdentityLauncher } from '../handlers/identityLauncher';

describe('sirsoft-basic — 복귀 주소·이동 폴백', () => {
  let originalLocation: Location;
  let fakeLocation: { href: string; pathname: string; search: string; hash: string; origin: string };

  beforeEach(() => {
    originalLocation = window.location;
    fakeLocation = {
      href: `${window.location.origin}/login?x=1`,
      pathname: '/login',
      search: '?x=1',
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

  // @scenario host_header=forged, sink=template_return_path
  // @effects template_passes_path_only_return
  it('소셜 로그인 복귀 주소는 경로만 넘긴다', () => {
    (window as any).G7Core = { t: (k: string) => k };
    render(<SocialLoginButtons providers={['google']} />);

    fireEvent.click(screen.getAllByRole('button')[0]);

    expect(fakeLocation.href).toBe(`/api/auth/google?redirect=${encodeURIComponent('/login?x=1')}`);
  });

  // @scenario host_header=forged, sink=template_nav_fallback
  // @effects template_nav_fallback_same_origin_only
  it('사용자 메뉴 이동 폴백은 외부 주소로 이동하지 않고, 같은 출처 경로로는 이동한다', () => {
    (window as any).G7Core = { t: (k: string) => k };
    const { container } = render(
      <UserInfo
        name="홍길동"
        userId={1}
        menuItems={[
          { key: 'evil', label: '외부', path: 'https://evil.example/phish' },
          { key: 'mine', label: '내 글', path: '/board/mine' },
        ] as any}
      />,
    );

    fireEvent.click(screen.getByText('홍길동'));
    fireEvent.click(screen.getByText('외부'));
    expect(fakeLocation.href).toBe(`${originalLocation.origin}/login?x=1`);

    fireEvent.click(screen.getByText('홍길동'));
    fireEvent.click(screen.getByText('내 글'));
    expect(fakeLocation.href).toBe('/board/mine');
    expect(container).toBeTruthy();
  });

  // @scenario host_header=canonical, sink=template_return_path
  // @effects template_identity_fallback_passes_return_url_path
  it('본인인증 모달을 못 열면 인증 화면에 return_url=<경로> 를 넘긴다', async () => {
    fakeLocation.pathname = '/mypage/profile';
    fakeLocation.search = '?tab=info';
    fakeLocation.hash = '#contact';
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

    await sirsoftBasicIdentityLauncher({
      policy_key: 'k',
      purpose: 'sensitive_action',
      provider_id: 'g7:core.mail',
      render_hint: 'text_code',
      return_request: { method: 'POST', url: '/api/me' },
    } as any);

    expect(redirectExternally).toHaveBeenCalledTimes(1);
    const redirectUrl = (redirectExternally.mock.calls[0] as any)[0].redirect_url as string;
    expect(redirectUrl).toContain(`return_url=${encodeURIComponent('/mypage/profile?tab=info')}`);
    expect(redirectUrl).not.toContain('return=http');
  });
});
