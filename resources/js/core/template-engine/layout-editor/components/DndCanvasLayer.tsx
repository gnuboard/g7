/**
 * DndCanvasLayer.tsx — 캔버스 드래그 앤 드롭 레이어
 *
 * EditorCanvasOverlay 안에 합성되어 다음을 마운트한다:
 *  - `DndContext` (PointerSensor) + useCanvasDnd 콜백 연결
 *  - draggable 노드마다 그 시각 위치 위에 투명 드래그 핸들 (`DraggableHandle`)
 *  - 드롭 인디케이터 선분 (`activeDropZone.indicator`)
 *  - `DragOverlay` 고스트(드래그 중 부유하는 노드 이름 배지)
 *
 * 드래그 핸들은 캔버스 DOM(DynamicRenderer 가 렌더한 요소) 위에 절대배치된다 —
 * 오버레이 레이어가 캔버스 요소를 직접 소유하지 않으므로, overlayGeometry 로 각
 * draggable 요소의 frame 기준 박스를 측정해 그 위에 핸들을 얹는다. 핸들만
 * pointer-events:auto 이고 나머지 레이어는 none 이라 hover/선택 동작과 공존한다.
 *
 * 좌표 정합: 본 레이어는 frame 과 동일 좌표 원점/스케일을 공유하는 g7le-overlay-layer
 * (PreviewCanvas) 안에 마운트된다. 인디케이터/핸들은 모두 frame 기준 상대좌표.
 *
 * @since engine-v1.50.0
 */

import React, { useCallback, useEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import {
  DndContext,
  DragOverlay,
  PointerSensor,
  pointerWithin,
  useSensor,
  useSensors,
  useDraggable,
  useDroppable,
} from '@dnd-kit/core';
import {
  measureOverlay,
  subscribeOverlayTracking,
  boxIntersectsFrame,
  type OverlayBox,
} from '../utils/overlayGeometry';
import { isDraggableNode } from '../dnd/nestingRules';
import { findNodeByPath, isInsideIterationInstance, serializeEditorPath, isResponsiveSegment, type EditorNode, type ComponentPath } from '../utils/layoutTreeUtils';
import { classifyLockKind, parseEditorPath } from '../hooks/useElementSelection';
import { useCanvasDnd, type UseCanvasDndParams } from '../hooks/useCanvasDnd';
import { buildDropSlots, type DropSlot } from '../dnd/dropSlots';
import { dndHandleZIndex, DND_DROP_SLOT, DND_DRAG_OVERLAY } from '../utils/overlayZIndex';
import { useTranslation } from '../../TranslationContext';

export interface DndCanvasLayerProps {
  frameEl: HTMLElement | null;
  nesting: UseCanvasDndParams['nesting'];
  editMode: UseCanvasDndParams['editMode'];
  currentExtensionId?: number;
  /**
   * 반복 항목 편집 모드(iteration_item)의 편집 대상 iteration 원본 노드 path.
   * 이 모드에서는 그 iteration 의 **인스턴스 내부 노드에 개별 드래그/선택 핸들**을 만들어
   * 항목 템플릿 내부 요소를 개별 편집할 수 있게 한다(route 모드의 "묶음 단위 1핸들" 정반대).
   */
  editableRootPath?: number[] | null;
  components: EditorNode[];
  patchLayout: UseCanvasDndParams['patchLayout'];
  pushHistory: UseCanvasDndParams['pushHistory'];
  /**
   * 핸들 클릭(드래그 미발생) 시 그 노드를 선택. 드래그 핸들이 frame 위 별도
   * 레이어라 EditorCanvasOverlay 의 frame 클릭 위임이 닿지 않으므로, 클릭
   * 선택을 본 콜백으로 위임받는다.
   */
  onSelectPath: (path: string) => void;
  /**
   * 이동 commit 후 이동한 노드의 새 path 로 재선택.
   * useCanvasDnd.onMovePath 로 위임 — 포커스가 이동 노드를 따라가게 한다.
   */
  onMovePath?: (path: string) => void;
  /**
   * 핸들 더블클릭 → 인라인 텍스트 편집 진입.
   *
   * 드래그 핸들이 노드 박스를 `pointerEvents:auto` 로 덮으므로, 실제 마우스 더블클릭은
   * 핸들에 먼저 맞아 frame 의 dblclick 위임에 닿지 못한다(인라인 편집 미진입). 핸들이
   * 더블클릭을 같은 진입점으로 forward 해 평문/키 노드를 인라인 편집할 수 있게 한다.
   */
  onRequestInlineEdit?: (path: string) => void;
  /**
   * 현재 선택된 노드 path.
   *
   * 부모/자식이 모두 draggable 이면 핸들 오버레이가 겹쳐, 포인터가 자식 영역에
   * 있을 때 자식 핸들이 드래그를 잡아 "선택과 무관한 자식이 끌려가는" 결함이 난다.
   * 선택 노드가 있으면 그 **자손** 핸들의 드래그를 비활성화해(클릭 선택은 유지),
   * 선택 노드 기준으로만 드래그가 시작되도록 한다. 단 자손 위 단순 클릭은 그대로
   * 그 자손을 재선택할 수 있다.
   */
  selectedPath?: string | null;
}

interface DraggableEntry {
  path: string;
  name: string;
  box: OverlayBox;
  /**
   * 이터레이션 가상 묶음 — 레이아웃에 반복을 묶는 컴포넌트가 없어
   * 펼침 인스턴스만 DOM 에 존재하므로, 편집기가 인스턴스들을 감싸는 **가상 묶음**
   * 핸들을 합성한다. path 는 iteration 원본 노드(속성 보유 노드), box 는 펼침
   * 인스턴스들의 union rect. 선택/드래그는 원본 노드 대상이라 묶음째 이동되고,
   * 가상 핸들은 오버레이 레이어에만 있어 layoutDocument 에 직렬화되지 않는다(저장 제외).
   */
  isIterationGroup?: boolean;
}

/** path 에서 `.iteration.\d+` 이후를 잘라낸 **iteration 원본 노드 path**. 없으면 null. */
function iterationOwnerPath(path: string): string | null {
  const m = /^(.*?)\.iteration\.\d+(?:\.|$)/.exec(path);
  return m ? m[1]! : null;
}

/**
 * DOM path(iteration/sortable 인스턴스 내부는 `.iteration.N`/`.sortable.N` 포함)를 원본 트리
 * 좌표 dot path 로 정규화. parseEditorPath 가 가상 인덱스를 제거한 number[]
 * 를 만들고, 그것을 `0.children.2.children.1` 형식으로 재직렬화한다. 가상 인덱스가 없는 path 는
 * 변화 없음(일반 레이아웃 무영향). 드롭 슬롯의 containerPath·index 를 commit(parseEditorPath)·
 * includeContainer(relevant Set 은 원본 좌표)와 일치시킨다.
 */
function normalizeDomPathToSourcePath(domPath: string): string {
  const idx = parseEditorPath(domPath);
  return serializeEditorPath(idx);
}

/**
 * DOM path 가 반복 항목 편집 대상(editableRootPath) iteration 의 **인스턴스 내부**인지
 * iteration_item 모드에서 그 한 iteration 의 인스턴스 자식만 개별 핸들/선택을
 * 허용하기 위한 판정. editableRootSourcePath = 편집 대상 iteration 원본 노드의 dot path.
 */
function isInsideEditableIteration(path: string, editableRootSourcePath: string | null): boolean {
  if (!editableRootSourcePath) return false;
  // 인스턴스(또는 그 자손): `{src}.iteration.N` / `{src}.iteration.N.children...`
  return path.startsWith(`${editableRootSourcePath}.iteration.`);
}

/**
 * path 가 편집 대상 루트(editableRootSourcePath) 자신 또는 그 자손인지 (modal/iteration_item 공통).
 *
 * modal 편집은 호스트 전체를 인플레이스로 렌더하되 모달 노드 서브트리만 편집 가능하다. 그러나
 * modal 모드의 호스트 노드는 classifyLockKind 가 none 이라(확장만 잠금) 핸들 생성이 호스트(딤)
 * 영역까지 만들어졌다. 본 함수로 편집 루트 밖
 * 노드의 핸들 생성을 차단한다.
 *
 *  - iteration_item: iteration 인스턴스 내부(`{src}.iteration.N…`).
 *  - modal 등: 편집 대상 노드 자신 또는 그 자손.
 */
function isInsideEditableRoot(
  path: string,
  editableRootSourcePath: string | null,
  editMode: string,
): boolean {
  if (!editableRootSourcePath) return false;
  if (editMode === 'iteration_item') {
    return isInsideEditableIteration(path, editableRootSourcePath);
  }
  return path === editableRootSourcePath || path.startsWith(`${editableRootSourcePath}.`);
}

/** 여러 OverlayBox 의 union(외접 사각형). 빈 배열이면 null. */
function unionBoxes(boxes: OverlayBox[]): OverlayBox | null {
  if (boxes.length === 0) return null;
  let left = Infinity, top = Infinity, right = -Infinity, bottom = -Infinity;
  for (const b of boxes) {
    left = Math.min(left, b.left);
    top = Math.min(top, b.top);
    right = Math.max(right, b.left + b.width);
    bottom = Math.max(bottom, b.top + b.height);
  }
  return { left, top, width: right - left, height: bottom - top, scale: boxes[0]!.scale };
}

export function DndCanvasLayer(props: DndCanvasLayerProps): React.ReactElement | null {
  const {
    frameEl,
    nesting,
    editMode,
    currentExtensionId,
    editableRootPath,
    components,
    patchLayout,
    pushHistory,
    onSelectPath,
    onMovePath,
    onRequestInlineEdit,
    selectedPath,
  } = props;

  // 편집 대상 루트 노드 dot path — iteration_item(반복 원본 노드) / modal(모달 노드).
  //  - iteration_item: 그 iteration 인스턴스 내부 노드만 개별 핸들/선택을 허용한다.
  //  - modal: 모달 노드 서브트리 밖(딤 호스트) 노드에는 핸들을 만들지 않는다.
  const editableRootSourcePath =
    (editMode === 'iteration_item' || editMode === 'modal') &&
    editableRootPath &&
    editableRootPath.length > 0
      ? serializeEditorPath(editableRootPath)
      : null;

  const { t } = useTranslation();

  // 디바이스 분기 경계 이동 거부 안내. base↔분기 또는 분기간
  // 드롭이 거부될 때(useCanvasDnd 가 showBranchBoundaryHint 호출) 캔버스 상단에 잠깐 뜨는
  // 토스트. 거부는 드래그 commit 시점 1회 이벤트라 transient(자동 사라짐)로 충분하다.
  const [boundaryHint, setBoundaryHint] = useState(false);
  const boundaryHintTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);
  const showBranchBoundaryHint = useCallback((): void => {
    setBoundaryHint(true);
    if (boundaryHintTimerRef.current) clearTimeout(boundaryHintTimerRef.current);
    boundaryHintTimerRef.current = setTimeout(() => setBoundaryHint(false), 2600);
  }, []);
  useEffect(
    () => () => {
      if (boundaryHintTimerRef.current) clearTimeout(boundaryHintTimerRef.current);
    },
    [],
  );

  const dnd = useCanvasDnd({
    frameEl,
    nesting,
    editMode,
    currentExtensionId,
    editableRootPath,
    components,
    patchLayout,
    pushHistory,
    onMovePath,
    selectedPath,
    showBranchBoundaryHint,
  });

  // PointerSensor — 8px 이동 후 드래그 시작(클릭/선택과 구분). dnd-kit 기본 패턴.
  const sensors = useSensors(
    useSensor(PointerSensor, { activationConstraint: { distance: 8 } })
  );

  // draggable 노드 박스 수집 — frame 안의 [data-editor-path] 중 draggable + 비잠금만.
  const [entries, setEntries] = useState<DraggableEntry[]>([]);

  const recomputeEntries = useCallback((): void => {
    if (!frameEl) {
      setEntries([]);
      return;
    }
    // 드래그 중에는 핸들 재계산 금지 — 라이브 프리뷰로 캔버스 DOM 이 프리뷰 트리로
    // 바뀌어 있어 components prop(원본)과 path 가 어긋난다. 활성 드래그의 핸들/세션은
    // dnd-kit 이 유지하므로 재계산 불필요. dragend 후 다음 effect 가 정상 재계산.
    if (dnd.activeDragPath !== null) return;
    const root: EditorNode = { children: components };
    const els = frameEl.querySelectorAll<HTMLElement>('[data-editor-path]');
    const next: DraggableEntry[] = [];
    // 이터레이션 가상 묶음 — 펼침 인스턴스 box 를 원본 path 별로 누적.
    const iterationGroups = new Map<string, OverlayBox[]>();
    els.forEach((el) => {
      const path = el.dataset.editorPath;
      if (typeof path !== 'string') return;
      // 편집 루트 confine — modal 등 비-iteration 편집 모드에서 편집 대상 노드 서브트리 밖
      // (딤 호스트) 노드에는 드래그 핸들을 만들지 않는다. iteration_item 은
      // 아래 인스턴스 전용 로직(isInsideEditableIteration)이 별도로 처리하므로 제외한다.
      if (
        editMode !== 'iteration_item' &&
        editableRootSourcePath &&
        !isInsideEditableRoot(path, editableRootSourcePath, editMode)
      ) {
        return;
      }
      // 이터레이션 펼침 인스턴스 → 원본 노드 묶음으로 누적(개별 핸들은 만들지 않음).
      //
      // 단, 반복 항목 편집 모드(iteration_item)의 **편집 대상 iteration** 인스턴스는 예외다
      // 이 모드는 itemTemplate 1개를 편집하는 전용 세션이고 인스턴스가 1개
      // 뿐이라, 인스턴스 자식 path(`{src}.iteration.0.children.N`)가 parseEditorPath 로
      // `.iteration.0` 제거 후 원본 itemTemplate children[N] 에 1:1 매핑된다(route 모드의
      // "어느 행을 고쳤나 모호" 문제가 없다). 따라서 이 경우엔 묶음 누적/early-return 을 건너뛰고
      // 아래 개별 핸들 생성 흐름으로 떨어뜨려 항목 내부 요소를 개별 선택·편집·드래그하게 한다.
      // route 모드/다른 iteration 은 종전대로 묶음 1핸들 — 이 분기 외엔 무영향.
      const ownerPath = iterationOwnerPath(path);
      if (ownerPath !== null && !isInsideEditableIteration(path, editableRootSourcePath)) {
        // 같은 원본의 첫 인스턴스 1개 box 만 모아도 union 충분 — 단 모든 인스턴스 누적해
        // 정확한 외접 사각형 산출. 인스턴스 자손(`.iteration.0.children.*`)은 중복이라 제외.
        if (/\.iteration\.\d+$/.test(path)) {
          const b = measureOverlay(el, frameEl);
          // frame 밖으로 클리핑된 인스턴스(닫힌 모바일 드로어 내부 반복 등)는 union 에서
          // 제외 — 포함하면 묶음 외접 사각형이 frame 밖까지 늘어나 점선이 노출된다.
          if (b && boxIntersectsFrame(b, frameEl)) {
            const arr = iterationGroups.get(ownerPath) ?? [];
            arr.push(b);
            iterationGroups.set(ownerPath, arr);
          }
        }
        return; // 인스턴스/그 자손은 개별 핸들 미생성
      }
      // 편집 대상 iteration 인스턴스 노드(`{src}.iteration.0` 자체)는 행 컨테이너라 개별 핸들에서
      // 제외 — 그 내부 자식만 개별 편집 대상이다(인스턴스 루트는 itemTemplate 루트와 동일).
      if (isInsideEditableIteration(path, editableRootSourcePath) && /\.iteration\.\d+$/.test(path)) {
        return;
      }
      const indexes = parseEditorPath(path);
      const node = findNodeByPath(root, indexes);
      if (!node) return;
      // iteration 정의 노드 자신은 일반 핸들로 만들지 않는다 — 가상 묶음(아래)으로만
      // 다룬다. (실제 DynamicRenderer 는 원본 노드에 표식을 안 붙이지만, 방어적 가드.)
      if (node.iteration && (node.iteration as { source?: unknown }).source !== undefined) return;
      const name =
        typeof node.name === 'string'
          ? node.name
          : typeof node.type === 'string'
            ? node.type
            : null;
      if (!name || !isDraggableNode(name, nesting)) return;
      // 드래그 불가 = **실제 잠금**(base/partial/extension/extension_point)만.
      // `data_bound`(`{{}}` 바인딩/iteration/조상 iteration)는 명세상
      // 인라인 텍스트 편집만 불가하고 **선택·드래그·구조 편집은 허용**이다. 따라서 핸들
      // 제외 조건에서 data_bound 를 빼야 한다 — 종전에는 `classifyLockKind !== 'none'`
      // 으로 data_bound 까지 핸들을 막아, 데이터 바인딩 composite(상품 이미지 갤러리 등)가
      // 선택·이동 불가였고 클릭이 조상 핸들에 가로채였다.
      const ancestors = ancestorsOf(root, indexes);
      // 반복(iteration) 인스턴스 **내부** 노드는 핸들 제외 — 펼침 인스턴스를 개별로
      // 선택/드래그하면 안 된다. 묶음 단위 편집은 아래 가상 묶음으로.
      // 예외: 반복 항목 편집 모드의 편집 대상 iteration 인스턴스 내부는 개별 핸들 허용.
      if (
        isInsideIterationInstance(ancestors) &&
        !isInsideEditableIteration(path, editableRootSourcePath)
      ) {
        return;
      }
      const lockKind = classifyLockKind(node, editMode, currentExtensionId, ancestors);
      // 편집 대상 iteration 인스턴스 내부는 그 모드의 편집 대상이므로 잠금/데이터바운드 무관 허용.
      if (
        lockKind !== 'none' &&
        lockKind !== 'data_bound' &&
        !isInsideEditableIteration(path, editableRootSourcePath)
      ) {
        return;
      }
      const box = measureOverlay(el, frameEl);
      if (!box) return;
      // frame 밖으로 클리핑된 노드(닫힌 모바일 드로어 등)에는 핸들을 만들지 않는다 —
      // overflow:hidden 은 시각만 가릴 뿐 getBoundingClientRect 는 좌표를 그대로 주므로,
      // 가려진 노드 자리에 핸들/점선이 편집기 회색 배경에 노출되던 회귀 차단.
      if (!boxIntersectsFrame(box, frameEl)) return;
      next.push({ path, name, box });
    });

    // 이터레이션 가상 묶음 핸들 합성 — 원본 노드가 비잠금이면 인스턴스 union 을 박스로.
    for (const [ownerPath, boxes] of iterationGroups) {
      const indexes = parseEditorPath(ownerPath);
      const ownerNode = findNodeByPath(root, indexes);
      if (!ownerNode) continue;
      const name =
        typeof ownerNode.name === 'string'
          ? ownerNode.name
          : typeof ownerNode.type === 'string'
            ? ownerNode.type
            : null;
      if (!name) continue;
      // 원본 노드 자체의 잠금만 검사(base/partial/extension). 원본은 iteration 정의
      // 노드라 data_bound 지만 대로 묶음 선택/이동은 허용.
      const ancestors = ancestorsOf(root, indexes);
      const lockKind = classifyLockKind(ownerNode, editMode, currentExtensionId, ancestors);
      if (lockKind !== 'none' && lockKind !== 'data_bound') continue;
      const box = unionBoxes(boxes);
      if (!box) continue;
      // 인스턴스를 frame 가시 영역으로 이미 걸렀으나, union 결과도 frame 과 겹치는지
      // 최종 확인(모든 인스턴스가 가려진 경우 boxes 가 비어 unionBoxes 가 null → continue).
      if (!boxIntersectsFrame(box, frameEl)) continue;
      next.push({ path: ownerPath, name, box, isIterationGroup: true });
    }

    setEntries(next);
  }, [frameEl, components, nesting, editMode, currentExtensionId, editableRootSourcePath, dnd.activeDragPath]);

  useEffect(() => {
    recomputeEntries();
  }, [recomputeEntries]);

  useEffect(() => {
    if (!frameEl) return;
    return subscribeOverlayTracking(recomputeEntries, frameEl);
  }, [frameEl, recomputeEntries]);

  // 드래그 중에는 핸들 박스 갱신을 멈춘다(트리는 dragend 에서만 변형되므로 안정적).
  const isDragging = dnd.activeDragPath !== null;

  const activeName = dnd.activeDragName;
  const activePath = dnd.activeDragPath;

  // 드래그 중인 요소의 실제 DOM 복제(반투명 고스트) + 크기 — 드래그 시작 시 1회 캡처.
  // 고스트는 실제 컴포넌트 모양 그대로(#3), 점선 박스는 그 크기만큼(#1).
  //
  // 보안: innerHTML 주입(dangerouslySetInnerHTML) 대신 `cloneNode(true)` 로 노드를
  // 복제해 ghostHostRef 에 append — 문자열 파싱 경로가 없어 XSS 표면이 없다.
  const [ghost, setGhost] = useState<{ node: HTMLElement; width: number; height: number } | null>(
    null
  );
  const lastCapturedPathRef = useRef<string | null>(null);
  useEffect(() => {
    if (!activePath || !frameEl) {
      if (!activePath) {
        lastCapturedPathRef.current = null;
        setGhost(null);
      }
      return;
    }
    if (lastCapturedPathRef.current === activePath) return;
    lastCapturedPathRef.current = activePath;
    const el = frameEl.querySelector<HTMLElement>(
      `[data-editor-path="${cssEscapePath(activePath)}"]`
    );
    if (el) {
      const r = el.getBoundingClientRect();
      setGhost({ node: el.cloneNode(true) as HTMLElement, width: r.width, height: r.height });
      return;
    }
    // 가상 묶음 — activePath 가 iteration 원본 노드면 단일 DOM 요소가
    // 없다. 펼침 인스턴스(`activePath.iteration.N`)들을 union 위치 그대로 한 래퍼에
    // 복제해 묶음 전체 모양의 고스트를 만든다(텍스트 배지 폴백 대신 실제 묶음 미리보기).
    const instances = Array.from(
      frameEl.querySelectorAll<HTMLElement>(
        `[data-editor-path^="${cssEscapePath(activePath)}.iteration."]`
      )
    ).filter((inst) => /\.iteration\.\d+$/.test(inst.dataset.editorPath ?? ''));
    if (instances.length === 0) {
      setGhost(null);
      return;
    }
    let left = Infinity, top = Infinity, right = -Infinity, bottom = -Infinity;
    for (const inst of instances) {
      const ir = inst.getBoundingClientRect();
      left = Math.min(left, ir.left);
      top = Math.min(top, ir.top);
      right = Math.max(right, ir.right);
      bottom = Math.max(bottom, ir.bottom);
    }
    const width = right - left;
    const height = bottom - top;
    const wrapper = document.createElement('div');
    wrapper.style.position = 'relative';
    wrapper.style.width = `${width}px`;
    wrapper.style.height = `${height}px`;
    for (const inst of instances) {
      const ir = inst.getBoundingClientRect();
      const clone = inst.cloneNode(true) as HTMLElement;
      clone.style.position = 'absolute';
      clone.style.left = `${ir.left - left}px`;
      clone.style.top = `${ir.top - top}px`;
      clone.style.width = `${ir.width}px`;
      clone.style.height = `${ir.height}px`;
      clone.style.margin = '0';
      wrapper.appendChild(clone);
    }
    setGhost({ node: wrapper, width, height });
  }, [activePath, frameEl]);

  // 명시적 드롭 슬롯 — 드래그 시작 시 원본 DOM 에서 1회 열거.
  // 각 슬롯은 useDroppable 타깃으로 렌더되어 dnd-kit pointerWithin 이 hover 판정.
  // display:contents/grid/반응형 무관 정확 — 기하 추론(resolveDropZone) 폐기.
  const [slots, setSlots] = useState<DropSlot[]>([]);
  const lastSlotPathRef = useRef<string | null>(null);
  // buildSlotPredicates 를 ref 로 — 매 렌더마다 새 `dnd` 객체가 effect 를 재발화시켜
  // setSlots 무한 루프가 나는 것을 방지(검수 9차 회귀: dnd dep → 매 렌더 setSlots([])).
  const buildSlotPredicatesRef = useRef(dnd.buildSlotPredicates);
  buildSlotPredicatesRef.current = dnd.buildSlotPredicates;
  useEffect(() => {
    if (!activePath || !activeName || !frameEl) {
      lastSlotPathRef.current = null;
      // 이미 빈 배열이면 setState 생략(불필요 재렌더/루프 방지)
      setSlots((prev) => (prev.length === 0 ? prev : []));
      return;
    }
    if (lastSlotPathRef.current === activePath) return;
    lastSlotPathRef.current = activePath;
    const { acceptsContainer, allowsNestSlot, includeContainer } =
      buildSlotPredicatesRef.current(activeName);
    setSlots(
      buildDropSlots({
        frameEl,
        draggedPath: activePath,
        acceptsContainer,
        allowsNestSlot,
        includeContainer,
        // DOM containerPath(iteration/sortable 인스턴스 내부는 `.iteration.N` 포함)를 원본 트리
        // 좌표로 정규화 — includeContainer(relevant Set)·commit(parseEditorPath)과 일관.
        normalizeContainerPath: (p) => normalizeDomPathToSourcePath(p),
      })
    );
  }, [activePath, activeName, frameEl]);

  if (!frameEl) return null;

  const activeSlotId = dnd.activeDropZone
    ? `slot:${dnd.activeDropZone.containerPath}:${dnd.activeDropZone.index}`
    : null;

  return (
    <DndContext
      sensors={sensors}
      collisionDetection={pointerWithin}
      onDragStart={dnd.onDragStart}
      onDragMove={dnd.onDragMove}
      onDragEnd={dnd.onDragEnd}
      onDragCancel={dnd.onDragCancel}
    >
      {/* 드래그 핸들 — 각 draggable 요소 위 투명 오버레이. 드래그 중에는 활성 노드만 유지.
          모든 핸들은 자기 path 로 useDraggable 등록(드래그 가능) + 클릭 시 자기 path 재선택.
          "선택 노드 기준 드래그"(결함 2)는 useCanvasDnd.onDragStart 가 드래그 대상이 현재
          선택 노드의 자손이면 드래그 path 를 선택 노드로 치환해 처리한다 — 핸들 레벨에서
 드래그를 비활성화하면 자손이 덮은 영역에서 부모 드래그가 막히므로. */}
      {entries.map((entry) => (
        <DraggableHandle
          key={entry.path}
          path={entry.path}
          name={entry.name}
          box={entry.box}
          dimmed={isDragging && dnd.activeDragPath !== entry.path}
          isIterationGroup={entry.isIterationGroup}
          onSelect={onSelectPath}
          onRequestInlineEdit={onRequestInlineEdit}
        />
      ))}

      {/* 명시적 드롭 슬롯 — 드래그 중에만 렌더. hover 중인 슬롯(activeSlotId)은 강조. */}
      {isDragging &&
        slots.map((slot) => (
          <SlotDroppable key={slot.id} slot={slot} active={slot.id === activeSlotId} />
        ))}

      {/* DragOverlay 고스트 — document.body 포털로 transform:scale() 조상을 탈출(#2).
          dnd-kit DragOverlay 는 position:fixed 라 scale 조상 안에서 좌표가 왜곡되므로,
          포털로 body 직속에 렌더해 커서와 정합. createPortal 은 React context(DndContext)
          를 보존하므로 DragOverlay 가 드래그 상태를 정상 수신한다. */}
      {createPortal(
        <DragOverlay dropAnimation={null} zIndex={DND_DRAG_OVERLAY}>
          {activeName ? (
            ghost ? (
              <GhostClone name={activeName} node={ghost.node} width={ghost.width} height={ghost.height} />
            ) : (
              <div
                className="g7le-dnd-drag-ghost"
                data-testid="g7le-dnd-drag-ghost"
                style={{
                  padding: '4px 10px',
                  background: 'rgba(79,70,229,0.95)',
                  color: '#fff',
                  fontSize: 12,
                  fontWeight: 600,
                  borderRadius: 4,
                  pointerEvents: 'none',
                  whiteSpace: 'nowrap',
                }}
              >
                {`<${activeName}>`}
              </div>
            )
          ) : null}
        </DragOverlay>,
        document.body
      )}

      {/* 디바이스 분기 경계 이동 거부 안내 토스트. frame 상단 중앙에 잠깐
          떴다 사라진다. 디바이스별 구성은 독립이라 그 사이 이동은 의미상 병합 — 1차
          릴리스는 거부하고 이 안내로 사용자에게 사유를 알린다. */}
      {boundaryHint &&
        createPortal(
          <div
            data-testid="g7le-branch-boundary-hint"
            role="status"
            style={{
              position: 'absolute',
              left: '50%',
              top: 12,
              transform: 'translateX(-50%)',
              background: 'rgba(245,158,11,0.97)',
              color: '#fff',
              fontSize: 13,
              fontWeight: 600,
              padding: '8px 16px',
              borderRadius: 8,
              boxShadow: '0 4px 14px rgba(0,0,0,0.2)',
              pointerEvents: 'none',
              whiteSpace: 'nowrap',
              zIndex: DND_DRAG_OVERLAY,
            }}
          >
            ⚠️ {t('layout_editor.overlay.branch_boundary_denied')}
          </div>,
          frameEl,
        )}
    </DndContext>
  );
}

/**
 * 고스트 클론 — 캡처한 실제 DOM 노드를 ref 컨테이너에 cloneNode 로 append.
 * innerHTML 문자열 파싱 경로가 없어 XSS 표면이 없다. 반투명 + 캡처 크기 고정.
 */
function GhostClone(props: {
  name: string;
  node: HTMLElement;
  width: number;
  height: number;
}): React.ReactElement {
  const { node, width, height } = props;
  const hostRef = useRef<HTMLDivElement | null>(null);
  useEffect(() => {
    const host = hostRef.current;
    if (!host) return;
    host.replaceChildren();
    host.appendChild(node.cloneNode(true));
    return () => {
      host.replaceChildren();
    };
  }, [node]);
  return (
    <div
      ref={hostRef}
      className="g7le-dnd-drag-ghost"
      data-testid="g7le-dnd-drag-ghost"
      style={{
        width,
        height,
        opacity: 0.7,
        pointerEvents: 'none',
        boxShadow: '0 6px 18px rgba(0,0,0,0.25)',
        borderRadius: 4,
        overflow: 'hidden',
        cursor: 'grabbing',
      }}
    />
  );
}

/**
 * 단일 드롭 슬롯 — useDroppable 타깃 + 시각 인디케이터.
 *
 * dnd-kit 이 이 요소의 측정 rect 로 collision(pointerWithin)을 판정한다 — frame 위
 * 절대배치라 캔버스 DOM 변형/`display:contents`/scale 과 무관하게 hover 가 정확.
 *  - gap 슬롯: 형제 사이 얇은 띠. active 면 점선 라인 강조.
 *  - nest 슬롯: 빈 컨테이너 내부 영역. active 면 점선 테두리 강조.
 * 히트 영역(투명)은 살짝 넓게, 강조 시각은 슬롯 box 그대로.
 */
function SlotDroppable(props: { slot: DropSlot; active: boolean }): React.ReactElement {
  const { slot, active } = props;
  const { setNodeRef } = useDroppable({ id: slot.id });
  const isLine = slot.orientation !== 'area';
  return (
    <div
      ref={setNodeRef}
      data-testid={`g7le-dnd-slot-${slot.id}`}
      data-dnd-slot-id={slot.id}
      style={{
        position: 'absolute',
        left: slot.box.left,
        top: slot.box.top,
        width: slot.box.width,
        height: slot.box.height,
        pointerEvents: 'auto',
        // 드롭 슬롯은 드래그 중에만 존재하며 hover(pointerWithin) 판정을 위해 모든
        // 드래그 핸들 위에 있어야 한다. 핸들 z 가 깊이순(20+depth)으로
        // 커질 수 있어 DND_DROP_SLOT 으로 확실히 올린다(DND_DRAG_OVERLAY 아래라 고스트는 최상위 유지).
        zIndex: DND_DROP_SLOT,
        boxSizing: 'border-box',
        ...(isLine
          ? {
              // gap 슬롯 — active 면 중앙에 점선 라인 강조
              background: active ? 'rgba(79,70,229,0.15)' : 'transparent',
              ...(active
                ? slot.orientation === 'vertical'
                  ? { borderLeft: '2px solid #4f46e5' }
                  : { borderTop: '2px solid #4f46e5' }
                : {}),
            }
          : {
              // nest 슬롯 — active 면 내부 점선 테두리 강조
              border: active ? '2px dashed #4f46e5' : '1px dashed transparent',
              background: active ? 'rgba(79,70,229,0.08)' : 'transparent',
              borderRadius: 4,
            }),
      }}
    />
  );
}

/** data-editor-path selector escape (점/숫자만 포함하므로 단순) */
function cssEscapePath(value: string): string {
  if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') return CSS.escape(value);
  return value.replace(/[^a-zA-Z0-9_\-.]/g, (ch) => `\\${ch}`);
}

/**
 * 단일 드래그 핸들 — 캔버스 요소 위 투명 오버레이. useDraggable 의 listeners 를
 * 부착해 PointerSensor 가 드래그를 잡는다. id = data-editor-path 문자열.
 */
function DraggableHandle(props: {
  path: string;
  name: string;
  box: OverlayBox;
  dimmed: boolean;
  /** 이터레이션 가상 묶음 핸들 — 묶음임을 점선 테두리로 시각 구분. */
  isIterationGroup?: boolean;
  onSelect: (path: string) => void;
  /** 핸들 더블클릭 → 인라인 텍스트 편집 진입 forward */
  onRequestInlineEdit?: (path: string) => void;
}): React.ReactElement {
  const { path, box, dimmed, isIterationGroup, onSelect, onRequestInlineEdit } = props;
  const { attributes, listeners, setNodeRef, isDragging } = useDraggable({ id: path });

  // z-index 는 **트리 깊이순** — 깊은(구체적) 핸들이 항상 위로 와서 (a) 클릭이 가장
  // 안쪽 요소에 도달하고(자식 클릭 → 자식 선택) (b) 드래그 시작도 그 핸들이 잡는다.
  // "선택 노드 기준 드래그"(결함 2)는 핸들 비활성화가 아니라 useCanvasDnd.onDragStart
  // 의 드래그 path 치환으로 처리한다 — 핸들을 비활성화하면 자손이 덮은 영역에서 부모
  // 드래그가 막히기 때문. 모든 핸들은 자기 path 로 등록 + 드래그 가능.
  const depth = (path.match(/\.children\./g) || []).length;
  // 깊이순 z-index — 깊은 핸들이 위(클릭/드래그 시작 우선, 결함 2). 단 depth 는 클램프해
  // 어떤 깊이에서도 어포던스 밴드(OVERLAY_AFFORDANCE)를 침범하지 않는다 — 침범 시 +/ⓘ/
  // 리사이즈 핸들 클릭이 드래그 핸들에 가로채임.
  const zIndex = dndHandleZIndex(depth);

  return (
    <div
      ref={setNodeRef}
      data-testid={`g7le-dnd-handle-${path}`}
      data-dnd-handle-path={path}
      data-dnd-iteration-group={isIterationGroup ? 'true' : undefined}
      {...listeners}
      {...attributes}
      onClick={(e) => {
        // 드래그 미발생 클릭(8px 미만 이동) — 선택으로 위임. 드래그가 발생하면
        // dnd-kit 이 click 을 억제하므로 본 핸들러는 순수 클릭에만 발화.
        e.stopPropagation();
        // 드래그 불가 자식 가로채임 방지 — 컨테이너 핸들은 자기 노드 전체를
        // 덮는데, 드래그 불가 자식(Header/Footer composite 등)은 자체 핸들이 없어 그 위
        // 클릭이 조상 핸들 path(루트 통짜)로 새었다. 클릭 지점 아래의 **이 핸들 서브트리에
        // 속한 최심 frame 노드**로 선택을 위임한다. 반복 가상 묶음은 통짜 선택이 명세
        // 이므로 종전대로 자기 path 를 선택한다. 선택 가능 여부 판정은
        // onSelect 파이프라인(isSelectableInCurrentMode/정규화)이 종전대로 수행.
        if (
          !isIterationGroup &&
          typeof document !== 'undefined' &&
          typeof document.elementsFromPoint === 'function'
        ) {
          const stack = document.elementsFromPoint(e.clientX, e.clientY);
          for (const el of stack) {
            const hit = (el as HTMLElement).closest?.('[data-editor-path]') as HTMLElement | null;
            const hitPath = hit?.dataset?.editorPath;
            if (typeof hitPath !== 'string') continue;
            if (hitPath === path || hitPath.startsWith(`${path}.`)) {
              onSelect(hitPath);
              return;
            }
          }
        }
        onSelect(path);
      }}
      onDoubleClick={(e) => {
        // 핸들이 노드를 덮어 frame dblclick 위임에 닿지 못하므로, 여기서 인라인 편집
        // 진입을 forward 한다.
        // iteration 가상 묶음(원본 path)도 진입점이 데이터결정 판정으로 거른다.
        e.stopPropagation();
        e.preventDefault();
        onRequestInlineEdit?.(path);
      }}
      style={{
        position: 'absolute',
        left: box.left,
        top: box.top,
        width: box.width,
        height: box.height,
        cursor: 'grab',
        pointerEvents: 'auto',
        // 드래그 중인 본인은 살짝 흐리게, 그 외는 투명. hover/선택 오버레이를 가리지
        // 않도록 background 는 거의 투명하게 둔다. 가상 묶음은 점선 테두리로 "반복 묶음"
        // 임을 시각 구분.
        background: isDragging ? 'rgba(79,70,229,0.06)' : 'transparent',
        ...(isIterationGroup
          ? { border: '1px dashed rgba(79,70,229,0.5)', borderRadius: 4 }
          : {}),
        opacity: dimmed ? 0.6 : 1,
        // 깊이순 z — 깊은 핸들이 위(클릭/드래그 시작 우선). 가상 묶음은 인스턴스 내부
        // 핸들이 없으므로(인스턴스는 개별 핸들 미생성) 깊이만으로 충분.
        zIndex,
        touchAction: 'none',
      }}
    />
  );
}

/** path 조상 노드 배열(루트→부모, 자기 제외) — classifyLockKind ancestors 입력 */
function ancestorsOf(root: EditorNode, path: ComponentPath): EditorNode[] {
  const out: EditorNode[] = [];
  let current: EditorNode = root;
  let childArray: EditorNode[] = Array.isArray(root.children) ? (root.children as EditorNode[]) : [];
  for (let i = 0; i < path.length - 1; i++) {
    const seg = path[i]!;
    if (isResponsiveSegment(seg)) {
      const branch = current.responsive?.[seg.responsive];
      childArray = branch && Array.isArray(branch.children) ? (branch.children as EditorNode[]) : [];
      continue;
    }
    const next = childArray[seg] ?? null;
    if (!next) break;
    out.push(next);
    current = next;
    childArray = Array.isArray(next.children) ? (next.children as EditorNode[]) : [];
  }
  return out;
}
