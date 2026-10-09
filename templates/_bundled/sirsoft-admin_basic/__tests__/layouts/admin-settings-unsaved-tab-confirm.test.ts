// e2e:allow Vitest 레이아웃 계약 파일 — 브라우저 흐름(고친 뒤 탭 클릭 → 확인 창 → 머무르기/옮기기, ko/en/ja)은 tests/Playwright/specs/admin/same-layout-route-locale-state.spec.ts 와 7.1.0 조치 G 재실측에서 확인한다.
/**
 * 코어 환경설정 — 저장하지 않은 변경이 있을 때 탭을 옮기기 전에 묻는가
 *
 * 배경(7.1.0 브라우저 실측 조치 G2): 저장은 지금 탭의 구획만 보내고, 탭을 옮기면 설정을 다시 받아
 * 고친 값이 저장된 값으로 돌아간다(이 복귀는 의도된 동작 — 2026-10-08 결정). 다만 말없이 사라지면
 * 운영자는 저장된 줄 안다. 고친 것이 있으면 탭을 옮기기 전에 확인 창을 띄우고, 머무르면 고친 값을 그대로
 * 두고, 옮기기로 하면 평소 탭 전환과 같은 순서로 옮긴다.
 */

import { describe, it, expect } from 'vitest';

import layout from '../../layouts/admin_settings.json';
import modal from '../../layouts/partials/admin_settings/_modal_unsaved_tab_switch.json';
import identityTab from '../../layouts/partials/admin_settings/_tab_identity_policies.json';

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

const onTabChange = collect(layout, (n) => n.event === 'onTabChange')[0];
const [guarded, fallback] = onTabChange?.conditions ?? [];

function ctx(hasChanges: boolean, next: string, active?: string) {
  return { _local: { hasChanges }, _global: { activeSettingsTab: active }, query: {}, $args: [next] };
}

describe('코어 환경설정 — 미저장 변경이 있을 때 탭 전환 확인 창', () => {
  // @scenario case=dirty_other_tab_opens_confirm
  // @effects unsaved_tab_confirm_opens
  it('고친 것이 있는 채 다른 탭을 누르면 옮기지 않고 확인 창을 연다', () => {
    expect(onTabChange?.handler).toBe('conditions');
    expect(evaluate(guarded.if, ctx(true, 'mail'))).toBe(true);

    const actions = flatten(guarded.then);
    expect(actions.some((a) => a.handler === 'navigate')).toBe(false);
    expect(actions.find((a) => a.handler === 'setState')?.params).toMatchObject({ target: 'global', pendingSettingsTab: '{{$args[0]}}' });
    expect(actions.find((a) => a.handler === 'openModal')?.target).toBe('modal_settings_unsaved_tab');
  });

  // @scenario case=clean_other_tab_switches
  // @effects tab_switch_without_confirm
  it('고친 것이 없으면 확인 없이 바로 옮긴다', () => {
    expect(evaluate(guarded.if, ctx(false, 'mail'))).toBe(false);
    expect(fallback.if).toBeUndefined();

    const navigate = flatten(fallback.then).find((a) => a.handler === 'navigate');
    expect(navigate?.params).toMatchObject({ path: '/admin/settings', replace: true, query: { tab: '{{$args[0]}}' } });
  });

  // @scenario case=dirty_same_tab_no_confirm
  // @effects tab_switch_without_confirm
  it('지금 탭을 다시 누르면 고친 것이 있어도 창을 띄우지 않는다', () => {
    expect(evaluate(guarded.if, ctx(true, 'general'))).toBe(false);
    expect(evaluate(guarded.if, ctx(true, 'mail', 'mail'))).toBe(false);
  });

  // @scenario case=stay_keeps_edits
  // @effects unsaved_tab_confirm_stay_keeps_edits
  it('머무르기는 옮기려던 탭만 지우고 창을 닫는다(고친 값과 변경 표시는 건드리지 않는다)', () => {
    expect((layout as any).modals.some((m: any) => String(m.partial ?? '').endsWith('_modal_unsaved_tab_switch.json'))).toBe(true);
    expect((modal as any).id).toBe('modal_settings_unsaved_tab');

    const stay = collect(modal, (n) => n.props?.['data-testid'] === 'settings-unsaved-tab-stay')[0];
    const actions = flatten(stay.actions);
    expect(actions.find((a) => a.handler === 'setState')?.params).toEqual({ target: 'global', pendingSettingsTab: null });
    expect(actions.find((a) => a.handler === 'closeModal')?.target).toBe('modal_settings_unsaved_tab');
    expect(actions.some((a) => a.handler === 'navigate' || a.handler === 'actionRef' || a.actionRef)).toBe(false);
    expect(actions.some((a) => a.params?.target === 'local')).toBe(false);
  });

  // @scenario case=leave_switches_tab
  // @effects unsaved_tab_confirm_leave_switches_tab
  it('옮기기는 페이지 상태를 $parent._local 로 정리한 뒤 창을 닫고 확인 창에 맡겨 둔 탭으로 옮긴다', () => {
    const leave = collect(modal, (n) => n.props?.['data-testid'] === 'settings-unsaved-tab-leave')[0];
    const actions = flatten(leave.actions);
    const parentLocal = actions.filter((a) => a.handler === 'setState' && String(a.params?.target ?? '').startsWith('$parent._local'));
    const closeIdx = actions.findIndex((a) => a.handler === 'closeModal');
    const moveIdx = actions.findIndex((a) => a.handler === 'navigate');

    // 모달 문맥의 target=local 은 페이지 폼 렌더러 상태에 닿지 않아, 옮긴 뒤 첫 입력에 지운 값(인라인 폼 플래그 등)이
    // 되살아났다(G2 실측 C7). 페이지 상태는 여는 쪽 문맥인 $parent._local 로 고친다(modal-usage.md TL;DR 3)
    expect(actions.some((a) => a.handler === 'setState' && a.params?.target === 'local')).toBe(false);
    expect(actions.some((a) => a.actionRef)).toBe(false);
    // $parent 는 openModal 이 쌓은 여는 쪽 문맥이고 closeModal 이 걷어 낸다 — 그 전에 쓴다(TL;DR 7)
    expect(parentLocal.length).toBeGreaterThan(0);
    for (const a of parentLocal) expect(actions.indexOf(a)).toBeLessThan(closeIdx);
    // 고친 것을 버렸으므로 「변경 있음」을 끈다 — 설정 재조회(수 초) 도착 전 다른 탭 클릭에 창이 다시 뜨지 않게(실측)
    expect(parentLocal.some((a) => a.params.target === '$parent._local' && a.params.hasChanges === false)).toBe(true);
    // 창은 이동 전에 닫는다 — 이동(설정 재조회)이 끝날 때까지 창이 떠 있지 않게(실측 1~5초)
    expect(closeIdx).toBeGreaterThan(-1);
    expect(closeIdx).toBeLessThan(moveIdx);

    // 바로 옮길 때와 같은 페이지 상태 정리(오류, 인라인 추가 폼 플래그와 입력 버퍼)
    const directLocal: Record<string, any> = { ...(flatten(fallback.then).find((a) => a.params?.target === 'local')?.params ?? {}) };
    delete directLocal.target;
    const leaveLocal: Record<string, any> = {};
    for (const a of parentLocal) {
      const { target, value, ...rest } = a.params;
      if (target === '$parent._local') Object.assign(leaveLocal, rest);
      else leaveLocal[String(target).slice('$parent._local.'.length)] = value;
    }
    // 이중 쓰기(modal-usage.md §부모 폼 커밋): 엔진은 $parent._local 쓰기를 저장소 B 미러에서 일부러 빼 두므로
    // 같은 값을 전역 `_local.*`(저장, 저장 가드가 읽는 자리)에도 쓴다. 한쪽만 지우면 첫 입력에 다른 쪽 옛 값이 되살아난다(C7)
    const globalLocalWrites = actions.filter((x) => x.handler === 'setState' && x.params?.target === 'global' && Object.keys(x.params).some((k) => k.startsWith('_local.')));
    const globalLocal: Record<string, any> = {};
    for (const a of globalLocalWrites) {
      for (const [k, v] of Object.entries(a.params)) if (k.startsWith('_local.')) globalLocal[k.slice('_local.'.length)] = v;
      expect(actions.indexOf(a)).toBeLessThan(closeIdx);
    }
    expect(globalLocal).toEqual(leaveLocal);
    delete leaveLocal.hasChanges;
    expect(leaveLocal).toEqual(directLocal);

    expect(actions.find((a) => a.params?.target === 'global' && 'activeSettingsTab' in a.params)?.params.activeSettingsTab).toBe('{{_global.pendingSettingsTab}}');
    expect(actions[moveIdx]?.params).toMatchObject({ path: '/admin/settings', replace: true, query: { tab: '{{_global.pendingSettingsTab}}' } });
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
    for (const n of navigates) expect(n.params.path).toBe('/admin/settings');
    // 검색 버튼도 입력 중인 검색어를 쿼리로 싣는다
    expect(navigates.some((n) => String(n.params.query?.search ?? '').includes('_local.identityPolicySearch'))).toBe(true);
  });
  // @scenario case=tab_refetch_overlay_covers_inputs
  // @effects tab_refetch_overlay_covers_inputs
  it('탭 전환 재조회 동안 오버레이가 탭 본문을 덮는다(도착 전 입력이 응답에 말없이 덮이지 않게)', () => {
    // 쿼리만 바뀌는 탭 전환은 설정을 다시 받는다. 덮개가 탭 본문을 가리지 않으면 도착 전에 고친 칸이 응답에 덮였다(G2 실측 C9 — 게시판)
    const overlay = (layout as any).transition_overlay;
    expect(overlay?.target).toBe('settings_tab_content');
    expect(overlay?.wait_for).toContain('settings');
    expect(collect(layout, (n) => n.id === 'settings_tab_content').length).toBe(1);
  });
  // @scenario case=identity_list_refetches_list_only
  // @effects identity_list_ops_keep_settings_edits
  it('본인인증 정책 목록의 필터·검색·초기화·쪽 넘김은 목록 소스(policies)만 다시 받는다(위쪽 설정 칸에서 고친 값을 덮지 않게)', () => {
    // 목록 조작이 설정까지 다시 받으면 같은 탭 위쪽 설정 칸에서 고친 값이 서버 값으로 돌아가고 「변경 있음」이 풀렸다(7.1.0 조치 R1 ②, 2026-10-08 결정)
    const navigates = collect(identityTab, (n) => n.handler === 'navigate');
    expect(navigates.length).toBeGreaterThan(0);
    for (const n of navigates) {
      expect(n.params.replace).toBe(true);
      expect(n.params.refetch_data_sources).toBe('policies');
    }
    // 목록만 다시 받을 때도 목록 영역 덮개가 뜨도록 목록 소스가 덮개 대기 목록에 있다
    expect((layout as any).transition_overlay?.wait_for).toContain('policies');
    // 목록 소스는 이 레이아웃에 있는 데이터 소스여야 한다(오타면 아무것도 다시 받지 않는다)
    expect(((layout as any).data_sources ?? []).some((d: any) => d.id === 'policies')).toBe(true);
  });
});
