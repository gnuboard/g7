/**
 * EditorToolbar.pageSettings.test.tsx —
 *
 * ⚙ 페이지 설정 버튼 회귀 가드(종전 ⚙데이터 버튼을 대체 — 데이터 진입은 [데이터] 탭 흡수):
 * - onPageSettings 미제공 또는 라우트 미선택 시 disabled
 * - route 모드 + onPageSettings 제공 시 활성 + 클릭 시 호출
 * - extension/iteration_item 모드 시 disabled(페이지 메타 없음)
 * - 종전 ⚙데이터 버튼(g7le-toolbar-data-sources) 부재 확인(진입점 단일화)
 */

import React, { useEffect } from 'react';
import { describe, expect, it, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { EditorToolbar } from '../../components/EditorToolbar';
import { LayoutEditorProvider, useLayoutEditor } from '../../LayoutEditorContext';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

function SelectRoute({ layoutName }: { layoutName: string }): null {
  const { dispatch } = useLayoutEditor();
  useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path: `/${layoutName}`, layoutName } });
  }, [dispatch, layoutName]);
  return null;
}

function EnterMode({ mode }: { mode: 'extension' | 'iteration_item' }): null {
  const { dispatch } = useLayoutEditor();
  useEffect(() => {
    dispatch({ type: 'SELECT_ROUTE', route: { path: '/home', layoutName: 'home' } });
    if (mode === 'extension') dispatch({ type: 'ENTER_EXTENSION_EDIT', extensionId: '44' });
    else dispatch({ type: 'ENTER_ITERATION_ITEM_EDIT', sourcePath: '0.children.1', hostLayout: 'home' });
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);
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

describe('EditorToolbar — ⚙ 페이지 설정 버튼', () => {
  it('onPageSettings 미제공 시 disabled', () => {
    render(wrap(<EditorToolbar />, true));
    const btn = screen.getByTestId('g7le-toolbar-page-settings') as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
  });

  it('라우트 미선택 시 disabled (핸들러 제공해도)', () => {
    render(wrap(<EditorToolbar onPageSettings={vi.fn()} />, false));
    const btn = screen.getByTestId('g7le-toolbar-page-settings') as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
  });

  it('route 모드 + 핸들러 제공 시 활성 + 클릭 시 호출', () => {
    const onPageSettings = vi.fn();
    render(wrap(<EditorToolbar onPageSettings={onPageSettings} />, true));
    const btn = screen.getByTestId('g7le-toolbar-page-settings') as HTMLButtonElement;
    expect(btn.disabled).toBe(false);
    fireEvent.click(btn);
    expect(onPageSettings).toHaveBeenCalledTimes(1);
  });

  it('extension 모드 → disabled(페이지 메타 없음)', async () => {
    render(
      wrap(
        <>
          <EnterMode mode="extension" />
          <EditorToolbar onPageSettings={vi.fn()} />
        </>,
      ),
    );
    await screen.findByTestId('g7le-toolbar-exit-alt-mode');
    const btn = screen.getByTestId('g7le-toolbar-page-settings') as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
  });

  it('iteration_item 모드 → disabled', async () => {
    render(
      wrap(
        <>
          <EnterMode mode="iteration_item" />
          <EditorToolbar onPageSettings={vi.fn()} />
        </>,
      ),
    );
    await screen.findByTestId('g7le-toolbar-exit-alt-mode');
    const btn = screen.getByTestId('g7le-toolbar-page-settings') as HTMLButtonElement;
    expect(btn.disabled).toBe(true);
  });

  it('종전 ⚙데이터 버튼 부재(진입점 [데이터] 탭 단일화)', () => {
    render(wrap(<EditorToolbar onPageSettings={vi.fn()} />, true));
    expect(screen.queryByTestId('g7le-toolbar-data-sources')).not.toBeInTheDocument();
  });
});
