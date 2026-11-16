/**
 * @file admin-user-form-member-fields.test.tsx
 * @description 관리자 회원 폼과 회원 상세의 회원 항목 칸 (#490 6단계)
 *
 * 이름, 닉네임, 연락처, 위치, 서명, 자기소개와 운영자가 추가한 항목은 서버가 준 항목 계약(데이터소스 `memberFields`)대로
 * 공용 조각(partials/member/_member_fields, _member_fields_view)이 그린다. 이 파일이 잠그는 것:
 * 칸의 DOM id(`field_{키}`), 범위 배지, 잠금 안내, 주소 갈래의 주소 검색 확장점, 파일 갈래의 관리자 API 주소,
 * 회원 추가 화면의 「저장한 뒤 올릴 수 있습니다」 안내, 새 구획(추가 항목, 확장 항목)과 탭의 조건,
 * 오류가 난 칸이 든 탭의 빨간 점(U22), 수정과 상세 화면의 회원 항목 상태 줄(U17).
 *
 * 레이아웃 JSON 은 파일에서 그대로 읽는다(사본 금지). 식은 구운 JSON 의 것을 계산하고, 화면은 실제 엔진으로 그린다.
 *
 * @scenario-manifest tests/scenarios/admin-user-form-member-fields.yaml
 */

import React from 'react';
import { describe, it, expect, afterEach, beforeEach, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { createLayoutTest } from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { actionsOf, callbacksOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { evaluateComputedDefinitions } from '@core/template-engine/helpers/ComputedEvaluator';
import { DataBindingEngine } from '@core/template-engine/DataBindingEngine';
import { resolveExpressionString } from '@core/template-engine/helpers/RenderHelpers';
import { koTranslations, loadLayout, resolveIncludes, setupRegistry, settle, watch, type Json } from './support/memberTabHarness';
import { MemberFieldInput } from '../../src/components/composite/MemberFieldInput';
import { TabNavigationScroll } from '../../src/components/composite/TabNavigationScroll';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';

// 엔진(코어)은 저장소 루트의 react 를, 실물 부품(MemberFieldInput, TabNavigationScroll)은 이 템플릿 node_modules 의 react 를 읽어 사본이 둘이 된다 — 루트 사본으로 맞춘다
vi.mock('react', async () => {
  const p = await import('path');
  const u = await import('url');
  const dir = p.dirname(u.fileURLToPath(import.meta.url));

  return import(/* @vite-ignore */ u.pathToFileURL(p.resolve(dir, '../../../../../node_modules/react/index.js')).href);
});

const form = loadLayout('admin_user_form.json');
const detail = loadLayout('admin_user_detail.json');
const inputPartial = loadLayout('partials/member/_member_fields.json');
const viewPartial = loadLayout('partials/member/_member_fields_view.json');

/** 트리의 노드를 나온 순서대로 모은다(자식, 슬롯, 탭 항목 포함). */
function flatten(node: any, out: Json[] = []): Json[] {
  if (Array.isArray(node)) {
    node.forEach((child) => flatten(child, out));
    return out;
  }
  if (node && typeof node === 'object') {
    out.push(node);
    Object.values(node).forEach((value) => flatten(value, out));
  }
  return out;
}

const byId = (tree: any, id: string): Json | undefined => flatten(tree).find((node) => node.id === id && (node.type || node.name));

/** 표현식({{ }})을 주어진 변수로 계산한다. */
function evaluate(expression: string, scope: Record<string, unknown>): any {
  const body = String(expression).replace(/^\{\{/, '').replace(/\}\}$/, '');
  // eslint-disable-next-line no-new-func
  return new Function(...Object.keys(scope), 'return (' + body + ');')(...Object.values(scope));
}

/**
 * 레이아웃의 계산값 하나를 엔진의 평가기로 계산한다.
 *
 * 식 엔진은 자바스크립트 전체를 받지 않는다(함수 본문의 대입문 등) — `new Function` 으로 계산하면 엔진이 못 푸는 식이 통과한다.
 * 그래서 계산값은 실제 앱과 같은 평가기로 풀고, 풀지 못한 식이 하나라도 있으면 실패로 본다.
 */
function computed(layout: Json, key: string, context: Json): any {
  const failures: string[] = [];
  const values = evaluateComputedDefinitions(layout.computed, context, new DataBindingEngine(), {
    previous: {},
    onError: 'undefined',
    failureLog: (name: string, error: unknown) => failures.push(`${name}: ${String(error)}`),
  } as any);
  expect(failures).toEqual([]);
  return values[key];
}

/** 항목 계약 표본 한 건(관리자 항목 API 모양). */
function contract(overrides: Json): Json {
  return {
    type: 'text',
    renderer: 'builtin',
    owner: 'core',
    owner_type: 'core',
    label: overrides.key,
    placeholder: null,
    help: null,
    required: false,
    readonly: false,
    locked_reason: null,
    deferred: false,
    options: [],
    constraints: {},
    default: null,
    ui: {},
    parts: null,
    display_value: null,
    files: null,
    file_rules: null,
    edit_scope: 'self',
    name: overrides.key,
    ...overrides,
  };
}

const custom = (overrides: Json): Json => contract({ owner: 'custom', owner_type: 'custom', name: `custom_fields.${overrides.key}`, ...overrides });

/** 표본 항목 — 코어 묶음 넷, 운영자 추가 항목 셋(글, 관리자만 열람, 파일), 확장 선언 항목 하나. */
function sampleFields(): Json[] {
  return [
    contract({ key: 'name', label: '이름', required: true, ui: { group: 'basic' }, display_value: '홍길동' }),
    contract({ key: 'nickname', label: '닉네임', ui: { group: 'basic' }, display_value: '길동이' }),
    contract({ key: 'mobile', label: '휴대폰', type: 'phone', ui: { group: 'contact' }, display_value: '010-1111-2222' }),
    contract({ key: 'language', label: '언어', type: 'choice', options: [{ value: 'ko', label: '한국어' }, { value: 'en', label: 'English' }], constraints: { display: 'select' }, default: 'ko', ui: { group: 'location' }, display_value: '한국어' }),
    contract({ key: 'address', label: '주소', type: 'address', renderer: 'address', ui: { group: 'location' }, parts: { zipcode: 'zipcode', address: 'address', address_detail: 'address_detail' }, display_value: '(04524) 서울 중구 세종대로 110' }),
    contract({ key: 'bio', label: '자기소개', type: 'textarea', ui: { group: 'profile' }, display_value: '첫 줄\n둘째 줄' }),
    custom({ key: 'job', label: '직업', required: true, display_value: '직장인' }),
    custom({ key: 'grade', label: '내부 등급', edit_scope: 'admin_only', display_value: 'A' }),
    custom({ key: 'license', label: '사업자등록증', type: 'file', renderer: 'file', edit_scope: 'admin_edit', display_value: 'biz.pdf', files: [{ id: 7, name: 'biz.pdf', url: '/api/attachment/abc' }], file_rules: { extensions: ['pdf'], max_count: 2, max_size_mb: 10 } }),
    contract({ key: 'preferred_currency', name: 'ecommerce_preferred_currency', label: '결제 통화', type: 'choice', owner: 'sirsoft-ecommerce', owner_type: 'module', options: [{ value: 'KRW', label: 'KRW' }], display_value: 'KRW' }),
  ];
}

const fieldsResponse = (fields: Json[] = sampleFields(), member: Json | null = null): Json => ({ data: { fields, fields_version: 'v1', member } });

/**
 * 본 레이아웃의 노드 몇 개를 계산값과 함께 렌더 가능한 레이아웃으로 감싼다(조각 참조는 실제 조각으로 푼다).
 *
 * 회원 폼의 칸은 본문을 감싼 노드의 폼 상태 이름(dataKey)으로 값을 주고받는다 — 그 노드의 속성을 그대로 가져와 감싼다.
 */
function buildFrom(layout: Json, ids: string[]): Json {
  const children = ids.map((id) => {
    const node = byId(layout.slots, id);
    if (!node) throw new Error(`노드를 찾지 못했습니다: ${id}`);
    return resolveIncludes(node);
  });
  const formRoot = byId(layout.slots, 'user_form_content');

  return {
    version: '1.0.0',
    layout_name: 'test_admin_user_member_fields',
    computed: layout.computed,
    components: formRoot
      ? [{ id: 'test_form_root', type: 'basic', name: 'Div', dataKey: formRoot.dataKey, trackChanges: formRoot.trackChanges, children }]
      : children,
  };
}

let active: ReturnType<typeof createLayoutTest> | null = null;

/** 폼 또는 상세의 구획을 실제 엔진으로 그린다. */
async function renderNodes(layout: Json, ids: string[], opts: { route?: Record<string, string>; fields?: Json; local?: Json; user?: Json } = {}) {
  const composite = (name: string, component: any) => ({ component, metadata: { name, type: 'composite' } });
  active = createLayoutTest(buildFrom(layout, ids) as any, {
    componentRegistry: setupRegistry({
      MemberFieldInput: composite('MemberFieldInput', MemberFieldInput),
      TabNavigationScroll: composite('TabNavigationScroll', TabNavigationScroll),
    }),
    translations: koTranslations(),
    locale: 'ko',
    routeParams: opts.route ?? {},
    initialState: { _local: opts.local ?? { form: {} }, _global: {} },
    initialData: { memberFields: opts.fields ?? fieldsResponse(), user: opts.user ?? { data: {} } },
  } as any);
  const result = await active.render();
  watch(active);
  await settle();
  return result;
}

/** 한국어 문구 묶음에서 키를 찾아 `{{이름}}` 자리를 채운다(실제 앱의 `G7Core.t` 와 같은 결과). */
function translateKo(key: string, params: Record<string, string | number> = {}): string {
  const found = key.split('.').reduce<any>((node, part) => (node && typeof node === 'object' ? node[part] : undefined), koTranslations());
  return typeof found === 'string' ? found.replace(/{{(w+)}}/g, (_m, name) => String(params[name] ?? '')) : key;
}

beforeEach(() => {
  // 실물 부품(MemberFieldInput)은 자기 문구를 `G7Core.t` 로 읽는다. 테스트 도구가 렌더할 때 세우는 가짜 G7Core 에는 번역기가 없어
  // 키가 그대로 보이므로, G7Core 가 세워질 때마다 한국어 번역기를 얹는다.
  let core: any = (window as any).G7Core;
  Object.defineProperty(window, 'G7Core', {
    configurable: true,
    get: () => core,
    set: (value: any) => {
      core = value;
      if (core && typeof core === 'object') core.t = translateKo;
    },
  });
  // 탭 막대의 스크롤 감시가 쓰는 관찰자 — jsdom 에는 없다
  vi.stubGlobal(
    'IntersectionObserver',
    class {
      observe() {}
      unobserve() {}
      disconnect() {}
    },
  );
});

afterEach(() => {
  active?.cleanup();
  active = null;
  watch(null);
  vi.unstubAllGlobals();
  const core = (window as any).G7Core;
  Object.defineProperty(window, 'G7Core', { configurable: true, writable: true, value: core });
});

const inputNodes = flatten(inputPartial);
const viewNodes = flatten(viewPartial);
const row = inputPartial.children[0];

describe('회원 항목 반복 조각 (관리자, 입력)', () => {
  /**
   * @scenario case=root
   * @effects root_id_and_layout_follow_group
   */
  it('루트 노드 id 는 묶음 이름을 따르고, 칸을 늘어놓는 클래스는 묶음이 정한다(768px 이상은 wideClassName)', () => {
    expect(inputPartial.id).toBe('member_fields_group_{{memberFieldGroup.key}}');
    const base = inputPartial.props.className as string;
    expect(evaluate(base, { memberFieldGroup: { key: 'extra' } })).toBe('row-stack');
    expect(evaluate(base, { memberFieldGroup: { key: 'basic', className: 'grid grid-cols-1 gap-4', wideClassName: 'grid grid-cols-2 gap-4' } })).toBe('grid grid-cols-1 gap-4');

    const wide = Object.values(inputPartial.responsive as Json)[0] as Json;
    expect(Object.keys(inputPartial.responsive)).toEqual(['768-']);
    expect(evaluate(wide.props.className, { memberFieldGroup: { key: 'basic', className: 'grid grid-cols-1 gap-4', wideClassName: 'grid grid-cols-2 gap-4' } })).toBe('grid grid-cols-2 gap-4');
    expect(evaluate(wide.props.className, { memberFieldGroup: { key: 'extra' } })).toBe('row-stack');
  });

  /**
   * @scenario case=row
   * @effects row_and_error_ids_follow_field_key
   */
  it('칸의 id 는 종전 회원 폼과 같은 field_{키}, 오류 줄의 id 는 mf_error_{키} 다. 약관 묶음의 동의 항목도 함께 그린다', () => {
    expect(row.id).toBe('field_{{field.key}}');
    expect(row.iteration.item_var).toBe('field');
    const fields = [{ key: 'name', ui: { group: 'basic' } }, { key: 'marketing', ui: { group: 'agreements' } }, { key: 'job' }];
    expect(evaluate(row.iteration.source, { memberFieldGroup: { key: 'x', fields } }).map((f: Json) => f.key)).toEqual(['name', 'marketing', 'job']);
    // 확장이 선언한 항목은 라벨 앞에 그 확장의 이름을 꼬리표로 단다(코어, 운영자 추가 항목에는 없다)
    const source = inputNodes.find((node) => node.id === 'mf_source_{{field.key}}')!;
    expect(evaluate(source.if, { field: { source_label: '마케팅 동의' } })).toBe(true);
    expect(evaluate(source.if, { field: { source_label: null } })).toBe(false);
    expect(viewNodes.some((node) => node.id === 'mf_source_{{field.key}}')).toBe(true);
    expect(evaluate(row.iteration.source, { memberFieldGroup: undefined })).toEqual([]);

    const error = inputNodes.find((node) => node.id === 'mf_error_{{field.key}}')!;
    expect(error.name).toBe('Span');
    const scope = (errors: Json, field: Json) => ({ field, _computed: { memberFieldCtx: { errors } } });
    // 묶음 유형은 하위 경로와 부분 경로의 오류도 이 줄에 모은다
    expect(evaluate(error.if, scope({ 'custom_fields.period.end': ['끝'] }, { name: 'custom_fields.period' }))).toBe(true);
    expect(evaluate(error.text, scope({ 'custom_fields.period.end': ['끝나는 날이 빠릅니다'] }, { name: 'custom_fields.period' }))).toBe('끝나는 날이 빠릅니다');
    expect(evaluate(error.if, scope({ zipcode: ['우편번호'] }, { name: 'address', parts: { zipcode: 'zipcode' } }))).toBe(true);
    expect(evaluate(error.if, scope({ nickname: ['다른 칸'] }, { name: 'name' }))).toBe(false);
  });

  /**
   * @scenario case=label
   * @effects required_star_follows_field_required
   */
  it('필수 별표는 field.required 일 때만 그린다', () => {
    const star = inputNodes.find((node) => node.name === 'Span' && node.text === '*')!;
    expect(star.if).toBe('{{field.required}}');
    expect(star.props.className).toContain('text-red-600');
    expect(star.props.className).toContain('dark:text-red-400');
  });

  /**
   * @scenario case=scope_badge
   * @effects scope_badge_shows_edit_scope
   */
  it.each([
    ['입력 조각', inputNodes],
    ['보기 조각', viewNodes],
  ])('%s: 항목마다 범위 배지를 그린다 — 문구는 환경설정 회원 탭의 범위 문구, 색은 범위마다 다르다', (_name, nodes) => {
    const badge = (nodes as Json[]).find((node) => node.id === 'mf_scope_{{field.key}}')!;
    expect(badge.text).toBe('$t:admin.settings.member.fields.scope.{{field.edit_scope}}');
    for (const scope of ['self', 'admin_edit', 'admin_only']) {
      expect(evaluate(badge.if, { field: { edit_scope: scope, owner: 'custom' } })).toBe(true);
      expect(typeof (adminKo as Json).settings.member.fields.scope[scope]).toBe('string');
    }
    // 범위를 모르는 응답(이전 서버)에는 배지를 그리지 않는다
    expect(evaluate(badge.if, { field: {} })).toBe(false);
    // 코어 항목은 본인 수정이 기본이라 배지를 생략한다 — 운영자가 범위를 바꾼 코어 항목과 추가, 확장 항목에만 붙는다
    expect(evaluate(badge.if, { field: { edit_scope: 'self', owner: 'core' } })).toBe(false);
    expect(evaluate(badge.if, { field: { edit_scope: 'admin_edit', owner: 'core' } })).toBe(true);
    expect(evaluate(badge.if, { field: { edit_scope: 'self', owner: 'sirsoft-ecommerce' } })).toBe(true);
    expect(evaluate(badge.props.className, { field: { edit_scope: 'self' } })).toContain('badge-gray');
    expect(evaluate(badge.props.className, { field: { edit_scope: 'admin_edit' } })).toContain('badge-blue');
    expect(evaluate(badge.props.className, { field: { edit_scope: 'admin_only' } })).toContain('badge-yellow');
  });

  /**
   * @scenario case=input
   * @effects builtin_input_binds_by_field_name
   */
  it('기본 입력칸은 MemberFieldInput 이고 name 을 field.name 으로 준다 (폼 자동 바인딩)', () => {
    const input = inputNodes.find((node) => node.name === 'MemberFieldInput')!;
    expect(input.type).toBe('composite');
    expect(input.props.name).toBe('{{field.name}}');
    expect(input.props.field).toBe('{{field}}');
    expect(input.props.value).toBeUndefined();
    expect(input.props.errorId).toBe('mf_error_{{field.key}}');
    const cases: Array<[Json, boolean]> = [
      [{ renderer: 'builtin' }, true],
      [{ renderer: 'address' }, false],
      [{ renderer: 'extension' }, false],
      [{ renderer: 'file' }, false],
      [{ renderer: 'builtin', deferred: true }, false],
    ];
    for (const [field, shown] of cases) expect(evaluate(input.if, { field })).toBe(shown);

    const errors = { 'custom_fields.other': ['다른 칸'], 'custom_fields.period.end': ['끝나는 날이 빠릅니다'] };
    expect(evaluate(input.props.error, { field: { name: 'custom_fields.period' }, _computed: { memberFieldCtx: { errors } } })).toBe('끝나는 날이 빠릅니다');
    expect(evaluate(input.props.error, { field: { name: 'name' }, _computed: { memberFieldCtx: { errors: {} } } })).toBe('');
  });

  /**
   * @scenario case=address
   * @effects address_parts_bind_by_parts_paths_and_keep_search_slot
   */
  it('주소 갈래는 부분별 폼 경로(field.parts)로 세 칸을 잇고, 주소 검색 확장점에 종전과 같은 값(readOnlyFields, targetFields)을 넘긴다', () => {
    const inputs = inputNodes.filter((node) => node.name === 'Input' && node.props?.type === 'text');
    expect(inputs.map((node) => node.props.name)).toEqual(['{{field.parts?.zipcode}}', '{{field.parts?.address}}', '{{field.parts?.address_detail}}']);
    expect(inputs.map((node) => node.props.placeholder)).toEqual([
      '$t:admin.users.form.fields.zipcode',
      '$t:admin.users.form.fields.address',
      '$t:admin.users.form.fields.address_detail',
    ]);

    const slot = inputNodes.find((node) => node.type === 'extension_point' && node.name === 'address_search_slot')!;
    expect(slot.props.readOnlyFields).toEqual(['zipcode', 'address']);
    const scope = { field: { parts: { zipcode: 'zipcode', address: 'address' } }, _computed: { memberFieldCtx: { formKey: 'form' } } };
    // 코어 주소 항목이면 종전 회원 폼이 넘기던 경로 그대로다
    expect(evaluate(slot.props.targetFields.zipcode, scope)).toBe('form.zipcode');
    expect(evaluate(slot.props.targetFields.address, scope)).toBe('form.address');
    expect(evaluate(slot.props.targetFields.region, scope)).toBe('form.region');
    expect(evaluate(slot.props.targetFields.city, scope)).toBe('form.city');
    // 운영자가 추가한 주소 항목은 자기 경로로 받는다
    const customScope = { field: { parts: { zipcode: 'custom_fields.office.zipcode', address: 'custom_fields.office.address' } }, _computed: { memberFieldCtx: { formKey: 'form' } } };
    expect(evaluate(slot.props.targetFields.zipcode, customScope)).toBe('form.custom_fields.office.zipcode');

    // 잠긴 항목은 세 칸 모두 비활성이고 검색 단추를 그리지 않는다
    for (const input of inputs) {
      expect(evaluate(input.props.disabled, { field: { readonly: true }, _computed: {} })).toBe(true);
      expect(evaluate(input.props.disabled, { field: { readonly: false }, _computed: {} })).toBe(false);
    }
    expect(evaluate(slot.if, { field: { readonly: true } })).toBe(false);
    expect(evaluate(slot.if, { field: { readonly: false } })).toBe(true);
  });

  /**
   * @scenario case=locked
   * @effects locked_reason_picks_one_notice
   */
  it('잠긴 항목은 사유에 맞는 안내 문구 하나만 보인다 (회사 계정 잠금은 종전 문구 그대로)', () => {
    const notices: Record<string, Json> = {
      sso: inputNodes.find((node) => node.text === '$t:admin.users.form.fields.locked_by_company')!,
      identity: inputNodes.find((node) => node.text === '$t:admin.users.member_fields.locked_identity')!,
      other: inputNodes.find((node) => node.text === '$t:admin.users.member_fields.locked_generic')!,
    };
    for (const notice of Object.values(notices)) {
      expect(notice).toBeDefined();
      expect(notice.props.className).toContain('text-xs text-gray-500 dark:text-gray-400');
    }
    const shown = (field: Json) => Object.entries(notices).filter(([, node]) => evaluate(node.if, { field }) === true).map(([reason]) => reason);

    expect(shown({ readonly: true, locked_reason: 'sso' })).toEqual(['sso']);
    expect(shown({ readonly: true, locked_reason: 'identity' })).toEqual(['identity']);
    expect(shown({ readonly: true, locked_reason: 'something' })).toEqual(['other']);
    expect(shown({ readonly: true, locked_reason: null })).toEqual(['other']);
    expect(shown({ readonly: false, locked_reason: 'sso' })).toEqual([]);
  });

  /**
   * @scenario case=file
   * @effects file_branch_lists_files_and_gates_add_by_count
   */
  it('파일 갈래는 올라간 파일 목록을 그리고, 개수가 남았고 잠기지 않았을 때만 「파일 추가」를 보인다', () => {
    const branch = inputNodes.find((node) => node.id === 'mf_files_{{field.key}}')!;
    expect(evaluate(branch.if, { field: { renderer: 'file' } })).toBe(true);
    expect(evaluate(branch.if, { field: { renderer: 'file', deferred: true } })).toBe(false);
    expect(evaluate(branch.if, { field: { renderer: 'builtin' } })).toBe(false);

    const item = inputNodes.find((node) => node.id === 'mf_file_{{field.key}}_{{fieldFile.id}}')!;
    expect(item.iteration.item_var).toBe('fieldFile');
    const download = inputNodes.find((node) => node.id === 'mf_file_download_{{field.key}}_{{fieldFile.id}}')!;
    expect(actionsOf(download)[0].handler).toBe('downloadAttachment');
    expect(actionsOf(download)[0].params).toEqual({ url: '{{fieldFile.url}}', filename: '{{fieldFile.name}}' });

    const add = inputNodes.find((node) => node.id === 'mf_file_add_{{field.key}}')!;
    expect(evaluate(add.if, { field: { files: [{ id: 1 }], file_rules: { max_count: 2 } } })).toBe(true);
    expect(evaluate(add.if, { field: { files: [{ id: 1 }, { id: 2 }], file_rules: { max_count: 2 } } })).toBe(false);
    expect(evaluate(add.if, { field: { files: [], file_rules: { max_count: 2 }, readonly: true } })).toBe(false);
    expect(evaluate(add.if, { field: { files: [{ id: 1 }] } })).toBe(false);
  });

  /**
   * @scenario case=file
   * @effects file_upload_and_delete_use_admin_api_and_refetch_member_fields
   */
  it('파일 올리기와 지우기는 관리자 API 로 바로 보내고 성공하면 데이터소스 memberFields 를 다시 받는다', () => {
    const API = '/api/admin/users/{{route?.id}}/fields/{{field.key}}/file';
    const add = inputNodes.find((node) => node.id === 'mf_file_add_{{field.key}}')!;
    const picker = flatten(add).find((node) => node.name === 'Input')!;
    expect(picker.props.type).toBe('file');
    expect(evaluate(picker.props.accept, { field: { file_rules: { extensions: ['pdf', 'png'] } } })).toBe('.pdf,.png');

    const upload = actionsOf(picker)[0];
    expect(upload.type).toBe('change');
    expect(upload.handler).toBe('apiCall');
    expect(upload.target).toBe(API);
    expect(upload.auth_required).toBe(true);
    expect(upload.params.method).toBe('POST');
    expect(upload.params.contentType).toBe('multipart/form-data');
    const picked = { name: 'biz.pdf' };
    expect(evaluate(upload.params.body, { $event: { target: { files: [picked] } } })).toEqual({ file: picked });
    expect(evaluate(upload.if, { $event: { target: { files: [] } } })).toBe(false);
    const uploaded = callbacksOf(upload, 'onSuccess');
    expect(uploaded.map((action) => action.handler)).toEqual(['refetchDataSource', 'toast']);
    expect(uploaded[0].params.dataSourceId).toBe('memberFields');
    const failMessage = callbacksOf(upload, 'onError').find((action) => action.handler === 'toast')!.params.message;
    expect(evaluate(failMessage, { error: { errors: { file: ['pdf 만 올릴 수 있습니다'] }, message: '검증 실패' } })).toBe('pdf 만 올릴 수 있습니다');

    const remove = inputNodes.find((node) => node.id === 'mf_file_remove_{{field.key}}_{{fieldFile.id}}')!;
    expect(remove.name).toBe('Button');
    expect(evaluate(remove.if, { field: { readonly: true } })).toBe(false);
    const sequence = actionsOf(remove)[0];
    expect(sequence.handler).toBe('sequence');
    const [busy, call] = callbacksOf(sequence, 'actions');
    expect(busy.params.memberFieldFileBusy).toBe('{{field.key}}');
    expect(call.target).toBe(API);
    expect(call.params.method).toBe('DELETE');
    expect(call.params.body).toEqual({ attachment_id: '{{fieldFile.id}}' });
    const removed = callbacksOf(call, 'onSuccess');
    expect(removed.map((action) => action.handler)).toEqual(['setState', 'refetchDataSource', 'toast']);
    expect(removed[1].params.dataSourceId).toBe('memberFields');
    expect(removed[0].params.memberFieldFileBusy).toBeNull();
    expect(callbacksOf(call, 'onError')[0].params.memberFieldFileBusy).toBeNull();
    expect(evaluate(remove.props.disabled, { _local: { memberFieldFileBusy: 'license' }, field: { key: 'license' } })).toBe(true);
  });

  /**
   * @scenario case=extension
   * @effects extension_type_slot_receives_field
   */
  it('확장 유형 갈래는 확장점 member_field_input 에 항목 계약, 오류, 비활성 여부, 폼 이름을 넘긴다', () => {
    const slot = inputNodes.find((node) => node.type === 'extension_point' && node.name === 'member_field_input')!;
    expect(Object.keys(slot.props).sort()).toEqual(['disabled', 'error', 'field', 'formKey']);
    expect(slot.props.field).toBe('{{field}}');
    expect(evaluate(slot.if, { field: { renderer: 'extension' } })).toBe(true);
    expect(evaluate(slot.if, { field: { renderer: 'builtin' } })).toBe(false);
  });

  /**
   * @scenario case=deferred
   * @effects deferred_field_shows_upload_after_save_notice_only
   */
  it('회원 추가 화면의 파일 항목(deferred)은 「저장한 뒤 올릴 수 있습니다」 안내 줄만 그린다', () => {
    const notice = inputNodes.find((node) => node.id === 'mf_deferred_{{field.key}}')!;
    expect(notice.if).toBe('{{field.deferred}}');
    expect(notice.text).toBe('$t:admin.users.member_fields.upload_after_save');
  });

  /**
   * @scenario case=dark
   * @effects every_color_class_has_dark_pair
   */
  it('두 조각의 글자색, 테두리 클래스에 다크 짝이 있고 Tailwind 반응형 접두를 쓰지 않는다', () => {
    for (const node of [...inputNodes, ...viewNodes]) {
      const className = String(node.props?.className ?? '');
      if (/(^|[\s'])text-(gray|red|blue)-\d+/.test(className)) expect(className).toMatch(/dark:text-/);
      if (/(^|[\s'])border-gray-\d+/.test(className)) expect(className).toMatch(/dark:border-/);
      expect(className).not.toMatch(/(^|[\s'])(sm|md|lg|xl):/);
    }
  });
});

describe('회원 항목 보기 조각 (관리자, 상세)', () => {
  /**
   * @scenario case=view
   * @effects view_row_keeps_field_id_and_shows_display_value
   */
  it('칸의 id 는 입력 조각과 같은 field_{키} 이고 보기용 값(없으면 「-」)을 그린다. 여러 줄 글은 줄바꿈을 살린다', () => {
    const viewRow = viewPartial.children[0];
    expect(viewPartial.id).toBe('member_fields_view_group_{{memberFieldGroup.key}}');
    expect(viewRow.id).toBe('field_{{field.key}}');

    const value = viewNodes.find((node) => node.id === 'mf_view_value_{{field.key}}')!;
    expect(evaluate(value.text, { field: { display_value: '직장인' } })).toBe('직장인');
    expect(evaluate(value.text, { field: { display_value: null } })).toBe('-');
    expect(evaluate(value.if, { field: { renderer: 'file' } })).toBe(false);
    expect(evaluate(value.props.className, { field: { type: 'textarea' } })).toContain('whitespace-pre-line');
    expect(evaluate(value.props.className, { field: { type: 'text' } })).not.toContain('whitespace-pre-line');
  });

  /**
   * @scenario case=view_file
   * @effects view_file_lists_names_and_downloads
   */
  it('파일 항목은 파일 이름 목록을 그리고 이름을 누르면 내려받는다. 올리기와 지우기는 없다', () => {
    const branch = viewNodes.find((node) => node.id === 'mf_view_files_{{field.key}}')!;
    expect(evaluate(branch.if, { field: { renderer: 'file' } })).toBe(true);
    const item = viewNodes.find((node) => node.id === 'mf_view_file_{{field.key}}_{{fieldFile.id}}')!;
    expect(item.iteration.item_var).toBe('fieldFile');
    const download = flatten(item).find((node) => actionsOf(node)[0]?.handler === 'downloadAttachment')!;
    expect(actionsOf(download)[0].params).toEqual({ url: '{{fieldFile.url}}', filename: '{{fieldFile.name}}' });
    expect(viewNodes.some((node) => actionsOf(node).some((action) => action.handler === 'apiCall'))).toBe(false);
    expect(viewNodes.some((node) => node.name === 'Input' || node.name === 'MemberFieldInput')).toBe(false);
  });
});

describe('회원 폼과 상세의 항목 데이터소스, 묶음, 구획', () => {
  /**
   * @scenario case=form_source
   * @effects form_fetches_fields_by_mode_without_blocking
   */
  it('폼은 항목을 한 데이터소스(memberFields)로 받는다 — 추가는 회원 없는 주소, 수정은 그 회원의 주소. blocking 전략을 쓰지 않는다', () => {
    const source = form.data_sources.find((d: Json) => d.id === 'memberFields');
    expect(source.method).toBe('GET');
    expect(source.auth_required).toBe(true);
    expect(source.loading_strategy).not.toBe('blocking');
    expect(source.initLocal).toBeUndefined();

    // 주소 안의 식은 엔진이 요청을 보낼 때 쓰는 것과 같은 함수로 푼다
    const resolve = (route: Json) => resolveExpressionString(source.endpoint, { route, query: {} }, { skipCache: true });
    expect(resolve({})).toBe('/api/admin/users/form-fields');
    expect(resolve({ id: 'uuid-1' })).toBe('/api/admin/users/uuid-1/form-fields');
    expect(form.transition_overlay.wait_for).toContain('memberFields');
  });

  /**
   * @scenario case=detail_source
   * @effects detail_fetches_same_fields_source_without_error_page
   */
  it('상세도 같은 이름의 데이터소스로 그 회원의 항목을 받고, 받지 못해도 화면을 접지 않는다', () => {
    const source = detail.data_sources.find((d: Json) => d.id === 'memberFields');
    expect(source.endpoint).toBe('/api/admin/users/{{route?.id}}/form-fields');
    expect(JSON.stringify(source)).not.toContain('showErrorPage');
    expect(detail.transition_overlay.wait_for).toContain('memberFields');
    expect(detail.computed.memberFieldGroups).toBe(form.computed.memberFieldGroups);

    // 403, 404 는 알리지 않는다 — 같은 주소의 회원 조회(user)가 같은 응답으로 오류 페이지를 보이므로 그 위에 알림이 겹친다.
    // 그 밖의 실패만 알림으로 알린다.
    const handling = source.errorHandling ?? {};
    expect(handling['403']?.handler).toBe('suppress');
    expect(handling['404']?.handler).toBe('suppress');
    expect(JSON.stringify(handling.default)).toContain('toast');
    const user = detail.data_sources.find((d: Json) => d.id === 'user');
    expect(user.errorHandling['403'].handler).toBe('showErrorPage');
    expect(user.errorHandling['404'].handler).toBe('showErrorPage');
  });

  /**
   * @scenario case=groups
   * @effects groups_split_by_ui_group_custom_and_extension_owner
   */
  it('묶음은 항목의 ui.group 으로 가르고, 묶음 없는 운영자 추가 항목은 extra, 모듈과 플러그인이 선언한 항목은 extension 으로 간다', () => {
    const groups = computed(form, 'memberFieldGroups', { memberFields: fieldsResponse() }) as Json[];
    expect(groups.map((g) => [g.key, g.fields.map((f: Json) => f.key)])).toEqual([
      ['basic', ['name', 'nickname']],
      ['contact', ['mobile']],
      ['location', ['language', 'address']],
      ['profile', ['bio']],
      ['extra', ['job', 'grade', 'license']],
      ['extension', ['preferred_currency']],
    ]);
    // 항목이 없는 묶음은 목록에 없다. 응답이 아직 없어도 깨지지 않는다
    expect(computed(form, 'memberFieldGroups', { memberFields: fieldsResponse([contract({ key: 'name', ui: { group: 'basic' } })]) }).map((g: Json) => g.key)).toEqual(['basic']);
    expect(computed(form, 'memberFieldGroups', { memberFields: undefined })).toEqual([]);
    // 확장이 선언한 항목은 묶음이 있어도 확장 구획에만 그린다(두 번 그리지 않는다)
    const withGroup = [contract({ key: 'points', owner: 'acme', owner_type: 'plugin', ui: { group: 'basic' } })];
    expect(computed(form, 'memberFieldGroups', { memberFields: fieldsResponse(withGroup) }).map((g: Json) => g.key)).toEqual(['extension']);
  });

  /**
   * @scenario case=sections
   * @effects new_sections_have_html_ids_and_show_only_with_fields
   */
  it.each([
    ['폼', form],
    ['상세', detail],
  ])('%s: 새 구획 section_extra_fields, section_extension_fields 는 탭이 찾는 HTML id 를 갖고 항목이 있을 때만 그린다', (_name, layout) => {
    const scope = (keys: string[]) => ({ _computed: { memberFieldGroups: keys.map((key) => ({ key, fields: [{}] })) } });
    for (const [ref, htmlId, group] of [
      ['section_extra_fields', 'extra_fields', 'extra'],
      ['section_extension_fields', 'extension_fields', 'extension'],
      ['section_location_info', 'location_info', 'location'],
    ]) {
      const section = byId((layout as Json).slots, ref)!;
      expect(section, ref).toBeDefined();
      expect(section.props.id).toBe(htmlId);
      expect(section.props.className).toContain('admin-card');
      expect(evaluate(section.if, scope([group]))).toBe(true);
      expect(evaluate(section.if, scope(['basic']))).toBe(false);
      expect(evaluate(section.if, { _computed: {} })).toBe(false);
    }
  });

  /**
   * @scenario case=tabs
   * @effects new_tabs_follow_profile_and_show_only_with_fields
   */
  it.each([
    ['폼', form, 'user_form_tabs'],
    ['상세', detail, 'user_detail_tabs'],
  ])('%s: 「추가 항목」「확장 항목」 탭은 프로필/메모 뒤에 오고 그 묶음에 항목이 있을 때만 보인다', (_name, layout, ref) => {
    const nav = byId((layout as Json).slots, ref as string)!;
    expect(nav.props.sectionIdPrefix).toBe('');
    const tabs = nav.props.tabs as Json[];
    const ids = tabs.map((tab) => tab.id);
    expect(ids.slice(0, 7)).toEqual(['basic_info', 'contact_info', 'location_info', 'profile_memo', 'extra_fields', 'extension_fields', 'activity_info']);

    const scope = (keys: string[]) => ({ _computed: { memberFieldGroups: keys.map((key) => ({ key })) } });
    const tab = (id: string) => tabs.find((t) => t.id === id)!;
    expect(evaluate(tab('extra_fields').if, scope(['extra']))).toBe(true);
    expect(evaluate(tab('extra_fields').if, scope(['basic', 'extension']))).toBe(false);
    expect(evaluate(tab('extension_fields').if, scope(['extension']))).toBe(true);
    expect(evaluate(tab('extension_fields').if, scope(['extra']))).toBe(false);
    expect(evaluate(tab('location_info').if, scope(['location']))).toBe(true);
    expect(evaluate(tab('location_info').if, scope([]))).toBe(false);
    expect(tab('extra_fields').label).toBe('$t:admin.users.member_fields.extra_title');
    expect(tab('extension_fields').label).toBe('$t:admin.users.member_fields.extension_title');
  });

  /**
   * @scenario case=error_dot
   * @effects error_keys_map_to_tabs_by_field_group
   */
  it('U22: 오류 키를 항목의 묶음과 대조해 오류가 난 칸이 든 탭 id 를 낸다 (고정 칸의 오류도 자기 탭으로)', () => {
    const tabsFor = (errors: unknown) => computed(form, 'memberFieldErrorTabs', { _local: { errors }, memberFields: fieldsResponse() }) as string[];

    expect(tabsFor({ name: ['필수'] })).toEqual(['basic_info']);
    expect(tabsFor({ mobile: ['형식'] })).toEqual(['contact_info']);
    expect(tabsFor({ zipcode: ['우편번호'] })).toEqual(['location_info']);
    expect(tabsFor({ bio: ['길이'] })).toEqual(['profile_memo']);
    expect(tabsFor({ 'custom_fields.job': ['필수'] })).toEqual(['extra_fields']);
    expect(tabsFor({ 'custom_fields.job.other': ['필수'] })).toEqual(['extra_fields']);
    expect(tabsFor({ ecommerce_preferred_currency: ['통화'] })).toEqual(['extension_fields']);
    // 항목이 아닌 고정 칸
    expect(tabsFor({ email: ['중복'], password: ['짧음'], admin_memo: ['길이'], 'role_ids.0': ['없는 역할'] })).toEqual(['contact_info', 'basic_info', 'profile_memo', 'basic_info']);
    // 어느 탭에도 속하지 않는 오류, 글자로 온 오류, 오류 없음
    expect(tabsFor({ unknown_key: ['?'] })).toEqual([]);
    expect(tabsFor('서버 오류')).toEqual([]);
    expect(tabsFor(null)).toEqual([]);

    const nav = byId(form.slots, 'user_form_tabs')!;
    expect(nav.props.errorDotLabel).toBe('$t:admin.users.member_fields.tab_has_error');
    for (const id of ['basic_info', 'contact_info', 'location_info', 'profile_memo', 'extra_fields', 'extension_fields']) {
      const tab = (nav.props.tabs as Json[]).find((t) => t.id === id)!;
      expect(evaluate(tab.errorDot, { _computed: { memberFieldErrorTabs: [id] } }), id).toBe(true);
      expect(evaluate(tab.errorDot, { _computed: { memberFieldErrorTabs: ['other'] } }), id).toBe(false);
      expect(evaluate(tab.errorDot, { _computed: {} }), id).toBe(false);
    }
  });

  /**
   * @scenario case=orphan_errors
   * @effects top_box_lists_only_errors_outside_member_fields
   */
  it('오류는 칸 아래로 — 위쪽 상자는 조각이 그린 항목에 속하지 않는 오류만 나열한다', () => {
    const orphan = (errors: unknown) => computed(form, 'memberFieldOrphanErrors', { _local: { errors }, memberFields: fieldsResponse() });
    expect(orphan({ name: ['이름 필수'], 'custom_fields.job': ['직업 필수'], zipcode: ['우편번호'], email: ['이메일 중복'], status: '상태 오류' })).toEqual(['이메일 중복', '상태 오류']);
    expect(orphan('서버 오류')).toEqual([]);
    expect(orphan(null)).toEqual([]);

    const list = flatten(byId(form.slots, 'validation_error')).find((node) => node.name === 'Ul')!;
    expect(list.iteration.source).toBe('{{_computed.memberFieldOrphanErrors ?? []}}');
    expect(evaluate(list.if, { _computed: { memberFieldOrphanErrors: ['x'] } })).toBe(true);
    expect(evaluate(list.if, { _computed: { memberFieldOrphanErrors: [] } })).toBe(false);
  });

  /**
   * @scenario case=anchors
   * @effects fixed_fields_and_extension_anchors_untouched
   */
  it('고정 칸과 확장 주입 자리는 그대로다 — 가변 칸만 조각으로 바뀌었다', () => {
    for (const id of [
      'field_uuid', 'field_roles', 'field_status', 'field_has_password', 'field_email_verified', 'field_create_without_password',
      'field_password_group', 'field_password', 'field_password_confirmation', 'field_current_password', 'field_email', 'field_admin_memo',
      'section_basic_info', 'section_contact_info', 'section_location_info', 'section_profile_memo', 'section_activity_info', 'section_password_info',
      'user_form_tabs', 'extension_form_content', 'validation_error', 'footer_save_button',
    ]) {
      expect(byId(form.slots, id), id).toBeDefined();
    }
    for (const id of ['extension_tab_content', 'user_detail_tabs', 'field_uuid', 'field_roles', 'field_email', 'field_admin_memo']) {
      expect(byId(detail.slots, id), id).toBeDefined();
    }
    // 가변 칸은 고정 노드로 남아 있지 않다(조각이 같은 id 로 그린다)
    for (const key of ['name', 'nickname', 'mobile', 'phone', 'homepage', 'language', 'country', 'timezone', 'address', 'signature', 'bio']) {
      expect(byId(form.slots, `field_${key}`), `폼 field_${key}`).toBeUndefined();
      expect(byId(detail.slots, `field_${key}`), `상세 field_${key}`).toBeUndefined();
    }
    // 저장 본문은 폼 상태에 받은 정의 버전(fields_version)만 얹는다 — 항목 정의는 폼 상태에 섞이지 않는다.
    // 정의 버전은 회원 항목을 그리는 화면이라는 표식이다(없으면 서버가 옛 화면으로 보고 운영자 추가 필수 항목을 건너뛴다).
    const save = flatten(actionsOf(byId(form.slots, 'footer_save_button')!)).find((node) => node.handler === 'apiCall')!;
    const sent = { name: '홍길동', custom_fields: { job: 'dev' } };
    expect(evaluate(save.params.body, { _local: { form: sent }, memberFields: { data: { fields_version: 'v916' } } })).toEqual({ ...sent, fields_version: 'v916' });
    expect(evaluate(save.params.body, { _local: { form: sent }, memberFields: undefined })).toEqual(sent);
    // 조각에 넘기는 문맥: 폼 상태 이름은 form, 오류가 글자로 오면 빈 객체
    expect(computed(form, 'memberFieldCtx', { _local: { errors: { name: ['x'] } } })).toEqual({ formKey: 'form', errors: { name: ['x'] }, disabled: false });
    expect(computed(form, 'memberFieldCtx', { _local: { errors: '서버 오류' } }).errors).toEqual({});
  });

  /**
   * @scenario case=lang_keys
   * @effects member_fields_keys_exist_in_ko_and_en
   */
  it('새 문구 키(admin.users.member_fields)는 ko 와 en 의 모양이 같고, 레이아웃이 쓰는 키가 모두 있다', () => {
    const shape = (value: any, prefix = ''): string[] =>
      value && typeof value === 'object' ? Object.entries(value).flatMap(([k, v]) => shape(v, prefix ? `${prefix}.${k}` : k)) : [prefix];
    const ko = shape((adminKo as Json).users.member_fields).sort();
    expect(shape((adminEn as Json).users.member_fields).sort()).toEqual(ko);

    const used = new Set<string>();
    for (const layout of [form, detail, inputPartial, viewPartial]) {
      for (const match of JSON.stringify(layout).matchAll(/\$t:admin\.users\.member_fields\.([a-z_.]+)/g)) used.add(match[1].replace(/\.$/, ''));
    }
    expect([...used].filter((key) => !ko.includes(key))).toEqual([]);
    expect(used.size).toBeGreaterThanOrEqual(12);
  });
});

describe('회원 폼 렌더 — 항목 계약대로 칸을 그린다', () => {
  const FORM_SECTIONS = ['sticky_header', 'validation_error', 'section_basic_info', 'section_contact_info', 'section_location_info', 'section_profile_memo', 'section_extra_fields', 'section_extension_fields'];

  /**
   * @scenario case=render_edit
   * @effects form_renders_fields_with_field_ids_badges_and_values
   */
  it('수정 화면: 묶음별 구획에 칸이 field_{키} id 로 서고, 범위 배지와 필수 별표가 보이고, 폼 값이 칸에 들어온다', async () => {
    const { container } = await renderNodes(form, FORM_SECTIONS, {
      route: { id: 'uuid-1' },
      local: { form: { email: 'a@example.com', name: '홍길동', nickname: '길동이', mobile: '010-1111-2222', bio: '소개', custom_fields: { job: '직장인', grade: 'A' } } },
    });
    const q = (selector: string) => container.querySelector(selector) as HTMLElement | null;

    // 칸의 구획 배치
    const within = (section: string) => Array.from(q(`#${section}`)!.querySelectorAll('[id^="field_"]')).map((el) => el.id);
    expect(within('basic_info')).toEqual(expect.arrayContaining(['field_uuid', 'field_name', 'field_nickname', 'field_roles']));
    expect(within('contact_info')).toEqual(['field_email', 'field_mobile']);
    expect(within('location_info')).toEqual(['field_language', 'field_address']);
    expect(within('profile_memo')).toEqual(['field_bio', 'field_admin_memo']);
    expect(within('extra_fields')).toEqual(['field_job', 'field_grade', 'field_license']);
    expect(within('extension_fields')).toEqual(['field_preferred_currency']);
    // 이름 칸은 닉네임 칸보다 앞이고 역할 칸보다 앞이다(종전 순서)
    const basic = within('basic_info');
    expect(basic.indexOf('field_name')).toBeLessThan(basic.indexOf('field_nickname'));
    expect(basic.indexOf('field_nickname')).toBeLessThan(basic.indexOf('field_roles'));

    // 값과 name 바인딩
    expect((q('#field_name input') as HTMLInputElement).value).toBe('홍길동');
    expect((q('#field_name input') as HTMLInputElement).name).toBe('name');
    expect((q('#field_job input') as HTMLInputElement).value).toBe('직장인');
    expect((q('#field_job input') as HTMLInputElement).name).toBe('custom_fields.job');
    expect((q('#field_bio textarea') as HTMLTextAreaElement).value).toBe('소개');
    expect(Array.from(q('#field_address')!.querySelectorAll('input')).map((el) => el.getAttribute('name'))).toEqual(['zipcode', 'address', 'address_detail']);

    // 라벨, 필수 별표, 범위 배지
    expect(q('#field_name label')!.textContent).toBe('이름*');
    expect(q('#field_nickname label')!.textContent).toBe('닉네임');
    expect(q('#mf_scope_name')).toBeNull();
    expect(q('#mf_scope_grade')!.textContent).toBe('관리자만 열람');
    expect(q('#mf_scope_license')!.textContent).toBe('관리자만 수정');
    expect(q('#mf_scope_license')!.className).toContain('badge-blue');

    // 파일 항목: 올라간 파일과 「파일 추가」(최대 2개 중 1개)
    expect(q('#mf_file_license_7')!.textContent).toContain('biz.pdf');
    expect(q('#mf_file_add_license')).not.toBeNull();
    expect(q('#mf_file_remove_license_7')).not.toBeNull();
    expect(q('#mf_deferred_license')).toBeNull();

    // 화면에 번역 키가 그대로 보이지 않는다
    expect(container.textContent).not.toMatch(/admin\.users\.|admin\.settings\./);
    // 오류가 없으면 오류 줄과 빨간 점이 없다
    expect(container.querySelectorAll('[id^="mf_error_"]').length).toBe(0);
    expect(container.querySelectorAll('[data-tab-error-dot]').length).toBe(0);
  });

  /**
   * @scenario case=render_create_deferred
   * @effects create_mode_file_field_shows_notice_without_upload
   */
  it('추가 화면: 파일 항목은 안내 줄만 있고 올리기 단추가 없다. 회원 항목 상태 줄도 없다', async () => {
    const fields = sampleFields().map((f) => (f.renderer === 'file' ? { ...f, deferred: true, required: false, files: [] } : f));
    const { container } = await renderNodes(form, FORM_SECTIONS, {
      fields: fieldsResponse(fields, { nickname_changed_at: '2026-11-01 10:00', nickname_next_changeable_at: null, pending_required_fields: [] }),
    });

    expect(container.querySelector('#mf_deferred_license')!.textContent).toBe('회원을 저장한 뒤 수정 화면에서 올릴 수 있습니다.');
    expect(container.querySelector('#mf_files_license')).toBeNull();
    expect(container.querySelector('#mf_file_add_license')).toBeNull();
    expect(container.querySelector('#field_license input')).toBeNull();
    // 직전 회원의 응답이 남아 있어도 추가 화면에는 회원 항목 상태를 그리지 않는다
    expect(container.querySelector('#member_status_info')).toBeNull();
    expect(container.querySelector('#field_uuid')).toBeNull();
  });

  /**
   * @scenario case=render_locked
   * @effects locked_field_is_disabled_with_company_notice
   */
  it('수정 화면: 회사 계정이 관리하는 칸(readonly, sso)은 비활성이고 칸 아래 종전 안내 문구가 보인다', async () => {
    const fields = sampleFields().map((f) => (f.key === 'name' ? { ...f, readonly: true, locked_reason: 'sso' } : f));
    const { container } = await renderNodes(form, ['section_basic_info'], { route: { id: 'uuid-1' }, fields: fieldsResponse(fields), local: { form: { name: '홍길동' } } });

    expect((container.querySelector('#field_name input') as HTMLInputElement).disabled).toBe(true);
    expect(container.querySelector('#mf_locked_sso_name')!.textContent).toBe('회사 계정에서 관리하는 항목입니다');
    expect((container.querySelector('#field_nickname input') as HTMLInputElement).disabled).toBe(false);
    expect(container.querySelector('#mf_locked_sso_nickname')).toBeNull();
    expect(container.querySelector('#mf_locked_other_name')).toBeNull();
  });

  /**
   * @scenario case=render_errors
   * @effects errors_render_under_fields_and_mark_tabs_with_red_dot
   */
  it('U22: 검증 오류는 칸 아래에 뜨고, 그 칸이 든 탭 이름 옆에 빨간 점이 붙는다. 위쪽 상자는 칸 밖 오류만 나열한다', async () => {
    const { container } = await renderNodes(form, FORM_SECTIONS, {
      route: { id: 'uuid-1' },
      local: { form: {}, errors: { name: ['이름은 필수입니다.'], 'custom_fields.job': ['직업은 필수입니다.'], status: ['상태 값이 올바르지 않습니다.'] } },
    });

    expect(container.querySelector('#mf_error_name')!.textContent).toBe('이름은 필수입니다.');
    expect(container.querySelector('#mf_error_job')!.textContent).toBe('직업은 필수입니다.');
    expect(container.querySelector('#mf_error_nickname')).toBeNull();
    expect(container.querySelector('#field_name input')!.getAttribute('aria-invalid')).toBe('true');
    expect(container.querySelector('#field_name input')!.getAttribute('aria-describedby')).toBe('mf_error_name');

    const dotted = screen.getAllByRole('tab').filter((tab) => tab.querySelector('[data-tab-error-dot]')).map((tab) => tab.textContent);
    expect(dotted).toEqual(['기본정보', '추가 항목']);
    expect(container.querySelector('[data-tab-error-dot]')!.getAttribute('aria-label')).toBe('오류가 있는 칸이 있습니다');

    const boxed = Array.from(container.querySelectorAll('#validation_error li')).map((li) => li.textContent);
    expect(boxed).toEqual(['상태 값이 올바르지 않습니다.']);
  });

  /**
   * @scenario case=render_tabs
   * @effects tabs_hide_groups_without_fields
   */
  it('운영자 추가 항목도 확장 항목도 없는 사이트: 「추가 항목」「확장 항목」 탭과 구획이 없다', async () => {
    const coreOnly = sampleFields().filter((f) => f.owner_type === 'core');
    const { container } = await renderNodes(form, FORM_SECTIONS, { route: { id: 'uuid-1' }, fields: fieldsResponse(coreOnly) });

    expect(screen.getAllByRole('tab').map((tab) => tab.textContent).slice(0, 5)).toEqual(['기본정보', '연락처', '위치정보', '프로필/메모', '활동정보']);
    expect(container.querySelector('#extra_fields')).toBeNull();
    expect(container.querySelector('#extension_fields')).toBeNull();
  });

  /**
   * @scenario case=render_member_status
   * @effects member_status_lines_render_only_with_values
   */
  it('U17 수정 화면: 닉네임 마지막 변경일, 다시 바꿀 수 있는 날, 비어 있는 필수 항목 이름을 값이 있는 줄만 그린다', async () => {
    const member = { nickname_changed_at: '2026-11-01 10:00', nickname_next_changeable_at: '2026-11-08 10:00', required_fields_deferred_at: null, pending_required_fields: [{ key: 'job', label: '직업' }, { key: 'mobile', label: '휴대폰' }] };
    const full = await renderNodes(form, ['section_basic_info'], { route: { id: 'uuid-1' }, fields: fieldsResponse(sampleFields(), member) });
    const text = (id: string) => full.container.querySelector(`#${id}`)?.textContent ?? null;
    expect(text('member_status_nickname_changed_at')).toContain('닉네임 마지막 변경');
    expect(text('member_status_nickname_changed_at')).toContain('2026-11-01 10:00');
    expect(text('member_status_nickname_next_changeable_at')).toContain('2026-11-08 10:00');
    expect(text('member_status_pending_required')).toContain('비어 있는 필수 항목');
    expect(Array.from(full.container.querySelectorAll('#member_status_pending_required .badge')).map((el) => el.textContent)).toEqual(['직업', '휴대폰']);
    active?.cleanup();

    const partial = await renderNodes(form, ['section_basic_info'], {
      route: { id: 'uuid-1' },
      fields: fieldsResponse(sampleFields(), { nickname_changed_at: '2026-11-01 10:00', nickname_next_changeable_at: null, pending_required_fields: [] }),
    });
    expect(partial.container.querySelector('#member_status_nickname_changed_at')).not.toBeNull();
    expect(partial.container.querySelector('#member_status_nickname_next_changeable_at')).toBeNull();
    expect(partial.container.querySelector('#member_status_pending_required')).toBeNull();
    active?.cleanup();

    const none = await renderNodes(form, ['section_basic_info'], {
      route: { id: 'uuid-1' },
      fields: fieldsResponse(sampleFields(), { nickname_changed_at: null, nickname_next_changeable_at: null, pending_required_fields: [] }),
    });
    expect(none.container.querySelector('#member_status_info')).toBeNull();
  });

  /**
   * @scenario case=render_display_name_status
   * @effects display_name_status_shows_chosen_kind_and_shown_names_read_only
   */
  it('U17 수정 화면: 회원이 고른 표시 이름 종류와 지금 보이는 이름(사이트 기본, 관리자 화면)을 읽기 전용으로 그린다', async () => {
    const base = { nickname_changed_at: null, nickname_next_changeable_at: null, pending_required_fields: [] };
    const chosen = await renderNodes(form, ['section_basic_info'], {
      route: { id: 'uuid-1' },
      fields: fieldsResponse(sampleFields(), {
        ...base, display_name_kind: 'nickname', display_name_kind_label: '닉네임', display_name_kind_unavailable: false,
        display_name_effective_kind: 'nickname', display_name_effective_kind_label: '닉네임', display_name: '길동이', display_name_admin: '홍길동',
      }),
    });
    const section = chosen.container.querySelector('#member_status_info') as HTMLElement;
    expect(section).not.toBeNull();
    expect(chosen.container.querySelector('#member_status_display_name_kind')?.textContent).toContain('회원이 고른 표시 이름 종류');
    expect(screen.getByTestId('member-status-display-name-kind').textContent).toBe('닉네임');
    expect(screen.queryByTestId('member-status-display-name-kind-unavailable')).toBeNull();
    expect(chosen.container.querySelector('#member_status_display_name_kind')?.textContent).toContain('회원 본인만 바꿀 수 있습니다.');
    expect(chosen.container.querySelector('#member_status_display_name')?.textContent).toContain('지금 보이는 이름');
    expect(screen.getByTestId('member-status-display-name-site').textContent).toBe('길동이');
    expect(screen.getByTestId('member-status-display-name-admin').textContent).toBe('홍길동');
    // 관리자는 종류를 바꾸지 못한다 — 이 구획에는 입력 칸이 없다
    expect(section.querySelectorAll('input, select, textarea, [role="switch"]')).toHaveLength(0);
    expect(section.textContent).not.toMatch(/admin\.users\.|\{\{|\}\}/);
    active?.cleanup();

    // 고르지 않은 회원: 회원 기본 종류를 알린다
    await renderNodes(form, ['section_basic_info'], {
      route: { id: 'uuid-1' },
      fields: fieldsResponse(sampleFields(), {
        ...base, display_name_kind: null, display_name_kind_label: null, display_name_kind_unavailable: false,
        display_name_effective_kind: 'name', display_name_effective_kind_label: '이름', display_name: '홍길동', display_name_admin: '홍길동',
      }),
    });
    expect(screen.getByTestId('member-status-display-name-kind').textContent).toBe('고르지 않음 (회원 기본 종류: 이름)');
    active?.cleanup();

    // 고른 종류가 허용에서 빠진 회원(U21): 그 사실과 지금 적용되는 종류를 알린다
    await renderNodes(form, ['section_basic_info'], {
      route: { id: 'uuid-1' },
      fields: fieldsResponse(sampleFields(), {
        ...base, display_name_kind: 'masked_name', display_name_kind_label: '가린 이름', display_name_kind_unavailable: true,
        display_name_effective_kind: 'name', display_name_effective_kind_label: '이름', display_name: null, display_name_admin: null,
      }),
    });
    expect(screen.getByTestId('member-status-display-name-kind').textContent).toBe('가린 이름');
    expect(screen.getByTestId('member-status-display-name-kind-unavailable').textContent).toBe('지금은 고를 수 없는 종류입니다. 회원 기본 종류(이름)로 보입니다.');
    // 보일 이름이 없으면(값이 null) 이름 줄은 그리지 않는다
    expect(document.querySelector('#member_status_display_name')).toBeNull();
    active?.cleanup();

    // 추가 화면에서는 직전 회원의 응답이 남아 있어도 그리지 않는다
    const create = await renderNodes(form, ['section_basic_info'], {
      fields: fieldsResponse(sampleFields(), { ...base, display_name_kind: 'nickname', display_name_kind_label: '닉네임', display_name: '길동이', display_name_admin: '홍길동', display_name_effective_kind_label: '닉네임' }),
    });
    expect(create.container.querySelector('#member_status_info')).toBeNull();
  });
});

describe('회원 상세 렌더 — 표시 이름 정보 (U17)', () => {
  /**
   * @scenario case=render_detail_display_name
   * @effects detail_shows_chosen_kind_and_shown_names
   */
  it('상세 화면도 회원이 고른 종류와 지금 보이는 이름을 같은 자리에 그린다', async () => {
    const { container } = await renderNodes(detail, ['section_basic_info'], {
      route: { id: 'uuid-1' },
      fields: fieldsResponse(sampleFields(), {
        nickname_changed_at: null, nickname_next_changeable_at: null, pending_required_fields: [],
        display_name_kind: 'name_nickname', display_name_kind_label: '이름(닉네임)', display_name_kind_unavailable: false,
        display_name_effective_kind: 'name_nickname', display_name_effective_kind_label: '이름(닉네임)', display_name: '홍길동(길동이)', display_name_admin: '홍길동',
      }),
      user: { data: { uuid: 'uuid-1', email: 'a@example.com', roles: [] } },
    });

    expect(container.querySelector('#member_status_info')).not.toBeNull();
    expect(screen.getByTestId('member-status-display-name-kind').textContent).toBe('이름(닉네임)');
    expect(screen.getByTestId('member-status-display-name-site').textContent).toBe('홍길동(길동이)');
    expect(screen.getByTestId('member-status-display-name-admin').textContent).toBe('홍길동');
    expect(container.querySelector('#member_status_info')!.querySelectorAll('input, select')).toHaveLength(0);
    // 두 화면의 줄이 같은 id 와 같은 번역 키를 쓴다
    for (const id of ['member_status_display_name_kind', 'member_status_display_name']) {
      expect(byId(form, id), id).toBeTruthy();
      expect(byId(detail, id), id).toBeTruthy();
    }
    for (const locale of [adminKo, adminEn] as Json[]) {
      const status = locale.users.member_fields.status;
      for (const key of ['display_name_kind', 'display_name_kind_none', 'display_name_kind_unavailable', 'display_name_kind_hint', 'display_name_current', 'display_name_site', 'display_name_admin']) {
        expect(typeof status[key], key).toBe('string');
      }
    }
  });
});

describe('회원 상세 렌더 — 항목의 보기용 값을 그린다', () => {
  const DETAIL_SECTIONS = ['user_detail_tabs', 'section_basic_info', 'section_contact_info', 'section_location_info', 'section_profile_memo', 'section_extra_fields', 'section_extension_fields'];

  /**
   * @scenario case=render_detail
   * @effects detail_renders_display_values_badges_files_and_member_status
   */
  it('코어 항목과 운영자 추가 항목의 보기용 값, 범위 배지, 파일 이름 목록, 회원 항목 상태 줄을 그린다', async () => {
    const member = { nickname_changed_at: '2026-11-01 10:00', nickname_next_changeable_at: null, required_fields_deferred_at: null, pending_required_fields: [{ key: 'job', label: '직업' }] };
    const fields = sampleFields().map((f) => (f.key === 'nickname' ? { ...f, display_value: null } : f));
    const { container } = await renderNodes(detail, DETAIL_SECTIONS, {
      route: { id: 'uuid-1' },
      fields: fieldsResponse(fields, member),
      user: { data: { uuid: 'uuid-1', email: 'a@example.com', roles: [] } },
    });
    const text = (selector: string) => container.querySelector(selector)?.textContent ?? null;
    const within = (section: string) => Array.from(container.querySelector(`#${section}`)!.querySelectorAll('[id^="field_"]')).map((el) => el.id);

    expect(within('basic_info').slice(0, 4)).toEqual(['field_uuid', 'field_name', 'field_nickname', 'field_roles']);
    expect(within('contact_info')).toEqual(['field_email', 'field_mobile']);
    expect(within('extra_fields')).toEqual(['field_job', 'field_grade', 'field_license']);

    // 관리자 화면의 이름 칸은 서버가 준 원본 값 그대로다
    expect(text('#mf_view_value_name')).toBe('홍길동');
    expect(text('#mf_view_value_nickname')).toBe('-');
    expect(text('#mf_view_value_address')).toBe('(04524) 서울 중구 세종대로 110');
    expect(text('#mf_view_value_bio')).toBe('첫 줄\n둘째 줄');
    expect(container.querySelector('#mf_view_value_bio')!.className).toContain('whitespace-pre-line');
    expect(text('#mf_view_value_job')).toBe('직장인');
    expect(text('#mf_view_value_preferred_currency')).toBe('KRW');
    expect(text('#mf_scope_grade')).toBe('관리자만 열람');

    // 파일은 이름 목록(누르면 내려받는다)이고 올리기, 지우기 단추가 없다
    expect(text('#mf_view_file_license_7')).toBe('biz.pdf');
    expect(container.querySelector('#mf_view_file_license_7 button')).not.toBeNull();
    expect(container.querySelector('#mf_view_value_license')).toBeNull();
    expect(container.querySelector('input')).toBeNull();

    // 회원 항목 상태(U17): 값이 있는 줄만
    expect(text('#member_status_nickname_changed_at')).toContain('2026-11-01 10:00');
    expect(container.querySelector('#member_status_nickname_next_changeable_at')).toBeNull();
    expect(Array.from(container.querySelectorAll('#member_status_pending_required .badge')).map((el) => el.textContent)).toEqual(['직업']);

    // 탭: 항목이 있는 묶음의 탭이 프로필/메모 뒤에 선다
    expect(screen.getAllByRole('tab').map((tab) => tab.textContent).slice(0, 7)).toEqual(['기본정보', '연락처', '위치정보', '프로필/메모', '추가 항목', '확장 항목', '활동정보']);
    expect(container.textContent).not.toMatch(/admin\.users\.|admin\.settings\./);
  });
});
