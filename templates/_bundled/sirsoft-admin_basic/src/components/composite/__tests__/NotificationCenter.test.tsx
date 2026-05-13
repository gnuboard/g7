import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
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

    it('읽지 않은 알림이 99개 초과 시 99+로 표시됨', () => {
      const manyNotifications: NotificationItem[] = Array.from({ length: 150 }, (_, i) => ({
        id: i,
        title: `Notification ${i}`,
        message: 'Message',
        time: 'Just now',
        read: false,
      }));

      render(<NotificationCenter notifications={manyNotifications} />);
      expect(screen.getByText('99+')).toBeInTheDocument();
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

      // 읽지 않은 알림 찾기 — 카드는 data-notification-id 를 가진 Div (투명도 변형 bg-blue-50/70 적용)
      const unreadNotification = screen.getByText('New Comment').closest('[data-notification-id]');
      expect(unreadNotification?.className).toMatch(/bg-blue-50/);
    });

    it('읽은 알림에는 파란색 배경이 없음', async () => {
      const user = userEvent.setup();
      render(<NotificationCenter notifications={mockNotifications} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      // 읽은 알림 찾기
      const readNotification = screen.getByText('System Update').closest('[data-notification-id]');
      expect(readNotification?.className).not.toMatch(/bg-blue-50/);
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

      const notification = screen.getByText('New Comment').closest('[data-notification-id]');
      await user.click(notification as HTMLElement);

      expect(onNotificationClick).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));
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

      const notification = screen.getByText('Test').closest('[data-notification-id]');
      await user.click(notification as HTMLElement);

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

      const notification = screen.getByText('New Comment').closest('[data-notification-id]');
      await user.click(notification as HTMLElement);

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

    it('알림 아이템들이 카드 단위로 렌더링되어 접근 가능함', async () => {
      const user = userEvent.setup();
      const { container } = render(<NotificationCenter notifications={mockNotifications} />);

      const notificationButton = screen.getByRole('button');
      await user.click(notificationButton);

      // button-in-button 회피를 위해 카드는 Div 로 렌더링됨 → data-notification-id 로 접근
      const cards = container.querySelectorAll('[data-notification-id]');
      expect(cards.length).toBe(mockNotifications.length);
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
      await user.click(screen.getByText('New Comment').closest('[data-notification-id]') as HTMLElement);
      expect(onNotificationClick).toHaveBeenCalledWith(expect.objectContaining({ id: 1 }));

      // 드롭다운 다시 열기
      await user.click(screen.getByRole('button'));

      // 두 번째 알림 클릭
      await user.click(screen.getByText('System Update').closest('[data-notification-id]') as HTMLElement);
      expect(onNotificationClick).toHaveBeenCalledWith(expect.objectContaining({ id: 2 }));

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

  describe('[회귀] 무한스크롤 중복 호출 방지 (gnuboard/g7#14 관련)', () => {
    // 첫 번째로 생성되는 observer 만 무한스크롤용으로 취급. 미읽음 추적 observer 의
    // disconnect 가 무한스크롤 callback 을 교란하지 않도록 인스턴스 메서드로 격리한다.
    let infiniteScrollInstance: any = null;

    beforeEach(() => {
      infiniteScrollInstance = null;
      vi.stubGlobal(
        'IntersectionObserver',
        class MockIO {
          root: Element | null = null;
          rootMargin = '';
          thresholds: number[] = [];
          callback: IntersectionObserverCallback | null;
          target: Element | null = null;
          constructor(cb: IntersectionObserverCallback) {
            this.callback = cb;
            if (!infiniteScrollInstance) infiniteScrollInstance = this;
          }
          observe(target: Element) {
            if (!this.target) this.target = target;
          }
          disconnect() {
            this.callback = null;
            this.target = null;
            if (infiniteScrollInstance === this) infiniteScrollInstance = null;
          }
          unobserve() {}
          takeRecords() { return []; }
        },
      );
    });

    afterEach(() => {
      vi.unstubAllGlobals();
    });

    const fireIntersection = () => {
      infiniteScrollInstance?.callback?.(
        [{ isIntersecting: true, target: infiniteScrollInstance.target } as unknown as IntersectionObserverEntry],
        {} as IntersectionObserver,
      );
    };

    const makeNotifications = (count: number): NotificationItem[] =>
      Array.from({ length: count }, (_, i) => ({
        id: i + 1,
        title: `t${i}`,
        message: `m${i}`,
        time: '1m',
        read: false,
      }));

    it('같은 notifications.length 에서 sentinel 이 재교차해도 onLoadMore 는 한 번만 호출된다', async () => {
      const user = userEvent.setup();
      const onLoadMore = vi.fn();
      render(
        <NotificationCenter
          notifications={makeNotifications(15)}
          hasMore={true}
          loading={false}
          onLoadMore={onLoadMore}
        />,
      );

      await user.click(screen.getByRole('button'));

      fireIntersection();
      fireIntersection();
      fireIntersection();

      expect(onLoadMore).toHaveBeenCalledTimes(1);
    });

    it('loading=true 일 때는 onLoadMore 를 발행하지 않는다', async () => {
      const user = userEvent.setup();
      const onLoadMore = vi.fn();
      render(
        <NotificationCenter
          notifications={makeNotifications(15)}
          hasMore={true}
          loading={true}
          onLoadMore={onLoadMore}
        />,
      );

      await user.click(screen.getByRole('button'));
      fireIntersection();

      expect(onLoadMore).not.toHaveBeenCalled();
    });

    it('hasMore=false 일 때는 onLoadMore 를 발행하지 않는다', async () => {
      const user = userEvent.setup();
      const onLoadMore = vi.fn();
      render(
        <NotificationCenter
          notifications={makeNotifications(15)}
          hasMore={false}
          loading={false}
          onLoadMore={onLoadMore}
        />,
      );

      await user.click(screen.getByRole('button'));
      fireIntersection();

      expect(onLoadMore).not.toHaveBeenCalled();
    });

    it('notifications.length 가 증가한 뒤 재교차하면 onLoadMore 를 다시 발행한다', async () => {
      const user = userEvent.setup();
      const onLoadMore = vi.fn();
      const { rerender } = render(
        <NotificationCenter
          notifications={makeNotifications(15)}
          hasMore={true}
          loading={false}
          onLoadMore={onLoadMore}
        />,
      );

      await user.click(screen.getByRole('button'));
      fireIntersection();
      expect(onLoadMore).toHaveBeenCalledTimes(1);

      // 서버 응답이 와서 목록이 늘어난 상황
      rerender(
        <NotificationCenter
          notifications={makeNotifications(30)}
          hasMore={true}
          loading={false}
          onLoadMore={onLoadMore}
        />,
      );

      fireIntersection();
      expect(onLoadMore).toHaveBeenCalledTimes(2);

      // 같은 length 에서 재교차 → 다시 무시
      fireIntersection();
      expect(onLoadMore).toHaveBeenCalledTimes(2);
    });
  });

  describe('[회귀] 닫기 시 read-batch 불필요 호출 방지', () => {
    it('unreadCount=0 + 모든 알림 read=true 상태에서 닫으면 onClose 를 호출하지 않는다', async () => {
      const user = userEvent.setup();
      const onClose = vi.fn();
      const allRead: NotificationItem[] = mockNotifications.map((n) => ({ ...n, read: true }));

      render(
        <NotificationCenter
          notifications={allRead}
          unreadCount={0}
          onClose={onClose}
        />,
      );

      // 레이어 열고 닫기
      const button = screen.getByRole('button');
      await user.click(button);
      await user.click(button);

      expect(onClose).not.toHaveBeenCalled();
    });

    it('뷰포트에 노출됐던 ID 라도 현재 notifications 에서 read=true 로 바뀐 것은 onClose 인자에서 제외된다', async () => {
      const user = userEvent.setup();
      const onClose = vi.fn();

      // mockNotifications: id 1(unread), id 2(read), id 3(unread)
      const { rerender, container } = render(
        <NotificationCenter notifications={mockNotifications} unreadCount={2} onClose={onClose} />,
      );

      const button = screen.getByRole('button');
      await user.click(button);

      // data-notification-id 요소에 data-unread 속성을 주입하여 Observer가 ID를 수집할 수 있게 시뮬레이션
      container.querySelectorAll('[data-notification-id]').forEach((el) => {
        const id = el.getAttribute('data-notification-id');
        const notification = mockNotifications.find((n) => String(n.id) === String(id));
        el.setAttribute('data-unread', notification?.read ? 'false' : 'true');
      });

      // rerender: 모든 알림이 read=true 로 갱신된 상황 (모두 읽음 onSuccess 반영)
      rerender(
        <NotificationCenter
          notifications={mockNotifications.map((n) => ({ ...n, read: true }))}
          unreadCount={0}
          onClose={onClose}
        />,
      );

      // 레이어 닫기
      await user.click(screen.getByRole('button'));

      expect(onClose).not.toHaveBeenCalled();
    });
  });

  describe('[회귀] 드롭다운 뷰포트 이탈 방지', () => {
    let originalGetBoundingClientRect: typeof Element.prototype.getBoundingClientRect;

    beforeEach(() => {
      originalGetBoundingClientRect = Element.prototype.getBoundingClientRect;
    });

    afterEach(() => {
      Element.prototype.getBoundingClientRect = originalGetBoundingClientRect;
    });

    it('드롭다운에 max-w-[calc(100vw-1rem)] 클래스가 적용된다', async () => {
      const user = userEvent.setup();
      const { container } = render(<NotificationCenter notifications={mockNotifications} />);
      await user.click(screen.getByRole('button'));

      const dropdown = container.querySelector('.absolute.w-80');
      expect(dropdown?.className).toContain('max-w-[calc(100vw-1rem)]');
    });

    it('드롭다운이 뷰포트 오른쪽을 이탈하면 translateX 로 왼쪽으로 보정된다', async () => {
      // rect.right 가 vw 를 초과하도록 mock → 이탈량만큼 음의 translateX 기대
      Element.prototype.getBoundingClientRect = vi.fn(() => ({
        left: 100,
        right: 1100,
        top: 0,
        bottom: 100,
        width: 320,
        height: 100,
        x: 100,
        y: 0,
        toJSON: () => ({}),
      }) as DOMRect);
      Object.defineProperty(window, 'innerWidth', { value: 1024, writable: true, configurable: true });

      const user = userEvent.setup();
      const { container } = render(
        <NotificationCenter notifications={mockNotifications} dropdownAlign="left" />,
      );
      await user.click(screen.getByRole('button'));

      const dropdown = container.querySelector<HTMLElement>('.absolute.w-80');
      // 1100 - (1024 - 8) = 84 → translateX(-84px)
      expect(dropdown?.style.transform).toBe('translateX(-84px)');
    });

    it('드롭다운이 뷰포트 왼쪽을 이탈하면 translateX 로 오른쪽으로 보정된다', async () => {
      Element.prototype.getBoundingClientRect = vi.fn(() => ({
        left: -50,
        right: 270,
        top: 0,
        bottom: 100,
        width: 320,
        height: 100,
        x: -50,
        y: 0,
        toJSON: () => ({}),
      }) as DOMRect);
      Object.defineProperty(window, 'innerWidth', { value: 1024, writable: true, configurable: true });

      const user = userEvent.setup();
      const { container } = render(
        <NotificationCenter notifications={mockNotifications} dropdownAlign="right" />,
      );
      await user.click(screen.getByRole('button'));

      const dropdown = container.querySelector<HTMLElement>('.absolute.w-80');
      // margin(8) - left(-50) = 58 → translateX(58px)
      expect(dropdown?.style.transform).toBe('translateX(58px)');
    });

    it('드롭다운이 뷰포트 안에 있으면 transform 이 적용되지 않는다', async () => {
      Element.prototype.getBoundingClientRect = vi.fn(() => ({
        left: 100,
        right: 420,
        top: 0,
        bottom: 100,
        width: 320,
        height: 100,
        x: 100,
        y: 0,
        toJSON: () => ({}),
      }) as DOMRect);
      Object.defineProperty(window, 'innerWidth', { value: 1024, writable: true, configurable: true });

      const user = userEvent.setup();
      const { container } = render(
        <NotificationCenter notifications={mockNotifications} dropdownAlign="right" />,
      );
      await user.click(screen.getByRole('button'));

      const dropdown = container.querySelector<HTMLElement>('.absolute.w-80');
      expect(dropdown?.style.transform).toBe('');
    });
  });
});
