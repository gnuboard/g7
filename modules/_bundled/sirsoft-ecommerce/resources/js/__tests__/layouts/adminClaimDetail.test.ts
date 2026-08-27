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
  ['activity_log', activityLog as Node],
];

/** 배송 축의 분기 조건 — 셸의 탭 배열과 섹션 `if` 가 **문자 그대로** 공유해야 한다 (D17-①) */
const SHIPPING_CONDITION = "['exchange', 'return'].includes(claim?.data?.claim?.claim_type ?? '')";

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

  it('탭 콘텐츠 영역이 세 partial 을 순서대로 싣는다', () => {
    expect((byId(shell, 'claim_tab_content_area').children as Node[]).map((n) => n.partial)).toEqual([
      'partials/admin_ecommerce_claim_detail/_partial_claim_items.json',
      'partials/admin_ecommerce_claim_detail/_partial_claim_shipping.json',
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
  it('탭 id 세 종과 섹션 id 세 종이 1:1 이다', () => {
    const tabIds = [...tabsExpression.matchAll(/id:\s*'([a-z_]+)'/g)].map((m) => m[1]);
    const sectionIds = DETAIL_FILES.flatMap(([, node]) =>
      collect(node, (n) => typeof n.props?.['data-section-id'] === 'string').map(
        (n) => n.props['data-section-id'] as string,
      ),
    );

    expect(tabIds).toEqual(['claim_info', 'shipping_info', 'activity_log']);
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
   * 결제 정보·환불 실행 탭은 그 섹션을 만드는 세션(S9b)이 **탭과 함께** 더한다 — 지금 탭만
   * 세워 두면 그 사이 클릭이 아무 일도 하지 않는다.
   *
   * @effects claim_detail_defers_payment_tab_to_owner_session
   */
  it('아직 섹션이 없는 결제·환불 실행 탭을 미리 세워 두지 않았다', () => {
    expect(tabsExpression).not.toContain('payment_info');
    expect(tabsExpression).not.toContain('refund_execute');
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
    ['cs_004_collection_carrier', 'cs_014_redelivery_carrier'].forEach((id) => {
      expect(byId(shippingSection, id).props.options).toBe('{{claim?.data?.carriers ?? []}}');
    });
  });
});

describe('신청상품 — 일괄 변경 바와 표 (D24·D25)', () => {
  /**
   * 어휘가 부분집합이면 지정할 수 있어야 할 상태가 화면에서 사라지고, 초과집합이면 그 값을
   * 고르는 순간 요청 전체가 422 가 된다 — 표는 그대로 남아 실패가 안 보인다.
   *
   * @scenario claim_type=exchange, claim_stage=in_progress
   * @effects claim_item_status_vocabulary_matches_gate
   */
  it.each([
    ['exchange', 'ci_002_item_status_exchange'],
    ['return', 'ci_003_item_status_return'],
  ])('%s 상품 단위 셀렉트 어휘가 게이트와 같은 집합이다', (claimType, nodeId) => {
    const options = (byId(itemsSection, nodeId).props.options as Node[])
      .map((o) => o.value)
      .filter((v: string) => v !== '');

    expect(options).toEqual(optionAssignableValues(claimType));
    expect(options).not.toContain('payment_pending');
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
  });

  /**
   * 환불에는 상품 단위 전이 축이 없다 — 바를 그리면 누를 수 있는 버튼이 언제나 실패다.
   *
   * @scenario claim_type=refund, claim_stage=in_progress
   * @effects claim_item_bulk_bar_absent_for_refund
   */
  it('상품 단위 일괄 바가 교환·반품에서만 그려진다', () => {
    expect(byId(itemsSection, 'claim_item_bulk_bar').if).toBe(`{{${SHIPPING_CONDITION}}}`);
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
