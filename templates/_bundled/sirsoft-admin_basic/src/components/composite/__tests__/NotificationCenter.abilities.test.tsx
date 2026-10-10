/**
 * @file NotificationCenter.abilities.test.tsx
 * @description 알림 센터 쓰기 권한 게이트(G21) — canUpdate/canDelete 가 false 면 읽음·삭제 동작을 내지 않는다
 */
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { NotificationCenter, NotificationItem } from '../NotificationCenter';

/** 관찰 대상 전부를 즉시 「보임」 으로 알리는 IntersectionObserver 대역 */
class VisibleAllIO {
  private cb: IntersectionObserverCallback;

  constructor(cb: IntersectionObserverCallback) {
    this.cb = cb;
  }

  observe(target: Element) {
    this.cb([{ isIntersecting: true, target } as unknown as IntersectionObserverEntry], this as unknown as IntersectionObserver);
  }

  unobserve() {}

  disconnect() {}

  takeRecords() {
    return [];
  }
}

const items: NotificationItem[] = [
  { id: 1, title: '새 댓글', message: '댓글이 달렸습니다', time: '방금', read: false },
  { id: 2, title: '업데이트', message: '갱신됨', time: '1시간 전', read: true },
];

describe('NotificationCenter abilities', () => {
  // @scenario can_update=false,can_delete=false
  // @effects notification_center_hides_write_actions_without_permission
  it('읽음·삭제 권한이 없으면 모두 읽음·모두 삭제·개별 삭제 버튼이 없다', async () => {
    const user = userEvent.setup();
    render(
      <NotificationCenter
        notifications={items}
        canUpdate={false}
        canDelete={false}
        markAllReadText="모두 읽음"
        deleteAllText="모두 삭제"
        onMarkAllRead={vi.fn()}
        onDeleteAll={vi.fn()}
        onDelete={vi.fn()}
      />
    );
    await user.click(screen.getAllByRole('button')[0]);

    expect(screen.getByText('새 댓글')).toBeInTheDocument();
    expect(screen.queryByText('모두 읽음')).toBeNull();
    expect(screen.queryByText('모두 삭제')).toBeNull();
    expect(screen.queryByRole('button', { name: /삭제|delete/i })).toBeNull();
  });

  // @scenario can_update=false,action=close_dropdown
  // @effects notification_center_skips_read_batch_without_update_permission
  it('읽음 권한이 없으면 드롭다운을 닫아도 읽음 처리 요청(onClose ids)을 보내지 않는다', async () => {
    const user = userEvent.setup();
    vi.stubGlobal('IntersectionObserver', VisibleAllIO);
    try {
      const allowed = vi.fn();
      const { unmount } = render(<NotificationCenter notifications={items} unreadCount={1} onClose={allowed} />);
      let bell = screen.getAllByRole('button')[0];
      await user.click(bell);
      await user.click(bell);
      // 대조군: 권한이 있으면 보인 미읽음 id 로 onClose 가 불린다
      expect(allowed).toHaveBeenCalledTimes(1);
      unmount();

      const onClose = vi.fn();
      render(<NotificationCenter notifications={items} unreadCount={1} canUpdate={false} onClose={onClose} />);
      bell = screen.getAllByRole('button')[0];
      await user.click(bell);
      await user.click(bell);

      expect(onClose).not.toHaveBeenCalled();
    } finally {
      vi.unstubAllGlobals();
    }
  });

  // @scenario can_update=true,can_delete=true
  // @effects notification_center_shows_write_actions_with_permission
  it('권한이 있으면(기본값) 모두 읽음·모두 삭제 버튼이 보인다', async () => {
    const user = userEvent.setup();
    render(
      <NotificationCenter
        notifications={items}
        markAllReadText="모두 읽음"
        deleteAllText="모두 삭제"
        onMarkAllRead={vi.fn()}
        onDeleteAll={vi.fn()}
      />
    );
    await user.click(screen.getAllByRole('button')[0]);

    expect(screen.getByText('모두 읽음')).toBeInTheDocument();
    expect(screen.getByText('모두 삭제')).toBeInTheDocument();
  });
});
