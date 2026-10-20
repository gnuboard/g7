/**
 * @file ProviderButtons.test.tsx
 * @description 제공자 버튼 컴포넌트 — 모양 계열별 클래스·심볼·문구, 시작 주소, 연결·재확인 핸들러 호출.
 *
 * @vitest-environment jsdom
 */
import React from 'react';
import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { render, cleanup } from '@testing-library/react';
import { ProviderButtons } from '../components/composite/ProviderButtons';
import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';

/** 버튼 스타일시트에서 선택자 하나의 선언을 꺼낸다(정확히 그 선택자만 가진 규칙). */
const cssRule = (css: string, selector: string): Record<string, string> | null => {
  const escaped = selector.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
  const match = css.match(new RegExp(`(?:^|\\})\\s*${escaped}\\s*\\{([^}]*)\\}`));
  if (!match) return null;
  return Object.fromEntries(
    match[1].split(';').map((d) => d.trim()).filter(Boolean).map((d) => {
      const [k, ...v] = d.split(':');
      return [k.trim(), v.join(':').trim().toLowerCase()];
    }),
  );
};

const providers = [
  { key: 'kakao', label: '카카오', brand: 'kakao' as const, style: null, start_url: '/plugins/sirsoft-social_login/oauth/kakao/start' },
  { key: 'naver', label: '네이버', brand: 'naver' as const, style: null, start_url: '/plugins/sirsoft-social_login/oauth/naver/start' },
  { key: 'google', label: 'Google', brand: 'google' as const, style: null, start_url: '/plugins/sirsoft-social_login/oauth/google/start' },
  { key: 'facebook', label: 'Facebook', brand: 'facebook' as const, style: null, start_url: '/plugins/sirsoft-social_login/oauth/facebook/start' },
  { key: 'corp', label: '사내 계정', brand: 'custom' as const, style: { bg: '#112233', fg: '#ffffff', border: '#000000' }, start_url: '/plugins/sirsoft-social_login/oauth/corp/start' },
];

describe('ProviderButtons', () => {
  let dispatch: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    dispatch = vi.fn();
    (window as any).G7Core = { dispatch, t: (key: string) => key };
  });

  afterEach(() => {
    cleanup();
    delete (window as any).G7Core;
  });

  /**
   * @scenario intent=login, brand=all
   * @effects buttons_are_links_with_redirect, brand_class_per_provider, custom_style_and_initial
   */
  it('로그인 버튼은 시작 주소 링크이고 복귀 주소를 싣는다 — 계열별 클래스·커스텀 색·첫 글자', () => {
    const { container } = render(<ProviderButtons providers={providers} intent="login" redirect="/mypage/orders" />);
    const links = container.querySelectorAll('a[data-social-provider]');

    expect(links).toHaveLength(5);
    expect(links[0].getAttribute('href')).toBe('/plugins/sirsoft-social_login/oauth/kakao/start?redirect=%2Fmypage%2Forders');
    expect(Array.from(links).map((a) => a.className.split(' ')[1])).toEqual([
      'sl-button--kakao', 'sl-button--naver', 'sl-button--google', 'sl-button--facebook', 'sl-button--custom',
    ]);
    const custom = links[4] as HTMLElement;
    expect(custom.style.backgroundColor).toBe('rgb(17, 34, 51)');
    expect(custom.querySelector('.sl-symbol--initial')?.textContent).toBe('사');
    expect(container.querySelectorAll('svg.sl-symbol')).toHaveLength(4);
  });

  /**
   * @scenario intent=login, redirect=external
   * @effects external_redirect_not_attached
   */
  it('바깥 주소·프로토콜 상대 주소는 복귀 주소로 싣지 않는다', () => {
    const { container } = render(<ProviderButtons providers={providers.slice(0, 1)} redirect="//evil.example" />);

    expect(container.querySelector('a')?.getAttribute('href')).toBe('/plugins/sirsoft-social_login/oauth/kakao/start');
  });

  /**
   * @scenario intent=login|register|link|reauth, i18n=missing
   * @effects button_text_per_intent
   */
  it('번역이 없으면 의도별 기본 문구에 제공자 이름을 넣는다', () => {
    const custom = [providers[4]];
    const texts = (['login', 'register', 'link', 'reauth'] as const).map((intent) => {
      const { container, unmount } = render(<ProviderButtons providers={custom} intent={intent} />);
      const text = container.querySelector('.sl-button__text')?.textContent;
      unmount();
      return text;
    });

    expect(texts).toEqual(['사내 계정(으)로 로그인', '사내 계정(으)로 가입', '사내 계정 연결', '사내 계정(으)로 다시 확인']);
  });

  /**
   * @scenario intent=link|reauth
   * @effects link_button_dispatches_start_link, reauth_button_dispatches_start_reauth
   */
  it('연결·재확인 버튼은 플러그인 핸들러를 부른다', () => {
    const { container, unmount } = render(<ProviderButtons providers={providers.slice(1, 2)} intent="link" />);
    (container.querySelector('button') as HTMLButtonElement).click();
    expect(dispatch).toHaveBeenCalledWith({ handler: 'sirsoft-social_login.startLink', params: { provider: 'naver' } });
    unmount();

    const reauth = render(<ProviderButtons providers={[{ provider_key: 'kakao', label: '카카오', brand: 'kakao' }]} intent="reauth" />);
    (reauth.container.querySelector('button') as HTMLButtonElement).click();
    expect(dispatch).toHaveBeenLastCalledWith({ handler: 'sirsoft-social_login.startReauth', params: { provider: 'kakao', return: window.location.pathname } });
  });

  /**
   * @scenario providers=none, disabled=true
   * @effects empty_providers_render_nothing, disabled_link_has_no_href
   */
  it('제공자가 없으면 아무것도 그리지 않고, 비활성 링크는 주소가 없다', () => {
    const { container, unmount } = render(<ProviderButtons providers={[]} />);
    expect(container.innerHTML).toBe('');
    unmount();

    const disabled = render(<ProviderButtons providers={providers.slice(0, 1)} disabled />);
    const link = disabled.container.querySelector('a') as HTMLAnchorElement;
    expect(link.hasAttribute('href')).toBe(false);
    expect(link.getAttribute('aria-disabled')).toBe('true');
  });

  /**
   * @scenario theme=light|dark
   * @effects google_dark_follows_site_theme
   */
  it('Google 버튼 다크는 사이트 다크(html.dark)만 따르고 운영체제 설정을 따르지 않는다', () => {
    const css = readFileSync(resolve(__dirname, '../../css/buttons.css'), 'utf8');

    expect(cssRule(css, '.sl-button--google')).toEqual({ 'background-color': '#ffffff', 'border-color': '#747775', color: '#1f1f1f' });
    expect(cssRule(css, '.dark .sl-button--google')).toEqual({ 'background-color': '#131314', 'border-color': '#8e918f', color: '#e3e3e3' });
    expect(css).not.toMatch(/prefers-color-scheme/);
  });
});
