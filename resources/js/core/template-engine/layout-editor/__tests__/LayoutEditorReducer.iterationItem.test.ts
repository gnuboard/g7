// e2e:allow 순수 reducer 단위 테스트 — DOM/네트워크 영향 없음.
/**
 * layoutEditorReducer — 반복 항목 편집 모드(iteration_item) 진입/종료 
 *
 * base/extension 모드와 동형으로 ENTER 시 가상 path `__iteration__/{sourcePath}` 로
 * 전환하고 returnRoute 를 보존, EXIT 시 route 모드로 복귀하는지 검증한다.
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
    history: { canUndo: false, canRedo: false },
    isPaletteOpen: false,
    ...overrides,
  };
}

describe('layoutEditorReducer — iteration_item 모드', () => {
  it('ENTER_ITERATION_ITEM_EDIT → editMode=iteration_item + 가상 path + returnRoute 보존', () => {
    const start = baseState();
    const next = layoutEditorReducer(start, {
      type: 'ENTER_ITERATION_ITEM_EDIT',
      sourcePath: '2.children.5.children.0',
    });
    expect(next.editMode).toBe('iteration_item');
    expect(next.selectedRoute?.path).toBe('__iteration__/2.children.5.children.0');
    // layoutName 은 진입 직전 라우트의 것을 유지 (단독 렌더 세션이 사용)
    expect(next.selectedRoute?.layoutName).toBe('home');
    // 종료 시 복귀할 라우트 보존
    expect(next.returnRoute).toEqual({ path: '/', layoutName: 'home' });
  });

  it('EXIT_ITERATION_ITEM_EDIT → route 모드 복귀 + returnRoute 초기화', () => {
    const entered = layoutEditorReducer(baseState(), {
      type: 'ENTER_ITERATION_ITEM_EDIT',
      sourcePath: '2.children.5.children.0',
    });
    const exited = layoutEditorReducer(entered, { type: 'EXIT_ITERATION_ITEM_EDIT' });
    expect(exited.editMode).toBe('route');
    expect(exited.selectedRoute).toEqual({ path: '/', layoutName: 'home' });
    expect(exited.returnRoute).toBeNull();
  });

  it('selectedRoute 가 null 이어도 진입 안전 (layoutName null 폴백)', () => {
    const next = layoutEditorReducer(baseState({ selectedRoute: null }), {
      type: 'ENTER_ITERATION_ITEM_EDIT',
      sourcePath: '0',
    });
    expect(next.editMode).toBe('iteration_item');
    expect(next.selectedRoute?.path).toBe('__iteration__/0');
    expect(next.selectedRoute?.layoutName).toBeNull();
  });

  // 회귀: 확장 편집 모드 안에서 반복 항목 편집에 진입했다가 종료하면
  // route 가 아니라 확장 편집 모드로 복귀해야 한다(중첩 진입). 종전엔 EXIT 가 editMode='route'
  // 를 강제해 확장 컨텍스트를 잃고 "라우트 선택" 화면으로 떨어졌다.
  it('확장 편집 모드 안에서 진입 → 종료 시 확장 편집 모드로 복귀 (중첩, returnEditMode)', () => {
    const inExtension = baseState({
      editMode: 'extension',
      selectedRoute: { path: '__extension__/9', layoutName: null },
    });
    const entered = layoutEditorReducer(inExtension, {
      type: 'ENTER_ITERATION_ITEM_EDIT',
      sourcePath: '0.children.2',
    });
    expect(entered.editMode).toBe('iteration_item');
    expect(entered.returnEditMode).toBe('extension');
    expect(entered.returnRoute).toEqual({ path: '__extension__/9', layoutName: null });

    const exited = layoutEditorReducer(entered, { type: 'EXIT_ITERATION_ITEM_EDIT' });
    // route 가 아니라 진입 직전 모드(extension)로 복귀.
    expect(exited.editMode).toBe('extension');
    expect(exited.selectedRoute).toEqual({ path: '__extension__/9', layoutName: null });
    expect(exited.returnEditMode).toBeNull();
    expect(exited.returnRoute).toBeNull();
  });

  it('route 모드에서 진입 시 returnEditMode=route → 종료 시 route 복귀(기존 동작 유지)', () => {
    const entered = layoutEditorReducer(baseState(), {
      type: 'ENTER_ITERATION_ITEM_EDIT',
      sourcePath: '1',
    });
    expect(entered.returnEditMode).toBe('route');
    const exited = layoutEditorReducer(entered, { type: 'EXIT_ITERATION_ITEM_EDIT' });
    expect(exited.editMode).toBe('route');
  });

  // URL 다이렉트 진입: selectedRoute 가 null 이어도 호출자가 host 로부터 도출한
  // returnRoute 를 명시 전달하면 종료 시 그 호스트 라우트로 복귀한다("라우트 선택" 화면 회귀 방지).
  it('selectedRoute=null 이라도 returnRoute 명시 전달 시 종료 후 그 라우트로 복귀', () => {
    const entered = layoutEditorReducer(baseState({ selectedRoute: null }), {
      type: 'ENTER_ITERATION_ITEM_EDIT',
      sourcePath: '2.children.5.children.0',
      returnRoute: { path: '/boards/popular', layoutName: 'board/popular' },
    });
    expect(entered.editMode).toBe('iteration_item');
    expect(entered.returnRoute).toEqual({ path: '/boards/popular', layoutName: 'board/popular' });
    // 진입 시 layoutName 은 hostLayout 미전달이라 selectedRoute(null) 폴백이지만, returnRoute 는 합성됨.
    const exited = layoutEditorReducer(entered, { type: 'EXIT_ITERATION_ITEM_EDIT' });
    expect(exited.editMode).toBe('route');
    expect(exited.selectedRoute).toEqual({ path: '/boards/popular', layoutName: 'board/popular' });
  });

  // returnRoute 를 명시 전달하지 않으면(클릭 진입) 종전대로 selectedRoute 를 보존한다(회귀 없음).
  it('returnRoute 미전달(클릭 진입) 시 selectedRoute 보존', () => {
    const entered = layoutEditorReducer(baseState(), {
      type: 'ENTER_ITERATION_ITEM_EDIT',
      sourcePath: '1',
    });
    expect(entered.returnRoute).toEqual({ path: '/', layoutName: 'home' });
  });
});
