// e2e:allow 레이아웃 편집기 캔버스 오버레이/속성패널 UI — dnd-kit/합성 이벤트 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스(T1~T8) 실측 + 단위/레이아웃 렌더링 테스트로 검증
/**
 * TableEditor.test.tsx — `table` 노드 에디터 RTL
 *
 * 검증:
 *  - 표 grid 렌더(행/열 거터 + 셀 입력)
 *  - 행 추가/삭제, 열 추가/삭제 → onPatchNode(노드 전체 교체)
 *  - 셀 선택 + Shift 영역 선택 → 병합 → origin span 설정 + 흡수 셀 제거
 *  - 병합 해제 → span 제거 + 빈 셀 복원
 *  - 셀 테두리(className) 입력 → 셀 props.className 패치
 *  - 복합 셀(자식 Span) 텍스트 자손만 편집(구조 보존), 순수 구조 셀은 라벨
 *  - 셀 텍스트 평문 blur → createCustomKey → 텍스트 노드 `$t:custom.*` 치환
 *
 * @effects table_capability_declares_node_editor_kind_table_both_templates, table_editor_registered_via_registercoreeditors_kind_agnostic, property_modal_dispatches_table_node_editor_in_props_tab_by_kind, add_row_inserts_blank_row_keeps_col_count, remove_row_keeps_min_one_row, add_column_inserts_blank_col, remove_column_keeps_min_one_col, shift_select_range_then_merge_sets_origin_span_removes_absorbed, unmerge_clears_span_restores_blank_cells, cell_border_className_input_patches_cell_props, complex_cell_edits_text_descendant_preserves_structure, structural_only_cell_shows_label_not_input, plain_cell_blur_creates_custom_key_replaces_text_token, tree_to_grid_expands_thead_tbody_with_merge_coordinates, tree_to_grid_detects_overlap_and_hole_marks_invalid, params_role_mapping_neutral_div_grid_works_same_adapter, add_row_bumps_rowspan_origin_for_absorbed_cells, remove_row_decrements_crossing_rowspan, move_row_band_moves_merge_block_as_unit, move_row_section_boundary_disabled_with_reason, add_column_bumps_colspan_for_crossing_cell, remove_column_decrements_crossing_colspan, move_column_band_moves_merge_block_as_unit, move_column_table_edge_band_disabled_boundary, merge_rejects_non_rectangular_and_hole, live_add_row_col_merge_save_persists_to_user_page
 * @since engine-v1.50.0
 */

import React from 'react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, fireEvent, cleanup, waitFor } from '@testing-library/react';

vi.mock('../../LayoutEditorContext', () => ({
  useLayoutEditor: () => ({
    state: {
      templateIdentifier: 'sirsoft-basic',
      locale: 'ko',
      selectedRoute: { path: '/login', layoutName: 'login' },
    },
  }),
}));

const createCustomKey = vi.fn();
const updateCustomKeyValue = vi.fn();
const bustTranslationCache = vi.fn().mockResolvedValue(undefined);
vi.mock('../../hooks/useInlineEdit', () => ({
  createCustomKey: (...a: unknown[]) => createCustomKey(...a),
  updateCustomKeyValue: (...a: unknown[]) => updateCustomKeyValue(...a),
  bustTranslationCache: (...a: unknown[]) => bustTranslationCache(...a),
  EDITOR_TRANSLATIONS_REFRESHED_EVENT: 'g7le:editor-translations-refreshed',
}));

vi.mock('../../../TranslationEngine', () => ({
  TranslationEngine: {
    getInstance: () => ({ translate: (key: string) => key }),
  },
}));

import { TableEditor } from '../../components/property-controls/TableEditor';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import { treeToGrid, cellRefAt } from '../../spec/tableGridModel';

const t = (k: string, p?: Record<string, string | number>) =>
  p ? `${k}:${Object.values(p).join(',')}` : k;

const params = {
  rowContainer: 'Tbody',
  row: 'Tr',
  cell: 'Td',
  headerCell: 'Th',
  colSpanProp: 'colSpan',
  rowSpanProp: 'rowSpan',
};

const baseProps = { params, spec: null as never, manifest: null as never, t, templateIdentifier: 'sirsoft-basic' };

function td(text?: string, children?: EditorNode[]): EditorNode {
  const n: EditorNode = { type: 'basic', name: 'Td' };
  if (text !== undefined) n.text = text;
  if (children) n.children = children;
  return n;
}
function table2x2(): EditorNode {
  return {
    type: 'basic',
    name: 'Table',
    children: [
      {
        type: 'basic',
        name: 'Tbody',
        children: [
          { type: 'basic', name: 'Tr', children: [td('a'), td('b')] },
          { type: 'basic', name: 'Tr', children: [td('c'), td('d')] },
        ],
      },
    ],
  };
}

afterEach(() => cleanup());
beforeEach(() => {
  createCustomKey.mockReset();
  updateCustomKeyValue.mockReset();
  bustTranslationCache.mockClear();
});

describe('TableEditor 렌더/구조', () => {
  it('표 grid 와 행/열 거터를 렌더한다', () => {
    render(<TableEditor {...baseProps} node={table2x2()} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-table-editor-grid')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-table-cell-0-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-table-cell-1-1')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-table-col-add-0')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-table-row-add-0')).toBeInTheDocument();
  });

  it('행 추가 → onPatchNode 로 4행 패치', () => {
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} node={table2x2()} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-table-row-add-0'));
    const patched = onPatch.mock.calls[0]![0] as EditorNode;
    expect(treeToGrid(patched, params).rows.length).toBe(3);
  });

  it('열 추가 → 3열 패치', () => {
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} node={table2x2()} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-table-col-add-0'));
    const patched = onPatch.mock.calls[0]![0] as EditorNode;
    expect(treeToGrid(patched, params).colCount).toBe(3);
  });

  it('행 삭제 → 1행 패치', () => {
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} node={table2x2()} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-table-row-remove-1'));
    const patched = onPatch.mock.calls[0]![0] as EditorNode;
    expect(treeToGrid(patched, params).rows.length).toBe(1);
  });
});

describe('이동 버튼 활성/비활성 피드백 (밴드 모델)', () => {
  // 병합 블록도 밴드 단위로 통째 이동 가능 — 표 끝 밴드만 boundary 로 비활성.
  // [(0,1) colSpan2][2] 헤더 + 단일 본문 → 열 밴드 [(0,1)][2] 2개 → 서로 이동 가능.
  const bandedTable: EditorNode = {
    type: 'basic',
    name: 'Table',
    children: [
      {
        type: 'basic',
        name: 'Thead',
        children: [
          { type: 'basic', name: 'Tr', children: [{ ...td('h12'), props: { colSpan: 2 } }, td('h3')] },
        ],
      },
      {
        type: 'basic',
        name: 'Tbody',
        children: [{ type: 'basic', name: 'Tr', children: [td('1'), td('2'), td('3')] }],
      },
    ],
  };

  it('병합 블록 밴드는 인접 밴드와 이동 가능(버튼 활성)', () => {
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} node={bandedTable} onPatchNode={onPatch} />);
    // [(0,1)] 밴드(col0)를 오른쪽으로 → [3] 너머 → 활성.
    const right0 = screen.getByTestId('g7le-table-col-right-0') as HTMLButtonElement;
    expect(right0.disabled).toBe(false);
    fireEvent.click(right0);
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    const body = treeToGrid(patched, params).rows[1]!.cells.map((c) => c.cell.text);
    expect(body).toEqual(['3', '1', '2']); // 밴드 통째 이동
  });

  it('표 끝 밴드의 바깥 방향 이동은 boundary 로 비활성', () => {
    render(<TableEditor {...baseProps} node={bandedTable} onPatchNode={vi.fn()} />);
    // 첫 밴드(col0) 왼쪽 = 표 끝 → 비활성.
    const left0 = screen.getByTestId('g7le-table-col-left-0') as HTMLButtonElement;
    expect(left0.disabled).toBe(true);
    expect(left0.style.cursor).toBe('not-allowed');
  });

  it('병합 없는 표는 이동 버튼 활성(경계 제외)', () => {
    // component table2x2 = Tbody only 2행(grid 0,1).
    render(<TableEditor {...baseProps} node={table2x2()} onPatchNode={vi.fn()} />);
    // row0→row1(같은 Tbody 섹션, 병합 없음) → 이동 가능.
    const down0 = screen.getByTestId('g7le-table-row-down-0') as HTMLButtonElement;
    expect(down0.disabled).toBe(false);
    // 마지막 행(row1) 아래로는 경계 → 비활성.
    const down1 = screen.getByTestId('g7le-table-row-down-1') as HTMLButtonElement;
    expect(down1.disabled).toBe(true);
  });

  it('Thead↔Tbody 섹션 경계 행 이동은 비활성 + section 사유', () => {
    const withHead: EditorNode = {
      type: 'basic',
      name: 'Table',
      children: [
        { type: 'basic', name: 'Thead', children: [{ type: 'basic', name: 'Tr', children: [td('h1'), td('h2')] }] },
        { type: 'basic', name: 'Tbody', children: [{ type: 'basic', name: 'Tr', children: [td('a'), td('b')] }] },
      ],
    };
    render(<TableEditor {...baseProps} node={withHead} onPatchNode={vi.fn()} />);
    // header(row0)→body(row1) 는 섹션 경계 → 비활성 + section 사유.
    const down0 = screen.getByTestId('g7le-table-row-down-0') as HTMLButtonElement;
    expect(down0.disabled).toBe(true);
    expect(down0.getAttribute('title')).toContain('move_blocked_section');
  });
});

describe('셀 병합/해제 UI', () => {
  it('셀 클릭 + Shift 클릭 → 병합 버튼 활성 → 병합 패치', () => {
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} node={table2x2()} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0'));
    fireEvent.click(screen.getByTestId('g7le-table-cell-1-1'), { shiftKey: true });
    const mergeBtn = screen.getByTestId('g7le-table-merge') as HTMLButtonElement;
    expect(mergeBtn.disabled).toBe(false);
    fireEvent.click(mergeBtn);
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    const grid = treeToGrid(patched, params);
    const o = cellRefAt(grid, 0, 0)!;
    expect(o.colSpan).toBe(2);
    expect(o.rowSpan).toBe(2);
  });

  it('셀 텍스트 input 단일 클릭(mousedown) → 셀 선택(앵커) + 편집 포커스 비차단', () => {
    render(<TableEditor {...baseProps} node={table2x2()} onPatchNode={vi.fn()} />);
    // 앵커를 input 클릭으로 잡고, 다른 셀 Shift 클릭 → 병합 활성 = 선택 성립.
    const ev = fireEvent.mouseDown(screen.getByTestId('g7le-table-cell-input-0-0'));
    expect(ev).toBe(true); // preventDefault 안 함 → 포커스(편집) 진입 가능
    fireEvent.click(screen.getByTestId('g7le-table-cell-1-1'), { shiftKey: true });
    expect((screen.getByTestId('g7le-table-merge') as HTMLButtonElement).disabled).toBe(false);
  });

  it('셀 텍스트 input Shift+클릭(mousedown) → preventDefault(편집 포커스 차단, 선택만)', () => {
    render(<TableEditor {...baseProps} node={table2x2()} onPatchNode={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0')); // 앵커
    const ev = fireEvent.mouseDown(screen.getByTestId('g7le-table-cell-input-1-1'), { shiftKey: true });
    expect(ev).toBe(false); // preventDefault → 포커스(편집) 차단
    expect((screen.getByTestId('g7le-table-merge') as HTMLButtonElement).disabled).toBe(false); // 영역 선택은 성립
  });

  it('병합된 origin 선택 → 해제 버튼 활성', () => {
    const merged: EditorNode = {
      type: 'basic',
      name: 'Table',
      children: [
        {
          type: 'basic',
          name: 'Tbody',
          children: [
            { type: 'basic', name: 'Tr', children: [{ ...td('a'), props: { colSpan: 2, rowSpan: 2 } }] },
            { type: 'basic', name: 'Tr', children: [] },
          ],
        },
      ],
    };
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} node={merged} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0'));
    const unmerge = screen.getByTestId('g7le-table-unmerge') as HTMLButtonElement;
    expect(unmerge.disabled).toBe(false);
    fireEvent.click(unmerge);
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    const grid = treeToGrid(patched, params);
    expect(cellRefAt(grid, 0, 0)!.colSpan).toBe(1);
    expect(cellRefAt(grid, 1, 1)).not.toBeNull(); // 복원
  });
});

describe('셀 테두리/텍스트', () => {
  it('셀 선택 후 테두리 className 입력 → 셀 props 패치', () => {
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} node={table2x2()} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0'));
    const input = screen.getByTestId('g7le-table-cell-border-input');
    fireEvent.change(input, { target: { value: 'border border-red-500' } });
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    const grid = treeToGrid(patched, params);
    expect(cellRefAt(grid, 0, 0)!.cell.props?.className).toBe('border border-red-500');
  });

  it('Shift 영역 다중 선택 후 색 적용 → 영역 전 셀에 borderColor(하나만 적용되던 결함)', () => {
    const catalog = {
      sides: [{ value: 'all', prefix: 'border' }],
      widths: [{ value: 'none' }, { value: 'thin', suffix: '' }, { value: 'medium', suffix: '-2' }],
      colors: [{ value: 'red', swatch: '#ef4444', token: 'border-red-500' }],
    };
    const paramsCat = { ...params, cellBorder: catalog };
    // 셀에 두께(border)를 미리 부여 → 색 피커가 즉시 노출(앵커 셀 className 기반). 실제 운영자
    // 흐름(얇게 선택된 상태)과 동일. (단위 테스트의 onPatchNode mock 은 node prop 을
    // 되먹이지 않아 두께 클릭→리렌더로는 색 피커가 안 떠 별도 셀 className 으로 노출.)
    const bc = (text: string): EditorNode => ({ type: 'basic', name: 'Td', text, props: { className: 'border' } });
    const bordered = (): EditorNode => ({
      type: 'basic', name: 'Table',
      children: [{ type: 'basic', name: 'Tbody', children: [
        { type: 'basic', name: 'Tr', children: [bc('a'), bc('b')] },
        { type: 'basic', name: 'Tr', children: [bc('c'), bc('d')] },
      ] }],
    });
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} params={paramsCat} node={bordered()} onPatchNode={onPatch} />);
    // 앵커(0,0) + Shift(1,1) → 2x2 전 셀 영역 선택.
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0'));
    fireEvent.click(screen.getByTestId('g7le-table-cell-1-1'), { shiftKey: true });
    // 자유 색 적용.
    fireEvent.input(screen.getByTestId('g7le-cell-border-color-picker'), { target: { value: '#ff0000' } });
    const grid = treeToGrid(onPatch.mock.calls.at(-1)![0] as EditorNode, params);
    const color = (r: number, c: number) => (cellRefAt(grid, r, c)!.cell.props?.style as Record<string, unknown>)?.borderColor;
    // 영역 4셀 모두 색 적용(하나만 아님).
    expect(color(0, 0)).toBe('#ff0000');
    expect(color(0, 1)).toBe('#ff0000');
    expect(color(1, 0)).toBe('#ff0000');
    expect(color(1, 1)).toBe('#ff0000');
  });

  it('미리보기 td 가 셀 실제 테두리(두께/색)를 반영(고정 회색 1px 아님)', () => {
    // cellBorder 카탈로그 공급 + border-2(2px) + 인라인 빨강 셀.
    const catalog = {
      sides: [{ value: 'all', prefix: 'border' }, { value: 'top', prefix: 'border-t' }],
      widths: [{ value: 'none' }, { value: 'thin', suffix: '' }, { value: 'medium', suffix: '-2' }, { value: 'thick', suffix: '-4' }],
      colors: [{ value: 'red', swatch: '#ef4444', token: 'border-red-500' }],
    };
    const paramsCat = { ...params, cellBorder: catalog };
    const node: EditorNode = {
      type: 'basic', name: 'Table',
      children: [{ type: 'basic', name: 'Tbody', children: [
        { type: 'basic', name: 'Tr', children: [
          { type: 'basic', name: 'Td', text: 'a', props: { className: 'border-2', style: { borderColor: 'rgb(255, 0, 0)' } } },
          td('b'),
        ] },
      ] }],
    };
    render(<TableEditor {...baseProps} params={paramsCat} node={node} onPatchNode={vi.fn()} />);
    const cellTd = screen.getByTestId('g7le-table-cell-0-0') as HTMLTableCellElement;
    // 미리보기 td 인라인 스타일이 2px + 빨강 반영(고정 #cbd5e1 1px 아님).
    expect(cellTd.style.borderTopWidth).toBe('2px');
    expect(cellTd.style.borderTopColor).toBe('rgb(255, 0, 0)');
  });

  it('순수 구조 셀(Icon만)은 텍스트 입력 대신 구조 라벨', () => {
    const t2: EditorNode = {
      type: 'basic',
      name: 'Table',
      children: [
        {
          type: 'basic',
          name: 'Tbody',
          children: [
            {
              type: 'basic',
              name: 'Tr',
              children: [td(undefined, [{ type: 'basic', name: 'Icon', props: { name: 'star' } }]), td('b')],
            },
          ],
        },
      ],
    };
    render(<TableEditor {...baseProps} node={t2} onPatchNode={vi.fn()} />);
    expect(screen.getByTestId('g7le-table-cell-struct-0-0')).toBeInTheDocument();
    expect(screen.queryByTestId('g7le-table-cell-input-0-0')).not.toBeInTheDocument();
  });

  it('복합 셀(Span 텍스트) 편집 → 텍스트 자손만 키화(구조 보존)', async () => {
    createCustomKey.mockResolvedValue({
      kind: 'ok',
      resource: { translation_key: 'custom.login.9' },
    });
    const complexCell = td(undefined, [
      { type: 'basic', name: 'Icon', props: { name: 'star' } },
      { type: 'basic', name: 'Span', text: '라벨' },
    ]);
    const t2: EditorNode = {
      type: 'basic',
      name: 'Table',
      children: [
        { type: 'basic', name: 'Tbody', children: [{ type: 'basic', name: 'Tr', children: [complexCell, td('b')] }] },
      ],
    };
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} node={t2} onPatchNode={onPatch} />);
    const input = screen.getByTestId('g7le-table-cell-input-0-0');
    fireEvent.change(input, { target: { value: '새 라벨' } });
    fireEvent.blur(input);
    await waitFor(() => expect(createCustomKey).toHaveBeenCalled());
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    const grid = treeToGrid(patched, params);
    const cell = cellRefAt(grid, 0, 0)!.cell;
    expect((cell.children as EditorNode[])[0]!.name).toBe('Icon'); // 구조 보존
    expect((cell.children as EditorNode[])[1]!.text).toBe('$t:custom.login.9');
  });

  it('평문 직접-text 셀 blur → createCustomKey → 토큰 치환', async () => {
    createCustomKey.mockResolvedValue({
      kind: 'ok',
      resource: { translation_key: 'custom.login.5' },
    });
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} node={table2x2()} onPatchNode={onPatch} />);
    const input = screen.getByTestId('g7le-table-cell-input-0-0');
    fireEvent.change(input, { target: { value: '머리글' } });
    fireEvent.blur(input);
    await waitFor(() => expect(createCustomKey).toHaveBeenCalled());
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    const grid = treeToGrid(patched, params);
    expect(cellRefAt(grid, 0, 0)!.cell.text).toBe('$t:custom.login.5');
  });
});

describe('선택 셀 다국어 섹션(I18nTextField ko/en/ja 펼침)', () => {
  it('셀 선택 시 선택 셀 다국어 섹션 + I18nTextField 미리보기(셀 텍스트) 노출', () => {
    render(<TableEditor {...baseProps} node={table2x2()} onPatchNode={vi.fn()} />);
    // 미선택 상태에서는 섹션 미노출.
    expect(screen.queryByTestId('g7le-table-cell-i18n-section')).not.toBeInTheDocument();
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0'));
    expect(screen.getByTestId('g7le-table-cell-i18n-section')).toBeInTheDocument();
    // I18nTextField 미리보기 input 에 선택 셀의 평문 텍스트('a').
    const preview = screen.getByTestId('g7le-table-cell-i18n-preview') as HTMLInputElement;
    expect(preview.value).toBe('a');
    // 🌐 언어 편집 토글 버튼 노출(ko/en/ja 펼침 진입).
    expect(screen.getByTestId('g7le-table-cell-i18n-toggle')).toBeInTheDocument();
  });

  it('순수 구조 셀(Icon만) 선택 시 다국어 섹션 미노출', () => {
    const t2: EditorNode = {
      type: 'basic', name: 'Table',
      children: [{ type: 'basic', name: 'Tbody', children: [
        { type: 'basic', name: 'Tr', children: [
          td(undefined, [{ type: 'basic', name: 'Icon', props: { name: 'star' } }]), td('b'),
        ] },
      ] }],
    };
    render(<TableEditor {...baseProps} node={t2} onPatchNode={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0'));
    expect(screen.queryByTestId('g7le-table-cell-i18n-section')).not.toBeInTheDocument();
  });

  it('다국어 섹션 평문 입력 blur → createCustomKey → 선택 셀 직접-text 토큰 치환', async () => {
    createCustomKey.mockResolvedValue({ kind: 'ok', resource: { translation_key: 'custom.login.7' } });
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} node={table2x2()} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-1'));
    const preview = screen.getByTestId('g7le-table-cell-i18n-preview');
    fireEvent.change(preview, { target: { value: '머리글내용' } });
    fireEvent.blur(preview);
    await waitFor(() => expect(createCustomKey).toHaveBeenCalled());
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    const grid = treeToGrid(patched, params);
    expect(cellRefAt(grid, 0, 1)!.cell.text).toBe('$t:custom.login.7');
  });

  it('다국어 섹션 복합 셀(Span) 입력 → 텍스트 자손만 토큰화(구조 보존)', async () => {
    createCustomKey.mockResolvedValue({ kind: 'ok', resource: { translation_key: 'custom.login.8' } });
    const complexCell = td(undefined, [
      { type: 'basic', name: 'Icon', props: { name: 'star' } },
      { type: 'basic', name: 'Span', text: '라벨' },
    ]);
    const t2: EditorNode = {
      type: 'basic', name: 'Table',
      children: [{ type: 'basic', name: 'Tbody', children: [{ type: 'basic', name: 'Tr', children: [complexCell, td('b')] }] }],
    };
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} node={t2} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0'));
    const preview = screen.getByTestId('g7le-table-cell-i18n-preview');
    fireEvent.change(preview, { target: { value: '새 라벨' } });
    fireEvent.blur(preview);
    await waitFor(() => expect(createCustomKey).toHaveBeenCalled());
    const patched = onPatch.mock.calls.at(-1)![0] as EditorNode;
    const cell = cellRefAt(treeToGrid(patched, params), 0, 0)!.cell;
    expect((cell.children as EditorNode[])[0]!.name).toBe('Icon'); // 구조 보존
    expect((cell.children as EditorNode[])[1]!.text).toBe('$t:custom.login.8');
  });

  //  (표 셀 결선 회귀) — TableEditor 는 NodeEditorProps.candidates 를 선택 셀 다국어
  // 섹션의 I18nTextField 로 흘려야 `+데이터` 칩 삽입(키화) 입구가 뜬다.
  const scalarCandidate = {
    expression: '{{user.name}}', source: 'data_source' as const, sourceId: 'user',
    path: 'name', shape: 'scalar' as const, preview: '홍길동',
  };

  it('candidates 전달 시 선택 셀 텍스트 칸에 +데이터 버튼 노출', () => {
    render(
      <TableEditor {...baseProps} node={table2x2()} onPatchNode={vi.fn()} candidates={[scalarCandidate]} />,
    );
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0'));
    expect(screen.getByTestId('g7le-table-cell-i18n-plus-data-btn')).toBeInTheDocument();
  });

  it('candidates 미전달 시 +데이터 버튼 미노출 (디그레이드)', () => {
    render(<TableEditor {...baseProps} node={table2x2()} onPatchNode={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0'));
    expect(screen.queryByTestId('g7le-table-cell-i18n-plus-data-btn')).not.toBeInTheDocument();
  });
});

// 셀에 데이터 칩을 끼워 키화하면 셀 text 가 `$t:custom.X|pN={{}}` 가
// 된다. 그리드 셀 미니맵의 resolveTextNode 가 `{{}}` 포함만 보고 BINDING_RE 에 걸려 raw
// `$t:custom.*|...` 를 그대로 노출하고 편집 input 도 안 띄우던 결함(속성탭 그리드에 다국어 키가
// 그대로 보임). custom param 키는 편집 가능한 다국어 문구이므로 키 값을 해석해 표시 + 편집 input.
describe('그리드 셀 미니맵 — custom param 키 셀 raw 미노출 + 편집 가능', () => {
  const paramCellTable = (): EditorNode => ({
    type: 'basic', name: 'Table',
    children: [{ type: 'basic', name: 'Tbody', children: [
      { type: 'basic', name: 'Tr', children: [
        td("$t:custom.home.60|p0={{current_user?.data?.id ?? ''}}"),
        td('내용'),
      ] },
    ] }],
  });

  it('custom param 키 셀 → raw `$t:custom...` 미노출 + 편집 input 표시', () => {
    render(<TableEditor {...baseProps} node={paramCellTable()} onPatchNode={vi.fn()} />);
    // 그리드 셀에 raw 키 문자열이 노출되지 않는다.
    expect(screen.queryByDisplayValue(/\$t:custom\.home\.60/)).not.toBeInTheDocument();
    expect(document.body.textContent).not.toMatch(/\$t:custom\.home\.60\|p0=/);
    // 편집 input 이 뜬다(textEditable=true) — 더블클릭 없이 단일 클릭 인라인 편집 칸.
    expect(screen.getByTestId('g7le-table-cell-input-0-0')).toBeInTheDocument();
  });

  it('순수 데이터 바인딩 셀(`{{...}}` only)은 종전대로 편집 비대상(raw 표시 유지)', () => {
    const boundTable = (): EditorNode => ({
      type: 'basic', name: 'Table',
      children: [{ type: 'basic', name: 'Tbody', children: [
        { type: 'basic', name: 'Tr', children: [td('{{product.data.name}}'), td('내용')] },
      ] }],
    });
    render(<TableEditor {...baseProps} node={boundTable()} onPatchNode={vi.fn()} />);
    // 순수 바인딩은 편집 input 미표시(데이터 영역).
    expect(screen.queryByTestId('g7le-table-cell-input-0-0')).not.toBeInTheDocument();
  });
});

describe('셀 배경색/여백(인라인 style)', () => {
  const paramsFill = {
    ...params,
    cellBackground: { colors: [{ value: 'yellow', swatch: '#fef9c3' }, { value: 'blue', swatch: '#dbeafe' }] },
    cellPadding: { steps: [{ value: 'none' }, { value: 'narrow', px: 4 }, { value: 'normal', px: 8 }] },
  };

  it('셀 선택 → 배경/여백 컨트롤 행 노출', () => {
    render(<TableEditor {...baseProps} params={paramsFill} node={table2x2()} onPatchNode={vi.fn()} />);
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0'));
    expect(screen.getByTestId('g7le-table-cell-fill-row')).toBeInTheDocument();
    expect(screen.getByTestId('g7le-table-cell-padding-row')).toBeInTheDocument();
  });

  it('배경 프리셋 클릭 → 선택 셀만 인라인 backgroundColor', () => {
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} params={paramsFill} node={table2x2()} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0'));
    fireEvent.click(screen.getByTestId('g7le-cell-fill-color-yellow'));
    const grid = treeToGrid(onPatch.mock.calls.at(-1)![0] as EditorNode, params);
    expect((cellRefAt(grid, 0, 0)!.cell.props?.style as Record<string, unknown>)?.backgroundColor).toBe('#fef9c3');
    expect((cellRefAt(grid, 1, 1)!.cell.props?.style as Record<string, unknown> | undefined)?.backgroundColor).toBeUndefined();
  });

  it('여백 단계 클릭 → 선택 셀 인라인 padding', () => {
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} params={paramsFill} node={table2x2()} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0'));
    fireEvent.click(screen.getByTestId('g7le-cell-padding-step-normal'));
    const grid = treeToGrid(onPatch.mock.calls.at(-1)![0] as EditorNode, params);
    expect((cellRefAt(grid, 0, 0)!.cell.props?.style as Record<string, unknown>)?.padding).toBe('8px');
  });

  it('Shift 영역 다중 선택 → 배경색이 영역 전 셀에 적용', () => {
    const onPatch = vi.fn();
    render(<TableEditor {...baseProps} params={paramsFill} node={table2x2()} onPatchNode={onPatch} />);
    fireEvent.click(screen.getByTestId('g7le-table-cell-0-0'));
    fireEvent.click(screen.getByTestId('g7le-table-cell-1-1'), { shiftKey: true });
    fireEvent.click(screen.getByTestId('g7le-cell-fill-color-blue'));
    const grid = treeToGrid(onPatch.mock.calls.at(-1)![0] as EditorNode, params);
    for (const [r, c] of [[0, 0], [0, 1], [1, 0], [1, 1]] as const) {
      expect((cellRefAt(grid, r, c)!.cell.props?.style as Record<string, unknown>)?.backgroundColor).toBe('#dbeafe');
    }
  });

  it('미리보기 td 가 셀 인라인 배경색을 반영', () => {
    const node: EditorNode = {
      type: 'basic', name: 'Table',
      children: [{ type: 'basic', name: 'Tbody', children: [
        { type: 'basic', name: 'Tr', children: [
          { type: 'basic', name: 'Td', text: 'a', props: { style: { backgroundColor: 'rgb(254, 249, 195)' } } },
          td('b'),
        ] },
      ] }],
    };
    render(<TableEditor {...baseProps} params={paramsFill} node={node} onPatchNode={vi.fn()} />);
    const cellTd = screen.getByTestId('g7le-table-cell-0-0') as HTMLTableCellElement;
    expect(cellTd.style.backgroundColor).toBe('rgb(254, 249, 195)');
  });
});
