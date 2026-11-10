/**
 * 눌러 쓰는 비단추 요소의 키보드 접근 — 관리자 부품 나머지 (#869, 설계 확정 2026-11-08 「전부 #869 에서 고침」)
 *
 * 하단 저작권과 버전 칸, 태그 지우기, 다국어 태그 이름(편집 창 열기), 프로필 메뉴의 내 정보 칸, 버전 목록 항목이 클릭만 받았다.
 * 표 열 선택의 체크박스는 아무 일도 하지 않는 onChange 라 Space 로 켜고 끌 수 없었다.
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, fireEvent } from '@testing-library/react';
import { AdminFooter } from '../composite/AdminFooter';
import { TagSelect } from '../composite/TagSelect';
import { MultilingualTagInput } from '../composite/MultilingualTagInput';
import { UserProfile } from '../composite/UserProfile';
import { VersionList } from '../composite/VersionList';
import { DataGrid } from '../composite/DataGrid';
import { PermissionTree } from '../composite/PermissionTree';

const press = (el: Element, key: 'Enter' | ' ') => fireEvent.keyDown(el, { key });

describe('키보드 접근 — 관리자 부품 나머지', () => {
  let dispatch: ReturnType<typeof vi.fn>;
  beforeEach(() => {
    dispatch = vi.fn();
    (window as any).G7Core = { ...((window as any).G7Core ?? {}), dispatch };
  });

  // @scenario element=footer_link
  // @effects clickable_element_keyboard_operable
  it('하단 저작권과 버전 칸: 창을 여는 칸은 단추이고 Enter 로 연다', () => {
    render(<AdminFooter copyright="© G7" version="7.2.0" copyrightModalId="m_copy" changelogModalId="m_log" />);
    const copyright = screen.getByText('© G7');
    expect(copyright.getAttribute('role')).toBe('button');
    expect((copyright as HTMLElement).tabIndex).toBe(0);
    press(copyright, 'Enter');
    const version = screen.getByText('v7.2.0').closest('[role="button"]') as HTMLElement;
    expect(version).not.toBeNull();
    press(version, ' ');
    expect(dispatch).toHaveBeenCalledTimes(2);
  });

  // @scenario element=tag_remove
  // @effects clickable_element_keyboard_operable
  it('태그 지우기: Tab 으로 닿고 Enter 로 지운다', () => {
    const onChange = vi.fn();
    render(<TagSelect options={[{ value: 'a', label: '가' }, { value: 'b', label: '나' }]} value={['a', 'b']} onChange={onChange} />);
    const remove = screen.getAllByRole('button').find((b) => b.className.includes('tag-remove')) as HTMLElement;
    expect(remove.tabIndex).toBe(0);
    press(remove, 'Enter');
    expect(onChange).toHaveBeenCalledWith(['b']);
  });

  // @scenario element=multilingual_tag
  // @effects clickable_element_keyboard_operable
  it('다국어 태그 이름: 단추이고 Enter 로 편집 창을 연다', () => {
    render(<MultilingualTagInput name="values" value={[{ ko: '빨강', en: 'Red' }]} />);
    const label = screen.getByText(/빨강|Red/).closest('[role="button"]') as HTMLElement;
    expect(label).not.toBeNull();
    expect(label.tabIndex).toBe(0);
    press(label, 'Enter');
    expect(document.querySelector('[role="dialog"]')).not.toBeNull();
  });

  // @scenario element=profile_detail
  // @effects clickable_element_keyboard_operable
  it('프로필 메뉴의 내 정보 칸: 단추이고 Enter 로 회원 상세로 간다', () => {
    // uuid 가 있으면 내 정보 칸이 링크(<a>)라 키보드는 링크가 받는다 — 링크가 없는 갈래를 본다
    render(<UserProfile user={{ name: '관리자', email: 'a@b.c' } as any} />);
    fireEvent.click(screen.getAllByRole('button')[0]);
    const detail = screen.getAllByText('관리자').map((n) => n.closest('[role="button"][tabindex="0"]')).find(Boolean) as HTMLElement;
    expect(detail).toBeTruthy();
    press(detail, 'Enter');
    expect(dispatch).toHaveBeenCalledWith(expect.objectContaining({ handler: 'navigate' }));
  });

  // @scenario element=version_option
  // @effects clickable_element_keyboard_operable
  it('버전 목록: 고르는 목록(listbox)의 항목(option)이고 Space 로 고른다', () => {
    const onSelect = vi.fn();
    render(<VersionList versions={[{ id: 7, version: '1.0.0', created_at: '2024-01-01T10:00:00Z', changes_summary: { added: 1, removed: 0, modified: 0 } } as any]} selectedId={7} onSelect={onSelect} />);
    expect(screen.getByRole('listbox')).toBeInTheDocument();
    const option = screen.getByRole('option');
    expect(option.getAttribute('aria-selected')).toBe('true');
    expect(option.tabIndex).toBe(0);
    press(option, ' ');
    expect(onSelect).toHaveBeenCalledWith(7);
  });

  // @scenario element=grid_column_toggle
  // @effects clickable_element_keyboard_operable
  it('표 열 선택: 체크박스가 직접 열을 켜고 끈다(Space 는 체크박스의 기본 동작)', () => {
    render(
      <DataGrid
        data={[{ id: 1, a: 'x', b: 'y' }]}
        columns={[{ field: 'a', header: 'A열' }, { field: 'b', header: 'B열' }] as any}
        showColumnSelector
      />,
    );
    const opener = screen.getAllByRole('button').find((b) => /column|열/i.test(b.getAttribute('aria-label') ?? b.textContent ?? '')) as HTMLElement;
    fireEvent.click(opener);
    const checkbox = screen.getByRole('checkbox', { name: 'B열' }) as HTMLInputElement;
    expect(checkbox.checked).toBe(true);
    fireEvent.click(checkbox);
    expect((screen.getByRole('checkbox', { name: 'B열' }) as HTMLInputElement).checked).toBe(false);
  });

  // @scenario element=permission_scope_option
  // @effects clickable_element_keyboard_operable
  it('권한 적용 범위: 펼친 선택지도 Tab 으로 닿고 Enter 로 고른다', () => {
    const onChange = vi.fn();
    const data = [{ id: 1, identifier: 'core', name: '코어', extension_type: 'core', is_assignable: false, leaf_count: 1, children: [
      { id: 2, identifier: 'core.users', name: '회원 관리', is_assignable: false, leaf_count: 1, children: [
        { id: 3, identifier: 'core.users.read', name: '회원 조회', is_assignable: true, leaf_count: 1, resource_route_key: 'user', scope_supported: true, children: [] },
      ] },
    ] }] as any;
    render(<PermissionTree data={data} value={[{ id: 3, scope_type: 'self' }]} scopeOptions={[{ value: null, label: '전체' }, { value: 'self', label: '본인만' }]} onChange={onChange} />);
    const trigger = document.querySelector('[aria-haspopup="listbox"]') as HTMLElement;
    expect(trigger).not.toBeNull();
    press(trigger, 'Enter');
    const option = screen.getAllByRole('option')[0];
    expect(option.tabIndex).toBe(0);
    press(option, 'Enter');
    expect(onChange).toHaveBeenCalled();
    expect(screen.queryAllByRole('option')).toHaveLength(0);
  });
});
