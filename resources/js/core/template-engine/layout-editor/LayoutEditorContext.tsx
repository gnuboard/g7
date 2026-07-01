/**
 * LayoutEditorContext.tsx
 *
 * 레이아웃 편집기 도메인 상태.
 *
 * 본 Context 는 코어 컨텍스트 래퍼(TranslationProvider/TransitionProvider/
 * ResponsiveProvider/SlotProvider) **안쪽** 에 마운트된다. 코어
 * 컨텍스트는 항상 상속하며, 편집기 도메인 상태만 별도 useReducer 로 격리.
 *
 * @since engine-v1.50.0
 */

import React, { createContext, useContext, useEffect, useMemo, useReducer } from 'react';
import { trackEditorState } from './devtools/editorTrackers';
import type { EditorAccessError } from './types/editorErrors';
import type { EditorStateItemSpec } from './spec/specTypes';

/**
 * 라우트 트리 접힘 상태 영속화 키 (localStorage). 편집 세션/새로고침/템플릿 전환
 * 간에도 사용자가 접어둔 상태가 유지되도록 한다.
 */
const ROUTE_TREE_COLLAPSED_STORAGE_KEY = 'g7le.routeTree.collapsed';

/**
 * localStorage 에서 라우트 트리 접힘 상태를 읽는다. 미설정/접근 불가 시 false(펼침).
 *
 * @returns 접힘 여부
 */
function readPersistedRouteTreeCollapsed(): boolean {
  if (typeof window === 'undefined') return false;
  try {
    return window.localStorage?.getItem(ROUTE_TREE_COLLAPSED_STORAGE_KEY) === '1';
  } catch {
    // 프라이빗 모드/스토리지 차단 환경 — 기본값(펼침)으로 디그레이드
    return false;
  }
}

/**
 * 라우트 트리 접힘 상태를 localStorage 에 기록한다. 접근 불가 시 조용히 무시.
 *
 * @param collapsed 접힘 여부
 */
function writePersistedRouteTreeCollapsed(collapsed: boolean): void {
  if (typeof window === 'undefined') return;
  try {
    window.localStorage?.setItem(ROUTE_TREE_COLLAPSED_STORAGE_KEY, collapsed ? '1' : '0');
  } catch {
    // 스토리지 쓰기 실패 — 영속화만 포기(런타임 동작엔 영향 없음)
  }
}

// ============================================================================
// 타입 정의
// ============================================================================

export type EditMode = 'route' | 'base' | 'modal' | 'extension' | 'iteration_item';

/**
 * 프리뷰 디바이스 키.
 *
 * 프리셋(`desktop`/`tablet`/`mobile`) + `custom`(사용자 지정 폭) + 레이아웃이 선언한
 * 동적 breakpoint 키(`portable`, 커스텀 범위 `"600-900"` 등)를 모두 허용한다(
 * 디바이스 목록 동적 수집). 명명 프리셋은 자동완성·특례(custom 의 previewCustomWidth) 를
 * 위해 유지하되, 그 외 임의 키를 widen 으로 허용한다(리듀서는 키를 그대로 저장하므로 무영향).
 * 디바이스별 캔버스 폭은 `resolveDeviceWidth`(deviceList.ts)가 산출한다.
 */
export type PreviewDevice = 'desktop' | 'tablet' | 'mobile' | 'custom' | (string & {});

/** 프리뷰 색상 테마 — 캔버스 프레임 `.dark` 래퍼 토글 */
export type PreviewColorScheme = 'light' | 'dark';

export interface RouteTreeNode {
  /** 라우트 경로 또는 식별자 (모달/base 는 별도) */
  path: string;
  /** 레이아웃 이름 */
  layoutName: string | null;
  /** 표시 라벨 ($t: 키 또는 평문 — 렌더 시 해석) */
  label: string;
  /** 라벨 출처 */
  labelSource: 'editor_label' | 'title' | 'path';
  /** 아이콘 (meta.icon) */
  icon?: string | null;
  /** 출처 — getRoutesDataWithModules source 메타 */
  source: { kind: 'template' | 'module' | 'plugin' | 'core'; identifier: string | null };
  /** 리다이렉트 라우트 여부 */
  isRedirect?: boolean;
  /** 숨김 라우트 여부 (meta.hidden) */
  isHidden?: boolean;
  /** 노드 종류 — 라우트/모달/base/확장 */
  kind: 'route' | 'modal' | 'base' | 'extension';
  /** 모달 노드: 모달 id */
  modalId?: string;
  /** 모달 노드: 호스트 레이아웃 이름 */
  modalHostLayout?: string;
  /** 확장 노드: 확장 식별자 (PK 문자열) */
  extensionId?: string;
  /** 확장 노드: 확장 타입 (확장점/오버레이) — 배지 */
  extensionType?: 'extension_point' | 'overlay';
  /** 확장 노드: 우선순위 — 배지 */
  extensionPriority?: number;
  /** 확장 노드: 주입 대상 이름 (extension_point 이름 / overlay target_layout) */
  extensionTargetName?: string;
  /** 확장/모달/base 노드: 수정됨 배지 표시 여부 */
  isModified?: boolean;
  /** 확장/base 노드: 비활성(흐림) 여부 */
  isInactive?: boolean;
  /**
   * 연결 자식(모달/확장) 노드: 이 항목이 속한 호스트 라우트의 path.
   * 자식 클릭으로 별도 편집 모드(modal/extension) 에 진입하면 selectedRoute 가 가상 path 로
   * 바뀌어 호스트 라우트 노드 강조가 풀리므로, RouteTreePanel 이 이 값으로 호스트 라우트를
   * 계속 강조한다.
   */
  connectedHostRoutePath?: string;
  /** 중첩 자식 */
  children?: RouteTreeNode[];
}

export interface LayoutEditorState {
  /** 편집 대상 템플릿 식별자 */
  templateIdentifier: string;
  /** 라우트 트리 (구성 후) */
  routeTree: RouteTreeNode[];
  /**
   * 라우트 트리 fetch 실패 정보 — null = 정상.
   * useEditorRoutes 가 셋팅하고 LayoutEditorChrome 이 AccessErrorPanel 로 분기 렌더.
   * (Phase 2 후속 — routes 로드 실패도 자산/레이아웃 에러와 같은 풍성 UI 사용)
   */
  routesError: EditorAccessError | null;
  /** 선택된 라우트 (path + layoutName) */
  selectedRoute: { path: string; layoutName: string | null } | null;
  /** 라우트 트리 패널 접힘 상태 */
  isRouteTreeCollapsed: boolean;
  /** 현재 편집 모드 */
  editMode: EditMode;
  /** 별도 편집 모드 진입 직전 라우트 (종료 시 복귀) */
  returnRoute: { path: string; layoutName: string | null } | null;
  /**
   * 별도 편집 모드 진입 직전 편집 모드 (종료 시 복귀) — 중첩 진입 지원.
   * 예: 확장 편집 모드 안에서 반복 항목 편집에 진입했다가 종료하면 확장 편집 모드로 복귀.
   * 진입 시 직전 editMode 를 보관하고, 종료 시 이 값으로 복귀(미보관 시 'route').
   */
  returnEditMode: EditMode | null;
  /** 콘텐츠 로케일 (Phase 6 에서 사용 — Phase 1 은 초기값만 보유) */
  locale: string;
  /** 프리뷰 디바이스 (Phase 2 에서 사용) */
  previewDevice: PreviewDevice;
  /** 프리뷰 줌 (Phase 2 에서 사용) */
  previewZoom: number;
  /**
   * custom 디바이스 선택 시 적용할 프레임 폭(px).
   * previewDevice === 'custom' 일 때만 의미. 320~1920 클램프.
   */
  previewCustomWidth: number;
  /**
   * 프리뷰 색상 테마. 캔버스 프레임 `.dark` 래퍼 토글 —
   * 디바이스 토글과 동격(미리보기 전용). 속성 모달 열 때 색 모드 세부탭 기본값으로 스냅샷.
   */
  previewColorScheme: PreviewColorScheme;
  /** 이력 가용 플래그 (Phase 3 에서 연결) */
  history: { canUndo: boolean; canRedo: boolean };
  /** 글로벌 요소 추가 팔레트 열림 상태 (toolbar "+ 요소 추가" →) */
  isPaletteOpen: boolean;
  /**
   * 현재 편집 대상에 매칭되는 페이지 상태 목록.
   * LayoutEditorChrome 이 editor-spec.states + 현재 scope 로 도출해 SET_AVAILABLE_STATES
   * 로 셋팅. 0~1개면 PageStateSwitcher 가 토글을 미표시(디그레이드).
   */
  availableStates: EditorStateItemSpec[];
  /**
   * 활성 페이지 상태 id. 기본 상태(default:true/첫 항목)로 진입.
   * PageStateSwitcher 토글 변경 시 SET_ACTIVE_STATE 로 갱신 → 캔버스 즉시 재시뮬레이션.
   * availableStates 가 비면 null.
   */
  activeStateId: string | null;
  /**
   * 레이아웃 이름 → 현재(최신) 저장 버전 번호.
   * useEditorRoutes 가 편집기 routes 응답의 `layout_versions` 로 일괄 셋팅하고,
   * 저장/버전 복원 성공 시 SET_LAYOUT_VERSION 으로 해당 레이아웃만 동기화한다.
   * 버전 이력이 없는(한 번도 저장 안 된 원본) 레이아웃은 맵에 없음 → 배지 미표시.
   */
  layoutVersions: Record<string, number>;
  /**
   * 확장 ID(문자열) → 현재(최신) 저장 버전 번호.
   * useEditorRoutes 가 layout-extensions 응답의 확장별 `current_version` 으로 일괄 셋팅하고,
   * 확장 저장/버전 복원 성공 시 SET_EXTENSION_VERSION 으로 해당 확장만 동기화한다.
   * 버전 이력이 없는(한 번도 저장 안 된 원본) 확장은 맵에 없음 → 배지 미표시.
   */
  extensionVersions: Record<string, number>;
}

export type LayoutEditorAction =
  | { type: 'SET_ROUTE_TREE'; tree: RouteTreeNode[] }
  | { type: 'SET_ROUTES_ERROR'; error: EditorAccessError | null }
  | { type: 'SELECT_ROUTE'; route: { path: string; layoutName: string | null } | null }
  | { type: 'TOGGLE_ROUTE_TREE' }
  | { type: 'SET_LOCALE'; locale: string }
  | { type: 'ENTER_BASE_EDIT'; layoutName: string; returnRoute?: { path: string; layoutName: string | null } | null }
  | { type: 'EXIT_BASE_EDIT' }
  | { type: 'ENTER_MODAL_EDIT'; modalId: string; hostLayout: string; returnRoute?: { path: string; layoutName: string | null } | null }
  | { type: 'EXIT_MODAL_EDIT' }
  | { type: 'ENTER_EXTENSION_EDIT'; extensionId: string; extensionHost?: string; returnRoute?: { path: string; layoutName: string | null } | null }
  | { type: 'SET_RETURN_ROUTE'; route: { path: string; layoutName: string | null } }
  | { type: 'EXIT_EXTENSION_EDIT' }
  | { type: 'ENTER_ITERATION_ITEM_EDIT'; sourcePath: string; hostLayout?: string; returnRoute?: { path: string; layoutName: string | null } | null }
  | { type: 'EXIT_ITERATION_ITEM_EDIT' }
  | { type: 'SET_PREVIEW_DEVICE'; device: PreviewDevice }
  | { type: 'SET_PREVIEW_ZOOM'; zoom: number }
  | { type: 'SET_PREVIEW_CUSTOM_WIDTH'; width: number }
  | { type: 'SET_PREVIEW_COLOR_SCHEME'; scheme: PreviewColorScheme }
  | { type: 'TOGGLE_PALETTE' }
  | { type: 'SET_PALETTE_OPEN'; open: boolean }
  | { type: 'SET_AVAILABLE_STATES'; states: EditorStateItemSpec[]; activeStateId: string | null }
  | { type: 'SET_ACTIVE_STATE'; activeStateId: string | null }
  | { type: 'SET_LAYOUT_VERSIONS'; versions: Record<string, number> }
  | { type: 'SET_LAYOUT_VERSION'; layoutName: string; version: number }
  | { type: 'SET_EXTENSION_VERSIONS'; versions: Record<string, number> }
  | { type: 'SET_EXTENSION_VERSION'; extensionId: string; version: number };

function createInitialState(templateIdentifier: string, locale: string): LayoutEditorState {
  return {
    templateIdentifier,
    routeTree: [],
    routesError: null,
    selectedRoute: null,
    // 접힘 상태는 localStorage 에서 복원
    isRouteTreeCollapsed: readPersistedRouteTreeCollapsed(),
    editMode: 'route',
    returnRoute: null,
    returnEditMode: null,
    locale,
    previewDevice: 'desktop',
    previewZoom: 1,
    previewCustomWidth: 1024,
    previewColorScheme: 'light',
    history: { canUndo: false, canRedo: false },
    isPaletteOpen: false,
    availableStates: [],
    activeStateId: null,
    layoutVersions: {},
    extensionVersions: {},
  };
}

export function layoutEditorReducer(state: LayoutEditorState, action: LayoutEditorAction): LayoutEditorState {
  switch (action.type) {
    case 'SET_ROUTE_TREE':
      // 새 트리가 들어오면 이전 에러는 자동 해제 (재시도 성공 케이스).
      return { ...state, routeTree: action.tree, routesError: null };
    case 'SET_ROUTES_ERROR':
      return { ...state, routesError: action.error };
    case 'SELECT_ROUTE':
      // 라우트를 선택하면 어떤 별도 편집 모드(modal/extension/base/iteration_item)에 있든
      // route 모드로 복원한다. 그렇지 않으면 editMode 가 'extension' 등으로
      // 남아 캔버스가 빈 확장 조각 문서를 계속 공급해 "표시할 컴포넌트가 없습니다" 가 뜬다.
      // 별도 편집 모드 복귀 상태(returnRoute/returnEditMode)도 함께 초기화한다.
      return {
        ...state,
        editMode: 'route',
        selectedRoute: action.route,
        returnRoute: null,
        returnEditMode: null,
      };
    case 'TOGGLE_ROUTE_TREE':
      return { ...state, isRouteTreeCollapsed: !state.isRouteTreeCollapsed };
    case 'SET_LOCALE':
      return { ...state, locale: action.locale };
    case 'ENTER_BASE_EDIT':
      return {
        ...state,
        editMode: 'base',
        // URL 다이렉트 진입/새로고침 복원은 selectedRoute 가 아직 null 이므로 호출자가 host 로
        // 부터 도출한 returnRoute 를 명시 전달한다(미전달=클릭 진입 시 selectedRoute 사용).
        returnRoute: action.returnRoute !== undefined ? action.returnRoute : state.selectedRoute,
        selectedRoute: { path: `__base__/${action.layoutName}`, layoutName: action.layoutName },
      };
    case 'EXIT_BASE_EDIT':
      return {
        ...state,
        editMode: 'route',
        selectedRoute: state.returnRoute,
        returnRoute: null,
      };
    case 'ENTER_MODAL_EDIT':
      return {
        ...state,
        editMode: 'modal',
        // URL 복원 시 호출자가 host 기반 returnRoute 명시 전달(미전달=클릭 진입).
        returnRoute: action.returnRoute !== undefined ? action.returnRoute : state.selectedRoute,
        selectedRoute: { path: `__modal__/${action.modalId}`, layoutName: action.hostLayout },
      };
    case 'EXIT_MODAL_EDIT':
      return {
        ...state,
        editMode: 'route',
        selectedRoute: state.returnRoute,
        returnRoute: null,
      };
    case 'ENTER_EXTENSION_EDIT':
      return {
        ...state,
        editMode: 'extension',
        // URL 복원 시 호출자가 host 기반 returnRoute 명시 전달(미전달=클릭 진입).
        returnRoute: action.returnRoute !== undefined ? action.returnRoute : state.selectedRoute,
        // 호스트가 진입 시점에 확정되면(라우트 하위 진입·overlay) layoutName 에 담아 picker 를
        // 생략한다. 미확정(출처 그룹 진입·복수 호스트)이면 null → picker.
        selectedRoute: {
          path: `__extension__/${action.extensionId}`,
          layoutName: action.extensionHost ?? null,
        },
      };
    case 'SET_RETURN_ROUTE':
      // 별도 편집 모드 진입 후 복귀 라우트 후행 합성.
      // `?edit=__extension__/{id}` 단독 진입은 진입 시점에 호스트가 미확정이라 returnRoute 가
      // null 로 남는다(종료 시 라우트 선택 화면). 호스트가 picker 선택/단일 호스트 자동 확정된
      // 시점에 LayoutEditorChrome 이 본 액션으로 복귀 라우트를 보충한다. 클릭 진입 등으로 이미
      // returnRoute 가 있으면 덮지 않고, route 모드(복귀 상태 무의미)에서는 무시한다.
      if (state.editMode === 'route' || state.returnRoute !== null) return state;
      return { ...state, returnRoute: action.route };
    case 'EXIT_EXTENSION_EDIT':
      return {
        ...state,
        editMode: 'route',
        selectedRoute: state.returnRoute,
        returnRoute: null,
      };
    case 'ENTER_ITERATION_ITEM_EDIT':
      // 반복 항목 편집 모드 진입 — base/extension 모드와 동형.
      // 가상 path `__iteration__/{sourcePath}` 에 편집 대상 iteration 원본 노드의
      // 에디터 path 를 담아, 항목 템플릿 단독 편집 세션이 출처를 식별한다.
      // 진입 직전 라우트/편집모드를 보존하고 종료 시 복귀한다(확장 편집 모드 안에서
      // 진입한 경우 종료 시 확장 편집 모드로 복귀 — 중첩 진입 지원).
      return {
        ...state,
        editMode: 'iteration_item',
        // URL 다이렉트 진입 시 selectedRoute 가 null 이라 클릭 진입과 달리 returnRoute 가
        // 비어 종료 시 "라우트 선택" 화면으로 떨어졌다. 호출자가 host(layoutName)로부터 도출한
        // returnRoute 를 명시 전달하면 그것을 사용(미전달=클릭 진입 시 selectedRoute 사용).
        returnRoute: action.returnRoute !== undefined ? action.returnRoute : state.selectedRoute,
        returnEditMode: state.editMode,
        selectedRoute: {
          path: `__iteration__/${action.sourcePath}`,
          // 호스트 레이아웃명 — 클릭 진입은 현재 selectedRoute.layoutName 을 유지하고, URL 직접
          // 진입(새로고침/주소창)은 action.hostLayout(`?host=`)으로 복원한다. 둘 다 없으면 null
          // (그 경우 useLayoutDocument 가 빈 문서 → 진입 불가, URL 에 host 누락 시 방지).
          layoutName: action.hostLayout ?? state.selectedRoute?.layoutName ?? null,
        },
      };
    case 'EXIT_ITERATION_ITEM_EDIT':
      return {
        ...state,
        editMode: state.returnEditMode ?? 'route',
        selectedRoute: state.returnRoute,
        returnRoute: null,
        returnEditMode: null,
      };
    case 'SET_PREVIEW_DEVICE':
      return { ...state, previewDevice: action.device };
    case 'SET_PREVIEW_ZOOM':
      return { ...state, previewZoom: action.zoom };
    case 'SET_PREVIEW_CUSTOM_WIDTH':
      return { ...state, previewCustomWidth: action.width };
    case 'SET_PREVIEW_COLOR_SCHEME':
      return { ...state, previewColorScheme: action.scheme };
    case 'TOGGLE_PALETTE':
      return { ...state, isPaletteOpen: !state.isPaletteOpen };
    case 'SET_PALETTE_OPEN':
      return { ...state, isPaletteOpen: action.open };
    case 'SET_AVAILABLE_STATES':
      return { ...state, availableStates: action.states, activeStateId: action.activeStateId };
    case 'SET_ACTIVE_STATE':
      return { ...state, activeStateId: action.activeStateId };
    case 'SET_LAYOUT_VERSIONS':
      // 편집기 routes 응답의 레이아웃별 최신 버전 맵 일괄 셋팅.
      return { ...state, layoutVersions: action.versions };
    case 'SET_LAYOUT_VERSION':
      // 저장/버전 복원 성공 — 해당 레이아웃 버전만 동기화.
      return {
        ...state,
        layoutVersions: { ...state.layoutVersions, [action.layoutName]: action.version },
      };
    case 'SET_EXTENSION_VERSIONS':
      // layout-extensions 응답의 확장별 최신 버전 맵 일괄 셋팅.
      return { ...state, extensionVersions: action.versions };
    case 'SET_EXTENSION_VERSION':
      // 확장 저장/버전 복원 성공 — 해당 확장 버전만 동기화.
      return {
        ...state,
        extensionVersions: { ...state.extensionVersions, [action.extensionId]: action.version },
      };
    default:
      return state;
  }
}

/**
 * 편집 대상(라우트 path / 편집 모드)이 바뀌면 이전 페이지 상태가 stale 로 남지 않도록
 * availableStates/activeStateId 를 리셋한다. LayoutEditorChrome 이 새 대상에 매칭되는
 * states 를 다시 SET_AVAILABLE_STATES 로 셋팅한다(scope 재매칭).
 *
 * 본 래퍼가 리셋만 담당하고 셋팅은 Chrome effect 가 담당해, 같은 라우트 안에서의 상태
 * 토글(SET_ACTIVE_STATE)은 보존하면서 라우트/모드 전환 시에만 초기화된다.
 */
function reducerWithStateScopeReset(
  state: LayoutEditorState,
  action: LayoutEditorAction,
): LayoutEditorState {
  const next = layoutEditorReducer(state, action);
  if (
    (next.selectedRoute?.path ?? null) !== (state.selectedRoute?.path ?? null) ||
    next.editMode !== state.editMode
  ) {
    const needsStateReset = next.availableStates.length > 0 || next.activeStateId !== null;
    if (needsStateReset) {
      return { ...next, availableStates: [], activeStateId: null };
    }
  }
  return next;
}

// ============================================================================
// Context
// ============================================================================

export interface LayoutEditorContextValue {
  state: LayoutEditorState;
  dispatch: React.Dispatch<LayoutEditorAction>;
}

const LayoutEditorContext = createContext<LayoutEditorContextValue | null>(null);

export interface LayoutEditorProviderProps {
  templateIdentifier: string;
  initialLocale: string;
  children: React.ReactNode;
}

/**
 * reducer 호출마다 devtools `editor-state` 카테고리에 스냅샷 적재.
 * devtools 비활성 환경에서는 no-op 이므로 회귀 위험 없음.
 */
function reducerWithDevtools(state: LayoutEditorState, action: LayoutEditorAction): LayoutEditorState {
  const next = reducerWithStateScopeReset(state, action);
  trackEditorState(next, action.type);
  return next;
}

export function LayoutEditorProvider({
  templateIdentifier,
  initialLocale,
  children,
}: LayoutEditorProviderProps): React.ReactElement {
  const [state, dispatch] = useReducer(
    reducerWithDevtools,
    undefined,
    () => {
      const initial = createInitialState(templateIdentifier, initialLocale);
      trackEditorState(initial, undefined);
      return initial;
    }
  );

  // 접힘 상태가 바뀔 때마다 localStorage 에 영속화.
  useEffect(() => {
    writePersistedRouteTreeCollapsed(state.isRouteTreeCollapsed);
  }, [state.isRouteTreeCollapsed]);

  const value = useMemo(() => ({ state, dispatch }), [state]);

  return React.createElement(LayoutEditorContext.Provider, { value }, children);
}

export function useLayoutEditor(): LayoutEditorContextValue {
  const ctx = useContext(LayoutEditorContext);
  if (!ctx) {
    throw new Error('useLayoutEditor must be used within LayoutEditorProvider');
  }
  return ctx;
}
