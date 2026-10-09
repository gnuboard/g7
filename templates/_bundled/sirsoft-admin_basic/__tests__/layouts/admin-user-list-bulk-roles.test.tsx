/**
 * @file admin-user-list-bulk-roles.test.tsx
 * @description 회원 목록 — 역할 일괄 추가·제거 창 (#708)
 *
 * 실제 파셜 JSON(`_modal_bulk_roles.json`)을 띄워, 서버가 돌려준 결과·거부가 창에 어떻게 남는지 본다.
 * - 제외된 회원이 있으면 창을 닫지 않고 사유별 행을 보여 준다 (라이브에서는 범위 밖 회원이 목록에 안 보여 못 그린 갈래)
 * - 상한 위반(403)은 창 안 문구로 남고 선택은 그대로다
 * - 같은 틱 연타는 300ms 디바운스로 한 번만 보낸다
 */

import React from 'react';
import fs from 'fs';
import path from 'path';
import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { actionsOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { createLayoutTest, screen, fireEvent, waitFor } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { ComponentRegistry } from '@core/template-engine/ComponentRegistry';

const read = (relative: string) => JSON.parse(fs.readFileSync(path.resolve(__dirname, relative), 'utf-8'));

const listLayout = read('../../layouts/admin_user_list.json');
const modalPartial = read('../../layouts/partials/admin_user_list/_modal_bulk_roles.json');
const adminKo = read('../../lang/partial/ko/admin.json');
const adminEn = read('../../lang/partial/en/admin.json');
const adminJa = read('../../../../../lang-packs/_bundled/g7-template-sirsoft-admin_basic-ja/frontend/partial/admin.json');

const SELECTED = ['uuid-u1', 'uuid-u2', 'uuid-u3'];
const USERS = [
  { uuid: 'uuid-u1', name: '회원 하나' },
  { uuid: 'uuid-u2', name: '회원 둘' },
  { uuid: 'uuid-u3', name: '회원 셋' },
];

const TestDiv: React.FC<{ className?: string; id?: string; children?: React.ReactNode }> = ({ className, id, children }) => (
  <div className={className} data-testid={id}>{children}</div>
);
const TestText: React.FC<{ children?: React.ReactNode; text?: string }> = ({ children, text }) => <span>{children || text}</span>;
const TestButton: React.FC<{ id?: string; disabled?: boolean; onClick?: () => void; children?: React.ReactNode }> = ({ id, disabled, onClick, children }) => (
  <button data-testid={id} disabled={disabled} onClick={onClick}>{children}</button>
);
const TestIcon: React.FC<{ name?: string }> = ({ name }) => <i data-icon={name} />;
const TestTagInput: React.FC<{ id?: string; value?: any[] }> = ({ id, value }) => (
  <div data-testid={id} data-value={JSON.stringify(value ?? [])} />
);
const TestModal: React.FC<{ id?: string; children?: React.ReactNode }> = ({ id, children }) => (
  <div data-testid={`modal-${id}`} role="dialog">{children}</div>
);

function setupTestRegistry(): ComponentRegistry {
  const registry = ComponentRegistry.getInstance();
  const basic = (name: string, component: React.FC<any>) => ({ component, metadata: { name, type: 'basic' } });

  (registry as any).registry = {
    Div: basic('Div', TestDiv),
    P: basic('P', TestText),
    Span: basic('Span', TestText),
    Label: basic('Label', TestText),
    H4: basic('H4', TestText),
    Ul: basic('Ul', TestDiv),
    Li: basic('Li', TestDiv),
    Icon: basic('Icon', TestIcon),
    Button: basic('Button', TestButton),
    TagInput: { component: TestTagInput, metadata: { name: 'TagInput', type: 'composite' } },
    Modal: { component: TestModal, metadata: { name: 'Modal', type: 'composite' } },
    Fragment: { component: ({ children }: { children?: React.ReactNode }) => <>{children}</>, metadata: { name: 'Fragment', type: 'layout' } },
  };

  return registry;
}

/** 부분 레이아웃 트리에서 조건에 맞는 노드를 모두 모은다. */
function collect(node: any, match: (n: any) => boolean, found: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, found));
    return found;
  }
  if (node && typeof node === 'object') {
    if (match(node)) found.push(node);
    Object.values(node).forEach((value) => {
      if (value && typeof value === 'object') collect(value, match, found);
    });
  }
  return found;
}

/** `{{ ... }}` 식 하나를 주어진 문맥으로 평가한다 (엔진과 같은 JS 식). */
function evaluate(expression: string, context: Record<string, any>): any {
  const body = expression.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(context), `return (${body});`)(...Object.values(context));
}

function buildLayout(global: Record<string, any>) {
  return {
    version: '1.0.0',
    layout_name: 'admin_user_list_bulk_roles_test',
    data_sources: [
      { id: 'users', type: 'api', endpoint: '/api/admin/users', method: 'GET', auth_required: true, fallback: { data: { data: [] } } },
    ],
    initGlobal: global,
    modals: [modalPartial],
    components: [{ id: 'main-content', type: 'basic', name: 'Div', children: [] }],
  };
}

const translations = { admin: adminKo, common: { cancel: '취소', processing: '처리 중...' } };

describe('회원 목록 — 역할 일괄 변경 창 (#708)', () => {
  let registry: ComponentRegistry;
  let testUtils: ReturnType<typeof createLayoutTest> | undefined;

  beforeEach(() => {
    registry = setupTestRegistry();
  });

  afterEach(() => {
    testUtils?.cleanup();
    testUtils = undefined;
  });

  // @scenario mode=add, target=normal, role=within_ceiling
  // @effects bulk_role_entry_from_pc_and_mobile
  it('PC 일괄 바의 「역할 추가」「역할 제거」 는 고른 회원이 없으면 잠기고, 누르면 모드를 정하고 지난 입력을 비운 뒤 창을 연다', () => {
    const expected = { add: 'bulk_role_add', remove: 'bulk_role_remove' } as const;

    for (const [mode, id] of Object.entries(expected)) {
      const button = collect(listLayout, (n) => n.id === id)[0];
      const disabled = (selected?: string[]) =>
        evaluate(button.props.disabled, { _global: { selectedIds_admin_users: selected } });

      expect(disabled(undefined)).toBe(true);
      expect(disabled([])).toBe(true);
      expect(disabled(SELECTED)).toBe(false);

      const click = actionsOf(button).find((a: any) => a.type === 'click');
      const set = collect(click, (n) => n.handler === 'setState')[0];
      const open = collect(click, (n) => n.handler === 'openModal')[0];
      expect(set.params).toMatchObject({
        target: 'global',
        userBulkRoleMode: mode,
        userBulkRoleIds: [],
        userBulkRoleResult: null,
        userBulkRoleError: null,
      });
      expect(open.target).toBe('bulk_roles_modal');
    }
  });

  // @scenario mode=remove, target=normal, role=within_ceiling
  // @effects bulk_role_entry_from_pc_and_mobile
  it('모바일 일괄 드롭다운에도 「역할 추가」「역할 제거」 가 있고, 고르면 PC 버튼과 같은 상태로 같은 창을 연다', () => {
    const dropdown = collect(listLayout, (n) => n.id === 'mobile_bulk_action_dropdown')[0];
    const values = dropdown.props.items.map((item: any) => item.value);
    expect(values).toEqual(expect.arrayContaining(['role_add', 'role_remove']));

    const onItem = actionsOf(dropdown).find((a: any) => a.event === 'onItemClick');
    expect(onItem.handler).toBe('switch');

    for (const [value, mode, buttonId] of [['role_add', 'add', 'bulk_role_add'], ['role_remove', 'remove', 'bulk_role_remove']]) {
      const pcClick = actionsOf(collect(listLayout, (n) => n.id === buttonId)[0]).find((a: any) => a.type === 'click');
      const mobileCase = onItem.cases[value];
      const set = collect(mobileCase, (n) => n.handler === 'setState')[0];

      expect(set.params.userBulkRoleMode).toBe(mode);
      expect(collect(mobileCase, (n) => n.handler === 'openModal')[0].target).toBe('bulk_roles_modal');
      expect(set.params).toEqual(collect(pcClick, (n) => n.handler === 'setState')[0].params);
    }
  });

  // @scenario mode=add, target=normal, role=within_ceiling
  // @effects assigned_by_recorded
  it('요청 본문은 고른 회원 uuid·역할·모드를 그대로 싣고, 연타는 300ms 디바운스로 모은다', () => {
    const apply = collect(modalPartial, (n) => n.id === 'bulk_roles_apply')[0];
    const click = actionsOf(apply).find((a: any) => a.type === 'click');
    const call = collect(click, (n) => n.handler === 'apiCall')[0];

    expect(click.handler).toBe('sequence');
    expect(click.debounce).toBe(300);
    expect(call.target).toBe('/api/admin/users/bulk-roles');
    expect(call.params.method).toBe('PATCH');
    expect(call.params.body).toEqual({
      uuids: '{{_global.selectedIds_admin_users ?? []}}',
      role_ids: '{{_global.userBulkRoleIds ?? []}}',
      mode: "{{_global.userBulkRoleMode ?? 'add'}}",
    });
  });

  // @scenario mode=add, target=super_admin, role=within_ceiling
  // @effects grade_filtered
  it('제외된 회원이 있으면 결과 카드에 사유별 행이 남고 역할 칸·적용 버튼은 숨는다', async () => {
    testUtils = createLayoutTest(buildLayout({
      userBulkRoleMode: 'add',
      selectedIds_admin_users: [],
      userBulkRoleResult: {
        updated_count: 0,
        skipped_count: 3,
        skipped: [
          { uuid: 'uuid-u1', reason: 'grade' },
          { uuid: 'uuid-u2', reason: 'scope' },
          { uuid: 'uuid-u3', reason: 'last_admin' },
        ],
      },
    }), { translations, locale: 'ko', componentRegistry: registry, includeModals: true });
    testUtils.mockApi('users', { response: { success: true, data: { data: USERS } } });

    await testUtils.render();

    const result = await screen.findByTestId('bulk_roles_result');
    expect(result).toBeTruthy();
    expect(result.textContent).toContain('회원 하나');
    expect(result.textContent).toContain(adminKo.users.bulk_roles.reason_grade);
    expect(result.textContent).toContain(adminKo.users.bulk_roles.reason_scope);
    expect(result.textContent).toContain(adminKo.users.bulk_roles.reason_last_admin);
    expect(screen.queryByTestId('bulk_roles_field')).toBeNull();
    expect(screen.queryByTestId('bulk_roles_apply')).toBeNull();
  });

  // @scenario mode=remove, target=normal, role=within_ceiling
  // @effects last_role_skipped
  it('제거로 역할이 하나도 남지 않아 건너뛴 회원은 「마지막 관리자」 가 아니라 그 사유로 보인다', async () => {
    testUtils = createLayoutTest(buildLayout({
      userBulkRoleMode: 'remove',
      selectedIds_admin_users: [],
      userBulkRoleResult: {
        updated_count: 1,
        skipped_count: 1,
        skipped: [{ uuid: 'uuid-u2', reason: 'last_role' }],
      },
    }), { translations, locale: 'ko', componentRegistry: registry, includeModals: true });
    testUtils.mockApi('users', { response: { success: true, data: { data: USERS } } });

    await testUtils.render();

    const result = await screen.findByTestId('bulk_roles_result');
    expect(result.textContent).toContain('회원 둘');
    expect(result.textContent).toContain(adminKo.users.bulk_roles.reason_last_role);
    expect(result.textContent).not.toContain(adminKo.users.bulk_roles.reason_last_admin);
    expect(adminEn.users.bulk_roles.reason_last_role).toBeTruthy();
  });

  // @scenario mode=add, target=out_of_scope, role=within_ceiling
  // @effects scope_filtered
  it('성공 응답에 제외가 있으면 선택은 비우되 창은 닫지 않고, 제외가 없으면 닫는다', async () => {
    const apply = collect(modalPartial, (n) => n.id === 'bulk_roles_apply')[0];
    const success = collect(apply, (n) => n.handler === 'apiCall')[0].onSuccess;
    const close = collect(success, (n) => n.handler === 'closeModal')[0];
    const write = collect(success, (n) => n.handler === 'setState')[0];

    // 제외도, 이미 반영되어 변경 없는 회원도 없을 때만 닫는다 — 둘 중 하나라도 있으면 결과 카드로 말한다.
    expect(close.if).toBe('{{(response.data?.skipped_count ?? 0) === 0 && (response.data?.unchanged_count ?? 0) === 0}}');
    expect(write.params.selectedIds_admin_users).toEqual([]);
    expect(write.params.userBulkRoleResult).toBe('{{response.data}}');
    expect(collect(success, (n) => n.handler === 'refetchDataSource')[0].params.dataSourceId).toBe('users');
  });

  // 창 닫기가 목록 재요청 뒤에 있으면, 재요청을 기다리는 동안 닫힐 창에 결과 카드와 「0명에게…」 안내가 비친다
  // (라이브 실측: 제외 0명인 추가에서 결과 카드 「2명 변경, 0명 제외」 와 「선택한 회원 0명에게…」 가 잠깐 보였다).
  // @scenario mode=add, target=normal, role=within_ceiling
  // @effects bulk_role_result_hides_zero_count_desc
  it('제외가 없으면 목록 재요청보다 창 닫기가 먼저다', () => {
    const apply = collect(modalPartial, (n) => n.id === 'bulk_roles_apply')[0];
    const success = collect(apply, (n) => n.handler === 'apiCall')[0].onSuccess;
    const steps = collect(success, (n) => n.handler === 'sequence')[0].actions.map((a: any) => a.handler);

    expect(steps.indexOf('closeModal')).toBeGreaterThan(-1);
    expect(steps.indexOf('closeModal')).toBeLessThan(steps.indexOf('refetchDataSource'));
  });

  // 적용하면 선택이 비어 안내 문구의 인원이 0 으로 바뀐다 — 결과 화면에서는 안내를 숨기고 결과 카드가 말한다.
  // @scenario mode=add, target=super_admin, role=within_ceiling
  // @effects bulk_role_result_hides_zero_count_desc
  it('결과 화면에서는 「선택한 회원 N명에게…」 안내를 숨긴다 (추가·제거 모두)', async () => {
    for (const mode of ['add', 'remove'] as const) {
      testUtils = createLayoutTest(buildLayout({
        userBulkRoleMode: mode,
        selectedIds_admin_users: [],
        userBulkRoleResult: { updated_count: 1, skipped_count: 1, skipped: [{ uuid: 'uuid-u1', reason: 'grade' }] },
      }), { translations, locale: 'ko', componentRegistry: registry, includeModals: true });
      testUtils.mockApi('users', { response: { success: true, data: { data: USERS } } });

      await testUtils.render();

      await screen.findByTestId('bulk_roles_result');
      const desc = mode === 'add' ? adminKo.users.bulk_roles.add_desc : adminKo.users.bulk_roles.remove_desc;
      expect(screen.queryByText(desc.replace('{{count}}', '0'))).toBeNull();

      testUtils.cleanup();
      testUtils = undefined;
    }
  });

  // @scenario mode=add, target=normal, role=above_ceiling
  // @effects ceiling_violation_rejects_request
  it('상한 위반 403 은 창 안 문구로 남고 선택은 그대로다', async () => {
    testUtils = createLayoutTest(buildLayout({
      userBulkRoleMode: 'add',
      selectedIds_admin_users: SELECTED,
      userBulkRoleIds: [1],
      isProcessing: false,
    }), { translations, locale: 'ko', componentRegistry: registry, includeModals: true });
    // 모킹 열쇠는 URL 의 세 번째 마디다 — /api/admin/users/bulk-roles 도 'users' 로 잡힌다. 목록 데이터소스는 오류 모킹이면 fallback 으로 그린다.
    testUtils.mockApiError('users', 403, '내가 부여할 수 없는 권한이 담긴 역할이 포함되어 있어 처리하지 않았습니다.');

    await testUtils.render();

    const button = await screen.findByTestId('bulk_roles_apply');
    expect(button).toBeTruthy();
    fireEvent.click(button);

    await waitFor(() => {
      expect(testUtils!.getState()._global.userBulkRoleError).toBe('내가 부여할 수 없는 권한이 담긴 역할이 포함되어 있어 처리하지 않았습니다.');
    });
    const state = testUtils.getState()._global;
    expect(state.isProcessing).toBe(false);
    expect(state.selectedIds_admin_users).toEqual(SELECTED);
    expect(state.userBulkRoleResult ?? null).toBeNull();
  });

  // @scenario mode=remove, target=self_last_admin, role=within_ceiling
  // @effects last_admin_self_skipped
  it('제거 모드면 제거 안내 문구를 보이고, 안내는 상한 위반이 「요청 전체 거부」 임을 말한다 (ko·en)', async () => {
    testUtils = createLayoutTest(buildLayout({
      userBulkRoleMode: 'remove',
      selectedIds_admin_users: SELECTED,
    }), { translations, locale: 'ko', componentRegistry: registry, includeModals: true });
    testUtils.mockApi('users', { response: { success: true, data: { data: USERS } } });

    await testUtils.render();

    const hint = await screen.findByTestId('bulk_roles_hint');
    expect(hint.textContent).toBe(adminKo.users.bulk_roles.hint);
    expect(screen.getByText(adminKo.users.bulk_roles.remove_desc.replace('{{count}}', '3'))).toBeTruthy();

    // 라이브 실측: 상한 밖 역할도 선택지에 보이고, 고르면 요청이 403 으로 거부된다 — 「고를 수 없다」 고 말하면 안 된다.
    expect(adminKo.users.bulk_roles.hint).not.toContain('고를 수 없습니다');
    expect(adminKo.users.bulk_roles.hint).toContain('거부');
    expect(adminEn.users.bulk_roles.hint).toContain('rejected');
  });

  // 라이브 실측(O-C3): 4명을 골라 역할을 추가했는데 결과가 「2명 변경, 1명 제외」 — 이미 그 역할을 가진 1명이 어디에도 안 잡혔다.
  // @scenario mode=add, target=normal, role=within_ceiling
  // @effects unchanged_reported
  it('이미 반영되어 변경 없는 회원은 결과 요약에 수가 실리고 「이미 반영」 사유 행으로 보인다 (ko·en·ja 키)', async () => {
    testUtils = createLayoutTest(buildLayout({
      userBulkRoleMode: 'add',
      selectedIds_admin_users: [],
      userBulkRoleResult: {
        updated_count: 1,
        skipped_count: 1,
        skipped: [{ uuid: 'uuid-u1', reason: 'grade' }],
        unchanged_count: 1,
        unchanged: [{ uuid: 'uuid-u3', reason: 'already_assigned' }],
      },
    }), { translations, locale: 'ko', componentRegistry: registry, includeModals: true });
    testUtils.mockApi('users', { response: { success: true, data: { data: USERS } } });

    await testUtils.render();

    const result = await screen.findByTestId('bulk_roles_result');
    expect(result.textContent).toContain(adminKo.users.bulk_roles.result_unchanged.replace('{{unchanged}}', '1'));
    const unchanged = await screen.findByTestId('bulk_roles_unchanged');
    expect(unchanged.textContent).toContain('회원 셋');
    expect(unchanged.textContent).toContain(adminKo.users.bulk_roles.reason_already_assigned);

    for (const pack of [adminKo, adminEn, adminJa]) {
      expect(pack.users.bulk_roles.result_unchanged).toContain('{{unchanged}}');
      expect(pack.users.bulk_roles.reason_already_assigned).toBeTruthy();
      expect(pack.users.bulk_roles.reason_not_assigned).toBeTruthy();
    }
  });

  // @scenario mode=add, target=normal, role=within_ceiling
  // @effects unchanged_reported
  it('변경 없는 회원이 0명이면 요약 줄·행을 그리지 않는다', async () => {
    testUtils = createLayoutTest(buildLayout({
      userBulkRoleMode: 'add',
      selectedIds_admin_users: [],
      userBulkRoleResult: { updated_count: 1, skipped_count: 1, skipped: [{ uuid: 'uuid-u1', reason: 'grade' }], unchanged_count: 0, unchanged: [] },
    }), { translations, locale: 'ko', componentRegistry: registry, includeModals: true });
    testUtils.mockApi('users', { response: { success: true, data: { data: USERS } } });

    await testUtils.render();

    await screen.findByTestId('bulk_roles_result');
    expect(screen.queryByTestId('bulk_roles_unchanged')).toBeNull();
  });

  // 라이브 실측(O-C6): 제거 창 안내에 「역할이 남지 않는 회원은 건너뜀」 이 없어 결과 창에서야 알았다.
  // @scenario mode=remove, target=normal, role=within_ceiling
  // @effects last_role_skipped
  it('제거 모드에만 「역할이 하나도 남지 않는 회원은 건너뜀」 안내가 보인다 (ko·en·ja)', async () => {
    for (const mode of ['remove', 'add'] as const) {
      testUtils = createLayoutTest(buildLayout({
        userBulkRoleMode: mode,
        selectedIds_admin_users: SELECTED,
      }), { translations, locale: 'ko', componentRegistry: registry, includeModals: true });
      testUtils.mockApi('users', { response: { success: true, data: { data: USERS } } });

      await testUtils.render();
      await screen.findByTestId('bulk_roles_hint');

      const removeHint = screen.queryByTestId('bulk_roles_remove_hint');
      if (mode === 'remove') {
        expect(removeHint?.textContent).toBe(adminKo.users.bulk_roles.remove_hint);
      } else {
        expect(removeHint).toBeNull();
      }

      testUtils.cleanup();
      testUtils = undefined;
    }

    expect(adminKo.users.bulk_roles.remove_hint).toContain('역할이 하나도 남지 않');
    expect(adminEn.users.bulk_roles.remove_hint).toMatch(/no role/i);
    expect(adminJa.users.bulk_roles.remove_hint).toBeTruthy();
  });
});
