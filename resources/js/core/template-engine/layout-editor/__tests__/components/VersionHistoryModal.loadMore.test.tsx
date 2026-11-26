/**
 * VersionHistoryModal.loadMore.test.tsx — 「더 보기」 단추 연결(모달 단위)
 *
 * 상한 논리는 훅(useLayoutVersions.loadMore.test.tsx)이 잰다. 여기서는 훅 상태를 고정해 모달이 그 상태대로
 * 단추를 보이고, 누르면 loadMore 를 부르고, 조회 중에는 막는지만 잰다. 행을 상한만큼 그리지 않아 동시 실행
 * 부하에도 가볍다.
 */

import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';

const hookState = vi.hoisted(() => ({
  hasMore: false,
  isLoadingMore: false,
  loadMore: (() => Promise.resolve()) as () => Promise<void>,
}));

vi.mock('../../hooks/useLayoutVersions', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../hooks/useLayoutVersions')>();
  return {
    ...actual,
    useLayoutVersions: () => ({
      versions: [
        {
          id: 11,
          layout_id: 1,
          version: 2,
          changes_summary: { added_count: 1, removed_count: 0, char_diff: 5 },
          created_at: '2026-06-01T00:00:00+00:00',
          created_by_name: null,
        },
      ],
      isLoading: false,
      error: null,
      restoringId: null,
      loadVersions: () => Promise.resolve(),
      hasMore: hookState.hasMore,
      loadMore: hookState.loadMore,
      isLoadingMore: hookState.isLoadingMore,
      restore: () => Promise.resolve({ kind: 'not_found' }),
      loadVersionDetail: () => Promise.resolve({ kind: 'not_found' }),
    }),
  };
});

import { VersionHistoryModal } from '../../components/VersionHistoryModal';

const t = (k: string) => k;

function renderModal() {
  render(
    <VersionHistoryModal
      templateIdentifier="sirsoft-basic"
      target={{ kind: 'layout', layoutName: 'auth/login' } as never}
      t={t}
      onRestored={vi.fn()}
      onClose={vi.fn()}
    />,
  );
}

afterEach(() => {
  cleanup();
  hookState.hasMore = false;
  hookState.isLoadingMore = false;
});

describe('VersionHistoryModal — 더 보기 단추 연결', () => {
  // @scenario area=load_more
  /**
   * @effects version_load_more_only_when_limit_filled
   */
  it('hasMore 면 단추를 보이고, 아니면 숨긴다', () => {
    hookState.hasMore = true;
    renderModal();
    expect(screen.getByTestId('g7le-version-history-load-more')).toBeInTheDocument();
    cleanup();
    hookState.hasMore = false;
    renderModal();
    expect(screen.queryByTestId('g7le-version-history-load-more')).toBeNull();
  });

  // @scenario area=load_more
  /**
   * @effects version_load_more_widens_limit_and_refetches
   */
  it('단추를 누르면 loadMore 를 부르고, 조회 중(isLoadingMore)에는 단추가 막힌다', () => {
    const loadMore = vi.fn(() => Promise.resolve());
    hookState.hasMore = true;
    hookState.loadMore = loadMore;
    renderModal();
    fireEvent.click(screen.getByTestId('g7le-version-history-load-more'));
    expect(loadMore).toHaveBeenCalledTimes(1);
    cleanup();
    hookState.isLoadingMore = true;
    renderModal();
    expect((screen.getByTestId('g7le-version-history-load-more') as HTMLButtonElement).disabled).toBe(true);
  });
});
