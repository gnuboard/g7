/**
 * ActionPreview.tsx — 액션 친화 요약 + 코드 보기
 *
 * 한 액션(JSON) 1건을 ① 친화 한 줄 요약(핸들러 스펙 라벨 + 입력값 합성, `summarizeAction`)
 * 으로 상시 표시하고, ② [</> 코드 보기] 토글로 `buildAction` 이 만들 실제 JSON(읽기전용)을
 * 펼친다. [화면 동작] 탭(InitActionsForm)과 컴포넌트 속성 [동작] 탭(ActionRecipeEditor)이
 * 공유한다(동일 수준).
 *
 * 미리보기 = `actionRecipeEngine` 순수 변환(샘플 평가 0-43). 필수 입력 누락 시 요약
 * 자리에 안내 문구로 전환한다(별도 모달 금지). 고급 보존 항목(matchAction 미매칭)도 코드
 * 열람만 제공한다.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React, { useState } from 'react';
import {
  summarizeAction,
  type NormalizedActionRecipe,
} from '../../spec/actionRecipeEngine';

export interface ActionPreviewProps {
  /** 미리보기 대상 액션 JSON */
  action: Record<string, unknown>;
  /** 매칭된 레시피(친화 요약용). null 이면 고급 보존(핸들러명 폴백) */
  recipe: Pick<NormalizedActionRecipe, 'label' | 'params'> | null;
  /** 친화 요약에 쓸 입력값(matchAction values) */
  values?: Record<string, unknown>;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 필수 입력이 누락됐는지 — 누락 시 요약 자리에 안내 전환 */
  missingRequired?: boolean;
  /**
   * [</> 코드 보기] 버튼 표시 여부(기본 true). 카드(ActionListBuilder)가 자체 카드 레벨 코드
   * 버튼을 그릴 때는 false 로 넘겨 중복 `</>` 를 없앤다.
   */
  showCodeButton?: boolean;
  /** testid 접두 */
  testIdPrefix?: string;
}

/** `$t:` 키면 t() 로 해석, 평문이면 그대로 */
function resolveLabel(t: ActionPreviewProps['t'], key: string): string {
  return key.startsWith('$t:') ? t(key.slice(3)) : key;
}

/**
 * 액션 친화 요약 + 코드 보기.
 *
 * @param props ActionPreviewProps
 * @return 미리보기 엘리먼트
 */
export function ActionPreview({
  action,
  recipe,
  values = {},
  t,
  missingRequired = false,
  showCodeButton = true,
  testIdPrefix = 'g7le-action-preview',
}: ActionPreviewProps): React.ReactElement {
  const [codeOpen, setCodeOpen] = useState(false);

  const summary = recipe
    ? summarizeAction(recipe, values, (k) => resolveLabel(t, k))
    : typeof action.handler === 'string'
      ? action.handler
      : t('layout_editor.action.advanced_action');

  return (
    <div className={testIdPrefix} data-testid={testIdPrefix} style={wrap}>
      {missingRequired ? (
        <span data-testid={`${testIdPrefix}-missing`} style={missing}>
          ⚠ {t('layout_editor.action.summary_missing_required')}
        </span>
      ) : (
        <span data-testid={`${testIdPrefix}-summary`} style={summaryText}>
          {summary}
        </span>
      )}
      {showCodeButton ? (
        <button
          type="button"
          data-testid={`${testIdPrefix}-code-toggle`}
          onClick={() => setCodeOpen((v) => !v)}
          style={codeBtn}
          aria-expanded={codeOpen}
          aria-label={t('layout_editor.action.view_code')}
        >
          {'</>'}
        </button>
      ) : null}
      {showCodeButton && codeOpen ? (
        <pre data-testid={`${testIdPrefix}-code`} style={codeView}>
          {JSON.stringify(action, null, 2)}
        </pre>
      ) : null}
    </div>
  );
}

const wrap: React.CSSProperties = { display: 'flex', flexWrap: 'wrap', alignItems: 'center', gap: 6, minWidth: 0 };
const summaryText: React.CSSProperties = { flex: 1, minWidth: 0, fontSize: 12, color: '#475569' };
const missing: React.CSSProperties = { flex: 1, minWidth: 0, fontSize: 12, color: '#b45309' };
const codeBtn: React.CSSProperties = { border: '1px solid #cbd5e1', borderRadius: 6, background: '#f8fafc', color: '#475569', cursor: 'pointer', fontSize: 11, padding: '2px 6px', whiteSpace: 'nowrap' };
const codeView: React.CSSProperties = { flexBasis: '100%', margin: '6px 0 0', padding: 8, background: '#0f172a', color: '#e2e8f0', borderRadius: 6, fontSize: 11, overflow: 'auto' };
