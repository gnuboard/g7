// e2e:allow Vitest 컴포넌트 렌더 테스트(받는 중 prop → 실제 Header 출력). 새로고침 타이밍은 브라우저 실측(Playwright 응답 지연)으로 별도 검증.
/**
 * @file HeaderUserLoading.test.tsx
 * @description Header — 로그인 사용자 정보를 받는 동안 사용자 메뉴 자리에 빈 자리를 그린다.
 *
 * 회원이 새로고침하면 사용자 정보(_global.currentUser)를 받기 전 헤더가 먼저 그려진다. 받는 중을 비회원으로
 * 읽으면 회원에게 「로그인·회원가입」이 잠깐 보였다가 이름으로 바뀌고, 그 사이 오른쪽 묶음 폭이 바뀌어 검색창이
 * 출렁인다. 레이아웃이 `userLoading` 을 넘기면 헤더는 받는 동안 회색 원(아바타 크기)과 이름 막대를 그린다.
 *
 * @vitest-environment jsdom
 */

import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Header from '../Header';

beforeEach(() => {
  (window as any).G7Core = {
    t: (key: string) => ({ 'common.loading': '로딩 중...', 'auth.login': '로그인', 'auth.register_link': '회원가입' } as Record<string, string>)[key] ?? key,
    useResponsive: () => ({ width: 1280, breakpoint: 'desktop' }),
    dispatch: vi.fn(),
    state: { getLocal: () => ({}), getGlobal: () => ({}) },
    getSlotContext: () => ({ getSlotComponents: () => [], subscribeToSlot: () => () => {}, isEnabled: true }),
  };
  (window as any).__slotContextValue = (window as any).G7Core.getSlotContext();
});

afterEach(() => {
  vi.clearAllMocks();
  delete (window as any).G7Core;
  delete (window as any).__slotContextValue;
});

const links = (container: HTMLElement, href: string) => container.querySelectorAll(`a[href="${href}"]`).length;

describe('[case:components-misc-progressive-partial-arrival-exposure] Header — 사용자 정보 받는 중(userLoading)', () => {
  /**
   * @scenario viewer=guest, load_state=loading
   * @effects header_skeleton_while_user_loading
   */
  it('받는 중이면 로그인·회원가입·주문조회 대신 빈 자리(role=status)를 그린다', () => {
    const { container } = render(<Header siteName="S" userLoading />);
    const status = screen.getByRole('status');
    expect(status.getAttribute('aria-label')).toBe('로딩 중...');
    expect(links(container, '/login')).toBe(0);
    expect(links(container, '/register')).toBe(0);
    expect(links(container, '/login?redirect=/mypage/orders')).toBe(0);
    // 회색 원은 아바타(sm) 크기, 테마 대비 색
    const circle = status.querySelector('[data-skeleton="avatar"]') as HTMLElement;
    expect(circle).not.toBeNull();
    expect(circle.className).toContain('w-8');
    expect(circle.className).toContain('h-8');
    expect(circle.className).toContain('bg-gray-200');
    expect(circle.className).toContain('dark:bg-gray-700');
    expect(status.querySelector('[data-skeleton="name"]')).not.toBeNull();
  });

  /**
   * 받는 중에 사용자 정보가 이미 있어도(앞 값) 빈 자리가 이긴다 — 받는 중 판정은 레이아웃이 한다.
   *
   * @scenario viewer=member, load_state=loading
   * @effects header_skeleton_while_user_loading
   */
  it('받는 중이면 사용자 메뉴도 그리지 않는다', () => {
    render(<Header siteName="S" userLoading user={{ uuid: 'u-1', name: '홍길동' } as any} />);
    expect(screen.queryByText('홍길동')).toBeNull();
    expect(screen.getByRole('status')).toBeInTheDocument();
  });

  /**
   * @scenario viewer=guest, load_state=loaded
   * @effects header_guest_links_after_load
   */
  it('받은 뒤 비회원이면 로그인·회원가입 링크가 있다', () => {
    const { container } = render(<Header siteName="S" userLoading={false} user={[] as any} />);
    expect(links(container, '/login')).toBe(1);
    expect(links(container, '/register')).toBe(1);
    expect(screen.queryByRole('status')).toBeNull();
  });

  /**
   * @scenario viewer=member, load_state=loaded
   * @effects header_member_menu_after_load
   */
  it('받은 뒤 회원이면 아바타·이름이 있고 로그인 링크가 없다', () => {
    const { container } = render(<Header siteName="S" user={{ uuid: 'u-1', name: '홍길동' } as any} />);
    expect(screen.getAllByText('홍길동').length).toBeGreaterThan(0);
    expect(links(container, '/login')).toBe(0);
    expect(screen.queryByRole('status')).toBeNull();
  });

  /**
   * 기본값 false — 레이아웃 편집기 미리보기·옛 레이아웃(바인딩 없음)은 지금처럼 그린다.
   *
   * @scenario viewer=guest, load_state=loaded
   * @effects header_guest_links_after_load
   */
  it('userLoading 을 넘기지 않으면 기존과 같다(받은 뒤 분기)', () => {
    const { container } = render(<Header siteName="S" />);
    expect(links(container, '/login')).toBe(1);
    expect(screen.queryByRole('status')).toBeNull();
  });
});
