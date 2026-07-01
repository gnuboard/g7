/**
 * useEditorUrlSync.ts
 *
 * 브라우저 URL ↔ LayoutEditorContext.selectedRoute 양방향 동기화.
 *
 * - 트리 → URL: `RouteTreePanel.handleSelect` 가 직접 `pushState` 호출 (본 hook 관여 안 함)
 * - URL → 트리:
 *   (1) 초기 마운트 시 `window.location.search` 의 `?route=` 를 routes 로드 완료
 *       시점에 한 번 적용 (useEditorRoutes 가 SET_ROUTE_TREE dispatch 한 뒤)
 *   (2) `popstate` 이벤트(브라우저 뒤로/앞으로) 발생 시 현재 URL 의 `?route=` 로
 *       selectedRoute 를 재설정. 다른 source 가 만든 history 항목도 동일하게
 *       처리(외부 라이브러리가 URL 을 바꿔도 캔버스가 따라간다).
 *
 * 본 hook 은 layout-editor 패키지 내부에서만 사용되며, 일반 사이트 렌더와 무관하다.
 * 부팅 시 호스트 라우터(react-router 등)가 별도 동작 중이라면 그 라우터는
 * `/admin/layout-editor/*` 경로에서 자체 라우팅을 하지 않아야 한다(이미 설계에서
 * template-engine.ts 가 LayoutEditorChrome 분기로 전환).
 *
 * @since engine-v1.50.0
 */

import { useEffect, useRef } from 'react';
import { useLayoutEditor, type RouteTreeNode, type EditMode } from '../LayoutEditorContext';
import {
  buildEditorUrl,
  extractInitialRoutePath,
  extractEditModePath,
  extractEditModeHost,
} from './useEditorMode';

/**
 * 별도 편집 모드 가상 path(`?edit=`) → ENTER_* 액션 dispatch.
 * 새로고침/뒤로가기로 URL 에 편집 모드가 담겨 들어온 경우 그 모드로 복원한다.
 *
 * @param editPath 가상 path (`__base__/{layout}` 등)
 * @param dispatch reducer dispatch
 * @returns 복원 시도 여부 (형식 인식 실패 시 false)
 */
function dispatchEditModeFromPath(
  editPath: string,
  hostLayout: string | null,
  dispatch: (action: any) => void,
  routeTree: RouteTreeNode[] = [],
): boolean {
  // URL 다이렉트 진입/새로고침 복원 시 returnRoute 합성.
  // 클릭 진입은 reducer 가 진입 직전 selectedRoute 를 returnRoute 로 보존하지만, URL 직접
  // 진입은 selectedRoute 가 null 이라 종료 시 "라우트 선택" 화면으로 떨어진다. host(layoutName)
  // 로부터 트리에서 호스트 라우트 노드를 찾아 returnRoute 를 명시 전달한다. 매칭 실패 시
  // undefined → reducer 가 기존 selectedRoute(클릭 경로)를 사용(회귀 없음).
  const returnRoute = hostLayout ? findRouteNodeByLayoutName(routeTree, hostLayout) : null;
  const returnRouteAction =
    returnRoute !== null
      ? { path: returnRoute.path, layoutName: returnRoute.layoutName }
      : undefined;

  if (editPath.startsWith('__base__/')) {
    const layoutName = editPath.slice('__base__/'.length);
    if (layoutName) {
      dispatch({ type: 'ENTER_BASE_EDIT', layoutName, returnRoute: returnRouteAction });
      return true;
    }
  } else if (editPath.startsWith('__modal__/')) {
    const modalId = editPath.slice('__modal__/'.length);
    // 모달 복원은 호스트 레이아웃명(`?host=`)이 함께 있어야 한다(modals[] 추출/저장 격리에 필요).
    if (modalId && !modalId.includes('/') && hostLayout) {
      dispatch({ type: 'ENTER_MODAL_EDIT', modalId, hostLayout, returnRoute: returnRouteAction });
      return true;
    }
    return false;
  } else if (editPath.startsWith('__extension__/')) {
    const extensionId = editPath.slice('__extension__/'.length);
    if (extensionId && !extensionId.includes('/')) {
      dispatch({ type: 'ENTER_EXTENSION_EDIT', extensionId, returnRoute: returnRouteAction });
      return true;
    }
  } else if (editPath.startsWith('__iteration__/')) {
    const sourcePath = editPath.slice('__iteration__/'.length);
    // URL 직접 진입/새로고침 복원은 호스트 레이아웃명(`?host=`)이 함께 있어야 한다 — 그래야
    // useLayoutDocument 가 그 호스트를 로드하고 sourcePath 노드를 찾는다(host 누락 시 빈 화면).
    if (sourcePath && hostLayout) {
      dispatch({
        type: 'ENTER_ITERATION_ITEM_EDIT',
        sourcePath,
        hostLayout,
        returnRoute: returnRouteAction,
      });
      return true;
    }
    return false;
  }
  return false;
}

/**
 * 트리에서 layoutName 으로 라우트 노드 탐색 (kind === 'route' 만 대상)
 *
 * URL 다이렉트 진입 복원 시 host(layoutName)로부터 호스트 라우트 path 를 역추적해 returnRoute
 * 를 합성하기 위함. 한 layoutName 이 복수 라우트에 매핑될 수 있으나(드묾), 편집 종료 후 어느
 * 호스트로 돌아가도 동일 레이아웃이므로 첫 매칭이면 충분하다.
 */
function findRouteNodeByLayoutName(
  tree: RouteTreeNode[],
  layoutName: string,
): RouteTreeNode | null {
  for (const node of tree) {
    if (node.kind === 'route' && node.layoutName === layoutName) return node;
    if (node.children && node.children.length > 0) {
      const found = findRouteNodeByLayoutName(node.children, layoutName);
      if (found) return found;
    }
  }
  return null;
}

/**
 * 트리에서 path 로 라우트 노드 탐색 (kind === 'route' 만 대상).
 *
 * base/modal/extension 가상 path 는 URL 동기화 대상에서 제외하므로 무시.
 */
function findRouteNodeByPath(tree: RouteTreeNode[], path: string): RouteTreeNode | null {
  for (const node of tree) {
    if (node.kind === 'route' && node.path === path) return node;
    if (node.children && node.children.length > 0) {
      const found = findRouteNodeByPath(node.children, path);
      if (found) return found;
    }
  }
  return null;
}

/**
 * URL ↔ selectedRoute 양방향 동기화 — LayoutEditorChromeBody 에서 호출.
 *
 * @param templateIdentifier 편집 대상 템플릿 식별자 (URL 조립에 사용)
 */
export function useEditorUrlSync(templateIdentifier: string): void {
  const { state, dispatch } = useLayoutEditor();
  const initialAppliedRef = useRef(false);
  // 현재 편집 모드 ref — popstate 핸들러(이벤트 클로저)가 최신 editMode 를 읽어 뒤로가기 시
  // 편집 모드를 종료시키기 위함(closure 가 캡처한 state 는 stale 할 수 있음).
  const editModeRef = useRef<EditMode>(state.editMode);
  editModeRef.current = state.editMode;

  // (1) 초기 ?route= 복원 — routes 로드 완료(routeTree.length > 0) 후 1회만 실행.
  // useEditorRoutes 가 SET_ROUTE_TREE 를 dispatch 하면 본 effect 가 재실행되어
  // 트리에서 path 매칭 노드를 찾아 SELECT_ROUTE 한다. 매칭 실패 시(잘못된 URL)
  // 그대로 두면 사용자가 트리에서 직접 선택할 수 있다 — 자동 redirect 하지 않는다.
  useEffect(() => {
    if (initialAppliedRef.current) return;
    if (state.routeTree.length === 0) return;
    if (typeof window === 'undefined') return;

    // (1-a) 별도 편집 모드(`?edit=`) 복원 우선 — 새로고침 시 base/extension/iteration 모드 유지.
    const editPath = extractEditModePath(window.location.search);
    if (editPath) {
      const host = extractEditModeHost(window.location.search);
      const restored = dispatchEditModeFromPath(editPath, host, dispatch, state.routeTree);
      initialAppliedRef.current = true;
      if (restored) return;
      // 복원 실패(형식 미인식 등) — route 복원으로 폴백.
    }

    // (1-b) 일반 라우트(`?route=`) 복원.
    const initialPath = extractInitialRoutePath(window.location.search);
    if (!initialPath) {
      initialAppliedRef.current = true;
      return;
    }

    const matched = findRouteNodeByPath(state.routeTree, initialPath);
    if (matched) {
      dispatch({
        type: 'SELECT_ROUTE',
        route: { path: matched.path, layoutName: matched.layoutName },
      });
    }
    initialAppliedRef.current = true;
  }, [state.routeTree, dispatch]);

  // (2) popstate 구독 — 브라우저 뒤로/앞으로/주소창 직접 변경 시 모드/라우트 재설정.
  useEffect(() => {
    if (typeof window === 'undefined') return;

    const handler = (): void => {
      // 별도 편집 모드(`?edit=`) 우선 — 뒤로/앞으로로 편집 모드 history 항목에 도달한 경우.
      const editPath = extractEditModePath(window.location.search);
      if (editPath) {
        const host = extractEditModeHost(window.location.search);
        if (dispatchEditModeFromPath(editPath, host, dispatch, state.routeTree)) return;
      }

      // ?edit= 가 사라진 URL 로 돌아온 경우(뒤로가기로 편집 모드 진입 전으로 복귀) — 현재
      // 편집 모드를 종료해 route 모드로 복귀시킨다. EXIT_* 가 returnRoute 로 복귀하므로
      // 진입 직전 라우트가 자연히 선택된다(URL 의 route 매칭보다 우선).
      if (editModeRef.current !== 'route') {
        switch (editModeRef.current) {
          case 'base':
            dispatch({ type: 'EXIT_BASE_EDIT' });
            return;
          case 'modal':
            dispatch({ type: 'EXIT_MODAL_EDIT' });
            return;
          case 'extension':
            dispatch({ type: 'EXIT_EXTENSION_EDIT' });
            return;
          case 'iteration_item':
            dispatch({ type: 'EXIT_ITERATION_ITEM_EDIT' });
            return;
        }
      }

      const path = extractInitialRoutePath(window.location.search);
      if (!path) {
        // ?route=/?edit= 둘 다 없는 경우 — 선택 해제 (트리 초기 상태로).
        dispatch({ type: 'SELECT_ROUTE', route: null });
        return;
      }
      const matched = findRouteNodeByPath(state.routeTree, path);
      if (matched) {
        dispatch({
          type: 'SELECT_ROUTE',
          route: { path: matched.path, layoutName: matched.layoutName },
        });
      }
      // 매칭 실패는 무시 (외부에서 잘못된 URL 로 이동한 경우)
    };

    window.addEventListener('popstate', handler);
    return () => {
      window.removeEventListener('popstate', handler);
    };
  }, [state.routeTree, dispatch]);

  // (3) selectedRoute/editMode 변경 → URL 동기화.
  // 별도 편집 모드 진입(`?edit=`)은 pushState 로 history 항목을 남겨 URL 이 바뀌고 뒤로가기가
  // 동작하게 한다. 일반 라우트/복귀는 replaceState 로 history 를 더럽히지 않는다.
  const prevEditModeRef = useRef<EditMode>('route');
  useEffect(() => {
    if (typeof window === 'undefined') return;
    if (!window.history?.replaceState) return;
    // routes 로드 전에는 보정하지 않음 — 초기 복원 effect 와 경쟁 회피
    if (state.routeTree.length === 0) return;

    const expectedUrl = buildEditorUrl(
      templateIdentifier,
      state.selectedRoute?.path ?? null,
      state.selectedRoute?.layoutName ?? null,
    );
    const currentUrl = `${window.location.pathname}${window.location.search}`;
    const prevMode = prevEditModeRef.current;
    prevEditModeRef.current = state.editMode;

    if (currentUrl === expectedUrl) return;

    // route → 별도 편집 모드 진입: pushState(뒤로가기로 직전 라우트 복귀 가능).
    const enteringEditMode = prevMode === 'route' && state.editMode !== 'route';
    if (enteringEditMode && window.history.pushState) {
      window.history.pushState(window.history.state, '', expectedUrl);
    } else {
      window.history.replaceState(window.history.state, '', expectedUrl);
    }
  }, [state.selectedRoute, state.editMode, state.routeTree, templateIdentifier]);
}
