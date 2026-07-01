// e2e:allow 레시피 파라미터 picker 위젯 — i18n-text 메시지 칸의 데이터 칩/표현식 분해 트리는 칩 입력기·합성 클릭·contentEditable 의존으로 Playwright 부적합. 단위(property-controls.test/prop-i18n-text-field.test) + Chrome MCP 라이브 매트릭스로 검증
/**
 * RecipePickerControls.tsx — 레시피 파라미터 picker 위젯
 *
 * 액션/조건 레시피의 파라미터 입력 위젯:
 *  - `page-picker`       : 라우트 선택(후보 목록) + 자유 path 입력 폴백.
 *  - `datasource-picker` : 데이터소스 id 선택(후보) + 자유 입력 폴백.
 *  - `state-key-picker`  : 화면 상태 키 선택(후보) + 자유 입력 폴백.
 *  - `i18n-text`         : 메시지/라벨 텍스트 입력(토스트 메시지 등).
 *  - `text`              : 범용 자유 텍스트.
 *
 * 후보 목록(`candidates`)이 있으면 select + "직접 입력" 항목, 없으면 자유 텍스트만
 * 렌더한다. 코어 위젯은 `g7le-*` BEM + 인라인 스타일만 사용한다(메모리
 * feedback_layout_editor_no_css_lib_dependency).
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import type { WidgetProps } from '../../spec/widgetRegistry';
import { I18nTextField } from './I18nTextField';
import { DataChipValueInput } from '../page-settings/DataChipValueInput';
import type { BindingCandidate } from '../../spec/bindingCandidates';

/** "직접 입력" 센티넬 — select 에서 자유 입력 모드로 전환 */
const CUSTOM = '__g7le_custom__';

/** 후보 목록 + 자유 입력 폴백 공통 picker */
function CandidatePicker({
  value,
  onChange,
  candidates,
  placeholder,
  testid,
  t,
  bindingCandidates,
}: {
  value: unknown;
  onChange: (v: unknown) => void;
  candidates: Array<{ value: string; label: string }> | undefined;
  placeholder: string;
  testid: string;
  t: WidgetProps['t'];
  /**
   * 자유 입력 폴백에 데이터칩·표현식을 닿게 할 후보 풀. 라우트/데이터소스/
   * 상태키 자리에도 동적 경로(`{{query.tab}}`)·표현식을 쓸 수 있다. 미전달 시 평문 입력만.
   */
  bindingCandidates?: BindingCandidate[];
}): React.ReactElement {
  const current = value === undefined || value === null ? '' : String(value);
  const hasCandidates = Array.isArray(candidates) && candidates.length > 0;
  const matchesCandidate = hasCandidates && candidates!.some((c) => c.value === current);
  // 후보에 없는 비어있지 않은 값 → 자유 입력 모드 강제
  const [customMode, setCustomMode] = React.useState<boolean>(
    !hasCandidates || (current !== '' && !matchesCandidate),
  );

  React.useEffect(() => {
    if (current !== '' && hasCandidates && !matchesCandidate) setCustomMode(true);
  }, [current, hasCandidates, matchesCandidate]);

  if (hasCandidates && !customMode) {
    // 목록 선택 모드 — select + [✎] 명시 버튼으로 텍스트/표현식 편집 복귀(
    // 종전 복귀가 드롭다운 "직접 입력" 옵션 하나뿐이라 발견 불가). 양방향 토글.
    return (
      <div style={customWrap}>
        <select
          className="g7le-widget g7le-widget--picker"
          data-testid={testid}
          value={matchesCandidate ? current : ''}
          onChange={(e) => {
            const v = e.target.value;
            if (v === CUSTOM) {
              setCustomMode(true);
              return;
            }
            onChange(v === '' ? undefined : v);
          }}
          style={{ ...selectStyle, flex: 1, minWidth: 0 }}
        >
          <option value="">{t('layout_editor.control.default')}</option>
          {candidates!.map((c) => (
            <option key={c.value} value={c.value}>
              {c.label}
            </option>
          ))}
          <option value={CUSTOM}>{t('layout_editor.recipe.custom_input')}</option>
        </select>
        <button
          type="button"
          data-testid={`${testid}-to-custom`}
          title={t('layout_editor.recipe.custom_input')}
          aria-label={t('layout_editor.recipe.custom_input')}
          onClick={() => setCustomMode(true)}
          style={pickBtn}
        >
          ✎
        </button>
      </div>
    );
  }

  // 자유 입력(텍스트/데이터칩/표현식) 모드 — DataChipValueInput. [≡] 로 목록 선택 복귀(양방향).
  // customWrap = align-items flex-start: ≡ 가 입력 첫 줄에 정렬(DataChipValueInput 이 ƒx/?? 버튼을
  // 둘째 줄에 펼쳐도 ≡ 가 세로 중앙으로 떠 어긋나지 않게).
  return (
    <div style={customWrap}>
      <div style={{ flex: 1, minWidth: 0 }}>
        <DataChipValueInput
          value={current}
          onChange={(v) => onChange(v === '' ? undefined : v)}
          t={t}
          candidates={bindingCandidates}
          placeholder={placeholder}
          testidPrefix={`${testid}-input`}
        />
      </div>
      {hasCandidates && (
        <button
          type="button"
          data-testid={`${testid}-pick`}
          title={t('layout_editor.recipe.pick_from_list')}
          onClick={() => {
            setCustomMode(false);
            onChange(undefined);
          }}
          style={pickBtn}
        >
          ≡
        </button>
      )}
    </div>
  );
}

/** 라우트(페이지) 선택 위젯 */
export function PagePickerControl({ value, onChange, candidates, t, bindingCandidates }: WidgetProps): React.ReactElement {
  return (
    <CandidatePicker
      value={value}
      onChange={onChange}
      candidates={candidates}
      placeholder={t('layout_editor.recipe.page_placeholder')}
      testid="g7le-widget-page-picker"
      t={t}
      bindingCandidates={bindingCandidates}
    />
  );
}

/** 데이터소스 선택 위젯 */
export function DataSourcePickerControl({ value, onChange, candidates, t, bindingCandidates }: WidgetProps): React.ReactElement {
  return (
    <CandidatePicker
      value={value}
      onChange={onChange}
      candidates={candidates}
      placeholder={t('layout_editor.recipe.datasource_placeholder')}
      testid="g7le-widget-datasource-picker"
      t={t}
      bindingCandidates={bindingCandidates}
    />
  );
}

/** 화면 상태 키 선택 위젯 */
export function StateKeyPickerControl({ value, onChange, candidates, t, bindingCandidates }: WidgetProps): React.ReactElement {
  return (
    <CandidatePicker
      value={value}
      onChange={onChange}
      candidates={candidates}
      placeholder={t('layout_editor.recipe.state_key_placeholder')}
      testid="g7le-widget-state-key-picker"
      t={t}
      bindingCandidates={bindingCandidates}
    />
  );
}

/** 모달(레이아웃 modals) 선택 위젯 — openModal 대상. 후보 목록 + 자유 입력 폴백(동적 모달 id). */
export function ModalPickerControl({ value, onChange, candidates, t, bindingCandidates }: WidgetProps): React.ReactElement {
  return (
    <CandidatePicker
      value={value}
      onChange={onChange}
      candidates={candidates}
      placeholder={t('layout_editor.recipe.modal_placeholder')}
      testid="g7le-widget-modal-picker"
      t={t}
      bindingCandidates={bindingCandidates}
    />
  );
}

/**
 * i18n 텍스트(메시지/라벨) 입력 위젯.
 *
 * 레시피 파라미터(토스트/알림 메시지 등 사용자 표시 prose)도 속성 패널의 다른
 * 텍스트와 **동일 공통 위젯 `I18nTextField`**(useCustomTranslation SSoT)로 다국어 자동화한다.
 * 평문 입력 → `$t:custom.*` 자동 생성, 🌐 ko/en/ja 일괄 편집, `{{...}}` 바인딩(동적 메시지)은
 * 읽기전용 디그레이드. (이전: bare input 으로 평문 그대로 저장 → 다국어 미연동.)
 *
 * 참고: propControl(apply=propValue) 경로의 i18n-text 는 `ControlRenderer` 가 더 앞단에서
 * `I18nTextField` 로 승격하므로 본 위젯 본체는 레시피 파라미터 디스패치에서만 도달한다.
 */
export function I18nTextControl({ value, onChange, t, bindingCandidates }: WidgetProps): React.ReactElement {
  const current = typeof value === 'string' ? value : value == null ? '' : String(value);
  return (
    <div className="g7le-widget g7le-widget--i18n-text" data-testid="g7le-widget-i18n-text" style={{ flex: 1, minWidth: 100, width: '100%' }}>
      <I18nTextField
        value={current}
        onChange={(token) => onChange(token === '' ? undefined : token)}
        t={t}
        placeholder={t('layout_editor.recipe.i18n_text_placeholder')}
        testidPrefix="g7le-widget-i18n-text-field"
        // 레시피 메시지 텍스트(토스트/알림)도 데이터
        // 칩 + 표현식 분해 트리(접힌 미리보기 + [수정]). 후보 풀은 ActionRecipeEditor 가 흘려보냄.
        candidates={bindingCandidates}
        enableExpressionTree
        expressionTreeCollapsible
      />
    </div>
  );
}

/**
 * 다중 데이터 바인딩 후보(2~N) 입력 위젯 — first_of(candidates) 등 가변 후보 param.
 *
 * 값은 쉼표 구분 단일 문자열(컴포넌트 경계 — ComputedForm 의 param 값은 문자열). 직접 만들기
 * firstOf(CustomComputedBuilder)와 동형 UX 다. 후보 목록(candidates)이 있으면 select 로 한 개씩
 * 골라 끝에 덧붙일 수 있고, 자유 입력도 허용한다. 엔진(buildComputedExpr)이 이 문자열을 `, ` 로
 * 쪼개 `{candidates[0]} ?? {candidates[1]} ?? …` 인덱스 체인으로 치환한다(first_of
 * 다중 후보 완전 구현).
 */
export function BindingListControl({ value, onChange, candidates, t }: WidgetProps): React.ReactElement {
  const current = value === undefined || value === null ? '' : String(value);
  const parts = current.split(',').map((s) => s.trim()).filter(Boolean);
  const hasCandidates = Array.isArray(candidates) && candidates.length > 0;

  const appendCandidate = (v: string): void => {
    if (!v) return;
    const next = [...parts, v];
    onChange(next.join(', '));
  };

  return (
    <div className="g7le-widget g7le-widget--binding-list" data-testid="g7le-widget-binding-list" style={{ display: 'flex', flexDirection: 'column', gap: 4, width: '100%' }}>
      <input
        type="text"
        data-testid="g7le-widget-binding-list-input"
        value={current}
        placeholder={t('layout_editor.recipe.datasource_placeholder')}
        onChange={(e) => onChange(e.target.value === '' ? undefined : e.target.value)}
        style={inputStyle}
      />
      {hasCandidates ? (
        <select
          className="g7le-widget g7le-widget--picker"
          data-testid="g7le-widget-binding-list-add"
          value=""
          onChange={(e) => {
            appendCandidate(e.target.value);
            e.target.value = '';
          }}
          style={{ ...selectStyle, minWidth: 0 }}
        >
          <option value="">{t('layout_editor.recipe.pick_from_list')}</option>
          {candidates!.map((c) => (
            <option key={c.value} value={c.value}>
              {c.label}
            </option>
          ))}
        </select>
      ) : null}
    </div>
  );
}

/** 범용 자유 텍스트 입력 위젯 */
export function TextControl({ value, onChange }: WidgetProps): React.ReactElement {
  const current = value === undefined || value === null ? '' : String(value);
  return (
    <input
      type="text"
      className="g7le-widget g7le-widget--text"
      data-testid="g7le-widget-text"
      value={current}
      onChange={(e) => onChange(e.target.value === '' ? undefined : e.target.value)}
      style={inputStyle}
    />
  );
}

const selectStyle: React.CSSProperties = { padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', minWidth: 140, width: '100%' };
const inputStyle: React.CSSProperties = { flex: 1, padding: '5px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, minWidth: 100, width: '100%' };
// align-items: flex-start — ≡/✎ 우측 버튼을 입력 첫 줄(top)에 맞춤(DataChipValueInput 이 ƒx/??
// 버튼을 둘째 줄에 펼쳐도 버튼이 세로 중앙으로 떠 어긋나지 않게)..
const customWrap: React.CSSProperties = { display: 'flex', gap: 4, alignItems: 'flex-start', width: '100%' };
const pickBtn: React.CSSProperties = { padding: '4px 8px', fontSize: 12, border: '1px solid #cbd5e1', borderRadius: 6, background: '#fff', color: '#64748b', cursor: 'pointer' };
