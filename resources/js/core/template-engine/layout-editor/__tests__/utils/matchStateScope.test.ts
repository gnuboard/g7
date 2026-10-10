/**
 * matchStateScope.test.ts — 페이지 상태 scope 매칭
 *
 * 검증:
 *  - deriveCurrentScope: route/base/modal 인코딩 → scope 기준 / extension·iteration_item 은 호스트 scope 상속 / 미선택·호스트 미상 디그레이드
 *  - matchStateItems: kind+match 정확 일치 / route glob 1단계(`*`) / 그룹 concat / 미매칭 빈 배열
 *  - resolveDefaultStateId: default:true 우선 / 없으면 첫 항목 / 빈 배열 null
 */

import { describe, it, expect } from 'vitest';
import {
  deriveCurrentScope,
  matchStateItems,
  resolveDefaultStateId,
  resolveNextActiveStateId,
  stateScopeKey,
} from '../../utils/matchStateScope';
import type { EditorStateGroupSpec } from '../../spec/specTypes';

describe('deriveCurrentScope — 편집 대상 scope 도출', () => {
  /** @effects scope_route_exact_matches_current_route_path */
  it('route 모드 → 라우트 path 그대로', () => {
    expect(deriveCurrentScope('route', '/admin/users')).toEqual({ kind: 'route', match: '/admin/users' });
  });

  /** @effects scope_base_and_modal_match_exactly */
  it('base 모드 → `__base__/{name}` 에서 식별자 추출', () => {
    expect(deriveCurrentScope('base', '__base__/_user_base')).toEqual({ kind: 'base', match: '_user_base' });
  });

  /** @effects scope_base_and_modal_match_exactly */
  it('modal 모드 → `__modal__/{id}` 에서 modal id 추출', () => {
    expect(deriveCurrentScope('modal', '__modal__/confirm_delete')).toEqual({
      kind: 'modal',
      match: 'confirm_delete',
    });
  });

  /** @effects iteration_item_degrades_when_host_path_unknown */
  it('iteration_item 모드 — hostRoutePath 없으면 디그레이드(null)', () => {
    // 진입 직전 화면(returnRoute)을 모르면 물려받을 scope 가 없다.
    expect(deriveCurrentScope('iteration_item', '__iteration__/0.children.1')).toBeNull();
    expect(deriveCurrentScope('iteration_item', '__iteration__/0.children.1', null)).toBeNull();
    expect(deriveCurrentScope('iteration_item', '__iteration__/0.children.1', '')).toBeNull();
  });

  /** @effects iteration_item_inherits_host_route_scope, iteration_item_inherits_host_base_and_modal_scope */
  it('iteration_item 모드 — 호스트 scope 를 물려받는다 (route/base/modal 3갈래)', () => {
    // 반복 항목 템플릿은 그 항목을 그리는 화면의 상태 뒤에 있다(예: 알림톡 템플릿 행은
    // `query.tab=templates` 상태에서만 존재). 호스트 scope 를 물려받지 않으면 항목 편집에
    // 들어가는 순간 상태가 풀려 편집 대상이 캔버스에서 사라진다 — 상태 토글도 함께 숨는다.
    expect(
      deriveCurrentScope(
        'iteration_item',
        '__iteration__/0.children.1',
        '*/admin/plugins/sirsoft-message_bizppurio/settings',
      ),
    ).toEqual({ kind: 'route', match: '*/admin/plugins/sirsoft-message_bizppurio/settings' });

    expect(
      deriveCurrentScope('iteration_item', '__iteration__/2.children.0', '__base__/_user_base'),
    ).toEqual({ kind: 'base', match: '_user_base' });

    expect(
      deriveCurrentScope('iteration_item', '__iteration__/3.children.4', '__modal__/modal_bizppurio_template'),
    ).toEqual({ kind: 'modal', match: 'modal_bizppurio_template' });
  });

  /** @effects extension_inherits_host_modal_scope */
  it('extension 모드 — hostRoutePath(`__modal__/`) → modal scope', () => {
    // 모달 안에 주입된 확장 조각 — 호스트가 모달이면 modal scope 로 매칭한다.
    expect(deriveCurrentScope('extension', '__extension__/41', '__modal__/addressManageModal')).toEqual({
      kind: 'modal',
      match: 'addressManageModal',
    });
  });

  /** @effects simulator_noop_when_no_active_state */
  it('extension 모드 — hostRoutePath 없으면 디그레이드(null)', () => {
    // 호스트 path 미상(picker 미선택 등) → 폴백 안내로 디그레이드.
    expect(deriveCurrentScope('extension', '__extension__/35')).toBeNull();
    expect(deriveCurrentScope('extension', '__extension__/35', null)).toBeNull();
    expect(deriveCurrentScope('extension', '__extension__/35', '')).toBeNull();
  });

  /** @effects scope_route_exact_matches_current_route_path */
  it('extension 모드 — hostRoutePath(라우트) → route scope', () => {
    // 호스트가 라우트 레이아웃이면 그 라우트 path 로 route scope 매칭 (게이트 뒤 조각 노출).
    expect(deriveCurrentScope('extension', '__extension__/35', '/board/free/write')).toEqual({
      kind: 'route',
      match: '/board/free/write',
    });
  });

  /** @effects scope_base_and_modal_match_exactly */
  it('extension 모드 — hostRoutePath(`__base__/`) → base scope', () => {
    // 호스트가 공통 base 레이아웃(라우트 노드 없음)이면 base scope 로 매칭.
    expect(deriveCurrentScope('extension', '__extension__/38', '__base__/_user_base')).toEqual({
      kind: 'base',
      match: '_user_base',
    });
  });

  /** @effects simulator_noop_when_no_active_state */
  it('path 미선택 시 null', () => {
    expect(deriveCurrentScope('route', null)).toBeNull();
    expect(deriveCurrentScope('route', undefined)).toBeNull();
  });

  /** @effects scope_base_and_modal_match_exactly */
  it('base/modal 접두사 불일치 시 null', () => {
    expect(deriveCurrentScope('base', '/some/path')).toBeNull();
  });
});

describe('matchStateItems — scope 매칭 items 평탄화', () => {
  const groups: EditorStateGroupSpec[] = [
    {
      scope: { kind: 'route', match: '/login' },
      items: [
        { id: 'default', default: true },
        { id: 'login_failed' },
      ],
    },
    {
      scope: { kind: 'route', match: '/board/:slug/write' },
      items: [{ id: 'write_member' }],
    },
    {
      scope: { kind: 'base', match: '_user_base' },
      items: [{ id: 'logged_in' }],
    },
  ];

  /** @effects scope_route_exact_matches_current_route_path */
  it('kind+match 정확 일치 그룹의 items 만 반환', () => {
    const items = matchStateItems(groups, { kind: 'route', match: '/login' });
    expect(items.map((i) => i.id)).toEqual(['default', 'login_failed']);
  });

  /** @effects scope_route_exact_matches_current_route_path */
  it('path param 토큰(:slug)은 리터럴 정확 일치', () => {
    expect(matchStateItems(groups, { kind: 'route', match: '/board/:slug/write' }).map((i) => i.id)).toEqual([
      'write_member',
    ]);
    // 실제 채워진 path 는 매칭 안 됨(시뮬레이션 — 토큰 리터럴 키)
    expect(matchStateItems(groups, { kind: 'route', match: '/board/free/write' })).toEqual([]);
  });

  /** @effects scope_base_and_modal_match_exactly */
  it('base kind 정확 일치', () => {
    expect(matchStateItems(groups, { kind: 'base', match: '_user_base' }).map((i) => i.id)).toEqual([
      'logged_in',
    ]);
  });

  /** @effects scope_route_glob_matches_one_segment_wildcard */
  it('route `*` glob 1단계 매칭 (한 세그먼트)', () => {
    const globGroups: EditorStateGroupSpec[] = [
      { scope: { kind: 'route', match: '/admin/*/edit' }, items: [{ id: 'edit' }] },
    ];
    expect(matchStateItems(globGroups, { kind: 'route', match: '/admin/users/edit' }).map((i) => i.id)).toEqual([
      'edit',
    ]);
    // `*` 는 한 세그먼트만 — 슬래시 넘는 매칭 거부
    expect(matchStateItems(globGroups, { kind: 'route', match: '/admin/a/b/edit' })).toEqual([]);
  });

  // 라우트 접두사가 운영자 설정이라 있을 수도 없을 수도 있는 경우(engine-v1.58.0).
  // 상점 주소는 `route_path` 로 바꿀 수 있고(`/store/products`) `no_route` 를 켜면
  // 세그먼트가 아예 없다(`/products`). `*` 는 정확히 한 세그먼트라 후자를 표현하지 못해,
  // 그 상점에서는 상태 그룹이 매칭되지 않고 상태 토글이 조용히 사라졌다.
  describe('route `/*?` 선택 세그먼트 (0개 또는 1개)', () => {
    const optionalGroups: EditorStateGroupSpec[] = [
      { scope: { kind: 'route', match: '/*?/products' }, items: [{ id: 'list' }] },
    ];
    const ids = (path: string) =>
      matchStateItems(optionalGroups, { kind: 'route', match: path }).map((i) => i.id);

    /** @effects scope_route_glob_matches_one_segment_wildcard */
    it('세그먼트가 기본값이면 매칭된다', () => {
      expect(ids('/shop/products')).toEqual(['list']);
    });

    /** @effects scope_route_glob_matches_one_segment_wildcard */
    it('세그먼트를 운영자가 바꿔도 매칭된다', () => {
      expect(ids('/store/products')).toEqual(['list']);
    });

    /** @effects scope_route_glob_matches_one_segment_wildcard */
    it('세그먼트가 없으면(no_route) 슬래시까지 접혀 매칭된다', () => {
      expect(ids('/products')).toEqual(['list']);
    });

    /** @effects scope_route_glob_matches_one_segment_wildcard */
    it('세그먼트가 둘 이상이면 매칭되지 않는다', () => {
      expect(ids('/a/b/products')).toEqual([]);
    });

    /** @effects scope_route_glob_matches_one_segment_wildcard */
    it('다른 경로는 매칭되지 않는다', () => {
      expect(ids('/shop/cart')).toEqual([]);
      expect(ids('/products/1')).toEqual([]);
    });
  });

  // 회귀: `?` 를 쓰지 않는 기존 패턴은 동작이 바뀌지 않아야 한다
  // (선택 세그먼트 치환은 `/*?` 형태에만 반응한다).
  //
  // 관리자 라우트는 routes.json 이 path 자체를 `*/admin/...` 로 선언하므로 편집기가
  // 넘기는 selectedRoute.path 도 같은 문자열이다 — 이 경우는 glob 이 아니라 정확 일치로
  // 먼저 걸린다. 그 경로가 그대로인지 함께 고정한다.
  /** @effects scope_route_glob_matches_one_segment_wildcard */
  it('`?` 없는 기존 패턴은 동작이 바뀌지 않는다', () => {
    const legacy: EditorStateGroupSpec[] = [
      { scope: { kind: 'route', match: '*/admin/users' }, items: [{ id: 'users' }] },
    ];
    // 정확 일치 경로 (관리자 라우트 실제 형태)
    expect(matchStateItems(legacy, { kind: 'route', match: '*/admin/users' }).map((i) => i.id)).toEqual([
      'users',
    ]);
    // `*` 는 한 세그먼트에 대응하므로 접두사 세그먼트가 하나 있으면 glob 으로도 걸린다
    expect(matchStateItems(legacy, { kind: 'route', match: 'x/admin/users' }).map((i) => i.id)).toEqual([
      'users',
    ]);
    // 접두사 세그먼트가 없으면 여전히 미매칭 — `*` 는 0개를 허용하지 않는다
    expect(matchStateItems(legacy, { kind: 'route', match: '/admin/users' })).toEqual([]);
  });

  /** @effects scope_route_exact_matches_current_route_path */

  it('같은 scope 그룹이 둘이면 items concat (확장 네임스페이스 병합)', () => {
    const merged: EditorStateGroupSpec[] = [
      { scope: { kind: 'route', match: '/x' }, items: [{ id: 'a' }] },
      { scope: { kind: 'route', match: '/x' }, items: [{ id: 'b' }] },
    ];
    expect(matchStateItems(merged, { kind: 'route', match: '/x' }).map((i) => i.id)).toEqual(['a', 'b']);
  });

  /** @effects state_switcher_hidden_when_single_or_zero_or_unmatched */

  it('미매칭/scope null/groups 부재 → 빈 배열 (디그레이드)', () => {
    expect(matchStateItems(groups, { kind: 'route', match: '/none' })).toEqual([]);
    expect(matchStateItems(groups, null)).toEqual([]);
    expect(matchStateItems(undefined, { kind: 'route', match: '/login' })).toEqual([]);
  });

  /** @effects scope_with_no_effect_variant_is_rejected */

  it('id 없는 항목은 제외', () => {
    const bad: EditorStateGroupSpec[] = [
      { scope: { kind: 'route', match: '/x' }, items: [{ id: '' }, { id: 'ok' }] },
    ];
    expect(matchStateItems(bad, { kind: 'route', match: '/x' }).map((i) => i.id)).toEqual(['ok']);
  });
});

describe('resolveDefaultStateId — 기본 상태 결정', () => {
  /** @effects state_switcher_visible_when_two_or_more_items */
  it('default:true 인 항목 우선', () => {
    expect(resolveDefaultStateId([{ id: 'a' }, { id: 'b', default: true }, { id: 'c' }])).toBe('b');
  });

  /** @effects state_switcher_visible_when_two_or_more_items */
  it('default 없으면 첫 항목', () => {
    expect(resolveDefaultStateId([{ id: 'a' }, { id: 'b' }])).toBe('a');
  });

  /** @effects state_switcher_hidden_when_single_or_zero_or_unmatched */
  it('빈 배열 → null', () => {
    expect(resolveDefaultStateId([])).toBeNull();
  });
});

describe('resolveNextActiveStateId — scope 재도출 시 이어 갈 상태', () => {
  // 반복 항목·모달 편집에 들어갔다 나오면 scope 가 다시 도출된다. 그때마다 기본 상태로
  // 되돌리면 저작자가 고른 화면으로 돌아오지 못한다 — 고른 상태에서만 보이던 구역이
  // 다시 사라지므로, 같은 항목을 이어서 편집할 수 없다.
  const items = [{ id: 'connection_tab', default: true }, { id: 'templates_tab' }];
  const SCOPE = 'route|*/admin/plugins/sirsoft-message_bizppurio/settings';
  const OTHER_SCOPE = 'route|*/admin/plugins/sirsoft-koreaexim/settings';

  /** @effects active_state_survives_enter_iteration_item_edit */
  it('같은 scope 의 직전 선택이 새 목록에 있으면 그대로 이어 간다 (기본값으로 풀지 않는다)', () => {
    expect(resolveNextActiveStateId(items, { scopeKey: SCOPE, id: 'templates_tab' }, SCOPE)).toBe(
      'templates_tab',
    );
  });

  /** @effects active_state_survives_enter_iteration_item_edit */
  it('직전 선택이 기본 항목이어도 그대로 이어 간다', () => {
    expect(resolveNextActiveStateId(items, { scopeKey: SCOPE, id: 'connection_tab' }, SCOPE)).toBe(
      'connection_tab',
    );
  });

  /** @effects active_state_resets_to_default_when_moving_to_other_screen */
  it('직전 선택이 새 목록에 없으면(다른 화면 이동) 그 목록의 기본 상태', () => {
    expect(
      resolveNextActiveStateId(items, { scopeKey: SCOPE, id: 'some_other_screen_state' }, SCOPE),
    ).toBe('connection_tab');
  });

  // 상태 id 는 화면마다 재사용된다 — 번들 실측으로 `modal_filled` 하나가 170개 scope,
  // `validation_failed` 가 17개 scope 에 쓰인다. id 만 대조하면 A 화면에서 고른 상태가
  // 이름이 같다는 이유로 B 화면에서 되살아나, B 가 기본 상태로 열리지 않는다.
  /** @effects active_state_memory_is_scoped_to_its_screen */
  it('id 가 같아도 scope 가 다르면 되살리지 않는다 (동명 상태 오인 방지)', () => {
    expect(
      resolveNextActiveStateId(items, { scopeKey: OTHER_SCOPE, id: 'templates_tab' }, SCOPE),
    ).toBe('connection_tab');
  });

  /** @effects active_state_memory_is_scoped_to_its_screen */
  it('현재 scope 가 미상(호스트 디그레이드)이면 기억을 이어 가지 않는다', () => {
    expect(resolveNextActiveStateId(items, { scopeKey: SCOPE, id: 'templates_tab' }, null)).toBe(
      'connection_tab',
    );
  });

  /** @effects state_switcher_visible_when_two_or_more_items */
  it('직전 선택이 없으면 기본 상태', () => {
    expect(resolveNextActiveStateId(items, null, SCOPE)).toBe('connection_tab');
    expect(resolveNextActiveStateId(items, undefined, SCOPE)).toBe('connection_tab');
    expect(resolveNextActiveStateId(items, { scopeKey: SCOPE, id: null }, SCOPE)).toBe(
      'connection_tab',
    );
  });

  /** @effects state_switcher_hidden_when_single_or_zero_or_unmatched */
  it('새 목록이 비면 null (상태 없는 화면으로 이동)', () => {
    expect(resolveNextActiveStateId([], { scopeKey: SCOPE, id: 'templates_tab' }, SCOPE)).toBeNull();
  });
});

describe('stateScopeKey — scope 를 기억용 키로 접는다', () => {
  /** @effects active_state_memory_is_scoped_to_its_screen */
  it('kind 와 match 를 합쳐 키를 만들고, scope 가 없으면 null', () => {
    expect(stateScopeKey({ kind: 'route', match: '*/admin/users' })).toBe('route|*/admin/users');
    expect(stateScopeKey({ kind: 'modal', match: 'cart_added_modal' })).toBe(
      'modal|cart_added_modal',
    );
    expect(stateScopeKey(null)).toBeNull();
  });

  /** @effects active_state_memory_is_scoped_to_its_screen */
  it('kind 가 다르면 match 가 같아도 키가 갈린다', () => {
    expect(stateScopeKey({ kind: 'base', match: 'x' })).not.toBe(
      stateScopeKey({ kind: 'modal', match: 'x' }),
    );
  });
});

/**
 * 7.1.0 실측(영역 I2): 창 scope 는 창 id 만 보아, 같은 id 를 쓰는 다른 확장의 창 상태가 함께 매칭됐다(회원 목록 삭제 창에
 * 「창 내용 채움」×3·「삭제 확인」×2). 창을 연 화면(호스트 레이아웃)의 주인 확장이 낸 묶음을 우선하고, 주인이 낸 묶음이
 * 없을 때만 다른 출처 묶음을 쓴다.
 */
describe('matchStateItems — 창 상태는 호스트 레이아웃 주인의 묶음을 우선한다', () => {
  const groups = [
    { __source: { kind: 'module', id: 'sirsoft-board' }, scope: { kind: 'modal', match: 'privacy_run_modal' }, items: [{ id: 'modal_filled', label: 'board' }] },
    { __source: { kind: 'plugin', id: 'sirsoft-gdpr' }, scope: { kind: 'modal', match: 'privacy_run_modal' }, items: [{ id: 'modal_filled', label: 'gdpr' }] },
    { __source: { kind: 'template', id: 'sirsoft-admin_basic' }, scope: { kind: 'modal', match: 'privacy_run_modal' }, items: [{ id: 'modal_filled', label: 'core' }] },
    { __source: { kind: 'module', id: 'sirsoft-ecommerce' }, scope: { kind: 'modal', match: 'only_in_ecommerce_spec' }, items: [{ id: 'x', label: 'ecom' }] },
  ] as unknown as EditorStateGroupSpec[];
  const scope = { kind: 'modal' as const, match: 'privacy_run_modal' };

  /** @effects modal_states_prefer_host_owner_extension */
  it('확장 레이아웃(`sirsoft-gdpr.plugin_settings`)의 창 → 그 확장 묶음만', () => {
    expect(matchStateItems(groups, scope, 'sirsoft-gdpr.plugin_settings').map((i) => i.label)).toEqual(['gdpr']);
    expect(matchStateItems(groups, scope, 'sirsoft-board.admin_board_settings').map((i) => i.label)).toEqual(['board']);
  });

  /** @effects modal_states_prefer_template_for_template_layout */
  it('템플릿 레이아웃(`admin_settings`)의 창 → 템플릿 묶음만', () => {
    expect(matchStateItems(groups, scope, 'admin_settings').map((i) => i.label)).toEqual(['core']);
  });

  /** @effects modal_states_fall_back_when_owner_has_none */
  it('주인이 낸 묶음이 없으면 다른 출처 묶음을 쓴다(디그레이드)', () => {
    const s = { kind: 'modal' as const, match: 'only_in_ecommerce_spec' };
    expect(matchStateItems(groups, s, 'shop/cart').map((i) => i.label)).toEqual(['ecom']);
  });

  /** @effects modal_states_unchanged_without_host */
  it('호스트를 모르거나 route 범위면 종전처럼 전부 concat', () => {
    expect(matchStateItems(groups, scope).map((i) => i.label)).toEqual(['board', 'gdpr', 'core']);
    expect(matchStateItems(groups, scope, null).map((i) => i.label)).toEqual(['board', 'gdpr', 'core']);
  });
});
