/**
 * 고르거나 지운 직후 포커스가 body 로 빠지지 않는다 (#869 브라우저 실측, 결정 「키보드 접근 전부」)
 *
 * 선택지를 고르면 목록이 닫히고, 태그를 지우면 그 지우기 단추가 사라진다. 그 자리에 있던 포커스가 body 로 빠져
 * 키보드 사용자는 처음부터 다시 Tab 을 눌러야 했다. 목록을 닫으면 연 칸으로, 태그를 지우면 같은 자리의 다음 지우기 단추로,
 * 다음이 없으면 입력칸(입력칸이 없는 부품은 앞 지우기 단추, 그것도 없으면 태그 상자)으로 돌린다.
 *
 * 조합과 효과 표식은 그것을 재는 시험마다 단다.
 */
import { useState } from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen, fireEvent, act } from '@testing-library/react';
import { PermissionTree, PermissionNode, ScopeOption } from '../composite/PermissionTree';
import { MultilingualTagInput } from '../composite/MultilingualTagInput';
import { TagSelect } from '../composite/TagSelect';
import { Dropdown } from '../composite/Dropdown';
import { IconSelect } from '../composite/IconSelect';
import { DropdownButton } from '../composite/DropdownButton';
import { ColumnSelector } from '../composite/ColumnSelector';

const SCOPE_OPTIONS: ScopeOption[] = [
  { value: null, label: '범위-전체' },
  { value: 'role', label: '범위-소유역할' },
  { value: 'self', label: '범위-본인만' },
];
const tree: PermissionNode[] = [
  {
    id: 100, identifier: 'sirsoft-board', name: '게시판', extension_type: 'module', extension_identifier: 'sirsoft-board',
    is_assignable: false, leaf_count: 1,
    children: [{ id: 1, identifier: 'sirsoft-board.qa.posts.read', name: '게시글 조회', is_assignable: true, leaf_count: 1, children: [], resource_route_key: 'post', owner_key: 'user_id' } as any],
  } as any,
];

describe('고르거나 지운 직후 포커스 복귀', () => {
  // @scenario component=permission_scope
  // @effects focus_returns_after_select_or_remove
  it('권한 범위: 선택지를 Enter 로 고르거나 Esc 로 닫으면 여는 칸으로 돌아온다', () => {
    render(<PermissionTree data={tree} value={[{ id: 1, scope_type: null }]} scopeOptions={SCOPE_OPTIONS} onChange={() => {}} />);
    const trigger = screen.getByText('범위-전체').closest('[role="button"]') as HTMLElement;

    fireEvent.click(trigger);
    const option = screen.getAllByRole('option').find((o) => o.textContent?.includes('범위-본인만')) as HTMLElement;
    option.focus();
    fireEvent.keyDown(option, { key: 'Enter' });
    expect(document.activeElement, '고른 뒤 포커스가 빠졌습니다').toBe(trigger);

    fireEvent.click(trigger);
    const again = screen.getAllByRole('option')[0];
    again.focus();
    fireEvent.keyDown(again, { key: 'Escape' });
    expect(screen.queryAllByRole('option')).toHaveLength(0);
    expect(document.activeElement, 'Esc 로 닫은 뒤 포커스가 빠졌습니다').toBe(trigger);
  });

  // @scenario component=multilingual_tag
  // @effects focus_returns_after_select_or_remove
  it('다국어 태그: 지우면 같은 자리의 다음 지우기 단추로, 마지막을 지우면 입력칸으로 간다', () => {
    render(<MultilingualTagInput name="values" value={[{ ko: '빨강' }, { ko: '파랑' }] as any} creatable placeholder="values" />);
    const removers = () => screen.getAllByRole('button').filter((b) => /빨강|파랑/.test(b.getAttribute('aria-label') ?? ''));

    const first = removers()[0];
    first.focus();
    act(() => { fireEvent.click(first); });
    expect(removers()).toHaveLength(1);
    expect(document.activeElement).toBe(removers()[0]);

    act(() => { fireEvent.click(removers()[0]); });
    expect(document.activeElement).toBe(screen.getByRole('textbox', { name: 'values' }));
  });

  // @scenario component=tag_select
  // @effects focus_returns_after_select_or_remove
  it('태그 선택: 마지막을 지우면 앞 지우기 단추로, 모두 지우면 태그 상자로 간다', () => {
    const options = [{ value: 's', label: 'S' }, { value: 'm', label: 'M' }, { value: 'l', label: 'L' }];
    const Host = () => {
      const [value, setValue] = useState<(string | number)[]>(['s', 'm']);
      return <TagSelect id="sizes" options={options} value={value} onChange={setValue} />;
    };
    render(<Host />);
    const removers = () => screen.queryAllByRole('button');

    // 마지막(m)을 지우면 앞 단추(s)로
    act(() => { removers()[1].focus(); fireEvent.click(removers()[1]); });
    expect(removers()).toHaveLength(1);
    expect(document.activeElement).toBe(removers()[0]);

    // 남은 하나를 지우면 태그 상자로
    act(() => { fireEvent.click(removers()[0]); });
    expect(removers()).toHaveLength(0);
    expect(document.activeElement, '모두 지운 뒤 포커스가 빠졌습니다').toBe(document.getElementById('sizes'));
  });

  // @scenario component=dropdown
  // @effects focus_returns_after_select_or_remove
  it('드롭다운: 항목을 Enter 로 고르거나 Esc 로 닫으면 여는 단추로 돌아온다', () => {
    render(<Dropdown label="선택" items={[{ label: '하나', value: '1' }, { label: '둘', value: '2' }]} />);
    const trigger = screen.getByRole('button', { name: /선택/ });

    fireEvent.click(trigger);
    fireEvent.keyDown(document, { key: 'ArrowDown' });
    fireEvent.keyDown(document, { key: 'Enter' });
    expect(document.activeElement, '고른 뒤 포커스가 빠졌습니다').toBe(trigger);

    fireEvent.click(trigger);
    fireEvent.keyDown(document, { key: 'Escape' });
    expect(document.activeElement, 'Esc 로 닫은 뒤 포커스가 빠졌습니다').toBe(trigger);
  });

  // @scenario component=icon_select
  // @effects focus_returns_after_select_or_remove
  it('아이콘 선택: 아이콘을 고르면 여는 단추로 돌아온다', () => {
    render(<IconSelect />);
    const trigger = screen.getAllByRole('button')[0];
    fireEvent.click(trigger);
    const choice = screen.getAllByRole('button').find((b) => b !== trigger) as HTMLElement;
    choice.focus();
    fireEvent.click(choice);
    expect(document.activeElement).toBe(trigger);
  });

  // @scenario component=dropdown_button
  // @effects focus_returns_after_select_or_remove
  it('드롭다운 단추: 메뉴 항목을 고르거나 Esc 로 닫으면 여는 단추로 돌아온다', () => {
    render(
      <DropdownButton label="작업">
        <button type="button">복사</button>
      </DropdownButton>
    );
    const trigger = screen.getByRole('button', { name: /작업/ });
    fireEvent.click(trigger);
    const item = screen.getByRole('button', { name: '복사' });
    item.focus();
    fireEvent.click(item);
    expect(document.activeElement, '고른 뒤 포커스가 빠졌습니다').toBe(trigger);

    fireEvent.click(trigger);
    const again = screen.getByRole('button', { name: '복사' });
    again.focus();
    fireEvent.keyDown(again, { key: 'Escape' });
    expect(screen.queryByRole('button', { name: '복사' })).toBeNull();
    expect(document.activeElement, 'Esc 로 닫은 뒤 포커스가 빠졌습니다').toBe(trigger);
  });

  // @scenario component=column_selector
  // @effects focus_returns_after_select_or_remove
  it('열 선택: 펼친 목록을 Esc 로 닫으면 여는 단추로 돌아온다', () => {
    render(<ColumnSelector id="cols_probe" columns={[{ field: 'a', label: '가' }, { field: 'b', label: '나' }] as any} />);
    const trigger = screen.getAllByRole('button')[0];
    fireEvent.click(trigger);
    const box = screen.getAllByRole('checkbox')[0];
    box.focus();
    fireEvent.keyDown(box, { key: 'Escape' });
    expect(screen.queryAllByRole('checkbox')).toHaveLength(0);
    expect(document.activeElement, 'Esc 로 닫은 뒤 포커스가 빠졌습니다').toBe(trigger);
  });
});
