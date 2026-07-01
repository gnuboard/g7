/**
 * usePageSettings.test.tsx — 페이지 설정 최상위 속성 로드/패치 훅
 *
 * 검증:
 *  - getValue: 최상위 키 읽기 + fallback
 *  - patch: patchDocumentRaw 위임(originalValue 유무 분기)
 *  - createI18nKey/updateI18nKeyValue: useInlineEdit 위임 + 미해석 가드(null)
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { usePageSettings } from '../../hooks/usePageSettings';
import { LayoutDocumentProvider } from '../../LayoutDocumentContext';
import { LayoutEditorProvider } from '../../LayoutEditorContext';
import type { UseLayoutDocumentResult } from '../../hooks/useLayoutDocument';

// useInlineEdit 의 createCustomKey/updateCustomKeyValue 는 fetch 를 수행하므로 모킹.
const createCustomKey = vi.fn(async () => ({ kind: 'ok', translationKey: '$t:custom.x' }));
const updateCustomKeyValue = vi.fn(async () => ({ kind: 'ok' }));
vi.mock('../../hooks/useInlineEdit', () => ({
  createCustomKey: (...args: unknown[]) => createCustomKey(...args),
  updateCustomKeyValue: (...args: unknown[]) => updateCustomKeyValue(...args),
}));

const patchDocumentRaw = vi.fn();

function makeDocValue(raw: Record<string, unknown>): UseLayoutDocumentResult {
  return {
    document: { raw } as UseLayoutDocumentResult['document'],
    patchDocumentRaw,
  } as unknown as UseLayoutDocumentResult;
}

function wrap(raw: Record<string, unknown>): React.FC<{ children: React.ReactNode }> {
  return ({ children }) => (
    <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale="ko">
      <LayoutDocumentProvider value={makeDocValue(raw)}>{children}</LayoutDocumentProvider>
    </LayoutEditorProvider>
  );
}

beforeEach(() => {
  patchDocumentRaw.mockClear();
  createCustomKey.mockClear();
  updateCustomKeyValue.mockClear();
});

describe('usePageSettings — getValue', () => {
  it('최상위 키를 읽고 미존재 시 fallback 을 반환한다', () => {
    const { result } = renderHook(() => usePageSettings(), {
      wrapper: wrap({ meta: { seo: { enabled: true } }, permissions: ['a'] }),
    });
    expect(result.current.getValue('permissions')).toEqual(['a']);
    expect(result.current.getValue('meta')).toEqual({ seo: { enabled: true } });
    expect(result.current.getValue('nope', 'fallback')).toBe('fallback');
  });
});

describe('usePageSettings — patch', () => {
  it('originalValue 미지정 시 2-인자 patchDocumentRaw 위임', () => {
    const { result } = renderHook(() => usePageSettings(), { wrapper: wrap({}) });
    result.current.patch('permissions', ['x']);
    expect(patchDocumentRaw).toHaveBeenCalledWith('permissions', ['x']);
  });

  it('originalValue 지정 시 3-인자 patchDocumentRaw 위임(무손실 라운드트립)', () => {
    const { result } = renderHook(() => usePageSettings(), { wrapper: wrap({}) });
    result.current.patch('init_actions', [{ handler: 'toast' }], []);
    expect(patchDocumentRaw).toHaveBeenCalledWith('init_actions', [{ handler: 'toast' }], []);
  });
});

describe('usePageSettings — i18n 위임', () => {
  it('createI18nKey 는 layoutName 없으면 null(미해석 가드)', async () => {
    // LayoutEditorProvider 초기 selectedRoute=null → layoutName 빈 문자열 → null.
    const { result } = renderHook(() => usePageSettings(), { wrapper: wrap({}) });
    const r = await result.current.createI18nKey('ko', '제목');
    expect(r).toBeNull();
    expect(createCustomKey).not.toHaveBeenCalled();
  });

  it('updateI18nKeyValue 는 templateIdentifier 가 있으면 useInlineEdit 에 위임', async () => {
    const { result } = renderHook(() => usePageSettings(), { wrapper: wrap({}) });
    await result.current.updateI18nKeyValue('$t:custom.x', 'ko', '새 값');
    // updateCustomKeyValue(templateIdentifier, customKey, locale, value) — 4-인자.
    expect(updateCustomKeyValue).toHaveBeenCalledWith('sirsoft-basic', '$t:custom.x', 'ko', '새 값');
  });
});
