/**
 * useLayoutDocument — save / saveGuard / 409 / 422 통합 회귀 테스트
 *
 *
 * 검증 매트릭스:
 *  - golden: PATCH → save(200) → newLockVersion 갱신 + dirty false
 *  - 409 Conflict → kind=concurrent_modification + current/your version 전달
 *  - 422 Validation → kind=validation_failed + errors 객체 전달
 *  - 가드: 비활성 확장 신규 노드 차단 → blocked_inactive_extension
 *  - 네트워크 실패 → kind=network_error
 *
 * @since engine-v1.50.0
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import React from 'react';
import { useLayoutDocument } from '../../hooks/useLayoutDocument';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';

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
    data: {
      components: [{ name: 'Div', type: 'basic' }],
      lock_version: 7,
    },
  }),
};

function mockOk(payload: any, lockVersion: number = 8) {
  return {
    ok: true,
    status: 200,
    json: async () => ({ success: true, data: { ...payload, lock_version: lockVersion } }),
  } as unknown as Response;
}

function mockStatus(status: number, body: any = {}) {
  return {
    ok: false,
    status,
    json: async () => body,
  } as unknown as Response;
}

describe('useLayoutDocument — golden save', () => {
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchMock = vi.fn();
    fetchMock
      .mockResolvedValueOnce(initialLoadResponse as any)
      .mockResolvedValueOnce(mockOk({ ok: true }, 8));
    (globalThis as any).fetch = fetchMock;
  });

  afterEach(() => {
    delete (globalThis as any).fetch;
  });

  it('PATCH → save(200) → newLockVersion 갱신 + dirty=false', async () => {
    const { result } = renderHook(() => useLayoutDocument(), { wrapper });

    await waitFor(() => expect(result.current.document).not.toBeNull());
    expect(result.current.document?.lockVersion).toBe(7);

    act(() => {
      result.current.patchLayout((cur) => [...cur, { name: 'Button', type: 'basic' }]);
    });
    expect(result.current.isDirty).toBe(true);

    let saveResult: any;
    await act(async () => {
      saveResult = await result.current.save();
    });
    expect(saveResult.kind).toBe('success');
    expect(saveResult.newLockVersion).toBe(8);
    expect(result.current.isDirty).toBe(false);
    expect(result.current.document?.lockVersion).toBe(8);
  });
});

describe('useLayoutDocument — 409 Conflict', () => {
  beforeEach(() => {
    const fetchMock = vi.fn();
    fetchMock
      .mockResolvedValueOnce(initialLoadResponse as any)
      .mockResolvedValueOnce(
        mockStatus(409, {
          error: 'concurrent_modification',
          current_version: 9,
          your_version: 7,
        })
      );
    (globalThis as any).fetch = fetchMock;
  });

  afterEach(() => {
    delete (globalThis as any).fetch;
  });

  it('save 응답이 409 면 concurrent_modification 결과 + 버전 정보 전달', async () => {
    const { result } = renderHook(() => useLayoutDocument(), { wrapper });
    await waitFor(() => expect(result.current.document).not.toBeNull());

    let saveResult: any;
    await act(async () => {
      saveResult = await result.current.save();
    });
    expect(saveResult).toEqual({
      kind: 'concurrent_modification',
      currentVersion: 9,
      yourVersion: 7,
    });
  });
});

describe('useLayoutDocument — 422 Validation', () => {
  beforeEach(() => {
    const fetchMock = vi.fn();
    fetchMock
      .mockResolvedValueOnce(initialLoadResponse as any)
      .mockResolvedValueOnce(
        mockStatus(422, {
          message: 'validation failed',
          errors: { content: ['invalid layout'] },
        })
      );
    (globalThis as any).fetch = fetchMock;
  });

  afterEach(() => {
    delete (globalThis as any).fetch;
  });

  it('save 응답이 422 면 validation_failed + errors 전달', async () => {
    const { result } = renderHook(() => useLayoutDocument(), { wrapper });
    await waitFor(() => expect(result.current.document).not.toBeNull());

    let saveResult: any;
    await act(async () => {
      saveResult = await result.current.save();
    });
    expect(saveResult.kind).toBe('validation_failed');
    expect(saveResult.status).toBe(422);
    expect(saveResult.errors).toEqual({ content: ['invalid layout'] });
  });
});

describe('useLayoutDocument — 활성 확장 재검증 가드', () => {
  beforeEach(() => {
    const fetchMock = vi.fn();
    // 비활성 확장 컴포넌트가 신규 노드로 들어가 있는 문서를 초기 로드
    fetchMock.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({
        success: true,
        data: {
          components: [
            {
              name: 'X',
              type: 'composite',
              __source: { kind: 'extension', extensionId: 'sirsoft-shop' },
            },
          ],
          lock_version: 1,
        },
      }),
    } as any);
    (globalThis as any).fetch = fetchMock;
  });

  afterEach(() => {
    delete (globalThis as any).fetch;
  });

  it('sessionAddedPaths 가 비활성 확장 노드를 가리키면 save 가 차단되고 PUT 미호출', async () => {
    const { result } = renderHook(() => useLayoutDocument(), { wrapper });
    await waitFor(() => expect(result.current.document).not.toBeNull());

    let saveResult: any;
    await act(async () => {
      saveResult = await result.current.save({
        sessionAddedPaths: ['0'],
        resolveActiveExtensions: async () => ({
          moduleIds: ['sirsoft-board'], // sirsoft-shop 비활성
          pluginIds: [],
        }),
      });
    });

    expect(saveResult.kind).toBe('blocked_inactive_extension');
    expect(saveResult.blockedPaths).toEqual(['0']);
    // 가드가 차단해 PUT 은 호출되지 않음 → fetch 호출 총 1 (초기 로드만)
    expect((globalThis as any).fetch).toHaveBeenCalledTimes(1);
  });

  it('활성 확장이면 통과', async () => {
    (globalThis as any).fetch.mockResolvedValueOnce(mockOk({ ok: true }, 2));

    const { result } = renderHook(() => useLayoutDocument(), { wrapper });
    await waitFor(() => expect(result.current.document).not.toBeNull());

    let saveResult: any;
    await act(async () => {
      saveResult = await result.current.save({
        sessionAddedPaths: ['0'],
        resolveActiveExtensions: async () => ({
          moduleIds: ['sirsoft-shop'],
          pluginIds: [],
        }),
      });
    });
    expect(saveResult.kind).toBe('success');
  });
});

describe('useLayoutDocument — 네트워크 실패', () => {
  beforeEach(() => {
    const fetchMock = vi.fn();
    fetchMock
      .mockResolvedValueOnce(initialLoadResponse as any)
      .mockRejectedValueOnce(new Error('network down'));
    (globalThis as any).fetch = fetchMock;
  });

  afterEach(() => {
    delete (globalThis as any).fetch;
  });

  it('save 중 fetch reject → kind=network_error + message 전달', async () => {
    const { result } = renderHook(() => useLayoutDocument(), { wrapper });
    await waitFor(() => expect(result.current.document).not.toBeNull());

    let saveResult: any;
    await act(async () => {
      saveResult = await result.current.save();
    });
    expect(saveResult.kind).toBe('network_error');
    expect(saveResult.message).toBe('network down');
  });
});

describe('useLayoutDocument — 중첩 경로 layoutName PUT URL 슬래시 보존', () => {
  // 회귀: layoutName(`auth/forgot_password`) 이 PUT URL 에서 encodeURIComponent 로
  // `auth%2Fforgot_password` 인코딩되면 Laravel/Apache 가 `%2F` 를 단일 segment 로
  // 인식 못해 404. GET 로드(line 160) 는 슬래시 raw 삽입으로 정상이나 save(PUT) 만
  // 인코딩해 홈(슬래시 없음)에서만 통과하고 중첩 경로에서 404 발생.
  function nestedWrapper({ children }: { children: React.ReactNode }): React.ReactElement {
    return (
      <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale="ko">
        <NestedRouteSeeder>{children}</NestedRouteSeeder>
      </LayoutEditorProvider>
    );
  }
  function NestedRouteSeeder({ children }: { children: React.ReactNode }): React.ReactElement {
    const { dispatch } = useLayoutEditor();
    React.useEffect(() => {
      dispatch({
        type: 'SELECT_ROUTE',
        route: { path: '/forgot-password', layoutName: 'auth/forgot_password' },
      });
    }, [dispatch]);
    return <>{children}</>;
  }

  let fetchMock: ReturnType<typeof vi.fn>;
  beforeEach(() => {
    fetchMock = vi.fn();
    fetchMock
      .mockResolvedValueOnce(initialLoadResponse as any)
      .mockResolvedValueOnce(mockOk({ ok: true }, 8));
    (globalThis as any).fetch = fetchMock;
  });
  afterEach(() => {
    delete (globalThis as any).fetch;
  });

  it('PUT URL 이 슬래시를 인코딩하지 않고 raw 로 보존 (auth%2F... 금지)', async () => {
    const { result } = renderHook(() => useLayoutDocument(), { wrapper: nestedWrapper });
    await waitFor(() => expect(result.current.document).not.toBeNull());

    act(() => {
      result.current.patchLayout((cur) => [...cur, { name: 'Button', type: 'basic' }]);
    });

    let saveResult: any;
    await act(async () => {
      saveResult = await result.current.save();
    });
    expect(saveResult.kind).toBe('success');

    // 2번째 fetch 호출 = PUT 저장
    const putCall = fetchMock.mock.calls[1];
    const putUrl = String(putCall[0]);
    expect(putCall[1]?.method).toBe('PUT');
    // 슬래시가 raw 로 보존되어야 함 (GET 로드 경로와 동일 규약)
    expect(putUrl).toContain('/layouts/auth/forgot_password');
    expect(putUrl).not.toContain('auth%2Fforgot_password');
  });
});
