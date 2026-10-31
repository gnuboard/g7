/**
 * @file adminUserListFilterSection.test.tsx
 * @description 회원 목록 필터 조각 — 구조·렌더 테스트 (#705)
 *
 * 회원관리 필터를 쿠폰·상품·주문과 같은 슬롯 기반(기본/상세 + 검색편집)으로 바꿨다. 합격선은
 * 참조 화면과의 1:1 구조 대조라서 다음을 고정한다.
 *
 * - 슬롯 아웃렛 2개(basic_filters·detail_filters)가 있고 둘 다 `filter-grid` 를 가진다(#408 간격 회귀)
 * - 필터 행 12개가 등록 영역에 있고 `slotOrder` 가 1..12 로 끊김 없이 이어진다
 * - 초기 기본 행은 계정 상태·권한 그룹·가입일 3개다(D7) — 행마다 visibleFilters 로 슬롯이 갈린다
 * - 편집모드 토글 표현식이 참조 3화면과 문자 단위로 같다
 * - 확장점(admin_user_list_filters)이 상세 아웃렛 뒤에 있고 상세가 펼쳐질 때만 보인다
 * - 초기화 버튼 두 벌이 filter 하위 전 키와 확장 값을 비우고 병합 없이 이동한다
 * - 빠른 기간은 날짜 형식(`format: 'date'`)으로 부른다
 * - defaultFilters 목록이 init-local·init·선택기 세 곳에서 같다
 *
 * 렌더 절은 상태 행을 슬롯 속성 없이 떼어 렌더해, 상태 6종 + 「전체」 체크박스가 실제로 나오는지 본다.
 *
 * @vitest-environment jsdom
 */

import { describe, it, expect } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';
import { asArray, initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import filterSection from '../../layouts/partials/admin_user_list/_partial_filter_section.json';
import userList from '../../layouts/admin_user_list.json';

type Node = Record<string, any>;

/** JSON 트리에서 조건을 만족하는 노드를 모두 모은다. */
function collect(obj: any, predicate: (n: any) => boolean, out: any[] = []): any[] {
  if (!obj || typeof obj !== 'object') return out;
  if (predicate(obj)) out.push(obj);
  for (const value of Object.values(obj)) {
    if (Array.isArray(value)) value.forEach((v) => collect(v, predicate, out));
    else if (value && typeof value === 'object') collect(value, predicate, out);
  }
  return out;
}

/** 등록 영역의 필터 행 목록. */
function filterRows(): Node[] {
  const area = collect(filterSection, (n) => n?.id === 'slot_registration_area')[0];
  return (area?.children ?? []) as Node[];
}

/** 행 slot 표현식에서 visibleFilters 키를 뽑는다. */
function slotKey(row: Node): string | null {
  const match = String(row.slot ?? '').match(/includes\('([^']+)'\)/);
  return match ? match[1] : null;
}

const EXPECTED_KEYS = [
  'status', 'role', 'createdAt', 'lastLoginAt', 'adminScope', 'lockState',
  'dormantAt', 'statusChangedAt', 'language', 'country', 'emailVerified', 'identityVerified',
];

const DEFAULT_FILTERS = ['searchField', 'searchKeyword', 'status', 'role', 'createdAt'];

describe('회원 목록 필터 조각 — 구조', () => {
  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('슬롯 아웃렛 두 개가 있고 둘 다 filter-grid 를 가진다', () => {
    const outlets = collect(
      filterSection,
      (n) => n?.name === 'SlotContainer' && ['basic_filters', 'detail_filters'].includes(n?.props?.slotId)
    );

    expect(outlets.map((o) => o.props.slotId).sort()).toEqual(['basic_filters', 'detail_filters']);
    for (const outlet of outlets) {
      expect(outlet.props.className).toContain('filter-grid');
    }
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('상세 아웃렛은 상세검색을 펼쳤을 때만 보인다', () => {
    const detail = collect(filterSection, (n) => n?.props?.slotId === 'detail_filters')[0];

    expect(detail.if).toBe('{{_local.showDetailFilter}}');
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('필터 행 12개가 §5-1 순서대로 등록되고 slotOrder 가 1..12 로 이어진다', () => {
    const rows = filterRows();

    expect(rows).toHaveLength(12);
    expect(rows.map(slotKey)).toEqual(EXPECTED_KEYS);
    expect(rows.map((r) => Number(r.slotOrder))).toEqual([1, 2, 3, 4, 5, 6, 7, 8, 9, 10, 11, 12]);
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('모든 행이 visibleFilters 에 따라 basic/detail 슬롯으로 갈리고 filter-row 를 쓴다', () => {
    for (const row of filterRows()) {
      const key = slotKey(row);
      expect(row.slot).toBe(`{{(_local.visibleFilters || []).includes('${key}') ? 'basic_filters' : 'detail_filters'}}`);
      expect(row.props?.className).toContain('filter-row');
    }
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('행 라벨은 filter-label w-32 다(코어 관리자 관례)', () => {
    const labels = collect(filterSection, (n) => String(n?.props?.className ?? '').startsWith('filter-label'));

    expect(labels).toHaveLength(12);
    for (const label of labels) {
      expect(label.props.className).toMatch(/^filter-label w-32( |$)/);
    }
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('초기 기본 행은 계정 상태·권한 그룹·가입일 3개다 (D7)', () => {
    const basic = filterRows().map(slotKey).filter((k) => DEFAULT_FILTERS.includes(k as string));

    expect(basic).toEqual(['status', 'role', 'createdAt']);
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('검색편집 토글 표현식이 참조 화면과 문자 단위로 같다 (PC·모바일 두 벌)', () => {
    const toggles = collect(
      filterSection,
      (n) => n?.handler === 'setState' && typeof n?.params?.isEditMode === 'string'
    );

    expect(toggles).toHaveLength(2);
    for (const t of toggles) {
      expect(t.params.isEditMode).toBe('{{!_local.isEditMode}}');
      expect(t.params.showDetailFilter).toBe('{{!_local.isEditMode ? true : _local.showDetailFilter}}');
    }
  });

  // 역할은 설치마다 수십 개가 될 수 있다 — 체크박스를 늘어놓으면 행이 화면 몇 개 높이로 자란다.
  // 회원 폼의 역할 선택과 같은 「검색 + 태그」 TagInput 한 칸으로 고르고, 비우면 전체다.
  // 값은 URL(`role_ids[]`)에서 문자열로 되읽히므로 선택지 value 도 문자열 id 로 맞춘다 — 숫자면 새로고침 뒤 태그가 사라진다.
  // @scenario filter_axis=role, outcome=narrows
  // @effects filter_section_slot_structure
  it('권한 그룹은 검색형 태그 입력 한 칸이고 역할 수만큼 체크박스를 그리지 않는다', () => {
    const row = filterRows().find((r) => slotKey(r) === 'role') as Node;
    const tagInputs = collect(row, (n) => n?.name === 'TagInput');

    expect(tagInputs).toHaveLength(1);
    const tag = tagInputs[0];
    expect(tag.props.name).toBe('role');
    expect(tag.props.value).toContain('_local.filter.role');
    expect(tag.props.options).toContain('roles?.data?.data');
    expect(tag.props.options).toContain('String(r.id)');
    expect(collect(row, (n) => typeof n?.iteration?.source === 'string' && n.iteration.source.includes('roles'))).toHaveLength(0);
    expect(collect(row, (n) => n?.props?.type === 'checkbox' && n?.props?.name !== undefined)).toHaveLength(0);

    const change = (tag.actions ?? []).find((a: Node) => a.type === 'change');
    expect(JSON.stringify(change)).toContain('filter.role');
  });

  // 역할 목록(`/api/admin/roles/active`)은 목록보다 늦게 온다(냉간 8초 실측). 그 사이 URL 에서 되읽은 값만으로
  // 태그를 그리면 이름 대신 id(「1」「3」)가 보였다 — 목록이 오기 전에는 「…」 태그로 두고 입력을 잠근다.
  // 또 TagInput 의 class 는 안쪽 상자에만 붙어 바깥 칸이 내용 폭으로 줄었다 — 폭은 감싸는 div 가 맡는다.
  // @scenario filter_axis=role, outcome=narrows
  // @effects filter_section_slot_structure
  it('권한 그룹 태그는 역할 목록이 오기 전 id 를 드러내지 않고, 감싸는 칸이 폭을 차지한다', () => {
    const row = filterRows().find((r) => slotKey(r) === 'role') as Node;
    const tag = collect(row, (n) => n?.name === 'TagInput')[0];

    expect(tag.props.options).toContain("label: '…'");
    expect(tag.props.disabled).toContain('roles?.data?.data');

    const wrapper = (row.children as Node[]).find((c) => collect(c, (n) => n?.name === 'TagInput').length > 0) as Node;
    expect(wrapper.name).toBe('Div');
    expect(String(wrapper.props?.className)).toContain('flex-1');
    expect(String(tag.props.className)).toContain('w-full');
  });

  // 상품·주문·클레임 목록처럼 상세 영역 머리에 제목을 둔다(계획서 §5-8 `admin.users.filter.detail_title`).
  // 슬롯 구조(§5-2 — 상세 아웃렛의 data-if·filter-grid)는 건드리지 않고 제목 한 줄만 앞에 선다.
  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('상세 아웃렛 바로 앞에 상세 영역 제목이 있고 상세가 펼쳐질 때만 보인다', () => {
    const card = collect(filterSection, (n) => n?.id === 'filter_card')[0];
    const ids = (card.children as Node[]).map((c) => c.id ?? c.props?.slotId ?? c.name);
    const title = collect(filterSection, (n) => n?.id === 'detail_filter_title')[0];

    expect(title).toBeDefined();
    expect(title.text).toBe('$t:admin.users.filter.detail_title');
    expect(title.if).toBe('{{_local.showDetailFilter}}');
    expect(ids.indexOf('detail_filter_title')).toBe(ids.indexOf('detail_filters_section') - 1);
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('확장점은 상세 아웃렛 뒤에 있고 상세가 펼쳐질 때만 보인다', () => {
    const card = collect(filterSection, (n) => n?.id === 'filter_card')[0];
    const ids = (card.children as Node[]).map((c) => c.id ?? c.props?.slotId ?? c.name);
    const ext = collect(filterSection, (n) => n?.type === 'extension_point')[0];

    expect(ext).toBeDefined();
    expect(ext.name).toBe('admin_user_list_filters');
    expect(ext.if).toBe('{{_local.showDetailFilter}}');
    expect(ids.indexOf('user_list_filter_extension_slot')).toBeGreaterThan(ids.indexOf('detail_filters_section'));
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('빠른 기간 12개(가입일 6 + 최근 로그인 6)가 날짜 형식으로 부른다', () => {
    const quick = collect(filterSection, (n) => n?.handler === 'setDateRange');

    expect(quick).toHaveLength(12);
    expect(new Set(quick.map((q) => q.params.preset))).toEqual(new Set(['today', 'week', 'month', '3months', '6months', '1year']));
    for (const q of quick) {
      expect(q.params.format).toBe('date');
    }
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('초기화 버튼 두 벌이 filter 전 키와 확장 값을 비우고 병합 없이 이동한다', () => {
    const initKeys = Object.keys(initStateOf(userList as any).filter).sort();

    for (const id of ['reset_button_pc', 'reset_button_mobile']) {
      const button = collect(filterSection, (n) => n?.id === id)[0];
      const actions = collect(button, (n) => typeof n?.handler === 'string');
      const reset = actions.find((a) => a.handler === 'setState');
      const nav = actions.find((a) => a.handler === 'navigate');

      expect(Object.keys(reset.params.filter).sort()).toEqual(initKeys);
      expect(reset.params.userFilterExtraQuery).toEqual([]);
      expect(nav.params.mergeQuery).toBe(false);
      expect(nav.params.path).toBe('/admin/users');
    }
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('검색어 대상은 7종이다 (A1 — 화면·다국어·서버 일치)', () => {
    const select = collect(filterSection, (n) => n?.id === 'search_field_select')[0];
    const values = (select.props.options as Array<{ value: string }>).map((o) => o.value);

    expect(values).toEqual(['all', 'name', 'nickname', 'email', 'contact', 'identifier', 'admin_memo']);
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('슬롯 등록 행은 _defines 를 읽지 않고 _computed 를 쓴다 (브라우저 실측 회귀 — 투영된 행에는 _defines 가 실리지 않아 상태 체크박스 6개가 사라졌다)', () => {
    const text = JSON.stringify(filterRows(), (key, value) => (key === 'comment' ? undefined : value));

    expect(text).not.toContain('_defines');
    expect(text).toContain('_computed.userStatusOptions');
    expect(text).toContain('_computed.countryOptions');
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('상태·국가 선택지 computed 가 본체에 정의돼 있다', () => {
    const computed = (userList as any).computed ?? {};

    expect(computed.userStatusOptions).toContain('_defines');
    expect(computed.countryOptions).toContain('_defines');
  });

  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('defaultFilters 가 init-local·init·필터 표시 선택기 세 곳에서 같다', () => {
    const layout = userList as any;
    const initLocal = initStateOf(layout).visibleFilters;
    const initAction = asArray<any>(layout.init_actions).find((a: any) => a.handler === 'loadVisibleFilters');
    const selector = collect(layout, (n) => n?.name === 'FilterVisibilitySelector')[0];

    expect(initLocal).toEqual(DEFAULT_FILTERS);
    expect(initAction.params.defaultFilters).toEqual(DEFAULT_FILTERS);
    expect(initAction.params.id).toBe('admin_user_filters');
    expect(selector.props.defaultFilters).toEqual(DEFAULT_FILTERS);
    expect(selector.props.id).toBe('admin_user_filters');
  });

  // 빠른기간 선택값은 버튼 강조용 화면 상태다(참조 3화면과 같음) — URL 에 실으면 새로고침·링크 공유 뒤
  // 날짜칸과 따로 복원돼 강조가 날짜와 어긋난다. 빠른기간 키는 init-local 의 filter 에서 파생한다.
  // @scenario filter_axis=created_at, outcome=narrows
  // @effects search_users_query_contract
  it('빠른기간 선택값은 검색 URL 에 싣지 않는다 (#705 §5-3)', () => {
    const quickKeys = Object.keys(initStateOf(userList as any).filter).filter((k) => /Quick$/.test(k));
    const query = (userList as any).named_actions.searchUsers.params.query;

    expect(quickKeys.length).toBeGreaterThan(0);
    for (const key of Object.keys(query)) {
      expect(key.toLowerCase(), key).not.toContain('quick');
    }
    const serialized = JSON.stringify(query);
    for (const key of quickKeys) {
      expect(serialized, key).not.toContain(`filter.${key}`);
    }
  });
});

describe('회원 목록 열 선택기 — 표와 대조', () => {
  // 열 선택기에 표가 그리지 않는 열(`id`)이 「필수」 로 들어 있어, 선택지에는 켜진 채 보이는데 표에는 아무것도
  // 나오지 않았다(브라우저 실측: 선택기 "ID 필수", 표 머리글 이름·역할·이메일·작업).
  // @scenario filter_axis=status, outcome=narrows
  // @effects column_selector_matches_grid_columns
  it('선택지와 기본 열은 모두 표(users_data_grid)가 그리는 열이다', () => {
    const selector = collect(filterSection, (n) => n?.name === 'ColumnSelector')[0];
    const grid = collect(userList, (n) => n?.name === 'DataGrid' && n?.id === 'users_data_grid')[0];
    const gridFields = new Set<string>((grid?.props?.columns ?? []).map((c: any) => c.field));

    expect(selector).toBeTruthy();
    expect(gridFields.size).toBeGreaterThan(0);

    const options = (selector.props.columns as any[]).map((c) => c.field);
    expect(options.filter((f) => !gridFields.has(f))).toEqual([]);
    expect((selector.props.defaultColumns as string[]).filter((f) => !gridFields.has(f))).toEqual([]);
  });
});

describe('회원 목록 필터 조각 — 렌더', () => {
  // @scenario filter_axis=status, outcome=narrows
  // @effects filter_section_slot_structure
  it('계정 상태 행은 「전체」 + 상태 6종 체크박스를 그린다', async () => {
    const row = filterRows()[0];
    const { slot, slotOrder, ...detached } = row;
    const layout = {
      version: '1.0.0',
      layout_name: 'test/admin-user-filter-row',
      defines: (userList as any).defines,
      components: [detached],
    };

    const utils = createLayoutTest(layout as any, {
      componentRegistry: createMockComponentRegistryWithBasics() as any,
      locale: 'ko',
      initialState: { _local: { filter: { status: ['blocked'] }, visibleFilters: DEFAULT_FILTERS } },
      // 런타임에서는 본체 computed 가 defines 로 선택지를 만든다 — 테스트 유틸은 computed 를 돌리지 않으므로 결과를 직접 준다.
      initialData: {
        _computed: {
          userStatusOptions: (userList as any).defines.userStatusList.map((d: any) => ({ code: d.code, name: d.name })),
        },
      },
    } as any);
    await utils.render();

    const all = document.querySelector('input[name="statusAll"]') as HTMLInputElement;
    const boxes = Array.from(document.querySelectorAll('input[name="status"]')) as HTMLInputElement[];

    expect(all).not.toBeNull();
    expect(boxes.map((b) => b.value)).toEqual(['active', 'inactive', 'blocked', 'withdrawn', 'pending_verification', 'dormant']);
    // 고른 값만 체크되고 「전체」는 풀린다.
    expect(boxes.filter((b) => b.checked).map((b) => b.value)).toEqual(['blocked']);
    expect(all.checked).toBe(false);
    utils.cleanup();
  });
});
