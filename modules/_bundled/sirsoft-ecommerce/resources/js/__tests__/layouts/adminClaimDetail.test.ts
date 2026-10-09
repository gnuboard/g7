/**
 * @file adminClaimDetail.test.ts
 * @description 관리자 클레임 상세 골격 — 구조 계약 (S9a · D17·D24·D25·D26)
 *
 * 이 화면에서 조용히 깨지는 축을 구조로 못박는다:
 *
 *  1. **탭 배열과 섹션 존재가 같은 조건으로 분기하는가** (D17-①). 탭만 남기면 스크롤 대상이
 *     없어 클릭이 무반응이고, 섹션만 남기면 scrollSpy 가 없는 탭 id 를 활성화하려다 무시된다.
 *     어느 쪽도 오류를 남기지 않는다.
 *  2. **회수·재발송이 한 섹션 안의 두 항목인가** (D17-②). 섹션을 둘로 나누면 탭이 하나 더
 *     생기고 재발송이 없는 반품에서 빈 탭이 남는다.
 *  3. **유형 스코프 경로 세그먼트를 화면이 조립하지 않는가**. `claim_type + 's'` 는 세그먼트
 *     규칙의 두 번째 출처이며, 규칙이 바뀌어도 오류 없이 404 만 나는 URL 이 만들어진다.
 *  4. **완료 처리 모달을 복제하지 않았는가** (D26 — 세 진입점이 파일 하나를 공유).
 *  5. **화면이 읽는 데이터소스의 게이트가 이 화면의 라우트 권한보다 좁지 않은가**. 좁으면
 *     그 권한 조합의 운영자에게만 값이 조용히 빈다(전권 계정에서는 재현되지 않는다).
 *  6. **상품 단위 어휘가 게이트와 같은 집합인가** — 신청서 어휘에서 `payment_pending` 제외.
 *  7. **금액을 화면이 조립하지 않는가** — 서버가 준 `*_formatted` 만 그린다.
 *  8. **선택 범위를 명시했는가** — 일괄 처리 목록이므로 `page`.
 *  9. **다국어 키가 ko/en 양쪽에 실재하는가** — 없으면 화면에 원문 키가 그대로 나온다.
 *
 * @vitest-environment node
 */

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';

import shell from '../../../layouts/admin/admin_ecommerce_claim_detail.json';
import itemsSection from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_items.json';
import shippingSection from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_shipping.json';
import reasonBlock from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_reason.json';
import paymentSection from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_payment.json';
import refundExecuteModal from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_refund_execute.json';
import quantityModal from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_item_quantity_confirm.json';
import activityLog from '../../../layouts/admin/partials/admin_ecommerce_claim_detail/_partial_claim_activity_log.json';
import adminRoutes from '../../../routes/admin.json';

const MODULE_ROOT = path.resolve(path.dirname(fileURLToPath(import.meta.url)), '../../../..');

type Node = Record<string, any>;

/** 이 세션이 소유한 상세 레이아웃 파일 */
const DETAIL_FILES: Array<[string, Node]> = [
  ['shell', shell as Node],
  ['items', itemsSection as Node],
  ['shipping', shippingSection as Node],
  ['reason', reasonBlock as Node],
  ['payment', paymentSection as Node],
  ['refund_execute_modal', refundExecuteModal as Node],
  ['quantity_confirm_modal', quantityModal as Node],
  ['activity_log', activityLog as Node],
];

/** 배송 축의 분기 조건 — 셸의 탭 배열과 섹션 `if` 가 **문자 그대로** 공유해야 한다 (D17-①) */
const SHIPPING_CONDITION = "['exchange', 'return'].includes(claim?.data?.claim?.claim_type ?? '')";

/** 환불 실행 축의 분기 조건 — 배송 축과 같은 규율 (D17-①) */
const REFUND_CONDITION = "(claim?.data?.claim?.claim_type ?? '') === 'refund'";

/**
 * 상품 단위 전이 축의 분기 조건.
 *
 * **배송 축과 이름부터 분리한다.** 두 조건은 한때 문자열이 같았지만 뜻이 다르다 — 배송은
 * 교환·반품에만 있고, 상품 단위 전이는 취소만 빼고 모두 있다(§4). 상수를 공유한 채 이쪽만
 * 고치면 배송 동일성 단언이 깨지고, 그것을 맞추려고 배송 조건까지 바꾸면 **환불 상세에
 * 배송 섹션이 생긴다.**
 */
const ITEM_BULK_CONDITION = "(claim?.data?.claim?.claim_type ?? '') !== 'cancel'";

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

/** 렌더되지 않는 설명 필드 — 규약 검사 대상이 아니다 (금지 패턴을 설명하는 주석이 자기 검사에 걸린다) */
const NON_RENDERED_KEYS = new Set(['comment', '_comment', 'description']);

/**
 * 서브트리의 렌더되는 문자열 리프를 모은다. (주석·설명 필드 제외)
 *
 * @param node 시작 노드
 * @returns 문자열 배열
 */
function strings(node: unknown): string[] {
  const found: string[] = [];

  const visit = (value: unknown): void => {
    if (typeof value === 'string') {
      found.push(value);

      return;
    }
    if (Array.isArray(value)) {
      value.forEach(visit);

      return;
    }
    if (value && typeof value === 'object') {
      Object.entries(value as Node).forEach(([key, child]) => {
        if (!NON_RENDERED_KEYS.has(key)) {
          visit(child);
        }
      });
    }
  };

  visit(node);

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
 * 유형별 **상품 단위** 지정 가능 상태 어휘를 Enum 소스에서 도출한다.
 *
 * 신청서 단위 어휘(`REQUESTED`/`PAID`/`WITHDRAWN` 제외)에서 `payment_pending` 을 더 뺀 것이다 —
 * 결제는 신청서 축이라 상품 행에 지정할 수 없다(`BulkClaimOptionStatusRequest`). 값 목록을
 * 테스트에 복사하지 않는다 (S8a 판정 ④ — 사본이 세 번째 출처가 된다).
 *
 * @param claimType 클레임 유형
 * @returns 지정 가능 상태 값 배열
 */
function optionAssignableValues(claimType: string): string[] {
  const enumName = claimType === 'exchange' ? 'ExchangeStatusEnum' : 'ReturnStatusEnum';

  return enumValues(enumName).filter(
    (value) => !['requested', 'paid', 'withdrawn', 'payment_pending'].includes(value),
  );
}

/**
 * 다국어 사전을 `$partial` 참조까지 따라가며 읽는다.
 *
 * @param locale 로케일
 * @returns 사전 객체
 */
function loadMessages(locale: string): Node {
  const root = path.join(MODULE_ROOT, 'resources/lang');

  const resolve = (value: unknown): unknown => {
    if (Array.isArray(value)) {
      return value.map(resolve);
    }
    if (!value || typeof value !== 'object') {
      return value;
    }

    const node = value as Node;

    if (typeof node.$partial === 'string') {
      return resolve(JSON.parse(fs.readFileSync(path.join(root, node.$partial), 'utf8')));
    }

    return Object.fromEntries(Object.entries(node).map(([key, child]) => [key, resolve(child)]));
  };

  return resolve(JSON.parse(fs.readFileSync(path.join(root, `${locale}.json`), 'utf8'))) as Node;
}

/**
 * 점 표기 키가 사전에 실재하는지 본다.
 *
 * @param messages 사전
 * @param key 모듈 접두를 뗀 키
 * @returns 존재 여부
 */
function hasMessage(messages: Node, key: string): boolean {
  return key.split('.').reduce<unknown>(
    (node, segment) => (node && typeof node === 'object' ? (node as Node)[segment] : undefined),
    messages,
  ) !== undefined;
}

describe('상세 셸 — 라우트·데이터소스·모달 (S9a)', () => {
  const routes = (adminRoutes as Node).routes as Node[];

  /**
   * 목록의 클레임번호·[상세 보기]가 이 주소로 간다 — 라우트가 없으면 템플릿 404 다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_detail_route_registered
   */
  it('번호 기반 상세 라우트가 등록되어 있다', () => {
    const route = routes.find((r) => r.path === '*/admin/ecommerce/claims/:claimNumber');

    expect(route).toBeDefined();
    expect(route?.layout).toBe('admin_ecommerce_claim_detail');
    expect(route?.permission).toBe('sirsoft-ecommerce.claims.read');
  });

  /**
   * 상세 화면은 `claims.read` 라우트다. 그보다 좁은 게이트의 엔드포인트를 읽으면 그 권한
   * 조합의 운영자에게만 값이 비고, 전권 계정에서는 재현되지 않는다.
   *
   * @effects claim_detail_datasources_within_route_permission
   */
  it('상세 화면은 자기 라우트 권한보다 좁은 게이트의 엔드포인트를 읽지 않는다', () => {
    const endpoints = ((shell as Node).data_sources as Node[]).map((ds) => ds.endpoint);

    expect(endpoints).toEqual([
      '/api/modules/sirsoft-ecommerce/admin/claims/{{route.claimNumber}}',
      '/api/modules/sirsoft-ecommerce/admin/claims/{{route.claimNumber}}/logs',
      // 환불 실행 모달의 은행 코드표 — 공개 결제 설정이라 권한 게이트가 없다(P7 #15).
      '/api/modules/sirsoft-ecommerce/settings/payment',
    ]);
    // `settings.read` 게이트 — 완료 기본값·택배사는 상세 응답이 나른다.
    expect(endpoints.some((e: string) => e.includes('/admin/settings'))).toBe(false);
    expect(endpoints.some((e: string) => e.includes('shipping-carriers'))).toBe(false);
  });

  /**
   * 활동 로그가 상세 응답의 도착을 기다리면 그동안 섹션이 빈 채로 열린다.
   *
   * @effects claim_logs_independent_of_detail_response
   */
  it('활동 로그 데이터소스는 상세 응답이 아니라 URL 파라미터만 읽는다', () => {
    const logs = ((shell as Node).data_sources as Node[]).find((ds) => ds.id === 'claim_logs');

    expect(logs?.endpoint).not.toContain('claim?.data');
    expect(JSON.stringify(logs?.params)).not.toContain('claim?.data');
  });

  /**
   * 파일 하나를 세 진입점이 공유한다 (D26) — 복제하면 한 곳만 고쳐졌을 때 나머지 두 경로가
   * 조용히 옛 규칙으로 남는다.
   *
   * @scenario completion_entry=detail
   * @effects claim_complete_modal_shared_not_copied
   */
  it('완료 처리 모달은 목록이 소유한 파일을 그대로 참조한다', () => {
    expect(((shell as Node).modals as Node[]).map((m) => m.partial)).toEqual([
      'partials/admin_ecommerce_claim_list/_modal_claim_complete.json',
      // 환불 실행 확인 모달은 이 화면만 여는 진입점이라 상세가 소유한다 (D18).
      'partials/admin_ecommerce_claim_detail/_modal_claim_refund_execute.json',
      // 수량 확인 모달도 같은 이유로 상세 소유다 (§4-6) — 주문상세 모달을 복제하지 않는다:
      // 그 파일은 주문 상태 어휘·`_local.changeQuantities` 키·`confirmItems` 계약에 묶여 있다.
      'partials/admin_ecommerce_claim_detail/_modal_claim_item_quantity_confirm.json',
      // 첨부 일괄 정리 확인 모달 — 되돌릴 수 없는 삭제라 한 번 더 묻는다(P7 #4). 상세만 연다.
      'partials/admin_ecommerce_claim_detail/_modal_claim_images_clear.json',
      // 진행 방식 전환(D1) · 고객 요청 대리 철회(S14) · 신청 내용 수정(S15) — 상세만 여는 확인 모달.
      'partials/admin_ecommerce_claim_detail/_modal_claim_exchange_mode_confirm.json',
      'partials/admin_ecommerce_claim_detail/_modal_claim_withdraw_on_behalf.json',
      'partials/admin_ecommerce_claim_detail/_modal_claim_request_edit.json',
    ]);
    expect(
      fs.existsSync(
        path.join(
          MODULE_ROOT,
          'resources/layouts/admin/partials/admin_ecommerce_claim_detail/_modal_claim_complete.json',
        ),
      ),
    ).toBe(false);
  });

  /** @effects claim_detail_tab_content_loads_four_partials_in_order */
  it('탭 콘텐츠 영역이 네 partial 을 순서대로 싣는다', () => {
    expect((byId(shell, 'claim_tab_content_area').children as Node[]).map((n) => n.partial)).toEqual([
      'partials/admin_ecommerce_claim_detail/_partial_claim_items.json',
      'partials/admin_ecommerce_claim_detail/_partial_claim_shipping.json',
      // 결제 정보와 환불 실행은 같은 금액 축을 보는 한 파일이다 (§5-2 파일 목록).
      'partials/admin_ecommerce_claim_detail/_partial_claim_payment.json',
      'partials/admin_ecommerce_claim_detail/_partial_claim_activity_log.json',
    ]);
  });

  /**
   * 목록 복귀는 보던 조건 그대로다 — 검색어·필터·페이지·유형 서브탭을 URL 에서 승계한다.
   *
   * @effects claim_detail_list_return_preserves_context
   */
  it('[목록] 버튼이 목록 컨텍스트를 승계한다', () => {
    const action = (byId(shell, 'cd_010_list_button').actions as Node[])[0];

    expect(action.params.path).toBe('/admin/ecommerce/claims');
    expect(action.params.mergeQuery).toBe(true);
    expect(action.params.query).toEqual({});
  });
});

describe('섹션 탭 ↔ 섹션 존재 동시 분기 (D17-①)', () => {
  const tabsExpression: string = (shell as Node).computed.claimDetailTabs;

  /**
   * 탭 배열이 만드는 id 집합과 실제 섹션(`data-section-id`) 집합이 같아야 한다.
   *
   * @effects claim_detail_tabs_match_sections
   */
  it('탭 id 다섯 종과 섹션 id 다섯 종이 1:1 이다', () => {
    const tabIds = [...tabsExpression.matchAll(/id:\s*'([a-z_]+)'/g)].map((m) => m[1]);
    const sectionIds = DETAIL_FILES.flatMap(([, node]) =>
      collect(node, (n) => typeof n.props?.['data-section-id'] === 'string').map(
        (n) => n.props['data-section-id'] as string,
      ),
    );

    expect(tabIds).toEqual([
      'claim_info',
      'shipping_info',
      'payment_info',
      'refund_execute',
      'activity_log',
    ]);
    expect([...sectionIds].sort()).toEqual([...tabIds].sort());
  });

  /**
   * computed 값은 `_computed.` 접두로만 읽힌다. 맨 이름으로 읽으면 표현식이 `undefined` 가
   * 되어 `?? []` 폴백이 걸리고, **탭이 한 칸도 그려지지 않는다** — 오류는 나지 않고 셸의
   * 탭 영역만 비어 클릭할 것이 없어진다(실측이 잡은 결함).
   *
   * @effects claim_detail_tabs_read_computed_with_prefix
   */
  it('탭 배열이 computed 를 `_computed.` 접두로 읽는다', () => {
    expect(byId(shell, 'claim_tab_navigation').props.tabs).toBe('{{_computed.claimDetailTabs ?? []}}');
  });

  /**
   * 조건이 문자 그대로 같아야 한쪽만 바뀌는 일이 생기지 않는다.
   *
   * @scenario claim_type=refund, claim_stage=in_progress
   * @effects claim_detail_shipping_condition_identical
   */
  it('배송 탭과 배송 섹션이 문자 그대로 같은 조건을 쓴다', () => {
    expect(tabsExpression).toContain(SHIPPING_CONDITION);
    expect((shippingSection as Node).if).toBe(`{{${SHIPPING_CONDITION}}}`);
  });

  /**
   * 결제 정보는 유형과 무관하게 그린다 — 원 주문의 결제 사실은 네 유형 모두에 있다.
   * 환불 실행은 환불신청서에만 있는 축이라 조건이 붙고, 그 조건은 섹션 `if` 와 **문자
   * 그대로** 같아야 한다 (D17-①).
   *
   * @scenario claim_type=refund, cancellable=present
   * @effects claim_detail_refund_execute_condition_identical
   */
  it('결제 탭은 무조건, 환불 실행 탭은 환불 유형에서만 세워진다', () => {
    // 결제 정보 탭에는 조건이 붙지 않는다 — 조건부 항목만 스프레드로 들어간다.
    expect(tabsExpression).toContain(
      "{ id: 'payment_info', label: $t('sirsoft-ecommerce.admin.claim.detail.tabs.payment_info') }",
    );
    expect(tabsExpression).toContain(REFUND_CONDITION);

    const refundSection = collect(
      paymentSection,
      (n) => n.props?.['data-section-id'] === 'refund_execute',
    )[0];

    expect(refundSection.if).toBe(`{{${REFUND_CONDITION}}}`);
  });

  /**
   * 섹션 id 접두는 `TabNavigationScroll` 의 `sectionIdPrefix` 와 맞물린다 — 어긋나면 탭
   * 클릭이 스크롤 대상을 찾지 못한다.
   *
   * @effects claim_detail_section_id_prefix_matches
   */
  it('섹션 노드 id 가 `section_` + 탭 id 형태다', () => {
    const nav = byId(shell, 'claim_tab_navigation');
    expect(nav.props.sectionIdPrefix).toBe('section_');
    expect(nav.props.enableScrollSpy).toBe(true);

    DETAIL_FILES.forEach(([, node]) => {
      collect(node, (n) => typeof n.props?.['data-section-id'] === 'string').forEach((section) => {
        expect(section.id).toBe(`section_${section.props['data-section-id']}`);
      });
    });
  });

  /**
   * 사유·메모 블록은 `#section_claim_info` 안의 블록이다 — 섹션으로 승격되면 scrollSpy 가
   * 탭 없는 섹션 id 를 잡아 활성 탭이 어긋난다.
   *
   * @effects claim_reason_block_is_not_a_section
   */
  it('사유·메모 블록은 섹션이 아니다', () => {
    expect((reasonBlock as Node).props?.['data-section-id']).toBeUndefined();
  });
});

describe('배송 정보 — 회수·재발송 통합 (D17-② · D14)', () => {
  /**
   * 회수와 재발송은 한 카드 안의 두 항목이다 — 섹션을 둘로 나누면 탭이 하나 더 생기고,
   * 재발송이 없는 반품에서 빈 탭이 남는다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_shipping_single_section_two_blocks
   */
  it('회수·재발송이 한 섹션의 한 카드 안에 있다', () => {
    expect(collect(shippingSection, (n) => typeof n.props?.['data-section-id'] === 'string')).toHaveLength(1);

    const card = byId(shippingSection, 'claim_shipping_card');
    const blockIds = (card.children as Node[]).map((n) => n.id);

    expect(blockIds).toEqual([
      undefined,
      // 운송장 기록·정정 입구 안내 — 이 탭에는 입력칸이 없다(송장은 상품 행마다 — #599).
      'cs_001_edit_hint',
      'claim_collection_block',
      'claim_redelivery_divider',
      'claim_redelivery_not_applicable',
      'claim_redelivery_block',
    ]);
  });

  /**
   * 반품에도 재발송 항목 자리를 남기고 '해당 없음' 을 명시한다 — 통째로 빼면 운영자가
   * '아직 안 보이는 것' 과 '해당 없음' 을 구분할 수 없다.
   *
   * @scenario claim_type=return
   * @effects claim_shipping_redelivery_not_applicable_shown
   */
  it('교환이 아닌 유형에는 재발송 해당 없음 안내가 있다', () => {
    expect(byId(shippingSection, 'claim_redelivery_not_applicable').if).toBe(
      "{{(claim?.data?.claim?.claim_type ?? '') !== 'exchange'}}",
    );
    expect(byId(shippingSection, 'claim_redelivery_block').if).toBe(
      "{{(claim?.data?.claim?.claim_type ?? '') === 'exchange'}}",
    );
  });

  /**
   * 두 뱃지는 서로 다른 사실을 말한다 (D14) — 하나로 합치면 운영자가 값이 되돌아간 건과
   * 고객이 손댄 건을 구분할 수 없다.
   *
   * @scenario claim_type=exchange, redelivery_address=changed
   * @effects claim_redelivery_two_badges_distinct
   */
  it('재발송지 뱃지 2종이 서로 다른 근거를 읽는다', () => {
    expect(byId(shippingSection, 'cs_009_address_differs_badge').if).toBe(
      '{{claim?.data?.redelivery_address_differs === true}}',
    );
    expect(byId(shippingSection, 'cs_010_changed_by_user_badge').if).toBe(
      '{{claim?.data?.claim?.redelivery_address_snapshot?.changed_by_user === true}}',
    );
  });

  /**
   * 택배사 선택지는 상세 응답이 나른다 — 배송사 엔드포인트는 `settings.read` 게이트다.
   *
   * @effects claim_carrier_options_come_from_detail_response
   */
  it('택배사 셀렉트가 상세 응답의 목록을 읽는다', () => {
    // 송장 입력은 상품 칸 선택 바 하나다 — 배송 정보 탭은 운송장별로 묶어 보여 주기만 한다(#599).
    expect(byId(itemsSection, 'ci_007_carrier').props.options).toContain('.concat(claim?.data?.carriers ?? [])');
    expect(collect(shippingSection, (n) => n.name === 'Select' || n.name === 'Input')).toEqual([]);
  });

  /**
   * 배송 정보 탭은 운송장 묶음(`tracking_groups`)을 방향별로 그리고, 묶음마다 [도착 처리] 가 운송장 단위
   * 도착 핸들러를 부른다(R9). 켜짐은 서버 판정(`can_deliver`)을 따른다.
   *
   * @effects claim_tracking_deliver_posts_the_waybill
   */
  it('운송장 묶음마다 [도착 처리] 가 서버 판정을 따른다', () => {
    const groups = collect(shippingSection, (n) => typeof n.iteration?.source === 'string'
      && n.iteration.source.includes('tracking_groups'));
    const deliver = collect(shippingSection, (n) => Array.isArray(n.actions)
      && (n.actions as Node[]).some((a) => a.handler === 'sirsoft-ecommerce.deliverClaimTracking'));

    expect(groups.length).toBe(2);
    expect(deliver.length).toBe(2);
    deliver.forEach((button) => {
      expect(String(button.props.disabled)).toContain('group.can_deliver !== true');
    });
  });
});

describe('신청상품 — 일괄 변경 바와 표 (D24·D25)', () => {
  /**
   * 어휘를 화면이 들지 않는다 — 서버가 **행마다** 실어 준 전이 선택지의 교집합을 핸들러가
   * 계산해 `_local.itemTransitionOptions` 에 깐다. 화면이 상태 목록을 리터럴로 들면 전이표가
   * 바뀔 때 화면과 서버가 갈리고, 그 사이 운영자는 "가끔 안 되는 버튼" 을 본다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_item_status_vocabulary_comes_from_the_server
   */
  it('상품 단위 셀렉트가 서버 어휘를 그대로 읽는다', () => {
    const select = byId(itemsSection, 'ci_002_item_status');

    expect(select.props.options).toBe(
      "{{[{ value: '', label: $t('sirsoft-ecommerce.admin.claim.bulk.status_placeholder'), disabled: true }].concat(_local.itemTransitionOptions ?? [])}}",
    );
    expect(JSON.stringify(select.props.options)).not.toContain('approved');
  });

  /**
   * 레이아웃 어디에도 상태 값 리터럴이 남지 않아야 한다 — 한 곳이라도 남으면 그것이 어휘의
   * 두 번째 출처가 된다.
   *
   * @effects claim_item_bar_has_no_status_literals
   */
  it('일괄 바에 상태 리터럴이 없다', () => {
    const bar = JSON.stringify(byId(itemsSection, 'claim_item_bulk_bar'));

    ['collection_ready', 'redelivering', 'redelivered', 'on_hold'].forEach((value) => {
      expect(bar).not.toContain(`"${value}"`);
    });
  });

  /**
   * 교집합이 0건이면 셀렉트 대신 이유를 적는다 — 빈 셀렉트만 두면 운영자는 화면이 덜 그려진
   * 줄 안다.
   *
   * @effects claim_item_bar_explains_an_empty_intersection
   */
  it('교집합 0건 안내가 선택이 있을 때만 선다', () => {
    expect(byId(itemsSection, 'ci_003_no_common_transition').if).toBe(
      "{{(_local.selectedClaimItems ?? []).length > 0 && (_local.itemTransitionOptions ?? []).length === 0}}",
    );
  });

  /**
   * 선택은 화면 밖(로컬 상태)에 저장되므로 범위를 명시한다 — 일괄 처리 목록이라 `page` 다.
   *
   * @effects claim_items_grid_declares_selection_scope
   */
  it('신청상품 표가 선택 범위를 명시한다', () => {
    const grid = byId(itemsSection, 'claim_items_datagrid');

    expect(grid.props.selectionScope).toBe('page');
    expect(grid.actions?.[0]?.event).toBe('onSelectionChange');
    // 선택만 담는 `setState` 로는 교집합·고른 상태의 유효성이 갱신되지 않는다 (§4-4).
    expect(grid.actions?.[0]?.handler).toBe('sirsoft-ecommerce.syncClaimItemSelection');
  });

  /**
   * 취소만 빼고 그린다 — 취소는 생성이 곧 확정이라 옮길 상태가 없다. 배송 축과 **다른**
   * 상수를 쓰는 것이 이 단언의 요점이다(R9): 두 축은 뜻이 달라 한쪽을 고칠 때 다른 쪽이
   * 딸려가면 안 된다.
   *
   * @scenario claim_type=refund, claim_stage=in_progress
   * @effects claim_item_bulk_bar_absent_only_for_cancel
   */
  it('상품 단위 일괄 바가 취소에서만 빠진다', () => {
    expect(byId(itemsSection, 'claim_item_bulk_bar').if).toBe(`{{${ITEM_BULK_CONDITION}}}`);
    expect(byId(itemsSection, 'claim_items_datagrid').props.selectable).toBe(`{{${ITEM_BULK_CONDITION}}}`);
    // 배송 축은 그대로다 — 같이 바꾸면 환불 상세에 배송 섹션이 생긴다.
    expect(ITEM_BULK_CONDITION).not.toBe(SHIPPING_CONDITION);
  });

  /**
   * 수량 행은 **`iteration` 선언**으로 반복한다.
   *
   * `forEach` 는 엔진이 읽지 않는 키다 — 그대로 두면 행이 **한 번만** 그려지고 `item` 이
   * 미해석이라 수량 입력이 비활성·빈 값으로 선다. 라이브 실측에서 그 형태로 드러났다
   * (`inputMax:"" · inputValue:"" · inputDisabled:true`).
   *
   * @effects claim_item_quantity_modal_iterates_rows_declaratively
   */
  it('수량 확인 모달이 iteration 선언으로 행을 반복한다', () => {
    const rows = byId(quantityModal, 'ciq_002_rows');
    const repeated = (rows.children as Node[])[0];

    expect(repeated.iteration?.source).toBe('{{_global.claimItemQuantityContext?.rows ?? []}}');
    expect(repeated.iteration?.item_var).toBe('qtyRow');
    expect(JSON.stringify(quantityModal)).not.toContain('"forEach"');
  });

  /**
   * 수량 확인 모달은 **자기 파일 · 자기 상태 키**를 쓴다 (§4-6).
   *
   * 주문상세 모달을 복제하면 그 파일이 묶여 있는 `_local.changeQuantities` 키를 함께 들고 와,
   * 주문상세 탭을 갔다 온 뒤 클레임 수량이 섞인다. 반대로 모달을 상품 partial 안에 인라인하면
   * 상세를 여는 것만으로 모달 트리가 매번 다시 만들어진다. 둘 다 예외가 없어 드러나지 않는다.
   *
   * @effects claim_item_quantity_modal_is_a_separate_partial
   */
  it('수량 확인 모달이 별도 partial 이고 주문상세 상태 키를 쓰지 않는다', () => {
    // ① 셸이 파일로 참조한다 — 상품 partial 안에 인라인되어 있지 않다.
    expect(((shell as Node).modals as Node[]).map((m) => m.partial)).toContain(
      'partials/admin_ecommerce_claim_detail/_modal_claim_item_quantity_confirm.json',
    );
    expect(JSON.stringify(itemsSection)).not.toContain('ciq_002_rows');

    // ② 주문상세 전용 상태 키·계약을 물려받지 않는다.
    //    `comment` 는 "왜 복제하지 않는가" 를 적느라 그 이름들을 인용하므로 빼고 본다 —
    //    설명을 지웠다고 초록이 되거나, 설명을 적었다고 빨개지면 잰 것이 계약이 아니다.
    const serialized = JSON.stringify(quantityModal, (key, value) => (key === 'comment' ? undefined : value));

    expect(serialized).not.toContain('changeQuantities');
    expect(serialized).not.toContain('confirmItems');

    // ③ 자기 핸들러를 쓴다 — 주문 핸들러를 공유하면 ① 의 키 충돌로 되돌아간다.
    expect(serialized).toContain('sirsoft-ecommerce.updateClaimItemQuantity');
  });

  /**
   * [적용] 은 곧바로 실행하지 않는다 — 수량은 행마다 다르므로 확인 모달이 받는다 (§4-6).
   *
   * @effects claim_item_apply_routes_through_the_quantity_modal
   */
  it('[적용] 이 수량 확인 모달 핸들러를 부른다', () => {
    const action = (byId(itemsSection, 'ci_004_item_bulk_apply').actions as Node[])[0];

    expect(action.handler).toBe('sirsoft-ecommerce.openClaimItemQuantityConfirm');
  });

  /**
   * 완료 처리 카드는 교환·반품의 신청서 단위 진입점이다 (D26 ③).
   *
   * @effects claim_completion_card_opens_shared_modal
   */
  it('완료 처리 카드가 공용 모달 핸들러를 부른다', () => {
    const action = (byId(itemsSection, 'cmpl_002_button').actions as Node[])[0];

    expect(action.handler).toBe('sirsoft-ecommerce.openClaimDetailComplete');
    expect(action.params.completeDefaults).toBe('{{claim?.data?.complete_defaults ?? {} }}');
  });

  /**
   * 진행 단계 배열은 서버가 만든다 — 화면이 순서를 다시 적으면 그 사본이 어휘의 두 번째
   * 출처가 되어, 상태가 늘어도 화면만 낡은 채로 남는다.
   *
   * @effects claim_progress_steps_come_from_server
   */
  it('진행단계 스텝퍼가 서버가 준 배열만 그린다', () => {
    const steps = byId(itemsSection, 'claim_progress_step_{{stepIdx}}');


    expect(steps.iteration.source).toBe('{{claim?.data?.progress_steps ?? []}}');
    expect(strings(byId(itemsSection, 'claim_progress_card')).join(' ')).not.toContain('collection_ready');
  });

  /**
   * 칸 폭이 라벨 길이를 따라가면 언어마다 칸이 들쭉날쭉해지고, 모바일에서 한 줄에 몰아넣으면
   * 가로 스크롤이 생긴다. 균일 폭(auto-cols-fr)과 4칸/줄 줄바꿈이 그 둘을 막는다.
   *
   * @effects claim_progress_cells_uniform_width_with_chevrons, claim_progress_wraps_on_mobile_without_scroll
   */
  it('진행단계 칸은 균일 폭 grid 이고 모바일은 4칸/줄로 줄바꿈하며 가로 스크롤을 만들지 않는다', () => {
    const container = byId(itemsSection, 'claim_progress_steps');
    const pc = container.props.className as string;
    const mobile = container.responsive.portable.props.className as string;

    expect(pc).toContain('grid-flow-col');
    expect(pc).toContain('auto-cols-fr');
    expect(mobile).toContain('grid-cols-4');
    expect(pc).not.toContain('overflow-x-auto');
    expect(mobile).not.toContain('overflow-x-auto');

    const card = strings(byId(itemsSection, 'claim_progress_card')).join(' ');
    expect(card).not.toContain('overflow-x-auto');
    expect(card).not.toContain('whitespace-nowrap');
    expect(card).not.toContain('shrink-0');
  });

  /**
   * 아이콘·색은 서버가 상태 Enum 에서 실어 준다 — 화면이 상태 값으로 그림을 다시 고르면 그 표가
   * 어휘의 두 번째 출처가 된다. 꺾쇠는 칸 사이에만 서고(PC 는 마지막 칸 제외, 모바일은 줄 끝도 제외).
   *
   * @effects claim_progress_steps_carry_icon_and_variant, claim_progress_cells_uniform_width_with_chevrons
   */
  it('칸마다 서버가 준 아이콘·색을 읽고 꺾쇠는 마지막 칸과 모바일 줄 끝을 뺀다', () => {
    const step = byId(itemsSection, 'claim_progress_step_{{stepIdx}}');
    const icons = collect(step, (n) => n.name === 'Icon');

    const statusIcon = icons.find((n) => String(n.props?.name ?? '').includes('step.icon'));
    expect(statusIcon, '상태 아이콘 노드').toBeTruthy();

    const circle = collect(step, (n) => typeof n.classMap?.key === 'string' && n.classMap.key.includes('step.variant'))[0];
    expect(circle, '상태 색 classMap').toBeTruthy();
    const variantKeys = Object.keys(circle.classMap.variants);
    expect(variantKeys).toEqual(expect.arrayContaining(['done:success', 'current:info', 'current:primary', 'off_path:danger']));
    expect(variantKeys.filter((k) => k.startsWith('done:'))).toHaveLength(7);
    expect(variantKeys.filter((k) => k.startsWith('current:'))).toHaveLength(7);

    // 꺾쇠의 숨김은 래퍼 Div 가 맡는다 — Icon 에 `hidden` 을 직접 주면 Font Awesome 의 display
    // 규칙(레이어 밖)이 Tailwind 유틸리티(레이어 안)를 이겨 숨겨지지 않는다(실서버 실측).
    const chevrons = collect(step, (n) => typeof n.props?.['data-step-chevron'] === 'string');
    const pcChevron = chevrons.find((n) => n.props['data-step-chevron'] === 'pc');
    const mobileChevron = chevrons.find((n) => n.props['data-step-chevron'] === 'mobile');
    expect(pcChevron.name).toBe('Div');
    expect(mobileChevron.name).toBe('Div');
    expect(collect(pcChevron, (n) => n.name === 'Icon')[0].props.name).toBe('chevron-right');
    expect(pcChevron.if).toContain('length - 1');
    expect(pcChevron.responsive.portable.props.className).toBe('hidden');
    expect(mobileChevron.if).toContain('% 4 !== 3');
    expect(mobileChevron.props.className).toBe('hidden');
    expect(mobileChevron.responsive.portable.props.className).not.toContain('hidden');
    expect(icons.some((n) => String(n.props?.className ?? '').split(' ').includes('hidden'))).toBe(false);

    // 라벨은 nowrap 이 아니라 칸 안에서 줄바꿈한다.
    const label = collect(step, (n) => n.name === 'Span' && String(n.text ?? '').includes('step.label'))[0];
    expect(label.classMap.base).toContain('break-words');
    expect(label.classMap.base).not.toContain('whitespace-nowrap');
  });
});

describe('전 상세 레이아웃 공통 규약', () => {
  /**
   * 세그먼트를 화면이 조립하면 그 조립이 규칙의 두 번째 출처가 된다 — 규칙이 바뀌어도
   * 오류 없이 404 만 나는 URL 이 만들어진다.
   *
   * @effects claim_detail_never_assembles_path_segment
   */
  it.each(DETAIL_FILES)('%s 는 유형 세그먼트를 조립하지 않는다', (_label, node) => {
    strings(node).forEach((value) => {
      // 세그먼트는 유형 값 뒤에 s 를 붙여 만드는 형태다 — 중간에 널 병합이 끼어도 문자열
      // 연결은 남으므로, 그 연결 자체를 금지한다.
      expect(value).not.toMatch(/\+\s*'s'/);
      expect(value).not.toContain('claim_type + ');
    });
  });

  /**
   * 통화 기호·자릿수는 설정과 거래 시점 스냅샷이 정한다 — 화면이 숫자에 기호를 붙이면
   * 기본 통화가 원이 아닌 상점에서 값은 맞고 단위만 틀린 금액이 나간다.
   *
   * @effects claim_detail_amounts_use_server_formatting
   */
  it.each(DETAIL_FILES)('%s 는 금액 표기를 조립하지 않는다', (_label, node) => {
    strings(node).forEach((value) => {
      expect(value).not.toContain('toLocaleString');
      expect(value).not.toMatch(/\}\}\s*원/);
    });
  });

  /**
   * `{{{` / `}}}` 는 파서 모호성 구간이다 — 화면에 표현식 잔재가 그대로 노출된다.
   *
   * @effects claim_detail_binding_braces_are_sane
   */
  it.each(DETAIL_FILES)('%s 의 바인딩에 3연속 중괄호가 없다', (_label, node) => {
    strings(node).forEach((value) => {
      expect(value).not.toContain('{{{');
      expect(value).not.toContain('}}}');
    });
  });

  /**
   * `if` 는 `{{...}}` 한 쌍으로 감싼 단일 바인딩일 때만 식으로 평가된다 — 감싸지 않으면
   * 원본 문자열이 그대로 참으로 읽혀 조건이 언제나 통과한다.
   *
   * @effects claim_detail_if_conditions_are_single_bindings
   */
  it.each(DETAIL_FILES)('%s 의 모든 if 가 단일 바인딩이다', (_label, node) => {
    collect(node, (n) => typeof n.if === 'string').forEach((n) => {
      expect(n.if).toMatch(/^\{\{[\s\S]*\}\}$/);
      expect(n.if.slice(2, -2)).not.toContain('}}');
    });
  });
});

describe('결제 정보 — 주문상세 정본 동형 (D17-③)', () => {
  /** 주문상세 결제 정보 정본 — 이 화면의 행 마크업이 그대로 따라야 할 원본 */
  const orderPaymentInfo: Node = JSON.parse(
    fs.readFileSync(
      path.join(
        MODULE_ROOT,
        'resources/layouts/admin/partials/admin_ecommerce_order_detail/_partial_payment_info.json',
      ),
      'utf8',
    ),
  );

  /**
   * 결제수단 카드 — 정본 복제 대상은 이 카드다. '이 클레임의 정산' 은 같은 섹션 안에 있지만
   * 신설 블록이고(§6-4 "별도 블록"), 환불 실행은 다른 섹션이다.
   */
  const paymentInfoSection = byId(paymentSection, 'claim_payment_card');

  /**
   * 서브트리에서 금액 행(`flex justify-between`)의 className 집합을 모은다.
   *
   * @param node 시작 노드
   * @returns className 집합
   */
  const rowClasses = (node: unknown): Set<string> =>
    new Set(
      collect(
        node,
        (n) => typeof n.props?.className === 'string' && n.props.className.includes('flex justify-between'),
      ).map((n) => n.props.className as string),
    );

  /**
   * 행 마크업이 갈리면 같은 주문이 두 화면에서 다르게 보인다 — 눈으로는 알아채기 어렵고
   * 오류도 남지 않는다. 정본이 쓰는 행 클래스 밖의 형태를 이 섹션에 새로 만들지 않는다.
   *
   * @effects claim_payment_rows_match_order_detail_source
   */
  it('결제 정보 섹션의 금액 행이 정본의 행 클래스만 쓴다', () => {
    const source = rowClasses(orderPaymentInfo);
    const mine = [...rowClasses(paymentInfoSection)];

    expect(mine.length).toBeGreaterThan(0);
    expect(mine.filter((cls) => !source.has(cls))).toEqual([]);
  });

  /**
   * 행 마크업이 같아도 **라벨이 다르면** 같은 주문의 같은 행이 두 화면에서 다른 말로 보인다
   * (실측이 잡은 결함 — 정본은 "과세 금액"·"부가세 (VAT)"·"적립예정 포인트" 인데 이 화면은
   * "과세금액"·"부가세"·"적립예정" 이었다). 값이 같아 오류가 남지 않고, 두 화면을 나란히
   * 열어 보지 않으면 드러나지 않는다.
   *
   * 그래서 라벨 키를 복제하지 않고 **정본이 쓰는 키를 그대로** 읽는다 — 정본의 문구가 바뀌면
   * 이 화면도 함께 바뀐다. 이 카드에만 있는 축(결제 건 없음 안내)만 예외다.
   *
   * @effects claim_payment_labels_reuse_order_detail_keys
   */
  it('결제 정보 카드가 정본의 라벨 키를 그대로 읽는다', () => {
    const keysOf = (node: unknown): string[] =>
      strings(node).flatMap((value) => [...value.matchAll(/\$t:(sirsoft-ecommerce\.[A-Za-z0-9_.]+)/g)]
        .map((m) => m[1].replace(/\.$/, '')));

    const source = new Set(keysOf(orderPaymentInfo));
    /** 이 카드에만 있는 축 — 정본에는 결제행이 없을 때의 안내 자체가 없다 */
    const OWN_KEYS = new Set(['sirsoft-ecommerce.admin.claim.detail.payment.no_payment']);

    const drifted = [...new Set(keysOf(paymentInfoSection))]
      .filter((key) => !source.has(key) && !OWN_KEYS.has(key));

    expect(drifted).toEqual([]);
  });

  /**
   * 주문 상세 엔드포인트는 `orders.read` 게이트다 — 이 화면(`claims.read`)이 직접 부르면
   * 클레임 권한만 가진 운영자에게만 403 이 되어 결제 정보 탭이 통째로 빈다.
   *
   * @scenario claim_type=return, claim_stage=in_progress
   * @effects claim_payment_reads_detail_envelope_only
   */
  it('결제 정보가 상세 봉투의 주문 축만 읽는다', () => {
    const values = strings(paymentInfoSection);

    expect(values.some((v) => v.includes('claim?.data?.order?.'))).toBe(true);
    expect(values.filter((v) => v.includes('/admin/orders'))).toEqual([]);
    expect(values.filter((v) => v.includes('order_detail'))).toEqual([]);
  });

  /**
   * 결제행이 없는 주문(전액 할인 등)에서 카드가 통째로 사라지면 운영자는 "아직 안 불러왔다"
   * 와 "결제 건이 없다" 를 구분할 수 없다.
   *
   * @effects claim_payment_shows_empty_notice
   */
  it('결제 건이 없을 때의 안내가 있다', () => {
    expect(byId(paymentSection, 'cp_000_no_payment').if).toBe(
      '{{(claim?.data?.order?.payments ?? []).length === 0}}',
    );
  });

  /**
   * 환불 두 행은 클레임 발 환불에서만 0 을 넘는다 (§4-3-1) — 실행 후 이 행이 렌더되는 것이
   * `total_refunded_amount` 누적의 화면 쪽 증거다.
   *
   * @scenario claim_type=refund, cancellable=present
   * @effects claim_payment_renders_refund_rows_after_execution
   */
  it('누적 환불 두 행이 주문 집계를 읽는다', () => {
    expect(byId(paymentSection, 'cp_004_refunded_amount_{{payIdx}}').if).toBe(
      '{{(claim?.data?.order?.total_refunded_amount ?? 0) > 0}}',
    );
    expect(byId(paymentSection, 'cp_005_refunded_points_{{payIdx}}').if).toBe(
      '{{(claim?.data?.order?.total_refunded_points_amount ?? 0) > 0}}',
    );
  });

  /**
   * 「마일리지로 환불」로 지급한 현금분은 환불금액 행의 그중 내역으로 따로 보인다(Q3) — 결제행은
   * 결제완료로 남으므로 이 줄이 없으면 돈이 카드·계좌로 나가지 않았다는 사실이 화면에 없다.
   *
   * @effects claim_payment_shows_the_mileage_credited_cash_share
   */
  it('마일리지로 지급한 현금분 행이 주문 집계를 읽는다', () => {
    expect(byId(paymentSection, 'cp_006_refunded_mileage_credit_{{payIdx}}').if).toBe(
      '{{(claim?.data?.order?.total_refunded_mileage_credit_amount ?? 0) > 0}}',
    );
  });

  /**
   * 수단을 「마일리지로 환불」로 고르는 자리와 실행 확인 창에서, 현금도 마일리지로 간다는 사실을
   * 알린다(D1). 표식만 남은 신청서에는 [환불하기]가 장부만 확정한다는 안내가 선다(§14-C).
   *
   * @effects claim_refund_execute_explains_points_cash_credit_and_pg_marker
   */
  it('마일리지로 환불 안내와 PG 표식 안내가 서버·선택 값을 읽는다', () => {
    expect(byId(paymentSection, 'cre_017_points_cash_credit_notice').if).toBe(
      "{{(_local.refundMethod ?? '') === 'points'}}",
    );
    expect(byId(paymentSection, 'cre_013c_pg_settled_pending_ledger_notice').if).toBe(
      '{{claim?.data?.claim?.pg_settled_pending_ledger === true}}',
    );
    expect(String(byId(refundExecuteModal, 'crm_010_points_cash_credit_notice').if)).toContain(
      "_global.claimRefundExecuteContext?.refundMethod === 'points'",
    );
  });
});

describe('이 클레임의 정산 (D18)', () => {
  /**
   * 환불 예정액은 합계다 — 가산 두 축이 없으면 산식이 닫히지 않아 운영자가 그 금액의 근거를
   * 화면에서 읽을 수 없다. 두 행은 서버가 재계산으로 만든 값을 그대로 읽는다(화면이 역산하면
   * 같은 금액의 두 번째 출처가 된다). 교환은 서버가 null 을 주어 그 자리를 그리지 않는다.
   *
   * @scenario claim_type=return, cancellable=absent
   * @effects claim_settlement_breaks_down_the_expected_refund
   */
  it('환불 예정액의 가산 두 축을 서버 값 그대로 그린다', () => {
    const product = byId(paymentSection, 'cst_008a_refund_target_amount');
    const shipping = byId(paymentSection, 'cst_008b_refund_shipping_amount');

    expect(product.children[1].text).toBe(
      "{{claim?.data?.settlement?.refund_product_amount_formatted ?? ''}}",
    );
    expect(shipping.children[1].text).toBe(
      "{{claim?.data?.settlement?.refund_shipping_amount_formatted ?? ''}}",
    );

    // 교환은 서버가 null 을 준다 — 조건이 없으면 빈 행이 남는다.
    expect(product.if).toContain('refund_product_amount_formatted != null');
    expect(shipping.if).toContain('refund_shipping_amount_formatted != null');

    // 화면이 합산·역산하면 두 번째 출처가 된다.
    const raw = JSON.stringify([product, shipping]);
    expect(raw).not.toMatch(/reduce\(|Number\(|toLocaleString/);
  });

  /**
   * 서버는 배송정책을 해석하지 못하면 예외 대신 0원 + 사유로 처리하고 로그만 남긴다 — 그
   * 사유가 화면에 없으면 정책을 지운 상점에서 청구액이 조용히 0원이 되고 운영자는 '청구 없음'
   * 으로 읽는다. 근거는 신청서가 동결한 스냅샷에서만 읽는다.
   *
   * @scenario claim_type=exchange, cancellable=absent
   * @effects claim_shipping_fee_basis_is_visible
   */
  it('배송비가 왜 이 금액인지의 근거를 스냅샷에서 읽어 알린다', () => {
    const note = byId(paymentSection, 'cst_002b_fee_basis_note');

    expect(note.if).toContain('shipping_fee_snapshot');
    expect(note.if).toContain('no_shipping_policy');
    expect(note.if).toContain('api_fallback');
    expect(note.if).toContain('live_policy');

    const text = note.children[0].text;
    expect(text).toContain('settlement.fee_basis_no_policy');
    expect(text).toContain('settlement.fee_basis_api_fallback');
    expect(text).toContain('settlement.fee_basis_live_policy');
  });

  /**
   * 허용 어휘는 운영자 설정이 정하고 그 조회는 `settings.read` 게이트다 — 화면이 셋을 다시
   * 적으면 상점이 막아 둔 방식이 선택 가능해지고 저장이 422 가 된다.
   *
   * @effects claim_charge_method_options_come_from_detail_response
   */
  it('청구 방식 셀렉트가 상세 응답의 목록을 읽는다', () => {
    expect(byId(paymentSection, 'cst_003_charge_method').props.options).toBe(
      "{{[{ value: '', label: $t('sirsoft-ecommerce.admin.claim.detail.settlement.charge_method_placeholder'), disabled: true }].concat(claim?.data?.charge_methods ?? [])}}",
    );
  });

  /**
   * 금액 수정 게이트는 `claims.update-amounts` 다 — 상태 전이 권한으로 잡으면 그 권한만 가진
   * 운영자에게 버튼은 살아 있고 저장만 403 이 된다.
   *
   * @effects claim_amount_controls_gate_on_update_amounts
   */
  it.each([
    ['cst_001_shipping_fee_input', 'disabled'],
    ['cst_003_charge_method', 'disabled'],
    ['cre_008_pg_amount', 'disabled'],
    ['cre_009_points_amount', 'disabled'],
    ['cre_010_refund_method', 'disabled'],
  ])('%s 가 금액 수정 권한으로 게이팅된다', (nodeId, prop) => {
    expect(byId(paymentSection, nodeId).props[prop]).toContain('can_update_amounts');
  });

  /**
   * 환불 예정액은 헤더 컬럼이 아니라 완료 시점 재계산 결과다 — 화면이 다시 계산하면 완료
   * 처리 모달이 보여 주는 숫자와 갈린다.
   *
   * @scenario claim_type=return, claim_stage=in_progress
   * @effects claim_settlement_preview_comes_from_server
   */
  it('완료 시 환불 예정이 서버가 준 정산 축을 읽는다', () => {
    expect(byId(paymentSection, 'cst_preview_block').if).toBe('{{!!claim?.data?.settlement}}');
    expect(byId(paymentSection, 'cst_009_expected_refund').children[1].text).toBe(
      "{{claim?.data?.settlement?.refund_amount_formatted ?? ''}}",
    );
  });

  /**
   * 접수 불가 사유 둘은 뜻이 다르다 — 이미 접수됐거나(다른 신청서가 맡았다), 애초에 환불
   * 대상이 없다. 감추면 0원 예고가 정상으로 보인다.
   *
   * @effects claim_settlement_blocked_reason_shown
   */
  it('접수 불가 사유를 두 가지로 구분해 알린다', () => {
    const notice = byId(paymentSection, 'cst_008_blocked_notice');

    expect(notice.text).toContain('claim_refund_request_exists');
    expect(notice.text).toContain('blocked_already_filed');
    expect(notice.text).toContain('blocked_not_applicable');
  });

  /**
   * 환불신청서에는 청구 축이 없고 교환·반품에는 환불 실행 축이 없다 — 반대로 그리면 누를
   * 수 있는 컨트롤이 언제나 실패한다.
   *
   * @scenario claim_type=refund, cancellable=present
   * @effects claim_settlement_blocks_split_by_type
   */
  it('정산 블록이 유형별로 갈린다', () => {
    expect(byId(paymentSection, 'cst_charge_block').if).toBe(`{{${SHIPPING_CONDITION}}}`);
    expect(byId(paymentSection, 'cst_refund_block').if).toBe(`{{${REFUND_CONDITION}}}`);
  });
});

describe('환불 실행 — 취소 가능 금액과 실행 (D18·D19)', () => {
  /**
   * 세 축과 최종 금액은 서버가 산정하고 서버가 표기한다 — 화면이 더하거나 기호를 붙이면
   * 실행 가드가 쓰는 값과 화면이 예고하는 값이 갈린다.
   *
   * @scenario claim_type=refund, cancellable=present
   * @effects claim_cancellable_axes_come_from_server
   */
  it('취소 가능 금액 3축과 합계가 서버 표기를 그대로 그린다', () => {
    const expected: Array<[string, string]> = [
      ['cre_001_domain_refundable', 'domain_refundable_formatted'],
      ['cre_002_payment_capacity', 'payment_capacity_formatted'],
      ['cre_004_reserved', 'reserved_formatted'],
    ];

    expected.forEach(([nodeId, field]) => {
      expect(byId(paymentSection, nodeId).children[1].text).toBe(
        `{{claim?.data?.cancellable?.${field} ?? ''}}`,
      );
    });

    expect(byId(paymentSection, 'cre_006_cancellable_amount').text).toBe(
      "{{claim?.data?.cancellable?.amount_formatted ?? ''}}",
    );
  });

  /**
   * 입력 옆의 수단별 잔여는 좌측 산정 카드와 **같은 서버 필드**를 읽어야 한다. 화면이 다시
   * 더하거나 골라 만들면 같은 금액의 두 번째 출처가 되어, 한쪽만 바뀌었을 때 한 화면 안에서
   * 두 숫자가 갈린다 — 값이 같아 보이는 동안은 오류도 남지 않는다.
   *
   * @scenario claim_type=refund, cancellable=present
   * @effects claim_refund_input_shows_per_method_remaining
   */
  it('환불액 입력 옆의 수단별 잔여가 산정 카드와 같은 서버 필드를 읽는다', () => {
    const cases: Array<[string, string, string]> = [
      ['cre_015_pg_method_limit_{{pgIdx}}', 'payment', 'pgCapacity'],
      ['cre_016_points_method_limit_{{ptIdx}}', 'mileage', 'ptCapacity'],
    ];

    cases.forEach(([nodeId, type, itemVar]) => {
      const node = byId(paymentSection, nodeId);

      expect(node.iteration?.source).toBe(
        `{{(claim?.data?.cancellable?.methods ?? []).filter(m => m.type === '${type}')}}`,
      );
      expect(node.iteration?.item_var).toBe(itemVar);
      expect(node.text).toContain(`${itemVar}.method_label`);
      expect(node.text).toContain(`${itemVar}.remaining_base_formatted`);
    });

    // 화면이 잔여를 다시 만들지 않는다 — 합산·재계산이 들어오면 그 순간 두 번째 출처가 된다.
    cases.forEach(([nodeId]) => {
      const text = byId(paymentSection, nodeId).text ?? '';

      expect(text).not.toMatch(/reduce\(|Number\(|\.map\(/);
    });
  });

  /**
   * 산정 불가와 "0원" 은 다른 사실이다 — 뭉뚱그리면 운영자가 되돌릴 것이 없다고 읽는다.
   *
   * @effects claim_cancellable_unavailable_distinguished_from_zero
   */
  it('취소 가능 금액을 산정하지 못한 경우를 따로 알린다', () => {
    expect(byId(paymentSection, 'cre_000_cancellable_unavailable').if).toBe('{{!claim?.data?.cancellable}}');
    expect(byId(paymentSection, 'cre_cancellable_body').if).toBe('{{!!claim?.data?.cancellable}}');
  });

  /**
   * 어휘는 서버가 준 목록만 쓴다 — 화면이 셋을 다시 적으면 수단이 늘어도 화면만 낡은 채로
   * 남는다.
   *
   * @effects claim_refund_method_options_come_from_detail_response
   */
  it('환불 수단 라디오가 상세 응답의 목록을 읽는다', () => {
    const radio = byId(paymentSection, 'cre_010_refund_method');

    expect(radio.props.options).toBe('{{claim?.data?.refund_methods ?? []}}');
    // 자동바인딩은 값 경로를 폼 상태에 맡긴다 — 로컬 상태를 읽는 이 화면에서는 끈다.
    expect(radio.props.autoBinding).toBe(false);
    expect(radio.props.value).toBe("{{_local.refundMethod ?? ''}}");
  });

  /**
   * 환불은 되돌릴 수 없는 처리라 곧바로 실행하지 않는다 — 버튼이 실행 핸들러를 직접 부르면
   * 오클릭 한 번이 곧 환불이다.
   *
   * @scenario claim_type=refund, cancellable=present
   * @effects claim_refund_execute_requires_confirm_modal
   */
  it('[환불하기] 가 확인 모달을 거친다', () => {
    const button = byId(paymentSection, 'cre_014_execute_button');
    const action = (button.actions as Node[])[0];

    expect(action.handler).toBe('sirsoft-ecommerce.openClaimRefundExecute');
    expect(button.props.disabled).toContain('can_execute_refund');
    // 실행 게이트는 금액 수정과 다른 권한이다 — 한쪽으로 묶으면 다른 쪽이 열리거나 잠긴다.
    expect(button.props.disabled).not.toContain('can_update_amounts');
  });

  /**
   * [환불하기]는 **승인된 건에서만** 눌린다 (F2).
   *
   * 권한만 보던 시절에는 `requested`(아직 승인 전)와 `completed`(이미 끝난 건)에서도 버튼이
   * 살아 있었다. 누르면 서버가 거절하므로 돈이 잘못 나가지는 않지만, 운영자는 눌리는 버튼을
   * 눌렀다가 원인 모를 오류를 받는다 — 상세에 다른 전이 수단이 없던 때에는 막다른 길이었다(F1).
   *
   * 서버측 계약은 `ClaimRefundExecutionTest::test_execute_requires_an_approved_refund` 가 잠근다.
   * 이 케이스는 **화면이 그 계약을 미리 보여 주는지**를 잰다 — 상태 축을 빼면 red 가 된다.
   *
   * @scenario claim_type=refund, cancellable=present
   * @effects claim_refund_execute_disabled_unless_approved
   */
  it('[환불하기] 가 승인 상태가 아니면 비활성이다 (F2)', () => {
    const button = byId(paymentSection, 'cre_014_execute_button');
    const disabled = String(button.props.disabled);

    expect(disabled).toContain('approved');
    expect(disabled).toContain('status');
  });

  /**
   * 계좌가 없으면 [환불하기] 가 잠긴다 — 계좌를 적거나 동의를 켜야 풀린다. (T2-D6)
   *
   * 2026-09-22 #599 3차 실측 L-8 — 이 창은 계좌를 **보여 주기만** 해서, 무통장으로 10,000원을
   * 받은 주문의 환불을 계좌 세 칸이 빈 채로 실행할 수 있었다. 돈은 한 푼도 나가지 않았는데
   * 신청서만 `completed` 로 닫혔고, 그 뒤에는 되돌릴 경로도 계좌를 적을 자리도 없었다.
   *
   * 서버측 계약은 `AdminRefundExecuteRequiresBankTest` 가 잠근다. 이 케이스는 **화면이 그
   * 계약을 미리 보여 주는지**를 잰다 — 세 조건 중 하나라도 빠지면 red 가 된다.
   *
   * @scenario claim_type=refund, cancellable=present
   * @effects claim_refund_execute_modal_gates_on_the_bank_account
   */
  it('확인 모달의 [환불하기] 가 계좌도 동의도 없으면 잠긴다 (T2-D6)', () => {
    const disabled = strings(refundExecuteModal).filter((v) => v.includes('claimRefundExecuteForce'));

    expect(disabled.length).toBeGreaterThan(0);
    const gate = disabled.join(' ');
    // 판정은 서버가 내려 준다 — 화면이 결제수단 코드로 재현하지 않는다.
    expect(gate).toContain('refundAccountRequired');
    expect(gate).toContain('claimRefundExecuteBankCode');
    expect(gate).toContain('claimRefundExecuteBankAccount');
    expect(gate).toContain('claimRefundExecuteBankHolder');
  });

  /**
   * 계좌를 **적을 자리**가 그 창에 있다. (T2-D6)
   *
   * 막기만 하고 적을 자리를 주지 않으면 그 건은 어느 화면에서도 끝낼 수 없다 — 클레임 상세에도
   * 주문 상세에도 환불 계좌 입력 요소가 하나도 없던 것이 L-8 의 절반이었다.
   *
   * @scenario claim_type=refund, cancellable=present
   * @effects claim_refund_execute_modal_gates_on_the_bank_account
   */
  it('확인 모달에 환불 계좌 입력 세 칸과 동의 체크박스가 있다 (T2-D6)', () => {
    const values = strings(refundExecuteModal).join(' ');

    expect(values).toContain('refund_bank_input_title');
    // 은행은 코드표 Select 로 고른다(P7 #15) — 빈 선택지 라벨이 은행 선택 안내다.
    expect(values).toContain('refund_bank_select');
    expect(values).toContain('refund_bank_account_placeholder');
    expect(values).toContain('refund_bank_holder_placeholder');
    // 동의는 결과를 말하는 힌트와 한 쌍이다 (주문 상세 `mark_order_complete` 와 같은 형태).
    expect(values).toContain('force_without_bank');
    expect(values).toContain('force_without_bank_hint');
  });

  /**
   * 모달은 부모 화면과 다른 컨텍스트라 `_local` 표현식을 해석하지 못한다 — 그대로 두면
   * 값이 비어 보이는데 오류는 나지 않는다.
   *
   * @effects claim_refund_modal_does_not_read_parent_local
   */
  it('확인 모달이 부모 화면의 `_local` 을 읽지 않는다', () => {
    strings(refundExecuteModal).forEach((value) => {
      expect(value).not.toContain('_local.');
    });
    expect(strings(refundExecuteModal).some((v) => v.includes('_global.claimRefundExecuteContext'))).toBe(
      true,
    );
  });

  /**
   * 저장과 실행은 다른 처리이며 게이트도 다르다 — 승인 직후 금액만 고쳐 두고 실행은 나중에
   * 하는 운영이 성립한다.
   *
   * @effects claim_refund_save_and_execute_are_separate
   */
  it('금액 저장과 환불 실행이 서로 다른 액션이다', () => {
    expect((byId(paymentSection, 'cre_013_save_amounts').actions as Node[])[0].handler).toBe(
      'sirsoft-ecommerce.saveClaimAmounts',
    );
    expect(byId(paymentSection, 'cre_013_save_amounts').props.disabled).toContain('can_update_amounts');
  });
});

describe('다국어 키 — ko/en 도달', () => {
  const messages = { ko: loadMessages('ko'), en: loadMessages('en') };

  /** 레이아웃이 쓰는 모듈 다국어 키 전수 (`$t:` 표기 + computed 의 `$t()` 호출) */
  const usedKeys = [
    ...new Set(
      DETAIL_FILES.flatMap(([, node]) =>
        strings(node).flatMap((value) => [
          ...[...value.matchAll(/\$t:sirsoft-ecommerce\.([A-Za-z0-9_.]+)/g)].map((m) => m[1]),
          ...[...value.matchAll(/\$t\('sirsoft-ecommerce\.([A-Za-z0-9_.]+)'\)/g)].map((m) => m[1]),
        ]),
      ),
    ),
  ].map((key) => key.replace(/\.$/, ''));

  /** @effects claim_detail_message_key_collection_is_not_empty */
  it('레이아웃이 쓰는 키를 최소 60종 이상 수집한다', () => {
    expect(usedKeys.length).toBeGreaterThanOrEqual(60);
  });

  /**
   * 키가 없으면 화면에 원문 키가 그대로 나온다 — 렌더는 성공하므로 오류가 남지 않는다.
   *
   * @effects claim_detail_i18n_keys_resolve_in_both_locales
   */
  it.each(['ko', 'en'])('%s 사전에 전 키가 실재한다', (locale) => {
    const missing = usedKeys.filter((key) => !hasMessage(messages[locale as 'ko' | 'en'], key));

    expect(missing).toEqual([]);
  });

  /**
   * 핸들러 폴백 문구가 붙은 키도 사전에 있어야 한다 — 폴백은 사전이 없을 때의 임시방편이지
   * 어휘의 두 번째 출처가 아니다.
   *
   * @effects claim_detail_handler_fallback_keys_resolve
   */
  it.each(['ko', 'en'])('%s 사전에 상세 핸들러가 쓰는 키도 실재한다', (locale) => {
    const source = fs.readFileSync(path.join(MODULE_ROOT, 'resources/js/handlers/claimHandlers.ts'), 'utf8');
    const keys = [...source.matchAll(/t\('sirsoft-ecommerce\.(admin\.claim\.detail\.[A-Za-z0-9_.]+)'/g)].map(
      (m) => m[1],
    );

    expect(keys.length).toBeGreaterThan(0);
    expect(keys.filter((key) => !hasMessage(messages[locale as 'ko' | 'en'], key))).toEqual([]);
  });
});

describe('입력 컴포넌트의 change 이벤트 경로 (브라우저 실측 회귀)', () => {
  /** 값을 `{ target: { value } }` 로 emit 하는 컴포넌트 — 엔진이 문서화한 합성 이벤트 계약 */
  const TARGET_VALUE_COMPONENTS = new Set(['Select', 'Input', 'Textarea', 'MultilingualInput']);

  /**
   * 이 화면의 모든 `change` 액션을 소유 컴포넌트와 함께 모은다.
   *
   * @returns [컴포넌트명, 액션] 쌍 배열
   */
  function changeActions(): Array<[string, Node]> {
    const pairs: Array<[string, Node]> = [];

    DETAIL_FILES.forEach(([, node]) => {
      walk(node, (n) => {
        if (typeof n.name !== 'string' || !Array.isArray(n.actions)) {
          return;
        }

        n.actions.forEach((action: Node) => {
          if (action?.type === 'change') {
            pairs.push([n.name as string, action]);
          }
        });
      });
    });

    return pairs;
  }

  /**
   * 모집단이 비면 이 검사가 공전한다 — 먼저 그것부터 막는다.
   *
   * @scenario claim_type=refund
   *
   * @effects claim_detail_change_actions_population_is_not_empty
   */
  it('change 액션을 실제로 수집한다', () => {
    expect(changeActions().length).toBeGreaterThan(0);
  });

  /**
   * `Select`·`Input` 의 change 액션은 **`$event.target.value`** 로 값을 읽는다.
   *
   * 브라우저 실측(#599 후속)이 찾은 결함이다. 신청서 상태 변경 바가 `{{$event.value}}` 로
   * 읽고 있었는데, 합성 컴포넌트는 `{ target: { value } }` 를 emit 하므로 그 경로는 언제나
   * `undefined` 다. 그러면 `_local.claimBulkStatus` 가 영영 비고, 그 값을 보는 [일괄 적용]
   * 버튼은 **영구히 비활성**이다 — 운영자는 상태를 고를 수는 있는데 적용할 수 없다.
   *
   * 이 실패는 어디에도 흔적을 남기지 않는다. 핸들러는 정상 실행되고(값만 빈다), 콘솔·네트워크
   * 어디에도 오류가 없으며, 렌더 테스트는 컨트롤이 그려지는 것만 보므로 계속 초록이다.
   * 엔진도 `ActionDispatcher` 주석에 같은 함정을 적어 두고 있다.
   *
   * @scenario claim_type=refund
   *
   * @effects claim_detail_change_actions_read_event_target_value
   */
  it('Select·Input 의 change 액션은 $event.target.value 로 읽는다', () => {
    const offenders = changeActions()
      .filter(([name]) => TARGET_VALUE_COMPONENTS.has(name))
      .filter(([, action]) => JSON.stringify(action.params ?? {}).includes('$event.value'))
      .map(([name, action]) => `${name}: ${JSON.stringify(action.params)}`);

    expect(offenders).toEqual([]);
  });

  /**
   * 그 액션들이 값을 **실제로 읽고 있는지**도 함께 본다.
   *
   * 위 검사만 두면 `$event.value` 를 지우고 리터럴을 넣어도 통과한다 — 그때는 상태가 늘 같은
   * 값으로 덮여 다른 방향으로 조용히 고장난다.
   *
   * @scenario claim_type=refund
   *
   * @effects claim_detail_change_actions_bind_a_value
   */
  it('Select·Input 의 change 액션이 이벤트 값을 바인딩한다', () => {
    const bound = changeActions()
      .filter(([name]) => TARGET_VALUE_COMPONENTS.has(name))
      .filter(([, action]) => JSON.stringify(action.params ?? {}).includes('$event.target.value'));

    expect(bound.length).toBeGreaterThan(0);
  });

  /**
   * 전이 바가 쓰는 상태 키가 **쓰는 쪽과 읽는 쪽에서 같다**.
   *
   * 실측에서 이 축이 두 번 어긋났다 — 핸들러 이름(엔진에 없는 `setLocalState`)과 이벤트 경로.
   * 둘 다 "쓰기만 조용히 실패" 라 읽는 쪽 바인딩은 멀쩡해 보인다. 그래서 키가 양쪽에 실재하는지를
   * 함께 못박는다.
   *
   * @scenario claim_type=refund
   *
   * @effects claim_detail_transition_bar_state_key_round_trips
   */
  it('전이 바의 상태 키가 쓰기·읽기 양쪽에 있다', () => {
    const select = byId(itemsSection as Node, 'cts_001_status_select');
    const apply = byId(itemsSection as Node, 'cts_003_apply');

    expect(JSON.stringify(select.actions)).toContain('claimBulkStatus');
    expect(JSON.stringify(select.actions)).toContain('$event.target.value');
    expect(JSON.stringify(select.props)).toContain('_local.claimBulkStatus');
    expect(JSON.stringify(apply.props)).toContain('_local.claimBulkStatus');
  });

  /**
   * 신청서 상태 변경 [일괄 적용] 도 저장 중 스피너를 띄운다 — 같은 화면의 금액·메모·송장·상품 일괄 버튼은
   * 모두 스피너를 켜는데 이 버튼만 잠기기만 해서, 응답을 기다리는 몇 초 동안 눌렸는지 알 수 없었다
   * (2026-10-07 실측: dblclick 직후 disabled=true, 스피너 요소 0). 스피너는 핸들러가 켜고 끄는
   * `_local.isTransitioningClaim` 을 그대로 읽는다.
   *
   * @scenario claim_type=return
   *
   * @effects claim_detail_transition_apply_shows_spinner
   */
  it('신청서 상태 변경 [일괄 적용] 이 처리 중 스피너를 띄운다', () => {
    const apply = byId(itemsSection as Node, 'cts_003_apply');
    const spinner = (apply.children ?? []).find((c: Node) => c.name === 'Icon' && c.props?.name === 'spinner') as Node | undefined;

    expect(spinner).toBeDefined();
    expect(String(spinner?.if ?? '')).toContain('_local.isTransitioningClaim');
  });

  /**
   * 엔진에 없는 핸들러 이름을 쓰지 않는다.
   *
   * `setLocalState` 는 레이아웃 편집기 스펙의 **별칭**일 뿐 `ActionDispatcher` 의 핸들러가
   * 아니다. 레이아웃에 그대로 적으면 커스텀 핸들러 분기로 떨어져 아무 일도 하지 않는다.
   *
   * @scenario claim_type=refund
   *
   * @effects claim_detail_uses_no_unknown_handler_alias
   */
  it('레이아웃이 setLocalState 를 쓰지 않는다', () => {
    const offenders = DETAIL_FILES.filter(([, node]) => JSON.stringify(node).includes('setLocalState')).map(
      ([name]) => name,
    );

    expect(offenders).toEqual([]);
  });

  /**
   * 첨부 업로더가 **순서 변경 엔드포인트를 비워 둔다**.
   *
   * 비우지 않으면 기본값인 코어 첨부 순서 API(`/api/admin/attachments/reorder`)로 나간다 —
   * 드래그 한 번이 **남의 첨부 순서**를 바꾸고, 이 화면에는 오류가 뜨지 않는다. 피해는
   * 상품·페이지 화면에서 나중에 발견된다.
   *
   * @effects claim_attachment_uploader_blocks_the_core_reorder_endpoint
   */
  it('첨부 업로더가 코어 첨부 API 로 새지 않는다', () => {
    const uploader = byId(reasonBlock as Node, 'cr_006_images_uploader');
    const endpoints = uploader.props?.apiEndpoints ?? {};

    expect(Object.keys(endpoints).sort()).toEqual(['delete', 'reorder', 'upload']);
    expect(endpoints.reorder).toBe('');
    expect(endpoints.upload).toContain('/api/modules/sirsoft-ecommerce/admin/');
    expect(endpoints.delete).toContain('/images/:id');
    expect(JSON.stringify(reasonBlock)).not.toContain('/api/admin/attachments');
  });

  /**
   * 업로더의 콜백이 **`event` 로 선언**된다.
   *
   * 관리자 템플릿의 액션 타입 표에는 `onXxx` 별칭이 없다 — `"type": "change"` 만 쓰면
   * 어느 콜백에도 붙지 않고, 화면에는 아무 증상이 없다.
   *
   * @effects claim_attachment_uploader_actions_declare_event_names
   */
  it('첨부 업로더의 액션이 event 이름을 명시한다', () => {
    const uploader = byId(reasonBlock as Node, 'cr_006_images_uploader');
    const actions = uploader.actions ?? [];

    expect(actions.length).toBeGreaterThan(0);
    for (const action of actions) {
      expect(typeof action.event).toBe('string');
      expect(action.event.startsWith('on')).toBe(true);
      expect(action.type).toBeUndefined();
    }
  });

  /**
   * 업로더가 **병렬 업로드를 막는다**.
   *
   * 서버는 개수 상한을 "조회 → 판정 → 저장" 으로 잠금 없이 판정한다 — 병렬이면 상한을
   * 넘겨 저장되고 정렬값이 겹치는데, 그 어느 쪽도 422 를 내지 않는다.
   *
   * @effects claim_attachment_uploader_serializes_uploads
   */
  it('첨부 업로더가 한 번에 한 건만 올린다', () => {
    const uploader = byId(reasonBlock as Node, 'cr_006_images_uploader');

    expect(uploader.props?.maxConcurrentUploads).toBe(1);
    expect(uploader.props?.autoUpload).toBe(true);
  });

  /**
   * 상한·허용 확장자를 **화면이 리터럴로 적지 않는다**.
   *
   * 적으면 운영자가 설정을 바꾼 순간 화면과 서버가 갈리고, 사용자에게는 이유 없는 422 만
   * 남는다. 특히 `image/*` 는 서버가 받지 않는 확장자를 고를 수 있게 한다.
   *
   * @effects claim_attachment_limits_come_from_the_server
   */
  it('첨부 상한·확장자를 서버 봉투에서 읽는다', () => {
    const uploader = byId(reasonBlock as Node, 'cr_006_images_uploader');
    const hint = byId(reasonBlock as Node, 'cr_005b_images_hint');

    for (const value of [uploader.props?.maxFiles, uploader.props?.maxSize, uploader.props?.accept]) {
      expect(String(value)).toContain('attachment_settings');
    }

    expect(JSON.stringify(hint)).toContain('attachment_settings');
    expect(JSON.stringify(reasonBlock)).not.toContain('image/*');
  });

  /**
   * 업로드는 컴포넌트가 한다 — 옛 핸들러 참조가 남지 않는다.
   *
   * @effects claim_attachment_upload_handler_is_gone
   */
  it('레이아웃에 uploadClaimImage 참조가 없다', () => {
    expect(JSON.stringify(reasonBlock)).not.toContain('uploadClaimImage');
    expect(JSON.stringify(reasonBlock)).not.toContain('isUploadingClaimImage');
  });

  /**
   * 첨부 출처 스트립이 **주체 미상(`unknown`)에는 뱃지를 그리지 않는다**.
   *
   * 라벨은 서버가 `unknown` 이면 null 로 내려 주고, 화면은 그 null 로 그리기를 멈춘다 —
   * 조용한 거짓("고객 첨부")보다 조용한 공백이 낫다.
   *
   * @effects claim_attachment_origin_strip_hides_unknown_badges
   */
  it('출처 스트립이 라벨 없는 첨부에는 뱃지를 그리지 않는다', () => {
    const strip = byId(reasonBlock as Node, 'cr_007_images_origin_list');
    const badges = collect(strip, (n) => n.name === 'Badge');

    expect(badges.length).toBeGreaterThan(0);
    for (const badge of badges) {
      expect(String(badge.if ?? '')).toContain('uploader_type_label');
    }
  });

  /**
   * 새로 쓴 첨부 문구가 ko/en 양쪽에 실재한다.
   *
   * @effects claim_attachment_messages_exist_in_both_locales
   */
  it('첨부 문구가 ko/en 양쪽에 있다', () => {
    const keys = [
      'admin.claim.detail.reason.images_hint',
      'admin.claim.detail.reason.images_origin_title',
      'admin.claim.detail.reason.image_origin_uploaded_at',
      'admin.claim.detail.reason.confirm_remove_image_title',
      'admin.claim.detail.reason.confirm_remove_image_message',
    ];

    for (const locale of ['ko', 'en']) {
      const messages = loadMessages(locale);
      for (const key of keys) {
        expect(hasMessage(messages, key), `${locale}: ${key}`).toBe(true);
      }
    }
  });
});

describe('#599 후속 — 막히는 입구 정리·반려 사유·탭 고정', () => {
  /**
   * 탭 막대는 헤더 묶음 **밖**에서 스크롤 영역 위에 붙는다 — 헤더 묶음 안에 두면 sticky 가
   * 부모 경계에 막혀 스크롤과 함께 사라지고, 긴 구역을 지나 탭으로 돌아가려면 끝까지 올려야 했다.
   *
   * @effects claim_detail_tab_nav_is_sticky_outside_header
   */
  it('탭 막대가 고정 클래스를 갖고 헤더 묶음 밖에 있다', () => {
    const nav = byId(shell, 'claim_tab_navigation');
    const header = byId(shell, 'claim_detail_header_section');

    expect(String(nav.props.className)).toContain('sticky-tab-nav');
    expect(collect(header, (n) => n.id === 'claim_tab_navigation')).toEqual([]);
  });

  /**
   * [완료 처리] 는 서버가 센 완료 가능 수로 켜지고, 안내 건수도 그 값이다 — 전체 상품 수를 적고
   * 늘 켜 두면 회수도착 전에도 눌려 모달까지 열린 뒤 거절된다.
   *
   * @effects claim_completion_button_follows_completable_count
   */
  it('완료 처리 버튼·건수가 completable_item_count 를 읽는다', () => {
    const button = byId(itemsSection, 'cmpl_002_button');
    const description = byId(itemsSection, 'cmpl_001_description');
    const unavailable = byId(itemsSection, 'cmpl_003_unavailable');

    expect(button.props.disabled).toContain('(claim?.data?.completable_item_count ?? 0) === 0');
    expect(description.text).toContain('count={{claim?.data?.completable_item_count ?? 0}}');
    expect(description.if).toBe('{{(claim?.data?.completable_item_count ?? 0) > 0}}');
    // 선교환 「미회수로 완료」 후보가 있거나 이미 끝난 신청서면 「완료할 수 없음」 안내를 세우지 않는다.
    expect(unavailable.if).toBe('{{(claim?.data?.completable_item_count ?? 0) === 0 && (claim?.data?.unreturned_eligible_count ?? 0) === 0 && claim?.data?.claim?.is_final !== true}}');
  });

  /**
   * 상품 칸 반려는 사유 입력칸을 열고, [적용] → 수량 모달 → 실행까지 사유가 이어진다.
   *
   * @effects claim_item_rejection_reason_is_wired_through_the_modal
   */
  it('상품 칸 반려 사유가 입력칸 → [적용] → 수량 모달로 이어진다', () => {
    const input = byId(itemsSection, 'ci_005_reject_reason');
    const apply = (byId(itemsSection, 'ci_004_item_bulk_apply').actions as Node[])[0];
    const confirm = collect(quantityModal, (n) => Array.isArray(n.actions)
      && (n.actions as Node[]).some((a) => a.handler === 'sirsoft-ecommerce.executeClaimItemBulkStatus'))[0];
    const execute = (confirm.actions as Node[]).find((a) => a.handler === 'sirsoft-ecommerce.executeClaimItemBulkStatus') as Node;

    expect(input.if).toBe("{{(_local.itemBulkStatus ?? '') === 'rejected'}}");
    expect(apply.params.rejectReason).toBe("{{_local.itemRejectReason ?? ''}}");
    expect(execute.params.rejectReason).toBe("{{_global.claimItemQuantityContext?.rejectReason ?? ''}}");
  });

  /**
   * 신청서 칸의 선택지는 서버가 거른 `transition_options` 만 그린다 — 화면이 완료·회수 단계를
   * 따로 더하거나 빼지 않는다(거절 판정의 두 번째 출처 금지).
   *
   * @effects claim_header_select_renders_server_options_only
   */
  it('신청서 칸 셀렉트가 서버 선택지만 그린다', () => {
    const select = byId(itemsSection, 'cts_001_status_select');

    expect(select.props.options).toContain('.concat(claim?.data?.transition_options ?? [])');
    expect(strings(itemsSection).some((value) => value.includes("'completed'"))).toBe(false);
  });
});

describe('#599 후속 — 회수중·발송중 송장 · 저장은 저장만', () => {
  /**
   * 회수중·발송중을 고르면 상품 칸에 택배사·운송장 입력이 열리고, 고르는 순간 신청서에 저장된 송장으로
   * 미리 채워지며(회수중은 회수 송장, 발송중은 재발송 송장), [적용] → 수량 모달 → 실행까지 송장이 이어진다.
   *
   * @effects claim_item_tracking_is_wired_through_the_modal
   */
  it('상품 칸 송장이 입력칸 → [적용] → 수량 모달로 이어진다', () => {
    const row = byId(itemsSection, 'ci_006_tracking_row');
    const carrier = byId(itemsSection, 'ci_007_carrier');
    const tracking = byId(itemsSection, 'ci_008_tracking_number');
    const statusChange = (byId(itemsSection, 'ci_002_item_status').actions as Node[])[0];
    const apply = (byId(itemsSection, 'ci_004_item_bulk_apply').actions as Node[])[0];
    const confirm = collect(quantityModal, (n) => Array.isArray(n.actions)
      && (n.actions as Node[]).some((a) => a.handler === 'sirsoft-ecommerce.executeClaimItemBulkStatus'))[0];
    const execute = (confirm.actions as Node[]).find((a) => a.handler === 'sirsoft-ecommerce.executeClaimItemBulkStatus') as Node;

    // 「송장만 저장」 모드에서도 같은 칸이 열린다(R7).
    expect(row.if).toBe("{{['collecting', 'redelivering'].includes(_local.itemBulkStatus ?? '') || _local.itemTrackingMode === true}}");
    expect(carrier.props.options).toContain('.concat(claim?.data?.carriers ?? [])');
    expect(tracking.props.maxLength).toBe(50);
    // 미리 채움은 고른 **행들의** 공통 송장이다 — 핸들러가 행 목록을 받아 판정한다(P1).
    expect(statusChange.handler).toBe('sirsoft-ecommerce.selectClaimItemBulkStatus');
    expect(statusChange.params.rows).toBe('{{claim?.data?.claim?.items ?? []}}');
    expect(apply.params.carrierId).toBe("{{_local.itemCarrierId ?? ''}}");
    expect(apply.params.trackingNumber).toBe("{{_local.itemTrackingNumber ?? ''}}");
    expect(execute.params.trackingNumber).toBe("{{_global.claimItemQuantityContext?.trackingNumber ?? ''}}");
    expect(execute.params.carrierId).toBe("{{_global.claimItemQuantityContext?.carrierId ?? ''}}");
  });

  /**
   * 배송 정보 카드의 운송장 입력은 DB 칸 길이(50)까지만 받는다 — 100자를 받으면 51자부터 저장이 서버 오류로 끊겼다.
   *
   * @effects claim_tracking_inputs_are_capped_at_the_column_length
   */
  it('배송 정보 카드 운송장 입력이 50자로 막힌다', () => {
    // 운송장 입력은 상품 칸 선택 바 하나다(#599) — 그 칸이 DB 칸 길이(50)로 막힌다.
    expect(byId(itemsSection, 'ci_008_tracking_number').props.maxLength).toBe(50);
  });
});

describe('#599 상품 행 송장 — 진행 방식(선교환) 줄', () => {
  /**
   * 쇼핑몰 설정에서 선교환이 꺼져 있어도 [선교환으로 전환] 은 사라지지 않고 **꺼진 채** 선다 — 막힌 이유는
   * 버튼 옆 문구(`blocked_reason`)가 알린다. 버튼을 숨기면 운영자는 기능이 있는지조차 모른다(계획 §3-9).
   *
   * @effects exchange_mode_button_stays_visible_and_disabled_with_reason
   */
  it('[선교환으로 전환] 은 설정과 무관하게 서고 능력으로만 꺼진다', () => {
    const setAdvance = byId(shell, 'cd_016_set_advance');
    const blocked = byId(shell, 'cd_018_mode_blocked');

    expect(setAdvance.if).toBe('{{claim?.data?.claim?.is_advance_exchange !== true}}');
    expect(String(setAdvance.props.disabled)).toContain('exchange_mode_abilities?.can_set_advance !== true');
    expect(String(setAdvance.props.className)).toContain('disabled:opacity-50');
    expect(blocked.if).toBe('{{!!claim?.data?.exchange_mode_abilities?.blocked_reason}}');
  });
});

describe('#599 직권 접수 — 머리의 운영자 이름', () => {
  /**
   * 판매자 접수 뱃지 곁에 접수한 운영자, 대리 철회된 건에는 철회한 운영자 이름이 선다(계획 §3-11). 이름은
   * 관리자 상세 응답에만 있으므로 화면은 그 필드가 있을 때만 그린다.
   *
   * @effects admin_detail_names_the_filing_and_withdrawing_operator
   */
  it('판매자 접수 건은 접수 운영자, 대리 철회 건은 철회 운영자 이름을 머리에 둔다', () => {
    const requested = byId(shell, 'cd_008c_requested_by_admin');
    const withdrawn = byId(shell, 'cd_008d_withdrawn_by_admin');

    expect(requested.if).toBe("{{claim?.data?.claim?.request_source === 'admin' && !!claim?.data?.claim?.requested_by_admin_name}}");
    expect(JSON.stringify(requested)).toContain('admin.claim.detail.requested_by_admin|name={{claim?.data?.claim?.requested_by_admin_name}}');
    expect(withdrawn.if).toBe('{{!!claim?.data?.claim?.withdrawn_by_admin_name}}');
    expect(JSON.stringify(withdrawn)).toContain('admin.claim.detail.withdrawn_by_admin|name={{claim?.data?.claim?.withdrawn_by_admin_name}}');
  });
});
