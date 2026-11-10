/**
 * useLayoutDocument — 다국어 칸 기록을 기다리는 동안 편집기가 닫혀도 저장이 끝나는지 (관찰 O3)
 *
 * [저장]은 진행 중인 다국어 칸 기록(키 생성 → 토큰 기록)이 끝날 때까지 기다린 뒤 함수형 갱신(`setDocument(prev => prev)`)
 * 으로 최신 문서를 받는다. 그 대기 중에 훅이 언마운트되면 갱신 함수가 돌지 않아 저장이 영영 끝나지 않을 수 있다는 관찰을
 * 실물 훅으로 잠근다 — 7.1.4 처럼 누른 저장은 편집기를 닫아도 서버까지 가야 한다.
 *
 * @since engine-v1.88.0
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import React from 'react';
import { useLayoutDocument } from '../../hooks/useLayoutDocument';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { beginCustomTextCommit } from '../../hooks/inflightCustomTextCommits';

function wrapper({ children }: { children: React.ReactNode }): React.ReactElement {
  return (
    <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
      <RouteSeeder>{children}</RouteSeeder>
    </LayoutEditorProvider>
  );
}

function RouteSeeder({ children }: { children: React.ReactNode }): React.ReactElement {
  const { dispatch } = useLayoutEditor();
  React.useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path: '/', layoutName: 'home' } });
  }, [dispatch]);
  return <>{children}</>;
}

const initialLoadResponse = {
  ok: true,
  status: 200,
  json: async () => ({
    success: true,
    data: { components: [{ name: 'Div', type: 'basic' }], lock_version: 7 },
  }),
};

const saveOkResponse = {
  ok: true,
  status: 200,
  json: async () => ({ success: true, data: { lock_version: 8 } }),
};

/** 상한 안에 끝나지 않으면 'pending' 을 돌려준다 */
function settleWithin<T>(promise: Promise<T>, ms: number): Promise<T | 'pending'> {
  return Promise.race([promise, new Promise<'pending'>((resolve) => setTimeout(() => resolve('pending'), ms))]);
}

describe('useLayoutDocument — 다국어 칸 기록 대기 중 언마운트 (O3)', () => {
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchMock = vi.fn();
    fetchMock.mockResolvedValueOnce(initialLoadResponse as any).mockResolvedValueOnce(saveOkResponse as any);
    (globalThis as any).fetch = fetchMock;
  });

  afterEach(() => {
    delete (globalThis as any).fetch;
  });

  /**
   * @effects save_waiting_i18n_commit_still_puts_after_editor_unmount
   */
  it('기록이 끝나기 전에 훅이 언마운트돼도 저장 요청을 보내고 결과를 돌려준다', async () => {
    const { result, unmount } = renderHook(() => useLayoutDocument(), { wrapper });
    await waitFor(() => expect(result.current.document).not.toBeNull());

    act(() => {
      result.current.patchLayout((cur) => [...cur, { name: 'Button', type: 'basic' }]);
    });
    expect(result.current.isDirty).toBe(true);

    const endCommit = beginCustomTextCommit();
    const save = result.current.save();

    unmount();
    endCommit();

    const outcome = await settleWithin(save, 2000);
    expect(outcome).not.toBe('pending');
    expect((outcome as any).kind).toBe('success');

    const saveCall = fetchMock.mock.calls[1];
    expect(saveCall).toBeDefined();
    const body = JSON.parse(String(saveCall[1]?.body ?? '{}'));
    expect(JSON.parse(body.content).components.map((c: any) => c.name)).toEqual(['Div', 'Button']);
  });

  /**
   * @effects save_waits_inflight_i18n_key_creation_before_put
   */
  it('언마운트 전에 기록이 끝나면 종전처럼 최신 문서로 저장한다', async () => {
    const { result } = renderHook(() => useLayoutDocument(), { wrapper });
    await waitFor(() => expect(result.current.document).not.toBeNull());

    act(() => {
      result.current.patchLayout((cur) => [...cur, { name: 'Span', type: 'basic' }]);
    });

    // act 안에서 기다리면 act 가 콜백이 끝날 때까지 렌더를 미뤄 함수형 갱신이 돌지 않는다 — 실제 화면처럼 act 밖에서 기다린다
    const endCommit = beginCustomTextCommit();
    const save = result.current.save();
    endCommit();
    const outcome = await settleWithin(save, 2000);
    expect(outcome).not.toBe('pending');
    expect((outcome as any).kind).toBe('success');
    const body = JSON.parse(String(fetchMock.mock.calls[1]?.[1]?.body ?? '{}'));
    expect(JSON.parse(body.content).components.map((c: any) => c.name)).toEqual(['Div', 'Span']);
  });

  /**
   * @effects save_waiting_i18n_commit_still_puts_after_editor_unmount
   */
  it('기록이 끝나는 같은 순간(patch 가 아직 그려지기 전)에 언마운트돼도 저장이 끝난다', async () => {
    const { result, unmount } = renderHook(() => useLayoutDocument(), { wrapper });
    await waitFor(() => expect(result.current.document).not.toBeNull());

    const endCommit = beginCustomTextCommit();
    const save = result.current.save();

    // 다국어 칸 기록의 실제 순서: 토큰 patch(setDocument) → finally 에서 기록 끝 알림. 그 사이에 편집기가 닫힌다.
    result.current.patchLayout((cur) => [...cur, { name: 'Em', type: 'basic' }]);
    unmount();
    endCommit();

    const outcome = await settleWithin(save, 2000);
    expect(outcome).not.toBe('pending');
  });
});
