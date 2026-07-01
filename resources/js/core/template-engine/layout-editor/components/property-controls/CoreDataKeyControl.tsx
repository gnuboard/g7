/**
 * CoreDataKeyControl.tsx — 코어 제공 "폼 데이터 연결점(dataKey)" 위젯
 *
 * 폼 컨테이너(Form/Div/Container 류) 노드의 `dataKey` 를 편집하는 코어 위젯. `dataKey:"form"`
 * 이면 그 컨테이너 자식 입력들이 `_local.form.*` 에 자동 바인딩된다(DynamicRenderer 폼 자동
 * 바인딩). `dataKey` 는 **노드 최상위 구조키**(props 아님)라 coreProps 가 `nodeKey` apply 로
 * 노드 최상위에 패치한다([[노드 구조키는 최상위]]).
 *
 * CoreIdControl 과 동형 안전장치:
 *  - **바인딩 가드**: 현재 값이 `{{...}}` 면 자유 편집을 막고 "바인딩됨(코드 편집)" 디그레이드.
 *  - **접두 힌트**: `_global.`/`_isolated.` 접두로 전역/격리 스코프 바인딩 가능함을 안내.
 *  - 빈 값이면 노드 키 삭제(위젯 규약 — onChange(undefined)).
 *
 * id 와 달리 HTML 안전 문자 sanitize 는 하지 않는다 — dataKey 는 `_global.formData` 처럼 점
 * 경로를 가질 수 있다(점/언더스코어 허용). 단 공백·`{`·`}` 등 식별자 비허용 문자만 제거.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import type { WidgetProps } from '../../spec/widgetRegistry';

/** dataKey 식별자 비허용 문자 — 영문자/숫자/`.`/`-`/`_` 외 제거(점 경로 허용) */
const UNSAFE_DATAKEY_CHARS = /[^A-Za-z0-9.\-_]/g;

/** 값이 데이터바인딩(`{{...}}`) 표현식을 포함하는지 */
function isBindingValue(value: unknown): boolean {
  return typeof value === 'string' && /\{\{[\s\S]*?\}\}/.test(value);
}

/**
 * dataKey 입력에서 식별자 안전 문자만 남긴다(점 경로 허용).
 *
 * @param raw 사용자 입력 원문
 * @returns 비허용 문자 제거된 dataKey 문자열
 */
export function sanitizeDataKey(raw: string): string {
  return raw.replace(UNSAFE_DATAKEY_CHARS, '');
}

const inputStyle: React.CSSProperties = {
  width: '100%',
  padding: '6px 8px',
  fontSize: 12,
  border: '1px solid #cbd5e1',
  borderRadius: 4,
  fontFamily: 'monospace',
  boxSizing: 'border-box',
};

const degradedStyle: React.CSSProperties = {
  ...inputStyle,
  background: '#f1f5f9',
  color: '#64748b',
  cursor: 'not-allowed',
};

const hintStyle: React.CSSProperties = {
  marginTop: 4,
  fontSize: 11,
  color: '#94a3b8',
};

/**
 * 코어 제공 dataKey 위젯.
 *
 * @param props WidgetProps — value(현재 dataKey), onChange, t, control
 * @return dataKey 위젯 엘리먼트
 */
export function CoreDataKeyControl({ value, onChange, t }: WidgetProps): React.ReactElement {
  const current = value === undefined || value === null ? '' : String(value);
  const bound = isBindingValue(value);

  if (bound) {
    return (
      <div data-testid="g7le-core-datakey-bound">
        <input
          type="text"
          className="g7le-widget g7le-widget--core-datakey-bound"
          data-testid="g7le-widget-core-datakey"
          value={current}
          readOnly
          disabled
          style={degradedStyle}
        />
        <div style={hintStyle}>{t('layout_editor.core_props.dataKey.bound_degraded')}</div>
      </div>
    );
  }

  return (
    <div>
      <input
        type="text"
        className="g7le-widget g7le-widget--core-datakey"
        data-testid="g7le-widget-core-datakey"
        value={current}
        placeholder={t('layout_editor.core_props.dataKey.placeholder')}
        onChange={(e) => {
          const safe = sanitizeDataKey(e.target.value);
          onChange(safe === '' ? undefined : safe);
        }}
        spellCheck={false}
        autoComplete="off"
        style={inputStyle}
      />
      <div style={hintStyle}>{t('layout_editor.core_props.dataKey.hint')}</div>
    </div>
  );
}
