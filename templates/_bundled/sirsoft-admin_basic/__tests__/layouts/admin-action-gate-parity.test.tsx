/**
 * @file admin-action-gate-parity.test.tsx
 * @description 관리자 화면의 버튼 활성 조건과 서버 수락 조건의 일치 (#703)
 *
 * 화면이 누를 수 있게 둔 조작을 서버가 422 로 거절하면 운영자는 이유 모를 실패만 받는다.
 * 각 단언은 레이아웃이 켜고 끄는 식을 실제 값으로 평가해, 서버가 거절할 상태에서 조작이
 * 막히는지(그리고 서버가 받는 상태에서는 열려 있는지)를 고정한다.
 *
 * 대상:
 * - admin_role_list — 보호 역할 행 메뉴·토글 잠금(서버 abilities)과 안내
 * - admin_user_list — 선택 0건 일괄 처리(모바일 드롭다운·확인 창), 본인 행 선택, 일괄 결과 안내
 * - admin_user_form — 역할 부여 권한 없는 등록(역할 칸 필수 표시·안내)
 * - _modal_notification_identity_definition_add — 본인인증 정책별 문구 추가: 운영자 정책 0건·이미 문구가 있는 정책
 * - 회원 검색 드롭다운 2곳 — 빈 검색어 요청
 */

import { describe, it, expect } from 'vitest';
import roleListLayout from '../../layouts/admin_role_list.json';
import userListLayout from '../../layouts/admin_user_list.json';
import userFormLayout from '../../layouts/admin_user_form.json';
import identityNotificationAddModal from '../../layouts/partials/admin_settings/_modal_notification_identity_definition_add.json';
import notificationDefinitionsTab from '../../layouts/partials/admin_settings/_tab_notification_definitions.json';
import adminSettingsLayout from '../../layouts/admin_settings.json';
import activityLogFilter from '../../layouts/partials/admin_activity_log_list/_partial_filter.json';
import notificationTemplateForm from '../../layouts/partials/admin_settings/_modal_notification_template_form.json';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';

type Json = Record<string, any>;

/** 트리 전체를 돌며 조건에 맞는 노드를 모두 모은다. */
function collect(node: unknown, match: (n: Json) => boolean): Json[] {
  const out: Json[] = [];
  const walk = (n: unknown): void => {
    if (Array.isArray(n)) {
      n.forEach(walk);
      return;
    }
    if (n && typeof n === 'object') {
      const obj = n as Json;
      if (match(obj)) out.push(obj);
      Object.values(obj).forEach(walk);
    }
  };
  walk(node);
  return out;
}

/** id 로 노드 하나를 찾는다. */
function byId(node: unknown, id: string): Json {
  const found = collect(node, (n) => n.id === id)[0];
  expect(found, `노드 ${id} 가 없습니다.`).toBeDefined();
  return found;
}

/**
 * `{{ ... }}` 바인딩 식을 주어진 문맥으로 평가한다.
 *
 * 엔진과 같은 JS 식이므로 문맥 변수만 넘겨 그대로 계산한다(이 파일의 단언은 식의 결과만 본다).
 */
function evaluate(binding: string, ctx: Record<string, unknown>): any {
  const m = /^\{\{([\s\S]*)\}\}$/.exec(String(binding).trim());
  expect(m, `바인딩 식이 아닙니다: ${binding}`).not.toBeNull();
  const keys = Object.keys(ctx);
  // eslint-disable-next-line @typescript-eslint/no-implied-eval, no-new-func
  const fn = new Function(...keys, `return (${m![1]});`);
  return fn(...keys.map((k) => ctx[k]));
}

describe('역할 목록 — 보호 역할', () => {
  /**
   * 잠금은 서버가 행마다 실어 준 abilities 로만 한다(서버 가드와 같은 판정) — 화면이
   * 이름·종류로 따로 판정하면 서버와 갈린다. 잠긴 이유는 안내 문구로 알린다.
   *
   * @scenario defect=role_delete_protected
   * @effects role_row_actions_follow_server_abilities_with_hint
   */
  it('행 메뉴·토글은 서버 abilities 를 읽고, 보호 역할 안내가 ko·en 으로 보인다', () => {
    const grid = byId(roleListLayout, 'roles_data_grid');
    const actions: Json[] = grid.props.rowActions;
    expect(actions.find((a) => a.id === 'delete')!.disabledField).toBe('abilities.can_delete');
    expect(actions.find((a) => a.id === 'edit')!.disabledField).toBe('abilities.can_update');

    const toggle = collect(grid, (n) => n.name === 'Toggle')[0];
    expect(evaluate(toggle.props.disabled, { row: { abilities: { can_toggle_status: false } } })).toBe(true);
    expect(evaluate(toggle.props.disabled, { row: { abilities: { can_toggle_status: true } } })).toBe(false);

    expect(byId(roleListLayout, 'protected_roles_hint').text).toBe('$t:admin.roles.protected_roles_hint');
    for (const dict of [adminKo, adminEn] as unknown as Json[]) {
      expect(dict.roles.protected_roles_hint).toBeTruthy();
    }
  });
});

describe('회원 목록 — 선택 0건 일괄 처리', () => {
  /**
   * @scenario defect=bulk_status_empty_selection
   * @effects bulk_actions_disabled_without_selection
   */
  it('모바일 일괄 처리 드롭다운 항목은 선택이 없으면 비활성이다', () => {
    const dropdown = byId(userListLayout, 'mobile_bulk_action_dropdown');
    const items: Json[] = dropdown.props.items;
    // 상태 4종 + 역할 일괄 추가·제거 — 새 항목도 같은 잠금 규칙을 따라야 한다.
    expect(items.map((i) => i.value)).toEqual(['activate', 'deactivate', 'block', 'withdraw', 'role_add', 'role_remove']);

    for (const item of items) {
      expect(item.disabled, `${item.value} 항목에 비활성 조건이 없습니다.`).toBeDefined();
      expect(evaluate(item.disabled, { _global: { selectedIds_admin_users: [] } })).toBe(true);
      expect(evaluate(item.disabled, { _global: {} })).toBe(true);
      expect(evaluate(item.disabled, { _global: { selectedIds_admin_users: ['u-1'] } })).toBe(false);
    }
  });

  /**
   * @scenario defect=bulk_status_empty_selection
   * @effects bulk_actions_disabled_without_selection
   */
  it('일괄 확인 창 4개의 실행 버튼은 선택이 없으면 비활성이다', () => {
    for (const kind of ['activate', 'deactivate', 'block', 'withdraw']) {
      const button = byId(userListLayout, `bulk_${kind}_submit_button`);
      expect(evaluate(button.props.disabled, { _global: { selectedIds_admin_users: [] } }), kind).toBe(true);
      expect(evaluate(button.props.disabled, { _global: { selectedIds_admin_users: ['u-1'], isProcessing: false } }), kind).toBe(false);
      expect(evaluate(button.props.disabled, { _global: { selectedIds_admin_users: ['u-1'], isProcessing: true } }), kind).toBe(true);
    }
  });
});

describe('회원 목록 — 본인 행', () => {
  /**
   * @scenario defect=bulk_status_self_included
   * @effects self_row_checkbox_disabled
   */
  it('표는 로그인한 본인의 행을 선택할 수 없게 한다', () => {
    const grid = byId(userListLayout, 'users_data_grid');
    expect(grid.props.nonSelectableIds, '본인 행 선택 차단 바인딩이 없습니다.').toBeDefined();
    expect(evaluate(grid.props.nonSelectableIds, { current_user: { data: { uuid: 'me-uuid' } } })).toEqual(['me-uuid']);
    expect(evaluate(grid.props.nonSelectableIds, { current_user: undefined })).toEqual([]);
  });
});

describe('회원 목록 — 일괄 결과 안내', () => {
  /**
   * @scenario defect=bulk_status_partial_result
   * @effects bulk_status_toast_reflects_partial_result
   */
  it('활성·비활성·차단 결과 안내가 failed_count 에 따라 성공·부분·전건 실패로 갈린다', () => {
    const t = (s: string) => s;
    for (const [status, successKey] of [
      ['active', 'bulk_activate_success'],
      ['inactive', 'bulk_deactivate_success'],
      ['blocked', 'bulk_block_success'],
    ]) {
      const call = collect(userListLayout, (n) => n.handler === 'apiCall' && String(n.target).includes('bulk-status') && n.params?.body?.status === status)[0];
      expect(call, status).toBeDefined();
      const toast = (call.onSuccess as Json[]).find((a) => a.handler === 'toast')!;

      const ok = { response: { data: { updated_count: 2, failed_count: 0, failed_reasons: [] } }, t };
      const partial = { response: { data: { updated_count: 1, failed_count: 1, failed_reasons: ['사유'] } }, t };
      const none = { response: { data: { updated_count: 0, failed_count: 2, failed_reasons: ['사유'] } }, t };

      expect(evaluate(toast.params.type, ok)).toBe('success');
      expect(evaluate(toast.params.message, ok)).toBe(`$t:admin.users.modals.${successKey}`);
      expect(evaluate(toast.params.type, partial)).toBe('warning');
      expect(evaluate(toast.params.message, partial)).toContain('$t:admin.users.modals.bulk_status_partial');
      expect(evaluate(toast.params.type, none)).toBe('error');
      expect(evaluate(toast.params.message, none)).toContain('$t:admin.users.modals.bulk_status_all_failed');
    }
  });

  /**
   * @scenario defect=bulk_status_partial_result
   * @effects bulk_status_toast_reflects_partial_result
   */
  it('부분·전건 실패 문구가 ko·en 에 있고 사유 자리를 갖는다', () => {
    for (const dict of [adminKo, adminEn] as unknown as Json[]) {
      expect(String(dict.users.modals.bulk_status_partial)).toContain('{reason}');
      expect(String(dict.users.modals.bulk_status_partial)).toContain('{failed}');
      expect(String(dict.users.modals.bulk_status_all_failed)).toContain('{reason}');
    }
  });
});

describe('회원 등록 — 역할 부여 권한 없이', () => {
  /**
   * @scenario defect=user_create_without_role_assign
   * @effects create_form_marks_roles_optional_with_default_hint
   */
  it('등록 모드에서는 역할 칸을 필수로 표시하지 않고 기본 역할 안내를 보인다', () => {
    const field = byId(userFormLayout, 'field_roles');
    const serialized = JSON.stringify(field);

    const required = collect(field, (n) => n.name === 'Span' && n.text === '*')[0];
    expect(required, '필수 표시(*)가 없습니다.').toBeDefined();
    expect(evaluate(required.if, { route: {} }), '등록 모드에서 필수 표시가 보입니다.').toBe(false);
    expect(evaluate(required.if, { route: { id: 'u-1' } })).toBe(true);

    expect(serialized).toContain('$t:admin.users.form.fields.role_default_hint');
    for (const dict of [adminKo, adminEn] as unknown as Json[]) {
      expect(dict.users.form.fields.role_default_hint).toBeTruthy();
    }
  });
});

describe('본인인증 정책별 문구 추가 (알림 설정 > 본인인증)', () => {
  const ctxBase = (overrides: Record<string, unknown> = {}) => ({
    adminIdentityPolicies: { data: { data: [{ key: 'policy_a' }, { key: 'policy_b' }] } },
    identityPolicyDefinitions: { data: { data: [{ type: 'identity.policy.policy_a', policy_key: 'policy_a' }] } },
    _global: { identity_notification_add_modal: { form: { policy_key: '' } } },
    ...overrides,
  });

  /**
   * 서버는 같은 정책에 두 번째 정의를 중복(422)으로 거절한다 — 판정은 type `identity.policy.{정책 키}` 다.
   *
   * @scenario defect=identity_notification_definition_add
   * @effects defined_policies_excluded_from_options
   */
  it('이미 문구가 있는 정책은 선택지에서 빠진다', () => {
    const select = collect(identityNotificationAddModal, (n) => n.name === 'Select')[0];
    expect(evaluate(select.props.options, ctxBase())).toEqual([{ value: 'policy_b', label: 'policy_b' }]);
    // 고를 정책이 하나도 없으면 선택 칸 대신 안내가 뜬다
    expect(evaluate(select.if, ctxBase({ identityPolicyDefinitions: { data: { data: [{ policy_key: 'policy_a' }, { policy_key: 'policy_b' }] } } }))).toBe(false);
    // 100자를 넘는 키는 type 이 해시(`identity.policy.~…`)라 type 으로는 못 맞춘다 — 서버가 실어 주는 policy_key 로 뺀다
    const longKey = 'admin.' + 'x'.repeat(90);
    expect(
      evaluate(select.props.options, ctxBase({
        adminIdentityPolicies: { data: { data: [{ key: longKey }, { key: 'policy_b' }] } },
        identityPolicyDefinitions: { data: { data: [{ type: 'identity.policy.~0123456789abcdef0123', policy_key: longKey }] } },
      })),
    ).toEqual([{ value: 'policy_b', label: 'policy_b' }]);
  });

  /**
   * @scenario defect=identity_notification_definition_add
   * @effects save_disabled_without_selectable_policy
   */
  it('고를 정책이 없거나 고르지 않았으면 저장이 비활성이다', () => {
    const save = byId(identityNotificationAddModal, 'identity_notification_add_save_button');
    const pick = (key: string) => ({ _global: { identity_notification_add_modal: { form: { policy_key: key } } } });

    // 고르지 않음
    expect(evaluate(save.props.disabled, ctxBase())).toBe(true);
    // 운영자 정책 0건
    expect(evaluate(save.props.disabled, ctxBase({ adminIdentityPolicies: { data: { data: [] } } }))).toBe(true);
    // 이미 문구가 있는 정책을 고른 채 남아 있음
    expect(evaluate(save.props.disabled, ctxBase(pick('policy_a')))).toBe(true);
    // 정상 선택
    expect(evaluate(save.props.disabled, ctxBase(pick('policy_b')))).toBe(false);
    // 저장 중에는 다시 누를 수 없다
    expect(
      evaluate(save.props.disabled, ctxBase({ _global: { identity_notification_add_modal: { form: { policy_key: 'policy_b' }, isSaving: true } } })),
    ).toBe(true);
  });

  /**
   * @scenario defect=identity_notification_definition_add
   * @effects defined_policies_excluded_from_options
   */
  it('문구가 있는 정책 목록은 서버의 중복 판정과 같은 조건(코어·본인인증 분류)으로 받고, 창을 열 때와 저장 뒤 다시 받는다', () => {
    const source = collect(adminSettingsLayout, (n) => n.id === 'identityPolicyDefinitions' && n.endpoint)[0];
    expect(source, '문구가 있는 정책 목록 데이터소스가 없습니다.').toBeDefined();
    expect(source.endpoint).toBe('/api/admin/notification-definitions');
    expect(source.params.extension_type).toBe('core');
    expect(source.params.category).toBe('identity');
    // 목록 화면의 분류·페이지와 묶이면 다른 페이지의 정의가 빠진다 — 한 번에 받는다
    expect(source.params.page).toBeUndefined();

    const openButton = byId(notificationDefinitionsTab, 'notif_identity_add_button');
    const onOpen = collect(openButton, (n) => n.handler === 'refetchDataSource' && n.params?.dataSourceId === 'identityPolicyDefinitions');
    expect(onOpen.length, '창을 열 때 다시 받는 동작이 없습니다.').toBeGreaterThan(0);

    const afterSave = collect(identityNotificationAddModal, (n) => n.handler === 'refetchDataSource' && n.params?.dataSourceId === 'identityPolicyDefinitions');
    expect(afterSave.length, '저장 뒤 다시 받는 동작이 없습니다.').toBeGreaterThan(0);
  });
});

describe('회원 검색 드롭다운 — 빈 검색어', () => {
  /**
   * @scenario defect=user_search_empty_keyword
   * @effects empty_keyword_search_not_sent
   */
  it('활동 로그 행위자·알림 수신자 검색은 검색어가 비면 요청하지 않는다', () => {
    for (const [name, tree] of [
      ['activity_log_filter', activityLogFilter],
      ['notification_template_form', notificationTemplateForm],
    ] as const) {
      const calls = collect(tree, (n) => n.handler === 'apiCall' && String(n.target).includes('/api/admin/users/search'));
      expect(calls.length, name).toBeGreaterThan(0);
      for (const call of calls) {
        expect(call.if, `${name}: 빈 검색어 차단 조건이 없습니다.`).toBeDefined();
        expect(evaluate(call.if, { $args: [''] })).toBe(false);
        expect(evaluate(call.if, { $args: ['   '] })).toBe(false);
        expect(evaluate(call.if, { $args: [undefined] })).toBe(false);
        expect(evaluate(call.if, { $args: ['홍'] })).toBe(true);
      }
    }
  });
});
