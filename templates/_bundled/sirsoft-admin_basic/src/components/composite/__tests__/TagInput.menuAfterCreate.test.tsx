/**
 * @file TagInput.menuAfterCreate.test.tsx
 * @description 태그 입력 — 새 태그를 만든 뒤(Enter·Tab·쉼표·여러 항목 붙여넣기) 메뉴가 닫히고, 만들기 칸에 포커스만 줘도
 *   붙은 태그를 메뉴로 펼치지 않는다
 *
 * 환경설정 > 보안 > IP 접근 제한 규칙 「편집」 창 실측: 허용 IP 칸에 값을 치고 Enter 로 태그를 만든 뒤 바로 아래
 * 「메모」 칸을 마우스로 누를 수 없었다 — 태그 입력 메뉴 포털(z-9999)이 열린 채 남아 클릭을 가로챘다.
 *
 * 여러 개 고르는 칸은 고른 뒤에도 메뉴를 열어 둔다(closeMenuOnSelect=false). react-select 는 새 값을 만드는 Enter 도
 * 「포커스된 옵션 고르기」 경로로 처리하므로 만든 뒤에도 메뉴가 열린 채 남고, 입력이 비면 메뉴는 이미 붙은 태그
 * 전부를 옵션으로 펼친다. 값이 있던 칸(편집 창)일수록 메뉴가 길어져 아래 칸을 덮었다.
 *
 * 같은 창에서 칸에 포커스만 줘도 메뉴가 붙은 태그 전부를 체크 표시 행으로 펼쳐 메모 칸을 덮었다. 그 상태로 메모 칸을
 * 누르면 클릭이 행에 닿아 그 태그가 해제됐고(선택된 옵션을 다시 고르면 해제), 빈 입력 Enter 도 첫 행을 다시 골라
 * 해제했다. 만들기 칸은 직접 친 태그를 메뉴 행으로 되풀이하지 않고, 보일 것이 없으면 빈 입력에서 메뉴를 열지 않는다.
 *
 * 데스크톱 환경 재현: react-select 는 `document.createEvent('TouchEvent')` 가 되면 「터치 기기」로 보고 고른 뒤
 * 입력칸을 흐린다(blurInputOnSelect 기본값 — 흐리면 메뉴도 닫힌다). jsdom 은 TouchEvent 를 만들 수 있어 이 결함이
 * 가려지므로, 데스크톱 브라우저처럼 TouchEvent 생성을 거부한 뒤 react-select 를 불러온다(기본값이 불러올 때 정해진다).
 */

import { vi } from 'vitest';

vi.hoisted(() => {
  const original = document.createEvent.bind(document);
  document.createEvent = ((type: string) => {
    if (type === 'TouchEvent') throw new Error('NotSupportedError: 데스크톱 브라우저는 TouchEvent 를 만들지 않는다');
    return original(type);
  }) as typeof document.createEvent;
});

import React from 'react';
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TagInput, TagOption } from '../TagInput';

const roleOptions: TagOption[] = [
  { value: '1', label: '회원' },
  { value: '2', label: '콘텐츠 관리자' },
  { value: '3', label: '마케팅 담당자' },
];

/** onChange 가 마지막으로 넘긴 값 배열. */
const lastValue = (fn: ReturnType<typeof vi.fn>) => fn.mock.calls[fn.mock.calls.length - 1]?.[0]?.target?.value;

/** body 에 붙는 메뉴 포털(없으면 null). */
const menuPortal = () => document.body.querySelector('.tag-input__menu-portal');

describe('[case:components-misc-tag-input-menu-stays-open-after-create] TagInput — 새 태그를 만든 뒤 메뉴 (데스크톱)', () => {
  // @scenario mode=creatable, input=prefilled_typed_enter
  // @effects tag_input_create_closes_menu
  it('값이 있는 만들기 칸: 친 값을 Enter 로 만들면 메뉴가 닫히고 입력칸 포커스는 남으며, 다시 치면 메뉴가 열린다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['10.15.4.0/24']} options={[]} onChange={handleChange} creatable />);

    await user.type(screen.getByRole('combobox'), '10.15.5.5');
    expect(menuPortal(), '입력 중에는 「추가」 항목이 있는 메뉴가 열려 있어야 한다').not.toBeNull();
    await user.keyboard('{Enter}');

    expect(lastValue(handleChange)).toEqual(['10.15.4.0/24', '10.15.5.5']);
    expect(menuPortal(), '태그를 만든 뒤 메뉴가 열린 채 남아 아래 칸을 덮는다').toBeNull();
    expect(screen.getByRole('combobox')).toHaveFocus();

    // 다음 값을 이어 치면 메뉴가 다시 열리고 Enter 로 또 만들 수 있다(키보드 연속 입력 유지)
    await user.keyboard('10.15.6.6');
    expect(menuPortal()).not.toBeNull();
    await user.keyboard('{Enter}');
    expect(lastValue(handleChange)).toEqual(['10.15.4.0/24', '10.15.5.5', '10.15.6.6']);
    expect(menuPortal()).toBeNull();
  });

  // @scenario mode=creatable, input=typed_match
  // @effects tag_input_create_closes_menu
  it('빈 만들기 칸: 첫 태그를 Enter 로 만들어도 메뉴가 닫힌다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={[]} options={[]} onChange={handleChange} creatable />);

    await user.type(screen.getByRole('combobox'), '10.15.4.0/24');
    await user.keyboard('{Enter}');

    expect(lastValue(handleChange)).toEqual(['10.15.4.0/24']);
    expect(menuPortal()).toBeNull();
  });

  // @scenario mode=creatable, input=typed_delimiter
  // @effects tag_input_create_closes_menu
  it('만들기 칸: 쉼표로 태그를 만들어도 메뉴가 닫힌다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['10.15.4.0/24']} options={[]} onChange={handleChange} creatable />);

    await user.type(screen.getByRole('combobox'), '10.15.5.5,');

    expect(lastValue(handleChange)).toEqual(['10.15.4.0/24', '10.15.5.5']);
    expect(menuPortal()).toBeNull();
  });

  // @scenario mode=creatable, input=typed_then_tab
  // @effects tag_input_create_closes_menu
  it('만들기 칸: 친 글자를 Tab 으로 태그로 만들어도 메뉴가 닫힌다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['10.15.4.0/24']} options={[]} onChange={handleChange} creatable />);

    await user.type(screen.getByRole('combobox'), '10.15.5.5');
    await user.tab();

    expect(lastValue(handleChange)).toEqual(['10.15.4.0/24', '10.15.5.5']);
    expect(menuPortal()).toBeNull();
    // Tab 은 태그만 만들고 칸을 떠나지 않는다(친 글자가 있으면 기본 동작을 막는다 — 종전 동작)
    expect(screen.getByRole('combobox')).toHaveFocus();
  });

  // @scenario mode=select_only, input=arrow_focus
  // @effects tag_input_select_keeps_menu_open
  it('고르기 전용 칸: 옵션을 고른 뒤에는 메뉴를 열어 둔다(여러 개를 잇달아 고르는 종전 동작)', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={[]} options={roleOptions} onChange={handleChange} creatable={false} />);

    await user.click(screen.getByRole('combobox'));
    await screen.findByText('회원');
    await user.keyboard('{ArrowDown}{Enter}');

    expect(lastValue(handleChange)).toEqual(['2']);
    expect(menuPortal()).not.toBeNull();

    // Escape 로는 여전히 닫힌다
    await user.keyboard('{Escape}');
    expect(menuPortal()).toBeNull();
  });
});

/** 레이아웃이 준 제안 옵션(쿠키 이름 추천 같은 자동완성 목록). */
const suggestionOptions: TagOption[] = [
  { value: '_ga', label: '_ga' },
  { value: '_gid', label: '_gid' },
];

describe('[case:components-misc-tag-input-menu-stays-open-after-create] TagInput — 여러 항목 붙여넣기 뒤 메뉴 (데스크톱)', () => {
  // @scenario mode=creatable, input=pasted_multiple
  // @effects tag_input_create_closes_menu
  it('제안 목록이 있는 만들기 칸: 쉼표로 이은 여러 값을 붙여넣어 태그를 만들면 메뉴가 닫히고 포커스는 남는다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={[]} options={suggestionOptions} onChange={handleChange} creatable />);

    await user.click(screen.getByRole('combobox'));
    expect(menuPortal(), '포커스를 주면 제안 목록이 열린다').not.toBeNull();
    await user.paste('example.com,example.org');

    expect(lastValue(handleChange)).toEqual(['example.com', 'example.org']);
    expect(menuPortal(), '붙여넣어 태그를 만든 뒤 메뉴가 열린 채 남아 아래 칸을 덮는다').toBeNull();
    expect(screen.getByRole('combobox')).toHaveFocus();
  });

  // @scenario mode=creatable, input=pasted_multiple
  // @effects tag_input_create_closes_menu
  it('값이 있는 만들기 칸: 여러 IP 를 붙여넣어도 메뉴가 남지 않는다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['10.15.4.0/24']} options={[]} onChange={handleChange} creatable />);

    await user.click(screen.getByRole('combobox'));
    await user.paste('10.15.5.5, 10.15.6.6');

    expect(lastValue(handleChange)).toEqual(['10.15.4.0/24', '10.15.5.5', '10.15.6.6']);
    expect(menuPortal()).toBeNull();
  });

  // @scenario mode=creatable, input=pasted_multiple
  // @effects tag_input_create_closes_menu
  it('먼저 친 글자가 남아 있으면 붙여넣은 값만 태그가 되고, 남은 글자의 「추가」 항목은 메뉴에 남아 Enter 로 만든다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={[]} options={[]} onChange={handleChange} creatable />);

    await user.type(screen.getByRole('combobox'), '10.15.9.9');
    await user.paste('10.15.5.5,10.15.6.6');

    expect(lastValue(handleChange)).toEqual(['10.15.5.5', '10.15.6.6']);
    expect(screen.getByRole('combobox')).toHaveValue('10.15.9.9');
    expect(menuPortal(), '남은 글자를 Enter 로 만들 「추가」 항목이 있어야 한다').not.toBeNull();
    await user.keyboard('{Enter}');
    expect(lastValue(handleChange)).toEqual(['10.15.5.5', '10.15.6.6', '10.15.9.9']);
    expect(menuPortal()).toBeNull();
  });
});

describe('[case:components-misc-tag-input-menu-stays-open-after-create] TagInput — 만들기 칸에 포커스만 줄 때 메뉴 (데스크톱)', () => {
  // @scenario mode=creatable, input=prefilled_focus_only
  // @effects tag_input_create_menu_omits_attached_tags, tag_input_create_blank_menu_stays_closed
  it('값이 있는 만들기 칸: 포커스만 주면 붙은 태그를 메뉴로 펼치지 않는다(아래 칸을 덮지 않고 「펼쳐짐」 으로도 읽히지 않는다)', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['10.15.4.0/24', '10.15.5.5']} options={[]} onChange={handleChange} creatable />);

    const combobox = screen.getByRole('combobox');
    await user.click(combobox);

    expect(combobox).toHaveFocus();
    expect(menuPortal(), '포커스만 줘도 붙은 태그 전부가 메뉴 행으로 펼쳐져 아래 칸을 덮는다').toBeNull();
    expect(screen.queryAllByRole('option')).toHaveLength(0);
    expect(combobox).toHaveAttribute('aria-expanded', 'false');
    expect(handleChange).not.toHaveBeenCalled();
  });

  // @scenario mode=creatable, input=prefilled_focus_only
  // @effects tag_input_create_blank_menu_stays_closed
  it('값이 있는 만들기 칸: 포커스만 준 채 빈 입력에서 Enter 를 눌러도 첫 태그가 해제되지 않고 폼도 제출되지 않는다', async () => {
    const handleChange = vi.fn();
    const handleSubmit = vi.fn((e: React.FormEvent) => e.preventDefault());
    const user = userEvent.setup();

    render(
      <form onSubmit={handleSubmit}>
        <TagInput value={['10.15.4.0/24', '10.15.5.5']} options={[]} onChange={handleChange} creatable />
      </form>,
    );

    await user.click(screen.getByRole('combobox'));
    await user.keyboard('{Enter}');

    expect(handleChange, '메뉴 첫 행(이미 붙은 태그)이 Enter 로 다시 골라져 해제된다').not.toHaveBeenCalled();
    expect(handleSubmit).not.toHaveBeenCalled();
  });

  // @scenario mode=creatable, input=prefilled_focus_only
  // @effects tag_input_create_menu_omits_attached_tags
  it('값이 있는 만들기 칸: 글자를 치면 메뉴가 열리되 「추가」 항목만 있고, 이미 붙은 태그는 행으로 나오지 않는다', async () => {
    const user = userEvent.setup();

    render(<TagInput value={['10.15.4.0/24']} options={[]} onChange={vi.fn()} creatable />);

    await user.type(screen.getByRole('combobox'), '10.15');

    expect(menuPortal()).not.toBeNull();
    const rows = screen.getAllByRole('option').map((o) => o.textContent);
    expect(rows).toEqual(['10.15 추가']);
  });

  // @scenario mode=creatable, input=prefilled_focus_only
  // @effects tag_input_create_suggestion_rows_shown
  it('제안 목록이 있는 만들기 칸: 포커스하면 제안 행은 체크 표시와 함께 열린다(직접 친 태그 행은 없음)', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['_ga', 'custom_cookie']} options={suggestionOptions} onChange={handleChange} creatable />);

    await user.click(screen.getByRole('combobox'));

    expect(menuPortal()).not.toBeNull();
    expect(screen.getAllByRole('option').map((o) => o.textContent)).toEqual(['✓_ga', '_gid']);
  });

  // @scenario mode=creatable, input=prefilled_focus_only
  // @effects tag_input_create_suggestion_rows_shown
  it('레이아웃이 안내 문구를 준 만들기 칸: 제안이 비어도 포커스하면 그 안내 문구는 종전대로 보인다', async () => {
    const user = userEvent.setup();

    render(<TagInput value={[]} options={[]} onChange={vi.fn()} creatable noOptionsMessage="추천 항목이 없습니다. 직접 입력하세요." />);

    await user.click(screen.getByRole('combobox'));

    expect(menuPortal()).not.toBeNull();
    expect(screen.getByText('추천 항목이 없습니다. 직접 입력하세요.')).toBeInTheDocument();
  });
});

/** 칸에 붙은 태그 상자(라벨 글자로 찾는다). */
const tagChip = (label: string) => {
  const chip = Array.from(document.querySelectorAll<HTMLElement>('.tag-input__multi-value'))
    .find((el) => el.querySelector('.tag-input__multi-value__label')?.textContent === label);
  if (!chip) throw new Error(`태그 「${label}」 이 칸에 없다`);
  return chip;
};

/** 키보드로 고른 태그에 보이는 표시(포커스 링)가 있는가. */
const looksFocused = (el: HTMLElement) => el.classList.contains('ring-2');

describe('[case:components-misc-tag-input-menu-stays-open-after-create] TagInput — 키보드로 태그 지우기 (데스크톱)', () => {
  // 만들기 칸은 직접 친 태그를 메뉴 행으로 보여 주지 않으므로 메뉴 행 Enter 로 지우는 길이 없다. 태그의 × 단추는
  // 포인터용(Tab 순서 밖)이라, 키보드만 쓰는 사람의 길은 ←·→ 로 태그에 포커스한 뒤 Backspace·Delete 다.

  // @scenario mode=creatable, input=tag_arrow_remove
  // @effects tag_input_keyboard_removes_focused_tag, tag_input_focused_tag_visible
  it('값이 있는 만들기 칸: 빈 입력에서 ← 로 마지막 태그에 포커스하면 그 태그가 보이게 표시되고, Backspace 로 지우면 포커스는 칸에 남는다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['10.15.4.0/24', '10.15.5.5']} options={[]} onChange={handleChange} creatable />);

    const combobox = screen.getByRole('combobox');
    await user.click(combobox);
    await user.keyboard('{ArrowLeft}');

    expect(looksFocused(tagChip('10.15.5.5')), '← 로 고른 태그가 화면에 표시되지 않아 Backspace 가 무엇을 지울지 보이지 않는다').toBe(true);
    expect(looksFocused(tagChip('10.15.4.0/24'))).toBe(false);

    await user.keyboard('{Backspace}');

    expect(lastValue(handleChange)).toEqual(['10.15.4.0/24']);
    expect(combobox).toHaveFocus();
    expect(menuPortal()).toBeNull();
  });

  // @scenario mode=creatable, input=tag_arrow_remove
  // @effects tag_input_keyboard_removes_focused_tag, tag_input_focused_tag_visible
  it('만들기 칸: ←·→ 로 태그 사이를 옮긴 뒤 Delete 로 그 태그만 지운다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['10.15.4.0/24', '10.15.5.5', '10.15.6.6']} options={[]} onChange={handleChange} creatable />);

    await user.click(screen.getByRole('combobox'));
    // ← 세 번은 첫 태그에서 멈추고(처음으로 돌아가지 않음), → 한 번에 둘째 태그로 간다
    await user.keyboard('{ArrowLeft}{ArrowLeft}{ArrowLeft}{ArrowLeft}{ArrowRight}');
    expect(looksFocused(tagChip('10.15.5.5'))).toBe(true);
    expect(handleChange).not.toHaveBeenCalled();

    await user.keyboard('{Delete}');

    expect(lastValue(handleChange)).toEqual(['10.15.4.0/24', '10.15.6.6']);
    expect(screen.getByRole('combobox')).toHaveFocus();
  });

  // @scenario mode=select_only, input=tag_arrow_remove
  // @effects tag_input_keyboard_removes_focused_tag, tag_input_focused_tag_visible
  it('고르기 전용 칸: ← 로 고른 역할 태그도 보이게 표시되고 Backspace 로 지운다(메뉴의 ✓ 행 Enter 해제와 함께 두 길이 있다)', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['1', '2']} options={roleOptions} onChange={handleChange} creatable={false} />);

    await user.click(screen.getByRole('combobox'));
    await user.keyboard('{ArrowLeft}');
    expect(looksFocused(tagChip('콘텐츠 관리자'))).toBe(true);

    await user.keyboard('{Backspace}');
    expect(lastValue(handleChange)).toEqual(['1']);
  });

  // @scenario mode=creatable, input=blank_backspace
  // @effects tag_input_blank_backspace_keeps_tags
  it('만들기 칸: 태그에 포커스하지 않은 빈 입력의 Backspace 는 태그를 지우지 않고, 친 글자가 있으면 ←·Backspace 는 글자만 다룬다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['10.15.4.0/24', '10.15.5.5']} options={[]} onChange={handleChange} creatable />);

    const combobox = screen.getByRole('combobox');
    await user.click(combobox);
    // 마지막 태그를 바로 지우지 않는다(backspaceRemovesValue=false — 지우던 글자 끝에서 한 번 더 눌러 태그를 잃던 실수 방지)
    await user.keyboard('{Backspace}{Backspace}');
    expect(handleChange).not.toHaveBeenCalled();

    await user.keyboard('10.1');
    await user.keyboard('{ArrowLeft}{Backspace}');
    expect(handleChange).not.toHaveBeenCalled();
    expect(looksFocused(tagChip('10.15.5.5'))).toBe(false);
    expect(document.querySelectorAll('.tag-input__multi-value')).toHaveLength(2);
  });

  // @scenario mode=creatable, input=tab_past_tags
  // @effects tag_input_tab_skips_remove_buttons
  it('Tab 은 태그의 × 단추마다 멈추지 않고 다음 칸으로 간다 — × 는 이름이 있는 포인터용 단추다', async () => {
    const user = userEvent.setup();

    render(
      <>
        <TagInput value={['10.15.4.0/24', '10.15.5.5']} options={[]} onChange={vi.fn()} creatable />
        <button type="button">메모</button>
      </>,
    );

    const removeButtons = Array.from(document.querySelectorAll<HTMLElement>('.tag-input__multi-value__remove'));
    expect(removeButtons).toHaveLength(2);
    for (const remove of removeButtons) {
      expect(remove).toHaveAttribute('role', 'button');
      expect(remove.getAttribute('aria-label')).toBeTruthy();
      expect(remove).not.toHaveAttribute('tabindex');
    }

    await user.click(screen.getByRole('combobox'));
    await user.tab();
    expect(screen.getByRole('button', { name: '메모' }), '태그가 많은 칸(허용 IP 등)에서 × 마다 Tab 이 멈추면 다음 칸까지 태그 수만큼 눌러야 한다').toHaveFocus();
    await user.tab({ shift: true });
    expect(screen.getByRole('combobox')).toHaveFocus();
  });
});

/** 값과 이름이 다른 제안 옵션(친 글자가 이름에만 맞는 경우). */
const namedSuggestions: TagOption[] = [
  { value: 'ga', label: 'Google Analytics' },
  { value: 'gid', label: 'Google ID' },
];

describe('[case:components-misc-tag-input-reselect-removes-tag] TagInput — 만들기 칸에서 이미 붙은 행을 다시 고르면 그대로 둔다 (데스크톱)', () => {
  // 만들기 칸의 메뉴에서 이미 붙은 행(✓)을 다시 고르면 react-select 가 그 태그를 해제했다(deselect-option). 사용자가 고른 것이
  // 사라지는 것이므로 고르면 유지한다(이미 있으면 그대로). 태그를 빼는 길은 태그의 × 와 ←·→ 뒤 Backspace·Delete 다.

  // @scenario mode=creatable, input=reselect_attached
  // @effects tag_input_create_reselect_keeps_tag
  it('제안 행 ✓ 를 마우스로 다시 누르면 태그가 그대로다(값 변경 없음)', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['_ga', 'custom_cookie']} options={suggestionOptions} onChange={handleChange} creatable />);

    await user.click(screen.getByRole('combobox'));
    await user.click(screen.getByRole('option', { name: /_ga/ }));

    expect(handleChange).not.toHaveBeenCalled();
    expect(Array.from(document.querySelectorAll('.tag-input__multi-value')).map((n) => n.textContent), '붙은 태그가 그대로 보여야 한다').toEqual(['_ga', 'custom_cookie']);
  });

  // @scenario mode=creatable, input=reselect_attached
  // @effects tag_input_create_reselect_keeps_tag
  it('화살표로 ✓ 행에 가서 Enter 를 눌러도 태그가 그대로고, 이어서 다른 제안을 고르면 더해진다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['_ga']} options={suggestionOptions} onChange={handleChange} creatable />);

    await user.click(screen.getByRole('combobox'));
    // 메뉴를 열면 첫 행(✓_ga)에 포커스가 간다
    await user.keyboard('{Enter}');
    expect(handleChange).not.toHaveBeenCalled();

    await user.keyboard('{ArrowDown}{Enter}');
    expect(lastValue(handleChange)).toEqual(['_ga', '_gid']);
  });

  // @scenario mode=creatable, input=reselect_attached
  // @effects tag_input_create_reselect_keeps_tag
  it('친 글자가 붙은 제안의 이름에 맞아 그 행을 Enter 로 골라도 태그가 그대로다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['ga']} options={namedSuggestions} onChange={handleChange} creatable />);

    await user.type(screen.getByRole('combobox'), 'Analytics');
    const rows = screen.getAllByRole('option').map((o) => o.textContent);
    expect(rows.some((r) => r?.includes('✓Google Analytics'))).toBe(true);
    // 「… 추가」 행이 먼저이므로 ✓ 행까지 내려가서 고른다
    while (!document.querySelector('.tag-input__option--is-focused')?.textContent?.includes('Google Analytics')) {
      await user.keyboard('{ArrowDown}');
    }
    await user.keyboard('{Enter}');

    expect(handleChange).not.toHaveBeenCalled();
  });

  // @scenario mode=creatable, input=reselect_attached
  // @effects tag_input_create_reselect_keeps_tag
  it('이미 붙은 값을 섞어 붙여넣어도 붙은 태그는 그대로고 새 값만 더해진다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['_ga', 'custom_cookie']} options={suggestionOptions} onChange={handleChange} creatable />);

    await user.click(screen.getByRole('combobox'));
    await user.paste('_ga,_gid');

    expect(lastValue(handleChange)).toEqual(['_ga', 'custom_cookie', '_gid']);
  });

  // @scenario mode=select_only, input=reselect_attached
  // @effects tag_input_select_reselect_toggles
  it('고르기 전용 칸은 ✓ 행을 다시 고르면 해제한다(체크 목록 — 종전 동작)', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();

    render(<TagInput value={['1']} options={roleOptions} onChange={handleChange} creatable={false} />);

    await user.click(screen.getByRole('combobox'));
    await user.click(screen.getByRole('option', { name: /회원/ }));

    expect(lastValue(handleChange)).toEqual([]);
  });
});
