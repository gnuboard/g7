/**
 * @file DataGrid.nonSelectableIds.test.tsx
 * @description 선택할 수 없는 행(nonSelectableIds) — 회원 목록의 본인 행 (#703)
 *
 * 회원 일괄 상태 변경은 로그인한 본인이 대상에 섞이면 서버가 요청 전체를 422 로 거절한다
 * (ExcludeCurrentUser). 화면이 본인 행을 고를 수 있게 두면 운영자는 누를 수 있는 버튼으로
 * 실패만 받는다. 표가 그 행의 체크박스를 끄고 전체 선택에서도 빼야 요청에 실리지 않는다.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { DataGrid, DataGridColumn } from '../DataGrid';

const columns: DataGridColumn[] = [{ field: 'name', header: '이름' }];
const rows = [
  { id: 'me', name: '나' },
  { id: 'a', name: '회원A' },
  { id: 'b', name: '회원B' },
];

describe('DataGrid — nonSelectableIds', () => {
  /**
   * @scenario defect=bulk_status_self_included
   * @effects self_row_checkbox_disabled
   */
  it('선택할 수 없는 행의 체크박스는 비활성이다', () => {
    render(
      <DataGrid columns={columns} data={rows} selectable selectedIds={[]} onSelectionChange={vi.fn()} idField="id" nonSelectableIds={['me']} />
    );

    const boxes = screen.getAllByRole('checkbox');
    // [0] 은 머리글 전체 선택, 이후 행 순서
    expect(boxes[1]).toBeDisabled();
    expect(boxes[2]).not.toBeDisabled();
    expect(boxes[3]).not.toBeDisabled();
  });

  /**
   * @scenario defect=bulk_status_self_included
   * @effects select_all_skips_non_selectable_rows
   */
  it('전체 선택은 선택할 수 없는 행을 빼고 고른다', async () => {
    const onSelectionChange = vi.fn();
    render(
      <DataGrid columns={columns} data={rows} selectable selectedIds={[]} onSelectionChange={onSelectionChange} idField="id" nonSelectableIds={['me']} />
    );

    await userEvent.click(screen.getAllByRole('checkbox')[0]);

    expect(onSelectionChange).toHaveBeenCalledWith(['a', 'b']);
  });

  /**
   * 고를 수 있는 행이 전부 선택되면 머리글이 「전체 선택됨」 이어야 다시 눌러 해제할 수 있다 —
   * 본인 행까지 세면 영영 전체 선택이 아니어서 누를 때마다 다시 고르기만 한다.
   *
   * @scenario defect=bulk_status_self_included
   * @effects select_all_skips_non_selectable_rows
   */
  it('고를 수 있는 행이 모두 선택되면 머리글을 눌러 해제할 수 있다', async () => {
    const onSelectionChange = vi.fn();
    render(
      <DataGrid columns={columns} data={rows} selectable selectedIds={['a', 'b']} onSelectionChange={onSelectionChange} idField="id" nonSelectableIds={['me']} />
    );

    const header = screen.getAllByRole('checkbox')[0] as HTMLInputElement;
    expect(header.checked).toBe(true);

    await userEvent.click(header);

    expect(onSelectionChange).toHaveBeenCalledWith([]);
  });

  /**
   * @scenario defect=bulk_status_self_included
   * @effects self_row_checkbox_disabled
   */
  it('선택 목록에 남아 있던 선택 불가 행은 page 범위 정리에서 빠진다', () => {
    const onSelectionChange = vi.fn();
    render(
      <DataGrid columns={columns} data={rows} selectable selectedIds={['me', 'a']} onSelectionChange={onSelectionChange} idField="id" selectionScope="page" nonSelectableIds={['me']} />
    );

    expect(onSelectionChange).toHaveBeenCalledWith(['a']);
  });
});
