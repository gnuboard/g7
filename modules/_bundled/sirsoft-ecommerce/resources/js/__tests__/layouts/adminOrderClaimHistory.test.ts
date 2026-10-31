/**
 * @file adminOrderClaimHistory.test.ts
 * @description 관리자 주문상세 '클레임 내역' 섹션 — 구조 계약 (S9c · D20)
 *
 * 이 섹션에서 조용히 깨지는 축을 구조로 못박는다:
 *
 *  1. **조회 전용인가** (D20). 상태 변경 select·송장 input·저장 버튼이 하나라도 들어오면
 *     같은 값을 바꾸는 지점이 두 곳이 되고, 그 사본은 상세(S9a·S9b)가 규칙을 바꿔도
 *     따라오지 않는다. 오류는 나지 않는다 — 두 화면의 결과만 갈린다.
 *  2. **1열 병합이 상품 수와 맞는가**. `rowSpan` 이 그 신청서의 상품 수가 아니거나 첫 행
 *     분기가 없으면 표가 오류 없이 어긋난 채 그려진다.
 *  3. **신청번호 링크가 서버 경로를 그대로 쓰는가**. 화면이 `/admin/ecommerce/
 *     claims/` + 번호로 조립하면 경로 규칙의 두 번째 출처가 되고, 규칙이 바뀌어도 오류 없이
 *     404 만 나는 URL 이 만들어진다. 번호 자체가 새 창 링크이고 네 유형 모두 상세가 있다
 *     (취소도 조회 전용 상세) — 서버가 경로를 못 만든 행만 글자로 남는다.
 *  4. **서브탭이 재조회하지 않는가** (D20 — "별도 데이터소스를 만들지 않는다"). 서버가 전
 *     유형을 한 응답에 싣는 것이 전제다.
 *  5. **뱃지 숫자가 서버 `counts` 인가**. 화면이 `claims` 배열을 다시 세면 상한이 걸린
 *     응답에서 뱃지와 목록 건수가 갈린다.
 *  6. **금액·경로를 화면이 조립하지 않는가** — 서버가 준 `*_formatted` / `detail_url` 만 그린다.
 *  7. **탭 배열과 섹션 존재가 함께 있는가** (D17-①). 탭만 남기면 클릭이 무반응이고, 섹션만
 *     남기면 scrollSpy 가 없는 탭 id 를 활성화하려다 무시된다.
 *  8. **모바일 카드가 PC 병합셀과 같은 정보인가** (D20). 한쪽만 고치면 같은 신청서가 화면
 *     크기에 따라 다른 정보를 보인다.
 *  9. **삭제된 정적 목업 3탭이 실제로 사라졌는가** — 파일과 그 어휘가 함께 없어야 한다.
 * 10. **다국어 키가 ko/en 양쪽에 실재하는가** — 없으면 화면에 원문 키가 그대로 나온다.
 *
 * @vitest-environment node
 */

import { initStateOf } from '@core/template-engine/__tests__/utils/layoutShapeUtils';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import shell from '../../../layouts/admin/admin_ecommerce_order_detail.json';
import claimHistory from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_claim_history.json';
import paymentInfo from '../../../layouts/admin/partials/admin_ecommerce_order_detail/_partial_payment_info.json';
import koOrder from '../../../lang/partial/ko/admin/order.json';
import enOrder from '../../../lang/partial/en/admin/order.json';

const MODULE_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');
const ORDER_DETAIL_DIR = path.join(
  MODULE_ROOT,
  'resources/layouts/admin/partials/admin_ecommerce_order_detail'
);

type Node = Record<string, any>;

/** D20 이 흡수한 정적 목업 3탭 — 파일이 남아 있으면 어휘가 두 벌이 된다 */
const ORPHAN_MOCK_TABS = ['_tab_claim_refund.json', '_tab_claim_return.json', '_tab_claim_exchange.json'];

/** 유형 서브탭 어휘 (ClaimTypeEnum 값) */
const CLAIM_TYPES = ['exchange', 'return', 'cancel', 'refund'];

/** 기본 서브탭 판정식 — 접수 건이 있는 첫 유형, 없으면 교환 */
const DEFAULT_TYPE = "(['exchange', 'return', 'cancel', 'refund'].find(t => (orderClaims?.data?.counts?.[t] ?? 0) > 0) ?? 'exchange')";

/**
 * 서브트리의 모든 노드를 훑는다. (children 뿐 아니라 props/responsive 안쪽까지)
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
    if (match(n)) found.push(n);
  });

  return found;
}

/**
 * id 로 노드 하나를 찾는다.
 *
 * @param node 시작 노드
 * @param id 노드 id
 * @returns 찾은 노드 (없으면 undefined)
 */
function byId(node: unknown, id: string): Node | undefined {
  return collect(node, (n) => n.id === id)[0];
}

/**
 * 다국어 팩에서 점 경로 값을 읽는다.
 *
 * @param pack 언어 팩 객체
 * @param dottedPath 점 경로
 * @returns 값 (없으면 undefined)
 */
function langValue(pack: Node, dottedPath: string): unknown {
  return dottedPath.split('.').reduce<any>((acc, key) => (acc && typeof acc === 'object' ? acc[key] : undefined), pack);
}

/** PC 표의 병합 셀 (신청서 단위) */
const mergedCell = byId(claimHistory, 'claim_history_number_cell_{{claim.claim_number}}');
/** 모바일 카드의 헤더 (같은 정보를 다르게 그린 자리) */
const mobileCardHeader = byId(claimHistory, 'claim_history_card_header_{{claim.claim_number}}');
/** PC/모바일을 가르는 래퍼 */
const body = byId(claimHistory, 'claim_history_body');

describe('S9c — 주문상세 클레임 내역 섹션 구조 계약 (D20)', () => {
  describe('탭 배열과 섹션 존재 (D17-①)', () => {
    /**
     * @effects order_claim_history_tab_and_section_are_paired
     */
    it('셸의 탭 배열에 claim_history 가 있고 그 id 로 스크롤할 섹션이 실재한다', () => {
      const tabs = (shell as Node).computed?.orderDetailTabs;

      expect(typeof tabs).toBe('string');
      expect(tabs).toContain("id: 'claim_history'");

      // scrollSpy 는 `sectionIdPrefix + 탭 id` 로 섹션을 찾는다
      expect((claimHistory as Node).id).toBe('section_claim_history');
      expect((claimHistory as Node).props?.['data-section-id']).toBe('claim_history');
    });

    /**
     * @effects order_claim_history_partial_wired_between_payment_and_activity_log
     */
    it('셸이 그 섹션 partial 을 결제 정보와 활동 로그 사이에 배선한다', () => {
      const partials = collect(shell, (n) => typeof n.partial === 'string').map((n) => n.partial as string);
      const order = partials.filter((p) => p.includes('admin_ecommerce_order_detail/_partial_'));

      expect(order).toEqual([
        'partials/admin_ecommerce_order_detail/_partial_order_progress.json',
        'partials/admin_ecommerce_order_detail/_partial_order_info.json',
        'partials/admin_ecommerce_order_detail/_partial_payment_info.json',
        'partials/admin_ecommerce_order_detail/_partial_claim_history.json',
        'partials/admin_ecommerce_order_detail/_partial_activity_log.json',
      ]);
    });

    /**
     * @effects order_claim_history_tab_badge_counts_requests
     */
    it('탭 뱃지 숫자는 응답의 신청서 수를 읽고, 0건이면 숫자를 붙이지 않는다', () => {
      const tabs = String((shell as Node).computed?.orderDetailTabs);

      // 건수는 서버 `counts` 의 합이다 — 서브탭 뱃지와 같은 출처(P7 10-#7). 배열 길이를 세면
      // 상한이 걸린 응답에서 탭 라벨과 서브탭 숫자가 갈린다.
      expect(tabs).toContain('_computed.claimHistoryTotal');
      expect(tabs).toMatch(/claimHistoryTotal \?\? 0\) > 0 \?/);
      expect(String((shell as Node).computed?.claimHistoryTotal)).toContain('orderClaims?.data?.counts');
    });
  });

  describe('조회 전용 (D20)', () => {
    /**
     * @effects order_claim_history_is_read_only
     */
    it('입력 요소가 하나도 없다', () => {
      const inputs = collect(
        claimHistory,
        (n) => typeof n.name === 'string'
          && ['Input', 'Select', 'Textarea', 'Checkbox', 'FileInput', 'RadioGroup', 'Radio'].includes(n.name)
      );

      expect(inputs.map((n) => n.id ?? n.name)).toEqual([]);
    });

    /**
     * @effects order_claim_history_has_no_write_actions
     */
    it('서버로 쓰기를 보내는 액션이 없다 — 버튼은 서브탭 전환(setState)뿐이다', () => {
      const handlers = collect(claimHistory, (n) => typeof n.handler === 'string').map((n) => n.handler as string);

      expect([...new Set(handlers)].sort()).toEqual(['setState']);
    });

    /**
     * @effects order_claim_history_does_not_copy_detail_controls
     */
    it('상세가 소유한 조작 어휘를 이 섹션이 복제하지 않는다', () => {
      const source = JSON.stringify(claimHistory);

      for (const forbidden of [
        'update-status',
        'tracking',
        'amounts',
        'refund-request',
        'completion-preview',
        'saveClaim',
        'executeClaimRefund',
      ]) {
        expect(source).not.toContain(forbidden);
      }
    });
  });

  describe('1열 신청서 단위 rowspan 병합', () => {
    /**
     * @effects order_claim_history_groups_by_request
     */
    it('바깥 반복은 신청서, 안쪽 반복은 그 신청서의 상품이다', () => {
      const group = byId(claimHistory, 'claim_history_group_{{claim.claim_number}}');

      expect(group?.name).toBe('Tbody');
      expect(group?.iteration).toEqual({
        source: '{{_computed.claimHistoryRows ?? []}}',
        item_var: 'claim',
      });

      const row = byId(claimHistory, 'claim_history_row_{{claim.claim_number}}_{{itemIndex}}');

      expect(row?.name).toBe('Tr');
      expect(row?.iteration).toEqual({
        source: '{{claim.items ?? []}}',
        item_var: 'item',
        index_var: 'itemIndex',
      });
    });

    /**
     * @effects order_claim_history_merged_cell_spans_item_count
     */
    it('병합 셀은 첫 행에만 렌더되고 rowSpan 은 그 신청서의 상품 수다', () => {
      expect(mergedCell?.name).toBe('Td');
      expect(mergedCell?.if).toBe('{{itemIndex === 0}}');
      expect(mergedCell?.props?.rowSpan).toBe('{{(claim.items ?? []).length}}');
    });

    /**
     * @effects order_claim_history_request_level_amounts_stay_in_merged_cell
     */
    it('신청서 단위 금액(배송비·총 청구액)은 상품 행에 안분하지 않고 병합 셀이 그린다 (S6c 판정 ②)', () => {
      const merged = JSON.stringify(mergedCell);

      expect(merged).toContain('claim.claim_shipping_fee_formatted');
      expect(merged).toContain('claim.total_charge_amount_formatted');

      // 상품 행(금액 셀)은 그 상품의 정규화 금액만 읽는다
      const amountCell = byId(claimHistory, 'claim_history_amount_cell_{{claim.claim_number}}_{{itemIndex}}');
      const amount = JSON.stringify(amountCell);

      expect(amount).toContain('item.amount_formatted');
      expect(amount).not.toContain('claim_shipping_fee');
      expect(amount).not.toContain('total_charge_amount');
    });

    /**
     * @effects order_claim_history_amount_direction_from_amount_type
     */
    it('금액의 방향은 amount_type 이 단독으로 나른다 — 화면이 유형으로 분기해 부호를 붙이지 않는다', () => {
      const amountCell = byId(claimHistory, 'claim_history_amount_cell_{{claim.claim_number}}_{{itemIndex}}');
      const variants = collect(amountCell, (n) => !!n.classMap)[0]?.classMap;

      expect(variants?.key).toBe("{{item.amount_type ?? 'none'}}");
      expect(Object.keys(variants?.variants ?? {}).sort()).toEqual(['charge', 'none', 'refund']);

      // 부호를 다시 붙이면 저가 교환에서 이중 부호가 된다
      expect(JSON.stringify(amountCell)).not.toContain("'-' +");
      expect(JSON.stringify(amountCell)).not.toContain('claim_type');
    });
  });

  describe('신청번호 링크', () => {
    /**
     * @effects order_claim_history_number_is_the_link, order_claim_history_detail_link_opens_new_window, order_claim_history_detail_link_uses_server_url
     */
    it('번호 자체가 새 창 링크이고 경로는 서버가 준 detail_url 을 그대로 쓴다 (PC 병합셀 · 모바일 카드)', () => {
      for (const id of [
        'claim_history_number_link_{{claim.claim_number}}',
        'claim_history_card_number_link_{{claim.claim_number}}',
      ]) {
        const link = byId(claimHistory, id);

        expect(link?.name).toBe('A');
        expect(link?.props?.target).toBe('_blank');
        expect(link?.props?.href).toBe('{{claim.detail_url}}');
        expect(link?.if).toBe('{{!!claim.detail_url}}');
        expect(JSON.stringify(link)).toContain("{{claim.claim_number ?? ''}}");
      }
    });

    /**
     * @effects order_claim_history_number_links_for_every_type
     */
    it('별도의 [조회하기] 버튼이 없고, 서버가 경로를 못 만든 행만 글자로 남는다 (취소만 빼던 분기가 없다)', () => {
      expect(byId(claimHistory, 'claim_history_detail_link_{{claim.claim_number}}')).toBeUndefined();
      expect(byId(claimHistory, 'claim_history_card_detail_link_{{claim.claim_number}}')).toBeUndefined();

      for (const id of [
        'claim_history_number_text_{{claim.claim_number}}',
        'claim_history_card_number_text_{{claim.claim_number}}',
      ]) {
        expect(byId(claimHistory, id)?.if).toBe('{{!claim.detail_url}}');
      }

      expect(JSON.stringify(claimHistory)).not.toContain("claim_type === 'cancel'");
    });

    /**
     * @effects order_claim_history_thumbnail_placeholder_when_missing
     */
    it('상품 썸네일 자리는 이미지가 없어도 남는다 — Img 와 자리표시 아이콘이 반대 조건으로 선다', () => {
      for (const id of [
        'claim_history_item_thumb_{{claim.claim_number}}_{{itemIndex}}',
        'claim_history_card_item_thumb_{{claim.claim_number}}_{{cardItemIndex}}',
      ]) {
        const box = byId(claimHistory, id);
        const img = (box?.children ?? []).find((c: Node) => c.name === 'Img');
        const icon = (box?.children ?? []).find((c: Node) => c.name === 'Icon');

        expect(img?.if).toBe('{{!!item.thumbnail_url}}');
        expect(icon?.if).toBe('{{!item.thumbnail_url}}');
        expect(icon?.props?.name).toBe('image');
      }
    });

    /**
     * @effects order_claim_history_detail_link_uses_server_url
     */
    it('화면이 상세 경로를 조립하지 않는다', () => {
      expect(JSON.stringify(claimHistory)).not.toContain('/admin/ecommerce/claims/');
    });
  });

  describe('데이터소스 (D20 — 별도 데이터소스를 만들지 않는다)', () => {
    /**
     * @effects order_claim_history_single_datasource
     */
    it('셸이 주문 스코프 그룹 응답 하나만 읽는다', () => {
      const source = ((shell as Node).data_sources ?? []).find((d: Node) => d.id === 'orderClaims');

      expect(source?.endpoint).toBe(
        '/api/modules/sirsoft-ecommerce/admin/orders/{{route.orderNumber}}/claims'
      );
      expect(source?.method).toBe('GET');
      expect(source?.auth_required).toBe(true);
    });

    /**
     * @effects order_claim_history_datasource_failure_does_not_break_screen
     */
    it('이 화면의 라우트 권한보다 좁은 게이트라 403 이 화면 전체를 무너뜨리지 않는다', () => {
      const source = ((shell as Node).data_sources ?? []).find((d: Node) => d.id === 'orderClaims');

      expect(source?.errorHandling?.['403']?.handler).toBe('suppress');
      expect(source?.fallback?.data?.unavailable).toBe(true);
      expect(source?.fallback?.data?.claims).toEqual([]);

      // 한 섹션이 주문 화면 전체의 진입을 막아서는 안 된다
      expect((shell as Node).transition_overlay?.wait_for ?? []).not.toContain('orderClaims');
    });

    /**
     * @effects order_claim_history_subtab_switch_does_not_refetch
     */
    it('서브탭 전환이 재조회를 일으키지 않는다', () => {
      const source = JSON.stringify(claimHistory);

      expect(source).not.toContain('apiCall');
      expect(source).not.toContain('refetchDataSource');
    });

    /**
     * @effects order_claim_history_badges_read_server_counts
     */
    it('서브탭 4종의 뱃지 숫자는 서버 counts 를 그대로 읽는다', () => {
      const types = String((shell as Node).computed?.claimHistoryTypes);

      for (const type of CLAIM_TYPES) {
        expect(types).toContain(`'${type}'`);
      }
      expect(types).toContain('orderClaims?.data?.counts');
      // 화면이 배열을 다시 세면 상한이 걸린 응답에서 뱃지와 목록이 갈린다
      expect(types).not.toContain('claims');
    });

    /**
     * @effects order_claim_history_rows_filtered_by_claim_type
     */
    it('서브탭 필터는 claim_type 으로 거른다 (S6c 판정 ⑤ — 서버는 전 유형 평면 배열)', () => {
      expect(String((shell as Node).computed?.claimHistoryRows)).toBe(
        '{{(orderClaims?.data?.claims ?? []).filter(c => c.claim_type === (_local.claimHistoryType || ' + DEFAULT_TYPE + '))}}'
      );
    });

    /**
     * 종전에는 늘 교환으로 시작해 취소만 있는 주문에서 '접수된 건이 없습니다' 가 먼저 보였다.
     * 기본 유형은 접수 건이 있는 첫 유형이고 순서는 교환 → 반품 → 취소 → 환불, 넷 다 비었으면
     * 교환이다. 판정은 뱃지와 같은 counts 를 읽는다.
     *
     * @effects order_claim_history_default_subtab_is_first_type_with_claims
     */
    it('기본 서브탭은 접수 건이 있는 첫 유형이다 (교환 → 반품 → 취소 → 환불, 없으면 교환)', () => {
      expect(String((shell as Node).computed?.claimHistoryActiveType)).toBe(
        '{{_local.claimHistoryType || ' + DEFAULT_TYPE + '}}'
      );
      // 누른 값이 비어 있어야 판정이 돈다 — 초기 상태와 진입 초기화 둘 다
      expect(initStateOf(shell as any)?.claimHistoryType).toBe('');
      const initActions = ((shell as Node).init_actions ?? []) as Array<{ params?: Record<string, unknown> }>;
      const entry = initActions.find((a) => a.params && 'claimHistoryType' in a.params);
      expect(entry?.params?.claimHistoryType).toBe('');
      // 행 필터도 같은 기준을 쓴다 (computed 간 참조가 불가해 식이 되풀이된다)
      expect(String((shell as Node).computed?.claimHistoryRows)).toContain('_local.claimHistoryType || ' + DEFAULT_TYPE);
      // partial 의 활성 판정은 그 computed 를 읽는다 — _local 에 직접 폴백하면 판정을 건너뛴다
      const partialText = JSON.stringify(claimHistory);
      expect(partialText).toContain('_computed.claimHistoryActiveType');
      expect(partialText).not.toContain("_local.claimHistoryType ?? 'exchange'");
    });
  });

  describe('금액 표기', () => {
    /**
     * @effects order_claim_history_amounts_come_from_server
     */
    it('화면이 통화 기호·자릿수를 만들지 않는다', () => {
      const source = JSON.stringify(claimHistory);

      expect(source).not.toContain('toLocaleString');
      expect(source).not.toMatch(/\}\}\s*원/);
      expect(source).not.toContain('Intl.NumberFormat');
    });

    /**
     * @effects order_payment_info_claim_additional_paid_row_hidden_when_zero
     */
    it('결제 정보의 클레임 추가결제 합계 행은 0원이면 렌더하지 않고 서버 표기를 쓴다', () => {
      const row = byId(paymentInfo, 'claim_additional_paid_row_{{payIdx}}');

      expect(row?.if).toBe('{{(order.data?.claim_additional_paid_total ?? 0) > 0}}');
      expect(JSON.stringify(row)).toContain('order.data?.claim_additional_paid_total_formatted');
      expect(JSON.stringify(row)).not.toContain('toLocaleString');
    });
  });

  describe('모바일 신청서 단위 카드 (D20)', () => {
    /**
     * @effects order_claim_history_mobile_switches_to_cards
     */
    it('<768 에서 표 대신 카드로 바뀐다', () => {
      expect(Object.keys(body?.responsive ?? {})).toEqual(['mobile']);

      const mobileChildren = body?.responsive?.mobile?.children;
      const tableNodes = collect(
        mobileChildren,
        (n) => typeof n.name === 'string' && ['Table', 'Thead', 'Tbody', 'Tr', 'Td', 'Th'].includes(n.name)
      );

      expect(tableNodes).toEqual([]);
      expect(collect(mobileChildren, (n) => n.name === 'Div' && !!n.iteration).length).toBeGreaterThan(0);
    });

    /**
     * @effects order_claim_history_mobile_card_header_matches_merged_cell
     */
    it('카드 헤더가 PC 병합셀과 같은 신청서 축을 그린다', () => {
      const fieldsOf = (node: unknown): string[] => {
        const found = new Set<string>();
        walk(node, (n) => {
          for (const value of Object.values(n)) {
            if (typeof value !== 'string') continue;
            for (const match of value.matchAll(/claim\.([a-z_]+)/g)) found.add(match[1]);
          }
        });

        // `items` 는 표기 축이 아니라 병합 셀의 `rowSpan` 계산에만 쓰이는 표 기계장치다 —
        // 카드에는 병합이 없으므로 두 자리의 '같은 정보' 판정에서 뺀다.
        found.delete('items');

        return [...found].sort();
      };

      expect(fieldsOf(mobileCardHeader)).toEqual(fieldsOf(mergedCell));
    });
  });

  describe('흡수된 정적 목업 3탭', () => {
    /**
     * @effects order_claim_history_orphan_mock_tabs_removed
     */
    it('파일이 남아 있지 않다', () => {
      for (const file of ORPHAN_MOCK_TABS) {
        expect(fs.existsSync(path.join(ORDER_DETAIL_DIR, file))).toBe(false);
      }
    });

    /**
     * @effects order_claim_history_orphan_mock_vocabulary_removed
     */
    it('그 어휘도 함께 사라졌다 — 소비처 없는 키가 남으면 다음 화면이 그것을 정본으로 읽는다', () => {
      for (const pack of [koOrder, enOrder] as Node[]) {
        const block = langValue(pack, 'detail.claim_history') as Node;

        expect(block.tabs).toBeUndefined();
        expect(block.sort).toBeUndefined();
        expect(block.per_page).toBeUndefined();
        expect(block.method).toBeUndefined();
        expect(block.status).toBeUndefined();
        expect(block.delivery).toBeUndefined();
      }
    });
  });

  describe('다국어', () => {
    /**
     * @effects order_claim_history_i18n_keys_exist
     */
    it('이 섹션이 읽는 키가 ko/en 양쪽에 실재한다', () => {
      const keys = new Set<string>();
      walk(claimHistory, (n) => {
        for (const value of Object.values(n)) {
          if (typeof value !== 'string') continue;
          for (const match of value.matchAll(/\$t:sirsoft-ecommerce\.([a-zA-Z0-9_.-]+)/g)) keys.add(match[1]);
        }
      });

      // 동적 세그먼트는 취할 수 있는 값 전수로 대체한다
      for (const type of CLAIM_TYPES) keys.add(`admin.order.detail.claim_history.subtabs.${type}`);
      for (const kind of ['charge', 'refund', 'none']) {
        keys.add(`admin.order.detail.claim_history.amount_type.${kind}`);
      }
      keys.add('admin.order.detail.payment_info.claim_additional_paid');
      keys.add('admin.order.detail.tabs.claim_history');

      // 동적 세그먼트로 끝나는 원본 키(`…subtabs.{{…}}`)는 위 확장 목록이 대신한다 —
      // 추출 정규식이 `{` 앞에서 끊기므로 끝이 점인 키도 같은 부류다.
      const resolvable = [...keys].filter((key) => !key.includes('{') && !key.endsWith('.'));
      expect(resolvable.length).toBeGreaterThan(10);

      for (const key of resolvable) {
        const relative = key.replace(/^admin\.order\./, '');
        for (const [locale, pack] of [['ko', koOrder], ['en', enOrder]] as Array<[string, Node]>) {
          expect(`${locale}:${key}=${langValue(pack, relative)}`).not.toContain('=undefined');
        }
      }
    });
  });
});
