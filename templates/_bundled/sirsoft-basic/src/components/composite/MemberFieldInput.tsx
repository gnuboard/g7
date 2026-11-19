/**
 * MemberFieldInput 컴포넌트
 *
 * 회원 항목 1개의 입력칸을 그린다. 가입 화면, 내 정보 수정, 추가 정보 입력이 같은 컴포넌트를 쓴다.
 * 어떤 입력칸을 그릴지는 서버가 준 항목 계약(`field`)의 `type` 과 `constraints` 가 정한다 — 화면은 유형을 모른다.
 *
 * 값은 폼 자동 바인딩으로 오간다: 레이아웃이 `name="{{field.name}}"` 을 주면 엔진이 `value` 와 `onChange` 를 꽂는다.
 * 유형별 값 모양은 서버 검증 규칙과 같다.
 *
 * | 유형 | 값 |
 * | --- | --- |
 * | text, textarea, phone, email, url, number, date, datetime | 문자열 |
 * | boolean | true / false |
 * | choice | 선택지 값 문자열. 「기타」를 고르면 `{ value: '__other__', other: '입력 글' }` |
 * | multi_choice | 선택지 값 배열 |
 * | date_range | `{ start, end }` |
 *
 * `constraints.widget` 이 `country` 면 국가 선택 목록(값은 ISO 2글자 코드), `timezone` 이면 검색되는 시간대 목록을 그린다(유형과 무관).
 * `field.readonly` 면 어느 입력칸이든 고칠 수 없게 잠그고 회색으로 그린다(잠근 사유 문구는 레이아웃 조각이 그 아래에 둔다).
 * 사유가 회사 계정(`locked_reason === 'sso'`)이면 잠그되 종전 화면과 같은 흰 배경으로 둔다.
 *
 * 주소, 파일, 확장 유형은 이 컴포넌트가 그리지 않는다(레이아웃 조각이 `field.renderer` 로 갈라 그린다).
 */

import React, { useCallback, useId } from 'react';
import { Div } from '../basic/Div';
import { Input } from '../basic/Input';
import { Textarea } from '../basic/Textarea';
import { Select } from '../basic/Select';
import { Checkbox } from '../basic/Checkbox';
import { Label } from '../basic/Label';
import { Span } from '../basic/Span';
import { BREAKPOINTS, useViewportWidth } from '../layout/viewportWidth';
import type { EditorAttrs } from '../../types';

/** 「기타(직접 입력)」 선택지의 값 — 서버 `FieldValue::OTHER_INDEX` 와 같다 */
export const MEMBER_FIELD_OTHER = '__other__';

const t = (key: string, params?: Record<string, string | number>): string =>
  (window as any).G7Core?.t?.(key, params) ?? key;

export interface MemberFieldOption {
  value: string;
  label: string;
}

/** 서버가 주는 항목 계약 (`GET /api/auth/register/fields` 등) */
export interface MemberFieldContract {
  key: string;
  name: string;
  type: string;
  renderer?: string;
  label?: string;
  placeholder?: string | null;
  help?: string | null;
  required?: boolean;
  readonly?: boolean;
  locked_reason?: string | null;
  options?: MemberFieldOption[];
  constraints?: Record<string, any>;
  default?: any;
}

export interface MemberFieldInputProps {
  /** 항목 계약 */
  field: MemberFieldContract;
  /** 폼 경로 (자동 바인딩의 필드 이름) */
  name?: string;
  /** 현재 값 */
  value?: any;
  /** 값 변경 콜백 (엔진 변경 이벤트를 받는다) */
  onChange?: (event: any) => void;
  /** 이 칸의 검증 오류 문구 (있으면 빨간 테두리와 aria-invalid) */
  error?: string | null;
  /** 오류 문구 노드의 id (aria-describedby 로 잇는다) */
  errorId?: string;
  /** 비활성 여부 (제출 중 등) */
  disabled?: boolean;
  /** 시간대 선택지 (생략하면 전역 `appConfig.supportedTimezones` 를 읽는다) */
  timezones?: Array<string | MemberFieldOption>;
  /** 입력칸에 붙일 클래스 (화면마다 입력칸 모양이 달라 레이아웃이 준다) */
  inputClassName?: string;
  /** 오류가 있을 때 입력칸에 붙일 클래스 */
  errorClassName?: string;
  /** 루트 클래스 */
  className?: string;
  /** DOM id (레이아웃 편집기 코어 일괄 ID) */
  id?: string;
  /** 레이아웃 편집기 주입 속성 (편집 모드 전용, 루트에 spread) */
  editorAttrs?: EditorAttrs;
}

const DEFAULT_INPUT_CLASS =
  'w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-white dark:bg-gray-700 text-gray-900 dark:text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500';

/** 본인이 고칠 수 없는 칸(readonly)의 입력칸 모양 — 내 정보 수정의 이메일 칸과 같은 회색 */
const READONLY_INPUT_CLASS =
  'w-full px-4 py-2 border border-gray-300 dark:border-gray-600 rounded-lg bg-gray-50 dark:bg-gray-700 text-gray-500 dark:text-gray-400 cursor-not-allowed';

const DEFAULT_ERROR_CLASS = 'border-red-500 dark:border-red-500';

const CHECK_CLASS = 'h-4 w-4 shrink-0 rounded border-gray-300 dark:border-gray-600 text-blue-600 dark:text-blue-400';

const OPTION_ROW_CLASS = 'flex min-h-11 items-center gap-2 text-sm text-gray-700 dark:text-gray-300 cursor-pointer';

const HTML_INPUT_TYPES: Record<string, string> = {
  text: 'text',
  phone: 'tel',
  email: 'email',
  url: 'url',
  number: 'number',
  date: 'date',
  datetime: 'datetime-local',
};

/** 국가 선택 목록의 국가 코드(ISO 3166-1 두 글자). 이름은 다국어 `countries.{코드}` 에서 읽는다 */
export const MEMBER_FIELD_COUNTRY_CODES = [
  'KR', 'US', 'JP', 'CN', 'GB', 'DE', 'FR', 'CA', 'AU', 'IN', 'BR', 'RU', 'IT', 'ES', 'MX', 'NL',
  'SE', 'CH', 'SG', 'HK', 'TW', 'TH', 'VN', 'PH', 'MY', 'ID', 'NZ', 'AT', 'BE', 'DK', 'FI', 'NO',
  'PL', 'PT', 'IE', 'GR', 'CZ', 'HU', 'TR', 'IL', 'AE', 'SA', 'ZA', 'EG', 'AR', 'CL', 'CO', 'PE',
];

/**
 * 전역 상태의 시간대 선택지(`appConfig.supportedTimezones`)를 읽는다.
 *
 * @returns 시간대 선택지 (없으면 빈 배열)
 */
function readGlobalTimezones(): Array<string | MemberFieldOption> {
  const list = (window as any).G7Core?.state?.get?.()?.appConfig?.supportedTimezones;
  return Array.isArray(list) ? list : [];
}

/**
 * 날짜 제한 값(날짜 글자 또는 `today`·`after_today`)을 입력칸의 min/max 로 바꾼다.
 *
 * 화면의 제한은 편의일 뿐이고 판정은 서버가 사이트 시간대로 한다 — 그래서 상대 기준은 브라우저의 오늘로 어림한다.
 *
 * @param bound 제한 값
 * @param side 가장 이른 날(min)인지 가장 늦은 날(max)인지
 * @param withTime 날짜+시간 입력칸인지
 * @returns 입력칸에 줄 값 (없으면 undefined)
 */
export function resolveDateBound(bound: unknown, side: 'min' | 'max', withTime = false): string | undefined {
  if (typeof bound !== 'string' || bound === '') {
    return undefined;
  }

  let date = bound;
  if (bound === 'today' || bound === 'after_today') {
    const now = new Date();
    if (bound === 'after_today') {
      now.setDate(now.getDate() + (side === 'min' ? 1 : -1));
    }
    const pad = (n: number) => String(n).padStart(2, '0');
    date = `${now.getFullYear()}-${pad(now.getMonth() + 1)}-${pad(now.getDate())}`;
  }

  if (!withTime || date.includes('T')) {
    return date;
  }

  return `${date}T${side === 'min' ? '00:00' : '23:59'}`;
}

/**
 * 회원 항목 입력칸
 *
 * @example
 * ```html
 * <member-field-input field="{{field}}" name="{{field.name}}" error="{{_local.errors?.[field.name]?.[0]}}"/>
 * ```
 */
const MemberFieldInput: React.FC<MemberFieldInputProps> = ({
  field,
  name,
  value,
  onChange,
  error,
  errorId,
  disabled = false,
  timezones,
  inputClassName,
  errorClassName,
  className = '',
  id,
  editorAttrs,
}) => {
  const generatedId = useId();
  // 선택지 묶음은 좁은 화면에서 세로 한 줄씩, 넓은 화면에서 옆으로 흐른다 — 폭은 엔진 폭(편집기 미리보기 폭을 따른다)으로 가른다.
  const optionGroupClass = useViewportWidth() < BREAKPOINTS.sm ? 'flex flex-col' : 'flex flex-row flex-wrap gap-x-4';
  const fieldName = name ?? field?.name ?? '';
  const constraints = field?.constraints ?? {};
  const options = Array.isArray(field?.options) ? field.options : [];
  const locked = disabled || field?.readonly === true;
  const hasError = typeof error === 'string' && error !== '';
  // 회사 계정(SSO)이 잠근 칸은 이 부품이 생기기 전부터 흰 배경의 읽기 전용이었다 — 그 모양을 그대로 둔다. 그 밖의 잠금은 회색으로 그린다.
  const greyed = field?.readonly === true && field?.locked_reason !== 'sso';
  const baseInputClass = inputClassName ?? (greyed ? READONLY_INPUT_CLASS : DEFAULT_INPUT_CLASS);
  const inputClass = `${baseInputClass}${hasError ? ` ${errorClassName ?? DEFAULT_ERROR_CLASS}` : ''}`;
  const label = field?.label ?? field?.key ?? '';

  const emit = useCallback(
    (next: unknown) => {
      if (!onChange) {
        return;
      }
      const event =
        (window as any).G7Core?.createChangeEvent?.({ value: next, name: fieldName }) ??
        { target: { name: fieldName, value: next } };
      onChange(event);
    },
    [onChange, fieldName],
  );

  const aria = {
    'aria-label': label,
    'aria-required': field?.required ? true : undefined,
    'aria-invalid': hasError ? true : undefined,
    'aria-describedby': hasError && errorId ? errorId : undefined,
  };

  const root = (children: React.ReactNode) => (
    <Div id={id} className={className} data-member-field={field?.key} {...(editorAttrs ?? {})}>
      {children}
    </Div>
  );

  if (!field) {
    return null;
  }

  // 국가, 시간대 — 유형이 글이어도 제한 값의 widget 이 선택 목록을 정한다. 서버가 선택지를 주면 그것을 쓴다.
  if (constraints.widget === 'country' || constraints.widget === 'timezone') {
    const isCountry = constraints.widget === 'country';
    const shown = value ?? field.default ?? '';
    const source: Array<string | MemberFieldOption> =
      options.length > 0
        ? options
        : isCountry
          ? MEMBER_FIELD_COUNTRY_CODES.map((code) => {
              const translated = t(`countries.${code}`);
              return { value: code, label: translated === `countries.${code}` ? code : translated };
            })
          : timezones ?? readGlobalTimezones();
    const widgetOptions = [
      ...(field.required && shown !== '' ? [] : [{ value: '', label: field.placeholder ?? t('member.fields.select_placeholder') }]),
      ...source.map((option) => (typeof option === 'string' ? { value: option, label: option } : option)),
    ];

    return root(
      <Select
        name={fieldName}
        value={shown}
        options={widgetOptions}
        disabled={locked}
        searchable={!isCountry}
        className={inputClass}
        onChange={(e: any) => emit(e?.target?.value ?? '')}
        {...aria}
      />,
    );
  }

  // 하나 선택 — 목록
  if (field.type === 'choice' && constraints.display !== 'radio') {
    const picked = typeof value === 'object' && value !== null ? value.value : value;
    const shown = picked ?? field.default ?? '';
    const selectOptions = [
      ...(field.required && shown !== '' ? [] : [{ value: '', label: field.placeholder ?? t('member.fields.select_placeholder') }]),
      ...options,
      ...(constraints.allow_other ? [{ value: MEMBER_FIELD_OTHER, label: t('member.fields.other') }] : []),
    ];

    return root(
      <>
        <Select
          name={fieldName}
          value={shown}
          options={selectOptions}
          disabled={locked}
          className={inputClass}
          onChange={(e: any) => {
            const next = e?.target?.value ?? '';
            emit(next === MEMBER_FIELD_OTHER ? { value: MEMBER_FIELD_OTHER, other: '' } : next);
          }}
          {...aria}
        />
        {picked === MEMBER_FIELD_OTHER && (
          <Input
            type="text"
            className={`${inputClass} mt-2`}
            value={value?.other ?? ''}
            disabled={locked}
            maxLength={255}
            aria-label={t('member.fields.other_input', { label })}
            placeholder={t('member.fields.other_placeholder')}
            onChange={(e: React.ChangeEvent<HTMLInputElement>) => emit({ value: MEMBER_FIELD_OTHER, other: e.target.value })}
          />
        )}
      </>,
    );
  }

  // 하나 선택 — 라디오 묶음
  if (field.type === 'choice') {
    const picked = typeof value === 'object' && value !== null ? value.value : value ?? field.default ?? '';
    const radioOptions = constraints.allow_other
      ? [...options, { value: MEMBER_FIELD_OTHER, label: t('member.fields.other') }]
      : options;

    return root(
      <>
        <Div role="radiogroup" className={optionGroupClass} {...aria}>
          {radioOptions.map((option) => (
            <Label key={option.value} className={OPTION_ROW_CLASS}>
              <Input
                type="radio"
                name={`${generatedId}-${fieldName}`}
                className={CHECK_CLASS.replace('rounded ', 'rounded-full ')}
                checked={picked === option.value}
                disabled={locked}
                onChange={() => emit(option.value === MEMBER_FIELD_OTHER ? { value: MEMBER_FIELD_OTHER, other: '' } : option.value)}
              />
              <Span className="min-w-0 break-words">{option.label}</Span>
            </Label>
          ))}
        </Div>
        {picked === MEMBER_FIELD_OTHER && (
          <Input
            type="text"
            className={`${inputClass} mt-2`}
            value={value?.other ?? ''}
            disabled={locked}
            maxLength={255}
            aria-label={t('member.fields.other_input', { label })}
            placeholder={t('member.fields.other_placeholder')}
            onChange={(e: React.ChangeEvent<HTMLInputElement>) => emit({ value: MEMBER_FIELD_OTHER, other: e.target.value })}
          />
        )}
      </>,
    );
  }

  // 여러 개 선택
  if (field.type === 'multi_choice') {
    const picked: string[] = Array.isArray(value) ? value.map(String) : [];

    // 「목록」 표시는 테두리 안에서 세로로 훑는 목록, 「체크박스」 표시는 옆으로 흐르는 묶음이다.
    const asList = constraints.display === 'select';

    return root(
      <Div
        role="group"
        className={
          asList
            ? `flex max-h-48 flex-col overflow-y-auto rounded-lg border px-3 ${hasError ? 'border-red-500 dark:border-red-500' : 'border-gray-300 dark:border-gray-600'}`
            : optionGroupClass
        }
        {...aria}
      >
        {options.map((option) => (
          <Label key={option.value} className={OPTION_ROW_CLASS}>
            <Checkbox
              className={CHECK_CLASS}
              checked={picked.includes(option.value)}
              disabled={locked}
              onChange={(e: React.ChangeEvent<HTMLInputElement>) =>
                emit(e.target.checked ? [...picked, option.value] : picked.filter((item) => item !== option.value))
              }
            />
            <Span className="min-w-0 break-words">{option.label}</Span>
          </Label>
        ))}
      </Div>,
    );
  }

  // 예/아니오
  if (field.type === 'boolean') {
    const checked = value === true || value === 1 || value === '1' || value === 'true' || (value == null && field.default === true);
    const isSwitch = constraints.widget !== 'checkbox';

    return root(
      <Label className={OPTION_ROW_CLASS}>
        <Checkbox
          name={fieldName}
          role={isSwitch ? 'switch' : undefined}
          aria-checked={isSwitch ? checked : undefined}
          className={CHECK_CLASS}
          checked={checked}
          disabled={locked}
          onChange={(e: React.ChangeEvent<HTMLInputElement>) => emit(e.target.checked)}
          {...aria}
        />
        <Span className="min-w-0 break-words">{constraints.inline_label ?? label}</Span>
      </Label>,
    );
  }

  // 기간
  if (field.type === 'date_range') {
    const range = typeof value === 'object' && value !== null ? value : {};
    const min = resolveDateBound(constraints.min, 'min');
    const max = resolveDateBound(constraints.max, 'max');

    return root(
      <Div role="group" className="flex items-center gap-2" aria-label={label} aria-invalid={hasError ? true : undefined}>
        <Input
          type="date"
          className={`${inputClass} min-w-0`}
          value={range.start ?? ''}
          disabled={locked}
          min={min}
          max={range.end || max}
          aria-label={t('member.fields.range_start', { label })}
          onChange={(e: React.ChangeEvent<HTMLInputElement>) => emit({ start: e.target.value, end: range.end ?? '' })}
        />
        <Span className="shrink-0 text-gray-500 dark:text-gray-400">~</Span>
        <Input
          type="date"
          className={`${inputClass} min-w-0`}
          value={range.end ?? ''}
          disabled={locked}
          min={range.start || min}
          max={max}
          aria-label={t('member.fields.range_end', { label })}
          onChange={(e: React.ChangeEvent<HTMLInputElement>) => emit({ start: range.start ?? '', end: e.target.value })}
        />
      </Div>,
    );
  }

  // 여러 줄 글
  if (field.type === 'textarea') {
    return root(
      <Textarea
        name={fieldName}
        className={inputClass}
        rows={Number(constraints.rows) > 0 ? Number(constraints.rows) : 4}
        value={value ?? ''}
        placeholder={field.placeholder ?? undefined}
        maxLength={constraints.max_length ?? undefined}
        disabled={disabled}
        readOnly={field.readonly === true}
        required={field.required === true}
        onChange={(e: React.ChangeEvent<HTMLTextAreaElement>) => emit(e.target.value)}
        {...aria}
      />,
    );
  }

  // 한 줄 입력 (글, 전화, 이메일, URL, 숫자, 날짜, 날짜+시간, 모르는 유형)
  const htmlType = HTML_INPUT_TYPES[field.type] ?? 'text';
  const isDate = field.type === 'date' || field.type === 'datetime';
  const decimals = Number(constraints.decimals) > 0 ? Number(constraints.decimals) : 0;
  const input = (
    <Input
      type={htmlType}
      name={fieldName}
      className={field.type === 'number' && constraints.unit ? `${inputClass} min-w-0` : inputClass}
      value={value ?? ''}
      placeholder={field.placeholder ?? undefined}
      maxLength={isDate || field.type === 'number' ? undefined : constraints.max_length ?? undefined}
      min={isDate ? resolveDateBound(constraints.min, 'min', field.type === 'datetime') : field.type === 'number' ? constraints.min ?? undefined : undefined}
      max={isDate ? resolveDateBound(constraints.max, 'max', field.type === 'datetime') : field.type === 'number' ? constraints.max ?? undefined : undefined}
      step={field.type === 'number' ? (decimals > 0 ? String(1 / 10 ** decimals) : '1') : undefined}
      inputMode={field.type === 'number' ? (decimals > 0 ? 'decimal' : 'numeric') : undefined}
      disabled={disabled}
      readOnly={field.readonly === true}
      required={field.required === true}
      onChange={(e: React.ChangeEvent<HTMLInputElement>) => emit(e.target.value)}
      {...aria}
    />
  );

  if (field.type === 'number' && constraints.unit) {
    return root(
      <Div className="flex items-center gap-2">
        {input}
        <Span className="shrink-0 text-sm text-gray-600 dark:text-gray-400">{String(constraints.unit)}</Span>
      </Div>,
    );
  }

  return root(input);
};

export default MemberFieldInput;
