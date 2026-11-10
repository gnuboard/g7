/**
 * useLayoutVersions.loadMore.test.tsx — 버전 목록 「더 보기」 상한 논리(훅 단위)
 *
 * 종전에는 이 논리를 VersionHistoryModal 렌더로 쟀다. `hasMore` 가 「응답 건수 >= 요청 상한」이라 응답을
 * 상한만큼 채워야 하고, 그 행을 모두 jsdom 에 그리느라(행 하나 약 13ms, 100행 1.5초, 클램프 시나리오는
 * 누적 1,500행 약 10초) 동시 실행 부하에서 제한 시간을 넘겼다(#869). 상한 논리는 훅이 소유하므로 행을
 * 그리지 않는 훅 단위에서 재고, 모달은 단추 연결만 VersionHistoryModal.loadMore.test.tsx 가 잰다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import { useLayoutVersions, VERSION_MAX_LIMIT, VERSION_PAGE_SIZE } from '../../hooks/useLayoutVersions';

/** 상한만큼(또는 지정 건수) 채운 버전 목록. */
function makeVersions(count: number) {
  return Array.from({ length: count }, (_, i) => ({
    id: 1000 + i,
    layout_id: 1,
    version: count - i,
    changes_summary: { added_count: 1, removed_count: 0, char_diff: 5 },
    created_at: '2026-06-01T00:00:00+00:00',
    created_by_name: null,
  }));
}

/** 요청 URL 의 limit 값. */
function limitOf(url: unknown): number {
  return Number(new URL(String(url), 'https://example.test').searchParams.get('limit'));
}

/** 응답 건수를 limit 에 따라 정하는 fetch 목. */
function mockFetch(rowsFor: (limit: number) => number) {
  const fn = vi.fn(async (url: string) => ({
    ok: true,
    json: async () => ({ success: true, data: makeVersions(rowsFor(limitOf(url))) }),
  }));
  vi.stubGlobal('fetch', fn);
  return fn;
}

const TARGET = { kind: 'layout', layoutName: 'auth/login' } as const;

beforeEach(() => {
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => (k === 'auth_token' ? 'TESTTOKEN' : null),
    setItem: () => {},
    removeItem: () => {},
  } as unknown as Storage);
});

afterEach(() => {
  vi.unstubAllGlobals();
  vi.restoreAllMocks();
});

describe('useLayoutVersions — 더 보기 상한', () => {
  // @scenario area=load_more
  /**
   * @effects version_load_more_only_when_limit_filled
   */
  it('첫 조회는 기본 상한, 응답이 상한을 채우면 hasMore, 미만이면 아니다', async () => {
    const full = mockFetch(() => VERSION_PAGE_SIZE);
    const { result } = renderHook(() => useLayoutVersions('sirsoft-basic', TARGET as never));
    await act(async () => {
      await result.current.loadVersions();
    });
    expect(limitOf(full.mock.calls[0]![0])).toBe(VERSION_PAGE_SIZE);
    expect(result.current.hasMore).toBe(true);

    mockFetch(() => 2);
    const { result: short } = renderHook(() => useLayoutVersions('sirsoft-basic', TARGET as never));
    await act(async () => {
      await short.current.loadVersions();
    });
    expect(short.current.hasMore).toBe(false);
  });

  // @scenario area=load_more
  /**
   * @effects version_load_more_widens_limit_and_refetches
   */
  it('loadMore 는 상한을 한 묶음 넓혀 다시 조회하고(이어붙이기 아님), 상한 미만이 오면 hasMore 를 끈다', async () => {
    const fetchMock = mockFetch((limit) => Math.min(limit, 150));
    const { result } = renderHook(() => useLayoutVersions('sirsoft-basic', TARGET as never));
    await act(async () => {
      await result.current.loadVersions();
    });
    expect(result.current.hasMore).toBe(true);
    await act(async () => {
      await result.current.loadMore();
    });
    expect(fetchMock.mock.calls.map((c) => limitOf(c[0]))).toEqual([VERSION_PAGE_SIZE, VERSION_PAGE_SIZE * 2]);
    // 이어붙이기가 아니라 다시 조회한 150건이 목록 전체다.
    expect(result.current.versions).toHaveLength(150);
    expect(result.current.hasMore).toBe(false);
  });

  // @scenario area=load_more
  /**
   * @effects version_load_more_clamps_to_server_max
   */
  it('서버 최대 상한을 넘겨 요청하지 않고, 상한에 닿으면 더 넓히지 않는다', async () => {
    // 매번 요청한 만큼 꽉 채워 돌려준다 — 클램프가 없으면 loadMore 마다 limit 이 끝없이 커진다.
    const fetchMock = mockFetch((limit) => limit);
    const { result } = renderHook(() => useLayoutVersions('sirsoft-basic', TARGET as never));
    await act(async () => {
      await result.current.loadVersions();
    });
    for (let attempt = 0; attempt < 10 && result.current.hasMore; attempt++) {
      await act(async () => {
        await result.current.loadMore();
      });
    }
    const limits = fetchMock.mock.calls.map((c) => limitOf(c[0]));
    expect(Math.max(...limits)).toBe(VERSION_MAX_LIMIT);
    expect(limits.every((n) => n <= VERSION_MAX_LIMIT)).toBe(true);
    expect(result.current.hasMore).toBe(false);
    // 상한에 닿은 뒤 loadMore 를 더 불러도 요청이 나가지 않는다.
    const before = fetchMock.mock.calls.length;
    await act(async () => {
      await result.current.loadMore();
    });
    await waitFor(() => expect(fetchMock.mock.calls.length).toBe(before));
  });
});
