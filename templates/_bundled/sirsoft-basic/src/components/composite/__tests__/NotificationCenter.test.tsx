/**
 * NotificationCenter 컴포넌트 테스트 (sirsoft-basic)
 *
 * @description 알림센터 드롭다운의 핵심 동작 검증
 * 주요 회귀:
 * - 알림 카드는 Button이 아닌 Div (button-in-button HTML 무효 회피)
 * - 외부 클릭 useEffect 의존성에 [showNotifications, handleClose] 포함
 * - useEffect는 handleClose(useCallback) 정의 이후 위치 → TDZ 회피
 * - 콜백은 notification 객체 전체를 인자로 전달 (id가 아님)
 */
import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { NotificationCenter, type NotificationItem } from '../NotificationCenter';

// IntersectionObserver 폴리필
beforeEach(() => {
  cleanup();
  (window as any).IntersectionObserver = class {
    observe() {}
    unobserve() {}
    disconnect() {}
  };
});

const mockNotifications: NotificationItem[] = [
  {
    id: 1,
    title: '새 댓글',
    message: '게시물에 댓글이 달렸습니다',
    time: '2026-04-10 10:00:00',
    read: false,
  },
  {
    id: 2,
    title: '시스템 알림',
    message: '시스템 점검이 완료되었습니다',
    time: '2026-04-10 09:00:00',
    read: true,
  },
];

describe('NotificationCenter (sirsoft-basic)', () => {
  describe('기본 렌더링', () => {
    it('Bell 트리거 버튼이 렌더링됨', () => {
      render(<NotificationCenter />);
      expect(screen.getByLabelText('알림')).toBeInTheDocument();
    });

    it('unreadCount가 0보다 크면 배지가 표시됨', () => {
      render(<NotificationCenter unreadCount={5} />);
      expect(screen.getByText('5')).toBeInTheDocument();
    });

    it('unreadCount가 99 초과 시 99+로 표시됨', () => {
      render(<NotificationCenter unreadCount={150} />);
      expect(screen.getByText('99+')).toBeInTheDocument();
    });

    it('unreadCount가 0이면 배지가 표시되지 않음', () => {
      const { container } = render(<NotificationCenter unreadCount={0} notifications={[]} />);
      // 배지는 .bg-red-500 클래스를 가지는 Span
      expect(container.querySelector('.bg-red-500')).not.toBeInTheDocument();
    });
  });

  describe('드롭다운 토글', () => {
    it('Bell 클릭 시 드롭다운이 열림', () => {
      render(<NotificationCenter notifications={mockNotifications} titleText="알림" />);
      fireEvent.click(screen.getByLabelText('알림'));
      expect(screen.getByText('새 댓글')).toBeInTheDocument();
      expect(screen.getByText('시스템 알림')).toBeInTheDocument();
    });

    it('알림이 비어있을 때 emptyText가 표시됨', () => {
      render(<NotificationCenter notifications={[]} emptyText="알림이 없습니다." />);
      fireEvent.click(screen.getByLabelText('알림'));
      expect(screen.getByText('알림이 없습니다.')).toBeInTheDocument();
    });

    it('드롭다운 외부 클릭 시 닫힘 (외부 클릭 의존성 회귀)', () => {
      const { container } = render(
        <div>
          <NotificationCenter notifications={mockNotifications} />
          <div data-testid="outside">바깥</div>
        </div>
      );
      fireEvent.click(screen.getByLabelText('알림'));
      expect(screen.getByText('새 댓글')).toBeInTheDocument();

      fireEvent.mouseDown(screen.getByTestId('outside'));
      expect(screen.queryByText('새 댓글')).not.toBeInTheDocument();
    });
  });

  describe('button-in-button 회귀 — 알림 카드는 Div', () => {
    it('알림 카드가 button 태그가 아닌 div 태그여야 함', () => {
      const { container } = render(<NotificationCenter notifications={mockNotifications} />);
      fireEvent.click(screen.getByLabelText('알림'));

      const titleEl = screen.getByText('새 댓글');
      // 가장 가까운 알림 카드 컨테이너 (data-notification-id 속성을 가진 요소)
      const card = titleEl.closest('[data-notification-id]');
      expect(card).not.toBeNull();
      expect(card!.tagName.toLowerCase()).toBe('div');
    });
  });

  describe('이벤트 핸들러', () => {
    it('알림 클릭 시 onNotificationClick 콜백에 객체 전체가 전달됨', () => {
      const handler = vi.fn();
      render(
        <NotificationCenter
          notifications={mockNotifications}
          onNotificationClick={handler}
        />
      );
      fireEvent.click(screen.getByLabelText('알림'));
      const card = screen.getByText('새 댓글').closest('[data-notification-id]');
      fireEvent.click(card!);

      expect(handler).toHaveBeenCalledTimes(1);
      expect(handler).toHaveBeenCalledWith(
        expect.objectContaining({ id: 1, title: '새 댓글' })
      );
    });

    it('개별 삭제 버튼 클릭 시 onDelete가 호출되고 카드 클릭 이벤트는 전파되지 않음', () => {
      const onDelete = vi.fn();
      const onClick = vi.fn();
      const { container } = render(
        <NotificationCenter
          notifications={mockNotifications}
          onDelete={onDelete}
          onNotificationClick={onClick}
        />
      );
      fireEvent.click(screen.getByLabelText('알림'));

      const deleteBtn = container.querySelector('[aria-label="Delete notification"]');
      expect(deleteBtn).not.toBeNull();
      fireEvent.click(deleteBtn!);

      expect(onDelete).toHaveBeenCalledTimes(1);
      expect(onDelete).toHaveBeenCalledWith(
        expect.objectContaining({ id: 1 })
      );
      // stopPropagation 검증: 카드 클릭 핸들러는 호출되지 않아야 함
      expect(onClick).not.toHaveBeenCalled();
    });

    it('"모두 읽음" 버튼은 displayCount > 0 일 때만 표시되고 onMarkAllRead 호출', () => {
      const onMarkAllRead = vi.fn();
      render(
        <NotificationCenter
          notifications={mockNotifications}
          unreadCount={2}
          onMarkAllRead={onMarkAllRead}
          markAllReadText="모두 읽음"
        />
      );
      fireEvent.click(screen.getByLabelText('알림'));
      fireEvent.click(screen.getByText('모두 읽음'));
      expect(onMarkAllRead).toHaveBeenCalledTimes(1);
    });

    it('"모두 삭제" 버튼은 notifications.length > 0 일 때만 표시되고 onDeleteAll 호출', () => {
      const onDeleteAll = vi.fn();
      render(
        <NotificationCenter
          notifications={mockNotifications}
          onDeleteAll={onDeleteAll}
          deleteAllText="모두 삭제"
        />
      );
      fireEvent.click(screen.getByLabelText('알림'));
      fireEvent.click(screen.getByText('모두 삭제'));
      expect(onDeleteAll).toHaveBeenCalledTimes(1);
    });

    it('"안 읽은 알림만" 체크박스 토글 시 onUnreadOnlyToggle 호출', () => {
      const onUnreadOnlyToggle = vi.fn();
      render(
        <NotificationCenter
          notifications={mockNotifications}
          unreadOnly={false}
          onUnreadOnlyToggle={onUnreadOnlyToggle}
          unreadOnlyText="안 읽은 알림만"
        />
      );
      fireEvent.click(screen.getByLabelText('알림'));
      const checkbox = screen.getByRole('checkbox') as HTMLInputElement;
      fireEvent.click(checkbox);
      expect(onUnreadOnlyToggle).toHaveBeenCalled();
      expect(onUnreadOnlyToggle).toHaveBeenLastCalledWith(true);
    });
  });

  describe('dropdownAlign prop', () => {
    it('기본값은 right (right-0 클래스)', () => {
      const { container } = render(<NotificationCenter notifications={mockNotifications} />);
      fireEvent.click(screen.getByLabelText('알림'));
      expect(container.querySelector('.right-0')).not.toBeNull();
    });

    it('left 지정 시 left-0 클래스', () => {
      const { container } = render(
        <NotificationCenter notifications={mockNotifications} dropdownAlign="left" />
      );
      fireEvent.click(screen.getByLabelText('알림'));
      expect(container.querySelector('.left-0')).not.toBeNull();
    });
  });

  describe('읽음/미읽음 시각 구분', () => {
    it('미읽음 알림에는 파란 점이 있음', () => {
      const { container } = render(
        <NotificationCenter notifications={mockNotifications} />
      );
      fireEvent.click(screen.getByLabelText('알림'));
      const blueDots = container.querySelectorAll('.bg-blue-500.rounded-full');
      // 미읽음 1건만 점 표시
      expect(blueDots.length).toBe(1);
    });

    it('미읽음 카드에는 파란 배경 클래스가 적용됨', () => {
      render(<NotificationCenter notifications={mockNotifications} />);
      fireEvent.click(screen.getByLabelText('알림'));
      const unread = screen.getByText('새 댓글').closest('[data-notification-id]');
      expect(unread?.className).toMatch(/bg-blue-50/);
    });

    it('읽음 카드에는 opacity-70 클래스가 적용됨', () => {
      render(<NotificationCenter notifications={mockNotifications} />);
      fireEvent.click(screen.getByLabelText('알림'));
      const read = screen.getByText('시스템 알림').closest('[data-notification-id]');
      expect(read?.className).toMatch(/opacity-70/);
    });
  });
});
