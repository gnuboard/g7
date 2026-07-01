/**
 * ActionRecipeEditor.tsx — 동작(액션) 편집 탭 본체
 *
 * 컴포넌트 capability 의 `events`(`onClick`/`onHover` 등) 만큼 동작 슬롯을 렌더한다. 각 슬롯은
 * 그 이벤트에 매달린 친화 동작 목록을 편집한다.
 *
 *  ② 공통화: 슬롯 안의 액션 리스트 UI(카드·드래그·추가·편집·출처 배지·onSuccess 중첩)는
 * 공용 `ActionListBuilder` 에 위임한다(종전 자체 ActionList/ActionCard/ParamField 복제 제거 —
 *  ②). 본 컴포넌트는 **이벤트 슬롯 루프 + node ↔ slot 변환**(readEventActions/
 * writeEventActions)만 담당한다.
 *
 * 무파괴 유지([[feedback_editor_event_delegation_change_requires_full_interaction_matrix]] /
 * [[feedback_node_structural_keys_if_actions_are_top_level_not_props]]):
 *  - 이벤트 슬롯 구조(`capability.events`)는 컴포넌트 고유 — 유지.
 *  - 액션 저장 위치는 노드 **최상위 `node.actions`** 배열 + 이벤트는 항목의 `type`
 *    (onClick→click, onHover→mouseenter). `props.actions`/`props.events` 미생성 — 유지.
 *  - `onSuccess`/`onError`(action-list) 중첩 동작 목록 재귀 — 공용 빌더가 처리(카드 일관).
 *  - 기존 buildAction/matchAction 변환 경로 — 무변경.
 *
 * 코어 핸들러 스펙(coreActionRecipes)이 actionRecipes base 로 병합되므로(editorSpecLoader
 * coreSeed), 템플릿이 actionRecipes 를 미작성해도 코어 핸들러가 1급 노출된다.
 *
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 * @since engine-v1.50.0
 */

import React, { useMemo } from 'react';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { EditorSpec, ComponentCapabilitySpec } from '../../spec/specTypes';
import { ActionListBuilder } from '../page-settings/ActionListBuilder';
import type { ActionParamCandidatePools } from '../page-settings/ActionParamFields';
import type { BindingCandidate } from '../../spec/bindingCandidates';

export interface ActionRecipeEditorProps {
  node: EditorNode;
  spec: EditorSpec | null;
  capability: ComponentCapabilitySpec | null;
  t: (key: string, params?: Record<string, string | number>) => string;
  onPatchNode: (patched: EditorNode) => void;
  /** 라우트 후보 (page-picker) */
  pageCandidates?: Array<{ value: string; label: string }>;
  /** 데이터소스 후보 (datasource-picker) */
  dataSourceCandidates?: Array<{ value: string; label: string }>;
  /** 상태 키 후보 (state-key-picker) */
  stateKeyCandidates?: Array<{ value: string; label: string }>;
  /** 데이터칩 후보 풀 (param i18n-text/data-chip) */
  bindingCandidates?: BindingCandidate[];
}

/**
 * capability 친화 이벤트명 → 액션 항목의 DOM 이벤트 `type` 매핑 (docs/frontend/actions.md).
 *
 * G7 액션 규약: 액션은 노드 **최상위 `node.actions`** 배열에 들어가고, 이벤트는 각 항목의
 * `type`(표준 DOM 이벤트) 으로 구분한다. onHover 는 mouseenter 로 매핑한다.
 */
const EVENT_TYPE_MAP: Record<string, string> = {
  onClick: 'click',
  onHover: 'mouseenter',
  onChange: 'change',
  onSubmit: 'submit',
  onMount: 'mount',
  onUnmount: 'unmount',
  onFocus: 'focus',
  onBlur: 'blur',
};

/** 친화 이벤트명 → DOM type (미정의면 친화명에서 on 제거 후 소문자) */
function eventToType(eventName: string): string {
  return EVENT_TYPE_MAP[eventName] ?? eventName.replace(/^on/, '').toLowerCase();
}

/**
 * 노드 액션 배열 — 최상위 `node.actions` 정본, 과거 잘못 저장된 `props.actions`/`props.events`
 * 역호환 폴백(평탄화 흡수 — 저장 시 writeEventActions 가 최상위로 이관).
 */
function nodeActions(node: EditorNode): Record<string, unknown>[] {
  const top = (node as Record<string, unknown>).actions;
  if (Array.isArray(top)) {
    return top.filter((a): a is Record<string, unknown> => !!a && typeof a === 'object');
  }
  const props = (node.props ?? {}) as Record<string, unknown>;
  if (Array.isArray(props.actions)) {
    return (props.actions as unknown[]).filter((a): a is Record<string, unknown> => !!a && typeof a === 'object');
  }
  const events = props.events as Record<string, unknown> | undefined;
  if (events && typeof events === 'object') {
    const flat: Record<string, unknown>[] = [];
    for (const [evName, arr] of Object.entries(events)) {
      if (!Array.isArray(arr)) continue;
      const ty = eventToType(evName);
      for (const a of arr) if (a && typeof a === 'object') flat.push('type' in a ? a : { type: ty, ...(a as object) });
    }
    return flat;
  }
  return [];
}

/** 노드에서 한 이벤트(type)에 매달린 액션만 읽기 — node.actions 에서 type 매칭 */
function readEventActions(node: EditorNode, eventName: string): Record<string, unknown>[] {
  const type = eventToType(eventName);
  return nodeActions(node).filter((a) => a.type === type || (eventName === 'onClick' && a.type === undefined));
}

/**
 * 한 이벤트(type)의 액션 목록을 노드 최상위 `node.actions` 에 병합 기록.
 *
 * 다른 이벤트 type 의 기존 액션은 보존하고, 이 이벤트 type 의 액션만 교체한다. 새 액션
 * 항목에는 `type` 을 부여한다(빌드 결과에는 핸들러만 있으므로 본 함수가 이벤트 type 주입).
 */
function writeEventActions(
  node: EditorNode,
  eventName: string,
  actions: Record<string, unknown>[],
): EditorNode {
  const type = eventToType(eventName);
  const others = nodeActions(node).filter(
    (a) => !(a.type === type || (eventName === 'onClick' && a.type === undefined)),
  );
  const typed = actions.map((a) => ('type' in a ? a : { type, ...a }));
  const next = { ...node };
  // legacy 위치(props.actions/props.events) 잔재 정리 — 최상위로 일원화.
  if (next.props && typeof next.props === 'object') {
    const props = { ...(next.props as Record<string, unknown>) };
    let touched = false;
    if ('actions' in props) { delete props.actions; touched = true; }
    if ('events' in props) { delete props.events; touched = true; }
    if (touched) next.props = props;
  }
  const merged = [...others, ...typed];
  if (merged.length === 0) delete next.actions;
  else next.actions = merged;
  return next;
}

/**
 * 동작 편집 탭 — 이벤트 슬롯 루프 + 공용 빌더 위임.
 *
 * @param props ActionRecipeEditorProps
 * @return 동작 편집 엘리먼트
 */
export function ActionRecipeEditor({
  node,
  spec,
  capability,
  t,
  onPatchNode,
  pageCandidates,
  dataSourceCandidates,
  stateKeyCandidates,
  bindingCandidates,
}: ActionRecipeEditorProps): React.ReactElement {
  const rawRecipes = spec?.actionRecipes as Record<string, unknown> | undefined;
  const pools: ActionParamCandidatePools = useMemo(
    () => ({ pageCandidates, dataSourceCandidates, stateKeyCandidates, bindingCandidates }),
    [pageCandidates, dataSourceCandidates, stateKeyCandidates, bindingCandidates],
  );
  const events: string[] = Array.isArray((capability as { events?: unknown } | null)?.events)
    ? ((capability as { events?: string[] }).events as string[])
    : [];

  if (events.length === 0) {
    return (
      <div data-testid="g7le-action-no-events" style={emptyNotice}>
        {t('layout_editor.action.no_events')}
      </div>
    );
  }

  if (!rawRecipes || Object.keys(rawRecipes).length === 0) {
    return (
      <div data-testid="g7le-action-no-recipes" style={emptyNotice}>
        {t('layout_editor.action.no_recipes')}
      </div>
    );
  }

  return (
    <div className="g7le-action-editor" data-testid="g7le-action-editor" style={{ display: 'flex', flexDirection: 'column', gap: 14 }}>
      {events.map((eventName) => (
        <div key={eventName} className="g7le-action-event-slot" data-testid={`g7le-action-event-${eventName}`}>
          <div style={eventTitle}>{t(`layout_editor.action.event.${eventName}`)}</div>
          <ActionListBuilder
            actions={readEventActions(node, eventName)}
            onChange={(next) => onPatchNode(writeEventActions(node, eventName, next))}
            t={t}
            recipes={rawRecipes as never}
            candidatePools={pools}
            // 컴포넌트 동작의 apiCall onSuccess/onError 안에서 응답/오류 칩(+ 확장 도메인 응답 필드)을
            // 쓸 수 있도록 병합 editor-spec 의 actionChipCandidates 를 흘린다. 최상위 이벤트(click 등)는
            // 컨텍스트가 없고(chipContext 미지정), apiCall onSuccess 진입 시 빌더가 'response' 로 전환한다.
            actionChipCandidates={spec?.actionChipCandidates ?? null}
            addContext="component"
            showSourceBadge
            testIdPrefix={`g7le-action-slot-${eventName}`}
          />
        </div>
      ))}
    </div>
  );
}

const emptyNotice: React.CSSProperties = { fontSize: 12, color: '#94a3b8', padding: '16px 0', textAlign: 'center' };
const eventTitle: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#475569', marginBottom: 6 };
