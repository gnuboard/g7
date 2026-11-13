/**
 * @file DataGridColumnSelectorDynamicParity.test.tsx
 * @description 회원 목록의 표와 열 선택기가 같은 동적 열 집합과 순서를 본다 (#490 6단계, 0단계 S9)
 *
 * 실제 레이아웃(`admin_user_list`, 조각 `_partial_filter_section`)의 정적 열 정의와 배선을 그대로 가져와 진짜 부품 둘을
 * 레이아웃처럼 잇는다: 선택기가 보이는 열을 정해 알리면(전역 상태) 표가 그 목록을 받는다. 두 부품은 같은 동적 열을
 * 같은 자리(닉네임 뒤)에 받는다.
 *
 *  - 선택지 순서 == 표의 열 순서(전부 켰을 때), 동적 열은 닉네임 열 바로 뒤.
 *  - 동적 열의 값은 행의 `custom_fields.{키}` 에서 읽고, 비면 「-」.
 *  - 동적 열이 없으면 표와 선택기가 종전과 같다.
 *  - 열을 숨기거나 켠 결과가 다시 들어와도(새로고침) 남고, 저장값이 있는 사용자에게도 새로 켠 항목 열이 보인다.
 */

import React, { useState } from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, beforeEach } from 'vitest';
import { render, screen, fireEvent, within } from '@testing-library/react';
import { ColumnSelector } from '../ColumnSelector';
import { DataGrid } from '../DataGrid';

type Json = Record<string, any>;

const read = (relative: string): Json => JSON.parse(fs.readFileSync(path.resolve(__dirname, relative), 'utf-8'));
const userList = read('../../../../layouts/admin_user_list.json');
const filterSection = read('../../../../layouts/partials/admin_user_list/_partial_filter_section.json');

/**
 * JSON 트리에서 id 가 같은 첫 노드를 찾습니다.
 *
 * @param root 탐색 시작
 * @param id 찾을 id
 * @returns 노드
 */
function findById(root: unknown, id: string): Json {
  let found: Json | undefined;
  const visit = (value: unknown): void => {
    if (found || !value || typeof value !== 'object') return;
    if (!Array.isArray(value) && (value as Json).id === id) {
      found = value as Json;
      return;
    }
    Object.values(value as Json).forEach(visit);
  };
  visit(root);
  if (!found) throw new Error(`${id} 노드가 없습니다.`);
  return found;
}

const gridNode = findById(userList, 'users_data_grid');
const selectorNode = findById(filterSection, 'column_selector');

// 셀 모양(cellChildren)은 엔진이 그리는 것이라 여기서는 뗀다. 열의 자리와 값 경로만 본다
const gridColumns = (gridNode.props.columns as Json[]).map(({ cellChildren: _cell, ...column }) => column);
const selectorColumns = selectorNode.props.columns as Json[];
const defaultColumns = selectorNode.props.defaultColumns as string[];
const insertAfter = gridNode.props.dynamicColumnsInsertAfter as string;
const storageId = selectorNode.props.id as string;

/** 레이아웃의 computed(memberFieldColumns)가 응답에서 만드는 모양. */
const toDynamic = (columns: Array<{ key: string; field: string; header: string }>) =>
  columns.map((c) => ({ field: c.field, header: c.header, sortable: false, emptyText: '-' }));

const JOB = { key: 'job', field: 'custom_fields.job', header: '직업' };
const BIRTH = { key: 'birth', field: 'custom_fields.birth', header: '생년월일' };

const rows = [
  { uuid: 'u-1', name: '김하나', email: 'one@example.com', nickname: '하나', custom_fields: { job: '개발자', birth: null } },
  { uuid: 'u-2', name: '이둘', email: 'two@example.com', nickname: '둘', custom_fields: { job: null, birth: '1990-01-01' } },
];

/**
 * 레이아웃의 배선을 옮긴 화면: 선택기가 알린 보이는 열을 표가 받는다.
 *
 * @param props 응답의 member_fields.columns
 * @returns 선택기와 표
 */
const Screen: React.FC<{ memberColumns: Array<{ key: string; field: string; header: string }> }> = ({ memberColumns }) => {
  const [visible, setVisible] = useState<string[] | undefined>(undefined);
  const dynamic = toDynamic(memberColumns);

  return (
    <div>
      <ColumnSelector
        id={storageId}
        columns={selectorColumns as any}
        defaultColumns={defaultColumns}
        visibleColumns={visible}
        dynamicColumns={dynamic as any}
        dynamicColumnsInsertAfter={selectorNode.props.dynamicColumnsInsertAfter}
        onColumnVisibilityChange={(fields) => setVisible(fields)}
      />
      <DataGrid
        columns={gridColumns as any}
        data={rows}
        idField="uuid"
        sortable={false}
        showColumnSelector={false}
        visibleColumns={visible}
        dynamicColumns={dynamic as any}
        dynamicColumnsInsertAfter={insertAfter}
      />
    </div>
  );
};

/**
 * 표 머리글을 순서대로 읽습니다.
 *
 * @returns 머리글 목록
 */
const headers = (): string[] => screen.getAllByRole('columnheader').map((th) => th.textContent?.trim() ?? '');

/**
 * 한 열의 셀 글자를 행 순서대로 읽습니다.
 *
 * @param header 머리글
 * @returns 셀 글자 목록
 */
const columnCells = (header: string): string[] => {
  const index = headers().indexOf(header);
  return screen
    .getAllByRole('row')
    .slice(1)
    .map((row) => within(row).getAllByRole('cell')[index]?.textContent?.trim() ?? '');
};

/**
 * 선택지를 열어 둡니다.
 */
const openSelector = (): void => {
  if (screen.queryAllByRole('checkbox').length === 0) fireEvent.click(screen.getByRole('button', { expanded: false }));
};

/**
 * 선택지 이름을 순서대로 읽습니다.
 *
 * @returns 선택지 이름 목록
 */
const optionNames = (): string[] => {
  openSelector();
  return screen.getAllByRole('checkbox').map((box) => box.getAttribute('aria-label') ?? '');
};

/**
 * 선택지 하나를 켜거나 끕니다.
 *
 * @param name 선택지 이름
 */
const toggle = (name: string): void => {
  openSelector();
  fireEvent.click(screen.getByRole('checkbox', { name }));
};

const NICKNAME = '$t:admin.users.columns.nickname';
const PENDING = '$t:admin.users.list_member_fields.pending_column';
const DEFAULT_HEADERS = ['$t:admin.users.columns.name', '$t:admin.users.columns.role', '$t:admin.users.columns.email'];

beforeEach(() => {
  localStorage.clear();
});

describe('회원 목록: 표와 열 선택기의 동적 열 일치', () => {
  /**
   * @scenario case=wired_screen
   * @effects grid_and_selector_same_dynamic_set_and_order
   */
  it('전부 켜면 표의 열 순서가 선택지 순서와 같고, 동적 열은 닉네임 바로 뒤다', () => {
    render(<Screen memberColumns={[JOB, BIRTH]} />);

    const names = optionNames();
    expect(names.slice(names.indexOf(NICKNAME), names.indexOf(NICKNAME) + 4)).toEqual([NICKNAME, '직업', '생년월일', PENDING]);

    // 꺼져 있는 선택지를 전부 켠다
    for (const box of screen.getAllByRole('checkbox')) {
      if (!(box as HTMLInputElement).checked) fireEvent.click(box);
    }

    expect(headers()).toEqual(names);
  });

  /**
   * @scenario case=wired_screen
   * @effects new_member_field_columns_visible_on_first_visit
   */
  it('처음 들어오면 기본 열 뒤에 운영자가 켠 항목 열이 보이고, 값은 custom_fields 에서 읽으며 비면 「-」다', () => {
    render(<Screen memberColumns={[JOB, BIRTH]} />);

    expect(headers()).toEqual([...DEFAULT_HEADERS, '직업', '생년월일']);
    expect(columnCells('직업')).toEqual(['개발자', '-']);
    expect(columnCells('생년월일')).toEqual(['-', '1990-01-01']);
  });

  /**
   * @scenario case=wired_screen
   * @effects zero_member_field_columns_no_change
   */
  it('운영자가 켠 항목 열이 없으면 표와 선택지가 정적 열 그대로다', () => {
    render(<Screen memberColumns={[]} />);

    expect(headers()).toEqual(DEFAULT_HEADERS);
    expect(optionNames()).toEqual(selectorColumns.map((c) => c.header));
    expect(localStorage.length).toBe(0);
  });

  /**
   * @scenario case=wired_screen
   * @effects hidden_and_shown_columns_persist_after_reload
   */
  it('항목 열을 숨기고 다른 열을 켠 결과가 다시 들어와도 남는다', () => {
    const first = render(<Screen memberColumns={[JOB, BIRTH]} />);
    toggle('직업');
    toggle(NICKNAME);
    expect(headers()).toEqual([...DEFAULT_HEADERS, NICKNAME, '생년월일']);
    first.unmount();

    render(<Screen memberColumns={[JOB, BIRTH]} />);
    expect(headers()).toEqual([...DEFAULT_HEADERS, NICKNAME, '생년월일']);
  });

  /**
   * @scenario case=wired_screen
   * @effects saved_user_sees_newly_enabled_member_field_column
   */
  it('열 선택 저장값이 있는 운영자에게도 새로 켠 항목 열이 보이고, 그 운영자가 끈 열은 그대로 꺼져 있다 (S9)', () => {
    // 항목 열이 생기기 전부터 열을 골라 둔 운영자
    localStorage.setItem(`g7_columns_${storageId}`, JSON.stringify(['name', 'role', 'email', 'status']));

    const first = render(<Screen memberColumns={[JOB]} />);
    expect(headers()).toEqual([...DEFAULT_HEADERS, '직업', '$t:admin.users.columns.status']);
    toggle('직업');
    expect(headers()).toEqual([...DEFAULT_HEADERS, '$t:admin.users.columns.status']);
    first.unmount();

    // 환경설정에서 항목 하나를 더 목록 열로 켜고 돌아온다
    render(<Screen memberColumns={[JOB, BIRTH]} />);
    expect(headers()).toEqual([...DEFAULT_HEADERS, '생년월일', '$t:admin.users.columns.status']);
  });

  /**
   * @scenario case=wired_screen
   * @effects removed_member_field_column_leaves_grid_and_selector
   */
  it('운영자가 목록 열에서 뺀 항목은 표와 선택지 양쪽에서 사라진다', () => {
    const first = render(<Screen memberColumns={[JOB, BIRTH]} />);
    expect(headers()).toContain('직업');
    first.unmount();

    render(<Screen memberColumns={[BIRTH]} />);
    expect(headers()).toEqual([...DEFAULT_HEADERS, '생년월일']);
    expect(optionNames()).not.toContain('직업');
  });
});
