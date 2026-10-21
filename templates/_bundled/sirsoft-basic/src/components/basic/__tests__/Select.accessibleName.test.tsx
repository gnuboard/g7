/**
 * @file Select.accessibleName.test.tsx
 * @description 목록형 Select(options 모드) 단추가 「{이름} {현재 값}」으로 읽힌다 (#757)
 *
 * 결함: options 모드는 레이아웃이 준 aria-label·aria-labelledby 를 바깥 Div 에 펼치고 실제 단추에는 넘기지 않았다.
 *   역할 없는 Div 의 이름은 화면 낭독기가 읽지 않으므로, 마이페이지 검색 대상·회원정보 수정 국가 등 선택칸이
 *   이름을 달아도 「제목+내용, 단추」처럼 고른 값만 읽혔다.
 *
 * 계약(관리자 템플릿 Select 와 같다):
 *   - aria-label → 숨은 이름 span(sr-only) + 단추 aria-labelledby="{이름 id} {값 span id}"
 *   - aria-labelledby → 단추 aria-labelledby="{받은 id} {값 span id}"
 *   - 둘 다 없으면 단추에 aria-labelledby 를 두지 않는다(종전 그대로 — 자동 라벨 연결은 추가하지 않는다)
 *   - 바깥 Div 에는 aria 이름을 두지 않는다
 *
 * @scenario-manifest tests/scenarios/control-accessible-names.yaml
 */

import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { Select } from '../Select';

const OPTIONS = [
  { value: 'title_content', label: '제목+내용' },
  { value: 'title', label: '제목' },
];

/** aria-labelledby 가 가리키는 요소들의 글자를 이어 읽는다(접근 이름 계산의 labelledby 단계). */
const nameByLabelledBy = (el: Element): string =>
  (el.getAttribute('aria-labelledby') ?? '')
    .split(/\s+/)
    .filter(Boolean)
    .map((id) => document.getElementById(id)?.textContent?.trim() ?? '')
    .join(' ')
    .trim();

describe('Select 접근 이름 (options 모드, #757)', () => {
  /**
   * @scenario surface=user_layout
   * @effects listbox_reads_name_and_value
   */
  it('aria-label 을 주면 단추가 「이름 + 현재 값」으로 읽힌다', () => {
    const { container } = render(<Select aria-label="검색 대상" options={OPTIONS} value="title_content" />);
    const button = screen.getByRole('button');

    expect(nameByLabelledBy(button)).toBe('검색 대상 제목+내용');
    // 바깥 상자에는 이름을 두지 않는다 — 역할 없는 상자의 이름은 읽히지 않고 중복만 된다
    expect(container.firstElementChild?.getAttribute('aria-label')).toBeNull();
  });

  /**
   * @scenario surface=user_layout
   * @effects listbox_reads_name_and_value
   */
  it('값을 바꾸면 이름 뒤 값도 따라 바뀐다', () => {
    const { rerender } = render(<Select aria-label="검색 대상" options={OPTIONS} value="title_content" />);
    rerender(<Select aria-label="검색 대상" options={OPTIONS} value="title" />);

    expect(nameByLabelledBy(screen.getByRole('button'))).toBe('검색 대상 제목');
  });

  /**
   * @scenario surface=user_layout
   * @effects listbox_reads_name_and_value
   */
  it('aria-labelledby 를 주면 그 요소 + 현재 값으로 읽힌다', () => {
    render(
      <div>
        <span id="outer-title">정렬 기준</span>
        <Select aria-labelledby="outer-title" options={OPTIONS} value="title" />
      </div>,
    );

    expect(nameByLabelledBy(screen.getByRole('button'))).toBe('정렬 기준 제목');
  });

  /**
   * 고른 값이 없으면 값 자리를 잇지 않는다 — 빈 값 span 이 이름 뒤에 붙지 않고 이름만 읽힌다(관리자 템플릿과 같은 계약).
   *
   * @scenario surface=user_layout
   * @effects listbox_reads_name_and_value
   */
  it('고른 값이 없으면 이름만 읽는다(값 자리를 잇지 않는다)', () => {
    render(<Select aria-label="검색 대상" options={OPTIONS} value="" />);
    const button = screen.getByRole('button');

    expect(nameByLabelledBy(button)).toBe('검색 대상');
    expect((button.getAttribute('aria-labelledby') ?? '').split(/\s+/)).toHaveLength(1);
  });

  /**
   * 사용자 레이아웃은 안내 문구를 빈 값 선택지로 둔다(`{ value: '', label: '사이즈 선택' }`). 빈 값은 「고른 값 없음」이라
   * 그 안내 글자를 값으로 잇지 않는다 — 잇으면 「사이즈 사이즈 선택」「국가 국가를 선택하세요」처럼 겹친다(실측).
   *
   * @scenario surface=user_layout
   * @effects listbox_reads_name_and_value
   */
  it('빈 값 선택지(안내 문구)가 골라져 있으면 이름만 읽는다', () => {
    const withPrompt = [{ value: '', label: '사이즈 선택' }, { value: 'L', label: 'L' }];
    const { rerender } = render(<Select aria-label="사이즈" options={withPrompt} value="" />);

    expect(nameByLabelledBy(screen.getByRole('button'))).toBe('사이즈');

    // 실제 값을 고르면 다시 「이름 + 값」
    rerender(<Select aria-label="사이즈" options={withPrompt} value="L" />);
    expect(nameByLabelledBy(screen.getByRole('button'))).toBe('사이즈 L');
  });

  /**
   * 빈 값이라도 뜻 있는 선택지(「전체 카테고리」 — 거르지 않음)는 값이다. 자리표시로 보는 것은 disabled 이거나
   * 글자가 이름으로 시작하는 빈 값 선택지뿐이다(관리자 템플릿 Select 와 같은 판정).
   *
   * @scenario surface=user_layout
   * @effects listbox_reads_name_and_value
   */
  it('빈 값이지만 뜻 있는 선택지(전체 카테고리)는 「이름 + 값」으로 읽는다', () => {
    const filter = [{ value: '', label: '전체 카테고리' }, { value: 'notice', label: '공지' }];
    render(<Select aria-label="분류" options={filter} value="" />);

    expect(nameByLabelledBy(screen.getByRole('button'))).toBe('분류 전체 카테고리');
  });

  /**
   * @scenario surface=user_layout
   * @effects listbox_reads_name_and_value
   */
  it('disabled 인 빈 값 선택지는 글자와 무관하게 자리표시라 이름만 읽는다', () => {
    const withDisabled = [{ value: '', label: '골라 주세요', disabled: true }, { value: 'a', label: 'A' }];
    render(<Select aria-label="유형" options={withDisabled} value="" />);

    expect(nameByLabelledBy(screen.getByRole('button'))).toBe('유형');
  });

  /**
   * @scenario surface=user_layout
   * @effects controls_have_accessible_name
   */
  it('이름 prop 이 없으면 단추에 aria-labelledby 를 두지 않는다(종전 동작)', () => {
    render(<Select options={OPTIONS} value="title" />);

    expect(screen.getByRole('button').getAttribute('aria-labelledby')).toBeNull();
  });

  /**
   * 한 화면에 같은 선택칸이 여럿이어도 숨은 이름 id 가 겹치지 않아야 서로의 이름을 읽지 않는다.
   *
   * @scenario surface=user_layout
   * @effects listbox_reads_name_and_value
   */
  it('여러 개를 그려도 이름 id 가 겹치지 않는다', () => {
    render(
      <div>
        <Select aria-label="게시판" options={OPTIONS} value="title" />
        <Select aria-label="정렬 기준" options={OPTIONS} value="title_content" />
      </div>,
    );
    const [a, b] = screen.getAllByRole('button');

    expect(nameByLabelledBy(a)).toBe('게시판 제목');
    expect(nameByLabelledBy(b)).toBe('정렬 기준 제목+내용');
  });

  /**
   * @scenario surface=user_layout
   * @effects controls_have_accessible_name
   */
  it('children 모드(기본 select)는 aria-label 을 select 에 그대로 둔다', () => {
    const { container } = render(
      <Select aria-label="게시판">
        <option value="">전체</option>
      </Select>,
    );

    expect(container.querySelector('select')?.getAttribute('aria-label')).toBe('게시판');
  });
});
