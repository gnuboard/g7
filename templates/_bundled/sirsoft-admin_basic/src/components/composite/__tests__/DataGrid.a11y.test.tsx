/**
 * @file DataGrid.a11y.test.tsx
 * @description DataGrid 선택 체크박스·펼침 단추 접근 가능한 이름 (7.1.0 영역 J D-J5, 관측 O-CF5)
 *
 * 목록 머리·행의 선택 체크박스에 접근 가능한 이름이 없어 화면 읽기 프로그램에 「체크박스」 만 읽혔다.
 * 펼침 단추는 언어와 무관하게 영어(「Expand row」·「Collapse card」)였다. 이름은 사람이 읽는 다국어 문구로 단다.
 */

import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { DataGrid, DataGridColumn } from '../DataGrid';

const DICT: Record<string, string> = {
  'common.select_all': '전체 선택',
  'common.select_row': '행 선택',
  'common.expand': '펼치기',
  'common.collapse': '접기',
};

const columns: DataGridColumn[] = [{ field: 'name', header: '이름' }];
const rows = [
  { id: 'a', name: '회원A' },
  { id: 'b', name: '' },
];

describe('DataGrid 접근성 — 선택 체크박스 이름', () => {
  beforeEach(() => {
    (window as any).G7Core = {
      t: (key: string, params?: Record<string, string>) =>
        key === 'common.select_item' ? `${params?.name} 선택` : (DICT[key] ?? key),
    };
  });
  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario control=datagrid_checkbox
   * @effects datagrid_checkboxes_named_by_locale
   */
  it('머리 체크박스는 「전체 선택」, 행 체크박스는 첫 열 값으로 「회원A 선택」, 값이 없으면 「행 선택」', () => {
    render(<DataGrid columns={columns} data={rows} selectable selectedIds={[]} onSelectionChange={vi.fn()} idField="id" />);

    expect(screen.getByRole('checkbox', { name: '전체 선택' })).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: '회원A 선택' })).toBeTruthy();
    expect(screen.getByRole('checkbox', { name: '행 선택' })).toBeTruthy();
  });

  /**
   * @scenario control=datagrid_expand
   * @effects datagrid_checkboxes_named_by_locale
   */
  it('펼침 단추 이름이 영어 고정이 아니라 번역 문구다', () => {
    render(
      <DataGrid columns={columns} data={rows} idField="id" expandable expandChildren={[{ type: 'basic', name: 'Div', text: 'x' }] as any} />
    );
    const expandButtons = screen.getAllByRole('button', { name: '펼치기' });
    expect(expandButtons.length).toBeGreaterThan(0);
    expect(screen.queryByRole('button', { name: /Expand row|Expand card/ })).toBeNull();
  });
});

describe('DataGrid 접근성 — 내장 컬럼 선택', () => {
  beforeEach(() => {
    (window as any).G7Core = { t: (key: string) => DICT[key] ?? key };
  });
  afterEach(() => {
    delete (window as any).G7Core;
  });

  /**
   * @scenario control=column_checkbox
   * @effects datagrid_checkboxes_named_by_locale
   */
  it('내장 컬럼 선택 목록의 체크박스는 열 이름으로 읽힌다(7.1.0 J D-J13)', async () => {
    const { fireEvent } = await import('@testing-library/react');
    const cols: DataGridColumn[] = [
      { field: 'name', header: '작업명', required: true },
      { field: 'next', header: '다음 실행 예정' },
    ];
    render(<DataGrid columns={cols} data={rows} idField="id" showColumnSelector />);
    const trigger = screen.queryAllByRole('button').find((b) => /column_selector|컬럼/.test(b.textContent ?? ''));
    expect(trigger).toBeDefined();
    fireEvent.click(trigger!);
    expect(screen.getByRole('checkbox', { name: '다음 실행 예정' })).toBeTruthy();
  });
});
