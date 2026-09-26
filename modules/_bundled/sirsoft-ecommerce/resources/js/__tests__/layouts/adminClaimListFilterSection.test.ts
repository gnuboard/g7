/**
 * @file adminClaimListFilterSection.test.ts
 * @description 관리자 클레임 목록 필터 섹션 — 구조 계약 (S8a · D21/D27/D28)
 *
 * 이 화면은 주문관리 `_partial_filter_section.json` 의 구조를 정본으로 복제한 것이라
 * "무엇이 그려지는가" 보다 "어떤 순서로, 어떤 축이" 놓였는지가 계약이다. 세 축을 고정한다:
 *
 *  1. 필터 카드 안 노드 순서 (D21) — 프리셋 → 검색행 → 기본필터 → 상세필터 → 액션행.
 *     순서가 바뀌면 운영자가 늘 보던 자리에서 컨트롤이 사라지는데 오류는 나지 않는다.
 *  2. 상태 어휘가 게이트(`Admin\ClaimListRequest::allowedStatuses()`)와 정확히 같은 집합인지.
 *     부분집합이면 그 상태로 기록된 신청서가 어떤 필터 조합으로도 도달 불가가 되고,
 *     초과집합이면 칩 하나를 누른 순간 목록 전체가 422 가 된다. 둘 다 조용하다.
 *  3. 검색 드롭다운 4축이 상세 필터에 중복 배치되지 않았는지 (D27).
 *
 * @vitest-environment node
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import filterSection from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_partial_filter_section.json';
import shell from '../../../layouts/admin/admin_ecommerce_claim_list.json';
import presetSaveModal from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_modal_preset_save.json';

const MODULE_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');

interface Node {
  id?: string;
  type?: string;
  name?: string;
  if?: string;
  slot?: string;
  slotOrder?: number;
  props?: Record<string, unknown>;
  children?: Node[];
  actions?: unknown[];
  text?: string;
}

const root = filterSection as unknown as Node;

/**
 * 서브트리에서 조건에 맞는 노드를 모두 모은다.
 *
 * @param node 시작 노드
 * @param match 판정 함수
 * @returns 일치한 노드 배열
 */
function collect(node: Node, match: (n: Node) => boolean): Node[] {
  const found: Node[] = [];
  const walk = (n: Node): void => {
    if (match(n)) {
      found.push(n);
    }
    (n.children ?? []).forEach(walk);
  };
  walk(node);

  return found;
}

/**
 * id 로 노드 하나를 찾는다.
 *
 * @param id 노드 id
 * @returns 노드
 */
function byId(id: string): Node {
  const [node] = collect(root, (n) => n.id === id);
  expect(node, `#${id} 노드가 없다`).toBeTruthy();

  return node;
}

/**
 * PHP Enum 파일에서 backed value 목록을 읽는다. (게이트 어휘의 단일 출처)
 *
 * @param enumName Enum 클래스명
 * @returns 값 배열
 */
function enumValues(enumName: string): string[] {
  const source = fs.readFileSync(path.join(MODULE_ROOT, 'src/Enums', `${enumName}.php`), 'utf8');

  return [...source.matchAll(/^\s{4}case\s+\w+\s*=\s*'([^']+)';/gm)].map((m) => m[1]);
}

describe('클레임 목록 필터 섹션 — 카드 구조 (D21)', () => {
  /**
   * @effects filter_card_node_order_matches_order_screen
   */
  it('필터 카드의 노드 순서가 주문관리 정본과 같다', () => {
    expect((root.children ?? []).map((n) => n.id)).toEqual([
      'filter_visibility_selector',
      'slot_registration_area',
      'preset_row',
      'search_row_wrapper',
      'basic_filter_section',
      'detail_filter_wrapper',
      'filter_actions',
    ]);
  });

  /**
   * 표시 여부 선택기는 상태만 가진다 — 카드 구조 순서의 맨 앞 노드다.
   *
   * @effects filter_card_node_order_matches_order_screen
   */
  it('FilterVisibilitySelector 는 UI 를 렌더하지 않으므로 카드 최상단에 두고 화면에는 그리지 않는다', () => {
    const selector = byId('filter_visibility_selector');
    expect(selector.name).toBe('FilterVisibilitySelector');
    expect(selector.props?.id).toBe('admin_ecommerce_claim_filters');
    expect(selector.children).toBeUndefined();
  });

  /**
   * @effects filter_rows_registered_through_slot_system
   */
  it('필터 행은 전부 슬롯 등록 영역(hidden) 안에서 정의되고 slotOrder 로 정렬된다', () => {
    const area = byId('slot_registration_area');
    expect(String(area.props?.className)).toContain('hidden');
    expect((area.children ?? []).map((n) => ({ id: n.id, order: n.slotOrder }))).toEqual([
      { id: 'date_filter_row', order: 2 },
      { id: 'claimStatus_filter_row', order: 3 },
      { id: 'faultType_filter_row', order: 4 },
      { id: 'chargeMethod_filter_row', order: 5 },
      { id: 'reasonCode_filter_row', order: 6 },
      { id: 'requesterType_filter_row', order: 7 },
      { id: 'amount_filter_row', order: 8 },
      { id: 'chargePaymentMethod_filter_row', order: 9 },
      // 관리자 접수 · 회수 기한 초과 · 환불 미접수 체크 묶음(#599 §3-11·§3-12)
      { id: 'claimFlags_filter_row', order: 10 },
    ]);
  });

  /**
   * @effects filter_visibility_has_a_single_persistence_owner
   */
  it('필터 표시/숨김은 상태만 바꾸고 레이아웃이 따로 저장하지 않는다', () => {
    // 영속의 SSoT 는 FilterVisibilitySelector 가 쓰고 loadVisibleFilters 가 읽는
    // `g7_filters_{id}_{userUuid}` 다. 레이아웃이 별도 키에 또 쓰면 아무도 읽지 않는 두 번째
    // 출처가 생기고, 같은 토글 식을 두 번 평가하는 순서 문제까지 따라온다 (주문관리 실측 —
    // 상태를 숨겼는데 저장값에는 남아 있었다).
    for (const row of byId('slot_registration_area').children ?? []) {
      const [checkbox] = row.children ?? [];
      const action = (checkbox.actions ?? [])[0] as any;

      expect(action.handler).toBe('setState');
      expect(action.params.target).toBe('_local');
    }

    expect(JSON.stringify(filterSection)).not.toContain('saveToLocalStorage');
  });

  /**
   * 필터 행은 슬롯 등록으로 기본/상세 자리를 고른다.
   *
   * @effects filter_rows_registered_through_slot_system
   */
  it('각 필터 행은 표시 여부에 따라 기본/상세 슬롯으로 갈린다', () => {
    for (const row of byId('slot_registration_area').children ?? []) {
      const key = String(row.id).replace('_filter_row', '');
      expect(row.slot).toBe(
        `{{(_local.visibleFilters || []).includes('${key}') ? 'basic_filters' : 'detail_filters'}}`
      );
    }
  });

  /**
   * 슬롯을 받는 컨테이너가 하나씩이어야 행이 두 번 그려지지 않는다.
   *
   * @effects filter_rows_registered_through_slot_system
   */
  it('기본/상세 슬롯 컨테이너가 각각 하나씩 있다', () => {
    const containers = collect(root, (n) => n.name === 'SlotContainer');
    expect(containers.map((n) => n.props?.slotId)).toEqual(['basic_filters', 'detail_filters']);
  });

  /**
   * @effects detail_filter_opens_only_by_toggle
   */
  it('상세 필터 영역은 상세검색 토글로만 열린다', () => {
    expect(byId('detail_filter_wrapper').if).toBe('{{_local.showDetailFilter}}');
  });

  /**
   * @effects filter_actions_are_three_column_split
   */
  it('액션 행은 좌(검색편집) / 중앙(검색·초기화·프리셋) / 우(여백) 3분할이다', () => {
    const row1 = byId('filter_actions_row1');
    const [left, center, right] = row1.children ?? [];
    expect(left.id).toBe('edit_search_button_wrapper');
    expect((center.children ?? []).map((n) => n.id)).toEqual([
      'f_911_search_button',
      'f_912_reset_button',
      'f_913_preset_button',
    ]);
    expect(right.children).toBeUndefined();
    expect(String(right.props?.className)).toContain('flex-1');
  });
});

describe('클레임 목록 필터 섹션 — 상태 필터 (D21 · D28)', () => {
  const statusRow = () => byId('claimStatus_filter_row');

  const GATE = {
    exchange: 'ExchangeStatusEnum',
    return: 'ReturnStatusEnum',
    refund: 'RefundStatusEnum',
    cancel: 'CancelStatusEnum',
  } as const;

  /**
   * 상태 필터는 칩으로만 고른다 — 드롭다운이면 여러 상태를 한 번에 걸 수 없다.
   *
   * @effects status_filter_uses_chip_checkbox_not_dropdown
   */
  it('상태 필터에는 드롭다운(Select)이 하나도 없다', () => {
    expect(collect(statusRow(), (n) => n.name === 'Select')).toHaveLength(0);
  });

  /**
   * 상태 칩은 배열에 누적된다.
   *
   * @effects status_filter_uses_chip_checkbox_not_dropdown
   */
  it('상태 칩은 전부 ChipCheckbox 이고 toggleArrayValue 로 배열을 누적한다', () => {
    const chips = collect(statusRow(), (n) => n.name === 'ChipCheckbox');
    expect(chips.length).toBeGreaterThan(0);

    for (const chip of chips) {
      expect(chip.type).toBe('composite');
      expect(chip.actions).toEqual([
        {
          type: 'change',
          handler: 'sirsoft-ecommerce.toggleArrayValue',
          params: { target: '_local', path: 'filter.claimStatus', value: chip.props?.value },
        },
      ]);
    }
  });

  /**
   * @effects status_vocabulary_matches_request_gate
   */
  it.each(Object.entries(GATE))(
    '%s 상태 칩 집합이 게이트 Enum 어휘와 정확히 같다',
    (type, enumName) => {
      const group = byId(`claim_status_chips_${type}`);
      const chipValues = collect(group, (n) => n.name === 'ChipCheckbox').map((n) => String(n.props?.value));

      expect(chipValues).toEqual(enumValues(enumName));
    }
  );

  /**
   * 다른 유형의 상태 칩이 섞이지 않게 활성 서브탭 묶음만 등록한다.
   *
   * @effects status_chips_scoped_to_active_subtab
   */
  it('유형별 칩 묶음은 활성 서브탭에서만 등록된다 (기본값 교환 — D16)', () => {
    for (const type of Object.keys(GATE)) {
      expect(byId(`claim_status_chips_${type}`).if).toBe(`{{(query.type || 'exchange') === '${type}'}}`);
    }
  });

  /**
   * @effects status_labels_read_type_prefixed_enum_keys
   */
  it('상태 라벨은 유형 접두 Enum 키 단일 출처를 읽는다 (D28)', () => {
    for (const [type, block] of Object.entries({
      exchange: 'exchange_status',
      return: 'return_status',
      refund: 'refund_status',
      cancel: 'cancel_status',
    })) {
      const group = byId(`claim_status_chips_${type}`);
      for (const chip of collect(group, (n) => n.name === 'ChipCheckbox')) {
        expect(chip.props?.label).toBe(
          `$t:sirsoft-ecommerce.enums.${block}.${String(chip.props?.value)}`
        );
      }
    }
  });
});

describe('클레임 목록 필터 섹션 — 유형에 없는 축 (게이트 prohibited)', () => {
  /**
   * 취소에는 귀책 축이 없다 — 게이트가 prohibited 로 거절한다.
   *
   * @effects fault_axis_absent_for_cancel
   */
  it('귀책 필터는 취소 유형에서 등록되지 않는다', () => {
    expect(byId('faultType_filter_row').if).toBe("{{(query.type || 'exchange') !== 'cancel'}}");
  });

  /**
   * 청구 방식은 교환·반품에만 있다.
   *
   * @effects charge_axis_absent_for_refund_and_cancel
   */
  it('청구방식 필터는 교환·반품에서만 등록된다', () => {
    expect(byId('chargeMethod_filter_row').if).toBe(
      "{{(query.type || 'exchange') === 'exchange' || (query.type || 'exchange') === 'return'}}"
    );
  });

  /**
   * @effects search_action_omits_params_for_axes_the_type_lacks
   */
  it('검색 액션은 축이 없는 유형에서 그 파라미터를 아예 싣지 않는다', () => {
    const query = (shell as any).named_actions.searchClaims.params.query;
    expect(query['fault_types[]']).toContain("(query.type || 'exchange') !== 'cancel'");
    expect(query['charge_methods[]']).toContain("(query.type || 'exchange') === 'exchange'");
    expect(query['charge_methods[]']).toContain("(query.type || 'exchange') === 'return'");
  });
});

describe('클레임 목록 필터 섹션 — 검색 드롭다운 (D27)', () => {
  const SEARCH_FIELDS = ['claim_number', 'order_number', 'requester', 'product_name'];

  /**
   * @effects search_dropdown_has_four_fields_and_no_all_option
   */
  it('검색필드 Select 는 4옵션이며 전체(all) 옵션을 두지 않는다', () => {
    const options = byId('f_001_search_field_select').props?.options as { value: string }[];
    expect(options.map((o) => o.value)).toEqual(SEARCH_FIELDS);
  });

  /**
   * @effects search_field_options_match_request_gate
   */
  it('검색필드 4옵션이 게이트(search_field) 어휘와 같다', () => {
    const request = fs.readFileSync(
      path.join(MODULE_ROOT, 'src/Http/Requests/Admin/ClaimListRequest.php'),
      'utf8'
    );
    const gate = /'search_field'\s*=>\s*\['nullable',\s*'in:([^']+)'\]/.exec(request);
    expect(gate, 'search_field 게이트 규칙을 찾지 못했다').toBeTruthy();
    expect(gate![1].split(',')).toEqual(SEARCH_FIELDS);
  });

  /**
   * @effects search_axes_not_duplicated_in_detail_filter
   */
  it('검색 드롭다운 4축은 상세 필터 행으로 중복 배치되지 않는다', () => {
    const rowKeys = (byId('slot_registration_area').children ?? []).map((n) =>
      String(n.id).replace('_filter_row', '')
    );

    // 검색 축을 필터 행으로도 두면 같은 값을 두 경로로 걸 수 있고, 어느 쪽이 이겼는지 화면에 드러나지 않는다.
    for (const forbidden of ['claimNumber', 'orderNumber', 'requester', 'productName']) {
      expect(rowKeys).not.toContain(forbidden);
    }
  });

  /**
   * 검색 드롭다운 축은 상세 필터로 다시 두지 않는다 — 상세로 내려가는 축은 겹치지 않는 것뿐이다.
   *
   * @effects search_axes_not_duplicated_in_detail_filter
   */
  it('상세 필터로 내려가는 축은 검색 드롭다운과 겹치지 않는 회원구분 하나다', () => {
    const defaults = byId('filter_visibility_selector').props?.defaultFilters as string[];
    const rowKeys = (byId('slot_registration_area').children ?? []).map((n) =>
      String(n.id).replace('_filter_row', '')
    );

    // 상세 필터로 내려가는 축은 검색 드롭다운 4축과 겹치지 않는 것들이다 (D27 · 와이어프레임 ④).
    expect(rowKeys.filter((k) => !defaults.includes(k))).toEqual([
      'requesterType',
      'amount',
      'chargePaymentMethod',
      'claimFlags',
    ]);
  });
});

describe('[case:components-misc-computed-prefix-options] 클레임 목록 필터 섹션 — 셸 computed 참조', () => {
  /**
   * @effects computed_options_are_referenced_with_computed_prefix
   */
  it('셸 computed 를 읽는 바인딩은 _computed. 접두를 쓴다', () => {
    // 접두가 없으면 표현식이 undefined 로 풀리는데, Select 는 옵션 없는 빈 드롭다운으로
    // 조용히 렌더된다 — 오류도 콘솔 경고도 없다 (실측: 사유 필터가 빈 라벨로 떴다).
    const computedKeys = Object.keys((shell as any).computed);
    expect(computedKeys.length).toBeGreaterThan(0);

    const serialized = JSON.stringify(filterSection);
    for (const key of computedKeys) {
      const bare = new RegExp(`\{\{\s*${key}\b`);
      expect(serialized, `${key} 를 _computed. 없이 참조한다`).not.toMatch(bare);

      if (serialized.includes(key)) {
        expect(serialized).toContain(`_computed.${key}`);
      }
    }
  });

  /**
   * 셸이 만든 computed 선택지는 필터가 `_computed.` 로 읽는다 — 읽히지 않는 항목이 없다.
   *
   * @effects computed_options_are_referenced_with_computed_prefix
   */
  it('셸 computed 는 전부 소비된다 (사문 항목 없음)', () => {
    const serialized = JSON.stringify(filterSection) + JSON.stringify((shell as any).slots);
    for (const key of Object.keys((shell as any).computed)) {
      expect(serialized, `computed.${key} 를 읽는 곳이 없다`).toContain(key);
    }
  });
});

describe('클레임 목록 필터 섹션 — 상세 필터 축 (와이어프레임 §6-4 ④)', () => {
  /**
   * @effects amount_axis_matches_the_list_amount_column
   */
  it('금액 범위는 최소·최대·통화 셋을 한 행에 둔다', () => {
    const row = byId('amount_filter_row');
    const inputs = collect(row, (n) => n.name === 'Input' && n.props?.type === 'number');
    expect(inputs.map((n) => n.props?.name)).toEqual(['minAmount', 'maxAmount']);
    expect(collect(row, (n) => n.name === 'Select' && n.props?.name === 'currencyCode')).toHaveLength(1);
  });

  /**
   * @effects currency_options_come_from_shop_settings
   */
  it('통화 선택지는 상점 설정에서 온다 (코드에 통화를 열거하지 않는다)', () => {
    const select = collect(byId('amount_filter_row'), (n) => n.props?.name === 'currencyCode')[0];
    expect(select.props?.options).toBe('{{_computed.claimCurrencyOptions ?? []}}');

    const computed = (shell as any).computed.claimCurrencyOptions;
    expect(computed).toContain('language_currency?.currencies');
  });

  /**
   * @effects charge_payment_axis_absent_for_refund_and_cancel
   */
  it('청구 결제수단은 추가결제가 성립하는 교환·반품에서만 등록된다', () => {
    expect(byId('chargePaymentMethod_filter_row').if).toBe(
      "{{(query.type || 'exchange') === 'exchange' || (query.type || 'exchange') === 'return'}}"
    );

    const action = (shell as any).named_actions.searchClaims.params.query['charge_payment_methods[]'];
    expect(action).toContain("(query.type || 'exchange') === 'exchange'");
    expect(action).toContain("(query.type || 'exchange') === 'return'");
  });

  /**
   * @effects charge_payment_options_come_from_claim_settings
   */
  it('청구 결제수단 선택지는 클레임 설정의 허용 목록에서 온다', () => {
    const computed = (shell as any).computed.claimChargePaymentMethods;
    expect(computed).toContain('claim?.charge_payment_methods');
  });
});

describe('클레임 목록 필터 섹션 — 목록 컨텍스트 왕복', () => {
  /**
   * @effects search_navigate_merges_list_context
   */
  it('검색은 URL 목록 상태를 병합해 유형 서브탭·페이지 밖 조건을 잃지 않는다', () => {
    const action = (shell as any).named_actions.searchClaims;
    expect(action.params.mergeQuery).toBe(true);
    expect(action.params.path).toBe('/admin/ecommerce/claims');
  });

  /**
   * @effects reset_clears_every_filter_key_but_keeps_subtab
   */
  it('초기화·프리셋 적용은 병합하지 않되 유형 서브탭은 유지한다', () => {
    const resetNavigate = collect(byId('filter_actions'), (n) => n.id === 'f_912_reset_button')[0]
      .actions![0] as any;
    const navigate = resetNavigate.actions.find((a: any) => a.handler === 'navigate');

    expect(navigate.params.mergeQuery).toBe(false);
    expect(navigate.params.query).toEqual({ type: "{{(query.type || 'exchange')}}" });
    expect(navigate.comment).toContain('audit:allow layout-list-context-navigate-merge-query');
  });

  /**
   * 초기화는 필터 상태의 모든 키를 비우고 서브탭만 남긴다.
   *
   * @effects reset_clears_every_filter_key_but_keeps_subtab
   */
  it('초기화는 필터 상태 전 키를 비운다 (화면에 남은 조건 없이 URL 만 비는 상태 방지)', () => {
    const reset = collect(byId('filter_actions'), (n) => n.id === 'f_912_reset_button')[0]
      .actions![0] as any;
    const setState = reset.actions.find((a: any) => a.handler === 'setState');

    expect(Object.keys(setState.params.filter).sort()).toEqual(
      Object.keys(initStateOf(shell as any).filter).sort()
    );
  });
});

describe('클레임 목록 필터 섹션 — 프리셋', () => {
  /**
   * 프리셋 동작은 클레임 전용 모달을 연다.
   *
   * @effects preset_actions_target_claim_scoped_modals
   */
  it('프리셋 행은 카드 안 최상단이고 관리 모달은 클레임 전용 id 를 연다', () => {
    const presetRow = byId('preset_row');
    const openManage = (presetRow.children ?? [])[0].actions![0] as any;

    expect(openManage.handler).toBe('openModal');
    expect(openManage.target).toBe('modal_claim_preset_manage');
  });

  /**
   * @effects preset_actions_target_claim_scoped_modals
   */
  it('프리셋 저장 버튼 2종(PC·모바일)이 같은 클레임 전용 모달을 연다', () => {
    const buttons = collect(root, (n) => n.id === 'f_913_preset_button' || n.id === 'preset_save_button_mobile');
    expect(buttons).toHaveLength(2);

    for (const button of buttons) {
      const sequence = button.actions![0] as any;
      expect(sequence.actions.at(-1)).toEqual({ handler: 'openModal', target: 'modal_claim_preset_save' });
    }
  });

  /**
   * @effects presets_scoped_to_claims_target_screen
   */
  /**
   * @effects preset_carries_its_claim_type
   */
  it('프리셋은 저장 시점의 유형을 함께 담고, 적용 시 그 유형으로 이동한다', () => {
    // 상태 어휘는 유형에 속한다 — 교환 프리셋을 환불 서브탭에서 적용하면 게이트가
    // `statuses.*` 를 거절해(실측 422) 목록 전체가 뜨지 않는다.
    const save = JSON.parse(
      JSON.stringify(presetSaveModal)
    );
    const serializedSave = JSON.stringify(save);
    expect(serializedSave).toContain('"claimType"');
    expect(serializedSave).toContain("query.type || 'exchange'");

    const chip = collect(root, (n) => n.id === 'preset_buttons_dynamic_{{presetIdx}}')[0];
    const navigate = (chip.actions![0] as any).actions.find((a: any) => a.handler === 'navigate');
    expect(navigate.params.query.type).toBe("{{preset.conditions?.claimType || (query.type || 'exchange')}}");
  });

  /**
   * @effects preset_axis_gates_follow_the_applied_type
   */
  it('프리셋 적용의 귀책·청구 게이트는 적용될 유형을 기준으로 판정한다', () => {
    const chip = collect(root, (n) => n.id === 'preset_buttons_dynamic_{{presetIdx}}')[0];
    const navigate = (chip.actions![0] as any).actions.find((a: any) => a.handler === 'navigate');

    // 현재 서브탭이 아니라 프리셋이 데려갈 유형으로 판정해야 한다.
    expect(navigate.params.query['fault_types[]']).toContain('preset.conditions?.claimType');
    expect(navigate.params.query['charge_methods[]']).toContain('preset.conditions?.claimType');
  });

  /**
   * @effects preset_state_keys_match_shell_state
   */
  it('프리셋 적용이 쓰는 필터 키는 셸 state·초기화와 정확히 같다', () => {
    // 유형(claimType)은 URL 이 갖는 값이라 _local.filter 에 넣지 않는다 — 넣으면
    // 초기화가 지우지 않는 키가 하나 생긴다.
    const chip = collect(root, (n) => n.id === 'preset_buttons_dynamic_{{presetIdx}}')[0];
    const setState = (chip.actions![0] as any).actions.find((a: any) => a.handler === 'setState');
    const shellKeys = Object.keys(initStateOf(shell as any).filter).sort();

    expect(Object.keys(setState.params.filter).sort()).toEqual(shellKeys);
  });

  /**
   * 프리셋 데이터소스는 클레임 화면 것만 읽는다.
   *
   * @effects presets_scoped_to_claims_target_screen
   */
  it('셸이 프리셋 데이터소스를 claims 화면으로 스코프한다', () => {
    const presets = (shell as any).data_sources.find((d: any) => d.id === 'presets');
    expect(presets.params.target_screen).toBe('claims');
  });

  /**
   * 검색 액션만 게이트하고 데이터소스를 비워 두면 두 경로의 규칙이 갈라진다.
   * URL 은 검색 액션 말고도 북마크·뒤로가기·수기 편집으로 만들어지므로, 데이터소스가
   * 무게이트면 교차 조합 URL 에서 목록 전체가 422 가 되고 화면은 '로딩 중...' 에
   * 멈춘 채로 남는다 (실측 — type=refund + charge_payment_methods[]=card).
   *
   * @effects search_action_omits_params_for_axes_the_type_lacks, list_datasource_gates_prohibited_axes_like_the_search_action
   */
  it('목록 데이터소스도 유형별 금지 축을 검색 액션과 동일하게 게이트한다', () => {
    const claims = (shell as any).data_sources.find((d: any) => d.id === 'claims');

    // 취소는 귀책 축이 없다.
    expect(claims.params.fault_types).toContain("!== 'cancel'");
    // 환불·취소는 청구 축이 없다.
    expect(claims.params.charge_methods).toContain("=== 'exchange'");
    expect(claims.params.charge_methods).toContain("=== 'return'");
    // 환불·취소는 청구 결제수단 축이 없다.
    expect(claims.params.charge_payment_methods).toContain("=== 'exchange'");
    expect(claims.params.charge_payment_methods).toContain("=== 'return'");

    // 게이트를 통과하지 못한 축은 보내지 않는다 (빈 문자열이 아니라 null).
    for (const key of ['fault_types', 'charge_methods', 'charge_payment_methods']) {
      expect(claims.params[key]).toContain(': null');
    }
  });

  /**
   * 게이트 대상 3축은 검색 액션과 데이터소스가 정확히 같은 집합이어야 한다 —
   * 한쪽에만 축이 늘면 그 축이 다시 무게이트 경로가 된다.
   *
   * @effects search_action_omits_params_for_axes_the_type_lacks, search_action_and_datasource_gate_the_same_axis_set
   */
  it('검색 액션과 데이터소스가 게이트하는 축 집합이 같다', () => {
    const claims = (shell as any).data_sources.find((d: any) => d.id === 'claims');
    const query = (shell as any).named_actions.searchClaims.params.query;

    const gatedInAction = Object.keys(query)
      .filter((k) => String(query[k]).includes("query.type || 'exchange'"))
      .map((k) => k.replace('[]', ''))
      .sort();
    const gatedInSource = Object.keys(claims.params)
      .filter((k) => k !== 'type' && String(claims.params[k]).includes("query.type || 'exchange'"))
      .sort();

    expect(gatedInSource).toEqual(gatedInAction);
  });
});

/**
 * 화면이 참조하는 `$t:` 네임스페이스가 실제로 적재되는지 — partial 선언 축.
 *
 * 프론트엔드 다국어는 `resources/lang/{ko,en}.json` 안에 `$partial` 선언이 놓인 키
 * 위치로만 partial 사전을 적재한다. 사전 파일을 새로 만들어도 그 선언을 빠뜨리면
 * 파일은 디스크에만 있고 런타임 사전에는 없다. 사전에 없는 키는 예외를 던지지 않고
 * 키 문자열을 그대로 출력하므로, 화면 전체가 `sirsoft-ecommerce.admin.claim.title`
 * 같은 원문으로 렌더되면서 오류도 경고도 남지 않는다.
 *
 * 파일 존재만 보면 통과하므로 선언을 직접 단언한다. 형제(admin.order)와 같은 형태여야
 * 하며, 형태가 달라도 JSON 은 유효해 구조 검사로는 잡히지 않는다.
 */
describe('[case:components-misc-i18n-partial-missing] 클레임 목록 — 다국어 partial 선언', () => {
  const LOCALES = ['ko', 'en'] as const;

  /**
   * ko·en 사전의 admin.claim 이 partial 파일을 선언한다 — 선언이 빠지면 목록 문구가 키로 보인다.
   *
   * @effects claim_list_i18n_partial_declared
   */
  it.each(LOCALES)('%s.json 의 admin.claim 이 partial 을 선언한다', (locale) => {
    const dict = JSON.parse(
      fs.readFileSync(path.join(MODULE_ROOT, 'resources/lang', `${locale}.json`), 'utf8')
    );

    // 선언 형태: { admin: { claim: { "$partial": "partial/{locale}/admin/claim.json" } } }
    // 형제(admin.order)와 같은 형태여야 한다 — 형태가 달라도 JSON 은 유효하므로 조용히 통과한다.
    const decl = dict?.admin?.claim;

    expect(decl, `${locale}.json 에 admin.claim 항목이 없다 — 화면 전체가 키 원문으로 렌더된다`)
      .toBeDefined();
    expect(decl.$partial, `admin.claim 에 $partial 선언이 없다`)
      .toBe(`partial/${locale}/admin/claim.json`);
    expect(typeof dict.admin.order.$partial, '형제 선언과 형태가 다르다')
      .toBe(typeof decl.$partial);
  });

  /**
   * 선언한 partial 파일이 실제로 있고 비어 있지 않다.
   *
   * @effects claim_list_i18n_partial_declared
   */
  it.each(LOCALES)('%s 의 admin/claim 사전 파일이 실재하고 비어 있지 않다', (locale) => {
    const file = path.join(MODULE_ROOT, 'resources/lang/partial', locale, 'admin/claim.json');

    expect(fs.existsSync(file), `${file} 이 없다`).toBe(true);
    expect(Object.keys(JSON.parse(fs.readFileSync(file, 'utf8'))).length).toBeGreaterThan(0);
  });
});
