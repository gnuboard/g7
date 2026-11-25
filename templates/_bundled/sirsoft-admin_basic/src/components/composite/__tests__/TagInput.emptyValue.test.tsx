// e2e:allow 시험·시험 헬퍼 파일 — 런타임 동작을 바꾸지 않는다. 대상 화면(정보 탭·회원 목록·TagInput 두 화면·클레임 상세)은 headless 브라우저로 수정 전·후를 실측했고 정보 탭·추정 건수는 E2E settings-info-value-alignment.spec.ts 가 잠근다
/**
 * @file TagInput.emptyValue.test.tsx
 * @description 태그 입력 — 값이 비었을 때(빈 문자열·공백만 있는 항목) 빈 태그를 그리지 않고, 저장 값에도 섞지 않는다
 *
 * 관리자 상품 수정 화면의 SEO 키워드 칸(`name="meta_keywords"`)은 상품의 키워드가 null 이면 내용 없는 태그 하나와
 * 삭제 버튼을 그렸다. 폼 자동 바인딩은 상태 값이 null·undefined 인 칸에 빈 문자열을 넘기는데(input 칸의 제어 값 규칙),
 * 이 부품은 문자열 값을 「한 항목」 으로 감싸 `['']` 로 읽었다. 환경설정 회원 탭의 닉네임 금지어 칸도 설정을 받기 전
 * 렌더에서 같은 빈 태그가 잠깐 보였다(받은 뒤 `[]` 로 바뀌면 사라지므로 측정 시점에 따라 간헐 재현).
 * 그 상태로 태그를 추가하면 저장 배열 앞에 빈 문자열이 섞였다.
 */

import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { TagInput } from '../TagInput';

/** 칸에 그려진 태그(칩) 수. */
const chipCount = (container: HTMLElement) => container.querySelectorAll('.tag-input__multi-value').length;

/** onChange 가 마지막으로 넘긴 값. */
const lastValue = (fn: ReturnType<typeof vi.fn>) => fn.mock.calls[fn.mock.calls.length - 1]?.[0]?.target?.value;

describe('TagInput — 빈 값은 태그가 아니다', () => {
  // @scenario area=empty_value
  // @effects tag_input_empty_value_renders_no_chip
  it('폼 자동 바인딩이 넘기는 빈 문자열 값이면 태그를 그리지 않는다 (상품 SEO 키워드 null)', () => {
    const { container } = render(<TagInput value="" options={[]} onChange={vi.fn()} creatable name="meta_keywords" />);

    expect(chipCount(container)).toBe(0);
    expect(container.querySelector('input[type="hidden"][name="meta_keywords"]')).toHaveValue('');
  });

  // @scenario area=empty_value
  // @effects tag_input_empty_value_renders_no_chip
  it('공백만 있는 문자열 값도 태그로 그리지 않는다', () => {
    const { container } = render(<TagInput value="   " options={[]} onChange={vi.fn()} creatable />);

    expect(chipCount(container)).toBe(0);
  });

  // @scenario area=empty_value
  // @effects tag_input_empty_value_renders_no_chip
  it('배열 안의 빈 문자열·공백 항목은 빼고 나머지 태그만 그린다', () => {
    const { container } = render(<TagInput value={['', '  ', 'seo']} options={[]} onChange={vi.fn()} creatable />);

    expect(chipCount(container)).toBe(1);
    expect(screen.getByText('seo')).toBeInTheDocument();
  });

  // @scenario area=empty_value
  // @effects tag_input_empty_value_renders_no_chip
  it('받기 전 빈 문자열 → 받은 뒤 빈 배열로 바뀌어도 어느 쪽에서도 태그가 없다 (닉네임 금지어 간헐 재현)', () => {
    const { container, rerender } = render(<TagInput value="" options={[]} onChange={vi.fn()} creatable />);
    expect(chipCount(container)).toBe(0);

    rerender(<TagInput value={[]} options={[]} onChange={vi.fn()} creatable />);
    expect(chipCount(container)).toBe(0);
  });

  // @scenario area=empty_value
  // @effects tag_input_empty_value_not_saved
  it('빈 문자열 값인 칸에 태그를 추가하면 저장 값에 빈 문자열이 섞이지 않는다', async () => {
    const handleChange = vi.fn();
    const user = userEvent.setup();
    const { container } = render(<TagInput value="" options={[]} onChange={handleChange} creatable />);

    await user.type(screen.getByRole('combobox'), 'shoes{Enter}');

    expect(lastValue(handleChange)).toEqual(['shoes']);
    expect(chipCount(container)).toBe(1);
  });

  // @scenario area=empty_value
  // @effects tag_input_empty_value_keeps_real_values
  it('정상 값은 그대로 태그로 그린다 (대조)', () => {
    const { container } = render(<TagInput value={['a', 'b', 0]} options={[]} onChange={vi.fn()} creatable />);

    expect(chipCount(container)).toBe(3);
  });

  // @scenario area=empty_value
  // @effects tag_input_empty_value_keeps_real_values
  it('빈 문자열이 실제 옵션 값이면(단일 선택 「전체」 등) 그 옵션을 고른 것으로 둔다', () => {
    render(
      <TagInput
        value=""
        isMulti={false}
        options={[{ value: '', label: 'All' }, { value: 'x', label: 'X' }]}
        onChange={vi.fn()}
      />,
    );

    expect(screen.getByText('All')).toBeInTheDocument();
  });
});
