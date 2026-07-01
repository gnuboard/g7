/**
 * useCanvasDnd.ts — 캔버스 드래그 앤 드롭 재배치 오케스트레이션
 *
 * dnd-kit 의 onDragStart / onDragMove / onDragEnd 를 받아:
 *  - onDragStart: 드래그 대상 path 보관 + devtools editor-dnd 1회 적재(source='drag')
 *  - onDragMove: 포인터 위치 → dropZoneResolver 로 드롭 존 계산 → 인디케이터 갱신
 *  (intermediate 좌표는 devtools 에 적재하지 않는다 — 위험 25)
 *  - onDragEnd: 최종 드롭 존 확정 → nesting/잠금 가드 → moveNode → history.push +
 *    patchLayout → devtools editor-dnd 1회 적재(결과 decision/result)
 *
 * nesting/잠금 가드:
 *  - 드래그 대상이 draggable 목록에 없으면 시작 자체를 거부(denied_no_draggable)
 *  - 드래그 대상이 잠금(base/partial/extension/data_bound/extension_point)이면 거부
 *  - 드롭 대상 컨테이너가 accepts 에 dragged 를 포함하지 않으면 거부(denied_no_accepts)
 *
 * 본 hook 은 DnD 도메인 로직만 — DOM 마운트(DndContext/DragOverlay)는 DndCanvasLayer 가 한다.
 *
 * @since engine-v1.50.0
 */

import { useCallback, useRef, useState } from 'react';
import type { DragStartEvent, DragMoveEvent, DragEndEvent } from '@dnd-kit/core';
import {
  canDrop,
  isDraggableNode,
  isContainerComponent,
} from '../dnd/nestingRules';
import { parseSlotId } from '../dnd/dropSlots';

/**
 * 활성 드롭 위치 — 명시적 드롭 슬롯에서 파싱한 컨테이너 path + 인덱스.
 * (구 geometry resolver 의 DropZone 을 대체 — flow/indicator 필드는 슬롯 droppable
 * 자체가 시각화하므로 불요.)
 */
export interface DropZone {
  containerPath: string;
  index: number;
}
import {
  findNodeByPath,
  isInsideIterationInstance,
  moveNode,
  rebasePathAfterRemoval,
  serializeEditorPath,
  isResponsiveSegment,
  segEqual,
  type EditorNode,
  type ComponentPath,
} from '../utils/layoutTreeUtils';
import { classifyLockKind, parseEditorPath } from './useElementSelection';
import { trackEditorDnd, type EditorDndDecision } from '../devtools/editorTrackers';
import type { NestingSpec } from '../spec/specTypes';

export interface UseCanvasDndParams {
  /** 편집 캔버스 frame DOM */
  frameEl: HTMLElement | null;
  /** editor-spec 의 nesting 블록 */
  nesting: NestingSpec | null | undefined;
  /** 현재 편집 모드 (잠금 판정) */
  editMode: 'route' | 'base' | 'modal' | 'extension' | 'iteration_item';
  /** 확장 편집 모드일 때 편집 중인 확장 PK */
  currentExtensionId?: number;
  /**
   * 반복 항목 편집 모드(iteration_item)의 편집 대상 iteration 원본 노드 path.
   * 이 모드에서 그 iteration 인스턴스 내부 노드의 드래그를 허용한다(일반 레이아웃처럼 순서
   * 변경 가능). 인스턴스 path 는 parseEditorPath 로 `.iteration.N` 제거 후 원본 itemTemplate
   * 좌표로 매핑되므로 이동 commit 이 정확히 항목 템플릿에 반영된다.
   */
  editableRootPath?: number[] | null;
  /** 현재 components 트리 (루트 children) */
  components: EditorNode[];
  /** 트리 변형 적용 — useLayoutDocument.patchLayout */
  patchLayout: (patcher: (current: EditorNode[]) => EditorNode[]) => void;
  /** 이동 후 변경 결과 스냅샷을 이력에 push */
  pushHistory: (snapshot: EditorNode[], label: string) => void;
  /**
   * 이동 commit 후 이동한 노드의 **새 path** 로 선택을 재복원.
   * 선택은 path 문자열 기준이라 moveNode 후 옛 path 는 다른 노드를 가리킨다.
   * 이동 노드의 목적지 path 로 재선택해 포커스를 유지한다.
   */
  onMovePath?: (destPath: string) => void;
  /**
   * 현재 선택된 노드 path.
   *
   * 드래그 시작 시 드래그 대상(`active.id`)이 이 선택 노드의 **자손**이면, 드래그
   * path 를 선택 노드로 **치환**한다. 즉 부모를 선택한 상태에서 자식(자손)이 덮은
   * 영역을 잡고 끌어도 선택한 부모가 이동한다. 핸들 자체는 모두 드래그 가능하게
   * 두므로(클릭 재선택 + 자손 영역에서도 드래그 시작 가능), 자손 핸들을 비활성화해
   * 부모 드래그가 막히던 문제가 없다.
   */
  selectedPath?: string | null;
  /**
   * 디바이스 분기 경계 이동 거부 시 사용자에게 안내(풍선/토스트)를 띄우는 콜백
   * (base↔responsive 분기 간 이동은 1차 거부). 미전달 시 안내 생략(거부만 수행).
   */
  showBranchBoundaryHint?: () => void;
}

export interface UseCanvasDndReturn {
  /** 현재 드래그 중인 노드 path (null = 비드래그) — DragOverlay 라벨/고스트용 */
  activeDragPath: string | null;
  /** 현재 드래그 중인 노드 이름 (Div/Button 등) — DragOverlay 라벨용 */
  activeDragName: string | null;
  /** 현재 드롭 존 (null = 드롭 불가/비드래그) — 인디케이터 렌더용 */
  activeDropZone: DropZone | null;
  onDragStart: (e: DragStartEvent) => void;
  onDragMove: (e: DragMoveEvent) => void;
  onDragEnd: (e: DragEndEvent) => void;
  onDragCancel: () => void;
  /**
   * 드롭 슬롯 생성 술어 — `DndCanvasLayer` 가 `buildDropSlots` 에 전달.
   * 드래그 중인 컴포넌트 이름 기준으로 (a) gap 슬롯 자격(accepts+비잠금)과
   * (b) nest 슬롯 자격(빈 컨테이너/레이아웃 박스, 같은 부모는 gap 으로 충분)을 판정.
   */
  buildSlotPredicates: (draggedName: string) => {
    acceptsContainer: (containerPath: string) => boolean;
    allowsNestSlot: (containerPath: string) => boolean;
    includeContainer: (containerPath: string) => boolean;
  };
}

/**
 * dnd-kit active id 또는 activatorEvent 에서 드래그 대상 path 추출.
 * draggable id 는 data-editor-path 문자열을 그대로 쓴다(DndCanvasLayer 가 부여).
 */
function dragPathFromEvent(activeId: unknown): string | null {
  if (typeof activeId === 'string' && activeId.length > 0) return activeId;
  return null;
}

/**
 * 선택 기준 드래그 치환 — 드래그 시작 path 가 현재 선택 노드의 **자손**
 * 이면 선택 노드 자신으로 치환한다. 부모를 선택한 상태에서 자식(자손)이 덮은 영역을
 * 잡고 끌어도 선택한 부모가 이동하도록. 드래그 path 가 선택 노드 자신이거나 무관한
 * 노드, 또는 선택이 없으면 원본 path 그대로.
 *
 * @param dragPath dnd-kit active.id 에서 얻은 원본 드래그 path
 * @param selectedPath 현재 선택된 노드 path (없으면 null/undefined)
 * @return 치환된 드래그 path
 */
function resolveSelectionDragPath(
  dragPath: string,
  selectedPath: string | null | undefined
): string {
  if (!selectedPath) return dragPath;
  // dragPath 가 selectedPath 의 자손(점 prefix)이면 selectedPath 로 치환.
  if (dragPath.startsWith(`${selectedPath}.`)) return selectedPath;
  return dragPath;
}

/**
 * dnd-kit `over` (hover 중 droppable slot id) → DropZone.
 * 명시적 드롭 슬롯 id 가 containerPath + index 를 직접 인코딩하므로 기하 추론 불요.
 * 슬롯 위가 아니면(over 없음) null = 드롭 불가.
 */
function zoneFromOver(overId: unknown): DropZone | null {
  if (typeof overId !== 'string') return null;
  const parsed = parseSlotId(overId);
  if (!parsed) return null;
  return { containerPath: parsed.containerPath, index: parsed.index };
}

/**
 * DOM path 가 반복 항목 편집 대상 iteration 의 인스턴스 내부인지.
 */
function isInsideEditableIteration(path: string, editableRootSourcePath: string | null): boolean {
  if (!editableRootSourcePath) return false;
  return path.startsWith(`${editableRootSourcePath}.iteration.`);
}

/**
 * path 가 편집 대상 루트(editableRootSourcePath) 자신 또는 그 자손인지 (modal/iteration_item 공통).
 *
 * 모달/반복항목 편집은 호스트 전체를 인플레이스로 렌더하되 **편집 대상 노드 서브트리만**
 * 편집 가능하고 나머지 호스트는 딤(잠금)이다. 그러나 modal 모드의 호스트 노드는 isNodeLocked
 * 가 잠그지 않아(확장만 잠금) DnD 가 딤 영역으로의 드롭/딤 영역으로의 이탈을 허용했다
 * 본 함수로 "편집 루트 밖" 을 판정해 드롭 컨테이너·드래그 노드를 모두
 * 편집 루트 서브트리로 가둔다.
 *
 *  - modal: 모달 노드 path(editIndexPath) 와 그 자손(`<root>` 또는 `<root>.children…`).
 *  - iteration_item: iteration 원본 노드 인스턴스 내부(`<root>.iteration.N…`) — 와 동일.
 *
 * @param path 정규화 dot path
 * @param editableRootSourcePath 편집 대상 루트 dot path (없으면 항상 false)
 * @param editMode 편집 모드 — iteration_item 은 인스턴스 경로, 그 외는 직접 서브트리
 */
function isInsideEditableRoot(
  path: string,
  editableRootSourcePath: string | null,
  editMode: string,
): boolean {
  if (!editableRootSourcePath) return false;
  if (editMode === 'iteration_item') {
    // 반복 항목 — 편집 대상 iteration 인스턴스 내부(가상 인덱스 `.iteration.N`).
    return isInsideEditableIteration(path, editableRootSourcePath);
  }
  // modal 등 — 편집 대상 노드 자신 또는 그 자손.
  return path === editableRootSourcePath || path.startsWith(`${editableRootSourcePath}.`);
}


export function useCanvasDnd(params: UseCanvasDndParams): UseCanvasDndReturn {
  const {
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
  } = params;

  // 편집 대상 루트 노드 dot path — iteration_item(반복 원본 노드) / modal(모달 노드).
  //  - iteration_item: iteration 인스턴스 내부 노드의 드래그를 허용한다(일반 레이아웃처럼).
  //  - modal: 모달 노드 서브트리만 편집 가능 — 딤(잠긴 호스트)으로의 드롭/이탈을
  //    차단한다(아래 makeCanDropInContainer / onDragStart / onDragMove 의 confine 가드).
  // route/base/extension 모드 등에서는 null → 무영향.
  const editableRootSourcePath =
    (editMode === 'iteration_item' || editMode === 'modal') &&
    editableRootPath &&
    editableRootPath.length > 0
      ? serializeEditorPath(editableRootPath)
      : null;

  const [activeDragPath, setActiveDragPath] = useState<string | null>(null);
  const [activeDragName, setActiveDragName] = useState<string | null>(null);
  const [activeDropZone, setActiveDropZone] = useState<DropZone | null>(null);

  // 최신 트리/스펙 ref — dnd-kit 콜백이 stale 클로저로 옛 트리를 보지 않도록.
  const liveRef = useRef({ components, nesting, editMode, currentExtensionId, selectedPath, editableRootSourcePath });
  liveRef.current = { components, nesting, editMode, currentExtensionId, selectedPath, editableRootSourcePath };

  // 드래그 중인 노드의 원본 fromPath — buildSlotPredicates 의 "같은 부모 형제 재배치"
  // 판정에 사용. 라이브 프리뷰는 폐기됨 — 드롭 위치 슬롯만 표시.
  const fromPathRef = useRef<ComponentPath | null>(null);

  /** 루트 가상 노드 — findNodeByPath 의 진입점 */
  const rootOf = useCallback(
    (comps: EditorNode[]): EditorNode => ({ children: comps }),
    []
  );

  /** path 의 노드 이름(Div/Button 등) 조회 */
  const nameAtPath = useCallback(
    (comps: EditorNode[], path: ComponentPath): string | null => {
      const node = findNodeByPath(rootOf(comps), path);
      if (!node) return null;
      return typeof node.name === 'string'
        ? node.name
        : typeof node.type === 'string'
          ? node.type
          : null;
    },
    [rootOf]
  );

  /**
   * 드롭 대상 컨테이너가 dragged 를 받을 수 있는지 — nesting.canDrop + 컨테이너 잠금 가드.
   * dropZoneResolver 의 canDropInContainer 콜백으로 주입.
   */
  const makeCanDropInContainer = useCallback(
    (draggedName: string) =>
      (containerPath: string): boolean => {
        const live = liveRef.current;
        // 루트('') 컨테이너 — 가상 루트는 항상 컨테이너이며, draggable 인 dragged 는
        // 루트 직속에 놓을 수 있다(nesting 에 'root' 컨테이너 규칙이 없으면 draggable 만 검사).
        if (containerPath === '') {
          return isDraggableNode(draggedName, live.nesting);
        }
        const path = parseEditorPath(containerPath);
        const containerNode = findNodeByPath(rootOf(live.components), path);
        if (!containerNode) return false;
        const containerName =
          typeof containerNode.name === 'string'
            ? containerNode.name
            : typeof containerNode.type === 'string'
              ? containerNode.type
              : '';
        if (!containerName) return false;

        // 편집 대상 루트 서브트리(iteration 원본 노드/모달 노드 + 자손) 컨테이너인가.
        // containerPath 는 정규화 좌표라 editableRootSourcePath(정규화 sp)와 직접 prefix 비교.
        const isInsideEditableRootContainer =
          !!live.editableRootSourcePath &&
          (containerPath === live.editableRootSourcePath ||
            containerPath.startsWith(`${live.editableRootSourcePath}.`));

        // 편집 루트 confine — 편집 대상 루트가 있으면(modal/iteration_item) 그 서브트리 **밖**
        // 컨테이너로의 드롭을 전부 거부한다. modal 모드의 호스트 노드는
        // isNodeLocked 가 잠그지 않아(확장만 잠금) 종전엔 딤(잠긴 호스트) 영역으로의 드롭이
        // 허용됐다. 이 가드로 딤 영역 드롭·모달 외부 이탈을 함께 차단한다.
        if (live.editableRootSourcePath && !isInsideEditableRootContainer) {
          return false;
        }

        // 컨테이너가 잠금/데이터 결정 영역이면 거부.
        // 단, 편집 대상 iteration/모달(원본 노드 및 그 자손)은 그 모드의 편집 대상이므로
        // data_bound 잠금을 무시하고 컨테이너로 허용한다.
        if (!isInsideEditableRootContainer) {
          const ancestors = ancestorsOf(rootOf(live.components), path);
          const lockKind = classifyLockKind(
            containerNode,
            live.editMode,
            live.currentExtensionId,
            ancestors
          );
          if (lockKind !== 'none') return false;
        }

        // nesting accepts 검사 — 컨테이너성도 함께 판정
        if (!isContainerComponent(containerName, live.nesting)) return false;
        return canDrop({
          draggedComponentName: draggedName,
          targetContainerName: containerName,
          nesting: live.nesting,
        });
      },
    [rootOf]
  );

  /**
   * 컨테이너에 드롭(채택)을 허용할지 판정.
   *
   * 두 경우를 구분한다:
   *  1. **같은 컨테이너 내 형제 재배치** (containerPath == 드래그 노드의 부모) — 항상 허용.
   *     드래그 노드가 이미 그 컨테이너의 자식이므로 "안에 넣기"가 아니라 순서 변경이다.
   *     (예: `display:contents` 래퍼 안의 stat 카드 4개를 서로 재배치 — 래퍼가 채워진
   *      일반 Div 라도 그 안에서의 재배치는 정당.)
   *  2. **다른 컨테이너로 nest** — 그 컨테이너가 dragged 를 accepts 하면 허용
   * 채워진 카드 안으로도 nest 가능해야
   *     카드 내부 아이콘을 다른 카드로 옮길 수 있다. accepts/잠금 판정은 호출부
   *     (`buildSlotPredicates` 의 acceptsContainer 합성)가 이미 수행하므로, 여기서는
   *     컨테이너성만 확인한다. 단, **반복(iteration) 인스턴스 내부**는 데이터 결정
   *     영역이라 nest 금지(makeCanDropInContainer 의 잠금 가드가 이미 차단).
   *
   * case 1 판정은 `fromPathRef.current` 의 부모와 containerPath 를 인덱스 배열로 비교.
   */
  const allowsNestingInContainer = useCallback(
    (containerPath: string): boolean => {
      if (containerPath === '') return true; // 루트 콘텐츠 영역
      const live = liveRef.current;

      // case 1 — 같은 컨테이너 내 형제 재배치는 항상 허용.
      // 인덱스 배열로 비교(문자열 포맷 차이/iteration·sortable 토큰 회피).
      const path = parseEditorPath(containerPath);
      const from = fromPathRef.current;
      if (from && from.length > 0) {
        const fromParent = from.slice(0, -1);
        if (pathsEqual(path, fromParent)) return true;
      }

      // case 2 — 다른 컨테이너 nest: 컨테이너성 노드면 허용(accepts/잠금은 상위 합성).
      const node = findNodeByPath(rootOf(live.components), path);
      if (!node) return false;
      const name =
        typeof node.name === 'string'
          ? node.name
          : typeof node.type === 'string'
            ? node.type
            : '';
      // 컨테이너성 = 레이아웃 컴포넌트이거나, children 배열을 가질 수 있는 노드.
      // Img 등 leaf 컴포넌트는 accepts=[] 라 상위 acceptsContainer 에서 이미 거부됨.
      return name.length > 0;
    },
    [rootOf]
  );

  /**
   * 슬롯 생성 술어 — `buildDropSlots` 에 전달.
   *  - acceptsContainer: gap 슬롯 자격 = nesting accepts + 비잠금 (makeCanDropInContainer).
   *  - allowsNestSlot: nest 슬롯 자격 = 빈 컨테이너/레이아웃 박스 AND 드래그 노드의
   *    현재 부모가 아님(같은 부모는 gap 으로 충분 — nest 슬롯 중복 방지).
   */
  const buildSlotPredicates = useCallback(
    (draggedName: string) => {
      const accepts = makeCanDropInContainer(draggedName);
      const live = liveRef.current;
      const fromPath = fromPathRef.current;
      const fromParent = fromPath ? fromPath.slice(0, -1) : null;

      // 관련 컨테이너 집합 — 슬롯을 의미 있는 레벨로 제한해 카드 내부 콘텐츠 leaf
      // 까지 슬롯이 깔리는 것을 차단하되, 임의 컨테이너로의
      // 이동(결함바깥→다른/원래 컨테이너)이 가능해야 한다.
      //
      //  (A) 드래그 노드의 **조상 체인** = 부모(형제 재배치) → 조부모 → … → 루트.
      //      각 레벨이 "그 컨테이너 직속 형제로 삽입"하는 gap 슬롯을 생성하므로,
      //      카드를 한 단계씩 바깥 행으로 끌어낼 수 있다(이슈 3: 내부↔외곽 전환).
      //  (B) 트리 전역의 **dragged 를 accepts 하는 모든 컨테이너**(드래그 노드 자신/
      //      자손 제외). 이전에는 조상 체인의 직접 형제만 봐서 (1) 루트로 빠져나온 노드
      //      재드래그 시 fromParent=[] 면 형제 순회가 0회라 어떤 컨테이너도 포함 못 했고
      //      (2) 2단계 이상 깊은 컨테이너로 nest 불가했다(결함 1 근본 원인).
      //      후보는 `accepts`(makeCanDropInContainer = 컨테이너성 + nesting accepts +
      //      비잠금)로 거른다 — 콘텐츠 leaf(텍스트 등 accepts 미포함)와 잠금/데이터 영역이
      //  자동 배제되어 검수 10차 과생성(home 81개) 회귀가 재발하지 않는다.
      const relevant = new Set<string>();
      if (fromParent) {
        // (A) 조상 체인 — fromParent 부터 루트('')까지 모든 prefix.
        //     형제 재배치 gap 용. accepts 통과 여부는 acceptsContainer 가 별도 게이트.
        for (let len = fromParent.length; len >= 0; len--) {
          relevant.add(editorPathString(fromParent.slice(0, len)));
        }
      }
      const draggedStr = editorPathString(fromPath ?? []);
      const isDraggedSelfOrDescendant = (p: string): boolean =>
        !!fromPath && (p === draggedStr || p.startsWith(`${draggedStr}.`));
      // (B) 트리 전역 컨테이너 후보 수집 — DFS 로 모든 노드 path 순회.
      //
      // 디바이스 분기(responsive.{key}.children — 자식 완전 교체형)도 base children 과
      // 동일하게 순회한다. 분기 children 을 빠뜨리면 모바일 보기에서
      // 분기 안에 드롭 슬롯이 0개라 **같은 분기 내 이동조차 거부**된다(③ 허용과 모순).
      // 분기 children 의 후보 path 는 `…responsive.{key}.children.N` prefix 를 갖는다.
      // base↔분기 교차 드롭은 여전히 `sameBranchContext`(드롭 commit 가드)가 거부하므로
      // 후보로 수집되어도 경계는 안전하다.
      const collectContainers = (nodes: EditorNode[], prefix: ComponentPath): void => {
        for (let i = 0; i < nodes.length; i++) {
          const childPath: ComponentPath = [...prefix, i];
          const cp = editorPathString(childPath);
          if (
            !relevant.has(cp) &&
            !isDraggedSelfOrDescendant(cp) &&
            accepts(cp) &&
            allowsNestingInContainer(cp)
          ) {
            relevant.add(cp);
          }
          const node = nodes[i];
          if (node && Array.isArray(node.children)) {
            collectContainers(node.children as EditorNode[], childPath);
          }
          // 디바이스 분기 children — 각 분기 키마다 `{responsive:key}` 세그먼트를 끼워
          // 하강. 분기 children 은 base children 과 별개 배열이라 별도 순회가 필요하다.
          if (node && node.responsive && typeof node.responsive === 'object') {
            for (const branchKey of Object.keys(node.responsive)) {
              const branch = node.responsive[branchKey];
              if (branch && Array.isArray(branch.children)) {
                collectContainers(branch.children as EditorNode[], [
                  ...childPath,
                  { responsive: branchKey },
                ]);
              }
            }
          }
        }
      };
      collectContainers(live.components, []);

      return {
        acceptsContainer: accepts,
        includeContainer: (containerPath: string): boolean => {
          if (containerPath === '') return true;
          if (isDraggedSelfOrDescendant(containerPath)) return false;
          return relevant.has(containerPath);
        },
        allowsNestSlot: (containerPath: string): boolean => {
          if (!accepts(containerPath)) return false;
          // 같은 부모면 gap 으로 처리 — nest 슬롯 미생성
          if (fromParent) {
            const cp = parseEditorPath(containerPath);
            if (pathsEqual(cp, fromParent)) return false;
          }
          return allowsNestingInContainer(containerPath);
        },
      };
    },
    [makeCanDropInContainer, allowsNestingInContainer, rootOf]
  );

  const onDragStart = useCallback(
    (e: DragStartEvent): void => {
      const rawPath = dragPathFromEvent(e.active.id);
      const live = liveRef.current;
      if (!rawPath) return;
      // 선택 기준 드래그 — 드래그 대상이 선택 노드의 자손이면 선택 노드로 치환.
      const path = resolveSelectionDragPath(rawPath, live.selectedPath);
      const indexes = parseEditorPath(path);
      const node = findNodeByPath(rootOf(live.components), indexes);
      const name =
        node && typeof node.name === 'string'
          ? node.name
          : node && typeof node.type === 'string'
            ? node.type
            : null;

      setActiveDragPath(path);
      setActiveDragName(name);
      setActiveDropZone(null);

      // 드래그 노드 fromPath 보관 — 슬롯 술어의 "같은 부모" 판정용.
      fromPathRef.current = node ? indexes : null;

      // 드래그 가능 여부/잠금 판정 — 시작 시점 1회 devtools 적재(source='drag')
      let decision: EditorDndDecision = 'allowed';
      if (!name || !isDraggableNode(name, live.nesting)) {
        decision = 'denied_no_draggable';
      } else if (node) {
        const ancestors = ancestorsOf(rootOf(live.components), indexes);
        const lockKind = classifyLockKind(node, live.editMode, live.currentExtensionId, ancestors);
        // 편집 루트 confine(modal/iteration_item) — 편집 대상 루트 서브트리 **밖** 노드의 드래그
        // 시작을 거부한다. 선택 자체가 편집 루트로 제한되나(useElementSelection),
        // 드롭 가드와 일관되게 드래그 시작에서도 한 번 더 가둔다(딤 영역 요소 이동 방지).
        if (
          live.editableRootSourcePath &&
          !isInsideEditableRoot(path, live.editableRootSourcePath, live.editMode)
        ) {
          decision = 'denied_data_bound';
        }
        // 반복(iteration) 인스턴스 **내부** 노드는 드래그 거부 — 개별 펼침 인스턴스를
        // 직접 옮기면 안 된다. 묶음 편집은 가상 묶음/iteration_item 모드.
        // 단, 반복 항목 편집 모드의 편집 대상 iteration 인스턴스 내부는 드래그 허용 —
        // 인스턴스가 1개뿐이고 path 가 parseEditorPath 로 원본 itemTemplate 좌표에 매핑된다.
        else if (
          isInsideIterationInstance(ancestors) &&
          !isInsideEditableIteration(path, live.editableRootSourcePath)
        ) {
          decision = 'denied_data_bound';
        }
        // 자신 바인딩 data_bound(상품 이미지 갤러리 등)는 명세상 선택·드래그·구조
        // 편집 허용 — 위치 이동은 정당한 구조 편집이므로 막지 않는다.
        else if (lockKind === 'base' || lockKind === 'partial') decision = 'denied_base_locked';
        else if (lockKind === 'extension' || lockKind === 'extension_point')
          decision = 'denied_extension_locked';
      }

      trackEditorDnd({
        source: 'drag',
        draggedComponentName: name,
        targetContainerName: null,
        targetContainerPath: null,
        decision,
        result: decision === 'allowed' ? 'completed' : 'denied',
        timestamp: Date.now(),
      });
    },
    [rootOf]
  );

  const onDragMove = useCallback(
    (e: DragMoveEvent): void => {
      if (!frameEl) return;
      const rawPath = dragPathFromEvent(e.active.id);
      if (!rawPath) return;
      const draggedName = activeDragName;
      if (!draggedName) {
        setActiveDropZone(null);
        return;
      }
      // 드래그 대상이 draggable/비잠금이 아니면 드롭 존 미계산
      const live = liveRef.current;
      // 선택 기준 드래그 — 자손이 잡혀도 선택 노드로 치환(onDragStart 와 일관).
      const draggedPath = resolveSelectionDragPath(rawPath, live.selectedPath);
      const indexes = parseEditorPath(draggedPath);
      const node = findNodeByPath(rootOf(live.components), indexes);
      if (!node || !isDraggableNode(draggedName, live.nesting)) {
        setActiveDropZone(null);
        return;
      }
      const ancestors = ancestorsOf(rootOf(live.components), indexes);
      // 편집 루트 confine(modal/iteration_item) — 편집 대상 루트 밖 노드는 드롭존 미계산.
      if (
        live.editableRootSourcePath &&
        !isInsideEditableRoot(draggedPath, live.editableRootSourcePath, live.editMode)
      ) {
        setActiveDropZone(null);
        return;
      }
      // 반복 인스턴스 내부는 드롭존 미계산(개별 인스턴스 직접 이동 금지).
      // 자신 바인딩 data_bound 는 허용 — 실제 잠금만 드롭존 미계산.
      // 단, 반복 항목 편집 모드의 편집 대상 iteration 인스턴스 내부는 드롭존 계산 허용.
      const moveLockKind = classifyLockKind(node, live.editMode, live.currentExtensionId, ancestors);
      const insideEditableIter = isInsideEditableIteration(draggedPath, live.editableRootSourcePath);
      if (
        (isInsideIterationInstance(ancestors) && !insideEditableIter) ||
        (moveLockKind !== 'none' && moveLockKind !== 'data_bound' && !insideEditableIter)
      ) {
        setActiveDropZone(null);
        return;
      }

      // 드롭 타깃 = dnd-kit 이 hover 판정한 droppable 슬롯(명시적 드롭존).
      // slot id 가 containerPath + index 를 직접 인코딩 → activeDropZone 으로 슬롯 강조만.
      // 라이브 프리뷰(트리 실시간 변형)는 폐기 — 드롭 위치 표시만.
      setActiveDropZone(zoneFromOver(e.over?.id));
    },
    [frameEl, activeDragName, rootOf]
  );

  const finishDrag = useCallback((): void => {
    setActiveDragPath(null);
    setActiveDragName(null);
    setActiveDropZone(null);
    fromPathRef.current = null;
  }, []);

  const onDragEnd = useCallback(
    (e: DragEndEvent): void => {
      const rawPath = dragPathFromEvent(e.active.id);
      const draggedName = activeDragName;
      const live = liveRef.current;
      // 선택 기준 드래그 — 자손이 잡혀도 선택 노드로 치환(commit 대상도 선택 노드).
      const draggedPath = rawPath ? resolveSelectionDragPath(rawPath, live.selectedPath) : null;

      if (!frameEl || !draggedPath || !draggedName) {
        finishDrag();
        return;
      }

      const zone = zoneFromOver(e.over?.id);

      const fromPath = parseEditorPath(draggedPath);

      if (!zone) {
        // 드롭 불가/취소
        trackEditorDnd({
          source: 'drag',
          draggedComponentName: draggedName,
          targetContainerName: null,
          targetContainerPath: null,
          decision: 'denied_no_accepts',
          result: 'cancelled',
          timestamp: Date.now(),
        });
        finishDrag();
        return;
      }

      const toParentPath = zone.containerPath === '' ? [] : parseEditorPath(zone.containerPath);
      const targetContainerName =
        zone.containerPath === '' ? null : nameAtPath(live.components, toParentPath);

      // 디바이스 분기 경계 가드. base ↔ responsive 분기,
      // 또는 서로 다른 분기 간 이동은 1차 릴리스에서 거부한다. 두 벌 구성은 디바이스별
      // 독립 구성이라 둘 사이 이동은 의미상 병합 — 임의 처리 시 데이터 손상 위험.
      // 판정: fromPath 부모 체인과 toParentPath 의 responsive 세그먼트 시퀀스가 다르면 거부.
      if (!sameBranchContext(fromPath.slice(0, -1), toParentPath)) {
        trackEditorDnd({
          source: 'drag',
          draggedComponentName: draggedName,
          targetContainerName,
          targetContainerPath: zone.containerPath || null,
          decision: 'denied_responsive_branch_boundary',
          result: 'denied',
          timestamp: Date.now(),
        });
        showBranchBoundaryHint?.();
        finishDrag();
        return;
      }

      // 편집 루트 confine(modal/iteration_item) — commit 직전 최종 가드. 드롭 슬롯 생성은 이미
      // makeCanDropInContainer 로 편집 루트로 제한되나, stale 슬롯/루트('') 드롭이 편집 루트
      // 밖으로 commit 되지 않도록 한 번 더 차단한다.
      if (
        live.editableRootSourcePath &&
        !isInsideEditableRoot(zone.containerPath, live.editableRootSourcePath, live.editMode)
      ) {
        trackEditorDnd({
          source: 'drag',
          draggedComponentName: draggedName,
          targetContainerName,
          targetContainerPath: zone.containerPath || null,
          decision: 'denied_data_bound',
          result: 'denied',
          timestamp: Date.now(),
        });
        finishDrag();
        return;
      }

      // no-op 가드 — 같은 위치면 변형/이력 생략.
      //
      // zone.index 는 **원본 트리 인덱스**.
      // 같은 부모에서 드래그 노드를 자기 자리에 다시 넣는 것은 변화 없음: 그 위치는
      // (a) fromIndex 앞 = index fromIndex, (b) fromIndex 바로 뒤 = index fromIndex+1.
      // (제거 후 재삽입 시 둘 다 원래 자리로 환원되므로 no-op.)
      const fromParentPath = fromPath.slice(0, -1);
      const fromIndex = fromPath[fromPath.length - 1] ?? -1;
      const sameParentGuard = pathsEqual(fromParentPath, toParentPath);
      const samePosition =
        sameParentGuard && (zone.index === fromIndex || zone.index === fromIndex + 1);

      if (samePosition) {
        trackEditorDnd({
          source: 'drag',
          draggedComponentName: draggedName,
          targetContainerName,
          targetContainerPath: zone.containerPath || null,
          decision: 'allowed',
          insertionIndex: zone.index,
          result: 'cancelled',
          timestamp: Date.now(),
        });
        finishDrag();
        return;
      }

      // commit — **원본 트리** 에 moveNode 로 이동(노드 유실 방지).
      //
      // zone.index 는 이제 **원본 트리 인덱스**
      // 이므로 별도 환산 없이 moveNode 의 toIndex 로 그대로 넘긴다. moveNode 가 내부에서
      // removeNode 후 같은-부모 fromIndex<toIndex 시 -1 보정을 일관 수행한다.
      //
      // (이전: zone.index 가 base-space 라 +1 환산했으나, contents 투명화로 슬롯 인덱스가
      //  원본 트리 기준으로 바뀌어 환산 불요. baseRoot 직접 insert 의 노드 유실 회귀는
      //  moveNode + rebasePathAfterRemoval 로 이미 방지됨.)
      const sameParent = pathsEqual(fromParentPath, toParentPath);
      const toIndex = zone.index;

      let nextComponentsCaptured: EditorNode[] = [];
      patchLayout((current) => {
        const root: EditorNode = { children: current };
        const next = moveNode(root, fromPath, toParentPath, toIndex);
        const nextComponents = (next.children as EditorNode[]) ?? [];
        nextComponentsCaptured = nextComponents;
        return nextComponents;
      });
      pushHistory(nextComponentsCaptured, `move ${draggedName}`);

      // 이슈 2 — 이동한 노드의 **새 path** 로 선택 재복원. moveNode 내부 좌표 보정과
      // 동일하게 계산: 제거 후 좌표로 toParentPath rebase + 같은 부모 인덱스 -1 보정.
      const rebasedParent = rebasePathAfterRemoval(toParentPath, fromPath);
      const finalIndex = sameParent && fromIndex < toIndex ? toIndex - 1 : toIndex;
      const destPath = editorPathString([...rebasedParent, finalIndex]);
      onMovePath?.(destPath);

      trackEditorDnd({
        source: 'drag',
        draggedComponentName: draggedName,
        targetContainerName,
        targetContainerPath: zone.containerPath || null,
        decision: 'allowed',
        insertionIndex: zone.index,
        result: 'completed',
        timestamp: Date.now(),
      });

      finishDrag();
    },
    [frameEl, activeDragName, makeCanDropInContainer, allowsNestingInContainer, nameAtPath, patchLayout, pushHistory, finishDrag, onMovePath]
  );

  const onDragCancel = useCallback((): void => {
    finishDrag();
  }, [finishDrag]);

  return {
    activeDragPath,
    activeDragName,
    activeDropZone,
    onDragStart,
    onDragMove,
    onDragEnd,
    onDragCancel,
    buildSlotPredicates,
  };
}

/** path 의 조상 노드 배열(루트→부모, 자기 자신 제외) — classifyLockKind 의 ancestors 입력 */
function ancestorsOf(root: EditorNode, path: ComponentPath): EditorNode[] {
  const out: EditorNode[] = [];
  let current: EditorNode = root;
  let childArray: EditorNode[] = Array.isArray(root.children) ? (root.children as EditorNode[]) : [];
  // 마지막 세그먼트(자기 자신)는 제외. responsive 세그먼트는 노드를 내리지 않고
  // childArray 만 분기로 전환 — 조상 목록에 추가하지 않는다.
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

function pathsEqual(a: ComponentPath, b: ComponentPath): boolean {
  if (a.length !== b.length) return false;
  for (let i = 0; i < a.length; i++) {
    if (!segEqual(a[i]!, b[i]!)) return false;
  }
  return true;
}

/**
 * 두 path 가 **같은 디바이스 분기 컨텍스트**인지 — responsive 세그먼트 시퀀스가 동일한지.
 *
 * base(분기 세그먼트 없음)끼리, 또는 같은 `{responsive:key}` 분기 안끼리면 true.
 * base ↔ 분기, 또는 서로 다른 분기/다른 위치의 분기면 false → 이동 거부.
 *
 * 단순히 "분기 세그먼트만 추출해 순서 비교"한다(인덱스 number 세그먼트는 무시) —
 * 분기 경계를 넘는지 여부만 판정하면 충분하기 때문.
 */
export function sameBranchContext(a: ComponentPath, b: ComponentPath): boolean {
  const aBranches = a.filter(isResponsiveSegment) as Array<{ responsive: string }>;
  const bBranches = b.filter(isResponsiveSegment) as Array<{ responsive: string }>;
  if (aBranches.length !== bBranches.length) return false;
  for (let i = 0; i < aBranches.length; i++) {
    if (aBranches[i]!.responsive !== bBranches[i]!.responsive) return false;
  }
  return true;
}

/**
 * ComponentPath → editor-path 문자열. `[]` = '' (루트),
 * `[2,1]` = '2.children.1', `[2,{responsive:'portable'},1]` =
 * '2.responsive.portable.children.1'. dropSlots 의 containerPath 포맷과 일치.
 *
 * responsive 세그먼트(객체)를 `[object Object]` 로 깨뜨리지 않도록 공용
 * `serializeEditorPath` 에 위임한다(G-3).
 */
function editorPathString(path: ComponentPath): string {
  return serializeEditorPath(path);
}
