/**
 * @file adminClaimListBody.test.ts
 * @description 관리자 클레임 목록 본체 — 구조 계약 (S8b · D26)
 *
 * 목록 본체(서브탭·일괄 변경 바·DataGrid)와 완료 처리 모달이 지켜야 할 축을 고정한다.
 * 다섯 가지가 모두 "조용히" 깨지는 종류라 구조로 못박는다:
 *
 *  1. 일괄 상태 어휘가 게이트(`BulkClaimStatusRequest::assignableStatuses()`)와 같은 집합인가.
 *     부분집합이면 운영자가 지정할 수 있어야 할 상태가 화면에서 사라지고, 초과집합이면
 *     그 값을 고르는 순간 요청 전체가 422 가 된다 — 목록은 그대로 남아 실패가 안 보인다.
 *  2. 컬럼 표시 영속의 SSoT 가 하나인가. 정본(주문 목록)은 `ColumnSelector` 자기 키와
 *     `g7_order_visible_columns` 두 벌을 쓰는데, 후자의 쓰기 핸들러는 키가 하드코딩이라
 *     그대로 복제하면 클레임 화면이 주문 화면 설정을 덮어쓴다.
 *  3. 전환 오버레이 대상 이름이 DataGrid id 와 맞물리는가. 어긋나면 오버레이가 아무 데도
 *     걸리지 않아 목록이 갱신되는 동안 옛 행이 그대로 보인다.
 *  4. 완료 처리 모달이 특정 화면의 데이터소스를 읽지 않는가 (D26 — 세 진입점 공용).
 *  5. 금액을 화면이 조립하지 않는가. 통화 기호·자릿수는 설정이 정하므로 서버가 준
 *     `*_formatted` 만 쓴다.
 *
 * @vitest-environment node
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { callbacksOf, initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';

import shell from '../../../layouts/admin/admin_ecommerce_claim_list.json';
import filterSection from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_partial_filter_section.json';
import datagrid from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_partial_claim_datagrid.json';
import bulkSection from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_partial_bulk_action_section.json';
import bulkConfirmModal from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_modal_bulk_confirm.json';
import completeModal from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_modal_claim_complete.json';

const MODULE_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');

type Node = Record<string, any>;

/** 이 세션이 소유한 레이아웃 파일 (금액·병합 규약을 전수로 본다) */
const BODY_FILES: Array<[string, Node]> = [
  ['datagrid', datagrid as Node],
  ['bulk_section', bulkSection as Node],
  ['bulk_confirm_modal', bulkConfirmModal as Node],
  ['complete_modal', completeModal as Node],
];

/**
 * 서브트리의 모든 노드를 훑는다. (children 뿐 아니라 props/actions 안쪽까지)
 *
 * @param node 시작 노드
 * @param visit 방문 함수
 */
function walk(node: unknown, visit: (n: Node) => void): void {
  if (Array.isArray(node)) {
    node.forEach((child) => walk(child, visit));

    return;
  }

  if (node && typeof node === 'object') {
    visit(node as Node);
    Object.values(node as Node).forEach((value) => walk(value, visit));
  }
}

/**
 * 조건에 맞는 노드를 모두 모은다.
 *
 * @param node 시작 노드
 * @param match 판정 함수
 * @returns 일치한 노드 배열
 */
function collect(node: unknown, match: (n: Node) => boolean): Node[] {
  const found: Node[] = [];
  walk(node, (n) => {
    if (match(n)) {
      found.push(n);
    }
  });

  return found;
}

/**
 * id 로 노드 하나를 찾는다.
 *
 * @param node 시작 노드
 * @param id 노드 id
 * @returns 노드
 */
function byId(node: unknown, id: string): Node {
  const found = collect(node, (n) => n.id === id)[0];

  if (!found) {
    throw new Error(`노드 #${id} 를 찾지 못했다`);
  }

  return found;
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

/**
 * Enum 소스의 `adminAssignableStatuses()` 본문이 열거한 case 이름을 읽는다.
 *
 * 환불처럼 목록을 직접 나열하는 Enum 이 대상이다 — 값을 테스트에 복사하면 그 사본이 세 번째
 * 출처가 되어, Enum 이 바뀌어도 화면과 테스트가 같이 낡은 채로 초록이 된다 (S8a 판정 ④).
 *
 * @param enumName Enum 클래스명
 * @returns case 이름 배열 (미선언이면 빈 배열)
 */
function adminAssignableCaseNames(enumName: string): string[] {
  const source = fs.readFileSync(path.join(MODULE_ROOT, 'src/Enums', `${enumName}.php`), 'utf8');
  const body = source.match(
    /function\s+adminAssignableStatuses\(\)[\s\S]*?return\s*\[([\s\S]*?)\];/,
  );

  return body ? [...body[1].matchAll(/self::(\w+)/g)].map((m) => m[1]) : [];
}

/**
 * Enum 소스에서 case 이름 → backed value 매핑을 읽는다.
 *
 * @param enumName Enum 클래스명
 * @returns case 이름 → 값 매핑
 */
function enumCaseMap(enumName: string): Record<string, string> {
  const source = fs.readFileSync(path.join(MODULE_ROOT, 'src/Enums', `${enumName}.php`), 'utf8');

  return Object.fromEntries(
    [...source.matchAll(/^\s{4}case\s+(\w+)\s*=\s*'([^']+)';/gm)].map((m) => [m[1], m[2]]),
  );
}

/**
 * 유형별 관리자 지정 가능 상태 어휘를 Enum 소스에서 도출한다.
 *
 * 교환·반품은 `REQUESTED`/`PAID`/`WITHDRAWN` 을 뺀 전체(그 Enum 의 `array_filter` 규칙),
 * 환불은 `adminAssignableStatuses()` 가 나열한 case 를 그대로 읽는다 — 어느 쪽도 값 목록을
 * 테스트에 복사하지 않는다 (S8a 판정 ④).
 *
 * @param claimType 클레임 유형
 * @returns 지정 가능 상태 값 배열
 */
function adminAssignableValues(claimType: string): string[] {
  if (claimType === 'refund') {
    const caseMap = enumCaseMap('RefundStatusEnum');
    const names = adminAssignableCaseNames('RefundStatusEnum');

    expect(names.length).toBeGreaterThan(0);

    return names.map((name) => caseMap[name]);
  }

  const enumName = claimType === 'exchange' ? 'ExchangeStatusEnum' : 'ReturnStatusEnum';

  return enumValues(enumName).filter((value) => !['requested', 'paid', 'withdrawn'].includes(value));
}

describe('클레임 목록 셸 — 본체 배선 (S8b)', () => {
  const content = (shell as Node).slots.content[0].children as Node[];

  /**
   * @scenario claim_type=exchange, completion_choice=not_applicable
   * @effects list_body_replaces_placeholder
   */
  it('S8a 가 남긴 자리표시 노드와 주석이 사라졌다', () => {
    expect((shell as Node)._comment).toBeUndefined();
    expect(collect(shell, (n) => n.id === 'list_body_placeholder')).toHaveLength(0);
  });

  /**
   * @effects subtabs_render_above_filter_card
   */
  it('노드 순서가 §6-4 와이어프레임과 같다 (서브탭 → 필터 카드 → 목록 본체)', () => {
    expect(content.map((n) => n.id ?? n.partial)).toEqual([
      'page_header',
      'mobile_top_actions',
      'claim_type_subtabs',
      'partials/admin_ecommerce_claim_list/_partial_filter_section.json',
      'claim_data_table_section',
    ]);
  });

  /**
   * @effects list_body_replaces_placeholder
   */
  it('목록 본체는 일괄 변경 바 → DataGrid 순으로 partial 을 싣는다', () => {
    expect((byId(shell, 'claim_data_table_section').children ?? []).map((n: Node) => n.partial)).toEqual([
      'partials/admin_ecommerce_claim_list/_partial_bulk_action_section.json',
      'partials/admin_ecommerce_claim_list/_partial_claim_datagrid.json',
    ]);
  });

  /**
   * @effects completion_modal_registered_once
   */
  it('일괄 확인 모달과 완료 처리 모달이 셸에 등록돼 있다', () => {
    const partials = ((shell as Node).modals ?? []).map((m: Node) => m.partial);

    expect(partials).toContain('partials/admin_ecommerce_claim_list/_modal_bulk_confirm.json');
    expect(partials).toContain('partials/admin_ecommerce_claim_list/_modal_claim_complete.json');
  });

  /**
   * @effects subtab_badges_read_response_counts
   */
  it('서브탭 뱃지는 응답의 유형별 건수를 그대로 읽는다', () => {
    const tabs = byId(shell, 'claim_type_subtabs').props.tabs as Node[];

    expect(tabs.map((tab) => tab.id)).toEqual(['exchange', 'return', 'refund', 'cancel']);
    tabs.forEach((tab) => {
      expect(tab.badge).toBe(`{{claims?.data?.counts?.${tab.id} ?? 0}}`);
    });
  });

  /**
   * 유형 전환은 의도적 리셋이다 — 병합하면 앞 유형의 `statuses[]` 가 따라와 목록 전체가
   * 422 가 된다. 면제는 코드에 표식으로 남긴다.
   *
   * @effects subtab_switch_resets_type_scoped_filters
   */
  it('유형 전환은 병합하지 않고, 그 의도를 면제 표식으로 남긴다', () => {
    const actions = callbacksOf(byId(shell, 'claim_type_subtabs').actions[0], 'actions') as Node[];
    const navigate = actions.find((a) => a.handler === 'navigate') as Node;

    expect(navigate.params.mergeQuery).toBeUndefined();
    expect(navigate.params.query.type).toBe('{{$args[0]}}');
    // 유형 종속 어휘(상태·귀책·청구·사유·접수 경로·회수 지연·환불 미접수·청구 결제수단)는 따라가지 않는다.
    ['statuses[]', 'fault_types[]', 'charge_methods[]', 'reason_code', 'request_source', 'collection_overdue', 'pending_refund', 'charge_payment_methods[]'].forEach((key) => {
      expect(navigate.params.query).not.toHaveProperty(key);
    });
    expect(navigate.comment).toContain('audit:allow layout-list-context-navigate-merge-query');
  });

  /**
   * 다른 탭의 뱃지는 "그 탭으로 가면 몇 건이 보이는가" 다 — 서버는 그 건수를 유형 공통 필터
   * (`ClaimListRequest::countFilters()`)로 센다. 탭을 누를 때 그 필터를 버리면 「반품 4」를 눌러
   * 17건을 보게 된다(실측 2026-10-07). 승계 키 집합은 서버의 건수 필터 집합과 같아야 한다.
   *
   * @effects subtab_switch_carries_count_filters
   */
  it('유형 전환은 뱃지를 센 공통 필터(기간·검색·회원구분·금액·통화)를 그대로 이어 간다', () => {
    const actions = callbacksOf(byId(shell, 'claim_type_subtabs').actions[0], 'actions') as Node[];
    const navigate = actions.find((a) => a.handler === 'navigate') as Node;
    const request = fs.readFileSync(path.join(MODULE_ROOT, 'src/Http/Requests/Admin/ClaimListRequest.php'), 'utf8');
    const body = request.slice(request.indexOf('function countFilters'));
    const countKeys = [...body.slice(0, body.indexOf('];')).matchAll(/'([a-z_]+)' =>/g)].map((m) => m[1]);

    expect(countKeys.length).toBeGreaterThan(0);
    expect(Object.keys(navigate.params.query).filter((k) => k !== 'type').sort()).toEqual([...countKeys].sort());
    countKeys.forEach((key) => {
      expect(navigate.params.query[key]).toBe(`{{query.${key} || null}}`);
    });
  });

  /**
   * 상태 어휘는 유형이 정한다 — 앞 유형에서 고른 값을 들고 넘어가면 새 유형의 어휘에 없어
   * [일괄 적용] 이 요청 전체를 422 로 만든다.
   *
   * @effects subtab_switch_clears_bulk_status, subtab_switch_clears_carried_selection
   */
  it('유형 전환이 일괄 상태 선택과 체크한 행을 함께 비운다', () => {
    const actions = callbacksOf(byId(shell, 'claim_type_subtabs').actions[0], 'actions') as Node[];
    const reset = actions.find((a) => a.handler === 'setState') as Node;

    expect(reset.params.target).toBe('_local');
    expect(reset.params.bulkClaimStatus).toBe('');
    // 선택도 여기서 비운다. DataGrid 의 `selectionScope: 'page'` 는 **지금 페이지에 없는
    // id 만** 버리는데, `id` 는 유형마다 다른 테이블의 기본키(order_exchanges /
    // order_returns / order_refunds)라 값이 겹치면 앞 유형에서 고른 선택이 살아남는다 —
    // 운영자가 체크한 적 없는 다른 유형의 신청서가 [일괄 적용] 대상이 된다(오류 없음).
    expect(reset.params.selectedItems).toEqual([]);
  });

  /**
   * @effects list_body_state_declared_in_shell
   */
  it('본체가 쓰는 상태가 셸에 선언돼 있다', () => {
    expect(initStateOf(shell as Node).selectedItems).toEqual([]);
    expect(initStateOf(shell as Node).bulkClaimStatus).toBe('');
    expect(initStateOf(shell as Node).perPage).toBe('20');
  });

  /**
   * 유형 전환은 `per_page` 를 URL 에서 떨구므로 서버는 기본값 20 으로 응답한다 — 셀렉트만
   * 앞 유형의 값을 붙들고 있으면 화면이 실제와 다른 페이지 크기를 가리킨다.
   *
   * @effects page_size_control_restores_from_url
   */
  it('유형 전환이 페이지 크기 선택도 기본값으로 되돌린다', () => {
    const actions = callbacksOf(byId(shell, 'claim_type_subtabs').actions[0], 'actions') as Node[];
    const reset = actions.find((a) => a.handler === 'setState') as Node;

    expect(reset.params.perPage).toBe('20');
  });

  /**
   * 북마크·뒤로가기로 `?per_page=50` 에 들어오면 목록은 50건을 그리는데, 셀렉트가 상태
   * 기본값('20')에 머물면 컨트롤이 실제 페이지 크기를 잘못 가리킨다.
   *
   * @effects page_size_control_restores_from_url
   */
  it('진입 시 페이지 크기를 URL 에서 복원한다', () => {
    const seed = ((shell as Node).init_actions as Node[]).find(
      (a) => a.handler === 'setState' && a.params?._local?.filter !== undefined,
    ) as Node;

    expect(seed.params._local.perPage).toContain('query.per_page');
    // 셀렉트 어휘 밖의 값(수기 URL)은 빈 컨트롤이 되므로 기본값으로 떨어뜨린다.
    expect(seed.params._local.perPage).toContain("'10','20','50','100'");
  });
});

describe('컬럼 표시 영속 — SSoT 는 ColumnSelector 자기 키 하나다 (2차 F5 이월)', () => {
  /**
   * @effects visible_columns_single_persistence_owner
   */
  it('셸이 두 번째 키(g7_claim_visible_columns)를 읽지 않는다', () => {
    const loaders = ((shell as Node).init_actions ?? []).filter(
      (a: Node) => a.handler === 'loadFromLocalStorage',
    );

    expect(loaders).toHaveLength(0);
  });

  /**
   * @effects visible_columns_single_persistence_owner
   */
  it('클레임 레이아웃 어디에서도 saveVisibleColumns 를 부르지 않는다', () => {
    const layouts: Node[] = [shell as Node, filterSection as Node, ...BODY_FILES.map(([, doc]) => doc)];
    const writers = layouts.flatMap((doc) =>
      collect(doc, (n) => typeof n.handler === 'string' && n.handler.endsWith('saveVisibleColumns')),
    );

    expect(writers).toHaveLength(0);
  });

  /**
   * ColumnSelector 는 마운트 시 저장값으로 `onColumnVisibilityChange` 를 쏘아
   * `_local.visibleColumns` 를 채운다 — DataGrid 가 같은 상태를 읽으면 왕복이 닫힌다.
   *
   * @effects column_selector_and_datagrid_share_state
   */
  it('DataGrid 와 ColumnSelector 가 같은 상태·같은 selector id 를 본다', () => {
    const selector = byId(filterSection, 'f_003_column_selector');
    const grid = byId(datagrid, 'claim_datagrid');

    expect(selector.props.id).toBe('admin_claim_list_columns');
    // DataGrid 에는 `columnSelectorId` prop 이 없다(조용히 버려졌다 — P7 #7). 둘은 selector id 가
    // 아니라 `_local.visibleColumns` 하나를 함께 읽어 이어진다.
    expect(grid.props).not.toHaveProperty('columnSelectorId');
    expect(selector.props.visibleColumns).toBe('{{_local.visibleColumns}}');
    expect(grid.props.visibleColumns).toBe('{{_local.visibleColumns}}');
  });

  /**
   * 컬럼 목록이 어긋나면 켠 컬럼이 표에 없거나(선택은 되는데 안 나옴), 표에만 있는 컬럼을
   * 끌 수 없다. 둘 다 오류 없이 나타난다.
   *
   * @effects column_selector_matches_datagrid_columns
   */
  it('ColumnSelector 11종과 DataGrid 컬럼이 같은 집합이다 (30분 확인 4번)', () => {
    const selectorFields = (byId(filterSection, 'f_003_column_selector').props.columns as Node[]).map(
      (c) => c.field,
    );
    const gridFields = (byId(datagrid, 'claim_datagrid').props.columns as Node[]).map((c) => c.field);

    // 11 = 종전 10 + 운송장(tracking — 송장은 상품 행마다, #599)
    expect(selectorFields).toHaveLength(11);
    expect([...gridFields].sort()).toEqual([...selectorFields].sort());
  });
});

describe('전환 오버레이 대상 — DataGrid id 와 맞물린다 (30분 확인 3번)', () => {
  /**
   * @effects transition_overlay_targets_datagrid_body
   */
  it('모든 전환 오버레이 대상이 `<DataGrid id>__body` 다', () => {
    const gridId = byId(datagrid, 'claim_datagrid').id;
    const targets = [shell as Node, ...BODY_FILES.map(([, doc]) => doc)].flatMap((doc) =>
      collect(doc, (n) => typeof n.transition_overlay_target === 'string').map(
        (n) => n.transition_overlay_target as string,
      ),
    );

    expect(targets.length).toBeGreaterThan(0);
    targets.forEach((target) => expect(target).toBe(`${gridId}__body`));
  });
});

describe('일괄 상태 변경 바 (S8b)', () => {
  /**
   * 취소는 게이트의 `type` 어휘에 아예 없다 — 일괄 전이 대상이 아니다.
   *
   * @effects bulk_bar_absent_for_cancel
   */
  it('취소 유형에는 일괄 바를 그리지 않는다', () => {
    expect((bulkSection as Node).if).toBe("{{(query.type ?? 'exchange') !== 'cancel'}}");
  });

  /**
   * @effects bulk_status_vocabulary_matches_request_gate
   */
  it.each([
    ['exchange', 'cb_002_status_select_exchange'],
    ['return', 'cb_003_status_select_return'],
    ['refund', 'cb_004_status_select_refund'],
  ])('%s 일괄 상태 어휘가 게이트와 정확히 같은 집합이다', (claimType, selectId) => {
    const options = (byId(bulkSection, selectId).props.options as Node[])
      .map((o) => o.value)
      .filter((value) => value !== '');

    expect([...options].sort()).toEqual([...adminAssignableValues(claimType)].sort());
  });

  /**
   * @scenario claim_type=return, completion_choice=not_applicable
   * @effects bulk_status_vocabulary_matches_request_gate
   */
  it('유형별 셀렉트는 그 유형에서만 그려진다 (어휘 혼입 차단)', () => {
    expect(byId(bulkSection, 'cb_002_status_select_exchange').if).toContain("=== 'exchange'");
    expect(byId(bulkSection, 'cb_003_status_select_return').if).toContain("=== 'return'");
    expect(byId(bulkSection, 'cb_004_status_select_refund').if).toContain("=== 'refund'");
  });

  /**
   * @effects status_labels_read_type_prefixed_enum_keys
   */
  it('상태 라벨은 유형 접두 Enum 키를 읽는다 (라벨 표 복제 금지)', () => {
    const prefixes: Record<string, string> = {
      cb_002_status_select_exchange: '$t:sirsoft-ecommerce.enums.exchange_status.',
      cb_003_status_select_return: '$t:sirsoft-ecommerce.enums.return_status.',
      cb_004_status_select_refund: '$t:sirsoft-ecommerce.enums.refund_status.',
    };

    Object.entries(prefixes).forEach(([selectId, prefix]) => {
      (byId(bulkSection, selectId).props.options as Node[])
        .filter((o) => o.value !== '')
        .forEach((option) => expect(option.label).toBe(`${prefix}${option.value}`));
    });
  });

  /**
   * 일괄 바에는 송장 한 쌍을 두지 않는다 — 송장은 신청서마다 달라 한 쌍을 여러 건에 나눠 쓸 수 없고,
   * 회수중·발송중이면 확인 창이 신청서마다 한 줄씩 받는다(`trackings[]`, #599 후속 운영 결정).
   *
   * @effects bulk_bar_has_no_uncontracted_tracking_inputs
   */
  it('일괄 바에 송장 한 쌍 입력이 없다 (송장은 확인 창이 신청서마다 받는다)', () => {
    const names = collect(bulkSection, (n) => typeof n.props?.name === 'string').map(
      (n) => n.props.name as string,
    );

    expect(names).not.toContain('bulkCourier');
    expect(names).not.toContain('bulkTrackingNumber');
  });

  /**
   * @effects completion_defaults_reach_claims_read_operator
   */
  it('[일괄 적용] 은 유형·상태·반려 사유·대상과 완료 기본값·택배사 선택지를 함께 넘긴다', () => {
    const action = byId(bulkSection, 'cb_005_bulk_apply_button').actions[0] as Node;

    expect(action.handler).toBe('sirsoft-ecommerce.buildClaimBulkConfirmData');
    expect(Object.keys(action.params).sort()).toEqual(
      ['carriers', 'claimType', 'completeDefaults', 'ids', 'rejectReason', 'rows', 'status'].sort(),
    );
  });

  /**
   * 설정 엔드포인트(`admin/settings`)는 `settings.read` 게이트인데 이 화면의 라우트는
   * `claims.read` 다 — 기본 역할 `manager` 는 클레임 권한만 갖는다. 모달 기본값을 설정
   * 응답에서 읽으면 그 운영자에게만 403 으로 비고, 상점이 재고 복구를 꺼 두어도 모달은
   * 켜진 채 열려 그대로 실행된다(오류·경고 없음).
   *
   * @effects completion_defaults_reach_claims_read_operator
   */
  it('완료 기본값을 목록 응답에서 읽는다 (설정 응답이 아니라)', () => {
    const action = byId(bulkSection, 'cb_005_bulk_apply_button').actions[0] as Node;

    expect(action.params.completeDefaults).toContain('claims?.data?.complete_defaults');
    expect(action.params.completeDefaults).not.toContain('ecommerce_settings');
  });
});

describe('DataGrid (S8b)', () => {
  const grid = byId(datagrid, 'claim_datagrid');

  /**
   * 선택은 화면 밖 상태에 남는다 — 페이지를 넘겨 사라진 행의 선택이 살아 있으면 일괄
   * 처리가 운영자가 보지도 체크하지도 않은 행을 대상으로 삼는다.
   *
   * @effects selection_scope_declared_page
   */
  it('일괄 처리 목록이므로 selectionScope 가 page 다', () => {
    expect(grid.props.selectionScope).toBe('page');
  });

  /**
   * @effects selection_closed_for_cancel_subtab
   */
  it('취소 유형과 권한 없는 운영자에게는 선택이 열리지 않는다', () => {
    expect(grid.props.selectable).toBe(
      "{{(query.type ?? 'exchange') !== 'cancel' && claims?.data?.abilities?.can_update_status === true}}",
    );
  });

  /**
   * @effects server_pagination_reads_response_meta
   */
  it('서버 페이지네이션을 응답 메타에서 읽는다', () => {
    expect(grid.props.serverSidePagination).toBe(true);
    expect(grid.props.serverCurrentPage).toBe('{{claims?.data?.pagination?.current_page ?? 1}}');
    expect(grid.props.serverTotalPages).toBe('{{claims?.data?.pagination?.last_page ?? null}}');
  });

  /**
   * 목록 게이트에 정렬 파라미터가 없다 — 옵션을 만들면 화면에만 있고 저장소가 모르는
   * 축이 되어 422 가 되거나 조용히 무시된다.
   *
   * @effects no_sort_control_without_repository_support
   */
  it('게이트가 모르는 정렬 컨트롤을 두지 않는다', () => {
    const request = fs.readFileSync(
      path.join(MODULE_ROOT, 'src/Http/Requests/Admin/ClaimListRequest.php'),
      'utf8',
    );

    expect(request).not.toMatch(/'sort_by'\s*=>/);
    expect(collect(datagrid, (n) => n.props?.name === 'sortBy')).toHaveLength(0);
  });
});

describe('완료 처리 모달 — 세 진입점 공용 (D26)', () => {
  /**
   * 모달이 목록의 데이터소스를 읽으면 상세·완료 버튼 진입점에서 그 바인딩이 비어 버린다.
   *
   * @effects completion_modal_reads_only_shared_global_contract
   */
  it('모달은 화면별 데이터소스를 읽지 않고 _global 계약만 읽는다', () => {
    const serialized = JSON.stringify(completeModal);

    expect(serialized).not.toContain('claims?.data');
    expect(serialized).not.toContain('ecommerce_settings?.data');
    expect(serialized).toContain('_global.claimCompleteRows');
    expect(serialized).toContain('_global.claimCompleteContext');
  });

  /**
   * @effects completion_modal_registered_once
   */
  it('모달 id 가 세 진입점이 여는 이름과 같다', () => {
    expect((completeModal as Node).id).toBe('modal_claim_complete');
  });

  /**
   * @effects completion_modal_has_per_claim_and_header_stock_toggles
   */
  it('건별 재고 체크와 헤더 일괄 체크가 있다', () => {
    // 반복 안 노드의 id 는 index_var 로 동적화돼 있다 — 정적 id 를 그대로 두면 행마다 같은
    // HTML id 가 중복된다.
    const rowToggle = byId(completeModal, 'cc_006_restore_checkbox_{{claimRowIndex}}');
    const allToggle = byId(completeModal, 'cc_004_restore_all_checkbox');

    expect(rowToggle.actions[0].handler).toBe('sirsoft-ecommerce.toggleClaimCompleteStock');
    expect(allToggle.actions[0].handler).toBe('sirsoft-ecommerce.toggleClaimCompleteStockAll');
  });

  /**
   * 같은 HTML id 가 행마다 반복되면 접근성 트리와 라벨 연결이 깨지고, 실측 셀렉터가 첫 행만
   * 집는다.
   *
   * @effects iteration_row_ids_are_index_scoped
   */
  it('반복 안 노드의 id 가 index_var 로 동적화돼 있다', () => {
    const iterationRoot = collect(completeModal, (n) => !!n.iteration)[0];

    expect(iterationRoot.iteration.index_var).toBe('claimRowIndex');
    collect(iterationRoot, (n) => typeof n.id === 'string').forEach((node) => {
      expect(node.id, `${node.id} 가 행마다 중복된다`).toContain('{{claimRowIndex}}');
    });
  });

  /**
   * 체크 표시의 근거는 **선택 상태**(`claimCompleteRestore`)이지 미리보기 응답
   * (`claimCompleteRows[].restore`)이 아니다.
   *
   * 응답을 근거로 두면 제어 컴포넌트라 클릭 직후 React 가 옛 값으로 되돌리고, debounce
   * 200ms + 왕복이 끝나야 표시가 바뀐다 — 운영자에게는 클릭이 먹지 않는 것으로 보이고
   * 다시 누르게 된다. 미리보기가 실패하면 `revertSelectionToPreviewed()` 가 이 맵을 화면
   * 값으로 되돌리므로, 근거를 옮겨도 "화면이 예고하는 것 = 실행하는 것" 은 유지된다.
   *
   * @effects completion_modal_stock_toggle_reflects_immediately
   */
  it('재고 체크 표시가 응답이 아니라 선택 상태를 읽는다', () => {
    const rowToggle = byId(completeModal, 'cc_006_restore_checkbox_{{claimRowIndex}}');
    const allToggle = byId(completeModal, 'cc_004_restore_all_checkbox');

    expect(rowToggle.props.checked).toContain('_global.claimCompleteRestore');
    expect(rowToggle.props.checked).not.toContain('claimRow.restore');

    // 헤더 일괄 체크는 "지금 그려진 행 전부가 켜져 있는가" 라 행 목록은 계속 읽되,
    // 각 행의 켜짐 여부는 같은 선택 상태에서 판정한다.
    expect(allToggle.props.checked).toContain('_global.claimCompleteRestore');
    expect(allToggle.props.checked).not.toContain('r.restore');
  });

  /**
   * 4열 머리는 컬럼 이름('재고 복구')과 일괄 토글('전체 복구')을 함께 진다. 체크박스 라벨만
   * 두면 이 열이 무엇인지가 화면에서 사라지고, 3언어에 있는 `complete.col_restore_stock` 은
   * 소비처가 0 이 된다 (§6-4-1 컬럼명).
   * @effects completion_modal_has_per_claim_and_header_stock_toggles
   */
  it('재고 복구 열 머리가 컬럼 이름과 일괄 토글을 함께 낸다', () => {
    const header = collect(completeModal, (n) => n.text === '$t:sirsoft-ecommerce.admin.claim.complete.col_restore_stock');
    const allLabel = collect(completeModal, (n) => n.text === '$t:sirsoft-ecommerce.admin.claim.complete.restore_all');

    expect(header).toHaveLength(1);
    expect(allLabel).toHaveLength(1);
  });

  /**
   * '함께 실행되는 처리' 항목 순서는 §6-4-1 이 정한다 — 상태전이 · 재고 · 적립 · 쿠폰 ·
   * 회수비. 순서가 어긋나도 오류가 없어 요약만 계획과 다른 이야기를 한다.
   * @effects completion_summary_items_follow_spec_order
   */
  it('요약 항목이 §6-4-1 순서로 놓인다', () => {
    const summary = byId(completeModal, 'cc_014_summary');
    const order = (summary.children as Node[])
      .map((child) => String(child.text ?? ''))
      .map((text) => text.replace(/^\$t:sirsoft-ecommerce\.admin\.claim\.complete\./, '').split('|')[0]);

    expect(order).toEqual([
      'summary_label',
      'summary_transition',
      'summary_stock',
      'summary_earn',
      'summary_coupon',
      'summary_shipping_amount',
      'summary_shipping',
    ]);
  });

  /**
   * 해제한 건의 재고 폐기는 활동 로그에 남지 않는다 (`restoreOptionStockForClaim` 이 훅도
   * 로그도 발화하지 않는다). "기록됩니다" 라고 안내하면 운영자는 없는 이력을 찾게 된다 —
   * 화면은 지키지 못할 약속을 하지 않는다.
   * @effects discard_note_does_not_claim_recording
   */
  it('폐기 안내가 기록되지 않는 것을 기록된다고 말하지 않는다', () => {
    const note = byId(completeModal, 'cc_007_discard_note');

    expect(note.text).toBe('$t:sirsoft-ecommerce.admin.claim.complete.discard_note');

    const dictionaries: Array<[string, string]> = [
      ['ko', path.join(MODULE_ROOT, 'resources/lang/partial/ko/admin/claim.json')],
      ['en', path.join(MODULE_ROOT, 'resources/lang/partial/en/admin/claim.json')],
      [
        'ja',
        path.join(MODULE_ROOT, '../../../lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/partial/admin/claim.json'),
      ],
    ];

    dictionaries.forEach(([locale, file]) => {
      const text = JSON.parse(fs.readFileSync(file, 'utf8')).complete.discard_note as string;

      expect(text, `${locale} 안내가 비어 있다`).toBeTruthy();
      // 활동 로그·기록을 약속하는 어휘가 없어야 한다 (S8b 4차 N8 — 기록 자체는 S6b 이월).
      expect(/활동 로그|기록됩니다|기록된다|activity log|logged|記録され/.test(text), `${locale} 안내가 없는 이력을 약속한다`).toBe(false);
    });
  });

  /**
   * 자동바인딩은 boolean 을 문자열로 저장해 서버 boolean 규칙에서 422 가 된다.
   *
   * @effects completion_modal_boolean_inputs_disable_autobinding
   */
  it('체크박스·라디오가 자동바인딩을 쓰지 않는다', () => {
    ['cc_004_restore_all_checkbox', 'cc_006_restore_checkbox_{{claimRowIndex}}', 'cc_010_refund_radio'].forEach((id) => {
      expect(byId(completeModal, id).props.autoBinding).toBe(false);
    });
  });

  /**
   * 접수 여부는 기본값이 없다 — 설정은 초기 선택값일 뿐이며 서버는 두 값 중 하나를 반드시
   * 받는다.
   *
   * @effects refund_request_radio_offers_both_choices
   */
  it('환불신청서 라디오가 두 선택지를 낸다', () => {
    expect((byId(completeModal, 'cc_010_refund_radio').props.options as Node[]).map((o) => o.value)).toEqual([
      'true',
      'false',
    ]);
  });

  /**
   * @effects blocked_reasons_shown_separately
   */
  it('접수 불가 사유 두 가지를 구분해 안내한다', () => {
    const serialized = JSON.stringify(completeModal);

    expect(serialized).toContain('claim_refund_request_exists');
    expect(serialized).toContain('claim_refund_not_applicable');
  });

  /**
   * `$t:defer:` 가 해소되는 경로는 **`renderItemChildren` 하나뿐**이다 — DataGrid 의
   * `cellChildren`/`expandChildren` 이 그 경로를 탄다. 레이아웃 `iteration` 은 다르다:
   * DynamicRenderer 가 항목 컨텍스트를 실어 자기 자신을 다시 타는데, 그 경로는 defer 접두사를
   * 의도적으로 건너뛴다. 그래서 `iteration` 안이든 밖이든 defer 를 쓰면 원문 키가 그대로
   * 화면에 나온다 — 구조 테스트는 문자열만 비교하므로 통과하고, 브라우저에서만 드러난다
   * (실측이 완료 모달에서 4건을 잡았다). 항목 컨텍스트는 그 경로에도 실려 있으므로 일반
   * `$t:` 로 파라미터까지 정상 해석된다.
   *
   * @effects defer_translation_only_inside_repeat_context
   */
  it('[case:components-datagrid-iteration-tdefer] $t:defer: 는 cellChildren/expandChildren 안에서만 쓴다', () => {
    const offenders: string[] = [];

    const walk = (node: any, inItemChildren: boolean): void => {
      if (Array.isArray(node)) {
        node.forEach((child) => walk(child, inItemChildren));

        return;
      }
      if (!node || typeof node !== 'object') return;

      for (const [key, value] of Object.entries(node)) {
        // 주석은 렌더되지 않는다 — 이 규율을 설명하는 문장 자체가 걸리면 안 된다.
        if (key === 'comment' || key === '_comment') continue;

        if (typeof value === 'string' && value.includes('$t:defer:')) {
          if (!inItemChildren) offenders.push(`${node.id ?? '(no id)'}.${key}`);
        } else if (typeof value === 'object') {
          walk(value, inItemChildren || key === 'cellChildren' || key === 'expandChildren');
        }
      }
    };

    [shell, filterSection, datagrid, bulkSection, bulkConfirmModal, completeModal].forEach((tree) => walk(tree, false));

    expect(offenders).toEqual([]);
  });

  /**
   * §6-4-1 의 '함께 실행되는 처리' 는 회수배송비 차감도 알린다. 금액은 서버가 준 표기
   * 문자열만 쓰고, 통화가 섞여 합계 표기가 없으면 금액 없이 항목만 남긴다.
   *
   * @effects collection_fee_shown_in_completion_summary
   */
  it('요약이 회수배송비 차감을 알린다', () => {
    const withAmount = byId(completeModal, 'cc_017_summary_shipping_amount');
    const withoutAmount = byId(completeModal, 'cc_018_summary_shipping');

    expect(withAmount.text).toContain('deducted_shipping_fee_total_formatted');
    expect(withAmount.if).toContain('deducted_shipping_fee_total_formatted');
    // 표기 문자열이 없을 때만(통화 혼재) 금액 없는 형제가 대신 뜬다.
    expect(withoutAmount.text).toContain('complete.summary_shipping');
    expect(withoutAmount.text).not.toContain('amount=');
    expect(withoutAmount.if).toContain('== null');
  });

  /**
   * @effects completion_cancel_button_only_closes
   */
  it('취소 버튼은 아무 실행 없이 닫기만 한다 (부분 실행 없음)', () => {
    const cancel = byId(completeModal, 'cc_021_cancel_button');

    // 취소 경로에 등장하는 핸들러를 전부 모은다 (sequence 하위까지).
    const handlers = [...JSON.stringify(cancel.actions).matchAll(/"handler":\s*"([^"]+)"/g)]
      .map((match) => match[1]);

    // 허용 집합은 "화면을 닫는 것" 과 "대기 중인 미리보기 재조회를 취소하는 것" 뿐이다.
    // 미리보기는 DB 를 바꾸지 않으므로 둘 다 상태를 남기지 않는다 (D26).
    expect(handlers.length).toBeGreaterThan(0);
    expect(new Set(handlers)).toEqual(
      new Set(['sequence', 'sirsoft-ecommerce.clearClaimCompleteTimers', 'closeModal']),
    );

    // 상태를 바꾸는 핸들러가 섞이면 취소가 부분 실행이 된다.
    handlers.forEach((handler) => {
      expect(handler).not.toMatch(/apiCall|executeClaim|refetchDataSource|setState/);
    });
  });
});

describe('금액 표기 — 서버가 준 문자열만 쓴다', () => {
  /**
   * 통화 기호·자릿수는 설정이 정한다 — 화면이 조립하면 기본 통화가 원이 아닌 상점에서
   * 값은 맞고 단위만 틀린 금액이 나가는데, 오류도 경고도 남지 않는다.
   *
   * @effects amounts_render_from_server_formatted_only
   */
  it.each(BODY_FILES)('%s 에 통화 기호·숫자 포맷 조립이 없다', (_name, doc) => {
    const serialized = JSON.stringify(doc);

    expect(serialized).not.toContain('toLocaleString');
    expect(serialized).not.toMatch(/원'|원"/);
    expect(serialized).not.toContain('toFixed');
  });

  /**
   * @effects amounts_render_from_server_formatted_only
   */
  it('금액 셀은 *_formatted 필드만 바인딩한다', () => {
    const amountColumn = (byId(datagrid, 'claim_datagrid').props.columns as Node[]).find(
      (c) => c.field === 'amount',
    ) as Node;
    const texts = collect(amountColumn, (n) => typeof n.text === 'string').map((n) => n.text as string);

    texts
      .filter((text) => text.includes('row.') && !text.includes('$t:'))
      .forEach((text) => expect(text).toMatch(/_formatted/));
  });
});

describe('목록 컨텍스트 왕복 — 클러스터 안 이동은 상태를 보존한다', () => {
  /**
   * 페이지·페이지크기·상세 이동은 모두 같은 목록 클러스터 안이다 — 병합하지 않으면 걸어둔
   * 필터가 통째로 날아가는데, 화면은 정상적으로 새 목록을 그린다.
   *
   * @effects list_context_preserved_across_cluster_navigation
   */
  it('DataGrid 의 모든 이동이 mergeQuery 를 유지한다', () => {
    const navigations = collect(
      datagrid,
      (n) => n.handler === 'navigate' && typeof n.params?.path === 'string',
    );

    expect(navigations.length).toBeGreaterThan(0);
    navigations.forEach((nav) => {
      expect(nav.params.mergeQuery).toBe(true);
      expect(nav.params.query).toBeDefined();
    });
  });
});

describe('i18n — 새 화면 라벨이 세 사전에 모두 있다', () => {
  /**
   * 한 로케일에서 키가 빠지면 그 언어만 조용히 ko 로 폴백한다 — 화면은 정상으로 보인다.
   *
   * @effects new_labels_present_in_all_three_dictionaries
   */
  it.each([
    ['ko', path.join(MODULE_ROOT, 'resources/lang/partial/ko/admin/claim.json')],
    ['en', path.join(MODULE_ROOT, 'resources/lang/partial/en/admin/claim.json')],
    [
      'ja',
      path.join(
        MODULE_ROOT,
        '../../../lang-packs/_bundled/g7-module-sirsoft-ecommerce-ja/frontend/partial/admin/claim.json',
      ),
    ],
  ])('%s 사전이 본체가 쓰는 키를 전부 담는다', (_locale, file) => {
    const dictionary = JSON.parse(fs.readFileSync(file, 'utf8'));
    const used = new Set<string>();

    BODY_FILES.forEach(([, doc]) => {
      [...JSON.stringify(doc).matchAll(/\$t:(?:defer:)?sirsoft-ecommerce\.admin\.claim\.([A-Za-z0-9_.]+)/g)]
        .map((m) => m[1])
        .forEach((key) => used.add(key));
    });

    expect(used.size).toBeGreaterThan(0);
    used.forEach((key) => {
      const value = key.split('.').reduce<any>((node, part) => node?.[part], dictionary);
      expect(value, `${key} 가 사전에 없다`).toBeTypeOf('string');
    });
  });
});

describe('목록 일괄 반려 사유 (#599 후속 운영 결정)', () => {
  /**
   * 반려를 고르면 사유 입력칸이 열리고, [일괄 적용] 이 그 사유를 확인 데이터로 넘기며, 확인 모달이
   * 들어갈 사유를 한 번 더 보여 준다 — 비워 보내면 구매자 화면의 반려 사유가 빈 칸이 된다.
   *
   * @effects bulk_rejection_reason_is_wired_through_confirm
   */
  it('반려 사유 입력칸 → [일괄 적용] → 확인 모달로 이어진다', () => {
    const input = byId(bulkSection, 'cb_006_reject_reason');
    const apply = (byId(bulkSection, 'cb_005_bulk_apply_button').actions as Node[])[0];
    const shown = byId(bulkConfirmModal, 'cbc_007_reject_reason_value');

    expect(input.if).toBe("{{(_local.bulkClaimStatus ?? '') === 'rejected'}}");
    expect(apply.params.rejectReason).toBe("{{_local.bulkRejectReason ?? ''}}");
    expect(shown.text).toBe("{{_global.claimBulkConfirmData?.rejectReason ?? ''}}");
  });
});

describe('목록 일괄 회수중·발송중 송장 (#599 후속 운영 결정)', () => {
  /**
   * [일괄 적용] 이 택배사 선택지를 넘기고, 확인 모달이 신청서마다 택배사·운송장 한 줄을 그리며, 한 줄이라도
   * 비면 [변경] 이 잠긴다 — 송장은 신청서마다 달라 한 쌍을 여러 건에 나눠 쓸 수 없다.
   *
   * @effects bulk_logistics_tracking_is_wired_through_confirm
   */
  it('택배사 선택지 → 신청서별 송장 줄 → 빈 줄이면 [변경] 잠금', () => {
    const apply = (byId(bulkSection, 'cb_005_bulk_apply_button').actions as Node[])[0];
    const rows = byId(bulkConfirmModal, 'cbc_008_trackings');
    const applyButton = byId(bulkConfirmModal, 'cbc_006_apply_button');
    const handlers = collect(rows, (n) => Array.isArray(n.actions))
      .flatMap((n) => (n.actions as Node[]).map((a) => a.handler));

    expect(apply.params.carriers).toBe('{{claims?.data?.carriers ?? []}}');
    expect(rows.if).toBe('{{(_global.claimBulkConfirmData?.trackings ?? []).length > 0}}');
    expect(handlers).toEqual(['sirsoft-ecommerce.updateClaimBulkTracking', 'sirsoft-ecommerce.updateClaimBulkTracking']);
    expect(String(applyButton.props.disabled)).toContain('trackings ?? []).some(');
  });
});

describe('목록 행 뱃지 · 운송장 열 (#599 후속 §3-9·§3-12)', () => {
  const grid = byId(datagrid, 'claim_datagrid');

  /**
   * 목록 컬럼 하나를 찾는다.
   *
   * @param field 컬럼 필드
   * @returns 컬럼 정의
   */
  const column = (field: string): Node => {
    const found = (grid.props.columns as Node[]).find((c) => c.field === field);

    if (!found) {
      throw new Error(`목록에 ${field} 컬럼이 없다`);
    }

    return found;
  };

  /**
   * 판매자 접수·선교환·회수 지연·환불 미접수·재활성(전체·일부)·환불 진행(보완 5) 일곱은 전부 서버가 행에 실어 준 값으로만 켠다 —
   * 화면이 상태·날짜로 다시 판정하면 서버 필터(`pending_refund=1`·회수 지연만)와 뱃지가 서로 다른 건을 가리킨다.
   * 회수 지연 일수·재활성 일시도 서버 값이다(재활성은 취소신청서에 기록한 값 — Q29).
   *
   * @effects claim_list_row_badges_read_server_flags, claim_list_cancel_row_shows_reactivated_badge_with_time
   */
  it('클레임번호 칸의 뱃지 일곱이 서버 행 플래그로만 켜진다', () => {
    const badges = collect(column('claim_number').cellChildren, (n) => n.name === 'Badge');

    expect(badges.map((b) => [b.if, b.props.variant, b.text])).toEqual([
      ['{{row.is_admin_requested === true}}', 'warning', '$t:defer:sirsoft-ecommerce.admin.claim.column.admin_requested_badge'],
      ['{{row.is_advance_exchange === true}}', 'info', '$t:defer:sirsoft-ecommerce.admin.claim.column.advance_badge'],
      [
        '{{row.is_collection_overdue === true}}',
        'danger',
        '$t:defer:sirsoft-ecommerce.admin.claim.column.overdue_badge|days={{row.collection_overdue_days ?? 0}}',
      ],
      ['{{row.has_pending_refund === true}}', 'warning', '$t:defer:sirsoft-ecommerce.admin.claim.column.pending_refund_badge'],
      // 환불 진행(보완 5) — 노출 조건은 감싼 링크(`!!row.refund_progress`)에 있고, 상태·라벨은 서버 값이다.
      [undefined, "{{row.refund_progress.status_variant ?? 'default'}}", "{{row.refund_progress.status_label ?? ''}}"],
      [
        '{{row.reactivated_at_formatted != null && row.is_partially_reactivated !== true}}',
        'info',
        '$t:defer:sirsoft-ecommerce.admin.claim.column.reactivated_badge|at={{row.reactivated_at_formatted}}',
      ],
      [
        '{{row.reactivated_at_formatted != null && row.is_partially_reactivated === true}}',
        'info',
        '$t:defer:sirsoft-ecommerce.admin.claim.column.reactivated_partial_badge|at={{row.reactivated_at_formatted}}',
      ],
    ]);
  });

  /**
   * 송장은 상품 행마다 있어 한 신청서에 운송장이 여럿일 수 있다 — 목록 칸은 방향별 첫 운송장과 나머지 개수
   * (`count - 1`)로 줄인다. 요약은 서버(`tracking_summary`)가 만든다. 두 방향 모두 없으면 `-` 한 줄이다.
   * 「외 N개」 는 운송장이 둘 이상일 때만 붙는다 — 하나뿐인 대부분의 건에 「외 0개」 가 서던 결함의 회귀 가드다.
   *
   * @effects claim_list_tracking_column_shows_first_waybill_and_more_count
   */
  it('운송장 열이 방향별 첫 운송장과 「외 N개」 를 서버 요약으로 그린다', () => {
    const lines = (column('tracking').cellChildren as Node[])[0].children as Node[];

    expect(lines.map((n) => n.if)).toEqual([
      '{{!!row.tracking_summary?.collection?.first}}',
      '{{!!row.tracking_summary?.redelivery?.first}}',
      '{{!row.tracking_summary?.collection?.first && !row.tracking_summary?.redelivery?.first}}',
    ]);

    (['collection', 'redelivery'] as const).forEach((direction, index) => {
      const [first, more] = lines[index].children as Node[];

      expect(first.text).toBe(
        `$t:defer:sirsoft-ecommerce.admin.claim.column.tracking_${direction}|number={{row.tracking_summary?.${direction}?.first ?? ''}}`,
      );
      expect(more.if).toBe(`{{(row.tracking_summary?.${direction}?.count ?? 0) > 1}}`);
      expect(more.text).toBe(
        `$t:defer:sirsoft-ecommerce.admin.claim.column.tracking_more|more={{(row.tracking_summary?.${direction}?.count ?? 0) - 1}}`,
      );
    });
    expect(lines[2].text).toBe('-');
    // 헤더 송장 필드를 읽지 않는다 — 신청서 헤더에는 송장 칸이 없다.
    expect(JSON.stringify(column('tracking'))).not.toMatch(/row\.(collection|redelivery)_tracking_number/);
  });
});

describe('빌드 CSS 에 있는 간격 클래스만 쓴다 (3차 검증 인터뷰 2026-09-26)', () => {
  /**
   * 관리자 템플릿 CSS 는 템플릿 자신의 레이아웃과 safelist 만 훑는다 — 모듈 레이아웃에만 있는 클래스는 만들어지지
   * 않아 조용히 무효가 된다(완료 창 `min-w-[12rem]`·목록 `gap-0.5` 가 그랬다). 클레임 완료 창·목록의 간격·최소 폭
   * 클래스가 빌드 CSS 에 실제로 있는지 잰다. 모집단은 파일에서 뽑는다.
   *
   * @effects claim_list_spacing_classes_exist_in_the_admin_build
   */
  it('완료 창·목록의 gap-*·min-w-* 클래스가 관리자 빌드 CSS 에 있다', () => {
    const here = path.dirname(fileURLToPath(import.meta.url));
    const css = fs.readFileSync(path.resolve(here, '../../../../../../../templates/_bundled/sirsoft-admin_basic/dist/css/components.css'), 'utf8');
    const dir = path.resolve(here, '../../../layouts/admin/partials/admin_ecommerce_claim_list');
    const classes = new Set<string>();

    for (const file of ['_modal_claim_complete.g7.html', '_partial_claim_datagrid.g7.html']) {
      const source = fs.readFileSync(path.join(dir, file), 'utf8');
      for (const [, list] of source.matchAll(/class="([^"]*)"/g)) {
        list.split(/\s+/).filter((c) => /^(gap|gap-x|gap-y|min-w)-/.test(c)).forEach((c) => classes.add(c));
      }
    }

    expect(classes.size).toBeGreaterThan(0);

    // CSS 선택자는 `.`·`[`·`]`·`/`·`:` 를 역슬래시로 이스케이프한다(`.min-w-\[200px\]`).
    const escape = (c: string) => '.' + c.replace(/([.[\]/:])/g, '\\$1');
    const missing = [...classes].filter((c) => !css.includes(escape(c)));

    expect(missing).toEqual([]);
  });
});

describe('필터 식별자 번호 (3차 검증 인터뷰 2026-09-26)', () => {
  /**
   * 필터 칸의 `data-ref` 번호(`f_NNN_…`)는 칸마다 하나다 — 같은 번호가 둘이면 레이아웃 확장·테스트가 번호로 칸을
   * 가리킬 때 어느 칸인지 갈린다(판정·접수 경로 필터를 넣으며 `f_600`·`f_610` 이 겹쳤다).
   *
   * @effects claim_filter_refs_have_unique_numbers
   */
  it('필터 칸의 f_NNN 번호가 겹치지 않는다', () => {
    const here = path.dirname(fileURLToPath(import.meta.url));
    const source = fs.readFileSync(path.resolve(here, '../../../layouts/admin/partials/admin_ecommerce_claim_list/_partial_filter_section.g7.html'), 'utf8');
    const numbers = [...source.matchAll(/data-ref="f_(\d+)_/g)].map((m) => m[1]);

    expect(numbers.length).toBeGreaterThan(0);
    expect(numbers.filter((n, i) => numbers.indexOf(n) !== i)).toEqual([]);
  });
});
