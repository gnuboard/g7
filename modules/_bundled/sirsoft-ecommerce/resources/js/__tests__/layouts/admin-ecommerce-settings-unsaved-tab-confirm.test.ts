// e2e:allow Vitest 레이아웃 계약 파일 — 브라우저 흐름(고친 뒤 탭 클릭 → 확인 창 → 머무르기/옮기기, ko/en/ja)은 tests/Playwright/specs/admin/same-layout-route-locale-state.spec.ts 와 7.1.0 조치 G 재실측에서 확인한다.
/**
 * 이커머스 환경설정 — 저장하지 않은 변경이 있을 때 탭을 옮기기 전에 묻는가
 *
 * 배경(7.1.0 브라우저 실측 조치 G2): 저장은 지금 탭의 구획만 보내고, 탭을 옮기면 설정을 다시 받아
 * 고친 값이 저장된 값으로 돌아간다(이 복귀는 의도된 동작 — 2026-10-08 결정). 다만 말없이 사라지면
 * 운영자는 저장된 줄 안다. 고친 것이 있으면 탭을 옮기기 전에 확인 창을 띄우고, 머무르면 고친 값을 그대로
 * 두고, 옮기기로 하면 평소 탭 전환과 같은 순서로 옮긴다.
 */

import { describe, it, expect } from 'vitest';

import layout from '../../../layouts/admin/admin_ecommerce_settings.json';
import modal from '../../../layouts/admin/partials/admin_ecommerce_settings/_modal_unsaved_tab_switch.json';
import identityTab from '../../../layouts/admin/partials/admin_ecommerce_settings/_tab_identity_policies.json';

/** 노드 트리를 돌며 조건에 맞는 노드를 모은다. */
function collect(node: any, match: (n: any) => boolean, out: any[] = []): any[] {
  if (Array.isArray(node)) {
    node.forEach((child) => collect(child, match, out));
    return out;
  }
  if (node && typeof node === 'object') {
    if (match(node)) out.push(node);
    Object.values(node).forEach((value) => collect(value, match, out));
  }
  return out;
}

/** `{{ ... }}` 식 하나를 주어진 문맥으로 평가한다 (엔진과 같은 JS 식). */
function evaluate(expression: string, context: Record<string, any>): any {
  const body = expression.trim().replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(context), `return (${body});`)(...Object.values(context));
}

/** 핸들러 하나나 배열·sequence 를 평평한 액션 목록으로 편다. */
function flatten(action: any): any[] {
  if (!action) return [];
  if (Array.isArray(action)) return action.flatMap(flatten);
  if (action.handler === 'sequence') return [action, ...flatten(action.actions)];
  return [action];
}

/** 탭 전환 이벤트 — 탭 줄(변경 없음)과 확인 창 [이동] 이 보내고 탭 본문 틀이 받는다(#753) */
const SWITCH_EVENT = 'sirsoft-ecommerce:settings-switch-tab';

const onTabChange = collect(layout, (n) => n.event === 'onTabChange')[0];
const [guarded, fallback] = onTabChange?.conditions ?? [];

function ctx(hasChanges: boolean, next: string, active?: string) {
  return { _local: { hasChanges }, _global: { activeEcommerceSettingsTab: active }, query: {}, $args: [next] };
}

describe('이커머스 환경설정 — 미저장 변경이 있을 때 탭 전환 확인 창', () => {
  // @scenario case=dirty_other_tab_opens_confirm
  // @effects unsaved_tab_confirm_opens
  it('고친 것이 있는 채 다른 탭을 누르면 옮기지 않고 확인 창을 연다', () => {
    expect(onTabChange?.handler).toBe('conditions');
    expect(evaluate(guarded.if, ctx(true, 'shipping'))).toBe(true);

    const actions = flatten(guarded.then);
    expect(actions.some((a) => a.handler === 'navigate')).toBe(false);
    expect(actions.find((a) => a.handler === 'setState')?.params).toMatchObject({ target: 'global', pendingEcommerceSettingsTab: '{{$args[0]}}' });
    expect(actions.find((a) => a.handler === 'openModal')?.target).toBe('modal_ecommerce_settings_unsaved_tab');
  });

  // @scenario case=clean_other_tab_switches
  // @effects tab_switch_without_confirm
  it('고친 것이 없으면 확인 없이 탭 전환 이벤트만 보낸다 (정리·이동은 탭 본문 틀의 이벤트 받기가 한다)', () => {
    expect(evaluate(guarded.if, ctx(false, 'shipping'))).toBe(false);
    expect(fallback.if).toBeUndefined();

    const actions = flatten(fallback.then);
    expect(actions).toHaveLength(1);
    expect(actions[0]).toMatchObject({ handler: 'emitEvent', params: { event: SWITCH_EVENT, data: { tab: '{{$args[0]}}' } } });
  });

  // @scenario case=dirty_same_tab_no_confirm
  // @effects tab_switch_without_confirm
  it('지금 탭을 다시 누르면 고친 것이 있어도 창을 띄우지 않는다', () => {
    expect(evaluate(guarded.if, ctx(true, 'basic_info'))).toBe(false);
    expect(evaluate(guarded.if, ctx(true, 'shipping', 'shipping'))).toBe(false);
  });

  // @scenario case=stay_keeps_edits
  // @effects unsaved_tab_confirm_stay_keeps_edits
  it('머무르기는 옮기려던 탭만 지우고 창을 닫는다(고친 값과 변경 표시는 건드리지 않는다)', () => {
    expect((layout as any).modals.some((m: any) => String(m.partial ?? '').endsWith('_modal_unsaved_tab_switch.json'))).toBe(true);
    expect((modal as any).id).toBe('modal_ecommerce_settings_unsaved_tab');

    const stay = collect(modal, (n) => n.props?.['data-testid'] === 'ecommerce-settings-unsaved-tab-stay')[0];
    const actions = flatten(stay.actions);
    expect(actions.find((a) => a.handler === 'setState')?.params).toEqual({ target: 'global', pendingEcommerceSettingsTab: null });
    expect(actions.find((a) => a.handler === 'closeModal')?.target).toBe('modal_ecommerce_settings_unsaved_tab');
    expect(actions.some((a) => a.handler === 'navigate' || a.handler === 'actionRef' || a.actionRef)).toBe(false);
    expect(actions.some((a) => a.params?.target === 'local')).toBe(false);
  });

  // @scenario case=leave_switches_tab
  // @effects unsaved_tab_confirm_leave_switches_tab
  it('옮기기는 창을 먼저 닫고 확인 창에 맡겨 둔 탭으로 전환 이벤트만 보낸다', () => {
    const leave = collect(modal, (n) => n.props?.['data-testid'] === 'ecommerce-settings-unsaved-tab-leave')[0];
    const actions = flatten(leave.actions);
    const closeIdx = actions.findIndex((a) => a.handler === 'closeModal');
    const emitIdx = actions.findIndex((a) => a.handler === 'emitEvent');

    // 정리 목록은 탭 본문 틀의 이벤트 받기 한 곳 — 창 쪽에는 상태 쓰기(local·$parent·전역 _local 이중 쓰기)가 없다
    expect(actions.some((a) => a.handler === 'setState')).toBe(false);
    expect(actions.some((a) => a.handler === 'navigate')).toBe(false);
    // 창은 이동 전에 닫는다 — 이동(설정 재조회)이 끝날 때까지 창이 떠 있지 않게(실측 1~5초)
    expect(closeIdx).toBeGreaterThan(-1);
    expect(closeIdx).toBeLessThan(emitIdx);
    expect(actions[emitIdx]?.params).toEqual({ event: SWITCH_EVENT, data: { tab: '{{_global.pendingEcommerceSettingsTab}}' } });
  });

  // @scenario case=leave_switches_tab
  // @effects unsaved_tab_confirm_leave_switches_tab
  it('탭 본문 틀의 이벤트 받기가 정리 목록·전역 탭·주소 갱신을 한 곳에서 맡는다', () => {
    const container = collect(layout, (n) => n.id === 'ecommerce_settings_tab_content')[0];
    const listeners = (container?.onComponentEvent ?? []).filter((e: any) => e.event === SWITCH_EVENT);
    expect(listeners).toHaveLength(1);

    const actions = flatten(listeners[0]);
    const local = actions.find((a) => a.handler === 'setState' && a.params?.target === 'local')?.params ?? {};
    const { target: _t, ...cleanup } = local;
    // 고친 것을 버렸으므로 「변경 있음」을 끈다 — 설정 재조회(수 초) 도착 전 다른 탭 클릭에 창이 다시 뜨지 않게(실측)
    expect(cleanup).toEqual({
      hasChanges: false, errors: null,
      isAddingExchangeReason: false, exchangeReasonForm: null, exchangeReasonFormErrors: null,
      isAddingReturnReason: false, returnReasonForm: null, returnReasonFormErrors: null,
      isAddingRefundReason: false, refundReasonForm: null, refundReasonFormErrors: null,
      isAddingCarrier: false, carrierForm: null, carrierFormErrors: null,
      isAddingShippingType: false, shippingTypeForm: null, shippingTypeFormErrors: null,
      isAddingCountry: false, newCountry: null, isAddingCurrency: false, 'form.newCurrency': null,
      isAddingMileageCurrency: false, 'form.newMileageCurrency': null, claimReasonTab: null,
    });
    expect(actions.find((a) => a.handler === 'setState' && a.params?.target === 'global')?.params).toEqual({
      target: 'global', activeEcommerceSettingsTab: '{{_eventData.tab}}', pendingEcommerceSettingsTab: null,
    });
    expect(actions.find((a) => a.handler === 'navigate')?.params).toMatchObject({ path: '/admin/ecommerce/settings', replace: true, query: { tab: '{{_eventData.tab}}' } });
    // 정리 → 전역 탭 → 이동 순서
    const order = actions.filter((a) => a.handler !== 'sequence').map((a) => a.handler);
    expect(order).toEqual(['setState', 'setState', 'navigate']);
  });
  // @scenario case=identity_filter_not_form_state
  // @effects identity_filter_not_bound_to_settings_form
  it('본인인증 정책 탭의 필터와 검색칸은 설정 폼에 자동 바인딩되지 않고 이 화면 경로로만 옮긴다', () => {
    // 자동바인딩되면 form.filter.* 에 남고 「변경 있음」을 켜 탭 전환 확인 창이 오탐으로 뜬다(G2 실측 C8)
    const filters = collect(identityTab, (n) => typeof n.props?.name === 'string' && n.props.name.startsWith('filter.'));
    expect(filters.length).toBeGreaterThan(0);
    for (const f of filters) expect(f.props.autoBinding).toBe(false);

    const search = filters.find((f) => f.props.name === 'filter.search');
    expect(search.props.value).toContain('_local.identityPolicySearch');
    expect(flatten(search.actions).some((a) => a.handler === 'setState' && 'identityPolicySearch' in (a.params ?? {}))).toBe(true);

    // 필터, 검색, 초기화, 쪽 넘김은 모두 이 설정 화면 경로로 옮긴다(다른 경로면 다른 레이아웃으로 넘어간다)
    const navigates = collect(identityTab, (n) => n.handler === 'navigate');
    expect(navigates.length).toBeGreaterThan(0);
    for (const n of navigates) expect(n.params.path).toBe('/admin/ecommerce/settings');
    // 검색 버튼도 입력 중인 검색어를 쿼리로 싣는다
    expect(navigates.some((n) => String(n.params.query?.search ?? '').includes('_local.identityPolicySearch'))).toBe(true);
  });
  // @scenario case=tab_refetch_overlay_covers_inputs
  // @effects tab_refetch_overlay_covers_inputs
  it('탭 전환 재조회 동안 오버레이가 탭 본문을 덮는다(도착 전 입력이 응답에 말없이 덮이지 않게)', () => {
    // 쿼리만 바뀌는 탭 전환은 설정을 다시 받는다. 덮개가 탭 본문을 가리지 않으면 도착 전에 고친 칸이 응답에 덮였다(G2 실측 C9 — 게시판)
    const overlay = (layout as any).transition_overlay;
    expect(overlay?.target).toBe('ecommerce_settings_tab_content');
    expect(overlay?.wait_for).toContain('settings');
    expect(collect(layout, (n) => n.id === 'ecommerce_settings_tab_content').length).toBe(1);
  });
  // @scenario case=identity_list_refetches_list_only
  // @effects identity_list_ops_keep_settings_edits
  it('본인인증 정책 목록의 필터·검색·초기화·쪽 넘김은 목록 소스(ecommerceIdentityPolicies)만 다시 받는다(위쪽 설정 칸에서 고친 값을 덮지 않게)', () => {
    // 목록 조작이 설정까지 다시 받으면 같은 탭 위쪽 설정 칸에서 고친 값이 서버 값으로 돌아가고 「변경 있음」이 풀렸다(7.1.0 조치 R1 ②, 2026-10-08 결정)
    const navigates = collect(identityTab, (n) => n.handler === 'navigate');
    expect(navigates.length).toBeGreaterThan(0);
    for (const n of navigates) {
      expect(n.params.replace).toBe(true);
      expect(n.params.refetch_data_sources).toBe('ecommerceIdentityPolicies');
    }
    // 목록만 다시 받을 때도 목록 영역 덮개가 뜨도록 목록 소스가 덮개 대기 목록에 있다
    expect((layout as any).transition_overlay?.wait_for).toContain('ecommerceIdentityPolicies');
    // 목록 소스는 이 레이아웃에 있는 데이터 소스여야 한다(오타면 아무것도 다시 받지 않는다)
    expect(((layout as any).data_sources ?? []).some((d: any) => d.id === 'ecommerceIdentityPolicies')).toBe(true);
  });
});
