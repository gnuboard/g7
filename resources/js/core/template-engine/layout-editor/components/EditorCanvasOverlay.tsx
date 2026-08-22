// e2e:allow 레이아웃 편집기 캔버스 오버레이 — 합성 더블클릭/칩 드래그/contentEditable 의존으로 Playwright 자동화 부적합, Chrome MCP 매트릭스 + 단위(useInlineEdit/inlineBindingApi/EditorCanvasOverlay.history)로 검증 (InlineParamChipEditor.tsx 와 동일 정책)
/**
 * EditorCanvasOverlay.tsx — 편집기 캔버스 위에 떠 있는 선택/팔레트/오버레이 합성 컨테이너
 *
 *
 * PreviewCanvas frame 위에 절대 위치로 마운트되어:
 *  - useElementSelection 으로 선택/hover componentPath 추적
 *  - ElementOverlay 로 hover 점선/선택 실선 + ⓘ + 잠금 어포던스 + 네비 어포던스
 *  - useInsertionPoints + InsertionAffordances 로 4방향 + 버튼
 *  - ComponentPalette 로 요소 추가
 *  - 컨텍스트 메뉴의 복사/삭제 → useLayoutDocument.patchLayout 호출
 *
 * `componentRegistry`/`pathParser` 등 외부에서 props 로 받지 않고 본 컴포넌트
 * 내부에서 직접 hook 호출 — Provider/Context 단일 진입점 보장.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { useLayoutEditor } from '../LayoutEditorContext';
import type { RouteTreeNode } from '../LayoutEditorContext';
import { useLayoutDocumentContext } from '../LayoutDocumentContext';
import {
  useElementSelection,
  parseEditorPath,
  isContextMenuAllowed,
} from '../hooks/useElementSelection';
import { useInsertionPoints, type InsertionPoint } from '../hooks/useInsertionPoints';
import { useEditorHistory } from '../hooks/useEditorHistory';
import { useUndoRedoShortcuts } from '../hooks/useUndoRedoShortcuts';
import {
  measureOverlay,
  subscribeOverlayTracking,
  boxIntersectsFrame,
  type OverlayBox,
} from '../utils/overlayGeometry';
import {
  duplicateNode as duplicateNodeUtil,
  insertNode,
  removeNode,
  separateBranch,
  mergeBranch,
  serializeEditorPath,
  isResponsiveSegment,
  type EditorNode,
  type NodeSource,
  type ComponentPath,
} from '../utils/layoutTreeUtils';
import { ElementOverlay } from './ElementOverlay';
import { InsertionAffordances } from './InsertionAffordances';
import { DndCanvasLayer } from './DndCanvasLayer';
import { SourceLockDimLayer } from './SourceLockDimLayer';
import {
  ComponentPalette,
  type ComponentManifest,
} from './ComponentPalette';
import { resolveGlobalInsertionTarget } from '../dnd/nestingRules';
import type { NestingSpec, ComponentPaletteSpec, EditorSpec } from '../spec/specTypes';
import {
  buildPageCandidates,
  buildDataSourceCandidates,
  buildStateKeyCandidates,
} from '../spec/candidatePools';
import { collectIsolatedScopes, buildScopeIdCandidates } from '../spec/isolatedScopeUtils';
import { useEditorModal } from '../EditorModalContext';
import { PropertyEditorModal } from './PropertyEditorModal';
import {
  COMPONENT_TARGET_PICK_REQUEST_EVENT,
  COMPONENT_TARGET_PICKED_EVENT,
} from './property-controls/ComponentTargetPicker';
import { deviceToBreakpoint, breakpointKeyLabel, resolveBranchSeparationMode, type StyleScope } from '../spec/styleScope';
import { collectDefinedDeviceBranches } from '../spec/deviceList';
import { useTranslation } from '../../TranslationContext';
import { findNodeByPath, patchNode, resolveBaseEditTarget } from '../utils/layoutTreeUtils';
import { trackEditorPropertyPatch, trackEditorDocument } from '../devtools/editorTrackers';
import { saveInjectedPropsToExtension } from '../utils/injectedPropsCrossSave';
import { getControl, getComponentCapability } from '../spec/editorSpecLoader';
import { getCanvasOverlay } from '../spec/canvasOverlayRegistry';
import {
  type BindingCandidate,
  buildBindingCandidates,
  buildArrayItemFieldsLookup,
  collectIterationVars,
} from '../spec/bindingCandidates';
import { useBindingCandidates } from '../hooks/useBindingCandidates';
import { useEditorShortcuts, type ShortcutHandlers } from '../hooks/useEditorShortcuts';
import { writeClipboard, readClipboard } from '../utils/editorClipboard';
import { useResizeHandles, type ResizeHandleKey } from '../hooks/useResizeHandles';
import { useDevicePreview } from '../hooks/useDevicePreview';
import { EDIT_LOCK_DIM } from '../utils/overlayZIndex';
import { resolveEditorTargetElement } from '../utils/resolveEditorTarget';
import { InlineTextEditor } from './InlineTextEditor';
import { InlineParamChipEditor } from './InlineParamChipEditor';
import { InlineTextToolbar } from './InlineTextToolbar';
import { useInlineEdit, EDITOR_TRANSLATIONS_REFRESHED_EVENT } from '../hooks/useInlineEdit';
import { insertBindingIntoParamKey, keyifyWithNewBinding, disconnectParamAllLocales } from './property-controls/inlineBindingApi';
import { extractParamBindings, removeParamBinding } from '../spec/inlineBindingUtils';
import { getPendingValue } from '../hooks/pendingCustomTranslations';
import { trackEditorI18n } from '../devtools/editorTrackers';

export interface EditorCanvasOverlayProps {
  /** frame DOM (PreviewCanvas 가 ref 로 전달) */
  frameEl: HTMLElement | null;
  /** 편집 대상 템플릿의 컴포넌트 매니페스트 (components.json) */
  manifest: ComponentManifest | null;
  /** editor-spec 의 nesting 블록 */
  nesting: NestingSpec | null | undefined;
  /** editor-spec 의 componentPalette 블록 — 그룹 정의 + 친화 라벨  */
  componentPalette?: ComponentPaletteSpec | null;
  /** 병합 editor-spec 전체 — 속성 편집 모달이 componentCapabilities/controls 조회  */
  spec?: EditorSpec | null;
  /** 권한 키 후보 (속성 모달 고급 탭 permissions TagInput — a-2) */
  permissionCandidates?: Array<{ value: string; label: string }>;
  /** 라우트 경로 → 내부 라우트 트리 매칭 결과 */
  resolveRouteMatch?: (path: string) => 'route_in_tree' | 'route_not_in_tree';
  /** "→ 이 화면 편집" 클릭 시 호출 (라우트 매칭 가능 시) */
  onNavigateToDestination?: (destinationPath: string) => void;
  /**
   * 본 세션에서 새로 추가된 노드 path 누적 (save 가드 입력).
   *  부터 placeholder 시각화는 회수되었으나, 비활성 확장 자식 신규 추가
   * 차단(blocked_inactive_extension) 가드에 여전히 본 trace 가 필요하다.
   */
  onSessionAddedPathsChange?: (paths: string[]) => void;
  /** Context Menu "속성 설정" — Phase 4 위임 (현재는 stub) */
  onEditProps?: (componentPath: string) => void;
}

export function EditorCanvasOverlay(props: EditorCanvasOverlayProps): React.ReactElement | null {
  const {
    frameEl,
    manifest,
    nesting,
    componentPalette,
    spec = null,
    permissionCandidates,
    resolveRouteMatch,
    onNavigateToDestination,
    onSessionAddedPathsChange,
    onEditProps,
  } = props;

  const { state, dispatch } = useLayoutEditor();
  const { t: chromeT } = useTranslation();
  // 디바이스 미리보기 컨트롤 — scale(프레임 축소) + setDevice(디바이스 구성 점프 후속).
  const deviceControls = useDevicePreview();

  // 편집 대상 템플릿 사전 우선 해석 t — 속성 편집 모달의
  // 컨트롤/컴포넌트 라벨은 편집 대상 템플릿(예: sirsoft-basic)의 `editor.*` 키다.
  // 편집기 chrome 은 admin 템플릿 컨텍스트로 렌더되어 chromeT 가 `editor.*` 를
  // 해석하지 못한다(`layout_editor.*` 는 코어 사전이라 해석됨). PreviewCanvas 가
  // `window.G7Core.t` 를 편집 대상 사전 fallback 체인으로 swap 해 두므로, 그것을 우선
  // 사용하고 미해석 시 chromeT 로 폴백한다.
  const editorAwareT = useCallback(
    (key: string, params?: Record<string, string | number>): string => {
      const g7 = (window as { G7Core?: { t?: (k: string, p?: Record<string, string | number>) => string } }).G7Core;
      if (g7 && typeof g7.t === 'function') {
        const resolved = g7.t(key, params);
        // 해석 성공(키 원문/`$t:` 접두 미반환) 시 사용
        if (resolved && resolved !== key && !resolved.startsWith('$t:')) return resolved;
      }
      return chromeT(key, params);
    },
    [chromeT],
  );
  const docCtx = useLayoutDocumentContext();
  const history = useEditorHistory<EditorNode[]>(50);
  const modal = useEditorModal();
  const sessionAddedPathsRef = useRef<string[]>([]);

  // 루트 노드 — 가상 컨테이너로 components 배열을 children 으로 wrapping
  const components = useMemo<EditorNode[]>(() => {
    return (docCtx?.document?.raw?.components as EditorNode[] | undefined) ?? [];
  }, [docCtx?.document?.raw?.components]);
  const rootNode = useMemo<EditorNode | null>(
    () => (components.length === 0 ? null : { children: components }),
    [components]
  );

  // 확장 편집 모드일 때 편집 중인 확장 PK — selectedRoute.path 의 `__extension__/{id}`
  // 에서 추출(ENTER_EXTENSION_EDIT). 그 외 모드는 undefined.
  // 편집 중 확장 노드는 그 모드에서 자유 편집 대상이므로 잠금·통짜 정규화에서 제외된다.
  const currentExtensionId = useMemo<number | undefined>(() => {
    if (state.editMode !== 'extension') return undefined;
    const m = /^__extension__\/(\d+)/.exec(state.selectedRoute?.path ?? '');
    return m ? Number(m[1]) : undefined;
  }, [state.editMode, state.selectedRoute?.path]);

  // path 기반 편집 대상 모드(iteration_item / modal)의 편집 가능 노드 트리 경로.
  // SourceLockDimLayer 가 이 노드 박스만 구멍으로 노출하고 나머지 호스트를 음영으로 덮는다.
  //  - iteration_item: 호스트 트리의 iteration 원본 노드(iterationContext.sourceIndexPath).
  //  - modal: 호스트 components 트리에 인플레이스로 렌더된 모달 노드 경로(modalContext.editIndexPath).
  const editableRootPath = useMemo<number[] | null>(() => {
    if (state.editMode === 'iteration_item') {
      const p = docCtx?.document?.iterationContext?.sourceIndexPath;
      return Array.isArray(p) && p.length > 0 ? p : null;
    }
    if (state.editMode === 'modal') {
      const p = docCtx?.document?.modalContext?.editIndexPath;
      return Array.isArray(p) && p.length > 0 ? p : null;
    }
    return null;
  }, [
    state.editMode,
    docCtx?.document?.iterationContext?.sourceIndexPath,
    docCtx?.document?.modalContext?.editIndexPath,
  ]);

  // 선택/hover 매핑
  const selection = useElementSelection({
    rootNode,
    editMode: state.editMode,
    currentExtensionId,
    editableRootPath,
    pathParser: parseEditorPath,
    resolveRouteMatch,
  });

  // 선택/hover 박스 좌표
  const [hoverBox, setHoverBox] = useState<OverlayBox | null>(null);
  const [selectedBox, setSelectedBox] = useState<OverlayBox | null>(null);

  // 컴포넌트 영역 pick 모드 — ComponentTargetPicker(🎯 영역 선택)가
  // `g7le:component-target-pick-request` 를 발사하면 그 requestId 를 보관해 pick 모드 진입.
  // 이 모드 동안 캔버스 클릭은 일반 선택 대신 "그 노드의 id 회신"으로 분기(아래 onClick pick
  // 분기). id 미부여 노드 클릭은 회신하지 않고 안내(선택 불가). Esc 로 취소.
  const [pickRequestId, setPickRequestId] = useState<string | null>(null);
  const pickRequestIdRef = useRef<string | null>(null);
  pickRequestIdRef.current = pickRequestId;
  // pick 모드 hover — 박스 + 그 노드가 id 보유(선택 가능)인지. id 미부여면 "ID 필요" 안내(선택 불가).
  const [pickHover, setPickHover] = useState<{ box: OverlayBox; hasId: boolean } | null>(null);
  // pick 시작 직전의 캔버스 선택 path — pick 종료 시 이걸로 **복원**한다(: 영역 선택을
  // 마치면 원래 편집하던 컴포넌트가 다시 선택돼야 한다). pick 클릭이 캔버스 노드 선택을 일으켜도
  // 이 값으로 되돌려, "고른 노드가 선택돼버림"도 "선택이 통째로 사라짐"도 막는다.
  const pickPrevSelectionRef = useRef<string | null>(null);
  // 현재 선택 path 미러 — onPickRequest effect(deps=[finishPick]) 가 stale 없이 읽도록 ref 화.
  const selectedPathRef = useRef<string | null>(null);
  selectedPathRef.current = selection.selectedPath;

  // 인라인 텍스트 편집 — 더블클릭한 텍스트 노드의 path/박스/분류를 보관.
  // null = 비편집. useInlineEdit 가 평문→키 생성 / 기존 키 값 수정을 수행한다.
  const inlineEdit = useInlineEdit();
  const [inlineEditing, setInlineEditing] = useState<{
    path: ComponentPath;
    /**
     * 캔버스 DOM `data-editor-path` 원문(`2.children.5.children…`). `path`(파싱된 ComponentPath)
     * 를 `.join('.')` 하면 `.children.` 세그먼트가 빠진 `2.5.0…` 이 되어 DOM selector 와
     * 불일치한다 — 미러 querySelector 는 반드시 이 원문을 써야 노드를 찾는다(
     * — 미러가 빈 채로 남던 결함의 근본 원인: 파싱 path 로 selector 를 재구성).
     */
    domPath: string;
    box: OverlayBox;
    initialValue: string;
    isCustomKey: boolean;
    /**
     * param 부착 키(`$t:custom.X|pN={{}}`) 여부 + 키. true 면 InlineParamChipEditor
     * (칩 합성 위젯)로 분기해 평문은 편집·보간은 드래그 칩으로 표시한다. customKey 는 그 키.
     */
    isParamKey?: boolean;
    customKey?: string | null;
    /**
     * 데이터 든 **미키화** 노드(plain_with_binding)의 파생 칩 값/라벨(칩 온
     * 엔트리). chipValue 가 있으면 칩 편집기로 분기해 데이터를 칩으로 보이고, 내용 변경 시 키화한다.
     */
    chipValue?: string;
    chipParamLabels?: Record<string, string>;
    /**
     * 서식 적용마다 +1 — `inlineEditingNode` memo 의 재계산을 강제하는 버전 태그
     * docCtx.document 참조 변화만으로는 EditorCanvasOverlay 가 한 단계
     * stale 되어 툴바 active·편집 오버레이 미러가 서식 변경을 즉시 반영 못했다. 적용 시
     * 이 버전을 올려 memo dep(`inlineEditing`)을 바꿔 fresh 노드를 다시 읽게 한다.
     */
    formatVersion?: number;
    /**
     * 편집 오버레이 서식 미러의 **결정적 소스**. 서식 적용 시 patch 결과
     * 노드의 className/style 을 여기에 직접 담아, DOM/RAF 타이밍에 의존하지 않고 오버레이가
     * 즉시 같은 서식을 보이게 한다(캔버스 DOM 비동기 갱신을 기다리던 미러 stale 해소).
     * 진입 시엔 편집 대상 노드의 현재 className/style 로 초기화한다.
     */
    mirrorClassName?: string;
    mirrorStyle?: React.CSSProperties;
    /**
     * 편집 대상 노드의 캔버스 내 실제 글자색(computed) — 인라인 오버레이의 대비 배경/글자색
     * 판정 소스. 오버레이는 다크 컨텍스트(`.g7le-preview-dark`) 밖에 마운트되어
     * `dark:` 토큰이 발동하지 않으므로, 다크 모드 흰 글자 노드를 자체 computed 로 읽으면 색이
     * 어긋난다. 대상 노드 자체(다크 컨텍스트 안)의 색을 측정해 넘기면 oklch/rgb 무관하게
     * 브라우저가 정규화한 값을 받아 대비를 정확히 판정하고 캔버스와 같은 색으로 보인다.
     */
    nodeEffectiveColor?: string;
  } | null>(null);

  // 인라인 편집 진입 요청 — 더블클릭(프레임 위임) + 드래그 핸들 더블클릭(DndCanvasLayer)
  // 양쪽이 공유하는 단일 진입점. 실제 마우스 더블클릭은 노드 위를 덮은 드래그 핸들에
  // 먼저 맞으므로,
  // 핸들도 본 콜백으로 같은 진입을 호출한다. 편집 불가 노드는 트래커만 남기고 무시.
  const requestInlineEditAt = useCallback(
    (pathStr: string): void => {
      if (!frameEl) return;
      const pathIdx = parseEditorPath(pathStr);
      if (!pathIdx) return;
      const root: EditorNode = { children: liveDataRef.current.components };
      const node = findNodeByPath(root, pathIdx);
      const cls = inlineEdit.classify(node);
      if (!cls.editable) {
        trackEditorI18n({
          op: 'inline_edit_blocked_binding',
          sourceState: cls.sourceState,
          componentPath: serializeEditorPath(pathIdx),
          timestamp: Date.now(),
        });
        return;
      }
      const box = measurePathBox(frameEl, pathStr);
      if (!box) return;
      // 진입 시 미러를 편집 대상 노드의 현재 className/style 로 초기화(결정적 소스).
      const nodeProps = (node?.props ?? {}) as Record<string, unknown>;
      // 편집 대상 노드의 캔버스 내 실제 글자색(computed)을 읽는다. 인라인 오버레이는
      // 다크 컨텍스트 밖에 마운트되므로 자체 computed 로는 `dark:` 토큰이 미발동해 색이 어긋난다.
      // 대상 노드(다크 컨텍스트 안)의 색을 측정하면 oklch/rgb 무관하게 브라우저가 정규화한 값을
      // 받아 대비 배경을 정확히 판정하고 캔버스와 같은 색으로 보인다.
      const nodeEffectiveColor = measureNodeColor(frameEl, pathStr);
      setInlineEditing({
        path: pathIdx,
        domPath: pathStr,
        box,
        initialValue: cls.displayValue,
        isCustomKey: cls.sourceState === 'custom_key',
        isParamKey: cls.isParamKey === true,
        customKey: cls.customKey,
        // 데이터 든 미키화 노드(plain_with_binding) — 칩 온 엔트리. 파생 칩 값/라벨이
        // 있으면 칩 편집기로 분기해 데이터를 칩으로 보인다(키 생성은 내용 변경 시).
        chipValue: cls.chipValue,
        chipParamLabels: cls.chipParamLabels,
        mirrorClassName: typeof nodeProps.className === 'string' ? (nodeProps.className as string) : undefined,
        mirrorStyle: (nodeProps.style as React.CSSProperties | undefined) ?? undefined,
        nodeEffectiveColor,
      });
      trackEditorI18n({
        op: 'inline_edit_enter',
        sourceState: cls.sourceState,
        componentPath: serializeEditorPath(pathIdx),
        timestamp: Date.now(),
      });
    },
    [frameEl, inlineEdit],
  );

  // 코어 placeholder 오버레이는 회수됨. 신규 노드 시각화는 템플릿의
  // `editorSpec.componentPalette.entries[name].defaultNode` 가 책임진다.
  // `data-editor-empty` 마커도 미사용 (작업 범위 — DynamicRenderer 회귀 가드).

  const refreshBoxes = useCallback(() => {
    if (!frameEl) {
      setHoverBox(null);
      setSelectedBox(null);
      return;
    }
    setSelectedBox(measurePathBox(frameEl, selection.selectedPath));
    setHoverBox(measurePathBox(frameEl, selection.hoverPath));
    // `components` 는 직접 사용하지 않지만 dep 으로 두어 트리 변경(리사이즈/속성/드롭) 후
    // DOM 이 갱신되면 선택/hover 박스를 재측정하게 한다. (100% 폭 요소를
    // 가로 축소하면 frame 크기는 불변이라 ResizeObserver(frame 부착)가 발화하지 않고,
    // selectedPath 도 그대로라 선택 박스가 옛 크기로 남던 결함. 다른 요소 선택 후 재선택
    // 해야만 갱신되던 현상.)
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [frameEl, selection.selectedPath, selection.hoverPath, components]);

  useEffect(() => {
    // 트리 변경 후 React 가 DOM 을 커밋한 다음 측정하도록 다음 프레임에 재측정한다.
    // patchLayout → components 변경 → DynamicRenderer 재렌더가 같은 커밋에 반영되지만,
    // 일부 컴포넌트는 자식 레이아웃 확정이 한 프레임 늦으므로 RAF 한 번으로 안정화.
    refreshBoxes();
    if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
      const id = window.requestAnimationFrame(() => refreshBoxes());
      return () => window.cancelAnimationFrame(id);
    }
    return undefined;
  }, [refreshBoxes]);

  useEffect(() => {
    if (!frameEl) return;
    return subscribeOverlayTracking(refreshBoxes, frameEl);
  }, [frameEl, refreshBoxes]);

  // 컴포넌트 영역 pick 모드 — picker 회신 + 모드 종료. cancelled 면 id 없이 회신.
  const finishPick = useCallback(
    (requestId: string, id: string | null): void => {
      if (typeof window === 'undefined') return;
      window.dispatchEvent(
        new CustomEvent(COMPONENT_TARGET_PICKED_EVENT, {
          detail: id === null ? { requestId, cancelled: true } : { requestId, id },
        }),
      );
      setPickRequestId(null);
      setPickHover(null);
      // pick 의 결과는 "요소 id 회신"일 뿐 노드 선택이 아니다. 그런데 pick 클릭이 노드 DOM 의
      // editorOnClick(onComponentSelect, 버블 단계)에도 닿아 고른 노드가 캔버스에서 선택돼 버린다.
      // 종료 시 pick **시작 직전 선택으로 복원**한다("원래 편집하던 컴포넌트가
      // 다시 선택돼야 한다"). 고른 노드 부수 선택 제거 + 원래 선택 유지를 동시에 만족.
      const prevSel = pickPrevSelectionRef.current;
      if (prevSel) {
        selection.handleSelect('', { dataset: { editorPath: prevSel } as DOMStringMap });
      } else {
        selection.clearSelection();
      }
      pickPrevSelectionRef.current = null;
    },
    [selection],
  );

  // pick-request 수신 — ComponentTargetPicker(🎯) 가 발사. requestId 보관 후 pick 모드 진입.
  // 이미 다른 pick 진행 중이면 그것을 취소(cancelled)하고 새 요청으로 교체(중첩 방지).
  useEffect(() => {
    if (typeof window === 'undefined') return;
    const onPickRequest = (e: Event): void => {
      const detail = (e as CustomEvent).detail as { requestId?: string };
      if (typeof detail?.requestId !== 'string') return;
      const prev = pickRequestIdRef.current;
      if (prev && prev !== detail.requestId) finishPick(prev, null);
      // pick 시작 직전 선택을 기억 — 종료 시 이걸로 복원(원래 편집하던 컴포넌트 유지).
      pickPrevSelectionRef.current = selectedPathRef.current;
      setPickRequestId(detail.requestId);
    };
    window.addEventListener(COMPONENT_TARGET_PICK_REQUEST_EVENT, onPickRequest);
    return () => window.removeEventListener(COMPONENT_TARGET_PICK_REQUEST_EVENT, onPickRequest);
  }, [finishPick]);

  // pick 모드 중 Esc → 취소 회신(모달 복원).
  useEffect(() => {
    if (!pickRequestId) return;
    const onKey = (e: KeyboardEvent): void => {
      if (e.key === 'Escape') {
        e.preventDefault();
        finishPick(pickRequestId, null);
      }
    };
    window.addEventListener('keydown', onKey, true);
    return () => window.removeEventListener('keydown', onKey, true);
  }, [pickRequestId, finishPick]);

  // 캔버스 위임 이벤트 — DynamicRenderer 의 PreviewCanvas onComponentSelect 가
  // 빈 함수라 dataset 만 부여하고 끝남. 본 위임이 click/mousemove 를 가로채
  // 가장 가까운 [data-editor-path] 노드에서 selection.handleSelect/handleHover
  // 를 호출한다. capture 단계로 react 핸들러보다 먼저 잡고 stopPropagation 하면
  // 액션 발동이 차단된다 (DynamicRenderer 의 onClick stopPropagation 과 보완).
  useEffect(() => {
    if (!frameEl) return;

    // 클릭/더블클릭 좌표에서 편집 대상 노드를 찾는다 — resolveEditorTarget SSoT.
    // 핸들 testid 역추출 폴백은 **반복 항목/모달 편집 모드 전용**(개별 핸들이 텍스트를 덮어 내부
    // 노드 선택이 막히는 걸 해소). route(호스트) 모드는 끈다 — 거기서 iteration 묶음 핸들 클릭은
    // 반복 영역을 **통짜로 선택**해야 하므로, 핸들→내부 노드 역질의를 적용하면 안 됨.
    const handleFallback =
      state.editMode === 'iteration_item' || state.editMode === 'modal';
    const findEditorElement = (
      target: EventTarget | null,
      e?: MouseEvent,
    ): HTMLElement | null =>
      resolveEditorTargetElement(
        target,
        frameEl,
        e ? { x: e.clientX, y: e.clientY } : undefined,
        { handleFallback },
      );

    // pick 모드 — DOM 노드 엘리먼트 → **유저가 레이아웃에서 직접 부여한** 표시 id 추출.
    // 없으면 ''(선택 불가). onClick(회신)·onMouseMove(hover 강조/안내)가 공유한다.
    //
    // 편집기/렌더러가 자동 부여한 `auto_…` id(DynamicRenderer 가 id 없는 노드에 안정 추적용으로
    // 주입)는 **직접 부여가 아니므로 제외**한다(: "유저가 직접 id 를 부여한 요소만
    // 연결 대상"). 그렇지 않으면 모든 노드가 auto id 를 갖게 되어 사실상 전부 선택 가능해진다.
    const pickNodeIdOf = (el: HTMLElement): string => {
      const pathStr = el.dataset.editorPath ?? '';
      const path = pathStr ? parseEditorPath(pathStr) : null;
      const pickRoot: EditorNode = { children: liveDataRef.current.components };
      const targetNode = path ? findNodeByPath(pickRoot, path) : null;
      const rawId =
        targetNode && typeof (targetNode.props as Record<string, unknown> | undefined)?.id === 'string'
          ? ((targetNode.props as Record<string, unknown>).id as string)
          : typeof targetNode?.id === 'string'
            ? (targetNode.id as string)
            : '';
      // 자동 부여 id 는 직접 부여로 치지 않는다(선택 불가).
      return rawId.startsWith('auto_') ? '' : rawId;
    };

    const onClick = (e: MouseEvent): void => {
      const el = findEditorElement(e.target, e);
      if (!el) return;
      // pick 모드 — 일반 선택 대신 그 노드의 id 를 picker 에 회신. id 미부여
      // 노드는 회신하지 않고(모드 유지) 안내(pickMode 오버레이가 "ID 부여 필요" 호버). ref 로
      // 최신 pickRequestId 를 읽어 effect 재등록(=리스너 재바인딩)을 피한다(회귀 안전).
      const activePick = pickRequestIdRef.current;
      if (activePick) {
        e.preventDefault();
        e.stopPropagation();
        // id 보유 노드만 회신 — 미부여 노드 클릭은 무시(모드 유지, 사용자가 다른 노드 선택).
        const nodeId = pickNodeIdOf(el);
        if (nodeId) finishPick(activePick, nodeId);
        return;
      }
      // capture 단계 — 액션/네비 차단
      e.preventDefault();
      e.stopPropagation();
      selection.handleSelect(el.dataset.editorId ?? '', {
        currentTarget: el,
        dataset: el.dataset,
      } as any);
    };

    const onMouseMove = (e: MouseEvent): void => {
      const el = findEditorElement(e.target, e);
      // pick 모드 — 일반 hover 선택 위임을 끄고 pick 전용 hover.
      // id 보유 노드만 강조(선택 가능), id 없는 노드는 "ID 필요" 안내(선택 불가). 일반 selection
      // hover 는 호출하지 않는다(어포던스/실선이 pick 모드에서 안 뜨게).
      if (pickRequestIdRef.current) {
        if (!el) { setPickHover(null); return; }
        const box = measurePathBox(frameEl, el.dataset.editorPath ?? null);
        if (!box) { setPickHover(null); return; }
        setPickHover({ box, hasId: pickNodeIdOf(el) !== '' });
        return;
      }
      if (!el) {
        selection.handleHover(null, {} as any);
        return;
      }
      selection.handleHover(el.dataset.editorId ?? '', {
        currentTarget: el,
        dataset: el.dataset,
      } as any);
    };

    const onMouseLeave = (): void => {
      if (pickRequestIdRef.current) { setPickHover(null); return; }
      selection.handleHover(null, {} as any);
    };

    // 더블클릭 → 인라인 텍스트 편집 진입. 가장 가까운 [data-editor-path] 노드의
    // path 를 파싱해 소스 트리 노드를 분류한다. 편집 가능(평문/커스텀 키)이면 InlineTextEditor
    // 를 그 박스 위에 띄운다. 잠금/데이터결정/바인딩식 노드는 진입하지 않는다.
    const onDblClick = (e: MouseEvent): void => {
      const el = findEditorElement(e.target, e);
      if (!el) return;
      const pathStr = el.dataset.editorPath ?? '';
      if (!pathStr) return;
      e.preventDefault();
      e.stopPropagation();
      // 잠금/바인딩/데이터결정 판정 + 박스 측정 + 진입은 공유 진입점이 수행한다.
      requestInlineEditAt(pathStr);
    };

    // 리스너 등록 대상 = frame 의 부모 래퍼(`g7le-preview-frame-wrapper`).
    //
    // 종전엔 frameEl(`g7le-preview-frame`, 콘텐츠 트리)에만 등록했으나, dnd 핸들/딤은 **형제
    // 레이어**(`g7le-editor-canvas-overlay`) 에 렌더돼 frame 의 자손이 아니다. 따라서 핸들 위에서
    // 발생한 click/dblclick 은 frame 의 capture 리스너에 **도달하지 못해** 선택·인라인편집이
    // 막혔다(개별 핸들이 텍스트를 덮는 반복 항목 편집 모드에서 특히). 공통 조상인 wrapper 에
    // 등록하면 frame 콘텐츠 직접 클릭과 오버레이(핸들) 클릭을 모두 capture 단계에서 잡고,
    // findEditorElement 의 좌표 폴백이 frame 안의 실제 노드로 위임한다.
    const listenerTarget: HTMLElement = frameEl.parentElement ?? frameEl;
    listenerTarget.addEventListener('click', onClick, true);
    listenerTarget.addEventListener('mousemove', onMouseMove, true);
    listenerTarget.addEventListener('mouseleave', onMouseLeave, true);
    listenerTarget.addEventListener('dblclick', onDblClick, true);
    return () => {
      listenerTarget.removeEventListener('click', onClick, true);
      listenerTarget.removeEventListener('mousemove', onMouseMove, true);
      listenerTarget.removeEventListener('mouseleave', onMouseLeave, true);
      listenerTarget.removeEventListener('dblclick', onDblClick, true);
    };
  }, [frameEl, selection, requestInlineEditAt, state.editMode, finishPick]);

  // 부모 선택(겹친 부모 escalation) — 캔버스 클릭은 `closest('[data-editor-path]')` 로 늘
  // 가장 깊은 자식을 잡으므로, 부모/자식 크기가 같아 자식만 잡히는 경우 부모를 선택할 수
  // 없다(상용 편집기 공통 — 부모는 별도 어포던스). 선택 path 의 마지막 `.children.N` 세그먼트를
  // 떼어 한 단계 상위 노드를 선택한다. 부모가 없으면(루트 직계 = `N` 또는 빈 path) null 반환 →
  // 칩이 클릭 불가 라벨로 디그레이드. handleSelect 가 확장 조각 진입점 정규화를 함께 수행한다.
  const parentPathStr = useMemo<string | null>(() => {
    const p = selection.selectedPath;
    if (!p) return null;
    // DOM path 형식: `2`(루트 직계) / `2.children.5.children.0`(중첩). 마지막 `.children.N` 제거.
    const m = p.match(/^(.*)\.children\.\d+$/);
    return m ? m[1]! : null;
  }, [selection.selectedPath]);

  const handleSelectParent = useCallback((): void => {
    if (!parentPathStr) return;
    selection.handleSelect('', { dataset: { editorPath: parentPathStr } as DOMStringMap });
  }, [parentPathStr, selection]);

  // 캔버스 단축키(복사/잘라내기/붙여넣기/삭제/속성/부모선택/해제)는 중앙 키맵
  // (editorShortcuts.ts SSoT)로 useEditorShortcuts 가 디스패치한다 — 아래 shortcutHandlers
  // 정의 + 훅 호출(파일 하단). 종전 ad-hoc ↑/Esc keydown effect 는 그 훅으로 흡수됨
  // (입력/모달 가드·Esc deselect/exit 우선순위는 훅이 일원 처리).

  // 컨텍스트 + 버튼 — 선택 노드의 부모 DOM 기반 + 선택 노드 DOM (시각 형제 매핑용)
  const selectedDomEl = useMemo<HTMLElement | null>(() => {
    if (!frameEl || !selection.selectedPath) return null;
    return frameEl.querySelector(
      `[data-editor-path="${cssEscape(selection.selectedPath)}"]`
    ) as HTMLElement | null;
  }, [frameEl, selection.selectedPath]);

  const selectedPathIndexes = useMemo(
    () => (selection.selectedPath ? parseEditorPath(selection.selectedPath) : null),
    [selection.selectedPath]
  );

  // 디바이스 분기 배지 라벨 — 선택 path 에 responsive 세그먼트가 있으면
  // 그 분기 키로 "모바일 구성" 등 라벨을 만든다. base 노드면 null(배지 미표시).
  const selectedBranchLabel = useMemo<string | null>(() => {
    if (!selectedPathIndexes) return null;
    const branchSeg = selectedPathIndexes.find(isResponsiveSegment) as
      | { responsive: string }
      | undefined;
    if (!branchSeg) return null;
    const { i18nKey, raw } = breakpointKeyLabel(branchSeg.responsive);
    return i18nKey ? chromeT(i18nKey) : chromeT('layout_editor.overlay.branch_custom', { range: raw });
  }, [selectedPathIndexes, chromeT]);

  // ─── 잠긴 영역 "요소 추가"(+) anchor ──────────────────────
  // "요소 추가"는 잠긴 노드 자체를 변형하는 게 아니라 그 **다음에(형제로)** 새 요소를
  // 추가하는 작업이라 소유권 경계를 침해하지 않는다. 잠금 종류별로 "안전한 형제 기준
  // 노드(anchor)"가 다르다:
  //  - extension/extension_point/base/partial: 잠긴 노드는 캔버스에 **단일 박스**로
  //    렌더되므로 그 노드 자신이 anchor (선택 박스 = 묶음 박스). 그 부모(라우트 소유)
  //    children 에 형제로 삽입 → 저장 마스킹이 신규 노드를 route 콘텐츠로 보존.
  //  - data_bound 이터레이션: 캔버스에 보이는 건 펼쳐진 인스턴스(`.iteration.N`)뿐이고
  //    반복 **정의 노드**는 자체 DOM 이 없다. 인스턴스 옆 형제 삽입은 원본 itemTemplate
  //    에 매핑 불가(가상 인덱스)하므로, anchor = **반복 정의 노드**(selectedIterationSourcePath).
  //    + 버튼은 그 정의 노드의 인스턴스 union 박스(= 데이터 영역 점선 박스) 경계에 뜨고,
  //    삽입은 정의 노드의 부모(라우트 소유) children 형제로 간다(반복 묶음 바깥).
  //  - none: anchor = 선택 노드 자체 (기존 동작 100% 동일).
  // anchor DOM path 는 selection 이 이미 제공하는 값으로 구한다(별도 트리 탐색 없음):
  //  - 이터레이션: selectedIterationSourcePath (`.iteration.` 직전까지 = 정의 노드 path)
  //  - 그 외: selection.selectedPath
  const insertionAnchorDomPath = useMemo<string | null>(() => {
    if (!selection.selectedPath) return null;
    if (selection.selectedIsIteration && selection.selectedIterationSourcePath) {
      return selection.selectedIterationSourcePath;
    }
    return selection.selectedPath;
  }, [selection.selectedPath, selection.selectedIsIteration, selection.selectedIterationSourcePath]);

  const insertionAnchorPathIndexes = useMemo<ComponentPath | null>(
    () => (insertionAnchorDomPath ? parseEditorPath(insertionAnchorDomPath) : null),
    [insertionAnchorDomPath]
  );

  // anchor DOM 요소 — 이터레이션 정의 노드는 자체 DOM 이 없을 수 있다(인스턴스만 렌더).
  // 그 경우 null 이며, flow 판정은 부모 DOM(아래)로 한다.
  const insertionAnchorDomEl = useMemo<HTMLElement | null>(() => {
    if (!frameEl || !insertionAnchorDomPath) return null;
    return frameEl.querySelector(
      `[data-editor-path="${cssEscape(insertionAnchorDomPath)}"]`
    ) as HTMLElement | null;
  }, [frameEl, insertionAnchorDomPath]);

  // anchor 의 부모 DOM — flow(상/하/좌/우 방향) 판정용. anchor 자체 DOM 이 있으면 그
  // parentElement, 없으면(이터레이션 정의 노드) 부모 path 의 DOM 요소를 직접 조회.
  const insertionAnchorParentEl = useMemo<HTMLElement | null>(() => {
    if (insertionAnchorDomEl?.parentElement) return insertionAnchorDomEl.parentElement;
    if (!frameEl || !insertionAnchorDomPath) return null;
    const m = insertionAnchorDomPath.match(/^(.*)\.children\.\d+$/);
    if (!m) return null;
    return frameEl.querySelector(
      `[data-editor-path="${cssEscape(m[1]!)}"]`
    ) as HTMLElement | null;
  }, [insertionAnchorDomEl, frameEl, insertionAnchorDomPath]);

  // 선택 노드의 컴포넌트 **타입 식별자** — 선택 박스 위 흐린 오버레이 라벨(
  // Div/Table/P/H1 등 실제 컴포넌트 타입을 식별). 친화 라벨("영역" 등)이 아니라
  // 노드의 컴포넌트 이름(name) 원명을 그대로 표시. 선택 시에만 계산(hover 는 미표시).
  const selectedTypeName = useMemo<string | null>(() => {
    const node = selection.selectedNode;
    if (!node) return null;
    const name = typeof node.name === 'string' ? node.name : typeof node.type === 'string' ? node.type : '';
    return name || null;
  }, [selection.selectedNode]);

  // 확장 잠금 노드의 출처 라벨 — "확장 편집" 어포던스에 어느 확장인지 표시.
  // 백엔드 markExtensionSource 가 부여한 로케일 표시명(`extensionName`) + 식별자
  // (`extensionIdentifier`)를 "표시명 (식별자)" 로 결합한다. 표시명이 없으면 식별자만,
  // 식별자도 없으면 출처 타입(module/plugin/template)으로 폴백한다.
  const selectedExtensionLabel = useMemo<string | null>(() => {
    if (selection.selectedLockKind !== 'extension') return null;
    const src = (selection.selectedNode as { __source?: NodeSource } | null)?.__source;
    if (!src || src.kind !== 'extension') return null;
    const name = src.extensionName?.trim();
    const id = src.extensionIdentifier?.trim();
    if (name && id) return `${name} (${id})`;
    return name || id || src.extensionSourceType || null;
  }, [selection.selectedLockKind, selection.selectedNode]);

  // 컨텍스트 + 버튼 flow 계산은 **삽입 anchor** 기준(잠긴 묶음/이터레이션 정의 노드로
  // 정규화된 위치). none 노드는 anchor === 선택 노드라 selectedParentEl/selectedDomEl 과
  // 동일 결과. selectedEl 이 null(이터레이션 정의 노드, 자체 DOM 없음)이면
  // useInsertionPoints 가 부모 flow(block) 폴백으로 상/하 형제 삽입을 계산한다.
  const insertion = useInsertionPoints({
    parentEl: insertionAnchorParentEl,
    selectedPath: insertionAnchorPathIndexes,
    selectedEl: insertionAnchorDomEl,
  });

  // + 버튼이 그려질 기준 박스 = anchor 박스. 이터레이션 정의 노드는 자체 DOM 이 없어
  // measurePathBox 가 인스턴스 union(= 데이터 영역 점선 박스)으로 폴백 측정한다. none/기타
  // 잠금은 선택 노드 박스와 동일. components dep 으로 트리 변경 후 재측정.
  const insertionAnchorBox = useMemo<OverlayBox | null>(() => {
    if (!frameEl || !insertionAnchorDomPath) return selectedBox;
    if (insertionAnchorDomPath === selection.selectedPath) return selectedBox;
    return measurePathBox(frameEl, insertionAnchorDomPath);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [frameEl, insertionAnchorDomPath, selectedBox, selection.selectedPath, components]);

  // 팔레트 — 컨텍스트+버튼 경로 (: 화면 중앙 모달).
  // 본 ref 는 같은 모달이 두 번 열리지 않게 + 닫기 호출에 사용.
  const contextPaletteModalIdRef = useRef<string | null>(null);
  const closeContextPalette = useCallback(() => {
    const id = contextPaletteModalIdRef.current;
    if (id) {
      modal.close(id);
      contextPaletteModalIdRef.current = null;
    }
  }, [modal]);

  // handleInsert / 최신 manifest/nesting/components 를 ref 로 보관 — modal content
  // 가 마운트될 때 캡처되는 함수가 stale 되지 않도록 (모달은 한 번 open() 으로 열린
  // 뒤 사용자가 닫을 때까지 살아 있다)
  const handleInsertRef = useRef<
    (newNode: EditorNode, parentPath: ComponentPath, index: number) => void
  >(() => {});
  const liveDataRef = useRef({ manifest, nesting, componentPalette, components });
  liveDataRef.current = { manifest, nesting, componentPalette, components };

  /** + 버튼 클릭 → 모달 형태로 팔레트 열기 */
  const handleAddRequest = useCallback(
    (point: InsertionPoint): void => {
      const parentPath = point.insertion?.parentPath ?? [];
      const insertionIndex = point.insertion?.index ?? 0;
      const live = liveDataRef.current;
      const targetContainerName = resolveContainerNameAt(live.components, parentPath);
      // 기존 모달이 떠 있으면 먼저 닫기 (같은 + 버튼 이중 클릭/다른 위치 클릭 모두 안전)
      closeContextPalette();
      const id = modal.open({
        ariaLabel: editorAwareT('layout_editor.palette.title'),
        width: 880,
        maxHeightRatio: 0.82,
        content: React.createElement(ComponentPalette, {
          manifest: live.manifest,
          nesting: live.nesting,
          componentPalette: live.componentPalette ?? null,
          targetParentPath: parentPath,
          targetContainerName,
          onInsert: (newNode, p, idx) => {
            handleInsertRef.current(newNode, p, idx);
          },
          insertionIndex,
          onClose: () => closeContextPalette(),
          editorTemplateId: state.templateIdentifier,
          editorLocale: state.locale,
        }),
        onClose: () => {
          contextPaletteModalIdRef.current = null;
        },
      });
      contextPaletteModalIdRef.current = id;
    },
    [modal, closeContextPalette]
  );

  // 글로벌 팔레트 — toolbar "+ 요소 추가" 가 dispatch 한 TOGGLE_PALETTE 로 열림.
  // 삽입 위치는 기본 규칙: 선택 노드의 children 끝(컨테이너) / 형제 다음 / 루트 끝.
  // 잠금 노드(data_bound/base/partial/extension/extension_point) 선택 시에는 그 노드
  // **내부**에 넣지 않고, 안전 anchor(insertionAnchorPathIndexes — 이터레이션은 반복
  // 정의 노드, 그 외는 잠긴 노드 자신)의 **형제 다음**으로 삽입한다(
  // "요소 추가는 그 다음에 또 다른 요소를 추가하는 작업"). anchor 의 부모는 라우트
  // 소유(none) 영역이라 저장 마스킹이 신규 노드를 route 콘텐츠로 보존한다.
  const globalInsertionTarget = useMemo(() => {
    if (!state.isPaletteOpen) return null;
    if (!selectedPathIndexes) {
      // 선택 없음 → 루트 끝
      return { parentPath: [] as number[], index: components.length };
    }
    const isLocked = selection.selectedLockKind !== 'none';
    if (isLocked) {
      // 잠금 노드 → 안전 anchor 의 형제 다음(after). anchor 없으면 루트 끝.
      const anchor = insertionAnchorPathIndexes;
      if (!anchor || anchor.length === 0) {
        return { parentPath: [] as number[], index: components.length };
      }
      return { parentPath: anchor.slice(0, -1), index: (anchor[anchor.length - 1] ?? 0) + 1 };
    }
    // 컨테이너 여부는 nesting.containers 정의 기준(빈 컨테이너도 포함) — 드롭 경로의
    // isContainerComponent 와 동일 기준. children 배열 존재 여부로 판정하면 빈
    // 컨테이너(Div/Form 등)의 첫 자식을 그 부모 accepts 로 잘못 필터함.
    const selectedNode = selection.selectedNode;
    const childrenCount = Array.isArray(selectedNode?.children)
      ? (selectedNode!.children as EditorNode[]).length
      : 0;
    return resolveGlobalInsertionTarget(
      selectedNode?.name,
      childrenCount,
      selectedPathIndexes,
      nesting,
      components.length
    );
  }, [
    state.isPaletteOpen,
    selectedPathIndexes,
    insertionAnchorPathIndexes,
    selection.selectedLockKind,
    selection.selectedNode,
    components.length,
    nesting,
  ]);

  const closeGlobalPalette = useCallback(() => {
    dispatch({ type: 'SET_PALETTE_OPEN', open: false });
  }, [dispatch]);

  // 글로벌 팔레트 modal 토글 — state.isPaletteOpen 변화에 반응.
  // open=true 진입 시 모달 1회 열고 그 id 보관 → open=false 또는 언마운트 시 닫음.
  const globalPaletteModalIdRef = useRef<string | null>(null);
  useEffect(() => {
    if (!state.isPaletteOpen) {
      // 닫힘 신호 — 떠 있는 모달 닫기
      const id = globalPaletteModalIdRef.current;
      if (id) {
        modal.close(id);
        globalPaletteModalIdRef.current = null;
      }
      return;
    }
    if (!globalInsertionTarget) return;
    // 이미 떠 있으면 중복 방지 (state 변화로 effect 가 재진입 가능)
    if (globalPaletteModalIdRef.current) return;
    const target = globalInsertionTarget;
    const live = liveDataRef.current;
    const targetContainerName = resolveContainerNameAt(live.components, target.parentPath);
    const id = modal.open({
      ariaLabel: editorAwareT('layout_editor.palette.title'),
      width: 880,
      maxHeightRatio: 0.82,
      content: React.createElement(ComponentPalette, {
        manifest: live.manifest,
        nesting: live.nesting,
        componentPalette: live.componentPalette ?? null,
        targetParentPath: target.parentPath,
        targetContainerName,
        onInsert: (newNode, p, idx) => {
          handleInsertRef.current(newNode, p, idx);
          closeGlobalPalette();
        },
        insertionIndex: target.index,
        onClose: () => closeGlobalPalette(),
        editorTemplateId: state.templateIdentifier,
        editorLocale: state.locale,
      }),
      onClose: () => {
        globalPaletteModalIdRef.current = null;
        // 백드롭/ESC 로 닫혔다면 state 도 동기화
        dispatch({ type: 'SET_PALETTE_OPEN', open: false });
      },
    });
    globalPaletteModalIdRef.current = id;
    // 다음 effect 실행 시 cleanup 으로 닫음 — open=true → false 전환에서 위 분기로 정리.
  }, [state.isPaletteOpen, globalInsertionTarget, modal, closeGlobalPalette, dispatch]);

  // 세션 누적 path 보고/placeholder 갱신 트리거는 회수됨.
  // 새 노드 시각화는 템플릿 defaultNode 가 책임지므로 별도 후처리 불필요.

  // 이력 모델 — "변경 후 상태" 를 push 하는 표준 cursor 패턴.
  //  - 문서 로드 직후 1회: baseline(현재 components) 를 push (아래 useEffect).
  //  - patch 이후: 변경 결과(nextComponents) 를 push.
  //  - undo: cursor-1 entry 의 snapshot 으로 복원, redo: cursor+1 entry 로 복원.
  // `canUndo = cursor > 0` 이 의미하는 것은 "되돌릴 베이스라인이 있다" — baseline
  // push 가 누락되면 첫 변경 후에도 canUndo=false 가 되어 사용자가 되돌릴 수 없다.
  // (이번 결함의 근본 원인: 직전 "before" 스냅샷을 푸시하고 baseline 누락 → cursor=0
  // 고정으로 canUndo 영구 false 였음.)
  const historyBaselineRef = useRef<{ layoutName: string | null; pushed: boolean }>({
    layoutName: null,
    pushed: false,
  });
  useEffect(() => {
    const currentLayout = docCtx?.document?.layoutName ?? null;
    if (!currentLayout) {
      historyBaselineRef.current = { layoutName: null, pushed: false };
      return;
    }
    // 라우트가 바뀌면 baseline 재push (이력은 라우트 단위로 격리)
    if (historyBaselineRef.current.layoutName !== currentLayout) {
      history.clear();
      history.push({
        actionKind: 'inline_text_edit',
        label: 'baseline',
        snapshot: components,
      });
      historyBaselineRef.current = { layoutName: currentLayout, pushed: true };
    }
  }, [docCtx?.document?.layoutName, components, history]);

  // 결함 I — save success 시 history reset.
  // useLayoutDocument 의 saveSuccessCounter 가 +1 되면 (PUT 200 응답) history.clear()
  // 호출 후 현재 저장된 상태(=새 baseline)를 재push 한다. 결과: Undo/Redo 양쪽
  // disabled 로 리셋. 사용자가 저장 후 Undo 로 클라이언트만 이전 상태로 돌아가서
  // 서버 DB 와 불일치하는 데이터 정합성 위험을 차단한다.
  // 초기값(0) 에서는 발화하지 않도록 prevRef 로 변화만 감지.
  const lastSaveCounterRef = useRef<number>(0);
  useEffect(() => {
    const counter = docCtx?.saveSuccessCounter ?? 0;
    if (counter === lastSaveCounterRef.current) return;
    lastSaveCounterRef.current = counter;
    if (counter === 0) return; // 초기 hook 진입은 무시
    // 저장된 상태가 새 baseline — undo/redo 양쪽 비움
    history.clear();
    history.push({
      actionKind: 'inline_text_edit',
      label: 'save_baseline',
      snapshot: components,
    });
    // sessionAddedPaths 도 리셋 — 저장 완료된 노드는 더 이상 "본 세션 추가" 아님
    sessionAddedPathsRef.current = [];
    onSessionAddedPathsChange?.([]);
  }, [docCtx?.saveSuccessCounter, components, history, onSessionAddedPathsChange]);

  // 항목8 — reload(레이아웃 초기화) 시 history baseline 재설정.
  // reload() 는 같은 layoutName 으로 서버 최신을 재fetch 하므로, layoutName 기준
  // baseline 재push 경로(historyBaselineRef)가 발화하지 않는다. 또한 reload 직후엔
  // components 가 아직 이전 값이라 여기서 즉시 push 하면 stale baseline 이 박힌다.
  // 따라서 reloadCounter 변화 시 baseline ref 를 무효화만 하고, 서버 최신 components
  // 가 도착해 위 historyBaselineRef effect 가 재발화할 때 새 baseline 을 push 하게 한다.
  const lastReloadCounterRef = useRef<number>(0);
  useEffect(() => {
    const counter = docCtx?.reloadCounter ?? 0;
    if (counter === lastReloadCounterRef.current) return;
    lastReloadCounterRef.current = counter;
    if (counter === 0) return;
    // baseline ref 무효화 → 다음 components 변화 시 historyBaselineRef effect 가 clear+재push.
    historyBaselineRef.current = { layoutName: null, pushed: false };
    sessionAddedPathsRef.current = [];
    onSessionAddedPathsChange?.([]);
  }, [docCtx?.reloadCounter, onSessionAddedPathsChange]);

  // 팔레트 → patchLayout
  const handleInsert = useCallback(
    (newNode: EditorNode, parentPath: ComponentPath, index: number): void => {
      if (!docCtx) return;
      let nextComponentsCaptured: EditorNode[] = [];
      docCtx.patchLayout((current) => {
        const root: EditorNode = { children: current };
        // index 가 음수면 children.length 로 클램프 (wrap 의 below 등에서 발생)
        const parent = traverse(root, parentPath);
        const safeIndex =
          index < 0 ? (Array.isArray(parent?.children) ? parent.children.length : 0) : index;
        const next = insertNode(root, parentPath, safeIndex, newNode);
        const nextComponents = (next.children as EditorNode[]) ?? [];
        nextComponentsCaptured = nextComponents;
        return nextComponents;
      });
      // history push — 변경 *후* 스냅샷
      history.push({
        actionKind: 'insert',
        label: `insert ${newNode.name ?? newNode.type ?? ''}`,
        snapshot: nextComponentsCaptured,
      });
      // 비활성 확장 자식 신규 추가 차단 가드 입력 — sessionAddedPaths trace 유지.
      // (placeholder 시각화는 에서 회수되었으나 save 가드 입력은 유지.)
      const parent = traverse({ children: nextComponentsCaptured } as EditorNode, parentPath);
      const safeIdx =
        index < 0 ? (Array.isArray(parent?.children) ? parent.children.length - 1 : 0) : index;
      const insertedPathKey = serializeEditorPath([...parentPath, safeIdx]);
      sessionAddedPathsRef.current = [...sessionAddedPathsRef.current, insertedPathKey];
      onSessionAddedPathsChange?.(sessionAddedPathsRef.current);

      // 신규 노드가 viewport 밖(예: footer 아래)에 append 되면
      // 사용자가 "추가됐다" 는 시각 단서를 인지할 수 없음. React 렌더 사이클이
      // DOM 에 [data-editor-path] 를 부착할 때까지 짧게 기다린 후 scrollIntoView
      // 로 캔버스를 신규 노드 위치로 이동. 시각 단서 보강용 best-effort 이므로
      // 노드를 못 찾으면 무시.
      if (frameEl) {
        const insertedSelector = `[data-editor-path="${cssEscape(insertedPathKey)}"]`;
        const scheduleScroll = (): void => {
          const el = frameEl.querySelector(insertedSelector) as HTMLElement | null;
          if (el && typeof el.scrollIntoView === 'function') {
            el.scrollIntoView({ behavior: 'smooth', block: 'center', inline: 'nearest' });
          }
        };
        if (typeof window !== 'undefined' && typeof window.requestAnimationFrame === 'function') {
          window.requestAnimationFrame(() => window.requestAnimationFrame(scheduleScroll));
        } else {
          setTimeout(scheduleScroll, 32);
        }
      }
    },
    [docCtx, history, onSessionAddedPathsChange, frameEl]
  );
  // 모달 content 의 onInsert 콜백이 최신 handleInsert 를 호출하도록 ref 동기화
  handleInsertRef.current = handleInsert;

  // 컨텍스트 메뉴 — 복사 / 삭제
  const handleDuplicate = useCallback((): void => {
    if (!docCtx || !selection.selectedNode || !selectedPathIndexes) return;
    const dup = duplicateNodeUtil(selection.selectedNode);
    let nextComponentsCaptured: EditorNode[] = [];
    docCtx.patchLayout((current) => {
      const root: EditorNode = { children: current };
      const parentPath = selectedPathIndexes.slice(0, -1);
      const idx = selectedPathIndexes[selectedPathIndexes.length - 1] ?? 0;
      const next = insertNode(root, parentPath, idx + 1, dup);
      const nextComponents = (next.children as EditorNode[]) ?? [];
      nextComponentsCaptured = nextComponents;
      return nextComponents;
    });
    history.push({ actionKind: 'insert', label: 'duplicate', snapshot: nextComponentsCaptured });
  }, [docCtx, selection.selectedNode, selectedPathIndexes, history]);

  const handleDelete = useCallback((): void => {
    if (!docCtx || !selectedPathIndexes) return;
    let nextComponentsCaptured: EditorNode[] = [];
    docCtx.patchLayout((current) => {
      const root: EditorNode = { children: current };
      const next = removeNode(root, selectedPathIndexes);
      const nextComponents = (next.children as EditorNode[]) ?? [];
      nextComponentsCaptured = nextComponents;
      return nextComponents;
    });
    history.push({ actionKind: 'remove', label: 'remove', snapshot: nextComponentsCaptured });
    selection.clearSelection();
  }, [docCtx, selectedPathIndexes, history, selection]);

  // ── 디바이스 전용 분리 생성/해제 ──────
  // 현재 디바이스 폭 범위와 노드 분기 키들의 포함관계로 판정(resolveBranchSeparationMode):
  //  - 'separate' = 현재 폭과 정확히 같은 children 분기 없음 → 현재 디바이스 전용 생성 버튼.
  //                 sourceKey(포괄 분기)가 있으면 그 분기 children 을 복제 원본으로 쓴다.
  //  - 'merge'    = 현재 폭과 정확히 같은 children 분기 → 분리 해제 버튼
  // 포괄 분기(portable ⊇ mobile 등) 안이어도 더는 버튼을 숨기지 않는다:
  // 그 포괄 구성으로 이동하는 점프 버튼(definedDeviceBranches)과 현재 디바이스 전용 신규 생성
  // 버튼(separate, sourceKey=포괄 분기)을 함께 노출한다.
  // 가드: ① 컨테이너 노드(children 보유) ② 선택 path 에 분기 세그먼트 없음(분기 안 노드는
  // 이미 그 분기를 편집 중 — 재분리/해제 대상 아님). 잠금 노드는 ⓘ 메뉴 자체가 차단.
  const branchSeparation = useMemo<{ mode: 'separate' | 'merge'; key: string; sourceKey?: string } | null>(() => {
    if (!selection.selectedNode || !selectedPathIndexes) return null;
    if (selectedPathIndexes.some(isResponsiveSegment)) return null; // 이미 분기 안 노드
    const node = selection.selectedNode;
    if (!Array.isArray(node.children)) return null; // 컨테이너만
    const decision = resolveBranchSeparationMode(
      state.previewDevice,
      node,
      state.previewCustomWidth,
    );
    return { mode: decision.mode, key: decision.key, sourceKey: decision.sourceKey };
  }, [selection.selectedNode, selectedPathIndexes, state.previewDevice, state.previewCustomWidth]);

  // 분리 생성 — 선택 노드의 children 을 현재 디바이스 분기 children 으로 복제 신설.
  // sourceKey(포괄 분기)가 있으면 그 분기 children 을, 없으면 base children 을 복제 원본으로.
  const handleSeparateBranch = useCallback((): void => {
    if (!docCtx || !selectedPathIndexes || branchSeparation?.mode !== 'separate') return;
    const key = branchSeparation.key;
    const sourceKey = branchSeparation.sourceKey;
    let nextComponentsCaptured: EditorNode[] = [];
    docCtx.patchLayout((current) => {
      const root: EditorNode = { children: current };
      const next = separateBranch(root, selectedPathIndexes, key, sourceKey);
      nextComponentsCaptured = (next.children as EditorNode[]) ?? [];
      return nextComponentsCaptured;
    });
    history.push({ actionKind: 'insert', label: 'separate_branch', snapshot: nextComponentsCaptured });
  }, [docCtx, selectedPathIndexes, branchSeparation, history]);

  // 분리 해제 — 선택 노드의 현재 디바이스 분기를 제거해 기본 구성으로 복귀.
  const handleMergeBranch = useCallback((): void => {
    if (!docCtx || !selectedPathIndexes || branchSeparation?.mode !== 'merge') return;
    const key = branchSeparation.key;
    let nextComponentsCaptured: EditorNode[] = [];
    docCtx.patchLayout((current) => {
      const root: EditorNode = { children: current };
      const next = mergeBranch(root, selectedPathIndexes, key);
      nextComponentsCaptured = (next.children as EditorNode[]) ?? [];
      return nextComponentsCaptured;
    });
    history.push({ actionKind: 'remove', label: 'merge_branch', snapshot: nextComponentsCaptured });
  }, [docCtx, selectedPathIndexes, branchSeparation, history]);

  // breakpoint 키 → 디바이스명(라벨). preset 은 i18n, 커스텀 범위는 그 범위 표기.
  const deviceLabelOf = useCallback(
    (key: string): string => {
      switch (key) {
        case 'mobile':
          return chromeT('layout_editor.context_menu.device_mobile');
        case 'portable':
          // portable = 0~1023(모바일+태블릿 포괄) — 'mobile' 과 다른 고유 라벨.
          return chromeT('layout_editor.context_menu.device_portable');
        case 'tablet':
          return chromeT('layout_editor.context_menu.device_tablet');
        case 'desktop':
          return chromeT('layout_editor.context_menu.device_desktop');
        default:
          return chromeT('layout_editor.context_menu.device_custom', { range: key });
      }
    },
    [chromeT],
  );

  // 메뉴/버튼 라벨 — 현재 디바이스명 포함("이 영역을 모바일 전용으로 분리" 등).
  // 디바이스명은 분기 키(separate=생성키 / merge=해제 대상키) 기준. 커스텀 범위는 범위 표기.
  const branchSeparationLabels = useMemo<{ separate: string; merge: string } | null>(() => {
    if (!branchSeparation) return null;
    const device = deviceLabelOf(branchSeparation.key);
    return {
      separate: chromeT('layout_editor.context_menu.separate_branch_device', { device }),
      merge: chromeT('layout_editor.context_menu.merge_branch_device', { device }),
    };
  }, [branchSeparation, chromeT, deviceLabelOf]);

  // ── '정의된 디바이스 구성' 점프 버튼군 ───────────────
  // 선택 노드가 디바이스별 children 교체 구성(`responsive.{key}.children`)을 가지면, 그 구성
  // 키들을 버튼으로 노출해 "다른 디바이스에 별도 구성이 있음" 을 알리고, 클릭 시 그 디바이스
  // 보기로 캔버스를 전환한다(현재 보고 있는 디바이스 키는 제외 — 이미 그 구성을 편집 중).
  // 가드: 분기 안 노드(선택 path 에 responsive 세그먼트)는 제외(이미 한 분기 내부 — 형제 분기로
  // 점프는 별 의미). base 를 보는 노드에서만 노출.
  const definedDeviceBranches = useMemo<Array<{ key: string; label: string }>>(() => {
    if (!selection.selectedNode || !selectedPathIndexes) return [];
    if (selectedPathIndexes.some(isResponsiveSegment)) return [];
    const currentBp = deviceToBreakpoint(state.previewDevice, selection.selectedNode, state.previewCustomWidth);
    return collectDefinedDeviceBranches(selection.selectedNode, currentBp).map((key) => ({
      key,
      label: deviceLabelOf(key),
    }));
  }, [selection.selectedNode, selectedPathIndexes, state.previewDevice, state.previewCustomWidth, deviceLabelOf]);

  // 디바이스 구성 점프 — 그 키가 preset 디바이스면 동명 디바이스로, 커스텀 범위면 custom 폭으로
  // 전환한다(custom 폭 = 그 범위 상한, 매칭되도록).
  const handleJumpToDevice = useCallback(
    (key: string): void => {
      if (key === 'desktop' || key === 'tablet' || key === 'mobile' || key === 'portable') {
        deviceControls.setDevice(key);
        return;
      }
      // 커스텀 범위 키 — 동적 디바이스 키로 직접 전환(폭은 resolveDeviceWidth 가 산출).
      deviceControls.setDevice(key);
    },
    [deviceControls],
  );

  // ── 클립보드(복사/잘라내기/붙여넣기 — sessionStorage, 다른 레이아웃 가능) ──────────
  // 복사: 선택 노드를 sessionStorage 버퍼에 기록(`__source` 등 내부 메타 제거).
  const handleCopy = useCallback((): void => {
    if (!selection.selectedNode) return;
    writeClipboard(selection.selectedNode);
  }, [selection.selectedNode]);

  // 잘라내기: 복사 후 선택 노드 삭제.
  const handleCut = useCallback((): void => {
    if (!selection.selectedNode) return;
    writeClipboard(selection.selectedNode);
    handleDelete();
  }, [selection.selectedNode, handleDelete]);

  // 붙여넣기: 버퍼 노드를 선택 노드의 **다음 형제**로 삽입(선택 없으면 루트 끝). 다른
  // 레이아웃으로 이동해도 sessionStorage 라 동작. duplicateNodeUtil 로 새 id 부여(중복 id 회피).
  const handlePaste = useCallback((): void => {
    if (!docCtx) return;
    const buf = readClipboard();
    if (!buf) return;
    const fresh = duplicateNodeUtil(buf); // 새 노드로 취급(id 재생성 등)
    if (selectedPathIndexes && selectedPathIndexes.length > 0) {
      const parentPath = selectedPathIndexes.slice(0, -1);
      const idx = (selectedPathIndexes[selectedPathIndexes.length - 1] ?? 0) + 1;
      handleInsert(fresh, parentPath, idx);
    } else {
      // 선택 없음 — 루트 끝에 추가.
      handleInsert(fresh, [], components.length);
    }
  }, [docCtx, selectedPathIndexes, handleInsert, components.length]);

  // 드래그 앤 드롭 (S5b) — useCanvasDnd 가 moveNode + patch 수행 후, 변경 결과
  // 스냅샷을 본 어댑터로 이력에 push (actionKind: 'move'). insert/duplicate/delete
  // 와 동일한 "변경 후 스냅샷" cursor 패턴.
  const pushMoveHistory = useCallback(
    (snapshot: EditorNode[], label: string): void => {
      history.push({ actionKind: 'move', label, snapshot });
    },
    [history]
  );

  // 링크 어포던스 — 라우트 매칭 시 SELECT_ROUTE
  const handleLinkEditDestination = useCallback((): void => {
    if (selection.selectedNavAffordance !== 'route_in_tree') return;
    if (!selection.selectedNavTargetPath) return;
    if (onNavigateToDestination) {
      onNavigateToDestination(selection.selectedNavTargetPath);
      return;
    }
    dispatch({
      type: 'SELECT_ROUTE',
      route: { path: selection.selectedNavTargetPath, layoutName: null },
    });
  }, [
    selection.selectedNavAffordance,
    selection.selectedNavTargetPath,
    onNavigateToDestination,
    dispatch,
  ]);

  // 잠금 어포던스 — base/extension 진입
  // 진입 대상 base 레이아웃은 **선택된 노드의 출처**(`__source.layout`, 예 `_user_base`)다.
  // 이는 "공통 레이아웃 편집" 칩 라벨(`selectedBaseLayout`)이 가리키는 그 파일이며, 현재 보고
  // 있는 라우트(`selectedRoute.layoutName`, 예 `board/form`)와는 다르다. 종전엔 라우트
  // layoutName 을 진입 식별자로 써서 `__base__/board/form` 으로 잘못 이동했다(칩은
  // `_user_base` 라 표시하면서 URL/로드는 board/form). 칩 라벨과 동일 출처로 진입을 일치시킨다.
  const handleEditBase = useCallback((): void => {
    const layout = resolveBaseEditTarget(selection.selectedBaseLayout, state.selectedRoute?.layoutName);
    if (layout) {
      dispatch({ type: 'ENTER_BASE_EDIT', layoutName: layout });
    }
  }, [selection.selectedBaseLayout, state.selectedRoute?.layoutName, dispatch]);

  const handleEditExtension = useCallback((): void => {
    const extId = (selection.selectedNode as any)?.__source?.extensionId;
    if (extId !== undefined && extId !== null) {
      dispatch({ type: 'ENTER_EXTENSION_EDIT', extensionId: String(extId) });
    }
  }, [selection.selectedNode, dispatch]);

  // 반복 항목 편집 모드 진입 — 선택 영역이 반복(iteration)일 때만 어포던스 노출.
  // iteration 원본 노드의 에디터 path 를 출처로 전달(항목 템플릿 단독 편집은 별도 세션).
  const handleEditIteration = useCallback((): void => {
    const sourcePath = selection.selectedIterationSourcePath;
    if (sourcePath) {
      dispatch({ type: 'ENTER_ITERATION_ITEM_EDIT', sourcePath });
    }
  }, [selection.selectedIterationSourcePath, dispatch]);

  // 속성 편집 모달 — ⓘ 메뉴 "속성 설정". 모달은 한 번 open 으로 떠 있고
  // 패치마다 같은 id 로 content 를 재오픈(idempotent)해 최신 노드를 반영한다.
  // 패치 대상 노드 path 는 모달 열 때 캡처(드래그/삭제로 path 가 바뀌면 닫고 다시 열기).
  const propertyModalIdRef = useRef<string | null>(null);
  const propertyModalPathRef = useRef<number[] | null>(null);
  // 외부(캔버스 인플레이스/DnD 등) patchLayout 으로 노드가 바뀌면 열려 있는 속성 모달도
  // 최신 노드로 재오픈하기 위한 콜백(openPropertyModalFor 가 자기 renderContent 로 채운다).
  // 양방향 일관성(속성 모달 ↔ 캔버스 동시 열림 시 캔버스 조작이 모달
  // 미니표에 반영돼야 함). 모달이 닫히면 null.
  const reopenPropertyModalRef = useRef<((latestNode: EditorNode) => void) | null>(null);
  // 스타일 탭 색 모드 × 디바이스 scope 스냅샷.
  // propertyModalIdRef 와 같은 생명주기로 보관 — 새 노드 열 때만 툴바에서 재스냅샷,
  // 패치 재마운트엔 불변(D1 "독립"). renderContent 클로저가 이 ref 를 읽고, onScopeChange
  // 가 ref 를 갱신 후 같은 id 로 재오픈한다.
  const propertyModalScopeRef = useRef<StyleScope>({ colorScheme: 'base', breakpoint: 'base' });
  // 항목7 — 속성 편집 모달이 열려 있는 동안 선택 요소 외 캔버스를 어둡게/잠금.
  // 잠금 상태를 수동 토글(open 시 true / 각 onClose 에서 false)로 두면 close 경로가
  // 여러 갈래(초기 open onClose / 패치 재오픈 onClose / X / 닫기 버튼)라 한 곳만
  // 누락돼도 닫은 뒤 딤이 남는다.
  // 따라서 **모달 스택을 단일 진실로** 삼아, 속성 모달 id 가 스택에 실재할 때만
  // 잠금을 활성화한다. 어떤 경로로 닫든 스택에서 빠지면 자동 해제 — 누락 불가능.
  const editLockActive = propertyModalIdRef.current !== null
    ? modal.stack.some((e) => e.id === propertyModalIdRef.current)
    : false;

  // 레시피 파라미터 picker 후보 — 라우트(page)는 편집기 라우트 트리,
  // 데이터소스는 현재 문서의 data_sources, 상태 키는 spec.states 의 initialState 키에서 도출.
  // 후보 부재 picker 는 자유 텍스트로 디그레이드한다(원칙 4.4).
  // 후보 풀 도출은 공용 순수 함수(spec/candidatePools)로 추출 — 페이지 설정 모달(셸)과
  // 동일 함수 공유(명세 1곳, 캔버스/모달 후보 불일치 0). 입출력은 candidatePools.test 가 고정.
  const pageCandidates = useMemo<Array<{ value: string; label: string }>>(
    () => buildPageCandidates(state.routeTree, editorAwareT),
    [state.routeTree, editorAwareT],
  );

  const dataSourceCandidates = useMemo<Array<{ value: string; label: string }>>(
    () => buildDataSourceCandidates(docCtx?.document?.raw),
    [docCtx?.document?.raw],
  );

  const stateKeyCandidates = useMemo<Array<{ value: string; label: string }>>(
    () => buildStateKeyCandidates(spec),
    [spec?.states],
  );

  // 격리 영역(isolatedScopeId) 검색 후보 — 속성 모달 IsolatedScopeControl 이
  // 쓴다. 레이아웃 기존 scopeId 전수(collectIsolatedScopes) + initIsolated 키 + 관용 패턴.
  // usedScopeIds 는 중복 안내용(현재 노드 포함 — 자기 자신 중복은 드물고, 도출 단순화).
  const isolatedScopeIdCandidates = useMemo<string[]>(() => {
    const raw = docCtx?.document?.raw as Record<string, unknown> | undefined;
    const scopes = collectIsolatedScopes(raw?.components);
    const initIsolated = raw?.initIsolated;
    const initIsolatedKeys =
      initIsolated && typeof initIsolated === 'object' && !Array.isArray(initIsolated)
        ? Object.keys(initIsolated as Record<string, unknown>)
        : [];
    return buildScopeIdCandidates(scopes, initIsolatedKeys);
  }, [docCtx?.document?.raw]);

  const usedScopeIds = useMemo<string[]>(() => {
    const raw = docCtx?.document?.raw as Record<string, unknown> | undefined;
    return collectIsolatedScopes(raw?.components)
      .map((s) => s.scopeId)
      .filter((id): id is string => typeof id === 'string' && id.length > 0);
  }, [docCtx?.document?.raw]);

  // 데이터 연결 검색 후보 풀 — [속성] 탭 "데이터 연결" 영역의 검색형
  // 피커가 쓴다. data_sources 각 소스의 샘플 응답 shape + 상태 트리(_global/_local/route/
  // query/_computed) 를 평탄화한다. 샘플 데이터 SSoT(부록6) — 런타임 응답 shape 추측 금지.
  // 친화 명칭은 data_source `label_key` + spec.stateLabels 카탈로그(빌더가 결선).
  // 6-b 확장: `_global`(sampleGlobal) 에 더해 spec.states 의 각 페이지 상태 initialState 패치
  // (local/query/route) 와 레이아웃 raw.computed 키를 scope 별로 집계해 후보로 노출한다.
  // 상태별 트리는 "이 레이아웃에서 그 scope 에 존재할 수 있는 키" 의 합집합 — 검색 피커가
  // _local.* / query.* / _computed.* 도 찾을 수 있게 한다(라이브 상태 토글과 별개로 후보 인지).
  // 데이터 칩 후보 풀 — 공용 훅으로 추출. 캔버스 오버레이와
  // 페이지 설정 모달이 같은 풀을 공유. 동작 무변경(evaluateComputed 미지정 = 기존 scalar 폴백).
  const bindingCandidates = useBindingCandidates({
    raw: docCtx?.document?.raw as Record<string, unknown> | undefined,
    spec,
  });

  const openPropertyModalFor = useCallback(
    (path: number[], initialTab?: 'settings' | 'style' | 'action' | 'visibility' | 'translation' | 'advanced'): void => {
      if (!docCtx) return;
      const root: EditorNode = { children: liveDataRef.current.components };
      const node = findNodeByPath(root, path);
      if (!node) return;

      // 스타일 탭 scope 초기 스냅샷 — 모달 열리는 순간 툴바의
      // (색 모드 × 디바이스) 스냅샷. 이후 툴바를 바꿔도 모달 scope 는 동기화 안 함(독립).
      propertyModalScopeRef.current = {
        colorScheme: state.previewColorScheme === 'dark' ? 'dark' : 'base',
        breakpoint: deviceToBreakpoint(state.previewDevice, node, state.previewCustomWidth),
      };

      // 선택 요소 viewport 좌표.
      // selectedBox 는 frame-local 좌표이므로 frame 의 viewport rect 를 더해 변환.
      const frameRect = frameEl?.getBoundingClientRect();
      const avoidRect =
        selectedBox && frameRect
          ? {
              left: frameRect.left + selectedBox.left,
              top: frameRect.top + selectedBox.top,
              width: selectedBox.width,
              height: selectedBox.height,
            }
          : undefined;

      // 캔버스에 렌더된 이 노드 DOM — flex auto 판정용. 선택 노드 DOM 사용.
      const liveElement = selectedDomEl;

      // 디바이스 분기 안 노드면 그 디바이스명 — 모달에 "이 요소는 [디바이스]
      // 전용 구성에 속함" 안내 배지로 노출. path 의 responsive 세그먼트로 도출(base 면 null).
      // deviceLabelOf 는 "모바일"/"태블릿"/범위 표기(배지 문구가 "전용 구성"을 덧붙이므로
      // "구성" 이 중복되지 않도록 디바이스명만 — breakpointKeyLabel("모바일 구성")과 구분).
      const branchSeg = path.find(isResponsiveSegment) as { responsive: string } | undefined;
      const modalBranchLabel = branchSeg ? deviceLabelOf(branchSeg.responsive) : null;

      // 반복 목록 안의 노드면 iteration 조상의 인덱스/행 변수를 데이터 칩 후보로 보강한다.
      // id 등 식별자 칸에서 `{{$idx}}`/`{{row.id}}` 를 골라 항목별 고유 식별자를 만들 수 있게 한다.
      // 노드별로 달라지므로 전역 bindingCandidates 와 분리해 모달 단위로 합친다(전역 풀 불변).
      const numericPath = path.filter((seg): seg is number => typeof seg === 'number');
      const iterationVars = collectIterationVars(
        { children: liveDataRef.current.components },
        numericPath,
        buildArrayItemFieldsLookup(bindingCandidates),
      );
      const modalBindingCandidates =
        iterationVars.length > 0
          ? [...buildBindingCandidates({ iterationVars }), ...bindingCandidates]
          : bindingCandidates;

      // initialTab 은 최초 오픈에만 적용한다 — 패치/scope 재오픈 시에는 전달하지 않아
      // 사용자가 다른 탭으로 이동한 상태를 강제로 되돌리지 않는다.
      const renderContent = (currentNode: EditorNode, tabForOpen?: typeof initialTab): React.ReactNode =>
        React.createElement(PropertyEditorModal, {
          // 노드 정체성(선택 path) 으로 keying — 다른 노드로 전환하면 모달 content 가 remount 되어
          // 컨트롤/위젯 내부 state(I18nTextField 펼침·load lock_version 등)가 이전 노드에서 새지
          // 않는다. 같은 노드 안의 패치 재오픈은 path 가 동일해 remount 되지 않는다(
          // MCP — 노드 전환 시 이전 키 폼 잔존 → stale lock 저장/409 차단).
          key: `pem-${serializeEditorPath(propertyModalPathRef.current ?? [])}`,
          node: currentNode,
          spec,
          manifest: liveDataRef.current.manifest,
          t: editorAwareT,
          permissionCandidates,
          pageCandidates,
          dataSourceCandidates,
          stateKeyCandidates,
          bindingCandidates: modalBindingCandidates,
          liveElement,
          templateIdentifier: state.templateIdentifier,
          initialTab: tabForOpen,
          branchLabel: modalBranchLabel,
          // 격리 영역 그룹 — IsolatedScopeControl scopeId 검색 후보·중복 안내.
          isolatedScopeIdCandidates,
          usedScopeIds,
          // 확장 주입 props 교차 저장 — 호스트 노드의 `__injectedProps` 편집
          // 결과를 그 확장 행으로 저장. 호스트 노드 id(target_id)로 해당 injection 을 찾는다.
          onSaveInjectedProps: async (
            extensionId: number,
            nextProps: Record<string, unknown>,
          ): Promise<void> => {
            const hostNodeId =
              typeof (currentNode as { id?: unknown }).id === 'string'
                ? ((currentNode as { id: string }).id)
                : '';
            const result = await saveInjectedPropsToExtension(
              state.templateIdentifier,
              extensionId,
              hostNodeId,
              nextProps,
            );
            trackEditorDocument({
              op: 'save',
              layoutName: `extension:${extensionId}`,
              editMode: state.editMode,
              saveTarget: 'layout_extension',
              endpoint: `/api/admin/templates/${state.templateIdentifier}/layout-extensions/${extensionId}`,
              isDirty: true,
              timestamp: Date.now(),
            });
            if (result.kind !== 'success') {
              throw new Error(
                result.kind === 'conflict'
                  ? editorAwareT('layout_editor.save.conflict')
                  : result.kind === 'injection_not_found'
                    ? 'injection not found'
                    : result.kind === 'not_found'
                      ? 'extension not found'
                      : (result as { message?: string }).message ?? 'save failed',
              );
            }
          },
          scope: propertyModalScopeRef.current,
          onScopeChange: (next: StyleScope) => {
            // scope 스냅샷 ref 갱신 후 같은 id 로 재오픈(content 재렌더). re-mount 함정 —
            // 모달 내부 state 가 아닌 외부 ref 에 보관해 패치 재마운트에도 scope 유지.
            propertyModalScopeRef.current = next;
            const id = propertyModalIdRef.current;
            if (id) {
              modal.open({
                id,
                ariaLabel: editorAwareT('layout_editor.property_modal.title'),
                width: 420,
                draggable: true,
                avoidRect,
                content: renderContent(currentNode),
                onClose: () => {
                  propertyModalIdRef.current = null;
                  propertyModalPathRef.current = null;
                },
              });
            }
          },
          onPatchNode: (patched: EditorNode) => {
            // path 의 노드를 patched 로 교체 → patchLayout → history → 모달 content 갱신
            let nextCaptured: EditorNode[] = [];
            docCtx.patchLayout((current) => {
              const r: EditorNode = { children: current };
              const next = patchNode(r, path, () => patched);
              nextCaptured = (next.children as EditorNode[]) ?? [];
              return nextCaptured;
            });
            history.push({ actionKind: 'inline_text_edit', label: 'edit_props', snapshot: nextCaptured });
            trackEditorPropertyPatch({
              source: 'style_control',
              componentPath: serializeEditorPath(path),
              componentName: typeof patched.name === 'string' ? patched.name : null,
              patchKey: null,
              timestamp: Date.now(),
            });
            // 같은 id 로 재오픈 — 최신 노드로 content 교체(idempotent)
            const id = propertyModalIdRef.current;
            if (id) {
              modal.open({
                id,
                ariaLabel: editorAwareT('layout_editor.property_modal.title'),
                width: 420,
                draggable: true,
                avoidRect,
                content: renderContent(patched),
                onClose: () => {
                  propertyModalIdRef.current = null;
                  propertyModalPathRef.current = null;
                },
              });
            }
          },
          onDelete: () => {
            const id = propertyModalIdRef.current;
            if (id) modal.close(id);
            handleDelete();
          },
          onClose: () => {
            const id = propertyModalIdRef.current;
            if (id) modal.close(id);
          },
        });

      // path ref 를 content 생성 **전에** 설정 — renderContent 의 key(`pem-{path}`)가 첫 오픈부터
      // 올바른 노드 정체성을 갖게 한다(이전 노드 path 로 keying 되어 remount 누락되는 것 방지).
      propertyModalPathRef.current = path;
      const id = modal.open({
        ariaLabel: editorAwareT('layout_editor.property_modal.title'),
        width: 420,
        draggable: true,
        avoidRect,
        content: renderContent(node, initialTab),
        onClose: () => {
          propertyModalIdRef.current = null;
          propertyModalPathRef.current = null;
          reopenPropertyModalRef.current = null;
        },
      });
      propertyModalIdRef.current = id;
      // 외부 patchLayout 동기화용 재오픈 콜백 — 같은 id 로 최신 노드 content 교체(양방향 일관성).
      reopenPropertyModalRef.current = (latestNode: EditorNode) => {
        modal.open({
          id,
          ariaLabel: editorAwareT('layout_editor.property_modal.title'),
          width: 420,
          draggable: true,
          avoidRect,
          content: renderContent(latestNode),
          onClose: () => {
            propertyModalIdRef.current = null;
            propertyModalPathRef.current = null;
            reopenPropertyModalRef.current = null;
          },
        });
      };
      // 항목7 — 딤/잠금은 modal.stack 에서 파생(위 editLockActive). 별도 토글 불필요:
      // 어떤 경로로 닫혀도 스택에서 빠지면 자동 해제.
    },
    [docCtx, spec, editorAwareT, permissionCandidates, pageCandidates, dataSourceCandidates, stateKeyCandidates, bindingCandidates, selectedDomEl, history, modal, handleDelete, frameEl, selectedBox, state.previewColorScheme, state.previewDevice, state.previewCustomWidth, deviceLabelOf],
  );

  // 양방향 일관성 — 속성 모달이 열린 채 외부(캔버스 인플레이스/DnD 등)에서
  // patchLayout 이 일어나 노드가 바뀌면, 모달 미니표도 최신 노드로 재오픈한다. 모달 자체
  // 패치도 components 를 바꾸지만 같은 노드·같은 id 라 idempotent(추가 비용만, 무해).
  // path 위치에 노드가 없으면(삭제/구조 변경으로 path 무효) 모달을 닫는다.
  useEffect(() => {
    const reopen = reopenPropertyModalRef.current;
    const path = propertyModalPathRef.current;
    if (!reopen || !path) return;
    const root: EditorNode = { children: liveDataRef.current.components };
    const latest = findNodeByPath(root, path);
    if (latest) reopen(latest);
    else if (propertyModalIdRef.current) modal.close(propertyModalIdRef.current);
    // components 변경 시에만 — 모달 열림 여부는 ref 가드로 판정.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [components]);

  // 항목7 — 캔버스 딤/잠금 레이어. 선택 요소 박스를 "구멍"으로 둔 4개 사각형으로
  // frame 전체를 덮어 선택 외 영역을 어둡게 처리한다. pointerEvents:auto 로 클릭을
  // 가로채 선택 외 요소 조작을 잠근다(선택 요소만 밝게·조작 가능). selectedBox 가
  // 없으면(측정 전) 전체를 덮어 잠금만 유지.
  const renderEditLock = (): React.ReactNode => {
    if (!editLockActive) return null;
    const dim = 'rgba(15, 23, 42, 0.45)';
    const common: React.CSSProperties = {
      position: 'absolute',
      background: dim,
      pointerEvents: 'auto',
      // 드래그 핸들 밴드(≤70) 위 → 편집 중 선택 외 요소의 핸들 클릭으로 선택/이동되던
      // 결함 차단. 어포던스 밴드(120) 아래 → 선택 요소의 ⓘ/리사이즈는 딤 위에서 조작 가능.
      zIndex: EDIT_LOCK_DIM,
    };
    const block = (e: React.MouseEvent): void => {
      // 선택 외 영역 클릭/이동 차단 — 선택 유지(잠금).
      e.preventDefault();
      e.stopPropagation();
    };
    if (!selectedBox) {
      return (
        <div
          data-testid="g7le-edit-lock-full"
          onClick={block}
          onMouseMove={block}
          style={{ ...common, inset: 0 }}
        />
      );
    }
    const { left, top, width, height } = selectedBox;
    return (
      <div data-testid="g7le-edit-lock" style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}>
        {/* 위 */}
        <div data-testid="g7le-edit-lock-top" onClick={block} onMouseMove={block}
          style={{ ...common, left: 0, top: 0, right: 0, height: Math.max(0, top) }} />
        {/* 아래 */}
        <div data-testid="g7le-edit-lock-bottom" onClick={block} onMouseMove={block}
          style={{ ...common, left: 0, top: top + height, right: 0, bottom: 0 }} />
        {/* 좌 */}
        <div data-testid="g7le-edit-lock-left" onClick={block} onMouseMove={block}
          style={{ ...common, left: 0, top, width: Math.max(0, left), height }} />
        {/* 우 */}
        <div data-testid="g7le-edit-lock-right" onClick={block} onMouseMove={block}
          style={{ ...common, left: left + width, top, right: 0, height }} />
      </div>
    );
  };

  const handleEditProps = useCallback((): void => {
    if (!selectedPathIndexes) return;
    // 잠금 출처 노드(base/partial/extension/extension_point)는 속성 편집 차단 —
    // ElementOverlay 가 ⓘ 를 미표시하지만, 외부 호출자(Chrome stub) 등 다른 경로로도
    // 들어올 수 있으므로 여기서도 방어한다. data_bound·none 만 허용.
    if (!isContextMenuAllowed(selection.selectedLockKind)) return;
    // 외부 호출자(Chrome stub) 가 있으면 위임(하위호환), 아니면 자체 모달 오픈
    if (onEditProps && selection.selectedPath) {
      onEditProps(selection.selectedPath);
      return;
    }
    openPropertyModalFor(selectedPathIndexes);
  }, [selectedPathIndexes, selection.selectedPath, selection.selectedLockKind, onEditProps, openPropertyModalFor]);

  // 모서리 드래그 리사이즈 — width/height 컨트롤 레시피 재사용. 스펙이
  // 선언한 축만 핸들 활성. 드래그 결과는 속성 모달 width/height 와 양방향 동기
  // (같은 컨트롤·레시피 → reverseResolve 가 같은 값 반환).
  const { scale } = deviceControls;
  const selectedComponentName =
    typeof selection.selectedNode?.name === 'string' ? selection.selectedNode.name : '';
  const selectedCapability = spec?.componentCapabilities?.[selectedComponentName] ?? null;
  const selectedStyleControls: string[] = Array.isArray(selectedCapability?.styleControls)
    ? (selectedCapability!.styleControls as string[])
    : [];
  const widthControl =
    selectedStyleControls.includes('width') ? getControl(spec, 'width') : null;
  const heightControl =
    selectedStyleControls.includes('height') ? getControl(spec, 'height') : null;

  const handleResize = useCallback(
    (patched: EditorNode, axis: 'width' | 'height' | 'both'): void => {
      if (!docCtx || !selectedPathIndexes) return;
      let nextCaptured: EditorNode[] = [];
      docCtx.patchLayout((current) => {
        const r: EditorNode = { children: current };
        const next = patchNode(r, selectedPathIndexes, () => patched);
        nextCaptured = (next.children as EditorNode[]) ?? [];
        return nextCaptured;
      });
      trackEditorPropertyPatch({
        source: 'resize',
        componentPath: serializeEditorPath(selectedPathIndexes),
        componentName: selectedComponentName || null,
        patchKey: axis === 'height' ? 'height' : 'width',
        resizeAxis: axis,
        timestamp: Date.now(),
      });
      // move 마다는 patchLayout 만(라이브 미리보기). history push 는 pointerup 시
      // handleResizeEnd 가 1회 수행한다 — move 마다 push 하면 이력 폭증.
      void nextCaptured;
    },
    [docCtx, selectedPathIndexes, selectedComponentName],
  );

  // 리사이즈 종료(pointerup) — 최종 노드를 patch 한 결과 스냅샷을 history 에 1회 push.
  // 종전엔 resize 가 patchLayout 만 하고 history 를 건너뛰어 undo 스택에 안 쌓였다
  // 종료 시점에 한 번만 기록해 한
  // 번의 Ctrl+Z 로 리사이즈 직전 상태로 되돌아가게 한다.
  const handleResizeEnd = useCallback(
    (patched: EditorNode, axis: 'width' | 'height' | 'both'): void => {
      if (!docCtx || !selectedPathIndexes) return;
      let nextCaptured: EditorNode[] = [];
      docCtx.patchLayout((current) => {
        const r: EditorNode = { children: current };
        const next = patchNode(r, selectedPathIndexes, () => patched);
        nextCaptured = (next.children as EditorNode[]) ?? [];
        return nextCaptured;
      });
      history.push({
        actionKind: 'inline_text_edit',
        label: `resize ${axis}`,
        snapshot: nextCaptured,
      });
    },
    [docCtx, selectedPathIndexes, history],
  );

  // 드래그 시작 시점 실측 크기 — 선택 요소의 렌더 box(getBoundingClientRect)를 logical px
  // 로 환산(디바이스 미리보기 scale 보정)해 공급. style px 가 없는 요소가 0px 부터
  // 리사이즈되던 결함을 막는다. (delta 도 onMove 에서 scale 로 나누므로
  // 시작 크기도 같은 logical 공간이어야 일관.)
  const measureStartSize = useCallback((): { width: number; height: number } | null => {
    if (!selectedDomEl) return null;
    const r = selectedDomEl.getBoundingClientRect();
    const s = scale || 1;
    return { width: r.width / s, height: r.height / s };
  }, [selectedDomEl, scale]);

  const resize = useResizeHandles({
    node: selection.selectedNode,
    widthControl,
    heightControl,
    scale,
    measureStartSize,
    onResize: handleResize,
    onResizeEnd: handleResizeEnd,
  });

  const handleResizeHandlePointerDown = useCallback(
    (handle: ResizeHandleKey, e: { clientX: number; clientY: number }): void => {
      resize.onHandlePointerDown(handle, e);
    },
    [resize],
  );

  // 단축키 — Ctrl+Z / Ctrl+Shift+Z
  const handleUndo = useCallback(() => {
    const prev = history.undo();
    if (!prev || !docCtx) return;
    docCtx.setLayoutComponents(prev.snapshot);
  }, [history, docCtx]);
  const handleRedo = useCallback(() => {
    const next = history.redo();
    if (!next || !docCtx) return;
    docCtx.setLayoutComponents(next.snapshot);
  }, [history, docCtx]);
  useUndoRedoShortcuts({ onUndo: handleUndo, onRedo: handleRedo });

  // 요소/클립보드/선택 단축키(editorShortcuts SSoT) — 캔버스가 소유하는 액션만 결선.
  // 문서/보기 액션(save/exit/code/preview/add/reset/translations/help)은 LayoutEditorChrome
  // 이 별도 useEditorShortcuts 로 결선한다. Escape 는 훅이 hasSelection 으로 분기(선택 있으면
  // deselect=캔버스 소유, 없으면 exit=chrome 소유)하므로 두 훅이 충돌 없이 공존한다(핸들러
  // partial — 자기 소유 액션만 동작). openProps(Enter)는 선택 노드 속성 모달 진입.
  const shortcutHandlers = useMemo<ShortcutHandlers>(
    () => ({
      copy: handleCopy,
      cut: handleCut,
      paste: handlePaste,
      delete: handleDelete,
      openProps: handleEditProps,
      selectParent: parentPathStr ? handleSelectParent : undefined,
      deselect: selection.clearSelection,
    }),
    [handleCopy, handleCut, handlePaste, handleDelete, handleEditProps, parentPathStr, handleSelectParent, selection.clearSelection],
  );
  useEditorShortcuts({ handlers: shortcutHandlers, hasSelection: !!selection.selectedPath });

  // 선택 상태를 window 로 노출 — LayoutEditorChrome 의 문서/보기 단축키 훅이 Escape
  // 우선순위(선택 있으면 deselect=캔버스, 없으면 exit=chrome)를 판정하는 데 사용.
  useEffect(() => {
    (window as { __g7LayoutEditorHasSelection?: boolean }).__g7LayoutEditorHasSelection = !!selection.selectedPath;
    return () => {
      try { delete (window as { __g7LayoutEditorHasSelection?: boolean }).__g7LayoutEditorHasSelection; } catch { /* ignore */ }
    };
  }, [selection.selectedPath]);

  // 외부에서 history 동작이 필요한 경우(Toolbar) 를 위해 window 글로벌 노출 —
  // 본 Phase 는 단일 캔버스 기준. (장기적으로는 별도 Provider 로 분리 가능)
  useEffect(() => {
    (window as any).__g7LayoutEditorHistory = {
      undo: handleUndo,
      redo: handleRedo,
      canUndo: history.canUndo,
      canRedo: history.canRedo,
    };
    return () => {
      try {
        delete (window as any).__g7LayoutEditorHistory;
      } catch {
        // ignore
      }
    };
  }, [handleUndo, handleRedo, history.canUndo, history.canRedo]);

  // 인라인 편집 확정 — useInlineEdit.commit 위임(평문→키 생성 / 기존 키 값 수정).
  // 확정 후 편집 종료. 키 생성 시 patchLayout 가 일어나므로 history 에 push 한다.
  const handleInlineCommit = useCallback(
    (value: string): void => {
      const editing = inlineEditing;
      setInlineEditing(null);
      if (!editing) return;
      void (async () => {
        const result = await inlineEdit.commit(editing.path, value);
        // 키 생성으로 노드 text 가 치환된 경우(patchLayout 발생) 이력에 기록.
        if (result.kind === 'created' && docCtx) {
          const next = (docCtx.document?.raw?.components as EditorNode[] | undefined) ?? [];
          history.push({
            actionKind: 'inline_text_edit',
            label: 'inline_create_key',
            snapshot: next,
          });
        }
      })();
    },
    [inlineEditing, inlineEdit, docCtx, history],
  );

  const handleInlineCancel = useCallback((): void => {
    const editing = inlineEditing;
    setInlineEditing(null);
    inlineEdit.trackCancel(editing?.path ?? null);
  }, [inlineEditing, inlineEdit]);

  // param 키 칩 편집 확정 — 키 값(현재 로케일)만 PUT 되므로 node.text/레이아웃 변경은
  // 없다(history push 불필요). InlineParamChipEditor 가 PUT + bustTranslationCache 까지 마쳤으니
  // 편집만 닫는다. 캔버스는 사전 재fetch 로 갱신된다(키 값 변경 반영).
  const handleInlineParamChipCommit = useCallback((): void => {
    setInlineEditing(null);
  }, []);

  // 데이터 든 미키화 노드(plain_with_binding) 칩 편집기에서 **내용 변경 시** 키화.
  // InlineParamChipEditor 가 baseline 과 달라진 키 값을 넘긴다 → keyifyChipValue 가 키 생성 +
  // node.text param 치환(레이아웃 변경 → history push) + 키 값 버퍼(저장-지연)까지 수행. 생성된
  // customKey 를 칩 편집기에 돌려줘 이후 일반 param 키 경로로 전환하게 한다. 실패 시 null.
  const handleInlineChipKeyify = useCallback(
    async (editedKeyValue: string): Promise<string | null> => {
      const editing = inlineEditing;
      if (!editing) return null;
      const result = await inlineEdit.keyifyChipValue(editing.path, editedKeyValue);
      if (result.kind !== 'created' || !result.translationKey) return null;
      // node.text 가 param 형태로 치환됨(레이아웃 변경) → 이력에 기록.
      const next = (docCtx?.document?.raw?.components as EditorNode[] | undefined) ?? [];
      history.push({ actionKind: 'inline_text_edit', label: 'inline_keyify_chip', snapshot: next });
      return result.translationKey;
    },
    [inlineEditing, inlineEdit, docCtx, history],
  );

  // 인라인 편집 힌트 배지 클릭 → 이 노드의 속성 모달 [번역] 탭 열기.
  // 사용자가 "텍스트만 고치면 끝"으로 오해하지 않도록, 편집 중 그 자리에서 전체 로케일
  // 일괄 편집(번역 탭)으로 한 번에 진입하게 한다. 인라인 편집은 닫고 같은 노드를 선택한 뒤 모달을 연다.
  const handleInlineOpenTranslations = useCallback((): void => {
    const editing = inlineEditing;
    if (!editing) return;
    setInlineEditing(null);
    // 선택 동기화 — 모달이 선택 노드 기준 좌표/회피를 쓰므로 먼저 선택.
    selection.handleSelect('', { dataset: { editorPath: editing.domPath } as DOMStringMap });
    openPropertyModalFor(editing.path, 'translation');
  }, [inlineEditing, selection, openPropertyModalFor]);

  // 인라인 편집 중 노드 — 서식 툴바/편집 오버레이 미러가 그 노드의 className·styleControls 를
  // 조회한다. patch 시점의 최신 문서를 직접 읽도록 `docCtx.document` 를 dep 으로 둔다
  // (`components` 메모를 거치면 한 단계 stale 되어 서식 변경이 툴바 active·
  // 편집 오버레이에 반영 안 되던 결함). patchLayout 이 새 document 객체를 set 하므로 이 memo 가
  // 즉시 재계산된다.
  const inlineEditingNode = useMemo<EditorNode | null>(() => {
    if (!inlineEditing) return null;
    const fresh = (docCtx?.document?.raw?.components as EditorNode[] | undefined) ?? components;
    return findNodeByPath({ children: fresh }, inlineEditing.path);
  }, [inlineEditing, docCtx?.document, components]);

  // '+데이터' 커서 위치 삽입 — 인라인 칩 편집 중 새 데이터 칩을 커서 위치에
  // 끼운다. (1) `insertBindingIntoParamKey` 가 node.text 에 `|pN=` 추가 + 전 로케일 키 값 자리표시
  // (편집 로케일=커서 charIndex, 그 외=문장 끝)를 저장-지연 버퍼에 기록하고, (2) 그 새 node.text 로
  // 레이아웃을 패치한다(history push — node.text 변경이므로). 칩 위젯에는 편집 로케일 갱신 키 값을
  // 돌려줘 즉시 칩을 추가 렌더한다(라이브). 키 값 영속은 레이아웃 [저장] 시 flushPending 으로 동기.
  // inlineEditingNode 선언 뒤에 둬야 한다(callback 본문에서 참조 — 선언 전 사용 방지).
  const handleInlineParamChipInsert = useCallback(
    async (charIndex: number, candidate: BindingCandidate): Promise<string | null> => {
      const editing = inlineEditing;
      if (!docCtx || !editing) return null;
      const nodeText = typeof inlineEditingNode?.text === 'string' ? inlineEditingNode.text : '';
      // 미키화 노드(`!isParamKey` — 평문 또는 평문+raw 보간 Shape A)의 '+데이터'.
      // 종전엔 `!isParamKey` 면 즉시 return null → 인라인 데이터 추가가 **무반응**이었다. 미키화
      // 노드는 keyifyWithNewBinding 으로 먼저 키화한 뒤 그 키 텍스트로 patch 한다(속성 탭 insertDataKeyify
      // 와 동일 SSoT). lang 키(`$t:policy.*`)는 편집 대상 사전(state.templateIdentifier)으로 평문화한다.
      let res: { kind: 'ok'; text: string; paramName?: string } | { kind: 'error'; message?: string };
      if (!editing.isParamKey) {
        // lang 키 평문화는 editorAwareT(window.G7Core.t 우선 — 편집 대상 사전)로 한다 — 싱글톤 엔진은
        // 캔버스 격리 엔진과 달라 `policy.*` 를 못 찾는다(빈값 → 라벨 소실). 속성 탭과 동일 SSoT.
        // 정규식 키 문자 클래스에서 콜론 제외 — Shape A `$t:policy.published_at:` 의 끝 `:` 은 라벨
        // 구분자(평문)지 키 일부가 아니다(콜론 삼키면 미해석 → 라벨 소실). 속성 탭 resolveLang 과 동일.
        const resolveLang = (s: string): string =>
          s
            .replace(/\$t:[a-zA-Z0-9._-]+/g, (tok) => {
              const r = editorAwareT(tok.slice(3));
              return r && r !== tok.slice(3) && !r.startsWith('$t:') ? r : '';
            })
            .replace(/\s+/g, ' ')
            .trim();
        res = await keyifyWithNewBinding(
          state.templateIdentifier,
          state.selectedRoute?.layoutName ?? null,
          state.locale,
          nodeText,
          charIndex,
          candidate.sourceId,
          candidate.path,
          'scalar',
          resolveLang,
          // S9-N4 — lang named-param Shape(`$t:user.*|count={{}}`)를 deriveChipModel 로 분해하기
          // 위해 lang **값** 해석기(editorAwareT — window.G7Core.t 우선, 편집 대상 사전)를 넘긴다.
          editorAwareT,
        );
      } else {
        res = await insertBindingIntoParamKey(
          state.templateIdentifier,
          state.locale,
          nodeText,
          charIndex,
          candidate.sourceId,
          candidate.path,
          'scalar',
        );
      }
      if (res.kind === 'error') return null;
      // node.text 패치(`|pN=` 추가) — 인라인 편집 노드 path 에 교체.
      const path = editing.path;
      let nextCaptured: EditorNode[] = [];
      docCtx.patchLayout((current) => {
        const r: EditorNode = { children: current };
        const next = patchNode(r, path, (cur) => ({ ...cur, text: res.text }));
        nextCaptured = (next.children as EditorNode[]) ?? [];
        return nextCaptured;
      });
      history.push({ actionKind: 'inline_text_edit', label: 'inline_insert_binding', snapshot: nextCaptured });
      // G-2 충돌 차단 — 미키화 노드(`!isParamKey`)가 keyifyWithNewBinding 으로
      // 방금 키화됐으면(`res.key`), 인라인 편집 상태를 **키화 완료**로 전환한다. 이렇게 해야:
      //  ① 둘째 '+데이터'가 미키화 분기(keyifyWithNewBinding)로 다시 들어가 이미 키화된 node.text
      //     (`$t:custom.X|...`)를 또 키화하지 않고 isParamKey 분기(insertBindingIntoParamKey)로 간다.
      //  ② 저장 시 칩 편집기 commit 이 새 customKey 를 받아 keyifyChipValue(재키화) 대신
      //     putSingleLocaleKeyValue 경로를 타서 둘째 키 생성을 막는다(activeKeyRef 전파는 위젯이
      //     customKey prop 변화로 동기 — InlineParamChipEditor effect). 본 상태 갱신이 SSoT.
      const keyifiedKey = res.kind === 'ok' && 'key' in res ? (res as { key?: string }).key : undefined;
      if (!editing.isParamKey && keyifiedKey) {
        setInlineEditing((prev) =>
          prev && prev.path === path
            ? { ...prev, isParamKey: true, customKey: keyifiedKey, chipValue: undefined, chipParamLabels: undefined }
            : prev,
        );
      }
      // 사전 재fetch 신호 — 캔버스/번역탭이 갱신된 키 값을 다시 읽도록.
      try {
        window.dispatchEvent(
          new CustomEvent(EDITOR_TRANSLATIONS_REFRESHED_EVENT, {
            detail: { templateIdentifier: state.templateIdentifier, locale: state.locale },
          }),
        );
      } catch {
        /* 무해 — 다음 진입 시 최신 사전 로드 */
      }
      // 편집 로케일 갱신 키 값 — 버퍼에 방금 기록됐으므로 getPendingValue 로 읽어 칩 위젯에 반영.
      // 미키화 경로(keyifyWithNewBinding)는 editing.customKey 가 null 이므로 새 키 텍스트(res.text)에서
      // 키를 추출한다(`$t:custom.X|p0=..` → `custom.X`).
      const parsedNew = extractParamBindings(res.text);
      const key = editing.customKey ?? parsedNew?.key ?? '';
      return key ? getPendingValue(key, state.locale) ?? null : null;
    },
    [docCtx, inlineEditing, inlineEditingNode, state.templateIdentifier, state.locale, state.selectedRoute, history, editorAwareT],
  );

  // 칩 우측 X = 데이터 연결 '해제' — 삽입(handleInlineParamChipInsert)과
  // 대칭. node.text 의 `|pN=` 제거(patchLayout + history) + 전 로케일 키 값 `{pN}` 제거 + 캔버스/번역탭
  // 동기화 신호(disconnectParamAllLocales 내부 발화) 후 편집 로케일 갱신 키 값을 반환(칩 위젯 즉시 반영).
  const handleInlineParamChipRemove = useCallback(
    async (paramName: string): Promise<string | null> => {
      const editing = inlineEditing;
      if (!docCtx || !editing) return null;
      const nodeText = typeof inlineEditingNode?.text === 'string' ? inlineEditingNode.text : '';
      const parsed = extractParamBindings(nodeText);
      const key = editing.customKey ?? parsed?.key ?? null;
      // node.text 패치(`|pN=` 제거) — 인라인 편집 노드 path 에 교체.
      const nextText = removeParamBinding(nodeText, paramName);
      const path = editing.path;
      let nextCaptured: EditorNode[] = [];
      docCtx.patchLayout((current) => {
        const r: EditorNode = { children: current };
        const next = patchNode(r, path, (cur) => ({ ...cur, text: nextText }));
        nextCaptured = (next.children as EditorNode[]) ?? [];
        return nextCaptured;
      });
      history.push({ actionKind: 'inline_text_edit', label: 'inline_remove_binding', snapshot: nextCaptured });
      // 전 로케일 키 값 `{pN}` 제거 + 캔버스/번역탭 재읽기 신호(disconnectParamAllLocales 내부 발화).
      if (key) {
        await disconnectParamAllLocales(state.templateIdentifier, key, paramName, state.locale);
      }
      return key ? getPendingValue(key, state.locale) ?? null : null;
    },
    [docCtx, inlineEditing, inlineEditingNode, state.templateIdentifier, state.locale, history],
  );

  // 편집 오버레이 미러용 라이브 className/style — 캔버스 프레임 DOM 의 편집 대상 노드에서
  // 읽는다. 문서 상태(inlineEditingNode)는 patch 직후 한 단계 stale 되고,
  // 편집 오버레이 서식 미러 — 편집 대상 캔버스 노드(DOM)를 MutationObserver 로 관찰해 그
  // className/style 을 그대로 따라간다. 캔버스 DOM 은 DynamicRenderer 가 항상
  // 최신으로 커밋하므로(서식 툴바·속성 모달·외부 패치 무엇이든), 그 변화를 attribute 관찰로
  // 받으면 어떤 타이밍 가정도 없이 미러가 정확히 일치한다(문서 상태/RAF/patcher 동기성 의존 제거).
  const [liveMirror, setLiveMirror] = useState<{ className?: string; style?: React.CSSProperties; effectiveColor?: string }>({});
  const MIRROR_STYLE_KEYS = ['fontSize', 'color', 'fontStyle', 'textDecoration', 'textAlign', 'letterSpacing', 'lineHeight', 'fontFamily'] as const;
  useEffect(() => {
    if (!inlineEditing || !frameEl || typeof MutationObserver === 'undefined') {
      setLiveMirror({});
      return;
    }
    // selector 는 DOM 원문 path(domPath)로 — 파싱된 number[] 의 join 은 `.children.` 누락으로 불일치.
    const sel = `[data-editor-path="${cssEscape(inlineEditing.domPath)}"]`;
    // 관찰 대상 노드를 매번 새로 질의해 읽는다 — DynamicRenderer 가 patch 시 같은 DOM 노드를
    // 재사용하지 않고 교체하면(특히 classToken 적용으로 트리 재조정 시) 진입 시 캡처한 target 은
    // 분리(detached)되어 그 노드의 MutationObserver 는 새 노드의 class 변화를 못 본다(
    // E4 — fontSize/textAlign 선택 시 캔버스엔 적용되나 편집 오버레이 미러가 빈 채로 남던 결함).
    // 따라서 (a) 원본 노드의 attribute 변화(in-place 갱신)와 (b) 부모 subtree 의 노드 교체
    // (childList/subtree) 둘 다 관찰하고, 콜백에서 selector 로 현재 노드를 다시 찾아 읽는다.
    const read = (): void => {
      const el = frameEl.querySelector(sel) as HTMLElement | null;
      if (!el) return;
      const style: React.CSSProperties = {};
      for (const key of MIRROR_STYLE_KEYS) {
        const v = el.style.getPropertyValue(key.replace(/([A-Z])/g, '-$1').toLowerCase());
        if (v) (style as Record<string, string>)[key] = v;
      }
      // 대상 노드의 실제 글자색(computed, 캔버스 다크 컨텍스트 반영)도 함께 추종한다.
      // 서식 색 토글 시에도 MutationObserver 가 재측정해 오버레이 대비 배경/글자색이 즉시 따라온다.
      const effectiveColor = measureNodeColor(frameEl, inlineEditing.domPath) ?? undefined;
      setLiveMirror({ className: el.className, style, effectiveColor });
    };
    read(); // 진입 즉시 1회
    const target = frameEl.querySelector(sel) as HTMLElement | null;
    if (!target) return;
    // 대상 노드 자체의 속성 변화 + 조상 subtree 의 노드 교체를 모두 관찰한다. 조상은 frameEl
    // (캔버스 프레임 루트) — 어떤 깊이에서 노드가 교체돼도 selector 재질의로 최신 노드를 읽는다.
    const obs = new MutationObserver(read);
    obs.observe(target, { attributes: true, attributeFilter: ['class', 'style'] });
    obs.observe(frameEl, { childList: true, subtree: true });
    return () => obs.disconnect();
    // path 변경(다른 노드 진입) 시 재관찰. formatVersion 은 노드 DOM 변화로 자동 반영되므로 불필요.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [inlineEditing?.path ? serializeEditorPath(inlineEditing.path) : null, frameEl]);

  // 서식 툴바 적용 — applyFn 을 patch 시점의 fresh 노드(patcher 의 current)에 적용해 patchLayout.
  // 미러는 캔버스 DOM MutationObserver(liveMirror)가 자동 추종하므로 여기선 캡처 불필요
  const handleInlineApplyControl = useCallback(
    (controlKey: string, applyFn: (freshNode: EditorNode) => EditorNode): void => {
      if (!docCtx || !inlineEditing) return;
      const path = inlineEditing.path;
      let nextCaptured: EditorNode[] = [];
      let patchedName: string | null = null;
      docCtx.patchLayout((current) => {
        const r: EditorNode = { children: current };
        const next = patchNode(r, path, (cur) => {
          const patched = applyFn(cur);
          patchedName = typeof patched.name === 'string' ? patched.name : null;
          return patched;
        });
        nextCaptured = (next.children as EditorNode[]) ?? [];
        return nextCaptured;
      });
      history.push({ actionKind: 'inline_text_edit', label: `inline_format ${controlKey}`, snapshot: nextCaptured });
      trackEditorPropertyPatch({
        source: 'style_control',
        componentPath: serializeEditorPath(path),
        componentName: patchedName,
        patchKey: controlKey,
        timestamp: Date.now(),
      });
    },
    [docCtx, inlineEditing, history],
  );

  // ── 캔버스 인플레이스 오버레이 디스패치 ──────
  // capability.canvasOverlay.kind 를 가진 컴포넌트(표)가 선택됐거나, 그 컴포넌트의 **자손
  // 셀이 선택**됐을 때 오버레이를 그 표에 앵커링해 마운트한다( 재설계: 셀 선택은
  // 코어 표준 선택으로 받아 표 본체의 드래그/더블클릭 인라인 편집을 살린다 — 불투명 셀 클릭
  // 레이어 제거). 코어는 kind 로 디스패치만(table/탭 무관, 빌트인도 특권 0). 미등록/미해당이면
  // null → 기존 코어 선택/삽입 오버레이 디그레이드(회귀 0).
  // 선택 path(DOM 문자열)에서 자기→조상 순으로 올라가며 canvasOverlay capability 보유 노드를
  // 찾는다. 찾으면 그 노드=표, 선택 path 가 표보다 깊으면 그 깊은 path 가 선택된 셀이다.
  const overlayContext = useMemo<{
    kind: string;
    params?: Record<string, unknown>;
    tablePath: string;
    tableNode: EditorNode;
    selectedCellRel: string | null; // 표 기준 상대 path(`children.a.children.b`) 또는 null(표 자체 선택)
  } | null>(() => {
    const selPath = selection.selectedPath;
    if (!selPath || !rootNode) return null;
    // 자기→조상 path 후보(긴 것부터) 생성.
    const candidates: string[] = [];
    let p: string | null = selPath;
    while (p) {
      candidates.push(p);
      const m = p.match(/^(.*)\.children\.\d+$/);
      p = m ? m[1]! : null;
    }
    for (const cand of candidates) {
      const idx = parseEditorPath(cand);
      if (!idx) continue;
      const node = findNodeByPath(rootNode, idx);
      const name = typeof node?.name === 'string' ? node.name : null;
      if (!name) continue;
      const cap = getComponentCapability(spec, name) as { canvasOverlay?: { kind?: unknown; params?: unknown } } | null;
      const kind = cap?.canvasOverlay?.kind;
      if (typeof kind !== 'string' || !kind || !node) continue;
      const params =
        cap!.canvasOverlay!.params && typeof cap!.canvasOverlay!.params === 'object'
          ? (cap!.canvasOverlay!.params as Record<string, unknown>)
          : undefined;
      const selectedCellRel = cand === selPath ? null : selPath.slice(cand.length + 1);
      return { kind, params, tablePath: cand, tableNode: node, selectedCellRel };
    }
    return null;
  }, [selection.selectedPath, rootNode, spec]);

  const CanvasOverlayComp = overlayContext ? getCanvasOverlay(overlayContext.kind) : null;

  // 표 박스 측정(앵커 — 선택 박스가 아니라 표 자체). 셀 선택 시에도 오버레이는 표에 앵커.
  const overlayTableBox = useMemo<OverlayBox | null>(() => {
    if (!CanvasOverlayComp || !frameEl || !overlayContext) return null;
    return measurePathBox(frameEl, overlayContext.tablePath);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [CanvasOverlayComp, frameEl, overlayContext, selectedBox, components]);

  // 표 자손 셀 박스 측정 — `data-editor-path` 가 표 path 접두인 DOM 을 측정해 표 기준 상대
  // path + 박스로 넘긴다. 오버레이가 grid 좌표로 역매핑. (frame 기준 좌표.)
  const overlayCellBoxes = useMemo<Array<OverlayBox & { path: string }>>(() => {
    if (!CanvasOverlayComp || !frameEl || !overlayContext) return [];
    const base = overlayContext.tablePath;
    const baseEsc = cssEscape(base);
    const out: Array<OverlayBox & { path: string }> = [];
    // (1) 자식 노드 셀(표 등 STRUCT-TREE) — `data-editor-path` 가 표 path 접두인 DOM.
    const descendants = frameEl.querySelectorAll<HTMLElement>(`[data-editor-path^="${baseEsc}.children."]`);
    descendants.forEach((el) => {
      const full = el.dataset.editorPath ?? '';
      if (!full.startsWith(base + '.')) return;
      const rel = full.slice(base.length + 1); // `children.a.children.b...`
      const b = measureOverlay(el, frameEl);
      if (!b) return;
      out.push({ path: rel, top: b.top, left: b.left, width: b.width, height: b.height });
    });
    // (2) 배열 prop 항목(ARRAY-PROP 인플레이스 — TabNavigation tabs 등). 항목은 자식
    // 노드가 아니라 `node.props[arrayProp]` 라 `data-editor-path` 가 없다. 컴포넌트가 편집
    // 모드에서 각 항목 DOM 에 `data-editor-item-path="<자기 path>.props.<arrayProp>.<i>"` 마커를
    // 부여하면 코어가 그 박스를 측정해 표(노드) 기준 상대 path(`props.tabs.0` 등)로 넘긴다.
    // 오버레이가 그 상대 path 로 항목을 역매핑(부록4-bis registerCanvasOverlay 레퍼런스 — 1D 항목).
    const itemEls = frameEl.querySelectorAll<HTMLElement>(`[data-editor-item-path^="${baseEsc}."]`);
    itemEls.forEach((el) => {
      const full = el.dataset.editorItemPath ?? '';
      if (!full.startsWith(base + '.')) return;
      const rel = full.slice(base.length + 1); // `props.tabs.0` 등
      const b = measureOverlay(el, frameEl);
      if (!b) return;
      out.push({ path: rel, top: b.top, left: b.left, width: b.width, height: b.height });
    });
    return out;
    // selectedBox/components 를 dep 에 두어 트리 변경(행/열/병합/항목추가) 후 재측정.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [CanvasOverlayComp, frameEl, overlayContext, selectedBox, components]);

  // 인플레이스 오버레이 노드 패치 — **표 노드**(선택 셀이 아니라)를 통째 교체한다. 속성 패널
  // TableEditor 의 onPatchNode 와 동일 경로(patchNode + patchLayout + history) — 단일 SSoT.
  const patchOverlayNode = useCallback(
    (patched: EditorNode): void => {
      const tableIdx = overlayContext ? parseEditorPath(overlayContext.tablePath) : null;
      if (!docCtx || !tableIdx) return;
      let nextCaptured: EditorNode[] = [];
      docCtx.patchLayout((current) => {
        const r: EditorNode = { children: current };
        const next = patchNode(r, tableIdx, () => patched);
        nextCaptured = (next.children as EditorNode[]) ?? [];
        return nextCaptured;
      });
      history.push({ actionKind: 'inline_text_edit', label: 'inplace_edit', snapshot: nextCaptured });
      trackEditorPropertyPatch({
        source: 'style_control',
        componentPath: overlayContext?.tablePath ?? '',
        componentName: typeof patched.name === 'string' ? patched.name : null,
        patchKey: 'canvas_inplace',
        timestamp: Date.now(),
      });
    },
    [docCtx, selectedPathIndexes, history],
  );

  if (!frameEl) return null;

  return (
    <div
      className="g7le-editor-canvas-overlay"
      data-testid="g7le-editor-canvas-overlay"
      style={{ position: 'absolute', inset: 0, pointerEvents: 'none' }}
    >
      {/* eslint-disable-next-line — see: empty container placeholder withdrawn; template defaultNode owns the visual cue. */}

      {/* 항목7 — 속성 편집 중 선택 외 영역 딤/잠금 (선택 박스는 구멍으로 남겨 밝게 유지).
 단 pick 모드(🎯 영역 선택 /)에서는 딤을 걷는다 — 딤이
          `pointerEvents:auto`+onClick block 으로 선택 박스 외 캔버스 클릭을 전부 가로채, 모달
          편집 중 영역 선택 시 어느 요소도 클릭되지 않던 결함. pick 모드는 "캔버스에서 요소 고르기"
          이므로 편집 잠금 딤이 의미 없다(요소 선택 후 모달 복원 시 딤도 자동 복귀). */}
      {!pickRequestId && renderEditLock()}

      {/* 컴포넌트 영역 pick 모드 안내 — 🎯 영역 선택 진입 시 캔버스 상단에
          "요소를 클릭하세요 · Esc 취소" 배너. hover 는 기존 메커니즘이 표시(점선). 취소 버튼은
          pointerEvents 활성(배너 외 캔버스는 클릭으로 pick). */}
      {pickRequestId && (
        <div
          data-testid="g7le-canvas-pick-banner"
          style={{
            position: 'absolute',
            top: 8,
            left: '50%',
            transform: 'translateX(-50%)',
            zIndex: 9999,
            display: 'flex',
            alignItems: 'center',
            gap: 12,
            padding: '8px 14px',
            background: '#1e293b',
            color: '#fff',
            borderRadius: 8,
            fontSize: 13,
            boxShadow: '0 4px 12px rgba(0,0,0,0.25)',
            pointerEvents: 'auto',
          }}
        >
          <span>🎯 {editorAwareT('layout_editor.target_picker.canvas_pick_hint')}</span>
          <button
            type="button"
            data-testid="g7le-canvas-pick-cancel"
            onClick={() => finishPick(pickRequestId, null)}
            style={{
              border: '1px solid #475569',
              borderRadius: 6,
              background: 'transparent',
              color: '#cbd5e1',
              cursor: 'pointer',
              fontSize: 12,
              padding: '3px 10px',
            }}
          >
            {editorAwareT('layout_editor.target_picker.cancel')}
          </button>
        </div>
      )}

      {/* 출처 기반 역 스포트라이트 음영. **별도 편집 모드(extension/base/modal/
          iteration)에서만** 마운트한다. 편집 가능 영역(편집 중 확장 조각·base 본체·모달
          children 등)만 밝게 두고 나머지 전체를 한 장의 음영으로 덮는다.

          route 모드에는 마운트하지 않는다 (D-16 회귀 수정) — route 모드는 확장 주입 영역을
          기존 hover/선택 어포던스("확장 편집")로 표시하며, 음영 레이어가 캔버스 위에 깔리면
          그 표식과 경합해 표식이 사라진다. route 모드는 잠금 매트릭스(classifyLockKind →
          extension)가 어포던스를 띄우므로 별도 음영이 불필요하다. */}
      {state.editMode !== 'route' && (
        <SourceLockDimLayer
          frameEl={frameEl}
          components={components}
          editMode={state.editMode}
          currentExtensionId={currentExtensionId}
          editableRootPath={editableRootPath}
        />
      )}

      {/* 인라인 텍스트 편집 — 더블클릭 진입 시 contentEditable 오버레이 +
          서식 툴바(컴포넌트 styleControls 기반). 평문→키 생성 / 기존 키 값 수정. */}
      {inlineEditing && (
        // 칩 편집기 분기 — (a) 키화된 param 키(`$t:custom.X|pN={{}}`) 또는 (b) **데이터 든 미키화
        // 노드**(plain_with_binding — chipValue 보유"데이터는 첫 진입부터
        // 칩"). 양쪽 모두 데이터를 드래그 칩으로 보이고 평문만 편집한다. (b)는 내용 변경 시 키화한다.
        ((inlineEditing.isParamKey && inlineEditing.customKey) || inlineEditing.chipValue) && inlineEditingNode ? (
          // param 키 노드 — 칩 합성 위젯 오버레이. 평문 편집 + 보간 드래그 칩.
          // 일반 평문 InlineTextEditor 대신 분기(보간이 박힌 노드는 평문만 편집하면 보간이
          // 깨지므로 — 칩으로 보호). 저장은 현재 로케일 키 값 PUT(로케일 독립).
          //
          // 서식 툴바(InlineTextToolbar)는 칩 유무와 직교(노드 className/style 을 바꿈 — 텍스트
          // 내용과 무관)하므로 param 키에도 동일하게 마운트한다(칩이
          // 붙어도 굵게/정렬/글자색 서식은 딸려와야 함). 평문 노드와 동일 UX.
          <>
            {inlineEditingNode && (
              <InlineTextToolbar
                node={inlineEditingNode}
                spec={spec}
                t={editorAwareT}
                onApplyControl={handleInlineApplyControl}
                box={inlineEditing.box}
                scope={{
                  colorScheme: state.previewColorScheme === 'dark' ? 'dark' : 'base',
                  breakpoint: deviceToBreakpoint(state.previewDevice, inlineEditingNode, state.previewCustomWidth),
                }}
              />
            )}
            <InlineParamChipEditor
              box={inlineEditing.box}
              node={inlineEditingNode}
              customKey={inlineEditing.customKey ?? null}
              initialChipValue={inlineEditing.chipValue}
              chipParamLabels={inlineEditing.chipParamLabels}
              onKeyify={handleInlineChipKeyify}
              templateIdentifier={state.templateIdentifier}
              locale={state.locale}
              t={editorAwareT}
              candidates={bindingCandidates}
              onInsertBinding={handleInlineParamChipInsert}
              onRemoveBinding={handleInlineParamChipRemove}
              onCommit={handleInlineParamChipCommit}
              onCancel={handleInlineCancel}
            />
          </>
        ) : (
        <>
          {inlineEditingNode && (
            <InlineTextToolbar
              node={inlineEditingNode}
              spec={spec}
              t={editorAwareT}
              onApplyControl={handleInlineApplyControl}
              box={inlineEditing.box}
              scope={{
                colorScheme: state.previewColorScheme === 'dark' ? 'dark' : 'base',
                breakpoint:
                  inlineEditingNode
                    ? deviceToBreakpoint(state.previewDevice, inlineEditingNode, state.previewCustomWidth)
                    : 'base',
              }}
            />
          )}
          <InlineTextEditor
            box={inlineEditing.box}
            initialValue={inlineEditing.initialValue}
            isCustomKey={inlineEditing.isCustomKey}
            t={editorAwareT}
            onCommit={handleInlineCommit}
            onCancel={handleInlineCancel}
            // 서식 툴바 변경을 편집 오버레이에 즉시 미러링 (오버레이가 노드를
            // 덮어 서식 미반영처럼 보이던 결함). 캔버스 DOM(항상 최신)에서 읽은 live className/style.
            mirrorClassName={liveMirror.className}
            mirrorStyle={liveMirror.style}
            // 편집 대상 노드의 캔버스 내 실제 글자색(다크 컨텍스트 반영, oklch/rgb 정규화). 오버레이가
            // 다크 컨텍스트 밖이라 자체 computed 로는 색이 어긋나므로, 대비 배경·글자색 판정의
            // 결정적 소스로 전달한다. live(서식 추종) 우선, 없으면 진입 시 캡처값.
            nodeEffectiveColor={liveMirror.effectiveColor ?? inlineEditing.nodeEffectiveColor}
            // 힌트 배지 클릭 → 이 노드의 [번역] 탭으로 진입(전체 로케일 일괄 편집).
            onOpenTranslations={handleInlineOpenTranslations}
          />
        </>
        )
      )}

      {/* pick 모드 — 일반 선택 UI(hover 점선·선택 실선·↑타입칩·
          리사이즈·＋요소추가·ⓘ)를 전부 숨긴다. "지금은 영역 선택 중"임을 명확히 하고, 아래 pick
          전용 hover 박스만 둔다(id 보유=강조, id 없음=불가 안내). */}
      {!pickRequestId && (
      <ElementOverlay
        hoverBox={hoverBox}
        selectedBox={selectedBox}
        lockKind={selection.selectedLockKind}
        navAffordance={selection.selectedNavAffordance}
        onEditProps={handleEditProps}
        onDuplicate={handleDuplicate}
        onDelete={handleDelete}
        onSeparateBranch={
          branchSeparation?.mode === 'separate' ? handleSeparateBranch : undefined
        }
        separateBranchLabel={branchSeparationLabels?.separate}
        onMergeBranch={
          branchSeparation?.mode === 'merge' ? handleMergeBranch : undefined
        }
        mergeBranchLabel={branchSeparationLabels?.merge}
        definedDeviceBranches={definedDeviceBranches}
        onJumpToDevice={definedDeviceBranches.length > 0 ? handleJumpToDevice : undefined}
        onLinkEditDestination={handleLinkEditDestination}
        onEditBase={handleEditBase}
        onEditExtension={handleEditExtension}
        extensionLabel={selectedExtensionLabel}
        baseLayoutLabel={selection.selectedBaseLayout}
        dataSourceLabel={selection.selectedDataSourceId}
        branchLabel={selectedBranchLabel}
        onEditIteration={
          // 이미 반복 항목 편집 모드(iteration_item)면 그 안에서 또 "반복 항목 편집" 진입
          // 어포던스를 띄우지 않는다. 다른 모드에서만 노출.
          selection.selectedIsIteration && state.editMode !== 'iteration_item'
            ? handleEditIteration
            : undefined
        }
        resizeEnabledAxes={resize.enabledAxes}
        onResizeHandlePointerDown={
          resize.enabledAxes.width || resize.enabledAxes.height
            ? handleResizeHandlePointerDown
            : undefined
        }
        selectedName={selectedTypeName}
        onSelectParent={parentPathStr ? handleSelectParent : undefined}
      />
      )}

      {/* pick 모드 전용 hover 박스 — id 보유 노드는 강조(파란
          실선, 선택 가능), id 없는 노드는 회색 빗금 + "연결하려면 ID가 필요합니다" 안내(선택 불가).
          좌표는 frame-local → 오버레이 레이어 기준이므로 그대로 absolute 배치. */}
      {pickRequestId && pickHover && (
        <div
          data-testid="g7le-canvas-pick-hover"
          data-pick-has-id={pickHover.hasId ? 'true' : 'false'}
          style={{
            position: 'absolute',
            left: pickHover.box.left,
            top: pickHover.box.top,
            width: pickHover.box.width,
            height: pickHover.box.height,
            pointerEvents: 'none',
            zIndex: 9998,
            boxSizing: 'border-box',
            border: pickHover.hasId ? '2px solid #2563eb' : '2px dashed #94a3b8',
            background: pickHover.hasId
              ? 'rgba(37,99,235,0.10)'
              : 'repeating-linear-gradient(45deg, rgba(148,163,184,0.18) 0, rgba(148,163,184,0.18) 6px, transparent 6px, transparent 12px)',
            cursor: pickHover.hasId ? 'pointer' : 'not-allowed',
          }}
        >
          {!pickHover.hasId && (
            <span
              data-testid="g7le-canvas-pick-hover-hint"
              style={{
                position: 'absolute',
                top: 0,
                left: 0,
                transform: 'translateY(-100%)',
                background: '#475569',
                color: '#fff',
                fontSize: 11,
                lineHeight: 1.4,
                padding: '2px 6px',
                borderRadius: 4,
                whiteSpace: 'nowrap',
              }}
            >
              {editorAwareT('layout_editor.target_picker.needs_id')}
            </span>
          )}
        </div>
      )}

      {/* 코어 4방향 + 오버레이 — 표(인플레이스 오버레이) 노드에서도 유지한다(:
          "요소 추가 버튼은 없애면 안 되고 띄워진 거리에 표시"). 표 거터 레일은 코어 + 밴드
          바깥으로 더 이격해(TableInplaceOverlay 의 COL_RAIL/ROW_RAIL gap) 겹치지 않게 한다.

          잠긴 영역(extension/extension_point/base/partial/data_bound)에서도 + 버튼을
 띄운다.
          기준 박스는 anchor 박스(insertionAnchorBox): 잠긴 묶음/이터레이션 데이터 영역의
          경계라, + 버튼이 그 바깥에 떠 묶음의 형제로 삽입된다. 삽입 위치는 useInsertionPoints
          가 anchor 부모(라우트 소유) 기준으로 계산. insertion.points 가 비면
          InsertionAffordances 가 자체 null 반환.
          pick 모드에서는 일반 선택 UI 와 함께 숨긴다(영역 선택 중 요소 추가 불가). */}
      {!pickRequestId && (
      <InsertionAffordances
        selectedBox={insertionAnchorBox}
        points={insertion.points}
        onAddRequest={handleAddRequest}
      />
      )}

      {/* 캔버스 인플레이스 오버레이 — 선택 노드 capability.canvasOverlay.kind 가
          등록돼 있으면 그 오버레이를 마운트(table=셀 단위 핸들). 코어 측정 박스/콜백 주입.
          kind-agnostic — 코어/템플릿 등록분 모두 동일 경로. 잠금 노드는 미표시. */}
      {CanvasOverlayComp && overlayContext && overlayTableBox && selection.selectedLockKind === 'none' && (
        <CanvasOverlayComp
          node={overlayContext.tableNode}
          params={overlayContext.params}
          nodeBox={overlayTableBox}
          cellBoxes={overlayCellBoxes}
          selectedCellPath={overlayContext.selectedCellRel ?? undefined}
          colorScheme={state.previewColorScheme === 'dark' ? 'dark' : 'light'}
          t={editorAwareT}
          onPatchNode={patchOverlayNode}
          onInsertChild={handleInsert}
          onRemoveChild={(path) => {
            const idx = parseEditorPath(path);
            if (!docCtx || !idx) return;
            let nextCaptured: EditorNode[] = [];
            docCtx.patchLayout((current) => {
              const next = removeNode({ children: current }, idx);
              nextCaptured = (next.children as EditorNode[]) ?? [];
              return nextCaptured;
            });
            history.push({ actionKind: 'remove', label: 'inplace_remove', snapshot: nextCaptured });
          }}
          onRequestInlineEdit={(cellRel) => {
            // 셀 상대 path → 절대 path 로 변환 후 엔진 인라인 편집 진입(단일 클릭=선택+인라인).
            if (!overlayContext) return;
            requestInlineEditAt(`${overlayContext.tablePath}.${cellRel}`);
          }}
        />
      )}

      {/* 드래그 앤 드롭 레이어 (S5b) — frame 위 draggable 핸들 + 드롭 인디케이터 +
          DragOverlay 고스트. docCtx 가 있어야 트리 변형 가능. */}
      {docCtx && (
        <DndCanvasLayer
          frameEl={frameEl}
          nesting={nesting}
          editMode={state.editMode}
          currentExtensionId={currentExtensionId}
          editableRootPath={editableRootPath}
          components={components}
          patchLayout={docCtx.patchLayout}
          pushHistory={pushMoveHistory}
          selectedPath={selection.selectedPath}
          onSelectPath={(path) =>
            selection.handleSelect('', { dataset: { editorPath: path } as DOMStringMap })
          }
          onMovePath={(path) =>
            selection.handleSelect('', { dataset: { editorPath: path } as DOMStringMap })
          }
          onRequestInlineEdit={requestInlineEditAt}
        />
      )}

      {/* ComponentPalette is rendered inside EditorModalRoot via modal.open — not mounted inline here. */}
    </div>
  );
}

/**
 * componentPath 부모 컨테이너의 컴포넌트 이름을 트리에서 탐색.
 */
function resolveContainerNameAt(components: EditorNode[], parentPath: ComponentPath): string | null {
  if (parentPath.length === 0) return null; // 루트 삽입
  const root: EditorNode = { children: components };
  const node = traverse(root, parentPath);
  if (!node) return null;
  return typeof node.name === 'string' ? node.name : null;
}

function traverse(root: EditorNode, path: ComponentPath): EditorNode | null {
  // 세그먼트 union(responsive 분기 포함) 하강은 공용 findNodeByPath 가 SSoT.
  // 로컬 number-only 하강을 두면 responsive 분기 path 에서 base 노드를 잘못 반환한다.
  return findNodeByPath(root, path);
}

/**
 * CSS.escape 폴리필 — selector 안전 escape.
 */
function cssEscape(value: string): string {
  if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') {
    return CSS.escape(value);
  }
  // 점/숫자 외 문자만 정밀 escape — 본 path 는 `0.children.2.iteration.1` 형태로
  // 점/숫자/영문만 포함하므로 단순 fallback.
  return value.replace(/[^a-zA-Z0-9_\-.]/g, (ch) => `\\${ch}`);
}

/**
 * path 의 오버레이 박스 측정 — 단일 DOM 요소가 있으면 그것을, 없으면 **이터레이션
 * 원본 노드**로 보고 그 펼침 인스턴스(`path.iteration.N`)들의 union 으로 폴백한다
 * (iteration 원본 노드는 단일 DOM 요소로 렌더되지 않으므로 가상
 * 묶음 선택 시 인스턴스 union 을 선택 박스로 사용). path 가 null 이면 null.
 */
/**
 * 편집 대상 노드의 캔버스 내 실제 글자색(computed)을 읽는다.
 *
 * 인라인 편집 오버레이는 다크 컨텍스트(`.g7le-preview-dark`) 밖에 마운트되어 `dark:text-white`
 * 류 토큰이 발동하지 않아, 오버레이 자체 computed 로는 다크 모드 흰 글자 노드의 색이 어긋난다.
 * 대상 노드는 캔버스 프레임(다크 컨텍스트) 안에 있으므로 그 노드의 색을 읽으면 현재 모드가
 * 반영된 정확한 색을 얻는다. 텍스트가 자손에 중첩된 경우(장식 span 등)를 대비해 텍스트를 가진
 * 가장 깊은 자손의 색을 우선한다. 반환값은 브라우저가 정규화한 색 문자열(oklch/rgb 등)이며,
 * 측정 불가 시 null.
 *
 * @param frameEl 캔버스 프레임 요소
 * @param path 편집 대상 노드의 data-editor-path 원문
 * @return computed 글자색 문자열 또는 null
 */
function measureNodeColor(frameEl: HTMLElement, path: string | null): string | null {
  if (!path) return null;
  const el = frameEl.querySelector<HTMLElement>(`[data-editor-path="${cssEscape(path)}"]`);
  if (!el || typeof window.getComputedStyle !== 'function') return null;
  // 텍스트를 직접 가진 가장 깊은 자손을 찾는다(없으면 노드 자체).
  let target: HTMLElement = el;
  const walker = el.ownerDocument.createTreeWalker(el, NodeFilter.SHOW_TEXT);
  let textNode = walker.nextNode();
  while (textNode) {
    if ((textNode.textContent ?? '').trim().length > 0) {
      const parent = textNode.parentElement;
      if (parent && el.contains(parent)) {
        target = parent;
        break;
      }
    }
    textNode = walker.nextNode();
  }
  try {
    return window.getComputedStyle(target).color || null;
  } catch {
    return null;
  }
}

function measurePathBox(frameEl: HTMLElement, path: string | null): OverlayBox | null {
  if (!path) return null;
  const el = frameEl.querySelector(`[data-editor-path="${cssEscape(path)}"]`);
  if (el) {
    const box = measureOverlay(el, frameEl);
    // frame 밖으로 클리핑된 노드(닫힌 모바일 드로어 등)에는 선택/hover 박스를 그리지
    // 않는다 — 가려진 노드 자리에 박스가 편집기 회색 배경에 노출되는 것을 차단.
    return box && boxIntersectsFrame(box, frameEl) ? box : null;
  }
  // 폴백 — iteration 원본 path 의 직접 펼침 인스턴스 union.
  const instances = frameEl.querySelectorAll<HTMLElement>(
    `[data-editor-path^="${cssEscape(path)}.iteration."]`
  );
  let left = Infinity, top = Infinity, right = -Infinity, bottom = -Infinity;
  let found = false;
  instances.forEach((inst) => {
    const p = inst.dataset.editorPath ?? '';
    // 직접 인스턴스(`path.iteration.N`)만 — 인스턴스 자손 제외.
    if (!new RegExp(`\\.iteration\\.\\d+$`).test(p)) return;
    const b = measureOverlay(inst, frameEl);
    if (!b) return;
    // frame 밖으로 클리핑된 인스턴스는 union 에서 제외(가려진 항목까지 박스가 늘어나지 않게).
    if (!boxIntersectsFrame(b, frameEl)) return;
    found = true;
    left = Math.min(left, b.left);
    top = Math.min(top, b.top);
    right = Math.max(right, b.left + b.width);
    bottom = Math.max(bottom, b.top + b.height);
  });
  if (!found) return null;
  return { left, top, width: right - left, height: bottom - top, scale: 1 };
}
