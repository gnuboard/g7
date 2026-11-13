/**
 * @file DataGrid.dynamicColumns.test.tsx
 * @description DataGrid 의 동적 열(dynamicColumns)과 점 경로 값, 빈 값 글자 (#490 6단계: 회원 목록이 첫 사용)
 *
 *  - 동적 열은 `dynamicColumnsInsertAfter` 가 가리키는 열 뒤에 순서대로 끼이고, 그 열이 없으면 끝에 붙는다.
 *  - 열의 `field` 가 점 경로(`custom_fields.job`)면 행의 하위 객체에서 값을 읽는다. 행에 그 이름의 키가 그대로 있으면 그 값이 먼저다.
 *  - `emptyText` 는 값이 비었을 때(null, undefined, 빈 문자열)만 쓰인다. 0, false 는 값이다.
 *  - `hidden` 인 동적 열은 표가 스스로 켜지 않는다(0단계 S9: 종전에는 hidden 이어도 켜졌다).
 *  - 동적 열이 없으면 종전과 같다.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { DataGrid } from '../DataGrid';

const columns = [
  { field: 'name', header: '이름' },
  { field: 'nickname', header: '닉네임' },
  { field: 'status', header: '상태' },
];

const rows = [
  { uuid: 'u-1', name: '김하나', nickname: '하나', status: '활성', custom_fields: { job: '개발자', birth: null, point: 0 } },
  { uuid: 'u-2', name: '이둘', nickname: '둘', status: '휴면', custom_fields: { job: '', birth: '1990-01-01', point: 12 } },
];

/**
 * 표 머리글 글자를 순서대로 읽습니다.
 *
 * @returns 머리글 목록
 */
const headers = (): string[] => screen.getAllByRole('columnheader').map((th) => th.textContent?.trim() ?? '');

/**
 * 한 행의 셀 글자를 순서대로 읽습니다.
 *
 * @param rowIndex 0부터 세는 데이터 행 번호
 * @returns 셀 글자 목록
 */
const cells = (rowIndex: number): string[] =>
  Array.from(screen.getAllByRole('row')[rowIndex + 1].querySelectorAll('td')).map((td) => td.textContent?.trim() ?? '');

describe('DataGrid 동적 열', () => {
  /**
   * @scenario case=grid_merge
   * @effects dynamic_columns_inserted_after_named_field_in_order
   */
  it('동적 열은 지정한 열 뒤에 준 순서대로 끼인다', () => {
    render(
      <DataGrid
        columns={columns as any}
        data={rows}
        idField="uuid"
        dynamicColumns={[
          { field: 'custom_fields.job', header: '직업' },
          { field: 'custom_fields.birth', header: '생년월일' },
        ] as any}
        dynamicColumnsInsertAfter="nickname"
      />,
    );

    expect(headers()).toEqual(['이름', '닉네임', '직업', '생년월일', '상태']);
  });

  /**
   * @scenario case=grid_merge
   * @effects dynamic_columns_appended_when_anchor_missing
   */
  it('끼울 자리의 열이 없거나 자리를 주지 않으면 끝에 붙는다', () => {
    const dynamic = [{ field: 'custom_fields.job', header: '직업' }] as any;
    const { unmount } = render(
      <DataGrid columns={columns as any} data={rows} idField="uuid" dynamicColumns={dynamic} dynamicColumnsInsertAfter="no_such_field" />,
    );
    expect(headers()).toEqual(['이름', '닉네임', '상태', '직업']);
    unmount();

    render(<DataGrid columns={columns as any} data={rows} idField="uuid" dynamicColumns={dynamic} />);
    expect(headers()).toEqual(['이름', '닉네임', '상태', '직업']);
  });

  /**
   * @scenario case=grid_merge
   * @effects no_dynamic_columns_keeps_static_columns
   */
  it('동적 열이 없으면(빈 배열, 미지정) 정적 열 그대로다', () => {
    const { unmount } = render(
      <DataGrid columns={columns as any} data={rows} idField="uuid" dynamicColumns={[]} dynamicColumnsInsertAfter="nickname" />,
    );
    expect(headers()).toEqual(['이름', '닉네임', '상태']);
    expect(cells(0)).toEqual(['김하나', '하나', '활성']);
    unmount();

    render(<DataGrid columns={columns as any} data={rows} idField="uuid" />);
    expect(headers()).toEqual(['이름', '닉네임', '상태']);
  });

  /**
   * @scenario case=grid_cell_value
   * @effects cell_value_read_by_dot_path
   */
  it('field 가 점 경로면 행의 하위 객체에서 값을 읽고, 값이 비면 emptyText 를 그린다', () => {
    render(
      <DataGrid
        columns={columns as any}
        data={rows}
        idField="uuid"
        dynamicColumns={[
          { field: 'custom_fields.job', header: '직업', emptyText: '-' },
          { field: 'custom_fields.birth', header: '생년월일', emptyText: '-' },
          { field: 'custom_fields.none', header: '없는 항목', emptyText: '-' },
        ] as any}
        dynamicColumnsInsertAfter="nickname"
      />,
    );

    // 값 있음, null, 키 없음
    expect(cells(0)).toEqual(['김하나', '하나', '개발자', '-', '-', '활성']);
    // 빈 문자열, 값 있음, 키 없음
    expect(cells(1)).toEqual(['이둘', '둘', '-', '1990-01-01', '-', '휴면']);
  });

  /**
   * @scenario case=grid_cell_value
   * @effects empty_text_only_for_empty_values
   */
  it('emptyText 는 빈 값에만 쓰인다: 0 은 값이고, emptyText 가 없는 열의 빈 값은 빈 칸이다', () => {
    render(
      <DataGrid
        columns={columns as any}
        data={rows}
        idField="uuid"
        dynamicColumns={[
          { field: 'custom_fields.point', header: '점수', emptyText: '-' },
          { field: 'custom_fields.birth', header: '생년월일' },
        ] as any}
      />,
    );

    expect(cells(0)).toEqual(['김하나', '하나', '활성', '0', '']);
    expect(cells(1)).toEqual(['이둘', '둘', '휴면', '12', '1990-01-01']);
  });

  /**
   * @scenario case=grid_cell_value
   * @effects literal_row_key_wins_over_dot_path
   */
  it('행에 점이 든 이름의 키가 그대로 있으면 그 값이 경로 해석보다 먼저다', () => {
    render(
      <DataGrid
        columns={[{ field: 'a.b', header: '점 키' }] as any}
        data={[{ id: 1, 'a.b': '그대로', a: { b: '경로' } }]}
      />,
    );

    expect(cells(0)).toEqual(['그대로']);
  });

  /**
   * @scenario case=grid_cell_value
   * @effects custom_render_receives_dot_path_value
   */
  it('render 함수가 있는 열도 점 경로로 읽은 값을 받는다', () => {
    const renderCell = vi.fn((value: any) => `[${value}]`);
    render(
      <DataGrid columns={[{ field: 'custom_fields.job', header: '직업', render: renderCell }] as any} data={[rows[0]]} idField="uuid" />,
    );

    expect(renderCell).toHaveBeenCalledWith('개발자', rows[0]);
    expect(cells(0)).toEqual(['[개발자]']);
  });

  /**
   * @scenario case=grid_visibility
   * @effects hidden_dynamic_column_not_auto_shown
   */
  it('표가 스스로 열을 고를 때 hidden 인 동적 열은 켜지 않고, 나머지 새 동적 열은 켠다', () => {
    const onChange = vi.fn();
    render(
      <DataGrid
        columns={columns as any}
        data={rows}
        idField="uuid"
        showColumnSelector
        dynamicColumns={[
          { field: 'custom_fields.job', header: '직업' },
          { field: 'custom_fields.birth', header: '생년월일', hidden: true },
        ] as any}
        dynamicColumnsInsertAfter="nickname"
        onColumnVisibilityChange={onChange}
      />,
    );

    expect(headers()).toEqual(['이름', '닉네임', '직업', '상태']);
    expect(onChange).toHaveBeenCalledWith(['name', 'nickname', 'status', 'custom_fields.job']);
  });

  /**
   * @scenario case=grid_visibility
   * @effects external_visible_columns_decide_dynamic_columns
   */
  it('바깥에서 보이는 열을 주면 그 목록대로만 그린다(동적 열도 목록에 있어야 보인다)', () => {
    const dynamic = [
      { field: 'custom_fields.job', header: '직업' },
      { field: 'custom_fields.birth', header: '생년월일' },
    ] as any;
    const { rerender } = render(
      <DataGrid
        columns={columns as any}
        data={rows}
        idField="uuid"
        showColumnSelector={false}
        visibleColumns={['name', 'custom_fields.birth']}
        dynamicColumns={dynamic}
        dynamicColumnsInsertAfter="nickname"
      />,
    );
    expect(headers()).toEqual(['이름', '생년월일']);

    // 목록 순서가 아니라 열 정의 순서대로 놓인다
    rerender(
      <DataGrid
        columns={columns as any}
        data={rows}
        idField="uuid"
        showColumnSelector={false}
        visibleColumns={['custom_fields.birth', 'status', 'custom_fields.job', 'name']}
        dynamicColumns={dynamic}
        dynamicColumnsInsertAfter="nickname"
      />,
    );
    expect(headers()).toEqual(['이름', '직업', '생년월일', '상태']);
  });
});
