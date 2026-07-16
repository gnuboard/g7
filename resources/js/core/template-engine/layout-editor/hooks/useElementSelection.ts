/**
 * useElementSelection.ts — 선택/hover 상태 + DOM↔노드 매핑
 *
 * `DynamicRenderer` 의 `onComponentSelect` / `onComponentHover` 콜백을 받아
 * 선택/hover componentPath 를 상태로 보관한다. 노드 본체는 `useLayoutDocument`
 * 의 트리에서 `layoutTreeUtils.findNodeByPath` 로 매번 역참조 — 트리 갱신 시
 * 일관성 유지.
 *
 * 본 hook 은 **선택 상태만** 관리. 오버레이 좌표 계산은 `ElementOverlay` 가
 * `overlayGeometry.measureOverlay` 로 직접 수행.
 *
 * Phase 3 범위:
 *  - 선택 / hover 상태 (componentPath 기반)
 *  - 선택 변경 시 devtools `editor-selection` 트래커 적재
 *  - 잠금 분류 메타 산출 (`isNodeLocked` 위임)
 *  - navigate / `<a href>` 액션 식별 → 링크 어포던스 종류 산출
 *
 * @since engine-v1.50.0
 */

import { useCallback, useMemo, useRef, useState } from 'react';
import type { EditorNode, ComponentPath } from '../utils/layoutTreeUtils';
import { findNodeByPath, isNodeLocked, isDataBoundNode, isSelfDataBoundNode, isExtensionPointNode, normalizeToExtensionEntry } from '../utils/layoutTreeUtils';
import { trackEditorSelection, type EditorSelectionInfo } from '../devtools/editorTrackers';

export type SelectionLockKind = EditorSelectionInfo['lockKind'];
export type NavAffordanceKind = EditorSelectionInfo['navAffordance'];

export interface UseElementSelectionParams {
  /** 현재 편집 중 루트 (가상 루트 — { children: components[] } 형태) */
  rootNode: EditorNode | null;
  /** 편집 모드 */
  editMode: 'route' | 'base' | 'modal' | 'extension' | 'iteration_item';
  /** 확장 편집 모드일 때 편집 중인 확장 PK — 그 외 미전달 */
  currentExtensionId?: number;
  /**
   * path 기반 편집 대상 모드(iteration_item / modal)에서 편집 가능 노드의 트리
   * 인덱스 경로. 이 경로 노드와 그 자손만 선택 가능하고, 나머지 호스트 전체는 선택 차단된다
   * (확장 편집의 출처 기반 잠금과 동형의 path 기반 잠금). extension/base 모드에서는 미전달.
   */
  editableRootPath?: ComponentPath | null;
  /**
   * componentPath 문자열을 ComponentPath(number[])로 변환할 때 사용.
   * DynamicRenderer 의 path 표기는 `0.children.2.iteration.1.sortable.3.children.0` 같은
   * 점 구분 문자열이며, `.children.` / `.iteration.` / `.sortable.` 키워드 사이의
   * 숫자만 추출해 index 배열로 변환한다.
   */
  pathParser?: (path: string) => ComponentPath;
  /**
   * 라우트 매칭 함수 — `navigate.path` / `<a href>` 가 내부 라우트 트리의 어느
   * 라우트와 매칭되는지 판정. 미전달 시 모든 내부 경로는 `route_not_in_tree`.
   */
  resolveRouteMatch?: (path: string) => 'route_in_tree' | 'route_not_in_tree';
}

export interface SelectionState {
  /** 선택된 노드 path (data-editor-path 표기 그대로) */
  selectedPath: string | null;
  /** hover 중인 노드 path */
  hoverPath: string | null;
  /** 선택 노드 (역참조 결과 — 트리 변경 시 자동 갱신) */
  selectedNode: EditorNode | null;
  /** hover 노드 */
  hoverNode: EditorNode | null;
  /** 선택 노드의 잠금 종류 */
  selectedLockKind: SelectionLockKind;
  /** 선택 노드의 네비게이션 어포던스 종류 */
  selectedNavAffordance: NavAffordanceKind;
  /** 선택 노드의 navigate.path 또는 A.href (어포던스 클릭 시 사용) */
  selectedNavTargetPath: string | null;
  /**
   * 선택 노드가 base 출처일 때 그 공통 레이아웃 파일명(예: `_user_base`).
   * "공통 레이아웃 편집" 어포던스에 어느 파일인지 표시.
   */
  selectedBaseLayout: string | null;
  /**
   * 선택 노드가 data_bound(반복) 영역일 때 그 데이터 출처 식별자
   * (예: `recent_posts`). iteration source 바인딩(`{{recent_posts?.data}}`)에서
   * 추출. "데이터 영역" 안내에 어느 데이터소스인지 표시.
   */
  selectedDataSourceId: string | null;
  /**
   * 선택 영역이 반복(iteration)인지 — 선택 노드 자신 또는 조상이 `iteration.source`
   * 를 가지면 true. 반복 항목 편집 모드(`iteration_item`) 진입 어포던스
   * 표시 여부 판정에 사용. props/text 바인딩만의 data_bound(폼 필드 등)는 false.
   */
  selectedIsIteration: boolean;
  /**
   * 반복 영역일 때 그 iteration 원본 노드의 에디터 path — 반복 항목 편집 모드
   * 진입 시 편집 대상 출처로 전달. 반복이 아니면 null.
   */
  selectedIterationSourcePath: string | null;
}

export interface UseElementSelectionReturn extends SelectionState {
  /** DynamicRenderer 의 onComponentSelect 콜백에 그대로 연결 */
  handleSelect: (componentId: string, e: React.MouseEvent | { dataset?: DOMStringMap; currentTarget?: { dataset?: DOMStringMap } }) => void;
  /** DynamicRenderer 의 onComponentHover 콜백에 그대로 연결 */
  handleHover: (
    componentId: string | null,
    e: React.MouseEvent | { dataset?: DOMStringMap; currentTarget?: { dataset?: DOMStringMap } }
  ) => void;
  /** 선택 해제 */
  clearSelection: () => void;
}

/**
 * 점 구분 path 문자열 → ComponentPath 변환 기본 구현.
 *
 * `.children.N` 의 N 은 컴포넌트 트리의 실제 자식 인덱스다. 반면 `.iteration.N`·
 * `.sortable.N` 의 N 은 **데이터 행 인덱스**(가상)다 — DynamicRenderer 가
 * `dataArray.map((item, index) => ...componentPath=\`${path}.iteration.${index}\`)`
 * 로 **같은 템플릿 노드**를 데이터 행마다 반복 렌더하기 때문이다. 즉 모든 행
 * 인스턴스는 소스 트리에서 동일한 한 노드(템플릿)를 가리킨다.
 *
 * 따라서 `iteration`/`sortable` 바로 뒤의 데이터 행 인덱스는 소스 path 에서
 * **제외**한다 — 그래야 어느 행 인스턴스를 선택·편집해도 템플릿 노드 1개를
 * 패치하게 되어 모든 행에 동시 반영된다("이터레이션 영역의 속성을
 * 편집하면 모든 이터레이션의 속성이 함께 바뀌어야 한다"). 행 인덱스를 자식
 * 인덱스로 잘못 끼워 넣으면 존재하지 않거나 엉뚱한 형제 노드를 패치한다.
 *
 * 예시:
 *  - `"0"`            → `[0]`
 *  - `"0.children.2"` → `[0, 2]`
 *  - `"0.children.2.iteration.1.children.0"` → `[0, 2, 0]` (행 인덱스 1 제외)
 *  - `"0.children.2.sortable.3.children.0"`  → `[0, 2, 0]` (행 인덱스 3 제외)
 */
export function parseEditorPath(path: string): ComponentPath {
  if (!path) return [];
  const out: ComponentPath = [];
  const tokens = path.split('.');
  for (let i = 0; i < tokens.length; i++) {
    const token = tokens[i];
    if (token === 'children') continue;
    if (token === 'iteration' || token === 'sortable') {
      // 바로 뒤 토큰(데이터 행 인덱스)은 가상 — 소스 path 에서 건너뛴다.
      // (제외) responsive 세그먼트는 반대로 보존한다 — 아래 분기 참조. 한 path 에
      // 둘이 공존해도(`responsive.portable.children.0.iteration.3.children.0`) 같은
      // 패스에서 일관 처리(G-2 합성 순서).
      i += 1;
      continue;
    }
    if (token === 'responsive') {
      // 디바이스 분기 세그먼트 — 다음 토큰이 breakpoint 키 원문. 가상이 아니라
      // 소스 위치를 바꾸므로 **보존**한다(G-2).
      const key = tokens[i + 1];
      if (typeof key === 'string' && key.length > 0) {
        out.push({ responsive: key });
        i += 1; // 키 토큰 소비
      }
      continue;
    }
    const n = Number(token);
    if (Number.isFinite(n) && Number.isInteger(n) && n >= 0) {
      out.push(n);
    }
  }
  return out;
}

/**
 * 노드의 액션 배열에서 navigate / `<a href>` 를 식별해 어포던스 종류·목적지
 * 경로를 결정한다.
 *
 * 외부 URL: `http://` / `https://` / `//` 로 시작.
 * 동적 경로: `{{...}}` 바인딩 포함.
 * 내부 라우트: 위 둘이 아니고 `/` 로 시작.
 */
export function classifyNavAffordance(
  node: EditorNode | null,
  resolveRouteMatch?: (path: string) => 'route_in_tree' | 'route_not_in_tree'
): { affordance: NavAffordanceKind; targetPath: string | null } {
  if (!node) return { affordance: 'none', targetPath: null };

  const target = extractNavTarget(node);
  if (!target) return { affordance: 'none', targetPath: null };

  if (containsBinding(target)) {
    return { affordance: 'dynamic_path', targetPath: target };
  }
  if (/^(https?:)?\/\//.test(target)) {
    return { affordance: 'external_url', targetPath: target };
  }
  if (target.startsWith('/')) {
    if (resolveRouteMatch) {
      return { affordance: resolveRouteMatch(target), targetPath: target };
    }
    return { affordance: 'route_not_in_tree', targetPath: target };
  }
  // 상대 경로 등은 미정 — `route_not_in_tree` 로 보수적 분류
  return { affordance: 'route_not_in_tree', targetPath: target };
}

/**
 * 노드의 navigate.path / A 컴포넌트의 href props 를 추출.
 */
function extractNavTarget(node: EditorNode): string | null {
  // 액션 배열 검색 — handler=navigate 의 params.path
  const actions = (node as any).actions;
  if (Array.isArray(actions)) {
    for (const a of actions) {
      if (a && typeof a === 'object' && a.handler === 'navigate') {
        const p = a.params?.path;
        if (typeof p === 'string' && p.length > 0) return p;
      }
    }
  }

  // A 컴포넌트의 href
  if (node.name === 'A' || node.type === 'A') {
    const href = node.props?.href;
    if (typeof href === 'string' && href.length > 0) return href;
  }

  return null;
}

function containsBinding(value: string): boolean {
  return /\{\{[\s\S]*?\}\}/.test(value);
}

/**
 * 선택 영역이 반복(iteration)인지 판정하고, 반복이면 그 iteration 원본 노드의
 * 에디터 path 를 구한다 (반복 항목 편집 모드 진입용).
 *
 * - 선택 노드 자신이 `iteration.source` 를 가지면 → 그 노드가 원본. path = selectedPath.
 * - 선택 path 가 펼침 인스턴스(`...{원본}.iteration.N...`) 안이면 → `.iteration.` 직전까지가
 *   원본 노드 path.
 * - 그 외(조상 중 iteration 보유) → 조상 path 를 selectedPath 에서 `.iteration.` 경계로 절단.
 * - props/text 바인딩만의 data_bound(폼 필드 등)는 반복이 아니므로 isIteration=false.
 *
 * @param node 선택 노드
 * @param ancestors 선택 노드의 조상(가까운 순)
 * @param selectedPath 선택 노드의 DOM 에디터 path
 * @returns 반복 여부 + 원본 노드 path
 */
function resolveIterationEntry(
  node: EditorNode,
  ancestors: EditorNode[],
  selectedPath: string | null,
): { isIteration: boolean; iterationSourcePath: string | null } {
  const hasIteration = (n: EditorNode | null | undefined): boolean =>
    !!(n?.iteration && (n.iteration as { source?: unknown }).source !== undefined);

  // iteration 원본 노드 path 는 **가상 인덱스(`.iteration.N` / `.sortable.N`)를 절대 포함하면
  // 안 된다**(feedback_editor_path_iteration_index_is_virtual). 그 인덱스가 섞이면 반복 항목
  // 편집 모드의 sourcePath 가 "특정 데이터 행 인스턴스 전체"를 가리켜, 호스트 트리에서 항목
  // 템플릿 내부 엘리먼트를 개별 선택할 수 없게 된다(통짜 잠금). 어떤 분기로 잡든 첫 `.iteration.`
  // / `.sortable.` 직전까지로 절단한다.
  const stripVirtualIndex = (p: string | null): string | null => {
    if (!p) return p;
    const idxIter = p.indexOf('.iteration.');
    const idxSort = p.indexOf('.sortable.');
    const cut = [idxIter, idxSort].filter((i) => i >= 0).sort((a, b) => a - b)[0];
    return cut === undefined ? p : p.slice(0, cut);
  };

  // 선택 노드 자신이 iteration 정의 노드
  if (hasIteration(node)) {
    return { isIteration: true, iterationSourcePath: stripVirtualIndex(selectedPath) };
  }

  // 펼침 인스턴스 안 — path 에 `.iteration.` 토큰이 있으면 그 직전까지가 원본 노드 path.
  if (selectedPath && selectedPath.includes('.iteration.')) {
    return { isIteration: true, iterationSourcePath: stripVirtualIndex(selectedPath) };
  }

  // 조상 중 iteration 보유 (path 토큰 없이 트리 구조상 조상이 iteration 인 경우)
  if (ancestors.some(hasIteration)) {
    return { isIteration: true, iterationSourcePath: stripVirtualIndex(selectedPath) };
  }

  return { isIteration: false, iterationSourcePath: null };
}

/**
 * data_bound 영역의 데이터 출처 식별자를 해석한다.
 *
 * 우선순위:
 *  1. 반복(iteration): 선택 노드/조상의 `iteration.source` 바인딩 루트 식별자
 *     (예: `{{recent_posts?.data}}` → `recent_posts`).
 *  2. 바인딩 노드: 선택 노드의 `text` 또는 `props` 값 바인딩 루트 식별자
 *     (예: 입력 필드의 `value="{{registerForm.email}}"` → `registerForm`,
 *      `error="{{_local.errors?.email?.[0]}}"` → `_local`).
 *
 * 여러 바인딩이 있으면 상태 루트(`_local`/`_global`/`route`/`query` 등)보다
 * 실제 데이터소스로 보이는 토큰을 우선 노출한다. 식별자를 못 찾으면 null.
 *
 * @param node 선택 노드
 * @param ancestors 선택 노드의 조상(가까운 순)
 * @returns 데이터 출처 식별자 (해석 불가 시 null)
 */
function resolveDataBoundSourceId(node: EditorNode, ancestors: EditorNode[]): string | null {
  // 1) iteration source (자기 자신 우선, 없으면 가까운 조상부터)
  const readIteration = (n: EditorNode | null | undefined): string | null => {
    const src = n?.iteration && (n.iteration as { source?: unknown }).source;
    return typeof src === 'string' && src.length > 0 ? src : null;
  };
  const iterationSource =
    readIteration(node) ?? ancestors.map(readIteration).find((s): s is string => s !== null) ?? null;
  if (iterationSource !== null) {
    const id = extractFirstBindingRoot(iterationSource);
    if (id !== null) return id;
  }

  // 2) 노드 자신의 text/props 바인딩에서 루트 토큰 후보 수집
  const candidates: string[] = [];
  if (typeof node.text === 'string') {
    candidates.push(...extractBindingRoots(node.text));
  }
  if (node.props && typeof node.props === 'object') {
    for (const value of Object.values(node.props)) {
      if (typeof value === 'string') candidates.push(...extractBindingRoots(value));
    }
  }
  if (candidates.length === 0) return null;

  // 상태 루트가 아닌 토큰을 우선(실제 데이터소스로 추정), 없으면 첫 토큰.
  const nonState = candidates.find((c) => !STATE_ROOTS.has(c));
  return nonState ?? candidates[0]!;
}

/** 데이터소스가 아닌 상태/컨텍스트 루트 토큰 — 후보 우선순위에서 후순위로 둔다. */
const STATE_ROOTS = new Set([
  '_local',
  '_global',
  '_computed',
  'route',
  'query',
  'props',
  'item',
  'index',
  'event',
  '$event',
  '$response',
  'response',
]);

/**
 * 바인딩 표현식 문자열에서 모든 `{{...}}` 의 루트 식별자를 순서대로 추출한다.
 *
 * @param value 바인딩을 포함할 수 있는 문자열
 * @returns 루트 식별자 배열(없으면 빈 배열)
 */
function extractBindingRoots(value: string): string[] {
  const roots: string[] = [];
  const re = /\{\{([\s\S]*?)\}\}/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(value)) !== null) {
    const root = extractIdentifierRoot(m[1]!);
    if (root !== null) roots.push(root);
  }
  return roots;
}

/**
 * 단일 바인딩 표현식(`{{...}}` 포함 가능)의 첫 루트 식별자를 추출한다.
 *
 * @param expression 표현식 문자열
 * @returns 루트 식별자 또는 null
 */
function extractFirstBindingRoot(expression: string): string | null {
  const inner = expression.replace(/\{\{|\}\}/g, '');
  return extractIdentifierRoot(inner);
}

/**
 * 표현식 본문에서 점/옵셔널체이닝/대괄호 이전의 첫 루트 식별자 토큰을 추출한다.
 *
 * @param inner `{{}}` 가 제거된 표현식 본문
 * @returns 루트 식별자 또는 null
 */
function extractIdentifierRoot(inner: string): string | null {
  const m = /[A-Za-z_$][A-Za-z0-9_$]*/.exec(inner.trim());
  return m ? m[0] : null;
}

/**
 * 노드의 잠금 종류 분류 (devtools selection 트래커 + 오버레이 어포던스용).
 */
export function classifyLockKind(
  node: EditorNode | null,
  editMode: 'route' | 'base' | 'modal' | 'extension' | 'iteration_item',
  currentExtensionId?: number,
  ancestors: EditorNode[] = []
): SelectionLockKind {
  if (!node) return 'none';
  if (isExtensionPointNode(node)) return 'extension_point';
  // 슬롯 노드 — 공통(base) 레이아웃의 슬롯
  // (`slot: "content"`, base 편집 캔버스는 표시 마커 `__editorSlotName` 으로 공급)은 자식
  // 화면 콘텐츠가 채우는 계약 영역이라 base 편집에서 선택/속성/리사이즈/드롭을 허용하면
  // 안 된다. extension_point 와 동일한 잠금류로 분류한다(선택 차단 + 진입 어포던스 없음).
  // 머지된 라우트 트리에는 slot 키가 남지 않으므로 base 단독 렌더에서만 발효된다.
  if (
    typeof (node as { slot?: unknown }).slot === 'string' ||
    typeof (node as { __editorSlotName?: unknown }).__editorSlotName === 'string'
  ) {
    return 'extension_point';
  }

  // 별도 편집 모드(extension/base/modal/iteration)에서만 **출처 잠금을 data_bound 보다 우선**
  // 적용한다. 그 모드들에서는 호스트 본체 노드가 폼 입력
  // 등으로 data_bound 인 경우가 많은데, data_bound 를 먼저 판정하면 잠겨야 할 호스트 노드가
  // "텍스트만 잠금(선택/스타일 허용)"으로 새어 편집된다. 그래서 잠긴 노드는 출처로 분류한다.
  //
  // route 모드는 **종전 동작을 그대로 보존**한다 — data_bound 를 먼저 판정해,
  // 확장 주입 영역의 data_bound 노드가 "데이터 영역" 표식을 유지하고, 잠긴 확장/ base 노드는
  // 아래 출처 분류로 "확장 편집"/"공통 레이아웃 편집" 어포던스를 띄운다. route 동작을 한 줄도
  // 바꾸지 않아 기존 확장 주입 영역 표식/진입이 그대로 복원된다.
  if (editMode !== 'route' && isNodeLocked(node, editMode, currentExtensionId)) {
    const src = node.__source?.kind;
    if (src === 'base') return 'base';
    if (src === 'partial') return 'partial';
    if (src === 'extension') return 'extension';
    // 출처 메타가 없는데 잠금(별도 모드 한정) — 보수적으로 base 취급(선택 차단).
    return 'base';
  }

  // ── 종전 분류 순서 (route 모드 + 별도 모드의 미잠금 노드) ──
  if (isDataBoundNode(node, ancestors)) return 'data_bound';
  if (!isNodeLocked(node, editMode, currentExtensionId)) return 'none';
  const src = node.__source?.kind;
  if (src === 'base') return 'base';
  if (src === 'partial') return 'partial';
  if (src === 'extension') return 'extension';
  return 'none';
}

/**
 * 노드의 확장 출처 PK 를 해석한다 (devtools editor-selection __sourceExtensionId).
 *
 * - `__source.kind === 'extension'` → `__source.extensionId`.
 * - inject_props 호스트 노드(`__injectedProps[]` 보유) → 첫 주입 확장 PK.
 * - 그 외 → null.
 *
 * @param node 선택/hover 노드
 * @returns 확장 PK 또는 null
 */
export function resolveSourceExtensionId(node: EditorNode | null): number | null {
  if (!node) return null;
  if (node.__source?.kind === 'extension' && typeof node.__source.extensionId === 'number') {
    return node.__source.extensionId;
  }
  const injected = (node as { __injectedProps?: Array<{ extensionId?: unknown }> }).__injectedProps;
  if (Array.isArray(injected) && injected.length > 0) {
    const first = injected[0]?.extensionId;
    if (typeof first === 'number') return first;
  }
  return null;
}

/**
 * DOM path 문자열(`2.children.0.children.0.iteration.1.children.0`)을 트리 깊이 `depth`
 * 까지의 prefix 로 자른다. `depth` 는 `parseEditorPath` 가 만드는 인덱스 path 의 길이
 * 기준(= 트리 노드 깊이) — `.children.N` 의 N 은 깊이 1 증가, `.iteration.N`/`.sortable.N`
 * 의 N(행 인덱스)은 깊이를 증가시키지 않으나 진입점이 그 안쪽이 아닌 한 prefix 에 포함된다.
 *
 * 진입점은 항상 `.children.` 경계(또는 루트 인덱스)이므로, depth 개의 트리 인덱스 토큰을
 * 소비하는 지점까지의 prefix 를 반환한다. iteration/sortable 토큰쌍은 깊이를 늘리지 않지만,
 * prefix 가 그 토큰쌍 직전에서 끝나도록(진입점은 행 인덱스 안쪽이 아님) 경계 토큰 전에서 자른다.
 *
 * @param domPath DOM data-editor-path 문자열
 * @param depth 트리 노드 깊이 (1 이상)
 * @returns 잘린 DOM path prefix
 */
export function sliceDomPathToDepth(domPath: string, depth: number): string {
  if (depth <= 0) return domPath;
  const tokens = domPath.split('.');
  let treeDepth = 0;
  const out: string[] = [];
  for (let i = 0; i < tokens.length; i++) {
    const token = tokens[i]!;
    if (token === 'children') {
      // 다음 토큰(자식 인덱스)이 트리 깊이를 1 증가시킨다.
      if (treeDepth >= depth) break; // 더 깊이 들어가면 진입점 초과 — 자름
      out.push(token);
      continue;
    }
    if (token === 'iteration' || token === 'sortable') {
      // 행 인덱스 토큰쌍 — 진입점이 그 안쪽이 아니라면 prefix 에서 제외하고 종료.
      if (treeDepth >= depth) break;
      out.push(token);
      continue;
    }
    // 숫자 인덱스 토큰
    out.push(token);
    // `children`/`iteration`/`sortable` 뒤의 첫 인덱스이거나(루트 제외) 루트 인덱스면 트리 깊이 +1.
    const prev = tokens[i - 1];
    if (prev === undefined || prev === 'children') {
      treeDepth += 1;
    }
    if (treeDepth >= depth) {
      // 진입점 깊이 도달 — 여기까지가 prefix.
      break;
    }
  }
  return out.join('.');
}

/**
 * DOM path(`selectedPath`)를 확장 조각 진입점으로 정규화 (통짜 표시).
 *
 * `normalizeToExtensionEntry` 가 인덱스 path 기준으로 진입점 깊이를 구하고, 그 깊이만큼
 * DOM path prefix 를 잘라 반환한다. 확장 조각 내부가 아니면 원본 DOM path 를 그대로 반환.
 */
function normalizeDomPathToExtensionEntry(
  rootNode: EditorNode | null,
  domPath: string | null,
  parser: (p: string) => ComponentPath,
  currentExtensionId?: number,
): string | null {
  if (!rootNode || !domPath) return domPath;
  const indexes = parser(domPath);
  const entryIndexes = normalizeToExtensionEntry(rootNode, indexes, currentExtensionId);
  if (entryIndexes.length === indexes.length) return domPath; // 변경 없음(일반 노드)
  return sliceDomPathToDepth(domPath, entryIndexes.length);
}

/**
 * 선택 노드의 ⓘ 컨텍스트 메뉴(속성 설정 / 복사 / 삭제) 허용 여부.
 *
 * 계획서 — 잠금 출처 노드는 그 확장/공통 레이아웃이
 * 소유·버전 관리하므로 라우트(템플릿) 편집 맥락에서 속성/구조 편집을 차단한다.
 * 잠금 노드에는 ⓘ 메뉴 대신 진입 어포던스("공통 레이아웃 편집"/"확장 편집")만
 * 표시한다.
 *
 * - `none`        → 허용 (일반 편집 노드)
 * - `data_bound` → 허용 (계획서 line 4383 — 텍스트만 편집 불가,
 *                   스타일/배치/복사/삭제는 허용)
 * - `base` → 차단 ("속성 메뉴 비활성", 공통 레이아웃 편집 모드 위임)
 * - `partial`     → 차단 (인클루드 원본이 SSoT)
 * - `extension` → 차단 (통짜 표시, 확장 편집 모드 위임)
 * - `extension_point` → 차단 (슬롯 위치는 호스트 개발자 영역)
 *
 * @param lockKind 선택 노드의 잠금 종류
 * @returns ⓘ 메뉴를 띄워도 되면 true
 */
export function isContextMenuAllowed(lockKind: SelectionLockKind): boolean {
  return lockKind === 'none' || lockKind === 'data_bound';
}

/**
 * 선택/hover 매핑 hook.
 */
export function useElementSelection(params: UseElementSelectionParams): UseElementSelectionReturn {
  const {
    rootNode,
    editMode,
    currentExtensionId,
    editableRootPath,
    pathParser = parseEditorPath,
    resolveRouteMatch,
  } = params;

  const [selectedPath, setSelectedPath] = useState<string | null>(null);
  const [hoverPath, setHoverPath] = useState<string | null>(null);

  // 별도 편집 모드(extension/base/modal/iteration)에서 **편집 중 대상과 무관한 잠긴 노드**는
  // 클릭·hover 를 무시한다. 종전엔 잠긴 노드도 선택은 되고
  // "공통 레이아웃 편집"/"확장 편집" 진입 칩만 떠서, 확장 편집 중 _base 로케일 Select 등 무관
  // 노드가 점선 선택됐다. 그 모드들에선 무관 노드를 다른 영역으로 넘어가는 통로로 쓰지 않고
  // (좌측 트리로 이동) 캔버스에선 편집 대상만 선택 가능하게 한다.
  //
  // route 모드는 종전 동작을 그대로 보존한다 — 확장 주입 영역·base 노드 선택 시 진입 어포던스를
  // 띄워 다른 영역으로 넘어가는 통로 역할이 필요하기 때문이다.
  const isSelectableInCurrentMode = useCallback(
    (path: string | null): boolean => {
      if (path === null) return true;
      if (editMode === 'route') return true;
      // path 기반 편집 대상 모드(iteration_item / modal). 편집 단위가 노드 출처가
      // 아니라 호스트 트리의 특정 위치다. editableRootPath(편집 대상 노드) 와 그 자손만 선택
      // 가능하고 나머지 호스트 전체는 차단(확장 편집의 출처 잠금과 동형의 path 잠금). 출처 기반
      // classifyLockKind 는 이 모드에서 부정확(호스트 본체가 route 출처라 일부가 새므로) → 미사용.
      if (editMode === 'iteration_item' || editMode === 'modal') {
        if (!editableRootPath || editableRootPath.length === 0) return true; // 컨텍스트 부재 폴백
        const target = pathParser(path);
        return isPathWithin(target, editableRootPath);
      }
      const node = resolveNode(rootNode, path, pathParser);
      if (!node) return true;
      const ancestors = resolveAncestors(rootNode, path, pathParser);
      const kind = classifyLockKind(node, editMode, currentExtensionId, ancestors);
      // none = 편집 가능, data_bound = 편집 중 대상의 데이터 영역(선택 허용). 그 외는 잠금 → 차단.
      return kind === 'none' || kind === 'data_bound';
    },
    [rootNode, pathParser, editMode, currentExtensionId, editableRootPath]
  );

  // DynamicRenderer 의 onComponentSelect 콜백은 (editorId, event) 시그니처.
  // editorId 보다 dataset.editorPath 가 안정 식별자(componentPath) 이므로 이를 우선.
  const handleSelect = useCallback(
    (_componentId: string, e: any) => {
      const ds = (e?.currentTarget?.dataset ?? e?.dataset) as DOMStringMap | undefined;
      const rawPath = ds?.editorPath ?? null;
      //  통짜 표시 — 확장 조각 내부 자식을 클릭하면 그 조각 진입점으로 선택을 올린다.
      // 확장 조각은 통짜(블랙박스) 단위로만 선택·잠금·어포던스가 적용되어야 한다.
      const path = normalizeDomPathToExtensionEntry(rootNode, rawPath, pathParser, currentExtensionId);
      if (!isSelectableInCurrentMode(path)) return; // 무관 잠금 노드 클릭 무시
      setSelectedPath(path);
    },
    [rootNode, pathParser, currentExtensionId, isSelectableInCurrentMode]
  );

  const handleHover = useCallback(
    (componentId: string | null, e: any) => {
      if (componentId === null) {
        setHoverPath(null);
        return;
      }
      const ds = (e?.currentTarget?.dataset ?? e?.dataset) as DOMStringMap | undefined;
      const rawPath = ds?.editorPath ?? null;
      // 선택과 동일하게 확장 조각은 진입점으로 — hover 점선도 조각 통짜로 표시.
      const path = normalizeDomPathToExtensionEntry(rootNode, rawPath, pathParser, currentExtensionId);
      if (!isSelectableInCurrentMode(path)) {
        setHoverPath(null); // 무관 잠금 노드는 hover 점선도 표시 안 함
        return;
      }
      setHoverPath(path);
    },
    [rootNode, pathParser, currentExtensionId, isSelectableInCurrentMode]
  );

  const clearSelection = useCallback(() => {
    setSelectedPath(null);
  }, []);

  // 선택/hover 노드 역참조 + 잠금/어포던스 분류
  const derived = useMemo<Omit<SelectionState, 'selectedPath' | 'hoverPath'>>(() => {
    const selectedNode = resolveNode(rootNode, selectedPath, pathParser);
    const hoverNode = resolveNode(rootNode, hoverPath, pathParser);
    const ancestors = selectedNode ? resolveAncestors(rootNode, selectedPath, pathParser) : [];
    let lockKind = classifyLockKind(selectedNode, editMode, currentExtensionId, ancestors);
    // path 기반 편집 대상 모드(iteration_item / modal). 편집 대상(editableRootPath
    // 자손) 노드의 잠금 분류를 정교화한다. `isDataBoundNode` 는 **조상 iteration 때문에** 항목
    // 템플릿 내부 노드를 전부 data_bound 로 잡으므로(평문 포함), 그대로 두면 항목을 편집할 수 없다
    // 그렇다고 일괄 `none` 으로 덮으면 항목 안의 **데이터 바인딩 노드**(`{{item.title}}`)
    // 까지 편집 가능으로 보여 더블클릭 시 아무 피드백 없이 무반응이 된다.
    //
    // 일반 편집기와 동일 가시: editableRootPath 자손에서
    //  - 노드 자신이 바인딩(`isSelfDataBoundNode`)  → `data_bound` 유지("데이터 영역 편집 불가" 안내)
    //  - 출처 잠금(확장/base/partial)               → 그대로 유지(해당 잠금 안내)
    //  - 그 외(평문/컨테이너)                        → `none`(편집 가능)
    // editableRootPath 밖은 종전 분류 유지(차단은 isSelectableInCurrentMode 가 담당).
    if (
      (editMode === 'iteration_item' || editMode === 'modal') &&
      selectedPath !== null &&
      selectedNode &&
      editableRootPath &&
      editableRootPath.length > 0 &&
      isPathWithin(pathParser(selectedPath), editableRootPath)
    ) {
      if (isSelfDataBoundNode(selectedNode)) {
        lockKind = 'data_bound';
      } else if (lockKind === 'data_bound') {
        // 조상 iteration 때문에 data_bound 로 잡힌 평문/컨테이너 → 편집 가능.
        lockKind = 'none';
      }
      // 그 외(extension/base/partial 출처 잠금)는 유지 — 항목 안의 확장 주입 등.
    }
    const { affordance, targetPath } = classifyNavAffordance(selectedNode, resolveRouteMatch);
    // base 출처 파일명 — "공통 레이아웃 편집" 라벨용
    const baseLayout =
      lockKind === 'base' && typeof selectedNode?.__source?.layout === 'string'
        ? selectedNode.__source.layout
        : null;
    // data_bound 반복 영역의 데이터 출처 식별자 — "데이터 영역" 안내 라벨용
    const dataSourceId =
      lockKind === 'data_bound' && selectedNode
        ? resolveDataBoundSourceId(selectedNode, ancestors)
        : null;
    // 반복(iteration) 여부 + 원본 노드 path — "반복 항목 편집" 모드 진입 어포던스용 
    const { isIteration, iterationSourcePath } =
      lockKind === 'data_bound' && selectedNode
        ? resolveIterationEntry(selectedNode, ancestors, selectedPath)
        : { isIteration: false, iterationSourcePath: null };
    return {
      selectedNode,
      hoverNode,
      selectedLockKind: lockKind,
      selectedNavAffordance: affordance,
      selectedNavTargetPath: targetPath,
      selectedBaseLayout: baseLayout,
      selectedDataSourceId: dataSourceId,
      selectedIsIteration: isIteration,
      selectedIterationSourcePath: iterationSourcePath,
    };
  }, [rootNode, selectedPath, hoverPath, editMode, currentExtensionId, editableRootPath, pathParser, resolveRouteMatch]);

  // devtools 트래커 — 선택 변경 시 한 번 적재 (hover 도 변경 시 별도 적재)
  const lastSelectionRef = useRef<string | null | undefined>(undefined);
  if (lastSelectionRef.current !== selectedPath) {
    lastSelectionRef.current = selectedPath;
    trackEditorSelection({
      op: selectedPath === null ? 'clear' : 'select',
      componentPath: selectedPath,
      componentName: getComponentDisplayName(derived.selectedNode),
      lockKind: derived.selectedLockKind,
      navAffordance: derived.selectedNavAffordance,
      sourceExtensionId: resolveSourceExtensionId(derived.selectedNode),
      timestamp: Date.now(),
    });
  }
  const lastHoverRef = useRef<string | null | undefined>(undefined);
  if (lastHoverRef.current !== hoverPath) {
    lastHoverRef.current = hoverPath;
    if (hoverPath !== null) {
      trackEditorSelection({
        op: 'hover',
        componentPath: hoverPath,
        componentName: getComponentDisplayName(derived.hoverNode),
        lockKind: classifyLockKind(derived.hoverNode, editMode, currentExtensionId, resolveAncestors(rootNode, hoverPath, pathParser)),
        navAffordance: 'none',
        sourceExtensionId: resolveSourceExtensionId(derived.hoverNode),
        timestamp: Date.now(),
      });
    }
  }

  return {
    selectedPath,
    hoverPath,
    ...derived,
    handleSelect,
    handleHover,
    clearSelection,
  };
}

function resolveNode(
  root: EditorNode | null,
  path: string | null,
  parser: (p: string) => ComponentPath
): EditorNode | null {
  if (!root || !path) return null;
  const indexes = parser(path);
  return findNodeByPath(root, indexes);
}

/**
 * target 인덱스 경로가 root 경로 자신이거나 그 자손인지 — path 기반 편집 대상 모드
 * (iteration_item / modal)의 선택 가능 판정. iteration 인스턴스 경로
 * (`[...source, N, ...]`)도 source 가 prefix 이므로 within 으로 정상 매칭된다(가상 인덱스 무관).
 *
 * @param target 클릭 노드의 인덱스 경로 (pathParser 결과)
 * @param root 편집 대상 노드의 인덱스 경로 (editableRootPath)
 * @returns target 이 root 와 같거나 root 의 자손이면 true
 */
function isPathWithin(target: ComponentPath, root: ComponentPath): boolean {
  if (target.length < root.length) return false;
  for (let i = 0; i < root.length; i++) {
    if (target[i] !== root[i]) return false;
  }
  return true;
}

function resolveAncestors(
  root: EditorNode | null,
  path: string | null,
  parser: (p: string) => ComponentPath
): EditorNode[] {
  if (!root || !path) return [];
  const indexes = parser(path);
  const out: EditorNode[] = [];
  for (let i = 0; i < indexes.length; i++) {
    const ancestorPath = indexes.slice(0, i);
    const node = findNodeByPath(root, ancestorPath);
    if (node) out.push(node);
  }
  return out;
}

function getComponentDisplayName(node: EditorNode | null): string | null {
  if (!node) return null;
  if (typeof node.name === 'string' && node.name.length > 0) return node.name;
  if (typeof node.type === 'string' && node.type.length > 0) return node.type;
  return null;
}
