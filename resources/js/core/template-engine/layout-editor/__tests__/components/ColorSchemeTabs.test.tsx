// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * ColorSchemeTabs.test.tsx — 셀 색 라이트/다크 단일 공용 탭
 *
 * 검증: 라이트/다크 단일 탭 렌더 + i18n, 클릭 시 onChange, 활성 표시, 다크 안내.
 * 탭은 테두리색·배경색 컨트롤에 각각 두지 않고 색상 섹션 상단 단일 1개.
 *
 * @scenario cell_color_single_scheme_tab
 * @effects cell_color_scheme_tab_shared_across_border_and_fill
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { ColorSchemeTabs } from '../../components/property-controls/ColorSchemeTabs';

const t = (k: string) => k;

afterEach(() => cleanup());

describe('ColorSchemeTabs', () => {
  it('라이트/다크 단일 탭 렌더 + i18n', () => {
    render(<ColorSchemeTabs colorScheme="light" onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-color-scheme-tabs')).toBeTruthy();
    expect(screen.getByTestId('g7le-color-scheme-light').textContent).toBe('layout_editor.property_modal.scope.scheme_light');
    expect(screen.getByTestId('g7le-color-scheme-dark').textContent).toBe('layout_editor.property_modal.scope.scheme_dark');
  });

  it('라이트 활성 표시(다크 비활성)', () => {
    render(<ColorSchemeTabs colorScheme="light" onChange={vi.fn()} t={t} />);
    expect((screen.getByTestId('g7le-color-scheme-light') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
    expect((screen.getByTestId('g7le-color-scheme-dark') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('false');
  });

  it('다크 탭 클릭 → onChange("dark")', () => {
    const onChange = vi.fn();
    render(<ColorSchemeTabs colorScheme="light" onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-color-scheme-dark'));
    expect(onChange).toHaveBeenCalledWith('dark');
  });

  it('라이트 탭 클릭 → onChange("light")', () => {
    const onChange = vi.fn();
    render(<ColorSchemeTabs colorScheme="dark" onChange={onChange} t={t} />);
    fireEvent.click(screen.getByTestId('g7le-color-scheme-light'));
    expect(onChange).toHaveBeenCalledWith('light');
  });

  it('다크 스킴이면 안내 노출, 라이트면 미노출', () => {
    const { rerender } = render(<ColorSchemeTabs colorScheme="dark" onChange={vi.fn()} t={t} />);
    expect(screen.getByTestId('g7le-color-scheme-dark-hint')).toBeTruthy();
    rerender(<ColorSchemeTabs colorScheme="light" onChange={vi.fn()} t={t} />);
    expect(screen.queryByTestId('g7le-color-scheme-dark-hint')).toBeNull();
  });
});
