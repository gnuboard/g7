/**
 * InitActionsForm.tsx — [화면 동작] 탭 본체
 *
 * 레이아웃 최상위 `init_actions`(배열, 순서=실행 순서)를 핸들러 스펙 인스턴스 목록으로
 * 편집한다. 유저가 하는 일 = ① 핸들러 선택(추가 picker) ② 순서 배치(드래그) ③ 스펙 허용
 * 값 채우기(params 위젯) ④ 실행조건(if). **새 핸들러 선언 불가**.
 *
 *  ② 공통화: 카드/드래그/추가/편집(params·if)/출처 배지는 공용 `ActionListBuilder` 에
 * 위임한다(종전 자체 ActionCard/ParamField/if 토글 복제 제거). 본 폼은 **상속 그룹 분리**
 * (base/route)와 "[공통에서 수정]" 버튼만 담당한다 — 차이를 호출자가 처리하고 카드는 공용
 * 빌더 SSoT(②).
 *
 * 상속: 자식 편집 시 병합 init_actions 를 `__source.kind`('base'/'route')로 두 그룹
 * 분리. base 그룹은 ActionListBuilder `isLocked` 로 전부 잠금(읽기전용 — 코드 보기만) + "[공통
 * 에서 수정]" 버튼. route 그룹만 편집/추가/삭제/순서. 저장 시 호출자가 __source 기준 분리.
 *
 * @since engine-v1.50.0 · 공통화 engine-v1.50.0
 */

import React, { useCallback, useMemo } from 'react';
import type { ActionRecipeSpec } from '../../spec/specTypes';
import type { RecipeSource } from '../../spec/editorSpecLoader';
import { ActionListBuilder } from './ActionListBuilder';
import type { ActionParamCandidatePools } from './ActionParamFields';
import type { BindingCandidate } from '../../spec/bindingCandidates';

export interface InitActionsFormProps {
  /** 편집 중 init_actions 배열(병합본 — 상속 시 부모+자식, `__source` 부착) */
  actions: Array<Record<string, unknown>>;
  /** 배열 변경 콜백(자식 분만 patch 책임은 호출자) */
  onChange: (next: Array<Record<string, unknown>>) => void;
  /** 핸들러 스펙 맵(코어 시드 + 확장) */
  recipes?: Record<string, ActionRecipeSpec | string>;
  /** 다국어 해석 */
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 데이터칩 후보(action param 데이터칩) */
  bindingCandidates?: BindingCandidate[];
  /** 라우트 후보 (page-picker) */
  pageCandidates?: Array<{ value: string; label: string }>;
  /** 데이터소스 후보 (datasource-picker) */
  dataSourceCandidates?: Array<{ value: string; label: string }>;
  /** 상태 키 후보 (state-key-picker) */
  stateKeyCandidates?: Array<{ value: string; label: string }>;
  /** 레이아웃 modals 후보 (modal-picker — openModal 대상) */
  modalCandidates?: Array<{ value: string; label: string }>;
}

/**
 * init_actions 의 `__source.kind`. 출처 배지(RecipeSource)와 상속 그룹(base/route) 두 의미가
 * 한 필드에 실린다. 셸(PageSettingsModal)이 병합 시 상속 항목에 base/route 를 부착한다.
 */
type InitActionSourceKind = RecipeSource['kind'] | 'base' | 'route';
interface InitActionSource {
  kind: InitActionSourceKind;
  id?: string;
}

/** 액션의 `__source` 메타 추출 */
function actionSource(action: Record<string, unknown>): InitActionSource | null {
  const s = action.__source;
  if (s && typeof s === 'object') return s as InitActionSource;
  return null;
}

/**
 * [화면 동작] 탭 폼.
 *
 * @param props InitActionsFormProps
 * @return 화면 동작 폼 엘리먼트
 */
export function InitActionsForm({
  actions,
  onChange,
  recipes,
  t,
  bindingCandidates,
  pageCandidates,
  dataSourceCandidates,
  stateKeyCandidates,
  modalCandidates,
}: InitActionsFormProps): React.ReactElement {
  const pools: ActionParamCandidatePools = useMemo(
    () => ({ pageCandidates, dataSourceCandidates, stateKeyCandidates, modalCandidates, bindingCandidates }),
    [pageCandidates, dataSourceCandidates, stateKeyCandidates, modalCandidates, bindingCandidates],
  );

  // 상속 그룹 분리 — `__source.kind`. base 그룹이 하나라도 있으면 상속 모드.
  const baseActions = useMemo(() => actions.filter((a) => actionSource(a)?.kind === 'base'), [actions]);
  const selfActions = useMemo(() => actions.filter((a) => actionSource(a)?.kind !== 'base'), [actions]);
  const inheritanceMode = baseActions.length > 0;

  // self 그룹 변경 → base + self 재병합(base 는 항상 앞, 불변). 비상속 모드면 전체가 self.
  const onSelfChange = useCallback(
    (nextSelf: Array<Record<string, unknown>>): void => {
      onChange(inheritanceMode ? [...baseActions, ...nextSelf] : nextSelf);
    },
    [onChange, inheritanceMode, baseActions],
  );

  // base 그룹은 ActionListBuilder 가 직접 변경하지 않는다(전부 잠금) — onChange 는 no-op.
  const noop = useCallback(() => {}, []);

  return (
    <div className="g7le-init-actions-form" data-testid="g7le-init-actions-form" style={form}>
      <p style={heading}>{t('layout_editor.page_settings.init_actions.heading')}</p>

      {inheritanceMode ? (
        <>
          <div data-testid="g7le-init-action-group-base" style={groupBox}>
            <div style={groupHeader}>{t('layout_editor.init_actions.group_base')}</div>
            <ActionListBuilder
              actions={baseActions}
              onChange={noop}
              t={t}
              recipes={recipes}
              candidatePools={pools}
              addContext="init"
              showSourceBadge
              isLocked={() => true}
              hideAddPicker
              testIdPrefix="g7le-init-action-base"
            />
            <p data-testid="g7le-init-action-base-hint" style={baseHint}>
              ⓘ {t('layout_editor.init_actions.base_readonly_hint')}
            </p>
          </div>
          <div data-testid="g7le-init-action-group-self" style={groupBox}>
            <div style={groupHeader}>{t('layout_editor.init_actions.group_self')}</div>
            {selfActions.length === 0 ? (
              <p style={emptyHint}>{t('layout_editor.init_actions.empty_self')}</p>
            ) : null}
            <ActionListBuilder
              actions={selfActions}
              onChange={onSelfChange}
              t={t}
              recipes={recipes}
              candidatePools={pools}
              addContext="init"
              showSourceBadge
              testIdPrefix="g7le-init-action-self"
            />
          </div>
          <p data-testid="g7le-init-action-merge-order" style={mergeOrder}>
            ⓘ {t('layout_editor.init_actions.merge_order')}
          </p>
        </>
      ) : (
        <>
          {actions.length === 0 ? (
            <p data-testid="g7le-init-action-empty" style={emptyHint}>
              {t('layout_editor.init_actions.empty')}
            </p>
          ) : null}
          <ActionListBuilder
            actions={actions}
            onChange={onChange}
            t={t}
            recipes={recipes}
            candidatePools={pools}
            addContext="init"
            showSourceBadge
            testIdPrefix="g7le-init-action"
          />
        </>
      )}
    </div>
  );
}

const form: React.CSSProperties = { display: 'flex', flexDirection: 'column', gap: 12, minWidth: 0 };
const heading: React.CSSProperties = { margin: 0, fontSize: 13, fontWeight: 700, color: '#0f172a' };
const groupBox: React.CSSProperties = { border: '1px solid #e2e8f0', borderRadius: 10, padding: 10, display: 'flex', flexDirection: 'column', gap: 8 };
const groupHeader: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#475569' };
const baseHint: React.CSSProperties = { margin: 0, fontSize: 11, color: '#64748b' };
const emptyHint: React.CSSProperties = { margin: 0, fontSize: 12, color: '#94a3b8' };
const mergeOrder: React.CSSProperties = { margin: 0, fontSize: 11, color: '#64748b' };
