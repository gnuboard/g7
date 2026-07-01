// e2e:allow 옵션 라벨 위젯 — `+데이터` 칩 삽입(키화) 후보 풀 결선. 칩 입력기·합성 클릭 의존으로 Playwright 부적합, 단위(OptionsListControl.test)+Chrome MCP 라이브 매트릭스로 검증 (계획 정책)
/**
 * OptionsListControl.tsx — `options-list` 위젯
 *
 * 컴포넌트의 `options` 배열 prop(`[{ value, label }]`)을 항목 단위로 편집한다.
 * 값은 옵션 객체 배열(propValue 로 `node.props.options` 에 기록 — recipeEngine).
 * 각 행: value 입력(식별자) + **label = 공통 다국어 위젯 `I18nTextField`** + 위/아래 이동 + 삭제.
 *
 * **옵션 라벨 다국어**: label 은 사용자에게 보이는 선택지 텍스트(번역
 * 대상)이므로 속성 패널의 다른 텍스트 propControl·children 항목·표 셀과 **동일 공통 위젯
 * `I18nTextField`**(useCustomTranslation SSoT)로 편집한다. 평문 입력 시 `$t:custom.*` 키가
 * 자동 생성되고, 🌐 로 ko/en/ja 일괄 편집, 기존 `$t:` 키는 raw 노출 없이 해석값 미리보기,
 * `{{...}}` 바인딩식은 읽기전용 디그레이드. (이전: bare input 으로 `$t:shop.sort.latest`
 * 같은 raw 키를 그대로 노출·평문 저장 → 다국어 미연동 결함.) **value 는 식별자라 평문 유지.**
 *
 * 정적-바인딩 가드: `props.options` 가 `{{...}}` 데이터바인딩 문자열이면
 * 편집 비대상 → "바인딩됨(코드 편집)" 디그레이드 표시(덮어쓰기 위험 차단,
 * 메모리 feedback_dont_modify_stable_infrastructure_without_evidence 정합).
 *
 * 코어 위젯은 `g7le-*` BEM + 인라인 스타일만 사용한다.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import type { WidgetProps } from '../../spec/widgetRegistry';
import { I18nTextField } from './I18nTextField';

/** 옵션 1건 — value 필수, label 선택 */
interface OptionItem {
  value: unknown;
  label?: unknown;
  [key: string]: unknown;
}

/** value 가 정적 옵션 배열인지 (각 항목이 객체이고 value 키 보유) */
function isStaticOptionArray(value: unknown): value is OptionItem[] {
  return (
    Array.isArray(value) &&
    value.every((it) => it !== null && typeof it === 'object' && !Array.isArray(it))
  );
}

/** value 가 데이터바인딩 표현식 문자열인지 (`{{...}}`) */
function isBindingExpression(value: unknown): boolean {
  return typeof value === 'string' && /\{\{.*\}\}/.test(value);
}

export function OptionsListControl({ value, onChange, t, bindingCandidates }: WidgetProps): React.ReactElement {
  // 바인딩 디그레이드 — 정적 편집 비대상(덮어쓰기 차단).
  if (isBindingExpression(value)) {
    return (
      <div
        className="g7le-widget g7le-widget--options-list"
        data-testid="g7le-widget-options-list-bound"
        style={boundHint}
      >
        {t('layout_editor.list_editor.bound_degraded')}
      </div>
    );
  }

  const items: OptionItem[] = isStaticOptionArray(value) ? value : [];

  const commit = (next: OptionItem[]): void => {
    onChange(next.length === 0 ? undefined : next);
  };

  const updateAt = (idx: number, patch: Partial<OptionItem>): void => {
    const next = items.map((it, i) => (i === idx ? { ...it, ...patch } : it));
    commit(next);
  };

  const removeAt = (idx: number): void => {
    commit(items.filter((_, i) => i !== idx));
  };

  const move = (idx: number, dir: -1 | 1): void => {
    const target = idx + dir;
    if (target < 0 || target >= items.length) return;
    const next = [...items];
    [next[idx], next[target]] = [next[target], next[idx]];
    commit(next);
  };

  const add = (): void => {
    commit([...items, { value: '', label: '' }]);
  };

  /** label 현재값(문자열) — I18nTextField 가 평문/`$t:` 키/바인딩을 분류·표시한다. */
  const labelValue = (it: OptionItem): string =>
    typeof it.label === 'string' ? it.label : it.label == null ? '' : String(it.label);
  const valueText = (it: OptionItem): string =>
    it.value === undefined || it.value === null ? '' : String(it.value);

  return (
    <div className="g7le-widget g7le-widget--options-list" data-testid="g7le-widget-options-list" style={wrap}>
      {items.length === 0 && (
        <div data-testid="g7le-options-empty" style={emptyHint}>
          {t('layout_editor.list_editor.empty')}
        </div>
      )}
      {items.map((it, idx) => (
        <div key={idx} data-testid={`g7le-options-row-${idx}`} style={row}>
          {/* 3줄 구조 — 좁은 속성 모달에서 value·label·🌐·↑↓✕ 를 한 줄에 넣으면
              겹침·가로 스크롤이 나고, 2줄(입력 1줄)에선 value/label 이 폭을 반씩 나눠 label 입력칸이
              48px 로 너무 좁았다(라이브 실측). value(식별자) 줄 / label(표시·번역 텍스트, +🌐) 줄 /
              액션 버튼 줄로 각 입력이 전폭을 쓰게 분리한다. */}
          <div style={inputLine}>
            <span style={fieldHint}>{t('layout_editor.list_editor.option_value')}</span>
            <input
              type="text"
              data-testid={`g7le-options-value-${idx}`}
              value={valueText(it)}
              placeholder={t('layout_editor.list_editor.option_value')}
              onChange={(e) => updateAt(idx, { value: e.target.value })}
              style={cellInput}
            />
          </div>
          {/* 라벨 = 공통 다국어 위젯(7-b). 평문 입력 → `$t:custom.*` 자동 생성 토큰을 label 에
              기록, 🌐 ko/en/ja 일괄 편집, 기존 키는 해석값 미리보기(raw 미노출). 전폭 줄. */}
          <div style={inputLine}>
            <span style={fieldHint}>{t('layout_editor.list_editor.option_label')}</span>
            <div data-testid={`g7le-options-label-${idx}`} style={{ flex: 1, minWidth: 0 }}>
              <I18nTextField
                value={labelValue(it)}
                onChange={(token) => updateAt(idx, { label: token ?? '' })}
                t={t}
                placeholder={t('layout_editor.list_editor.option_label')}
                testidPrefix={`g7le-options-label-i18n-${idx}`}
                // 옵션 라벨도 `+데이터` 칩 삽입(키화)에 후보 풀이 닿도록 전달.
                candidates={bindingCandidates}
                // 옵션 라벨도 표현식 분해 트리(접힌
                // 미리보기 + [수정]) + 데이터 칩. 평문/단일키/칩은 종전 경로(opt-in 게이트 회귀 0).
                enableExpressionTree
                expressionTreeCollapsible
              />
            </div>
          </div>
          {/* 액션 버튼 줄 — 입력 아래 별도 줄. 우측 정렬, 줄어들지 않아도 윗줄과 겹치지 않는다. */}
          <div style={actionLine}>
            <button
              type="button"
              data-testid={`g7le-options-up-${idx}`}
              title={t('layout_editor.list_editor.move_up')}
              disabled={idx === 0}
              onClick={() => move(idx, -1)}
              style={iconBtn}
            >
              ↑
            </button>
            <button
              type="button"
              data-testid={`g7le-options-down-${idx}`}
              title={t('layout_editor.list_editor.move_down')}
              disabled={idx === items.length - 1}
              onClick={() => move(idx, 1)}
              style={iconBtn}
            >
              ↓
            </button>
            <button
              type="button"
              data-testid={`g7le-options-remove-${idx}`}
              title={t('layout_editor.list_editor.remove')}
              onClick={() => removeAt(idx)}
              style={removeBtn}
            >
              ✕
            </button>
          </div>
        </div>
      ))}
      <button type="button" data-testid="g7le-options-add" onClick={add} style={addBtn}>
        {t('layout_editor.list_editor.add')}
      </button>
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 8, width: '100%' };
// 2줄 구조 — 행은 세로 컨테이너. 윗줄 입력 + 아랫줄 액션 버튼.
// 한 줄에 6요소를 가로로 욱여넣어 버튼이 겹치고 가로 스크롤이 나던 결함을 근절.
const row: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 4, width: '100%', minWidth: 0, paddingBottom: 6, borderBottom: '1px solid #f1f5f9' };
// 입력 줄(value / 다국어 label 각각 자체 줄) — 전폭. minWidth:0 으로 내부 입력이 줄어들 수 있게 해 넘침 차단.
const inputLine: React.CSSProperties = { display: 'flex', gap: 6, alignItems: 'center', width: '100%', minWidth: 0 };
// 필드 힌트(값/표시) — 좁은 고정폭 라벨. 스택된 입력칸 구분용(어느 줄이 값/표시인지).
const fieldHint: React.CSSProperties = { flexShrink: 0, width: 36, fontSize: 11, color: '#94a3b8', textAlign: 'right' };
// 액션 버튼 줄 — 입력 아래 별도 줄, 우측 정렬. 윗줄과 분리돼 겹치지 않는다.
const actionLine: React.CSSProperties = { display: 'flex', gap: 4, alignItems: 'center', justifyContent: 'flex-end' };
const cellInput: React.CSSProperties = { flex: 1, minWidth: 0, padding: '4px 6px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6 };
const iconBtn: React.CSSProperties = { padding: '2px 6px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer' };
const removeBtn: React.CSSProperties = { padding: '2px 6px', fontSize: 12, border: '1px solid #fecaca', borderRadius: 6, background: '#fff', color: '#dc2626', cursor: 'pointer' };
const addBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 12, border: '1px dashed #94a3b8', borderRadius: 6, background: '#fff', color: '#475569', cursor: 'pointer', alignSelf: 'flex-start' };
const emptyHint: React.CSSProperties = { fontSize: 11, color: '#94a3b8', fontStyle: 'italic' };
const boundHint: React.CSSProperties = { fontSize: 11, color: '#b45309', fontStyle: 'italic', padding: '4px 2px' };
