// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * CellPaddingControl.test.tsx — 셀 내부 여백 시각 피커
 *
 * 검증: 프리셋 단계 칩 + 자유 px 입력 렌더, 단계 클릭 시 px 인라인 콜백(없음=빈값),
 * 자유 px 입력 후 blur/Enter 적용, 현재 padding 에 따른 활성 표시.
 *
 * @scenario cell_padding_visual_picker
 * @effects cell_padding_step_applies_inline_px, cell_padding_none_clears, cell_padding_free_px_input, cell_padding_reverse_resolves_active
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, act } from '@testing-library/react';
import { CellPaddingControl, type CellPaddingCatalog } from '../../components/property-controls/CellPaddingControl';

const t = (k: string) => k;

const catalog: CellPaddingCatalog = {
  steps: [
    { value: 'none' },
    { value: 'narrow', px: 4 },
    { value: 'normal', px: 8 },
    { value: 'wide', px: 16 },
  ],
};

afterEach(() => cleanup());

describe('CellPaddingControl — 렌더', () => {
  it('카탈로그 공급 → 단계 칩 + 자유 px 입력 렌더', () => {
    render(<CellPaddingControl catalog={catalog} t={t} onChange={vi.fn()} />);
    expect(screen.getByTestId('g7le-cell-padding-step-none')).toBeTruthy();
    expect(screen.getByTestId('g7le-cell-padding-step-wide')).toBeTruthy();
    expect(screen.getByTestId('g7le-cell-padding-free')).toBeTruthy();
  });
});

describe('CellPaddingControl — 적용(인라인 px SSoT)', () => {
  it('단계 normal(8px) 클릭 → onChange("8px")', () => {
    const onChange = vi.fn();
    render(<CellPaddingControl catalog={catalog} t={t} onChange={onChange} />);
    fireEvent.click(screen.getByTestId('g7le-cell-padding-step-normal'));
    expect(onChange).toHaveBeenCalledWith('8px');
  });

  it('단계 none 클릭 → onChange("")', () => {
    const onChange = vi.fn();
    render(<CellPaddingControl catalog={catalog} paddingStyle="8px" t={t} onChange={onChange} />);
    fireEvent.click(screen.getByTestId('g7le-cell-padding-step-none'));
    expect(onChange).toHaveBeenCalledWith('');
  });

  it('자유 px 입력 후 blur → onChange("20px")', () => {
    const onChange = vi.fn();
    render(<CellPaddingControl catalog={catalog} t={t} onChange={onChange} />);
    const input = screen.getByTestId('g7le-cell-padding-free') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '20' } });
    fireEvent.blur(input);
    expect(onChange).toHaveBeenCalledWith('20px');
  });

  it('자유 px 0 입력 → onChange("")(여백 제거)', () => {
    const onChange = vi.fn();
    render(<CellPaddingControl catalog={catalog} t={t} onChange={onChange} />);
    const input = screen.getByTestId('g7le-cell-padding-free') as HTMLInputElement;
    fireEvent.change(input, { target: { value: '0' } });
    fireEvent.keyDown(input, { key: 'Enter' });
    expect(onChange).toHaveBeenCalledWith('');
  });
});

describe('CellPaddingControl — 역해석(활성 표시)', () => {
  it('현재 8px → normal 단계 활성', () => {
    render(<CellPaddingControl catalog={catalog} paddingStyle="8px" t={t} onChange={vi.fn()} />);
    expect((screen.getByTestId('g7le-cell-padding-step-normal') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
    expect((screen.getByTestId('g7le-cell-padding-step-narrow') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('false');
  });

  it('빈 padding → none 단계 활성', () => {
    render(<CellPaddingControl catalog={catalog} paddingStyle="" t={t} onChange={vi.fn()} />);
    expect((screen.getByTestId('g7le-cell-padding-step-none') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
  });

  it('프리셋에 없는 자유값(20px) → 자유 입력칸에 표시, 모든 단계 비활성', () => {
    render(<CellPaddingControl catalog={catalog} paddingStyle="20px" t={t} onChange={vi.fn()} />);
    expect((screen.getByTestId('g7le-cell-padding-free') as HTMLInputElement).value).toBe('20');
    expect((screen.getByTestId('g7le-cell-padding-step-normal') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('false');
  });

  // 회귀: 다른 셀 선택 시 paddingStyle prop 이 바뀌면 자유 px
  // 입력칸이 그 셀 값으로 재동기화돼야 한다. 종전엔 useState 초기값이 마운트 1회뿐이라 셀을
  // 바꿔도 이전 셀의 자유 px(예 20)가 입력칸에 stale 하게 남았다.
  it('paddingStyle prop 변화(셀 전환) → 자유 입력칸 재동기화', () => {
    const { rerender } = render(<CellPaddingControl catalog={catalog} paddingStyle="20px" t={t} onChange={vi.fn()} />);
    expect((screen.getByTestId('g7le-cell-padding-free') as HTMLInputElement).value).toBe('20');
    // 무스타일 셀(빈 padding)로 전환 → 자유 입력칸 비워짐.
    act(() => { rerender(<CellPaddingControl catalog={catalog} paddingStyle="" t={t} onChange={vi.fn()} />); });
    expect((screen.getByTestId('g7le-cell-padding-free') as HTMLInputElement).value).toBe('');
    // 프리셋 값(8px) 셀로 전환 → 자유 입력칸도 비워짐(프리셋 활성).
    act(() => { rerender(<CellPaddingControl catalog={catalog} paddingStyle="8px" t={t} onChange={vi.fn()} />); });
    expect((screen.getByTestId('g7le-cell-padding-free') as HTMLInputElement).value).toBe('');
    expect((screen.getByTestId('g7le-cell-padding-step-normal') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
    // 다른 자유값(32px) 셀로 전환 → 자유 입력칸 32 로 갱신.
    act(() => { rerender(<CellPaddingControl catalog={catalog} paddingStyle="32px" t={t} onChange={vi.fn()} />); });
    expect((screen.getByTestId('g7le-cell-padding-free') as HTMLInputElement).value).toBe('32');
  });
});
