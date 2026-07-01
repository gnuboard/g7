// E2E: tests/Playwright/specs/layout-editor/prop-i18n-text-field.spec.ts (부록7 7-b — i18n-text+propValue 승격 위젯).
/**
 * ControlRenderer.tsx — 컨트롤 1건 렌더 + 값 ↔ 패치 연결
 *
 * `control.widget` → `widgetRegistry` → 위젯 컴포넌트 디스패치. 위젯은 현재값
 * (`reverseResolve` 결과)을 표시하고, 변경 시 `applyRecipe()` → onPatch(노드 패치)
 * 를 호출한다(라이브 미리보기). 미등록 위젯은 "지원하지 않는 위젯" 폴백.
 *
 * 역해석이 실패하면(`matched:false`) 호출자(AdvancedPropsForm/스타일 탭)가 고급값
 * 배지를 표시한다 — 본 컴포넌트는 컨트롤이 매핑되면 매핑값을 보여주고, 매핑 안 되면
 * `기본`(미적용) 으로 표시(원칙 4.4 — 무손실 보존).
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import type { EditorControlSpec } from '../../spec/specTypes';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { BindingCandidate } from '../../spec/bindingCandidates';
import { getWidget } from '../../spec/widgetRegistry';
import { applyRecipe, reverseResolve } from '../../spec/recipeEngine';
import { BASE_SCOPE, isDarkEditable, type StyleScope } from '../../spec/styleScope';
import { I18nTextField } from './I18nTextField';
import { DataChipValueInput } from '../page-settings/DataChipValueInput';

export interface ControlRendererProps {
  /** 컨트롤 키 (`textAlign` 등) — 라벨 fallback 에 사용 */
  controlKey: string;
  /** 컨트롤 정의 */
  control: EditorControlSpec;
  /** 현재 편집 대상 노드 */
  node: EditorNode;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 값 변경 → 노드 패치 적용. 호출자가 PATCH_LAYOUT 으로 캔버스 반영 */
  onPatch: (patched: EditorNode) => void;
  /** tag-input 등 후보 목록 공급 */
  candidates?: Array<{ value: string; label: string }>;
  /**
   * 데이터 연결 검색 후보 풀 — i18n-text propControl(I18nTextField)의
   * `+데이터` 칩 삽입(키화)에 쓴다. 위 `candidates`(tag-input용 `{value,label}[]`)와 타입이
   * 달라 별도 prop. PropertyEditorModal 이 빌드해 주입(미전달 시 빈 검색 — 디그레이드).
   */
  bindingCandidates?: BindingCandidate[];
  /**
   * 활성 StyleScope (색 모드 × 디바이스). 기본 BASE_SCOPE = 라이트 × 공통.
   * scope≠base 면 reverseResolve/applyRecipe 가 해당 위치에 읽기/쓰기하고, 오버라이드가
   * 없으면 base 상속값을 placeholder(흐릿)로 표시한다(D6). 다크 scope 의 인라인 컨트롤은
   * 읽기전용(D4).
   */
  scope?: StyleScope;
}

/** 컨트롤 라벨 해석 — `$t:` 키면 t(), 평문이면 그대로, 미지정이면 controlKey */
function resolveLabel(controlKey: string, control: EditorControlSpec, t: ControlRendererProps['t']): string {
  const label = control.label;
  if (typeof label === 'string') {
    return label.startsWith('$t:') ? t(label.slice(3)) : label;
  }
  return controlKey;
}

export function ControlRenderer({
  controlKey,
  control,
  node,
  t,
  onPatch,
  candidates,
  bindingCandidates,
  scope = BASE_SCOPE,
}: ControlRendererProps): React.ReactElement {
  const Widget = getWidget(control.widget);
  const label = resolveLabel(controlKey, control, t);

  const resolution = React.useMemo(() => reverseResolve(node, control, scope), [node, control, scope]);

  // 부록7 7-a — 텍스트 propControl(i18n-text + propValue) 동적 다국어 승격. recipe 파라미터의
  // i18n-text(ActionRecipeEditor 경유, apply 없음)는 영향 없음 — 본 분기는 propValue 컨트롤만.
  // 위젯이 `$t:custom.*` 키를 생성/언어별 편집하고, 그 토큰 문자열을 propValue 로 기록한다.
  const isDynamicI18nProp =
    control.widget === 'i18n-text' &&
    (control.apply as { type?: string } | undefined)?.type === 'propValue';

  // 범용 `text`
  // 위젯이 컴포넌트 prop 값(propValue)을 편집할 때는 평문 input 대신 DataChipValueInput 로 승격
  // 한다. 데이터칩/표현식 분해로 `{{...}}`·`$core_settings:`·조건식을 시각화하되 **번역키(`$t:
  // custom.*`)는 만들지 않는다**(값 전용 — SEO 값칸과 동일 정책). 식별자 칸(요소 ID/dataKey)은
  // 전용 위젯(core-id/core-datakey)이라 본 분기에 닿지 않고, recipe/computed/condition 파라미터의
  // `text`(key/selector/event 등 식별자)는 apply 가 없어(propValue 아님) 제외된다 — 부작용 0.
  const isValueTextProp =
    control.widget === 'text' &&
    (control.apply as { type?: string } | undefined)?.type === 'propValue';

  // 다크 scope + 인라인(styleProp/cssVar/propValue) 컨트롤 → 읽기전용(D4, 무손실 보존).
  // resolution.darkReadonly 가 1차, control.apply 보유 시 isDarkEditable 로 보강.
  const darkReadonly =
    resolution.darkReadonly === true ||
    (scope.colorScheme === 'dark' && control.apply !== undefined && !isDarkEditable(control.apply as never));

  // 다크 scope + classToken **자유값**(tokenTemplate) 컨트롤(color/dimension) → 자유 입력만
  // 차단(프리셋 토큰은 다크 적용 가능). `dark:text-[#hex]` 같은 임의값 다크는 Tailwind safelist
  // 한계로 빌드 불가 → 자유값=라이트 전용. darkReadonly 가 아니면서
  // (=classToken 이라 프리셋은 다크 가능) control.apply 에 tokenTemplate 자유값 합성이 선언된 경우.
  const controlApply = control.apply as { type?: string; tokenTemplate?: string } | undefined;
  const freeValueDisabled =
    scope.colorScheme === 'dark' &&
    !darkReadonly &&
    controlApply?.type === 'classToken' &&
    typeof controlApply.tokenTemplate === 'string' &&
    controlApply.tokenTemplate.includes('{value}');

  // D6 placeholder — scope 자체 오버라이드가 없고 base 상속값만 있을 때 흐릿하게.
  const isPlaceholder = resolution.scopedValue === undefined && resolution.baseFallback !== undefined;

  const handleChange = React.useCallback(
    (value: unknown) => {
      if (darkReadonly) return; // 읽기전용 — 패치 미발생
      const patched = applyRecipe(node, control, value, scope);
      onPatch(patched);
    },
    [node, control, onPatch, scope, darkReadonly],
  );

  return (
    <div className="g7le-control-row" data-testid={`g7le-control-${controlKey}`} style={row}>
      <span className="g7le-control-label" style={labelStyle}>
        {label}
        {resolution.conflict && (
          <span data-testid={`g7le-control-conflict-${controlKey}`} title={t('layout_editor.property_modal.value_conflict')} style={conflictBadge}>
            !
          </span>
        )}
      </span>
      <div
        className="g7le-control-widget"
        style={{ flex: 1, minWidth: 0, opacity: isPlaceholder ? 0.45 : 1 }}
        data-placeholder={isPlaceholder ? 'true' : undefined}
      >
        {isDynamicI18nProp ? (
          // 다국어 텍스트 prop 도 페이지 제목/설명과
          // 동일하게 표현식 분해 트리(접힌 미리보기 + [수정]) + 데이터 칩을 연다. 후보 풀은 모달이
          // 이미 흘려보냄(bindingCandidates). 평문/단일키/칩은 종전 경로 그대로(opt-in 게이트라 회귀 0),
          // 못 푸는 복잡식만 읽기전용(손상 0).
          <I18nTextField
            value={typeof resolution.value === 'string' ? resolution.value : ''}
            onChange={(v) => handleChange(v)}
            t={t}
            testidPrefix={`g7le-prop-i18n-${controlKey}`}
            candidates={bindingCandidates}
            enableExpressionTree
            expressionTreeCollapsible
          />
        ) : isValueTextProp ? (
          // 범용 text
          // prop 값은 칩/표현식 분해로 시각화하되 번역키를 만들지 않는다(값 전용). undefined 전달 시
          // "기본/미적용" 해석을 유지하기 위해 빈 문자열은 undefined 로 환원해 handleChange 에 넘긴다.
          <DataChipValueInput
            value={typeof resolution.value === 'string' ? resolution.value : ''}
            onChange={(v: string) => handleChange(v === '' ? undefined : v)}
            t={t}
            testidPrefix={`g7le-prop-value-${controlKey}`}
            candidates={bindingCandidates}
          />
        ) : darkReadonly ? (
          <span
            data-testid={`g7le-control-dark-readonly-${controlKey}`}
            style={darkReadonlyStyle}
          >
            {t('layout_editor.property_modal.dark_code_only')}
          </span>
        ) : Widget ? (
          <Widget control={control} value={resolution.value} onChange={handleChange} t={t} candidates={candidates} bindingCandidates={bindingCandidates} freeValueDisabled={freeValueDisabled} />
        ) : (
          <span data-testid={`g7le-control-unsupported-${controlKey}`} style={unsupported}>
            {t('layout_editor.property_modal.unsupported_widget')}
          </span>
        )}
      </div>
    </div>
  );
}

// minWidth:0 — 컨트롤 행이 본문 폭 안에서 줄어들 수 있게 해, 위젯(옵션 목록·다국어 펼침 폼 등)의
// min-content 가 행을 본문보다 넓게 밀어 가로 스크롤을 만드는 것을 근절.
const row: React.CSSProperties = { display: 'flex', alignItems: 'center', gap: 12, padding: '6px 0', minHeight: 32, minWidth: 0 };
const labelStyle: React.CSSProperties = { fontSize: 12, color: '#475569', minWidth: 88, display: 'inline-flex', alignItems: 'center', gap: 4 };
const conflictBadge: React.CSSProperties = { display: 'inline-flex', width: 14, height: 14, borderRadius: 7, background: '#f59e0b', color: '#fff', fontSize: 10, alignItems: 'center', justifyContent: 'center', fontWeight: 700 };
const unsupported: React.CSSProperties = { fontSize: 11, color: '#94a3b8', fontStyle: 'italic' };
const darkReadonlyStyle: React.CSSProperties = { fontSize: 11, color: '#94a3b8', fontStyle: 'italic' };
