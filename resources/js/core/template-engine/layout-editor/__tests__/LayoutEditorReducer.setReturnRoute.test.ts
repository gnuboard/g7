// e2e:allow 순수 reducer 단위 테스트 — DOM/네트워크 영향 없음.
/**
 * layoutEditorReducer — SET_RETURN_ROUTE (host 미지정 확장 URL 직접 진입 종료 복귀 보강)
 *
 * `?edit=__extension__/{id}` 단독(host 미지정) 진입은 진입 시점에 호스트가 미확정이라
 * returnRoute 가 null 로 남아 종료 시 "라우트 선택" 화면으로 떨어졌다.
 * 호스트 확정 시점(picker 선택/단일 호스트 자동 확정)에 LayoutEditorChrome 이
 * SET_RETURN_ROUTE 로 복귀 라우트를 후행 합성한다. 본 테스트는 reducer 의
 * 합성/보존/무시 분기를 잠근다.
 *
 * @since engine-v1.50.0
 */

import { describe, it, expect } from 'vitest';
import { layoutEditorReducer, type LayoutEditorState } from '../LayoutEditorContext';

function baseState(overrides: Partial<LayoutEditorState> = {}): LayoutEditorState {
  return {
    templateIdentifier: 'sirsoft-basic',
    routeTree: [],
    routesError: null,
    selectedRoute: { path: '/', layoutName: 'home' },
    isRouteTreeCollapsed: false,
    editMode: 'route',
    returnRoute: null,
    returnEditMode: null,
    locale: 'ko',
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
    ...overrides,
  } as LayoutEditorState;
}

describe('layoutEditorReducer — SET_RETURN_ROUTE (호스트 확정 후 복귀 라우트 합성)', () => {
  it('확장 편집 모드 + returnRoute null → 합성 적용', () => {
    // host 미지정 URL 직접 진입: ENTER_EXTENSION_EDIT 가 returnRoute null 로 진입한 상태.
    const entered = layoutEditorReducer(baseState({ selectedRoute: null }), {
      type: 'ENTER_EXTENSION_EDIT',
      extensionId: '38',
      returnRoute: null,
    });
    expect(entered.returnRoute).toBeNull();

    const synthesized = layoutEditorReducer(entered, {
      type: 'SET_RETURN_ROUTE',
      route: { path: 'auth/register', layoutName: 'auth/register' },
    });
    expect(synthesized.returnRoute).toEqual({ path: 'auth/register', layoutName: 'auth/register' });

    // 종료 시 합성된 라우트로 복귀 (종전: null → 라우트 선택 화면).
    const exited = layoutEditorReducer(synthesized, { type: 'EXIT_EXTENSION_EDIT' });
    expect(exited.editMode).toBe('route');
    expect(exited.selectedRoute).toEqual({ path: 'auth/register', layoutName: 'auth/register' });
  });

  it('기존 returnRoute(클릭 진입) 가 있으면 덮지 않음', () => {
    const entered = layoutEditorReducer(baseState(), {
      type: 'ENTER_EXTENSION_EDIT',
      extensionId: '38',
    });
    // 클릭 진입 — 진입 직전 selectedRoute 가 returnRoute 로 보존됨.
    expect(entered.returnRoute).toEqual({ path: '/', layoutName: 'home' });

    const next = layoutEditorReducer(entered, {
      type: 'SET_RETURN_ROUTE',
      route: { path: 'other/path', layoutName: 'other' },
    });
    expect(next.returnRoute).toEqual({ path: '/', layoutName: 'home' });
    expect(next).toBe(entered); // 상태 객체 불변(no-op)
  });

  it('route 모드(복귀 상태 무의미)에서는 무시', () => {
    const start = baseState();
    const next = layoutEditorReducer(start, {
      type: 'SET_RETURN_ROUTE',
      route: { path: 'auth/register', layoutName: 'auth/register' },
    });
    expect(next).toBe(start);
    expect(next.returnRoute).toBeNull();
  });

  it('모달/반복 항목 등 다른 별도 편집 모드에서도 returnRoute null 이면 합성 허용', () => {
    const inIteration = baseState({
      editMode: 'iteration_item',
      selectedRoute: { path: '__iteration__/0', layoutName: 'home' },
      returnRoute: null,
    });
    const next = layoutEditorReducer(inIteration, {
      type: 'SET_RETURN_ROUTE',
      route: { path: '/', layoutName: 'home' },
    });
    expect(next.returnRoute).toEqual({ path: '/', layoutName: 'home' });
  });
});
