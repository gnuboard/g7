// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * cellBorderPreview.test.ts — 셀 테두리 미리보기 CSS 변환
 *
 * @effects table_editor_property_panel_preview_reflects_cell_border_width_style_color
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { cellBorderPreviewStyle, cellFillPaddingPreviewStyle } from '../../spec/cellBorderPreview';
import type { EditorNode } from '../../utils/layoutTreeUtils';

const catalog = {
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
  colors: [{ swatch: '#ef4444', token: 'border-red-500' }],
};

function cell(className?: string, style?: Record<string, unknown>): EditorNode {
  const n: EditorNode = { type: 'basic', name: 'Td' };
  const props: Record<string, unknown> = {};
  if (className !== undefined) props.className = className;
  if (style) props.style = style;
  if (Object.keys(props).length) n.props = props;
  return n;
}

describe('cellBorderPreviewStyle', () => {
  it('테두리 토큰/스타일 없음 → 빈 객체(호출부 폴백 사용)', () => {
    expect(cellBorderPreviewStyle(cell(), catalog)).toEqual({});
    expect(cellBorderPreviewStyle(null, catalog)).toEqual({});
    expect(cellBorderPreviewStyle(cell('border'), null)).toEqual({});
  });

  it('전체 얇게 → 4변 1px solid', () => {
    const css = cellBorderPreviewStyle(cell('border'), catalog) as Record<string, unknown>;
    expect(css.borderTopWidth).toBe('1px');
    expect(css.borderBottomWidth).toBe('1px');
    expect(css.borderLeftWidth).toBe('1px');
    expect(css.borderRightWidth).toBe('1px');
    expect(css.borderTopStyle).toBe('solid');
  });

  it('전체 굵게(-4) → 4변 4px', () => {
    const css = cellBorderPreviewStyle(cell('border-4'), catalog) as Record<string, unknown>;
    expect(css.borderTopWidth).toBe('4px');
    expect(css.borderRightWidth).toBe('4px');
  });

  it('전체 보통(-2) → 4변 2px', () => {
    const css = cellBorderPreviewStyle(cell('border-2'), catalog) as Record<string, unknown>;
    expect(css.borderTopWidth).toBe('2px');
  });

  it('개별 변(상단만) → 그 변만 적용', () => {
    const css = cellBorderPreviewStyle(cell('border-t-2'), catalog) as Record<string, unknown>;
    expect(css.borderTopWidth).toBe('2px');
    expect(css.borderBottomWidth).toBeUndefined();
    expect(css.borderLeftWidth).toBeUndefined();
  });

  it('인라인 borderColor 가 4변 색에 반영', () => {
    const css = cellBorderPreviewStyle(cell('border', { borderColor: 'rgb(255, 0, 0)' }), catalog) as Record<string, unknown>;
    expect(css.borderTopColor).toBe('rgb(255, 0, 0)');
    expect(css.borderLeftColor).toBe('rgb(255, 0, 0)');
  });

  it('per-side 인라인 색(공유 변 보정)이 해당 변에 우선 반영', () => {
    const css = cellBorderPreviewStyle(
      cell('border', { borderColor: '#000000', borderBottomColor: '#ef4444' }),
      catalog,
    ) as Record<string, unknown>;
    expect(css.borderTopColor).toBe('#000000');
    expect(css.borderBottomColor).toBe('#ef4444');
  });

  it('인라인 색 없으면 className 색 토큰의 swatch 사용', () => {
    const css = cellBorderPreviewStyle(cell('border border-red-500'), catalog) as Record<string, unknown>;
    expect(css.borderTopColor).toBe('#ef4444');
  });

  it('두께 none(테두리 없음) 토큰만 → 빈 객체', () => {
    // 'none' 은 suffix 없음 + width value 'none' 이라 토큰화되지 않음 → 매칭 0.
    expect(cellBorderPreviewStyle(cell('p-2 text-center'), catalog)).toEqual({});
  });

  // ── 라이트/다크 스킴 ──────────────────────────────────
  it('light 스킴(기본) = 인라인 borderColor 우선 — 회귀 동일', () => {
    const css = cellBorderPreviewStyle(cell('border', { borderColor: '#ef4444' }), catalog, 'light') as Record<string, unknown>;
    expect(css.borderTopColor).toBe('#ef4444');
  });

  it('light 스킴은 dark:border-* 토큰을 무시(라이트 색만)', () => {
    const css = cellBorderPreviewStyle(cell('border dark:border-red-500'), catalog, 'light') as Record<string, unknown>;
    // 라이트 토큰/인라인 색 없음 → 폴백색.
    expect(css.borderTopColor).toBe('#94a3b8');
  });

  it('dark 스킴 = dark:border-* 토큰 swatch 매핑, 인라인 색 무시', () => {
    const css = cellBorderPreviewStyle(
      cell('border dark:border-red-500', { borderColor: '#000000' }),
      catalog,
      'dark',
    ) as Record<string, unknown>;
    expect(css.borderTopColor).toBe('#ef4444');
  });

  it('dark 스킴 + 다크 색 토큰 없음 → 폴백색(인라인 무시)', () => {
    const css = cellBorderPreviewStyle(cell('border border-red-500', { borderColor: '#123456' }), catalog, 'dark') as Record<string, unknown>;
    expect(css.borderTopColor).toBe('#94a3b8');
  });
});

describe('cellFillPaddingPreviewStyle — 배경색/여백 인라인 style 반영', () => {
  it('style 없으면 빈 객체', () => {
    expect(cellFillPaddingPreviewStyle(cell('border'))).toEqual({});
    expect(cellFillPaddingPreviewStyle(null)).toEqual({});
  });

  it('backgroundColor 인라인 style → 미리보기 backgroundColor', () => {
    const css = cellFillPaddingPreviewStyle(cell(undefined, { backgroundColor: '#fef9c3' })) as Record<string, unknown>;
    expect(css.backgroundColor).toBe('#fef9c3');
  });

  it('padding 인라인 style → 미리보기 padding', () => {
    const css = cellFillPaddingPreviewStyle(cell(undefined, { padding: '12px' })) as Record<string, unknown>;
    expect(css.padding).toBe('12px');
  });

  it('배경+여백 동시 → 둘 다 반영, borderColor 등 타 style 은 무시', () => {
    const css = cellFillPaddingPreviewStyle(
      cell(undefined, { backgroundColor: '#dbeafe', padding: '8px', borderColor: '#000' }),
    ) as Record<string, unknown>;
    expect(css.backgroundColor).toBe('#dbeafe');
    expect(css.padding).toBe('8px');
    expect(css.borderColor).toBeUndefined();
  });

  // ── 배경 토큰(스킴별) → swatch + 라이트/다크 분리 ────────
  const fillCatalog = {
    colors: [
      { value: 'gray-100', swatch: '#f3f4f6', token: 'bg-gray-100' },
      { value: 'blue-100', swatch: '#dbeafe', token: 'bg-blue-100' },
    ],
  };

  it('light 스킴 — 인라인 backgroundColor 우선(회귀 동일)', () => {
    const css = cellFillPaddingPreviewStyle(cell(undefined, { backgroundColor: '#fef9c3' }), fillCatalog, 'light') as Record<string, unknown>;
    expect(css.backgroundColor).toBe('#fef9c3');
  });

  it('light 스킴 — 인라인 없으면 비-dark bg 토큰 swatch', () => {
    const css = cellFillPaddingPreviewStyle(cell('bg-gray-100'), fillCatalog, 'light') as Record<string, unknown>;
    expect(css.backgroundColor).toBe('#f3f4f6');
  });

  it('light 스킴 — dark:bg-* 토큰은 무시', () => {
    const css = cellFillPaddingPreviewStyle(cell('dark:bg-blue-100'), fillCatalog, 'light') as Record<string, unknown>;
    expect(css.backgroundColor).toBeUndefined();
  });

  it('dark 스킴 — dark:bg-* 토큰 swatch 매핑(인라인/라이트 토큰 무시)', () => {
    const css = cellFillPaddingPreviewStyle(
      cell('bg-gray-100 dark:bg-blue-100', { backgroundColor: '#000' }),
      fillCatalog,
      'dark',
    ) as Record<string, unknown>;
    expect(css.backgroundColor).toBe('#dbeafe');
  });

  it('dark 스킴 — 다크 토큰 없으면 배경 미지정(인라인 무시)', () => {
    const css = cellFillPaddingPreviewStyle(cell('bg-gray-100', { backgroundColor: '#000' }), fillCatalog, 'dark') as Record<string, unknown>;
    expect(css.backgroundColor).toBeUndefined();
  });

  it('카탈로그 미공급 시 라이트 인라인만(다크 토큰 매핑 불가 — graceful)', () => {
    const css = cellFillPaddingPreviewStyle(cell('bg-gray-100', { backgroundColor: '#abc' })) as Record<string, unknown>;
    expect(css.backgroundColor).toBe('#abc');
  });
});
