/**
 * @file userClaimListRender.test.tsx
 * @description 유저 클레임 **신청내역 목록** 렌더 회귀 테스트 (#599 S13)
 *
 * 목록은 유형마다 4열째의 구성이 다르다(§6-1). 그 분기가 어긋나면 오류 없이 **다른 유형의
 * 금액 줄**이 서거나 있어야 할 줄이 빠지는데, 응답은 200 이라 어디에도 흔적이 남지 않는다.
 * 그래서 화면을 실제로 그려 글자가 나오는지로 잰다.
 *
 * 잰다:
 *  ① 유형 서브탭 4종이 서고, 활성 판정이 주소(query.type)를 단일 출처로 삼는다
 *  ② 카드 헤더 — 클레임번호·신청일·주문번호·상태 라벨
 *  ③ [수정]/[철회]는 서버 판정(is_editable / is_withdrawable)에서만 선다
 *  ④ 상품 줄과 (교환이면) 교환 대상 줄
 *  ⑤ 금액 열의 유형 분기 — 교환/반품/환불이 각기 다른 줄을 세운다
 *  ⑥ 보조 통화 병기(mc_*)가 글자로 나온다
 *  ⑦ 빈 목록과 페이지네이션
 *
 * @scenario claim_type=exchange, claim_type=return, claim_type=refund
 * @effects claim_list_type_tabs_follow_the_query,
 *   claim_list_row_actions_follow_server_flags,
 *   claim_list_amount_column_branches_by_type,
 *   claim_list_return_row_omits_refund_amount,
 *   claim_list_shows_secondary_currency,
 *   claim_list_empty_state_and_pagination
 *
 * @vitest-environment jsdom
 */

import React from 'react';
import fs from 'node:fs';
import path from 'node:path';
import { describe, it, expect, afterEach } from 'vitest';
import {
  createLayoutTest,
  createMockComponentRegistryWithBasics,
} from '@core/template-engine/__tests__/utils/layoutTestUtils';

function setupRegistry() {
  const registry = createMockComponentRegistryWithBasics();

  registry.register('basic', 'Icon', ({ className, name }: any) =>
    React.createElement('i', { className, 'data-icon': name })
  );
  // 페이지네이션은 코어 composite 다 — 여기서 잴 것은 "섰는가" 와 그 페이지 수뿐이다.
  registry.register('composite', 'Pagination', ({ currentPage, totalPages }: any) =>
    React.createElement('div', {
      'data-testid': 'pagination',
      'data-current': String(currentPage ?? ''),
      'data-total': String(totalPages ?? ''),
    })
  );

  return registry;
}

function listLayout(): any {
  const partial = JSON.parse(
    fs.readFileSync(
      path.resolve(__dirname, '../../layouts/partials/mypage/claims/_list.json'),
      'utf-8'
    )
  );

  return {
    // 셸의 데이터소스만 흉내낸다 — 셸 전체(탭 네비게이션·컨테이너)는 이 파일의 관심사가 아니다.
    data_sources: [
      {
        id: 'claims',
        type: 'api',
        endpoint: '/api/modules/sirsoft-ecommerce/user/claims',
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
      go_orders: '주문내역 보기',
    },
  },
  shop: { count_unit: '개' },
};

/** 교환 신청서 한 건 — 서버 응답(ClaimResource 목록 표현)의 형태를 그대로 흉내낸다. */
function exchangeClaim(overrides: Record<string, any> = {}): Record<string, any> {
  return {
    claim_number: 'EX20260901-0001',
    claim_type: 'exchange',
    claim_type_label: '교환',
    order_number: 'O20260812-0000123',
    status: 'collecting',
    status_label: '교환회수중',
    status_variant: 'info',
    is_editable: true,
    is_withdrawable: true,
    reason_label: '사이즈가 맞지 않아요',
    fault_type_label: '고객 귀책',
    currency_code: 'KRW',
    requested_at_formatted: '2026-09-01 10:00',
    claim_shipping_fee: 6000,
    claim_shipping_fee_formatted: '6,000원',
    additional_product_amount: 3000,
    additional_product_amount_formatted: '3,000원',
    refund_product_amount: 0,
    total_charge_amount: 9000,
    total_charge_amount_formatted: '9,000원',
    mc_total_charge_amount: { USD: { formatted: '$6.75' } },
    charge_method_label: '신청 시 결제',
    collection_carrier_name: 'CJ대한통운',
    collection_tracking_number: '651234567890',
    collection_tracking_url: 'https://example.test/track/651234567890',
    redelivery_tracking_number: null,
    redelivery_tracking_url: null,
    items: [
      {
        product_name: '티셔츠',
        product_option_name: '차콜/M',
        quantity: 1,
        subtotal_amount_formatted: '29,000원',
        thumbnail_url: '',
        is_option_change: true,
        new_option_label: '블랙/L 각인"JE"',
        price_difference: 3000,
        price_difference_formatted: '3,000원',
        mc_price_difference: { USD: { formatted: '$2.25' } },
        status_label: '교환회수중',
        status_variant: 'info',
      },
    ],
    ...overrides,
  };
}

function returnClaim(overrides: Record<string, any> = {}): Record<string, any> {
  return {
    claim_number: 'RT20260901-0002',
    claim_type: 'return',
    claim_type_label: '반품',
    order_number: 'O20260812-0000124',
    status_label: '반품신청',
    status_variant: 'warning',
    is_editable: false,
    is_withdrawable: false,
    reason_label: '단순 변심',
    currency_code: 'KRW',
    requested_at_formatted: '2026-09-01 11:00',
    claim_shipping_fee: 3000,
    claim_shipping_fee_formatted: '3,000원',
    total_charge_amount: 3000,
    total_charge_amount_formatted: '3,000원',
    charge_method_label: '환불금에서 차감',
    items: [],
    ...overrides,
  };
}

function refundClaim(overrides: Record<string, any> = {}): Record<string, any> {
  return {
    claim_number: 'RF20260901-0003',
    claim_type: 'refund',
    claim_type_label: '환불',
    order_number: 'O20260812-0000125',
    status_label: '환불완료',
    status_variant: 'success',
    is_editable: false,
    is_withdrawable: false,
    reason_label: '주문 실수',
    currency_code: 'KRW',
    requested_at_formatted: '2026-09-01 12:00',
    refund_amount: 39000,
    refund_total_formatted: '39,000원',
    items: [],
    ...overrides,
  };
}

async function renderList(
  claims: Record<string, any>[],
  query: Record<string, string> = {},
  pagination: Record<string, any> = { current_page: 1, last_page: 1, has_more_pages: false }
) {
  const utils = createLayoutTest(listLayout(), {
    initialState: { _local: {} },
    locale: 'ko',
    translations: TRANSLATIONS,
    componentRegistry: setupRegistry(),
    queryParams: query,
  });

  utils.mockApi('claims', { response: { data: { data: claims, pagination } } });

  const { container } = await utils.render();

  return { utils, container };
}

describe('클레임 신청내역 목록 — 렌더', () => {
  let active: { cleanup: () => void } | null = null;

  afterEach(() => {
    active?.cleanup();
    active = null;
  });

  it('유형 서브탭 4종이 서고, 활성 판정은 주소를 따른다', async () => {
    const { utils, container } = await renderList([exchangeClaim()], { type: 'return' });
    active = utils;

    const text = container.textContent ?? '';

    // 탭 라벨은 iteration source 안의 `$t('...')` 로 만든다 — 테스트 하네스는 그 형태를
    // 치환하지 않고 키를 그대로 남기므로 키로 잰다(실 화면에서는 엔진이 치환한다).
    for (const key of [
      'claim.list.tab_exchange',
      'claim.list.tab_return',
      'claim.list.tab_refund',
      'claim.list.tab_cancel',
    ]) {
      expect(text).toContain(key);
    }

    // 활성 탭은 굵게 그린다 — 그 클래스를 가진 버튼이 정확히 하나여야 한다.
    const active버튼 = Array.from(container.querySelectorAll('button')).filter((el) =>
      (el.className ?? '').includes('font-semibold')
    );
    expect(active버튼).toHaveLength(1);
    expect(active버튼[0].textContent).toBe('claim.list.tab_return');
  });

  it('주소에 유형이 없으면 교환 탭이 활성이다 (서버 게이트가 유형을 요구한다)', async () => {
    const { utils, container } = await renderList([exchangeClaim()], {});
    active = utils;

    const active버튼 = Array.from(container.querySelectorAll('button')).filter((el) =>
      (el.className ?? '').includes('font-semibold')
    );
    expect(active버튼[0].textContent).toBe('claim.list.tab_exchange');
  });

  it('카드 헤더 — 클레임번호·신청일·주문번호·상태 라벨이 나온다', async () => {
    const { utils, container } = await renderList([exchangeClaim()], { type: 'exchange' });
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('EX20260901-0001');
    expect(text).toContain('2026-09-01 10:00 신청');
    expect(text).toContain('주문번호 O20260812-0000123');
    // 상태 라벨은 유형 접두 정확 명칭이다(D28) — 화면이 '회수중' 으로 줄이면 어느 유형의
    // 회수인지 알 수 없다.
    expect(text).toContain('교환회수중');
  });

  it('[수정]·[철회]는 서버 판정에서만 선다', async () => {
    const { utils, container } = await renderList(
      [exchangeClaim(), returnClaim()],
      { type: 'exchange' }
    );
    active = utils;

    const labels = Array.from(container.querySelectorAll('button')).map((el) => el.textContent);

    // 승인 전(교환) 한 건만 [수정]/[철회] 를 가진다 — 승인 뒤(반품)는 없다.
    expect(labels.filter((l) => l === '수정')).toHaveLength(1);
    expect(labels.filter((l) => l === '철회')).toHaveLength(1);
  });

  it('상품 줄과 교환 대상 줄이 나온다', async () => {
    const { utils, container } = await renderList([exchangeClaim()], { type: 'exchange' });
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('티셔츠');
    expect(text).toContain('차콜/M · 1개');
    expect(text).toContain('29,000원');
    // 교환 대상 — 옵션을 바꾼 상품만 이 줄을 갖는다.
    expect(text).toContain('블랙/L 각인"JE"');
  });

  it('동일상품 교환에는 교환 대상 줄이 서지 않는다', async () => {
    const { utils, container } = await renderList(
      [
        exchangeClaim({
          items: [
            {
              product_name: '니트',
              product_option_name: '아이보리/L',
              quantity: 1,
              subtotal_amount_formatted: '52,000원',
              is_option_change: false,
              new_option_label: '',
              price_difference: 0,
            },
          ],
        }),
      ],
      { type: 'exchange' }
    );
    active = utils;

    expect(container.textContent ?? '').not.toContain('⇄');
  });

  it('교환 금액 열 — 왕복 배송비·추가 상품금액·총 청구액', async () => {
    const { utils, container } = await renderList([exchangeClaim()], { type: 'exchange' });
    active = utils;

    const text = container.textContent ?? '';

    // 유형 분기 라벨은 `$t('...')` 형태라 하네스에서 키로 남는다 — 잴 것은 **어느 쪽 키가
    // 섰는가** 이고, 그것이 곧 유형 분기가 맞았다는 증거다.
    expect(text).toContain('claim.list.round_trip_shipping_fee');
    expect(text).not.toContain('claim.list.collection_shipping_fee');
    expect(text).toContain('+6,000원');
    expect(text).toContain('추가 상품금액');
    expect(text).toContain('+3,000원');
    expect(text).toContain('총 청구액');
    expect(text).toContain('9,000원');
    // 보조 통화 병기 — 값이 빈 문자열이면 이 단언이 잡는다(형제 화면의 실제 회귀였다).
    expect(text).toContain('$6.75');
  });

  it('반품 금액 열 — 회수 배송비가 서고 환불액은 서지 않는다', async () => {
    const { utils, container } = await renderList([returnClaim()], { type: 'return' });
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('claim.list.collection_shipping_fee');
    expect(text).toContain('+3,000원');
    expect(text).not.toContain('claim.list.round_trip_shipping_fee');
    // 반품의 환불액은 환불신청서가 접수되기 전에는 도메인 어디에도 없다 — 화면이 추정치를
    // 세우면 실제 환불액과 다른 수가 목록에만 존재하게 된다.
    expect(text).not.toContain('환불액');
  });

  it('환불 금액 열 — 환불액이 음수 부호와 함께 선다', async () => {
    const { utils, container } = await renderList([refundClaim()], { type: 'refund' });
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('환불액');
    expect(text).toContain('−39,000원');
    expect(text).not.toContain('총 청구액');
    expect(text).not.toContain('claim.list.collection_shipping_fee');
  });

  it('배송조회 — 교환은 회수·재발송 두 항목, 환불은 대시', async () => {
    const { utils: u1, container: exchange } = await renderList([exchangeClaim()], { type: 'exchange' });
    active = u1;

    const exchangeText = exchange.textContent ?? '';
    expect(exchangeText).toContain('회수');
    expect(exchangeText).toContain('재발송');
    expect(exchangeText).toContain('CJ대한통운 651234567890');
    expect(exchange.querySelector('a[href="https://example.test/track/651234567890"]')).not.toBeNull();

    active.cleanup();

    const { utils: u2, container: refund } = await renderList([refundClaim()], { type: 'refund' });
    active = u2;

    const refundText = refund.textContent ?? '';
    expect(refundText).toContain('배송조회');
    expect(refundText).not.toContain('재발송');
  });

  it('빈 목록 — 안내와 주문내역 링크가 서고 카드는 없다', async () => {
    const { utils, container } = await renderList([], { type: 'exchange' });
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('신청 내역이 없습니다.');
    expect(text).toContain('주문내역 보기');
    expect(text).not.toContain('상세보기');
  });

  it('페이지네이션 — 여러 페이지일 때만 선다', async () => {
    const { utils: u1, container: single } = await renderList(
      [exchangeClaim()],
      { type: 'exchange' },
      { current_page: 1, last_page: 1, has_more_pages: false }
    );
    active = u1;

    expect(single.querySelector('[data-testid="pagination"]')).toBeNull();

    active.cleanup();

    const { utils: u2, container: many } = await renderList(
      [exchangeClaim()],
      { type: 'exchange', page: '2' },
      { current_page: 2, last_page: 5, has_more_pages: true }
    );
    active = u2;

    const pager = many.querySelector('[data-testid="pagination"]');
    expect(pager).not.toBeNull();
    expect(pager?.getAttribute('data-current')).toBe('2');
    expect(pager?.getAttribute('data-total')).toBe('5');
  });

  it('총 건수가 상한에 잘린 응답에서도 페이저가 선다 (last_page 는 null 이다)', async () => {
    // 대용량 목록은 총 건수만 상한을 받고 "다음" 은 실측으로 열려 있다 — last_page 가 null 인
    // 응답에서 페이저를 감추면 1페이지 밖 신청서가 조용히 잘린다.
    const { utils, container } = await renderList(
      [exchangeClaim()],
      { type: 'exchange' },
      { current_page: 1, last_page: null, has_more_pages: true }
    );
    active = utils;

    const pager = container.querySelector('[data-testid="pagination"]');
    expect(pager).not.toBeNull();
    expect(pager?.getAttribute('data-total')).toBe('');
  });
});
