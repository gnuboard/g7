// e2e:allow Vitest 컴포넌트 렌더 테스트(긴 텍스트 클래스 계약). 실제 폭에서의 넘침 수치는 tests/Playwright/specs/long-text-overflow.spec.ts 와 Chrome MCP 실측이 맡는다.
/**
 * @file LongTextOverflowGuards.test.tsx
 * @description 긴 텍스트 넘침 방지 — 컴포넌트 클래스 계약 (#143)
 *
 * jsdom 은 레이아웃을 계산하지 않으므로 여기서는 긴 이름을 주입해 렌더한 뒤, 넘침을 막는 클래스가 그 텍스트를
 * 그리는 요소에 붙어 있는지만 확인한다.
 *
 * - Header(PC ≥1024px): 로고 단추는 먼저 줄어들고(min-w-24 shrink-1000) 이름은 truncate, 검색창은 32rem 기준 폭(basis-lg,
 *   flex-1 아님)을 지키며, 우측 액션 묶음도 먼저 줄어든다(shrink-1000) — 줄어드는 몫은 회원 이름(line-clamp-1 break-all
 *   max-w-32 min-w-12)만 진다. 비회원 단추 묶음은 whitespace-nowrap. 드롭다운 이름은 truncate.
 * - Footer: 사이트 이름·설명 break-words, 저작권 P(flex 줄 안) min-w-0 wrap-anywhere.
 * - HtmlContent: 평문·HTML 두 모드 루트에 wrap-anywhere(본문 긴 URL).
 * - UserInfo: 작성자 이름 단추 truncate max-w-32.
 * - MobileNav: 사이트 이름 단추 min-w-0 flex-1 + 이름 truncate, 닫기 단추 shrink-0, 사용자·게시판 이름 truncate.
 *
 * @scenario-manifest tests/scenarios/long-text-overflow.yaml
 * @vitest-environment jsdom
 */

import React from 'react';
import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Header from '../Header';
import Footer from '../Footer';
import MobileNav from '../MobileNav';
import { HtmlContent } from '../HtmlContent';
import { UserInfo } from '../UserInfo';

const LONG_NAME = 'ABCDEFGHIJKLMNOPQRSTUVWXYZABCDEFGHIJKLMNOPQRSTUVWXYZ';
const LONG_URL = `https://example.com/${'a'.repeat(80)}`;

beforeEach(() => {
  (window as any).G7Core = {
    t: (key: string) => key,
    useResponsive: () => ({ width: 1280, breakpoint: 'desktop', isMobile: false, isTablet: false, isDesktop: true }),
    dispatch: vi.fn(),
    navigate: vi.fn(),
    state: { getLocal: () => ({}), getGlobal: () => ({}) },
    getSlotContext: () => ({
      getSlotComponents: () => [],
      subscribeToSlot: () => () => {},
      isEnabled: true,
    }),
  };
  (window as any).__slotContextValue = (window as any).G7Core.getSlotContext();
});

afterEach(() => {
  vi.clearAllMocks();
  delete (window as any).G7Core;
  delete (window as any).__slotContextValue;
});

/** 요소 className 토큰 배열. */
function tokens(el: Element | null): string[] {
  expect(el).toBeTruthy();

  return (el!.getAttribute('class') ?? '').split(/\s+/);
}

describe('Header(PC) 긴 사이트 이름·사용자 이름', () => {
  /**
   * @scenario case=desktop_header_long_site_name
   * @effects site_name_single_line_ellipsis, header_right_buttons_in_viewport
   */
  it('로고 단추 min-w-24 shrink-1000(줄지 않는 flex-shrink-0 아님), 이름 truncate, 검색창 basis-lg, 우측 액션 shrink-1000', () => {
    render(<Header siteName={LONG_NAME} availableLocales={['ko', 'en']} currentLocale="ko" />);
    const name = screen.getByText(LONG_NAME);
    expect(tokens(name)).toContain('truncate');
    const logoButton = name.closest('a, button');
    expect(tokens(logoButton)).toEqual(expect.arrayContaining(['min-w-24', 'shrink-1000']));
    expect(tokens(logoButton)).not.toContain('flex-shrink-0');
    // 52자 이름에서 로고 칸이 646px 를 차지해 검색창이 57px 로 눌렸다(1280 실측) — 로고 칸 상한과 검색창 하한
    expect(tokens(logoButton)).toContain('max-w-xs');
    const bar = logoButton!.parentElement!;
    const form = bar.querySelector('form');
    // 검색창이 남는 폭만 받으면(flex-1) 이름이 길수록 좁아졌다 — 1024px 329→192·1280px 512→345 (7.1.1 실측)
    expect(tokens(form)).toEqual(expect.arrayContaining(['basis-lg', 'min-w-48', 'max-w-lg']));
    expect(tokens(form)).not.toContain('flex-1');
    const right = bar.lastElementChild;
    expect(tokens(right)).toContain('shrink-1000');
    expect(tokens(right)).not.toContain('shrink-0');
  });

  /**
   * @scenario case=desktop_header_long_site_name
   * @effects narrow_label_ellipsis_with_cap
   */
  it('비회원 단추 묶음은 whitespace-nowrap — 우측 묶음이 줄어들 때 「로그인」·「회원가입」 이 글자 단위로 접히지 않는다', () => {
    render(<Header siteName="사이트" availableLocales={['ko', 'en']} currentLocale="ko" />);
    const register = screen.getByText('auth.register_link');
    expect(tokens(register.parentElement)).toContain('whitespace-nowrap');
  });

  /**
   * @scenario case=desktop_header_long_site_name
   * @effects narrow_label_ellipsis_with_cap
   */
  it('사용자 이름 라벨 line-clamp-1 break-all max-w-32 min-w-12(최소 폭이 이름 전체가 아니다), 드롭다운 이름 truncate', () => {
    render(
      <Header
        siteName="사이트"
        user={{ uuid: 'u-1', name: LONG_NAME } as any}
        availableLocales={['ko', 'en']}
        currentLocale="ko"
      />,
    );
    const label = screen.getByText(LONG_NAME);
    // truncate(nowrap) 이면 묶음의 최소 폭이 이름 전체(최대 128px)라 이름이 길수록 검색창이 좁아졌다(1024px 426→334 모사 실측)
    expect(tokens(label)).toEqual(expect.arrayContaining(['sm:line-clamp-1', 'break-all', 'max-w-32', 'min-w-12']));
    expect(tokens(label)).not.toContain('truncate');
    expect(label.getAttribute('title')).toBe(LONG_NAME);
    fireEvent.click(label.closest('button')!);
    const names = screen.getAllByText(LONG_NAME);
    const dropdownName = names.find((n) => n !== label)!;
    expect(tokens(dropdownName)).toContain('truncate');
    expect(tokens(dropdownName.parentElement)).toContain('min-w-0');
  });
});

describe('Header(PC) 긴 게시판 이름 탭', () => {
  /**
   * @scenario case=desktop_header_long_site_name
   * @effects narrow_label_ellipsis_with_cap
   */
  it('게시판 탭은 max-w-xs, 이름은 min-w-0 truncate — 1280 에서 탭 하나가 768px 를 차지했다', () => {
    render(<Header siteName="사이트" boards={[{ id: 1, slug: 'b', name: LONG_NAME } as any]} availableLocales={['ko', 'en']} currentLocale="ko" />);
    const label = screen.getAllByText(LONG_NAME)[0];
    expect(tokens(label)).toEqual(expect.arrayContaining(['min-w-0', 'truncate']));
    expect(tokens(label.closest('a, button'))).toContain('max-w-xs');
  });
});

describe('로고 이미지 비율', () => {
  /**
   * @scenario case=desktop_header_long_site_name
   * @scenario case=mobile_nav_long_names
   * @effects header_right_buttons_in_viewport
   */
  it('PC 헤더·서랍 로고 img 는 max-w-full object-contain object-left — 칸이 줄 때 가로만 눌려 찌그러지지 않는다', () => {
    const { unmount } = render(<Header siteName="사이트" logo="/wide-logo.png" availableLocales={['ko', 'en']} currentLocale="ko" />);
    expect(tokens(screen.getByAltText('사이트'))).toEqual(expect.arrayContaining(['max-w-full', 'object-contain', 'object-left']));
    unmount();
    (window as any).G7Core.useResponsive = () => ({ width: 297, breakpoint: 'mobile', isMobile: true, isTablet: false, isDesktop: false });
    render(<MobileNav isOpen onClose={() => {}} siteName="사이트" logo="/wide-logo.png" />);
    expect(tokens(screen.getByAltText('사이트'))).toEqual(expect.arrayContaining(['max-w-full', 'object-contain', 'object-left']));
  });
});

describe('Footer 긴 사이트 이름', () => {
  /**
   * @scenario case=footer_long_site_name
   * @effects block_text_wraps_within_box, flex_text_shrinks_and_wraps
   */
  it('사이트 이름·설명 break-words, 저작권 P min-w-0 wrap-anywhere', () => {
    render(<Footer siteName={LONG_NAME} siteDescription={LONG_URL} />);
    const h3 = screen.getByText(LONG_NAME, { selector: 'h3' });
    expect(tokens(h3)).toContain('break-words');
    expect(tokens(screen.getByText(LONG_URL))).toContain('break-words');
    const copyright = screen.getByText((text) => text.includes(`${LONG_NAME}. All rights reserved.`));
    expect(tokens(copyright)).toEqual(expect.arrayContaining(['min-w-0', 'wrap-anywhere']));
  });
});

describe('HtmlContent 긴 URL', () => {
  /**
   * @scenario case=html_content_long_url
   * @effects flex_text_shrinks_and_wraps
   */
  it('평문 모드 루트 wrap-anywhere', () => {
    const { container } = render(<HtmlContent content={LONG_URL} isHtml={false} />);
    expect(tokens(container.firstElementChild)).toContain('wrap-anywhere');
  });

  /**
   * @scenario case=html_content_long_url
   * @effects flex_text_shrinks_and_wraps
   */
  it('HTML(prose) 모드 루트 wrap-anywhere', () => {
    const { container } = render(<HtmlContent content={`<p>${LONG_URL}</p>`} isHtml />);
    expect(tokens(container.firstElementChild)).toContain('wrap-anywhere');
  });
});

describe('UserInfo 긴 작성자 이름', () => {
  /**
   * @scenario case=user_info_long_name
   * @effects narrow_label_ellipsis_with_cap
   */
  it('이름 단추 truncate max-w-32', () => {
    render(<UserInfo name={LONG_NAME} userId="u-1" />);
    expect(tokens(screen.getByText(LONG_NAME).closest('button'))).toEqual(expect.arrayContaining(['truncate', 'max-w-32']));
  });

  /**
   * 320px 인기글에서 비회원 작성자 「관리자」·배지 「비회원」 이 「관리/자」「비회/원」 으로 글자 단위로 접혔다(7.1.1) —
   * 비회원 이름에는 줄임 클래스가 없고 배지는 줄어들었다.
   *
   * @scenario case=user_info_long_name
   * @effects narrow_label_ellipsis_with_cap
   */
  it('비회원 이름 min-w-0 truncate max-w-32, 「비회원」 배지 shrink-0 whitespace-nowrap, 감싼 줄 min-w-0', () => {
    render(<UserInfo name={LONG_NAME} isGuest />);
    const name = screen.getByText(LONG_NAME);
    expect(tokens(name)).toEqual(expect.arrayContaining(['min-w-0', 'truncate', 'max-w-32']));
    expect(tokens(name.parentElement)).toContain('min-w-0');
    const badge = name.nextElementSibling;
    expect(tokens(badge)).toEqual(expect.arrayContaining(['shrink-0', 'whitespace-nowrap']));
  });
});

describe('MobileNav 긴 이름', () => {
  /**
   * @scenario case=mobile_nav_long_names
   * @effects site_name_single_line_ellipsis, narrow_label_ellipsis_with_cap
   */
  it('사이트 이름 단추 min-w-0 flex-1 + 이름 truncate, 닫기 shrink-0, 사용자·게시판 이름 truncate', () => {
    // 드로어는 1024px 미만에서만 그려진다
    (window as any).G7Core.useResponsive = () => ({ width: 297, breakpoint: 'mobile', isMobile: true, isTablet: false, isDesktop: false });
    render(
      <MobileNav
        isOpen
        onClose={() => {}}
        siteName={LONG_NAME}
        user={{ uuid: 'u-1', name: `${LONG_NAME}-user` } as any}
        boards={[{ id: 1, slug: 'free', name: `${LONG_NAME}-board` } as any]}
      />,
    );
    const site = screen.getByText(LONG_NAME);
    expect(tokens(site)).toContain('truncate');
    const siteButton = site.closest('a, button')!;
    expect(tokens(siteButton)).toEqual(expect.arrayContaining(['min-w-0', 'flex-1']));
    expect(tokens(siteButton.nextElementSibling)).toContain('shrink-0');
    const userName = screen.getByText(`${LONG_NAME}-user`);
    expect(tokens(userName)).toContain('truncate');
    expect(tokens(userName.parentElement)).toContain('min-w-0');
    expect(tokens(screen.getByText(`${LONG_NAME}-board`))).toEqual(expect.arrayContaining(['min-w-0', 'truncate']));
  });
});
