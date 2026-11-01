// e2e:allow Vitest 컴포넌트 렌더 테스트(Header·Footer 모듈 활성 props). 실제 조합별 화면은 총괄 Chrome MCP 매트릭스(#822 §11)에서 측정한다.
/**
 * @file HeaderFooterModuleFlags.test.tsx
 * @description 데스크톱 Header·Footer 가 모듈 활성 props 로 꺼진 모듈의 링크를 숨기는지 검증 (#822).
 *
 * 레이아웃(_user_base)은 `_global.activeModules` 로 판정한 값을 boardEnabled·shopEnabled·searchEnabled(Header),
 * boardEnabled·pageEnabled(Footer) 로 넘긴다. 값이 오지 않으면(루트 레이아웃을 keep 으로 업데이트해 새 바인딩이
 * 없는 사이트) 기본값 true 로 지금처럼 보인다. Footer 에 linkGroups 를 직접 넘기면 플래그와 무관하게 그대로 그린다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import { render, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';

vi.mock('../../src/components/composite/NotificationCenter', () => ({
  NotificationCenter: () => <div data-testid="notification-center-mock" />,
}));

import Header from '../../src/components/composite/Header';
import Footer from '../../src/components/composite/Footer';

beforeEach(() => {
  (window as any).G7Core = {
    t: (key: string) => key,
    useResponsive: () => ({ width: 1280, breakpoint: 'desktop' }),
    dispatch: vi.fn(),
    state: { getLocal: () => ({}), getGlobal: () => ({}), get: () => ({}) },
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

const boards = [
  { id: 1, name: '자유게시판', slug: 'free' },
  { id: 2, name: '질문답변', slug: 'qna' },
];
const member = { uuid: 'u-1', name: '회원' } as any;

/** 문서에서 href 로 링크 수를 센다. */
const countHref = (container: HTMLElement, href: string) => container.querySelectorAll(`a[href="${href}"]`).length;

describe('Header — 모듈 활성 props (#822)', () => {
  // @scenario surface=header_footer_component
  // @effects header_footer_module_props
  it('props 를 넘기지 않으면(옛 레이아웃) 인기글·쇼핑·장바구니·검색칸·게시판 탭·비회원 주문조회를 모두 그린다', () => {
    const { container, getByTestId } = render(<Header boards={boards} shopBase="/shop" />);

    expect(getByTestId('nav-popular')).toBeTruthy();
    expect(getByTestId('nav-shop')).toBeTruthy();
    expect(getByTestId('header-cart')).toBeTruthy();
    expect(getByTestId('header-search')).toBeTruthy();
    expect(countHref(container, '/board/free')).toBe(1);
    expect(countHref(container, '/login?redirect=/mypage/orders')).toBe(1);
  });

  // @scenario surface=header_footer_component
  // @effects header_footer_module_props
  it('boardEnabled=false 면 인기글 탭과 게시판 탭이 0 이다(목록이 와도)', () => {
    const { container, queryByTestId } = render(<Header boards={boards} boardEnabled={false} />);

    expect(queryByTestId('nav-popular')).toBeNull();
    expect(countHref(container, '/board/free')).toBe(0);
    expect(countHref(container, '/board/qna')).toBe(0);
    expect(queryByTestId('nav-shop')).toBeTruthy();
  });

  // @scenario surface=header_footer_component
  // @effects header_footer_module_props
  it('shopEnabled=false 면 쇼핑 탭·장바구니·비회원 주문조회가 0 이다', () => {
    const { container, queryByTestId } = render(<Header boards={boards} shopEnabled={false} />);

    expect(queryByTestId('nav-shop')).toBeNull();
    expect(queryByTestId('header-cart')).toBeNull();
    expect(countHref(container, '/login?redirect=/mypage/orders')).toBe(0);
    expect(queryByTestId('nav-popular')).toBeTruthy();
  });

  // @scenario surface=header_footer_component
  // @effects header_footer_module_props
  it('shopEnabled=false 면 회원 메뉴의 주문내역·위시리스트가 0 이고 마이페이지는 남는다', () => {
    const { container, getAllByRole } = render(<Header user={member} shopEnabled={false} />);
    const trigger = getAllByRole('button').find((button) => button.textContent?.includes('회원'))!;
    fireEvent.click(trigger);

    expect(countHref(container, '/mypage')).toBe(1);
    expect(countHref(container, '/mypage/orders')).toBe(0);
    expect(countHref(container, '/mypage/wishlist')).toBe(0);
  });

  // @scenario surface=header_footer_component
  // @effects header_footer_module_props
  it('회원 메뉴의 주문내역·위시리스트는 기본값에서 그대로 보인다', () => {
    const { container, getAllByRole } = render(<Header user={member} />);
    const trigger = getAllByRole('button').find((button) => button.textContent?.includes('회원'))!;
    fireEvent.click(trigger);

    expect(countHref(container, '/mypage/orders')).toBe(1);
    expect(countHref(container, '/mypage/wishlist')).toBe(1);
  });

  // @scenario surface=header_footer_component
  // @effects header_footer_module_props
  it('searchEnabled=false 면 검색칸이 0 이다', () => {
    const { queryByTestId } = render(<Header searchEnabled={false} />);

    expect(queryByTestId('header-search')).toBeNull();
  });
});

describe('Footer — 모듈 활성 props (#822)', () => {
  // @scenario surface=header_footer_component
  // @effects header_footer_module_props
  it('props 를 넘기지 않으면 커뮤니티·정보·정책 세 묶음을 그린다', () => {
    const { container } = render(<Footer siteName="사이트" />);

    expect(container.querySelectorAll('h4')).toHaveLength(3);
    expect(countHref(container, '/boards')).toBe(1);
    expect(container.querySelectorAll('a[href^="/page/"]')).toHaveLength(6);
  });

  // @scenario surface=header_footer_component
  // @effects header_footer_module_props
  it('boardEnabled=false 면 커뮤니티 묶음(게시판 링크)이 0 이다', () => {
    const { container } = render(<Footer siteName="사이트" boardEnabled={false} />);

    expect(countHref(container, '/boards')).toBe(0);
    expect(countHref(container, '/boards/popular')).toBe(0);
    expect(container.querySelectorAll('a[href^="/page/"]')).toHaveLength(6);
  });

  // @scenario surface=header_footer_component
  // @effects header_footer_module_props
  it('pageEnabled=false 면 정보·정책 링크가 0 이다', () => {
    const { container } = render(<Footer siteName="사이트" pageEnabled={false} />);

    expect(container.querySelectorAll('a[href^="/page/"]')).toHaveLength(0);
    expect(countHref(container, '/boards')).toBe(1);
  });

  // @scenario surface=header_footer_component
  // @effects header_footer_module_props
  it('linkGroups 를 직접 넘기면 플래그와 무관하게 그대로 그린다', () => {
    const linkGroups = [{ title: '안내', links: [{ label: '약관', href: '/page/terms' }, { label: '게시판', href: '/boards' }] }];
    const { container } = render(<Footer siteName="사이트" linkGroups={linkGroups} boardEnabled={false} pageEnabled={false} />);

    expect(countHref(container, '/page/terms')).toBe(1);
    expect(countHref(container, '/boards')).toBe(1);
  });
});
