/**
 * 구매자 클레임 신청내역 — 목록 · 상세 왕복 · 결제 결과 (#599 S15b · §6-1)
 *
 * 브라우저로만 드러나는 축을 본다.
 *
 *  ① **서브탭은 목록 정체성이 바뀌는 이동이다.** 유형마다 서버가 다른 테이블을 읽으므로 앞
 *     유형의 페이지 번호를 물려받으면 3페이지가 없는 유형에서 빈 화면이 열린다 — 응답은 200 이라
 *     어긋난 사실이 어디에도 남지 않는다. 반대로 **상세 왕복은 같은 목록 안의 이동**이라 서브탭·
 *     페이지를 그대로 물려줘야 한다. 두 규칙이 한 화면에 공존하므로 주소를 실제로 밟아야 갈린다.
 *  ② **금액 열의 구성이 유형마다 다르다.** 분기가 어긋나면 다른 유형의 줄이 서거나 있어야 할
 *     줄이 빠지는데 둘 다 오류를 남기지 않는다. 특히 **반품 행에는 환불액을 싣지 않는다** —
 *     그 금액은 완료 처리 전에는 도메인 어디에도 없다.
 *  ③ **결제 결과 배너는 세 결과를 구분한다.** 성공·실패·취소가 한 문구로 뭉뚱그려지면 돈이
 *     나갔는지조차 알 수 없다. 쿼리가 없으면 배너 자체가 서지 않아야 한다.
 *
 * 앞의 두 테스트는 **응답을 가로채지 않는다** — 새로 발급된 회원은 신청서가 0건이므로 빈 목록과
 * 서브탭 왕복이 그 자체로 실측 대상이다. 뒤의 테스트들만 목록·상세 응답을 세운다.
 *
 *
 * 매니페스트 마킹(@scenario/@effects)은 **테스트마다** 그 케이스 위에 둔다 — 한 docblock 에
 * 여러 줄을 적으면 마킹 추출기가 첫 줄만 읽어 나머지 조합이 조용히 미커버로 남는다.
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';
import {
  MOCK_ORDER,
  claimRow,
  grantFunctionalConsent,
  mockClaimDetail,
  mockClaimList,
  mockClaimable,
  mockOrder,
  waitForApp,
} from '../../fixtures/claim-screens';
import type { Page } from '@playwright/test';

/**
 * 신청내역 목록을 열고 **본문이 도착할 때까지** 기다립니다.
 *
 * 서브탭은 목록 응답 전에도 서므로 탭 가시성만으로는 카드가 그려졌다는 뜻이 되지 않는다 —
 * 그 상태에서 글자를 읽으면 껍데기만 보고 "줄이 없다" 로 붉는다(실측: 전수 실행에서만 재현).
 *
 * @param page Playwright 페이지
 * @param query 주소에 붙일 쿼리스트링
 * @param expectText 본문 도착의 신호가 되는 글자 (생략 시 탭까지만 기다린다)
 * @returns 완료 Promise
 */
async function openList(page: Page, query = '', expectText?: string): Promise<void> {
  await page.goto(`/mypage/claims${query}`);
  await waitForApp(page);
  await expect(page.getByRole('button', { name: '교환', exact: true })).toBeVisible({ timeout: 30_000 });

  if (expectText) {
    await expect
      .poll(async () => (await screenText(page)).includes(expectText), { timeout: 20_000 })
      .toBe(true);
  }
}

/** 승인 전이라 수정·철회가 열린 신청서 (수정 화면이 이 값에서 상태를 만든다) */
const EDITABLE: Record<string, unknown> = {
  claim_number: 'EX20260902-0500',
  claim_type: 'exchange',
  claim_type_label: '교환',
  order_number: MOCK_ORDER.number,
  status: 'requested',
  status_label: '교환신청',
  status_variant: 'info',
  currency_code: 'KRW',
  requested_at_formatted: '2026-09-02 10:00',
  is_editable: true,
  is_withdrawable: true,
  reason_code: 'size_mismatch',
  reason_label: '사이즈가 맞지 않음',
  fault_type: 'customer',
  charge_method: 'pay_on_request',
  charge_method_label: '신청 시 결제',
  total_charge_amount: 6000,
  items: [
    {
      order_option_id: 920,
      product_id: 5920,
      product_name: '반팔 티셔츠',
      product_option_name: '화이트 / M',
      quantity: 1,
      unit_price: 29000,
      unit_price_formatted: '29,000원',
      thumbnail_url: null,
      is_option_change: false,
      new_additional_options: [],
    },
  ],
  images: [],
};

/** 유형 서브탭 버튼 */
function tab(page: Page, label: string) {
  return page.getByRole('button', { name: label, exact: true });
}

/** 화면 전체 글자 (공백 정규화) */
async function screenText(page: Page): Promise<string> {
  return ((await page.locator('body').innerText()) ?? '').replace(/\s+/g, ' ');
}

test.describe('구매자 클레임 신청내역', () => {
  test.beforeEach(async ({ page, memberToken }) => {
    await grantFunctionalConsent(page);
    await authenticatePage(page, memberToken);
  });

  /**
   * @scenario claim_type=exchange, screen=list, entry=none
   * @effects claim_list_empty_state_and_pagination
   */
  test('신청서가 없는 회원에게도 서브탭 4종과 빈 안내가 선다', async ({ page }) => {
    await openList(page);

    for (const label of ['교환', '반품', '환불', '취소']) {
      await expect(tab(page, label), `${label} 서브탭이 없다`).toBeVisible();
    }

    // 서브탭은 응답 전에도 서지만 빈 안내는 목록 응답이 도착해야 선다 — 그 도착을 기다린다.
    await expect
      .poll(async () => (await screenText(page)).includes('신청 내역이 없습니다'), { timeout: 20_000 })
      .toBe(true);
    expect(await screenText(page), '빈 목록에서 나갈 곳이 없다').toContain('주문내역 보기');
  });

  /**
   * @scenario claim_type=return, screen=list, entry=none
   * @effects claim_list_type_tabs_follow_the_query
   */
  test('활성 탭은 주소를 단일 출처로 삼는다', async ({ page }) => {
    await openList(page, '?type=return');

    // 활성 판정을 로컬 상태로 이중화하면 새로고침·뒤로가기에서 주소와 화면이 갈린다.
    // 활성 탭만 진한 밑줄(border-gray-900)을 갖는다.
    await expect(tab(page, '반품'), '주소가 지목한 탭이 활성이 아니다').toHaveClass(/border-gray-900/);
    await expect(tab(page, '교환'), '주소가 지목하지 않은 탭이 활성이다').not.toHaveClass(/border-gray-900/);
  });

  /**
   * @scenario claim_type=refund, screen=list, entry=none
   * @effects claim_list_tab_switch_does_not_inherit_the_previous_page
   */
  test('서브탭 전환은 앞 유형의 페이지 번호를 물려받지 않는다', async ({ page }) => {
    await openList(page, '?type=exchange&page=3');

    await tab(page, '반품').click();

    // 유형마다 다른 테이블이라 3페이지가 없는 유형에서 빈 화면이 열린다 — 그래서 초기화한다.
    await expect
      .poll(() => new URL(page.url()).searchParams.get('type'), { timeout: 15_000 })
      .toBe('return');
    expect(
      new URL(page.url()).searchParams.get('page'),
      '탭을 바꿨는데 앞 유형의 페이지 번호가 남았다',
    ).toBe('1');
  });

  /**
   * @scenario claim_type=return, screen=list, entry=none
   * @effects claim_list_amount_column_branches_by_type, claim_list_return_row_omits_refund_amount
   */
  test('금액 열은 유형마다 다르게 서고 반품 행에는 환불액이 없다', async ({ page }) => {
    await mockClaimList(page, {
      exchange: [
        claimRow({
          claim_number: 'EX20260902-0100',
          claim_shipping_fee: 6_000,
          claim_shipping_fee_formatted: '6,000원',
          additional_product_amount: 3_000,
          additional_product_amount_formatted: '3,000원',
          total_charge_amount: 9_000,
          total_charge_amount_formatted: '9,000원',
        }),
      ],
      return: [
        claimRow({
          claim_number: 'RT20260902-0100',
          claim_type: 'return',
          claim_type_label: '반품',
          status_label: '반품신청',
          claim_shipping_fee: 3_000,
          claim_shipping_fee_formatted: '3,000원',
          total_charge_amount: 3_000,
          total_charge_amount_formatted: '3,000원',
          // 서버는 반품 행에 환불액을 싣지 않는다 — 화면도 그 자리를 만들지 않아야 한다.
          refund_amount: null,
          refund_amount_formatted: null,
        }),
      ],
    });

    await openList(page, '?type=exchange', 'EX20260902-0100');
    const exchangeText = await screenText(page);
    expect(exchangeText, '교환 행에 왕복 배송비 줄이 없다').toContain('왕복 배송비');
    expect(exchangeText, '교환 행에 추가 상품금액 줄이 없다').toContain('추가 상품금액');
    expect(exchangeText, '교환 행에 총 청구액 줄이 없다').toContain('총 청구액');

    await tab(page, '반품').click();
    await expect.poll(async () => (await screenText(page)).includes('RT20260902-0100'), {
      timeout: 15_000,
    }).toBe(true);

    const returnText = await screenText(page);
    expect(returnText, '반품 행에 회수 배송비 줄이 없다').toContain('회수 배송비');
    expect(returnText, '반품 행에 교환 전용 줄(왕복 배송비)이 섰다').not.toContain('왕복 배송비');
    expect(
      returnText,
      '반품 행에 환불액 줄이 섰다 — 그 금액은 완료 처리 전에는 도메인 어디에도 없다',
    ).not.toContain('환불액');
  });

  /**
   * @scenario claim_type=exchange, screen=list, entry=none
   * @effects claim_list_row_actions_follow_server_flags
   */
  test('[수정]·[철회]는 서버 판정에서만 선다', async ({ page }) => {
    await mockClaimList(page, {
      exchange: [
        claimRow({ claim_number: 'EX20260902-0201', is_editable: true, is_withdrawable: true }),
      ],
      return: [
        claimRow({
          claim_number: 'RT20260902-0201',
          claim_type: 'return',
          claim_type_label: '반품',
          status_label: '반품완료',
          is_editable: false,
          is_withdrawable: false,
        }),
      ],
    });

    await openList(page, '?type=exchange', 'EX20260902-0201');
    await expect(page.getByRole('button', { name: '수정' })).toBeVisible({ timeout: 15_000 });
    await expect(page.getByRole('button', { name: '철회' })).toBeVisible();

    await tab(page, '반품').click();
    await expect.poll(async () => (await screenText(page)).includes('RT20260902-0201'), {
      timeout: 15_000,
    }).toBe(true);

    // 화면이 상태 문자열로 재현하면 상태가 늘 때마다 갈린다 — 서버 판정만 본다.
    await expect(
      page.getByRole('button', { name: '수정' }),
      '서버가 닫은 신청서에 [수정]이 섰다',
    ).toHaveCount(0);
    await expect(
      page.getByRole('button', { name: '철회' }),
      '서버가 닫은 신청서에 [철회]가 섰다',
    ).toHaveCount(0);
  });

  /**
   * @scenario claim_type=return, screen=detail, entry=none
   * @effects claim_list_page_and_detail_round_trip_keeps_the_list_state
   */
  test('목록 → 상세 왕복은 서브탭과 페이지를 그대로 물려준다', async ({ page }) => {
    await mockClaimList(page, {
      return: [
        claimRow({
          claim_number: 'RT20260902-0300',
          claim_type: 'return',
          claim_type_label: '반품',
          status_label: '반품신청',
          claim_shipping_fee_formatted: '3,000원',
        }),
      ],
    });
    await mockClaimDetail(page, {
      claim_number: 'RT20260902-0300',
      claim_type: 'return',
      claim_type_label: '반품',
      order_number: MOCK_ORDER.number,
      status: 'requested',
      status_label: '반품신청',
      status_variant: 'info',
      currency_code: 'KRW',
      requested_at_formatted: '2026-09-02 10:00',
      is_editable: false,
      is_withdrawable: false,
      reason_label: '단순 변심',
      items: [],
      images: [],
    });

    await openList(page, '?type=return&page=1', 'RT20260902-0300');
    await page.getByRole('button', { name: '상세보기' }).first().click();

    await expect.poll(() => new URL(page.url()).pathname, { timeout: 20_000 }).toContain(
      '/mypage/claims/RT20260902-0300',
    );

    // 상세 왕복은 **같은 목록 안의 이동**이다 — 유형·페이지가 그대로 실려야 돌아올 자리가 남는다.
    const detailQuery = new URL(page.url()).searchParams;
    expect(detailQuery.get('type'), '상세로 가며 서브탭이 사라졌다').toBe('return');
    expect(detailQuery.get('page'), '상세로 가며 페이지가 사라졌다').toBe('1');
  });

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_progress_stepper_renders_server_steps
   */
  test('신청서 상세는 관리자와 같은 진행 단계를 그리고 모바일에서 스크롤 없이 줄바꿈한다', async ({ page }) => {
    const progress = [
      { value: 'requested', label: '교환신청', state: 'done', icon: 'status-request', variant: 'secondary' },
      { value: 'approved', label: '교환승인', state: 'done', icon: 'status-approved', variant: 'info' },
      { value: 'collection_ready', label: '교환회수준비중', state: 'done', icon: 'status-pickup-ready', variant: 'primary' },
      { value: 'collecting', label: '교환회수중', state: 'current', icon: 'status-pickup', variant: 'primary' },
      { value: 'collected', label: '교환회수도착', state: 'upcoming', icon: 'status-received', variant: 'primary' },
      { value: 'redelivery_ready', label: '교환발송준비중', state: 'upcoming', icon: 'status-dispatch-ready', variant: 'primary' },
      { value: 'redelivering', label: '교환발송중', state: 'upcoming', icon: 'status-dispatch', variant: 'primary' },
      { value: 'redelivered', label: '교환발송도착', state: 'upcoming', icon: 'status-delivered', variant: 'primary' },
      { value: 'completed', label: '교환완료', state: 'upcoming', icon: 'status-completed', variant: 'success' },
    ];

    await mockOrder(page);
    await mockClaimDetail(
      page,
      {
        ...EDITABLE,
        claim_number: 'EX20260902-0600',
        status: 'collecting',
        status_label: '교환회수중',
        status_variant: 'primary',
        status_icon: 'status-pickup',
        is_editable: false,
        is_withdrawable: false,
      },
      { progress_steps: progress },
    );

    await page.goto('/mypage/claims/EX20260902-0600');
    await waitForApp(page);

    const cells = page.locator('[id^="claim_progress_step_"]');
    await expect(cells).toHaveCount(progress.length, { timeout: 30_000 });
    await expect(cells.nth(3)).toHaveAttribute('data-step-state', 'current');
    await expect(page.locator('[id^="claim_progress_step_"] .rounded-full i.fa-truck')).toHaveCount(1);
    await expect(page.locator('[data-step-chevron="pc"]:visible')).toHaveCount(progress.length - 1);

    // 헤더 뱃지에도 같은 상태 아이콘이 붙는다.
    await expect(page.locator('h1 ~ span i.fa-truck, h1 + span i.fa-truck').first()).toBeVisible();

    // 모바일 — 4칸/줄 줄바꿈, 스텝퍼도 문서도 가로 스크롤 없음
    await page.setViewportSize({ width: 390, height: 844 });
    await expect.poll(async () => {
      const tops = await cells.evaluateAll((els) => els.map((el) => Math.round(el.getBoundingClientRect().top)));

      return new Set(tops).size;
    }, { timeout: 10_000 }).toBe(Math.ceil(progress.length / 4));
    const box = await page.locator('#claim_progress_steps').evaluate((el) => ({ scroll: el.scrollWidth, client: el.clientWidth }));
    expect(box.scroll).toBeLessThanOrEqual(box.client + 1);
    const doc = await page.evaluate(() => ({ scroll: document.documentElement.scrollWidth, client: document.documentElement.clientWidth }));
    expect(doc.scroll).toBeLessThanOrEqual(doc.client + 1);
  });

  /**
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_payment_result_banner_distinguishes_three_outcomes, claim_payment_result_banner_absent_without_the_query
   */
  test('결제 결과 배너는 세 결과를 구분하고, 쿼리가 없으면 서지 않는다', async ({ page }) => {
    await mockClaimDetail(page, {
      claim_number: 'EX20260902-0400',
      claim_type: 'exchange',
      claim_type_label: '교환',
      order_number: MOCK_ORDER.number,
      status: 'payment_pending',
      status_label: '교환결제대기',
      status_variant: 'warning',
      currency_code: 'KRW',
      requested_at_formatted: '2026-09-02 10:00',
      is_editable: false,
      is_withdrawable: false,
      items: [],
      images: [],
    });

    const base = '/mypage/claims/EX20260902-0400';

    await page.goto(`${base}?claim_payment=success&claim_number=EX20260902-0400`);
    await waitForApp(page);
    await expect
      .poll(async () => (await screenText(page)).includes('추가 결제가 완료되었습니다'), {
        timeout: 20_000,
      })
      .toBe(true);

    await page.goto(`${base}?claim_payment=failed&claim_number=EX20260902-0400&error=PG_DECLINED`);
    await waitForApp(page);
    await expect
      .poll(async () => (await screenText(page)).includes('추가 결제가 완료되지 않았습니다'), {
        timeout: 20_000,
      })
      .toBe(true);
    expect(await screenText(page), '실패 코드가 안내되지 않았다').toContain('PG_DECLINED');

    await page.goto(`${base}?claim_payment=cancelled&claim_number=EX20260902-0400`);
    await waitForApp(page);
    await expect
      .poll(async () => (await screenText(page)).includes('결제를 취소하셨습니다'), { timeout: 20_000 })
      .toBe(true);

    // 쿼리 없이 들어오면 배너 자체가 없다 — 있으면 결제한 적 없는 신청서에 결과가 붙는다.
    await page.goto(base);
    await waitForApp(page);
    await expect
      .poll(async () => (await screenText(page)).includes('EX20260902-0400'), { timeout: 20_000 })
      .toBe(true);
    const plain = await screenText(page);
    expect(plain, '쿼리 없이 성공 배너가 섰다').not.toContain('추가 결제가 완료되었습니다');
    expect(plain, '쿼리 없이 취소 배너가 섰다').not.toContain('결제를 취소하셨습니다');
  });
  /**
   * 대기 결제행의 수단은 접수 때 확정되지만 그 뒤에 낼 수 없게 될 수 있다 — 마일리지 잔액이
   * 모자라거나, 운영자가 그 수단을 카탈로그에서 내리거나. 고를 자리가 없으면 사용자는
   * 「미수납 ○○원 + 결제하기」를 보면서 누를 때마다 같은 거절을 받고 출구가 철회뿐이다
   * (#599 3차 실측 F-1·F-2 — T1-D6).
   *
   * @scenario claim_type=exchange, screen=detail, entry=none
   * @effects claim_detail_pending_payment_method_can_be_rechosen
   */
  test('낼 수 있는 수단이 둘 이상이면 상세에서 결제 수단을 다시 고를 수 있다', async ({ page }) => {
    await mockClaimDetail(page, {
      claim_number: 'EX20260902-0500',
      claim_type: 'exchange',
      claim_type_label: '교환',
      order_number: MOCK_ORDER.number,
      status: 'payment_pending',
      status_label: '교환결제대기',
      status_variant: 'warning',
      currency_code: 'KRW',
      requested_at_formatted: '2026-09-02 10:00',
      is_editable: false,
      is_withdrawable: false,
      items: [],
      images: [],
      outstanding_amount: 4000,
      outstanding_amount_formatted: '4,000원',
      available_payment_methods: ['card', 'point'],
      pending_payment: {
        payment_number: 'CP20260902-0500',
        payment_method: 'point',
        payment_method_label: '포인트결제',
        total_amount_formatted: '4,000원',
      },
    });

    await page.goto('/mypage/claims/EX20260902-0500');
    await waitForApp(page);

    const methods = page.locator('input[type="radio"][name="claimPayMethod"]');

    await expect(methods, '낼 수 있는 수단이 둘인데 고를 자리가 없다').toHaveCount(2, {
      timeout: 20_000,
    });
    // 손대기 전에는 서버가 확정해 둔 수단이 골라져 있어야 한다 — 화면이 안내하는 값과 실제로
    // 나가는 값이 어긋나면 고른 적 없는 수단으로 결제가 시작된다.
    await expect(
      methods.filter({ has: page.locator('[value="point"]') }).or(page.locator('input[value="point"][name="claimPayMethod"]')),
      '서버가 확정한 수단이 선택돼 있지 않다',
    ).toBeChecked();

    // 다른 수단으로 갈아탈 수 있어야 한다 — 이 클릭이 막히면 출구가 신청서 철회뿐이다.
    await page.locator('input[value="card"][name="claimPayMethod"]').check();
    await expect(page.locator('input[value="card"][name="claimPayMethod"]')).toBeChecked();
  });

  /**
   * claim_edit_locks_items_and_quantity, claim_edit_replaces_submit_with_save,
   * claim_edit_hides_charge_axes_and_shows_the_recorded_method
   *
   * @scenario claim_type=exchange, screen=edit, entry=list
   * @effects claim_edit_route_resolves_number_by_position, claim_edit_seeds_items_from_the_claim,
   */
  test('목록의 [수정]으로 들어가면 수정 모드로 서고 품목·수량·청구 방식이 잠긴다', async ({ page }) => {
    await mockClaimList(page, {
      exchange: [claimRow({ claim_number: EDITABLE.claim_number, is_editable: true, is_withdrawable: true })],
    });
    await mockClaimDetail(page, EDITABLE);
    await mockOrder(page);
    await mockClaimable(page, [
      { option: { id: 920, name: '반팔 티셔츠', optionName: '화이트 / M' }, availableQuantity: 1, types: ['exchange'] },
    ]);

    await openList(page, '?type=exchange', EDITABLE.claim_number as string);
    await page.getByRole('button', { name: '수정' }).first().click();

    // 수정 주소에는 유형도 주문번호도 없다 — 둘 다 신청서가 알려 준다.
    await expect
      .poll(() => new URL(page.url()).pathname, { timeout: 20_000 })
      .toBe(`/mypage/claims/${EDITABLE.claim_number}/edit`);

    await expect(page.locator('#claim_apply_items')).toBeVisible({ timeout: 30_000 });

    const text = await screenText(page);
    expect(text, '수정 화면의 범위 안내가 없다').toContain('승인 전까지');

    // 품목·수량은 바꿀 수 없다 — 켜지는 컨트롤을 남기면 눌러도 아무 일이 없는 자리가 된다.
    await expect(
      page.locator('#claim_apply_items input[type="checkbox"]').first(),
      '수정 화면에서 품목 체크가 열려 있다',
    ).toBeDisabled();
    await expect(
      page.locator('#claim_apply_items input[type="number"]').first(),
      '수정 화면에서 수량 입력이 열려 있다',
    ).toBeDisabled();

    // 제출 버튼은 [저장] 이고, 청구 2축은 접수 때 고른 방식을 읽기 전용으로만 보여 준다.
    await expect(
      page.locator('#claim_apply_summary').getByRole('button', { name: '저장' }),
      '수정 화면의 실행 버튼이 [저장]이 아니다',
    ).toBeVisible({ timeout: 15_000 });
    const summary = (await page.locator('#claim_apply_summary').innerText()).replace(/\s+/g, ' ');
    expect(summary, '접수 때 고른 청구 방식이 보이지 않는다').toContain('신청 시 결제');
    await expect(
      page.locator('#claim_apply_summary input[type="radio"]'),
      '수정 화면에 청구 2축이 다시 섰다',
    ).toHaveCount(0);
  });

  /**
   * 진입 축(entry)은 수정·철회 **화면**에만 걸린다 — 목록은 도착지라 `none` 이 그 셀이다
   * (매니페스트 exclusions: screen [list, detail] × entry [list, detail]).
   *
   * @scenario claim_type=exchange, screen=list, entry=none
   * @effects claim_withdraw_entry_points_exist_on_list_and_detail
   */
  test('목록의 [철회]는 곧바로 실행하지 않고 대상과 결과를 밝히는 확인 절차를 연다', async ({ page }) => {
    await mockClaimList(page, {
      exchange: [claimRow({ claim_number: EDITABLE.claim_number, is_editable: true, is_withdrawable: true })],
    });

    await openList(page, '?type=exchange', EDITABLE.claim_number as string);
    await page.getByRole('button', { name: '철회' }).first().click();

    const dialog = page.getByRole('dialog');
    await expect(dialog, '철회 확인 절차가 열리지 않았다').toBeVisible({ timeout: 15_000 });

    const modal = (await dialog.innerText()).replace(/\s+/g, ' ');
    // 대상 없이 열리면 확인 버튼이 어느 신청서를 가리키는지 알 수 없다.
    expect(modal, '확인 절차가 대상 신청번호를 밝히지 않는다').toContain(EDITABLE.claim_number as string);
    expect(modal, '되돌릴 수 없다는 사실이 없다').toContain('되돌릴 수 없습니다');
    expect(modal, '추가 결제 환급 안내가 없다').toContain('환급');
  });
});
