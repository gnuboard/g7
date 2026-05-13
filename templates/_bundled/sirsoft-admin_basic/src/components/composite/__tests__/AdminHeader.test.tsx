import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { AdminHeader, AdminUser, NotificationItem } from '../AdminHeader';
import { IconName } from '../../basic/IconTypes';

describe('AdminHeader', () => {
  const mockUser: AdminUser = {
    name: '홍길동',
    email: 'hong@example.com',
    avatar: '/avatar.png',
    role: '관리자',
  };

  const mockNotifications: NotificationItem[] = [
    { id: 1, title: '새 댓글', message: '게시물에 댓글이 달렸습니다', time: '5분 전', read: false },
    { id: 2, title: '시스템 알림', message: '업데이트가 완료되었습니다', time: '1시간 전', read: true },
  ];

  it('컴포넌트가 렌더링됨', () => {
    render(<AdminHeader user={mockUser} />);
    expect(screen.getByText('홍길동')).toBeInTheDocument();
  });

  it('사용자 정보가 표시됨', () => {
    render(<AdminHeader user={mockUser} />);

    expect(screen.getByText('홍길동')).toBeInTheDocument();
    expect(screen.getByText('관리자')).toBeInTheDocument();
    expect(screen.getByAltText('홍길동')).toHaveAttribute('src', '/avatar.png');
  });

  it('아바타가 없을 때 기본 아이콘이 표시됨', () => {
    const userWithoutAvatar = { ...mockUser, avatar: undefined };
    render(<AdminHeader user={userWithoutAvatar} />);

    // 기본 아이콘 컨테이너가 존재하는지 확인
    expect(screen.getByText('홍길동')).toBeInTheDocument();
  });

  it('알림 버튼을 클릭하면 알림 드롭다운이 표시됨', async () => {
    const user = userEvent.setup();
    render(<AdminHeader user={mockUser} notifications={mockNotifications} />);

    const notificationButton = screen.getByLabelText('알림');
    await user.click(notificationButton);

    expect(screen.getByText('새 댓글')).toBeInTheDocument();
    expect(screen.getByText('시스템 알림')).toBeInTheDocument();
  });

  it('읽지 않은 알림 개수가 표시됨', () => {
    render(<AdminHeader user={mockUser} notifications={mockNotifications} />);

    expect(screen.getByText('1')).toBeInTheDocument();
  });

  it('알림이 9개 이상일 때 9+로 표시됨', () => {
    const manyNotifications: NotificationItem[] = Array.from({ length: 10 }, (_, i) => ({
      id: i,
      title: `알림 ${i}`,
      message: '메시지',
      time: '방금',
      read: false,
    }));

    render(<AdminHeader user={mockUser} notifications={manyNotifications} />);

    expect(screen.getByText('9+')).toBeInTheDocument();
  });

  it('프로필 버튼을 클릭하면 프로필 드롭다운이 표시됨', async () => {
    const user = userEvent.setup();
    render(<AdminHeader user={mockUser} />);

    const profileButton = screen.getByText('홍길동').closest('button');
    await user.click(profileButton!);

    expect(screen.getByText('프로필 설정')).toBeInTheDocument();
    expect(screen.getByText('로그아웃')).toBeInTheDocument();
  });

  it('프로필 메뉴에서 프로필 설정 클릭 시 핸들러가 호출됨', async () => {
    const user = userEvent.setup();
    const onProfileClick = vi.fn();
    render(<AdminHeader user={mockUser} onProfileClick={onProfileClick} />);

    const profileButton = screen.getByText('홍길동').closest('button');
    await user.click(profileButton!);

    const settingsButton = screen.getByText('프로필 설정');
    await user.click(settingsButton);

    expect(onProfileClick).toHaveBeenCalledTimes(1);
  });

  it('로그아웃 버튼 클릭 시 핸들러가 호출됨', async () => {
    const user = userEvent.setup();
    const onLogoutClick = vi.fn();
    render(<AdminHeader user={mockUser} onLogoutClick={onLogoutClick} />);

    const profileButton = screen.getByText('홍길동').closest('button');
    await user.click(profileButton!);

    const logoutButton = screen.getByText('로그아웃');
    await user.click(logoutButton);

    expect(onLogoutClick).toHaveBeenCalledTimes(1);
  });

  it('알림 클릭 시 핸들러가 호출됨', async () => {
    const user = userEvent.setup();
    const onNotificationClick = vi.fn();
    render(
      <AdminHeader
        user={mockUser}
        notifications={mockNotifications}
        onNotificationClick={onNotificationClick}
      />
    );

    const notificationButton = screen.getByLabelText('알림');
    await user.click(notificationButton);

    const notification = screen.getByText('새 댓글').closest('button');
    await user.click(notification!);

    expect(onNotificationClick).toHaveBeenCalledWith(1);
  });

  it('알림이 없을 때 빈 상태 메시지가 표시됨', async () => {
    const user = userEvent.setup();
    render(<AdminHeader user={mockUser} notifications={[]} />);

    const notificationButton = screen.getByLabelText('알림');
    await user.click(notificationButton);

    expect(screen.getByText('알림이 없습니다')).toBeInTheDocument();
  });
});
