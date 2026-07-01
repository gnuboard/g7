// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * CellFillControl.test.tsx — 셀 배경색 시각 피커
 *
 * 검증: 프리셋 스와치(token 경로) + 없음(투명) + 자유 컬러피커(인라인, 라이트 전용) 렌더,
 * 라이트/다크 탭, 토큰 역해석 활성 표시, 다크 탭 자유 색 비활성 + token 없는 프리셋 비활성,
 * 카탈로그 미공급 시 자유 색만.
 *
 * @scenario cell_fill_visual_picker_light_dark
 * @effects cell_fill_preset_applies_classtoken_per_scheme, cell_fill_custom_inline_light_only, cell_fill_none_clears, cell_fill_dark_disables_custom
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { CellFillControl, type CellFillCatalog } from '../../components/property-controls/CellFillControl';

const t = (k: string) => k;

const catalog: CellFillCatalog = {
  colors: [
    { value: 'white', swatch: '#ffffff', token: 'bg-white' },
    { value: 'yellow-100', swatch: '#fef9c3', token: 'bg-yellow-100' },
    { value: 'blue-100', swatch: '#dbeafe', token: 'bg-blue-100' },
  ],
};

afterEach(() => cleanup());

describe('CellFillControl — 렌더', () => {
  it('카탈로그 공급 → 없음 + 프리셋 스와치 + 자유 색 피커 렌더', () => {
    render(<CellFillControl catalog={catalog} t={t} onCustomColor={vi.fn()} onClear={vi.fn()} onPresetToken={vi.fn()} />);
    expect(screen.getByTestId('g7le-cell-fill-none')).toBeTruthy();
    expect(screen.getByTestId('g7le-cell-fill-color-yellow-100')).toBeTruthy();
    expect(screen.getByTestId('g7le-cell-fill-color-picker')).toBeTruthy();
  });

  it('카탈로그 미공급 → 없음 + 자유 색만(프리셋 부재)', () => {
    render(<CellFillControl catalog={null} t={t} onCustomColor={vi.fn()} onClear={vi.fn()} />);
    expect(screen.getByTestId('g7le-cell-fill-none')).toBeTruthy();
    expect(screen.getByTestId('g7le-cell-fill-color-picker')).toBeTruthy();
    expect(screen.queryByTestId('g7le-cell-fill-color-yellow-100')).toBeNull();
  });
});

describe('CellFillControl — 적용(프리셋=토큰, 자유=인라인)', () => {
  it('프리셋(token 보유) 클릭 → onPresetToken(token)', () => {
    const onPresetToken = vi.fn();
    render(<CellFillControl catalog={catalog} t={t} onPresetToken={onPresetToken} onCustomColor={vi.fn()} onClear={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-cell-fill-color-yellow-100'));
    expect(onPresetToken).toHaveBeenCalledWith('bg-yellow-100');
  });

  it('없음 클릭 → onClear()', () => {
    const onClear = vi.fn();
    render(<CellFillControl catalog={catalog} colorStyle="#fef9c3" t={t} onClear={onClear} onCustomColor={vi.fn()} onPresetToken={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-cell-fill-none'));
    expect(onClear).toHaveBeenCalled();
  });

  it('자유 컬러 피커 → onCustomColor(HEX) (라이트)', () => {
    const onCustomColor = vi.fn();
    render(<CellFillControl catalog={catalog} t={t} onCustomColor={onCustomColor} onClear={vi.fn()} onPresetToken={vi.fn()} />);
    fireEvent.input(screen.getByTestId('g7le-cell-fill-color-picker'), { target: { value: '#ff8800' } });
    expect(onCustomColor).toHaveBeenCalledWith('#ff8800');
  });
});

describe('CellFillControl — 역해석(활성 표시)', () => {
  it('라이트: className 에 프리셋 토큰 → 그 프리셋 활성', () => {
    render(<CellFillControl catalog={catalog} className="bg-yellow-100" colorScheme="light" t={t} onPresetToken={vi.fn()} onCustomColor={vi.fn()} onClear={vi.fn()} />);
    expect((screen.getByTestId('g7le-cell-fill-color-yellow-100') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
  });

  it('라이트: 인라인 자유 HEX(프리셋 swatch 불일치) → 프리셋 비활성', () => {
    render(<CellFillControl catalog={catalog} colorStyle="#123456" colorScheme="light" t={t} onPresetToken={vi.fn()} onCustomColor={vi.fn()} onClear={vi.fn()} />);
    expect((screen.getByTestId('g7le-cell-fill-color-yellow-100') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('false');
    expect((screen.getByTestId('g7le-cell-fill-none') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('false');
  });

  it('라이트: 색 토큰/인라인 모두 없음 → 없음(투명) 활성', () => {
    render(<CellFillControl catalog={catalog} colorStyle="" colorScheme="light" t={t} onPresetToken={vi.fn()} onCustomColor={vi.fn()} onClear={vi.fn()} />);
    expect((screen.getByTestId('g7le-cell-fill-none') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
  });
});

describe('CellFillControl — colorScheme prop (단일 공용 탭은 부모)', () => {
  it('스킴 탭은 컨트롤 내부에 없음(부모 ColorSchemeTabs 로 이동)', () => {
    render(<CellFillControl catalog={catalog} t={t} colorScheme="light" onPresetToken={vi.fn()} onCustomColor={vi.fn()} onClear={vi.fn()} />);
    expect(screen.queryByTestId('g7le-cell-fill-scheme-tabs')).toBeNull();
  });

  it('colorScheme=dark: dark:bg-* 토큰 활성, 자유 색 비활성', () => {
    render(<CellFillControl catalog={catalog} className="dark:bg-blue-100" colorScheme="dark" t={t} onPresetToken={vi.fn()} onCustomColor={vi.fn()} onClear={vi.fn()} />);
    expect((screen.getByTestId('g7le-cell-fill-color-blue-100') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
    expect((screen.getByTestId('g7le-cell-fill-color-picker') as HTMLInputElement).disabled).toBe(true);
  });

  it('colorScheme=dark: 프리셋 클릭 → onPresetToken(token) (부모가 dark: 적용)', () => {
    const onPresetToken = vi.fn();
    render(<CellFillControl catalog={catalog} colorScheme="dark" t={t} onPresetToken={onPresetToken} onCustomColor={vi.fn()} onClear={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-cell-fill-color-blue-100'));
    expect(onPresetToken).toHaveBeenCalledWith('bg-blue-100');
  });

  it('colorScheme=dark: 라이트 색 토큰(bg-yellow-100)은 비활성(스킴 분리)', () => {
    render(<CellFillControl catalog={catalog} className="bg-yellow-100" colorScheme="dark" t={t} onPresetToken={vi.fn()} onCustomColor={vi.fn()} onClear={vi.fn()} />);
    expect((screen.getByTestId('g7le-cell-fill-color-yellow-100') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('false');
  });
});
