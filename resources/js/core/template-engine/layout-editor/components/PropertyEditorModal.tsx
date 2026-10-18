// e2e:allow 레이아웃 편집기 속성 편집 모달 — 컴포넌트 ⓘ 메뉴 진입 + 속성/번역 탭 칩 위젯·contentEditable·합성 클릭 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스 + 단위(s7-property-controls/prop-i18n-text-field/TranslationField 등)로 검증 (TranslationField.tsx 와 동일 정책)
/**
 * PropertyEditorModal.tsx — 속성 편집 모달
 *
 * 컴포넌트 ⓘ 메뉴의 "속성 설정" 으로 열린다. 탭 구성:
 * `[설정] [스타일] [동작] [표시조건] [번역] [고급]`.
 *  - `[설정]` — 집합 컴포넌트가 components.json 에 `settings` 를 선언한 경우만 표시
 *    (CompositeSettingsForm). 집합 컴포넌트면 기본 활성.
 *  - `[스타일]` — componentCapabilities[name].styleControls 화이트리스트의 컨트롤
 *  (ControlRenderer 디스패치). Phase 4 범위.
 *  - `[동작] [표시조건]` — Phase 5, `[번역]` — Phase 6 (자리만, "추후 지원" 안내).
 *  - `[고급]` — componentCapabilities[name].advanced (AdvancedPropsForm). Phase 4.
 *
 * 컨트롤 조작은 즉시 노드를 패치해 캔버스 라이브 반영(원칙 — onPatchNode 가
 * PATCH_LAYOUT 으로 연결). 스펙에 해당 컴포넌트 항목이 없으면 "편집 가능한 속성이
 * 없습니다" 안내(원칙 4.6). 고급 값 존재 시 상단 배지(원칙 4.4).
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만.
 *
 * @since engine-v1.50.0
 */

import React, { useMemo, useState } from 'react';
import type { EditorSpec } from '../spec/specTypes';
import type { EditorNode } from '../utils/layoutTreeUtils';
import type { ComponentManifest } from './ComponentPalette';
import { getComponentCapability, getControl } from '../spec/editorSpecLoader';
import { ControlRenderer } from './property-controls/ControlRenderer';
import { CompositeSettingsForm } from './property-controls/CompositeSettingsForm';
import { AdvancedPropsForm } from './property-controls/AdvancedPropsForm';
import { ActionRecipeEditor } from './property-controls/ActionRecipeEditor';
import { ConditionBuilder } from './property-controls/ConditionBuilder';
import {
  TranslationField,
  extractCustomKeyFromNode,
  deriveParamLabelsFromNode,
} from './property-controls/TranslationField';
import { DataBindingSection } from './property-controls/DataBindingSection';
import { InlineBindingSection } from './property-controls/InlineBindingSection';
import { IterationBindingSection } from './property-controls/IterationBindingSection';
import { disconnectParamAllLocales } from './property-controls/inlineBindingApi';
import { removeParamBinding } from '../spec/inlineBindingUtils';
import { isTextBindableNode } from '../spec/textComponents';
import type { BindingCandidate } from '../spec/bindingCandidates';
import type { DataPropSpec } from '../spec/specTypes';
import { FlexEditor } from './property-controls/FlexEditor';
import { StyleScopeTabs } from './property-controls/StyleScopeTabs';
import { getFlexEditorRole } from '../spec/flexModel';
import { getNodeEditor } from '../spec/nodeEditorRegistry';
import { hasAdvancedValues } from '../utils/advancedValueUtils';
import { BASE_SCOPE, clearScopeOverride, type StyleScope } from '../spec/styleScope';
import { CORE_PROP_CONTROLS, resolveCorePropKeys } from '../spec/coreProps';
import { IsolatedScopeControl } from './property-controls/IsolatedScopeControl';
import {
  InjectedPropsSection,
  type InjectedPropsEntry,
} from './property-controls/InjectedPropsSection';

type TabKey = 'settings' | 'props' | 'style' | 'action' | 'visibility' | 'translation' | 'advanced';

export interface PropertyEditorModalProps {
  /** 편집 대상 노드 (현재 패치 반영본) */
  node: EditorNode;
  /** 병합 editor-spec */
  spec: EditorSpec | null;
  /** components.json 매니페스트 — 집합 컴포넌트 settings 조회 */
  manifest: ComponentManifest | null;
  t: (key: string, params?: Record<string, string | number>) => string;
  /** 노드 패치 — 컨트롤/폼 변경 시 호출. 호출자가 PATCH_LAYOUT 으로 캔버스 반영 */
  onPatchNode: (patched: EditorNode) => void;
  /** 모달 닫기 */
  onClose: () => void;
  /** 이 요소 삭제 (모달 하단 버튼) */
  onDelete: () => void;
  /** 권한 키 후보 (고급 탭 permissions TagInput) */
  permissionCandidates?: Array<{ value: string; label: string }>;
  /** 라우트 후보 (동작 탭 page-picker) — */
  pageCandidates?: Array<{ value: string; label: string }>;
  /** 데이터소스 후보 (동작/표시조건 탭 datasource-picker) */
  dataSourceCandidates?: Array<{ value: string; label: string }>;
  /** 화면 상태 키 후보 (동작/표시조건 탭 state-key-picker) */
  stateKeyCandidates?: Array<{ value: string; label: string }>;
  /**
   * 데이터 연결 검색 후보 풀 — [속성] 탭 "데이터 연결" 영역의
   * 검색형 피커가 쓴다. EditorCanvasOverlay 가 data_sources 샘플 + 상태 트리를 평탄화해
   * 주입한다. 미전달 시 데이터 연결 영역은 후보 없음(빈 검색 결과)으로 디그레이드.
   */
  bindingCandidates?: BindingCandidate[];
  /** 캔버스에 렌더된 이 노드의 DOM (flex auto 판정용) */
  liveElement?: Element | null;
  /**
   * 활성 StyleScope (색 모드 × 디바이스). EditorCanvasOverlay 가 모달 열 때 1회 스냅샷해
   * 내려준다(re-mount 함정 회피 — 패치 재마운트엔 불변). 기본 BASE_SCOPE.
   */
  scope?: StyleScope;
  /** scope 세부탭 변경 콜백 — EditorCanvasOverlay 의 스냅샷 ref 를 갱신 */
  onScopeChange?: (scope: StyleScope) => void;
  /**
   * 편집 대상 템플릿 식별자 — [번역] 탭(TranslationField)이 custom-translations API
   * 호출에 사용한다. 미전달 시 번역 탭은 "다국어 키 아님" 디그레이드.
   */
  templateIdentifier?: string;
  /**
   * 모달 진입 시 처음 활성화할 탭 — 인라인 편집 힌트 배지 클릭으로 모달을 열 때
   * 곧장 [번역] 탭을 보여 주기 위해 사용한다. 지정 탭이 가용 목록에 없으면 무시(기본 규칙 적용).
   */
  initialTab?: TabKey;
  /**
   * 확장 주입 props 저장 콜백. 호스트 노드에 `__injectedProps` 가
   * 있을 때 "확장이 주입한 속성" 섹션을 렌더하고, 편집 결과를 그 확장 행으로 교차 저장한다.
   * 미전달 시 섹션 미표시(디그레이드).
   */
  onSaveInjectedProps?: (extensionId: number, nextProps: Record<string, unknown>) => Promise<void>;
  /**
   * 디바이스 전용 구성 라벨 — 선택 노드가 어떤 디바이스 분기
   * (`responsive.{key}.children`) 안에 속하면 그 구성 라벨("모바일 구성" 등). EditorCanvasOverlay
   * 가 선택 path 의 responsive 세그먼트로 도출해 내려준다. 이 값이 있으면 모달 상단에
   * "이 요소는 [디바이스] 전용 구성에 속합니다" 안내 배지를 노출해, 다른 디바이스에서는
   * 다른 구성이 렌더된다는 사실(이 요소에 표시조건이 없는 이유)을 알린다. base 노드면 미전달.
   */
  branchLabel?: string | null;
  /**
   * 격리 영역(isolatedScopeId) 검색 드롭다운 후보 — 레이아웃 기존 scopeId +
   * initIsolated 키 + 관용 패턴(buildScopeIdCandidates). EditorCanvasOverlay 가 docCtx.raw 에서
   * 도출해 주입. 미전달 시 IsolatedScopeControl 은 후보 없이 자유 입력만(디그레이드).
   */
  isolatedScopeIdCandidates?: string[];
  /** 같은 레이아웃 내 다른 노드가 쓰는 scopeId(중복 안내용) */
  usedScopeIds?: string[];
}

/** manifest 전체에서 컴포넌트 엔트리 탐색 (basic/composite/layout 평면 검색) */
function findManifestEntry(manifest: ComponentManifest | null, name: string) {
  const groups = manifest?.components ?? {};
  for (const list of Object.values(groups)) {
    const found = list?.find((e) => e.name === name);
    if (found) return found;
  }
  return null;
}

export function PropertyEditorModal({
  node,
  spec,
  manifest,
  t,
  onPatchNode,
  onClose,
  onDelete,
  permissionCandidates,
  pageCandidates,
  dataSourceCandidates,
  stateKeyCandidates,
  bindingCandidates,
  liveElement,
  scope = BASE_SCOPE,
  onScopeChange,
  templateIdentifier,
  initialTab,
  onSaveInjectedProps,
  branchLabel,
  isolatedScopeIdCandidates,
  usedScopeIds,
}: PropertyEditorModalProps): React.ReactElement {
  // 확장 주입 props — 호스트 노드 `__injectedProps` 메타. 콜백이 있을 때만 섹션 렌더.
  const injectedProps = (node as { __injectedProps?: InjectedPropsEntry[] }).__injectedProps;
  const hasInjectedProps =
    Array.isArray(injectedProps) && injectedProps.length > 0 && !!onSaveInjectedProps;
  // scope 변경 — 상위(EditorCanvasOverlay) 스냅샷 ref 가 있으면 그쪽으로, 없으면 무시
  // (단독 사용/테스트 시 BASE_SCOPE 고정). 모달 자체는 scope state 를 보유하지 않는다
  // (re-mount 함정 — 패치마다 content 재마운트되므로 useState 는 매번 리셋됨).
  const handleScopeChange = (next: StyleScope): void => {
    onScopeChange?.(next);
  };
  // "기본값으로 초기화" — 현재 scope override 제거 후 노드 패치.
  const handleClearScope = (): void => {
    onPatchNode(clearScopeOverride(node, scope));
  };
  const componentName = typeof node.name === 'string' ? node.name : '';
  const capability = getComponentCapability(spec, componentName);
  const manifestEntry = findManifestEntry(manifest, componentName);
  const settingsSpec = manifestEntry?.settings;
  const hasSettings = !!(settingsSpec?.groups && settingsSpec.groups.length > 0);

  // 속성 탭 — capability.propControls 선언 시만. 비-스타일 prop(propValue)
  // 컨트롤 화이트리스트. 컨트롤 렌더 발효는 단계 2(현재는 탭 등장 + 디스패치 인프라).
  const propControlKeys: string[] = Array.isArray(capability?.propControls)
    ? (capability!.propControls as string[])
    : [];
  // 코어 제공 속성(요소 id 등) — 모든 draggable 에 일괄 노출(템플릿 opt-out 가능).
  // 코어는 컨트롤만 제공(강제 DOM 주입 X), 값은 표준 node.props.id.
  // 확장 propControls 가 같은 propKey(id)를 또 선언하면 코어가 우선(중복 제거).
  const corePropKeys = resolveCorePropKeys(
    (capability as { coreProps?: unknown } | null)?.coreProps,
  );
  const corePropKeySet = new Set(
    corePropKeys.map((k) => CORE_PROP_CONTROLS[k].apply?.propKey),
  );
  // 확장 propControls 중 코어가 이미 제공하는 propKey 와 겹치는 것은 제외(코어 우선).
  const extraPropControlKeys = propControlKeys.filter((key) => {
    const ctl = getControl(spec, key);
    const pk = ctl?.apply?.propKey;
    return !(pk !== undefined && corePropKeySet.has(pk));
  });
  // 일반 노드 에디터 슬롯(구조 편집) — capability.nodeEditor.kind 로 레지스트리 조회
  // (kind-agnostic, 부록4-ter). children/table/신규 종류 모두 동일 경로 — `if(kind==='table')`
  // 분기 0. 구조 편집(항목 추가/삭제/정렬, 행/열/병합)은 **CSS/className 이 아니라 노드 구조**라
  // [속성] 탭(scope 비종속 BASE_SCOPE)에 렌더한다 — [스타일] 탭은 CSS 전용.
  // nodeEditor 는 다크/디바이스 분기가 없다.
  const nodeEditorKind =
    typeof (capability as { nodeEditor?: { kind?: unknown } } | null)?.nodeEditor?.kind === 'string'
      ? ((capability as { nodeEditor?: { kind?: string } }).nodeEditor!.kind as string)
      : undefined;
  const nodeEditorParams =
    (capability as { nodeEditor?: { params?: Record<string, unknown> } } | null)?.nodeEditor
      ?.params;
  const NodeEditorComp = nodeEditorKind ? getNodeEditor(nodeEditorKind) : null;
  const hasNodeEditor = !!NodeEditorComp;
  // 데이터 연결 — capability.dataProps 선언 시 [속성] 탭 상단에
  // "데이터 연결" 전용 영역을 렌더한다. 데이터를 바라보는 prop(단일값/배열)만 대상이며,
  // 구조/수치/enum/boolean prop 은 propControls 정적 편집(토글 없음 — 1차 결함 근절).
  const dataProps: DataPropSpec[] = Array.isArray(
    (capability as { dataProps?: unknown } | null)?.dataProps,
  )
    ? ((capability as { dataProps?: DataPropSpec[] }).dataProps as DataPropSpec[])
    : [];
  const hasDataBinding = dataProps.length > 0;
  // 반복(iteration) 데이터 연결 — `node.iteration.source` 는 노드 최상위
  // 구조 키라 컴포넌트 capability 무관. iteration 을 가진 모든 노드에 공용 "반복 데이터 연결"
  // 영역을 [속성] 탭 최상단에 렌더. 6-b 가
  // iteration 축을 통째로 누락했던 결함을 메운다.
  const hasIteration = !!(node.iteration && typeof node.iteration === 'object');
  // 텍스트(보간) 데이터 연결 — 텍스트 보유 컴포넌트(코어 10종 또는 string text
  // 보유 노드)의 `text` prop 보간 조각을 조각 단위로 교체/해제/추가하는 영역. capability
  // 무관(코어 SSoT 집합 — `textComponents.ts`)이며 dataProps(prop 통째)와 직교한다.
  // iteration 노드는 제외(반복 소스는 IterationBindingSection 축).
  const hasInlineBinding = isTextBindableNode(node, capability as { textBinding?: unknown } | null);
  // 구조 에디터 보유는 [속성] 탭을 띄운다(코어 id 와 동격 — 비-스타일 편집 표면).
  const hasProps =
    corePropKeys.length > 0 ||
    extraPropControlKeys.length > 0 ||
    hasNodeEditor ||
    hasDataBinding ||
    hasInlineBinding ||
    hasIteration;

  const styleControlKeys: string[] = Array.isArray(capability?.styleControls)
    ? (capability!.styleControls as string[])
    : [];
  const advancedKeys: string[] = Array.isArray(capability?.advanced)
    ? (capability!.advanced as string[])
    : [];

  // 동작 탭 — capability.events 선언 시만.
  const eventKeys: string[] = Array.isArray((capability as { events?: unknown } | null)?.events)
    ? ((capability as { events?: string[] }).events as string[])
    : [];
  const hasActions = eventKeys.length > 0;
  // 정렬 박스(flex) 편집 — capability.flexEditor 선언 시 스타일 탭 상단에 노출.
  const flexRole = getFlexEditorRole(capability);
  // 스타일 탭은 CSS/className(스타일 컨트롤 + 정렬 박스) 전용. 구조 에디터(nodeEditor)는
  // [속성] 탭으로 분리(위 hasProps 참조).
  const hasStyleArea = styleControlKeys.length > 0 || flexRole !== null;
  // 표시조건 탭 — `visibilityCondition: false` 면 명시 차단. 그 외에는 편집 가능한
  // 실체 있는 컴포넌트(속성/스타일/동작/집합/고급 보유)에서만 노출(빈 capability 는 제외).
  const visibilityBlocked =
    (capability as { visibilityCondition?: unknown } | null)?.visibilityCondition === false;
  const allowVisibility =
    !visibilityBlocked &&
    (hasProps || hasStyleArea || hasActions || hasSettings || advancedKeys.length > 0);

  // 탭 가용성 — 선언이 있는 탭만 활성. 집합 컴포넌트면 설정 탭 기본 활성.
  // 순서: 설정 → 속성 → 스타일 → 동작 → 표시조건 → 번역 → 고급.
  const availableTabs = useMemo<TabKey[]>(() => {
    const tabs: TabKey[] = [];
    if (hasSettings) tabs.push('settings');
    if (hasProps) tabs.push('props');
    if (hasStyleArea) tabs.push('style');
    if (hasActions) tabs.push('action');
    if (allowVisibility) tabs.push('visibility');
    tabs.push('translation'); // Phase 6 자리
    if (advancedKeys.length > 0) tabs.push('advanced');
    return tabs;
  }, [hasSettings, hasProps, hasStyleArea, hasActions, allowVisibility, advancedKeys.length]);

  // 기본 활성 탭 결정 시 "속성" 탭은 **템플릿 제공 컨트롤 또는 구조 에디터가 있을 때만** 우선한다.
  // 코어 제공 속성(id)만 있는 컴포넌트는 속성 탭이 존재하되 기본 활성은 스타일로 양보
  // (스타일이 주 편집 영역 — 코어 id 하나로 기본 탭을 가로채지 않음)..
  // 구조 에디터(목록/표) 보유 컴포넌트는 그 구조 편집이 주 편집 영역이라 속성 탭을 기본 활성으로.
  const hasTemplateProps =
    extraPropControlKeys.length > 0 || hasNodeEditor || hasDataBinding || hasIteration;
  const [activeTab, setActiveTab] = useState<TabKey>(
    // initialTab 이 가용 탭이면 우선(인라인 힌트 배지 → [번역] 탭 직행), 아니면 기본 규칙.
    // 기본 활성 체인: 설정 → (템플릿)속성 → 스타일 → 속성(코어만) → 첫 가용 탭.
    initialTab && availableTabs.includes(initialTab)
      ? initialTab
      : hasSettings
        ? 'settings'
        : hasTemplateProps
          ? 'props'
          : hasStyleArea
            ? 'style'
            : hasProps
              ? 'props'
              : availableTabs[0] ?? 'style',
  );

  const showAdvancedBadge = hasAdvancedValues(node);
  const noEditable =
    !hasProps &&
    !hasStyleArea &&
    !hasSettings &&
    !hasActions &&
    !allowVisibility &&
    advancedKeys.length === 0;

  const friendlyName = capability?.label
    ? capability.label.startsWith('$t:')
      ? t(capability.label.slice(3))
      : capability.label
    : componentName || t('layout_editor.property_modal.element');

  const patchProp = (key: string, value: unknown): void => {
    const props = { ...(node.props ?? {}) } as Record<string, unknown>;
    if (value === undefined) delete props[key];
    else props[key] = value;
    onPatchNode({ ...node, props });
  };

  const tabLabel: Record<TabKey, string> = {
    settings: t('layout_editor.property_modal.tab.settings'),
    props: t('layout_editor.property_modal.tab.props'),
    style: t('layout_editor.property_modal.tab.style'),
    action: t('layout_editor.property_modal.tab.action'),
    visibility: t('layout_editor.property_modal.tab.visibility'),
    translation: t('layout_editor.property_modal.tab.translation'),
    advanced: t('layout_editor.property_modal.tab.advanced'),
  };

  return (
    <div className="g7le-property-modal" data-testid="g7le-property-modal" style={modalWrap}>
      <div data-modal-drag-handle data-testid="g7le-property-modal-header" style={headerStyle}>
        <span data-testid="g7le-property-modal-title" style={{ fontSize: 14, fontWeight: 700 }}>
          {hasSettings && activeTab === 'settings'
            ? t('layout_editor.property_modal.settings_title', { name: friendlyName })
            : t('layout_editor.property_modal.title', { name: friendlyName })}
        </span>
        <button type="button" data-testid="g7le-property-modal-close" aria-label="close" onClick={onClose} style={closeBtn}>
          ✕
        </button>
      </div>

      {showAdvancedBadge && (
        <div data-testid="g7le-property-modal-advanced-badge" style={advancedBadge}>
          {t('layout_editor.property_modal.advanced_badge')}
        </div>
      )}

      {/* 디바이스 전용 구성 안내 — 이 노드가 특정 디바이스
          구성(responsive children 교체) 안에 속하면, 다른 디바이스에서는 다른 구성이 렌더된다는
          사실(이 요소에 표시조건이 없는 이유)을 알린다. */}
      {branchLabel && (
        <div data-testid="g7le-property-modal-branch-badge" style={branchBadge}>
          {t('layout_editor.property_modal.branch_badge', { device: branchLabel })}
        </div>
      )}

      <div style={tabBar} role="tablist">
        {availableTabs.map((tab) => (
          <button
            key={tab}
            type="button"
            role="tab"
            aria-selected={activeTab === tab}
            data-testid={`g7le-property-tab-${tab}`}
            data-active={activeTab === tab ? 'true' : 'false'}
            onClick={() => setActiveTab(tab)}
            style={{
              ...tabBtn,
              color: activeTab === tab ? '#2563eb' : '#64748b',
              borderBottomColor: activeTab === tab ? '#2563eb' : 'transparent',
            }}
          >
            {tabLabel[tab]}
          </button>
        ))}
      </div>

      <div style={bodyStyle} data-testid="g7le-property-modal-body">
        {noEditable && !hasInjectedProps && (
          <div data-testid="g7le-property-modal-no-editable" style={emptyNotice}>
            {t('layout_editor.property_modal.no_editable_props')}
          </div>
        )}

        {/* 확장이 주입한 속성 섹션 — 호스트 본체 속성과 한 창 안 위아래로
            구분(출처 배지). 저장은 호스트 레이아웃이 아니라 그 확장 행으로 교차. */}
        {hasInjectedProps && (
          <InjectedPropsSection
            injectedProps={injectedProps as InjectedPropsEntry[]}
            t={t}
            onSaveInjectedProps={onSaveInjectedProps!}
          />
        )}

        {activeTab === 'settings' && settingsSpec && (
          <CompositeSettingsForm spec={settingsSpec} node={node} t={t} onPatchProp={patchProp} />
        )}

        {/* 속성 탭 — 비-스타일 prop(propValue) 컨트롤. StyleScopeTabs/FlexEditor 없이
 BASE_SCOPE 고정(propValue 는 scope 비종속 — 다크/디바이스 분기 없음).
            컨트롤 정의(propControls)는 단계 1~2 에서 전수 작성되며, 단계 0 은 탭 등장 +
            디스패치 인프라만 — 빈 propControls 면 본 탭이 가용 목록에 없어 표시되지 않는다. */}
        {activeTab === 'props' && (
          <div data-testid="g7le-property-props-tab" style={{ display: 'flex', flexDirection: 'column' }}>
            {/* 반복 데이터 연결 영역 — 속성 탭 최상단. `node.iteration.source` 는
                노드 최상위 구조 키라 컴포넌트 capability 무관(iteration 을 가진 모든 노드에 공용
                노출). 반복 소스는 항상 배열 바인딩 — array 후보만 검색형 피커에 노출. */}
            {hasIteration && (
              <IterationBindingSection
                node={node}
                candidates={bindingCandidates ?? []}
                t={t}
                onPatchNode={onPatchNode}
              />
            )}
            {/* 데이터 연결 영역 — 속성 탭 최상단 전용 섹션. capability.dataProps
                의 데이터형 prop(단일값/배열)만 검색형 바인딩. 구조/수치 propControls 와 시각 분리
                (헤딩 + 구분선). 데이터 prop 은 항상 바인딩(정적↔바인딩 토글 없음). */}
            {hasDataBinding && (
              <DataBindingSection
                node={node}
                dataProps={dataProps}
                candidates={bindingCandidates ?? []}
                t={t}
                onPatchNode={onPatchNode}
              />
            )}
            {/* 텍스트 데이터 연결 영역 — 텍스트 보유 컴포넌트의 `text` prop 보간
                조각을 조각 단위로 교체/해제하고 끝에 신규 추가한다(부록6 dataProps 의 text
                내부 보간판). 라벨/구분자/평문은 보존(inlineBindingUtils 위치 보존 split/join). */}
            {hasInlineBinding && (
              <InlineBindingSection
                node={node}
                candidates={bindingCandidates ?? []}
                t={t}
                onPatchNode={onPatchNode}
                templateIdentifier={templateIdentifier}
              />
            )}
            {/* 구조 에디터(nodeEditor) — kind-agnostic 디스패치(부록4-ter). children/table/
                신규 종류 모두 동일 슬롯에 직접 렌더, 코어는 kind 를 모른다. 구조 편집(항목/행·열)은
 CSS 가 아니라 노드 구조라 [속성] 탭에 둔다(스타일 탭=CSS 전용). */}
            {hasNodeEditor && NodeEditorComp && (
              <NodeEditorComp
                node={node}
                params={nodeEditorParams}
                spec={spec}
                manifest={manifest}
                t={t}
                onPatchNode={onPatchNode}
                templateIdentifier={templateIdentifier}
                // 배열폼/목록/표 셀 항목 텍스트의 `+데이터` 칩 삽입(단계 2~4)에 쓸
                // 후보 풀을 nodeEditor 에 일괄 주입(공용 인프라). 위젯이 항목 I18nTextField 로 흘린다.
                candidates={bindingCandidates}
              />
            )}
            {/* 코어 제공 속성(요소 id 등) — 확장 propControls 보다 먼저(코어 먼저 결합,
). 합성 EditorControlSpec 이라 controls.json 없이 동작 —
                동일 ControlRenderer 파이프라인으로 BASE_SCOPE 고정 렌더. */}
            {corePropKeys.map((key) => (
              <ControlRenderer
                key={`core-${key}`}
                controlKey={`core-${key}`}
                control={CORE_PROP_CONTROLS[key]}
                node={node}
                t={t}
                onPatch={onPatchNode}
                scope={BASE_SCOPE}
                // i18n-text/options-list propControl 의 `+데이터` 칩 삽입(키화)에
                // 후보 풀이 닿도록 전달(모달→ControlRenderer→위젯/I18nTextField).
                bindingCandidates={bindingCandidates}
              />
            ))}
            {extraPropControlKeys.map((key) => {
              const control = getControl(spec, key);
              if (!control) return null;
              return (
                <ControlRenderer
                  key={key}
                  controlKey={key}
                  control={control}
                  node={node}
                  t={t}
                  onPatch={onPatchNode}
                  scope={BASE_SCOPE}
                  // i18n-text/options-list propControl 의 `+데이터` 후보 풀 전달.
                  bindingCandidates={bindingCandidates}
                />
              );
            })}
            {/* 격리 영역 그룹 — isolatedState/isolatedScopeId 노드 최상위 키 부여.
                전 컴포넌트 노출(컨테이너 한정 아님 — 어떤 컴포넌트도 격리 스코프가 될 수 있음).
                [초기 상태] 탭 initIsolated 와 짝 연계(scopeId 후보·orphan 해소). */}
            <IsolatedScopeControl
              node={node}
              onPatchNode={onPatchNode}
              t={t}
              scopeIdCandidates={isolatedScopeIdCandidates}
              usedScopeIds={usedScopeIds}
            />
          </div>
        )}

        {activeTab === 'style' && (
          <div data-testid="g7le-property-style-tab" style={{ display: 'flex', flexDirection: 'column' }}>
            {/* 색 모드 × 디바이스 서브탭 — 스크롤 본체 상단 고정. 아래
                컨트롤이 길어 스크롤돼도 scope 탭은 항상 보이도록 sticky + 불투명 배경. */}
            <div style={stickyScope}>
              <StyleScopeTabs scope={scope} onChange={handleScopeChange} node={node} t={t} onClearScope={handleClearScope} />
            </div>
            {flexRole !== null && (
              <FlexEditor
                node={node}
                spec={spec}
                capability={capability}
                t={t}
                onPatchNode={onPatchNode}
                liveElement={liveElement}
                scope={scope}
              />
            )}
            {styleControlKeys.map((key) => {
              const control = getControl(spec, key);
              if (!control) return null;
              return (
                <ControlRenderer
                  key={key}
                  controlKey={key}
                  control={control}
                  node={node}
                  t={t}
                  onPatch={onPatchNode}
                  scope={scope}
                />
              );
            })}
          </div>
        )}

        {activeTab === 'action' && (
          <ActionRecipeEditor
            node={node}
            spec={spec}
            capability={capability}
            t={t}
            onPatchNode={onPatchNode}
            pageCandidates={pageCandidates}
            dataSourceCandidates={dataSourceCandidates}
            stateKeyCandidates={stateKeyCandidates}
            bindingCandidates={bindingCandidates}
          />
        )}

        {activeTab === 'visibility' && (
          <div data-testid="g7le-property-visibility-tab" style={{ display: 'flex', flexDirection: 'column' }}>
            {/* 디바이스 세부탭만 — if 는 다크 무관(D9), 색 모드 줄 숨김. 스크롤 본체 상단 고정. */}
            <div style={stickyScope}>
              <StyleScopeTabs scope={scope} onChange={handleScopeChange} node={node} t={t} showColorScheme={false} />
            </div>
            <ConditionBuilder
              node={node}
              spec={spec}
              t={t}
              onPatchNode={onPatchNode}
              dataSourceCandidates={dataSourceCandidates}
              stateKeyCandidates={stateKeyCandidates}
              scope={scope}
            />
          </div>
        )}

        {activeTab === 'translation' && (
          templateIdentifier ? (
            // node→customKey 추상화. [번역] 탭은 node 에서 customKey/paramLabels 를
            // 어댑트해 주입(펼침=칸자리와 동일 컴포넌트 공유 SSoT). 기존 동작 보존.
            <TranslationField
              customKey={extractCustomKeyFromNode(node)}
              templateIdentifier={templateIdentifier}
              t={t}
              paramLabels={deriveParamLabelsFromNode(node)}
              // 칩 우측 X = 데이터 연결 '해제'. node.text `|pN=` 제거(패치) + 전 로케일
              // `{pN}` 제거 + 캔버스/칸자리 동기화(칩 위젯 SSoT 일원화 — 칸자리/인라인/펼침 동일 동작).
              onRemoveParam={(paramName) => {
                const text = typeof node.text === 'string' ? node.text : '';
                onPatchNode({ ...node, text: removeParamBinding(text, paramName) });
                const key = extractCustomKeyFromNode(node);
                if (templateIdentifier && key) {
                  void disconnectParamAllLocales(templateIdentifier, key, paramName);
                }
              }}
            />
          ) : (
            <div data-testid="g7le-property-translation-deferred" style={emptyNotice}>
              {t('layout_editor.translation.not_a_key')}
            </div>
          )
        )}

        {activeTab === 'advanced' && (
          <AdvancedPropsForm
            node={node}
            advanced={advancedKeys}
            t={t}
            onPatch={onPatchNode}
            permissionCandidates={permissionCandidates}
          />
        )}
      </div>

      <div style={footerStyle}>
        <span data-testid="g7le-property-modal-node-id" style={{ fontSize: 11, color: '#94a3b8' }}>
          {typeof node.id === 'string' || typeof node.id === 'number' ? `ID: ${node.id}` : ''}
        </span>
        <div style={{ display: 'flex', gap: 8 }}>
          <button type="button" data-testid="g7le-property-modal-delete" onClick={onDelete} style={deleteBtn}>
            {t('layout_editor.property_modal.delete_element')}
          </button>
          <button type="button" data-testid="g7le-property-modal-done" onClick={onClose} style={doneBtn}>
            {t('layout_editor.property_modal.close')}
          </button>
        </div>
      </div>
    </div>
  );
}

const modalWrap: React.CSSProperties = { display: 'flex', flexDirection: 'column', width: 420, maxWidth: '92vw' };
// cursor:move — draggable 모달일 때 헤더가 드래그 핸들임을 시각 표시.
// 비-draggable 모달에서는 EditorModalContext 가 onPointerDown 을 붙이지 않으므로
// cursor 표식만 남고 동작 영향 없음.
const headerStyle: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', borderBottom: '1px solid #e2e8f0', cursor: 'move', userSelect: 'none' };
const closeBtn: React.CSSProperties = { border: 'none', background: 'transparent', fontSize: 14, cursor: 'pointer', color: '#64748b' };
const advancedBadge: React.CSSProperties = { margin: '8px 16px 0', padding: '6px 10px', fontSize: 11, background: '#fef3c7', color: '#92400e', borderRadius: 6 };
/** 디바이스 전용 구성 안내 배지 — 파란 톤(정보성, advancedBadge 노랑 경고와 구분). */
const branchBadge: React.CSSProperties = { margin: '8px 16px 0', padding: '6px 10px', fontSize: 11, background: '#dbeafe', color: '#1e40af', borderRadius: 6 };
const tabBar: React.CSSProperties = { display: 'flex', gap: 2, padding: '0 12px', borderBottom: '1px solid #e2e8f0', overflowX: 'auto' };
const tabBtn: React.CSSProperties = { padding: '8px 10px', fontSize: 12, border: 'none', borderBottom: '2px solid transparent', background: 'transparent', cursor: 'pointer', whiteSpace: 'nowrap' };
const bodyStyle: React.CSSProperties = { padding: 16, overflowY: 'auto', maxHeight: '60vh' };
// 색 모드 × 디바이스 서브탭을 스크롤 본체 상단에 고정. body 의 padding(16)
// 을 음수 마진으로 상쇄해 본체 폭 전체에 붙이고, 불투명 흰 배경 + z-index 로 아래 컨트롤이
// 탭 뒤로 스크롤되게 한다. top: -16 은 body 상단 패딩만큼 끌어올려 탭이 본체 맨 위에 닿게 한다.
const stickyScope: React.CSSProperties = {
  position: 'sticky',
  top: -16,
  zIndex: 2,
  background: '#fff',
  // body 패딩(16)을 좌우/상단 음수 마진으로 상쇄해 본체 폭 전체에 붙이고, 상단은 6px 만 두어
  // 메인탭과의 간격을 좁힌다. 좌우 패딩은 메인탭 정렬(12)과 맞춤.
  margin: '-16px -16px 0',
  padding: '6px 16px 0',
};
const emptyNotice: React.CSSProperties = { fontSize: 12, color: '#94a3b8', padding: '16px 0', textAlign: 'center' };
const footerStyle: React.CSSProperties = { display: 'flex', alignItems: 'center', justifyContent: 'space-between', padding: '12px 16px', borderTop: '1px solid #e2e8f0' };
const deleteBtn: React.CSSProperties = { padding: '6px 12px', fontSize: 12, border: '1px solid #fecaca', borderRadius: 6, background: '#fff', color: '#dc2626', cursor: 'pointer' };
const doneBtn: React.CSSProperties = { padding: '6px 12px', fontSize: 12, border: 'none', borderRadius: 6, background: '#2563eb', color: '#fff', cursor: 'pointer' };
