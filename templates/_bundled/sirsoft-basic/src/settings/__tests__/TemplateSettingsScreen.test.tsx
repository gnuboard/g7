// e2e:allow 화면 부품 단위 잠금 — 브라우저 흐름은 tests/Playwright/specs/admin/template-settings-screen.spec.ts·template-settings-favicon.spec.ts 가 덮는다
/**
 * @file TemplateSettingsScreen.test.tsx
 * @description 템플릿이 공급한 설정 화면 부품을 잠근다 (#434 D28·D29).
 *
 * 토스트·모달·탭 줄 같은 관리자 템플릿 부품 없이 어느 관리자 템플릿에서나 뜨도록, 부품이 탭·저장 결과·확인 창을 직접 그린다.
 *
 * 시나리오: case=settings_layout
 *
 * 효과: settings_layout_saves_template_settings
 * 효과: settings_tabs_per_section
 * 효과: settings_reset_to_defaults
 * 효과: read_only_without_settings_permission
 */

import { describe, expect, it, vi } from 'vitest';
import { fireEvent, render, screen } from '@testing-library/react';
import { TemplateSettingsScreen } from '../TemplateSettingsScreen';

const data = {
  name: 'Basic',
  type: 'user',
  is_active: true,
  abilities: { can_update: true, can_reset: true },
  sections: [
    { key: 'branding', label: '브랜딩', fields: [] },
    { key: 'header', label: '헤더', fields: [] },
  ],
};

describe('TemplateSettingsScreen (#434)', () => {
  // @scenario case=settings_layout
  // @effects settings_tabs_per_section
  it('변경이 없으면 바로 옮기고, 변경이 있으면 확인 창 뒤 「버렸다」로 알린다 — 머무르기는 옮기지 않는다', () => {
    const onTabChange = vi.fn();
    const { rerender } = render(<TemplateSettingsScreen data={data} activeSection="branding" readOnly={false} onTabChange={onTabChange} />);

    fireEvent.click(screen.getByRole('tab', { name: '헤더' }));
    expect(onTabChange).toHaveBeenLastCalledWith('header', false);

    onTabChange.mockClear();
    rerender(<TemplateSettingsScreen data={data} activeSection="branding" readOnly={false} dirty onTabChange={onTabChange} />);
    fireEvent.click(screen.getByRole('tab', { name: '헤더' }));
    expect(onTabChange).not.toHaveBeenCalled();
    expect(screen.getByRole('dialog')).toBeTruthy();

    fireEvent.keyDown(document, { key: 'Escape' });
    expect(screen.queryByRole('dialog')).toBeNull();
    expect(onTabChange).not.toHaveBeenCalled();

    fireEvent.click(screen.getByRole('tab', { name: '헤더' }));
    fireEvent.click(screen.getByTestId('template_settings_unsaved_tab_confirm'));
    expect(onTabChange).toHaveBeenCalledWith('header', true);
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  // @scenario case=settings_layout
  // @effects settings_reset_to_defaults
  it('되돌리기는 확인 창을 거치고 끝나면 창이 닫힌다', () => {
    const onReset = vi.fn();
    const { rerender } = render(<TemplateSettingsScreen data={data} activeSection="branding" readOnly={false} onReset={onReset} />);

    fireEvent.click(screen.getByTestId('template-settings-reset'));
    expect(onReset).not.toHaveBeenCalled();
    fireEvent.click(screen.getByTestId('template_settings_reset_confirm'));
    expect(onReset).toHaveBeenCalledTimes(1);

    rerender(<TemplateSettingsScreen data={data} activeSection="branding" readOnly={false} resetting onReset={onReset} />);
    expect(screen.getByRole('dialog')).toBeTruthy();
    rerender(<TemplateSettingsScreen data={data} activeSection="branding" readOnly={false} onReset={onReset} />);
    expect(screen.queryByRole('dialog')).toBeNull();
  });

  // @scenario case=settings_layout
  // @effects settings_layout_saves_template_settings
  it('저장 단추는 변경이 있을 때만 열리고, 결과와 오류 요약을 화면 안에 보인다 — 서버 문구가 있으면 그것', () => {
    const onSave = vi.fn();
    const { rerender } = render(<TemplateSettingsScreen data={data} activeSection="branding" readOnly={false} onSave={onSave} />);

    expect((screen.getByTestId('template-settings-save') as HTMLButtonElement).disabled).toBe(true);

    rerender(<TemplateSettingsScreen data={data} activeSection="branding" readOnly={false} dirty onSave={onSave} status={{ type: 'success' }} />);
    fireEvent.click(screen.getByTestId('template-settings-save'));
    expect(onSave).toHaveBeenCalledTimes(1);
    expect(screen.getByTestId('template-settings-status').textContent).toBe('Saved');

    rerender(<TemplateSettingsScreen data={data} activeSection="branding" readOnly={false} status={{ type: 'error', message: '서버 문구' }} errors={{ 'header.menu': ['칸 오류'] }} />);
    expect(screen.getByTestId('template-settings-status').textContent).toBe('서버 문구');
    expect(screen.getByTestId('template-settings-error-summary').textContent).toContain('칸 오류');

    rerender(<TemplateSettingsScreen data={data} activeSection="branding" readOnly={false} status={{ type: 'success', action: 'reset' }} />);
    expect(screen.getByTestId('template-settings-status').textContent).toBe('Reset done');
  });

  // @scenario case=settings_layout
  // @effects read_only_without_settings_permission
  it('읽기 전용이면 안내를 보이고 저장 바가 없다', () => {
    render(<TemplateSettingsScreen data={data} activeSection="branding" readOnly />);

    expect(screen.getByTestId('template-settings-read-only')).toBeTruthy();
    expect(screen.queryByTestId('template-settings-footer')).toBeNull();
  });
});
