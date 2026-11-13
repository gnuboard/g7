/**
 * MemberFieldInput 컴포넌트 테스트
 *
 * @description 서버 항목 계약의 유형과 제한 값에 따라 입력칸을 고르고, 유형별 값 모양으로 변경을 내보내는지 검증한다.
 * 값 모양은 서버 검증 규칙과 같아야 한다 — 글 유형은 문자열, 예/아니오는 boolean, 여러 개 선택은 배열,
 * 기간은 { start, end }, 「기타」를 고른 하나 선택은 { value: '__other__', other }.
 *
 * @scenario-manifest tests/scenarios/member-field-input.yaml
 */

import { render, screen, fireEvent } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach } from 'vitest';

vi.mock('../../basic/Div', () => ({
  Div: ({ children, editorAttrs, ...props }: any) => <div {...props}>{children}</div>,
}));
vi.mock('../../basic/Span', () => ({
  Span: ({ children, ...props }: any) => <span {...props}>{children}</span>,
}));
vi.mock('../../basic/Label', () => ({
  Label: ({ children, ...props }: any) => <label {...props}>{children}</label>,
}));
vi.mock('../../basic/Input', () => ({
  Input: (props: any) => <input {...props} />,
}));
vi.mock('../../basic/Textarea', () => ({
  Textarea: (props: any) => <textarea {...props} />,
}));
vi.mock('../../basic/Checkbox', () => ({
  Checkbox: (props: any) => <input type="checkbox" {...props} />,
}));
// 커스텀 드롭다운 대신 네이티브 select 로 바꿔 선택 동작만 본다
vi.mock('../../basic/Select', () => ({
  Select: ({ options = [], value, onChange, className, disabled, name, ...rest }: any) => (
    <select data-testid="select" data-searchable={rest.searchable ? 'true' : 'false'} name={name} value={value} disabled={disabled} className={className} onChange={onChange} aria-label={rest['aria-label']}>
      {options.map((option: any) => (
        <option key={String(option.value)} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  ),
}));

import MemberFieldInput, { MEMBER_FIELD_COUNTRY_CODES, MEMBER_FIELD_OTHER, resolveDateBound } from '../MemberFieldInput';

const field = (overrides: Record<string, any> = {}) => ({
  key: 'sample',
  name: 'custom_fields.sample',
  type: 'text',
  label: '표본',
  required: false,
  options: [],
  constraints: {},
  ...overrides,
});

describe('MemberFieldInput', () => {
  let emitted: any[];
  const onChange = (event: any) => emitted.push(event.target.value);

  beforeEach(() => {
    emitted = [];
    (window as any).G7Core = {
      t: (key: string) => key,
      createChangeEvent: ({ value, name }: any) => ({ target: { value, name } }),
    };
  });

  /**
   * @scenario case=text
   * @effects text_input_emits_string_with_name
   */
  it('한 줄 글: text 입력칸을 그리고 문자열을 폼 경로와 함께 내보낸다', () => {
    const events: any[] = [];
    render(<MemberFieldInput field={field({ placeholder: '적어 주세요', constraints: { max_length: 20 }, required: true })} name="custom_fields.sample" value="" onChange={(e) => events.push(e)} />);

    const input = screen.getByLabelText('표본') as HTMLInputElement;
    expect(input.type).toBe('text');
    expect(input.placeholder).toBe('적어 주세요');
    expect(input.maxLength).toBe(20);
    expect(input.required).toBe(true);

    fireEvent.change(input, { target: { value: '안녕' } });
    expect(events[0].target).toEqual({ value: '안녕', name: 'custom_fields.sample' });
  });

  /**
   * @scenario case=phone_email_url_number_date
   * @effects html_input_type_follows_field_type
   */
  it.each([
    ['phone', 'tel'],
    ['email', 'email'],
    ['url', 'url'],
    ['number', 'number'],
    ['date', 'date'],
    ['datetime', 'datetime-local'],
    ['unknown_type', 'text'],
  ])('유형 %s 는 input type=%s 로 그린다', (type, htmlType) => {
    render(<MemberFieldInput field={field({ type })} value="" onChange={onChange} />);
    expect((screen.getByLabelText('표본') as HTMLInputElement).type).toBe(htmlType);
  });

  /**
   * @scenario case=number
   * @effects number_shows_unit_and_step
   */
  it('숫자: 단위를 옆에 보이고 소수 자리에 맞는 step 을 준다', () => {
    render(<MemberFieldInput field={field({ type: 'number', constraints: { unit: 'kg', decimals: 2, min: 0, max: 300 } })} value="" onChange={onChange} />);

    const input = screen.getByLabelText('표본') as HTMLInputElement;
    expect(input.step).toBe('0.01');
    expect(input.min).toBe('0');
    expect(input.max).toBe('300');
    expect(screen.getByText('kg')).toBeTruthy();
  });

  /**
   * @scenario case=textarea
   * @effects textarea_uses_rows
   */
  it('여러 줄 글: 줄 수 제한 값을 rows 로 쓴다', () => {
    render(<MemberFieldInput field={field({ type: 'textarea', constraints: { rows: 7 } })} value="" onChange={onChange} />);

    const area = screen.getByLabelText('표본') as HTMLTextAreaElement;
    expect(area.tagName).toBe('TEXTAREA');
    expect(area.rows).toBe(7);
    fireEvent.change(area, { target: { value: '여러\n줄' } });
    expect(emitted).toEqual(['여러\n줄']);
  });

  /**
   * @scenario case=boolean
   * @effects boolean_emits_true_false_and_switch_role
   */
  it('예/아니오: 스위치 역할의 체크박스로 그리고 true/false 를 내보낸다', () => {
    const { rerender } = render(<MemberFieldInput field={field({ type: 'boolean', constraints: { inline_label: '소식 받기' } })} value={false} onChange={onChange} />);

    const box = screen.getByRole('switch') as HTMLInputElement;
    expect(box.checked).toBe(false);
    expect(screen.getByText('소식 받기')).toBeTruthy();
    fireEvent.click(box);
    expect(emitted).toEqual([true]);

    rerender(<MemberFieldInput field={field({ type: 'boolean', constraints: { widget: 'checkbox' } })} value={true} onChange={onChange} />);
    expect(screen.queryByRole('switch')).toBeNull();
    expect((screen.getByRole('checkbox') as HTMLInputElement).checked).toBe(true);
  });

  /**
   * @scenario case=choice_select
   * @effects choice_select_emits_value_and_shows_default_without_emitting
   */
  it('하나 선택(목록): 값이 없으면 기본값을 골라 보이되 내보내지 않고, 고르면 선택지 값을 내보낸다', () => {
    const options = [{ value: 'ko', label: '한국어' }, { value: 'en', label: 'English' }];
    render(<MemberFieldInput field={field({ type: 'choice', options, default: 'en' })} value={undefined} onChange={onChange} />);

    const select = screen.getByTestId('select') as HTMLSelectElement;
    expect(select.value).toBe('en');
    expect(emitted).toEqual([]);

    fireEvent.change(select, { target: { value: 'ko' } });
    expect(emitted).toEqual(['ko']);
  });

  /**
   * @scenario case=choice_radio
   * @effects choice_radio_emits_value
   */
  it('하나 선택(라디오): radiogroup 으로 그리고 고른 값을 내보낸다', () => {
    const options = [{ value: 'student', label: '학생' }, { value: 'worker', label: '직장인' }];
    render(<MemberFieldInput field={field({ type: 'choice', options, constraints: { display: 'radio' } })} value="student" onChange={onChange} />);

    expect(screen.getByRole('radiogroup')).toBeTruthy();
    const radios = screen.getAllByRole('radio') as HTMLInputElement[];
    expect(radios.map((radio) => radio.checked)).toEqual([true, false]);
    fireEvent.click(radios[1]);
    expect(emitted).toEqual(['worker']);
  });

  /**
   * @scenario case=choice_other
   * @effects choice_other_emits_value_and_text
   */
  it('하나 선택 + 기타 허용: 「기타」를 고르면 입력칸이 나타나고 { value, other } 를 내보낸다', () => {
    const options = [{ value: 'student', label: '학생' }];
    const fieldDef = field({ type: 'choice', options, constraints: { display: 'radio', allow_other: true } });
    const { rerender } = render(<MemberFieldInput field={fieldDef} value="student" onChange={onChange} />);

    expect(screen.queryByPlaceholderText('member.fields.other_placeholder')).toBeNull();
    fireEvent.click(screen.getAllByRole('radio')[1]);
    expect(emitted[0]).toEqual({ value: MEMBER_FIELD_OTHER, other: '' });

    rerender(<MemberFieldInput field={fieldDef} value={{ value: MEMBER_FIELD_OTHER, other: '' }} onChange={onChange} />);
    fireEvent.change(screen.getByPlaceholderText('member.fields.other_placeholder'), { target: { value: '프리랜서' } });
    expect(emitted[1]).toEqual({ value: MEMBER_FIELD_OTHER, other: '프리랜서' });
  });

  /**
   * @scenario case=multi_choice
   * @effects multi_choice_emits_array
   */
  it('여러 개 선택: 체크박스 묶음으로 그리고 고른 값 배열을 내보낸다(목록 표시도 같은 값)', () => {
    const options = [{ value: 'dev', label: '개발' }, { value: 'plan', label: '기획' }, { value: 'design', label: '디자인' }];
    const { rerender } = render(<MemberFieldInput field={field({ type: 'multi_choice', options })} value={['dev']} onChange={onChange} />);

    const boxes = screen.getAllByRole('checkbox') as HTMLInputElement[];
    expect(boxes.map((box) => box.checked)).toEqual([true, false, false]);
    fireEvent.click(boxes[2]);
    expect(emitted[0]).toEqual(['dev', 'design']);
    fireEvent.click(boxes[0]);
    expect(emitted[1]).toEqual([]);

    rerender(<MemberFieldInput field={field({ type: 'multi_choice', options, constraints: { display: 'select' } })} value={['plan']} onChange={onChange} />);
    expect((screen.getAllByRole('checkbox') as HTMLInputElement[]).map((box) => box.checked)).toEqual([false, true, false]);
    expect(screen.getByRole('group').className).toContain('overflow-y-auto');
  });

  /**
   * @scenario case=date_range
   * @effects date_range_emits_start_end
   */
  it('기간: 날짜 두 칸으로 그리고 { start, end } 를 내보낸다', () => {
    render(<MemberFieldInput field={field({ type: 'date_range' })} value={{ start: '2026-01-01', end: '' }} onChange={onChange} />);

    const start = screen.getByLabelText('member.fields.range_start') as HTMLInputElement;
    const end = screen.getByLabelText('member.fields.range_end') as HTMLInputElement;
    expect(start.value).toBe('2026-01-01');
    expect(end.min).toBe('2026-01-01');

    fireEvent.change(end, { target: { value: '2026-01-31' } });
    expect(emitted[0]).toEqual({ start: '2026-01-01', end: '2026-01-31' });
  });

  /**
   * @scenario case=error_disabled_readonly
   * @effects error_marks_invalid_and_describedby
   */
  it('오류가 있으면 aria-invalid 와 aria-describedby 를 달고, 잠긴 칸은 읽기 전용이다', () => {
    const { rerender } = render(<MemberFieldInput field={field()} value="" error="필수입니다" errorId="mf_error_sample" onChange={onChange} />);

    const input = screen.getByLabelText('표본') as HTMLInputElement;
    expect(input.getAttribute('aria-invalid')).toBe('true');
    expect(input.getAttribute('aria-describedby')).toBe('mf_error_sample');
    expect(input.className).toContain('border-red-500');

    rerender(<MemberFieldInput field={field({ readonly: true })} value="홍길동" disabled onChange={onChange} />);
    const locked = screen.getByLabelText('표본') as HTMLInputElement;
    expect(locked.readOnly).toBe(true);
    expect(locked.disabled).toBe(true);
    expect(locked.getAttribute('aria-invalid')).toBeNull();
  });

  /**
   * @scenario case=widget_country
   * @effects country_widget_lists_codes_with_translated_names
   */
  it('국가(widget=country): 유형이 글이어도 국가 선택 목록을 그리고 ISO 두 글자 코드를 내보낸다', () => {
    (window as any).G7Core.t = (key: string) => ({ 'countries.KR': '대한민국', 'countries.US': '미국' } as Record<string, string>)[key] ?? key;
    const country = field({ key: 'country', name: 'country', type: 'text', label: '국가', constraints: { widget: 'country' } });
    const { rerender } = render(<MemberFieldInput field={country} name="country" value="" onChange={onChange} />);

    const select = screen.getByTestId('select') as HTMLSelectElement;
    expect(select.name).toBe('country');
    expect(select.getAttribute('data-searchable')).toBe('false');
    const options = Array.from(select.options);
    // 맨 앞은 「고르지 않음」, 그 뒤로 국가 코드 목록
    expect(options[0].value).toBe('');
    expect(options[0].textContent).toBe('member.fields.select_placeholder');
    expect(options.slice(1).map((option) => option.value)).toEqual(MEMBER_FIELD_COUNTRY_CODES);
    expect(options[1].textContent).toBe('대한민국');
    expect(options[2].textContent).toBe('미국');
    // 번역이 없는 국가는 코드로 보인다
    expect(options[3].textContent).toBe('JP');

    fireEvent.change(select, { target: { value: 'US' } });
    expect(emitted).toEqual(['US']);

    // 필수이고 값이 있으면 「고르지 않음」을 두지 않는다
    rerender(<MemberFieldInput field={{ ...country, required: true }} name="country" value="KR" onChange={onChange} />);
    const required = screen.getByTestId('select') as HTMLSelectElement;
    expect(required.value).toBe('KR');
    expect(Array.from(required.options).some((option) => option.value === '')).toBe(false);

    // 서버가 선택지를 주면 그것을 쓴다
    rerender(<MemberFieldInput field={{ ...country, options: [{ value: 'KR', label: '한국' }] }} name="country" value="KR" onChange={onChange} />);
    expect(Array.from((screen.getByTestId('select') as HTMLSelectElement).options).map((option) => option.textContent)).toEqual(['member.fields.select_placeholder', '한국']);
  });

  /**
   * @scenario case=widget_timezone
   * @effects timezone_widget_is_searchable_and_reads_global_list
   */
  it('시간대(widget=timezone): 전역 설정의 시간대 목록으로 검색되는 선택 목록을 그린다 (prop 이 있으면 그것이 먼저)', () => {
    (window as any).G7Core.state = {
      get: () => ({ appConfig: { supportedTimezones: [{ value: 'Asia/Seoul', label: '(UTC+09:00) 서울' }, { value: 'UTC', label: 'UTC' }] } }),
    };
    const timezone = field({ key: 'timezone', name: 'timezone', type: 'text', label: '시간대', constraints: { widget: 'timezone' } });
    const { rerender } = render(<MemberFieldInput field={timezone} name="timezone" value="Asia/Seoul" onChange={onChange} />);

    const select = screen.getByTestId('select') as HTMLSelectElement;
    expect(select.getAttribute('data-searchable')).toBe('true');
    expect(select.value).toBe('Asia/Seoul');
    expect(Array.from(select.options).map((option) => option.value)).toEqual(['', 'Asia/Seoul', 'UTC']);
    expect(select.options[1].textContent).toBe('(UTC+09:00) 서울');

    fireEvent.change(select, { target: { value: 'UTC' } });
    expect(emitted).toEqual(['UTC']);

    // 글자 배열로 준 목록도 받는다
    rerender(<MemberFieldInput field={timezone} name="timezone" value="" timezones={['Europe/Paris']} onChange={onChange} />);
    expect(Array.from((screen.getByTestId('select') as HTMLSelectElement).options).map((option) => option.value)).toEqual(['', 'Europe/Paris']);

    // 전역 설정을 읽을 수 없어도 그린다(빈 목록)
    delete (window as any).G7Core.state;
    rerender(<MemberFieldInput field={timezone} name="timezone" value="" onChange={onChange} />);
    expect(Array.from((screen.getByTestId('select') as HTMLSelectElement).options).map((option) => option.value)).toEqual(['']);
  });

  /**
   * @scenario case=readonly_lock
   * @effects readonly_locks_select_types_and_greys_input
   */
  it('잠긴 항목(readonly): 글 칸은 회색 읽기 전용이고 선택류(목록, 국가, 시간대, 라디오, 체크박스)는 고를 수 없다', () => {
    const { rerender } = render(<MemberFieldInput field={field({ readonly: true })} value="홍길동" onChange={onChange} />);
    const text = screen.getByLabelText('표본') as HTMLInputElement;
    expect(text.readOnly).toBe(true);
    expect(text.disabled).toBe(false);
    expect(text.className).toContain('bg-gray-50');
    expect(text.className).toContain('dark:bg-gray-700');
    expect(text.className).toContain('cursor-not-allowed');
    expect(text.className).not.toContain('bg-white');

    // 회사 계정이 잠근 칸은 읽기 전용이되 종전 화면과 같은 흰 배경이다
    rerender(<MemberFieldInput field={field({ readonly: true, locked_reason: 'sso' })} value="홍길동" onChange={onChange} />);
    const ssoLocked = screen.getByLabelText('표본') as HTMLInputElement;
    expect(ssoLocked.readOnly).toBe(true);
    expect(ssoLocked.className).toContain('bg-white');
    expect(ssoLocked.className).not.toContain('cursor-not-allowed');

    // 잠기지 않은 칸은 종전 모양 그대로
    rerender(<MemberFieldInput field={field()} value="홍길동" onChange={onChange} />);
    expect((screen.getByLabelText('표본') as HTMLInputElement).className).toContain('bg-white');
    expect((screen.getByLabelText('표본') as HTMLInputElement).className).not.toContain('cursor-not-allowed');

    const options = [{ value: 'a', label: '가' }, { value: 'b', label: '나' }];
    rerender(<MemberFieldInput field={field({ type: 'choice', options, readonly: true })} value="a" onChange={onChange} />);
    expect((screen.getByTestId('select') as HTMLSelectElement).disabled).toBe(true);

    rerender(<MemberFieldInput field={field({ constraints: { widget: 'country' }, readonly: true })} value="KR" onChange={onChange} />);
    expect((screen.getByTestId('select') as HTMLSelectElement).disabled).toBe(true);

    rerender(<MemberFieldInput field={field({ constraints: { widget: 'timezone' }, readonly: true })} value="UTC" timezones={['UTC']} onChange={onChange} />);
    expect((screen.getByTestId('select') as HTMLSelectElement).disabled).toBe(true);

    rerender(<MemberFieldInput field={field({ type: 'choice', options, constraints: { display: 'radio' }, readonly: true })} value="a" onChange={onChange} />);
    expect(screen.getAllByRole('radio').every((radio) => (radio as HTMLInputElement).disabled)).toBe(true);

    rerender(<MemberFieldInput field={field({ type: 'multi_choice', options, readonly: true })} value={['a']} onChange={onChange} />);
    expect(screen.getAllByRole('checkbox').every((box) => (box as HTMLInputElement).disabled)).toBe(true);
    expect(emitted).toEqual([]);
  });

  /**
   * @scenario case=date_bounds
   * @effects relative_date_bounds_resolve_to_today
   */
  it('날짜 제한: 날짜 글자는 그대로, today·after_today 는 브라우저의 오늘 기준으로 바꾼다', () => {
    const pad = (n: number) => String(n).padStart(2, '0');
    const now = new Date();
    const today = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
    const tomorrow = new Date(now);
    tomorrow.setDate(now.getDate() + 1);

    expect(resolveDateBound('2026-01-01', 'min')).toBe('2026-01-01');
    expect(resolveDateBound('today', 'max')).toBe(today);
    expect(resolveDateBound('after_today', 'min')).toBe(`${tomorrow.getFullYear()}-${pad(tomorrow.getMonth() + 1)}-${pad(tomorrow.getDate())}`);
    expect(resolveDateBound('today', 'min', true)).toBe(`${today}T00:00`);
    expect(resolveDateBound(undefined, 'min')).toBeUndefined();
  });
});
