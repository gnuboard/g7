/**
 * @file DisplayNameComponents.test.tsx
 * @description 헤더, 모바일 메뉴, 작성자 표시 컴포넌트가 표시 이름을 쓰는지 (#490 8단계)
 *
 * 로그인 사용자 응답은 실명(`name`)을 그대로 두고 그 화면에서 보일 이름을 `display_name` 으로 따로 싣는다.
 * 헤더(Header)와 모바일 메뉴(MobileNav)는 `display_name ?? name ?? ''` 순서로 읽는다. 이름 항목을 쓰지 않는 사이트는
 * `name` 이 null 이어도 터지지 않고 「null」 글자도 나오지 않아야 한다. 작성자 표시(UserInfo)는 서버가 준 이름을 그대로 쓰되
 * null 이면 다음 값으로 넘어간다.
 *
 * 실제 컴포넌트를 렌더한다(가짜 컴포넌트가 아니다).
 *
 * @scenario-manifest tests/scenarios/display-name-components.yaml
 * @vitest-environment jsdom
 */

import { render, screen } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import Header from '../Header';
import MobileNav from '../MobileNav';
import { UserInfo } from '../UserInfo';

/** 화면 폭을 바꿔 가며 쓰는 G7Core 대역 */
function installCore(width: number): void {
  (window as any).G7Core = {
    t: (key: string) => key,
    useResponsive: () => ({ width, breakpoint: width >= 1024 ? 'desktop' : 'mobile' }),
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
}

beforeEach(() => installCore(1280));

afterEach(() => {
  vi.clearAllMocks();
  delete (window as any).G7Core;
  delete (window as any).__slotContextValue;
});

describe('Header — 사용자 메뉴의 이름', () => {
  const renderHeader = (user: Record<string, unknown>) =>
    render(<Header siteName="S" user={user as any} availableLocales={['ko', 'en']} currentLocale="ko" />);

  // @scenario case=header_display_name
  // @effects header_shows_display_name_before_real_name
  it('표시 이름이 있으면 실명이 아니라 표시 이름을 보인다', () => {
    renderHeader({ uuid: 'u-1', name: '홍길동', nickname: '길동이', display_name: '길동이' });

    const name = screen.getByTestId('header-user-name');
    expect(name.textContent).toBe('길동이');
    expect(name.getAttribute('title')).toBe('길동이');
    expect(screen.queryByText('홍길동')).toBeNull();
  });

  // @scenario case=header_legacy_response
  // @effects header_falls_back_to_real_name
  it('표시 이름이 없는 옛 응답은 실명을 보인다', () => {
    renderHeader({ uuid: 'u-2', name: '홍길동' });

    expect(screen.getByTestId('header-user-name').textContent).toBe('홍길동');
  });

  // @scenario case=header_null_name
  // @effects header_is_null_safe
  it('이름이 null 이어도 터지지 않고 null 글자를 보이지 않는다', () => {
    renderHeader({ uuid: 'u-3', name: null, display_name: 'gil***' });
    expect(screen.getByTestId('header-user-name').textContent).toBe('gil***');
  });

  // @scenario case=header_null_name
  // @effects header_is_null_safe
  it('표시 이름도 실명도 없으면 빈 글자다', () => {
    renderHeader({ uuid: 'u-4', name: null });

    const name = screen.getByTestId('header-user-name');
    expect(name.textContent).toBe('');
    expect(document.body.textContent).not.toContain('null');
    expect(document.body.textContent).not.toContain('undefined');
  });
});

describe('MobileNav — 드로어의 사용자 이름', () => {
  const renderNav = (user: Record<string, unknown>) => {
    installCore(390);
    return render(<MobileNav isOpen onClose={vi.fn()} user={user as any} />);
  };

  // @scenario case=mobile_nav_display_name
  // @effects mobile_nav_shows_display_name_before_real_name
  it('표시 이름을 먼저 보이고, 아바타 대체 글자도 표시 이름의 첫 글자다', () => {
    renderNav({ id: 1, name: '홍길동', display_name: '길동이' });

    expect(screen.getByText('길동이')).toBeInTheDocument();
    expect(screen.queryByText('홍길동')).toBeNull();
    // 아바타가 없으면 이름 첫 글자를 원 안에 보인다
    expect(screen.getByText('길')).toBeInTheDocument();
  });

  // @scenario case=mobile_nav_legacy_response
  // @effects mobile_nav_falls_back_to_real_name
  it('표시 이름이 없는 옛 응답은 실명을 보인다', () => {
    renderNav({ id: 2, name: '홍길동' });

    expect(screen.getByText('홍길동')).toBeInTheDocument();
  });

  // @scenario case=mobile_nav_null_name
  // @effects mobile_nav_is_null_safe
  it('이름이 null 이면 대체 글자 U 를 보이고 null 글자를 보이지 않는다', () => {
    renderNav({ id: 3, name: null });

    expect(screen.getByText('U')).toBeInTheDocument();
    expect(document.body.textContent).not.toContain('null');
  });

  // @scenario case=mobile_nav_display_name
  // @effects mobile_nav_shows_display_name_before_real_name
  it('아바타 그림의 대체 글도 표시 이름이다', () => {
    renderNav({ id: 4, name: '홍길동', display_name: '길동이', avatar: '/a.png' });

    expect(screen.getByAltText('길동이')).toBeInTheDocument();
  });
});

describe('UserInfo — 작성자 이름', () => {
  // @scenario case=user_info_display_name
  // @effects user_info_shows_server_given_name
  it('서버가 준 작성자 이름(그 자리의 표시 이름)을 그대로 보인다', () => {
    render(<UserInfo author={{ uuid: 'u-1', name: '길동이' }} showDropdown={false} />);

    expect(screen.getByText('길동이')).toBeInTheDocument();
  });

  // @scenario case=user_info_null_name
  // @effects user_info_is_null_safe
  it('개별 이름이 null 이면 작성자 객체의 이름으로 넘어가고, 둘 다 없으면 빈 글자다', () => {
    const { unmount } = render(<UserInfo name={null} author={{ uuid: 'u-1', name: '길동이' }} showDropdown={false} />);
    expect(screen.getByText('길동이')).toBeInTheDocument();
    unmount();

    render(<UserInfo author={{ uuid: 'u-2', name: null }} showDropdown={false} />);
    expect(document.body.textContent).not.toContain('null');
    expect(document.body.textContent).not.toContain('undefined');
  });
});
