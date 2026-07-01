/**
 * layoutEditorReducer — 레이아웃 버전 맵 액션 테스트
 *
 * SET_LAYOUT_VERSIONS(routes 응답 일괄)와 SET_LAYOUT_VERSION(저장/복원 단건 동기화)이
 * layoutVersions 맵을 정확히 갱신하고 다른 상태를 건드리지 않음을 가드한다.
 */

import { describe, it, expect } from 'vitest';
import { layoutEditorReducer, type LayoutEditorState } from '../LayoutEditorContext';

function makeState(overrides: Partial<LayoutEditorState> = {}): LayoutEditorState {
  return {
    templateIdentifier: 'test-tpl',
    routeTree: [],
    routesError: null,
    selectedRoute: null,
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
  };
}

describe('layoutEditorReducer — layoutVersions', () => {
  it('SET_LAYOUT_VERSIONS — routes 응답 맵으로 일괄 교체', () => {
    const state = makeState({ layoutVersions: { stale: 1 } });
    const next = layoutEditorReducer(state, {
      type: 'SET_LAYOUT_VERSIONS',
      versions: { home: 3, 'auth/login': 7 },
    });
    expect(next.layoutVersions).toEqual({ home: 3, 'auth/login': 7 });
  });

  it('SET_LAYOUT_VERSION — 해당 레이아웃만 갱신하고 나머지 항목 보존 (저장/복원 동기화)', () => {
    const state = makeState({ layoutVersions: { home: 3, 'auth/login': 7 } });
    const next = layoutEditorReducer(state, {
      type: 'SET_LAYOUT_VERSION',
      layoutName: 'home',
      version: 4,
    });
    expect(next.layoutVersions).toEqual({ home: 4, 'auth/login': 7 });
  });

  it('SET_LAYOUT_VERSION — 맵에 없던 레이아웃(첫 저장)도 추가된다', () => {
    const state = makeState();
    const next = layoutEditorReducer(state, {
      type: 'SET_LAYOUT_VERSION',
      layoutName: 'board/index',
      version: 2,
    });
    expect(next.layoutVersions).toEqual({ 'board/index': 2 });
  });

  it('버전 액션은 selectedRoute/editMode 등 다른 상태를 변경하지 않는다', () => {
    const state = makeState({
      selectedRoute: { path: '/', layoutName: 'home' },
      editMode: 'modal',
    });
    const next = layoutEditorReducer(state, {
      type: 'SET_LAYOUT_VERSION',
      layoutName: 'home',
      version: 5,
    });
    expect(next.selectedRoute).toEqual({ path: '/', layoutName: 'home' });
    expect(next.editMode).toBe('modal');
  });
});

describe('layoutEditorReducer — extensionVersions', () => {
  it('SET_EXTENSION_VERSIONS — layout-extensions 응답 맵으로 일괄 교체', () => {
    const state = makeState({ extensionVersions: { '99': 1 } });
    const next = layoutEditorReducer(state, {
      type: 'SET_EXTENSION_VERSIONS',
      versions: { '7': 3, '12': 5 },
    });
    expect(next.extensionVersions).toEqual({ '7': 3, '12': 5 });
  });

  it('SET_EXTENSION_VERSION — 해당 확장만 갱신 + 레이아웃 맵은 불변 (저장/복원 동기화)', () => {
    const state = makeState({
      layoutVersions: { home: 3 },
      extensionVersions: { '7': 3, '12': 5 },
    });
    const next = layoutEditorReducer(state, {
      type: 'SET_EXTENSION_VERSION',
      extensionId: '7',
      version: 4,
    });
    expect(next.extensionVersions).toEqual({ '7': 4, '12': 5 });
    expect(next.layoutVersions).toEqual({ home: 3 });
  });

  it('SET_EXTENSION_VERSION — 맵에 없던 확장(첫 저장)도 추가된다', () => {
    const next = layoutEditorReducer(makeState(), {
      type: 'SET_EXTENSION_VERSION',
      extensionId: '21',
      version: 2,
    });
    expect(next.extensionVersions).toEqual({ '21': 2 });
  });
});
