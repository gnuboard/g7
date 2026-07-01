/**
 * FlexEditor.tsx — "정렬 박스"(flex) 편집 컨트롤
 *
 * flex 를 비개발자 친화 UI 로 노출한다(이슈 12번). capability 의 `flexEditor`
 * (`container`|`item`|`auto`)에 따라 컨테이너 컨트롤(방향/주축·교차축 정렬/줄바꿈/
 * 간격) 또는 아이템 컨트롤(늘어남/혼자 정렬/순서)을 렌더한다. 각 축은 editor-spec
 * 의 `controls` 레시피(`flexDirection`/`flexJustify`/…)를 ControlRenderer 와 동일한
 * applyRecipe/reverseResolve 로 코드에 반영한다(원칙 4.8 — classToken/styleProp
 * 어느 쪽이든 그 템플릿 결정).
 *
 * `auto` 컨테이너는 실제 flex 로 렌더되는지 computed style(12.3.4)로 판정해 컨테이너
 * 컨트롤 또는 "정렬 박스로 만들기" 버튼만 노출한다.
 *
 * 편집기 코어 컴포넌트 — `g7le-*` + 인라인 스타일만, CSS 라이브러리 토큰 비종속.
 *
 * @since engine-v1.50.0
 */

import React from 'react';
import type { EditorNode } from '../../utils/layoutTreeUtils';
import type { EditorSpec, ComponentCapabilitySpec } from '../../spec/specTypes';
import { getControl } from '../../spec/editorSpecLoader';
import {
  getFlexEditorRole,
  resolveFlexContainerMode,
  isNodeFlexEnabled,
  FLEX_CONTAINER_CONTROL_KEYS,
  FLEX_ITEM_CONTROL_KEYS,
} from '../../spec/flexModel';
import { ControlRenderer } from './ControlRenderer';
import { applyRecipe } from '../../spec/recipeEngine';
import { BASE_SCOPE, type StyleScope } from '../../spec/styleScope';

export interface FlexEditorProps {
  node: EditorNode;
  spec: EditorSpec | null;
  capability: ComponentCapabilitySpec | null;
  t: (key: string, params?: Record<string, string | number>) => string;
  onPatchNode: (patched: EditorNode) => void;
  /** 캔버스에 렌더된 이 노드의 DOM (auto flex 판정용). 미공급 시 auto=block 취급 */
  liveElement?: Element | null;
  /**
   * 활성 StyleScope (색 모드 × 디바이스). 기본 BASE_SCOPE = 라이트 × 공통.
   * scope≠base 면 flex 축/enable 이 그 위치(`responsive[bp].props`)에 기록되고, 디바이스별
   * flex 해제는 off 레시피(명시 해제 토큰, D7)로 base 상속을 끊는다.
   */
  scope?: StyleScope;
}

/** 컨테이너 축 라벨 키 */
const CONTAINER_LABELS: Record<string, string> = {
  [FLEX_CONTAINER_CONTROL_KEYS.direction]: 'layout_editor.flex.direction',
  [FLEX_CONTAINER_CONTROL_KEYS.justify]: 'layout_editor.flex.justify',
  [FLEX_CONTAINER_CONTROL_KEYS.align]: 'layout_editor.flex.align',
  [FLEX_CONTAINER_CONTROL_KEYS.wrap]: 'layout_editor.flex.wrap',
  [FLEX_CONTAINER_CONTROL_KEYS.gap]: 'layout_editor.flex.gap',
};
const ITEM_LABELS: Record<string, string> = {
  [FLEX_ITEM_CONTROL_KEYS.grow]: 'layout_editor.flex.item_grow',
  [FLEX_ITEM_CONTROL_KEYS.selfAlign]: 'layout_editor.flex.item_align',
  [FLEX_ITEM_CONTROL_KEYS.order]: 'layout_editor.flex.item_order',
};

export function FlexEditor({
  node,
  spec,
  capability,
  t,
  onPatchNode,
  liveElement,
  scope = BASE_SCOPE,
}: FlexEditorProps): React.ReactElement {
  const role = getFlexEditorRole(capability);
  const enableControl = getControl(spec, FLEX_CONTAINER_CONTROL_KEYS.enable);
  // 정렬 박스 활성 여부는 **노드 파생**(enable 컨트롤 역해석, scope 적용) — liveElement 가
  // 패치 직후 stale 해도 정확(§항목A 결함 해소). enable 컨트롤이 없는 템플릿만 computed
  // style 폴백(base scope 한정, D8).
  const nodeFlexEnabled = isNodeFlexEnabled(node, enableControl, scope);
  const { showContainer, showEnableButton, showDisableButton } = resolveFlexContainerMode(
    role,
    liveElement,
    nodeFlexEnabled,
    !!enableControl,
    scope,
  );

  // 컨테이너 컨트롤 — 선언된 것만(원칙 4.6).
  const containerKeys = Object.values(FLEX_CONTAINER_CONTROL_KEYS).filter(
    (k) => k !== FLEX_CONTAINER_CONTROL_KEYS.enable && getControl(spec, k),
  );
  const itemKeys = Object.values(FLEX_ITEM_CONTROL_KEYS).filter((k) => getControl(spec, k));

  // flexEnable on/off 2상태 옵션값 추출(D7). 옵션 기반이면 on/off 값을 명시 적용해
  // scope 에서 base 상속을 끊는다(off 레시피 = 명시 해제 토큰). 미선언 템플릿은 종전
  // 동작(onValue ?? true / undefined 제거)으로 디그레이드.
  const enableOptions = (enableControl as { options?: Array<{ value: unknown }> } | null)?.options;
  const onValue =
    (enableControl as { onValue?: unknown } | null)?.onValue ??
    enableOptions?.[0]?.value ??
    true;
  // off 값 — 옵션이 2개 이상이면 둘째 옵션을 off 로(on/off 2상태). 없으면 undefined(제거).
  const offValue = Array.isArray(enableOptions) && enableOptions.length >= 2
    ? enableOptions[1].value
    : undefined;

  const enableFlex = (): void => {
    if (!enableControl) return;
    // "정렬 박스로 만들기" — enable 컨트롤의 apply 로 flex 활성값 적용(템플릿 결정), scope 위치.
    const patched = applyRecipe(node, enableControl, onValue, scope);
    onPatchNode(patched);
  };

  const disableFlex = (): void => {
    if (!enableControl) return;
    // "정렬 박스 해제" — base scope 는 enable 토큰 제거(undefined). scope≠base 는 off 옵션이
    // 있으면 명시 해제 토큰을 기록해 base 상속을 끊는다(D7). off 옵션 미선언 템플릿은 제거로
    // 디그레이드(이 경우 base 가 flex 면 scope 에서 완전 해제는 불가 — 템플릿 책임).
    const value = scope.breakpoint !== 'base' && offValue !== undefined ? offValue : undefined;
    const patched = applyRecipe(node, enableControl, value, scope);
    onPatchNode(patched);
  };

  const renderControls = (keys: string[], labels: Record<string, string>, testid: string): React.ReactElement => (
    <div data-testid={testid} style={{ display: 'flex', flexDirection: 'column' }}>
      {keys.map((key) => {
        const control = getControl(spec, key);
        if (!control) return null;
        // 라벨이 control 에 없으면 코어 flex 라벨로 보강.
        const withLabel = control.label ? control : { ...control, label: `$t:${labels[key]}` };
        return (
          <ControlRenderer
            key={key}
            controlKey={key}
            control={withLabel}
            node={node}
            t={t}
            onPatch={onPatchNode}
            scope={scope}
          />
        );
      })}
    </div>
  );

  return (
    <div className="g7le-flex-editor" data-testid="g7le-flex-editor" style={{ display: 'flex', flexDirection: 'column', gap: 12 }}>
      {showEnableButton && (
        <button type="button" data-testid="g7le-flex-enable" onClick={enableFlex} style={enableBtn}>
          {t('layout_editor.flex.make_flex')}
        </button>
      )}

      {showContainer && containerKeys.length > 0 && (
        <div className="g7le-flex-container-section" data-testid="g7le-flex-container-section">
          <div style={sectionTitle}>{t('layout_editor.flex.container_title')}</div>
          {renderControls(containerKeys, CONTAINER_LABELS, 'g7le-flex-container-controls')}
          {showDisableButton && (
            <button type="button" data-testid="g7le-flex-disable" onClick={disableFlex} style={disableBtn}>
              {t('layout_editor.flex.unmake_flex')}
            </button>
          )}
        </div>
      )}

      {role === 'item' && itemKeys.length > 0 && (
        <div className="g7le-flex-item-section" data-testid="g7le-flex-item-section">
          <div style={sectionTitle}>{t('layout_editor.flex.item_title')}</div>
          {renderControls(itemKeys, ITEM_LABELS, 'g7le-flex-item-controls')}
        </div>
      )}

      {!showContainer && !showEnableButton && role !== 'item' && (
        <div data-testid="g7le-flex-empty" style={emptyNotice}>
          {t('layout_editor.flex.not_flex')}
        </div>
      )}
    </div>
  );
}

const sectionTitle: React.CSSProperties = { fontSize: 12, fontWeight: 700, color: '#0f172a', marginBottom: 6 };
const enableBtn: React.CSSProperties = { padding: '8px 12px', fontSize: 12, border: '1px dashed #2563eb', borderRadius: 6, background: '#eff6ff', color: '#2563eb', cursor: 'pointer' };
const disableBtn: React.CSSProperties = { marginTop: 8, padding: '6px 10px', fontSize: 12, border: '1px solid #e2e8f0', borderRadius: 6, background: '#f8fafc', color: '#64748b', cursor: 'pointer' };
const emptyNotice: React.CSSProperties = { fontSize: 12, color: '#94a3b8', padding: '16px 0', textAlign: 'center' };
