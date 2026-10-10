/**
 * @file ComponentDisabledContract.test.tsx
 * @description 사용자 템플릿 컴포넌트의 disabled·권한 계약 — 관리자 템플릿과 대칭 (7.1.0 abilities 게이트 G09·G27)
 */
import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { HtmlEditor } from '../HtmlEditor';
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
  { id: 1, title: '새 댓글', message: '댓글', time: '방금', read: false },
];

describe('사용자 템플릿 컴포넌트 계약', () => {
  // @scenario component=HtmlEditor,disabled=true
  // @effects html_editor_disabled_blocks_editing
  it('HtmlEditor 는 disabled 를 readOnly 와 같이 다룬다', () => {
    const { container } = render(<HtmlEditor content="본문" disabled />);
    const textarea = container.querySelector('textarea') as HTMLTextAreaElement;

    expect(textarea).not.toBeNull();
    expect(textarea.readOnly || textarea.disabled).toBe(true);
  });

  // @scenario component=NotificationCenter,can_update=false,can_delete=false
  // @effects notification_center_hides_write_actions_without_permission
  it('NotificationCenter 는 권한이 없으면 모두 읽음·모두 삭제를 숨기고 닫을 때 읽음 처리를 보내지 않는다', async () => {
    vi.stubGlobal('IntersectionObserver', VisibleAllIO);
    try {
      const user = userEvent.setup();
      const onClose = vi.fn();
      render(
        <NotificationCenter
          notifications={items}
          unreadCount={1}
          canUpdate={false}
          canDelete={false}
          markAllReadText="모두 읽음"
          deleteAllText="모두 삭제"
          onMarkAllRead={vi.fn()}
          onDeleteAll={vi.fn()}
          onClose={onClose}
        />
      );
      const bell = screen.getAllByRole('button')[0];
      await user.click(bell);

      expect(screen.queryByText('모두 읽음')).toBeNull();
      expect(screen.queryByText('모두 삭제')).toBeNull();

      await user.click(bell);
      expect(onClose).not.toHaveBeenCalled();
    } finally {
      vi.unstubAllGlobals();
    }
  });
});
