/**
 * @file guestOrderClaimSection.test.tsx
 * @description 비회원 주문상세 '이 주문의 클레임' 섹션 렌더 회귀 (#599 S14)
 *
 * 이 섹션은 회원 신청내역과 **같은 partial**(`_list.json`)이다 — 다른 것은 셸이 세운 주소뿐이다.
 * 형제 계약 테스트(`guestClaimShellContract`)가 그 배선을 구조로 재는 데 반해, 이 파일은
 * 표현식이 **실제로 해석되는지**를 잰다: 셸이 준 주소 조각이 이어붙어 비회원 경로가 되는가.
 *
 * 어긋나도 화면은 정상으로 보인다 — 카드는 그대로 그려지고, 눌렀을 때 비로소 회원 전용 주소로
 * 가 로그인 화면이 열린다(오류도 토스트도 남지 않는다).
 *
 * @scenario claim_type=return, entry=with_token, screen=order_section
 * @effects guest_order_claim_section_links_to_the_guest_routes
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

/** 비회원 주문상세 셸이 `_list.json` 에게 주는 것만 갖춘 최소 레이아웃. */
function sectionLayout(): any {
  const partial = JSON.parse(
    fs.readFileSync(
      path.resolve(__dirname, '../../layouts/partials/mypage/claims/_list.json'),
      'utf-8'
    )
  );

  return {
    data_sources: [
      {
        id: 'claims',
        type: 'api',
        endpoint: '/api/modules/sirsoft-ecommerce/guest/orders/20260901-0007/claims',
        method: 'GET',
        auto_fetch: true,
      },
    ],
    components: [partial],
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
      go_orders: '쇼핑 계속하기',
      tracking_more: '외 {{more}}개',
    },
  },
  shop: { count_unit: '개' },
};

/** 서버 응답(ClaimCollection 목록 표현)의 형태를 그대로 흉내낸다. */
const RETURN_CLAIM = {
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
      thumbnail_url: 'https://example.test/bag.jpg',
    },
  ],
};

/** 비회원 셸이 세우는 계약값 — 주문 스코프가 붙는다. */
const GUEST_LOCALS = {
  isGuest: true,
  withdrawRefreshDataSourceId: 'claims',
  // 비회원 조회 토큰은 탭에 묶여(sessionStorage) 새 창에 실리지 않는다 — 번호는 같은 창에서 상세로 간다.
  claimDetailLinkMode: 'same_window',
  claimWithdrawApiBase: '/api/modules/sirsoft-ecommerce/guest/orders/20260901-0007/claims',
  claimListPath: '/shop/guest/orders/20260901-0007',
  claimDetailPathBase: '/shop/guest/claims',
  claimEmptyCtaPath: '/shop/products',
};

function makeTest(locals: Record<string, any> = GUEST_LOCALS, rows: any[] = [RETURN_CLAIM]) {
  const test = createLayoutTest(sectionLayout(), {
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

describe('비회원 주문상세 클레임 섹션 — 기본 서브탭 (서버가 고른 유형)', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  // @scenario claim_type=return, entry=with_token, screen=order_section
  // @effects guest_order_detail_sends_no_type_when_address_has_none
  it('비회원 셸도 주소에 유형이 없을 때 type 을 비워 보내고, 응답의 claim_type 이 활성 서브탭이다', async () => {
    const shell = JSON.parse(
      require('node:fs').readFileSync(path.resolve(__dirname, '../../layouts/shop/guest_order_show.json'), 'utf8'),
    );
    const claims = (shell.data_sources ?? []).find((d: any) => d.id === 'claims');
    expect(claims.params.type).toBe("{{_local.claimsType ?? query.type ?? ''}}");

    const test = createLayoutTest(sectionLayout(), {
      componentRegistry: setupRegistry(),
      translations: TRANSLATIONS,
      queryParams: { page: '1' },
      initialState: { _local: GUEST_LOCALS },
    });
    test.mockApi('claims', {
      response: {
        data: {
          data: [RETURN_CLAIM],
          claim_type: 'return',
          counts: { exchange: 0, return: 1, refund: 0, cancel: 0 },
          pagination: { current_page: 1, per_page: 20, last_page: 1, total: 1, has_more_pages: false },
        },
      },
    });
    await test.render();

    const active = [...document.querySelectorAll('button')]
      .filter((b) => b.className.includes('border-gray-900'))
      // 라벨만 본다 — 탭에는 건수 배지(#599 후속 F12)가 함께 실려 textContent 끝에 숫자가 붙는다.
      .map((b) => (b.querySelector('span')?.textContent ?? b.textContent ?? '').trim());
    // 탭 라벨은 표현식 안 $t() 라 하네스가 키를 그대로 남긴다 — 활성 판정만 본다
    expect(active).toEqual(['claim.list.tab_return']);

    test.cleanup();
  });
});

describe('비회원 주문상세 클레임 섹션 — 같은 카드가 비회원 주소로 이어진다', () => {
  let active: ReturnType<typeof createLayoutTest> | null = null;

  afterEach(() => {
    active?.cleanup();
    active = null;
  });

  /** @effects guest_claim_cards_keep_shared_values */
  it('신청서 카드가 회원 화면과 같은 값을 그린다', async () => {
    active = makeTest();
    await active.render();

    expect(screen.getByText('RT20260901-0003')).toBeInTheDocument();
    expect(screen.getByText('반품신청')).toBeInTheDocument();
    expect(screen.getByText('가죽 크로스백')).toBeInTheDocument();
    expect(screen.getByText('단순 변심')).toBeInTheDocument();
  });

  // @scenario claim_type=return, entry=with_token, screen=order_section
  // @effects guest_order_claim_section_links_to_the_guest_routes
  // @scenario surface=guest, has_image=yes
  // @effects guest_order_detail_number_navigates_in_the_same_window
  it('신청번호가 같은 창에서 비회원 신청서 주소로 간다 (셸이 준 앞부분이 실제로 이어붙는다)', async () => {
    active = makeTest();
    await active.render();

    // 번호가 곧 링크라 푸터 [상세보기] 는 서지 않고, 새 창 링크(<a target=_blank>)도 아니다 — 토큰이 탭에 묶여 있다.
    expect(screen.queryByText('상세보기')).toBeNull();
    expect(document.querySelector('a[target="_blank"]')).toBeNull();

    fireEvent.click(screen.getByText('RT20260901-0003'));

    const history = active.getNavigationHistory();
    expect(history.some((p) => p.startsWith('/shop/guest/claims/RT20260901-0003'))).toBe(true);
    // 회원 전용 주소로 새지 않는다 — 그 주소는 로그인 화면을 연다.
    expect(history.some((p) => p.includes('/mypage/'))).toBe(false);
  });

  // @scenario claim_type=return, entry=with_token, screen=edit
  // @effects guest_order_claim_section_links_to_the_guest_routes
  it('수정 진입도 같은 앞부분을 쓰고 `/edit` 로 끝난다', async () => {
    active = makeTest();
    await active.render();

    fireEvent.click(screen.getByText('수정'));

    const history = active.getNavigationHistory();
    expect(history.some((p) => p.startsWith('/shop/guest/claims/RT20260901-0003/edit'))).toBe(true);
    expect(history.some((p) => p.includes('/mypage/'))).toBe(false);
  });

  // @scenario surface=guest, has_image=no
  // @effects order_claim_section_thumbnail_placeholder_when_missing
  it('이미지 없는 상품에는 자리표시 아이콘이 선다 (회원 화면과 같은 규칙)', async () => {
    active = makeTest(GUEST_LOCALS, [
      { ...RETURN_CLAIM, items: [{ ...RETURN_CLAIM.items[0], thumbnail_url: null }] },
    ]);
    await active.render();

    expect(document.querySelector('[data-icon="image"]')).not.toBeNull();
    expect(document.querySelector('img')).toBeNull();
  });

  /**
   * 송장은 상품 행마다 있어 한 신청서에 운송장이 여럿일 수 있다 — 카드의 배송조회 칸은 서버 요약
   * (`tracking_summary`)의 첫 운송장과 「외 N개」 만 그린다. 헤더 송장 필드(`collection_tracking_number`)는
   * 응답에 없다. 판매자가 대신 접수한 건은 출처 라벨을 단다.
   *
   * @scenario claim_type=return, entry=with_token, screen=order_section
   * @effects guest_order_claim_section_shows_the_row_waybill_summary
   */
  it('배송조회 칸이 첫 운송장과 「외 N개」 를 그리고 판매자 접수 라벨이 선다', async () => {
    active = makeTest(GUEST_LOCALS, [
      {
        ...RETURN_CLAIM,
        request_source: 'admin',
        request_source_label: '판매자 접수',
        tracking_summary: {
          collection: { first: '612345678901', count: 3 },
          redelivery: { first: null, count: 0 },
        },
      },
    ]);
    await active.render();

    expect(screen.getByText('612345678901')).toBeInTheDocument();
    expect(screen.getByText('외 2개')).toBeInTheDocument();
    expect(screen.getByText('판매자 접수')).toBeInTheDocument();
  });

  /**
   * 운송장이 하나뿐이면 「외 N개」 를 붙이지 않는다(「외 0개」 금지). 송장이 없으면 대시 한 칸이다.
   *
   * @effects guest_order_claim_section_shows_the_row_waybill_summary
   */
  it('운송장이 하나면 「외 N개」 가 없고, 없으면 대시가 선다', async () => {
    active = makeTest(GUEST_LOCALS, [
      {
        ...RETURN_CLAIM,
        tracking_summary: {
          collection: { first: '612345678901', count: 1 },
          redelivery: { first: null, count: 0 },
        },
      },
      {
        ...RETURN_CLAIM,
        claim_number: 'RT20260901-0004',
        tracking_summary: {
          collection: { first: null, count: 0 },
          redelivery: { first: null, count: 0 },
        },
      },
    ]);
    await active.render();

    expect(screen.getByText('612345678901')).toBeInTheDocument();
    expect(screen.queryByText(/외 \d+개/)).toBeNull();
    expect(screen.getAllByText('—').length).toBeGreaterThan(0);
    expect(screen.queryByText('판매자 접수')).toBeNull();
  });

  /** @effects guest_claim_empty_list_returns_to_guest_shop */
  it('빈 목록의 나가는 버튼도 비회원 주소다 (주문 내역 화면이 없다)', async () => {
    active = makeTest(GUEST_LOCALS, []);
    await active.render();

    expect(screen.getByText('신청 내역이 없습니다.')).toBeInTheDocument();

    fireEvent.click(screen.getByText('쇼핑 계속하기'));

    const history = active.getNavigationHistory();
    expect(history).toContain('/shop/products');
    expect(history.some((p) => p.includes('/mypage/'))).toBe(false);
  });

  /** @effects guest_claim_tab_uses_shell_path */
  it('유형 서브탭이 셸이 준 화면 주소로 되돌아간다 (구조로 잰다)', () => {
    // 탭 전환은 `sequence` 의 중첩 `actions` 로 되어 있고 이 하네스는 그 중첩을 실행하지
    // 않는다(클릭해도 이동 이력이 비어 있다). 실 화면에서는 엔진이 실행한다 — S13 브라우저
    // 실측이 회원 화면에서 그 동작을 확인했다. 여기서는 목적지가 셸이 준 값인지만 구조로 잰다.
    const partial = JSON.parse(
      fs.readFileSync(
        path.resolve(__dirname, '../../layouts/partials/mypage/claims/_list.json'),
        'utf-8'
      )
    );

    const raw = JSON.stringify(partial);
    expect(raw).toContain('_local.claimListPath');
    // 회원 전용 주소가 리터럴로 남아 있으면 비회원 탭 전환이 로그인 화면을 연다.
    expect(raw).not.toContain('"path":"/mypage/');
  });
});
