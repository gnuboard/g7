/**
 * 마일리지 내역 레이아웃 구조 검증 테스트
 *
 * @description
 * - admin_ecommerce_mileage_transaction_index.json + partial 4종 구조 검증
 * - 통계 카드 부재, 필터 상태 모델(검색 지연 commit / 셀렉트 즉시 commit)
 * - DataGrid selectable/rowActions(can_manage gating)/행 확장 연결거래
 * - 셀 인터랙션(주문 링크 / 회원·부여자 컨텍스트 메뉴) / 수동·연장 모달
 *
 * @vitest-environment node
 */

import { describe, it, expect } from 'vitest';

import mainLayout from '../../../layouts/admin/admin_ecommerce_mileage_transaction_index.json';
import filters from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_filters.json';
import table from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_transactions_table.json';
import modalManual from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_modal_manual_transaction.json';
import modalExtend from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_modal_extend_expiry.json';
import modalEdit from '../../../layouts/admin/partials/admin_ecommerce_mileage_transaction_index/_modal_edit_transaction.json';
import { asArray, callbacksOf, initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

function findFirst(node: any, predicate: (n: any) => boolean): any | null {
  if (!node) return null;
  if (predicate(node)) return node;
  const kids = [
    ...asArray(node?.children),
    ...(node.columns ?? []),
    ...(node.cellChildren ?? []),
    ...(node.expandChildren ?? []),
    ...asArray(node.responsive?.portable?.children),
    ...(node.props?.columns ?? []),
    ...(node.props?.expandChildren ?? []),
    ...asArray(node?.actions),
    ...callbacksOf(node, 'actions'),
  ];
  for (const child of kids) {
    const found = findFirst(child, predicate);
    if (found) return found;
  }
  return null;
}

function findAll(node: any, predicate: (n: any) => boolean): any[] {
  const out: any[] = [];
  if (!node) return out;
  if (predicate(node)) out.push(node);
  const kids = [
    ...asArray(node?.children),
    ...(node.columns ?? []),
    ...(node.cellChildren ?? []),
    ...(node.expandChildren ?? []),
    ...asArray(node.responsive?.portable?.children),
    ...(node.props?.columns ?? []),
    ...(node.props?.expandChildren ?? []),
  ];
  for (const child of kids) out.push(...findAll(child, predicate));
  return out;
}

function jsonOf(o: any): string {
  return JSON.stringify(o);
}

describe('마일리지 내역 메인 레이아웃', () => {
  /**
   * 목록 화면은 마일리지 조회 권한으로만 들어오고 관리자 기본 틀을 상속한다.
   *
   * @scenario actor_permission=no_permission, list_state=empty
   *
   * @effects list_screen_requires_read_permission_and_extends_admin_base
   */
  it('mileage.read 권한을 요구하고 _admin_base 상속', () => {
    expect(mainLayout.extends).toBe('_admin_base');
    expect(mainLayout.permissions).toContain('sirsoft-ecommerce.mileage.read');
  });

  /**
   * 목록 조회 요청이 주소의 검색·유형·정렬 값을 그대로 서버로 넘긴다.
   *
   * @scenario actor_permission=read_and_manage, search_field=member, type_filter=earn, list_state=multi_page
   *
   * @effects list_datasource_reads_filters_from_query
   */
  it('transactions 데이터소스 params 가 query.* 를 읽고 4분류 type 슬러그를 전달한다', () => {
    const ds = (mainLayout.data_sources ?? []).find((d: any) => d.id === 'transactions');
    expect(ds.params.type).toContain('query.type');
    expect(ds.params.search_field).toContain('query.search_field');
    expect(ds.params.sort).toContain('query.sort');
  });

  /**
   * 화면에 들어오면 주소의 검색어가 필터 입력칸에 복원된다.
   *
   * @scenario actor_permission=read_and_manage, search_field=member, type_filter=all, list_state=multi_page
   *
   * @effects filter_state_restored_from_query_on_entry
   */
  it('init_actions 가 query 에서 _local.filter 를 복원한다', () => {
    const setState = (mainLayout.init_actions ?? []).find((a: any) => a.handler === 'setState');
    expect(setState.params.filter.searchKeyword).toContain('query.search_keyword');
  });

  /**
   * 통계 요약 카드는 제거되어 목록 위에 나오지 않는다.
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects no_summary_stat_cards
   */
  it('상단 통계 요약 카드가 없다 (§14 통계 제거)', () => {
    const stat = findFirst(mainLayout.slots.content[0], (n) =>
      typeof n.id === 'string' && /summary|stat/i.test(n.id)
    );
    expect(stat).toBeNull();
  });

  /**
   * 수동 지급/차감 버튼은 관리 권한이 없으면 잠기고, 누르면 수동 처리 창을 연다.
   *
   * @scenario actor_permission=read_only, list_state=single_page
   *
   * @effects manual_button_disabled_when_no_manage_ability
   */
  it('수동 지급/차감 버튼이 can_manage 로 gating 되고 openModal 을 발화한다', () => {
    const btn = findFirst(mainLayout.slots.content[0], (n) => n.id === 'manual_action_button');
    expect(btn.props.disabled).toContain('can_manage !== true');
    expect(jsonOf(btn)).toContain('"openModal"');
    expect(jsonOf(btn)).toContain('modal_manual_transaction');
  });

  /**
   * 일괄 연장 바는 늘 보이고, 고른 적립건이 없거나 관리 권한이 없으면 연장 버튼이 잠긴다.
   *
   * @scenario actor_permission=read_only, list_state=multi_page
   *
   * @effects bulk_extend_bar_always_visible_and_disabled_without_selection_or_manage
   */
  it('일괄 유효기간 연장 바가 항시 표출되고 선택 0건/권한없음 시 버튼 비활성화 (주문/리뷰 batch_bar 일관)', () => {
    const bar = findFirst(mainLayout.slots.content[0], (n) => n.id === 'bulk_action_bar');
    // 리뷰/주문 batch_bar 처럼 if 조건 없이 항시 표출
    expect(bar.if).toBeUndefined();
    // 선택 카운트 표시
    const count = findFirst(bar, (n) => typeof n.text === 'string' && n.text.includes('bulk.selected'));
    expect(count).toBeTruthy();
    const extendBtn = findFirst(bar, (n) => n.id === 'bulk_extend_button');
    expect(extendBtn.props.disabled).toContain('can_manage !== true');
    expect(extendBtn.props.disabled).toContain('selectedItems');
    expect(extendBtn.props.disabled).toContain('length === 0');
  });

  /**
   * 수동 처리·유효기간 연장 창이 화면 최상위 창으로 선언된다.
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects modals_declared_top_level
   */
  it('모달 2종이 top-level modals 로 선언된다', () => {
    const partials = (mainLayout.modals ?? []).map((m: any) => m.partial);
    expect(partials.some((p: string) => p.includes('_modal_manual_transaction.json'))).toBe(true);
    expect(partials.some((p: string) => p.includes('_modal_extend_expiry.json'))).toBe(true);
  });

  /**
   * 정렬을 바꾸면 첫 페이지로 즉시 이동한다.
   *
   * @scenario actor_permission=read_and_manage, list_state=multi_page
   *
   * @effects sort_and_per_page_navigate_immediately
   */
  it('정렬 Select(최신순/금액↑↓) 이 query.sort 즉시 navigate 한다 (§14.2)', () => {
    const sortSelect = findFirst(mainLayout.slots.content[0], (n) => n.id === 'sort_select');
    expect(sortSelect).toBeTruthy();
    const values = sortSelect.props.options.map((o: any) => o.value);
    expect(values).toEqual(['created_at_desc', 'amount_desc', 'amount_asc']);
    const nav = asArray(sortSelect?.actions).find((a: any) => a.handler === 'navigate');
    expect(nav.params.query.sort).toContain('$event.target.value');
    expect(nav.params.query.page).toBe(1);
  });

  /**
   * 페이지당 개수를 바꾸면 첫 페이지로 즉시 이동한다.
   *
   * @scenario actor_permission=read_and_manage, list_state=multi_page
   *
   * @effects sort_and_per_page_navigate_immediately
   */
  it('perPage Select(10/20/50/100) 이 query.per_page 즉시 navigate 한다 (§14.1)', () => {
    const perPageSelect = findFirst(mainLayout.slots.content[0], (n) => n.id === 'per_page_select');
    expect(perPageSelect).toBeTruthy();
    const values = perPageSelect.props.options.map((o: any) => o.value);
    expect(values).toEqual(['10', '20', '50', '100']);
    const nav = asArray(perPageSelect?.actions).find((a: any) => a.handler === 'navigate');
    expect(nav.params.query.per_page).toContain('$event.target.value');
    expect(nav.params.query.page).toBe(1);
  });

  /**
   * 목록 머리에 전체 건수(총 N개)가 나온다.
   *
   * @scenario actor_permission=read_and_manage, list_state=multi_page
   *
   * @effects total_count_header_reads_pagination_total
   */
  it('table_header 가 "총 N개" 카운트(pagination.total)를 노출한다 (세 화면 일관)', () => {
    const header = findFirst(mainLayout.slots.content[0], (n) => n.id === 'table_header');
    expect(header).toBeTruthy();
    const count = findFirst(header, (n) => typeof n.text === 'string' && n.text.includes('table.total_count'));
    expect(count).toBeTruthy();
    expect(count.text).toContain('transactions?.data?.pagination?.total');
  });

  /**
   * 본문과 화면 머리가 관리자 공통 유틸 클래스를 쓴다.
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects page_header_uses_common_util_classes
   */
  it('콘텐츠/헤더가 admin 공통 유틸 클래스(p-6/page-header/page-title/page-description)를 사용한다', () => {
    const content = mainLayout.slots.content[0];
    expect(content.props.className).toContain('p-6');
    expect(content.props.className).not.toContain('sm:p-6 lg:p-8');
    const header = findFirst(content, (n) => n.id === 'page_header');
    expect(header.props.className).toBe('page-header');
    expect(findFirst(header, (n) => String(n.props?.className).includes('page-title'))).toBeTruthy();
    expect(findFirst(header, (n) => String(n.props?.className).includes('page-description'))).toBeTruthy();
  });

  /**
   * 화면 제목에 아이콘을 두지 않는다(주문·리뷰 화면과 일관).
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects page_header_uses_common_util_classes
   */
  it('타이틀(page-title)에 아이콘이 없다 (주문/리뷰 헤더 일관 — 타이틀 아이콘 제거)', () => {
    const header = findFirst(mainLayout.slots.content[0], (n) => n.id === 'page_header');
    const title = findFirst(header, (n) => String(n.props?.className).includes('page-title'));
    expect(title).toBeTruthy();
    // H1 은 text 속성만 가지고 Icon 자식이 없다
    const icon = findFirst(title, (n) => n.name === 'Icon');
    expect(icon).toBeNull();
    expect(title.text).toContain('mileage_transactions.title');
  });

  /**
   * 수동 처리 버튼이 공통 버튼 유틸을 쓴다.
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects page_header_uses_common_util_classes
   */
  it('수동 버튼이 인라인 Tailwind 대신 btn 공통 유틸을 사용한다', () => {
    const btn = findFirst(mainLayout.slots.content[0], (n) => n.id === 'manual_action_button');
    expect(btn.props.className).toContain('btn btn-primary');
  });
});

describe('필터 상태 모델 (_filters.json)', () => {
  /**
   * 검색어 입력은 바로 조회하지 않고 필터 상태에만 담아 둔다.
   *
   * @scenario actor_permission=read_and_manage, search_field=member, list_state=multi_page
   *
   * @effects classification_filters_commit_on_search_button
   */
  it('검색어 input 은 _local.filter.searchKeyword 에 지연 저장한다', () => {
    const kw = findFirst(filters, (n) => n.name === 'Input' && String(n.props?.value).includes('searchKeyword'));
    const setState = kw.actions?.find((a: any) => a.handler === 'setState');
    // 리뷰 화면 규약과 동일하게 filter 중첩 객체로 부분 갱신한다
    expect(setState.params.target).toBe('local');
    expect(setState.params.filter.searchKeyword).toContain('$event.target.value');
  });

  /**
   * 유형 선택은 바로 조회하지 않고 필터 상태에만 담아 둔다.
   *
   * @scenario actor_permission=read_and_manage, type_filter=use, list_state=multi_page
   *
   * @effects classification_filters_commit_on_search_button
   */
  it('유형(4분류) Select 은 분류성 필터이므로 지연 commit(setState _local.filter.type) 한다 (배송/쿠폰 분류필터 일관)', () => {
    // 즉시 navigate 가 아니라 _local.filter.type 에 저장 → 검색 버튼으로 commit
    const typeSelect = findFirst(filters, (n) => n.name === 'Select' && String(n.props?.value).includes('_local.filter.type'));
    expect(typeSelect).toBeTruthy();
    const nav = typeSelect.actions?.find((a: any) => a.handler === 'navigate');
    expect(nav).toBeUndefined();
    const setState = typeSelect.actions?.find((a: any) => a.handler === 'setState');
    expect(setState.params.target).toBe('local');
    expect(setState.params.filter.type).toContain('$event.target.value');
  });

  /**
   * 통화 선택은 바로 조회하지 않고 필터 상태에만 담아 둔다.
   *
   * @scenario actor_permission=read_and_manage, type_filter=all, list_state=multi_page
   *
   * @effects classification_filters_commit_on_search_button
   */
  it('통화 Select 은 분류성 필터이므로 지연 commit(setState _local.filter.currency) 한다', () => {
    const currencySelect = findFirst(filters, (n) => n.name === 'Select' && String(n.props?.value).includes('_local.filter.currency'));
    expect(currencySelect).toBeTruthy();
    const nav = currencySelect.actions?.find((a: any) => a.handler === 'navigate');
    expect(nav).toBeUndefined();
    const setState = currencySelect.actions?.find((a: any) => a.handler === 'setState');
    expect(setState.params.filter.currency).toContain('$event.target.value');
  });

  /**
   * 검색 버튼이 유형·통화·검색어·기간을 한 번에 주소로 반영하고 첫 페이지로 이동한다.
   *
   * @scenario actor_permission=read_and_manage, search_field=email, type_filter=expire, list_state=multi_page
   *
   * @effects classification_filters_commit_on_search_button
   */
  it('검색 버튼이 모든 분류·텍스트 필터(type/currency/search_keyword/날짜)를 _local.filter 에서 한 번에 commit 한다', () => {
    const searchBtn = findFirst(filters, (n) => n.id === 'search_submit_button');
    const nav = asArray(searchBtn?.actions)[0];
    // 유형/통화도 검색 버튼 commit 시점에 _local.filter 에서 읽는다 (즉시 navigate 제거 후 일관)
    expect(nav.params.query.type).toContain('_local.filter.type');
    expect(nav.params.query.currency).toContain('_local.filter.currency');
    expect(nav.params.query.search_keyword).toContain('_local.filter.searchKeyword');
    expect(nav.params.query.start_date).toContain('_local.filter.startDate');
    // 정렬/per_page 는 즉시 navigate 필터이므로 검색 버튼에서는 query 폴백으로 재포함
    expect(nav.params.query.sort).toContain('query.sort');
    expect(nav.params.query.per_page).toContain('query.per_page');
    expect(nav.params.query.page).toBe(1);
  });

  /**
   * 유형·통화 필터가 진입 시 주소에서 복원되고 초기화 버튼으로 비워진다.
   *
   * @scenario actor_permission=read_and_manage, type_filter=adjust, list_state=multi_page
   *
   * @effects filter_state_restored_from_query_on_entry
   */
  it('init_actions/state 가 type/currency 를 _local.filter 로 복원·초기화한다 (지연 commit 상태 모델)', () => {
    // state.filter 에 type/currency 키 존재
    expect(initStateOf(mainLayout).filter).toHaveProperty('type');
    expect(initStateOf(mainLayout).filter).toHaveProperty('currency');
    // init_actions 가 query 에서 복원
    const initSetState = (mainLayout.init_actions ?? []).find((a: any) => a.handler === 'setState');
    expect(initSetState.params.filter.type).toContain('query.type');
    expect(initSetState.params.filter.currency).toContain('query.currency');
    // 초기화 버튼이 type/currency 도 리셋
    const resetBtn = findFirst(filters, (n) => n.id === 'search_reset_button');
    const resetSetState = findFirst(resetBtn, (n) => n.handler === 'setState');
    expect(resetSetState.params.filter).toHaveProperty('type');
    expect(resetSetState.params.filter).toHaveProperty('currency');
  });

  /**
   * 정렬·페이지당 개수는 분류 필터와 달리 즉시 이동을 유지한다.
   *
   * @scenario actor_permission=read_and_manage, list_state=multi_page
   *
   * @effects sort_and_per_page_navigate_immediately
   */
  it('정렬/per_page Select 은 즉시 navigate 를 유지한다 (모든 레퍼런스 화면 공통 — 분류필터와 구분)', () => {
    const sortSelect = findFirst(mainLayout.slots.content[0], (n) => n.id === 'sort_select');
    const perPageSelect = findFirst(mainLayout.slots.content[0], (n) => n.id === 'per_page_select');
    expect(asArray(sortSelect?.actions).find((a: any) => a.handler === 'navigate')).toBeTruthy();
    expect(asArray(perPageSelect?.actions).find((a: any) => a.handler === 'navigate')).toBeTruthy();
  });

  /**
   * 기간 빠른선택 버튼이 필터의 시작·종료일을 채운다.
   *
   * @scenario actor_permission=read_and_manage, list_state=multi_page
   *
   * @effects date_quick_select_fills_filter_dates
   */
  it('기간 빠른선택은 setDateRange 핸들러로 _local.filter 날짜를 채운다', () => {
    const json = jsonOf(filters);
    expect(json).toContain('sirsoft-ecommerce.setDateRange');
    expect(json).toContain('$prev.startDate');
    expect(json).toContain('$prev.preset');
  });

  /**
   * 통화 선택지는 서버가 내려 준 통화 목록을 쓴다.
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects collection_returns_currencies_from_settings
   */
  it('통화 Select 은 백엔드 transactions.data.currencies 를 옵션으로 사용한다', () => {
    const currencySelect = findFirst(filters, (n) => n.name === 'Select' && String(n.props?.value).includes('_local.filter.currency'));
    expect(currencySelect).toBeTruthy();
    expect(jsonOf(currencySelect.props.options)).toContain('transactions?.data?.currencies');
  });

  /**
   * 필터 카드·날짜 입력·빠른선택이 공통 유틸을 쓰고 날짜 입력에 폭이 지정된다.
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects filter_card_uses_common_util_classes,
   *   date_input_has_width_class
   */
  it('필터 카드/입력/빠른선택이 admin 공통 유틸(card/input/btn-date)을 사용한다', () => {
    expect(filters.props.className).toContain('card');
    const dateInput = findFirst(filters, (n) => n.name === 'Input' && n.props?.type === 'date');
    expect(dateInput.props.className).toContain('input');
    expect(dateInput.props.className).toContain('w-36');
    const json = jsonOf(filters);
    expect(json).toContain('btn-date');
    // 인라인 폭 미지정으로 인한 date input 세로 늘어짐 회귀 차단
    const dateInputs = findAll(filters, (n) => n.name === 'Input' && n.props?.type === 'date');
    for (const di of dateInputs) {
      expect(String(di.props.className)).toMatch(/\bw-\d/);
    }
  });

  /**
   * 필터 행마다 검색·유형·통화·기간 레이블이 붙는다.
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects filter_rows_labeled_and_actions_centered
   */
  it('각 필터 행에 필터명 레이블(검색/유형/통화/기간)이 명시된다 (주문/리뷰 필터 레이블 일관)', () => {
    const json = jsonOf(filters);
    expect(json).toContain('filter.label_search');
    expect(json).toContain('filter.label_type');
    expect(json).toContain('filter.label_currency');
    expect(json).toContain('filter.label_date');
    // 리뷰 화면과 동일한 레이블 유틸 클래스
    const labels = findAll(filters, (n) => n.name === 'Span' && String(n.props?.className).includes('text-label-subtle'));
    expect(labels.length).toBeGreaterThanOrEqual(4);
  });

  /**
   * 검색·초기화 버튼이 필터 카드 하단 가운데에 놓인다.
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects filter_rows_labeled_and_actions_centered
   */
  it('검색/초기화 버튼이 filter_actions(border-t + justify-center) 하단 중앙에 배치된다', () => {
    const actions = findFirst(filters, (n) => n.id === 'filter_actions');
    expect(actions).toBeTruthy();
    expect(actions.props.className).toContain('border-t');
    // 중앙 정렬 컨테이너에 검색/초기화 버튼이 함께 위치
    const centerBox = findFirst(actions, (n) => String(n.props?.className).includes('justify-center'));
    expect(centerBox).toBeTruthy();
    expect(findFirst(centerBox, (n) => n.id === 'search_submit_button')).toBeTruthy();
    expect(findFirst(centerBox, (n) => n.id === 'search_reset_button')).toBeTruthy();
  });
});

describe('DataGrid (_transactions_table.json)', () => {
  const grid = findFirst(table, (n) => n.name === 'DataGrid');

  /**
   * 목록 표는 행 선택·서버 페이지 넘김·행 펼치기를 지원한다.
   *
   * @scenario actor_permission=read_and_manage, list_state=multi_page
   *
   * @effects grid_selectable_server_paginated_expandable
   */
  it('selectable + 서버사이드 페이지네이션 + expandable', () => {
    expect(grid.props.selectable).toBe(true);
    expect(grid.props.serverSidePagination).toBe(true);
    expect(grid.props.expandable).toBe(true);
    expect(grid.props.data).toContain('transactions?.data?.data');
  });

  /**
   * 목록 표에 번호·일시·회원·유형·금액·잔여·유효기간·주문·부여 9개 열이 있다.
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects grid_columns_render_transaction_fields
   */
  it('컬럼 9종(번호/일시/회원/유형/금액/잔여/유효기간/주문/부여)', () => {
    // 첫 컬럼 field 는 `number` 다 (표시 헤더는 "NO"). 종전 `no` 표기는 레이아웃에서
    // 이미 바뀌었는데 이 단언만 남아 있던 stale 케이스였다.
    const fields = grid.props.columns.map((c: any) => c.field);
    expect(fields).toEqual(expect.arrayContaining(['number', 'created_at', 'member', 'type', 'amount', 'remaining', 'expiry', 'order', 'granted_by']));
  });

  /**
   * 유효기간 열이 만료 예정·무기한·없음과 일부/전체 소멸을 구분해 보여 준다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_active, expiry_display=unlimited
   *
   * @effects expiry_column_shows_partial_vs_full_with_amount
   */
  it('유효기간 컬럼이 expiry_state 기준 소멸(일부/전체)·만료예정·무기한·없음을 렌더한다', () => {
    const expiryCol = grid.props.columns.find((c: any) => c.field === 'expiry');
    expect(expiryCol).toBeTruthy();
    const json = jsonOf(expiryCol);
    // 만료 예정: expires_at_formatted
    expect(json).toContain('expires_at_formatted');
    // 무기한 / 없음 분기
    expect(json).toContain('mileage_transactions.cell.unlimited');
    expect(json).toContain('mileage_transactions.cell.none');
    // 소멸 분기는 expiry_state(fully/partial) 기준 (expired_at 단독 아님 — 비적립계 소멸거래 행 오인 방지)
    const expiredBlock = findFirst(expiryCol, (n) => n.if && String(n.if).includes('expiry_state'));
    expect(expiredBlock).toBeTruthy();
  });

  /**
   * 일부 소멸이면 일부 소멸 배지와 소멸액을, 전체 소멸이면 전체 소멸 배지와 만료일을 함께 보여 준다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_expired, expiry_display=partial_expired
   *
   * @effects expiry_column_shows_partial_vs_full_with_amount,
   *   partial_expiry_shows_amount_in_expiry_column
   */
  it('B-3: 부분 소멸 시 일부/전체 구분 배지 + 소멸액을 유효기간 열에 통합 표시한다', () => {
    const expiryCol = grid.props.columns.find((c: any) => c.field === 'expiry');
    const json = jsonOf(expiryCol);
    // 일부/전체 소멸 구분 배지
    expect(json).toContain('mileage_transactions.cell.partial_expired');
    expect(json).toContain('mileage_transactions.cell.expired');
    // 소멸액 표시 (Resource 집계 expired_amount_formatted)
    expect(json).toContain('mileage_transactions.cell.expired_amount');
    expect(json).toContain('expired_amount_formatted');
    // partial 일 때 partial 배지, 아니면 fully 배지로 분기
    expect(json).toContain("expiry_state === 'partial_expired'");
    expect(json).toContain("expiry_state === 'fully_expired'");

    // 회귀: cellChildren 안에서 row 를 번역 파라미터(|amount=)로 쓸 땐 $t:defer: 필수.
    // $t: 만 쓰면 props 처리 시점에 row 컨텍스트가 없어 amount 가 빈 문자열 → " 소멸" 만 렌더.
    const amountSpan = findFirst(expiryCol, (n) =>
      typeof n.text === 'string' && n.text.includes('cell.expired_amount')
    );
    expect(amountSpan).toBeTruthy();
    expect(amountSpan.text.startsWith('$t:defer:')).toBe(true);
    expect(amountSpan.text).toContain('amount={{row.expired_amount_formatted}}');

    // 회귀: 소멸 행도 정상 행과 동일하게 만료 예정일(expires_at_formatted)을 함께 표시해야 한다
    // (소멸 배지/소멸액만 있고 날짜가 빠지면 "언제 만료인지" 정보 누락)
    const expiredBlock = findFirst(expiryCol, (n) =>
      n.if && String(n.if).includes("expiry_state === 'fully_expired'")
    );
    expect(expiredBlock).toBeTruthy();
    const expiryDateSpan = findFirst(expiredBlock, (n) =>
      typeof n.text === 'string' && n.text.includes('row.expires_at_formatted')
    );
    expect(expiryDateSpan).toBeTruthy();
  });

  /**
   * 주문번호를 누르면 주문 상세가 새 창으로 열리고, 주문이 없으면 대시가 나온다.
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects order_cell_opens_order_detail_in_new_window
   */
  it('주문번호 셀이 주문 상세를 새 창(openWindow)으로 열고 주문 없으면 "-" 노출', () => {
    const orderCol = grid.props.columns.find((c: any) => c.field === 'order');
    const json = jsonOf(orderCol);
    expect(json).toContain('/admin/ecommerce/orders/');
    expect(json).toContain('mileage_transactions.cell.none');
    // 새 창 열기: navigate 가 아니라 openWindow
    const link = findFirst(orderCol, (n) => n.if && String(n.if).includes('row.order_number') && n.actions);
    const click = asArray(link?.actions).find((a: any) => a.type === 'click');
    expect(click.handler).toBe('openWindow');
    expect(click.params.path).toContain('row.order_number');
  });

  /**
   * 목록 표를 흰 카드로 감싸지 않아 표 아래 페이지 넘김이 카드에 갇히지 않는다.
   *
   * @scenario actor_permission=read_and_manage, list_state=multi_page
   *
   * @effects grid_selectable_server_paginated_expandable
   */
  it('그리드 래퍼가 흰색 박스가 아니라 리뷰 화면과 동일한 overflow-hidden 컨테이너다 (페이지네이션 흰박스 갇힘 회귀 차단)', () => {
    const wrap = findFirst(table, (n) => n.id === 'mileage_transactions_grid_wrap');
    expect(wrap).toBeTruthy();
    expect(wrap.props.className).toContain('overflow-hidden');
    // 별도 흰색 카드 박스로 DataGrid 내장 페이지네이션을 가두지 않는다
    expect(wrap.props.className).not.toContain('bg-white');
    expect(wrap.props.className).not.toContain('rounded-lg');
  });

  /**
   * 회원 칸에 회원정보·이 회원 검색 메뉴가 붙는다.
   *
   * @scenario actor_permission=read_and_manage, search_field=member_id, list_state=single_page
   *
   * @effects member_and_granter_cells_have_context_menu
   */
  it('회원 셀은 ActionMenu 컨텍스트 메뉴(회원정보/이회원검색)를 가진다', () => {
    const memberCol = grid.props.columns.find((c: any) => c.field === 'member');
    const menu = findFirst(memberCol, (n) => n.name === 'ActionMenu');
    expect(menu).toBeTruthy();
    const ids = menu.props.items.map((i: any) => i.id);
    expect(ids).toEqual(expect.arrayContaining(['view_member', 'search_member']));
  });

  /**
   * 회원·부여 칸이 아이콘과 이름 칩으로 표시된다.
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects grid_columns_render_transaction_fields
   */
  it('회원/부여 셀이 아바타 아이콘 + 이름 칩 디자인을 사용한다 (쿠폰 등록자 칩 일관)', () => {
    // 파란 텍스트 링크가 아니라 Icon + 이름 칩 구조
    const memberCol = grid.props.columns.find((c: any) => c.field === 'member');
    const memberChipIcon = findFirst(memberCol, (n) => n.name === 'Icon' && /fa-user\b/.test(String(n.props?.name)));
    expect(memberChipIcon).toBeTruthy();
    expect(jsonOf(memberCol)).not.toContain('text-blue-600 dark:text-blue-400 cursor-pointer hover:underline');

    const grantedCol = grid.props.columns.find((c: any) => c.field === 'granted_by');
    const grantedChipIcon = findFirst(grantedCol, (n) => n.name === 'Icon' && /user-shield/.test(String(n.props?.name)));
    expect(grantedChipIcon).toBeTruthy();
  });

  /**
   * 부여 칸은 부여 관리자가 있으면 메뉴를, 없으면 시스템 표시를 보여 준다.
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects member_and_granter_cells_have_context_menu
   */
  it('부여 셀은 관리자 uuid 존재 시 ActionMenu, NULL 이면 시스템 표시', () => {
    const grantedCol = grid.props.columns.find((c: any) => c.field === 'granted_by');
    const menu = findFirst(grantedCol, (n) => n.name === 'ActionMenu' && n.if && String(n.if).includes('granted_by_uuid'));
    expect(menu).toBeTruthy();
    const systemSpan = findFirst(grantedCol, (n) => n.if && String(n.if).includes('!row.granted_by_uuid'));
    expect(systemSpan).toBeTruthy();
  });

  /**
   * 유형 배지가 공통 배지 5색 매핑을 쓴다.
   *
   * @scenario actor_permission=read_and_manage, type_filter=all, list_state=single_page
   *
   * @effects grid_columns_render_transaction_fields
   */
  it('유형 배지가 admin_badge_group 5색 매핑을 사용한다', () => {
    const typeCol = grid.props.columns.find((c: any) => c.field === 'type');
    const json = jsonOf(typeCol);
    expect(json).toContain('admin_badge_group');
    expect(json).toContain('teal');
    expect(json).toContain('amber');
  });

  /**
   * 행 메뉴의 사유·기간 변경은 적립 거래이고 관리 권한이 있을 때만 켜지며, 삭제 메뉴는 없다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=non_earning
   *
   * @effects row_data_exposes_can_edit_ability,
   *   row_edit_disabled_for_non_earning
   */
  it('rowActions 의 사유·기간 변경 항목이 can_edit 로 gating 된다 (적립계만 + 권한)', () => {
    const edit = grid.props.rowActions.find((a: any) => a.id === 'edit');
    expect(edit).toBeTruthy();
    // 비적립계 또는 권한없음이면 비활성 — Resource 가 can_edit = can_manage && isEarning 으로 계산
    expect(edit.disabledField).toBe('abilities.can_edit');
    // 원장 불변: 삭제 액션은 존재하지 않는다 (정책 확정)
    const ids = grid.props.rowActions.map((a: any) => a.id);
    expect(ids).not.toContain('delete');
    expect(ids).not.toContain('manual');
  });

  /**
   * 사유·기간 변경을 고르면 그 행의 거래 정보를 편집 창 상태에 담고 편집 창을 연다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_active
   *
   * @effects row_edit_action_loads_row_and_opens_edit_modal
   */
  it('onRowAction edit 이 행 거래 데이터를 _global.mileageEdit 로 적재하고 편집 모달을 연다', () => {
    const rowAction = asArray(grid?.actions).find((a: any) => a.event === 'onRowAction');
    const editCase = rowAction.cases.edit;
    const setState = asArray(editCase?.actions).find((a: any) => a.handler === 'setState');
    const edit = setState.params.mileageEdit;
    // 거래 id + 읽기전용 정보(회원/금액/유형/적립일) + 편집 초기값(memo/만료일) + 기간변경 가능 여부
    expect(String(edit.id)).toContain('$args[1].id');
    expect(String(edit.memberName)).toContain('user_name');
    expect(String(edit.amountFormatted)).toContain('amount_formatted');
    expect(String(edit.typeLabel)).toContain('type_label');
    expect(String(edit.createdAtFormatted)).toContain('created_at_formatted');
    expect(String(edit.canEditExpiry)).toContain('can_edit_expiry');
    expect(String(edit.memo)).toContain('$args[1].memo');
    expect(String(edit.expiresAt)).toContain('expires_at_date');
    // 진입 즉시 편집 모달을 연다
    const open = asArray(editCase?.actions).find((a: any) => a.handler === 'openModal');
    expect(open.target).toBe('modal_edit_transaction');
  });

  /**
   * 행 펼치기는 표의 펼치기 기능 하나로만 제공되고 행 메뉴에 중복되지 않는다.
   *
   * @scenario actor_permission=read_and_manage, list_state=single_page
   *
   * @effects row_expand_unified_via_datagrid_expandable
   */
  it('행 확장은 DataGrid expandable 로 일원화되어 rowAction expand 중복이 없다 (B5)', () => {
    const ids = grid.props.rowActions.map((a: any) => a.id);
    expect(ids).not.toContain('expand');
    expect(grid.props.expandable).toBe(true);
  });

  /**
   * 표의 현재 페이지·전체 페이지가 서버 응답의 페이지 정보를 읽는다.
   *
   * @scenario actor_permission=read_and_manage, list_state=multi_page
   *
   * @effects collection_returns_pagination_block
   */
  it('서버 페이지네이션이 백엔드 pagination 응답 경로를 읽는다 (A1 회귀 차단)', () => {
    expect(String(grid.props.serverCurrentPage)).toContain('transactions?.data?.pagination?.current_page');
    expect(String(grid.props.serverTotalPages)).toContain('transactions?.data?.pagination?.last_page');
  });

  /**
   * 행을 펼치면 그 거래의 연결 거래를 조회한다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_active
   *
   * @effects expand_row_shows_linked_transactions
   */
  it('행 확장 시 연결 거래(linkedTransactions)를 onExpandChange 에서 조회한다', () => {
    const expandAction = asArray(grid?.actions).find((a: any) => a.event === 'onExpandChange');
    const json = jsonOf(expandAction);
    expect(json).toContain('/linked');
    expect(json).toContain('linkedTransactions');
  });

  /**
   * 연결 거래 바인딩에 객체 기본값을 섞지 않아 펼친 행에 연결 거래가 실제로 나온다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_active
   *
   * @effects expand_row_shows_linked_transactions
   */
  it('expandContext.linkedTransactions 표현식에 객체 리터럴 fallback(|| {})이 없어야 한다 (회귀)', () => {
    // 회귀: renderExpandContent 의 단일 바인딩 판별 정규식 /^\{\{([^}]+)\}\}$/ 은 `}` 를 허용하지 않는다.
    // expandContext 값이 "{{_local.linkedTransactions || {} }}" 처럼 내부에 `}` 를 포함하면
    // 단일 바인딩으로 인식되지 못해 평가 없이 원본 문자열 그대로 전달 → linkedTransactions?.[row.id]
    // 가 문자열 인덱스 조회가 되어 항상 빈 값 → "연결 거래 없음" 고정.
    // 따라서 expandContext 값은 `}` 를 포함하지 않는 단일 바인딩이어야 한다.
    const expr = grid.props.expandContext?.linkedTransactions;
    expect(typeof expr).toBe('string');
    expect(expr).toBe('{{_local.linkedTransactions}}');
    // 내부에 `}` (객체 리터럴 fallback) 가 없어야 한다
    const inner = expr.replace(/^\{\{/, '').replace(/\}\}$/, '');
    expect(inner).not.toContain('}');
    expect(inner).not.toContain('{');
  });

  /**
   * 연결 거래가 없으면 연결 거래 없음 안내가 나온다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_active
   *
   * @effects expand_row_shows_linked_transactions
   */
  it('확장 영역이 연결 거래 없을 때 "연결 거래 없음" 안내', () => {
    const json = jsonOf(grid.props.expandChildren);
    expect(json).toContain('linked.empty');
  });

  /**
   * 연결 거래 항목이 주문번호(새 창 열기)와 부여자 또는 시스템 표시를 보여 준다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_active
   *
   * @effects expand_row_shows_linked_transactions
   */
  it('연결 거래 항목이 주문번호(클릭 시 주문 상세 새 창)와 부여자/시스템 정보를 노출한다', () => {
    const json = jsonOf(grid.props.expandChildren);
    // 주문번호 링크 → openWindow 로 주문 상세
    expect(json).toContain('linked.order_number');
    expect(json).toContain('/admin/ecommerce/orders/');
    // 부여자 이름 노출
    expect(json).toContain('linked.granted_by_name');
    // 부여자도 주문도 없으면 "시스템" 표시
    expect(json).toContain('mileage_transactions.cell.system');
    // 주문번호 항목이 클릭으로 새 창을 연다 (iteration 항목 내부)
    const orderLink = findFirst(grid.props.expandChildren[0], (n) =>
      n.if && String(n.if).includes('linked.order_number') && n.actions
    );
    expect(orderLink).toBeTruthy();
    const click = asArray(orderLink?.actions).find((a: any) => a.type === 'click');
    expect(click.handler).toBe('openWindow');
    expect(click.params.path).toContain('linked.order_number');
  });
});

describe('수동 지급/차감 모달 (_modal_manual_transaction.json)', () => {
  /**
   * 수동 처리 창의 회원 검색이 코어 회원 검색을 지연 호출하는 자동완성이다.
   *
   * @scenario actor_permission=read_and_manage
   *
   * @effects manual_modal_member_search_submits_uuid
   */
  it('회원 검색 input 이 코어 users/search debounce 자동완성을 사용한다', () => {
    const searchInput = findFirst(modalManual, (n) => n.name === 'Input' && n.props?.debounce);
    const apiCall = findFirst(asArray(searchInput?.actions)[0], (n) => n.handler === 'apiCall');
    expect(apiCall.target).toContain('/api/admin/users/search');
  });

  /**
   * 지급/차감을 고르고, 차감액이 잔액을 넘으면 경고가 나오며 회원 미선택 시 실행이 잠긴다.
   *
   * @scenario actor_permission=read_and_manage
   *
   * @effects manual_modal_deduct_exceed_warning_and_submit_guard
   */
  it('지급/차감 라디오 + 차감 잔액초과 경고 + 실행 disabled', () => {
    const json = jsonOf(modalManual);
    expect(json).toContain('mileageManual.action');
    const warning = findFirst(modalManual, (n) => n.id === 'deduct_exceed_warning');
    expect(warning.if).toContain('selectedMember?.balance');
    const submit = findFirst(modalManual, (n) => n.id === 'manual_submit_button');
    expect(submit.props.disabled).toContain('selectedMember');
  });

  /**
   * 수동 처리 제출이 성공하면 창을 닫고 목록을 다시 읽고 안내를 띄운다.
   *
   * @scenario actor_permission=read_and_manage
   *
   * @effects manual_modal_submit_refreshes_list_on_success
   */
  it('제출 POST onSuccess(closeModal+refetch+toast) — sequence 로 감싼다', () => {
    const submit = findFirst(modalManual, (n) => n.id === 'manual_submit_button');
    const apiCall = findFirst(submit, (n) => n.handler === 'apiCall');
    expect(apiCall.params.method).toBe('POST');
    const handlers = asArray(apiCall?.onSuccess).map((a: any) => a.handler);
    expect(handlers).toEqual(expect.arrayContaining(['closeModal', 'refetchDataSource', 'toast']));
  });

  // 회귀: 회원 식별자는 uuid 로 전송 (코어 UserResource 가 id 미노출 — 정수 id 전송 시 422)
  /**
   * 고른 회원은 uuid 로 저장되고 제출 시 그 uuid 를 회원 식별자로 보낸다.
   *
   * @scenario actor_permission=read_and_manage
   *
   * @effects manual_modal_member_search_submits_uuid
   */
  it('selectedMember 는 member.uuid 를 저장하고 body 는 uuid 를 user_id 로 전송한다', () => {
    // 검색 결과 클릭 → uuid 저장 (id 추출 금지)
    const pickAction = findFirst(modalManual, (n) =>
      n.handler === 'setState' && String(n.params?.['mileageManual.selectedMember']).includes('member.uuid')
    );
    expect(pickAction).toBeTruthy();
    expect(String(pickAction.params['mileageManual.selectedMember'])).not.toContain('member.id');
    // 제출 body 가 selectedMember.uuid 를 user_id 로 보낸다
    const apiCall = findFirst(findFirst(modalManual, (n) => n.id === 'manual_submit_button'), (n) => n.handler === 'apiCall');
    expect(apiCall.params.body).toContain('user_id: _global.mileageManual?.selectedMember?.uuid');
    expect(apiCall.params.body).not.toContain('selectedMember?.id');
  });

  // 회귀: 검색 결과 드롭다운은 부유(absolute) — 모달 본문을 밀어내지 않는다
  /**
   * 회원 검색 결과 목록이 창 본문을 밀어내지 않고 위에 떠서 나온다.
   *
   * @scenario actor_permission=read_and_manage
   *
   * @effects manual_modal_member_search_submits_uuid
   */
  it('검색 결과 드롭다운이 부유(absolute z-index)이고 부모 블록이 relative 다', () => {
    const block = findFirst(modalManual, (n) => n.id === 'member_search_block');
    expect(block.props.className).toContain('relative');
    const results = findFirst(modalManual, (n) => n.id === 'member_search_results');
    expect(results.props.className).toContain('absolute');
    expect(results.props.className).toMatch(/\bz-\d/);
  });

  // 회귀: G7 표준 검증 피드백 3종 (상단 배너 + 필드 강조 + 필드 하단 문구)
  /**
   * 수동 처리 검증 실패 시 상단 안내·입력칸 강조·칸 아래 문구 세 가지가 함께 나온다.
   *
   * @scenario actor_permission=read_and_manage
   *
   * @effects manual_modal_validation_feedback_and_saving_state
   */
  it('G7 표준 검증 피드백 3종 — 상단 배너 + 필드별 input-error 강조 + 하단 인라인 메시지', () => {
    // ① 상단 에러 배너
    const banner = findFirst(modalManual, (n) => n.id === 'manual_error_banner');
    expect(banner.if).toContain('mileageManual?.errors');
    expect(jsonOf(banner)).toContain('Object.entries(_global.mileageManual?.errors?.errors');
    // ② 필드 강조 (amount/memo/user_id className 조건부 input-error)
    const json = jsonOf(modalManual);
    expect(json).toContain("errors?.errors?.amount ? 'input flex-1 input-error");
    expect(json).toContain("errors?.errors?.memo ? 'input w-full input-error");
    // ③ 필드 하단 인라인 메시지
    const amountMsg = findFirst(modalManual, (n) =>
      n.name === 'Span' && n.if && String(n.if).includes('errors?.errors?.amount')
    );
    expect(amountMsg).toBeTruthy();
    const memoMsg = findFirst(modalManual, (n) =>
      n.name === 'Span' && n.if && String(n.if).includes('errors?.errors?.memo')
    );
    expect(memoMsg).toBeTruthy();
  });

  // 회귀: onError 가 toast 단건이 아니라 errors 객체를 _global 에 저장한다
  /**
   * 수동 처리 실패 응답의 오류를 창 상태에 담고 저장 중 표시를 끈다.
   *
   * @scenario actor_permission=read_and_manage
   *
   * @effects manual_modal_validation_feedback_and_saving_state
   */
  it('onError 가 errors 를 _global.mileageManual.errors 에 저장하고 isSaving 을 false 로 복원한다', () => {
    const apiCall = findFirst(findFirst(modalManual, (n) => n.id === 'manual_submit_button'), (n) => n.handler === 'apiCall');
    const errSet = asArray(apiCall?.onError).find((a: any) => a.handler === 'setState');
    expect(errSet.params['mileageManual.errors']).toBe('{{error}}');
    expect(errSet.params['mileageManual.isSaving']).toBe(false);
  });

  // 회귀: 유효기간 영역에 '유효기간' 제목 라벨이 있어야 한다 (사용자가 무엇을 정하는지 인지)
  /**
   * 지급일 때만 유효기간 영역이 나오고 정책 따름/직접 지정을 고를 수 있다.
   *
   * @scenario actor_permission=read_and_manage
   *
   * @effects manual_modal_earn_expiry_policy_or_custom_date
   */
  it('지급 시 유효기간 영역이 "유효기간" 라벨을 노출하고 정책따름/직접지정 라디오를 가진다', () => {
    const block = findFirst(modalManual, (n) => n.id === 'earn_expiry_block');
    expect(block).toBeTruthy();
    // 지급(earn) 모드에서만 노출
    expect(block.if).toContain("=== 'earn'");
    // 제목 라벨 (modal_manual.expiry)
    const title = findFirst(block, (n) =>
      n.name === 'Label' && typeof n.text === 'string' && n.text.includes('modal_manual.expiry')
    );
    expect(title).toBeTruthy();
    // 정책따름/직접지정 라디오 2종
    const json = jsonOf(block);
    expect(json).toContain('modal_manual.expiry_policy');
    expect(json).toContain('modal_manual.expiry_custom');
    expect(json).toContain('mileageManual.useDefaultExpiry');
  });

  // 회귀: '직접 지정' 라디오와 날짜 입력이 같은 flex Label 에 묶이면 텍스트가 줄바꿈된다 → 분리
  /**
   * 직접 지정 날짜 입력은 라디오 옆이 아니라 별도 전체 폭 줄에 나온다.
   *
   * @scenario actor_permission=read_and_manage
   *
   * @effects manual_modal_earn_expiry_policy_or_custom_date
   */
  it('날짜 입력이 라디오 Label 밖 별도 풀너비(w-full) 행으로 분리된다 (직접지정 텍스트 줄바꿈 회귀 차단)', () => {
    const block = findFirst(modalManual, (n) => n.id === 'earn_expiry_block');
    const dateInput = findFirst(block, (n) => n.name === 'Input' && n.props?.type === 'date');
    expect(dateInput).toBeTruthy();
    // 직접 지정 선택 시에만 표출
    expect(dateInput.if).toContain('useDefaultExpiry === false');
    // 풀 너비 (라디오 줄에 끼어 들어가 줄바꿈되지 않도록)
    expect(String(dateInput.props.className)).toContain('w-full');
    // 날짜 Input 이 '직접 지정' Span 과 같은 Label(부모) 안에 있지 않아야 한다
    const customLabel = findFirst(block, (n) =>
      n.name === 'Label' && findFirst(n, (m) => m.name === 'Span' && String(m.text).includes('expiry_custom'))
    );
    expect(customLabel).toBeTruthy();
    const dateInsideCustomLabel = findFirst(customLabel, (n) => n.name === 'Input' && n.props?.type === 'date');
    expect(dateInsideCustomLabel).toBeNull();
  });

  // 회귀: 로딩 상태 (modal-usage.md 일반 액션 버튼 표준)
  /**
   * 수동 처리 실행 버튼이 저장 중에는 잠기고 스피너와 진행 문구를 보여 준다.
   *
   * @scenario actor_permission=read_and_manage
   *
   * @effects manual_modal_validation_feedback_and_saving_state
   */
  it('실행 버튼이 isSaving 스피너 + disabled + 텍스트 변경을 갖는다', () => {
    const submit = findFirst(modalManual, (n) => n.id === 'manual_submit_button');
    expect(submit.props.disabled).toContain('isSaving');
    const spinner = findFirst(submit, (n) => n.name === 'Icon' && n.props?.name === 'spinner');
    expect(spinner.if).toContain('isSaving');
    const label = findFirst(submit, (n) => n.name === 'Span');
    expect(label.text).toContain('isSaving ?');
    expect(label.text).toContain('submitting');
    // sequence 첫 액션이 isSaving=true + errors 초기화
    const seq = asArray(submit?.actions).find((a: any) => a.handler === 'sequence');
    const first = callbacksOf(seq, 'actions')[0];
    expect(first.params['mileageManual.isSaving']).toBe(true);
    expect(first.params['mileageManual.errors']).toBe(null);
  });
});

describe('유효기간 연장 모달 (_modal_extend_expiry.json)', () => {
  /**
   * 회귀(#703): 회원을 하나로 지정하면 여러 회원의 적립건을 골랐을 때 나머지 회원 적립건이 조용히 빠진다.
   *
   * @scenario selection=multiple_members, user_id_param=omitted
   *
   * @effects all_selected_members_lots_extended
   */
  it('extend-expiry POST 로 lot_ids/days 만 전달하고 회원(user_id)은 지정하지 않는다 — sequence 로 감싼다', () => {
    const submit = findFirst(modalExtend, (n) => n.id === 'extend_submit_button');
    const apiCall = findFirst(submit, (n) => n.handler === 'apiCall');
    expect(apiCall.target).toContain('/extend-expiry');
    expect(apiCall.params.body).toContain('lot_ids');
    expect(apiCall.params.body).toContain('days');
    expect(apiCall.params.body).not.toContain('user_id');
    expect(String(submit.props.disabled)).not.toContain('userId');
  });

  // 회귀: G7 표준 검증 피드백 + 로딩 상태
  /**
   * 유효기간 연장 창이 검증 실패 안내·일수 칸 강조·저장 중 잠금을 갖는다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_active
   *
   * @effects extend_modal_validation_feedback_and_saving_state
   */
  it('상단 에러 배너 + days 필드 강조/하단 메시지 + isSaving 로딩 상태를 갖는다', () => {
    const banner = findFirst(modalExtend, (n) => n.id === 'extend_error_banner');
    expect(banner.if).toContain('mileageExtend?.errors');
    const json = jsonOf(modalExtend);
    expect(json).toContain("errors?.errors?.days ? 'input w-24 text-center input-error");
    const daysMsg = findFirst(modalExtend, (n) => n.name === 'Span' && n.if && String(n.if).includes('errors?.errors?.days'));
    expect(daysMsg).toBeTruthy();
    const submit = findFirst(modalExtend, (n) => n.id === 'extend_submit_button');
    expect(submit.props.disabled).toContain('isSaving');
    const spinner = findFirst(submit, (n) => n.name === 'Icon' && n.props?.name === 'spinner');
    expect(spinner.if).toContain('isSaving');
    const apiCall = findFirst(submit, (n) => n.handler === 'apiCall');
    const errSet = asArray(apiCall?.onError).find((a: any) => a.handler === 'setState');
    expect(errSet.params['mileageExtend.errors']).toBe('{{error}}');
  });
});

describe('일괄 연장 진입 (메인 레이아웃)', () => {
  /**
   * 회귀(#703): 첫 선택 행의 회원만 담으면 다른 회원의 적립건이 연장에서 빠진다 — 적립건 묶음만 담는다.
   *
   * @scenario selection=multiple_members, user_id_param=omitted
   *
   * @effects all_selected_members_lots_extended
   */
  it('bulk_extend_button 이 선택한 적립건 묶음만 담고 회원(userId)은 담지 않는다', () => {
    const btn = findFirst(mainLayout.slots.content[0], (n) => n.id === 'bulk_extend_button');
    const seq = asArray(btn?.actions).find((a: any) => a.handler === 'sequence');
    const setState = callbacksOf(seq, 'actions').find((a: any) => a.handler === 'setState');
    expect(String(setState.params.mileageExtend.lotIds)).toContain('selectedItems');
    expect(setState.params.mileageExtend.userId).toBeUndefined();
  });
});

describe('적립건 사유·기간 변경 모달 (_modal_edit_transaction.json)', () => {
  /**
   * 사유·기간 변경 창이 화면 최상위 창으로 선언된다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_active
   *
   * @effects modals_declared_top_level
   */
  it('편집 모달이 top-level modals 로 선언된다', () => {
    const partials = (mainLayout.modals ?? []).map((m: any) => m.partial);
    expect(partials.some((p: string) => p.includes('_modal_edit_transaction.json'))).toBe(true);
  });

  /**
   * 사유·기간 변경 창에서 회원·금액·유형·적립일은 읽기 전용으로만 보인다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_active
   *
   * @effects edit_modal_readonly_info_and_editable_memo
   */
  it('회원·금액·유형·적립일을 읽기전용(비활성 회색)으로 표시한다', () => {
    const info = findFirst(modalEdit, (n) => n.id === 'edit_readonly_info');
    expect(info).toBeTruthy();
    const json = jsonOf(info);
    // 4개 읽기전용 정보 라벨
    expect(json).toContain('modal_edit.info_member');
    expect(json).toContain('modal_edit.info_amount');
    expect(json).toContain('modal_edit.info_type');
    expect(json).toContain('modal_edit.info_earned_at');
    // 값은 _global.mileageEdit 에서 읽고, Input 이 아니라 Span 으로 표시(편집 불가)
    expect(json).toContain('_global.mileageEdit?.memberName');
    const input = findFirst(info, (n) => n.name === 'Input');
    expect(input).toBeNull();
  });

  /**
   * 사유는 적립건 상태와 관계없이 늘 고칠 수 있다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_expired
   *
   * @effects edit_modal_readonly_info_and_editable_memo,
   *   row_edit_expired_lot_blocks_expiry_allows_memo
   */
  it('사유(memo)는 항상 편집 가능한 입력이다', () => {
    const reasonInput = findFirst(modalEdit, (n) =>
      n.name === 'Input' && String(n.props?.value).includes('mileageEdit?.memo')
    );
    expect(reasonInput).toBeTruthy();
    const setState = asArray(reasonInput?.actions).find((a: any) => a.handler === 'setState');
    expect(setState.params['mileageEdit.memo']).toContain('$event.target.value');
  });

  /**
   * 만료일은 바꿀 수 있는 적립건일 때만 날짜 입력이, 아니면 잠금 안내가 나온다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_expired
   *
   * @effects row_edit_expired_lot_blocks_expiry_allows_memo
   */
  it('만료일은 can_edit_expiry 시 날짜 입력, 아니면 잠금 안내(비활성)로 분기한다', () => {
    // can_edit_expiry true → date input
    const dateInput = findFirst(modalEdit, (n) =>
      n.name === 'Input' && n.props?.type === 'date' && n.if && String(n.if).includes('canEditExpiry')
    );
    expect(dateInput).toBeTruthy();
    expect(String(dateInput.if)).toContain('canEditExpiry === true');
    // can_edit_expiry false → 잠금 안내
    const lockedBlock = findFirst(modalEdit, (n) => n.if && String(n.if).includes('canEditExpiry !== true'));
    expect(lockedBlock).toBeTruthy();
    expect(jsonOf(lockedBlock)).toContain('modal_edit.expiry_locked');
  });

  /**
   * 만료일 날짜 입력은 적립일보다 이전 날짜를 고를 수 없다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_active
   *
   * @effects edit_modal_expiry_min_is_earned_date
   */
  it('만료일 날짜 입력에 min(적립일)이 적용되어 적립일보다 과거 선택을 막는다 (서버 가드 + UX 2차 방어)', () => {
    const dateInput = findFirst(modalEdit, (n) =>
      n.name === 'Input' && n.props?.type === 'date' && n.if && String(n.if).includes('canEditExpiry')
    );
    expect(dateInput).toBeTruthy();
    // min 이 적립일(earnedAtDate)로 설정되어 datepicker 가 적립일 이전을 비활성화
    expect(String(dateInput.props.min)).toContain('mileageEdit?.earnedAtDate');
  });

  /**
   * 사유·기간 변경은 그 거래에 PATCH 로 보내고 만료일은 바꿀 수 있을 때만 보낸다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_active
   *
   * @effects row_edit_updates_memo_and_expiry
   */
  it('제출은 PATCH .../{id} 로 보내고 만료일 변경 가능 시에만 expires_at 을 body 에 포함한다', () => {
    const submit = findFirst(modalEdit, (n) => n.id === 'edit_submit_button');
    const apiCall = findFirst(submit, (n) => n.handler === 'apiCall');
    expect(apiCall.params.method).toBe('PATCH');
    expect(apiCall.target).toContain('/admin/mileage-transactions/');
    expect(apiCall.target).toContain('mileageEdit?.id');
    // 만료일 변경 불가 시 expires_at 미전송 (memo 만) — canEditExpiry 분기
    expect(apiCall.params.body).toContain('canEditExpiry === true');
    expect(apiCall.params.body).toContain('memo');
    expect(apiCall.params.body).toContain('expires_at');
  });

  /**
   * 사유·기간 변경이 성공하면 창을 닫고 목록을 다시 읽으며, 실패하면 오류를 창 상태에 담는다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_active
   *
   * @effects row_edit_updates_memo_and_expiry
   */
  it('제출 onSuccess(closeModal+refetch+toast) — sequence 로 감싸고 onError 가 errors 저장', () => {
    const submit = findFirst(modalEdit, (n) => n.id === 'edit_submit_button');
    const apiCall = findFirst(submit, (n) => n.handler === 'apiCall');
    const handlers = asArray(apiCall?.onSuccess).map((a: any) => a.handler);
    expect(handlers).toEqual(expect.arrayContaining(['closeModal', 'refetchDataSource', 'toast']));
    const errSet = asArray(apiCall?.onError).find((a: any) => a.handler === 'setState');
    expect(errSet.params['mileageEdit.errors']).toBe('{{error}}');
    expect(errSet.params['mileageEdit.isSaving']).toBe(false);
  });

  /**
   * 사유·기간 변경 실행 버튼이 저장 중에는 잠기고 스피너를 보여 준다.
   *
   * @scenario actor_permission=read_and_manage, lot_editability=earning_active
   *
   * @effects edit_modal_submit_saving_state
   */
  it('실행 버튼이 isSaving 스피너 + disabled 를 갖는다', () => {
    const submit = findFirst(modalEdit, (n) => n.id === 'edit_submit_button');
    expect(submit.props.disabled).toContain('isSaving');
    const spinner = findFirst(submit, (n) => n.name === 'Icon' && n.props?.name === 'spinner');
    expect(spinner.if).toContain('isSaving');
  });
});
