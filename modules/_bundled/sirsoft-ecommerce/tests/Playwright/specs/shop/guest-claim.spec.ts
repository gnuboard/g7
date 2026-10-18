/**
 * 비회원 클레임 화면 — 토큰 처분 · 주문상세 클레임 섹션 · 신청 진입 · 상세 (#599 S15b · §5-1-3)
 *
 * 비회원 화면은 회원 것과 **같은 partial** 을 쓰고 셸이 계약값과 주소만 바꾼다. 그래서 조용히
 * 깨지는 자리가 둘이다.
 *
 *  ① **토큰이 없거나 만료된 진입.** 주문 스코프가 서지 않으면 화면은 주소를 만들고 요청은 404 가
 *     된다. 그때 남은 토큰을 비우지 않으면 다음 진입이 같은 404 를 반복한다 — 어느 로그에도
 *     남지 않고 사용자에게는 "조회했는데 계속 튕긴다" 로만 보인다.
 *  ② **공유 partial 이 회원 전용 주소를 들고 있으면** 비회원이 그 주소를 타는 순간 로그인 화면이
 *     열린다. 카드·버튼은 정상으로 그려지므로 **누르기 전에는 드러나지 않는다.**
 *
 * ① 은 서버가 실제로 404 를 내는 경로라 응답을 가로채지 않는다. ② 는 토큰의 짝인 주문이 있어야
 * 성립하므로, 조회 토큰 3키를 심고 그 주문의 응답을 세운 뒤 **주소가 어디로 가는지**를 잰다.
 *
 *
 * 매니페스트 마킹(@scenario/@effects)은 **테스트마다** 그 케이스 위에 둔다 — 한 docblock 에
 * 여러 줄을 적으면 마킹 추출기가 첫 줄만 읽어 나머지 조합이 조용히 미커버로 남는다.
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import {
  API,
  MOCK_ORDER,
  claimRow,
  grantFunctionalConsent,
  readGuestSession,
  seedGuestSession,
  shopBase,
  waitForApp,
  FROZEN_COMPARISON,
} from '../../fixtures/claim-screens';
import type { Page, Route } from '@playwright/test';

/** 비회원 주문상세가 읽는 상품 옵션 id */
const OPTION_ID = 810;

/**
 * JSON 응답으로 라우트를 채웁니다.
 *
 * @param route Playwright 라우트
 * @param data `data` 봉투에 실을 페이로드
 * @returns 완료 Promise
 */
async function fulfillData(route: Route, data: unknown): Promise<void> {
  await route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ success: true, message: null, data }),
  });
}

/**
 * 상점 접두를 읽습니다 — 운영자가 바꿀 수 있으므로 리터럴로 들지 않는다.
 *
 * @param page Playwright 페이지
 * @returns 상점 접두
 */
async function resolveShopBase(page: Page): Promise<string> {
  await page.goto('/');
  await waitForApp(page);

  return shopBase(page);
}

/**
 * 비회원 신청서 상세 응답을 만듭니다.
 *
 * @param claimNumber 신청번호
 * @param items 신청 상품 행 (수정 화면이 이 행으로 단가 줄을 그린다)
 * @returns 신청서 페이로드
 */
function guestClaimDetail(
  claimNumber: string,
  items: Record<string, unknown>[] = [],
): Record<string, unknown> {
  return {
    claim_number: claimNumber,
    claim_type: 'exchange',
    claim_type_label: '교환',
    order_number: MOCK_ORDER.number,
    status: 'requested',
    status_label: '교환신청',
    status_variant: 'info',
    currency_code: 'KRW',
    requested_at_formatted: '2026-09-02 10:00',
    is_editable: false,
    is_withdrawable: false,
    reason_label: '사이즈가 맞지 않음',
    items,
    images: [],
    // 접수 시점 주문금액 비교(#599 후속) — 회원 상세와 같은 키·같은 partial 이 읽는다.
    calculation_comparison: FROZEN_COMPARISON,
  };
}

/**
 * 토큰을 쥔 비회원의 주문상세가 읽는 응답 4종을 세웁니다.
 *
 * @param page Playwright 페이지
 * @param claims '이 주문의 클레임' 목록에 실을 행
 * @param claimsType 그 행이 속한 유형
 * @param claimItems 신청서 상세·수정이 그릴 신청 상품 행
 * @returns 완료 Promise
 */
async function mockGuestOrderScreens(
  page: Page,
  claims: Record<string, unknown>[] = [],
  claimsType: 'exchange' | 'return' | 'refund' | 'cancel' = 'exchange',
  claimItems: Record<string, unknown>[] = [],
): Promise<void> {
  await page.route(`**${API}/user/orders/${MOCK_ORDER.number}*`, async (route) => {
    await fulfillData(route, {
      id: MOCK_ORDER.id,
      order_number: MOCK_ORDER.number,
      order_status: 'delivered',
      base_currency: 'KRW',
      currency_code: 'KRW',
      ordered_at_formatted: '2026-09-01 10:00',
      abilities: { can_cancel: false },
      options: [
        {
          id: OPTION_ID,
          product_name: '반팔 티셔츠',
          product_option_name: '화이트 / M',
          quantity: 1,
          option_status: 'delivered',
          option_status_label: '배송완료',
          unit_price: 29_000,
          unit_price_formatted: '29,000원',
          total_price_formatted: '29,000원',
          thumbnail_url: null,
        },
      ],
    });
  });

  // 비회원 스코프 엔드포인트는 **한 핸들러**가 경로로 갈라 받는다. 글로브를 여러 개 걸면
  // `claims` 와 `claims/{번호}` 가 서로의 패턴에 걸려 등록 순서가 응답을 정하게 된다.
  await page.route(`**${API}/guest/orders/**`, async (route) => {
    const url = new URL(route.request().url());

    if (url.pathname.endsWith('/claimable-options')) {
      await fulfillData(route, {
        order_id: MOCK_ORDER.id,
        order_number: MOCK_ORDER.number,
        resolved_type: null,
        options: [
          {
            option: {
              id: OPTION_ID,
              product_id: 5001,
              product_name: '반팔 티셔츠',
              product_option_name: '화이트 / M',
              unit_price: 29_000,
              unit_price_formatted: '29,000원',
              thumbnail_url: null,
            },
            available_quantity: 1,
            locked_quantity: 0,
            claimable_types: ['exchange', 'return', 'refund'],
            refund_branch: 'request',
          },
        ],
        reasons: {},
        settings: { allowed_charge_methods: ['pay_on_request'], charge_payment_methods: ['card'] },
        pending_images: [],
      });

      return;
    }

    const detail = url.pathname.match(/\/claims\/([^/]+)$/);
    if (detail) {
      await fulfillData(route, {
        claim: guestClaimDetail(decodeURIComponent(detail[1]), claimItems),
      });

      return;
    }

    if (url.pathname.endsWith('/claims')) {
      // 서버 계약: 유형을 비우면 접수 건이 있는 첫 유형, 넷 다 없으면 교환. 이 목은 한 유형(claimsType)에만 행을 둔다.
      const type = url.searchParams.get('type') || (claims.length > 0 ? claimsType : 'exchange');
      const rows = type === claimsType ? claims : [];

      await fulfillData(route, {
        data: rows,
        pagination: {
          current_page: Number(url.searchParams.get('page') ?? 1),
          per_page: 20,
          total: rows.length,
          last_page: 1,
          total_relation: 'eq',
        },
        claim_type: type,
        counts: { exchange: 0, return: 0, refund: 0, cancel: 0, [claimsType]: claims.length },
      });

      return;
    }

    await route.fallback();
  });

  await page.route(`**${API}/user/claim-reasons*`, async (route) => {
    await fulfillData(route, []);
  });
}

/** 화면 전체 글자 (공백 정규화) */
async function screenText(page: Page): Promise<string> {
  return ((await page.locator('body').innerText()) ?? '').replace(/\s+/g, ' ');
}

test.describe('비회원 클레임 화면', () => {
  // @scenario screen=detail, entry=without_token, claim_type=exchange
  // @effects guest_claim_routes_are_token_protected, guest_claim_screens_clear_the_token_and_return_to_lookup
  test('토큰 없이 신청서 상세로 들어가면 조회 폼으로 돌려보낸다', async ({ page }) => {
    const base = await resolveShopBase(page);

    await page.goto(`${base}/guest/claims/EX20260902-9999`);
    await waitForApp(page);

    // 토큰이 없으면 서버는 존재 여부를 드러내지 않고 404 로 끝낸다 — 화면은 조회 폼으로 보낸다.
    await expect
      .poll(() => new URL(page.url()).pathname, { timeout: 30_000 })
      .toBe(`${base}/guest/orders`);

    const session = await readGuestSession(page);
    expect(session.token, '없던 토큰이 생겼다').toBeNull();
    expect(session.orderNumber, '토큰 없이 주문번호만 남았다').toBeNull();
  });

  // @scenario screen=detail, entry=expired_token, claim_type=exchange
  // @effects guest_order_number_is_cleared_with_the_token, guest_claim_screens_clear_the_token_and_return_to_lookup
  test('만료된 토큰으로 들어가면 토큰과 주문번호를 함께 폐기한다', async ({ page }) => {
    const base = await resolveShopBase(page);

    // 만료 시각을 과거로 둔 3키 — 화면 진입 전에 심어야 부모 레이아웃의 init 이 그것을 읽는다.
    await grantFunctionalConsent(page);
    await seedGuestSession(page, new Date(Date.now() - 60_000).toISOString());

    await page.goto(`${base}/guest/claims/EX20260902-9999`);
    await waitForApp(page);

    await expect
      .poll(() => new URL(page.url()).pathname, { timeout: 30_000 })
      .toBe(`${base}/guest/orders`);

    // 비우지 않으면 다음 진입이 같은 만료 토큰을 다시 들고 같은 404 를 반복한다.
    const session = await readGuestSession(page);
    expect(session.token, '만료 토큰이 남았다').toBeNull();
    expect(session.orderNumber, '토큰의 짝인 주문번호가 남았다').toBeNull();
    expect(session.expiresAt, '만료 시각이 남았다').toBeNull();
  });

  // @scenario screen=order_section, entry=with_token, claim_type=exchange
  // @effects guest_order_claim_section_reuses_the_member_list_partial, guest_order_claim_list_is_paginated_by_type
  test('주문상세의 클레임 섹션은 회원 목록과 같은 partial 로 선다', async ({ page }) => {
    const base = await resolveShopBase(page);
    await grantFunctionalConsent(page);
    await seedGuestSession(page);
    await mockGuestOrderScreens(page, [
      claimRow({ claim_number: 'EX20260902-0900', claim_shipping_fee_formatted: '6,000원' }),
    ]);

    await page.goto(`${base}/guest/orders/${encodeURIComponent(MOCK_ORDER.number)}`);
    await waitForApp(page);

    await expect
      .poll(async () => (await screenText(page)).includes('EX20260902-0900'), { timeout: 30_000 })
      .toBe(true);

    const text = await screenText(page);
    expect(text, '클레임 섹션 제목이 없다').toContain('이 주문의 취소·반품·교환');

    // 비회원에게는 주문을 가로지르는 신청내역이 없다 — 이 섹션이 곧 그 목록이고 서브탭도 같다.
    for (const label of ['교환', '반품', '환불', '취소']) {
      await expect(
        page.getByRole('button', { name: new RegExp(`^${label}( \\d+)?$`) }),
        `${label} 서브탭이 없다`,
      ).toBeVisible();
    }

    // 번호가 곧 상세 링크다 — 푸터 [상세보기] 는 서지 않는다. 새 창 링크는 아니다: 비회원 조회
    // 토큰이 탭(sessionStorage)에 묶여 새 창에 실리지 않으므로 같은 창에서 이동한다.
    await expect(page.locator('[id^="claim_list_number_button_"]')).toHaveCount(1);
    await expect(page.locator('a[target="_blank"]', { hasText: 'EX20260902-0900' })).toHaveCount(0);
    await expect(page.getByRole('button', { name: '상세보기' })).toHaveCount(0);
  });

  // @scenario screen=order_section, entry=with_token, claim_type=return
  // @effects guest_order_detail_sends_no_type_when_address_has_none
  test('주소에 유형이 없으면 접수 건이 있는 유형이 먼저 열린다 — 누르기 전에 (회원 섹션과 같은 규칙)', async ({ page }) => {
    const base = await resolveShopBase(page);
    await grantFunctionalConsent(page);
    await seedGuestSession(page);
    const requested: URL[] = [];
    page.on('request', (r) => {
      const u = new URL(r.url());
      if (u.pathname.endsWith(`/guest/orders/${encodeURIComponent(MOCK_ORDER.number)}/claims`)) requested.push(u);
    });
    await mockGuestOrderScreens(
      page,
      [claimRow({ claim_number: 'RT20260902-0930', claim_type: 'return', claim_type_label: '반품', status_label: '반품신청' })],
      'return',
    );

    await page.goto(`${base}/guest/orders/${encodeURIComponent(MOCK_ORDER.number)}`);
    await waitForApp(page);

    await expect
      .poll(async () => (await screenText(page)).includes('RT20260902-0930'), { timeout: 30_000 })
      .toBe(true);

    // 반품만 활성(진한 밑줄) — 교환 탭의 빈 안내가 먼저 보이지 않는다
    await expect(page.getByRole('button', { name: /^반품( \d+)?$/ })).toHaveClass(/border-gray-900/);
    await expect(page.getByRole('button', { name: /^교환( \d+)?$/ })).not.toHaveClass(/border-gray-900/);
    expect(new URL(page.url()).searchParams.get('type')).toBeNull();

    // 화면이 유형을 정하지 않았다 — 나간 요청의 type 은 전부 비어 있다
    expect(requested.length).toBeGreaterThan(0);
    expect(requested.every((u) => !u.searchParams.get('type'))).toBe(true);
  });

  // @scenario screen=apply, entry=with_token, claim_type=exchange
  // @effects guest_order_detail_offers_the_three_claim_entries, guest_order_claim_section_links_to_the_guest_routes
  test('상품을 고르면 신청 진입 3종이 열리고 비회원 주소로 보낸다', async ({ page }) => {
    const base = await resolveShopBase(page);
    await grantFunctionalConsent(page);
    await seedGuestSession(page);
    await mockGuestOrderScreens(page);

    await page.goto(`${base}/guest/orders/${encodeURIComponent(MOCK_ORDER.number)}`);
    await waitForApp(page);

    const exchangeEntry = page.getByRole('button', { name: '교환 신청' });
    await expect(exchangeEntry).toBeVisible({ timeout: 30_000 });

    // 고르기 전에는 무엇을 신청할지 정해지지 않았으므로 잠겨 있다.
    await expect(exchangeEntry, '상품을 고르기 전인데 신청 진입이 열려 있다').toBeDisabled();

    const itemCheckbox = page.locator('input[type="checkbox"]').first();
    await itemCheckbox.click();

    await expect(exchangeEntry, '상품을 골랐는데 신청 진입이 잠긴 채다').toBeEnabled({ timeout: 15_000 });
    await expect(page.getByRole('button', { name: '반품 신청' })).toBeEnabled();
    await expect(page.getByRole('button', { name: '취소·환불 신청' })).toBeEnabled();

    await exchangeEntry.click();

    // 회원 전용 주소(/mypage/…)로 보내면 로그인 화면이 열린다 — 오류도 토스트도 남지 않는다.
    await expect
      .poll(() => new URL(page.url()).pathname, { timeout: 20_000 })
      .toBe(`${base}/guest/orders/${MOCK_ORDER.number}/claims/apply/exchange`);
    expect(
      new URL(page.url()).searchParams.get('options'),
      '고른 상품이 신청서로 전달되지 않았다',
    ).toBe(String(OPTION_ID));
  });

  // @scenario screen=detail, entry=with_token, claim_type=exchange
  // @effects guest_claim_shells_declare_the_order_scoped_bases
  test('신청서 상세는 주문 스코프 없는 비회원 주소로 서고 목록으로 돌아간다', async ({ page }) => {
    const base = await resolveShopBase(page);
    await grantFunctionalConsent(page);
    await seedGuestSession(page);
    await mockGuestOrderScreens(page, [
      claimRow({ claim_number: 'EX20260902-0901', claim_shipping_fee_formatted: '6,000원' }),
    ]);

    await page.goto(`${base}/guest/orders/${encodeURIComponent(MOCK_ORDER.number)}`);
    await waitForApp(page);

    await expect
      .poll(async () => (await screenText(page)).includes('EX20260902-0901'), { timeout: 30_000 })
      .toBe(true);

    // 번호가 곧 링크다 — 같은 창에서 이동한다 (조회 토큰이 탭에 묶여 있어 새 창은 조회 폼으로 되돌아간다).
    await page.locator('[id^="claim_list_number_button_"]').first().click();

    // 비회원 상세 주소에는 주문번호가 없다 — 스코프는 토큰의 짝인 `_global.guestOrderNumber` 다.
    await expect
      .poll(() => new URL(page.url()).pathname, { timeout: 20_000 })
      .toBe(`${base}/guest/claims/EX20260902-0901`);

    // 그 화면에서도 토큰은 유효한 채로 남는다 (진입이 404 를 받으면 폐기된다).
    const session = await readGuestSession(page);
    expect(session.token, '정상 진입인데 토큰이 폐기됐다').not.toBeNull();
  });
  // @scenario screen=edit, entry=with_token, claim_type=exchange
  // @effects guest_claim_edit_entry_reads_global_not_local, guest_claim_shells_reuse_the_member_partials
  test('수정 진입은 주소에 없는 주문 스코프를 토큰의 짝에서 가져온다', async ({ page }) => {
    const base = await resolveShopBase(page);
    await grantFunctionalConsent(page);
    await seedGuestSession(page);

    // 신청서를 어느 주소로 읽었는지 본다 — 수정 라우트에는 주문번호가 없다.
    const claimReads: string[] = [];
    page.on('request', (r) => {
      if (/\/guest\/orders\/[^/]+\/claims\/[^/]+$/.test(new URL(r.url()).pathname)) {
        claimReads.push(new URL(r.url()).pathname);
      }
    });

    await mockGuestOrderScreens(page);

    await page.goto(`${base}/guest/claims/EX20260902-0902/edit`);
    await waitForApp(page);

    // 회원과 같은 partial 이 서고(작성 셸을 edit 모드로 재사용) 수정 범위 안내가 붙는다.
    await expect(page.locator('#claim_apply_items')).toBeVisible({ timeout: 30_000 });
    expect(await screenText(page), '수정 범위 안내가 없다').toContain('승인 전까지');

    // 주소는 `_global.guestOrderNumber` 가 만든다 — `_local` 은 init_actions 시점에 비어 있다.
    await expect
      .poll(() => claimReads.length, { timeout: 20_000 })
      .toBeGreaterThan(0);
    expect(
      claimReads[claimReads.length - 1],
      '수정 진입이 주문 스코프 없는 주소로 신청서를 읽었다',
    ).toBe(`/api/modules/sirsoft-ecommerce/guest/orders/${MOCK_ORDER.number}/claims/EX20260902-0902`);
  });

  // @scenario screen=edit, entry=with_token, claim_type=exchange
  // @effects guest_claim_cards_keep_shared_values
  test('수정 화면의 단가 줄은 차액이 아니라 자기 보조통화를 단다 (S3-D6)', async ({ page }) => {
    //
    // 두 값 모두 정상 서식이라 어긋나도 화면에 흔적이 남지 않는다 — 실측
    // EX20260915-0853360266 에서 `28,000원` 줄에 교환 차액(-3,000)의 보조통화가 붙었다.
    // 그래서 목은 단가와 차액을 **부호까지 다르게** 세운다: 둘을 바꿔 읽으면 반드시 깨진다.
    const base = await resolveShopBase(page);
    await grantFunctionalConsent(page);
    await seedGuestSession(page);

    await mockGuestOrderScreens(page, [], 'exchange', [
      {
        order_option_id: OPTION_ID,
        product_name: '반팔 티셔츠',
        product_option_name: '화이트 / M',
        quantity: 1,
        unit_price: 28_000,
        unit_price_formatted: '28,000원',
        available_quantity: 1,
        locked_quantity: 0,
        mc_unit_price: {
          USD: { amount: 28, formatted: '$28.00' },
          JPY: { amount: 3_220, formatted: '¥3,220' },
        },
        mc_price_difference: {
          USD: { amount: -3, formatted: '$-3.00' },
          JPY: { amount: -345, formatted: '¥-345' },
        },
      },
    ]);

    await page.goto(`${base}/guest/claims/EX20260902-0902/edit`);
    await waitForApp(page);

    const items = page.locator('#claim_apply_items');
    await expect(items).toBeVisible({ timeout: 30_000 });

    // 단가 줄의 보조통화는 단가의 것이다.
    await expect(items).toContainText('28,000원', { timeout: 30_000 });
    await expect(items).toContainText('$28.00');
    await expect(items).toContainText('¥3,220');

    // 그리고 차액 값이 그 자리에 흘러들지 않았다 — 이것이 S3-D6 의 red 면이다.
    expect(await items.innerText(), '단가 줄에 교환 차액의 보조통화가 섰다').not.toContain('$-3.00');
  });

  // @scenario screen=detail, entry=with_token, claim_type=exchange
  // @effects claim_detail_comparison_renders_from_frozen_snapshot
  test('비회원 신청서 상세도 접수 시점 주문금액 비교표를 회원과 같은 partial 로 그린다 (#599 후속 P4)', async ({ page }) => {
    const base = await resolveShopBase(page);
    await grantFunctionalConsent(page);
    await seedGuestSession(page);
    await mockGuestOrderScreens(page, [claimRow({ claim_number: 'EX20260902-0902' })]);

    await page.goto(`${base}/guest/claims/EX20260902-0902`);
    await waitForApp(page);

    const table = page.locator('[data-testid="claim-detail-comparison-table"]');
    await expect(table).toBeVisible({ timeout: 30_000 });
    await expect(table.locator('[data-row]')).toHaveCount(14);
    await expect(table).toContainText('5,636원');
    await expect(table).toContainText('3,091원');
    await expect(table).toContainText('$38.25');
    await expect(page.locator('[data-testid="claim-detail-comparison-empty"]')).toHaveCount(0);
    expect(await screenText(page)).not.toContain('claim.detail.comparison');
  });
});
