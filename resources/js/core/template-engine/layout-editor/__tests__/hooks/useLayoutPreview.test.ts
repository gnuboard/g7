/**
 * useLayoutPreview.test.ts —
 *
 * 검증 대상: 실데이터 미리보기 hook
 *  - storePreview POST + Bearer + content 직렬화(마스킹)
 *  - 성공 시 openWindow(/preview/{token}) dispatch
 *  - layoutName/raw 없으면 no_document (fetch 미호출)
 *  - 실패 시 network_error
 *
 * @effects preview_creates_temp_record_and_opens_window_with_token, preview_no_document_when_no_layout_or_raw,
 *   version_and_preview_fetch_attach_bearer_token
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useLayoutPreview } from '../../hooks/useLayoutPreview';

beforeEach(() => {
  vi.stubGlobal('localStorage', {
    getItem: (k: string) => (k === 'auth_token' ? 'TESTTOKEN' : null),
    setItem: () => {},
    removeItem: () => {},
  } as unknown as Storage);
});

afterEach(() => {
  vi.restoreAllMocks();
});

const sampleRaw = {
  components: [{ type: 'Div', children: [{ type: 'Span', text: 'hi' }] }],
  __editor: { original: { components: [] } },
};

describe('useLayoutPreview', () => {
  it('성공 시 POST preview + Bearer + openWindow dispatch', async () => {
    const fetchFn = vi.fn(async () => ({
      ok: true,
      status: 200,
      json: async () => ({ success: true, data: { token: 'TKN', preview_url: '/preview/TKN' } }),
    }));
    vi.stubGlobal('fetch', fetchFn);
    const dispatch = vi.fn();
    vi.stubGlobal('window', { ...globalThis, G7Core: { dispatch } } as any);

    const { result } = renderHook(() => useLayoutPreview('sirsoft-basic', 'auth/login'));
    let res: any;
    await act(async () => {
      res = await result.current.createPreview(sampleRaw);
    });

    expect(res.kind).toBe('success');
    const [url, init] = fetchFn.mock.calls[0] as [string, RequestInit];
    expect(url).toBe('/api/admin/templates/sirsoft-basic/layouts/auth/login/preview');
    expect(init.method).toBe('POST');
    expect((init.headers as Record<string, string>).Authorization).toBe('Bearer TESTTOKEN');
    // body.content 는 마스킹된 content 의 JSON 직렬화 문자열
    const body = JSON.parse(init.body as string);
    expect(typeof body.content).toBe('string');
    expect(JSON.parse(body.content)).toHaveProperty('components');
    // openWindow dispatch
    expect(dispatch).toHaveBeenCalledWith(
      expect.objectContaining({ handler: 'openWindow', params: { path: '/preview/TKN' } }),
    );
  });

  it('layoutName 없으면 no_document (fetch 미호출)', async () => {
    const fetchFn = vi.fn();
    vi.stubGlobal('fetch', fetchFn);
    const { result } = renderHook(() => useLayoutPreview('sirsoft-basic', null));
    let res: any;
    await act(async () => {
      res = await result.current.createPreview(sampleRaw);
    });
    expect(res.kind).toBe('no_document');
    expect(fetchFn).not.toHaveBeenCalled();
  });

  it('raw 없으면 no_document', async () => {
    const fetchFn = vi.fn();
    vi.stubGlobal('fetch', fetchFn);
    const { result } = renderHook(() => useLayoutPreview('sirsoft-basic', 'home'));
    let res: any;
    await act(async () => {
      res = await result.current.createPreview(null);
    });
    expect(res.kind).toBe('no_document');
    expect(fetchFn).not.toHaveBeenCalled();
  });

  it('실패 시 network_error', async () => {
    vi.stubGlobal('fetch', vi.fn(async () => ({
      ok: false,
      status: 500,
      json: async () => ({ message: 'boom' }),
    })));
    vi.stubGlobal('window', { ...globalThis, G7Core: { dispatch: vi.fn() } } as any);
    const { result } = renderHook(() => useLayoutPreview('sirsoft-basic', 'home'));
    let res: any;
    await act(async () => {
      res = await result.current.createPreview(sampleRaw);
    });
    expect(res.kind).toBe('network_error');
    expect(res.message).toBe('boom');
  });
});
