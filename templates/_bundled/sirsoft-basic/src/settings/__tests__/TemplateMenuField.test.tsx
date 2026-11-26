// e2e:allow Vitest 컴포넌트 렌더 테스트(메뉴 편집기 단추·편집 판). 끌어서 놓기와 저장→사용자 화면은 Playwright E2E·브라우저 실측으로 별도 검증.
/**
 * TemplateMenuField — 템플릿 설정 메뉴 칸 편집기
 *
 * 두 판(왼쪽 트리 · 오른쪽 편집 판)으로 추가·고르기·고치기·하위로/상위로·지우기를 하고 바뀐 트리를 onChange 로 올린다.
 */

import React, { useState } from 'react';
import { fireEvent, render, screen, within } from '@testing-library/react';
import { describe, expect, it, vi } from 'vitest';
import { TemplateMenuField } from '../TemplateMenuField';
import type { TemplateMenuNode } from '../templateMenuTree';

const AUTO = [
  { key: 'home', label: '홈' },
  { key: 'boards', label: '게시판 전체(자동)', hint: '새 게시판이 자동으로 붙습니다.' },
];
const ROLES = [{ identifier: 'user', name: { ko: '일반 회원' } }, { identifier: 'vip', name: 'VIP' }];

const Harness: React.FC<{ initial: TemplateMenuNode[]; onChange?: (next: TemplateMenuNode[]) => void; errors?: Record<string, string[]>; disabled?: boolean; maxItems?: number }> = ({ initial, onChange, errors, disabled, maxItems }) => {
  const [value, setValue] = useState<TemplateMenuNode[]>(initial);

  return (
    <TemplateMenuField
      value={value}
      path="header.menu"
      label="헤더 메뉴"
      autoItems={AUTO}
      roleOptions={ROLES}
      errors={errors}
      disabled={disabled}
      maxItems={maxItems}
      onChange={(next) => {
        setValue(next);
        onChange?.(next);
      }}
    />
  );
};

const rows = () => screen.getAllByTestId('template-menu-row');
const rowTitles = () => rows().map((row) => `${row.getAttribute('data-depth')}:${row.textContent?.replace(/자동|꺼짐|하위 메뉴/g, '').trim()}`);

describe('TemplateMenuField', () => {
  // @scenario case=menu_editor
  // @effects menu_editor_add_edit
  it('빈 메뉴 안내 → 「링크 추가」로 항목이 생기고 바로 편집 판에 고른다', () => {
    const onChange = vi.fn();
    render(<Harness initial={[]} onChange={onChange} />);

    expect(screen.getByTestId('template-menu-empty')).toBeTruthy();
    fireEvent.click(screen.getByTestId('template-menu-add-link'));

    const next = onChange.mock.lastCall?.[0] as TemplateMenuNode[];
    expect(next).toHaveLength(1);
    expect(next[0]).toMatchObject({ kind: 'link', url: '', is_active: true, visibility: 'all' });
    expect(next[0].id).toMatch(/^[A-Za-z0-9_-]{1,40}$/);
    expect(screen.getByTestId('template-menu-url')).toBeTruthy();
  });

  // @scenario case=menu_editor
  // @effects menu_editor_add_edit
  it('자동 항목은 메뉴에 한 번만 — 이미 있으면 추가 단추가 꺼진다', () => {
    render(<Harness initial={[{ id: 'boards', kind: 'boards', children: [] }]} />);

    expect((screen.getByTestId('template-menu-add-auto-boards') as HTMLButtonElement).disabled).toBe(true);
    expect((screen.getByTestId('template-menu-add-auto-home') as HTMLButtonElement).disabled).toBe(false);
  });

  // @scenario case=menu_editor
  // @effects menu_editor_add_edit
  it('고른 링크의 주소·보이기(특정 역할)를 고치면 그 항목만 바뀐다', async () => {
    const onChange = vi.fn();
    render(<Harness initial={[{ id: 'a', kind: 'link', label: { ko: '공지' }, url: '/notice', children: [] }]} onChange={onChange} />);

    fireEvent.click(within(rows()[0]).getByRole('button', { pressed: false }));
    fireEvent.change(screen.getByTestId('template-menu-url'), { target: { value: '/board/notice' } });
    expect((onChange.mock.lastCall?.[0] as TemplateMenuNode[])[0].url).toBe('/board/notice');

    // 보이기 선택은 목록 상자(사용자 정의 드롭다운)다
    fireEvent.click(screen.getByTestId('template-menu-editor').querySelector('[aria-haspopup="listbox"]') as HTMLButtonElement);
    fireEvent.click(within(await screen.findByRole('listbox')).getByRole('option', { name: 'roles' }));
    fireEvent.click(within(screen.getByTestId('template-menu-roles')).getByLabelText('VIP'));

    const item = (onChange.mock.lastCall?.[0] as TemplateMenuNode[])[0];
    expect(item.visibility).toBe('roles');
    expect(item.roles).toEqual(['vip']);
  });

  // @scenario case=menu_editor
  // @effects menu_editor_add_edit
  it('하위로·상위로·하위 메뉴 추가 단추 — 줄의 깊이가 따라 바뀐다', () => {
    render(
      <Harness
        initial={[
          { id: 'a', kind: 'link', label: { ko: '커뮤니티' }, url: '', children: [] },
          { id: 'b', kind: 'link', label: { ko: '자유' }, url: '/free', children: [] },
        ]}
      />,
    );

    fireEvent.click(within(rows()[1]).getByRole('button', { pressed: false }));
    fireEvent.click(screen.getByTestId('template-menu-indent'));
    expect(rowTitles()).toEqual(['0:커뮤니티', '1:자유']);

    fireEvent.click(screen.getByTestId('template-menu-outdent'));
    expect(rowTitles()).toEqual(['0:커뮤니티', '0:자유']);

    fireEvent.click(within(rows()[0]).getByRole('button', { pressed: false }));
    fireEvent.click(screen.getByTestId('template-menu-add-child'));
    expect(rows().map((row) => row.getAttribute('data-depth'))).toEqual(['0', '1', '0']);
  });

  // @scenario case=menu_editor
  // @effects menu_editor_add_edit
  it('지우기는 한 번 더 묻고, 하위 메뉴가 있으면 함께 지운다고 알린다', () => {
    const onChange = vi.fn();
    render(<Harness initial={[{ id: 'a', kind: 'link', label: { ko: '커뮤니티' }, url: '', children: [{ id: 'a1', kind: 'link', label: { ko: '자유' }, url: '/free', children: [] }] }]} onChange={onChange} />);

    fireEvent.click(within(rows()[0]).getByRole('button', { pressed: false }));
    fireEvent.click(screen.getByTestId('template-menu-delete'));
    expect(onChange).not.toHaveBeenCalled();
    expect(screen.getByText('Delete with its sub-menus?')).toBeTruthy();

    fireEvent.click(screen.getByTestId('template-menu-delete-confirm'));
    expect(onChange.mock.lastCall?.[0]).toEqual([]);
  });

  // @scenario case=menu_editor
  // @effects menu_editor_server_errors
  it('서버 오류는 그 항목 줄에 표시가 붙고, 고르면 편집 판에 문구가 보인다', () => {
    render(
      <Harness
        initial={[
          { id: 'a', kind: 'link', label: { ko: '정상' }, url: '/ok', children: [] },
          { id: 'b', kind: 'link', label: { ko: '' }, url: '', children: [] },
        ]}
        errors={{ 'header.menu.1': ['메뉴 이름을 입력하세요.'], 'header.menu.1.url': ['주소 오류'] }}
      />,
    );

    expect(within(rows()[0]).queryByLabelText('Has errors')).toBeNull();
    expect(within(rows()[1]).getByLabelText('Has errors')).toBeTruthy();

    fireEvent.click(within(rows()[1]).getByRole('button', { pressed: false }));
    expect(screen.getByTestId('template-menu-item-error').textContent).toBe('메뉴 이름을 입력하세요.');
    expect(screen.getByText('주소 오류')).toBeTruthy();
  });

  // @scenario case=menu_editor
  // @effects menu_editor_add_edit
  it('읽기 전용이면 추가·끌기·편집 단추가 없다', () => {
    render(<Harness initial={[{ id: 'a', kind: 'link', label: { ko: '공지' }, url: '/n', children: [] }]} disabled />);

    expect(screen.queryByTestId('template-menu-add-link')).toBeNull();
    expect(screen.queryByLabelText(/Drag to move/)).toBeNull();
    fireEvent.click(within(rows()[0]).getByRole('button', { pressed: false }));
    expect(screen.queryByTestId('template-menu-delete')).toBeNull();
  });

  // @scenario case=menu_editor
  // @effects menu_editor_add_edit
  it('전체 개수 상한에 닿으면 추가 단추가 꺼진다', () => {
    render(<Harness initial={[{ id: 'a', kind: 'link', label: { ko: 'a' }, url: '/a', children: [] }]} maxItems={1} />);

    expect((screen.getByTestId('template-menu-add-link') as HTMLButtonElement).disabled).toBe(true);
  });
});
