/**
 * @file userOrderClaimSection.test.tsx
 * @description 회원 주문상세 '이 주문의 클레임' 섹션 — 셸 계약 + 신청번호 링크 + 썸네일 자리표시 (#599)
 *
 * 이 섹션은 회원 신청내역·비회원 주문상세와 **같은 partial**(`_list.json`)이다. 회원 주문상세 셸이
 * 더 세우는 것은 하나 — `claimDetailLinkMode: new_window`. 그 값이 서면 신청번호 자체가 새 창
 * 상세 링크가 되고 푸터 [상세보기]는 서지 않는다. 신청내역 목록 화면은 그 값을 세우지 않으므로
 * 종전 모양(글자 번호 + 푸터 버튼)이 그대로다 — 두 상태를 같은 partial 로 한 번에 잰다.
 *
 * 어긋나도 화면은 정상으로 보인다 — 번호가 글자로만 남거나, 목록 화면에서 [상세보기]가 사라져도
 * 오류도 토스트도 남지 않는다.
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, afterEach } from 'vitest';
import { screen, fireEvent } from '@testing-library/react';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

type Node = Record<string, any>;

const LAYOUTS = path.resolve(__dirname, '../../layouts');

function readLayout(rel: string): Node {
  return JSON.parse(fs.readFileSync(path.join(LAYOUTS, rel), 'utf-8'));
}

/**
 * 트리를 순회하며 방문자를 부른다.
 *
 * @param node 시작 노드
 * @param visit 방문자
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

function setupRegistry() {
  const registry = createMockComponentRegistryWithBasics();

  registry.register('basic', 'Icon', ({ className, name }: any) =>
    React.createElement('i', { className, 'data-icon': name })
  );
  registry.register('composite', 'Pagination', ({ currentPage, totalPages }: any) =>
    React.createElement('div', {
      'data-testid': 'pagination',
      'data-current': String(currentPage ?? ''),
      'data-total': String(totalPages ?? ''),
    })
  );

  return registry;
}

/** 회원 주문상세 셸이 `_list.json` 에게 주는 것만 갖춘 최소 레이아웃. */
function sectionLayout(): Node {
  return {
    data_sources: [
      {
        id: 'claims',
        type: 'api',
        endpoint: '/api/modules/sirsoft-ecommerce/user/orders/990120/claims',
        method: 'GET',
        auto_fetch: true,
      },
    ],
    components: [readLayout('partials/mypage/claims/_list.json')],
  };
}

const TRANSLATIONS = {
  claim: {
    list: {
      tab_exchange: '교환',
      tab_return: '반품',
      tab_refund: '환불',
      tab_cancel: '취소',
      requested_at: '{{at}} 신청',
      order_number: '주문번호 {{number}}',
      edit: '수정',
      withdraw: '철회',
      detail: '상세보기',
      col_items: '신청상품',
      col_reason: '사유',
      col_tracking: '배송조회',
      col_amount: '금액',
      collection: '회수',
      redelivery: '재발송',
      round_trip_shipping_fee: '왕복 배송비',
      collection_shipping_fee: '회수 배송비',
      additional_product_amount: '추가 상품금액',
      refund_product_amount: '환불 상품금액',
      refund_amount: '환불액',
      total_charge: '총 청구액',
      empty: '신청 내역이 없습니다.',
      go_orders: '주문내역 보기',
    },
  },
  shop: { count_unit: '개' },
};

/** 서버 응답(ClaimCollection 목록 표현)의 형태를 그대로 흉내낸다. */
function returnClaim(thumbnailUrl: string | null = 'https://example.test/bag.jpg'): Node {
  return {
    claim_number: 'RT20260901-0003',
    claim_type: 'return',
    claim_type_label: '반품',
    status: 'requested',
    status_label: '반품신청',
    status_variant: 'info',
    order_number: '20260901-0007',
    requested_at_formatted: '2026-09-01 14:20',
    reason_label: '단순 변심',
    is_editable: true,
    is_withdrawable: true,
    items: [
      {
        product_name: '가죽 크로스백',
        product_option_name: '블랙 / M',
        quantity: 1,
        subtotal_amount_formatted: '39,000원',
        thumbnail_url: thumbnailUrl,
      },
    ],
  };
}

/** 회원 주문상세 셸이 세우는 계약값 (initLocal + init_actions 의 결과). */
const MEMBER_ORDER_LOCALS = {
  isGuest: false,
  withdrawRefreshDataSourceId: 'claims',
  claimDetailLinkMode: 'new_window',
  claimWithdrawApiBase: '/api/modules/sirsoft-ecommerce/user/claims',
  claimListPath: '/mypage/orders/20260901-0007',
  claimDetailPathBase: '/mypage/claims',
  claimEmptyCtaPath: '/mypage/orders',
};

/** 신청내역 목록 화면(/mypage/claims)이 세우는 계약값 — 링크 모드가 없다. */
const CLAIM_LIST_LOCALS = {
  isGuest: false,
  withdrawRefreshDataSourceId: 'claims',
  claimWithdrawApiBase: '/api/modules/sirsoft-ecommerce/user/claims',
  claimListPath: '/mypage/claims',
  claimDetailPathBase: '/mypage/claims',
  claimEmptyCtaPath: '/mypage/orders',
};

function makeTest(locals: Record<string, any>, rows: Node[]) {
  const test = createLayoutTest(sectionLayout() as any, {
    componentRegistry: setupRegistry(),
    translations: TRANSLATIONS,
    queryParams: { type: 'return', page: '1' },
    initialState: { _local: locals },
  });

  test.mockApi('claims', {
    response: {
      data: {
        data: rows,
        pagination: {
          current_page: 1,
          per_page: 20,
          last_page: 1,
          total: rows.length,
          has_more_pages: false,
        },
      },
    },
  });

  return test;
}

describe('회원 주문상세 셸 — 클레임 섹션 계약 (구조)', () => {
  const shell = readLayout('mypage/orders/show.json');
  const guestShell = readLayout('shop/guest_order_show.json');

  // @scenario surface=member, has_image=yes
  // @effects member_order_detail_declares_claim_section_contract
  it('initLocal 이 공유 partial 의 계약값을 전부 세우고 링크 모드는 새 창이다', () => {
    expect(shell.initLocal.isGuest).toBe(false);
    expect(shell.initLocal.withdrawRefreshDataSourceId).toBe('claims');
    expect(shell.initLocal.claimDetailLinkMode).toBe('new_window');
    expect(shell.initLocal.claimWithdrawApiBase).toBe('/api/modules/sirsoft-ecommerce/user/claims');
    expect(shell.initLocal.claimDetailPathBase).toBe('/mypage/claims');
    expect(shell.initLocal.claimEmptyCtaPath).toBe('/mypage/orders');

    // 주문번호가 들어가는 값은 initLocal 이 해석하지 못하므로 init_actions 가 세운다 (비회원 셸과 같은 규칙)
    const seeds = (shell.init_actions ?? []).flatMap((a: Node) => (a.handler === 'setState' ? [a.params] : []));
    expect(seeds.some((p: Node) => String(p.claimListPath).includes('/mypage/orders/'))).toBe(true);
  });

  // @scenario surface=guest, has_image=yes
  // @effects guest_order_detail_number_navigates_in_the_same_window
  it('비회원 셸은 같은 창 모드다 — 조회 토큰이 탭에 묶여 새 창에 실리지 않는다', () => {
    expect(guestShell.initLocal.claimDetailLinkMode).toBe('same_window');
  });

  // @scenario surface=member, has_image=yes
  // @effects member_order_detail_mounts_the_shared_claim_list
  it('공유 목록 partial 과 철회 모달을 마운트하고, 목록 데이터소스는 주문 응답 뒤 체인에서 부른다', () => {
    const partials: string[] = [];
    walk(shell.components ?? shell.slots ?? shell, (n) => {
      if (typeof n.partial === 'string') partials.push(n.partial);
    });
    expect(partials).toContain('partials/mypage/claims/_list.json');
    expect((shell.modals ?? []).map((m: Node) => m.partial)).toContain('partials/mypage/claims/_modal_withdraw.json');

    const claims = (shell.data_sources ?? []).find((d: Node) => d.id === 'claims');
    expect(claims).toBeDefined();
    expect(claims.auto_fetch).toBe(false);
    expect(String(claims.endpoint)).toContain('/user/orders/{{_local.claimOrderId}}/claims');
    // 주소에 유형이 없으면 type 을 비워 보낸다 — 서버가 접수 건이 있는 첫 유형을 고른다. 'exchange' 를
    // 박으면 그 판정이 영영 돌지 않는다.
    expect(claims.params).toEqual({ type: "{{_local.claimsType ?? query.type ?? ''}}", page: '{{_local.claimsPage ?? query.page ?? 1}}' });

    // 주문 응답 → claimOrderId → claimable 과 claims 를 같은 sequence 에서 부른다
    const order = (shell.data_sources ?? []).find((d: Node) => d.id === 'order');
    const refetched: string[] = [];
    walk(order.onSuccess, (n) => {
      if (n.handler === 'refetchDataSource') refetched.push(n.params?.dataSourceId);
    });
    expect(refetched).toEqual(['claimable', 'claims']);
  });
});

describe('회원 주문상세 클레임 섹션 — 기본 서브탭 (서버가 고른 유형)', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  /** 활성(굵게·밑줄 진한) 서브탭의 라벨 */
  function activeTabs(): string[] {
    return [...document.querySelectorAll('button')]
      .filter((b) => b.className.includes('border-gray-900'))
      // 라벨만 본다 — 탭에는 건수 배지(#599 후속 F12)가 함께 실려 textContent 끝에 숫자가 붙는다.
      .map((b) => (b.querySelector('span')?.textContent ?? b.textContent ?? '').trim());
  }

  function makeDefaultTypeTest(queryParams: Record<string, string>, claimType: string) {
    const test = createLayoutTest(sectionLayout() as any, {
      componentRegistry: setupRegistry(),
      translations: TRANSLATIONS,
      queryParams,
      initialState: { _local: MEMBER_ORDER_LOCALS },
    });
    const rows = [returnClaim()];
    test.mockApi('claims', {
      response: {
        data: {
          data: rows,
          claim_type: claimType,
          counts: { exchange: 0, return: 1, refund: 0, cancel: 0 },
          pagination: { current_page: 1, per_page: 20, last_page: 1, total: rows.length, has_more_pages: false },
        },
      },
    });

    return test;
  }

  // @scenario surface=member, has_image=yes
  // @effects order_claim_section_active_subtab_follows_server_default
  it('주소에 유형이 없으면 응답의 claim_type 이 활성 서브탭이다 (취소만 있는 주문 → 취소가 먼저 열린다)', async () => {
    const test = makeDefaultTypeTest({ page: '1' }, 'return');
    await test.render();

    // 탭 라벨은 표현식 안 $t() 라 하네스가 키를 그대로 남긴다 — 활성 판정만 본다
    expect(activeTabs()).toEqual(['claim.list.tab_return']);
    expect(screen.getByText('RT20260901-0003')).toBeInTheDocument();

    test.cleanup();
  });

  // @scenario surface=member, has_image=no
  // @effects order_claim_section_address_type_beats_server_default
  it('주소의 유형이 있으면 그것이 우선이다 — 응답 claim_type 과 달라도 주소가 단일 출처다', async () => {
    const test = makeDefaultTypeTest({ type: 'exchange', page: '1' }, 'return');
    await test.render();

    expect(activeTabs()).toEqual(['claim.list.tab_exchange']);

    test.cleanup();
  });

  // @scenario surface=member, has_image=no
  // @effects member_order_detail_sends_no_type_when_address_has_none
  it('셸의 목록 데이터소스는 주소에 유형이 없을 때 type 을 비워 보낸다 (서버 판정 위임)', () => {
    const shell = readLayout('mypage/orders/show.json');
    const claims = (shell.data_sources ?? []).find((d: Node) => d.id === 'claims');
    expect(claims.params.type).toBe("{{_local.claimsType ?? query.type ?? ''}}");
    expect(String(claims.comment)).toContain('claim_type');
  });
});

describe('회원 주문상세 클레임 섹션 — 렌더', () => {
  let active: ReturnType<typeof createLayoutTest> | null = null;

  afterEach(() => {
    active?.cleanup();
    active = null;
  });

  // @scenario surface=member, has_image=yes
  // @effects member_order_detail_number_opens_detail_in_new_window
  it('신청번호가 새 창 링크이고 푸터 [상세보기]는 서지 않는다', async () => {
    active = makeTest(MEMBER_ORDER_LOCALS, [returnClaim()]);
    await active.render();

    const link = document.querySelector('a[target="_blank"]');
    expect(link).not.toBeNull();
    expect(link?.textContent).toContain('RT20260901-0003');
    expect(link?.getAttribute('href')).toBe('/mypage/claims/RT20260901-0003');
    expect(link?.getAttribute('rel')).toContain('noopener');

    expect(screen.queryByText('상세보기')).toBeNull();
    // 카드의 나머지는 회원 신청내역과 같다
    expect(screen.getByText('반품신청')).toBeInTheDocument();
    expect(screen.getByText('가죽 크로스백')).toBeInTheDocument();
  });

  // @scenario surface=member, has_image=yes
  // @effects claim_list_page_keeps_text_number_and_footer_button
  it('링크 모드가 없는 신청내역 목록 화면은 번호가 글자이고 [상세보기]가 같은 창으로 간다', async () => {
    active = makeTest(CLAIM_LIST_LOCALS, [returnClaim()]);
    await active.render();

    expect(document.querySelector('a[target="_blank"]')).toBeNull();
    expect(screen.getByText('RT20260901-0003')).toBeInTheDocument();

    fireEvent.click(screen.getByText('상세보기'));

    const history = active.getNavigationHistory();
    expect(history.some((p) => p.startsWith('/mypage/claims/RT20260901-0003'))).toBe(true);
  });

  // @scenario surface=member, has_image=no
  // @effects order_claim_section_thumbnail_placeholder_when_missing
  it('이미지 없는 상품에는 자리표시 아이콘이 서고, 있으면 썸네일이 선다', async () => {
    active = makeTest(MEMBER_ORDER_LOCALS, [returnClaim(null)]);
    await active.render();

    expect(document.querySelector('[data-icon="image"]')).not.toBeNull();
    expect(document.querySelector('img')).toBeNull();
    active.cleanup();

    active = makeTest(MEMBER_ORDER_LOCALS, [returnClaim('https://example.test/bag.jpg')]);
    await active.render();

    expect(document.querySelector('img')?.getAttribute('src')).toBe('https://example.test/bag.jpg');
    expect(document.querySelector('[data-icon="image"]')).toBeNull();
  });

  // @scenario surface=member, has_image=yes
  // @effects member_order_detail_declares_claim_section_contract
  it('빈 목록의 나가는 버튼은 회원 주문 내역이다', async () => {
    active = makeTest(MEMBER_ORDER_LOCALS, []);
    await active.render();

    expect(screen.getByText('신청 내역이 없습니다.')).toBeInTheDocument();

    fireEvent.click(screen.getByText('주문내역 보기'));

    expect(active.getNavigationHistory()).toContain('/mypage/orders');
  });
});
