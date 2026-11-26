/**
 * useLayoutDocument — `enabled:false` 면 레이아웃을 요청하지 않는다 (이월 5: 편집기 첫 적재)
 *
 * 배경: PreviewCanvas 는 문서 Provider(LayoutEditorChrome)가 있어도 레거시 호환용 `useLayoutDocument()` 를 늘 함께
 * 불러, 같은 레이아웃(`/api/layouts/{t}/{name}.json?with_source_meta=1`)을 두 번 받았다(실측: 각 6~7초, HEAD 부터 있던
 * 중복). 이제 Provider 가 있으면 PreviewCanvas 의 사본 훅은 `enabled:false` 로 요청하지 않는다.
 *
 * 효과 요약(마커 아님 — 평문, 시험별 표식에 있음): editor_layout_fetched_once_with_provider
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { renderHook, waitFor } from '@testing-library/react';
import { useLayoutDocument } from '../../hooks/useLayoutDocument';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';

function wrapper({ children }: { children: React.ReactNode }): React.ReactElement {
  return React.createElement(LayoutEditorProvider, { templateIdentifier: 'sirsoft-basic', initialLocale: 'ko', children });
}

afterEach(() => {
  vi.restoreAllMocks();
});

describe('useLayoutDocument enabled 옵션', () => {
  for (const enabled of [true, false]) {
    /** @effects editor_layout_fetched_once_with_provider */
    it(`enabled=${enabled}: 라우트를 고르면 ${enabled ? '요청한다' : '요청하지 않는다'}`, async () => {
      const fetchMock = vi.fn(async (_url: string) => ({ ok: true, status: 200, json: async () => ({ data: { components: [] } }) }));
      (globalThis as any).fetch = fetchMock;
      const { result } = renderHook(
        () => ({ doc: useLayoutDocument({ enabled }), editor: useLayoutEditor() }),
        { wrapper },
      );
      result.current.editor.dispatch({ type: 'SELECT_ROUTE', route: { path: '/', layoutName: 'home' } });
      await waitFor(() => {
        const layoutCalls = fetchMock.mock.calls.filter((c) => String(c[0]).includes('/api/layouts/'));
        expect(layoutCalls.length).toBe(enabled ? 1 : 0);
      });
      if (!enabled) {
        await new Promise((r) => setTimeout(r, 50));
        expect(fetchMock.mock.calls.filter((c) => String(c[0]).includes('/api/layouts/')).length).toBe(0);
      }
    });
  }
});
