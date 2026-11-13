/**
 * @file DisplayNameComponents.test.tsx
 * @description 관리자 화면의 사용자 표시 컴포넌트(UserProfile, AdminHeader)가 표시 이름을 쓰는지 (#490 8단계)
 *
 * 관리자 화면의 로그인 사용자 응답은 실명(`name`)을 그대로 두고 관리자 화면 자리에서 보일 이름을 `display_name` 으로 따로 싣는다.
 * 두 컴포넌트는 `display_name ?? name ?? ''` 순서로 읽는다. 이름 항목을 쓰지 않는 사이트는 `name` 이 null 이어도
 * 터지지 않고 「null」 글자도 나오지 않아야 한다.
 *
 * @scenario-manifest tests/scenarios/admin-display-name-components.yaml
 */

import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { UserProfile, User } from '../UserProfile';
import { AdminHeader, AdminUser } from '../AdminHeader';

describe('UserProfile — 사용자 이름', () => {
  const base: User = { id: 1, name: '홍길동', email: 'hong@example.com', role: '관리자' };

  // @scenario case=user_profile_display_name
  // @effects user_profile_shows_display_name_before_real_name
  it('표시 이름이 있으면 실명이 아니라 표시 이름을 보인다(단추와 펼친 메뉴 모두)', async () => {
    const user = userEvent.setup();
    render(<UserProfile user={{ ...base, display_name: '길동이', avatar: '/a.png' }} />);

    expect(screen.getByText('길동이')).toBeInTheDocument();
    expect(screen.queryByText('홍길동')).toBeNull();
    expect(screen.getByAltText('길동이')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { expanded: false }));
    // 단추의 이름과 펼친 메뉴 머리의 이름, 두 곳 모두 표시 이름이다
    expect(screen.getAllByText('길동이')).toHaveLength(2);
    expect(screen.queryByText('홍길동')).toBeNull();
  });

  // @scenario case=user_profile_legacy_response
  // @effects user_profile_falls_back_to_real_name
  it('표시 이름이 없는 옛 응답은 실명을 보인다', () => {
    render(<UserProfile user={base} />);

    expect(screen.getByText('홍길동')).toBeInTheDocument();
  });

  // @scenario case=user_profile_null_name
  // @effects user_profile_is_null_safe
  it('이름이 null 이어도 터지지 않고 null 글자를 보이지 않는다', async () => {
    const user = userEvent.setup();
    render(<UserProfile user={{ id: 2, name: null, email: 'nick@example.com' }} />);

    await user.click(screen.getByRole('button', { expanded: false }));
    expect(screen.getByText('nick@example.com')).toBeInTheDocument();
    expect(document.body.textContent).not.toContain('null');
    expect(document.body.textContent).not.toContain('undefined');
  });
});

describe('AdminHeader — 사용자 이름', () => {
  const base: AdminUser = { name: '홍길동', email: 'hong@example.com', role: '관리자' };

  // @scenario case=admin_header_display_name
  // @effects admin_header_shows_display_name_before_real_name
  it('표시 이름이 있으면 실명이 아니라 표시 이름을 보인다', () => {
    render(<AdminHeader user={{ ...base, display_name: '길동이', avatar: '/a.png' }} />);

    expect(screen.getByText('길동이')).toBeInTheDocument();
    expect(screen.queryByText('홍길동')).toBeNull();
    expect(screen.getByAltText('길동이')).toBeInTheDocument();
  });

  // @scenario case=admin_header_legacy_response
  // @effects admin_header_falls_back_to_real_name
  it('표시 이름이 없는 옛 응답은 실명을 보인다', () => {
    render(<AdminHeader user={base} />);

    expect(screen.getByText('홍길동')).toBeInTheDocument();
  });

  // @scenario case=admin_header_null_name
  // @effects admin_header_is_null_safe
  it('이름이 null 이어도 터지지 않고 null 글자를 보이지 않는다', () => {
    render(<AdminHeader user={{ name: null, email: 'nick@example.com' }} />);

    expect(document.body.textContent).not.toContain('null');
    expect(document.body.textContent).not.toContain('undefined');
  });
});
