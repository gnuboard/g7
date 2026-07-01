// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * CellBorderControl.test.tsx — 셀 테두리 시각 피커
 *
 * 검증: 카탈로그 기반 두께/변/색 버튼 렌더 + className 합성(border 토큰만 교체, 나머지 보존)
 * + 역해석(현재 className → 활성 두께/변/색) + 카탈로그 미공급 시 null(중립 폴백).
 *
 * @scenario cell_border_visual_picker
 * @effects cell_border_visual_picker_replaces_raw_classname_input, cell_border_compose_preserves_non_border_classes, cell_border_reverse_resolves_active_state, cell_border_library_neutral_catalog_supplied
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup } from '@testing-library/react';
import { CellBorderControl, type CellBorderCatalog } from '../../components/property-controls/CellBorderControl';

const t = (k: string) => k;

const catalog: CellBorderCatalog = {
  sides: [
    { value: 'all', prefix: 'border' },
    { value: 'top', prefix: 'border-t' },
    { value: 'bottom', prefix: 'border-b' },
    { value: 'left', prefix: 'border-l' },
    { value: 'right', prefix: 'border-r' },
  ],
  widths: [
    { value: 'none' },
    { value: 'thin', suffix: '' },
    { value: 'medium', suffix: '-2' },
    { value: 'thick', suffix: '-4' },
  ],
  colors: [
    { value: 'gray-300', swatch: '#d1d5db', token: 'border-gray-300' },
    { value: 'blue-500', swatch: '#3b82f6', token: 'border-blue-500' },
  ],
};

afterEach(() => cleanup());

describe('CellBorderControl — 렌더/디그레이드', () => {
  it('카탈로그 미공급 → null(중립 폴백)', () => {
    const { container } = render(<CellBorderControl className="" catalog={null} t={t} onChange={vi.fn()} />);
    expect(container.firstChild).toBeNull();
  });

  it('카탈로그 공급 → 두께 버튼 렌더, 두께 none 이면 변/색 미표시', () => {
    render(<CellBorderControl className="" catalog={catalog} t={t} onChange={vi.fn()} />);
    expect(screen.getByTestId('g7le-cell-border-width-thin')).toBeTruthy();
    expect(screen.getByTestId('g7le-cell-border-width-thick')).toBeTruthy();
    // 두께 미선택(none) → 변/색 영역 숨김.
    expect(screen.queryByTestId('g7le-cell-border-sides')).toBeNull();
    expect(screen.queryByTestId('g7le-cell-border-colors')).toBeNull();
  });

  it('두께 선택 시 변/색 노출', () => {
    render(<CellBorderControl className="border border-gray-300" catalog={catalog} t={t} onChange={vi.fn()} />);
    expect(screen.getByTestId('g7le-cell-border-sides')).toBeTruthy();
    expect(screen.getByTestId('g7le-cell-border-colors')).toBeTruthy();
  });
});

describe('CellBorderControl — className 합성(border 토큰만 교체)', () => {
  it('두께 thin 선택 → 비어있으면 all 변 + border 추가', () => {
    const onChange = vi.fn();
    render(<CellBorderControl className="px-4 py-2" catalog={catalog} t={t} onChange={onChange} />);
    fireEvent.click(screen.getByTestId('g7le-cell-border-width-thin'));
    const next = onChange.mock.calls[0][0] as string;
    expect(next).toContain('px-4');
    expect(next).toContain('py-2'); // 비-테두리 클래스 보존
    expect(next.split(/\s+/)).toContain('border');
  });

  it('두께 medium → border-2, 기존 border 두께 토큰 교체', () => {
    const onChange = vi.fn();
    // 색은 인라인 SSoT 라 className 에서 제외 — 두께 변경은 width/side 토큰만, 색 토큰 미포함.
    render(<CellBorderControl className="border px-2" catalog={catalog} t={t} onChange={onChange} />);
    fireEvent.click(screen.getByTestId('g7le-cell-border-width-medium'));
    const next = (onChange.mock.calls[0][0] as string).split(/\s+/);
    expect(next).toContain('border-2');
    expect(next).not.toContain('border'); // 기존 두께 교체
    expect(next).toContain('px-2'); // 비-테두리 보존
    expect(next.some((tk) => /gray|blue|red/.test(tk))).toBe(false); // 색 토큰은 className 에 없음
  });

  it('프리셋 색 클릭 → onPresetToken(토큰) 호출(부모가 활성 스킴 토큰으로 적용)', () => {
    const onPresetToken = vi.fn();
    const onChange = vi.fn();
    render(<CellBorderControl className="border" catalog={catalog} t={t} onChange={onChange} onPresetToken={onPresetToken} />);
    fireEvent.click(screen.getByTestId('g7le-cell-border-color-blue-500'));
    expect(onPresetToken).toHaveBeenCalledWith('border-blue-500');
  });

  it('두께 none → 두께 토큰만 제거(색 토큰은 보존 — 4차: 색=토큰 SSoT 독립), 나머지 보존', () => {
    // 4차: 색은 className 토큰 SSoT(스킴별)라 두께 변경과 독립 — 두께 none 은 width/side 토큰만
    // 제거하고 색 토큰(border-gray-300)은 보존(두께 0이라 시각상 안 보이나 색 설정은 유지).
    const onChange = vi.fn();
    render(<CellBorderControl className="border border-gray-300 rounded px-2" catalog={catalog} t={t} onChange={onChange} />);
    fireEvent.click(screen.getByTestId('g7le-cell-border-width-none'));
    const next = (onChange.mock.calls[0][0] as string).split(/\s+/).filter(Boolean);
    expect(next).not.toContain('border'); // 두께 토큰 제거
    expect(next).toContain('border-gray-300'); // 색 토큰은 보존
    expect(next).toContain('rounded');
    expect(next).toContain('px-2');
  });

  it('자유 색 컬러 피커 → onColorStyle(HEX) 호출', () => {
    const onColorStyle = vi.fn();
    render(<CellBorderControl className="border border-gray-300" catalog={catalog} t={t} onChange={vi.fn()} colorStyle="" onColorStyle={onColorStyle} />);
    const picker = screen.getByTestId('g7le-cell-border-color-picker') as HTMLInputElement;
    fireEvent.input(picker, { target: { value: '#ff8800' } });
    expect(onColorStyle).toHaveBeenCalledWith('#ff8800');
  });

  it('라이트 탭: 프리셋 색 토큰(border-gray-300) 활성 + 자유 색(인라인)은 별도 활성', () => {
    // 4차: 프리셋=className 토큰, 자유=인라인 — 두 SSoT 가 분리되어 공존 가능.
    render(<CellBorderControl className="border border-gray-300" catalog={catalog} t={t} onChange={vi.fn()} colorStyle="#123456" onColorStyle={vi.fn()} />);
    // 프리셋 토큰이 className 에 있으면 그 프리셋 활성(토큰 경로).
    expect((screen.getByTestId('g7le-cell-border-color-gray-300') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
  });

  // ── colorScheme prop (단일 공용 탭은 부모 ColorSchemeTabs — 컨트롤은 표시만) ──
  it('스킴 탭은 컨트롤 내부에 없음(부모 단일 탭으로 이동)', () => {
    render(<CellBorderControl className="border border-gray-300" catalog={catalog} t={t} onChange={vi.fn()} colorScheme="light" onPresetToken={vi.fn()} onColorStyle={vi.fn()} />);
    expect(screen.queryByTestId('g7le-cell-border-scheme-tabs')).toBeNull();
  });

  it('colorScheme=dark: dark:border-* 토큰 활성, 자유 색 컬러피커 비활성', () => {
    render(<CellBorderControl className="border dark:border-blue-500" catalog={catalog} t={t} onChange={vi.fn()} colorScheme="dark" onPresetToken={vi.fn()} colorStyle="#123456" onColorStyle={vi.fn()} />);
    // 다크 색 토큰(blue-500) 활성.
    expect((screen.getByTestId('g7le-cell-border-color-blue-500') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
    // 자유 색 컬러피커 비활성(다크는 프리셋만).
    expect((screen.getByTestId('g7le-cell-border-color-picker') as HTMLInputElement).disabled).toBe(true);
  });

  it('colorScheme=dark: 라이트 색 토큰(border-gray-300)은 비활성(스킴 분리)', () => {
    render(<CellBorderControl className="border border-gray-300" catalog={catalog} t={t} onChange={vi.fn()} colorScheme="dark" onPresetToken={vi.fn()} onColorStyle={vi.fn()} />);
    expect((screen.getByTestId('g7le-cell-border-color-gray-300') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('false');
  });

  it('변 토글 → 개별 변(top) 선택 시 border-t, all 해제', () => {
    const onChange = vi.fn();
    render(<CellBorderControl className="border border-gray-300" catalog={catalog} t={t} onChange={onChange} />);
    fireEvent.click(screen.getByTestId('g7le-cell-border-side-top'));
    const next = (onChange.mock.calls[0][0] as string).split(/\s+/);
    expect(next).toContain('border-t');
    expect(next).not.toContain('border'); // all 해제
  });
});

describe('CellBorderControl — 역해석(현재 className → 활성 상태)', () => {
  it('border border-blue-500 → 두께 thin·변 all·색 blue 활성', () => {
    render(<CellBorderControl className="border border-blue-500" catalog={catalog} t={t} onChange={vi.fn()} />);
    expect((screen.getByTestId('g7le-cell-border-width-thin') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
    expect((screen.getByTestId('g7le-cell-border-side-all') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
    expect((screen.getByTestId('g7le-cell-border-color-blue-500') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
  });

  it('border-t-2 → 두께 medium·변 top 활성', () => {
    render(<CellBorderControl className="border-t-2 border-gray-300" catalog={catalog} t={t} onChange={vi.fn()} />);
    expect((screen.getByTestId('g7le-cell-border-width-medium') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
    expect((screen.getByTestId('g7le-cell-border-side-top') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('true');
    expect((screen.getByTestId('g7le-cell-border-side-all') as HTMLButtonElement).getAttribute('aria-pressed')).toBe('false');
  });
});
