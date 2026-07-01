/**
 * useLayoutDocument 회귀 테스트
 *
 * 계획서 가 약속한 테스트 파일이 누락되어 있어 본 세션에서 신설.
 * 로드/에러/dirty/reload 4 동작을 가드.
 */

import { describe, it, expect, beforeEach, vi } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import React from 'react';
import {
  useLayoutDocument,
  extractModalIdFromPath,
  extractIterationSourcePath,
} from '../../hooks/useLayoutDocument';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';

function makeWrapper(): React.FC<{ children: React.ReactNode }> {
  return ({ children }) =>
    React.createElement(
      LayoutEditorProvider,
      { templateIdentifier: 'sirsoft-basic', initialLocale: 'ko' },
      children,
    );
}

function combinedHook() {
  // useLayoutDocument 와 reducer dispatch 를 같은 wrapper 안에서 동시 노출
  const editor = useLayoutEditor();
  const document = useLayoutDocument();
  return { editor, document };
}

describe('useLayoutDocument', () => {
  let fetchSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchSpy = vi.fn();
    (global as any).fetch = fetchSpy;
    if (typeof window !== 'undefined') {
      window.localStorage?.clear();
    }
  });

  it('selectedRoute 가 null 이면 fetch 미발생 + document=null', async () => {
    fetchSpy.mockResolvedValue({
      ok: true,
      json: async () => ({ data: { components: [] } }),
    });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    await waitFor(() => {
      expect(result.current.document.isLoading).toBe(false);
    });

    expect(fetchSpy).not.toHaveBeenCalled();
    expect(result.current.document.document).toBeNull();
    expect(result.current.document.error).toBeNull();
    expect(result.current.document.isDirty).toBe(false);
  });

  it('SELECT_ROUTE 후 with_source_meta=1 쿼리 포함된 URL 로 fetch 발생', async () => {
    fetchSpy.mockResolvedValue({
      ok: true,
      json: async () => ({ data: { components: [{ id: 'root' }] } }),
    });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    act(() => {
      result.current.editor.dispatch({
        type: 'SELECT_ROUTE',
        route: { path: '/', layoutName: 'home' },
      });
    });

    await waitFor(() => {
      expect(result.current.document.isLoading).toBe(false);
      expect(result.current.document.document).not.toBeNull();
    });

    expect(fetchSpy).toHaveBeenCalledTimes(1);
    const url = fetchSpy.mock.calls[0][0] as string;
    expect(url).toContain('/api/layouts/sirsoft-basic/home.json');
    expect(url).toContain('with_source_meta=1');
    expect(result.current.document.document?.layoutName).toBe('home');
    expect((result.current.document.document?.raw as any).components[0].id).toBe('root');
  });

  it('Sanctum 토큰이 있으면 Authorization Bearer 헤더 부착', async () => {
    if (typeof window !== 'undefined') {
      window.localStorage.setItem('auth_token', 'test-token-xyz');
    }
    fetchSpy.mockResolvedValue({
      ok: true,
      json: async () => ({ data: {} }),
    });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    act(() => {
      result.current.editor.dispatch({
        type: 'SELECT_ROUTE',
        route: { path: '/', layoutName: 'home' },
      });
    });

    await waitFor(() => {
      expect(fetchSpy).toHaveBeenCalled();
    });

    const headers = fetchSpy.mock.calls[0][1]?.headers ?? {};
    expect(headers.Authorization).toBe('Bearer test-token-xyz');
  });

  it('403 응답 → error.kind=forbidden + status + 백엔드 메시지 + 필요 권한 보존', async () => {
    fetchSpy.mockResolvedValue({
      ok: false,
      status: 403,
      statusText: 'Forbidden',
      json: async () => ({
        message: '권한이 없습니다',
        data: { required_permissions: 'core.templates.layouts.edit' },
      }),
    });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    act(() => {
      result.current.editor.dispatch({
        type: 'SELECT_ROUTE',
        route: { path: '/', layoutName: 'home' },
      });
    });

    await waitFor(() => {
      expect(result.current.document.error).not.toBeNull();
    });
    const err = result.current.document.error!;
    expect(err.kind).toBe('forbidden');
    expect(err.status).toBe(403);
    expect(err.message).toBe('권한이 없습니다');
    expect(err.requiredPermissions).toBe('core.templates.layouts.edit');
    expect(err.source).toBe('layout');
    expect(result.current.document.document).toBeNull();
  });

  it('401 응답 → error.kind=unauthorized + 필요 권한 보존 (세션 만료 분기)', async () => {
    fetchSpy.mockResolvedValue({
      ok: false,
      status: 401,
      statusText: 'Unauthorized',
      json: async () => ({
        message: '로그인이 필요한 레이아웃입니다.',
        data: { required_permissions: 'core.templates.layouts.edit' },
      }),
    });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    act(() => {
      result.current.editor.dispatch({
        type: 'SELECT_ROUTE',
        route: { path: '/', layoutName: 'home' },
      });
    });

    await waitFor(() => {
      expect(result.current.document.error).not.toBeNull();
    });
    const err = result.current.document.error!;
    expect(err.kind).toBe('unauthorized');
    expect(err.status).toBe(401);
    expect(err.requiredPermissions).toBe('core.templates.layouts.edit');
  });

  it('404 응답 → error.kind=not_found', async () => {
    fetchSpy.mockResolvedValue({
      ok: false,
      status: 404,
      statusText: 'Not Found',
      json: async () => ({ message: '레이아웃을 찾을 수 없습니다' }),
    });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    act(() => {
      result.current.editor.dispatch({
        type: 'SELECT_ROUTE',
        route: { path: '/', layoutName: 'home' },
      });
    });

    await waitFor(() => {
      expect(result.current.document.error?.kind).toBe('not_found');
    });
  });

  it('500 응답 → error.kind=server_error', async () => {
    fetchSpy.mockResolvedValue({
      ok: false,
      status: 500,
      statusText: 'Internal Server Error',
      json: async () => ({ message: '서버 오류' }),
    });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    act(() => {
      result.current.editor.dispatch({
        type: 'SELECT_ROUTE',
        route: { path: '/', layoutName: 'home' },
      });
    });

    await waitFor(() => {
      expect(result.current.document.error?.kind).toBe('server_error');
    });
  });

  it('fetch reject → error.kind=network', async () => {
    fetchSpy.mockRejectedValue(new Error('connection refused'));

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    act(() => {
      result.current.editor.dispatch({
        type: 'SELECT_ROUTE',
        route: { path: '/', layoutName: 'home' },
      });
    });

    await waitFor(() => {
      expect(result.current.document.error?.kind).toBe('network');
    });
    expect(result.current.document.error?.message).toContain('connection refused');
  });

  it('슬래시 포함 layoutName(`auth/login`) 도 fetch URL 에 그대로 path segment 로 들어가야 함 (%2F 인코딩 금지)', async () => {
    // 회귀 시그널: 슬래시가 `%2F` 로 인코딩되면 Laravel 라우터의 segment
    // 경계 검출이 깨져 404 반환. 정공 패턴인 `LayoutLoader.ts:742` 는
    // encodeURIComponent 미사용 — `${templateId}/${layoutPath}.json` 그대로.
    fetchSpy.mockResolvedValue({
      ok: true,
      json: async () => ({ data: { components: [] } }),
    });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    act(() => {
      result.current.editor.dispatch({
        type: 'SELECT_ROUTE',
        route: { path: '/auth/login', layoutName: 'auth/login' },
      });
    });

    await waitFor(() => {
      expect(fetchSpy).toHaveBeenCalled();
    });

    const url = fetchSpy.mock.calls[0][0] as string;
    // 결정적 가드: `%2F` 가 등장하면 안 됨 (디코딩된 슬래시로 들어가야 함)
    expect(url).not.toContain('%2F');
    expect(url).not.toContain('%2f');
    // segment 경계가 살아 있어야 함
    expect(url).toContain('/api/layouts/sirsoft-basic/auth/login.json');
  });

  it('reload() 호출 시 fetch 재발생', async () => {
    fetchSpy.mockResolvedValue({
      ok: true,
      json: async () => ({ data: { components: [] } }),
    });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    act(() => {
      result.current.editor.dispatch({
        type: 'SELECT_ROUTE',
        route: { path: '/', layoutName: 'home' },
      });
    });

    await waitFor(() => {
      expect(fetchSpy).toHaveBeenCalledTimes(1);
    });

    await act(async () => {
      await result.current.document.reload();
    });

    expect(fetchSpy).toHaveBeenCalledTimes(2);
  });

  // 버전 복원 캐시-버스트 회귀 — 버전 기록 복원은 onRestored 에서 reload() 를 호출하는데, 종전엔
  // reload 가 캐시-버스트 nonce 를 올리지 않아 fetch URL `?v=<ver>.<nonce>` 이 복원 전과 동일 →
  // 브라우저 HTTP 캐시가 stale 응답을 줘 캔버스가 복원 버전으로 갱신되지 않고 새로고침해야 했다
  // reload 는 항상 nonce 를 올려 새 URL 로 fetch 한다.
  it('reload() 시 fetch URL 의 cache-bust nonce 가 증가 (버전 복원 stale 회피)', async () => {
    fetchSpy.mockResolvedValue({
      ok: true,
      json: async () => ({ data: { components: [] } }),
    });
    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    act(() => {
      result.current.editor.dispatch({ type: 'SELECT_ROUTE', route: { path: '/', layoutName: 'home' } });
    });
    await waitFor(() => expect(fetchSpy).toHaveBeenCalledTimes(1));

    const nonceOf = (u: string): number => {
      const m = /[?&]v=[^.]*\.(\d+)/.exec(u);
      return m ? Number(m[1]) : -1;
    };
    const firstUrl = String(fetchSpy.mock.calls[0][0]);

    await act(async () => {
      await result.current.document.reload();
    });
    const reloadUrl = String(fetchSpy.mock.calls[fetchSpy.mock.calls.length - 1][0]);

    // 두 URL 모두 `?v=<ver>.<nonce>` 형태 + reload 후 nonce 가 증가(다른 URL → HTTP 캐시 우회).
    expect(nonceOf(reloadUrl)).toBeGreaterThan(nonceOf(firstUrl));
    expect(reloadUrl).not.toBe(firstUrl);
  });
});

// ============================================================================
// 8 — 라우트 전환 세션 캐시 + dirty 키 + reload/초기화
// ============================================================================
describe('useLayoutDocument — 세션 편집 캐시', () => {
  let fetchSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchSpy = vi.fn();
    (global as any).fetch = fetchSpy;
    if (typeof window !== 'undefined') window.localStorage?.clear();
  });

  function selectRoute(result: any, path: string, layoutName: string) {
    act(() => {
      result.current.editor.dispatch({ type: 'SELECT_ROUTE', route: { path, layoutName } });
    });
  }

  it('A 편집(dirty) → B 이동 → A 복귀 시 캐시 복원(재fetch 없음) + dirty 유지', async () => {
    fetchSpy.mockImplementation(async (url: string) => ({
      ok: true,
      json: async () => ({
        data: { components: [{ id: url.includes('/home.') ? 'home-root' : 'about-root' }], lock_version: 3 },
      }),
    }));

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    selectRoute(result, '/', 'home');
    await waitFor(() => expect(result.current.document.document?.layoutName).toBe('home'));
    expect(fetchSpy).toHaveBeenCalledTimes(1);

    act(() => {
      result.current.document.patchLayout((cur) => [...cur, { name: 'Div', id: 'added' } as any]);
    });
    expect(result.current.document.isDirty).toBe(true);
    expect(result.current.document.dirtyLayoutNames.has('home')).toBe(true);

    selectRoute(result, '/about', 'about');
    await waitFor(() => expect(result.current.document.document?.layoutName).toBe('about'));
    expect(fetchSpy).toHaveBeenCalledTimes(2);
    expect(result.current.document.isDirty).toBe(false);

    selectRoute(result, '/', 'home');
    await waitFor(() => expect(result.current.document.document?.layoutName).toBe('home'));
    expect(fetchSpy).toHaveBeenCalledTimes(2); // 재fetch 없음 — 캐시 복원
    expect(result.current.document.isDirty).toBe(true);
    const comps = (result.current.document.document?.raw as any).components as any[];
    expect(comps.some((c) => c.id === 'added')).toBe(true);
  });

  it('reload(초기화) → 캐시 무시하고 서버 재fetch + dirty 키 해제 + reloadCounter 증가', async () => {
    fetchSpy.mockResolvedValue({
      ok: true,
      json: async () => ({ data: { components: [{ id: 'srv' }], lock_version: 1 } }),
    });
    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    selectRoute(result, '/', 'home');
    await waitFor(() => expect(result.current.document.document?.layoutName).toBe('home'));

    act(() => {
      result.current.document.patchLayout((cur) => [...cur, { name: 'Div', id: 'x' } as any]);
    });
    expect(result.current.document.dirtyLayoutNames.has('home')).toBe(true);
    const beforeReloadCounter = result.current.document.reloadCounter;

    await act(async () => {
      await result.current.document.reload();
    });

    expect(fetchSpy).toHaveBeenCalledTimes(2);
    expect(result.current.document.isDirty).toBe(false);
    expect(result.current.document.dirtyLayoutNames.has('home')).toBe(false);
    expect(result.current.document.reloadCounter).toBe(beforeReloadCounter + 1);
    const comps = (result.current.document.document?.raw as any).components as any[];
    expect(comps.some((c) => c.id === 'x')).toBe(false);
  });

  it('저장 성공 → dirty 키 해제(트리 배지 사라짐)', async () => {
    fetchSpy.mockImplementation(async (_url: string, init?: RequestInit) => {
      if (init?.method === 'PUT') {
        return { ok: true, json: async () => ({ data: { lock_version: 2 } }) };
      }
      return { ok: true, json: async () => ({ data: { components: [], lock_version: 1 } }) };
    });
    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    selectRoute(result, '/', 'home');
    await waitFor(() => expect(result.current.document.document?.layoutName).toBe('home'));

    act(() => {
      result.current.document.patchLayout((cur) => [...cur, { name: 'Div' } as any]);
    });
    expect(result.current.document.dirtyLayoutNames.has('home')).toBe(true);

    await act(async () => {
      await result.current.document.save();
    });
    expect(result.current.document.isDirty).toBe(false);
    expect(result.current.document.dirtyLayoutNames.has('home')).toBe(false);
  });

  it('저장 성공 응답의 current_version 을 SaveResult 로 반환 (트리 버전 배지 동기화)', async () => {
    fetchSpy.mockImplementation(async (_url: string, init?: RequestInit) => {
      if (init?.method === 'PUT') {
        return { ok: true, json: async () => ({ data: { lock_version: 2, current_version: 9 } }) };
      }
      return { ok: true, json: async () => ({ data: { components: [], lock_version: 1 } }) };
    });
    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    selectRoute(result, '/', 'home');
    await waitFor(() => expect(result.current.document.document?.layoutName).toBe('home'));

    act(() => {
      result.current.document.patchLayout((cur) => [...cur, { name: 'Div' } as any]);
    });

    let saveResult: any = null;
    await act(async () => {
      saveResult = await result.current.document.save();
    });
    expect(saveResult.kind).toBe('success');
    expect(saveResult.newContentVersion).toBe(9);
    expect(saveResult.savedLayoutName).toBe('home');
  });

  it('저장 성공 응답에 current_version 이 없으면(구버전 백엔드) newContentVersion=undefined 로 디그레이드', async () => {
    fetchSpy.mockImplementation(async (_url: string, init?: RequestInit) => {
      if (init?.method === 'PUT') {
        return { ok: true, json: async () => ({ data: { lock_version: 2 } }) };
      }
      return { ok: true, json: async () => ({ data: { components: [], lock_version: 1 } }) };
    });
    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    selectRoute(result, '/', 'home');
    await waitFor(() => expect(result.current.document.document?.layoutName).toBe('home'));

    act(() => {
      result.current.document.patchLayout((cur) => [...cur, { name: 'Div' } as any]);
    });

    let saveResult: any = null;
    await act(async () => {
      saveResult = await result.current.document.save();
    });
    expect(saveResult.kind).toBe('success');
    expect(saveResult.newContentVersion).toBeUndefined();
  });
});

// ============================================================================
// 모달 편집 모드 저장 격리 (modals[i] 패치)
// ============================================================================
describe('extractModalIdFromPath', () => {
  it('__modal__/{modalId} 에서 modalId 추출 (reducer/matchStateScope 와 동일 형식)', () => {
    expect(extractModalIdFromPath('__modal__/login')).toBe('login');
    expect(extractModalIdFromPath('__modal__/delete_confirm_modal')).toBe('delete_confirm_modal');
  });

  it('형식 불일치 / null → null', () => {
    expect(extractModalIdFromPath('/about')).toBeNull();
    expect(extractModalIdFromPath('__base__/_user_base')).toBeNull();
    // 2세그먼트(예상 외 형식) 거부 — 단일 세그먼트만 허용.
    expect(extractModalIdFromPath('__modal__/host/modal')).toBeNull();
    expect(extractModalIdFromPath('__modal__/')).toBeNull();
    expect(extractModalIdFromPath(null)).toBeNull();
    expect(extractModalIdFromPath(undefined)).toBeNull();
  });
});

describe('extractIterationSourcePath', () => {
  it('__iteration__/{sourcePath} 에서 sourcePath 추출', () => {
    expect(extractIterationSourcePath('__iteration__/0.children.2')).toBe('0.children.2');
    expect(extractIterationSourcePath('__iteration__/2.children.5.children.0')).toBe(
      '2.children.5.children.0',
    );
  });
  it('형식 불일치 → null', () => {
    expect(extractIterationSourcePath('/about')).toBeNull();
    expect(extractIterationSourcePath('__modal__/x')).toBeNull();
    expect(extractIterationSourcePath('__iteration__/')).toBeNull();
    expect(extractIterationSourcePath(null)).toBeNull();
  });
});

describe('useLayoutDocument — 반복 항목 편집 저장 격리', () => {
  let fetchSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchSpy = vi.fn();
    (global as any).fetch = fetchSpy;
    if (typeof window !== 'undefined') window.localStorage?.clear();
  });

  // 호스트 레이아웃: components[1] 에 iteration 원본 노드(children=항목 템플릿), 그 외 보존 대상.
  const hostLayout = {
    components: [
      { id: 'header', type: 'basic', name: 'Div' },
      {
        id: 'list',
        type: 'basic',
        name: 'Div',
        iteration: { source: 'items', item_var: 'item' },
        children: [{ id: 'item-tpl', type: 'basic', name: 'Span' }],
      },
    ],
    data_sources: [{ id: 'ds1' }],
    lock_version: 7,
  };

  function enterIteration(result: any, sourcePath: string) {
    // route 선택(layoutName 보유) 후 iteration 진입 — layoutName 이 유지돼 호스트 로드.
    act(() => {
      result.current.editor.dispatch({
        type: 'SELECT_ROUTE',
        route: { path: '/admin/settings', layoutName: 'admin_settings' },
      });
    });
    act(() => {
      result.current.editor.dispatch({ type: 'ENTER_ITERATION_ITEM_EDIT', sourcePath });
    });
  }

  it('iteration 모드 로드 → 원본 노드 children(항목 템플릿) 단독 노출 + iterationContext', async () => {
    fetchSpy.mockResolvedValue({ ok: true, json: async () => ({ data: hostLayout }) });
    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterIteration(result, '1'); // components[1] = list

    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    const doc = result.current.document.document!;
    // raw.components = 호스트 전체(header + list). 조각 단독 아님.
    expect((doc.raw as any).components).toHaveLength(2);
    expect((doc.raw as any).components[0].id).toBe('header');
    expect((doc.raw as any).components[1].id).toBe('list');
    // 편집 대상 = iteration 원본 노드(list, 항목 템플릿은 그 children).
    expect((doc.raw as any).components[1].children[0].id).toBe('item-tpl');
    expect(doc.iterationContext?.sourcePath).toBe('1');
    expect(doc.iterationContext?.sourceIndexPath).toEqual([1]);
    expect((doc.iterationContext?.hostRaw as any).components[0].id).toBe('header');
  });

  it('iteration 저장 → 호스트 PUT, 원본 노드 children 만 갱신, iteration 정의/본체/data_sources 보존', async () => {
    fetchSpy.mockResolvedValue({ ok: true, json: async () => ({ data: hostLayout }) });
    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterIteration(result, '1');
    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    // 캔버스 components = 호스트 전체. iteration 원본 노드(list, [1])의
    // children(항목 템플릿)만 편집. 저장 시 그 노드 children 이 modals 처럼 호스트로 반영된다.
    act(() => {
      result.current.document.setLayoutComponents([
        { id: 'header', type: 'basic', name: 'Div' } as any,
        {
          id: 'list',
          type: 'basic',
          name: 'Div',
          iteration: { source: 'items', item_var: 'item' },
          children: [{ id: 'item-tpl-edited', type: 'basic', name: 'Span' }],
        } as any,
      ]);
    });

    fetchSpy.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({ data: { lock_version: 8 } }),
    });
    await act(async () => {
      await result.current.document.save();
    });

    const putCall = fetchSpy.mock.calls.find((c) => c[1]?.method === 'PUT')!;
    expect(putCall[0]).toContain('/layouts/admin_settings');
    const body = JSON.parse(putCall[1].body);
    const savedContent = JSON.parse(body.content);

    // 원본 노드(components[1]) children 만 편집분으로 갱신.
    expect(savedContent.components[1].children[0].id).toBe('item-tpl-edited');
    // iteration 정의 보존(전 인스턴스 1개 템플릿 반영 핵심).
    expect(savedContent.components[1].iteration).toEqual({ source: 'items', item_var: 'item' });
    // 본체(header) / data_sources 보존.
    expect(savedContent.components[0].id).toBe('header');
    expect(savedContent.data_sources[0].id).toBe('ds1');
    // 호스트 lock_version 으로 낙관적 잠금.
    expect(body.expected_lock_version).toBe(7);
  });

  // 편집 모드 저장 후 편집 종료(route 복귀) 시 호스트 화면에 변경분이 반영되어야 한다.
  // 저장은 그 호스트 layoutName 의 route 모드 캐시도 stale 로 만들고, 클라이언트는 부팅 시점
  // cache_version 만 알아 GET 이 옛 URL 로 stale 응답을 받을 수 있었다. 수정 후엔 (1) 호스트
  // route 캐시가 비워져 서버 재fetch (2) GET URL `?v=` 에 단조 증가 nonce 가 붙어 신선 응답.
  it('iteration 저장 → 편집 종료 시 호스트 route 모드가 서버 재fetch + cache-bust nonce 증가', async () => {
    fetchSpy.mockResolvedValue({ ok: true, json: async () => ({ data: hostLayout }) });
    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });

    // (1) 먼저 route 모드로 호스트를 본다 → route|admin_settings 캐시 워밍 가능성.
    act(() => {
      result.current.editor.dispatch({
        type: 'SELECT_ROUTE',
        route: { path: '/admin/settings', layoutName: 'admin_settings' },
      });
    });
    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    // (2) 반복 항목 편집 진입.
    act(() => {
      result.current.editor.dispatch({ type: 'ENTER_ITERATION_ITEM_EDIT', sourcePath: '1' });
    });
    await waitFor(() => expect(result.current.document.document?.iterationContext).toBeTruthy());

    // (3) 편집 + 저장.
    act(() => {
      result.current.document.setLayoutComponents([
        { id: 'header', type: 'basic', name: 'Div' } as any,
        {
          id: 'list',
          type: 'basic',
          name: 'Div',
          iteration: { source: 'items', item_var: 'item' },
          children: [{ id: 'item-tpl-edited', type: 'basic', name: 'Span' }],
        } as any,
      ]);
    });
    fetchSpy.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({ data: { lock_version: 8 } }),
    });
    await act(async () => {
      await result.current.document.save();
    });

    // 저장 직후의 GET URL nonce 를 잡기 위해 이후 GET 응답을 새 호스트로 구분.
    const getUrlsBefore = fetchSpy.mock.calls
      .filter((c) => !c[1] || c[1].method !== 'PUT')
      .map((c) => String(c[0]));

    // (4) 편집 종료 → route 복귀. 호스트를 다시 fetch 해야 한다(stale 캐시 복원 아님).
    fetchSpy.mockResolvedValue({
      ok: true,
      json: async () => ({ data: { ...hostLayout, components: [{ id: 'header-v2' }] } }),
    });
    act(() => {
      result.current.editor.dispatch({ type: 'EXIT_ITERATION_ITEM_EDIT' });
    });

    // route 복귀 후 서버 재fetch 로 새 호스트(header-v2)가 반영되어야 한다(stale 'header' 아님).
    await waitFor(() => {
      const comps = (result.current.document.document?.raw as any)?.components;
      expect(comps?.[0]?.id).toBe('header-v2');
    });

    // 저장 후 GET URL 의 cache-bust nonce 가 저장 전보다 증가했는지 — `?v=<ver>.<nonce>` 형태.
    const getUrlsAfter = fetchSpy.mock.calls
      .filter((c) => !c[1] || c[1].method !== 'PUT')
      .map((c) => String(c[0]));
    const lastGet = getUrlsAfter[getUrlsAfter.length - 1];
    const firstGet = getUrlsBefore[0];
    const nonceOf = (u: string): number => {
      const m = u.match(/[?&]v=[\d]+\.(\d+)/);
      return m ? Number(m[1]) : -1;
    };
    expect(nonceOf(lastGet)).toBeGreaterThan(nonceOf(firstGet));
  });
});

describe('useLayoutDocument — 모달 편집 모드 저장 격리', () => {
  let fetchSpy: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchSpy = vi.fn();
    (global as any).fetch = fetchSpy;
    if (typeof window !== 'undefined') window.localStorage?.clear();
  });

  // 호스트 레이아웃 — 본체 components + data_sources + modals(2개).
  // 모달은 단일 컴포넌트 노드(`{id, type, name, props, children}` — 예: Modal composite).
  const hostLayout = {
    components: [{ id: 'host-body', type: 'basic', name: 'Div' }],
    data_sources: [{ id: 'ds1' }],
    modals: [
      {
        id: 'other_modal',
        type: 'composite',
        name: 'Modal',
        children: [{ id: 'other-c', type: 'basic', name: 'Span' }],
      },
      {
        id: 'delete_confirm_modal',
        type: 'composite',
        name: 'Modal',
        props: { title: '삭제 확인', isOpen: '{{modals.deleteConfirm}}' },
        children: [{ id: 'modal-c', type: 'basic', name: 'Button' }],
      },
    ],
    lock_version: 4,
  };

  function enterModal(result: any) {
    act(() => {
      result.current.editor.dispatch({
        type: 'ENTER_MODAL_EDIT',
        modalId: 'delete_confirm_modal',
        hostLayout: '_admin_base',
      });
    });
  }

  it('모달 모드 로드 → 호스트에서 modals[i] 조각 추출(raw.components = 모달 components) + modalContext 보유', async () => {
    fetchSpy.mockResolvedValue({ ok: true, json: async () => ({ data: hostLayout }) });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterModal(result);

    await waitFor(() => {
      expect(result.current.document.document).not.toBeNull();
    });

    const doc = result.current.document.document!;
    // raw.components = 호스트 전체(host-body) + 끝에 편집 대상 모달 append.
    expect((doc.raw as any).components).toHaveLength(2);
    expect((doc.raw as any).components[0].id).toBe('host-body');
    const appended = (doc.raw as any).components[1];
    expect(appended.id).toBe('delete_confirm_modal');
    expect(appended.children[0].id).toBe('modal-c');
    // 편집 표시용으로 isOpen=true 강제 (Modal 이 닫힘이면 null 렌더 → 빈 캔버스 방지).
    expect(appended.props.isOpen).toBe(true);
    expect(doc.modalContext?.modalId).toBe('delete_confirm_modal');
    expect(doc.modalContext?.modalIndex).toBe(1);
    // 인플레이스 모달 노드의 components 트리 인덱스 경로 = [1](append 위치).
    expect(doc.modalContext?.editIndexPath).toEqual([1]);
    // 원본 모달 노드는 isOpen 바인딩 보존.
    expect((doc.modalContext?.originalModalNode as any).props.isOpen).toBe('{{modals.deleteConfirm}}');
    // hostRaw 는 호스트 전체 보존.
    expect((doc.modalContext?.hostRaw as any).components[0].id).toBe('host-body');
  });

  it('모달 저장 → 호스트 레이아웃 PUT, modals[i].components 만 갱신하고 본체/타모달/data_sources 보존', async () => {
    fetchSpy.mockResolvedValue({ ok: true, json: async () => ({ data: hostLayout }) });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterModal(result);
    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    // 캔버스 components = 호스트(host-body) + 끝에 편집 대상 모달.
    // 모달 children 을 새 컴포넌트로 교체. props.isOpen=true 는 편집 표시용 강제값 — 저장 시
    // 원본 바인딩으로 복원되고, 모달은 components 가 아닌 modals[1] 로 되돌아가야 한다.
    act(() => {
      result.current.document.setLayoutComponents([
        { id: 'host-body', type: 'basic', name: 'Div' } as any,
        {
          id: 'delete_confirm_modal',
          type: 'composite',
          name: 'Modal',
          props: { title: '삭제 확인', isOpen: true },
          children: [{ id: 'modal-c-edited', type: 'basic', name: 'Button' }],
        } as any,
      ]);
    });

    // 저장 — PUT 응답 mock.
    fetchSpy.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({ data: { lock_version: 5 } }),
    });

    await act(async () => {
      await result.current.document.save();
    });

    // 마지막 fetch = PUT, URL 은 호스트 레이아웃(_admin_base).
    const putCall = fetchSpy.mock.calls.find((c) => c[1]?.method === 'PUT')!;
    expect(putCall[0]).toContain('/layouts/_admin_base');
    const body = JSON.parse(putCall[1].body);
    const savedContent = JSON.parse(body.content);

    // modals[1] 이 편집된 모달 노드로 갱신(children 교체) + 기타 키(props.title) 보존.
    expect(savedContent.modals[1].id).toBe('delete_confirm_modal');
    expect(savedContent.modals[1].children[0].id).toBe('modal-c-edited');
    expect(savedContent.modals[1].props.title).toBe('삭제 확인');
    // 편집 표시용 isOpen=true 가 원본 바인딩으로 복원(운영 content 무오염).
    expect(savedContent.modals[1].props.isOpen).toBe('{{modals.deleteConfirm}}');
    // 호스트 본체 / 타 모달 / data_sources 보존 (저장 격리 — 결함 15).
    expect(savedContent.components[0].id).toBe('host-body');
    expect(savedContent.modals[0].children[0].id).toBe('other-c');
    expect(savedContent.data_sources[0].id).toBe('ds1');
    // 호스트 lock_version 으로 낙관적 잠금.
    expect(body.expected_lock_version).toBe(4);
  });

  // 호스트 components 가 **base 출처**(extends 자식 레이아웃, 예 auth/register)일 때
  // 모달 편집 저장이 키화/편집분을 영속하지 못하던 결함. 진입 시 모달 노드를
  // `mergedComponents` 끝(editIndexPath=[N])에 append 하는데, 저장 추출이 호스트 전체를
  // 먼저 stripInheritedNodes 로 마스킹(=base 노드 제거 + slot 래퍼 children 끌어올림)한 뒤
  // editIndexPath[0] 인덱스로 모달 노드를 찾으려 했다. 마스킹이 배열 길이/인덱스를 재정렬해
  // editedComps[editIndexPath[0]] 이 모달 노드가 아닌 엉뚱한 route 자식(또는 undefined)을 가리켜
  // modals[i] 가 미갱신 → node.text 키화가 DB content 에 영속되지 않음.
  it('base 출처 호스트(extends)에서도 모달 편집분이 modals[i] 로 정확히 영속', async () => {
    // register 형: 호스트 components 가 전부 base 출처(_fromBase). slot 래퍼 Div 는 children 끌어올림.
    const baseHostLayout = {
      components: [
        { id: 'toast', type: 'composite', name: 'Toast', __source: { kind: 'base' }, _fromBase: true },
        {
          id: 'page-indicator',
          type: 'composite',
          name: 'PageTransitionIndicator',
          __source: { kind: 'base' },
          _fromBase: true,
        },
        {
          // slot 래퍼 base Div — 자체 제거 + route children 끌어올림.
          id: 'base-wrapper',
          type: 'basic',
          name: 'Div',
          __source: { kind: 'base' },
          _fromBase: true,
          // register 실측: slot 래퍼 base Div 가 route children 8개 보유 → 끌어올리면
          // 마스킹 후 모달 노드 위치가 8(editIndexPath[0]=3 과 어긋남).
          children: Array.from({ length: 8 }, (_, i) => ({
            id: `route-${i}`,
            type: 'basic',
            name: 'Div',
            __source: { kind: 'route' },
          })),
        },
      ],
      data_sources: [{ id: 'ds1' }],
      modals: [
        {
          id: 'other_modal',
          type: 'composite',
          name: 'Modal',
          children: [{ id: 'other-c', type: 'basic', name: 'Span' }],
        },
        {
          id: 'delete_confirm_modal',
          type: 'composite',
          name: 'Modal',
          props: { title: '삭제 확인', isOpen: '{{modals.deleteConfirm}}' },
          children: [{ id: 'modal-c', type: 'basic', name: 'Button' }],
        },
      ],
      lock_version: 7,
    };
    fetchSpy.mockResolvedValue({ ok: true, json: async () => ({ data: baseHostLayout }) });

    const { result } = renderHook(() => combinedHook(), { wrapper: makeWrapper() });
    enterModal(result);
    await waitFor(() => expect(result.current.document.document).not.toBeNull());

    const doc = result.current.document.document!;
    // 진입 시 모달 노드는 mergedComponents 끝(인덱스 3 = base3개 + 모달)에 append.
    expect((doc.raw as any).components).toHaveLength(4);
    expect(doc.modalContext?.editIndexPath).toEqual([3]);
    const appendedIdx = 3;
    expect((doc.raw as any).components[appendedIdx].id).toBe('delete_confirm_modal');

    // 캔버스에서 모달 children 을 편집(키화 시뮬레이션 — text 가 $t:custom 으로 치환된 노드).
    const editedComponents = [...(doc.raw as any).components];
    editedComponents[appendedIdx] = {
      ...editedComponents[appendedIdx],
      children: [
        { id: 'modal-c', type: 'basic', name: 'Span', text: '$t:custom.auth_register.36|p0={{x}}' },
      ],
    };
    act(() => {
      result.current.document.setLayoutComponents(editedComponents as any);
    });

    fetchSpy.mockResolvedValueOnce({
      ok: true,
      status: 200,
      json: async () => ({ data: { lock_version: 8 } }),
    });
    await act(async () => {
      await result.current.document.save();
    });

    const putCall = fetchSpy.mock.calls.find((c) => c[1]?.method === 'PUT')!;
    const savedContent = JSON.parse(JSON.parse(putCall[1].body).content);

    // 편집한 모달 노드(키화된 text)가 modals[1] 로 정확히 영속되어야 한다.
    expect(savedContent.modals[1].id).toBe('delete_confirm_modal');
    expect(savedContent.modals[1].children[0].text).toBe('$t:custom.auth_register.36|p0={{x}}');
    // isOpen 원본 바인딩 복원 + 다른 모달/마스킹된 호스트 보존.
    expect(savedContent.modals[1].props.isOpen).toBe('{{modals.deleteConfirm}}');
    expect(savedContent.modals[0].children[0].id).toBe('other-c');
    // base 노드는 마스킹으로 제거되고 slot 래퍼 children(route 8개)만 끌어올려 보존.
    expect(savedContent.components.map((c: any) => c.id)).toEqual(
      Array.from({ length: 8 }, (_, i) => `route-${i}`),
    );
  });
});
