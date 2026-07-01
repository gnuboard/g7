/**
 * dropSlots.ts — 명시적 드롭 슬롯 열거
 *
 * 기하 추론(elementsFromPoint / union / rebase) 으로 드롭 위치를 역산하던 방식을
 * 폐기하고, 드래그 시작 시점에 **드롭 가능한 모든 위치를 명시적 슬롯으로 열거**한다.
 * 각 슬롯은 dnd-kit `useDroppable` 타깃으로 렌더되어, hover 판정은 dnd-kit
 * collision(pointerWithin)이 직접 수행한다 — `display:contents`/grid/반응형 무관.
 *
 * 슬롯 종류:
 *  - **gap 슬롯**: 한 컨테이너의 직접 자식 사이/앞/뒤 삽입 지점. `containerPath` +
 *    `index`(0..childCount) 를 식별. 자식 rect 경계에 얇은 띠(가로 flow=세로 띠,
 *    세로 flow=가로 띠)로 배치. 컨테이너가 `display:contents` 라도 자식 rect 기준이라
 *    정확.
 *  - **nest 슬롯**: 빈 컨테이너/레이아웃 박스의 내부 영역 전체. 그 안으로 nest.
 *
 * 슬롯 생성 정책(렌더 시점에 결정 — hover 시점 추론 없음):
 *  - 컨테이너가 accepts(dragged) + 비잠금이어야 슬롯 생성.
 *  - 같은 부모 형제 재배치는 항상 gap 슬롯 생성(드래그 노드 부모).
 *  - 다른 컨테이너 nest 는 빈 컨테이너/레이아웃 컴포넌트만 nest 슬롯 생성.
 *  - 드래그 노드 자신/자손이 차지한 위치는 제외(자기 안에 드롭 불가).
 *
 * @since engine-v1.50.0
 */

import type { OverlayBox } from '../utils/overlayGeometry';
import { detectContainerLayoutFlow, boxIntersectsFrame } from '../utils/overlayGeometry';

/** 슬롯 id 인코딩: `slot:<containerPath>:<index>` (containerPath '' = 루트) */
export type DropSlotKind = 'gap' | 'nest';

export interface DropSlot {
  /** dnd-kit droppable id */
  id: string;
  kind: DropSlotKind;
  /** 드롭 시 삽입할 컨테이너 path ('' = 루트) */
  containerPath: string;
  /** 컨테이너 children 내 삽입 인덱스 (gap). nest 슬롯은 0(컨테이너 끝/내부) */
  index: number;
  /** frame 기준 슬롯 박스 (드롭 타깃 + 인디케이터 영역) */
  box: OverlayBox;
  /** 인디케이터 방향 힌트 — gap 슬롯의 시각 라인 방향 */
  orientation: 'vertical' | 'horizontal' | 'area';
}

export interface BuildDropSlotsParams {
  frameEl: HTMLElement;
  /** 드래그 중인 노드 path (자기/자손 제외용) */
  draggedPath: string;
  /**
   * 컨테이너가 형제 재배치(gap) 슬롯을 생성할 자격이 있는지 — accepts + 비잠금.
   * (드래그 노드의 부모인지 무관 — 같은 부모/다른 컨테이너 모두 gap 가능하나
   *  다른 컨테이너 gap 은 allowNestSlot 과 별개로 "그 컨테이너 직속 형제로 삽입".)
   */
  acceptsContainer: (containerPath: string) => boolean;
  /**
   * 컨테이너가 nest(내부 영역) 슬롯을 생성할 자격 — 빈 컨테이너/레이아웃 박스.
   * 같은 부모는 gap 으로 충분하므로 nest 슬롯 불필요.
   */
  allowsNestSlot: (containerPath: string) => boolean;
  /**
   * 이 컨테이너가 드롭 슬롯을 생성할 **관련 레벨**인지.
   *
   * 미전달 시 모든 컨테이너에 슬롯 생성(레거시) — 그러면 카드 내부 flex/텍스트
   * 자손까지 슬롯이 깔려(home 기준 81개) `pointerWithin` 이 항상 최내곽 작은 슬롯을
   * 선택, 외곽 행으로 끌어낼 수 없고(이슈 3) 카드 중앙엔 슬롯이 없어 엉뚱한 인접
   * 슬롯에 걸린다(이슈 1).
   *
   * 관련 레벨 = 드래그 노드의 **조상 체인**(부모→루트: 형제 재배치 + 외곽 행) +
   * 그 체인 노드들의 **형제 중 빈/레이아웃 컨테이너**(nest 타깃). 카드 등 콘텐츠
   * 노드의 내부 자손은 제외 — 드롭 의미가 없다.
   */
  includeContainer?: (containerPath: string) => boolean;
  /**
   * DOM path 를 **원본 트리 좌표**로 정규화. iteration
   * 인스턴스 내부 컨테이너의 DOM path 는 `{src}.iteration.0.children.N` 처럼 가상 인덱스를
   * 포함한다. includeContainer(relevant Set 은 원본 좌표) 매칭과 slot id(commit 은
   * parseEditorPath 로 원본 좌표 사용)를 위해, 슬롯의 containerPath 는 이 함수로 정규화한
   * 값을 쓴다. 미전달 시 항등(일반 레이아웃 — `.iteration.` 없는 path 는 변화 없음).
   */
  normalizeContainerPath?: (containerPath: string) => string;
}

/** path 가 ancestorPath 의 자기 자신/자손인지 (점 prefix) */
function isSelfOrDescendant(path: string, ancestorPath: string): boolean {
  return path === ancestorPath || path.startsWith(`${ancestorPath}.`);
}

/** path 가 containerPath 의 직접 자식이면 그 인덱스, 아니면 null */
function directChildIndex(containerPath: string, path: string): number | null {
  if (containerPath === '') {
    return /^\d+$/.test(path) ? Number(path) : null;
  }
  const prefix = `${containerPath}.children.`;
  if (!path.startsWith(prefix)) return null;
  const rest = path.slice(prefix.length);
  return /^\d+$/.test(rest) ? Number(rest) : null;
}

/**
 * frame 안 모든 editor 요소 path→rect (placeholder 제외).
 *  - `path`: **정규화 좌표**(normalize 적용 — iteration/sortable 가상 인덱스 제거). 모든 path
 *  연산(컨테이너 수집/자식 인덱스/prefix)에 사용 → commit·includeContainer 와 일관.
 *  - `domPath`: 원본 DOM data-editor-path(가상 인덱스 포함). DOM 재조회(querySelector)에 사용.
 *
 * 같은 정규화 path 가 여러 DOM 요소에 대응할 수 있다(반복 인스턴스 N개 → 같은 원본 좌표).
 * 반복 항목 편집 모드는 인스턴스가 1개라 충돌이 없고, route 모드는 정규화가 항등이라 무영향.
 */
function indexElements(
  frameEl: HTMLElement,
  normalize: (p: string) => string,
): Array<{ path: string; domPath: string; rect: DOMRect }> {
  const out: Array<{ path: string; domPath: string; rect: DOMRect }> = [];
  frameEl.querySelectorAll<HTMLElement>('[data-editor-path]').forEach((el) => {
    if (el.hasAttribute('data-dnd-placeholder')) return;
    if (el.closest('[data-dnd-placeholder]')) return;
    const domPath = el.dataset.editorPath;
    if (typeof domPath === 'string') {
      out.push({ path: normalize(domPath), domPath, rect: el.getBoundingClientRect() });
    }
  });
  return out;
}

const GAP_THICKNESS = 16; // 슬롯 띠 두께(px) — 히트 영역

/**
 * 드롭 슬롯 전체 열거. 모든 컨테이너 path 집합을 모아 각각 gap/nest 슬롯을 만든다.
 */
export function buildDropSlots(params: BuildDropSlotsParams): DropSlot[] {
  const { frameEl, draggedPath, acceptsContainer, allowsNestSlot, includeContainer } = params;
  // DOM path → 원본 트리 좌표 정규화. iteration/sortable 인스턴스 내부
  // 컨테이너의 슬롯이 includeContainer(원본 좌표 relevant Set)·commit(parseEditorPath 원본
  // 좌표)과 일관되도록 한다. 미전달 시 항등.
  const normalize = params.normalizeContainerPath ?? ((p) => p);
  const frameRect = frameEl.getBoundingClientRect();
  // 모든 path 연산은 **정규화 좌표**로, DOM 재조회는 원본 domPath 로 분리한다.
  const elements = indexElements(frameEl, normalize);
  // 정규화 path → 원본 domPath 역매핑(DOM 재조회용). 반복 인스턴스가 1개라 1:1(편집 모드),
  // route 모드는 정규화 항등이라 동일.
  const domPathOf = new Map<string, string>();
  for (const e of elements) if (!domPathOf.has(e.path)) domPathOf.set(e.path, e.domPath);
  const normalizedDraggedPath = normalize(draggedPath);

  // 컨테이너 후보 path 집합(정규화 좌표) — 루트('') + 자식을 1개 이상 가진 모든 path.
  const containerPaths = new Set<string>(['']);
  for (const el of elements) {
    const parent = parentPathOf(el.path);
    if (parent !== null) containerPaths.add(parent);
  }
  // 빈 컨테이너도 nest 후보 — elements 중 자식 없는 것도 컨테이너일 수 있음(allowsNestSlot 가 판정)
  for (const el of elements) containerPaths.add(el.path);

  const slots: DropSlot[] = [];

  for (const containerPath of containerPaths) {
    const isRoot = containerPath === '';
    // DOM 재조회는 원본 domPath 로(정규화 좌표엔 `.iteration.` 이 없어 직접 querySelector 불가).
    const containerDomPath = isRoot ? null : (domPathOf.get(containerPath) ?? containerPath);
    const containerEl = isRoot
      ? frameEl
      : frameEl.querySelector<HTMLElement>(`[data-editor-path="${cssEscape(containerDomPath!)}"]`);
    if (!containerEl && !isRoot) continue;

    // 드래그 노드 자신/자손인 컨테이너는 제외(자기 안에 드롭 불가)
    if (!isRoot && isSelfOrDescendant(containerPath, normalizedDraggedPath)) continue;

    // 관련 레벨 제한 — 조상 체인 + 형제 nest 타깃만. 카드 내부 자손은 제외.
    // 루트는 항상 포함(외곽 최상위 행). includeContainer 미전달 시 레거시(모두 포함).
    // containerPath 는 이미 정규화 좌표 → includeContainer(relevant Set, 원본 좌표)와 직접 매칭.
    const normContainerPath = containerPath;
    if (!isRoot && includeContainer && !includeContainer(normContainerPath)) continue;

    // `display:contents` 직접 자식인지 판정 헬퍼(정규화 path → domPath 로 DOM 조회).
    const isContentsChild = (normPath: string): boolean => {
      const dp = domPathOf.get(normPath);
      if (!dp || typeof window === 'undefined') return false;
      const el = frameEl.querySelector<HTMLElement>(`[data-editor-path="${cssEscape(dp)}"]`);
      if (!el) return false;
      return (window.getComputedStyle(el).display ?? '').toLowerCase() === 'contents';
    };

    // 이 컨테이너의 직접 자식(드래그 노드/자손 제외) rect 수집.
    // **시각 흐름 투명화**:
    // `display:contents` 직접 자식은 그 자손이 시각적으로 이 컨테이너의 흐름을 차지한다.
    // 그 contents 자식 위치에 이 컨테이너 레벨 gap 을 만들면 — 그 자리는 사실 래퍼 내부
    // 카드들의 영역이므로 — 카드를 드롭하면 래퍼 밖으로 튀어 reflow("딸려옴")한다.
    // 그래서 contents 자식은 **이 레벨의 삽입 경계에서 제외**한다. 반면 일반(비-contents)
    // 자식(예: Welcome 히어로)은 정당한 grid 형제이므로 그 주변 gap 은 유지 — 카드를
    // Welcome 옆 grid 위치로 옮기는 동작이 가능해야 한다.
    const children = elements
      .filter((e) => directChildIndex(containerPath, e.path) !== null)
      .filter((e) => !isSelfOrDescendant(e.path, normalizedDraggedPath))
      .filter((e) => !isContentsChild(e.path)) // contents 자식은 이 레벨 경계에서 제외
      .map((e) => ({
        origIndex: directChildIndex(containerPath, e.path)!,
        rect: effectiveChildRect(e.rect, e.path, elements),
      }))
      .filter((c) => c.rect.width > 0 || c.rect.height > 0)
      .sort((a, b) => a.origIndex - b.origIndex);

    const flow = detectContainerLayoutFlow(isRoot ? frameEl : containerEl).kind;
    const horizontal =
      flow === 'flex_row_single' ||
      flow === 'flex_row_wrap' ||
      flow === 'grid_single_row' ||
      flow === 'grid_2d';

    if (children.length > 0) {
      // gap 슬롯 — accepts 면 생성. 인덱스는 **원본 트리 인덱스(origIndex)** 사용 —
      // contents 자식을 경계에서 빼도 삽입 위치는 원본 트리 기준이어야 moveNode 가 맞다.
      // accepts/slot id 의 containerPath 는 정규화 좌표(commit 의 parseEditorPath 와 일치).
      if (acceptsContainer(normContainerPath)) {
        children.forEach((child) => {
          // 자식 origIndex 앞 gap
          slots.push(
            makeGapSlot(normContainerPath, child.origIndex, child.rect, frameRect, horizontal, 'before')
          );
        });
        // 마지막(시각상 끝) 자식 뒤 gap — origIndex+1
        const last = children[children.length - 1]!;
        slots.push(
          makeGapSlot(normContainerPath, last.origIndex + 1, last.rect, frameRect, horizontal, 'after')
        );
      }
    } else {
      // 빈 컨테이너 — nest 슬롯(내부 영역 전체). allowsNestSlot 면 생성.
      if (!isRoot && allowsNestSlot(normContainerPath) && containerEl) {
        const r = containerEl.getBoundingClientRect();
        if (r.width > 0 && r.height > 0) {
          slots.push({
            id: `slot:${normContainerPath}:0`,
            kind: 'nest',
            containerPath: normContainerPath,
            index: 0,
            box: toBox(r, frameRect),
            orientation: 'area',
          });
        }
      } else if (isRoot) {
        // 루트가 완전히 빈 경우(컴포넌트 0개) — 루트 전체 nest 슬롯
        slots.push({
          id: `slot::0`,
          kind: 'gap',
          containerPath: '',
          index: 0,
          box: toBox(frameRect, frameRect),
          orientation: 'area',
        });
      }
    }
  }

  // frame 밖으로 클리핑된 노드(닫힌 모바일 드로어 등)에서 파생된 슬롯은 제외 —
  // overflow:hidden 은 시각만 가릴 뿐 getBoundingClientRect 좌표는 그대로라, 가려진
  // 노드 자리에 드롭 슬롯이 편집기 회색 배경에 노출될 수 있다.
  return slots.filter((s) => boxIntersectsFrame(s.box, frameEl));
}

/** gap 슬롯 생성 — 자식 rect 의 앞/뒤 가장자리에 얇은 띠 */
function makeGapSlot(
  containerPath: string,
  index: number,
  childRect: DOMRect,
  frameRect: DOMRect,
  horizontal: boolean,
  side: 'before' | 'after'
): DropSlot {
  const t = GAP_THICKNESS;
  let r: { left: number; top: number; width: number; height: number };
  if (horizontal) {
    // 세로 띠 — 자식 좌측(before) 또는 우측(after)
    const x = side === 'before' ? childRect.left : childRect.right;
    r = { left: x - t / 2, top: childRect.top, width: t, height: childRect.height };
  } else {
    // 가로 띠 — 자식 상단(before) 또는 하단(after)
    const y = side === 'before' ? childRect.top : childRect.bottom;
    r = { left: childRect.left, top: y - t / 2, width: childRect.width, height: t };
  }
  return {
    id: `slot:${containerPath}:${index}`,
    kind: 'gap',
    containerPath,
    index,
    box: {
      left: r.left - frameRect.left,
      top: r.top - frameRect.top,
      width: r.width,
      height: r.height,
      scale: 1,
    },
    orientation: horizontal ? 'vertical' : 'horizontal',
  };
}

/**
 * 자식의 시각 rect 보정 — `display:contents` 등으로 rect 0×0 이면 그 자손들의
 * union rect 로 대체한다. 자손도 없으면 원본(0×0) 그대로(상위 filter 가 제거).
 *
 * @param rect 자식 자신의 getBoundingClientRect 결과
 * @param childPath 자식 editor-path
 * @param elements frame 내 모든 editor 요소 path→rect
 */
function effectiveChildRect(
  rect: DOMRect,
  childPath: string,
  elements: Array<{ path: string; rect: DOMRect }>
): DOMRect {
  if (rect.width > 0 || rect.height > 0) return rect;
  // 0×0 — 자손들의 union 으로 보정 (contents 래퍼)
  const prefix = `${childPath}.children.`;
  let left = Infinity;
  let top = Infinity;
  let right = -Infinity;
  let bottom = -Infinity;
  for (const e of elements) {
    if (!e.path.startsWith(prefix)) continue;
    if (e.rect.width === 0 && e.rect.height === 0) continue;
    left = Math.min(left, e.rect.left);
    top = Math.min(top, e.rect.top);
    right = Math.max(right, e.rect.right);
    bottom = Math.max(bottom, e.rect.bottom);
  }
  if (left === Infinity) return rect; // 자손도 없음 — 원본 0×0 유지
  return {
    left,
    top,
    width: right - left,
    height: bottom - top,
    right,
    bottom,
    x: left,
    y: top,
    toJSON: () => ({}),
  } as DOMRect;
}

function toBox(r: DOMRect, frameRect: DOMRect): OverlayBox {
  return {
    left: r.left - frameRect.left,
    top: r.top - frameRect.top,
    width: r.width,
    height: r.height,
    scale: 1,
  };
}

/** path 의 부모. `0.children.2` → `0`, `0` → '', '' → null */
function parentPathOf(path: string): string | null {
  if (path === '') return null;
  const idx = path.lastIndexOf('.children.');
  if (idx < 0) return '';
  return path.slice(0, idx);
}

/** slot id 파싱 → { containerPath, index } */
export function parseSlotId(id: string): { containerPath: string; index: number } | null {
  if (!id.startsWith('slot:')) return null;
  const rest = id.slice('slot:'.length);
  const lastColon = rest.lastIndexOf(':');
  if (lastColon < 0) return null;
  const containerPath = rest.slice(0, lastColon);
  const index = Number(rest.slice(lastColon + 1));
  if (!Number.isFinite(index)) return null;
  return { containerPath, index };
}

function cssEscape(value: string): string {
  if (typeof CSS !== 'undefined' && typeof CSS.escape === 'function') return CSS.escape(value);
  return value.replace(/[^a-zA-Z0-9_\-.]/g, (ch) => `\\${ch}`);
}
