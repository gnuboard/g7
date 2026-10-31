/**
 * @file NotificationCenter.readBatchChunk.test.tsx
 * @description 드롭다운을 닫을 때 읽음 처리 id 를 서버 한도(100건) 단위로 나눠 보낸다 (#703)
 *
 * 더보기로 알림을 많이 펼친 뒤 닫으면 화면에 보인 미읽음 id 가 100건을 넘을 수 있다.
 * 읽음 일괄 API(`read-batch`)는 `ids` 를 최대 100건까지만 받으므로(NotificationBatchReadRequest),
 * 한 번에 넘기면 422 로 거절되어 아무것도 읽음 처리되지 않는다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { NotificationCenter, NotificationItem, READ_BATCH_MAX_IDS } from '../NotificationCenter';

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

const makeUnread = (count: number): NotificationItem[] =>
  Array.from({ length: count }, (_, i) => ({ id: `n-${i + 1}`, title: `t${i}`, message: `m${i}`, time: '1m', read: false }));

describe('NotificationCenter — 닫기 읽음 처리 분할', () => {
  beforeEach(() => {
    vi.stubGlobal('IntersectionObserver', VisibleAllIO);
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  /**
   * @scenario defect=notification_read_batch_over_limit
   * @effects read_batch_ids_chunked_within_server_limit
   */
  it('보인 미읽음이 한도를 넘으면 한도 이하 묶음으로 나눠 onClose 를 부른다', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(<NotificationCenter notifications={makeUnread(230)} unreadCount={230} onClose={onClose} />);

    const trigger = screen.getAllByRole('button')[0];
    await user.click(trigger);
    await user.click(trigger);

    expect(READ_BATCH_MAX_IDS).toBe(100);
    expect(onClose).toHaveBeenCalledTimes(3);
    const sizes = onClose.mock.calls.map((c) => (c[0] as unknown[]).length);
    expect(sizes).toEqual([100, 100, 30]);
    const all = onClose.mock.calls.flatMap((c) => c[0] as string[]);
    expect(new Set(all).size).toBe(230);
  });

  /**
   * @scenario defect=notification_read_batch_over_limit
   * @effects read_batch_ids_chunked_within_server_limit
   */
  it('한도 이하면 한 번만 부른다', async () => {
    const user = userEvent.setup();
    const onClose = vi.fn();
    render(<NotificationCenter notifications={makeUnread(15)} unreadCount={15} onClose={onClose} />);

    const trigger = screen.getAllByRole('button')[0];
    await user.click(trigger);
    await user.click(trigger);

    expect(onClose).toHaveBeenCalledTimes(1);
    expect((onClose.mock.calls[0][0] as unknown[]).length).toBe(15);
  });
});
