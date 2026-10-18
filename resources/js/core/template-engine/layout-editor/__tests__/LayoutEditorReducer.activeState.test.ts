// e2e:allow 순수 reducer 단위 테스트 — DOM/네트워크 영향 없음.
/**
 * layoutEditorReducer — 활성 페이지 상태의 모드 왕복 보존
 *
 * 저작자가 고른 상태(예: 「알림톡 템플릿 탭」)는 반복 항목·모달 편집을 드나들어도
 * 유지돼야 한다. 유지되지 않으면 그 상태에서만 보이던 구역이 다시 사라져 **같은 항목을
 * 이어서 편집할 수 없다**(PO 실측: 반복 항목 편집 진입 시 상태가 최초 모드로 되돌아감).
 *
 * 까다로운 지점은 전환 도중 매칭 목록이 **한 프레임 동안 비는 구간**이 있다는 것이다
 * (호스트 path 가 확정되기 전). activeStateId 만으로는 그 구간에서 기억이 지워지므로
 * lastActiveStateId 가 그 구간을 건너뛴다.
 */

import { describe, it, expect } from 'vitest';
import { layoutEditorReducer, type LayoutEditorState } from '../LayoutEditorContext';
import type { EditorStateItemSpec } from '../spec/specTypes';

const ITEMS: EditorStateItemSpec[] = [
  { id: 'connection_tab', default: true },
  { id: 'templates_tab' },
];

function baseState(overrides: Partial<LayoutEditorState> = {}): LayoutEditorState {
  return {
    templateIdentifier: 'sirsoft-admin_basic',
    routeTree: [],
    routesError: null,
    selectedRoute: { path: '*/admin/plugins/sirsoft-message_bizppurio/settings', layoutName: 'host' },
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
  } as LayoutEditorState;
}

/** 이 화면(비즈뿌리오 설정)의 scope 키. */
const SCOPE = 'route|*/admin/plugins/sirsoft-message_bizppurio/settings';
/** 다른 화면의 scope 키 — 상태 id 가 겹쳐도 기억이 넘어가면 안 된다. */
const OTHER_SCOPE = 'route|*/admin/plugins/sirsoft-koreaexim/settings';

/** 저작자가 상태를 고른 뒤의 상태값. */
function afterPicking(id: string): LayoutEditorState {
  const listed = layoutEditorReducer(baseState(), {
    type: 'SET_AVAILABLE_STATES',
    states: ITEMS,
    activeStateId: 'connection_tab',
    scopeKey: SCOPE,
  });
  return layoutEditorReducer(listed, { type: 'SET_ACTIVE_STATE', activeStateId: id });
}

describe('layoutEditorReducer — 활성 상태 보존', () => {
  /** @effects active_state_survives_enter_iteration_item_edit */
  it('고른 상태가 새 목록에도 있으면 기본값으로 풀지 않는다', () => {
    const picked = afterPicking('templates_tab');
    expect(picked.activeStateId).toBe('templates_tab');

    // 반복 항목 편집 진입 → scope 재도출로 같은 목록이 다시 셋팅된다.
    const next = layoutEditorReducer(picked, {
      type: 'SET_AVAILABLE_STATES',
      states: ITEMS,
      activeStateId: 'connection_tab', // 호출자가 계산한 기본 상태
      scopeKey: SCOPE,
    });
    expect(next.activeStateId).toBe('templates_tab');
  });

  /** @effects active_state_survives_transient_empty_state_list */
  it('목록이 잠깐 비는 구간을 지나도 복귀 시 고른 상태가 되살아난다', () => {
    const picked = afterPicking('templates_tab');

    // (1) 전환 도중 — 호스트 path 미확정으로 매칭 0건
    const empty = layoutEditorReducer(picked, {
      type: 'SET_AVAILABLE_STATES',
      states: [],
      activeStateId: null,
      scopeKey: SCOPE,
    });
    expect(empty.activeStateId).toBeNull();
    // 기억은 남아 있어야 한다 — 이게 없으면 아래 복귀에서 되살릴 근거가 없다.
    expect(empty.lastActiveStateId).toBe('templates_tab');

    // (2) 호스트 scope 확정 — 목록이 돌아온다
    const restored = layoutEditorReducer(empty, {
      type: 'SET_AVAILABLE_STATES',
      states: ITEMS,
      activeStateId: 'connection_tab',
      scopeKey: SCOPE,
    });
    expect(restored.activeStateId).toBe('templates_tab');
  });

  /** @effects active_state_resets_to_default_when_moving_to_other_screen */
  it('다른 화면으로 이동하면(고른 상태가 새 목록에 없음) 그 목록의 기본 상태로 간다', () => {
    const picked = afterPicking('templates_tab');
    const other: EditorStateItemSpec[] = [{ id: 'with_data', default: true }, { id: 'empty_data' }];
    const next = layoutEditorReducer(picked, {
      type: 'SET_AVAILABLE_STATES',
      states: other,
      activeStateId: 'with_data',
      scopeKey: OTHER_SCOPE,
    });
    expect(next.activeStateId).toBe('with_data');
  });

  // 상태 id 는 화면마다 재사용된다 — 번들 실측으로 `modal_filled` 하나가 170개 scope,
  // `validation_failed` 가 17개 scope 에 쓰인다. id 만 대조하면 A 화면에서 고른 상태가
  // 이름이 같다는 이유로 B 화면에서 되살아나, B 가 기본 상태로 열리지 않는다.
  /** @effects active_state_memory_is_scoped_to_its_screen */
  it('다른 화면에 같은 id 의 상태가 있어도 되살리지 않는다 (동명 상태 오인 방지)', () => {
    const picked = afterPicking('templates_tab');
    // 옮겨 간 화면도 'templates_tab' 을 갖지만 **다른 scope** 다.
    const sameIdsOtherScreen: EditorStateItemSpec[] = [
      { id: 'overview_tab', default: true },
      { id: 'templates_tab' },
    ];
    const next = layoutEditorReducer(picked, {
      type: 'SET_AVAILABLE_STATES',
      states: sameIdsOtherScreen,
      activeStateId: 'overview_tab',
      scopeKey: OTHER_SCOPE,
    });
    expect(next.activeStateId).toBe('overview_tab');
  });

  /** @effects active_state_memory_not_cleared_on_stateless_screen */
  it('상태 없는 화면으로 이동하면 활성 상태는 비지만 기억은 남는다', () => {
    const picked = afterPicking('templates_tab');
    const none = layoutEditorReducer(picked, {
      type: 'SET_AVAILABLE_STATES',
      states: [],
      activeStateId: null,
      scopeKey: null,
    });
    expect(none.activeStateId).toBeNull();
    expect(none.lastActiveStateId).toBe('templates_tab');
  });

  /** @effects picking_a_state_overwrites_the_memory */
  it('상태를 새로 고르면 기억도 그 값으로 덮인다', () => {
    const picked = afterPicking('templates_tab');
    const repicked = layoutEditorReducer(picked, {
      type: 'SET_ACTIVE_STATE',
      activeStateId: 'connection_tab',
    });
    expect(repicked.activeStateId).toBe('connection_tab');
    expect(repicked.lastActiveStateId).toBe('connection_tab');
  });
});
