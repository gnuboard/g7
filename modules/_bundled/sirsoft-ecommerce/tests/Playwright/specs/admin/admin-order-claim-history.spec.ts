/**
 * 관리자 주문상세 '클레임 내역' 섹션 — 탭 진입·서브탭 전환·새 창 진입 (#599 S9c · D20)
 *
 * 이 섹션에서 브라우저로만 드러나는 축을 본다:
 *
 *  ① **탭을 눌러야 섹션이 보이는가.** 탭 배열과 섹션 존재가 어긋나면(D17-①) 탭은 그려지는데
 *     클릭이 아무 일도 하지 않는다 — 예외도 로그도 남지 않는다.
 *  ② **서브탭 전환이 재조회를 일으키지 않는가** (D20 — "별도 데이터소스를 만들지 않는다").
 *     레이아웃 정적 검사는 `apiCall` 이 없다는 것까지만 본다. 실제로 요청이 나가는지는
 *     네트워크를 감시해야 알 수 있다.
 *  ③ **신청번호가 새 창으로 열리는가.** 같은 탭에서 열리면 주문상세의 작업
 *     맥락(스크롤 위치·서브탭 선택)이 사라진다.
 *  ④ **진입 직후 접수 건이 있는 유형이 선택돼 있는가.** 렌더 테스트는 응답을 직접 실어 본다 —
 *     실제 화면은 데이터소스 응답이 도착한 뒤 computed 가 다시 돌아야 그 유형으로 바뀐다.
 *
 * 클레임이 달린 주문 한 건이 필요하다. 시드 커맨드(`playwright:seed-ecommerce`)의 클레임
 * 축이 아직 stub 이라(계획서 이월) 목록에서 발견되는 주문으로 진행하고, 한 건도 없으면 그
 * 사실을 드러내며 건너뛴다 — 없는 데이터를 있는 척 단언하지 않는다.
 *
 * 소유 시나리오: `admin-order-claim-history` (축 매핑은 각 테스트에 있다)
 * @axes claim_type=exchange|return|cancel|refund
 * @effects order_detail_claim_history_tab_reveals_section, order_detail_claim_history_subtabs_switch_without_refetch, order_detail_claim_history_detail_link_targets_blank, order_detail_claim_history_cancel_number_opens_cancel_detail, order_detail_claim_history_thumbnail_placeholder_visible, order_detail_claim_history_default_subtab_has_claims
 */
import { test, expect, authenticatePage } from '../../fixtures/ecommerce-auth';

const DETAIL_URL = (orderNumber: string) => `/admin/ecommerce/orders/${encodeURIComponent(orderNumber)}`;
const CLAIMS_API = '/claims';

/** 클레임이 달린 주문 (유형별 건수 포함) */
type DiscoveredOrder = { orderNumber: string; counts: Record<string, number>; detailUrl: string | null };

/**
 * 클레임이 달린 주문 한 건을 찾습니다.
 *
 * 관리자 클레임 목록에서 신청서를 얻고 그 주문번호로 D20 그룹 응답을 확인한다 — 주문 목록을
 * 훑으며 주문마다 클레임을 조회하면 요청이 페이지 크기만큼 늘어난다.
 *
 * @param request Playwright API 요청 컨텍스트
 * @param token 인증 토큰
 * @param claimTypes 훑을 유형 순서 (특정 유형이 달린 주문이 필요하면 그 유형만 넘긴다)
 * @returns 발견한 주문 (없으면 null)
 */
async function discoverOrderWithClaims(
  request: import('@playwright/test').APIRequestContext,
  token: string,
  claimTypes: string[] = ['exchange', 'return', 'refund', 'cancel'],
): Promise<DiscoveredOrder | null> {
  const headers = { Authorization: `Bearer ${token}`, Accept: 'application/json' };

  for (const claimType of claimTypes) {
    const listed = await request.get(
      `/api/modules/sirsoft-ecommerce/admin/claims?type=${claimType}&per_page=5`,
      { headers },
    );

    if (!listed.ok()) continue;

    for (const row of ((await listed.json())?.data?.data ?? []) as Array<Record<string, any>>) {
      if (!row.order_number) continue;

      const grouped = await request.get(
        `/api/modules/sirsoft-ecommerce/admin/orders/${encodeURIComponent(row.order_number)}/claims`,
        { headers },
      );

      if (!grouped.ok()) continue;

      const payload = (await grouped.json())?.data ?? {};
      const claims = (payload.claims ?? []) as Array<Record<string, any>>;

      if (claims.length === 0) continue;

      return {
        orderNumber: row.order_number,
        counts: payload.counts ?? {},
        detailUrl: claims.find((claim) => claim.detail_url)?.detail_url ?? null,
      };
    }
  }

  return null;
}

/**
 * 주문상세가 그려질 때까지 기다립니다.
 *
 * `networkidle` 은 쓰지 않는다 — 관리자 SPA 는 실시간 알림 연결·주기 폴링이 붙어 무통신
 * 구간이 오지 않을 수 있다. 다음 단계에 필요한 구체 신호(탭)만 기다린다.
 *
 * @param page Playwright 페이지
 */
async function waitForOrderDetail(page: import('@playwright/test').Page): Promise<void> {
  await page.waitForLoadState('domcontentloaded', { timeout: 30_000 });
  await expect(page.locator('#tab_navigation')).toBeVisible({ timeout: 30_000 });
}

/**
 * 유형 서브탭 중 건수가 있는 것의 값을 고릅니다.
 *
 * @param counts 유형별 건수
 * @returns 건수가 있는 유형 값 배열
 */
function typesWithClaims(counts: Record<string, number>): string[] {
  return ['exchange', 'return', 'cancel', 'refund'].filter((type) => (counts[type] ?? 0) > 0);
}

test.describe('관리자 주문상세 클레임 내역 섹션 (#599 S9c)', () => {
  /**
   * 탭이 있는데 섹션이 없으면 클릭이 무반응이다 (D17-①) — 그 어긋남은 오류를 남기지 않는다.
   */
  // @scenario claim_type=exchange, claims_present=yes
  // @effects order_detail_claim_history_tab_reveals_section
  test('클레임 내역 탭이 있고 그 섹션이 조회 전용으로 그려진다', async ({ page, request, ordersReadToken }) => {
    const order = await discoverOrderWithClaims(request, ordersReadToken);
    test.skip(
      order === null,
      '클레임이 달린 주문이 없다 — `playwright:seed-ecommerce` 클레임 시드(계획서 이월) 선행',
    );

    await authenticatePage(page, ordersReadToken);
    await page.goto(DETAIL_URL(order!.orderNumber));
    await waitForOrderDetail(page);

    const section = page.locator('[data-section-id="claim_history"]');
    await expect(section).toHaveCount(1);

    // 탭 버튼의 role 은 `tab` 이다 (WAI-ARIA Tabs 규약) — `button` 으로 찾으면 잡히지 않는다.
    await page.locator('#tab_navigation').getByRole('tab', { name: /클레임/ }).click();
    await page.waitForTimeout(1_500);

    await expect(section).toBeVisible({ timeout: 30_000 });

    // 조회 전용 — 이 섹션 안에는 입력 요소가 없고 버튼은 유형 서브탭 4개뿐이다 (D20).
    await expect(section.locator('input, select, textarea')).toHaveCount(0);
    await expect(section.locator('button')).toHaveCount(4);

    // 원문 다국어 키가 화면에 노출되지 않는다.
    expect(await section.innerText()).not.toContain('sirsoft-ecommerce.admin.order.detail.claim_history');
  });

  /**
   * 서버가 전 유형을 한 응답에 싣는 것이 D20 의 전제다 — 전환마다 요청이 나가면 그 전제가
   * 깨진 것이고, 뱃지와 목록이 갈릴 창도 함께 열린다.
   */
  // @scenario claim_type=return, claims_present=yes
  // @effects order_detail_claim_history_subtabs_switch_without_refetch
  test('유형 서브탭을 오가도 클레임 조회 요청이 다시 나가지 않는다', async ({ page, request, ordersReadToken }) => {
    const order = await discoverOrderWithClaims(request, ordersReadToken);
    test.skip(
      order === null,
      '클레임이 달린 주문이 없다 — `playwright:seed-ecommerce` 클레임 시드(계획서 이월) 선행',
    );

    await authenticatePage(page, ordersReadToken);
    await page.goto(DETAIL_URL(order!.orderNumber));
    await waitForOrderDetail(page);
    await page.waitForTimeout(2_000);

    // 진입 조회가 끝난 뒤부터 센다.
    let refetches = 0;
    page.on('request', (req) => {
      if (req.url().includes(encodeURIComponent(order!.orderNumber)) && req.url().endsWith(CLAIMS_API)) {
        refetches += 1;
      }
    });

    const section = page.locator('[data-section-id="claim_history"]');
    for (const type of ['return', 'cancel', 'refund', 'exchange']) {
      await section.locator(`#claim_history_subtab_${type}`).click();
      await page.waitForTimeout(400);
    }

    expect(refetches).toBe(0);

    // 건수가 있는 유형으로 돌아오면 그 유형의 행이 다시 보인다 — 필터가 화면에서 돈다.
    const present = typesWithClaims(order!.counts);
    if (present.length > 0) {
      await section.locator(`#claim_history_subtab_${present[0]}`).click();
      await page.waitForTimeout(400);
      await expect(section.locator('#claim_history_empty')).toHaveCount(0);
    }
  });

  /**
   * 종전에는 늘 교환으로 시작해 취소만 있는 주문에서 빈 안내가 먼저 보였다. 기본 유형은 접수
   * 건이 있는 첫 유형(교환 → 반품 → 취소 → 환불)이고, 그 판정은 응답이 도착한 뒤 computed 가
   * 다시 돌아야 화면에 닿는다 — 아무것도 누르지 않은 상태를 본다.
   */
  // @scenario claim_type=cancel, claims_present=yes
  // @effects order_detail_claim_history_default_subtab_has_claims
  test('진입 직후 접수 건이 있는 첫 유형이 선택돼 있다 — 누르기 전에', async ({ page, request, ordersReadToken }) => {
    const order = await discoverOrderWithClaims(request, ordersReadToken);
    test.skip(
      order === null,
      '클레임이 달린 주문이 없다 — `playwright:seed-ecommerce` 클레임 시드(계획서 이월) 선행',
    );

    const present = typesWithClaims(order!.counts);
    test.skip(present.length === 0, '접수된 유형이 없다');

    await authenticatePage(page, ordersReadToken);
    await page.goto(DETAIL_URL(order!.orderNumber));
    await waitForOrderDetail(page);

    const section = page.locator('[data-section-id="claim_history"]');
    await page.locator('#tab_navigation').getByRole('tab', { name: /클레임/ }).click();
    await expect(section).toBeVisible({ timeout: 30_000 });

    // 활성 변형은 채운 배경(bg-blue-600) — 첫 유형 하나만 활성이고 빈 안내는 없다.
    await expect(section.locator(`#claim_history_subtab_${present[0]}`)).toHaveClass(/bg-blue-600/, { timeout: 15_000 });
    for (const type of ['exchange', 'return', 'cancel', 'refund'].filter((t) => t !== present[0])) {
      await expect(section.locator(`#claim_history_subtab_${type}`)).not.toHaveClass(/bg-blue-600/);
    }
    await expect(section.locator('#claim_history_empty')).toHaveCount(0);
    await expect(section.locator('td[rowspan], [id^="claim_history_card_"]').first()).toBeVisible();
  });

  /**
   * 같은 탭에서 열리면 주문상세의 작업 맥락(스크롤 위치·서브탭 선택)이 사라진다. 번호 자체가
   * 링크이고 네 유형 모두 상세가 있다 — 취소도 조회 전용 상세가 열린다.
   */
  // @scenario claim_type=exchange, claims_present=yes
  // @effects order_detail_claim_history_detail_link_targets_blank
  test('신청번호가 새 창으로 그 클레임 상세를 연다', async ({ page, request, ordersReadToken }) => {
    const order = await discoverOrderWithClaims(request, ordersReadToken);
    test.skip(
      order === null || order.detailUrl === null,
      '클레임이 달린 주문이 없다 — 클레임 시드 선행',
    );

    await authenticatePage(page, ordersReadToken);
    await page.goto(DETAIL_URL(order!.orderNumber));
    await waitForOrderDetail(page);

    const section = page.locator('[data-section-id="claim_history"]');
    const present = typesWithClaims(order!.counts);
    test.skip(present.length === 0, '접수된 유형이 없다');

    await section.locator(`#claim_history_subtab_${present[0]}`).click();
    await page.waitForTimeout(600);

    // 번호가 곧 링크다 — 별도의 [조회하기] 버튼은 없다
    const link = section.locator('td[rowspan] a[target="_blank"]').first();
    await expect(link).toHaveCount(1);
    await expect(section.locator('a[target="_blank"]:has-text("조회하기")')).toHaveCount(0);

    // 도착 URL 이 그 클레임 상세다 — 경로를 화면이 조립하면 규칙이 바뀔 때 조용히 404 가 된다.
    const href = await link.getAttribute('href');
    expect(href).toMatch(/^\/admin\/ecommerce\/claims\/.+/);
    expect((await link.innerText()).trim().length).toBeGreaterThan(0);
    expect(href!.endsWith(encodeURIComponent((await link.innerText()).trim())) || href!.endsWith((await link.innerText()).trim())).toBe(true);

    const [opened] = await Promise.all([page.context().waitForEvent('page'), link.click()]);
    await opened.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    expect(new URL(opened.url()).pathname).toBe(href);
    await expect(opened.locator('#claim_tab_navigation')).toBeVisible({ timeout: 30_000 });

    await opened.close();
  });

  /**
   * 취소는 종전에 상세가 없어 번호가 글자로만 남았다 — 이제 조회 전용 상세가 열리고, 그 화면에는
   * 취소 정보 카드가 서고 전이·송장 컨트롤은 없다.
   */
  // @scenario claim_type=cancel, claims_present=yes
  // @effects order_detail_claim_history_cancel_number_opens_cancel_detail
  test('취소 번호도 새 창으로 취소 상세(조회 전용)를 연다', async ({ page, request, ordersReadToken }) => {
    // 교환을 먼저 고르는 발견 헬퍼는 취소가 없는 주문에서 멈춘다 — 이 축은 취소 탭에서 직접 찾는다.
    const order = await discoverOrderWithClaims(request, ordersReadToken, ['cancel']);
    test.skip(order === null || (order.counts.cancel ?? 0) === 0, '취소가 달린 주문이 없다 — 클레임 시드 선행');

    await authenticatePage(page, ordersReadToken);
    await page.goto(DETAIL_URL(order!.orderNumber));
    await waitForOrderDetail(page);

    const section = page.locator('[data-section-id="claim_history"]');
    await section.locator('#claim_history_subtab_cancel').click();
    await page.waitForTimeout(600);

    const link = section.locator('td[rowspan] a[target="_blank"]').first();
    await expect(link).toHaveCount(1);
    const href = await link.getAttribute('href');

    const [opened] = await Promise.all([page.context().waitForEvent('page'), link.click()]);
    await opened.waitForLoadState('domcontentloaded', { timeout: 30_000 });

    expect(new URL(opened.url()).pathname).toBe(href);
    await expect(opened.locator('#claim_cancel_info_card')).toBeVisible({ timeout: 30_000 });
    await expect(opened.locator('#cm_001b_memo_unavailable_cancel')).toBeVisible();
    await expect(opened.locator('[data-section-id="shipping_info"]')).toHaveCount(0);
    await expect(opened.locator('#cm_003_memo_textarea')).toHaveCount(0);

    await opened.close();
  });

  /**
   * 자리표시는 레이아웃 크기의 문제라 브라우저에서만 드러난다 — 이미지가 없는 행에서도 썸네일
   * 자리가 같은 크기로 남고 아이콘이 선다. 응답의 썸네일만 비워 어느 주문이 열리든 같은 상태를 본다.
   */
  // @scenario claim_type=exchange, claims_present=yes
  // @effects order_detail_claim_history_thumbnail_placeholder_visible
  test('이미지 없는 상품에는 같은 크기의 자리표시가 선다', async ({ page, request, ordersReadToken }) => {
    const order = await discoverOrderWithClaims(request, ordersReadToken);
    test.skip(order === null, '클레임이 달린 주문이 없다 — 클레임 시드 선행');

    await page.route('**/api/modules/sirsoft-ecommerce/admin/orders/*/claims', async (route) => {
      if (route.request().method() !== 'GET') {
        await route.fallback();
        return;
      }
      const response = await route.fetch();
      const body = await response.json();
      for (const claim of body?.data?.claims ?? []) {
        for (const item of claim.items ?? []) item.thumbnail_url = null;
      }
      await route.fulfill({ response, body: JSON.stringify(body) });
    });

    await authenticatePage(page, ordersReadToken);
    await page.goto(DETAIL_URL(order!.orderNumber));
    await waitForOrderDetail(page);

    const section = page.locator('[data-section-id="claim_history"]');
    const present = typesWithClaims(order!.counts);
    test.skip(present.length === 0, '접수된 유형이 없다');
    await section.locator(`#claim_history_subtab_${present[0]}`).click();
    await page.waitForTimeout(600);

    const boxes = section.locator('tbody [id^="claim_history_item_thumb_"]');
    const count = await boxes.count();
    expect(count).toBeGreaterThan(0);
    await expect(section.locator('tbody img')).toHaveCount(0);
    await expect(section.locator('tbody [id^="claim_history_item_thumb_"] i[class*="fa-image"]')).toHaveCount(count);

    const sizes = await boxes.evaluateAll((els) => els.map((el) => {
      const r = el.getBoundingClientRect();
      return [Math.round(r.width), Math.round(r.height)];
    }));
    expect(sizes.every(([w, h]) => w === 48 && h === 48)).toBe(true);
  });
});
