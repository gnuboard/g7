/**
 * @file adminCheckoutFieldsTable.test.tsx
 * @description 쇼핑몰 설정 주문 설정 탭의 「주문서 항목」 구획 — 표, 좁은 화면 카드, 항목 추가·수정 창, 삭제 확인 창
 *
 * 표의 스위치는 누르자마자 코어 항목 API 로 나가고 `checkoutFieldDefs` 만 다시 받는다. 설정 폼(`_local.form`)과 「변경 있음」
 * (`hasChanges`)은 건드리지 않아야 한다 — 건드리면 화면 하단 「저장」과 탭 전환 확인 창이 이 표 때문에 켜진다.
 *
 * 화면의 스위치와 단추를 실제로 눌러 엔진(ActionDispatcher)이 보낸 요청을 가짜 fetch 로 받는다. 창의 본문과 하단 단추는
 * 굽기 산출 JSON 의 노드를 그대로 한 화면에 펼쳐 그린다(실물 Modal 은 하단을 따로 그리므로 여기서는 노드만 쓴다).
 */

import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import {
  GIFT_LOCK,
  NAME_LOCK,
  buildSettingsLayout,
  collect,
  fieldDefs,
  lines,
  loadLayout,
  loadSettingsPartial,
  setupRegistry,
  settle,
  shownToasts,
  stubFetch,
  stubRefetch,
  translations,
  watch,
  type FetchRoute,
  type Json,
} from './support/checkoutFieldsHarness';

const settings = loadLayout('admin_ecommerce_settings.json');
const orderTab = loadSettingsPartial('_tab_order_settings.json');
const tablePartial = loadSettingsPartial('_checkout_fields_table.json');
const cardsPartial = loadSettingsPartial('_checkout_fields_cards.json');
const formModal = loadSettingsPartial('_modal_checkout_field_form.json');
const deleteModal = loadSettingsPartial('_modal_checkout_field_delete.json');

const FIELDS = '/api/admin/field-sets/sirsoft-ecommerce.order_contact/fields';
const CHANGED = '다른 관리자가 항목을 바꿨습니다. 다시 불러왔습니다.';
const FORM = { order_settings: { auto_cancel_expired: true, auto_cancel_days: 3 }, inquiry: { board_slug: 'qna' } };

let active: ReturnType<typeof createLayoutTest> | null = null;

interface Rendered {
  t: ReturnType<typeof createLayoutTest>;
  container: HTMLElement;
  requests: ReturnType<typeof stubFetch>;
  refetched: string[];
}

/** 창의 본문과 하단 단추를 한 상자에 펼친 노드(창이 열렸을 때만 그린다). */
function modalAsBlock(modal: Json, stateKey: string): Json {
  return { type: 'basic', name: 'Div', if: `{{!!_global.${stateKey}}}`, props: { 'data-testid': `${stateKey}-block` }, children: [...modal.children, ...modal.props.footer] };
}

/** 주문서 항목 구획(과 창 둘)을 렌더하고 요청, 재조회 기록을 건다. */
async function renderSection(opts: { partial?: Json; routes?: FetchRoute[]; defs?: Json; withModals?: boolean; global?: Json } = {}): Promise<Rendered> {
  const components = [opts.partial ?? tablePartial];
  if (opts.withModals) {
    components.push(modalAsBlock(formModal, 'checkout_field_form_modal'), modalAsBlock(deleteModal, 'checkout_field_delete_modal'));
  }
  active = createLayoutTest(buildSettingsLayout(components) as any, {
    componentRegistry: setupRegistry(),
    translations: translations('ko'),
    locale: 'ko',
    queryParams: { tab: 'order_settings' },
    initialState: { _local: { form: JSON.parse(JSON.stringify(FORM)) }, _global: { ...(opts.global ?? {}) } },
    initialData: { checkoutFieldDefs: opts.defs ?? fieldDefs() },
  } as any);
  const result = await active.render();
  watch(active);
  const requests = stubFetch(opts.routes ?? []);
  const refetched = stubRefetch();

  return { t: active, container: result.container, requests, refetched };
}

/** 설정 폼과 「변경 있음」이 처음 그대로인지 단언한다. */
function expectFormUntouched(t: ReturnType<typeof createLayoutTest>) {
  const local = t.getState()._local;
  expect(local.form).toEqual(FORM);
  expect(local.hasChanges).toBeUndefined();
}

/** DOM id 로 스위치를 찾는다. 실물 스위치는 id 를 바깥 상자에 달고 그 안에 `role="switch"` 를 둔다(시험 부품도 같은 모양이다). */
function switchOf(id: string): HTMLElement {
  const found = document.querySelector<HTMLElement>(`#${id} [role="switch"]`);
  if (!found) throw new Error(`스위치를 찾지 못했다: #${id}`);

  return found;
}

/** 행(또는 카드)의 글자를 공백을 줄여 읽는다. */
const textOf = (el: Element | null) => (el?.textContent ?? '').replace(/\s+/g, ' ').trim();

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

describe('주문서 항목 — 설정 화면에 붙는 자리', () => {
  // @scenario case=wiring
  // @effects checkout_fields_source_fetched_only_on_order_tab
  it('항목 정의는 주문 설정 탭에서만 코어 항목 집합 API 로 받고, 도착하면 저장 중 표식을 내린다', () => {
    const source = settings.data_sources.find((s: Json) => s.id === 'checkoutFieldDefs');

    expect(source.endpoint).toBe(FIELDS);
    expect(source.method).toBe('GET');
    expect(source.if).toBe("{{(query.tab || _global.activeEcommerceSettingsTab || 'basic_info') === 'order_settings'}}");
    expect(source.fallback.data.abilities.can_update).toBe(false);
    expect(collect(source.onSuccess, (n) => n.handler === 'setState')[0].params).toMatchObject({ target: 'global', checkoutFieldBusy: null });
    expect(settings.transition_overlay.wait_for).toContain('checkoutFieldDefs');
  });

  // @scenario case=wiring
  // @effects checkout_fields_section_included_in_order_tab_with_modals
  it('주문 설정 탭이 표 조각을, 표 조각이 카드 조각을 끌어오고, 창 둘이 설정 화면에 등록돼 있다', () => {
    expect(collect(orderTab, (n) => n.partial === '_checkout_fields_table.json')).toHaveLength(1);
    expect(collect(tablePartial, (n) => n.partial === '_checkout_fields_cards.json')).toHaveLength(1);
    expect(tablePartial.id).toBe('checkout_fields_section');

    const modalFiles = JSON.stringify(settings.modals);
    expect(modalFiles).toContain('_modal_checkout_field_form.json');
    expect(modalFiles).toContain('_modal_checkout_field_delete.json');
    expect(formModal.id).toBe('checkout_field_form_modal');
    expect(deleteModal.id).toBe('checkout_field_delete_modal');
  });
});

describe('주문서 항목 표 — 행의 구획과 출처', () => {
  // @scenario case=rows
  // @effects rows_grouped_orderer_first_with_group_and_source
  it('주문자 구획을 앞에, 배송지 구획을 뒤에 그리고 구획 이름과 출처를 보인다', async () => {
    const { container } = await renderSection();

    const rows = Array.from(container.querySelectorAll('tbody tr'));
    expect(rows.map((r) => r.id)).toEqual([
      'checkout_field_row_orderer_name',
      'checkout_field_row_orderer_email',
      'checkout_field_row_tax_invoice',
      'checkout_field_row_recipient_name',
      'checkout_field_row_shipping_memo',
      'checkout_field_row_gift_wrap',
      'checkout_field_row_door_code',
      'checkout_field_row_delivery_time',
    ]);

    const cells = (key: string) => Array.from(container.querySelectorAll(`#checkout_field_row_${key} td`)).map(textOf);
    // 이커머스가 선언한 항목 — 출처 칸에 모듈 이름
    expect(cells('orderer_name').slice(0, 3)).toEqual(['주문자', '주문자 이름', '이커머스']);
    expect(cells('shipping_memo').slice(0, 3)).toEqual(['배송지', '배송 메모', '이커머스']);
    // 다른 확장이 기여한 항목 — 그 확장 이름
    expect(cells('gift_wrap').slice(0, 3)).toEqual(['배송지', '선물 포장', '선물 포장 플러그인']);
    // 운영자가 추가한 항목 — 「추가」
    expect(cells('door_code').slice(0, 3)).toEqual(['배송지', '현관 비밀번호', '추가']);
  });

  // @scenario case=rows
  // @effects row_description_shown_in_request_locale
  it('안내 문구가 있는 항목은 요청 로케일로 푼 안내를 이름 아래에 보이고, 없는 항목에는 안내 줄이 없다', async () => {
    const { container } = await renderSection();

    expect(textOf(container.querySelector('#checkout_field_row_orderer_email td:nth-child(2) p'))).toBe('비회원은 필수');
    expect(container.querySelector('#checkout_field_row_shipping_memo td:nth-child(2) p')).toBeNull();
  });

  // @scenario case=rows
  // @effects group_falls_back_to_shipping
  it('구획이 없는 항목은 배송지로 본다', async () => {
    const defs = fieldDefs();
    defs.data.fields = [{ ...defs.data.fields[2], ui: {} }];
    const { container } = await renderSection({ defs });

    expect(textOf(container.querySelector('#checkout_field_row_shipping_memo td'))).toBe('배송지');
  });

  // @scenario case=unavailable
  // @effects unavailable_row_shows_badge_instead_of_switches
  it('유형을 준 확장이 꺼진 항목은 스위치 대신 「사용 불가」를 보인다', async () => {
    const { container } = await renderSection();

    const row = container.querySelector('#checkout_field_row_tax_invoice')!;
    expect(textOf(row)).toContain('사용 불가');
    expect(textOf(row)).toContain('세금계산서 플러그인');
    expect(row.querySelectorAll('[role="switch"]')).toHaveLength(0);
    expect(document.querySelector('#checkout_field_enabled_tax_invoice')).toBeNull();
  });

  // @scenario case=empty
  // @effects empty_and_forbidden_states_explained
  it('항목이 없으면 빈 안내를, 조회 권한이 없으면(403 표지) 표 대신 이유를 보인다', async () => {
    const empty = await renderSection({ defs: fieldDefs({ fields: [] }) });
    expect(textOf(empty.container.querySelector('#checkout_fields_empty'))).toBe('항목이 없습니다.');
    expect(empty.container.querySelector('#checkout_fields_no_permission')).toBeNull();
    empty.t.cleanup();

    const forbidden = await renderSection({ defs: fieldDefs({ fields: [], abilities: { can_update: false }, set: {} }), global: { checkoutFieldsLoadError: 'forbidden' } });
    expect(textOf(forbidden.container.querySelector('#checkout_fields_no_permission'))).toBe('주문서 항목을 볼 권한이 없습니다.');
    expect(forbidden.container.querySelector('table')).toBeNull();
  });
});

describe('주문서 항목 표 — 잠금과 권한', () => {
  // @scenario case=locks
  // @effects locked_switch_disabled_with_reason
  it('선언한 쪽이 잠근 속성의 스위치는 눌리지 않고, 자물쇠가 사유를 title 과 접근 가능한 이름으로 준다', async () => {
    const { requests } = await renderSection();

    const enabled = switchOf('checkout_field_enabled_orderer_name');
    const required = switchOf('checkout_field_required_orderer_name');
    expect(enabled).toBeDisabled();
    expect(required).toBeDisabled();
    // 잠겨도 값은 그대로 보인다(주문자 이름은 사용, 필수가 켜진 채 고정이다)
    expect(enabled.getAttribute('aria-checked')).toBe('true');
    expect(required.getAttribute('aria-checked')).toBe('true');

    const lock = screen.getByTestId('checkout-field-table-enabled-lock-orderer_name');
    expect(lock.getAttribute('title')).toBe(NAME_LOCK);
    expect(lock.getAttribute('aria-label')).toBe(NAME_LOCK);
    expect(lock.getAttribute('role')).toBe('img');

    fireEvent.click(enabled);
    await settle();
    expect(requests).toEqual([]);
  });

  // @scenario case=locks
  // @effects contributed_lock_applies_per_attribute
  it('기여한 확장이 「필수」만 잠그면 필수 스위치만 잠기고 사용 스위치는 눌린다', async () => {
    await renderSection();

    expect(switchOf('checkout_field_enabled_gift_wrap')).not.toBeDisabled();
    expect(switchOf('checkout_field_required_gift_wrap')).toBeDisabled();
    expect(screen.getByTestId('checkout-field-table-required-lock-gift_wrap').getAttribute('title')).toBe(GIFT_LOCK);
    expect(screen.queryByTestId('checkout-field-table-enabled-lock-gift_wrap')).toBeNull();
    // 잠금이 없는 항목에는 자물쇠가 없다
    expect(screen.queryByTestId('checkout-field-table-enabled-lock-shipping_memo')).toBeNull();
  });

  // @scenario case=read_only
  // @effects read_only_admin_gets_disabled_switches_and_no_row_buttons
  it('수정 권한이 없으면 스위치가 전부 잠기고 「항목 추가」가 잠기며 행 단추가 없다', async () => {
    const { container, requests } = await renderSection({ defs: fieldDefs({ abilities: { can_update: false } }) });

    const switches = Array.from(container.querySelectorAll('table [role="switch"]'));
    expect(switches.length).toBe(14);
    switches.forEach((s) => expect(s).toBeDisabled());
    expect(screen.getByTestId('checkout-field-add')).toBeDisabled();
    expect(screen.queryByTestId('checkout-field-table-edit-door_code')).toBeNull();
    expect(screen.queryByTestId('checkout-field-table-delete-door_code')).toBeNull();

    fireEvent.click(switchOf('checkout_field_enabled_shipping_memo'));
    await settle();
    expect(requests).toEqual([]);
  });

  // @scenario case=row_buttons
  // @effects only_custom_rows_have_edit_and_delete
  it('수정과 삭제 단추는 운영자가 추가한 항목에만 있다(선언 항목과 기여 항목에는 없다)', async () => {
    await renderSection();

    for (const key of ['orderer_name', 'shipping_memo', 'gift_wrap', 'tax_invoice']) {
      expect(screen.queryByTestId(`checkout-field-table-edit-${key}`)).toBeNull();
      expect(screen.queryByTestId(`checkout-field-table-delete-${key}`)).toBeNull();
    }
    for (const key of ['door_code', 'delivery_time']) {
      expect(screen.getByTestId(`checkout-field-table-edit-${key}`)).toBeInTheDocument();
      expect(screen.getByTestId(`checkout-field-table-delete-${key}`)).toBeInTheDocument();
    }
    expect(screen.getByTestId('checkout-field-table-delete-door_code').getAttribute('aria-label')).toBe('현관 비밀번호 삭제');
  });
});

describe('주문서 항목 표 — 스위치 즉시 저장', () => {
  // @scenario case=switch_saves_once
  // @effects checkout_switch_click_sends_one_put_and_keeps_settings_form
  it.each([
    ['enabled', 'shipping_memo', 13, { enabled: false }],
    ['required', 'shipping_memo', 13, { required: true }],
    ['enabled', 'gift_wrap', 15, { enabled: false }],
    ['required', 'door_code', 17, { required: true }],
  ] as const)('%s 스위치(%s)를 한 번 누르면 PUT 1건이 나가고 항목 정의만 다시 받으며 설정 폼은 그대로다', async (attr, key, id, body) => {
    const { t, requests, refetched } = await renderSection();

    const toggle = switchOf(`checkout_field_${attr}_${key}`);
    const label = fieldDefs().data.fields.find((f: Json) => f.key === key).label;
    expect(toggle.getAttribute('aria-label')).toBe(`${label} ${attr === 'enabled' ? '사용' : '필수'}`);
    fireEvent.click(toggle);
    await settle();

    expect(lines(requests)).toEqual([`PUT ${FIELDS}/${id}`]);
    expect(requests[0].body).toEqual({ ...body, fields_version: 'v1' });
    expect(refetched).toEqual(['checkoutFieldDefs']);
    expectFormUntouched(t);
    expect(shownToasts(t.getToasts())).toEqual([{ type: 'success', message: '저장했습니다.' }]);
  });

  // @scenario case=switch_structure
  // @effects checkout_switches_not_bound_to_settings_form
  it('표와 카드의 스위치에는 name 이 없고, 두 조각의 어떤 액션도 설정 폼과 「변경 있음」을 쓰지 않는다', () => {
    for (const partial of [tablePartial, cardsPartial]) {
      const toggles = collect(partial, (n) => n.name === 'Toggle');
      expect(toggles.length).toBe(2);
      for (const toggle of toggles) {
        expect(toggle.props.name).toBeUndefined();
        expect(toggle.props.awaitConfirmation).toBe(true);
        expect(collect(toggle.actions, (n) => n.handler === 'apiCall' && n.params?.method === 'PUT').length).toBe(1);
      }

      const writes = collect(partial, (n) => n.handler === 'setState');
      expect(writes.length).toBeGreaterThan(0);
      for (const write of writes) {
        expect(write.params.target).toBe('global');
        expect(Object.keys(write.params).some((k) => k === 'hasChanges' || k.startsWith('form') || k.startsWith('_local'))).toBe(false);
      }
      const refetches = collect(partial, (n) => n.handler === 'refetchDataSource').map((n) => n.params.dataSourceId);
      expect(new Set(refetches)).toEqual(new Set(['checkoutFieldDefs']));
    }
  });

  // @scenario case=instant_notice
  // @effects instant_save_notice_shown_above_table
  it('표 머리에 「이 표의 변경은 바로 저장됩니다」 안내가 있다', async () => {
    const { container } = await renderSection();

    expect(textOf(container.querySelector('#checkout_fields_instant_notice'))).toBe('이 표의 변경은 바로 저장됩니다.');
  });

  // @scenario case=switch_failure
  // @effects checkout_switch_failure_toasts_and_refetches
  it('저장에 실패하면 서버 문구를 오류 토스트로 알리고 표를 다시 받는다', async () => {
    const { t, requests, refetched } = await renderSection({
      routes: [{ method: 'PUT', endsWith: '/fields/13', status: 422, body: { success: false, message: '선택지를 1개 이상 넣어 주세요.', errors: { options: ['선택지를 1개 이상 넣어 주세요.'] } } }],
    });

    fireEvent.click(switchOf('checkout_field_required_shipping_memo'));
    await settle();

    expect(lines(requests)).toEqual([`PUT ${FIELDS}/13`]);
    expect(shownToasts(t.getToasts())).toEqual([{ type: 'error', message: '선택지를 1개 이상 넣어 주세요.' }]);
    expect(refetched).toEqual(['checkoutFieldDefs']);
    expectFormUntouched(t);
  });

  // @scenario case=switch_conflict
  // @effects checkout_stale_table_conflict_toasts_changed_by_other_and_refetches
  it('다른 관리자가 먼저 바꿔 409 가 오면 「다른 관리자가 항목을 바꿨습니다」를 알리고 표를 다시 받는다', async () => {
    const { t, requests, refetched } = await renderSection({
      routes: [{ method: 'PUT', endsWith: '/fields/13', status: 409, body: { success: false, message: 'conflict', error_code: 'fields_changed', data: { fields_version: 'v2' } } }],
    });

    fireEvent.click(switchOf('checkout_field_enabled_shipping_memo'));
    await settle();

    expect(requests[0].body.fields_version).toBe('v1');
    expect(shownToasts(t.getToasts())).toEqual([{ type: 'error', message: CHANGED }]);
    expect(refetched).toEqual(['checkoutFieldDefs']);
    expectFormUntouched(t);
  });

  // @scenario case=busy_lock
  // @effects checkout_busy_locks_other_controls_until_definitions_arrive
  it('저장이 도는 동안 다른 스위치와 행 단추가 잠기고, 항목 정의가 도착하면 풀린다', async () => {
    const { t } = await renderSection();

    fireEvent.click(switchOf('checkout_field_enabled_shipping_memo'));
    await settle();

    // 표식은 요청이 끝나도 남는다 — 새 정의 버전이 도착하기 전에 다음 조작이 나가면 낡은 버전으로 거부된다
    expect(t.getState()._global.checkoutFieldBusy).toBe('13:enabled');
    await t.rerender();
    expect(switchOf('checkout_field_enabled_shipping_memo').getAttribute('aria-busy')).toBe('true');
    expect(switchOf('checkout_field_required_shipping_memo')).toBeDisabled();
    expect(switchOf('checkout_field_enabled_gift_wrap')).toBeDisabled();
    expect(screen.getByTestId('checkout-field-table-delete-door_code')).toBeDisabled();
    expect(screen.getByTestId('checkout-field-add')).toBeDisabled();

    // 항목 정의가 도착했을 때 데이터소스가 돌리는 액션(성공 쪽)을 그대로 실행한다
    const source = settings.data_sources.find((s: Json) => s.id === 'checkoutFieldDefs');
    for (const action of [].concat(source.onSuccess)) {
      await t.triggerAction({ type: 'click', ...(action as Json) } as any);
    }
    expect(t.getState()._global.checkoutFieldBusy).toBeNull();
    await t.rerender();
    expect(switchOf('checkout_field_enabled_gift_wrap')).not.toBeDisabled();
    expect(screen.getByTestId('checkout-field-add')).not.toBeDisabled();
  });
});

describe('주문서 항목 카드(좁은 화면)', () => {
  // @scenario case=cards
  // @effects cards_render_same_rows_with_card_ids
  it('카드도 같은 순서로 그리고, 카드와 스위치의 DOM id 가 표와 겹치지 않는다', async () => {
    const { container } = await renderSection({ partial: cardsPartial });

    const cards = Array.from(container.querySelectorAll('[id^="checkout_field_card_"]')).filter((el) => !/^checkout_field_card_(enabled|required)_/.test(el.id));
    expect(cards.map((c) => c.id).slice(0, 4)).toEqual(['checkout_field_card_orderer_name', 'checkout_field_card_orderer_email', 'checkout_field_card_tax_invoice', 'checkout_field_card_recipient_name']);
    expect(cards).toHaveLength(8);

    const memo = container.querySelector('#checkout_field_card_shipping_memo')!;
    expect(textOf(memo)).toContain('배송 메모');
    expect(textOf(memo)).toContain('배송지');
    expect(textOf(memo)).toContain('이커머스');
    // 스위치 DOM id 는 표의 것(`checkout_field_enabled_…`)과 겹치지 않는다
    expect(switchOf('checkout_field_card_enabled_shipping_memo')).toBeInTheDocument();
    expect(switchOf('checkout_field_card_required_shipping_memo')).toBeInTheDocument();
    expect(document.querySelector('#checkout_field_enabled_shipping_memo')).toBeNull();

    expect(textOf(container.querySelector('#checkout_field_card_orderer_email'))).toContain('비회원은 필수');
    expect(textOf(container.querySelector('#checkout_field_card_tax_invoice'))).toContain('사용 불가');
    expect(textOf(container.querySelector('#checkout_field_card_door_code'))).toContain('추가');
  });

  // @scenario case=cards
  // @effects checkout_card_switch_uses_same_wiring
  it('카드의 스위치도 같은 요청을 보내고 설정 폼을 건드리지 않으며, 잠금과 행 단추 규칙이 표와 같다', async () => {
    const { t, requests, refetched } = await renderSection({ partial: cardsPartial });

    expect(switchOf('checkout_field_card_enabled_orderer_name')).toBeDisabled();
    expect(screen.getByTestId('checkout-field-card-enabled-lock-orderer_name').getAttribute('aria-label')).toBe(NAME_LOCK);
    expect(screen.queryByTestId('checkout-field-card-delete-gift_wrap')).toBeNull();
    expect(screen.getByTestId('checkout-field-card-delete-door_code')).toBeInTheDocument();

    fireEvent.click(switchOf('checkout_field_card_required_shipping_memo'));
    await settle();

    expect(lines(requests)).toEqual([`PUT ${FIELDS}/13`]);
    expect(requests[0].body).toEqual({ required: true, fields_version: 'v1' });
    expect(refetched).toEqual(['checkoutFieldDefs']);
    expectFormUntouched(t);
  });
});

describe('주문서 항목 — 운영자 추가 항목의 추가, 수정, 삭제 창', () => {
  // @scenario case=add_button
  // @effects add_button_follows_allows_custom
  it('「항목 추가」는 운영자 추가를 받는 집합일 때만 나온다', async () => {
    const off = await renderSection({ defs: fieldDefs({ set: { allows_custom: false } }) });
    expect(screen.queryByTestId('checkout-field-add')).toBeNull();
    off.t.cleanup();

    const on = await renderSection();
    const button = screen.getByTestId('checkout-field-add');
    expect(button.id).toBe('checkout_field_add_button');
    expect(button).not.toBeDisabled();
    expect(on.requests).toEqual([]);
  });

  // @scenario case=add_modal
  // @effects add_opens_modal_with_fresh_state
  it('「항목 추가」를 누르면 빈 폼으로 창이 열리고, 유형은 지금 쓸 수 있는 것만 고를 수 있다', async () => {
    const { t, container } = await renderSection({ withModals: true });

    fireEvent.click(screen.getByTestId('checkout-field-add'));
    await settle();

    expect(t.getModalStack()).toEqual(['checkout_field_form_modal']);
    expect(t.getState()._global.checkout_field_form_modal).toEqual({
      isNew: true,
      errors: null,
      isSaving: false,
      fieldsVersion: 'v1',
      form: { id: null, key: '', type: '', group: 'shipping', name: {}, description: {}, options: [], enabled: true, required: false },
    });
    await t.rerender();

    const typeOptions = Array.from(container.querySelectorAll('#checkout_field_form_type option')).map((o) => [o.getAttribute('value'), o.textContent]);
    expect(typeOptions).toEqual([
      ['', '유형을 고르세요'],
      ['text', '한 줄 글'],
      ['choice', '하나 선택'],
      ['multi_choice', '여러 개 선택'],
    ]);
    expect(Array.from(container.querySelectorAll('#checkout_field_form_group option')).map((o) => o.textContent)).toEqual(['주문자', '배송지']);
    expect(container.querySelector('#checkout_field_form_key')).not.toBeDisabled();
    // 선택 유형이 아니면 선택지 구획이 없다
    expect(container.querySelector('#checkout_field_form_options')).toBeNull();
    // 회원 전용 옵션은 이 창에 없다
    const body = JSON.stringify(formModal);
    for (const memberOnly of ['show_on_signup', 'edit_scope', 'require_existing_members', 'public_profile', 'admin_list_column', 'display_name_usable', 'idv_claim']) {
      expect(body).not.toContain(memberOnly);
    }
  });

  // @scenario case=add_save
  // @effects add_posts_key_type_group_and_options
  it('선택 유형을 골라 적고 저장하면 키, 유형, 구획, 선택지를 POST 로 보내고 창을 닫은 뒤 표를 다시 받는다', async () => {
    const { t, container, requests, refetched } = await renderSection({ withModals: true });

    fireEvent.click(screen.getByTestId('checkout-field-add'));
    await settle();
    await t.rerender();

    fireEvent.change(container.querySelector('#checkout_field_form_type')!, { target: { value: 'choice' } });
    await settle();
    fireEvent.change(container.querySelector('#checkout_field_form_group')!, { target: { value: 'orderer' } });
    await settle();
    fireEvent.change(container.querySelector('#checkout_field_form_key')!, { target: { value: ' Visit_Time ' } });
    await settle();
    fireEvent.change(container.querySelector('#checkout_field_form_name')!, { target: { value: '방문 시간' } });
    await settle();
    await t.rerender();
    fireEvent.click(switchOf('checkout_field_form_required'));
    await settle();
    fireEvent.click(container.querySelector('#checkout_field_form_option_add')!);
    await settle();
    await t.rerender();
    fireEvent.change(screen.getByTestId('checkout-field-form-option-value-0'), { target: { value: 'AM' } });
    await settle();
    fireEvent.change(screen.getByTestId('checkout-field-form-option-label-0'), { target: { value: '오전' } });
    await settle();
    await t.rerender();

    fireEvent.click(container.querySelector('#checkout_field_form_save')!);
    await settle();

    expect(lines(requests)).toEqual([`POST ${FIELDS}`]);
    expect(requests[0].body).toEqual({
      key: 'visit_time',
      type: 'choice',
      name: { ko: '방문 시간' },
      description: {},
      enabled: true,
      required: true,
      ui: { group: 'orderer' },
      options: [{ value: 'am', label: { ko: '오전' } }],
      fields_version: 'v1',
    });
    expect(t.getModalStack()).toEqual([]);
    expect(t.getState()._global.checkout_field_form_modal).toBeNull();
    expect(refetched).toEqual(['checkoutFieldDefs']);
    expectFormUntouched(t);
  });

  // @scenario case=add_validation
  // @effects validation_errors_shown_under_fields
  it('검증 실패(422)는 창을 닫지 않고 각 칸 아래에 서버 문구를 보인다', async () => {
    const KEY_ERROR = '키는 영문 소문자로 시작하고 영문 소문자, 숫자, 밑줄만 쓸 수 있습니다(2~50자).';
    const { t, container, refetched } = await renderSection({
      withModals: true,
      routes: [{ method: 'POST', endsWith: '/fields', status: 422, body: { success: false, message: '입력 값을 확인해 주세요.', errors: { key: [KEY_ERROR], 'name.ko': ['이름을 입력해 주세요.'], type: ['유형을 골라 주세요.'] } } }],
    });

    fireEvent.click(screen.getByTestId('checkout-field-add'));
    await settle();
    await t.rerender();
    fireEvent.click(container.querySelector('#checkout_field_form_save')!);
    await settle();
    await t.rerender();

    expect(t.getModalStack()).toEqual(['checkout_field_form_modal']);
    expect(t.getState()._global.checkout_field_form_modal.isSaving).toBe(false);
    const block = screen.getByTestId('checkout_field_form_modal-block');
    expect(textOf(block)).toContain('입력 값을 확인해 주세요.');
    expect(textOf(block)).toContain(KEY_ERROR);
    expect(textOf(block)).toContain('유형을 골라 주세요.');
    expect(textOf(block.querySelector('[data-error]'))).toBe('이름을 입력해 주세요.');
    expect(refetched).toEqual([]);
  });

  // @scenario case=edit_modal
  // @effects edit_locks_key_and_type_and_puts_without_them
  it('[수정]은 행의 값으로 창을 열고 키와 유형을 잠그며, 저장은 키와 유형 없이 PUT 으로 나간다', async () => {
    const { t, container, requests, refetched } = await renderSection({ withModals: true });

    fireEvent.click(screen.getByTestId('checkout-field-table-edit-delivery_time'));
    await settle();
    await t.rerender();

    expect(t.getState()._global.checkout_field_form_modal.form).toMatchObject({ id: 18, key: 'delivery_time', type: 'choice', group: 'shipping', name: { ko: '희망 배송 시간' }, enabled: true, required: false });
    expect(container.querySelector('#checkout_field_form_key')).toBeDisabled();
    expect(container.querySelector('#checkout_field_form_type')).toBeNull();
    expect(textOf(container.querySelector('#checkout_field_form_type_locked'))).toBe('하나 선택');
    // 저장된 선택지의 값(영문)은 잠긴다
    expect(screen.getByTestId('checkout-field-form-option-value-0')).toBeDisabled();
    expect((screen.getByTestId('checkout-field-form-option-label-1') as HTMLInputElement).value).toBe('오후');

    fireEvent.click(screen.getByTestId('checkout-field-form-option-remove-1'));
    await settle();
    await t.rerender();
    fireEvent.click(container.querySelector('#checkout_field_form_save')!);
    await settle();

    expect(lines(requests)).toEqual([`PUT ${FIELDS}/18`]);
    expect(requests[0].body).toEqual({
      name: { ko: '희망 배송 시간' },
      description: { ko: '가능한 시간대를 골라 주세요.' },
      enabled: true,
      required: false,
      ui: { group: 'shipping' },
      options: [{ value: 'am', label: { ko: '오전' } }],
      fields_version: 'v1',
    });
    expect(refetched).toEqual(['checkoutFieldDefs']);
    expectFormUntouched(t);
  });

  // @scenario case=edit_conflict
  // @effects modal_conflict_keeps_modal_and_drops_stale_version
  it('창 저장이 409 로 거부되면 알리고 표를 다시 받되 창과 적은 내용은 그대로 두고, 쥐고 있던 버전을 비운다', async () => {
    const { t, container, refetched } = await renderSection({
      withModals: true,
      routes: [{ method: 'PUT', endsWith: '/fields/17', status: 409, body: { success: false, message: 'conflict', error_code: 'fields_changed', data: { fields_version: 'v2' } } }],
    });

    fireEvent.click(screen.getByTestId('checkout-field-table-edit-door_code'));
    await settle();
    await t.rerender();
    fireEvent.click(container.querySelector('#checkout_field_form_save')!);
    await settle();

    expect(shownToasts(t.getToasts())).toEqual([{ type: 'error', message: CHANGED }]);
    expect(refetched).toEqual(['checkoutFieldDefs']);
    expect(t.getModalStack()).toEqual(['checkout_field_form_modal']);
    expect(t.getState()._global.checkout_field_form_modal).toMatchObject({ isSaving: false, fieldsVersion: null, form: { key: 'door_code' } });
  });

  // @scenario case=delete_modal
  // @effects delete_sends_version_in_query_and_refetches
  it('[삭제]는 확인 창을 열고, 확인하면 정의 버전을 쿼리로 실어 DELETE 를 보낸 뒤 표를 다시 받는다', async () => {
    const { t, container, requests, refetched } = await renderSection({ withModals: true });

    fireEvent.click(screen.getByTestId('checkout-field-table-delete-door_code'));
    await settle();
    await t.rerender();

    expect(t.getModalStack()).toEqual(['checkout_field_delete_modal']);
    expect(requests).toEqual([]);
    expect(textOf(container.querySelector('#checkout_field_delete_target'))).toBe('현관 비밀번호');
    expect(textOf(container.querySelector('#checkout_field_delete_message'))).toContain('이 항목을 삭제할까요?');
    expect(textOf(container.querySelector('#checkout_field_delete_warning'))).toBe('이 항목에 입력된 값도 함께 지워지며 되돌릴 수 없습니다.');

    fireEvent.click(container.querySelector('#checkout_field_delete_submit')!);
    await settle();

    expect(lines(requests)).toEqual([`DELETE ${FIELDS}/17?fields_version=v1`]);
    expect(t.getModalStack()).toEqual([]);
    expect(t.getState()._global.checkout_field_delete_modal).toBeNull();
    expect(refetched).toEqual(['checkoutFieldDefs']);
    expect(shownToasts(t.getToasts())).toEqual([{ type: 'success', message: '삭제했습니다.' }]);
    expectFormUntouched(t);
  });

  // @scenario case=delete_failure
  // @effects delete_failure_keeps_modal_and_shows_server_message
  it('삭제가 거부되면(다른 설정이 쓰는 항목 등) 서버 문구를 알리고 창을 그대로 둔다', async () => {
    const { t, container, refetched } = await renderSection({
      withModals: true,
      routes: [{ method: 'DELETE', endsWith: '/fields/17', status: 409, body: { success: false, message: '다른 설정이 이 항목을 쓰고 있습니다.', error_code: 'field_in_use', data: { usages: [] } } }],
    });

    fireEvent.click(screen.getByTestId('checkout-field-table-delete-door_code'));
    await settle();
    await t.rerender();
    fireEvent.click(container.querySelector('#checkout_field_delete_submit')!);
    await settle();

    expect(shownToasts(t.getToasts())).toEqual([{ type: 'error', message: '다른 설정이 이 항목을 쓰고 있습니다.' }]);
    expect(t.getModalStack()).toEqual(['checkout_field_delete_modal']);
    expect(t.getState()._global.checkout_field_delete_modal.isDeleting).toBe(false);
    expect(refetched).toEqual([]);
  });
});
