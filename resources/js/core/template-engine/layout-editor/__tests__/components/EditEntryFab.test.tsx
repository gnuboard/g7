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
  // @scenario case=ops_screen_floating_fab_authenticated_with_perm_normal_render
  // @effects ops_screen_fab_mounts_for_layout_editor
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

  // @scenario case=ops_screen_floating_fab_normal_render_logout
  // @effects ops_screen_fab_hidden_without_ability
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

  // @scenario case=ops_screen_floating_fab_authenticated_no_perm_normal_render
  // @effects ops_screen_fab_hidden_without_ability
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

  // @scenario case=url_restore_reload_edit_mode
  // @effects ops_screen_fab_mounts_for_layout_editor
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

  // @scenario case=setting_toggle
  // @effects ops_screen_fab_hidden_when_setting_off
  it('환경설정에서 끈 경우(enabled=false) → 권한 보유자도 미렌더 (7.2.0)', () => {
    renderWithTranslation(
      <EditEntryFab
        templateIdentifier="sirsoft-basic"
        pathname="/"
        isAuthenticated
        hasLayoutEditPermission
        currentRoutePath="/"
        enabled={false}
        openWindow={() => {}}
      />,
    );
    expect(screen.queryByTestId('g7le-edit-entry-fab')).toBeNull();
  });
});

describe('EditEntryFab — 모양 (7.2.0)', () => {
  // @scenario case=ops_screen_floating_fab_mobile
  // @effects ops_screen_fab_compact_has_accessible_name, ops_screen_fab_default_bottom_right
  it('글자 없이 아이콘만 두고, 보이는 이름은 aria-label, 자리는 오른쪽 아래', () => {
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
    const fab = screen.getByTestId('g7le-edit-entry-fab');
    expect(fab.textContent).toBe('');
    expect(fab.querySelector('[data-testid="g7le-edit-entry-fab-icon"]')?.tagName.toLowerCase()).toBe('svg');
    expect(fab.getAttribute('aria-label')).toBe('layout_editor.chrome.fab.label');
    expect(fab.style.right).toBe('16px');
    expect(fab.style.bottom).toBe('16px');
    expect(fab.style.left).toBe('');
  });
});

describe('EditEntryFab — 클릭 동작', () => {
  // @scenario case=ops_screen_floating_fab_authenticated_with_perm_yes
  // @effects ops_screen_fab_passes_route_definition_path
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
