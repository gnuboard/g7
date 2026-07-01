/**
 * CoreIdControl.tsx — 코어 제공 "요소 ID" 위젯
 *
 * 모든 draggable 컴포넌트의 [속성] 탭 최상단에 코어가 일괄 제공하는 요소 id 편집 위젯.
 * 일반 text 위젯과 달리 두 가지 특성을 갖는다:
 *
 *  1. **데이터 칩 연동**: id 값에 `{{...}}` 데이터바인딩을 섞을 수 있다. iteration/sortable
 *     안에서 `id="item_{{$idx}}"` 처럼 반복 인덱스/행 키를 붙여 각 행마다 고유 DOM id 를
 *     만드는 용도(엔진 런타임이 보간 — DynamicRenderer.resolvedComponentId).
 *     칩이 포함되면 [BindingChipTextInput] 로 평문+칩 혼합 편집을 제공하고, 정적 id 면
 *     단순 input + [+데이터] 진입점(후보가 있을 때)으로 칩을 삽입할 수 있다.
 *
 *  2. **문자 sanitize(평문 세그먼트 한정)**: HTML id 안전 문자만 허용. 영문자/숫자/
 *     `-`/`_`/`:`/`.` 만 통과시키고 한글·공백 등 불허 문자는 입력 시 자동 제거한다.
 *     단 `{{...}}` 칩 토큰은 보존 — 평문 세그먼트에만 sanitize 를 적용한다(세그먼트 단위).
 *     CSS 셀렉터/`getElementById` 안전성 보장.
 *
 * 값은 표준 `node.props.id` 로만 흐른다(코어는 강제 DOM 주입 안 함 — 컴포넌트
 * passthrough 책임). 빈 값이면 prop 삭제(text 위젯 규약 — onChange(undefined)).
 *
 * @since engine-v1.50.0
 */

import React, { useState } from 'react';
import type { WidgetProps } from '../../spec/widgetRegistry';
import { splitInlineSegments, hasInlineBinding } from '../../spec/inlineBindingUtils';
import { BindingChipTextInput } from '../page-settings/BindingChipTextInput';

/** HTML id 안전 문자 — 영문자/숫자/하이픈/언더스코어/콜론/마침표 외 제거 */
const UNSAFE_ID_CHARS = /[^A-Za-z0-9\-_:.]/g;

/** 값이 데이터바인딩(`{{...}}`) 표현식을 포함하는지 */
function isBindingValue(value: unknown): boolean {
  return typeof value === 'string' && hasInlineBinding(value);
}

/**
 * 평문 세그먼트의 HTML id 안전 문자만 남긴다.
 *
 * `{{...}}` 바인딩 토큰은 그대로 보존하고, 그 사이/바깥의 평문(literal) 세그먼트에만
 * 안전문자 필터를 적용한다. 칩과 정적 id 가 섞인 `item_{{$idx}}` 같은 값에서 칩을
 * 보존하면서 평문 부분만 정리하기 위함.
 *
 * @param raw 사용자 입력 원문(평문 + 칩 혼합 가능)
 * @returns 칩 보존 + 평문 안전화된 id 문자열
 */
export function sanitizeElementId(raw: string): string {
  if (!hasInlineBinding(raw)) {
    return raw.replace(UNSAFE_ID_CHARS, '');
  }
  return splitInlineSegments(raw)
    .map((seg) => (seg.kind === 'binding' ? seg.raw : seg.raw.replace(UNSAFE_ID_CHARS, '')))
    .join('');
}

const inputStyle: React.CSSProperties = {
  width: '100%',
  padding: '6px 8px',
  fontSize: 12,
  border: '1px solid #cbd5e1',
  borderRadius: 4,
  fontFamily: 'monospace',
};

const hintStyle: React.CSSProperties = {
  marginTop: 4,
  fontSize: 11,
  color: '#94a3b8',
};

const rowStyle: React.CSSProperties = {
  display: 'flex',
  gap: 6,
  alignItems: 'center',
};

const insertBtnStyle: React.CSSProperties = {
  flexShrink: 0,
  padding: '5px 8px',
  fontSize: 11,
  border: '1px solid #cbd5e1',
  borderRadius: 4,
  background: '#f8fafc',
  cursor: 'pointer',
  whiteSpace: 'nowrap',
};

export function CoreIdControl({
  value,
  onChange,
  t,
  bindingCandidates,
}: WidgetProps): React.ReactElement {
  const current = value === undefined || value === null ? '' : String(value);
  const bound = isBindingValue(value);
  const hasCandidates = !!bindingCandidates && bindingCandidates.length > 0;

  // 정적 id 에서 [+데이터] 로 칩 편집 모드를 명시적으로 켰는지(칩 입력 진입).
  const [chipEditing, setChipEditing] = useState(false);

  /** 칩 입력 결과(평문+칩 혼합)를 세그먼트 sanitize 후 흘려보낸다. */
  const handleChipChange = (next: string): void => {
    const safe = sanitizeElementId(next);
    onChange(safe === '' ? undefined : safe);
  };

  // 칩 포함 또는 칩 편집 진입 상태 → 평문+칩 혼합 편집기.
  if (bound || chipEditing) {
    return (
      <div data-testid="g7le-core-id-chip">
        <BindingChipTextInput
          value={current}
          onChange={handleChipChange}
          t={t}
          candidates={bindingCandidates}
          onDone={() => setChipEditing(false)}
          testidPrefix="g7le-core-id-chip-input"
        />
        <div style={hintStyle}>{t('layout_editor.core_props.id.binding_hint')}</div>
      </div>
    );
  }

  // 정적 id — 단순 input + (후보 있을 때) [+데이터] 칩 진입점.
  return (
    <div>
      <div style={rowStyle}>
        <input
          type="text"
          className="g7le-widget g7le-widget--core-id"
          data-testid="g7le-widget-core-id"
          value={current}
          onChange={(e) => {
            const safe = sanitizeElementId(e.target.value);
            onChange(safe === '' ? undefined : safe);
          }}
          spellCheck={false}
          autoComplete="off"
          style={inputStyle}
        />
        {hasCandidates && (
          <button
            type="button"
            data-testid="g7le-core-id-add-data"
            onClick={() => setChipEditing(true)}
            style={insertBtnStyle}
            title={t('layout_editor.core_props.id.add_data')}
          >
            🔗 {t('layout_editor.core_props.id.add_data')}
          </button>
        )}
      </div>
    </div>
  );
}
