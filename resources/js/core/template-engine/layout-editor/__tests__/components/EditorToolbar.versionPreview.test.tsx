/**
 * EditorToolbar.versionPreview.test.tsx —
 *
 * 👁 미리보기 / 🕘 버전 기록 버튼 회귀 가드:
 * - onPreview 미제공 또는 라우트 미선택(layoutName 없음) 시 disabled
 * - 라우트 선택 + onPreview 제공 시 활성 + 클릭 시 호출
 * - onShowVersions 동일 규칙
 * - 미리보기 Promise 진행 중 스피너 + disabled (재진입 방지)
 *
 * @effects preview_button_disabled_when_no_layout_name, preview_button_enabled_when_layout_selected,
 *   preview_button_shows_spinner_while_pending_then_restores, version_button_disabled_when_no_layout_name
 */

import React, { useEffect } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
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

describe('EditorToolbar — 👁 미리보기 버튼', () => {
  it('onPreview 미제공 시 disabled', () => {
    render(wrap(<EditorToolbar />, true));
    const btn = screen.getByTestId('g7le-toolbar-preview') as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
  });

  it('라우트 미선택 시 disabled (핸들러 제공해도)', () => {
    render(wrap(<EditorToolbar onPreview={vi.fn()} />, false));
    const btn = screen.getByTestId('g7le-toolbar-preview') as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
  });

  it('라우트 선택 + 핸들러 제공 시 활성 + 클릭 시 호출', () => {
    const onPreview = vi.fn();
    render(wrap(<EditorToolbar onPreview={onPreview} />, true));
    const btn = screen.getByTestId('g7le-toolbar-preview') as HTMLButtonElement;
    expect(btn.disabled).toBe(false);
    fireEvent.click(btn);
    expect(onPreview).toHaveBeenCalledTimes(1);
  });

  it('Promise 진행 중 스피너 표시 + disabled (재진입 방지)', async () => {
    let resolve: () => void = () => {};
    const onPreview = vi.fn(() => new Promise<void>((r) => { resolve = r; }));
    render(wrap(<EditorToolbar onPreview={onPreview} />, true));
    const btn = screen.getByTestId('g7le-toolbar-preview') as HTMLButtonElement;
    fireEvent.click(btn);
    await screen.findByTestId('g7le-toolbar-preview-spinner');
    expect(btn.disabled).toBe(true);
    // 진행 중 재클릭은 무시 (disabled)
    fireEvent.click(btn);
    expect(onPreview).toHaveBeenCalledTimes(1);
    resolve();
    await waitFor(() => expect(btn.disabled).toBe(false));
  });
});

describe('EditorToolbar — 🕘 버전 기록 버튼', () => {
  it('onShowVersions 미제공 시 disabled', () => {
    render(wrap(<EditorToolbar />, true));
    const btn = screen.getByTestId('g7le-toolbar-versions') as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
  });

  it('라우트 미선택 시 disabled (핸들러 제공해도)', () => {
    render(wrap(<EditorToolbar onShowVersions={vi.fn()} />, false));
    const btn = screen.getByTestId('g7le-toolbar-versions') as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
  });

  it('라우트 선택 + 핸들러 제공 시 활성 + 클릭 시 호출', () => {
    const onShowVersions = vi.fn();
    render(wrap(<EditorToolbar onShowVersions={onShowVersions} />, true));
    const btn = screen.getByTestId('g7le-toolbar-versions') as HTMLButtonElement;
    expect(btn.disabled).toBe(false);
    fireEvent.click(btn);
    expect(onShowVersions).toHaveBeenCalledTimes(1);
  });
});
