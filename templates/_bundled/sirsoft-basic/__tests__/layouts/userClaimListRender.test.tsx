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
 *   claim_list_empty_state_and_pagination,
 *   claim_list_tracking_column_first_waybill_and_more,
 *   claim_list_admin_requested_badge
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
      tracking_more: '외 {{more}}개',
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
    // 목록은 방향별 첫 운송장과 개수만 싣는다(`tracking_summary`) — 운송장별 링크는 상세 몫이다.
    tracking_summary: {
      collection: { first: '651234567890', count: 2 },
      redelivery: { first: null, count: 0 },
    },
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
  pagination: Record<string, any> = { current_page: 1, last_page: 1, has_more_pages: false },
  envelope: Record<string, any> = {}
) {
  const utils = createLayoutTest(listLayout(), {
    initialState: { _local: {} },
    locale: 'ko',
    translations: TRANSLATIONS,
    componentRegistry: setupRegistry(),
    queryParams: query,
  });

  utils.mockApi('claims', { response: { data: { data: claims, pagination, ...envelope } } });

  const { container } = await utils.render();

  return { utils, container };
}

describe('클레임 신청내역 목록 — 렌더', () => {
  let active: { cleanup: () => void } | null = null;

  afterEach(() => {
    active?.cleanup();
    active = null;
  });

  /**
   * 서브탭 네 개의 활성 판정은 주소(query.type)가 단일 출처다.
   *
   * @effects claim_list_type_tabs_follow_the_query
   */
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

  /**
   * 서브탭에 **건수 배지**가 선다. (#599 후속 F12)
   *
   * 서버는 매 요청 네 유형을 세어 `counts` 로 실어 보내는데 구매자 화면은 그 값을 그리지
   * 않았다 — 어느 탭에 무엇이 있는지 눌러 보기 전에는 알 수 없었다(관리자 화면에는 있는
   * 표기다). 0건인 탭에는 세우지 않는다: 0 을 나란히 그리면 "아직 세지 않음" 과 구분되지
   * 않는다.
   *
   * @scenario claim_type=refund, screen=list, entry=none
   *
   * @effects claim_list_subtab_badges_render_server_counts
   */
  it('서브탭에 0건이 아닌 유형의 건수 배지가 선다 (F12)', async () => {
    const { utils, container } = await renderList(
      [exchangeClaim()],
      { type: 'exchange' },
      { current_page: 1, last_page: 1, has_more_pages: false },
      { counts: { exchange: 3, return: 0, refund: 2, cancel: 0 } }
    );
    active = utils;

    const tabs = Array.from(container.querySelectorAll('button')).filter((el) =>
      (el.textContent ?? '').includes('claim.list.tab_')
    );

    const byLabel = (key: string) =>
      tabs.find((el) => (el.textContent ?? '').includes(key))?.textContent ?? '';

    expect(byLabel('claim.list.tab_exchange')).toContain('3');
    expect(byLabel('claim.list.tab_refund')).toContain('2');
    // 0건 탭에는 숫자를 세우지 않는다.
    expect(byLabel('claim.list.tab_return')).toBe('claim.list.tab_return');
    expect(byLabel('claim.list.tab_cancel')).toBe('claim.list.tab_cancel');
  });

  /**
   * `counts` 가 없으면 배지도 없다 — 구형 응답에서 숫자 자리가 `0` 으로 서지 않아야 한다.
   *
   * @scenario claim_type=refund, screen=list, entry=none
   *
   * @effects claim_list_subtab_badges_absent_without_counts
   */
  it('counts 가 없으면 배지가 서지 않는다', async () => {
    const { utils, container } = await renderList([exchangeClaim()], { type: 'exchange' });
    active = utils;

    const tab = Array.from(container.querySelectorAll('button')).find((el) =>
      (el.textContent ?? '').includes('claim.list.tab_exchange')
    );

    expect(tab?.textContent).toBe('claim.list.tab_exchange');
  });

  /**
   * 유형 없는 주소는 교환 탭으로 연다.
   *
   * @effects claim_list_type_tabs_follow_the_query
   */
  it('주소에 유형이 없으면 교환 탭이 활성이다 (서버 게이트가 유형을 요구한다)', async () => {
    const { utils, container } = await renderList([exchangeClaim()], {});
    active = utils;

    const active버튼 = Array.from(container.querySelectorAll('button')).filter((el) =>
      (el.className ?? '').includes('font-semibold')
    );
    expect(active버튼[0].textContent).toBe('claim.list.tab_exchange');
  });

  /**
   * 카드 헤더가 서버 값을 그대로 그린다.
   *
   * @effects claim_list_row_actions_follow_server_flags
   */
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

  /**
   * 수정·철회 버튼은 서버 판정(is_editable/is_withdrawable)만 따른다.
   *
   * @effects claim_list_row_actions_follow_server_flags
   */
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

  /**
   * 상품 줄 아래에 교환 대상 줄이 선다.
   *
   * @effects claim_list_amount_column_branches_by_type
   */
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

  /**
   * 옵션을 바꾸지 않은 교환에는 교환 대상 줄이 없다.
   *
   * @effects claim_list_amount_column_branches_by_type
   */
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

  /**
   * 교환 금액 열의 구성.
   *
   * @effects claim_list_amount_column_branches_by_type
   */
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

  /**
   * 반품 행에는 환불액을 싣지 않는다.
   *
   * @effects claim_list_return_row_omits_refund_amount
   */
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

  // @scenario claim_type=return, entry=with_token, screen=order_section
  // @effects claim_list_amount_column_branches_by_type
  it('청구가 0원인 반품은 금액 열에 보조 통화만 남기지 않는다', async () => {
    // 2026-09-01 비회원 실측: 차감 방식(회수 배송비 0원) 반품에서 금액 열이
    // `$0.00 ¥0 元0.00 €0.00` 만 남았다. 회수 배송비 줄과 총 청구액 줄은 0원이라 서지 않는데
    // 보조 통화 iteration 만 게이트 없이 돌았기 때문이다 — 구매자는 그 0 이 무엇의 0 인지 알 수
    // 없고, 응답은 200 이라 어긋난 흔적이 어디에도 남지 않는다.
    const { utils, container } = await renderList(
      [
        returnClaim({
          claim_shipping_fee: 0,
          claim_shipping_fee_formatted: '0원',
          total_charge_amount: 0,
          total_charge_amount_formatted: '0원',
          mc_total_charge_amount: {
            USD: { formatted: '$0.00' },
            JPY: { formatted: '¥0' },
          },
        }),
      ],
      { type: 'return' }
    );
    active = utils;

    const text = container.textContent ?? '';

    // 근거가 되는 줄이 서지 않으므로 그 값의 보조 통화도 서지 않는다.
    expect(text).not.toContain('$0.00');
    expect(text).not.toContain('¥0');
    expect(text).not.toContain('claim.list.total_charge');
    expect(text).not.toContain('claim.list.collection_shipping_fee');
    // 금액 열 자체(제목)는 남는다 — 열을 통째로 지우면 표가 어긋난다.
    expect(text).toContain('금액');
  });

  // @scenario claim_type=exchange, entry=with_token, screen=list
  // @effects claim_list_item_secondary_currency_matches_subtotal
  it('상품 행의 보조 통화는 그 위 소계와 같은 값이다 (차액이 아니다)', async () => {
    // 2026-09-01 비회원 실측: 소계 줄은 `subtotal_amount_formatted`(28,000원)를 그리는데
    // 바로 아래 보조 통화 줄이 `mc_price_difference`(교환 차액)를 그렸다. 동일상품 교환은
    // 차액이 0 이라 `28,000원` 밑에 `$0.00` 이 붙었고, 다통화 사용자는 상품 금액을 0 으로 읽는다.
    // 두 줄은 같은 값의 다른 표기여야 한다.
    const { utils, container } = await renderList(
      [
        exchangeClaim({
          items: [
            {
              product_name: '티셔츠',
              product_option_name: '차콜/M',
              quantity: 1,
              subtotal_amount_formatted: '29,000원',
              mc_subtotal_amount: { USD: { formatted: '$21.75' }, JPY: { formatted: '¥2,940' } },
              thumbnail_url: '',
              is_option_change: true,
              new_option_label: '블랙/L',
              price_difference: 3000,
              price_difference_formatted: '3,000원',
              mc_price_difference: { USD: { formatted: '$2.25' } },
              status_label: '교환회수중',
              status_variant: 'info',
            },
          ],
        }),
      ],
      { type: 'exchange' }
    );
    active = utils;

    const text = container.textContent ?? '';

    // 소계의 보조 통화가 그려진다.
    expect(text).toContain('$21.75');
    expect(text).toContain('¥2,940');

    // 차액의 보조 통화를 **소계 자리에** 그리지 않는다. 예전에는 `not.toContain('$2.25')` 로 이
    // 계약을 대신 표현했는데, 그것은 차액의 보조 통화가 카드 어디에도 없던 시절에만 성립하는
    // 대역이었다(2026-09-01 4차 계획 검증 에서 교환 대상 줄에도 병기가 붙었다). 계약은
    // 그대로이므로 자리를 직접 지목하도록 조인다 — 소계 바로 다음 형제가 소계의 보조 통화 줄이다.
    const subtotal = Array.from(container.querySelectorAll('p')).find(
      (el) => el.textContent?.trim() === '29,000원'
    );
    expect(subtotal).toBeTruthy();
    const subtotalSecondary = subtotal?.nextElementSibling?.textContent ?? '';
    expect(subtotalSecondary).toContain('$21.75');
    expect(subtotalSecondary).not.toContain('$2.25');
  });

  // @scenario claim_type=return, entry=with_token, screen=list
  // @effects claim_list_item_secondary_currency_matches_subtotal
  it('교환이 아닌 유형의 상품 행에도 보조 통화가 선다', async () => {
    // `mc_price_difference` 는 교환 유형에만 실리는 필드라, 반품·취소·환불 행은 보조 통화 줄이
    // 통째로 비어 있었다. 같은 카드가 유형에 따라 다른 정보를 그리면 안 된다.
    const { utils, container } = await renderList(
      [
        returnClaim({
          items: [
            {
              product_name: 'USB 케이블',
              product_option_name: '1m/화이트',
              quantity: 1,
              subtotal_amount_formatted: '7,000원',
              mc_subtotal_amount: { USD: { formatted: '$5.95' }, JPY: { formatted: '¥805' } },
              thumbnail_url: '',
              status_label: '반품신청',
              status_variant: 'secondary',
            },
          ],
        }),
      ],
      { type: 'return' }
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('$5.95');
    expect(text).toContain('¥805');
  });

  /**
   * 환불 금액 열은 음수 부호로 선다.
   *
   * @effects claim_list_amount_column_branches_by_type
   */
  it('환불 금액 열 — 환불액이 음수 부호와 함께 선다', async () => {
    const { utils, container } = await renderList([refundClaim()], { type: 'refund' });
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('환불액');
    expect(text).toContain('−39,000원');
    expect(text).not.toContain('총 청구액');
    expect(text).not.toContain('claim.list.collection_shipping_fee');
  });

  /**
   * 환불액에도 보조 통화가 병기된다.
   *
   * @effects claim_list_shows_secondary_currency
   */
  it('환불 금액 열 — 환불액에도 보조 통화가 병기된다 (합계 필드를 읽는다)', async () => {
    // 서버는 목록 응답에도 `mc_refund_total`(현금+마일리지)·`mc_refund_amount`(현금분)를 싣는데
    // 화면이 쓰지 않아, 다통화 상점에서 환불·취소 카드만 외화가 통째로 빠져 있었다. 총 청구액 줄은
    // 바로 아래에서 병기하므로 같은 카드가 유형에 따라 다른 정보를 그렸다(2026-09-01 4차).
    // 기준 줄이 합계(`refund_total_formatted`)이므로 현금분을 읽으면 두 줄이 다른 금액이 된다.
    const { utils, container } = await renderList(
      [
        refundClaim({
          mc_refund_amount: { USD: { formatted: '$28.50' } },
          mc_refund_total: { USD: { formatted: '$29.25' }, JPY: { formatted: '¥3,955' } },
        }),
      ],
      { type: 'refund' }
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('−39,000원');
    expect(text).toContain('$29.25');
    expect(text).toContain('¥3,955');
    expect(text).not.toContain('$28.50');
  });

  /**
   * 취소 금액의 보조 통화는 현금분을 읽는다.
   *
   * @effects claim_list_shows_secondary_currency
   */
  it('취소 금액 열 — 보조 통화는 현금분(mc_refund_amount)으로 떨어진다', async () => {
    // 취소는 기준 줄이 연결 환불의 현금분(`refund_amount_formatted`)이라 합계 키가 없다.
    const { utils, container } = await renderList(
      [
        refundClaim({
          claim_number: 'CN20260901-0004',
          claim_type: 'cancel',
          claim_type_label: '취소',
          refund_total_formatted: undefined,
          refund_amount_formatted: '32,000원',
          mc_refund_amount: { USD: { formatted: '$27.20' } },
        }),
      ],
      { type: 'cancel' }
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('−32,000원');
    expect(text).toContain('$27.20');
  });

  /**
   * 클레임 배송비에도 보조 통화가 병기된다.
   *
   * @effects claim_list_shows_secondary_currency
   */
  it('금액 열 — 클레임 배송비에도 보조 통화가 병기된다', async () => {
    // 같은 금액 열의 형제 줄(총 청구액)은 병기하는데 배송비 줄만 기준 통화로 남아 있었다.
    const { utils, container } = await renderList(
      [exchangeClaim({ mc_claim_shipping_fee: { USD: { formatted: '$4.50' } } })],
      { type: 'exchange' }
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('+6,000원');
    expect(text).toContain('$4.50');
  });

  /**
   * 옵션 차액의 보조 통화는 차액 줄과 같은 게이트를 쓴다.
   *
   * @effects claim_list_shows_secondary_currency
   */
  it('상품 행 — 옵션 차액에도 보조 통화가 병기된다 (차액이 0 이면 서지 않는다)', async () => {
    // 게이트는 차액이 실제로 그려지는 조건과 같아야 한다 — 동일상품 교환에서 이 줄만 돌면
    // 근거 없는 0 이 남는다(상세의 같은 자리에서 3차가 겪은 형태다).
    const { utils, container } = await renderList([exchangeClaim()], { type: 'exchange' });
    active = utils;

    expect(container.textContent ?? '').toContain('$2.25');

    const { utils: same, container: sameOption } = await renderList(
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
              mc_price_difference: { USD: { formatted: '$0.00' } },
            },
          ],
        }),
      ],
      { type: 'exchange' }
    );

    // 단언이 cleanup **앞**에 와야 한다 — 언마운트된 컨테이너는 비어 있어 어떤 `not.toContain`
    // 이든 통과한다(2026-09-01 4차: 이 순서 때문에 게이트 되돌림이 red 가 되지 않았다).
    expect(sameOption.textContent ?? '').toContain('52,000원');
    expect(sameOption.textContent ?? '').not.toContain('$0.00');

    same.cleanup();
  });

  /**
   * 교환에 얹은 추가옵션을 이름으로 적는다.
   *
   * @effects claim_list_amount_column_branches_by_type
   */
  it('상품 행 — 이 교환에 얹은 추가옵션이 이름으로 나온다', async () => {
    // 신청 시점 동결값이다. 예전에는 선택 ID 만 남아 이 자리에 그릴 재료가 없었다.
    const { utils, container } = await renderList(
      [
        exchangeClaim({
          items: [
            {
              product_name: '티셔츠',
              product_option_name: '차콜/M',
              quantity: 1,
              subtotal_amount_formatted: '29,000원',
              thumbnail_url: '',
              is_option_change: true,
              new_option_label: '블랙/L',
              price_difference: 3000,
              price_difference_formatted: '3,000원',
              new_additional_options: [
                { name: '선물 포장', price_adjustment: 3000, price_adjustment_formatted: '3,000원', custom_text: '' },
                { name: '각인', price_adjustment: 0, price_adjustment_formatted: '0원', custom_text: 'G7' },
              ],
              status_label: '교환회수중',
              status_variant: 'info',
            },
          ],
        }),
      ],
      { type: 'exchange' }
    );
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('선물 포장');
    expect(text).toContain('각인 (G7)');
  });

  /**
   * 추가옵션이 없으면 그 줄이 없다.
   *
   * @effects claim_list_amount_column_branches_by_type
   */
  it('상품 행 — 추가옵션이 없으면 그 줄이 서지 않는다', async () => {
    const { utils, container } = await renderList([exchangeClaim()], { type: 'exchange' });
    active = utils;

    expect(container.textContent ?? '').not.toContain('선물 포장');

    // 게이트가 없으면 목록이 비어도 접두사 `+` 만 남은 줄이 선다.
    const strays = Array.from(container.querySelectorAll('p')).filter(
      (el) => (el.textContent ?? '').trim() === '+'
    );
    expect(strays).toHaveLength(0);
  });

  /**
   * @effects claim_list_tracking_column_first_waybill_and_more
   */
  it('배송조회 — 교환은 회수·재발송 두 항목, 환불은 대시', async () => {
    const { utils: u1, container: exchange } = await renderList([exchangeClaim()], { type: 'exchange' });
    active = u1;

    const exchangeText = exchange.textContent ?? '';
    expect(exchangeText).toContain('회수');
    expect(exchangeText).toContain('재발송');
    // 운송장이 둘이면 첫 운송장 + 「외 1개」.
    expect(exchangeText).toContain('651234567890');
    expect(exchangeText).toContain('외 1개');

    active.cleanup();

    const { utils: u2, container: refund } = await renderList([refundClaim()], { type: 'refund' });
    active = u2;

    const refundText = refund.textContent ?? '';
    expect(refundText).toContain('배송조회');
    expect(refundText).not.toContain('재발송');
  });

  /**
   * 운송장이 하나면 「외 N개」 가 서지 않는다.
   *
   * @effects claim_list_tracking_column_first_waybill_and_more
   */
  it('배송조회 — 운송장이 하나면 「외 N개」 가 없다', async () => {
    const { utils, container } = await renderList(
      [exchangeClaim({ tracking_summary: { collection: { first: 'ONE-1', count: 1 }, redelivery: { first: null, count: 0 } } })],
      { type: 'exchange' },
    );
    active = utils;

    const text = container.textContent ?? '';
    expect(text).toContain('ONE-1');
    expect(text).not.toContain('외 ');
  });

  /**
   * 판매자가 대신 접수한 신청서는 목록 카드에도 출처 뱃지가 선다(D8).
   *
   * @effects claim_list_admin_requested_badge
   */
  it('판매자 접수 — 카드에 출처 뱃지가 선다', async () => {
    const { utils, container } = await renderList(
      [exchangeClaim({ request_source: 'admin', request_source_label: '판매자 접수', is_editable: false, is_withdrawable: false })],
      { type: 'exchange' },
    );
    active = utils;

    const text = container.textContent ?? '';
    expect(text).toContain('판매자 접수');
    expect(text).not.toContain('철회');
  });

  /**
   * 빈 목록 안내.
   *
   * @effects claim_list_empty_state_and_pagination
   */
  it('빈 목록 — 안내와 주문내역 링크가 서고 카드는 없다', async () => {
    const { utils, container } = await renderList([], { type: 'exchange' });
    active = utils;

    const text = container.textContent ?? '';

    expect(text).toContain('신청 내역이 없습니다.');
    expect(text).toContain('주문내역 보기');
    expect(text).not.toContain('상세보기');
  });

  /**
   * 페이저는 여러 페이지일 때만 선다.
   *
   * @effects claim_list_empty_state_and_pagination
   */
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

  /**
   * 잘린 총 건수에서도 페이저가 선다.
   *
   * @effects claim_list_pagination_survives_a_truncated_total
   */
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
