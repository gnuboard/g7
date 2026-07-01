/**
 * EditEntryFab 컴포넌트 테스트
 */

import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { EditEntryFab } from '../../components/EditEntryFab';
import { TranslationProvider } from '../../../TranslationContext';
import { TranslationEngine } from '../../../TranslationEngine';

function renderWithTranslation(ui: React.ReactElement): ReturnType<typeof render> {
  const engine = new TranslationEngine();
  return render(
    <TranslationProvider
      translationEngine={engine}
      translationContext={{ templateId: 'test', locale: 'ko' }}
    >
      {ui}
    </TranslationProvider>,
  );
}

describe('EditEntryFab — 표시 조건', () => {
  it('인증 + 권한 보유 → 일반 렌더 URL 에서 렌더', () => {
    renderWithTranslation(
      <EditEntryFab
        templateIdentifier="sirsoft-basic"
        pathname="/"
        isAuthenticated
        hasLayoutEditPermission
        currentRoutePath="/"
        openWindow={() => {}}
      />,
    );
    expect(screen.queryByTestId('g7le-edit-entry-fab')).toBeTruthy();
  });

  it('비로그인 → 미렌더', () => {
    renderWithTranslation(
      <EditEntryFab
        templateIdentifier="sirsoft-basic"
        pathname="/"
        isAuthenticated={false}
        hasLayoutEditPermission
        currentRoutePath="/"
        openWindow={() => {}}
      />,
    );
    expect(screen.queryByTestId('g7le-edit-entry-fab')).toBeNull();
  });

  it('권한 없음 → 미렌더', () => {
    renderWithTranslation(
      <EditEntryFab
        templateIdentifier="sirsoft-basic"
        pathname="/"
        isAuthenticated
        hasLayoutEditPermission={false}
        currentRoutePath="/"
        openWindow={() => {}}
      />,
    );
    expect(screen.queryByTestId('g7le-edit-entry-fab')).toBeNull();
  });

  it('편집 모드 URL → 권한 보유자도 미렌더', () => {
    renderWithTranslation(
      <EditEntryFab
        templateIdentifier="sirsoft-basic"
        pathname="/admin/layout-editor/sirsoft-basic"
        isAuthenticated
        hasLayoutEditPermission
        currentRoutePath="/"
        openWindow={() => {}}
      />,
    );
    expect(screen.queryByTestId('g7le-edit-entry-fab')).toBeNull();
  });
});

describe('EditEntryFab — 클릭 동작', () => {
  it('클릭 → openWindow 가 진입 URL 로 호출됨', () => {
    const openWindow = vi.fn();
    renderWithTranslation(
      <EditEntryFab
        templateIdentifier="sirsoft-basic"
        pathname="/board/list"
        isAuthenticated
        hasLayoutEditPermission
        currentRoutePath="/board/list"
        openWindow={openWindow}
      />,
    );

    const fab = screen.getByTestId('g7le-edit-entry-fab');
    fireEvent.click(fab);

    expect(openWindow).toHaveBeenCalledOnce();
    expect(openWindow).toHaveBeenCalledWith(
      '/admin/layout-editor/sirsoft-basic?route=%2Fboard%2Flist',
    );
  });
});
