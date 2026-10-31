/**
 * @file adminClaimItemTracking.test.ts
 * @description 관리자 클레임 상세 — 상품 행 송장·선교환·환불 미접수 화면 구조 계약 (#599 후속 §3-9·§3-12)
 *
 * 송장은 신청서 헤더가 아니라 **상품 행**에 붙는다. 화면에서 조용히 갈리는 지점을 구조로 못박는다:
 *
 *  1. **[송장만 저장] 이 교환·반품에만 서고 고른 행·방향·송장을 핸들러로 넘기는가** — 환불에 서면
 *     물건이 오가지 않는 신청서에 송장 칸이 열린다.
 *  2. **송장 입력 줄이 회수중·발송중 또는 「송장만 저장」 모드에서만 열리는가**, 방향 선택은 교환의
 *     「송장만 저장」 에서만 서는가(반품은 회수뿐).
 *  3. **상품 표의 송장 열이 행 필드를 방향별로 읽는가** — 헤더 필드를 읽으면 행마다 다른 송장이 한 값으로 덮인다.
 *  4. **배송 정보 탭이 운송장 묶음을 방향별로 그리고 [도착 처리] 가 서버 판정(`can_deliver`)을 따르는가**,
 *     반품은 회수 묶음만 그리는가.
 *  5. **선교환 지연 배너·진행 방식 줄·환불 미접수 배너·미회수 입력칸이 서버가 준 값으로만 분기하는가.**
 *
 * @vitest-environment node
 */

import { describe, expect, it } from 'vitest';

import shell from '../../../layouts/admin/admin_ecommerce_claim_detail.json';
import itemsSection from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_items.json';
import shippingSection from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_shipping.json';
import completeModal from '../../../layouts/admin/partials/admin_ecommerce_claim_list/_modal_claim_complete.json';

type Node = Record<string, any>;

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
 * 노드의 첫 액션 중 지정 핸들러를 찾는다.
 *
 * @param node 노드
 * @param handler 핸들러 이름
 * @returns 액션
 */
function actionOf(node: Node, handler: string): Node {
  const found = ((node.actions ?? []) as Node[]).find((a) => a.handler === handler);

  if (!found) {
    throw new Error(`노드 #${node.id ?? node.name} 에 ${handler} 액션이 없다`);
  }

  return found;
}

/**
 * 서브트리 안의 모든 문자열 값을 모은다.
 *
 * @param node 시작 노드
 * @returns 문자열 배열
 */
function strings(node: unknown): string[] {
  const out: string[] = [];
  const visit = (value: unknown): void => {
    if (typeof value === 'string') {
      out.push(value);
    } else if (Array.isArray(value)) {
      value.forEach(visit);
    } else if (value && typeof value === 'object') {
      Object.values(value).forEach(visit);
    }
  };
  visit(node);

  return out;
}

/**
 * 신청 상품 표의 컬럼 하나를 찾는다.
 *
 * @param field 컬럼 필드
 * @returns 컬럼 정의
 */
function itemColumn(field: string): Node {
  const grid = byId(itemsSection, 'claim_items_datagrid');
  const column = (grid.props.columns as Node[]).find((c) => c.field === field);

  if (!column) {
    throw new Error(`신청 상품 표에 ${field} 컬럼이 없다`);
  }

  return column;
}

describe('신청 상품 선택 바 — [송장만 저장] (R7)', () => {
  /**
   * 송장은 교환·반품에만 있다 — 환불·취소에는 물건이 오가지 않는다. 버튼은 선택 행이 없거나 처리 중이거나
   * 상태 변경 권한이 없으면 잠긴다. 핸들러는 고른 행·방향(교환만 선택, 반품은 회수 고정)·택배사·운송장·
   * 행 목록(공통 송장 미리 채움·수량 모달용)을 함께 받는다.
   *
   * @effects claim_item_tracking_only_button_is_wired_to_the_rows
   */
  it('[송장만 저장] 이 교환·반품에만 서고 고른 행·방향·송장을 넘긴다', () => {
    const button = byId(itemsSection, 'ci_009_tracking_only');
    const action = actionOf(button, 'sirsoft-ecommerce.openClaimItemTrackingOnly');

    expect(button.if).toBe("{{['exchange', 'return'].includes(claim?.data?.claim?.claim_type ?? '')}}");
    expect(String(button.props.disabled)).toBe(
      "{{_local.isUpdatingClaimItems === true || (_local.selectedClaimItems ?? []).length === 0 || claim?.data?.abilities?.can_update_status !== true}}",
    );
    expect(action.params).toMatchObject({
      pathSegment: "{{claim?.data?.claim_path_segment ?? ''}}",
      claimType: "{{claim?.data?.claim?.claim_type ?? ''}}",
      claimId: '{{claim?.data?.claim?.id ?? null}}',
      itemIds: '{{_local.selectedClaimItems ?? []}}',
      trackingMode: '{{_local.itemTrackingMode === true}}',
      direction: "{{(claim?.data?.claim?.claim_type ?? '') === 'exchange' ? (_local.itemTrackingDirection ?? 'collection') : 'collection'}}",
      carrierId: "{{_local.itemCarrierId ?? ''}}",
      trackingNumber: "{{_local.itemTrackingNumber ?? ''}}",
      carriers: '{{claim?.data?.carriers ?? []}}',
      rows: '{{claim?.data?.claim?.items ?? []}}',
    });
    // 경로 세그먼트를 화면이 조립하지 않는다(`claim_type + 's'` 금지).
    expect(strings(action.params).some((v) => v.includes("+ 's'"))).toBe(false);
    // 실패 알림은 핸들러가 한 번만 띄운다.
    expect(action.onError?.handler).toBe('suppress');
  });

  /**
   * 입력 줄은 택배가 물건을 싣고 가는 두 상태(회수중·발송중)이거나 「송장만 저장」 모드일 때만 열린다.
   * 방향 선택은 교환의 「송장만 저장」 에서만 선다 — 전이에서는 상태가 방향을 정하고(회수중=회수,
   * 발송중=재발송), 반품은 회수뿐이다. 방향을 바꾸면 핸들러가 그 방향의 공통 송장을 다시 미리 채운다.
   *
   * @effects claim_item_tracking_row_opens_for_logistics_status_or_tracking_mode, claim_item_tracking_direction_is_exchange_tracking_mode_only
   */
  it('송장 입력 줄은 회수중·발송중·「송장만 저장」 에서 열리고 방향 선택은 교환 전용이다', () => {
    const row = byId(itemsSection, 'ci_006_tracking_row');
    const direction = byId(itemsSection, 'ci_010_direction');
    const change = actionOf(direction, 'sirsoft-ecommerce.selectClaimItemTrackingDirection');

    expect(row.if).toBe(
      "{{['collecting', 'redelivering'].includes(_local.itemBulkStatus ?? '') || _local.itemTrackingMode === true}}",
    );
    // 방향 선택·택배사·운송장 셋이 한 줄 안에 있다 — 줄이 닫히면 셋이 함께 닫힌다.
    expect(collect(row, (n) => typeof n.id === 'string').map((n) => n.id)).toEqual([
      'ci_006_tracking_row',
      'ci_010_direction',
      'ci_007_carrier',
      'ci_008_tracking_number',
    ]);
    expect(direction.if).toBe(
      "{{_local.itemTrackingMode === true && (claim?.data?.claim?.claim_type ?? '') === 'exchange'}}",
    );
    expect(direction.props.value).toBe("{{_local.itemTrackingDirection ?? 'collection'}}");
    expect(String(direction.props.options)).toContain("value: 'collection'");
    expect(String(direction.props.options)).toContain("value: 'redelivery'");
    expect(change.params).toEqual({
      direction: '{{$event.target.value}}',
      rows: '{{claim?.data?.claim?.items ?? []}}',
    });
  });
});

describe('신청 상품 표 — 송장 열 (행 송장)', () => {
  /**
   * 송장은 행마다 다르다 — 셀은 `row.*` 행 필드만 읽고 신청서 헤더(`claim.data.claim.*_tracking_*`)를 읽지
   * 않는다. 회수 줄은 행에 회수 필드가 있으면(교환·반품) 서고, 재발송 줄은 행에 재발송 필드가 있을 때만
   * (교환만 — 서버가 반품 행에는 재발송 키를 싣지 않는다) 선다. 조회 링크는 서버가 만든 URL 만 쓴다.
   *
   * @effects claim_items_table_tracking_column_reads_row_fields_per_direction
   */
  it('송장 열이 방향별 행 필드와 서버 조회 URL 만 읽는다', () => {
    const column = itemColumn('tracking');
    const [wrapper] = column.cellChildren as Node[];
    const [collectionLine, redeliveryLine] = wrapper.children as Node[];
    const cellStrings = strings(column.cellChildren);

    expect(column.header).toBe('$t:sirsoft-ecommerce.admin.claim.detail.items.columns.tracking');
    expect(collectionLine.if).toBe('{{row.collection_tracking_number !== undefined}}');
    expect(redeliveryLine.if).toBe('{{row.redelivery_tracking_number !== undefined}}');

    const collectionLink = collect(collectionLine, (n) => n.name === 'A')[0];
    const redeliveryLink = collect(redeliveryLine, (n) => n.name === 'A')[0];
    expect(collectionLink.if).toBe('{{!!row.collection_tracking_url}}');
    expect(collectionLink.props.href).toBe('{{row.collection_tracking_url}}');
    expect(redeliveryLink.props.href).toBe('{{row.redelivery_tracking_url}}');

    expect(cellStrings).toContain("{{(row.collection_carrier_name ?? '') + ' ' + row.collection_tracking_number}}");
    expect(cellStrings).toContain("{{(row.redelivery_carrier_name ?? '') + ' ' + row.redelivery_tracking_number}}");
    // 헤더 송장 필드를 읽지 않는다.
    expect(cellStrings.some((v) => /claim\.data\.claim\??\.?(collection|redelivery)_tracking/.test(v))).toBe(false);
    expect(cellStrings.some((v) => v.includes('claim?.data?.claim?.collection_tracking'))).toBe(false);
  });

  /**
   * 선교환 회수 진행 뱃지는 선교환일 때만 선다(일반 교환은 상태 뱃지가 이미 그 정보를 말한다). 미회수 뱃지는
   * 서버 불리언(`is_unreturned`)으로만 켠다.
   *
   * @effects claim_items_table_marks_advance_collection_state_and_unreturned
   */
  it('선교환 회수 진행 뱃지와 미회수 뱃지가 서버 값으로 켜진다', () => {
    const trackingBadges = collect(itemColumn('tracking').cellChildren, (n) => n.name === 'Badge');
    const statusBadges = collect(itemColumn('status').cellChildren, (n) => n.name === 'Badge');

    expect(trackingBadges.map((b) => b.if)).toEqual([
      '{{claim?.data?.claim?.is_advance_exchange === true && !!row.collection_state_label}}',
    ]);
    expect(trackingBadges[0].props.variant).toBe("{{row.collection_state_variant ?? 'secondary'}}");
    expect(statusBadges.map((b) => b.if)).toContain('{{row.is_unreturned === true}}');
  });
});

describe('배송 정보 탭 — 운송장 묶음 (R9)', () => {
  /**
   * 방향마다 운송장 묶음(`tracking_groups.{direction}`)을 반복으로 그린다 — 묶음이 없으면 「아직 송장이
   * 없습니다」 한 줄이 그 자리를 지킨다. 묶음 줄은 택배사·운송장·서버 조회 URL·담긴 상품 × 수량을 그린다.
   * 이 탭에는 입력칸이 없다(정정은 상품 칸의 [송장만 저장]).
   *
   * @effects claim_shipping_tab_renders_waybill_groups_per_direction
   */
  it('회수·재발송이 각자의 묶음 배열을 반복하고 비면 안내가 선다', () => {
    const collectionGroup = byId(shippingSection, 'cs_006_collection_group_{{groupIdx}}');
    const redeliveryGroup = byId(shippingSection, 'cs_015_redelivery_group_{{groupIdx}}');

    expect(collectionGroup.iteration).toEqual({
      source: '{{claim?.data?.claim?.tracking_groups?.collection ?? []}}',
      item_var: 'group',
      index_var: 'groupIdx',
    });
    expect(redeliveryGroup.iteration).toEqual({
      source: '{{claim?.data?.claim?.tracking_groups?.redelivery ?? []}}',
      item_var: 'group',
      index_var: 'groupIdx',
    });
    expect(byId(shippingSection, 'cs_005_collection_empty').if).toBe(
      '{{(claim?.data?.claim?.tracking_groups?.collection ?? []).length === 0}}',
    );
    expect(byId(shippingSection, 'cs_014_redelivery_empty').if).toBe(
      '{{(claim?.data?.claim?.tracking_groups?.redelivery ?? []).length === 0}}',
    );

    for (const group of [collectionGroup, redeliveryGroup]) {
      const link = collect(group, (n) => n.name === 'A')[0];
      const itemLines = collect(group, (n) => n.iteration?.item_var === 'gItem');

      expect(link.if).toBe('{{!!group.tracking_url}}');
      expect(link.props.href).toBe('{{group.tracking_url}}');
      expect(itemLines).toHaveLength(1);
      expect(itemLines[0].iteration.source).toBe('{{group.items ?? []}}');
      expect(strings(itemLines[0])).toContain(
        "{{(gItem.product_name ?? '') + (gItem.option_name ? ' (' + gItem.option_name + ')' : '') + ' × ' + (gItem.quantity ?? 0)}}",
      );
      // 송장 없이 도착한 묶음은 뱃지로 구분하고 [도착 처리] 를 두지 않는다.
      expect(collect(group, (n) => n.if === '{{group.no_tracking === true}}')).toHaveLength(1);
    }

    expect(collect(shippingSection, (n) => n.name === 'Select' || n.name === 'Input')).toEqual([]);
  });

  /**
   * [도착 처리] 는 묶음마다 하나이며, 켜짐은 서버 판정(`group.can_deliver`)과 상태 변경 권한·진행 중 잠금을
   * 함께 따른다. 막힌 이유는 버튼 옆 문구다. 핸들러는 방향과 그 묶음의 (택배사, 운송장)을 받는다.
   *
   * @effects claim_shipping_deliver_button_follows_can_deliver
   */
  it('[도착 처리] 가 `group.can_deliver` 로 잠기고 방향·운송장을 넘긴다', () => {
    const buttons = collect(shippingSection, (n) => Array.isArray(n.actions)
      && (n.actions as Node[]).some((a) => a.handler === 'sirsoft-ecommerce.deliverClaimTracking'));

    expect(buttons).toHaveLength(2);
    expect(buttons.map((b) => actionOf(b, 'sirsoft-ecommerce.deliverClaimTracking').params.direction)).toEqual([
      'collection',
      'redelivery',
    ]);

    for (const button of buttons) {
      const action = actionOf(button, 'sirsoft-ecommerce.deliverClaimTracking');

      expect(button.props.disabled).toBe(
        '{{claim?.data?.abilities?.can_update_status !== true || group.can_deliver !== true || _local.isDeliveringTracking === true}}',
      );
      expect(action.params.carrierId).toBe('{{group.carrier_id}}');
      expect(action.params.trackingNumber).toBe('{{group.tracking_number}}');
      expect(action.params.pathSegment).toBe("{{claim?.data?.claim_path_segment ?? ''}}");
      expect(action.onError?.handler).toBe('suppress');
    }

    expect(collect(shippingSection, (n) => n.if === '{{group.can_deliver !== true && !!group.blocked_reason}}')).toHaveLength(2);
  });

  /**
   * 반품에는 재발송이 없다 — 재발송 묶음 블록은 교환에서만 서고, 반품은 회수 묶음만 그린 채 재발송 자리에
   * '해당 없음' 을 남긴다(통째로 빼면 '아직 없음' 과 구분되지 않는다).
   *
   * @effects claim_shipping_return_renders_collection_only
   */
  it('반품은 회수 묶음만 그리고 재발송은 해당 없음으로 남긴다', () => {
    const collectionBlock = byId(shippingSection, 'claim_collection_block');
    const redeliveryBlock = byId(shippingSection, 'claim_redelivery_block');
    const notApplicable = byId(shippingSection, 'claim_redelivery_not_applicable');

    // 회수 블록은 조건 없이 선다(섹션 자체가 교환·반품 전용).
    expect(collectionBlock.if).toBeUndefined();
    expect(redeliveryBlock.if).toBe("{{(claim?.data?.claim?.claim_type ?? '') === 'exchange'}}");
    expect(notApplicable.if).toBe("{{(claim?.data?.claim?.claim_type ?? '') !== 'exchange'}}");
    // 재발송 묶음 반복은 교환 전용 블록 안에만 있다.
    expect(collect(redeliveryBlock, (n) => n.id === 'cs_015_redelivery_group_{{groupIdx}}')).toHaveLength(1);
    expect(collect(collectionBlock, (n) => String(n.iteration?.source ?? '').includes('redelivery'))).toEqual([]);
  });
});

describe('상세 헤더 — 선교환 지연 배너 · 진행 방식 줄', () => {
  /**
   * 반송 기한 경과는 서버가 센다(`is_collection_overdue`·`collection_overdue_days`·`collection_due_date_formatted`) —
   * 화면이 날짜를 비교하면 보는 사람 타임존과 서버 판정이 갈린다.
   *
   * @effects advance_overdue_banner_reads_the_server_flag
   */
  it('지연 배너가 서버 판정 하나로 켜지고 일수·기한을 서버 값으로 그린다', () => {
    const banner = byId(shell, 'cd_013_overdue_banner');

    expect(banner.if).toBe('{{claim?.data?.claim?.is_collection_overdue === true}}');
    expect(banner.text).toBe(
      "$t:sirsoft-ecommerce.admin.claim.detail.exchange_mode.overdue_banner|count={{claim?.data?.claim?.collection_overdue_days ?? 0}}|days={{claim?.data?.claim?.collection_overdue_days ?? 0}}|date={{claim?.data?.claim?.collection_due_date_formatted ?? ''}}",
    );
  });

  /**
   * 진행 방식 줄은 교환이고 서버가 전환 능력(`exchange_mode_abilities`)을 실어 줄 때만 선다. 뱃지는 서버 라벨을
   * 그리고, 두 전환 버튼은 현재 방식에 따라 하나만 선다.
   *
   * @effects advance_exchange_mode_row_is_exchange_only
   */
  it('진행 방식 줄이 교환 전용이고 버튼은 현재 방식의 반대만 선다', () => {
    const row = byId(shell, 'cd_014_exchange_mode_row');
    const badge = byId(row, 'cd_015_exchange_mode_badge');
    const setAdvance = byId(row, 'cd_016_set_advance');
    const setStandard = byId(row, 'cd_017_set_standard');

    expect(row.if).toBe(
      "{{(claim?.data?.claim?.claim_type ?? '') === 'exchange' && !!claim?.data?.exchange_mode_abilities}}",
    );
    expect(badge.text).toBe("{{claim?.data?.claim?.exchange_mode_label ?? ''}}");
    expect(setAdvance.if).toBe('{{claim?.data?.claim?.is_advance_exchange !== true}}');
    expect(setStandard.if).toBe('{{claim?.data?.claim?.is_advance_exchange === true}}');
    expect(actionOf(setAdvance, 'sirsoft-ecommerce.openClaimExchangeModeConfirm').params.mode).toBe('advance');
    expect(actionOf(setStandard, 'sirsoft-ecommerce.openClaimExchangeModeConfirm').params.mode).toBe('standard');
    expect(String(setStandard.props.disabled)).toContain('exchange_mode_abilities?.can_set_standard !== true');
  });
});

describe('상세 헤더 — 환불 미접수 배너 (D9)', () => {
  /**
   * 완료됐는데 환불신청서가 없고 환불액이 남은 건은 서버가 `pending_refund` 로 알린다. 배너는 그 값 하나로
   * 켜지고 금액은 서버 표기를 그대로 쓰며, 버튼은 기존 수동 접수 핸들러를 부른다.
   *
   * @effects pending_refund_banner_reads_the_detail_envelope
   */
  it('환불 미접수 배너가 `pending_refund` 로 켜지고 기존 접수 핸들러를 부른다', () => {
    const banner = byId(shell, 'cd_012_pending_refund_banner');
    const button = collect(banner, (n) => n.name === 'Button')[0];
    const action = actionOf(button, 'sirsoft-ecommerce.storeClaimRefundRequest');

    expect(banner.if).toBe('{{!!claim?.data?.pending_refund}}');
    expect(strings(banner)).toContain(
      "$t:sirsoft-ecommerce.admin.claim.detail.pending_refund.banner|amount={{claim?.data?.pending_refund?.amount_formatted ?? ''}}",
    );
    expect(button.props.disabled).toBe('{{claim?.data?.abilities?.can_update_status !== true}}');
    expect(action.params).toEqual({
      pathSegment: "{{claim?.data?.claim_path_segment ?? ''}}",
      claimId: '{{claim?.data?.claim?.id ?? null}}',
    });
    expect(action.onError?.handler).toBe('suppress');
  });
});

describe('완료 처리 모달 — 미회수로 완료 (D3)', () => {
  /**
   * 미회수 후보는 미리보기(`unreturned_eligible_items`)가 준다 — 후보가 한 건도 없으면 절이 통째로 사라진다.
   * 사유 입력칸은 그 행을 체크했을 때만 열린다(사유 필수). 체크박스는 자동바인딩을 끈다.
   *
   * @effects advance_unreturned_section_follows_preview_candidates
   */
  it('미회수 절이 미리보기 후보로만 서고 체크한 행에만 사유 칸이 열린다', () => {
    const section = byId(completeModal, 'cc_023_unreturned_section');
    const rows = collect(section, (n) => n.iteration?.item_var === 'unretItem');
    const checkbox = collect(section, (n) => n.name === 'Checkbox')[0];
    const reason = collect(section, (n) => n.name === 'Input')[0];

    expect(section.if).toBe(
      '{{(_global.claimCompletePreview?.claims ?? []).some(c => (c.unreturned_eligible_items ?? []).length > 0)}}',
    );
    expect(rows).toHaveLength(1);
    expect(rows[0].iteration.source).toBe(
      '{{(_global.claimCompletePreview?.claims ?? []).flatMap(c => c.unreturned_eligible_items ?? [])}}',
    );
    expect(checkbox.props.autoBinding).toBe(false);
    expect(actionOf(checkbox, 'sirsoft-ecommerce.toggleClaimCompleteUnreturned').params).toEqual({
      itemId: '{{unretItem.id}}',
      checked: '{{$event.target.checked}}',
    });
    expect(reason.if).toBe(
      '{{Object.keys(_global.claimCompleteUnreturned ?? {}).includes(String(unretItem.id))}}',
    );
    expect(actionOf(reason, 'sirsoft-ecommerce.setClaimCompleteUnreturnedReason').params.itemId).toBe('{{unretItem.id}}');
  });
});
