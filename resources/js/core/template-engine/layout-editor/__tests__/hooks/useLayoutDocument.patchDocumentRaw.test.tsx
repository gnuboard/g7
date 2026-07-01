/**
 * useLayoutDocument.patchDocumentRaw.test.tsx —
 *
 * data_sources 등 최상위 구조 키 편집의 저장 생존 검증:
 *  - patchDocumentRaw 가 raw[key](merged) 와 __editor.original[key](own) 분리 기입
 *  - save 시 마스킹 골격이 __editor.original 을 쓰므로 PUT body 의
 *    data_sources 가 own(자체)만 포함 — 상속 소스/옛 값으로 덮어쓰지 않음
 *  - originalValue 미지정 시 value 를 양쪽에 동일 기입(상속 없는 독립 키)
 *
 * 시나리오 매니페스트: tests/scenarios/layout-editor-data-sources.yaml
 *
 * @effects patch_document_raw_writes_merged_to_toplevel_own_to_editor_original,
 *   save_put_body_data_sources_contains_own_only_no_inherited_no_stale,
 *   editor_meta_stripped_from_save_payload,
 *   new_source_appears_in_binding_picker_candidates_with_friendly_label
 *
 * @since engine-v1.50.0
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { renderHook, act, waitFor } from '@testing-library/react';
import React from 'react';
import { useLayoutDocument } from '../../hooks/useLayoutDocument';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';

function RouteSeeder({ children }: { children: React.ReactNode }): React.ReactElement {
  const { dispatch } = useLayoutEditor();
  React.useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path: '/', layoutName: 'home' } });
  }, [dispatch]);
  return <>{children}</>;
}

function wrapper({ children }: { children: React.ReactNode }): React.ReactElement {
  return (
    <LayoutEditorProvider templateIdentifier="test-tpl" initialLocale="ko">
      <RouteSeeder>{children}</RouteSeeder>
    </LayoutEditorProvider>
  );
}

// 편집 모드 응답 — components(merged, route 출처 메타) + __editor.original(자체 원본).
// data_sources: merged 에 상속(inh) + 자체(own), original 에 자체(own)만.
const loadResponse = {
  ok: true,
  status: 200,
  json: async () => ({
    success: true,
    data: {
      components: [{ name: 'Div', type: 'basic', __source: { kind: 'route' } }],
      data_sources: [
        { id: 'inh', endpoint: '/api/inh', __source: { kind: 'base' } },
        { id: 'products', endpoint: '/api/products', __source: { kind: 'route' } },
      ],
      __editor: {
        original: {
          extends: null,
          components: [{ name: 'Div', type: 'basic' }],
          data_sources: [{ id: 'products', endpoint: '/api/products' }],
        },
      },
      lock_version: 3,
    },
  }),
};

function mockOk(lockVersion = 4) {
  return {
    ok: true,
    status: 200,
    json: async () => ({ success: true, data: { lock_version: lockVersion } }),
  } as unknown as Response;
}

describe('useLayoutDocument.patchDocumentRaw — data_sources 저장 생존', () => {
  let fetchMock: ReturnType<typeof vi.fn>;

  beforeEach(() => {
    fetchMock = vi.fn();
    fetchMock.mockResolvedValueOnce(loadResponse as any).mockResolvedValueOnce(mockOk(4));
    (globalThis as any).fetch = fetchMock;
  });

  afterEach(() => {
    delete (globalThis as any).fetch;
  });

  it('merged → raw.data_sources, own → __editor.original.data_sources 분리 기입', async () => {
    const { result } = renderHook(() => useLayoutDocument(), { wrapper });
    await waitFor(() => expect(result.current.document).not.toBeNull());

    const newOwn = [
      { id: 'products', endpoint: '/api/products', label_key: '$t:editor.data_source.products' },
      { id: 'categories', endpoint: '/api/categories' },
    ];
    const merged = [{ id: 'inh', endpoint: '/api/inh', __source: { kind: 'base' } }, ...newOwn];

    act(() => {
      result.current.patchDocumentRaw('data_sources', merged, newOwn);
    });

    expect(result.current.isDirty).toBe(true);
    const raw = result.current.document!.raw as any;
    // 최상위 = merged (상속 보존)
    expect(raw.data_sources.map((d: any) => d.id)).toEqual(['inh', 'products', 'categories']);
    // original = own only (저장 골격)
    expect(raw.__editor.original.data_sources.map((d: any) => d.id)).toEqual(['products', 'categories']);
    expect(raw.__editor.original.data_sources[0].label_key).toBe('$t:editor.data_source.products');
  });

  it('save 시 PUT body 의 data_sources 가 own(자체)만 포함 — 상속/옛값 덮어쓰기 0', async () => {
    const { result } = renderHook(() => useLayoutDocument(), { wrapper });
    await waitFor(() => expect(result.current.document).not.toBeNull());

    const newOwn = [
      { id: 'products', endpoint: '/api/products', label_key: '$t:editor.data_source.products' },
      { id: 'categories', endpoint: '/api/categories', label_key: '$t:editor.data_source.categories' },
    ];
    const merged = [{ id: 'inh', endpoint: '/api/inh', __source: { kind: 'base' } }, ...newOwn];

    act(() => {
      result.current.patchDocumentRaw('data_sources', merged, newOwn);
    });

    await act(async () => {
      await result.current.save();
    });

    // 2번째 fetch = PUT save
    const putCall = fetchMock.mock.calls[1];
    const body = JSON.parse(putCall[1].body);
    const content = JSON.parse(body.content);
    // 마스킹 골격이 original 을 썼으므로 data_sources = 자체만(상속 inh 제외)
    expect(content.data_sources.map((d: any) => d.id)).toEqual(['products', 'categories']);
    expect(content.data_sources[0].label_key).toBe('$t:editor.data_source.products');
    // __editor 메타는 저장 페이로드에서 제거됨
    expect(content.__editor).toBeUndefined();
  });

  it('originalValue 미지정 시 value 를 양쪽에 동일 기입', async () => {
    const { result } = renderHook(() => useLayoutDocument(), { wrapper });
    await waitFor(() => expect(result.current.document).not.toBeNull());

    const list = [{ id: 'solo' }];
    act(() => {
      result.current.patchDocumentRaw('data_sources', list);
    });
    const raw = result.current.document!.raw as any;
    expect(raw.data_sources.map((d: any) => d.id)).toEqual(['solo']);
    expect(raw.__editor.original.data_sources.map((d: any) => d.id)).toEqual(['solo']);
  });
});
