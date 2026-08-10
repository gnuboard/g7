// e2e:allow 순수 reducer 단위 테스트 — DOM/네트워크 영향 없음.
/**
 * layoutEditorReducer — SELECT_ROUTE 가 별도 편집 모드(modal/extension/base/iteration_item)를
 * route 모드로 복원하는지 검증.
 *
 * 회귀 배경: 확장/모달 편집 모드에서 트리의 일반 라우트를 클릭하면 SELECT_ROUTE 만 dispatch
 * 되어 selectedRoute 는 라우트로 바뀌지만 editMode 가 'extension'/'modal' 인 채 남았다. 그러면
 * LayoutEditorChrome 의 documentProviderValue 가 여전히 extensionDocument(빈 조각)를 공급해
 * 캔버스에 "이 레이아웃에는 표시할 컴포넌트가 없습니다" 가 떴다(라우트 화면 편집 불가).
 * 라우트를 선택하면 어떤 편집 모드에 있든 route 모드로 복원되어야 한다.
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
    ...overrides,
  };
}

describe('layoutEditorReducer — SELECT_ROUTE 가 별도 편집 모드를 종료', () => {
  it('extension 편집 모드 → SELECT_ROUTE → editMode=route 복원 + 라우트 선택', () => {
    const start = baseState({
      editMode: 'extension',
      selectedRoute: { path: '__extension__/6', layoutName: null },
      returnRoute: { path: '/shop/cart', layoutName: 'shop/cart' },
    });
    const next = layoutEditorReducer(start, {
      type: 'SELECT_ROUTE',
      route: { path: '/shop/checkout', layoutName: 'shop/checkout' },
    });
    expect(next.editMode).toBe('route');
    expect(next.selectedRoute).toEqual({ path: '/shop/checkout', layoutName: 'shop/checkout' });
    // 별도 편집 모드 복귀 상태는 깨끗이 초기화(다음 진입에 stale 잔존 방지)
    expect(next.returnRoute).toBeNull();
    expect(next.returnEditMode).toBeNull();
  });

  it('modal 편집 모드 → SELECT_ROUTE → editMode=route 복원', () => {
    const start = baseState({
      editMode: 'modal',
      selectedRoute: { path: '__modal__/delete_confirm', layoutName: 'admin_user_list' },
    });
    const next = layoutEditorReducer(start, {
      type: 'SELECT_ROUTE',
      route: { path: '/admin/orders', layoutName: 'admin_order_list' },
    });
    expect(next.editMode).toBe('route');
    expect(next.selectedRoute?.path).toBe('/admin/orders');
  });

  it('base 편집 모드 → SELECT_ROUTE → editMode=route 복원', () => {
    const start = baseState({
      editMode: 'base',
      selectedRoute: { path: '__base__/_user_base', layoutName: '_user_base' },
    });
    const next = layoutEditorReducer(start, {
      type: 'SELECT_ROUTE',
      route: { path: '/', layoutName: 'home' },
    });
    expect(next.editMode).toBe('route');
  });

  it('이미 route 모드면 동작 변화 없음 (라우트만 교체)', () => {
    const start = baseState({ editMode: 'route' });
    const next = layoutEditorReducer(start, {
      type: 'SELECT_ROUTE',
      route: { path: '/about', layoutName: 'about' },
    });
    expect(next.editMode).toBe('route');
    expect(next.selectedRoute?.path).toBe('/about');
  });

  it('SELECT_ROUTE route=null (선택 해제) 도 route 모드로 복원', () => {
    const start = baseState({
      editMode: 'extension',
      selectedRoute: { path: '__extension__/6', layoutName: null },
    });
    const next = layoutEditorReducer(start, { type: 'SELECT_ROUTE', route: null });
    expect(next.editMode).toBe('route');
    expect(next.selectedRoute).toBeNull();
  });
});

