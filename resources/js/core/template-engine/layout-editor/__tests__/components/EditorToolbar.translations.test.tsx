/**
 * EditorToolbar.translations.test.tsx —
 *
 * 🌐 다국어 버튼 회귀 가드:
 * - onManageTranslations 미제공 또는 라우트 미선택 시 disabled
 * - 라우트 선택 + onManageTranslations 제공 시 활성 + 클릭 시 호출
 */

import React, { useEffect } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { EditorToolbar } from '../../components/EditorToolbar';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

/** 마운트 시 라우트를 선택해 state.selectedRoute 를 채우는 하네스 */
function SelectRoute({ layoutName }: { layoutName: string }): null {
  const { dispatch } = useLayoutEditor();
  useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path: `/${layoutName}`, layoutName } });
  }, [dispatch, layoutName]);
  return null;
}

function wrap(node: React.ReactElement, withRoute = false): React.ReactElement {
  const engine = new TranslationEngine();
  return (
    <TranslationProvider
      translationEngine={engine}
      translationContext={{ templateId: 'sirsoft-basic', locale: 'ko' }}
    >
      <LayoutEditorProvider templateIdentifier="sirsoft-basic" initialLocale="ko">
        {withRoute ? <SelectRoute layoutName="home" /> : null}
        {node}
      </LayoutEditorProvider>
    </TranslationProvider>
  );
}

describe('EditorToolbar — 🌐 다국어 버튼', () => {
  it('onManageTranslations 미제공 시 disabled', () => {
    render(wrap(<EditorToolbar />, true));
    const btn = screen.getByTestId('g7le-toolbar-translations') as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
  });

  it('라우트 미선택 시 disabled (핸들러 제공해도)', () => {
    render(wrap(<EditorToolbar onManageTranslations={vi.fn()} />, false));
    const btn = screen.getByTestId('g7le-toolbar-translations') as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
  });

  it('라우트 선택 + 핸들러 제공 시 활성 + 클릭 시 호출', () => {
    const onManageTranslations = vi.fn();
    render(wrap(<EditorToolbar onManageTranslations={onManageTranslations} />, true));
    const btn = screen.getByTestId('g7le-toolbar-translations') as HTMLButtonElement;
    expect(btn.disabled).toBe(false);
    fireEvent.click(btn);
    expect(onManageTranslations).toHaveBeenCalledTimes(1);
  });
});
