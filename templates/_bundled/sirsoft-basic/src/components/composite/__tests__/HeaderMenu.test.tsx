// e2e:allow Vitest 컴포넌트 렌더 테스트(메뉴 props → 실제 Header 렌더). 사용자 화면 반영은 Playwright E2E·브라우저 실측으로 별도 검증.
/**
 * Header — 템플릿 설정 `header.menu` 렌더 (#434)
 *
 * - 메뉴 순서대로 탭을 그린다. 자동 항목(홈·인기글·쇼핑·게시판 전체)은 그 자리에서 펴고, 모듈이 꺼져 있으면 그리지 않는다.
 * - 하위 메뉴가 있는 링크는 펼침 단추 — 누르면 열리고(aria-expanded) Esc·바깥 누르기로 닫힌다. 상위 주소가 있으면 펼침 첫 줄.
 * - 게시판 전체: 상위에서는 「게시판 메뉴 개수」만큼 + 더보기, 하위에서는 펼침 안에 전부(더보기 없음).
 */

import React from 'react';
import { fireEvent, render, screen } from '@testing-library/react';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import Header, { DEFAULT_HEADER_MENU, HeaderMenuItem } from '../Header';

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
});

const boards = Array.from({ length: 7 }, (_, i) => ({ id: i + 1, name: `게시판${i + 1}`, slug: `b${i + 1}` }));
const link = (id: string, extra: Partial<HeaderMenuItem> = {}): HeaderMenuItem => ({ id, kind: 'link', label: { ko: id }, url: `/${id}`, ...extra });

describe('Header 메뉴 (#434)', () => {
  // @scenario case=header_menu
  // @effects header_menu_renders_tree
  it('메뉴가 없으면 기본 메뉴 순서(홈 → 인기글 → 쇼핑 → 게시판) — 지금 헤더와 같다', () => {
    render(<Header siteName="사이트" boards={boards} maxVisibleBoards={3} />);

    const nav = screen.getByTestId('header-menu-nav');
    const texts = Array.from(nav.querySelectorAll('a, button')).map((el) => el.textContent?.trim());

    expect(texts.slice(0, 6)).toEqual(['nav.home', '🔥nav.popular', '🛒nav.shop', '게시판1', '게시판2', '게시판3']);
    expect(texts).toContain('nav.more');
    expect(DEFAULT_HEADER_MENU.map((item) => item.kind)).toEqual(['home', 'popular', 'shop', 'boards']);
  });

  // @scenario case=header_menu
  // @effects header_menu_renders_tree
  it('운영자가 정한 순서와 자동 항목 이름 바꾸기를 따른다', () => {
    render(
      <Header
        siteName="사이트"
        currentLocale="ko"
        boards={boards.slice(0, 2)}
        menu={[link('notice'), { id: 'boards', kind: 'boards' }, { id: 'home', kind: 'home', label: { ko: '처음' } }]}
      />,
    );

    const texts = Array.from(screen.getByTestId('header-menu-nav').querySelectorAll('a, button')).map((el) => el.textContent?.trim());

    expect(texts).toEqual(['notice', '게시판1', '게시판2', '처음']);
  });

  // @scenario case=header_menu
  // @effects header_menu_renders_tree
  it('모듈이 꺼지면 그 자동 항목을 그리지 않는다', () => {
    render(<Header siteName="사이트" boards={boards} boardEnabled={false} shopEnabled={false} menu={DEFAULT_HEADER_MENU} />);

    expect(screen.queryByTestId('nav-popular')).toBeNull();
    expect(screen.queryByTestId('nav-shop')).toBeNull();
    expect(screen.queryByText('게시판1')).toBeNull();
    expect(screen.getByTestId('nav-home')).toBeTruthy();
  });

  // @scenario case=header_menu
  // @effects header_menu_renders_tree
  it('하위 메뉴가 있는 링크는 펼침 단추 — 누르면 열리고 Esc 로 닫힌다. 상위 주소가 있으면 펼침 첫 줄이다', () => {
    render(
      <Header
        siteName="사이트"
        currentLocale="ko"
        menu={[link('community', { url: '/community', children: [link('free'), link('qna', { url: 'https://qna.example.com', new_tab: true })] })]}
      />,
    );

    const parent = screen.getByTestId('nav-menu-parent');
    expect(parent.getAttribute('aria-expanded')).toBe('false');
    expect(screen.queryByTestId('nav-menu-dropdown')).toBeNull();

    fireEvent.click(parent);
    expect(parent.getAttribute('aria-expanded')).toBe('true');

    const dropdown = screen.getByTestId('nav-menu-dropdown');
    const links = Array.from(dropdown.querySelectorAll('a'));
    expect(links.map((a) => a.textContent?.trim())).toEqual(['community', 'free', 'qna']);
    expect(links[2].getAttribute('target')).toBe('_blank');
    expect(links[2].getAttribute('rel')).toBe('noopener noreferrer');

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByTestId('nav-menu-dropdown')).toBeNull();
  });

  // @scenario case=header_menu
  // @effects header_menu_renders_tree
  it('펼침 메뉴 안의 사이트 안 링크를 누르면 SPA 로 이동하고 펼침이 닫힌다', () => {
    render(<Header siteName="사이트" currentLocale="ko" menu={[link('community', { url: '', children: [link('free')] })]} />);

    fireEvent.click(screen.getByTestId('nav-menu-parent'));
    fireEvent.click(screen.getByText('free'));

    expect(navigate).toHaveBeenCalledWith('/free');
    expect(screen.queryByTestId('nav-menu-dropdown')).toBeNull();
  });

  // @scenario case=header_menu
  // @effects header_menu_renders_tree
  it('게시판 전체를 하위 메뉴에 두면 펼침 안에 전부 나오고 더보기는 없다', () => {
    render(
      <Header
        siteName="사이트"
        currentLocale="ko"
        boards={boards}
        maxVisibleBoards={3}
        menu={[link('community', { url: '', children: [{ id: 'boards', kind: 'boards' }] })]}
      />,
    );

    expect(screen.queryByText('nav.more')).toBeNull();
    expect(screen.queryByText('게시판1')).toBeNull();

    fireEvent.click(screen.getByTestId('nav-menu-parent'));
    const dropdown = screen.getByTestId('nav-menu-dropdown');

    expect(dropdown.querySelectorAll('a')).toHaveLength(7);
  });
});
