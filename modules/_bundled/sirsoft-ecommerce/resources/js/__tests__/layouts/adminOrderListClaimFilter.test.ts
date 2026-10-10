/**
 * @file adminOrderListClaimFilter.test.ts
 * @description 주문 목록 클레임 기준 필터 · 클레임 컬럼 — 구조 계약 (S10 · D22)
 *
 * 이 축이 조용히 깨지는 방식은 셋이다:
 *
 *  1. **화면 어휘 ⊄ 게이트.** 칩 값이 서버 Enum 을 벗어나면 그 칩 하나를 누른 순간 목록
 *     전체가 422 가 되고 화면에는 직전 목록이 그대로 남는다. 반대로 Enum 값이 칩에서
 *     빠지면 그 상태로 기록된 클레임이 달린 주문은 어떤 필터 조합으로도 도달 불가가 된다.
 *  2. **왕복 구멍.** 필터 상태 → URL → 데이터소스 파라미터 → 재진입 복원 네 지점 중
 *     하나라도 축이 빠지면 새로고침에서 그 필터만 사라진다.
 *  3. **요약 opt-in 의 부활.** 클레임 요약은 요청과 무관하게 항상 실린다(확정). 표시
 *     근거(브라우저 기억)와 값 수신 근거(주소)가 갈리면 컬럼은 보이는데 전 행이 "-" 가
 *     되므로, 화면이 `with_claim_summary` 를 다시 보내기 시작하는 회귀를 여기서 막는다.
 *
 * @vitest-environment node
 */

import { initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import { describe, expect, it } from 'vitest';

import shell from '../../../layouts/admin/admin_ecommerce_order_list.json';
import datagrid from '../../../layouts/admin/partials/admin_ecommerce_order_list/_partial_order_datagrid.json';
import filterSection from '../../../layouts/admin/partials/admin_ecommerce_order_list/_partial_filter_section.json';
import excelModal from '../../../layouts/admin/partials/admin_ecommerce_order_list/_modal_excel_download.json';
import presetSaveModal from '../../../layouts/admin/partials/admin_ecommerce_order_list/_modal_preset_save.json';

interface Node {
  id?: string;
  slot?: string;
  slotOrder?: number;
  props?: Record<string, any>;
  children?: Node[];
  actions?: any[];
}

/**
 * OrderListRequest 가 게이트로 쓰는 Enum 값.
 *
 * 이 상수는 **사본**이다 — Vitest 는 PHP Enum 을 읽을 수 없다. 어휘의 단일 출처 대조는
 * `modules/_bundled/sirsoft-ecommerce/tests/Unit/Layouts/OrderListClaimFilterVocabularyTest.php` 가 맡는다(Enum 에서 파생해
 * 같은 파셜을 대조하므로 회귀 구멍은 없다). 여기는 화면 배선(칩 → URL → 데이터소스 → 복원)을
 * 보는 축이고, 그 배선을 읽으려면 값 목록이 필요해 사본을 둔다.
 *
 * 그래서 Enum 어휘가 바뀌면 이 파일은 **거짓 red** 를 낸다 — 그때 고칠 곳은 화면이 아니라
 * 이 상수이며, 진짜 판정은 위 PHP 테스트가 한다.
 */
const GATE = {
  claimExchangeStatuses: ['requested', 'payment_pending', 'paid', 'approved', 'on_hold', 'rejected', 'collection_ready', 'collecting', 'collected', 'redelivery_ready', 'redelivering', 'redelivered', 'completed', 'withdrawn'],
  claimReturnStatuses: ['requested', 'payment_pending', 'paid', 'approved', 'on_hold', 'rejected', 'collection_ready', 'collecting', 'collected', 'completed', 'withdrawn'],
  claimRefundStatuses: ['requested', 'approved', 'processing', 'on_hold', 'completed', 'rejected', 'withdrawn'],
  claimCancelTypes: ['full', 'partial'],
  claimFaultTypes: ['customer', 'seller', 'carrier'],
};

/** 필터 상태 키 → URL 파라미터 이름 */
const AXES: Array<[string, string, boolean]> = [
  ['claimPresence', 'claim_presence', false],
  ['claimExchangeStatuses', 'claim_exchange_statuses', true],
  ['claimReturnStatuses', 'claim_return_statuses', true],
  ['claimRefundStatuses', 'claim_refund_statuses', true],
  ['claimCancelTypes', 'claim_cancel_types', true],
  ['claimFaultTypes', 'claim_fault_types', true],
  ['claimRequestedFrom', 'claim_requested_from', false],
  ['claimRequestedTo', 'claim_requested_to', false],
];

/**
 * 서브트리에서 조건에 맞는 노드를 모은다.
 *
 * @param node 시작 노드
 * @param match 판정 함수
 * @returns 일치한 노드 배열
 */
function collect(node: any, match: (n: Node) => boolean): Node[] {
  const found: Node[] = [];
  const walk = (n: any): void => {
    if (!n || typeof n !== 'object') return;
    if (Array.isArray(n)) { n.forEach(walk); return; }
    if (match(n)) found.push(n);
    Object.values(n).forEach(walk);
  };
  walk(node);

  return found;
}

const claimRow = collect(filterSection, (n) => n.id === 'claim_filter_row')[0];

/**
 * @scenario claim_axis=presence
 *
 * @effects order_list_claim_filter_axes_are_registered_in_slot_area,
 *   order_list_claim_filter_is_visible_by_default,
 *   order_list_claim_presence_is_scalar,
 *   order_list_claim_filter_vocabulary_matches_server_gate,
 *   order_list_claim_requested_range_has_both_bounds
 */
describe('클레임 기준 필터 블록 (D22)', () => {
  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_axes_are_registered_in_slot_area
  it('필터 카드의 슬롯 등록 영역에 클레임 행이 있다', () => {
    expect(claimRow).toBeDefined();
    expect(claimRow.slot).toContain("includes('claim')");
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_is_visible_by_default
  it('기본으로 노출되고, 검색 편집에서 숨길 수도 있다', () => {
    // 계획서 §6-4-2 는 "기본 필터 아래에 블록으로 삽입(검색 편집에서 숨김 가능)" 이다.
    // 기본 목록에서 빠져 있으면 운영자가 검색 편집을 열어보기 전에는 이 기능이 있다는
    // 것조차 모른다 — 6차 검증에서 실제로 그 상태였다.
    const restore = ((shell as any).init_actions ?? []).find(
      (a: any) => a.handler === 'sirsoft-ecommerce.loadVisibleFilters'
    );

    expect(restore?.params?.defaultFilters).toContain('claim');
    expect(initStateOf(shell as any)?.visibleFilters).toContain('claim');

    const toggles = collect(claimRow, (n) => n.props?.type === 'checkbox');

    expect(toggles).toHaveLength(1);
    expect(JSON.stringify(toggles[0].actions)).toContain("f !== 'claim'");
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_vocabulary_matches_server_gate
  it.each(Object.entries(GATE))('%s 칩 값 집합이 서버 게이트와 정확히 같다', (statePath, expected) => {
    const chips = collect(claimRow, (n: any) => n.name === 'ChipCheckbox' && String(n.id ?? '').includes(statePath));
    const values = chips.map((c) => String(c.props?.value));

    expect(values.sort()).toEqual([...expected].sort());
  });

  // @scenario claim_axis=presence
  // @effects order_list_claim_presence_is_scalar
  it('클레임 유무는 스칼라 계약이라 한 값만 선택되고 다시 누르면 해제된다', () => {
    const chips = collect(claimRow, (n: any) => n.name === 'ChipCheckbox' && String(n.id ?? '').includes('claimPresence'));

    expect(chips.map((c) => String(c.props?.value)).sort()).toEqual(['active', 'any', 'none']);

    for (const chip of chips) {
      const action = JSON.stringify(chip.actions);
      expect(action).toContain("'' : '");
      expect(action).not.toContain('toggleArrayValue');
    }
  });

  // @scenario claim_axis=fault_and_date
  // @effects order_list_claim_requested_range_has_both_bounds
  it('클레임 신청일은 from ~ to 두 입력을 갖는다', () => {
    const dates = collect(claimRow, (n: any) => n.props?.type === 'date');

    expect(dates.map((d) => d.id)).toEqual(['f_261_claim_requested_from', 'f_263_claim_requested_to']);
  });
});

/**
 * @scenario claim_axis=type_status
 *
 * @effects order_list_claim_filter_round_trips_through_url,
 *   order_list_claim_filter_search_passes_changed_values,
 *   order_list_claim_filter_reset_clears_axes,
 *   order_list_claim_filter_preset_carries_axes,
 *   order_list_claim_filter_dead_singular_keys_removed
 */
describe('필터 왕복 — 상태 · URL · 데이터소스 · 재진입 네 지점', () => {
  const orders = (shell as any).data_sources.find((d: any) => d.id === 'orders');
  const seed = (shell as any).init_actions.find((a: any) => a?.params?._local?.filter)?.params._local.filter;
  const searchQuery = (shell as any).named_actions.searchOrders.params.query;
  const state = initStateOf(shell as any).filter;

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_round_trips_through_url
  it.each(AXES)('%s 축이 네 지점 모두에 있다', (stateKey, param, isArray) => {
    const urlKey = isArray ? `${param}[]` : param;

    expect(state).toHaveProperty(stateKey);
    expect(seed).toHaveProperty(stateKey);
    expect(searchQuery).toHaveProperty(urlKey);
    expect(orders.params).toHaveProperty(param);
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_search_passes_changed_values
  it('검색 실행은 바뀐 값을 직접 넘긴다 (병합만으로는 새 값이 전달되지 않는다)', () => {
    for (const [stateKey] of AXES) {
      expect(JSON.stringify(searchQuery)).toContain(`_local.filter.${stateKey}`);
    }
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_reset_clears_axes
  it('초기화 버튼은 클레임 축도 함께 비운다', () => {
    const reset = collect(filterSection, (n) => n.id === 'f_142_reset_button')[0];
    const cleared = reset.actions?.[0].actions.find((a: any) => a.handler === 'setState').params.filter;

    for (const [stateKey] of AXES) {
      expect(cleared).toHaveProperty(stateKey);
    }
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_preset_carries_axes
  it('프리셋은 클레임 축을 승계한다 (저장·적용 양쪽)', () => {
    const preset = collect(filterSection, (n) => n.id === 'preset_buttons_dynamic_{{presetIdx}}')[0];
    const applied = JSON.stringify(preset.actions?.[0].actions);

    for (const [stateKey] of AXES) {
      expect(applied).toContain(`conditions?.${stateKey}`);
    }
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_dead_singular_keys_removed
  it('서버가 모르는 단수 키(claim_refund_status 등)는 어디에도 남지 않는다', () => {
    // 죽은 단수 키는 필터 섹션뿐 아니라 엑셀 다운로드·프리셋 저장 모달에도 있었다. 한 파일만
    // 훑으면 나머지 두 곳에서 되살아나도 초록이다. 또 실제 형태는 `claimRefundStatus ||` 처럼
    // 따옴표가 아니라 공백이 뒤따르므로, 바늘에 `"` 를 붙이면 그 형태를 잡지 못한다.
    const text = JSON.stringify([shell, filterSection, excelModal, presetSaveModal]);

    // 살아 있는 복수 키(`claimExchangeStatuses`)가 단수 키를 접두로 포함하므로 단순 substring
    // 으로는 정상 배선까지 걸린다 — 뒤에 영문자가 오지 않을 때만 단수형으로 판정한다.
    const dead: Array<[string, RegExp]> = [
      ['claimExchangeStatus', /claimExchangeStatus(?![A-Za-z])/],
      ['claimReturnStatus', /claimReturnStatus(?![A-Za-z])/],
      ['claimRefundStatus', /claimRefundStatus(?![A-Za-z])/],
      ['claim_exchange_status[]', /claim_exchange_status\[\]/],
      ['claim_return_status[]', /claim_return_status\[\]/],
      ['claim_refund_status[]', /claim_refund_status\[\]/],
    ];

    for (const [label, pattern] of dead) {
      expect(pattern.test(text), `죽은 단수 키 ${label} 가 남아 있다`).toBe(false);
    }
  });
});

/**
 * @scenario claim_axis=type_status
 *
 * @effects order_list_claim_column_is_always_served,
 *   order_list_claim_column_renders_latest_status_per_type,
 *   order_list_claim_column_display_has_single_source
 */
describe('클레임 컬럼 (D22)', () => {
  const columnSelector = collect(filterSection, (n) => n.id === 'f_003_column_selector')[0];
  const grid = collect(datagrid, (n: any) => n.name === 'DataGrid')[0];

  /**
   * 계약 변경 (확정, 6차 검증). 종전 계약은 "클레임 요약은 컬럼을 켠 요청에만 실린다
   * (opt-in)" 이고, 그 요청 여부를 주소(`with_claim_summary`)가 날랐다. 그런데 컬럼 표시는
   * 브라우저 저장값이 정하므로 근거가 둘이 되어 어긋났다 — 컬럼을 한 번 켠 운영자의 다음
   * 진입에서 컬럼은 보이는데 전 행이 "-" 가 됐고(주소에 축이 없다), 반대로 공유 링크
   * 수신자는 서버가 계산한 값을 받고도 컬럼을 못 봤다.
   *
   * 서버가 요약을 항상 싣도록 바꿔(OrderRepository) 근거를 하나로 되돌렸다. 그래서 이제
   * 클레임 컬럼은 다른 10개와 같은 평범한 컬럼이고, 표시 여부는 컬럼 선택 하나가 정한다.
   * 아래 케이스들은 그 단일 출처가 다시 갈라지지 않는지를 지킨다.
   */
  // @scenario claim_axis=type_status
  // @effects order_list_claim_column_display_has_single_source
  it('표시 컬럼 선택에 클레임이 있고 기본으로 표시된다', () => {
    const fields = columnSelector.props?.columns.map((c: any) => c.field);

    expect(fields).toContain('claim');
    expect(columnSelector.props?.defaultColumns).toContain('claim');
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_column_renders_latest_status_per_type
  it('DataGrid 에 클레임 컬럼이 있고 유형별 최신 상태 배지를 그린다', () => {
    const claimCol = grid.props?.columns.find((c: any) => c.field === 'claim');

    expect(claimCol).toBeDefined();
    expect(JSON.stringify(claimCol)).toContain('row.claim_summary');
    expect(JSON.stringify(claimCol)).toContain('StatusBadge');
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_column_is_always_served
  it('요약 요청을 주소로 나르는 축이 화면 어디에도 없다', () => {
    // 이 축이 되살아나면 표시(브라우저 저장값)와 값 수신(주소)이 다시 두 근거를 갖는다.
    // 셸·필터 두 파일이 화면이고, `_partial_preset_section.json` 은 **어느 레이아웃도
    // partial 로 참조하지 않는 미배선 파일**이다(처분은 S16 소유). 여기 함께 넣는 것은
    // "이 파일이 화면이라서" 가 아니라 나중에 배선될 때 죽은 축을 물고 들어오지 않게
    // 못박아 두기 위해서다 — 화면 커버리지로 읽지 말 것.
    //
    // 14차 정정 — 같은 파일 22줄 위의 형제 검사(죽은 단수 키)는 5파일 × 경계 정규식인데
    // 이 검사만 3파일 × 큰따옴표 고정이었다. 그 주석이 적어 둔 교훈 두 가지("한 파일만 훑으면
    // 나머지에서 되살아나도 초록" · "바늘에 `\"` 를 붙이면 실물 형태를 잡지 못한다")를 이
    // 검사도 받는다 — 컬럼을 그리는 `_partial_order_datagrid.json` 과 두 모달이 범위 밖이었고,
    // `...&with_claim_summary=1` 같은 URL 문자열 형태는 큰따옴표 바늘로는 잡히지 않았다.
    // 계약 대상은 **살아 있는 배선**이다. 폐지 경위를 적어 둔 `comment` 는 되살아남이 아니라
    // 되살아나지 않게 하는 장치이므로 직렬화에서 뺀다 — 넣으면 설명이 곧 위반이 되어,
    // 다음 사람이 검사를 통과시키려고 설명을 지우게 된다.
    const serialized = JSON.stringify(
      [shell, filterSection, datagrid, excelModal, presetSaveModal],
      (key, value) => (key === 'comment' ? undefined : value)
    );

    expect(
      /with_claim_summary(?![A-Za-z_])/.test(serialized),
      '요약 요청을 주소로 나르는 축이 되살아났다',
    ).toBe(false);
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_column_display_has_single_source
  it('컬럼 토글은 표시 상태만 바꾼다 — 주소를 쓰지 않는다', () => {
    const action = columnSelector.actions?.find((a: any) => a.event === 'onColumnVisibilityChange');

    expect(action).toBeDefined();
    expect(action.handler).toBe('setState');
    expect(action.params?.visibleColumns).toContain('$args[0]');
    expect(JSON.stringify(action)).not.toContain('navigate');
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_column_display_has_single_source
  it('컬럼 선택기는 저장 키를 유지한다 — 없으면 컬럼 선택이 새로고침마다 초기화된다', () => {
    // props.id 가 저장 키(g7_columns_{id}_{uuid})를 만든다. 이 id 가 도입된 커밋(8b36c8488)이
    // 고친 버그가 바로 "컬럼을 골라도 새로고침하면 초기화된다" 였다.
    expect(columnSelector.props?.id).toBe('admin_order_list_columns');
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_column_display_has_single_source
  it('init_actions 가 컬럼 표시를 주소 기준으로 다시 맞추지 않는다', () => {
    // 복원(localStorage)과 주소가 서로 컬럼 목록을 고쳐 쓰면 마운트 순서에 따라 결과가
    // 갈린다. 표시의 출처는 저장값 하나여야 한다.
    const reconcile = ((shell as any).init_actions ?? []).find(
      (a: any) => a.handler === 'setState' && Object.keys(a.params ?? {}).includes('visibleColumns')
    );

    expect(reconcile).toBeUndefined();
  });
});

/**
 * @scenario claim_axis=fault_and_date
 *
 * @effects order_list_claim_filter_fault_and_date_axes_are_wired
 */
describe('클레임 기준 필터 — 귀책 · 신청일 축', () => {
  // @scenario claim_axis=fault_and_date
  // @effects order_list_claim_filter_fault_and_date_axes_are_wired
  it('귀책 유형 칩이 서버 어휘 3종을 그대로 그린다', () => {
    const chips = collect(
      filterSection,
      (n: any) => typeof n?.id === 'string' && n.id.includes('claimFaultTypes')
    );

    // 서버 게이트는 ClaimReasonFaultTypeEnum 3종이다 — 화면이 그보다 좁으면 그 귀책으로
    // 기록된 주문이 어떤 필터 조합으로도 도달 불가가 된다.
    expect(chips.map((c: any) => c.id).sort()).toEqual([
      'f_242_claimFaultTypes_customer',
      'f_243_claimFaultTypes_seller',
      'f_244_claimFaultTypes_carrier',
    ].sort());
  });

  // @scenario claim_axis=fault_and_date
  // @effects order_list_claim_filter_fault_and_date_axes_are_wired
  it('신청일 범위 from/to 가 상태·데이터소스 양쪽에 배선돼 있다', () => {
    const orders = (shell as any).data_sources.find((d: any) => d.id === 'orders');

    expect(Object.keys(orders.params)).toContain('claim_requested_from');
    expect(Object.keys(orders.params)).toContain('claim_requested_to');

    const filterState = initStateOf(shell as any)?.filter ?? {};
    const keys = Object.keys(filterState).filter((k) => k.toLowerCase().includes('claimrequested'));

    expect(keys.length).toBe(2);
  });
});

// 모달은 **별도 컨텍스트**라 페이지의 `_local` 을 읽지 못한다. 그래서 모달이 페이지 상태를
// `_local.` 로 직접 읽으면 표현식은 문법적으로 유효한 채 값만 비고, 실패가 조용하다 —
// 엑셀 다운로드의 `filters` 가 빈 채로 나가면 "검색결과" 가 전건이 된다. S10 이 그 표현식에
// 클레임 8축을 더했지만 그 델타는 도달하지 않는 값 위에 얹힌 사문이었다.
//
// 목록을 손으로 적지 않는다 — 이 화면의 모달 파셜 전부를 파생시켜 검사한다.
/**
 * @scenario claim_axis=fault_and_date
 *
 * @effects order_list_claim_filter_reaches_the_excel_modal_through_the_global_bridge
 */
describe('모달 파셜 — 페이지 로컬 상태를 직접 읽지 않는다 (_global 브리지 경유)', () => {
  const MODAL_PARTIALS: [string, unknown][] = [
    ['_modal_excel_download.json', excelModal],
    ['_modal_preset_save.json', presetSaveModal],
  ];

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_reaches_the_excel_modal_through_the_global_bridge
  it('검사할 모달 파셜이 파생된다 (파생 자체의 건전성)', () => {
    expect(MODAL_PARTIALS.length).toBeGreaterThanOrEqual(2);
  });

  // 모달이 **자기** 로컬 상태를 쓰고 읽는 것은 규약대로다(`_modal_preset_save` 의 `presetName`
  // 은 그 모달이 직접 쓴다). 조용히 비는 것은 모달이 **쓰지 않은 키를 읽을 때**다 — 그 값은
  // 페이지 컨텍스트에만 있고 모달에는 도달하지 않는다. 그래서 계약은 "모달이 읽는 `_local` 키
  // ⊆ 그 모달이 쓰는 `_local` 키" 이고, 양쪽 집합을 파셜에서 파생시킨다.
  const localWrites = (partial: unknown): Set<string> => {
    const written = new Set<string>();
    const walk = (node: any): void => {
      if (Array.isArray(node)) { node.forEach(walk); return; }
      if (!node || typeof node !== 'object') return;
      const isLocal = node.handler === 'setState'
        && ['local', '_local'].includes(String(node.params?.target ?? ''));
      if (isLocal) {
        for (const key of Object.keys(node.params ?? {})) {
          if (key !== 'target') written.add(key.split('.')[0]);
        }
      }
      Object.values(node).forEach(walk);
    };
    walk(partial);
    return written;
  };

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_reaches_the_excel_modal_through_the_global_bridge
  it.each(MODAL_PARTIALS)('%s 은 자기가 쓰지 않은 로컬 키를 읽지 않는다', (_name, partial) => {
    const text = JSON.stringify(partial);
    const reads = new Set(Array.from(text.matchAll(/_local\.([A-Za-z_][A-Za-z0-9_]*)/g)).map((m) => m[1]));
    const writes = localWrites(partial);
    const orphans = [...reads].filter((k) => !writes.has(k)).sort();

    expect(
      orphans,
      '모달이 자기가 쓰지 않은 _local 키를 읽는다 — 그 값은 페이지 컨텍스트에만 있어 모달에는 도달하지 않고, 표현식은 유효한 채 값만 빈다',
    ).toEqual([]);
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_reaches_the_excel_modal_through_the_global_bridge
  it('엑셀 모달을 여는 지점이 대상·필터를 _global 브리지에 싣는다', () => {
    const text = JSON.stringify(datagrid);

    expect(text, '엑셀 모달을 여는 지점에 브리지 seed 가 없다').toContain('"excelDownloadContext"');
    expect(text, '브리지가 선택 항목을 싣지 않는다').toContain('selectedItems');
    expect(text, '브리지가 필터를 싣지 않는다').toContain('filter: _local.filter');
    expect(text, '브리지가 총 건수를 싣지 않는다 — 모달의 "검색결과 (N건)" 이 항상 0 이 된다').toContain('total:');
  });

  // `_local` 만 보면 계약의 절반만 닫힌다. 모달이 못 읽는 것은 페이지의 로컬 상태만이
  // 아니라 **페이지 데이터소스**도 마찬가지다 — 종전에 이 모달은 `orders?.data?.total` 을
  // 읽었는데, 그것은 별도 컨텍스트라 도달하지 않을 뿐 아니라 경로 자체가 형제 셀
  // (`orders?.data?.pagination?.total`)과 달라 도달해도 값이 없었다. 두 겹으로 틀린 채
  // 표현식은 유효해서 "검색결과 (0건)" 으로만 나타났다.
  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_reaches_the_excel_modal_through_the_global_bridge
  it.each(MODAL_PARTIALS)('%s 은 페이지 데이터소스를 직접 읽지 않는다', (_name, partial) => {
    const dataSourceIds = ((shell as any).data_sources ?? []).map((d: any) => d.id);
    const text = JSON.stringify(partial);

    expect(dataSourceIds.length, '셸의 데이터소스 목록을 읽지 못했다').toBeGreaterThanOrEqual(1);

    // 정규식은 **문자열 리터럴이 아니라 정규식 소스**로 만든다. 템플릿 리터럴 안의 `\b` 는
    // 정규식의 단어경계가 아니라 **백스페이스(U+0008)** 로 컴파일되므로, `` `\b${id}\?\.` `` 는
    // 어떤 입력에도 매치하지 않는 사문 패턴이 된다(그 상태로 이 검사는 영원히 초록이었다).
    // 백슬래시를 하나 더 넣어 정규식에 `\b` 라는 두 글자가 전달되게 한다.
    const leaked = dataSourceIds.filter((id: string) =>
      new RegExp(`\\b${id}\\?\\.`).test(text) || new RegExp(`\\b${id}\\.data`).test(text)
    );

    expect(
      leaked,
      '모달이 페이지 데이터소스를 직접 읽는다 — 모달은 별도 컨텍스트라 그 값이 도달하지 않고, 표현식은 유효한 채 값만 빈다',
    ).toEqual([]);
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_reaches_the_excel_modal_through_the_global_bridge
  it('엑셀 모달이 싣는 필터 축이 목록 데이터소스의 클레임 축을 모두 덮는다', () => {
    const orders = (shell as any).data_sources.find((d: any) => d.id === 'orders');
    const claimParams = Object.keys(orders.params).filter((k) => k.startsWith('claim_'));
    const body = JSON.stringify((excelModal as any));

    expect(claimParams.length, '목록 데이터소스의 클레임 축을 읽지 못했다').toBeGreaterThanOrEqual(8);
    for (const key of claimParams) {
      expect(body, `엑셀 다운로드가 ${key} 를 싣지 않는다`).toContain(`${key}:`);
    }
  });
});

/**
 * 프리셋 왕복의 키 열거는 손으로 적혀 있고, 필터 축은 계속 늘어난다. 그래서 축이 늘 때마다
 * 세 곳(저장 / 적용 시드 / 적용 이동) 중 일부만 갱신되는 형태로 조용히 어긋난다 — 실제로
 * `shippingPolicy` · `minShippingFee` · `maxShippingFee` 세 축이 세 곳 모두에서 빠져 있었다.
 * 그 상태에서도 저장은 성공하고 적용도 성공하며, 경고도 오류도 없다. 다만 저장할 때 걸었던
 * 배송정책·배송비 조건이 프리셋에 담기지 않아, 나중에 그 프리셋을 적용하면 다른 결과 집합이
 * 나올 뿐이다.
 *
 * 그래서 이 블록은 목록을 또 손으로 적지 않는다 — 판정 기준을 저장소에서 파생한다.
 *
 *  - 조건 축의 정본: `state.filter` 키 집합 (`selectedPreset` 은 "지금 어떤 프리셋이
 *    눌렸는가" 를 담는 화면 표식이라 조건이 아니다)
 *  - 상태 키 → URL 파라미터 대응의 정본: `named_actions.searchOrders` 의 query
 *
 * 두 정본이 비면 모든 단언이 공집합 대조로 통과하므로, 하한 단언을 함께 둔다.
 */
describe('프리셋 3출처는 필터 상태의 전 축을 나른다', () => {
  const filterState: Record<string, unknown> = initStateOf(shell as any).filter;

  const searchQuery: Record<string, string> = (shell as any).named_actions.searchOrders.params.query;
  const stateToUrl = new Map<string, string>();
  for (const [urlKey, expr] of Object.entries(searchQuery)) {
    const matched = /_local\.filter\.([A-Za-z0-9_]+)/.exec(String(expr));
    if (matched) stateToUrl.set(matched[1], urlKey);
  }

  // 조건 축의 정본은 `state.filter` **와** `searchOrders` 의 상태 참조를 **합친** 것이다.
  // `state.filter` 만 보면 안 되는 이유: 선언되지 않은 채 init 재시드(`query.* → filter.*`)로만
  // 살아나는 축이 실재한다(`memberType` · `ordererUuid`). 그 둘은 필터 UI 도 있고 검색 실행이
  // 주소로도 내보내는 **완전한 필터**인데 `state.filter` 에 선언만 없었고, 그래서 이 검사가
  // 정본을 `state.filter` 하나로 잡았던 동안 두 축이 프리셋 저장에서 통째로 빠져 있는 것을
  // 잡지 못했다. 정본을 좁게 잡으면 검사는 초록인 채로 축을 놓친다.
  const conditionKeys = [
    ...new Set([...Object.keys(filterState), ...stateToUrl.keys()]),
  ].filter((k) => k !== 'selectedPreset');

  // 주소에 실리지 않는 조건 축. `dateQuick` 은 기간 빠른선택 버튼의 눌림 표시라 서버로 가지
  // 않는다(실제 기간은 startDate/endDate 가 나른다). 이 목록이 늘어난다는 것은 새 필터가
  // 주소 왕복에서 빠졌다는 뜻이므로, 여기서 red 로 드러나야 한다.
  const UI_ONLY_KEYS = ['dateQuick'];

  // 프리셋에 담지 않는 조건 축. `dateBasis` 는 대시보드 「오늘」 배지로 들어왔을 때만 서는 날짜 기준(사이트 하루)이다 —
  // 프리셋은 운영자가 고른 조건이라 보는 사람의 하루가 맞고, 적용 시드는 이 축을 비워 되돌린다(아래 「화면 상태로
  // 되돌린다」 검사가 시드에 있는지 본다). 저장 열거·적용 이동 query 에는 일부러 없다.
  const NON_PRESET_KEYS = ['dateBasis'];

  const saveCall = collect(
    presetSaveModal,
    (n: any) => n.handler === 'apiCall' && n.params?.body?.conditions
  )[0];
  const savedConditions: Record<string, unknown> = (saveCall as any)?.params?.body?.conditions ?? {};

  const applyButton = collect(filterSection, (n) => n.id === 'preset_buttons_dynamic_{{presetIdx}}')[0];
  const appliedSeed: Record<string, unknown> =
    (collect(applyButton, (n: any) => n.handler === 'setState' && n.params?.filter)[0] as any)?.params?.filter ?? {};
  const appliedQuery: Record<string, unknown> =
    (collect(applyButton, (n: any) => n.handler === 'navigate' && n.params?.query?.preset_id)[0] as any)?.params?.query ?? {};

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_preset_carries_axes
  it('판정 기준 두 정본과 대조 대상 세 출처를 모두 읽었다', () => {
    expect(conditionKeys.length, 'state.filter 를 읽지 못했다').toBeGreaterThanOrEqual(28);
    expect(stateToUrl.size, 'searchOrders 의 상태 → URL 대응을 읽지 못했다').toBeGreaterThanOrEqual(28);
    expect(Object.keys(savedConditions).length, '프리셋 저장의 conditions 를 읽지 못했다').toBeGreaterThanOrEqual(28);
    expect(Object.keys(appliedSeed).length, '프리셋 적용의 상태 시드를 읽지 못했다').toBeGreaterThanOrEqual(28);
    expect(Object.keys(appliedQuery).length, '프리셋 적용의 이동 query 를 읽지 못했다').toBeGreaterThanOrEqual(28);
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_round_trips_through_url
  it('주소에 실리지 않는 조건 축은 선언된 것뿐이다', () => {
    const unmapped = conditionKeys.filter((k) => !stateToUrl.has(k)).sort();

    expect(
      unmapped,
      '조건 축이 검색 실행의 주소 파라미터에 없다 — 그 필터는 새로고침·공유 링크에서 사라진다',
    ).toEqual([...UI_ONLY_KEYS].sort());
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_preset_carries_axes
  it('프리셋 저장이 조건 축을 하나도 빠뜨리지 않는다', () => {
    const missing = conditionKeys
      .filter((k) => !NON_PRESET_KEYS.includes(k))
      .filter((k) => !(k in savedConditions))
      .sort();

    expect(
      missing,
      '저장 열거에 없는 축은 프리셋에 담기지 않는다 — 저장은 성공하고 조건만 사라진다',
    ).toEqual([]);
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_preset_carries_axes
  it('프리셋 적용이 조건 축을 하나도 빠뜨리지 않고 화면 상태로 되돌린다', () => {
    const missing = conditionKeys.filter((k) => !(k in appliedSeed)).sort();

    expect(
      missing,
      '적용 시드에 없는 축은 프리셋을 눌러도 입력칸에 되돌아오지 않는다',
    ).toEqual([]);
  });

  // @scenario claim_axis=type_status
  // @effects order_list_claim_filter_preset_carries_axes
  it('프리셋 적용 이동이 조건 축을 하나도 빠뜨리지 않고 주소에 싣는다', () => {
    const missing = conditionKeys
      .filter((k) => stateToUrl.has(k))
      .filter((k) => !NON_PRESET_KEYS.includes(k))
      .filter((k) => !(stateToUrl.get(k)! in appliedQuery))
      .sort();

    expect(
      missing,
      '적용 이동의 query 에 없는 축은 목록 조회에 반영되지 않는다 — 화면 입력칸만 채워지고 결과 집합이 달라진다',
    ).toEqual([]);
  });
});
