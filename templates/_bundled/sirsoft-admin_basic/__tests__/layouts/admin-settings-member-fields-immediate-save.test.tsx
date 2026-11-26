/**
 * @file admin-settings-member-fields-immediate-save.test.tsx
 * @description 환경설정 「회원」 탭 가입 항목 표의 즉시 저장 배선
 *
 * 표의 스위치와 순서는 누르자마자 항목 API 로 나가고 `memberFieldDefs` 만 다시 받는다. 설정 폼(`_local.form`)과
 * 「변경 있음」(`hasChanges`)은 건드리지 않아야 한다 — 건드리면 화면 하단 「저장」과 탭 전환 확인 창이 이 표 때문에 켜진다.
 *
 * 화면의 스위치와 단추를 실제로 눌러 엔진(ActionDispatcher)이 보낸 요청을 가짜 fetch 로 받는다. 끌어 놓기만은 jsdom 에서
 * 재현할 수 없어, 굽기 산출 JSON 의 `onSortEnd` 액션을 엔진에 그대로 넣고 `$sortedItems` 를 문맥으로 준다.
 */

import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import {
  buildLayout,
  collect,
  fieldDefs,
  koTranslations,
  loadLayout,
  setupRegistry,
  settle,
  shownToasts,
  stubFetch,
  stubRefetch,
  watch,
  type FetchRoute,
  type Json,
} from './support/memberTabHarness';

const settings = loadLayout('admin_settings.json');
const tablePartial = loadLayout('partials/admin_settings/_tab_member_fields.json');
const cardsPartial = loadLayout('partials/admin_settings/_tab_member_fields_cards.json');
const requiredModal = loadLayout('partials/admin_settings/_modal_member_field_required.json');

const FIELDS = '/api/admin/field-sets/core.user/fields';
const CHANGED = '다른 관리자가 항목을 바꿨습니다. 다시 불러왔습니다.';
const FORM = { member: { nickname_unique: false, nickname_banned_words: ['관리자'], nickname_change_interval_days: 30 }, security: { force_https: true } };

let active: ReturnType<typeof createLayoutTest> | null = null;

interface Rendered {
  t: ReturnType<typeof createLayoutTest>;
  container: HTMLElement;
  requests: ReturnType<typeof stubFetch>;
  refetched: string[];
  /** 액션 문맥의 최상위 변수(`$sortedItems` 등)를 얹는 자리 */
  context: Json;
}

/** 가입 항목 조각을 렌더하고 요청, 재조회 기록을 건다. */
async function renderFields(opts: { partial?: string; routes?: FetchRoute[]; defs?: Json } = {}): Promise<Rendered> {
  const context: Json = { memberFieldDefs: opts.defs ?? fieldDefs() };
  active = createLayoutTest(buildLayout(opts.partial ?? '_tab_member_fields.json') as any, {
    componentRegistry: setupRegistry(),
    translations: koTranslations(),
    locale: 'ko',
    queryParams: { tab: 'member' },
    initialState: { _local: { form: JSON.parse(JSON.stringify(FORM)) }, _global: { settingsAbilities: { can_update: true } } },
    initialData: context,
  } as any);
  const result = await active.render();
  watch(active);
  const requests = stubFetch(opts.routes ?? []);
  const refetched = stubRefetch();

  return { t: active, container: result.container, requests, refetched, context };
}

/** 요청을 `METHOD 주소` 로 줄인다. */
const lines = (requests: Array<{ method: string; url: string }>) => requests.map((r) => `${r.method} ${r.url}`);

/** 설정 폼과 「변경 있음」이 처음 그대로인지 단언한다. */
function expectFormUntouched(t: ReturnType<typeof createLayoutTest>) {
  const local = t.getState()._local;
  expect(local.form).toEqual(FORM);
  expect(local.hasChanges).toBeUndefined();
}

/** 항목 정의가 도착했을 때 데이터소스가 돌리는 액션(성공 쪽)을 그대로 실행한다. */
async function arriveFieldDefs(t: ReturnType<typeof createLayoutTest>) {
  const source = settings.data_sources.find((s: Json) => s.id === 'memberFieldDefs');
  for (const action of [].concat(source.onSuccess)) {
    await t.triggerAction({ type: 'click', ...(action as Json) } as any);
  }
}

// 단추를 여러 번 누르고 그때마다 액션 사슬이 끝나기를 기다린다 — 다른 테스트 파일과 함께 돌 때 기본 5초로는 모자란다
vi.setConfig({ testTimeout: 30_000 });

beforeEach(() => {
  (window as any).__templateApp = { getConfig: () => ({ templateId: 'test-template', locale: 'ko' }) };
});

afterEach(() => {
  active?.cleanup();
  active = null;
  watch(null);
  vi.unstubAllGlobals();
  delete (window as any).__templateApp;
});

describe('가입 항목 표 — 스위치 즉시 저장', () => {
  // @scenario case=switch_saves_once
  // @effects switch_click_sends_one_put_and_keeps_settings_form
  it.each([
    ['enabled', 'mobile', 3, { enabled: false }],
    ['show_on_signup', 'mobile', 3, { show_on_signup: false }],
    ['enabled', 'job', 5, { enabled: false }],
  ] as const)('%s 스위치(%s)를 한 번 누르면 PUT 1건이 나가고 설정 폼과 「변경 있음」은 그대로다', async (attr, key, id, body) => {
    const { t, requests, refetched } = await renderFields();

    fireEvent.click(screen.getByTestId(`member-field-table-${attr}-${key}`));
    await settle();

    expect(lines(requests)).toEqual([`PUT ${FIELDS}/${id}`]);
    expect(requests[0].body).toEqual({ ...body, fields_version: 'v1' });
    expectFormUntouched(t);
    // 다시 받는 것은 항목 정의뿐이다(설정 전체를 다시 받으면 다른 칸에서 고친 값이 덮인다)
    expect(refetched).toEqual(['memberFieldDefs']);
    expect(shownToasts(t.getToasts())).toEqual([{ type: 'success', message: '저장했습니다.' }]);
  });

  // @scenario case=switch_structure
  // @effects table_switches_not_bound_to_settings_form
  it('표와 카드의 스위치에는 name 이 없고, 두 조각의 어떤 액션도 설정 폼과 「변경 있음」을 쓰지 않는다', () => {
    for (const partial of [tablePartial, cardsPartial]) {
      const toggles = collect(partial, (n) => n.name === 'Toggle');
      expect(toggles.length).toBe(3);
      for (const toggle of toggles) {
        expect(toggle.props.name).toBeUndefined();
        expect(toggle.props.awaitConfirmation).toBe(true);
        expect(collect(toggle.actions, (n) => n.handler === 'apiCall' && n.params?.method === 'PUT').length).toBeGreaterThan(0);
      }

      const writes = collect(partial, (n) => n.handler === 'setState');
      expect(writes.length).toBeGreaterThan(0);
      for (const write of writes) {
        expect(write.params.target).toBe('global');
        const keys = Object.keys(write.params);
        expect(keys.some((k) => k === 'hasChanges' || k.startsWith('form') || k.startsWith('_local'))).toBe(false);
      }
      // 저장 뒤 다시 받는 소스는 항목 정의 하나뿐이다
      const refetches = collect(partial, (n) => n.handler === 'refetchDataSource').map((n) => n.params.dataSourceId);
      expect(new Set(refetches)).toEqual(new Set(['memberFieldDefs']));
    }
  });

  // @scenario case=switch_failure
  // @effects switch_failure_toasts_and_refetches
  it('저장에 실패하면 서버 문구를 오류 토스트로 알리고 표를 다시 받는다', async () => {
    const { t, requests, refetched } = await renderFields({
      routes: [{ method: 'PUT', endsWith: '/fields/3', status: 422, body: { success: false, message: '선택지를 1개 이상 넣어 주세요.', errors: { options: ['선택지를 1개 이상 넣어 주세요.'] } } }],
    });

    fireEvent.click(screen.getByTestId('member-field-table-enabled-mobile'));
    await settle();

    expect(lines(requests)).toEqual([`PUT ${FIELDS}/3`]);
    expect(shownToasts(t.getToasts())).toEqual([{ type: 'error', message: '선택지를 1개 이상 넣어 주세요.' }]);
    expect(refetched).toEqual(['memberFieldDefs']);
    expectFormUntouched(t);
  });

  // @scenario case=switch_conflict
  // @effects stale_table_conflict_toasts_changed_by_other_and_refetches
  it('다른 관리자가 먼저 바꿔 409 가 오면 「다른 관리자가 항목을 바꿨습니다」를 알리고 표를 다시 받는다', async () => {
    const { t, requests, refetched } = await renderFields({
      routes: [{ method: 'PUT', endsWith: '/fields/3', status: 409, body: { success: false, message: 'conflict', error_code: 'fields_changed', data: { fields_version: 'v2' } } }],
    });

    fireEvent.click(screen.getByTestId('member-field-table-enabled-mobile'));
    await settle();

    expect(requests[0].body.fields_version).toBe('v1');
    expect(shownToasts(t.getToasts())).toEqual([{ type: 'error', message: CHANGED }]);
    expect(refetched).toEqual(['memberFieldDefs']);
    expectFormUntouched(t);
  });

  // @scenario case=busy_lock
  // @effects busy_locks_other_controls_until_definitions_arrive
  it('저장이 도는 동안 다른 스위치와 손잡이가 잠기고, 항목 정의가 도착하면 풀린다', async () => {
    const { t, container } = await renderFields();

    fireEvent.click(screen.getByTestId('member-field-table-enabled-mobile'));
    await settle();

    // 표식은 요청이 끝나도 남는다 — 새 정의 버전이 도착하기 전에 다음 조작이 나가면 낡은 버전으로 거부된다
    expect(t.getState()._global.memberFieldBusy).toBe('3:enabled');
    await t.rerender();
    expect(screen.getByTestId('member-field-table-enabled-mobile').getAttribute('aria-busy')).toBe('true');
    expect(screen.getByTestId('member-field-table-enabled-name')).toBeDisabled();
    expect(screen.getByTestId('member-field-table-required-mobile')).toBeDisabled();
    expect(container.querySelectorAll('[data-drag-handle]')).toHaveLength(0);
    expect(screen.getByTestId('member-field-table-handle-disabled-name')).toBeDisabled();

    await arriveFieldDefs(t);
    expect(t.getState()._global.memberFieldBusy).toBeNull();
    await t.rerender();
    expect(screen.getByTestId('member-field-table-enabled-name')).not.toBeDisabled();
    expect(container.querySelectorAll('[data-drag-handle]')).toHaveLength(6);
  });

  // @scenario case=card_switch
  // @effects card_switch_uses_same_wiring
  it('좁은 화면 카드의 스위치도 같은 요청을 보내고 설정 폼을 건드리지 않는다', async () => {
    const { t, requests, refetched } = await renderFields({ partial: '_tab_member_fields_cards.json' });

    fireEvent.click(screen.getByTestId('member-field-card-show_on_signup-job'));
    await settle();

    expect(lines(requests)).toEqual([`PUT ${FIELDS}/5`]);
    expect(requests[0].body).toEqual({ show_on_signup: false, fields_version: 'v1' });
    expect(refetched).toEqual(['memberFieldDefs']);
    expectFormUntouched(t);
  });
});

describe('가입 항목 표 — 필수를 켤 때의 확인(U1)', () => {
  const missing = (count: number): FetchRoute => ({ method: 'GET', endsWith: '/fields/3/missing-count', body: { success: true, data: { missing_count: count } } });
  const submit = collect(requiredModal, (n) => n.props?.['data-testid'] === 'member-field-required-submit')[0];
  const cancel = collect(requiredModal, (n) => n.props?.['data-testid'] === 'member-field-required-cancel')[0];
  const modeRadio = collect(requiredModal, (n) => n.id === 'member_field_required_mode')[0];

  // @scenario case=required_on_no_missing
  // @effects required_on_without_missing_members_saves_directly
  it('빈 기존 회원이 없으면 확인 없이 바로 필수로 저장한다', async () => {
    const { t, requests, refetched } = await renderFields({ routes: [missing(0)] });

    fireEvent.click(screen.getByTestId('member-field-table-required-mobile'));
    await settle();

    expect(lines(requests)).toEqual([`GET ${FIELDS}/3/missing-count`, `PUT ${FIELDS}/3`]);
    expect(requests[1].body).toEqual({ required: true, fields_version: 'v1' });
    expect(t.getModalStack()).toEqual([]);
    expect(refetched).toEqual(['memberFieldDefs']);
    expectFormUntouched(t);
  });

  // @scenario case=required_on_with_missing
  // @effects required_on_with_missing_members_opens_confirm_before_saving
  it('빈 기존 회원이 있으면 저장하지 않고 인원과 함께 확인 창을 연다', async () => {
    const { t, requests } = await renderFields({ routes: [missing(128)] });

    fireEvent.click(screen.getByTestId('member-field-table-required-mobile'));
    await settle();

    expect(lines(requests)).toEqual([`GET ${FIELDS}/3/missing-count`]);
    expect(t.getModalStack()).toEqual(['member_field_required_modal']);
    expect(t.getState()._global.member_field_required_modal).toEqual({
      id: 3,
      label: '휴대폰',
      missing_count: 128,
      mode: 'on_edit',
      fieldsVersion: 'v1',
      isSaving: false,
    });
    // 창이 뜨면 표의 저장 중 표식은 내려간다(취소해도 표가 잠긴 채 남지 않게)
    expect(t.getState()._global.memberFieldBusy).toBeNull();
    expectFormUntouched(t);
  });

  // @scenario case=required_confirm_on_login
  // @scenario case=required_confirm_on_edit
  // @effects required_confirm_sends_required_and_existing_members_in_one_request
  it.each([
    ['on_login', true],
    ['on_edit', false],
  ] as const)('확인 창에서 %s 을 고르고 누르면 필수와 기존 회원 요구를 한 요청으로 보낸다', async (mode, requireExisting) => {
    const { t, requests, refetched } = await renderFields({ routes: [missing(5)] });

    fireEvent.click(screen.getByTestId('member-field-table-required-mobile'));
    await settle();
    // 창의 라디오가 고른 값을 창 상태에 담는다
    await t.triggerAction({ ...modeRadio.actions[0], params: { ...modeRadio.actions[0].params, 'member_field_required_modal.mode': mode } } as any);
    expect(t.getState()._global.member_field_required_modal.mode).toBe(mode);
    requests.length = 0;

    await t.triggerAction(submit.actions[0]);
    await settle();

    expect(lines(requests)).toEqual([`PUT ${FIELDS}/3`]);
    expect(requests[0].body).toEqual({ required: true, require_existing_members: requireExisting, fields_version: 'v1' });
    expect(t.getModalStack()).toEqual([]);
    expect(t.getState()._global.member_field_required_modal).toBeNull();
    expect(refetched).toEqual(['memberFieldDefs']);
    expectFormUntouched(t);
  });

  // @scenario case=required_confirm_cancel
  // @effects required_confirm_cancel_saves_nothing
  it('확인 창에서 취소하면 아무것도 저장하지 않는다', async () => {
    const { t, requests, refetched } = await renderFields({ routes: [missing(5)] });

    fireEvent.click(screen.getByTestId('member-field-table-required-mobile'));
    await settle();
    requests.length = 0;
    await t.triggerAction(cancel.actions[0]);
    await settle();

    expect(requests).toHaveLength(0);
    expect(refetched).toEqual([]);
    expect(t.getModalStack()).toEqual([]);
  });

  // @scenario case=required_confirm_conflict
  // @effects stale_table_conflict_toasts_changed_by_other_and_refetches
  it('확인 창 저장이 409 로 거부되면 창을 닫고 알린 뒤 표를 다시 받는다', async () => {
    const { t, refetched } = await renderFields({
      routes: [missing(5), { method: 'PUT', endsWith: '/fields/3', status: 409, body: { success: false, message: 'conflict', error_code: 'fields_changed', data: { fields_version: 'v2' } } }],
    });

    fireEvent.click(screen.getByTestId('member-field-table-required-mobile'));
    await settle();
    await t.triggerAction(submit.actions[0]);
    await settle();

    expect(shownToasts(t.getToasts())).toEqual([{ type: 'error', message: CHANGED }]);
    expect(t.getModalStack()).toEqual([]);
    expect(refetched).toEqual(['memberFieldDefs']);
  });

  // @scenario case=required_off
  // @effects required_off_saves_directly
  it('필수를 끌 때는 인원을 묻지 않고 바로 저장한다', async () => {
    const { requests } = await renderFields();

    fireEvent.click(screen.getByTestId('member-field-table-required-name'));
    await settle();

    expect(lines(requests)).toEqual([`PUT ${FIELDS}/1`]);
    expect(requests[0].body).toEqual({ required: false, fields_version: 'v1' });
  });

  // @scenario case=required_on_admin_scope
  // @effects required_on_admin_scope_saves_directly
  it('본인 수정 범위가 아닌 항목은 회원에게 요구할 수 없으므로 인원을 묻지 않고 바로 저장한다', async () => {
    const defs = fieldDefs();
    defs.data.fields[2].edit_scope = 'admin_edit';
    const { requests } = await renderFields({ defs });

    fireEvent.click(screen.getByTestId('member-field-table-required-mobile'));
    await settle();

    expect(lines(requests)).toEqual([`PUT ${FIELDS}/3`]);
    expect(requests[0].body).toEqual({ required: true, fields_version: 'v1' });
  });

  // @scenario case=required_card
  // @effects card_switch_uses_same_wiring
  it('카드의 필수 스위치도 인원을 먼저 묻는다', async () => {
    const { t, requests } = await renderFields({ partial: '_tab_member_fields_cards.json', routes: [missing(2)] });

    fireEvent.click(screen.getByTestId('member-field-card-required-mobile'));
    await settle();

    expect(lines(requests)).toEqual([`GET ${FIELDS}/3/missing-count`]);
    expect(t.getModalStack()).toEqual(['member_field_required_modal']);
  });
});

describe('가입 항목 표 — 순서 즉시 저장', () => {
  const tableSort = collect(tablePartial, (n) => n.event === 'onSortEnd')[0];
  const cardSort = collect(cardsPartial, (n) => n.event === 'onSortEnd')[0];
  const serverOrder = [1, 2, 3, 4, 5, 6];
  const dragged = [3, 1, 2, 4, 5, 6];

  /** 끌어 놓은 결과로 정렬 액션을 실행한다. */
  async function drop(r: Rendered, action: Json) {
    const byId = new Map(fieldDefs().data.fields.map((f: Json) => [f.id, f]));
    r.context.$sortedItems = dragged.map((id) => byId.get(id));
    await r.t.triggerAction(action as any);
    await settle();
  }

  // @scenario case=sort_saves
  // @effects sort_end_sends_order_put_with_version
  it('표는 끌어 놓자마자 새 순서를 PUT 으로 보내고 항목 정의만 다시 받는다', async () => {
    expect(collect(tablePartial, (n) => n.sortable)[0].sortable).toMatchObject({ itemKey: 'id', handle: '[data-drag-handle]', wrapperElement: 'tr' });
    const r = await renderFields();

    await drop(r, tableSort);

    expect(lines(r.requests)).toEqual([`PUT ${FIELDS}/order`]);
    expect(r.requests[0].body).toEqual({ order: dragged, fields_version: 'v1' });
    expect(r.refetched).toEqual(['memberFieldDefs']);
    expect(shownToasts(r.t.getToasts())).toEqual([{ type: 'success', message: '순서를 저장했습니다.' }]);
    expectFormUntouched(r.t);
  });

  // @scenario case=sort_optimistic
  // @effects sort_shows_dropped_order_until_definitions_arrive
  it('놓은 순서를 먼저 그리고, 항목 정의가 도착하면 서버 순서를 따른다', async () => {
    const r = await renderFields();
    expect(r.t.getComputed('memberFields').map((f: Json) => f.id)).toEqual(serverOrder);

    await drop(r, tableSort);

    expect(r.t.getState()._global.memberFieldOrder).toEqual(dragged);
    expect(r.t.getState()._global.memberFieldBusy).toBe('order');
    expect(r.t.getComputed('memberFields').map((f: Json) => f.id)).toEqual(dragged);

    await arriveFieldDefs(r.t);
    expect(r.t.getState()._global.memberFieldOrder).toBeNull();
    expect(r.t.getComputed('memberFields').map((f: Json) => f.id)).toEqual(serverOrder);
  });

  // @scenario case=sort_failure
  // @effects sort_failure_refetches_and_returns_to_server_order
  it('순서 저장에 실패하면 알리고 다시 받아 서버 순서로 돌아간다', async () => {
    const r = await renderFields({ routes: [{ method: 'PUT', endsWith: '/fields/order', status: 500, body: { success: false, message: '서버 오류가 발생했습니다.' } }] });

    await drop(r, tableSort);

    expect(lines(r.requests)).toEqual([`PUT ${FIELDS}/order`]);
    expect(shownToasts(r.t.getToasts())).toEqual([{ type: 'error', message: '서버 오류가 발생했습니다.' }]);
    expect(r.refetched).toEqual(['memberFieldDefs']);

    // 다시 받은 항목 정의가 도착하면(데이터소스 성공 액션) 임시 순서를 버린다
    await arriveFieldDefs(r.t);
    expect(r.t.getComputed('memberFields').map((f: Json) => f.id)).toEqual(serverOrder);
    expectFormUntouched(r.t);
  });

  // @scenario case=sort_conflict
  // @effects stale_table_conflict_toasts_changed_by_other_and_refetches
  it('순서 저장이 409 로 거부되면 「다른 관리자가 항목을 바꿨습니다」를 알리고 다시 받는다', async () => {
    const r = await renderFields({
      routes: [{ method: 'PUT', endsWith: '/fields/order', status: 409, body: { success: false, message: 'conflict', error_code: 'fields_changed', data: { fields_version: 'v2' } } }],
    });

    await drop(r, tableSort);

    expect(shownToasts(r.t.getToasts())).toEqual([{ type: 'error', message: CHANGED }]);
    expect(r.refetched).toEqual(['memberFieldDefs']);
  });

  // @scenario case=sort_refetch_failure
  // @effects definitions_fetch_failure_also_clears_busy_and_order
  it('다시 받기가 실패해도 저장 중 표식과 임시 순서를 지운다', async () => {
    const r = await renderFields();
    await drop(r, tableSort);

    const source = settings.data_sources.find((s: Json) => s.id === 'memberFieldDefs');
    for (const action of [].concat(source.onError)) {
      await r.t.triggerAction({ type: 'click', ...(action as Json) } as any);
    }

    expect(r.t.getState()._global.memberFieldBusy).toBeNull();
    expect(r.t.getState()._global.memberFieldOrder).toBeNull();
  });

  // @scenario case=card_sort
  // @effects card_sort_and_move_buttons_use_order_api
  it('카드도 끌어 놓으면 같은 순서 요청을 보낸다', async () => {
    const r = await renderFields({ partial: '_tab_member_fields_cards.json' });

    await drop(r, cardSort);

    expect(lines(r.requests)).toEqual([`PUT ${FIELDS}/order`]);
    expect(r.requests[0].body).toEqual({ order: dragged, fields_version: 'v1' });
    expect(r.refetched).toEqual(['memberFieldDefs']);
  });

  // @scenario case=card_move_buttons
  // @effects card_sort_and_move_buttons_use_order_api
  it.each([
    ['down', 'name', [2, 1, 3, 4, 5, 6]],
    ['up', 'mobile', [1, 3, 2, 4, 5, 6]],
    ['up', 'color', [1, 2, 3, 4, 6, 5]],
  ] as const)('카드의 %s 단추(%s)는 이웃과 자리를 바꾼 순서를 저장한다', async (direction, key, order) => {
    const r = await renderFields({ partial: '_tab_member_fields_cards.json' });

    fireEvent.click(screen.getByTestId(`member-field-card-${direction}-${key}`));
    await settle();

    expect(lines(r.requests)).toEqual([`PUT ${FIELDS}/order`]);
    expect(r.requests[0].body).toEqual({ order, fields_version: 'v1' });
    expect(r.t.getState()._global.memberFieldOrder).toEqual(order);
    expect(r.refetched).toEqual(['memberFieldDefs']);
    expectFormUntouched(r.t);
  });
});
