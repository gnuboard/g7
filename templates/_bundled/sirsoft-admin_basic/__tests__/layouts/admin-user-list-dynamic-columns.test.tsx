/**
 * @file admin-user-list-dynamic-columns.test.tsx
 * @description 회원 목록의 회원 항목 표면 (#490 6단계): 추가 항목 열, 「미입력」 열, 검색 대상, 회원 항목 조건 둘
 *
 * 서버 응답(`GET /api/admin/users`)의 `member_fields.columns`, `member_fields.search_fields`, 행의 `custom_fields`,
 * `pending_required_fields` 와 요청 인자 `required_missing`, `nickname_duplicate` 를 레이아웃이 어떻게 쓰는지 잠근다.
 *
 *  - 표(`users_data_grid`)와 열 선택기(`column_selector`)가 **같은 computed**(`memberFieldColumns`)를 `dynamicColumns` 로
 *    받고 둘 다 닉네임 열 뒤에 끼운다. 한쪽만 다른 식을 쓰면 열 집합이나 순서가 갈린다.
 *  - computed 는 응답의 `field`(값 경로 `custom_fields.{키}`)와 `header` 를 그대로 옮기고 빈 값 글자 「-」를 단다.
 *    응답에 항목이 없으면 빈 배열이라 표와 선택기가 종전과 같다.
 *  - 「미입력」 열은 비어 있는 필수 항목 개수 배지(올리면 항목 이름)이고 없으면 「-」.
 *  - 검색 대상 선택지는 고정 7종 뒤에 응답의 추가 항목이 붙고, 고른 값은 종전 `filters[0][field]` 로 나간다.
 *  - 회원 항목 조건 둘은 값 `1` 인 평면 주소 인자다. 주소로 들어오면 켜진 채 상세 검색이 펼쳐지고, 초기화하면 꺼진다.
 *
 * 부품 쪽 동작(열 병합, 저장값 영속, 새 열 자동 켜짐)은 `src/components/composite/__tests__/` 의
 * `DataGrid.dynamicColumns`, `ColumnSelector.dynamicColumns`, `DataGridColumnSelectorDynamicParity` 가 본다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect, afterEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { actionsOf, asArray, initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import userList from '../../layouts/admin_user_list.json';
import filterSection from '../../layouts/partials/admin_user_list/_partial_filter_section.json';
import adminKo from '../../lang/partial/ko/admin.json';
import adminEn from '../../lang/partial/en/admin.json';

type Json = Record<string, any>;

/**
 * JSON 트리에서 조건에 맞는 노드를 모두 모읍니다.
 *
 * @param root 탐색 시작
 * @param predicate 조건
 * @param out 누적 배열
 * @returns 맞는 노드 목록
 */
function collect(root: unknown, predicate: (node: Json) => boolean, out: Json[] = []): Json[] {
  if (!root || typeof root !== 'object') return out;
  if (!Array.isArray(root) && predicate(root as Json)) out.push(root as Json);
  for (const value of Object.values(root as Json)) collect(value, predicate, out);
  return out;
}

const layout = userList as Json;
const grid = collect(layout, (n) => n.id === 'users_data_grid')[0];
const selector = collect(filterSection, (n) => n.id === 'column_selector')[0];
const searchSelect = collect(filterSection, (n) => n.id === 'search_field_select')[0];
const usersSource = asArray<Json>(layout.data_sources).find((d) => d.id === 'users')!;
// 초기 상태와 초기 액션은 굽기가 모양을 접을 수 있어 도우미로 읽는다
const initState = initStateOf(layout);
const initActions = asArray<Json>(layout.init_actions);

/** 서버가 주는 목록 응답 표본(운영자가 항목 둘을 목록 열로, 하나를 검색 대상으로 켠 상태). */
const USERS_RESPONSE = {
  data: {
    data: [
      {
        uuid: 'u-1',
        name: '김하나',
        nickname: '하나',
        custom_fields: { job: '개발자', birth: null },
        pending_required_fields: [
          { key: 'birth', label: '생년월일' },
          { key: 'address', label: '주소' },
        ],
      },
      { uuid: 'u-2', name: '이둘', nickname: null, custom_fields: { job: null, birth: '1990-01-01' }, pending_required_fields: [] },
    ],
    member_fields: {
      columns: [
        { key: 'job', field: 'custom_fields.job', header: '직업' },
        { key: 'birth', field: 'custom_fields.birth', header: '생년월일' },
      ],
      search_fields: [{ value: 'custom:job', label: '직업' }],
    },
  },
};

let active: ReturnType<typeof createLayoutTest> | null = null;

/**
 * 실제 레이아웃의 초기 상태, computed, 초기 액션을 실은 시험용 레이아웃을 띄웁니다.
 *
 * @param components 그릴 노드(실제 레이아웃에서 떼어 온 것)
 * @param options 주소 인자, 데이터소스 값, 상태, 추가 computed
 * @returns 테스트 도구
 */
async function mount(
  components: Json[],
  options: { query?: Record<string, string>; data?: Json; local?: Json; computed?: Record<string, string>; init?: boolean } = {},
) {
  const utils = createLayoutTest(
    {
      version: '1.0.0',
      layout_name: 'test/admin-user-list-member-fields',
      data_sources: [],
      initLocal: initState,
      computed: { memberFieldColumns: layout.computed.memberFieldColumns, ...(options.computed ?? {}) },
      // 주소 인자를 상태로 되읽는 초기 액션만 쓴다(필터 표시 저장값 되읽기는 이 시험의 대상이 아니다)
      init_actions: options.init ? initActions.filter((a) => a.handler === 'setState') : [],
      components: components.length > 0 ? components : [{ type: 'basic', name: 'Div', props: { 'data-testid': 'probe' } }],
    } as never,
    {
      componentRegistry: createMockComponentRegistryWithBasics() as never,
      locale: 'ko',
      translations: { admin: adminKo },
      queryParams: options.query ?? {},
      initialData: options.data ?? {},
      initialState: options.local ? { _local: options.local } : undefined,
    } as never,
  );
  active = utils;
  await utils.render();
  return utils;
}

/**
 * 슬롯으로 옮겨 그려지는 필터 행을 슬롯 속성 없이 떼어 냅니다.
 *
 * @param id 행 id
 * @returns 그 자리에서 그릴 수 있는 행
 */
function detachedRow(id: string): Json {
  const row = collect(filterSection, (n) => n.id === id)[0];
  expect(row, id).toBeTruthy();
  const { slot: _slot, slotOrder: _slotOrder, ...detached } = row;
  return detached;
}

/**
 * 목적지 주소의 쿼리스트링을 읽습니다.
 *
 * @param dest navigate 목적지
 * @returns URLSearchParams
 */
function queryOf(dest: string): URLSearchParams {
  return new URLSearchParams(dest.includes('?') ? dest.slice(dest.indexOf('?')) : '');
}

afterEach(() => {
  active?.cleanup();
  active = null;
});

describe('회원 목록 추가 항목 열: 표와 열 선택기가 같은 동적 열을 본다', () => {
  /**
   * @scenario case=shared_columns
   * @effects grid_and_selector_share_dynamic_columns_source
   */
  it('표와 열 선택기가 같은 computed 를 dynamicColumns 로 받고 둘 다 닉네임 열 뒤에 끼운다', () => {
    expect(grid.props.dynamicColumns).toBe('{{_computed.memberFieldColumns ?? []}}');
    expect(selector.props.dynamicColumns).toBe(grid.props.dynamicColumns);
    expect(grid.props.dynamicColumnsInsertAfter).toBe('nickname');
    expect(selector.props.dynamicColumnsInsertAfter).toBe('nickname');

    // 끼울 자리(닉네임 열)가 두 곳 모두에 있고, 정적 열의 순서도 두 곳이 같다. 순서가 다르면 같은 자리에 끼워도 갈린다
    const gridFields = (grid.props.columns as Json[]).map((c) => c.field);
    const selectorFields = (selector.props.columns as Json[]).map((c) => c.field);
    expect(gridFields).toContain('nickname');
    expect(selectorFields).toEqual(gridFields);

    // 표는 열 선택기가 정한 보이는 열을 받는다(표 자체 선택기는 꺼 둔다). 그래야 열 켜고 끔의 출처가 하나다
    expect(grid.props.visibleColumns).toBe('{{_global.visibleColumns_admin_users}}');
    expect(selector.props.visibleColumns).toBe(grid.props.visibleColumns);
    expect(grid.props.showColumnSelector).toBe(false);
    expect(selector.props.id).toBe('admin_user_list_columns');
  });

  /**
   * @scenario case=shared_columns
   * @effects dynamic_columns_follow_response_order_and_value_path
   */
  it('동적 열은 응답의 순서, 값 경로(field), 이름(header)을 그대로 옮기고 빈 값 글자 「-」를 단다', async () => {
    const utils = await mount([], { data: { users: USERS_RESPONSE } });

    expect(utils.getComputed('memberFieldColumns')).toEqual([
      { field: 'custom_fields.job', header: '직업', sortable: false, emptyText: '-' },
      { field: 'custom_fields.birth', header: '생년월일', sortable: false, emptyText: '-' },
    ]);
  });

  /**
   * @scenario case=shared_columns
   * @effects no_member_field_columns_leaves_list_unchanged
   */
  it('운영자가 켠 목록 열이 없으면(또는 응답이 아직 없으면) 동적 열은 빈 배열이다', async () => {
    const empty = await mount([], { data: { users: { data: { data: [], member_fields: { columns: [], search_fields: [] } } } } });
    expect(empty.getComputed('memberFieldColumns')).toEqual([]);
    empty.cleanup();

    const legacy = await mount([], { data: { users: { data: { data: [] } } } });
    expect(legacy.getComputed('memberFieldColumns')).toEqual([]);
    legacy.cleanup();

    const loading = await mount([], {});
    expect(loading.getComputed('memberFieldColumns')).toEqual([]);
  });
});

describe('회원 목록 「미입력」 열 (U16)', () => {
  const pendingColumn = (grid.props.columns as Json[]).find((c) => c.field === 'pending_required_fields')!;

  /**
   * @scenario case=pending_column
   * @effects pending_column_listed_after_nickname_in_grid_and_selector
   */
  it('표와 열 선택기 양쪽에 닉네임 바로 뒤 정적 열로 있고(동적 열은 그 사이에 끼인다) 같은 머리글을 쓴다', () => {
    const gridFields = (grid.props.columns as Json[]).map((c) => c.field);
    const option = (selector.props.columns as Json[]).find((c) => c.field === 'pending_required_fields');

    expect(gridFields[gridFields.indexOf('nickname') + 1]).toBe('pending_required_fields');
    expect(option?.header).toBe('$t:admin.users.list_member_fields.pending_column');
    expect(pendingColumn.header).toBe(option?.header);
    expect(pendingColumn.sortable).toBe(false);
  });

  /**
   * @scenario case=display_name_column
   * @effects display_name_column_static_off_by_default_in_grid_and_selector
   */
  it('「보이는 이름」 열(U16)은 표와 열 선택기 양쪽에 닉네임 앞 정적 열로 있고 기본은 꺼져 있다', async () => {
    const gridColumns = grid.props.columns as Json[];
    const gridFields = gridColumns.map((c) => c.field);
    const selectorFields = (selector.props.columns as Json[]).map((c) => c.field);
    const column = gridColumns.find((c) => c.field === 'display_name')!;
    const option = (selector.props.columns as Json[]).find((c) => c.field === 'display_name')!;

    // 닉네임 바로 앞이라 동적 열이 끼는 자리(닉네임 뒤)를 건드리지 않는다
    expect(gridFields[gridFields.indexOf('nickname') - 1]).toBe('display_name');
    expect(selectorFields[selectorFields.indexOf('nickname') - 1]).toBe('display_name');
    expect(column.header).toBe('$t:admin.users.list_member_fields.display_name_column');
    expect(option.header).toBe(column.header);
    expect(column.sortable).toBe(false);
    // 기본으로 보이는 열이 아니고 필수 열도 아니다(운영자가 열 선택기에서 켠다)
    expect(selector.props.defaultColumns).not.toContain('display_name');
    expect(column.required).toBeUndefined();
    expect(option.required).toBeUndefined();

    // 칸은 사이트 기본 자리에서 보이는 이름을 그리고, 값이 없으면 다른 빈 값처럼 「-」
    await mount(column.cellChildren, { data: { row: { uuid: 'u-1', name: '홍길동', nickname: '길동이', display_name: '길동이' } } });
    expect(document.querySelector('.display-name-cell')?.textContent).toBe('길동이');
    active?.cleanup();
    await mount(column.cellChildren, { data: { row: { uuid: 'u-2', name: '옛 응답' } } });
    expect(document.querySelector('.display-name-cell')?.textContent).toBe('-');

    expect((adminKo as Json).users.list_member_fields.display_name_column).toBe('보이는 이름');
    expect(typeof (adminEn as Json).users.list_member_fields.display_name_column).toBe('string');
  });

  /**
   * @scenario case=pending_column
   * @effects pending_badge_shows_count_and_field_names
   */
  it('비어 있는 필수 항목이 있으면 개수 배지를 그리고 올리면 항목 이름이 나온다', async () => {
    await mount(pendingColumn.cellChildren, { data: { row: USERS_RESPONSE.data.data[0] } });

    const badge = document.querySelector('.pending-required-badge') as HTMLElement;
    expect(badge).not.toBeNull();
    expect(badge.textContent).toBe('2개');
    expect(badge.getAttribute('title')).toBe('생년월일, 주소');
    expect(document.body.textContent).not.toContain('-');
  });

  /**
   * @scenario case=pending_column
   * @effects pending_empty_shows_dash
   */
  it('비어 있는 필수 항목이 없으면(빈 배열이거나 키가 없으면) 배지 없이 「-」를 그린다', async () => {
    const none = await mount(pendingColumn.cellChildren, { data: { row: USERS_RESPONSE.data.data[1] } });
    expect(document.querySelector('.pending-required-badge')).toBeNull();
    expect(document.body.textContent?.trim()).toBe('-');
    none.cleanup();

    await mount(pendingColumn.cellChildren, { data: { row: { uuid: 'u-3', name: '옛 응답' } } });
    expect(document.querySelector('.pending-required-badge')).toBeNull();
    expect(document.body.textContent?.trim()).toBe('-');
  });

  /**
   * @scenario case=pending_column
   * @effects pending_count_has_english_singular
   */
  it('배지 문구는 ko, en 에 있고 영어는 단수형(_one)을 갖는다', () => {
    const ko = (adminKo as Json).users.list_member_fields;
    const en = (adminEn as Json).users.list_member_fields;

    expect(ko.pending_count).toContain('{{count}}');
    expect(en.pending_count).toContain('{{count}}');
    expect(en.pending_count_one).toContain('{{count}}');
    // 단수형을 뺀 키 집합은 두 언어가 같다
    expect(Object.keys(en).filter((k) => !k.endsWith('_one')).sort()).toEqual(Object.keys(ko).sort());
  });
});

describe('회원 목록 검색 대상: 운영자가 켠 추가 항목이 뒤에 붙는다', () => {
  const FIXED = ['all', 'name', 'nickname', 'email', 'contact', 'identifier', 'admin_memo'];

  /**
   * @scenario case=search_field
   * @effects search_field_options_append_member_fields
   */
  it('고정 7종 뒤에 응답의 search_fields 가 순서대로 붙는다', async () => {
    const utils = await mount([], { data: { users: USERS_RESPONSE }, computed: { searchOptions: searchSelect.props.options } });
    const options = utils.getComputed('searchOptions') as Array<{ value: string; label: string }>;

    expect(options.map((o) => o.value)).toEqual([...FIXED, 'custom:job']);
    expect(options.at(-1)).toEqual({ value: 'custom:job', label: '직업' });
  });

  /**
   * @scenario case=search_field
   * @effects search_field_options_fixed_without_member_fields
   */
  it('응답에 추가 항목이 없으면 고정 7종 그대로다', async () => {
    const utils = await mount([], { data: { users: { data: { data: [] } } }, computed: { searchOptions: searchSelect.props.options } });

    expect((utils.getComputed('searchOptions') as Array<{ value: string }>).map((o) => o.value)).toEqual(FIXED);
  });

  /**
   * @scenario case=search_field
   * @effects custom_search_field_uses_existing_filter_serialization
   */
  it('추가 항목을 고르고 검색하면 종전 검색 조건 자리(filters[0][field])에 custom:{키} 로 나간다', async () => {
    const utils = await mount([], {
      local: { filter: { ...initState.filter, searchField: 'custom:job', searchKeyword: '개발' } },
    });
    await utils.triggerAction({ type: 'click', ...layout.named_actions.searchUsers } as never);
    const query = queryOf(utils.getNavigationHistory()[0]);

    expect(query.get('filters[0][field]')).toBe('custom:job');
    expect(query.get('filters[0][value]')).toBe('개발');
    expect(query.get('filters[0][operator]')).toBe('like');
    // 데이터소스는 그 주소 인자를 그대로 요청 인자로 읽는다
    expect(usersSource.params['filters[0][field]']).toBe("{{query['filters[0][field]'] || 'all'}}");
  });
});

describe('회원 목록 회원 항목 조건: 필수 항목 미입력, 닉네임 중복', () => {
  const FILTERS = [
    { key: 'requiredMissing', param: 'required_missing', row: 'required_missing_filter_row' },
    { key: 'nicknameDuplicate', param: 'nickname_duplicate', row: 'nickname_duplicate_filter_row' },
  ] as const;

  /**
   * @scenario case=member_field_filter
   * @effects member_field_filters_sent_as_flat_params
   */
  it('켠 조건은 값 1 인 평면 주소 인자로 나가고 데이터소스가 그 인자를 요청에 싣는다. 끈 조건은 주소에서 빠진다', async () => {
    for (const { param } of FILTERS) {
      expect(usersSource.params[param]).toBe(`{{query.${param}}}`);
    }

    const on = await mount([], {
      local: { filter: { ...initState.filter, requiredMissing: true, nicknameDuplicate: true } },
    });
    await on.triggerAction({ type: 'click', ...layout.named_actions.searchUsers } as never);
    const onQuery = queryOf(on.getNavigationHistory()[0]);
    expect(onQuery.get('required_missing')).toBe('1');
    expect(onQuery.get('nickname_duplicate')).toBe('1');
    on.cleanup();

    const off = await mount([], { local: { filter: { ...initState.filter } } });
    await off.triggerAction({ type: 'click', ...layout.named_actions.searchUsers } as never);
    const offQuery = queryOf(off.getNavigationHistory()[0]);
    expect(offQuery.get('required_missing') ?? '').toBe('');
    expect(offQuery.get('nickname_duplicate') ?? '').toBe('');
  });

  /**
   * @scenario case=member_field_filter
   * @effects url_entry_turns_filter_on_and_opens_detail
   */
  it('?nickname_duplicate=1 로 들어오면 닉네임 중복 조건이 켜진 채 상세 검색이 펼쳐지고 다른 조건은 꺼져 있다', async () => {
    const utils = await mount([detachedRow('nickname_duplicate_filter_row'), detachedRow('required_missing_filter_row')], {
      query: { nickname_duplicate: '1' },
      init: true,
    });
    const local = utils.getState()._local;

    expect(local.filter.nicknameDuplicate).toBe(true);
    expect(local.filter.requiredMissing).toBe(false);
    expect(local.showDetailFilter).toBe(true);
    expect((document.querySelector('input[name="nicknameDuplicate"]') as HTMLInputElement).checked).toBe(true);
    expect((document.querySelector('input[name="requiredMissing"]') as HTMLInputElement).checked).toBe(false);
  });

  /**
   * @scenario case=member_field_filter
   * @effects url_entry_turns_filter_on_and_opens_detail
   */
  it('?required_missing=1 로 들어오면 필수 항목 미입력 조건이 켜진다. 인자가 없으면 둘 다 꺼진 채 상세 검색은 접혀 있다', async () => {
    const on = await mount([detachedRow('required_missing_filter_row')], { query: { required_missing: '1' }, init: true });
    expect(on.getState()._local.filter.requiredMissing).toBe(true);
    expect(on.getState()._local.filter.nicknameDuplicate).toBe(false);
    expect(on.getState()._local.showDetailFilter).toBe(true);
    on.cleanup();

    const plain = await mount([detachedRow('required_missing_filter_row')], { init: true });
    expect(plain.getState()._local.filter.requiredMissing).toBe(false);
    expect(plain.getState()._local.filter.nicknameDuplicate).toBe(false);
    expect(plain.getState()._local.showDetailFilter).toBe(false);
  });

  /**
   * @scenario case=member_field_filter
   * @effects member_field_filter_rows_follow_filter_row_convention
   */
  it('두 조건은 기존 필터 행 관례(슬롯 등록, 기본 검색 표시 스위치, 라벨)를 따르는 체크박스 행이다', () => {
    for (const { key, row: rowId } of FILTERS) {
      const row = collect(filterSection, (n) => n.id === rowId)[0];
      expect(row.slot).toBe(`{{(_local.visibleFilters || []).includes('${key}') ? 'basic_filters' : 'detail_filters'}}`);

      const boxes = collect(row, (n) => n.name === 'Input' && n.props?.type === 'checkbox');
      expect(boxes).toHaveLength(2);
      // 앞의 것은 검색편집의 「기본 검색에 표시」 스위치, 뒤의 것이 조건 체크박스다
      expect(boxes[0].props['aria-label']).toMatch(/^\$t:admin\.users\.filter\.show_in_basic\|label=\$t:admin\.users\.list_member_fields\./);
      expect(boxes[1].props.name).toBe(key);
      expect(boxes[1].props.checked).toBe(`{{!!_local.filter.${key}}}`);
      expect(actionsOf(boxes[1])[0].params[`filter.${key}`]).toBe(`{{!_local.filter.${key}}}`);
    }
  });

  /**
   * @scenario case=member_field_filter
   * @effects reset_turns_member_field_filters_off
   */
  it('필터 초기화(넓은 화면, 좁은 화면 두 벌)는 두 조건을 끄고 주소 인자를 전부 비운다', async () => {
    for (const id of ['reset_button_pc', 'reset_button_mobile']) {
      const button = collect(filterSection, (n) => n.id === id)[0];
      const actions = collect(button, (n) => typeof n.handler === 'string');
      const reset = actions.find((a) => a.handler === 'setState')!;
      const nav = actions.find((a) => a.handler === 'navigate')!;

      expect(reset.params.filter.requiredMissing).toBe(false);
      expect(reset.params.filter.nicknameDuplicate).toBe(false);

      const utils = await mount([], {
        query: { nickname_duplicate: '1', required_missing: '1' },
        local: { filter: { ...initState.filter, requiredMissing: true, nicknameDuplicate: true } },
      });
      await utils.triggerAction({ type: 'click', ...reset } as never);
      await utils.triggerAction({ type: 'click', ...nav } as never);

      expect(utils.getState()._local.filter.requiredMissing, id).toBe(false);
      expect(utils.getState()._local.filter.nicknameDuplicate, id).toBe(false);
      // 병합하지 않으므로 목적지에 조건이 남지 않는다
      expect(utils.getNavigationHistory().at(-1), id).toBe('/admin/users');
      utils.cleanup();
    }
  });

  /**
   * @scenario case=member_field_filter
   * @effects member_field_filter_state_keys_in_init_and_reset
   */
  it('두 조건의 상태 키가 초기 상태, 주소 되읽기, 초기화 세 곳에 모두 있다', () => {
    const initSet = initActions.find((a) => a.handler === 'setState')!;

    for (const { key, param } of FILTERS) {
      expect(initState.filter[key]).toBe(false);
      expect(initSet.params._local.filter[key]).toBe(`{{['1', 'true'].includes(query.${param})}}`);
      expect(initSet.params._local.showDetailFilter).toContain(`query.${param}`);
    }
  });
});
