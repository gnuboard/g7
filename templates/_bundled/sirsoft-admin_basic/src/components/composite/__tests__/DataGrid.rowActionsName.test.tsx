/**
 * DataGrid 행 메뉴(…) 트리거의 접근 가능한 이름.
 *
 * 7.1.0 전수 실측 K1 후속 관측 O-KF-1: 관리자 목록(게시판, 반응·스크랩 관리, 회원, 신고)의 행 메뉴
 * 트리거가 글자 없는 아이콘 버튼이라 스크린리더에 이름 없는 「버튼」으로 읽혔다(aria-label 없음).
 * DataGrid 는 작업 열 머리말 문구(`actionsColumnHeader`, 없으면 「작업」)를 트리거 이름으로 넘긴다.
 * 표 보기와 좁은 화면 카드 보기 두 경로 모두 같은 이름을 쓴다.
 */
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { DataGrid } from '../DataGrid';

const columns = [{ field: 'name', header: '이름' }];
const data = [
  { id: 1, name: '첫째' },
  { id: 2, name: '둘째' },
];
const rowActions = [{ id: 'edit', label: '수정' }];

/**
 * 행 메뉴 트리거(aria-haspopup=menu 버튼)를 모두 찾는다.
 *
 * @returns 트리거 버튼 목록
 */
const triggers = () =>
  screen.getAllByRole('button').filter((b) => b.getAttribute('aria-haspopup') === 'menu');

describe('[case:datagrid-row-menu-name] DataGrid 행 메뉴 트리거 이름', () => {
  // @scenario space_below=enough
  // @effects action_menu_trigger_has_accessible_name
  it('표 보기의 행 메뉴 트리거는 작업 열 머리말 문구를 이름으로 쓴다', () => {
    render(<DataGrid columns={columns} data={data} rowActions={rowActions} actionsColumnHeader="관리" />);

    const found = triggers();
    expect(found.length).toBeGreaterThanOrEqual(2);
    found.forEach((button) => expect(button).toHaveAccessibleName('관리'));
  });

  // @scenario space_below=enough
  // @effects action_menu_trigger_has_accessible_name
  it('머리말 문구를 주지 않아도 이름 없는 트리거를 남기지 않는다', () => {
    render(<DataGrid columns={columns} data={data} rowActions={rowActions} />);

    const found = triggers();
    expect(found.length).toBeGreaterThanOrEqual(2);
    found.forEach((button) => expect((button.getAttribute('aria-label') ?? '').trim()).not.toBe(''));
  });
});
