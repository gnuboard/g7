/**
 * @file admin-settings-member-display-name.test.tsx
 * @description 환경설정 「회원」 탭의 표시 이름 구획(#490 8단계)
 *
 * 구획은 회원이 고를 수 있는 종류(체크 목록과 종류별 미리보기), 회원 기본 종류, 자리(영역)별 방식과 종류를 다룬다.
 * 값은 설정 폼(`_local.form.member.display_name_*`)에 담고 화면 하단 「저장」이 회원 탭 몫을 한 번에 보낸다.
 * 고친 설정이 닉네임을 더 강하게 요구하게 되면 저장하기 전에 닉네임이 비어 있는 회원 수와 「로그인 시 닉네임 입력 요구」
 * 체크칸을 보인다(U12). 저장에 성공하면 항목 정의를 다시 받아 가입 항목 표의 닉네임 행 잠금이 새로고침 없이 바뀐다(T5-a).
 *
 * 레이아웃 JSON 은 파일에서 그대로 읽어 렌더한다(사본 금지). 계산값과 저장 본문은 실제 엔진의 평가기로도 계산한다.
 *
 * @scenario-manifest tests/scenarios/admin-settings-member-display-name.yaml
 */

import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { evaluateComputedDefinitions } from '@core/template-engine/helpers/ComputedEvaluator';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import {
  buildLayout,
  collect,
  field,
  fieldDefs,
  koTranslations,
  loadLayout,
  setupRegistry,
  settle,
  watch,
  type Json,
} from './support/memberTabHarness';

const settings = loadLayout('admin_settings.json');

/** 종류 표본 — 코어 다섯, 운영자 추가 항목 하나, 확장이 더한 종류 하나 */
function kinds(): Json[] {
  const kind = (key: string, label: string, preview: string, extra: Json = {}) => ({
    key, label, owner_type: 'core', owner: 'core', requires_nickname: false, searchable: true, fallback: 'masked_id', preview, ...extra,
  });

  return [
    kind('name', '이름', '홍길동'),
    kind('nickname', '닉네임', '길동이', { requires_nickname: true }),
    kind('masked_name', '가린 이름', '홍*동', { searchable: false }),
    kind('masked_id', '아이디 가림', 'gil***', { searchable: false }),
    kind('name_nickname', '이름(닉네임)', '홍길동(길동이)', { requires_nickname: true }),
    kind('field:alias', '활동명', '활동명', { owner_type: 'custom', owner: 'custom' }),
    kind('acme-rank:title', '칭호', '용사 길동이', { owner_type: 'plugin', owner: 'acme-rank' }),
  ];
}

/** 자리 표본 — 서버가 나무 순서로 펴서 준 모양 */
function areas(): Json[] {
  const area = (key: string, label: string, depth: number, mode: string, kind: string | null, extra: Json = {}) => ({
    key, label, parent: depth === 0 ? null : 'core:site', depth, audience: 'user', owner_type: 'core', owner: 'core', can_inherit: depth > 0,
    mode, kind, default: { mode, kind }, effective: { mode: 'fixed', kind: 'name', source: 'core:site' }, ...extra,
  });

  return [
    area('core:site', '사이트 기본', 0, 'fixed', 'name'),
    area('core:admin', '관리자 화면', 1, 'fixed', 'name', { audience: 'admin' }),
    area('sirsoft-board:admin', '게시판 관리', 2, 'inherit', null, { parent: 'core:admin', audience: 'admin', owner_type: 'module', owner: 'sirsoft-board' }),
    area('core:notification', '알림', 1, 'inherit', null, { audience: 'notification' }),
    area('core:notification:mail', '알림: 메일', 2, 'inherit', null, { parent: 'core:notification', audience: 'notification' }),
    area('sirsoft-board:posts', '게시판 글과 댓글', 1, 'member', null, { owner_type: 'module', owner: 'sirsoft-board', effective: { mode: 'member', kind: null, source: 'sirsoft-board:posts' } }),
  ];
}

function options(over: Json = {}): Json {
  return {
    data: {
      kinds: kinds(),
      areas: areas(),
      selectable_kinds: ['name', 'masked_name'],
      member_default_kind: 'name',
      sample: { name: '홍길동', nickname: '길동이', id: 'gildong' },
      nickname_requirement: 'none',
      ...over,
    },
  };
}

function emptyCount(over: Json = {}): Json {
  return { data: { nickname_empty_count: 12, nickname: { enabled: true, required: false, require_existing_members: false }, nickname_requirement: 'none', ...over } };
}

/** 설정 폼의 회원 몫(서버가 준 저장 값) */
function memberForm(over: Json = {}): Json {
  return {
    nickname_unique: false,
    nickname_banned_words: [],
    nickname_change_interval_days: 0,
    display_name_selectable_kinds: ['name', 'masked_name'],
    display_name_member_default_kind: 'name',
    display_name_areas: { 'core:site': { mode: 'fixed', kind: 'name' }, 'core:admin': { mode: 'fixed', kind: 'name' } },
    ...over,
  };
}

let active: ReturnType<typeof createLayoutTest> | null = null;

async function renderSection(opts: { partial?: string; member?: Json; local?: Json; global?: Json; options?: Json; empty?: Json; defs?: Json } = {}) {
  active = createLayoutTest(buildLayout(opts.partial ?? '_tab_member_display_name.json') as any, {
    componentRegistry: setupRegistry(),
    translations: koTranslations(),
    locale: 'ko',
    queryParams: { tab: 'member' },
    initialState: {
      _local: { form: { member: opts.member ?? memberForm() }, ...(opts.local ?? {}) },
      _global: { settingsAbilities: { can_update: true }, ...(opts.global ?? {}) },
    },
    initialData: {
      memberFieldDefs: opts.defs ?? fieldDefs(),
      memberNicknameRecompute: { data: { status: 'idle', processed: 0, total: 0 } },
      memberDisplayNameOptions: opts.options ?? options(),
      memberNicknameEmptyCount: opts.empty ?? emptyCount(),
    },
  } as any);
  const result = await active.render();
  watch(active);

  return { t: active, container: result.container };
}

/** 계산값 `memberDisplayNamePending` 을 실제 엔진의 평가기로 계산한다(풀지 못하면 실패). */
function pendingOf(member: Json, source: Json = options()): Json {
  const failures: string[] = [];
  const values = evaluateComputedDefinitions(
    { memberDisplayNamePending: settings.computed.memberDisplayNamePending },
    { _local: { form: { member } }, memberDisplayNameOptions: source },
    new DataBindingEngine(),
    { previous: {}, onError: 'undefined', failureLog: (name: string, error: unknown) => failures.push(`${name}: ${String(error)}`) } as any,
  );
  expect(failures).toEqual([]);

  return values.memberDisplayNamePending;
}

/** 하단 「저장」 단추의 저장 요청 액션 */
function saveCall(): Json {
  const button = collect(settings.slots, (n) => n.id === 'save_button')[0];

  return collect(button.actions, (n) => n.handler === 'apiCall' && n.target === '/api/admin/settings')[0];
}

/** 저장 본문을 실제 엔진의 식 평가기로 계산한다. */
function saveBody(member: Json, pending: Json): Json {
  const expression = String(saveCall().params.body).replace(/^\{\{/, '').replace(/\}\}$/, '');

  return new DataBindingEngine().evaluateExpression(
    expression,
    { _global: { activeSettingsTab: 'member' }, query: { tab: 'member' }, _local: { form: { member, _meta: {} } }, _computed: { memberDisplayNamePending: pending } } as any,
    { skipCache: true },
  );
}

const select = (testId: string) => screen.getByTestId(testId) as HTMLSelectElement;
const optionValues = (testId: string) => [...select(testId).options].map((o) => o.value);

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

describe('표시 이름 구획 — 데이터와 뼈대', () => {
  // @scenario case=datasources
  // @effects display_name_options_and_empty_count_fetched_only_on_member_tab
  it('종류와 자리 목록, 닉네임이 빈 회원 수는 회원 탭에서만 받는다', () => {
    const gate = "{{(query.tab || _global.activeSettingsTab || 'general') === 'member'}}";
    const optionsSource = settings.data_sources.find((s: Json) => s.id === 'memberDisplayNameOptions');
    expect(optionsSource.endpoint).toBe('/api/admin/member-fields/display-name-options');
    expect(optionsSource.method).toBe('GET');
    expect(optionsSource.auto_fetch).toBe(true);
    expect(optionsSource.auth_required).toBe(true);
    expect(optionsSource.if).toBe(gate);
    expect(optionsSource.fallback.data.kinds).toEqual([]);
    expect(optionsSource.fallback.data.areas).toEqual([]);
    expect(optionsSource.fallback.data.nickname_requirement).toBe('none');
    // 구획이 빈 채로 먼저 보이지 않게 탭 전환 덮개가 기다린다
    expect(settings.transition_overlay.wait_for).toContain('memberDisplayNameOptions');

    const countSource = settings.data_sources.find((s: Json) => s.id === 'memberNicknameEmptyCount');
    expect(countSource.endpoint).toBe('/api/admin/member-fields/nickname-empty-count');
    expect(countSource.auto_fetch).toBe(true);
    expect(countSource.if).toBe(gate);
    // 안내용 숫자라 덮개가 기다리지 않고, 받지 못해도 화면을 막지 않는다
    expect(countSource.loading_strategy).toBe('background');
    expect(settings.transition_overlay.wait_for).not.toContain('memberNicknameEmptyCount');
    expect(countSource.fallback.data.nickname_empty_count).toBe(0);
  });

  // @scenario case=render_kinds
  // @effects kinds_listed_with_preview_and_badges
  it('종류마다 체크칸, 미리보기(U15), 닉네임 필요와 확장 꼬리표를 그리고 번역 키를 그대로 내보이지 않는다', async () => {
    const { container } = await renderSection();

    expect(container.querySelector('#member_display_name_card')).not.toBeNull();
    for (const kind of kinds()) {
      expect(screen.getByTestId(`member-display-name-selectable-${kind.key}`), kind.key).toBeInTheDocument();
      expect(screen.getByTestId(`member-display-name-preview-${kind.key}`).textContent, kind.key).toBe(`→ ${kind.preview}`);
    }
    // 저장된 값대로 체크된다
    expect(screen.getByTestId('member-display-name-selectable-name')).toBeChecked();
    expect(screen.getByTestId('member-display-name-selectable-masked_name')).toBeChecked();
    expect(screen.getByTestId('member-display-name-selectable-nickname')).not.toBeChecked();

    const list = container.querySelector('#member_display_name_kind_list') as HTMLElement;
    // 닉네임이 필요한 종류 둘에만 꼬리표가 붙는다
    expect([...list.querySelectorAll('span')].filter((el) => el.textContent === '닉네임 필요')).toHaveLength(2);
    // 확장이 더한 종류만 출처를 보인다(코어와 운영자 추가 항목 종류는 없다)
    expect(screen.getByTestId('member-display-name-kind-owner-acme-rank:title').textContent).toBe('acme-rank');
    expect(screen.queryByTestId('member-display-name-kind-owner-name')).toBeNull();
    expect(screen.queryByTestId('member-display-name-kind-owner-field:alias')).toBeNull();
    // 미리보기 기준 회원
    expect(container.querySelector('#member_display_name_sample')?.textContent).toBe('미리보기 기준 회원: 이름 홍길동, 닉네임 길동이, 아이디 gildong');

    const text = container.textContent ?? '';
    expect(text).not.toMatch(/\$t[:(]/);
    expect(text).not.toMatch(/admin\.settings\./);
    expect(text).not.toMatch(/\{\{|\}\}/);
  });

  // @scenario case=member_default
  // @effects member_default_offers_only_selectable_kinds
  it('회원 기본 종류의 선택지는 지금 체크한 종류뿐이고, 회원 기본 종류로 쓰는 종류는 뺄 수 없게 잠긴다', async () => {
    const { t } = await renderSection();

    expect(optionValues('member-display-name-default-kind')).toEqual(['name', 'masked_name']);
    expect(select('member-display-name-default-kind').value).toBe('name');
    expect(screen.getByTestId('member-display-name-selectable-name')).toBeDisabled();
    expect(screen.getByTestId('member-display-name-selectable-masked_name')).not.toBeDisabled();

    fireEvent.change(select('member-display-name-default-kind'), { target: { value: 'masked_name' } });
    await settle();

    expect(t.getState()._local.form.member.display_name_member_default_kind).toBe('masked_name');
    expect(t.getState()._local.hasChanges).toBe(true);
    // 잠금이 새 회원 기본 종류로 옮겨 간다
    expect(screen.getByTestId('member-display-name-selectable-masked_name')).toBeDisabled();
    expect(screen.getByTestId('member-display-name-selectable-name')).not.toBeDisabled();
  });

  // @scenario case=toggle_kind
  // @effects selectable_kinds_written_to_member_form_in_kind_order
  it('종류를 체크하면 폼의 고를 수 있는 종류에 종류 목록 순서대로 들어가고, 풀면 빠진다', async () => {
    const { t } = await renderSection();

    fireEvent.click(screen.getByTestId('member-display-name-selectable-nickname'));
    await settle();
    expect(t.getState()._local.form.member.display_name_selectable_kinds).toEqual(['name', 'nickname', 'masked_name']);
    expect(t.getState()._local.hasChanges).toBe(true);
    // 회원 기본 종류의 선택지도 따라 늘어난다
    expect(optionValues('member-display-name-default-kind')).toEqual(['name', 'nickname', 'masked_name']);

    fireEvent.click(screen.getByTestId('member-display-name-selectable-masked_name'));
    await settle();
    expect(t.getState()._local.form.member.display_name_selectable_kinds).toEqual(['name', 'nickname']);
  });
});

describe('표시 이름 구획 — 자리별 방식', () => {
  // @scenario case=area_tree
  // @effects areas_drawn_as_indented_tree_with_owner_badge
  it('자리를 서버가 준 나무 순서대로, 깊이만큼 들여 그리고 확장이 선언한 자리는 출처를 보인다', async () => {
    const { container } = await renderSection();
    const rows = [...container.querySelectorAll('[data-testid^="member-display-name-area-"][data-depth]')] as HTMLElement[];

    expect(rows.map((row) => row.getAttribute('data-testid'))).toEqual(areas().map((a) => `member-display-name-area-${a.key}`));
    expect(rows.map((row) => row.getAttribute('data-depth'))).toEqual(['0', '1', '2', '1', '2', '1']);
    // 첫 칸의 왼쪽 여백이 깊이 x 16px 다(식을 엔진의 평가기로 계산한다 — 시험용 컴포넌트는 style 을 DOM 에 싣지 않는다)
    const partial = loadLayout('partials/admin_settings/_tab_member_display_name.json');
    const firstCell = collect(partial, (n) => n.iteration?.item_var === 'area')[0].children[0];
    const indent = (depth: unknown) =>
      new DataBindingEngine().evaluateExpression(String(firstCell.props.style).replace(/^\{\{/, '').replace(/\}\}$/, ''), { area: { depth } } as any, { skipCache: true });
    expect([0, 1, 2, undefined].map(indent)).toEqual(['padding-left:0px', 'padding-left:16px', 'padding-left:32px', 'padding-left:0px']);
    // 깊이가 있는 자리만 가지 표시를 앞에 둔다
    expect(rows.map((row) => (row.firstElementChild as HTMLElement).querySelector('[aria-hidden="true"]') !== null)).toEqual([false, true, true, true, true, true]);

    expect(screen.getByTestId('member-display-name-area-owner-sirsoft-board:posts').textContent).toBe('sirsoft-board');
    expect(screen.getByTestId('member-display-name-area-owner-sirsoft-board:admin').textContent).toBe('sirsoft-board');
    expect(screen.queryByTestId('member-display-name-area-owner-core:site')).toBeNull();
    // 자리 목록은 표 역할을 갖고, 방식 칸은 자리 이름이 든 접근 이름을 갖는다
    expect(container.querySelector('#member_display_name_area_list')?.getAttribute('role')).toBe('table');
    expect(select('member-display-name-area-mode-core:site').getAttribute('aria-label')).toBe('사이트 기본 방식');
  });

  // @scenario case=area_modes
  // @effects area_mode_options_and_kind_select_follow_mode
  it('사이트 기본 자리에는 「따름」이 없고, 종류 칸은 고정일 때만 나온다', async () => {
    await renderSection();

    expect(optionValues('member-display-name-area-mode-core:site')).toEqual(['fixed', 'member']);
    expect(optionValues('member-display-name-area-mode-core:admin')).toEqual(['inherit', 'fixed', 'member']);
    // 지금 값: 폼에 있으면 폼, 없으면 서버가 준 값
    expect(select('member-display-name-area-mode-core:site').value).toBe('fixed');
    expect(select('member-display-name-area-mode-core:notification').value).toBe('inherit');
    expect(select('member-display-name-area-mode-sirsoft-board:posts').value).toBe('member');

    // 고정인 자리만 종류 칸이 있고 모든 종류를 고를 수 있다
    expect(optionValues('member-display-name-area-kind-core:site')).toEqual(kinds().map((k) => k.key));
    expect(select('member-display-name-area-kind-core:site').value).toBe('name');
    expect(screen.queryByTestId('member-display-name-area-kind-core:notification')).toBeNull();
    expect(screen.queryByTestId('member-display-name-area-kind-sirsoft-board:posts')).toBeNull();
    // 따름과 회원이 고른 값은 지금 무엇으로 보이는지 곁들인다
    expect(screen.getByTestId('member-display-name-area-note-core:notification').textContent).toBe('지금 적용: 이름');
    expect(screen.getByTestId('member-display-name-area-note-sirsoft-board:posts').textContent).toBe('고르지 않은 회원은 이름');
  });

  // @scenario case=area_change
  // @effects area_setting_written_as_whole_object_to_member_form
  it('방식과 종류를 바꾸면 자리 설정 객체가 통째로 폼에 담기고 다른 자리의 값은 그대로다', async () => {
    const { t } = await renderSection();
    const areasOf = () => t.getState()._local.form.member.display_name_areas;

    // 따름 → 고정: 종류가 함께 담긴다(전에 고른 종류가 없으면 지금 적용되는 종류)
    fireEvent.change(select('member-display-name-area-mode-core:notification'), { target: { value: 'fixed' } });
    await settle();
    expect(areasOf()['core:notification']).toEqual({ mode: 'fixed', kind: 'name' });
    expect(areasOf()['core:site']).toEqual({ mode: 'fixed', kind: 'name' });
    expect(t.getState()._local.hasChanges).toBe(true);
    // 종류 칸이 나타나고, 고르면 그 종류로 고정된다
    fireEvent.change(select('member-display-name-area-kind-core:notification'), { target: { value: 'nickname' } });
    await settle();
    expect(areasOf()['core:notification']).toEqual({ mode: 'fixed', kind: 'nickname' });

    // 고정 → 회원이 고른 값: 종류를 비운다
    fireEvent.change(select('member-display-name-area-mode-core:site'), { target: { value: 'member' } });
    await settle();
    expect(areasOf()['core:site'].mode).toBe('member');
    expect(areasOf()['core:site'].kind ?? null).toBeNull();
    expect(screen.queryByTestId('member-display-name-area-kind-core:site')).toBeNull();
    // 고치지 않은 자리는 그대로다
    expect(areasOf()['core:admin']).toEqual({ mode: 'fixed', kind: 'name' });
    // 고친 뒤에는 따름인 자리의 「지금 적용」이 저장 전 값이라 문구를 바꾼다
    expect(screen.getByTestId('member-display-name-area-note-core:notification:mail').textContent).toBe('저장하면 상위 영역의 설정을 따릅니다');
  });
});

/** 계산값 `memberDefaultKindUsage` 를 실제 엔진의 평가기로 계산한다(풀지 못하면 실패). */
function usageOf(member: Json, source: Json = options()): string[] {
  const failures: string[] = [];
  const values = evaluateComputedDefinitions(
    { memberDefaultKindUsage: settings.computed.memberDefaultKindUsage },
    { _local: { form: { member } }, memberDisplayNameOptions: source },
    new DataBindingEngine(),
    { previous: {}, onError: 'undefined', failureLog: (name: string, error: unknown) => failures.push(`${name}: ${String(error)}`) } as any,
  );
  expect(failures).toEqual([]);

  return values.memberDefaultKindUsage;
}

describe('표시 이름 구획 — 회원 기본 종류를 쓰는 곳', () => {
  // @scenario case=default_kind_usage
  // @effects default_kind_usage_follows_form_and_inheritance
  it('방식이 「회원이 고른 값」인 자리와, 상위를 따라 그렇게 된 자리를 폼 값 기준으로 나무 순서대로 모은다', () => {
    // 저장된 그대로: 게시판 글 자리만 「회원이 고른 값」
    expect(usageOf(memberForm())).toEqual(['게시판 글과 댓글']);
    // 사이트 기본을 「회원이 고른 값」으로 바꾸면 따르는 자리(알림, 메일)도 함께 들어가고 「이름」 고정인 관리자 쪽은 빠진다
    expect(usageOf(memberForm({ display_name_areas: { 'core:site': { mode: 'member' }, 'core:admin': { mode: 'fixed', kind: 'name' } } })))
      .toEqual(['사이트 기본', '알림', '알림: 메일', '게시판 글과 댓글']);
    // 관리자 화면을 「따름」으로 바꾸면 게시판 관리까지 따라온다
    expect(usageOf(memberForm({ display_name_areas: { 'core:site': { mode: 'member' }, 'core:admin': { mode: 'inherit' } } })))
      .toEqual(['사이트 기본', '관리자 화면', '게시판 관리', '알림', '알림: 메일', '게시판 글과 댓글']);
    // 자리는 고정이어도 그 자리의 인스턴스(게시판마다 설정)가 「회원이 고른 값」이면 「자리 · 인스턴스」로 든다
    const withInstance = options({
      areas: areas().map((a) => (a.key === 'sirsoft-board:posts' ? { ...a, mode: 'fixed', kind: 'name', member_instances: ['자유게시판'] } : a)),
    });
    expect(usageOf(memberForm(), withInstance)).toEqual(['게시판 글과 댓글 · 자유게시판']);
    // 쓰는 곳이 없으면 빈 목록, 목록을 아직 못 받았어도 풀린다
    expect(usageOf(memberForm(), options({ areas: areas().filter((a) => a.mode !== 'member') }))).toEqual([]);
    expect(usageOf(memberForm(), { data: {} })).toEqual([]);
  });

  // @scenario case=default_kind_usage
  // @effects default_kind_usage_line_names_areas
  it('회원 기본 종류 아래에 이 값을 쓰는 영역을 보이고, 넷 이상이면 셋까지만 이름을 대고 나머지는 개수로 줄인다', async () => {
    await renderSection();
    expect(screen.getByTestId('member-display-name-default-kind-usage').textContent).toBe('지금 이 값을 쓰는 영역: 게시판 글과 댓글');
    expect(screen.queryByTestId('member-display-name-default-kind-unused')).toBeNull();

    // 사이트 기본을 「회원이 고른 값」으로 바꾸면 저장 전에 바로 늘어난다
    fireEvent.change(select('member-display-name-area-mode-core:site'), { target: { value: 'member' } });
    await settle();
    expect(screen.getByTestId('member-display-name-default-kind-usage').textContent).toBe('지금 이 값을 쓰는 영역: 사이트 기본, 알림, 알림: 메일 외 1곳');
  });

  // @scenario case=default_kind_usage
  // @effects default_kind_unused_warning
  it('이 값을 쓰는 영역이 하나도 없으면 바꿔도 화면에 변화가 없다고 알린다', async () => {
    await renderSection({ options: options({ areas: areas().filter((a) => a.mode !== 'member') }) });

    expect(screen.queryByTestId('member-display-name-default-kind-usage')).toBeNull();
    expect(screen.getByTestId('member-display-name-default-kind-unused').textContent)
      .toBe('지금은 이 값을 쓰는 영역이 없습니다. 이 값은 방식이 「회원이 고른 값」인 영역에서만 쓰입니다.');
  });
});

describe('표시 이름 구획 — 닉네임 안내(U12)', () => {
  // @scenario case=pending_level
  // @effects pending_nickname_requirement_computed_from_form
  it('고친 설정이 닉네임에 거는 요구 수준을 폼에서 계산한다(전원 필수, 고른 회원만, 없음)', () => {
    // 저장된 그대로면 요구가 없다
    expect(pendingOf(memberForm())).toEqual({ level: 'none', saved: 'none', raised: false });
    // 어떤 자리가 닉네임이 필요한 종류로 고정되면 전원 필수
    expect(pendingOf(memberForm({ display_name_areas: { 'core:site': { mode: 'fixed', kind: 'nickname' } } }))).toEqual({ level: 'all', saved: 'none', raised: true });
    expect(pendingOf(memberForm({ display_name_areas: { 'core:notification': { mode: 'fixed', kind: 'name_nickname' } } })).level).toBe('all');
    // 「회원이 고른 값」 자리가 있고(게시판 자리가 서버 값으로 member) 고를 수 있는 종류에 닉네임이 필요한 종류가 있으면 고른 회원만
    expect(pendingOf(memberForm({ display_name_selectable_kinds: ['name', 'nickname'] }))).toEqual({ level: 'chosen', saved: 'none', raised: true });
    expect(pendingOf(memberForm({ display_name_member_default_kind: 'nickname' })).level).toBe('chosen');
    // 「회원이 고른 값」 자리가 하나도 없으면 고를 수 있는 종류는 어디에도 보이지 않는다
    const noMemberArea = options({ areas: areas().filter((a) => a.mode !== 'member') });
    expect(pendingOf(memberForm({ display_name_selectable_kinds: ['name', 'nickname'] }), noMemberArea).level).toBe('none');
    // 추가 항목 종류와 가린 종류는 닉네임을 요구하지 않는다
    expect(pendingOf(memberForm({ display_name_areas: { 'core:site': { mode: 'fixed', kind: 'field:alias' } } })).level).toBe('none');

    // 이미 같은 수준이 저장돼 있으면 올라가는 것이 아니다
    expect(pendingOf(memberForm({ display_name_selectable_kinds: ['name', 'nickname'] }), options({ nickname_requirement: 'chosen' })).raised).toBe(false);
    expect(pendingOf(memberForm({ display_name_areas: { 'core:site': { mode: 'fixed', kind: 'nickname' } } }), options({ nickname_requirement: 'chosen' })).raised).toBe(true);
    expect(pendingOf(memberForm(), options({ nickname_requirement: 'all' })).raised).toBe(false);
    // 종류와 자리 목록을 아직 못 받았어도 풀린다
    expect(pendingOf(memberForm(), { data: {} })).toEqual({ level: 'none', saved: 'none', raised: false });
  });

  // @scenario case=notice_hidden
  // @effects nickname_notice_hidden_until_requirement_rises
  it('닉네임 요구가 올라가지 않으면 안내를 그리지 않는다', async () => {
    const { container } = await renderSection();

    expect(container.querySelector('#member_display_name_nickname_notice')).toBeNull();
    expect(screen.queryByTestId('member-display-name-require-existing')).toBeNull();
  });

  // @scenario case=notice_all
  // @effects nickname_notice_shows_lock_message_empty_count_and_require_checkbox
  it('자리를 닉네임 종류로 고정하면 저장 전에 고정 안내, 닉네임이 빈 회원 수, 「로그인 시 닉네임 입력 요구」 체크칸이 나온다', async () => {
    const { t, container } = await renderSection();

    fireEvent.change(select('member-display-name-area-kind-core:site'), { target: { value: 'nickname' } });
    await settle();

    const notice = container.querySelector('#member_display_name_nickname_notice') as HTMLElement;
    expect(notice).not.toBeNull();
    expect(notice.getAttribute('role')).toBe('alert');
    expect(screen.getByTestId('member-display-name-nickname-message').textContent).toBe('저장하면 닉네임 항목이 사용, 필수로 고정됩니다.');
    expect(screen.getByTestId('member-display-name-nickname-empty-count').textContent).toBe('지금 닉네임이 비어 있는 회원은 12명입니다.');

    const check = screen.getByTestId('member-display-name-require-existing');
    expect(check).not.toBeChecked();
    fireEvent.click(check);
    await settle();
    expect(t.getState()._local.form.member.nickname_require_existing_members).toBe(true);
    expect(screen.getByTestId('member-display-name-require-existing')).toBeChecked();

    // 설정을 되돌리면 안내가 거둬진다
    fireEvent.change(select('member-display-name-area-kind-core:site'), { target: { value: 'name' } });
    await settle();
    expect(container.querySelector('#member_display_name_nickname_notice')).toBeNull();
  });

  // @scenario case=notice_chosen
  // @effects nickname_notice_for_chosen_level_has_no_require_checkbox
  it('고를 수 있는 종류에 닉네임 종류를 넣으면 「사용으로 고정」 안내만 나오고 입력 요구 체크칸은 없다', async () => {
    await renderSection();

    fireEvent.click(screen.getByTestId('member-display-name-selectable-nickname'));
    await settle();

    expect(screen.getByTestId('member-display-name-nickname-message').textContent).toBe(
      '저장하면 닉네임 항목이 사용으로 고정되고, 닉네임이 필요한 종류로 보이는 회원은 닉네임이 필수가 됩니다.',
    );
    expect(screen.getByTestId('member-display-name-nickname-empty-count').textContent).toBe('지금 닉네임이 비어 있는 회원은 12명입니다.');
    expect(screen.queryByTestId('member-display-name-require-existing')).toBeNull();
  });

  // @scenario case=notice_already_required
  // @effects nickname_notice_tells_when_existing_members_already_required
  it('기존 회원에게 이미 닉네임 입력을 요구하고 있으면 체크칸 대신 그 사실을 알린다', async () => {
    await renderSection({ empty: emptyCount({ nickname: { enabled: true, required: true, require_existing_members: true } }) });

    fireEvent.change(select('member-display-name-area-kind-core:site'), { target: { value: 'nickname' } });
    await settle();

    expect(screen.queryByTestId('member-display-name-require-existing')).toBeNull();
    expect(screen.getByTestId('member-display-name-require-existing-on').textContent).toBe('기존 회원에게도 닉네임 입력을 이미 요구하고 있습니다.');
  });
});

describe('표시 이름 구획 — 저장', () => {
  // @scenario case=save_body
  // @effects save_body_carries_display_name_keys_under_member
  it('저장 본문은 회원 탭 몫에 고를 수 있는 종류, 회원 기본 종류, 자리 설정을 싣는다', () => {
    const call = saveCall();
    expect(call.params.method).toBe('POST');

    const member = memberForm({
      display_name_selectable_kinds: ['name', 'nickname'],
      display_name_member_default_kind: 'nickname',
      display_name_areas: { 'core:site': { mode: 'member', kind: null }, 'core:admin': { mode: 'fixed', kind: 'name' } },
    });
    const body = saveBody(member, { level: 'chosen', saved: 'none', raised: true });

    expect(body._tab).toBe('member');
    expect(body.member.display_name_selectable_kinds).toEqual(['name', 'nickname']);
    expect(body.member.display_name_member_default_kind).toBe('nickname');
    expect(body.member.display_name_areas).toEqual({ 'core:site': { mode: 'member', kind: null }, 'core:admin': { mode: 'fixed', kind: 'name' } });
    // 닉네임 정책 값도 같은 묶음으로 함께 간다
    expect(body.member.nickname_unique).toBe(false);
    expect(body.member.nickname_change_interval_days).toBe(0);
    expect('nickname_require_existing_members' in body.member).toBe(false);
  });

  // @scenario case=save_require_existing
  // @effects require_existing_directive_sent_only_while_notice_requires_all
  it('「로그인 시 닉네임 입력 요구」 지시 값은 안내가 보이고 전원 필수가 되는 저장에만 싣는다', () => {
    const member = memberForm({ nickname_require_existing_members: true, display_name_areas: { 'core:site': { mode: 'fixed', kind: 'nickname' } } });

    expect(saveBody(member, { level: 'all', saved: 'none', raised: true }).member.nickname_require_existing_members).toBe(true);
    // 체크해 둔 뒤 설정을 되돌렸거나(올라가지 않음) 고른 회원만 필수인 저장에는 싣지 않는다
    expect('nickname_require_existing_members' in saveBody(member, { level: 'none', saved: 'none', raised: false }).member).toBe(false);
    expect('nickname_require_existing_members' in saveBody(member, { level: 'chosen', saved: 'none', raised: true }).member).toBe(false);
    expect('nickname_require_existing_members' in saveBody(member, { level: 'all', saved: 'all', raised: false }).member).toBe(false);
    // 체크하지 않았으면 싣지 않는다
    expect('nickname_require_existing_members' in saveBody(memberForm(), { level: 'all', saved: 'none', raised: true }).member).toBe(false);
  });

  // @scenario case=save_refetch
  // @effects save_success_refetches_field_defs_and_display_name_sources
  it('T5-a: 저장에 성공하면 「변경 있음」을 끄고 항목 정의, 종류와 자리 목록, 닉네임이 빈 회원 수를 다시 받는다', () => {
    const call = saveCall();
    const success = Array.isArray(call.onSuccess) ? call.onSuccess : [call.onSuccess];
    const memberOnly = "{{(_global.activeSettingsTab || query.tab) === 'member'}}";

    const clears = success.find((a: Json) => a.handler === 'setState' && a.params.target === 'local');
    expect(clears.params.hasChanges).toBe(false);
    expect(clears.params.isSaving).toBe(false);

    const refetched = success.filter((a: Json) => a.handler === 'refetchDataSource');
    // 설정 폼은 서버 값으로 되맞춘다(체크해 둔 지시 값도 이때 사라진다)
    expect(refetched.some((a: Json) => a.params.dataSourceId === 'settings' && !a.if)).toBe(true);
    for (const id of ['memberFieldDefs', 'memberDisplayNameOptions', 'memberNicknameEmptyCount']) {
      const action = refetched.find((a: Json) => a.params.dataSourceId === id);
      expect(action, id).toBeTruthy();
      expect(action.if, id).toBe(memberOnly);
    }
  });

  // @scenario case=errors
  // @effects validation_errors_shown_under_their_blocks
  it('저장 오류는 해당 칸 아래에 뜬다(고를 수 있는 종류, 회원 기본 종류, 자리)', async () => {
    const { container } = await renderSection({
      local: {
        errors: {
          'member.display_name_selectable_kinds.1': ['없는 종류입니다.'],
          'member.display_name_member_default_kind': ['고를 수 있는 종류 가운데 하나여야 합니다.'],
          'member.display_name_areas.core:notification.kind': ['종류를 골라 주세요.'],
        },
      },
    });

    expect(container.querySelector('#field_display_name_selectable_kinds .form-error')?.textContent).toBe('없는 종류입니다.');
    expect(container.querySelector('#field_display_name_member_default_kind .form-error')?.textContent).toBe('고를 수 있는 종류 가운데 하나여야 합니다.');
    const row = screen.getByTestId('member-display-name-area-core:notification');
    expect(row.querySelector('.form-error')?.textContent).toBe('종류를 골라 주세요.');
    // 이름이 앞부분만 같은 다른 자리(알림: 메일)에는 붙지 않는다
    expect(screen.getByTestId('member-display-name-area-core:notification:mail').querySelector('.form-error')).toBeNull();
    expect(screen.getByTestId('member-display-name-area-core:site').querySelector('.form-error')).toBeNull();
  });

  // @scenario case=readonly
  // @effects readonly_admin_cannot_change_display_name_settings
  it('조회 권한만 있는 관리자에게는 체크칸과 선택 칸이 모두 잠긴다', async () => {
    const { container } = await renderSection({ global: { settingsAbilities: { can_update: false } } });

    const controls = [...container.querySelectorAll('#member_display_name_card input[type="checkbox"], #member_display_name_card select')] as Array<HTMLInputElement | HTMLSelectElement>;
    expect(controls.length).toBeGreaterThan(10);
    controls.forEach((control) => expect(control.disabled, control.getAttribute('data-testid') ?? '').toBe(true));
  });
});

describe('표시 이름 구획 — 가입 항목 표의 닉네임 잠금', () => {
  const lockMessage = '표시 이름 설정이 닉네임을 쓰고 있어 바꿀 수 없습니다.';
  const lockedDefs = () => {
    const defs = fieldDefs();
    defs.data.fields = [
      field({ id: 1, key: 'name', label: '이름', required: true, sort_order: 10 }),
      field({ id: 2, key: 'nickname', label: '닉네임', enabled: true, required: true, sort_order: 20, locks: { enabled: lockMessage, required: lockMessage }, lock_reasons: { enabled: 'nickname_display', required: 'nickname_display' } }),
      field({ id: 3, key: 'mobile', label: '휴대폰', sort_order: 30, locks: { required: '선언한 쪽이 잠갔습니다.' }, lock_reasons: { required: 'declared' } }),
    ];

    return defs;
  };

  // @scenario case=lock_link_table
  // @effects nickname_display_lock_shows_reason_and_link_to_display_name_section
  it('표시 이름 설정 때문에 잠긴 닉네임 행은 스위치가 잠기고 사유와 표시 이름 구획으로 가는 단추를 보인다', async () => {
    await renderSection({ partial: '_tab_member_fields.json', defs: lockedDefs() });

    expect(screen.getByTestId('member-field-table-enabled-nickname')).toBeDisabled();
    expect(screen.getByTestId('member-field-table-required-nickname')).toBeDisabled();
    expect(screen.getAllByText(lockMessage)).toHaveLength(1);
    expect(screen.getByTestId('member-field-table-lock-link-nickname').textContent).toBe('표시 이름 설정 보기');
    // 다른 사유로 잠긴 행과 잠기지 않은 행에는 단추가 없다
    expect(screen.queryByTestId('member-field-table-lock-link-mobile')).toBeNull();
    expect(screen.queryByTestId('member-field-table-lock-link-name')).toBeNull();

    // 단추는 같은 화면의 표시 이름 구획으로 스크롤한다
    const table = loadLayout('partials/admin_settings/_tab_member_fields.json');
    const link = collect(table, (n) => typeof n.props?.['data-testid'] === 'string' && n.props['data-testid'].includes('member-field-table-lock-link-'))[0];
    const scroll = collect(link.actions, (n) => n.handler === 'scrollIntoView')[0];
    expect(scroll.params.selector).toBe('#member_section_display_name');
  });

  // @scenario case=lock_link_cards
  // @effects nickname_display_lock_link_mirrored_in_cards
  it('좁은 화면 카드에도 같은 단추가 있다', async () => {
    await renderSection({ partial: '_tab_member_fields_cards.json', defs: lockedDefs() });

    expect(screen.getByTestId('member-field-card-lock-link-nickname').textContent).toBe('표시 이름 설정 보기');
    expect(screen.queryByTestId('member-field-card-lock-link-mobile')).toBeNull();
    expect(screen.getByTestId('member-field-card-enabled-nickname')).toBeDisabled();
  });
});
