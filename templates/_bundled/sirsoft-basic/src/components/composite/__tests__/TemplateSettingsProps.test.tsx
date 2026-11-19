// e2e:allow Vitest 컴포넌트 렌더 테스트(템플릿 설정 props → 실제 Header·Footer·ThemeToggle 렌더). 사용자 화면 반영은 Playwright·Chrome MCP 실측으로 별도 검증.
/**
 * @file TemplateSettingsProps.test.tsx
 * @description 템플릿 설정(#434)이 넘기는 props 가 실제 Header·Footer·ThemeToggle 출력에 반영되는지 잠근다.
 *
 * - Header: 다크 로고(어두운 화면 전용 img), 테마 단추 끄기, 메뉴 링크(언어별 이름·사이트 안 경로·새 창)
 * - Footer: 소셜 링크 7종(상표 아이콘 묶음), 「Powered by」 끄기, 운영자 저작권 문구 → 없으면 기본 문구
 * - 기본 화면 모드: 저장값이 없으면 `<html data-g7-color-scheme-default>` 를 따른다
 *
 * @vitest-environment jsdom
 *
 * 시나리오: case=settings_layout
 *
 * 효과: template_settings_exposed_to_global
 */

import { render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Header, { pickLocalizedLabel } from '../Header';
import Footer from '../Footer';
import { getDefaultTheme, initTheme } from '../../../handlers/setThemeHandler';

const navigate = vi.fn();

beforeEach(() => {
  (window as any).G7Core = {
    t: (key: string) => key,
    useResponsive: () => ({ width: 1280, breakpoint: 'desktop' }),
    dispatch: vi.fn((action: any) => {
      if (action?.handler === 'navigate') navigate(action?.params?.path);
    }),
    state: { getLocal: () => ({}), getGlobal: () => ({}) },
    getSlotContext: () => ({ getSlotComponents: () => [], subscribeToSlot: () => () => {}, isEnabled: true }),
  };
  (window as any).__slotContextValue = (window as any).G7Core.getSlotContext();
});

afterEach(() => {
  vi.clearAllMocks();
  delete (window as any).G7Core;
  delete (window as any).__slotContextValue;
  document.documentElement.removeAttribute('data-g7-color-scheme-default');
  document.documentElement.classList.remove('dark');
  localStorage.clear();
});

describe('Header — 템플릿 설정 props (#434)', () => {
  // @scenario case=settings_layout
  // @effects template_settings_exposed_to_global
  it('다크 로고가 있으면 어두운 화면 전용 img 를 더하고 사이트 로고는 어두운 화면에서 숨긴다', () => {
    const { container } = render(<Header siteName="사이트" logo="/logo.png" logoDark="/logo-dark.png" />);

    const dark = screen.getByTestId('header-logo-dark');
    expect(dark.getAttribute('src')).toBe('/logo-dark.png');
    expect(dark.className).toContain('hidden dark:block');
    expect(container.querySelector('img[src="/logo.png"]')!.className).toContain('dark:hidden');
  });

  // @scenario case=settings_layout
  // @effects template_settings_exposed_to_global
  it('다크 로고가 없으면 지금과 같다(사이트 로고만, dark:hidden 없음)', () => {
    const { container } = render(<Header siteName="사이트" logo="/logo.png" />);

    expect(screen.queryByTestId('header-logo-dark')).toBeNull();
    expect(container.querySelector('img[src="/logo.png"]')!.className).not.toContain('dark:hidden');
  });

  // @scenario case=settings_layout
  // @effects template_settings_exposed_to_global
  it('테마 단추는 끌 수 있고 기본은 보인다', () => {
    const { rerender, container } = render(<Header siteName="사이트" />);
    const count = () => container.querySelectorAll('[aria-haspopup], button').length;
    const withToggle = count();

    rerender(<Header siteName="사이트" showThemeToggle={false} />);
    expect(count()).toBeLessThan(withToggle);
  });

  // @scenario case=settings_layout
  // @effects template_settings_exposed_to_global
  it('메뉴 링크 — 현재 언어 이름, 사이트 안 경로는 SPA 이동, 새 창은 opener 를 끊고, 주소 없는 링크는 그리지 않는다', () => {
    render(
      <Header
        siteName="사이트"
        currentLocale="en"
        menu={[
          { id: 'a', kind: 'link', label: { ko: '공지', en: 'Notice' }, url: '/board/notice' },
          { id: 'b', kind: 'link', label: { ko: '외부만' }, url: 'https://example.com', new_tab: true },
          { id: 'c', kind: 'link', label: 'x', url: '' },
        ]}
      />,
    );

    const links = screen.getAllByTestId('nav-menu-link');
    expect(links).toHaveLength(2);
    expect(links[0].textContent).toBe('Notice');
    expect(links[0].getAttribute('href')).toContain('/board/notice');
    expect(links[1].textContent).toContain('외부만');
    expect(links[1].getAttribute('target')).toBe('_blank');
    expect(links[1].getAttribute('rel')).toBe('noopener noreferrer');
  });

  // @scenario case=settings_layout
  // @effects template_settings_exposed_to_global
  it('언어별 이름 고르기 — 현재 언어 → 다른 언어 첫 값 → 문자열 그대로', () => {
    expect(pickLocalizedLabel({ ko: '가', en: 'A' }, 'en')).toBe('A');
    expect(pickLocalizedLabel({ ko: '가', en: '' }, 'en')).toBe('가');
    expect(pickLocalizedLabel('그대로', 'ja')).toBe('그대로');
    expect(pickLocalizedLabel(undefined, 'ko')).toBe('');
  });
});

describe('Footer — 템플릿 설정 props (#434)', () => {
  // @scenario case=settings_layout
  // @effects template_settings_exposed_to_global
  it('소셜 링크는 값이 있는 것만, 상표 아이콘(fab) 묶음으로 새 창에 연다', () => {
    const { container } = render(<Footer siteName="사이트" socialLinks={{ youtube: 'https://youtube.com/@g7', x: 'https://x.com/g7', github: '', blog: 'https://blog.example' }} />);

    const anchors = screen.getByTestId('footer-social-links').querySelectorAll('a');
    expect(Array.from(anchors).map((a) => a.getAttribute('aria-label'))).toEqual(['YouTube', 'X', 'Blog']);
    expect(Array.from(anchors).every((a) => a.getAttribute('rel') === 'noopener noreferrer')).toBe(true);
    expect(container.querySelector('.fab.fa-youtube')).not.toBeNull();
    expect(container.querySelector('.fab.fa-x-twitter')).not.toBeNull();
    expect(container.querySelector('.fas.fa-blog')).not.toBeNull();
  });

  // @scenario case=settings_layout
  // @effects template_settings_exposed_to_global
  it('「Powered by」 는 끌 수 있고 기본은 보인다', () => {
    const { rerender } = render(<Footer siteName="사이트" />);
    expect(screen.getByTestId('footer-powered-by')).toBeTruthy();

    rerender(<Footer siteName="사이트" showPoweredBy={false} />);
    expect(screen.queryByTestId('footer-powered-by')).toBeNull();
  });

  // @scenario case=settings_layout
  // @effects template_settings_exposed_to_global
  it('저작권 — 운영자 문구가 우선, 비면 기본 문구', () => {
    const { rerender } = render(<Footer siteName="사이트" copyrightText="© 우리 회사" defaultCopyrightText="기본 문구" />);
    expect(screen.getByText('© 우리 회사')).toBeTruthy();

    rerender(<Footer siteName="사이트" copyrightText="" defaultCopyrightText="기본 문구" />);
    expect(screen.getByText('기본 문구')).toBeTruthy();
  });
});

describe('기본 화면 모드 (#434)', () => {
  // @scenario case=settings_layout
  // @effects standard_attributes_rendered
  it('저장값이 없으면 <html data-g7-color-scheme-default> 를 따른다 — system 은 auto', () => {
    expect(getDefaultTheme()).toBe('auto');

    document.documentElement.setAttribute('data-g7-color-scheme-default', 'dark');
    expect(getDefaultTheme()).toBe('dark');
    initTheme();
    expect(document.documentElement.classList.contains('dark')).toBe(true);

    document.documentElement.setAttribute('data-g7-color-scheme-default', 'system');
    expect(getDefaultTheme()).toBe('auto');
  });

  // @scenario case=settings_layout
  // @effects standard_attributes_rendered
  it('방문자가 고른 값(저장값)이 기본 모드보다 우선한다', () => {
    document.documentElement.setAttribute('data-g7-color-scheme-default', 'dark');
    localStorage.setItem('g7_color_scheme', 'light');

    initTheme();
    expect(document.documentElement.classList.contains('dark')).toBe(false);
  });
});
