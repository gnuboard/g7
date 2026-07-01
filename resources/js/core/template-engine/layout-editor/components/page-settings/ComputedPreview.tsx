/**
 * ComputedPreview.tsx — 자동 계산 결과값 + 화면효과 미리보기
 *
 * computed 식 1건을 편집기 샘플 컨텍스트로 평가해 ① 결과값 + 타입(숫자/문자/예아니오/목록/
 * 묶음) 상시 표시, ② 평가 실패 시 같은 자리 에러 안내 전환(별도 모달 금지). UI 효과형
 * (P2 읽기전용/표시숨김 등 boolean)은 ③ [▷ 이 조건일 때 화면] 화면효과 토글로 캔버스에
 * 그 계산값을 켠 상태 미리보기 신호를 발사한다.
 *
 * 미리보기 = `evaluateComputedPreview` 순수 평가(신규 평가 파서 0, 운영 evaluator 재사용).
 * 평가 실패 ≠ 저장 차단 — 식은 보존하고 자리만 에러 안내로 바뀐다.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import {
  evaluateComputedPreview,
  type ComputedPreviewType,
} from '../../spec/computedRecipeEngine';
import type { BindingContext } from '../../DataBindingEngine';

/** 화면효과 토글 신호 이벤트 — 캔버스가 수신해 그 계산값 켠 상태 미리보기 */
export const COMPUTED_EFFECT_PREVIEW_EVENT = 'g7le:computed-effect-preview';

export interface ComputedPreviewProps {
  /** 미리보기 대상 computed 식(`{{ }}` 포함 가능) */
  expr: string;
  /** computed 키(화면효과 토글 신호에 사용) */
  computedKey?: string;
  /** 편집기 샘플 평가 컨텍스트(useBindingCandidates 와 동일 풀) */
  sampleContext: BindingContext;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** UI 효과형(boolean 계산값) — 화면효과 토글 제공 */
  isEffect?: boolean;
  /** testid 접두 */
  testIdPrefix?: string;
}

/** 타입 라벨 키 */
const TYPE_LABEL: Record<ComputedPreviewType, string> = {
  number: 'layout_editor.computed.type_number',
  string: 'layout_editor.computed.type_string',
  boolean: 'layout_editor.computed.type_boolean',
  list: 'layout_editor.computed.type_list',
  object: 'layout_editor.computed.type_object',
  null: 'layout_editor.computed.type_null',
};

/** 평가값을 미리보기 문자열로 — 목록/묶음은 길이+요약 */
function formatValue(value: unknown, type: ComputedPreviewType, t: ComputedPreviewProps['t']): string {
  if (type === 'list') {
    const arr = value as unknown[];
    const head = arr.slice(0, 2).map((v) => JSON.stringify(v)).join(', ');
    return arr.length <= 2 ? `[${head}]` : `[${head}, ${t('layout_editor.computed.list_more', { count: arr.length })}]`;
  }
  if (type === 'object') return JSON.stringify(value);
  if (type === 'boolean') return value ? 'true' : 'false';
  if (type === 'null') return t('layout_editor.computed.value_none');
  return String(value);
}

/**
 * 자동 계산 미리보기.
 *
 * @param props ComputedPreviewProps
 * @return 미리보기 엘리먼트
 */
export function ComputedPreview({
  expr,
  computedKey,
  sampleContext,
  t,
  isEffect = false,
  testIdPrefix = 'g7le-computed-preview',
}: ComputedPreviewProps): React.ReactElement {
  const result = evaluateComputedPreview(expr, sampleContext);

  const fireEffectPreview = (): void => {
    if (typeof window === 'undefined' || !computedKey) return;
    window.dispatchEvent(
      new CustomEvent(COMPUTED_EFFECT_PREVIEW_EVENT, { detail: { key: computedKey } }),
    );
  };

  if (!result.ok) {
    return (
      <div data-testid={`${testIdPrefix}-error`} style={errorBox}>
        ⚠ {t('layout_editor.computed.preview_error')}
      </div>
    );
  }

  return (
    <div className={testIdPrefix} data-testid={testIdPrefix} style={wrap}>
      <span style={previewLabel}>{t('layout_editor.computed.preview_label')}</span>
      <span data-testid={`${testIdPrefix}-value`} style={previewValue}>
        {formatValue(result.value, result.type, t)}
      </span>
      <span data-testid={`${testIdPrefix}-type`} style={typeBadge}>
        ({t(TYPE_LABEL[result.type])})
      </span>
      {isEffect && result.type === 'boolean' ? (
        <button
          type="button"
          data-testid={`${testIdPrefix}-effect`}
          onClick={fireEffectPreview}
          style={effectBtn}
        >
          ▷ {t('layout_editor.computed.effect_preview')}
        </button>
      ) : null}
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', alignItems: 'center', flexWrap: 'wrap', gap: 6, minWidth: 0 };
const previewLabel: React.CSSProperties = { fontSize: 11, color: '#94a3b8' };
const previewValue: React.CSSProperties = { fontSize: 12, color: '#0f172a', fontFamily: 'monospace', wordBreak: 'break-all' };
const typeBadge: React.CSSProperties = { fontSize: 10, color: '#64748b' };
const effectBtn: React.CSSProperties = { padding: '2px 8px', fontSize: 11, border: '1px solid #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer' };
const errorBox: React.CSSProperties = { fontSize: 12, color: '#b45309' };
