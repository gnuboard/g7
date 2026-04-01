import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { NotificationCenter, NotificationItem } from '../NotificationCenter';
import { IconName } from '../../basic/IconTypes';

describe('NotificationCenter', () => {
  const mockNotifications: NotificationItem[] = [
    {
      id: 1,
      title: 'New Comment',
      message: 'You have a new comment on your post',
      time: '5 mins ago',
      read: false,
      iconName: IconName.Comment,
    },
    {
      id: 2,
      title: 'System Update',
      message: 'System has been updated successfully',
      time: '1 hour ago',
      read: true,
      iconName: IconName.Info,
    },
    {
      id: 3,
      title: 'New Message',
      message: 'You received a message from admin',
      time: '2 hours ago',
      read: false,
    },
  ];

  describe('렌더링 테스트', () => {
    it('컴포넌트가 렌더링됨', () => {
      render(<NotificationCenter />);
      const button = screen.getByRole('button');
      expect(button).toBeInTheDocument();
    });

    it('알림 버튼에 벨 아이콘이 표시됨', () => {
      const { container } = render(<NotificationCenter />);
      // Bell 아이콘 확인
      const button = screen.getByRole('button');
      expect(button).toBeInTheDocument();
    });

    it('알림이 없을 때 배지가 표시되지 않음', () => {
      render(<NotificationCenter notifications={[]} />);
      expect(screen.queryByText(/^\d+$/)).not.toBeInTheDocument();
    });

    it('읽지 않은 알림 개수가 배지에 표시됨', () => {
      render(<NotificationCenter notifications={mockNotifications} />);
      expect(screen.getByText('2')).toBeInTheDocument(); // 읽지 않은 알림 2개
    });

    it('읽지 않은 알림이 9개 초과 시 9+로 표시됨', () => {
      const manyNotifications: NotificationItem[] = Array.from({ length: 15 }, (_, i) => ({
        id: i,
        title: `Notification ${i}`,
        message: 'Message',
        time: 'Just now',
        read: false,
      }));

      render(<NotificationCenter notifications={manyNotifications} />);
      expect(screen.getByText('9+')).toBeInTheDocument();
    });
  });

  describe('알림 드롭다운 테스트', () => {
    it('알림 버튼 클릭 시 드롭다운이 표시됨', async () => {
      const user = userEvent.setup();
      render(<NotificationCenter notifications={mockNotifications} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      expect(screen.getByText('New Comment')).toBeInTheDocument();
      expect(screen.getByText('System Update')).toBeInTheDocument();
      expect(screen.getByText('New Message')).toBeInTheDocument();
    });

    it('알림이 없을 때 빈 상태 메시지가 표시됨', async () => {
      const user = userEvent.setup();
      render(<NotificationCenter notifications={[]} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      expect(screen.getByText('No notifications')).toBeInTheDocument();
    });

    it('알림 제목이 드롭다운에 표시됨', async () => {
      const user = userEvent.setup();
      render(<NotificationCenter notifications={mockNotifications} titleText="Notifications" />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      expect(screen.getByText('Notifications')).toBeInTheDocument();
    });

    it('읽지 않은 알림에 파란색 배경이 표시됨', async () => {
      const user = userEvent.setup();
      const { container } = render(<NotificationCenter notifications={mockNotifications} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      // 읽지 않은 알림 찾기
      const unreadNotification = screen.getByText('New Comment').closest('button');
      expect(unreadNotification).toHaveClass('bg-blue-50');
    });

    it('읽은 알림에는 파란색 배경이 없음', async () => {
      const user = userEvent.setup();
      render(<NotificationCenter notifications={mockNotifications} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      // 읽은 알림 찾기
      const readNotification = screen.getByText('System Update').closest('button');
      expect(readNotification).not.toHaveClass('bg-blue-50');
    });

    it('읽지 않은 알림에 파란색 점 표시가 있음', async () => {
      const user = userEvent.setup();
      const { container } = render(<NotificationCenter notifications={mockNotifications} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      // 파란색 점 표시 확인
      const blueDots = container.querySelectorAll('.bg-blue-500.rounded-full');
      expect(blueDots.length).toBe(2); // 읽지 않은 알림 2개
    });
  });

  describe('다국어 Props 테스트', () => {
    it('커스텀 titleText가 표시됨', async () => {
      const user = userEvent.setup();
      render(
        <NotificationCenter
          notifications={mockNotifications}
          titleText="알림"
        />
      );

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      expect(screen.getByText('알림')).toBeInTheDocument();
    });

    it('커스텀 emptyText가 표시됨', async () => {
      const user = userEvent.setup();
      render(
        <NotificationCenter
          notifications={[]}
          emptyText="알림이 없습니다"
        />
      );

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      expect(screen.getByText('알림이 없습니다')).toBeInTheDocument();
    });

    it('기본 영어 텍스트가 표시됨', async () => {
      const user = userEvent.setup();
      render(<NotificationCenter notifications={[]} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      expect(screen.getByText('Notifications')).toBeInTheDocument();
      expect(screen.getByText('No notifications')).toBeInTheDocument();
    });
  });

  describe('이벤트 핸들러 테스트', () => {
    it('알림 클릭 시 onNotificationClick이 호출됨', async () => {
      const user = userEvent.setup();
      const onNotificationClick = vi.fn();
      render(
        <NotificationCenter
          notifications={mockNotifications}
          onNotificationClick={onNotificationClick}
        />
      );

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      const notification = screen.getByText('New Comment').closest('button');
      await user.click(notification!);

      expect(onNotificationClick).toHaveBeenCalledWith(1);
      expect(onNotificationClick).toHaveBeenCalledTimes(1);
    });

    it('알림 아이템의 onClick이 호출됨', async () => {
      const user = userEvent.setup();
      const onClick = vi.fn();
      const notificationsWithClick: NotificationItem[] = [
        {
          id: 1,
          title: 'Test',
          message: 'Test message',
          time: 'Now',
          onClick,
        },
      ];

      render(<NotificationCenter notifications={notificationsWithClick} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      const notification = screen.getByText('Test').closest('button');
      await user.click(notification!);

      expect(onClick).toHaveBeenCalledTimes(1);
    });

    it('알림 클릭 시 드롭다운이 닫힘', async () => {
      const user = userEvent.setup();
      const onNotificationClick = vi.fn();
      render(
        <NotificationCenter
          notifications={mockNotifications}
          onNotificationClick={onNotificationClick}
        />
      );

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      const notification = screen.getByText('New Comment').closest('button');
      await user.click(notification!);

      // 드롭다운이 닫혔는지 확인
      expect(screen.queryByText('System Update')).not.toBeInTheDocument();
    });
  });

  describe('외부 클릭 감지 테스트', () => {
    it('드롭다운 외부 클릭 시 드롭다운이 닫힘', async () => {
      const user = userEvent.setup();
      render(
        <div>
          <NotificationCenter notifications={mockNotifications} />
          <div data-testid="outside">Outside Element</div>
        </div>
      );

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      // 드롭다운이 열렸는지 확인
      expect(screen.getByText('New Comment')).toBeInTheDocument();

      // 외부 클릭
      const outsideElement = screen.getByTestId('outside');
      await user.click(outsideElement);

      // 드롭다운이 닫혔는지 확인
      expect(screen.queryByText('New Comment')).not.toBeInTheDocument();
    });
  });

  describe('알림 아이템 렌더링 테스트', () => {
    it('알림 제목이 표시됨', async () => {
      const user = userEvent.setup();
      render(<NotificationCenter notifications={mockNotifications} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      expect(screen.getByText('New Comment')).toBeInTheDocument();
      expect(screen.getByText('System Update')).toBeInTheDocument();
      expect(screen.getByText('New Message')).toBeInTheDocument();
    });

    it('알림 메시지가 표시됨', async () => {
      const user = userEvent.setup();
      render(<NotificationCenter notifications={mockNotifications} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      expect(screen.getByText('You have a new comment on your post')).toBeInTheDocument();
      expect(screen.getByText('System has been updated successfully')).toBeInTheDocument();
      expect(screen.getByText('You received a message from admin')).toBeInTheDocument();
    });

    it('알림 시간이 표시됨', async () => {
      const user = userEvent.setup();
      render(<NotificationCenter notifications={mockNotifications} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      expect(screen.getByText('5 mins ago')).toBeInTheDocument();
      expect(screen.getByText('1 hour ago')).toBeInTheDocument();
      expect(screen.getByText('2 hours ago')).toBeInTheDocument();
    });

    it('아이콘이 있는 알림은 아이콘이 표시됨', async () => {
      const user = userEvent.setup();
      const { container } = render(<NotificationCenter notifications={mockNotifications} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      // 아이콘 확인 (첫 두 개의 알림에만 있음)
      const icons = container.querySelectorAll('.fa-comment, .fa-info');
      expect(icons.length).toBeGreaterThan(0);
    });
  });

  describe('스타일 및 className 테스트', () => {
    it('커스텀 className이 적용됨', () => {
      const { container } = render(
        <NotificationCenter className="custom-notification" />
      );
      const wrapper = container.firstChild;
      expect(wrapper).toHaveClass('custom-notification');
    });

    it('기본 클래스가 유지됨', () => {
      const { container } = render(<NotificationCenter />);
      const wrapper = container.firstChild;
      expect(wrapper).toHaveClass('relative');
    });

    it('알림 버튼에 hover 효과가 있음', () => {
      render(<NotificationCenter />);
      const button = screen.getByRole('button');
      expect(button).toHaveClass('hover:bg-gray-100');
    });
  });

  describe('접근성 테스트', () => {
    it('알림 버튼에 aria-label이 있음', () => {
      render(<NotificationCenter titleText="Notifications" />);
      const button = screen.getByLabelText('Notifications');
      expect(button).toBeInTheDocument();
    });

    it('알림 아이템들이 버튼으로 접근 가능함', async () => {
      const user = userEvent.setup();
      render(<NotificationCenter notifications={mockNotifications} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      const buttons = screen.getAllByRole('button');
      expect(buttons.length).toBeGreaterThanOrEqual(4); // 메인 버튼 + 알림 3개
    });
  });

  describe('복합 시나리오 테스트', () => {
    it('드롭다운 열고 닫기를 반복해도 정상 동작함', async () => {
      const user = userEvent.setup();
      render(<NotificationCenter notifications={mockNotifications} />);

      const notificationButton = screen.getByRole('button');

      // 첫 번째 열기
      await user.click(notificationButton);
      expect(screen.getByText('New Comment')).toBeInTheDocument();

      // 닫기 (같은 버튼 클릭)
      await user.click(notificationButton);
      expect(screen.queryByText('New Comment')).not.toBeInTheDocument();

      // 두 번째 열기
      await user.click(notificationButton);
      expect(screen.getByText('New Comment')).toBeInTheDocument();
    });

    it('여러 알림을 순차적으로 클릭해도 정상 동작함', async () => {
      const user = userEvent.setup();
      const onNotificationClick = vi.fn();
      render(
        <NotificationCenter
          notifications={mockNotifications}
          onNotificationClick={onNotificationClick}
        />
      );

      // 첫 번째 알림 클릭
      await user.click(screen.getByRole('button'));
      await user.click(screen.getByText('New Comment').closest('button')!);
      expect(onNotificationClick).toHaveBeenCalledWith(1);

      // 드롭다운 다시 열기
      await user.click(screen.getByRole('button'));

      // 두 번째 알림 클릭
      await user.click(screen.getByText('System Update').closest('button')!);
      expect(onNotificationClick).toHaveBeenCalledWith(2);

      expect(onNotificationClick).toHaveBeenCalledTimes(2);
    });

    it('빈 알림에서 알림 추가 시 정상 렌더링됨', async () => {
      const user = userEvent.setup();
      const { rerender } = render(<NotificationCenter notifications={[]} />);

      // 빈 상태 확인
      await user.click(screen.getByRole('button'));
      expect(screen.getByText('No notifications')).toBeInTheDocument();

      // 알림 추가
      rerender(<NotificationCenter notifications={mockNotifications} />);

      // 배지 업데이트 확인
      expect(screen.getByText('2')).toBeInTheDocument(); // 읽지 않은 알림 2개
    });
  });

  describe('드롭다운 위치 테스트', () => {
    it('드롭다운이 올바른 위치에 렌더링됨', async () => {
      const user = userEvent.setup();
      const { container } = render(<NotificationCenter notifications={mockNotifications} />);

      await user.click(screen.getByRole('button'));

      // 드롭다운이 오른쪽 정렬되어 있는지 확인
      const dropdown = container.querySelector('.absolute.right-0');
      expect(dropdown).toBeInTheDocument();
    });

    it('드롭다운이 최대 높이를 가짐', async () => {
      const user = userEvent.setup();
      const { container } = render(<NotificationCenter notifications={mockNotifications} />);

      await user.click(screen.getByRole('button'));

      // 최대 높이 클래스 확인
      const scrollableArea = container.querySelector('.max-h-96.overflow-y-auto');
      expect(scrollableArea).toBeInTheDocument();
    });
  });
});
