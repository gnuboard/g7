/**
 * MemberFieldInput 컴포넌트 테스트 (관리자 템플릿)
 *
 * @description 서버 항목 계약의 유형과 제한 값에 따라 관리자 템플릿의 입력 부품을 고르고, 유형별 값 모양으로 변경을 내보내는지 검증한다.
 * 값 모양은 서버 검증 규칙과 같아야 한다 — 글 유형은 문자열, 예/아니오는 boolean, 여러 개 선택은 배열,
 * 기간은 { start, end }, 「기타」를 고른 하나 선택은 { value: '__other__', other }.
 * 관리자 템플릿의 부품 배정: 예/아니오는 Toggle, 라디오 표시는 RadioGroup, 여러 개 선택의 목록 표시는 TagInput, 국가는 SearchableDropdown.
 *
 * @scenario-manifest tests/scenarios/admin-member-field-input.yaml
 */

import fs from 'node:fs';
import path from 'node:path';
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
    <select data-testid="select" data-searchable={rest.searchable ? 'true' : 'false'} name={name} value={value} disabled={disabled} className={className} onChange={onChange} aria-label={rest['aria-label']} aria-invalid={rest['aria-invalid']} aria-describedby={rest['aria-describedby']}>
      {options.map((option: any) => (
        <option key={String(option.value)} value={option.value}>
          {option.label}
        </option>
      ))}
    </select>
  ),
}));
// 관리자 템플릿의 묶음 부품은 각자 테스트가 있다 — 여기서는 어느 부품에 무엇을 넘기는지와 값 변환만 본다
vi.mock('../Toggle', () => ({
  Toggle: ({ checked, onChange, disabled, label, ariaLabel }: any) => (
    <span>
      <button type="button" role="switch" data-part="Toggle" aria-checked={!!checked} aria-label={ariaLabel} disabled={!!disabled} onClick={() => onChange?.({ target: { checked: !checked } })} />
      {label ? <span data-toggle-label="">{label}</span> : null}
    </span>
  ),
}));
vi.mock('../RadioGroup', () => ({
  RadioGroup: ({ name, value, options = [], onChange, disabled, inline }: any) => (
    <div data-part="RadioGroup" data-inline={inline ? 'true' : 'false'}>
      {options.map((option: any) => (
        <label key={option.value}>
          <input type="radio" name={name} value={option.value} checked={String(value) === String(option.value)} disabled={!!disabled} onChange={onChange} />
          {option.label}
        </label>
      ))}
    </div>
  ),
}));
vi.mock('../TagInput', () => ({
  TagInput: ({ name, value = [], options = [], onChange, disabled, creatable, ...rest }: any) => (
    <div data-part="TagInput" data-name={name} data-disabled={disabled ? 'true' : 'false'} data-creatable={creatable ? 'true' : 'false'} aria-label={rest['aria-label']}>
      {value.map((picked: string) => (
        <span key={picked} data-chip="">
          {picked}
        </span>
      ))}
      {options.map((option: any) => (
        <button key={option.value} type="button" data-tag-option={option.value} onClick={() => onChange?.({ target: { value: [...value, option.value] } })}>
          {option.label}
        </button>
      ))}
    </div>
  ),
}));
vi.mock('../SearchableDropdown', () => ({
  SearchableDropdown: ({ options = [], value, onChange, disabled, triggerLabel, searchPlaceholder, noResultsText, ...rest }: any) => (
    <div data-part="SearchableDropdown" data-value={value ?? ''} data-disabled={disabled ? 'true' : 'false'} data-trigger-label={triggerLabel} data-search-placeholder={searchPlaceholder} data-no-results={noResultsText} aria-label={rest['aria-label']}>
      {options.map((option: any) => (
        <button key={option.value} type="button" data-country={option.value} onClick={() => onChange?.(option.value)}>
          {option.label}
        </button>
      ))}
    </div>
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

const part = (container: HTMLElement, name: string) => container.querySelector(`[data-part="${name}"]`) as HTMLElement | null;

describe('MemberFieldInput (관리자 템플릿)', () => {
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
    expect(input.name).toBe('custom_fields.sample');
    expect(input.placeholder).toBe('적어 주세요');
    expect(input.maxLength).toBe(20);
    expect(input.getAttribute('aria-required')).toBe('true');

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
  it('여러 줄 글: 줄 수 제한 값을 rows 로 쓰고, 없으면 종전 회원 폼과 같은 세 줄이다', () => {
    const { rerender } = render(<MemberFieldInput field={field({ type: 'textarea', constraints: { rows: 7 } })} value="" onChange={onChange} />);

    const area = screen.getByLabelText('표본') as HTMLTextAreaElement;
    expect(area.tagName).toBe('TEXTAREA');
    expect(area.rows).toBe(7);
    fireEvent.change(area, { target: { value: '여러\n줄' } });
    expect(emitted).toEqual(['여러\n줄']);

    rerender(<MemberFieldInput field={field({ type: 'textarea' })} value="" onChange={onChange} />);
    expect((screen.getByLabelText('표본') as HTMLTextAreaElement).rows).toBe(3);
  });

  /**
   * @scenario case=boolean
   * @effects boolean_uses_toggle_and_emits_true_false
   */
  it('예/아니오: 관리자 템플릿의 Toggle 로 그리고 true/false 를 내보낸다 (widget=checkbox 면 체크박스)', () => {
    const { container, rerender } = render(<MemberFieldInput field={field({ type: 'boolean', constraints: { inline_label: '소식 받기' } })} value={false} onChange={onChange} />);

    expect(part(container, 'Toggle')).not.toBeNull();
    const toggle = screen.getByRole('switch');
    expect(toggle.getAttribute('aria-checked')).toBe('false');
    expect(toggle.getAttribute('aria-label')).toBe('표본');
    expect(screen.getByText('소식 받기')).toBeTruthy();
    fireEvent.click(toggle);
    expect(emitted).toEqual([true]);

    // 값이 없으면 기본값을 켜진 모양으로 보이되 내보내지 않는다
    rerender(<MemberFieldInput field={field({ type: 'boolean', default: true })} value={undefined} onChange={onChange} />);
    expect(screen.getByRole('switch').getAttribute('aria-checked')).toBe('true');
    expect(emitted).toEqual([true]);

    rerender(<MemberFieldInput field={field({ type: 'boolean', constraints: { widget: 'checkbox' } })} value={true} onChange={onChange} />);
    expect(screen.queryByRole('switch')).toBeNull();
    const box = screen.getByRole('checkbox') as HTMLInputElement;
    expect(box.checked).toBe(true);
    fireEvent.click(box);
    expect(emitted).toEqual([true, false]);
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
    expect(Array.from(select.options).map((option) => option.value)).toEqual(['', 'ko', 'en']);
    expect(select.options[0].textContent).toBe('admin.users.member_fields.select_placeholder');
    expect(emitted).toEqual([]);

    fireEvent.change(select, { target: { value: 'ko' } });
    expect(emitted).toEqual(['ko']);
  });

  /**
   * @scenario case=choice_radio
   * @effects choice_radio_uses_radio_group_and_emits_value
   */
  it('하나 선택(라디오): 관리자 템플릿의 RadioGroup 을 radiogroup 안에 그리고 고른 값을 내보낸다', () => {
    const options = [{ value: 'student', label: '학생' }, { value: 'worker', label: '직장인' }];
    const { container } = render(<MemberFieldInput field={field({ type: 'choice', options, constraints: { display: 'radio' } })} value="student" onChange={onChange} />);

    const group = screen.getByRole('radiogroup');
    expect(group.getAttribute('aria-label')).toBe('표본');
    expect(group.contains(part(container, 'RadioGroup'))).toBe(true);
    const radios = screen.getAllByRole('radio') as HTMLInputElement[];
    expect(radios.map((radio) => radio.checked)).toEqual([true, false]);
    // 라디오의 name 은 폼 경로 그대로가 아니다(한 화면에서 겹치지 않게) — 폼 경로는 변경 이벤트에 싣는다
    expect(radios[0].name).not.toBe('custom_fields.sample');
    expect(radios[0].name).toContain('custom_fields.sample');
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

    expect(screen.queryByPlaceholderText('admin.users.member_fields.other_placeholder')).toBeNull();
    fireEvent.click(screen.getAllByRole('radio')[1]);
    expect(emitted[0]).toEqual({ value: MEMBER_FIELD_OTHER, other: '' });

    rerender(<MemberFieldInput field={fieldDef} value={{ value: MEMBER_FIELD_OTHER, other: '' }} onChange={onChange} />);
    expect((screen.getAllByRole('radio')[1] as HTMLInputElement).checked).toBe(true);
    fireEvent.change(screen.getByPlaceholderText('admin.users.member_fields.other_placeholder'), { target: { value: '프리랜서' } });
    expect(emitted[1]).toEqual({ value: MEMBER_FIELD_OTHER, other: '프리랜서' });

    // 목록 표시에서도 같은 값 모양이다
    const asSelect = field({ type: 'choice', options, constraints: { allow_other: true } });
    rerender(<MemberFieldInput field={asSelect} value="student" onChange={onChange} />);
    fireEvent.change(screen.getByTestId('select'), { target: { value: MEMBER_FIELD_OTHER } });
    expect(emitted[2]).toEqual({ value: MEMBER_FIELD_OTHER, other: '' });
  });

  /**
   * @scenario case=multi_choice
   * @effects multi_choice_checkbox_group_emits_array
   */
  it('여러 개 선택(체크박스): 체크박스 묶음으로 그리고 고른 값 배열을 내보낸다', () => {
    const options = [{ value: 'dev', label: '개발' }, { value: 'plan', label: '기획' }, { value: 'design', label: '디자인' }];
    const { container } = render(<MemberFieldInput field={field({ type: 'multi_choice', options })} value={['dev']} onChange={onChange} />);

    expect(part(container, 'TagInput')).toBeNull();
    expect(screen.getByRole('group').getAttribute('aria-label')).toBe('표본');
    const boxes = screen.getAllByRole('checkbox') as HTMLInputElement[];
    expect(boxes.map((box) => box.checked)).toEqual([true, false, false]);
    fireEvent.click(boxes[2]);
    expect(emitted[0]).toEqual(['dev', 'design']);
    fireEvent.click(boxes[0]);
    expect(emitted[1]).toEqual([]);
  });

  /**
   * @scenario case=multi_choice_list
   * @effects multi_choice_list_uses_tag_input
   */
  it('여러 개 선택(목록): 관리자 템플릿의 TagInput 으로 그리고 고른 값 배열을 내보낸다 (새 값은 만들 수 없다)', () => {
    const options = [{ value: 'dev', label: '개발' }, { value: 'plan', label: '기획' }];
    const { container } = render(<MemberFieldInput field={field({ type: 'multi_choice', options, constraints: { display: 'select' } })} name="custom_fields.sample" value={['dev']} onChange={onChange} />);

    const tags = part(container, 'TagInput')!;
    expect(tags).not.toBeNull();
    expect(tags.getAttribute('data-name')).toBe('custom_fields.sample');
    expect(tags.getAttribute('data-creatable')).toBe('false');
    expect(tags.getAttribute('aria-label')).toBe('표본');
    expect(Array.from(tags.querySelectorAll('[data-chip]')).map((chip) => chip.textContent)).toEqual(['dev']);
    expect(screen.queryAllByRole('checkbox')).toEqual([]);

    fireEvent.click(tags.querySelector('[data-tag-option="plan"]')!);
    expect(emitted).toEqual([['dev', 'plan']]);
  });

  /**
   * @scenario case=date_range
   * @effects date_range_emits_start_end
   */
  it('기간: 날짜 두 칸으로 그리고 { start, end } 를 내보낸다', () => {
    render(<MemberFieldInput field={field({ type: 'date_range' })} value={{ start: '2026-01-01', end: '' }} onChange={onChange} />);

    const start = screen.getByLabelText('admin.users.member_fields.range_start') as HTMLInputElement;
    const end = screen.getByLabelText('admin.users.member_fields.range_end') as HTMLInputElement;
    expect(start.value).toBe('2026-01-01');
    expect(end.min).toBe('2026-01-01');

    fireEvent.change(end, { target: { value: '2026-01-31' } });
    expect(emitted[0]).toEqual({ start: '2026-01-01', end: '2026-01-31' });
  });

  /**
   * @scenario case=error
   * @effects error_marks_invalid_and_describedby
   */
  it('오류가 있으면 aria-invalid, aria-describedby 와 오류 테두리(input-error)를 단다', () => {
    const { rerender } = render(<MemberFieldInput field={field()} value="" error="필수입니다" errorId="mf_error_sample" onChange={onChange} />);

    const input = screen.getByLabelText('표본') as HTMLInputElement;
    expect(input.getAttribute('aria-invalid')).toBe('true');
    expect(input.getAttribute('aria-describedby')).toBe('mf_error_sample');
    expect(input.className).toContain('input-error');

    rerender(<MemberFieldInput field={field({ type: 'choice', options: [{ value: 'a', label: '가' }] })} value="" error="골라 주세요" errorId="mf_error_sample" onChange={onChange} />);
    const select = screen.getByTestId('select');
    expect(select.getAttribute('aria-invalid')).toBe('true');
    expect(select.className).toContain('input-error');

    rerender(<MemberFieldInput field={field()} value="" error="" onChange={onChange} />);
    const clean = screen.getByLabelText('표본') as HTMLInputElement;
    expect(clean.getAttribute('aria-invalid')).toBeNull();
    expect(clean.className).not.toContain('input-error');
  });

  /**
   * @scenario case=widget_country
   * @effects country_widget_uses_searchable_dropdown_with_list_filter_countries
   */
  it('국가(widget=country): 종전 회원 폼과 같은 SearchableDropdown 과 같은 국가 목록을 쓰고 ISO 두 글자 코드를 내보낸다', () => {
    (window as any).G7Core.t = (key: string) => ({ 'countries.KR': '대한민국', 'countries.US': '미국' } as Record<string, string>)[key] ?? key;
    const country = field({ key: 'country', name: 'country', type: 'text', label: '국가', constraints: { widget: 'country' } });
    const { container, rerender } = render(<MemberFieldInput field={country} name="country" value="KR" onChange={onChange} />);

    const dropdown = part(container, 'SearchableDropdown')!;
    expect(dropdown).not.toBeNull();
    expect(dropdown.getAttribute('data-value')).toBe('KR');
    expect(dropdown.getAttribute('aria-label')).toBe('국가');
    expect(dropdown.getAttribute('data-trigger-label')).toBe('admin.users.form.fields.country_select');
    expect(dropdown.getAttribute('data-search-placeholder')).toBe('admin.users.form.fields.country_search');
    expect(dropdown.getAttribute('data-no-results')).toBe('common.no_results');
    const buttons = Array.from(dropdown.querySelectorAll('[data-country]'));
    expect(buttons.map((button) => button.getAttribute('data-country'))).toEqual(MEMBER_FIELD_COUNTRY_CODES);
    expect(buttons[0].textContent).toBe('대한민국');
    expect(buttons[1].textContent).toBe('미국');
    // 번역이 없는 국가는 코드로 보인다
    expect(buttons[2].textContent).toBe('JP');

    fireEvent.click(buttons[1]);
    expect(emitted).toEqual(['US']);

    // 서버가 선택지를 주면 그것을 쓴다
    rerender(<MemberFieldInput field={{ ...country, options: [{ value: 'KR', label: '한국' }] }} name="country" value="KR" onChange={onChange} />);
    expect(Array.from(part(container, 'SearchableDropdown')!.querySelectorAll('[data-country]')).map((button) => button.textContent)).toEqual(['한국']);
  });

  /**
   * @scenario case=widget_country
   * @effects country_codes_match_user_list_filter
   */
  it('국가 목록은 회원 목록의 국가 필터(countryList)와 같다 — 여기서 고른 값을 목록이 찾는다', () => {
    const listLayout = fs.readFileSync(path.resolve(__dirname, '../../../../layouts/admin_user_list.g7.html'), 'utf-8');
    const block = /<define name="countryList">([\s\S]*?)<\/define>/.exec(listLayout);
    expect(block, '회원 목록의 countryList 정의').not.toBeNull();
    const codes = [...block![1].matchAll(/<item code="([A-Z]{2})"/g)].map((match) => match[1]);

    expect(MEMBER_FIELD_COUNTRY_CODES).toEqual(codes);
  });

  /**
   * @scenario case=widget_timezone
   * @effects timezone_widget_is_searchable_and_reads_global_list
   */
  it('시간대(widget=timezone): 전역 설정의 시간대 목록으로 검색되는 선택 목록을 그린다 (prop 이 있으면 그것이 먼저)', () => {
    (window as any).G7Core.state = {
      get: () => ({ appConfig: { supportedTimezones: [{ value: 'Asia/Seoul', label: '(UTC+09:00) 서울' }, { value: 'UTC', label: 'UTC' }] } }),
    };
    const timezone = field({ key: 'timezone', name: 'timezone', type: 'text', label: '시간대', constraints: { widget: 'timezone', format: 'timezone' } });
    const { rerender } = render(<MemberFieldInput field={timezone} name="timezone" value="Asia/Seoul" onChange={onChange} />);

    const select = screen.getByTestId('select') as HTMLSelectElement;
    expect(select.name).toBe('timezone');
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
   * @effects readonly_or_disabled_locks_every_input_kind
   */
  it('잠긴 항목(readonly)과 비활성(disabled): 어느 입력 부품이든 고칠 수 없다 (종전 회원 폼처럼 disabled)', () => {
    const { container, rerender } = render(<MemberFieldInput field={field({ readonly: true, locked_reason: 'sso' })} value="홍길동" onChange={onChange} />);
    expect((screen.getByLabelText('표본') as HTMLInputElement).disabled).toBe(true);

    rerender(<MemberFieldInput field={field()} value="홍길동" disabled onChange={onChange} />);
    expect((screen.getByLabelText('표본') as HTMLInputElement).disabled).toBe(true);

    rerender(<MemberFieldInput field={field()} value="홍길동" onChange={onChange} />);
    expect((screen.getByLabelText('표본') as HTMLInputElement).disabled).toBe(false);

    rerender(<MemberFieldInput field={field({ type: 'textarea', readonly: true })} value="글" onChange={onChange} />);
    expect((screen.getByLabelText('표본') as HTMLTextAreaElement).disabled).toBe(true);

    const options = [{ value: 'a', label: '가' }, { value: 'b', label: '나' }];
    rerender(<MemberFieldInput field={field({ type: 'choice', options, readonly: true })} value="a" onChange={onChange} />);
    expect((screen.getByTestId('select') as HTMLSelectElement).disabled).toBe(true);

    rerender(<MemberFieldInput field={field({ constraints: { widget: 'country' }, readonly: true })} value="KR" onChange={onChange} />);
    expect(part(container, 'SearchableDropdown')!.getAttribute('data-disabled')).toBe('true');

    rerender(<MemberFieldInput field={field({ constraints: { widget: 'timezone' }, readonly: true })} value="UTC" timezones={['UTC']} onChange={onChange} />);
    expect((screen.getByTestId('select') as HTMLSelectElement).disabled).toBe(true);

    rerender(<MemberFieldInput field={field({ type: 'choice', options, constraints: { display: 'radio' }, readonly: true })} value="a" onChange={onChange} />);
    expect(screen.getAllByRole('radio').every((radio) => (radio as HTMLInputElement).disabled)).toBe(true);

    rerender(<MemberFieldInput field={field({ type: 'multi_choice', options, readonly: true })} value={['a']} onChange={onChange} />);
    expect(screen.getAllByRole('checkbox').every((box) => (box as HTMLInputElement).disabled)).toBe(true);

    rerender(<MemberFieldInput field={field({ type: 'multi_choice', options, constraints: { display: 'select' }, readonly: true })} value={['a']} onChange={onChange} />);
    expect(part(container, 'TagInput')!.getAttribute('data-disabled')).toBe('true');

    rerender(<MemberFieldInput field={field({ type: 'boolean', readonly: true })} value={true} onChange={onChange} />);
    expect((screen.getByRole('switch') as HTMLButtonElement).disabled).toBe(true);
    expect(emitted).toEqual([]);
  });

  /**
   * @scenario case=date_bounds
   * @effects relative_date_bounds_resolve_to_today
   */
  it('날짜 제한: 날짜 글자는 그대로, today 와 after_today 는 브라우저의 오늘 기준으로 바꾼다', () => {
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

  /**
   * @scenario case=no_field
   * @effects renders_nothing_without_field
   */
  it('항목 계약이 없으면 아무것도 그리지 않는다', () => {
    const { container } = render(<MemberFieldInput field={undefined as any} value="" onChange={onChange} />);
    expect(container.innerHTML).toBe('');
  });
});
