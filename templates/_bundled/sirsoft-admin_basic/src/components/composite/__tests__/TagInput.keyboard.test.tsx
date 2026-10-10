/**
 * @file TagInput.keyboard.test.tsx
 * @description 태그 입력 — Enter 키로 옵션 고르기·새 값 만들기·중복 무시·폼 제출 막기
 *
 * 비-creatable 모드는 입력값을 제어하지 않아 내부 inputValue 가 늘 빈 값인데, Enter 가드가 그 값을 보고
 * preventDefault 해 react-select 가 포커스된 옵션을 고르지 못했다. 회원 목록 「역할 추가」 창·권한 그룹 필터·
 * 메뉴 일괄 역할 칸에서 검색어를 치고 Enter 를 눌러도 선택되지 않았다(브라우저 실측).
 *
 * 메뉴가 열린 동안 react-select 에 맡기도록 고치면서, 새로 만드는 칸에 이미 있는 값을 다시 치고 Enter 를
 * 누르면 react-select 가 그 태그를 해제하는 갈래가 드러났다(관리자 허용 IP 칸 실측). 중복 가드는 메뉴 판정보다
 * 먼저 둔다.
 */

import React from 'react';
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TagInput, TagOption } from '../TagInput';

const roleOptions: TagOption[] = [
  { value: '1', label: '회원' },
  { value: '2', label: '콘텐츠 관리자' },
  { value: '3', label: '마케팅 담당자' },
  { value: '4', label: '운영자' },
];

/** onChange 가 마지막으로 넘긴 값 배열. */
const lastValue = (fn: ReturnType<typeof vi.fn>) => fn.mock.calls[fn.mock.calls.length - 1]?.[0]?.target?.value;

describe('TagInput — Enter 키', () => {
  // @scenario mode=select_only, input=typed_match
  // @effects tag_input_enter_selects_focused_option
  it('고르기 전용 칸: 검색어를 친 뒤 Enter 로 걸러진 옵션이 선택된다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={[]} options={roleOptions} onChange={handleChange} creatable={false} />);

    await user.type(screen.getByRole('combobox'), '운영');
    await screen.findByText('운영자');
    await user.keyboard('{Enter}');

    expect(handleChange).toHaveBeenCalled();
    expect(lastValue(handleChange)).toEqual(['4']);
  });

  // @scenario mode=select_only, input=arrow_focus
  // @effects tag_input_enter_selects_focused_option
  it('고르기 전용 칸: 화살표로 옮긴 옵션을 Enter 로 선택한다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={[]} options={roleOptions} onChange={handleChange} creatable={false} />);

    await user.click(screen.getByRole('combobox'));
    await screen.findByText('회원');
    await user.keyboard('{ArrowDown}{Enter}');

    expect(handleChange).toHaveBeenCalled();
    expect(lastValue(handleChange)).toEqual(['2']);
  });

  // @scenario mode=select_only, input=empty_closed
  // @effects tag_input_enter_on_closed_empty_does_not_submit
  it('목록이 닫힌 빈 칸의 Enter 는 폼 제출로 번지지 않는다', async () => {
    const onSubmit = vi.fn((e: React.FormEvent) => e.preventDefault());
    const user = userEvent.setup();

    render(
      <form onSubmit={onSubmit}>
        <TagInput value={[]} options={roleOptions} onChange={() => {}} creatable={false} />
      </form>
    );

    await user.click(screen.getByRole('combobox'));
    await user.keyboard('{Escape}');
    await user.keyboard('{Enter}');

    expect(onSubmit).not.toHaveBeenCalled();
  });

  // @scenario mode=creatable, input=typed_match
  // @effects tag_input_enter_creates_new_value
  it('새로 만드는 칸: 친 값을 Enter 로 태그로 만든다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={[]} options={[]} onChange={handleChange} creatable />);

    await user.type(screen.getByRole('combobox'), '10.9.9.9');
    await user.keyboard('{Enter}');

    expect(lastValue(handleChange)).toEqual(['10.9.9.9']);
  });

  // @scenario mode=creatable, input=duplicate
  // @effects tag_input_enter_ignores_duplicate_value
  it('새로 만드는 칸: 이미 있는 값을 다시 치고 Enter 를 눌러도 기존 태그가 해제되지 않는다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    const { container } = render(<TagInput value={['10.9.9.9']} options={[]} onChange={handleChange} creatable />);

    await user.type(screen.getByRole('combobox'), '10.9.9.9');
    await user.keyboard('{Enter}');

    // 바뀐 값이 나가지 않았거나, 나갔더라도 기존 태그가 그대로 남아 있어야 한다
    const changed = handleChange.mock.calls.map((call) => call[0]?.target?.value);
    expect(changed.every((v) => Array.isArray(v) && v.includes('10.9.9.9'))).toBe(true);
    const tags = Array.from(container.querySelectorAll('.tag-input__multi-value__label')).map((el) => el.textContent);
    expect(tags).toEqual(['10.9.9.9']);
  });
});

// 7.1.0 영역 J D-J6(관측 O-CF6): 메뉴가 열린 채 Tab 으로 칸을 떠나면 react-select 기본값(tabSelectsValue)이
// 포커스된 옵션을 골라, 회원 역할 칸에서 「관리자」 같은 역할이 의도치 않게 붙을 뻔했다. Tab 은 고르지 않고 떠난다.
describe('TagInput — Tab 키', () => {
  // @scenario mode=select_only, input=tab_with_open_menu
  // @effects tag_input_tab_never_selects_option
  it('고르기 전용 칸: 메뉴를 연 채 Tab 을 누르면 아무것도 선택하지 않는다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={[]} options={roleOptions} onChange={handleChange} creatable={false} />);

    await user.click(screen.getByRole('combobox'));
    await screen.findByText('회원');
    await user.keyboard('{ArrowDown}');
    await user.tab();

    expect(handleChange).not.toHaveBeenCalled();
  });

  // @scenario mode=creatable, input=typed_then_tab
  // @effects tag_input_tab_never_selects_option
  it('만들기 칸: 친 글자는 Tab 으로 태그가 된다(종전 동작 유지)', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={[]} options={[]} onChange={handleChange} creatable />);

    await user.type(screen.getByRole('combobox'), 'abc');
    await user.tab();

    expect(lastValue(handleChange)).toEqual(['abc']);
  });
});
