/**
 * JsonBlockField.tsx — 임의 JSON 값 텍스트 편집 공용 부품
 *
 * "임의 JSON 값(스칼라/객체/배열/중첩)을 monospace 텍스트로 안전하게 편집"하는 단일 구현.
 * 종전엔 같은 로직(JSON.stringify 시드 → textarea → JSON.parse 검증 → 오류 표시)이 코어 곳곳에
 * 독립 복제돼 있었다(DataSourcesPanel params 의 "코드로" 토글, InjectedPropsSection, 초기 상태
 * 탭 추가 예정분). 이 부품으로 통합해 한 곳만 고치면 모두 반영되게 한다(
 * "최대한 범용성").
 *
 * 동작:
 *  - 외부 `value` 가 바뀌면 텍스트를 재시드한다(편집 중이 아닐 때 — 외부 갱신 반영). 사용자가
 *    타이핑한 텍스트는 그대로 보존(매 keystroke 재시드로 커서가 튀지 않게).
 *  - 입력마다 `JSON.parse` 시도 → 성공이면 (선택) shape 가드 통과 시 `onChange(parsed)` +
 *    `onValidityChange(true)`. 실패/가드 위반이면 그 자리 빨간 오류 표시 + `onValidityChange(false)`
 *    (값은 흘리지 않음 → 호스트가 validity 로 저장을 차단). 빈 문자열은 `emptyValue`(기본 undefined)로.
 *  - 외부에 끌어쓸 라이브러리(monaco/CodeMirror) 없이 표준 `<textarea>` — 편집기 코어 CSS
 *    라이브러리 비종속 규율(g7le-* + inline style)을 지킨다.
 *
 * @since engine-v1.50.0
 */

import React, { useEffect, useRef, useState } from 'react';

/** 허용 값 모양 — 'any'(전타입) | 'object'(객체만) | 'object-or-array'(객체/배열) */
export type JsonShape = 'any' | 'object' | 'object-or-array';

export interface JsonBlockFieldProps {
  /** 현재 값(임의 JSON). 텍스트 시드 원본 */
  value: unknown;
  /** 유효 JSON 입력 시 파싱값을 흘린다(무효 입력은 호출 안 함) */
  onChange: (next: unknown) => void;
  /** 유효성 변화 콜백 — 호스트가 저장 차단 판단에 쓴다(무효=false) */
  onValidityChange?: (valid: boolean) => void;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 허용 값 모양(기본 'any') — 위반 시 무효 처리 */
  shape?: JsonShape;
  /** 빈 문자열일 때의 값(기본 undefined). object 가드면 호스트가 빈 객체로 보고 싶을 수 있다 */
  emptyValue?: unknown;
  /** placeholder(미입력 시 예시) */
  placeholder?: string;
  /** textarea 최소 높이(px, 기본 80) */
  minHeight?: number;
  /** data-testid 접두 — `{prefix}`(textarea) / `{prefix}-error`(오류) */
  testidPrefix?: string;
  /** 모양 위반 시 오류 메시지(미지정 시 기본 JSON 오류 문구) */
  shapeErrorKey?: string;
  /** 잘못된 JSON 문법 오류 메시지 키(미지정 시 `layout_editor.json_block.invalid`) */
  invalidErrorKey?: string;
  /** 접근성 라벨(htmlFor 연결 대신) */
  ariaLabel?: string;
  /** textarea id(label htmlFor 연결용) */
  id?: string;
  /**
   * shape 통과 후 호스트별 추가 검증(예: 객체 키가 유효 식별자인지). 오류 메시지(이미 해석된
   * 문자열)를 반환하면 무효 처리(저장 차단), null 이면 통과. 미지정 시 추가 검증 없음.
   */
  validate?: (parsed: unknown) => string | null;
}

/** 파싱값이 shape 가드를 만족하는지 */
function passesShape(parsed: unknown, shape: JsonShape): boolean {
  if (shape === 'any') return true;
  if (parsed === null || typeof parsed !== 'object') return false;
  if (shape === 'object') return !Array.isArray(parsed);
  return true; // object-or-array — 배열/객체 모두 허용(typeof object + null 제외 위에서 처리)
}

/**
 * 임의 JSON 값 텍스트 편집 공용 부품.
 *
 * @param props JsonBlockFieldProps
 * @return JSON 텍스트 편집 엘리먼트
 */
export function JsonBlockField({
  value,
  onChange,
  onValidityChange,
  t,
  shape = 'any',
  emptyValue = undefined,
  placeholder,
  minHeight = 80,
  testidPrefix = 'g7le-json-block',
  shapeErrorKey,
  invalidErrorKey,
  ariaLabel,
  id,
  validate,
}: JsonBlockFieldProps): React.ReactElement {
  // 텍스트는 로컬 상태 — 매 keystroke 외부 value 재시드로 커서가 튀지 않게 분리.
  const [text, setText] = useState<string>(() => seedText(value));
  const [error, setError] = useState<string | null>(null);
  // 사용자가 편집 중인지 — 편집 중엔 외부 value 변화로 텍스트를 덮지 않는다(타이핑 보존).
  const editingRef = useRef(false);
  // 직전에 흘린 유효 값(외부 재시드 판정용) — 우리가 onChange 로 흘린 값이 되돌아오면 재시드 생략.
  const lastEmittedRef = useRef<unknown>(value);

  useEffect(() => {
    // 외부 value 가 바뀌었고(우리가 흘린 값이 아니고) 편집 중이 아니면 재시드.
    if (editingRef.current) return;
    if (Object.is(value, lastEmittedRef.current)) return;
    if (seedText(value) === seedText(lastEmittedRef.current)) {
      lastEmittedRef.current = value;
      return;
    }
    setText(seedText(value));
    setError(null);
    lastEmittedRef.current = value;
    onValidityChange?.(true);
    // onChange/onValidityChange 는 의도적으로 deps 제외(콜백 변동으로 재시드 방지).
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [value]);

  const handleChange = (raw: string): void => {
    editingRef.current = true;
    setText(raw);
    if (raw.trim() === '') {
      setError(null);
      lastEmittedRef.current = emptyValue;
      onValidityChange?.(true);
      onChange(emptyValue);
      return;
    }
    let parsed: unknown;
    try {
      parsed = JSON.parse(raw);
    } catch {
      setError(t(invalidErrorKey ?? 'layout_editor.json_block.invalid'));
      onValidityChange?.(false);
      return;
    }
    if (!passesShape(parsed, shape)) {
      setError(t(shapeErrorKey ?? 'layout_editor.json_block.invalid'));
      onValidityChange?.(false);
      return;
    }
    // 호스트별 추가 검증(키 식별자 규칙 등) — 오류 메시지 반환 시 무효 처리.
    const hostError = validate?.(parsed) ?? null;
    if (hostError) {
      setError(hostError);
      onValidityChange?.(false);
      return;
    }
    setError(null);
    lastEmittedRef.current = parsed;
    onValidityChange?.(true);
    onChange(parsed);
  };

  return (
    <div className="g7le-json-block" style={wrap}>
      <textarea
        id={id}
        data-testid={testidPrefix}
        style={{ ...area, minHeight }}
        value={text}
        spellCheck={false}
        aria-label={ariaLabel}
        aria-invalid={error ? true : undefined}
        placeholder={placeholder}
        onChange={(e) => handleChange(e.target.value)}
        onBlur={() => {
          editingRef.current = false;
        }}
      />
      {error ? (
        <div data-testid={`${testidPrefix}-error`} style={errorStyle}>
          {error}
        </div>
      ) : null}
    </div>
  );
}

/** 값 → 텍스트 시드(undefined/빈 → 빈 문자열, 그 외 pretty JSON, 직렬화 불가 시 빈 문자열) */
function seedText(value: unknown): string {
  if (value === undefined) return '';
  try {
    return JSON.stringify(value, null, 2) ?? '';
  } catch {
    return '';
  }
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, minWidth: 0 };
const area: React.CSSProperties = {
  width: '100%',
  boxSizing: 'border-box',
  fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace',
  fontSize: 12,
  lineHeight: 1.5,
  color: '#0f172a',
  // 배경을 흰색으로 명시(투명 금지) — 다크 컨텍스트 안에서 쓰여도 검은 글자가 묻히지 않게.
  // 공용 부품이라 어느 진입점에서든 컨텍스트 독립적으로 가독성을 보장한다([[feedback_editor_inline_color_fallback_masks_classtoken]] 정신).
  background: '#fff',
  border: '1px solid #cbd5e1',
  borderRadius: 6,
  padding: '6px 8px',
  resize: 'vertical',
};
const errorStyle: React.CSSProperties = { fontSize: 11, color: '#dc2626' };
